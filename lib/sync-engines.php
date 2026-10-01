<?php

/**
 * Declarative provider sync recipes + per-API-family engines.
 *
 * Adding a provider in an already-supported family = add a recipe entry below (config only).
 * A brand-new API family = add one engine function + a recipe. GA4/GSC keep their own
 * (verified) sync functions in lib/sync.php and are dispatched separately.
 *
 * All family engines here write into the generic provider_metrics_daily store, so no
 * per-provider table/dashboard code is ever needed.
 */

require_once __DIR__ . '/oauth.php';

/**
 * Highest Meta rate-limit usage % from a response's headers. Meta reports each limit as 0–100 (%
 * of the rolling-window budget consumed): X-App-Usage (call_count/cputime/time), X-Ad-Account-Usage
 * (acc_id_util_pct) and X-Business-Use-Case-Usage (per-asset array). Page-token & ads calls are
 * governed by the BUC header, app-token calls by App-Usage. Returns -1 when no usage header present.
 */
function allstat_meta_usage_pct(array $resp): float
{
    $headers = $resp['headers'] ?? [];
    $max = -1.0;
    $bump = static function ($v) use (&$max): void {
        if (is_numeric($v) && (float) $v > $max) { $max = (float) $v; }
    };

    if (isset($headers['x-app-usage']) && is_array($a = json_decode((string) $headers['x-app-usage'], true))) {
        foreach (['call_count', 'total_cputime', 'total_time'] as $k) { $bump($a[$k] ?? null); }
    }
    if (isset($headers['x-ad-account-usage']) && is_array($a = json_decode((string) $headers['x-ad-account-usage'], true))) {
        $bump($a['acc_id_util_pct'] ?? null);
    }
    if (isset($headers['x-business-use-case-usage']) && is_array($b = json_decode((string) $headers['x-business-use-case-usage'], true))) {
        foreach ($b as $entries) {
            foreach ((array) $entries as $e) {
                if (!is_array($e)) { continue; }
                foreach (['call_count', 'total_cputime', 'total_time'] as $k) { $bump($e[$k] ?? null); }
            }
        }
    }

    return $max;
}

/**
 * Persist the latest Meta usage % to the connection's quota_json (visible in the admin) and, if it is
 * at/over $threshold, throw to abort the sync BEFORE the next expensive phase. Because the sync writes
 * to the DB incrementally, partial data is kept and the rest is picked up on the next (cron) run. This
 * is the actual guard that keeps backfills / repeated manual syncs from blowing the rate limit.
 */
function allstat_meta_guard(PDO $pdo, int $connId, array $resp, float $threshold = 90.0): float
{
    $pct = allstat_meta_usage_pct($resp);
    if ($pct >= 0) {
        try {
            $pdo->prepare('UPDATE domain_sources SET quota_json = ?, quota_updated_at = NOW() WHERE id = ?')
                ->execute([json_encode(['meta_usage_pct' => round($pct, 1), 'at' => date('c')]), $connId]);
        } catch (Throwable) { /* quota column is optional */ }
        if ($pct >= $threshold) {
            throw new RuntimeException(sprintf('Meta rate limit na %.0f %% – sync zastaven kvůli ochraně limitu. Zbytek se dosynuje při dalším běhu.', $pct));
        }
    }

    return $pct;
}

function allstat_sync_recipe(string $providerKey): ?array
{
    return match ($providerKey) {
        'facebook_pages' => [
            'engine' => 'meta_graph',
            'object' => 'page',
            // Post-Nov-2025 valid Page Insights metrics (page_impressions + page_fans were retired).
            // All are daily counts → safely summable over a range. The engine skips any that Meta
            // rejects (further deprecations land 15. 6. 2026), so the sync never 400s as a whole.
            'metrics' => [
                // Dosah: legacy unique-impressions + its 15.6.2026 replacement, both → reach. The engine
                // retries metrics one-by-one and skips any the API rejects, so requesting both is safe
                // before AND after the deprecation — whichever Meta still serves populates reach.
                // page_fan_adds/page_fan_removes are ALREADY dead (verified live → "must be a valid insights
                // metric"); the surviving follower-change metrics are page_daily_follows/page_daily_unfollows.
                'page_impressions_unique' => 'reach',
                'page_total_media_view_unique' => 'reach',
                'page_media_view' => 'page_impressions',
                'page_post_engagements' => 'engagements',
                'page_views_total' => 'page_views',
                'page_daily_follows' => 'new_follows',
                'page_daily_unfollows' => 'unfollows',
            ],
        ],
        'instagram_business' => [
            'engine' => 'meta_graph',
            'object' => 'ig',
            // reach + follower_count are valid period=day time series. profile_views was DROPPED — Meta
            // moved it to metric_type=total_value (a single range value, no daily series), which doesn't fit
            // the daily-sum model; it 400'd and was skipped anyway. follower_count = daily new followers.
            'metrics' => ['reach' => 'reach', 'follower_count' => 'new_follows'],
        ],
        'meta_ads' => [
            'engine' => 'meta_graph',
            'object' => 'ads',
            'metrics' => ['impressions' => 'impressions', 'clicks' => 'clicks', 'spend' => 'spend', 'reach' => 'reach'],
            // Conversions + value are nested in the Marketing API `actions`/`action_values` arrays.
            // We sum the values whose action_type is a conversion (for count) / a purchase (for revenue),
            // so the dashboard can derive ROAS = value/spend and CPA = spend/conversions.
            'conversion_actions' => ['purchase', 'lead', 'complete_registration', 'submit_application', 'subscribe', 'start_trial', 'offsite_conversion.fb_pixel_purchase', 'offsite_conversion.fb_pixel_lead', 'onsite_conversion.purchase', 'onsite_conversion.lead_grouped'],
            'value_actions' => ['purchase', 'omni_purchase', 'offsite_conversion.fb_pixel_purchase', 'onsite_conversion.purchase'],
        ],
        'linkedin_company' => [
            'engine' => 'linkedin',
            'metrics' => ['impressionCount' => 'impressions', 'clickCount' => 'clicks', 'likeCount' => 'likes', 'shareCount' => 'shares', 'commentCount' => 'comments', 'engagement' => 'engagement_rate'],
        ],
        'clarity' => [
            'engine' => 'clarity',
            // Clarity API is a tiny rolling window (last ~3 days) hard-capped at 10 calls/day,
            // so month-by-month backfill is impossible — treat backfill as a single call.
            'single_shot' => true,
        ],
        'google_ads' => [
            'engine' => 'google_ads',
            'metrics' => ['impressions' => 'impressions', 'clicks' => 'clicks', 'cost' => 'cost', 'conversions' => 'conversions', 'conversion_value' => 'conversion_value'],
        ],
        'seznam_wmt' => [
            'engine' => 'seznam_wmt',
            'metrics' => ['indexed' => 'indexed', 'content' => 'content', 'downloaded' => 'downloaded', 'error' => 'error', 'redirected' => 'redirected', 'doc_count' => 'doc_count'],
        ],
        'youtube' => [
            'engine' => 'youtube',
            // YouTube Analytics (dimensions=day) → lokální klíče; všechno denní COUNTY kromě
            // averageViewDuration (denní PRŮMĚR v sekundách, ve view se přepočítá ze součtů).
            'metrics' => ['views' => 'views', 'estimatedMinutesWatched' => 'watch_time_min', 'averageViewDuration' => 'avg_view_duration',
                'likes' => 'likes', 'comments' => 'comments', 'shares' => 'shares', 'subscribersGained' => 'new_follows', 'subscribersLost' => 'unfollows'],
        ],
        default => null,
    };
}

function allstat_pm_statement(PDO $pdo): PDOStatement
{
    return $pdo->prepare('
        INSERT INTO provider_metrics_daily (domain_id, source_id, connection_id, metric_date, metric_key, dimension, metric_value)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE metric_value = VALUES(metric_value)
    ');
}

/**
 * $connectionId is domain_sources.id — the per-connection grain, so two connections of the same
 * provider on one domain (e.g. two Facebook pages) never overwrite each other. $sourceId stays the
 * data_sources.id (provider type) for provider-name joins/reports.
 */
function allstat_pm_put(PDOStatement $stmt, int $domainId, int $sourceId, int $connectionId, string $date, string $key, float $value, string $dimension = ''): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    $stmt->execute([$domainId, $sourceId, $connectionId, $date, mb_substr($key, 0, 80), mb_substr($dimension, 0, 120), $value]);
    return true;
}

/**
 * Run a recipe-based engine. Returns stats array with a 'summary'.
 */
function allstat_run_engine(string $engine, PDO $pdo, array $config, array $connection, string $token, string $startDate, string $endDate, array $recipe): array
{
    return match ($engine) {
        'meta_graph' => allstat_engine_meta_graph($pdo, $config, $connection, $token, $startDate, $endDate, $recipe),
        'linkedin' => allstat_engine_linkedin($pdo, $config, $connection, $token, $startDate, $endDate, $recipe),
        'clarity' => allstat_engine_clarity($pdo, $config, $connection, $token, $startDate, $endDate, $recipe),
        'google_ads' => allstat_engine_google_ads($pdo, $config, $connection, $token, $startDate, $endDate, $recipe),
        'seznam_wmt' => allstat_engine_seznam_wmt($pdo, $config, $connection, $token, $startDate, $endDate, $recipe),
        'youtube' => allstat_engine_youtube($pdo, $config, $connection, $token, $startDate, $endDate, $recipe),
        default => throw new RuntimeException('Neznámý engine: ' . $engine),
    };
}

/**
 * Sum a Meta insights `actions` / `action_values` array down to (conversions, conversion_value):
 * conversions = Σ values whose action_type is a configured conversion, value = Σ purchase-type values.
 * The arrays look like [['action_type' => 'purchase', 'value' => '3'], ...]; missing/garbage is ignored.
 */
function allstat_meta_parse_actions(array $row, array $conversionTypes, array $valueTypes): array
{
    $conversions = 0.0;
    $value = 0.0;
    foreach (($row['actions'] ?? []) as $action) {
        if (in_array($action['action_type'] ?? '', $conversionTypes, true) && is_numeric($action['value'] ?? null)) {
            $conversions += (float) $action['value'];
        }
    }
    foreach (($row['action_values'] ?? []) as $action) {
        if (in_array($action['action_type'] ?? '', $valueTypes, true) && is_numeric($action['value'] ?? null)) {
            $value += (float) $action['value'];
        }
    }
    // Awareness/traffic actions — always extracted (the useful metrics for accounts with no conversions:
    // link clicks, landing-page views, video views, post engagement). Stored as their own metric keys.
    $aware = ['link_click' => 0.0, 'landing_page_view' => 0.0, 'video_view' => 0.0, 'post_engagement' => 0.0];
    foreach (($row['actions'] ?? []) as $action) {
        $t = (string) ($action['action_type'] ?? '');
        if (array_key_exists($t, $aware) && is_numeric($action['value'] ?? null)) {
            $aware[$t] = (float) $action['value'];
        }
    }

    return ['conversions' => $conversions, 'conversion_value' => $value, 'aware' => $aware];
}

/**
 * Sum the numeric values of a Meta insights action-shaped field (e.g. video_play_actions,
 * video_thruplay_watched_actions), which arrives as [['action_type'=>..., 'value'=>'N'], ...].
 */
function allstat_meta_sum_action_field($field): float
{
    if (!is_array($field)) {
        return 0.0;
    }
    $sum = 0.0;
    foreach ($field as $entry) {
        if (is_array($entry) && is_numeric($entry['value'] ?? null)) {
            $sum += (float) $entry['value'];
        }
    }

    return $sum;
}

/**
 * Souhrny Meta Ads za CELOU dobu (insights s date_preset=maximum a bez time_increment) pro kampaně, sestavy
 * a reklamy → tabulka meta_ads_totals. Meta tu vrací DEDUPLIKOVANÝ dosah, takže zobrazení ÷ dosah je skutečná
 * frekvence (kolikrát reklamu viděl tentýž člověk). Z denních řádků ji spočítat nejde: součet denních dosahů
 * počítá stejného člověka každý den znovu, proto „frekvence" z nich vycházela kolem 1,0 i u kampaně, kterou
 * lidé viděli opakovaně. Bere i cíl kampaně (objective) a optimalizaci sestavy, podle kterých se kampaň
 * hodnotí. Jméno se ořezává stejně jako `dimension` v provider_metrics_daily (trim + 120 znaků), aby šly
 * souhrny spárovat s denními řádky. Vrací počet zapsaných řádků; chyby DB / rate limitu nechává probublat.
 */
function allstat_meta_ads_sync_totals(PDO $pdo, string $base, string $accountId, string $token, int $connectionId): int
{
    $stmt = $pdo->prepare('INSERT INTO meta_ads_totals (connection_id, level, entity_id, name, objective, optimization_goal, impressions, reach, spend)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE name = VALUES(name), objective = VALUES(objective), optimization_goal = VALUES(optimization_goal),
            impressions = VALUES(impressions), reach = VALUES(reach), spend = VALUES(spend), updated_at = CURRENT_TIMESTAMP');
    $levels = [
        'campaign' => ['campaign_id', 'campaign_name', 'objective'],
        'adset' => ['adset_id', 'adset_name', 'objective,optimization_goal'],
        'ad' => ['ad_id', 'ad_name', 'objective,optimization_goal'],
    ];
    $written = 0;
    foreach ($levels as $level => [$idField, $nameField, $goalFields]) {
        $url = $base . '/' . rawurlencode($accountId) . '/insights?level=' . $level . '&date_preset=maximum'
            . '&fields=' . $idField . ',' . $nameField . ',' . $goalFields . ',impressions,reach,spend&limit=500&access_token=' . urlencode($token);
        $guard = 0;
        while ($url !== '' && $guard < 10) {
            $resp = allstat_http_request('GET', $url, ['Accept' => 'application/json'], null, 60);
            allstat_meta_guard($pdo, $connectionId, $resp);
            if ($resp['status'] !== 200) { break; } // nepodporované pole / oprávnění → jen tahle úroveň chybí
            foreach ($resp['json']['data'] ?? [] as $row) {
                $entityId = trim((string) ($row[$idField] ?? ''));
                $name = mb_substr(trim((string) ($row[$nameField] ?? '')), 0, 120);
                if ($entityId === '' || $name === '') { continue; }
                $stmt->execute([
                    $connectionId, $level, mb_substr($entityId, 0, 40), $name,
                    mb_substr((string) ($row['objective'] ?? ''), 0, 40), mb_substr((string) ($row['optimization_goal'] ?? ''), 0, 40),
                    (int) round((float) ($row['impressions'] ?? 0)), (int) round((float) ($row['reach'] ?? 0)), round((float) ($row['spend'] ?? 0), 2),
                ]);
                $written++;
            }
            $url = (string) ($resp['json']['paging']['next'] ?? '');
            $guard++;
        }
    }

    return $written;
}

/**
 * Convert a Meta time value to the app timezone, returning ['date'=>'Y-m-d','dt'=>'Y-m-d H:i:s']
 * (both '' on failure). Accepts ISO-8601 with offset (created_time / timestamp, default +0000) or a
 * Unix-epoch string (stories' creation_time). FIX: previously the code sliced the first 10 chars of the
 * raw UTC string, which filed a late-evening Prague post under the previous (UTC) day — an off-by-one
 * that both mis-dated posts and could shift them across the selected period boundary. Parsing the full
 * value WITH its offset and converting to Europe/Prague yields the correct local publish date + time.
 */
function allstat_meta_local_time(?string $raw, DateTimeZone $tz): array
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return ['date' => '', 'dt' => ''];
    }
    try {
        $d = (ctype_digit($raw) ? new DateTimeImmutable('@' . $raw) : new DateTimeImmutable($raw))->setTimezone($tz);

        return ['date' => $d->format('Y-m-d'), 'dt' => $d->format('Y-m-d H:i:s')];
    } catch (Throwable) {
        return ['date' => '', 'dt' => ''];
    }
}

/**
 * Meta Graph API (Facebook Pages, Instagram Business, Meta Ads).
 * page/ig: GET {base}/{id}/insights?metric=...&period=day&since&until → data[].values[].{value,end_time}
 * ads:     GET {base}/{id}/insights?fields=...&time_increment=1&time_range → data[].{date_start,<field>}
 */
