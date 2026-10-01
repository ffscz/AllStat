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
    allstat_admin_header('Reporty', 'reports', $user, $config);
    echo '<div class="admin-card"><div class="admin-card-header"><div><h2>Zatím nemáte přidělený web</h2>'
        . '<p>Účet je aktivní, ale administrátor vám zatím nepřidělil žádný web, nebo jsou přidělené weby vypnuté. Požádejte ho o přístup.</p>'
        . '</div></div></div>';
    allstat_admin_footer($config);
    exit;
}
// Web z URL se zapamatuje, bez parametru platí web zvolený jinde v administraci (přehled, zdroje…).
$domainId = allstat_current_domain_id($pdo, $domains, filter_input(INPUT_GET, 'domain_id', FILTER_VALIDATE_INT) ?: null) ?: 1;

// Vstupní stránky (count + jedna stránka výsledků) — sdílí to HTML render i AJAX partial níže.
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

// Lehký AJAX partial pro stránkování vstupních stránek: jen 2 dotazy a exit — nerenderuje se zbytek
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

allstat_admin_header('Reporty', 'reports', $user, $config);

// Český popisek období pro nadpisy sekcí (místo ISO "2026-06-30 → 2026-07-06").
$rangeLabel = date('j. n. Y', strtotime($start)) . ' – ' . date('j. n. Y', strtotime($end));
$quickRanges = [
    '7 dní' => 6,
    '30 dní' => 29,
    '90 dní' => 89,
];
// Metriky napojených služeb mimo GA4/GSC (Meta, LinkedIn, Clarity, Meta Ads…) — počítá se už tady,
// protože každá kategorie služeb (sociální sítě / analytika / PPC…) má VLASTNÍ kartu i záložku
// a záložky se kreslí hned v hlavičce reportu.
$pmRows = [];
if (allstat_table_exists($pdo, 'provider_metrics_daily')) {
    // Jen účtové (nedimenzované) řádky — per-kampaň/per-stránka rozpady by tu součty zdvojily.
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
    'social' => ['tab' => 'Sociální sítě', 'title' => 'Sociální sítě', 'desc' => 'Dosah, zapojení a sledující na napojených profilech (Facebook, Instagram, LinkedIn…).'],
    'analytics' => ['tab' => 'MS Clarity', 'title' => 'MS Clarity (chování na webu)', 'desc' => 'Chování návštěvníků na webu z Microsoft Clarity, relace, boti, čas na webu, scroll.'],
    'ppc' => ['tab' => 'Reklama (PPC)', 'title' => 'Reklama (PPC)', 'desc' => 'Výkon placených kampaní (Meta Ads…), útrata, zobrazení, prokliky, konverze.'],
    'seo' => ['tab' => 'SEO nástroje', 'title' => 'SEO nástroje', 'desc' => 'Indexace a viditelnost z napojených SEO nástrojů.'],
    'other' => ['tab' => 'Další služby', 'title' => 'Další služby', 'desc' => 'Metriky z ostatních napojených služeb.'],
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
// MS Clarity patří mezi webové statistiky (hned za GSC) — je to třetí analytika webu vedle GA4/GSC;
// sociální sítě, PPC a spol. až za obsahové záložky.
$reportTabs = [
    'all' => 'Vše',
    'pages' => 'Stránky',
    'sources' => 'Zdroje návštěv',
    'audience' => 'Publikum',
    'search' => 'Vyhledávání (GSC)',
];
if (isset($pmByCategory['analytics'])) {
    $reportTabs['analytics'] = $pmCatMeta['analytics']['tab'];
}
$reportTabs['actions'] = 'Klíčové akce';
foreach ($pmByCategory as $pmCatKey => $pmTiles) {
    if ($pmCatKey !== 'analytics') {
        $reportTabs[$pmCatKey] = $pmCatMeta[$pmCatKey]['tab'];
    }
}

// Karta jedné kategorie služeb (dlaždice po účtech) — volá se na dvou místech: MS Clarity hned
// za GSC dotazy (uvnitř .report-grid, přes celou šířku), ostatní kategorie na konci reportu.
function allstat_report_service_card(string $catKey, array $tiles, array $meta, string $rangeLabel, string $cardClass = ''): void
{
    ?>
    <div class="admin-card<?= $cardClass !== '' ? ' ' . h($cardClass) : '' ?>" id="services-<?= h($catKey) ?>" data-report-tab="<?= h($catKey) ?>">
        <div class="admin-card-header">
            <div>
                <h2><?= h($meta['title']) ?></h2>
                <p><?= h($meta['desc']) ?> Za <?= h($rangeLabel) ?>. Souhrn za období, detailní pohled najdeš na dashboardu v přepínači „Zdroj dat".</p>
            </div>
        </div>
        <div class="admin-card-body">
            <div class="pm-grid">
                <?php foreach ($tiles as $sourceName => $rows): $pmProvider = (string) $rows[0]['provider_key']; $pmProviderName = (string) $rows[0]['provider_name']; ?>
                    <section class="pm-tile">
                        <header class="pm-tile-head">
                            <span class="source-picker-ic"><?= allstat_provider_icon_svg($pmProvider) ?></span>
                            <div>
                                <strong><?= h($sourceName) ?></strong>
                                <?php if ($pmProviderName !== '' && $pmProviderName !== $sourceName): ?><small><?= h($pmProviderName) ?></small><?php endif; ?>
                            </div>
                        </header>
                        <div class="pm-rows">
                            <?php foreach ($rows as $r): ?>
                                <div class="pm-row" title="<?= h($r['metric_key']) ?>">
                                    <span><?= $r['metric_label'] !== null ? h($r['metric_label']) : h($r['metric_key']) ?></span>
                                    <strong><?= h(allstat_number((float) $r['total'], 0)) ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php
}
?>
<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2>Rychlý report</h2>
            <p>Souhrnné KPI za zvolený web a období (<strong><?= h($rangeLabel) ?></strong>) s možností stažení jako CSV. Záložkami níže si přepneš, kterou oblast chceš vidět, „Vše" zobrazí kompletní report.</p>
        </div>
        <a class="button-primary" href="reports.php?domain_id=<?= (int) $domainId ?>&start=<?= h($start) ?>&end=<?= h($end) ?>&download=csv">Stáhnout CSV</a>
    </div>
    <div class="admin-card-body">
        <form method="get" class="form-grid form-grid-3">
            <label><span>Web</span><select name="domain_id" data-autosubmit><?php foreach ($domains as $domain): ?><option value="<?= (int) $domain['id'] ?>" <?= $domainId === (int) $domain['id'] ? 'selected' : '' ?>><?= h($domain['url']) ?></option><?php endforeach; ?></select></label>
            <label><span>Od</span><input type="date" name="start" value="<?= h($start) ?>"></label>
            <label><span>Do</span><input type="date" name="end" value="<?= h($end) ?>"></label>
            <div class="form-actions"><button class="button-primary" type="submit">Načíst report</button></div>
        </form>
        <div class="report-quick-ranges">
            <span class="table-muted">Rychlá volba:</span>
            <?php foreach ($quickRanges as $qrLabel => $qrDays): $qrStart = (new DateTimeImmutable('today'))->modify('-' . $qrDays . ' days')->format('Y-m-d'); $qrEnd = (new DateTimeImmutable('today'))->format('Y-m-d'); ?>
                <a class="anchor-chip<?= $start === $qrStart && $end === $qrEnd ? ' is-active' : '' ?>" href="reports.php?domain_id=<?= (int) $domainId ?>&start=<?= h($qrStart) ?>&end=<?= h($qrEnd) ?>">posledních <?= h($qrLabel) ?></a>
            <?php endforeach; ?>
        </div>
        <nav class="report-tabs" aria-label="Sekce reportu" data-report-tabs>
            <?php foreach ($reportTabs as $tabKey => $tabLabel): ?>
                <button type="button" class="report-tab<?= $tabKey === 'all' ? ' is-active' : '' ?>" data-report-tab-btn="<?= h($tabKey) ?>"><?= h($tabLabel) ?></button>
            <?php endforeach; ?>
        </nav>
    </div>
</div>

<div class="admin-stats">
    <?php foreach ($data['kpis'] as $kpi): ?>
        <div class="stat-tile"><span><?= h($kpi['label']) ?></span><strong><?= h($kpi['displayValue']) ?></strong><p class="form-help"><?= h($kpi['changeLabel']) ?> vs. předchozí období</p></div>
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
<div class="admin-card" id="landing-pages" data-report-tab="pages" data-landing-pages>
    <div class="admin-card-header">
        <div>
            <h2>Všechny vstupní stránky</h2>
            <p>Kterou stránkou návštěva začala, první stránka, na kterou člověk z vyhledávání, odkazu či reklamy přišel. Setříděno podle návštěv za <?= h($rangeLabel) ?>. Celkem <strong data-lp-total><?= $totalPages ?></strong> různých cest, stránka <span data-lp-page><?= $page ?></span>/<span data-lp-pages><?= $totalPagination ?></span>.</p>
        </div>
        <form method="get" class="toolbar">
            <input type="hidden" name="domain_id" value="<?= (int) $domainId ?>">
            <input type="hidden" name="start" value="<?= h($start) ?>">
            <input type="hidden" name="end" value="<?= h($end) ?>">
            <input type="hidden" name="page" value="1">
            <label class="filter-control">
                <span>Na stránku</span>
                <select name="per_page" data-lp-perpage>
                    <?php foreach ([25, 50, 100, 200] as $opt): ?>
                        <option value="<?= $opt ?>" <?= $perPage === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
    </div>
    <div class="admin-card-body table-scroll">
        <table class="admin-table admin-table--num">
            <thead><tr><th>Stránka</th><th>Návštěvy</th><th>Konverze</th></tr></thead>
            <tbody data-lp-rows>
                <?php foreach ($allPages as $row): ?>
                    <tr>
                        <td><?= h($row['path']) ?></td>
                        <td><?= h(allstat_number((int) $row['sessions'])) ?></td>
                        <td><?= h(allstat_number((int) $row['conversions'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$allPages): ?>
                    <tr><td colspan="3" class="table-muted">Žádná data pro toto období. Spusť sync v <a href="sources.php">Zdroje dat</a>.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <nav class="pagination" aria-label="Stránkování" data-lp-nav<?= $totalPagination <= 1 ? ' hidden' : '' ?>>
        <a class="button-secondary <?= $page <= 1 ? 'is-disabled' : '' ?>" data-lp-prev href="<?= $page > 1 ? h($pageLink($page - 1)) : '#' ?>">← Předchozí</a>
        <span class="pagination-info">Stránka <span data-lp-page><?= $page ?></span> z <span data-lp-pages><?= $totalPagination ?></span></span>
        <a class="button-secondary <?= $page >= $totalPagination ? 'is-disabled' : '' ?>" data-lp-next href="<?= $page < $totalPagination ? h($pageLink($page + 1)) : '#' ?>">Další →</a>
    </nav>
</div>

<?php
// ---- Plné reporty (mirror dashboard panelů, ne­zkrácené) ----
$reportLimit = 100;
$pct = static fn (int $part, int $total): string => allstat_percent($total > 0 ? $part / $total * 100 : 0, 1);

// Podíl jako vizuální pruh + procento — pro laika čitelnější než holé číslo.
$shareCell = static function (float $share): string {
    return '<div class="share-cell"><div class="quota-bar"><div class="quota-bar-fill quota-bar-neutral" style="width: ' . round(max(2, min(100, $share))) . '%;"></div></div><span>' . h(allstat_percent($share, 1)) . '</span></div>';
};

// Dlouhé tabulky ukazují top 10; zbytek schová .row-extra + tlačítko „Zobrazit dalších X" (admin.js).
$rowExtra = static fn (int $i): string => $i >= 10 ? ' class="row-extra"' : '';
$showMore = static function (int $count): string {
    return $count > 10 ? '<button type="button" class="button-secondary show-more-btn" data-show-more>Zobrazit dalších ' . ($count - 10) . ' řádků</button>' : '';
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
    SELECT query_text, SUM(clicks) AS clicks, SUM(impressions) AS impressions, AVG(position) AS position
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
<div class="report-grid">
<div class="admin-card report-half" id="traffic-sources" data-report-tab="sources">
    <div class="admin-card-header"><div><h2>Zdroje návštěv (GA4)</h2><p>Odkud lidé přišli: z vyhledávačů (Organické vyhledávání), napřímo (zadali adresu nebo měli záložku), z odkazů na jiných webech, ze sociálních sítí nebo z reklamy. Za <?= h($rangeLabel) ?>. <span class="table-muted">GA4 dimenze <code>sessionDefaultChannelGroup</code>.</span></p></div></div>
    <div class="admin-card-body table-scroll">
        <table class="admin-table admin-table--num">
            <thead><tr><th>Kanál</th><th>Návštěvy</th><th>Podíl</th><th>Konverze</th></tr></thead>
            <tbody>
                <?php foreach ($trafficRows as $r): ?>
                    <tr><td><?= h(allstat_ga4_channel_label((string) $r['source'])) ?></td><td><?= h(allstat_number((int) $r['sessions'])) ?></td><td><?= $shareCell($trafficTotal > 0 ? (int) $r['sessions'] / $trafficTotal * 100 : 0) ?></td><td><?= h(allstat_number((int) $r['conversions'])) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$trafficRows): ?><tr><td colspan="4" class="table-muted">Žádná data, spusť GA4 sync.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="admin-card report-half" id="devices" data-report-tab="audience">
    <div class="admin-card-header"><div><h2>Zařízení (GA4)</h2><p>Z čeho lidé web prohlížejí, mobil, počítač, tablet. Když převažuje mobil, musí web především dobře fungovat na malém displeji. Za <?= h($rangeLabel) ?>. <span class="table-muted">GA4 dimenze <code>deviceCategory</code>.</span></p></div></div>
    <div class="admin-card-body table-scroll">
        <table class="admin-table admin-table--num">
            <thead><tr><th>Zařízení</th><th>Návštěvy</th><th>Podíl</th><th>Konverze</th></tr></thead>
            <tbody>
                <?php foreach ($deviceRows as $r): ?>
                    <tr><td><?= h(allstat_device_label((string) $r['device'])) ?></td><td><?= h(allstat_number((int) $r['sessions'])) ?></td><td><?= $shareCell($deviceTotal > 0 ? (int) $r['sessions'] / $deviceTotal * 100 : 0) ?></td><td><?= h(allstat_number((int) $r['conversions'])) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$deviceRows): ?><tr><td colspan="4" class="table-muted">Žádná data, spusť GA4 sync.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="admin-card report-full" id="pages" data-report-tab="pages">
    <div class="admin-card-header"><div><h2>Top stránky (všechny podle zobrazení)</h2><p>Které stránky si lidé nejvíc prohlížejí. „Zobrazení" počítá i opakovaná otevření stejné stránky, „Návštěvy" jednotlivé příchody. Za <?= h($rangeLabel) ?>. <span class="table-muted">GA4 <code>pagePath</code> / <code>screenPageViews</code> · top <?= $reportLimit ?>.</span></p></div></div>
    <div class="admin-card-body table-scroll">
        <table class="admin-table admin-table--num">
            <thead><tr><th>Stránka</th><th>Zobrazení</th><th>Podíl</th><th>Návštěvy</th><th>Konverze</th></tr></thead>
            <tbody>
                <?php foreach ($pageRows as $i => $r): ?>
                    <tr<?= $rowExtra($i) ?>><td><?= h($r['path']) ?></td><td><?= h(allstat_number((int) $r['views'])) ?></td><td><?= $shareCell($pageViewsTotal > 0 ? (int) $r['views'] / $pageViewsTotal * 100 : 0) ?></td><td><?= h(allstat_number((int) $r['sessions'])) ?></td><td><?= h(allstat_number((int) $r['conversions'])) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$pageRows): ?><tr><td colspan="5" class="table-muted">Žádná data, spusť GA4 sync.</td></tr><?php endif; ?>
            </tbody>
        </table>
        <?= $showMore(count($pageRows)) ?>
    </div>
</div>

<div class="admin-card report-full" id="queries" data-report-tab="search">
    <div class="admin-card-header"><div><h2>Vyhledávací dotazy (GSC)</h2><p>Co lidé hledali v Googlu, než klikli na tvůj web. „Zobrazení" = kolikrát se web u dotazu ukázal ve výsledcích, „CTR" = jak často z toho lidé klikli, „Pozice" = průměrné pořadí ve výsledcích (menší číslo = výš). Za <?= h($rangeLabel) ?>. <span class="table-muted">Z Google Search Console · top <?= $reportLimit ?>.</span></p></div></div>
    <div class="admin-card-body table-scroll">
        <table class="admin-table admin-table--num">
            <thead><tr><th>Dotaz</th><th>Kliknutí</th><th>Zobrazení</th><th>CTR</th><th>Pozice</th></tr></thead>
            <tbody>
                <?php foreach ($queryRows as $i => $r):
                    $clicks = (int) $r['clicks']; $impr = (int) $r['impressions'];
                ?>
                    <tr<?= $rowExtra($i) ?>><td><?= h($r['query_text']) ?></td><td><?= h(allstat_number($clicks)) ?></td><td><?= h(allstat_number($impr)) ?></td><td><?= h(allstat_percent($impr > 0 ? $clicks / $impr * 100 : 0, 1)) ?></td><td><?= h(allstat_number((float) $r['position'], 1)) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$queryRows): ?><tr><td colspan="5" class="table-muted">Zatím bez dat, napoj GSC ve <a href="sources.php">Zdroje dat</a>.</td></tr><?php endif; ?>
            </tbody>
        </table>
        <?= $showMore(count($queryRows)) ?>
    </div>
