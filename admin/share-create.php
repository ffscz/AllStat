<?php

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/growth.php';
require_once __DIR__ . '/../lib/share.php';

$pdo = allstat_admin_require_database($pdo, $config);
$user = allstat_require_user($pdo, $config);
allstat_csrf_check();

header('Content-Type: application/json; charset=utf-8');

$domainId = filter_input(INPUT_POST, 'domain_id', FILTER_VALIDATE_INT) ?: 0;
$viewSourceId = filter_input(INPUT_POST, 'source_id', FILTER_VALIDATE_INT) ?: 0;
$defaultEnd = (new DateTimeImmutable('yesterday'))->format('Y-m-d');
$defaultStart = (new DateTimeImmutable('yesterday'))->modify('-29 days')->format('Y-m-d');
$start = allstat_normalize_date($_POST['start'] ?? null, $defaultStart);
$end = allstat_normalize_date($_POST['end'] ?? null, $defaultEnd);
$format = (string) ($_POST['format'] ?? 'md');
// Granularitu ODVOZUJEME z rozsahu (klient dřív posílal zastaralou hodnotu). repository.php tu není
// načtené, proto inline stejná logika jako allstat_auto_granularity: ≤45 dní = den, ≤184 = týden, jinak měsíc.
try {
    $spanDays = (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days + 1;
} catch (Throwable) {
    $spanDays = 30;
}
$granularity = $spanDays <= 45 ? 'day' : ($spanDays <= 184 ? 'week' : 'month');
// Růst kanálů: report za okno uzavřených měsíců pohledu (3 / 6 / 12), ne za denní rozsah filtru.
if ((string) ($_POST['view'] ?? '') === 'growth') {
    $growthWindow = allstat_growth_window(filter_input(INPUT_POST, 'months', FILTER_VALIDATE_INT) ?: 12);
    $start = $growthWindow['start'];
    $end = $growthWindow['end'];
    $granularity = 'growth';
    $viewSourceId = 0;
}
$ttl = (int) ($_POST['ttl'] ?? 15);

if ($domainId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Chybí web.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Odkaz smí vzniknout jen na web, ke kterému má uživatel přístup, a jen na napojení toho webu
// (jinak by šlo přes id napojení číst data cizího webu).
$domainOk = allstat_domain_in_scope($domainId)
    && allstat_fetch_one($pdo, 'SELECT id FROM domains WHERE id = ? AND is_active = 1', [$domainId]) !== null;
$sourceOk = $viewSourceId === 0
    || allstat_fetch_one($pdo, 'SELECT id FROM domain_sources WHERE id = ? AND domain_id = ?', [$viewSourceId, $domainId]) !== null;
if (!$domainOk || !$sourceOk) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'K tomuto webu nebo zdroji nemáte přístup.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $res = allstat_share_create($pdo, $domainId, $viewSourceId, $start, $end, $format, $granularity, $ttl);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Odkaz se nepodařilo vytvořit.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$url = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . allstat_url($config, 'share.php')
    . '?t=' . $res['token'] . '&f=' . $res['format'];

echo json_encode([
    'ok' => true,
    'url' => $url,
    'format' => $res['format'],
    'expires_at' => $res['expires_at'],
    'ttl_minutes' => $res['ttl_minutes'],
], JSON_UNESCAPED_UNICODE);
