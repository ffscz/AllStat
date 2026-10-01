<?php

/**
 * Autorizační endpoint OAuth 2.1 pro AllStat MCP ({A}/oauth/authorize.php): přihlášení + stránka souhlasu.
 *
 * GET  = ověření požadavku aplikace (Claude, ChatGPT, Claude Code ...), přihlášení přes session AllStatu (vč. 2FA,
 *        návrat na tuto adresu přes next=) a stránka souhlasu. Souhlas smí udělit jen aktivní administrátor. Když
 *        poslední přihlášení heslem v session (nebo potvrzení hesla na této stránce) je starší než 10 minut, stránka žádá
 *        i heslo k AllStatu.
 * POST = rozhodnutí (Povolit / Zamítnout): CSRF, podepsané pole `req` (10 minut, vázané na uživatele a jednorázové
 *        v rámci session), případně ověření hesla (pokus se rezervuje před ověřením: 5 za 15 minut na uživatele a
 *        adresu, 10 za hodinu na uživatele ze všech adres), znovu ověření klienta a návratové adresy, pak 303 zpět do
 *        aplikace (code + state + iss).
 *
 * Na návratovou adresu se nikdy nepřesměrovává, dokud není klient rozpoznán a adresa ověřena; předtím se zobrazí
 * jen česká chybová stránka. Neznámé a opakované parametry (např. ba_param od ChatGPT) i `prompt` se ignorují.
 * Neošetřená chyba skončí českou stránkou (HTTP 500) bez podrobností; podrobnosti jdou jen do error_log.
 */

ini_set('display_errors', '0');

/** Česká stránka při neočekávané chybě: bez podrobností, s HTTP 500 (rozpracovaný výstup se zahodí). */
function allstat_mcp_authz_crash_page(): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }

    $message = 'Při zpracování žádosti došlo k chybě. Zkuste připojení zopakovat, případně kontaktujte správce AllStatu.';

    try {
        if (isset($GLOBALS['config']) && is_array($GLOBALS['config']) && function_exists('allstat_url') && function_exists('allstat_nonce') && function_exists('h')) {
            allstat_mcp_authz_error($GLOBALS['config'], 500, 'Něco se nepovedlo', $message);
        }
    } catch (Throwable) {
        // sem se dostane jen zpráva bez stylů níže
    }

    echo '<!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>Něco se nepovedlo | AllStat</title></head>'
        . '<body><h1>Něco se nepovedlo</h1><p>' . $message . '</p></body></html>';
}

set_exception_handler(static function (Throwable $exception): void {
    error_log('allstat oauth/authorize: ' . get_class($exception) . ': ' . $exception->getMessage() . ' @ ' . basename($exception->getFile()) . ':' . $exception->getLine());
    allstat_mcp_authz_crash_page();
});

register_shutdown_function(static function (): void {
    $error = error_get_last();

    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('allstat oauth/authorize fatal: ' . $error['message'] . ' @ ' . basename($error['file']) . ':' . $error['line']);
        allstat_mcp_authz_crash_page();
    }
});

$config = require __DIR__ . '/../config.php';
date_default_timezone_set($config['app']['timezone']);

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/database.php';
require_once __DIR__ . '/../lib/migrations.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/mcp-oauth.php';

allstat_session_start($config); // session + výchozí CSP a bezpečnostní hlavičky
header('Cache-Control: no-store');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow');

/** Samostatná stránka ve stylu přihlášení (karta uprostřed), tělo je už escapované HTML. */
function allstat_mcp_authz_page(array $config, int $status, string $title, string $bodyHtml): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    $nonce = allstat_nonce();
    ?><!doctype html>
<html lang="cs" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title><?= h($title) ?> | AllStat</title>
    <script nonce="<?= h($nonce) ?>">document.documentElement.dataset.theme = localStorage.getItem('allstat-theme') || 'light';</script>
    <link rel="stylesheet" href="<?= h(allstat_url($config, 'assets/css/app.css')) ?>">
    <link rel="stylesheet" href="<?= h(allstat_url($config, 'assets/css/admin.css')) ?>">
</head>
<body>
    <main class="auth-shell">
        <section class="auth-card">
            <div class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></div>
            <?= $bodyHtml ?>
        </section>
    </main>
</body>
</html>
<?php
    exit;
}

/** Česká chybová stránka (bez jakéhokoli přesměrování). */
function allstat_mcp_authz_error(array $config, int $status, string $heading, string $message): never
{
    allstat_mcp_authz_page(
        $config,
        $status,
        $heading,
        '<h1>' . h($heading) . '</h1><div class="notice notice-error">' . h($message) . '</div>'
        . '<p class="form-help">Vraťte se do aplikace a připojení zkuste zopakovat.</p>'
    );
}

