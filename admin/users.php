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
        $result = allstat_reset_user_totp($pdo, (int) $user['id'], (int) ($_POST['id'] ?? 0));
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
<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2><?= h($editing ? t('Upravit uživatele') : t('Nový uživatel')) ?></h2>
            <p><?= h(t('Účty používají hashovaná hesla, audit log a volitelné TOTP 2FA.')) ?></p>
        </div>
    </div>
    <div class="admin-card-body">
        <form method="post" class="form-grid">
            <?= allstat_csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
            <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
            <label><span><?= h(t('Jméno')) ?></span><input type="text" name="name" required value="<?= h($editing['name'] ?? '') ?>"></label>
            <label><span>E-mail</span><input type="text" name="email" required inputmode="email" value="<?= h($editing['email'] ?? '') ?>" autocomplete="username"></label>
            <label><span><?= h(t('Role')) ?></span><select name="role" data-domain-role><option value="admin" <?= ($editing['role'] ?? '') === 'admin' ? 'selected' : '' ?>><?= h(t('Admin')) ?></option><option value="user" <?= ($editing['role'] ?? 'user') === 'user' ? 'selected' : '' ?>><?= h(t('Uživatel')) ?></option></select></label>
            <label><span><?= h(t('Stav')) ?></span><select name="is_active"><option value="1" <?= (int) ($editing['is_active'] ?? 1) === 1 ? 'selected' : '' ?>><?= h(t('Aktivní')) ?></option><option value="0" <?= isset($editing['is_active']) && (int) $editing['is_active'] === 0 ? 'selected' : '' ?>><?= h(t('Vypnuto')) ?></option></select></label>
            <label><span><?= h($editing ? t('Nové heslo') : t('Heslo')) ?></span><input type="password" name="password" <?= $editing ? '' : 'required' ?> minlength="12" autocomplete="new-password" placeholder="<?= $editing ? h(t('nechte prázdné pro zachování')) : '' ?>"></label>
            <fieldset class="domain-access" data-domain-access>
                <legend><?= h(t('Přístup k webům')) ?></legend>
                <div class="domain-access-mode">
                    <label class="domain-access-option"><input type="radio" name="domain_access" value="all" <?= $domainAccess === 'all' ? 'checked' : '' ?>><span><strong><?= h(t('Všechny weby')) ?></strong><small><?= h(t('včetně webů přidaných později')) ?></small></span></label>
                    <label class="domain-access-option"><input type="radio" name="domain_access" value="selected" <?= $domainAccess === 'selected' ? 'checked' : '' ?>><span><strong><?= h(t('Jen vybrané weby')) ?></strong><small><?= h(t('v přehledu, reportech i sdílených odkazech uvidí jen zaškrtnuté')) ?></small></span></label>
                </div>
                <p class="field-help" data-domain-admin-note<?= ($editing['role'] ?? 'user') === 'admin' ? '' : ' hidden' ?>><?= h(t('Administrátor vidí vždy všechny weby. Výběr se uplatní, až z účtu bude běžný uživatel.')) ?></p>
                <div class="domain-access-pick" data-domain-pick<?= $domainAccess === 'selected' ? '' : ' hidden' ?>>
                    <?php if (!$allDomains): ?>
                        <p class="field-help"><?= h(t('Zatím tu není žádný web. Přidejte ho ve Weby.')) ?></p>
                    <?php else: ?>
                        <div class="domain-access-toolbar">
                            <?php if (count($allDomains) > 8): ?><input type="search" placeholder="<?= h(t('Hledat web')) ?>" aria-label="<?= h(t('Hledat web')) ?>" data-domain-filter><?php endif; ?>
                            <button class="button-secondary" type="button" data-domain-check="all"><?= h(t('Vybrat vše')) ?></button>
                            <button class="button-secondary" type="button" data-domain-check="none"><?= h(t('Zrušit výběr')) ?></button>
                            <span class="domain-access-count" data-domain-count></span>
                        </div>
                        <div class="domain-access-list">
                            <?php foreach ($allDomains as $domain): ?>
                                <label class="domain-access-item" data-domain-item data-search="<?= h(mb_strtolower($domain['name'] . ' ' . $domain['url'])) ?>">
                                    <input type="checkbox" name="domain_ids[]" value="<?= (int) $domain['id'] ?>" <?= in_array((int) $domain['id'], $assignedIds, true) ? 'checked' : '' ?>>
                                    <span><strong><?= h($domain['name']) ?></strong><small><?= h(allstat_normalize_host((string) $domain['url'])) ?><?= (int) $domain['is_active'] === 1 ? '' : h(', ' . t('vypnutý')) ?></small></span>
                                </label>
                            <?php endforeach; ?>
                            <p class="field-help" data-domain-empty hidden><?= h(t('Žádný web neodpovídá hledání.')) ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </fieldset>
            <div class="form-actions"><button class="button-primary" type="submit"><?= h($editing ? t('Uložit uživatele') : t('Vytvořit uživatele')) ?></button><?php if ($editing): ?><a class="button-secondary" href="users.php"><?= h(t('Zrušit úpravu')) ?></a><?php endif; ?></div>
        </form>
    </div>
