<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Run: php tests/openai-ads.php [--into=<connection_id>]
 * OpenAI Ads proti simulovanému Reporting API (žádná síť, žádný klíč): stránkování přes after/last_id,
 * součet kampaní do účtu, kampaně v dimenzi, měna z endpointu konverzí, chyba 401.
 * Bez --into si založí dočasnou databázi (jako tests/meta-batch.php) a na konci ji smaže. S --into=ID zapíše
 * simulovaná data do LOKÁLNÍ instalace (config.php) k napojení s tím ID, aby šla vidět na dashboardu.
 */
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/database.php';
require_once __DIR__ . '/../lib/oauth.php';
require_once __DIR__ . '/../lib/sync-engines.php';
require_once __DIR__ . '/../lib/migrations.php';

function check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
    echo "  ok  $message\n";
}

/** Simulované API: 3 smyšlené kampaně, denní řádky s realistickými čísly (deterministicky podle data). */
function openai_ads_mock_rows(string $since, string $until): array
{
    $campaigns = [
        ['cmpn_101', 'Jarní akce', 1.0],
        ['cmpn_102', 'Registrace na webinář', 0.6],
        ['cmpn_103', 'Konzultace zdarma', 0.4],
    ];
    $rows = [];
    for ($d = new DateTimeImmutable($since); $d <= new DateTimeImmutable($until); $d = $d->modify('+1 day')) {
        $day = $d->format('Y-m-d');
        $weekend = (int) $d->format('N') >= 6 ? 0.55 : 1.0;
        foreach ($campaigns as $i => [$id, $name, $w]) {
            $seed = crc32($day . $id);
            $imp = (int) round((900 + $seed % 1700) * $w * $weekend);
            $ctr = (0.006 + ($seed >> 8) % 90 / 10000);
            $clicks = (int) round($imp * $ctr);
            $cpc = 0.38 + (($seed >> 4) % 40) / 100;
            $spend = round($clicks * $cpc, 2);
            $conv = $clicks > 0 ? intdiv($clicks + ($seed % 7), 9) : 0;
            $start = $d->getTimestamp();
            $rows[] = ['id' => 'start=' . $start . ':end=' . ($start + 86400) . ':entity_id=' . $id, 'start_time' => $start, 'end_time' => $start + 86400,
                'readable_time' => $day, 'campaign_id' => $id, 'campaign_name' => $name,
                'impressions' => $imp, 'clicks' => $clicks, 'spend' => $spend, 'conversions' => $conv, 'order_created_attributed_sales' => null];
        }
    }

    return $rows;
}

$calls = [];
$GLOBALS['allstat_http_mock'] = static function (string $method, string $url, array $headers, ?string $body) use (&$calls): array {
    $calls[] = [$method, $url];
    if ((string) parse_url($url, PHP_URL_HOST) !== 'api.ads.openai.com') {
        throw new RuntimeException('Neočekávaný host: ' . $url); // pojistka: nikdy skutečná síť
    }
    if (($headers['Authorization'] ?? '') !== 'Bearer TEST_KEY') {
        return ['status' => 401, 'json' => ['error' => ['message' => 'Invalid API key']], 'body' => '', 'headers' => []];
    }
    $path = (string) parse_url($url, PHP_URL_PATH);
    if ($method === 'POST' && $path === '/v1/conversions/insights') {
        return ['status' => 200, 'json' => ['object' => 'list', 'account_currency' => 'USD', 'data' => [], 'count' => 0], 'body' => '', 'headers' => []];
    }
    if ($method === 'GET' && $path === '/v1/ad_account/insights') {
        // http_build_query tvar time_ranges[]=…&fields[]=… → parse_str to vrátí jako pole
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $range = json_decode((string) ($q['time_ranges'][0] ?? ''), true) ?: [];
        $all = openai_ads_mock_rows((string) $range['since'], (string) $range['until']);
        $pageSize = 30; // menší stránky než limit, ať se ověří stránkování
        $start = 0;
        if (!empty($q['after'])) {
            foreach ($all as $i => $r) { if ($r['id'] === $q['after']) { $start = $i + 1; break; } }
        }
        $page = array_slice($all, $start, $pageSize);
        // Kanonické názvy (campaign.spend, metadata.readable_time) → ploché klíče odpovědi; ploché projdou beze změny.
        $fields = array_map(static fn (string $f): string => match ($f) { 'campaign.id' => 'campaign_id', 'campaign.name' => 'campaign_name', default => preg_replace('/^(campaign|metadata)\./', '', $f) }, $q['fields'] ?? []);
        if (!empty($GLOBALS['oai_reject_conversions']) && in_array('campaign.conversions', $q['fields'] ?? [], true)) {
            return ['status' => 400, 'json' => ['error' => ['message' => 'Unknown field campaign.conversions']], 'body' => '', 'headers' => []];
        }
        $page = array_map(static fn (array $r): array => array_intersect_key($r, array_flip(array_merge(['id', 'start_time', 'end_time'], $fields))), $page);
        return ['status' => 200, 'json' => ['object' => 'list', 'data' => $page, 'count' => count($page),
            'first_id' => $page[0]['id'] ?? null, 'last_id' => $page ? end($page)['id'] : null, 'has_more' => $start + $pageSize < count($all)], 'body' => '', 'headers' => []];
    }
    return ['status' => 404, 'json' => ['error' => ['message' => 'not found']], 'body' => '', 'headers' => []];
};

