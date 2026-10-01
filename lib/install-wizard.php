<?php

/**
 * AllStat: webový instalátor (vstupní bod je install.php v kořeni aplikace).
 *
 * Průvodce zkontroluje server, připojí se k databázi, vytvoří tabulky, prvního administrátora a
 * první sledovaný web a sám vytvoří config.php a config-keys.php s UNIKÁTNÍM šifrovacím klíčem.
 * Po dokončení se uzamkne (a pokud to jde, smaže sám sebe).
 *
 * Bez sessions: ochrana proti CSRF používá cookie (double submit), stav se mezi kroky nenosí,
 * takže průvodce funguje i na hostinzích s nezapisovatelným úložištěm sessions.
 */

$installRoot = dirname(__DIR__);

// Poznámky a upozornění na zastaralé rysy PHP by rozbila hlavičky i vzhled stránky, chyby a varování zůstávají.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_STRICT);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/admin-repository.php';

/** Chyba s hláškou určenou přímo uživateli instalátoru. */
final class InstallerError extends RuntimeException
{
    /** @var string[] */
    private array $messages;

    /** @param string|string[] $messages */
    public function __construct(string|array $messages)
    {
        $this->messages = (array) $messages;
        parent::__construct(implode(' ', $this->messages));
    }

    /** @return string[] */
    public function messages(): array
    {
        return $this->messages;
    }
}

// ---------------------------------------------------------------------------------------------
// Prostředí a pomocné funkce
// ---------------------------------------------------------------------------------------------

function installer_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    // Za reverzní proxy (např. Cloudflare) přijde HTTPS jen v hlavičce.
    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https' || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function installer_host(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');

    return preg_match('/^[A-Za-z0-9.\-:\[\]]{1,255}$/', $host) === 1 ? $host : 'localhost';
}

function installer_is_local_host(): bool
{
    $host = strtolower((string) preg_replace('/:\d+$/', '', installer_host()));

    return $host === 'localhost' || $host === '127.0.0.1' || $host === '[::1]' || str_ends_with($host, '.localhost') || str_ends_with($host, '.test');
}

/** Cesta, na které aplikace běží (např. "/allstat", nebo "/" v kořeni domény). */
function installer_base_path(): string
{
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '/install.php');
    $dir = str_replace('\\', '/', dirname($script));

    return ($dir === '' || $dir === '.' || $dir === '/') ? '/' : rtrim($dir, '/');
}

function installer_url(string $path = ''): string
{
    $base = installer_base_path();

    return (installer_is_https() ? 'https' : 'http') . '://' . installer_host() . rtrim($base, '/') . '/' . ltrim($path, '/');
}

function installer_version(string $root): string
{
    $file = $root . '/VERSION';

    return is_file($file) ? trim((string) @file_get_contents($file)) : '';
}

function installer_nonce(): string
{
    static $nonce = null;

    return $nonce ??= base64_encode(random_bytes(12));
}

function installer_send_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'nonce-" . installer_nonce() . "'; img-src data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
}

// --- CSRF bez sessions (double submit cookie) --------------------------------------------------

