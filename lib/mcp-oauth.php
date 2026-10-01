<?php

/**
 * AllStat MCP: vlastní OAuth 2.1 autorizační server a ověřování Bearer tokenů pro MCP endpoint.
 *
 * Model: veřejní klienti (bez tajemství), povinné PKCE S256, jediný scope `allstat.read`, tokeny se ukládají
 * jen jako SHA-256 hash. Registrace klienta přes DCR (POST /register) nebo přes Client ID Metadata Document
 * (CIMD, jen pro hostitele claude.ai a chatgpt.com). Souhlas uděluje jen aktivní administrátor AllStatu
 * přes {A}/oauth/authorize.php (přihlášení + 2FA řeší stávající session AllStatu).
 *
 * Dvě základní adresy (nastavení v allstat_settings, bez koncového lomítka, nikdy z hlavičky Host):
 *   {H} = mcp.host_url  - MCP host (issuer, /mcp, /token, /register, /revoke, /.well-known/*)
 *   {A} = mcp.app_url   - aplikace AllStat (autorizační endpoint a administrace)
 *
 * Kontrakt pro ostatní části (názvy jsou závazné): allstat_mcp_host_url / _app_url / _is_enabled / _resource_url,
 * allstat_mcp_authenticate, allstat_mcp_send_auth_error, allstat_mcp_rate_hit, allstat_mcp_client_ip,
 * allstat_mcp_allowed_origins, allstat_mcp_origin_allowed, allstat_mcp_log, allstat_mcp_recent_log,
 * allstat_mcp_connections, allstat_mcp_revoke_grant / _revoke_user / _revoke_all, allstat_mcp_purge,
 * allstat_mcp_json_response. Vše ostatní má prefix allstat_mcp_oauth_ (interní).
 *
 * Časy v tabulkách allstat_mcp_* jsou DATETIME v časovém pásmu aplikace (Europe/Prague), počítané v PHP
 * (nikdy NOW() z databáze), stejně jako u share_links.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';

// ---------------------------------------------------------------------------------------------------------------
// Základní pomocné funkce
// ---------------------------------------------------------------------------------------------------------------

function allstat_mcp_oauth_scope(): string
{
    return 'allstat.read';
}

/** Aktuální čas jako DATETIME řetězec v pásmu aplikace (volitelně posunutý o sekundy). */
function allstat_mcp_oauth_now(int $offsetSeconds = 0): string
{
    return date('Y-m-d H:i:s', time() + $offsetSeconds);
}

function allstat_mcp_oauth_b64url(string $binary): string
{
    return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
}

function allstat_mcp_oauth_b64url_decode(string $text): ?string
{
    if ($text === '' || preg_match('~[^A-Za-z0-9_-]~', $text)) {
        return null;
    }

    $mod = strlen($text) % 4;
    if ($mod === 1) {
        return null;
    }

    $decoded = base64_decode(strtr($text, '-_', '+/') . str_repeat('=', $mod === 0 ? 0 : 4 - $mod), true);

    return $decoded === false ? null : $decoded;
}

function allstat_mcp_oauth_hash(string $secret): string
{
    return hash('sha256', $secret);
}

/** Náhodný token: prefix + base64url(32 náhodných bajtů). V databázi je jen jeho SHA-256. */
function allstat_mcp_oauth_random_token(string $prefix): string
{
    return $prefix . allstat_mcp_oauth_b64url(random_bytes(32));
}

/** Text od klienta (název aplikace apod.): bez značek, řídicích a formátovacích znaků, oříznutý. */
function allstat_mcp_oauth_clean_text(mixed $value, int $max = 120): string
{
    if (!is_string($value)) {
        return '';
    }

    $value = strip_tags($value);
    $value = (string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $value);
    $value = trim((string) preg_replace('/\s+/u', ' ', $value));

    return mb_substr($value, 0, $max);
}

/** Řetězcový parametr z pole parametrů; pole, jiné typy a příliš dlouhé hodnoty se berou jako chybějící. */
function allstat_mcp_oauth_str(array $params, string $key, int $max = 2048): string
{
    $value = $params[$key] ?? null;
    if (!is_string($value)) {
        return '';
    }

    $value = trim($value);

    return strlen($value) > $max ? '' : $value;
}

/** Odstraní z textu vše, co vypadá jako token nebo Bearer hlavička (do logu a chybových hlášek). */
function allstat_mcp_oauth_redact(string $text): string
{
    $text = preg_replace('~\b(?:asa|asr|asc)_[A-Za-z0-9_-]{16,}~', '[skryto]', $text) ?? $text;
    $text = preg_replace('#\bBearer\s+[A-Za-z0-9._~+/=-]{8,}#i', 'Bearer [skryto]', $text) ?? $text;
    $text = preg_replace('~[A-Za-z0-9_-]{40,}~', '[skryto]', $text) ?? $text;

    return $text;
}

/** ASCII verze textu pro hodnoty hlaviček (WWW-Authenticate připouští jen ASCII). */
function allstat_mcp_oauth_ascii(string $text): string
{
    $text = strtr($text, [
        'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r',
        'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z',
        'Á' => 'A', 'Č' => 'C', 'Ď' => 'D', 'É' => 'E', 'Ě' => 'E', 'Í' => 'I', 'Ň' => 'N', 'Ó' => 'O', 'Ř' => 'R',
        'Š' => 'S', 'Ť' => 'T', 'Ú' => 'U', 'Ů' => 'U', 'Ý' => 'Y', 'Ž' => 'Z',
    ]);
    $text = (string) preg_replace('/[^\x20-\x7E]/', '', $text);

    return trim(str_replace(['"', '\\'], ["'", '/'], $text));
}

// ---------------------------------------------------------------------------------------------------------------
// Nastavení a adresy
// ---------------------------------------------------------------------------------------------------------------

/** Více nastavení naráz (1 dotaz). Chybějící klíče mají hodnotu ''. */
function allstat_mcp_oauth_settings(PDO $pdo, array $keys): array
{
    $out = array_fill_keys($keys, '');
    if (!$keys) {
        return $out;
    }

    try {
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $rows = allstat_fetch_all($pdo, 'SELECT setting_key, setting_value FROM allstat_settings WHERE setting_key IN (' . $placeholders . ')', array_values($keys));
        foreach ($rows as $row) {
            $out[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }
    } catch (Throwable) {
        // nastavení nejsou k dispozici: bere se jako nenastaveno
    }

    return $out;
}

function allstat_mcp_oauth_setting(PDO $pdo, string $key, string $default = ''): string
{
    $value = allstat_mcp_oauth_settings($pdo, [$key])[$key] ?? '';

    return $value === '' ? $default : $value;
}

/** TTL v sekundách z nastavení, oříznuté na rozumný rozsah. */
function allstat_mcp_oauth_ttl(PDO $pdo, string $key, int $default, int $min, int $max): int
{
    $raw = allstat_mcp_oauth_setting($pdo, $key, '');
    $value = ctype_digit($raw) ? (int) $raw : $default;

    return max($min, min($max, $value));
}

/**
 * Host, který smí skončit v CSP (form-action) a ve srovnávání původů: jen [a-z0-9.-] nebo IPv6 literál v hranatých
 * závorkách. Žádný středník, čárka, mezera ani uvozovka, kterými by šla do hlavičky vložit další direktiva.
 */
function allstat_mcp_oauth_host_is_safe(string $host): bool
{
    $host = strtolower($host);

    return preg_match('#^[a-z0-9.-]{1,253}$#D', $host) === 1
        || preg_match('#^\[[0-9a-f:.]{2,45}\]$#D', $host) === 1;
}

/**
 * Základní URL ve tvaru scheme://host[:port][/cesta] bez koncového lomítka, bez výchozího portu.
 * Vrací '' pro neplatnou hodnotu (jiné schéma než http/https, dotaz, fragment, přihlašovací údaje, mezery, nebezpečný host).
 */
function allstat_mcp_oauth_normalize_base_url(string $url): string
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 300 || preg_match('~[^\x21-\x7E]|[?#]~', $url)) {
        return '';
    }

    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }

    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        return '';
    }

    $scheme = strtolower($parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return '';
    }

    if (!allstat_mcp_oauth_host_is_safe($parts['host'])) {
        return '';
    }

    $port = isset($parts['port']) ? (int) $parts['port'] : null;
    if ($port !== null && ($port < 1 || $port > 65535)) {
        return '';
    }

    if ($port !== null && (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
        $port = null;
    }

    $path = rtrim((string) ($parts['path'] ?? ''), '/');

    return $scheme . '://' . strtolower($parts['host']) . ($port !== null ? ':' . $port : '') . $path;
}

/** {H}: adresa MCP hostu ('' když není nastavena). */
function allstat_mcp_host_url(PDO $pdo): string
{
    return allstat_mcp_oauth_normalize_base_url(allstat_mcp_oauth_setting($pdo, 'mcp.host_url'));
}

/** {A}: adresa aplikace AllStat ('' když není nastavena). */
function allstat_mcp_app_url(PDO $pdo): string
{
    return allstat_mcp_oauth_normalize_base_url(allstat_mcp_oauth_setting($pdo, 'mcp.app_url'));
}

/**
 * Jsou tabulky allstat_mcp_* k dispozici? Ověří se jednou za proces (kladný výsledek se drží). Když migrace tabulek
 * selhala nebo se ještě neprovedla, MCP se nesmí tvářit jako zapnuté.
 */
function allstat_mcp_oauth_tables_ready(PDO $pdo): bool
{
    static $ready = [];

    $key = spl_object_id($pdo);
    if (!empty($ready[$key])) {
        return true;
    }

    try {
        foreach (['allstat_mcp_clients', 'allstat_mcp_grants', 'allstat_mcp_tokens', 'allstat_mcp_log', 'allstat_mcp_ratelimit'] as $table) {
            $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1')->closeCursor();
        }
    } catch (Throwable) {
        return false;
    }

    $ready[$key] = true;

    return true;
}

/**
 * MCP je zapnuté jen když mcp.enabled = '1', jsou nastavené obě adresy, migrace tabulek MCP doběhla
 * (marker mcp.schema.version) a tabulky opravdu existují.
 */
function allstat_mcp_is_enabled(PDO $pdo): bool
{
    $settings = allstat_mcp_oauth_settings($pdo, ['mcp.enabled', 'mcp.host_url', 'mcp.app_url', 'mcp.schema.version']);

    return $settings['mcp.enabled'] === '1'
        && $settings['mcp.schema.version'] !== ''
        && allstat_mcp_oauth_normalize_base_url($settings['mcp.host_url']) !== ''
        && allstat_mcp_oauth_normalize_base_url($settings['mcp.app_url']) !== ''
        && allstat_mcp_oauth_tables_ready($pdo);
}

/** Identifikátor chráněného prostředku (RFC 8707): {H}/mcp ('' když {H} není nastavena). */
function allstat_mcp_resource_url(PDO $pdo): string
{
    $host = allstat_mcp_host_url($pdo);

    return $host === '' ? '' : $host . '/mcp';
}

/** Cesta v adrese {H} (např. '/allstat-mcp' u varianty s prefixem, jinak ''). */
function allstat_mcp_oauth_host_base_path(PDO $pdo): string
{
    $host = allstat_mcp_host_url($pdo);

    return $host === '' ? '' : (string) parse_url($host, PHP_URL_PATH);
}

/**
 * Kanonický tvar URL pro porovnání zdrojů: malá písmena ve schématu a hostu, bez výchozího portu, bez koncového
 * lomítka. Vrací '' pro neplatné URL (fragment, přihlašovací údaje, bílé znaky, jiné schéma).
 */
function allstat_mcp_oauth_canonical_url(string $url): string
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 2048 || preg_match('~[^\x21-\x7E]|#~', $url)) {
        return '';
    }

    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        return '';
    }

    $scheme = strtolower($parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return '';
    }

    $port = isset($parts['port']) ? (int) $parts['port'] : null;
    if ($port !== null && (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
        $port = null;
    }

    return $scheme . '://' . strtolower($parts['host']) . ($port !== null ? ':' . $port : '')
        . rtrim((string) ($parts['path'] ?? ''), '/')
        . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');
}

/** Odpovídá zadaný `resource` našemu MCP endpointu? */
function allstat_mcp_oauth_resource_matches(PDO $pdo, string $resource): bool
{
    $expected = allstat_mcp_oauth_canonical_url(allstat_mcp_resource_url($pdo));
    $given = allstat_mcp_oauth_canonical_url($resource);

    return $expected !== '' && $given !== '' && hash_equals($expected, $given);
}

/**
 * scheme://host[:port] z libovolné URL ('' když nejde o http/https URL). Host se ověřuje přísně (viz host_is_safe),
 * protože výsledek se zapisuje do CSP form-action; port musí být číslo 1 až 65535. Jinak se vrací ''.
 */
function allstat_mcp_oauth_origin_of(string $url): string
{
    $parts = parse_url(trim($url));
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }

    $scheme = strtolower($parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return '';
    }

    $host = strtolower($parts['host']);
    if (!allstat_mcp_oauth_host_is_safe($host)) {
        return '';
    }

    $port = isset($parts['port']) ? (int) $parts['port'] : null;
    if ($port !== null && ($port < 1 || $port > 65535)) {
        return '';
    }

    if ($port !== null && (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
        $port = null;
    }

    return $scheme . '://' . $host . ($port !== null ? ':' . $port : '');
}

function allstat_mcp_oauth_is_loopback_host(string $host): bool
{
    return in_array(strtolower($host), ['localhost', '127.0.0.1', '[::1]'], true);
}

/** Povolené původy (Origin) pro MCP endpoint: {H}, {A} a klienti Claude / ChatGPT. Loopback řeší origin_allowed. */
function allstat_mcp_allowed_origins(PDO $pdo): array
{
    $list = [];

    foreach ([allstat_mcp_host_url($pdo), allstat_mcp_app_url($pdo)] as $url) {
        $origin = $url !== '' ? allstat_mcp_oauth_origin_of($url) : '';
        if ($origin !== '') {
            $list[] = $origin;
        }
    }

    foreach (['https://claude.ai', 'https://claude.com', 'https://chatgpt.com', 'https://chat.openai.com', 'https://platform.openai.com'] as $origin) {
        $list[] = $origin;
    }

    return array_values(array_unique($list));
}