function allstat_engine_meta_graph(PDO $pdo, array $config, array $connection, string $token, string $startDate, string $endDate, array $recipe): array
{
    $base = rtrim((string) ($connection['api_base_url'] ?: 'https://graph.facebook.com/v25.0'), '/');
    $object = $recipe['object'] ?? 'page';
    $id = trim((string) ($connection['property_id'] ?? ''));
    if ($id === '') {
        throw new RuntimeException('Meta: vyplň Property ID (Page ID / IG Business ID / act_…).');
    }
    $metrics = $recipe['metrics'] ?? [];
    $stmt = allstat_pm_statement($pdo);
    $domainId = (int) $connection['domain_id'];
    $sourceId = (int) $connection['source_id'];
    $connectionId = (int) $connection['id'];
    $written = 0;

    if ($object === 'ads') {
        $conversionTypes = $recipe['conversion_actions'] ?? [];
        $valueTypes = $recipe['value_actions'] ?? [];
        $wantActions = $conversionTypes || $valueTypes;
        $timeRange = rawurlencode(json_encode(['since' => $startDate, 'until' => $endDate]));

        // Optional attribution window from the connection's config_json, e.g.
        // {"attribution_windows":["7d_click","1d_view"]}. Omitted = Meta's own account default.
        $cfg = json_decode((string) ($connection['config_json'] ?? ''), true);
        $attrParam = (is_array($cfg) && !empty($cfg['attribution_windows']) && is_array($cfg['attribution_windows']))
            ? '&action_attribution_windows=' . rawurlencode(json_encode(array_values($cfg['attribution_windows'])))
            : '';

        // 1) Account-level daily headline metrics (+ conversions/value parsed from actions).
        $fields = array_keys($metrics);
        if ($wantActions) { $fields[] = 'actions'; $fields[] = 'action_values'; }
        $url = $base . '/' . rawurlencode($id) . '/insights?level=account&time_increment=1&fields=' . implode(',', $fields)
            . '&time_range=' . $timeRange . $attrParam . '&access_token=' . urlencode($token);
        $resp = allstat_http_request('GET', $url, ['Accept' => 'application/json'], null, 60);
        allstat_meta_guard($pdo, $connectionId, $resp);
        if ($resp['status'] !== 200) {
            throw new RuntimeException('Meta Ads API HTTP ' . $resp['status'] . ': ' . substr($resp['body'], 0, 180));
        }
        foreach ($resp['json']['data'] ?? [] as $row) {
            $date = (string) ($row['date_start'] ?? '');
            foreach ($metrics as $apiKey => $localKey) {
                if (isset($row[$apiKey]) && is_numeric($row[$apiKey])) {
                    if (allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $date, $localKey, (float) $row[$apiKey])) { $written++; }
                }
            }
            if ($wantActions && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $parsed = allstat_meta_parse_actions($row, $conversionTypes, $valueTypes);
                allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $date, 'conversions', $parsed['conversions']);
                allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $date, 'conversion_value', $parsed['conversion_value']);
                // Awareness/traffic metrics (useful when there are no conversions): link clicks, landing-page
                // views, video views, post engagement.
                foreach (['link_click' => 'link_clicks', 'landing_page_view' => 'lp_views', 'video_view' => 'video_views', 'post_engagement' => 'post_engagement'] as $atype => $key) {
                    allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $date, $key, $parsed['aware'][$atype]);
                }
                $written += 6;
            }
        }

        // 2) Per-campaign / ad-set / ad daily breakdowns. Each is best-effort (an unsupported field
        //    or missing permission just skips that level; the account-level headline data is kept).
        //    Stored dimensioned by name with a level prefix; every level keeps 3s video views
        //    (`<prefix>_video_views`) and the ad level also thruplays, so the dashboard can show the
        //    hook rate. Frequency over a whole campaign comes from allstat_meta_ads_sync_totals() below.
        $breakdown = function (string $level, string $nameField, string $prefix, bool $withVideo)
                use ($base, $id, $timeRange, $attrParam, $token, $wantActions, $conversionTypes, $valueTypes, $stmt, $domainId, $sourceId, $connectionId): int {
            // POZOR na dva různé „prokliky": `clicks` jsou VŠECHNA kliknutí (i lajky, jméno stránky,
            // rozbalení textu), kdežto `inline_link_clicks` jsou kliknutí NA ODKAZ. Business Suite ukazuje
            // v hlavičce reklamy to druhé („Kliknutí na odkaz" a „Za kliknutí na odkaz"), takže bez něj
            // vycházela cena za proklik jinak než v Metě (ověřeno 17. 8. 2026: účet 0,54–0,67 Kč za klik
            // vs. 0,67–1,39 Kč za klik na odkaz podle reklamy).
            // `actions` se tahají vždy: kromě konverzí nesou i `video_view` = přehrání videa aspoň 3 s.
            // Dřív se hook rate počítal z `video_play_actions`, což je KAŽDÉ spuštění videa (i automatické
            // při scrollování), takže vycházel mnohonásobně vyšší (září 2026: 25 835 spuštění vs. 3 240
            // přehrání na 3 s). Starý klíč <prefix>_video3s se proto už nezapisuje a nikde nečte.
            $fields = [$nameField, 'spend', 'impressions', 'clicks', 'inline_link_clicks', 'reach', 'actions'];
            if ($wantActions) { $fields[] = 'action_values'; }
            if ($withVideo) { $fields[] = 'video_thruplay_watched_actions'; }
            $url = $base . '/' . rawurlencode($id) . '/insights?level=' . $level . '&time_increment=1&fields=' . implode(',', $fields)
                . '&time_range=' . $timeRange . $attrParam . '&limit=500&access_token=' . urlencode($token);
            $resp = allstat_http_request('GET', $url, ['Accept' => 'application/json'], null, 60);
            if ($resp['status'] !== 200) { return 0; }
            $w = 0;
            foreach ($resp['json']['data'] ?? [] as $row) {
                $date = (string) ($row['date_start'] ?? '');
                $name = trim((string) ($row[$nameField] ?? ''));
                if ($name === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { continue; }
                foreach (['spend', 'impressions', 'clicks', 'reach'] as $f) {
                    if (isset($row[$f]) && is_numeric($row[$f])) {
                        if (allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $date, $prefix . '_' . $f, (float) $row[$f], $name)) { $w++; }
                    }
                }
                // Kliknutí na odkaz se ukládají pod vlastní klíč (na účtové úrovni je to `link_clicks`,
                // tady s prefixem úrovně), ať jde spočítat cena za proklik na odkaz per reklama.
                if (isset($row['inline_link_clicks']) && is_numeric($row['inline_link_clicks'])) {
                    if (allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $date, $prefix . '_link_clicks', (float) $row['inline_link_clicks'], $name)) { $w++; }
                }
                $p = allstat_meta_parse_actions($row, $conversionTypes, $valueTypes);
                if ($wantActions) {
                    allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $date, $prefix . '_conversions', $p['conversions'], $name);
                    allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $date, $prefix . '_value', $p['conversion_value'], $name);
                    $w += 2;
                }
                allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $date, $prefix . '_video_views', $p['aware']['video_view'], $name);
                $w++;
                if ($withVideo) {
                    allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $date, $prefix . '_thruplays', allstat_meta_sum_action_field($row['video_thruplay_watched_actions'] ?? null), $name);
                    $w++;
                }
            }
            return $w;
        };
        foreach ([['campaign', 'campaign_name', 'campaign', false], ['adset', 'adset_name', 'adset', false], ['ad', 'ad_name', 'ad', true]] as [$lvl, $nameField, $prefix, $withVideo]) {
            try { $written += $breakdown($lvl, $nameField, $prefix, $withVideo); } catch (Throwable) { /* per-level best-effort */ }
        }

        // 3) Demografie a umístění reklam: age / gender / region / publisher_platform (impressions per hodnota,
        //    denně → dimension). Best-effort: od 6. 8. 2026 mohou region/publisher_platform bez opt-inu v Ads
        //    Manageru vrátit chybu → daný breakdown se jen přeskočí, zbytek (a účtová data) zůstává.
        $demoBreakdown = function (string $bd, string $key)
                use ($base, $id, $timeRange, $token, $stmt, $domainId, $sourceId, $connectionId): int {
            $url = $base . '/' . rawurlencode($id) . '/insights?level=account&time_increment=1&breakdowns=' . $bd
                . '&fields=impressions&time_range=' . $timeRange . '&limit=500&access_token=' . urlencode($token);
            $w = 0; $guard = 0;
            while ($url !== '' && $guard < 25) {
                $resp = allstat_http_request('GET', $url, ['Accept' => 'application/json'], null, 60);
                if ($resp['status'] !== 200) { return $w; } // opt-in / nepodporováno → skip celého breakdownu
                foreach ($resp['json']['data'] ?? [] as $row) {
                    $date = (string) ($row['date_start'] ?? '');
                    $dim = trim((string) ($row[$bd] ?? ''));
                    $imp = (float) ($row['impressions'] ?? 0);
                    if ($dim === '' || $imp <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { continue; }
                    if (allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $date, $key, $imp, $dim)) { $w++; }
                }
                $url = (string) ($resp['json']['paging']['next'] ?? '');
                $guard++;
            }
            return $w;
        };
        foreach ([['age', 'ads_age'], ['gender', 'ads_gender'], ['region', 'ads_region'], ['publisher_platform', 'ads_platform']] as [$bd, $key]) {
            try { $written += $demoBreakdown($bd, $key); } catch (Throwable) { /* breakdown best-effort */ }
        }

        // 4) Souhrny za celou dobu kampaní / sestav / reklam (skutečná frekvence + cíl kampaně). Jen u
        //    aktuálního okna (cron, ruční sync, poslední chunk backfillu), ne u každého historického měsíce:
        //    souhrn je pokaždé stejný „za celou dobu", 16 měsíčních chunků by ho jen 16× zbytečně stahovalo.
        if ($endDate >= (new DateTimeImmutable('today'))->modify('-2 days')->format('Y-m-d')) {
            try { $written += allstat_meta_ads_sync_totals($pdo, $base, $id, $token, $connectionId); } catch (Throwable) { /* souhrny jsou bonus */ }
        }

        return ['rows' => $written, 'summary' => sprintf('%d metrik (%s → %s)', $written, $startDate, $endDate)];
    }

    // page / ig insights. Meta keeps retiring Page Insights metrics (page_impressions + page_fans
    // already gone 11/2025; more on 15. 6. 2026) and a single invalid metric 400s the WHOLE request.
    // So: try the batch, and on a 400 retry each metric alone, skipping (and reporting) the dead ones —
    // the sync stays green and self-heals as Meta's metric set shifts.
    $apiKeys = array_keys($metrics);
    $skipped = [];

    // Dead-metric mute cache (per connection). Meta keeps retiring Page/IG insights metrics and a single
    // invalid one 400s the WHOLE batch, which then triggers a per-metric retry storm on EVERY sync. We
    // remember rejected metrics for 30 days (in allstat_settings) and skip them, so the daily sync stops
    // re-probing dead metrics. They auto-revive after 30 days (one re-probe), so the set self-heals.
    $muteKey = 'meta.dead.' . $connectionId;
    $muteStmt = $pdo->prepare('SELECT setting_value FROM allstat_settings WHERE setting_key = ?');
    $muteStmt->execute([$muteKey]);
    $muteVal = $muteStmt->fetchColumn();
    $muted = $muteVal ? (json_decode((string) $muteVal, true) ?: []) : [];
    $today = date('Y-m-d');
    $saveMuted = function () use ($pdo, $muteKey, &$muted): void {
        $pdo->prepare('INSERT INTO allstat_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute([$muteKey, json_encode($muted)]);
    };
    $active = array_values(array_filter($apiKeys, static fn($m) => !isset($muted[$m]) || (string) $muted[$m] < $today));
    if (!$active) { $active = $apiKeys; } // never mute everything, force a re-probe

    $fetch = static function (array $names) use ($base, $id, $startDate, $endDate, $token): array {
        $url = $base . '/' . rawurlencode($id) . '/insights?metric=' . urlencode(implode(',', $names))
            . '&period=day&since=' . urlencode($startDate) . '&until=' . urlencode($endDate)
            . '&access_token=' . urlencode($token);
        return allstat_http_request('GET', $url, ['Accept' => 'application/json'], null, 60);
    };

    $writeSeries = function (array $data) use (&$written, $metrics, $stmt, $domainId, $sourceId, $connectionId): void {
        foreach ($data as $series) {
            $apiKey = (string) ($series['name'] ?? '');
            $localKey = $metrics[$apiKey] ?? null;
            if (!$localKey) { continue; }
            foreach ($series['values'] ?? [] as $point) {
                $date = substr((string) ($point['end_time'] ?? ''), 0, 10);
                $value = $point['value'] ?? null;
                // A few metrics return a per-breakdown object instead of a scalar — sum its numeric parts.
                if (is_array($value)) { $value = array_sum(array_filter($value, 'is_numeric')); }
                if (is_numeric($value)) {
                    if (allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $date, $localKey, (float) $value)) { $written++; }
                }
            }
        }
    };

    $resp = $fetch($active);
    allstat_meta_guard($pdo, $connectionId, $resp);
    if ($resp['status'] === 200) {
        $writeSeries($resp['json']['data'] ?? []);
    } elseif ($resp['status'] === 400) {
        foreach ($active as $name) {
            $one = $fetch([$name]);
            if ($one['status'] === 200) {
                $writeSeries($one['json']['data'] ?? []);
            } else {
                $skipped[] = $name;
                $muted[$name] = date('Y-m-d', strtotime('+30 days')); // mute this dead metric for 30 days
            }
        }
        if ($skipped) { $saveMuted(); }
        if (count($skipped) === count($active)) {
            // Every metric failed — a real error (bad Page ID / permissions), not just one dead metric.
            throw new RuntimeException('Meta Graph API HTTP 400 (žádná metrika neprošla): ' . substr($resp['body'], 0, 150));
        }
    } else {
        throw new RuntimeException('Meta Graph API HTTP ' . $resp['status'] . ': ' . substr($resp['body'], 0, 180));
    }

    // Celkový počet sledujících: insights vrací jen denní PŘÍRŮSTKY (page_daily_follows /
    // follower_count), absolutní stav je object field na Page / IG Useru. Ukládá se k dnešku jako
    // followers_total (FB navíc fan_count → fans_total, skrytý), takže denními syncy postupně
    // vznikne řada vývoje; zpětnou historii Graph API nedává. Best-effort, nesmí shodit sync.
    if ($object === 'page' || $object === 'ig') {
        try {
            $snapFields = $object === 'page' ? 'followers_count,fan_count' : 'followers_count';
            $snapResp = allstat_http_request('GET', $base . '/' . rawurlencode($id) . '?fields=' . $snapFields
                . '&access_token=' . urlencode($token), ['Accept' => 'application/json'], null, 30);
            if ($snapResp['status'] === 200) {
                $snapDate = date('Y-m-d');
                if (is_numeric($snapResp['json']['followers_count'] ?? null)) {
                    if (allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $snapDate, 'followers_total', (float) $snapResp['json']['followers_count'])) { $written++; }
                }
                if ($object === 'page' && is_numeric($snapResp['json']['fan_count'] ?? null)) {
                    if (allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $snapDate, 'fans_total', (float) $snapResp['json']['fan_count'])) { $written++; }
                }
            }
        } catch (Throwable) { /* snapshot je bonus */ }
    }

    // IG: demografie sledujících (věk/pohlaví/země/město) + okruh (dosah/zobrazení podle sledující/nesledující).
    // Ověřeno sondou 2026-07-14: breakdown existuje JEN na metric_type=total_value (time_series ho nepodporuje).
    // Vše best-effort a izolované — chybějící read_insights / práh 100 sledujících jen přeskočí danou část.
    if ($object === 'ig') {
        $today = date('Y-m-d');
        // Demografie = lifetime snímek → uloží se na dnešní datum, view čte poslední den.
        foreach (['age' => 'dem_age', 'gender' => 'dem_gender', 'country' => 'dem_country', 'city' => 'dem_city'] as $bd => $key) {
            try {
                $u = $base . '/' . rawurlencode($id) . '/insights?metric=follower_demographics&period=lifetime'
                    . '&metric_type=total_value&timeframe=this_month&breakdown=' . $bd . '&access_token=' . urlencode($token);
                $r = allstat_http_request('GET', $u, ['Accept' => 'application/json'], null, 30);
                foreach ($r['json']['data'][0]['total_value']['breakdowns'][0]['results'] ?? [] as $res) {
                    $dim = trim((string) ($res['dimension_values'][0] ?? ''));
                    if ($dim !== '' && is_numeric($res['value'] ?? null)
                        && allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $today, $key, (float) $res['value'], $dim)) {
                        $written++;
                    }
                }
            } catch (Throwable) { /* demografie best-effort (práh 100 sledujících / oprávnění) */ }
        }
        // Okruh = dosah/zobrazení podle follow_type, per den (total_value agreguje přes okno, takže voláme
        // 1denní okna). JEN posledních ~45 dní OD DNEŠKA (recent audience) protnuto s oknem syncu — starý
        // backfill okruh přeskočí (0 volání), ať se API nevytíží; incremental / recentní chunk ho stáhne.
        try {
            $today = new DateTimeImmutable('today');
            $last = new DateTimeImmutable($endDate);
            if ($last > $today) { $last = $today; }
            $floor = $today->modify('-44 days');
            $lo = new DateTimeImmutable($startDate);
            $from = $lo > $floor ? $lo : $floor;
            $okruhMetrics = ($from <= $last) ? ['reach' => 'reach_follow', 'views' => 'views_follow'] : [];
            foreach ($okruhMetrics as $metric => $key) {
                for ($day = $from; $day <= $last; $day = $day->modify('+1 day')) {
                    $u = $base . '/' . rawurlencode($id) . '/insights?metric=' . $metric . '&period=day&metric_type=total_value'
                        . '&breakdown=follow_type&since=' . $day->getTimestamp() . '&until=' . $day->modify('+1 day')->getTimestamp()
                        . '&access_token=' . urlencode($token);
                    $r = allstat_http_request('GET', $u, ['Accept' => 'application/json'], null, 25);
                    foreach ($r['json']['data'][0]['total_value']['breakdowns'][0]['results'] ?? [] as $res) {
                        $dim = trim((string) ($res['dimension_values'][0] ?? ''));
                        if ($dim !== '' && is_numeric($res['value'] ?? null)) {
                            allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $day->format('Y-m-d'), $key, (float) $res['value'], $dim);
                            $written++;
                        }
                    }
                }
            }
        } catch (Throwable) { /* okruh best-effort */ }
    }

    // Per-post engagement (FB posts + Reels + Stories / IG media) → social_posts, for the "Top
    // příspěvky" view + post counts. CRITICAL: we request the post LIST with LIGHT fields and NO
    // inline reactions.summary / comments.summary — Facebook silently DROPS a post from a list query
    // when it can't compute that summary (e.g. some ZoomSphere / third-party-published posts;
    // verified live: the same window returned 4 posts without summary but only 3 with it). Engagement,
    // reach/impressions/clicks, reaction breakdown and post metadata (status_type / object_id) are all
    // fetched separately via batched ?ids= object lookups, which do NOT drop posts — and each as its OWN
    // call so a missing read_insights permission only zeroes the insights, never the base engagement.
    // We also page in small sub-windows (FB's since/until is flaky on wide windows). Best-effort.
    $postsWritten = 0;
    $network = $object === 'ig' ? 'instagram' : 'facebook';
    $tz = new DateTimeZone((string) ($config['app']['timezone'] ?? 'Europe/Prague'));
    $postStmt = $pdo->prepare("
        INSERT INTO social_posts
            (domain_id, connection_id, metric_date, published_at, network, post_type, post_format, post_id, message, permalink,
             reactions, r_like, r_love, r_haha, r_wow, r_sad, r_angry, reactions_viral, comments, comments_viral, shares, saved, total_interactions, story_replies, story_navigation, impressions, post_clicks, link_clicks, clicks_json, reach, fan_reach, video_views, watch_time_sec, plays, engagement, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?,  ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE metric_date = VALUES(metric_date), published_at = VALUES(published_at),
            post_type = IF(post_type = 'reel' AND VALUES(post_type) = 'post', 'reel', VALUES(post_type)),
            post_format = CASE WHEN VALUES(post_format) IN ('', 'status') AND post_format NOT IN ('', 'status') THEN post_format
                               WHEN post_format = 'reel' AND VALUES(post_format) = 'video' THEN 'reel'
                               ELSE VALUES(post_format) END,
            message = VALUES(message), permalink = VALUES(permalink), reactions = VALUES(reactions),
            r_like = VALUES(r_like), r_love = VALUES(r_love), r_haha = VALUES(r_haha), r_wow = VALUES(r_wow), r_sad = VALUES(r_sad), r_angry = VALUES(r_angry),
            reactions_viral = GREATEST(reactions_viral, VALUES(reactions_viral)), comments_viral = GREATEST(comments_viral, VALUES(comments_viral)),
            link_clicks = GREATEST(link_clicks, VALUES(link_clicks)), clicks_json = VALUES(clicks_json), fan_reach = GREATEST(fan_reach, VALUES(fan_reach)),
            comments = VALUES(comments), shares = GREATEST(shares, VALUES(shares)),
            saved = GREATEST(saved, VALUES(saved)), total_interactions = GREATEST(total_interactions, VALUES(total_interactions)),
            story_replies = GREATEST(story_replies, VALUES(story_replies)), story_navigation = GREATEST(story_navigation, VALUES(story_navigation)),
            impressions = GREATEST(impressions, VALUES(impressions)), post_clicks = GREATEST(post_clicks, VALUES(post_clicks)),
            reach = GREATEST(reach, VALUES(reach)), video_views = VALUES(video_views),
            watch_time_sec = GREATEST(watch_time_sec, VALUES(watch_time_sec)), plays = GREATEST(plays, VALUES(plays)),
            engagement = VALUES(reactions) + VALUES(comments) + GREATEST(shares, VALUES(shares)), updated_at = NOW()
    ");
    // Hodnoty, které doplňuje ruční import CSV z Business Suite (admin/social-import.php), se tu drží přes
    // GREATEST: čísla za celou dobu příspěvku jen rostou a 0 nebo nižší číslo z API nesmí smazat, co přišlo
    // z CSV (dosah, zobrazení, kliknutí, cizí reakce a komentáře, sdílení, uložení, doba sledování). Dřív
    // synchronizace (i „Stáhnout historii") dosah a zobrazení přepsala a CSV se muselo nahrát znovu (29. 9. 2026).
    // Typ a formát: obecný údaj z API ('status' nebo prázdný) nepřepíše přesnější z CSV a reel nezmění zpět
    // na video/příspěvek; konkrétní formát z API má přednost (API rozlišuje reels a videa, export ne).
    // Engagement se skládá ze stejných výrazů jako SET, ne z VALUES(engagement), a nezávisí na pořadí
    // vyhodnocení přiřazení (MariaDB SIMULTANEOUS_ASSIGNMENT); post_type a post_format čtou jen svou starou hodnotu.
    // One assoc-array store so callers pass only what an edge actually has (everything else defaults to 0).
    $store = function (array $row) use (&$postsWritten, $postStmt, $domainId, $connectionId, $network): void {
        $pid = (string) ($row['post_id'] ?? '');
        $date = (string) ($row['metric_date'] ?? '');
        if ($pid === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return; }
        $rea = (int) ($row['reactions'] ?? 0); $com = (int) ($row['comments'] ?? 0); $sha = (int) ($row['shares'] ?? 0);
        $pub = ((string) ($row['published_at'] ?? '')) !== '' ? (string) $row['published_at'] : null;
        $postStmt->execute([
            $domainId, $connectionId, $date, $pub, $network, (string) ($row['post_type'] ?? 'post'), (string) ($row['post_format'] ?? ''),
            mb_substr($pid, 0, 150), mb_substr((string) ($row['message'] ?? ''), 0, 500), mb_substr((string) ($row['permalink'] ?? ''), 0, 400),
            $rea, (int) ($row['r_like'] ?? 0), (int) ($row['r_love'] ?? 0), (int) ($row['r_haha'] ?? 0), (int) ($row['r_wow'] ?? 0), (int) ($row['r_sad'] ?? 0), (int) ($row['r_angry'] ?? 0),
            (int) ($row['reactions_viral'] ?? 0),
            $com, (int) ($row['comments_viral'] ?? 0), $sha, (int) ($row['saved'] ?? 0), (int) ($row['total_interactions'] ?? 0), (int) ($row['story_replies'] ?? 0), (int) ($row['story_navigation'] ?? 0), (int) ($row['impressions'] ?? 0), (int) ($row['post_clicks'] ?? 0),
            (int) ($row['link_clicks'] ?? 0), mb_substr((string) ($row['clicks_json'] ?? ''), 0, 255), (int) ($row['reach'] ?? 0), (int) ($row['fan_reach'] ?? 0), (int) ($row['video_views'] ?? 0),
            (int) ($row['watch_time_sec'] ?? 0), (int) ($row['plays'] ?? 0), $rea + $com + $sha,
        ]);
        $postsWritten++;
    };
    // Small overlapping sub-windows (~7 days, since = subStart-2) — FB's since/until is flaky on wide
    // windows; small windows reliably return in-range posts. Covers incremental + month backfills.
    $windows = [];
    try {
        $cur = new DateTimeImmutable($startDate);
        $endDt = new DateTimeImmutable($endDate);
        while ($cur <= $endDt) {
            $subEnd = $cur->modify('+6 days');
            if ($subEnd > $endDt) { $subEnd = $endDt; }
            $windows[] = [$cur->modify('-2 days')->format('Y-m-d'), $subEnd->format('Y-m-d')];
            $cur = $subEnd->modify('+1 day');
        }
    } catch (Throwable) {
        $windows = [[$startDate, $endDate]];
    }
    // Local (Europe/Prague) publish date+time per row — fixes the UTC-slice off-by-one (see allstat_meta_local_time).
    $timePost = static fn (array $r): array => allstat_meta_local_time((string) ($r['created_time'] ?? $r['timestamp'] ?? ''), $tz);
    $timeStory = static fn (array $r): array => allstat_meta_local_time((string) ($r['creation_time'] ?? ''), $tz);

    // Collect an edge's posts across the sub-windows with LIGHT fields → keyed by post_id (dedup),
    // each value = ['day' => Y-m-d, 'dt' => Y-m-d H:i:s, 'p' => raw row]. No summary fields here (they drop posts).
    $collect = function (string $edge, string $listFields, callable $timeFn)
            use ($base, $id, $windows, $startDate, $endDate, $token): array {
        $rows = [];
        foreach ($windows as [$ws, $we]) {
            // `until` musí být AŽ ZA posledním dnem okna: Facebook bere holé datum jako půlnoc, takže
            // příspěvek publikovaný v poslední den okna třeba v 10:09 se do výsledku nevejde. U vnitřních
            // sub-oken to zachraňoval dvoudenní překryv `since`, ale poslední sub-okno dávky už nic
            // nenásleduje — proto měly příspěvky z posledního dne měsíční dávky backfillu samé nuly
            // (ověřeno 17. 8. 2026: 15. 2., 15. 3., 15. 4., 15. 5. a 15. 8.). Rozsah se stejně ještě
            // filtruje níž podle $startDate/$endDate, takže širší `until` nic navíc nepropustí.
            $until = $we;
            try { $until = (new DateTimeImmutable($we))->modify('+1 day')->format('Y-m-d'); } catch (Throwable) { /* ponech */ }
            $url = $base . '/' . rawurlencode($id) . '/' . $edge . '?fields=' . rawurlencode($listFields)
                . '&since=' . urlencode($ws) . '&until=' . urlencode($until) . '&limit=50&access_token=' . urlencode($token);
            $guard = 0;
            while ($url !== '' && $guard < 20) {
                $resp = allstat_http_request('GET', $url, ['Accept' => 'application/json'], null, 60);
                if ($resp['status'] !== 200) { break; }
                $data = $resp['json']['data'] ?? [];
                if (!$data) { break; }
                foreach ($data as $p) {
                    $pid = (string) ($p['id'] ?? '');
                    $t = $timeFn($p);
                    $day = $t['date'];
                    if ($pid === '' || $day === '' || $day < $startDate || $day > $endDate) { continue; }
                    $rows[$pid] = ['day' => $day, 'dt' => $t['dt'], 'p' => $p];
                }
                $url = (string) ($resp['json']['paging']['next'] ?? '');
                $guard++;
            }
        }
        return $rows;
    };
    // Batch a field set by ids (chunks of 50): GET /?ids=a,b&fields=... A direct object lookup, so it does
    // NOT drop posts the way a list+summary query does. Returns id => row. Best-effort: a non-200 chunk
    // (e.g. read_insights not granted) just yields no entries for that chunk — callers default to 0.
    $batchById = function (array $ids, string $fields) use ($base, $token): array {
        $out = [];
        foreach (array_chunk($ids, 50) as $chunk) {
            $url = $base . '/?ids=' . rawurlencode(implode(',', $chunk)) . '&fields=' . rawurlencode($fields) . '&access_token=' . urlencode($token);
            $resp = allstat_http_request('GET', $url, ['Accept' => 'application/json'], null, 60);
            if ($resp['status'] !== 200) { continue; }
            foreach (($resp['json'] ?? []) as $pid => $obj) {
                if (is_array($obj)) { $out[(string) $pid] = $obj; }
            }
        }
        return $out;
    };
    // Parse a post-insights object into our fields. The batched ?ids=&fields=insights.metric(...){name,values}
    // form returns entries WITH `name` (we also fall back to the `id` path); reactions arrive as ONE
    // post_reactions_by_type_total metric whose value is a {like,love,haha,wow,sorry,anger} object.
    // VERIFIED LIVE on a production account: reactions.summary 403s, but these insight metrics all return 200.
    $insOf = static function (array $obj): array {
        $out = ['reach' => 0, 'impressions' => 0, 'post_clicks' => 0, 'video_views' => 0, 'watch_time' => 0, 'reactions_total' => 0, 'comments' => 0,
                'saved' => 0, 'total_interactions' => 0, 'shares' => 0, 'replies' => 0, 'navigation' => 0, 'link_clicks' => 0, 'clicks_by_type' => [], 'fan_reach' => 0,
                'r_like' => 0, 'r_love' => 0, 'r_haha' => 0, 'r_wow' => 0, 'r_sad' => 0, 'r_angry' => 0];
        foreach (($obj['insights']['data'] ?? []) as $m) {
            $name = (string) ($m['name'] ?? '');
            if ($name === '' && preg_match('#/insights/([^/]+)/#', (string) ($m['id'] ?? ''), $mm)) { $name = $mm[1]; }
            $val = $m['values'][0]['value'] ?? 0;
            // POZOR: Facebook vrací některé metriky v jedné odpovědi DVAKRÁT — nejdřív lifetime hodnotu a na
            // konci ještě denní řez (s end_time), který bývá 0. Slepý přepis v tomhle cyklu proto nulovaly
            // dosah u ~92 % příspěvků (ověřeno živě 17. 8. 2026 na post_total_media_view_unique: 2244, pak 0).
            // Skalární metriky se kvůli tomu zapisují přes max(), aby pozdější nula nepřebila platnou hodnotu.
            $keepMax = static function (int $old, mixed $new): int { $n = (int) $new; return $n > $old ? $n : $old; };
            switch ($name) {
                // reach: FB new media-views-unique / legacy unique-impressions, OR IG media's plain `reach`.
                case 'reach':
                case 'post_total_media_view_unique':
                case 'post_impressions_unique': $out['reach'] = $keepMax($out['reach'], $val); break;
                // total views/impressions: new media_view (FB) / views (IG universal metric) replaces retired impressions.
                case 'views':
                case 'post_media_view':
                case 'post_impressions': $out['impressions'] = $keepMax($out['impressions'], $val); break;
                case 'post_clicks': $out['post_clicks'] = $keepMax($out['post_clicks'], $val); break;
                // Rozpad kliků (Business Suite „Kliknutí na odkazy" je položka „link clicks"; zbytek jsou
                // prokliky fotky, rozbalení textu apod. — proto se nesmí míchat do jednoho čísla).
                case 'post_clicks_by_type':
                    if (is_array($val)) {
                        $out['clicks_by_type'] = array_map('intval', array_filter($val, 'is_numeric'));
                        $out['link_clicks'] = (int) ($val['link clicks'] ?? $val['link_clicks'] ?? 0);
                    }
                    break;
                case 'post_fan_reach': $out['fan_reach'] = $keepMax($out['fan_reach'], $val); break;
                // IG media: uložení + celkové interakce (like+comment+save+share). Signál kvality obsahu.
                case 'saved': $out['saved'] = $keepMax($out['saved'], $val); break;
                case 'total_interactions': $out['total_interactions'] = $keepMax($out['total_interactions'], $val); break;
                // IG media: sdílení příspěvku (FB bere sdílení z pole shares.count v seznamu příspěvků, ne z insights).
                case 'shares': $out['shares'] = $keepMax($out['shares'], $val); break;
                // IG Stories: odpovědi + navigace (celkem posunů/odchodů). Signál retence stories.
                case 'replies': $out['replies'] = (int) $val; break;
                case 'navigation': $out['navigation'] = is_numeric($val) ? (int) $val : (is_array($val) ? array_sum(array_map('intval', array_filter($val, 'is_numeric'))) : 0); break;
                case 'post_video_views': $out['video_views'] = $keepMax($out['video_views'], $val); break;
                // Watch time in MS (converted to sec at store). FB post_video_view_time / IG ig_reels_video_view_total_time.
                case 'post_video_view_time':
                case 'ig_reels_video_view_total_time': $out['watch_time'] = $keepMax($out['watch_time'], $val); break;
                case 'post_reactions_by_type_total':
                    if (is_array($val)) {
                        $out['r_like'] = (int) ($val['like'] ?? 0); $out['r_love'] = (int) ($val['love'] ?? 0);
                        $out['r_haha'] = (int) ($val['haha'] ?? 0); $out['r_wow'] = (int) ($val['wow'] ?? 0);
                        $out['r_sad'] = (int) ($val['sorry'] ?? $val['sad'] ?? 0); $out['r_angry'] = (int) ($val['anger'] ?? $val['angry'] ?? 0);
                        $out['reactions_total'] = array_sum(array_map('intval', array_filter($val, 'is_numeric')));
                    }
                    break;
                // Comment count: comments.summary(total_count) as a FIELD returns null on this page (verified
                // live), but this insight's {comment} value is correct → that's where comments come from.
                case 'post_activity_by_action_type':
                    if (is_array($val)) { $out['comments'] = (int) ($val['comment'] ?? 0); }
                    break;
            }
        }
        return $out;
    };
    // Media format (photo/video/reel/link/status/share) from status_type + permalink — for the "podle typu" breakdown.
    $formatOf = static function (array $p, array $meta): string {
        if (stripos((string) ($p['permalink_url'] ?? ''), '/reel') !== false) { return 'reel'; }
        return match ((string) ($meta['status_type'] ?? '')) {
            'added_photos' => 'photo',
            'added_video' => 'video',
            'shared_story' => 'share',
            'mobile_status_update', 'created_note' => 'status',
            default => (trim((string) ($p['message'] ?? '')) !== '' ? 'status' : ''),
        };
    };

    try {
        if ($object === 'ig') {
            // IG media: scalar like_count / comments_count (engagement) + per-post reach via a batched media
            // insights call (insights.metric(reach) — verified live; reach is the universal IG media metric).
            $media = $collect('media', 'id,caption,timestamp,permalink,like_count,comments_count,media_type,media_product_type', $timePost);
            $mids = array_keys($media);
            $mins = $mids ? $batchById($mids, 'insights.metric(reach){name,values}') : [];
            // Views = IG's universal impressions replacement (feed/reel/story/video). Isolated call so a media
            // type that lacks `views` can't void the reach batch. Stored to impressions ("Zobrazení").
            $mviews = $mids ? $batchById($mids, 'insights.metric(views){name,values}') : [];
            // Uložení + celkové interakce (like+comment+save+share) — kvalita obsahu. Isolovaně, ať typ média
            // bez některé metriky nevoidne dávku. „saved"/„total_interactions" jsou univerzální IG media metriky.
            $msaved = $mids ? $batchById($mids, 'insights.metric(saved,total_interactions){name,values}') : [];
            // Sdílení (univerzální IG media metrika pro feed i reels). Bez ní měl AllStat u IG všude 0, přitom
            // Business Suite ukazoval 206 sdílení za 3 měsíce (29. 9. 2026). Izolovaně jako ostatní metriky.
            $mshares = $mids ? $batchById($mids, 'insights.metric(shares){name,values}') : [];
            // Reels watch time (ms). Isolated; ig_reels_video_view_total_time only exists on REELS media.
            $mwatch = $mids ? $batchById($mids, 'insights.metric(ig_reels_video_view_total_time){name,values}') : [];
            foreach ($media as $pid => $r) {
                $p = $r['p'];
                // Distinguish reels from feed posts: media_product_type = REELS (else FEED / AD).
                $isReel = strtoupper((string) ($p['media_product_type'] ?? '')) === 'REELS';
                $store([
                    'post_id' => $pid, 'metric_date' => $r['day'], 'published_at' => $r['dt'],
                    'post_type' => $isReel ? 'reel' : 'post',
                    'post_format' => $isReel ? 'reel' : strtolower((string) ($p['media_type'] ?? '')),
                    'message' => (string) ($p['caption'] ?? ''), 'permalink' => (string) ($p['permalink'] ?? ''),
                    'reactions' => (int) ($p['like_count'] ?? 0), 'comments' => (int) ($p['comments_count'] ?? 0),
                    'reach' => $insOf($mins[$pid] ?? [])['reach'],
                    'impressions' => $insOf($mviews[$pid] ?? [])['impressions'],
                    'saved' => $insOf($msaved[$pid] ?? [])['saved'],
                    'total_interactions' => $insOf($msaved[$pid] ?? [])['total_interactions'],
                    'shares' => $insOf($mshares[$pid] ?? [])['shares'],
                    'watch_time_sec' => $isReel ? intdiv($insOf($mwatch[$pid] ?? [])['watch_time'], 1000) : 0,
                ]);
            }
            // IG Stories — ephemeral (~24 h), captured only while live (run cron/story-sync.php frequently).
            // Best-effort: reach + views if the account exposes them; IG hides stories with < 5 views.
            try {
                $igStories = $collect('stories', 'id,media_type,timestamp,permalink', $timeStory);
                $sids = array_keys($igStories);
                // reach + views (známé dobré) v jedné dávce; replies a navigation ISOLOVANĚ, ať jejich
                // případná deprecace/nedostupnost nevoidne reach/views.
                $sins = $sids ? $batchById($sids, 'insights.metric(reach,views){name,values}') : [];
                $srepl = $sids ? $batchById($sids, 'insights.metric(replies){name,values}') : [];
                $snav = $sids ? $batchById($sids, 'insights.metric(navigation){name,values}') : [];
                foreach ($igStories as $spid => $sr) {
                    $sp = $sr['p'];
                    $si = $insOf($sins[$spid] ?? []);
                    $store([
                        'post_id' => $spid, 'metric_date' => $sr['day'], 'published_at' => $sr['dt'], 'post_type' => 'story', 'post_format' => 'story',
                        'message' => '(Story' . (($sp['media_type'] ?? '') !== '' ? ' ' . strtolower((string) $sp['media_type']) : '') . ')',
                        'permalink' => (string) ($sp['permalink'] ?? ''),
                        'reach' => $si['reach'], 'impressions' => $si['impressions'],
                        'story_replies' => $insOf($srepl[$spid] ?? [])['replies'],
                        'story_navigation' => $insOf($snav[$spid] ?? [])['navigation'],
                    ]);
                }
            } catch (Throwable) { /* IG stories edge unsupported / none active */ }
        } else {
            // Page posts: light list (returns ALL posts incl. third-party-published) + separate batched insights.
            // Reactions come from the post_reactions_by_type_total INSIGHT (the reactions.summary field is
            // permission-blocked → HTTP 403); comments via .summary and shares from the list field both work.
            $posts = $collect('published_posts', 'id,message,created_time,permalink_url,shares', $timePost);
            $ids = array_keys($posts);
            $met = $ids ? $batchById($ids, 'status_type') : [];
            // REAKCE A KOMENTÁŘE NA ORIGINÁLU — přesně to, co ukazuje Meta Business Suite u příspěvku.
            // Insight post_reactions_by_type_total počítá i reakce na PŘESDÍLENÍCH („stories created about your
            // post"), takže u sdíleného obsahu nadhodnocuje (ověřeno 17. 8. 2026: insight 89 vs. reálných 36 při
            // 10 sdíleních) a u příspěvků typu created_event vrací prázdno, tedy nulu místo reálných reakcí.
            // Summary edge tenhle problém nemá. Historický komentář tvrdil, že 403uje — to už neplatí, ověřeno
            // živě na produkčním účtu. Drží se ale pravidlo výše: summary NIKDY v list dotazu (tam mizí příspěvky),
            // vždy jen v dávkovém ?ids= lookupu, který nic nezahazuje.
            $rxTypes = ['LIKE' => 'rx_like', 'LOVE' => 'rx_love', 'HAHA' => 'rx_haha', 'WOW' => 'rx_wow', 'SAD' => 'rx_sad', 'ANGRY' => 'rx_angry'];
            $rxFields = ['reactions.limit(0).summary(total_count).as(rx_all)', 'comments.limit(0).summary(total_count).as(cm_all)'];
            foreach ($rxTypes as $t => $alias) { $rxFields[] = "reactions.type($t).limit(0).summary(total_count).as($alias)"; }
            $edgeRx = $ids ? $batchById($ids, implode(',', $rxFields)) : [];
            $sumOf = static fn (array $o, string $k): ?int => isset($o[$k]['summary']['total_count']) ? (int) $o[$k]['summary']['total_count'] : null;
            // Future-proof insight set (all survive the 15.6.2026 cull): new reach + total views + clicks +
            // reactions-by-type + activity-by-type. The last one carries the COMMENT count — comments.summary
            // as a field-expansion returns null on this page (verified live), so comments come from here.
            $insMain = $ids ? $batchById($ids, 'insights.metric(post_total_media_view_unique,post_media_view,post_clicks,post_clicks_by_type,post_fan_reach,post_reactions_by_type_total,post_activity_by_action_type){name,values}') : [];
            // Legacy reach (dies 15.6.2026) and 3s video (no replacement) — isolated so each death can't void the rest.
            $insLegacy = $ids ? $batchById($ids, 'insights.metric(post_impressions_unique){name,values}') : [];
            $insVideo = $ids ? $batchById($ids, 'insights.metric(post_video_views,post_video_view_time){name,values}') : [];
            foreach ($posts as $pid => $r) {
                $p = $r['p'];
                $m = $met[$pid] ?? [];
                $fmt = $formatOf($p, $m);
                $main = $insOf($insMain[$pid] ?? []);
                $legacy = $insOf($insLegacy[$pid] ?? []);
                $ex = $edgeRx[$pid] ?? [];
                // Edge je zdroj pravdy pro reakce/komentáře; insight zůstává jen jako fallback (kdyby edge
                // chybělo) a jako samostatná „virální" hodnota do detailu. null = edge nic nevrátil.
                $eAll = $sumOf($ex, 'rx_all');
                $eCom = $sumOf($ex, 'cm_all');
                $store([
                    'post_id' => $pid, 'metric_date' => $r['day'], 'published_at' => $r['dt'],
                    'post_type' => $fmt === 'reel' ? 'reel' : 'post', 'post_format' => $fmt,
                    'message' => (string) ($p['message'] ?? ''), 'permalink' => (string) ($p['permalink_url'] ?? ''),
                    'reactions' => $eAll ?? $main['reactions_total'],
                    'comments' => $eCom ?? $main['comments'],
                    'reactions_viral' => $main['reactions_total'],
                    'comments_viral' => $main['comments'],
                    'shares' => (int) ($p['shares']['count'] ?? 0),
                    'reach' => $main['reach'] ?: $legacy['reach'],
                    'fan_reach' => $main['fan_reach'],
                    'impressions' => $main['impressions'],
                    'post_clicks' => $main['post_clicks'],
                    'link_clicks' => $main['link_clicks'],
                    'clicks_json' => $main['clicks_by_type'] ? json_encode($main['clicks_by_type'], JSON_UNESCAPED_UNICODE) : '',
                    'video_views' => $insOf($insVideo[$pid] ?? [])['video_views'],
                    'watch_time_sec' => intdiv($insOf($insVideo[$pid] ?? [])['watch_time'], 1000),
                    // Rozpad po typech taky z edge, ať sedí součet na celkové číslo. Bez edge padá na insight.
                    'r_like' => $sumOf($ex, 'rx_like') ?? $main['r_like'], 'r_love' => $sumOf($ex, 'rx_love') ?? $main['r_love'],
                    'r_haha' => $sumOf($ex, 'rx_haha') ?? $main['r_haha'], 'r_wow' => $sumOf($ex, 'rx_wow') ?? $main['r_wow'],
                    'r_sad' => $sumOf($ex, 'rx_sad') ?? $main['r_sad'], 'r_angry' => $sumOf($ex, 'rx_angry') ?? $main['r_angry'],
                ]);
            }
            // NOTE: we deliberately do NOT pull the video_reels edge. Reels published by the page already appear
            // in published_posts with a real pageid_postid id, so post insights (reach/clicks/reactions) work on
            // them; the video_reels edge returns BARE video ids on which post insights 400 (reach stays 0) and it
            // costs extra API calls. published_posts is the single source for reels.
            // Stories — ephemeral (~24 h), best-effort; engagement not exposed via API. Stored but EXCLUDED
            // from the "počet příspěvků" / frequency KPIs in the repository (post_type = 'story').
            try {
                $fbStories = $collect('stories', 'id,creation_time,media_type,permalink_url', $timeStory);
                $stIds = array_keys($fbStories);
                // Story insights are limited and only available while live; best-effort views + reach.
                $stIns = $stIds ? $batchById($stIds, 'insights.metric(post_impressions,post_impressions_unique){name,values}') : [];
                foreach ($fbStories as $pid => $r) {
                    $p = $r['p'];
                    $si = $insOf($stIns[$pid] ?? []);
                    $store([
                        'post_id' => $pid, 'metric_date' => $r['day'], 'published_at' => $r['dt'], 'post_type' => 'story', 'post_format' => 'story',
                        'message' => '(Story' . (($p['media_type'] ?? '') !== '' ? ' ' . strtolower((string) $p['media_type']) : '') . ')',
                        'permalink' => (string) ($p['permalink_url'] ?? ''),
                        'reach' => $si['reach'], 'impressions' => $si['impressions'],
                    ]);
                }
            } catch (Throwable) { /* stories edge unsupported / no active stories */ }
        }
    } catch (Throwable) {
        // posts/media edge unsupported or permission-limited — keep page metrics.
    }

    $summary = sprintf('%d metrik, %d příspěvků (%s → %s)', $written, $postsWritten, $startDate, $endDate);
    if ($skipped) {
        $summary .= '; přeskočeno (zrušené/neplatné): ' . implode(', ', $skipped);
    }
    return ['rows' => $written, 'posts' => $postsWritten, 'summary' => $summary];
}

/**
 * LinkedIn organizationalEntityShareStatistics with daily time granularity.
 */
function allstat_engine_linkedin(PDO $pdo, array $config, array $connection, string $token, string $startDate, string $endDate, array $recipe): array
{
    $base = rtrim((string) ($connection['api_base_url'] ?: 'https://api.linkedin.com/v2'), '/');
    $urn = trim((string) ($connection['property_id'] ?? ''));
    if ($urn === '') {
        throw new RuntimeException('LinkedIn: vyplň Property ID (urn:li:organization:…).');
    }
    // Accept a bare numeric id or a urn:li:company: URN and normalise to urn:li:organization:ID.
    if (preg_match('/^\d+$/', $urn)) {
        $urn = 'urn:li:organization:' . $urn;
    } elseif (preg_match('/urn:li:(?:organization|company):(\d+)/i', $urn, $m)) {
        $urn = 'urn:li:organization:' . $m[1];
    }
    $startMs = (new DateTimeImmutable($startDate))->getTimestamp() * 1000;
    $endMs = (new DateTimeImmutable($endDate))->modify('+1 day')->getTimestamp() * 1000;
    // Rest.li 2.0.0 (the X-Restli-Protocol-Version header below) wants complex params in the REDUCED
    // PARENTHETICAL form, not dot-notation. Dot-notation (timeIntervals.timeRange.start=…) → HTTP 403
    // "Unpermitted fields present in PARAMETER … [/timeIntervals…]". Parens/colons/commas go literal.
    $url = $base . '/organizationalEntityShareStatistics?q=organizationalEntity'
        . '&organizationalEntity=' . rawurlencode($urn)
        . '&timeIntervals=(timeRange:(start:' . $startMs . ',end:' . $endMs . '),timeGranularityType:DAY)';
    $resp = allstat_http_request('GET', $url, [
        'Authorization' => 'Bearer ' . $token,
        'X-Restli-Protocol-Version' => '2.0.0',
        'Accept' => 'application/json',
    ], null, 60);
    if ($resp['status'] !== 200) {
        if ($resp['status'] === 429) {
            throw new RuntimeException('LinkedIn: vyčerpán denní limit API (HTTP 429 – Development tier 500/app/den, 100/member/den; reset o půlnoci UTC). Zkus zítra.');
        }
        throw new RuntimeException('LinkedIn API HTTP ' . $resp['status'] . ': ' . substr($resp['body'], 0, 180));
    }
    $metrics = $recipe['metrics'] ?? [];
    $stmt = allstat_pm_statement($pdo);
    $domainId = (int) $connection['domain_id'];
    $sourceId = (int) $connection['source_id'];
    $connectionId = (int) $connection['id'];
    $written = 0;
    foreach ($resp['json']['elements'] ?? [] as $el) {
        $startTs = (int) ($el['timeRange']['start'] ?? 0);
        if ($startTs <= 0) { continue; }
        $date = (new DateTimeImmutable('@' . (int) ($startTs / 1000)))->format('Y-m-d');
        $stats = $el['totalShareStatistics'] ?? [];
        foreach ($metrics as $apiKey => $localKey) {
            if (isset($stats[$apiKey]) && is_numeric($stats[$apiKey])) {
                if (allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $date, $localKey, (float) $stats[$apiKey])) { $written++; }
            }
        }
    }

    // Návštěvy firemní stránky (Fáze C) – denní page_views z organizationPageStatistics (per-období,
    // jde i do historie přes backfill; best-effort, nesmí shodit sync).
    try {
        $written += allstat_linkedin_sync_page_views($pdo, $connection, $token, $urn, $startMs, $endMs, $stmt);
    } catch (Throwable $e) {
        error_log('AllStat LinkedIn page views: ' . $e->getMessage());
    }

    // Denní přírůstky sledujících (per-období, jde i do historie přes backfill; best-effort).
    try {
        $written += allstat_linkedin_sync_follower_gains($pdo, $connection, $token, $urn, $startMs, $endMs, $stmt);
    } catch (Throwable $e) {
        error_log('AllStat LinkedIn follower gains: ' . $e->getMessage());
    }

    // Celkový počet sledujících stránky: share statistics ho nemají, je to samostatný snapshot
    // endpoint /networkSizes. Ukládá se k dnešku jako followers_total (denní syncy → řada vývoje,
    // zpětnou historii API nedává). Best-effort, nesmí shodit sync.
    try {
        $nsResp = allstat_http_request('GET', $base . '/networkSizes/' . rawurlencode($urn) . '?edgeType=CompanyFollowedByMember', [
            'Authorization' => 'Bearer ' . $token,
            'X-Restli-Protocol-Version' => '2.0.0',
            'Accept' => 'application/json',
        ], null, 30);
        if ($nsResp['status'] === 200 && is_numeric($nsResp['json']['firstDegreeSize'] ?? null)) {
            if (allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, date('Y-m-d'), 'followers_total', (float) $nsResp['json']['firstDegreeSize'])) { $written++; }
        }
    } catch (Throwable) { /* snapshot je bonus */ }

    // Per-post content analytics → social_posts (network='linkedin'), best-effort (aggregate už uloženo).
    $postsWritten = 0;
    try {
        $postsWritten = allstat_linkedin_sync_posts($pdo, $config, $connection, $token, $urn, $startDate, $endDate);
    } catch (Throwable $e) {
        error_log('AllStat LinkedIn posts: ' . $e->getMessage());
    }

    // Demografie sledujících (Fáze C): organizationalEntityFollowerStatistics bez timeIntervals = celoživotní
    // rozpad po facetech (seniorita/funkce/obor/země/velikost firmy). Jsou to STAVOVÉ (lifetime) počty, ne
    // per-období → ukládají se snapshotem k dnešku a jen u AKTUÁLNÍHO syncu (endDate v posledních 2 dnech);
    // historické backfill chunky přeskočíme, ať nevznikají zavádějící „demografie k lednu" a nepálí API.
    try {
        if ($endDate >= (new DateTimeImmutable('today'))->modify('-2 days')->format('Y-m-d')) {
            $today = (new DateTimeImmutable('today'))->format('Y-m-d');
            try { $written += allstat_linkedin_sync_follower_demographics($pdo, $connection, $token, $urn, $today); }
            catch (Throwable $e) { error_log('AllStat LinkedIn follower demographics: ' . $e->getMessage()); }
            try { $written += allstat_linkedin_sync_visitor_demographics($pdo, $connection, $token, $urn, $today); }
            catch (Throwable $e) { error_log('AllStat LinkedIn visitor demographics: ' . $e->getMessage()); }
        }
    } catch (Throwable $e) {
        error_log('AllStat LinkedIn demographics gate: ' . $e->getMessage());
    }

    return ['rows' => $written, 'posts' => $postsWritten,
        'summary' => sprintf('%d metrik, %d příspěvků (%s → %s)', $written, $postsWritten, $startDate, $endDate)];
}

