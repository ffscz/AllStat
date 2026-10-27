<?php

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/repository.php';
require __DIR__ . '/_layout.php';

$pdo = allstat_admin_require_database($pdo, $config);
$user = allstat_require_user($pdo, $config);
$defaultEnd = (new DateTimeImmutable('today'))->format('Y-m-d');
$defaultStart = (new DateTimeImmutable('today'))->modify('-6 days')->format('Y-m-d');
$start = allstat_normalize_date($_GET['start'] ?? null, $defaultStart);
$end = allstat_normalize_date($_GET['end'] ?? null, $defaultEnd);
[$start, $end] = allstat_limited_range($start, $end);
$domains = allstat_admin_domains($pdo, false);
if (!$domains && allstat_domain_scope() !== null) {
    http_response_code(403);
    allstat_admin_header(t('Reporty'), 'reports', $user, $config);
    echo '<section class="card"><div class="empty"><span class="empty-title">' . h(t('Zatím nemáte přidělený web')) . '</span>'
        . '<span class="empty-desc">' . h(t('Účet je aktivní, ale administrátor vám zatím nepřidělil žádný web, nebo jsou přidělené weby vypnuté. Požádejte ho o přístup.')) . '</span>'
        . '</div></section>';
    allstat_admin_footer($config);
    exit;
}
// Web z URL se zapamatuje, bez parametru platí web zvolený jinde v administraci (přehled, zdroje…).
$domainId = allstat_current_domain_id($pdo, $domains, filter_input(INPUT_GET, 'domain_id', FILTER_VALIDATE_INT) ?: null) ?: 1;

// Vstupní stránky (count + jedna stránka výsledků) - sdílí to HTML render i AJAX partial níže.
function allstat_report_landing_pages(PDO $pdo, int $domainId, string $start, string $end, int $perPage, int $page): array
{
    $totalRow = allstat_fetch_one($pdo, "
        SELECT COUNT(DISTINCT path) AS total
        FROM landing_pages_daily
        WHERE domain_id = ? AND metric_date BETWEEN ? AND ? AND path <> '' AND path <> '(not set)'
    ", [$domainId, $start, $end]);
    $total = (int) ($totalRow['total'] ?? 0);
    $pageCount = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, $page), $pageCount);
    $offset = ($page - 1) * $perPage;

    $statement = $pdo->prepare("
        SELECT path, SUM(sessions) AS sessions, SUM(conversions) AS conversions
        FROM landing_pages_daily
        WHERE domain_id = ? AND metric_date BETWEEN ? AND ? AND path <> '' AND path <> '(not set)'
        GROUP BY path
        ORDER BY sessions DESC
        LIMIT $perPage OFFSET $offset
    ");
    $statement->execute([$domainId, $start, $end]);

    return ['rows' => $statement->fetchAll(), 'total' => $total, 'page' => $page, 'pageCount' => $pageCount];
}

// Lehký AJAX partial pro stránkování vstupních stránek: jen 2 dotazy a exit - nerenderuje se zbytek
// reportu (šetří DB) a stránka se nerefreshuje (nepřebije se tak zvolená záložka).
if (($_GET['partial'] ?? '') === 'landing_pages') {
    $perPage = max(10, min(200, (int) ($_GET['per_page'] ?? 50)));
    $lp = allstat_report_landing_pages($pdo, $domainId, $start, $end, $perPage, max(1, (int) ($_GET['page'] ?? 1)));
    header('Content-Type: application/json; charset=utf-8');
    echo allstat_json(['ok' => true, 'total' => $lp['total'], 'page' => $lp['page'], 'pageCount' => $lp['pageCount'],
        'rows' => array_map(static fn (array $r): array => [
            'path' => (string) $r['path'],
            'sessions' => allstat_number((int) $r['sessions']),
            'conversions' => allstat_number((int) $r['conversions']),
        ], $lp['rows'])]);
    exit;
}

$data = allstat_get_dashboard_data($pdo, $domainId, $start, $end);

