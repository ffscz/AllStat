<?php
/**
 * Aktualizace AllStatu z veřejných vydání (github.com/ffscz/AllStat).
 *
 * Funguje jen v instalaci z veřejného balíčku: ta má v kořeni soubor VERSION a lib/manifest.json. Interní vývojová
 * instalace je nemá, updater se tam neukazuje a aktualizace nasazuje vývojář.
 *
 * Postup instalace (allstat_update_install): stáhnout ZIP z feedu → ověřit SHA-256 a ECDSA podpis veřejným klíčem
 * zabudovaným níže (bez platného podpisu se nic neinstaluje, ani když by někdo podvrhl feed) → rozbalit do
 * _update/stage → zkontrolovat VERSION a manifest → zálohovat soubory, které se přepíšou nebo smažou → vyměnit
 * soubory (rename, při chybě vrátit) → databázi doplní migrace při dalším požadavku (jen přidávají, nemažou).
 * config.php, config-keys.php a data se nikdy nemění. Upravené .htaccess se nepřepíší (nová verze vedle jako .new).
 */

const ALLSTAT_UPDATE_FEED = 'https://raw.githubusercontent.com/ffscz/AllStat/main/update.json';
const ALLSTAT_UPDATE_PUBLIC_KEY = "-----BEGIN PUBLIC KEY-----\n"
    . "MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE0lgY9cuzS2xv6pc5egfZ0UBwBmy/\n"
    . "tHnTLSUpgydNP42pLAvtKXsXNJmbHXfZNefCCBSBH5JlSmo1EoSmpfmyWA==\n"
    . "-----END PUBLIC KEY-----\n";
const ALLSTAT_UPDATE_CHECK_TTL = 43200;          // kontrola nové verze nejvýš jednou za 12 h
const ALLSTAT_UPDATE_MAX_BYTES = 50 * 1024 * 1024;
const ALLSTAT_UPDATE_KEEP_BACKUPS = 2;
const ALLSTAT_UPDATE_NEVER_TOUCH = ['config.php', 'config-keys.php'];

function allstat_update_root(): string
{
    return str_replace('\\', '/', dirname(__DIR__));
}

/** Nainstalovaná verze z kořenového souboru VERSION, null = interní instalace bez updateru. */
function allstat_installed_version(): ?string
{
    $version = trim((string) @file_get_contents(allstat_update_root() . '/VERSION'));

    return preg_match('/^\d+\.\d+\.\d+$/', $version) === 1 ? $version : null;
}

/** Pracovní složka _update (záloha, rozbalený balíček), zvenku nepřístupná. */
function allstat_update_dir(): string
{
    $dir = allstat_update_root() . '/_update';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (is_dir($dir) && !is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        @file_put_contents($dir . '/index.html', '');
    }

    return $dir;
}

function allstat_update_fetch(string $url, int $timeout = 20): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: AllStat-Updater/" . (allstat_installed_version() ?? 'dev') . "\r\nAccept: */*\r\n",
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 1,
            'max_redirects' => 5,
        ],
    ]);
    $body = @file_get_contents($url, false, $context, 0, ALLSTAT_UPDATE_MAX_BYTES + 1);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int) $m[1]; // po přesměrování platí poslední stav
        }
    }

    return ['status' => $status, 'body' => is_string($body) ? $body : ''];
}

function allstat_update_feed_url(PDO $pdo): string
{
    // Jiný feed (test, zrcadlo) je bezpečný: instaluje se jen balíček podepsaný klíčem výše.
    $custom = trim((string) allstat_setting($pdo, 'update.feed_url', ''));

    return preg_match('#^https?://#i', $custom) ? $custom : ALLSTAT_UPDATE_FEED;
}

/**
 * Zjistí nejnovější vydání. Výsledek se drží v allstat_settings (update.cache), síť se volá jen po uplynutí TTL
 * nebo s $force. Vrací ['installed', 'latest' => ?array, 'available' => bool, 'checked_at' => ?string, 'error' => ?string].
 */
