<?php

// Bez config.php ještě neproběhla instalace: přesměrovat na instalačního průvodce.
if (!is_file(__DIR__ . '/config.php')) {
    header('Location: ' . rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/') . '/install.php');
    exit;
}

$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['app']['timezone']);

require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/database.php';
require_once __DIR__ . '/lib/migrations.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/repository.php';
require_once __DIR__ . '/lib/growth.php';
require_once __DIR__ . '/lib/funnels.php';
// Updater je jen ve veřejném balíčku; interní instalace (nasazovaná ručně) ho mít nemusí.
if (is_file(__DIR__ . '/lib/updater.php')) {
    require_once __DIR__ . '/lib/updater.php';
}

allstat_session_start($config);
$pdo = allstat_db($config);

if (!$pdo || !allstat_tables_ready($pdo)) {
    allstat_redirect($config, 'install.php');
}

allstat_migrate($pdo);
$user = allstat_require_user($pdo, $config);

// Default = last 7 days incl. today, so the range is unambiguous and matches the "7 dní" quick-range chip.
$defaultEnd = (new DateTimeImmutable('today'))->format('Y-m-d');
$defaultStart = (new DateTimeImmutable('today'))->modify('-6 days')->format('Y-m-d');
// Pohled „Trychtýř“ (?view=funnel) bez zadaného období ukáže 30 dní: 7 dní je pro trychtýř s málo eventy příliš krátké okno.
if ((string) ($_GET['view'] ?? '') === 'funnel' && !isset($_GET['start']) && !isset($_GET['end'])) {
    $defaultStart = (new DateTimeImmutable('today'))->modify('-29 days')->format('Y-m-d');
}
$start = allstat_normalize_date($_GET['start'] ?? null, $defaultStart);
$end = allstat_normalize_date($_GET['end'] ?? null, $defaultEnd);
[$start, $end] = allstat_limited_range($start, $end);
// Chart interval is auto-derived from the selected range (no manual switcher): the graph just shows the chosen period.
$granularity = allstat_auto_granularity($start, $end);
$domains = allstat_get_domains($pdo);
// Účet s přístupem jen k vybraným webům, který zatím žádný (aktivní) web nemá: nic neukazovat, ani web č. 1.
if (!$domains && allstat_domain_scope() !== null) {
    http_response_code(403);
    require __DIR__ . '/views/no-access.php';
    exit;
}
// Web zvolený v URL se zapamatuje a drží napříč celou administrací; bez parametru se použije
// naposledy zvolený web, po přihlášení primární web (Nastavení, resp. doména, na které AllStat běží).
$domainId = allstat_current_domain_id($pdo, $domains, filter_input(INPUT_GET, 'domain_id', FILTER_VALIDATE_INT) ?: null) ?: 1;
$data = allstat_get_dashboard_data($pdo, (int) $domainId, $start, $end, $granularity);
$statusLabels = ['ok' => 'OK', 'warning' => 'Varování', 'error' => 'Chyba'];
$granularityLabels = ['day' => 'Denně', 'week' => 'Týdně', 'month' => 'Měsíčně'];

// Source switcher: overview (GA4/GSC native view) vs. a single connected provider's generic view.
$providerOptions = allstat_dashboard_provider_options($pdo, (int) $domainId);
$viewSourceId = filter_input(INPUT_GET, 'source_id', FILTER_VALIDATE_INT) ?: 0;
$selectedProvider = null;
foreach ($providerOptions as $opt) {
    if ($opt['connection_id'] === $viewSourceId) { $selectedProvider = $opt; break; }
}
// GA4 + GSC share the main overview (GSC just supplements it), so only "generic" providers
// (Clarity, Meta…) get their own view; anything else falls back to the overview.
if (!$selectedProvider || empty($selectedProvider['generic'])) { $selectedProvider = null; $viewSourceId = 0; }
$isGenericView = $selectedProvider !== null;

// Úvodní pohled = Růst kanálů (návrh „Přehled růstu kanálů", 29. 9. 2026). Klasický přehled GA4 + GSC je
// ?view=overview; staré odkazy a záložky s obdobím (start/end bez view) nebo se source_id vedou dál na něj.
$requestedView = (string) ($_GET['view'] ?? '');
$isGrowthView = !$isGenericView && ($requestedView === 'growth'
    || ($requestedView === '' && !isset($_GET['start']) && !isset($_GET['end']) && !isset($_GET['source_id'])));
$growth = null;
$growthChart = null;
$growthError = false;
if ($isGrowthView) {
    // Výchozí 12 měsíců (29. 9. 2026 na přání uživatele; karty navíc ukazují meziroční srovnání).
    $growthMonths = filter_input(INPUT_GET, 'months', FILTER_VALIDATE_INT) ?: 12;
    try {
        $growth = allstat_get_channel_growth($pdo, (int) $domainId, $growthMonths);
    } catch (Throwable $e) {
        error_log('AllStat růst kanálů: ' . $e->getMessage());
        $growthError = true;
        $growth = ['window' => allstat_growth_window($growthMonths), 'channels' => [], 'ranked' => [], 'best' => null, 'worst' => null];
    }
    $growthChart = allstat_growth_chart_payload($growth);
}