</div>

<?php if (isset($pmByCategory['analytics'])) { allstat_report_service_card('analytics', $pmByCategory['analytics'], $pmCatMeta['analytics'], $rangeLabel, 'report-full'); } ?>

<div class="admin-card report-half" id="referrers" data-report-tab="sources">
    <div class="admin-card-header"><div><h2>Konkrétní referrery (GA4)</h2><p>Konkrétní cizí weby, ze kterých lidé proklikli na tvůj web, tedy kdo na tebe odkazuje a skutečně posílá návštěvníky. Za <?= h($rangeLabel) ?>. <span class="table-muted">GA4 dimenze <code>sessionSource</code>, medium = referral · top <?= $reportLimit ?>.</span></p></div></div>
    <div class="admin-card-body table-scroll">
        <table class="admin-table admin-table--num">
            <thead><tr><th>Referrer</th><th>Návštěvy</th><th>Podíl</th><th>Konverze</th></tr></thead>
            <tbody>
                <?php foreach ($referrerRows as $i => $r): ?>
                    <tr<?= $rowExtra($i) ?>><td><?= h($r['source']) ?></td><td><?= h(allstat_number((int) $r['sessions'])) ?></td><td><?= $shareCell($referrerTotal > 0 ? (int) $r['sessions'] / $referrerTotal * 100 : 0) ?></td><td><?= h(allstat_number((int) $r['conversions'])) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$referrerRows): ?><tr><td colspan="4" class="table-muted">Zatím bez referral dat, spusť GA4 sync.</td></tr><?php endif; ?>
            </tbody>
        </table>
        <?= $showMore(count($referrerRows)) ?>
    </div>
