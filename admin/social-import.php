<?php

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_layout.php';

$pdo = allstat_admin_require_database($pdo, $config);
$user = allstat_require_user($pdo, $config);
// Správa je jen pro administrátora i pro čtení: stránka ukazuje všechny weby, běžný uživatel smí jen přehled a reporty svých webů.
allstat_require_admin($user);

/**
 * Map a Meta Business Suite "Typ příspěvku" label to our post_format.
 * Export 2026: Facebook píše jen „Fotky" / „Videa" (reels jsou taky „Videa"), Instagram „IG image" /
 * „IG reel" / „IG carousel" (mapujeme na stejné hodnoty, jaké pro IG ukládá API). Jednotná čísla
 * (Fotka, Více fotek, Reels, Odkaz, Text…) zůstávají kvůli starším exportům.
 * Returns '' when we don't recognise it (then we keep the existing format).
 */
function allstat_csv_post_format(string $label): string
{
    $l = mb_strtolower(trim($label));
    if (str_starts_with($l, 'ig ')) {
        return match (trim(substr($l, 3))) {
            'reel', 'reels' => 'reel',
            'carousel', 'carousel album', 'carousel_album' => 'carousel_album',
            'image', 'photo' => 'image',
            'video' => 'video',
            default => '',
        };
    }
    return match (true) {
        $l === 'reels' || $l === 'reel' => 'reel',
        str_contains($l, 'více fotek') || str_contains($l, 'vice fotek') || $l === 'album' => 'album',
        str_contains($l, 'fotk') || $l === 'photo' || $l === 'photos' => 'photo',
        str_contains($l, 'odkaz') || $l === 'link' || $l === 'links' => 'link',
        str_contains($l, 'příběh') || str_contains($l, 'pribeh') || $l === 'story' => 'story',
        str_contains($l, 'vide') || str_contains($l, 'živé') || str_contains($l, 'zive') || $l === 'live' => 'video',
        $l === 'text' || $l === 'status' => 'status',
        default => '',
    };
}

/**
 * Business Suite ukládá export obsahu v UTF-8 s BOM, exporty grafů a „Okruh uživatelů" v UTF-16 s BOM.
 * Vrací text v UTF-8 bez BOM.
 */
function allstat_csv_to_utf8(string $raw): string
{
    $bom = substr($raw, 0, 2);
    if ($bom === "\xFF\xFE") {
        return (string) mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
    }
    if ($bom === "\xFE\xFF") {
        return (string) mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
    }
    return str_starts_with($raw, "\xEF\xBB\xBF") ? substr($raw, 3) : $raw;
}

/**
 * Co za export z Business Suite to je: 'content' = obsah po příspěvcích, 'audience' = Okruh uživatelů
 * (demografie), 'chart' = export jednoho grafu z přehledu (Top formáty obsahu, Zobrazení…), '' = nevím.
 * Slouží jen k srozumitelné chybě, když někdo nahraje jiný soubor, než slot čeká.
 */
function allstat_csv_export_kind(string $utf8): string
{
    $head = mb_strtolower(mb_substr($utf8, 0, 3000));
    $firstLine = strtok($head, "\r\n") ?: '';
    if (str_contains($firstLine, 'id příspěvku') || str_contains($firstLine, 'post id')) {
        return 'content';
    }
    if (preg_match('/věk a pohlaví|nejčastější (města|země)|age and gender|top (cities|countries)/u', $head)) {
        return 'audience';
    }
    return str_starts_with(ltrim($head), 'sep=') ? 'chart' : '';
}

/**
 * Parse a Meta Business Suite per-post content export (Přehledy → Obsah → Exportovat data).
 * Facebook: Úroveň obsahu „Příspěvek" + Zobrazení dat „Dlouhodobě", Instagram: „Příspěvek".
 * Columns are matched by HEADER NAME: order shifts between exports and FB/IG name them differently
 * (FB „Zhlédnutí / Reakce / Sdílení", IG „Zobrazení / To se mi líbí / Sdílené"). Values are lifetime.
 * Returns ['ok'=>true, 'rows'=>[postIdSuffix => fields], 'network', 'names', 'has'=>[field=>bool]],
 * or ['ok'=>false, 'message'=>…] that says which export setting to change.
 */
