<?php

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/sync.php';

$pdo = allstat_admin_require_database($pdo, $config);
$user = allstat_require_user($pdo, $config);
allstat_require_admin($user);
allstat_csrf_check();

header('Content-Type: application/json; charset=utf-8');

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
$mode = (string) ($_POST['mode'] ?? 'incremental');
$connection = $id ? allstat_get_connection($pdo, $id) : null;

if (!$connection) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Napojení neexistuje.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$end = allstat_sync_end_date();

if ($mode === 'backfill') {
    // Single-shot providers (e.g. Clarity: ~3-day rolling window, 10 calls/day) can't be
    // backfilled month-by-month — that would blow the daily call budget on the first run.
    // Collapse the whole "history" into one incremental-style call and report it done.
    $recipe = allstat_sync_recipe((string) ($connection['provider_key'] ?? ''));
    if (is_array($recipe) && !empty($recipe['single_shot'])) {
        $start = allstat_incremental_start_date();
        $end = allstat_incremental_end_date();
        $result = allstat_sync_connection($pdo, $config, $connection, $start, $end, 'backfill');
        if ($result['ok']) {
            $pdo->prepare('UPDATE domain_sources SET backfill_completed_at = NOW() WHERE id = ?')->execute([$id]);
        }
        echo json_encode([
            'ok' => $result['ok'],
            'mode' => 'backfill',
            'message' => $result['message'],
            'chunk' => ['start' => $start, 'end' => $end],
            'next_chunk_start' => null,
            'done' => true,
            'single_shot' => true,
            'backfill_start' => $start,
            'stats' => $result['stats'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Chunked: one month per call, driven by frontend loop.
    $chunkStart = (string) ($_POST['chunk_start'] ?? '');
    $minStart = allstat_backfill_start_date();
    if ($chunkStart === '' || $chunkStart < $minStart) {
        $chunkStart = $minStart;
    }
    $chunk = allstat_backfill_chunk($chunkStart);
    $result = allstat_sync_connection($pdo, $config, $connection, $chunk['start'], $chunk['end'], 'backfill');

    if ($result['ok'] && $chunk['next'] === null) {
        $pdo->prepare('UPDATE domain_sources SET backfill_completed_at = NOW() WHERE id = ?')->execute([$id]);
    }

    echo json_encode([
        'ok' => $result['ok'],
        'mode' => 'backfill',
        'message' => $result['message'],
        'chunk' => ['start' => $chunk['start'], 'end' => $chunk['end']],
        'next_chunk_start' => $result['ok'] ? $chunk['next'] : null,
        'done' => $chunk['next'] === null,
        'backfill_start' => allstat_backfill_start_date(),
        'stats' => $result['stats'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Incremental: last N days incl. today (intraday).
// Manual-sync cooldown: block re-syncing the SAME connection within 60 s (prevents accidental
// double-clicks / hammering the provider API). Per-connection, so syncing other pages is unaffected;
// cron and backfill never hit this path.
$lastSync = $connection['last_sync_at'] ?? null;
if ($lastSync && (time() - strtotime((string) $lastSync)) < 60) {
    echo json_encode([
        'ok' => true,
        'mode' => 'incremental',
        'message' => 'Přeskočeno – tohle napojení bylo synchronizováno před chvílí (cooldown 60 s, šetří API limit). Data jsou aktuální.',
        'stats' => null,
        'cooldown' => true,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$start = allstat_incremental_start_date();
$result = allstat_sync_connection($pdo, $config, $connection, $start, allstat_incremental_end_date(), 'sync');

// Surface Meta API limit usage right in the result (the persistent quota panel only updates on reload).
$message = (string) $result['message'];
$fresh = allstat_get_connection($pdo, $id);
$q = !empty($fresh['quota_json']) ? json_decode((string) $fresh['quota_json'], true) : null;
if (is_array($q) && isset($q['meta_usage_pct'])) {
    $message .= sprintf(' · Meta API limit: %s %% využito (klouzavé 1h okno).', number_format((float) $q['meta_usage_pct'], 1, ',', ' '));
}

echo json_encode([
    'ok' => $result['ok'],
    'mode' => 'incremental',
    'message' => $message,
    'stats' => $result['stats'],
], JSON_UNESCAPED_UNICODE);
