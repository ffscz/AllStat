<?php
/**
 * Přehled pro uživatele s přístupem jen k vybraným webům, který zatím žádný aktivní web nemá (index.php).
 * V rozsahu: $config, h(), allstat_url(), allstat_nonce(), allstat_stylesheets(), allstat_icon().
 */
?><!doctype html>
<html lang="<?= h(allstat_locale()) ?>" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h(t('Bez přístupu')) ?> | <?= h($config['app']['name']) ?></title>
    <script nonce="<?= h(allstat_nonce()) ?>">document.documentElement.dataset.theme = localStorage.getItem('allstat-theme') || 'light';</script>
    <?= allstat_stylesheets($config, []) ?>
</head>
<body>
    <main class="auth">
        <section class="card auth-card">
            <a class="logo auth-logo" href="<?= h(allstat_url($config, '')) ?>" aria-label="AllStat">
                <svg class="logo-mark" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="13" width="4.5" height="8" rx="1.5" fill="currentColor" opacity=".45"/><rect x="9.75" y="7" width="4.5" height="14" rx="1.5" fill="currentColor" opacity=".7"/><rect x="16.5" y="3" width="4.5" height="18" rx="1.5" fill="currentColor"/></svg>
                <span class="logo-name">AllStat</span>
            </a>
            <div class="empty auth-empty">
                <span class="empty-icon tone-flat"><?= allstat_icon('globe-2', 'icon') ?></span>
                <h1 class="empty-title"><?= h(t('Zatím nemáte přidělený web')) ?></h1>
                <p class="empty-desc"><?= h(t('Účet je aktivní, ale administrátor vám zatím nepřidělil žádný web, nebo jsou přidělené weby vypnuté. Požádejte ho o přístup, pak se tu přehled objeví sám.')) ?></p>
            </div>
            <a class="btn btn-secondary" href="<?= h(allstat_url($config, 'admin/logout.php')) ?>"><?= allstat_icon('log-out', 'icon-sm') ?><span><?= h(t('Odhlásit')) ?></span></a>
        </section>
    </main>
</body>
</html>
