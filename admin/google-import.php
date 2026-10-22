<?php

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../lib/google-accounts.php';

$pdo = allstat_admin_require_database($pdo, $config);
$user = allstat_require_user($pdo, $config);
allstat_require_admin($user);

$accountId = (int) ($_POST['account_id'] ?? (filter_input(INPUT_GET, 'account_id', FILTER_VALIDATE_INT) ?: 0));
$account = allstat_google_account($pdo, $accountId);
if (!$account) {
    allstat_flash('error', t('Google účet neexistuje.'));
    allstat_redirect($config, 'admin/google-accounts.php');
}
$self = 'admin/google-import.php?account_id=' . (int) $account['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    allstat_csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'refresh') {
        $result = allstat_google_discover($pdo, $config, (int) $account['id']);
        if (!$result['ok']) {
            allstat_flash('error', (string) $result['message']);
        }
    } elseif ($action === 'import') {
        $result = allstat_google_import($pdo, $config, $account, array_values((array) ($_POST['rows'] ?? [])), array_map('strval', (array) ($_POST['gsc'] ?? [])), (int) $user['id']);
        if ($result['ok']) {
            $_SESSION['google_import_result'] = ['account_id' => (int) $account['id']] + $result;
        } else {
            allstat_flash('error', (string) $result['message']);
        }
    }
    allstat_redirect($config, $self);
}

// Při první návštěvě (třeba hned po přidání účtu) se seznam načte sám, později jen tlačítkem.
$discovery = allstat_google_cached_discovery($account);
if ($discovery === null) {
    $result = allstat_google_discover($pdo, $config, (int) $account['id']);
    if ($result['ok']) {
        $discovery = $result['discovery'];
        $account = allstat_google_account($pdo, (int) $account['id']) ?? $account;
    } else {
        allstat_flash('error', (string) $result['message']);
    }
}
$rows = $discovery !== null ? allstat_google_import_rows($pdo, $discovery) : [];

$importResult = null;
if (($_SESSION['google_import_result']['account_id'] ?? 0) === (int) $account['id']) {
    $importResult = $_SESSION['google_import_result'];
    unset($_SESSION['google_import_result']);
}

