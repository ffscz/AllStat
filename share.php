<?php

/**
 * PUBLIC (no auth) temporary share endpoint. Serves ONE dashboard view as Markdown (default) or JSON for
 * a valid, unexpired token. The token is the access control — see lib/share.php. Intended to be pasted
 * into an AI assistant, which fetches this URL and analyses the report.
 */

$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['app']['timezone']);

require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/database.php';
require_once __DIR__ . '/lib/providers.php';
require_once __DIR__ . '/lib/repository.php';
require_once __DIR__ . '/lib/growth.php';
require_once __DIR__ . '/lib/share.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$pdo = allstat_db($config);
if (!$pdo || !allstat_tables_ready($pdo)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Databáze není připravená.';
    exit;
}

allstat_share_cleanup($pdo);

$share = allstat_share_lookup($pdo, (string) ($_GET['t'] ?? ''));
if (!$share) {
    http_response_code(410); // Gone
    header('Content-Type: text/plain; charset=utf-8');
    echo "Tento sdílecí odkaz vypršel nebo neexistuje.\n\nDočasné odkazy z AllStatu platí max 15 minut. Vygeneruj si v AllStatu nový a zkus to znovu.";
    exit;
}

$format = (string) ($_GET['f'] ?? $share['format'] ?? 'md');

try {
    $payload = allstat_share_build($pdo, $config, $share);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Report se nepodařilo sestavit.';
    exit;
}

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} else {
    // text/plain so assistants and browsers receive the raw Markdown (no download prompt).
    header('Content-Type: text/plain; charset=utf-8');
    echo allstat_share_to_md($payload);
}