// Pohled „Trychtýř“ (?view=funnel&funnel_id=N): trychtýř musí patřit zvolenému webu, id cizího webu nebo neznámé id
// vede na výchozí pohled (izolace webů řeší allstat_funnel_get). Vypnutý trychtýř vidí jen administrátor (náhled při nastavování).
$isFunnelView = false;
$funnel = null;
$funnelReport = null;
$funnelChart = null;
$funnelError = false;
if (!$isGenericView && $requestedView === 'funnel') {
    $requestedFunnelId = filter_input(INPUT_GET, 'funnel_id', FILTER_VALIDATE_INT) ?: 0;
    $funnel = $requestedFunnelId > 0 ? allstat_funnel_get($pdo, $requestedFunnelId, (int) $domainId) : null;
    if ($funnel !== null && !$funnel['is_active'] && ($user['role'] ?? '') !== 'admin') {
        $funnel = null;
    }
    if ($funnel === null) {
        allstat_redirect($config, 'index.php?domain_id=' . (int) $domainId);
    }
    $isFunnelView = true;
    $funnelBreakdown = (string) ($_GET['breakdown'] ?? '');
    try {
        $funnelReport = allstat_funnel_report($pdo, (int) $domainId, $funnel, $start, $end, isset(allstat_funnel_breakdowns()[$funnelBreakdown]) ? $funnelBreakdown : null);
        $trend = $funnelReport['trend'];
        $funnelChart = ['granularity' => $trend['granularity'], 'labels' => [], 'longLabels' => [], 'series' => []];
        foreach ($trend['labels'] as $iso) {
            $bucket = new DateTimeImmutable($iso);
            $funnelChart['labels'][] = $bucket->format('j. n.');
            $funnelChart['longLabels'][] = ($trend['granularity'] === 'week' ? 'Týden od ' : '') . $bucket->format('j. n. Y');
        }
        foreach ($funnelReport['steps'] as $i => $step) {
            $funnelChart['series'][] = ['name' => $step['label'], 'event' => $step['event'], 'data' => $trend['series'][$i] ?? []];
        }
    } catch (Throwable $e) {
        error_log('AllStat trychtýř: ' . $e->getMessage());
        $funnelError = true;
    }
    $funnelBreakdownKey = (string) ($funnelReport['funnel']['breakdown'] ?? $funnel['breakdown']);
}
// Aktivní trychtýře webu do přepínače „Zdroj dat“.
$funnelOptions = allstat_funnels_for_domain($pdo, (int) $domainId, true);
$providerPayload = null;
$clarityBreakdowns = null;
$metaAdsBreakdown = null;
$metaAdsAdsets = null;
$metaAdsCreatives = null;
$metaAdsNoConversions = false;
$metaAdsRecommendations = null;
$metaAdsDemographics = null;
$googleAdsNoConversions = false;
$socialPosts = null;
$socialPostsAll = null;
$socialStories = null;
$igDemographics = null;
$igAudience = null;
$fbDemographics = null;
$linkedinFollowerDemographics = null;
$linkedinVisitorDemographics = null;
$linkedinReactions = null;
$youtubeVideos = null;
$youtubeAudience = null;
$youtubeCatalog = null;
if ($isGenericView) {
    if (($selectedProvider['provider_key'] ?? '') === 'clarity') {
        $clarityKpis = allstat_get_clarity_kpis($pdo, (int) $domainId, $viewSourceId, $start, $end);
        $clarityBreakdowns = allstat_get_clarity_breakdowns($pdo, (int) $domainId, $viewSourceId, $start, $end);
        $providerPayload = [
            'labels' => $clarityKpis['labels'],
            'metrics' => $clarityKpis['metrics'],
            'chartKeys' => ['sessions', 'bot_sessions'],
            'showChart' => count($clarityKpis['labels']) > 1,
            'hasData' => $clarityKpis['hasData'],
        ];
    } elseif (($selectedProvider['provider_key'] ?? '') === 'meta_ads') {
        // Meta Ads gets its own rich view: derived ROAS/CPC/CPM/CTR/frequency/CPA KPIs + campaigns.
        $adsKpis = allstat_get_meta_ads_kpis($pdo, (int) $domainId, $viewSourceId, $start, $end);
        $metaAdsBreakdown = allstat_get_meta_ads_breakdowns($pdo, (int) $domainId, $viewSourceId, $start, $end);
        $metaAdsAdsets = allstat_get_meta_ads_subbreakdown($pdo, (int) $domainId, $viewSourceId, $start, $end, 'adset');
        $metaAdsCreatives = allstat_get_meta_ads_subbreakdown($pdo, (int) $domainId, $viewSourceId, $start, $end, 'ad');
        $metaAdsNoConversions = !empty($adsKpis['noConversions']);
        $metaAdsRecommendations = allstat_get_meta_ads_recommendations($pdo, (int) $domainId, $viewSourceId, $start, $end);
        $metaAdsDemographics = allstat_get_meta_ads_demographics($pdo, $viewSourceId, $start, $end);
        $providerPayload = [
            'labels' => $adsKpis['labels'],
            'metrics' => $adsKpis['metrics'],
            // No-conversion accounts: chart útrata + prokliky na odkaz (conversions tile is hidden anyway).
            'chartKeys' => $metaAdsNoConversions ? ['spend', 'link_clicks'] : ['spend', 'conversions'],
            'showChart' => count($adsKpis['labels']) > 1,
            'hasData' => $adsKpis['hasData'],
        ];
    } elseif (($selectedProvider['provider_key'] ?? '') === 'google_ads') {
        // Google Ads gets its own rich PPC view: derived ROAS/PNO/CPA/CPC/CTR/CPM KPIs from the synced
        // cost/clicks/impressions/conversions/conversion_value (account-level).
        $adsKpis = allstat_get_google_ads_kpis($pdo, (int) $domainId, $viewSourceId, $start, $end);
        $googleAdsNoConversions = !empty($adsKpis['noConversions']);
        $providerPayload = [
            'labels' => $adsKpis['labels'],
            'metrics' => $adsKpis['metrics'],
            'chartKeys' => $googleAdsNoConversions ? ['cost', 'clicks'] : ['cost', 'conversions'],
            'showChart' => count($adsKpis['labels']) > 1,
            'hasData' => $adsKpis['hasData'],
        ];
    } elseif (($selectedProvider['provider_key'] ?? '') === 'seznam_wmt') {
        // Seznam Webmaster: indexation snapshot KPIs (latest day) + trend over time.
        $wmtKpis = allstat_get_seznam_wmt_kpis($pdo, (int) $domainId, $viewSourceId, $start, $end);
        $providerPayload = [
            'labels' => $wmtKpis['labels'],
            'metrics' => $wmtKpis['metrics'],
            'chartKeys' => ['indexed', 'content', 'error'],
            'showChart' => count($wmtKpis['labels']) > 1,
            'hasData' => $wmtKpis['hasData'],
        ];
    } elseif (($selectedProvider['provider_key'] ?? '') === 'linkedin_company') {
        // LinkedIn organická page view: počty (Zobrazení/Prokliky/reakce) + dopočítaný Celkový engagement
        // a Míra zapojení (%). Graf = reach vs engagement; míra zapojení (%) se do grafu nedává (měřítko).
        // Per-post content analytics (Top posty / podle typu) jedou stejnou social_posts cestou jako FB/IG.
        $socialPosts = allstat_get_social_posts($pdo, $viewSourceId, $start, $end, 5);
        $postsPage = filter_input(INPUT_GET, 'posts_page', FILTER_VALIDATE_INT) ?: 1;
        $socialPostsAll = allstat_get_social_posts_page($pdo, $viewSourceId, $start, $end, $postsPage, 15);
        $linkedinFollowerDemographics = allstat_get_linkedin_follower_demographics($pdo, $viewSourceId);
        $linkedinVisitorDemographics = allstat_get_linkedin_visitor_demographics($pdo, $viewSourceId);
        $linkedinReactions = allstat_get_linkedin_reactions($pdo, $viewSourceId, $start, $end);
        $pv = allstat_get_provider_view($pdo, (int) $domainId, $viewSourceId, $start, $end, $granularity, 'linkedin_company');
        $providerPayload = [
            'labels' => $pv['labels'],
            'metrics' => $pv['metrics'],
            'chartKeys' => ['impressions', 'clicks'],
            'showChart' => count($pv['labels']) > 1,
            'hasData' => $pv['hasData'],
        ];
    } elseif (($selectedProvider['provider_key'] ?? '') === 'youtube') {
        // YouTube: generické dlaždice (odběratelé, zhlédnutí, sledovaný čas, …) + videa a publikum z vlastních čteček.
        $youtubeVideos = allstat_get_youtube_videos($pdo, $viewSourceId, $start, $end, 10);
        $youtubeAudience = allstat_get_youtube_audience($pdo, $viewSourceId, $start, $end);
        $youtubeCatalog = allstat_get_youtube_catalog($pdo, $viewSourceId);
        $pv = allstat_get_provider_view($pdo, (int) $domainId, $viewSourceId, $start, $end, $granularity, 'youtube');
        $providerPayload = [
            'labels' => $pv['labels'],
            'metrics' => $pv['metrics'],
            'chartKeys' => ['views', 'engagements_total'],
            'showChart' => count($pv['labels']) > 1,
            'hasData' => $pv['hasData'],
        ];
    } else {
        if (in_array((string) ($selectedProvider['provider_key'] ?? ''), ['facebook_pages', 'instagram_business'], true)) {
            $socialPosts = allstat_get_social_posts($pdo, $viewSourceId, $start, $end, 5);
            $postsPage = filter_input(INPUT_GET, 'posts_page', FILTER_VALIDATE_INT) ?: 1;
            $socialPostsAll = allstat_get_social_posts_page($pdo, $viewSourceId, $start, $end, $postsPage, 15);
            // Stories zachytáváme u FB i IG (post_type='story'); IG má navíc metriky (dosah/zobrazení/odpovědi/navigace).
            $socialStories = allstat_get_social_stories($pdo, $viewSourceId, $start, $end, 20);
            if (($selectedProvider['provider_key'] ?? '') === 'facebook_pages') {
                $fbDemographics = allstat_get_ig_demographics($pdo, $viewSourceId); // dem_* z CSV Publikum (hodnoty v %)
            }
            if (($selectedProvider['provider_key'] ?? '') === 'instagram_business') {
                $igDemographics = allstat_get_ig_demographics($pdo, $viewSourceId);
                $igAudience = allstat_get_ig_audience($pdo, $viewSourceId, $start, $end);
            }
        }
        $pv = allstat_get_provider_view($pdo, (int) $domainId, $viewSourceId, $start, $end, $granularity, (string) $selectedProvider['provider_key']);
        $providerPayload = [
            'labels' => $pv['labels'],
            'metrics' => $pv['metrics'],
            // Sledující celkem (followers_total) je stavová řada v jiném měřítku (tisíce vs. stovky
            // denních interakcí) — do společného trend grafu se nedává, dlaždice má vlastní sparkline.
            'chartKeys' => array_values(array_diff(array_column($pv['metrics'], 'key'), ['followers_total', 'fans_total'])),
            // FB/IG mají „Trendy v čase" jako přehledné grafy per metrika (views/provider/_content_trends.php),
            // takže společný víceřádkový trend graf u nich vypínáme (byl nečitelný, 6 linek různých měřítek).
            'showChart' => count($pv['labels']) > 1 && !in_array((string) ($selectedProvider['provider_key'] ?? ''), ['facebook_pages', 'instagram_business'], true),
            'hasData' => $pv['hasData'],
        ];
    }
}

?><!doctype html>
<html lang="cs" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($config['app']['name']) ?> | Přehled</title>
    <script nonce="<?= h(allstat_nonce()) ?>">
        document.documentElement.dataset.theme = localStorage.getItem('allstat-theme') || 'light';
    </script>
    <link rel="stylesheet" href="assets/css/app.css?v=<?= @filemtime(__DIR__ . '/assets/css/app.css') ?: '1' ?>">
