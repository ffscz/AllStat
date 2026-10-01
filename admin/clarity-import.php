<?php

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_layout.php';

$pdo = allstat_admin_require_database($pdo, $config);
$user = allstat_require_user($pdo, $config);
// Správa je jen pro administrátora i pro čtení: stránka ukazuje všechny weby, běžný uživatel smí jen přehled a reporty svých webů.
allstat_require_admin($user);

// Clean keys the Clarity view understands. Anything else under the Clarity source is legacy junk
// (from a pre-fix backfill via the old generic-dump engine) and can be purged.
const ALLSTAT_CLARITY_KEYS = [
    'sessions', 'bot_sessions', 'new_user_sessions', 'returning_user_sessions',
    'scroll_depth', 'active_time', 'pages_per_session',
    'referrer', 'page', 'browser', 'smart_event',
];

/**
 * Parse a Microsoft Clarity dashboard CSV export (single-day only — multi-day exports are period
 * aggregates that can't be split). Captures additive counts, per-session averages, and breakdowns.
 */
function allstat_parse_clarity_csv(string $raw): array
{
    $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    $start = null;
    $end = null;
    $sessions = null;
    $bots = null;
    $scalars = [];
    $breakdowns = ['referrer' => [], 'page' => [], 'browser' => [], 'smart_event' => []];
    $sectionToKey = ['Referrer' => 'referrer', 'Top pages' => 'page', 'Browsers' => 'browser', 'Smart events' => 'smart_event'];
    $section = null;

    foreach ($lines as $line) {
        if (trim($line) === '') { $section = null; continue; }
        $cols = str_getcsv($line);
        $c0 = trim((string) ($cols[0] ?? ''));
        $c1 = trim((string) ($cols[1] ?? ''));
        $c2 = trim((string) ($cols[2] ?? ''));

        if ($c0 === 'Date range') {
            if (preg_match('#(\d{2})/(\d{2})/(\d{4}).*?-\s*(\d{2})/(\d{2})/(\d{4})#', $c1, $m)) {
                $start = $m[3] . '-' . $m[1] . '-' . $m[2];
                $end = $m[6] . '-' . $m[4] . '-' . $m[5];
            }
            continue;
        }
        if ($c0 === 'Metric') { $section = $c1; continue; }

        if ($section === 'Sessions') {
            if ($c1 === 'Total sessions' && is_numeric($c2)) { $sessions = (int) $c2; }
            elseif ($c1 === 'Bot sessions' && is_numeric($c2)) { $bots = (int) $c2; }
        } elseif ($section === 'Scroll depth' && $c1 === 'Average' && is_numeric($c2)) {
            $scalars['scroll_depth'] = (float) $c2;
        } elseif ($section === 'Active time spent' && $c1 === 'Active time' && is_numeric($c2)) {
            $scalars['active_time'] = (float) $c2;
        } elseif ($section === 'Pages per session' && $c1 === 'Average' && is_numeric($c2)) {
            $scalars['pages_per_session'] = (float) $c2;
        } elseif ($section === 'Users overview') {
            if ($c1 === 'Sessions with new users' && is_numeric($c2)) { $scalars['new_user_sessions'] = (float) $c2; }
            elseif ($c1 === 'Sessions with returning users' && is_numeric($c2)) { $scalars['returning_user_sessions'] = (float) $c2; }
        } elseif (isset($sectionToKey[$section]) && $c1 !== '' && is_numeric($c2)) {
            $breakdowns[$sectionToKey[$section]][] = ['name' => $c1, 'value' => (int) $c2];
        }
    }

    if (!$start || !$end) {
        return ['ok' => false, 'message' => 'V CSV se nenašel řádek „Date range", je to opravdu export z Clarity?'];
    }
    if ($start !== $end) {
        return ['ok' => false, 'message' => 'Tohle je agregát za období (' . $start . ' → ' . $end . '). Pro denní řadu nahraj jednodenní export (Clarity → Dnes nebo Včera). Vícedenní souhrn nelze rozpadnout na dny.'];
    }
    if ($sessions === null) {
        return ['ok' => false, 'message' => 'V CSV se nenašlo „Total sessions".'];
    }

    return ['ok' => true, 'date' => $start, 'sessions' => $sessions, 'bots' => $bots ?? 0, 'scalars' => $scalars, 'breakdowns' => $breakdowns];
}