function allstat_parse_fb_content_csv(string $raw): array
{
    $text = allstat_csv_to_utf8($raw);
    $kind = allstat_csv_export_kind($text);
    if ($kind === 'audience') {
        return ['ok' => false, 'message' => 'Tohle je export „Okruh uživatelů" (demografie). Nahraj ho do části 2. Publikum a demografie.'];
    }
    if ($kind === 'chart') {
        return ['ok' => false, 'message' => 'Tohle je export jednoho grafu z přehledu (třeba „Top formáty obsahu" nebo „Zobrazení"), ne export po příspěvcích. V Business Suite otevři Přehledy → Obsah a klikni vpravo nahoře na Exportovat data.'];
    }

    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $text);
    rewind($fh);
    $header = fgetcsv($fh, null, ',', '"', '');
    if (!$header || count($header) < 2) {
        fclose($fh);
        return ['ok' => false, 'message' => 'Soubor je prázdný nebo to není CSV z Meta Business Suite.'];
    }
    // Export za delší období slepí dvě sady sloupců: starší příspěvky mají popisné sloupce anglicky
    // (Post ID, Page name, Post type…), novější česky na konci řádku (ověřeno 29. 9. 2026 na exportu
    // 1. 10. 2025 až 29. 9. 2026). Pro každé pole proto držíme VŠECHNY odpovídající sloupce a v řádku
    // bereme první neprázdnou hodnotu.
    $idx = [];
    foreach ($header as $i => $name) {
        $idx[trim((string) $name)][] = $i;
    }
    $col = static function (array $names) use ($idx): array {
        $found = [];
        foreach ($names as $n) {
            foreach ($idx[$n] ?? [] as $i) { $found[] = $i; }
        }
        return $found;
    };
    $cell = static function (array $row, array $cands): string {
        foreach ($cands as $i) {
            $v = trim((string) ($row[$i] ?? ''));
            if ($v !== '') { return $v; }
        }
        return '';
    };
    $cPostId = $col(['ID příspěvku', 'Post ID']);
    if (!$cPostId) {
        fclose($fh);
        return ['ok' => false, 'message' => 'V CSV chybí sloupec „ID příspěvku". V okně Exportovat metrická data zvol Úroveň obsahu: Příspěvek (ne Video ani Stránka).'];
    }
    $cols = [
        'views'       => $col(['Zhlédnutí', 'Zobrazení', 'Views']),
        'reach'       => $col(['Dosah', 'Reach']),
        'reactions'   => $col(['Reakce', 'To se mi líbí', 'Reactions', 'Likes']),
        'comments'    => $col(['Komentáře', 'Comments']),
        'shares'      => $col(['Sdílení', 'Sdílené', 'Shares']),
        'saved'       => $col(['Uložení', 'Saves']),
        'clicks'      => $col(['Celkem kliknutí', 'Total clicks']),
        'link_clicks' => $col(['Kliknutí na odkaz', 'Link clicks']),
        'views_paid'  => $col(['Zobrazení z Propagované příspěvky', 'Paid views', 'Paid impressions']),
        'reach_paid'  => $col(['Dosah z Propagované příspěvky', 'Paid reach']),
        'watch_sec'   => $col(['Zhlédnutí s', 'Seconds viewed']),
    ];
    $cType = $col(['Typ příspěvku', 'Post type']);
    $cDate = $col(['Datum', 'Date']);
    $cName = $col(['Název stránky', 'Uživatelské jméno účtu', 'Page name', 'Account username']);
    $network = $col(['ID účtu', 'Uživatelské jméno účtu', 'Account ID', 'Account username']) ? 'instagram'
        : ($col(['ID stránky', 'Název stránky', 'Page ID', 'Page name']) ? 'facebook' : '');

    $num = static function (?string $v): int {
        if ($v === null) { return 0; }
        $v = trim($v);
        if ($v === '') { return 0; }
        // Business Suite may use thousands separators / decimals; keep the integer part.
        $v = str_replace([' ', "\xC2\xA0", ','], ['', '', '.'], $v);
        return (int) round((float) $v);
    };

    $rows = [];
    $names = [];
    $daily = false;
    while (($r = fgetcsv($fh, null, ',', '"', '')) !== false) {
        $rawId = $cell($r, $cPostId);
        if ($rawId === '' || !ctype_digit(str_replace('_', '', $rawId))) { continue; }
        // Sloupec „Datum" je u exportu Dlouhodobě vždy „Dlouhodobě", u Denně datum konkrétního dne.
        $when = mb_strtolower($cell($r, $cDate));
        if ($when !== '' && $when !== 'dlouhodobě' && $when !== 'lifetime') { $daily = true; }
        // CSV "ID příspěvku" is the numeric post id only; take the part after any "_" to be safe.
        $suffix = str_contains($rawId, '_') ? substr($rawId, strrpos($rawId, '_') + 1) : $rawId;
        if (isset($rows[$suffix])) { $daily = true; }
        $fields = ['format' => allstat_csv_post_format($cell($r, $cType))];
        foreach ($cols as $key => $c) {
            $fields[$key] = $num($cell($r, $c));
        }
        $rows[$suffix] = $fields;
        $name = $cell($r, $cName);
        if ($name !== '') { $names[$name] = true; }
    }
    fclose($fh);

    if ($daily) {
        return ['ok' => false, 'message' => 'Export je rozdělený po dnech (Zobrazení dat: Denně), u příspěvku by se tak uložil jen jeden den. Vygeneruj ho znovu s volbou Zobrazení dat: Dlouhodobě.'];
    }
    if (!$rows) {
        return ['ok' => false, 'message' => 'V CSV nebyl žádný příspěvek. Zkontroluj v exportu období a vybranou stránku nebo účet.'];
    }
    return [
        'ok' => true,
        'rows' => $rows,
        'network' => $network,
        'names' => array_keys($names),
        'has' => array_map(static fn (array $c): bool => $c !== [], $cols),
    ];
}

