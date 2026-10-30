<?php

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_layout.php';

// AI konektory (MCP): nastavení nového 2FA je změna přihlašovacích údajů, dříve povolená AI připojení se odvolají.
if (is_file(__DIR__ . '/../lib/mcp-oauth.php')) {
    try {
        require_once __DIR__ . '/../lib/mcp-oauth.php';
    } catch (Throwable $exception) {
        error_log('AllStat 2FA: lib/mcp-oauth.php se nepodařilo načíst: ' . $exception->getMessage());
    }
}

/** Odpojí AI aplikace uživatele (změna 2FA); chyba se jen oznámí, nastavení 2FA nezastaví. */
function allstat_setup_2fa_revoke_mcp(PDO $pdo, int $userId): void
{
    if (!function_exists('allstat_mcp_revoke_user')) {
        return;
    }

    try {
        allstat_mcp_revoke_user($pdo, $userId);
    } catch (Throwable $exception) {
        error_log('AllStat 2FA: odpojení MCP připojení uživatele ' . $userId . ' selhalo: ' . $exception->getMessage());
        allstat_flash('error', t('Připojení AI se nepodařilo odpojit. Odpojte je ručně v Administrace → AI konektory (jen pro administrátory).'));
    }
}

$pdo = allstat_admin_require_database($pdo, $config);
$user = allstat_require_user($pdo, $config, true);
$userId = (int) $user['id'];
$isAdmin = ($user['role'] ?? '') === 'admin';
$error = null;

if (!allstat_user_has_totp($user) && empty($_SESSION['totp_setup_secret'])) {
    $_SESSION['totp_setup_secret'] = allstat_totp_generate_secret();
}

$secret = (string) ($_SESSION['totp_setup_secret'] ?? '');
$uri = $secret !== '' ? allstat_totp_uri($secret, $config['app']['name'], $user['email']) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    allstat_csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if (isset($_POST['reset_pending_secret'])) {
        $_SESSION['totp_setup_secret'] = allstat_totp_generate_secret();
        allstat_redirect($config, 'admin/setup-2fa.php');
    }

    if (!allstat_user_has_totp($user)) {
        $result = allstat_setup_user_totp($pdo, $config, $userId, $secret, (string) ($_POST['code'] ?? ''));

        if ($result['ok']) {
            unset($_SESSION['totp_setup_secret']);
            allstat_flash('ok', t($result['message']));
            allstat_setup_2fa_revoke_mcp($pdo, $userId);
            // Záložní kódy se ukážou jednou, na této stránce po přesměrování.
            if (is_array($result['backup_codes'] ?? null)) {
                $_SESSION['backup_codes_show'] = $result['backup_codes'];
            } else {
                allstat_flash('error', t('Záložní kódy se nepodařilo vytvořit. Vygenerujte je níže znovu.'));
            }
            allstat_redirect($config, 'admin/setup-2fa.php');
        }

        $error = t($result['message']);
    } elseif ($action === 'regenerate_backup_codes') {
        $error = allstat_confirm_password($pdo, $user, $_POST['password'] ?? null, 'backup_codes');

        if ($error === null) {
            $codes = allstat_backup_codes_regenerate($pdo, $userId);
            if ($codes === null) {
                $error = t('Záložní kódy se nepodařilo vytvořit. Zkuste to prosím znovu.');
            } else {
                $_SESSION['backup_codes_show'] = $codes;
                allstat_flash('ok', t('Máte nové záložní kódy. Dosavadní kódy už neplatí.'));
                allstat_redirect($config, 'admin/setup-2fa.php');
            }
        }
    } elseif ($action === 'reset_own_2fa' && $isAdmin) {
        $error = allstat_confirm_password($pdo, $user, $_POST['password'] ?? null, 'reset_own_2fa');

        if ($error === null) {
            allstat_reset_user_totp($pdo, $userId, $userId);
            allstat_setup_2fa_revoke_mcp($pdo, $userId);
            unset($_SESSION['totp_setup_secret'], $_SESSION['backup_codes_show']);
            allstat_flash('ok', t('2FA pro váš účet je vypnuté. Nastavte ho znovu s novým telefonem nebo aplikací.'));
            allstat_redirect($config, 'admin/setup-2fa.php');
        }
    }
}

