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
            $result = ['ok' => true, 'message' => t('Web {name} je teď primární, otevře se hned po přihlášení.', ['name' => $primary['name']])];
        } else {
            $result = ['ok' => false, 'message' => t('Primárním webem může být jen aktivní web.')];
        }
    } else {
        $result = allstat_save_domain($pdo, (int) $user['id'], $_POST);
    }

    allstat_flash($result['ok'] ? 'ok' : 'error', t($result['message']));
    allstat_redirect($config, 'admin/domains.php');
}

if (isset($_GET['edit'])) {
    $editing = allstat_fetch_one($pdo, 'SELECT * FROM domains WHERE id = ?', [(int) $_GET['edit']]);
}

$domains = allstat_admin_domains($pdo);
$activeDomains = array_values(array_filter($domains, static fn (array $d): bool => (int) $d['is_active'] === 1));
$primaryDomainId = allstat_primary_domain_id($pdo, $activeDomains);
$primaryIsExplicit = (int) allstat_setting($pdo, 'app.primary_domain_id', '0') === $primaryDomainId && $primaryDomainId > 0;
allstat_admin_header(t('Weby'), 'domains', $user, $config, null, [
    'sub' => t('Web je základní jednotka pro GA4, GSC, Ads a social napojení.'),
    'actions' => '<a class="btn btn-secondary" href="google-accounts.php" title="' . h(t('Založit weby výběrem ze seznamu GA4 a Search Console Google účtu')) . '">' . allstat_icon('key-round', 'icon-sm') . '<span>' . h(t('Přidat weby z Google')) . '</span></a>',
]);
$hostOf = static fn (string $url): string => (string) preg_replace('~^(https?://)?(www\.)?~i', '', rtrim($url, '/'));
?>
<section class="card" aria-labelledby="domains-title">
    <div class="card-head">
        <div>
            <h2 class="card-title" id="domains-title"><?= h(t('Spravované weby')) ?> <small class="num"><?= count($domains) ?></small></h2>
            <p class="card-desc"><?= h(t('Primární web se otevře hned po přihlášení. Web zvolený v přehledu nebo ve filtrech se pak drží napříč celou administrací, dokud ho sám nepřepneš.')) ?><?php if (!$primaryIsExplicit && $primaryDomainId): ?> <?= h(t('Primární web zatím není nastaven, používá se web shodný s doménou, na které AllStat běží, jinak první podle abecedy.')) ?><?php endif; ?></p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="table table-data">
            <colgroup><col><col class="col-xl"><col class="col-sm"><col class="col-md"><col class="col-sm"></colgroup>
            <thead><tr><th scope="col"><?= h(t('Název')) ?></th><th scope="col"><?= h(t('Doména')) ?></th><th scope="col"><?= h(t('Zdroje')) ?></th><th scope="col"><?= h(t('Stav')) ?></th><th scope="col" data-no-sort><span class="sr-only"><?= h(t('Akce')) ?></span></th></tr></thead>
            <tbody>
            <?php foreach ($domains as $domain): $id = (int) $domain['id']; $isPrimary = $id === $primaryDomainId; $isActive = (int) $domain['is_active'] === 1; ?>
                <tr>
                    <td>
                        <div class="cell">
                            <span class="tile tile-md" aria-hidden="true"><?= h(allstat_site_initial($domain)) ?></span>
                            <div class="name-stack">
                                <span class="name-main ellipsis"><?= h($domain['name']) ?></span>
                                <?php if ($isPrimary): ?><span class="name-sub" title="<?= h($primaryIsExplicit ? t('Otevře se hned po přihlášení.') : t('Automaticky: shoduje se s doménou aplikace, nebo je první podle abecedy. Potvrď tlačítkem Nastavit jako primární.')) ?>"><?= h(t('Primární')) ?></span><?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td class="ellipsis"><?= h($hostOf((string) $domain['url'])) ?></td>
                    <td class="num"><a class="link-more" href="sources.php?domain_id=<?= $id ?>"><?= (int) $domain['source_count'] ?></a></td>
                    <td><?= $isActive ? allstat_status_pill('ok', t('Aktivní')) : allstat_status_badge('off') ?></td>
                    <td>
                        <div class="cell-actions dropdown">
                            <a class="btn-icon btn-sm" href="domains.php?edit=<?= $id ?>#domain-form" title="<?= h(t('Upravit')) ?>" aria-label="<?= h(t('Upravit')) ?>: <?= h($domain['name']) ?>"><?= allstat_icon('pencil', 'icon-sm') ?></a>
                            <button type="button" class="btn-icon btn-sm" aria-label="<?= h(t('Další akce pro {name}', ['name' => $domain['name']])) ?>" aria-haspopup="true" aria-expanded="false" data-menu-toggle="domain-menu-<?= $id ?>"><?= allstat_icon('ellipsis', 'icon-sm') ?></button>
                            <div class="menu menu-end" id="domain-menu-<?= $id ?>" hidden>
                                <a class="menu-item" href="sources.php?domain_id=<?= $id ?>"><?= allstat_icon('database', 'icon-sm') ?><span><?= h(t('Zdroje dat')) ?></span></a>
                                <?php if ($isActive && (!$isPrimary || !$primaryIsExplicit)): ?>
                                <form method="post">
                                    <?= allstat_csrf_field() ?>
                                    <input type="hidden" name="action" value="set_primary">
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <button class="menu-item" type="submit" title="<?= h(t('Tento web se otevře hned po přihlášení.')) ?>"><?= allstat_icon('star', 'icon-sm') ?><span><?= h(t('Nastavit jako primární')) ?></span></button>
                                </form>
                                <?php endif; ?>
                                <div class="menu-sep"></div>
                                <form method="post" data-confirm="<?= h(t('Smazat web včetně metrik a napojení?')) ?>">
                                    <?= allstat_csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <button class="menu-item is-danger" type="submit"><?= allstat_icon('trash-2', 'icon-sm') ?><span><?= h(t('Smazat')) ?></span></button>
                                </form>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card" id="domain-form" aria-labelledby="domain-form-title">
    <div class="card-head">
        <div>
            <h2 class="card-title" id="domain-form-title"><?= h($editing ? t('Upravit web') : t('Nový web')) ?></h2>
            <p class="card-desc"><?= h(t('Web je základní jednotka pro GA4, GSC, Ads a social napojení.')) ?></p>
        </div>
    </div>
    <form method="post" class="card-body form-grid">
        <?= allstat_csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
        <label class="field"><span class="field-label"><?= h(t('Název')) ?></span><input class="input" type="text" name="name" required value="<?= h($editing['name'] ?? '') ?>" placeholder="<?= h(t('Vaše doména')) ?>"></label>
        <label class="field"><span class="field-label"><?= h(t('Doména')) ?></span><input class="input" type="text" name="url" required value="<?= h($editing['url'] ?? '') ?>" placeholder="example.cz"></label>
        <label class="field"><span class="field-label"><?= h(t('Stav')) ?></span><select class="input" name="is_active"><option value="1" <?= (int) ($editing['is_active'] ?? 1) === 1 ? 'selected' : '' ?>><?= h(t('Aktivní')) ?></option><option value="0" <?= isset($editing['is_active']) && (int) $editing['is_active'] === 0 ? 'selected' : '' ?>><?= h(t('Vypnuto')) ?></option></select></label>
        <div class="form-actions field-full">
            <button class="btn btn-primary" type="submit"><?= h(t('Uložit web')) ?></button>
            <?php if ($editing): ?><a class="btn btn-secondary" href="domains.php"><?= h(t('Zrušit úpravu')) ?></a><?php endif; ?>
        </div>
    </form>
</section>
<?php allstat_admin_footer($config); ?>
