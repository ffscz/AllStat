<?php

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_layout.php';

// Serverová část AI konektoru (OAuth server + MCP server) je samostatný balík souborů. Stránka načítá jen to,
// co na serveru existuje, takže se vykreslí i před jeho nasazením a při nedokončeném nahrávání přes FTP.
$mcpLoadErrors = [];
foreach (['mcp-oauth.php', 'mcp-server.php', 'mcp-tools.php'] as $mcpLibFile) {
    $mcpLibPath = __DIR__ . '/../lib/' . $mcpLibFile;
    if (!is_file($mcpLibPath)) {
        continue;
    }
    try {
        require_once $mcpLibPath;
    } catch (Throwable $exception) {
        $mcpLoadErrors[] = 'lib/' . $mcpLibFile . ': ' . $exception->getMessage();
        error_log('AllStat MCP admin: ' . $mcpLibFile . ': ' . $exception->getMessage());
    }
}
unset($mcpLibFile, $mcpLibPath);

/** Nejvyšší délka kontextu pro AI u jednoho webu (stejný limit má pole v instalačním průvodci). */
const ALLSTAT_AI_CONTEXT_MAX = 4000;

/** localhost, 127.0.0.1 a ::1 jsou jediní hostitelé, kde je povolené http:// (lokální vývoj a testy). */
function allstat_mcp_admin_is_local_host(string $host): bool
{
    $host = strtolower(trim($host, '[]'));

    return $host === 'localhost' || $host === '127.0.0.1' || $host === '::1';
}

/**
 * True, když IP adresa není použitelná veřejná adresa: privátní, loopback, link-local, CGNAT, dokumentační, multicast
 * a další rezervované rozsahy. Výjimka: 127.0.0.1 a ::1 (lokální test). IPv6 se povoluje jen z globálního rozsahu
 * 2000::/3 (bez dokumentačních, Teredo a 6to4 bloků), IPv4 zapsaná v IPv6 (::ffff:a.b.c.d) se posuzuje jako IPv4.
 */
function allstat_mcp_admin_ip_is_blocked(string $ip): bool
{
    if ($ip === '127.0.0.1' || $ip === '::1') {
        return false;
    }

    $packed = @inet_pton($ip);
    if ($packed === false) {
        return true;
    }

    if (strlen($packed) === 4) {
        $number = ip2long($ip);
        if ($number === false) {
            return true;
        }
        $number &= 0xFFFFFFFF;
        foreach ([
            ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16], ['172.16.0.0', 12],
            ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.88.99.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15],
            ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
        ] as [$base, $bits]) {
            $mask = (0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF;
            if (($number & $mask) === (ip2long($base) & $mask)) {
                return true;
            }
        }

        return false;
    }

    $bytes = array_values(unpack('C*', $packed));
    if (array_sum(array_slice($bytes, 0, 10)) === 0 && $bytes[10] === 0xFF && $bytes[11] === 0xFF) {
        return allstat_mcp_admin_ip_is_blocked(implode('.', array_slice($bytes, 12, 4)));
    }
    if (($bytes[0] & 0xE0) !== 0x20) {
        return true; // mimo 2000::/3: fc00::/7, fe80::/10, ff00::/8, ::, ::1 a další
    }
    $prefix32 = ($bytes[0] << 24) | ($bytes[1] << 16) | ($bytes[2] << 8) | $bytes[3];

    return $prefix32 === 0x20010DB8 || $prefix32 === 0x20010000 || (($bytes[0] << 8) | $bytes[1]) === 0x2002;
}

/**
 * Ověří hostitele z adresy (malými písmeny, IPv6 v hranatých závorkách). Kontrola nastavení posílá z serveru požadavky
 * na tuto adresu, proto IP literál nesmí být z privátních ani rezervovaných rozsahů, číselné zápisy IP (2130706433,
 * 0x7f000001) se odmítají a ostatní názvy musí být plné názvy s doménou. Vrací chybu nebo null.
 */
