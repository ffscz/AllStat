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
$currentDomain = null;
foreach ($domains as $domain) {
    if ((int) $domain['id'] === $domainId) {
        $currentDomain = $domain;
    }
}
$connections = allstat_source_connections($pdo, $domainId);
$categories = ['analytics' => t('Analytika'), 'seo' => t('Vyhledávání'), 'social' => t('Sociální sítě'), 'ppc' => t('Reklama')];
// Katalog ve stejném pořadí kategorií jako skupiny v tabulce.
$sources = allstat_data_sources($pdo);
$categoryOrder = array_flip(array_keys($categories));
usort($sources, static fn (array $a, array $b): int => [$categoryOrder[$a['category']] ?? 9, $a['name']] <=> [$categoryOrder[$b['category']] ?? 9, $b['name']]);
$metaFamily = ['facebook_pages', 'instagram_business', 'meta_ads'];
$hostOf = static fn (string $url): string => (string) preg_replace('~^(https?://)?(www\.)?~i', '', rtrim($url, '/'));

// Jeden web = skupiny podle kategorie služby (jako v návrhu), všechny weby = skupiny podle webu.
$groups = [];
$attention = 0;
$perProvider = [];
foreach ($connections as $connection) {
    $key = $domainId ? (string) $connection['category'] : (string) $connection['domain_url'];
    $groups[$key][] = $connection;
    $isAttention = (int) ($connection['is_enabled'] ?? 1) === 1 && in_array($connection['status'], ['warning', 'error'], true);
    $attention += $isAttention ? 1 : 0;
    $perProvider[$connection['provider_key']] = ($perProvider[$connection['provider_key']] ?? 0) + 1;
}
if ($domainId) {
    $groups = array_replace(array_intersect_key(array_fill_keys(array_keys($categories), null), $groups), $groups);
}
$groupLabel = static fn (string $key): string => $domainId ? ($categories[$key] ?? $key) : $hostOf($key);

$actions = '<a class="btn btn-secondary" href="logs.php">' . allstat_icon('history', 'icon-sm') . '<span>' . h(t('Historie synchronizací')) . '</span></a>';
if ($connections) {
    $actions .= '<button class="btn btn-secondary" type="button" data-sync-all data-csrf="' . h(allstat_csrf_token()) . '" title="' . h(t('Postupně stáhne aktuální data (posledních 7 dní) pro všechna napojení níže, náhrada za cron.')) . '">'
        . allstat_icon('refresh-cw', 'icon-sm') . '<span>' . h(t('Synchronizovat vše')) . '</span></button>';
}
$actions .= '<a class="btn btn-primary" href="#katalog">' . allstat_icon('plus', 'icon-sm') . '<span>' . h(t('Přidat zdroj')) . '</span></a>';

allstat_admin_header(t('Zdroje dat'), 'sources', $user, $config, null, [
    'sub' => $currentDomain
        ? t('Služby napojené na web {web}. Data se stahují každou noc.', ['web' => $hostOf((string) $currentDomain['url'])])
        : t('Služby napojené na všechny weby. Data se stahují každou noc.'),
    'actions' => $actions,
]);
?>
<div class="callout" data-sync-all-result role="status" hidden></div>

