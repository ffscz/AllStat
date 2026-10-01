<?php

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_layout.php';

$pdo = allstat_admin_require_database($pdo, $config);
$user = allstat_require_user($pdo, $config);
allstat_require_admin($user);

$state = (string) ($_GET['state'] ?? '');
$stored = $_SESSION['oauth_state'][$state] ?? null;

if (!$state || !$stored || time() - (int) $stored['created_at'] > 900) {
    allstat_flash('error', 'OAuth state nesedí nebo vypršel. Spusťte připojení znovu.');
    allstat_redirect($config, 'admin/sources.php');
}

unset($_SESSION['oauth_state'][$state]);

// One-click Meta connect: not a per-connection OAuth, but a bulk discovery flow. Exchange the code
// for a long-lived token, discover Pages/IG/Ad accounts, stash them in session, go to the picker.
if (($stored['flow'] ?? '') === 'meta_bulk') {
    require_once __DIR__ . '/../lib/meta-connect.php';
    $error = (string) ($_GET['error'] ?? '');
    if ($error !== '') {
        allstat_flash('error', 'Meta OAuth vrátil chybu: ' . $error);
        allstat_redirect($config, 'admin/meta-connect.php');
    }
    $code = (string) ($_GET['code'] ?? '');
    if ($code === '') {
        allstat_flash('error', 'Meta OAuth callback neobsahuje autorizační kód.');
        allstat_redirect($config, 'admin/meta-connect.php');
    }
    $res = allstat_meta_exchange_and_discover($pdo, $config, $code, (string) $stored['redirect_uri']);
    if (!$res['ok']) {
        allstat_flash('error', $res['message']);
        allstat_redirect($config, 'admin/meta-connect.php');
    }
    $_SESSION['meta_discovery'] = [
        'domain_id' => (int) $stored['domain_id'],
        'user_token' => $res['user_token'],
        'pages' => $res['pages'],
        'adaccounts' => $res['adaccounts'],
        'debug' => $res['debug'] ?? [],
        'created_at' => time(),
    ];
    allstat_audit($pdo, (int) $user['id'], null, 'meta_oauth_connected', 'domain ' . (int) $stored['domain_id']);
    allstat_redirect($config, 'admin/meta-select.php');
}

$id = (int) $stored['connection_id'];
$connection = allstat_get_connection($pdo, $id);
$error = (string) ($_GET['error'] ?? '');
$code = (string) ($_GET['code'] ?? '');

if (!$connection) {
    allstat_flash('error', 'Napojení zdroje neexistuje.');
    allstat_redirect($config, 'admin/sources.php');
}

if ($error !== '') {
    allstat_flash('error', 'OAuth vrátil chybu: ' . $error);
    allstat_redirect($config, 'admin/source-edit.php?id=' . $id);
}

if ($code === '') {
    allstat_flash('error', 'OAuth callback neobsahuje autorizační kód.');
    allstat_redirect($config, 'admin/source-edit.php?id=' . $id);
}

$clientSecret = allstat_decrypt_secret($connection['client_secret_enc'] ?? null, $config);

if (empty($connection['token_url']) || empty($connection['client_id']) || !$clientSecret) {
    allstat_flash('error', 'Pro výměnu kódu chybí Token URL, Client ID nebo Client secret.');
    allstat_redirect($config, 'admin/source-edit.php?id=' . $id);
}

if (!allstat_provider_url_allowed((string) $connection['provider_key'], (string) $connection['token_url'])) {
    allstat_flash('error', 'Token URL není pro tohoto providera povolená.');
    allstat_redirect($config, 'admin/source-edit.php?id=' . $id);
}

$body = http_build_query([
    'grant_type' => 'authorization_code',
    'code' => $code,
    'redirect_uri' => $stored['redirect_uri'],
    'client_id' => $connection['client_id'],
    'client_secret' => $clientSecret,
]);
$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
        'content' => $body,
        'ignore_errors' => true,
        'timeout' => 20,
    ],
]);
$response = @file_get_contents($connection['token_url'], false, $context);
$payload = is_string($response) ? json_decode($response, true) : null;

if (!is_array($payload) || isset($payload['error'])) {
    $detail = is_array($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'Token endpoint nevrátil validní JSON.';
    allstat_add_sync_log($pdo, (int) $connection['domain_id'], (int) $connection['source_id'], 'error', 'OAuth token exchange failed: ' . substr($detail, 0, 190));
    allstat_flash('error', 'Token se nepodařilo získat. Detail je v sync logu.');
    allstat_redirect($config, 'admin/source-edit.php?id=' . $id);
}

$accessToken = (string) ($payload['access_token'] ?? '');
$refreshToken = (string) ($payload['refresh_token'] ?? '');
$expiresAt = null;

if (!empty($payload['expires_in'])) {
    $expiresAt = (new DateTimeImmutable('now'))->modify('+' . (int) $payload['expires_in'] . ' seconds')->format('Y-m-d H:i:s');
}

$statement = $pdo->prepare('UPDATE domain_sources SET access_token_enc = COALESCE(?, access_token_enc), refresh_token_enc = COALESCE(?, refresh_token_enc), token_expires_at = ?, status = ?, note = ?, updated_at = NOW() WHERE id = ?');
$statement->execute([
    $accessToken !== '' ? allstat_encrypt_secret($accessToken, $config) : null,
    $refreshToken !== '' ? allstat_encrypt_secret($refreshToken, $config) : null,
    $expiresAt,
    $accessToken !== '' ? 'ok' : 'warning',
    $accessToken !== '' ? 'OAuth token uložen' : 'OAuth callback proběhl bez access tokenu',
    $id,
]);
allstat_add_sync_log($pdo, (int) $connection['domain_id'], (int) $connection['source_id'], 'info', 'OAuth callback uložil tokeny.');
allstat_audit($pdo, (int) $user['id'], null, 'oauth_connected', (string) $id);
allstat_flash('ok', 'OAuth callback proběhl a tokeny byly uloženy.');
allstat_redirect($config, 'admin/source-edit.php?id=' . $id);