/**
 * Detect a LinkedIn post's media format from the /rest/posts `content` block → our post_format vocabulary
 * (photo/video/document/link/album/poll/status). Used by the LinkedIn view's "výkon podle typu obsahu".
 */
function allstat_linkedin_post_format(array $post): string
{
    $c = $post['content'] ?? null;
    if (!is_array($c)) { return 'status'; }            // no media = text-only
    if (isset($c['poll'])) { return 'poll'; }
    if (isset($c['multiImage'])) { return 'album'; }    // carousel
    if (isset($c['article'])) { return 'link'; }        // article / external link share
    if (isset($c['media'])) {
        $id = (string) ($c['media']['id'] ?? '');
        if (str_contains($id, ':video:')) { return 'video'; }
        if (str_contains($id, ':document:')) { return 'document'; }
        if (str_contains($id, ':image:')) { return 'photo'; }
        return 'photo';
    }
    return 'status';
}

/**
 * LinkedIn seniority URN (urn:li:seniority:N) → český label. Stabilní standardized-data enum (10 hodnot,
 * ověřeno z MS Learn: 1=Unpaid … 9=Partner). Neznámé id dostane neutrální fallback.
 */
function allstat_linkedin_seniority_label(string $id): string
{
    static $m = [
        '1' => 'Neplacené', '2' => 'Ve výcviku', '3' => 'Junior', '4' => 'Senior', '5' => 'Manažer',
        '6' => 'Ředitel', '7' => 'Viceprezident', '8' => 'CxO (vedení)', '9' => 'Partner', '10' => 'Majitel',
    ];
    return $m[$id] ?? ('Seniorita #' . $id);
}

