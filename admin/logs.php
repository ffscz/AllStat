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
    allstat_flash('ok', 'Log záznam byl uložen.');
    allstat_redirect($config, 'admin/logs.php');
}

$domains = allstat_admin_domains($pdo, false);
$currentDomainId = allstat_current_domain_id($pdo, $domains);
$sources = allstat_data_sources($pdo);
$logs = allstat_sync_logs($pdo, 150);
allstat_admin_header('Sync log', 'sources', $user, $config);
?>
<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2>Sync log</h2>
            <p>Historie všech automatických i manuálních synchronizací zdrojů (test napojení, sync teď, cron sync). Filtrovat zatím nelze; nejnovější nahoře.</p>
        </div>
        <a class="button-secondary" href="sources.php">← Zpět na Zdroje dat</a>
    </div>
</div>

<details class="admin-card admin-card-collapsible">
    <summary class="admin-card-header">
        <div>
            <h2>Pokročilé: ručně přidat sync log</h2>
            <p>Pro debugging. Běžně se logy generují samy.</p>
        </div>
    </summary>
    <div class="admin-card-body">
        <form method="post" class="form-grid form-grid-3">
            <?= allstat_csrf_field() ?>
            <label><span>Web</span><select name="domain_id" required><?php foreach ($domains as $domain): ?><option value="<?= (int) $domain['id'] ?>"<?= (int) $domain['id'] === $currentDomainId ? ' selected' : '' ?>><?= h($domain['url']) ?></option><?php endforeach; ?></select></label>
            <label><span>Zdroj</span><select name="source_id" required><?php foreach ($sources as $source): ?><option value="<?= (int) $source['id'] ?>"><?= h($source['name']) ?></option><?php endforeach; ?></select></label>
            <label><span>Úroveň</span><select name="level"><option value="info">Info</option><option value="warning">Varování</option><option value="error">Chyba</option></select></label>
            <label style="grid-column:1 / -1"><span>Zpráva</span><input type="text" name="message" required></label>
            <div class="form-actions"><button class="button-primary" type="submit">Přidat log</button></div>
        </form>
    </div>
</details>

<div class="admin-card">
    <div class="admin-card-header"><h2>Poslední synchronizace</h2></div>
    <div class="admin-card-body table-scroll">
        <table class="admin-table">
            <thead><tr><th>Čas</th><th>Web</th><th>Zdroj</th><th>Úroveň</th><th>Zpráva</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <tr><td><?= h(allstat_iso_to_cz($log['created_at'])) ?></td><td><?= h($log['domain_url']) ?></td><td><?= h($log['source_name']) ?></td><td><?= allstat_status_badge($log['level']) ?></td><td><?= h($log['message']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$logs): ?><tr><td colspan="5" class="table-muted">Zatím žádné sync logy.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php allstat_admin_footer($config); ?>
