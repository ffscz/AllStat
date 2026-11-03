<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Run: php tests/new-sources.php
 * Zdroje z 1.5.5 proti simulovaným API (žádná síť, žádné klíče): Bing Webmaster Tools, Google Business Profile,
 * TikTok Ads, a k tomu přehled reklam napříč platformami a detekce neobvyklých změn.
 * Založí si dočasnou databázi (jako tests/openai-ads.php) a na konci ji smaže.
 */
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/database.php';
require_once __DIR__ . '/../lib/oauth.php';
require_once __DIR__ . '/../lib/sync-engines.php';
require_once __DIR__ . '/../lib/migrations.php';
require_once __DIR__ . '/../lib/repository.php';
require_once __DIR__ . '/../lib/growth.php';
require_once __DIR__ . '/../lib/ads-overview.php';
require_once __DIR__ . '/../lib/insights.php';

function check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
    echo "  ok  $message\n";
}

/** WCF datum Bingu pro den v zóně -0700 (Bing vrací půlnoc své zóny). */
function bing_date(string $day): string
{
    return '/Date(' . ((new DateTimeImmutable($day . ' 00:00:00', new DateTimeZone('-07:00')))->getTimestamp() * 1000) . '-0700)/';
}

$calls = [];
$GLOBALS['tiktok_reject_value'] = true;
$GLOBALS['gbp_reject_menu'] = true;
$GLOBALS['allstat_http_mock'] = static function (string $method, string $url, array $headers, ?string $body) use (&$calls): array {
    $calls[] = [$method, $url];
    $host = (string) parse_url($url, PHP_URL_HOST);
    $path = (string) parse_url($url, PHP_URL_PATH);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $ok = static fn (array $json): array => ['status' => 200, 'json' => $json, 'body' => '', 'headers' => []];

    if ($host === 'ssl.bing.com') {
        if (($q['apikey'] ?? '') !== 'BING_KEY') {
            return ['status' => 400, 'json' => ['Message' => 'ErrorCode=InvalidApiKey'], 'body' => '', 'headers' => []];
        }
        return $ok(['d' => match (basename($path)) {
            'GetUserSites' => [['Url' => 'https://example.org/', 'IsVerified' => true]],
            'GetRankAndTrafficStats' => [
                ['Date' => bing_date('2026-10-05'), 'Clicks' => 10, 'Impressions' => 300],
                ['Date' => bing_date('2026-10-06'), 'Clicks' => 12, 'Impressions' => 320],
                ['Date' => bing_date('2026-10-07'), 'Clicks' => 8, 'Impressions' => 280],
            ],
            'GetCrawlStats' => [
                ['Date' => bing_date('2026-10-05'), 'CrawledPages' => 40, 'CrawlErrors' => 1, 'InIndex' => 120, 'InLinks' => 900, 'Code2xx' => 38, 'Code301' => 1, 'Code302' => 0, 'Code4xx' => 1, 'Code5xx' => 0],
                ['Date' => bing_date('2026-10-06'), 'CrawledPages' => 35, 'CrawlErrors' => 0, 'InIndex' => 125, 'InLinks' => 910],
            ],
            // Týdenní řádky; stejný dotaz dvakrát v jednom týdnu se sečte, pozice se váží zobrazeními.
            'GetQueryStats' => [
                ['Date' => bing_date('2026-10-04'), 'Query' => 'kurzy', 'Clicks' => 5, 'Impressions' => 100, 'AvgImpressionPosition' => 2.0],
                ['Date' => bing_date('2026-10-04'), 'Query' => 'kurzy', 'Clicks' => 1, 'Impressions' => 300, 'AvgImpressionPosition' => 6.0],
                ['Date' => bing_date('2026-10-04'), 'Query' => 'akce', 'Clicks' => 9, 'Impressions' => 50, 'AvgImpressionPosition' => 1.0],
            ],
            'GetPageStats' => [
                ['Date' => bing_date('2026-10-04'), 'Query' => 'https://example.org/kurzy', 'Clicks' => 7, 'Impressions' => 200, 'AvgImpressionPosition' => 3.0],
            ],
            default => [],
        }]);
    }

    if ($host === 'businessprofileperformance.googleapis.com') {
        if (($headers['Authorization'] ?? '') !== 'Bearer GBP_TOKEN') {
            return ['status' => 401, 'json' => ['error' => ['message' => 'Request had invalid authentication credentials.']], 'body' => '', 'headers' => []];
        }
        if (str_contains($path, '/searchkeywords/')) {
            return $ok(['searchKeywordsCounts' => [
                ['searchKeyword' => 'jazykova skola', 'insightsValue' => ['value' => '42']],
                ['searchKeyword' => 'kurzy anglictiny', 'insightsValue' => ['threshold' => '15']],
            ]]);
        }
        // dailyMetrics se opakuje, parse_str si nechá jen poslední: čte se ze surového dotazu.
        preg_match_all('/dailyMetrics=([A-Z_]+)/', (string) parse_url($url, PHP_URL_QUERY), $m);
        $metrics = $m[1];
        if (!empty($GLOBALS['gbp_reject_menu']) && in_array('BUSINESS_FOOD_MENU_CLICKS', $metrics, true)) {
            return ['status' => 400, 'json' => ['error' => ['message' => 'Invalid metric BUSINESS_FOOD_MENU_CLICKS']], 'body' => '', 'headers' => []];
        }
        $values = ['BUSINESS_IMPRESSIONS_DESKTOP_SEARCH' => 10, 'BUSINESS_IMPRESSIONS_MOBILE_SEARCH' => 20, 'BUSINESS_IMPRESSIONS_DESKTOP_MAPS' => 3, 'BUSINESS_IMPRESSIONS_MOBILE_MAPS' => 7, 'WEBSITE_CLICKS' => 2, 'CALL_CLICKS' => 1];
        $series = [];
        foreach ($metrics as $metric) {
            $dated = [];
            foreach (['2026-10-05', '2026-10-06'] as $day) {
                [$y, $mo, $d] = array_map('intval', explode('-', $day));
                // Den 6. 10. bez hodnoty (API ho vynechá) = 0.
                $dated[] = ['date' => ['year' => $y, 'month' => $mo, 'day' => $d]] + ($day === '2026-10-05' && isset($values[$metric]) ? ['value' => (string) $values[$metric]] : []);
            }
            $series[] = ['dailyMetric' => $metric, 'timeSeries' => ['datedValues' => $dated]];
        }
        return $ok(['multiDailyMetricTimeSeries' => [['dailyMetricTimeSeries' => $series]]]);
    }

    if ($host === 'business-api.tiktok.com') {
        if (($headers['Access-Token'] ?? '') !== 'TT_TOKEN') {
            return $ok(['code' => 40105, 'message' => 'Access token is incorrect or has been revoked.']);
        }
        if ($path === '/open_api/v1.3/advertiser/info/') {
            return $ok(['code' => 0, 'message' => 'OK', 'data' => ['list' => [['name' => 'Testovací účet', 'currency' => 'EUR']]]]);
        }
        if ($path === '/open_api/v1.3/report/integrated/get/') {
            $metrics = json_decode((string) ($q['metrics'] ?? '[]'), true);
            if (!empty($GLOBALS['tiktok_reject_value']) && in_array('total_purchase_value', $metrics, true)) {
                return $ok(['code' => 40002, 'message' => 'Invalid metric: total_purchase_value']);
            }
            $rows = [];
            for ($d = new DateTimeImmutable((string) $q['start_date']); $d <= new DateTimeImmutable((string) $q['end_date']); $d = $d->modify('+1 day')) {
                foreach ([['111', 'Podzim'], ['222', 'Brand']] as [$id, $name]) {
                    $rows[] = ['dimensions' => ['campaign_id' => $id, 'stat_time_day' => $d->format('Y-m-d') . ' 00:00:00'],
                        'metrics' => ['campaign_name' => $name, 'spend' => '10.50', 'impressions' => '1000', 'clicks' => '20', 'conversion' => '2']];
                }
            }
            $page = (int) ($q['page'] ?? 1);
            $size = 25; // menší než skutečných 1000, ať se ověří stránkování
            return $ok(['code' => 0, 'message' => 'OK', 'data' => ['list' => array_slice($rows, ($page - 1) * $size, $size), 'page_info' => ['page' => $page, 'total_page' => (int) ceil(count($rows) / $size)]]]);
        }
    }

    throw new RuntimeException('Neočekávaný požadavek: ' . $method . ' ' . $url); // pojistka: nikdy skutečná síť
};