/**
 * LinkedIn function URN (urn:li:function:N) → český label. Stabilní standardized-data enum (1..26,
 * ověřeno z MS Learn: 10=Finance, 22=Quality Assurance …).
 */
function allstat_linkedin_function_label(string $id): string
{
    static $m = [
        '1' => 'Účetnictví', '2' => 'Administrativa', '3' => 'Umění a design', '4' => 'Obchodní rozvoj',
        '5' => 'Komunitní a sociální služby', '6' => 'Poradenství', '7' => 'Vzdělávání', '8' => 'Inženýrství',
        '9' => 'Podnikání', '10' => 'Finance', '11' => 'Zdravotnictví', '12' => 'Lidské zdroje (HR)',
        '13' => 'IT', '14' => 'Právo', '15' => 'Marketing', '16' => 'Média a komunikace',
        '17' => 'Armáda a ochrana', '18' => 'Provoz', '19' => 'Produktový management',
        '20' => 'Projektový management', '21' => 'Nákup', '22' => 'Kvalita (QA)', '23' => 'Reality',
        '24' => 'Výzkum', '25' => 'Prodej', '26' => 'Podpora',
    ];
    return $m[$id] ?? ('Funkce #' . $id);
}

/**
 * LinkedIn staffCountRange enum → český label (velikost firmy, ve které sledující pracuje). Spojovník,
 * ne pomlčka (UI konvence).
 */
