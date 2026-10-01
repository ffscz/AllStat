<?php

/**
 * One-click Meta connect: a single OAuth against ONE app-wide Meta App (stored in allstat_settings),
 * then auto-discovery of the user's Facebook Pages (+ linked Instagram accounts) and Ad accounts via
 * the Graph API, so the admin just ticks which assets to attach — no per-connection form filling.
 *
 * Flow: meta-connect.php (start) → facebook OAuth → oauth-callback.php (flow=meta_bulk branch) →
 *       allstat_meta_exchange_and_discover() → meta-select.php → allstat_meta_create_connections().
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/oauth.php';

const ALLSTAT_META_GRAPH = 'https://graph.facebook.com/v25.0';
// Combined read scopes for Pages insights + Instagram insights + Ads read (one consent for all three).
const ALLSTAT_META_SCOPES = 'pages_show_list,pages_read_engagement,read_insights,business_management,instagram_basic,instagram_manage_insights,ads_read';

/**
 * The single app-wide Meta App credentials (App ID + decrypted App Secret) from settings.
 */
function allstat_meta_app(PDO $pdo, array $config): array
{
    $appId = trim((string) allstat_setting($pdo, 'meta.app_id', ''));
    $secret = (string) allstat_decrypt_secret((string) allstat_setting($pdo, 'meta.app_secret_enc', ''), $config);

    return [
        'app_id' => $appId,
        'app_secret' => $secret,
        'configured' => $appId !== '' && $secret !== '',
    ];
}

function allstat_meta_save_app(PDO $pdo, array $config, string $appId, string $appSecret): void
{
    allstat_set_setting($pdo, 'meta.app_id', trim($appId));
    if (trim($appSecret) !== '') {
        allstat_set_setting($pdo, 'meta.app_secret_enc', (string) allstat_encrypt_secret(trim($appSecret), $config));
    }
}

/**
 * Requested OAuth scopes — editable in settings (meta.scopes) so you can request only the
 * permissions your Meta App actually has enabled (a missing one makes Facebook reject ALL at once).
 */