function allstat_mcp_origin_allowed(PDO $pdo, string $origin): bool
{
    $origin = trim($origin);
    if ($origin === '' || strtolower($origin) === 'null' || preg_match('~[^\x21-\x7E]~', $origin)) {
        return false;
    }

    $parts = parse_url($origin);
    if (!is_array($parts) || isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
        return false;
    }

    $normalized = allstat_mcp_oauth_origin_of($origin);
    if ($normalized === '') {
        return false;
    }

    if (in_array($normalized, allstat_mcp_allowed_origins($pdo), true)) {
        return true;
    }

    return allstat_mcp_oauth_is_loopback_host((string) parse_url($normalized, PHP_URL_HOST));
}

// ---------------------------------------------------------------------------------------------------------------
// HTTP odpovědi
// ---------------------------------------------------------------------------------------------------------------

/**
 * JSON odpověď a konec skriptu. $headers: ['Název' => 'hodnota'] nebo celé řádky hlaviček. Prázdné pole se odešle
 * jako {}. Pro HEAD se pošlou jen hlavičky.
 */
function allstat_mcp_json_response(int $status, array $body, array $headers = []): never
{
    $json = json_encode($body === [] ? new stdClass() : $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $status = 500;
        $json = '{"error":"server_error"}';
    }

    $isHead = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD';

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        foreach ($headers as $name => $value) {
            header(is_int($name) ? (string) $value : $name . ': ' . $value);
        }

        if ($isHead) {
            header('Content-Length: ' . strlen($json));
        }

        // Stavový kód až po všech hlavičkách: header('WWW-Authenticate: ...') by ho jinak vrátil na 401 (i u 403).
        http_response_code($status);
    }

    if (!$isHead) {
        echo $json;
    }

    exit;
}

/** Odpověď OAuth koncových bodů: JSON bez cache a s CORS pro všechny původy. */
function allstat_mcp_oauth_reply(int $status, array $body, array $headers = []): never
{
    allstat_mcp_json_response($status, $body, $headers + [
        'Cache-Control' => 'no-store',
        'Pragma' => 'no-cache',
        'Access-Control-Allow-Origin' => '*',
    ]);
}

/** Chyba podle RFC 6749 §5.2 jako pole [status, body] (pro jednotkově testovatelné jádro). */
function allstat_mcp_oauth_err(string $error, string $description, int $status = 400): array
{
    return ['status' => $status, 'body' => ['error' => $error, 'error_description' => $description]];
}

/** CORS preflight pro API koncové body (204). */
function allstat_mcp_oauth_preflight(array $methods): never
{
    if (!headers_sent()) {
        http_response_code(204);
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: ' . implode(', ', $methods));
        header('Access-Control-Allow-Headers: Authorization, Content-Type, MCP-Protocol-Version, Mcp-Method, Mcp-Name');
        header('Access-Control-Max-Age: 600');
    }

    exit;
}

/** Povolí jen dané metody (OPTIONS vždy jako preflight); jinak 405 s hlavičkou Allow. */
function allstat_mcp_oauth_require_method(array $allowed): string
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'OPTIONS') {
        allstat_mcp_oauth_preflight(array_values(array_unique(array_merge($allowed, ['OPTIONS']))));
    }

    if (!in_array($method, $allowed, true)) {
        allstat_mcp_oauth_reply(405, ['error' => 'method_not_allowed', 'error_description' => 'Tato metoda tady není povolená.'], [
            'Allow' => implode(', ', array_values(array_unique(array_merge($allowed, ['OPTIONS'])))),
        ]);
    }

    return $method;
}

/** Tělo požadavku (max. $maxBytes) nebo null, když je větší. */
function allstat_mcp_oauth_read_body(int $maxBytes = 65536): ?string
{
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false) {
        $raw = '';
    }

    return strlen($raw) > $maxBytes ? null : $raw;
}

/** Parametry požadavku: JSON, jinak x-www-form-urlencoded (z těla), jinak $_POST. Null = tělo je příliš velké. */
function allstat_mcp_oauth_request_params(): ?array
{
    $raw = allstat_mcp_oauth_read_body();
    if ($raw === null) {
        return null;
    }

    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    $params = [];

    if (str_contains($contentType, 'json')) {
        $decoded = json_decode($raw, true, 16);
        $params = is_array($decoded) ? $decoded : [];
    } elseif ($raw !== '') {
        parse_str($raw, $params);
    }

    if (!$params && !empty($_POST) && is_array($_POST)) {
        $params = $_POST;
    }

    return is_array($params) ? $params : [];
}

/** Přidá parametry k redirect URI (zachová existující dotaz), prázdné hodnoty vynechá. */
function allstat_mcp_oauth_add_query(string $uri, array $params): string
{
    $params = array_filter($params, static fn (mixed $value): bool => $value !== null && $value !== '');
    if (!$params) {
        return $uri;
    }

    return $uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

// ---------------------------------------------------------------------------------------------------------------
// Omezení počtu požadavků
// ---------------------------------------------------------------------------------------------------------------

/** Přesná adresa klienta (REMOTE_ADDR) pro audit; do omezení počtu požadavků se používá allstat_mcp_client_ip(). */
function allstat_mcp_client_ip_full(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    return $ip !== '' ? substr($ip, 0, 45) : '0.0.0.0';
}

/**
 * Klíč pro omezení počtu požadavků z dané adresy: IPv4 beze změny, IPv6 jako prefix /64 (např. 2001:db8:1:2::/64),
 * aby limity nešly obejít střídáním adres z jednoho bloku (běžné přidělení je celá /64). IPv4 zapsaná jako
 * ::ffff:a.b.c.d se bere jako IPv4. Hodnota, která už je klíčem (nebo není adresa), se vrací beze změny.
 */
function allstat_mcp_ip_bucket(string $ip): string
{
    $binary = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? @inet_pton($ip) : false;

    if ($binary === false || strlen($binary) !== 16) {
        return $ip;
    }

    if (substr($binary, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
        $mapped = inet_ntop(substr($binary, 12));

        return $mapped === false ? $ip : $mapped;
    }

    $prefix = inet_ntop(substr($binary, 0, 8) . str_repeat("\0", 8));

    return $prefix === false ? $ip : $prefix . '/64';
}

/** Klíč aktuálního klienta pro omezení počtu požadavků (viz allstat_mcp_ip_bucket()). */
function allstat_mcp_client_ip(): string
{
    return allstat_mcp_ip_bucket(allstat_mcp_client_ip_full());
}

function allstat_mcp_oauth_bucket(string $bucket): string
{
    return substr((string) preg_replace('/[^A-Za-z0-9:._-]/', '_', $bucket), 0, 190);
}

/** Jen čtení: je počet v aktuálním okně už na limitu (nebo nad ním)? Při chybě databáze neblokuje. */
function allstat_mcp_oauth_rate_blocked(PDO $pdo, string $bucket, int $limit, int $windowSec): bool
{
    $windowSec = max(1, $windowSec);

    try {
        $statement = $pdo->prepare('SELECT hits FROM allstat_mcp_ratelimit WHERE bucket = ? AND window_start = ?');
        $statement->execute([allstat_mcp_oauth_bucket($bucket), intdiv(time(), $windowSec) * $windowSec]);

        return (int) $statement->fetchColumn() >= $limit;
    } catch (Throwable) {
        return false;
    }
}

/**
 * Započítá požadavek do okna (pevné okno $windowSec sekund) a vrátí true, dokud je počet v limitu.
 * Při chybě databáze propouští (limiter nesmí shodit službu).
 */
function allstat_mcp_rate_hit(PDO $pdo, string $bucket, int $limit, int $windowSec): bool
{
    $windowSec = max(1, $windowSec);
    $bucket = allstat_mcp_oauth_bucket($bucket);
    $windowStart = intdiv(time(), $windowSec) * $windowSec;

    try {
        $pdo->prepare('INSERT INTO allstat_mcp_ratelimit (bucket, window_start, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1')
            ->execute([$bucket, $windowStart]);

        $statement = $pdo->prepare('SELECT hits FROM allstat_mcp_ratelimit WHERE bucket = ? AND window_start = ?');
        $statement->execute([$bucket, $windowStart]);
        $hits = (int) $statement->fetchColumn();

        if (random_int(1, 200) === 1) {
            $pdo->prepare('DELETE FROM allstat_mcp_ratelimit WHERE window_start < ? LIMIT 5000')->execute([time() - 86400]);
        }

        return $hits <= $limit;
    } catch (Throwable $exception) {
        error_log('allstat mcp rate limiter: ' . $exception->getMessage());

        return true;
    }
}

/**
 * Vrátí jeden dříve započítaný pokus do aktuálního okna (protějšek allstat_mcp_rate_hit()). Používá se u pokusů, které
 * se rezervují předem, ale nakonec nebyly chybné (správné heslo). Počet nikdy neklesne pod nulu; při chybě se nic neděje.
 */
function allstat_mcp_oauth_rate_refund(PDO $pdo, string $bucket, int $windowSec): void
{
    $windowSec = max(1, $windowSec);

    try {
        $pdo->prepare('UPDATE allstat_mcp_ratelimit SET hits = hits - 1 WHERE bucket = ? AND window_start = ? AND hits > 0')
            ->execute([allstat_mcp_oauth_bucket($bucket), intdiv(time(), $windowSec) * $windowSec]);
    } catch (Throwable) {
        // vrácení pokusu je jen vstřícnost k uživateli, nesmí shodit požadavek
    }
}

// ---------------------------------------------------------------------------------------------------------------
// Politika návratových adres, scope a zdroje
// ---------------------------------------------------------------------------------------------------------------

/** Přesně povolené návratové adresy vestavěných klientů. */
function allstat_mcp_oauth_builtin_redirects(): array
{
    return [
        'https://claude.ai/api/mcp/auth_callback',
        'https://claude.com/api/mcp/auth_callback',
        'https://chatgpt.com/connector_platform_oauth_redirect',
        'https://platform.openai.com/apps-manage/oauth',
    ];
}

/**
 * Tvar redirect URI: max. 1024 znaků (sloupec v tokens), jen tisknutelné ASCII, bez fragmentu, zpětného lomítka
 * a přihlašovacích údajů, schéma http/https s hostem. Vrací rozparsované části (schéma a host malými písmeny) nebo null.
 */
function allstat_mcp_oauth_parse_uri(string $uri): ?array
{
    if ($uri === '' || strlen($uri) > 1024 || preg_match('~[^\x21-\x7E]|[\\\\#]~', $uri)) {
        return null;
    }

    $parts = parse_url($uri);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
        return null;
    }

    $scheme = strtolower($parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return null;
    }

    $parts['scheme'] = $scheme;
    $parts['host'] = strtolower($parts['host']);

    // Host (a port) skončí v CSP form-action: jen bezpečné znaky, jinak se adresa odmítne (platí i pro mcp.extra_redirect_uris).
    if (!allstat_mcp_oauth_host_is_safe($parts['host']) || (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535))) {
        return null;
    }

    return $parts;
}

/** Další přesné návratové adresy z nastavení mcp.extra_redirect_uris (jedna na řádek). */
function allstat_mcp_oauth_extra_redirects(PDO $pdo): array
{
    $raw = allstat_mcp_oauth_setting($pdo, 'mcp.extra_redirect_uris');
    $out = [];

    foreach (preg_split('/\R/', $raw) ?: [] as $line) {
        $line = trim($line);
        $parts = $line !== '' ? allstat_mcp_oauth_parse_uri($line) : null;

        // Jen https (loopback přes http je povolený obecně); http na cizí adresu nikdy, ani když je v nastavení.
        if ($parts !== null && $parts['scheme'] === 'https') {
            $out[] = $line;
        }
    }

    return $out;
}

/**
 * Smí být tahle adresa vůbec návratovou adresou? Přesná shoda se seznamem Claude / ChatGPT, vzor ChatGPT
 * connector/oauth/<id>, loopback (localhost, 127.0.0.1, [::1] s libovolným portem a cestou) nebo přesná shoda
 * s adresou z nastavení. Používá se při registraci, v CIMD dokumentech i v /authorize.
 */
function allstat_mcp_oauth_redirect_uri_allowed(PDO $pdo, string $uri): bool
{
    $parts = allstat_mcp_oauth_parse_uri($uri);
    if ($parts === null) {
        return false;
    }

    if (in_array($uri, allstat_mcp_oauth_builtin_redirects(), true)) {
        return true;
    }

    if (preg_match('~^https://chatgpt\.com/connector/oauth/[A-Za-z0-9_-]{1,128}$~D', $uri) === 1) {
        return true;
    }

    if ($parts['scheme'] === 'http' && allstat_mcp_oauth_is_loopback_host($parts['host'])) {
        return true;
    }

    return in_array($uri, allstat_mcp_oauth_extra_redirects($pdo), true);
}

/**
 * Je adresa mezi zaregistrovanými adresami klienta? Přesná shoda řetězce; u loopbacku (http, localhost /
 * 127.0.0.1 / [::1]) se ignoruje port (RFC 8252, port si aplikace volí za běhu), host, cesta a dotaz musí sedět.
 */
function allstat_mcp_oauth_redirect_registered(array $registered, string $uri): bool
{
    $given = allstat_mcp_oauth_parse_uri($uri);
    if ($given === null) {
        return false;
    }

    foreach ($registered as $candidate) {
        if (!is_string($candidate)) {
            continue;
        }

        if ($candidate === $uri) {
            return true;
        }

        $reg = allstat_mcp_oauth_parse_uri($candidate);
        if ($reg === null) {
            continue;
        }

        if ($reg['scheme'] === 'http' && $given['scheme'] === 'http'
            && allstat_mcp_oauth_is_loopback_host($reg['host']) && allstat_mcp_oauth_is_loopback_host($given['host'])
            && $reg['host'] === $given['host']
            && ($reg['path'] ?? '/') === ($given['path'] ?? '/')
            && ($reg['query'] ?? '') === ($given['query'] ?? '')) {
            return true;
        }
    }

    return false;
}

/** host[:port] z URL pro zobrazení uživateli. */
function allstat_mcp_oauth_uri_host_label(string $uri): string
{
    $parts = parse_url($uri);
    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }

    return strtolower($parts['host']) . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
}

/** Jen host (bez portu) pro uložení k připojení. */
function allstat_mcp_oauth_uri_host_only(string $uri): string
{
    $parts = parse_url($uri);

    return is_array($parts) && !empty($parts['host']) ? strtolower($parts['host']) : '';
}

/**
 * Rozebere parametr scope (dělení podle bílých znaků, prázdné položky pryč). Vrací null pro nepodporovaný scope;
 * jinak vždy [allstat.read] (offline_access se přijímá a ignoruje, refresh tokeny vydáváme vždy).
 */
