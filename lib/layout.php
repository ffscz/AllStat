<?php

/**
 * Společný shell AllStatu: hlavička dokumentu, postranní panel (logo, přepínač webu, navigace, verze)
 * a horní lišta (drobečky, stav dat, nápověda, motiv, uživatelská nabídka). Stejný pro přehled
 * (index.php) i správu (admin/_layout.php), obsah stránky jde do <main class="page">.
 *
 * Styly: assets/css/tokens.css, base.css, layout.css, components.css. Chování nabídek a motivu:
 * assets/js/shell.js.
 */

require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/csrf.php';
// Updater je jen ve veřejném balíčku; interní instalace ho mít nemusí.
if (is_file(__DIR__ . '/updater.php')) {
    require_once __DIR__ . '/updater.php';
}

/** Adresa souboru z assets/ s otiskem změny, ať prohlížeč po aktualizaci nevezme starou kopii z cache. */
function allstat_asset_url(array $config, string $path): string
{
    $mtime = @filemtime(__DIR__ . '/../' . $path);

    return allstat_url($config, $path) . '?v=' . ($mtime ?: '1');
}

/** Styly stránky: tokeny, základ, rozvržení a komponenty (stejné pro všechny stránky). */
function allstat_stylesheets(array $config): string
{
    $html = '';
    foreach (['tokens', 'base', 'layout', 'components'] as $name) {
        $html .= '<link rel="stylesheet" href="' . h(allstat_asset_url($config, 'assets/css/' . $name . '.css')) . '">' . "\n";
    }

    return $html;
}

/**
 * Zkratka a barva služby pro dlaždici (.tile .src-*). Neznámá služba dostane první dvě písmena a akcent.
 *
 * @return array{code: string, class: string}
 */
function allstat_source_badge(string $providerKey): array
{
    $map = [
        'ga4' => ['GA', 'src-ga'],
        'gsc' => ['SC', 'src-sc'],
        'clarity' => ['CL', 'src-cl'],
        'facebook_pages' => ['FB', 'src-fb'],
        'instagram_business' => ['IG', 'src-ig'],
        'linkedin_company' => ['LI', 'src-li'],
        'youtube' => ['YT', 'src-yt'],
        'meta_ads' => ['MA', 'src-ma'],
        'google_ads' => ['AD', 'src-ad'],
        'microsoft_ads' => ['MS', 'src-ms'],
        'seznam_wmt' => ['SZ', 'src-sz'],
    ];
    [$code, $class] = $map[$providerKey] ?? [strtoupper(substr($providerKey, 0, 2)), ''];

    return ['code' => $code, 'class' => $class];
}

/** Dlaždice se zkratkou služby; $size = '' (36 px) | xs | sm | md | lg. */
function allstat_source_tile(string $providerKey, string $size = ''): string
{
    $badge = allstat_source_badge($providerKey);

    return '<span class="' . h(trim('tile ' . ($size !== '' ? 'tile-' . $size . ' ' : '') . $badge['class'])) . '" aria-hidden="true">' . h($badge['code']) . '</span>';
}

/** Iniciála webu do dlaždice přepínače (první písmeno názvu, jinak adresy). */
function allstat_site_initial(array $domain): string
{
    $name = trim((string) ($domain['name'] ?? '')) ?: (string) preg_replace('~^(https?://)?(www\.)?~i', '', (string) ($domain['url'] ?? ''));

    return mb_strtoupper(mb_substr($name, 0, 1)) ?: '?';
}

/** Iniciály uživatele do avataru (dvě písmena ze jména, jinak z e-mailu). */
function allstat_user_initials(array $user): string
{
    $name = trim((string) ($user['name'] ?? ''));
    if ($name !== '') {
        $parts = preg_split('/\s+/u', $name) ?: [];
        $initials = mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : '');

        return mb_strtoupper($initials);
    }

    return mb_strtoupper(mb_substr((string) ($user['email'] ?? '?'), 0, 2));
}

/**
 * Položky navigace po skupinách. Běžný uživatel má jen přehled, všechny weby a reporty; správa
 * (weby, zdroje, metriky, feed, nastavení, uživatelé, AI konektory) je jen pro administrátora.
 *
 * @return array<int, array{label: string, items: array<int, array{key: string, label: string, icon: string, href: string}>}>
 */