$dbName = 'allstat_test_155_' . getmypid();
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
    $sid = static fn (string $pk): int => (int) $pdo->query("SELECT id FROM data_sources WHERE provider_key = " . $pdo->quote($pk))->fetchColumn();
    check($sid('bing_wmt') > 0 && $sid('google_business') > 0 && $sid('tiktok_ads') > 0, 'migrace založila Bing Webmaster, Google Business Profile a TikTok Ads');
    check(allstat_column_exists($pdo, 'traffic_sources_daily', 'revenue') && allstat_column_exists($pdo, 'utm_daily', 'revenue'), 'migrace přidala tržby ke kanálům a UTM');
    $pdo->exec("INSERT INTO domains (id, name, url) VALUES (1, 'Test', 'example.org')");
    $addConn = static function (int $id, string $pk, string $property, string $label = '') use ($pdo, $sid): array {
        $pdo->prepare("INSERT INTO domain_sources (id, domain_id, source_id, account_label, property_id, status, is_enabled) VALUES (?, 1, ?, ?, ?, 'ok', 1)")
            ->execute([$id, $sid($pk), $label, $property]);
        return $pdo->query("SELECT ds.*, s.provider_key FROM domain_sources ds JOIN data_sources s ON s.id = ds.source_id WHERE ds.id = $id")->fetch();
    };

    echo "Bing Webmaster Tools\n";
    $bing = $addConn(11, 'bing_wmt', 'https://example.org');
    check(allstat_bing_wmt_date(bing_date('2026-10-05')) === '2026-10-05' && allstat_bing_wmt_date('/Date(1791183600000-0700)/') === '2026-10-05', 'datum WCF se bere v zóně Bingu');
    check(allstat_bing_wmt_test($bing, 'BING_KEY')['ok'] === true, 'test napojení najde web i bez koncového lomítka');
    check(str_contains(allstat_bing_wmt_test($bing, 'WRONG')['message'], 'API klíč neplatí'), 'neplatný klíč má srozumitelnou hlášku');
    allstat_engine_bing_wmt($pdo, [], $bing, 'BING_KEY', '2026-10-01', '2026-10-31', allstat_sync_recipe('bing_wmt'));
    $sum = static fn (int $cid, string $key, string $dim = ''): float => (float) $pdo->query("SELECT COALESCE(SUM(metric_value), 0) FROM provider_metrics_daily WHERE connection_id = $cid AND metric_key = " . $pdo->quote($key) . ' AND dimension = ' . $pdo->quote($dim))->fetchColumn();
    check($sum(11, 'clicks') === 30.0 && $sum(11, 'impressions') === 900.0, 'denní kliknutí a zobrazení (30 / 900)');
    $pv = allstat_get_provider_view($pdo, 1, 11, '2026-10-01', '2026-10-31', 'day', 'bing_wmt');
    $tiles = array_column($pv['metrics'], 'total', 'key');
    check((float) $tiles['in_index'] === 125.0, 'stránky v indexu = poslední stav (125), ne součet');
    check(!isset($tiles['http_2xx']), 'pomocné kódy HTTP nemají dlaždici');
    $top = allstat_get_bing_wmt_top($pdo, 11, '2026-10-01', '2026-10-31', 'query');
    $kurzy = array_values(array_filter($top, static fn (array $r): bool => $r['label'] === 'kurzy'))[0] ?? null;
    check($kurzy !== null && $kurzy['clicks'] === 6.0 && $kurzy['impressions'] === 400.0 && $kurzy['position'] === 5.0, 'dotaz sečtený za týden, pozice vážená zobrazeními (5,0)');
    check($top[0]['label'] === 'akce', 'dotazy seřazené podle kliknutí');
    check(count(allstat_get_bing_wmt_top($pdo, 11, '2026-10-01', '2026-10-31', 'page')) === 1, 'stránky uložené zvlášť');
    allstat_engine_bing_wmt($pdo, [], $bing, 'BING_KEY', '2026-10-01', '2026-10-31', allstat_sync_recipe('bing_wmt'));
    check($sum(11, 'query_clicks', 'kurzy') === 6.0, 'opakovaný sync dotazy nezdvojí');

    echo "Google Business Profile\n";
    $gbp = $addConn(12, 'google_business', 'accounts/9/locations/555');
    $res = allstat_engine_google_business($pdo, [], $gbp, 'GBP_TOKEN', '2026-10-05', '2026-10-06', allstat_sync_recipe('google_business'));
    check(str_contains($res['summary'], 'BUSINESS_FOOD_MENU_CLICKS'), 'odmítnutá metrika se přeskočí a ostatní se stáhnou po jedné');
    check($sum(12, 'impressions') === 40.0 && $sum(12, 'impressions_search') === 30.0 && $sum(12, 'impressions_maps') === 10.0, 'zobrazení sečtená z hledání a Map (40)');
    check((int) $pdo->query("SELECT COUNT(*) FROM provider_metrics_daily WHERE connection_id = 12 AND metric_key = 'website_clicks' AND metric_date = '2026-10-06' AND metric_value = 0")->fetchColumn() === 1, 'den bez hodnoty se uloží jako 0');
    check(str_contains(allstat_google_business_error(['status' => 429, 'json' => ['error' => ['message' => 'Quota exceeded']]]), 'Application for Basic API Access'), 'kvóta 0 vede na žádost o přístup');
    $kw = allstat_get_google_business_keywords($pdo, 12, '2026-10-01', '2026-10-31');
    check(count($kw['rows']) === 2 && $kw['rows'][0]['keyword'] === 'jazykova skola', 'hledané výrazy za měsíc');
    check($kw['rows'][1]['below'] === true && $kw['rows'][1]['impressions'] === 15.0, 'výraz pod prahem nese jen horní hranici');
    check(allstat_google_business_test($pdo, $gbp, 'GBP_TOKEN')['ok'] === true, 'test napojení projde');

    echo "TikTok Ads\n";
    $tt = $addConn(13, 'tiktok_ads', '7000123');
    $res = allstat_engine_tiktok_ads($pdo, [], $tt, 'TT_TOKEN', '2026-09-01', '2026-10-15', allstat_sync_recipe('tiktok_ads'));
    $reports = array_filter($calls, static fn (array $c): bool => str_contains($c[1], 'report/integrated/get'));
    check(count(array_filter($reports, static fn (array $c): bool => str_contains($c[1], 'total_purchase_value'))) === 1, 'odmítnutá hodnota konverzí se zkusí jen jednou');
    check($sum(13, 'cost') === round(45 * 2 * 10.5, 2), 'útrata 45 dní × 2 kampaně, okna po 30 dnech a stránkování');
    check($sum(13, 'campaign_cost', 'Podzim') === 45 * 10.5, 'kampaně v rozpadu podle názvu');
    check($pdo->query("SELECT setting_value FROM allstat_settings WHERE setting_key = 'tiktok_ads.currency.13'")->fetchColumn() === 'EUR', 'měna účtu z advertiser/info');
    check($pdo->query('SELECT account_label FROM domain_sources WHERE id = 13')->fetchColumn() === 'Testovací účet', 'název účtu doplněn');
    allstat_engine_tiktok_ads($pdo, [], $tt, 'TT_TOKEN', '2026-10-01', '2026-10-15', allstat_sync_recipe('tiktok_ads'));
    check($sum(13, 'campaign_cost', 'Podzim') === 45 * 10.5, 'opakovaný sync kampaně nezdvojí');
    check(!array_filter(array_column($calls, 1), static fn (string $u): bool => str_contains($u, 'TT_TOKEN')), 'token jen v hlavičce, ne v adrese');
    try {
        allstat_engine_tiktok_ads($pdo, [], $tt, 'WRONG', '2026-10-01', '2026-10-02', allstat_sync_recipe('tiktok_ads'));
        check(false, 'neplatný token musí skončit chybou');
    } catch (AllstatTikTokAdsError $e) {
        check(str_contains($e->getMessage(), 'spusť OAuth znovu'), 'neplatný token má srozumitelnou hlášku');
    }

    echo "Přehled reklamy napříč platformami\n";
    $gads = $addConn(14, 'google_ads', '1234567890', 'Hlavní účet');
    $stmt = allstat_pm_statement($pdo);
    foreach (['2026-10-05' => [300.0, 50, 2000, 4], '2026-10-06' => [200.0, 30, 1500, 1]] as $day => [$cost, $clicks, $imp, $conv]) {
        foreach (['cost' => $cost, 'clicks' => $clicks, 'impressions' => $imp, 'conversions' => $conv] as $k => $v) {
            allstat_pm_put($stmt, 1, $sid('google_ads'), 14, $day, $k, (float) $v);
        }
    }
    $utm = $pdo->prepare('INSERT INTO utm_daily (domain_id, metric_date, campaign, source, medium, content, landing_page, sessions, conversions, engaged_sessions, revenue) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $utm->execute(['2026-10-05', 'podzim', 'google', 'cpc', '(not set)', '/', 70, 3, 40, 1500.5]);
    $utm->execute(['2026-10-05', 'podzim', 'tiktok', 'paid_social', '(not set)', '/', 25, 1, 10, 0]);
    $utm->execute(['2026-10-05', 'newsletter', 'facebook', 'referral', '(not set)', '/', 99, 9, 50, 0]);
    $pdo->exec("INSERT INTO traffic_sources_daily (domain_id, metric_date, source, sessions, conversions, revenue) VALUES (1, '2026-10-05', 'Paid Search', 70, 3, 1500.5), (1, '2026-10-05', 'Organic Search', 500, 10, 300)");
    $o = allstat_get_ads_overview($pdo, 1, '2026-10-01', '2026-10-15');
    $byCid = array_column($o['accounts'], null, 'connection_id');
    check($byCid[14]['currency'] === 'CZK' && $byCid[13]['currency'] === 'EUR', 'každý účet ve své měně (Google bez uložené měny = CZK)');
    check(isset($o['currencies']['CZK'], $o['currencies']['EUR']) && $o['currencies']['CZK']['spend'] === 500.0, 'útrata sečtená jen v rámci měny');
    check(round($byCid[14]['share']) === 100.0 && round($byCid[13]['share']) === 100.0, 'podíl na útratě jen mezi účty stejné měny');
    check(round((float) $byCid[14]['cpa'], 2) === 100.0 && $byCid[14]['roas'] === null, 'CPA 500 / 5, ROAS bez hodnoty konverzí není');
    check(($o['ga4']['google_ads']['revenue'] ?? 0) === 1500.5 && ($o['ga4']['tiktok_ads']['sessions'] ?? 0) === 25, 'GA4 návštěvy a tržby podle zdroje platformy');
    check(!isset($o['ga4']['meta_ads']), 'neplacený facebook / referral se reklamě nepřipisuje');
    check($o['ga4Paid']['sessions'] === 70 && $o['hasGa4Revenue'] === true, 'placené kanály GA4 a příznak tržeb');
    check(allstat_ads_ga4_platform('bing', 'cpc') === 'microsoft_ads' && allstat_ads_ga4_platform('chatgpt.com', 'cpc') === 'openai_ads' && allstat_ads_ga4_platform('google', 'organic') === null, 'mapování zdroje GA4 na platformu');

    echo "Detekce neobvyklých změn\n";
    $md = $pdo->prepare('INSERT INTO metrics_daily (domain_id, metric_date, visits, conversions, clicks, impressions) VALUES (1, ?, ?, ?, ?, ?)');
    $today = new DateTimeImmutable('2026-11-01');
    for ($d = $today->modify('-80 days'); $d < $today; $d = $d->modify('+1 day')) {
        $recent = $d >= $today->modify('-7 days');
        $wobble = (int) $d->format('j') % 5; // běžný rozptyl
        $md->execute([$d->format('Y-m-d'), ($recent ? 220 : 100) + $wobble, 5, 40 + $wobble, 1000 + $wobble * 10]);
    }
    $r = allstat_detect_changes($pdo, 1, 7, $today);
    $ids = array_column($r['changes'], 'series');
    check(in_array('web.visits', $ids, true), 'zdvojené návštěvy za poslední týden se ohlásí');
    $visits = $r['changes'][array_search('web.visits', $ids, true)];
    check($visits['direction'] === 'up' && $visits['good'] === true && $visits['change_pct'] > 100, 'směr, hodnocení a změna v procentech');
    check(!in_array('web.impressions', $ids, true) && !in_array('web.conversions', $ids, true), 'stabilní řady a malé hodnoty se nehlásí');
    check($r['checked'] >= 4, 'prověřeno víc řad (' . $r['checked'] . ')');

    echo "Hotovo: všechny kontroly prošly.\n";
} finally {
    $root->exec("DROP DATABASE IF EXISTS `$dbName`");
}
