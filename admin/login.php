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

    $error = $result['message'];
}

?><!doctype html>
<html lang="cs" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Přihlášení | AllStat</title>
    <script nonce="<?= h(allstat_nonce()) ?>">document.documentElement.dataset.theme = localStorage.getItem('allstat-theme') || 'light';</script>
    <link rel="stylesheet" href="<?= h(allstat_url($config, 'assets/css/app.css')) ?>">
    <link rel="stylesheet" href="<?= h(allstat_url($config, 'assets/css/admin.css')) ?>">
</head>
<body>
    <main class="auth-shell">
        <section class="auth-card">
            <div class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></div>
            <h1>Přihlášení</h1>
            <p>Administrace AllStat je chráněná účtem a případně jednorázovým 2FA kódem.</p>
            <?php if ($error): ?><div class="notice notice-error"><?= h($error) ?></div><?php endif; ?>
            <form method="post" class="form-stack">
                <?= allstat_csrf_field() ?>
                <input type="hidden" name="next" value="<?= h($next) ?>">
                <label><span>E-mail</span><input type="text" name="email" required inputmode="email" autocomplete="username" autofocus></label>
                <label><span>Heslo</span><input type="password" name="password" required autocomplete="current-password"></label>
                <button class="button-primary" type="submit">Přihlásit</button>
            </form>
        </section>
    </main>
    <?php $nonce = allstat_nonce(); ?>
    <script src="<?= h(allstat_url($config, 'assets/vendor/lucide.min.js')) ?>" nonce="<?= h($nonce) ?>"></script>
    <script src="<?= h(allstat_url($config, 'assets/js/admin.js')) ?>" nonce="<?= h($nonce) ?>"></script>
</body>
</html>
