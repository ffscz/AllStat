<?php

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_layout.php';

// AI konektory (MCP): když uživatel přijde o přístup, odpojí se i jeho připojené aplikace.
if (is_file(__DIR__ . '/../lib/mcp-oauth.php')) {
    try {
        require_once __DIR__ . '/../lib/mcp-oauth.php';
    } catch (Throwable $exception) {
        error_log('AllStat users: lib/mcp-oauth.php se nepodařilo načíst: ' . $exception->getMessage());
    }
}

/**
 * Odpojí AI aplikace uživatele (MCP), pokud je serverová část nainstalovaná. Chyba nesmí zablokovat správu uživatelů.
 * Vrací false jen když odpojení selhalo (nic k odpojení nebo chybějící serverová část je v pořádku).
 */
function allstat_users_revoke_mcp_grants(PDO $pdo, int $userId): bool
{
    if ($userId <= 0 || !function_exists('allstat_mcp_revoke_user')) {
        return true;
    }

    try {
        allstat_mcp_revoke_user($pdo, $userId);

        return true;
    } catch (Throwable $exception) {
        error_log('AllStat users: odpojení MCP připojení uživatele ' . $userId . ' selhalo: ' . $exception->getMessage());

        return false;
    }
}

$pdo = allstat_admin_require_database($pdo, $config);
$user = allstat_require_user($pdo, $config);
allstat_require_admin($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    allstat_csrf_check();
    $action = (string) ($_POST['action'] ?? 'create');

    if ($action === 'delete') {
        $result = allstat_delete_user($pdo, (int) $user['id'], (int) ($_POST['id'] ?? 0));
    } elseif ($action === 'reset_2fa') {
        // Vlastní 2FA jen na stránce Dvoufaktorové ověření s heslem: otevřená session nesmí stačit k jeho vypnutí.
        $result = (int) ($_POST['id'] ?? 0) === (int) $user['id']
            ? ['ok' => false, 'message' => 'Vlastní 2FA resetujte na stránce Dvoufaktorové ověření, kde se potvrzuje heslem.']
            : allstat_reset_user_totp($pdo, (int) $user['id'], (int) ($_POST['id'] ?? 0));
    } elseif ($action === 'update') {
        $result = allstat_update_user($pdo, (int) $user['id'], (int) ($_POST['id'] ?? 0), $_POST);
    } else {
        $result = allstat_create_user($pdo, (int) $user['id'], $_POST);
    }

    // Uživatel, který je po smazání nebo úpravě neaktivní či už není admin, nesmí mít připojenou žádnou AI aplikaci.
    // Ověřuje se skutečný stav v DB: allstat_update_user uloží roli i tehdy, když pak vrátí chybu kvůli heslu.
    $mcpRevokeFailed = false;
    if ($action === 'delete' || $action === 'update') {
        $targetId = (int) ($_POST['id'] ?? 0);
        $after = $targetId > 0 ? allstat_fetch_one($pdo, 'SELECT role, is_active FROM allstat_users WHERE id = ?', [$targetId]) : null;
        if (!$after || (int) $after['is_active'] !== 1 || $after['role'] !== 'admin') {
            $mcpRevokeFailed = !allstat_users_revoke_mcp_grants($pdo, $targetId) || $mcpRevokeFailed;
        }
    }

    // Změna přihlašovacích údajů (nové heslo, reset 2FA) odvolá i AI připojení uživatele: kdo mohl znát staré heslo nebo
    // starý 2FA kód, nesmí zůstat přihlášený přes dříve povolenou aplikaci. Heslo se změnilo jen při úspěšném uložení
    // (allstat_update_user při chybě hesla vrací ok=false a hash nepřepíše).
    if (!empty($result['ok']) && ($action === 'reset_2fa' || ($action === 'update' && (string) ($_POST['password'] ?? '') !== ''))) {
        $mcpRevokeFailed = !allstat_users_revoke_mcp_grants($pdo, (int) ($_POST['id'] ?? 0)) || $mcpRevokeFailed;
    }

    allstat_flash($result['ok'] ? 'ok' : 'error', t($result['message']));
    if ($mcpRevokeFailed) {
        allstat_flash('error', t('Připojení AI tohoto uživatele se nepodařilo odpojit. Odpojte je ručně v Administrace → AI konektory.'));
    }
    allstat_redirect($config, 'admin/users.php');
}

