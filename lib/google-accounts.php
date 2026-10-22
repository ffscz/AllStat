<?php

/**
 * Google účty: jedno přihlášení Googlem (OAuth) pro víc webů.
 *
 * Instalace má jednoho Google OAuth klienta (Client ID a secret v nastavení, jako Meta App). Administrátor přidá
 * jeden nebo víc pojmenovaných účtů (například „CITADELA“, „MLMEDIA“); obnovovací token se uloží k účtu, ne
 * k napojení. Z účtu se načte seznam GA4 property a webů ze Search Console a vybrané se naimportují jako weby
 * a napojení s vyplněným domain_sources.oauth_account_id. Sync si pak token bere z účtu
 * (allstat_get_valid_access_token v lib/oauth.php). Napojení s vlastním Client ID a tokeny fungují dál beze změny.
 */

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/oauth.php';

const ALLSTAT_GOOGLE_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
const ALLSTAT_GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
const ALLSTAT_GOOGLE_REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
const ALLSTAT_GOOGLE_USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';
const ALLSTAT_GOOGLE_ADMIN_API = 'https://analyticsadmin.googleapis.com/v1beta';
const ALLSTAT_GOOGLE_GSC_SITES_URL = 'https://searchconsole.googleapis.com/webmasters/v3/sites';
const ALLSTAT_GOOGLE_SCOPE_GA4 = 'https://www.googleapis.com/auth/analytics.readonly';
const ALLSTAT_GOOGLE_SCOPE_GSC = 'https://www.googleapis.com/auth/webmasters.readonly';

/** Provideři, kteří umí brát token z Google účtu. */
const ALLSTAT_GOOGLE_ACCOUNT_PROVIDERS = ['ga4', 'gsc'];

// ---------------------------------------------------------------------------------------------
// OAuth klient instalace
// ---------------------------------------------------------------------------------------------

/** @return array{client_id:string, client_secret:string, configured:bool} */
function allstat_google_client(PDO $pdo, array $config): array
{
    $clientId = (string) allstat_setting($pdo, 'google.client_id', '');
    $secret = (string) (allstat_decrypt_secret((string) allstat_setting($pdo, 'google.client_secret_enc', ''), $config) ?? '');

    return ['client_id' => $clientId, 'client_secret' => $secret, 'configured' => $clientId !== '' && $secret !== ''];
}

/** Uloží klienta; prázdný secret ponechá ten uložený. */
function allstat_google_save_client(PDO $pdo, array $config, string $clientId, string $clientSecret): array
{
    $clientId = trim($clientId);
    $clientSecret = trim($clientSecret);

    if ($clientId === '' || preg_match('/^[A-Za-z0-9._-]{10,255}$/', $clientId) !== 1) {
        return ['ok' => false, 'message' => t('Client ID nevypadá platně (má tvar 1234-abc.apps.googleusercontent.com).')];
    }
    if ($clientSecret === '' && allstat_google_client($pdo, $config)['client_secret'] === '') {
        return ['ok' => false, 'message' => t('Vyplňte i Client secret.')];
    }

    allstat_set_setting($pdo, 'google.client_id', $clientId);
    if ($clientSecret !== '') {
        allstat_set_setting($pdo, 'google.client_secret_enc', (string) allstat_encrypt_secret($clientSecret, $config));
    }

    return ['ok' => true, 'message' => t('Google OAuth klient je uložený.')];
}

/** Napojení Google služeb s vlastním Client ID a secretem, ze kterých jde klienta převzít (jedno na Client ID). */
function allstat_google_client_donors(PDO $pdo): array
{
    $rows = allstat_fetch_all($pdo, "SELECT ds.id, ds.client_id, s.name AS source_name, d.name AS domain_name
        FROM domain_sources ds
        INNER JOIN data_sources s ON s.id = ds.source_id
        INNER JOIN domains d ON d.id = ds.domain_id
        WHERE s.provider_key IN ('ga4','gsc','youtube','google_ads') AND ds.client_id IS NOT NULL AND ds.client_id <> ''
          AND ds.client_secret_enc IS NOT NULL AND ds.client_secret_enc <> ''
        ORDER BY ds.id");
    $out = [];
    foreach ($rows as $row) {
        $out[(string) $row['client_id']] ??= $row;
    }

    return array_values($out);
}

/** Převezme Client ID a (šifrovaný) secret z existujícího napojení. Klíč je stejný, secret se jen zkopíruje. */
function allstat_google_client_from_connection(PDO $pdo, int $connectionId): array
{
    foreach (allstat_google_client_donors($pdo) as $donor) {
        if ((int) $donor['id'] === $connectionId) {
            $row = allstat_fetch_one($pdo, 'SELECT client_id, client_secret_enc FROM domain_sources WHERE id = ?', [$connectionId]);
            allstat_set_setting($pdo, 'google.client_id', (string) $row['client_id']);
            allstat_set_setting($pdo, 'google.client_secret_enc', (string) $row['client_secret_enc']);

            return ['ok' => true, 'message' => t('Převzat Google OAuth klient z napojení „{name}“.', ['name' => $donor['source_name'] . ', ' . $donor['domain_name']])];
        }
    }

    return ['ok' => false, 'message' => t('Zvolené napojení nemá Client ID a secret, ze kterých by šlo klienta převzít.')];
}

/** Callback je stejná adresa jako u napojení se svým Client ID, takže už zapsaná v Google Cloud stačí. */
function allstat_google_redirect_uri(array $config): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . allstat_url($config, 'admin/oauth-callback.php');
}