/**
 * Parse a Meta Business Suite "Okruh uživatelů" export (Přehledy → Okruh uživatelů → Exportovat → CSV).
 * UTF-16, sekce „Věk a pohlaví" (věk × pohlaví, %), „Nejčastější města" (%), „Nejčastější země" (%).
 * Vrací ['ok'=>bool, 'age'=>[label=>pct], 'gender'=>[…], 'city'=>[…], 'country'=>[…]], hodnoty jsou PROCENTA.
 */
function allstat_parse_fb_audience_csv(string $raw): array
{
    $text = allstat_csv_to_utf8($raw);
    $kind = allstat_csv_export_kind($text);
    if ($kind === 'content') {
        return ['ok' => false, 'message' => 'Tohle je export obsahu po příspěvcích. Nahraj ho do části 1. Obsah příspěvků.'];
    }
    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
    $cells = static fn (string $l): array => str_getcsv($l, ',', '"', '');
    $pct = static function ($v): float {
        $v = str_replace([' ', "\xC2\xA0"], '', trim((string) $v));
        $v = str_replace(',', '.', $v);
        return is_numeric($v) ? (float) $v : 0.0;
    };
    $out = ['age' => [], 'gender' => [], 'city' => [], 'country' => []];
    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        $line = trim($lines[$i]);
        if ($line === '' || stripos($line, 'sep=') === 0) { continue; }
        $c = $cells($line);
        $head = trim((string) ($c[0] ?? ''));
        $isHeader = count(array_filter($c, static fn ($x) => trim((string) $x) !== '')) === 1;
        if (!$isHeader) { continue; }

        if (mb_stripos($head, 'věk') !== false || mb_stripos($head, 'pohlav') !== false) {
            // další řádek = hlavička pohlaví ("","Ženy","Muži"); pak řádky věk, ženy%, muži%
            $gh = $cells(trim($lines[$i + 1] ?? ''));
            $g1Label = trim((string) ($gh[1] ?? 'Ženy')) ?: 'Ženy';
            $g2Label = trim((string) ($gh[2] ?? 'Muži')) ?: 'Muži';
            $i++;
            while ($i + 1 < $n) {
                $row = $cells(trim($lines[$i + 1] ?? ''));
                $age = trim((string) ($row[0] ?? ''));
                if ($age === '' || !preg_match('/^\d/', $age)) { break; }
                $v1 = $pct($row[1] ?? 0); $v2 = $pct($row[2] ?? 0);
                $out['age'][$age] = ($out['age'][$age] ?? 0) + $v1 + $v2;
                $out['gender'][$g1Label] = ($out['gender'][$g1Label] ?? 0) + $v1;
                $out['gender'][$g2Label] = ($out['gender'][$g2Label] ?? 0) + $v2;
                $i++;
            }
        } elseif (mb_stripos($head, 'měst') !== false) {
            $names = $cells(trim($lines[$i + 1] ?? '')); $vals = $cells(trim($lines[$i + 2] ?? ''));
            foreach ($names as $k => $nm) { $nm = trim((string) $nm); if ($nm !== '') { $out['city'][$nm] = $pct($vals[$k] ?? 0); } }
            $i += 2;
        } elseif (mb_stripos($head, 'zem') !== false) {
            $names = $cells(trim($lines[$i + 1] ?? '')); $vals = $cells(trim($lines[$i + 2] ?? ''));
            foreach ($names as $k => $nm) { $nm = trim((string) $nm); if ($nm !== '') { $out['country'][$nm] = $pct($vals[$k] ?? 0); } }
            $i += 2;
        }
    }
    if (!($out['age'] || $out['gender'] || $out['city'] || $out['country'])) {
        return ['ok' => false, 'message' => $kind === 'chart'
            ? 'Tohle je export jiného grafu (třeba „Top formáty obsahu"), ne demografie. V Business Suite otevři Přehledy → Okruh uživatelů a klikni na Exportovat → Exportovat jako CSV.'
            : 'V CSV jsem nenašel sekce „Věk a pohlaví", „Nejčastější města" ani „Nejčastější země". V Business Suite otevři Přehledy → Okruh uživatelů a klikni na Exportovat → Exportovat jako CSV.'];
    }
    // Součty věku (ženy + muži) jinak nesou float šum typu 8.600000000000001.
    foreach (['age', 'gender', 'city', 'country'] as $slot) {
        $out[$slot] = array_map(static fn (float $v): float => round($v, 2), $out[$slot]);
    }
    $out['ok'] = true;
    return $out;
}

