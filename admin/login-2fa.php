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

    $error = $result['message'];
}

?><!doctype html>
<html lang="cs" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>2FA ověření | AllStat</title>
    <script nonce="<?= h(allstat_nonce()) ?>">document.documentElement.dataset.theme = localStorage.getItem('allstat-theme') || 'light';</script>
    <link rel="stylesheet" href="<?= h(allstat_url($config, 'assets/css/app.css')) ?>">
    <link rel="stylesheet" href="<?= h(allstat_url($config, 'assets/css/admin.css')) ?>">
</head>
<body>
    <main class="auth-shell">
        <section class="auth-card">
            <div class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></div>
            <h1>2FA ověření</h1>
            <p>Zadejte šestimístný kód z autentizační aplikace pro účet <?= h($user['email']) ?>.</p>
            <?php if ($error): ?><div class="notice notice-error"><?= h($error) ?></div><?php endif; ?>
            <form method="post" class="form-stack">
                <?= allstat_csrf_field() ?>
                <input type="hidden" name="next" value="<?= h($next) ?>">
                <label><span>Ověřovací kód</span><input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus autocomplete="one-time-code"></label>
                <button class="button-primary" type="submit">Ověřit</button>
            </form>
        </section>
    </main>
</body>
</html>