/** Přesměrování zpět do aplikace; adresa je složená z ověřené redirect_uri a zakódovaných parametrů. */
function allstat_mcp_authz_redirect(string $url, int $status): never
{
    header('Location: ' . $url, true, $status);
    exit;
}

/**
 * Chrome uplatňuje form-action i na přesměrování po odeslání formuláře, proto se na této stránce (a jen na ní) do
 * CSP přidá původ ověřené návratové adresy. Ostatní direktivy zůstávají tak, jak je poslala session. Původ se skládá
 * jen z přísně ověřeného hosta (allstat_mcp_oauth_origin_of); při jakékoli pochybnosti vrací false a stránka se nezobrazí.
 */
function allstat_mcp_authz_allow_form_target(string $redirectUri): bool
{
    $origin = allstat_mcp_oauth_origin_of($redirectUri);
    if ($origin === '' || headers_sent()) {
        return false;
    }

    $csp = '';
    foreach (headers_list() as $line) {
        if (stripos($line, 'Content-Security-Policy:') === 0) {
            $csp = trim(substr($line, strlen('Content-Security-Policy:')));
        }
    }

    if ($csp === '') {
        $nonce = allstat_nonce();
        $csp = "default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; "
            . "connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";
    }

    $csp = (string) preg_replace('~form-action\s+[^;]*~i', "form-action 'self' " . $origin, $csp, 1, $replaced);
    if ($replaced === 0) {
        $csp .= "; form-action 'self' " . $origin;
    }

    header('Content-Security-Policy: ' . $csp);

    return true;
}

/**
 * Čas posledního ověření heslem v této session: přihlášení heslem (user_authed_at, nastavuje se jen v
 * allstat_attempt_login() hned po ověření hesla) nebo potvrzení hesla na této stránce. Záměrně se NEPOČÍTÁ
 * totp_action_verified_at: nastavuje ho i zapnutí 2FA (admin/setup-2fa.php), které heslo nevyžaduje, takže by
 * ukradená session mohla souhlas potvrdit bez znalosti hesla.
 */
function allstat_mcp_authz_last_auth(): int
{
    return min(time(), max((int) ($_SESSION['user_authed_at'] ?? 0), (int) ($_SESSION['mcp_pw_confirmed_at'] ?? 0)));
}

/** Souhlas vyžaduje potvrzení heslem, když poslední ověření heslem je starší než 10 minut. */
function allstat_mcp_authz_needs_password(): bool
{
    return time() - allstat_mcp_authz_last_auth() > 600;
}

/**
 * Ověří heslo zadané na stránce souhlasu (pravidla a limity viz allstat_mcp_oauth_consent_confirm_password()). Vrací
 * null při shodě, jinak [zpráva, HTTP stav]; při shodě si session zapamatuje čas potvrzení. Nikdy se nepřesměrovává
 * do aplikace.
 *
 * @return array{0: string, 1: int}|null
 */
function allstat_mcp_authz_confirm_password(PDO $pdo, array $user, mixed $password, array $request): ?array
{
    $failure = allstat_mcp_oauth_consent_confirm_password($pdo, $user, $password, allstat_mcp_oauth_uri_host_only($request['redirect_uri']));

    if ($failure === null) {
        $_SESSION['mcp_pw_confirmed_at'] = time();
    }

    return $failure;
}