function allstat_google_account_scopes(): string
{
    return 'openid email ' . ALLSTAT_GOOGLE_SCOPE_GA4 . ' ' . ALLSTAT_GOOGLE_SCOPE_GSC;
}

function allstat_google_auth_url(string $clientId, string $redirectUri, string $state, string $loginHint = ''): string
{
    $params = [
        'response_type' => 'code',
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'scope' => allstat_google_account_scopes(),
        'state' => $state,
        'access_type' => 'offline',
        'prompt' => 'consent select_account',
        'include_granted_scopes' => 'true',
    ];
    if ($loginHint !== '') {
        $params['login_hint'] = $loginHint;
    }

    return ALLSTAT_GOOGLE_AUTH_URL . '?' . http_build_query($params);
}

// ---------------------------------------------------------------------------------------------
// Účty
// ---------------------------------------------------------------------------------------------

/** Účty s počtem napojení, která je používají. */
function allstat_google_accounts(PDO $pdo): array
{
    return allstat_fetch_all($pdo, "SELECT a.id, a.label, a.account_email, a.status, a.note, a.scopes, a.discovered_at, a.created_at,
            (SELECT COUNT(*) FROM domain_sources ds WHERE ds.oauth_account_id = a.id) AS connections
        FROM allstat_oauth_accounts a
        WHERE a.provider = 'google'
        ORDER BY a.label, a.id");
}

function allstat_google_account(PDO $pdo, int $id): ?array
{
    return $id > 0 ? allstat_fetch_one($pdo, "SELECT * FROM allstat_oauth_accounts WHERE id = ? AND provider = 'google'", [$id]) : null;
}

function allstat_google_normalize_label(string $label): string
{
    return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $label)), 0, 120);
}

/**
 * Vymění autorizační kód za tokeny a zjistí e-mail účtu.
 *
 * @return array{ok:bool, message?:string, access_token?:string, refresh_token?:string, expires_at?:?string, scopes?:string, email?:string}
 */
function allstat_google_exchange_code(PDO $pdo, array $config, string $code, string $redirectUri): array
{
    $client = allstat_google_client($pdo, $config);
    if (!$client['configured']) {
        return ['ok' => false, 'message' => t('Chybí Google OAuth klient (Client ID a secret).')];
    }

    $resp = allstat_http_request('POST', ALLSTAT_GOOGLE_TOKEN_URL, ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'], http_build_query([
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $redirectUri,
        'client_id' => $client['client_id'],
        'client_secret' => $client['client_secret'],
    ]));
    $payload = $resp['json'];
    if (!is_array($payload) || empty($payload['access_token'])) {
        $detail = is_array($payload) ? (string) ($payload['error_description'] ?? $payload['error'] ?? '') : mb_substr((string) $resp['body'], 0, 160);
        return ['ok' => false, 'message' => t('Google nevydal token (HTTP {status}): {detail}', ['status' => (int) $resp['status'], 'detail' => $detail])];
    }

    $accessToken = (string) $payload['access_token'];
    // sub = stálé ID Google účtu (e-mail se může změnit), podle něj se pozná stejný účet při obnovení připojení.
    $email = '';
    $sub = '';
    $info = allstat_http_request('GET', ALLSTAT_GOOGLE_USERINFO_URL, ['Authorization' => 'Bearer ' . $accessToken, 'Accept' => 'application/json']);
    if ($info['status'] === 200 && is_array($info['json'])) {
        $email = allstat_normalize_email((string) ($info['json']['email'] ?? ''));
        $sub = preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) ($info['json']['sub'] ?? '')) === 1 ? (string) $info['json']['sub'] : '';
    }

    return [
        'ok' => true,
        'access_token' => $accessToken,
        'refresh_token' => (string) ($payload['refresh_token'] ?? ''),
        'expires_at' => allstat_google_expires_at($payload),
        'scopes' => trim((string) ($payload['scope'] ?? '')),
        'email' => mb_substr($email, 0, 190),
        'sub' => $sub,
    ];
}

function allstat_google_expires_at(array $payload): ?string
{
    return !empty($payload['expires_in'])
        ? (new DateTimeImmutable('now'))->modify('+' . (int) $payload['expires_in'] . ' seconds')->format('Y-m-d H:i:s')
        : null;
}

/** Stejný Google účet podle stálého ID (sub), u starších záznamů bez něj podle e-mailu. */
function allstat_google_find_account(PDO $pdo, string $sub, string $email): ?array
{
    if ($sub !== '' && ($row = allstat_fetch_one($pdo, "SELECT * FROM allstat_oauth_accounts WHERE provider = 'google' AND account_sub = ?", [$sub]))) {
        return $row;
    }

    return $email !== '' ? allstat_fetch_one($pdo, "SELECT * FROM allstat_oauth_accounts WHERE provider = 'google' AND account_email = ? ORDER BY id LIMIT 1", [$email]) : null;
}

