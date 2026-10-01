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
            return $label . ': IPv6 adresa „' . $hostName . '“ není platná.';
        }
    } elseif (preg_match('/^[0-9.]+$/', $bare) === 1) {
        if (filter_var($bare, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return $label . ': IP adresa „' . $bare . '“ není platná. Zapište ji ve tvaru a.b.c.d (čísla 0 až 255 bez úvodních nul).';
        }
    } else {
        if (filter_var($bare, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return $label . ': název serveru „' . $hostName . '“ není platný.';
        }
        if ($bare === 'localhost') {
            return null;
        }
        $labels = explode('.', $bare);
        $tld = (string) end($labels);
        if (count($labels) < 2 || preg_match('/^(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/', $tld) !== 1) {
            return $label . ': název serveru „' . $hostName . '“ musí být plný název s doménou (například allstat.example.cz) nebo IP adresa ve tvaru a.b.c.d. Číselné zápisy IP adres (například 2130706433 nebo 0x7f000001) nejsou povolené.';
        }
        if ($tld === 'localhost') {
            return $label . ': názvy končící na .localhost nejsou povolené, použijte localhost nebo 127.0.0.1.';
        }

        return null;
    }

    if (allstat_mcp_admin_ip_is_blocked($bare)) {
        return $label . ': adresa ' . $bare . ' je privátní, lokální nebo rezervovaná IP adresa. Zadejte veřejný název serveru nebo veřejnou IP adresu (výjimkou je jen localhost a 127.0.0.1 pro lokální test).';
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
        return [$value, $label . ': adresa je příliš dlouhá nebo obsahuje mezery či nepovolené znaky.'];
    }
    if (str_contains($value, '?') || str_contains($value, '#')) {
        return [$value, $label . ' nesmí obsahovat parametry (za znakem ?) ani kotvu (za znakem #).'];
    }

    $parts = parse_url($value);
    if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
        return [$value, $label . ' není platná. Zadejte ji celou, včetně https://, například https://allstat.example.cz.'];
    }

    $scheme = strtolower((string) $parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return [$value, $label . ' musí začínat https:// (při lokálním testu smí být http://).'];
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return [$value, $label . ' nesmí obsahovat uživatelské jméno ani heslo.'];
    }

    $hostName = strtolower((string) $parts['host']);
    $hostError = allstat_mcp_admin_host_error($hostName, $label);
    if ($hostError !== null) {
        return [$value, $hostError];
    }
    if ($scheme === 'http' && !allstat_mcp_admin_is_local_host($hostName)) {
        return [$value, $label . ' musí používat https://. Protokol http:// je povolený jen pro localhost a 127.0.0.1.'];
    }
    if (isset($parts['port']) && ((int) $parts['port'] < 1 || (int) $parts['port'] > 65535)) {
        return [$value, $label . ': číslo portu není platné.'];
    }

    $path = (string) ($parts['path'] ?? '');
    if ($path !== '' && str_ends_with($path, '/')) {
        return [$value, $label . ' nesmí končit lomítkem. Odeberte lomítko na konci adresy.'];
    }
    if ($path !== '' && preg_match('#^(?:/[A-Za-z0-9._~-]+)+$#', $path) !== 1) {
        return [$value, $label . ': cesta v adrese obsahuje nepovolené znaky (povolená jsou písmena, číslice, tečka, pomlčka, podtržítko a vlnovka).'];
    }
    foreach (explode('/', $path) as $segment) {
        if ($segment === '.' || $segment === '..') {
            return [$value, $label . ': cesta v adrese nesmí obsahovat úseky „.“ ani „..“.'];
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
        return [[], ['Další návratové adresy obsahují neplatné znaky (očekává se UTF-8).']];
    }

    foreach ($lines as $index => $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $where = 'Další návratové adresy, řádek ' . ($index + 1) . ' („' . mb_strimwidth($line, 0, 70, '...') . '“): ';
        if (strlen($line) > 512 || preg_match('/[\s\x00-\x1f\x7f]/', $line) === 1) {
            $errors[] = $where . 'adresa je příliš dlouhá nebo obsahuje mezery či ovládací znaky.';
            continue;
        }
        if (preg_match('/[;,\'"`\\\\<>*|^{}]/', $line) === 1) {
            $errors[] = $where . 'adresa obsahuje nepovolený znak (středník, čárku, uvozovky, zpětné lomítko, hvězdičku apod.).';
            continue;
        }
        if (str_contains($line, '?') || str_contains($line, '#')) {
            $errors[] = $where . 'návratová adresa nesmí obsahovat parametry (za znakem ?) ani kotvu (za znakem #).';
            continue;
        }
        if (preg_match('#^(https?)://([^/]*)(/.*)?$#', $line, $m) !== 1) {
            $errors[] = $where . 'musí začínat https:// (http:// jen pro localhost a 127.0.0.1), malými písmeny, například https://aplikace.example.com/oauth/callback.';
            continue;
        }
        $scheme = $m[1];
        $authority = $m[2];
        $path = $m[3] ?? '';

        if (str_contains($authority, '@')) {
            $errors[] = $where . 'adresa nesmí obsahovat uživatelské jméno ani heslo.';
            continue;
        }
        if (preg_match('/^(\[[0-9a-f:.]+\]|[a-z0-9.-]+)(?::([0-9]{1,5}))?$/', $authority, $am) !== 1) {
            $errors[] = $where . 'název serveru smí obsahovat jen malá písmena bez diakritiky, číslice, tečky a pomlčky (nebo IPv6 v hranatých závorkách), za ním volitelně :port.';
            continue;
        }

        $hostName = $am[1];
        $port = isset($am[2]) && $am[2] !== '' ? (int) $am[2] : null;
        if ($hostName[0] === '[') {
            if (filter_var(trim($hostName, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                $errors[] = $where . 'IPv6 adresa v hranatých závorkách není platná.';
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
                $errors[] = $where . 'název serveru není platný (prázdná část mezi tečkami, pomlčka na začátku či konci části, příliš dlouhý název).';
                continue;
            }
        }
        if ($port !== null && ($port < 1 || $port > 65535)) {
            $errors[] = $where . 'číslo portu není platné.';
            continue;
        }
        if ($scheme === 'http' && !allstat_mcp_admin_is_local_host($hostName)) {
            $errors[] = $where . 'http:// je povolené jen pro localhost, 127.0.0.1 a [::1], jinde použijte https://.';
            continue;
        }
        if ($path === '') {
            $errors[] = $where . 'adresa musí obsahovat cestu (například /oauth/callback).';
            continue;
        }
        if (preg_match('#^/[A-Za-z0-9._~%:@+=/-]*$#', $path) !== 1) {
            $errors[] = $where . 'cesta obsahuje nepovolené znaky (povolená jsou písmena bez diakritiky, číslice a znaky . _ ~ % : @ + = / -).';
            continue;
        }
        if (in_array('..', explode('/', $path), true) || in_array('.', explode('/', $path), true)) {
            $errors[] = $where . 'cesta nesmí obsahovat úseky „.“ ani „..“.';
            continue;
        }

        $list[$line] = $line;
    }

    if (count($list) > 20) {
        $errors[] = 'Další návratové adresy: zadejte nejvýše 20 řádků.';
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
        return (new DateTimeImmutable($value))->format($seconds ? 'j. n. H:i:s' : 'j. n. Y H:i');
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
    $label = $rest === 1 ? 'Zobrazit další 1 řádek' : ($rest <= 4 ? 'Zobrazit další ' . $rest . ' řádky' : 'Zobrazit dalších ' . $rest . ' řádků');

    return '<button type="button" class="button-secondary show-more-btn" data-show-more>' . h($label) . '</button>';
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
            $results[$key]['error'] = 'Na serveru chybí PHP rozšíření cURL.';
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

/** Selhání na úrovni spojení (DNS, TLS, časový limit). Vrací [popis, rada] nebo null, když server odpověděl. */
function allstat_mcp_admin_transport_problem(array $response): ?array
{
    if ((int) ($response['status'] ?? 0) > 0) {
        return null;
    }

    $errno = (int) ($response['errno'] ?? 0);
    $raw = (string) ($response['error'] ?? '');

    if ($errno === -1) {
        return [$raw, 'Požádejte hosting o zapnutí PHP rozšíření cURL, bez něj kontrolu nelze provést.'];
    }
    if ($errno === 6) {
        return ['Doménu se nepodařilo přeložit (DNS).', 'Zkontrolujte DNS záznam (A záznam subdomény) a překlepy v adrese. Pozor: některé hostingy se nedokážou dovolat samy na sebe, adresu pak ověřte v prohlížeči.'];
    }
    if ($errno === 7) {
        return ['Nepodařilo se navázat spojení se serverem.', 'Server na adrese neodpovídá. Zkontrolujte, že subdoména míří na správný server, že web běží a že adresa včetně portu je správně.'];
    }
    if ($errno === 28) {
        return ['Server neodpověděl do 8 sekund.', 'Zkontrolujte, že server běží. První volání po nahrání nové verze může trvat déle (spouští se migrace databáze), zkuste kontrolu za chvíli zopakovat.'];
    }
    if (in_array($errno, [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91], true)) {
        return ['Chyba zabezpečeného spojení (SSL/TLS).', 'Certifikát je neplatný, vypršel nebo nemá úplný řetězec. Claude i ChatGPT vyžadují platný certifikát od veřejné autority (například Let\'s Encrypt).'];
    }

    return ['Spojení selhalo (cURL ' . $errno . ($raw !== '' ? ': ' . $raw : '') . ').', 'Zkontrolujte adresu a dostupnost serveru.'];
}

/** Společné chybné stavy HTTP (přesměrování, 403, 404, 429, 5xx). Vrací [popis, rada] nebo null. */
function allstat_mcp_admin_status_problem(array $response): ?array
{
    $status = (int) ($response['status'] ?? 0);

    if ($status >= 300 && $status < 400) {
        $location = allstat_mcp_admin_header_value($response, 'location');

        return ['Server přesměrovává (HTTP ' . $status . ($location !== '' ? ' na ' . $location : '') . ').', 'Klienti přesměrování u těchto adres nesledují. Uložte v nastavení přesnou konečnou adresu (https, s www nebo bez www podle skutečnosti) a na serveru přesměrování pro tyto cesty vypněte.'];
    }
    if ($status === 403) {
        return ['Požadavek odmítl server (HTTP 403).', 'Blokuje ho pravděpodobně firewall, WAF (například Cloudflare nebo ModSecurity) nebo pravidlo v .htaccess (bot filtr, Require). Přidejte výjimku pro adresy /mcp a /.well-known/.'];
    }
    if ($status === 404) {
        return ['Adresa neexistuje (HTTP 404).', 'Ověřte, že je připojení AI zapnuté (při vypnutí server záměrně odpovídá 404), že je nahraná složka allstat-mcp včetně .htaccess (mod_rewrite) a že Adresa MCP serveru odpovídá místu, kde složka skutečně běží.'];
    }
    if ($status === 429) {
        return ['Server odmítl požadavek pro příliš mnoho volání (HTTP 429).', 'Zkuste kontrolu za minutu zopakovat.'];
    }
    if ($status >= 500) {
        return ['Chyba serveru (HTTP ' . $status . ').', 'Podívejte se do error_log na hostingu. Časté příčiny: chybějící soubory lib/mcp-*.php, nedostupná databáze, PHP chyba.'];
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
        return ['Adresa vyžaduje přihlášení (HTTP 401).', 'Metadata musí být veřejná. Zkontrolujte, že složku serveru nechrání Basic Auth z hostingu.'];
    }
    if ($status !== 200) {
        return ['Neočekávaná odpověď (HTTP ' . $status . ', má být 200).', 'Zkontrolujte, že adresu obsluhuje složka allstat-mcp.'];
    }

    $data = json_decode((string) $response['body'], true);
    if (!is_array($data)) {
        $type = allstat_mcp_admin_header_value($response, 'content-type');

        return ['Odpověď není JSON (Content-Type: ' . ($type !== '' ? $type : 'neuveden') . ').', 'Adresu zřejmě obsluhuje jiná aplikace (například WordPress) místo složky allstat-mcp. Zkontrolujte, kam míří kořenový adresář subdomény a že v této složce funguje .htaccess (mod_rewrite).'];
    }

    $actual = $data[$key] ?? null;
    if ($actual !== $expected) {
        $shown = is_string($actual) ? $actual : (string) json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return ['Pole „' . $key . '“ má hodnotu „' . $shown . '“, očekává se „' . $expected . '“.', 'Adresa MCP serveru v nastavení se liší od adresy, na které server skutečně běží (nebo ji přepisuje proxy). Opravte nastavení tak, aby přesně odpovídalo adrese, kterou používá klient.'];
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
        'ua_claude' => ['Claude-User', 'Přístup s User-Agent Claude-User (Claude)'],
        'ua_httpx' => ['python-httpx/0.28.1', 'Přístup s User-Agent python-httpx (Claude, Python MCP SDK)'],
        'ua_openai' => ['openai-mcp/1.0.0', 'Přístup s User-Agent openai-mcp (ChatGPT)'],
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
        'Metadata chráněného zdroje (protected resource)',
        'GET ' . $prmUrl . ' a ' . $prmMcpUrl,
        $problem === null ? 'ok' : 'error',
        $problem === null ? 'HTTP 200 na obou adresách, resource = ' . $mcpUrl : $problem[0],
        $problem === null ? '' : $problem[1]
    );

    // 2. a 3. Metadata autorizačního serveru (RFC 8414 a OpenID Connect discovery).
    foreach ([['as', $asUrl, 'Metadata autorizačního serveru (oauth-authorization-server)'], ['oidc', $oidcUrl, 'Metadata OpenID (openid-configuration)']] as [$key, $url, $title]) {
        $verdict = allstat_mcp_admin_verify_metadata($res[$key], 'issuer', $host);
        $add(
            $title,
            'GET ' . $url,
            $verdict === null ? 'ok' : 'error',
            $verdict === null ? 'HTTP 200, issuer = ' . $host . ', bez přesměrování' : $verdict[0],
            $verdict === null ? '' : $verdict[1]
        );
    }

    // 4. MCP endpoint bez tokenu: 401 + WWW-Authenticate s resource_metadata.
    $r = $res['mcp_anon'];
    $problem = allstat_mcp_admin_transport_problem($r) ?? allstat_mcp_admin_status_problem($r);
    $target = 'POST ' . $mcpUrl . ' (bez tokenu)';
    $title = 'MCP endpoint bez tokenu vrací 401 s odkazem na přihlášení';
    if ($problem !== null) {
        $add($title, $target, 'error', $problem[0], $problem[1]);
    } elseif ((int) $r['status'] !== 401) {
        $add(
            $title,
            $target,
            'error',
            'Server odpověděl HTTP ' . (int) $r['status'] . ', očekává se 401.',
            (int) $r['status'] === 200
                ? 'Endpoint /mcp pustil požadavek bez tokenu. To je bezpečnostní chyba, bez tokenu musí vracet 401.'
                : 'Zkontrolujte, že adresu /mcp obsluhuje front controller ve složce allstat-mcp (index.php a .htaccess s mod_rewrite).'
        );
    } else {
        $authenticate = allstat_mcp_admin_header_value($r, 'www-authenticate');
        if (stripos($authenticate, 'resource_metadata') === false) {
            $add($title, $target, 'error', 'Odpověď 401 nemá v hlavičce WWW-Authenticate údaj resource_metadata.', 'Bez něj klient neví, kde najít přihlášení. Hlavičku obvykle odstraňuje proxy nebo nastavení hostingu.');
        } else {
            $advertised = preg_match('/resource_metadata="([^"]+)"/i', $authenticate, $m) === 1 ? $m[1] : '';
            if ($advertised !== '' && $advertised !== $prmMcpUrl) {
                $add($title, $target, 'warning', 'HTTP 401, ale resource_metadata míří na „' . $advertised . '“, očekává se „' . $prmMcpUrl . '“.', 'Zkontrolujte Adresu MCP serveru v nastavení, klient bude metadata hledat na adrese z hlavičky.');
            } else {
                $add($title, $target, 'ok', 'HTTP 401, WWW-Authenticate obsahuje resource_metadata.');
            }
        }
    }

    // 5. Neplatný token: dokazuje, že hlavička Authorization dorazí do PHP.
    $r = $res['mcp_bad'];
    $problem = allstat_mcp_admin_transport_problem($r) ?? allstat_mcp_admin_status_problem($r);
    $target = 'POST ' . $mcpUrl . ' (Authorization: Bearer neplatny)';
    $title = 'Hlavička Authorization dorazí do PHP';
    if ($problem !== null) {
        $add($title, $target, 'error', $problem[0], $problem[1]);
    } elseif ((int) $r['status'] !== 401) {
        $add($title, $target, 'error', 'Server odpověděl HTTP ' . (int) $r['status'] . ', očekává se 401 s chybou invalid_token.', 'Zkontrolujte, že adresu /mcp obsluhuje front controller ve složce allstat-mcp.');
    } else {
        $body = json_decode((string) $r['body'], true);
        $invalid = stripos(allstat_mcp_admin_header_value($r, 'www-authenticate'), 'invalid_token') !== false
            || (is_array($body) && ($body['error'] ?? null) === 'invalid_token');
        if ($invalid) {
            $add($title, $target, 'ok', 'HTTP 401 invalid_token: server hlavičku přijal a token vyhodnotil.');
        } else {
            $add(
                $title,
                $target,
                'error',
                'Server odpověděl 401, ale token nevyhodnotil jako neplatný (chybí invalid_token). Hlavička Authorization se pravděpodobně cestou ztrácí.',
                'Na Apache a LiteSpeed pomáhá v .htaccess složky allstat-mcp pravidlo RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}] (soubor ho obsahuje, ověřte že se nahrál a že je zapnutý mod_rewrite). U PHP-FPM a FastCGI někdy pomůže CGIPassAuth On.'
            );
        }
    }

    // 6. až 8. Požadavky s User-Agenty skutečných klientů: 401 = projdou, 403 = blokuje bot filtr nebo WAF.
    foreach ($uaChecks as $key => [$ua, $title]) {
        $r = $res[$key];
        $target = 'POST ' . $mcpUrl . ' (User-Agent: ' . $ua . ')';
        $problem = allstat_mcp_admin_transport_problem($r);
        if ($problem !== null) {
            $add($title, $target, 'error', $problem[0], $problem[1]);
        } elseif ((int) $r['status'] === 401) {
            $add($title, $target, 'ok', 'HTTP 401, požadavek se dostal až do AllStatu.');
        } elseif ((int) $r['status'] === 403) {
            $add($title, $target, 'error', 'Požadavek s tímto User-Agentem odmítl server (HTTP 403).', 'Blokuje ho firewall, WAF (Cloudflare, ModSecurity) nebo bot filtr v .htaccess. Přidejte výjimku pro adresy /mcp a /.well-known/ (nebo pro celou složku allstat-mcp).');
        } else {
            $other = allstat_mcp_admin_status_problem($r);
            $add($title, $target, 'error', $other !== null ? $other[0] : 'Server odpověděl HTTP ' . (int) $r['status'] . ', očekává se 401.', $other !== null ? $other[1] : 'Zkontrolujte, že adresu /mcp obsluhuje složka allstat-mcp.');
        }
    }

    // 9. Přihlašovací a souhlasová stránka v AllStatu.
    $r = $res['authorize'];
    $status = (int) $r['status'];
    $target = 'GET ' . $authorizeUrl;
    $title = 'Přihlašovací a souhlasová stránka je dostupná';
    $problem = allstat_mcp_admin_transport_problem($r);
    if ($problem !== null) {
        $add($title, $target, 'error', $problem[0], $problem[1]);
    } elseif ($status === 404 && stripos((string) $r['body'], 'Připojení AI je vypnuté') !== false) {
        $add($title, $target, 'error', 'Stránka odpovídá, ale hlásí, že je připojení AI vypnuté (HTTP 404).', 'Adresa AllStatu zřejmě míří na jinou instalaci AllStatu, kde je připojení AI vypnuté, nebo se nastavení ještě neuložilo. Zkontrolujte Adresu AllStatu.');
    } elseif ($status === 404) {
        $add($title, $target, 'error', 'Soubor oauth/authorize.php na adrese neexistuje (HTTP 404).', 'Nahrajte složku oauth/ z nové verze AllStatu a zkontrolujte Adresu AllStatu: musí končit cestou, na které běží tato administrace.');
    } elseif ($status === 403) {
        $add($title, $target, 'error', 'Stránku odmítl server (HTTP 403).', 'Blokuje ji firewall nebo pravidlo v .htaccess (například bot filtr). Uživatelé se v prohlížeči k přihlášení nedostanou.');
    } elseif ($status >= 500) {
        $add($title, $target, 'error', 'Chyba serveru (HTTP ' . $status . ').', 'Podívejte se do error_log na hostingu (složka oauth/ nebo lib/mcp-oauth.php).');
    } elseif (in_array($status, [200, 301, 302, 303, 307, 308, 400, 401], true)) {
        $add($title, $target, 'ok', 'HTTP ' . $status . ', stránka odpovídá.');
    } else {
        $add($title, $target, 'warning', 'Neočekávaná odpověď (HTTP ' . $status . ').', 'Otevřete adresu v prohlížeči a ověřte, že se zobrazí stránka AllStatu.');
    }

    // 10. HTTPS u obou adres (na localhostu jen upozornění).
    $httpsStatus = 'ok';
    $httpsLines = [];
    foreach ([['Adresa MCP serveru', $host], ['Adresa AllStatu', $app]] as [$label, $address]) {
        $parts = parse_url($address);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $local = is_array($parts) && allstat_mcp_admin_is_local_host((string) ($parts['host'] ?? ''));
        if ($scheme === 'https') {
            $httpsLines[] = $label . ': https';
        } elseif ($local) {
            $httpsLines[] = $label . ': http (lokální test)';
            $httpsStatus = $httpsStatus === 'error' ? 'error' : 'warning';
        } else {
            $httpsLines[] = $label . ': ' . ($scheme !== '' ? $scheme : 'neznámé schéma') . ' (nedostačuje)';
            $httpsStatus = 'error';
        }
    }
    $add(
        'Obě adresy používají HTTPS',
        $host . ' a ' . $app,
        $httpsStatus,
        implode('; ', $httpsLines),
        $httpsStatus === 'ok' ? '' : ($httpsStatus === 'warning'
            ? 'Na localhostu je http v pořádku pro vývoj. Claude a ChatGPT se ale k produkčnímu serveru připojí jen přes HTTPS.'
            : 'Claude a ChatGPT se připojí jen přes HTTPS. Nastavte adresy s https:// a zajistěte platný certifikát.')
    );

    // 11. Kořenová metadata (RFC 8414 path insertion), když je server pod cestou.
    if ($rootAsUrl !== '') {
        $verdict = allstat_mcp_admin_verify_metadata($res['root_as'], 'issuer', $host);
        $add(
            'Kořenová metadata pro server pod cestou (RFC 8414)',
            'GET ' . $rootAsUrl,
            $verdict === null ? 'ok' : 'warning',
            $verdict === null ? 'HTTP 200, issuer = ' . $host : 'Na kořeni domény metadata nejsou dostupná: ' . $verdict[0],
            $verdict === null ? '' : 'Klienti dodržující RFC 8414 hledají metadata autorizačního serveru na kořeni domény s cestou na konci. Přidejte do .htaccess v kořeni webu pravidlo (rewrite, ne redirect), které tuto adresu obslouží složkou allstat-mcp, nebo použijte vlastní subdoménu.'
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

        [$newHost, $hostError] = allstat_mcp_admin_check_base_url($hostRaw, 'Adresa MCP serveru');
        [$newApp, $appError] = allstat_mcp_admin_check_base_url($appRaw, 'Adresa AllStatu');
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
            $errors[] = 'Pro zapnutí připojení AI vyplňte Adresu MCP serveru i Adresu AllStatu.';
        }
        if ($wasEnabled && !$wantEnabled && !$confirmOff) {
            $errors[] = 'Vypnutí odpojí všechny připojené aplikace. Potvrďte ho zaškrtnutím políčka „Opravdu odpojit všechny aplikace“.';
        }

        // Vypnutí: nejdřív odpojit aplikace. Když to selže, zůstává zapnuto a nic se neuloží (stav zůstane konzistentní).
        if (!$errors && $wasEnabled && !$wantEnabled && function_exists('allstat_mcp_revoke_all')) {
            try {
                allstat_mcp_revoke_all($pdo);
            } catch (Throwable $exception) {
                error_log('AllStat MCP admin: revoke_all selhalo: ' . $exception->getMessage());
                $errors[] = 'Aplikace se nepodařilo odpojit, připojení AI proto zůstává zapnuté. Zkuste to znovu.';
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
                $message = 'Připojení AI je vypnuté a všechny připojené aplikace byly odpojeny.';
            } elseif (!$wasEnabled && $wantEnabled) {
                $message = 'Připojení AI je zapnuté. Ověřte nastavení tlačítkem „Spustit kontrolu“ v sekci Kontrola nastavení a pak přidejte konektor v Claude nebo ChatGPT.';
            } else {
                $message = 'Nastavení AI konektorů bylo uloženo.';
            }
            if ($wantEnabled && $oldHost !== '' && $newHost !== $oldHost && function_exists('allstat_mcp_connections')) {
                try {
                    if (allstat_mcp_connections($pdo)) {
                        $message .= ' Adresa MCP serveru se změnila: aplikace připojené přes původní adresu je potřeba připojit znovu.';
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
            allstat_flash('error', 'Neplatný identifikátor připojení.');
        } elseif (!function_exists('allstat_mcp_revoke_grant')) {
            allstat_flash('error', 'Serverová část ještě není nainstalovaná.');
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
                allstat_flash('ok', 'Aplikace „' . $label . '“ byla odpojena a nemůže dál číst data z AllStatu.');
            } catch (Throwable $exception) {
                error_log('AllStat MCP admin: revoke_grant selhalo: ' . $exception->getMessage());
                allstat_flash('error', 'Aplikaci se nepodařilo odpojit. Zkuste to znovu.');
            }
        }
        allstat_redirect($config, 'admin/mcp.php');
    } elseif ($action === 'run_check') {
        $checkHost = allstat_mcp_admin_base_url($pdo, 'mcp.host_url');
        $checkApp = allstat_mcp_admin_base_url($pdo, 'mcp.app_url');

        if (allstat_setting($pdo, 'mcp.enabled', '0') !== '1' || $checkHost === '' || $checkApp === '') {
            allstat_flash('error', 'Kontrolu jde spustit, až je připojení AI zapnuté a obě adresy jsou uložené.');
            allstat_redirect($config, 'admin/mcp.php');
        }

        // Uložené adresy se před dotazy ze serveru znovu ověří stejnými pravidly (mohly vzniknout před jejich zpřísněním).
        foreach ([['Adresa MCP serveru', $checkHost], ['Adresa AllStatu', $checkApp]] as [$addressLabel, $address]) {
            [, $addressError] = allstat_mcp_admin_check_base_url($address, $addressLabel);
            if ($addressError !== null) {
                allstat_flash('error', 'Kontrola se nespustila, uložená adresa nevyhovuje. ' . $addressError);
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
            allstat_flash('error', 'Kontrolu se nepodařilo provést: ' . $exception->getMessage());
            allstat_redirect($config, 'admin/mcp.php');
        }
        $checkStatuses = array_count_values(array_column($checkItems, 'status'));
        allstat_audit($pdo, (int) $user['id'], null, 'mcp_check_run', 'host=' . $checkHost . ' errors=' . ($checkStatuses['error'] ?? 0) . ' warnings=' . ($checkStatuses['warning'] ?? 0));
        allstat_redirect($config, 'admin/mcp.php#kontrola');
    } else {
        allstat_flash('error', 'Neznámá akce.');
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
foreach ([['Adresa MCP serveru', $curHost], ['Adresa AllStatu', $curApp]] as [$storedLabel, $storedAddress]) {
    if ($storedAddress !== '') {
        [, $storedError] = allstat_mcp_admin_check_base_url($storedAddress, $storedLabel);
        if ($storedError !== null) {
            $storedProblems[] = $storedError;
        }
    }
}

$isOn = $curEnabled && $curHost !== '' && $curApp !== '';
if ($isOn) {
    $stateBadge = ['ok', 'Zapnuto'];
} elseif ($curEnabled) {
    $stateBadge = ['warning', 'Nedokončeno'];
} else {
    $stateBadge = ['info', 'Vypnuto'];
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
        $connectionsError = 'Seznam připojení se nepodařilo načíst.';
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
        $logError = 'Záznam aktivity se nepodařilo načíst.';
    }
}

$tools = [];
$toolsError = '';
if (function_exists('allstat_mcp_tool_definitions')) {
    try {
        $tools = allstat_mcp_tool_definitions();
    } catch (Throwable $exception) {
        error_log('AllStat MCP admin: tools: ' . $exception->getMessage());
        $toolsError = 'Seznam nástrojů se nepodařilo načíst.';
    }
}

$canCheck = $isOn;
$check = $_SESSION['mcp_admin_check'] ?? null;
if (!is_array($check) || ($check['host'] ?? '') !== $curHost || ($check['app'] ?? '') !== $curApp || !is_array($check['items'] ?? null)) {
    $check = null; // výsledek platí jen pro adresy, se kterými proběhl
}
$checkLabels = ['ok' => 'OK', 'error' => 'Chyba', 'warning' => 'Upozornění', 'info' => 'Info'];

allstat_admin_header('AI konektory (MCP)', 'mcp', $user, $config);
?>
<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2>Stav a nastavení</h2>
            <p>Připojení AI umožní Claude nebo ChatGPT číst data z AllStatu (jen čtení, všechny weby). Přístup povoluje a kdykoli ruší administrátor.</p>
        </div>
        <span class="status-badge status-<?= h($stateBadge[0]) ?>"><?= h($stateBadge[1]) ?></span>
    </div>
    <div class="admin-card-body">
        <?php foreach ($mcpLoadErrors as $loadError): ?>
            <div class="notice notice-warn"><strong>Soubor se nepodařilo načíst.</strong> <?= h($loadError) ?></div>
        <?php endforeach; ?>
        <?php if (!function_exists('allstat_mcp_connections')): ?>
            <div class="notice notice-warn">Serverová část ještě není nainstalovaná. Nastavení jde uložit, ale připojení AI začne fungovat až po nahrání souborů lib/mcp-oauth.php, lib/mcp-server.php a lib/mcp-tools.php.</div>
        <?php endif; ?>
        <?php foreach ($errors as $formError): ?>
            <div class="notice notice-error"><?= h($formError) ?></div>
        <?php endforeach; ?>
        <?php foreach ($storedProblems as $storedProblem): ?>
            <div class="notice notice-warn"><strong>Uložené nastavení nevyhovuje pravidlům.</strong> <?= h($storedProblem) ?> Opravte ho a uložte znovu.</div>
        <?php endforeach; ?>

        <form method="post" class="form-stack mcp-settings-form" data-mcp-settings data-was-enabled="<?= $curEnabled ? '1' : '0' ?>">
            <?= allstat_csrf_field() ?>
            <input type="hidden" name="action" value="save_settings">
            <div class="form-grid">
                <label>
                    <span>Povolit připojení AI (MCP)</span>
                    <select name="mcp_enabled">
                        <option value="1"<?= $form['enabled'] === '1' ? ' selected' : '' ?>>Ano</option>
                        <option value="0"<?= $form['enabled'] !== '1' ? ' selected' : '' ?>>Ne</option>
                    </select>
                    <small class="field-help">Při „Ne“ všechny adresy konektoru odpovídají 404 a nikdo se nepřipojí. Vypnutí zároveň odpojí už připojené aplikace.</small>
                </label>
                <label>
                    <span>Adresa MCP serveru</span>
                    <input type="text" name="mcp_host_url" value="<?= h($form['host']) ?>" placeholder="https://allstat.example.cz" inputmode="url" autocomplete="off" spellcheck="false">
                    <small class="field-help">Adresa, na které běží složka allstat-mcp, nejlépe vlastní subdoména. Musí být HTTPS a bez lomítka na konci (http:// jen pro localhost).</small>
                </label>
                <label>
                    <span>Adresa AllStatu</span>
                    <input type="text" name="mcp_app_url" value="<?= h($form['app']) ?>" placeholder="https://www.example.cz/allstat" inputmode="url" autocomplete="off" spellcheck="false">
                    <small class="field-help">Kde běží tato administrace, včetně cesty. Sem se uživatel vrací k přihlášení a potvrzení přístupu.<?= $appPrefilled ? ' Předvyplněno podle aktuální adresy, zkontrolujte ji.' : '' ?></small>
                </label>
                <label>
                    <span>Další povolené návratové adresy (volitelné)</span>
                    <textarea name="mcp_extra_redirect_uris" rows="4" placeholder="https://aplikace.example.com/oauth/callback" spellcheck="false" autocomplete="off"><?= h($form['extra']) ?></textarea>
                    <small class="field-help">Jedna přesná adresa na řádek ve tvaru https://server/cesta (bez parametrů, server malými písmeny, http:// jen pro localhost a 127.0.0.1). Claude, ChatGPT a Claude Code jsou povolené automaticky, sem patří jen výjimky pro jiné aplikace.</small>
                </label>
            </div>
            <?php if ($curEnabled): ?>
                <label class="mcp-check" data-mcp-confirm-off>
                    <input type="checkbox" name="mcp_confirm_disable" value="1"<?= !empty($form['confirm']) ? ' checked' : '' ?>>
                    <span><strong>Opravdu odpojit všechny aplikace.</strong> Platí při přepnutí na „Ne“: vypnutí hned zneplatní všechna připojení (Claude, ChatGPT...) a aplikace se musí připojit znovu.</span>
                </label>
            <?php endif; ?>
            <div class="form-actions">
                <button class="button-primary" type="submit">Uložit nastavení</button>
            </div>
        </form>
    </div>
</div>

<div class="admin-card mcp-connector">
    <div class="admin-card-header">
        <div>
            <h2>Adresa konektoru</h2>
            <p>Tuto adresu vložte do Claude nebo ChatGPT jako vlastní (custom) konektor. Aplikace pak smí jen číst data všech webů v AllStatu.</p>
        </div>
    </div>
    <div class="admin-card-body">
        <?php if ($connectorUrl === ''): ?>
            <div class="notice notice-warn">Nejdřív vyplňte a uložte Adresu MCP serveru v nastavení výše, adresa konektoru se z ní odvodí.</div>
        <?php else: ?>
            <?php if (!$isOn): ?>
                <div class="notice notice-warn"><?= $curEnabled ? 'Připojení AI je zapnuté, ale chybí Adresa AllStatu. Doplňte ji v nastavení výše, jinak se nikdo nemůže přihlásit.' : 'Připojení AI je teď vypnuté, konektor nebude fungovat, dokud ho v nastavení nezapnete.' ?></div>
            <?php endif; ?>
            <div class="share-url-row">
                <input type="text" id="mcpConnectorUrl" readonly value="<?= h($connectorUrl) ?>" data-select-all aria-label="Adresa konektoru">
                <button type="button" class="button-secondary" data-mcp-copy="mcpConnectorUrl">Kopírovat</button>
            </div>
            <div class="form-actions">
                <a class="button-primary" href="<?= h($claudeAddUrl) ?>" target="_blank" rel="noopener noreferrer">Přidat do Claude</a>
                <span class="table-muted">Otevře Claude s předvyplněným konektorem. Pro ChatGPT použijte návod níže.</span>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2>Návody k připojení</h2>
            <p>Postup se v aplikacích občas mění, názvy položek jsou uvedené česky i anglicky.<?= $connectorUrl === '' ? ' Adresa konektoru v návodech je zatím jen příklad, nahradí se po uložení Adresy MCP serveru.' : '' ?></p>
        </div>
    </div>
    <div class="admin-card-body mcp-guide-list">
        <details class="admin-card-collapsible mcp-guide">
            <summary>
                <div>
                    <h2>Claude (web, desktop, mobil)</h2>
                    <p>Free, Pro, Max, Team a Enterprise</p>
                </div>
            </summary>
            <div class="admin-card-body">
                <ol class="setup-steps">
                    <li>V Claude otevřete <strong>Přizpůsobit (Customize) → Konektory (Connectors)</strong> a klikněte na <strong>Přidat vlastní konektor (Add custom connector)</strong>. Nebo použijte tlačítko „Přidat do Claude“ výše, které vyplní název i adresu.</li>
                    <li>Jako název zadejte <code>AllStat</code>, jako URL <code><?= h($guideUrl) ?></code> a potvrďte.</li>
                    <li>Klikněte na <strong>Připojit (Connect)</strong>. Otevře se AllStat: přihlaste se jako administrátor a klikněte na <strong>Povolit</strong>.</li>
                </ol>
                <p class="form-help">Volbu OAuth klienta nechte na výchozí („Use Claude's published identity“). Když přihlášení nefunguje, zkuste „Register automatically“.</p>
                <p class="form-help"><strong>Team a Enterprise:</strong> vlastník organizace nejdřív přidá konektor v <em>Organization settings → Connectors → Add → Custom → Web</em>. Členové pak jen kliknou na Connect.</p>
            </div>
        </details>
        <details class="admin-card-collapsible mcp-guide">
            <summary>
                <div>
                    <h2>ChatGPT</h2>
                    <p>Plus, Pro, Business, Enterprise a Edu, jen ve webové verzi</p>
                </div>
            </summary>
            <div class="admin-card-body">
                <ol class="setup-steps">
                    <li>V ChatGPT otevřete <strong>Settings → Security and login</strong> a zapněte <strong>Developer mode</strong>.</li>
                    <li>Otevřete <strong>Plugins</strong> (dříve Apps / Connectors), klikněte na <strong>+</strong> a vyplňte název <code>AllStat</code>, MCP URL <code><?= h($guideUrl) ?></code> a Authentication <strong>OAuth</strong>.</li>
                    <li>Přihlaste se do AllStatu jako administrátor a klikněte na <strong>Povolit</strong>.</li>
                </ol>
                <p class="form-help">Po aktualizaci AllStatu (nové nebo změněné nástroje) klikněte v konektoru na <strong>Refresh</strong>. V Business a Enterprise musí vývojářský režim nejdřív povolit správce workspace.</p>
            </div>
        </details>
        <details class="admin-card-collapsible mcp-guide">
            <summary>
                <div>
                    <h2>Claude Code</h2>
                    <p>Příkazová řádka</p>
                </div>
            </summary>
            <div class="admin-card-body">
                <div class="code-box">claude mcp add --transport http allstat <?= h($guideUrl) ?></div>
                <p class="form-help">Potom v Claude Code spusťte <code>/mcp</code>, vyberte <strong>allstat</strong> a zvolte <strong>Authenticate</strong>.</p>
            </div>
        </details>
        <div class="notice notice-info"><strong>Tip:</strong> po připojení napište například: „Udělej měsíční report webu za srpen.“</div>
    </div>
</div>

<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2>Připojené aplikace</h2>
            <p>Aplikace, které smí číst data z AllStatu. Odpojením ztratí přístup okamžitě, nový přístup musí administrátor znovu povolit.</p>
        </div>
    </div>
    <div class="admin-card-body table-scroll">
        <?php if (!function_exists('allstat_mcp_connections')): ?>
            <div class="notice notice-warn">Serverová část ještě není nainstalovaná.</div>
        <?php elseif ($connectionsError !== ''): ?>
            <div class="notice notice-error"><?= h($connectionsError) ?></div>
        <?php else: ?>
            <table class="admin-table mcp-stack">
                <thead><tr><th>Uživatel</th><th>Aplikace</th><th>Návrat na</th><th>Připojeno</th><th>Naposledy použito</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($connections as $connectionIndex => $connection): ?>
                    <?php
                        $appName = trim((string) ($connection['client_name'] ?? ''));
                        $redirectHost = (string) ($connection['redirect_host'] ?? '');
                        $appLabel = $appName !== '' ? $appName : ($redirectHost !== '' ? $redirectHost : 'Neznámá aplikace');
                        $clientHost = (string) parse_url((string) ($connection['client_id'] ?? ''), PHP_URL_HOST);
                        $identity = ($connection['client_type'] ?? '') === 'cimd' && $clientHost !== '' ? 'ověřeno z ' . $clientHost : 'automatická registrace';
                        $userName = trim((string) ($connection['user_name'] ?? ''));
                        $userEmail = (string) ($connection['user_email'] ?? '');
                        $userLabel = $userName !== '' ? $userName : ($userEmail !== '' ? $userEmail : 'Smazaný uživatel #' . (int) ($connection['user_id'] ?? 0));
                    ?>
                    <tr<?= $connectionIndex >= 10 ? ' class="row-extra"' : '' ?>>
                        <td data-label="Uživatel"><div class="mcp-cell"><strong><?= h($userLabel) ?></strong><?php if ($userName !== '' && $userEmail !== ''): ?><div class="table-muted"><?= h($userEmail) ?></div><?php endif; ?></div></td>
                        <td data-label="Aplikace"><div class="mcp-cell"><strong><?= h($appLabel) ?></strong><div class="table-muted"><?= h($identity) ?></div></div></td>
                        <td data-label="Návrat na"><div class="mcp-cell"><?= h($redirectHost !== '' ? $redirectHost : '–') ?></div></td>
                        <td data-label="Připojeno" data-sort="<?= h((string) ($connection['created_at'] ?? '')) ?>"><div class="mcp-cell"><?= h(allstat_mcp_admin_time($connection['created_at'] ?? null) ?: '–') ?></div></td>
                        <td data-label="Naposledy použito" data-sort="<?= h((string) ($connection['last_used_at'] ?? '')) ?>"><div class="mcp-cell"><?= ($lastUsed = allstat_mcp_admin_time($connection['last_used_at'] ?? null)) !== '' ? h($lastUsed) : '<span class="table-muted">zatím nepoužito</span>' ?></div></td>
                        <td class="mcp-actions">
                            <div class="table-actions">
                                <form method="post" data-confirm="<?= h('Odpojit aplikaci „' . $appLabel . '“' . ($userEmail !== '' ? ' uživatele ' . $userEmail : '') . '? Přestane číst data z AllStatu.') ?>">
                                    <?= allstat_csrf_field() ?>
                                    <input type="hidden" name="action" value="revoke_grant">
                                    <input type="hidden" name="grant_id" value="<?= h((string) ($connection['grant_id'] ?? '')) ?>">
                                    <button class="button-danger" type="submit">Odpojit</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$connections): ?>
                    <tr><td colspan="6" class="table-muted">Zatím není připojená žádná aplikace. Po přidání konektoru v Claude nebo ChatGPT se zde objeví.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <?= allstat_mcp_admin_show_more(count($connections)) ?>
        <?php endif; ?>
    </div>
</div>

<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2>Poslední aktivita</h2>
            <p>Posledních 50 volání z připojených aplikací, nejnovější nahoře. Zaznamenává se metoda, nástroj a parametry, ne obsah odpovědí. Záznamy se po 90 dnech mažou.</p>
        </div>
    </div>
    <div class="admin-card-body table-scroll">
        <?php if (!function_exists('allstat_mcp_recent_log')): ?>
            <div class="notice notice-warn">Serverová část ještě není nainstalovaná.</div>
        <?php elseif ($logError !== ''): ?>
            <div class="notice notice-error"><?= h($logError) ?></div>
        <?php else: ?>
            <table class="admin-table mcp-stack">
                <thead><tr><th>Čas</th><th>Uživatel</th><th>Aplikace</th><th>Metoda / nástroj</th><th>Stav</th><th class="num">ms</th><th class="num">Velikost</th></tr></thead>
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
                        $logBadge = ['ok' => ['ok', 'OK'], 'error' => ['error', 'Chyba'], 'denied' => ['warning', 'Zamítnuto']][$logStatus] ?? ['info', $logStatus !== '' ? $logStatus : '–'];
                        $logTool = trim((string) ($logRow['tool'] ?? ''));
                        $logArgs = trim((string) ($logRow['args_json'] ?? ''));
                        $logErrorText = trim((string) ($logRow['error'] ?? ''));
                        $logMeta = trim('IP ' . (string) ($logRow['ip'] ?? '') . ' ' . (string) ($logRow['user_agent'] ?? ''));
                    ?>
                    <tr<?= $logIndex >= 10 ? ' class="row-extra"' : '' ?>>
                        <td data-label="Čas" title="<?= h($logMeta) ?>" data-sort="<?= h((string) ($logRow['created_at'] ?? '')) ?>"><div class="mcp-cell"><?= h(allstat_mcp_admin_time($logRow['created_at'] ?? null, true) ?: '–') ?></div></td>
                        <td data-label="Uživatel"<?= $logUserEmail !== '' ? ' title="' . h($logUserEmail) . '"' : '' ?>><div class="mcp-cell"><?= h($logUserLabel !== '' ? $logUserLabel : '–') ?></div></td>
                        <td data-label="Aplikace"><div class="mcp-cell"><?= h(trim((string) ($logRow['client_name'] ?? '')) ?: '–') ?></div></td>
                        <td data-label="Metoda / nástroj">
                            <div class="mcp-cell">
                                <?php if ($logTool !== ''): ?><code><?= h($logTool) ?></code><?php else: ?><?= h((string) ($logRow['method'] ?? '–')) ?><?php endif; ?>
                                <?php if ($logArgs !== ''): ?><div class="table-muted mcp-args" title="<?= h($logArgs) ?>"><?= h(mb_strimwidth($logArgs, 0, 90, '...')) ?></div><?php endif; ?>
                            </div>
                        </td>
                        <td data-label="Stav">
                            <div class="mcp-cell">
                                <span class="status-badge status-<?= h($logBadge[0]) ?>"><?= h($logBadge[1]) ?></span>
                                <?php if ($logErrorText !== ''): ?><div class="table-muted mcp-args" title="<?= h($logErrorText) ?>"><?= h(mb_strimwidth($logErrorText, 0, 60, '...')) ?></div><?php endif; ?>
                            </div>
                        </td>
                        <td data-label="ms" class="num"><div class="mcp-cell"><?= h(allstat_number((int) ($logRow['duration_ms'] ?? 0))) ?></div></td>
                        <td data-label="Velikost" class="num"><div class="mcp-cell"><?= h(allstat_mcp_admin_bytes($logRow['response_bytes'] ?? 0)) ?></div></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$logRows): ?>
                    <tr><td colspan="7" class="table-muted">Zatím žádná aktivita. Zaznamená se každé volání z připojených aplikací.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <?= allstat_mcp_admin_show_more(count($logRows)) ?>
        <?php endif; ?>
    </div>
</div>

<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2>Nástroje</h2>
            <p>Co může připojená AI z AllStatu zjistit. Všechny nástroje jsou jen pro čtení a vracejí agregovaná analytická data, žádné přihlašovací údaje ani tokeny.</p>
        </div>
    </div>
    <div class="admin-card-body">
        <?php if (!function_exists('allstat_mcp_tool_definitions')): ?>
            <div class="notice notice-warn">Serverová část ještě není nainstalovaná.</div>
        <?php elseif ($toolsError !== ''): ?>
            <div class="notice notice-error"><?= h($toolsError) ?></div>
        <?php else: ?>
            <div class="mcp-tool-list">
                <?php foreach ($tools as $tool): ?>
                    <div class="mcp-tool">
                        <div class="mcp-tool-head"><strong><?= h((string) (($tool['title'] ?? '') !== '' ? $tool['title'] : ($tool['name'] ?? ''))) ?></strong> <code><?= h((string) ($tool['name'] ?? '')) ?></code></div>
                        <p><?= h((string) ($tool['description'] ?? '')) ?></p>
                    </div>
                <?php endforeach; ?>
                <?php if (!$tools): ?>
                    <p class="table-muted">Žádné nástroje.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="admin-card" id="kontrola">
    <div class="admin-card-header">
        <div>
            <h2>Kontrola nastavení</h2>
            <p>Server AllStatu si sám zavolá vaše adresy tak, jak to udělá Claude nebo ChatGPT. Neposílá žádné skutečné tokeny, jen neplatný token „neplatny“.</p>
        </div>
        <div class="card-header-actions">
            <form method="post" data-mcp-check-form>
                <?= allstat_csrf_field() ?>
                <input type="hidden" name="action" value="run_check">
                <button class="button-primary" type="submit"<?= $canCheck ? '' : ' disabled' ?>>Spustit kontrolu</button>
            </form>
        </div>
    </div>
    <div class="admin-card-body">
        <?php if (!$canCheck): ?>
            <p class="table-muted">Kontrolu jde spustit, až je připojení AI zapnuté a obě adresy jsou uložené (jinak server záměrně odpovídá 404).</p>
        <?php elseif ($check === null): ?>
            <p class="table-muted">Kontrola zatím neproběhla. Trvá několik sekund a zkusí všechny adresy najednou.</p>
        <?php else: ?>
            <?php
                $counts = ['ok' => 0, 'error' => 0, 'warning' => 0, 'info' => 0];
                foreach ($check['items'] as $checkItem) {
                    $counts[$checkItem['status'] ?? 'info'] = ($counts[$checkItem['status'] ?? 'info'] ?? 0) + 1;
                }
                $summaryClass = $counts['error'] > 0 ? 'notice-error' : ($counts['warning'] > 0 ? 'notice-warn' : 'notice-ok');
            ?>
            <div class="notice <?= h($summaryClass) ?>">
                <?php if ($counts['error'] > 0): ?>
                    Kontrola našla chyby (<?= (int) $counts['error'] ?>), konektor pravděpodobně nepůjde připojit. Postupujte podle rad u jednotlivých položek.
                <?php elseif ($counts['warning'] > 0): ?>
                    Kontrola proběhla bez chyb, ale s upozorněním (<?= (int) $counts['warning'] ?>).
                <?php else: ?>
                    Vše v pořádku, konektor můžete přidat do Claude nebo ChatGPT.
                <?php endif; ?>
                <span class="mcp-check-meta">Provedeno v <?= h(date('H:i:s', (int) ($check['at'] ?? time()))) ?>, trvalo <?= h((float) ($check['took'] ?? 0) < 0.1 ? 'méně než 0,1 s' : allstat_number((float) $check['took'], 1) . ' s') ?>.</span>
            </div>
            <div class="sync-result-list">
                <?php foreach ($check['items'] as $checkItem): ?>
                    <?php $checkStatus = (string) ($checkItem['status'] ?? 'info'); ?>
                    <div class="sync-result-item">
                        <div class="sync-result-text">
                            <strong><?= h($checkItem['title'] ?? '') ?></strong>
                            <small class="mcp-check-target"><code><?= h($checkItem['target'] ?? '') ?></code></small>
                            <small><?= h($checkItem['detail'] ?? '') ?></small>
                            <?php if (($checkItem['hint'] ?? '') !== ''): ?>
                                <small class="mcp-hint"><strong>Co s tím:</strong> <?= h($checkItem['hint']) ?></small>
                            <?php endif; ?>
                        </div>
                        <span class="status-badge status-<?= h($checkStatus) ?>"><?= h($checkLabels[$checkStatus] ?? $checkStatus) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($counts['error'] > 0): ?>
                <p class="form-help">Když selhaly všechny položky, server AllStatu se možná nedokáže dovolat na vlastní veřejnou adresu (omezení hostingu). Otevřete v prohlížeči <code><?= h($curHost) ?>/.well-known/oauth-protected-resource</code>: měl by se zobrazit JSON s polem resource.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

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
                try { document.execCommand('copy'); flashLabel(button, 'Zkopírováno ✓'); } catch (e) { flashLabel(button, 'Stiskněte Ctrl+C'); }
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(input.value).then(() => flashLabel(button, 'Zkopírováno ✓'), fallback);
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
                if (window.confirm('Vypnutím se odpojí všechny připojené aplikace (Claude, ChatGPT...). Opravdu pokračovat?')) {
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
            if (submit) { setTimeout(() => { submit.disabled = true; submit.textContent = 'Kontroluji...'; }, 0); }
        });
    }
})();
</script>
<?php allstat_admin_footer($config); ?>