/** Stránka souhlasu (administrátor) nebo upozornění pro běžného uživatele. */
function allstat_mcp_authz_consent_page(array $config, array $client, array $request, array $user, string $token, bool $isAdmin, bool $needsPassword = false, ?string $error = null, int $status = 200): never
{
    $redirectLabel = allstat_mcp_oauth_uri_host_label($request['redirect_uri']);
    $appName = $client['client_name'] !== '' ? $client['client_name'] : $redirectLabel;
    $identityHost = $client['type'] === 'cimd' ? allstat_mcp_oauth_uri_host_label($client['client_id']) : '';

    ob_start();

    if ($isAdmin) {
        ?>
            <h1>Povolit přístup k AllStatu?</h1>
            <?php if ($error !== null): ?>
                <div class="notice notice-error"><?= h($error) ?></div>
            <?php endif; ?>
            <p>Aplikace <strong><?= h($appName) ?></strong> chce číst data z AllStatu.</p>
            <p>Po povolení se vrátíte na: <strong><?= h($redirectLabel) ?></strong></p>
            <?php if ($identityHost !== ''): ?>
                <p>Identita aplikace ověřena z: <strong><?= h($identityHost) ?></strong></p>
            <?php else: ?>
                <div class="notice notice-warn">Název aplikace si zvolila sama aplikace a nelze ho ověřit. Rozhodující je adresa, na kterou se po povolení vrátíte.</div>
            <?php endif; ?>
            <p>Bude moci číst metriky, reporty a přehledy všech webů v AllStatu. Nemůže nic měnit ani mazat.</p>
            <p><strong>Povolte jen tehdy, když jste připojení právě sami spustili v Claude, ChatGPT nebo jiné aplikaci.</strong></p>
            <p class="form-help">Přihlášen(a) jako <?= h($user['email']) ?></p>
            <form method="post" class="form-stack">
                <?= allstat_csrf_field() ?>
                <input type="hidden" name="req" value="<?= h($token) ?>">
                <?php if ($needsPassword): ?>
                    <label><span>Pro potvrzení zadejte své heslo k AllStatu</span><input type="password" name="password" autocomplete="current-password" required></label>
                <?php endif; ?>
                <div class="form-actions">
                    <button class="button-primary" type="submit" name="decision" value="allow">Povolit</button>
                    <button class="button-secondary" type="submit" name="decision" value="deny" formnovalidate>Zamítnout</button>
                </div>
            </form>
            <p class="form-help">Přístup můžete kdykoli zrušit v Administrace → AI konektory.</p>
        <?php
    } else {
        ?>
            <h1>Povolit přístup k AllStatu?</h1>
            <div class="notice notice-error">Připojení AI konektoru může povolit jen administrátor AllStatu.</div>
            <p class="form-help">Přihlášen(a) jako <?= h($user['email']) ?></p>
            <form method="post" class="form-stack">
                <?= allstat_csrf_field() ?>
                <input type="hidden" name="req" value="<?= h($token) ?>">
                <div class="form-actions">
                    <button class="button-secondary" type="submit" name="decision" value="deny">Zpět do aplikace</button>
                </div>
            </form>
        <?php
    }

    allstat_mcp_authz_page($config, $status, 'Povolit přístup k AllStatu', (string) ob_get_clean());
}

// ---------------------------------------------------------------------------------------------------------------

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    header('Allow: GET, HEAD, POST');
    allstat_mcp_authz_error($config, 405, 'Metoda není povolená', 'Tuto stránku lze otevřít jen z aplikace, která se chce připojit.');
}

$pdo = allstat_db($config);
if (!$pdo || !allstat_tables_ready($pdo)) {
    allstat_mcp_authz_error($config, 503, 'AllStat není připravený', 'Databáze AllStatu teď není dostupná. Zkuste to za chvíli.');
}

allstat_migrate($pdo);

if (!allstat_mcp_is_enabled($pdo)) {
    allstat_mcp_authz_error($config, 404, 'Připojení AI je vypnuté', 'Připojení AI konektorů je v AllStatu vypnuté. Požádejte administrátora, aby ho zapnul v Administrace → AI konektory.');
}

// Limit hned na začátku: chrání i stahování popisu klienta (CIMD) před zneužitím.
if (!allstat_mcp_rate_hit($pdo, 'authorize:' . allstat_mcp_client_ip(), 30, 60)) {
    allstat_mcp_authz_error($config, 429, 'Příliš mnoho požadavků', 'Z této adresy přišlo příliš mnoho požadavků na připojení. Zkuste to za minutu.');
}

// ---------------------------------------------------------------------------------------------------------------
// POST: rozhodnutí na stránce souhlasu
// ---------------------------------------------------------------------------------------------------------------