if (($_GET['download'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="allstat-report-' . $start . '-' . $end . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Metrika', 'Hodnota', 'Změna']);
    foreach ($data['kpis'] as $kpi) {
        fputcsv($out, [$kpi['label'], $kpi['displayValue'], $kpi['changeLabel']]);
    }
    fclose($out);
    exit;
}

allstat_admin_header(t('Reporty'), 'reports', $user, $config);

// Český popisek období pro nadpisy sekcí (místo ISO "2026-06-30 → 2026-07-06").
$rangeLabel = allstat_date_label($start) . ' – ' . allstat_date_label($end);
$quickRanges = [
    t('posledních 7 dní') => 6,
    t('posledních 30 dní') => 29,
    t('posledních 90 dní') => 89,
];
// Metriky napojených služeb mimo GA4/GSC (Meta, LinkedIn, Clarity, Meta Ads…) - počítá se už tady,
// protože každá kategorie služeb (sociální sítě / analytika / PPC…) má VLASTNÍ kartu i záložku
// a záložky se kreslí hned v hlavičce reportu.
$pmRows = [];
if (allstat_table_exists($pdo, 'provider_metrics_daily')) {
    // Jen účtové (nedimenzované) řádky - per-kampaň/per-stránka rozpady by tu součty zdvojily.
    $pmRows = allstat_fetch_all($pdo, "
        SELECT pm.connection_id, COALESCE(NULLIF(conn.account_label, ''), s.name) AS source_name,
               s.provider_key, s.name AS provider_name, s.category, pm.metric_key, SUM(pm.metric_value) AS total
        FROM provider_metrics_daily pm
        INNER JOIN data_sources s ON s.id = pm.source_id
        LEFT JOIN domain_sources conn ON conn.id = pm.connection_id
        WHERE pm.domain_id = ? AND pm.metric_date BETWEEN ? AND ? AND COALESCE(pm.dimension, '') = ''
        GROUP BY pm.connection_id, source_name, s.provider_key, provider_name, s.category, pm.metric_key
        ORDER BY source_name ASC, pm.metric_key ASC
    ", [$domainId, $start, $end]);
}
$pmBySource = [];
foreach ($pmRows as $r) {
    // České popisky kurátorovaných metrik; nekurátorované klíče zůstávají syrové (viditelně technické).
    $labels = allstat_provider_metric_labels((string) $r['provider_key']);
    $r['metric_label'] = $labels[$r['metric_key']] ?? null;
    $pmBySource[$r['source_name']][] = $r;
}
// V dlaždici napřed kurátorované metriky (v pořadí definice popisků), syrové klíče až za nimi.
foreach ($pmBySource as &$pmGroupRows) {
    $order = array_flip(array_keys(allstat_provider_metric_labels((string) $pmGroupRows[0]['provider_key'])));
    usort($pmGroupRows, static fn (array $a, array $b): int =>
        [($order[$a['metric_key']] ?? PHP_INT_MAX), $a['metric_key']] <=> [($order[$b['metric_key']] ?? PHP_INT_MAX), $b['metric_key']]);
}
unset($pmGroupRows);

// Kategorie služeb: záložka + nadpis + popis karty. Klíč = data_sources.category (a zároveň klíč
// záložky, proto jen [a-z]). Vykreslí se jen kategorie, které mají napojenou službu s daty.
$pmCatMeta = [
    'social' => ['tab' => t('Sociální sítě'), 'title' => t('Sociální sítě'), 'desc' => t('Dosah, zapojení a sledující na napojených profilech (Facebook, Instagram, LinkedIn…).')],
    'analytics' => ['tab' => 'MS Clarity', 'title' => t('MS Clarity (chování na webu)'), 'desc' => t('Chování návštěvníků na webu z Microsoft Clarity, relace, boti, čas na webu, scroll.')],
    'ppc' => ['tab' => t('Reklama (PPC)'), 'title' => t('Reklama (PPC)'), 'desc' => t('Výkon placených kampaní (Meta Ads…), útrata, zobrazení, prokliky, konverze.')],
    'seo' => ['tab' => t('SEO nástroje'), 'title' => t('SEO nástroje'), 'desc' => t('Indexace a viditelnost z napojených SEO nástrojů.')],
    'other' => ['tab' => t('Další služby'), 'title' => t('Další služby'), 'desc' => t('Metriky z ostatních napojených služeb.')],
];
$pmByCategory = [];
foreach (array_keys($pmCatMeta) as $pmCatKey) {
    foreach ($pmBySource as $pmName => $pmRows2) {
        $rowCat = (string) $pmRows2[0]['category'];
        $bucket = isset($pmCatMeta[$rowCat]) ? $rowCat : 'other';
        if ($bucket === $pmCatKey) {
            $pmByCategory[$pmCatKey][$pmName] = $pmRows2;
        }
    }
}

// Záložky: každá sekce (karta) nese data-report-tab; přepínání řeší admin.js bez reloadu,
// volba se drží v URL hashi (#tab-…). „Vše" = výchozí, nic není schované.
// MS Clarity patří mezi webové statistiky (hned za GSC) - je to třetí analytika webu vedle GA4/GSC;
// sociální sítě, PPC a spol. až za obsahové záložky.
$reportTabs = [
    'all' => t('Vše'),
    'pages' => t('Stránky'),
    'sources' => t('Zdroje návštěv'),
    'audience' => t('Publikum'),
    'search' => t('Vyhledávání (GSC)'),
];
if (isset($pmByCategory['analytics'])) {
    $reportTabs['analytics'] = $pmCatMeta['analytics']['tab'];
}
$reportTabs['actions'] = t('Klíčové akce');
foreach ($pmByCategory as $pmCatKey => $pmTiles) {
    if ($pmCatKey !== 'analytics') {
        $reportTabs[$pmCatKey] = $pmCatMeta[$pmCatKey]['tab'];
    }
}

// Karta jedné kategorie služeb (dlaždice po účtech) - volá se na dvou místech: MS Clarity hned
// za GSC dotazy (uvnitř .report-grid, přes celou šířku), ostatní kategorie na konci reportu.
function allstat_report_service_card(string $catKey, array $tiles, array $meta, string $rangeLabel, string $cardClass = ''): void
{
    ?>
    <section class="card<?= $cardClass !== '' ? ' ' . h($cardClass) : '' ?>" id="services-<?= h($catKey) ?>" data-report-tab="<?= h($catKey) ?>">
        <div class="card-head">
            <div>
                <h2 class="card-title"><?= h($meta['title']) ?></h2>
                <p class="card-desc"><?= h($meta['desc']) ?> <?= h(t('Za {range}. Souhrn za období, detailní pohled najdeš na dashboardu v přepínači „Zdroj dat".', ['range' => $rangeLabel])) ?></p>
            </div>
        </div>
        <div class="card-body">
            <div class="grid grid-tiles">
                <?php foreach ($tiles as $sourceName => $rows): $pmProvider = (string) $rows[0]['provider_key']; $pmProviderName = (string) $rows[0]['provider_name']; ?>
                    <div class="card service">
                        <div class="service-head">
                            <?= allstat_source_tile($pmProvider, 'md') ?>
                            <div class="name-stack">
                                <span class="name-main ellipsis"><?= h($sourceName) ?></span>
                                <?php if ($pmProviderName !== '' && $pmProviderName !== $sourceName): ?><span class="name-sub ellipsis"><?= h($pmProviderName) ?></span><?php endif; ?>
                            </div>
                        </div>
                        <div class="rows">
                            <?php foreach ($rows as $r): ?>
                                <div class="row" title="<?= h($r['metric_key']) ?>">
                                    <span class="row-label"><?= $r['metric_label'] !== null ? h($r['metric_label']) : h($r['metric_key']) ?></span>
                                    <span class="row-value"><?= h(allstat_number((float) $r['total'], 0)) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php
}
?>
<section class="card">
    <div class="card-head">
        <div>
            <h2 class="card-title"><?= h(t('Rychlý report')) ?></h2>
            <p class="card-desc"><?= t('Souhrnné KPI za zvolený web a období (<strong>{range}</strong>) s možností stažení jako CSV. Záložkami níže si přepneš, kterou oblast chceš vidět, „Vše" zobrazí kompletní report.', ['range' => h($rangeLabel)]) ?></p>
        </div>
        <a class="btn btn-primary" href="reports.php?domain_id=<?= (int) $domainId ?>&start=<?= h($start) ?>&end=<?= h($end) ?>&download=csv"><?= allstat_icon('download', 'icon-sm') ?><span><?= h(t('Stáhnout CSV')) ?></span></a>
    </div>
    <div class="card-body section">
        <form method="get" class="form-grid">
            <label class="field"><span class="field-label"><?= h(t('Web')) ?></span><select class="input" name="domain_id" data-autosubmit><?php foreach ($domains as $domain): ?><option value="<?= (int) $domain['id'] ?>" <?= $domainId === (int) $domain['id'] ? 'selected' : '' ?>><?= h($domain['url']) ?></option><?php endforeach; ?></select></label>
            <label class="field"><span class="field-label"><?= h(t('Od')) ?></span><input class="input" type="date" name="start" value="<?= h($start) ?>"></label>
            <label class="field"><span class="field-label"><?= h(t('Do')) ?></span><input class="input" type="date" name="end" value="<?= h($end) ?>"></label>
            <div class="form-actions field-full"><button class="btn btn-primary" type="submit"><?= h(t('Načíst report')) ?></button></div>
        </form>
        <div class="form-actions">
            <span class="is-muted"><?= h(t('Rychlá volba:')) ?></span>
            <div class="seg" role="group" aria-label="<?= h(t('Rychlá volba:')) ?>">
                <?php foreach ($quickRanges as $qrLabel => $qrDays): $qrStart = (new DateTimeImmutable('today'))->modify('-' . $qrDays . ' days')->format('Y-m-d'); $qrEnd = (new DateTimeImmutable('today'))->format('Y-m-d'); ?>
                    <a class="seg-item"<?= $start === $qrStart && $end === $qrEnd ? ' aria-current="true"' : '' ?> href="reports.php?domain_id=<?= (int) $domainId ?>&start=<?= h($qrStart) ?>&end=<?= h($qrEnd) ?>"><?= h($qrLabel) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="table-wrap">
            <nav class="seg" role="group" aria-label="<?= h(t('Sekce reportu')) ?>" data-report-tabs>
                <?php foreach ($reportTabs as $tabKey => $tabLabel): ?>
                    <button type="button" class="seg-item" aria-pressed="<?= $tabKey === 'all' ? 'true' : 'false' ?>" data-report-tab-btn="<?= h($tabKey) ?>"><?= h($tabLabel) ?></button>
                <?php endforeach; ?>
            </nav>
        </div>
    </div>
</section>

<div class="grid grid-kpis">
    <?php foreach ($data['kpis'] as $kpi): ?>
        <div class="card kpi"><span class="metric-label"><?= h($kpi['label']) ?></span><span class="metric-value num"><?= h($kpi['displayValue']) ?></span><span class="metric-note"><?= h($kpi['changeLabel']) ?> <?= h(t('vs. předchozí období')) ?></span></div>
    <?php endforeach; ?>
</div>

<?php
$perPage = max(10, min(200, (int) ($_GET['per_page'] ?? 50)));
$lp = allstat_report_landing_pages($pdo, $domainId, $start, $end, $perPage, max(1, (int) ($_GET['page'] ?? 1)));
$allPages = $lp['rows'];
$totalPages = $lp['total'];
$page = $lp['page'];
$totalPagination = $lp['pageCount'];

// Fallback bez JS: plný reload s kotvou #tab-pages, ať uživatel zůstane na záložce Stránky.
$baseQuery = http_build_query(['domain_id' => $domainId, 'start' => $start, 'end' => $end, 'per_page' => $perPage]);
$pageLink = static fn(int $n): string => 'reports.php?' . $baseQuery . '&page=' . $n . '#tab-pages';
?>
<section class="card" id="landing-pages" data-report-tab="pages" data-landing-pages>
    <div class="card-head">
        <div>
            <h2 class="card-title"><?= h(t('Všechny vstupní stránky')) ?></h2>
            <p class="card-desc"><?= t('Kterou stránkou návštěva začala, první stránka, na kterou člověk z vyhledávání, odkazu či reklamy přišel. Setříděno podle návštěv za {range}. Celkem <strong data-lp-total>{total}</strong> různých cest, stránka <span data-lp-page>{page}</span>/<span data-lp-pages>{pages}</span>.', ['range' => h($rangeLabel), 'total' => $totalPages, 'page' => $page, 'pages' => $totalPagination]) ?></p>
        </div>
        <form method="get">
            <input type="hidden" name="domain_id" value="<?= (int) $domainId ?>">
            <input type="hidden" name="start" value="<?= h($start) ?>">
            <input type="hidden" name="end" value="<?= h($end) ?>">
            <input type="hidden" name="page" value="1">
            <label class="field">
                <span class="field-label"><?= h(t('Na stránku')) ?></span>
                <select class="input" name="per_page" data-lp-perpage>
                    <?php foreach ([25, 50, 100, 200] as $opt): ?>
                        <option value="<?= $opt ?>" <?= $perPage === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
    </div>
    <div class="card-body">
        <div class="table-wrap">
            <table class="table table-plain">
                <colgroup><col><col class="col-md"><col class="col-md"></colgroup>
                <thead><tr><th scope="col"><?= h(t('Stránka')) ?></th><th scope="col"><?= h(t('Návštěvy')) ?></th><th scope="col"><?= h(t('Konverze')) ?></th></tr></thead>
                <tbody data-lp-rows>
                    <?php foreach ($allPages as $row): ?>
                        <tr>
                            <td class="ellipsis" title="<?= h($row['path']) ?>"><?= h($row['path']) ?></td>
                            <td class="num"><?= h(allstat_number((int) $row['sessions'])) ?></td>
                            <td class="num"><?= h(allstat_number((int) $row['conversions'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$allPages): ?>
                        <tr><td colspan="3" class="is-muted"><?= t('Žádná data pro toto období. Spusť sync v <a href="sources.php">Zdroje dat</a>.') ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <nav class="card-foot" aria-label="<?= h(t('Stránkování')) ?>" data-lp-nav<?= $totalPagination <= 1 ? ' hidden' : '' ?>>
        <a class="btn btn-secondary btn-sm"<?= $page <= 1 ? ' aria-disabled="true"' : '' ?> data-lp-prev href="<?= $page > 1 ? h($pageLink($page - 1)) : '#' ?>"><span>← <?= h(t('Předchozí')) ?></span></a>
        <span class="is-muted"><?= t('Stránka {page} z {pages}', ['page' => '<span data-lp-page>' . $page . '</span>', 'pages' => '<span data-lp-pages>' . $totalPagination . '</span>']) ?></span>
        <a class="btn btn-secondary btn-sm"<?= $page >= $totalPagination ? ' aria-disabled="true"' : '' ?> data-lp-next href="<?= $page < $totalPagination ? h($pageLink($page + 1)) : '#' ?>"><span><?= h(t('Další')) ?> →</span></a>
    </nav>
</section>

<?php
// ---- Plné reporty (mirror dashboard panelů, ne­zkrácené) ----
$reportLimit = 100;
$pct = static fn (int $part, int $total): string => allstat_percent($total > 0 ? $part / $total * 100 : 0, 1);

// Podíl jako vizuální pruh + procento - pro laika čitelnější než holé číslo.
$shareCell = static function (float $share): string {
    return '<div class="meter"><span class="num">' . h(allstat_percent($share, 1)) . '</span><div class="track"><div class="track-fill" style="width: ' . round(max(2, min(100, $share))) . '%;"></div></div></div>';
};

// Dlouhé tabulky ukazují top 10; zbytek schová .row-extra + tlačítko „Zobrazit dalších X" (admin.js).
$rowExtra = static fn (int $i): string => $i >= 10 ? ' class="row-extra"' : '';
$showMore = static function (int $count): string {
    return $count > 10 ? '<div class="card-foot"><button type="button" class="btn btn-secondary btn-sm" data-show-more>' . h(tn($count - 10, 'Zobrazit další 1 řádek', 'Zobrazit další {count} řádky', 'Zobrazit dalších {count} řádků')) . '</button></div>' : '';
};

// Zdroje návštěv (GA4 channel groups)
$trafficRows = allstat_fetch_all($pdo, "
    SELECT source, SUM(sessions) AS sessions, SUM(conversions) AS conversions
    FROM traffic_sources_daily
    WHERE domain_id = ? AND metric_date BETWEEN ? AND ?
    GROUP BY source ORDER BY sessions DESC
", [$domainId, $start, $end]);
$trafficTotal = (int) array_sum(array_column($trafficRows, 'sessions'));

// Top stránky (všechny, podle zobrazení)
$pageRows = allstat_fetch_all($pdo, "
    SELECT path, SUM(views) AS views, SUM(sessions) AS sessions, SUM(conversions) AS conversions
    FROM pages_daily
    WHERE domain_id = ? AND metric_date BETWEEN ? AND ? AND path <> '' AND path <> '(not set)'
    GROUP BY path ORDER BY views DESC LIMIT $reportLimit
", [$domainId, $start, $end]);
$pageViewsTotal = (int) array_sum(array_column($pageRows, 'views'));

// Vyhledávací dotazy (GSC)
$queryRows = allstat_fetch_all($pdo, "
    SELECT query_text, SUM(clicks) AS clicks, SUM(impressions) AS impressions, CASE WHEN SUM(impressions) > 0 THEN SUM(position * impressions) / SUM(impressions) ELSE AVG(position) END AS position
    FROM search_queries_daily
    WHERE domain_id = ? AND metric_date BETWEEN ? AND ?
    GROUP BY query_text ORDER BY clicks DESC LIMIT $reportLimit
", [$domainId, $start, $end]);

// Zařízení
$deviceRows = allstat_fetch_all($pdo, "
    SELECT device, SUM(sessions) AS sessions, SUM(conversions) AS conversions
    FROM device_daily
    WHERE domain_id = ? AND metric_date BETWEEN ? AND ? AND device <> '' AND device <> '(other)'
    GROUP BY device ORDER BY sessions DESC
", [$domainId, $start, $end]);
$deviceTotal = (int) array_sum(array_column($deviceRows, 'sessions'));

// Konkrétní referrery
$referrerRows = allstat_fetch_all($pdo, "
    SELECT source, SUM(sessions) AS sessions, SUM(conversions) AS conversions
    FROM referrers_daily
    WHERE domain_id = ? AND metric_date BETWEEN ? AND ?
    GROUP BY source ORDER BY sessions DESC LIMIT $reportLimit
", [$domainId, $start, $end]);
$referrerTotal = (int) array_sum(array_column($referrerRows, 'sessions'));

// Regiony
$geoRows = allstat_fetch_all($pdo, "
    SELECT country, region, SUM(sessions) AS sessions, SUM(conversions) AS conversions
    FROM geo_daily
    WHERE domain_id = ? AND metric_date BETWEEN ? AND ?
    GROUP BY country, region ORDER BY sessions DESC LIMIT $reportLimit
", [$domainId, $start, $end]);
$geoTotal = (int) array_sum(array_column($geoRows, 'sessions'));

// Klíčové akce (events)
$eventRows = allstat_fetch_all($pdo, "
    SELECT event_name, SUM(event_count) AS event_count, SUM(key_events) AS key_events
    FROM events_daily
    WHERE domain_id = ? AND metric_date BETWEEN ? AND ?
    GROUP BY event_name ORDER BY event_count DESC LIMIT $reportLimit
", [$domainId, $start, $end]);
?>
<div class="grid grid-2 report-grid">
<section class="card report-half" id="traffic-sources" data-report-tab="sources">
    <div class="card-head"><div><h2 class="card-title"><?= h(t('Zdroje návštěv (GA4)')) ?></h2><p class="card-desc"><?= (t('Odkud lidé přišli: z vyhledávačů (Organické vyhledávání), napřímo (zadali adresu nebo měli záložku), z odkazů na jiných webech, ze sociálních sítí nebo z reklamy. Za {range}. <span class="is-muted">GA4 dimenze <code>sessionDefaultChannelGroup</code>.</span>', ['range' => h($rangeLabel)])) ?></p></div></div>
    <div class="card-body">
        <div class="table-wrap">
            <table class="table table-plain">
                <colgroup><col><col class="col-sm"><col class="col-sm"><col class="col-sm"></colgroup>
                <thead><tr><th scope="col"><?= h(t('Kanál')) ?></th><th scope="col"><?= h(t('Návštěvy')) ?></th><th scope="col"><?= h(t('Podíl')) ?></th><th scope="col"><?= h(t('Konverze')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($trafficRows as $r): ?>
                        <tr><td class="ellipsis"><?= h(allstat_ga4_channel_label((string) $r['source'])) ?></td><td class="num"><?= h(allstat_number((int) $r['sessions'])) ?></td><td><?= $shareCell($trafficTotal > 0 ? (int) $r['sessions'] / $trafficTotal * 100 : 0) ?></td><td class="num"><?= h(allstat_number((int) $r['conversions'])) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$trafficRows): ?><tr><td colspan="4" class="is-muted"><?= h(t('Žádná data, spusť GA4 sync.')) ?></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="card report-half" id="devices" data-report-tab="audience">
    <div class="card-head"><div><h2 class="card-title"><?= h(t('Zařízení (GA4)')) ?></h2><p class="card-desc"><?= (t('Z čeho lidé web prohlížejí, mobil, počítač, tablet. Když převažuje mobil, musí web především dobře fungovat na malém displeji. Za {range}. <span class="is-muted">GA4 dimenze <code>deviceCategory</code>.</span>', ['range' => h($rangeLabel)])) ?></p></div></div>
    <div class="card-body">
        <div class="table-wrap">
            <table class="table table-plain">
                <colgroup><col><col class="col-sm"><col class="col-sm"><col class="col-sm"></colgroup>
                <thead><tr><th scope="col"><?= h(t('Zařízení')) ?></th><th scope="col"><?= h(t('Návštěvy')) ?></th><th scope="col"><?= h(t('Podíl')) ?></th><th scope="col"><?= h(t('Konverze')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($deviceRows as $r): ?>
                        <tr><td class="ellipsis"><?= h(allstat_device_label((string) $r['device'])) ?></td><td class="num"><?= h(allstat_number((int) $r['sessions'])) ?></td><td><?= $shareCell($deviceTotal > 0 ? (int) $r['sessions'] / $deviceTotal * 100 : 0) ?></td><td class="num"><?= h(allstat_number((int) $r['conversions'])) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$deviceRows): ?><tr><td colspan="4" class="is-muted"><?= h(t('Žádná data, spusť GA4 sync.')) ?></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
</div><!-- /.report-grid -->

<section class="card report-full" id="pages" data-report-tab="pages">
    <div class="card-head"><div><h2 class="card-title"><?= h(t('Top stránky (všechny podle zobrazení)')) ?></h2><p class="card-desc"><?= (t('Které stránky si lidé nejvíc prohlížejí. „Zobrazení" počítá i opakovaná otevření stejné stránky, „Návštěvy" jednotlivé příchody. Za {range}. <span class="is-muted">GA4 <code>pagePath</code> / <code>screenPageViews</code> · top {limit}.</span>', ['range' => h($rangeLabel), 'limit' => $reportLimit])) ?></p></div></div>
    <div class="card-body">
        <div class="table-wrap">
            <table class="table table-plain">
                <colgroup><col><col class="col-sm"><col class="col-md"><col class="col-sm"><col class="col-sm"></colgroup>
                <thead><tr><th scope="col"><?= h(t('Stránka')) ?></th><th scope="col"><?= h(t('Zobrazení')) ?></th><th scope="col"><?= h(t('Podíl')) ?></th><th scope="col"><?= h(t('Návštěvy')) ?></th><th scope="col"><?= h(t('Konverze')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($pageRows as $i => $r): ?>
                        <tr<?= $rowExtra($i) ?>><td class="ellipsis" title="<?= h($r['path']) ?>"><?= h($r['path']) ?></td><td class="num"><?= h(allstat_number((int) $r['views'])) ?></td><td><?= $shareCell($pageViewsTotal > 0 ? (int) $r['views'] / $pageViewsTotal * 100 : 0) ?></td><td class="num"><?= h(allstat_number((int) $r['sessions'])) ?></td><td class="num"><?= h(allstat_number((int) $r['conversions'])) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$pageRows): ?><tr><td colspan="5" class="is-muted"><?= h(t('Žádná data, spusť GA4 sync.')) ?></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?= $showMore(count($pageRows)) ?>
</section>

<section class="card report-full" id="queries" data-report-tab="search">
    <div class="card-head"><div><h2 class="card-title"><?= h(t('Vyhledávací dotazy (GSC)')) ?></h2><p class="card-desc"><?= (t('Co lidé hledali v Googlu, než klikli na tvůj web. „Zobrazení" = kolikrát se web u dotazu ukázal ve výsledcích, „CTR" = jak často z toho lidé klikli, „Pozice" = průměrné pořadí ve výsledcích (menší číslo = výš). Za {range}. <span class="is-muted">Z Google Search Console · top {limit}.</span>', ['range' => h($rangeLabel), 'limit' => $reportLimit])) ?></p></div></div>
    <div class="card-body">
        <div class="table-wrap">
            <table class="table table-plain">
                <colgroup><col><col class="col-sm"><col class="col-sm"><col class="col-sm"><col class="col-sm"></colgroup>
                <thead><tr><th scope="col"><?= h(t('Dotaz')) ?></th><th scope="col"><?= h(t('Kliknutí')) ?></th><th scope="col"><?= h(t('Zobrazení')) ?></th><th scope="col">CTR</th><th scope="col"><?= h(t('Pozice')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($queryRows as $i => $r):
                        $clicks = (int) $r['clicks']; $impr = (int) $r['impressions'];
                    ?>
                        <tr<?= $rowExtra($i) ?>><td class="ellipsis" title="<?= h($r['query_text']) ?>"><?= h($r['query_text']) ?></td><td class="num"><?= h(allstat_number($clicks)) ?></td><td class="num"><?= h(allstat_number($impr)) ?></td><td class="num"><?= h(allstat_percent($impr > 0 ? $clicks / $impr * 100 : 0, 1)) ?></td><td class="num"><?= h(allstat_number((float) $r['position'], 1)) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$queryRows): ?><tr><td colspan="5" class="is-muted"><?= t('Zatím bez dat, napoj GSC ve <a href="sources.php">Zdroje dat</a>.') ?></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?= $showMore(count($queryRows)) ?>
</section>

<?php if (isset($pmByCategory['analytics'])) { allstat_report_service_card('analytics', $pmByCategory['analytics'], $pmCatMeta['analytics'], $rangeLabel, 'report-full'); } ?>

<div class="grid grid-2 report-grid">
<section class="card report-half" id="referrers" data-report-tab="sources">
    <div class="card-head"><div><h2 class="card-title"><?= h(t('Konkrétní referrery (GA4)')) ?></h2><p class="card-desc"><?= (t('Konkrétní cizí weby, ze kterých lidé proklikli na tvůj web, tedy kdo na tebe odkazuje a skutečně posílá návštěvníky. Za {range}. <span class="is-muted">GA4 dimenze <code>sessionSource</code>, medium = referral · top {limit}.</span>', ['range' => h($rangeLabel), 'limit' => $reportLimit])) ?></p></div></div>
    <div class="card-body">
        <div class="table-wrap">
            <table class="table table-plain">
                <colgroup><col><col class="col-sm"><col class="col-sm"><col class="col-sm"></colgroup>
                <thead><tr><th scope="col"><?= h(t('Referrer')) ?></th><th scope="col"><?= h(t('Návštěvy')) ?></th><th scope="col"><?= h(t('Podíl')) ?></th><th scope="col"><?= h(t('Konverze')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($referrerRows as $i => $r): ?>
                        <tr<?= $rowExtra($i) ?>><td class="ellipsis" title="<?= h($r['source']) ?>"><?= h($r['source']) ?></td><td class="num"><?= h(allstat_number((int) $r['sessions'])) ?></td><td><?= $shareCell($referrerTotal > 0 ? (int) $r['sessions'] / $referrerTotal * 100 : 0) ?></td><td class="num"><?= h(allstat_number((int) $r['conversions'])) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$referrerRows): ?><tr><td colspan="4" class="is-muted"><?= h(t('Zatím bez referral dat, spusť GA4 sync.')) ?></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?= $showMore(count($referrerRows)) ?>
</section>

<section class="card report-half" id="geo" data-report-tab="audience">
    <div class="card-head"><div><h2 class="card-title"><?= h(t('Návštěvy podle regionu (GA4)')) ?></h2><p class="card-desc"><?= (t('Odkud návštěvníci geograficky jsou, rychlá kontrola, jestli web zasahuje správnou oblast. Za {range}. <span class="is-muted">GA4 dimenze <code>region</code> / <code>country</code> · top {limit}.</span>', ['range' => h($rangeLabel), 'limit' => $reportLimit])) ?></p></div></div>
    <div class="card-body">
        <div class="table-wrap">
            <table class="table table-plain">
                <colgroup><col><col class="col-md"><col class="col-sm"><col class="col-sm"></colgroup>
                <thead><tr><th scope="col"><?= h(t('Region')) ?></th><th scope="col"><?= h(t('Země')) ?></th><th scope="col"><?= h(t('Návštěvy')) ?></th><th scope="col"><?= h(t('Podíl')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($geoRows as $i => $r): ?>
                        <tr<?= $rowExtra($i) ?>><td class="ellipsis" title="<?= h($r['region']) ?>"><?= h($r['region']) ?></td><td class="ellipsis"><?= h($r['country']) ?></td><td class="num"><?= h(allstat_number((int) $r['sessions'])) ?></td><td><?= $shareCell($geoTotal > 0 ? (int) $r['sessions'] / $geoTotal * 100 : 0) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$geoRows): ?><tr><td colspan="4" class="is-muted"><?= h(t('Žádná data, spusť GA4 sync.')) ?></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?= $showMore(count($geoRows)) ?>
</section>

<section class="card report-half" id="events" data-report-tab="actions">
    <div class="card-head"><div><h2 class="card-title"><?= h(t('Klíčové akce (GA4)')) ?></h2><p class="card-desc"><?= (t('Co lidé na webu dělají, každý řádek je jedna akce (zobrazení stránky, scrollování, kliknutí, odeslání formuláře…). Akce se štítkem <span class="status tone-up">key</span> jsou označené jako konverze, tedy to, co má pro tebe skutečnou hodnotu. Za {range}. <span class="is-muted">GA4 <code>eventName</code> · top {limit}.</span>', ['range' => h($rangeLabel), 'limit' => $reportLimit])) ?></p></div></div>
    <div class="card-body">
        <div class="table-wrap">
            <table class="table table-plain">
                <colgroup><col><col class="col-sm"><col class="col-md"></colgroup>
                <thead><tr><th scope="col"><?= h(t('Akce')) ?></th><th scope="col"><?= h(t('Počet')) ?></th><th scope="col"><?= h(t('Z toho key events')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($eventRows as $i => $r): ?>
                        <tr<?= $rowExtra($i) ?>><td class="ellipsis"><?= h($r['event_name']) ?><?= (int) $r['key_events'] > 0 ? ' <span class="status tone-up">key</span>' : '' ?></td><td class="num"><?= h(allstat_number((int) $r['event_count'])) ?></td><td class="num"><?= h(allstat_number((int) $r['key_events'])) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$eventRows): ?><tr><td colspan="3" class="is-muted"><?= h(t('Žádná data, spusť GA4 sync.')) ?></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?= $showMore(count($eventRows)) ?>
</section>

<?php $aiSources = $data['tables']['aiSources'] ?? []; ?>
<section class="card report-half" id="ai-sources" data-report-tab="sources">
    <div class="card-head">
        <div>
            <h2 class="card-title"><?= h(t('AI zdroje návštěvnosti')) ?></h2>
            <p class="card-desc"><?= t('Návštěvy z AI asistentů (ChatGPT, Perplexity, Gemini, Copilot, Claude…) za {range}. Z GA4 dimenze <code>sessionSource</code>, měřitelné jen u nástrojů, které předají referrer.', ['range' => h($rangeLabel)]) ?></p>
        </div>
    </div>
    <div class="card-body">
        <div class="table-wrap">
            <table class="table table-plain">
                <colgroup><col><col class="col-sm"><col class="col-sm"><col class="col-sm"></colgroup>
                <thead><tr><th scope="col"><?= h(t('AI nástroj')) ?></th><th scope="col"><?= h(t('Návštěvy')) ?></th><th scope="col"><?= h(t('Podíl z AI')) ?></th><th scope="col"><?= h(t('Konverze')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($aiSources as $row): ?>
                        <tr>
                            <td class="ellipsis"><?= h($row['source']) ?></td>
                            <td class="num"><?= h($row['sessionsLabel']) ?></td>
                            <td><?= $shareCell((float) ($row['share'] ?? 0)) ?></td>
                            <td class="num"><?= h($row['conversionsLabel']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$aiSources): ?>
                        <tr><td colspan="4" class="is-muted"><?= h(t('Zatím žádné AI návštěvy v tomto období. Naplní se po GA4 syncu.')) ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
</div><!-- /.report-grid -->

<?php if (!$pmByCategory): ?>
    <section class="card" id="provider-metrics">
        <div class="card-head">
            <div>
                <h2 class="card-title"><?= h(t('Sociální sítě a další služby')) ?></h2>
                <p class="card-desc"><?= h(t('Souhrn z napojených služeb mimo GA4/GSC (sociální sítě, Clarity, reklama…).')) ?></p>
            </div>
        </div>
        <div class="card-body">
            <p class="is-muted"><?= t('Zatím žádné metriky z dalších služeb. Napoj službu ve <a href="sources.php">Zdroje dat</a> a spusť sync.') ?></p>
        </div>
    </section>
<?php endif; ?>

<?php foreach ($pmByCategory as $pmCatKey => $pmTiles): ?>
    <?php if ($pmCatKey === 'analytics') { continue; } // MS Clarity je vykreslená výš, mezi webovými statistikami ?>
    <?php allstat_report_service_card($pmCatKey, $pmTiles, $pmCatMeta[$pmCatKey], $rangeLabel); ?>
<?php endforeach; ?>
<?php allstat_admin_footer($config); ?>
