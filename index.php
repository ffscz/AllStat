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
require_once __DIR__ . '/lib/version.php';
require_once __DIR__ . '/lib/layout.php';
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
$statusLabels = ['ok' => 'OK', 'warning' => t('Varování'), 'error' => t('Chyba')];
$granularityLabels = ['day' => t('Denně'), 'week' => t('Týdně'), 'month' => t('Měsíčně')];

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
            $funnelChart['longLabels'][] = ($trend['granularity'] === 'week' ? t('Týden od') . ' ' : '') . allstat_date_label($iso);
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
    } elseif (in_array(($selectedProvider['provider_key'] ?? ''), ['google_ads', 'microsoft_ads'], true)) {
        // Google Ads gets its own rich PPC view: derived ROAS/PNO/CPA/CPC/CTR/CPM KPIs from the synced
        // cost/clicks/impressions/conversions/conversion_value (account-level).
        $adsKpis = allstat_get_google_ads_kpis($pdo, (int) $domainId, $viewSourceId, $start, $end, 'day', (string) $selectedProvider['name']);
        $googleAdsNoConversions = !empty($adsKpis['noConversions']);
        $googleAdsCampaigns = allstat_get_google_ads_campaigns($pdo, (int) $domainId, $viewSourceId, $start, $end, 15);
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


// Název, drobečky a nápověda podle pohledu: Růst kanálů je úvodní Přehled, ostatní pohledy jsou pod ním.
$overviewCrumb = ['label' => t('Přehled'), 'href' => 'index.php?domain_id=' . (int) $data['domain']['id']];
$pageSubtitle = '';
if ($isGenericView) {
    // name = služba (Facebook Pages), label = účet nebo stránka (KIC KK).
    $pageTitle = (string) $selectedProvider['name'];
    $pageSubtitle = (string) $selectedProvider['label'];
    $pageCrumbs = [$overviewCrumb, ['label' => $selectedProvider['name'] . ' · ' . $selectedProvider['label'], 'href' => null]];
} elseif ($isFunnelView) {
    $pageTitle = t('Trychtýř: {name}', ['name' => $funnel['name']]);
    $pageCrumbs = [$overviewCrumb, ['label' => $pageTitle, 'href' => null]];
} elseif ($isGrowthView) {
    $pageTitle = t('Přehled');
    $pageSubtitle = t('Jak rostou kanály webu {url} · uzavřené měsíce {range}', ['url' => $data['domain']['url'] ?? '', 'range' => $growth['window']['rangeLabel'] ?? '']);
    $pageCrumbs = [['label' => $pageTitle, 'href' => null]];
} else {
    $pageTitle = t('Přehled (GA4 + GSC)');
    $pageCrumbs = [$overviewCrumb, ['label' => $pageTitle, 'href' => null]];
}

allstat_layout_start($pdo, $config, $user, [
    'title' => $pageTitle,
    'active' => 'dashboard',
    'crumbs' => $pageCrumbs,
    'help' => $isFunnelView ? 'trychtyre' : '',
    'domainId' => (int) $data['domain']['id'],
    'bodyAttr' => 'data-dashboard',
]);

include __DIR__ . '/views/_dashboard-head.php';

if ($isGenericView) {
    include __DIR__ . '/views/provider.php';
} elseif ($isGrowthView) {
    if ($growthError) {
        echo '<div class="callout tone-down" role="alert">' . t('Data pro Růst kanálů se nepodařilo načíst. Zkus obnovit stránku, případně otevři {link}.', ['link' => '<a href="?view=overview&amp;domain_id=' . (int) $data['domain']['id'] . '">' . h(t('Přehled (GA4 + GSC)')) . '</a>']) . '</div>';
    } else {
        include __DIR__ . '/views/growth.php';
    }
} elseif ($isFunnelView) {
    if ($funnelError) {
        echo '<div class="callout tone-down" role="alert">' . t('Data trychtýře se nepodařilo načíst. Zkus obnovit stránku, případně otevři {link}.', ['link' => '<a href="?view=overview&amp;domain_id=' . (int) $data['domain']['id'] . '">' . h(t('Přehled (GA4 + GSC)')) . '</a>']) . '</div>';
    } else {
        include __DIR__ . '/views/funnel.php';
    }
} else {
    include __DIR__ . '/views/overview.php';
}

// Data pro grafy a AJAX obnovu (assets/js/app.js, growth.js, funnel.js) jdou před skripty stránky.
$nonce = allstat_nonce();
$inline = '<script nonce="' . h($nonce) . '">window.ALLSTAT_DATA = ' . allstat_json($data) . ';'
    . ($isGenericView ? ' window.ALLSTAT_PROVIDER = ' . allstat_json($providerPayload) . ';' : '')
    . ($isGrowthView ? " window.ALLSTAT_VIEW = 'growth'; window.ALLSTAT_GROWTH = " . allstat_json($growthChart) . ';' : '')
    . ($isFunnelView ? " window.ALLSTAT_VIEW = 'funnel'; window.ALLSTAT_FUNNEL = " . allstat_json($funnelChart) . ';' : '')
    . '</script>';
$scripts = ['assets/vendor/chart.umd.min.js', 'assets/js/app.js', 'assets/js/select-search.js'];
if ($isGrowthView) {
    $scripts[] = 'assets/js/growth.js';
}
if ($isFunnelView && !$funnelError) {
    $scripts[] = 'assets/js/funnel.js';
}
$scripts[] = 'assets/js/table-sort.js';
$scripts[] = 'assets/js/share-ai.js';
allstat_layout_end($config, $scripts, $inline);