$into = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--into=')) { $into = (int) substr($arg, 7); }
}

if ($into) {
    // Simulace do lokální instalace: jen k napojení s tímto ID, které musí být openai_ads.
    $config = require __DIR__ . '/../config.php';
    $pdo = allstat_db($config);
    $conn = $pdo->query('SELECT ds.*, s.provider_key FROM domain_sources ds JOIN data_sources s ON s.id = ds.source_id WHERE ds.id = ' . $into)->fetch(PDO::FETCH_ASSOC);
    if (!$conn || $conn['provider_key'] !== 'openai_ads') { fwrite(STDERR, "Napojení $into není openai_ads.\n"); exit(1); }
    $end = (new DateTimeImmutable('yesterday'))->format('Y-m-d');
    $res = allstat_engine_openai_ads($pdo, $config, $conn, 'TEST_KEY', (new DateTimeImmutable('yesterday'))->modify('-89 days')->format('Y-m-d'), $end, allstat_sync_recipe('openai_ads'));
    $pdo->prepare("UPDATE domain_sources SET status = 'ok', last_sync_at = NOW(), note = ? WHERE id = ?")->execute(['SIMULACE: ' . $res['summary'], $into]);
    echo 'Simulace zapsána: ' . $res['summary'] . "\n";
    exit(0);
}