if ($method === 'POST') {
    allstat_csrf_check();
    $user = allstat_require_user($pdo, $config);

    $token = is_string($_POST['req'] ?? null) ? (string) $_POST['req'] : '';
    $payload = allstat_mcp_oauth_consent_token_verify($config, $token);

    if ($payload === null || $payload['uid'] !== (int) $user['id']) {
        allstat_mcp_authz_error($config, 400, 'Požadavek vypršel', 'Žádost o připojení vypršela nebo není platná. Vraťte se do aplikace a připojení zopakujte.');
    }

    $fingerprint = hash('sha256', $token);
    $done = is_array($_SESSION['mcp_consent_done'] ?? null) ? $_SESSION['mcp_consent_done'] : [];
    if (isset($done[$fingerprint])) {
        allstat_mcp_authz_error($config, 400, 'Požadavek už byl vyřízen', 'Tato žádost o připojení už byla vyřízena. Vraťte se do aplikace, případně připojení zopakujte.');
    }

    // Znovu ověřit klienta, návratovou adresu a PKCE stejnou cestou jako u GET.
    $request = $payload['request'];
    $check = allstat_mcp_oauth_authorize_validate($pdo, [
        'client_id' => $request['client_id'],
        'redirect_uri' => $request['redirect_uri'],
        'response_type' => 'code',
        'code_challenge' => $request['code_challenge'],
        'code_challenge_method' => 'S256',
        'state' => $request['state'],
        'scope' => $request['scope'],
        'resource' => $request['resource'],
    ]);

    if (!$check['ok']) {
        if ($check['type'] === 'page') {
            allstat_mcp_authz_error($config, (int) $check['status'], 'Nelze pokračovat', (string) $check['message']);
        }

        allstat_mcp_authz_redirect(allstat_mcp_oauth_redirect_url($pdo, $check['redirect_uri'], [
            'error' => $check['error'],
            'error_description' => $check['description'],
            'state' => $check['state'],
        ]), 303);
    }

    $client = $check['client'];
    $request = $check['request'];

    $name = mb_substr($client['client_name'] !== '' ? $client['client_name'] : allstat_mcp_oauth_uri_host_label($request['redirect_uri']), 0, 120);
    $host = allstat_mcp_oauth_uri_host_only($request['redirect_uri']);
    $isAdmin = allstat_mcp_oauth_active_admin($pdo, (int) $user['id']) !== null;
    $allow = ($_POST['decision'] ?? '') === 'allow' && $isAdmin;

    // Krok navíc: po delší době od přihlášení se před vydáním kódu ověří heslo. Chyba znamená znovu stránku souhlasu
    // (nikdy přesměrování do aplikace) a požadavek se za vyřízený nepovažuje.
    if ($allow && allstat_mcp_authz_needs_password()) {
        // Hodnota, která není řetězec (např. password[]=x), se bere jako nezadané heslo (bez varování PHP).
        $failure = allstat_mcp_authz_confirm_password($pdo, $user, is_string($_POST['password'] ?? null) ? $_POST['password'] : '', $request);

        if ($failure !== null) {
            if (!allstat_mcp_authz_allow_form_target($request['redirect_uri'])) {
                allstat_mcp_authz_error($config, 400, 'Nelze pokračovat', 'Návratová adresa aplikace není povolená.');
            }

            allstat_mcp_authz_consent_page($config, $client, $request, $user, allstat_mcp_oauth_consent_token($config, $request, (int) $user['id']), true, true, $failure[0], $failure[1]);
        }
    }

    $done[$fingerprint] = time();
    $_SESSION['mcp_consent_done'] = array_slice($done, -20, null, true);

    if ($allow) {
        allstat_mcp_authz_redirect(allstat_mcp_oauth_consent_allow($pdo, $user, $client, $request), 303);
    }

    allstat_audit($pdo, (int) $user['id'], null, 'mcp_consent_denied', 'client=' . $name . ' host=' . $host);
    allstat_mcp_authz_redirect(allstat_mcp_oauth_consent_deny_url($pdo, $request), 303);
}

// ---------------------------------------------------------------------------------------------------------------
// GET: ověření požadavku, přihlášení, stránka souhlasu
// ---------------------------------------------------------------------------------------------------------------

$check = allstat_mcp_oauth_authorize_validate($pdo, $_GET);

if (!$check['ok']) {
    if ($check['type'] === 'page') {
        allstat_mcp_authz_error($config, (int) $check['status'], 'Nelze pokračovat', (string) $check['message']);
    }

    allstat_mcp_authz_redirect(allstat_mcp_oauth_redirect_url($pdo, $check['redirect_uri'], [
        'error' => $check['error'],
        'error_description' => $check['description'],
        'state' => $check['state'],
    ]), 302);
}

$client = $check['client'];
$request = $check['request'];

// Formulář odesílá zpět na tuto stránku a prohlížeč pak následuje přesměrování do aplikace.
if (!allstat_mcp_authz_allow_form_target($request['redirect_uri'])) {
    allstat_mcp_authz_error($config, 400, 'Nelze pokračovat', 'Návratová adresa aplikace není povolená.');
}

// Rozpracované přihlášení (heslo ověřeno, 2FA ne): allstat_require_user by ztratilo next=, proto ho předáme sami.
$sessionUser = allstat_current_user($pdo);
if ($sessionUser && allstat_user_has_totp($sessionUser) && empty($_SESSION['totp_verified'])) {
    allstat_redirect($config, 'admin/login-2fa.php?next=' . urlencode((string) ($_SERVER['REQUEST_URI'] ?? '')));
}

$user = allstat_require_user($pdo, $config);
$isAdmin = ($user['role'] ?? '') === 'admin';

allstat_mcp_authz_consent_page(
    $config,
    $client,
    $request,
    $user,
    allstat_mcp_oauth_consent_token($config, $request, (int) $user['id']),
    $isAdmin,
    $isAdmin && allstat_mcp_authz_needs_password()
);