</head>
<body>
    <div class="app-shell" data-dashboard>
        <aside class="sidebar" id="mainSidebar" aria-label="Hlavní navigace">
            <a class="brand" href="index.php" aria-label="AllStat">
                <span class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></span>
                <span>AllStat</span>
            </a>

            <nav class="sidebar-nav">
                <?php $isAdmin = ($user['role'] ?? '') === 'admin'; // správa je jen pro administrátora (stránky vrací 403) ?>
                <a class="active" href="index.php"><?= allstat_icon('home') ?><span>Přehled</span></a>
                <?php if ($isAdmin): ?>
                <a href="admin/domains.php"><?= allstat_icon('globe-2') ?><span>Weby</span></a>
                <a href="admin/sources.php"><?= allstat_icon('database') ?><span>Zdroje dat</span></a>
                <a href="admin/metrics.php"><?= allstat_icon('line-chart') ?><span>Metriky</span></a>
                <a href="admin/funnels.php"><?= allstat_icon('filter') ?><span>Trychtýře</span></a>
                <?php endif; ?>
                <a href="admin/reports.php"><?= allstat_icon('clipboard-list') ?><span>Reporty</span></a>
                <?php if ($isAdmin): ?>
                <a href="admin/feeds.php"><?= allstat_icon('sheet') ?><span>Datový feed</span></a>
                <?php endif; ?>
                <span class="nav-divider"></span>
                <?php if ($isAdmin): ?>
                <a href="admin/settings.php"><?= allstat_icon('settings') ?><span>Nastavení</span></a>
                <a href="admin/mcp.php"><?= allstat_icon('plug-zap') ?><span>AI konektory</span></a>
                <a href="admin/users.php"><?= allstat_icon('users') ?><span>Uživatelé</span></a>
                <?php endif; ?>
                <a href="admin/setup-2fa.php"><?= allstat_icon('shield-check') ?><span>2FA</span></a>
                <a href="admin/logout.php"><?= allstat_icon('log-out') ?><span>Odhlásit</span></a>
            </nav>

            <?php
            // Zaseknuty zdroj musi byt videt: driv panel hlasil „vsechny synchronizovane" i kdyz se cast
            // zdroju tyden neozvala (vypadek cronu 2.-9. 9. 2026 si nikdo nevsiml prave kvuli tomu).
            $syncHealth = $data['meta']['syncHealth'] ?? ['stale' => [], 'staleCount' => 0];
            $staleCount = (int) ($syncHealth['staleCount'] ?? 0);
            $syncOk = $data['meta']['databaseMode'] && $staleCount === 0;
            $staleAll = array_map(static fn (array $x): string => $x['source'] . ' (' . $x['lastSync'] . ')', $syncHealth['stale'] ?? []);
            // Do karty se vejdou jen prvni tri, zbytek je v tooltipu, jinak by pri vypadku vsech zdroju
            // seznam roztahl cely bocni panel.
            $staleNames = implode(', ', array_slice($staleAll, 0, 3));
            $zbytek = count($staleAll) - 3;
            if ($zbytek > 0) { $staleNames .= ($zbytek <= 4 ? ' a další ' : ' a dalších ') . $zbytek; }
            // Ceska shoda: 1 zdroj se neozval / 2-4 zdroje se neozvaly / 5+ zdroju se neozvalo.
            $staleTitle = $staleCount === 1
                ? '1 zdroj se dlouho neozval'
                : ($staleCount >= 2 && $staleCount <= 4 ? $staleCount . ' zdroje se dlouho neozvaly' : $staleCount . ' zdrojů se dlouho neozvalo');
            $updateNotice = function_exists('allstat_update_notice') ? allstat_update_notice($pdo, $user) : null;
            ?>
            <?php if ($updateNotice): ?>
            <a class="update-card" href="admin/update.php">
                <i data-lucide="download" aria-hidden="true"></i>
                <span><strong>Nová verze <?= h($updateNotice['version']) ?></strong><small>Máš <?= h($updateNotice['installed']) ?>, klikni pro aktualizaci</small></span>
            </a>
            <?php endif; ?>
            <div class="sync-card">
                <span class="status-dot <?= $syncOk ? 'status-dot-ok' : 'status-dot-warning' ?>" aria-hidden="true"></span>
                <strong data-data-mode><?php
                    if (!$data['meta']['databaseMode']) { echo 'Demo data jsou aktivní'; }
                    elseif ((int) ($data['meta']['sourceCount'] ?? 0) === 0) { echo 'Zdroje zatím nejsou napojené'; }
                    elseif ($staleCount > 0) { echo h($staleTitle); }
                    else { echo 'Všechny zdroje jsou synchronizované'; }
                ?></strong>
                <span>Nejstarší sync: <span data-last-sync><?= h($data['meta']['lastSync']) ?></span></span>
                <?php if ($staleCount > 0): ?>
                    <span class="sync-card-stale" title="<?= h(implode(', ', $staleAll)) ?>">Přes 48 h bez dat: <?= h($staleNames) ?>. Zkontroluj cron.</span>
                <?php endif; ?>
            </div>
        </aside>

        <div class="nav-backdrop" data-nav-backdrop aria-hidden="true"></div>

        <main class="main-content">
            <header class="topbar">
                <div class="topbar-title">
                    <button class="icon-button nav-toggle" id="navToggle" type="button" title="Menu" aria-label="Menu" aria-controls="mainSidebar" aria-expanded="false"><i data-lucide="menu"></i></button>
                    <h1>Přehled</h1>
                    <span class="title-actions">
                        <button class="icon-button" type="submit" form="dashboardFilters" title="Obnovit data" aria-label="Obnovit data"><i data-lucide="rotate-cw"></i></button>
                        <?php // Sdílení pro AI umí přehled, růst a zdroje; trychtýř do něj nepatří (AI čte trychtýře přes MCP get_funnel). ?>
                        <?php if (!$isFunnelView): ?>
                        <button class="icon-button" type="button" id="shareAiBtn" title="Sdílet pro AI (dočasný odkaz)" aria-label="Sdílet pro AI"
                            data-domain="<?= (int) $data['domain']['id'] ?>" data-source="<?= (int) $viewSourceId ?>"
                            data-start="<?= h($data['range']['start']) ?>" data-end="<?= h($data['range']['end']) ?>"
                            data-gran="<?= h($granularity) ?>" data-csrf="<?= h(allstat_csrf_token()) ?>"
                            <?php if ($isGrowthView): ?>data-view="growth" data-months="<?= (int) $growth['window']['n'] ?>"<?php endif; ?>><i data-lucide="sparkles"></i></button>
                        <?php endif; ?>
                        <button class="avatar-button" id="themeToggle" type="button" title="Motiv" aria-label="Motiv"><i data-lucide="moon"></i></button>
                    </span>
                </div>

                <?php
                $today = (new DateTimeImmutable('today'))->format('Y-m-d');
                if ($selectedProvider !== null && !empty($selectedProvider['rolling'])) {
                    // Clarity-style rolling provider: day-scale ranges (its API only covers the last 1-3 days;
                    // longer ranges fill in as the daily cron accumulates).
                    $quickRanges = [
                        'Dnes' => [$today, $today],
                        'Včera' => [(new DateTimeImmutable('yesterday'))->format('Y-m-d'), (new DateTimeImmutable('yesterday'))->format('Y-m-d')],
                        '3 dny' => [(new DateTimeImmutable('today'))->modify('-2 days')->format('Y-m-d'), $today],
                        '7 dní' => [(new DateTimeImmutable('today'))->modify('-6 days')->format('Y-m-d'), $today],
                        'Vše' => [(new DateTimeImmutable('today'))->modify('-540 days')->format('Y-m-d'), $today],
                    ];
                } else {
                    $quickRanges = [
                        'Dnes' => [$today, $today],
                        '7 dní' => [(new DateTimeImmutable('today'))->modify('-6 days')->format('Y-m-d'), $today],
                        '30 dní' => [(new DateTimeImmutable('today'))->modify('-29 days')->format('Y-m-d'), $today],
                        '90 dní' => [(new DateTimeImmutable('today'))->modify('-89 days')->format('Y-m-d'), $today],
                        'Vše' => [(new DateTimeImmutable('today'))->modify('-16 months')->format('Y-m-d'), $today],
                    ];
                }
                $curStart = $data['range']['start'];
                $curEnd = $data['range']['end'];
                // Label rozsahu pro trigger vlastního datepickeru (JS ho pak aktualizuje). En-pomlčka „–" u rozsahu.
                $rlS = new DateTimeImmutable($curStart);
                $rlE = new DateTimeImmutable($curEnd);
                if ($curStart === $curEnd) {
                    $rangeLabel = $rlS->format('j. n. Y');
                } elseif ($rlS->format('Y') === $rlE->format('Y')) {
                    $rangeLabel = $rlS->format('j. n.') . ' – ' . $rlE->format('j. n. Y');
                } else {
                    $rangeLabel = $rlS->format('j. n. Y') . ' – ' . $rlE->format('j. n. Y');
                }
                ?>
                <?php
                // Rychlé volby období se mají vracet na stejný pohled (přehled / trychtýř se zvoleným rozpadem).
                $rangeLinkTail = $isGenericView ? '' : ($isFunnelView ? '&view=funnel&funnel_id=' . (int) $funnel['id'] . '&breakdown=' . rawurlencode($funnelBreakdownKey) : '&view=overview');
                ?>
                <div class="topbar-controls">
                <?php if ($isGrowthView): ?>
                <div class="quick-ranges" aria-label="Období (uzavřené měsíce)">
                    <?php foreach ([3 => '3 měsíce', 6 => '6 měsíců', 12 => '12 měsíců'] as $gm => $gmLabel): ?>
                        <a class="chip <?= $growth['window']['n'] === $gm ? 'is-active' : '' ?>" href="?<?= h(http_build_query(['view' => 'growth', 'months' => $gm, 'domain_id' => (int) $data['domain']['id']])) ?>"><?= h($gmLabel) ?></a>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="quick-ranges" aria-label="Rychlý výběr období">
                    <?php foreach ($quickRanges as $label => [$qs, $qe]): ?>
                        <a class="chip <?= ($curStart === $qs && $curEnd === $qe) ? 'is-active' : '' ?>" data-range-start="<?= h($qs) ?>" data-range-end="<?= h($qe) ?>" href="?domain_id=<?= (int) $data['domain']['id'] ?>&start=<?= h($qs) ?>&end=<?= h($qe) ?>&source_id=<?= (int) $viewSourceId ?><?= $rangeLinkTail ?>"><?= h($label) ?></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <form class="toolbar" id="dashboardFilters" method="get">
                    <?php if ($isGrowthView): ?>
                    <div class="filter-control" title="Počítají se jen uzavřené měsíce, rozběhnutý měsíc je v tabulce zvlášť jako „zatím“.">
                        <i data-lucide="calendar-days"></i>
                        <span class="growth-range-label"><?= h($growth['window']['rangeLabel']) ?> <small>· měsíčně</small></span>
                    </div>
                    <input type="hidden" name="view" value="growth">
                    <input type="hidden" name="months" value="<?= (int) $growth['window']['n'] ?>">
                    <?php else: ?>
                    <div class="filter-control date-range" data-date-range>
                        <i data-lucide="calendar-days"></i>
                        <button type="button" class="date-range-trigger" data-date-range-trigger aria-haspopup="dialog" aria-expanded="false">
                            <span data-date-range-label><?= h($rangeLabel) ?></span>
                            <span class="date-range-chev" aria-hidden="true"><i data-lucide="chevron-down"></i></span>
                        </button>
                        <input type="hidden" name="start" value="<?= h($data['range']['start']) ?>" data-date-start>
                        <input type="hidden" name="end" value="<?= h($data['range']['end']) ?>" data-date-end>
                        <div class="date-range-pop" data-date-range-pop role="dialog" aria-label="Výběr období" hidden></div>
                    </div>
                    <?php if ($isFunnelView): ?>
                    <input type="hidden" name="view" value="funnel">
                    <input type="hidden" name="funnel_id" value="<?= (int) $funnel['id'] ?>">
                    <input type="hidden" name="breakdown" value="<?= h($funnelBreakdownKey) ?>">
                    <?php elseif (!$isGenericView): ?><input type="hidden" name="view" value="overview"><?php endif; ?>
                    <?php endif; ?>

                    <label class="filter-control">
                        <i data-lucide="globe-2"></i>
                        <select name="domain_id" aria-label="Doména">
                            <?php foreach ($data['domains'] as $domain): ?>
                                <option value="<?= (int) $domain['id'] ?>" <?= (int) $domain['id'] === (int) $data['domain']['id'] ? 'selected' : '' ?>>
                                    <?= h($domain['url']) ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if (!$data['domains']): ?>
                                <option value="0">Žádný web</option>
                            <?php endif; ?>
                        </select>
                    </label>

                    <?php
                    // Group connected providers by category so organic social and Meta Ads (PPC) are
                    // clearly separated; each connection is one option, labelled by its account/page name.
                    $catGroupCz = ['social' => 'Sociální sítě', 'ppc' => 'Reklama (PPC)', 'analytics' => 'Analytika', 'seo' => 'SEO'];
                    $genericByGroup = [];
                    foreach ($providerOptions as $opt) {
                        if (empty($opt['generic'])) { continue; }
                        $groupLabel = $catGroupCz[$opt['category']] ?? ($opt['categoryLabel'] ?? 'Ostatní');
                        $genericByGroup[$groupLabel][] = $opt;
                    }
                    ?>
                    <?php
                    // Vlastní listbox místo <select> — nativní <option> neumí SVG ikony providerů.
                    $pickerLabel = $isGenericView ? $selectedProvider['label'] . ', ' . $selectedProvider['name'] : ($isGrowthView ? 'Růst kanálů' : ($isFunnelView ? 'Trychtýř: ' . $funnel['name'] : 'Přehled (GA4 + GSC)'));
                    $pickerIcon = $isGrowthView ? '<i data-lucide="trending-up"></i>' : ($isFunnelView ? '<i data-lucide="filter"></i>' : allstat_provider_icon_svg($isGenericView ? (string) $selectedProvider['provider_key'] : 'overview'));
                    $isOverviewSel = !$isGenericView && !$isGrowthView && !$isFunnelView;
                    ?>
                    <div class="filter-control source-picker" data-source-picker data-domain="<?= (int) $data['domain']['id'] ?>" data-start="<?= $isGrowthView ? '' : h($data['range']['start']) ?>" data-end="<?= $isGrowthView ? '' : h($data['range']['end']) ?>">
                        <button type="button" class="source-picker-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="Zdroj dat">
                            <span class="source-picker-ic"><?= $pickerIcon ?></span>
                            <span class="source-picker-label"><?= h($pickerLabel) ?></span>
                            <i data-lucide="chevron-down"></i>
                        </button>
                        <div class="source-picker-menu" role="listbox" aria-label="Zdroj dat" hidden>
                            <button type="button" role="option" class="source-picker-item<?= $isGrowthView ? ' is-selected' : '' ?>" aria-selected="<?= $isGrowthView ? 'true' : 'false' ?>" data-value="0" data-view="growth">
                                <span class="source-picker-ic"><i data-lucide="trending-up"></i></span>
                                <span>Růst kanálů <small>· všechny kanály po měsících</small></span>
                            </button>
                            <button type="button" role="option" class="source-picker-item<?= $isOverviewSel ? ' is-selected' : '' ?>" aria-selected="<?= $isOverviewSel ? 'true' : 'false' ?>" data-value="0" data-view="overview">
                                <span class="source-picker-ic"><?= allstat_provider_icon_svg('overview') ?></span>
                                <span>Přehled (GA4 + GSC)</span>
                            </button>
                            <?php if ($funnelOptions): ?>
                                <div class="source-picker-group">Trychtýře</div>
                                <?php foreach ($funnelOptions as $fOpt): $fSel = $isFunnelView && (int) $fOpt['id'] === (int) $funnel['id']; $fCount = count($fOpt['steps']); ?>
                                    <button type="button" role="option" class="source-picker-item<?= $fSel ? ' is-selected' : '' ?>" aria-selected="<?= $fSel ? 'true' : 'false' ?>" data-value="0" data-view="funnel" data-funnel="<?= (int) $fOpt['id'] ?>">
                                        <span class="source-picker-ic"><i data-lucide="filter"></i></span>
                                        <span><?= h($fOpt['name']) ?> <small>· <?= $fCount ?> <?= $fCount === 1 ? 'krok' : ($fCount < 5 ? 'kroky' : 'kroků') ?></small></span>
                                    </button>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php foreach ($genericByGroup as $groupLabel => $opts): ?>
                                <div class="source-picker-group"><?= h($groupLabel) ?></div>
                                <?php foreach ($opts as $opt): $optSelected = $viewSourceId === (int) $opt['connection_id']; ?>
                                    <button type="button" role="option" class="source-picker-item<?= $optSelected ? ' is-selected' : '' ?>" aria-selected="<?= $optSelected ? 'true' : 'false' ?>" data-value="<?= (int) $opt['connection_id'] ?>">
                                        <span class="source-picker-ic"><?= allstat_provider_icon_svg((string) $opt['provider_key']) ?></span>
                                        <span><?= h($opt['label']) ?> <small>· <?= h($opt['name']) ?></small></span>
                                    </button>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <input type="hidden" name="source_id" value="<?= (int) $viewSourceId ?>">
                </form>
                </div>
            </header>

            <?php // Hlášky z akcí, které se vrací na dashboard (např. import CSV z rozbalovacího boxu u FB/IG). ?>
            <?php foreach (($_SESSION['flashes'] ?? []) as $flash): ?>
                <div class="notice <?= ($flash['type'] ?? '') === 'error' ? 'notice-error' : 'notice-ok' ?>"><?= h((string) ($flash['message'] ?? '')) ?></div>
            <?php endforeach; unset($_SESSION['flashes']); ?>

            <div id="shareResult" class="share-result" hidden>
                <div class="share-result-head"><strong>Report pro AI</strong> <span data-share-expiry></span><button type="button" class="share-close" data-share-close aria-label="Zavřít">×</button></div>
                <p class="share-hint"><strong>Klikni „Kopírovat text pro AI"</strong> a vlož do ChatGPT / Claude / Perplexity. Text přečte každá AI hned. Report platí max 15 minut. Odkaz níže je jen pro ruční použití, běžný chat ho sám neotevře.</p>
                <div style="margin-bottom:8px;"><button type="button" class="button-primary" data-share-copy-text>Kopírovat text pro AI</button></div>
                <div class="share-url-row"><input type="text" readonly data-share-url aria-label="Odkaz (pro ruční použití)"></div>
                <div class="share-fmt">Formát: <a href="#" data-share-fmt="md">Markdown</a> · <a href="#" data-share-fmt="json">JSON</a></div>
            </div>

            <?php if ($isGenericView): ?>
            <?php /* ---- Generic single-provider view (Meta, LinkedIn, Ads, Clarity…) ---- */ ?>
            <?php
            // Czech category group + a clear "Meta Ads vs organic" flag for the heading/badges.
            $provGroupCz = ['social' => 'Sociální sítě', 'ppc' => 'Reklama (PPC)', 'analytics' => 'Analytika', 'seo' => 'SEO'];
            $provGroup = $provGroupCz[$selectedProvider['category']] ?? ($selectedProvider['categoryLabel'] ?? '');
            $provIsAds = ($selectedProvider['category'] ?? '') === 'ppc';
            ?>
            <?php if (empty($providerPayload['hasData'])): ?>
            <section class="analytics-grid" aria-label="Zdroj">
                <article class="panel panel-wide">
                    <div class="panel-header"><h2><?= h($selectedProvider['label']) ?> <span>(<?= h($selectedProvider['name']) ?>)</span></h2></div>
                    <p class="panel-help"><?= h($provGroup) ?>, tento zdroj zatím nemá data za zvolené období. Napoj a synchronizuj ho ve <a href="admin/sources.php">Zdroje dat</a><?php if (($selectedProvider['provider_key'] ?? '') === 'clarity'): ?>, nebo nahraj denní CSV přes <a href="admin/clarity-import.php?domain_id=<?= (int) $data['domain']['id'] ?>">Import Clarity CSV</a><?php endif; ?>, případně zvol jiné období.</p>
                </article>
            </section>
            <?php else: ?>
            <section class="analytics-grid" aria-label="Vybraný zdroj">
                <article class="panel panel-wide">
                    <div class="panel-header"><h2><?= h($selectedProvider['label']) ?> <span>(<?= h($selectedProvider['name']) ?>)</span></h2></div>
                    <p class="panel-help"><?= h($provGroup) ?>, data jen za <?= $provIsAds ? 'tento reklamní účet' : 'tuto jednu stránku / účet' ?>. Nemíchá se s ostatními zdroji ani s GA4 přehledem.<?php if ($provIsAds): ?> ROAS a konverze závisí na <strong>atribučním okně</strong> Meta (výchozí 7 dní klik / 1 den zobrazení; lze změnit v config_json napojení).<?php endif; ?></p>
                </article>
            </section>
            <section class="kpi-grid" aria-label="Metriky zdroje">
                <?php foreach ($providerPayload['metrics'] as $metric): ?>
                    <article class="kpi-card kpi-<?= h($metric['color'] ?? 'cyan') ?>">
                        <div class="kpi-head">
                            <span class="kpi-icon"><i data-lucide="<?= h($metric['icon'] ?? 'activity') ?>"></i></span>
                            <span><strong><?= h($metric['label']) ?></strong> <small>(<?= h($selectedProvider['label']) ?>)</small></span>
                            <?php if (!empty($metric['tooltip'])): ?>
                                <span class="kpi-help" tabindex="0" aria-label="Vysvětlení metriky">
                                    <i data-lucide="info"></i>
                                    <span class="kpi-help-popover"><?= h($metric['tooltip']) ?></span>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="kpi-value"><?= h($metric['displayValue'] ?? ($metric['totalLabel'] ?? '0')) ?></div>
                        <div class="sparkline-wrap"><canvas data-provider-spark="<?= h($metric['key']) ?>"></canvas></div>
                    </article>
                <?php endforeach; ?>
            </section>

            <?php if (!empty($providerPayload['showChart'])): ?>
            <section class="analytics-grid" aria-label="Trend zdroje">
                <article class="panel panel-wide">
                    <div class="panel-header"><h2><?= h($selectedProvider['label']) ?>, trend v čase <span>(<?= h($selectedProvider['name']) ?>)</span></h2></div>
                    <p class="panel-help">Vývoj klíčových metrik tohoto zdroje v čase za zvolené období. Jen za tento jeden zdroj (nemíchá se s GA4). U jednodenního období se graf nezobrazuje.</p>
                    <div class="chart-frame chart-frame-line"><canvas id="providerChart"></canvas></div>
                </article>
            </section>
            <?php endif; ?>

            <?php
            // Provider-specific breakdown sections (Clarity tables, Meta Ads campaigns/ad-sets/creatives…)
            // live in views/provider/<provider_key>.php — adding a provider's rich view = drop a file there.
            // The shared KPI grid + trend above render the same for every provider; only these sections differ.
            $allstatProviderPartial = __DIR__ . '/views/provider/' . preg_replace('/[^a-z0-9_]/', '', (string) ($selectedProvider['provider_key'] ?? '')) . '.php';
            if (is_file($allstatProviderPartial)) {
                include $allstatProviderPartial;
            }
            ?>

            <?php endif; ?>
            <?php elseif ($isGrowthView): ?>
            <?php if ($growthError): ?>
                <div class="notice notice-error">Data pro Růst kanálů se nepodařilo načíst. Zkus obnovit stránku, případně otevři <a class="text-link" href="?view=overview&amp;domain_id=<?= (int) $data['domain']['id'] ?>">Přehled (GA4 + GSC)</a>.</div>
            <?php else: ?>
                <?php include __DIR__ . '/views/growth.php'; ?>
            <?php endif; ?>
            <?php elseif ($isFunnelView): ?>
            <?php if ($funnelError): ?>
                <div class="notice notice-error">Data trychtýře se nepodařilo načíst. Zkus obnovit stránku, případně otevři <a class="text-link" href="?view=overview&amp;domain_id=<?= (int) $data['domain']['id'] ?>">Přehled (GA4 + GSC)</a>.</div>
            <?php else: ?>
                <?php include __DIR__ . '/views/funnel.php'; ?>
            <?php endif; ?>
            <?php else: ?>
            <?php
            $fmtRangeDate = static fn (string $iso): string => (new DateTimeImmutable($iso))->format('j. n. Y');
            $previousRangeLabel = !empty($data['previousRange'])
                ? $fmtRangeDate($data['previousRange']['start']) . ' – ' . $fmtRangeDate($data['previousRange']['end'])
                : '';
            // KPI seskupené do kategorií (Návštěvnost / Zapojení / Konverze / SEO / AI) místo jedné mřížky
            // 14 karet — ze 14 metrik se pak čte 5 skupin. AJAX update (app.js) hledá karty přes
            // [data-kpi-card] globálně, takže seskupení do sekcí nic nerozbije.
            $kpiGroups = [];
            foreach ($data['kpis'] as $kpi) { $kpiGroups[$kpi['group'] ?? ''][] = $kpi; }
            ?>
            <div class="overview-top">
            <div class="overview-kpis">
            <?php foreach ($kpiGroups as $groupName => $groupKpis): ?>
            <section class="kpi-group" aria-label="<?= h($groupName !== '' ? $groupName : 'KPI') ?>">
                <?php if ($groupName !== ''): ?><h2 class="kpi-group-title"><?= h($groupName) ?></h2><?php endif; ?>
                <div class="kpi-grid">
                    <?php foreach ($groupKpis as $kpi): ?>
                    <article class="kpi-card kpi-<?= h($kpi['color']) ?>" data-kpi-card="<?= h($kpi['key']) ?>">
                        <div class="kpi-head">
                            <span class="kpi-icon"><i data-lucide="<?= h($kpi['icon']) ?>"></i></span>
                            <span><strong data-kpi-label><?= h($kpi['label']) ?></strong> <small>(<?= h($kpi['provider']) ?>)</small></span>
                            <?php if (!empty($kpi['tooltip'])): ?>
                                <span class="kpi-help" tabindex="0" aria-label="Vysvětlení metriky">
                                    <i data-lucide="info"></i>
                                    <span class="kpi-help-popover"><?= h($kpi['tooltip']) ?></span>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="kpi-value" data-kpi-value><?= h($kpi['displayValue']) ?></div>
                        <div class="kpi-meta">
                            <?php if ($kpi['trend'] === 'none'): ?>
                                <span class="trend trend-none" data-kpi-change>bez srovnání</span>
                                <span>(předchozí období nemá data)</span>
                            <?php else: ?>
                                <span class="trend <?= $kpi['trend'] === 'up' ? 'trend-up' : 'trend-down' ?>" data-kpi-change><?= $kpi['trend'] === 'up' ? '&uarr;' : '&darr;' ?> <?= h($kpi['changeLabel']) ?></span>
                                <span data-prev-range title="Změna oproti stejně dlouhému období těsně před zvoleným rozsahem (např. u „7 dní" = předchozích 7 dní, ne loňský rok).">vs. předchozí období<?= $previousRangeLabel !== '' ? ' (' . h($previousRangeLabel) . ')' : '' ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="sparkline-wrap"><canvas data-sparkline="<?= h($kpi['key']) ?>"></canvas></div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endforeach; ?>
            </div>
            <aside class="overview-rail" aria-label="Grafy">
                <article class="panel panel-wide">
                    <div class="panel-header">
                        <h2>Návštěvy</h2>
                        <span class="chart-interval" aria-label="Interval grafu" title="Interval se volí automaticky podle délky období"><i data-lucide="calendar-range"></i>Interval: <strong><?= h($granularityLabels[$data['granularity']] ?? 'Denně') ?></strong></span>
                    </div>
                    <p class="panel-help">Trend návštěv (modrá, GA4 sessions), uživatelů (zelená, GA4 active users) a konverzí (fialová, GA4 key events, čte se na <strong>pravé ose</strong>, protože bývají v jiném řádu). Interval grafu se volí <strong>automaticky</strong> podle délky období (krátké → denně, delší → týdně/měsíčně).</p>
                    <div class="chart-frame chart-frame-line"><canvas id="visitsChart"></canvas></div>
                </article>

                <article class="panel">
                    <div class="panel-header">
                        <h2>Zdroje návštěv <span>(GA4)</span></h2>
                        <span class="panel-total"><strong data-traffic-total><?= h($data['charts']['traffic']['totalLabel']) ?></strong> celkem</span>
                    </div>
                    <p class="panel-help">Rozdělení návštěv podle kanálu (Organic Search, Direct, Referral, Social…). Data z GA4 dimenze <code>sessionDefaultChannelGroup</code>. Délka pruhu = poměr k největšímu kanálu.</p>
                    <?php
                    $barPalette = ['#2563eb', '#16a34a', '#8b5cf6', '#f59e0b', '#06b6d4', '#f43f5e'];
                    $trafficRows = $data['charts']['traffic']['sources'] ?? [];
                    $trafficMax = 1;
                    foreach ($trafficRows as $r) { $trafficMax = max($trafficMax, (int) $r['sessions']); }
                    ?>
                    <div class="bar-chart" data-bar-traffic>
                        <?php foreach ($trafficRows as $i => $r): ?>
                            <div class="bar-row">
                                <span class="bar-row-label" title="<?= h($r['source']) ?>"><?= h($r['source']) ?></span>
                                <div class="bar-row-track"><div class="bar-row-fill" style="width: <?= round(max(2, (int) $r['sessions'] / $trafficMax * 100), 1) ?>%; background: <?= $barPalette[$i % 6] ?>;"></div></div>
                                <span class="bar-row-value"><strong><?= h($r['sessionsLabel']) ?></strong> <small>(<?= h($r['shareLabel']) ?>)</small></span>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$trafficRows): ?><p class="table-muted">Zatím bez dat o kanálech, spusť GA4 sync.</p><?php endif; ?>
                    </div>
                    <a class="text-link" href="admin/reports.php?domain_id=<?= (int) $data['domain']['id'] ?>&start=<?= h($data['range']['start']) ?>&end=<?= h($data['range']['end']) ?>#traffic-sources">Zobrazit celý report <i data-lucide="arrow-right"></i></a>
                </article>

                <article class="panel">
                    <div class="panel-header">
                        <h2>Zařízení <span>(GA4)</span></h2>
                        <span class="panel-total"><strong data-device-total><?= h($data['charts']['devices']['totalLabel'] ?? '0') ?></strong> návštěv</span>
                    </div>
                    <p class="panel-help">Rozdělení návštěv podle typu zařízení (GA4 dimenze <code>deviceCategory</code>). Mobil / Desktop / Tablet.</p>
                    <?php
                    $deviceRows = $data['charts']['devices']['items'] ?? [];
                    $deviceVals = $data['charts']['devices']['values'] ?? [];
                    $deviceMax = 1;
                    foreach ($deviceRows as $i => $r) { $deviceMax = max($deviceMax, (int) ($r['sessions'] ?? ($deviceVals[$i] ?? 0))); }
                    ?>
                    <div class="bar-chart" data-bar-devices>
                        <?php foreach ($deviceRows as $i => $r): $v = (int) ($r['sessions'] ?? ($deviceVals[$i] ?? 0)); ?>
                            <div class="bar-row">
                                <span class="bar-row-label" title="<?= h($r['device']) ?>"><?= h($r['device']) ?></span>
                                <div class="bar-row-track"><div class="bar-row-fill" style="width: <?= round(max(2, $v / $deviceMax * 100), 1) ?>%; background: <?= $barPalette[$i % 6] ?>;"></div></div>
                                <span class="bar-row-value"><strong><?= h($r['sessionsLabel']) ?></strong> <small>(<?= h($r['shareLabel']) ?>)</small></span>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$deviceRows): ?><p class="table-muted">Zatím bez dat o zařízeních, spusť GA4 sync.</p><?php endif; ?>
                    </div>
                </article>

                <article class="panel">
                    <div class="panel-header">
                        <h2>Noví vs vracející se <span>(GA4)</span></h2>
                        <span class="panel-total"><strong data-newreturning-total><?= h($data['charts']['newReturning']['totalLabel'] ?? '0') ?></strong> uživatelů</span>
                    </div>
                    <p class="panel-help">Poměr nových a vracejících se uživatelů za období. Vracející se = uživatelé − noví uživatelé (GA4 <code>newUsers</code>).</p>
                    <?php
                    $nrRows = $data['charts']['newReturning']['segments'] ?? [];
                    $nrVals = $data['charts']['newReturning']['values'] ?? [];
                    $nrMax = 1;
                    foreach ($nrRows as $i => $s) { $nrMax = max($nrMax, (int) ($s['value'] ?? ($nrVals[$i] ?? 0))); }
                    ?>
                    <div class="bar-chart" data-bar-newreturning>
                        <?php foreach ($nrRows as $i => $s): $v = (int) ($s['value'] ?? ($nrVals[$i] ?? 0)); ?>
                            <div class="bar-row">
                                <span class="bar-row-label" title="<?= h($s['label']) ?>"><?= h($s['label']) ?></span>
                                <div class="bar-row-track"><div class="bar-row-fill" style="width: <?= round(max(2, $v / $nrMax * 100), 1) ?>%; background: <?= $barPalette[$i % 6] ?>;"></div></div>
                                <span class="bar-row-value"><strong><?= h($s['valueLabel']) ?></strong> <small>(<?= h($s['shareLabel']) ?>)</small></span>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$nrRows): ?><p class="table-muted">Zatím bez dat, spusť GA4 sync.</p><?php endif; ?>
                    </div>
                </article>
            </aside>
            </div>

            <section class="tables-grid" aria-label="Tabulky">
                <article class="panel table-panel">
                    <div class="panel-header"><h2>Top stránky</h2><div class="seg-toggle" role="group" aria-label="Typ stránek"><button type="button" class="seg-btn is-active" data-pages-toggle="vstupni">Vstupní</button><button type="button" class="seg-btn" data-pages-toggle="vsechny">Všechny</button></div></div>
                    <p class="panel-help" data-pages-help>Top vstupní stránky, kde návštěva začala. GA4 dimenze <code>landingPage</code>. „Změna" = rozdíl proti předchozímu stejně dlouhému období. Přepni na „Všechny" pro nejnavštěvovanější stránky podle zobrazení.</p>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr data-pages-head><th>Stránka</th><th>Návštěvy</th><th>Změna</th></tr>
                            </thead>
                            <tbody data-pages-rows>
                                <?php foreach ($data['tables']['landingPages'] as $page):
                                    $changeClass = $page['change'] === null ? '' : ($page['change'] >= 0 ? 'positive' : 'negative');
                                    $arrow = $page['change'] === null ? '' : ($page['change'] >= 0 ? '&uarr;' : '&darr;');
                                ?>
                                    <tr>
                                        <td><?= h($page['path']) ?></td>
                                        <td><?= h(allstat_number($page['sessions'])) ?></td>
                                        <td class="<?= $changeClass ?>"><?= $arrow ?> <?= h($page['changeLabel']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <a class="text-link" href="admin/reports.php?domain_id=<?= (int) $data['domain']['id'] ?>&start=<?= h($data['range']['start']) ?>&end=<?= h($data['range']['end']) ?>#pages">Zobrazit všechny stránky <i data-lucide="arrow-right"></i></a>
                </article>

                <article class="panel table-panel">
                    <div class="panel-header"><h2>Nejčastější vyhledávací dotazy <span>(GSC)</span></h2></div>
                    <p class="panel-help">Co lidé hledali v Googlu předtím, než klikli na web. Z Google Search Console, vyplní se až po napojení GSC v <a href="admin/sources.php">Zdrojích dat</a>.</p>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr><th>Dotaz</th><th>Kliknutí</th><th>Zobrazení</th><th>CTR</th></tr>
                            </thead>
                            <tbody data-query-rows>
                                <?php foreach ($data['tables']['queries'] as $query): ?>
                                    <tr>
                                        <td><?= h($query['query']) ?></td>
                                        <td><?= h(allstat_number($query['clicks'])) ?></td>
                                        <td><?= h(allstat_number($query['impressions'])) ?></td>
                                        <td><?= h($query['ctrLabel']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <a class="text-link" href="admin/reports.php?domain_id=<?= (int) $data['domain']['id'] ?>&start=<?= h($data['range']['start']) ?>&end=<?= h($data['range']['end']) ?>#queries">Zobrazit všechny dotazy <i data-lucide="arrow-right"></i></a>
                </article>

                <article class="panel table-panel">
                    <div class="panel-header"><h2>Konkrétní referrery <span>(GA4)</span></h2></div>
                    <p class="panel-help">Reálné odkazující weby (GA4 dimenze <code>sessionSource</code>, medium = referral). Návštěvy z AI asistentů jsou označené odznakem <span class="status-badge status-ai">AI</span>.</p>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr><th>Referrer</th><th>Návštěvy</th><th>Podíl</th></tr>
                            </thead>
                            <tbody data-referrer-rows>
                                <?php foreach ($data['tables']['referrers'] as $ref): ?>
                                    <tr>
                                        <td><?= h($ref['source']) ?><?= !empty($ref['ai']) ? ' <span class="status-badge status-ai">AI · ' . h($ref['ai']) . '</span>' : '' ?></td>
                                        <td><?= h($ref['sessionsLabel']) ?></td>
                                        <td><?= h($ref['shareLabel']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$data['tables']['referrers']): ?>
                                    <tr><td colspan="3" class="table-muted">Zatím bez referral dat, spusť GA4 sync.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>

                <article class="panel table-panel">
                    <div class="panel-header"><h2>Návštěvy podle regionu <span>(GA4)</span></h2></div>
                    <p class="panel-help">Odkud návštěvníci jsou (GA4 dimenze <code>region</code>). Ukazuje, jestli zasahuješ správnou oblast.</p>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr><th>Region</th><th>Země</th><th>Návštěvy</th><th>Podíl</th></tr>
                            </thead>
                            <tbody data-geo-rows>
                                <?php foreach ($data['tables']['geo'] as $geo): ?>
                                    <tr>
                                        <td><?= h($geo['region']) ?></td>
                                        <td><?= h($geo['country']) ?></td>
                                        <td><?= h($geo['sessionsLabel']) ?></td>
                                        <td><?= h($geo['shareLabel']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$data['tables']['geo']): ?>
                                    <tr><td colspan="4" class="table-muted">Zatím bez geo dat, spusť sync.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>

                <article class="panel table-panel">
                    <div class="panel-header"><h2>Klíčové akce <span>(GA4)</span></h2></div>
                    <p class="panel-help">Které události uživatelé spouští (GA4 <code>eventName</code>). „Key events" = akce označené jako konverze (registrace, kontakt, stažení…).</p>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr><th>Akce</th><th>Počet</th><th>Z toho key events</th></tr>
                            </thead>
                            <tbody data-event-rows>
                                <?php foreach ($data['tables']['events'] as $event): ?>
                                    <tr>
                                        <td><?= h($event['event']) ?><?= $event['isKey'] ? ' <span class="status-badge status-ok">key</span>' : '' ?></td>
                                        <td><?= h($event['countLabel']) ?></td>
                                        <td><?= h($event['keyEventsLabel']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$data['tables']['events']): ?>
                                    <tr><td colspan="3" class="table-muted">Zatím bez event dat, spusť sync.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>

                <?php $utmCampaigns = $data['tables']['utm'] ?? []; ?>
                <article class="panel table-panel">
                    <div class="panel-header"><h2>Kampaně (UTM) <span>(GA4)</span></h2></div>
                    <p class="panel-help">Návštěvy z odkazů označených UTM parametry (<code>utm_campaign</code>, <code>utm_source</code>…), funguje pro Meta reklamy, newslettery i jakýkoli otagovaný odkaz. Štítek „varianta" (<code>utm_content</code>) odlišuje A/B verze stejné kampaně. Organická a přímá návštěvnost se sem nezahrnuje.</p>
                    <div class="utm-list" data-utm-list>
                        <?php foreach ($utmCampaigns as $c): ?>
                            <div class="utm-item">
                                <div class="utm-item-top">
                                    <span class="utm-name" title="utm_campaign"><?= h($c['campaignLabel']) ?></span>
                                    <span class="utm-value"><strong><?= h($c['sessionsLabel']) ?></strong> <small>návštěv · <?= h($c['shareLabel']) ?></small></span>
                                </div>
                                <div class="utm-track"><div class="utm-fill" style="width: <?= round(max(3, (float) $c['share']), 1) ?>%;"></div></div>
                                <div class="utm-tags">
                                    <span class="utm-chip" title="utm_source / utm_medium"><?= h($c['channelLabel']) ?></span>
                                    <?php if ($c['contentLabel'] !== '—'): ?><span class="utm-chip" title="utm_content, A/B varianta">varianta: <?= h($c['contentLabel']) ?></span><?php endif; ?>
                                    <span class="utm-chip<?= (int) $c['conversions'] > 0 ? ' utm-chip-ok' : '' ?>" title="Konverze z této kampaně (a podíl z jejích návštěv)">konverze: <?= h($c['conversionsLabel']) ?><?= $c['convRate'] !== '—' ? ' (' . h($c['convRate']) . ')' : '' ?></span>
                                </div>
                                <?php if ($c['pages']): ?>
                                    <div class="utm-pages">Kam přišli: <?= implode(' · ', array_map(static fn (array $p): string => h($p['path']) . ' <span class="table-muted">(' . h($p['sessionsLabel']) . ')</span>', $c['pages'])) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$utmCampaigns): ?>
                            <p class="table-muted">Zatím žádná návštěvnost s UTM parametry za zvolené období. Otaguj odkazy (např. <code>?utm_campaign=jaro&amp;utm_source=facebook&amp;utm_medium=cpc&amp;utm_content=banner1</code>) a po dalším GA4 syncu se kampaně objeví zde.</p>
                        <?php endif; ?>
                    </div>
                </article>

                <?php $funnel = $data['tables']['funnel'] ?? []; if (!empty($funnel['hasData'])): ?>
                <article class="panel table-panel table-panel-wide">
                    <div class="panel-header"><h2>Nákupní trychtýř <span>(e-commerce)</span></h2></div>
                    <p class="panel-help">Kroky nákupu z GA4 e-commerce událostí za období. <strong>Pokles</strong> = kolik % lidí mezi kroky odpadlo; největší pokles je <strong>úzké hrdlo</strong>. <?= h($funnel['overallLabel']) ?><?= $funnel['removeFromCart'] > 0 ? ' · Odebrání z košíku: ' . h($funnel['removeFromCartLabel']) : '' ?>.</p>
                    <div class="table-scroll">
                        <table>
                            <thead><tr><th>Krok</th><th>Počet</th><th>Podíl z vrcholu</th><th>Pokles vs. předchozí</th></tr></thead>
                            <tbody>
                                <?php foreach ($funnel['steps'] as $step): ?>
                                    <tr>
                                        <td><?= h($step['label']) ?><?= $step['isBottleneck'] ? ' <span class="status-badge status-warning">úzké hrdlo</span>' : '' ?></td>
                                        <td><?= h($step['countLabel']) ?></td>
                                        <td>
                                            <span style="display:inline-block;height:8px;border-radius:4px;background:#6366f1;width:<?= (int) round($step['share']) ?>%;min-width:3px;vertical-align:middle"></span>
                                            <?= h($step['shareLabel']) ?>
                                        </td>
                                        <td><?= $step['dropLabel'] !== '' ? h($step['dropLabel']) : '—' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
                <?php endif; ?>

                <?php $products = $data['tables']['topProducts'] ?? []; if ($products): ?>
                <article class="panel table-panel table-panel-wide">
                    <div class="panel-header"><h2>Top produkty <span>(e-commerce)</span></h2></div>
                    <p class="panel-help">Nejprodávanější produkty podle tržeb (GA4 <code>itemName</code>). <strong>Konverze</strong> = kolik % zobrazení produktu skončilo nákupem.</p>
                    <div class="table-scroll">
                        <table>
                            <thead><tr><th>Produkt</th><th>Zobrazení</th><th>Do košíku</th><th>Prodáno</th><th>Tržby</th><th>Konverze</th></tr></thead>
                            <tbody>
                                <?php foreach ($products as $p): ?>
                                    <tr>
                                        <td><?= h($p['name']) ?></td>
                                        <td><?= h($p['viewedLabel']) ?></td>
                                        <td><?= h($p['addedLabel']) ?></td>
                                        <td><?= h($p['purchasedLabel']) ?></td>
                                        <td><?= h($p['revenueLabel']) ?></td>
                                        <td><?= h($p['buyRateLabel']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
                <?php endif; ?>

                <?php $demo = $data['tables']['demographics'] ?? []; if (!empty($demo['hasData'])): ?>
                <article class="panel table-panel">
                    <div class="panel-header"><h2>Věk <span>(GA4)</span></h2></div>
                    <p class="panel-help">Věkové složení publika, <strong>modelovaný odhad</strong> (Google Signals). GA4 malé segmenty skrývá kvůli ochraně soukromí, takže součet nemusí dát 100 % a část je „Neurčeno".</p>
                    <div class="table-scroll">
                        <table>
                            <thead><tr><th>Věk</th><th>Uživatelé</th><th>Podíl</th></tr></thead>
                            <tbody>
                                <?php foreach ($demo['age'] as $a): ?>
                                    <tr><td><?= h($a['label']) ?></td><td><?= h($a['usersLabel']) ?></td><td><?= h($a['shareLabel']) ?></td></tr>
                                <?php endforeach; ?>
                                <?php if (!$demo['age']): ?><tr><td colspan="3" class="table-muted">Bez věkových dat (GA4 práh / Signals).</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
                <article class="panel table-panel">
                    <div class="panel-header"><h2>Pohlaví <span>(GA4)</span></h2></div>
                    <p class="panel-help">Rozdělení publika podle pohlaví, modelovaný odhad (Google Signals). Slouží k cílení na segmenty, ne k identifikaci jednotlivců.</p>
                    <div class="table-scroll">
                        <table>
                            <thead><tr><th>Pohlaví</th><th>Uživatelé</th><th>Podíl</th></tr></thead>
                            <tbody>
                                <?php foreach ($demo['gender'] as $g): ?>
                                    <tr><td><?= h($g['label']) ?></td><td><?= h($g['usersLabel']) ?></td><td><?= h($g['shareLabel']) ?></td></tr>
                                <?php endforeach; ?>
                                <?php if (!$demo['gender']): ?><tr><td colspan="3" class="table-muted">Bez dat o pohlaví (GA4 práh / Signals).</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
                <?php endif; ?>

                <article class="panel table-panel table-panel-wide">
                    <div class="panel-header"><h2>Přehled zdrojů dat</h2></div>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr><th>Zdroj</th><th>Stav</th><th>Poslední sync</th><th></th></tr>
                            </thead>
                            <tbody data-source-rows>
                                <?php foreach ($data['tables']['sources'] as $source): ?>
                                    <tr>
                                        <td><?= h($source['source']) ?></td>
                                        <td><span class="status-badge status-<?= h($source['status']) ?>"><?= h($statusLabels[$source['status']] ?? $source['status']) ?></span></td>
                                        <td><?= h($source['lastSync']) ?></td>
                                        <td><button class="table-icon-button" type="button" title="Obnovit" aria-label="Obnovit" data-refresh-dashboard><i data-lucide="refresh-cw"></i></button></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$data['tables']['sources']): ?>
                                    <tr><td colspan="4" class="table-muted">Zatím není napojený žádný zdroj dat.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <a class="text-link" href="admin/sources.php?domain_id=<?= (int) $data['domain']['id'] ?>">Správa zdrojů <i data-lucide="arrow-right"></i></a>
                </article>
            </section>
            <?php endif; ?>
        </main>
    </div>

    <?php $nonce = allstat_nonce(); ?>
    <script src="assets/vendor/chart.umd.min.js?v=<?= @filemtime(__DIR__ . '/assets/vendor/chart.umd.min.js') ?: '1' ?>" nonce="<?= h($nonce) ?>"></script>
    <script src="assets/vendor/lucide.min.js?v=<?= @filemtime(__DIR__ . '/assets/vendor/lucide.min.js') ?: '1' ?>" nonce="<?= h($nonce) ?>"></script>
    <script nonce="<?= h($nonce) ?>">window.ALLSTAT_DATA = <?= allstat_json($data) ?>;</script>
    <?php if ($isGenericView): ?>
    <script nonce="<?= h($nonce) ?>">window.ALLSTAT_PROVIDER = <?= allstat_json($providerPayload) ?>;</script>
    <?php endif; ?>
    <?php if ($isGrowthView): ?>
    <script nonce="<?= h($nonce) ?>">window.ALLSTAT_VIEW = 'growth'; window.ALLSTAT_GROWTH = <?= allstat_json($growthChart) ?>;</script>
    <?php endif; ?>
    <?php if ($isFunnelView): ?>
    <script nonce="<?= h($nonce) ?>">window.ALLSTAT_VIEW = 'funnel'; window.ALLSTAT_FUNNEL = <?= allstat_json($funnelChart) ?>;</script>
    <?php endif; ?>
    <script src="assets/js/app.js?v=<?= @filemtime(__DIR__ . '/assets/js/app.js') ?: '1' ?>" nonce="<?= h($nonce) ?>"></script>
    <script src="assets/js/select-search.js?v=<?= @filemtime(__DIR__ . '/assets/js/select-search.js') ?: '1' ?>" nonce="<?= h($nonce) ?>"></script>
    <?php if ($isGrowthView): ?>
    <script src="assets/js/growth.js?v=<?= @filemtime(__DIR__ . '/assets/js/growth.js') ?: '1' ?>" nonce="<?= h($nonce) ?>"></script>
    <?php endif; ?>
    <?php if ($isFunnelView && !$funnelError): ?>
    <script src="assets/js/funnel.js?v=<?= @filemtime(__DIR__ . '/assets/js/funnel.js') ?: '1' ?>" nonce="<?= h($nonce) ?>"></script>
    <?php endif; ?>
    <script src="assets/js/table-sort.js?v=<?= @filemtime(__DIR__ . '/assets/js/table-sort.js') ?: '1' ?>" nonce="<?= h($nonce) ?>"></script>
    <script nonce="<?= h($nonce) ?>">
    (function () {
        var btn = document.getElementById('shareAiBtn');
        var box = document.getElementById('shareResult');
        if (!btn || !box) { return; }
        var lastFmt = 'md';
        function gen(fmt) {
            lastFmt = fmt;
            btn.disabled = true;
            // Období + web čteme ŽIVĚ z filtru (skrytá pole rozsahu / výběr webu), ne ze zastaralých
            // data-atributů tlačítka. Po AJAX změně období na přehledu se ty atributy neaktualizují, takže
            // by report jinak vždy vzal původní rozsah. Na provider views to funguje taky (form se re-rendruje).
            var form = document.getElementById('dashboardFilters');
            var live = function (sel, fb) { var el = form && form.querySelector(sel); return (el && el.value) ? el.value : fb; };
            var body = new URLSearchParams({
                domain_id: live('[name="domain_id"]', btn.dataset.domain),
                source_id: btn.dataset.source,
                start: live('[data-date-start]', btn.dataset.start),
                end: live('[data-date-end]', btn.dataset.end),
                // Růst kanálů: report za měsíční okno pohledu (share-create.php si ho dopočítá z months).
                view: btn.dataset.view || '',
                months: btn.dataset.months || '',
                format: fmt, ttl: '15', csrf: btn.dataset.csrf
            });
            fetch('admin/share-create.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    btn.disabled = false;
                    if (!d.ok) { alert(d.message || 'Odkaz se nepodařilo vytvořit.'); return; }
                    box.querySelector('[data-share-url]').value = d.url;
                    var exp = (d.expires_at || '').slice(11, 16);
                    box.querySelector('[data-share-expiry]').textContent = ' · platí ' + d.ttl_minutes + ' min' + (exp ? ' (do ' + exp + ')' : '');
                    box.hidden = false;
                })
                .catch(function () { btn.disabled = false; alert('Chyba sítě.'); });
        }
        btn.addEventListener('click', function () { gen(lastFmt); });
        box.querySelectorAll('[data-share-fmt]').forEach(function (a) {
            a.addEventListener('click', function (e) { e.preventDefault(); gen(a.dataset.shareFmt); });
        });
        function flash(el, msg, orig, ms) { el.textContent = msg; setTimeout(function () { el.textContent = orig; }, ms || 1600); }
        // "Kopírovat text pro AI" — stáhne obsah reportu (same-origin) a dá ho do schránky; funguje v každé AI.
        var copyTextBtn = box.querySelector('[data-share-copy-text]');
        if (copyTextBtn) {
            copyTextBtn.addEventListener('click', function () {
                var url = box.querySelector('[data-share-url]').value;
                if (!url) { return; }
                copyTextBtn.disabled = true;
                copyTextBtn.textContent = 'Stahuji…';
                fetch(url, { headers: { 'Accept': 'text/plain' } })
                    .then(function (r) { if (!r.ok) { throw new Error('http'); } return r.text(); })
                    .then(function (text) {
                        if (navigator.clipboard) { return navigator.clipboard.writeText(text); }
                        var ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
                    })
                    .then(function () { copyTextBtn.disabled = false; flash(copyTextBtn, 'Text zkopírován ✓', 'Kopírovat text pro AI', 1800); })
                    .catch(function () { copyTextBtn.disabled = false; copyTextBtn.textContent = 'Kopírovat text pro AI'; alert('Text se nepodařilo načíst, odkaz možná vypršel. Vygeneruj nový (klikni na ✨).'); });
            });
        }
        var closeBtn = box.querySelector('[data-share-close]');
        if (closeBtn) { closeBtn.addEventListener('click', function () { box.hidden = true; }); }
    })();
    </script>
</body>
</html>
