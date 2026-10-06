<?php

require_once __DIR__ . '/demo-data.php';
require_once __DIR__ . '/ai-sources.php';

/**
 * Czech labels for standard GA4 event names + default channel groups. Custom/unknown names pass through.
 * Applied at display time only — stored values stay the original GA4 strings.
 */
function allstat_ga4_event_label(string $name): string
{
    static $map = [
        'page_view' => 'Zobrazení stránky',
        'session_start' => 'Začátek relace',
        'first_visit' => 'První návštěva',
        'user_engagement' => 'Aktivní zapojení',
        'scroll' => 'Scrollování',
        'click' => 'Kliknutí',
        'form_submit' => 'Odeslání formuláře',
        'form_start' => 'Začátek formuláře',
        'generate_lead' => 'Lead (poptávka)',
        'file_download' => 'Stažení souboru',
        'video_start' => 'Spuštění videa',
        'video_progress' => 'Průběh videa',
        'video_complete' => 'Dokončení videa',
        'view_search_results' => 'Zobrazení výsledků hledání',
        'search' => 'Vyhledávání',
        'purchase' => 'Nákup',
        'add_to_cart' => 'Přidání do košíku',
        'begin_checkout' => 'Zahájení objednávky',
        'sign_up' => 'Registrace',
        'login' => 'Přihlášení',
        'view_item' => 'Zobrazení produktu',
        'view_item_list' => 'Zobrazení seznamu produktů',
        'select_item' => 'Výběr produktu',
        'view_cart' => 'Zobrazení košíku',
        'remove_from_cart' => 'Odebrání z košíku',
        'add_shipping_info' => 'Zadání dopravy',
        'add_payment_info' => 'Zadání platby',
        'add_to_wishlist' => 'Přidání do oblíbených',
        'refund' => 'Vrácení peněz',
        'outbound_click' => 'Odchozí proklik',
    ];

    return $map[$name] ?? $name;
}

function allstat_ga4_channel_label(string $name): string
{
    static $map = [
        'Direct' => 'Přímá návštěvnost',
        'Organic Search' => 'Organické vyhledávání',
        'Paid Search' => 'Placené vyhledávání',
        'Referral' => 'Odkazující weby',
        'Organic Social' => 'Organické sociální sítě',
        'Paid Social' => 'Placené sociální sítě',
        'Social' => 'Sociální sítě',
        // GA4 přidalo 5/2026 nativní kanál pro návštěvy z AI asistentů (medium=ai-assistant).
        'AI Assistant' => 'AI asistenti',
        'Email' => 'E-mail',
        'Display' => 'Display reklama',
        'Affiliates' => 'Afiliace',
        'Organic Shopping' => 'Organické nákupy',
        'Paid Shopping' => 'Placené nákupy',
        'Organic Video' => 'Organické video',
        'Paid Video' => 'Placené video',
        'Audio' => 'Audio',
        'SMS' => 'SMS',
        'Mobile Push Notifications' => 'Push notifikace',
        'Cross-network' => 'Cross-network',
        'Unassigned' => 'Nepřiřazeno',
        '(other)' => 'Ostatní',
    ];

    return $map[$name] ?? $name;
}

function allstat_get_domains(?PDO $pdo): array
{
    if ($pdo && allstat_tables_ready($pdo)) {
        // Uživatel s přístupem jen k vybraným webům vidí jen je (allstat_domain_scope v auth.php).
        $scope = function_exists('allstat_domain_scope') ? allstat_domain_scope() : null;
        if ($scope === []) {
            return [];
        }

        try {
            $domains = allstat_fetch_all(
                $pdo,
                'SELECT id, name, url FROM domains WHERE is_active = 1'
                    . ($scope !== null ? ' AND id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')' : '')
                    . ' ORDER BY name ASC',
                $scope ?? []
            );

            return array_map(static fn (array $domain): array => [
                'id' => (int) $domain['id'],
                'name' => $domain['name'],
                'url' => $domain['url'],
            ], $domains);
        } catch (Throwable) {
            return [];
        }
    }

    return allstat_demo_domains();
}

function allstat_get_dashboard_data(?PDO $pdo, int $domainId, string $start, string $end, string $granularity = 'day'): array
{
    [$start, $end] = allstat_limited_range($start, $end);
    $granularity = allstat_normalize_granularity($granularity);

    if ($pdo && allstat_tables_ready($pdo)) {
        try {
            return allstat_sql_dashboard_data($pdo, $domainId, $start, $end, $granularity);
        } catch (Throwable) {
            return allstat_empty_dashboard_data([], $domainId, $start, $end, $granularity, true);
        }
    }

    return allstat_demo_dashboard_data($domainId, $start, $end, $granularity);
}

function allstat_sql_dashboard_data(PDO $pdo, int $domainId, string $start, string $end, string $granularity = 'day'): array
{
    $domains = allstat_get_domains($pdo);
    $domain = $domains[0] ?? null;

    foreach ($domains as $item) {
        if ((int) $item['id'] === $domainId) {
            $domain = $item;
            break;
        }
    }

    if (!$domain) {
        return allstat_empty_dashboard_data($domains, $domainId, $start, $end, $granularity, true);
    }

    $domainId = (int) $domain['id'];
    [$previousStart, $previousEnd] = allstat_previous_range($start, $end);
    $sourceStatuses = allstat_query_source_statuses($pdo, $domainId);

    if (!$sourceStatuses) {
        return allstat_empty_dashboard_data($domains, $domainId, $start, $end, $granularity, true);
    }

    $summary = allstat_query_summary($pdo, $domainId, $start, $end);
    $previous = allstat_query_summary($pdo, $domainId, $previousStart, $previousEnd);
    $series = allstat_query_series($pdo, $domainId, $start, $end, $granularity);

    $payload = allstat_dashboard_payload(
        $domain,
        $domains,
        $start,
        $end,
        $granularity,
        $summary,
        $previous,
        $series,
        allstat_query_traffic_sources($pdo, $domainId, $start, $end),
        allstat_query_landing_pages($pdo, $domainId, $start, $end, $previousStart, $previousEnd),
        allstat_query_search_queries($pdo, $domainId, $start, $end),
        $sourceStatuses,
        true,
        ['start' => $previousStart, 'end' => $previousEnd],
        allstat_query_geo($pdo, $domainId, $start, $end),
        allstat_query_events($pdo, $domainId, $start, $end),
        allstat_query_ai_sources($pdo, $domainId, $start, $end),
        allstat_query_referrers($pdo, $domainId, $start, $end),
        allstat_query_devices($pdo, $domainId, $start, $end),
        allstat_query_all_pages($pdo, $domainId, $start, $end)
    );

    // E-commerce add-ons (overview): funnel from events_daily + top products from items_daily. Added after
    // the shared payload so demo/empty paths simply don't carry them (index.php guards with ?? []).
    $payload['tables']['funnel'] = allstat_query_ecommerce_funnel($pdo, $domainId, $start, $end);
    $payload['tables']['topProducts'] = allstat_query_top_products($pdo, $domainId, $start, $end);
    $payload['tables']['demographics'] = allstat_query_demographics($pdo, $domainId, $start, $end);
    $payload['tables']['utm'] = allstat_query_utm($pdo, $domainId, $start, $end);

    return $payload;
}

/**
 * Auto-pick the chart interval from the selected range length, so the visits chart always reads well
 * without a manual switcher: short range → daily, up to ~half a year → weekly, longer → monthly.
 */