/**
 * Uloží tokeny z přihlášení. $accountId > 0 = obnovení připojení existujícího účtu, a to jen stejným Google
 * účtem. Nový účet, který už v AllStatu je (stejné sub nebo e-mail), nezaloží duplikát, jen obnoví ten existující.
 *
 * @return array{ok:bool, message:string, account_id?:int, created?:bool}
 */
function allstat_google_save_account(PDO $pdo, array $config, int $accountId, string $label, array $tokens, ?int $actorId): array
{
    $email = (string) ($tokens['email'] ?? '');
    $sub = (string) ($tokens['sub'] ?? '');
    $existing = $accountId > 0 ? allstat_google_account($pdo, $accountId) : allstat_google_find_account($pdo, $sub, $email);

    if ($existing && $accountId > 0) {
        $knownSub = (string) ($existing['account_sub'] ?? '');
        $knownEmail = (string) ($existing['account_email'] ?? '');
        $verified = $knownSub !== '' ? $sub === $knownSub : ($knownEmail === '' || $email === $knownEmail);
        if (!$verified) {
            return ['ok' => false, 'message' => t('Přihlásili jste se jiným Google účtem{email} než účet „{label}“{known}, nebo se ho nepodařilo ověřit. Připojení se nezměnilo.', [
                'email' => $email !== '' ? ' (' . $email . ')' : '',
                'label' => $existing['label'],
                'known' => $knownEmail !== '' ? ' (' . $knownEmail . ')' : '',
            ])];
        }
    }

    $granted = ' ' . (string) ($tokens['scopes'] ?? '') . ' ';
    $missing = [];
    if (!str_contains($granted, ' ' . ALLSTAT_GOOGLE_SCOPE_GA4 . ' ')) {
        $missing[] = 'Google Analytics';
    }
    if (!str_contains($granted, ' ' . ALLSTAT_GOOGLE_SCOPE_GSC . ' ')) {
        $missing[] = 'Search Console';
    }
    $status = $missing === [] ? 'ok' : 'warning';
    $note = $missing === [] ? null : t('Při přihlášení nebyl povolen přístup: {services}.', ['services' => implode(', ', $missing)]);

    $access = allstat_encrypt_secret((string) $tokens['access_token'], $config);
    $refresh = ($tokens['refresh_token'] ?? '') !== '' ? allstat_encrypt_secret((string) $tokens['refresh_token'], $config) : null;

    $update = static function (array $account) use ($pdo, $email, $sub, $access, $refresh, $tokens, $status, $note, $actorId): array {
        $pdo->prepare('UPDATE allstat_oauth_accounts SET account_email = COALESCE(NULLIF(?, \'\'), account_email), account_sub = COALESCE(NULLIF(?, \'\'), account_sub),
                access_token_enc = ?, refresh_token_enc = COALESCE(?, refresh_token_enc), token_expires_at = ?, scopes = ?, status = ?, note = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$email, $sub, $access, $refresh, $tokens['expires_at'] ?? null, (string) ($tokens['scopes'] ?? ''), $status, $note, (int) $account['id']]);
        allstat_audit($pdo, $actorId, null, 'google_account_reconnected', (string) $account['id']);

        return ['ok' => true, 'account_id' => (int) $account['id'], 'created' => false,
            'message' => t('Google účet „{label}“ je znovu připojený.', ['label' => $account['label']]) . ($note ? ' ' . $note : '')];
    };

    if ($existing) {
        return $update($existing);
    }

    if ($refresh === null) {
        return ['ok' => false, 'message' => t('Google nevydal obnovovací token. Zkuste přidat účet znovu.')];
    }

    $label = allstat_google_normalize_label($label) ?: ($email !== '' ? $email : t('Google účet'));
    try {
        $pdo->prepare("INSERT INTO allstat_oauth_accounts (provider, label, account_email, account_sub, access_token_enc, refresh_token_enc, token_expires_at, scopes, status, note, created_by)
                VALUES ('google', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$label, $email !== '' ? $email : null, $sub !== '' ? $sub : null, $access, $refresh, $tokens['expires_at'] ?? null, (string) ($tokens['scopes'] ?? ''), $status, $note, $actorId]);
    } catch (PDOException $exception) {
        // Souběh dvou přihlášení stejným účtem: unikátní (provider, account_sub) pustí jen jedno, druhé obnoví ten účet.
        $twin = str_contains($exception->getMessage(), 'Duplicate') ? allstat_google_find_account($pdo, $sub, $email) : null;
        if (!$twin) {
            throw $exception;
        }

        return $update($twin);
    }
    $id = (int) $pdo->lastInsertId();
    allstat_audit($pdo, $actorId, null, 'google_account_added', (string) $id);

    return ['ok' => true, 'account_id' => $id, 'created' => true, 'message' => t('Google účet „{label}“ je připojený.', ['label' => $label]) . ($note ? ' ' . $note : '')];
}