function allstat_update_check(PDO $pdo, bool $force = false, int $timeout = 15): array
{
    $installed = allstat_installed_version();
    $cache = json_decode((string) allstat_setting($pdo, 'update.cache', ''), true);
    $cache = is_array($cache) ? $cache : [];
    $fresh = isset($cache['checked_at']) && time() - strtotime((string) $cache['checked_at']) < ALLSTAT_UPDATE_CHECK_TTL;

    if ($installed !== null && ($force || !$fresh)) {
        $response = allstat_update_fetch(allstat_update_feed_url($pdo), $timeout);
        $feed = $response['status'] === 200 ? json_decode($response['body'], true) : null;
        $latest = is_array($feed) ? allstat_update_normalize_feed($feed) : null;
        $cache = [
            'checked_at' => date('Y-m-d H:i:s'),
            'latest' => $latest ?? ($cache['latest'] ?? null),
            'error' => $latest === null ? 'Feed s verzemi není dostupný (HTTP ' . $response['status'] . ').' : null,
        ];
        allstat_set_setting($pdo, 'update.cache', json_encode($cache, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    $latest = is_array($cache['latest'] ?? null) ? $cache['latest'] : null;

    return [
        'installed' => $installed,
        'latest' => $latest,
        'available' => $installed !== null && $latest !== null && version_compare($latest['version'], $installed, '>'),
        'checked_at' => $cache['checked_at'] ?? null,
        'error' => $cache['error'] ?? null,
    ];
}

function allstat_update_normalize_feed(array $feed): ?array
{
    $version = (string) ($feed['version'] ?? '');
    $zip = (string) ($feed['zip_url'] ?? '');
    $sha = strtolower((string) ($feed['sha256'] ?? ''));
    $sig = (string) ($feed['signature'] ?? '');
    // ZIP jen přes HTTPS (http jen z localhostu pro test updateru); hlavní ochranou je stejně podpis.
    if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1 || !preg_match('#^(https://|http://(localhost|127\.0\.0\.1)[:/])#i', $zip)
        || preg_match('/^[a-f0-9]{64}$/', $sha) !== 1 || $sig === '' || base64_decode($sig, true) === false) {
        return null;
    }

    return [
        'version' => $version,
        'zip_url' => $zip,
        'sha256' => $sha,
        'signature' => $sig,
        'published' => mb_substr((string) ($feed['published'] ?? ''), 0, 40),
        'notes' => mb_substr((string) ($feed['notes'] ?? ''), 0, 8000),
        'min_php' => preg_match('/^\d+\.\d+(\.\d+)?$/', (string) ($feed['min_php'] ?? '')) ? (string) $feed['min_php'] : '8.1',
        'html_url' => preg_match('#^https://#i', (string) ($feed['html_url'] ?? '')) ? (string) $feed['html_url'] : '',
    ];
}

/** Upozornění do bočního panelu: jen administrátor, jen veřejná instalace, bez síťového volání (jen cache). */
function allstat_update_notice(PDO $pdo, array $user, ?array $config = null): ?array
{
    if (($user['role'] ?? '') !== 'admin' || allstat_installed_version() === null) {
        return null;
    }
    // Čekající .htaccess.new (nechala ho tam starší verze updateru): doplnit pravidla na kterékoli stránce administrátora,
    // ne jen na stránce Aktualizace, kam se po aktualizaci nikdo vracet nemusí.
    if ($config !== null && is_file(allstat_update_root() . '/.htaccess.new')) {
        try {
            if (allstat_update_htaccess_autofix($pdo, allstat_update_selftest_url($config)) === 'merged' && function_exists('allstat_flash')) {
                allstat_flash('ok', 'Pravidla AllStatu v souboru .htaccess jsou doplněná automaticky, vlastní úpravy zůstaly beze změny.');
            }
        } catch (Throwable) {
            // nevyšlo, karta na stránce Aktualizace zůstane
        }
    }
    try {
        $cache = json_decode((string) allstat_setting($pdo, 'update.cache', ''), true);
        $stale = !is_array($cache) || !isset($cache['checked_at']) || time() - strtotime((string) $cache['checked_at']) > 2 * ALLSTAT_UPDATE_CHECK_TTL;
        // Když cron neběží, zkontroluje se to tady, krátce a nejvýš jednou za 24 h.
        $status = allstat_update_check($pdo, false, $stale ? 4 : 1);
    } catch (Throwable) {
        return null;
    }

    return $status['available'] ? ['version' => $status['latest']['version'], 'installed' => $status['installed']] : null;
}

/** Ověří stažený balíček: SHA-256 z feedu a ECDSA podpis (P-256, SHA-256) veřejným klíčem aplikace. */
function allstat_update_verify_package(string $zipPath, array $latest): ?string
{
    if (!hash_equals($latest['sha256'], hash_file('sha256', $zipPath))) {
        return 'Kontrolní součet staženého balíčku nesedí (poškozené nebo nedokončené stažení).';
    }
    $signature = base64_decode($latest['signature'], true);
    if ($signature === false || openssl_verify((string) file_get_contents($zipPath), $signature, ALLSTAT_UPDATE_PUBLIC_KEY, OPENSSL_ALGO_SHA256) !== 1) {
        return 'Podpis balíčku není platný. Aktualizace byla zastavena, nic se nezměnilo.';
    }

    return null;
}

/**
 * Rozbalí ZIP (vlastní čtečka, rozšíření zip na hostingu být nemusí; metody stored a deflate) do $dest.
 * Bere jen soubory pod horní složkou $top, kontroluje cesty i CRC. Vrací seznam relativních cest.
 */
function allstat_update_zip_extract(string $zipPath, string $dest, string $top = 'allstat'): array
{
    $data = (string) file_get_contents($zipPath);
    $eocd = strrpos($data, "PK\x05\x06");
    if ($eocd === false) {
        throw new RuntimeException('Balíček není platný ZIP.');
    }
    $info = unpack('vdisk/vcdisk/ventries/vtotal/Vsize/Voffset', substr($data, $eocd + 4, 16));
    $pos = (int) $info['offset'];
    $files = [];
    for ($i = 0; $i < (int) $info['total']; $i++) {
        if (substr($data, $pos, 4) !== "PK\x01\x02") {
            throw new RuntimeException('Poškozený adresář ZIP.');
        }
        $c = unpack('vmade/vneed/vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/viattr/Veattr/Vlocal', substr($data, $pos + 4, 42));
        $name = substr($data, $pos + 46, $c['nlen']);
        $pos += 46 + $c['nlen'] + $c['elen'] + $c['clen'];
        if (str_ends_with($name, '/')) {
            continue;
        }
        if (!str_starts_with($name, $top . '/') || str_contains($name, '\\') || str_contains($name, "\0") || preg_match('#(^|/)\.\.(/|$)#', $name)) {
            throw new RuntimeException('Nepovolená cesta v balíčku: ' . mb_substr($name, 0, 80));
        }
        $rel = substr($name, strlen($top) + 1);
        $local = unpack('Vsig/vneed/vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen', substr($data, $c['local'], 30));
        $raw = substr($data, $c['local'] + 30 + $local['nlen'] + $local['elen'], $c['csize']);
        $content = match ((int) $c['method']) {
            0 => $raw,
            8 => gzinflate($raw),
            default => false,
        };
        if (!is_string($content) || strlen($content) !== (int) $c['usize'] || (hexdec(hash('crc32b', $content)) & 0xFFFFFFFF) !== ($c['crc'] & 0xFFFFFFFF)) {
            throw new RuntimeException('Soubor ' . $rel . ' v balíčku je poškozený.');
        }
        $target = $dest . '/' . $rel;
        if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0755, true)) {
            throw new RuntimeException('Nelze vytvořit složku pro ' . $rel);
        }
        if (@file_put_contents($target, $content) === false) {
            throw new RuntimeException('Nelze zapsat ' . $rel);
        }
        $files[] = $rel;
    }

    return $files;
}

