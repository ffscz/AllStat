<?php

require_once __DIR__ . '/oauth.php';
require_once __DIR__ . '/sync-engines.php';
require_once __DIR__ . '/ai-sources.php';
require_once __DIR__ . '/funnels.php';

const ALLSTAT_BACKFILL_MONTHS = 16;
const ALLSTAT_INCREMENTAL_DAYS = 7;

/**
 * Rolling backfill start date = yesterday minus N months.
 */
function allstat_backfill_start_date(): string
{
    return (new DateTimeImmutable('yesterday'))
        ->modify('-' . ALLSTAT_BACKFILL_MONTHS . ' months')
        ->format('Y-m-d');
}

function allstat_sync_end_date(): string
{
    // Backfill uses completed days only (yesterday).
    return (new DateTimeImmutable('yesterday'))->format('Y-m-d');
}

function allstat_incremental_end_date(): string
{
    // Incremental + cron include TODAY (intraday/partial) so the dashboard can show "dnes".
    return (new DateTimeImmutable('today'))->format('Y-m-d');
}

function allstat_incremental_start_date(): string
{
    return (new DateTimeImmutable('today'))
        ->modify('-' . (ALLSTAT_INCREMENTAL_DAYS - 1) . ' days')
        ->format('Y-m-d');
}

/**
 * Compute one monthly chunk [start, end] starting at $chunkStart, clamped to yesterday.
 * Returns ['start','end','next' => next chunk start or null if done].
 */
function allstat_backfill_chunk(string $chunkStart): array
{
    $end = allstat_sync_end_date();
    $start = $chunkStart;

    try {
        $startDt = new DateTimeImmutable($start);
    } catch (Throwable) {
        $startDt = new DateTimeImmutable(allstat_backfill_start_date());
        $start = $startDt->format('Y-m-d');
    }

    $endDt = new DateTimeImmutable($end);
    if ($startDt > $endDt) {
        return ['start' => $end, 'end' => $end, 'next' => null];
    }

    $chunkEndDt = $startDt->modify('+1 month -1 day');
    if ($chunkEndDt >= $endDt) {
        return ['start' => $start, 'end' => $end, 'next' => null];
    }

    $next = $chunkEndDt->modify('+1 day')->format('Y-m-d');
    return ['start' => $start, 'end' => $chunkEndDt->format('Y-m-d'), 'next' => $next];
}

/**
 * Provider-agnostic sync dispatcher. Pulls [startDate, endDate] for one connection.
 * Updates status, note, last_sync_at, quota, and writes a sync log.
 * Returns ['ok'=>bool, 'message'=>string, 'stats'=>array|null].
 */
function allstat_sync_connection(PDO $pdo, array $config, array $connection, string $startDate, string $endDate, string $label = 'sync'): array
{
    $id = (int) $connection['id'];
    $providerKey = (string) ($connection['provider_key'] ?? '');

    // Native (verified) engines + any provider_key that has a declarative recipe.
    $recipe = allstat_sync_recipe($providerKey);
    if (!in_array($providerKey, ['ga4', 'gsc'], true) && $recipe === null) {
        return [
            'ok' => false,
            'message' => 'Sync pro providera "' . $providerKey . '" zatím není implementovaný (žádný engine ani recept).',
            'stats' => null,
        ];
    }

    // OAuth providers use a refreshable access token; API-key providers (Clarity) use the stored token directly.
    if ((int) ($connection['supports_oauth'] ?? 1) === 1) {
        $tokenResult = allstat_get_valid_access_token($pdo, $config, $connection);
        if (!$tokenResult['ok']) {
            allstat_sync_mark($pdo, $connection, 'error', $label . ': ' . $tokenResult['message'], false);
            return ['ok' => false, 'message' => $tokenResult['message'], 'stats' => null];
        }
        $token = $tokenResult['access_token'];
    } else {
        $token = allstat_decrypt_secret($connection['access_token_enc'] ?? null, $config);
        if (!$token) {
            $msg = 'Není uložen API token. Vyplň ho do pole API token.';
            allstat_sync_mark($pdo, $connection, 'error', $label . ': ' . $msg, false);
            return ['ok' => false, 'message' => $msg, 'stats' => null];
        }
    }

    try {
        $stats = match (true) {
            $providerKey === 'ga4' => allstat_ga4_sync($pdo, $config, $connection, $token, $startDate, $endDate),
            $providerKey === 'gsc' => allstat_gsc_sync($pdo, $config, $connection, $token, $startDate, $endDate),
            default => allstat_run_engine($recipe['engine'], $pdo, $config, $connection, $token, $startDate, $endDate, $recipe),
        };
        allstat_sync_mark($pdo, $connection, 'ok', $label . ': ' . $stats['summary'], true);
        allstat_add_sync_log($pdo, (int) $connection['domain_id'], (int) $connection['source_id'], 'info', ucfirst($label) . ' ' . strtoupper($providerKey) . ': ' . $stats['summary']);
        return ['ok' => true, 'message' => $stats['summary'], 'stats' => $stats];
    } catch (Throwable $exception) {
        $msg = mb_substr($exception->getMessage(), 0, 220);
        allstat_sync_mark($pdo, $connection, 'error', $label . ' chyba: ' . $msg, false);
        allstat_add_sync_log($pdo, (int) $connection['domain_id'], (int) $connection['source_id'], 'error', strtoupper($providerKey) . ' ' . $label . ': ' . $msg);
        return ['ok' => false, 'message' => $msg, 'stats' => null];
    }
}