function allstat_mcp_oauth_parse_scope(string $scope): ?array
{
    $asked = array_values(array_filter(preg_split('/\s+/', trim($scope)) ?: [], static fn (string $item): bool => $item !== ''));

    foreach ($asked as $item) {
        if ($item !== allstat_mcp_oauth_scope() && $item !== 'offline_access') {
            return null;
        }
    }

    return [allstat_mcp_oauth_scope()];
}

// ---------------------------------------------------------------------------------------------------------------
// Uživatelé
// ---------------------------------------------------------------------------------------------------------------

/** Aktivní administrátor (jen bezpečné sloupce) nebo null. */
function allstat_mcp_oauth_active_admin(PDO $pdo, int $userId): ?array
{
    $user = allstat_fetch_one($pdo, 'SELECT id, email, name, role, is_active FROM allstat_users WHERE id = ?', [$userId]);

    if (!$user || (int) $user['is_active'] !== 1 || (string) $user['role'] !== 'admin') {
        return null;
    }

    return ['id' => (int) $user['id'], 'email' => (string) $user['email'], 'name' => (string) $user['name']];
}

// ---------------------------------------------------------------------------------------------------------------
// Klienti: DCR (POST /register) a CIMD (client_id je https URL dokumentu)
// ---------------------------------------------------------------------------------------------------------------

/** Hostitelé, jejichž Client ID Metadata Document smíme stahovat (pevný seznam, ochrana před SSRF). */
function allstat_mcp_oauth_cimd_hosts(): array
{
    return ['claude.ai', 'chatgpt.com'];
}

/** Je IP adresa v rozsahu CIDR (IPv4 i IPv6)? */
function allstat_mcp_oauth_ip_in_cidr(string $ip, string $cidr): bool
{
    [$network, $bits] = array_pad(explode('/', $cidr, 2), 2, '');
    $ipBin = @inet_pton($ip);
    $netBin = @inet_pton($network);

    if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin) || $bits === '') {
        return false;
    }

    $bits = (int) $bits;
    $bytes = intdiv($bits, 8);
    $rest = $bits % 8;

    if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
        return false;
    }

    if ($rest === 0) {
        return true;
    }

    $mask = (0xFF << (8 - $rest)) & 0xFF;

    return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
}

/** Je to veřejně směrovatelná adresa (žádná privátní, loopback, link-local, sdílená, dokumentační, multicast)? */
function allstat_mcp_oauth_ip_is_public(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    $blocked = [
        // IPv4
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        // IPv6
        '::/96', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/32', '2001:2::/48',
        '2001:10::/28', '2001:db8::/32', '2002::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    foreach ($blocked as $cidr) {
        if (allstat_mcp_oauth_ip_in_cidr($ip, $cidr)) {
            return false;
        }
    }

    return true;
}

/**
 * Veřejné IP adresy hostu. Preferuje IPv4; jedna neveřejná adresa v sadě znamená odmítnutí celého hostu
 * (ochrana před DNS triky). Prázdné pole = nelze použít.
 */
function allstat_mcp_oauth_resolve_public_ips(string $host): array
{
    $v4 = [];
    $v6 = [];

    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    if (is_array($records)) {
        foreach ($records as $record) {
            if (($record['type'] ?? '') === 'A' && !empty($record['ip'])) {
                $v4[] = (string) $record['ip'];
            } elseif (($record['type'] ?? '') === 'AAAA' && !empty($record['ipv6'])) {
                $v6[] = (string) $record['ipv6'];
            }
        }
    }

    if (!$v4 && !$v6) {
        $fallback = @gethostbynamel($host);
        if (is_array($fallback)) {
            $v4 = $fallback;
        }
    }

    $chosen = $v4 ?: $v6;
    if (!$chosen) {
        return [];
    }

    foreach ($chosen as $ip) {
        if (!allstat_mcp_oauth_ip_is_public($ip)) {
            return [];
        }
    }

    return array_values(array_unique($chosen));
}

/**
 * Svazek CA certifikátů pro cURL, když ho nastavení PHP samo nemá: proměnná prostředí ALLSTAT_MCP_CAINFO, jinak
 * (jen když PHP nemá žádný CA svazek: curl.cainfo, openssl.cafile ani výchozí soubor) svazek lokálního Laragonu.
 * Na běžném hostingu PHP svazek má a funkce vrací null. Ověření certifikátu se nikdy nevypíná.
 */
function allstat_mcp_oauth_ca_bundle(): ?string
{
    $env = getenv('ALLSTAT_MCP_CAINFO');
    if (is_string($env) && $env !== '' && is_file($env)) {
        return $env;
    }

    $locations = function_exists('openssl_get_cert_locations') ? openssl_get_cert_locations() : [];
    $configured = (string) ini_get('curl.cainfo') !== '' || (string) ini_get('openssl.cafile') !== ''
        || (isset($locations['default_cert_file']) && is_file((string) $locations['default_cert_file']));

    if (!$configured && is_file('C:/laragon/etc/ssl/cacert.pem')) {
        return 'C:/laragon/etc/ssl/cacert.pem';
    }

    return null;
}

/**
 * Bezpečné stažení malého https dokumentu: jen https na portu 443, žádná přesměrování, DNS se přeloží ručně a
 * spojení se připne na ověřenou veřejnou IP (CURLOPT_RESOLVE), timeouty 3 s / 5 s, maximálně $maxBytes.
 *
 * @return array{ok: bool, status: int, body: string, cache_control: string, error: string}
 */
function allstat_mcp_oauth_safe_fetch(string $url, string $userAgent, int $maxBytes = 65536): array
{
    $fail = static fn (string $error, int $status = 0): array => ['ok' => false, 'status' => $status, 'body' => '', 'cache_control' => '', 'error' => $error];

    if (!function_exists('curl_init')) {
        return $fail('curl_missing');
    }

    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
        return $fail('bad_url');
    }

    $host = strtolower($parts['host']);
    $ips = allstat_mcp_oauth_resolve_public_ips($host);
    if (!$ips) {
        return $fail('dns_or_private_address');
    }

    $ip = $ips[0];
    $pin = $host . ':443:' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip);

    $body = '';
    $tooBig = false;
    $cacheControl = '';

    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_HTTPGET => true,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_RESOLVE => [$pin],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => $userAgent,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooBig, $maxBytes): int {
            if (strlen($body) + strlen($chunk) > $maxBytes) {
                $tooBig = true;

                return 0; // přeruší přenos
            }

            $body .= $chunk;

            return strlen($chunk);
        },
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$cacheControl): int {
            if (stripos($line, 'cache-control:') === 0) {
                $cacheControl = trim(substr($line, 14));
            }

            return strlen($line);
        },
    ]);

    $caBundle = allstat_mcp_oauth_ca_bundle();
    if ($caBundle !== null) {
        curl_setopt($handle, CURLOPT_CAINFO, $caBundle);
    }

    curl_exec($handle);
    $errno = curl_errno($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    if ($tooBig) {
        return $fail('too_large', $status);
    }

    if ($errno !== 0) {
        return $fail('curl_' . $errno, $status);
    }

    return ['ok' => true, 'status' => $status, 'body' => $body, 'cache_control' => $cacheControl, 'error' => ''];
}

/** Rozebere client_id ve tvaru CIMD URL (https, povolený host, cesta, bez dotazu a fragmentu) nebo vrátí null. */
function allstat_mcp_oauth_cimd_url_parts(string $url): ?array
{
    if ($url === '' || strlen($url) > 512 || preg_match('~[^\x21-\x7E]|[?#]~', $url)) {
        return null;
    }

    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
        return null;
    }

    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
        return null;
    }

    $path = (string) ($parts['path'] ?? '');
    if ($path === '' || $path === '/') {
        return null;
    }

    if (!in_array(strtolower($parts['host']), allstat_mcp_oauth_cimd_hosts(), true)) {
        return null;
    }

    return $parts;
}

/** Načte klienta z databáze a převede ho na pole (redirect_uris jako pole). */
function allstat_mcp_oauth_client_load(PDO $pdo, string $clientId): ?array
{
    $row = allstat_fetch_one(
        $pdo,
        'SELECT client_id, type, client_name, redirect_uris, metadata, created_at, fetched_at, cache_until, last_used_at FROM allstat_mcp_clients WHERE client_id = ?',
        [$clientId]
    );

    if (!$row) {
        return null;
    }

    $uris = json_decode((string) $row['redirect_uris'], true);
    $meta = json_decode((string) ($row['metadata'] ?? ''), true);

    return [
        'client_id' => (string) $row['client_id'],
        'type' => (string) $row['type'],
        'client_name' => (string) $row['client_name'],
        'redirect_uris' => is_array($uris) ? array_values(array_filter($uris, 'is_string')) : [],
        'client_uri' => is_array($meta) ? (string) ($meta['client_uri'] ?? '') : '',
        'fetched_at' => $row['fetched_at'],
        'cache_until' => $row['cache_until'],
    ];
}

/**
 * Stáhne a ověří Client ID Metadata Document a uloží ho do cache.
 *
 * @return array{ok: true, client: array}|array{ok: false, error: string}
 */