function allstat_mcp_admin_host_error(string $hostName, string $label): ?string
{
    $bare = trim($hostName, '[]');

    if (str_starts_with($hostName, '[')) {
        if (filter_var($bare, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return t('{label}: IPv6 adresa „{host}“ není platná.', ['label' => $label, 'host' => $hostName]);
        }
    } elseif (preg_match('/^[0-9.]+$/', $bare) === 1) {
        if (filter_var($bare, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return t('{label}: IP adresa „{ip}“ není platná. Zapište ji ve tvaru a.b.c.d (čísla 0 až 255 bez úvodních nul).', ['label' => $label, 'ip' => $bare]);
        }
    } else {
        if (filter_var($bare, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return t('{label}: název serveru „{host}“ není platný.', ['label' => $label, 'host' => $hostName]);
        }
        if ($bare === 'localhost') {
            return null;
        }
        $labels = explode('.', $bare);
        $tld = (string) end($labels);
        if (count($labels) < 2 || preg_match('/^(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/', $tld) !== 1) {
            return t('{label}: název serveru „{host}“ musí být plný název s doménou (například allstat.example.cz) nebo IP adresa ve tvaru a.b.c.d. Číselné zápisy IP adres (například 2130706433 nebo 0x7f000001) nejsou povolené.', ['label' => $label, 'host' => $hostName]);
        }
        if ($tld === 'localhost') {
            return t('{label}: názvy končící na .localhost nejsou povolené, použijte localhost nebo 127.0.0.1.', ['label' => $label]);
        }

        return null;
    }

    if (allstat_mcp_admin_ip_is_blocked($bare)) {
        return t('{label}: adresa {ip} je privátní, lokální nebo rezervovaná IP adresa. Zadejte veřejný název serveru nebo veřejnou IP adresu (výjimkou je jen localhost a 127.0.0.1 pro lokální test).', ['label' => $label, 'ip' => $bare]);
    }

    return null;
}

/**
 * Ověří základní adresu z formuláře (MCP server, AllStat) a sjednotí schéma a hostitele na malá písmena.
 * Pravidla: http(s) s hostitelem, bez přihlašovacích údajů, parametrů, kotvy a lomítka na konci;
 * https je povinné, http jen pro localhost a 127.0.0.1. Vrací [normalizovaná adresa, chyba nebo null].
 *
 * @return array{0: string, 1: ?string}
 */
function allstat_mcp_admin_check_base_url(string $value, string $label): array
{
    $value = trim($value);
    if ($value === '') {
        return ['', null];
    }
    if (strlen($value) > 255 || preg_match('/[\s\x00-\x1f\x7f]/', $value) === 1) {
        return [$value, t('{label}: adresa je příliš dlouhá nebo obsahuje mezery či nepovolené znaky.', ['label' => $label])];
    }
    if (str_contains($value, '?') || str_contains($value, '#')) {
        return [$value, t('{label} nesmí obsahovat parametry (za znakem ?) ani kotvu (za znakem #).', ['label' => $label])];
    }

    $parts = parse_url($value);
    if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
        return [$value, t('{label} není platná. Zadejte ji celou, včetně https://, například https://allstat.example.cz.', ['label' => $label])];
    }

    $scheme = strtolower((string) $parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return [$value, t('{label} musí začínat https:// (při lokálním testu smí být http://).', ['label' => $label])];
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return [$value, t('{label} nesmí obsahovat uživatelské jméno ani heslo.', ['label' => $label])];
    }

    $hostName = strtolower((string) $parts['host']);
    $hostError = allstat_mcp_admin_host_error($hostName, $label);
    if ($hostError !== null) {
        return [$value, $hostError];
    }
    if ($scheme === 'http' && !allstat_mcp_admin_is_local_host($hostName)) {
        return [$value, t('{label} musí používat https://. Protokol http:// je povolený jen pro localhost a 127.0.0.1.', ['label' => $label])];
    }
    if (isset($parts['port']) && ((int) $parts['port'] < 1 || (int) $parts['port'] > 65535)) {
        return [$value, t('{label}: číslo portu není platné.', ['label' => $label])];
    }

    $path = (string) ($parts['path'] ?? '');
    if ($path !== '' && str_ends_with($path, '/')) {
        return [$value, t('{label} nesmí končit lomítkem. Odeberte lomítko na konci adresy.', ['label' => $label])];
    }
    if ($path !== '' && preg_match('#^(?:/[A-Za-z0-9._~-]+)+$#', $path) !== 1) {
        return [$value, t('{label}: cesta v adrese obsahuje nepovolené znaky (povolená jsou písmena, číslice, tečka, pomlčka, podtržítko a vlnovka).', ['label' => $label])];
    }
    foreach (explode('/', $path) as $segment) {
        if ($segment === '.' || $segment === '..') {
            return [$value, t('{label}: cesta v adrese nesmí obsahovat úseky „.“ ani „..“.', ['label' => $label])];
        }
    }

    $port = isset($parts['port']) ? (int) $parts['port'] : null;
    if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
        $port = null; // výchozí port se neukládá, server pracuje s kanonickou podobou bez něj
    }

    return [$scheme . '://' . $hostName . ($port !== null ? ':' . $port : '') . $path, null];
}

/**
 * Zpracuje pole „Další povolené návratové adresy“: jedna PŘESNÁ adresa na řádek. Pravidla jsou přísná, protože se adresy
 * porovnávají doslova a hostitel se dostává i do hlaviček: https://server[:port]/cesta, bez parametrů a kotvy, bez mezer
 * a znaků ; , ' " a podobných, server jen malými písmeny a-z, číslicemi, tečkou a pomlčkou (nebo IPv6 v hranatých
 * závorkách), http:// jen pro localhost, 127.0.0.1 a [::1]. Chyba na kterémkoli řádku znamená odmítnutí celého uložení.
 *
 * @return array{0: list<string>, 1: list<string>} [seznam adres, chyby]
 */
function allstat_mcp_admin_check_redirect_uris(string $raw): array
{
    $list = [];
    $errors = [];
    $lines = preg_split('/\R/u', $raw);

    if ($lines === false) {
        return [[], [t('Další návratové adresy obsahují neplatné znaky (očekává se UTF-8).')]];
    }

    foreach ($lines as $index => $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $where = t('Další návratové adresy, řádek {line} („{value}“): ', ['line' => $index + 1, 'value' => mb_strimwidth($line, 0, 70, '...')]);
        if (strlen($line) > 512 || preg_match('/[\s\x00-\x1f\x7f]/', $line) === 1) {
            $errors[] = $where . t('adresa je příliš dlouhá nebo obsahuje mezery či ovládací znaky.');
            continue;
        }
        if (preg_match('/[;,\'"`\\\\<>*|^{}]/', $line) === 1) {
            $errors[] = $where . t('adresa obsahuje nepovolený znak (středník, čárku, uvozovky, zpětné lomítko, hvězdičku apod.).');
            continue;
        }
        if (str_contains($line, '?') || str_contains($line, '#')) {
            $errors[] = $where . t('návratová adresa nesmí obsahovat parametry (za znakem ?) ani kotvu (za znakem #).');
            continue;
        }
        if (preg_match('#^(https?)://([^/]*)(/.*)?$#', $line, $m) !== 1) {
            $errors[] = $where . t('musí začínat https:// (http:// jen pro localhost a 127.0.0.1), malými písmeny, například https://aplikace.example.com/oauth/callback.');
            continue;
        }
        $scheme = $m[1];
        $authority = $m[2];
        $path = $m[3] ?? '';

        if (str_contains($authority, '@')) {
            $errors[] = $where . t('adresa nesmí obsahovat uživatelské jméno ani heslo.');
            continue;
        }
        if (preg_match('/^(\[[0-9a-f:.]+\]|[a-z0-9.-]+)(?::([0-9]{1,5}))?$/', $authority, $am) !== 1) {
            $errors[] = $where . t('název serveru smí obsahovat jen malá písmena bez diakritiky, číslice, tečky a pomlčky (nebo IPv6 v hranatých závorkách), za ním volitelně :port.');
            continue;
        }

        $hostName = $am[1];
        $port = isset($am[2]) && $am[2] !== '' ? (int) $am[2] : null;
        if ($hostName[0] === '[') {
            if (filter_var(trim($hostName, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                $errors[] = $where . t('IPv6 adresa v hranatých závorkách není platná.');
                continue;
            }
        } else {
            $labelsOk = strlen($hostName) <= 253;
            foreach (explode('.', $hostName) as $hostLabel) {
                if ($hostLabel === '' || strlen($hostLabel) > 63 || str_starts_with($hostLabel, '-') || str_ends_with($hostLabel, '-')) {
                    $labelsOk = false;
                    break;
                }
            }
            if (!$labelsOk) {
                $errors[] = $where . t('název serveru není platný (prázdná část mezi tečkami, pomlčka na začátku či konci části, příliš dlouhý název).');
                continue;
            }
        }
        if ($port !== null && ($port < 1 || $port > 65535)) {
            $errors[] = $where . t('číslo portu není platné.');
            continue;
        }
        if ($scheme === 'http' && !allstat_mcp_admin_is_local_host($hostName)) {
            $errors[] = $where . t('http:// je povolené jen pro localhost, 127.0.0.1 a [::1], jinde použijte https://.');
            continue;
        }
        if ($path === '') {
            $errors[] = $where . t('adresa musí obsahovat cestu (například /oauth/callback).');
            continue;
        }
        if (preg_match('#^/[A-Za-z0-9._~%:@+=/-]*$#', $path) !== 1) {
            $errors[] = $where . t('cesta obsahuje nepovolené znaky (povolená jsou písmena bez diakritiky, číslice a znaky . _ ~ % : @ + = / -).');
            continue;
        }
        if (in_array('..', explode('/', $path), true) || in_array('.', explode('/', $path), true)) {
            $errors[] = $where . t('cesta nesmí obsahovat úseky „.“ ani „..“.');
            continue;
        }

        $list[$line] = $line;
    }

    if (count($list) > 20) {
        $errors[] = t('Další návratové adresy: zadejte nejvýše 20 řádků.');
    }

    return [array_values($list), $errors];
}

/**
 * Návrh pro Adresu AllStatu: schéma a hostitel z aktuálního požadavku plus cesta aplikace z konfigurace.
 * Hostitel z hlavičky se použije jen jako předvyplněný návrh (uloží se až po potvrzení formuláře).
 */
function allstat_mcp_admin_detect_app_url(array $config): string
{
    $hostHeader = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if (preg_match('/^(?:[A-Za-z0-9.-]+|\[[0-9A-Fa-f:]+\])(?::\d{1,5})?$/', $hostHeader) !== 1) {
        return '';
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

    return strtolower($scheme . '://' . $hostHeader) . allstat_base_path($config);
}

/** Uložená základní adresa v kanonické podobě, kterou používá i server (bez lomítka na konci a výchozího portu). */
function allstat_mcp_admin_base_url(PDO $pdo, string $key): string
{
    if ($key === 'mcp.host_url' && function_exists('allstat_mcp_host_url')) {
        return allstat_mcp_host_url($pdo);
    }
    if ($key === 'mcp.app_url' && function_exists('allstat_mcp_app_url')) {
        return allstat_mcp_app_url($pdo);
    }

    return rtrim(trim((string) allstat_setting($pdo, $key, '')), '/');
}

function allstat_mcp_admin_time(mixed $value, bool $seconds = false): string
{
    if (!is_string($value) || $value === '') {
        return '';
    }

    try {
        return (new DateTimeImmutable($value))->format(allstat_locale() === 'en' ? ($seconds ? 'j M H:i:s' : 'j M Y H:i') : ($seconds ? 'j. n. H:i:s' : 'j. n. Y H:i'));
    } catch (Throwable) {
        return '';
    }
}

/** Tlačítko „Zobrazit další řádky“ pro tabulky s víc než $visible řádky (vzor z admin/reports.php, obsluhuje admin.js). */
function allstat_mcp_admin_show_more(int $total, int $visible = 10): string
{
    $rest = $total - $visible;
    if ($rest <= 0) {
        return '';
    }
    $label = tn($rest, 'Zobrazit další 1 řádek', 'Zobrazit další {count} řádky', 'Zobrazit dalších {count} řádků');

    return '<div class="card-foot"><button type="button" class="btn btn-secondary btn-sm show-more-btn" data-show-more>' . h($label) . '</button></div>';
}

/**
 * Výsledek uložení kontextu pro AI. U jednoho webu se ukáže v jeho rozbaleném formuláři (stránka po uložení
 * skočí na něj, horní hláška by nebyla vidět), $text vrátí do pole neuložený text. Jinak běžná hláška nahoře.
 */
function allstat_mcp_admin_context_notice(int $domainId, string $type, string $message, ?string $text = null): void
{
    if ($domainId > 0) {
        $_SESSION['ai_context_notice'] = ['id' => $domainId, 'type' => $type, 'message' => $message, 'text' => $text];
        return;
    }
    allstat_flash($type, $message);
}

function allstat_mcp_admin_bytes(mixed $bytes): string
{
    $bytes = max(0, (int) $bytes);

    return $bytes < 1024 ? allstat_number($bytes) . ' B' : allstat_number($bytes / 1024, 1) . ' kB';
}

function allstat_mcp_admin_header_value(array $response, string $name): string
{
    $values = $response['headers'][strtolower($name)] ?? [];

    return implode(', ', array_map('strval', is_array($values) ? $values : [$values]));
}

/**
 * Pošle všechny požadavky souběžně (curl_multi): 8 s limit na požadavek, přesměrování se nesledují,
 * tělo odpovědi se čte nejvýše do 64 KiB. Každý požadavek: method, url, ua, headers, body.
 *
 * @param array<string, array<string, mixed>> $requests
 * @return array<string, array{status: int, headers: array<string, list<string>>, body: string, errno: int, error: string, ms: int, truncated: bool}>
 */
function allstat_mcp_admin_http_batch(array $requests): array
{
    $results = [];
    foreach ($requests as $key => $request) {
        $results[$key] = ['status' => 0, 'headers' => [], 'body' => '', 'errno' => 0, 'error' => '', 'ms' => 0, 'truncated' => false];
    }

    if (!function_exists('curl_init') || !function_exists('curl_multi_init')) {
        foreach ($results as $key => $unused) {
            $results[$key]['errno'] = -1;
            $results[$key]['error'] = t('Na serveru chybí PHP rozšíření cURL.');
        }

        return $results;
    }

    $multi = curl_multi_init();
    $handles = [];
    $caBundle = getenv('ALLSTAT_MCP_CAINFO'); // volitelné: svazek CA certifikátů pro https (stejná proměnná jako u serverové části)

    foreach ($requests as $key => $request) {
        $handle = curl_init((string) $request['url']);
        $headerLines = [];
        foreach ((array) ($request['headers'] ?? []) as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $options = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_USERAGENT => (string) ($request['ua'] ?? 'AllStat-Kontrola/1.0'),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$results, $key): int {
                $length = strlen($line);
                $line = trim($line);
                if ($line === '') {
                    return $length;
                }
                if (str_starts_with($line, 'HTTP/')) {
                    $results[$key]['headers'] = [];

                    return $length;
                }
                $colon = strpos($line, ':');
                if ($colon !== false) {
                    $results[$key]['headers'][strtolower(trim(substr($line, 0, $colon)))][] = trim(substr($line, $colon + 1));
                }

                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$results, $key): int {
                if (strlen($results[$key]['body']) >= 65536) {
                    $results[$key]['truncated'] = true;

                    return 0; // přeruší přenos, stav a hlavičky už máme
                }
                $results[$key]['body'] .= $chunk;

                return strlen($chunk);
            },
        ];

        if (is_string($caBundle) && $caBundle !== '' && is_file($caBundle)) {
            $options[CURLOPT_CAINFO] = $caBundle;
        }
        if (strtoupper((string) ($request['method'] ?? 'GET')) === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = (string) ($request['body'] ?? '');
        } else {
            $options[CURLOPT_HTTPGET] = true;
        }

        curl_setopt_array($handle, $options);
        curl_multi_add_handle($multi, $handle);
        $handles[$key] = $handle;
    }

    $running = 0;
    do {
        $code = curl_multi_exec($multi, $running);
    } while ($code === CURLM_CALL_MULTI_PERFORM);

    $deadline = microtime(true) + 14;
    $timedOut = false;
    while ($running > 0 && $code === CURLM_OK) {
        if (microtime(true) > $deadline) {
            $timedOut = true;
            break;
        }
        if (curl_multi_select($multi, 0.5) === -1) {
            usleep(20000);
        }
        do {
            $code = curl_multi_exec($multi, $running);
        } while ($code === CURLM_CALL_MULTI_PERFORM);
    }

    $codes = [];
    while (($message = curl_multi_info_read($multi)) !== false) {
        if (($message['msg'] ?? null) === CURLMSG_DONE) {
            $codes[spl_object_id($message['handle'])] = (int) $message['result'];
        }
    }

    foreach ($handles as $key => $handle) {
        $errno = $codes[spl_object_id($handle)] ?? ($timedOut ? 28 : 0);
        if ($errno === 23 && $results[$key]['truncated']) {
            $errno = 0; // vlastní přerušení po 64 KiB
        }
        $results[$key]['status'] = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $results[$key]['ms'] = (int) round(((float) curl_getinfo($handle, CURLINFO_TOTAL_TIME)) * 1000);
        $results[$key]['errno'] = $errno;
        $results[$key]['error'] = $errno !== 0 ? curl_strerror($errno) : '';
        curl_multi_remove_handle($multi, $handle);
    }

    return $results;
}

/**
 * True, když stránka souhlasu hlásí vypnuté připojení AI. Stránka se vykresluje v jazyce návštěvníka (zde cURL bez
 * cookie, tedy ve výchozím jazyce instalace), proto se hledá česká, slovenská i anglická podoba hlášky.
 */
function allstat_mcp_admin_body_has_disabled_notice(string $body): bool
{
    $needles = ['Připojení AI je vypnuté'];
    foreach (['sk', 'en'] as $locale) {
        $translated = allstat_i18n_dictionary($locale)['Připojení AI je vypnuté'] ?? '';
        if ($translated !== '') {
            $needles[] = $translated;
        }
    }
    foreach ($needles as $needle) {
        if (stripos($body, $needle) !== false) {
            return true;
        }
    }

    return false;
}

/** Selhání na úrovni spojení (DNS, TLS, časový limit). Vrací [popis, rada] nebo null, když server odpověděl. */
function allstat_mcp_admin_transport_problem(array $response): ?array
{
    if ((int) ($response['status'] ?? 0) > 0) {
        return null;
    }

    $errno = (int) ($response['errno'] ?? 0);
    $raw = (string) ($response['error'] ?? '');

    if ($errno === -1) {
        return [$raw, t('Požádejte hosting o zapnutí PHP rozšíření cURL, bez něj kontrolu nelze provést.')];
    }
    if ($errno === 6) {
        return [t('Doménu se nepodařilo přeložit (DNS).'), t('Zkontrolujte DNS záznam (A záznam subdomény) a překlepy v adrese. Pozor: některé hostingy se nedokážou dovolat samy na sebe, adresu pak ověřte v prohlížeči.')];
    }
    if ($errno === 7) {
        return [t('Nepodařilo se navázat spojení se serverem.'), t('Server na adrese neodpovídá. Zkontrolujte, že subdoména míří na správný server, že web běží a že adresa včetně portu je správně.')];
    }
    if ($errno === 28) {
        return [t('Server neodpověděl do 8 sekund.'), t('Zkontrolujte, že server běží. První volání po nahrání nové verze může trvat déle (spouští se migrace databáze), zkuste kontrolu za chvíli zopakovat.')];
    }
    if (in_array($errno, [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91], true)) {
        return [t('Chyba zabezpečeného spojení (SSL/TLS).'), t('Certifikát je neplatný, vypršel nebo nemá úplný řetězec. Claude i ChatGPT vyžadují platný certifikát od veřejné autority (například Let\'s Encrypt).')];
    }

    return [t('Spojení selhalo (cURL {detail}).', ['detail' => $errno . ($raw !== '' ? ': ' . $raw : '')]), t('Zkontrolujte adresu a dostupnost serveru.')];
}

/** Společné chybné stavy HTTP (přesměrování, 403, 404, 429, 5xx). Vrací [popis, rada] nebo null. */
function allstat_mcp_admin_status_problem(array $response): ?array
{
    $status = (int) ($response['status'] ?? 0);

    if ($status >= 300 && $status < 400) {
        $location = allstat_mcp_admin_header_value($response, 'location');

        return [$location !== '' ? t('Server přesměrovává (HTTP {status} na {location}).', ['status' => $status, 'location' => $location]) : t('Server přesměrovává (HTTP {status}).', ['status' => $status]), t('Klienti přesměrování u těchto adres nesledují. Uložte v nastavení přesnou konečnou adresu (https, s www nebo bez www podle skutečnosti) a na serveru přesměrování pro tyto cesty vypněte.')];
    }
    if ($status === 403) {
        return [t('Požadavek odmítl server (HTTP 403).'), t('Blokuje ho pravděpodobně firewall, WAF (například Cloudflare nebo ModSecurity) nebo pravidlo v .htaccess (bot filtr, Require). Přidejte výjimku pro adresy /mcp a /.well-known/.')];
    }
    if ($status === 404) {
        return [t('Adresa neexistuje (HTTP 404).'), t('Ověřte, že je připojení AI zapnuté (při vypnutí server záměrně odpovídá 404), že je nahraná složka allstat-mcp včetně .htaccess (mod_rewrite) a že Adresa MCP serveru odpovídá místu, kde složka skutečně běží.')];
    }
    if ($status === 429) {
        return [t('Server odmítl požadavek pro příliš mnoho volání (HTTP 429).'), t('Zkuste kontrolu za minutu zopakovat.')];
    }
    if ($status >= 500) {
        return [t('Chyba serveru (HTTP {status}).', ['status' => $status]), t('Podívejte se do error_log na hostingu. Časté příčiny: chybějící soubory lib/mcp-*.php, nedostupná databáze, PHP chyba.')];
    }

    return null;
}

/** Ověří JSON s metadaty (PRM, AS): stav 200, JSON a očekaná hodnota jednoho pole. Vrací [popis, rada] nebo null. */
function allstat_mcp_admin_verify_metadata(array $response, string $key, string $expected): ?array
{
    $problem = allstat_mcp_admin_transport_problem($response) ?? allstat_mcp_admin_status_problem($response);
    if ($problem !== null) {
        return $problem;
    }

    $status = (int) $response['status'];
    if ($status === 401) {
        return [t('Adresa vyžaduje přihlášení (HTTP 401).'), t('Metadata musí být veřejná. Zkontrolujte, že složku serveru nechrání Basic Auth z hostingu.')];
    }
    if ($status !== 200) {
        return [t('Neočekávaná odpověď (HTTP {status}, má být 200).', ['status' => $status]), t('Zkontrolujte, že adresu obsluhuje složka allstat-mcp.')];
    }

    $data = json_decode((string) $response['body'], true);
    if (!is_array($data)) {
        $type = allstat_mcp_admin_header_value($response, 'content-type');

        return [t('Odpověď není JSON (Content-Type: {type}).', ['type' => $type !== '' ? $type : t('neuveden')]), t('Adresu zřejmě obsluhuje jiná aplikace (například WordPress) místo složky allstat-mcp. Zkontrolujte, kam míří kořenový adresář subdomény a že v této složce funguje .htaccess (mod_rewrite).')];
    }

    $actual = $data[$key] ?? null;
    if ($actual !== $expected) {
        $shown = is_string($actual) ? $actual : (string) json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [t('Pole „{key}“ má hodnotu „{shown}“, očekává se „{expected}“.', ['key' => $key, 'shown' => $shown, 'expected' => $expected]), t('Adresa MCP serveru v nastavení se liší od adresy, na které server skutečně běží (nebo ji přepisuje proxy). Opravte nastavení tak, aby přesně odpovídalo adrese, kterou používá klient.')];
    }

    return null;
}

/**
 * Kontrola nastavení: požadavky, které pošle Claude nebo ChatGPT, jen s neplatným tokenem. Běží na serveru
 * AllStatu (cURL), takže neověří síť mezi klientem a serverem, ale zachytí většinu chyb konfigurace.
 *
 * @return list<array{title: string, target: string, status: string, detail: string, hint: string}>
 */
function allstat_mcp_admin_selfcheck(string $host, string $app): array
{
    $hostParts = parse_url($host);
    $hostPath = is_array($hostParts) ? (string) ($hostParts['path'] ?? '') : '';
    $hostOrigin = is_array($hostParts)
        ? (string) ($hostParts['scheme'] ?? 'https') . '://' . (string) ($hostParts['host'] ?? '') . (isset($hostParts['port']) ? ':' . $hostParts['port'] : '')
        : $host;
    $mcpUrl = $host . '/mcp';
    $prmUrl = $host . '/.well-known/oauth-protected-resource';
    $prmMcpUrl = $prmUrl . '/mcp';
    $asUrl = $host . '/.well-known/oauth-authorization-server';
    $oidcUrl = $host . '/.well-known/openid-configuration';
    $authorizeUrl = $app . '/oauth/authorize.php';
    $rootAsUrl = $hostPath !== '' ? $hostOrigin . '/.well-known/oauth-authorization-server' . $hostPath : '';

    $neutralUa = 'Mozilla/5.0 (compatible; AllStat-Kontrola/1.0)';
    $browserUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';
    $rpcBody = (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => new stdClass()]);
    $postHeaders = ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'];
    $mcpPost = static fn (string $ua, array $extraHeaders = []): array => [
        'method' => 'POST', 'url' => $mcpUrl, 'ua' => $ua, 'headers' => $postHeaders + $extraHeaders, 'body' => $rpcBody,
    ];
    $jsonGet = static fn (string $url): array => [
        'method' => 'GET', 'url' => $url, 'ua' => $neutralUa, 'headers' => ['Accept' => 'application/json'],
    ];

    $uaChecks = [
        'ua_claude' => ['Claude-User', t('Přístup s User-Agent Claude-User (Claude)')],
        'ua_httpx' => ['python-httpx/0.28.1', t('Přístup s User-Agent python-httpx (Claude, Python MCP SDK)')],
        'ua_openai' => ['openai-mcp/1.0.0', t('Přístup s User-Agent openai-mcp (ChatGPT)')],
    ];

    $requests = [
        'prm' => $jsonGet($prmUrl),
        'prm_mcp' => $jsonGet($prmMcpUrl),
        'as' => $jsonGet($asUrl),
        'oidc' => $jsonGet($oidcUrl),
        'mcp_anon' => $mcpPost($neutralUa),
        'mcp_bad' => $mcpPost($neutralUa, ['Authorization' => 'Bearer neplatny']),
        'authorize' => ['method' => 'GET', 'url' => $authorizeUrl, 'ua' => $browserUa, 'headers' => ['Accept' => 'text/html']],
    ];
    foreach ($uaChecks as $key => [$ua]) {
        $requests[$key] = $mcpPost($ua);
    }
    if ($rootAsUrl !== '') {
        $requests['root_as'] = $jsonGet($rootAsUrl);
    }

    $res = allstat_mcp_admin_http_batch($requests);
    $items = [];
    $add = static function (string $title, string $target, string $status, string $detail, string $hint = '') use (&$items): void {
        $items[] = ['title' => $title, 'target' => $target, 'status' => $status, 'detail' => $detail, 'hint' => $hint];
    };

    // 1. Metadata chráněného zdroje (RFC 9728): obě adresy, které klienti zkoušejí.
    $problem = null;
    foreach ([[$prmUrl, 'prm'], [$prmMcpUrl, 'prm_mcp']] as [$url, $key]) {
        $verdict = allstat_mcp_admin_verify_metadata($res[$key], 'resource', $mcpUrl);
        if ($verdict !== null) {
            $problem = ['GET ' . $url . ': ' . $verdict[0], $verdict[1]];
            break;
        }
    }
    $add(
        t('Metadata chráněného zdroje (protected resource)'),
        t('GET {url1} a {url2}', ['url1' => $prmUrl, 'url2' => $prmMcpUrl]),
        $problem === null ? 'ok' : 'error',
        $problem === null ? t('HTTP 200 na obou adresách, resource = {url}', ['url' => $mcpUrl]) : $problem[0],
        $problem === null ? '' : $problem[1]
    );

    // 2. a 3. Metadata autorizačního serveru (RFC 8414 a OpenID Connect discovery).
    foreach ([['as', $asUrl, t('Metadata autorizačního serveru (oauth-authorization-server)')], ['oidc', $oidcUrl, t('Metadata OpenID (openid-configuration)')]] as [$key, $url, $title]) {
        $verdict = allstat_mcp_admin_verify_metadata($res[$key], 'issuer', $host);
        $add(
            $title,
            'GET ' . $url,
            $verdict === null ? 'ok' : 'error',
            $verdict === null ? t('HTTP 200, issuer = {host}, bez přesměrování', ['host' => $host]) : $verdict[0],
            $verdict === null ? '' : $verdict[1]
        );
    }

    // 4. MCP endpoint bez tokenu: 401 + WWW-Authenticate s resource_metadata.
    $r = $res['mcp_anon'];
    $problem = allstat_mcp_admin_transport_problem($r) ?? allstat_mcp_admin_status_problem($r);
    $target = t('POST {url} (bez tokenu)', ['url' => $mcpUrl]);
    $title = t('MCP endpoint bez tokenu vrací 401 s odkazem na přihlášení');
    if ($problem !== null) {
        $add($title, $target, 'error', $problem[0], $problem[1]);
    } elseif ((int) $r['status'] !== 401) {
        $add(
            $title,
            $target,
            'error',
            t('Server odpověděl HTTP {status}, očekává se 401.', ['status' => (int) $r['status']]),
            (int) $r['status'] === 200
                ? t('Endpoint /mcp pustil požadavek bez tokenu. To je bezpečnostní chyba, bez tokenu musí vracet 401.')
                : t('Zkontrolujte, že adresu /mcp obsluhuje front controller ve složce allstat-mcp (index.php a .htaccess s mod_rewrite).')
        );
    } else {
        $authenticate = allstat_mcp_admin_header_value($r, 'www-authenticate');
        if (stripos($authenticate, 'resource_metadata') === false) {
            $add($title, $target, 'error', t('Odpověď 401 nemá v hlavičce WWW-Authenticate údaj resource_metadata.'), t('Bez něj klient neví, kde najít přihlášení. Hlavičku obvykle odstraňuje proxy nebo nastavení hostingu.'));
        } else {
            $advertised = preg_match('/resource_metadata="([^"]+)"/i', $authenticate, $m) === 1 ? $m[1] : '';
            if ($advertised !== '' && $advertised !== $prmMcpUrl) {
                $add($title, $target, 'warning', t('HTTP 401, ale resource_metadata míří na „{actual}“, očekává se „{expected}“.', ['actual' => $advertised, 'expected' => $prmMcpUrl]), t('Zkontrolujte Adresu MCP serveru v nastavení, klient bude metadata hledat na adrese z hlavičky.'));
            } else {
                $add($title, $target, 'ok', t('HTTP 401, WWW-Authenticate obsahuje resource_metadata.'));
            }
        }
    }

    // 5. Neplatný token: dokazuje, že hlavička Authorization dorazí do PHP.
    $r = $res['mcp_bad'];
    $problem = allstat_mcp_admin_transport_problem($r) ?? allstat_mcp_admin_status_problem($r);
    $target = 'POST ' . $mcpUrl . ' (Authorization: Bearer neplatny)';
    $title = t('Hlavička Authorization dorazí do PHP');
    if ($problem !== null) {
        $add($title, $target, 'error', $problem[0], $problem[1]);
    } elseif ((int) $r['status'] !== 401) {
        $add($title, $target, 'error', t('Server odpověděl HTTP {status}, očekává se 401 s chybou invalid_token.', ['status' => (int) $r['status']]), t('Zkontrolujte, že adresu /mcp obsluhuje front controller ve složce allstat-mcp.'));
    } else {
        $body = json_decode((string) $r['body'], true);
        $invalid = stripos(allstat_mcp_admin_header_value($r, 'www-authenticate'), 'invalid_token') !== false
            || (is_array($body) && ($body['error'] ?? null) === 'invalid_token');
        if ($invalid) {
            $add($title, $target, 'ok', t('HTTP 401 invalid_token: server hlavičku přijal a token vyhodnotil.'));
        } else {
            $add(
                $title,
                $target,
                'error',
                t('Server odpověděl 401, ale token nevyhodnotil jako neplatný (chybí invalid_token). Hlavička Authorization se pravděpodobně cestou ztrácí.'),
                t('Na Apache a LiteSpeed pomáhá v .htaccess složky allstat-mcp pravidlo RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}] (soubor ho obsahuje, ověřte že se nahrál a že je zapnutý mod_rewrite). U PHP-FPM a FastCGI někdy pomůže CGIPassAuth On.')
            );
        }
    }

    // 6. až 8. Požadavky s User-Agenty skutečných klientů: 401 = projdou, 403 = blokuje bot filtr nebo WAF.
    foreach ($uaChecks as $key => [$ua, $title]) {
        $r = $res[$key];
        $target = t('POST {url} (User-Agent: {ua})', ['url' => $mcpUrl, 'ua' => $ua]);
        $problem = allstat_mcp_admin_transport_problem($r);
        if ($problem !== null) {
            $add($title, $target, 'error', $problem[0], $problem[1]);
        } elseif ((int) $r['status'] === 401) {
            $add($title, $target, 'ok', t('HTTP 401, požadavek se dostal až do AllStatu.'));
        } elseif ((int) $r['status'] === 403) {
            $add($title, $target, 'error', t('Požadavek s tímto User-Agentem odmítl server (HTTP 403).'), t('Blokuje ho firewall, WAF (Cloudflare, ModSecurity) nebo bot filtr v .htaccess. Přidejte výjimku pro adresy /mcp a /.well-known/ (nebo pro celou složku allstat-mcp).'));
        } else {
            $other = allstat_mcp_admin_status_problem($r);
            $add($title, $target, 'error', $other !== null ? $other[0] : t('Server odpověděl HTTP {status}, očekává se 401.', ['status' => (int) $r['status']]), $other !== null ? $other[1] : t('Zkontrolujte, že adresu /mcp obsluhuje složka allstat-mcp.'));
        }
    }

    // 9. Přihlašovací a souhlasová stránka v AllStatu.
    $r = $res['authorize'];
    $status = (int) $r['status'];
    $target = 'GET ' . $authorizeUrl;
    $title = t('Přihlašovací a souhlasová stránka je dostupná');
    $problem = allstat_mcp_admin_transport_problem($r);
    if ($problem !== null) {
        $add($title, $target, 'error', $problem[0], $problem[1]);
    } elseif ($status === 404 && allstat_mcp_admin_body_has_disabled_notice((string) $r['body'])) {
        $add($title, $target, 'error', t('Stránka odpovídá, ale hlásí, že je připojení AI vypnuté (HTTP 404).'), t('Adresa AllStatu zřejmě míří na jinou instalaci AllStatu, kde je připojení AI vypnuté, nebo se nastavení ještě neuložilo. Zkontrolujte Adresu AllStatu.'));
    } elseif ($status === 404) {
        $add($title, $target, 'error', t('Soubor oauth/authorize.php na adrese neexistuje (HTTP 404).'), t('Nahrajte složku oauth/ z nové verze AllStatu a zkontrolujte Adresu AllStatu: musí končit cestou, na které běží tato administrace.'));
    } elseif ($status === 403) {
        $add($title, $target, 'error', t('Stránku odmítl server (HTTP 403).'), t('Blokuje ji firewall nebo pravidlo v .htaccess (například bot filtr). Uživatelé se v prohlížeči k přihlášení nedostanou.'));
    } elseif ($status >= 500) {
        $add($title, $target, 'error', t('Chyba serveru (HTTP {status}).', ['status' => $status]), t('Podívejte se do error_log na hostingu (složka oauth/ nebo lib/mcp-oauth.php).'));
    } elseif (in_array($status, [200, 301, 302, 303, 307, 308, 400, 401], true)) {
        $add($title, $target, 'ok', t('HTTP {status}, stránka odpovídá.', ['status' => $status]));
    } else {
        $add($title, $target, 'warning', t('Neočekávaná odpověď (HTTP {status}).', ['status' => $status]), t('Otevřete adresu v prohlížeči a ověřte, že se zobrazí stránka AllStatu.'));
    }

    // 10. HTTPS u obou adres (na localhostu jen upozornění).
    $httpsStatus = 'ok';
    $httpsLines = [];
    foreach ([[t('Adresa MCP serveru'), $host], [t('Adresa AllStatu'), $app]] as [$label, $address]) {
        $parts = parse_url($address);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $local = is_array($parts) && allstat_mcp_admin_is_local_host((string) ($parts['host'] ?? ''));
        if ($scheme === 'https') {
            $httpsLines[] = t('{label}: https', ['label' => $label]);
        } elseif ($local) {
            $httpsLines[] = t('{label}: http (lokální test)', ['label' => $label]);
            $httpsStatus = $httpsStatus === 'error' ? 'error' : 'warning';
        } else {
            $httpsLines[] = t('{label}: {scheme} (nedostačuje)', ['label' => $label, 'scheme' => $scheme !== '' ? $scheme : t('neznámé schéma')]);
            $httpsStatus = 'error';
        }
    }
    $add(
        t('Obě adresy používají HTTPS'),
        t('{host} a {app}', ['host' => $host, 'app' => $app]),
        $httpsStatus,
        implode('; ', $httpsLines),
        $httpsStatus === 'ok' ? '' : ($httpsStatus === 'warning'
            ? t('Na localhostu je http v pořádku pro vývoj. Claude a ChatGPT se ale k produkčnímu serveru připojí jen přes HTTPS.')
            : t('Claude a ChatGPT se připojí jen přes HTTPS. Nastavte adresy s https:// a zajistěte platný certifikát.'))
    );

    // 11. Kořenová metadata (RFC 8414 path insertion), když je server pod cestou.
    if ($rootAsUrl !== '') {
        $verdict = allstat_mcp_admin_verify_metadata($res['root_as'], 'issuer', $host);
        $add(
            t('Kořenová metadata pro server pod cestou (RFC 8414)'),
            'GET ' . $rootAsUrl,
            $verdict === null ? 'ok' : 'warning',
            $verdict === null ? t('HTTP 200, issuer = {host}', ['host' => $host]) : t('Na kořeni domény metadata nejsou dostupná: {detail}', ['detail' => $verdict[0]]),
            $verdict === null ? '' : t('Klienti dodržující RFC 8414 hledají metadata autorizačního serveru na kořeni domény s cestou na konci. Přidejte do .htaccess v kořeni webu pravidlo (rewrite, ne redirect), které tuto adresu obslouží složkou allstat-mcp, nebo použijte vlastní subdoménu.')
        );
    }

    return $items;
}

$pdo = allstat_admin_require_database($pdo, $config);
$user = allstat_require_user($pdo, $config);
allstat_require_admin($user);

$errors = [];
$formValues = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    allstat_csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_settings') {
        $wasEnabled = allstat_setting($pdo, 'mcp.enabled', '0') === '1';
        $oldHost = allstat_mcp_admin_base_url($pdo, 'mcp.host_url');
        $oldApp = allstat_mcp_admin_base_url($pdo, 'mcp.app_url');
        $oldExtra = trim((string) allstat_setting($pdo, 'mcp.extra_redirect_uris', ''));

        $wantEnabled = (string) ($_POST['mcp_enabled'] ?? '0') === '1';
        $hostRaw = (string) ($_POST['mcp_host_url'] ?? '');
        $appRaw = (string) ($_POST['mcp_app_url'] ?? '');
        $extraRaw = (string) ($_POST['mcp_extra_redirect_uris'] ?? '');
        $confirmOff = !empty($_POST['mcp_confirm_disable']);

        [$newHost, $hostError] = allstat_mcp_admin_check_base_url($hostRaw, t('Adresa MCP serveru'));
        [$newApp, $appError] = allstat_mcp_admin_check_base_url($appRaw, t('Adresa AllStatu'));
        [$extraList, $extraErrors] = allstat_mcp_admin_check_redirect_uris($extraRaw);
        $newExtra = implode("\n", $extraList);

        foreach ([$hostError, $appError] as $fieldError) {
            if ($fieldError !== null) {
                $errors[] = $fieldError;
            }
        }
        foreach ($extraErrors as $fieldError) {
            $errors[] = $fieldError;
        }
        if ($wantEnabled && ($newHost === '' || $newApp === '')) {
            $errors[] = t('Pro zapnutí připojení AI vyplňte Adresu MCP serveru i Adresu AllStatu.');
        }
        if ($wasEnabled && !$wantEnabled && !$confirmOff) {
            $errors[] = t('Vypnutí odpojí všechny připojené aplikace. Potvrďte ho zaškrtnutím políčka „Opravdu odpojit všechny aplikace“.');
        }

        // Vypnutí: nejdřív odpojit aplikace. Když to selže, zůstává zapnuto a nic se neuloží (stav zůstane konzistentní).
        if (!$errors && $wasEnabled && !$wantEnabled && function_exists('allstat_mcp_revoke_all')) {
            try {
                allstat_mcp_revoke_all($pdo);
            } catch (Throwable $exception) {
                error_log('AllStat MCP admin: revoke_all selhalo: ' . $exception->getMessage());
                $errors[] = t('Aplikace se nepodařilo odpojit, připojení AI proto zůstává zapnuté. Zkuste to znovu.');
            }
        }

        if ($errors) {
            $formValues = ['enabled' => $wantEnabled ? '1' : '0', 'host' => $hostRaw, 'app' => $appRaw, 'extra' => $extraRaw, 'confirm' => $confirmOff];
        } else {
            allstat_set_setting($pdo, 'mcp.enabled', $wantEnabled ? '1' : '0');
            allstat_set_setting($pdo, 'mcp.host_url', $newHost);
            allstat_set_setting($pdo, 'mcp.app_url', $newApp);
            allstat_set_setting($pdo, 'mcp.extra_redirect_uris', $newExtra);

            $changed = [];
            if ($newHost !== $oldHost) {
                $changed[] = 'host_url';
            }
            if ($newApp !== $oldApp) {
                $changed[] = 'app_url';
            }
            if ($newExtra !== $oldExtra) {
                $changed[] = 'extra_redirect_uris';
            }

            if (!$wasEnabled && $wantEnabled) {
                allstat_audit($pdo, (int) $user['id'], null, 'mcp_enabled', 'host=' . $newHost . ' app=' . $newApp);
            } elseif ($wasEnabled && !$wantEnabled) {
                allstat_audit($pdo, (int) $user['id'], null, 'mcp_disabled', 'all_apps_disconnected=1');
            }
            if ($changed) {
                allstat_audit($pdo, (int) $user['id'], null, 'mcp_settings_changed', 'changed=' . implode(',', $changed) . ' host=' . $newHost . ' app=' . $newApp . ' extra_uris=' . count($extraList));
            }

            if ($wasEnabled && !$wantEnabled) {
                $message = t('Připojení AI je vypnuté a všechny připojené aplikace byly odpojeny.');
            } elseif (!$wasEnabled && $wantEnabled) {
                $message = t('Připojení AI je zapnuté. Ověřte nastavení tlačítkem „Spustit kontrolu“ v sekci Kontrola nastavení a pak přidejte konektor v Claude nebo ChatGPT.');
            } else {
                $message = t('Nastavení AI konektorů bylo uloženo.');
            }
            if ($wantEnabled && $oldHost !== '' && $newHost !== $oldHost && function_exists('allstat_mcp_connections')) {
                try {
                    if (allstat_mcp_connections($pdo)) {
                        $message .= ' ' . t('Adresa MCP serveru se změnila: aplikace připojené přes původní adresu je potřeba připojit znovu.');
                    }
                } catch (Throwable) {
                    // jen doplňující upozornění, bez něj se nic neděje
                }
            }

            allstat_flash('ok', $message);
            allstat_redirect($config, 'admin/mcp.php');
        }
    } elseif ($action === 'revoke_grant') {
        $grantId = (string) ($_POST['grant_id'] ?? '');

        if (preg_match('/^[A-Za-z0-9_-]{8,64}$/', $grantId) !== 1) {
            allstat_flash('error', t('Neplatný identifikátor připojení.'));
        } elseif (!function_exists('allstat_mcp_revoke_grant')) {
            allstat_flash('error', t('Serverová část ještě není nainstalovaná.'));
        } else {
            try {
                $label = 'připojení';
                $who = '';
                $rows = function_exists('allstat_mcp_connections') ? allstat_mcp_connections($pdo) : [];
                foreach ($rows as $row) {
                    if ((string) ($row['grant_id'] ?? '') === $grantId) {
                        $label = trim((string) ($row['client_name'] ?? '')) !== '' ? (string) $row['client_name'] : (string) ($row['redirect_host'] ?? $label);
                        $who = (string) ($row['user_email'] ?? '');
                        break;
                    }
                }
                allstat_mcp_revoke_grant($pdo, $grantId);
                allstat_audit($pdo, (int) $user['id'], null, 'mcp_grant_revoked', 'grant=' . $grantId . ' client=' . $label . ($who !== '' ? ' user=' . $who : ''));
                allstat_flash('ok', t('Aplikace „{label}“ byla odpojena a nemůže dál číst data z AllStatu.', ['label' => $label === 'připojení' ? t('připojení') : $label]));
            } catch (Throwable $exception) {
                error_log('AllStat MCP admin: revoke_grant selhalo: ' . $exception->getMessage());
                allstat_flash('error', t('Aplikaci se nepodařilo odpojit. Zkuste to znovu.'));
            }
        }
        allstat_redirect($config, 'admin/mcp.php');
    } elseif ($action === 'run_check') {
        $checkHost = allstat_mcp_admin_base_url($pdo, 'mcp.host_url');
        $checkApp = allstat_mcp_admin_base_url($pdo, 'mcp.app_url');

        if (allstat_setting($pdo, 'mcp.enabled', '0') !== '1' || $checkHost === '' || $checkApp === '') {
            allstat_flash('error', t('Kontrolu jde spustit, až je připojení AI zapnuté a obě adresy jsou uložené.'));
            allstat_redirect($config, 'admin/mcp.php');
        }

        // Uložené adresy se před dotazy ze serveru znovu ověří stejnými pravidly (mohly vzniknout před jejich zpřísněním).
        foreach ([[t('Adresa MCP serveru'), $checkHost], [t('Adresa AllStatu'), $checkApp]] as [$addressLabel, $address]) {
            [, $addressError] = allstat_mcp_admin_check_base_url($address, $addressLabel);
            if ($addressError !== null) {
                allstat_flash('error', t('Kontrola se nespustila, uložená adresa nevyhovuje. {error}', ['error' => $addressError]));
                allstat_redirect($config, 'admin/mcp.php');
            }
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(60);
        }
        try {
            $startedAt = microtime(true);
            $checkItems = allstat_mcp_admin_selfcheck($checkHost, $checkApp);
            $_SESSION['mcp_admin_check'] = [
                'at' => time(),
                'took' => round(microtime(true) - $startedAt, 1),
                'host' => $checkHost,
                'app' => $checkApp,
                'items' => $checkItems,
            ];
        } catch (Throwable $exception) {
            error_log('AllStat MCP admin: kontrola nastavení selhala: ' . $exception->getMessage());
            allstat_flash('error', t('Kontrolu se nepodařilo provést: {error}', ['error' => $exception->getMessage()]));
            allstat_redirect($config, 'admin/mcp.php');
        }
        $checkStatuses = array_count_values(array_column($checkItems, 'status'));
        allstat_audit($pdo, (int) $user['id'], null, 'mcp_check_run', 'host=' . $checkHost . ' errors=' . ($checkStatuses['error'] ?? 0) . ' warnings=' . ($checkStatuses['warning'] ?? 0));
        allstat_redirect($config, 'admin/mcp.php#kontrola');
    } elseif ($action === 'save_ai_context') {
        // Kontext pro AI u každého webu (domains.ai_context). Prázdné pole = NULL, tedy obecný kontext z lib/share.php.
        $posted = is_array($_POST['ai_context'] ?? null) ? $_POST['ai_context'] : [];
        // Formulář je u každého webu zvlášť: po uložení zpět na ten web (rozbalený).
        $ctxIds = array_map('intval', array_keys($posted));
        $ctxOne = count($ctxIds) === 1 && $ctxIds[0] > 0 ? $ctxIds[0] : 0;
        $ctxBack = $ctxOne > 0 ? 'admin/mcp.php?ctx=' . $ctxOne . '#kontext-ai-' . $ctxOne : 'admin/mcp.php#kontext-ai';
        $knownDomains = [];
        foreach ($pdo->query('SELECT id, name FROM domains')->fetchAll(PDO::FETCH_ASSOC) as $domainRow) {
            $knownDomains[(int) $domainRow['id']] = (string) $domainRow['name'];
        }
        $updates = [];
        foreach ($posted as $domainId => $text) {
            $domainId = (int) $domainId;
            if (!isset($knownDomains[$domainId]) || !is_string($text)) {
                continue;
            }
            $text = trim(str_replace("\r\n", "\n", $text));
            if (!mb_check_encoding($text, 'UTF-8')) {
                allstat_mcp_admin_context_notice($ctxOne, 'error', t('Kontext pro AI u webu {name} obsahuje neplatné znaky (očekává se UTF-8), nic se neuložilo.', ['name' => $knownDomains[$domainId]]), mb_scrub($text, 'UTF-8'));
                allstat_redirect($config, $ctxBack);
            }
            if (mb_strlen($text) > ALLSTAT_AI_CONTEXT_MAX) {
                allstat_mcp_admin_context_notice($ctxOne, 'error', t('Kontext pro AI u webu {name} je delší než {max} znaků, nic se neuložilo.', ['name' => $knownDomains[$domainId], 'max' => ALLSTAT_AI_CONTEXT_MAX]), $text);
                allstat_redirect($config, $ctxBack);
            }
            $updates[$domainId] = $text !== '' ? $text : null;
        }
        $changed = [];
        $statement = $pdo->prepare('UPDATE domains SET ai_context = ? WHERE id = ? AND NOT (ai_context <=> ?)');
        foreach ($updates as $domainId => $text) {
            $statement->execute([$text, $domainId, $text]);
            if ($statement->rowCount() > 0) {
                $changed[] = $domainId;
            }
        }
        if ($changed) {
            allstat_audit($pdo, (int) $user['id'], null, 'ai_context_updated', 'domains=' . implode(',', $changed));
        }
        allstat_mcp_admin_context_notice($ctxOne, 'ok', $changed ? t('Kontext pro AI byl uložen.') : t('Kontext pro AI se nezměnil.'));
        allstat_redirect($config, $ctxBack);
    } else {
        allstat_flash('error', t('Neznámá akce.'));
        allstat_redirect($config, 'admin/mcp.php');
    }
}