function allstat_sync_mark(PDO $pdo, array $connection, string $status, string $note, bool $touchSync): void
{
    $sql = $touchSync
        ? 'UPDATE domain_sources SET status = ?, note = ?, last_sync_at = NOW(), updated_at = NOW() WHERE id = ?'
        : 'UPDATE domain_sources SET status = ?, note = ?, updated_at = NOW() WHERE id = ?';
    $pdo->prepare($sql)->execute([$status, mb_substr($note, 0, 220), (int) $connection['id']]);
}

function allstat_gsc_sync(PDO $pdo, array $config, array $connection, string $accessToken, string $startDate, string $endDate): array
{
    $siteUrl = trim((string) ($connection['property_id'] ?? ''));
    if ($siteUrl === '') {
        throw new RuntimeException('Pro GSC vyplň Property ID = přesný siteUrl (sc-domain:... nebo https://...).');
    }

    $domainId = (int) $connection['domain_id'];
    $base = 'https://searchconsole.googleapis.com/webmasters/v3/sites/' . rawurlencode($siteUrl) . '/searchAnalytics/query';
    $authHeader = [
        'Authorization' => 'Bearer ' . $accessToken,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ];

    // Page through results, streaming each page to $handler (no full-set accumulation → bounded memory).
    $stream = function (array $dimensions, callable $handler) use ($base, $authHeader, $startDate, $endDate): int {
        $count = 0;
        $startRow = 0;
        $rowLimit = 25000;
        do {
            $body = json_encode([
                'startDate' => $startDate,
                'endDate' => $endDate,
                'dimensions' => $dimensions,
                'rowLimit' => $rowLimit,
                'startRow' => $startRow,
            ]);
            $response = allstat_http_request('POST', $base, $authHeader, $body, 60);
            if ($response['status'] !== 200) {
                $err = $response['json']['error']['message'] ?? substr($response['body'], 0, 200);
                throw new RuntimeException('GSC API HTTP ' . $response['status'] . ': ' . $err);
            }
            $rows = $response['json']['rows'] ?? [];
            $fetched = count($rows);
            foreach ($rows as $row) {
                if ($handler($row)) { $count++; }
            }
            unset($response, $rows);
            $startRow += $rowLimit;
        } while ($fetched === $rowLimit && $startRow < 200000);
        return $count;
    };

    // 1) Daily totals → metrics_daily (only clicks/impressions; preserves GA4 columns)
    $metricStatement = $pdo->prepare('
        INSERT INTO metrics_daily (domain_id, metric_date, clicks, impressions)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE clicks = VALUES(clicks), impressions = VALUES(impressions), updated_at = NOW()
    ');
    $dailyCount = $stream(['date'], function (array $row) use ($metricStatement, $domainId): bool {
        $date = (string) ($row['keys'][0] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return false; }
        $metricStatement->execute([$domainId, $date, (int) round((float) ($row['clicks'] ?? 0)), (int) round((float) ($row['impressions'] ?? 0))]);
        return true;
    });

    // 2) Queries → search_queries_daily
    $queryStatement = $pdo->prepare('
        INSERT INTO search_queries_daily (domain_id, metric_date, query_text, clicks, impressions, position)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE clicks = VALUES(clicks), impressions = VALUES(impressions), position = VALUES(position)
    ');
    $queryCount = $stream(['date', 'query'], function (array $row) use ($queryStatement, $domainId): bool {
        $date = (string) ($row['keys'][0] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return false; }
        $text = mb_substr((string) ($row['keys'][1] ?? ''), 0, 220);
        if ($text === '') { return false; }
        $queryStatement->execute([
            $domainId, $date, $text,
            (int) round((float) ($row['clicks'] ?? 0)),
            (int) round((float) ($row['impressions'] ?? 0)),
            round((float) ($row['position'] ?? 0), 2),
        ]);
        return true;
    });

    // 3) Pages → gsc_pages_daily
    $pageStatement = $pdo->prepare('
        INSERT INTO gsc_pages_daily (domain_id, metric_date, page, clicks, impressions, position)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE clicks = VALUES(clicks), impressions = VALUES(impressions), position = VALUES(position)
    ');
    $pageCount = $stream(['date', 'page'], function (array $row) use ($pageStatement, $domainId): bool {
        $date = (string) ($row['keys'][0] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return false; }
        $page = mb_substr((string) ($row['keys'][1] ?? ''), 0, 255);
        if ($page === '') { return false; }
        $pageStatement->execute([
            $domainId, $date, $page,
            (int) round((float) ($row['clicks'] ?? 0)),
            (int) round((float) ($row['impressions'] ?? 0)),
            round((float) ($row['position'] ?? 0), 2),
        ]);
        return true;
    });

    return [
        'range' => $startDate . ' → ' . $endDate,
        'metrics_daily_rows' => $dailyCount,
        'queries_rows' => $queryCount,
        'pages_rows' => $pageCount,
        'summary' => sprintf('%d dní, %d dotazů, %d stránek (%s → %s)', $dailyCount, $queryCount, $pageCount, $startDate, $endDate),
    ];
}

function allstat_ga4_sync(PDO $pdo, array $config, array $connection, string $accessToken, string $startDate, string $endDate): array
{
    $property = allstat_normalize_ga4_property((string) ($connection['property_id'] ?? ''));
    if (!str_starts_with($property, 'properties/')) {
        throw new RuntimeException('Property ID musí být ve formátu "properties/123456789".');
    }

    $domainId = (int) $connection['domain_id'];
    $authHeader = [
        'Authorization' => 'Bearer ' . $accessToken,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ];
    $baseUrl = 'https://analyticsdata.googleapis.com/v1beta/' . $property . ':runReport';

    $latestQuota = null;
    $report = function (array $body) use ($baseUrl, $authHeader, &$latestQuota): array {
        $body['returnPropertyQuota'] = true;
        $response = allstat_http_request('POST', $baseUrl, $authHeader, json_encode($body), 60);
        if ($response['status'] !== 200) {
            $err = $response['json']['error']['message'] ?? substr($response['body'], 0, 200);
            throw new RuntimeException('GA4 API HTTP ' . $response['status'] . ': ' . $err);
        }
        if (isset($response['json']['propertyQuota'])) {
            $latestQuota = $response['json']['propertyQuota'];
        }
        return $response['json'] ?? [];
    };

    $toDate = static function (string $raw): ?string {
        if (!preg_match('/^\d{8}$/', $raw)) { return null; }
        return substr($raw, 0, 4) . '-' . substr($raw, 4, 2) . '-' . substr($raw, 6, 2);
    };

    // 1) Daily core metrics (single report; engagement + new users add no extra request)
    $daily = $report([
        'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
        'dimensions' => [['name' => 'date']],
        'metrics' => [
            ['name' => 'sessions'],
            ['name' => 'engagedSessions'],
            ['name' => 'userEngagementDuration'],
            ['name' => 'activeUsers'],
            ['name' => 'newUsers'],
            ['name' => 'conversions'],
            // totalRevenue = GA4 headline revenue (purchase + ad + subscription). For ecommerce-only
            // sales swap to purchaseRevenue. Universal metric → returns 0 on properties without revenue,
            // so it's safe in the core (unwrapped) report.
            ['name' => 'totalRevenue'],
        ],
    ]);
    $metricStatement = $pdo->prepare('
        INSERT INTO metrics_daily (domain_id, metric_date, visits, engaged_sessions, engagement_time_sec, users_count, new_users, conversions, revenue)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            visits = VALUES(visits),
            engaged_sessions = VALUES(engaged_sessions),
            engagement_time_sec = VALUES(engagement_time_sec),
            users_count = VALUES(users_count),
            new_users = VALUES(new_users),
            conversions = VALUES(conversions),
            revenue = VALUES(revenue),
            updated_at = NOW()
    ');
    $dailyCount = 0;
    foreach ($daily['rows'] ?? [] as $row) {
        $date = $toDate($row['dimensionValues'][0]['value'] ?? '');
        if (!$date) { continue; }
        $metricStatement->execute([
            $domainId, $date,
            (int) ($row['metricValues'][0]['value'] ?? 0),
            (int) ($row['metricValues'][1]['value'] ?? 0),
            (int) round((float) ($row['metricValues'][2]['value'] ?? 0)),
            (int) ($row['metricValues'][3]['value'] ?? 0),
            (int) ($row['metricValues'][4]['value'] ?? 0),
            (int) ($row['metricValues'][5]['value'] ?? 0),
            round((float) ($row['metricValues'][6]['value'] ?? 0), 2),
        ]);
        $dailyCount++;
    }

    // 2) Channels
    $sources = $report([
        'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
        'dimensions' => [['name' => 'date'], ['name' => 'sessionDefaultChannelGroup']],
        'metrics' => [['name' => 'sessions'], ['name' => 'conversions']],
    ]);
    $sourceStatement = $pdo->prepare('
        INSERT INTO traffic_sources_daily (domain_id, metric_date, source, sessions, conversions)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE sessions = VALUES(sessions), conversions = VALUES(conversions)
    ');
    $sourceCount = 0;
    foreach ($sources['rows'] ?? [] as $row) {
        $date = $toDate($row['dimensionValues'][0]['value'] ?? '');
        if (!$date) { continue; }
        $channel = mb_substr((string) ($row['dimensionValues'][1]['value'] ?? '(other)'), 0, 80);
        $sourceStatement->execute([
            $domainId, $date, $channel,
            (int) ($row['metricValues'][0]['value'] ?? 0),
            (int) ($row['metricValues'][1]['value'] ?? 0),
        ]);
        $sourceCount++;
    }

    // 3) Landing pages (paginated)
    $landingStatement = $pdo->prepare('
        INSERT INTO landing_pages_daily (domain_id, metric_date, path, sessions, conversions)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE sessions = VALUES(sessions), conversions = VALUES(conversions)
    ');
    $landingCount = 0;
    $offset = 0;
    $pageLimit = 10000;
    do {
        $landings = $report([
            'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
            'dimensions' => [['name' => 'date'], ['name' => 'landingPage']],
            'metrics' => [['name' => 'sessions'], ['name' => 'conversions']],
            'limit' => $pageLimit,
            'offset' => $offset,
        ]);
        $rows = $landings['rows'] ?? [];
        foreach ($rows as $row) {
            $date = $toDate($row['dimensionValues'][0]['value'] ?? '');
            if (!$date) { continue; }
            $rawPath = trim((string) ($row['dimensionValues'][1]['value'] ?? ''));
            if ($rawPath === '' || $rawPath === '(not set)') { continue; }
            $landingStatement->execute([
                $domainId, $date, mb_substr($rawPath, 0, 220),
                (int) ($row['metricValues'][0]['value'] ?? 0),
                (int) ($row['metricValues'][1]['value'] ?? 0),
            ]);
            $landingCount++;
        }
        $rowCount = (int) ($landings['rowCount'] ?? 0);
        $offset += $pageLimit;
    } while (count($rows) === $pageLimit && $offset < $rowCount);

    // 4) Geography (date × country × region) — bounded, ~tens of rows/day
    $geo = $report([
        'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
        'dimensions' => [['name' => 'date'], ['name' => 'country'], ['name' => 'region']],
        'metrics' => [['name' => 'sessions'], ['name' => 'activeUsers'], ['name' => 'conversions']],
        'limit' => 10000,
    ]);
    $geoStatement = $pdo->prepare('
        INSERT INTO geo_daily (domain_id, metric_date, country, region, sessions, users, conversions)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE sessions = VALUES(sessions), users = VALUES(users), conversions = VALUES(conversions)
    ');
    $geoCount = 0;
    foreach ($geo['rows'] ?? [] as $row) {
        $date = $toDate($row['dimensionValues'][0]['value'] ?? '');
        if (!$date) { continue; }
        $country = mb_substr((string) ($row['dimensionValues'][1]['value'] ?? '(not set)'), 0, 80) ?: '(not set)';
        $region = mb_substr((string) ($row['dimensionValues'][2]['value'] ?? '(not set)'), 0, 120) ?: '(not set)';
        $geoStatement->execute([
            $domainId, $date, $country, $region,
            (int) ($row['metricValues'][0]['value'] ?? 0),
            (int) ($row['metricValues'][1]['value'] ?? 0),
            (int) ($row['metricValues'][2]['value'] ?? 0),
        ]);
        $geoCount++;
    }

    // 5) Key events (date × eventName). Wrapped so an unsupported metric won't kill the sync.
    $eventCount = 0;
    try {
        $events = $report([
            'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
            'dimensions' => [['name' => 'date'], ['name' => 'eventName']],
            'metrics' => [['name' => 'eventCount'], ['name' => 'keyEvents']],
            'limit' => 10000,
        ]);
        $eventStatement = $pdo->prepare('
            INSERT INTO events_daily (domain_id, metric_date, event_name, event_count, key_events)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE event_count = VALUES(event_count), key_events = VALUES(key_events)
        ');
        foreach ($events['rows'] ?? [] as $row) {
            $date = $toDate($row['dimensionValues'][0]['value'] ?? '');
            if (!$date) { continue; }
            $name = mb_substr((string) ($row['dimensionValues'][1]['value'] ?? ''), 0, 120);
            if ($name === '') { continue; }
            $eventStatement->execute([
                $domainId, $date, $name,
                (int) ($row['metricValues'][0]['value'] ?? 0),
                (int) round((float) ($row['metricValues'][1]['value'] ?? 0)),
            ]);
            $eventCount++;
        }
    } catch (Throwable) {
        // keyEvents metric may be unavailable on some properties — skip events, keep the rest.
    }

    // 5b) Trychtýře: eventy z AKTIVNÍCH trychtýřů webu rozpadlé podle kanálu / zdroje / média / kampaně
    //     (date × eventName × session dimenze) → events_source_daily. Stránkuje se přes offset (max. 20 stran),
    //     zapisuje se až po stažení všeho (DELETE okna + INSERT v transakci). Chyba nesmí shodit zbytek syncu.
    $funnelCount = 0;
    try {
        $funnelEvents = allstat_funnel_events_for_domain($pdo, $domainId);
        if ($funnelEvents) {
            $funnelRows = [];
            $offset = 0;
            $pageLimit = 10000;
            for ($funnelPage = 0; $funnelPage < 20; $funnelPage++) {
                $funnelReport = $report([
                    'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
                    'dimensions' => [['name' => 'date'], ['name' => 'eventName'], ['name' => 'sessionDefaultChannelGroup'], ['name' => 'sessionSource'], ['name' => 'sessionMedium'], ['name' => 'sessionCampaignName']],
                    'metrics' => [['name' => 'eventCount'], ['name' => 'totalUsers']],
                    'dimensionFilter' => ['filter' => ['fieldName' => 'eventName', 'inListFilter' => ['values' => $funnelEvents]]],
                    'limit' => $pageLimit,
                    'offset' => $offset,
                ]);
                $funnelPageRows = $funnelReport['rows'] ?? [];
                array_push($funnelRows, ...allstat_funnel_parse_source_rows($funnelReport));
                $rowCount = (int) ($funnelReport['rowCount'] ?? 0);
                $offset += $pageLimit;
                if (count($funnelPageRows) < $pageLimit || $offset >= $rowCount) {
                    break;
                }
            }
            $funnelCount = allstat_funnel_store_source_rows($pdo, $domainId, $startDate, $endDate, $funnelEvents, $funnelRows);
        }
    } catch (Throwable $exception) {
        error_log('AllStat GA4 trychtýře (domain ' . $domainId . '): ' . $exception->getMessage());
    }

    // 6) AI assistant referrals (date × sessionSource, pre-filtered to known AI tools).
    // Multiple raw hosts can map to one canonical tool, so aggregate per (date, tool) before writing.
    $aiCount = 0;
    try {
        $aiReport = $report([
            'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
            'dimensions' => [['name' => 'date'], ['name' => 'sessionSource']],
            'metrics' => [['name' => 'sessions'], ['name' => 'conversions']],
            'dimensionFilter' => [
                'filter' => [
                    'fieldName' => 'sessionSource',
                    'stringFilter' => [
                        'matchType' => 'PARTIAL_REGEXP',
                        'value' => allstat_ai_source_regexp(),
                        'caseSensitive' => false,
                    ],
                ],
            ],
            'limit' => 10000,
        ]);
        $aiAggregate = [];
        foreach ($aiReport['rows'] ?? [] as $row) {
            $date = $toDate($row['dimensionValues'][0]['value'] ?? '');
            if (!$date) { continue; }
            $label = allstat_classify_ai_source((string) ($row['dimensionValues'][1]['value'] ?? ''));
            if ($label === null) { continue; }
            $aiAggregate[$date][$label]['sessions'] = ($aiAggregate[$date][$label]['sessions'] ?? 0) + (int) ($row['metricValues'][0]['value'] ?? 0);
            $aiAggregate[$date][$label]['conversions'] = ($aiAggregate[$date][$label]['conversions'] ?? 0) + (int) ($row['metricValues'][1]['value'] ?? 0);
        }
        $aiStatement = $pdo->prepare('
            INSERT INTO ai_sources_daily (domain_id, metric_date, source, sessions, conversions)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE sessions = VALUES(sessions), conversions = VALUES(conversions)
        ');
        foreach ($aiAggregate as $date => $labels) {
            foreach ($labels as $label => $values) {
                $aiStatement->execute([
                    $domainId, $date, mb_substr($label, 0, 80),
                    (int) $values['sessions'],
                    (int) ($values['conversions'] ?? 0),
                ]);
                $aiCount++;
            }
        }
    } catch (Throwable) {
        // sessionSource or the regexp filter may be unsupported on some setups — skip AI, keep the rest.
    }

    // 7) Concrete referrers (date × sessionSource, medium = referral) → referrers_daily.
    $referrerCount = 0;
    try {
        $referrers = $report([
            'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
            'dimensions' => [['name' => 'date'], ['name' => 'sessionSource']],
            'metrics' => [['name' => 'sessions'], ['name' => 'conversions']],
            'dimensionFilter' => [
                'filter' => [
                    'fieldName' => 'sessionMedium',
                    'stringFilter' => ['matchType' => 'EXACT', 'value' => 'referral', 'caseSensitive' => false],
                ],
            ],
            'limit' => 10000,
        ]);
        $referrerStatement = $pdo->prepare('
            INSERT INTO referrers_daily (domain_id, metric_date, source, sessions, conversions)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE sessions = VALUES(sessions), conversions = VALUES(conversions)
        ');
        foreach ($referrers['rows'] ?? [] as $row) {
            $date = $toDate($row['dimensionValues'][0]['value'] ?? '');
            if (!$date) { continue; }
            $source = trim((string) ($row['dimensionValues'][1]['value'] ?? ''));
            if ($source === '' || $source === '(not set)') { continue; }
            $referrerStatement->execute([
                $domainId, $date, mb_substr($source, 0, 190),
                (int) ($row['metricValues'][0]['value'] ?? 0),
                (int) ($row['metricValues'][1]['value'] ?? 0),
            ]);
            $referrerCount++;
        }
    } catch (Throwable) {
        // sessionMedium filter may be unsupported on some setups — skip referrers, keep the rest.
    }

    // 8) Device category (date × deviceCategory) → device_daily. Bounded, ~3-4 rows/day.
    $deviceCount = 0;
    try {
        $devices = $report([
            'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
            'dimensions' => [['name' => 'date'], ['name' => 'deviceCategory']],
            'metrics' => [['name' => 'sessions'], ['name' => 'totalUsers'], ['name' => 'conversions']],
            'limit' => 10000,
        ]);
        $deviceStatement = $pdo->prepare('
            INSERT INTO device_daily (domain_id, metric_date, device, sessions, users, conversions)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE sessions = VALUES(sessions), users = VALUES(users), conversions = VALUES(conversions)
        ');
        foreach ($devices['rows'] ?? [] as $row) {
            $date = $toDate($row['dimensionValues'][0]['value'] ?? '');
            if (!$date) { continue; }
            $device = mb_substr((string) ($row['dimensionValues'][1]['value'] ?? '(other)'), 0, 40) ?: '(other)';
            $deviceStatement->execute([
                $domainId, $date, $device,
                (int) ($row['metricValues'][0]['value'] ?? 0),
                (int) ($row['metricValues'][1]['value'] ?? 0),
                (int) ($row['metricValues'][2]['value'] ?? 0),
            ]);
            $deviceCount++;
        }
    } catch (Throwable) {
        // deviceCategory may be unsupported on some setups — skip devices, keep the rest.
    }

    // 9) All pages (date × pagePath, paginated) → pages_daily. Same paging as landing pages.
    $pageCount = 0;
    try {
        $pageStatement = $pdo->prepare('
            INSERT INTO pages_daily (domain_id, metric_date, path, views, sessions, conversions)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE views = VALUES(views), sessions = VALUES(sessions), conversions = VALUES(conversions)
        ');
        $offset = 0;
        $pageLimit = 10000;
        do {
            $pages = $report([
                'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
                'dimensions' => [['name' => 'date'], ['name' => 'pagePath']],
                'metrics' => [['name' => 'screenPageViews'], ['name' => 'sessions'], ['name' => 'conversions']],
                'limit' => $pageLimit,
                'offset' => $offset,
            ]);
            $rows = $pages['rows'] ?? [];
            foreach ($rows as $row) {
                $date = $toDate($row['dimensionValues'][0]['value'] ?? '');
                if (!$date) { continue; }
                $rawPath = trim((string) ($row['dimensionValues'][1]['value'] ?? ''));
                if ($rawPath === '' || $rawPath === '(not set)') { continue; }
                $pageStatement->execute([
                    $domainId, $date, mb_substr($rawPath, 0, 220),
                    (int) ($row['metricValues'][0]['value'] ?? 0),
                    (int) ($row['metricValues'][1]['value'] ?? 0),
                    (int) ($row['metricValues'][2]['value'] ?? 0),
                ]);
                $pageCount++;
            }
            $rowCount = (int) ($pages['rowCount'] ?? 0);
            $offset += $pageLimit;
        } while (count($rows) === $pageLimit && $offset < $rowCount);
    } catch (Throwable) {
        // pagePath may be unsupported on some setups — skip all-pages, keep the rest.
    }

    // 10) UTM campaigns (date × campaign × source × medium × landingPage) → utm_daily. Lets the
    //     dashboard answer "lidé z utm_campaign=X přišli na stránku Y a konvertovali". We keep only
    //     UTM-tagged / non-passive traffic (skip rows that are BOTH no-campaign AND organic/direct/referral).
    $utmCount = 0;
    try {
        $utmStatement = $pdo->prepare('
            INSERT INTO utm_daily (domain_id, metric_date, campaign, source, medium, content, landing_page, sessions, conversions, engaged_sessions)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE sessions = VALUES(sessions), conversions = VALUES(conversions), engaged_sessions = VALUES(engaged_sessions)
        ');
        $offset = 0;
        $pageLimit = 10000;
        do {
            $utm = $report([
                'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
                'dimensions' => [['name' => 'date'], ['name' => 'sessionCampaignName'], ['name' => 'sessionSource'], ['name' => 'sessionMedium'], ['name' => 'sessionManualAdContent'], ['name' => 'landingPage']],
                'metrics' => [['name' => 'sessions'], ['name' => 'conversions'], ['name' => 'engagedSessions']],
                'limit' => $pageLimit,
                'offset' => $offset,
            ]);
            $rows = $utm['rows'] ?? [];
            foreach ($rows as $row) {
                $date = $toDate($row['dimensionValues'][0]['value'] ?? '');
                if (!$date) { continue; }
                $campaign = mb_substr((string) ($row['dimensionValues'][1]['value'] ?? '(not set)'), 0, 150) ?: '(not set)';
                $source = mb_substr((string) ($row['dimensionValues'][2]['value'] ?? '(not set)'), 0, 120) ?: '(not set)';
                $medium = mb_substr((string) ($row['dimensionValues'][3]['value'] ?? '(none)'), 0, 60) ?: '(none)';
                $content = mb_substr((string) ($row['dimensionValues'][4]['value'] ?? '(not set)'), 0, 120) ?: '(not set)';
                $landing = mb_substr(trim((string) ($row['dimensionValues'][5]['value'] ?? '')), 0, 190);
                $noCampaign = in_array($campaign, ['(not set)', '(organic)', '(direct)', '(referral)'], true);
                $passiveMedium = in_array($medium, ['(none)', 'organic', 'referral', '(not set)', ''], true);
                if ($noCampaign && $passiveMedium) { continue; }
                $utmStatement->execute([
                    $domainId, $date, $campaign, $source, $medium, $content, $landing,
                    (int) ($row['metricValues'][0]['value'] ?? 0),
                    (int) ($row['metricValues'][1]['value'] ?? 0),
                    (int) ($row['metricValues'][2]['value'] ?? 0),
                ]);
                $utmCount++;
            }
            $rowCount = (int) ($utm['rowCount'] ?? 0);
            $offset += $pageLimit;
        } while (count($rows) === $pageLimit && $offset < $rowCount);
    } catch (Throwable) {
        // UTM dimensions unsupported on some setups — skip, keep the rest.
    }

    // 11) E-commerce items (date × itemName) → items_daily. Only populated on e-commerce properties;
    //     wrapped so a non-ecommerce property (no item metrics) can't break the rest of the sync.
    $itemCount = 0;
    try {
        $itemStatement = $pdo->prepare('
            INSERT INTO items_daily (domain_id, metric_date, item_name, items_viewed, items_added_to_cart, items_purchased, item_revenue)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE items_viewed = VALUES(items_viewed), items_added_to_cart = VALUES(items_added_to_cart), items_purchased = VALUES(items_purchased), item_revenue = VALUES(item_revenue)
        ');
        $offset = 0;
        $pageLimit = 10000;
        do {
            $items = $report([
                'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
                'dimensions' => [['name' => 'date'], ['name' => 'itemName']],
                'metrics' => [['name' => 'itemsViewed'], ['name' => 'itemsAddedToCart'], ['name' => 'itemsPurchased'], ['name' => 'itemRevenue']],
                'limit' => $pageLimit,
                'offset' => $offset,
            ]);
            $rows = $items['rows'] ?? [];
            foreach ($rows as $row) {
                $date = $toDate($row['dimensionValues'][0]['value'] ?? '');
                if (!$date) { continue; }
                $name = trim((string) ($row['dimensionValues'][1]['value'] ?? ''));
                if ($name === '' || $name === '(not set)') { continue; }
                $itemStatement->execute([
                    $domainId, $date, mb_substr($name, 0, 200),
                    (int) ($row['metricValues'][0]['value'] ?? 0),
                    (int) ($row['metricValues'][1]['value'] ?? 0),
                    (int) ($row['metricValues'][2]['value'] ?? 0),
                    round((float) ($row['metricValues'][3]['value'] ?? 0), 2),
                ]);
                $itemCount++;
            }
            $rowCount = (int) ($items['rowCount'] ?? 0);
            $offset += $pageLimit;
        } while (count($rows) === $pageLimit && $offset < $rowCount);
    } catch (Throwable) {
        // itemName / item metrics unavailable on non-ecommerce properties — skip items, keep the rest.
    }

    // 12) Demographics (date × age × gender) → demographics_daily. Needs Google Signals; GA4 thresholds
    //     small segments for privacy (aggregate, modelled estimate). Wrapped so a property without Signals
    //     can't break the rest of the sync.
    $demoCount = 0;
    try {
        $demoStatement = $pdo->prepare('
            INSERT INTO demographics_daily (domain_id, metric_date, age_bracket, gender, users)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE users = VALUES(users)
        ');
        $demo = $report([
            'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
            'dimensions' => [['name' => 'date'], ['name' => 'userAgeBracket'], ['name' => 'userGender']],
            'metrics' => [['name' => 'activeUsers']],
            'limit' => 100000,
        ]);
        foreach ($demo['rows'] ?? [] as $row) {
            $date = $toDate($row['dimensionValues'][0]['value'] ?? '');
            if (!$date) { continue; }
            $age = mb_substr((string) ($row['dimensionValues'][1]['value'] ?? ''), 0, 20) ?: 'unknown';
            $gender = mb_substr((string) ($row['dimensionValues'][2]['value'] ?? ''), 0, 20) ?: 'unknown';
            $demoStatement->execute([$domainId, $date, $age, $gender, (int) ($row['metricValues'][0]['value'] ?? 0)]);
            $demoCount++;
        }
    } catch (Throwable) {
        // demographics need Google Signals enabled; unavailable → skip, keep the rest.
    }

    // 13) Počet různých uživatelů za běžná období → period_users. Denní activeUsers se sčítat nedají (vícedenní
    //     návštěvník by byl započten vícekrát), proto se GA4 zeptá za každé období zvlášť. Jen u čerstvé
    //     synchronizace (cron, ruční sync), stahování historie to nepotřebuje. Chyba nesmí shodit zbytek.
    $periodUsersCount = 0;
    if ($endDate >= (new DateTimeImmutable('yesterday'))->format('Y-m-d')) {
        try {
            $periodUsersCount = allstat_ga4_sync_period_users($pdo, $domainId, $report);
        } catch (Throwable) {
            $periodUsersCount = -1;
        }
    }

    if ($latestQuota) {
        $pdo->prepare('UPDATE domain_sources SET quota_json = ?, quota_updated_at = NOW() WHERE id = ?')
            ->execute([json_encode($latestQuota, JSON_UNESCAPED_UNICODE), (int) $connection['id']]);
    }

    return [
        'range' => $startDate . ' → ' . $endDate,
        'metrics_daily_rows' => $dailyCount,
        'traffic_sources_rows' => $sourceCount,
        'landing_pages_rows' => $landingCount,
        'pages_rows' => $pageCount,
        'device_rows' => $deviceCount,
        'geo_rows' => $geoCount,
        'events_rows' => $eventCount,
        'ai_sources_rows' => $aiCount,
        'referrers_rows' => $referrerCount,
        'utm_rows' => $utmCount,
        'items_rows' => $itemCount,
        'demographics_rows' => $demoCount,
        'funnel_rows' => $funnelCount,
        'period_users_rows' => $periodUsersCount,
        'quota' => $latestQuota,
        'summary' => sprintf('%d dní, %d channel, %d landing, %d pages, %d device, %d geo, %d events, %d AI, %d ref, %d utm, %d items, %d demo%s%s (%s → %s)', $dailyCount, $sourceCount, $landingCount, $pageCount, $deviceCount, $geoCount, $eventCount, $aiCount, $referrerCount, $utmCount, $itemCount, $demoCount, $funnelCount > 0 ? sprintf(', %d trychtýř', $funnelCount) : '',
            $periodUsersCount > 0 ? sprintf(', %d období uživatelů', $periodUsersCount) : ($periodUsersCount < 0 ? ', uživatelé za období: chyba' : ''), $startDate, $endDate),
    ];
}

/**
 * Období, pro která se ukládá počet různých uživatelů: relativní období MCP (končí včerejškem), rychlé volby
 * dashboardu (7/30/90 dní včetně dneška), běžící i minulý měsíc a rok a uzavřené měsíce za poslední rok, vždy
 * i se srovnávacím obdobím (allstat_previous_range), aby šla spočítat změna. Vrací [klíč => [start, end, closed]].
 *
 * @return array<string, array{0: string, 1: string, 2: bool}>
 */
function allstat_ga4_period_windows(DateTimeImmutable $today): array
{
    $today = $today->setTime(0, 0);
    $yesterday = $today->modify('-1 day');
    $windows = [];
    $add = static function (DateTimeImmutable $s, DateTimeImmutable $e, bool $closed = false) use (&$windows): void {
        if ($s > $e) {
            return;
        }
        foreach ([[$s->format('Y-m-d'), $e->format('Y-m-d')], allstat_previous_range($s->format('Y-m-d'), $e->format('Y-m-d'))] as $i => [$ws, $we]) {
            $windows[$ws . '|' . $we] ??= [$ws, $we, $closed || $i === 1 && $closed];
        }
    };
    foreach ([7, 30, 90, 365] as $n) {
        $add($yesterday->modify('-' . ($n - 1) . ' days'), $yesterday);
    }
    foreach ([7, 30, 90] as $n) {
        $add($today->modify('-' . ($n - 1) . ' days'), $today);
    }
    $year = (int) $today->format('Y');
    $add($today->modify('first day of this month'), $yesterday);
    $add($today->setDate($year, 1, 1), $yesterday);
    $add($today->modify('first day of last month'), $today->modify('last day of last month'));
    $add($today->setDate($year - 1, 1, 1), $today->setDate($year - 1, 12, 31), true);
    // Uzavřené měsíce za poslední rok (otázky typu „kolik lidí přišlo v červnu"). Měsíc starší než 3 dny po
    // svém konci se už nemění, ty se stahují jen jednou (viz allstat_ga4_sync_period_users).
    for ($i = 2; $i <= 13; $i++) {
        $m = $today->modify('first day of this month')->modify('-' . $i . ' months');
        $add($m, $m->modify('last day of this month'), true);
    }

    return $windows;
}

/**
 * Stáhne z GA4 activeUsers a newUsers za období z allstat_ga4_period_windows (po 4 obdobích na dotaz) a uloží je
 * do period_users. Uzavřená období, která už jsou uložená po svém konci + 3 dny, se znovu nestahují.
 * $report je dotaz na runReport (vyhazuje výjimku při chybě). Vrací počet uložených období.
 */
function allstat_ga4_sync_period_users(PDO $pdo, int $domainId, callable $report, ?DateTimeImmutable $today = null): int
{
    $windows = allstat_ga4_period_windows($today ?? new DateTimeImmutable('today'));
    $stored = [];
    foreach (allstat_fetch_all($pdo, 'SELECT start_date, end_date, updated_at FROM period_users WHERE domain_id = ?', [$domainId]) as $r) {
        $stored[$r['start_date'] . '|' . $r['end_date']] = (string) $r['updated_at'];
    }
    $todo = [];
    foreach ($windows as $key => [$s, $e, $closed]) {
        $final = (new DateTimeImmutable($e))->modify('+3 days')->format('Y-m-d 23:59:59');
        if ($closed && isset($stored[$key]) && $stored[$key] > $final) {
            continue; // uzavřené období s definitivními čísly už je uložené
        }
        $todo[] = [$s, $e];
    }

    $stmt = $pdo->prepare('INSERT INTO period_users (domain_id, start_date, end_date, active_users, new_users) VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE active_users = VALUES(active_users), new_users = VALUES(new_users), updated_at = CURRENT_TIMESTAMP');
    $saved = 0;
    foreach (array_chunk($todo, 4) as $chunk) {
        $ranges = [];
        foreach ($chunk as $i => [$s, $e]) {
            $ranges[] = ['startDate' => $s, 'endDate' => $e, 'name' => 'r' . $i];
        }
        $resp = $report(['dateRanges' => $ranges, 'metrics' => [['name' => 'activeUsers'], ['name' => 'newUsers']]]);
        foreach (allstat_ga4_parse_range_totals($resp, count($ranges)) as $i => [$active, $new]) {
            [$s, $e] = $chunk[$i];
            $stmt->execute([$domainId, $s, $e, $active, $new]);
            $saved++;
        }
    }

    return $saved;
}

/**
 * Součty metrik po obdobích z odpovědi runReport s více dateRanges a bez dalších dimenzí. GA4 přidá řádkům
 * dimenzi dateRange (hodnota = name z dotazu, tady r0…r3, případně date_range_N). Období bez řádku = 0.
 *
 * @return array<int, array{0: int, 1: int}> index období => [activeUsers, newUsers]
 */
function allstat_ga4_parse_range_totals(array $resp, int $count): array
{
    $headers = array_column($resp['dimensionHeaders'] ?? [], 'name');
    $rangeIdx = array_search('dateRange', $headers, true);
    $out = array_fill(0, $count, [0, 0]);
    foreach ($resp['rows'] ?? [] as $row) {
        $label = $rangeIdx !== false ? (string) ($row['dimensionValues'][$rangeIdx]['value'] ?? '') : ($count === 1 ? 'r0' : '');
        if (!preg_match('/^(?:r|date_range_)(\d+)$/', $label, $m) || (int) $m[1] >= $count) {
            continue;
        }
        $out[(int) $m[1]] = [(int) ($row['metricValues'][0]['value'] ?? 0), (int) ($row['metricValues'][1]['value'] ?? 0)];
    }

    return $out;
}
