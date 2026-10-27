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

allstat_admin_header(t('Google účty'), 'google', $user, $config, null, [
    'sub' => t('Připojte jeden nebo víc Google účtů, například účet agentury a účty klientů. Z každého pak vyberete weby z Google Analytics a Search Console a AllStat je založí sám. Property ID ani Client ID nikdo nemusí opisovat.'),
]);
?>
<?php if (!$client['configured']): ?>
<div class="callout"><?= allstat_icon('info', 'icon-sm') ?><div><?= h(t('Nejdřív níže uložte Google OAuth klienta. Stačí jednou pro celou instalaci.')) ?></div></div>
<?php else: ?>
<section class="card" aria-labelledby="accounts-title">
    <div class="card-head">
        <div>
            <h2 class="card-title" id="accounts-title"><?= h(t('Google účty')) ?></h2>
        </div>
    </div>
    <?php if ($accounts): ?>
    <div class="table-wrap">
        <table class="table table-data">
            <colgroup><col class="col-xl"><col class="col-lg"><col class="col-sm"><col class="col-md"><col class="col-lg"></colgroup>
            <thead><tr><th scope="col"><?= h(t('Účet')) ?></th><th scope="col"><?= h(t('Stav')) ?></th><th scope="col"><?= h(t('Napojení')) ?></th><th scope="col"><?= h(t('Seznam webů')) ?></th><th scope="col" data-no-sort><span class="sr-only"><?= h(t('Akce')) ?></span></th></tr></thead>
            <tbody>
            <?php foreach ($accounts as $account):
                $status = (string) $account['status'];
                $accountMenu = 'google-account-menu-' . (int) $account['id'];
            ?>
                <tr>
                    <td>
                        <div class="name-stack"><span class="name-main ellipsis"><?= h($account['label']) ?></span><?php if ($account['account_email']): ?><span class="name-sub ellipsis"><?= h($account['account_email']) ?></span><?php endif; ?></div>
                    </td>
                    <td>
                        <div class="name-stack"><span><?= allstat_status_pill($status === 'ok' || $status === 'warning' || $status === 'error' ? $status : 'info', $statusLabels[$status] ?? $status) ?></span><?php if ($account['note']): ?><span class="name-sub ellipsis" title="<?= h($account['note']) ?>"><?= h($account['note']) ?></span><?php endif; ?></div>
                    </td>
                    <td class="num"><?= (int) $account['connections'] ?></td>
                    <td class="is-muted"><?= $account['discovered_at'] ? h(allstat_date_label($account['discovered_at'], true)) : h(t('zatím nenačten')) ?></td>
                    <td>
                        <div class="cell-actions dropdown">
                            <a class="btn btn-secondary btn-sm" href="google-import.php?account_id=<?= (int) $account['id'] ?>"><span><?= h(t('Vybrat weby')) ?></span></a>
                            <button type="button" class="btn-icon btn-sm" aria-label="<?= h(t('Další akce pro {name}', ['name' => $account['label']])) ?>" aria-haspopup="true" aria-expanded="false" data-menu-toggle="<?= h($accountMenu) ?>"><?= allstat_icon('ellipsis', 'icon-sm') ?></button>
                            <div class="menu menu-end" id="<?= h($accountMenu) ?>" hidden>
                                <form method="post">
                                    <?= allstat_csrf_field() ?>
                                    <input type="hidden" name="action" value="connect">
                                    <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                                    <button class="menu-item" type="submit" title="<?= h(t('Znovu se přihlásit Googlem, když připojení vypršelo nebo chybí oprávnění')) ?>"><?= allstat_icon('refresh-cw', 'icon-sm') ?><span><?= h(t('Obnovit připojení')) ?></span></button>
                                </form>
                                <div class="menu-sep"></div>
                                <form method="post">
                                    <?= allstat_csrf_field() ?>
                                    <input type="hidden" name="action" value="rename">
                                    <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                                    <input class="input" type="text" name="label" value="<?= h($account['label']) ?>" maxlength="120" aria-label="<?= h(t('Název účtu')) ?>" required>
                                    <button class="menu-item" type="submit"><?= allstat_icon('pencil', 'icon-sm') ?><span><?= h(t('Přejmenovat')) ?></span></button>
                                </form>
                                <div class="menu-sep"></div>
                                <form method="post" data-confirm="<?= h(t('Odebrat Google účet „{label}“? Napojení, která ho používají ({count}), přestanou stahovat data, dokud je nepřipojíte k jinému účtu. Data v AllStatu zůstanou.', ['label' => $account['label'], 'count' => (int) $account['connections']])) ?>">
                                    <?= allstat_csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                                    <button class="menu-item is-danger" type="submit"><?= allstat_icon('unplug', 'icon-sm') ?><span><?= h(t('Odebrat účet')) ?></span></button>
                                </form>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty">
        <span class="empty-title"><?= h(t('Zatím žádný účet. Přidejte první níže.')) ?></span>
    </div>
    <?php endif; ?>