function allstat_linkedin_staff_label(string $e): string
{
    static $m = [
        'SIZE_1' => '1 zaměstnanec', 'SIZE_2_TO_10' => '2-10', 'SIZE_11_TO_50' => '11-50',
        'SIZE_51_TO_200' => '51-200', 'SIZE_201_TO_500' => '201-500', 'SIZE_501_TO_1000' => '501-1 000',
        'SIZE_1001_TO_5000' => '1 001-5 000', 'SIZE_5001_TO_10000' => '5 001-10 000',
        'SIZE_10001_OR_MORE' => '10 001+',
    ];
    return $m[$e] ?? $e;
}

/**
 * Best-effort dávkové přeložení LinkedIn standardized URN (geo / industries) → lidský název.
 * Vrací [numericId => label] pro ty, co se povedlo přeložit; při chybě prázdné pole (caller přeskočí).
 * $resource = 'geo' | 'industries'; $ids jsou číselné části urn:li:{typ}:{id}.
 */
function allstat_linkedin_resolve_labels(string $resource, array $ids, array $headers): array
{
    $ids = array_values(array_unique(array_filter($ids, static fn ($x) => (string) $x !== '')));
    if (!$ids) { return []; }
    $out = [];
    foreach (array_chunk($ids, 100) as $chunk) {
        $url = 'https://api.linkedin.com/rest/' . $resource . '?ids=List(' . implode(',', array_map('rawurlencode', $chunk)) . ')';
        try {
            $resp = allstat_http_request('GET', $url, $headers, null, 60);
            if (($resp['status'] ?? 0) !== 200) {
                error_log('AllStat LinkedIn resolve ' . $resource . ': HTTP ' . ($resp['status'] ?? '?') . ' ' . substr((string) ($resp['body'] ?? ''), 0, 140));
                continue;
            }
            foreach ($resp['json']['results'] ?? [] as $id => $obj) {
                $label = (string) ($obj['defaultLocalizedName']['value'] ?? $obj['name']['localized']['en_US'] ?? (is_string($obj['name'] ?? null) ? $obj['name'] : ''));
                if ($label !== '') { $out[(string) $id] = $label; }
            }
        } catch (Throwable $e) {
            error_log('AllStat LinkedIn resolve ' . $resource . ': ' . $e->getMessage());
        }
    }
    return $out;
}

/**
 * Demografie sledujících (Fáze C) → provider_metrics_daily dimension řádky. organizationalEntityFollowerStatistics
 * BEZ timeIntervals vrací per-facet rozpad (top 100/facet); použij organicFollowerCount (rolluje organic+paid,
 * paidFollowerCount se u demografie ignoruje). Ukládá se jako snapshot na $onDate (read bere poslední datum,
 * stejně jako IG demografie). Facety: seniorita/funkce/velikost firmy (labely z enumu) + obor/země (resolved,
 * best-effort; nepřeložené se přeskočí, ať nevznikají „Obor #96"). Vrací počet zapsaných řádků.
 */
function allstat_linkedin_sync_follower_demographics(PDO $pdo, array $connection, string $token, string $orgUrn, string $onDate): int
{
    $headers = [
        'Authorization' => 'Bearer ' . $token,
        'X-Restli-Protocol-Version' => '2.0.0',
        'LinkedIn-Version' => '202606',
        'Accept' => 'application/json',
    ];
    $url = 'https://api.linkedin.com/rest/organizationalEntityFollowerStatistics?q=organizationalEntity&organizationalEntity=' . rawurlencode($orgUrn);
    $resp = allstat_http_request('GET', $url, $headers, null, 60);
    if (($resp['status'] ?? 0) !== 200) {
        throw new RuntimeException('follower stats HTTP ' . ($resp['status'] ?? '?') . ': ' . substr((string) ($resp['body'] ?? ''), 0, 180));
    }
    $el = $resp['json']['elements'][0] ?? null;
    if (!is_array($el)) { return 0; }

    // [urnNeboEnum => organicFollowerCount] pro daný facet (víc řádků se stejným klíčem se sečte).
    $facet = static function (array $rows, string $valueField): array {
        $out = [];
        foreach ($rows as $r) {
            $key = (string) ($r[$valueField] ?? '');
            $cnt = (int) ($r['followerCounts']['organicFollowerCount'] ?? 0);
            if ($key === '' || $cnt <= 0) { continue; }
            $out[$key] = ($out[$key] ?? 0) + $cnt;
        }
        return $out;
    };
    $urnId = static fn (string $urn): string => (($p = strrpos($urn, ':')) !== false ? substr($urn, $p + 1) : $urn);

    $seniority = $facet($el['followerCountsBySeniority'] ?? [], 'seniority');
    $function  = $facet($el['followerCountsByFunction'] ?? [], 'function');
    $staff     = $facet($el['followerCountsByStaffCountRange'] ?? [], 'staffCountRange');
    $industry  = $facet($el['followerCountsByIndustry'] ?? [], 'industry');
    $country   = $facet($el['followerCountsByGeoCountry'] ?? [], 'geo');
    $region    = $facet($el['followerCountsByGeo'] ?? [], 'geo'); // jemnější „Lokalita" (například Praha a okolí)

    // Obor + zemi/lokalitu je nutné přeložit z URN (velké taxonomie) → dávkově, best-effort. Geo ids země
    // i regionu do JEDNOHO resolveru, ať se šetří API volání.
    $indLabels = allstat_linkedin_resolve_labels('industries', array_map($urnId, array_keys($industry)), $headers);
    $geoLabels = allstat_linkedin_resolve_labels('geo', array_merge(array_map($urnId, array_keys($country)), array_map($urnId, array_keys($region))), $headers);

    $stmt = allstat_pm_statement($pdo);
    $domainId = (int) $connection['domain_id'];
    $sourceId = (int) $connection['source_id'];
    $connectionId = (int) $connection['id'];
    $n = 0;
    $put = static function (string $key, string $dim, int $val) use (&$n, $stmt, $domainId, $sourceId, $connectionId, $onDate): void {
        if ($dim === '' || $val <= 0) { return; }
        if (allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $onDate, $key, (float) $val, $dim)) { $n++; }
    };
    foreach ($seniority as $urn => $v) { $put('foll_seniority', allstat_linkedin_seniority_label($urnId($urn)), $v); }
    foreach ($function as $urn => $v)  { $put('foll_function', allstat_linkedin_function_label($urnId($urn)), $v); }
    foreach ($staff as $e => $v)       { $put('foll_staff', allstat_linkedin_staff_label($e), $v); }
    foreach ($industry as $urn => $v)  { if (($lbl = $indLabels[$urnId($urn)] ?? null) !== null) { $put('foll_industry', $lbl, $v); } }
    foreach ($country as $urn => $v)   { if (($lbl = $geoLabels[$urnId($urn)] ?? null) !== null) { $put('foll_country', $lbl, $v); } }
    foreach ($region as $urn => $v)    { if (($lbl = $geoLabels[$urnId($urn)] ?? null) !== null) { $put('foll_region', $lbl, $v); } }

    return $n;
}

/**
 * Složení návštěvníků stránky (Fáze C, „Návštěvníci → Složení návštěvníků" v LinkedIn UI) → provider_metrics_daily
 * dimension řádky. organizationPageStatistics BEZ timeIntervals = lifetime rozpad NÁVŠTĚV stránky po facetech
 * (funkce/seniorita/obor/velikost firmy/lokalita), value = pageStatistics.views.allPageViews.pageViews. Stejný
 * snapshot model jako demografie sledujících (jen aktuální sync). Klíče pv_*. Best-effort. Vrací počet řádků.
 */