allstat_admin_header(t('Vybrat weby z Google'), 'google', $user, $config);
?>
<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2><?= h(t('Weby z účtu „{label}“', ['label' => $account['label']])) ?></h2>
            <p><?= $account['account_email'] ? h($account['account_email']) . '. ' : '' ?><?= h(t('Seznam GA4 property a webů ze Search Console{loaded}. GA4 a Search Console se párují podle domény webu.', ['loaded' => $account['discovered_at'] ? t(', načtený {date}', ['date' => allstat_date_label($account['discovered_at'], true)]) : ''])) ?></p>
        </div>
        <div class="card-header-actions">
            <form method="post">
                <?= allstat_csrf_field() ?>
                <input type="hidden" name="action" value="refresh">
                <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                <button class="button-secondary" type="submit"><i data-lucide="refresh-cw"></i> <?= h(t('Načíst znovu')) ?></button>
            </form>
            <a class="button-secondary" href="google-accounts.php"><?= h(t('Google účty')) ?></a>
        </div>
    </div>

    <?php if ($importResult): ?>
    <div class="admin-card-body">
        <div class="notice notice-ok"><?= h((string) $importResult['message']) ?></div>
        <?php foreach ((array) $importResult['skipped'] as $skip): ?>
            <div class="notice notice-warn"><?= h((string) $skip) ?></div>
        <?php endforeach; ?>
        <?php if (!empty($importResult['connection_ids'])): ?>
        <div class="google-backfill">
            <p class="form-help"><?= h(t('Teď stáhněte data. Historie 16 měsíců se stahuje po měsících a u víc webů to může trvat několik minut, stránku během stahování nezavírejte. Denní aktualizaci pak dělá cron.')) ?></p>
            <button type="button" class="button-primary" data-bulk-backfill="<?= h(json_encode(array_values(array_map('intval', $importResult['connection_ids'])))) ?>" data-csrf="<?= h(allstat_csrf_token()) ?>"><?= h(t('Stáhnout historii pro {count} napojení', ['count' => count($importResult['connection_ids'])])) ?></button>
            <div class="connection-result" data-bulk-backfill-status hidden></div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($discovery !== null): ?>
    <div class="admin-card-body">
        <?php foreach ((array) $discovery['errors'] as $error): ?>
            <div class="notice notice-warn"><?= h((string) $error) ?></div>
        <?php endforeach; ?>

        <?php if (!$rows): ?>
            <p class="table-muted"><?= h(t('Účet nemá přístup k žádné GA4 property ani k ověřenému webu v Search Console.')) ?></p>
        <?php else: ?>
        <form method="post" class="form-stack">
            <?= allstat_csrf_field() ?>
            <input type="hidden" name="action" value="import">
            <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
            <div class="toolbar">
                <label class="filter-control"><span><?= h(t('Hledat')) ?></span><input type="search" data-table-filter="google-import-table" placeholder="<?= h(t('doména, property')) ?>"></label>
            </div>
            <div class="table-scroll">
                <table class="admin-table" id="google-import-table">
                    <thead><tr>
                        <th><input type="checkbox" data-check-all="rows[]" aria-label="<?= h(t('Vybrat vše')) ?>"></th>
                        <th><?= h(t('Web')) ?></th><th>Google Analytics 4</th><th>Search Console</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row):
                        $ga4 = $row['ga4'];
                        $done = ($ga4 === null || $row['ga4_connected']) && $row['gsc_default'] === '';
                        $unusable = $row['host'] === '' && !$row['domain'];
                        $search = mb_strtolower($row['host'] . ' ' . ($ga4['name'] ?? '') . ' ' . ($ga4['account_name'] ?? '') . ' ' . implode(' ', array_column($row['gsc_options'], 'site')));
                    ?>
                        <tr data-filter-text="<?= h($search) ?>">
                            <td><input type="checkbox" name="rows[]" value="<?= h($row['key']) ?>"<?= $done || $unusable ? '' : ' checked' ?><?= $unusable ? ' disabled' : '' ?> aria-label="<?= h(t('Připojit {name}', ['name' => $row['host'] ?: ($ga4['name'] ?? '')])) ?>"></td>
                            <td>
                                <?php if ($row['host'] !== ''): ?><strong><?= h($row['host']) ?></strong><?php else: ?><span class="table-muted"><?= h(t('bez adresy')) ?></span><?php endif; ?><br>
                                <small class="table-muted"><?= $row['domain'] ? t('web v AllStatu: {name}', ['name' => h($row['domain']['name'])]) : h($row['host'] !== '' ? t('založí se nový web') : t('GA4 property nemá webový stream, web přidejte ručně')) ?></small>
                            </td>
                            <td>
                                <?php if ($ga4): ?>
                                    <?= h($ga4['name']) ?><br>
                                    <small class="table-muted"><?= h(($ga4['account_name'] !== '' ? $ga4['account_name'] . ', ' : '') . $ga4['property']) ?></small>
                                    <?php if ($row['ga4_connected']): ?><br><span class="status-badge status-ok"><?= $row['domain'] && allstat_normalize_host((string) $row['domain']['url']) !== $row['host'] ? t('už připojeno u webu {name}', ['name' => h($row['domain']['name'])]) : h(t('už připojeno')) ?></span><?php endif; ?>
                                <?php else: ?>
                                    <span class="table-muted"><?= h(t('bez GA4')) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['gsc_options']): ?>
                                <select name="gsc[<?= h($row['key']) ?>]" aria-label="<?= h(t('Search Console pro {host}', ['host' => $row['host']])) ?>">
                                    <?php foreach ($row['gsc_options'] as $i => $site): ?>
                                    <option value="<?= h(allstat_google_gsc_key((string) $site['site'])) ?>"<?= $site['site'] === $row['gsc_default'] ? ' selected' : '' ?>><?= h($site['site']) ?><?= $row['gsc_connected'][$i] ? ' ' . h(t('(už připojeno)')) : '' ?></option>
                                    <?php endforeach; ?>
                                    <option value=""<?= $row['gsc_default'] === '' ? ' selected' : '' ?>><?= h(t('nepřipojovat')) ?></option>
                                </select>
                                <?php if (in_array(true, $row['gsc_connected'], true)): ?><br><span class="status-badge status-ok"><?= h(t('už připojeno')) ?></span><?php endif; ?>
                                <?php else: ?>
                                    <span class="table-muted"><?= h(t('web v Search Console nenalezen')) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="form-help"><?= h(t('Nové weby dostanou název podle domény, přejmenujete je ve Webech. Property, která už je v AllStatu napojená (i u jiného webu), se nezaloží podruhé, jen se přepne na tento účet a data zůstanou.')) ?></p>
            <div class="form-actions"><button class="button-primary" type="submit"><?= h(t('Připojit vybrané')) ?></button></div>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php allstat_admin_footer($config); ?>