// Kódy z tohoto požadavku se ukážou jen teď, po obnovení stránky už ne.
$shownCodes = is_array($_SESSION['backup_codes_show'] ?? null) ? $_SESSION['backup_codes_show'] : [];
unset($_SESSION['backup_codes_show']);
$hasTotp = allstat_user_has_totp($user);
$remaining = $hasTotp ? allstat_backup_codes_remaining($pdo, $userId) : 0;

if ($shownCodes) {
    // Stránku s kódy nesmí prohlížeč ani proxy uložit (tlačítko Zpět, sdílený počítač).
    header('Cache-Control: no-store');
}

allstat_admin_header(t('Dvoufaktorové ověření'), '2fa', $user, $config);
?>
<section class="card">
    <div class="card-head">
        <div>
            <h2 class="card-title"><?= h(t('2FA pro váš účet')) ?></h2>
            <p class="card-desc"><?= h(t('Podporuje aplikace jako Microsoft Authenticator, Google Authenticator, 1Password nebo Bitwarden.')) ?></p>
        </div>
        <?= allstat_status_pill($hasTotp ? 'ok' : 'warning', $hasTotp ? t('Zapnuto') : t('Čeká na nastavení')) ?>
    </div>
    <div class="card-body section">
        <?php if ($error): ?><div class="callout tone-down" role="alert"><?= allstat_icon('info', 'icon-sm') ?><div><?= h($error) ?></div></div><?php endif; ?>
        <?php if ($hasTotp): ?>
            <p class="field-help"><?= h($isAdmin ? t('2FA je pro tento účet aktivní.') : t('2FA je pro tento účet aktivní. Reset může provést administrátor na stránce uživatelů.')) ?></p>
        <?php else: ?>
            <div class="card"><div class="card-body"><div id="totpQr"></div></div></div>
            <code class="code" data-totp-uri="<?= h($uri) ?>"><?= h($secret) ?></code>
            <form method="post" class="form-grid">
                <?= allstat_csrf_field() ?>
                <label class="field field-full"><span class="field-label"><?= h(t('Ověřovací kód')) ?></span><input class="input input-code" type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code" spellcheck="false" data-otp></label>
                <div class="form-actions field-full">
                    <button class="btn btn-primary" type="submit"><?= h(t('Zapnout 2FA')) ?></button>
                    <button class="btn btn-secondary" type="submit" name="reset_pending_secret" value="1" formnovalidate><?= h(t('Vygenerovat nový kód')) ?></button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php if ($hasTotp): ?>