function allstat_linkedin_sync_visitor_demographics(PDO $pdo, array $connection, string $token, string $orgUrn, string $onDate): int
{
    $headers = [
        'Authorization' => 'Bearer ' . $token,
        'X-Restli-Protocol-Version' => '2.0.0',
        'LinkedIn-Version' => '202606',
        'Accept' => 'application/json',
    ];
    $url = 'https://api.linkedin.com/rest/organizationPageStatistics?q=organization&organization=' . rawurlencode($orgUrn);
    $resp = allstat_http_request('GET', $url, $headers, null, 60);
    if (($resp['status'] ?? 0) !== 200) {
        throw new RuntimeException('page stats (visitor demo) HTTP ' . ($resp['status'] ?? '?') . ': ' . substr((string) ($resp['body'] ?? ''), 0, 180));
    }
    $el = $resp['json']['elements'][0] ?? null;
    if (!is_array($el)) { return 0; }
    $urnId = static fn (string $urn): string => (($p = strrpos($urn, ':')) !== false ? substr($urn, $p + 1) : $urn);
    // [urnNeboEnum => pageViews] z page-stats facetu (value = pageStatistics.views.allPageViews.pageViews).
    $facet = static function (array $rows, string $keyField): array {
        $out = [];
        foreach ($rows as $r) {
            $key = (string) ($r[$keyField] ?? '');
            $v = (int) ($r['pageStatistics']['views']['allPageViews']['pageViews'] ?? 0);
            if ($key === '' || $v <= 0) { continue; }
            $out[$key] = ($out[$key] ?? 0) + $v;
        }
        return $out;
    };
    $function  = $facet($el['pageStatisticsByFunction'] ?? [], 'function');
    $seniority = $facet($el['pageStatisticsBySeniority'] ?? [], 'seniority');
    $staff     = $facet($el['pageStatisticsByStaffCountRange'] ?? [], 'staffCountRange');
    $industry  = $facet($el['pageStatisticsByIndustryV2'] ?? [], 'industryV2'); // pozn.: klíč je industryV2, ne industry
    $region    = $facet($el['pageStatisticsByGeo'] ?? [], 'geo');

    $indLabels = allstat_linkedin_resolve_labels('industries', array_map($urnId, array_keys($industry)), $headers);
    $geoLabels = allstat_linkedin_resolve_labels('geo', array_map($urnId, array_keys($region)), $headers);

    $stmt = allstat_pm_statement($pdo);
    $domainId = (int) $connection['domain_id'];
    $sourceId = (int) $connection['source_id'];
    $connectionId = (int) $connection['id'];
    $n = 0;
    $put = static function (string $key, string $dim, int $val) use (&$n, $stmt, $domainId, $sourceId, $connectionId, $onDate): void {
        if ($dim === '' || $val <= 0) { return; }
        if (allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $onDate, $key, (float) $val, $dim)) { $n++; }
    };
    foreach ($function as $urn => $v)  { $put('pv_function', allstat_linkedin_function_label($urnId($urn)), $v); }
    foreach ($seniority as $urn => $v) { $put('pv_seniority', allstat_linkedin_seniority_label($urnId($urn)), $v); }
    foreach ($staff as $e => $v)       { $put('pv_staff', allstat_linkedin_staff_label($e), $v); }
    foreach ($industry as $urn => $v)  { if (($lbl = $indLabels[$urnId($urn)] ?? null) !== null) { $put('pv_industry', $lbl, $v); } }
    foreach ($region as $urn => $v)    { if (($lbl = $geoLabels[$urnId($urn)] ?? null) !== null) { $put('pv_region', $lbl, $v); } }

    return $n;
}

/**
 * Návštěvy firemní stránky (Fáze C) → denní metrika page_views. organizationPageStatistics time-bound
 * (timeGranularityType:DAY) vrací per-den totalPageStatistics.views.allPageViews.pageViews (souhrn všech
 * záložek stránky, mobil+desktop). Na rozdíl od demografie je to PER-OBDOBÍ → volá se v každém okně
 * (backfill i inkrement), takže historie naroste. Best-effort. Vrací počet zapsaných dní.
 */
/**
 * Denní přírůstky sledujících → provider_metrics_daily (new_follows / unfollows). Stejný endpoint jako
 * demografie sledujících, ale S timeIntervals: pak vrací per-den `followerGains` (organic + paid), což je
 * přesně to, co LinkedIn v UI ukazuje jako „Noví sledující uživatelé za 30 dní". BEZ timeIntervals vrací
 * celoživotní facety a žádné gains, proto to dřív vypadalo, že LinkedIn přírůstky nedává.
 * Ověřeno proti LinkedIn UI (org 1919137): okno 17. 6. – 16. 7. 2026 = 25, sedí přesně.
 *
 * `followerGain` je ČISTÁ denní změna a může být i záporná; rozpad na příchody a odchody API nedává. Ukládá
 * se proto SE ZNAMÉNKEM do new_follows a `unfollows` se nezapisuje vůbec: dlaždice „Odhlášení: 0" by tvrdila,
 * že se nikdo neodhlásil, což nevíme, a rozpad na kladné/záporné dny by zase nadsadil „Noví sledující"
 * (10 příchodů v pondělí a -5 v úterý není 10 nových, ale +5). Součet za období = přesně to, co LinkedIn
 * ukazuje jako „Noví sledující uživatelé". Zapisují se i nulové dny, ať je poznat, že období JE naměřené
 * (repository rozlišuje „řádky chybí" od „řádky jsou nulové").
 *
 * Per-období → volá se v každém okně (backfill i inkrement), takže historie naroste i zpětně, na rozdíl od
 * snapshotu followers_total. Best-effort. Vrací počet zapsaných dní.
 */
function allstat_linkedin_sync_follower_gains(PDO $pdo, array $connection, string $token, string $orgUrn, int $startMs, int $endMs, PDOStatement $stmt): int
{
    $headers = [
        'Authorization' => 'Bearer ' . $token,
        'X-Restli-Protocol-Version' => '2.0.0',
        'LinkedIn-Version' => '202606',
        'Accept' => 'application/json',
    ];
    $url = 'https://api.linkedin.com/rest/organizationalEntityFollowerStatistics?q=organizationalEntity&organizationalEntity=' . rawurlencode($orgUrn)
        . '&timeIntervals=(timeRange:(start:' . $startMs . ',end:' . $endMs . '),timeGranularityType:DAY)';
    $resp = allstat_http_request('GET', $url, $headers, null, 60);
    if (($resp['status'] ?? 0) !== 200) {
        throw new RuntimeException('follower gains HTTP ' . ($resp['status'] ?? '?') . ': ' . substr((string) ($resp['body'] ?? ''), 0, 180));
    }
    $domainId = (int) $connection['domain_id'];
    $sourceId = (int) $connection['source_id'];
    $connectionId = (int) $connection['id'];
    $n = 0;
    foreach ($resp['json']['elements'] ?? [] as $el) {
        $startTs = (int) ($el['timeRange']['start'] ?? 0);
        if ($startTs <= 0) { continue; }
        $date = (new DateTimeImmutable('@' . (int) ($startTs / 1000)))->format('Y-m-d');
        $gain = (int) ($el['followerGains']['organicFollowerGain'] ?? 0) + (int) ($el['followerGains']['paidFollowerGain'] ?? 0);
        if (allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $date, 'new_follows', (float) $gain)) { $n++; }
    }

    return $n;
}

function allstat_linkedin_sync_page_views(PDO $pdo, array $connection, string $token, string $orgUrn, int $startMs, int $endMs, PDOStatement $stmt): int
{
    $headers = [
        'Authorization' => 'Bearer ' . $token,
        'X-Restli-Protocol-Version' => '2.0.0',
        'LinkedIn-Version' => '202606',
        'Accept' => 'application/json',
    ];
    $url = 'https://api.linkedin.com/rest/organizationPageStatistics?q=organization&organization=' . rawurlencode($orgUrn)
        . '&timeIntervals=(timeRange:(start:' . $startMs . ',end:' . $endMs . '),timeGranularityType:DAY)';
    $resp = allstat_http_request('GET', $url, $headers, null, 60);
    if (($resp['status'] ?? 0) !== 200) {
        throw new RuntimeException('page stats HTTP ' . ($resp['status'] ?? '?') . ': ' . substr((string) ($resp['body'] ?? ''), 0, 180));
    }
    $domainId = (int) $connection['domain_id'];
    $sourceId = (int) $connection['source_id'];
    $connectionId = (int) $connection['id'];
    $n = 0;
    foreach ($resp['json']['elements'] ?? [] as $el) {
        $startTs = (int) ($el['timeRange']['start'] ?? 0);
        if ($startTs <= 0) { continue; }
        $date = (new DateTimeImmutable('@' . (int) ($startTs / 1000)))->format('Y-m-d');
        $all = (int) ($el['totalPageStatistics']['views']['allPageViews']['pageViews'] ?? 0);
        if (allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $date, 'page_views', (float) $all)) { $n++; }
    }
    return $n;
}

/**
 * Per-post statistics for a batch of post URNs via organizationalEntityShareStatistics. Splits by URN type
 * (urn:li:share: → `shares` facet, urn:li:ugcPost: → `ugcPosts` facet). Best-effort: returns
 * [postUrn => totalShareStatistics]; logs + skips a facet that errors. Heavily logged (format-fiddly API).
 */
function allstat_linkedin_post_stats(string $orgUrn, array $postUrns, array $headers): array
{
    $base = 'https://api.linkedin.com/v2/organizationalEntityShareStatistics';
    $out = [];
    $byType = ['shares' => [], 'ugcPosts' => []];
    foreach ($postUrns as $u) {
        if (str_contains($u, ':ugcPost:')) { $byType['ugcPosts'][] = $u; }
        elseif (str_contains($u, ':share:')) { $byType['shares'][] = $u; }
    }
    foreach ($byType as $facet => $urns) {
        if (!$urns) { continue; }
        foreach (array_chunk($urns, 20) as $chunk) {
            $list = 'List(' . implode(',', array_map('rawurlencode', $chunk)) . ')';
            $url = $base . '?q=organizationalEntity&organizationalEntity=' . rawurlencode($orgUrn) . '&' . $facet . '=' . $list;
            $resp = allstat_http_request('GET', $url, $headers, null, 60);
            if (($resp['status'] ?? 0) !== 200) {
                error_log('AllStat LinkedIn post stats (' . $facet . '): HTTP ' . ($resp['status'] ?? '?') . ' ' . substr((string) ($resp['body'] ?? ''), 0, 160));
                continue;
            }
            foreach ($resp['json']['elements'] ?? [] as $el) {
                $key = (string) ($el['share'] ?? $el['ugcPost'] ?? '');
                if ($key === '') { continue; }
                $out[$key] = $el['totalShareStatistics'] ?? [];
            }
        }
    }
    return $out;
}

/**
 * Sync the organization's posts (versioned /rest/posts) + per-post statistics into social_posts
 * (network='linkedin'). Paginates the author finder (start/count, up to 100/page, sortBy=CREATED so it
 * can stop once a page falls entirely before the window), keeps in-range posts, enriches with per-post
 * stats. Before this it fetched a single page of 50 → "počet příspěvků za období" was capped/undercounted.
 */
