<?php

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/totp.php';

function allstat_session_start(array $config): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $security = $config['security'] ?? [];
    $timeout = (int) ($security['idle_timeout'] ?? 3600);

    // Session GC musí mít stejné okno jako idle_timeout. Bez toho platí default gc_maxlifetime
    // (1440 s = 24 min) a PHP smaže session soubor dřív, než vyprší idle_timeout → uživatel je
    // odhlášen předčasně bez ohledu na nastavení výše. Musí se nastavit PŘED session_start().
    ini_set('session.gc_maxlifetime', (string) $timeout);
    session_name((string) ($security['session_name'] ?? 'allstat_admin_sid'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => (string) ($config['app']['base_path'] ?? '/allstat'),
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    if (!empty($_SESSION['user_last_seen']) && time() - (int) $_SESSION['user_last_seen'] > $timeout) {
        allstat_logout(false);
    }

    $_SESSION['user_last_seen'] = time();

    allstat_send_security_headers();
}

/**
 * Per-request CSP nonce — použije se v každém inline <script nonce="…"> tagu, aby přísné CSP
 * (script-src 'self' 'nonce-…', BEZ 'unsafe-inline') povolilo naše inline skripty a přitom blokovalo XSS.
 */
function allstat_nonce(): string
{
    static $nonce = null;

    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }

    return $nonce;
}

/**
 * Bezpečnostní hlavičky vč. přísného Content-Security-Policy. Skripty jen z vlastního originu
 * (self-hostované knihovny v assets/vendor) + nonce pro inline; styly self+inline (dynamické šířky
 * pruhů apod. — nízké riziko); žádné externí CDN. Voláno z allstat_session_start (před výstupem).
 */
function allstat_send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    $nonce = allstat_nonce();
    $csp = implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'nonce-{$nonce}'",
        "style-src 'self' 'unsafe-inline'",
        "img-src 'self' data:",
        "font-src 'self'",
        "connect-src 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'none'",
    ]);

    header('Content-Security-Policy: ' . $csp);
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

function allstat_base_path(array $config): string
{
    return rtrim((string) ($config['app']['base_path'] ?? '/allstat'), '/');
}

function allstat_url(array $config, string $path = ''): string
{
    return allstat_base_path($config) . '/' . ltrim($path, '/');
}

function allstat_redirect(array $config, string $path): never
{
    header('Location: ' . allstat_url($config, $path));
    exit;
}

function allstat_request_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
}

function allstat_user_count(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM allstat_users')->fetchColumn();
}

function allstat_current_user(PDO $pdo): ?array
{
    $userId = $_SESSION['user_id'] ?? null;

    if (!$userId) {
        return null;
    }

    $user = allstat_fetch_one($pdo, 'SELECT * FROM allstat_users WHERE id = ? AND is_active = 1', [(int) $userId]);

    if (!$user) {
        allstat_logout(false);
        return null;
    }

    $user['id'] = (int) $user['id'];
    $user['is_active'] = (int) $user['is_active'];
    allstat_set_domain_scope(allstat_user_domain_ids($pdo, $user));

    return $user;
}

/**
 * Weby, ke kterým má uživatel přístup: null = všechny (administrátor nebo účet s přístupem „Všechny weby"),
 * jinak seznam id (i prázdný). Čte se při každém requestu, takže odebrání webu platí okamžitě.
 *
 * @return list<int>|null
 */
function allstat_user_domain_ids(PDO $pdo, array $user): ?array
{
    if (($user['role'] ?? '') === 'admin' || ($user['domain_access'] ?? 'all') !== 'selected') {
        return null;
    }

    $rows = allstat_fetch_all($pdo, 'SELECT domain_id FROM allstat_user_domains WHERE user_id = ?', [(int) $user['id']]);

    return array_map(static fn (array $row): int => (int) $row['domain_id'], $rows);
}

/**
 * Omezení webů pro tento request. Nastaví ho allstat_current_user() podle přihlášeného uživatele a respektují ho
 * allstat_get_domains() i allstat_admin_domains(), takže výběr webu, zapamatovaný web i primární web pracují jen
 * s povolenými weby. null = bez omezení (administrátor, cron, MCP, veřejné sdílené odkazy a feedy).
 *
 * @param list<int>|null $ids
 */
function allstat_set_domain_scope(?array $ids): void
{
    allstat_domain_scope($ids, true);
}

/**
 * @param list<int>|null $ids jen spolu s $set
 * @return list<int>|null
 */
function allstat_domain_scope(?array $ids = null, bool $set = false): ?array
{
    static $scope = null;

    if ($set) {
        $scope = $ids === null ? null : array_values(array_unique(array_map('intval', $ids)));
    }

    return $scope;
}

