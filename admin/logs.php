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
    allstat_add_sync_log($pdo, (int) ($_POST['domain_id'] ?? 0), (int) ($_POST['source_id'] ?? 0), (string) ($_POST['level'] ?? 'info'), trim((string) ($_POST['message'] ?? '')) ?: 'Ručně vložený záznam.');
    allstat_flash('ok', t('Log záznam byl uložen.'));
    allstat_redirect($config, 'admin/logs.php');
}

$domains = allstat_admin_domains($pdo, false);
$currentDomainId = allstat_current_domain_id($pdo, $domains);
$sources = allstat_data_sources($pdo);
$logs = allstat_sync_logs($pdo, 150);
allstat_admin_header(t('Sync log'), 'sources', $user, $config, 'problemy', [
    'sub' => t('Historie všech automatických i manuálních synchronizací zdrojů (test napojení, sync teď, cron sync). Filtrovat zatím nelze; nejnovější nahoře.'),
    'actions' => '<a class="btn btn-secondary" href="sources.php"><span>' . h(t('← Zpět na Zdroje dat')) . '</span></a>',
]);
?>
<details class="card note">
    <summary><?= allstat_icon('plus', 'icon') ?><span><?= h(t('Pokročilé: ručně přidat sync log')) ?></span><?= allstat_icon('chevron-down', 'note-chev') ?></summary>
    <div class="note-body">
        <p><?= h(t('Pro debugging. Běžně se logy generují samy.')) ?></p>
        <form method="post" class="form-grid">
            <?= allstat_csrf_field() ?>
            <label class="field"><span class="field-label"><?= h(t('Web')) ?></span><select class="input" name="domain_id" required><?php foreach ($domains as $domain): ?><option value="<?= (int) $domain['id'] ?>"<?= (int) $domain['id'] === $currentDomainId ? ' selected' : '' ?>><?= h($domain['url']) ?></option><?php endforeach; ?></select></label>
            <label class="field"><span class="field-label"><?= h(t('Zdroj')) ?></span><select class="input" name="source_id" required><?php foreach ($sources as $source): ?><option value="<?= (int) $source['id'] ?>"><?= h($source['name']) ?></option><?php endforeach; ?></select></label>
            <label class="field"><span class="field-label"><?= h(t('Úroveň')) ?></span><select class="input" name="level"><option value="info">Info</option><option value="warning"><?= h(t('Varování')) ?></option><option value="error"><?= h(t('Chyba')) ?></option></select></label>
            <label class="field field-full"><span class="field-label"><?= h(t('Zpráva')) ?></span><input class="input" type="text" name="message" required></label>
            <div class="form-actions field-full"><button class="btn btn-primary" type="submit"><?= h(t('Přidat log')) ?></button></div>
        </form>
    </div>
</details>

<section class="card">
    <div class="card-head">
        <div>
            <h2 class="card-title"><?= h(t('Poslední synchronizace')) ?></h2>
        </div>
    </div>
    <?php if ($logs): ?>
    <div class="table-wrap">
        <table class="table table-data">
            <colgroup><col class="col-md"><col class="col-lg"><col class="col-lg"><col><col class="col-md"></colgroup>
            <thead><tr><th scope="col"><?= h(t('Čas')) ?></th><th scope="col"><?= h(t('Web')) ?></th><th scope="col"><?= h(t('Zdroj')) ?></th><th scope="col"><?= h(t('Zpráva')) ?></th><th scope="col"><?= h(t('Úroveň')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <tr><td class="num"><?= h(allstat_iso_to_cz($log['created_at'])) ?></td><td class="ellipsis" title="<?= h($log['domain_url']) ?>"><?= h($log['domain_url']) ?></td><td class="ellipsis" title="<?= h($log['source_name']) ?>"><?= h($log['source_name']) ?></td><td class="is-wrap"><?= h($log['message']) ?></td><td><?= allstat_status_badge($log['level']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty">
        <span class="empty-desc"><?= h(t('Zatím žádné sync logy.')) ?></span>
    </div>
    <?php endif; ?>
</section>
<?php allstat_admin_footer($config); ?>
