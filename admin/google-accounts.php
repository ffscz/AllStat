<?php

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../lib/google-accounts.php';

$pdo = allstat_admin_require_database($pdo, $config);
$user = allstat_require_user($pdo, $config);
allstat_require_admin($user);

$redirectUri = allstat_google_redirect_uri($config);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    allstat_csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $accountId = (int) ($_POST['account_id'] ?? 0);
    $actorId = (int) $user['id'];
    $result = null;

    if ($action === 'save_client') {
        $result = allstat_google_save_client($pdo, $config, (string) ($_POST['client_id'] ?? ''), (string) ($_POST['client_secret'] ?? ''));
    } elseif ($action === 'client_from_connection') {
        $result = allstat_google_client_from_connection($pdo, (int) ($_POST['connection_id'] ?? 0));
    } elseif ($action === 'connect') {
        // Nový účet (název z formuláře) nebo obnovení připojení existujícího účtu.
        $client = allstat_google_client($pdo, $config);
        $account = $accountId > 0 ? allstat_google_account($pdo, $accountId) : null;
        $label = allstat_google_normalize_label((string) ($_POST['label'] ?? ''));
        if (!$client['configured']) {
            $result = ['ok' => false, 'message' => t('Nejdřív uložte Google OAuth klienta (Client ID a secret).')];
        } elseif ($accountId > 0 && !$account) {
            $result = ['ok' => false, 'message' => t('Účet neexistuje.')];
        } elseif (!$account && $label === '') {
            $result = ['ok' => false, 'message' => t('Zadejte název účtu, například jméno klienta nebo agentury.')];
        } else {
            $state = bin2hex(random_bytes(24));
            $_SESSION['oauth_state'][$state] = [
                'flow' => 'google_account',
                'account_id' => $account ? (int) $account['id'] : 0,
                'label' => $label,
                'redirect_uri' => $redirectUri,
                'created_at' => time(),
            ];
            header('Location: ' . allstat_google_auth_url($client['client_id'], $redirectUri, $state, (string) ($account['account_email'] ?? '')));
            exit;
        }
    } elseif ($action === 'rename') {
        $result = allstat_google_rename_account($pdo, $accountId, (string) ($_POST['label'] ?? ''), $actorId);
    } elseif ($action === 'delete') {
        $result = allstat_google_delete_account($pdo, $config, $accountId, $actorId);
    }

    if ($result) {
        allstat_flash($result['ok'] ? 'ok' : 'error', (string) $result['message']);
    }
    allstat_redirect($config, 'admin/google-accounts.php');
}

$client = allstat_google_client($pdo, $config);
$accounts = allstat_google_accounts($pdo);
$donors = $client['configured'] ? [] : allstat_google_client_donors($pdo);
$statusLabels = ['ok' => t('Připojeno'), 'warning' => t('Částečně'), 'error' => t('Odpojeno')];

allstat_admin_header(t('Google účty'), 'google', $user, $config);
?>
<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2><?= h(t('Google účty')) ?></h2>
            <p><?= h(t('Připojte jeden nebo víc Google účtů, například účet agentury a účty klientů. Z každého pak vyberete weby z Google Analytics a Search Console a AllStat je založí sám. Property ID ani Client ID nikdo nemusí opisovat.')) ?></p>
        </div>
    </div>
    <div class="admin-card-body">
        <?php if (!$client['configured']): ?>
            <p class="table-muted"><?= h(t('Nejdřív níže uložte Google OAuth klienta. Stačí jednou pro celou instalaci.')) ?></p>
        <?php else: ?>
        <div class="table-scroll">
            <table class="admin-table">
                <thead><tr><th><?= h(t('Účet')) ?></th><th><?= h(t('Stav')) ?></th><th><?= h(t('Napojení')) ?></th><th><?= h(t('Seznam webů')) ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($accounts as $account):
                    $status = (string) $account['status'];
                ?>
                    <tr>
                        <td><strong><?= h($account['label']) ?></strong><?php if ($account['account_email']): ?><br><span class="table-muted"><?= h($account['account_email']) ?></span><?php endif; ?></td>
                        <td>
                            <span class="status-badge status-<?= h($status) ?>"><?= h($statusLabels[$status] ?? $status) ?></span>
                            <?php if ($account['note']): ?><br><small class="table-muted"><?= h($account['note']) ?></small><?php endif; ?>
                        </td>
                        <td><?= (int) $account['connections'] ?></td>
                        <td class="table-muted"><?= $account['discovered_at'] ? h(allstat_date_label($account['discovered_at'], true)) : h(t('zatím nenačten')) ?></td>
                        <td>
                            <div class="google-account-actions">
                                <a class="button-primary" href="google-import.php?account_id=<?= (int) $account['id'] ?>"><?= h(t('Vybrat weby')) ?></a>
                                <form method="post">
                                    <?= allstat_csrf_field() ?>
                                    <input type="hidden" name="action" value="connect">
                                    <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                                    <button class="button-secondary" type="submit" title="<?= h(t('Znovu se přihlásit Googlem, když připojení vypršelo nebo chybí oprávnění')) ?>"><?= h(t('Obnovit připojení')) ?></button>
                                </form>
                                <details class="google-account-more">
                                    <summary class="button-link"><?= h(t('Upravit')) ?></summary>
                                    <form method="post" class="google-account-rename">
                                        <?= allstat_csrf_field() ?>
                                        <input type="hidden" name="action" value="rename">
                                        <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                                        <input type="text" name="label" value="<?= h($account['label']) ?>" maxlength="120" aria-label="<?= h(t('Název účtu')) ?>" required>
                                        <button class="button-secondary" type="submit"><?= h(t('Přejmenovat')) ?></button>
                                    </form>
                                    <form method="post" data-confirm="<?= h(t('Odebrat Google účet „{label}“? Napojení, která ho používají ({count}), přestanou stahovat data, dokud je nepřipojíte k jinému účtu. Data v AllStatu zůstanou.', ['label' => $account['label'], 'count' => (int) $account['connections']])) ?>">
                                        <?= allstat_csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                                        <button class="button-danger" type="submit"><?= h(t('Odebrat účet')) ?></button>
                                    </form>
                                </details>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$accounts): ?>
                    <tr><td colspan="5" class="table-muted"><?= h(t('Zatím žádný účet. Přidejte první níže.')) ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <form method="post" class="toolbar google-account-add">
            <?= allstat_csrf_field() ?>
            <input type="hidden" name="action" value="connect">
            <label class="filter-control"><span><?= h(t('Název účtu')) ?></span>
                <input type="text" name="label" maxlength="120" placeholder="<?= h(t('např. Agentura')) ?>" required>
            </label>
            <button class="button-primary" type="submit"><i data-lucide="user-plus"></i> <?= h(t('Přidat účet')) ?></button>
        </form>
        <p class="form-help"><?= h(t('Po kliknutí se přihlásíte Googlem a povolíte AllStatu čtení Google Analytics a Search Console. Hned potom vyberete, které weby připojit.')) ?></p>
        <?php endif; ?>
    </div>