function allstat_update_read_manifest(string $path): array
{
    $manifest = json_decode((string) @file_get_contents($path), true);

    return is_array($manifest) ? $manifest + ['files' => [], 'protected' => []] : ['version' => null, 'files' => [], 'protected' => []];
}

/** Soubory instalace, které se od posledního balíčku ručně změnily (podle lib/manifest.json). */
function allstat_update_modified_files(): array
{
    $root = allstat_update_root();
    $manifest = allstat_update_read_manifest($root . '/lib/manifest.json');
    $modified = [];
    foreach ($manifest['files'] as $rel => $meta) {
        $path = $root . '/' . $rel;
        if (is_file($path) && hash_file('sha256', $path) !== ($meta['h'] ?? '')) {
            $modified[] = $rel;
        }
    }

    return $modified;
}

/**
 * Upravené chráněné soubory (.htaccess), vedle kterých aktualizace nechala novou verzi (.new). Vrací bloky nové verze,
 * jejichž pravidla v upraveném souboru chybí (blok = řádky mezi prázdnými řádky, komentář nad pravidly ho popisuje).
 * Když nic nechybí, je .new zbytečný a smaže se, karta na stránce Aktualizace se pak neukáže.
 *
 * @return list<array{file: string, blocks: list<array{title: string, code: string}>}>
 */
function allstat_update_protected_pending(): array
{
    $root = allstat_update_root();
    $manifest = allstat_update_read_manifest($root . '/lib/manifest.json');
    $normalize = static fn (string $line): string => (string) preg_replace('/\s+/', ' ', trim($line));
    $pending = [];
    foreach (array_keys($manifest['protected']) as $rel) {
        $newPath = $root . '/' . $rel . '.new';
        if (!is_file($newPath) || !is_file($root . '/' . $rel)) {
            continue;
        }
        $have = [];
        foreach (preg_split('/\R/', (string) file_get_contents($root . '/' . $rel)) ?: [] as $line) {
            $line = $normalize($line);
            if ($line !== '' && !str_starts_with($line, '#')) {
                $have[$line] = true;
            }
        }
        $blocks = [];
        foreach (preg_split('/\R[ \t]*\R/', trim((string) file_get_contents($newPath))) ?: [] as $chunk) {
            $title = [];
            $hasRules = false;
            $missing = false;
            $chunk = implode("\n", array_filter(preg_split('/\R/', $chunk) ?: [], static fn (string $l): bool => !preg_match('/^\s*# (BEGIN|END) AllStat\b/', $l)));
            foreach (preg_split('/\R/', $chunk) ?: [] as $line) {
                $line = $normalize($line);
                if ($line === '') {
                    continue;
                }
                if (str_starts_with($line, '#')) {
                    $title[] = ltrim(substr($line, 1));
                    continue;
                }
                $hasRules = true;
                $missing = $missing || !isset($have[$line]);
            }
            if ($hasRules && $missing) {
                $blocks[] = ['title' => implode(' ', $title), 'code' => trim($chunk)];
            }
        }
        if ($blocks === []) {
            @unlink($newPath); // pravidla nové verze už v souboru jsou, .new nic nepřináší
            continue;
        }
        $pending[] = ['file' => $rel, 'blocks' => $blocks];
    }

    return $pending;
}