function allstat_domain_in_scope(int $domainId): bool
{
    $scope = allstat_domain_scope();

    return $domainId > 0 && ($scope === null || in_array($domainId, $scope, true));
}

function allstat_user_has_totp(array $user): bool
{
    return !empty($user['totp_secret_enc']);
}

function allstat_is_authenticated(PDO $pdo): bool
{
    $user = allstat_current_user($pdo);

    if (!$user) {
        return false;
    }

    if (allstat_user_has_totp($user) && empty($_SESSION['totp_verified'])) {
        return false;
    }

    return true;
}

function allstat_require_user(PDO $pdo, array $config): array
{
    if (allstat_user_count($pdo) === 0) {
        allstat_redirect($config, 'install.php');
    }

    $user = allstat_current_user($pdo);
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

    if (!$user) {
        $next = urlencode((string) ($_SERVER['REQUEST_URI'] ?? allstat_url($config, '')));
        allstat_redirect($config, 'admin/login.php?next=' . $next);
    }

    $enforce2fa = allstat_setting($pdo, 'security.enforce_2fa', '0') === '1';

    if (!allstat_user_has_totp($user) && $enforce2fa && !str_ends_with($path, '/admin/setup-2fa.php')) {
        allstat_redirect($config, 'admin/setup-2fa.php');
    }

    if (allstat_user_has_totp($user) && empty($_SESSION['totp_verified']) && !str_ends_with($path, '/admin/login-2fa.php')) {
        allstat_redirect($config, 'admin/login-2fa.php');
    }

    return $user;
}

function allstat_require_admin(array $user): void
{
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('K této stránce nemáte oprávnění.');
    }
}

function allstat_login_is_throttled(PDO $pdo, string $ip): bool
{
    $row = allstat_fetch_one($pdo, "SELECT COUNT(*) AS attempts FROM allstat_login_attempts WHERE ip_address = ? AND was_success = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)", [$ip]);

    return (int) ($row['attempts'] ?? 0) >= 5;
}