function allstat_google_rename_account(PDO $pdo, int $id, string $label, ?int $actorId): array
{
    $label = allstat_google_normalize_label($label);
    if ($label === '' || !allstat_google_account($pdo, $id)) {
        return ['ok' => false, 'message' => t('Zadejte název účtu.')];
    }
    $pdo->prepare('UPDATE allstat_oauth_accounts SET label = ?, updated_at = NOW() WHERE id = ?')->execute([$label, $id]);
    allstat_audit($pdo, $actorId, null, 'google_account_renamed', (string) $id);

    return ['ok' => true, 'message' => t('Účet je přejmenovaný na „{label}“.', ['label' => $label])];
}

/**
 * Odebere účet a napojení, která ho používala, označí jako chybná. Přístup u Googlu zruší jen tehdy, když nic
 * jiného nesdílí stejný grant: revoke ruší povolení celého Google projektu pro daného uživatele, takže by zrušil
 * i tokeny napojení s vlastním Client ID stejného projektu a jiného účtu se stejným Google uživatelem.
 */
function allstat_google_delete_account(PDO $pdo, array $config, int $id, ?int $actorId): array
{
    $account = allstat_google_account($pdo, $id);
    if (!$account) {
        return ['ok' => false, 'message' => t('Účet neexistuje.')];
    }

    $clientId = allstat_google_client($pdo, $config)['client_id'];
    $sharedClient = $clientId !== '' && allstat_fetch_one($pdo, 'SELECT id FROM domain_sources WHERE client_id = ? AND refresh_token_enc IS NOT NULL LIMIT 1', [$clientId]);
    $sameUser = allstat_fetch_one($pdo, "SELECT id FROM allstat_oauth_accounts WHERE provider = 'google' AND id <> ?
        AND ((account_sub IS NOT NULL AND account_sub = ?) OR (account_email IS NOT NULL AND account_email = ?)) LIMIT 1",
        [$id, (string) ($account['account_sub'] ?? ''), (string) ($account['account_email'] ?? '')]);
    $token = allstat_decrypt_secret($account['refresh_token_enc'] ?? null, $config);
    $revoked = false;
    if ($token && !$sharedClient && !$sameUser) {
        $resp = allstat_http_request('POST', ALLSTAT_GOOGLE_REVOKE_URL, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['token' => $token]), 10);
        $revoked = $resp['status'] === 200;
    }

    $pdo->prepare("UPDATE domain_sources SET oauth_account_id = NULL, status = 'error', note = ?, updated_at = NOW() WHERE oauth_account_id = ?")
        ->execute([t('Google účet „{label}“ byl odebrán. Připojte napojení k jinému účtu.', ['label' => mb_substr((string) $account['label'], 0, 80)]), $id]);
    $pdo->prepare('DELETE FROM allstat_oauth_accounts WHERE id = ?')->execute([$id]);
    allstat_audit($pdo, $actorId, null, 'google_account_deleted', (string) $id . ($revoked ? ', přístup u Googlu zrušen' : ''));

    return ['ok' => true, 'message' => t('Google účet „{label}“ je odebraný.', ['label' => $account['label']]) . ($revoked ? ' ' . t('Přístup AllStatu k němu Google zrušil.')
        : ' ' . t('Přístup u Googlu zůstal, protože ho sdílí i jiná napojení. Zrušit ho jde v nastavení Google účtu (Zabezpečení, Aplikace třetích stran).'))];
}

/**
 * Platný access token účtu, v případě potřeby obnovený. Používá ho sync všech napojení s oauth_account_id.
 *
 * @return array{ok:bool, access_token?:string, message?:string}
 */