<section class="card" aria-label="<?= h(t('Napojené služby')) ?>">
    <div class="filterbar">
        <div class="seg" role="group" aria-label="<?= h(t('Filtr podle stavu')) ?>" data-status-filter-for="sources-table">
            <button type="button" class="seg-item" aria-pressed="true" data-status-filter="all"><span><?= h(t('Vše')) ?></span><span class="seg-count num"><?= count($connections) ?></span></button>
            <button type="button" class="seg-item" aria-pressed="false" data-status-filter="ok"><span><?= h(t('V pořádku')) ?></span><span class="seg-count num"><?= count($connections) - $attention ?></span></button>
            <button type="button" class="seg-item" aria-pressed="false" data-status-filter="attn"><span><?= h(t('Vyžaduje pozornost')) ?></span><span class="seg-count num"><?= $attention ?></span></button>
        </div>
        <form method="get">
            <label class="sr-only" for="sources-domain"><?= h(t('Web')) ?></label>
            <select class="input" id="sources-domain" name="domain_id" data-autosubmit>
                <option value=""><?= h(t('Všechny weby')) ?></option>
                <?php foreach ($domains as $domain): ?><option value="<?= (int) $domain['id'] ?>" <?= $domainId === (int) $domain['id'] ? 'selected' : '' ?>><?= h($hostOf((string) $domain['url'])) ?></option><?php endforeach; ?>
            </select>
        </form>
        <label class="search">
            <span class="sr-only"><?= h(t('Hledat zdroj')) ?></span>
            <?= allstat_icon('search', 'icon-sm') ?>
            <input class="input" type="search" placeholder="<?= h(t('Hledat službu, účet nebo ID')) ?>" data-table-filter="sources-table">
        </label>
    </div>

    <?php if ($connections): ?>
    <div class="table-wrap">
        <table class="table table-data" id="sources-table" data-csrf="<?= h(allstat_csrf_token()) ?>">
            <colgroup><col class="col-xl"><col><col class="col-md"><col class="col-md"><col class="col-lg"><col class="col-sm"></colgroup>
            <thead><tr><th scope="col"><?= h(t('Služba')) ?></th><th scope="col"><?= h(t('Účet a ID')) ?></th><th scope="col"><?= h(t('Přístup')) ?></th><th scope="col"><?= h(t('Stav')) ?></th><th scope="col"><?= h(t('Synchronizace')) ?></th><th scope="col" data-no-sort><span class="sr-only"><?= h(t('Akce')) ?></span></th></tr></thead>
            <tbody>
            <?php foreach ($groups as $groupKey => $rows): ?>
                <tr class="table-group" data-group="<?= h((string) $groupKey) ?>"><td colspan="6"><?= h($groupLabel((string) $groupKey)) ?> · <span data-group-count><?= count($rows) ?></span></td></tr>
                <?php foreach ($rows as $connection):
                    $id = (int) $connection['id'];
                    $account = (string) ($connection['account_label'] ?: '-');
                    $externalId = (string) ($connection['property_id'] ?: $connection['external_account_id'] ?: '');
                    $label = $connection['source_name'] . ' – ' . $account;
                    $isMeta = in_array($connection['provider_key'], $metaFamily, true);
                    $status = (int) ($connection['is_enabled'] ?? 1) === 1 ? (string) $connection['status'] : 'off';
                    $syncSub = !empty($connection['oauth_account_id'])
                        ? t('token z Google účtu')
                        : ($connection['token_expires_at'] ? t('token do {date}', ['date' => allstat_iso_to_cz($connection['token_expires_at'])]) : t('automaticky, každou noc'));
                ?>
                <tr data-conn-id="<?= $id ?>" data-group="<?= h((string) $groupKey) ?>" data-status="<?= in_array($status, ['warning', 'error'], true) ? 'attn' : 'ok' ?>"
                    data-conn-label="<?= h($connection['domain_url'] . ' · ' . $connection['source_name'] . ($connection['account_label'] ? ' (' . $connection['account_label'] . ')' : '')) ?>"
                    data-filter-text="<?= h(mb_strtolower($connection['source_name'] . ' ' . $account . ' ' . $externalId . ' ' . $connection['domain_url'])) ?>">
                    <td>
                        <div class="cell">
                            <?= allstat_source_tile((string) $connection['provider_key'], 'md') ?>
                            <div class="name-stack"><span class="name-main ellipsis"><?= h($connection['source_name']) ?></span><span class="name-sub ellipsis"><?= h($domainId ? ($categories[$connection['category']] ?? $connection['category']) : $hostOf((string) $connection['domain_url'])) ?></span></div>
                        </div>
                    </td>
                    <td>
                        <div class="name-stack"><span class="ellipsis" title="<?= h($account) ?>"><?= h($account) ?></span><span class="name-sub mono ellipsis" title="<?= h($externalId) ?>"><?= h($externalId) ?></span></div>
                    </td>
                    <td class="is-flat"><?= h(allstat_connection_access($connection)) ?></td>
                    <td><?= allstat_status_badge($status) ?></td>
                    <td>
                        <div class="name-stack"><span class="num"><?= $connection['last_sync_at'] ? h(allstat_iso_to_cz($connection['last_sync_at'])) : h(t('nikdy')) ?></span><span class="name-sub ellipsis"><?= h($syncSub) ?></span></div>
                    </td>
                    <td>
                        <div class="cell-actions dropdown">
                            <button type="button" class="btn-icon btn-sm" data-row-sync="<?= $id ?>" title="<?= h(t('Synchronizovat teď')) ?>" aria-label="<?= h(t('Synchronizovat {name}', ['name' => $label])) ?>"><?= allstat_icon('refresh-cw', 'icon-sm') ?></button>
                            <button type="button" class="btn-icon btn-sm" aria-label="<?= h(t('Další akce pro {name}', ['name' => $label])) ?>" aria-haspopup="true" aria-expanded="false" data-menu-toggle="conn-menu-<?= $id ?>"><?= allstat_icon('ellipsis', 'icon-sm') ?></button>
                            <div class="menu menu-end" id="conn-menu-<?= $id ?>" hidden>
                                <a class="menu-item" href="source-edit.php?id=<?= $id ?>"><?= allstat_icon('pencil', 'icon-sm') ?><span><?= h(t('Upravit napojení')) ?></span></a>
                                <?php if ($isMeta): ?>
                                <a class="menu-item" href="meta-connect.php"><?= allstat_icon('key-round', 'icon-sm') ?><span><?= h(t('Znovu ověřit přístup')) ?></span></a>
                                <?php elseif (!empty($connection['supports_oauth']) && empty($connection['oauth_account_id'])): ?>
                                <a class="menu-item" href="oauth-start.php?connection_id=<?= $id ?>"><?= allstat_icon('key-round', 'icon-sm') ?><span><?= h(t('Znovu ověřit přístup')) ?></span></a>
                                <?php endif; ?>
                                <a class="menu-item" href="source-edit.php?id=<?= $id ?>#sync"><?= allstat_icon('history', 'icon-sm') ?><span><?= h(t('Stáhnout historii')) ?></span></a>
                                <div class="menu-sep"></div>
                                <form method="post" data-confirm="<?= h(t('Odstranit napojení?')) ?>">
                                    <?= allstat_csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <button class="menu-item is-danger" type="submit"><?= allstat_icon('unplug', 'icon-sm') ?><span><?= h(t('Odpojit zdroj')) ?></span></button>
                                </form>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <div class="empty" data-filter-empty <?= $connections ? 'hidden' : '' ?>>
        <span class="empty-icon tone-flat"><?= allstat_icon('search', 'icon') ?></span>
        <span class="empty-title"><?= h($connections ? t('Nic jsme nenašli') : t('Zatím tu není žádné napojení.')) ?></span>
        <span class="empty-desc"><?= h($connections ? t('Žádný zdroj neodpovídá filtru. Zkuste název služby, účtu nebo jeho ID.') : t('Přidejte první službu v katalogu níže.')) ?></span>
    </div>