</div>

<div class="admin-card report-half" id="geo" data-report-tab="audience">
    <div class="admin-card-header"><div><h2>Návštěvy podle regionu (GA4)</h2><p>Odkud návštěvníci geograficky jsou, rychlá kontrola, jestli web zasahuje správnou oblast. Za <?= h($rangeLabel) ?>. <span class="table-muted">GA4 dimenze <code>region</code> / <code>country</code> · top <?= $reportLimit ?>.</span></p></div></div>
    <div class="admin-card-body table-scroll">
        <table class="admin-table admin-table--num2">
            <thead><tr><th>Region</th><th>Země</th><th>Návštěvy</th><th>Podíl</th></tr></thead>
            <tbody>
                <?php foreach ($geoRows as $i => $r): ?>
                    <tr<?= $rowExtra($i) ?>><td><?= h($r['region']) ?></td><td><?= h($r['country']) ?></td><td><?= h(allstat_number((int) $r['sessions'])) ?></td><td><?= $shareCell($geoTotal > 0 ? (int) $r['sessions'] / $geoTotal * 100 : 0) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$geoRows): ?><tr><td colspan="4" class="table-muted">Žádná data, spusť GA4 sync.</td></tr><?php endif; ?>
            </tbody>
        </table>
        <?= $showMore(count($geoRows)) ?>
    </div>