function allstat_nav_groups(array $config, array $user): array
{
    $isAdmin = ($user['role'] ?? '') === 'admin';
    $item = static fn (string $key, string $label, string $icon, string $path): array => ['key' => $key, 'label' => $label, 'icon' => $icon, 'href' => allstat_url($config, $path)];

    $analysis = [
        $item('dashboard', t('Přehled'), 'home', ''),
        $item('sites', t('Všechny weby'), 'layout-grid', 'admin/sites.php'),
    ];
    if ($isAdmin) {
        $analysis[] = $item('funnels', t('Trychtýře'), 'filter', 'admin/funnels.php');
    }
    $analysis[] = $item('reports', t('Reporty'), 'clipboard-list', 'admin/reports.php');
    if ($isAdmin) {
        $analysis[] = $item('feeds', t('Datový feed'), 'sheet', 'admin/feeds.php');
    }

    $groups = [['label' => t('Analýza'), 'items' => $analysis]];
    if ($isAdmin) {
        $groups[] = ['label' => t('Správa'), 'items' => [
            $item('domains', t('Weby'), 'globe-2', 'admin/domains.php'),
            $item('sources', t('Zdroje dat'), 'database', 'admin/sources.php'),
            $item('google', t('Google účty'), 'key-round', 'admin/google-accounts.php'),
            $item('metrics', t('Metriky'), 'line-chart', 'admin/metrics.php'),
            $item('mcp', t('AI konektory'), 'plug-zap', 'admin/mcp.php'),
            $item('users', t('Uživatelé'), 'users', 'admin/users.php'),
            $item('settings', t('Nastavení'), 'settings', 'admin/settings.php'),
        ]];
    }

    return $groups;
}

/** Počet zapnutých napojení každého webu (do přepínače webů). @return array<int, int> */
function allstat_layout_source_counts(PDO $pdo): array
{
    try {
        $counts = [];
        foreach (allstat_fetch_all($pdo, 'SELECT domain_id, COUNT(*) AS n FROM domain_sources WHERE is_enabled = 1 GROUP BY domain_id') as $row) {
            $counts[(int) $row['domain_id']] = (int) $row['n'];
        }

        return $counts;
    } catch (Throwable) {
        return [];
    }
}

/**
 * Stav dat zvoleného webu pro horní lištu: zelená = všechna napojení se ozvala do 48 h, oranžová = některé
 * se zasekly (výpadek cronu musí být vidět), šedá = web zatím nemá napojení.
 *
 * @return array{tone: string, label: string, title: string}
 */
function allstat_layout_sync_status(PDO $pdo, int $domainId): array
{
    try {
        $statuses = allstat_query_source_statuses($pdo, $domainId);
    } catch (Throwable) {
        $statuses = [];
    }
    $enabled = array_filter($statuses, static fn (array $s): bool => !empty($s['enabled']));
    if (!$enabled) {
        return ['tone' => 'is-muted', 'label' => t('Zdroje zatím nejsou napojené'), 'title' => ''];
    }
    $health = allstat_sync_health($statuses);
    if ($health['staleCount'] > 0) {
        $names = array_map(static fn (array $x): string => $x['source'] . ' (' . $x['lastSync'] . ')', $health['stale']);

        return [
            'tone' => 'is-warn',
            'label' => tn($health['staleCount'], '1 zdroj se dlouho neozval', '{count} zdroje se dlouho neozvaly', '{count} zdrojů se dlouho neozvalo'),
            'title' => t('Přes 48 h bez dat: {names}. Zkontroluj cron.', ['names' => implode(', ', $names)]),
        ];
    }

    return [
        'tone' => '',
        'label' => t('Data aktuální · {time}', ['time' => $health['oldestLabel']]),
        'title' => t('Nejstarší sync: {value}', ['value' => $health['oldestLabel']]),
    ];
}

/**
 * Adresa stejné stránky pro jiný web. Parametry vázané na konkrétní web (zdroj, trychtýř, stránkování)
 * se zahodí, období a pohled zůstanou.
 */
