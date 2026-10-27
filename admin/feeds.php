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
            allstat_flash('ok', t('Feed byl zneplatněn, odkaz =IMPORTDATA přestane vracet data.'));
        }
        allstat_redirect($config, 'admin/feeds.php');
    }

    $domainId = (int) ($_POST['domain_id'] ?? 0);
    if ($domainId <= 0) {
        allstat_flash('error', t('Vyber web.'));
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
    allstat_flash('ok', t('Feed vytvořen. Zkopíruj vzorec =IMPORTDATA z tabulky níže do svého Google Sheetu.'));
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

allstat_admin_header(t('Datový feed'), 'feeds', $user, $config);
?>
<section class="card">
    <div class="card-head">
        <div>
            <h2 class="card-title"><?= h(t('Export do Google Sheetu (pull přes =IMPORTDATA)')) ?></h2>
            <p class="card-desc"><?= t('Vytvoř <strong>read-only</strong> odkaz na data jednoho webu (GA4 + GSC, včetně <strong>Tržeb</strong>). V Google Sheetu pak stačí jedna buňka <code>=IMPORTDATA("…")</code> a list si data sám natáhne (Google obnovuje ~1×/h). AllStat <strong>nemá žádný přístup do tvého Google účtu</strong>, odkaz jen vystavuje agregovaná čísla. Token = přístup; kdykoli ho zneplatníš a přestane vracet data.') ?></p>
        </div>
    </div>
    <form method="post" class="card-body form-grid">
        <?= allstat_csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <label class="field"><span class="field-label"><?= h(t('Web')) ?></span>
            <select class="input" name="domain_id" required>
                <?php foreach ($domains as $domain): ?>
                    <option value="<?= (int) $domain['id'] ?>" <?= $preselect === (int) $domain['id'] ? 'selected' : '' ?>><?= h($domain['url']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field"><span class="field-label"><?= h(t('Podoba')) ?></span>
            <select class="input" name="shape">
                <?php foreach ($shapes as $k => $lbl): ?>
                    <option value="<?= h($k) ?>"><?= h(t($lbl)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field"><span class="field-label"><?= h(t('Granularita (u řady)')) ?></span>
            <select class="input" name="granularity">
                <option value="month" selected><?= h(t('Po měsících')) ?></option>
                <option value="week"><?= h(t('Po týdnech')) ?></option>
                <option value="day"><?= h(t('Po dnech')) ?></option>
            </select>
        </label>
        <label class="field"><span class="field-label"><?= h(t('Období (klouzavé)')) ?></span>
            <select class="input" name="range_key">
                <?php foreach ($ranges as $k => $lbl): ?>
                    <option value="<?= h($k) ?>" <?= $k === 'last_12_months' ? 'selected' : '' ?>><?= h(t($lbl)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field"><span class="field-label"><?= h(t('Formát')) ?></span>
            <select class="input" name="format">
                <option value="csv" selected><?= h(t('CSV (pro =IMPORTDATA)')) ?></option>
                <option value="tsv">TSV</option>
                <option value="json">JSON</option>
            </select>
        </label>
        <label class="field"><span class="field-label"><?= h(t('Popisek (volitelně)')) ?></span><input class="input" type="text" name="label" maxlength="120" placeholder="<?= h(t('např. Blend 2026')) ?>"></label>
        <div class="form-actions field-full"><button class="btn btn-primary" type="submit"><?= h(t('Vytvořit feed')) ?></button></div>
    </form>
</section>

<section class="card">
    <div class="card-head">
        <div>
            <h2 class="card-title"><?= h(t('Moje feedy')) ?></h2>
        </div>
    </div>
    <?php if ($feeds): ?>
    <div class="table-wrap">
        <table class="table table-data">
            <colgroup><col class="col-lg"><col class="col-lg"><col><col class="col-md"><col class="col-md"></colgroup>
            <thead><tr><th scope="col"><?= h(t('Web')) ?></th><th scope="col"><?= h(t('Co exportuje')) ?></th><th scope="col"><?= h(t('Vzorec do Google Sheetu')) ?></th><th scope="col"><?= h(t('Naposledy staženo')) ?></th><th scope="col" data-no-sort><span class="sr-only"><?= h(t('Akce')) ?></span></th></tr></thead>
            <tbody>
                <?php foreach ($feeds as $f): ?>
                    <?php
                        $revoked = !empty($f['revoked_at']);
                        $url = $feedBase . '?key=' . rawurlencode((string) $f['token']);
                        if (($f['format'] ?? 'csv') !== 'csv') { $url .= '&format=' . rawurlencode((string) $f['format']); }
                        $formula = '=IMPORTDATA("' . $url . '")';
                        $what = t($shapes[$f['shape']] ?? (string) $f['shape']) . ' · ' . t($ranges[$f['range_key']] ?? (string) $f['range_key']);
                        if (($f['shape'] ?? '') === 'series') { $what .= ' · ' . t((string) $f['granularity']); }
                    ?>
                    <tr>
                        <td>
                            <div class="name-stack">
                                <span class="name-main ellipsis"><?= h((string) ($f['domain_url'] ?? ('#' . $f['domain_id']))) ?></span>
                                <?php if (!empty($f['label'])): ?><span class="name-sub ellipsis"><?= h((string) $f['label']) ?></span><?php endif; ?>
                            </div>
                        </td>
                        <td class="is-wrap"><?= h($what) ?></td>
                        <td>
                            <?php if ($revoked): ?>
                                <?= allstat_status_pill('error', t('Zneplatněno')) ?>
                            <?php else: ?>
                                <input type="text" class="input mono" readonly value="<?= h($formula) ?>" data-select-all title="<?= h(t('Klikni a zkopíruj (Ctrl+C)')) ?>">
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= $f['last_access_at'] ? h(substr((string) $f['last_access_at'], 0, 16)) : '-' ?></td>
                        <td>
                            <?php if (!$revoked): ?>
                                <form method="post" data-confirm="<?= h(t('Zneplatnit tento feed? Odkaz =IMPORTDATA přestane vracet data.')) ?>">
                                    <?= allstat_csrf_field() ?>
                                    <input type="hidden" name="action" value="revoke">
                                    <input type="hidden" name="feed_id" value="<?= (int) $f['id'] ?>">
                                    <button class="btn btn-secondary btn-sm" type="submit"><?= h(t('Zneplatnit')) ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty">
        <span class="empty-desc"><?= h(t('Zatím žádný feed. Vytvoř první výše.')) ?></span>
    </div>
    <?php endif; ?>
</section>
<?php allstat_admin_footer($config); ?>