</div>

<div class="admin-card">
    <div class="admin-card-header"><h2><?= h(t('Účty')) ?></h2></div>
    <div class="admin-card-body table-scroll">
        <table class="admin-table">
            <thead><tr><th><?= h(t('Uživatel')) ?></th><th><?= h(t('Role')) ?></th><th><?= h(t('Weby')) ?></th><th>2FA</th><th><?= h(t('Poslední login')) ?></th><th><?= h(t('Stav')) ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($users as $item): ?>
                <?php
                $restricted = $item['role'] !== 'admin' && ($item['domain_access'] ?? 'all') === 'selected';
                $domainCount = (int) ($item['domain_count'] ?? 0);
                ?>
                <tr>
                    <td><strong><?= h($item['name']) ?></strong><div class="table-muted"><?= h($item['email']) ?></div></td>
                    <td><?= h($item['role'] === 'admin' ? t('Admin') : t('Uživatel')) ?></td>
                    <td data-sort="<?= $restricted ? $domainCount : 100000 ?>"><?php if (!$restricted): ?><?= h(t('Všechny')) ?><?php elseif ($domainCount === 0): ?><span class="secret-state warn"><?= h(t('Žádný')) ?></span><?php else: ?><?= h(t('{count} z {total}', ['count' => $domainCount, 'total' => $activeDomainCount])) ?><?php endif; ?></td>
                    <td><span class="secret-state <?= $item['totp_secret_enc'] ? 'on' : 'warn' ?>"><?= h($item['totp_secret_enc'] ? t('Zapnuto') : t('Vypnuto')) ?></span></td>
                    <td><?= $item['last_login_at'] ? h(allstat_iso_to_cz($item['last_login_at'])) : '<span class="table-muted">' . h(t('nikdy')) . '</span>' ?></td>
                    <td><?= (int) $item['is_active'] === 1 ? allstat_status_badge('ok') : allstat_status_badge('warning') ?></td>
                    <td>
                        <div class="table-actions">
                            <a class="button-secondary" href="users.php?edit=<?= (int) $item['id'] ?>"><?= h(t('Upravit')) ?></a>
                            <?php if ($item['totp_secret_enc']): ?>
                                <form method="post" data-confirm="<?= h(t('Resetovat 2FA pro tohoto uživatele?')) ?>"><?= allstat_csrf_field() ?><input type="hidden" name="action" value="reset_2fa"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><button class="button-secondary" type="submit"><?= h(t('Reset 2FA')) ?></button></form>
                            <?php endif; ?>
                            <?php if ((int) $item['id'] !== (int) $user['id']): ?>
                                <form method="post" data-confirm="<?= h(t('Smazat uživatele?')) ?>"><?= allstat_csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><button class="button-danger" type="submit"><?= h(t('Smazat')) ?></button></form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php allstat_admin_footer($config); ?>
