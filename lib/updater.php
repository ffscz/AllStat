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
function allstat_update_notice(PDO $pdo, array $user): ?array
{
    if (($user['role'] ?? '') !== 'admin' || allstat_installed_version() === null) {
        return null;
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
 */
function allstat_update_install(PDO $pdo, int $actorId): array
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
        if (!$status['available'] || $latest === null) {
            throw new RuntimeException('Není k dispozici novější verze.');
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
        foreach ($packageFiles as $rel) {
            if (in_array($rel, ALLSTAT_UPDATE_NEVER_TOUCH, true) || str_starts_with($rel, '_update/')) {
                continue;
            }
            $target = $root . '/' . $rel;
            if (isset($newManifest['protected'][$rel]) && is_file($target)) {
                $current = hash_file('sha256', $target);
                $known = [$oldManifest['protected'][$rel]['h'] ?? null, $newManifest['protected'][$rel]['h'] ?? null];
                if (!in_array($current, $known, true)) {
                    $write[$rel . '.new'] = $rel; // upravený soubor necháme, novou verzi vedle
                    $warnings[] = 'Soubor ' . $rel . ' má vaše úpravy, nepřepsal se. Nová verze je vedle jako ' . $rel . '.new, porovnejte je.';
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
        ];
        file_put_contents($backupDir . '/update.json', json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $history = allstat_update_history($pdo);
        array_unshift($history, array_diff_key($record, ['backed_up' => 1, 'added' => 1]));
        allstat_set_setting($pdo, 'update.history', json_encode(array_slice($history, 0, 20), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        allstat_audit($pdo, $actorId, null, 'app_updated', $installed . ' → ' . $latest['version']);
        allstat_update_rrmdir($stage);
        @unlink($zipPath);
        $backups = glob($work . '/backup-*', GLOB_ONLYDIR) ?: [];
        rsort($backups);
        foreach (array_slice($backups, ALLSTAT_UPDATE_KEEP_BACKUPS) as $old) {
            allstat_update_rrmdir($old);
        }

        return ['ok' => true, 'message' => 'AllStat je aktualizovaný na verzi ' . $latest['version'] . '.', 'warnings' => $warnings];
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
    allstat_update_rrmdir($backups[0]);
    $history = allstat_update_history($pdo);
    array_unshift($history, ['from' => $record['to'], 'to' => $record['from'], 'at' => date('Y-m-d H:i:s'), 'by' => $actorId, 'rollback' => true]);
    allstat_set_setting($pdo, 'update.history', json_encode(array_slice($history, 0, 20), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    allstat_audit($pdo, $actorId, null, 'app_rollback', $record['to'] . ' → ' . $record['from']);

    return ['ok' => true, 'message' => 'Vráceno na verzi ' . $record['from'] . '.'];
}