function allstat_google_account_token(PDO $pdo, array $config, int $accountId): array
{
    $account = allstat_google_account($pdo, $accountId);
    if (!$account) {
        return ['ok' => false, 'message' => t('Google účet tohoto napojení už neexistuje. Připojte napojení k jinému účtu.')];
    }

    $token = allstat_decrypt_secret($account['access_token_enc'] ?? null, $config);
    $expiresAt = $account['token_expires_at'] ?? null;
    if ($token && $expiresAt && strtotime((string) $expiresAt) - time() >= 60) {
        return ['ok' => true, 'access_token' => $token];
    }

    $reconnect = t('Google účet „{label}“ je potřeba připojit znovu (Google účty, Obnovit připojení).', ['label' => $account['label']]);
    $refresh = allstat_decrypt_secret($account['refresh_token_enc'] ?? null, $config);
    $client = allstat_google_client($pdo, $config);
    if (!$refresh || !$client['configured']) {
        return ['ok' => false, 'message' => $client['configured'] ? $reconnect : t('Chybí Google OAuth klient (Google účty, Client ID a secret).')];
    }

    $resp = allstat_http_request('POST', ALLSTAT_GOOGLE_TOKEN_URL, ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'], http_build_query([
        'grant_type' => 'refresh_token',
        'refresh_token' => $refresh,
        'client_id' => $client['client_id'],
        'client_secret' => $client['client_secret'],
    ]));
    $payload = $resp['json'];
    if (!is_array($payload) || empty($payload['access_token'])) {
        $error = is_array($payload) ? (string) ($payload['error'] ?? '') : '';
        // invalid_grant = token zrušený, propadlý (aplikace v režimu Testing ho ruší po 7 dnech) nebo změněné heslo.
        if ($error === 'invalid_grant') {
            $pdo->prepare("UPDATE allstat_oauth_accounts SET status = 'error', note = ?, updated_at = NOW() WHERE id = ?")
                ->execute([t('Google zrušil platnost připojení (invalid_grant). Připojte účet znovu.'), $accountId]);
            return ['ok' => false, 'message' => $reconnect];
        }
        $detail = is_array($payload) ? (string) ($payload['error_description'] ?? $error) : mb_substr((string) $resp['body'], 0, 160);
        return ['ok' => false, 'message' => t('Obnovení tokenu Google účtu selhalo (HTTP {status}): {detail}', ['status' => (int) $resp['status'], 'detail' => $detail])];
    }

    $newToken = (string) $payload['access_token'];
    $pdo->prepare("UPDATE allstat_oauth_accounts SET access_token_enc = ?, token_expires_at = ?,
            status = IF(status = 'error', 'ok', status), note = IF(status = 'error', NULL, note), updated_at = NOW() WHERE id = ?")
        ->execute([allstat_encrypt_secret($newToken, $config), allstat_google_expires_at($payload), $accountId]);

    return ['ok' => true, 'access_token' => $newToken];
}

// ---------------------------------------------------------------------------------------------
// Seznam property a webů (discovery)
// ---------------------------------------------------------------------------------------------

/** Srozumitelná chyba Google API pro stránku importu. */
function allstat_google_api_error(array $resp, string $api, string $apiLibraryName): string
{
    $err = is_array($resp['json'] ?? null) ? ($resp['json']['error'] ?? null) : null;
    $msg = is_array($err) ? (string) ($err['message'] ?? '') : mb_substr((string) ($resp['body'] ?? ''), 0, 200);
    $reason = '';
    if (is_array($err)) {
        foreach ((array) ($err['details'] ?? []) as $detail) {
            $reason .= ' ' . (string) ($detail['reason'] ?? '');
        }
        $reason .= ' ' . (string) ($err['status'] ?? '');
    }
    $hay = $reason . ' ' . $msg;
    $status = (int) ($resp['status'] ?? 0);

    if (stripos($hay, 'SERVICE_DISABLED') !== false || stripos($hay, 'has not been used in project') !== false || stripos($hay, 'is disabled') !== false) {
        return t('{api}: v Google Cloud projektu OAuth klienta není zapnuté „{library}“. Zapněte ho v Google Cloud Console (APIs & Services, Library) a seznam načtěte znovu.', ['api' => $api, 'library' => $apiLibraryName]);
    }
    if ($status === 401) {
        return t('{api}: přihlášení vypršelo. Připojte účet znovu.', ['api' => $api]);
    }
    if ($status === 403) {
        return t('{api}: účet k tomu nemá oprávnění nebo při přihlášení nepovolil přístup. Detail: {detail}', ['api' => $api, 'detail' => mb_substr($msg, 0, 160)]);
    }
    if ($status === 0) {
        return t('{api}: server se nespojil s Googlem (odchozí spojení blokované nebo časový limit).', ['api' => $api]);
    }

    return $api . ': HTTP ' . $status . ' ' . mb_substr($msg, 0, 160);
}

/** Doména z adresy webu nebo z property Search Console (sc-domain:example.cz, https://www.example.cz/). */
function allstat_google_host(string $value): string
{
    $value = trim($value);
    if (str_starts_with(strtolower($value), 'sc-domain:')) {
        $value = substr($value, 10);
    }

    return allstat_normalize_host($value);
}

/**
 * Načte z Googlu GA4 property (s adresou webu z datového streamu) a weby ze Search Console a uloží je k účtu.
 *
 * @return array{ok:bool, message?:string, discovery?:array}
 */