<section class="card" id="backup-codes">
    <div class="card-head">
        <div>
            <h2 class="card-title"><?= h(t('Záložní kódy')) ?></h2>
            <p class="card-desc"><?= h(t('Když přijdete o telefon, přihlásíte se záložním kódem místo kódu z aplikace. Každý kód platí jen jednou.')) ?></p>
        </div>
        <?php if (!$shownCodes): ?><?= allstat_status_pill($remaining > ALLSTAT_BACKUP_CODE_LOW ? 'ok' : 'warning', t('Zbývá {count} z {total}', ['count' => $remaining, 'total' => ALLSTAT_BACKUP_CODE_COUNT])) ?><?php endif; ?>
    </div>
    <div class="card-body section">
        <?php if ($shownCodes): ?>
            <div class="callout tone-warn" role="status"><?= allstat_icon('info', 'icon-sm') ?><div><?= h(t('Uložte si kódy hned, třeba do správce hesel nebo vytiskněte. Znovu se už neukážou.')) ?></div></div>
            <ol class="backup-codes" data-backup-codes><?php foreach ($shownCodes as $code): ?><li><code><?= h($code) ?></code></li><?php endforeach; ?></ol>
            <div class="form-actions">
                <button class="btn btn-secondary" type="button" data-backup-copy data-done="<?= h(t('Zkopírováno')) ?>"><?= allstat_icon('copy', 'icon-sm') ?><span><?= h(t('Kopírovat')) ?></span></button>
                <button class="btn btn-secondary" type="button" data-backup-download="<?= h('allstat-zalozni-kody-' . preg_replace('/[^a-z0-9.-]+/i', '-', (string) $user['email']) . '.txt') ?>" data-title="<?= h(t('Záložní kódy AllStatu pro {email}', ['email' => $user['email']])) ?>"><?= allstat_icon('download', 'icon-sm') ?><span><?= h(t('Stáhnout .txt')) ?></span></button>
            </div>
        <?php elseif ($remaining === 0): ?>
            <div class="callout tone-warn" role="status"><?= allstat_icon('info', 'icon-sm') ?><div><?= h(t('Nemáte žádné záložní kódy. Vygenerujte si je, jinak se po ztrátě telefonu nepřihlásíte.')) ?></div></div>
        <?php elseif ($remaining <= ALLSTAT_BACKUP_CODE_LOW): ?>
            <div class="callout tone-warn" role="status"><?= allstat_icon('info', 'icon-sm') ?><div><?= h(t('Zbývá vám málo záložních kódů. Vygenerujte si nové.')) ?></div></div>
        <?php endif; ?>
        <form method="post" class="form-grid" data-confirm="<?= h($remaining > 0 ? t('Vygenerovat nové záložní kódy? Dosavadní kódy přestanou platit.') : t('Vygenerovat záložní kódy?')) ?>">
            <?= allstat_csrf_field() ?>
            <input type="hidden" name="action" value="regenerate_backup_codes">
            <label class="field"><span class="field-label"><?= h(t('Vaše heslo')) ?></span><input class="input" type="password" name="password" required autocomplete="current-password"></label>
            <div class="form-actions field-full"><button class="btn btn-secondary" type="submit"><?= h($remaining > 0 || $shownCodes ? t('Vygenerovat nové kódy') : t('Vygenerovat záložní kódy')) ?></button></div>
        </form>
    </div>
</section>

<?php if ($isAdmin): ?>
<section class="card" id="reset-2fa">
    <div class="card-head">
        <div>
            <h2 class="card-title"><?= h(t('Reset 2FA')) ?></h2>
            <p class="card-desc"><?= h(t('Vypne 2FA pro váš účet, třeba při výměně telefonu. Záložní kódy přestanou platit a připojení AI se odpojí. Pak 2FA nastavíte znovu.')) ?></p>
        </div>
    </div>
    <div class="card-body section">
        <form method="post" class="form-grid" data-confirm="<?= h(t('Opravdu vypnout 2FA pro váš účet?')) ?>">
            <?= allstat_csrf_field() ?>
            <input type="hidden" name="action" value="reset_own_2fa">
            <label class="field"><span class="field-label"><?= h(t('Vaše heslo')) ?></span><input class="input" type="password" name="password" required autocomplete="current-password"></label>
            <div class="form-actions field-full"><button class="btn btn-danger" type="submit"><?= h(t('Resetovat 2FA')) ?></button></div>
        </form>
    </div>
</section>
<?php endif; ?>
<?php endif; ?>
<?php $nonce = allstat_nonce(); ?>
<script src="<?= h(allstat_asset_url($config, 'assets/js/otp.js')) ?>" nonce="<?= h($nonce) ?>"></script>
<?php if (!$hasTotp): ?>
<script src="<?= h(allstat_url($config, 'assets/vendor/qrcode.min.js')) ?>?v=<?= @filemtime(__DIR__ . '/../assets/vendor/qrcode.min.js') ?: '1' ?>" nonce="<?= h($nonce) ?>"></script>
<script nonce="<?= h($nonce) ?>">
    (function () {
        var uri = document.querySelector('[data-totp-uri]');
        var box = document.getElementById('totpQr');
        if (!uri || !box || typeof qrcode !== 'function') { return; }
        var qr = qrcode(0, 'M');
        qr.addData(uri.dataset.totpUri);
        qr.make();
        box.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2 });
    })();
</script>
<?php endif; ?>
<?php allstat_admin_footer($config); ?>
