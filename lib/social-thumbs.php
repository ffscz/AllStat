<?php

/**
 * Náhledy příspěvků Facebooku a Instagramu. Meta dává obrázek jen jako odkaz na svůj CDN (fbcdn.net,
 * cdninstagram.com), který po pár dnech přestane platit. AllStat si proto obrázek při synchronizaci stáhne,
 * zmenší na 160 px a uloží jako JPEG do media/thumbs. Ukládá se jen znovu zakódovaný obrázek z GD, nikdy
 * původní soubor, a složka pouští ven jen .jpg. Bez GD (imagecreatefromstring) se náhledy prostě nedělají.
 */

// Uložený náhled: 160 px na delší straně, v tabulce se ukazuje 56 px (160 stačí i na retina displej), soubor
// má zhruba 5 až 8 kB.
const ALLSTAT_THUMB_SIZE = 160;
// Stahuje se obrázek, který dá Meta (menší verzi API nenabízí): FB full_picture obvykle do 720 px a desítky kB,
// IG fotka 1080 až 1440 px a 100 kB až 1 MB. Uložený náhled má jen pár kB. Nad 2 MB se náhled nedělá vůbec.
const ALLSTAT_THUMB_MAX_BYTES = 2_000_000;
const ALLSTAT_THUMB_PER_SYNC = 60;
// Největší obrázek, který GD dekóduje: 5 Mpx (IG 1440 × 1800 = 2,6 Mpx, FB fotka 2048 px = 4,2 Mpx) ≈ 20 MB
// paměti. Větší se odmítne podle hlavičky dřív, než by GD alokovalo paměť (přetečení memory_limit je fatální
// chyba bez výjimky).
const ALLSTAT_THUMB_MAX_PIXELS = 5_000_000;
// Kolikrát se zkusí příspěvek, který Meta nevrátí (smazaný, nepodporovaný typ) nebo jehož obrázek nejde stáhnout,
// než dostane trvalou značku '-'. Mezistav se ukládá jako '?1', '?2'.
const ALLSTAT_THUMB_ATTEMPTS = 3;
// Časový rozpočet na doplňování náhledů v jednom běhu (s) a timeout jednoho stažení. Zbytek dorovná další běh,
// ať pomalé CDN neprodlouží synchronizaci volanou přes HTTP za max_execution_time.
const ALLSTAT_THUMB_TIME_BUDGET = 20;
const ALLSTAT_THUMB_TIMEOUT = 8;

function allstat_thumbs_dir(): string
{
    // Testy (tests/meta-batch.php) si přes ALLSTAT_THUMBS_DIR podstrčí dočasnou složku, ať nesahají na skutečnou.
    return defined('ALLSTAT_THUMBS_DIR') ? (string) constant('ALLSTAT_THUMBS_DIR') : dirname(__DIR__) . '/media/thumbs';
}

/** Složka náhledů; při prvním použití ji založí s pravidly, která pustí ven jen obrázky .jpg. */
function allstat_thumbs_prepare_dir(): ?string
{
    $dir = allstat_thumbs_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return null;
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Options -Indexes\n<FilesMatch \"\\.(?!jpg$)[^.]*$\">\n    Require all denied\n</FilesMatch>\n<FilesMatch \"^[^.]*$\">\n    Require all denied\n</FilesMatch>\n");
        @file_put_contents($dir . '/index.html', '');
    }

    return is_writable($dir) ? $dir : null;
}

function allstat_thumbs_supported(): bool
{
    return function_exists('imagecreatefromstring') && function_exists('imagejpeg');
}

/** Název souboru náhledu: číslo napojení + otisk id příspěvku (id obsahuje podtržítka a u IG je dlouhé). */
function allstat_thumb_filename(int $connectionId, string $postId): string
{
    return 'c' . $connectionId . '_' . sha1($postId) . '.jpg';
}

/** Adresa náhledu relativně ke kořeni aplikace (dashboard i sdílení běží v kořeni), '' = náhled není. */
function allstat_thumb_url(string $file): string
{
    return preg_match('/^c\d+_[0-9a-f]{40}\.jpg$/', $file) === 1 && is_file(allstat_thumbs_dir() . '/' . $file)
        ? 'media/thumbs/' . $file
        : '';
}

/** Stahovat smíme jen z obrázkového CDN Mety přes HTTPS (odkaz přišel z API, ale nevěříme mu naslepo). */
function allstat_thumb_source_allowed(string $url): bool
{
    if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
        return false;
    }
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));

    return (bool) preg_match('/(^|\.)(fbcdn\.net|cdninstagram\.com|fbsbx\.com)$/', $host);
}

/**
 * Stáhne obrázek, zmenší ho do čtverce ALLSTAT_THUMB_SIZE (poměr stran zůstane) a uloží jako JPEG.
 * Vrací název souboru; false = trvale nejde (cizí host, moc velký, nejde o obrázek), nezkoušet znovu;
 * null = přechodná chyba (síť, zápis), zkusit příště.
 */