</div>

<?php $clientOpen = !$client['configured']; ?>
<details class="admin-card-collapsible"<?= $clientOpen ? ' open' : '' ?>>
    <summary>
        <div>
            <h2><?= h(t('Google OAuth klient')) ?></h2>
            <p><?= $client['configured'] ? t('Uložený. Client ID {id}…', ['id' => h(substr($client['client_id'], 0, 16))]) : h(t('Zadejte jednou pro celou instalaci, Client secret se ukládá šifrovaně.')) ?></p>
        </div>
    </summary>
    <div class="admin-card-body">
        <?php if ($donors): ?>
        <form method="post" class="toolbar">
            <?= allstat_csrf_field() ?>
            <input type="hidden" name="action" value="client_from_connection">
            <label class="filter-control"><span><?= h(t('Převzít z existujícího napojení')) ?></span>
                <select name="connection_id">
                    <?php foreach ($donors as $donor): ?>
                    <option value="<?= (int) $donor['id'] ?>"><?= h($donor['source_name'] . ', ' . $donor['domain_name']) ?> (<?= h(substr((string) $donor['client_id'], 0, 16)) ?>…)</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="button-secondary" type="submit"><?= h(t('Převzít Client ID a secret')) ?></button>
        </form>
        <p class="form-help"><?= h(t('Napojení Google služeb už má svého klienta. Převzetím ho stačí jednou a nemusíte nic opisovat.')) ?></p>
        <?php endif; ?>

        <form method="post" class="form-stack">
            <?= allstat_csrf_field() ?>
            <input type="hidden" name="action" value="save_client">
            <div class="form-grid">
                <label><span>Client ID</span><input type="text" name="client_id" value="<?= h($client['client_id']) ?>" placeholder="1234567890-abc.apps.googleusercontent.com" autocomplete="off" spellcheck="false"></label>
                <label><span>Client secret</span><input type="password" name="client_secret" value="" autocomplete="new-password" placeholder="<?= $client['configured'] ? h(t('uloženo, nechte prázdné pro zachování')) : '' ?>"></label>
            </div>
            <div class="form-actions"><button class="button-primary" type="submit"><?= h(t('Uložit klienta')) ?></button></div>
        </form>

        <div class="code-box">
            <?= t('<strong>Authorized redirect URI</strong> (Google Cloud Console, APIs &amp; Services, Credentials, váš OAuth klient typu Web application):') ?><br>
            <input type="text" class="code-input" value="<?= h($redirectUri) ?>" readonly data-select-all aria-label="Redirect URI">
        </div>
        <ol class="provider-guide-steps">
            <li><?= t('V Google Cloud Console (APIs &amp; Services, Library) zapněte <strong>Google Analytics Data API</strong>, <strong>Google Analytics Admin API</strong> (podle něj AllStat najde vaše property) a <strong>Google Search Console API</strong>.') ?></li>
            <li><?= t('OAuth consent screen přepněte do stavu <strong>In production</strong>. Ve stavu Testing Google ruší připojení po 7 dnech. Ověření aplikace pro vlastní použití potřeba není, při přihlášení se jen ukáže upozornění na neověřenou aplikaci.') ?></li>
            <li><?= h(t('U OAuth klienta zadejte redirect URI výše. Pokud už ji máte zadanou kvůli napojení GA4, nic měnit nemusíte.')) ?></li>
        </ol>
    </div>
</details>
<?php allstat_admin_footer($config); ?>