// ---------- data pro vykreslení ----------
$curEnabled = allstat_setting($pdo, 'mcp.enabled', '0') === '1';
$curHost = allstat_mcp_admin_base_url($pdo, 'mcp.host_url');
$curApp = allstat_mcp_admin_base_url($pdo, 'mcp.app_url');
$curExtra = trim((string) allstat_setting($pdo, 'mcp.extra_redirect_uris', ''));
$detectedApp = allstat_mcp_admin_detect_app_url($config);
$appPrefilled = $formValues === null && $curApp === '' && $detectedApp !== '';

$form = $formValues ?? [
    'enabled' => $curEnabled ? '1' : '0',
    'host' => $curHost,
    'app' => $curApp !== '' ? $curApp : $detectedApp,
    'extra' => $curExtra,
    'confirm' => false,
];

$storedProblems = [];
foreach ([[t('Adresa MCP serveru'), $curHost], [t('Adresa AllStatu'), $curApp]] as [$storedLabel, $storedAddress]) {
    if ($storedAddress !== '') {
        [, $storedError] = allstat_mcp_admin_check_base_url($storedAddress, $storedLabel);
        if ($storedError !== null) {
            $storedProblems[] = $storedError;
        }
    }
}

$isOn = $curEnabled && $curHost !== '' && $curApp !== '';
if ($isOn) {
    $stateBadge = ['ok', t('Zapnuto')];
} elseif ($curEnabled) {
    $stateBadge = ['warning', t('Nedokončeno')];
} else {
    $stateBadge = ['info', t('Vypnuto')];
}

