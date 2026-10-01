<?php

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_layout.php';

$pdo = allstat_admin_require_database($pdo, $config);
$user = allstat_require_user($pdo, $config);
allstat_require_admin($user);

$id = filter_input(INPUT_GET, 'connection_id', FILTER_VALIDATE_INT) ?: 0;
$connection = $id ? allstat_get_connection($pdo, $id) : null;

if (!$connection) {
    allstat_flash('error', 'Napojení zdroje neexistuje.');
    allstat_redirect($config, 'admin/sources.php');
}

if (empty($connection['auth_url']) || empty($connection['client_id'])) {
    $missing = [];
    if (empty($connection['client_id'])) { $missing[] = 'Client ID'; }
    if (empty($connection['auth_url'])) { $missing[] = 'Authorization URL'; }
    allstat_flash('error', 'Nejdřív vyplň a ulož ' . implode(' + ', $missing) . ' v sekci OAuth/API. Tip: u Google služeb použij stejný Client ID i Client secret jako u GA4.');
    allstat_redirect($config, 'admin/source-edit.php?id=' . $id);
}

if (!allstat_provider_url_allowed((string) $connection['provider_key'], (string) $connection['auth_url'])) {
    allstat_flash('error', 'Authorization URL není pro tohoto providera povolená.');
    allstat_redirect($config, 'admin/source-edit.php?id=' . $id);
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$redirectUri = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . allstat_url($config, 'admin/oauth-callback.php');
$state = bin2hex(random_bytes(24));
$_SESSION['oauth_state'][$state] = [
    'connection_id' => $id,
    'redirect_uri' => $redirectUri,
    'created_at' => time(),
];

$params = [
    'response_type' => 'code',
    'client_id' => $connection['client_id'],
    'redirect_uri' => $redirectUri,
    'scope' => trim((string) ($connection['scopes'] ?? '')),
    'state' => $state,
];

if (in_array($connection['provider_key'], ['ga4', 'gsc', 'google_ads', 'youtube'], true)) {
    $params['access_type'] = 'offline';
    $params['prompt'] = 'consent';
}

$separator = str_contains($connection['auth_url'], '?') ? '&' : '?';
header('Location: ' . $connection['auth_url'] . $separator . http_build_query($params));
exit;
