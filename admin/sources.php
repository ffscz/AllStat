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
    $result = allstat_delete_connection($pdo, (int) $user['id'], (int) ($_POST['id'] ?? 0));
    allstat_flash($result['ok'] ? 'ok' : 'error', $result['message']);
    allstat_redirect($config, 'admin/sources.php');
}

$domains = allstat_admin_domains($pdo, false);
// Bez parametru se otevře web zvolený jinde v administraci; „Všechny weby" posílá prázdný domain_id.
$domainId = array_key_exists('domain_id', $_GET)
    ? (filter_input(INPUT_GET, 'domain_id', FILTER_VALIDATE_INT) ?: null)
    : (allstat_current_domain_id($pdo, $domains) ?: null);
if ($domainId) {
    allstat_remember_domain_id($domainId);
}
$connections = allstat_source_connections($pdo, $domainId);
$sources = allstat_data_sources($pdo);
$statusCounts = ['ok' => 0, 'warning' => 0, 'error' => 0];
foreach ($connections as $connection) {
    $statusCounts[$connection['status']] = ($statusCounts[$connection['status']] ?? 0) + 1;
}

allstat_admin_header('Zdroje dat', 'sources', $user, $config);
?>
<div class="admin-stats">
    <div class="stat-tile"><span>Napojení</span><strong><?= count($connections) ?></strong></div>
    <div class="stat-tile"><span>OK</span><strong><?= (int) $statusCounts['ok'] ?></strong></div>
    <div class="stat-tile"><span>Varování</span><strong><?= (int) $statusCounts['warning'] ?></strong></div>
    <div class="stat-tile"><span>Chyby</span><strong><?= (int) $statusCounts['error'] ?></strong></div>
</div>

<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2>Napojené služby</h2>
            <p>Každý řádek je konkrétní napojení služby na web, včetně tokenů a provider konfigurace.</p>
        </div>
        <div class="card-header-actions">
            <form method="get" class="toolbar">
                <label class="filter-control"><i data-lucide="globe-2"></i><select name="domain_id" data-autosubmit><option value="">Všechny weby</option><?php foreach ($domains as $domain): ?><option value="<?= (int) $domain['id'] ?>" <?= $domainId === (int) $domain['id'] ? 'selected' : '' ?>><?= h($domain['url']) ?></option><?php endforeach; ?></select></label>
            </form>
            <?php if ($connections): ?>
                <button class="button-primary" type="button" data-sync-all data-csrf="<?= h(allstat_csrf_token()) ?>" title="Postupně stáhne aktuální data (posledních 7 dní) pro všechna napojení níže, náhrada za cron."><i data-lucide="refresh-cw"></i> Synchronizovat vše (7 dní)</button>
            <?php endif; ?>
            <a class="button-secondary" href="logs.php"><i data-lucide="list"></i> Sync log</a>
        </div>
    </div>
    <div class="connection-result sync-all-result" data-sync-all-result hidden></div>
    <div class="admin-card-body table-scroll">
        <table class="admin-table">
            <thead><tr><th>Web</th><th>Služba</th><th>Účet/property</th><th>Stav</th><th>Token</th><th>Sync</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($connections as $connection): ?>
                <tr data-conn-id="<?= (int) $connection['id'] ?>" data-conn-label="<?= h($connection['domain_url'] . ' · ' . $connection['source_name'] . ($connection['account_label'] ? ' (' . $connection['account_label'] . ')' : '')) ?>">
                    <td><strong><?= h($connection['domain_url']) ?></strong></td>
                    <td>
                        <div class="svc-cell">
                            <span class="source-picker-ic"><?= allstat_provider_icon_svg((string) $connection['provider_key']) ?></span>
                            <div><?= h($connection['source_name']) ?><div class="table-muted"><?= h(allstat_provider_categories()[$connection['category']] ?? $connection['category']) ?></div></div>
                        </div>
                    </td>
                    <td><?= h($connection['account_label'] ?: '-') ?><div class="table-muted"><?= h($connection['property_id'] ?: $connection['external_account_id'] ?: '') ?></div></td>
                    <td><?= allstat_status_badge($connection['status']) ?></td>
                    <td><?= $connection['token_expires_at'] ? h(allstat_iso_to_cz($connection['token_expires_at'])) : '<span class="table-muted">není nastaven</span>' ?></td>
                    <td><?= $connection['last_sync_at'] ? h(allstat_iso_to_cz($connection['last_sync_at'])) : '<span class="table-muted">nikdy</span>' ?></td>
                    <td>
                        <div class="table-actions">
                            <a class="button-secondary" href="source-edit.php?id=<?= (int) $connection['id'] ?>">Upravit</a>
                            <?php if (!empty($connection['supports_oauth'])): ?><a class="button-secondary" href="oauth-start.php?connection_id=<?= (int) $connection['id'] ?>">OAuth</a><?php endif; ?>
                            <form method="post" data-confirm="Odstranit napojení?">
                                <?= allstat_csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $connection['id'] ?>">
                                <button class="button-danger" type="submit">Smazat</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$connections): ?><tr><td colspan="7" class="table-muted">Zatím tu není žádné napojení.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="admin-card">
    <div class="admin-card-header"><h2>Přidat službu</h2></div>
    <div class="admin-card-body table-scroll">
        <table class="admin-table">
            <thead><tr><th>Služba</th><th>Kategorie</th><th>Typ</th><th>Dokumentace</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($sources as $source): ?>
                <tr>
                    <td>
                        <div class="svc-cell">
                            <span class="source-picker-ic"><?= allstat_provider_icon_svg((string) $source['provider_key']) ?></span>
                            <strong><?= h($source['name']) ?></strong>
                        </div>
                    </td>
                    <td><?= h(allstat_provider_categories()[$source['category']] ?? $source['category']) ?></td>
                    <td><?= !empty($source['supports_oauth']) ? 'OAuth' : 'API klíč' ?></td>
                    <td><?= $source['docs_url'] ? '<a class="text-link" href="' . h($source['docs_url']) . '" target="_blank" rel="noreferrer">Dokumentace <i data-lucide="external-link"></i></a>' : '<span class="table-muted">-</span>' ?></td>
                    <?php $isMetaFam = in_array($source['provider_key'] ?? '', ['facebook_pages', 'instagram_business', 'meta_ads'], true); ?>
                    <td><?php if ($isMetaFam): ?><a class="button-primary" href="meta-connect.php"><?= allstat_meta_button_icon() ?> Připojit přes Meta</a><?php else: ?><a class="button-primary" href="source-edit.php?source_id=<?= (int) $source['id'] ?><?= $domainId ? '&domain_id=' . (int) $domainId : '' ?>">Přidat</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php allstat_admin_footer($config); ?>