$claritySourceId = (int) (allstat_fetch_one($pdo, "SELECT id FROM data_sources WHERE provider_key = 'clarity'")['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    allstat_csrf_check();
    allstat_require_admin($user);

    if ($claritySourceId === 0) {
        allstat_flash('error', 'Provider Microsoft Clarity není v katalogu zdrojů.');
        allstat_redirect($config, 'admin/clarity-import.php');
    }

    if (($_POST['action'] ?? '') === 'cleanup') {
        $placeholders = implode(',', array_fill(0, count(ALLSTAT_CLARITY_KEYS), '?'));
        $statement = $pdo->prepare("DELETE FROM provider_metrics_daily WHERE source_id = ? AND metric_key NOT IN ($placeholders)");
        $statement->execute(array_merge([$claritySourceId], ALLSTAT_CLARITY_KEYS));
        $deleted = $statement->rowCount();
        allstat_audit($pdo, (int) $user['id'], null, 'clarity_legacy_purged', (string) $deleted);
        allstat_flash('ok', sprintf('Smazáno %d starých (nepoužívaných) řádků Clarity dat.', $deleted));
        allstat_redirect($config, 'admin/clarity-import.php');
    }

    $domainId = (int) ($_POST['domain_id'] ?? 0);
    // provider_metrics_daily is keyed per connection — resolve this domain's Clarity connection so the
    // imported rows line up with the dashboard (which reads by connection_id, not the catalog source_id).
    $clarityConnId = (int) (allstat_fetch_one($pdo, 'SELECT id FROM domain_sources WHERE domain_id = ? AND source_id = ? ORDER BY is_enabled DESC, id ASC LIMIT 1', [$domainId, $claritySourceId])['id'] ?? 0);
    if ($clarityConnId === 0) {
        allstat_flash('error', 'Pro tento web není napojená Microsoft Clarity. Přidej ji nejdřív ve Zdroje dat, pak importuj CSV.');
        allstat_redirect($config, 'admin/clarity-import.php');
    }
    $file = $_FILES['csv'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) <= 0) {
        allstat_flash('error', 'Nahraj prosím CSV soubor exportovaný z Clarity.');
        allstat_redirect($config, 'admin/clarity-import.php');
    }
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        allstat_flash('error', 'Soubor je příliš velký (max 2 MB).');
        allstat_redirect($config, 'admin/clarity-import.php');
    }

    $parsed = allstat_parse_clarity_csv((string) file_get_contents($file['tmp_name']));
    if (!$parsed['ok']) {
        allstat_flash('error', $parsed['message']);
        allstat_redirect($config, 'admin/clarity-import.php');
    }

    // Clean re-import: drop everything stored for this day+connection, then write fresh rows.
    $pdo->prepare('DELETE FROM provider_metrics_daily WHERE domain_id = ? AND connection_id = ? AND metric_date = ?')
        ->execute([$domainId, $clarityConnId, $parsed['date']]);

    $insert = $pdo->prepare("
        INSERT INTO provider_metrics_daily (domain_id, source_id, connection_id, metric_date, metric_key, dimension, metric_value)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE metric_value = VALUES(metric_value)
    ");
    $insert->execute([$domainId, $claritySourceId, $clarityConnId, $parsed['date'], 'sessions', '', $parsed['sessions']]);
    $insert->execute([$domainId, $claritySourceId, $clarityConnId, $parsed['date'], 'bot_sessions', '', $parsed['bots']]);
    foreach ($parsed['scalars'] as $key => $value) {
        $insert->execute([$domainId, $claritySourceId, $clarityConnId, $parsed['date'], $key, '', $value]);
    }
    $dimRows = 0;
    foreach ($parsed['breakdowns'] as $key => $items) {
        foreach ($items as $item) {
            $insert->execute([$domainId, $claritySourceId, $clarityConnId, $parsed['date'], $key, mb_substr((string) $item['name'], 0, 120), (int) $item['value']]);
            $dimRows++;
        }
    }

    allstat_audit($pdo, (int) $user['id'], null, 'clarity_csv_imported', $parsed['date'] . ' sessions=' . $parsed['sessions']);
    allstat_flash('ok', sprintf('Importováno z Clarity CSV: %s, %d návštěv, %d botů, %d metrik + %d řádků rozpadů.', $parsed['date'], $parsed['sessions'], $parsed['bots'], count($parsed['scalars']), $dimRows));
    allstat_redirect($config, 'admin/clarity-import.php');
}

