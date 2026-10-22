<?php

require __DIR__ . '/_bootstrap.php';

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
    <link rel="stylesheet" href="<?= h(allstat_url($config, 'assets/css/app.css')) ?>">
    <link rel="stylesheet" href="<?= h(allstat_url($config, 'assets/css/admin.css')) ?>">
</head>
<body>
    <main class="auth-shell">
        <section class="auth-card">
            <div class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></div>
            <h1><?= h(t('2FA ověření')) ?></h1>
            <p><?= h(t('Zadejte šestimístný kód z autentizační aplikace pro účet {email}.', ['email' => $user['email']])) ?></p>
            <?php if ($error): ?><div class="notice notice-error"><?= h($error) ?></div><?php endif; ?>
            <?php if ($locked): ?>
            <p><a class="button-primary" href="<?= h(allstat_url($config, 'admin/login.php')) ?>"><?= h(t('Zpět na přihlášení')) ?></a></p>
            <?php else: ?>
            <form method="post" class="form-stack">
                <?= allstat_csrf_field() ?>
                <input type="hidden" name="next" value="<?= h($next) ?>">
                <label><span><?= h(t('Ověřovací kód')) ?></span><input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus autocomplete="one-time-code"></label>
                <button class="button-primary" type="submit"><?= h(t('Ověřit')) ?></button>
            </form>
            <?php endif; ?>
            <?= allstat_locale_switcher($config) ?>
        </section>
    </main>
    <script src="<?= h(allstat_url($config, 'assets/js/i18n.js')) ?>" nonce="<?= h(allstat_nonce()) ?>"></script>
</body>
</html>