function allstat_mcp_oauth_cimd_refresh(PDO $pdo, string $url): array
{
    $app = allstat_mcp_app_url($pdo);
    $fetched = allstat_mcp_oauth_safe_fetch($url, 'AllStat-MCP/1.0' . ($app !== '' ? ' (+' . $app . ')' : ''));

    if (!$fetched['ok']) {
        return ['ok' => false, 'error' => 'fetch:' . $fetched['error']];
    }

    if ($fetched['status'] !== 200) {
        return ['ok' => false, 'error' => 'http_' . $fetched['status']];
    }

    $doc = json_decode($fetched['body'], true, 16);
    if (!is_array($doc) || !isset($doc['client_id']) || !is_string($doc['client_id']) || $doc['client_id'] !== $url) {
        return ['ok' => false, 'error' => 'client_id_mismatch'];
    }

    // Povolené návratové adresy se ponechají, ostatní se zahodí; musí zbýt aspoň jedna.
    $uris = [];
    foreach (is_array($doc['redirect_uris'] ?? null) ? $doc['redirect_uris'] : [] as $candidate) {
        if (is_string($candidate) && allstat_mcp_oauth_redirect_uri_allowed($pdo, trim($candidate))) {
            $uris[] = trim($candidate);
        }
    }
    $uris = array_slice(array_values(array_unique($uris)), 0, 10);

    if (!$uris) {
        return ['ok' => false, 'error' => 'no_allowed_redirect_uris'];
    }

    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $name = allstat_mcp_oauth_clean_text($doc['client_name'] ?? '', 120);
    if ($name === '') {
        $name = $host;
    }

    $clientUri = is_string($doc['client_uri'] ?? null) && preg_match('~^https://[\x21-\x7E]{1,500}$~', $doc['client_uri']) === 1 ? $doc['client_uri'] : '';

    $ttl = 3600;
    if (preg_match('/max-age=(\d+)/i', $fetched['cache_control'], $match)) {
        $ttl = max(300, min(86400, (int) $match[1]));
    }

    $now = allstat_mcp_oauth_now();
    $metadata = json_encode([
        'client_uri' => $clientUri,
        'grant_types' => ['authorization_code', 'refresh_token'],
        'response_types' => ['code'],
        'token_endpoint_auth_method' => 'none',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $pdo->prepare(
        'INSERT INTO allstat_mcp_clients (client_id, type, client_name, redirect_uris, metadata, created_at, fetched_at, cache_until)
         VALUES (?, \'cimd\', ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE client_name = VALUES(client_name), redirect_uris = VALUES(redirect_uris), metadata = VALUES(metadata),
            fetched_at = VALUES(fetched_at), cache_until = VALUES(cache_until)'
    )->execute([$url, $name, json_encode($uris, JSON_UNESCAPED_SLASHES), $metadata, $now, $now, allstat_mcp_oauth_now($ttl)]);

    $client = allstat_mcp_oauth_client_load($pdo, $url);

    return $client ? ['ok' => true, 'client' => $client] : ['ok' => false, 'error' => 'store_failed'];
}

/**
 * Najde klienta podle client_id: DCR klient z databáze, nebo CIMD dokument (cache, případně stažení).
 *
 * @return array{ok: true, client: array}|array{ok: false, message: string}
 */
function allstat_mcp_oauth_resolve_client(PDO $pdo, string $clientId): array
{
    $clientId = trim($clientId);

    if ($clientId === '' || strlen($clientId) > 512 || preg_match('~[^\x21-\x7E]~', $clientId)) {
        return ['ok' => false, 'message' => 'Identifikátor aplikace (client_id) je neplatný.'];
    }

    if (!str_starts_with(strtolower($clientId), 'https://')) {
        $client = preg_match('~^mcp_[0-9a-f]{32}$~', $clientId) === 1 ? allstat_mcp_oauth_client_load($pdo, $clientId) : null;

        return $client && $client['type'] === 'dcr'
            ? ['ok' => true, 'client' => $client]
            : ['ok' => false, 'message' => 'Tahle aplikace není v AllStatu registrovaná. Zkuste ji v aplikaci připojit znovu.'];
    }

    if (allstat_mcp_oauth_cimd_url_parts($clientId) === null) {
        return ['ok' => false, 'message' => 'Adresa aplikace (client_id) není povolená. AllStat přijímá popis klienta jen od Claude a ChatGPT.'];
    }

    $cached = allstat_mcp_oauth_client_load($pdo, $clientId);
    if ($cached && $cached['type'] === 'cimd' && $cached['cache_until'] !== null && (string) $cached['cache_until'] > allstat_mcp_oauth_now()) {
        return ['ok' => true, 'client' => $cached];
    }

    // Negativní cache: po neúspěšném stažení se dokument 60 s znovu nestahuje (opakované žádosti se špatným client_id).
    if (!allstat_mcp_oauth_cimd_failed_recently($pdo, $clientId)) {
        $refreshed = allstat_mcp_oauth_cimd_refresh($pdo, $clientId);
        if ($refreshed['ok']) {
            return $refreshed;
        }

        allstat_mcp_oauth_cimd_note_failure($pdo, $clientId);
        error_log('allstat mcp: CIMD dokument se nepodařilo načíst (' . ($refreshed['error'] ?? '?') . ') pro ' . substr($clientId, 0, 120));
    }

    // Při výpadku stažení poslouží starší kopie (max. 7 dní), jinak je klient odmítnut.
    if ($cached && $cached['type'] === 'cimd' && $cached['fetched_at'] !== null && (string) $cached['fetched_at'] > allstat_mcp_oauth_now(-7 * 86400)) {
        return ['ok' => true, 'client' => $cached];
    }

    return ['ok' => false, 'message' => 'Popis aplikace se nepodařilo načíst nebo není platný. Zkuste připojení za chvíli zopakovat.'];
}

/** Klíč záznamu o nedávném selhání stažení dokumentu klienta (v tabulce limiteru; window_start = okamžik selhání). */
function allstat_mcp_oauth_cimd_fail_bucket(string $clientId): string
{
    return 'cimdfail:' . substr(hash('sha256', $clientId), 0, 40);
}

/** Selhalo stažení dokumentu tohoto klienta v posledních 60 sekundách? */
function allstat_mcp_oauth_cimd_failed_recently(PDO $pdo, string $clientId): bool
{
    try {
        $statement = $pdo->prepare('SELECT 1 FROM allstat_mcp_ratelimit WHERE bucket = ? AND window_start > ? LIMIT 1');
        $statement->execute([allstat_mcp_oauth_cimd_fail_bucket($clientId), time() - 60]);

        return $statement->fetchColumn() !== false;
    } catch (Throwable) {
        return false;
    }
}

/** Zaznamená neúspěšné stažení dokumentu klienta (negativní cache na 60 s; úklid řeší allstat_mcp_purge). */
function allstat_mcp_oauth_cimd_note_failure(PDO $pdo, string $clientId): void
{
    try {
        $pdo->prepare('INSERT INTO allstat_mcp_ratelimit (bucket, window_start, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1')
            ->execute([allstat_mcp_oauth_cimd_fail_bucket($clientId), time()]);
    } catch (Throwable) {
        // negativní cache je jen optimalizace
    }
}

/** Označí klienta jako použitého (dokončené vydání tokenu). */
function allstat_mcp_oauth_client_touch(PDO $pdo, string $clientId): void
{
    try {
        $pdo->prepare('UPDATE allstat_mcp_clients SET last_used_at = ? WHERE client_id = ?')->execute([allstat_mcp_oauth_now(), $clientId]);
    } catch (Throwable) {
        // informativní údaj, nesmí shodit požadavek
    }
}

/**
 * Kolik registrovaných (DCR) klientů z dané adresy (u IPv6 z bloku /64, viz allstat_mcp_ip_bucket()) vzniklo za posledních
 * 24 hodin a ještě nikdy nevydalo token, a za kolik sekund se uvolní místo pod stropem 20 (0, když je pod ním). Klientů je díky
 * obecnému stropu nejvýš 500, proto se sčítá v PHP podle klíče adresy (v tabulce je uložená přesná adresa).
 *
 * @return array{count: int, retry_after: int}
 */
function allstat_mcp_oauth_outstanding_registrations(PDO $pdo, string $bucket): array
{
    $statement = $pdo->prepare("SELECT created_ip, created_at FROM allstat_mcp_clients WHERE type = 'dcr' AND last_used_at IS NULL AND created_at > ? ORDER BY created_at ASC LIMIT 1000");
    $statement->execute([allstat_mcp_oauth_now(-86400)]);

    $times = [];

    foreach ($statement->fetchAll() as $row) {
        if (allstat_mcp_ip_bucket((string) ($row['created_ip'] ?? '')) === $bucket) {
            $times[] = strtotime((string) $row['created_at']) ?: time();
        }
    }

    $count = count($times);
    $retryAfter = $count >= 20 ? max(60, min(86400, $times[$count - 20] + 86400 - time())) : 0;

    return ['count' => $count, 'retry_after' => $retryAfter];
}

/**
 * Registrace klienta (DCR, RFC 7591): ověří metadata, uloží veřejného klienta a vrátí [status, body, headers?].
 * Strop registrací: z jedné adresy (IPv6 po /64) smí být rozpracováno (nikdy nepoužito, mladší 24 hodin) nejvýš 20
 * klientů, jinak 429 + Retry-After; navíc obecný strop 500 klientů (503 + Retry-After).
 *
 * @return array{status: int, body: array, headers?: array}
 */
function allstat_mcp_oauth_register_client(PDO $pdo, array $meta): array
{
    $uris = $meta['redirect_uris'] ?? null;
    if (!is_array($uris) || !array_is_list($uris) || count($uris) < 1 || count($uris) > 10) {
        return allstat_mcp_oauth_err('invalid_redirect_uri', 'redirect_uris musí být pole s 1 až 10 adresami.');
    }

    $clean = [];
    foreach ($uris as $candidate) {
        if (!is_string($candidate) || !allstat_mcp_oauth_redirect_uri_allowed($pdo, trim($candidate))) {
            $shown = is_string($candidate) ? allstat_mcp_oauth_clean_text($candidate, 80) : '';

            return allstat_mcp_oauth_err('invalid_redirect_uri', 'Návratová adresa není povolená' . ($shown !== '' ? ': ' . $shown : '') . '. AllStat přijímá jen adresy Claude, ChatGPT a lokální (loopback) adresy.');
        }

        $clean[] = trim($candidate);
    }
    $clean = array_values(array_unique($clean));

    if (array_key_exists('token_endpoint_auth_method', $meta) && $meta['token_endpoint_auth_method'] !== 'none') {
        return allstat_mcp_oauth_err('invalid_client_metadata', 'Podporujeme jen veřejné klienty (token_endpoint_auth_method = none).');
    }

    $grantTypes = ['authorization_code', 'refresh_token'];
    if (array_key_exists('grant_types', $meta)) {
        $given = $meta['grant_types'];
        if (!is_array($given) || !array_is_list($given) || !$given || array_filter($given, 'is_string') !== $given || array_diff($given, ['authorization_code', 'refresh_token']) || !in_array('authorization_code', $given, true)) {
            return allstat_mcp_oauth_err('invalid_client_metadata', 'grant_types musí obsahovat authorization_code a smí obsahovat jen authorization_code a refresh_token.');
        }

        $grantTypes = array_values(array_intersect(['authorization_code', 'refresh_token'], $given));
    }

    if (array_key_exists('response_types', $meta)) {
        $given = $meta['response_types'];
        if (!is_array($given) || !array_is_list($given) || !$given || array_filter($given, 'is_string') !== $given || array_diff($given, ['code'])) {
            return allstat_mcp_oauth_err('invalid_client_metadata', 'response_types smí obsahovat jen code.');
        }
    }

    $name = allstat_mcp_oauth_clean_text($meta['client_name'] ?? '', 120);

    // Strop rozpracovaných registrací z jedné adresy (IPv6 po /64): jedna adresa nemůže zaplnit tabulku klientů,
    // který nikdy nikdo nedokončí. Klienti, kteří vydali aspoň jeden token, ani starší než 24 hodin se nepočítají.
    $outstanding = allstat_mcp_oauth_outstanding_registrations($pdo, allstat_mcp_client_ip());
    if ($outstanding['count'] >= 20) {
        return [
            'status' => 429,
            'body' => ['error' => 'slow_down', 'error_description' => 'Z této adresy čeká na dokončení příliš mnoho registrací aplikací (nejvýš 20 za 24 hodin). Zkuste to později.'],
            'headers' => ['Retry-After' => (string) $outstanding['retry_after']],
        ];
    }

    $count = static fn (): int => (int) $pdo->query("SELECT COUNT(*) FROM allstat_mcp_clients WHERE type = 'dcr'")->fetchColumn();
    if ($count() >= 500) {
        // Nejdřív se uvolní místo po klientech, kteří nikdy nedokončili autorizaci a jsou starší než 24 hodin.
        allstat_mcp_oauth_purge_dcr_clients($pdo, 1, true);

        if ($count() >= 500) {
            return [
                'status' => 503,
                'body' => ['error' => 'temporarily_unavailable', 'error_description' => 'Registrace je dočasně nedostupná, zkuste to za hodinu.'],
                'headers' => ['Retry-After' => '3600'],
            ];
        }
    }

    $clientId = 'mcp_' . bin2hex(random_bytes(16));
    $issuedAt = time();
    $metadata = json_encode([
        'grant_types' => $grantTypes,
        'response_types' => ['code'],
        'token_endpoint_auth_method' => 'none',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $pdo->prepare(
        'INSERT INTO allstat_mcp_clients (client_id, type, client_name, redirect_uris, metadata, created_at, created_ip) VALUES (?, \'dcr\', ?, ?, ?, ?, ?)'
    )->execute([$clientId, $name, json_encode($clean, JSON_UNESCAPED_SLASHES), $metadata, allstat_mcp_oauth_now(), allstat_mcp_client_ip_full()]);

    $body = [
        'client_id' => $clientId,
        'client_id_issued_at' => $issuedAt,
        'redirect_uris' => $clean,
        'grant_types' => $grantTypes,
        'response_types' => ['code'],
        'token_endpoint_auth_method' => 'none',
    ];

    if ($name !== '') {
        $body['client_name'] = $name;
    }

    return ['status' => 201, 'body' => $body];
}

/**
 * Úklid DCR klientů. $onlyNeverUsed: jen ti, kdo nikdy nedokončili autorizaci a jsou starší než $days dní;
 * jinak i staří klienti bez jediného připojení (podle posledního použití nebo vzniku).
 */
function allstat_mcp_oauth_purge_dcr_clients(PDO $pdo, int $days, bool $onlyNeverUsed): void
{
    $cutoff = allstat_mcp_oauth_now(-$days * 86400);

    if ($onlyNeverUsed) {
        $pdo->prepare("DELETE FROM allstat_mcp_clients WHERE type = 'dcr' AND last_used_at IS NULL AND created_at < ? LIMIT 2000")->execute([$cutoff]);

        return;
    }

    $pdo->prepare(
        "DELETE FROM allstat_mcp_clients WHERE type = 'dcr' AND COALESCE(last_used_at, created_at) < ?
            AND NOT EXISTS (SELECT 1 FROM allstat_mcp_grants g WHERE g.client_id = allstat_mcp_clients.client_id) LIMIT 2000"
    )->execute([$cutoff]);
}

// ---------------------------------------------------------------------------------------------------------------
// Autorizační požadavek, souhlas, kód
// ---------------------------------------------------------------------------------------------------------------

/**
 * Ověří parametry GET /authorize (nejdřív klient a redirect_uri, teprve pak chyby, které se vrací přesměrováním).
 *
 * Výsledek:
 *  - ['ok' => true, 'client' => [...], 'request' => [client_id, redirect_uri, code_challenge, state, scope, resource]]
 *  - ['ok' => false, 'type' => 'page', 'status' => int, 'message' => string]      (HTML stránka, nikdy přesměrování)
 *  - ['ok' => false, 'type' => 'redirect', 'redirect_uri' => string, 'state' => string, 'error' => string, 'description' => string]
 */
function allstat_mcp_oauth_authorize_validate(PDO $pdo, array $query): array
{
    $page = static fn (string $message, int $status = 400): array => ['ok' => false, 'type' => 'page', 'status' => $status, 'message' => $message];

    $clientId = allstat_mcp_oauth_str($query, 'client_id', 512);
    if ($clientId === '') {
        return $page('V žádosti chybí identifikátor aplikace (client_id).');
    }

    $resolved = allstat_mcp_oauth_resolve_client($pdo, $clientId);
    if (!$resolved['ok']) {
        return $page($resolved['message']);
    }

    $client = $resolved['client'];

    $redirectUri = allstat_mcp_oauth_str($query, 'redirect_uri', 1024);
    if ($redirectUri === '') {
        return $page('V žádosti chybí návratová adresa (redirect_uri).');
    }

    if (!allstat_mcp_oauth_redirect_uri_allowed($pdo, $redirectUri) || !allstat_mcp_oauth_redirect_registered($client['redirect_uris'], $redirectUri)) {
        return $page('Návratová adresa aplikace není povolená ani zaregistrovaná, proto se na ni nelze vrátit. Zkuste aplikaci připojit znovu.');
    }

    $state = allstat_mcp_oauth_str($query, 'state', 2048);
    $fail = static fn (string $error, string $description): array => [
        'ok' => false,
        'type' => 'redirect',
        'redirect_uri' => $redirectUri,
        'state' => $state,
        'error' => $error,
        'description' => $description,
    ];

    if (isset($query['state']) && $state === '' && $query['state'] !== '') {
        return $fail('invalid_request', 'Parametr state je neplatný nebo příliš dlouhý.');
    }

    if (allstat_mcp_oauth_str($query, 'response_type', 64) !== 'code') {
        return $fail('unsupported_response_type', 'Podporovaný je jen response_type=code.');
    }

    // S256: challenge je base64url(SHA-256), tedy přesně 43 znaků [A-Za-z0-9_-] (verifier může mít 43 až 128 znaků).
    $challenge = allstat_mcp_oauth_str($query, 'code_challenge', 128);
    if ($challenge === '' || preg_match('#^[A-Za-z0-9_-]{43}$#D', $challenge) !== 1) {
        return $fail('invalid_request', 'Chybí platný PKCE code_challenge (S256, přesně 43 znaků base64url).');
    }

    if (allstat_mcp_oauth_str($query, 'code_challenge_method', 16) !== 'S256') {
        return $fail('invalid_request', 'Podporovaná je jen metoda PKCE S256.');
    }

    if (allstat_mcp_oauth_parse_scope(allstat_mcp_oauth_str($query, 'scope', 512)) === null) {
        return $fail('invalid_scope', 'Podporovaný je jen scope allstat.read.');
    }

    $resource = allstat_mcp_resource_url($pdo);
    if (array_key_exists('resource', $query)) {
        $given = is_string($query['resource']) ? trim($query['resource']) : '';
        if ($given !== '' && !allstat_mcp_oauth_resource_matches($pdo, $given)) {
            return $fail('invalid_target', 'Neznámý chráněný zdroj (resource). Očekává se ' . $resource . '.');
        }

        if (is_array($query['resource'])) {
            return $fail('invalid_target', 'Parametr resource smí být jen jeden.');
        }
    }

    return [
        'ok' => true,
        'client' => $client,
        'request' => [
            'client_id' => $client['client_id'],
            'redirect_uri' => $redirectUri,
            'code_challenge' => $challenge,
            'state' => $state,
            'scope' => allstat_mcp_oauth_scope(),
            'resource' => $resource,
        ],
    ];
}

/** Klíč pro podpis skrytého pole `req` na stránce souhlasu (odvozený od šifrovacího klíče aplikace). */
function allstat_mcp_oauth_consent_key(array $config): string
{
    return hash_hmac('sha256', 'allstat-mcp-consent', allstat_crypto_keys($config)[0], true);
}

/** Podepsaný záznam ověřeného požadavku (platí 10 minut, vázaný na přihlášeného uživatele). */
function allstat_mcp_oauth_consent_token(array $config, array $request, int $userId): string
{
    $payload = allstat_mcp_oauth_b64url((string) json_encode([
        'v' => 1,
        'cid' => $request['client_id'],
        'ru' => $request['redirect_uri'],
        'cc' => $request['code_challenge'],
        'st' => $request['state'],
        'sc' => $request['scope'],
        'rs' => $request['resource'],
        'uid' => $userId,
        'exp' => time() + 600,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    return $payload . '.' . allstat_mcp_oauth_b64url(hash_hmac('sha256', $payload, allstat_mcp_oauth_consent_key($config), true));
}

/** Ověří podpis a platnost pole `req`; vrací {request..., uid} nebo null. */
function allstat_mcp_oauth_consent_token_verify(array $config, string $token): ?array
{
    $pieces = explode('.', $token);
    if (count($pieces) !== 2 || strlen($token) > 8192) {
        return null;
    }

    $signature = allstat_mcp_oauth_b64url_decode($pieces[1]);
    if ($signature === null || !hash_equals(hash_hmac('sha256', $pieces[0], allstat_mcp_oauth_consent_key($config), true), $signature)) {
        return null;
    }

    $json = allstat_mcp_oauth_b64url_decode($pieces[0]);
    $data = $json !== null ? json_decode($json, true, 8) : null;

    if (!is_array($data) || ($data['v'] ?? null) !== 1 || (int) ($data['exp'] ?? 0) < time()) {
        return null;
    }

    foreach (['cid', 'ru', 'cc', 'sc', 'rs'] as $key) {
        if (!isset($data[$key]) || !is_string($data[$key])) {
            return null;
        }
    }

    return [
        'uid' => (int) ($data['uid'] ?? 0),
        'request' => [
            'client_id' => $data['cid'],
            'redirect_uri' => $data['ru'],
            'code_challenge' => $data['cc'],
            'state' => is_string($data['st'] ?? null) ? $data['st'] : '',
            'scope' => $data['sc'],
            'resource' => $data['rs'],
        ],
    ];
}

/** URL, na kterou se přesměruje zpět do aplikace (code/error + state + iss). */
function allstat_mcp_oauth_redirect_url(PDO $pdo, string $redirectUri, array $params): string
{
    return allstat_mcp_oauth_add_query($redirectUri, $params + ['iss' => allstat_mcp_host_url($pdo)]);
}

/** URL pro zamítnutí (access_denied). */
function allstat_mcp_oauth_consent_deny_url(PDO $pdo, array $request): string
{
    return allstat_mcp_oauth_redirect_url($pdo, $request['redirect_uri'], [
        'error' => 'access_denied',
        'error_description' => 'Uživatel přístup nepovolil.',
        'state' => $request['state'],
    ]);
}

/**
 * Potvrzení hesla na stránce souhlasu (krok navíc, když je poslední přihlášení heslem starší než 10 minut). Vrací null,
 * když heslo sedí, jinak [zpráva, HTTP stav]; nikdy nepřesměrovává do aplikace a nic nezapisuje do session.
 *
 * Pokus se rezervuje (atomicky započítá) PŘED ověřením hesla, takže souběžné odhady limit nepřekročí:
 *  - 5 pokusů za 15 minut na uživatele a adresu (u IPv6 na /64),
 *  - 10 pokusů za hodinu na uživatele ze všech adres dohromady.
 * Správné heslo pokus vrací, takže se počítají jen chybné (a právě rozpracované) pokusy. Prázdné heslo a hodnota, která
 * není řetězec (např. password[]=x), se neověřují a nepočítají; berou se jako nezadané heslo.
 *
 * @return array{0: string, 1: int}|null
 */
function allstat_mcp_oauth_consent_confirm_password(PDO $pdo, array $user, mixed $password, string $host = ''): ?array
{
    $userId = (int) ($user['id'] ?? 0);

    if (!is_string($password) || $password === '') {
        return ['Pro potvrzení zadejte své heslo k AllStatu.', 200];
    }

    $ipBucket = 'consent_pw:' . $userId . ':' . allstat_mcp_client_ip();
    $userBucket = 'consent_pw_user:' . $userId;

    if (!allstat_mcp_rate_hit($pdo, $ipBucket, 5, 900)) {
        return ['Příliš mnoho nesprávných pokusů o zadání hesla. Zkuste to znovu za 15 minut.', 429];
    }

    if (!allstat_mcp_rate_hit($pdo, $userBucket, 10, 3600)) {
        return ['Příliš mnoho nesprávných pokusů o zadání hesla k tomuto účtu. Zkuste to znovu za hodinu.', 429];
    }

    if (!password_verify($password, (string) ($user['password_hash'] ?? ''))) {
        try {
            allstat_audit($pdo, $userId, $userId, 'mcp_consent_password_failed', 'host=' . $host);
        } catch (Throwable) {
            // audit nesmí změnit výsledek (heslo stejně nesedí)
        }

        return ['Heslo nesedí. Zkuste to prosím znovu.', 200];
    }

    allstat_mcp_oauth_rate_refund($pdo, $ipBucket, 900);
    allstat_mcp_oauth_rate_refund($pdo, $userBucket, 3600);

    return null;
}

/**
 * Souhlas: založí připojení (grant) a jednorázový autorizační kód (5 minut), zapíše audit a vrátí URL, na kterou
 * se má prohlížeč přesměrovat (code + state + iss).
 */
function allstat_mcp_oauth_consent_allow(PDO $pdo, array $user, array $client, array $request): string
{
    $grantId = bin2hex(random_bytes(16));
    $code = allstat_mcp_oauth_random_token('asc_');
    $now = allstat_mcp_oauth_now();
    $redirectHost = allstat_mcp_oauth_uri_host_only($request['redirect_uri']);
    $name = mb_substr($client['client_name'] !== '' ? $client['client_name'] : allstat_mcp_oauth_uri_host_label($request['redirect_uri']), 0, 190);

    allstat_mcp_oauth_atomic($pdo, static function () use ($pdo, $grantId, $user, $client, $request, $code, $name, $redirectHost, $now): void {
        $pdo->prepare(
            'INSERT INTO allstat_mcp_grants (grant_id, user_id, client_id, client_name, redirect_host, scope, created_at, created_ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$grantId, (int) $user['id'], $client['client_id'], $name, mb_substr($redirectHost, 0, 190), $request['scope'], $now, allstat_mcp_client_ip_full()]);

        $pdo->prepare(
            'INSERT INTO allstat_mcp_tokens (token_hash, kind, grant_id, client_id, user_id, scope, resource, redirect_uri, code_challenge, expires_at, created_at)
             VALUES (?, \'code\', ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            allstat_mcp_oauth_hash($code), $grantId, $client['client_id'], (int) $user['id'], $request['scope'], $request['resource'],
            $request['redirect_uri'], $request['code_challenge'], allstat_mcp_oauth_now(300), $now,
        ]);
    });

    allstat_audit($pdo, (int) $user['id'], null, 'mcp_consent_granted', 'client=' . $name . ' host=' . $redirectHost);

    return allstat_mcp_oauth_redirect_url($pdo, $request['redirect_uri'], ['code' => $code, 'state' => $request['state']]);
}

// ---------------------------------------------------------------------------------------------------------------
// Tokeny: vydání, výměna kódu, obnovení s detekcí opakovaného použití, odvolání
// ---------------------------------------------------------------------------------------------------------------

/**
 * Spustí $work v transakci (pokud už transakce běží, jen ji použije). Při uváznutí (1213) nebo vypršení čekání na zámek
 * (1205) se transakce zopakuje, nejvýš třikrát. Všechny transakce zamykají nejdřív řádek připojení (allstat_mcp_grants),
 * teprve potom tokeny, aby k uváznutí ani nedocházelo.
 */
function allstat_mcp_oauth_atomic(PDO $pdo, callable $work): mixed
{
    if ($pdo->inTransaction()) {
        return $work();
    }

    for ($attempt = 1; ; $attempt++) {
        $pdo->beginTransaction();

        try {
            $result = $work();
            $pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $driverCode = $exception instanceof PDOException ? (string) ($exception->errorInfo[1] ?? '') : '';
            if ($attempt < 3 && in_array($driverCode, ['1213', '1205'], true)) {
                usleep(random_int(20000, 80000));

                continue;
            }

            throw $exception;
        }
    }
}

/** Nejdelší doba platnosti jednoho připojení od souhlasu: 180 dní (v sekundách), bez ohledu na obnovování tokenů. */
function allstat_mcp_oauth_grant_max_age(): int
{
    return 180 * 86400;
}

/** Unixový čas, kdy připojení nejpozději zaniká (vznik + 180 dní); neplatné datum znamená, že už zaniklo. */
function allstat_mcp_oauth_grant_end(string $createdAt): int
{
    $created = strtotime($createdAt);

    return ($created === false ? 0 : $created) + allstat_mcp_oauth_grant_max_age();
}

/**
 * Vydá dvojici access + refresh token pro připojení (staré access tokeny připojení se zahodí). Nejdřív zamkne řádek
 * připojení a ověří, že není odvolané ani starší než 180 dní; jinak nevydá nic a vrátí null. Platnost žádného z tokenů
 * nikdy nepřesáhne konec připojení (vznik + 180 dní).
 */
function allstat_mcp_oauth_issue_tokens(PDO $pdo, string $grantId, int $userId, string $clientId, string $scope, string $resource): ?array
{
    $access = allstat_mcp_oauth_random_token('asa_');
    $refresh = allstat_mcp_oauth_random_token('asr_');
    $accessTtl = allstat_mcp_oauth_ttl($pdo, 'mcp.access_ttl', 28800, 300, 2592000);
    $refreshTtl = allstat_mcp_oauth_ttl($pdo, 'mcp.refresh_ttl', 5184000, 3600, 31536000);
    $now = allstat_mcp_oauth_now();

    $accessLife = allstat_mcp_oauth_atomic($pdo, static function () use ($pdo, $grantId, $userId, $clientId, $scope, $resource, $access, $refresh, $accessTtl, $refreshTtl, $now): ?int {
        $lock = $pdo->prepare('SELECT revoked_at, created_at FROM allstat_mcp_grants WHERE grant_id = ? FOR UPDATE');
        $lock->execute([$grantId]);
        $row = $lock->fetch();

        if (!$row || $row['revoked_at'] !== null) {
            return null;
        }

        $remaining = allstat_mcp_oauth_grant_end((string) $row['created_at']) - time();
        if ($remaining <= 0) {
            return null;
        }

        $accessLife = min($accessTtl, $remaining);
        $refreshLife = min($refreshTtl, $remaining);
        $insert = $pdo->prepare('INSERT INTO allstat_mcp_tokens (token_hash, kind, grant_id, client_id, user_id, scope, resource, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');

        $pdo->prepare('UPDATE allstat_mcp_grants SET last_used_at = ? WHERE grant_id = ?')->execute([$now, $grantId]);
        $pdo->prepare("DELETE FROM allstat_mcp_tokens WHERE grant_id = ? AND kind = 'access'")->execute([$grantId]);
        $insert->execute([allstat_mcp_oauth_hash($access), 'access', $grantId, $clientId, $userId, $scope, $resource, allstat_mcp_oauth_now($accessLife), $now]);
        $insert->execute([allstat_mcp_oauth_hash($refresh), 'refresh', $grantId, $clientId, $userId, $scope, $resource, allstat_mcp_oauth_now($refreshLife), $now]);

        return $accessLife;
    });

    if ($accessLife === null) {
        return null;
    }

    return [
        'access_token' => $access,
        'token_type' => 'Bearer',
        'expires_in' => $accessLife,
        'refresh_token' => $refresh,
        'scope' => $scope,
    ];
}

/**
 * grant_type=authorization_code: kód se při prvním předložení označí jako použitý (i když je požadavek nakonec chybný)
 * a ponechá se ještě 10 minut. Opakované předložení použitého kódu odvolá celé připojení, které z něj vzniklo
 * (RFC 6749, oddíl 4.1.2).
 */
function allstat_mcp_oauth_grant_code(PDO $pdo, array $params): array
{
    $code = allstat_mcp_oauth_str($params, 'code', 512);
    $verifier = allstat_mcp_oauth_str($params, 'code_verifier', 256);
    $clientId = allstat_mcp_oauth_str($params, 'client_id', 512);
    $redirectUri = allstat_mcp_oauth_str($params, 'redirect_uri', 1024);
    $resource = allstat_mcp_oauth_str($params, 'resource', 2048);

    if ($code === '' || $verifier === '') {
        return allstat_mcp_oauth_err('invalid_request', 'Chybí parametr code nebo code_verifier.');
    }

    if ($clientId === '') {
        return allstat_mcp_oauth_err('invalid_request', 'Chybí parametr client_id.');
    }

    if ($redirectUri === '') {
        return allstat_mcp_oauth_err('invalid_request', 'Chybí parametr redirect_uri.');
    }

    $invalid = static fn (): array => allstat_mcp_oauth_err('invalid_grant', 'Autorizační kód je neplatný, vypršel nebo už byl použitý.');

    $hash = allstat_mcp_oauth_hash($code);
    $row = allstat_fetch_one(
        $pdo,
        "SELECT id, grant_id, client_id, user_id, scope, resource, redirect_uri, code_challenge, expires_at, used_at FROM allstat_mcp_tokens WHERE token_hash = ? AND kind = 'code'",
        [$hash]
    );

    if (!$row) {
        return $invalid();
    }

    $reused = static function () use ($pdo, $row): array {
        allstat_mcp_revoke_grant($pdo, (string) $row['grant_id']);

        try {
            allstat_audit($pdo, (int) $row['user_id'], null, 'mcp_code_reuse', 'grant=' . $row['grant_id'] . ' client=' . mb_substr((string) $row['client_id'], 0, 120));
        } catch (Throwable) {
            // audit nesmí zabránit odvolání
        }

        return allstat_mcp_oauth_err('invalid_grant', 'Autorizační kód byl už použit. Z bezpečnostních důvodů bylo připojení odvoláno, připojte aplikaci znovu.');
    };

    if ($row['used_at'] !== null) {
        return $reused();
    }

    // Atomické označení jako použitého: vyhrát smí jen jeden souběžný požadavek, další předložení je opakované použití.
    $now = allstat_mcp_oauth_now();
    $mark = $pdo->prepare("UPDATE allstat_mcp_tokens SET used_at = ?, expires_at = ? WHERE token_hash = ? AND kind = 'code' AND used_at IS NULL AND expires_at > ?");
    $mark->execute([$now, allstat_mcp_oauth_now(600), $hash, $now]);
    if ($mark->rowCount() !== 1) {
        $again = allstat_fetch_one($pdo, "SELECT used_at FROM allstat_mcp_tokens WHERE token_hash = ? AND kind = 'code'", [$hash]);

        return $again && $again['used_at'] !== null ? $reused() : $invalid();
    }

    if (!hash_equals((string) $row['client_id'], $clientId)) {
        return allstat_mcp_oauth_err('invalid_client', 'Kód byl vydán jiné aplikaci (client_id nesouhlasí).', 401);
    }

    if (!hash_equals((string) $row['redirect_uri'], $redirectUri)) {
        return allstat_mcp_oauth_err('invalid_grant', 'redirect_uri se neshoduje s adresou z autorizačního požadavku.');
    }

    $expectedChallenge = (string) $row['code_challenge'];
    $challenge = allstat_mcp_oauth_b64url(hash('sha256', $verifier, true));
    if (preg_match('#^[A-Za-z0-9._~-]{43,128}$#D', $verifier) !== 1 || $expectedChallenge === '' || !hash_equals($expectedChallenge, $challenge)) {
        allstat_mcp_revoke_grant($pdo, (string) $row['grant_id']);

        return allstat_mcp_oauth_err('invalid_grant', 'Ověření PKCE (code_verifier) selhalo.');
    }

    if ($resource !== '' && !allstat_mcp_oauth_resource_matches($pdo, $resource)) {
        return allstat_mcp_oauth_err('invalid_target', 'Neznámý chráněný zdroj (resource).');
    }

    if (!allstat_mcp_oauth_resource_matches($pdo, (string) $row['resource'])) {
        return allstat_mcp_oauth_err('invalid_grant', 'Chráněný zdroj se od vydání kódu změnil.');
    }

    $grant = allstat_fetch_one($pdo, 'SELECT grant_id, revoked_at FROM allstat_mcp_grants WHERE grant_id = ?', [(string) $row['grant_id']]);
    if (!$grant || $grant['revoked_at'] !== null) {
        return $invalid();
    }

    if (allstat_mcp_oauth_active_admin($pdo, (int) $row['user_id']) === null) {
        allstat_mcp_revoke_user($pdo, (int) $row['user_id']);

        return allstat_mcp_oauth_err('invalid_grant', 'Účet už nemá oprávnění administrátora AllStatu.');
    }

    $tokens = allstat_mcp_oauth_issue_tokens($pdo, (string) $row['grant_id'], (int) $row['user_id'], (string) $row['client_id'], (string) $row['scope'], (string) $row['resource']);
    if ($tokens === null) {
        return $invalid();
    }

    allstat_mcp_oauth_client_touch($pdo, (string) $row['client_id']);

    return ['status' => 200, 'body' => $tokens];
}

/**
 * grant_type=refresh_token: rotace. Token otočený před méně než 15 s a předložený znovu (souběžný duplicitní požadavek)
 * vrátí invalid_grant a zapíše audit mcp_refresh_grace, ale připojení se neodvolá; starší opakované použití odvolá
 * celé připojení. Připojení nikdy nežije déle než 180 dní od souhlasu.
 */
function allstat_mcp_oauth_grant_refresh(PDO $pdo, array $params): array
{
    $token = allstat_mcp_oauth_str($params, 'refresh_token', 512);
    $clientId = allstat_mcp_oauth_str($params, 'client_id', 512);
    $scope = allstat_mcp_oauth_str($params, 'scope', 512);
    $resource = allstat_mcp_oauth_str($params, 'resource', 2048);

    if ($token === '') {
        return allstat_mcp_oauth_err('invalid_request', 'Chybí parametr refresh_token.');
    }

    if ($clientId === '') {
        return allstat_mcp_oauth_err('invalid_request', 'Chybí parametr client_id (v těle požadavku nebo v hlavičce Authorization: Basic).');
    }

    $invalid = static fn (): array => allstat_mcp_oauth_err('invalid_grant', 'Obnovovací token je neplatný, vypršel nebo už byl použitý.');

    $hash = allstat_mcp_oauth_hash($token);
    $row = allstat_fetch_one(
        $pdo,
        "SELECT id, grant_id, client_id, user_id, scope, resource, expires_at, used_at FROM allstat_mcp_tokens WHERE token_hash = ? AND kind = 'refresh'",
        [$hash]
    );

    if (!$row) {
        return $invalid();
    }

    $reuse = static function () use ($pdo, $row): array {
        allstat_mcp_revoke_grant($pdo, (string) $row['grant_id']);

        try {
            allstat_audit($pdo, (int) $row['user_id'], null, 'mcp_refresh_reuse', 'grant=' . $row['grant_id'] . ' client=' . mb_substr((string) $row['client_id'], 0, 120));
        } catch (Throwable) {
            // audit nesmí zabránit odvolání
        }

        return allstat_mcp_oauth_err('invalid_grant', 'Obnovovací token byl už použit. Z bezpečnostních důvodů bylo připojení odvoláno, připojte aplikaci znovu.');
    };

    // Již použitý token: do 15 s po otočení jde o souběžný duplicitní požadavek (nový pár vítěze zůstává platný,
    // připojení se neodvolává, ale každý takový případ se zapíše do auditu jako mcp_refresh_grace), později o
    // opakované použití, které připojení odvolá.
    $alreadyUsed = static function (mixed $usedAt) use ($pdo, $row, $reuse): array {
        if ((string) $usedAt > allstat_mcp_oauth_now(-15)) {
            try {
                $named = allstat_fetch_one($pdo, 'SELECT client_name FROM allstat_mcp_clients WHERE client_id = ?', [(string) $row['client_id']]);
                $clientName = allstat_mcp_oauth_clean_text($named['client_name'] ?? '', 120);
                allstat_audit(
                    $pdo,
                    (int) $row['user_id'],
                    (int) $row['user_id'],
                    'mcp_refresh_grace',
                    'grant=' . $row['grant_id']
                        . ' client=' . ($clientName !== '' ? $clientName : mb_substr((string) $row['client_id'], 0, 120))
                        . ' ip=' . allstat_mcp_client_ip_full()
                        . ' ua=' . allstat_mcp_oauth_clean_text($_SERVER['HTTP_USER_AGENT'] ?? '', 120)
                );
            } catch (Throwable) {
                // audit nesmí změnit odpověď
            }

            return allstat_mcp_oauth_err('invalid_grant', 'Obnovovací token byl právě použit (souběžný požadavek). Použijte nový token z předchozí odpovědi.');
        }

        return $reuse();
    };

    if ($row['used_at'] !== null) {
        return $alreadyUsed($row['used_at']);
    }

    if ((string) $row['expires_at'] <= allstat_mcp_oauth_now()) {
        return $invalid();
    }

    if (!hash_equals((string) $row['client_id'], $clientId)) {
        return allstat_mcp_oauth_err('invalid_client', 'Token byl vydán jiné aplikaci (client_id nesouhlasí).', 401);
    }

    // Nejdřív všechny kontroly, které token nespotřebují: neplatný požadavek (scope, resource ...) nesmí
    // obnovovací token spálit, jinak by další řádné obnovení vypadalo jako opakované použití.
    if ($scope !== '' && allstat_mcp_oauth_parse_scope($scope) === null) {
        return allstat_mcp_oauth_err('invalid_scope', 'Podporovaný je jen scope allstat.read.');
    }

    if ($resource !== '' && !allstat_mcp_oauth_resource_matches($pdo, $resource)) {
        return allstat_mcp_oauth_err('invalid_target', 'Neznámý chráněný zdroj (resource).');
    }

    if (!allstat_mcp_oauth_resource_matches($pdo, (string) $row['resource'])) {
        return allstat_mcp_oauth_err('invalid_grant', 'Chráněný zdroj se od vydání tokenu změnil.');
    }

    $grant = allstat_fetch_one($pdo, 'SELECT grant_id, revoked_at, created_at FROM allstat_mcp_grants WHERE grant_id = ?', [(string) $row['grant_id']]);
    if (!$grant || $grant['revoked_at'] !== null) {
        return $invalid();
    }

    if (allstat_mcp_oauth_grant_end((string) $grant['created_at']) <= time()) {
        allstat_mcp_revoke_grant($pdo, (string) $row['grant_id']);

        return allstat_mcp_oauth_err('invalid_grant', 'Platnost připojení vypršela (nejdéle 180 dní od souhlasu). Připojte aplikaci znovu.');
    }

    if (allstat_mcp_oauth_active_admin($pdo, (int) $row['user_id']) === null) {
        allstat_mcp_revoke_user($pdo, (int) $row['user_id']);

        return allstat_mcp_oauth_err('invalid_grant', 'Účet už nemá oprávnění administrátora AllStatu.');
    }

    // Atomické označení jako použitého: vyhrát smí jen jeden souběžný požadavek, poražený je duplicitní požadavek.
    $mark = $pdo->prepare("UPDATE allstat_mcp_tokens SET used_at = ? WHERE token_hash = ? AND kind = 'refresh' AND used_at IS NULL AND expires_at > ?");
    $mark->execute([allstat_mcp_oauth_now(), $hash, allstat_mcp_oauth_now()]);
    if ($mark->rowCount() !== 1) {
        $again = allstat_fetch_one($pdo, "SELECT used_at FROM allstat_mcp_tokens WHERE token_hash = ? AND kind = 'refresh'", [$hash]);

        return $again && $again['used_at'] !== null ? $alreadyUsed($again['used_at']) : $invalid();
    }

    $tokens = allstat_mcp_oauth_issue_tokens($pdo, (string) $row['grant_id'], (int) $row['user_id'], (string) $row['client_id'], (string) $row['scope'], (string) $row['resource']);
    if ($tokens === null) {
        return $invalid();
    }

    allstat_mcp_oauth_client_touch($pdo, (string) $row['client_id']);

    return ['status' => 200, 'body' => $tokens];
}

/**
 * Jádro token endpointu (bez HTTP): vrací [status, body].
 *
 * @return array{status: int, body: array}
 */
function allstat_mcp_oauth_token_request(PDO $pdo, array $params): array
{
    $grantType = allstat_mcp_oauth_str($params, 'grant_type', 64);

    if ($grantType === '') {
        return allstat_mcp_oauth_err('invalid_request', 'Chybí parametr grant_type.');
    }

    return match ($grantType) {
        'authorization_code' => allstat_mcp_oauth_grant_code($pdo, $params),
        'refresh_token' => allstat_mcp_oauth_grant_refresh($pdo, $params),
        default => allstat_mcp_oauth_err('unsupported_grant_type', 'Podporované typy jsou authorization_code a refresh_token.'),
    };
}

/** RFC 7009: refresh token odvolá celé připojení, access token jen sebe. Neznámý token se tiše ignoruje. */
function allstat_mcp_oauth_revoke_token(PDO $pdo, string $token, string $clientId = ''): void
{
    if ($token === '' || strlen($token) > 512) {
        return;
    }

    $row = allstat_fetch_one($pdo, "SELECT id, kind, grant_id, client_id FROM allstat_mcp_tokens WHERE token_hash = ? AND kind IN ('access', 'refresh')", [allstat_mcp_oauth_hash($token)]);
    if (!$row) {
        return;
    }

    if ($clientId !== '' && !hash_equals((string) $row['client_id'], $clientId)) {
        return;
    }

    if ($row['kind'] === 'refresh') {
        allstat_mcp_revoke_grant($pdo, (string) $row['grant_id']);

        return;
    }

    $pdo->prepare('DELETE FROM allstat_mcp_tokens WHERE id = ?')->execute([(int) $row['id']]);
}

// ---------------------------------------------------------------------------------------------------------------
// Ověření Bearer tokenu pro /mcp
// ---------------------------------------------------------------------------------------------------------------

/** Hodnota hlavičky Authorization ze všech míst, kam ji server může uložit ('' když chybí). */
function allstat_mcp_oauth_authorization_header(): string
{
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
        if (!empty($_SERVER[$key]) && is_string($_SERVER[$key])) {
            return $_SERVER[$key];
        }
    }

    if (function_exists('getallheaders')) {
        foreach ((array) getallheaders() as $name => $value) {
            if (strcasecmp((string) $name, 'authorization') === 0 && is_string($value)) {
                return $value;
            }
        }
    }

    return '';
}

/** Bearer token z hlavičky Authorization nebo null (jiné schéma se bere jako "žádný token"). */
function allstat_mcp_oauth_bearer_token(): ?string
{
    $header = allstat_mcp_oauth_authorization_header();

    if ($header !== '' && preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $match) === 1) {
        return $match[1];
    }

    return null;
}

/**
 * Ověří Bearer token aktuálního požadavku (při KAŽDÉM požadavku znovu: token, připojení i aktivního administrátora).
 *
 * Úspěch: ['ok' => true, 'user' => ['id', 'email', 'name'], 'grant_id', 'client_id', 'client_name', 'scope'].
 * Neúspěch: ['ok' => false, 'status' => 401|403|429, 'error', 'description', 'token_sent'].
 */
function allstat_mcp_authenticate(PDO $pdo): array
{
    $fail = static fn (int $status, string $error, string $description, bool $sent): array => [
        'ok' => false,
        'status' => $status,
        'error' => $error,
        'description' => $description,
        'token_sent' => $sent,
    ];

    $token = allstat_mcp_oauth_bearer_token();
    if ($token === null) {
        return $fail(401, 'invalid_token', 'Chybí přístupový token. Připojte AllStat v aplikaci přes přihlášení (OAuth).', false);
    }

    if (!allstat_mcp_is_enabled($pdo)) {
        return $fail(401, 'invalid_token', 'Připojení AI je v AllStatu vypnuté.', true);
    }

    // Neplatné tokeny se počítají (60 za minutu z jedné adresy); platný token nikdy neblokuje.
    $invalid = static function (string $description) use ($pdo, $fail): array {
        if (!allstat_mcp_rate_hit($pdo, 'bearer_fail:' . allstat_mcp_client_ip(), 60, 60)) {
            return $fail(429, 'rate_limited', 'Příliš mnoho neplatných tokenů z této adresy, zkuste to za chvíli.', true);
        }

        return $fail(401, 'invalid_token', $description, true);
    };

    if (strlen($token) > 512 || strncmp($token, 'asa_', 4) !== 0 || preg_match('~^[A-Za-z0-9_-]+$~', $token) !== 1) {
        return $invalid('Přístupový token není platný.');
    }

    $row = allstat_fetch_one(
        $pdo,
        "SELECT t.id AS token_id, t.grant_id, t.client_id, t.user_id, t.scope, t.resource, t.expires_at, t.last_used_at AS token_last_used,
                g.client_name, g.revoked_at, g.created_at AS grant_created, g.last_used_at AS grant_last_used,
                u.email AS user_email, u.name AS user_name, u.role AS user_role, u.is_active AS user_active
         FROM allstat_mcp_tokens t
         LEFT JOIN allstat_mcp_grants g ON g.grant_id = t.grant_id
         LEFT JOIN allstat_users u ON u.id = t.user_id
         WHERE t.token_hash = ? AND t.kind = 'access'",
        [allstat_mcp_oauth_hash($token)]
    );

    if (!$row) {
        return $invalid('Přístupový token není platný.');
    }

    $now = allstat_mcp_oauth_now();

    if ((string) $row['expires_at'] <= $now) {
        return $invalid('Přístupový token vypršel.');
    }

    if ($row['client_name'] === null || $row['revoked_at'] !== null) {
        return $invalid('Přístup byl odvolán. Připojte AllStat v aplikaci znovu.');
    }

    // Absolutní životnost připojení: 180 dní od souhlasu, bez ohledu na to, jak často se tokeny obnovovaly.
    if (allstat_mcp_oauth_grant_end((string) $row['grant_created']) <= time()) {
        allstat_mcp_revoke_grant($pdo, (string) $row['grant_id']);

        return $invalid('Platnost připojení vypršela (nejdéle 180 dní od souhlasu). Připojte AllStat v aplikaci znovu.');
    }

    if (!allstat_mcp_oauth_resource_matches($pdo, (string) $row['resource'])) {
        return $invalid('Token nepatří k tomuto serveru.');
    }

    if (!in_array(allstat_mcp_oauth_scope(), preg_split('/\s+/', trim((string) $row['scope'])) ?: [], true)) {
        return $fail(403, 'insufficient_scope', 'Token nemá požadovaný scope allstat.read.', true);
    }

    if ($row['user_email'] === null || (int) $row['user_active'] !== 1 || (string) $row['user_role'] !== 'admin') {
        allstat_mcp_revoke_user($pdo, (int) $row['user_id']);

        return $fail(403, 'insufficient_scope', 'Účet už nemá oprávnění administrátora AllStatu, přístup byl odvolán.', true);
    }

    // Poslední použití se zapisuje nejvýš jednou za 5 minut.
    $threshold = allstat_mcp_oauth_now(-300);
    try {
        if ($row['token_last_used'] === null || (string) $row['token_last_used'] < $threshold) {
            $pdo->prepare('UPDATE allstat_mcp_tokens SET last_used_at = ? WHERE id = ?')->execute([$now, (int) $row['token_id']]);
        }

        if ($row['grant_last_used'] === null || (string) $row['grant_last_used'] < $threshold) {
            $pdo->prepare('UPDATE allstat_mcp_grants SET last_used_at = ? WHERE grant_id = ?')->execute([$now, (string) $row['grant_id']]);
        }
    } catch (Throwable) {
        // informativní údaj, nesmí shodit požadavek
    }

    return [
        'ok' => true,
        'user' => ['id' => (int) $row['user_id'], 'email' => (string) $row['user_email'], 'name' => (string) $row['user_name']],
        'grant_id' => (string) $row['grant_id'],
        'client_id' => (string) $row['client_id'],
        'client_name' => (string) $row['client_name'],
        'scope' => (string) $row['scope'],
    ];
}

/** Odešle 401 / 403 / 429 s WWW-Authenticate (RFC 6750 + RFC 9728) a JSON tělem. */
function allstat_mcp_send_auth_error(PDO $pdo, array $failure): never
{
    $status = (int) ($failure['status'] ?? 401);
    if (!in_array($status, [401, 403, 429], true)) {
        $status = 401;
    }

    $description = (string) ($failure['description'] ?? '');
    $tokenSent = !empty($failure['token_sent']);
    $error = match ($status) {
        403 => 'insufficient_scope',
        429 => 'rate_limited',
        default => 'invalid_token',
    };

    $headers = ['Cache-Control' => 'no-store'];

    if ($status === 429) {
        $headers['Retry-After'] = '60';
    } else {
        $params = [];
        $ascii = allstat_mcp_oauth_ascii($description);

        if ($status === 403 || $tokenSent) {
            $params[] = 'error="' . $error . '"';
            if ($ascii !== '') {
                $params[] = 'error_description="' . $ascii . '"';
            }
        }

        $host = allstat_mcp_host_url($pdo);
        if ($status === 403) {
            $params[] = 'scope="' . allstat_mcp_oauth_scope() . '"';
        }

        if ($host !== '') {
            $params[] = 'resource_metadata="' . $host . '/.well-known/oauth-protected-resource/mcp"';
        }

        if ($status !== 403) {
            $params[] = 'scope="' . allstat_mcp_oauth_scope() . '"';
        }

        $headers['WWW-Authenticate'] = 'Bearer ' . implode(', ', $params);
    }

    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && allstat_mcp_origin_allowed($pdo, $origin)) {
        $headers['Access-Control-Allow-Origin'] = $origin;
        $headers['Vary'] = 'Origin';
        $headers['Access-Control-Expose-Headers'] = 'WWW-Authenticate';
    }

    allstat_mcp_json_response($status, ['error' => $error, 'error_description' => $description], $headers);
}

// ---------------------------------------------------------------------------------------------------------------
// Správa: připojení, odvolání, úklid, log
// ---------------------------------------------------------------------------------------------------------------

/** Odvolá připojení: označí ho a smaže všechny jeho kódy a tokeny. */
function allstat_mcp_revoke_grant(PDO $pdo, string $grantId): void
{
    if ($grantId === '') {
        return;
    }

    allstat_mcp_oauth_atomic($pdo, static function () use ($pdo, $grantId): void {
        $pdo->prepare('UPDATE allstat_mcp_grants SET revoked_at = ? WHERE grant_id = ? AND revoked_at IS NULL')->execute([allstat_mcp_oauth_now(), $grantId]);
        $pdo->prepare('DELETE FROM allstat_mcp_tokens WHERE grant_id = ?')->execute([$grantId]);
    });
}

/** Odvolá všechna připojení jednoho uživatele (deaktivace, smazání, ztráta role admin). */
function allstat_mcp_revoke_user(PDO $pdo, int $userId): void
{
    allstat_mcp_oauth_atomic($pdo, static function () use ($pdo, $userId): void {
        $pdo->prepare('UPDATE allstat_mcp_grants SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL')->execute([allstat_mcp_oauth_now(), $userId]);
        $pdo->prepare('DELETE FROM allstat_mcp_tokens WHERE user_id = ?')->execute([$userId]);
    });
}

/** Odvolá všechna připojení (vypnutí MCP). */
function allstat_mcp_revoke_all(PDO $pdo): void
{
    allstat_mcp_oauth_atomic($pdo, static function () use ($pdo): void {
        $pdo->prepare('UPDATE allstat_mcp_grants SET revoked_at = ? WHERE revoked_at IS NULL')->execute([allstat_mcp_oauth_now()]);
        $pdo->exec('DELETE FROM allstat_mcp_tokens');
    });
}

/**
 * Aktivní připojení: neodvolaná s aspoň jedním platným tokenem. Sloupce: grant_id, user_id, user_email, user_name,
 * client_name, client_id, client_type (dcr|cimd|''), redirect_host, scope, created_at, last_used_at.
 */
function allstat_mcp_connections(PDO $pdo): array
{
    return allstat_fetch_all(
        $pdo,
        "SELECT g.grant_id, g.user_id, u.email AS user_email, u.name AS user_name, g.client_name, g.client_id,
                COALESCE(c.type, '') AS client_type, g.redirect_host, g.scope, g.created_at, g.last_used_at
         FROM allstat_mcp_grants g
         LEFT JOIN allstat_users u ON u.id = g.user_id
         LEFT JOIN allstat_mcp_clients c ON c.client_id = g.client_id
         WHERE g.revoked_at IS NULL
           AND EXISTS (SELECT 1 FROM allstat_mcp_tokens t WHERE t.grant_id = g.grant_id AND t.kind IN ('access', 'refresh') AND t.used_at IS NULL AND t.expires_at > ?)
         ORDER BY g.created_at DESC, g.grant_id",
        [allstat_mcp_oauth_now()]
    );
}

/**
 * Úklid: prošlé kódy a tokeny, staré odvolané a mrtvé záznamy, log starší 90 dní (zamítnuté požadavky už po 7 dnech),
 * okna limiteru, nepoužití klienti. Velké tabulky se mažou po dávkách. Nevyhazuje výjimky.
 */
function allstat_mcp_purge(PDO $pdo): void
{
    $run = static function (string $sql, array $params = []) use ($pdo): void {
        try {
            $pdo->prepare($sql)->execute($params);
        } catch (Throwable $exception) {
            error_log('allstat mcp purge: ' . $exception->getMessage());
        }
    };

    // Dávkové mazání (max. 20 dávek po 5000 řádcích): zaplavený log se tak vyčistí během několika běhů, ne jedním zámkem.
    $batched = static function (string $sql, array $params = []) use ($pdo): void {
        try {
            $statement = $pdo->prepare($sql . ' LIMIT 5000');

            for ($round = 0; $round < 20; $round++) {
                $statement->execute($params);

                if ($statement->rowCount() < 5000) {
                    break;
                }
            }
        } catch (Throwable $exception) {
            error_log('allstat mcp purge: ' . $exception->getMessage());
        }
    };

    $now = allstat_mcp_oauth_now();

    $batched('DELETE FROM allstat_mcp_tokens WHERE expires_at < ?', [$now]);
    $run('DELETE FROM allstat_mcp_tokens WHERE grant_id NOT IN (SELECT grant_id FROM allstat_mcp_grants) LIMIT 5000');
    $run('DELETE FROM allstat_mcp_grants WHERE revoked_at IS NOT NULL AND revoked_at < ? LIMIT 2000', [allstat_mcp_oauth_now(-30 * 86400)]);
    $run(
        'DELETE FROM allstat_mcp_grants WHERE revoked_at IS NULL AND created_at < ? AND NOT EXISTS (SELECT 1 FROM allstat_mcp_tokens t WHERE t.grant_id = allstat_mcp_grants.grant_id) LIMIT 2000',
        [allstat_mcp_oauth_now(-86400)]
    );
    $batched("DELETE FROM allstat_mcp_log WHERE status = 'denied' AND created_at < ?", [allstat_mcp_oauth_now(-7 * 86400)]);
    $batched('DELETE FROM allstat_mcp_log WHERE created_at < ?', [allstat_mcp_oauth_now(-90 * 86400)]);
    $batched('DELETE FROM allstat_mcp_ratelimit WHERE window_start < ?', [time() - 86400]);
    $run("DELETE FROM allstat_mcp_clients WHERE type = 'cimd' AND cache_until < ? LIMIT 2000", [allstat_mcp_oauth_now(-30 * 86400)]);

    try {
        allstat_mcp_oauth_purge_dcr_clients($pdo, 30, true);
        allstat_mcp_oauth_purge_dcr_clients($pdo, 180, false);
    } catch (Throwable $exception) {
        error_log('allstat mcp purge: ' . $exception->getMessage());
    }
}

/** Zbaví strukturu hodnot, které vypadají jako tajemství (podle klíče i podle podoby hodnoty). */
function allstat_mcp_oauth_redact_value(mixed $value, string $key = ''): mixed
{
    if (preg_match('/token|secret|password|authorization|code|key|hash/i', $key) === 1 && (is_string($value) || is_numeric($value))) {
        return '[skryto]';
    }

    if (is_array($value)) {
        $out = [];
        foreach ($value as $childKey => $child) {
            $out[$childKey] = allstat_mcp_oauth_redact_value($child, (string) $childKey);
        }

        return $out;
    }

    return is_string($value) ? allstat_mcp_oauth_redact($value) : $value;
}

/**
 * Ochrana proti zaplavení logu zamítnutými požadavky: má-li tabulka víc než 200000 řádků, další záznamy se stavem
 * "denied" se nezapisují (ostatní ano). Kontrola je levná: nejdřív rozsah id (dva zásahy do indexu), přesný počet
 * (omezený na 200001 řádků) jen když rozsah limit překračuje, a to nejvýš jednou za minutu (výsledek se drží v tabulce
 * limiteru). V rámci jednoho požadavku se rozhodnutí drží ve statické proměnné ($refresh = true ho přepočítá).
 */
function allstat_mcp_oauth_log_denied_allowed(PDO $pdo, bool $refresh = false): bool
{
    static $allowed = null;

    if ($allowed !== null && !$refresh) {
        return $allowed;
    }

    $allowed = true;

    try {
        $range = $pdo->query('SELECT MIN(id) AS lo, MAX(id) AS hi FROM allstat_mcp_log')->fetch();
        $span = $range && $range['hi'] !== null ? (int) $range['hi'] - (int) $range['lo'] + 1 : 0;

        if ($span > 200000) {
            $window = intdiv(time(), 60) * 60;
            $cached = $pdo->prepare("SELECT hits FROM allstat_mcp_ratelimit WHERE bucket = 'logcap' AND window_start = ?");
            $cached->execute([$window]);
            $value = $cached->fetchColumn();

            if ($value === false) {
                $exact = (int) $pdo->query('SELECT COUNT(*) FROM (SELECT 1 FROM allstat_mcp_log LIMIT 200001) counted')->fetchColumn();
                $value = $exact > 200000 ? 1 : 0;
                $pdo->prepare("INSERT IGNORE INTO allstat_mcp_ratelimit (bucket, window_start, hits) VALUES ('logcap', ?, ?)")->execute([$window, $value]);
            }

            $allowed = (int) $value === 0;
        }
    } catch (Throwable) {
        $allowed = true;
    }

    return $allowed;
}

/**
 * Zapíše záznam o požadavku na MCP endpoint. Klíče = sloupce tabulky allstat_mcp_log (created_at, ip a user_agent
 * se doplní samy); args_json může být pole nebo řetězec, hodnoty podobné tokenům se skryjí, text se zkrátí
 * na 2000 znaků. Záznamy "denied" se při zaplněném logu (> 200000 řádků) zahazují. Nikdy nevyhazuje výjimku.
 */
function allstat_mcp_log(PDO $pdo, array $entry): void
{
    try {
        $args = $entry['args_json'] ?? null;
        if (is_array($args)) {
            $args = json_encode(allstat_mcp_oauth_redact_value($args), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        if (is_string($args)) {
            $args = mb_substr(allstat_mcp_oauth_redact($args), 0, 2000);
        } else {
            $args = null;
        }

        $status = (string) ($entry['status'] ?? 'ok');
        if (!in_array($status, ['ok', 'error', 'denied'], true)) {
            $status = 'error';
        }

        if ($status === 'denied' && !allstat_mcp_oauth_log_denied_allowed($pdo)) {
            return;
        }

        $text = static fn (mixed $value, int $max): string => mb_substr(allstat_mcp_oauth_redact((string) $value), 0, $max);
        $nullable = static fn (mixed $value, int $max): ?string => ($value === null || $value === '') ? null : mb_substr(allstat_mcp_oauth_redact((string) $value), 0, $max);

        $pdo->prepare(
            'INSERT INTO allstat_mcp_log (created_at, user_id, grant_id, client_name, method, tool, args_json, status, error, duration_ms, response_bytes, ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            (string) ($entry['created_at'] ?? allstat_mcp_oauth_now()),
            isset($entry['user_id']) && $entry['user_id'] !== null ? (int) $entry['user_id'] : null,
            $nullable($entry['grant_id'] ?? null, 32),
            $text($entry['client_name'] ?? '', 190),
            $text($entry['method'] ?? '', 64),
            $nullable($entry['tool'] ?? null, 64),
            $args,
            $status,
            $nullable($entry['error'] ?? null, 255),
            max(0, (int) ($entry['duration_ms'] ?? 0)),
            max(0, (int) ($entry['response_bytes'] ?? 0)),
            $nullable($entry['ip'] ?? allstat_mcp_client_ip_full(), 45),
            $nullable($entry['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null), 190),
        ]);
    } catch (Throwable $exception) {
        error_log('allstat mcp log: ' . $exception->getMessage());
    }
}

/** Poslední záznamy logu (nejnovější první) s e-mailem a jménem uživatele. */
function allstat_mcp_recent_log(PDO $pdo, int $limit = 50): array
{
    $limit = max(1, min(500, $limit));

    return allstat_fetch_all(
        $pdo,
        'SELECT l.id, l.created_at, l.user_id, u.email AS user_email, u.name AS user_name, l.grant_id, l.client_name, l.method, l.tool,
                l.args_json, l.status, l.error, l.duration_ms, l.response_bytes, l.ip, l.user_agent
         FROM allstat_mcp_log l
         LEFT JOIN allstat_users u ON u.id = l.user_id
         ORDER BY l.id DESC
         LIMIT ' . $limit
    );
}

// ---------------------------------------------------------------------------------------------------------------
// Dokumenty s metadaty a obsluha tras MCP hostu
// ---------------------------------------------------------------------------------------------------------------

/** Metadata autorizačního serveru (RFC 8414 i OIDC discovery, stejné tělo). */
function allstat_mcp_oauth_as_metadata(PDO $pdo): array
{
    $host = allstat_mcp_host_url($pdo);
    $app = allstat_mcp_app_url($pdo);

    return [
        'issuer' => $host,
        'authorization_endpoint' => $app . '/oauth/authorize.php',
        'token_endpoint' => $host . '/token',
        'registration_endpoint' => $host . '/register',
        'revocation_endpoint' => $host . '/revoke',
        'jwks_uri' => $host . '/jwks.json',
        'response_types_supported' => ['code'],
        'response_modes_supported' => ['query'],
        'grant_types_supported' => ['authorization_code', 'refresh_token'],
        'code_challenge_methods_supported' => ['S256'],
        'token_endpoint_auth_methods_supported' => ['none'],
        'revocation_endpoint_auth_methods_supported' => ['none'],
        'scopes_supported' => [allstat_mcp_oauth_scope()],
        'subject_types_supported' => ['public'],
        'id_token_signing_alg_values_supported' => ['RS256'],
        // CIMD potřebuje cURL (bezpečné stažení dokumentu klienta); bez něj se klienti registrují přes DCR.
        'client_id_metadata_document_supported' => function_exists('curl_init'),
        'authorization_response_iss_parameter_supported' => true,
        'service_documentation' => $app . '/admin/mcp.php',
    ];
}

/** Metadata chráněného prostředku (RFC 9728). */
function allstat_mcp_oauth_prm_metadata(PDO $pdo): array
{
    $host = allstat_mcp_host_url($pdo);

    return [
        'resource' => $host . '/mcp',
        'authorization_servers' => [$host],
        'scopes_supported' => [allstat_mcp_oauth_scope()],
        'bearer_methods_supported' => ['header'],
        'resource_name' => 'AllStat (jen čtení)',
        'resource_documentation' => allstat_mcp_app_url($pdo) . '/admin/mcp.php',
    ];
}

/** Odpověď pro well-known dokumenty: vždy přímé 200 (žádné přesměrování), GET / HEAD / OPTIONS. */
function allstat_mcp_oauth_wellknown_reply(array $document): never
{
    allstat_mcp_oauth_require_method(['GET', 'HEAD']);
    allstat_mcp_json_response(200, $document, [
        'Cache-Control' => 'public, max-age=300',
        'Access-Control-Allow-Origin' => '*',
    ]);
}

function allstat_mcp_oauth_handle_as_metadata(PDO $pdo): never
{
    allstat_mcp_oauth_wellknown_reply(allstat_mcp_oauth_as_metadata($pdo));
}

function allstat_mcp_oauth_handle_prm(PDO $pdo): never
{
    allstat_mcp_oauth_wellknown_reply(allstat_mcp_oauth_prm_metadata($pdo));
}

/** JWKS: prázdná sada (metadata OIDC vyžadují jwks_uri, ID tokeny nevydáváme). */
function allstat_mcp_oauth_handle_jwks(PDO $pdo): never
{
    allstat_mcp_oauth_wellknown_reply(['keys' => []]);
}

function allstat_mcp_oauth_handle_register(PDO $pdo): never
{
    allstat_mcp_oauth_require_method(['POST']);

    if (!allstat_mcp_rate_hit($pdo, 'register:' . allstat_mcp_client_ip(), 20, 3600)) {
        allstat_mcp_oauth_reply(429, ['error' => 'slow_down', 'error_description' => 'Příliš mnoho registrací z této adresy, zkuste to později.'], ['Retry-After' => '3600']);
    }

    $params = allstat_mcp_oauth_request_params();
    if ($params === null || $params === []) {
        allstat_mcp_oauth_reply(400, ['error' => 'invalid_client_metadata', 'error_description' => 'Tělo požadavku musí být JSON s metadaty klienta (redirect_uris atd.).']);
    }

    $result = allstat_mcp_oauth_register_client($pdo, $params);
    allstat_mcp_oauth_reply($result['status'], $result['body'], $result['headers'] ?? []);
}

function allstat_mcp_oauth_handle_token(PDO $pdo): never
{
    allstat_mcp_oauth_require_method(['POST']);

    if (!allstat_mcp_rate_hit($pdo, 'token:' . allstat_mcp_client_ip(), 60, 60)) {
        allstat_mcp_oauth_reply(429, ['error' => 'slow_down', 'error_description' => 'Příliš mnoho požadavků, zkuste to za chvíli.'], ['Retry-After' => '60']);
    }

    $params = allstat_mcp_oauth_request_params();
    if ($params === null) {
        allstat_mcp_oauth_reply(400, ['error' => 'invalid_request', 'error_description' => 'Požadavek je příliš velký.']);
    }

    // Klienti, kteří posílají client_id v hlavičce Authorization: Basic (bez tajemství), se přijmou také.
    if (allstat_mcp_oauth_str($params, 'client_id', 512) === '' && preg_match('/^\s*Basic\s+(\S+)\s*$/i', allstat_mcp_oauth_authorization_header(), $match) === 1) {
        $decoded = base64_decode($match[1], true);
        if (is_string($decoded) && $decoded !== '') {
            $params['client_id'] = urldecode(explode(':', $decoded, 2)[0]);
        }
    }

    if (random_int(1, 25) === 1) {
        allstat_mcp_purge($pdo);
    }

    $result = allstat_mcp_oauth_token_request($pdo, $params);
    allstat_mcp_oauth_reply($result['status'], $result['body']);
}

function allstat_mcp_oauth_handle_revoke(PDO $pdo): never
{
    allstat_mcp_oauth_require_method(['POST']);

    if (!allstat_mcp_rate_hit($pdo, 'revoke:' . allstat_mcp_client_ip(), 60, 60)) {
        allstat_mcp_oauth_reply(429, ['error' => 'slow_down', 'error_description' => 'Příliš mnoho požadavků, zkuste to za chvíli.'], ['Retry-After' => '60']);
    }

    $params = allstat_mcp_oauth_request_params() ?? [];
    allstat_mcp_oauth_revoke_token($pdo, allstat_mcp_oauth_str($params, 'token', 512), allstat_mcp_oauth_str($params, 'client_id', 512));

    allstat_mcp_oauth_reply(200, []);
}

/** Starší klienti (2025-03-26) hledají /authorize na originu serveru: přesměrování na skutečný autorizační endpoint. */
function allstat_mcp_oauth_handle_authorize_alias(PDO $pdo): never
{
    allstat_mcp_oauth_require_method(['GET', 'HEAD']);

    $query = (string) preg_replace('/[^\x21-\x7E]/', '', (string) ($_SERVER['QUERY_STRING'] ?? ''));
    $target = allstat_mcp_app_url($pdo) . '/oauth/authorize.php' . ($query !== '' ? '?' . $query : '');

    http_response_code(302);
    header('Location: ' . $target);
    header('Cache-Control: no-store');
    exit;
}

/** Kořen hostu: jednořádkový text s adresou konektoru. */
function allstat_mcp_oauth_handle_root(PDO $pdo): never
{
    $method = allstat_mcp_oauth_require_method(['GET', 'HEAD']);
    $text = 'AllStat MCP server (jen pro čtení). Adresa konektoru pro Claude nebo ChatGPT: ' . allstat_mcp_resource_url($pdo) . "\n";

    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');

    if ($method === 'HEAD') {
        header('Content-Length: ' . strlen($text));
    } else {
        echo $text;
    }

    exit;
}

function allstat_mcp_oauth_handle_not_found(): never
{
    allstat_mcp_json_response(404, ['error' => 'not_found']);
}

// ---------------------------------------------------------------------------------------------------------------
// Směrování požadavků MCP hostu (volá front controller allstat-mcp/index.php)
// ---------------------------------------------------------------------------------------------------------------

/** Cesta požadavku bez dotazu: jednou dekódovaná, zdvojená lomítka sloučena. */
function allstat_mcp_oauth_request_path(): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

    if (preg_match('~^https?://[^/]+(/.*)?$~i', $uri, $match) === 1) {
        $uri = $match[1] ?? '/';
    }

    foreach (['?', '#'] as $delimiter) {
        $position = strpos($uri, $delimiter);
        if ($position !== false) {
            $uri = substr($uri, 0, $position);
        }
    }

    $path = (string) preg_replace('~/{2,}~', '/', rawurldecode($uri));

    return $path === '' ? '/' : $path;
}

/**
 * Určí trasu podle cesty. Malá/velká písmena se nerozlišují, koncové lomítko nevadí. Se základní cestou B (např.
 * /allstat-mcp u varianty s prefixem) platí: trasy pod B ({B}/mcp, {B}/.well-known/...) a přesné kořenové tvary
 * vkládající cestu (RFC 8414 / RFC 9728: /.well-known/oauth-authorization-server{B}, /.well-known/openid-configuration{B},
 * /.well-known/oauth-protected-resource{B}[/mcp]), na které kořenové .htaccess webu přesměruje jen tyto tvary.
 * Holé kořenové well-known adresy (bez B) patří jiné aplikaci a nikdy se neobsluhují.
 *
 * @return string root|mcp|token|register|revoke|authorize|jwks|as_metadata|prm|not_found
 */
function allstat_mcp_oauth_route(PDO $pdo, ?string $path = null): string
{
    $path = strtolower(rtrim($path ?? allstat_mcp_oauth_request_path(), '/'));
    $base = strtolower(allstat_mcp_oauth_host_base_path($pdo));

    if ($base !== '') {
        if (in_array($path, ['/.well-known/oauth-authorization-server' . $base, '/.well-known/openid-configuration' . $base], true)) {
            return 'as_metadata';
        }

        if (in_array($path, ['/.well-known/oauth-protected-resource' . $base, '/.well-known/oauth-protected-resource' . $base . '/mcp'], true)) {
            return 'prm';
        }

        if ($path === $base) {
            $path = '';
        } elseif (str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base));
        } else {
            return 'not_found';
        }
    }

    $fixed = [
        '' => 'root',
        '/mcp' => 'mcp',
        '/token' => 'token',
        '/register' => 'register',
        '/revoke' => 'revoke',
        '/authorize' => 'authorize',
        '/jwks.json' => 'jwks',
    ];

    if (isset($fixed[$path])) {
        return $fixed[$path];
    }

    $prefixes = [
        '/.well-known/oauth-authorization-server' => 'as_metadata',
        '/.well-known/openid-configuration' => 'as_metadata',
        '/.well-known/oauth-protected-resource' => 'prm',
    ];

    foreach ($prefixes as $prefix => $route) {
        if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
            return $route;
        }
    }

    return 'not_found';
}

/** Obslouží trasu OAuth (vše kromě `mcp`); předpokládá zapnuté MCP. */
function allstat_mcp_oauth_dispatch(PDO $pdo, string $route): never
{
    switch ($route) {
        case 'root':
            allstat_mcp_oauth_handle_root($pdo);
        case 'token':
            allstat_mcp_oauth_handle_token($pdo);
        case 'register':
            allstat_mcp_oauth_handle_register($pdo);
        case 'revoke':
            allstat_mcp_oauth_handle_revoke($pdo);
        case 'authorize':
            allstat_mcp_oauth_handle_authorize_alias($pdo);
        case 'jwks':
            allstat_mcp_oauth_handle_jwks($pdo);
        case 'as_metadata':
            allstat_mcp_oauth_handle_as_metadata($pdo);
        case 'prm':
            allstat_mcp_oauth_handle_prm($pdo);
        default:
            allstat_mcp_oauth_handle_not_found();
    }
}