function allstat_meta_scopes(PDO $pdo): string
{
    $scopes = trim((string) allstat_setting($pdo, 'meta.scopes', ''));
    // Normalize whitespace/newlines to a clean comma list.
    if ($scopes !== '') {
        $parts = preg_split('/[\s,]+/', $scopes, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return implode(',', $parts);
    }

    return ALLSTAT_META_SCOPES;
}

function allstat_meta_oauth_url(string $appId, string $redirectUri, string $state, string $scopes): string
{
    return 'https://www.facebook.com/v25.0/dialog/oauth?' . http_build_query([
        'client_id' => $appId,
        'redirect_uri' => $redirectUri,
        'state' => $state,
        'scope' => $scopes,
        'response_type' => 'code',
    ]);
}

/**
 * Follow Graph API cursor pagination, collecting every data[] row (bounded by a page guard).
 */
function allstat_meta_fetch_all(string $url): array
{
    $out = [];
    $guard = 0;

    while ($url !== '' && $guard < 20) {
        $r = allstat_http_request('GET', $url, ['Accept' => 'application/json'], null, 30);
        if ($r['status'] !== 200 || !is_array($r['json'])) {
            break;
        }
        foreach ($r['json']['data'] ?? [] as $row) {
            $out[] = $row;
        }
        $url = (string) ($r['json']['paging']['next'] ?? '');
        $guard++;
    }

    return $out;
}

/**
 * Exchange the OAuth code for a long-lived user token, then discover Pages (+ IG) and Ad accounts.
 * Pages are gathered from BOTH /me/accounts (classic personally-managed pages) AND every Business
 * portfolio's owned_pages/client_pages (org pages live there and don't show in /me/accounts), then
 * deduped. A `debug` block records each call's HTTP status/count/error so connection issues are
 * diagnosable from the picker instead of guessed at.
 * Returns ['ok'=>bool, 'message'=>?, 'user_token'=>?, 'pages'=>[], 'adaccounts'=>[], 'debug'=>[]].
 */
function allstat_meta_exchange_and_discover(PDO $pdo, array $config, string $code, string $redirectUri): array
{
    $app = allstat_meta_app($pdo, $config);
    if (!$app['configured']) {
        return ['ok' => false, 'message' => 'Meta App není nastavená (App ID / Secret).'];
    }

    // 1) authorization code → short-lived user token
    $r = allstat_http_request('GET', ALLSTAT_META_GRAPH . '/oauth/access_token?' . http_build_query([
        'client_id' => $app['app_id'],
        'client_secret' => $app['app_secret'],
        'redirect_uri' => $redirectUri,
        'code' => $code,
    ]), ['Accept' => 'application/json'], null, 30);

    if ($r['status'] !== 200 || empty($r['json']['access_token'])) {
        $detail = $r['json']['error']['message'] ?? substr($r['body'], 0, 180);
        return ['ok' => false, 'message' => 'Výměna kódu za token selhala: ' . $detail];
    }
    $shortToken = (string) $r['json']['access_token'];

    // 2) short-lived → long-lived user token (~60 dní); fall back to short if exchange fails
    $r = allstat_http_request('GET', ALLSTAT_META_GRAPH . '/oauth/access_token?' . http_build_query([
        'grant_type' => 'fb_exchange_token',
        'client_id' => $app['app_id'],
        'client_secret' => $app['app_secret'],
        'fb_exchange_token' => $shortToken,
    ]), ['Accept' => 'application/json'], null, 30);
    $longToken = !empty($r['json']['access_token']) ? (string) $r['json']['access_token'] : $shortToken;

    $pageFields = rawurlencode('id,name,access_token,instagram_business_account{id,username}');
    $tok = urlencode($longToken);
    $debug = [];
    $probe = static function (string $url) use (&$debug, $tok): array {
        $resp = allstat_http_request('GET', $url . '&access_token=' . $tok, ['Accept' => 'application/json'], null, 30);
        return ['status' => $resp['status'], 'count' => count($resp['json']['data'] ?? []), 'error' => $resp['json']['error']['message'] ?? null];
    };

    // who am I (token sanity)
    $me = allstat_http_request('GET', ALLSTAT_META_GRAPH . '/me?fields=id,name&access_token=' . $tok, ['Accept' => 'application/json'], null, 30);
    $debug['me'] = ['status' => $me['status'], 'id' => $me['json']['id'] ?? null, 'name' => $me['json']['name'] ?? null, 'error' => $me['json']['error']['message'] ?? null];

    $byId = [];

    // a) personally-managed pages
    $accUrl = ALLSTAT_META_GRAPH . '/me/accounts?fields=' . $pageFields . '&limit=100';
    $debug['me/accounts'] = $probe($accUrl);
    foreach (allstat_meta_fetch_all($accUrl . '&access_token=' . $tok) as $p) {
        $id = (string) ($p['id'] ?? '');
        if ($id !== '') { $byId[$id] = $p; }
    }

    // b) pages owned/managed via Business portfolios (org pages live here, not in /me/accounts)
    $bizUrl = ALLSTAT_META_GRAPH . '/me/businesses?fields=id,name&limit=100';
    $debug['me/businesses'] = $probe($bizUrl);
    foreach (allstat_meta_fetch_all($bizUrl . '&access_token=' . $tok) as $biz) {
        $bid = (string) ($biz['id'] ?? '');
        if ($bid === '') { continue; }
        foreach (['owned_pages', 'client_pages'] as $edge) {
            foreach (allstat_meta_fetch_all(ALLSTAT_META_GRAPH . '/' . rawurlencode($bid) . '/' . $edge . '?fields=' . $pageFields . '&limit=100&access_token=' . $tok) as $p) {
                $id = (string) ($p['id'] ?? '');
                if ($id !== '' && !isset($byId[$id])) { $byId[$id] = $p; }
            }
        }
    }

    // c) business-owned pages often omit access_token on the edge → fetch a page token per page.
    foreach ($byId as $id => $p) {
        if (empty($p['access_token'])) {
            $t = allstat_http_request('GET', ALLSTAT_META_GRAPH . '/' . rawurlencode($id) . '?fields=access_token&access_token=' . $tok, ['Accept' => 'application/json'], null, 30);
            if (!empty($t['json']['access_token'])) { $byId[$id]['access_token'] = (string) $t['json']['access_token']; }
        }
    }
    $pages = array_values($byId);
    $debug['pages_total'] = count($pages);

    // ad accounts
    $adUrl = ALLSTAT_META_GRAPH . '/me/adaccounts?fields=' . rawurlencode('id,account_id,name') . '&limit=100';
    $debug['me/adaccounts'] = $probe($adUrl);
    $adaccounts = allstat_meta_fetch_all($adUrl . '&access_token=' . $tok);

    return ['ok' => true, 'user_token' => $longToken, 'pages' => $pages, 'adaccounts' => $adaccounts, 'debug' => $debug];
}

/**
 * Create/refresh domain_sources connections for the picked Pages / IG accounts / Ad accounts.
 * Page & IG insights use the per-page token (long-lived); Ads use the long-lived user token.
 * ON DUPLICATE KEY (domain_id, source_id, property_id) → re-running refreshes tokens, no dupes.
 * Returns ['ok'=>true, 'created'=>int].
 */
function allstat_meta_create_connections(PDO $pdo, array $config, int $domainId, array $selection, array $assets): array
{
    $app = allstat_meta_app($pdo, $config);
    $secretEnc = allstat_encrypt_secret($app['app_secret'], $config);

    $sources = [];
    foreach (allstat_fetch_all($pdo, "SELECT id, provider_key, auth_url, token_url, api_base_url, default_scopes
        FROM data_sources WHERE provider_key IN ('facebook_pages','instagram_business','meta_ads')") as $s) {
        $sources[(string) $s['provider_key']] = $s;
    }

    $stmt = $pdo->prepare("
        INSERT INTO domain_sources
            (domain_id, source_id, account_label, property_id, external_account_id, client_id, client_secret_enc,
             access_token_enc, scopes, auth_url, token_url, api_base_url, status, is_enabled, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ok', 1, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            account_label = VALUES(account_label), client_id = VALUES(client_id),
            client_secret_enc = VALUES(client_secret_enc), access_token_enc = VALUES(access_token_enc),
            scopes = VALUES(scopes), auth_url = VALUES(auth_url), token_url = VALUES(token_url),
            api_base_url = VALUES(api_base_url), external_account_id = VALUES(external_account_id),
            status = 'ok', is_enabled = 1, updated_at = NOW()
    ");

    $created = 0;
    $insert = function (array $src, string $propertyId, string $label, string $token, string $externalId) use ($stmt, $config, $domainId, $app, $secretEnc, &$created): void {
        if ($propertyId === '') { return; }
        $stmt->execute([
            $domainId, (int) $src['id'], mb_substr($label, 0, 160), mb_substr($propertyId, 0, 120), mb_substr($externalId, 0, 160),
            $app['app_id'], $secretEnc, allstat_encrypt_secret($token, $config),
            (string) $src['default_scopes'], (string) $src['auth_url'], (string) $src['token_url'], (string) $src['api_base_url'],
        ]);
        $created++;
    };

    $pageById = [];
    foreach ($assets['pages'] ?? [] as $p) { $pageById[(string) ($p['id'] ?? '')] = $p; }
    $userToken = (string) ($assets['user_token'] ?? '');

    foreach ((array) ($selection['pages'] ?? []) as $pageId) {
        $p = $pageById[(string) $pageId] ?? null;
        if (!$p || empty($sources['facebook_pages'])) { continue; }
        $insert($sources['facebook_pages'], (string) $p['id'], (string) ($p['name'] ?? ('Stránka ' . $p['id'])), (string) ($p['access_token'] ?? $userToken), '');
    }

    foreach ((array) ($selection['ig'] ?? []) as $pageId) {
        $p = $pageById[(string) $pageId] ?? null;
        if (!$p || empty($p['instagram_business_account']['id']) || empty($sources['instagram_business'])) { continue; }
        $ig = $p['instagram_business_account'];
        $insert($sources['instagram_business'], (string) $ig['id'], '@' . (string) ($ig['username'] ?? $ig['id']), (string) ($p['access_token'] ?? $userToken), '');
    }

    $adById = [];
    foreach ($assets['adaccounts'] ?? [] as $a) { $adById[(string) ($a['id'] ?? '')] = $a; }

    foreach ((array) ($selection['ads'] ?? []) as $adId) {
        $a = $adById[(string) $adId] ?? null;
        if (!$a || empty($sources['meta_ads'])) { continue; }
        $insert($sources['meta_ads'], (string) $a['id'], (string) ($a['name'] ?? $a['id']), $userToken, (string) ($a['account_id'] ?? ''));
    }

    return ['ok' => true, 'created' => $created];
}