/** Smaže novou verzi chráněného souboru (.new), když si správce nechává svůj soubor. Jen pro soubory z manifestu. */
function allstat_update_protected_dismiss(string $rel): bool
{
    $root = allstat_update_root();
    $manifest = allstat_update_read_manifest($root . '/lib/manifest.json');

    return isset($manifest['protected'][$rel]) && is_file($root . '/' . $rel . '.new') && @unlink($root . '/' . $rel . '.new');
}

/** Značky bloku pravidel AllStatu v .htaccess: aktualizace přepisuje jen obsah mezi nimi, zbytek souboru nechává. */
const ALLSTAT_HTACCESS_BEGIN = '# BEGIN AllStat';
const ALLSTAT_HTACCESS_END = '# END AllStat';

/**
 * Standardní .htaccess balíčků 1.0.0 až 1.2.7 (bez značek BEGIN/END AllStat). Když ho upravený soubor obsahuje
 * celý, nahradí ho aktualizace novou verzí se značkami a úpravy okolo nechá.
 */
const ALLSTAT_HTACCESS_LEGACY = [<<<'HTACCESS'
# AllStat: bezpečnostní pravidla (Apache 2.4 nebo LiteSpeed). PHP soubory se normálně vykonávají,
# blokujeme jen přímé stažení citlivých souborů a přidáváme bezpečnostní hlavičky.
#
# Kdyby po nahrání na hosting web hlásil "Internal Server Error" (chyba 500), soubor .htaccess
# přejmenujte na .htaccess.off a stránku obnovte. Pak požádejte poskytovatele hostingu, aby
# ve složce povolil direktivy Options a Require (AllowOverride), a přejmenujte ho zpět.

# Zákaz výpisu adresářů
Options -Indexes

# Bezpečnostní hlavičky
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>

# Zákaz stažení citlivých souborů: SQL schéma, zálohy, dokumentace, logy a dočasné soubory.
<FilesMatch "(?i)\.(sql|md|docx?|log|bak|old|orig|save|inc|ini|ya?ml|lock|dist|sh|tmp)$|~$">
    Require all denied
</FilesMatch>

# Konfigurace a šifrovací klíč: i kdyby PHP na serveru přestalo fungovat, zdrojový kód se nesmí zobrazit.
<FilesMatch "^(config|config-keys)\.php$">
    Require all denied
</FilesMatch>

# Číslo verze a příručka nemají být z internetu vidět
<FilesMatch "^(VERSION|README\.txt)$">
    Require all denied
</FilesMatch>

# Chybové logy hostingu (soubory "error_log" bez přípony)
<FilesMatch "^error_log$">
    Require all denied
</FilesMatch>

# Skryté soubory (.git, .env, ...), kromě adres .well-known, které potřebuje připojení AI (MCP)
<FilesMatch "^\.(?!well-known)">
    Require all denied
</FilesMatch>

# Připojení AI (MCP): adresy MCP serveru a přihlašování (OAuth) obsluhuje front controller ve složce
# allstat-mcp. Pravidla jsou trvale zapnutá, ale dokud není připojení AI zapnuté v menu "AI konektory",
# odpovídá server na tyto adresy chybou 404. Hlavička Authorization se musí dostat až do PHP.
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
    RewriteRule ^(?:mcp|token|register|revoke|authorize|jwks\.json)/?$ allstat-mcp/index.php [L]
    RewriteRule ^\.well-known/(?:oauth-authorization-server|openid-configuration|oauth-protected-resource)(?:/.*)?$ allstat-mcp/index.php [L]
</IfModule>
HTACCESS];

/**
 * Sloučí pravidla AllStatu z nové verze .htaccess do upraveného souboru. Přepíše jen blok mezi značkami BEGIN/END AllStat,
 * standardní obsah starších verzí (bez značek) nahradí celou novou verzí, jinak blok připojí na konec. Vlastní úpravy mimo
 * blok zůstanou, konce řádků podle upraveného souboru. Null = nová verze blok nemá, sloučit nejde.
 */
