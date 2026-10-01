<?php

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_layout.php';

$pdo = allstat_admin_require_database($pdo, $config);
$user = allstat_require_user($pdo, $config);
// Správa je jen pro administrátora i pro čtení: stránka ukazuje všechny weby, běžný uživatel smí jen přehled a reporty svých webů.
allstat_require_admin($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    allstat_csrf_check();
    allstat_require_admin($user);
    $domainId = (int) ($_POST['domain_id'] ?? 0);
    $date = allstat_normalize_date($_POST['metric_date'] ?? null, date('Y-m-d'));
    $statement = $pdo->prepare('INSERT INTO metrics_daily (domain_id, metric_date, visits, users_count, clicks, impressions, conversions) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE visits = VALUES(visits), users_count = VALUES(users_count), clicks = VALUES(clicks), impressions = VALUES(impressions), conversions = VALUES(conversions)');
    $statement->execute([
        $domainId,
        $date,
        max(0, (int) ($_POST['visits'] ?? 0)),
        max(0, (int) ($_POST['users_count'] ?? 0)),
        max(0, (int) ($_POST['clicks'] ?? 0)),
        max(0, (int) ($_POST['impressions'] ?? 0)),
        max(0, (int) ($_POST['conversions'] ?? 0)),
    ]);
    allstat_audit($pdo, (int) $user['id'], null, 'metric_upserted', $date);
    allstat_flash('ok', 'Denní metriky byly uloženy.');
    allstat_redirect($config, 'admin/metrics.php');
}

$domains = allstat_admin_domains($pdo, false);

