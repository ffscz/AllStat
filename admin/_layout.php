<?php

function allstat_flash(string $type, string $message): void
{
    $_SESSION['flashes'][] = ['type' => $type, 'message' => $message];
}

function allstat_take_flashes(): array
{
    $flashes = $_SESSION['flashes'] ?? [];
    unset($_SESSION['flashes']);

    return is_array($flashes) ? $flashes : [];
}

function allstat_admin_header(string $title, string $active, array $user, array $config): void
{
    $mainItems = [
        ['key' => 'dashboard', 'label' => 'Přehled', 'icon' => 'home', 'href' => allstat_url($config, '')],
        ['key' => 'domains', 'label' => 'Weby', 'icon' => 'globe-2', 'href' => allstat_url($config, 'admin/domains.php')],
        ['key' => 'sources', 'label' => 'Zdroje dat', 'icon' => 'database', 'href' => allstat_url($config, 'admin/sources.php')],
        ['key' => 'metrics', 'label' => 'Metriky', 'icon' => 'line-chart', 'href' => allstat_url($config, 'admin/metrics.php')],
        ['key' => 'funnels', 'label' => 'Trychtýře', 'icon' => 'filter', 'href' => allstat_url($config, 'admin/funnels.php')],
        ['key' => 'reports', 'label' => 'Reporty', 'icon' => 'clipboard-list', 'href' => allstat_url($config, 'admin/reports.php')],
        ['key' => 'feeds', 'label' => 'Datový feed', 'icon' => 'sheet', 'href' => allstat_url($config, 'admin/feeds.php')],
    ];
    $adminItems = [
        ['key' => 'settings', 'label' => 'Nastavení', 'icon' => 'settings', 'href' => allstat_url($config, 'admin/settings.php')],
        ['key' => 'users', 'label' => 'Uživatelé', 'icon' => 'users', 'href' => allstat_url($config, 'admin/users.php')],
        ['key' => '2fa', 'label' => '2FA', 'icon' => 'shield-check', 'href' => allstat_url($config, 'admin/setup-2fa.php')],
        ['key' => 'logout', 'label' => 'Odhlásit', 'icon' => 'log-out', 'href' => allstat_url($config, 'admin/logout.php')],
    ];
    // AI konektory (MCP) vidí jen administrátor, stejně jako samotnou stránku.
    if (($user['role'] ?? '') === 'admin') {
        array_splice($adminItems, 1, 0, [['key' => 'mcp', 'label' => 'AI konektory', 'icon' => 'plug-zap', 'href' => allstat_url($config, 'admin/mcp.php')]]);
    } else {
        // Běžný uživatel má jen přehled, reporty a svůj účet; správa (weby, zdroje, metriky, feed, nastavení,
        // uživatelé) je jen pro administrátora a stránky by mu vrátily 403.
        $userKeys = ['dashboard', 'reports', '2fa', 'logout'];
        $mainItems = array_values(array_filter($mainItems, static fn (array $item): bool => in_array($item['key'], $userKeys, true)));
        $adminItems = array_values(array_filter($adminItems, static fn (array $item): bool => in_array($item['key'], $userKeys, true)));
    }
    ?><!doctype html>
<html lang="cs" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> | <?= h($config['app']['name']) ?></title>
    <script nonce="<?= h(allstat_nonce()) ?>">document.documentElement.dataset.theme = localStorage.getItem('allstat-theme') || 'light';</script>
    <link rel="stylesheet" href="<?= h(allstat_url($config, 'assets/css/app.css')) ?>?v=<?= @filemtime(__DIR__ . '/../assets/css/app.css') ?: '1' ?>">
    <link rel="stylesheet" href="<?= h(allstat_url($config, 'assets/css/admin.css')) ?>?v=<?= @filemtime(__DIR__ . '/../assets/css/admin.css') ?: '1' ?>">
</head>
<body>
    <div class="admin-shell" data-admin-shell>
        <aside class="admin-sidebar" data-admin-sidebar>
            <a class="brand" href="<?= h(allstat_url($config, '')) ?>" aria-label="AllStat">
                <span class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></span>
                <span>AllStat</span>
            </a>
            <nav class="sidebar-nav admin-nav">
                <?php foreach ($mainItems as $item): ?>
                    <a class="<?= $active === $item['key'] ? 'active' : '' ?>" href="<?= h($item['href']) ?>"><?= allstat_icon($item['icon']) ?><span><?= h($item['label']) ?></span></a>
                <?php endforeach; ?>
                <span class="nav-divider"></span>
                <?php foreach ($adminItems as $item): ?>
                    <a class="<?= $active === $item['key'] ? 'active' : '' ?>" href="<?= h($item['href']) ?>"><?= allstat_icon($item['icon']) ?><span><?= h($item['label']) ?></span></a>
                <?php endforeach; ?>
            </nav>
            <?php
            // Upozornění na novou verzi (jen administrátor, jen instalace z veřejného balíčku; čte se z cache).
            $updateNotice = null;
            if (($user['role'] ?? '') === 'admin' && ($GLOBALS['pdo'] ?? null) instanceof PDO && is_file(__DIR__ . '/../lib/updater.php')) {
                require_once __DIR__ . '/../lib/updater.php';
                $updateNotice = allstat_update_notice($GLOBALS['pdo'], $user);
            }
            ?>
            <?php if ($updateNotice && $active !== 'update'): ?>
            <a class="update-card" href="<?= h(allstat_url($config, 'admin/update.php')) ?>">
                <i data-lucide="download" aria-hidden="true"></i>
                <span><strong>Nová verze <?= h($updateNotice['version']) ?></strong><small>Máš <?= h($updateNotice['installed']) ?>, klikni pro aktualizaci</small></span>
            </a>
            <?php endif; ?>
            <?php require_once __DIR__ . '/../lib/version.php'; ?>
            <span class="sidebar-version">AllStat <?= h(allstat_app_version()) ?></span>
        </aside>
        <div class="nav-backdrop" data-admin-backdrop aria-hidden="true"></div>
        <main class="admin-main">
            <header class="admin-topbar">
                <div class="topbar-title">
                    <button class="icon-button nav-toggle" type="button" data-sidebar-toggle title="Menu" aria-label="Menu" aria-expanded="false"><i data-lucide="menu"></i></button>
                    <div>
                        <h1><?= h($title) ?></h1>
                        <p><?= h($user['name'] ?? $user['email'] ?? '') ?> · <?= h($user['role'] ?? '') ?></p>
                    </div>
                    <span class="title-actions">
                        <button class="avatar-button" id="themeToggle" type="button" title="Motiv" aria-label="Motiv"><i data-lucide="moon"></i></button>
                    </span>
                </div>
            </header>
            <section class="admin-content">
                <?php if (function_exists('allstat_crypto_uses_default_key') && allstat_crypto_uses_default_key($config) && ($user['role'] ?? '') === 'admin'): ?>
                    <div class="notice notice-critical">
                        <strong>⚠ Kritické riziko bezpečnosti:</strong>
                        Šifrovací klíč je výchozí (`allstat-local-dev-key-change-me`). Všechny tokeny v DB jsou dešifrovatelné kýmkoli s přístupem ke zdrojovému kódu.
                        <a href="<?= h(allstat_url($config, 'admin/security.php')) ?>">Rotovat klíč hned →</a>
                    </div>
                <?php endif; ?>
                <?php foreach (allstat_take_flashes() as $flash): ?>
                    <div class="notice <?= ($flash['type'] ?? '') === 'error' ? 'notice-error' : 'notice-ok' ?>"><?= h($flash['message'] ?? '') ?></div>
                <?php endforeach; ?>
    <?php
}