$editing = isset($_GET['edit']) ? allstat_get_user($pdo, (int) $_GET['edit']) : null;
$users = allstat_list_users($pdo);
// Přístup k webům: všechny weby včetně vypnutých (přidělit jde i web, který se teprve zapne).
$allDomains = allstat_admin_domains($pdo);
$activeDomainCount = count(array_filter($allDomains, static fn (array $d): bool => (int) $d['is_active'] === 1));
$domainAccess = ($editing['domain_access'] ?? 'all') === 'selected' ? 'selected' : 'all';
$assignedIds = $editing ? allstat_user_assigned_domain_ids($pdo, (int) $editing['id']) : [];
allstat_admin_header(t('Uživatelé'), 'users', $user, $config);
?>
<section class="card" id="user-form" aria-labelledby="user-form-title">
    <div class="card-head">
        <div>
            <h2 class="card-title" id="user-form-title"><?= h($editing ? t('Upravit uživatele') : t('Nový uživatel')) ?></h2>
            <p class="card-desc"><?= h(t('Účty používají hashovaná hesla, audit log a volitelné TOTP 2FA.')) ?></p>
        </div>
    </div>
    <form method="post" class="card-body form-grid">
        <?= allstat_csrf_field() ?>
        <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
        <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
        <label class="field"><span class="field-label"><?= h(t('Jméno')) ?></span><input class="input" type="text" name="name" required value="<?= h($editing['name'] ?? '') ?>"></label>
        <label class="field"><span class="field-label">E-mail</span><input class="input" type="text" name="email" required inputmode="email" value="<?= h($editing['email'] ?? '') ?>" autocomplete="username"></label>
        <label class="field"><span class="field-label"><?= h(t('Role')) ?></span><select class="input" name="role" data-domain-role><option value="admin" <?= ($editing['role'] ?? '') === 'admin' ? 'selected' : '' ?>><?= h(t('Admin')) ?></option><option value="user" <?= ($editing['role'] ?? 'user') === 'user' ? 'selected' : '' ?>><?= h(t('Uživatel')) ?></option></select></label>
        <label class="field"><span class="field-label"><?= h(t('Stav')) ?></span><select class="input" name="is_active"><option value="1" <?= (int) ($editing['is_active'] ?? 1) === 1 ? 'selected' : '' ?>><?= h(t('Aktivní')) ?></option><option value="0" <?= isset($editing['is_active']) && (int) $editing['is_active'] === 0 ? 'selected' : '' ?>><?= h(t('Vypnuto')) ?></option></select></label>
        <label class="field"><span class="field-label"><?= h($editing ? t('Nové heslo') : t('Heslo')) ?></span><input class="input" type="password" name="password" <?= $editing ? '' : 'required' ?> minlength="12" autocomplete="new-password" placeholder="<?= $editing ? h(t('nechte prázdné pro zachování')) : '' ?>"></label>
        <div class="field field-full section" role="group" aria-labelledby="domain-access-title" data-domain-access>
            <span class="field-label" id="domain-access-title"><?= h(t('Přístup k webům')) ?></span>
            <div class="grid grid-tiles">
                <label class="check"><input type="radio" name="domain_access" value="all" <?= $domainAccess === 'all' ? 'checked' : '' ?>><span class="name-stack"><span class="name-main"><?= h(t('Všechny weby')) ?></span><span class="name-sub"><?= h(t('včetně webů přidaných později')) ?></span></span></label>
                <label class="check"><input type="radio" name="domain_access" value="selected" <?= $domainAccess === 'selected' ? 'checked' : '' ?>><span class="name-stack"><span class="name-main"><?= h(t('Jen vybrané weby')) ?></span><span class="name-sub"><?= h(t('v přehledu, reportech i sdílených odkazech uvidí jen zaškrtnuté')) ?></span></span></label>
            </div>
            <div class="callout" data-domain-admin-note<?= ($editing['role'] ?? 'user') === 'admin' ? '' : ' hidden' ?>><?= allstat_icon('info', 'icon-sm') ?><div><?= h(t('Administrátor vidí vždy všechny weby. Výběr se uplatní, až z účtu bude běžný uživatel.')) ?></div></div>
            <div class="section" data-domain-pick<?= $domainAccess === 'selected' ? '' : ' hidden' ?>>
                <?php if (!$allDomains): ?>
                    <p class="field-help"><?= h(t('Zatím tu není žádný web. Přidejte ho ve Weby.')) ?></p>
                <?php else: ?>
                    <div class="form-actions">
                        <?php if (count($allDomains) >= 8): ?><input class="input" type="search" placeholder="<?= h(t('Hledat web')) ?>" aria-label="<?= h(t('Hledat web')) ?>" data-domain-filter><?php endif; ?>
                        <button class="btn btn-secondary btn-sm" type="button" data-domain-check="all"><?= h(t('Vybrat vše')) ?></button>
                        <button class="btn btn-secondary btn-sm" type="button" data-domain-check="none"><?= h(t('Zrušit výběr')) ?></button>
                        <span class="is-muted" data-domain-count></span>
                    </div>
                    <div class="grid grid-tiles">
                        <?php foreach ($allDomains as $domain): ?>
                            <label class="check" data-domain-item data-search="<?= h(mb_strtolower($domain['name'] . ' ' . $domain['url'])) ?>">
                                <input type="checkbox" name="domain_ids[]" value="<?= (int) $domain['id'] ?>" <?= in_array((int) $domain['id'], $assignedIds, true) ? 'checked' : '' ?>>
                                <span class="name-stack"><span class="name-main"><?= h($domain['name']) ?></span><span class="name-sub"><?= h(allstat_normalize_host((string) $domain['url'])) ?><?= (int) $domain['is_active'] === 1 ? '' : h(', ' . t('vypnutý')) ?></span></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="field-help" data-domain-empty hidden><?= h(t('Žádný web neodpovídá hledání.')) ?></p>
                <?php endif; ?>
            </div>
        </div>
        <div class="form-actions field-full"><button class="btn btn-primary" type="submit"><?= h($editing ? t('Uložit uživatele') : t('Vytvořit uživatele')) ?></button><?php if ($editing): ?><a class="btn btn-secondary" href="users.php"><?= h(t('Zrušit úpravu')) ?></a><?php endif; ?></div>
    </form>