</div>

<div class="admin-card report-half" id="events" data-report-tab="actions">
    <div class="admin-card-header"><div><h2>Klíčové akce (GA4)</h2><p>Co lidé na webu dělají, každý řádek je jedna akce (zobrazení stránky, scrollování, kliknutí, odeslání formuláře…). Akce se štítkem <span class="status-badge status-ok">key</span> jsou označené jako konverze, tedy to, co má pro tebe skutečnou hodnotu. Za <?= h($rangeLabel) ?>. <span class="table-muted">GA4 <code>eventName</code> · top <?= $reportLimit ?>.</span></p></div></div>
    <div class="admin-card-body table-scroll">
        <table class="admin-table admin-table--num">
            <thead><tr><th>Akce</th><th>Počet</th><th>Z toho key events</th></tr></thead>
            <tbody>
                <?php foreach ($eventRows as $i => $r): ?>
                    <tr<?= $rowExtra($i) ?>><td><?= h($r['event_name']) ?><?= (int) $r['key_events'] > 0 ? ' <span class="status-badge status-ok">key</span>' : '' ?></td><td><?= h(allstat_number((int) $r['event_count'])) ?></td><td><?= h(allstat_number((int) $r['key_events'])) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$eventRows): ?><tr><td colspan="3" class="table-muted">Žádná data, spusť GA4 sync.</td></tr><?php endif; ?>
            </tbody>
        </table>
        <?= $showMore(count($eventRows)) ?>
    </div>
