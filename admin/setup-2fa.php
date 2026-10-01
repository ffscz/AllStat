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

$pdo = allstat_admin_require_database($pdo, $config);
$user = allstat_require_user($pdo, $config);
$error = null;
$success = null;

if (!allstat_user_has_totp($user) && empty($_SESSION['totp_setup_secret'])) {
    $_SESSION['totp_setup_secret'] = allstat_totp_generate_secret();
}

$secret = (string) ($_SESSION['totp_setup_secret'] ?? '');
$uri = $secret !== '' ? allstat_totp_uri($secret, $config['app']['name'], $user['email']) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    allstat_csrf_check();

    if (isset($_POST['reset_pending_secret'])) {
        $_SESSION['totp_setup_secret'] = allstat_totp_generate_secret();
        allstat_redirect($config, 'admin/setup-2fa.php');
    }

    if (!allstat_user_has_totp($user)) {
        $result = allstat_setup_user_totp($pdo, $config, (int) $user['id'], $secret, (string) ($_POST['code'] ?? ''));

        if ($result['ok']) {
            unset($_SESSION['totp_setup_secret']);
            allstat_flash('ok', $result['message']);
            if (function_exists('allstat_mcp_revoke_user')) {
                try {
                    allstat_mcp_revoke_user($pdo, (int) $user['id']);
                } catch (Throwable $exception) {
                    error_log('AllStat 2FA: odpojení MCP připojení uživatele ' . (int) $user['id'] . ' selhalo: ' . $exception->getMessage());
                    allstat_flash('error', 'Připojení AI se nepodařilo odpojit. Odpojte je ručně v Administrace → AI konektory (jen pro administrátory).');
                }
            }
            allstat_redirect($config, '');
        }

        $error = $result['message'];
    }
}

allstat_admin_header('Dvoufaktorové ověření', '2fa', $user, $config);
?>
<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2>2FA pro váš účet</h2>
            <p>Podporuje aplikace jako Microsoft Authenticator, Google Authenticator, 1Password nebo Bitwarden.</p>
        </div>
        <span class="secret-state <?= allstat_user_has_totp($user) ? 'on' : 'warn' ?>"><?= allstat_user_has_totp($user) ? 'Zapnuto' : 'Čeká na nastavení' ?></span>
    </div>
    <div class="admin-card-body">
        <?php if ($error): ?><div class="notice notice-error"><?= h($error) ?></div><?php endif; ?>
        <?php if (allstat_user_has_totp($user)): ?>
            <p class="form-help">2FA je pro tento účet aktivní. Reset může provést administrátor na stránce uživatelů.</p>
        <?php else: ?>
            <div class="qr-box"><div id="totpQr"></div></div>
            <div class="code-box" data-totp-uri="<?= h($uri) ?>"><?= h($secret) ?></div>
            <form method="post" class="form-stack" style="margin-top:16px">
                <?= allstat_csrf_field() ?>
                <label><span>Ověřovací kód</span><input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code"></label>
                <div class="form-actions">
                    <button class="button-primary" type="submit">Zapnout 2FA</button>
                    <button class="button-secondary" type="submit" name="reset_pending_secret" value="1">Vygenerovat nový kód</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php if (!allstat_user_has_totp($user)): ?>
<?php $nonce = allstat_nonce(); ?>
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
