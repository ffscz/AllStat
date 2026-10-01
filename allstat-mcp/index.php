<?php

/**
 * Front controller MCP hostu AllStatu: jediný vstupní bod složky allstat-mcp.
 *
 * Obsluhuje MCP endpoint (/mcp), OAuth 2.1 autorizační server (metadata, /token, /register, /revoke, /jwks.json,
 * /authorize jako přesměrování) a kořen. Bez session, bez _bootstrap.php, bez cookies: čistě Bearer / JSON.
 * Adresa hostu se bere z nastavení mcp.host_url (nikdy z hlavičky Host), takže funguje na kořeni domény i pod
 * prefixem (např. https://www.vase-firma.cz/allstat-mcp) a pod PHP built-in serverem:
 *   php -S 127.0.0.1:8898 -t <tato složka> <tato složka>/index.php
 *
 * Cesta k aplikaci AllStat je v app-path.php (v balíčku ukazuje o složku výš, do složky AllStatu).
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

/** Chybová odpověď JSON (jen když ještě nic nebylo odesláno). */
function allstat_mcp_hostctl_fail(int $status, string $error): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }

    echo '{"error":"' . $error . '"}';
}

set_exception_handler(static function (Throwable $exception): void {
    error_log('allstat-mcp: ' . get_class($exception) . ': ' . $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine());
    allstat_mcp_hostctl_fail(500, 'server_error');
});

register_shutdown_function(static function (): void {
    $error = error_get_last();

    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('allstat-mcp fatal: ' . $error['message'] . ' @ ' . $error['file'] . ':' . $error['line']);
        allstat_mcp_hostctl_fail(500, 'server_error');
    }
});

header('X-Robots-Tag: noindex, nofollow');

$appPath = require __DIR__ . '/app-path.php';
$app = rtrim(str_replace('\\', '/', (string) $appPath), '/');

if (!is_file($app . '/config.php') || !is_file($app . '/lib/mcp-oauth.php')) {
    error_log('allstat-mcp: aplikace AllStat nebyla nalezena v ' . $app);
    allstat_mcp_hostctl_fail(500, 'server_error');
    exit;
}

$config = require $app . '/config.php';
date_default_timezone_set($config['app']['timezone']);

require_once $app . '/lib/helpers.php';
require_once $app . '/lib/database.php';
require_once $app . '/lib/providers.php';
require_once $app . '/lib/migrations.php';
require_once $app . '/lib/auth.php';
require_once $app . '/lib/mcp-oauth.php';

$pdo = allstat_db($config);

if (!$pdo) {
    allstat_mcp_hostctl_fail(503, 'database_unavailable');
    exit;
}

if (!allstat_tables_ready($pdo)) {
    allstat_mcp_hostctl_fail(503, 'not_ready');
    exit;
}

allstat_migrate($pdo); // levné, když je schéma aktuální (2 dotazy)

$route = allstat_mcp_oauth_route($pdo);

if ($route === 'mcp') {
    // Těžké knihovny (reporty, growth, nástroje) se načítají jen pro MCP endpoint; OAuth trasy zůstávají lehké.
    foreach (['repository', 'growth', 'share', 'mcp-server', 'mcp-tools'] as $library) {
        if (is_file($app . '/lib/' . $library . '.php')) {
            require_once $app . '/lib/' . $library . '.php';
        }
    }

    if (!function_exists('allstat_mcp_server_entry')) {
        if (!allstat_mcp_is_enabled($pdo)) {
            allstat_mcp_oauth_handle_not_found();
        }

        allstat_mcp_json_response(503, ['error' => 'not_ready']);
    }

    allstat_mcp_server_entry($pdo, $config);
    exit;
}

if (!allstat_mcp_is_enabled($pdo)) {
    allstat_mcp_oauth_handle_not_found();
}

allstat_mcp_oauth_dispatch($pdo, $route);
