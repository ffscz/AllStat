<?php

/**
 * AllStat denní sync cron.
 *
 * HTTP:  https://example.com/allstat/cron/sync.php?secret=XXX[&force=1]
 * CLI:   php cron/sync.php XXX [--force]
 *
 * Secret je v Nastavení (sync.cron_secret). Iteruje všechna zapnutá napojení
 * a stáhne posledních 7 dní (incremental). Backfill historie se dělá ručně.
 *
 * Dávkový režim (Nastavení → cron, sync.cron_max_connections / sync.cron_max_seconds > 0): jedno spuštění
 * zpracuje jen omezený počet napojení (nebo jen po danou dobu) a další spuštění pokračuje napojeními, která
 * dnes ještě nedošla. Cron se pak pouští častěji (např. co 10 minut v noci). Každé napojení dojde nejvýš
 * jednou denně, i když skončí chybou; force=1 vezme znovu všechna. Bez limitů se chová jako dřív.
 */

$isCli = PHP_SAPI === 'cli';
$config = require __DIR__ . '/../config.php';
date_default_timezone_set($config['app']['timezone']);

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/database.php';
require_once __DIR__ . '/../lib/migrations.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/crypto.php';
require_once __DIR__ . '/../lib/admin-repository.php';
require_once __DIR__ . '/../lib/sync.php';

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
}

$pdo = allstat_db($config);
if (!$pdo || !allstat_tables_ready($pdo)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => 'Databáze není připravená.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$expectedSecret = (string) allstat_setting($pdo, 'sync.cron_secret', '');
$providedSecret = $isCli ? (string) ($argv[1] ?? '') : (string) ($_GET['secret'] ?? $_SERVER['HTTP_X_CRON_SECRET'] ?? '');

if ($expectedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Neplatný cron secret.'], JSON_UNESCAPED_UNICODE);
    exit;
}

@set_time_limit(0);
ignore_user_abort(true);

// Zámek: běh, který ještě nedoběhl, se nesmí potkat s dalším spuštěním (dávky co pár minut). MySQL ho uvolní
// samo i při pádu skriptu (konec spojení).
if ((int) $pdo->query("SELECT GET_LOCK('allstat_cron_sync', 0)")->fetchColumn() !== 1) {
    echo json_encode(['ok' => true, 'message' => 'Předchozí běh ještě pokračuje, toto spuštění nic nedělá.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$force = $isCli ? in_array('--force', $argv, true) : (string) ($_GET['force'] ?? '') === '1';
$maxConnections = max(0, (int) allstat_setting($pdo, 'sync.cron_max_connections', '0'));
$maxSeconds = max(0, (int) allstat_setting($pdo, 'sync.cron_max_seconds', '0'));
$batchMode = $maxConnections > 0 || $maxSeconds > 0;
$todayStart = (new DateTimeImmutable('today'))->format('Y-m-d H:i:s');
$startedAt = microtime(true);

$start = allstat_incremental_start_date();
$end = allstat_incremental_end_date();
// Nejdéle čekající napojení první (nikdy nezpracovaná, pak nejstarší last_cron_at), aby se při dávkách žádné nezasekávalo.
$connections = allstat_source_connections($pdo);
usort($connections, static fn (array $a, array $b): int => [(string) ($a['last_cron_at'] ?? ''), (int) $a['id']] <=> [(string) ($b['last_cron_at'] ?? ''), (int) $b['id']]);
$markCron = $pdo->prepare('UPDATE domain_sources SET last_cron_at = ? WHERE id = ?');
$results = [];
$okCount = 0;
$skipCount = 0;
$errCount = 0;
$doneToday = 0;
$remaining = 0;

foreach ($connections as $row) {
    if ((int) ($row['is_enabled'] ?? 0) !== 1) {
        $skipCount++;
        continue;
    }

    if ($batchMode && !$force && (string) ($row['last_cron_at'] ?? '') >= $todayStart) {
        $doneToday++;
        continue;
    }

    $processed = $okCount + $errCount;
    if (($maxConnections > 0 && $processed >= $maxConnections) || ($maxSeconds > 0 && microtime(true) - $startedAt >= $maxSeconds)) {
        $remaining++;
        continue;
    }

    // Re-load with decrypted config_json + fresh token fields.
    $connection = allstat_get_connection($pdo, (int) $row['id']);
    if (!$connection) {
        continue;
    }

    $result = allstat_sync_connection($pdo, $config, $connection, $start, $end, 'cron');
    $markCron->execute([(new DateTimeImmutable('now'))->format('Y-m-d H:i:s'), (int) $connection['id']]);
    $results[] = [
        'id' => (int) $connection['id'],
        'domain' => $connection['domain_url'],
        'provider' => $connection['provider_key'],
        'ok' => $result['ok'],
        'message' => $result['message'],
    ];
    $result['ok'] ? $okCount++ : $errCount++;
}

allstat_add_sync_log_global($pdo, $okCount, $errCount, $skipCount, $batchMode ? [$remaining, $doneToday + $okCount + $errCount] : null);
$pdo->query("SELECT RELEASE_LOCK('allstat_cron_sync')");

// Kontrola nové verze AllStatu (nejvýš jednou za 12 h, jen instalace z veřejného balíčku); chyba sítě cron neshodí.
try {
    if (is_file(__DIR__ . '/../lib/updater.php')) {
        require_once __DIR__ . '/../lib/updater.php';
        allstat_update_check($pdo);
    }
} catch (Throwable) {
}

echo json_encode([
    'ok' => $errCount === 0,
    'range' => $start . ' → ' . $end,
    'synced' => $okCount,
    'errors' => $errCount,
    'skipped_disabled' => $skipCount,
    'batch' => $batchMode ? ['max_connections' => $maxConnections, 'max_seconds' => $maxSeconds, 'done_earlier_today' => $doneToday, 'remaining' => $remaining] : null,
    'results' => $results,
], JSON_UNESCAPED_UNICODE | ($isCli ? JSON_PRETTY_PRINT : 0));

function allstat_add_sync_log_global(PDO $pdo, int $ok, int $err, int $skip, ?array $batch): void
{
    // Audit-style line tied to first domain/source if any; otherwise a settings note.
    allstat_set_setting($pdo, 'sync.last_cron_run', (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'));
    allstat_set_setting($pdo, 'sync.last_cron_summary', sprintf('ok=%d err=%d skip=%d', $ok, $err, $skip) . ($batch !== null ? sprintf(' zbývá=%d hotovo_dnes=%d', $batch[0], $batch[1]) : ''));
}