function allstat_thumb_store(string $sourceUrl, int $connectionId, string $postId): string|false|null
{
    if (!allstat_thumbs_supported()) {
        return null;
    }
    if (!allstat_thumb_source_allowed($sourceUrl)) {
        return false;
    }
    $dir = allstat_thumbs_prepare_dir();
    if ($dir === null) {
        return null;
    }
    try {
        $resp = allstat_http_request('GET', $sourceUrl, ['Accept' => 'image/*'], null, ALLSTAT_THUMB_TIMEOUT, false, ALLSTAT_THUMB_MAX_BYTES);
    } catch (Throwable $e) {
        // Odpověď větší než limit hlásí allstat_http_request výjimkou → trvale; ostatní (síť) příště.
        return $e->getMessage() === ALLSTAT_HTTP_TOO_LARGE ? false : null;
    }
    if (($resp['status'] ?? 0) !== 200 || !is_string($resp['body'] ?? null) || $resp['body'] === '') {
        return null;
    }
    $size = @getimagesizefromstring($resp['body']);
    if (!is_array($size) || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > ALLSTAT_THUMB_MAX_PIXELS) {
        return false;
    }
    $src = @imagecreatefromstring($resp['body']);
    if ($src === false) {
        return false;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1.0, ALLSTAT_THUMB_SIZE / max(1, $w), ALLSTAT_THUMB_SIZE / max(1, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    // Průhledné PNG by v JPEG zčernalo, proto bílé pozadí.
    imagefill($dst, 0, 0, (int) imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $file = allstat_thumb_filename($connectionId, $postId);
    $tmp = $dir . '/' . $file . '.tmp';
    $ok = imagejpeg($dst, $tmp, 80) && @rename($tmp, $dir . '/' . $file);
    imagedestroy($src);
    imagedestroy($dst);
    if (!$ok) {
        @unlink($tmp);
        return null;
    }

    return $file;
}

/**
 * Doplní náhledy příspěvkům napojení, které ho ještě nemají (nejnovější první, nejvýš ALLSTAT_THUMB_PER_SYNC
 * za běh, takže starší příspěvky dorovnají další synchronizace). Odkaz na obrázek se bere čerstvý z API dávkou
 * po id: FB `full_picture`, IG `thumbnail_url` u videí a reelů, jinak `media_url`. Příspěvek bez obrázku
 * (text, odkaz bez náhledu, moc velký obrázek) dostane značku '-', ať se na něj API neptá pořád dokola.
 * Příspěvek, který Meta nevrátí nebo jehož obrázek se nepodaří stáhnout, se zkusí ALLSTAT_THUMB_ATTEMPTS-krát.
 * Na náhledy má jeden běh nejvýš ALLSTAT_THUMB_TIME_BUDGET sekund. Stories se tu vynechávají (po 24 hodinách
 * už obrázek nejde stáhnout).
 * Vrací počet uložených náhledů.
 */
function allstat_thumbs_fill(PDO $pdo, string $base, string $token, int $connectionId, string $object): int
{
    if (!allstat_thumbs_supported() || allstat_thumbs_prepare_dir() === null) {
        return 0;
    }
    $stmt = $pdo->prepare("SELECT post_id, thumb_file FROM social_posts WHERE connection_id = ? AND post_type <> 'story'
        AND (thumb_file = '' OR thumb_file LIKE '?%')
        ORDER BY COALESCE(published_at, metric_date) DESC LIMIT " . ALLSTAT_THUMB_PER_SYNC);
    $stmt->execute([$connectionId]);
    $state = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'thumb_file', 'post_id');
    $ids = array_map('strval', array_keys($state));
    if (!$ids) {
        return 0;
    }
    $fields = $object === 'ig' ? 'media_type,media_url,thumbnail_url' : 'full_picture';
    $objects = allstat_meta_batch_get($base, $token, $ids, $fields);
    $update = $pdo->prepare('UPDATE social_posts SET thumb_file = ? WHERE connection_id = ? AND post_id = ?');
    $saved = 0;
    $settled = 0;  // příspěvky, u kterých běh skončil jasným výsledkem (náhled nebo trvalé '-')
    $failed = [];  // přechodné neúspěchy tohoto běhu
    $deadline = microtime(true) + ALLSTAT_THUMB_TIME_BUDGET;
    foreach ($ids as $id) {
        if (microtime(true) > $deadline) {
            break; // zbytek příště
        }
        if (!isset($objects[$id])) {
            $failed[] = $id; // Meta příspěvek nevrátila (smazaný, nepodporovaný typ, chyba) → pokus
            continue;
        }
        $o = $objects[$id];
        $src = $object === 'ig'
            ? (string) (($o['thumbnail_url'] ?? '') !== '' ? $o['thumbnail_url'] : ($o['media_url'] ?? ''))
            : (string) ($o['full_picture'] ?? '');
        if ($src === '') {
            $update->execute(['-', $connectionId, $id]);
            $settled++;
            continue;
        }
        $file = allstat_thumb_store($src, $connectionId, $id);
        if (is_string($file)) {
            $update->execute([$file, $connectionId, $id]);
            $saved++;
            $settled++;
        } elseif ($file === false) {
            $update->execute(['-', $connectionId, $id]); // trvale nejde, neptat se znovu
            $settled++;
        } else {
            $failed[] = $id;
        }
    }
    // Pokus se započítá, jen když v tomtéž běhu u jiných příspěvků vše fungovalo. Když selže všechno (vyčerpaný
    // limit Mety, výpadek sítě nebo CDN), chyba není v příspěvku a pokusy se nepálí.
    if ($settled > 0) {
        foreach ($failed as $id) {
            $n = (int) ltrim((string) ($state[$id] ?? ''), '?') + 1;
            $update->execute([$n >= ALLSTAT_THUMB_ATTEMPTS ? '-' : '?' . $n, $connectionId, $id]);
        }
    }

    return $saved;
}

/** Smaže náhledy jednoho napojení (při jeho odstranění). */
function allstat_thumbs_delete_connection(int $connectionId): void
{
    foreach (glob(allstat_thumbs_dir() . '/c' . $connectionId . '_*.jpg') ?: [] as $path) {
        @unlink($path);
    }
}

/** Obrázek náhledu do tabulky příspěvků (prázdný řetězec, když náhled není). */
function allstat_thumb_img(string $url): string
{
    return $url === '' ? '' : '<img class="post-thumb" src="' . h($url) . '" alt="" loading="lazy" decoding="async" width="56" height="56">';
}