function allstat_update_htaccess_merge(string $current, string $new): ?string
{
    $eol = str_contains($current, "\r\n") ? "\r\n" : "\n";
    $current = str_replace("\r\n", "\n", $current);
    $new = str_replace("\r\n", "\n", $new);
    $pattern = '/^' . preg_quote(ALLSTAT_HTACCESS_BEGIN, '/') . '\b.*?^' . preg_quote(ALLSTAT_HTACCESS_END, '/') . '\b[^\n]*/ms';
    if (preg_match($pattern, $new, $match) !== 1) {
        return null;
    }
    $block = $match[0];
    if (preg_match($pattern, $current) === 1) {
        $merged = (string) preg_replace_callback($pattern, static fn (): string => $block, $current, 1);
    } else {
        $merged = null;
        foreach (ALLSTAT_HTACCESS_LEGACY as $legacy) {
            $legacy = trim(str_replace("\r\n", "\n", $legacy));
            if ($legacy !== '' && str_contains($current, $legacy)) {
                $merged = str_replace($legacy, trim($new), $current);
                break;
            }
        }
        $merged ??= rtrim($current) . "\n\n" . $block . "\n";
    }

    return str_replace("\n", $eol, $merged);
}

/** Odpovídá AllStat po změně .htaccess? true = ano (2xx/3xx), false = ne (chyba serveru, zákaz), null = nejde ověřit. */
function allstat_update_selftest(string $url): ?bool
{
    if ($url === '') {
        return null;
    }
    $status = allstat_update_fetch($url, 8)['status'];

    return $status === 0 ? null : ($status >= 200 && $status < 400);
}

/**
 * Doplní pravidla z čekající nové verze (.htaccess.new, nechala ji tam starší verze updateru) do upraveného .htaccess
 * stejně jako aktualizace: sloučit, ověřit, že AllStat odpovídá, jinak vrátit původní soubor. Stejnou dvojici souborů
 * zkouší jen jednou. Vrací 'merged', 'failed', nebo null, když nebylo co dělat.
 */
function allstat_update_htaccess_autofix(PDO $pdo, string $selfTestUrl): ?string
{
    $path = allstat_update_root() . '/.htaccess';
    if (!is_file($path) || !is_file($path . '.new')) {
        return null;
    }
    $current = (string) file_get_contents($path);
    $new = (string) file_get_contents($path . '.new');
    $attempt = hash('sha256', $current . "\0" . $new . "\0" . (allstat_installed_version() ?? '')); // nová verze zkusí znovu
    $merged = allstat_update_htaccess_merge($current, $new);
    if ($merged === null || allstat_setting($pdo, 'update.htaccess_autofix', '') === $attempt) {
        return null;
    }
    @copy($path, allstat_update_dir() . '/htaccess-' . date('Ymd-His') . '.bak');
    if (file_put_contents($path, $merged) === false) {
        return null;
    }
    if (allstat_update_selftest($selfTestUrl) === true) {
        @unlink($path . '.new');

        return 'merged';
    }
    file_put_contents($path, $current);
    allstat_set_setting($pdo, 'update.htaccess_autofix', $attempt);

    return 'failed';
}

/**
 * Po výměně souborů vyhodit PHP soubory z OPcache. Hosting kontroluje změny jen jednou za pár sekund
 * (opcache.revalidate_freq), takže by stránka hned po aktualizaci ještě běžela ve staré verzi.
 */
function allstat_update_opcache_invalidate(string $root, array $files): void
{
    if (!function_exists('opcache_invalidate')) {
        return;
    }
    foreach ($files as $rel) {
        if (str_ends_with((string) $rel, '.php')) {
            @opcache_invalidate($root . '/' . $rel, true);
        }
    }
}

/** Adresa, na které updater po změně .htaccess ověří, že AllStat odpovídá (stránka přihlášení této instalace). */
function allstat_update_selftest_url(array $config): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . allstat_url($config, 'admin/login.php');
}

/** Kontrola před aktualizací: verze PHP, rozšíření, zápis do složek, místo, ručně upravené soubory. */
function allstat_update_preflight(array $latest): array
{
    $root = allstat_update_root();
    $checks = [];
    $checks[] = ['ok' => version_compare(PHP_VERSION, $latest['min_php'], '>='), 'label' => 'PHP ' . PHP_VERSION . ' (nová verze potřebuje ' . $latest['min_php'] . ' a novější)'];
    $checks[] = ['ok' => function_exists('openssl_verify') && function_exists('gzinflate'), 'label' => 'Rozšíření openssl a zlib (ověření podpisu, rozbalení)'];
    $writable = is_writable($root);
    foreach (['lib', 'admin', 'assets', 'views', 'api', 'cron'] as $dir) {
        $writable = $writable && (!is_dir($root . '/' . $dir) || is_writable($root . '/' . $dir));
    }
    $checks[] = ['ok' => $writable, 'label' => 'Zápis do složky aplikace'];
    $free = @disk_free_space($root);
    $checks[] = ['ok' => $free === false || $free > 30 * 1024 * 1024, 'label' => 'Volné místo na disku (aspoň 30 MB)'];

    return ['ok' => !in_array(false, array_column($checks, 'ok'), true), 'checks' => $checks, 'modified' => allstat_update_modified_files()];
}