</section>

<section class="card" aria-labelledby="users-title">
    <div class="card-head">
        <div>
            <h2 class="card-title" id="users-title"><?= h(t('Účty')) ?> <small class="num"><?= count($users) ?></small></h2>
        </div>
    </div>
    <div class="table-wrap">
        <table class="table table-data">
            <colgroup><col><col class="col-sm"><col class="col-sm"><col class="col-sm"><col class="col-md"><col class="col-sm"><col class="col-md"></colgroup>
            <thead><tr><th scope="col"><?= h(t('Uživatel')) ?></th><th scope="col"><?= h(t('Role')) ?></th><th scope="col"><?= h(t('Weby')) ?></th><th scope="col">2FA</th><th scope="col"><?= h(t('Poslední login')) ?></th><th scope="col"><?= h(t('Stav')) ?></th><th scope="col" data-no-sort><span class="sr-only"><?= h(t('Akce')) ?></span></th></tr></thead>
            <tbody>
            <?php foreach ($users as $item): ?>
                <?php
                $restricted = $item['role'] !== 'admin' && ($item['domain_access'] ?? 'all') === 'selected';
                $domainCount = (int) ($item['domain_count'] ?? 0);
                $uid = (int) $item['id'];
                ?>
                <tr>
                    <td>
                        <div class="cell">
                            <span class="tile tile-md tile-round" aria-hidden="true"><?= h(mb_strtoupper(mb_substr((string) $item['name'], 0, 1))) ?></span>
                            <div class="name-stack">
                                <span class="name-main ellipsis"><?= h($item['name']) ?></span>
                                <span class="name-sub ellipsis"><?= h($item['email']) ?></span>
                            </div>
                        </div>
                    </td>
                    <td><?= h($item['role'] === 'admin' ? t('Admin') : t('Uživatel')) ?></td>
                    <td data-sort="<?= $restricted ? $domainCount : 100000 ?>"><?php if (!$restricted): ?><?= h(t('Všechny')) ?><?php elseif ($domainCount === 0): ?><?= allstat_status_pill('warning', t('Žádný')) ?><?php else: ?><?= h(t('{count} z {total}', ['count' => $domainCount, 'total' => $activeDomainCount])) ?><?php endif; ?></td>
                    <td><?= allstat_status_pill($item['totp_secret_enc'] ? 'ok' : 'warning', $item['totp_secret_enc'] ? t('Zapnuto') : t('Vypnuto')) ?></td>
                    <td><?= $item['last_login_at'] ? h(allstat_iso_to_cz($item['last_login_at'])) : '<span class="is-muted">' . h(t('nikdy')) . '</span>' ?></td>
                    <td><?= (int) $item['is_active'] === 1 ? allstat_status_pill('ok', t('Aktivní')) : allstat_status_badge('off') ?></td>
                    <td>
                        <div class="cell-actions dropdown">
                            <a class="btn-icon btn-sm" href="users.php?edit=<?= $uid ?>#user-form" title="<?= h(t('Upravit')) ?>" aria-label="<?= h(t('Upravit')) ?>: <?= h($item['name']) ?>"><?= allstat_icon('pencil', 'icon-sm') ?></a>
                            <?php if ($item['totp_secret_enc'] || $uid !== (int) $user['id']): ?>
                            <button type="button" class="btn-icon btn-sm" aria-label="<?= h(t('Další akce pro {name}', ['name' => $item['name']])) ?>" aria-haspopup="true" aria-expanded="false" data-menu-toggle="user-menu-<?= $uid ?>"><?= allstat_icon('ellipsis', 'icon-sm') ?></button>
                            <div class="menu menu-end" id="user-menu-<?= $uid ?>" hidden>
                                <?php if ($item['totp_secret_enc'] && $uid === (int) $user['id']): ?>
                                    <a class="menu-item" href="<?= h(allstat_url($config, 'admin/setup-2fa.php#reset-2fa')) ?>"><?= allstat_icon('refresh-cw', 'icon-sm') ?><span><?= h(t('Reset 2FA')) ?></span></a>
                                <?php elseif ($item['totp_secret_enc']): ?>
                                    <form method="post" data-confirm="<?= h(t('Resetovat 2FA pro tohoto uživatele?')) ?>"><?= allstat_csrf_field() ?><input type="hidden" name="action" value="reset_2fa"><input type="hidden" name="id" value="<?= $uid ?>"><button class="menu-item" type="submit"><?= allstat_icon('refresh-cw', 'icon-sm') ?><span><?= h(t('Reset 2FA')) ?></span></button></form>
                                <?php endif; ?>
                                <?php if ($item['totp_secret_enc'] && $uid !== (int) $user['id']): ?><div class="menu-sep"></div><?php endif; ?>
                                <?php if ($uid !== (int) $user['id']): ?>
                                    <form method="post" data-confirm="<?= h(t('Smazat uživatele?')) ?>"><?= allstat_csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $uid ?>"><button class="menu-item is-danger" type="submit"><?= allstat_icon('trash-2', 'icon-sm') ?><span><?= h(t('Smazat')) ?></span></button></form>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php allstat_admin_footer($config); ?>