function allstat_layout_site_url(int $domainId): string
{
    $query = $_GET;
    unset($query['source_id'], $query['funnel_id'], $query['posts_page'], $query['breakdown']);
    if (($query['view'] ?? '') === 'funnel') {
        unset($query['view']);
    }
    $query['domain_id'] = $domainId;
    $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?') ?: '';

    return $path . '?' . http_build_query($query);
}

/**
 * Začátek stránky: <head>, postranní panel, horní lišta a otevřený <main class="page">. Konec dělá
 * allstat_layout_end(). Volby $page:
 *   title     název stránky (už přeložený), jde do <title>
 *   active    klíč položky navigace (dashboard, sites, sources …)
 *   crumbs    drobečky: [['label' => …, 'href' => … | null], …]; poslední je aktuální stránka
 *   help      kotva kapitoly návodu ('' = začátek návodu)
 *   domainId  zvolený web (null = přepínač ukáže web ze session / primární)
 *   bodyAttr  atributy pro <div class="shell"> (např. 'data-dashboard')
 */
function allstat_layout_start(?PDO $pdo, array $config, array $user, array $page): void
{
    $title = (string) ($page['title'] ?? '');
    $active = (string) ($page['active'] ?? '');
    $isAdmin = ($user['role'] ?? '') === 'admin';
    $domains = $pdo ? allstat_get_domains($pdo) : [];
    // Web zvolený v přepínači (?domain_id=) se zapamatuje i na stránkách, které parametr samy nečtou.
    $domainId = (int) ($page['domainId'] ?? 0);
    if ($pdo && $domains && !in_array($domainId, array_map(static fn (array $d): int => (int) $d['id'], $domains), true)) {
        $domainId = allstat_current_domain_id($pdo, $domains, filter_input(INPUT_GET, 'domain_id', FILTER_VALIDATE_INT) ?: null);
    }
    $current = null;
    foreach ($domains as $d) {
        if ((int) $d['id'] === $domainId) {
            $current = $d;
        }
    }
    $counts = $pdo ? allstat_layout_source_counts($pdo) : [];
    $sourceLabel = static fn (int $id): string => tn($counts[$id] ?? 0, '{count} zdroj', '{count} zdroje', '{count} zdrojů');
    $hostOf = static fn (array $d): string => (string) preg_replace('~^(https?://)?(www\.)?~i', '', rtrim((string) $d['url'], '/'));
    $sync = ($pdo && $current) ? allstat_layout_sync_status($pdo, (int) $current['id']) : null;
    $crumbs = $page['crumbs'] ?? [['label' => $title, 'href' => null]];
    if ($current) {
        array_unshift($crumbs, ['label' => (string) ($current['name'] ?: $hostOf($current)), 'href' => allstat_url($config, '') . '?domain_id=' . (int) $current['id']]);
    }
    $updateNotice = null;
    if ($isAdmin && $pdo && $active !== 'update' && function_exists('allstat_update_notice')) {
        $updateNotice = allstat_update_notice($pdo, $user, $config);
    }
    $roleLabel = $isAdmin ? t('Administrátor') : t('Uživatel');
    $nonce = allstat_nonce();
    ?><!doctype html>
<html lang="<?= h(allstat_locale()) ?>"<?= allstat_i18n_js_attribute() ?> data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> | <?= h($config['app']['name']) ?></title>
    <script nonce="<?= h($nonce) ?>">document.documentElement.dataset.theme = localStorage.getItem('allstat-theme') || 'light';</script>
    <link rel="preload" href="<?= h(allstat_url($config, 'assets/fonts/geist-latin.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <?= allstat_stylesheets($config) ?>
</head>
<body>
<div class="shell" <?= $page['bodyAttr'] ?? '' ?>>
    <aside class="shell-side" aria-label="<?= h(t('Postranní panel')) ?>">
        <a class="logo" href="<?= h(allstat_url($config, '')) ?>" aria-label="AllStat">
            <svg class="logo-mark" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="13" width="4.5" height="8" rx="1.5" fill="currentColor" opacity=".45"/><rect x="9.75" y="7" width="4.5" height="14" rx="1.5" fill="currentColor" opacity=".7"/><rect x="16.5" y="3" width="4.5" height="18" rx="1.5" fill="currentColor"/></svg>
            <span class="logo-name">AllStat</span>
        </a>

        <?php if ($current): ?>
        <div class="site-switch dropdown">
            <button type="button" class="site-switch-btn" aria-haspopup="true" aria-expanded="false" data-menu-toggle="site-menu">
                <span class="tile tile-md"><?= h(allstat_site_initial($current)) ?></span>
                <span class="site-switch-text">
                    <span class="site-switch-name ellipsis"><?= h((string) ($current['name'] ?: $hostOf($current))) ?></span>
                    <span class="site-switch-url ellipsis"><?= h($hostOf($current)) ?> · <?= h($sourceLabel((int) $current['id'])) ?></span>
                </span>
                <?= allstat_icon('chevrons-up-down', 'icon-sm') ?>
            </button>
            <div class="menu menu-wide" id="site-menu" hidden>
                <div class="menu-label"><?= h(t('Weby')) ?></div>
                <?php foreach ($domains as $d): ?>
                <a class="menu-item" href="<?= h(allstat_layout_site_url((int) $d['id'])) ?>"<?= (int) $d['id'] === (int) $current['id'] ? ' aria-current="true"' : '' ?>>
                    <span class="tile tile-sm"><?= h(allstat_site_initial($d)) ?></span>
                    <span class="menu-text"><span class="ellipsis"><?= h((string) ($d['name'] ?: $hostOf($d))) ?></span><span class="menu-sub ellipsis"><?= h($hostOf($d)) ?> · <?= h($sourceLabel((int) $d['id'])) ?></span></span>
                    <?php if ((int) $d['id'] === (int) $current['id']): ?><?= allstat_icon('check', 'menu-check') ?><?php endif; ?>
                </a>
                <?php endforeach; ?>
                <?php if ($isAdmin): ?>
                <div class="menu-sep"></div>
                <a class="menu-item" href="<?= h(allstat_url($config, 'admin/domains.php')) ?>"><?= allstat_icon('globe', 'icon-sm') ?><span><?= h(t('Spravovat weby')) ?></span></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <nav class="nav" aria-label="<?= h(t('Hlavní navigace')) ?>">
            <?php foreach (allstat_nav_groups($config, $user) as $group): ?>
            <div class="nav-group">
                <div class="nav-label"><?= h($group['label']) ?></div>
                <?php foreach ($group['items'] as $item): ?>
                <a class="nav-item" href="<?= h($item['href']) ?>"<?= $item['key'] === $active ? ' aria-current="page"' : '' ?>><?= allstat_icon($item['icon'], 'icon') ?><span><?= h($item['label']) ?></span></a>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </nav>

        <div class="shell-side-foot">
            <?php if ($updateNotice): ?>
            <a class="callout tone-accent" href="<?= h(allstat_url($config, 'admin/update.php')) ?>">
                <?= allstat_icon('download', 'icon-sm') ?>
                <span><strong><?= h(t('Nová verze {version}', ['version' => $updateNotice['version']])) ?></strong><br><?= h(t('Máš {installed}, klikni pro aktualizaci', ['installed' => $updateNotice['installed']])) ?></span>
            </a>
            <?php endif; ?>
            <span>AllStat <?= h(allstat_app_version()) ?></span>
        </div>
    </aside>

    <div class="shell-main">
        <header class="shell-top">
            <nav class="crumbs" aria-label="<?= h(t('Drobečková navigace')) ?>">
                <?php foreach (array_values($crumbs) as $i => $crumb): $isLast = $i === count($crumbs) - 1; ?>
                    <?php if ($i > 0): ?><span class="crumb-sep" aria-hidden="true">/</span><?php endif; ?>
                    <?php if ($isLast || empty($crumb['href'])): ?>
                    <span class="crumb ellipsis"<?= $isLast ? ' aria-current="page"' : '' ?>><?= h((string) $crumb['label']) ?></span>
                    <?php else: ?>
                    <a class="crumb" href="<?= h((string) $crumb['href']) ?>"><?= h((string) $crumb['label']) ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
            <div class="shell-top-end">
                <?php if ($sync): ?>
                    <?php $syncAttrs = ' title="' . h($sync['title']) . '"'; ?>
                    <?php if ($isAdmin): ?>
                    <a class="pill" href="<?= h(allstat_url($config, 'admin/sources.php')) ?>"<?= $syncAttrs ?>><span class="dot <?= h($sync['tone']) ?>"></span><span><?= h($sync['label']) ?></span></a>
                    <?php else: ?>
                    <span class="pill"<?= $syncAttrs ?>><span class="dot <?= h($sync['tone']) ?>"></span><span><?= h($sync['label']) ?></span></span>
                    <?php endif; ?>
                    <span class="vsep" aria-hidden="true"></span>
                <?php endif; ?>
                <?= allstat_help_button((string) ($page['help'] ?? '')) ?>
                <button type="button" class="btn-icon" id="themeToggle" data-theme-toggle aria-pressed="false" title="<?= h(t('Tmavý motiv')) ?>" aria-label="<?= h(t('Tmavý motiv')) ?>">
                    <?= allstat_icon('moon', 'icon theme-icon-light') ?><?= allstat_icon('sun', 'icon theme-icon-dark') ?>
                </button>
                <div class="dropdown user-menu">
                    <button type="button" class="tile tile-md tile-round" aria-label="<?= h(t('Uživatelská nabídka')) ?>" aria-haspopup="true" aria-expanded="false" data-menu-toggle="user-menu"><?= h(allstat_user_initials($user)) ?></button>
                    <div class="menu menu-end" id="user-menu" hidden>
                        <div class="menu-head"><span class="ellipsis"><?= h((string) ($user['name'] ?: $user['email'])) ?></span><span class="menu-sub"><?= h($roleLabel) ?></span></div>
                        <div class="menu-sep"></div>
                        <a class="menu-item" href="<?= h(allstat_url($config, 'admin/setup-2fa.php')) ?>"><?= allstat_icon('shield-check', 'icon-sm') ?><span><?= h(t('Dvoufaktorové ověření')) ?></span></a>
                        <form method="post" action="<?= h(allstat_url($config, 'admin/locale.php')) ?>">
                            <?= allstat_csrf_field() ?>
                            <input type="hidden" name="back" value="<?= h((string) ($_SERVER['REQUEST_URI'] ?? '')) ?>">
                            <div class="menu-label"><?= h(t('Jazyk')) ?></div>
                            <?php foreach (ALLSTAT_LOCALES as $code => $label): ?>
                            <button type="submit" class="menu-item" name="locale" value="<?= h($code) ?>" lang="<?= h($code) ?>"<?= allstat_locale() === $code ? ' aria-current="true"' : '' ?>>
                                <?= allstat_icon('languages', 'icon-sm') ?><span><?= h($label) ?></span>
                                <?php if (allstat_locale() === $code): ?><?= allstat_icon('check', 'menu-check') ?><?php endif; ?>
                            </button>
                            <?php endforeach; ?>
                        </form>
                        <div class="menu-sep"></div>
                        <a class="menu-item" href="<?= h(allstat_url($config, 'admin/logout.php')) ?>"><?= allstat_icon('log-out', 'icon-sm') ?><span><?= h(t('Odhlásit')) ?></span></a>
                    </div>
                </div>
            </div>
        </header>

        <main class="page" id="main">
    <?php
}

/**
 * Konec stránky: zavře shell a načte společné skripty (Lucide, překlady, shell.js) a skripty stránky.
 *
 * @param string[] $scripts cesty k JS v assets/ (např. 'assets/js/admin.js')
 * @param string $inline HTML s daty pro skripty stránky (inline <script> s nonce), vloží se před ně
 */
function allstat_layout_end(array $config, array $scripts = [], string $inline = ''): void
{
    $nonce = allstat_nonce();
    ?>
        </main>
    </div>
</div>
<?php foreach (['assets/vendor/lucide.min.js', 'assets/js/i18n.js', 'assets/js/shell.js'] as $script): ?>
<script src="<?= h(allstat_asset_url($config, $script)) ?>" nonce="<?= h($nonce) ?>"></script>
<?php endforeach; ?>
<?= $inline ?>
<?php foreach ($scripts as $script): ?>
<script src="<?= h(allstat_asset_url($config, $script)) ?>" nonce="<?= h($nonce) ?>"></script>
<?php endforeach; ?>
</body>
</html>
    <?php
}
