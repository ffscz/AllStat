<?php

/**
 * PUBLIC (no auth) read-only data feed. Serves ONE view's KPIs as CSV (default), TSV or JSON for a valid,
 * non-revoked feed token. Built for Google Sheets =IMPORTDATA and other pull consumers. The token is the
 * access control (see lib/feed.php) and exposes only aggregated analytics. The rolling range is resolved at
 * request time, so the sheet always sees fresh numbers.
 */

$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['app']['timezone']);

require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/database.php';
require_once __DIR__ . '/lib/providers.php';
require_once __DIR__ . '/lib/repository.php';
require_once __DIR__ . '/lib/feed.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');

$pdo = allstat_db($config);
if (!$pdo || !allstat_tables_ready($pdo)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Databáze není připravená.';
    exit;
}

$feed = allstat_feed_lookup($pdo, (string) ($_GET['key'] ?? ''));
if (!$feed) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Feed neexistuje nebo byl zneplatněn.\n\nVygeneruj si nový v AllStatu → Datový feed.";
    exit;
}

allstat_feed_touch($pdo, (string) $feed['token']);
$format = strtolower((string) ($_GET['format'] ?? $_GET['f'] ?? $feed['format'] ?? 'csv'));

try {
    $data = allstat_feed_build($pdo, $feed);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Feed se nepodařilo sestavit.';
    exit;
}

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data['rows'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} elseif ($format === 'tsv') {
    header('Content-Type: text/tab-separated-values; charset=utf-8');
    echo allstat_feed_to_delimited($data, "\t");
} else {
    header('Content-Type: text/csv; charset=utf-8');
    echo allstat_feed_to_delimited($data, ',');
}