</div>

<?php $aiSources = $data['tables']['aiSources'] ?? []; ?>
<div class="admin-card report-half" id="ai-sources" data-report-tab="sources">
    <div class="admin-card-header">
        <div>
            <h2>AI zdroje návštěvnosti</h2>
            <p>Návštěvy z AI asistentů (ChatGPT, Perplexity, Gemini, Copilot, Claude…) za <?= h($rangeLabel) ?>. Z GA4 dimenze <code>sessionSource</code>, měřitelné jen u nástrojů, které předají referrer.</p>
        </div>
    </div>
    <div class="admin-card-body table-scroll">
        <table class="admin-table admin-table--num">
            <thead><tr><th>AI nástroj</th><th>Návštěvy</th><th>Podíl z AI</th><th>Konverze</th></tr></thead>
            <tbody>
                <?php foreach ($aiSources as $row): ?>
                    <tr>
                        <td><?= h($row['source']) ?></td>
                        <td><?= h($row['sessionsLabel']) ?></td>
                        <td><?= $shareCell((float) ($row['share'] ?? 0)) ?></td>
                        <td><?= h($row['conversionsLabel']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$aiSources): ?>
                    <tr><td colspan="4" class="table-muted">Zatím žádné AI návštěvy v tomto období. Naplní se po GA4 syncu.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div><!-- /.report-grid -->

<?php if (!$pmByCategory): ?>
    <div class="admin-card" id="provider-metrics">
        <div class="admin-card-header">
            <div>
                <h2>Sociální sítě a další služby</h2>
                <p>Souhrn z napojených služeb mimo GA4/GSC (sociální sítě, Clarity, reklama…).</p>
            </div>
        </div>
        <div class="admin-card-body">
            <p class="table-muted">Zatím žádné metriky z dalších služeb. Napoj službu ve <a href="sources.php">Zdroje dat</a> a spusť sync.</p>
        </div>
    </div>
<?php endif; ?>

<?php foreach ($pmByCategory as $pmCatKey => $pmTiles): ?>
    <?php if ($pmCatKey === 'analytics') { continue; } // MS Clarity je vykreslená výš, mezi webovými statistikami ?>
    <?php allstat_report_service_card($pmCatKey, $pmTiles, $pmCatMeta[$pmCatKey], $rangeLabel); ?>
<?php endforeach; ?>
<?php allstat_admin_footer($config); ?>
