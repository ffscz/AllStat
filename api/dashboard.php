<?php

$config = require __DIR__ . '/../config.php';
date_default_timezone_set($config['app']['timezone']);

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/database.php';
require_once __DIR__ . '/../lib/migrations.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/repository.php';

allstat_session_start($config);
$pdo = allstat_db($config);

header('Content-Type: application/json; charset=utf-8');

if (!$pdo || !allstat_tables_ready($pdo)) {
	http_response_code(503);
	echo allstat_json(['ok' => false, 'message' => 'AllStat není nainstalovaný.']);
	exit;
}

allstat_migrate($pdo);

if (!allstat_is_authenticated($pdo)) {
	http_response_code(401);
	echo allstat_json(['ok' => false, 'message' => 'Vyžaduje přihlášení.']);
	exit;
}

$defaultEnd = (new DateTimeImmutable('today'))->format('Y-m-d');
$defaultStart = (new DateTimeImmutable('today'))->modify('-6 days')->format('Y-m-d');
$start = allstat_normalize_date($_GET['start'] ?? null, $defaultStart);
$end = allstat_normalize_date($_GET['end'] ?? null, $defaultEnd);
[$start, $end] = allstat_limited_range($start, $end);
$granularity = allstat_auto_granularity($start, $end);
// Stejná logika jako index.php: výběr webu z filtru se uloží do session, bez parametru platí zapamatovaný web.
$domains = allstat_get_domains($pdo);
if (!$domains && allstat_domain_scope() !== null) {
	http_response_code(403);
	echo allstat_json(['ok' => false, 'message' => 'Nemáte přidělený žádný web.']);
	exit;
}
$domainId = allstat_current_domain_id($pdo, $domains, filter_input(INPUT_GET, 'domain_id', FILTER_VALIDATE_INT) ?: null) ?: 1;
$data = allstat_get_dashboard_data($pdo, (int) $domainId, $start, $end, $granularity);

echo allstat_json(['ok' => true, 'data' => $data]);