function allstat_record_login_attempt(PDO $pdo, string $ip, ?string $email, bool $success): void
{
    $statement = $pdo->prepare('INSERT INTO allstat_login_attempts (ip_address, email, was_success) VALUES (?, ?, ?)');
    $statement->execute([$ip, $email, $success ? 1 : 0]);

    if (random_int(1, 20) === 1) {
        $pdo->exec('DELETE FROM allstat_login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
    }
}

function allstat_login(PDO $pdo, array $config, string $email, string $password): array
{
    $email = allstat_normalize_email($email);
    $ip = allstat_request_ip();

    if (allstat_login_is_throttled($pdo, $ip)) {
        return ['ok' => false, 'message' => 'Příliš mnoho pokusů. Zkuste to znovu za 15 minut.'];
    }

    $user = allstat_fetch_one($pdo, 'SELECT * FROM allstat_users WHERE email = ? AND is_active = 1', [$email]);
    $hash = $user['password_hash'] ?? password_hash(random_bytes(16), PASSWORD_DEFAULT);
    $valid = password_verify($password, $hash);

    allstat_record_login_attempt($pdo, $ip, $email, $valid && (bool) $user);

    if (!$valid || !$user) {
        allstat_audit($pdo, null, null, 'login_failed', $email);
        return ['ok' => false, 'message' => 'E-mail nebo heslo nesedí.'];
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_authed_at'] = time();
    $_SESSION['totp_verified'] = empty($user['totp_secret_enc']);
    $_SESSION['totp_action_verified_at'] = 0;
    // Zaznamenat poslední přihlášení už po úspěšném hesle — dřív se nastavovalo jen po 2FA, takže
    // účty bez 2FA měly v seznamu uživatelů navždy „Nikdy". U 2FA účtů to complete_totp_login přepíše.
    $pdo->prepare('UPDATE allstat_users SET last_login_at = NOW() WHERE id = ?')->execute([(int) $user['id']]);
    allstat_audit($pdo, (int) $user['id'], (int) $user['id'], 'login_password_ok');

    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        $statement = $pdo->prepare('UPDATE allstat_users SET password_hash = ? WHERE id = ?');
        $statement->execute([password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);
    }

    return ['ok' => true, 'user' => $user, 'requires_totp' => !empty($user['totp_secret_enc'])];
}

function allstat_complete_totp_login(PDO $pdo, array $config, string $code): array
{
    $user = allstat_current_user($pdo);

    if (!$user || empty($user['totp_secret_enc'])) {
        return ['ok' => false, 'message' => '2FA ověření teď není dostupné.'];
    }

    $secret = allstat_decrypt_secret($user['totp_secret_enc'], $config);
    $matchedWindow = null;
    $lastWindow = isset($user['totp_last_window']) ? (int) $user['totp_last_window'] : -1;

    if (!$secret || !allstat_totp_verify($secret, $code, $lastWindow, $matchedWindow)) {
        allstat_audit($pdo, (int) $user['id'], (int) $user['id'], 'login_2fa_failed');
        return ['ok' => false, 'message' => 'Kód není platný nebo už byl použitý.'];
    }

    $statement = $pdo->prepare('UPDATE allstat_users SET totp_last_window = ?, last_login_at = NOW() WHERE id = ?');
    $statement->execute([$matchedWindow, (int) $user['id']]);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['totp_verified'] = true;
    $_SESSION['totp_action_verified_at'] = time();
    allstat_audit($pdo, (int) $user['id'], (int) $user['id'], 'login_2fa_ok');

    return ['ok' => true];
}

function allstat_logout(bool $destroyCookie = true): void
{
    $_SESSION = [];

    if ($destroyCookie && ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function allstat_setting(PDO $pdo, string $key, ?string $default = null): ?string
{
    $row = allstat_fetch_one($pdo, 'SELECT setting_value FROM allstat_settings WHERE setting_key = ?', [$key]);

    return $row ? (string) $row['setting_value'] : $default;
}

function allstat_set_setting(PDO $pdo, string $key, string $value): void
{
    $statement = $pdo->prepare('INSERT INTO allstat_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $statement->execute([$key, $value]);
}

/**
 * Normalizace domény pro porovnání (bez schématu, „www." a lomítek) – stejný tvar pro HTTP_HOST i domains.url.
 */
function allstat_normalize_host(string $value): string
{
    $value = strtolower(trim($value));
    $value = (string) preg_replace('~^[a-z]+://~', '', $value);
    $value = (string) preg_replace('~[/:].*$~', '', $value);

    return (string) preg_replace('~^www\.~', '', $value);
}

/**
 * Primární web: nastavení `app.primary_domain_id` (když je platné a aktivní), jinak web se stejnou doménou,
 * na které AllStat běží (example.cz/allstat → example.cz), jinak první podle abecedy.
 *
 * @param array<int, array<string, mixed>> $domains aktivní weby (id, url)
 */
function allstat_primary_domain_id(PDO $pdo, array $domains): int
{
    if (!$domains) {
        return 0;
    }

    $ids = array_map(static fn (array $d): int => (int) $d['id'], $domains);
    $setting = (int) allstat_setting($pdo, 'app.primary_domain_id', '0');
    if ($setting && in_array($setting, $ids, true)) {
        return $setting;
    }

    $host = allstat_normalize_host((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== '') {
        foreach ($domains as $d) {
            if (allstat_normalize_host((string) ($d['url'] ?? '')) === $host) {
                return (int) $d['id'];
            }
        }
    }

    return $ids[0];
}

/**
 * Aktuálně zvolený web. Volba se drží v session napříč celou administrací (přehled, reporty, zdroje…),
 * dokud ji uživatel sám nepřepne; po přihlášení začíná na primárním webu.
 *
 * Pořadí: explicitní volba v requestu (uloží se) → web ze session → primární web. Neplatné/neaktivní id
 * (např. smazaný web) se ignoruje.
 *
 * @param array<int, array<string, mixed>> $domains aktivní weby (id, url)
 * @param int|null $requested domain_id z requestu, null = nezadáno
 */
function allstat_current_domain_id(PDO $pdo, array $domains, ?int $requested = null): int
{
    $ids = array_map(static fn (array $d): int => (int) $d['id'], $domains);

    if ($requested && in_array($requested, $ids, true)) {
        allstat_remember_domain_id($requested);

        return $requested;
    }

    $remembered = (int) ($_SESSION['current_domain_id'] ?? 0);
    if ($remembered && in_array($remembered, $ids, true)) {
        return $remembered;
    }

    return allstat_primary_domain_id($pdo, $domains);
}

/**
 * Zapamatuje si zvolený web pro zbytek přihlášení (viz allstat_current_domain_id).
 */
function allstat_remember_domain_id(int $domainId): void
{
    if ($domainId > 0 && session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['current_domain_id'] = $domainId;
    }
}

function allstat_audit(PDO $pdo, ?int $actorUserId, ?int $targetUserId, string $action, ?string $detail = null): void
{
    $statement = $pdo->prepare('INSERT INTO allstat_user_audit_log (actor_user_id, target_user_id, action, detail, ip_address) VALUES (?, ?, ?, ?, ?)');
    $statement->execute([$actorUserId, $targetUserId, $action, $detail, allstat_request_ip()]);
}