$connectorUrl = $curHost !== '' ? $curHost . '/mcp' : '';
$guideUrl = $connectorUrl !== '' ? $connectorUrl : 'https://allstat.example.cz/mcp';
$claudeAddUrl = 'https://claude.ai/customize/connectors?modal=add-custom-connector&connectorName=AllStat&connectorUrl=' . urlencode($guideUrl);

$connections = [];
$connectionsError = '';
if (function_exists('allstat_mcp_connections')) {
    try {
        $connections = allstat_mcp_connections($pdo);
    } catch (Throwable $exception) {
        error_log('AllStat MCP admin: connections: ' . $exception->getMessage());
        $connectionsError = t('Seznam připojení se nepodařilo načíst.');
    }
}

$logRows = [];
$logError = '';
$logUsers = [];
if (function_exists('allstat_mcp_recent_log')) {
    try {
        $logRows = allstat_mcp_recent_log($pdo, 50);
        $missingUsers = [];
        foreach ($logRows as $logRow) {
            if (!empty($logRow['user_id']) && empty($logRow['user_email'])) {
                $missingUsers[(int) $logRow['user_id']] = true;
            }
        }
        if ($missingUsers) {
            $statement = $pdo->prepare('SELECT id, email, name FROM allstat_users WHERE id IN (' . implode(',', array_fill(0, count($missingUsers), '?')) . ')');
            $statement->execute(array_keys($missingUsers));
            foreach ($statement->fetchAll() as $userRow) {
                $logUsers[(int) $userRow['id']] = $userRow;
            }
        }
    } catch (Throwable $exception) {
        error_log('AllStat MCP admin: recent_log: ' . $exception->getMessage());
        $logError = t('Záznam aktivity se nepodařilo načíst.');
    }
}

