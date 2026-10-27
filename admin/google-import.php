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

$refreshForm = '<form method="post">' . allstat_csrf_field()
    . '<input type="hidden" name="action" value="refresh"><input type="hidden" name="account_id" value="' . (int) $account['id'] . '">'
    . '<button class="btn btn-secondary" type="submit">' . allstat_icon('refresh-cw', 'icon-sm') . '<span>' . h(t('Načíst znovu')) . '</span></button></form>';
$actions = $refreshForm . '<a class="btn btn-secondary" href="google-accounts.php"><span>' . h(t('Google účty')) . '</span></a>';

allstat_admin_header(t('Vybrat weby z Google'), 'google', $user, $config, null, [
    'sub' => t('Weby z účtu „{label}“', ['label' => $account['label']]) . '. ' . ($account['account_email'] ? $account['account_email'] . '. ' : '')
        . t('Seznam GA4 property a webů ze Search Console{loaded}. GA4 a Search Console se párují podle domény webu.', ['loaded' => $account['discovered_at'] ? t(', načtený {date}', ['date' => allstat_date_label($account['discovered_at'], true)]) : '']),
    'actions' => $actions,
]);
?>
<?php if ($importResult): ?>
<section class="section">
    <div class="callout tone-up"><?= allstat_icon('check', 'icon-sm') ?><div><?= h((string) $importResult['message']) ?></div></div>
    <?php foreach ((array) $importResult['skipped'] as $skip): ?>
        <div class="callout tone-warn"><?= allstat_icon('info', 'icon-sm') ?><div><?= h((string) $skip) ?></div></div>
    <?php endforeach; ?>
    <?php if (!empty($importResult['connection_ids'])): ?>
    <div class="card">
        <div class="card-body section">
            <p class="field-help"><?= h(t('Teď stáhněte data. Historie 16 měsíců se stahuje po měsících a u víc webů to může trvat několik minut, stránku během stahování nezavírejte. Denní aktualizaci pak dělá cron.')) ?></p>
            <div class="form-actions">
                <button type="button" class="btn btn-primary" data-bulk-backfill="<?= h(json_encode(array_values(array_map('intval', $importResult['connection_ids'])))) ?>" data-csrf="<?= h(allstat_csrf_token()) ?>"><?= h(t('Stáhnout historii pro {count} napojení', ['count' => count($importResult['connection_ids'])])) ?></button>
            </div>
            <div class="callout" data-bulk-backfill-status role="status" hidden></div>
        </div>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($discovery !== null): ?>
<?php foreach ((array) $discovery['errors'] as $error): ?>
    <div class="callout tone-warn"><?= allstat_icon('info', 'icon-sm') ?><div><?= h((string) $error) ?></div></div>
<?php endforeach; ?>

<?php if (!$rows): ?>
<section class="card">
    <div class="empty">
        <span class="empty-title"><?= h(t('Účet nemá přístup k žádné GA4 property ani k ověřenému webu v Search Console.')) ?></span>
    </div>
</section>
<?php else: ?>
<form method="post" class="card card-clip">
    <?= allstat_csrf_field() ?>
    <input type="hidden" name="action" value="import">
    <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
    <div class="filterbar">
        <label class="search">
            <span class="sr-only"><?= h(t('Hledat')) ?></span>
            <?= allstat_icon('search', 'icon-sm') ?>
            <input class="input" type="search" data-table-filter="google-import-table" placeholder="<?= h(t('doména, property')) ?>">
        </label>
    </div>
    <div class="table-wrap">
        <table class="table table-data" id="google-import-table">
            <colgroup><col class="col-xs"><col class="col-xl"><col><col class="col-xl"></colgroup>
            <thead><tr>
                <th scope="col"><input type="checkbox" data-check-all="rows[]" aria-label="<?= h(t('Vybrat vše')) ?>"></th>
                <th scope="col"><?= h(t('Web')) ?></th><th scope="col">Google Analytics 4</th><th scope="col">Search Console</th>
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
                        <div class="name-stack">
                            <?php if ($row['host'] !== ''): ?><span class="name-main ellipsis"><?= h($row['host']) ?></span><?php else: ?><span class="is-muted"><?= h(t('bez adresy')) ?></span><?php endif; ?>
                            <span class="name-sub ellipsis"><?= $row['domain'] ? t('web v AllStatu: {name}', ['name' => h($row['domain']['name'])]) : h($row['host'] !== '' ? t('založí se nový web') : t('GA4 property nemá webový stream, web přidejte ručně')) ?></span>
                        </div>
                    </td>
                    <td>
                        <?php if ($ga4): ?>
                        <div class="name-stack">
                            <span class="ellipsis"><?= h($ga4['name']) ?></span>
                            <span class="name-sub ellipsis"><?= h(($ga4['account_name'] !== '' ? $ga4['account_name'] . ', ' : '') . $ga4['property']) ?></span>
                            <?php if ($row['ga4_connected']): ?><span><?= allstat_status_pill('ok', $row['domain'] && allstat_normalize_host((string) $row['domain']['url']) !== $row['host'] ? t('už připojeno u webu {name}', ['name' => $row['domain']['name']]) : t('už připojeno')) ?></span><?php endif; ?>
                        </div>
                        <?php else: ?>
                            <span class="is-muted"><?= h(t('bez GA4')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($row['gsc_options']): ?>
                        <div class="name-stack">
                            <select class="input" name="gsc[<?= h($row['key']) ?>]" aria-label="<?= h(t('Search Console pro {host}', ['host' => $row['host']])) ?>">
                                <?php foreach ($row['gsc_options'] as $i => $site): ?>
                                <option value="<?= h(allstat_google_gsc_key((string) $site['site'])) ?>"<?= $site['site'] === $row['gsc_default'] ? ' selected' : '' ?>><?= h($site['site']) ?><?= $row['gsc_connected'][$i] ? ' ' . h(t('(už připojeno)')) : '' ?></option>
                                <?php endforeach; ?>
                                <option value=""<?= $row['gsc_default'] === '' ? ' selected' : '' ?>><?= h(t('nepřipojovat')) ?></option>
                            </select>
                            <?php if (in_array(true, $row['gsc_connected'], true)): ?><span><?= allstat_status_pill('ok', t('už připojeno')) ?></span><?php endif; ?>
                        </div>
                        <?php else: ?>
                            <span class="is-muted"><?= h(t('web v Search Console nenalezen')) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-body section">
        <p class="field-help"><?= h(t('Nové weby dostanou název podle domény, přejmenujete je ve Webech. Property, která už je v AllStatu napojená (i u jiného webu), se nezaloží podruhé, jen se přepne na tento účet a data zůstanou.')) ?></p>
        <div class="form-actions"><button class="btn btn-primary" type="submit"><?= h(t('Připojit vybrané')) ?></button></div>
    </div>
</form>
<?php endif; ?>
<?php endif; ?>
<?php allstat_admin_footer($config); ?>