function allstat_admin_footer(array $config): void
{
    ?>
            </section>
        </main>
    </div>
    <?php $nonce = allstat_nonce(); ?>
    <script src="<?= h(allstat_url($config, 'assets/vendor/lucide.min.js')) ?>?v=<?= @filemtime(__DIR__ . '/../assets/vendor/lucide.min.js') ?: '1' ?>" nonce="<?= h($nonce) ?>"></script>
    <script src="<?= h(allstat_url($config, 'assets/js/admin.js')) ?>?v=<?= @filemtime(__DIR__ . '/../assets/js/admin.js') ?: '1' ?>" nonce="<?= h($nonce) ?>"></script>
    <script src="<?= h(allstat_url($config, 'assets/js/select-search.js')) ?>?v=<?= @filemtime(__DIR__ . '/../assets/js/select-search.js') ?: '1' ?>" nonce="<?= h($nonce) ?>"></script>
    <script src="<?= h(allstat_url($config, 'assets/js/table-sort.js')) ?>?v=<?= @filemtime(__DIR__ . '/../assets/js/table-sort.js') ?: '1' ?>" nonce="<?= h($nonce) ?>"></script>
</body>
</html>
    <?php
}

function allstat_status_badge(string $status): string
{
    $labels = ['ok' => 'OK', 'warning' => 'Varování', 'error' => 'Chyba', 'info' => 'Info'];
    $safeStatus = h($status);

    return '<span class="status-badge status-' . $safeStatus . '">' . h($labels[$status] ?? $status) . '</span>';
}

function allstat_datetime_local(?string $value): string
{
    if (!$value) {
        return '';
    }

    try {
        return (new DateTimeImmutable($value))->format('Y-m-d\TH:i');
    } catch (Throwable) {
        return '';
    }
}