</section>

<section class="card" aria-labelledby="add-account-title">
    <div class="card-head">
        <div>
            <h2 class="card-title" id="add-account-title"><?= h(t('Přidat účet')) ?></h2>
            <p class="card-desc"><?= h(t('Po kliknutí se přihlásíte Googlem a povolíte AllStatu čtení Google Analytics a Search Console. Hned potom vyberete, které weby připojit.')) ?></p>
        </div>
    </div>
    <form method="post" class="card-body form-grid">
        <?= allstat_csrf_field() ?>
        <input type="hidden" name="action" value="connect">
        <label class="field">
            <span class="field-label"><?= h(t('Název účtu')) ?></span>
            <input class="input" type="text" name="label" maxlength="120" placeholder="<?= h(t('např. Agentura')) ?>" required>
        </label>
        <div class="form-actions field-full">
            <button class="btn btn-primary" type="submit"><?= allstat_icon('user-plus', 'icon-sm') ?><span><?= h(t('Přidat účet')) ?></span></button>
        </div>
    </form>
</section>
<?php endif; ?>

<?php $clientOpen = !$client['configured']; ?>
<details class="card note"<?= $clientOpen ? ' open' : '' ?>>
    <summary>
        <?= allstat_icon('key-round', 'icon') ?>
        <span><?= h(t('Google OAuth klient')) ?></span>
        <?= allstat_icon('chevron-down', 'note-chev') ?>
    </summary>
    <div class="note-body">
        <p><?= $client['configured'] ? t('Uložený. Client ID {id}…', ['id' => h(substr($client['client_id'], 0, 16))]) : h(t('Zadejte jednou pro celou instalaci, Client secret se ukládá šifrovaně.')) ?></p>
        <?php if ($donors): ?>
        <form method="post" class="form-grid">
            <?= allstat_csrf_field() ?>
            <input type="hidden" name="action" value="client_from_connection">
            <label class="field field-full">
                <span class="field-label"><?= h(t('Převzít z existujícího napojení')) ?></span>
                <select class="input" name="connection_id">
                    <?php foreach ($donors as $donor): ?>
                    <option value="<?= (int) $donor['id'] ?>"><?= h($donor['source_name'] . ', ' . $donor['domain_name']) ?> (<?= h(substr((string) $donor['client_id'], 0, 16)) ?>…)</option>
                    <?php endforeach; ?>
                </select>
                <span class="field-help"><?= h(t('Napojení Google služeb už má svého klienta. Převzetím ho stačí jednou a nemusíte nic opisovat.')) ?></span>
            </label>
            <div class="form-actions field-full">
                <button class="btn btn-secondary" type="submit"><?= h(t('Převzít Client ID a secret')) ?></button>
            </div>
        </form>
        <?php endif; ?>

        <form method="post" class="form-grid">
            <?= allstat_csrf_field() ?>
            <input type="hidden" name="action" value="save_client">
            <label class="field"><span class="field-label">Client ID</span><input class="input" type="text" name="client_id" value="<?= h($client['client_id']) ?>" placeholder="1234567890-abc.apps.googleusercontent.com" autocomplete="off" spellcheck="false"></label>
            <label class="field"><span class="field-label">Client secret</span><input class="input" type="password" name="client_secret" value="" autocomplete="new-password" placeholder="<?= $client['configured'] ? h(t('uloženo, nechte prázdné pro zachování')) : '' ?>"></label>
            <div class="form-actions field-full"><button class="btn btn-primary" type="submit"><?= h(t('Uložit klienta')) ?></button></div>
        </form>

        <label class="field">
            <span class="field-label"><?= t('<strong>Authorized redirect URI</strong> (Google Cloud Console, APIs &amp; Services, Credentials, váš OAuth klient typu Web application):') ?></span>
            <input type="text" class="input mono" value="<?= h($redirectUri) ?>" readonly data-select-all aria-label="Redirect URI">
        </label>
        <ol class="steps">
            <li><?= t('V Google Cloud Console (APIs &amp; Services, Library) zapněte <strong>Google Analytics Data API</strong>, <strong>Google Analytics Admin API</strong> (podle něj AllStat najde vaše property) a <strong>Google Search Console API</strong>.') ?></li>
            <li><?= t('OAuth consent screen přepněte do stavu <strong>In production</strong>. Ve stavu Testing Google ruší připojení po 7 dnech. Ověření aplikace pro vlastní použití potřeba není, při přihlášení se jen ukáže upozornění na neověřenou aplikaci.') ?></li>
            <li><?= h(t('U OAuth klienta zadejte redirect URI výše. Pokud už ji máte zadanou kvůli napojení GA4, nic měnit nemusíte.')) ?></li>
        </ol>
    </div>
</details>
<?php allstat_admin_footer($config); ?>