// "Tečou data?" — souhrn per web: poslední den se záznamem, pokrytí posledních 30 dní a trend návštěv.
$today = new DateTimeImmutable('today');
$d7 = $today->modify('-6 days')->format('Y-m-d');
$d14 = $today->modify('-13 days')->format('Y-m-d');
$d30 = $today->modify('-29 days')->format('Y-m-d');
$health = allstat_fetch_all($pdo, '
    SELECT d.id, d.url,
           MAX(m.metric_date) AS last_date,
           COUNT(DISTINCT CASE WHEN m.metric_date >= ? THEN m.metric_date END) AS days30,
           COALESCE(SUM(CASE WHEN m.metric_date >= ? THEN m.visits END), 0) AS visits7,
           COALESCE(SUM(CASE WHEN m.metric_date >= ? AND m.metric_date < ? THEN m.visits END), 0) AS visits7prev,
           COALESCE(SUM(CASE WHEN m.metric_date >= ? THEN m.conversions END), 0) AS conv30
    FROM domains d
    LEFT JOIN metrics_daily m ON m.domain_id = d.id
    GROUP BY d.id, d.url
    ORDER BY d.url ASC
', [$d30, $d7, $d14, $d7, $d30]);

foreach ($health as &$hRow) {
    if ($hRow['last_date'] === null) {
        $hRow['status'] = ['error', 'Bez dat'];
        $hRow['lagLabel'] = '—';
    } else {
        $lag = (int) $today->diff(new DateTimeImmutable($hRow['last_date']))->days;
        $hRow['status'] = $lag <= 1 ? ['ok', 'Aktuální'] : ($lag <= 3 ? ['warning', 'Zpoždění'] : ['error', 'Neteče']);
        $hRow['lagLabel'] = $lag === 0 ? 'dnes' : ($lag === 1 ? 'včera' : 'před ' . $lag . ' dny');
    }
    $prev = (int) $hRow['visits7prev'];
    $cur = (int) $hRow['visits7'];
    $hRow['trend'] = $prev > 0 ? ($cur - $prev) / $prev * 100 : null;
    $hRow['coverage'] = min(100, (int) $hRow['days30'] / 30 * 100);
}
unset($hRow);

// Filtr výpisu: web + délka období (GET, auto-submit).
// Bez parametru se předvolí web zvolený jinde v administraci; „Všechny weby" posílá domain_id=0.
$filterDomain = array_key_exists('domain_id', $_GET)
    ? (filter_input(INPUT_GET, 'domain_id', FILTER_VALIDATE_INT) ?: 0)
    : allstat_current_domain_id($pdo, $domains);
if ($filterDomain > 0) {
    allstat_remember_domain_id($filterDomain);
}
$filterDays = (int) ($_GET['days'] ?? 30);
if (!in_array($filterDays, [14, 30, 90], true)) {
    $filterDays = 30;
}
$since = $today->modify('-' . ($filterDays - 1) . ' days')->format('Y-m-d');
$listSql = 'SELECT m.*, d.url AS domain_url FROM metrics_daily m INNER JOIN domains d ON d.id = m.domain_id WHERE m.metric_date >= ?';
$listParams = [$since];
if ($filterDomain > 0) {
    $listSql .= ' AND m.domain_id = ?';
    $listParams[] = $filterDomain;
}
$listSql .= ' ORDER BY m.metric_date DESC, d.url ASC LIMIT 200';
$rows = allstat_fetch_all($pdo, $listSql, $listParams);
$maxVisits = max(1, ...array_map(static fn (array $r): int => (int) $r['visits'], $rows ?: [['visits' => 1]]));
$weekdays = ['Ne', 'Po', 'Út', 'St', 'Čt', 'Pá', 'So'];

allstat_admin_header('Metriky', 'metrics', $user, $config);
?>
<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2>Tečou data správně?</h2>
            <p>Denní agregace návštěv, uživatelů, kliknutí, zobrazení a konverzí plní automaticky synci napojených providerů (GA4, GSC). Tady na první pohled vidíš, jestli sync běží a data jsou čerstvá.</p>
        </div>
    </div>
    <div class="admin-card-body table-scroll">
        <table class="admin-table">
            <thead><tr><th>Web</th><th>Stav</th><th>Poslední den s daty</th><th>Pokrytí 30 dní</th><th class="num">Návštěvy (7 dní)</th><th>Trend vs. předchozích 7</th><th class="num">Konverze (30 dní)</th></tr></thead>
            <tbody>
            <?php foreach ($health as $hRow): ?>
                <tr>
                    <td><strong><?= h($hRow['url']) ?></strong></td>
                    <td><span class="status-badge status-<?= h($hRow['status'][0]) ?>"><?= h($hRow['status'][1]) ?></span></td>
                    <td><?= $hRow['last_date'] ? h(date('j. n. Y', strtotime($hRow['last_date']))) . ' <span class="table-muted">(' . h($hRow['lagLabel']) . ')</span>' : '<span class="table-muted">—</span>' ?></td>
                    <td>
                        <div class="coverage-cell">
                            <div class="quota-bar"><div class="quota-bar-fill <?= $hRow['coverage'] >= 90 ? 'quota-bar-ok' : ($hRow['coverage'] >= 50 ? 'quota-bar-warn' : 'quota-bar-danger') ?>" style="width: <?= round($hRow['coverage']) ?>%;"></div></div>
                            <span class="table-muted"><?= (int) $hRow['days30'] ?>/30 dní</span>
                        </div>
                    </td>
                    <td class="num"><strong><?= h(allstat_number((int) $hRow['visits7'])) ?></strong></td>
                    <td><?php if ($hRow['trend'] === null): ?><span class="table-muted"<span>bez srovnání</span><?php else: ?><span class="<?= $hRow['trend'] >= 0 ? 'positive' : 'negative' ?>"><?= $hRow['trend'] >= 0 ? '&uarr;' : '&darr;' ?> <?= h(allstat_percent(abs($hRow['trend']), 1)) ?></span><?php endif; ?></td>
                    <td class="num"><?= h(allstat_number((int) $hRow['conv30'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2>Denní řádky</h2>
            <p>Surová data po dnech, ze kterých dashboard počítá. Slouží ke kontrole konkrétního dne, např. když číslo na dashboardu nesedí s GA4.</p>
        </div>
        <form method="get" class="card-header-actions metrics-filter">
            <label><span class="table-muted">Web</span>
                <select name="domain_id" data-autosubmit>
                    <option value="0">Všechny weby</option>
                    <?php foreach ($domains as $domain): ?><option value="<?= (int) $domain['id'] ?>"<?= $filterDomain === (int) $domain['id'] ? ' selected' : '' ?>><?= h($domain['url']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label><span class="table-muted">Období</span>
                <select name="days" data-autosubmit>
                    <?php foreach ([14, 30, 90] as $d): ?><option value="<?= $d ?>"<?= $filterDays === $d ? ' selected' : '' ?>>posledních <?= $d ?> dní</option><?php endforeach; ?>
                </select>
            </label>
        </form>
    </div>
    <div class="admin-card-body table-scroll">
        <table class="admin-table">
            <thead><tr><th>Datum</th><?php if ($filterDomain === 0): ?><th>Web</th><?php endif; ?><th>Návštěvy</th><th class="num">Uživatelé</th><th class="num">Kliknutí</th><th class="num">Zobrazení</th><th class="num">Konverze</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): $dt = strtotime($row['metric_date']); ?>
                <tr>
                    <td><span class="table-muted"><?= h($weekdays[(int) date('w', $dt)]) ?></span> <strong><?= h(date('j. n. Y', $dt)) ?></strong></td>
                    <?php if ($filterDomain === 0): ?><td><?= h($row['domain_url']) ?></td><?php endif; ?>
                    <td>
                        <div class="visits-cell">
                            <span class="visits-cell-value"><?= h(allstat_number($row['visits'])) ?></span>
                            <div class="quota-bar visits-cell-bar"><div class="quota-bar-fill quota-bar-neutral" style="width: <?= round(max(2, (int) $row['visits'] / $maxVisits * 100)) ?>%;"></div></div>
                        </div>
                    </td>
                    <td class="num"><?= h(allstat_number($row['users_count'])) ?></td>
                    <td class="num"><?= h(allstat_number($row['clicks'])) ?></td>
                    <td class="num"><?= h(allstat_number($row['impressions'])) ?></td>
                    <td class="num"><?= (int) $row['conversions'] > 0 ? '<strong>' . h(allstat_number($row['conversions'])) . '</strong>' : '<span class="table-muted">0</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="7" class="table-muted">Za zvolené období nejsou žádné denní řádky, zkontroluj sync ve <a href="sources.php">Zdrojích dat</a>.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<details class="admin-card admin-card-collapsible">
    <summary class="admin-card-header">
        <div>
            <h2>Pokročilé: ručně vložit / přepsat denní řádek</h2>
            <p>Pro testování dashboardu nebo dočasný import před napojením API. Běžně nepotřebné, sync to dělá automaticky.</p>
        </div>
    </summary>
    <div class="admin-card-body">
        <form method="post" class="form-grid form-grid-3">
            <?= allstat_csrf_field() ?>
            <label><span>Web</span><select name="domain_id" required><?php foreach ($domains as $domain): ?><option value="<?= (int) $domain['id'] ?>"><?= h($domain['url']) ?></option><?php endforeach; ?></select></label>
            <label><span>Datum</span><input type="date" name="metric_date" required value="<?= h(date('Y-m-d')) ?>"></label>
            <label><span>Návštěvy</span><input type="number" name="visits" min="0" value="0"></label>
            <label><span>Uživatelé</span><input type="number" name="users_count" min="0" value="0"></label>
            <label><span>Kliknutí</span><input type="number" name="clicks" min="0" value="0"></label>
            <label><span>Zobrazení</span><input type="number" name="impressions" min="0" value="0"></label>
            <label><span>Konverze</span><input type="number" name="conversions" min="0" value="0"></label>
            <div class="form-actions"><button class="button-primary" type="submit">Uložit metriky</button></div>
        </form>
    </div>
</details>
<?php allstat_admin_footer($config); ?>
