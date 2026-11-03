<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Run: php tests/meta-batch.php
 * Synchronizace Facebooku a Instagramu proti podvržené Metě (žádná síť, žádný token): dávky přes Batch API
 * místo zrušeného GET /?ids=, náhledy příspěvků, nové metriky. Potřebuje lokální MySQL, založí si vlastní
 * dočasnou databázi (ALLSTAT_TEST_DSN / _USER / _PASS, výchozí root@127.0.0.1 bez hesla) a na konci ji smaže.
 */
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/database.php';
require_once __DIR__ . '/../lib/oauth.php';
require_once __DIR__ . '/../lib/sync-engines.php';
require_once __DIR__ . '/../lib/migrations.php';
require_once __DIR__ . '/../lib/mcp-query.php';

// Náhledy jen do dočasné složky, nikdy do media/thumbs instalace, ve které test běží.
define('ALLSTAT_THUMBS_DIR', sys_get_temp_dir() . '/allstat-test-thumbs-' . getmypid());

function check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
    echo "  ok  $message\n";
}

$dbName = 'allstat_test_meta_' . getmypid();
$root = new PDO(getenv('ALLSTAT_TEST_DSN') ?: 'mysql:host=127.0.0.1;charset=utf8mb4', getenv('ALLSTAT_TEST_USER') ?: 'root', getenv('ALLSTAT_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$root->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci");
$thumbDir = allstat_thumbs_dir();

try {
    $pdo = $root;
    $pdo->exec("USE `$dbName`");
    // Struktura z existující lokální instalace (ALLSTAT_TEST_SOURCE_DB, výchozí allstat), bez dat kromě
    // číselníku zdrojů; migrace pak doplní, co nová verze přidává.
    $srcDb = getenv('ALLSTAT_TEST_SOURCE_DB') ?: 'allstat';
    foreach ($pdo->query("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = " . $pdo->quote($srcDb))->fetchAll() as $t) {
        $pdo->exec("CREATE TABLE `{$t['t']}` LIKE `$srcDb`.`{$t['t']}`");
    }
    $pdo->exec("INSERT INTO data_sources SELECT * FROM `$srcDb`.data_sources");
    if (in_array('thumb_file', array_column($pdo->query('SHOW COLUMNS FROM social_posts')->fetchAll(), 'Field'), true)) { $pdo->exec('ALTER TABLE social_posts DROP COLUMN thumb_file'); }
    allstat_migrate($pdo);
    check(in_array('thumb_file', array_column($pdo->query('SHOW COLUMNS FROM social_posts')->fetchAll(), 'Field'), true), 'migrace přidala social_posts.thumb_file');

    $pdo->exec("INSERT INTO domains (id, name, url) VALUES (1, 'Test', 'example.org')");
    $sourceId = (int) $pdo->query("SELECT id FROM data_sources WHERE provider_key = 'facebook_pages'")->fetchColumn();
    $igSourceId = (int) $pdo->query("SELECT id FROM data_sources WHERE provider_key = 'instagram_business'")->fetchColumn();
    check($sourceId > 0 && $igSourceId > 0, 'zdroje facebook_pages a instagram_business existují');

    // --- podvržená Meta -------------------------------------------------------------------------------
    $calls = [];
    $png = (static function (): string { $im = imagecreatetruecolor(640, 480); imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30)); ob_start(); imagepng($im); return (string) ob_get_clean(); })();
    $GLOBALS['allstat_http_mock'] = static function (string $method, string $url, array $headers, ?string $body) use (&$calls, $png): array {
        $calls[] = [$method, $url, $body];
        $host = (string) parse_url($url, PHP_URL_HOST);
        $ok = static fn (array $json): array => ['status' => 200, 'json' => $json, 'body' => json_encode($json), 'headers' => []];
        if (str_ends_with($host, 'fbcdn.net') || str_ends_with($host, 'cdninstagram.com')) {
            return ['status' => 200, 'json' => null, 'body' => $png, 'headers' => []];
        }
        if ($host !== 'graph.example.test') {
            throw new RuntimeException('Neočekávaný host v testu: ' . $host); // pojistka: nikdy skutečná síť
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        if ($method === 'POST' && rtrim($path, '/') === '/v25.0') {
            parse_str((string) $body, $form);
            if (str_contains((string) ($form['batch'] ?? ''), 'FAIL5XX') && !isset($GLOBALS['fail5xx_seen'])) {
                $GLOBALS['fail5xx_seen'] = true;
                return ['status' => 503, 'json' => null, 'body' => '', 'headers' => []];
            }
            if (str_contains((string) ($form['batch'] ?? ''), 'LIMIT400')) {
                return ['status' => 400, 'json' => ['error' => ['code' => 4, 'message' => 'Application request limit reached']], 'body' => '', 'headers' => []];
            }
            $batch = json_decode((string) ($form['batch'] ?? ''), true);
            if (!is_array($batch) || count($batch) > 50 || ($form['access_token'] ?? '') !== 'TOKEN') {
                return ['status' => 400, 'json' => ['error' => ['message' => 'bad batch']], 'body' => '', 'headers' => []];
            }
            $answers = [];
            foreach ($batch as $req) {
                [$rid, $rq] = array_pad(explode('?', (string) $req['relative_url'], 2), 2, '');
                parse_str($rq, $rp);
                $rid = rawurldecode($rid);
                $fields = (string) ($rp['fields'] ?? '');
                if ($rid === 'POST_ERR') { $answers[] = ['code' => 400, 'body' => json_encode(['error' => ['message' => 'nope']])]; continue; }
                if ($rid === 'POST_SLOW' && !isset($GLOBALS['slow_seen'])) { $GLOBALS['slow_seen'] = true; $answers[] = null; continue; }
                $obj = ['id' => $rid];
                if (str_contains($fields, 'post_total_media_view_unique')) {
                    $obj['insights']['data'] = [
                        ['name' => 'post_total_media_view_unique', 'values' => [['value' => 120]]],
                        ['name' => 'post_media_view', 'values' => [['value' => 200]]],
                        ['name' => 'post_total_media_view_unique', 'values' => [['value' => 0, 'end_time' => 'x']]],
                    ];
                } elseif (str_contains($fields, 'insights.metric(reach)')) {
                    $obj['insights']['data'] = [['name' => 'reach', 'values' => [['value' => 77]]]];
                } elseif (str_contains($fields, 'story_media_view')) {
                    $obj['insights']['data'] = [['name' => 'story_media_view', 'values' => [['value' => 30]]], ['name' => 'story_total_media_view_unique', 'values' => [['value' => 25]]]];
                } elseif ($fields === 'status_type') {
                    $obj['status_type'] = 'added_photos';
                } elseif (str_contains($fields, 'rx_all')) {
                    $obj['rx_all'] = ['summary' => ['total_count' => 9]];
                    $obj['cm_all'] = ['summary' => ['total_count' => 2]];
                } elseif ($fields === 'full_picture') {
                    if ($rid !== 'P_TEXT') { $obj['full_picture'] = 'https://scontent.xx.fbcdn.net/v/' . $rid . '.jpg?oe=1'; }
                } elseif ($fields === 'media_type,media_url,thumbnail_url') {
                    $obj += ['media_type' => 'VIDEO', 'media_url' => 'https://video.cdninstagram.com/v.mp4', 'thumbnail_url' => 'https://scontent.cdninstagram.com/t/' . $rid . '.jpg'];
                }
                $answers[] = ['code' => 200, 'body' => json_encode($obj)];
            }
            return ['status' => 200, 'json' => $answers, 'body' => json_encode($answers), 'headers' => []];
        }
        if ($method !== 'GET') { return ['status' => 405, 'json' => null, 'body' => '', 'headers' => []]; }
        if (str_ends_with($path, '/insights')) {
            $metric = (string) ($q['metric'] ?? '');
            if (($q['metric_type'] ?? '') === 'total_value') {
                if (isset($q['breakdown']) && $q['breakdown'] === 'follow_type') {
                    $res = $metric === 'follows_and_unfollows'
                        ? [['dimension_values' => ['FOLLOWER'], 'value' => 3], ['dimension_values' => ['NON_FOLLOWER'], 'value' => 1]]
                        : [['dimension_values' => ['FOLLOWER'], 'value' => 10], ['dimension_values' => ['NON_FOLLOWER'], 'value' => 40]];
                    return $ok(['data' => [['name' => $metric, 'total_value' => ['breakdowns' => [['results' => $res]]]]]]);
                }
                if (isset($q['breakdown'])) {
                    return $ok(['data' => [['name' => $metric, 'total_value' => ['breakdowns' => [['results' => [['dimension_values' => ['35-44'], 'value' => 50]]]]]]]]);
                }
                return $ok(['data' => array_map(static fn (string $m): array => ['name' => $m, 'total_value' => ['value' => 2234]], explode(',', $metric))]);
            }
            if (($q['period'] ?? '') === 'total_over_range') {
                return $ok(['data' => [['name' => $metric, 'period' => 'total_over_range', 'values' => [['value' => 749]]]]]);
            }
            $data = [];
            foreach (explode(',', $metric) as $m) {
                // Jako Meta: bod za včerejšek má end_time dnešní půlnoc PT (07:00 UTC).
                $data[] = ['name' => $m, 'values' => [['value' => 5, 'end_time' => date('Y-m-d') . 'T07:00:00+0000']]];
            }
            return $ok(['data' => $data]);
        }
        if (str_ends_with($path, '/published_posts')) {
            if (isset($GLOBALS['posts_served'])) { return $ok(['data' => []]); }
            $GLOBALS['posts_served'] = true;
            $t = date('Y-m-d', strtotime('-1 day')) . 'T10:00:00+0000';
            return $ok(['data' => [
                ['id' => 'P_1', 'message' => 'Foto', 'created_time' => $t, 'permalink_url' => 'https://www.facebook.com/p/1', 'shares' => ['count' => 2]],
                ['id' => 'P_TEXT', 'message' => 'Jen text', 'created_time' => $t, 'permalink_url' => 'https://www.facebook.com/p/2'],
                ['id' => 'POST_ERR', 'message' => 'Chyba', 'created_time' => $t, 'permalink_url' => 'https://www.facebook.com/p/3'],
                ['id' => 'POST_SLOW', 'message' => 'Pomalý', 'created_time' => $t, 'permalink_url' => 'https://www.facebook.com/p/4'],
            ]]);
        }
        if (str_ends_with($path, '/media')) {
            if (isset($GLOBALS['media_served'])) { return $ok(['data' => []]); }
            $GLOBALS['media_served'] = true;
            return $ok(['data' => [['id' => 'M_1', 'caption' => 'Reel', 'timestamp' => date('Y-m-d', strtotime('-1 day')) . 'T10:00:00+0000', 'permalink' => 'https://www.instagram.com/reel/1', 'like_count' => 4, 'comments_count' => 1, 'media_type' => 'VIDEO', 'media_product_type' => 'REELS']]]);
        }
        if (str_ends_with($path, '/stories')) {
            if (isset($GLOBALS['stories_served_' . $path])) { return $ok(['data' => []]); }
            $GLOBALS['stories_served_' . $path] = true;
            return $ok(['data' => [['id' => 'S_1', 'media_type' => 'IMAGE', 'timestamp' => date('Y-m-d', strtotime('-1 day')) . 'T10:00:00+0000', 'creation_time' => date('Y-m-d', strtotime('-1 day')) . 'T10:00:00+0000', 'permalink' => 'https://www.instagram.com/s/1', 'media_url' => 'https://scontent.cdninstagram.com/s1.jpg']]]);
        }
        if (isset($q['fields']) && str_contains((string) $q['fields'], 'followers_count')) {
            return $ok(['followers_count' => 500, 'fan_count' => 480]);
        }
        return ['status' => 404, 'json' => ['error' => ['message' => 'unknown ' . $path]], 'body' => '', 'headers' => []];
    };

    // --- 1) samotná dávka ----------------------------------------------------------------------------
    echo "Batch API\n";
    $ids = array_map(static fn (int $i): string => 'X_' . $i, range(1, 120));
    $ids[] = 'POST_ERR';
    $calls = [];
    $got = allstat_meta_batch_get('https://graph.example.test/v25.0', 'TOKEN', $ids, 'status_type');
    check(count($calls) === 3 && array_unique(array_column($calls, 0)) === ['POST'], '121 id = 3 dávky POST (po 50)');
    check(count($got) === 120 && !isset($got['POST_ERR']) && ($got['X_7']['status_type'] ?? '') === 'added_photos', 'chybný objekt vynechán, ostatní z dávky zůstaly');
    $calls = [];
    $got = allstat_meta_batch_get('https://graph.example.test/v25.0', 'TOKEN', ['POST_SLOW', 'X_1'], 'status_type');
    check(isset($got['POST_SLOW']) && count($calls) === 2, 'položka, kterou Meta nestihla (null), se jednou zopakuje');
    unset($GLOBALS['slow_seen']);
    $calls = [];
    $got = allstat_meta_batch_get('https://graph.example.test/v25.0', 'TOKEN', ['FAIL5XX', 'X_1'], 'status_type');
    check(count($got) === 2 && count($calls) === 2, 'chyba serveru Mety (5xx) u celé dávky se jednou zopakuje');
    $got = allstat_meta_batch_get('https://graph.example.test/v25.0', 'TOKEN', ['LIMIT400', 'X_1'], 'status_type');
    check($got === [], 'vyčerpaný limit (400 celé dávky) nic nezapíše a nespadne');
    check(!str_contains((string) json_encode($calls), 'access_token=TOKEN&') || !array_filter(array_column($calls, 1), static fn (string $u): bool => str_contains($u, 'TOKEN')), 'token je jen v těle POST, ne v adrese');

    echo "Náhledy\n";
    check(allstat_thumb_source_allowed('https://scontent.xx.fbcdn.net/a.jpg') && allstat_thumb_source_allowed('https://scontent.cdninstagram.com/a.jpg'), 'CDN Mety povoleno');
    check(!allstat_thumb_source_allowed('http://scontent.xx.fbcdn.net/a.jpg'), 'bez HTTPS zakázáno');
    check(!allstat_thumb_source_allowed('https://evil.fbcdn.net.attacker.com/a.jpg') && !allstat_thumb_source_allowed('https://example.org/a.jpg') && !allstat_thumb_source_allowed('https://xfbcdn.net/a.jpg'), 'cizí a podvržené hosty zakázány');
    $GLOBALS['allstat_http_mock_saved'] = $GLOBALS['allstat_http_mock'];
    // PNG hlavička 10 000 × 10 000 px (100 Mpx): odmítne se podle rozměrů dřív, než by GD alokovalo paměť.
    $hugePng = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', 10000, 10000) . "\x08\x02\x00\x00\x00" . pack('N', 0);
    $GLOBALS['allstat_http_mock'] = static fn (string $m, string $u): array => ['status' => 200, 'json' => null, 'body' => $hugePng, 'headers' => []];
    check(allstat_thumb_store('https://scontent.xx.fbcdn.net/huge.jpg', 9, 'HUGE') === false, 'obří obrázek se odmítne bez dekódování');
    $GLOBALS['allstat_http_mock'] = static fn (string $m, string $u): array => ['status' => 302, 'json' => null, 'body' => '', 'headers' => ['location' => 'https://example.org/x.jpg']];
    check(allstat_thumb_store('https://scontent.xx.fbcdn.net/redir.jpg', 9, 'REDIR') === null, 'přesměrování se nesleduje');
    $GLOBALS['allstat_http_mock'] = static fn (string $m, string $u): array => ['status' => 200, 'json' => null, 'body' => '<?php echo 1;', 'headers' => []];
    check(allstat_thumb_store('https://scontent.xx.fbcdn.net/fake.jpg', 9, 'FAKE') === false, 'soubor, který není obrázek, se neuloží');
    $GLOBALS['allstat_http_mock'] = static fn (string $m, string $u): array => throw new RuntimeException('Odpověď API přesahuje povolenou velikost.');
    check(allstat_thumb_store('https://scontent.xx.fbcdn.net/big.jpg', 9, 'BIG') === false, 'obrázek nad 1 MB se trvale přeskočí');
    $GLOBALS['allstat_http_mock'] = static fn (string $m, string $u): array => ['status' => 0, 'json' => null, 'body' => '', 'headers' => []];
    check(allstat_thumb_store('https://scontent.xx.fbcdn.net/net.jpg', 9, 'NET') === null, 'výpadek sítě se zkusí příště');
    $GLOBALS['allstat_http_mock'] = $GLOBALS['allstat_http_mock_saved'];
    check(allstat_mcp_query_breakdown_is_snapshot('eng_dem_age') && allstat_mcp_query_breakdown_is_snapshot('dem_age') && !allstat_mcp_query_breakdown_is_snapshot('reach_follow'), 'MCP bere demografii zapojeného publika jako snímek, ne součet');

    // --- 2) Facebook stránka -------------------------------------------------------------------------
    echo "Facebook\n";
    $pdo->prepare("INSERT INTO domain_sources (id, domain_id, source_id, account_label, property_id, api_base_url, status, is_enabled)
        VALUES (1, 1, ?, 'test', 'PAGE', 'https://graph.example.test/v25.0', 'ok', 1)")->execute([$sourceId]);
    $conn = $pdo->query('SELECT * FROM domain_sources WHERE id = 1')->fetch();
    $start = date('Y-m-d', strtotime('-3 days'));
    $end = date('Y-m-d', strtotime('-1 day'));
    $calls = [];
    $res = allstat_engine_meta_graph($pdo, ['app' => ['timezone' => 'Europe/Prague']], $conn, 'TOKEN', $start, $end, allstat_sync_recipe('facebook_pages'));
    $urls = array_column($calls, 1);
    check(!array_filter($urls, static fn (string $u): bool => str_contains($u, '?ids=')), 'žádné volání GET /?ids=');
    check(!array_filter($urls, static fn (string $u): bool => str_contains($u, 'page_impressions_unique') || str_contains($u, 'post_impressions_unique')), 'zrušené metriky dosahu se už nežádají');
    $p1 = $pdo->query("SELECT * FROM social_posts WHERE post_id = 'P_1'")->fetch();
    check((int) $p1['reach'] === 120 && (int) $p1['impressions'] === 200 && (int) $p1['reactions'] === 9 && (int) $p1['comments'] === 2, 'příspěvek má dosah, zobrazení, reakce i komentáře z dávky');
    check(preg_match('/^c1_[0-9a-f]{40}\.jpg$/', (string) $p1['thumb_file']) === 1 && is_file($thumbDir . '/' . $p1['thumb_file']), 'náhled příspěvku se stáhl a uložil');
    [$tw, $th] = getimagesize($thumbDir . '/' . $p1['thumb_file']);
    check($tw === 160 && $th === 120 && filesize($thumbDir . '/' . $p1['thumb_file']) < 15000, 'náhled je zmenšený na 160 px se zachovaným poměrem a je malý');
    check($pdo->query("SELECT thumb_file FROM social_posts WHERE post_id = 'P_TEXT'")->fetchColumn() === '-', 'příspěvek bez obrázku dostal značku „-“');
    check($pdo->query("SELECT thumb_file FROM social_posts WHERE post_id = 'POST_ERR'")->fetchColumn() === '?1', 'příspěvek s chybou API má 1. pokus a zkusí se příště');
    check(allstat_thumb_url((string) $p1['thumb_file']) === 'media/thumbs/' . $p1['thumb_file'] && allstat_thumb_url('../config.php') === '', 'adresa náhledu jen pro platný název souboru');
    check((float) $pdo->query("SELECT metric_value FROM provider_metrics_daily WHERE connection_id = 1 AND metric_key = 'reach_month_unique' AND dimension = '" . date('Y-m') . "'")->fetchColumn() === 749.0, 'diváci za kalendářní měsíc (FB)');
    check((int) $pdo->query("SELECT COUNT(*) FROM provider_metrics_daily WHERE connection_id = 1 AND metric_key = 'video_views'")->fetchColumn() > 0, 'zhlédnutí videí 3 s za stránku');
    check((int) $pdo->query("SELECT COUNT(*) FROM provider_metrics_daily WHERE connection_id = 1 AND dimension = '' AND metric_date = '" . date('Y-m-d', strtotime('-1 day')) . "' AND metric_key = 'reach'")->fetchColumn() === 1
        && !(int) $pdo->query("SELECT COUNT(*) FROM provider_metrics_daily WHERE connection_id = 1 AND dimension = '' AND metric_date = '" . date('Y-m-d') . "' AND metric_key = 'reach'")->fetchColumn(),
        'denní bod s end_time dnešní půlnoci PT se uloží ke včerejšku (jako Business Suite)');
    check((float) $pdo->query("SELECT metric_value FROM provider_metrics_daily WHERE connection_id = 1 AND metric_key = 'reach_28d_unique' AND dimension = '" . date('Y-m-d', strtotime('-1 day')) . "'")->fetchColumn() === 749.0, 'diváci za posledních 28 dní (jako Business Suite)');
    check(str_contains((string) ($res['summary'] ?? ''), 'nových náhledů'), 'souhrn synchronizace hlásí nové náhledy');
    // Dávka historie (2. 3. – 1. 4. před rokem): stáhne různé lidi za březen, kde leží poslední den měsíce.
    $y = (int) date('Y') - 1;
    allstat_engine_meta_graph($pdo, ['app' => ['timezone' => 'Europe/Prague']], $conn, 'TOKEN', "$y-03-02", "$y-04-01", allstat_sync_recipe('facebook_pages'));
    check((float) $pdo->query("SELECT metric_value FROM provider_metrics_daily WHERE connection_id = 1 AND metric_key = 'reach_month_unique' AND dimension = '$y-03'")->fetchColumn() === 749.0
        && (float) $pdo->query("SELECT metric_value FROM provider_metrics_daily WHERE connection_id = 1 AND metric_key = 'reach_month_unique_days' AND dimension = '$y-03'")->fetchColumn() === 31.0,
        'stažení historie po dávkách uloží různé lidi za každý uzavřený měsíc');

    // --- 3) Instagram ----------------------------------------------------------------------------------
    echo "Instagram\n";
    $pdo->prepare("INSERT INTO domain_sources (id, domain_id, source_id, account_label, property_id, api_base_url, status, is_enabled)
        VALUES (2, 1, ?, 'test-ig', 'IGUSER', 'https://graph.example.test/v25.0', 'ok', 1)")->execute([$igSourceId]);
    $conn = $pdo->query('SELECT * FROM domain_sources WHERE id = 2')->fetch();
    $calls = [];
    allstat_engine_meta_graph($pdo, ['app' => ['timezone' => 'Europe/Prague']], $conn, 'TOKEN', $start, $end, allstat_sync_recipe('instagram_business'));
    check(!array_filter(array_column($calls, 1), static fn (string $u): bool => str_contains($u, '?ids=')), 'IG: žádné volání GET /?ids=');
    $m1 = $pdo->query("SELECT * FROM social_posts WHERE post_id = 'M_1'")->fetch();
    check((int) $m1['reach'] === 77 && $m1['post_type'] === 'reel', 'IG reel má dosah z dávky');
    check($m1['thumb_file'] !== '' && $m1['thumb_file'] !== '-' && is_file($thumbDir . '/' . $m1['thumb_file']), 'IG reel má náhled z thumbnail_url (ne z videa)');
    check(is_file($thumbDir . '/' . (string) $pdo->query("SELECT thumb_file FROM social_posts WHERE connection_id = 2 AND post_id = 'S_1'")->fetchColumn()), 'IG story má náhled uložený hned při synchronizaci');
    check((float) $pdo->query("SELECT SUM(metric_value) FROM provider_metrics_daily WHERE connection_id = 2 AND metric_key = 'unfollows' AND dimension = ''")->fetchColumn() > 0, 'IG zrušená sledování (NON_FOLLOWER)');
    check((int) $pdo->query("SELECT COUNT(*) FROM provider_metrics_daily WHERE connection_id = 2 AND metric_key = 'eng_dem_age'")->fetchColumn() > 0, 'IG demografie zapojeného publika');
    check((float) $pdo->query("SELECT metric_value FROM provider_metrics_daily WHERE connection_id = 2 AND metric_key = 'reach_month_unique' AND dimension = '" . date('Y-m') . "'")->fetchColumn() === 2234.0, 'IG dosah za kalendářní měsíc bez dvojího počítání');
    $sumReach = (float) $pdo->query("SELECT SUM(metric_value) FROM provider_metrics_daily WHERE connection_id = 2 AND metric_key = 'reach' AND dimension = ''")->fetchColumn();
    check($sumReach < 2234, 'měsíční dosah se nemíchá do denního součtu dosahu');
    $igMonthCalls = array_filter(array_column($calls, 1), static fn (string $u): bool => str_contains($u, 'metric=reach&period=day&metric_type=total_value') && !str_contains($u, 'breakdown'));
    $maxSpan = 0;
    foreach ($igMonthCalls as $u) {
        parse_str((string) parse_url($u, PHP_URL_QUERY), $qq);
        $maxSpan = max($maxSpan, (int) $qq['until'] - (int) $qq['since']);
    }
    check($igMonthCalls && $maxSpan <= 30 * 86400, 'IG měsíční dosah se ptá nejvýš na 30 dní');
    $w28 = array_column($pdo->query("SELECT metric_key, metric_value FROM provider_metrics_daily WHERE connection_id = 2 AND metric_key LIKE 'w28%'")->fetchAll(), 'metric_value', 'metric_key');
    $dayKeys = array_column($pdo->query("SELECT metric_key, COUNT(*) AS n FROM provider_metrics_daily WHERE connection_id = 2 AND dimension = '' AND metric_key IN ('views', 'interactions') GROUP BY metric_key")->fetchAll(), 'n', 'metric_key');
    check(($dayKeys['views'] ?? 0) > 0 && ($dayKeys['interactions'] ?? 0) >= 40, 'IG denní zobrazení a interakce (napoprvé 45 dní) pro horní karty');
    check((float) ($w28['w28_views'] ?? 0) === 2234.0 && (float) ($w28['w28_follows'] ?? 0) === 3.0 && (float) ($w28['w28_unfollows'] ?? 0) === 1.0 && (float) ($w28['w28_reach_nonfollower'] ?? 0) === 40.0, 'IG 28 dní: zobrazení, sledování, zrušení a rozpad dosahu jako Business Suite');
    $prevYm = date('Y-m', strtotime('first day of last month'));
    $prevDays = (int) $pdo->query("SELECT metric_value FROM provider_metrics_daily WHERE connection_id = 2 AND metric_key = 'reach_month_unique_days' AND dimension = " . $pdo->quote($prevYm))->fetchColumn();
    check($prevDays === min(30, (int) date('t', strtotime($prevYm . '-01'))), 'u minulého měsíce se uloží, kolik dní číslo pokrývá');
    // Druhý běh: živá story už náhled má → CDN se znovu nevolá.
    unset($GLOBALS['media_served'], $GLOBALS['stories_served_/v25.0/IGUSER/stories']);
    $calls = [];
    allstat_engine_meta_graph($pdo, ['app' => ['timezone' => 'Europe/Prague']], $conn, 'TOKEN', $start, $end, allstat_sync_recipe('instagram_business'));
    check(!array_filter(array_column($calls, 1), static fn (string $u): bool => str_contains($u, 's1.jpg')), 'náhled živé story se při dalším běhu nestahuje znovu');

    // --- 4) mazání napojení smaže i náhledy ---------------------------------------------------------------
    allstat_thumbs_delete_connection(1);
    check(!glob($thumbDir . '/c1_*.jpg'), 'odstranění napojení smaže jeho náhledy');
    $htaccess = (string) file_get_contents($thumbDir . '/.htaccess');
    check(str_contains($htaccess, 'Require all denied') && str_contains($htaccess, '(?!jpg$)'), 'složka náhledů pouští ven jen .jpg');

    echo "OK: všechny kontroly prošly\n";
} finally {
    $root->exec("DROP DATABASE IF EXISTS `$dbName`");
    foreach (array_merge(glob($thumbDir . '/*') ?: [], glob($thumbDir . '/.htaccess') ?: []) as $f) { @unlink($f); }
    @rmdir($thumbDir);
}
