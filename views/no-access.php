<?php
/**
 * Přehled pro uživatele s přístupem jen k vybraným webům, který zatím žádný aktivní web nemá (index.php).
 * V rozsahu: $config, h(), allstat_url(), allstat_nonce().
 */
?><!doctype html>
<html lang="cs" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bez přístupu | <?= h($config['app']['name']) ?></title>
    <script nonce="<?= h(allstat_nonce()) ?>">document.documentElement.dataset.theme = localStorage.getItem('allstat-theme') || 'light';</script>
    <link rel="stylesheet" href="<?= h(allstat_url($config, 'assets/css/app.css')) ?>">
    <link rel="stylesheet" href="<?= h(allstat_url($config, 'assets/css/admin.css')) ?>">
</head>
<body>
    <main class="auth-shell">
        <section class="auth-card">
            <div class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></div>
            <h1>Zatím nemáte přidělený web</h1>
            <p>Účet je aktivní, ale administrátor vám zatím nepřidělil žádný web, nebo jsou přidělené weby vypnuté. Požádejte ho o přístup, pak se tu přehled objeví sám.</p>
            <a class="button-secondary" href="<?= h(allstat_url($config, 'admin/logout.php')) ?>">Odhlásit</a>
        </section>
    </main>
</body>
</html>