$tools = [];
$toolsError = '';
if (function_exists('allstat_mcp_tool_definitions')) {
    try {
        $tools = allstat_mcp_tool_definitions();
    } catch (Throwable $exception) {
        error_log('AllStat MCP admin: tools: ' . $exception->getMessage());
        $toolsError = t('Seznam nástrojů se nepodařilo načíst.');
    }
}

$aiContextDomains = $pdo->query('SELECT id, name, url, is_active, ai_context FROM domains ORDER BY is_active DESC, name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];

$canCheck = $isOn;
$check = $_SESSION['mcp_admin_check'] ?? null;
if (!is_array($check) || ($check['host'] ?? '') !== $curHost || ($check['app'] ?? '') !== $curApp || !is_array($check['items'] ?? null)) {
    $check = null; // výsledek platí jen pro adresy, se kterými proběhl
}
$checkLabels = ['ok' => 'OK', 'error' => t('Chyba'), 'warning' => t('Upozornění'), 'info' => 'Info'];

allstat_admin_header(t('AI konektory (MCP)'), 'mcp', $user, $config, null, [
    'sub' => t('Připojení AI umožní Claude nebo ChatGPT číst data z AllStatu (jen čtení, všechny weby). Přístup povoluje a kdykoli ruší administrátor.'),
]);
?>
<section class="card">
    <div class="card-head">
        <div>
            <h2 class="card-title"><?= h(t('Stav a nastavení')) ?></h2>
        </div>
        <?= allstat_status_pill($stateBadge[0], $stateBadge[1]) ?>
    </div>
    <div class="card-body">
        <div class="section">
            <?php foreach ($mcpLoadErrors as $loadError): ?>
                <div class="callout tone-warn"><div><strong><?= h(t('Soubor se nepodařilo načíst.')) ?></strong> <?= h($loadError) ?></div></div>
            <?php endforeach; ?>
            <?php if (!function_exists('allstat_mcp_connections')): ?>
                <div class="callout tone-warn"><div><?= h(t('Serverová část ještě není nainstalovaná. Nastavení jde uložit, ale připojení AI začne fungovat až po nahrání souborů lib/mcp-oauth.php, lib/mcp-server.php a lib/mcp-tools.php.')) ?></div></div>
            <?php endif; ?>
            <?php foreach ($errors as $formError): ?>
                <div class="callout tone-down"><div><?= h($formError) ?></div></div>
            <?php endforeach; ?>
            <?php foreach ($storedProblems as $storedProblem): ?>
                <div class="callout tone-warn"><div><strong><?= h(t('Uložené nastavení nevyhovuje pravidlům.')) ?></strong> <?= h($storedProblem) ?> <?= h(t('Opravte ho a uložte znovu.')) ?></div></div>
            <?php endforeach; ?>

            <form method="post" class="form-grid" data-mcp-settings data-was-enabled="<?= $curEnabled ? '1' : '0' ?>">
                <?= allstat_csrf_field() ?>
                <input type="hidden" name="action" value="save_settings">
                <label class="field">
                    <span class="field-label"><?= h(t('Povolit připojení AI (MCP)')) ?></span>
                    <select class="input" name="mcp_enabled">
                        <option value="1"<?= $form['enabled'] === '1' ? ' selected' : '' ?>><?= h(t('Ano')) ?></option>
                        <option value="0"<?= $form['enabled'] !== '1' ? ' selected' : '' ?>><?= h(t('Ne')) ?></option>
                    </select>
                    <span class="field-help"><?= h(t('Při „Ne“ všechny adresy konektoru odpovídají 404 a nikdo se nepřipojí. Vypnutí zároveň odpojí už připojené aplikace.')) ?></span>
                </label>
                <label class="field">
                    <span class="field-label"><?= h(t('Adresa MCP serveru')) ?></span>
                    <input class="input" type="text" name="mcp_host_url" value="<?= h($form['host']) ?>" placeholder="https://allstat.example.cz" inputmode="url" autocomplete="off" spellcheck="false">
                    <span class="field-help"><?= h(t('Adresa, na které běží složka allstat-mcp, nejlépe vlastní subdoména. Musí být HTTPS a bez lomítka na konci (http:// jen pro localhost).')) ?></span>
                </label>
                <label class="field">
                    <span class="field-label"><?= h(t('Adresa AllStatu')) ?></span>
                    <input class="input" type="text" name="mcp_app_url" value="<?= h($form['app']) ?>" placeholder="https://www.example.cz/allstat" inputmode="url" autocomplete="off" spellcheck="false">
                    <span class="field-help"><?= h(t('Kde běží tato administrace, včetně cesty. Sem se uživatel vrací k přihlášení a potvrzení přístupu.')) ?><?= $appPrefilled ? ' ' . h(t('Předvyplněno podle aktuální adresy, zkontrolujte ji.')) : '' ?></span>
                </label>
                <label class="field field-full">
                    <span class="field-label"><?= h(t('Další povolené návratové adresy (volitelné)')) ?></span>
                    <textarea class="input" name="mcp_extra_redirect_uris" rows="4" placeholder="https://aplikace.example.com/oauth/callback" spellcheck="false" autocomplete="off"><?= h($form['extra']) ?></textarea>
                    <span class="field-help"><?= h(t('Jedna přesná adresa na řádek ve tvaru https://server/cesta (bez parametrů, server malými písmeny, http:// jen pro localhost a 127.0.0.1). Claude, ChatGPT a Claude Code jsou povolené automaticky, sem patří jen výjimky pro jiné aplikace.')) ?></span>
                </label>
                <?php if ($curEnabled): ?>
                    <label class="check field-full" data-mcp-confirm-off>
                        <input type="checkbox" name="mcp_confirm_disable" value="1"<?= !empty($form['confirm']) ? ' checked' : '' ?>>
                        <span><strong><?= h(t('Opravdu odpojit všechny aplikace.')) ?></strong> <?= h(t('Platí při přepnutí na „Ne“: vypnutí hned zneplatní všechna připojení (Claude, ChatGPT...) a aplikace se musí připojit znovu.')) ?></span>
                    </label>
                <?php endif; ?>
                <div class="form-actions field-full">
                    <button class="btn btn-primary" type="submit"><span><?= h(t('Uložit nastavení')) ?></span></button>
                </div>
            </form>
        </div>
    </div>
</section>

<section class="card" id="kontext-ai">
    <div class="card-head">
        <div>
            <h2 class="card-title"><?= h(t('Kontext pro AI')) ?></h2>
            <p class="card-desc"><?= h(t('Popis organizace a cíle webu. AllStat ho dá na začátek každého reportu pro AI (tlačítko Analyzovat s AI) a AI konektor ho dostane u daného webu. Každý web má vlastní text, prázdné pole znamená obecný kontext.')) ?></p>
        </div>
        <?php if (count($aiContextDomains) >= 8): ?>
            <label class="search">
                <span class="sr-only"><?= h(t('Hledat web')) ?></span>
                <?= allstat_icon('search', 'icon-sm') ?>
                <input class="input" type="search" data-table-filter="ai-context-list" placeholder="<?= h(t('název nebo doména')) ?>">
            </label>
        <?php endif; ?>
    </div>
    <?php if (!$aiContextDomains): ?>
        <div class="card-body"><p class="is-muted"><?= h(t('Zatím tu není žádný web.')) ?></p></div>
    <?php else:
        // Seznam webů: řádek s náhledem a stavem, úprava se rozbalí u jednoho webu a ukládá se jen on
        // (sbalený seznam zůstane přehledný a formulář nenarazí na max_input_vars).
        $ctxFilled = count(array_filter($aiContextDomains, static fn (array $d): bool => trim((string) ($d['ai_context'] ?? '')) !== ''));
        $ctxNotice = is_array($_SESSION['ai_context_notice'] ?? null) ? $_SESSION['ai_context_notice'] : null;
        unset($_SESSION['ai_context_notice']);
        $ctxOpenId = count($aiContextDomains) === 1 ? (int) $aiContextDomains[0]['id'] : (int) ($ctxNotice['id'] ?? $_GET['ctx'] ?? 0);
    ?>
        <div class="card-body ctx-list" id="ai-context-list">
            <?php if (count($aiContextDomains) >= 8): ?>
                <div class="seg ctx-filter" role="group" aria-label="<?= h(t('Filtr podle stavu')) ?>" data-status-filter-for="ai-context-list">
                    <button type="button" class="seg-item" aria-pressed="true" data-status-filter="all"><span><?= h(t('Vše')) ?></span><span class="seg-count num"><?= count($aiContextDomains) ?></span></button>
                    <button type="button" class="seg-item" aria-pressed="false" data-status-filter="own"><span><?= h(t('Vlastní kontext')) ?></span><span class="seg-count num"><?= $ctxFilled ?></span></button>
                    <button type="button" class="seg-item" aria-pressed="false" data-status-filter="general"><span><?= h(t('Obecný kontext')) ?></span><span class="seg-count num"><?= count($aiContextDomains) - $ctxFilled ?></span></button>
                </div>
            <?php endif; ?>
            <p class="field-help"><?= h(t('Webů s vlastním kontextem: {filled} z {count}.', ['filled' => $ctxFilled, 'count' => count($aiContextDomains)])) ?> <?= h(t('Stručně napište, kdo jste, komu web slouží, co je jeho cíl (prodej, registrace, povědomí) a jaké máte možnosti (velikost týmu, rozpočet). Nejvýše {max} znaků na web.', ['max' => ALLSTAT_AI_CONTEXT_MAX])) ?></p>
            <?php foreach ($aiContextDomains as $ctxDomain):
                $ctxId = (int) $ctxDomain['id'];
                $ctxText = trim((string) ($ctxDomain['ai_context'] ?? ''));
                $ctxPreview = $ctxText !== '' ? mb_substr(preg_replace('/\s+/u', ' ', $ctxText) ?? $ctxText, 0, 160) : t('Nevyplněno, AI dostane obecný kontext.');
                $ctxItemNotice = $ctxNotice !== null && (int) ($ctxNotice['id'] ?? 0) === $ctxId ? $ctxNotice : null;
            ?>
                <details class="ctx-item" id="kontext-ai-<?= $ctxId ?>" data-filter-text="<?= h(mb_strtolower($ctxDomain['name'] . ' ' . $ctxDomain['url'])) ?>" data-status="<?= $ctxText !== '' ? 'own' : 'general' ?>"<?= $ctxId === $ctxOpenId ? ' open' : '' ?>>
                    <summary>
                        <span class="ctx-name"><strong class="ellipsis"><?= h((string) $ctxDomain['name']) ?></strong><span class="is-muted ellipsis"><?= h((string) $ctxDomain['url']) ?></span></span>
                        <span class="ctx-preview"><?= h($ctxPreview) ?></span>
                        <span class="ctx-status">
                            <?= $ctxText !== '' ? allstat_status_pill('ok', t('Vlastní kontext')) : allstat_status_pill('off', t('Obecný kontext')) ?>
                            <?= (int) $ctxDomain['is_active'] === 1 ? '' : allstat_status_pill('off', t('Vypnuto')) ?>
                        </span>
                        <?= allstat_icon('chevron-down', 'note-chev') ?>
                    </summary>
                    <form method="post" class="ctx-form">
                        <?= allstat_csrf_field() ?>
                        <input type="hidden" name="action" value="save_ai_context">
                        <?php if ($ctxItemNotice !== null): ?>
                            <div class="callout <?= ($ctxItemNotice['type'] ?? '') === 'error' ? 'tone-down' : 'tone-up' ?>" role="status"><?= h((string) ($ctxItemNotice['message'] ?? '')) ?></div>
                        <?php endif; ?>
                        <label class="field">
                            <span class="sr-only"><?= h(t('Kontext pro AI: {name}', ['name' => (string) $ctxDomain['name']])) ?></span>
                            <textarea class="input" name="ai_context[<?= $ctxId ?>]" rows="6" maxlength="<?= ALLSTAT_AI_CONTEXT_MAX ?>" placeholder="<?= h(t('Například: Jsme e-shop s outdoorovým vybavením, hlavním cílem je prodej, marketing dělají dva lidé.')) ?>"><?= h(isset($ctxItemNotice['text']) ? (string) $ctxItemNotice['text'] : $ctxText) ?></textarea>
                        </label>
                        <div class="form-actions">
                            <button class="btn btn-primary" type="submit"><span><?= h(t('Uložit kontext')) ?></span></button>
                        </div>
                    </form>
                </details>
            <?php endforeach; ?>
            <div class="empty" data-filter-empty hidden><p class="is-muted"><?= h(t('Žádný web neodpovídá hledání.')) ?></p></div>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-head">
        <div>
            <h2 class="card-title"><?= h(t('Adresa konektoru')) ?></h2>
            <p class="card-desc"><?= h(t('Tuto adresu vložte do Claude nebo ChatGPT jako vlastní (custom) konektor. Aplikace pak smí jen číst data všech webů v AllStatu.')) ?></p>
        </div>
    </div>
    <div class="card-body">
        <div class="section">
            <?php if ($connectorUrl === ''): ?>
                <div class="callout tone-warn"><div><?= h(t('Nejdřív vyplňte a uložte Adresu MCP serveru v nastavení výše, adresa konektoru se z ní odvodí.')) ?></div></div>
            <?php else: ?>
                <?php if (!$isOn): ?>
                    <div class="callout tone-warn"><div><?= h($curEnabled ? t('Připojení AI je zapnuté, ale chybí Adresa AllStatu. Doplňte ji v nastavení výše, jinak se nikdo nemůže přihlásit.') : t('Připojení AI je teď vypnuté, konektor nebude fungovat, dokud ho v nastavení nezapnete.')) ?></div></div>
                <?php endif; ?>
                <div class="form-actions">
                    <input class="input mono" type="text" id="mcpConnectorUrl" readonly value="<?= h($connectorUrl) ?>" data-select-all aria-label="<?= h(t('Adresa konektoru')) ?>">
                    <button type="button" class="btn btn-secondary" data-mcp-copy="mcpConnectorUrl"><span><?= h(t('Kopírovat')) ?></span></button>
                </div>
                <div class="form-actions">
                    <a class="btn btn-primary" href="<?= h($claudeAddUrl) ?>" target="_blank" rel="noopener noreferrer"><span><?= h(t('Přidat do Claude')) ?></span></a>
                    <span class="is-muted"><?= h(t('Otevře Claude s předvyplněným konektorem. Pro ChatGPT použijte návod níže.')) ?></span>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="section">
    <div class="section-head">
        <h2 class="h2"><?= h(t('Návody k připojení')) ?></h2>
        <span class="section-desc"><?= h(t('Postup se v aplikacích občas mění, názvy položek jsou uvedené česky i anglicky.')) ?><?= $connectorUrl === '' ? ' ' . h(t('Adresa konektoru v návodech je zatím jen příklad, nahradí se po uložení Adresy MCP serveru.')) : '' ?></span>
    </div>
    <details class="card note">
        <summary>
            <?= allstat_icon('sparkles', 'icon') ?>
            <span><?= h(t('Claude (web, desktop, mobil)')) ?> <span class="is-muted"><?= h(t('Free, Pro, Max, Team a Enterprise')) ?></span></span>
            <?= allstat_icon('chevron-down', 'note-chev') ?>
        </summary>
        <div class="note-body">
            <ol class="steps">
                <li><?= t('V Claude otevřete <strong>Přizpůsobit (Customize) → Konektory (Connectors)</strong> a klikněte na <strong>Přidat vlastní konektor (Add custom connector)</strong>. Nebo použijte tlačítko „Přidat do Claude“ výše, které vyplní název i adresu.') ?></li>
                <li><?= t('Jako název zadejte <code>AllStat</code>, jako URL <code>{url}</code> a potvrďte.', ['url' => h($guideUrl)]) ?></li>
                <li><?= t('Klikněte na <strong>Připojit (Connect)</strong>. Otevře se AllStat: přihlaste se jako administrátor a klikněte na <strong>Povolit</strong>.') ?></li>
            </ol>
            <p class="field-help"><?= h(t('Volbu OAuth klienta nechte na výchozí („Use Claude\'s published identity“). Když přihlášení nefunguje, zkuste „Register automatically“.')) ?></p>
            <p class="field-help"><?= t('<strong>Team a Enterprise:</strong> vlastník organizace nejdřív přidá konektor v <em>Organization settings → Connectors → Add → Custom → Web</em>. Členové pak jen kliknou na Connect.') ?></p>
        </div>
    </details>
    <details class="card note">
        <summary>
            <?= allstat_icon('sparkles', 'icon') ?>
            <span>ChatGPT <span class="is-muted"><?= h(t('Plus, Pro, Business, Enterprise a Edu, jen ve webové verzi')) ?></span></span>
            <?= allstat_icon('chevron-down', 'note-chev') ?>
        </summary>
        <div class="note-body">
            <ol class="steps">
                <li><?= t('V ChatGPT otevřete <strong>Settings → Security and login</strong> a zapněte <strong>Developer mode</strong>.') ?></li>
                <li><?= t('Otevřete <strong>Plugins</strong> (dříve Apps / Connectors), klikněte na <strong>+</strong> a vyplňte název <code>AllStat</code>, MCP URL <code>{url}</code> a Authentication <strong>OAuth</strong>.', ['url' => h($guideUrl)]) ?></li>
                <li><?= t('Přihlaste se do AllStatu jako administrátor a klikněte na <strong>Povolit</strong>.') ?></li>
            </ol>
            <p class="field-help"><?= t('Po aktualizaci AllStatu (nové nebo změněné nástroje) klikněte v konektoru na <strong>Refresh</strong>. V Business a Enterprise musí vývojářský režim nejdřív povolit správce workspace.') ?></p>
        </div>
    </details>
    <details class="card note">
        <summary>
            <?= allstat_icon('sparkles', 'icon') ?>
            <span>Claude Code <span class="is-muted"><?= h(t('Příkazová řádka')) ?></span></span>
            <?= allstat_icon('chevron-down', 'note-chev') ?>
        </summary>
        <div class="note-body">
            <code class="code">claude mcp add --transport http allstat <?= h($guideUrl) ?></code>
            <p class="field-help"><?= t('Potom v Claude Code spusťte <code>/mcp</code>, vyberte <strong>allstat</strong> a zvolte <strong>Authenticate</strong>.') ?></p>
        </div>
    </details>
    <div class="callout"><?= allstat_icon('info', 'icon-sm') ?><div><?= t('<strong>Tip:</strong> po připojení napište například: „Udělej měsíční report webu za srpen.“') ?></div></div>
</section>

<section class="card">
    <div class="card-head">
        <div>
            <h2 class="card-title"><?= h(t('Připojené aplikace')) ?></h2>
            <p class="card-desc"><?= h(t('Aplikace, které smí číst data z AllStatu. Odpojením ztratí přístup okamžitě, nový přístup musí administrátor znovu povolit.')) ?></p>
        </div>
    </div>
    <?php if (!function_exists('allstat_mcp_connections')): ?>
        <div class="card-body"><div class="callout tone-warn"><div><?= h(t('Serverová část ještě není nainstalovaná.')) ?></div></div></div>
    <?php elseif ($connectionsError !== ''): ?>
        <div class="card-body"><div class="callout tone-down"><div><?= h($connectionsError) ?></div></div></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table-data">
                <colgroup><col><col><col><col class="col-md"><col class="col-md"><col class="col-md"></colgroup>
                <thead><tr><th scope="col"><?= h(t('Uživatel')) ?></th><th scope="col"><?= h(t('Aplikace')) ?></th><th scope="col"><?= h(t('Návrat na')) ?></th><th scope="col"><?= h(t('Připojeno')) ?></th><th scope="col"><?= h(t('Naposledy použito')) ?></th><th scope="col" data-no-sort><span class="sr-only"><?= h(t('Akce')) ?></span></th></tr></thead>
                <tbody>
                <?php foreach ($connections as $connectionIndex => $connection): ?>
                    <?php
                        $appName = trim((string) ($connection['client_name'] ?? ''));
                        $redirectHost = (string) ($connection['redirect_host'] ?? '');
                        $appLabel = $appName !== '' ? $appName : ($redirectHost !== '' ? $redirectHost : t('Neznámá aplikace'));
                        $clientHost = (string) parse_url((string) ($connection['client_id'] ?? ''), PHP_URL_HOST);
                        $identity = ($connection['client_type'] ?? '') === 'cimd' && $clientHost !== '' ? t('ověřeno z {host}', ['host' => $clientHost]) : t('automatická registrace');
                        $userName = trim((string) ($connection['user_name'] ?? ''));
                        $userEmail = (string) ($connection['user_email'] ?? '');
                        $userLabel = $userName !== '' ? $userName : ($userEmail !== '' ? $userEmail : t('Smazaný uživatel #{id}', ['id' => (int) ($connection['user_id'] ?? 0)]));
                    ?>
                    <tr<?= $connectionIndex >= 10 ? ' class="row-extra"' : '' ?>>
                        <td data-label="<?= h(t('Uživatel')) ?>"><div class="name-stack"><span class="name-main ellipsis"><?= h($userLabel) ?></span><?php if ($userName !== '' && $userEmail !== ''): ?><span class="name-sub ellipsis"><?= h($userEmail) ?></span><?php endif; ?></div></td>
                        <td data-label="<?= h(t('Aplikace')) ?>"><div class="name-stack"><span class="name-main ellipsis"><?= h($appLabel) ?></span><span class="name-sub ellipsis"><?= h($identity) ?></span></div></td>
                        <td data-label="<?= h(t('Návrat na')) ?>"><span class="ellipsis"><?= h($redirectHost !== '' ? $redirectHost : '–') ?></span></td>
                        <td data-label="<?= h(t('Připojeno')) ?>" data-sort="<?= h((string) ($connection['created_at'] ?? '')) ?>"><?= h(allstat_mcp_admin_time($connection['created_at'] ?? null) ?: '–') ?></td>
                        <td data-label="<?= h(t('Naposledy použito')) ?>" data-sort="<?= h((string) ($connection['last_used_at'] ?? '')) ?>"><?= ($lastUsed = allstat_mcp_admin_time($connection['last_used_at'] ?? null)) !== '' ? h($lastUsed) : '<span class="is-muted">' . h(t('zatím nepoužito')) . '</span>' ?></td>
                        <td>
                            <div class="cell-actions">
                                <form method="post" data-confirm="<?= h($userEmail !== '' ? t('Odpojit aplikaci „{app}“ uživatele {email}? Přestane číst data z AllStatu.', ['app' => $appLabel, 'email' => $userEmail]) : t('Odpojit aplikaci „{app}“? Přestane číst data z AllStatu.', ['app' => $appLabel])) ?>">
                                    <?= allstat_csrf_field() ?>
                                    <input type="hidden" name="action" value="revoke_grant">
                                    <input type="hidden" name="grant_id" value="<?= h((string) ($connection['grant_id'] ?? '')) ?>">
                                    <button class="btn btn-danger btn-sm" type="submit"><span><?= h(t('Odpojit')) ?></span></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (!$connections): ?>
            <div class="empty"><span class="empty-desc"><?= h(t('Zatím není připojená žádná aplikace. Po přidání konektoru v Claude nebo ChatGPT se zde objeví.')) ?></span></div>
        <?php endif; ?>
        <?= allstat_mcp_admin_show_more(count($connections)) ?>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-head">
        <div>
            <h2 class="card-title"><?= h(t('Poslední aktivita')) ?></h2>
            <p class="card-desc"><?= h(t('Posledních 50 volání z připojených aplikací, nejnovější nahoře. Zaznamenává se metoda, nástroj a parametry, ne obsah odpovědí. Záznamy se po 90 dnech mažou.')) ?></p>
        </div>
    </div>
    <?php if (!function_exists('allstat_mcp_recent_log')): ?>
        <div class="card-body"><div class="callout tone-warn"><div><?= h(t('Serverová část ještě není nainstalovaná.')) ?></div></div></div>
    <?php elseif ($logError !== ''): ?>
        <div class="card-body"><div class="callout tone-down"><div><?= h($logError) ?></div></div></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table-data">
                <colgroup><col class="col-md"><col><col><col class="col-xl"><col class="col-md"><col class="col-xs"><col class="col-sm"></colgroup>
                <thead><tr><th scope="col"><?= h(t('Čas')) ?></th><th scope="col"><?= h(t('Uživatel')) ?></th><th scope="col"><?= h(t('Aplikace')) ?></th><th scope="col"><?= h(t('Metoda / nástroj')) ?></th><th scope="col"><?= h(t('Stav')) ?></th><th scope="col" class="num">ms</th><th scope="col" class="num"><?= h(t('Velikost')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($logRows as $logIndex => $logRow): ?>
                    <?php
                        $logUserId = (int) ($logRow['user_id'] ?? 0);
                        $logUserEmail = trim((string) ($logRow['user_email'] ?? ''));
                        $logUserName = trim((string) ($logRow['user_name'] ?? ''));
                        if ($logUserId > 0 && ($logUserEmail === '' || $logUserName === '')) {
                            $logUserEmail = $logUserEmail !== '' ? $logUserEmail : trim((string) ($logUsers[$logUserId]['email'] ?? ''));
                            $logUserName = $logUserName !== '' ? $logUserName : trim((string) ($logUsers[$logUserId]['name'] ?? ''));
                        }
                        $logUserLabel = $logUserName !== '' ? $logUserName : $logUserEmail; // e-mail je v tooltipu
                        $logStatus = (string) ($logRow['status'] ?? '');
                        $logBadge = ['ok' => ['ok', 'OK'], 'error' => ['error', t('Chyba')], 'denied' => ['warning', t('Zamítnuto')]][$logStatus] ?? ['info', $logStatus !== '' ? $logStatus : '–'];
                        $logTool = trim((string) ($logRow['tool'] ?? ''));
                        $logArgs = trim((string) ($logRow['args_json'] ?? ''));
                        $logErrorText = trim((string) ($logRow['error'] ?? ''));
                        $logMeta = trim('IP ' . (string) ($logRow['ip'] ?? '') . ' ' . (string) ($logRow['user_agent'] ?? ''));
                    ?>
                    <tr<?= $logIndex >= 10 ? ' class="row-extra"' : '' ?>>
                        <td data-label="<?= h(t('Čas')) ?>" title="<?= h($logMeta) ?>" data-sort="<?= h((string) ($logRow['created_at'] ?? '')) ?>"><?= h(allstat_mcp_admin_time($logRow['created_at'] ?? null, true) ?: '–') ?></td>
                        <td data-label="<?= h(t('Uživatel')) ?>"<?= $logUserEmail !== '' ? ' title="' . h($logUserEmail) . '"' : '' ?>><span class="ellipsis"><?= h($logUserLabel !== '' ? $logUserLabel : '–') ?></span></td>
                        <td data-label="<?= h(t('Aplikace')) ?>"><span class="ellipsis"><?= h(trim((string) ($logRow['client_name'] ?? '')) ?: '–') ?></span></td>
                        <td data-label="<?= h(t('Metoda / nástroj')) ?>">
                            <div class="name-stack">
                                <span class="ellipsis"><?php if ($logTool !== ''): ?><code><?= h($logTool) ?></code><?php else: ?><?= h((string) ($logRow['method'] ?? '–')) ?><?php endif; ?></span>
                                <?php if ($logArgs !== ''): ?><span class="name-sub ellipsis" title="<?= h($logArgs) ?>"><?= h(mb_strimwidth($logArgs, 0, 90, '...')) ?></span><?php endif; ?>
                            </div>
                        </td>
                        <td data-label="<?= h(t('Stav')) ?>">
                            <div class="name-stack">
                                <span><?= allstat_status_pill($logBadge[0], $logBadge[1]) ?></span>
                                <?php if ($logErrorText !== ''): ?><span class="name-sub ellipsis" title="<?= h($logErrorText) ?>"><?= h(mb_strimwidth($logErrorText, 0, 60, '...')) ?></span><?php endif; ?>
                            </div>
                        </td>
                        <td data-label="ms" class="num"><?= h(allstat_number((int) ($logRow['duration_ms'] ?? 0))) ?></td>
                        <td data-label="<?= h(t('Velikost')) ?>" class="num"><?= h(allstat_mcp_admin_bytes($logRow['response_bytes'] ?? 0)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (!$logRows): ?>
            <div class="empty"><span class="empty-desc"><?= h(t('Zatím žádná aktivita. Zaznamená se každé volání z připojených aplikací.')) ?></span></div>
        <?php endif; ?>
        <?= allstat_mcp_admin_show_more(count($logRows)) ?>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-head">
        <div>
            <h2 class="card-title"><?= h(t('Nástroje')) ?></h2>
            <p class="card-desc"><?= h(t('Co může připojená AI z AllStatu zjistit. Všechny nástroje jsou jen pro čtení a vracejí agregovaná analytická data, žádné přihlašovací údaje ani tokeny.')) ?></p>
        </div>
    </div>
    <div class="card-body">
        <?php if (!function_exists('allstat_mcp_tool_definitions')): ?>
            <div class="callout tone-warn"><div><?= h(t('Serverová část ještě není nainstalovaná.')) ?></div></div>
        <?php elseif ($toolsError !== ''): ?>
            <div class="callout tone-down"><div><?= h($toolsError) ?></div></div>
        <?php else: ?>
            <div class="rows rows-spaced">
                <?php foreach ($tools as $tool): ?>
                    <div class="row">
                        <div class="name-stack">
                            <span class="name-main"><?= h((string) (($tool['title'] ?? '') !== '' ? $tool['title'] : ($tool['name'] ?? ''))) ?></span>
                            <span class="name-sub"><?= h((string) ($tool['description'] ?? '')) ?></span>
                        </div>
                        <code class="chip mono"><?= h((string) ($tool['name'] ?? '')) ?></code>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if (!$tools): ?>
                <p class="is-muted"><?= h(t('Žádné nástroje.')) ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<section class="card" id="kontrola">
    <div class="card-head">
        <div>
            <h2 class="card-title"><?= h(t('Kontrola nastavení')) ?></h2>
            <p class="card-desc"><?= h(t('Server AllStatu si sám zavolá vaše adresy tak, jak to udělá Claude nebo ChatGPT. Neposílá žádné skutečné tokeny, jen neplatný token „neplatny“.')) ?></p>
        </div>
        <form method="post" data-mcp-check-form>
            <?= allstat_csrf_field() ?>
            <input type="hidden" name="action" value="run_check">
            <button class="btn btn-primary" type="submit"<?= $canCheck ? '' : ' disabled' ?>><span><?= h(t('Spustit kontrolu')) ?></span></button>
        </form>
    </div>
    <div class="card-body">
        <?php if (!$canCheck): ?>
            <p class="is-muted"><?= h(t('Kontrolu jde spustit, až je připojení AI zapnuté a obě adresy jsou uložené (jinak server záměrně odpovídá 404).')) ?></p>
        <?php elseif ($check === null): ?>
            <p class="is-muted"><?= h(t('Kontrola zatím neproběhla. Trvá několik sekund a zkusí všechny adresy najednou.')) ?></p>
        <?php else: ?>
            <?php
                $counts = ['ok' => 0, 'error' => 0, 'warning' => 0, 'info' => 0];
                foreach ($check['items'] as $checkItem) {
                    $counts[$checkItem['status'] ?? 'info'] = ($counts[$checkItem['status'] ?? 'info'] ?? 0) + 1;
                }
                $summaryClass = $counts['error'] > 0 ? 'tone-down' : ($counts['warning'] > 0 ? 'tone-warn' : 'tone-up');
            ?>
            <div class="section">
                <div class="callout <?= h($summaryClass) ?>">
                    <div>
                        <?php if ($counts['error'] > 0): ?>
                            <?= h(t('Kontrola našla chyby ({count}), konektor pravděpodobně nepůjde připojit. Postupujte podle rad u jednotlivých položek.', ['count' => (int) $counts['error']])) ?>
                        <?php elseif ($counts['warning'] > 0): ?>
                            <?= h(t('Kontrola proběhla bez chyb, ale s upozorněním ({count}).', ['count' => (int) $counts['warning']])) ?>
                        <?php else: ?>
                            <?= h(t('Vše v pořádku, konektor můžete přidat do Claude nebo ChatGPT.')) ?>
                        <?php endif; ?>
                        <span class="is-muted"><?= h(t('Provedeno v {time}, trvalo {duration}.', ['time' => date('H:i:s', (int) ($check['at'] ?? time())), 'duration' => (float) ($check['took'] ?? 0) < 0.1 ? t('méně než 0,1 s') : allstat_number((float) $check['took'], 1) . ' s'])) ?></span>
                    </div>
                </div>
                <div class="table-wrap">
                    <table class="table table-plain">
                        <colgroup><col><col class="col-md"></colgroup>
                        <thead><tr><th scope="col"><?= h(t('Kontrola nastavení')) ?></th><th scope="col"><?= h(t('Stav')) ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($check['items'] as $checkItem): ?>
                            <?php $checkStatus = (string) ($checkItem['status'] ?? 'info'); ?>
                            <tr>
                                <td class="is-wrap">
                                    <div class="name-stack">
                                        <span class="name-main"><?= h($checkItem['title'] ?? '') ?></span>
                                        <span class="name-sub"><code><?= h($checkItem['target'] ?? '') ?></code></span>
                                        <span class="name-sub"><?= h($checkItem['detail'] ?? '') ?></span>
                                        <?php if (($checkItem['hint'] ?? '') !== ''): ?>
                                            <span class="name-sub"><strong><?= h(t('Co s tím:')) ?></strong> <?= h($checkItem['hint']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><?= allstat_status_pill($checkStatus, $checkLabels[$checkStatus] ?? $checkStatus) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($counts['error'] > 0): ?>
                    <p class="field-help"><?= t('Když selhaly všechny položky, server AllStatu se možná nedokáže dovolat na vlastní veřejnou adresu (omezení hostingu). Otevřete v prohlížeči <code>{url}/.well-known/oauth-protected-resource</code>: měl by se zobrazit JSON s polem resource.', ['url' => h($curHost)]) ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<script nonce="<?= h(allstat_nonce()) ?>">
(() => {
    const flashLabel = (button, text) => {
        const original = button.textContent;
        button.textContent = text;
        setTimeout(() => { button.textContent = original; }, 1600);
    };

    document.querySelectorAll('[data-mcp-copy]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = document.getElementById(button.getAttribute('data-mcp-copy'));
            if (!input) { return; }
            const fallback = () => {
                input.select();
                try { document.execCommand('copy'); flashLabel(button, <?= json_encode(t('Zkopírováno ✓'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>); } catch (e) { flashLabel(button, <?= json_encode(t('Stiskněte Ctrl+C'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>); }
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(input.value).then(() => flashLabel(button, <?= json_encode(t('Zkopírováno ✓'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>), fallback);
            } else {
                fallback();
            }
        });
    });

    const form = document.querySelector('[data-mcp-settings]');
    if (form) {
        const select = form.querySelector('[name="mcp_enabled"]');
        const confirmRow = form.querySelector('[data-mcp-confirm-off]');
        const box = form.querySelector('[name="mcp_confirm_disable"]');
        const wasEnabled = form.getAttribute('data-was-enabled') === '1';
        const sync = () => { if (confirmRow && select) { confirmRow.hidden = !(wasEnabled && select.value === '0'); } };
        if (select) { select.addEventListener('change', sync); }
        sync();
        form.addEventListener('submit', (event) => {
            if (wasEnabled && select && select.value === '0' && box && !box.checked) {
                if (window.confirm(<?= json_encode(t('Vypnutím se odpojí všechny připojené aplikace (Claude, ChatGPT...). Opravdu pokračovat?'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)) {
                    box.checked = true;
                } else {
                    event.preventDefault();
                }
            }
        });
    }

    const checkForm = document.querySelector('[data-mcp-check-form]');
    if (checkForm) {
        checkForm.addEventListener('submit', () => {
            const submit = checkForm.querySelector('button[type="submit"]');
            if (submit) { setTimeout(() => { submit.disabled = true; submit.textContent = <?= json_encode(t('Kontroluji...'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>; }, 0); }
        });
    }
})();
</script>
<?php allstat_admin_footer($config); ?>
