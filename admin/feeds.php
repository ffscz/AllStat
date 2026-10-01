<?php

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../lib/feed.php';

$pdo = allstat_admin_require_database($pdo, $config);
$user = allstat_require_user($pdo, $config);
// Správa je jen pro administrátora i pro čtení: stránka ukazuje všechny weby, běžný uživatel smí jen přehled a reporty svých webů.
allstat_require_admin($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    allstat_csrf_check();
    allstat_require_admin($user);

    if (($_POST['action'] ?? '') === 'revoke') {
        $fid = (int) ($_POST['feed_id'] ?? 0);
        if ($fid > 0) {
            allstat_feed_revoke($pdo, $fid);
            allstat_audit($pdo, (int) $user['id'], null, 'feed_revoked', (string) $fid);
            allstat_flash('ok', 'Feed byl zneplatněn, odkaz =IMPORTDATA přestane vracet data.');
        }
        allstat_redirect($config, 'admin/feeds.php');
    }

    $domainId = (int) ($_POST['domain_id'] ?? 0);
    if ($domainId <= 0) {
        allstat_flash('error', 'Vyber web.');
        allstat_redirect($config, 'admin/feeds.php');
    }

    allstat_feed_create(
        $pdo,
        $domainId,
        0, // 0 = Přehled (GA4 + GSC). Feedy dalších pohledů přidáme později.
        (string) ($_POST['shape'] ?? 'series'),
        (string) ($_POST['granularity'] ?? 'month'),
        (string) ($_POST['range_key'] ?? 'last_12_months'),
        (string) ($_POST['format'] ?? 'csv'),
        (string) ($_POST['label'] ?? '')
    );
    allstat_audit($pdo, (int) $user['id'], null, 'feed_created', 'domain=' . $domainId);
    allstat_flash('ok', 'Feed vytvořen. Zkopíruj vzorec =IMPORTDATA z tabulky níže do svého Google Sheetu.');
    allstat_redirect($config, 'admin/feeds.php');
}

$domains = allstat_admin_domains($pdo, false);
$preselect = allstat_current_domain_id($pdo, $domains, filter_input(INPUT_GET, 'domain_id', FILTER_VALIDATE_INT) ?: null);
$feeds = allstat_feed_list($pdo);

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$feedBase = $scheme . '://' . $host . allstat_url($config, 'feed.php');

$shapes = allstat_feed_shapes();
$ranges = allstat_feed_ranges();

allstat_admin_header('Datový feed', 'feeds', $user, $config);
?>
<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2>Export do Google Sheetu (pull přes =IMPORTDATA)</h2>
            <p>Vytvoř <strong>read-only</strong> odkaz na data jednoho webu (GA4 + GSC, včetně <strong>Tržeb</strong>). V Google Sheetu pak stačí jedna buňka <code>=IMPORTDATA("…")</code> a list si data sám natáhne (Google obnovuje ~1×/h). AllStat <strong>nemá žádný přístup do tvého Google účtu</strong>, odkaz jen vystavuje agregovaná čísla. Token = přístup; kdykoli ho zneplatníš a přestane vracet data.</p>
        </div>
    </div>
    <div class="admin-card-body">
        <form method="post" class="form-grid form-grid-3">
            <?= allstat_csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <label><span>Web</span>
                <select name="domain_id" required>
                    <?php foreach ($domains as $domain): ?>
                        <option value="<?= (int) $domain['id'] ?>" <?= $preselect === (int) $domain['id'] ? 'selected' : '' ?>><?= h($domain['url']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><span>Podoba</span>
                <select name="shape">
                    <?php foreach ($shapes as $k => $lbl): ?>
                        <option value="<?= h($k) ?>"><?= h($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><span>Granularita (u řady)</span>
                <select name="granularity">
                    <option value="month" selected>Po měsících</option>
                    <option value="week">Po týdnech</option>
                    <option value="day">Po dnech</option>
                </select>
            </label>
            <label><span>Období (klouzavé)</span>
                <select name="range_key">
                    <?php foreach ($ranges as $k => $lbl): ?>
                        <option value="<?= h($k) ?>" <?= $k === 'last_12_months' ? 'selected' : '' ?>><?= h($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><span>Formát</span>
                <select name="format">
                    <option value="csv" selected>CSV (pro =IMPORTDATA)</option>
                    <option value="tsv">TSV</option>
                    <option value="json">JSON</option>
                </select>
            </label>
            <label><span>Popisek (volitelně)</span><input type="text" name="label" maxlength="120" placeholder="např. Blend 2026"></label>
            <div class="form-actions"><button class="button-primary" type="submit">Vytvořit feed</button></div>
        </form>
    </div>
</div>

<div class="admin-card">
    <div class="admin-card-header"><h2>Moje feedy</h2></div>
    <div class="admin-card-body table-scroll">
        <table class="admin-table">
            <thead><tr><th>Web</th><th>Co exportuje</th><th>Vzorec do Google Sheetu</th><th>Naposledy staženo</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($feeds as $f): ?>
                    <?php
                        $revoked = !empty($f['revoked_at']);
                        $url = $feedBase . '?key=' . rawurlencode((string) $f['token']);
                        if (($f['format'] ?? 'csv') !== 'csv') { $url .= '&format=' . rawurlencode((string) $f['format']); }
                        $formula = '=IMPORTDATA("' . $url . '")';
                        $what = ($shapes[$f['shape']] ?? (string) $f['shape']) . ' · ' . ($ranges[$f['range_key']] ?? (string) $f['range_key']);
                        if (($f['shape'] ?? '') === 'series') { $what .= ' · ' . (string) $f['granularity']; }
                    ?>
                    <tr>
                        <td>
                            <?= h((string) ($f['domain_url'] ?? ('#' . $f['domain_id']))) ?>
                            <?php if (!empty($f['label'])): ?><br><small class="table-muted"><?= h((string) $f['label']) ?></small><?php endif; ?>
                        </td>
                        <td><?= h($what) ?></td>
                        <td>
                            <?php if ($revoked): ?>
                                <span class="status-badge status-error">Zneplatněno</span>
                            <?php else: ?>
                                <input type="text" class="feed-formula" readonly value="<?= h($formula) ?>" data-select-all title="Klikni a zkopíruj (Ctrl+C)">
                            <?php endif; ?>
                        </td>
                        <td><?= $f['last_access_at'] ? h(substr((string) $f['last_access_at'], 0, 16)) : '—' ?></td>
                        <td>
                            <?php if (!$revoked): ?>
                                <form method="post" data-confirm="Zneplatnit tento feed? Odkaz =IMPORTDATA přestane vracet data.">
                                    <?= allstat_csrf_field() ?>
                                    <input type="hidden" name="action" value="revoke">
                                    <input type="hidden" name="feed_id" value="<?= (int) $f['id'] ?>">
                                    <button class="button-secondary" type="submit">Zneplatnit</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$feeds): ?>
                    <tr><td colspan="5" class="table-muted">Zatím žádný feed. Vytvoř první výše.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php allstat_admin_footer($config); ?>
