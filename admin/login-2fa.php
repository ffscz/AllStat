<?php

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';

$pdo = allstat_admin_require_database($pdo, $config);
$user = allstat_current_user($pdo);

if (!$user) {
    allstat_redirect($config, 'admin/login.php');
}

if (empty($user['totp_secret_enc'])) {
    allstat_redirect($config, '');
}

$next = (string) ($_GET['next'] ?? allstat_url($config, ''));
if (!str_starts_with($next, allstat_base_path($config))) {
    $next = allstat_url($config, '');
}

$error = null;
$locked = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    allstat_csrf_check();
    $next = (string) ($_POST['next'] ?? $next);
    if (!str_starts_with($next, allstat_base_path($config))) {
        $next = allstat_url($config, '');
    }

    $result = allstat_complete_totp_login($pdo, $config, (string) ($_POST['code'] ?? ''));

    if ($result['ok']) {
        header('Location: ' . $next);
        exit;
    }

    $error = t($result['message']);
    // Po vyčerpání pokusů je session odhlášená, formulář by jen poslal na přihlášení.
    $locked = !empty($result['throttled']);
}

?><!doctype html>
<html lang="<?= h(allstat_locale()) ?>"<?= allstat_i18n_js_attribute() ?> data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h(t('2FA ověření')) ?> | AllStat</title>
    <script nonce="<?= h(allstat_nonce()) ?>">document.documentElement.dataset.theme = localStorage.getItem('allstat-theme') || 'light';</script>
    <?= allstat_stylesheets($config) ?>
</head>
<body>
    <main class="auth">
        <section class="card auth-card">
            <a class="logo auth-logo" href="<?= h(allstat_url($config, '')) ?>" aria-label="AllStat">
                <svg class="logo-mark" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="13" width="4.5" height="8" rx="1.5" fill="currentColor" opacity=".45"/><rect x="9.75" y="7" width="4.5" height="14" rx="1.5" fill="currentColor" opacity=".7"/><rect x="16.5" y="3" width="4.5" height="18" rx="1.5" fill="currentColor"/></svg>
                <span class="logo-name">AllStat</span>
            </a>
            <div class="auth-head">
                <h1 class="h1"><?= h(t('2FA ověření')) ?></h1>
                <p class="auth-desc"><?= h(t('Zadejte šestimístný kód z autentizační aplikace pro účet {email}.', ['email' => $user['email']])) ?></p>
            </div>
            <?php if ($error): ?><div class="callout tone-down" role="alert"><?= allstat_icon('info', 'icon-sm') ?><span><?= h($error) ?></span></div><?php endif; ?>
            <?php if ($locked): ?>
            <a class="btn btn-primary" href="<?= h(allstat_url($config, 'admin/login.php')) ?>"><?= h(t('Zpět na přihlášení')) ?></a>
            <?php else: ?>
            <form method="post" class="auth-form">
                <?= allstat_csrf_field() ?>
                <input type="hidden" name="next" value="<?= h($next) ?>">
                <label class="field"><span class="field-label"><?= h(t('Ověřovací kód')) ?></span><input class="input input-code" type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus autocomplete="one-time-code"></label>
                <button class="btn btn-primary" type="submit"><?= h(t('Ověřit')) ?></button>
            </form>
            <?php endif; ?>
            <?= allstat_locale_switcher($config) ?>
        </section>
    </main>
    <script src="<?= h(allstat_asset_url($config, 'assets/js/i18n.js')) ?>" nonce="<?= h(allstat_nonce()) ?>"></script>
</body>
</html>