function allstat_linkedin_sync_posts(PDO $pdo, array $config, array $connection, string $token, string $orgUrn, string $startDate, string $endDate): int
{
    $headers = [
        'Authorization' => 'Bearer ' . $token,
        'X-Restli-Protocol-Version' => '2.0.0',
        // Verzované REST API: verze žijí ~12 měsíců (202506 = červen 2025, sunset ~červen 2026).
        // Při dalším ročním bumpu stačí přepsat tady.
        'LinkedIn-Version' => '202606',
        'Accept' => 'application/json',
    ];
    $tz = new DateTimeZone((string) ($config['app']['timezone'] ?? 'Europe/Prague'));
    $startTs = (new DateTimeImmutable($startDate))->getTimestamp() * 1000 - 86400000;
    $endTs = (new DateTimeImmutable($endDate))->modify('+1 day')->getTimestamp() * 1000;

    // Stránkování Posts API: bez něj se stahovalo jen prvních 50 „naposledy upravených" příspěvků, takže
    // „Počet příspěvků za období" byl podhodnocený, jakmile stránka publikovala víc (za 6 měsíců běžně >50).
    // /rest/posts vrací max 100/stránku přes offset start/count; řadíme podle CREATED (chronologicky,
    // nejnovější první) → jakmile CELÁ stránka spadne PŘED začátek období, dál už jsou jen starší = konec.
    // POZOR: API může vrátit méně než `count` prvků, i když další existují → NEkončit na krátké stránce,
    // jen na prázdné (start se posouvá o požadovaný count, ne o počet vrácených). Strop stránek = pojistka
    // proti runaway (per-post statistiky jsou stejně dostupné jen ~12 měsíců zpět).
    $pageSize = 100;
    $maxPages = 20;
    $posts = [];
    for ($page = 0; $page < $maxPages; $page++) {
        $listUrl = 'https://api.linkedin.com/rest/posts?q=author&author=' . rawurlencode($orgUrn)
            . '&count=' . $pageSize . '&start=' . ($page * $pageSize) . '&sortBy=CREATED';
        $resp = allstat_http_request('GET', $listUrl, $headers, null, 60);
        if (($resp['status'] ?? 0) !== 200) {
            // 1. stránka = tvrdá chyba (nemáme co ukládat); další stránky best-effort (necháme, co už máme).
            if ($page === 0) {
                throw new RuntimeException('posts list HTTP ' . ($resp['status'] ?? '?') . ': ' . substr((string) ($resp['body'] ?? ''), 0, 180));
            }
            error_log('AllStat LinkedIn posts list page ' . $page . ': HTTP ' . ($resp['status'] ?? '?') . ' ' . substr((string) ($resp['body'] ?? ''), 0, 160));
            break;
        }
        $elements = $resp['json']['elements'] ?? [];
        if ($page === 0) {
            error_log('AllStat LinkedIn posts list: HTTP 200 page0 elements=' . count($elements) . ' body0=' . substr((string) ($resp['body'] ?? ''), 0, 200));
        }
        if (!$elements) { break; }                               // prázdná stránka = konec dat
        $allOlder = true;
        foreach ($elements as $el) {
            $pid = (string) ($el['id'] ?? '');
            if ($pid === '') { continue; }
            $ms = (int) ($el['createdAt'] ?? $el['publishedAt'] ?? $el['firstPublishedAt'] ?? 0);
            if ($ms <= 0 || $ms >= $startTs) { $allOlder = false; } // neznámé datum nebo v/nad oknem → pokračuj
            if ($ms > 0 && ($ms < $startTs || $ms > $endTs)) { continue; }
            $posts[$pid] = ['text' => (string) ($el['commentary'] ?? ''), 'ms' => $ms, 'format' => allstat_linkedin_post_format($el)];
        }
        if ($allOlder) { break; }                                // chronologicky jsme přešli za začátek období
        if ($page === $maxPages - 1) {
            error_log('AllStat LinkedIn posts: dosažen strop ' . $maxPages . ' stránek – nejstarší příspěvky v období mohou chybět.');
        }
    }
    if (!$posts) { return 0; }

    $stats = [];
    try { $stats = allstat_linkedin_post_stats($orgUrn, array_keys($posts), $headers); }
    catch (Throwable $e) { error_log('AllStat LinkedIn post stats: ' . $e->getMessage()); }

    $stmt = $pdo->prepare("INSERT INTO social_posts
        (domain_id, connection_id, metric_date, published_at, network, post_type, post_format, post_id, message, permalink,
         reactions, comments, shares, impressions, post_clicks, reach, engagement, updated_at)
        VALUES (?, ?, ?, ?, 'linkedin', 'post', ?, ?, ?, ?,  ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE metric_date=VALUES(metric_date), published_at=VALUES(published_at), post_format=VALUES(post_format),
          message=VALUES(message), permalink=VALUES(permalink), reactions=VALUES(reactions), comments=VALUES(comments),
          shares=VALUES(shares), impressions=VALUES(impressions), post_clicks=VALUES(post_clicks), reach=VALUES(reach),
          engagement=VALUES(engagement), updated_at=NOW()");
    $domainId = (int) $connection['domain_id'];
    $connectionId = (int) $connection['id'];
    $n = 0;
    foreach ($posts as $pid => $p) {
        if ($p['ms'] <= 0) { continue; }
        $dt = (new DateTimeImmutable('@' . (int) ($p['ms'] / 1000)))->setTimezone($tz);
        $s = $stats[$pid] ?? [];
        $rea = (int) ($s['likeCount'] ?? 0); $com = (int) ($s['commentCount'] ?? 0); $sha = (int) ($s['shareCount'] ?? 0);
        $permalink = 'https://www.linkedin.com/feed/update/' . rawurlencode($pid) . '/';
        $stmt->execute([
            $domainId, $connectionId, $dt->format('Y-m-d'), $dt->format('Y-m-d H:i:s'),
            $p['format'], mb_substr($pid, 0, 150), mb_substr($p['text'], 0, 500), mb_substr($permalink, 0, 400),
            $rea, $com, $sha, (int) ($s['impressionCount'] ?? 0), (int) ($s['clickCount'] ?? 0),
            (int) ($s['uniqueImpressionsCount'] ?? 0), $rea + $com + $sha,
        ]);
        $n++;
    }

    // Rozpad reakcí podle typu (Fáze C) → li_reaction dimension řádky (socialMetadata), best-effort.
    try { allstat_linkedin_sync_post_reactions($pdo, $connection, $posts, $headers); }
    catch (Throwable $e) { error_log('AllStat LinkedIn reactions: ' . $e->getMessage()); }

    return $n;
}

/**
 * LinkedIn reakce enum → český label (dle socialMetadata reactionType, ověřeno z MS Learn).
 */
function allstat_linkedin_reaction_label(string $type): string
{
    static $m = [
        'LIKE' => 'To se mi líbí', 'PRAISE' => 'Gratuluji', 'CELEBRATION' => 'Gratuluji',
        'EMPATHY' => 'Super (láska)', 'APPRECIATION' => 'Podpora', 'INTEREST' => 'Zajímavé',
        'MAYBE' => 'Zaujalo mě', 'ENTERTAINMENT' => 'Zábavné',
    ];
    return $m[strtoupper($type)] ?? ucfirst(mb_strtolower($type));
}

/**
 * Rozpad reakcí příspěvků podle typu (Fáze C) → provider_metrics_daily (metric_key li_reaction, dimension =
 * typ reakce, metric_date = datum příspěvku, value = počet). socialMetadata vrací AKTUÁLNÍ součty reakcí per
 * typ (LIKE/PRAISE/EMPATHY/…); attribujeme je k datu publikace příspěvku, sečteme per den+typ. Read pak sečte
 * přes období. $posts = [postUrn => ['ms'=>...]]. Best-effort. Vrací počet zapsaných řádků.
 */
function allstat_linkedin_sync_post_reactions(PDO $pdo, array $connection, array $posts, array $headers): int
{
    $ids = array_keys($posts);
    if (!$ids) { return 0; }
    $tz = new DateTimeZone('Europe/Prague');
    $byDayType = []; // [Y-m-d][label] => count
    foreach (array_chunk($ids, 50) as $chunk) {
        $url = 'https://api.linkedin.com/rest/socialMetadata?ids=List(' . implode(',', array_map('rawurlencode', $chunk)) . ')';
        $resp = allstat_http_request('GET', $url, $headers, null, 60);
        $st = (int) ($resp['status'] ?? 0);
        if ($st !== 200) {
            error_log('AllStat LinkedIn socialMetadata: HTTP ' . $st . ' ' . substr((string) ($resp['body'] ?? ''), 0, 160));
            // 403 = appka nemá r_organization_social_feed, 429 = vyčerpán denní limit → další chunky
            // nemá smysl zkoušet (jinak by rozpad reakcí pálil API volání při každém syncu nadarmo).
            if ($st === 403 || $st === 429) { break; }
            continue;
        }
        foreach ($resp['json']['results'] ?? [] as $pid => $meta) {
            $ms = (int) ($posts[$pid]['ms'] ?? 0);
            if ($ms <= 0) { continue; }
            $date = (new DateTimeImmutable('@' . (int) ($ms / 1000)))->setTimezone($tz)->format('Y-m-d');
            foreach ($meta['reactionSummaries'] ?? [] as $type => $rs) {
                $cnt = (int) ($rs['count'] ?? 0);
                if ($cnt <= 0) { continue; }
                $label = allstat_linkedin_reaction_label((string) ($rs['reactionType'] ?? $type));
                $byDayType[$date][$label] = ($byDayType[$date][$label] ?? 0) + $cnt;
            }
        }
    }
    if (!$byDayType) { return 0; }
    $stmt = allstat_pm_statement($pdo);
    $domainId = (int) $connection['domain_id'];
    $sourceId = (int) $connection['source_id'];
    $connectionId = (int) $connection['id'];
    $n = 0;
    foreach ($byDayType as $date => $types) {
        foreach ($types as $label => $cnt) {
            if (allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $date, 'li_reaction', (float) $cnt, $label)) { $n++; }
        }
    }
    return $n;
}

/**
 * Microsoft Clarity — API token (Bearer). The export API only returns a rolling aggregate of the
 * last 1-3 days (no per-day breakdown, no history, 10 req/day). So we pull a single 24h window and
 * stamp it on the sync day; the daily cron then accumulates a clean day-by-day series going forward
 * (consecutive 24h snapshots, so "Dnes" shows the latest snapshot and older days fill in).
 * Historical days can never be fetched retroactively — use GA4 for traffic history.
 */
function allstat_engine_clarity(PDO $pdo, array $config, array $connection, string $token, string $startDate, string $endDate, array $recipe): array
{
    $base = rtrim((string) ($connection['api_base_url'] ?: 'https://www.clarity.ms/export-data/api'), '/');
    $headers = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];

    // One insights call (last 24h, optional single dimension). Throws on non-200, so the base call
    // surfaces 429/auth errors; dimension calls are wrapped by the caller so they degrade gracefully.
    $fetch = static function (?string $dimension) use ($base, $headers): array {
        $url = $base . '/v1/project-live-insights?numOfDays=1' . ($dimension ? '&dimension1=' . urlencode($dimension) : '');
        $resp = allstat_http_request('GET', $url, $headers, null, 60);
        if ($resp['status'] === 429) {
            throw new RuntimeException('Clarity: vyčerpán denní limit API (HTTP 429 – max 10 callů/den/projekt). Zkus to zítra; backfill u Clarity nespouštěj.');
        }
        if ($resp['status'] !== 200) {
            throw new RuntimeException('Clarity API HTTP ' . $resp['status'] . ': ' . substr($resp['body'], 0, 180));
        }
        return $resp['json'] ?? [];
    };

    $stmt = allstat_pm_statement($pdo);
    $domainId = (int) $connection['domain_id'];
    $sourceId = (int) $connection['source_id'];
    $connectionId = (int) $connection['id'];
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) ? $endDate : (new DateTimeImmutable('today'))->format('Y-m-d');

    // 1) Headline counts (no dimension) → fixed keys "sessions"/"bot_sessions", continuous with CSV import.
    $sessions = null;
    $bots = null;
    foreach ($fetch(null) as $metric) {
        if (($metric['metricName'] ?? '') !== 'Traffic') { continue; }
        foreach ($metric['information'] ?? [] as $info) {
            if (isset($info['totalSessionCount']) && is_numeric($info['totalSessionCount'])) {
                $sessions = ($sessions ?? 0) + (float) $info['totalSessionCount'];
            }
            if (isset($info['totalBotSessionCount']) && is_numeric($info['totalBotSessionCount'])) {
                $bots = ($bots ?? 0) + (float) $info['totalBotSessionCount'];
            }
        }
    }
    $written = 0;
    if ($sessions !== null) { allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $date, 'sessions', $sessions); $written++; }
    if ($bots !== null) { allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $date, 'bot_sessions', $bots); $written++; }

    // 2) Best-effort breakdowns by one dimension each (referrers via Source, pages via URL). Same keys
    //    + dimensions as the CSV importer so manual and cron data line up. Wrapped per-dimension: an
    //    unexpected API shape or extra rate-limit hit just skips that breakdown, sync still succeeds.
    foreach (['Source' => 'referrer', 'URL' => 'page'] as $apiDimension => $localKey) {
        try {
            foreach ($fetch($apiDimension) as $metric) {
                if (($metric['metricName'] ?? '') !== 'Traffic') { continue; }
                foreach ($metric['information'] ?? [] as $info) {
                    $name = $info[$apiDimension] ?? null;
                    $value = $info['totalSessionCount'] ?? null;
                    if (is_string($name) && $name !== '' && is_numeric($value)) {
                        if (allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $date, $localKey, (float) $value, $name)) { $written++; }
                    }
                }
            }
        } catch (Throwable) {
            // dimension unsupported / quota reached — keep what we already stored.
        }
    }

    return ['rows' => $written, 'summary' => sprintf('Clarity %s: %s sessions, %s bot_sessions + rozpady (posledních 24 h, denně se akumuluje)', $date, $sessions ?? '0', $bots ?? '0')];
}

/**
 * Google Ads API (GAQL searchStream, verze ALLSTAT_GOOGLE_ADS_API_VERSION v oauth.php). Stačí OAuth token,
 * developer token se od 9. 9. 2026 nepoužívá (přístup určuje Google Cloud projekt OAuth klienta).
 */
function allstat_engine_google_ads(PDO $pdo, array $config, array $connection, string $token, string $startDate, string $endDate, array $recipe): array
{
    $customerId = preg_replace('/[^0-9]/', '', (string) ($connection['property_id'] ?? ''));
    if ($customerId === '') {
        throw new RuntimeException('Google Ads: vyplň Property ID (Customer ID bez pomlček).');
    }

    $gaql = "SELECT segments.date, metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions, metrics.conversions_value "
        . "FROM customer WHERE segments.date BETWEEN '" . $startDate . "' AND '" . $endDate . "'";
    $resp = allstat_http_request(
        'POST',
        allstat_google_ads_url($connection, 'customers/' . $customerId . '/googleAds:searchStream'),
        allstat_google_ads_headers($connection, $token),
        json_encode(['query' => $gaql]),
        60
    );
    if ($resp['status'] !== 200) {
        throw new RuntimeException(allstat_explain_google_ads_error($resp));
    }

    $stmt = allstat_pm_statement($pdo);
    $domainId = (int) $connection['domain_id'];
    $sourceId = (int) $connection['source_id'];
    $connectionId = (int) $connection['id'];
    $written = 0;
    // searchStream returns an array of batches, each with results[] (int64 metriky chodí jako string).
    $batches = is_array($resp['json']) ? $resp['json'] : [];
    if (isset($batches['results'])) { $batches = [$batches]; }
    foreach ($batches as $batch) {
        foreach ($batch['results'] ?? [] as $row) {
            $date = (string) ($row['segments']['date'] ?? '');
            $m = $row['metrics'] ?? [];
            $map = [
                'impressions' => (float) ($m['impressions'] ?? 0),
                'clicks' => (float) ($m['clicks'] ?? 0),
                'cost' => isset($m['costMicros']) ? ((float) $m['costMicros']) / 1_000_000 : 0,
                'conversions' => (float) ($m['conversions'] ?? 0),
                'conversion_value' => (float) ($m['conversionsValue'] ?? 0),
            ];
            foreach ($map as $key => $value) {
                if (allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $date, $key, $value)) { $written++; }
            }
        }
    }
    return ['rows' => $written, 'summary' => sprintf('%d metrik (%s → %s)', $written, $startDate, $endDate)];
}

/**
 * Seznam Webmaster (reporter.seznam.cz/wm-api) — organic INDEXATION status for Seznam.cz search (NOT
 * clicks/queries; this API doesn't expose those). One GET /web/documents-history?key= returns the full
 * daily history as [{date, counts:{indexed, content, downloaded, error, redirected, doc_count}}], so a
 * single call backfills everything; we store only the days inside the requested [start,end] window. The
 * key is per-web and IS the access token (supports_oauth=0 → token = decrypted access_token_enc).
 */
function allstat_engine_seznam_wmt(PDO $pdo, array $config, array $connection, string $token, string $startDate, string $endDate, array $recipe): array
{
    $base = rtrim((string) ($connection['api_base_url'] ?: 'https://reporter.seznam.cz/wm-api'), '/');
    $url = $base . '/web/documents-history?key=' . urlencode($token);
    $resp = allstat_http_request('GET', $url, ['Accept' => 'application/json'], null, 60);
    if ($resp['status'] !== 200) {
        throw new RuntimeException('Seznam Webmaster API HTTP ' . $resp['status'] . ': ' . substr($resp['body'], 0, 180));
    }
    $history = $resp['json'] ?? [];
    if (!is_array($history)) {
        throw new RuntimeException('Seznam Webmaster: neočekávaná odpověď API (čekáno pole historie indexace).');
    }

    $stmt = allstat_pm_statement($pdo);
    $domainId = (int) $connection['domain_id'];
    $sourceId = (int) $connection['source_id'];
    $connectionId = (int) $connection['id'];
    $keys = ['indexed', 'content', 'downloaded', 'error', 'redirected', 'doc_count'];
    $written = 0;
    foreach ($history as $row) {
        $date = (string) ($row['date'] ?? '');
        if ($date < $startDate || $date > $endDate) { continue; } // respect the sync window (date strings sort lexically)
        $counts = is_array($row['counts'] ?? null) ? $row['counts'] : [];
        foreach ($keys as $k) {
            if (allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $date, $k, (float) ($counts[$k] ?? 0))) { $written++; }
        }
    }

    return ['rows' => $written, 'summary' => sprintf('%d záznamů indexace (%s → %s)', $written, $startDate, $endDate)];
}

/**
 * YouTube kanál = YouTube Data API v3 + YouTube Analytics API v2 (jeden Google OAuth token s oběma scopes).
 *
 *  - channels.list (mine=true, nebo id=UC… z Property ID) → snapshot kanálu K DNEŠKU: followers_total
 *    (odběratelé), views_total, videos_total; + uploads playlist a název kanálu (doplní account_label).
 *  - Analytics reports?dimensions=day za [start,end] → denní views / watch_time_min / avg_view_duration /
 *    likes / comments / shares / new_follows (subscribersGained) / unfollows (subscribersLost). Backfill-able.
 *  - Analytics dimensions=day,insightTrafficSourceType → `traffic_source` dimension řádky per den (sčitatelné).
 *  - JEN u aktuálního syncu (endDate ≥ dnes−2): geografie `geo_country` (views, posledních 90 dní) a
 *    demografie `viewer_age` / `viewer_gender` (viewerPercentage, 90 dní) jako snapshot k dnešku — tyhle
 *    reporty nemají denní dimenzi, takže se čtou jako „stav k datu" (viz allstat_get_youtube_audience).
 *  - Videa: uploads playlist → videos.list (veřejné statistiky, délka, stav) + Analytics dimensions=video
 *    (sledovaný čas, Ø doba a % zhlédnutí, sdílení, odběratelé z videa; celoživotně) → social_posts
 *    (network='youtube', post_type='video', metric_date = datum publikace, hodnoty = celoživotní stav
 *    jako u FB/IG příspěvků). Soukromá videa se přeskakují.
 *
 * Kvóta Data API (10 000 jednotek/den): channels 1 + playlistItems 1/50 videí + videos 1/50 videí → jeden
 * sync stojí řádově desítky jednotek. Analytics API se počítá na dotazy, tady 3–6 dotazů na sync.
 */