function installer_csrf_token(): string
{
    static $token = null;

    if ($token !== null) {
        return $token;
    }

    $cookie = $_COOKIE['allstat_install_csrf'] ?? '';
    $token = (is_string($cookie) && preg_match('/^[a-f0-9]{48}$/', $cookie) === 1) ? $cookie : bin2hex(random_bytes(24));

    if (!headers_sent()) {
        setcookie('allstat_install_csrf', $token, [
            'expires' => 0,
            'path' => installer_base_path(),
            'secure' => installer_is_https(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    return $token;
}

function installer_csrf_valid(): bool
{
    $cookie = $_COOKIE['allstat_install_csrf'] ?? '';
    $posted = $_POST['csrf'] ?? '';

    return is_string($cookie) && is_string($posted) && $cookie !== '' && hash_equals($cookie, $posted);
}

// ---------------------------------------------------------------------------------------------
// Kontrola serveru
// ---------------------------------------------------------------------------------------------

/**
 * Porovná nahrané soubory s lib/manifest.json (velikost a SHA-256), aby se poznaly nedokončené nebo
 * poškozené FTP nahrávky. Vrací null, když manifest chybí (například při spuštění ze zdroje).
 *
 * @return array{total:int,missing:string[],corrupt:string[]}|null
 */
function installer_verify_files(string $root): ?array
{
    $file = $root . '/lib/manifest.json';
    if (!is_file($file)) {
        return null;
    }

    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data) || !isset($data['files']) || !is_array($data['files'])) {
        return null;
    }

    $missing = [];
    $corrupt = [];
    foreach ($data['files'] as $rel => $meta) {
        $path = $root . '/' . $rel;
        if (!is_file($path)) {
            $missing[] = (string) $rel;
        } elseif ((int) filesize($path) !== (int) ($meta['s'] ?? -1) || !hash_equals((string) ($meta['h'] ?? ''), (string) hash_file('sha256', $path))) {
            $corrupt[] = (string) $rel;
        }
    }

    return ['total' => count($data['files']), 'missing' => $missing, 'corrupt' => $corrupt];
}

/** Vrací [kód HTTP odpovědi na internet, kód HTTP odpovědi na schema.sql]; 0 = nezjištěno, -1 = cURL chybí. */
function installer_probe_http(): array
{
    if (!function_exists('curl_init') || !function_exists('curl_multi_init')) {
        return ['internet' => -1, 'schema' => -1];
    }

    $multi = curl_multi_init();
    $common = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false];

    $internet = curl_init('https://www.googleapis.com/');
    curl_setopt_array($internet, $common + [CURLOPT_NOBODY => true]);
    curl_multi_add_handle($multi, $internet);

    // Kontrola, že se citlivé soubory (.sql) nedají stáhnout: dotaz míří VŽDY na loopback, ne na cizí server.
    $schema = null;
    $host = (string) preg_replace('/:\d+$/', '', installer_host());
    if (preg_match('/^[A-Za-z0-9.\-]+$/', $host) === 1) {
        $port = installer_is_https() ? 443 : (int) ($_SERVER['SERVER_PORT'] ?? 80);
        $url = (installer_is_https() ? 'https' : 'http') . '://' . $host . ':' . $port . rtrim(installer_base_path(), '/') . '/schema.sql';
        $schema = curl_init($url);
        curl_setopt_array($schema, $common + [
            CURLOPT_RESOLVE => [$host . ':' . $port . ':127.0.0.1'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        curl_multi_add_handle($multi, $schema);
    }

    $running = 0;
    do {
        curl_multi_exec($multi, $running);
        if ($running > 0) {
            curl_multi_select($multi, 0.5);
        }
    } while ($running > 0);

    $out = ['internet' => (int) curl_getinfo($internet, CURLINFO_RESPONSE_CODE), 'schema' => 0];
    if ($schema !== null) {
        $out['schema'] = (int) curl_getinfo($schema, CURLINFO_RESPONSE_CODE);
        curl_multi_remove_handle($multi, $schema);
    }
    curl_multi_remove_handle($multi, $internet);
    curl_multi_close($multi);

    return $out;
}

/** Ověří, že PHP umí ukládat sessions (bez toho by nešlo přihlášení). Nezanechá žádnou cookie. */
function installer_session_works(): bool
{
    if (session_status() !== PHP_SESSION_NONE || headers_sent()) {
        return true; // nejde bezpečně otestovat
    }

    $failed = false;
    set_error_handler(static function () use (&$failed): bool {
        $failed = true;

        return true;
    });

    try {
        ini_set('session.use_cookies', '0');
        ini_set('session.use_only_cookies', '0');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cache_limiter', '');
        $id = 'allstatprobe' . bin2hex(random_bytes(8));
        session_id($id);
        $ok = session_start();
        if ($ok) {
            $_SESSION['probe'] = 1;
            session_write_close();
            session_id($id);
            session_start();
            $failed = $failed || ($_SESSION['probe'] ?? 0) !== 1;
            session_destroy();
        }
    } catch (Throwable) {
        $failed = true;
    } finally {
        restore_error_handler();
    }

    return !$failed && !empty($ok);
}

/**
 * Kontroly serveru. Každá položka: ['status' => ok|warn|fail, 'label' => ..., 'detail' => ...].
 *
 * @return array<int, array{status:string,label:string,detail:string}>
 */
function installer_environment_checks(string $root, bool $withNetwork = true): array
{
    $checks = [];
    $add = static function (string $status, string $label, string $detail = '') use (&$checks): void {
        $checks[] = ['status' => $status, 'label' => $label, 'detail' => $detail];
    };

    // PHP
    if (version_compare(PHP_VERSION, '8.1.0', '<')) {
        $add('fail', 'Verze PHP', 'Server používá PHP ' . PHP_VERSION . ', AllStat vyžaduje 8.1 nebo novější (doporučeno 8.3).');
    } elseif (version_compare(PHP_VERSION, '8.2.0', '<')) {
        $add('warn', 'Verze PHP', 'PHP ' . PHP_VERSION . ' funguje, ale už nedostává bezpečnostní opravy. Doporučujeme přepnout na PHP 8.3 nebo novější.');
    } else {
        $add('ok', 'Verze PHP', 'PHP ' . PHP_VERSION);
    }

    // Rozšíření
    $extensions = [
        'pdo_mysql' => 'přístup k databázi MySQL',
        'curl' => 'komunikace se službami Google, Meta a LinkedIn',
        'openssl' => 'šifrování uložených přístupů',
        'mbstring' => 'práce s českými znaky',
        'json' => 'zpracování dat',
        'ctype' => 'kontrola vstupů',
    ];
    foreach ($extensions as $name => $purpose) {
        if (extension_loaded($name)) {
            $add('ok', 'Rozšíření PHP: ' . $name, ucfirst($purpose));
        } else {
            $add('fail', 'Rozšíření PHP: ' . $name, 'Chybí (' . $purpose . '). Zapněte ho v nastavení PHP na hostingu nebo požádejte poskytovatele.');
        }
    }

    if (extension_loaded('openssl') && !in_array('aes-256-gcm', array_map('strtolower', openssl_get_cipher_methods()), true)) {
        $add('fail', 'Šifra AES-256-GCM', 'OpenSSL na tomto serveru nepodporuje šifru AES-256-GCM, kterou AllStat používá pro uložení přístupů.');
    }

    // Soubory a práva
    $verify = installer_verify_files($root);
    if (!is_file($root . '/schema.sql') || !is_readable($root . '/schema.sql') || !is_file($root . '/lib/migrations.php')) {
        $add('fail', 'Soubory aplikace', 'Chybí základní soubory (například schema.sql). Nahrajte prosím znovu celý obsah balíčku včetně všech složek.');
    } elseif ($verify !== null && ($verify['missing'] !== [] || $verify['corrupt'] !== [])) {
        $parts = [];
        if ($verify['missing'] !== []) {
            $parts[] = 'Chybějící soubory: ' . count($verify['missing']) . ' (například ' . implode(', ', array_slice($verify['missing'], 0, 4)) . ').';
        }
        if ($verify['corrupt'] !== []) {
            $parts[] = 'Poškozené soubory (jiná velikost nebo obsah): ' . count($verify['corrupt']) . ' (například ' . implode(', ', array_slice($verify['corrupt'], 0, 4)) . ').';
        }
        $add('fail', 'Soubory aplikace', implode(' ', $parts) . ' Nahrajte je prosím znovu, v FTP klientovi s automatickým nebo binárním režimem přenosu.');
    } elseif ($verify !== null) {
        $add('ok', 'Soubory aplikace', 'Všech ' . $verify['total'] . ' souborů je nahraných a neporušených.');
    } else {
        $add('ok', 'Soubory aplikace', 'Základní soubory jsou na místě.');
    }

    if (is_writable($root)) {
        $add('ok', 'Zápis do složky aplikace', 'Instalátor může sám uložit config.php a config-keys.php.');
    } else {
        $add('warn', 'Zápis do složky aplikace', 'Složka není zapisovatelná. Nevadí, na konci dostanete soubory config.php a config-keys.php ke stažení a nahrajete je ručně.');
    }

    // HTTPS
    if (installer_is_https()) {
        $add('ok', 'Šifrované spojení (HTTPS)', 'Web běží přes HTTPS.');
    } elseif (installer_is_local_host()) {
        $add('ok', 'Šifrované spojení (HTTPS)', 'Lokální adresa, HTTPS není potřeba.');
    } else {
        $add('warn', 'Šifrované spojení (HTTPS)', 'Web běží bez HTTPS. Hesla by putovala sítí nešifrovaně a služby jako Google a Meta HTTPS pro přihlášení vyžadují. Nejdřív zapněte HTTPS certifikát na hostingu.');
    }

    // Sessions
    if (installer_session_works()) {
        $add('ok', 'Přihlášení (sessions)', 'PHP umí ukládat relace přihlášených uživatelů.');
    } else {
        $add('warn', 'Přihlášení (sessions)', 'PHP nedokáže uložit relace (nezapisovatelná složka pro sessions). Přihlášení do AllStatu by nemuselo fungovat, požádejte poskytovatele hostingu o kontrolu.');
    }

    // Paměť
    $limit = (string) ini_get('memory_limit');
    $bytes = installer_bytes($limit);
    if ($bytes !== -1 && $bytes < 128 * 1024 * 1024) {
        $add('warn', 'Paměť pro PHP', 'Limit je ' . $limit . '. Pro větší weby doporučujeme alespoň 128M.');
    } else {
        $add('ok', 'Paměť pro PHP', 'Limit ' . $limit . '.');
    }

    // Síť
    if ($withNetwork) {
        $probe = installer_probe_http();

        if ($probe['internet'] === -1) {
            // cURL chybí, už je nahlášené výše
        } elseif ($probe['internet'] > 0) {
            $add('ok', 'Přístup na internet', 'Server se dokáže spojit s externími službami (Google a další).');
        } else {
            $add('warn', 'Přístup na internet', 'Server se nepodařilo spojit s https://www.googleapis.com. Hosting může blokovat odchozí spojení. Bez něj AllStat nestáhne data ze služeb.');
        }

        if ($probe['schema'] >= 200 && $probe['schema'] < 300) {
            $add('warn', 'Ochrana citlivých souborů', 'Soubor schema.sql je z internetu stažitelný, takže .htaccess na tomto serveru nefunguje (například nginx). Není to kritické, ale požádejte poskytovatele o zákaz přímého přístupu k souborům .sql, .md a .log.');
        } elseif ($probe['schema'] > 0) {
            $add('ok', 'Ochrana citlivých souborů', 'Přímé stažení souborů .sql je zakázané.');
        } elseif ($probe['schema'] === 0) {
            $add('ok', 'Ochrana citlivých souborů', 'Automaticky se to ověřit nepodařilo, nevadí. Postup ruční kontroly je v příručce.');
        }
    }

    return $checks;
}

/** Délka řetězce ve znacích; bez mbstring (které se stejně hlásí jako chybějící) spadne na bajty. */
function installer_strlen(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function installer_bytes(string $value): int
{
    $value = trim($value);

    if ($value === '' || $value === '-1') {
        return -1;
    }

    $number = (int) $value;

    return match (strtolower(substr($value, -1))) {
        'g' => $number * 1024 * 1024 * 1024,
        'm' => $number * 1024 * 1024,
        'k' => $number * 1024,
        default => $number,
    };
}

function installer_has_failure(array $checks): bool
{
    foreach ($checks as $check) {
        if ($check['status'] === 'fail') {
            return true;
        }
    }

    return false;
}

// ---------------------------------------------------------------------------------------------
// Stav instalace
// ---------------------------------------------------------------------------------------------

/** @return array{0:?array,1:?string} */
function installer_load_config(string $path): array
{
    try {
        $config = (static fn() => require $path)();
    } catch (Throwable $e) {
        return [null, 'Soubor config.php nejde načíst (' . preg_replace('/\s+/', ' ', $e->getMessage()) . ').'];
    }

    if (!is_array($config) || !isset($config['db']['host'], $config['db']['database'], $config['db']['username'])) {
        return [null, 'Soubor config.php nemá očekávanou strukturu.'];
    }

    $config['db'] += ['port' => 3306, 'password' => '', 'charset' => 'utf8mb4'];
    $config['app'] = ($config['app'] ?? []) + ['name' => 'AllStat', 'base_path' => '/allstat', 'timezone' => 'Europe/Prague'];

    return [$config, null];
}

function installer_table_exists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $statement->execute([$table]);

    return (int) $statement->fetchColumn() > 0;
}

/**
 * fresh   = config.php ještě není (čistá instalace)
 * resume  = config.php funguje a databáze je dosažitelná, ale instalace není dokončená
 * broken  = config.php existuje, ale nejde načíst nebo se přes něj nelze připojit k databázi
 * locked  = AllStat je nainstalovaný (existuje uživatel)
 *
 * @return array{mode:string,config?:array,pdo?:PDO,message?:string}
 */
function installer_detect_state(string $root): array
{
    $path = $root . '/config.php';

    if (!is_file($path)) {
        return ['mode' => 'fresh'];
    }

    [$config, $error] = installer_load_config($path);
    if ($error !== null) {
        return ['mode' => 'broken', 'message' => $error];
    }

    $pdo = allstat_db($config);
    if (!$pdo) {
        return ['mode' => 'broken', 'message' => 'Soubor config.php existuje, ale s jeho údaji se nepodařilo připojit k databázi.'];
    }

    if (installer_table_exists($pdo, 'allstat_users') && allstat_user_count($pdo) > 0) {
        return ['mode' => 'locked', 'config' => $config];
    }

    return ['mode' => 'resume', 'config' => $config, 'pdo' => $pdo];
}

// ---------------------------------------------------------------------------------------------
// Databáze
// ---------------------------------------------------------------------------------------------

function installer_pdo(array $db, bool $withDatabase = true): PDO
{
    $dsn = 'mysql:host=' . $db['host'] . ';port=' . (int) $db['port'] . ($withDatabase ? ';dbname=' . $db['database'] : '') . ';charset=utf8mb4';

    return new PDO($dsn, (string) $db['username'], (string) $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 10,
    ]);
}

function installer_db_error_text(Throwable $e): string
{
    $code = $e instanceof PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;

    $text = match (true) {
        $code === 1045 => 'Server databáze odmítl přihlášení: nesprávné jméno uživatele nebo heslo.',
        $code === 1044 => 'Uživatel nemá přístup k této databázi. V administraci hostingu ho přiřaďte k databázi s plnými právy.',
        $code === 1049 => 'Databáze tohoto názvu neexistuje.',
        $code === 1130 => 'Databázový server nepovoluje připojení z tohoto serveru.',
        $code === 2002 => 'K databázovému serveru se nelze připojit. Zkontrolujte název serveru a port: na většině hostingů je to „localhost“, jinde „127.0.0.1“ nebo adresa uvedená u databáze v administraci hostingu.',
        in_array($code, [1251, 2054], true) => 'Databázový server používá způsob přihlášení, který PHP na tomto hostingu nepodporuje. Požádejte poskytovatele o přepnutí uživatele na běžné ověřování heslem (mysql_native_password).',
        default => 'Připojení k databázi se nepovedlo.',
    };

    return $text . ' (technický detail: ' . trim((string) preg_replace('/\s+/', ' ', $e->getMessage())) . ')';
}

/** Názvy tabulek, které AllStat zakládá (ze schema.sql a migrací). @return string[] */
function installer_known_tables(string $root): array
{
    $names = [];
    foreach (['/schema.sql', '/lib/migrations.php'] as $file) {
        $text = is_file($root . $file) ? (string) file_get_contents($root . $file) : '';
        if (preg_match_all('/CREATE TABLE IF NOT EXISTS\s+`?([a-z0-9_]+)`?/i', $text, $found)) {
            $names = array_merge($names, $found[1]);
        }
    }

    return array_values(array_unique($names));
}

/**
 * Prověří připojenou databázi. Vrací ['checks' => [...], 'fatal' => bool, 'has_users' => bool].
 * Zkušebně vytvoří a hned smaže pomocnou tabulku (ověření práv, InnoDB a kolace).
 */
function installer_db_inspect(PDO $pdo, string $root): array
{
    $checks = [];
    $fatal = false;
    $add = static function (string $status, string $label, string $detail) use (&$checks, &$fatal): void {
        $checks[] = ['status' => $status, 'label' => $label, 'detail' => $detail];
        $fatal = $fatal || $status === 'fail';
    };

    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $isMaria = false;
    $numeric = '0.0.0';
    if (preg_match('/(\d+\.\d+\.\d+)-MariaDB/i', $version, $m)) {
        $isMaria = true;
        $numeric = $m[1];
    } elseif (preg_match('/(\d+\.\d+\.\d+)/', $version, $m)) {
        $numeric = $m[1];
    }
    $versionOk = $isMaria ? version_compare($numeric, '10.3.0', '>=') : version_compare($numeric, '5.7.8', '>=');
    $add($versionOk ? 'ok' : 'fail', 'Verze databáze', ($isMaria ? 'MariaDB ' : 'MySQL ') . $numeric . ($versionOk ? '' : ': AllStat vyžaduje MySQL 5.7.8 nebo novější, případně MariaDB 10.3 nebo novější.'));

    // Existující instalace
    $hasUsers = false;
    if (installer_table_exists($pdo, 'allstat_users')) {
        $hasUsers = (int) $pdo->query('SELECT COUNT(*) FROM allstat_users')->fetchColumn() > 0;
    }
    if ($hasUsers) {
        $add('fail', 'Obsah databáze', 'V této databázi už AllStat s uživateli existuje. Instalátor by ji přepsal, proto se zastavil. Použijte prázdnou databázi, nebo obnovte soubory config.php a config-keys.php ze zálohy.');
    } else {
        $existing = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
        $foreign = array_values(array_diff($existing, installer_known_tables($root)));
        if ($existing === []) {
            $add('ok', 'Obsah databáze', 'Databáze je prázdná.');
        } elseif ($foreign === []) {
            $add('ok', 'Obsah databáze', 'Databáze obsahuje jen nedokončenou předchozí instalaci AllStatu, instalace naváže.');
        } else {
            $add('warn', 'Obsah databáze', 'V databázi jsou i tabulky jiné aplikace (' . count($foreign) . '). AllStat používá tabulky bez předpony, doporučujeme pro něj prázdnou databázi.');
        }
    }

    // Zkušební tabulka: práva CREATE/ALTER/INSERT/DROP, InnoDB, kolace utf8mb4_czech_ci
    $probe = 'allstat_install_probe_' . bin2hex(random_bytes(3));
    try {
        $pdo->exec('CREATE TABLE `' . $probe . '` (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci');
        try {
            $pdo->exec('ALTER TABLE `' . $probe . '` ADD COLUMN note VARCHAR(20) NULL');
            $pdo->exec('INSERT INTO `' . $probe . '` (id, note) VALUES (1, \'test\')');
            $engine = (string) $pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $probe . "'")->fetchColumn();
        } finally {
            $pdo->exec('DROP TABLE IF EXISTS `' . $probe . '`');
        }

        if (strcasecmp($engine, 'InnoDB') !== 0) {
            $add('fail', 'Úložiště InnoDB', 'Server použil místo InnoDB úložiště „' . $engine . '“. AllStat potřebuje InnoDB (cizí klíče).');
        } else {
            $add('ok', 'Práva a úložiště', 'Uživatel smí vytvářet a upravovat tabulky (InnoDB, čeština utf8mb4).');
        }
    } catch (Throwable $e) {
        $code = $e instanceof PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
        if (in_array($code, [1044, 1142, 1227], true)) {
            $add('fail', 'Práva uživatele', 'Uživatel databáze nemá právo vytvářet nebo upravovat tabulky. V administraci hostingu mu přiřaďte plná práva k této databázi.');
        } elseif ($code === 1273 || $code === 1253) {
            $add('fail', 'Kolace utf8mb4_czech_ci', 'Databázový server nezná kolaci utf8mb4_czech_ci, kterou AllStat používá. Požádejte poskytovatele o novější verzi MySQL nebo MariaDB.');
        } else {
            $add('fail', 'Zkušební zápis do databáze', 'Nepodařilo se vytvořit zkušební tabulku (' . trim((string) preg_replace('/\s+/', ' ', $e->getMessage())) . ').');
        }
    }

    return ['checks' => $checks, 'fatal' => $fatal, 'has_users' => $hasUsers];
}

// ---------------------------------------------------------------------------------------------
// Formulář a validace
// ---------------------------------------------------------------------------------------------

function installer_timezones(): array
{
    $groups = [];
    foreach (DateTimeZone::listIdentifiers(DateTimeZone::ALL) as $id) {
        $group = str_contains($id, '/') ? substr($id, 0, (int) strpos($id, '/')) : 'Ostatní';
        $groups[$group][] = $id;
    }

    return $groups;
}

/** Hodnoty formuláře (z POSTu, jinak výchozí). */
function installer_form(): array
{
    $post = $_SERVER['REQUEST_METHOD'] === 'POST';
    $text = static fn(string $key, string $default = ''): string => ($post && isset($_POST[$key]) && is_string($_POST[$key])) ? trim($_POST[$key]) : $default;
    $raw = static fn(string $key): string => ($post && isset($_POST[$key]) && is_string($_POST[$key])) ? $_POST[$key] : '';

    $host = (string) preg_replace('/:\d+$/', '', installer_host());
    $webDefault = installer_is_local_host() ? '' : (installer_is_https() ? 'https' : 'http') . '://' . $host;

    return [
        'db_host' => $text('db_host', 'localhost'),
        'db_port' => $text('db_port', '3306'),
        'db_name' => $text('db_name'),
        'db_user' => $text('db_user'),
        'db_pass' => $raw('db_pass'),
        'org_name' => $text('org_name'),
        'org_email' => $text('org_email'),
        'org_web' => $text('org_web', $webDefault),
        'org_address' => $text('org_address'),
        'org_ico' => $text('org_ico'),
        'org_context' => $text('org_context'),
        'timezone' => $text('timezone', 'Europe/Prague'),
        'base_path' => $text('base_path', installer_base_path()),
        'admin_name' => $text('admin_name'),
        'admin_email' => $text('admin_email'),
        'admin_password' => $raw('admin_password'),
        'admin_password2' => $raw('admin_password2'),
        'mcp_mode' => $text('mcp_mode', 'same'),
        'mcp_host_url' => $text('mcp_host_url'),
    ];
}

/** Veřejná adresa AllStatu (bez lomítka na konci) podle cesty aplikace, například https://www.vase-firma.cz/allstat. */
function installer_app_url(string $basePath): string
{
    $path = ($basePath === '/' || $basePath === '') ? '' : '/' . trim($basePath, '/');

    return (installer_is_https() ? 'https' : 'http') . '://' . installer_host() . $path;
}

/**
 * Ověří a sjednotí adresu MCP serveru: https (http jen pro localhost a 127.0.0.1), bez parametrů, kotvy a lomítka na konci,
 * volitelná cesta. Stejná pravidla používá stránka AI konektory v administraci. Vrací normalizovanou adresu nebo null.
 */
function installer_normalize_mcp_url(string $value): ?string
{
    $value = trim($value);
    if ($value === '' || strlen($value) > 255 || preg_match('/[\s\x00-\x1f\x7f?#]/', $value) === 1) {
        return null;
    }

    $parts = parse_url($value);
    if ($parts === false || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        return null;
    }

    $scheme = strtolower((string) $parts['scheme']);
    $host = strtolower((string) $parts['host']);
    $local = in_array($host, ['localhost', '127.0.0.1'], true);
    if (($scheme !== 'https' && !($scheme === 'http' && $local)) || preg_match('/^[a-z0-9.\-]+$/', $host) !== 1) {
        return null;
    }
    if (!$local && (!str_contains($host, '.') || preg_match('/^[0-9.]+$/', $host) === 1)) {
        return null;
    }

    $path = (string) ($parts['path'] ?? '');
    if ($path !== '' && (str_ends_with($path, '/') || preg_match('#^(?:/[A-Za-z0-9._~\-]+)+$#', $path) !== 1)) {
        return null;
    }

    $port = isset($parts['port']) ? (int) $parts['port'] : null;
    if ($port !== null) {
        if ($port < 1 || $port > 65535) {
            return null;
        }
        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null; // výchozí port se neukládá
        }
    }

    return $scheme . '://' . $host . ($port !== null ? ':' . $port : '') . $path;
}

/**
 * Pravidlo pro soubor .htaccess v KOŘENI DOMÉNY při instalaci AllStatu ve složce: klienti připojení AI (Claude, ChatGPT)
 * hledají metadata přihlášení na kořeni domény s cestou na konci (RFC 8414 a 9728). Pravidlo takové adresy interně
 * předá front controlleru ve složce allstat-mcp. Vrací prázdný řetězec, když AllStat běží v kořeni adresy.
 */
function installer_mcp_root_block(string $basePath): string
{
    $path = trim($basePath, '/');
    if ($path === '') {
        return '';
    }

    $regex = str_replace('.', '\\.', $path);
    $target = '/' . $path . '/allstat-mcp/index.php';

    return "# BEGIN AllStat MCP\n"
        . "<IfModule mod_rewrite.c>\n"
        . "RewriteEngine On\n"
        . 'RewriteRule ^\.well-known/(?:oauth-authorization-server|openid-configuration)/' . $regex . '/?$ ' . $target . " [L,E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n"
        . 'RewriteRule ^\.well-known/oauth-protected-resource/' . $regex . '(?:/mcp)?/?$ ' . $target . " [L,E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n"
        . "</IfModule>\n"
        . '# END AllStat MCP';
}

/** Přidá schéma k adrese webu a ověří ji. Vrací [normalizovaná adresa, host] nebo null. */
function installer_normalize_web(string $value): ?array
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (!preg_match('~^[a-z][a-z0-9+.\-]*://~i', $value)) {
        $value = 'https://' . $value;
    }

    $parts = parse_url($value);
    $host = strtolower((string) ($parts['host'] ?? ''));
    if (!in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || $host === '' || preg_match('/^[a-z0-9.\-]+$/', $host) !== 1) {
        return null;
    }
    if (!str_contains($host, '.') && $host !== 'localhost') {
        return null;
    }

    return [rtrim($value, '/'), $host];
}

