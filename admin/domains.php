<?php

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_layout.php';

$pdo = allstat_admin_require_database($pdo, $config);
$user = allstat_require_user($pdo, $config);
// Správa je jen pro administrátora i pro čtení: stránka ukazuje všechny weby, běžný uživatel smí jen přehled a reporty svých webů.
allstat_require_admin($user);
$editing = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    allstat_csrf_check();
    allstat_require_admin($user);
    $action = (string) ($_POST['action'] ?? 'save');

    if ($action === 'delete') {
        $result = allstat_delete_domain($pdo, (int) $user['id'], (int) ($_POST['id'] ?? 0));
    } elseif ($action === 'set_primary') {
        // Primární web = výchozí po přihlášení (stejné nastavení jako v Nastavení → app.primary_domain_id).
        $primary = allstat_fetch_one($pdo, 'SELECT id, name FROM domains WHERE id = ? AND is_active = 1', [(int) ($_POST['id'] ?? 0)]);
        if ($primary) {
            allstat_set_setting($pdo, 'app.primary_domain_id', (string) (int) $primary['id']);
            allstat_remember_domain_id((int) $primary['id']);
            allstat_audit($pdo, (int) $user['id'], null, 'settings_updated', 'primary_domain_id=' . (int) $primary['id']);
            $result = ['ok' => true, 'message' => 'Web ' . $primary['name'] . ' je teď primární, otevře se hned po přihlášení.'];
        } else {
            $result = ['ok' => false, 'message' => 'Primárním webem může být jen aktivní web.'];
        }
    } else {
        $result = allstat_save_domain($pdo, (int) $user['id'], $_POST);
    }

    allstat_flash($result['ok'] ? 'ok' : 'error', $result['message']);
    allstat_redirect($config, 'admin/domains.php');
}

if (isset($_GET['edit'])) {
    $editing = allstat_fetch_one($pdo, 'SELECT * FROM domains WHERE id = ?', [(int) $_GET['edit']]);
}

$domains = allstat_admin_domains($pdo);
$activeDomains = array_values(array_filter($domains, static fn (array $d): bool => (int) $d['is_active'] === 1));
$primaryDomainId = allstat_primary_domain_id($pdo, $activeDomains);
$primaryIsExplicit = (int) allstat_setting($pdo, 'app.primary_domain_id', '0') === $primaryDomainId && $primaryDomainId > 0;
allstat_admin_header('Weby', 'domains', $user, $config);
?>
<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2><?= $editing ? 'Upravit web' : 'Nový web' ?></h2>
            <p>Web je základní jednotka pro GA4, GSC, Ads a social napojení.</p>
        </div>
    </div>
    <div class="admin-card-body">
        <form method="post" class="form-grid">
            <?= allstat_csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
            <label><span>Název</span><input type="text" name="name" required value="<?= h($editing['name'] ?? '') ?>" placeholder="Vaše doména"></label>
            <label><span>Doména</span><input type="text" name="url" required value="<?= h($editing['url'] ?? '') ?>" placeholder="example.cz"></label>
            <label><span>Stav</span><select name="is_active"><option value="1" <?= (int) ($editing['is_active'] ?? 1) === 1 ? 'selected' : '' ?>>Aktivní</option><option value="0" <?= isset($editing['is_active']) && (int) $editing['is_active'] === 0 ? 'selected' : '' ?>>Vypnuto</option></select></label>
            <div class="form-actions">
                <button class="button-primary" type="submit">Uložit web</button>
                <?php if ($editing): ?><a class="button-secondary" href="domains.php">Zrušit úpravu</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2>Spravované weby</h2>
            <p>Primární web se otevře hned po přihlášení. Web zvolený v přehledu nebo ve filtrech se pak drží napříč celou administrací, dokud ho sám nepřepneš.<?php if (!$primaryIsExplicit && $primaryDomainId): ?> Primární web zatím není nastaven, používá se web shodný s doménou, na které AllStat běží, jinak první podle abecedy.<?php endif; ?></p>
        </div>
    </div>
    <div class="admin-card-body table-scroll">
        <table class="admin-table">
            <thead><tr><th>Název</th><th>Doména</th><th>Zdroje</th><th>Stav</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($domains as $domain): $isPrimary = (int) $domain['id'] === $primaryDomainId; ?>
                <tr>
                    <td><strong><?= h($domain['name']) ?></strong><?php if ($isPrimary): ?> <span class="status-badge status-info" title="<?= $primaryIsExplicit ? 'Otevře se hned po přihlášení.' : 'Automaticky: shoduje se s doménou aplikace, nebo je první podle abecedy. Potvrď tlačítkem Nastavit jako primární.' ?>">Primární</span><?php endif; ?></td>
                    <td><?= h($domain['url']) ?></td>
                    <td><?= (int) $domain['source_count'] ?></td>
                    <td><?= (int) $domain['is_active'] === 1 ? allstat_status_badge('ok') : allstat_status_badge('warning') ?></td>
                    <td>
                        <div class="table-actions">
                            <?php if ((int) $domain['is_active'] === 1 && (!$isPrimary || !$primaryIsExplicit)): ?>
                            <form method="post">
                                <?= allstat_csrf_field() ?>
                                <input type="hidden" name="action" value="set_primary">
                                <input type="hidden" name="id" value="<?= (int) $domain['id'] ?>">
                                <button class="button-secondary" type="submit" title="Tento web se otevře hned po přihlášení.">Nastavit jako primární</button>
                            </form>
                            <?php endif; ?>
                            <a class="button-secondary" href="domains.php?edit=<?= (int) $domain['id'] ?>">Upravit</a>
                            <a class="button-secondary" href="sources.php?domain_id=<?= (int) $domain['id'] ?>">Zdroje</a>
                            <form method="post" data-confirm="Smazat web včetně metrik a napojení?">
                                <?= allstat_csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $domain['id'] ?>">
                                <button class="button-danger" type="submit">Smazat</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php allstat_admin_footer($config); ?>