$domains = allstat_admin_domains($pdo, false);
$preselect = allstat_current_domain_id($pdo, $domains, filter_input(INPUT_GET, 'domain_id', FILTER_VALIDATE_INT) ?: null);

$recent = $claritySourceId ? allstat_fetch_all($pdo, "
    SELECT d.url AS domain_url, pm.metric_date,
        MAX(CASE WHEN pm.metric_key = 'sessions' THEN pm.metric_value END) AS sessions,
        MAX(CASE WHEN pm.metric_key = 'bot_sessions' THEN pm.metric_value END) AS bot_sessions,
        SUM(pm.metric_key NOT IN ('sessions','bot_sessions')) AS dim_rows
    FROM provider_metrics_daily pm
    INNER JOIN domains d ON d.id = pm.domain_id
    WHERE pm.source_id = ?
    GROUP BY pm.domain_id, d.url, pm.metric_date
    ORDER BY pm.metric_date DESC
    LIMIT 40
", [$claritySourceId]) : [];

allstat_admin_header('Import Clarity CSV', 'sources', $user, $config);
?>
<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2>Import Microsoft Clarity CSV</h2>
            <p>Clarity API neumí historii (jen poslední 1–3 dny), proto jde data doplnit ručně z exportu. Nahraj <strong>jednodenní</strong> export (Clarity → zvol „Dnes" nebo „Včera" → Export CSV). Uloží se návštěvy, boti, průměry (aktivní čas, scroll, stránky/relace) i rozpady (referrery, stránky, prohlížeče, smart events). Vícedenní souhrny (3 dny, celé období) nelze rozpadnout na dny.</p>
        </div>
    </div>
    <div class="admin-card-body">
        <form method="post" enctype="multipart/form-data" class="form-grid form-grid-3">
            <?= allstat_csrf_field() ?>
            <label><span>Web</span>
                <select name="domain_id" required>
                    <?php foreach ($domains as $domain): ?>
                        <option value="<?= (int) $domain['id'] ?>" <?= $preselect === (int) $domain['id'] ? 'selected' : '' ?>><?= h($domain['url']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><span>Clarity CSV (jeden den)</span><input type="file" name="csv" accept=".csv,text/csv" required></label>
            <div class="form-actions"><button class="button-primary" type="submit">Importovat</button></div>
        </form>
    </div>
</div>

<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2>Údržba dat</h2>
            <p>Smaže stará nepoužívaná Clarity data (pozůstatky z dřívějšího syncu přes starý engine). Návštěvy, boti, průměry a rozpady zůstanou.</p>
        </div>
        <form method="post" data-confirm="Smazat stará nepoužívaná Clarity data? Čisté metriky (návštěvy, boti, rozpady) zůstanou.">
            <?= allstat_csrf_field() ?>
            <input type="hidden" name="action" value="cleanup">
            <button class="button-secondary" type="submit">Vyčistit stará Clarity data</button>
        </form>
    </div>
</div>

<div class="admin-card">
    <div class="admin-card-header"><h2>Naimportované dny (Clarity)</h2></div>
    <div class="admin-card-body table-scroll">
        <table class="admin-table">
            <thead><tr><th>Datum</th><th>Web</th><th>Návštěvy</th><th>Boti</th><th>Metriky/rozpady (řádků)</th></tr></thead>
            <tbody>
                <?php foreach ($recent as $row): ?>
                    <tr>
                        <td><?= h($row['metric_date']) ?></td>
                        <td><?= h($row['domain_url']) ?></td>
                        <td><?= h(allstat_number((float) ($row['sessions'] ?? 0))) ?></td>
                        <td><?= h(allstat_number((float) ($row['bot_sessions'] ?? 0))) ?></td>
                        <td><?= h(allstat_number((int) ($row['dim_rows'] ?? 0))) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$recent): ?>
                    <tr><td colspan="5" class="table-muted">Zatím nic naimportováno. Nahraj jednodenní Clarity CSV výše.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php allstat_admin_footer($config); ?>