/** @return string[] chyby */
function installer_validate_db(array $f): array
{
    $errors = [];

    if (preg_match('/^[A-Za-z0-9._\-]{1,255}$/', $f['db_host']) !== 1) {
        $errors[] = 'Server databáze smí obsahovat jen písmena, číslice, tečku, pomlčku a podtržítko (bez portu, ten se zadává zvlášť).';
    }
    if (preg_match('/^\d{1,5}$/', $f['db_port']) !== 1 || (int) $f['db_port'] < 1 || (int) $f['db_port'] > 65535) {
        $errors[] = 'Port databáze musí být číslo od 1 do 65535 (obvykle 3306).';
    }
    if (preg_match('/^[A-Za-z0-9_$\-]{1,64}$/', $f['db_name']) !== 1) {
        $errors[] = 'Název databáze vyplňte přesně podle hostingu (písmena, číslice, podtržítko, případně pomlčka; nejvýše 64 znaků).';
    }
    if ($f['db_user'] === '' || strlen($f['db_user']) > 80) {
        $errors[] = 'Vyplňte uživatele databáze.';
    }

    return $errors;
}

/** @return string[] chyby */
function installer_validate(array $f, array $state): array
{
    $errors = [];

    foreach (['db_name', 'db_user', 'org_name', 'org_email', 'org_web', 'org_address', 'org_ico', 'org_context', 'admin_name', 'admin_email'] as $key) {
        if (preg_match('//u', (string) ($f[$key] ?? '')) !== 1) {
            return ['Formulář obsahuje neplatné znaky (očekává se kódování UTF-8). Obnovte stránku a zkuste to znovu.'];
        }
    }

    if (($state['mode'] ?? 'fresh') === 'fresh') {
        $errors = installer_validate_db($f);

        $base = $f['base_path'];
        if ($base === '' || $base[0] !== '/' || preg_match('#^/[A-Za-z0-9._~\-/]*$#', $base) !== 1 || str_contains($base, '//')) {
            $errors[] = 'Cesta aplikace musí začínat lomítkem a obsahovat jen písmena, číslice a znaky . _ - / (například /allstat).';
        }
    }

    if ($f['org_name'] === '' || installer_strlen($f['org_name']) > 190) {
        $errors[] = 'Vyplňte název organizace nebo firmy.';
    }
    if (!allstat_is_valid_email($f['org_email'])) {
        $errors[] = 'Zadejte platný kontaktní e-mail organizace.';
    }
    if (installer_normalize_web($f['org_web']) === null) {
        $errors[] = 'Zadejte adresu sledovaného webu, například https://www.example.cz.';
    }
    if (installer_strlen($f['org_address']) > 250 || installer_strlen($f['org_ico']) > 20 || installer_strlen($f['org_context']) > 4000) {
        $errors[] = 'Některé z volitelných údajů o organizaci jsou příliš dlouhé.';
    }
    if (!in_array($f['timezone'], DateTimeZone::listIdentifiers(DateTimeZone::ALL), true)) {
        $errors[] = 'Vyberte platné časové pásmo.';
    }

    if (trim($f['admin_name']) === '' || installer_strlen($f['admin_name']) > 120) {
        $errors[] = 'Vyplňte jméno administrátora.';
    }
    if (!allstat_is_valid_email($f['admin_email'])) {
        $errors[] = 'Zadejte platný e-mail administrátora (bude sloužit k přihlášení).';
    }
    if ($f['admin_password'] !== $f['admin_password2']) {
        $errors[] = 'Obě hesla administrátora se musí shodovat.';
    } elseif (($passwordError = allstat_validate_password($f['admin_password'])) !== null) {
        $errors[] = $passwordError;
    }

    if (!in_array($f['mcp_mode'], ['same', 'custom', 'later'], true)) {
        $errors[] = 'Vyberte, kde má běžet připojení AI.';
    } elseif ($f['mcp_mode'] === 'custom' && installer_normalize_mcp_url($f['mcp_host_url']) === null) {
        $errors[] = 'Adresu MCP serveru zadejte celou, včetně https://, bez lomítka na konci, například https://mcp.vase-firma.cz.';
    }

    return $errors;
}