function allstat_auto_granularity(string $start, string $end): string
{
    try {
        $days = (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days + 1;
    } catch (Throwable) {
        return 'day';
    }
    if ($days <= 45) { return 'day'; }
    if ($days <= 184) { return 'week'; }

    return 'month';
}

function allstat_normalize_granularity(?string $granularity): string
{
    return in_array($granularity, ['day', 'week', 'month'], true) ? $granularity : 'day';
}

/**
 * Klíč periody pro seskupení řady (Y-m-d): den = datum, týden = pondělí ISO týdne, měsíc = 1. den měsíce.
 * SQL varianta a PHP varianta (allstat_period_key) musí dávat totéž, jinak se snímkové metriky (sledující
 * celkem) netrefí do periody. $column je vždy interní název sloupce, nikdy vstup od uživatele.
 */
function allstat_period_key_sql(string $column, string $granularity): string
{
    return match (allstat_normalize_granularity($granularity)) {
        'month' => "DATE_FORMAT($column, '%Y-%m-01')",
        'week' => "DATE_SUB($column, INTERVAL WEEKDAY($column) DAY)",
        default => $column,
    };
}

function allstat_period_key(string $date, string $granularity): string
{
    try {
        $d = new DateTimeImmutable($date);
    } catch (Throwable) {
        return $date;
    }

    return match (allstat_normalize_granularity($granularity)) {
        'month' => $d->format('Y-m-01'),
        'week' => $d->modify('-' . ((int) $d->format('N') - 1) . ' days')->format('Y-m-d'),
        default => $d->format('Y-m-d'),
    };
}

function allstat_query_summary(PDO $pdo, int $domainId, string $start, string $end): array
{
    $row = allstat_fetch_one($pdo, '
        SELECT
            COALESCE(SUM(visits), 0) AS visits,
            COALESCE(SUM(engaged_sessions), 0) AS engaged_sessions,
            COALESCE(SUM(engagement_time_sec), 0) AS engagement_time_sec,
            COALESCE(SUM(users_count), 0) AS users,
            COALESCE(SUM(new_users), 0) AS new_users,
            COALESCE(SUM(clicks), 0) AS clicks,
            COALESCE(SUM(impressions), 0) AS impressions,
            COALESCE(SUM(conversions), 0) AS conversions,
            COALESCE(SUM(revenue), 0) AS revenue
        FROM metrics_daily
        WHERE domain_id = ? AND metric_date BETWEEN ? AND ?
    ', [$domainId, $start, $end]) ?? [];

    $ai = allstat_fetch_one($pdo, '
        SELECT COALESCE(SUM(sessions), 0) AS ai_sessions, COALESCE(SUM(conversions), 0) AS ai_conversions
        FROM ai_sources_daily
        WHERE domain_id = ? AND metric_date BETWEEN ? AND ?
    ', [$domainId, $start, $end]) ?? [];
    $row['ai_sessions'] = (int) ($ai['ai_sessions'] ?? 0);
    $row['ai_conversions'] = (int) ($ai['ai_conversions'] ?? 0);

    // Uživatelé: denní activeUsers se nedají sčítat (kdo přišel ve více dnech, je v součtu vícekrát). Počet různých
    // lidí za období ukládá GA4 sync pro běžná období (period_users); jinde zůstane součet dní a users_basis to řekne.
    $row['users_basis'] = $start === $end ? 'unique' : 'daily_sum';
    if ($start !== $end && ($unique = allstat_period_users_lookup($pdo, $domainId, $start, $end)) !== null) {
        $row['users'] = $unique['active_users'];
        $row['users_basis'] = 'unique';
    }

    return allstat_summary_from_values($row);
}

/**
 * Počet různých uživatelů (GA4 activeUsers) za přesně toto období, pokud ho GA4 sync uložil; jinak null.
 *
 * @return array{active_users: int, new_users: int, updated_at: string}|null
 */
function allstat_period_users_lookup(PDO $pdo, int $domainId, string $start, string $end): ?array
{
    try {
        $row = allstat_fetch_one($pdo, 'SELECT active_users, new_users, updated_at FROM period_users WHERE domain_id = ? AND start_date = ? AND end_date = ? LIMIT 1', [$domainId, $start, $end]);
    } catch (Throwable) {
        return null; // tabulka ještě neexistuje (před migrací)
    }

    return $row ? ['active_users' => (int) $row['active_users'], 'new_users' => (int) $row['new_users'], 'updated_at' => (string) $row['updated_at']] : null;
}

function allstat_query_series(PDO $pdo, int $domainId, string $start, string $end, string $granularity = 'day'): array
{
    $granularity = allstat_normalize_granularity($granularity);
    $groupBy = match ($granularity) {
        'month' => 'YEAR(m.metric_date), MONTH(m.metric_date)',
        'week' => 'YEARWEEK(m.metric_date, 3)',
        default => 'm.metric_date',
    };

    $rows = allstat_fetch_all($pdo, "
        SELECT
            MIN(m.metric_date) AS period_start,
            MAX(m.metric_date) AS period_end,
            SUM(m.visits) AS visits,
            SUM(m.engaged_sessions) AS engaged_sessions,
            SUM(m.engagement_time_sec) AS engagement_time_sec,
            SUM(m.users_count) AS users,
            SUM(m.new_users) AS new_users,
            SUM(m.clicks) AS clicks,
            SUM(m.impressions) AS impressions,
            SUM(m.conversions) AS conversions,
            SUM(m.revenue) AS revenue,
            SUM(COALESCE(ai.sessions, 0)) AS ai_sessions
        FROM metrics_daily m
        LEFT JOIN (
            SELECT metric_date, SUM(sessions) AS sessions
            FROM ai_sources_daily
            WHERE domain_id = ? AND metric_date BETWEEN ? AND ?
            GROUP BY metric_date
        ) ai ON ai.metric_date = m.metric_date
        WHERE m.domain_id = ? AND m.metric_date BETWEEN ? AND ?
        GROUP BY $groupBy
        ORDER BY period_start ASC
    ", [$domainId, $start, $end, $domainId, $start, $end]);

    return allstat_series_from_rows(
        array_map(static fn (array $row): array => allstat_summary_row_from_values($row), $rows),
        $granularity
    );
}

function allstat_query_traffic_sources(PDO $pdo, int $domainId, string $start, string $end): array
{
    $rows = allstat_fetch_all($pdo, '
        SELECT source, SUM(sessions) AS sessions, SUM(conversions) AS conversions
        FROM traffic_sources_daily
        WHERE domain_id = ? AND metric_date BETWEEN ? AND ?
        GROUP BY source
        ORDER BY sessions DESC
    ', [$domainId, $start, $end]);

    return array_map(static fn (array $row): array => [
        'source' => $row['source'],
        'sessions' => (int) $row['sessions'],
        'conversions' => (int) $row['conversions'],
    ], $rows);
}

function allstat_query_ai_sources(PDO $pdo, int $domainId, string $start, string $end, int $limit = 12): array
{
    $rows = allstat_fetch_all($pdo, "
        SELECT source, SUM(sessions) AS sessions, SUM(conversions) AS conversions
        FROM ai_sources_daily
        WHERE domain_id = ? AND metric_date BETWEEN ? AND ?
        GROUP BY source
        ORDER BY sessions DESC
        LIMIT " . (int) $limit . "
    ", [$domainId, $start, $end]);
    $total = (int) (allstat_fetch_one($pdo, 'SELECT COALESCE(SUM(sessions), 0) AS sessions FROM ai_sources_daily WHERE domain_id = ? AND metric_date BETWEEN ? AND ?', [$domainId, $start, $end])['sessions'] ?? 0);

    return allstat_decorate_ai_sources(array_map(static fn (array $row): array => [
        'source' => $row['source'],
        'sessions' => (int) $row['sessions'],
        'conversions' => (int) $row['conversions'],
    ], $rows), $total);
}

/**
 * Add share (of total AI sessions) and formatted labels to an AI-source breakdown.
 * $total = všechny AI návštěvy za období; bez něj se podíl počítá z předaného seznamu.
 */
function allstat_decorate_ai_sources(array $sources, ?int $total = null): array
{
    $total = max($total ?? 0, array_sum(array_column($sources, 'sessions')));

    return array_map(static function (array $source) use ($total): array {
        $share = $total > 0 ? ((int) $source['sessions'] / $total) * 100 : 0;
        $source['share'] = $share;
        $source['shareLabel'] = allstat_percent($share, 1);
        $source['sessionsLabel'] = allstat_number((int) $source['sessions']);
        $source['conversionsLabel'] = allstat_number((int) ($source['conversions'] ?? 0));

        return $source;
    }, $sources);
}

/**
 * Turn GA4's bracketed placeholders ((not set)/(referral)/(organic)/(direct)/(none)) into a readable
 * label, so the UTM panel never shows raw "(not set)" as a campaign / content name.
 */
function allstat_utm_clean(string $value, string $emptyLabel): string
{
    return in_array($value, ['(not set)', '(referral)', '(organic)', '(direct)', '(none)', '(data not available)', '(not provided)', ''], true) ? $emptyLabel : $value;
}

/**
 * Human label for GA4 auto-generated campaign values that aren't real hand-written UTM tags,
 * so the dashboard doesn't show raw tokens like "(cross-network)".
 */
function allstat_utm_campaign_label(string $campaign): string
{
    return match ($campaign) {
        '(cross-network)' => 'Google reklamy, více sítí (cross-network)',
        default => allstat_utm_clean($campaign, '(bez kampaně)'),
    };
}

/**
 * UTM campaign overview (GA4): groups utm_daily by campaign+source+medium over the range, each with its
 * top landing pages. Answers "lidé z utm_campaign=X přišli na které stránky + kolik konverzí". Only
 * UTM-tagged / non-passive traffic is stored (see the GA4 sync), so this is campaign traffic, not organic.
 */
function allstat_query_utm(PDO $pdo, int $domainId, string $start, string $end, int $maxCampaigns = 30, int $maxPagesPerCampaign = 5): array
{
    $rows = allstat_fetch_all($pdo, "
        SELECT campaign, source, medium, content, landing_page,
               SUM(sessions) AS sessions, SUM(conversions) AS conversions
        FROM utm_daily
        WHERE domain_id = ? AND metric_date BETWEEN ? AND ?
        GROUP BY campaign, source, medium, content, landing_page
    ", [$domainId, $start, $end]);

    $groups = [];
    foreach ($rows as $r) {
        $key = $r['campaign'] . "\x1f" . $r['source'] . "\x1f" . $r['medium'] . "\x1f" . $r['content'];
        if (!isset($groups[$key])) {
            $groups[$key] = ['campaign' => (string) $r['campaign'], 'source' => (string) $r['source'], 'medium' => (string) $r['medium'], 'content' => (string) $r['content'], 'sessions' => 0, 'conversions' => 0, 'pages' => []];
        }
        $sess = (int) $r['sessions'];
        $groups[$key]['sessions'] += $sess;
        $groups[$key]['conversions'] += (int) $r['conversions'];
        if (($lp = (string) $r['landing_page']) !== '') {
            $groups[$key]['pages'][] = ['path' => $lp, 'sessions' => $sess];
        }
    }

    $groups = array_values($groups);
    usort($groups, static fn (array $a, array $b): int => $b['sessions'] <=> $a['sessions']);
    $totalSessions = array_sum(array_column($groups, 'sessions'));
    $groups = array_slice($groups, 0, $maxCampaigns);

    foreach ($groups as &$g) {
        usort($g['pages'], static fn (array $a, array $b): int => $b['sessions'] <=> $a['sessions']);
        $g['pages'] = array_slice($g['pages'], 0, $maxPagesPerCampaign);
        $g['sessionsLabel'] = allstat_number($g['sessions']);
        $g['conversionsLabel'] = allstat_number($g['conversions']);
        $g['campaignLabel'] = allstat_utm_campaign_label($g['campaign']);
        $g['contentLabel'] = allstat_utm_clean($g['content'], '—');
        // Konverze jsou GA4 události — může jich být víc než návštěv; >100 % by jen mátlo, tak se neukazuje.
        $g['convRate'] = $g['sessions'] > 0 && $g['conversions'] <= $g['sessions'] ? allstat_percent($g['conversions'] / $g['sessions'] * 100, 1) : '—';
        $g['share'] = $totalSessions > 0 ? $g['sessions'] / $totalSessions * 100 : 0;
        $g['shareLabel'] = allstat_percent($g['share'], 1);
        $src = allstat_utm_clean($g['source'], '');
        $med = allstat_utm_clean($g['medium'], '');
        $g['channelLabel'] = $src !== '' && $med !== '' ? $src . ' / ' . $med : ($src . $med !== '' ? $src . $med : '—');
        foreach ($g['pages'] as &$p) { $p['sessionsLabel'] = allstat_number($p['sessions']); }
        unset($p);
    }
    unset($g);

    return $groups;
}

function allstat_query_referrers(PDO $pdo, int $domainId, string $start, string $end, int $limit = 12): array
{
    $rows = allstat_fetch_all($pdo, "
        SELECT source, SUM(sessions) AS sessions, SUM(conversions) AS conversions
        FROM referrers_daily
        WHERE domain_id = ? AND metric_date BETWEEN ? AND ?
        GROUP BY source
        ORDER BY sessions DESC
        LIMIT " . (int) $limit . "
    ", [$domainId, $start, $end]);
    $total = (int) (allstat_fetch_one($pdo, 'SELECT COALESCE(SUM(sessions), 0) AS sessions FROM referrers_daily WHERE domain_id = ? AND metric_date BETWEEN ? AND ?', [$domainId, $start, $end])['sessions'] ?? 0);

    return allstat_decorate_referrers(array_map(static fn (array $row): array => [
        'source' => $row['source'],
        'sessions' => (int) $row['sessions'],
        'conversions' => (int) $row['conversions'],
    ], $rows), $total);
}

/**
 * Add share, formatted labels and an AI-tool tag (if the host maps to a known AI assistant) to a referrer list.
 * $total = návštěvy ze všech odkazujících webů za období; bez něj se podíl počítá z předaného seznamu.
 */
function allstat_decorate_referrers(array $referrers, ?int $total = null): array
{
    $total = max($total ?? 0, array_sum(array_column($referrers, 'sessions')));

    return array_map(static function (array $referrer) use ($total): array {
        $share = $total > 0 ? ((int) $referrer['sessions'] / $total) * 100 : 0;
        $referrer['share'] = $share;
        $referrer['shareLabel'] = allstat_percent($share, 1);
        $referrer['sessionsLabel'] = allstat_number((int) $referrer['sessions']);
        $referrer['conversionsLabel'] = allstat_number((int) ($referrer['conversions'] ?? 0));
        $referrer['ai'] = allstat_classify_ai_source((string) $referrer['source']);

        return $referrer;
    }, $referrers);
}

function allstat_query_events(PDO $pdo, int $domainId, string $start, string $end, int $limit = 8): array
{
    $rows = allstat_fetch_all($pdo, "
        SELECT event_name, SUM(event_count) AS event_count, SUM(key_events) AS key_events
        FROM events_daily
        WHERE domain_id = ? AND metric_date BETWEEN ? AND ?
        GROUP BY event_name
        ORDER BY key_events DESC, event_count DESC
        LIMIT " . (int) $limit . "
    ", [$domainId, $start, $end]);

    return array_map(static fn (array $row): array => [
        'event' => allstat_ga4_event_label((string) $row['event_name']),
        'name' => (string) $row['event_name'], // surový název z GA4 (generate_lead…), kroky trychtýřů ho používají
        'count' => (int) $row['event_count'],
        'countLabel' => allstat_number((int) $row['event_count']),
        'keyEvents' => (int) $row['key_events'],
        'keyEventsLabel' => allstat_number((int) $row['key_events']),
        'isKey' => (int) $row['key_events'] > 0,
    ], $rows);
}

/**
 * Top stránky ve vyhledávání Google (gsc_pages_daily): kliknutí, imprese, CTR a pozice VÁŽENÁ impresemi
 * za období, per URL. Právě tohle potřebuje SEO analýza „stránek s vysokými impresemi a nízkým CTR" —
 * dotazy (search_queries_daily) říkají CO lidé hledají, tady je vidět KTERÁ stránka se na to zobrazuje.
 * Tabulka se plní GSC syncem od začátku, ale tohle je první čtečka.
 */
function allstat_query_gsc_pages(PDO $pdo, int $domainId, string $start, string $end, int $limit = 15): array
{
    [$start, $end] = allstat_limited_range($start, $end);
    $rows = allstat_fetch_all($pdo, "
        SELECT page,
               SUM(clicks) AS clicks,
               SUM(impressions) AS impressions,
               CASE WHEN SUM(impressions) > 0 THEN SUM(position * impressions) / SUM(impressions) ELSE 0 END AS position
        FROM gsc_pages_daily
        WHERE domain_id = ? AND metric_date BETWEEN ? AND ?
        GROUP BY page
        ORDER BY impressions DESC
        LIMIT " . max(1, $limit) . "
    ", [$domainId, $start, $end]);

    return array_map(static function (array $r): array {
        $clicks = (int) $r['clicks'];
        $impressions = (int) $r['impressions'];
        // Zkrácení na path — plné URL v tabulce zabíjejí čitelnost, doména je v kontextu reportu.
        $path = (string) parse_url((string) $r['page'], PHP_URL_PATH) ?: (string) $r['page'];
        $query = (string) parse_url((string) $r['page'], PHP_URL_QUERY);

        return [
            'page' => $path . ($query !== '' ? '?' . $query : ''),
            'clicks' => $clicks,
            'clicksLabel' => allstat_number($clicks),
            'impressions' => $impressions,
            'impressionsLabel' => allstat_number($impressions),
            'ctrLabel' => $impressions > 0 ? allstat_percent($clicks / $impressions * 100, 1) : '—',
            'positionLabel' => allstat_number((float) $r['position'], 1),
        ];
    }, $rows);
}

/**
 * E-commerce funnel from events_daily (data already synced). Builds the canonical GA4 funnel
 * view_item → add_to_cart → begin_checkout → add_shipping_info → add_payment_info → purchase, but ONLY the
 * steps the property actually measures (count > 0), so a shop that skips a step doesn't get a false 100 %
 * drop. Drop-off % is computed vs. the previous SHOWN step; the biggest drop is flagged as the bottleneck.
 * hasData = at least 2 measured steps (otherwise the web isn't an e-shop / doesn't measure e-commerce).
 */
function allstat_query_ecommerce_funnel(PDO $pdo, int $domainId, string $start, string $end): array
{
    $stepDefs = [
        'view_item'         => 'Zobrazení produktu',
        'add_to_cart'       => 'Přidání do košíku',
        'begin_checkout'    => 'Zahájení objednávky',
        'add_shipping_info' => 'Zadání dopravy',
        'add_payment_info'  => 'Zadání platby',
        'purchase'          => 'Nákup',
    ];
    $names = array_keys($stepDefs);
    $names[] = 'remove_from_cart'; // leak indicator, not a forward step
    $ph = implode(',', array_fill(0, count($names), '?'));
    try {
        $rows = allstat_fetch_all($pdo, "
            SELECT event_name, SUM(event_count) AS c
            FROM events_daily
            WHERE domain_id = ? AND metric_date BETWEEN ? AND ? AND event_name IN ($ph)
            GROUP BY event_name
        ", array_merge([$domainId, $start, $end], $names));
    } catch (Throwable) {
        return ['steps' => [], 'hasData' => false, 'removeFromCart' => 0, 'removeFromCartLabel' => '0', 'overallLabel' => ''];
    }
    $counts = [];
    foreach ($rows as $r) { $counts[(string) $r['event_name']] = (int) $r['c']; }

    $steps = [];
    $top = null;
    $prev = null;
    foreach ($stepDefs as $event => $label) {
        $count = (int) ($counts[$event] ?? 0);
        if ($count <= 0) { continue; }
        if ($top === null) { $top = $count; }
        $drop = ($prev !== null && $prev > 0) ? (1 - $count / $prev) * 100 : 0.0;
        $steps[] = [
            'event' => $event,
            'label' => $label,
            'count' => $count,
            'countLabel' => allstat_number($count),
            'share' => $top > 0 ? $count / $top * 100 : 0,
            'shareLabel' => allstat_percent($top > 0 ? $count / $top * 100 : 0, 1),
            'drop' => $drop,
            'dropLabel' => $prev !== null ? '−' . allstat_percent($drop, 1) : '',
            'isBottleneck' => false,
        ];
        $prev = $count;
    }

    // Flag the biggest drop as the bottleneck (only among 2nd+ steps).
    $maxDrop = -1.0; $bottleneck = -1;
    foreach ($steps as $i => $s) {
        if ($i > 0 && $s['drop'] > $maxDrop) { $maxDrop = $s['drop']; $bottleneck = $i; }
    }
    if ($bottleneck >= 0 && $maxDrop > 0) { $steps[$bottleneck]['isBottleneck'] = true; }

    $overallLabel = '';
    if (count($steps) >= 2 && $steps[0]['count'] > 0) {
        $last = $steps[count($steps) - 1];
        $overallLabel = $steps[0]['label'] . ' → ' . $last['label'] . ': ' . allstat_percent($last['count'] / $steps[0]['count'] * 100, 2);
    }

    $remove = (int) ($counts['remove_from_cart'] ?? 0);

    return [
        'steps' => $steps,
        'hasData' => count($steps) >= 2,
        'removeFromCart' => $remove,
        'removeFromCartLabel' => allstat_number($remove),
        'overallLabel' => $overallLabel,
    ];
}

/**
 * Top selling products from items_daily (GA4 itemName report; only populated on e-commerce properties
 * after a sync). Ordered by revenue, then units sold. buyRate = purchased / viewed (view→buy conversion).
 */
function allstat_query_top_products(PDO $pdo, int $domainId, string $start, string $end, int $limit = 10): array
{
    try {
        $rows = allstat_fetch_all($pdo, "
            SELECT item_name,
                SUM(items_viewed) AS viewed,
                SUM(items_added_to_cart) AS added,
                SUM(items_purchased) AS purchased,
                SUM(item_revenue) AS revenue
            FROM items_daily
            WHERE domain_id = ? AND metric_date BETWEEN ? AND ? AND item_name <> '' AND item_name <> '(not set)'
            GROUP BY item_name
            ORDER BY revenue DESC, purchased DESC
            LIMIT " . max(1, (int) $limit) . "
        ", [$domainId, $start, $end]);
    } catch (Throwable) {
        return [];
    }

    return array_map(static function (array $r): array {
        $viewed = (int) $r['viewed'];
        $purchased = (int) $r['purchased'];

        return [
            'name' => (string) $r['item_name'],
            'viewed' => $viewed,
            'viewedLabel' => allstat_number($viewed),
            'added' => (int) $r['added'],
            'addedLabel' => allstat_number((int) $r['added']),
            'purchased' => $purchased,
            'purchasedLabel' => allstat_number($purchased),
            'revenue' => (float) $r['revenue'],
            'revenueLabel' => allstat_number((float) $r['revenue'], 0),
            'buyRateLabel' => $viewed > 0 ? allstat_percent($purchased / $viewed * 100, 1) : '—',
        ];
    }, $rows);
}

/**
 * Audience demographics (age bracket + gender) from demographics_daily. GA4 only returns these when Google
 * Signals is enabled, and it THRESHOLDS small segments (withholds rows / buckets them as 'unknown') for
 * privacy — so this is a MODELLED, aggregate estimate, never per-user, and the shares need not sum to 100 %.
 * hasData = there is at least one KNOWN age/gender bucket (otherwise Signals is off → the view hides itself).
 */
function allstat_query_demographics(PDO $pdo, int $domainId, string $start, string $end): array
{
    try {
        $rows = allstat_fetch_all($pdo, "
            SELECT age_bracket, gender, SUM(users) AS users
            FROM demographics_daily
            WHERE domain_id = ? AND metric_date BETWEEN ? AND ?
            GROUP BY age_bracket, gender
        ", [$domainId, $start, $end]);
    } catch (Throwable) {
        return ['age' => [], 'gender' => [], 'hasData' => false, 'total' => 0, 'totalLabel' => '0'];
    }

    $ageOrder = ['18-24', '25-34', '35-44', '45-54', '55-64', '65+'];
    $genderLabels = ['male' => 'Muži', 'female' => 'Ženy'];
    $isUnknown = static fn (string $v): bool => $v === '' || $v === 'unknown' || $v === '(not set)';

    $ageAgg = []; $genderAgg = []; $total = 0; $known = 0;
    foreach ($rows as $r) {
        $u = (int) $r['users'];
        $ageKey = $isUnknown((string) $r['age_bracket']) ? 'unknown' : (string) $r['age_bracket'];
        $genderKey = $isUnknown((string) $r['gender']) ? 'unknown' : (string) $r['gender'];
        $ageAgg[$ageKey] = ($ageAgg[$ageKey] ?? 0) + $u;
        $genderAgg[$genderKey] = ($genderAgg[$genderKey] ?? 0) + $u;
        $total += $u;
        if (!$isUnknown((string) $r['age_bracket']) || !$isUnknown((string) $r['gender'])) { $known += $u; }
    }

    $build = static function (array $agg, array $order, array $labels) use ($total): array {
        $out = [];
        foreach ($order as $key) {
            if (empty($agg[$key])) { continue; }
            $u = (int) $agg[$key];
            $out[] = ['label' => $labels[$key] ?? $key, 'users' => $u, 'usersLabel' => allstat_number($u),
                'share' => $total > 0 ? $u / $total * 100 : 0, 'shareLabel' => allstat_percent($total > 0 ? $u / $total * 100 : 0, 1)];
        }
        if (!empty($agg['unknown'])) {
            $u = (int) $agg['unknown'];
            $out[] = ['label' => 'Neurčeno', 'users' => $u, 'usersLabel' => allstat_number($u),
                'share' => $total > 0 ? $u / $total * 100 : 0, 'shareLabel' => allstat_percent($total > 0 ? $u / $total * 100 : 0, 1)];
        }
        return $out;
    };

    return [
        'age' => $build($ageAgg, $ageOrder, array_combine($ageOrder, $ageOrder)),
        'gender' => $build($genderAgg, ['male', 'female'], $genderLabels),
        'hasData' => $known > 0,
        'total' => $total,
        'totalLabel' => allstat_number($total),
    ];
}

function allstat_query_geo(PDO $pdo, int $domainId, string $start, string $end, int $limit = 8): array
{
    $rows = allstat_fetch_all($pdo, "
        SELECT region, country, SUM(sessions) AS sessions, SUM(users) AS users, SUM(conversions) AS conversions
        FROM geo_daily
        WHERE domain_id = ? AND metric_date BETWEEN ? AND ? AND region <> '' AND region <> '(not set)'
        GROUP BY region, country
        ORDER BY sessions DESC
        LIMIT " . (int) $limit . "
    ", [$domainId, $start, $end]);

    $total = (int) (allstat_fetch_one($pdo, "
        SELECT COALESCE(SUM(sessions), 0) AS total
        FROM geo_daily
        WHERE domain_id = ? AND metric_date BETWEEN ? AND ? AND region <> '' AND region <> '(not set)'
    ", [$domainId, $start, $end])['total'] ?? 0);

    return array_map(static function (array $row) use ($total): array {
        $sessions = (int) $row['sessions'];
        $share = $total > 0 ? ($sessions / $total) * 100 : 0;
        return [
            'region' => $row['region'],
            'country' => $row['country'],
            'sessions' => $sessions,
            'sessionsLabel' => allstat_number($sessions),
            'users' => (int) $row['users'],
            'conversions' => (int) $row['conversions'],
            'share' => $share,
            'shareLabel' => allstat_percent($share, 1),
        ];
    }, $rows);
}

function allstat_query_landing_pages(PDO $pdo, int $domainId, string $start, string $end, string $previousStart, string $previousEnd, int $limit = 5): array
{
    $current = allstat_fetch_all($pdo, "
        SELECT path, SUM(sessions) AS sessions, SUM(conversions) AS conversions
        FROM landing_pages_daily
        WHERE domain_id = ? AND metric_date BETWEEN ? AND ? AND path <> '' AND path <> '(not set)'
        GROUP BY path
        ORDER BY sessions DESC
        LIMIT " . max(1, $limit) . "
    ", [$domainId, $start, $end]);
    $previous = allstat_fetch_all($pdo, "
        SELECT path, SUM(sessions) AS sessions
        FROM landing_pages_daily
        WHERE domain_id = ? AND metric_date BETWEEN ? AND ? AND path <> '' AND path <> '(not set)'
        GROUP BY path
    ", [$domainId, $previousStart, $previousEnd]);
    $previousMap = [];

    foreach ($previous as $row) {
        $previousMap[$row['path']] = (int) $row['sessions'];
    }

    return array_map(static function (array $row) use ($previousMap): array {
        $change = allstat_change((int) $row['sessions'], $previousMap[$row['path']] ?? 0);

        return [
            'path' => $row['path'],
            'sessions' => (int) $row['sessions'],
            'conversions' => (int) $row['conversions'],
            'change' => $change,
            'changeLabel' => allstat_change_label($change),
        ];
    }, $current);
}

/**
 * All viewed pages (pagePath) summed over the range, ordered by page views — the "Všechny" tab of
 * the Top stránky panel (the "Vstupní" tab uses landing_pages_daily). Mirrors landing-page style.
 */
function allstat_query_all_pages(PDO $pdo, int $domainId, string $start, string $end, int $limit = 7): array
{
    $rows = allstat_fetch_all($pdo, "
        SELECT path, SUM(views) AS views, SUM(sessions) AS sessions, SUM(conversions) AS conversions
        FROM pages_daily
        WHERE domain_id = ? AND metric_date BETWEEN ? AND ? AND path <> '' AND path <> '(not set)'
        GROUP BY path
        ORDER BY views DESC
        LIMIT " . max(1, $limit) . "
    ", [$domainId, $start, $end]);

    // Podíl ze VŠECH zobrazení stránek za období, ne jen z vrácené top-N (jinak by se nafukoval).
    $total = (int) (allstat_fetch_one($pdo, "
        SELECT COALESCE(SUM(views), 0) AS views
        FROM pages_daily
        WHERE domain_id = ? AND metric_date BETWEEN ? AND ? AND path <> '' AND path <> '(not set)'
    ", [$domainId, $start, $end])['views'] ?? 0);

    return array_map(static function (array $row) use ($total): array {
        $views = (int) $row['views'];
        $share = $total > 0 ? ($views / $total) * 100 : 0;

        return [
            'path' => $row['path'],
            'views' => $views,
            'viewsLabel' => allstat_number($views),
            'sessions' => (int) $row['sessions'],
            'share' => $share,
            'shareLabel' => allstat_percent($share, 1),
        ];
    }, $rows);
}

/**
 * Sessions split by device category (mobile/desktop/tablet…) summed over the range, for the
 * "Zařízení" doughnut. English GA4 categories are mapped to Czech labels.
 */
function allstat_query_devices(PDO $pdo, int $domainId, string $start, string $end): array
{
    $rows = allstat_fetch_all($pdo, "
        SELECT device, SUM(sessions) AS sessions, SUM(conversions) AS conversions
        FROM device_daily
        WHERE domain_id = ? AND metric_date BETWEEN ? AND ? AND device <> '' AND device <> '(other)'
        GROUP BY device
        ORDER BY sessions DESC
    ", [$domainId, $start, $end]);

    return array_map(static fn (array $row): array => [
        'device' => allstat_device_label((string) $row['device']),
        'sessions' => (int) $row['sessions'],
        'conversions' => (int) $row['conversions'],
    ], $rows);
}

/**
 * Czech labels for GA4 deviceCategory values. Unknown values are capitalized as a fallback.
 */
function allstat_device_label(string $device): string
{
    static $map = [
        'mobile' => 'Mobil',
        'desktop' => 'Desktop',
        'tablet' => 'Tablet',
        'smart tv' => 'Smart TV',
        'smarttv' => 'Smart TV',
        'wearable' => 'Nositelné',
        'console' => 'Konzole',
    ];
    $key = mb_strtolower(trim($device));

    return $map[$key] ?? ($device === '' ? 'Ostatní' : mb_strtoupper(mb_substr($device, 0, 1)) . mb_substr($device, 1));
}

/**
 * Sum the post-level metrics for one connection over [start,end], EXCLUDING ephemeral Stories
 * (post_type='story') so the "počet příspěvků"/frequency KPIs stay meaningful. Returns plain ints.
 */
function allstat_social_period_aggregate(PDO $pdo, int $connectionId, string $start, string $end): array
{
    $row = allstat_fetch_one($pdo, "
        SELECT COUNT(*) AS cnt, COALESCE(SUM(engagement),0) AS eng, COALESCE(SUM(reactions),0) AS rea,
               COALESCE(SUM(comments),0) AS com, COALESCE(SUM(shares),0) AS sha, COALESCE(SUM(reach),0) AS reach,
               COALESCE(SUM(saved),0) AS saved, COALESCE(SUM(total_interactions),0) AS tot_int,
               COALESCE(SUM(impressions),0) AS impr, COALESCE(SUM(post_clicks),0) AS clicks, COALESCE(SUM(video_views),0) AS video,
               COALESCE(SUM(watch_time_sec),0) AS watch,
               COALESCE(SUM(r_like),0) AS r_like, COALESCE(SUM(r_love),0) AS r_love, COALESCE(SUM(r_haha),0) AS r_haha,
               COALESCE(SUM(r_wow),0) AS r_wow, COALESCE(SUM(r_sad),0) AS r_sad, COALESCE(SUM(r_angry),0) AS r_angry
        FROM social_posts
        WHERE connection_id = ? AND post_type <> 'story' AND metric_date BETWEEN ? AND ?
    ", [$connectionId, $start, $end]) ?? [];

    $int = static fn (string $k): int => (int) round((float) ($row[$k] ?? 0));

    return [
        'count' => $int('cnt'), 'eng' => $int('eng'), 'rea' => $int('rea'), 'com' => $int('com'), 'sha' => $int('sha'),
        'saved' => $int('saved'), 'totalInteractions' => $int('tot_int'),
        'reach' => $int('reach'), 'impressions' => $int('impr'), 'clicks' => $int('clicks'), 'video' => $int('video'), 'watch' => $int('watch'),
        'reactions' => ['like' => $int('r_like'), 'love' => $int('r_love'), 'haha' => $int('r_haha'), 'wow' => $int('r_wow'), 'sad' => $int('r_sad'), 'angry' => $int('r_angry')],
    ];
}

/**
 * Human "Doba sledování" label from total seconds, Business-Suite style: "5 h 47 min" / "3 min 12 s".
 */
function allstat_watch_time_label(int $seconds): string
{
    if ($seconds <= 0) { return '—'; }
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    if ($h > 0) { return $h . ' h ' . $m . ' min'; }
    $s = $seconds % 60;
    return $m > 0 ? $m . ' min ' . $s . ' s' : $s . ' s';
}

/**
 * Dvojice [aktuální net, předchozí net] pro srovnání přírůstku sledujících, nebo [null, null], když srovnání
 * nedává smysl: chybí zdroj, období se měřila jinak (události vs. dopočet ze snapshotu), nebo je předchozí
 * období pokryté výrazně méně dny než aktuální. Práh 80 % pustí drobné mezery (výpadek syncu), ale zachytí
 * případ „aktuální období naměřené celé, předchozí skoro vůbec", kde by procento bylo nesmyslné.
 */
function allstat_social_followers_delta_pair(array $cur, array $prev): array
{
    $source = $cur['source'] ?? 'none';
    $curDays = (int) ($cur['days'] ?? 0);
    $prevDays = (int) ($prev['days'] ?? 0);
    $comparable = $source !== 'none'
        && ($prev['source'] ?? 'none') === $source
        && $curDays > 0
        && $prevDays >= (int) floor($curDays * 0.8);

    return $comparable ? [(float) $cur['net'], (float) $prev['net']] : [null, null];
}

/**
 * Net follower change for one connection over [start,end] from the page-level daily metrics
 * (new_follows = page_fan_adds, unfollows = page_fan_removes). connection_id is globally unique.
 *
 * Denní přírůstky nedává každá síť: LinkedIn je nemá vůbec. Když v období není ANI JEDEN takový řádek,
 * spočítá se čistý přírůstek z denních snapshotů „Sledující celkem" (followers_total) jako poslední minus
 * první hodnota. Rozlišuje se „řádky chybí" vs. „řádky jsou a jsou nulové" (druhé je platná nula, ne díra).
 *
 * Snapshoty existují až od nasazení metriky (zpětnou historii API nedá), takže u delšího období pokrývají
 * jen jeho konec. Proto se vrací i `source` a reálné pokrytí `from`/`to`/`days` — volající to MUSÍ v reportu
 * přiznat, jinak by se rozdíl za pár dní četl jako přírůstek za celé období.
 */
function allstat_social_followers(PDO $pdo, int $connectionId, string $start, string $end): array
{
    $row = allstat_fetch_one($pdo, "
        SELECT COALESCE(SUM(CASE WHEN metric_key = 'new_follows' THEN metric_value END),0) AS adds,
               COALESCE(SUM(CASE WHEN metric_key = 'unfollows' THEN metric_value END),0) AS lost,
               COUNT(DISTINCT CASE WHEN metric_key IN ('new_follows','unfollows') THEN metric_date END) AS days
        FROM provider_metrics_daily
        WHERE connection_id = ? AND dimension = '' AND metric_date BETWEEN ? AND ?
    ", [$connectionId, $start, $end]) ?? [];

    $eventDays = (int) ($row['days'] ?? 0);
    $periodDays = (int) (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days + 1;
    $events = static fn (): array => ['new' => (int) round((float) ($row['adds'] ?? 0)), 'lost' => (int) round((float) ($row['lost'] ?? 0)),
        'net' => (int) round((float) ($row['adds'] ?? 0)) - (int) round((float) ($row['lost'] ?? 0)),
        'source' => 'events', 'from' => $start, 'to' => $end, 'days' => $eventDays];
    // Instagram posílá jen nové sledující, odhlášení ne: čistá změna z denních přírůstků by byla nadsazená.
    // U něj má přednost rozdíl denních snímků, pokud pokrývá skoro celé období.
    $grossOnly = false;
    try {
        $grossOnly = (string) (allstat_fetch_one($pdo, 'SELECT s.provider_key FROM domain_sources ds JOIN data_sources s ON s.id = ds.source_id WHERE ds.id = ?', [$connectionId])['provider_key'] ?? '') === 'instagram_business';
    } catch (Throwable) { /* bez katalogu zdrojů platí běžné pravidlo */ }
    // Denní přírůstky pokrývají skoro celé období → platí ony (nula je tu skutečná nula).
    if (!$grossOnly && $eventDays > 0 && $eventDays >= max(1, (int) floor($periodDays * 0.8))) {
        return $events();
    }

    // Jinak (přírůstky chybí nebo jich je jen pár dní, např. když je síť dočasně neposílá) z denních snímků
    // „Sledující celkem": poslední stav v období minus stav den před začátkem (nebo první den s daty).
    $snap = allstat_fetch_all($pdo, "
        SELECT metric_date, metric_value
        FROM provider_metrics_daily
        WHERE connection_id = ? AND dimension = '' AND metric_key = 'followers_total' AND metric_date BETWEEN ? AND ?
        ORDER BY metric_date ASC
    ", [$connectionId, (new DateTimeImmutable($start))->modify('-3 days')->format('Y-m-d'), $end]);
    if (count($snap) >= 2) {
        $base = $snap[0];
        foreach ($snap as $s) {
            if ((string) $s['metric_date'] < $start) {
                $base = $s; // poslední snímek před začátkem období
            }
        }
        $last = $snap[count($snap) - 1];
        $snapDays = (int) (new DateTimeImmutable((string) $base['metric_date']))->diff(new DateTimeImmutable((string) $last['metric_date']))->days;
        if ((string) $last['metric_date'] >= $start && ($snapDays > $eventDays || ($grossOnly && $snapDays >= (int) floor($periodDays * 0.8)))) {
            $net = (int) round((float) $last['metric_value'] - (float) $base['metric_value']);

            return ['new' => max(0, $net), 'lost' => max(0, -$net), 'net' => $net, 'source' => 'snapshot',
                'from' => (string) $base['metric_date'], 'to' => (string) $last['metric_date'], 'days' => $snapDays];
        }
    }
    if ($eventDays > 0) {
        return $events();
    }

    return ['new' => 0, 'lost' => 0, 'net' => 0, 'source' => 'none', 'from' => null, 'to' => null, 'days' => 0];
}

/**
 * Build a period-over-period delta block (change %, label, up/down/none) from two raw numbers.
 * null previous/current → 'none' (no comparison), matching the GA4 KPI card convention.
 */
function allstat_social_delta(?float $current, ?float $previous): array
{
    if ($current === null || $previous === null) {
        return ['trend' => 'none', 'label' => '—', 'change' => null];
    }
    $change = allstat_change($current, $previous);

    return [
        'change' => $change,
        'label' => allstat_change_label($change),
        'trend' => $change === null ? 'none' : ($change >= 0 ? 'up' : 'down'),
    ];
}

/**
 * Social posts analytics for one connection (FB page / IG account): top posts by engagement +
 * aggregate KPIs. Now also returns reach/impressions/clicks, engagement RATE (eng/reach), the
 * reaction-type breakdown, net follower change, a by-format breakdown, a weekday×hour posting
 * heatmap (best time to post) and period-over-period deltas. Stories are excluded from the counts.
 */
function allstat_get_social_posts(PDO $pdo, int $connectionId, string $start, string $end, int $limit = 5): array
{
    [$start, $end] = allstat_limited_range($start, $end);
    [$prevStart, $prevEnd] = allstat_previous_range($start, $end);

    $empty = [
        'hasData' => false, 'count' => 0, 'engagement' => 0, 'engagementLabel' => '0',
        'avgReactions' => 0, 'avgComments' => 0, 'avgShares' => 0, 'perWeek' => 0, 'top' => [], 'topSaved' => [],
        'saved' => 0, 'savedLabel' => '0', 'totalInteractions' => 0, 'avgSaved' => 0,
        'reach' => 0, 'impressions' => 0, 'clicks' => 0, 'videoViews' => 0, 'watchTimeSec' => 0, 'watchTimeLabel' => '—',
        'engRate' => null, 'engRateLabel' => '—', 'reactions' => ['like' => 0, 'love' => 0, 'haha' => 0, 'wow' => 0, 'sad' => 0, 'angry' => 0],
        'followers' => ['new' => 0, 'lost' => 0, 'net' => 0, 'source' => 'none', 'from' => null, 'to' => null, 'days' => 0],
        'deltas' => [], 'byFormat' => [], 'heatmap' => null,
    ];

    try {
        $cur = allstat_social_period_aggregate($pdo, $connectionId, $start, $end);
        $prev = allstat_social_period_aggregate($pdo, $connectionId, $prevStart, $prevEnd);
        $fol = allstat_social_followers($pdo, $connectionId, $start, $end);
        $folPrev = allstat_social_followers($pdo, $connectionId, $prevStart, $prevEnd);
        $topCols = "metric_date, published_at, message, permalink, post_type, post_format, reactions, comments, shares, saved, total_interactions, engagement, reach, impressions,
                   r_like, r_love, r_haha, r_wow, r_sad, r_angry, reactions_viral, comments_viral, post_clicks, link_clicks, clicks_json, fan_reach";
        $top = allstat_fetch_all($pdo, "
            SELECT $topCols
            FROM social_posts
            WHERE connection_id = ? AND post_type <> 'story' AND metric_date BETWEEN ? AND ?
            ORDER BY engagement DESC, metric_date DESC
            LIMIT " . max(1, $limit) . "
        ", [$connectionId, $start, $end]);
        // Top podle uložení (jen když nějaké uložení existuje — u FB/LinkedIn bude prázdné).
        $topSaved = allstat_fetch_all($pdo, "
            SELECT $topCols
            FROM social_posts
            WHERE connection_id = ? AND post_type <> 'story' AND metric_date BETWEEN ? AND ? AND saved > 0
            ORDER BY saved DESC, metric_date DESC
            LIMIT " . max(1, $limit) . "
        ", [$connectionId, $start, $end]);
        $formatRows = allstat_fetch_all($pdo, "
            SELECT post_format, COUNT(*) AS cnt, COALESCE(SUM(engagement),0) AS eng, COALESCE(SUM(reach),0) AS reach, COALESCE(SUM(impressions),0) AS views
            FROM social_posts
            WHERE connection_id = ? AND post_type <> 'story' AND metric_date BETWEEN ? AND ?
            GROUP BY post_format
            ORDER BY cnt DESC
        ", [$connectionId, $start, $end]);
        $heatRows = allstat_fetch_all($pdo, "
            SELECT WEEKDAY(published_at) AS wd, HOUR(published_at) AS hr, COUNT(*) AS cnt, COALESCE(SUM(engagement),0) AS eng
            FROM social_posts
            WHERE connection_id = ? AND post_type <> 'story' AND published_at IS NOT NULL AND metric_date BETWEEN ? AND ?
            GROUP BY WEEKDAY(published_at), HOUR(published_at)
        ", [$connectionId, $start, $end]);
    } catch (Throwable) {
        return $empty;
    }

    $count = $cur['count'];
    $days = max(1, (int) floor((strtotime($end) - strtotime($start)) / 86400) + 1);
    $weeks = max(1.0, $days / 7);
    $engRate = $cur['reach'] > 0 ? round($cur['eng'] / $cur['reach'] * 100, 1) : null;
    $engRatePrev = $prev['reach'] > 0 ? round($prev['eng'] / $prev['reach'] * 100, 1) : null;

    // Jeden mapper pro Top dle engagementu i Top dle uložení.
    $mapPost = static function (array $p): array {
        $msg = trim((string) $p['message']);
        if ($msg === '') { $msg = '(bez textu)'; }
        $reach = (int) $p['reach'];
        $eng = (int) $p['engagement'];
        $views = (int) ($p['impressions'] ?? 0);
        $saved = (int) ($p['saved'] ?? 0);

        return [
            'date' => allstat_social_post_date((string) ($p['published_at'] ?? ''), (string) $p['metric_date']),
            'message' => mb_strimwidth($msg, 0, 90, '…'),
            'permalink' => (string) $p['permalink'],
            'type' => (string) ($p['post_type'] ?? 'post'),
            'format' => (string) ($p['post_format'] ?? ''),
            'reactions' => (int) $p['reactions'],
            'comments' => (int) $p['comments'],
            'shares' => (int) $p['shares'],
            'saved' => $saved,
            'savedLabel' => $saved > 0 ? allstat_number($saved) : '—',
            'totalInteractions' => (int) ($p['total_interactions'] ?? 0),
            'engagement' => $eng,
            'engagementLabel' => allstat_number($eng),
            'reach' => $reach,
            'reachLabel' => $reach > 0 ? allstat_number($reach) : '—',
            'views' => $views,
            'viewsLabel' => $views > 0 ? allstat_number($views) : '—',
            'engRateLabel' => $reach > 0 ? allstat_percent(round($eng / $reach * 100, 1), 1) : '—',
            'reactionBreakdown' => allstat_social_reaction_breakdown($p),
            'detail' => allstat_social_post_detail($p),
        ];
    };

    return array_merge($empty, [
        'hasData' => $count > 0,
        'count' => $count,
        'engagement' => $cur['eng'],
        'engagementLabel' => allstat_number($cur['eng']),
        'avgReactions' => $count ? round($cur['rea'] / $count, 1) : 0,
        'avgComments' => $count ? round($cur['com'] / $count, 1) : 0,
        'avgShares' => $count ? round($cur['sha'] / $count, 1) : 0,
        'perWeek' => round($count / $weeks, 1),
        'reach' => $cur['reach'],
        'impressions' => $cur['impressions'],
        'clicks' => $cur['clicks'],
        'videoViews' => $cur['video'],
        'watchTimeSec' => $cur['watch'],
        'watchTimeLabel' => allstat_watch_time_label((int) $cur['watch']),
        'engRate' => $engRate,
        'engRateLabel' => $engRate === null ? '—' : allstat_percent($engRate, 1),
        'reactions' => $cur['reactions'],
        'followers' => $fol,
        'deltas' => [
            'count' => allstat_social_delta((float) $count, (float) $prev['count']),
            'engagement' => allstat_social_delta((float) $cur['eng'], (float) $prev['eng']),
            'reach' => allstat_social_delta($cur['reach'] > 0 ? (float) $cur['reach'] : null, $prev['reach'] > 0 ? (float) $prev['reach'] : null),
            'engRate' => allstat_social_delta($engRate, $engRatePrev),
            // Srovnávat jde jen stejně měřená a srovnatelně POKRYTÁ období. Metriky sledujících se do historie
            // doplňují postupně (backfill, u snapshotu vůbec ne), takže předchozí období bývá pokryté jen
            // zčásti nebo vůbec. Bez téhle pojistky vyleze „+6 400 %" ze srovnání 90 naměřených dní s jedním.
            'followers' => allstat_social_delta(...allstat_social_followers_delta_pair($fol, $folPrev)),
        ],
        'byFormat' => array_map(static function (array $r): array {
            $cnt = (int) $r['cnt'];
            $eng = (int) $r['eng'];

            return [
                'format' => (string) ($r['post_format'] ?? ''),
                'label' => allstat_social_format_label((string) ($r['post_format'] ?? '')),
                'count' => $cnt,
                'engagement' => $eng,
                'avgEngagement' => $cnt ? round($eng / $cnt, 1) : 0,
                'reach' => (int) $r['reach'],
                'views' => (int) $r['views'],
            ];
        }, $formatRows),
        'heatmap' => allstat_social_build_heatmap($heatRows),
        'saved' => $cur['saved'] ?? 0,
        'savedLabel' => allstat_number((int) ($cur['saved'] ?? 0)),
        'totalInteractions' => $cur['totalInteractions'] ?? 0,
        'avgSaved' => $count ? round(($cur['saved'] ?? 0) / $count, 1) : 0,
        'top' => array_map($mapPost, $top),
        'topSaved' => array_map($mapPost, $topSaved),
    ]);
}

/**
 * Per-post reaction breakdown for the tables: only the reaction types that actually occurred (>0),
 * each as [emoji, label, count], ordered by count desc. Empty array → no reactions to show.
 */
/**
 * Detail jednoho příspěvku v členění, jaké ukazuje Meta Business Suite (Přehled → rozpad interakcí →
 * prokliky). Vrací skupiny [nadpis, položky[label, value, hint]]; hodnoty jsou už naformátované.
 * Vědomě NEDOPOČÍTÁVÁ nic, co API nedá: chybějící metrika se ukáže jako „—", ne jako nula.
 */
function allstat_social_post_detail(array $row): array
{
    $n = static fn (mixed $v): string => ((int) $v) > 0 ? allstat_number((int) $v) : '—';
    $reach = (int) ($row['reach'] ?? 0);
    $views = (int) ($row['impressions'] ?? 0);
    $fanReach = (int) ($row['fan_reach'] ?? 0);
    $rea = (int) ($row['reactions'] ?? 0);
    $com = (int) ($row['comments'] ?? 0);
    $sha = (int) ($row['shares'] ?? 0);
    $saved = (int) ($row['saved'] ?? 0);
    $viral = (int) ($row['reactions_viral'] ?? 0);
    $comViral = (int) ($row['comments_viral'] ?? 0);

    $groups = [];
    $groups[] = ['title' => 'Přehled', 'items' => [
        ['label' => 'Zobrazení', 'value' => $n($views), 'hint' => 'Kolikrát se příspěvek ukázal, i opakovaně témuž člověku.'],
        ['label' => 'Diváci (dosah)', 'value' => $n($reach), 'hint' => 'Kolik různých lidí příspěvek vidělo.'],
        ['label' => 'Čistý počet interakcí', 'value' => $n($rea + $com + $sha), 'hint' => 'Reakce + komentáře + sdílení na tomto příspěvku.'],
        ['label' => 'Kliknutí na odkazy', 'value' => $n((int) ($row['link_clicks'] ?? 0)), 'hint' => 'Jen prokliky na odkaz, ne rozkliknutí fotky nebo textu.'],
    ]];
    $groups[] = ['title' => 'Interakce', 'items' => [
        ['label' => 'To se mi líbí a reakce', 'value' => $n($rea), 'hint' => 'Reakce přímo na tomto příspěvku, stejné číslo jako v Business Suite.'],
        ['label' => 'Komentáře', 'value' => $n($com), 'hint' => 'Komentáře pod tímto příspěvkem.'],
        ['label' => 'Sdílení', 'value' => $n($sha), 'hint' => 'Kolikrát lidé příspěvek sdíleli dál.'],
        ['label' => 'Uložení', 'value' => $n($saved), 'hint' => 'Facebook tuhle metriku pro stránky přes API nedává, proto bývá prázdná.'],
    ]];

    // Prokliky po typech (post_clicks_by_type) — ať je vidět, z čeho se skládá celkové číslo klikům.
    $clicks = [];
    $decoded = json_decode((string) ($row['clicks_json'] ?? ''), true);
    if (is_array($decoded)) {
        static $clickLabels = ['link clicks' => 'Kliknutí na odkaz', 'photo view' => 'Zobrazení fotky',
            'other clicks' => 'Ostatní kliknutí (rozbalení textu, jméno stránky)', 'video play' => 'Přehrání videa'];
        foreach ($decoded as $k => $v) {
            if ((int) $v <= 0) { continue; }
            $clicks[] = ['label' => $clickLabels[$k] ?? ucfirst((string) $k), 'value' => allstat_number((int) $v), 'hint' => ''];
        }
    }
    $clicks[] = ['label' => 'Kliknutí celkem', 'value' => $n((int) ($row['post_clicks'] ?? 0)), 'hint' => 'Součet všech typů kliknutí na příspěvek.'];
    $groups[] = ['title' => 'Kliknutí', 'items' => $clicks];

    // Doplňky, které Business Suite v tomhle panelu nemá, ale AllStat je umí: dosah mezi sledujícími
    // a širší „virální" počet interakcí včetně přesdílení (odtud plynul starý rozpor v číslech).
    $extra = [
        ['label' => 'Dosah mezi sledujícími', 'value' => $n($fanReach),
         'hint' => $reach > 0 && $fanReach > 0 ? 'Z celkového dosahu ' . allstat_number($reach) . ' tvoří sledující ' . allstat_percent(round($fanReach / $reach * 100, 1), 1) . '.' : 'Kolik zasažených lidí stránku už sleduje.'],
    ];
    if ($viral > $rea || $comViral > $com) {
        $extra[] = ['label' => 'Reakce včetně přesdílení', 'value' => $n($viral),
            'hint' => 'Facebook počítá i reakce na cizích sdíleních tohoto příspěvku. O ' . allstat_number(max(0, $viral - $rea)) . ' víc než na originálu; Business Suite ukazuje jen originál.'];
        $extra[] = ['label' => 'Komentáře včetně přesdílení', 'value' => $n($comViral), 'hint' => 'Totéž pro komentáře.'];
    }
    $groups[] = ['title' => 'Navíc oproti Business Suite', 'items' => $extra];

    return $groups;
}

function allstat_social_reaction_breakdown(array $row): array
{
    static $meta = [
        'r_like' => ['👍', 'To se mi líbí'], 'r_love' => ['❤️', 'Super'], 'r_haha' => ['😂', 'Haha'],
        'r_wow' => ['😮', 'Paráda'], 'r_sad' => ['😢', 'To mě mrzí'], 'r_angry' => ['😡', 'To mě štve'],
    ];
    $out = [];
    foreach ($meta as $key => [$emoji, $label]) {
        $n = (int) ($row[$key] ?? 0);
        if ($n > 0) {
            $out[] = ['emoji' => $emoji, 'label' => $label, 'count' => $n];
        }
    }
    usort($out, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

    return $out;
}

/**
 * Czech label for a post media format (photo/video/reel/link/status/share/album + IG image/carousel).
 */
function allstat_social_format_label(string $format): string
{
    return match ($format) {
        'photo', 'image' => 'Foto',
        'video' => 'Video',
        'reel' => 'Reel',
        'link' => 'Odkaz',
        'status' => 'Text',
        'share' => 'Sdílení',
        'album', 'carousel_album' => 'Album',
        'story' => 'Story',
        '' => 'Ostatní',
        default => mb_strtoupper(mb_substr($format, 0, 1)) . mb_substr($format, 1),
    };
}

/**
 * Format a post's display date: exact "d.m. H:i" when published_at is known, else date-only "d.m.".
 */
function allstat_social_post_date(string $publishedAt, string $metricDate): string
{
    if ($publishedAt !== '' && $publishedAt !== '0000-00-00 00:00:00') {
        try {
            return (new DateTimeImmutable($publishedAt))->format('d.m. H:i');
        } catch (Throwable) { /* fall through to date-only */ }
    }
    try {
        return (new DateTimeImmutable(substr($metricDate, 0, 10)))->format('d.m.');
    } catch (Throwable) {
        return $metricDate;
    }
}

/**
 * Turn the weekday×hour rows into a 7×24 grid (Mon..Sun) + the single best slot by avg engagement
 * (among slots that actually have posts). Hours are bucketed into 6 columns of 4h for a compact UI.
 */
function allstat_social_build_heatmap(array $rows): ?array
{
    if (!$rows) {
        return null;
    }
    $buckets = ['0–4', '4–8', '8–12', '12–16', '16–20', '20–24'];
    $grid = [];
    for ($w = 0; $w < 7; $w++) {
        $grid[$w] = array_fill(0, 6, ['count' => 0, 'eng' => 0]);
    }
    $total = 0;
    $best = null;
    foreach ($rows as $r) {
        $w = (int) $r['wd'];
        $b = intdiv((int) $r['hr'], 4);
        if ($w < 0 || $w > 6 || $b < 0 || $b > 5) { continue; }
        $grid[$w][$b]['count'] += (int) $r['cnt'];
        $grid[$w][$b]['eng'] += (int) $r['eng'];
        $total += (int) $r['cnt'];
    }
    $maxAvg = 0.0;
    $days = ['Po', 'Út', 'St', 'Čt', 'Pá', 'So', 'Ne'];
    $slots = [];
    for ($w = 0; $w < 7; $w++) {
        for ($b = 0; $b < 6; $b++) {
            $cell = $grid[$w][$b];
            $avg = $cell['count'] ? $cell['eng'] / $cell['count'] : 0;
            $grid[$w][$b]['avg'] = round($avg, 1);
            if ($cell['count'] > 0) {
                $maxAvg = max($maxAvg, $avg);
                $slots[] = ['day' => $days[$w], 'slot' => $buckets[$b], 'avg' => round($avg, 1), 'count' => $cell['count']];
            }
        }
    }
    // Žebříček oken: řadí se podle Ø engagementu, ale sloty s JEDNÍM příspěvkem až za vícekrát ověřené —
    // jeden šťastný post nemá přebít okno potvrzené třemi. lowSample zůstává vidět (UI ho označí).
    usort($slots, static function (array $a, array $b): int {
        $aMulti = $a['count'] >= 2 ? 1 : 0;
        $bMulti = $b['count'] >= 2 ? 1 : 0;
        return $bMulti <=> $aMulti ?: $b['avg'] <=> $a['avg'];
    });
    foreach ($slots as $i => $s) {
        $slots[$i]['score'] = $maxAvg > 0 ? (int) round($s['avg'] / $maxAvg * 100) : 0;
        $slots[$i]['lowSample'] = $s['count'] < 2;
    }
    $best = $slots[0] ?? null;

    return ['days' => $days, 'buckets' => $buckets, 'grid' => $grid, 'maxAvg' => $maxAvg, 'best' => $best,
        'top' => array_slice($slots, 0, 3), 'total' => $total];
}

/**
 * Paginated full list of posts for one connection (newest first) — for the "Zobrazit více" expander
 * under the Top-5. Returns formatted rows + pagination meta (15 per page by default).
 */
function allstat_get_social_posts_page(PDO $pdo, int $connectionId, string $start, string $end, int $page = 1, int $perPage = 15): array
{
    [$start, $end] = allstat_limited_range($start, $end);
    $perPage = max(1, min(100, $perPage));
    $page = max(1, $page);
    try {
        $total = (int) (allstat_fetch_one($pdo, "
            SELECT COUNT(*) AS c FROM social_posts WHERE connection_id = ? AND post_type <> 'story' AND metric_date BETWEEN ? AND ?
        ", [$connectionId, $start, $end])['c'] ?? 0);
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) { $page = $totalPages; }
        $offset = ($page - 1) * $perPage;
        $rows = allstat_fetch_all($pdo, "
            SELECT metric_date, published_at, message, permalink, post_type, post_format, reactions, comments, shares, saved, total_interactions, engagement, reach, impressions,
                   r_like, r_love, r_haha, r_wow, r_sad, r_angry, reactions_viral, comments_viral, post_clicks, link_clicks, clicks_json, fan_reach
            FROM social_posts
            WHERE connection_id = ? AND post_type <> 'story' AND metric_date BETWEEN ? AND ?
            ORDER BY published_at DESC, metric_date DESC, engagement DESC
            LIMIT $perPage OFFSET $offset
        ", [$connectionId, $start, $end]);
    } catch (Throwable) {
        return ['rows' => [], 'total' => 0, 'page' => 1, 'totalPages' => 1, 'perPage' => $perPage];
    }

    return [
        'total' => $total,
        'page' => $page,
        'totalPages' => $totalPages,
        'perPage' => $perPage,
        'rows' => array_map(static function (array $p): array {
            $msg = trim((string) $p['message']);
            if ($msg === '') { $msg = '(bez textu)'; }
            $reach = (int) $p['reach'];
            $eng = (int) $p['engagement'];
            $views = (int) ($p['impressions'] ?? 0);

            return [
                'date' => allstat_social_post_date((string) ($p['published_at'] ?? ''), (string) $p['metric_date']),
                'message' => mb_strimwidth($msg, 0, 140, '…'),
                'permalink' => (string) $p['permalink'],
                'type' => (string) ($p['post_type'] ?? 'post'),
                'format' => (string) ($p['post_format'] ?? ''),
                'reactions' => (int) $p['reactions'],
                'comments' => (int) $p['comments'],
                'shares' => (int) $p['shares'],
                'saved' => (int) ($p['saved'] ?? 0),
                'savedLabel' => ((int) ($p['saved'] ?? 0)) > 0 ? allstat_number((int) $p['saved']) : '—',
                'totalInteractions' => (int) ($p['total_interactions'] ?? 0),
                'engagement' => $eng,
                'engagementLabel' => allstat_number($eng),
                'reach' => $reach,
                'reachLabel' => $reach > 0 ? allstat_number($reach) : '—',
                'views' => $views,
                'viewsLabel' => $views > 0 ? allstat_number($views) : '—',
                'engRateLabel' => $reach > 0 ? allstat_percent(round($eng / $reach * 100, 1), 1) : '—',
                'reactionBreakdown' => allstat_social_reaction_breakdown($p),
                'detail' => allstat_social_post_detail($p),
            ];
        }, $rows),
    ];
}

/** IG pohlaví F/M/U → český popisek. */
function allstat_ig_gender_label(string $g): string
{
    return ['F' => 'Ženy', 'M' => 'Muži', 'U' => 'Neuvedeno'][strtoupper($g)] ?? $g;
}

/** ISO kód země → český název (fallback = kód). */
function allstat_ig_country_label(string $iso): string
{
    static $m = [
        'CZ' => 'Česko', 'SK' => 'Slovensko', 'DE' => 'Německo', 'AT' => 'Rakousko', 'PL' => 'Polsko',
        'GB' => 'Velká Británie', 'US' => 'USA', 'UA' => 'Ukrajina', 'ES' => 'Španělsko', 'IT' => 'Itálie',
        'FR' => 'Francie', 'NL' => 'Nizozemsko', 'HU' => 'Maďarsko', 'CH' => 'Švýcarsko', 'IE' => 'Irsko',
        'BE' => 'Belgie', 'SE' => 'Švédsko', 'DK' => 'Dánsko', 'PT' => 'Portugalsko', 'RU' => 'Rusko',
        'CA' => 'Kanada', 'HR' => 'Chorvatsko', 'RS' => 'Srbsko', 'RO' => 'Rumunsko', 'BG' => 'Bulharsko',
        'GR' => 'Řecko', 'NO' => 'Norsko', 'FI' => 'Finsko', 'VN' => 'Vietnam', 'SI' => 'Slovinsko',
    ];
    return $m[strtoupper($iso)] ?? $iso;
}

/**
 * Demografie sledujících IG (věk / pohlaví / země / město) = poslední uložený snímek
 * (follower_demographics je lifetime, ukládá se na datum syncu). Vrací per breakdown seřazené
 * sestupně s podílem v %. Z dimenzovaných řádků provider_metrics_daily (dem_age/gender/country/city).
 */
function allstat_get_ig_demographics(PDO $pdo, int $connectionId): array
{
    $out = ['age' => [], 'gender' => [], 'country' => [], 'city' => [], 'date' => '', 'hasData' => false];
    try {
        foreach (['dem_age' => 'age', 'dem_gender' => 'gender', 'dem_country' => 'country', 'dem_city' => 'city'] as $key => $slot) {
            $d = allstat_fetch_one($pdo, "SELECT MAX(metric_date) AS d FROM provider_metrics_daily WHERE connection_id = ? AND metric_key = ?", [$connectionId, $key])['d'] ?? null;
            if (!$d) { continue; }
            $rows = allstat_fetch_all($pdo, "SELECT dimension, metric_value FROM provider_metrics_daily
                WHERE connection_id = ? AND metric_key = ? AND metric_date = ? AND dimension <> '' ORDER BY metric_value DESC", [$connectionId, $key, $d]);
            $total = array_sum(array_map(static fn ($r) => (float) $r['metric_value'], $rows));
            foreach ($rows as $r) {
                // float, ne int — FB demografie z CSV jsou procenta s desetinami (2.8 %), IG jsou celé počty.
                $v = (float) $r['metric_value'];
                $label = match ($slot) {
                    'gender' => allstat_ig_gender_label((string) $r['dimension']),
                    'country' => allstat_ig_country_label((string) $r['dimension']),
                    'city' => trim(explode(',', (string) $r['dimension'])[0]),
                    default => (string) $r['dimension'],
                };
                $out[$slot][] = ['label' => $label, 'value' => $v, 'share' => $total > 0 ? $v / $total * 100 : 0];
            }
            $out['date'] = (string) $d;
        }
        $out['hasData'] = $out['age'] || $out['gender'] || $out['country'];
    } catch (Throwable) { /* zdroj demografie zatím nemá data */ }
    return $out;
}

/**
 * LinkedIn demografie sledujících (Fáze C) — celoživotní snapshot z organizationalEntityFollowerStatistics,
 * uložený jako dimension řádky (foll_seniority/foll_function/foll_staff/foll_industry/foll_country). Čte se
 * POSLEDNÍ snapshot per facet (jako IG demografie), share % se počítá z plného součtu facetu, zobrazí se top 12.
 */
function allstat_get_linkedin_follower_demographics(PDO $pdo, int $connectionId): array
{
    $out = ['seniority' => [], 'function' => [], 'staff' => [], 'industry' => [], 'country' => [], 'region' => [], 'date' => '', 'dates' => [], 'hasData' => false];
    $map = ['foll_seniority' => 'seniority', 'foll_function' => 'function', 'foll_staff' => 'staff', 'foll_industry' => 'industry', 'foll_country' => 'country', 'foll_region' => 'region'];
    try {
        foreach ($map as $key => $slot) {
            $d = allstat_fetch_one($pdo, "SELECT MAX(metric_date) AS d FROM provider_metrics_daily WHERE connection_id = ? AND metric_key = ?", [$connectionId, $key])['d'] ?? null;
            if (!$d) { continue; }
            $rows = allstat_fetch_all($pdo, "SELECT dimension, metric_value FROM provider_metrics_daily
                WHERE connection_id = ? AND metric_key = ? AND metric_date = ? AND dimension <> '' ORDER BY metric_value DESC", [$connectionId, $key, $d]);
            $total = array_sum(array_map(static fn ($r) => (float) $r['metric_value'], $rows));
            foreach (array_slice($rows, 0, 12) as $r) {
                $v = (float) $r['metric_value'];
                $out[$slot][] = ['label' => (string) $r['dimension'], 'value' => $v, 'share' => $total > 0 ? $v / $total * 100 : 0];
            }
            // Datum per facet: seniorita/funkce/velikost se obnovují každou noc, ale obor/země/lokalita
            // závisí na URN resolveru s vlastním denním limitem (429) a umí zamrznout na starším snímku.
            $out['dates'][$slot] = (string) $d;
            if ((string) $d > $out['date']) { $out['date'] = (string) $d; }
        }
        $out['hasData'] = $out['seniority'] || $out['function'] || $out['industry'] || $out['country'] || $out['region'] || $out['staff'];
    } catch (Throwable) { /* demografie zatím bez dat */ }
    return $out;
}

/**
 * LinkedIn složení návštěvníků stránky (Fáze C) — lifetime snapshot z organizationPageStatistics, uložený jako
 * pv_* dimension řádky (pv_function/pv_seniority/pv_staff/pv_industry/pv_region). Stejný tvar jako demografie
 * sledujících (poslední snapshot, share z plného součtu, top 12).
 */
function allstat_get_linkedin_visitor_demographics(PDO $pdo, int $connectionId): array
{
    $out = ['function' => [], 'seniority' => [], 'staff' => [], 'industry' => [], 'region' => [], 'date' => '', 'dates' => [], 'hasData' => false];
    $map = ['pv_function' => 'function', 'pv_seniority' => 'seniority', 'pv_staff' => 'staff', 'pv_industry' => 'industry', 'pv_region' => 'region'];
    try {
        foreach ($map as $key => $slot) {
            $d = allstat_fetch_one($pdo, "SELECT MAX(metric_date) AS d FROM provider_metrics_daily WHERE connection_id = ? AND metric_key = ?", [$connectionId, $key])['d'] ?? null;
            if (!$d) { continue; }
            $rows = allstat_fetch_all($pdo, "SELECT dimension, metric_value FROM provider_metrics_daily
                WHERE connection_id = ? AND metric_key = ? AND metric_date = ? AND dimension <> '' ORDER BY metric_value DESC", [$connectionId, $key, $d]);
            $total = array_sum(array_map(static fn ($r) => (float) $r['metric_value'], $rows));
            foreach (array_slice($rows, 0, 12) as $r) {
                $v = (float) $r['metric_value'];
                $out[$slot][] = ['label' => (string) $r['dimension'], 'value' => $v, 'share' => $total > 0 ? $v / $total * 100 : 0];
            }
            $out['dates'][$slot] = (string) $d;
            if ((string) $d > $out['date']) { $out['date'] = (string) $d; }
        }
        $out['hasData'] = $out['function'] || $out['seniority'] || $out['industry'] || $out['region'] || $out['staff'];
    } catch (Throwable) { /* složení návštěvníků zatím bez dat */ }
    return $out;
}

/**
 * LinkedIn rozpad reakcí podle typu (Fáze C) za období — sečte li_reaction dimension řádky (socialMetadata)
 * přes [start,end] podle typu reakce (To se mi líbí / Gratuluji / Podpora / Zajímavé…), share % z celku.
 */
function allstat_get_linkedin_reactions(PDO $pdo, int $connectionId, string $start, string $end): array
{
    $out = ['rows' => [], 'total' => 0, 'hasData' => false];
    try {
        $rows = allstat_fetch_all($pdo, "SELECT dimension, SUM(metric_value) AS v FROM provider_metrics_daily
            WHERE connection_id = ? AND metric_key = 'li_reaction' AND dimension <> '' AND metric_date BETWEEN ? AND ?
            GROUP BY dimension ORDER BY v DESC", [$connectionId, $start, $end]);
        $total = array_sum(array_map(static fn ($r) => (float) $r['v'], $rows));
        foreach ($rows as $r) {
            $v = (float) $r['v'];
            $out['rows'][] = ['label' => (string) $r['dimension'], 'value' => $v, 'share' => $total > 0 ? $v / $total * 100 : 0];
        }
        $out['total'] = (int) round($total);
        $out['hasData'] = $total > 0;
    } catch (Throwable) { /* reakce zatím bez dat */ }
    return $out;
}

/**
 * Okruh uživatelů IG = dosah a zobrazení v rozpadu sledující / nesledující, sečteno přes období
 * (reach_follow / views_follow, dimension FOLLOWER/NON_FOLLOWER/UNKNOWN).
 */
function allstat_get_ig_audience(PDO $pdo, int $connectionId, string $start, string $end): array
{
    $out = ['reach' => ['follower' => 0, 'nonFollower' => 0, 'unknown' => 0], 'views' => ['follower' => 0, 'nonFollower' => 0, 'unknown' => 0], 'hasData' => false];
    try {
        $rows = allstat_fetch_all($pdo, "SELECT metric_key, dimension, SUM(metric_value) AS v FROM provider_metrics_daily
            WHERE connection_id = ? AND metric_key IN ('reach_follow','views_follow') AND metric_date BETWEEN ? AND ?
            GROUP BY metric_key, dimension", [$connectionId, $start, $end]);
        $map = ['FOLLOWER' => 'follower', 'NON_FOLLOWER' => 'nonFollower', 'UNKNOWN' => 'unknown'];
        foreach ($rows as $r) {
            $slot = $r['metric_key'] === 'reach_follow' ? 'reach' : 'views';
            $dim = $map[strtoupper((string) $r['dimension'])] ?? null;
            if ($dim !== null) { $out[$slot][$dim] = (int) $r['v']; }
        }
        $out['hasData'] = array_sum($out['reach']) > 0 || array_sum($out['views']) > 0;
    } catch (Throwable) { /* okruh zatím bez dat */ }
    return $out;
}

/**
 * Captured Stories for one connection. Stories are ephemeral (~24 h) and FB's API only returns
 * CURRENTLY-ACTIVE ones, so this only ever holds stories a sync happened to run while they were live —
 * hence they're kept out of the post count/frequency and shown in their own honest little section.
 */
function allstat_get_social_stories(PDO $pdo, int $connectionId, string $start, string $end, int $limit = 20): array
{
    [$start, $end] = allstat_limited_range($start, $end);
    try {
        $count = (int) (allstat_fetch_one($pdo, "
            SELECT COUNT(*) c FROM social_posts WHERE connection_id = ? AND post_type = 'story' AND metric_date BETWEEN ? AND ?
        ", [$connectionId, $start, $end])['c'] ?? 0);
        $rows = allstat_fetch_all($pdo, "
            SELECT metric_date, published_at, message, permalink, reach, impressions, story_replies, story_navigation
            FROM social_posts
            WHERE connection_id = ? AND post_type = 'story' AND metric_date BETWEEN ? AND ?
            ORDER BY published_at DESC, metric_date DESC
            LIMIT " . max(1, $limit) . "
        ", [$connectionId, $start, $end]);
        // Souhrn za období (přes všechny zachycené stories, ne jen zobrazený limit).
        $sum = allstat_fetch_one($pdo, "
            SELECT COALESCE(SUM(reach),0) reach, COALESCE(SUM(impressions),0) views,
                   COALESCE(SUM(story_replies),0) replies, COALESCE(SUM(story_navigation),0) navigation
            FROM social_posts WHERE connection_id = ? AND post_type = 'story' AND metric_date BETWEEN ? AND ?
        ", [$connectionId, $start, $end]) ?? [];
    } catch (Throwable) {
        return ['count' => 0, 'rows' => [], 'totals' => ['reach' => 0, 'views' => 0, 'replies' => 0, 'navigation' => 0, 'hasMetrics' => false]];
    }

    $totReach = (int) ($sum['reach'] ?? 0); $totViews = (int) ($sum['views'] ?? 0);
    $totReplies = (int) ($sum['replies'] ?? 0); $totNav = (int) ($sum['navigation'] ?? 0);

    return [
        'count' => $count,
        'totals' => [
            'reach' => $totReach, 'views' => $totViews, 'replies' => $totReplies, 'navigation' => $totNav,
            'reachLabel' => allstat_number($totReach), 'viewsLabel' => allstat_number($totViews),
            'repliesLabel' => allstat_number($totReplies), 'navigationLabel' => allstat_number($totNav),
            'hasMetrics' => ($totReach + $totViews + $totReplies + $totNav) > 0,
        ],
        'rows' => array_map(static function (array $p): array {
            $msg = trim((string) $p['message']);
            $reach = (int) ($p['reach'] ?? 0); $views = (int) ($p['impressions'] ?? 0);

            return [
                'date' => allstat_social_post_date((string) ($p['published_at'] ?? ''), (string) $p['metric_date']),
                'message' => mb_strimwidth($msg !== '' ? $msg : '(Story)', 0, 70, '…'),
                'permalink' => (string) $p['permalink'],
                'reachLabel' => $reach > 0 ? allstat_number($reach) : '—',
                'viewsLabel' => $views > 0 ? allstat_number($views) : '—',
                'replies' => (int) ($p['story_replies'] ?? 0),
                'repliesLabel' => ((int) ($p['story_replies'] ?? 0)) > 0 ? allstat_number((int) $p['story_replies']) : '—',
                'navigationLabel' => ((int) ($p['story_navigation'] ?? 0)) > 0 ? allstat_number((int) $p['story_navigation']) : '—',
            ];
        }, $rows),
    ];
}

function allstat_query_search_queries(PDO $pdo, int $domainId, string $start, string $end, int $limit = 5): array
{
    $rows = allstat_fetch_all($pdo, '
        SELECT
            query_text,
            SUM(clicks) AS clicks,
            SUM(impressions) AS impressions,
            -- Pozice vážená zobrazeními (jako Search Console a stránky v allstat_query_gsc_pages), ne prostý průměr dní.
            CASE WHEN SUM(impressions) > 0 THEN SUM(position * impressions) / SUM(impressions) ELSE AVG(position) END AS position
        FROM search_queries_daily
        WHERE domain_id = ? AND metric_date BETWEEN ? AND ?
        GROUP BY query_text
        ORDER BY clicks DESC
        LIMIT ' . max(1, $limit) . '
    ', [$domainId, $start, $end]);

    return array_map(static function (array $row): array {
        $clicks = (int) $row['clicks'];
        $impressions = (int) $row['impressions'];
        $ctr = $impressions > 0 ? ($clicks / $impressions) * 100 : 0;

        return [
            'query' => $row['query_text'],
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $ctr,
            'ctrLabel' => allstat_percent($ctr, 1),
            'position' => (float) $row['position'],
        ];
    }, $rows);
}

function allstat_query_source_statuses(PDO $pdo, int $domainId): array
{
    $rows = allstat_fetch_all($pdo, '
        SELECT ds.name, ds.category, src.status, src.last_sync_at, src.token_expires_at, src.note, src.is_enabled
        FROM domain_sources src
        INNER JOIN data_sources ds ON ds.id = src.source_id
        WHERE src.domain_id = ?
        ORDER BY ds.id ASC
    ', [$domainId]);

    return array_map(static fn (array $row): array => [
        'source' => $row['name'],
        'category' => $row['category'],
        'status' => $row['status'],
        'lastSync' => allstat_iso_to_cz($row['last_sync_at']),
        // Syrove datum + priznak zapnuti drzime kvuli detekci zaseknuteho zdroje (viz allstat_sync_health).
        'lastSyncRaw' => $row['last_sync_at'],
        'enabled' => (int) ($row['is_enabled'] ?? 1) === 1,
        'tokenExpires' => $row['token_expires_at'] ? allstat_iso_to_cz($row['token_expires_at']) : null,
        'note' => $row['note'] ?? '',
    ], $rows);
}

/**
 * Connected providers for a domain (for the dashboard source switcher), one row PER CONNECTION.
 * `connection_id` is domain_sources.id (= provider_metrics_daily.connection_id) — the switcher value.
 * Two connections of the same provider (e.g. two Facebook pages) appear as two distinct entries,
 * labelled by their account name and grouped by category, so they stay clearly separated.
 */
/**
 * České labely zdrojů návštěvnosti YouTube (insightTrafficSourceType). Neznámé hodnoty projdou beze změny.
 */
function allstat_youtube_traffic_label(string $type): string
{
    static $map = [
        'YT_SEARCH' => 'Vyhledávání YouTube', 'SUGGESTED' => 'Navrhovaná videa', 'RELATED_VIDEO' => 'Navrhovaná videa',
        'EXT_URL' => 'Externí weby a aplikace', 'EXTERNAL' => 'Externí weby a aplikace', 'NO_LINK_OTHER' => 'Přímý přístup / neznámé',
        'NO_LINK_EMBEDDED' => 'Vložený přehrávač (embed)', 'YT_CHANNEL' => 'Stránka kanálu', 'SUBSCRIBER' => 'Odběratelé (kanál Odebíráno / domů)',
        'PLAYLIST' => 'Playlisty', 'YT_PLAYLIST_PAGE' => 'Stránka playlistu', 'NOTIFICATION' => 'Notifikace', 'SHORTS' => 'Shorts feed',
        'ADVERTISING' => 'Reklama', 'PROMOTED' => 'Propagovaný obsah', 'END_SCREEN' => 'Závěrečná obrazovka', 'ANNOTATION' => 'Anotace / karty',
        'CAMPAIGN_CARD' => 'Karta kampaně', 'HASHTAGS' => 'Hashtagy', 'YT_OTHER_PAGE' => 'Jiné stránky YouTube', 'SOUND_PAGE' => 'Stránka zvuku',
        'PRODUCT_PAGE' => 'Stránka produktu', 'VIDEO_REMIXES' => 'Remixy videa', 'LIVE_REDIRECT' => 'Přesměrování z živého vysílání',
        'IMMERSIVE_LIVE' => 'Živé vysílání (feed)', 'YT_SEARCH_SHORTS' => 'Vyhledávání Shorts',
    ];

    return $map[$type] ?? $type;
}

/**
 * Videa YouTube kanálu ze social_posts (network='youtube'). Hodnoty u videí jsou CELOŽIVOTNÍ stav (jako u
 * FB/IG příspěvků); „za období" = videa PUBLIKOVANÁ v období. Vrací souhrn za období + Top videa za období
 * (podle zhlédnutí) + Top videa celkově (všechna, nezávisle na období) + rozpad podle formátu.
 */
function allstat_get_youtube_videos(PDO $pdo, int $connectionId, string $start, string $end, int $limit = 10): array
{
    [$start, $end] = allstat_limited_range($start, $end);
    $empty = ['hasData' => false, 'count' => 0, 'views' => 0, 'watchSec' => 0, 'watchLabel' => '—', 'avgSec' => 0, 'avgLabel' => '—',
        'likes' => 0, 'comments' => 0, 'shares' => 0, 'subs' => 0, 'engRateLabel' => '—', 'top' => [], 'allTime' => [], 'allTimeCount' => 0, 'byFormat' => []];
    $cols = 'metric_date, published_at, message, permalink, post_format, reactions, comments, shares, impressions, watch_time_sec, stats_json';

    try {
        $sum = allstat_fetch_one($pdo, "
            SELECT COUNT(*) AS cnt, COALESCE(SUM(impressions),0) AS views, COALESCE(SUM(watch_time_sec),0) AS watch,
                   COALESCE(SUM(reactions),0) AS likes, COALESCE(SUM(comments),0) AS comments, COALESCE(SUM(shares),0) AS shares
            FROM social_posts WHERE connection_id = ? AND network = 'youtube' AND metric_date BETWEEN ? AND ?
        ", [$connectionId, $start, $end]) ?? [];
        $top = allstat_fetch_all($pdo, "SELECT $cols FROM social_posts WHERE connection_id = ? AND network = 'youtube' AND metric_date BETWEEN ? AND ?
            ORDER BY impressions DESC, metric_date DESC LIMIT " . max(1, $limit), [$connectionId, $start, $end]);
        $allTime = allstat_fetch_all($pdo, "SELECT $cols FROM social_posts WHERE connection_id = ? AND network = 'youtube'
            ORDER BY impressions DESC, metric_date DESC LIMIT " . max(1, $limit), [$connectionId]);
        $allCount = (int) (allstat_fetch_one($pdo, "SELECT COUNT(*) AS c FROM social_posts WHERE connection_id = ? AND network = 'youtube'", [$connectionId])['c'] ?? 0);
        $formats = allstat_fetch_all($pdo, "
            SELECT post_format, COUNT(*) AS cnt, COALESCE(SUM(impressions),0) AS views, COALESCE(SUM(watch_time_sec),0) AS watch,
                   COALESCE(SUM(reactions)+SUM(comments)+SUM(shares),0) AS eng
            FROM social_posts WHERE connection_id = ? AND network = 'youtube' AND metric_date BETWEEN ? AND ?
            GROUP BY post_format ORDER BY cnt DESC
        ", [$connectionId, $start, $end]);
    } catch (Throwable) {
        return $empty;
    }

    $map = 'allstat_youtube_map_video';

    $count = (int) ($sum['cnt'] ?? 0);
    $views = (int) ($sum['views'] ?? 0);
    $watch = (int) ($sum['watch'] ?? 0);
    $eng = (int) ($sum['likes'] ?? 0) + (int) ($sum['comments'] ?? 0) + (int) ($sum['shares'] ?? 0);
    $topMapped = array_map($map, $top);

    return array_merge($empty, [
        'hasData' => $count > 0 || $allCount > 0,
        'count' => $count,
        'views' => $views,
        'watchSec' => $watch,
        'watchLabel' => allstat_watch_time_label($watch),
        'avgSec' => $views > 0 ? (int) round($watch / $views) : 0,
        'avgLabel' => $views > 0 ? allstat_duration_label($watch / $views) : '—',
        'likes' => (int) ($sum['likes'] ?? 0),
        'comments' => (int) ($sum['comments'] ?? 0),
        'shares' => (int) ($sum['shares'] ?? 0),
        'subs' => array_sum(array_column($topMapped, 'subs')),
        'engRateLabel' => $views > 0 ? allstat_percent(round($eng / $views * 100, 2), 2) : '—',
        'top' => $topMapped,
        'allTime' => array_map($map, $allTime),
        'allTimeCount' => $allCount,
        'byFormat' => array_map(static fn (array $f): array => [
            'format' => (string) $f['post_format'],
            'label' => match ((string) $f['post_format']) { 'short' => 'Shorts', 'live' => 'Živá vysílání', default => 'Videa' },
            'count' => (int) $f['cnt'],
            'views' => (int) $f['views'],
            'avgViews' => (int) $f['cnt'] > 0 ? (int) round((int) $f['views'] / (int) $f['cnt']) : 0,
            'watchLabel' => allstat_watch_time_label((int) $f['watch']),
            'engagement' => (int) $f['eng'],
        ], $formats),
    ]);
}

/**
 * Řádek social_posts (network='youtube') → model videa pro view/export (labely už naformátované).
 */
function allstat_youtube_map_video(array $r): array
{
    $st = json_decode((string) ($r['stats_json'] ?? ''), true);
    $st = is_array($st) ? $st : [];
    $views = (int) $r['impressions'];
    $watch = (int) $r['watch_time_sec'];
    $eng = (int) $r['reactions'] + (int) $r['comments'] + (int) $r['shares'];
    $dur = (int) ($st['dur'] ?? 0);
    $title = trim((string) $r['message']);

    return [
        'id' => (string) ($r['post_id'] ?? ''),
        'title' => $title !== '' ? $title : '(bez názvu)',
        'permalink' => (string) $r['permalink'],
        'date' => allstat_social_post_date((string) ($r['published_at'] ?? ''), (string) $r['metric_date']),
        'metricDate' => (string) $r['metric_date'],
        'format' => (string) $r['post_format'],
        'formatLabel' => match ((string) $r['post_format']) { 'short' => 'Short', 'live' => 'Živě', default => 'Video' },
        'durationLabel' => $dur > 0 ? allstat_watch_time_label($dur) : '—',
        'views' => $views,
        'viewsLabel' => allstat_number($views),
        'watchSec' => $watch,
        'watchLabel' => allstat_watch_time_label($watch),
        'avgSec' => (int) ($st['avg_sec'] ?? 0),
        'avgLabel' => (int) ($st['avg_sec'] ?? 0) > 0 ? allstat_duration_label((int) $st['avg_sec']) : '—',
        'avgPct' => (float) ($st['avg_pct'] ?? 0),
        'avgPctLabel' => (float) ($st['avg_pct'] ?? 0) > 0 ? allstat_percent((float) $st['avg_pct'], 1) : '—',
        'likes' => (int) $r['reactions'],
        'comments' => (int) $r['comments'],
        'shares' => (int) $r['shares'],
        'subs' => (int) ($st['subs'] ?? 0),
        'engagement' => $eng,
        'engRateLabel' => $views > 0 ? allstat_percent(round($eng / $views * 100, 2), 2) : '—',
        'thumb' => (string) ($st['thumb'] ?? ''),
        'playlists' => [],
    ];
}

/**
 * Kompletní katalog videí kanálu (všechna uložená videa, nezávisle na období) seskupený podle playlistů
 * kanálu (social_collections). Video může být ve více playlistech (objeví se v každém); videa mimo playlisty
 * jdou do skupiny „Nezařazená". Každá skupina nese součty (videí, zhlédnutí, sledovaný čas, Ø na video,
 * Ø doba zhlédnutí, engagement) → srovnání kategorií.
 */
function allstat_get_youtube_catalog(PDO $pdo, int $connectionId): array
{
    $empty = ['hasData' => false, 'count' => 0, 'videos' => [], 'groups' => [], 'playlistCount' => 0, 'unassignedCount' => 0, 'multiCount' => 0];
    try {
        $rows = allstat_fetch_all($pdo, "SELECT post_id, metric_date, published_at, message, permalink, post_format, reactions, comments, shares, impressions, watch_time_sec, stats_json
            FROM social_posts WHERE connection_id = ? AND network = 'youtube' ORDER BY metric_date DESC, published_at DESC", [$connectionId]);
        $members = allstat_fetch_all($pdo, "SELECT collection_id, title, post_id, position FROM social_collections WHERE connection_id = ? ORDER BY title ASC, position ASC", [$connectionId]);
    } catch (Throwable) {
        return $empty;
    }
    if (!$rows) {
        return $empty;
    }

    $videos = [];
    foreach ($rows as $r) {
        $v = allstat_youtube_map_video($r);
        $videos[$v['id']] = $v;
    }
    $groups = [];
    $inAny = [];
    foreach ($members as $m) {
        $vid = (string) $m['post_id'];
        if (!isset($videos[$vid])) { continue; }
        $pid = (string) $m['collection_id'];
        $groups[$pid] ??= ['id' => $pid, 'title' => (string) $m['title'], 'videos' => []];
        $groups[$pid]['videos'][] = $vid;
        $videos[$vid]['playlists'][] = (string) $m['title'];
        $inAny[$vid] = ($inAny[$vid] ?? 0) + 1;
    }
    $unassigned = array_keys(array_diff_key($videos, $inAny));
    if ($unassigned) {
        $groups['_none'] = ['id' => '', 'title' => 'Nezařazená do playlistu', 'videos' => $unassigned];
    }

    $summarize = static function (array $g) use ($videos): array {
        $list = array_values(array_map(static fn (string $vid): array => $videos[$vid], $g['videos']));
        usort($list, static fn (array $a, array $b): int => strcmp($b['metricDate'], $a['metricDate']));
        $views = array_sum(array_column($list, 'views'));
        $watch = array_sum(array_column($list, 'watchSec'));
        $eng = array_sum(array_column($list, 'engagement'));
        $n = count($list);
        $avgPct = $n > 0 ? array_sum(array_column($list, 'avgPct')) / $n : 0.0;

        return $g + [
            'count' => $n,
            'views' => $views,
            'viewsLabel' => allstat_number($views),
            'avgViews' => $n > 0 ? (int) round($views / $n) : 0,
            'watchSec' => $watch,
            'watchLabel' => allstat_watch_time_label($watch),
            'avgLabel' => $views > 0 ? allstat_duration_label($watch / $views) : '—',
            'avgPctLabel' => $avgPct > 0 ? allstat_percent($avgPct, 1) : '—',
            'engagement' => $eng,
            'engRateLabel' => $views > 0 ? allstat_percent(round($eng / $views * 100, 2), 2) : '—',
            'newest' => $list[0]['date'] ?? '',
            'items' => $list,
        ];
    };
    $groups = array_map($summarize, $groups);
    // Playlisty podle počtu videí, „Nezařazená" vždy poslední.
    uasort($groups, static fn (array $a, array $b): int => ($a['id'] === '') <=> ($b['id'] === '') ?: $b['count'] <=> $a['count'] ?: strcmp($a['title'], $b['title']));

    $all = array_values($videos);
    usort($all, static fn (array $a, array $b): int => $b['views'] <=> $a['views']);

    return [
        'hasData' => true,
        'count' => count($videos),
        'videos' => $all,
        'groups' => array_values($groups),
        'playlistCount' => count(array_filter($groups, static fn (array $g): bool => $g['id'] !== '')),
        'unassignedCount' => count($unassigned),
        'multiCount' => count(array_filter($inAny, static fn (int $n): bool => $n > 1)),
    ];
}

/**
 * Publikum YouTube: zdroje návštěvnosti (SOUČET zhlédnutí za období, denní dimension řádky `traffic_source`)
 * + geografie a demografie (SNAPSHOT k poslednímu datu ≤ konec období, za 90 dní před ním; reporty nemají
 * denní dimenzi). Každá sekce nese datum, ať je ve view i exportu vidět, k čemu se vztahuje.
 */
function allstat_get_youtube_audience(PDO $pdo, int $connectionId, string $start, string $end): array
{
    [$start, $end] = allstat_limited_range($start, $end);
    $out = ['traffic' => [], 'trafficTotal' => 0, 'geo' => [], 'geoDate' => '', 'age' => [], 'gender' => [], 'demoDate' => '', 'hasData' => false];
    $share = static function (array $rows, string $labelFn = ''): array {
        $tot = array_sum(array_map(static fn ($r) => (float) $r['value'], $rows));
        return array_map(static fn (array $r): array => $r + ['share' => $tot > 0 ? round((float) $r['value'] / $tot * 100, 1) : 0], $rows);
    };

    try {
        $traffic = allstat_fetch_all($pdo, "
            SELECT dimension, SUM(metric_value) AS val FROM provider_metrics_daily
            WHERE connection_id = ? AND metric_key = 'traffic_source' AND metric_date BETWEEN ? AND ? AND dimension <> ''
            GROUP BY dimension ORDER BY val DESC
        ", [$connectionId, $start, $end]);
        $rows = [];
        foreach ($traffic as $t) {
            $v = (float) $t['val'];
            if ($v <= 0) { continue; }
            $rows[] = ['label' => allstat_youtube_traffic_label((string) $t['dimension']), 'value' => $v];
        }
        $out['traffic'] = $share($rows);
        $out['trafficTotal'] = (int) array_sum(array_map(static fn ($r) => (float) $r['value'], $rows));

        $snapshot = static function (string $key) use ($pdo, $connectionId, $end): array {
            $d = allstat_fetch_one($pdo, "SELECT MAX(metric_date) AS d FROM provider_metrics_daily WHERE connection_id = ? AND metric_key = ? AND metric_date <= ?", [$connectionId, $key, $end]);
            $date = (string) ($d['d'] ?? '');
            if ($date === '') { return ['', []]; }
            $rows = allstat_fetch_all($pdo, "SELECT dimension, metric_value FROM provider_metrics_daily WHERE connection_id = ? AND metric_key = ? AND metric_date = ? AND dimension <> '' ORDER BY metric_value DESC", [$connectionId, $key, $date]);
            return [$date, $rows];
        };
        [$geoDate, $geo] = $snapshot('geo_country');
        $out['geoDate'] = $geoDate !== '' ? (new DateTimeImmutable($geoDate))->format('j. n. Y') : '';
        $out['geo'] = $share(array_map(static fn (array $r): array => ['label' => allstat_youtube_country_label((string) $r['dimension']), 'value' => (float) $r['metric_value']], $geo));
        [$demoDate, $age] = $snapshot('viewer_age');
        [, $gender] = $snapshot('viewer_gender');
        $out['demoDate'] = $demoDate !== '' ? (new DateTimeImmutable($demoDate))->format('j. n. Y') : '';
        $ageLabel = static fn (string $d): string => match ($d) { 'age13-17' => '13–17', 'age18-24' => '18–24', 'age25-34' => '25–34', 'age35-44' => '35–44', 'age45-54' => '45–54', 'age55-64' => '55–64', 'age65-' => '65+', default => $d };
        $genderLabel = static fn (string $d): string => match ($d) { 'female' => 'Ženy', 'male' => 'Muži', 'user_specified' => 'Jiné / neuvedeno', default => $d };
        $out['age'] = array_map(static fn (array $r): array => ['label' => $ageLabel((string) $r['dimension']), 'value' => (float) $r['metric_value']], $age);
        usort($out['age'], static fn ($a, $b) => strcmp($a['label'], $b['label']));
        $out['gender'] = array_map(static fn (array $r): array => ['label' => $genderLabel((string) $r['dimension']), 'value' => (float) $r['metric_value']], $gender);
    } catch (Throwable) {
        return $out;
    }

    $out['hasData'] = $out['traffic'] !== [] || $out['geo'] !== [] || $out['age'] !== [];

    return $out;
}

/**
 * ISO kód země (YouTube Analytics dimension country) → český název; neznámé kódy zůstanou jako kód.
 */
function allstat_youtube_country_label(string $code): string
{
    static $map = ['CZ' => 'Česko', 'SK' => 'Slovensko', 'DE' => 'Německo', 'AT' => 'Rakousko', 'PL' => 'Polsko', 'US' => 'USA', 'GB' => 'Velká Británie',
        'FR' => 'Francie', 'IT' => 'Itálie', 'ES' => 'Španělsko', 'NL' => 'Nizozemsko', 'BE' => 'Belgie', 'CH' => 'Švýcarsko', 'HU' => 'Maďarsko', 'UA' => 'Ukrajina',
        'RU' => 'Rusko', 'IN' => 'Indie', 'CA' => 'Kanada', 'AU' => 'Austrálie', 'SE' => 'Švédsko', 'NO' => 'Norsko', 'DK' => 'Dánsko', 'FI' => 'Finsko', 'IE' => 'Irsko',
        'PT' => 'Portugalsko', 'RO' => 'Rumunsko', 'BG' => 'Bulharsko', 'HR' => 'Chorvatsko', 'SI' => 'Slovinsko', 'GR' => 'Řecko', 'TR' => 'Turecko', 'BR' => 'Brazílie',
        'MX' => 'Mexiko', 'JP' => 'Japonsko', 'KR' => 'Jižní Korea', 'CN' => 'Čína', 'VN' => 'Vietnam', 'PH' => 'Filipíny', 'ID' => 'Indonésie', 'ZZ' => 'Neznámá'];

    return $map[strtoupper($code)] ?? $code;
}

function allstat_dashboard_provider_options(?PDO $pdo, int $domainId): array
{
    if (!$pdo || !allstat_tables_ready($pdo)) {
        return [];
    }

    try {
        $rows = allstat_fetch_all($pdo, '
            SELECT src.id AS connection_id, src.account_label, src.property_id,
                   ds.id AS source_id, ds.provider_key, ds.name, ds.category
            FROM domain_sources src
            INNER JOIN data_sources ds ON ds.id = src.source_id
            WHERE src.domain_id = ? AND src.is_enabled = 1
            ORDER BY ds.category ASC, ds.name ASC, src.id ASC
        ', [$domainId]);
    } catch (Throwable) {
        return [];
    }

    return array_map(static function (array $row): array {
        $label = trim((string) ($row['account_label'] ?? ''));
        if ($label === '') {
            $label = (string) $row['name'];
        }

        return [
            'connection_id' => (int) $row['connection_id'],
            'source_id' => (int) $row['source_id'],
            'provider_key' => (string) $row['provider_key'],
            'name' => (string) $row['name'],
            // Account/page name when set (distinguishes two Facebook pages), else the provider name.
            'label' => $label,
            'category' => (string) $row['category'],
            'categoryLabel' => allstat_provider_categories()[$row['category']] ?? (string) $row['category'],
            // ga4/gsc have rich native views (the overview); everything else uses the generic view.
            'generic' => !in_array($row['provider_key'], ['ga4', 'gsc'], true),
            // "rolling" = tiny last-1-3-day API (Clarity), so it gets day-scale quick ranges and
            // builds history only by daily accumulation (mirrors the single_shot sync recipe).
            'rolling' => $row['provider_key'] === 'clarity',
        ];
    }, $rows);
}

/**
 * Curated, human labels for a provider's headline metric keys. When non-empty, the provider view
 * shows ONLY these keys (in this order) with these labels — hides raw/noisy keys from older syncs.
 */
function allstat_provider_metric_labels(string $providerKey): array
{
    if ($providerKey === 'clarity') {
        return ['sessions' => 'Návštěvy', 'bot_sessions' => 'Boti'];
    }

    return array_map(static fn (array $meta): string => $meta['label'], allstat_provider_metric_meta($providerKey));
}

/**
 * Curated tile metadata (label + lucide icon + color + tooltip) for a provider's headline metric
 * keys. When non-empty, the generic provider view shows ONLY these keys, in this order, as nicely
 * labelled tiles — and hides raw/deprecated keys. Keys must match the recipe's local metric keys
 * in lib/sync-engines.php.
 */
function allstat_provider_metric_meta(string $providerKey): array
{
    return match ($providerKey) {
        'seznam_wmt' => [
            'indexed' => ['label' => 'Indexované', 'icon' => 'check-circle-2', 'color' => 'green', 'tooltip' => 'Počet stránek webu zařazených v indexu Seznam.cz (aktuální stav k poslednímu dni). Trend ukazuje vývoj, propad = problém s indexací.'],
            'content' => ['label' => 'Obsahové stránky', 'icon' => 'file-text', 'color' => 'blue', 'tooltip' => 'Stránky s obsahem (skutečné indexovatelné stránky, ne obrázky/přesměrování). Aktuální stav.'],
            'downloaded' => ['label' => 'Stažené', 'icon' => 'download', 'color' => 'cyan', 'tooltip' => 'Kolik stránek robot Seznamu stáhl a zná. Aktuální stav.'],
            'error' => ['label' => 'Chyby', 'icon' => 'alert-triangle', 'color' => 'rose', 'tooltip' => 'Stránky, u kterých robot narazil na chybu (nedostupné, chybové kódy). Rostoucí číslo = řešit.'],
            'redirected' => ['label' => 'Přesměrování', 'icon' => 'corner-down-right', 'color' => 'orange', 'tooltip' => 'Stránky vracející přesměrování. Aktuální stav.'],
            'doc_count' => ['label' => 'Dokumenty celkem', 'icon' => 'files', 'color' => 'violet', 'tooltip' => 'Celkový počet dokumentů webu, které Seznam eviduje. Aktuální stav.'],
        ],
        'facebook_pages' => [
            'followers_total' => ['label' => 'Sledující celkem', 'icon' => 'users', 'color' => 'green', 'tooltip' => 'Celkový počet sledujících stránky (Facebook followers_count), aktuální stav k poslednímu syncu. Ukládá se denně, takže postupně vznikne graf vývoje; zpětnou historii Facebook API nedává.'],
            'reach' => ['label' => 'Dosah', 'icon' => 'eye', 'color' => 'blue', 'tooltip' => 'Unikátní lidé, kteří viděli obsah stránky (Facebook page_impressions_unique, po 15. 6. 2026 page_total_media_view_unique). Součet za období, klíčová metrika organického dosahu.'],
            'page_impressions' => ['label' => 'Zobrazení', 'icon' => 'eye', 'color' => 'cyan', 'tooltip' => 'Kolikrát se obsah stránky zobrazil celkem, vč. opakovaných (Facebook page_media_view, náhrada zrušené page_impressions). Součet za období.'],
            'engagements' => ['label' => 'Engagement', 'icon' => 'activity', 'color' => 'violet', 'tooltip' => 'Interakce s příspěvky (reakce, komentáře, sdílení, prokliky), Facebook page_post_engagements. Součet za období.'],
            'page_views' => ['label' => 'Návštěvy profilu', 'icon' => 'users-round', 'color' => 'teal', 'tooltip' => 'Kolikrát si lidé zobrazili profil stránky (Facebook page_views_total). Součet za období.'],
            'new_follows' => ['label' => 'Noví sledující', 'icon' => 'user-plus', 'color' => 'green', 'tooltip' => 'Noví sledující za období (Facebook page_daily_follows). Nahrazuje zrušené page_fan_adds.'],
            'unfollows' => ['label' => 'Odhlášení', 'icon' => 'user-minus', 'color' => 'rose', 'tooltip' => 'Kolik lidí přestalo sledovat stránku (Facebook page_daily_unfollows). Noví − odhlášení = čistý přírůstek.'],
        ],
        'instagram_business' => [
            'followers_total' => ['label' => 'Sledující celkem', 'icon' => 'users', 'color' => 'green', 'tooltip' => 'Celkový počet sledujících profilu (Instagram followers_count), aktuální stav k poslednímu syncu. Ukládá se denně, takže postupně vznikne graf vývoje; zpětnou historii API nedává.'],
            'reach' => ['label' => 'Dosah', 'icon' => 'eye', 'color' => 'blue', 'tooltip' => 'Unikátní účty, které viděly obsah (Instagram reach). Součet denního dosahu za období.'],
            'new_follows' => ['label' => 'Noví sledující', 'icon' => 'user-plus', 'color' => 'green', 'tooltip' => 'Noví sledující za období (Instagram follower_count, denní přírůstek). Nahradilo „Návštěvy profilu", tu Meta překlopila na jiný tvar dotazu (metric_type=total_value), který nejde po dnech.'],
        ],
        'meta_ads' => [
            'spend' => ['label' => 'Útrata', 'icon' => 'wallet', 'color' => 'rose', 'tooltip' => 'Útrata za reklamu (Meta Ads spend) za období. Fáze 2 doplní odvozené ROAS / CPC / CPM / CTR / frequency.'],
            'impressions' => ['label' => 'Zobrazení', 'icon' => 'eye', 'color' => 'orange', 'tooltip' => 'Počet zobrazení reklam (Meta Ads impressions).'],
            'clicks' => ['label' => 'Kliknutí', 'icon' => 'mouse-pointer-click', 'color' => 'violet', 'tooltip' => 'Počet kliknutí na reklamy (Meta Ads clicks).'],
            'reach' => ['label' => 'Dosah', 'icon' => 'users-round', 'color' => 'blue', 'tooltip' => 'Unikátní lidé, kteří viděli reklamu (Meta Ads reach).'],
        ],
        'youtube' => [
            // Klíče = recept youtube v sync-engines.php. followers_total / views_total / videos_total jsou SNAPSHOTY
            // (stav kanálu k datu), ostatní denní součty z YouTube Analytics. avg_view_duration (denní průměr) tu
            // záměrně není, view ho dopočítá ze součtů (sledovaný čas ÷ zhlédnutí).
            'followers_total' => ['label' => 'Odběratelé celkem', 'icon' => 'users', 'color' => 'green', 'tooltip' => 'Celkový počet odběratelů kanálu (YouTube subscriberCount), stav k poslednímu syncu. Ukládá se denně, takže postupně vznikne graf vývoje; zpětnou historii Data API nedává.'],
            'views' => ['label' => 'Zhlédnutí', 'icon' => 'eye', 'color' => 'blue', 'tooltip' => 'Počet zhlédnutí videí kanálu za období (YouTube Analytics views, denní součet). Počítá se každé přehrání, ne unikátní diváci.'],
            'watch_time_min' => ['label' => 'Sledovaný čas', 'icon' => 'clock', 'color' => 'cyan', 'tooltip' => 'Celková doba, kterou diváci strávili sledováním videí kanálu za období (YouTube Analytics estimatedMinutesWatched). Klíčová metrika pro algoritmus YouTube.'],
            'new_follows' => ['label' => 'Noví odběratelé', 'icon' => 'user-plus', 'color' => 'green', 'tooltip' => 'Kolik lidí se za období přihlásilo k odběru (YouTube Analytics subscribersGained). Součet za období.'],
            'unfollows' => ['label' => 'Odhlášení odběru', 'icon' => 'user-minus', 'color' => 'rose', 'tooltip' => 'Kolik lidí za období odběr zrušilo (YouTube Analytics subscribersLost). Noví − odhlášení = čistý přírůstek.'],
            'likes' => ['label' => 'To se mi líbí', 'icon' => 'thumbs-up', 'color' => 'violet', 'tooltip' => 'Počet „líbí se mi" u videí kanálu za období (YouTube Analytics likes, bez ohledu na datum publikace videa).'],
            'comments' => ['label' => 'Komentáře', 'icon' => 'message-circle', 'color' => 'teal', 'tooltip' => 'Počet komentářů u videí kanálu za období (YouTube Analytics comments).'],
            'shares' => ['label' => 'Sdílení', 'icon' => 'share-2', 'color' => 'orange', 'tooltip' => 'Kolikrát diváci videa sdíleli tlačítkem Sdílet (YouTube Analytics shares).'],
            'views_total' => ['label' => 'Zhlédnutí celkem', 'icon' => 'play-circle', 'color' => 'blue', 'tooltip' => 'Celoživotní počet zhlédnutí všech videí kanálu (YouTube viewCount), stav k poslednímu syncu. Není to součet za období.'],
            // videos_total (počet videí, snapshot) se ukládá taky, ale dlaždici nemá: počet ukazuje sekce Videa.
        ],
        'linkedin_company' => [
            // Local keys = recipe map in sync-engines.php (impressionCount→impressions, …). All are daily
            // COUNTS → safely summable. The 6th recipe key `engagement_rate` is a daily RATE (nesčítatelné),
            // so it's deliberately NOT whitelisted here → hidden; engagement se dopočítá v pořádné view.
            'followers_total' => ['label' => 'Sledující celkem', 'icon' => 'users', 'color' => 'green', 'tooltip' => 'Celkový počet sledujících firemní stránky (LinkedIn networkSizes), aktuální stav k poslednímu syncu. Ukládá se denně, takže postupně vznikne graf vývoje; zpětnou historii API nedává.'],
            'new_follows' => ['label' => 'Noví sledující', 'icon' => 'user-plus', 'color' => 'green', 'tooltip' => 'Čistý přírůstek sledujících za období (LinkedIn followerGains, organic + paid). Odpovídá údaji „Noví sledující uživatelé" ve statistikách LinkedInu. Je to čistá změna: den, kdy stránka o sledující přišla, je záporný. Kolik lidí se odhlásilo LinkedIn API neprozradí, jen výslednou změnu.'],
            'impressions' => ['label' => 'Zobrazení', 'icon' => 'eye', 'color' => 'blue', 'tooltip' => 'Kolikrát se příspěvky stránky zobrazily (LinkedIn impressionCount). Součet za období.'],
            'page_views' => ['label' => 'Návštěvy stránky', 'icon' => 'users-round', 'color' => 'teal', 'tooltip' => 'Kolikrát lidé zobrazili firemní stránku na LinkedIn (organizationPageStatistics, allPageViews, mobil i desktop, všechny záložky). Součet za období.'],
            'clicks' => ['label' => 'Prokliky', 'icon' => 'mouse-pointer-click', 'color' => 'cyan', 'tooltip' => 'Prokliky na příspěvky/odkazy stránky (LinkedIn clickCount). Součet za období.'],
            'likes' => ['label' => 'To se mi líbí', 'icon' => 'thumbs-up', 'color' => 'violet', 'tooltip' => 'Počet reakcí „To se mi líbí" (LinkedIn likeCount). Součet za období.'],
            'comments' => ['label' => 'Komentáře', 'icon' => 'message-circle', 'color' => 'teal', 'tooltip' => 'Počet komentářů u příspěvků (LinkedIn commentCount). Součet za období.'],
            'shares' => ['label' => 'Sdílení', 'icon' => 'share-2', 'color' => 'green', 'tooltip' => 'Počet sdílení příspěvků (LinkedIn shareCount). Součet za období.'],
        ],
        default => [],
    };
}

/**
 * Generic single-provider view: each metric_key from provider_metrics_daily as a total + daily series.
 * Used for Meta/Instagram/LinkedIn/Google Ads/Clarity etc. (the providers that write the generic store).
 * For providers with a curated label map (Clarity) only the whitelisted keys are returned, nicely labelled.
 */
function allstat_get_provider_view(PDO $pdo, int $domainId, int $connectionId, string $start, string $end, string $granularity = 'day', string $providerKey = ''): array
{
    [$start, $end] = allstat_limited_range($start, $end);
    $granularity = allstat_normalize_granularity($granularity);

    try {
        $totals = allstat_fetch_all($pdo, "
            SELECT metric_key, SUM(metric_value) AS total, COUNT(DISTINCT metric_date) AS days, MIN(metric_date) AS first_day, MAX(metric_date) AS last_day
            FROM provider_metrics_daily
            WHERE domain_id = ? AND connection_id = ? AND metric_date BETWEEN ? AND ? AND dimension = ''
            GROUP BY metric_key
            ORDER BY metric_key ASC
        ", [$domainId, $connectionId, $start, $end]);

        // Klíč periody se počítá z data (1. den měsíce / pondělí ISO týdne), ne jako MIN(metric_date) po
        // metrikách: metriky mívají v periodě různý první den (LinkedIn noví sledující od 31. 3., zobrazení od
        // 1. 3.), a pak z jednoho měsíce vznikly dvě periody („3/2026" dvakrát) s metrikami rozdělenými mezi ně.
        $periodExpr = allstat_period_key_sql('metric_date', $granularity);
        $seriesRows = allstat_fetch_all($pdo, "
            SELECT $periodExpr AS period_key, MIN(metric_date) AS period_start, MAX(metric_date) AS period_end, metric_key, SUM(metric_value) AS val
            FROM provider_metrics_daily
            WHERE domain_id = ? AND connection_id = ? AND metric_date BETWEEN ? AND ? AND dimension = ''
            GROUP BY period_key, metric_key
            ORDER BY period_key ASC
        ", [$domainId, $connectionId, $start, $end]);
    } catch (Throwable) {
        return ['labels' => [], 'metrics' => [], 'hasData' => false];
    }

    // Jedna perioda = jeden klíč; popisek z nejdřívějšího a nejpozdějšího dne s daty přes všechny metriky.
    $bounds = [];
    $byMetric = [];
    foreach ($seriesRows as $row) {
        $pk = (string) $row['period_key'];
        $ps = (string) $row['period_start'];
        $pe = (string) $row['period_end'];
        if (!isset($bounds[$pk])) {
            $bounds[$pk] = [$ps, $pe];
        } else {
            $bounds[$pk] = [min($bounds[$pk][0], $ps), max($bounds[$pk][1], $pe)];
        }
        $byMetric[(string) $row['metric_key']][$pk] = (float) $row['val'];
    }
    ksort($bounds);
    $periods = [];
    foreach ($bounds as $pk => [$ps, $pe]) {
        $periods[$pk] = allstat_series_label(['period_start' => $ps, 'period_end' => $pe], $granularity);
    }
    $periodKeys = array_keys($periods);

    // Snapshot metriky (stavové hodnoty typu „sledující celkem") se NESMÍ sčítat po dnech:
    // total = poslední známá hodnota v období, série = poslední hodnota v každé periodě
    // (chybějící periody se dopočítají poslední známou hodnotou, ať graf neskáče na nulu).
    $snapshotKeys = ['followers_total', 'fans_total', 'views_total', 'videos_total'];
    $snapTotals = [];
    try {
        $snapRows = allstat_fetch_all($pdo, "
            SELECT metric_date, metric_key, metric_value
            FROM provider_metrics_daily
            WHERE domain_id = ? AND connection_id = ? AND metric_date BETWEEN ? AND ? AND dimension = ''
              AND metric_key IN ('" . implode("','", $snapshotKeys) . "')
            ORDER BY metric_date ASC
        ", [$domainId, $connectionId, $start, $end]);
        foreach ($snapshotKeys as $sk) {
            unset($byMetric[$sk]);
        }
        foreach ($snapRows as $row) {
            $key = (string) $row['metric_key'];
            $day = (string) $row['metric_date'];
            $snapTotals[$key] = (float) $row['metric_value']; // řazeno ASC → vyhraje poslední
            $pk = allstat_period_key($day, $granularity);
            if (isset($periods[$pk])) {
                $byMetric[$key][$pk] = (float) $row['metric_value'];
            }
        }
        // Forward-fill mezer, ať sparkline drží poslední stav místo propadu na nulu.
        foreach ($snapshotKeys as $sk) {
            if (empty($byMetric[$sk])) { continue; }
            $carry = null;
            foreach ($periodKeys as $pk) {
                if (isset($byMetric[$sk][$pk])) { $carry = $byMetric[$sk][$pk]; }
                elseif ($carry !== null) { $byMetric[$sk][$pk] = $carry; }
            }
        }
        foreach ($totals as &$totalRow) {
            if (isset($snapTotals[(string) $totalRow['metric_key']])) {
                $totalRow['total'] = $snapTotals[(string) $totalRow['metric_key']];
            }
        }
        unset($totalRow);
    } catch (Throwable) { /* snapshot agregace je bonus */ }

    $metrics = array_map(static function (array $row) use ($byMetric, $periodKeys): array {
        $key = (string) $row['metric_key'];
        $total = (float) $row['total'];
        $decimals = fmod($total, 1.0) !== 0.0 ? 2 : 0;
        $series = array_map(static fn (string $pk): float => round((float) ($byMetric[$key][$pk] ?? 0), 2), $periodKeys);

        return [
            'key' => $key,
            'label' => $key,
            'total' => $total,
            'totalLabel' => allstat_number($total, $decimals),
            'series' => $series,
            // Pokrytí: kolik dní období má metrika řádek (a od kdy do kdy). Chybějící dny nejsou nula.
            'days' => (int) ($row['days'] ?? 0),
            'firstDay' => (string) ($row['first_day'] ?? ''),
            'lastDay' => (string) ($row['last_day'] ?? ''),
        ];
    }, $totals);

    // Curated providers (Facebook/Instagram/Meta Ads…): keep only whitelisted keys, in map order,
    // as labelled tiles with icon/color/tooltip; hides raw or deprecated keys from older syncs.
    $metaMap = allstat_provider_metric_meta($providerKey);
    if ($metaMap !== []) {
        $byKey = [];
        foreach ($metrics as $metric) {
            $byKey[$metric['key']] = $metric;
        }
        $metrics = [];
        foreach ($metaMap as $key => $meta) {
            if (isset($byKey[$key])) {
                $metric = $byKey[$key];
                $metric['label'] = $meta['label'];
                $metric['icon'] = $meta['icon'] ?? 'activity';
                $metric['color'] = $meta['color'] ?? 'cyan';
                $metric['tooltip'] = $meta['tooltip'] ?? '';
                $metrics[] = $metric;
            }
        }

        // YouTube: sledovaný čas z minut na „h min" a Ø doba zhlédnutí = sledovaný čas ÷ zhlédnutí (denní
        // averageViewDuration je průměr, sčítat ho nejde; ze součtů vyjde správný vážený průměr). Plus čistý
        // přírůstek odběratelů (noví − odhlášení) a engagement (líbí se + komentáře + sdílení).
        if ($providerKey === 'youtube') {
            $viewsTotal = (float) ($byKey['views']['total'] ?? 0);
            $watchMin = (float) ($byKey['watch_time_min']['total'] ?? 0);
            foreach ($metrics as &$m) {
                if ($m['key'] === 'watch_time_min') {
                    $m['totalLabel'] = allstat_watch_time_label((int) round($watchMin * 60));
                    $m['seriesMinutes'] = $m['series']; // pro MCP: stejná jednotka jako total (minuty)
                    $m['series'] = array_map(static fn (float $v): float => round($v / 60, 1), $m['series']); // graf v hodinách
                }
            }
            unset($m);
            if ($viewsTotal > 0 || $watchMin > 0) {
                $avgSeries = [];
                foreach ($periodKeys as $pk) {
                    $v = (float) ($byMetric['views'][$pk] ?? 0);
                    $avgSeries[] = $v > 0 ? round((float) ($byMetric['watch_time_min'][$pk] ?? 0) * 60 / $v, 1) : 0.0;
                }
                $avgSec = $viewsTotal > 0 ? $watchMin * 60 / $viewsTotal : 0.0;
                $metrics[] = ['key' => 'avg_view_duration_derived', 'label' => 'Ø doba zhlédnutí', 'total' => round($avgSec, 1),
                    'totalLabel' => allstat_duration_label($avgSec), 'series' => $avgSeries, 'icon' => 'timer', 'color' => 'cyan',
                    'tooltip' => 'Průměrná doba jednoho zhlédnutí = sledovaný čas ÷ zhlédnutí za období (vážený průměr, stejně jako YouTube Studio). Delší = obsah lidi drží.'];
            }
            if (isset($byKey['new_follows']) || isset($byKey['unfollows'])) {
                $netSeries = [];
                foreach ($periodKeys as $pk) {
                    $netSeries[] = round((float) ($byMetric['new_follows'][$pk] ?? 0) - (float) ($byMetric['unfollows'][$pk] ?? 0), 0);
                }
                $net = (float) ($byKey['new_follows']['total'] ?? 0) - (float) ($byKey['unfollows']['total'] ?? 0);
                $metrics[] = ['key' => 'subscribers_net', 'label' => 'Čistý přírůstek odběratelů', 'total' => $net,
                    'totalLabel' => ($net > 0 ? '+' : '') . allstat_number($net), 'series' => $netSeries, 'icon' => 'trending-up', 'color' => 'green',
                    'tooltip' => 'Noví odběratelé − odhlášení za období. Odpovídá „Odběratelé" v YouTube Studio za stejné období.'];
            }
            $ix = ['likes', 'comments', 'shares'];
            if (isset($byKey['likes']) || isset($byKey['comments']) || isset($byKey['shares'])) {
                $engSeries = [];
                foreach ($periodKeys as $pk) {
                    $e = 0.0;
                    foreach ($ix as $k) { $e += (float) ($byMetric[$k][$pk] ?? 0); }
                    $engSeries[] = round($e, 0);
                }
                $engTotal = 0.0;
                foreach ($ix as $k) { $engTotal += (float) ($byKey[$k]['total'] ?? 0); }
                $metrics[] = ['key' => 'engagements_total', 'label' => 'Celkový engagement', 'total' => $engTotal,
                    'totalLabel' => allstat_number($engTotal), 'series' => $engSeries, 'icon' => 'activity', 'color' => 'orange',
                    'tooltip' => 'To se mi líbí + komentáře + sdílení za období. Míra zapojení = engagement ÷ zhlédnutí' . ($viewsTotal > 0 ? ' = ' . allstat_number($engTotal / $viewsTotal * 100, 2) . ' %' : '') . '.'];
            }
            // Pořadí dlaždic: komunita → konzumace → interakce → celoživotní stav (odvozené hned u svých zdrojů).
            $order = array_flip(['followers_total', 'subscribers_net', 'new_follows', 'unfollows', 'views', 'watch_time_min', 'avg_view_duration_derived',
                'likes', 'comments', 'shares', 'engagements_total', 'views_total']);
            usort($metrics, static fn (array $a, array $b): int => ($order[$a['key']] ?? 99) <=> ($order[$b['key']] ?? 99));
        }

        // LinkedIn: dopočítej „Celkový engagement" (součet interakcí) a „Míra zapojení" (engagement ÷
        // zobrazení) z počítaných metrik — LinkedIn vlastní `engagement` je denní POMĚR (nesčítatelný),
        // tak ho recomputneme ze součtů. Per-period série, ať to může jít i do trend grafu.
        if ($providerKey === 'linkedin_company') {
            // engagement = sociální interakce BEZ prokliků — LinkedIn clickCount je velmi široký (zahrnuje
            // i prokliky na jméno stránky/„zobrazit více") a zkresloval by míru zapojení; prokliky mají
            // vlastní dlaždici. Engagement = reakce + komentáře + sdílení.
            $ix = ['likes', 'comments', 'shares'];
            $engSeries = [];
            foreach ($periodKeys as $pk) {
                $e = 0.0;
                foreach ($ix as $k) { $e += (float) ($byMetric[$k][$pk] ?? 0); }
                $engSeries[] = round($e, 2);
            }
            $engTotal = 0.0;
            foreach ($ix as $k) { $engTotal += (float) ($byKey[$k]['total'] ?? 0); }
            $imprTotal = (float) ($byKey['impressions']['total'] ?? 0);
            $metrics[] = ['key' => 'engagements_total', 'label' => 'Celkový engagement', 'total' => $engTotal,
                'totalLabel' => allstat_number($engTotal), 'series' => $engSeries, 'icon' => 'activity', 'color' => 'orange',
                'tooltip' => 'Součet sociálních interakcí (To se mi líbí + komentáře + sdílení) za období. Prokliky jsou samostatně (dlaždice Prokliky).'];
            $rate = $imprTotal > 0 ? $engTotal / $imprTotal * 100 : 0.0;
            $rateSeries = [];
            foreach ($periodKeys as $i => $pk) {
                $imp = (float) ($byMetric['impressions'][$pk] ?? 0);
                $rateSeries[] = $imp > 0 ? round($engSeries[$i] / $imp * 100, 2) : 0.0;
            }
            $metrics[] = ['key' => 'engagement_rate_derived', 'label' => 'Míra zapojení', 'total' => $rate,
                'totalLabel' => allstat_number($rate, 2) . ' %', 'series' => $rateSeries, 'icon' => 'percent', 'color' => 'violet',
                'tooltip' => 'Sociální interakce (reakce + komentáře + sdílení) ÷ zobrazení za období. Pozn.: LinkedIn do své oficiální míry počítá i prokliky a sledování, tady je záměrně užší „sociální" engagement.'];
        }
    }

    return [
        'labels' => array_values($periods),
        'metrics' => $metrics,
        'hasData' => $metrics !== [],
    ];
}

/**
 * Czech labels for Clarity smart-event names (English in the export). Unknown names pass through.
 */
function allstat_clarity_smart_event_label(string $name): string
{
    static $map = [
        'Outbound click' => 'Odchozí proklik',
        'Contact us' => 'Kontakt',
        'Show more' => 'Zobrazit více',
        'Download' => 'Stažení',
        'Submit form' => 'Odeslání formuláře',
        'Login' => 'Přihlášení',
        'Sign up' => 'Registrace',
        'Search' => 'Vyhledávání',
        'Add to cart' => 'Přidání do košíku',
        'Checkout' => 'Pokladna',
        'Purchase' => 'Nákup',
    ];

    return $map[$name] ?? $name;
}

/**
 * Clarity dimensioned breakdowns (referrers, pages, browsers, smart events) summed over the range.
 * Each entry: ['title' => ..., 'help' => ..., 'items' => [['name','sessions','sessionsLabel','shareLabel'], ...]].
 */
function allstat_get_clarity_breakdowns(PDO $pdo, int $domainId, int $connectionId, string $start, string $end, int $limit = 12): array
{
    [$start, $end] = allstat_limited_range($start, $end);
    $sections = [
        'referrer' => ['title' => 'Top referrery', 'help' => 'Odkud návštěvníci přišli (Clarity referrer). Součet relací za zvolené období.'],
        'page' => ['title' => 'Top stránky', 'help' => 'Nejnavštěvovanější stránky podle počtu návštěv (Clarity).'],
        'device' => ['title' => 'Zařízení', 'help' => 'Rozdělení relací podle typu zařízení (Clarity).'],
        'browser' => ['title' => 'Prohlížeče', 'help' => 'Rozdělení relací podle prohlížeče (Clarity).'],
        'os' => ['title' => 'Operační systémy', 'help' => 'Rozdělení relací podle operačního systému (Clarity).'],
        'country' => ['title' => 'Země', 'help' => 'Rozdělení relací podle země návštěvníka (Clarity).'],
        'smart_event' => ['title' => 'Smart events', 'help' => 'Akce sledované Clarity (prokliky, kontakt…). Počet relací, ve kterých akce nastala.'],
    ];
    $deviceLabels = ['PC' => 'Počítač', 'Mobile' => 'Mobil', 'Tablet' => 'Tablet', 'Other' => 'Ostatní'];
    $out = [];

    foreach ($sections as $key => $meta) {
        try {
            $rows = allstat_fetch_all($pdo, "
                SELECT dimension AS name, SUM(metric_value) AS sessions
                FROM provider_metrics_daily
                WHERE domain_id = ? AND connection_id = ? AND metric_key = ? AND dimension <> '' AND metric_date BETWEEN ? AND ?
                GROUP BY dimension
                ORDER BY sessions DESC
                LIMIT " . (int) $limit . "
            ", [$domainId, $connectionId, $key, $start, $end]);
            // Podíl z celku za období (všechny hodnoty), ne jen z vrácené top-N.
            $total = (float) (allstat_fetch_one($pdo, "
                SELECT COALESCE(SUM(metric_value), 0) AS total
                FROM provider_metrics_daily
                WHERE domain_id = ? AND connection_id = ? AND metric_key = ? AND dimension <> '' AND metric_date BETWEEN ? AND ?
            ", [$domainId, $connectionId, $key, $start, $end])['total'] ?? 0);
        } catch (Throwable) {
            $rows = [];
            $total = 0.0;
        }
        if ($key === 'device') {
            foreach ($rows as &$deviceRow) {
                $deviceRow['name'] = $deviceLabels[(string) $deviceRow['name']] ?? $deviceRow['name'];
            }
            unset($deviceRow);
        }
        $out[$key] = [
            'title' => $meta['title'],
            'help' => $meta['help'],
            'items' => array_map(static function (array $r) use ($total, $key): array {
                $sessions = (float) $r['sessions'];
                $raw = (string) $r['name'];
                if ($key === 'smart_event') {
                    $name = allstat_clarity_smart_event_label($raw);
                } elseif ($key === 'page') {
                    $name = preg_replace('#^https?://[^/]+#i', '', $raw);
                    if ($name === null || $name === '') { $name = '/'; }
                } else {
                    $name = $raw;
                }
                return [
                    'name' => $name,
                    'sessions' => (int) $sessions,
                    'sessionsLabel' => allstat_number($sessions),
                    'shareLabel' => allstat_percent($total > 0 ? ($sessions / $total) * 100 : 0, 1),
                ];
            }, $rows),
        ];
    }

    return $out;
}

/**
 * Clarity KPI tiles. Counts (sessions/bots/new users) are summed over the range; per-session
 * averages (active time, scroll depth, pages/session) are session-weighted across the days that
 * have them. Each tile carries icon/color/tooltip + a daily series for its sparkline.
 */
function allstat_get_clarity_kpis(PDO $pdo, int $domainId, int $connectionId, string $start, string $end, string $granularity = 'day'): array
{
    [$start, $end] = allstat_limited_range($start, $end);
    $definitions = [
        ['key' => 'sessions', 'label' => 'Návštěvy', 'icon' => 'users-round', 'color' => 'teal', 'agg' => 'sum', 'format' => 'number', 'tooltip' => 'Počet relací (sessions) v Microsoft Clarity za zvolené období. Součet přes dny.'],
        ['key' => 'bot_sessions', 'label' => 'Boti', 'icon' => 'bug', 'color' => 'rose', 'agg' => 'sum', 'format' => 'number', 'tooltip' => 'Relace vyhodnocené Clarity jako automatický provoz (boti). Součet za období.'],
        ['key' => 'distinct_users', 'label' => 'Uživatelé (součet dní)', 'icon' => 'user', 'color' => 'cyan', 'agg' => 'sum', 'format' => 'number', 'tooltip' => 'Různí uživatelé podle Clarity za každý den, sečtení přes dny (kdo přišel ve více dnech, je tu vícekrát).'],
        ['key' => 'new_user_sessions', 'label' => 'Noví uživatelé', 'icon' => 'user-plus', 'color' => 'cyan', 'agg' => 'sum', 'format' => 'number', 'tooltip' => 'Relace nových návštěvníků (poprvé na webu) dle Clarity. Jen z ručního CSV importu, API je nedává. Součet za období.'],
        ['key' => 'active_time', 'label' => 'Aktivní čas', 'icon' => 'clock', 'color' => 'orange', 'agg' => 'wavg', 'format' => 'duration', 'tooltip' => 'Průměrný aktivní čas na relaci (čas reálné interakce uživatele). Vážený průměr přes dny.'],
        ['key' => 'total_time', 'label' => 'Celkový čas', 'icon' => 'timer', 'color' => 'orange', 'agg' => 'wavg', 'format' => 'duration', 'tooltip' => 'Průměrná celková doba relace včetně nečinnosti (Clarity totalTime). Vážený průměr přes dny.'],
        ['key' => 'scroll_depth', 'label' => 'Scroll depth', 'icon' => 'mouse-pointer-2', 'color' => 'violet', 'agg' => 'wavg', 'format' => 'percent', 'tooltip' => 'Průměrná hloubka scrollu stránky (%). Vážený průměr přes dny.'],
        ['key' => 'pages_per_session', 'label' => 'Stránky/relace', 'icon' => 'files', 'color' => 'blue', 'agg' => 'wavg', 'format' => 'number2', 'tooltip' => 'Průměrný počet zobrazených stránek na jednu relaci. Vážený průměr přes dny.'],
        // Frustrační signály: podíl relací, ve kterých signál nastal (vážený průměr přes dny), počet v count.
        ['key' => 'rage_clicks_pct', 'count' => 'rage_clicks', 'label' => 'Rage clicks', 'icon' => 'zap', 'color' => 'rose', 'agg' => 'wavg', 'format' => 'percent', 'tooltip' => 'Podíl relací s „naštvaným" opakovaným klikáním na stejné místo (Clarity Rage clicks). Ukazuje, co nefunguje, jak lidé čekají.'],
        ['key' => 'dead_clicks_pct', 'count' => 'dead_clicks', 'label' => 'Dead clicks', 'icon' => 'mouse-pointer-click', 'color' => 'rose', 'agg' => 'wavg', 'format' => 'percent', 'tooltip' => 'Podíl relací s kliknutím, po kterém se nic nestalo (Clarity Dead clicks). Typicky prvek, který vypadá jako odkaz.'],
        ['key' => 'quickbacks_pct', 'count' => 'quickbacks', 'label' => 'Rychlé návraty', 'icon' => 'undo-2', 'color' => 'orange', 'agg' => 'wavg', 'format' => 'percent', 'tooltip' => 'Podíl relací, kde se člověk hned vrátil z otevřené stránky zpět (Clarity Quick backs). Stránka nesplnila očekávání.'],
        ['key' => 'excessive_scroll_pct', 'count' => 'excessive_scroll', 'label' => 'Nadměrný scroll', 'icon' => 'arrow-down-up', 'color' => 'orange', 'agg' => 'wavg', 'format' => 'percent', 'tooltip' => 'Podíl relací s přehnaným scrollováním tam a zpět (Clarity Excessive scrolling). Člověk nemůže najít, co hledá.'],
        ['key' => 'script_errors_pct', 'count' => 'script_errors', 'label' => 'Chyby JavaScriptu', 'icon' => 'bug', 'color' => 'rose', 'agg' => 'wavg', 'format' => 'percent', 'tooltip' => 'Podíl relací s chybou JavaScriptu na stránce (Clarity Script errors).'],
        ['key' => 'error_clicks_pct', 'count' => 'error_clicks', 'label' => 'Kliknutí s chybou', 'icon' => 'alert-triangle', 'color' => 'rose', 'agg' => 'wavg', 'format' => 'percent', 'tooltip' => 'Podíl relací, kde kliknutí vyvolalo chybu JavaScriptu (Clarity Error clicks).'],
    ];

    $keys = array_merge(array_column($definitions, 'key'), array_values(array_filter(array_column($definitions, 'count'))));
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    try {
        $rows = allstat_fetch_all($pdo, "
            SELECT metric_date, metric_key, metric_value
            FROM provider_metrics_daily
            WHERE domain_id = ? AND connection_id = ? AND dimension = '' AND metric_key IN ($placeholders) AND metric_date BETWEEN ? AND ?
            ORDER BY metric_date ASC
        ", array_merge([$domainId, $connectionId], $keys, [$start, $end]));
    } catch (Throwable) {
        return ['labels' => [], 'metrics' => [], 'hasData' => false];
    }

    $byDate = [];
    foreach ($rows as $row) {
        $byDate[(string) $row['metric_date']][(string) $row['metric_key']] = (float) $row['metric_value'];
    }
    ksort($byDate);
    $dates = array_keys($byDate);
    // Periody řady (den / týden / měsíc): průměry se váží relacemi uvnitř periody, součty se sčítají.
    $buckets = [];
    foreach ($dates as $d) {
        $pk = allstat_period_key($d, $granularity);
        $buckets[$pk]['dates'][] = $d;
    }
    $labels = array_map(static fn (array $b): string => allstat_series_label(['period_start' => $b['dates'][0], 'period_end' => end($b['dates'])], $granularity), array_values($buckets));

    $hasAny = false;
    $metrics = [];
    foreach ($definitions as $def) {
        $key = $def['key'];
        $series = [];
        $sum = 0.0;
        $count = 0.0;
        $weightedNum = 0.0;
        $weightDen = 0.0;
        $days = 0;
        foreach ($buckets as $bucket) {
            $bSum = 0.0;
            $bNum = 0.0;
            $bDen = 0.0;
            $bHas = false;
            foreach ($bucket['dates'] as $d) {
                $val = $byDate[$d][$key] ?? null;
                if ($val === null) { continue; }
                $bHas = true;
                $days++;
                $count += (float) ($byDate[$d][$def['count'] ?? ''] ?? 0);
                if ($def['agg'] === 'sum') {
                    $bSum += $val;
                } else {
                    $sessionsThatDay = (float) ($byDate[$d]['sessions'] ?? 0);
                    $bNum += $val * $sessionsThatDay;
                    $bDen += $sessionsThatDay;
                }
            }
            $sum += $bSum;
            $weightedNum += $bNum;
            $weightDen += $bDen;
            $series[] = $bHas ? round($def['agg'] === 'sum' ? $bSum : ($bDen > 0 ? $bNum / $bDen : 0), 2) : null;
        }
        if ($days > 0) {
            $hasAny = true;
        }
        // Bez jediného dne s daty je hodnota nedostupná (null), ne nula: API ji třeba vůbec nedává.
        $value = $days === 0 ? null : ($def['agg'] === 'sum' ? $sum : ($weightDen > 0 ? $weightedNum / $weightDen : 0));
        $displayValue = $value === null ? '—' : match ($def['format']) {
            'percent' => allstat_percent($value, 1),
            'duration' => allstat_duration_label($value),
            'number2' => allstat_number($value, 2),
            default => allstat_number($value, 0),
        };
        $metric = [
            'key' => $key,
            'label' => $def['label'],
            'icon' => $def['icon'],
            'color' => $def['color'],
            'value' => $value,
            'displayValue' => $displayValue,
            'tooltip' => $def['tooltip'],
            'series' => array_map(static fn ($v) => $v ?? 0, $series),
            'days' => $days,
        ];
        if (isset($def['count']) && $value !== null) {
            $metric['count'] = (int) round($count);
            $metric['displayValue'] .= ' relací (' . allstat_number($count, 0) . '×)';
        }
        $metrics[] = $metric;
    }

    // Dlaždice bez dat se na dashboardu neukazují (dřív svítily nuly u metrik, které API nedává).
    $metrics = array_values(array_filter($metrics, static fn (array $m): bool => $m['value'] !== null || in_array($m['key'], ['sessions', 'bot_sessions'], true)));

    return ['labels' => $labels, 'metrics' => $metrics, 'hasData' => $hasAny, 'dates' => count($dates)];
}

/**
 * Meta Ads KPI tiles. Count metrics (spend, impressions, clicks, reach, conversions, conversion_value)
 * are summed over the range; the marketing-critical RATIOS (ROAS, CPA, CTR, CPC, CPM, frequency) are
 * DERIVED from those sums — never summed — exactly like a real ads report. Each tile carries a daily
 * series (counts = that day's value, ratios = that day's derived value) for its sparkline.
 */
function allstat_get_meta_ads_kpis(PDO $pdo, int $domainId, int $connectionId, string $start, string $end, string $granularity = 'day'): array
{
    [$start, $end] = allstat_limited_range($start, $end);
    $baseKeys = ['spend', 'impressions', 'clicks', 'reach', 'conversions', 'conversion_value', 'link_clicks', 'lp_views', 'video_views'];
    $placeholders = implode(',', array_fill(0, count($baseKeys), '?'));
    try {
        $rows = allstat_fetch_all($pdo, "
            SELECT metric_date, metric_key, metric_value
            FROM provider_metrics_daily
            WHERE domain_id = ? AND connection_id = ? AND dimension = '' AND metric_key IN ($placeholders) AND metric_date BETWEEN ? AND ?
            ORDER BY metric_date ASC
        ", array_merge([$domainId, $connectionId], $baseKeys, [$start, $end]));
    } catch (Throwable) {
        return ['labels' => [], 'metrics' => [], 'hasData' => false];
    }

    $byDate = [];
    foreach ($rows as $r) {
        $byDate[(string) $r['metric_date']][(string) $r['metric_key']] = (float) $r['metric_value'];
    }
    // Řada po dnech, týdnech nebo měsících; poměry se počítají ze součtů periody.
    [$byDate, $labels] = allstat_rebucket_daily($byDate, $granularity);
    $dates = array_map('strval', array_keys($byDate));

    $sum = array_fill_keys($baseKeys, 0.0);
    foreach ($byDate as $day) {
        foreach ($baseKeys as $k) { $sum[$k] += $day[$k] ?? 0.0; }
    }
    $ratio = static fn (float $a, float $b, float $scale = 1.0): float => $b > 0.0 ? ($a / $b) * $scale : 0.0;
    $derivedSeries = static fn (string $num, string $den, float $scale): array => array_map(
        static fn (string $d): float => round($den !== '' && (float) ($byDate[$d][$den] ?? 0) > 0 ? ((float) ($byDate[$d][$num] ?? 0) / (float) $byDate[$d][$den]) * $scale : 0, 2),
        $dates
    );
    $countSeries = static fn (string $key): array => array_map(static fn (string $d): float => round((float) ($byDate[$d][$key] ?? 0), 2), $dates);

    $defs = [
        ['key' => 'spend', 'label' => 'Útrata', 'icon' => 'wallet', 'color' => 'rose', 'value' => $sum['spend'], 'format' => 'money0', 'series' => $countSeries('spend'), 'tooltip' => 'Kolik jsi za reklamu utratil za zvolené období (součet přes dny), v měně účtu.'],
        ['key' => 'roas', 'label' => 'ROAS', 'icon' => 'trending-up', 'color' => 'green', 'value' => $ratio($sum['conversion_value'], $sum['spend']), 'format' => 'x', 'series' => $derivedSeries('conversion_value', 'spend', 1.0), 'tooltip' => 'Návratnost výdajů na reklamu = tržby z konverzí ÷ útrata. „3×" = z 1 Kč v reklamě se vrátily 3 Kč tržeb. 0 = účet neměří tržby/konverze (není co dělit), typické u awareness/traffic kampaní.'],
        ['key' => 'pno', 'label' => 'PNO', 'icon' => 'percent', 'color' => 'rose', 'value' => $ratio($sum['spend'], $sum['conversion_value'], 100.0), 'format' => 'percent', 'series' => $derivedSeries('spend', 'conversion_value', 100.0), 'tooltip' => 'PNO (podíl nákladů na obratu) = útrata ÷ tržby z konverzí × 100. Kolik % z obratu spolkne reklama, převrácená hodnota ROAS (PNO 25 % ≈ ROAS 4×). Nižší = lepší. Skryté u účtů bez měření tržeb.'],
        ['key' => 'conversions', 'label' => 'Konverze', 'icon' => 'shopping-cart', 'color' => 'teal', 'value' => $sum['conversions'], 'format' => 'number', 'series' => $countSeries('conversions'), 'tooltip' => 'Počet konverzí (nákup, poptávka, registrace…) přiřazených reklamám. 0 = účet nemá nastavené konverzní sledování (pixel / události), takže žádné akce neměří, jede na dosah/provoz.'],
        ['key' => 'cpa', 'label' => 'CPA', 'icon' => 'target', 'color' => 'orange', 'value' => $ratio($sum['spend'], $sum['conversions']), 'format' => 'money2', 'series' => $derivedSeries('spend', 'conversions', 1.0), 'tooltip' => 'Cena za jednu konverzi = útrata ÷ počet konverzí. Nižší = lepší. Bez konverzí ji nelze spočítat (0).'],
        ['key' => 'conversion_value', 'label' => 'Hodnota konverzí', 'icon' => 'banknote', 'color' => 'green', 'value' => $sum['conversion_value'], 'format' => 'money0', 'series' => $countSeries('conversion_value'), 'tooltip' => 'Tržby (hodnota objednávek) přiřazené reklamám. 0 = účet nehlásí žádnou hodnotu konverzí.'],
        // CTR a CPC na úrovni účtu počítají VŠECHNA kliknutí (Meta `clicks`). Popisek to musí říkat, jinak se
        // zaměňují s prokliky na web (report za 9/2026 psal „cena za proklik" o ceně za jakékoli kliknutí).
        ['key' => 'ctr', 'label' => 'CTR (vše)', 'icon' => 'gauge', 'color' => 'cyan', 'value' => $ratio($sum['clicks'], $sum['impressions'], 100.0), 'format' => 'percent', 'series' => $derivedSeries('clicks', 'impressions', 100.0), 'tooltip' => 'Všechna kliknutí ÷ zobrazení × 100. Kolik % lidí, kterým se reklama ukázala, kliklo kamkoliv do reklamy, tedy i na lajk, komentář, jméno stránky nebo rozbalení textu. Na web vede jen „Proklik na odkaz".'],
        ['key' => 'cpc', 'label' => 'CPC (vše)', 'icon' => 'mouse-pointer-click', 'color' => 'violet', 'value' => $ratio($sum['spend'], $sum['clicks']), 'format' => 'money2', 'series' => $derivedSeries('spend', 'clicks', 1.0), 'tooltip' => 'Cena za jakékoli kliknutí = útrata ÷ všechna kliknutí (i lajky a rozbalení textu). Bývá nižší než cena za proklik na odkaz; kolik stojí přivést člověka na web, ukazuje „Cena / proklik na odkaz".'],
        ['key' => 'cpm', 'label' => 'CPM', 'icon' => 'eye', 'color' => 'orange', 'value' => $ratio($sum['spend'], $sum['impressions'], 1000.0), 'format' => 'money2', 'series' => $derivedSeries('spend', 'impressions', 1000.0), 'tooltip' => 'Cena za 1 000 zobrazení = útrata ÷ zobrazení × 1 000. Kolik platíš za tisíc zobrazení reklamy. Hlavní měřítko kampaní na povědomí.'],
        // Z denních řádků jde spočítat jen DENNÍ frekvenci: součet denních dosahů počítá téhož člověka každý
        // den znovu. Skutečná frekvence za celou dobu kampaně je v tabulce kampaní (meta_ads_totals).
        ['key' => 'frequency', 'label' => 'Denní frekvence', 'icon' => 'repeat', 'color' => 'violet', 'value' => $ratio($sum['impressions'], $sum['reach']), 'format' => 'x2', 'series' => $derivedSeries('impressions', 'reach', 1.0), 'tooltip' => 'Zobrazení ÷ součet denních dosahů = kolikrát průměrně viděl reklamu jeden člověk za JEDEN den. Kolikrát ji viděl za celé období, z denních dat spočítat nejde (tentýž člověk se každý den počítá znovu). Frekvenci za celou dobu kampaně ukazuje tabulka kampaní.'],
        ['key' => 'reach', 'label' => 'Dosah (součet dní)', 'icon' => 'users-round', 'color' => 'blue', 'value' => $sum['reach'], 'format' => 'number', 'series' => $countSeries('reach'), 'tooltip' => 'Součet denních dosahů. Kdo reklamu viděl ve více dnech, je tu vícekrát, takže různých lidí za období bylo méně. Kolik různých lidí vidělo kampaň za celou dobu, ukazuje tabulka kampaní.'],
        ['key' => 'impressions', 'label' => 'Zobrazení', 'icon' => 'eye', 'color' => 'blue', 'value' => $sum['impressions'], 'format' => 'number', 'series' => $countSeries('impressions'), 'tooltip' => 'Zobrazení = kolikrát se reklama ukázala celkem, včetně opakovaných zobrazení témuž člověku.'],
        ['key' => 'clicks', 'label' => 'Kliknutí (vše)', 'icon' => 'mouse-pointer-click', 'color' => 'cyan', 'value' => $sum['clicks'], 'format' => 'number', 'series' => $countSeries('clicks'), 'tooltip' => 'Všechna kliknutí na reklamy za období, vč. lajků, komentářů a prokliků na profil, ne jen prokliky na web. Pro proklik na web sleduj „Prokliky na odkaz".'],
        // Awareness/traffic metrics — populují se po dalším stažení dat (z Meta `actions`). U účtů bez
        // konverzí jsou tohle ty hlavní výkonnostní metriky.
        ['key' => 'link_clicks', 'label' => 'Prokliky na odkaz', 'icon' => 'external-link', 'color' => 'cyan', 'value' => $sum['link_clicks'], 'format' => 'number', 'series' => $countSeries('link_clicks'), 'tooltip' => 'Kliknutí přímo na odkaz reklamy (na web), bez lajků/komentářů. U traffic kampaní hlavní metrika výkonu.'],
        ['key' => 'cplc', 'label' => 'Cena / proklik na odkaz', 'icon' => 'mouse-pointer-click', 'color' => 'violet', 'value' => $ratio($sum['spend'], $sum['link_clicks']), 'format' => 'money2', 'series' => $derivedSeries('spend', 'link_clicks', 1.0), 'tooltip' => 'Útrata ÷ prokliky na odkaz = kolik stojí jeden proklik na web. Nižší = lepší.'],
        ['key' => 'lp_views', 'label' => 'Zobrazení vstup. stránky', 'icon' => 'file-check-2', 'color' => 'teal', 'value' => $sum['lp_views'], 'format' => 'number', 'series' => $countSeries('lp_views'), 'tooltip' => 'Kolikrát se po prokliku skutečně načetla cílová stránka (landing page view). Bývá nižší než prokliky, část lidí odejde před načtením; velký rozdíl = pomalý/špatný web.'],
        ['key' => 'video_views', 'label' => 'Přehrání videa (3 s)', 'icon' => 'play-circle', 'color' => 'orange', 'value' => $sum['video_views'], 'format' => 'number', 'series' => $countSeries('video_views'), 'tooltip' => 'Kolikrát lidé sledovali video v reklamách aspoň 3 sekundy (u Mety „3sekundová přehrání videa"). Samotné spuštění videa při scrollování se nepočítá.'],
    ];

    $metrics = array_map(static function (array $d): array {
        $v = (float) $d['value'];
        $displayValue = match ($d['format']) {
            'percent' => allstat_percent($v, 2),
            'money0' => allstat_number($v, 0),
            'money2' => allstat_number($v, 2),
            'x' => allstat_number($v, 2) . '×',
            'x2' => allstat_number($v, 2),
            default => allstat_number($v, 0),
        };

        return [
            'key' => $d['key'], 'label' => $d['label'], 'icon' => $d['icon'], 'color' => $d['color'],
            'value' => $v, 'displayValue' => $displayValue, 'series' => $d['series'], 'tooltip' => $d['tooltip'],
        ];
    }, $defs);

    // True when the account reports no conversions AND no conversion value over the range → ROAS/CPA/
    // Konverze/Hodnota would all be 0 not because of a bug but because the account has no conversion tracking
    // (awareness/traffic campaigns). In that case HIDE those four tiles entirely (instead of showing 0s) and
    // let the view render a short note. The awareness tiles (link clicks, video views…) carry the real signal.
    $noConversions = $rows !== [] && $sum['conversions'] <= 0 && $sum['conversion_value'] <= 0;
    if ($noConversions) {
        $metrics = array_values(array_filter($metrics, static fn (array $m): bool => !in_array($m['key'], ['roas', 'pno', 'conversions', 'cpa', 'conversion_value'], true)));
    }

    return [
        'labels' => $labels,
        'metrics' => $metrics,
        'hasData' => $rows !== [],
        'noConversions' => $noConversions,
        'spend' => $sum['spend'],
    ];
}

/**
 * Rich Google Ads PPC KPIs — derives the cost metrics agencies ask for (PNO/CPA/CPC/CTR/CPM/ROAS) from the
 * account-level cost/clicks/impressions/conversions/conversion_value the google_ads engine syncs into
 * provider_metrics_daily. Mirrors allstat_get_meta_ads_kpis (Google uses key `cost`, not `spend`; no
 * reach/frequency). Like Meta Ads, when the account reports no conversions AND no conversion value the
 * ROAS/PNO/CPA/Konverze/Hodnota tiles are hidden (they'd be all zeros — not a bug).
 */
function allstat_get_google_ads_kpis(PDO $pdo, int $domainId, int $connectionId, string $start, string $end, string $granularity = 'day'): array
{
    [$start, $end] = allstat_limited_range($start, $end);
    $baseKeys = ['cost', 'impressions', 'clicks', 'conversions', 'conversion_value'];
    $placeholders = implode(',', array_fill(0, count($baseKeys), '?'));
    try {
        $rows = allstat_fetch_all($pdo, "
            SELECT metric_date, metric_key, metric_value
            FROM provider_metrics_daily
            WHERE domain_id = ? AND connection_id = ? AND dimension = '' AND metric_key IN ($placeholders) AND metric_date BETWEEN ? AND ?
            ORDER BY metric_date ASC
        ", array_merge([$domainId, $connectionId], $baseKeys, [$start, $end]));
    } catch (Throwable) {
        return ['labels' => [], 'metrics' => [], 'hasData' => false];
    }

    $byDate = [];
    foreach ($rows as $r) {
        $byDate[(string) $r['metric_date']][(string) $r['metric_key']] = (float) $r['metric_value'];
    }
    [$byDate, $labels] = allstat_rebucket_daily($byDate, $granularity);
    $dates = array_map('strval', array_keys($byDate));

    $sum = array_fill_keys($baseKeys, 0.0);
    foreach ($byDate as $day) {
        foreach ($baseKeys as $k) { $sum[$k] += $day[$k] ?? 0.0; }
    }
    $ratio = static fn (float $a, float $b, float $scale = 1.0): float => $b > 0.0 ? ($a / $b) * $scale : 0.0;
    $derivedSeries = static fn (string $num, string $den, float $scale): array => array_map(
        static fn (string $d): float => round((float) ($byDate[$d][$den] ?? 0) > 0 ? ((float) ($byDate[$d][$num] ?? 0) / (float) $byDate[$d][$den]) * $scale : 0, 2),
        $dates
    );
    $countSeries = static fn (string $key): array => array_map(static fn (string $d): float => round((float) ($byDate[$d][$key] ?? 0), 2), $dates);

    $defs = [
        ['key' => 'cost', 'label' => 'Útrata', 'icon' => 'wallet', 'color' => 'rose', 'value' => $sum['cost'], 'format' => 'money0', 'series' => $countSeries('cost'), 'tooltip' => 'Kolik jsi za Google Ads utratil za období (součet přes dny), v měně účtu.'],
        ['key' => 'roas', 'label' => 'ROAS', 'icon' => 'trending-up', 'color' => 'green', 'value' => $ratio($sum['conversion_value'], $sum['cost']), 'format' => 'x', 'series' => $derivedSeries('conversion_value', 'cost', 1.0), 'tooltip' => 'Návratnost výdajů = hodnota konverzí ÷ útrata. „3×" = z 1 Kč v reklamě se vrátily 3 Kč tržeb. 0 = účet neměří hodnotu konverzí (není co dělit).'],
        ['key' => 'pno', 'label' => 'PNO', 'icon' => 'percent', 'color' => 'rose', 'value' => $ratio($sum['cost'], $sum['conversion_value'], 100.0), 'format' => 'percent', 'series' => $derivedSeries('cost', 'conversion_value', 100.0), 'tooltip' => 'PNO (podíl nákladů na obratu) = útrata ÷ hodnota konverzí × 100. Kolik % z obratu spolkne reklama, převrácená hodnota ROAS. Nižší = lepší. Skryté u účtů bez měření hodnoty konverzí.'],
        ['key' => 'conversions', 'label' => 'Konverze', 'icon' => 'shopping-cart', 'color' => 'teal', 'value' => $sum['conversions'], 'format' => 'number2', 'series' => $countSeries('conversions'), 'tooltip' => 'Počet konverzí přiřazených Google Ads. Může být desetinný (Google počítá modelované/částečné konverze). 0 = účet nemá konverzní sledování.'],
        ['key' => 'cpa', 'label' => 'CPA', 'icon' => 'target', 'color' => 'orange', 'value' => $ratio($sum['cost'], $sum['conversions']), 'format' => 'money2', 'series' => $derivedSeries('cost', 'conversions', 1.0), 'tooltip' => 'Cena za konverzi = útrata ÷ počet konverzí. Nižší = lepší. Bez konverzí ji nelze spočítat.'],
        ['key' => 'conversion_value', 'label' => 'Hodnota konverzí', 'icon' => 'banknote', 'color' => 'green', 'value' => $sum['conversion_value'], 'format' => 'money0', 'series' => $countSeries('conversion_value'), 'tooltip' => 'Tržby (hodnota konverzí) přiřazené Google Ads. 0 = účet nehlásí hodnotu konverzí.'],
        ['key' => 'ctr', 'label' => 'CTR', 'icon' => 'gauge', 'color' => 'cyan', 'value' => $ratio($sum['clicks'], $sum['impressions'], 100.0), 'format' => 'percent', 'series' => $derivedSeries('clicks', 'impressions', 100.0), 'tooltip' => 'Míra prokliku = kliknutí ÷ zobrazení × 100. V search bývá vyšší než v display/PMax.'],
        ['key' => 'cpc', 'label' => 'CPC', 'icon' => 'mouse-pointer-click', 'color' => 'violet', 'value' => $ratio($sum['cost'], $sum['clicks']), 'format' => 'money2', 'series' => $derivedSeries('cost', 'clicks', 1.0), 'tooltip' => 'Cena za kliknutí = útrata ÷ kliknutí.'],
        ['key' => 'cpm', 'label' => 'CPM', 'icon' => 'eye', 'color' => 'orange', 'value' => $ratio($sum['cost'], $sum['impressions'], 1000.0), 'format' => 'money2', 'series' => $derivedSeries('cost', 'impressions', 1000.0), 'tooltip' => 'Cena za 1 000 zobrazení = útrata ÷ zobrazení × 1 000.'],
        ['key' => 'clicks', 'label' => 'Kliknutí', 'icon' => 'mouse-pointer-click', 'color' => 'cyan', 'value' => $sum['clicks'], 'format' => 'number', 'series' => $countSeries('clicks'), 'tooltip' => 'Počet kliknutí na reklamy za období.'],
        ['key' => 'impressions', 'label' => 'Zobrazení', 'icon' => 'eye', 'color' => 'blue', 'value' => $sum['impressions'], 'format' => 'number', 'series' => $countSeries('impressions'), 'tooltip' => 'Kolikrát se reklamy zobrazily celkem.'],
    ];

    $metrics = array_map(static function (array $d): array {
        $v = (float) $d['value'];
        $displayValue = match ($d['format']) {
            'percent' => allstat_percent($v, 2),
            'money0' => allstat_number($v, 0),
            'money2' => allstat_number($v, 2),
            'number2' => allstat_number($v, 2),
            'x' => allstat_number($v, 2) . '×',
            default => allstat_number($v, 0),
        };

        return [
            'key' => $d['key'], 'label' => $d['label'], 'icon' => $d['icon'], 'color' => $d['color'],
            'value' => $v, 'displayValue' => $displayValue, 'series' => $d['series'], 'tooltip' => $d['tooltip'],
        ];
    }, $defs);

    $noConversions = $rows !== [] && $sum['conversions'] <= 0 && $sum['conversion_value'] <= 0;
    if ($noConversions) {
        $metrics = array_values(array_filter($metrics, static fn (array $m): bool => !in_array($m['key'], ['roas', 'pno', 'conversions', 'cpa', 'conversion_value'], true)));
    }

    return [
        'labels' => $labels,
        'metrics' => $metrics,
        'hasData' => $rows !== [],
        'noConversions' => $noConversions,
        'spend' => $sum['cost'],
    ];
}

/**
 * Kampaně Google Ads za období (klíče campaign_* s názvem kampaně v dimension, plní je google_ads engine od 1.2.4).
 * Seřazené podle útraty; poměry (CTR, CPC, CPA, ROAS) ze součtů za období, null když nejdou spočítat.
 *
 * @return list<array<string, mixed>>
 */
function allstat_get_google_ads_campaigns(PDO $pdo, int $domainId, int $connectionId, string $start, string $end, int $limit = 15): array
{
    [$start, $end] = allstat_limited_range($start, $end);
    try {
        $rows = allstat_fetch_all($pdo, "
            SELECT dimension AS name, metric_key, SUM(metric_value) AS total, MIN(metric_date) AS first_day, MAX(metric_date) AS last_day, COUNT(DISTINCT metric_date) AS days
            FROM provider_metrics_daily
            WHERE domain_id = ? AND connection_id = ? AND dimension <> '' AND metric_date BETWEEN ? AND ?
              AND metric_key IN ('campaign_cost', 'campaign_clicks', 'campaign_impressions', 'campaign_conversions', 'campaign_value')
            GROUP BY dimension, metric_key
        ", [$domainId, $connectionId, $start, $end]);
    } catch (Throwable) {
        return [];
    }

    $campaigns = [];
    foreach ($rows as $r) {
        $name = (string) $r['name'];
        $campaigns[$name] ??= ['name' => $name, 'cost' => 0.0, 'clicks' => 0.0, 'impressions' => 0.0, 'conversions' => 0.0, 'value' => 0.0, 'from' => null, 'to' => null, 'days' => 0];
        $field = substr((string) $r['metric_key'], strlen('campaign_'));
        $campaigns[$name][$field] = (float) $r['total'];
        if ($field === 'cost' || $campaigns[$name]['from'] === null) {
            $campaigns[$name]['from'] = (string) $r['first_day'];
            $campaigns[$name]['to'] = (string) $r['last_day'];
            $campaigns[$name]['days'] = (int) $r['days'];
        }
    }
    $campaigns = array_values(array_filter($campaigns, static fn (array $c): bool => $c['cost'] > 0 || $c['impressions'] > 0));
    usort($campaigns, static fn (array $a, array $b): int => [$b['cost'], $b['impressions']] <=> [$a['cost'], $a['impressions']]);

    return array_map(static function (array $c): array {
        $c['ctr'] = $c['impressions'] > 0 ? $c['clicks'] / $c['impressions'] * 100 : null;
        $c['cpc'] = $c['clicks'] > 0 ? $c['cost'] / $c['clicks'] : null;
        $c['cpa'] = $c['conversions'] > 0 ? $c['cost'] / $c['conversions'] : null;
        $c['roas'] = $c['cost'] > 0 && $c['value'] > 0 ? $c['value'] / $c['cost'] : null;
        $c['runLabel'] = allstat_meta_ads_run_label(['from' => (string) $c['from'], 'to' => (string) $c['to'], 'days' => $c['days']]);

        return $c;
    }, array_slice($campaigns, 0, max(1, $limit)));
}

/**
 * Seznam Webmaster indexation KPIs. The counts are a daily SNAPSHOT (state), not additive events, so the
 * KPI value is the LATEST day in range (never a SUM), while the sparkline/trend shows the whole series.
 * Mirrors the shared provider-view payload shape (labels + metrics[] + hasData) so index.php renders it
 * with the standard KPI grid + trend chart.
 */
function allstat_get_seznam_wmt_kpis(PDO $pdo, int $domainId, int $connectionId, string $start, string $end): array
{
    [$start, $end] = allstat_limited_range($start, $end);
    $keys = ['indexed', 'content', 'downloaded', 'error', 'redirected', 'doc_count'];
    $ph = implode(',', array_fill(0, count($keys), '?'));
    try {
        $rows = allstat_fetch_all($pdo, "
            SELECT metric_date, metric_key, metric_value
            FROM provider_metrics_daily
            WHERE domain_id = ? AND connection_id = ? AND dimension = '' AND metric_key IN ($ph) AND metric_date BETWEEN ? AND ?
            ORDER BY metric_date ASC
        ", array_merge([$domainId, $connectionId], $keys, [$start, $end]));
    } catch (Throwable) {
        return ['labels' => [], 'metrics' => [], 'hasData' => false];
    }

    $byDate = [];
    foreach ($rows as $r) {
        $byDate[(string) $r['metric_date']][(string) $r['metric_key']] = (float) $r['metric_value'];
    }
    ksort($byDate);
    $dates = array_keys($byDate);
    $labels = array_map(static fn (string $d): string => (new DateTimeImmutable($d))->format('j. n.'), $dates);
    $latest = $dates ? $byDate[$dates[count($dates) - 1]] : [];

    $meta = allstat_provider_metric_meta('seznam_wmt');
    $series = static fn (string $k): array => array_map(static fn (string $d): float => round((float) ($byDate[$d][$k] ?? 0), 0), $dates);

    $metrics = [];
    foreach ($keys as $k) {
        $m = $meta[$k] ?? ['label' => $k, 'icon' => 'file', 'color' => 'blue', 'tooltip' => ''];
        $v = (float) ($latest[$k] ?? 0);
        $metrics[] = [
            'key' => $k, 'label' => $m['label'], 'icon' => $m['icon'], 'color' => $m['color'],
            'value' => $v, 'displayValue' => allstat_number($v, 0), 'series' => $series($k), 'tooltip' => $m['tooltip'],
        ];
    }

    return ['labels' => $labels, 'metrics' => $metrics, 'hasData' => $rows !== []];
}

/**
 * Data-driven recommendations for a Meta Ads connection: examines the account + per-campaign metrics and
 * returns plain-Czech findings (level good|warn|bad|info + title + detail) about what's inefficient or
 * likely misconfigured. Heuristics use orientational benchmarks — they flag candidates, not verdicts.
 */
function allstat_get_meta_ads_recommendations(PDO $pdo, int $domainId, int $connectionId, string $start, string $end): array
{
    [$start, $end] = allstat_limited_range($start, $end);
    $keys = ['spend', 'impressions', 'clicks', 'reach', 'conversions', 'conversion_value', 'link_clicks', 'lp_views', 'video_views'];
    try {
        $ph = implode(',', array_fill(0, count($keys), '?'));
        $rows = allstat_fetch_all($pdo, "
            SELECT metric_key, SUM(metric_value) AS v FROM provider_metrics_daily
            WHERE domain_id = ? AND connection_id = ? AND dimension = '' AND metric_key IN ($ph) AND metric_date BETWEEN ? AND ?
            GROUP BY metric_key
        ", array_merge([$domainId, $connectionId], $keys, [$start, $end]));
    } catch (Throwable) {
        return [];
    }
    // Kampaně s cílem, prokliky na odkaz a frekvencí za celou dobu (allstat_get_meta_ads_breakdowns).
    $camps = array_values(array_filter(
        allstat_get_meta_ads_breakdowns($pdo, $domainId, $connectionId, $start, $end, 50),
        static fn (array $c): bool => $c['spend'] > 0
    ));

    $s = array_fill_keys($keys, 0.0);
    foreach ($rows as $r) { $s[(string) $r['metric_key']] = (float) $r['v']; }
    if ($s['spend'] <= 0 && $s['impressions'] <= 0) {
        return [];
    }

    $freqDaily = $s['reach'] > 0 ? $s['impressions'] / $s['reach'] : 0.0;
    $lpDrop = $s['link_clicks'] > 0 ? (1 - $s['lp_views'] / $s['link_clicks']) * 100 : 0.0;

    // Kampaně na povědomí a interakce Meta ukazuje co nejvíc lidem, ne těm, kdo kliknou, takže mají přirozeně
    // nízkou míru prokliku i drahé kliknutí. Proklikové metriky se proto počítají jen z kampaní, které mají
    // přivádět lidi na web. Bez známého cíle (před prvním syncem souhrnů) se bere celý účet jako dřív. Dřív se
    // sčítalo vše dohromady a report 9/2026 z toho vyvodil, že kampaň na povědomí je „nejslabší".
    $sumOf = static fn (array $list, string $k): float => (float) array_sum(array_column($list, $k));
    $hasGoals = array_filter($camps, static fn (array $c): bool => $c['goalKind'] !== '') !== [];
    $clickCamps = $hasGoals ? array_values(array_filter($camps, static fn (array $c): bool => allstat_meta_ads_click_goal($c['goalKind']))) : $camps;
    $awareCamps = array_values(array_filter($camps, static fn (array $c): bool => $c['goalKind'] === 'awareness'));
    if ($hasGoals) {
        $cImpr = $sumOf($clickCamps, 'impressions');
        $cLink = $sumOf($clickCamps, 'linkClicks');
        $linkCtr = $cImpr > 0 ? $cLink / $cImpr * 100 : 0.0;
        $cplc = $cLink > 0 ? $sumOf($clickCamps, 'spend') / $cLink : 0.0;
    } else {
        $linkCtr = $s['impressions'] > 0 ? $s['link_clicks'] / $s['impressions'] * 100 : 0.0;
        $cplc = $s['link_clicks'] > 0 ? $s['spend'] / $s['link_clicks'] : 0.0;
    }
    $scope = $hasGoals ? ' u kampaní na návštěvnost webu' : '';

    $rec = [];
    $add = static function (string $level, string $title, string $detail) use (&$rec): void {
        $rec[] = ['level' => $level, 'title' => $title, 'detail' => $detail];
    };
    $n = static fn (float $v, int $d = 0): string => allstat_number($v, $d);

    // 1) Frekvence / únava publika. Rozhoduje frekvence za CELOU dobu kampaně z Meta. Denní frekvence
    //    (zobrazení ÷ součet denních dosahů) vychází skoro vždy kolem 1,0 a o opakování za období nic neříká,
    //    proto se z ní „zdravá frekvence, přidej rozpočet" už nevyvozuje.
    $life = array_values(array_filter($camps, static fn (array $c): bool => $c['frequencyLifetime'] > 0 && $c['impressionsLifetime'] >= 1000));
    if ($life) {
        usort($life, static fn (array $a, array $b): int => $b['frequencyLifetime'] <=> $a['frequencyLifetime']);
        $top = $life[0];
        $f = (float) $top['frequencyLifetime'];
        $kdo = 'kampaň „' . $top['name'] . '"';
        if ($f >= 4) {
            $add('bad', 'Vysoká frekvence (' . $n($f, 1) . '×), únava publika', 'Za celou dobu viděl ' . $kdo . ' stejný člověk v průměru ' . $n($f, 1) . '×. Publikum je přesycené, roste cena a klesají prokliky. Rozšiř cílení, vyměň kreativu nebo sniž rozpočet.');
        } elseif ($f >= 3) {
            $add('warn', 'Zvýšená frekvence (' . $n($f, 1) . '×)', 'Za celou dobu viděl ' . $kdo . ' stejný člověk v průměru ' . $n($f, 1) . '×. Publikum se začíná přesycovat, připrav obměnu kreativy nebo širší publikum.');
        } elseif ($f < 2 && $s['impressions'] > 2000) {
            $add('good', 'Publikum není přesycené (nejvýš ' . $n($f, 1) . '×)', 'Nejčastěji se opakovala ' . $kdo . ': stejný člověk ji za celou dobu viděl v průměru ' . $n($f, 1) . '×, u ostatních kampaní je to méně. Je prostor přidat rozpočet bez únavy publika.');
        } elseif ($f >= 2) {
            $add('info', 'Frekvence v normě (nejvýš ' . $n($f, 1) . '×)', 'Nejčastěji se opakovala ' . $kdo . ': stejný člověk ji za celou dobu viděl v průměru ' . $n($f, 1) . '×. To je ještě v pořádku, od 3× se publikum začíná přesycovat. Při delším běhu ji hlídej a včas obměň kreativu.');
        }
    } elseif ($freqDaily >= 3) {
        $add('warn', 'Vysoká denní frekvence (' . $n($freqDaily, 1) . '×)', 'Stejný člověk viděl reklamy v průměru ' . $n($freqDaily, 1) . '× za jediný den. Publikum je nejspíš příliš malé, rozšiř cílení nebo sniž denní rozpočet.');
    }

    // 2) Link CTR (the meaningful click-through to the web), jen kampaně, které mají přivádět na web.
    if ($linkCtr > 0 && $linkCtr < 0.7) {
        $add('warn', 'Nízká míra prokliku na odkaz (' . $n($linkCtr, 2) . ' %)', 'Z lidí, kterým se reklama ukázala, kliklo na odkaz jen ' . $n($linkCtr, 2) . ' %' . $scope . '. Orientačně se čeká 0,7–1,5 %. Slabý hook/vizuál nebo nepřesné cílení, vyzkoušej jiný úvod videa/obrázek nebo užší publikum.' . ($hasGoals ? '' : ' Kampaně na povědomí mají nízkou míru prokliku přirozeně, ty z toho vynech.'));
    } elseif ($linkCtr >= 1.5) {
        $add('good', 'Dobrá míra prokliku (' . $n($linkCtr, 2) . ' %)', ($hasGoals ? 'Kampaně na návštěvnost webu mají dobrou kreativu i cílení' : 'Kreativa i cílení rezonují') . ', drž a škáluj, co funguje.');
    }

    // 3) Landing-page drop (link clicks vs landing-page views) = web/odkaz problem.
    if ($s['link_clicks'] >= 50 && $lpDrop >= 35) {
        $add('warn', 'Rozdíl prokliky vs. načtení stránky (−' . $n($lpDrop) . ' %), ověř cookie souhlas', 'Na odkaz kliklo ' . $n($s['link_clicks']) . ' lidí, ale Meta Pixel zaznamenal načtení vstupní stránky jen ' . $n($s['lp_views']) . '× (rozdíl ' . $n($lpDrop) . ' %). V EU to nejčastěji NENÍ ztráta lidí, ale MĚŘENÍ: cookie/consent lišta blokuje Meta Pixel, dokud návštěvník neodsouhlasí cookies, proklik se započítá, ale „načtení stránky" (pixel) ne. Nejdřív ověř míru souhlasu v cookie liště (běžně 30–60 % odmítne); teprve pak řeš rychlost webu, špatnou cílovou URL nebo mezistránku.');
    }

    // 4) No conversion tracking.
    if ($s['conversions'] <= 0 && $s['conversion_value'] <= 0 && $s['spend'] > 0) {
        $add('info', 'Účet neměří konverze', 'Reklama utrácí (' . $n($s['spend']) . '), ale neměří žádné výsledky (poptávky/nákupy). Pokud je chceš sledovat (a počítat ROAS), nasaď Meta Pixel + konverzní události na web. Bez toho hodnoť podle prokliků a dosahu, což je u awareness kampaní v pořádku.');
    }

    // 5) CPC orientation (only when clearly high, to avoid false alarms across markets).
    if ($cplc >= 15) {
        $add('warn', 'Vyšší cena za proklik na odkaz (' . $n($cplc, 2) . ')', 'Jeden proklik na web tě' . $scope . ' stojí ' . $n($cplc, 2) . '. To bývá hodně, zkus lepší kreativu/cílení nebo levnější umístění.');
    }

    // 6) Video views without clicks → missing CTA. Přehrání = aspoň 3 s. U kampaní na povědomí prokliky nejsou
    //    cílem, proto se berou jen kampaně na návštěvnost, když jsou přehrání po kampaních k dispozici.
    if ($hasGoals && $sumOf($camps, 'videoViews') > 0) {
        $vv = $sumOf($clickCamps, 'videoViews');
        $lc = $sumOf($clickCamps, 'linkClicks');
        $vvNote = '';
    } else {
        $vv = $s['video_views'];
        $lc = $s['link_clicks'];
        $vvNote = $awareCamps ? ' Část přehrání je z kampaní na povědomí, kde prokliky nejsou cílem.' : '';
    }
    if ($vv >= 500 && $lc > 0 && $vv > $lc * 6) {
        $add('warn', 'Hodně přehrání videa, málo prokliků', 'Video v reklamách lidé aspoň 3 sekundy sledovali ' . $n($vv) . '×, ale na odkaz kliklo jen ' . $n($lc) . '×. Chybí jasná výzva k akci (CTA) nebo lidé nevědí, co mají udělat, přidej do videa/popisku jasné „Klikni / Zjisti víc".' . $vvNote);
    }

    // 7) Per-campaign outlier: nejhorší míra prokliku NA ODKAZ mezi kampaněmi na návštěvnost s rozumnou útratou.
    //    Dřív se brala míra všech kliknutí a srovnávaly se i kampaně na povědomí, takže „slabší" vycházela vždy
    //    kampaň na povědomí, i když dělala přesně to, co měla.
    if (count($clickCamps) >= 2) {
        $totalSpend = $sumOf($clickCamps, 'spend');
        $groupImpr = $sumOf($clickCamps, 'impressions');
        $avgCtr = $groupImpr > 0 ? $sumOf($clickCamps, 'linkClicks') / $groupImpr * 100 : 0.0;
        $worst = null; $worstCtr = INF;
        foreach ($clickCamps as $c) {
            if ($c['impressions'] < 500 || $c['spend'] < $totalSpend * 0.1) { continue; }
            if ($c['linkCtr'] < $worstCtr) { $worstCtr = (float) $c['linkCtr']; $worst = $c; }
        }
        if ($worst && $worstCtr > 0 && $avgCtr > 0 && $worstCtr < $avgCtr * 0.6) {
            $w = allstat_rate_in_words($worstCtr, $avgCtr);
            $detail = 'Na odkaz ' . $w['klikl'] . ' jen ' . $w['a'] . ' lidí, kterým se reklama ukázala. ' . ($hasGoals ? 'V průměru kampaní na návštěvnost webu' : 'V průměru účtu') . ' je to ' . $w['b'] . '. Jeden proklik na web stál ' . $n((float) $worst['cplc'], 2) . ' při útratě ' . $n((float) $worst['spend']) . '. Kandidát na úpravu cílení/kreativy, nebo pozastavení a přesun rozpočtu k lepším.';
            if (!$hasGoals) {
                $detail .= ' Pokud jde o kampaň na povědomí, je nízká míra prokliku v pořádku; tu hodnoť podle ceny za 1 000 zobrazení (' . $n((float) $worst['cpm'], 2) . ').';
            }
            $add('warn', 'Slabší kampaň: „' . (string) $worst['name'] . '"', $detail);
        }
    }

    // 8) Kampaně na povědomí: podle čeho je hodnotit, s jejich čísly (cena za 1 000 zobrazení, lidé a frekvence
    //    za celou dobu), aby z nízké míry prokliku nikdo nevyvodil, že jsou „slabé".
    if ($awareCamps) {
        usort($awareCamps, static fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);
        $parts = [];
        foreach (array_slice($awareCamps, 0, 3) as $c) {
            $p = '„' . $c['name'] . '": 1 000 zobrazení za ' . $n((float) $c['cpm'], 2);
            if ($c['frequencyLifetime'] > 0) {
                $p .= ', za celou dobu ' . $n((float) $c['reachLifetime']) . ' lidí, každý ji viděl v průměru ' . $n((float) $c['frequencyLifetime'], 2) . '×';
            }
            $parts[] = $p;
        }
        $add('info', 'Kampaně na povědomí hodnoť podle dosahu, ne kliknutí', 'Meta je ukazuje co nejvíc lidem, ne těm, kdo kliknou, proto mají přirozeně nízkou míru prokliku a drahé kliknutí. Rozhoduje cena za 1 000 zobrazení a frekvence. ' . implode('; ', $parts) . '.');
    }

    if (!$rec) {
        $add('good', 'Bez zásadních varování', 'Z dostupných metrik za období nevidím žádný výrazný problém. Pokračuj a sleduj frekvenci a míru prokliku.');
    }

    return $rec;
}

/**
 * Demografie a umístění reklam (Meta Ads breakdowns z API): age / gender / region / publisher_platform,
 * impressions per hodnota sečtené přes období, s podílem v %. Položky mají tvar ['label','value','share']
 * (stejný jako IG demografie → sdílený bar renderer). Prázdné sloty = breakdown bez dat / opt-in po 6.8.2026.
 */
function allstat_get_meta_ads_demographics(PDO $pdo, int $connectionId, string $start, string $end): array
{
    $out = ['age' => [], 'gender' => [], 'region' => [], 'platform' => [], 'hasData' => false];
    try {
        $map = ['ads_age' => 'age', 'ads_gender' => 'gender', 'ads_region' => 'region', 'ads_platform' => 'platform'];
        $rows = allstat_fetch_all($pdo, "
            SELECT metric_key, dimension, SUM(metric_value) AS v
            FROM provider_metrics_daily
            WHERE connection_id = ? AND metric_key IN ('ads_age','ads_gender','ads_region','ads_platform')
              AND metric_date BETWEEN ? AND ? AND dimension <> ''
            GROUP BY metric_key, dimension
        ", [$connectionId, $start, $end]);
        $bySlot = [];
        foreach ($rows as $r) {
            $slot = $map[(string) $r['metric_key']] ?? null;
            if ($slot === null) { continue; }
            $bySlot[$slot][] = ['dim' => (string) $r['dimension'], 'v' => (float) $r['v']];
        }
        $genderLabel = static fn (string $g): string => ['male' => 'Muži', 'female' => 'Ženy', 'unknown' => 'Neuvedeno'][strtolower($g)] ?? $g;
        $platLabel = static fn (string $p): string => ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'audience_network' => 'Audience Network', 'messenger' => 'Messenger'][strtolower($p)] ?? ucfirst($p);
        foreach ($bySlot as $slot => $items) {
            usort($items, static fn (array $a, array $b): int => $b['v'] <=> $a['v']);
            $total = array_sum(array_column($items, 'v'));
            $items = array_slice($items, 0, $slot === 'region' ? 8 : 12);
            foreach ($items as $it) {
                $label = match ($slot) { 'gender' => $genderLabel($it['dim']), 'platform' => $platLabel($it['dim']), default => $it['dim'] };
                $out[$slot][] = ['label' => $label, 'value' => (int) round($it['v']), 'share' => $total > 0 ? $it['v'] / $total * 100 : 0];
            }
        }
        $out['hasData'] = $out['age'] || $out['gender'] || $out['region'] || $out['platform'];
    } catch (Throwable) { /* ads demografie zatím bez dat */ }
    return $out;
}

/**
 * Cíl kampaně z Meta API (objective, i staré hodnoty z doby před ODAX) → český název, jak ho ukazuje Správce
 * reklam, a druh, podle kterého se kampaň hodnotí: 'awareness' (povědomí, zhlédnutí videa: cena za 1 000
 * zobrazení a frekvence), 'engagement' (interakce), 'traffic', 'leads', 'sales', 'app', nebo '' = neznámý
 * (před prvním syncem souhrnů; bere se jako proklikový, stejně jako dřív).
 */
function allstat_meta_ads_objective(string $code): array
{
    $code = strtoupper(trim($code));
    [$label, $kind] = match ($code) {
        '' => ['', ''],
        'OUTCOME_AWARENESS', 'BRAND_AWARENESS', 'REACH', 'LOCAL_AWARENESS' => ['Povědomí', 'awareness'],
        'VIDEO_VIEWS' => ['Zhlédnutí videa', 'awareness'],
        'OUTCOME_TRAFFIC', 'LINK_CLICKS' => ['Návštěvnost', 'traffic'],
        'OUTCOME_ENGAGEMENT', 'POST_ENGAGEMENT', 'PAGE_LIKES', 'EVENT_RESPONSES', 'MESSAGES' => ['Interakce', 'engagement'],
        'OUTCOME_LEADS', 'LEAD_GENERATION' => ['Potenciální zákazníci', 'leads'],
        'OUTCOME_SALES', 'PRODUCT_CATALOG_SALES' => ['Prodej', 'sales'],
        'CONVERSIONS' => ['Konverze', 'sales'],
        'OUTCOME_APP_PROMOTION', 'APP_INSTALLS' => ['Propagace aplikace', 'app'],
        default => [ucfirst(strtolower(str_replace('_', ' ', $code))), ''],
    };

    return ['code' => $code, 'label' => $label, 'kind' => $kind];
}

/**
 * Hodnotí se kampaň podle prokliků na web? Povědomí a interakce ne: Meta je ukazuje co nejvíc lidem (nebo
 * těm, kdo reagují na příspěvek), ne těm, kdo kliknou na odkaz, takže mají přirozeně nízkou míru prokliku.
 */
function allstat_meta_ads_click_goal(string $kind): bool
{
    return !in_array($kind, ['awareness', 'engagement'], true);
}

/**
 * Optimalizace sestavy (optimization_goal z Meta API) → český popis; neznámé hodnoty se jen „polidští".
 */
function allstat_meta_ads_optimization_label(string $code): string
{
    $code = strtoupper(trim($code));

    return match ($code) {
        '' => '',
        'REACH' => 'Dosah',
        'IMPRESSIONS' => 'Zobrazení',
        'AD_RECALL_LIFT' => 'Zapamatování reklamy',
        'THRUPLAY' => 'ThruPlay (dokoukání videa)',
        'TWO_SECOND_CONTINUOUS_VIDEO_VIEWS' => 'Souvislé přehrání videa 2 s',
        'LINK_CLICKS' => 'Kliknutí na odkaz',
        'LANDING_PAGE_VIEWS' => 'Zobrazení vstupní stránky',
        'POST_ENGAGEMENT' => 'Interakce s příspěvkem',
        'PAGE_LIKES' => 'Označení stránky Líbí se',
        'EVENT_RESPONSES' => 'Odpovědi na událost',
        'OFFSITE_CONVERSIONS' => 'Konverze na webu',
        'LEAD_GENERATION', 'QUALITY_LEAD' => 'Potenciální zákazníci',
        'CONVERSATIONS' => 'Konverzace',
        'VALUE' => 'Hodnota konverzí',
        'APP_INSTALLS' => 'Instalace aplikace',
        'VISIT_INSTAGRAM_PROFILE', 'PROFILE_VISIT' => 'Návštěvy profilu',
        default => ucfirst(strtolower(str_replace('_', ' ', $code))),
    };
}

/**
 * Souhrny za celou dobu (meta_ads_totals, plní allstat_meta_ads_sync_totals) pro jednu úroveň, klíčované
 * jménem stejně jako `dimension` v provider_metrics_daily. Má-li víc entit stejné jméno, vyhraje ta s nejvíc
 * zobrazeními: dosah různých kampaní sčítat nejde, lidé se překrývají. Tabulka ještě není / prázdná → [].
 */
function allstat_meta_ads_totals_by_name(PDO $pdo, int $connectionId, string $level): array
{
    try {
        $rows = allstat_fetch_all($pdo, '
            SELECT name, objective, optimization_goal, impressions, reach, spend
            FROM meta_ads_totals
            WHERE connection_id = ? AND level = ?
        ', [$connectionId, $level]);
    } catch (Throwable) {
        return [];
    }

    $out = [];
    foreach ($rows as $r) {
        $name = (string) $r['name'];
        $impr = (float) $r['impressions'];
        if (isset($out[$name]) && $out[$name]['impressions'] >= $impr) {
            continue;
        }
        $reach = (float) $r['reach'];
        $out[$name] = [
            'objective' => (string) $r['objective'],
            'optimization' => (string) $r['optimization_goal'],
            'impressions' => $impr,
            'reach' => $reach,
            'spend' => (float) $r['spend'],
            'frequency' => $reach > 0 ? $impr / $reach : 0.0,
        ];
    }

    return $out;
}

/**
 * První a poslední den s útratou podle synchronizovaných denních dat, za celou dostupnou historii (ne jen za
 * zvolené období), aby bylo vidět, kdy kampaň běžela. $prefix = 'campaign' | 'adset' | 'ad'; klíč = jméno.
 */
function allstat_meta_ads_run_dates(PDO $pdo, int $domainId, int $connectionId, string $prefix, array $names): array
{
    $names = array_values(array_unique(array_filter(array_map('strval', $names), static fn (string $n): bool => $n !== '')));
    if (!$names) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($names), '?'));
    try {
        $rows = allstat_fetch_all($pdo, "
            SELECT dimension AS name, MIN(metric_date) AS first_day, MAX(metric_date) AS last_day, COUNT(DISTINCT metric_date) AS days
            FROM provider_metrics_daily
            WHERE domain_id = ? AND connection_id = ? AND metric_key = ? AND metric_value > 0 AND dimension IN ($ph)
            GROUP BY dimension
        ", array_merge([$domainId, $connectionId, $prefix . '_spend'], $names));
    } catch (Throwable) {
        return [];
    }

    $out = [];
    foreach ($rows as $r) {
        $out[(string) $r['name']] = ['from' => (string) $r['first_day'], 'to' => (string) $r['last_day'], 'days' => (int) $r['days']];
    }

    return $out;
}

/**
 * „16. 9.–29. 9. 2026" (rok jen jednou, když se nemění); jediný den → „16. 9. 2026"; bez dat → ''.
 * Měla-li kampaň mezi prvním a posledním dnem pauzu, přidá počet dní s útratou, ať rozpětí nevypadá
 * jako nepřetržitý běh („30. 7.–30. 9. 2026 (28 dní s útratou)").
 */
function allstat_meta_ads_run_label(?array $run): string
{
    if (!$run || (string) ($run['from'] ?? '') === '') {
        return '';
    }
    try {
        $a = new DateTimeImmutable((string) $run['from']);
        $b = new DateTimeImmutable((string) (($run['to'] ?? '') !== '' ? $run['to'] : $run['from']));
    } catch (Throwable) {
        return '';
    }
    if ($a->format('Y-m-d') === $b->format('Y-m-d')) {
        return $a->format('j. n. Y');
    }

    $label = ($a->format('Y') === $b->format('Y') ? $a->format('j. n.') : $a->format('j. n. Y')) . '–' . $b->format('j. n. Y');
    $days = (int) ($run['days'] ?? 0);
    if ($days > 0 && $days < $a->diff($b)->days + 1) {
        $label .= ' (' . $days . ' ' . ($days === 1 ? 'den' : ($days <= 4 ? 'dny' : 'dní')) . ' s útratou)';
    }

    return $label;
}

/**
 * Top campaigns for a Meta Ads connection (dimensioned campaign_* rows summed over the range), ordered by spend.
 * Vedle ROAS/CPA nese metriky, podle kterých se kampaně dají poctivě porovnat: zobrazení a cenu za 1 000
 * zobrazení (CPM), prokliky NA ODKAZ a cenu za ně (= přivedení člověka na web), přehrání videa na 3 s, cíl
 * kampaně a frekvenci za CELOU dobu z Meta. Klíče ctrLabel/cpcLabel zůstávají kvůli zpětné kompatibilitě,
 * ale počítají VŠECHNA kliknutí (Meta `clicks`: i lajky, jméno stránky, rozbalení textu), ne prokliky na web.
 * Returns [] when there's no campaign breakdown.
 */
function allstat_get_meta_ads_breakdowns(PDO $pdo, int $domainId, int $connectionId, string $start, string $end, int $limit = 10): array
{
    [$start, $end] = allstat_limited_range($start, $end);
    $cols = ['spend', 'value', 'conversions', 'impressions', 'clicks', 'link_clicks', 'video_views'];
    $select = [];
    $keys = [];
    foreach ($cols as $c) {
        $key = 'campaign_' . $c;
        $keys[] = $pdo->quote($key);
        $select[] = 'SUM(CASE WHEN metric_key = ' . $pdo->quote($key) . ' THEN metric_value ELSE 0 END) AS ' . $c;
    }
    try {
        $rows = allstat_fetch_all($pdo, '
            SELECT dimension AS name, ' . implode(', ', $select) . '
            FROM provider_metrics_daily
            WHERE domain_id = ? AND connection_id = ? AND dimension <> \'\' AND metric_date BETWEEN ? AND ?
              AND metric_key IN (' . implode(',', $keys) . ')
            GROUP BY dimension
            ORDER BY spend DESC
            LIMIT ' . (int) $limit . '
        ', [$domainId, $connectionId, $start, $end]);
    } catch (Throwable) {
        return [];
    }

    $totals = allstat_meta_ads_totals_by_name($pdo, $connectionId, 'campaign');
    $runs = allstat_meta_ads_run_dates($pdo, $domainId, $connectionId, 'campaign', array_column($rows, 'name'));

    return array_map(static function (array $r) use ($totals, $runs): array {
        $name = (string) $r['name'];
        $spend = (float) $r['spend'];
        $value = (float) $r['value'];
        $conv = (float) $r['conversions'];
        $impr = (float) $r['impressions'];
        $clicks = (float) $r['clicks'];
        $linkClicks = (float) $r['link_clicks'];
        $videoViews = (float) $r['video_views'];
        $cpm = $impr > 0 ? $spend / $impr * 1000 : 0.0;
        $cplc = $linkClicks > 0 ? $spend / $linkClicks : 0.0;
        $linkCtr = $impr > 0 ? $linkClicks / $impr * 100 : 0.0;
        $life = $totals[$name] ?? null;
        $goal = allstat_meta_ads_objective((string) ($life['objective'] ?? ''));
        $freqLife = (float) ($life['frequency'] ?? 0.0);
        $reachLife = (float) ($life['reach'] ?? 0.0);
        $run = $runs[$name] ?? null;

        return [
            'name' => $name,
            'spend' => $spend,
            'spendLabel' => allstat_number($spend, 0),
            'conversionsLabel' => allstat_number($conv, 0),
            'roasLabel' => $spend > 0 && $value > 0 ? allstat_number($value / $spend, 2) . '×' : '—',
            'cpaLabel' => $conv > 0 ? allstat_number($spend / $conv, 2) : '—',
            // Všechna kliknutí (vše), viz docblock.
            'clicks' => $clicks,
            'clicksLabel' => $clicks > 0 ? allstat_number($clicks, 0) : '—',
            'ctrLabel' => $impr > 0 ? allstat_percent($clicks / $impr * 100, 2) : '—',
            'cpcLabel' => $clicks > 0 ? allstat_number($spend / $clicks, 2) : '—',
            'impressions' => $impr,
            'impressionsLabel' => $impr > 0 ? allstat_number($impr, 0) : '—',
            'cpm' => $cpm,
            'cpmLabel' => $cpm > 0 ? allstat_number($cpm, 2) : '—',
            // Prokliky NA ODKAZ = přechod na web (Business Suite: „Kliknutí na odkaz", „Za kliknutí na odkaz").
            'linkClicks' => $linkClicks,
            'linkClicksLabel' => $linkClicks > 0 ? allstat_number($linkClicks, 0) : '—',
            'cplc' => $cplc,
            'cplcLabel' => $cplc > 0 ? allstat_number($cplc, 2) : '—',
            'linkCtr' => $linkCtr,
            'linkCtrLabel' => $impr > 0 ? allstat_percent($linkCtr, 2) : '—',
            'videoViews' => $videoViews,
            'videoViewsLabel' => $videoViews > 0 ? allstat_number($videoViews, 0) : '—',
            'hookLabel' => $impr > 0 && $videoViews > 0 ? allstat_percent($videoViews / $impr * 100, 1) : '—',
            // Cíl a souhrn za CELOU dobu kampaně (z Meta, ne ze zvoleného období).
            'goal' => $goal['code'],
            'goalLabel' => $goal['label'],
            'goalKind' => $goal['kind'],
            'runFrom' => (string) ($run['from'] ?? ''),
            'runTo' => (string) ($run['to'] ?? ''),
            'runDays' => (int) ($run['days'] ?? 0),
            'runLabel' => allstat_meta_ads_run_label($run),
            'impressionsLifetime' => (float) ($life['impressions'] ?? 0.0),
            'reachLifetime' => $reachLife,
            'reachLifetimeLabel' => $reachLife > 0 ? allstat_number($reachLife, 0) : '—',
            'frequencyLifetime' => $freqLife,
            'frequencyLifetimeLabel' => $freqLife > 0 ? allstat_number($freqLife, 2) : '—',
            'fatigue' => $freqLife >= 3.0,
        ];
    }, $rows);
}

/**
 * Ad-set or ad (creative) level breakdown for a Meta Ads connection. $prefix = 'adset' | 'ad'.
 * Reads the prefixed dimensioned rows (e.g. adset_spend / ad_video_views) summed over the range and
 * derives the actionable signals: ROAS, CPA, CTR, frequency and hook rate (3s video views / impressions).
 * Frekvence: přednostně za CELOU dobu z Meta (meta_ads_totals), jinak jen denní (zobrazení ÷ součet denních
 * dosahů, tentýž člověk se v ní každý den počítá znovu); klíč frequencyScope říká, která to je. Únava = ≥ 3.
 */
function allstat_get_meta_ads_subbreakdown(PDO $pdo, int $domainId, int $connectionId, string $start, string $end, string $prefix, int $limit = 10): array
{
    [$start, $end] = allstat_limited_range($start, $end);
    $prefix = $prefix === 'ad' ? 'ad' : 'adset';
    $cols = ['spend', 'impressions', 'clicks', 'link_clicks', 'reach', 'conversions', 'value', 'video_views', 'thruplays'];
    $select = [];
    $keys = [];
    foreach ($cols as $c) {
        $key = $prefix . '_' . $c;
        $keys[] = $pdo->quote($key);
        $select[] = 'SUM(CASE WHEN metric_key = ' . $pdo->quote($key) . ' THEN metric_value ELSE 0 END) AS ' . $c;
    }
    try {
        $rows = allstat_fetch_all($pdo, '
            SELECT dimension AS name, ' . implode(', ', $select) . '
            FROM provider_metrics_daily
            WHERE domain_id = ? AND connection_id = ? AND dimension <> \'\' AND metric_date BETWEEN ? AND ?
              AND metric_key IN (' . implode(',', $keys) . ')
            GROUP BY dimension
            ORDER BY spend DESC
            LIMIT ' . (int) $limit . '
        ', [$domainId, $connectionId, $start, $end]);
    } catch (Throwable) {
        return [];
    }

    $totals = allstat_meta_ads_totals_by_name($pdo, $connectionId, $prefix);
    $kindOf = static fn (array $r): string => allstat_meta_ads_objective((string) ($totals[(string) $r['name']]['objective'] ?? ''))['kind'];

    // Průměry účtu za období = referenční hladina pro hodnocení jednotlivých reklam. Vědomě se
    // neporovnává s oborovým benchmarkem: účty se liší cílem i trhem, ale „proti vlastnímu průměru"
    // dává smysl vždycky a je to i způsob, jakým se rozpočet reálně přelévá mezi reklamami.
    // Proklikové průměry jen z reklam, které mají přivádět lidi na web; reklamy na povědomí a interakce
    // se porovnávají mezi sebou cenou za 1 000 zobrazení (jinak by vždycky vyšly „podprůměrné").
    $tot = ['spend' => 0.0, 'impressions' => 0.0, 'link_clicks' => 0.0];
    $aw = ['spend' => 0.0, 'impressions' => 0.0];
    foreach ($rows as $r) {
        if (allstat_meta_ads_click_goal($kindOf($r))) {
            foreach ($tot as $k => $_) { $tot[$k] += (float) ($r[$k] ?? 0); }
        } else {
            foreach ($aw as $k => $_) { $aw[$k] += (float) ($r[$k] ?? 0); }
        }
    }
    $avgLinkCtr = $tot['impressions'] > 0 ? $tot['link_clicks'] / $tot['impressions'] * 100 : 0.0;
    $avgCplc = $tot['link_clicks'] > 0 ? $tot['spend'] / $tot['link_clicks'] : 0.0;
    $avgCpm = $aw['impressions'] > 0 ? $aw['spend'] / $aw['impressions'] * 1000 : 0.0;

    return array_map(static function (array $r) use ($avgLinkCtr, $avgCplc, $avgCpm, $totals): array {
        $name = (string) $r['name'];
        $spend = (float) $r['spend'];
        $impr = (float) $r['impressions'];
        $clicks = (float) $r['clicks'];
        $linkClicks = (float) ($r['link_clicks'] ?? 0);
        $reach = (float) $r['reach'];
        $conv = (float) $r['conversions'];
        $value = (float) $r['value'];
        $videoViews = (float) $r['video_views'];
        $thruplays = (float) ($r['thruplays'] ?? 0);
        $life = $totals[$name] ?? null;
        $freqDaily = $reach > 0 ? $impr / $reach : 0.0;
        $freqLife = (float) ($life['frequency'] ?? 0.0);
        $freq = $freqLife > 0 ? $freqLife : $freqDaily;
        $reachLife = (float) ($life['reach'] ?? 0.0);
        $goal = allstat_meta_ads_objective((string) ($life['objective'] ?? ''));
        $cpm = $impr > 0 ? $spend / $impr * 1000 : 0.0;
        $cplc = $linkClicks > 0 ? $spend / $linkClicks : 0.0;
        $linkCtr = $impr > 0 ? $linkClicks / $impr * 100 : 0.0;
        $hook = ($impr > 0 && $videoViews > 0) ? $videoViews / $impr * 100 : 0.0;

        return [
            'name' => $name,
            'spend' => $spend,
            'spendLabel' => allstat_number($spend, 0),
            'impressionsLabel' => $impr > 0 ? allstat_number($impr, 0) : '—',
            // Součet denních dosahů: kdo reklamu viděl ve více dnech, je tu vícekrát.
            'reachLabel' => $reach > 0 ? allstat_number($reach, 0) : '—',
            'reachLifetimeLabel' => $reachLife > 0 ? allstat_number($reachLife, 0) : '—',
            'clicksLabel' => $clicks > 0 ? allstat_number($clicks, 0) : '—',
            'linkClicks' => $linkClicks,
            'linkClicksLabel' => $linkClicks > 0 ? allstat_number($linkClicks, 0) : '—',
            // Cena za proklik NA ODKAZ = číslo, které Business Suite ukazuje jako „Za kliknutí na odkaz".
            'cplcLabel' => $cplc > 0 ? allstat_number($cplc, 2) : '—',
            // Cena za JAKÉKOLIV kliknutí (lajky, jméno stránky, rozbalení textu). Bývá nižší.
            'cpcLabel' => $clicks > 0 ? allstat_number($spend / $clicks, 2) : '—',
            'cpmLabel' => $impr > 0 ? allstat_number($cpm, 2) : '—',
            'conversionsLabel' => allstat_number($conv, 0),
            'roasLabel' => $spend > 0 && $value > 0 ? allstat_number($value / $spend, 2) . '×' : '—',
            'cpaLabel' => $conv > 0 ? allstat_number($spend / $conv, 2) : '—',
            'ctrLabel' => $impr > 0 ? allstat_percent($clicks / $impr * 100, 2) : '—',
            'linkCtrLabel' => $impr > 0 ? allstat_percent($linkCtr, 2) : '—',
            'goalLabel' => $goal['label'],
            'goalKind' => $goal['kind'],
            'optimizationLabel' => allstat_meta_ads_optimization_label((string) ($life['optimization'] ?? '')),
            'frequency' => $freq,
            'frequencyLabel' => $freq > 0 ? allstat_number($freq, 2) : '—',
            'frequencyScope' => $freqLife > 0 ? 'lifetime' : 'daily',
            'frequencyLifetime' => $freqLife,
            'frequencyLifetimeLabel' => $freqLife > 0 ? allstat_number($freqLife, 2) : '—',
            'frequencyDaily' => $freqDaily,
            'frequencyDailyLabel' => $freqDaily > 0 ? allstat_number($freqDaily, 2) : '—',
            'videoViewsLabel' => $videoViews > 0 ? allstat_number($videoViews, 0) : '—',
            'hookLabel' => $hook > 0 ? allstat_percent($hook, 1) : '—',
            'thruplaysLabel' => $thruplays > 0 ? allstat_number($thruplays, 0) : '—',
            'fatigue' => $freq >= 3.0,
            'verdict' => allstat_meta_ad_verdict($spend, $impr, $linkClicks, $cplc, $linkCtr, $freq, $hook, $conv, $avgLinkCtr, $avgCplc, $goal['kind'], $cpm, $avgCpm, $freqLife > 0),
        ];
    }, $rows);
}

/**
 * Hodnocení jedné reklamy proti průměru účtu za stejné období. Vrací ['level','label','reasons'].
 * Úmyslně opatrné: při malém objemu dat (nízká útrata / málo zobrazení) se nehodnotí vůbec, protože
 * pár desítek zobrazení o kvalitě reklamy nevypovídá a falešný verdikt je horší než žádný.
 * Reklamy na povědomí a interakce ($goalKind) se neměří prokliky, ale cenou za 1 000 zobrazení proti
 * ostatním takovým reklamám ($avgCpm). $freqIsLifetime = frekvence je za celou dobu (z Meta), ne denní.
 */
function allstat_meta_ad_verdict(float $spend, float $impr, float $linkClicks, float $cplc, float $linkCtr, float $freq, float $hook, float $conv, float $avgLinkCtr, float $avgCplc, string $goalKind = '', float $cpm = 0.0, float $avgCpm = 0.0, bool $freqIsLifetime = false): array
{
    if ($impr < 500 || $spend <= 0) {
        return ['level' => 'none', 'label' => 'Málo dat', 'reasons' => ['Za období má jen ' . allstat_number($impr, 0) . ' zobrazení, na hodnocení je to málo.']];
    }

    $n = static fn (float $v, int $d = 0): string => allstat_number($v, $d);
    $plus = []; $minus = [];
    $clickGoal = allstat_meta_ads_click_goal($goalKind);

    // 1) Míra prokliku na odkaz proti průměru účtu (hlavní signál, jestli kreativa a cílení sedí).
    if ($clickGoal && $avgLinkCtr > 0 && $linkCtr > 0) {
        $pomer = $linkCtr / $avgLinkCtr;
        // „X ze 100 lidí" místo procent z procent. Původní znění („21 % nad průměrem, 7,08 % vs. 5,85 %")
        // míchalo tři různá procenta a bylo pro netechnického čtenáře nesrozumitelné.
        $kolik = allstat_rate_in_words($linkCtr, $avgLinkCtr);
        if ($pomer >= 1.2) { $plus[] = 'Na odkaz ' . $kolik['klikl'] . ' ' . $kolik['a'] . ' lidí, kterým se reklama ukázala. U ostatních reklam na účtu je to ' . $kolik['b'] . ', tahle tedy táhne líp.'; }
        elseif ($pomer <= 0.8) { $minus[] = 'Na odkaz ' . $kolik['klikl'] . ' jen ' . $kolik['a'] . ' lidí, kterým se reklama ukázala. U ostatních reklam na účtu je to ' . $kolik['b'] . ', tahle tedy táhne hůř.'; }
    }

    // 2) Cena za proklik na odkaz proti průměru účtu (kolik stojí přivedení člověka na web).
    if ($clickGoal && $avgCplc > 0 && $cplc > 0) {
        $pomer = $cplc / $avgCplc;
        if ($pomer <= 0.85) { $plus[] = 'Přivést jednoho člověka na web stálo ' . $n($cplc, 2) . '. U ostatních reklam na účtu to vychází na ' . $n($avgCplc, 2) . ', tahle je tedy levnější.'; }
        elseif ($pomer >= 1.25) { $minus[] = 'Přivést jednoho člověka na web stálo ' . $n($cplc, 2) . '. U ostatních reklam na účtu to vychází na ' . $n($avgCplc, 2) . ', tahle je tedy dražší.'; }
    }

    // 2b) Povědomí a interakce: cena za 1 000 zobrazení proti ostatním reklamám, které necílí na prokliky.
    if (!$clickGoal && $avgCpm > 0 && $cpm > 0) {
        $pomer = $cpm / $avgCpm;
        if ($pomer <= 0.85) { $plus[] = 'Tisíc zobrazení stálo ' . $n($cpm, 2) . '. U ostatních reklam, které necílí na prokliky, to vychází na ' . $n($avgCpm, 2) . ', tahle je tedy levnější.'; }
        elseif ($pomer >= 1.25) { $minus[] = 'Tisíc zobrazení stálo ' . $n($cpm, 2) . '. U ostatních reklam, které necílí na prokliky, to vychází na ' . $n($avgCpm, 2) . ', tahle je tedy dražší.'; }
    }

    // 3) Únava publika. Denní frekvence o opakování za celou dobu nic neříká, proto jiná formulace.
    if ($freqIsLifetime) {
        if ($freq >= 4) { $minus[] = 'Stejný člověk reklamu za celou dobu viděl průměrně ' . $n($freq, 1) . '×. To už bývá moc, lidé ji přestávají vnímat.'; }
        elseif ($freq >= 3) { $minus[] = 'Stejný člověk reklamu za celou dobu viděl průměrně ' . $n($freq, 1) . '×, publikum se začíná přesycovat.'; }
    } elseif ($freq >= 3) {
        $minus[] = 'Stejný člověk reklamu viděl průměrně ' . $n($freq, 1) . '× za jediný den, publikum je nejspíš příliš malé.';
    }

    // 4) Video hook = přehrání aspoň na 3 s ÷ zobrazení (jen když jde o video a máme co měřit). U reklam na
    //    návštěvnost je hlavní měřítko proklik, slabý hook se proto počítá jen jako vysvětlení podprůměrné
    //    míry prokliku (u carouselu s jedním videem se zobrazení počítají i u karet bez videa).
    if ($hook > 0) {
        $h = allstat_rate_in_words($hook, $hook);
        if ($hook >= 30) { $plus[] = 'Video zaujme, aspoň 3 sekundy ho ' . $h['sledoval'] . ' ' . $h['a'] . ' lidí, kterým se ukázalo.'; }
        elseif ($hook < 15 && (!$clickGoal || ($avgLinkCtr > 0 && $linkCtr < $avgLinkCtr))) { $minus[] = 'Video málokoho zaujme, aspoň 3 sekundy ho ' . $h['sledoval'] . ' jen ' . $h['a'] . ' lidí, kterým se ukázalo.'; }
    }

    // 5) Konverze, pokud se na účtu vůbec měří.
    if ($conv > 0) { $plus[] = 'Přinesla ' . $n($conv) . ' měřených výsledků (přihlášek nebo poptávek).'; }

    $level = 'ok'; $label = 'Průměrná';
    if ($plus && !$minus) { $level = 'good'; $label = 'Nadprůměrná'; }
    elseif (count($minus) >= 2) { $level = 'bad'; $label = 'Podprůměrná'; }
    elseif ($minus) { $level = 'warn'; $label = 'Spíš slabší'; }

    $reasons = array_merge($plus, $minus);
    if (!$reasons) { $reasons[] = 'Drží se průměru účtu, nic nevyčnívá nahoru ani dolů.'; }
    if (!$clickGoal) {
        $reasons[] = 'Reklama na ' . ($goalKind === 'engagement' ? 'interakce' : 'povědomí') . ': Meta ji ukazuje co nejvíc lidem, ne těm, kdo kliknou, proto se nehodnotí prokliky na web, ale cena za zobrazení a zájem o video.';
    }

    return ['level' => $level, 'label' => $label, 'reasons' => $reasons];
}

/**
 * Převede míru v procentech na srozumitelné „X ze 100" (u malých hodnot „X z 1 000"). Bere obě
 * porovnávaná čísla, aby oba údaje ve větě používaly STEJNÝ základ, jinak by se nedaly srovnat.
 * Vrací i tvar slovesa v minulém čase, protože čeština ho ohýbá podle počtu (1 klikl, 2–4 klikli,
 * 5+ a 0 kliklo) a věta s natvrdo napsaným tvarem by u části čísel byla gramaticky špatně.
 */
function allstat_rate_in_words(float $pctA, float $pctB): array
{
    // Základ podle menšího čísla, aby nevyšlo „0 z 1 000" u míry 0,04 % (reklamy na povědomí).
    $min = min($pctA, $pctB);
    $base = $min >= 1.0 ? 100 : ($min >= 0.1 ? 1000 : 10000);
    // Různá čísla, která se zaokrouhlí stejně („2 ze 100 vs. 2 ze 100, tahle táhne hůř"), by větu popřela;
    // pak se přejde na jemnější základ (17 z 1 000 vs. 23 z 1 000).
    if ($base === 100 && (int) round($pctA) === (int) round($pctB) && abs($pctA - $pctB) >= 0.05) {
        $base = 1000;
    }
    if ($base === 1000 && (int) round($pctA * 10) === (int) round($pctB * 10) && abs($pctA - $pctB) >= 0.005) {
        $base = 10000;
    }
    $count = static fn (float $pct): int => (int) round($pct / 100 * $base);
    $slovy = static fn (float $pct): string => allstat_number($count($pct), 0) . match ($base) { 100 => ' ze 100', 1000 => ' z 1 000', default => ' z 10 000' };
    $nA = $count($pctA);

    return [
        'a' => $slovy($pctA),
        'b' => $slovy($pctB),
        // tvary pro „kliknout", „přehrát si" a „sledovat"
        'klikl' => $nA === 1 ? 'klikl' : ($nA >= 2 && $nA <= 4 ? 'klikli' : 'kliklo'),
        'prehral' => $nA === 1 ? 'přehrál' : ($nA >= 2 && $nA <= 4 ? 'přehráli' : 'přehrálo'),
        'sledoval' => $nA === 1 ? 'sledoval' : ($nA >= 2 && $nA <= 4 ? 'sledovali' : 'sledovalo'),
    ];
}

function allstat_dashboard_payload(
    array $domain,
    array $domains,
    string $start,
    string $end,
    string $granularity,
    array $summary,
    array $previous,
    array $series,
    array $trafficSources,
    array $landingPages,
    array $queries,
    array $sourceStatuses,
    bool $databaseMode,
    ?array $previousRange = null,
    array $geo = [],
    array $events = [],
    array $aiSources = [],
    array $referrers = [],
    array $devices = [],
    array $allPages = []
): array {
    $trafficTotal = array_sum(array_column($trafficSources, 'sessions'));
    $trafficSources = array_map(static function (array $source) use ($trafficTotal): array {
        $share = $trafficTotal > 0 ? ((int) $source['sessions'] / $trafficTotal) * 100 : 0;
        $source['source'] = allstat_ga4_channel_label((string) ($source['source'] ?? ''));
        $source['share'] = $share;
        $source['shareLabel'] = allstat_percent($share, 1);
        $source['sessionsLabel'] = allstat_number((int) $source['sessions']);

        return $source;
    }, $trafficSources);

    // Devices doughnut (Zařízení) — sessions split by device category.
    $deviceTotal = array_sum(array_column($devices, 'sessions'));
    $devices = array_map(static function (array $device) use ($deviceTotal): array {
        $share = $deviceTotal > 0 ? ((int) $device['sessions'] / $deviceTotal) * 100 : 0;
        $device['share'] = $share;
        $device['shareLabel'] = allstat_percent($share, 1);
        $device['sessionsLabel'] = allstat_number((int) $device['sessions']);

        return $device;
    }, $devices);

    // New vs returning users (Noví vs vracející) — derived from the summary, no extra query needed.
    $newUsers = (int) ($summary['new_users'] ?? 0);
    $returningUsers = (int) ($summary['returning_users'] ?? 0);
    $usersTotal = $newUsers + $returningUsers;
    $newReturning = [
        'labels' => ['Noví', 'Vracející se'],
        'values' => [$newUsers, $returningUsers],
        'total' => $usersTotal,
        'totalLabel' => allstat_number($usersTotal),
        'segments' => [
            ['label' => 'Noví', 'value' => $newUsers, 'valueLabel' => allstat_number($newUsers), 'shareLabel' => allstat_percent($usersTotal > 0 ? $newUsers / $usersTotal * 100 : 0, 1)],
            ['label' => 'Vracející se', 'value' => $returningUsers, 'valueLabel' => allstat_number($returningUsers), 'shareLabel' => allstat_percent($usersTotal > 0 ? $returningUsers / $usersTotal * 100 : 0, 1)],
        ],
    ];

    return [
        'domain' => $domain,
        'domains' => $domains,
        'range' => ['start' => $start, 'end' => $end],
        'previousRange' => $previousRange,
        'granularity' => $granularity,
        'summary' => $summary,
        'kpis' => allstat_build_kpis($summary, $previous, $series, $aiSources),
        'charts' => [
            'visits' => $series,
            'traffic' => [
                'labels' => array_column($trafficSources, 'source'),
                'values' => array_map('intval', array_column($trafficSources, 'sessions')),
                'total' => $trafficTotal,
                'totalLabel' => allstat_number($trafficTotal),
                'sources' => $trafficSources,
            ],
            'devices' => [
                'labels' => array_column($devices, 'device'),
                'values' => array_map('intval', array_column($devices, 'sessions')),
                'total' => $deviceTotal,
                'totalLabel' => allstat_number($deviceTotal),
                'items' => $devices,
            ],
            'newReturning' => $newReturning,
        ],
        'tables' => [
            'landingPages' => $landingPages,
            'allPages' => $allPages,
            'queries' => $queries,
            'sources' => $sourceStatuses,
            'geo' => $geo,
            'events' => $events,
            'aiSources' => $aiSources,
            'referrers' => $referrers,
        ],
        'meta' => [
            'databaseMode' => $databaseMode,
            'sourceCount' => count($sourceStatuses),
            'lastSync' => allstat_last_sync_label($sourceStatuses),
            'syncHealth' => allstat_sync_health($sourceStatuses),
        ],
    ];
}

function allstat_empty_dashboard_data(array $domains, int $domainId, string $start, string $end, string $granularity, bool $databaseMode): array
{
    $domain = $domains[0] ?? ['id' => $domainId, 'name' => 'Žádný web', 'url' => '-'];

    foreach ($domains as $item) {
        if ((int) $item['id'] === $domainId) {
            $domain = $item;
            break;
        }
    }

    return allstat_dashboard_payload(
        $domain,
        $domains,
        $start,
        $end,
        allstat_normalize_granularity($granularity),
        allstat_summary_from_values([]),
        allstat_summary_from_values([]),
        allstat_empty_series(),
        [],
        [],
        [],
        [],
        $databaseMode
    );
}

function allstat_empty_series(): array
{
    return ['labels' => [], 'visits' => [], 'users' => [], 'new_users' => [], 'clicks' => [], 'impressions' => [], 'conversions' => [], 'revenue' => [], 'ai_sessions' => [], 'ctr' => [], 'engagement_rate' => [], 'bounce_rate' => [], 'avg_engagement_time' => [], 'conversion_rate' => [], 'ai_share' => []];
}

function allstat_build_kpis(array $summary, array $previous, array $series, array $aiSources = []): array
{
    // Marketing-priority order + skupiny pro čitelnou KPI mřížku: Návštěvnost → Zapojení → Konverze → SEO → AI.
    // Barva ikony = KATEGORIE (skupina), ne sentiment (směr řeší šipka trendu) → sjednocená sémantika.
    $definitions = [
        ['key' => 'visits', 'group' => 'Návštěvnost', 'label' => 'Návštěvy', 'provider' => 'GA4', 'icon' => 'users-round', 'color' => 'blue', 'format' => 'number', 'decimals' => 0],
        ['key' => 'users', 'group' => 'Návštěvnost', 'label' => 'Uživatelé', 'provider' => 'GA4', 'icon' => 'user', 'color' => 'blue', 'format' => 'number', 'decimals' => 0],
        ['key' => 'new_users', 'group' => 'Návštěvnost', 'label' => 'Noví uživatelé', 'provider' => 'GA4', 'icon' => 'user-plus', 'color' => 'blue', 'format' => 'number', 'decimals' => 0],
        ['key' => 'engagement_rate', 'group' => 'Zapojení', 'label' => 'Míra zapojení', 'provider' => 'GA4', 'icon' => 'activity', 'color' => 'violet', 'format' => 'percent', 'decimals' => 1, 'higherIsBetter' => true],
        ['key' => 'bounce_rate', 'group' => 'Zapojení', 'label' => 'Míra odchodů', 'provider' => 'GA4', 'icon' => 'log-out', 'color' => 'violet', 'format' => 'percent', 'decimals' => 1, 'higherIsBetter' => false],
        ['key' => 'avg_engagement_time', 'group' => 'Zapojení', 'label' => 'Prům. čas na webu', 'provider' => 'GA4', 'icon' => 'clock', 'color' => 'violet', 'format' => 'duration', 'decimals' => 0, 'higherIsBetter' => true],
        ['key' => 'conversions', 'group' => 'Konverze', 'label' => 'Konverze', 'provider' => 'GA4', 'icon' => 'shopping-cart', 'color' => 'green', 'format' => 'number', 'decimals' => 0],
        ['key' => 'conversion_rate', 'group' => 'Konverze', 'label' => 'Konverzní poměr', 'provider' => 'GA4', 'icon' => 'target', 'color' => 'green', 'format' => 'percent', 'decimals' => 2, 'higherIsBetter' => true],
        ['key' => 'revenue', 'group' => 'Konverze', 'label' => 'Tržby', 'provider' => 'GA4', 'icon' => 'banknote', 'color' => 'green', 'format' => 'number', 'decimals' => 0, 'higherIsBetter' => true],
        ['key' => 'clicks', 'group' => 'SEO (Search Console)', 'label' => 'Kliknutí', 'provider' => 'GSC', 'icon' => 'mouse-pointer-click', 'color' => 'orange', 'format' => 'number', 'decimals' => 0],
        ['key' => 'impressions', 'group' => 'SEO (Search Console)', 'label' => 'Zobrazení', 'provider' => 'GSC', 'icon' => 'eye', 'color' => 'orange', 'format' => 'number', 'decimals' => 0],
        ['key' => 'ctr', 'group' => 'SEO (Search Console)', 'label' => 'CTR', 'provider' => 'GSC', 'icon' => 'gauge', 'color' => 'orange', 'format' => 'percent', 'decimals' => 2],
        ['key' => 'ai_sessions', 'group' => 'AI vyhledávání', 'label' => 'AI návštěvy', 'provider' => 'GA4', 'icon' => 'sparkles', 'color' => 'teal', 'format' => 'number', 'decimals' => 0, 'higherIsBetter' => true],
        ['key' => 'ai_share', 'group' => 'AI vyhledávání', 'label' => 'AI podíl', 'provider' => 'GA4', 'icon' => 'pie-chart', 'color' => 'teal', 'format' => 'percent', 'decimals' => 2, 'higherIsBetter' => true],
    ];

    $aiBreakdown = '';
    if ($aiSources) {
        $parts = [];
        foreach (array_slice($aiSources, 0, 6) as $aiSource) {
            $parts[] = $aiSource['source'] . ' ' . allstat_number((int) ($aiSource['sessions'] ?? 0));
        }
        if ($parts) {
            $aiBreakdown = ' Detekováno: ' . implode(', ', $parts) . '.';
        }
    }

    $tooltips = [
        'visits' => 'Sessions z GA4, počet návštěv (sekvence interakcí jednoho uživatele do 30min nečinnosti).',
        'users' => 'Active Users z GA4, unikátní uživatelé s alespoň 1 engaged session.',
        'new_users' => 'Noví uživatelé z GA4, kolik lidí navštívilo web poprvé. Zbytek (uživatelé − noví) jsou vracející se.',
        'engagement_rate' => 'Míra zapojení z GA4 = engaged sessions / sessions. Engaged = session >10s, 2+ zobrazení nebo konverze. Benchmark obsahového webu 65–90 %.',
        'bounce_rate' => 'Bounce rate (míra odchodů) = 100 − míra zapojení. Podíl návštěv bez zapojení (kratší než 10 s, jediné zobrazení, bez konverze). GA4 ji počítá přesně takto. Nižší = lepší.',
        'avg_engagement_time' => 'Průměrný čas aktivního zapojení na uživatele (GA4 userEngagementDuration / active users). Kvalitní obsah 2–4 min.',
        'conversions' => 'Key events z GA4, počet dokončených cílových akcí (registrace, kontakt, stažení…).',
        'conversion_rate' => 'Konverzní poměr = konverze / návštěvy. Měří efektivitu, ne jen objem.',
        'revenue' => 'Tržby z GA4 (totalRevenue) za období, v měně GA4 property. Zahrnuje nákupy i další příjmy; u e-shopu odpovídá obratu. Zdroj pro porovnání s plánem.',
        'clicks' => 'Kliknutí z Google Search Console na výsledky hledání směřující na web.',
        'impressions' => 'Zobrazení z Google Search Console ve výsledcích hledání.',
        'ctr' => 'Click-through rate = kliknutí / zobrazení (GSC).',
        'ai_sessions' => 'Návštěvy z AI asistentů (ChatGPT, Perplexity, Gemini, Copilot, Claude…). GA4 dimenze sessionSource, měřitelné jen u nástrojů, které předají referrer.' . $aiBreakdown,
        'ai_share' => 'Podíl AI asistentů na celkových návštěvách = AI návštěvy / návštěvy (GA4). Ukazuje, jak roste význam AI vyhledávání.',
    ];

    // Uživatelé za období: přesný počet různých lidí (GA4), nebo jen součet denních uživatelů. Podle toho popisek,
    // a když se základ obou období liší, změna se nepočítá (srovnávala by se jablka s hruškami).
    $usersUnique = ($summary['users_basis'] ?? 'daily_sum') === 'unique';
    $basisMismatch = ($summary['users_basis'] ?? 'daily_sum') !== ($previous['users_basis'] ?? 'daily_sum');
    if ($usersUnique) {
        $tooltips['users'] = 'Active Users z GA4 za celé období: každý člověk jednou, i když přišel ve více dnech.';
    } else {
        $tooltips['users'] = 'Součet denních uživatelů z GA4 (Active Users po dnech): kdo přišel ve více dnech, je započten vícekrát, takže různých lidí bylo méně. Přesný počet AllStat ukládá pro běžná období (posledních 7, 30, 90 a 365 dní, celé měsíce a roky).';
        $tooltips['avg_engagement_time'] .= ' Tady na denního uživatele (součet dní), proto vychází nižší než v GA4.';
        foreach ($definitions as &$definition) {
            if ($definition['key'] === 'users') {
                $definition['label'] = 'Uživatelé (součet dní)';
            }
        }
        unset($definition);
    }

    return array_map(static function (array $definition) use ($summary, $previous, $series, $tooltips, $basisMismatch): array {
        $key = $definition['key'];
        $value = (float) ($summary[$key] ?? 0);
        $previousValue = (float) ($previous[$key] ?? 0);
        $change = $basisMismatch && in_array($key, ['users', 'avg_engagement_time'], true) ? null : allstat_change($value, $previousValue);

        $displayValue = match ($definition['format']) {
            'percent' => allstat_percent($value, $definition['decimals']),
            'duration' => allstat_duration_label($value),
            default => allstat_number($value, $definition['decimals']),
        };

        return [
            'key' => $key,
            'group' => $definition['group'] ?? '',
            'label' => $definition['label'],
            'provider' => $definition['provider'],
            'icon' => $definition['icon'],
            'color' => $definition['color'],
            'value' => $value,
            'displayValue' => $displayValue,
            'change' => $change,
            'changeLabel' => allstat_change_label($change),
            'trend' => $change === null ? 'none' : ($change >= 0 ? 'up' : 'down'),
            'tooltip' => $tooltips[$key] ?? '',
            'sparkline' => $series[$key] ?? [],
        ];
    }, $definitions);
}

function allstat_summary_from_values(array $values): array
{
    $visits = (int) ($values['visits'] ?? 0);
    $engagedSessions = (int) ($values['engaged_sessions'] ?? 0);
    $engagementTime = (int) ($values['engagement_time_sec'] ?? 0);
    $users = (int) ($values['users'] ?? 0);
    $newUsers = (int) ($values['new_users'] ?? 0);
    $clicks = (int) ($values['clicks'] ?? 0);
    $impressions = (int) ($values['impressions'] ?? 0);
    $conversions = (int) ($values['conversions'] ?? 0);
    $revenue = (float) ($values['revenue'] ?? 0);
    $aiSessions = (int) ($values['ai_sessions'] ?? 0);
    $aiConversions = (int) ($values['ai_conversions'] ?? 0);

    return [
        'visits' => $visits,
        'engaged_sessions' => $engagedSessions,
        'engagement_time_sec' => $engagementTime,
        'users' => $users,
        // unique = různí lidé za období (GA4), daily_sum = součet denních uživatelů (vícedenní návštěvník vícekrát).
        'users_basis' => (string) ($values['users_basis'] ?? 'daily_sum'),
        'new_users' => $newUsers,
        'returning_users' => max(0, $users - $newUsers),
        'clicks' => $clicks,
        'impressions' => $impressions,
        'conversions' => $conversions,
        'revenue' => $revenue,
        'ai_sessions' => $aiSessions,
        'ai_conversions' => $aiConversions,
        'ctr' => $impressions > 0 ? ($clicks / $impressions) * 100 : 0,
        'engagement_rate' => $visits > 0 ? ($engagedSessions / $visits) * 100 : 0,
        // Bounce rate = the exact complement of engagement rate (GA4 computes it the same way):
        // share of sessions that were NOT engaged (<10s, single view, no conversion). Lower = better.
        'bounce_rate' => $visits > 0 ? (($visits - $engagedSessions) / $visits) * 100 : 0,
        'avg_engagement_time' => $users > 0 ? $engagementTime / $users : 0,
        'conversion_rate' => $visits > 0 ? ($conversions / $visits) * 100 : 0,
        'ai_share' => $visits > 0 ? ($aiSessions / $visits) * 100 : 0,
    ];
}

function allstat_summary_row_from_values(array $values): array
{
    $summary = allstat_summary_from_values($values);
    $summary['date'] = (string) ($values['date'] ?? $values['period_start'] ?? '');
    $summary['period_start'] = (string) ($values['period_start'] ?? $summary['date']);
    $summary['period_end'] = (string) ($values['period_end'] ?? $summary['date']);

    return $summary;
}

function allstat_summarize_rows(array $rows): array
{
    $acc = ['visits' => 0, 'engaged_sessions' => 0, 'engagement_time_sec' => 0, 'users' => 0, 'new_users' => 0, 'clicks' => 0, 'impressions' => 0, 'conversions' => 0, 'revenue' => 0.0, 'ai_sessions' => 0];

    foreach ($rows as $row) {
        $acc['visits'] += (int) ($row['visits'] ?? 0);
        $acc['engaged_sessions'] += (int) ($row['engaged_sessions'] ?? 0);
        $acc['engagement_time_sec'] += (int) ($row['engagement_time_sec'] ?? 0);
        $acc['users'] += (int) ($row['users'] ?? 0);
        $acc['new_users'] += (int) ($row['new_users'] ?? 0);
        $acc['clicks'] += (int) ($row['clicks'] ?? 0);
        $acc['impressions'] += (int) ($row['impressions'] ?? 0);
        $acc['conversions'] += (int) ($row['conversions'] ?? 0);
        $acc['revenue'] += (float) ($row['revenue'] ?? 0);
        $acc['ai_sessions'] += (int) ($row['ai_sessions'] ?? 0);
    }

    return allstat_summary_from_values($acc);
}

function allstat_series_from_rows(array $rows, string $granularity = 'day'): array
{
    $series = allstat_empty_series();
    $granularity = allstat_normalize_granularity($granularity);

    foreach ($rows as $row) {
        $series['labels'][] = allstat_series_label($row, $granularity);
        $series['visits'][] = (int) ($row['visits'] ?? 0);
        $series['users'][] = (int) ($row['users'] ?? 0);
        $series['new_users'][] = (int) ($row['new_users'] ?? 0);
        $series['clicks'][] = (int) ($row['clicks'] ?? 0);
        $series['impressions'][] = (int) ($row['impressions'] ?? 0);
        $series['conversions'][] = (int) ($row['conversions'] ?? 0);
        $series['revenue'][] = round((float) ($row['revenue'] ?? 0), 0);
        $series['ai_sessions'][] = (int) ($row['ai_sessions'] ?? 0);
        $series['ctr'][] = round((float) ($row['ctr'] ?? 0), 2);
        $series['engagement_rate'][] = round((float) ($row['engagement_rate'] ?? 0), 1);
        $series['bounce_rate'][] = round((float) ($row['bounce_rate'] ?? 0), 1);
        $series['avg_engagement_time'][] = round((float) ($row['avg_engagement_time'] ?? 0), 0);
        $series['conversion_rate'][] = round((float) ($row['conversion_rate'] ?? 0), 2);
        $series['ai_share'][] = round((float) ($row['ai_share'] ?? 0), 2);
    }

    return $series;
}

/**
 * Denní hodnoty [datum => [klíč => hodnota]] sečte do period (den / týden / měsíc). Vrací [periody, popisky].
 * Poměry (CTR, CPC…) se pak počítají z těchto součtů, takže za týden i měsíc vyjdou správně vážené.
 */
function allstat_rebucket_daily(array $byDate, string $granularity): array
{
    $granularity = allstat_normalize_granularity($granularity);
    ksort($byDate);
    if ($granularity === 'day') {
        return [$byDate, array_map(static fn (string $d): string => (new DateTimeImmutable($d))->format('j. n.'), array_map('strval', array_keys($byDate)))];
    }
    $buckets = [];
    $bounds = [];
    foreach ($byDate as $d => $values) {
        $d = (string) $d;
        $pk = allstat_period_key($d, $granularity);
        foreach ($values as $k => $v) {
            $buckets[$pk][$k] = ($buckets[$pk][$k] ?? 0.0) + (float) $v;
        }
        $bounds[$pk] = [min($bounds[$pk][0] ?? $d, $d), max($bounds[$pk][1] ?? $d, $d)];
    }
    ksort($buckets);
    $labels = [];
    foreach (array_keys($buckets) as $pk) {
        $labels[] = allstat_series_label(['period_start' => $bounds[$pk][0], 'period_end' => $bounds[$pk][1]], $granularity);
    }

    return [$buckets, $labels];
}

function allstat_series_label(array $row, string $granularity): string
{
    $start = new DateTimeImmutable((string) ($row['period_start'] ?? $row['date'] ?? 'now'));
    $end = new DateTimeImmutable((string) ($row['period_end'] ?? $row['date'] ?? $start->format('Y-m-d')));

    return match ($granularity) {
        'month' => $start->format('n/Y'),
        'week' => $start->format('j. n.') . '-' . $end->format('j. n.'),
        default => $start->format('j. n.'),
    };
}

function allstat_last_sync_label(array $statuses): string
{
    // NEJSTARSI sync ze zapnutych napojeni, ne prvni v poradi. Puvodne se bral prvni zaznam, takze
    // panel ukazoval svezi cas i ve chvili, kdy tri zdroje stale tyden (vypadek cronu 2.-9. 9. 2026)
    // a nikdo si toho nevsiml.
    $health = allstat_sync_health($statuses);

    return $health['oldestLabel'] !== '' ? $health['oldestLabel'] : 'nesync.';
}

/**
 * Stav synchronizace napric zapnutymi napojenimi. Vraci nejstarsi sync (to je to, co uzivatele zajima)
 * a seznam zdroju, ktere se neozvaly dele nez $staleHours. Cron jede 2x denne, takze 48 h uz je jasny
 * priznak, ze se neco pokazilo, a neni to falesny poplach po jednom vynechanem behu.
 */
function allstat_sync_health(array $statuses, int $staleHours = 48): array
{
    $oldestTs = null;
    $oldestLabel = '';
    $stale = [];
    $now = time();

    foreach ($statuses as $s) {
        if (array_key_exists('enabled', $s) && !$s['enabled']) { continue; }
        $raw = $s['lastSyncRaw'] ?? null;
        if (!$raw) { continue; }
        $ts = strtotime((string) $raw);
        if ($ts === false) { continue; }
        if ($oldestTs === null || $ts < $oldestTs) {
            $oldestTs = $ts;
            $oldestLabel = (string) ($s['lastSync'] ?? '');
        }
        if ($now - $ts > $staleHours * 3600) {
            $stale[] = ['source' => (string) ($s['source'] ?? ''), 'lastSync' => (string) ($s['lastSync'] ?? ''),
                'days' => (int) floor(($now - $ts) / 86400)];
        }
    }

    return ['oldestLabel' => $oldestLabel, 'stale' => $stale, 'staleCount' => count($stale)];
}