function allstat_engine_youtube(PDO $pdo, array $config, array $connection, string $token, string $startDate, string $endDate, array $recipe): array
{
    $analyticsBase = rtrim((string) ($connection['api_base_url'] ?: 'https://youtubeanalytics.googleapis.com'), '/');
    // Data API base jde přebít v config_json {"data_api_base": "…"} (testy proti mocku); jinak Google.
    $cfg = json_decode((string) ($connection['config_json'] ?? ''), true);
    $dataBase = rtrim((string) ((is_array($cfg) ? ($cfg['data_api_base'] ?? '') : '') ?: 'https://www.googleapis.com/youtube/v3'), '/');
    $headers = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
    $tz = new DateTimeZone('Europe/Prague');
    $today = (new DateTimeImmutable('today', $tz))->format('Y-m-d');
    $isCurrent = $endDate >= (new DateTimeImmutable('today', $tz))->modify('-2 days')->format('Y-m-d');

    $dataGet = static function (string $resource, array $params) use ($dataBase, $headers): array {
        $resp = allstat_http_request('GET', $dataBase . '/' . $resource . '?' . http_build_query($params), $headers, null, 60);
        if ($resp['status'] !== 200 || !is_array($resp['json'])) {
            throw new RuntimeException('YouTube Data API ' . $resource . ': ' . allstat_explain_youtube_error($resp));
        }
        return $resp['json'];
    };
    // Analytics: výsledek je tabulka columnHeaders + rows → převést na řádky klíčované názvem sloupce.
    $analytics = static function (array $params) use ($analyticsBase, $headers): array {
        $resp = allstat_http_request('GET', $analyticsBase . '/v2/reports?' . http_build_query($params), $headers, null, 60);
        if ($resp['status'] !== 200 || !is_array($resp['json'])) {
            throw new RuntimeException('YouTube Analytics: ' . allstat_explain_youtube_error($resp));
        }
        $cols = array_map(static fn (array $c): string => (string) ($c['name'] ?? ''), $resp['json']['columnHeaders'] ?? []);
        $rows = [];
        foreach ($resp['json']['rows'] ?? [] as $r) {
            if (is_array($r) && count($r) === count($cols)) { $rows[] = array_combine($cols, $r); }
        }
        return $rows;
    };

    // 1) Kanál (snapshot + uploads playlist).
    $channelId = trim((string) ($connection['property_id'] ?? ''));
    $chanResp = $dataGet('channels', ['part' => 'snippet,statistics,contentDetails'] + ($channelId !== '' ? ['id' => $channelId] : ['mine' => 'true']));
    $chan = $chanResp['items'][0] ?? null;
    if (!is_array($chan)) {
        throw new RuntimeException($channelId !== ''
            ? 'YouTube: kanál s ID "' . $channelId . '" neexistuje nebo k němu účet nemá přístup.'
            : 'YouTube: autorizovaný Google účet nemá žádný kanál. Spusť OAuth znovu účtem značky s kanálem, nebo vyplň ID kanálu.');
    }
    $channelId = (string) ($chan['id'] ?? $channelId);
    $uploads = (string) ($chan['contentDetails']['relatedPlaylists']['uploads'] ?? '');
    $title = trim((string) ($chan['snippet']['title'] ?? ''));
    if ($title !== '') {
        // Název kanálu jako popisek napojení (picker „Zdroj dat" ho ukazuje místo generického „YouTube").
        $pdo->prepare("UPDATE domain_sources SET account_label = ? WHERE id = ? AND (account_label IS NULL OR account_label = '')")
            ->execute([mb_substr($title, 0, 190), (int) $connection['id']]);
    }
    $ids = 'channel==' . (trim((string) ($connection['property_id'] ?? '')) !== '' ? $channelId : 'MINE');

    $stmt = allstat_pm_statement($pdo);
    $domainId = (int) $connection['domain_id'];
    $sourceId = (int) $connection['source_id'];
    $connectionId = (int) $connection['id'];
    $written = 0;
    $st = $chan['statistics'] ?? [];
    foreach (['followers_total' => 'subscriberCount', 'views_total' => 'viewCount', 'videos_total' => 'videoCount'] as $key => $field) {
        if (isset($st[$field]) && allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $today, $key, (float) $st[$field])) { $written++; }
    }

    // 2) Denní metriky kanálu za okno.
    $metricMap = (array) ($recipe['metrics'] ?? []);
    $rows = $analytics(['ids' => $ids, 'startDate' => $startDate, 'endDate' => $endDate, 'dimensions' => 'day',
        'metrics' => implode(',', array_keys($metricMap)), 'sort' => 'day']);
    $days = 0;
    foreach ($rows as $r) {
        $date = (string) ($r['day'] ?? '');
        foreach ($metricMap as $api => $local) {
            if (isset($r[$api]) && allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $date, $local, (float) $r[$api])) { $written++; }
        }
        $days++;
    }

    // 3) Zdroje návštěvnosti per den (best-effort: hlavní sync kvůli tomu nespadne).
    $trafficRows = 0;
    try {
        $rows = $analytics(['ids' => $ids, 'startDate' => $startDate, 'endDate' => $endDate,
            'dimensions' => 'day,insightTrafficSourceType', 'metrics' => 'views', 'sort' => 'day']);
        foreach ($rows as $r) {
            $dim = (string) ($r['insightTrafficSourceType'] ?? '');
            if ($dim === '') { continue; }
            if (allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, (string) ($r['day'] ?? ''), 'traffic_source', (float) ($r['views'] ?? 0), $dim)) { $trafficRows++; }
        }
    } catch (Throwable $e) {
        error_log('AllStat YouTube traffic sources: ' . $e->getMessage());
    }

    // 4) Geografie + demografie: snapshot k dnešku za posledních 90 dní (reporty nemají denní dimenzi).
    if ($isCurrent) {
        $snapStart = (new DateTimeImmutable('today', $tz))->modify('-90 days')->format('Y-m-d');
        try {
            $rows = $analytics(['ids' => $ids, 'startDate' => $snapStart, 'endDate' => $today, 'dimensions' => 'country',
                'metrics' => 'views', 'sort' => '-views', 'maxResults' => 25]);
            foreach ($rows as $r) {
                $c = (string) ($r['country'] ?? '');
                if ($c !== '') { allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $today, 'geo_country', (float) ($r['views'] ?? 0), $c); }
            }
        } catch (Throwable $e) {
            error_log('AllStat YouTube geography: ' . $e->getMessage());
        }
        try {
            $rows = $analytics(['ids' => $ids, 'startDate' => $snapStart, 'endDate' => $today, 'dimensions' => 'ageGroup,gender', 'metrics' => 'viewerPercentage']);
            $age = [];
            $gender = [];
            foreach ($rows as $r) {
                $pct = (float) ($r['viewerPercentage'] ?? 0);
                $age[(string) ($r['ageGroup'] ?? '')] = ($age[(string) ($r['ageGroup'] ?? '')] ?? 0) + $pct;
                $gender[(string) ($r['gender'] ?? '')] = ($gender[(string) ($r['gender'] ?? '')] ?? 0) + $pct;
            }
            foreach ($age as $dim => $val) { if ($dim !== '') { allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $today, 'viewer_age', round($val, 2), $dim); } }
            foreach ($gender as $dim => $val) { if ($dim !== '') { allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $today, 'viewer_gender', round($val, 2), $dim); } }
        } catch (Throwable $e) {
            error_log('AllStat YouTube demographics: ' . $e->getMessage());
        }
    }

    // 5) Videa (celý seznam nahraných videí, strop 1 000; hodnoty = celoživotní stav). Jen u aktuálního
    //    syncu: backfill jede po měsíčních chuncích a videa jsou vždy „stav teď", nemá smysl je tahat 16×.
    $videos = 0;
    $playlists = 0;
    if ($uploads !== '' && $isCurrent) {
        $videos = allstat_youtube_sync_videos($pdo, $connection, $uploads, $ids, $dataGet, $analytics, $tz);
        // Playlisty = kategorie kanálu (Motivační videa, Žijeme regionem…); best-effort.
        try {
            $playlists = allstat_youtube_sync_playlists($pdo, $connection, $channelId, trim((string) ($connection['property_id'] ?? '')) === '', $uploads, $dataGet);
        } catch (Throwable $e) {
            error_log('AllStat YouTube playlists: ' . $e->getMessage());
        }
    }

    return [
        'rows' => $written,
        'summary' => sprintf('%d dní, %d metrik, %d řádků zdrojů návštěvnosti, %d videí, %d playlistů (%s → %s)', $days, $written, $trafficRows, $videos, $playlists, $startDate, $endDate),
    ];
}

/**
 * ISO 8601 délka videa (PT1H2M3S) → sekundy.
 */
function allstat_youtube_duration_seconds(string $iso): int
{
    if (!preg_match('/^P(?:(\d+)D)?T?(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $iso, $m)) {
        return 0;
    }
    return (int) ($m[1] ?? 0) * 86400 + (int) ($m[2] ?? 0) * 3600 + (int) ($m[3] ?? 0) * 60 + (int) ($m[4] ?? 0);
}

/**
 * Videa kanálu → social_posts. $dataGet / $analytics = klienti z allstat_engine_youtube.
 * Vrací počet zapsaných videí.
 */
function allstat_youtube_sync_videos(PDO $pdo, array $connection, string $uploadsPlaylist, string $ids, callable $dataGet, callable $analytics, DateTimeZone $tz): int
{
    // a) ID všech nahraných videí (playlist je řazený od nejnovějšího; strop 20 stránek = 1 000 videí).
    $videoIds = [];
    $pageToken = '';
    for ($page = 0; $page < 20; $page++) {
        $params = ['part' => 'contentDetails', 'playlistId' => $uploadsPlaylist, 'maxResults' => 50];
        if ($pageToken !== '') { $params['pageToken'] = $pageToken; }
        $list = $dataGet('playlistItems', $params);
        foreach ($list['items'] ?? [] as $it) {
            $vid = (string) ($it['contentDetails']['videoId'] ?? '');
            if ($vid !== '') { $videoIds[$vid] = true; }
        }
        $pageToken = (string) ($list['nextPageToken'] ?? '');
        if ($pageToken === '') { break; }
    }
    $videoIds = array_keys($videoIds);
    if (!$videoIds) { return 0; }

    // b) Veřejné statistiky + metadata (po 50).
    $meta = [];
    foreach (array_chunk($videoIds, 50) as $chunk) {
        $resp = $dataGet('videos', ['part' => 'snippet,statistics,contentDetails,status,liveStreamingDetails', 'id' => implode(',', $chunk)]);
        foreach ($resp['items'] ?? [] as $v) {
            $vid = (string) ($v['id'] ?? '');
            if ($vid === '' || (string) ($v['status']['privacyStatus'] ?? 'public') === 'private') { continue; }
            $dur = allstat_youtube_duration_seconds((string) ($v['contentDetails']['duration'] ?? ''));
            $isLive = !empty($v['liveStreamingDetails']['actualStartTime']) || (string) ($v['snippet']['liveBroadcastContent'] ?? 'none') !== 'none';
            $meta[$vid] = [
                'title' => (string) ($v['snippet']['title'] ?? ''),
                'published' => (string) ($v['snippet']['publishedAt'] ?? ''),
                'thumb' => (string) ($v['snippet']['thumbnails']['medium']['url'] ?? $v['snippet']['thumbnails']['default']['url'] ?? ''),
                'duration' => $dur,
                // Shorts YouTube API neoznačuje; ≤ 60 s bereme jako short (konzervativně, Shorts smí mít až 3 min).
                'format' => $isLive ? 'live' : ($dur > 0 && $dur <= 60 ? 'short' : 'video'),
                'privacy' => (string) ($v['status']['privacyStatus'] ?? 'public'),
                'views' => (int) ($v['statistics']['viewCount'] ?? 0),
                'likes' => (int) ($v['statistics']['likeCount'] ?? 0),
                'comments' => (int) ($v['statistics']['commentCount'] ?? 0),
            ];
        }
    }
    if (!$meta) { return 0; }

    // c) Analytics per video (celoživotně; best-effort, po 200 ID = strop maxResults pro dimension=video).
    $an = [];
    $today = (new DateTimeImmutable('today', $tz))->format('Y-m-d');
    foreach (array_chunk(array_keys($meta), 200) as $chunk) {
        try {
            $rows = $analytics(['ids' => $ids, 'startDate' => '2005-01-01', 'endDate' => $today, 'dimensions' => 'video',
                'filters' => 'video==' . implode(',', $chunk),
                'metrics' => 'views,estimatedMinutesWatched,averageViewDuration,averageViewPercentage,shares,subscribersGained',
                'sort' => '-views', 'maxResults' => 200]);
            foreach ($rows as $r) {
                $vid = (string) ($r['video'] ?? '');
                if ($vid !== '') { $an[$vid] = $r; }
            }
        } catch (Throwable $e) {
            error_log('AllStat YouTube video analytics: ' . $e->getMessage());
        }
    }

    $stmt = $pdo->prepare("INSERT INTO social_posts
        (domain_id, connection_id, metric_date, published_at, network, post_type, post_format, post_id, message, permalink,
         reactions, comments, shares, impressions, video_views, watch_time_sec, plays, engagement, stats_json, updated_at)
        VALUES (?, ?, ?, ?, 'youtube', 'video', ?, ?, ?, ?,  ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE metric_date=VALUES(metric_date), published_at=VALUES(published_at), post_format=VALUES(post_format),
          message=VALUES(message), permalink=VALUES(permalink), reactions=VALUES(reactions), comments=VALUES(comments),
          shares=VALUES(shares), impressions=VALUES(impressions), video_views=VALUES(video_views), watch_time_sec=VALUES(watch_time_sec),
          plays=VALUES(plays), engagement=VALUES(engagement), stats_json=VALUES(stats_json), updated_at=NOW()");
    $domainId = (int) $connection['domain_id'];
    $connectionId = (int) $connection['id'];
    // Videa starší než retence metrik nezapisovat: noční údržba maže social_posts podle metric_date (= datum
    // publikace) a další sync by je zas vracel, zbytečné přepisování dokola.
    $retentionDays = 1095;
    try {
        $row = allstat_fetch_one($pdo, "SELECT setting_value FROM allstat_settings WHERE setting_key = 'storage.metric_retention_days'");
        if ($row && (int) $row['setting_value'] > 0) { $retentionDays = max(30, min(3650, (int) $row['setting_value'])); }
    } catch (Throwable) { /* výchozí retence */ }
    $cutoff = (new DateTimeImmutable('today', $tz))->modify('-' . $retentionDays . ' days')->format('Y-m-d');
    $n = 0;
    foreach ($meta as $vid => $m) {
        $pub = allstat_meta_local_time($m['published'], $tz);
        if ($pub['date'] === '' || $pub['date'] < $cutoff) { continue; }
        $a = $an[$vid] ?? [];
        $shares = (int) ($a['shares'] ?? 0);
        $watchSec = (int) round((float) ($a['estimatedMinutesWatched'] ?? 0) * 60);
        $stats = [
            'dur' => (int) $m['duration'],
            'avg_sec' => (int) round((float) ($a['averageViewDuration'] ?? 0)),
            'avg_pct' => round((float) ($a['averageViewPercentage'] ?? 0), 1),
            'subs' => (int) ($a['subscribersGained'] ?? 0),
            'an_views' => (int) ($a['views'] ?? 0),
            'privacy' => $m['privacy'],
            'thumb' => $m['thumb'],
        ];
        $stmt->execute([
            $domainId, $connectionId, $pub['date'], $pub['dt'],
            $m['format'], mb_substr($vid, 0, 150), mb_substr($m['title'], 0, 500), 'https://www.youtube.com/watch?v=' . $vid,
            $m['likes'], $m['comments'], $shares, $m['views'], $m['views'], $watchSec, $m['views'],
            $m['likes'] + $m['comments'] + $shares, mb_substr(json_encode($stats, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '', 0, 500),
        ]);
        $n++;
    }

    return $n;
}

/**
 * Playlisty kanálu → social_collections (playlist = kolekce, video = post_id). Načte všechny playlisty kanálu
 * (mine=true u autorizovaného kanálu, jinak channelId=) a jejich položky; cizí videa (z jiných kanálů) se
 * nepřebírají, uploads playlist se přeskakuje. Membership se přepíše celá (DELETE + INSERT v transakci).
 * Vrací počet playlistů.
 */
function allstat_youtube_sync_playlists(PDO $pdo, array $connection, string $channelId, bool $useMine, string $uploadsPlaylist, callable $dataGet): int
{
    $connectionId = (int) $connection['id'];
    $playlists = [];
    $pageToken = '';
    for ($page = 0; $page < 10; $page++) {
        $params = ['part' => 'snippet,contentDetails', 'maxResults' => 50] + ($useMine ? ['mine' => 'true'] : ['channelId' => $channelId]);
        if ($pageToken !== '') { $params['pageToken'] = $pageToken; }
        $list = $dataGet('playlists', $params);
        foreach ($list['items'] ?? [] as $it) {
            $pid = (string) ($it['id'] ?? '');
            if ($pid === '' || $pid === $uploadsPlaylist) { continue; }
            $playlists[$pid] = ['title' => trim((string) ($it['snippet']['title'] ?? '')) ?: $pid, 'count' => (int) ($it['contentDetails']['itemCount'] ?? 0)];
        }
        $pageToken = (string) ($list['nextPageToken'] ?? '');
        if ($pageToken === '') { break; }
    }

    // Videa kanálu (jen ta, která máme v social_posts) – cizí videa v playlistech ignorovat.
    $own = [];
    foreach (allstat_fetch_all($pdo, "SELECT post_id FROM social_posts WHERE connection_id = ? AND network = 'youtube'", [$connectionId]) as $r) {
        $own[(string) $r['post_id']] = true;
    }

    $rows = [];
    foreach ($playlists as $pid => $pl) {
        if ($pl['count'] === 0) { continue; }
        $pageToken = '';
        for ($page = 0; $page < 20; $page++) {
            $params = ['part' => 'contentDetails', 'playlistId' => $pid, 'maxResults' => 50];
            if ($pageToken !== '') { $params['pageToken'] = $pageToken; }
            $list = $dataGet('playlistItems', $params);
            foreach ($list['items'] ?? [] as $i => $it) {
                $vid = (string) ($it['contentDetails']['videoId'] ?? '');
                if ($vid !== '' && isset($own[$vid])) {
                    $rows[] = [$connectionId, mb_substr($pid, 0, 150), mb_substr($pl['title'], 0, 255), $vid, $page * 50 + $i];
                }
            }
            $pageToken = (string) ($list['nextPageToken'] ?? '');
            if ($pageToken === '') { break; }
        }
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM social_collections WHERE connection_id = ?')->execute([$connectionId]);
        $ins = $pdo->prepare('INSERT INTO social_collections (connection_id, collection_id, title, post_id, position) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE title = VALUES(title), position = VALUES(position)');
        foreach ($rows as $r) { $ins->execute($r); }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return count($playlists);
}