// ---------------------------------------------------------------------------------------------
// Instalace
// ---------------------------------------------------------------------------------------------

function installer_build_config(array $f): array
{
    return [
        'app' => [
            'name' => 'AllStat',
            'base_path' => $f['base_path'],
            'timezone' => $f['timezone'],
        ],
        'security' => [
            'session_name' => 'allstat_admin_sid',
            'idle_timeout' => 7200,
        ],
        'db' => [
            'host' => $f['db_host'],
            'port' => (int) $f['db_port'],
            'database' => $f['db_name'],
            'username' => $f['db_user'],
            'password' => $f['db_pass'],
            'charset' => 'utf8mb4',
        ],
    ];
}

function installer_config_php(array $config, string $when): string
{
    return "<?php\n\n"
        . "/**\n"
        . " * AllStat: konfigurace. Vytvořil instalátor dne " . $when . ".\n"
        . " * Obsahuje přístup k databázi: nikdy ji nezveřejňujte a zálohujte ji spolu se souborem config-keys.php.\n"
        . " */\n\n"
        . 'return ' . var_export($config, true) . ";\n";
}

function installer_keys_php(string $key): string
{
    return "<?php\n\nreturn ['keys' => [\n    " . var_export($key, true) . ",\n]];\n";
}

/** Vrací zapsané klíče z config-keys.php (prázdné pole, když soubor chybí nebo nic neobsahuje). @return string[] */
function installer_read_keys(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    try {
        $store = (static fn() => require $path)();
    } catch (Throwable) {
        return [];
    }

    return (is_array($store) && !empty($store['keys']) && is_array($store['keys'])) ? array_values(array_map('strval', $store['keys'])) : [];
}

/** Zapíše soubor atomicky (dočasný soubor + přejmenování). */
function installer_write_file(string $path, string $content, int $mode): bool
{
    $tmp = $path . '.tmp' . bin2hex(random_bytes(3));

    if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
        @unlink($tmp);

        return false;
    }

    @chmod($tmp, $mode);

    if (!@rename($tmp, $path)) {
        @unlink($tmp);

        return false;
    }

    return true;
}

