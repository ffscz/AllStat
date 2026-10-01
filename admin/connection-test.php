<?php

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/oauth.php';

$pdo = allstat_admin_require_database($pdo, $config);
$user = allstat_require_user($pdo, $config);
allstat_require_admin($user);
allstat_csrf_check();

header('Content-Type: application/json; charset=utf-8');

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$connection = $id ? allstat_get_connection($pdo, $id) : null;

if (!$connection) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Napojení neexistuje.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = allstat_test_connection($pdo, $config, $connection);

$status = $result['ok'] ? 'ok' : 'error';
$note = mb_substr((string) ($result['message'] ?? ''), 0, 220);
$statement = $pdo->prepare('UPDATE domain_sources SET status = ?, note = ?, updated_at = NOW() WHERE id = ?');
$statement->execute([$status, $note, $id]);

allstat_add_sync_log(
    $pdo,
    (int) $connection['domain_id'],
    (int) $connection['source_id'],
    $result['ok'] ? 'info' : 'error',
    'Test napojení: ' . $note
);

echo json_encode($result, JSON_UNESCAPED_UNICODE);