// Social connections (FB pages + IG accounts) to import into.
$connections = allstat_fetch_all($pdo, "
    SELECT ds.id, ds.domain_id, ds.source_id, d.url AS domain_url, s.provider_key, ds.account_label
    FROM domain_sources ds
    JOIN data_sources s ON s.id = ds.source_id
    JOIN domains d ON d.id = ds.domain_id
    WHERE s.provider_key IN ('facebook_pages','instagram_business')
    ORDER BY s.provider_key, ds.id
");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    allstat_csrf_check();
    allstat_require_admin($user);

    $connectionId = (int) ($_POST['connection_id'] ?? 0);
    // When the upload came from the dashboard's inline box, return there instead of this admin page.
    $backTo = (($_POST['return_to'] ?? '') === 'dashboard' && $connectionId > 0)
        ? 'index.php?source_id=' . $connectionId
        : 'admin/social-import.php';
    $conn = null;
    foreach ($connections as $c) {
        if ((int) $c['id'] === $connectionId) { $conn = $c; break; }
    }
    if (!$conn) {
        allstat_flash('error', 'Vyber prosím platné napojení (Facebook stránku nebo Instagram účet).');
        allstat_redirect($config, $backTo);
    }

    $file = $_FILES['csv'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) <= 0) {
        allstat_flash('error', 'Nahraj prosím CSV soubor exportovaný z Meta Business Suite.');
        allstat_redirect($config, $backTo);
    }
    if (($file['size'] ?? 0) > 8 * 1024 * 1024) {
        allstat_flash('error', 'Soubor je příliš velký (max 8 MB).');
        allstat_redirect($config, $backTo);
    }

    // Publikum / demografie export (FB) — jiný parser + úložiště než obsahový CSV.
    if ((string) ($_POST['import_type'] ?? 'content') === 'audience') {
        if (($conn['provider_key'] ?? '') !== 'facebook_pages') {
            allstat_flash('error', 'Export „Okruh uživatelů" je jen pro Facebook stránku (Instagram demografii bere z API automaticky).');
            allstat_redirect($config, $backTo);
        }
        $aud = allstat_parse_fb_audience_csv((string) file_get_contents($file['tmp_name']));
        if (!($aud['ok'] ?? false)) {
            allstat_flash('error', $aud['message'] ?? 'CSV se nepodařilo zpracovat.');
            allstat_redirect($config, $backTo);
        }
        $today = date('Y-m-d');
        // Snímek → nahradit dnešní demografické řádky tohoto napojení a vložit nové (dimenzované, hodnoty v %).
        $del = $pdo->prepare("DELETE FROM provider_metrics_daily WHERE connection_id = ? AND metric_date = ? AND metric_key IN ('dem_age','dem_gender','dem_country','dem_city')");
        $del->execute([$connectionId, $today]);
        $ins = $pdo->prepare("INSERT INTO provider_metrics_daily (domain_id, source_id, connection_id, metric_date, metric_key, dimension, metric_value)
            VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE metric_value = VALUES(metric_value)");
        $stored = 0;
        foreach (['age' => 'dem_age', 'gender' => 'dem_gender', 'city' => 'dem_city', 'country' => 'dem_country'] as $slot => $key) {
            foreach (($aud[$slot] ?? []) as $dim => $val) {
                $ins->execute([(int) $conn['domain_id'], (int) $conn['source_id'], $connectionId, $today, $key, mb_substr((string) $dim, 0, 120), (float) $val]);
                $stored++;
            }
        }
        allstat_audit($pdo, (int) $user['id'], null, 'fb_audience_csv_imported', 'conn=' . $connectionId . ' rows=' . $stored);
        allstat_flash('ok', sprintf('Demografie z „Okruh uživatelů" importována: %d hodnot (věk, pohlaví, města, země). Najdeš ji na dashboardu Facebook stránky v sekci „Demografické údaje".', $stored));
        allstat_redirect($config, $backTo);
    }

    $parsed = allstat_parse_fb_content_csv((string) file_get_contents($file['tmp_name']));
    if (!$parsed['ok']) {
        allstat_flash('error', $parsed['message']);
        allstat_redirect($config, $backTo);
    }

    $isFb = ($conn['provider_key'] ?? '') === 'facebook_pages';
    $connLabel = (string) (($conn['account_label'] ?? '') !== '' ? $conn['account_label'] : $conn['domain_url']);
    $csvLabel = implode(', ', $parsed['names']);
    // Export z jiné sítě, než je vybrané napojení: nic by se nenapárovalo, tak rovnou řekni proč.
    if ($parsed['network'] !== '' && $parsed['network'] !== ($isFb ? 'facebook' : 'instagram')) {
        allstat_flash('error', sprintf(
            'Tenhle export je z %s%s, ale vybrané napojení je %s „%s". Vyber správné napojení, nebo v Business Suite exportuj záložku %s.',
            $parsed['network'] === 'instagram' ? 'Instagramu' : 'Facebooku',
            $csvLabel !== '' ? ' (' . $csvLabel . ')' : '',
            $isFb ? 'Facebook stránka' : 'Instagram účet',
            $connLabel,
            $isFb ? 'Facebook' : 'Instagram'
        ));
        allstat_redirect($config, $backTo);
    }

    // Pojistka na budoucí přejmenování sloupců v Business Suite: bez žádné základní metriky je soubor
    // k ničemu (odmítnout), u chybějících jednotlivých metrik to aspoň říct, ať se to netratí potichu.
    $coreLabels = ['views' => 'zobrazení', 'reach' => 'dosah', 'reactions' => 'reakce', 'comments' => 'komentáře', 'shares' => 'sdílení'];
    $missingCore = array_values(array_intersect_key($coreLabels, array_filter($parsed['has'], static fn (bool $h): bool => !$h)));
    if (count($missingCore) === count($coreLabels)) {
        allstat_flash('error', 'V exportu jsem nenašel žádnou známou metriku (zobrazení, dosah, reakce, komentáře, sdílení). Meta nejspíš změnila názvy sloupců, import je potřeba upravit. Soubor pošli správci AllStatu.');
        allstat_redirect($config, $backTo);
    }

    // Which CSV post ids does this connection already have (FB post_id is "pageid_postid", IG plain id)?
    $known = [];
    $idStmt = $pdo->prepare('SELECT post_id FROM social_posts WHERE connection_id = ?');
    $idStmt->execute([$connectionId]);
    foreach ($idStmt->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        $pid = (string) $pid;
        $known[str_contains($pid, '_') ? substr($pid, strrpos($pid, '_') + 1) : $pid] = true;
    }

    // Čísla v exportu jsou za celou dobu života příspěvku a časem jen rostou, import proto vždy nechá
    // vyšší hodnotu: nic nesníží a chybějící sloupec (0) nic nepřepíše. Facebook v exportu počítá reakce
    // a komentáře i z cizích sdílení (ověřeno 29. 9. 2026 proti API: sedí na reactions_viral/comments_viral),
    // AllStat drží v reactions/comments jen originál, proto u FB míří do *_viral. Typ obsahu z CSV jen
    // doplní neznámý (FB export nerozlišuje reels a videa, API ano), reel ale zpřesní obecné video.
    // Číselné parametry VŽDY přes CAST: PDO je posílá jako text a MariaDB (produkce) pak GREATEST(sloupec, '594')
    // porovná jako řetězce (GREATEST(1000, '594') = 594, GREATEST(99, '100') = 99). MySQL 8.4 na lokálu typuje
    // parametr podle kontextu, takže se to tam neprojeví.
    $reactCol = $isFb ? 'reactions_viral' : 'reactions';
    $commCol = $isFb ? 'comments_viral' : 'comments';
    $n = 'CAST(? AS SIGNED)';
    $upd = $pdo->prepare("
        UPDATE social_posts
           SET impressions    = GREATEST(impressions, {$n}),
               reach          = GREATEST(reach, {$n}),
               {$reactCol}    = GREATEST({$reactCol}, {$n}),
               {$commCol}     = GREATEST({$commCol}, {$n}),
               shares         = GREATEST(shares, {$n}),
               saved          = GREATEST(saved, {$n}),
               post_clicks    = GREATEST(post_clicks, {$n}),
               link_clicks    = GREATEST(link_clicks, {$n}),
               views_paid     = GREATEST(views_paid, {$n}),
               reach_paid     = GREATEST(reach_paid, {$n}),
               watch_time_sec = GREATEST(watch_time_sec, {$n}),
               post_format    = CASE WHEN ? = '' THEN post_format
                                     WHEN post_format IN ('', 'status') THEN ?
                                     WHEN ? = 'reel' AND post_format = 'video' THEN 'reel'
                                     ELSE post_format END,
               post_type      = IF(? = 'reel', 'reel', post_type),
               engagement     = GREATEST(reactions, {$n}) + GREATEST(comments, {$n}) + GREATEST(shares, {$n})
         WHERE connection_id = ?
           AND (post_id = ? OR SUBSTRING_INDEX(post_id, '_', -1) = ?)
    ");

    $matched = 0;
    $changed = 0;
    foreach ($parsed['rows'] as $suffix => $f) {
        if (!isset($known[(string) $suffix])) { continue; }
        $matched++;
        $upd->execute([
            $f['views'], $f['reach'], $f['reactions'], $f['comments'], $f['shares'], $f['saved'],
            $f['clicks'], $f['link_clicks'], $f['views_paid'], $f['reach_paid'], $f['watch_sec'],
            $f['format'], $f['format'], $f['format'], $f['format'],
            // Engagement ze stejných výrazů jako SET výše, ať nezávisí na pořadí vyhodnocení přiřazení
            // (MariaDB SIMULTANEOUS_ASSIGNMENT). U FB se reactions/comments (originál) importem nemění.
            $isFb ? 0 : $f['reactions'], $isFb ? 0 : $f['comments'], $f['shares'],
            $connectionId, (string) $suffix, (string) $suffix,
        ]);
        if ($upd->rowCount() > 0) { $changed++; }
    }
    $total = count($parsed['rows']);

    allstat_audit($pdo, (int) $user['id'], null, 'social_csv_imported', $conn['provider_key'] . ' conn=' . $connectionId . ' matched=' . $matched . '/' . $total . ' changed=' . $changed);
    if ($matched === 0) {
        allstat_flash('error', sprintf(
            'Žádný z %d příspěvků v exportu%s se nenapároval s napojením „%s". Zkontroluj, že exportuješ stejnou stránku nebo účet a že má napojení stažené příspěvky (u napojení „Stáhnout historii").',
            $total, $csvLabel !== '' ? ' (' . $csvLabel . ')' : '', $connLabel
        ));
        allstat_redirect($config, $backTo);
    }
    $posts = static fn (int $n): string => $n . ($n === 1 ? ' příspěvek' : ($n >= 2 && $n <= 4 ? ' příspěvky' : ' příspěvků'));
    $msg = sprintf('Import hotov: napárováno %d z %d příspěvků, ', $matched, $total)
        . ($changed > 0 ? sprintf('u %d se čísla doplnila nebo zvýšila.', $changed) : 'čísla v AllStatu už byla stejná nebo vyšší.');
    if ($matched < $total) {
        $msg .= count($parsed['names']) > 1
            ? ' Export obsahoval víc stránek nebo účtů (' . $csvLabel . '), napárovaly se jen příspěvky vybraného napojení.'
            : ' V AllStatu zatím chybí ' . $posts($total - $matched) . ' z exportu (u napojení spusť „Stáhnout historii" a import zopakuj).';
    }
    if ($missingCore) {
        $msg .= ' V exportu chyběly sloupce: ' . implode(', ', $missingCore) . ' (Meta je možná přejmenovala), ty se nedoplnily.';
    }
    if ($isFb && !$parsed['has']['views_paid'] && !$parsed['has']['reach_paid']) {
        $msg .= ' Export neobsahoval rozpad organické a placené: příště zaškrtni v Předvolbách metrik všech 5 možností.';
    }
    allstat_flash('ok', $msg);
    allstat_redirect($config, $backTo);
}

$preselect = filter_input(INPUT_GET, 'connection_id', FILTER_VALIDATE_INT) ?: (int) ($connections[0]['id'] ?? 0);
$providerLabel = static fn (string $key): string => $key === 'instagram_business' ? 'Instagram' : 'Facebook';
$bsContentUrl = 'https://business.facebook.com/latest/insights/content';
$bsAudienceUrl = 'https://business.facebook.com/latest/insights/people';
// Anonymizované snímky z Business Suite (29. 9. 2026, názvy stránek nahrazené zástupným textem, data rozmazaná).
$shot = static fn (string $file, int $w, int $h, string $alt, string $caption): string => sprintf(
    '<figure><img src="%s" width="%d" height="%d" alt="%s" loading="lazy"><figcaption>%s</figcaption></figure>',
    h(allstat_url($config, 'assets/img/help/' . $file)), $w, $h, h($alt), h($caption)
);

allstat_admin_header('Import z CSV', 'sources', $user, $config);
?>
<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2>Import z CSV (Meta Business Suite)</h2>
            <p>Část čísel Meta přes API nedává: dosah u některých facebookových příspěvků, rozpad organické a placené a demografii Facebooku. Doplníš je ze dvou exportů z Meta Business Suite níže. Export obsahu navíc obnoví čísla starších příspěvků, noční synchronizace totiž aktualizuje jen posledních 7 dní. Každý export zabere pár minut a stačí ho dělat jednou za měsíc.</p>
        </div>
    </div>
</div>

<div class="admin-card" id="obsah">
    <div class="admin-card-header">
        <div>
            <h2>1. Obsah příspěvků <span>(Facebook i Instagram)</span></h2>
            <p><strong>Co doplní:</strong> dosah tam, kde ho API nevrátí, rozpad organické a placené (Meta ho z API zrušila 15. 6. 2026) a aktuální čísla starších příspěvků (zobrazení, dosah, reakce, sdílení, uložení, doba sledování). U Instagramu dává všechna čísla i API, export je tam jen rychlejší náhrada tlačítka „Stáhnout historii". Čísla se párují podle ID příspěvku, příspěvek proto musí být v AllStatu už stažený.</p>
        </div>
    </div>
    <div class="admin-card-body">
        <ol class="setup-steps">
            <li><strong>V Meta Business Suite otevři Přehledy → Obsah</strong> (<a href="<?= h($bsContentUrl) ?>" target="_blank" rel="noopener">otevřít</a>) a vpravo nahoře klikni na <strong>Exportovat data</strong>. Otevře se okno Exportovat metrická data.</li>
            <li><strong>Facebook stránka:</strong> nech záložku Facebook a nastav:
                <ul class="export-settings">
                    <li>Stránky: <strong>jen jedna stránka</strong>, ta, kterou níže vybereš jako napojení</li>
                    <li>Období: třeba <strong>Posledních 90 dní</strong> (export obsahuje příspěvky zveřejněné v tomto období, čísla u nich jsou za celou dobu). Business Suite povolí nejvýš rok, starší příspěvky exportuj dalším souborem</li>
                    <li>Předvolby metrik: <strong>zaškrtni všech 5</strong>, jinak v exportu chybí rozpad organické a placené</li>
                    <li>Zobrazení dat: <strong>Dlouhodobě</strong> (ne Denně)</li>
                    <li>Úroveň obsahu: <strong>Příspěvek</strong> (ne Video ani Stránka)</li>
                    <li>Filtr: nech Datum vytvoření</li>
                </ul>
            </li>
            <li><strong>Instagram účet:</strong> přepni na záložku Instagram, v poli Účty vyber jeden účet, nastav Období a nech volbu <strong>Příspěvek</strong> (ne Příběhy). Jiné volby tu nejsou.</li>
            <li>Klikni na <strong>Generovat</strong>. Soubor se nestáhne hned, Meta ho desítky sekund připravuje. Až se objeví „Váš export byl dokončen", klikni na <strong>Stáhnout export</strong>. Hotové exporty najdeš i později pod šipkou vedle tlačítka Exportovat data.</li>
            <li>Stažený soubor (jmenuje se třeba <code>Jul-01-2026_Sep-28-2026_1812859779746514.csv</code>) nahraj tady a vyber stejnou stránku nebo účet:</li>
        </ol>
        <details class="help-shots">
            <summary>Ukázat obrázky z Business Suite</summary>
            <div class="help-shots-grid">
                <?= $shot('bs-1-obsah-exportovat-data.webp', 750, 440, 'Business Suite, Přehledy: vlevo položka Obsah, nahoře tlačítko Exportovat data', '1. Přehledy → Obsah, nahoře tlačítko Exportovat data.') ?>
                <?= $shot('bs-2-facebook-nastaveni.webp', 770, 582, 'Okno Exportovat metrická data, záložka Facebook se správným nastavením', '2. Facebook: jedna stránka, všech 5 předvoleb metrik, Zobrazení dat Dlouhodobě, Úroveň obsahu Příspěvek, pak Generovat.') ?>
                <?= $shot('bs-3-predvolby-metrik.webp', 770, 582, 'Rozbalené Předvolby metrik se všemi pěti zaškrtnutými možnostmi', 'Předvolby metrik: zaškrtni všech 5 možností.') ?>
                <?= $shot('bs-2b-instagram-nastaveni.webp', 770, 494, 'Okno Exportovat metrická data, záložka Instagram s vybraným účtem a volbou Příspěvek', '3. Instagram: jeden účet a volba Příspěvek.') ?>
                <?= $shot('bs-4-export-hotovo.webp', 372, 262, 'Okénko Váš export byl dokončen s tlačítkem Stáhnout export', '4. Po chvíli se objeví „Váš export byl dokončen", klikni na Stáhnout export.') ?>
            </div>
        </details>
        <form method="post" enctype="multipart/form-data" class="form-grid form-grid-3">
            <?= allstat_csrf_field() ?>
            <input type="hidden" name="import_type" value="content">
            <label><span>Napojení (stránka / účet)</span>
                <select name="connection_id" required>
                    <?php foreach ($connections as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= $preselect === (int) $c['id'] ? 'selected' : '' ?>>
                            <?= h($providerLabel((string) $c['provider_key'])) ?>: <?= h(($c['account_label'] ?? '') !== '' ? $c['account_label'] : $c['domain_url']) ?>
                        </option>
                    <?php endforeach; ?>
                    <?php if (!$connections): ?><option value="0">Žádné Facebook / Instagram napojení</option><?php endif; ?>
                </select>
            </label>
            <label><span>CSV „Obsah" z Business Suite</span><input type="file" name="csv" accept=".csv,text/csv" required></label>
            <div class="form-actions"><button class="button-primary" type="submit">Importovat obsah</button></div>
        </form>
        <p class="import-pitfalls"><strong>Pozor na záměny:</strong> soubory jako „Top formáty obsahu.csv" nebo „Zobrazení.csv" jsou exporty jednoho grafu z přehledu, import je nepřečte. Export se Zobrazením dat „Denně" nebo s Úrovní obsahu „Video" či „Stránka" import odmítne a napíše, co v okně exportu změnit.</p>
    </div>
</div>

<?php $fbConnections = array_values(array_filter($connections, static fn ($c) => ($c['provider_key'] ?? '') === 'facebook_pages')); ?>
<div class="admin-card" id="publikum">
    <div class="admin-card-header">
        <div>
            <h2>2. Publikum a demografie <span>(Facebook)</span></h2>
            <p><strong>Co doplní:</strong> věk, pohlaví, nejčastější města a země publika <strong>Facebook stránky</strong>, Meta tuhle demografii z API úplně odebrala. Instagram ji do AllStatu posílá přes API sám, tam CSV není potřeba. Import je snímek k dnešnímu dni. Publikum se mění pomalu, stačí jednou za měsíc.</p>
        </div>
    </div>
    <div class="admin-card-body">
        <ol class="setup-steps">
            <li><strong>V Meta Business Suite otevři Přehledy → Okruh uživatelů</strong> (<a href="<?= h($bsAudienceUrl) ?>" target="_blank" rel="noopener">otevřít</a>). Nahoře musí být ve „Výběr entity" <strong>Facebook</strong> a v přepínači úplně vlevo nahoře správná stránka.</li>
            <li>Na záložce <strong>Demografické údaje</strong> klikni vpravo na <strong>Exportovat</strong> a vyber <strong>Exportovat jako CSV</strong>. Soubor <code>Okruh uživatelů.csv</code> se stáhne hned.</li>
            <li>Nahraj ho tady:</li>
        </ol>
        <details class="help-shots">
            <summary>Ukázat obrázek z Business Suite</summary>
            <div class="help-shots-grid">
                <?= $shot('bs-5-okruh-uzivatelu-export.webp', 750, 500, 'Business Suite, Přehledy, Okruh uživatelů: přepínač Facebook a menu Exportovat s volbou Exportovat jako CSV', 'Přehledy → Okruh uživatelů, nahoře Facebook, vpravo Exportovat → Exportovat jako CSV.') ?>
            </div>
        </details>
        <?php if ($fbConnections): ?>
        <form method="post" enctype="multipart/form-data" class="form-grid form-grid-3">
            <?= allstat_csrf_field() ?>
            <input type="hidden" name="import_type" value="audience">
            <label><span>Facebook stránka</span>
                <select name="connection_id" required>
                    <?php foreach ($fbConnections as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"><?= h(($c['account_label'] ?? '') !== '' ? $c['account_label'] : $c['domain_url']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><span>CSV „Okruh uživatelů" z Business Suite</span><input type="file" name="csv" accept=".csv,text/csv" required></label>
            <div class="form-actions"><button class="button-primary" type="submit">Importovat demografii</button></div>
        </form>
        <?php else: ?>
        <p class="table-muted">Zatím není napojená žádná Facebook stránka.</p>
        <?php endif; ?>
    </div>
</div>

<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2>Jak import funguje</h2>
            <p>Čísla v exportu jsou za celou dobu života příspěvku a časem jen rostou. Import proto u každého čísla nechá tu vyšší hodnotu: nic nesníží a nic nesmaže, ani když v exportu nějaký sloupec chybí. Stejný soubor jde bez obav nahrát znovu. Facebook v exportu počítá reakce a komentáře včetně těch na cizích sdíleních příspěvku, AllStat je proto uloží do rozšířeného čísla v detailu příspěvku a hlavní čísla nechá jen za originál. Typ obsahu z exportu doplní jen tam, kde ho API nezná (Facebook v exportu nerozlišuje reels a videa, API ano).</p>
        </div>
    </div>
</div>
<?php allstat_admin_footer($config); ?>
