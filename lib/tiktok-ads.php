<?php

/**
 * TikTok Ads: denní výkon účtu a kampaní přes TikTok API for Business v1.3 (Reporting, synchronní report).
 * https://business-api.tiktok.com/portal/docs
 *
 * POZOR: vývojářskou aplikaci musí TikTok schválit (App review), a každý inzerent ji pak autorizuje přes OAuth.
 * OAuth není standardní: autorizace /portal/auth?app_id=&redirect_uri=&state=, callback nese auth_code,
 * výměna kódu je POST JSON {app_id, secret, auth_code} a token se posílá v hlavičce „Access-Token“ (ne Bearer).
 * Token inzerenta dřív neexpiroval; když odpověď nese refresh_token a expires_in, AllStat ho obnovuje.
 *
 * Report: GET /open_api/v1.3/report/integrated/get/ report_type=BASIC, data_level=AUCTION_CAMPAIGN,
 * dimensions [campaign_id, stat_time_day]. S denní dimenzí API pustí nejvýš 30 dní na dotaz, proto se okno dělí.
 * Ukládá stejné klíče jako Google / Microsoft / OpenAI Ads (cost, impressions, clicks, conversions,
 * conversion_value + campaign_* s názvem kampaně v dimenzi). Částky jsou v měně účtu (advertiser/info currency
 * → tiktok_ads.currency.<napojení>). Chyby API chodí jako HTTP 200 s nenulovým „code“.
 */

const ALLSTAT_TIKTOK_ADS_BASE = 'https://business-api.tiktok.com';

function allstat_tiktok_ads_request(string $base, string $token, string $path, array $query): array
{
    $params = [];
    foreach ($query as $k => $v) {
        $params[$k] = is_array($v) ? (string) json_encode($v) : (string) $v;
    }
    $resp = allstat_http_request('GET', rtrim($base, '/') . '/open_api/v1.3/' . ltrim($path, '/') . '?' . http_build_query($params),
        ['Access-Token' => $token, 'Accept' => 'application/json'], null, 60);
    $code = (int) ($resp['json']['code'] ?? -1);
    if (($resp['status'] ?? 0) !== 200 || !is_array($resp['json']) || $code !== 0) {
        throw new AllstatTikTokAdsError(allstat_tiktok_ads_error($resp), $code);
    }

    return is_array($resp['json']['data'] ?? null) ? $resp['json']['data'] : [];
}

final class AllstatTikTokAdsError extends RuntimeException {}

function allstat_tiktok_ads_error(array $resp): string
{
    $status = (int) ($resp['status'] ?? 0);
    $code = (int) ($resp['json']['code'] ?? 0);
    $message = (string) ($resp['json']['message'] ?? mb_substr((string) ($resp['body'] ?? ''), 0, 160));

    return match (true) {
        in_array($code, [40100, 40105, 40104], true) || str_contains(strtolower($message), 'access token')
            => 'TikTok Ads: přístupový token neplatí nebo vypršel, spusť OAuth znovu. (' . $message . ')',
        $code === 40001 || str_contains(strtolower($message), 'permission')
            => 'TikTok Ads: aplikace nemá oprávnění k reportům účtu. Zkontroluj, že je schválená (App review), má oprávnění Reporting a inzerent ji autorizoval. (' . $message . ')',
        $code === 51021 || $status === 429 || str_contains(strtolower($message), 'too many')
            => 'TikTok Ads: vyčerpán limit API, zkus to později.',
        default => 'TikTok Ads API' . ($status !== 200 ? ' HTTP ' . $status : '') . ($code ? ' code ' . $code : '') . ($message !== '' ? ': ' . $message : ''),
    };
}

/** Řádky reportu kampaní po dnech za [start, end] (≤ 30 dní), se stránkováním. */
function allstat_tiktok_ads_report(string $base, string $token, string $advertiserId, string $startDate, string $endDate, array $metrics): array
{
    $rows = [];
    for ($page = 1; $page <= 50; $page++) {
        $data = allstat_tiktok_ads_request($base, $token, 'report/integrated/get/', [
            'advertiser_id' => $advertiserId,
            'report_type' => 'BASIC',
            'data_level' => 'AUCTION_CAMPAIGN',
            'dimensions' => ['campaign_id', 'stat_time_day'],
            'metrics' => $metrics,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'page' => $page,
            'page_size' => 1000,
        ]);
        foreach ($data['list'] ?? [] as $row) {
            if (is_array($row)) { $rows[] = $row; }
        }
        if ($page >= (int) ($data['page_info']['total_page'] ?? 1)) {
            return $rows;
        }
    }
    throw new RuntimeException('TikTok Ads: report má víc než 50 stránek, zkrať období.');
}