function installer_connect_or_create(array $config): PDO
{
    try {
        return installer_pdo($config['db']);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) !== 1049) {
            throw new InstallerError(installer_db_error_text($e));
        }
    }

    // Databáze neexistuje: pokus o její vytvoření (na sdíleném hostingu obvykle nepůjde, ale na vlastním serveru ano).
    try {
        $server = installer_pdo($config['db'], false);
        $server->exec('CREATE DATABASE `' . str_replace('`', '``', $config['db']['database']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci');

        return installer_pdo($config['db']);
    } catch (Throwable) {
        throw new InstallerError('Databáze „' . $config['db']['database'] . '“ neexistuje a uživatel nemá právo ji vytvořit. Vytvořte ji v administraci hostingu a spusťte instalaci znovu.');
    }
}

/**
 * Provede instalaci. Při chybě vyhodí InstallerError s hláškou pro uživatele.
 *
 * @return array<string, mixed> údaje pro závěrečnou stránku
 */
function installer_install(array $f, array $state, string $root): array
{
    @set_time_limit(180);

    $errors = installer_validate($f, $state);
    if ($errors !== []) {
        throw new InstallerError($errors);
    }

    $steps = [];
    $resume = ($state['mode'] ?? 'fresh') === 'resume';

    // 1) Připojení
    if ($resume) {
        $config = $state['config'];
        $pdo = $state['pdo'];
        $steps[] = 'Připojení k databázi z existujícího config.php.';
    } else {
        $config = installer_build_config($f);
        $pdo = installer_connect_or_create($config);
        $steps[] = 'Připojení k databázi „' . $f['db_name'] . '“.';
    }

    // 2) Kontrola databáze
    $inspect = installer_db_inspect($pdo, $root);
    if ($inspect['fatal']) {
        $messages = [];
        foreach ($inspect['checks'] as $check) {
            if ($check['status'] === 'fail') {
                $messages[] = $check['label'] . ': ' . $check['detail'];
            }
        }
        throw new InstallerError($messages);
    }

    // 3) Tabulky
    try {
        $schema = (string) file_get_contents($root . '/schema.sql');
        $schema = (string) preg_replace('/^\s*--.*$/m', '', $schema);

        // Některé tabulky ve schema.sql odkazují cizím klíčem na tabulku, která se vytváří až za nimi.
        // Po dobu vytváření proto kontrolu cizích klíčů vypneme (stejně jako mysqldump), pak ji zapneme.
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach (preg_split('/;\s*(?:\r?\n|$)/', $schema) ?: [] as $statement) {
                $statement = trim($statement);
                if ($statement !== '') {
                    $pdo->exec($statement);
                }
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        allstat_migrate($pdo);
    } catch (Throwable $e) {
        throw new InstallerError('Vytvoření tabulek selhalo: ' . trim((string) preg_replace('/\s+/', ' ', $e->getMessage())) . ' Instalaci můžete po opravě zopakovat, dokončí se tam, kde skončila.');
    }

    if (!allstat_schema_is_current($pdo)) {
        throw new InstallerError('Migrace databáze nedoběhla až do konce. Zopakujte instalaci, případně zkontrolujte chybový log hostingu.');
    }
    $tables = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn();
    $steps[] = 'Vytvořeno ' . $tables . ' databázových tabulek.';

    // 4) Nastavení, právní stránky, první web. Administrátor se zakládá AŽ NAKONEC: když cokoli dřív selže,
    //    v databázi nezůstane žádný uživatel a instalaci jde bez potíží zopakovat.
    try {
        [$webUrl, $webHost] = installer_normalize_web($f['org_web']);
        $today = (new DateTimeImmutable('now', new DateTimeZone($f['timezone'])))->format('j. n. Y');
        allstat_set_setting($pdo, 'app.public_base_url', $resume ? (string) ($config['app']['base_path'] ?? '/') : $f['base_path']);
        foreach (['app' => 'AllStat', 'org' => $f['org_name'], 'web' => $webUrl, 'email' => allstat_normalize_email($f['org_email']),
            'address' => $f['org_address'], 'ico' => $f['org_ico'], 'effective' => $today] as $key => $value) {
            allstat_set_setting($pdo, 'legal.' . $key, $value);
        }
        if ($f['org_context'] !== '') {
            allstat_set_setting($pdo, 'share.org_context', $f['org_context']);
        }

        $domain = allstat_save_domain($pdo, null, ['name' => (string) preg_replace('/^www\./', '', $webHost), 'url' => $webUrl, 'is_active' => 1]);
        $domainRow = allstat_fetch_one($pdo, 'SELECT id FROM domains ORDER BY id ASC LIMIT 1');
        if ($domainRow) {
            allstat_set_setting($pdo, 'app.primary_domain_id', (string) (int) $domainRow['id']);
        }
    } catch (Throwable $e) {
        throw new InstallerError('Uložení nastavení organizace selhalo: ' . trim((string) preg_replace('/\s+/', ' ', $e->getMessage())) . ' Instalaci můžete zopakovat.');
    }
    $steps[] = $domain['ok'] ? 'Přidán první sledovaný web ' . $webHost . '.' : 'První web ' . $webHost . ' už v databázi byl.';

    // 4b) Připojení AI (MCP): uloží se jen adresy, zapnutí zůstává na administrátorovi v menu AI konektory
    $effectiveBase = $resume ? (string) ($config['app']['base_path'] ?? '/') : $f['base_path'];
    $appUrl = installer_normalize_mcp_url(installer_app_url($effectiveBase));
    $mcp = ['mode' => $f['mcp_mode'], 'host' => '', 'app' => '', 'base_path' => $effectiveBase, 'root_block' => '', 'note' => ''];
    if ($f['mcp_mode'] === 'custom') {
        $mcp['host'] = (string) installer_normalize_mcp_url($f['mcp_host_url']);
    } elseif ($f['mcp_mode'] === 'same') {
        $mcp['host'] = (string) $appUrl;
        if ($appUrl === null) {
            $mcp['note'] = 'Adresa AllStatu zatím nepoužívá HTTPS, připojení AI nastavíte v menu AI konektory po zapnutí HTTPS.';
        }
    }
    if ($mcp['host'] !== '' && $appUrl !== null) {
        try {
            allstat_set_setting($pdo, 'mcp.host_url', $mcp['host']);
            allstat_set_setting($pdo, 'mcp.app_url', $appUrl);
            $mcp['app'] = $appUrl;
            if ($f['mcp_mode'] === 'same') {
                $mcp['root_block'] = installer_mcp_root_block($effectiveBase);
            }
            $steps[] = 'Uloženy adresy pro připojení AI (zapnete je v menu AI konektory).';
        } catch (Throwable $e) {
            $mcp['host'] = '';
            $mcp['note'] = 'Adresy pro připojení AI se nepodařilo uložit, nastavíte je v menu AI konektory.';
        }
    } elseif ($mcp['host'] !== '' && $appUrl === null) {
        $mcp['host'] = '';
        $mcp['note'] = $mcp['note'] !== '' ? $mcp['note'] : 'Adresu AllStatu se nepodařilo použít pro připojení AI, nastavíte ji v menu AI konektory.';
    }

    // 5) Soubory: klíč a konfigurace
    $keysPath = $root . '/config-keys.php';
    $configPath = $root . '/config.php';
    $when = (new DateTimeImmutable('now', new DateTimeZone($f['timezone'])))->format('j. n. Y H:i');

    $existingKeys = installer_read_keys($keysPath);
    $keepKeys = $existingKeys !== [] && $existingKeys[0] !== '' && $existingKeys[0] !== ALLSTAT_DEFAULT_ENCRYPTION_KEY;
    $keysContent = null;
    $keysState = 'kept';
    if (!$keepKeys) {
        $keysContent = installer_keys_php(bin2hex(random_bytes(32)));
        $keysState = installer_write_file($keysPath, $keysContent, 0640) ? 'written' : 'manual';
    }

    $configContent = null;
    $configState = 'kept';
    if (!$resume) {
        $configContent = installer_config_php($config, $when);
        $configState = installer_write_file($configPath, $configContent, 0640) ? 'written' : 'manual';
    }

    if ($keysState === 'written') {
        $steps[] = 'Vytvořen soubor config-keys.php s novým, jedinečným šifrovacím klíčem.';
    } elseif ($keysState === 'kept') {
        $steps[] = 'Ponechán stávající šifrovací klíč v config-keys.php.';
    }
    if ($configState === 'written') {
        $steps[] = 'Vytvořen soubor config.php.';
    }

    // 6) Ověření zapsaných souborů (jen když se zapsaly)
    if ($keysState !== 'manual' && $configState !== 'manual') {
        [$verify, $verifyError] = installer_load_config($configPath);
        if ($verify === null || !allstat_db($verify)) {
            throw new InstallerError('Soubor config.php se zapsal, ale nepodařilo se přes něj připojit k databázi: ' . ($verifyError ?? 'zkontrolujte přihlašovací údaje.'));
        }
        $probe = allstat_encrypt_secret('allstat-install-test', $verify);
        if (allstat_decrypt_secret($probe, $verify) !== 'allstat-install-test' || allstat_crypto_uses_default_key($verify)) {
            throw new InstallerError('Šifrovací klíč se nepodařilo ověřit. Zkontrolujte, že je soubor config-keys.php čitelný pro PHP.');
        }
        $steps[] = 'Ověřeno připojení a šifrování s vytvořenými soubory.';
    }

    $manual = [];
    if ($configState === 'manual' && $configContent !== null) {
        $manual['config.php'] = $configContent;
    }
    if ($keysState === 'manual' && $keysContent !== null) {
        $manual['config-keys.php'] = $keysContent;
    }

    // 7) Administrátor (poslední krok, viz výše)
    $created = allstat_create_user($pdo, null, [
        'email' => $f['admin_email'],
        'name' => $f['admin_name'],
        'role' => 'admin',
        'password' => $f['admin_password'],
        'is_active' => 1,
    ]);
    if (!$created['ok']) {
        throw new InstallerError('Administrátora se nepodařilo založit: ' . $created['message'] . ' Instalaci můžete zopakovat.');
    }
    $steps[] = 'Založen administrátor ' . allstat_normalize_email($f['admin_email']) . '.';

    // 8) Instalátor se po dokončení smaže (když to jde), jinak zůstane uzamčený
    $selfDelete = false;
    if ($manual === []) {
        $selfDelete = is_writable($root) && is_writable($root . '/lib');
        if ($selfDelete) {
            register_shutdown_function(static function () use ($root): void {
                if (@unlink($root . '/install.php')) {
                    @unlink($root . '/lib/install-wizard.php');
                }
            });
        }
    }

    return [
        'steps' => $steps,
        'manual' => $manual,
        'cron_secret' => (string) allstat_setting($pdo, 'sync.cron_secret', ''),
        'self_delete' => $selfDelete,
        'admin_email' => allstat_normalize_email($f['admin_email']),
        'root' => (string) (realpath($root) ?: $root),
        'base_path' => $effectiveBase,
        'mcp' => $mcp,
    ];
}

// ---------------------------------------------------------------------------------------------
// Zobrazení
// ---------------------------------------------------------------------------------------------

function installer_status_icon(string $status): string
{
    return match ($status) {
        'ok' => '<span class="pill pill-ok" aria-label="V pořádku">✓</span>',
        'warn' => '<span class="pill pill-warn" aria-label="Upozornění">!</span>',
        default => '<span class="pill pill-fail" aria-label="Chyba">✕</span>',
    };
}

function installer_render_checks(array $checks): void
{
    echo '<ul class="checks">';
    foreach ($checks as $check) {
        echo '<li>' . installer_status_icon($check['status'])
            . '<div><strong>' . h($check['label']) . '</strong>'
            . ($check['detail'] !== '' ? '<span>' . h($check['detail']) . '</span>' : '')
            . '</div></li>';
    }
    echo '</ul>';
}

/** České skloňování podle počtu: 1 kontrola, 2 až 4 kontroly, 5 a více kontrol. */
function installer_plural(int $n, string $one, string $few, string $many): string
{
    return $n === 1 ? $one : (($n >= 2 && $n <= 4) ? $few : $many);
}

/** Karta „Kontrola serveru“: když je vše v pořádku, stačí jedna věta, podrobnosti jdou rozbalit. */
function installer_render_environment(array $checks, bool $blocked): void
{
    $problems = array_values(array_filter($checks, static fn(array $c): bool => $c['status'] !== 'ok'));
    $total = count($checks);
    $okCount = $total - count($problems);

    echo '<div class="card"><h2>Kontrola serveru</h2>';
    if ($problems === []) {
        echo '<div class="notice notice-ok">Server splňuje všechny požadavky (' . $total . ' ' . installer_plural($total, 'kontrola', 'kontroly', 'kontrol') . ' v pořádku).</div>';
    } else {
        installer_render_checks($problems);
        if ($blocked) {
            echo '<div class="notice notice-error">Než budete pokračovat, opravte položky označené křížkem. Po úpravě tuto stránku obnovte.</div>';
        }
    }
    echo '<details><summary>' . ($problems === [] ? 'Zobrazit všechny kontroly' : 'Zobrazit i položky v pořádku (' . $okCount . ')') . '</summary><div class="inner">';
    installer_render_checks($problems === [] ? $checks : array_values(array_filter($checks, static fn(array $c): bool => $c['status'] === 'ok')));
    echo '</div></details></div>';
}

function installer_css(): string
{
    return <<<'CSS'
:root{color-scheme:light dark;--bg:#f6f8fb;--surface:#fff;--soft:#f8fafc;--text:#111827;--muted:#667085;--line:#e5e7eb;--line2:#d5dbe5;--blue:#2563eb;--blue-d:#1d4ed8;--ok:#15803d;--ok-bg:#ecfdf3;--warn:#a16207;--warn-bg:#fffbeb;--err:#b42318;--err-bg:#fef3f2;--shadow:0 18px 50px rgba(31,41,55,.08)}
@media (prefers-color-scheme:dark){:root{--bg:#10131a;--surface:#171b24;--soft:#1f2530;--text:#eef2f7;--muted:#a7b0c0;--line:#2b3240;--line2:#3a4354;--blue:#5b8def;--blue-d:#7aa2f7;--ok:#4ade80;--ok-bg:#12261a;--warn:#fbbf24;--warn-bg:#2a2210;--err:#f87171;--err-bg:#2c1615;--shadow:0 18px 48px rgba(0,0,0,.28)}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:16px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif}
.wrap{max-width:780px;margin:0 auto;padding:28px 16px 64px}
.top{display:flex;align-items:center;gap:12px;margin-bottom:20px}
.brand-mark{display:inline-flex;align-items:flex-end;gap:3px;width:28px;height:28px}
.brand-mark span{display:block;width:6px;border-radius:2px;background:#2563eb}
.brand-mark span:nth-child(1){height:13px;background:#60a5fa}.brand-mark span:nth-child(2){height:22px}.brand-mark span:nth-child(3){height:28px;background:#1d4ed8}
.top strong{font-size:20px}.top .ver{margin-left:auto;color:var(--muted);font-size:13px}
.card{background:var(--surface);border:1px solid var(--line);border-radius:12px;box-shadow:var(--shadow);padding:24px;margin-bottom:18px}
h1{font-size:26px;line-height:1.25;margin:0 0 8px}
h2{font-size:19px;margin:0 0 4px;display:flex;align-items:center;gap:10px}
h2 .num{display:inline-grid;place-items:center;width:28px;height:28px;border-radius:50%;background:var(--blue);color:#fff;font-size:14px;font-weight:700;flex:none}
p{margin:.4em 0}.lead{color:var(--muted)}.help{color:var(--muted);font-size:14px;margin:.2em 0 0}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 16px;margin-top:14px}.grid .full{grid-column:1/-1}
@media (max-width:600px){.grid{grid-template-columns:1fr}.card{padding:18px}}
label{display:block;font-weight:600;font-size:14px}
input[type=text],input[type=email],input[type=password],input[type=url],select,textarea{display:block;width:100%;margin-top:6px;padding:10px 12px;font:inherit;color:var(--text);background:var(--surface);border:1px solid var(--line2);border-radius:8px}
textarea{min-height:96px;resize:vertical;font-family:inherit}
input:focus,select:focus,textarea:focus{outline:2px solid var(--blue);outline-offset:1px;border-color:var(--blue)}
label .help{font-weight:400}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 20px;border-radius:8px;border:1px solid var(--blue);background:var(--blue);color:#fff;font:inherit;font-weight:600;cursor:pointer;text-decoration:none}
.btn:hover{background:var(--blue-d);border-color:var(--blue-d)}
.btn[disabled]{opacity:.5;cursor:not-allowed}
.btn.secondary{background:transparent;color:var(--blue)}.btn.secondary:hover{background:var(--soft)}
.btn.small{padding:7px 12px;font-size:14px}
.actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px;align-items:center}
.notice{margin:14px 0;padding:12px 14px;border-radius:8px;font-size:15px}
.notice ul{margin:6px 0 0;padding-left:20px}
.notice-ok{background:var(--ok-bg);color:var(--ok)}.notice-warn{background:var(--warn-bg);color:var(--warn)}.notice-error{background:var(--err-bg);color:var(--err)}
.checks{list-style:none;margin:12px 0 0;padding:0}
.checks li{display:flex;gap:12px;padding:10px 0;border-top:1px solid var(--line);align-items:flex-start}
.checks li:first-child{border-top:0}
.checks strong{display:block;font-size:15px}.checks span{display:block;color:var(--muted);font-size:14px}
.pill{flex:none;display:inline-grid;place-items:center;width:24px;height:24px;border-radius:50%;font-size:13px;font-weight:700;margin-top:1px}
.pill-ok{background:var(--ok-bg);color:var(--ok)}.pill-warn{background:var(--warn-bg);color:var(--warn)}.pill-fail{background:var(--err-bg);color:var(--err)}
details{margin-top:14px;border:1px solid var(--line);border-radius:8px;background:var(--soft)}
details summary{cursor:pointer;padding:10px 14px;font-weight:600;font-size:14px}
details .inner{padding:0 14px 14px}
.pw{display:flex;gap:8px;align-items:flex-end}.pw>label{flex:1}
code,.code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px}
.code{display:block;background:var(--soft);border:1px solid var(--line);border-radius:8px;padding:10px 12px;word-break:break-all;margin:8px 0 0;user-select:all}
ol.todo{padding-left:0;list-style:none;counter-reset:s;margin:14px 0 0}
ol.todo>li{counter-increment:s;position:relative;padding:12px 0 12px 44px;border-top:1px solid var(--line)}
ol.todo>li:first-child{border-top:0}
ol.todo>li::before{content:counter(s);position:absolute;left:0;top:12px;width:28px;height:28px;border-radius:50%;background:var(--soft);border:1px solid var(--line2);display:grid;place-items:center;font-weight:700;font-size:14px}
.steps{margin:10px 0 0;padding-left:20px;color:var(--muted);font-size:14px}
.choices{display:grid;gap:10px;margin-top:14px}
.choice{display:flex;gap:12px;align-items:flex-start;padding:14px;border:1px solid var(--line2);border-radius:10px;cursor:pointer;font-weight:400;font-size:16px;background:var(--surface)}
.choice:has(input:checked){border-color:var(--blue);background:var(--soft)}
.choice input[type=radio]{margin:5px 0 0;width:18px;height:18px;flex:none}
.choice strong{font-size:15px}
.choice .help{display:block}
.choice input[type=text]{margin-top:10px}
footer{color:var(--muted);font-size:13px;text-align:center;margin-top:24px}
CSS;
}

function installer_page_start(string $title, string $version): void
{
    echo '<!doctype html><html lang="cs"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow">'
        . '<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 28 28\'%3E%3Crect x=\'3\' y=\'15\' width=\'6\' height=\'13\' rx=\'2\' fill=\'%2360a5fa\'/%3E%3Crect x=\'11\' y=\'6\' width=\'6\' height=\'22\' rx=\'2\' fill=\'%232563eb\'/%3E%3Crect x=\'19\' y=\'0\' width=\'6\' height=\'28\' rx=\'2\' fill=\'%231d4ed8\'/%3E%3C/svg%3E">'
        . '<title>' . h($title) . '</title><style>' . installer_css() . '</style></head><body><div class="wrap">'
        . '<div class="top"><span class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></span>'
        . '<strong>AllStat</strong><span class="ver">Instalace' . ($version !== '' ? ' · verze ' . h($version) : '') . '</span></div>';
}

function installer_page_end(): void
{
    echo '<footer>AllStat · instalátor se po dokončení uzamkne</footer></div></body></html>';
}

function installer_page_message(string $title, string $bodyHtml, string $version): void
{
    installer_page_start($title, $version);
    echo '<div class="card"><h1>' . h($title) . '</h1>' . $bodyHtml . '</div>';
    installer_page_end();
}

function installer_render_form(array $ctx): void
{
    $f = $ctx['form'];
    $fresh = $ctx['state']['mode'] === 'fresh';
    $blocked = $ctx['blocked'];
    $nonce = installer_nonce();

    installer_page_start('AllStat: instalace', $ctx['version']);

    echo '<div class="card"><h1>Vítejte v instalaci</h1>'
        . '<p class="lead">Průvodce nastaví AllStat na vašem hostingu. Potřebujete prázdnou databázi MySQL (vytvoříte ji v administraci hostingu), '
        . 'adresu vašeho webu a asi pět minut času. Na jiné weby ani databáze na hostingu nijak nesáhne.</p></div>';

    if ($ctx['state']['mode'] === 'resume') {
        echo '<div class="notice notice-warn">Nalezen funkční config.php s připojením k databázi, ale instalace ještě není dokončená. Zbývá založit tabulky, organizaci a administrátora.</div>';
    }

    foreach ($ctx['errors'] as $error) {
        echo '<div class="notice notice-error">' . h($error) . '</div>';
    }

    // Kontrola serveru
    installer_render_environment($ctx['checks'], $blocked);

    echo '<form method="post" autocomplete="off" id="install-form">';
    echo '<input type="hidden" name="csrf" value="' . h(installer_csrf_token()) . '">';

    // 1) Databáze
    if ($fresh) {
        echo '<div class="card" id="db"><h2><span class="num">1</span> Databáze</h2>'
            . '<p class="help">V administraci hostingu vytvořte novou prázdnou databázi a uživatele s plným přístupem k ní '
            . '(v cPanelu: „MySQL® Databases“, jinde „Databáze“). Údaje sem opište přesně, včetně předpony před názvem (například <code>ucet_allstat</code>).</p>';

        if ($ctx['db_report'] !== null) {
            installer_render_checks($ctx['db_report']['checks']);
            echo $ctx['db_report']['fatal']
                ? '<div class="notice notice-error">Připojení má problém, opravte ho a test zopakujte.</div>'
                : '<div class="notice notice-ok">Databáze je připravená, můžete pokračovat.</div>';
        }

        echo '<div class="grid">'
            . '<label>Server databáze<input type="text" name="db_host" value="' . h($f['db_host']) . '" required><span class="help">Na většině hostingů „localhost“.</span></label>'
            . '<label>Port<input type="text" name="db_port" value="' . h($f['db_port']) . '" inputmode="numeric" required><span class="help">Obvykle 3306.</span></label>'
            . '<label>Název databáze<input type="text" name="db_name" value="' . h($f['db_name']) . '" required autocapitalize="off" spellcheck="false"></label>'
            . '<label>Uživatel databáze<input type="text" name="db_user" value="' . h($f['db_user']) . '" required autocapitalize="off" spellcheck="false"></label>'
            . '<label class="full">Heslo k databázi<input type="password" name="db_pass" value="' . h($f['db_pass']) . '" autocomplete="new-password"></label>'
            . '</div><div class="actions"><button class="btn secondary" type="submit" name="action" value="test_db" formaction="#db" formnovalidate' . ($blocked ? ' disabled' : '') . '>Otestovat připojení</button></div></div>';
    } else {
        echo '<div class="card"><h2><span class="num">1</span> Databáze</h2><p class="help">Použije se připojení z existujícího souboru config.php.</p></div>';
    }

    // 2) Organizace
    echo '<div class="card"><h2><span class="num">2</span> Organizace a web</h2>'
        . '<p class="help">Tyto údaje se zobrazí na veřejných stránkách o ochraně osobních údajů, které Google, Meta a LinkedIn vyžadují při napojení. Později je změníte v Nastavení.</p>'
        . '<div class="grid">'
        . '<label class="full">Název organizace nebo firmy<input type="text" name="org_name" value="' . h($f['org_name']) . '" required></label>'
        . '<label>Kontaktní e-mail<input type="email" name="org_email" value="' . h($f['org_email']) . '" required><span class="help">Na něj se budou obracet lidé s dotazy k osobním údajům.</span></label>'
        . '<label>Adresa sledovaného webu<input type="text" name="org_web" value="' . h($f['org_web']) . '" placeholder="https://www.example.cz" required><span class="help">První web, jehož statistiky chcete sledovat. Další přidáte později.</span></label>'
        . '</div>'
        . '<details><summary>Další údaje (nepovinné)</summary><div class="inner"><div class="grid">'
        . '<label>Sídlo organizace<input type="text" name="org_address" value="' . h($f['org_address']) . '"></label>'
        . '<label>IČO<input type="text" name="org_ico" value="' . h($f['org_ico']) . '"></label>'
        . '<label class="full">Popis organizace pro AI analýzy<textarea name="org_context" placeholder="Například: Jsme e-shop s outdoorovým vybavením, hlavním cílem je prodej, marketing dělají dva lidé.">' . h($f['org_context']) . '</textarea>'
        . '<span class="help">Když budete výsledky posílat AI asistentovi k rozboru, díky tomuto popisu ohodnotí data ve správném kontextu. Prázdné pole znamená obecný kontext.</span></label>'
        . '</div></div></details>';

    if ($fresh) {
        echo '<details><summary>Pokročilé (časové pásmo, cesta aplikace)</summary><div class="inner"><div class="grid">'
            . '<label>Časové pásmo<select name="timezone">';
        foreach (installer_timezones() as $group => $ids) {
            echo '<optgroup label="' . h($group) . '">';
            foreach ($ids as $id) {
                echo '<option value="' . h($id) . '"' . ($id === $f['timezone'] ? ' selected' : '') . '>' . h($id) . '</option>';
            }
            echo '</optgroup>';
        }
        echo '</select></label>'
            . '<label>Cesta aplikace na serveru<input type="text" name="base_path" value="' . h($f['base_path']) . '" spellcheck="false"><span class="help">Zjištěno automaticky. Měňte jen tehdy, když víte, že to váš hosting vyžaduje.</span></label>'
            . '</div></div></details>';
    } else {
        echo '<input type="hidden" name="timezone" value="' . h((string) ($ctx['state']['config']['app']['timezone'] ?? 'Europe/Prague')) . '">';
    }
    echo '</div>';

    // 3) Administrátor
    echo '<div class="card"><h2><span class="num">3</span> Administrátor</h2>'
        . '<p class="help">První účet s plnými právy. E-mail a heslo použijete k přihlášení do AllStatu.</p>'
        . '<div class="grid">'
        . '<label>Jméno<input type="text" name="admin_name" value="' . h($f['admin_name']) . '" autocomplete="name" required></label>'
        . '<label>E-mail (přihlašovací jméno)<input type="email" name="admin_email" value="' . h($f['admin_email']) . '" autocomplete="username" required></label>'
        . '<label>Heslo<input type="password" name="admin_password" id="pw1" minlength="12" autocomplete="new-password" required><span class="help">Nejméně 12 znaků.</span></label>'
        . '<label>Heslo znovu<input type="password" name="admin_password2" id="pw2" minlength="12" autocomplete="new-password" required></label>'
        . '</div><div class="actions"><button class="btn secondary small" type="button" id="pw-gen">Vygenerovat silné heslo</button>'
        . '<button class="btn secondary small" type="button" id="pw-show">Zobrazit hesla</button></div>'
        . '<p class="help" id="pw-note" hidden>Vygenerované heslo si hned uložte do správce hesel, po instalaci ho už nikde neuvidíte.</p></div>';

    // 4) Připojení AI (MCP)
    $detectedBase = $ctx['state']['mode'] === 'resume' ? (string) ($ctx['state']['config']['app']['base_path'] ?? '/') : installer_base_path();
    $atRoot = ($detectedBase === '/' || $detectedBase === '');
    $mode = $f['mcp_mode'];
    echo '<div class="card" id="mcp"><h2><span class="num">4</span> Připojení AI (Claude, ChatGPT)</h2>'
        . '<p class="help">AllStat umí zpřístupnit data asistentům Claude a ChatGPT, jen ke čtení. Přístup povoluje a kdykoli ruší administrátor. '
        . 'Zapnete ho po instalaci v menu „AI konektory“, tady jen určíte, na jaké adrese bude MCP server běžet.</p>'
        . '<div class="choices">'
        . '<label class="choice"><input type="radio" name="mcp_mode" value="same"' . ($mode === 'same' ? ' checked' : '') . '><span>'
        . '<strong>Na adrese AllStatu</strong> <code>' . h(installer_app_url($detectedBase)) . '</code>'
        . '<span class="help">' . ($atRoot
            ? 'AllStat běží v kořeni adresy (například na subdoméně), nic dalšího nastavovat nemusíte.'
            : 'AllStat běží ve složce domény. Po instalaci vám ukážeme krátké pravidlo pro soubor .htaccess v kořeni domény, bez něj by některé klienty nenašly přihlášení.')
        . '</span></span></label>'
        . '<label class="choice"><input type="radio" name="mcp_mode" value="custom"' . ($mode === 'custom' ? ' checked' : '') . '><span>'
        . '<strong>Na vlastní subdoméně</strong>'
        . '<span class="help">V hostingu vytvořte subdoménu a jako její složku nastavte složku, ve které je AllStat. Pravidlo v kořeni domény pak není potřeba.</span>'
        . '<input type="text" name="mcp_host_url" value="' . h($f['mcp_host_url']) . '" placeholder="https://mcp.vase-firma.cz" inputmode="url" autocapitalize="off" spellcheck="false">'
        . '</span></label>'
        . '<label class="choice"><input type="radio" name="mcp_mode" value="later"' . ($mode === 'later' ? ' checked' : '') . '><span>'
        . '<strong>Nastavit později</strong><span class="help">Adresy vyplníte sami v menu „AI konektory“.</span></span></label>'
        . '</div></div>';

    echo '<div class="card"><h2>Nainstalovat</h2>'
        . '<p class="help">Instalace založí tabulky v databázi, vytvoří soubory config.php a config-keys.php (s jedinečným šifrovacím klíčem) a připraví účet administrátora. Trvá několik sekund.</p>'
        . '<div class="actions"><button class="btn" type="submit" name="action" value="install" id="install-btn"' . ($blocked ? ' disabled' : '') . '>Nainstalovat AllStat</button></div></div>';
    echo '</form>';

    echo '<script nonce="' . h($nonce) . '">'
        . '(function(){var f=document.getElementById("install-form");if(!f)return;var p1=document.getElementById("pw1"),p2=document.getElementById("pw2");'
        . 'var g=document.getElementById("pw-gen"),s=document.getElementById("pw-show"),n=document.getElementById("pw-note");'
        . 'function gen(){var a="23456789ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz",o="",b=new Uint32Array(20);crypto.getRandomValues(b);for(var i=0;i<b.length;i++){o+=a.charAt(b[i]%a.length)}return o}'
        . 'if(g)g.addEventListener("click",function(){var v=gen();p1.value=v;p2.value=v;p1.type="text";p2.type="text";s.textContent="Skrýt hesla";n.hidden=false});'
        . 'if(s)s.addEventListener("click",function(){var t=p1.type==="password"?"text":"password";p1.type=t;p2.type=t;s.textContent=t==="text"?"Skrýt hesla":"Zobrazit hesla"});'
        . 'f.addEventListener("submit",function(e){var b=e.submitter;if(b&&b.id==="install-btn"){setTimeout(function(){b.disabled=true;b.textContent="Instaluji, vydržte prosím…"},0)}});'
        . '})();'
        . '</script>';

    installer_page_end();
}

function installer_render_success(array $r, string $version): void
{
    $nonce = installer_nonce();
    $loginUrl = installer_url('admin/login.php');
    $cronUrl = installer_url('cron/sync.php') . '?secret=' . rawurlencode($r['cron_secret']);
    $cliCommand = 'php ' . rtrim($r['root'], '/\\') . '/cron/sync.php ' . $r['cron_secret'];
    $manual = $r['manual'];

    installer_page_start('AllStat je nainstalovaný', $version);
    echo '<div class="card"><h1>' . ($manual === [] ? 'AllStat je nainstalovaný' : 'Skoro hotovo, zbývá nahrát soubory') . '</h1>';

    if ($manual === []) {
        echo '<p class="lead">Instalace proběhla. Přihlaste se a projděte čtyři kroky níže, aby AllStat fungoval dlouhodobě.</p>'
            . '<div class="actions"><a class="btn" href="' . h($loginUrl) . '">Přihlásit se do AllStatu</a></div>';
    } else {
        echo '<div class="notice notice-warn">Server nedovolil instalátoru zapsat soubory do složky aplikace. Databáze je připravená, ale aplikace se spustí až po nahrání souborů níže. '
            . '<strong>Tuto stránku nezavírejte ani neobnovujte</strong>, klíč z ní už podruhé neuvidíte.</div>';
        foreach ($manual as $name => $content) {
            $id = 'file-' . preg_replace('/[^a-z0-9]/', '', strtolower($name));
            echo '<h2 style="margin-top:18px">' . h($name) . '</h2>'
                . '<p class="help">Uložte jako <code>' . h($name) . '</code> do složky, kde leží soubor <code>install.php</code> (přes FTP nebo správce souborů).</p>'
                . '<textarea readonly rows="12" class="code" id="' . h($id) . '" style="width:100%;user-select:all">' . h($content) . '</textarea>'
                . '<div class="actions"><a class="btn secondary small" download="' . h($name) . '" href="data:text/plain;charset=utf-8;base64,' . base64_encode($content) . '">Stáhnout ' . h($name) . '</a></div>';
        }
        echo '<div class="actions"><a class="btn" href="' . h($loginUrl) . '">Po nahrání souborů přejít na přihlášení</a></div>';
    }
    echo '</div>';

    echo '<div class="card"><h2>Co udělat hned</h2><ol class="todo">'
        . '<li><strong>Zálohujte dva soubory.</strong> Stáhněte si z hostingu <code>config.php</code> a <code>config-keys.php</code> a uložte je na bezpečné místo mimo web (správce hesel, zabezpečené úložiště). '
        . 'V <code>config-keys.php</code> je klíč, kterým jsou zašifrované přístupy k vašim službám. Bez něj by se napojení musela vytvořit znovu.</li>'
        . '<li><strong>Nastavte automatické stahování dat.</strong> V administraci hostingu otevřete „Cron“ (plánovač úloh na serveru) a přidejte úlohu spouštěnou jednou denně (například v 6:00) s tímto příkazem:'
        . '<code class="code">curl -s "' . h($cronUrl) . '" &gt; /dev/null 2&gt;&amp;1</code>'
        . '<p class="help">Adresa obsahuje tajný klíč, nikomu ji neposílejte. Když hosting umí jen „zavolat URL“, použijte samotnou adresu:</p>'
        . '<code class="code">' . h($cronUrl) . '</code>'
        . '<p class="help">Alternativa přes příkazový řádek PHP (spolehlivější u dlouhých stahování):</p>'
        . '<code class="code">' . h($cliCommand) . '</code></li>'
        . '<li><strong>Zapněte dvoufázové ověření.</strong> Po přihlášení otevřete v menu „2FA“ a spárujte účet s aplikací typu Google Authenticator. Doporučujeme to všem administrátorům.</li>'
        . '<li><strong>Napojte první zdroj dat.</strong> V menu otevřete „Zdroje dat“, vyberte službu (například Google Analytics 4) a postupujte podle návodu přímo v aplikaci.</li>'
        . '</ol></div>';

    // Připojení AI (MCP): co ještě zbývá udělat podle zvolené varianty
    $m = $r['mcp'];
    if ($m['mode'] !== 'later') {
        echo '<div class="card"><h2>Připojení AI: další krok</h2>';
        if ($m['note'] !== '') {
            echo '<div class="notice notice-warn">' . h($m['note']) . '</div>';
        }
        if ($m['host'] !== '') {
            echo '<p>Adresa MCP serveru, kterou zadáte v Claude nebo ChatGPT: <code>' . h($m['host'] . '/mcp') . '</code></p>';
            if ($m['root_block'] !== '') {
                echo '<p>AllStat běží ve složce domény. Aby klienti našli přihlášení, vložte tento blok <strong>na začátek souboru <code>.htaccess</code> v kořeni domény</strong> '
                    . '(obvykle složka <code>public_html</code>, nad případná pravidla WordPressu). Když soubor neexistuje, vytvořte ho. Blok nic jiného na webu nemění.</p>'
                    . '<code class="code" style="white-space:pre-wrap;user-select:all">' . h($m['root_block']) . '</code>'
                    . '<p class="help">Bez bloku klienti, kteří hledají přihlášení podle standardu RFC 8414, spojení nenavážou. Kontrola v menu „AI konektory“ ho po vložení pozná. '
                    . 'Alternativou je vlastní subdoména, která ukazuje do stejné složky jako AllStat, pak blok nepotřebujete.</p>';
            } elseif ($m['mode'] === 'custom') {
                $host = (string) parse_url($m['host'], PHP_URL_HOST);
                echo '<p>V hostingu vytvořte subdoménu <code>' . h($host) . '</code> a jako její složku (document root) nastavte <code>' . h($r['root']) . '</code>, tedy tu, ve které je AllStat. '
                    . 'Žádné další pravidlo není potřeba.</p>';
            } else {
                echo '<p>AllStat běží v kořeni adresy, žádné další pravidlo není potřeba.</p>';
            }
            echo '<p class="help">Potom otevřete v menu „AI konektory“, spusťte kontrolu, zapněte připojení a postupujte podle návodu pro Claude nebo ChatGPT.</p>';
        }
        echo '</div>';
    }

    echo '<div class="card"><h2>Co se provedlo</h2><ul class="steps">';
    foreach ($r['steps'] as $step) {
        echo '<li>' . h($step) . '</li>';
    }
    echo '</ul>';
    if ($manual === []) {
        echo '<p class="help" style="margin-top:12px">' . ($r['self_delete']
            ? 'Instalátor (install.php) se po zobrazení této stránky sám odstraní. Kdyby na serveru zůstal, je uzamčený a můžete ho smazat ručně.'
            : 'Instalátor je uzamčený a nic už nezmění. Doporučujeme ho ze serveru smazat: soubory install.php a lib/install-wizard.php.') . '</p>';
    }
    echo '</div>';

    installer_page_end();
}

// ---------------------------------------------------------------------------------------------
// Hlavní tok
// ---------------------------------------------------------------------------------------------

$installVersion = installer_version($installRoot);
$state = installer_detect_state($installRoot);
installer_csrf_token(); // nastaví cookie před jakýmkoli výstupem
installer_send_headers();

if ($state['mode'] === 'locked') {
    http_response_code(410);
    installer_page_message(
        'AllStat je již nainstalovaný',
        '<p class="lead">Instalátor je z bezpečnostních důvodů uzamčený, protože v databázi už existuje uživatel.</p>'
        . '<div class="actions"><a class="btn" href="' . h(installer_url('admin/login.php')) . '">Přejít na přihlášení</a></div>'
        . '<p class="help" style="margin-top:14px">Soubory install.php a lib/install-wizard.php můžete na serveru smazat.</p>',
        $installVersion
    );
    exit;
}

if ($state['mode'] === 'broken') {
    installer_page_message(
        'Konfigurace vyžaduje pozornost',
        '<div class="notice notice-error">' . h((string) $state['message']) . '</div>'
        . '<p>Máte dvě možnosti:</p><ol>'
        . '<li>Opravte údaje v souboru <code>config.php</code> (přístup k databázi) a stránku obnovte.</li>'
        . '<li>Nebo soubor <code>config.php</code> smažte a instalátor spusťte znovu od začátku.</li></ol>',
        $installVersion
    );
    exit;
}

$form = installer_form();
$errors = [];
$dbReport = null;
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!installer_csrf_valid()) {
        $errors[] = 'Odeslání formuláře se nepodařilo ověřit. Zkontrolujte, že má prohlížeč povolené cookies, a zkuste to znovu.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'test_db' && $state['mode'] === 'fresh') {
            $problems = installer_validate_db($form);
            if ($problems !== []) {
                $errors = array_merge($errors, $problems);
            } else {
                try {
                    $pdo = installer_pdo(installer_build_config($form)['db']);
                    $dbReport = installer_db_inspect($pdo, $installRoot);
                } catch (Throwable $e) {
                    $dbReport = ['fatal' => true, 'checks' => [[
                        'status' => $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1049 ? 'warn' : 'fail',
                        'label' => 'Připojení k databázi',
                        'detail' => installer_db_error_text($e) . ($e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1049 ? ' Při instalaci se ji instalátor pokusí vytvořit.' : ''),
                    ]]];
                    if ($e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1049) {
                        $dbReport['fatal'] = false;
                    }
                }
            }
        } elseif ($action === 'install') {
            try {
                $result = installer_install($form, $state, $installRoot);
            } catch (InstallerError $e) {
                $errors = array_merge($errors, $e->messages());
            } catch (Throwable $e) {
                $errors[] = 'Neočekávaná chyba: ' . trim((string) preg_replace('/\s+/', ' ', $e->getMessage())) . ' Podrobnosti najdete v chybovém logu hostingu.';
            }
        }
    }
}

if ($result !== null) {
    installer_render_success($result, $installVersion);
    exit;
}

$checks = installer_environment_checks($installRoot);
installer_render_form([
    'state' => $state,
    'form' => $form,
    'errors' => $errors,
    'checks' => $checks,
    'blocked' => installer_has_failure($checks),
    'db_report' => $dbReport,
    'version' => $installVersion,
]);
