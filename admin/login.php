<?php

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_layout.php';

$pdo = allstat_admin_require_database($pdo, $config);

if (allstat_user_count($pdo) === 0) {
    allstat_redirect($config, 'install.php');
}

$next = (string) ($_GET['next'] ?? allstat_url($config, ''));
if (!str_starts_with($next, allstat_base_path($config))) {
    $next = allstat_url($config, '');
}

if (allstat_is_authenticated($pdo)) {
    header('Location: ' . $next);
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    allstat_csrf_check();
    $next = (string) ($_POST['next'] ?? $next);
    if (!str_starts_with($next, allstat_base_path($config))) {
        $next = allstat_url($config, '');
    }

    $result = allstat_login($pdo, $config, (string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));

    if ($result['ok']) {
        if (!empty($result['requires_totp'])) {
            allstat_redirect($config, 'admin/login-2fa.php?next=' . urlencode($next));
        }

        header('Location: ' . $next);
        exit;
    }

    $error = t($result['message']);
}

?><!doctype html>
<html lang="<?= h(allstat_locale()) ?>"<?= allstat_i18n_js_attribute() ?> data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h(t('Přihlášení')) ?> | AllStat</title>
    <script nonce="<?= h(allstat_nonce()) ?>">document.documentElement.dataset.theme = localStorage.getItem('allstat-theme') || 'light';</script>
    <?= allstat_stylesheets($config) ?>
</head>
<body>
    <main class="auth">
        <section class="card auth-card">
            <a class="logo" href="<?= h(allstat_url($config, '')) ?>" aria-label="AllStat">
                <svg class="logo-mark" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="13" width="4.5" height="8" rx="1.5" fill="currentColor" opacity=".45"/><rect x="9.75" y="7" width="4.5" height="14" rx="1.5" fill="currentColor" opacity=".7"/><rect x="16.5" y="3" width="4.5" height="18" rx="1.5" fill="currentColor"/></svg>
                <span class="logo-name">AllStat</span>
            </a>
            <div class="auth-head">
                <h1 class="h1"><?= h(t('Přihlášení')) ?></h1>
                <p class="auth-desc"><?= h(t('Administrace AllStat je chráněná účtem a případně jednorázovým 2FA kódem.')) ?></p>
            </div>
            <?php if ($error): ?><div class="callout tone-down" role="alert"><?= allstat_icon('info', 'icon-sm') ?><span><?= h($error) ?></span></div><?php endif; ?>
            <form method="post" class="auth-form">
                <?= allstat_csrf_field() ?>
                <input type="hidden" name="next" value="<?= h($next) ?>">
                <label class="field"><span class="field-label"><?= h(t('E-mail')) ?></span><input class="input" type="text" name="email" required inputmode="email" autocomplete="username" autofocus></label>
                <label class="field"><span class="field-label"><?= h(t('Heslo')) ?></span><input class="input" type="password" name="password" required autocomplete="current-password"></label>
                <button class="btn btn-primary" type="submit"><?= h(t('Přihlásit')) ?></button>
            </form>
            <?= allstat_locale_switcher($config) ?>
        </section>
    </main>
    <script src="<?= h(allstat_asset_url($config, 'assets/js/i18n.js')) ?>" nonce="<?= h(allstat_nonce()) ?>"></script>
</body>
</html>