function allstat_engine_tiktok_ads(PDO $pdo, array $config, array $connection, string $token, string $startDate, string $endDate, array $recipe): array
{
    $advertiserId = preg_replace('/[^0-9]/', '', (string) ($connection['property_id'] ?? ''));
    if ($advertiserId === '') {
        throw new RuntimeException('TikTok Ads: chybí Advertiser ID (vyplň ho, nebo spusť OAuth, který ho doplní).');
    }
    $base = (string) ($connection['api_base_url'] ?: ALLSTAT_TIKTOK_ADS_BASE);
    $endDate = min($endDate, (new DateTimeImmutable('today'))->format('Y-m-d'));
    // Hodnota konverzí (total_purchase_value) není u všech účtů; při odmítnutí se report zopakuje bez ní.
    $metricSets = [['campaign_name', 'spend', 'impressions', 'clicks', 'conversion', 'total_purchase_value'], ['campaign_name', 'spend', 'impressions', 'clicks', 'conversion']];

    $rows = [];
    for ($from = new DateTimeImmutable($startDate); $from->format('Y-m-d') <= $endDate; $from = $from->modify('+30 days')) {
        $to = min($from->modify('+29 days')->format('Y-m-d'), $endDate);
        $lastError = null;
        foreach ($metricSets as $i => $metrics) {
            try {
                $rows = array_merge($rows, allstat_tiktok_ads_report($base, $token, $advertiserId, $from->format('Y-m-d'), $to, $metrics));
                $lastError = null;
                if ($i > 0) { array_shift($metricSets); } // další okna rovnou bez odmítnuté metriky
                break;
            } catch (AllstatTikTokAdsError $e) {
                $lastError = $e;
                if ($e->getCode() !== 40002) { throw $e; } // 40002 = neplatný parametr (metrika), jinak dál nemá smysl
            }
        }
        if ($lastError !== null) {
            throw $lastError;
        }
    }

    $stmt = allstat_pm_statement($pdo);
    $campaignStmt = $pdo->prepare('INSERT INTO provider_metrics_daily (domain_id, source_id, connection_id, metric_date, metric_key, dimension, metric_value) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE metric_value = metric_value + VALUES(metric_value)');
    $domainId = (int) $connection['domain_id'];
    $sourceId = (int) $connection['source_id'];
    $connectionId = (int) $connection['id'];
    $num = static fn ($v): float => is_numeric($v) ? (float) $v : 0.0;
    $account = [];
    $campaignRows = 0;
    $pdo->beginTransaction();
    try {
        // Stejně jako Microsoft a OpenAI Ads: okno kampaní se smaže a hodnoty se sčítají (přejmenování, shodné názvy).
        $pdo->prepare("DELETE FROM provider_metrics_daily WHERE domain_id = ? AND connection_id = ? AND metric_date BETWEEN ? AND ? AND metric_key IN ('campaign_impressions','campaign_clicks','campaign_cost','campaign_conversions','campaign_value')")
            ->execute([$domainId, $connectionId, $startDate, $endDate]);
        foreach ($rows as $r) {
            $date = substr((string) ($r['dimensions']['stat_time_day'] ?? ''), 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { continue; }
            $m = $r['metrics'] ?? [];
            $name = trim((string) ($m['campaign_name'] ?? $r['dimensions']['campaign_id'] ?? ''));
            $values = [
                'impressions' => $num($m['impressions'] ?? null),
                'clicks' => $num($m['clicks'] ?? null),
                'cost' => $num($m['spend'] ?? null),
                'conversions' => $num($m['conversion'] ?? null),
                'conversion_value' => $num($m['total_purchase_value'] ?? null),
            ];
            foreach ($values as $k => $v) {
                $account[$date][$k] = ($account[$date][$k] ?? 0.0) + $v;
            }
            if ($name !== '' && array_sum($values) > 0) {
                foreach (['impressions' => 'campaign_impressions', 'clicks' => 'campaign_clicks', 'cost' => 'campaign_cost', 'conversions' => 'campaign_conversions', 'conversion_value' => 'campaign_value'] as $k => $ck) {
                    $campaignStmt->execute([$domainId, $sourceId, $connectionId, $date, $ck, mb_substr($name, 0, 120), $values[$k]]);
                    $campaignRows++;
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    $written = 0;
    foreach ($account as $date => $values) {
        foreach ($values as $k => $v) {
            if (allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $date, $k, $v)) { $written++; }
        }
    }

    // Měna a název účtu. Best-effort.
    try {
        $info = allstat_tiktok_ads_request($base, $token, 'advertiser/info/', ['advertiser_ids' => [$advertiserId], 'fields' => ['currency', 'name']]);
        $adv = $info['list'][0] ?? [];
        allstat_ads_save_currency($pdo, 'tiktok_ads', $connectionId, (string) ($adv['currency'] ?? ''));
        if (!empty($adv['name'])) {
            $pdo->prepare("UPDATE domain_sources SET account_label = ? WHERE id = ? AND (account_label IS NULL OR account_label = '')")
                ->execute([mb_substr((string) $adv['name'], 0, 190), $connectionId]);
        }
    } catch (Throwable) { /* měna je bonus */ }

    return ['rows' => $written + $campaignRows, 'summary' => sprintf('%d metrik účtu, %d řádků kampaní (%s → %s)', $written, $campaignRows, $startDate, $endDate)];
}

/**
 * Výměna auth_code za token (POST JSON). Vrací ['ok', 'access_token', 'refresh_token', 'expires_in', 'advertiser_ids', 'message'].
 */
function allstat_tiktok_ads_exchange(array $connection, string $secret, string $authCode): array
{
    $resp = allstat_http_request('POST', (string) $connection['token_url'], ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
        (string) json_encode(['app_id' => (string) $connection['client_id'], 'secret' => $secret, 'auth_code' => $authCode]), 30);
    $data = $resp['json']['data'] ?? null;
    if (($resp['status'] ?? 0) !== 200 || (int) ($resp['json']['code'] ?? -1) !== 0 || !is_array($data) || empty($data['access_token'])) {
        return ['ok' => false, 'message' => allstat_tiktok_ads_error($resp)];
    }

    return [
        'ok' => true,
        'access_token' => (string) $data['access_token'],
        'refresh_token' => (string) ($data['refresh_token'] ?? ''),
        'expires_in' => (int) ($data['access_token_expire_in'] ?? $data['expires_in'] ?? 0),
        'advertiser_ids' => array_values(array_map('strval', (array) ($data['advertiser_ids'] ?? []))),
    ];
}

/** Obnovení tokenu, jen když ho TikTok vydal s platností a refresh tokenem. */
function allstat_tiktok_ads_refresh(PDO $pdo, array $config, array $connection): array
{
    $refreshToken = allstat_decrypt_secret($connection['refresh_token_enc'] ?? null, $config);
    $secret = allstat_decrypt_secret($connection['client_secret_enc'] ?? null, $config);
    if (!$refreshToken || !$secret || empty($connection['client_id'])) {
        return ['ok' => false, 'message' => t('TikTok Ads: přístup vypršel a nejde obnovit, spusť OAuth znovu.')];
    }
    $base = (string) ($connection['api_base_url'] ?: ALLSTAT_TIKTOK_ADS_BASE);
    $resp = allstat_http_request('POST', rtrim($base, '/') . '/open_api/v1.3/oauth2/refresh_token/', ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
        (string) json_encode(['app_id' => (string) $connection['client_id'], 'secret' => $secret, 'refresh_token' => $refreshToken, 'grant_type' => 'refresh_token']), 30);
    $data = $resp['json']['data'] ?? null;
    if ((int) ($resp['json']['code'] ?? -1) !== 0 || !is_array($data) || empty($data['access_token'])) {
        return ['ok' => false, 'message' => allstat_tiktok_ads_error($resp)];
    }
    $expiresIn = (int) ($data['access_token_expire_in'] ?? $data['expires_in'] ?? 0);
    $expiresAt = $expiresIn > 0 ? (new DateTimeImmutable('now'))->modify('+' . $expiresIn . ' seconds')->format('Y-m-d H:i:s') : null;
    $pdo->prepare('UPDATE domain_sources SET access_token_enc = ?, refresh_token_enc = COALESCE(?, refresh_token_enc), token_expires_at = ?, updated_at = NOW() WHERE id = ?')
        ->execute([allstat_encrypt_secret((string) $data['access_token'], $config), !empty($data['refresh_token']) ? allstat_encrypt_secret((string) $data['refresh_token'], $config) : null, $expiresAt, (int) $connection['id']]);

    return ['ok' => true, 'access_token' => (string) $data['access_token'], 'expires_at' => $expiresAt];
}

/** Test napojení: informace o účtu inzerenta (ověří token i přístup k účtu). */
function allstat_tiktok_ads_test(array $connection, string $token): array
{
    $advertiserId = preg_replace('/[^0-9]/', '', (string) ($connection['property_id'] ?? ''));
    if ($advertiserId === '') {
        return ['ok' => false, 'message' => t('Vyplň Advertiser ID, nebo spusť OAuth (doplní ho sám).')];
    }
    try {
        $info = allstat_tiktok_ads_request((string) ($connection['api_base_url'] ?: ALLSTAT_TIKTOK_ADS_BASE), $token, 'advertiser/info/', ['advertiser_ids' => [$advertiserId], 'fields' => ['name', 'currency']]);
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
    $adv = $info['list'][0] ?? [];

    return ['ok' => true, 'http' => 200, 'message' => t('TikTok Ads odpovídá: účet „{name}“{currency}.', [
        'name' => (string) ($adv['name'] ?? $advertiserId),
        'currency' => !empty($adv['currency']) ? t(', měna {currency}', ['currency' => (string) $adv['currency']]) : '',
    ])];
}