function allstat_google_discover(PDO $pdo, array $config, int $accountId): array
{
    $token = allstat_google_account_token($pdo, $config, $accountId);
    if (!$token['ok']) {
        return ['ok' => false, 'message' => (string) $token['message']];
    }
    // Časový rozpočet podle limitu hostingu (set_time_limit tam často nejde zvýšit): datové streamy se čtou
    // jen do něj, zbytek property zůstane bez adresy a další „Načíst znovu“ naváže (známé adresy se nečtou znovu).
    $startedAt = microtime(true);
    @set_time_limit(300);
    $maxExecution = (int) ini_get('max_execution_time');
    $budget = $maxExecution > 0 ? max(10, min(120, $maxExecution - 15)) : 120;
    $previous = [];
    foreach ((array) (allstat_google_cached_discovery(allstat_google_account($pdo, $accountId) ?? [])['ga4'] ?? []) as $known) {
        if (($known['host'] ?? '') !== '') {
            $previous[(string) $known['property']] = ['uri' => (string) $known['uri'], 'host' => (string) $known['host']];
        }
    }
    $headers = ['Authorization' => 'Bearer ' . $token['access_token'], 'Accept' => 'application/json'];
    $ga4 = [];
    $gsc = [];
    $errors = [];

    $pageToken = '';
    for ($page = 0; $page < 20; $page++) {
        $resp = allstat_http_request('GET', ALLSTAT_GOOGLE_ADMIN_API . '/accountSummaries?' . http_build_query(['pageSize' => 200] + ($pageToken !== '' ? ['pageToken' => $pageToken] : [])), $headers, null, 30);
        if ($resp['status'] !== 200 || !is_array($resp['json'])) {
            $errors[] = allstat_google_api_error($resp, 'Google Analytics', 'Google Analytics Admin API');
            break;
        }
        foreach ((array) ($resp['json']['accountSummaries'] ?? []) as $summary) {
            foreach ((array) ($summary['propertySummaries'] ?? []) as $prop) {
                $propertyId = (string) ($prop['property'] ?? '');
                if (preg_match('#^properties/\d+$#', $propertyId) !== 1) {
                    continue;
                }
                $ga4[$propertyId] = [
                    'property' => $propertyId,
                    'name' => mb_substr((string) ($prop['displayName'] ?? $propertyId), 0, 160),
                    'account' => (string) ($summary['account'] ?? ''),
                    'account_name' => mb_substr((string) ($summary['displayName'] ?? ''), 0, 160),
                    'uri' => $previous[$propertyId]['uri'] ?? '',
                    'host' => $previous[$propertyId]['host'] ?? '',
                ];
            }
        }
        $pageToken = (string) ($resp['json']['nextPageToken'] ?? '');
        if ($pageToken === '') {
            break;
        }
    }

    $resp = allstat_http_request('GET', ALLSTAT_GOOGLE_GSC_SITES_URL, $headers, null, 30);
    if ($resp['status'] === 200 && is_array($resp['json'])) {
        $tooLong = [];
        foreach ((array) ($resp['json']['siteEntry'] ?? []) as $site) {
            $siteUrl = (string) ($site['siteUrl'] ?? '');
            $permission = (string) ($site['permissionLevel'] ?? '');
            if ($siteUrl === '' || $permission === 'siteUnverifiedUser' || allstat_google_host($siteUrl) === '') {
                continue;
            }
            // property_id napojení má nejvýš 120 znaků; zkrácená adresa by Search Console nenašla.
            if (strlen($siteUrl) > 120) {
                $tooLong[] = mb_substr($siteUrl, 0, 60) . '…';
                continue;
            }
            $gsc[$siteUrl] = ['site' => $siteUrl, 'permission' => $permission, 'host' => allstat_google_host($siteUrl)];
        }
        if ($tooLong) {
            $errors[] = t('Search Console: {count} webů má příliš dlouhou adresu property a AllStat je nenabídne ({list}).', ['count' => count($tooLong), 'list' => implode(', ', array_slice($tooLong, 0, 3))]);
        }
    } else {
        $errors[] = allstat_google_api_error($resp, 'Search Console', 'Google Search Console API');
    }

    // Adresa webu z prvního webového datového streamu (podle ní se páruje se Search Console a zakládá web).
    $missing = 0;
    foreach ($ga4 as $propertyId => $prop) {
        if ($prop['host'] !== '') {
            continue;
        }
        if (microtime(true) - $startedAt > $budget) {
            $missing++;
            continue;
        }
        $resp = allstat_http_request('GET', ALLSTAT_GOOGLE_ADMIN_API . '/' . $propertyId . '/dataStreams?pageSize=50', $headers, null, 20);
        if ($resp['status'] !== 200 || !is_array($resp['json'])) {
            continue;
        }
        foreach ((array) ($resp['json']['dataStreams'] ?? []) as $stream) {
            $uri = (string) ($stream['webStreamData']['defaultUri'] ?? '');
            if (($stream['type'] ?? '') === 'WEB_DATA_STREAM' && $uri !== '' && allstat_google_host($uri) !== '') {
                $ga4[$propertyId]['uri'] = mb_substr($uri, 0, 255);
                $ga4[$propertyId]['host'] = allstat_google_host($uri);
                break;
            }
        }
    }
    if ($missing > 0) {
        $errors[] = t('Google Analytics: u {count} property se adresu webu nestihlo načíst v časovém limitu serveru. Klikněte na „Načíst znovu“, načtení naváže.', ['count' => $missing]);
    }

    $discovery = ['ga4' => array_values($ga4), 'gsc' => array_values($gsc), 'errors' => $errors];
    $pdo->prepare('UPDATE allstat_oauth_accounts SET discovery_json = ?, discovered_at = NOW() WHERE id = ?')
        ->execute([json_encode($discovery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $accountId]);

    return ['ok' => true, 'discovery' => $discovery];
}

/** Naposledy načtený seznam účtu (null = ještě nenačten). */
function allstat_google_cached_discovery(array $account): ?array
{
    $data = json_decode((string) ($account['discovery_json'] ?? ''), true);

    return is_array($data) ? $data + ['ga4' => [], 'gsc' => [], 'errors' => []] : null;
}

function allstat_google_gsc_key(string $siteUrl): string
{
    return substr(hash('sha256', $siteUrl), 0, 16);
}

/**
 * Řádky pro výběr importu: GA4 property spárované s weby Search Console podle domény a k tomu weby ze Search
 * Console, které žádnou GA4 property nemají. U každého řádku stav v AllStatu (web a napojení už existují?).
 */
function allstat_google_import_rows(PDO $pdo, array $discovery): array
{
    $domainsByHost = [];
    foreach (allstat_fetch_all($pdo, 'SELECT id, name, url FROM domains ORDER BY id') as $domain) {
        $domainsByHost[allstat_normalize_host((string) $domain['url'])] ??= $domain;
    }
    // Už napojené property a web, u kterého jsou: import je přepne na účet, nezakládá je podruhé u jiného webu.
    $connected = ['ga4' => [], 'gsc' => []];
    foreach (allstat_fetch_all($pdo, "SELECT ds.property_id, s.provider_key, d.id, d.name, d.url FROM domain_sources ds
            INNER JOIN data_sources s ON s.id = ds.source_id INNER JOIN domains d ON d.id = ds.domain_id
            WHERE s.provider_key IN ('ga4','gsc') ORDER BY ds.id") as $row) {
        $connected[(string) $row['provider_key']][(string) $row['property_id']] ??= ['id' => $row['id'], 'name' => $row['name'], 'url' => $row['url']];
    }

    $sitesByHost = [];
    foreach ($discovery['gsc'] ?? [] as $site) {
        $sitesByHost[(string) $site['host']][] = $site;
    }
    // Doménová property (sc-domain:) pokrývá celý web, proto má přednost před URL property.
    foreach ($sitesByHost as &$sites) {
        usort($sites, static fn (array $a, array $b): int => [!str_starts_with($a['site'], 'sc-domain:'), $a['site']] <=> [!str_starts_with($b['site'], 'sc-domain:'), $b['site']]);
    }
    unset($sites);

    $rows = [];
    $usedSites = [];
    foreach ($discovery['ga4'] ?? [] as $prop) {
        $host = (string) $prop['host'];
        $options = $host !== '' ? ($sitesByHost[$host] ?? []) : [];
        // Webu stačí jedna property Search Console: když už je některá připojená, další se nenabízí předvybraná.
        $default = '';
        $anyConnected = false;
        foreach ($options as $site) {
            $usedSites[$site['site']] = true;
            $anyConnected = $anyConnected || !empty($connected['gsc'][$site['site']]);
        }
        if (!$anyConnected && $options) {
            $default = $options[0]['site'];
        }
        $ga4Domain = $connected['ga4'][$prop['property']] ?? null;
        $rows[] = [
            'key' => 'p' . substr((string) $prop['property'], 11),
            'host' => $host,
            'ga4' => $prop,
            'gsc_options' => $options,
            'gsc_default' => $default,
            // Napojená GA4 property určuje web; jinak web se stejnou doménou.
            'domain' => $ga4Domain ?? ($host !== '' ? ($domainsByHost[$host] ?? null) : null),
            'ga4_connected' => $ga4Domain !== null,
            'gsc_connected' => array_map(static fn (array $s): bool => isset($connected['gsc'][$s['site']]), $options),
        ];
    }
    foreach ($discovery['gsc'] ?? [] as $site) {
        if (!empty($usedSites[$site['site']])) {
            continue;
        }
        $gscDomain = $connected['gsc'][$site['site']] ?? null;
        $rows[] = [
            'key' => 's' . allstat_google_gsc_key((string) $site['site']),
            'host' => (string) $site['host'],
            'ga4' => null,
            'gsc_options' => [$site],
            'gsc_default' => $gscDomain === null ? $site['site'] : '',
            'domain' => $gscDomain ?? ($domainsByHost[(string) $site['host']] ?? null),
            'ga4_connected' => false,
            'gsc_connected' => [$gscDomain !== null],
        ];
    }

    usort($rows, static fn (array $a, array $b): int => [$a['host'] === '', $a['host'], $a['key']] <=> [$b['host'] === '', $b['host'], $b['key']]);

    return $rows;
}

/**
 * Založí vybrané weby a napojení. Výběr se bere jen z uloženého seznamu účtu, takže nejde naimportovat property,
 * ke které účet nemá přístup. Existující napojení na stejnou property se přepne na tento účet.
 *
 * @param list<string> $selectedKeys klíče řádků z allstat_google_import_rows
 * @param array<string,string> $gscChoice klíč řádku => allstat_google_gsc_key vybraného webu Search Console ('' = nepřipojovat)
 * @return array{ok:bool, message:string, connection_ids:list<int>, skipped:list<string>}
 */
function allstat_google_import(PDO $pdo, array $config, array $account, array $selectedKeys, array $gscChoice, ?int $actorId): array
{
    $discovery = allstat_google_cached_discovery($account);
    if ($discovery === null) {
        return ['ok' => false, 'message' => t('Seznam property ještě není načtený.'), 'connection_ids' => [], 'skipped' => []];
    }

    $rows = [];
    foreach (allstat_google_import_rows($pdo, $discovery) as $row) {
        $rows[$row['key']] = $row;
    }
    $sources = [];
    foreach (allstat_fetch_all($pdo, "SELECT id, provider_key, auth_url, token_url, api_base_url FROM data_sources WHERE provider_key IN ('ga4','gsc')") as $source) {
        $sources[(string) $source['provider_key']] = $source;
    }
    if (count($sources) < 2) {
        return ['ok' => false, 'message' => t('V katalogu zdrojů chybí GA4 nebo Search Console.'), 'connection_ids' => [], 'skipped' => []];
    }

    $upsert = $pdo->prepare("INSERT INTO domain_sources
            (domain_id, source_id, oauth_account_id, account_label, property_id, scopes, auth_url, token_url, api_base_url, status, note, is_enabled, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'warning', ?, 1, NOW(), NOW())
        ON DUPLICATE KEY UPDATE oauth_account_id = VALUES(oauth_account_id), is_enabled = 1, updated_at = NOW()");
    $note = mb_substr(t('Importováno z Google účtu „{label}“, čeká na stažení dat.', ['label' => (string) $account['label']]), 0, 220);
    $connect = function (int $domainId, string $provider, string $propertyId, string $label) use ($pdo, $upsert, $sources, $account, $note): int {
        $source = $sources[$provider];
        // Property už napojená (u kteréhokoli webu): jen ji přepnout na účet, druhé napojení by stahovalo stejná data.
        $existing = allstat_fetch_all($pdo, 'SELECT id FROM domain_sources WHERE source_id = ? AND property_id = ? ORDER BY id', [(int) $source['id'], $propertyId]);
        if ($existing) {
            $ids = array_map(static fn (array $r): int => (int) $r['id'], $existing);
            $pdo->prepare('UPDATE domain_sources SET oauth_account_id = ?, is_enabled = 1, updated_at = NOW() WHERE id IN (' . implode(',', $ids) . ')')
                ->execute([(int) $account['id']]);

            return $ids[0];
        }
        $upsert->execute([$domainId, (int) $source['id'], (int) $account['id'], mb_substr($label, 0, 160), mb_substr($propertyId, 0, 120),
            $provider === 'ga4' ? ALLSTAT_GOOGLE_SCOPE_GA4 : ALLSTAT_GOOGLE_SCOPE_GSC,
            (string) $source['auth_url'], (string) $source['token_url'], (string) $source['api_base_url'], $note]);
        $row = allstat_fetch_one($pdo, 'SELECT id FROM domain_sources WHERE domain_id = ? AND source_id = ? AND property_id = ?', [$domainId, (int) $source['id'], $propertyId]);

        return (int) ($row['id'] ?? 0);
    };

    $connectionIds = [];
    $skipped = [];
    $newDomains = 0;
    foreach (array_unique(array_map('strval', $selectedKeys)) as $key) {
        $row = $rows[$key] ?? null;
        if (!$row) {
            continue;
        }
        $site = null;
        $choice = (string) ($gscChoice[$key] ?? '');
        foreach ($row['gsc_options'] as $option) {
            if ($choice !== '' && hash_equals(allstat_google_gsc_key((string) $option['site']), $choice)) {
                $site = $option;
            }
        }
        $host = $row['host'];
        $domain = $row['domain'];
        if ($host === '' && !$domain) {
            $skipped[] = t('{name}: GA4 property nemá webový datový stream s adresou, web přidejte ručně.', ['name' => $row['ga4']['name'] ?? $key]);
            continue;
        }

        if (!$domain) {
            $saved = allstat_save_domain($pdo, $actorId, ['name' => $host, 'url' => $host, 'is_active' => 1]);
            $domain = allstat_fetch_one($pdo, 'SELECT id, name, url FROM domains WHERE url = ?', [$host]);
            if (!$domain) {
                $skipped[] = $host . ': ' . $saved['message'];
                continue;
            }
            $newDomains++;
        }

        if ($row['ga4']) {
            $connectionIds[] = $connect((int) $domain['id'], 'ga4', (string) $row['ga4']['property'], (string) $row['ga4']['name']);
        }
        if ($site) {
            $connectionIds[] = $connect((int) $domain['id'], 'gsc', (string) $site['site'], (string) $site['site']);
        }
    }

    $connectionIds = array_values(array_filter(array_unique($connectionIds)));
    allstat_audit($pdo, $actorId, null, 'google_import', 'account ' . (int) $account['id'] . ': ' . count($connectionIds) . ' napojení, ' . $newDomains . ' nových webů');

    return [
        'ok' => true,
        'message' => t('Připojeno {count} napojení{domains}.', ['count' => count($connectionIds), 'domains' => $newDomains ? t(', nových webů {count}', ['count' => $newDomains]) : '']),
        'connection_ids' => $connectionIds,
        'skipped' => $skipped,
    ];
}