function allstat_update_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

function allstat_update_history(PDO $pdo): array
{
    $history = json_decode((string) allstat_setting($pdo, 'update.history', ''), true);

    return is_array($history) ? $history : [];
}

/**
 * Nainstaluje nejnovější vydání. Vrací ['ok' => bool, 'message' => string, 'warnings' => list<string>].
 * Do výměny souborů se nic nemění; když výměna selže uprostřed, vrátí už přepsané soubory ze zálohy.
 * $reinstall = obnova z GitHubu i bez novější verze (po nepovedeném ručním nasazení): nainstaluje nejnovější vydání,
 * i když je stejné nebo starší než nainstalovaná verze. Databáze zůstává, migrace jen přidávají, takže starší kód s ní funguje.
 * $selfTestUrl = adresa přihlášení AllStatu: s ní se pravidla doplní i do upraveného .htaccess (jen blok BEGIN/END AllStat)
 * a po výměně se ověří, že AllStat odpovídá; bez ní nebo když ověření nevyjde, zůstane nová verze vedle jako .new.
 */
function allstat_update_install(PDO $pdo, int $actorId, bool $reinstall = false, ?string $selfTestUrl = null): array
{
    $installed = allstat_installed_version();
    if ($installed === null) {
        return ['ok' => false, 'message' => 'Tato instalace nepochází z veřejného balíčku, aktualizace nasazuje vývojář.', 'warnings' => []];
    }
    if ((int) $pdo->query("SELECT GET_LOCK('allstat_update', 0)")->fetchColumn() !== 1) {
        return ['ok' => false, 'message' => 'Aktualizace už probíhá v jiném okně.', 'warnings' => []];
    }
    @set_time_limit(300);
    ignore_user_abort(true);

    $root = allstat_update_root();
    $work = allstat_update_dir();
    $stage = $work . '/stage';
    $warnings = [];
    try {
        $status = allstat_update_check($pdo, true);
        $latest = $status['latest'];
        if ($latest === null || (!$reinstall && !$status['available'])) {
            throw new RuntimeException($latest === null ? 'Nepodařilo se zjistit vydání na GitHubu.' : 'Není k dispozici novější verze.');
        }
        $preflight = allstat_update_preflight($latest);
        if (!$preflight['ok']) {
            throw new RuntimeException('Kontrola serveru neprošla, podrobnosti jsou na stránce Aktualizace.');
        }

        // 1) Stažení a ověření (SHA-256 + podpis).
        $zipPath = $work . '/allstat-' . $latest['version'] . '.zip';
        $download = allstat_update_fetch($latest['zip_url'], 120);
        if ($download['status'] !== 200 || $download['body'] === '' || strlen($download['body']) > ALLSTAT_UPDATE_MAX_BYTES) {
            throw new RuntimeException('Balíček se nepodařilo stáhnout (HTTP ' . $download['status'] . ').');
        }
        file_put_contents($zipPath, $download['body']);
        if ($error = allstat_update_verify_package($zipPath, $latest)) {
            @unlink($zipPath);
            throw new RuntimeException($error);
        }

        // 2) Rozbalení a kontrola obsahu.
        allstat_update_rrmdir($stage);
        mkdir($stage, 0755, true);
        $packageFiles = allstat_update_zip_extract($zipPath, $stage);
        $packageVersion = trim((string) @file_get_contents($stage . '/VERSION'));
        if ($packageVersion !== $latest['version']) {
            throw new RuntimeException('Verze v balíčku (' . $packageVersion . ') nesedí s vydáním ' . $latest['version'] . '.');
        }
        $newManifest = allstat_update_read_manifest($stage . '/lib/manifest.json');
        foreach ($newManifest['files'] as $rel => $meta) {
            if (!is_file($stage . '/' . $rel) || hash_file('sha256', $stage . '/' . $rel) !== ($meta['h'] ?? '')) {
                throw new RuntimeException('Balíček je neúplný (' . $rel . ').');
            }
        }
        $oldManifest = allstat_update_read_manifest($root . '/lib/manifest.json');

        // 3) Plán: co se zapíše, co zůstane (upravené .htaccess), co se smaže (soubory, které nová verze nemá).
        $write = [];
        $keptProtected = [];
        $mergedProtected = [];
        foreach ($packageFiles as $rel) {
            if (in_array($rel, ALLSTAT_UPDATE_NEVER_TOUCH, true) || str_starts_with($rel, '_update/')) {
                continue;
            }
            $target = $root . '/' . $rel;
            if (isset($newManifest['protected'][$rel]) && is_file($target)) {
                $current = hash_file('sha256', $target);
                $oldHash = $oldManifest['protected'][$rel]['h'] ?? null;
                $newHash = $newManifest['protected'][$rel]['h'] ?? null;
                if (!in_array($current, [$oldHash, $newHash], true)) {
                    // Upravený soubor (správce, hosting, volba verze PHP v cPanelu…) zůstává. Když ho nová verze oproti
                    // předchozí nemění, není co doplňovat: žádný .new ani upozornění.
                    if ($oldHash === null || $oldHash !== $newHash) {
                        // Pravidla AllStatu se do upraveného .htaccess doplní sama (přepíše se jen blok BEGIN/END AllStat)
                        // a po výměně se ověří, že AllStat odpovídá. Jinak zůstane nová verze vedle jako .new a karta.
                        $newContent = (string) file_get_contents($stage . '/' . $rel);
                        $merged = $rel === '.htaccess' && $selfTestUrl !== null
                            ? allstat_update_htaccess_merge((string) file_get_contents($target), $newContent)
                            : null;
                        if ($merged !== null) {
                            file_put_contents($stage . '/' . $rel, $merged);
                            $mergedProtected[$rel] = $newContent;
                            $write[$rel] = $rel;
                        } else {
                            $write[$rel . '.new'] = $rel;
                            $keptProtected[] = $rel;
                        }
                    }
                    continue;
                }
            }
            $write[$rel] = $rel;
        }
        $delete = [];
        foreach ($oldManifest['files'] as $rel => $meta) {
            $path = $root . '/' . $rel;
            if (isset($newManifest['files'][$rel]) || in_array($rel, $packageFiles, true) || !is_file($path)) {
                continue;
            }
            if (hash_file('sha256', $path) === ($meta['h'] ?? '')) {
                $delete[] = $rel;
            } else {
                $warnings[] = 'Soubor ' . $rel . ' nová verze už nepoužívá, ale má vaše úpravy, proto zůstal.';
            }
        }
        foreach (allstat_update_modified_files() as $rel) {
            if (isset($write[$rel])) {
                $warnings[] = 'Soubor ' . $rel . ' měl ruční úpravy a byl přepsán (původní je v záloze).';
            }
        }

        // 4) Záloha všeho, co se přepíše nebo smaže.
        $backupName = 'backup-' . $installed . '-' . date('Ymd-His');
        $backupDir = $work . '/' . $backupName;
        $backedUp = [];
        $added = [];
        foreach (array_merge(array_keys($write), $delete) as $rel) {
            $path = $root . '/' . $rel;
            if (is_file($path)) {
                if (!is_dir(dirname($backupDir . '/files/' . $rel))) {
                    mkdir(dirname($backupDir . '/files/' . $rel), 0755, true);
                }
                if (!copy($path, $backupDir . '/files/' . $rel)) {
                    throw new RuntimeException('Zálohu souboru ' . $rel . ' se nepodařilo vytvořit, nic se nezměnilo.');
                }
                $backedUp[] = $rel;
            } else {
                $added[] = $rel;
            }
        }

        // 5) Výměna souborů (rename ze stage je rychlý a po souborech atomický). Při chybě vrátit ze zálohy.
        $done = [];
        try {
            foreach ($write as $targetRel => $sourceRel) {
                $target = $root . '/' . $targetRel;
                if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0755, true)) {
                    throw new RuntimeException('Nelze vytvořit složku pro ' . $targetRel);
                }
                if (!@rename($stage . '/' . $sourceRel, $target) && !@copy($stage . '/' . $sourceRel, $target)) {
                    throw new RuntimeException('Soubor ' . $targetRel . ' se nepodařilo zapsat.');
                }
                $done[] = $targetRel;
            }
            foreach ($delete as $rel) {
                @unlink($root . '/' . $rel);
            }
        } catch (Throwable $swapError) {
            foreach ($done as $rel) {
                in_array($rel, $backedUp, true) ? @copy($backupDir . '/files/' . $rel, $root . '/' . $rel) : @unlink($root . '/' . $rel);
            }
            throw new RuntimeException($swapError->getMessage() . ' Změněné soubory byly vráceny ze zálohy.');
        }

        allstat_update_opcache_invalidate($root, array_keys($write));

        // Sloučený .htaccess: když AllStat po výměně neodpovídá (nebo to nejde ověřit), vrátit původní soubor ze zálohy
        // a novou verzi nechat vedle jako .new (karta na stránce Aktualizace).
        $mergedOk = [];
        $mergeFailed = [];
        foreach ($mergedProtected as $rel => $newContent) {
            if (allstat_update_selftest((string) $selfTestUrl) === true) {
                $mergedOk[] = $rel;
                continue;
            }
            @copy($backupDir . '/files/' . $rel, $root . '/' . $rel);
            file_put_contents($root . '/' . $rel . '.new', $newContent);
            $keptProtected[] = $rel;
            $mergeFailed[] = $rel;
        }

        // Upozornit jen na upravené soubory, kterým z nové verze opravdu něco chybí (zbytečný .new se tím rovnou smaže).
        if ($keptProtected) {
            foreach (array_intersect($keptProtected, array_column(allstat_update_protected_pending(), 'file')) as $rel) {
                $warnings[] = (in_array($rel, $mergeFailed, true)
                    ? 'Pravidla do souboru ' . $rel . ' nešlo doplnit automaticky (AllStat pak neodpovídal nebo to nešlo ověřit), soubor zůstal beze změny. AllStat funguje, '
                    : 'Soubor ' . $rel . ' má na hostingu vlastní úpravy, proto ho aktualizace nepřepsala. AllStat funguje, ')
                    . 'jen nová verze v tomto souboru mění pravidla: co doplnit, ukazuje karta „Soubor ' . $rel . '“ na stránce Aktualizace.';
            }
        }

        // 6) Záznam pro návrat a historii, úklid.
        $record = [
            'from' => $installed,
            'to' => $latest['version'],
            'at' => date('Y-m-d H:i:s'),
            'by' => $actorId,
            'backup' => $backupName,
            'backed_up' => $backedUp,
            'added' => $added,
            'warnings' => $warnings,
            'reinstall' => $reinstall,
        ];
        file_put_contents($backupDir . '/update.json', json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $history = allstat_update_history($pdo);
        array_unshift($history, array_diff_key($record, ['backed_up' => 1, 'added' => 1]));
        allstat_set_setting($pdo, 'update.history', json_encode(array_slice($history, 0, 20), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        allstat_audit($pdo, $actorId, null, $reinstall ? 'app_reinstalled' : 'app_updated', $installed . ' → ' . $latest['version']);
        allstat_update_rrmdir($stage);
        @unlink($zipPath);
        $backups = glob($work . '/backup-*', GLOB_ONLYDIR) ?: [];
        rsort($backups);
        foreach (array_slice($backups, ALLSTAT_UPDATE_KEEP_BACKUPS) as $old) {
            allstat_update_rrmdir($old);
        }

        $message = $reinstall
            ? 'AllStat je obnovený z vydání ' . $latest['version'] . ' na GitHubu (předtím ' . $installed . ').'
            : 'AllStat je aktualizovaný na verzi ' . $latest['version'] . '.';
        if ($mergedOk) {
            $message .= ' Pravidla AllStatu v souboru .htaccess jsou doplněná, vlastní úpravy zůstaly beze změny.';
        }

        return ['ok' => true, 'message' => $message, 'warnings' => $warnings];
    } catch (Throwable $exception) {
        allstat_update_rrmdir($stage);

        return ['ok' => false, 'message' => $exception->getMessage(), 'warnings' => $warnings];
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('allstat_update')");
    }
}

/** Vrátí soubory z poslední zálohy (databáze zůstává, migrace jen přidávají, starší verze s ní funguje). */
function allstat_update_rollback(PDO $pdo, int $actorId): array
{
    $work = allstat_update_dir();
    $backups = glob($work . '/backup-*', GLOB_ONLYDIR) ?: [];
    rsort($backups);
    $record = $backups ? json_decode((string) @file_get_contents($backups[0] . '/update.json'), true) : null;
    if (!is_array($record) || allstat_installed_version() !== ($record['to'] ?? null)) {
        return ['ok' => false, 'message' => 'Není k dispozici záloha, ke které by se dalo vrátit.'];
    }
    $root = allstat_update_root();
    foreach ($record['added'] ?? [] as $rel) {
        @unlink($root . '/' . $rel);
    }
    foreach ($record['backed_up'] ?? [] as $rel) {
        if (!@copy($backups[0] . '/files/' . $rel, $root . '/' . $rel)) {
            return ['ok' => false, 'message' => 'Soubor ' . $rel . ' se nepodařilo vrátit. Záloha je ve složce _update/' . basename($backups[0]) . '.'];
        }
    }
    allstat_update_opcache_invalidate($root, array_merge($record['added'] ?? [], $record['backed_up'] ?? []));
    allstat_update_rrmdir($backups[0]);
    $history = allstat_update_history($pdo);
    array_unshift($history, ['from' => $record['to'], 'to' => $record['from'], 'at' => date('Y-m-d H:i:s'), 'by' => $actorId, 'rollback' => true]);
    allstat_set_setting($pdo, 'update.history', json_encode(array_slice($history, 0, 20), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    allstat_audit($pdo, $actorId, null, 'app_rollback', $record['to'] . ' → ' . $record['from']);

    return ['ok' => true, 'message' => 'Vráceno na verzi ' . $record['from'] . '.'];
}