$dbName = 'allstat_test_oai_' . getmypid();
$root = new PDO(getenv('ALLSTAT_TEST_DSN') ?: 'mysql:host=127.0.0.1;charset=utf8mb4', getenv('ALLSTAT_TEST_USER') ?: 'root', getenv('ALLSTAT_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$root->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci");
try {
    $pdo = $root;
    $pdo->exec("USE `$dbName`");
    $srcDb = getenv('ALLSTAT_TEST_SOURCE_DB') ?: 'allstat';
    foreach ($pdo->query("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = " . $pdo->quote($srcDb))->fetchAll() as $t) {
        $pdo->exec("CREATE TABLE `{$t['t']}` LIKE `$srcDb`.`{$t['t']}`");
    }
    $pdo->exec("INSERT INTO data_sources SELECT * FROM `$srcDb`.data_sources");
    allstat_migrate($pdo);
    $sourceId = (int) $pdo->query("SELECT id FROM data_sources WHERE provider_key = 'openai_ads'")->fetchColumn();
    check($sourceId > 0, 'migrace založila zdroj OpenAI Ads');
    $pdo->exec("INSERT INTO domains (id, name, url) VALUES (1, 'Test', 'example.org')");
    $pdo->prepare("INSERT INTO domain_sources (id, domain_id, source_id, account_label, property_id, api_base_url, status, is_enabled)
        VALUES (7, 1, ?, 'test', 'Test účet', 'https://api.ads.openai.com', 'ok', 1)")->execute([$sourceId]);
    $conn = $pdo->query('SELECT * FROM domain_sources WHERE id = 7')->fetch();

    $res = allstat_engine_openai_ads($pdo, [], $conn, 'TEST_KEY', '2026-10-01', '2026-10-28', allstat_sync_recipe('openai_ads'));
    $gets = array_filter($calls, static fn (array $c): bool => $c[0] === 'GET');
    check(count($gets) === 3, '84 řádků (3 kampaně × 28 dní) přišlo ve 3 stránkách přes after');
    $expected = array_sum(array_column(openai_ads_mock_rows('2026-10-01', '2026-10-28'), 'spend'));
    $stored = (float) $pdo->query("SELECT SUM(metric_value) FROM provider_metrics_daily WHERE connection_id = 7 AND metric_key = 'cost' AND dimension = ''")->fetchColumn();
    check(abs($stored - $expected) < 0.01, 'útrata účtu = součet kampaní (' . round($stored, 2) . ' USD)');
    check((int) $pdo->query("SELECT COUNT(DISTINCT dimension) FROM provider_metrics_daily WHERE connection_id = 7 AND metric_key = 'campaign_cost'")->fetchColumn() === 3, 'tři kampaně uložené v rozpadu podle názvu');
    check((int) $pdo->query("SELECT COUNT(*) FROM provider_metrics_daily WHERE connection_id = 7 AND metric_key = 'impressions' AND dimension = ''")->fetchColumn() === 28, 'denní řada účtu za 28 dní');
    check($pdo->query("SELECT setting_value FROM allstat_settings WHERE setting_key = 'openai_ads.currency.7'")->fetchColumn() === 'USD', 'měna účtu z endpointu konverzí');
    check(!array_filter(array_column($calls, 1), static fn (string $u): bool => str_contains($u, 'TEST_KEY')), 'klíč jen v hlavičce, ne v adrese');
    try {
        allstat_engine_openai_ads($pdo, [], $conn, 'WRONG', '2026-10-01', '2026-10-02', allstat_sync_recipe('openai_ads'));
        check(false, 'neplatný klíč musí skončit chybou');
    } catch (RuntimeException $e) {
        check(str_contains($e->getMessage(), 'HTTP 401'), 'neplatný klíč: srozumitelná chyba HTTP 401');
    }
    // Opakovaný sync téhož okna (např. po přejmenování kampaně) nesmí kampaně zdvojit.
    allstat_engine_openai_ads($pdo, [], $conn, 'TEST_KEY', '2026-10-01', '2026-10-28', allstat_sync_recipe('openai_ads'));
    $campaignCost = (float) $pdo->query("SELECT SUM(metric_value) FROM provider_metrics_daily WHERE connection_id = 7 AND metric_key = 'campaign_cost'")->fetchColumn();
    check(abs($campaignCost - $expected) < 0.01, 'opakovaný sync okna kampaně nezdvojí');
    $before = count($calls);
    $old = allstat_engine_openai_ads($pdo, [], $conn, 'TEST_KEY', '2024-01-01', '2024-01-31', allstat_sync_recipe('openai_ads'));
    check(count($calls) === $before && $old['rows'] === 0, 'dávka starší než 365 dní se přeskočí bez volání API');
    check(str_contains(implode(' ', array_column($calls, 1)), rawurlencode('campaign.spend')), 'pole se posílají kanonicky (campaign.spend)');
    $GLOBALS['oai_reject_conversions'] = true;
    allstat_engine_openai_ads($pdo, [], $conn, 'TEST_KEY', '2026-10-01', '2026-10-28', allstat_sync_recipe('openai_ads'));
    $GLOBALS['oai_reject_conversions'] = false;
    $cost2 = (float) $pdo->query("SELECT SUM(metric_value) FROM provider_metrics_daily WHERE connection_id = 7 AND metric_key = 'cost' AND dimension = ''")->fetchColumn();
    check(abs($cost2 - $expected) < 0.01, 'HTTP 400 na konverze: záložní sada polí stáhne útratu');
    $t = allstat_openai_ads_test($conn, 'TEST_KEY');
    check($t['ok'] === true, 'test napojení projde');
    echo "OK: všechny kontroly prošly\n";
} finally {
    $root->exec("DROP DATABASE IF EXISTS `$dbName`");
}