</section>

<section class="section" id="katalog" aria-labelledby="catalog-title">
    <div class="section-head">
        <h2 class="h2" id="catalog-title"><?= h(t('Přidat službu')) ?></h2>
        <span class="section-desc"><?= h(t('Analytika, vyhledávání, sociální sítě a reklama. Účty Meta se připojují jedním přihlášením.')) ?></span>
        <a class="btn btn-ghost btn-sm" href="google-accounts.php" title="<?= h(t('Připojit weby z GA4 a Search Console výběrem ze seznamu Google účtu')) ?>"><?= allstat_icon('key-round', 'icon-sm') ?><span><?= h(t('Import z Google')) ?></span></a>
    </div>
    <div class="grid grid-tiles">
        <?php foreach ($sources as $source):
            $key = (string) $source['provider_key'];
            $count = $perProvider[$key] ?? 0;
            $isMeta = in_array($key, $metaFamily, true);
            $access = $isMeta ? t('OAuth přes Meta') : (!empty($source['supports_oauth']) ? 'OAuth' : t('API klíč'));
            $href = $isMeta ? 'meta-connect.php' : 'source-edit.php?source_id=' . (int) $source['id'] . ($domainId ? '&domain_id=' . (int) $domainId : '');
        ?>
        <div class="card service">
            <div class="service-head">
                <?= allstat_source_tile($key) ?>
                <div class="name-stack"><span class="name-main"><?= h($source['name']) ?></span><span class="name-sub"><?= h(($categories[$source['category']] ?? $source['category']) . ' · ' . $access) ?></span></div>
            </div>
            <div class="service-foot">
                <span class="service-state<?= $count ? ' is-on' : '' ?>"><?= h($count > 1 ? t('Napojeno {count}×', ['count' => $count]) : ($count ? t('Napojeno') : t('Nenapojeno'))) ?></span>
                <a class="btn btn-secondary btn-sm" href="<?= h($href) ?>"><?= h($count ? t('Přidat další') : t('Připojit')) ?></a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php allstat_admin_footer($config); ?>
