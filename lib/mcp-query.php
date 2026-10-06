<?php

/**
 * MCP nástroj query_data: obecný dotaz nad daty, která AllStat ukládá (jen čtení, žádné volání API).
 *
 * Doplňuje hotové nástroje o „dlouhý ocas" otázek: konkrétní stránka, kampaň, událost, dotaz, region nebo
 * příspěvek, delší žebříčky (až 500 řádků), vývoj v čase po dnech, týdnech či měsících a srovnání
 * s předchozím obdobím nebo meziročně. Datové sady popisuje registr allstat_mcp_query_datasets().
 *
 * Bezpečnost: názvy tabulek, sloupců a SQL výrazy pocházejí jen z registru; hodnoty filtrů jdou vždy jako
 * parametry a LIKE se escapuje. Texty z dat (cesty, kampaně, dotazy, příspěvky) jdou přes sanitizér MCP.
 */

const ALLSTAT_MCP_QUERY_MAX_ROWS = 500;
const ALLSTAT_MCP_QUERY_SERIES_ROWS = 20;
const ALLSTAT_MCP_QUERY_MAX_POINTS = 4000;

/**
 * Registr datových sad. kind: web (potřebuje Google Analytics), gsc (Search Console), source (napojení
 * podle source_id). dims: alias => sloupec; metrics: alias => SQL agregace; ratio = metriky, které nejsou
 * součtem (bez podílu z celku); text = výraz, ve kterém hledá filter.
 */
function allstat_mcp_query_datasets(): array
{
    static $ds = null;
    if ($ds !== null) {
        return $ds;
    }
    $s = static fn (string $c): string => 'SUM(' . $c . ')';
    $usersNote = 'users je součet denních uživatelů (kdo přišel ve více dnech, je započten vícekrát).';
    $searchMetrics = [
        'clicks' => $s('clicks'),
        'impressions' => $s('impressions'),
        'ctr' => 'CASE WHEN SUM(impressions) > 0 THEN SUM(clicks) * 100 / SUM(impressions) ELSE 0 END',
        'position' => 'CASE WHEN SUM(impressions) > 0 THEN SUM(position * impressions) / SUM(impressions) ELSE AVG(position) END',
    ];
    $searchNote = 'ctr je v procentech, position průměrná pozice vážená zobrazeními (nižší je lepší). Search Console ukládá jen nejčastější položky (anonymizované dotazy chybí), součet řádků proto nemusí sedět s celkem webu.';

    $ds = [
        'pages' => ['kind' => 'web', 'label' => 'Stránky webu (Google Analytics, pagePath)', 'table' => 'pages_daily',
            'dims' => ['path' => 'path'], 'text' => 'path', 'primary' => 'views',
            'metrics' => ['views' => $s('views'), 'sessions' => $s('sessions'), 'conversions' => $s('conversions')],
            'note' => 'views = zobrazení stránky, sessions = návštěvy se zobrazením stránky, conversions = klíčové události v těchto návštěvách (součty dní).'],
        'landing_pages' => ['kind' => 'web', 'label' => 'Vstupní stránky (Google Analytics)', 'table' => 'landing_pages_daily',
            'dims' => ['path' => 'path'], 'text' => 'path', 'primary' => 'sessions',
            'metrics' => ['sessions' => $s('sessions'), 'conversions' => $s('conversions')],
            'note' => 'Stránka, na které návštěva začala.'],
        'channels' => ['kind' => 'web', 'label' => 'Kanály návštěvnosti (Google Analytics)', 'table' => 'traffic_sources_daily',
            'dims' => ['channel' => 'source'], 'text' => 'source', 'primary' => 'sessions',
            'metrics' => ['sessions' => $s('sessions'), 'conversions' => $s('conversions')],
            'note' => 'Výchozí skupiny kanálů GA4 (Organické vyhledávání, Přímá návštěvnost, Odkazující weby…).'],
        'devices' => ['kind' => 'web', 'label' => 'Zařízení (Google Analytics)', 'table' => 'device_daily',
            'dims' => ['device' => 'device'], 'text' => 'device', 'primary' => 'sessions',
            'metrics' => ['sessions' => $s('sessions'), 'users' => $s('users'), 'conversions' => $s('conversions')],
            'note' => $usersNote],
        'geo' => ['kind' => 'web', 'label' => 'Země a regiony (Google Analytics)', 'table' => 'geo_daily',
            'dims' => ['country' => 'country', 'region' => 'region'], 'text' => "CONCAT_WS(' ', country, region)", 'primary' => 'sessions',
            'metrics' => ['sessions' => $s('sessions'), 'users' => $s('users'), 'conversions' => $s('conversions')],
            'note' => 'Názvy zemí a regionů jsou anglicky (Czechia, Prague, Bavaria). ' . $usersNote],
        'events' => ['kind' => 'web', 'label' => 'Události (Google Analytics)', 'table' => 'events_daily',
            'dims' => ['event_name' => 'event_name'], 'text' => 'event_name', 'primary' => 'event_count',
            'metrics' => ['event_count' => $s('event_count'), 'key_events' => $s('key_events')],
            'note' => 'event_name je surový název z GA4 (generate_lead, form_submit, page_view…), key_events kolik z nich je klíčových (konverze).'],
        'referrers' => ['kind' => 'web', 'label' => 'Odkazující weby (Google Analytics)', 'table' => 'referrers_daily',
            'dims' => ['source' => 'source'], 'text' => 'source', 'primary' => 'sessions',
            'metrics' => ['sessions' => $s('sessions'), 'conversions' => $s('conversions')],
            'note' => 'Domény, ze kterých lidé přišli odkazem (médium referral).'],
        'ai_sources' => ['kind' => 'web', 'label' => 'AI asistenti (Google Analytics)', 'table' => 'ai_sources_daily',
            'dims' => ['source' => 'source'], 'text' => 'source', 'primary' => 'sessions',
            'metrics' => ['sessions' => $s('sessions'), 'conversions' => $s('conversions')],
            'note' => 'Návštěvy z ChatGPT, Perplexity, Gemini, Copilot, Claude… (jen když nástroj předá odkaz).'],
        'utm' => ['kind' => 'web', 'label' => 'Kampaně UTM (Google Analytics)', 'table' => 'utm_daily',
            'dims' => ['campaign' => 'campaign', 'source' => 'source', 'medium' => 'medium', 'content' => 'content', 'landing_page' => 'landing_page'],
            'default_group' => ['campaign', 'source', 'medium'],
            'text' => "CONCAT_WS(' ', campaign, source, medium, content, landing_page)", 'primary' => 'sessions',
            'metrics' => ['sessions' => $s('sessions'), 'conversions' => $s('conversions'), 'engaged_sessions' => $s('engaged_sessions'),
                'engagement_rate' => 'CASE WHEN SUM(sessions) > 0 THEN SUM(engaged_sessions) * 100 / SUM(sessions) ELSE 0 END'],
            'ratio' => ['engagement_rate'],
            'note' => 'Návštěvy s parametry kampaně (UTM). Organická, přímá a odkazující návštěvnost bez kampaně tu není. engagement_rate v procentech.'],
        'items' => ['kind' => 'web', 'label' => 'Produkty e-shopu (Google Analytics)', 'table' => 'items_daily',
            'dims' => ['item_name' => 'item_name'], 'text' => 'item_name', 'primary' => 'items_viewed',
            'metrics' => ['items_viewed' => $s('items_viewed'), 'items_added_to_cart' => $s('items_added_to_cart'), 'items_purchased' => $s('items_purchased'), 'item_revenue' => $s('item_revenue')],
            'note' => 'Jen weby s měřením e-commerce v GA4.'],
        'demographics' => ['kind' => 'web', 'label' => 'Věk a pohlaví (Google Analytics)', 'table' => 'demographics_daily',
            'dims' => ['age_bracket' => 'age_bracket', 'gender' => 'gender'], 'text' => "CONCAT_WS(' ', age_bracket, gender)", 'primary' => 'users',
            'metrics' => ['users' => $s('users')],
            'note' => 'Modelovaný odhad Google Signals, malé skupiny GA4 skrývá. ' . $usersNote],
        'funnel_events' => ['kind' => 'web', 'label' => 'Eventy trychtýřů podle zdroje (Google Analytics)', 'table' => 'events_source_daily',
            'dims' => ['event_name' => 'event_name', 'channel' => 'channel', 'source' => 'source', 'medium' => 'medium', 'campaign' => 'campaign'],
            'default_group' => ['event_name', 'channel'],
            'text' => "CONCAT_WS(' ', event_name, channel, source, medium, campaign)", 'primary' => 'event_count',
            'metrics' => ['event_count' => $s('event_count')],
            'note' => 'Jen eventy, které jsou kroky aktivních trychtýřů, rozpadlé podle kanálu, zdroje, média a kampaně. Plní se až od vytvoření trychtýře.'],
        'web_daily' => ['kind' => 'web', 'special' => 'web_daily', 'label' => 'Souhrn webu (Google Analytics + Search Console)', 'table' => 'metrics_daily',
            'dims' => [], 'primary' => 'visits',
            'metrics' => [
                'visits' => $s('visits'), 'engaged_sessions' => $s('engaged_sessions'), 'engagement_time_sec' => $s('engagement_time_sec'),
                'users' => $s('users_count'), 'new_users' => $s('new_users'), 'conversions' => $s('conversions'), 'revenue' => $s('revenue'),
                'clicks' => $s('clicks'), 'impressions' => $s('impressions'),
                'engagement_rate' => 'CASE WHEN SUM(visits) > 0 THEN SUM(engaged_sessions) * 100 / SUM(visits) ELSE 0 END',
                'conversion_rate' => 'CASE WHEN SUM(visits) > 0 THEN SUM(conversions) * 100 / SUM(visits) ELSE 0 END',
                'ctr' => 'CASE WHEN SUM(impressions) > 0 THEN SUM(clicks) * 100 / SUM(impressions) ELSE 0 END',
            ],
            'ratio' => ['engagement_rate', 'conversion_rate', 'ctr'],
            'note' => 'Celkové metriky webu za období; se series po dnech, týdnech nebo měsících. users je součet denních uživatelů, různé lidi za celé období ukazuje users_unique (jen u běžných období). clicks a impressions jsou ze Search Console (zpoždění 2 až 3 dny).'],
        'search_queries' => ['kind' => 'gsc', 'label' => 'Dotazy v Google (Search Console)', 'table' => 'search_queries_daily',
            'dims' => ['query' => 'query_text'], 'text' => 'query_text', 'primary' => 'clicks',
            'metrics' => $searchMetrics, 'ratio' => ['ctr', 'position'], 'note' => $searchNote],
        'search_pages' => ['kind' => 'gsc', 'label' => 'Stránky v Google (Search Console)', 'table' => 'gsc_pages_daily',
            'dims' => ['page' => 'page'], 'text' => 'page', 'primary' => 'clicks',
            'metrics' => $searchMetrics, 'ratio' => ['ctr', 'position'], 'note' => $searchNote],
        'source_metrics' => ['kind' => 'source', 'special' => 'source_metrics', 'label' => 'Všechny denní metriky zdroje',
            'note' => 'Každá metrika, kterou zdroj ukládá (i ty, které dashboard neukazuje). aggregation říká, jak vznikla hodnota: sum = součet dní, last = poslední stav (snímky jako počet sledujících), avg = průměr dní (poměry a průměrné časy).'],
        'source_breakdown' => ['kind' => 'source', 'special' => 'source_breakdown', 'label' => 'Rozpad metriky zdroje podle dimenze',
            'note' => 'Rozpady zdroje: zdroje zhlédnutí YouTube, demografie sledujících, kampaně, sestavy a reklamy Mety, rozpady Clarity, okruh Instagramu… Bez metric_key vrátí seznam dostupných rozpadů.'],
        'posts' => ['kind' => 'source', 'special' => 'posts', 'label' => 'Příspěvky a videa',
            'note' => 'Čísla příspěvků jsou celoživotní stav k poslední synchronizaci; filtruje se podle data publikace. series přidá souhrn po obdobích (počet příspěvků a součty).'],
    ];

    return $ds;
}

/** Klíče metrik příspěvků, které jde vracet a podle kterých jde řadit. */
function allstat_mcp_query_post_metrics(): array
{
    return ['engagement', 'reactions', 'comments', 'shares', 'saved', 'total_interactions', 'reach', 'impressions', 'fan_reach',
        'post_clicks', 'link_clicks', 'video_views', 'plays', 'watch_time_sec', 'reactions_viral', 'comments_viral',
        'story_replies', 'story_navigation', 'views_paid', 'reach_paid'];
}

function allstat_mcp_query_like(string $value): string
{
    return '%' . addcslashes($value, '\\%_') . '%';
}

/** Srovnávací období: previous podle allstat_previous_range, year = stejné dny o rok dřív. */
function allstat_mcp_query_compare_range(string $mode, string $start, string $end): ?array
{
    if ($mode === 'previous') {
        return allstat_previous_range($start, $end);
    }
    if ($mode !== 'year') {
        return null;
    }
    $shift = static function (string $date): string {
        $d = new DateTimeImmutable($date);
        $y = (int) $d->format('Y') - 1;
        $day = min((int) $d->format('j'), (int) $d->setDate($y, (int) $d->format('n'), 1)->format('t'));

        return $d->setDate($y, (int) $d->format('n'), $day)->format('Y-m-d');
    };

    return [$shift($start), $shift($end)];
}

/** Všechny periody [start, end] pro řadu: klíč periody (Y-m-d) => popisek. */
function allstat_mcp_query_period_keys(string $start, string $end, string $gran): array
{
    $s = new DateTimeImmutable($start);
    $e = new DateTimeImmutable($end);
    $cur = new DateTimeImmutable(allstat_period_key($start, $gran));
    $out = [];
    for ($guard = 0; $cur <= $e && $guard < 1000; $guard++) {
        $next = match ($gran) {
            'month' => $cur->modify('first day of next month'),
            'week' => $cur->modify('+7 days'),
            default => $cur->modify('+1 day'),
        };
        $ps = $cur < $s ? $s : $cur;
        $pe = $next->modify('-1 day') > $e ? $e : $next->modify('-1 day');
        $out[$cur->format('Y-m-d')] = allstat_series_label(['period_start' => $ps->format('Y-m-d'), 'period_end' => $pe->format('Y-m-d')], $gran);
        $cur = $next;
    }

    return $out;
}

function allstat_mcp_query_num(mixed $v): int|float|null
{
    if ($v === null || $v === '') {
        return null;
    }
    $f = (float) $v;

    return fmod($f, 1.0) === 0.0 && abs($f) < 1e15 ? (int) $f : round($f, 2);
}

function allstat_mcp_query_change(mixed $cur, mixed $prev): ?float
{
    if ($cur === null || $prev === null || (float) $prev === 0.0) {
        return null;
    }

    return round(((float) $cur - (float) $prev) / abs((float) $prev) * 100, 1);
}

/* ------------------------------------------------------------------------------------------------
 * Tool entry
 * ---------------------------------------------------------------------------------------------- */

function allstat_mcp_tool_query_data(PDO $pdo, array $config, array $a): array
{
    $site = allstat_mcp_tool_website($pdo, $a);
    if (!$site['ok']) {
        return allstat_mcp_tool_error($site['error']);
    }
    $datasets = allstat_mcp_query_datasets();
    $key = (string) ($a['dataset'] ?? '');
    $ds = $datasets[$key] ?? null;
    if ($ds === null) {
        return allstat_mcp_tool_error('Neznámý dataset. Povolené: ' . implode(', ', array_keys($datasets)) . '.');
    }
    $per = allstat_mcp_tool_resolve_period($a);
    if (!$per['ok']) {
        return allstat_mcp_tool_error($per['error']);
    }

    $q = [
        'site' => $site,
        'dataset' => $key,
        'ds' => $ds,
        'start' => $per['start'],
        'end' => $per['end'],
        'filter' => trim((string) ($a['filter'] ?? '')),
        'filters' => is_array($a['filters'] ?? null) ? $a['filters'] : [],
        'group_by' => is_array($a['group_by'] ?? null) ? $a['group_by'] : [],
        'metrics' => is_array($a['metrics'] ?? null) ? $a['metrics'] : [],
        'sort' => trim((string) ($a['sort'] ?? '')),
        'order' => strtolower((string) ($a['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC',
        'limit' => max(1, min(ALLSTAT_MCP_QUERY_MAX_ROWS, (int) ($a['limit'] ?? 50))),
        'offset' => max(0, (int) ($a['offset'] ?? 0)),
        'series' => (string) ($a['series'] ?? 'none'),
        'compare' => (string) ($a['compare'] ?? 'none'),
        'metric_key' => trim((string) ($a['metric_key'] ?? '')),
    ];
    $q['compare_range'] = allstat_mcp_query_compare_range($q['compare'], $q['start'], $q['end']);

    $head = [
        'website' => allstat_mcp_tool_site_payload($site),
        'dataset' => ['key' => $key, 'label' => $ds['label'], 'note' => $ds['note'] ?? ''],
        'period' => allstat_mcp_tool_period_payload($per),
    ];
    if ($q['compare_range'] !== null) {
        $head['comparison'] = ['mode' => $q['compare'], 'start' => $q['compare_range'][0], 'end' => $q['compare_range'][1]];
    }

    // Zdroj: datové sady napojení potřebují source_id, web a Search Console potřebují napojený zdroj.
    if ($ds['kind'] === 'source') {
        $sid = (int) ($a['source_id'] ?? 0);
        if ($sid < 1) {
            return allstat_mcp_tool_error('Dataset ' . $key . ' potřebuje source_id (napojený zdroj z list_websites).');
        }
        $src = allstat_mcp_tool_source($pdo, $site, $sid);
        if (!$src['ok']) {
            return allstat_mcp_tool_error($src['error']);
        }
        if (in_array((string) $src['source']['provider_key'], ['ga4', 'gsc'], true)) {
            return allstat_mcp_tool_error('source_id=' . $sid . ' je Google Analytics nebo Search Console. Data webu jsou v datasetech pages, landing_pages, channels, devices, geo, events, referrers, ai_sources, utm, items, demographics, funnel_events, web_daily, search_queries a search_pages (bez source_id).');
        }
        $q['source'] = $src['source'];
        $head['source'] = allstat_mcp_tool_source_payload($src['source']);
    } else {
        $need = $ds['kind'] === 'gsc' ? 'gsc' : 'ga4';
        $connected = array_column(allstat_dashboard_provider_options($pdo, $site['id']), 'provider_key');
        if (!in_array($need, $connected, true)) {
            return allstat_mcp_tool_error('Web ' . $site['name'] . ' nemá napojený zdroj ' . ($need === 'gsc' ? 'Google Search Console' : 'Google Analytics 4') . ', dataset ' . $key . ' proto nemá data.');
        }
    }

    try {
        $body = match ($ds['special'] ?? 'grouped') {
            'web_daily' => allstat_mcp_query_web_daily($pdo, $q),
            'source_metrics' => allstat_mcp_query_source_metrics($pdo, $q),
            'source_breakdown' => allstat_mcp_query_source_breakdown($pdo, $q),
            'posts' => allstat_mcp_query_posts($pdo, $q),
            default => allstat_mcp_query_grouped($pdo, $q, $ds, [], []),
        };
    } catch (InvalidArgumentException $e) {
        return allstat_mcp_tool_error($e->getMessage());
    }

    return allstat_mcp_tool_success($head + $body);
}

/* ------------------------------------------------------------------------------------------------
 * Grouped datasets (tabulky s dimenzemi a součty po dnech)
 * ---------------------------------------------------------------------------------------------- */

/**
 * Seskupený dotaz nad denní tabulkou. $extraWhere/$extraParams přidá podmínky (např. napojení a metric_key
 * u rozpadu zdroje). Vrací část výsledku: group_by, metrics, totals, rows_total, rows (+ series, srovnání).
 */
function allstat_mcp_query_grouped(PDO $pdo, array $q, array $ds, array $extraWhere, array $extraParams): array
{
    $dims = $ds['dims'];
    $ratio = $ds['ratio'] ?? [];

    $group = $q['group_by'] ?: ($ds['default_group'] ?? array_keys($dims));
    foreach ($group as $g) {
        if (!isset($dims[$g])) {
            throw new InvalidArgumentException('group_by: neznámá dimenze "' . $g . '". Pro dataset ' . $q['dataset'] . ' jde: ' . implode(', ', array_keys($dims)) . '.');
        }
    }
    $metricKeys = $q['metrics'] ?: array_keys($ds['metrics']);
    foreach ($metricKeys as $m) {
        if (!isset($ds['metrics'][$m])) {
            throw new InvalidArgumentException('metrics: neznámá metrika "' . $m . '". Pro dataset ' . $q['dataset'] . ' jde: ' . implode(', ', array_keys($ds['metrics'])) . '.');
        }
    }
    $sort = $q['sort'] !== '' ? $q['sort'] : $ds['primary'];
    if (!isset($ds['metrics'][$sort]) && !isset($dims[$sort])) {
        throw new InvalidArgumentException('sort: neznámé pole "' . $sort . '". Řadit jde podle metrik ' . implode(', ', array_keys($ds['metrics'])) . ' nebo dimenzí ' . implode(', ', array_keys($dims)) . '.');
    }
    if (isset($dims[$sort]) && !in_array($sort, $group, true)) {
        throw new InvalidArgumentException('sort: podle dimenze "' . $sort . '" jde řadit, jen když je v group_by.');
    }
    if (!in_array($sort, $metricKeys, true) && isset($ds['metrics'][$sort])) {
        $metricKeys[] = $sort;
    }

    $where = array_merge(['domain_id = ?', 'metric_date BETWEEN ? AND ?'], $extraWhere);
    $params = array_merge([(int) $q['site']['id'], $q['start'], $q['end']], $extraParams);
    $filterWhere = [];
    $filterParams = [];
    if ($q['filter'] !== '' && isset($ds['text'])) {
        $filterWhere[] = $ds['text'] . ' LIKE ?';
        $filterParams[] = allstat_mcp_query_like($q['filter']);
    }
    foreach ($q['filters'] as $fk => $fv) {
        if (!isset($dims[$fk])) {
            throw new InvalidArgumentException('filters: neznámá dimenze "' . $fk . '". Pro dataset ' . $q['dataset'] . ' jde: ' . implode(', ', array_keys($dims)) . '.');
        }
        $filterWhere[] = $dims[$fk] . ' LIKE ?';
        $filterParams[] = allstat_mcp_query_like((string) $fv);
    }
    $whereSql = implode(' AND ', array_merge($where, $filterWhere));
    $baseParams = array_merge($params, $filterParams);

    $dimSelect = array_map(static fn (string $g): string => $dims[$g] . ' AS `' . $g . '`', $group);
    $metricSelect = array_map(static fn (string $m): string => $ds['metrics'][$m] . ' AS `' . $m . '`', $metricKeys);
    $groupSql = implode(', ', array_map(static fn (string $g): string => $dims[$g], $group));
    $orderSql = '`' . $sort . '` ' . $q['order'] . ', ' . implode(', ', array_map(static fn (string $g): string => $dims[$g] . ' ASC', $group));
    $table = $ds['table'];

    $rows = allstat_fetch_all($pdo, 'SELECT ' . implode(', ', array_merge($dimSelect, $metricSelect)) . " FROM $table WHERE $whereSql GROUP BY $groupSql ORDER BY $orderSql LIMIT " . (int) $q['limit'] . ' OFFSET ' . (int) $q['offset'], $baseParams);
    $totalsRow = allstat_fetch_one($pdo, 'SELECT ' . implode(', ', $metricSelect) . " FROM $table WHERE $whereSql", $baseParams) ?? [];
    $count = (int) (allstat_fetch_one($pdo, "SELECT COUNT(*) AS n FROM (SELECT 1 FROM $table WHERE $whereSql GROUP BY $groupSql) t", $baseParams)['n'] ?? 0);

    $totals = [];
    foreach ($metricKeys as $m) {
        $totals[$m] = allstat_mcp_query_num($totalsRow[$m] ?? null);
    }
    $keyOf = static fn (array $r): string => implode("\x1f", array_map(static fn (string $g): string => (string) ($r[$g] ?? ''), $group));
    $shareMetric = !in_array($sort, $ratio, true) && isset($ds['metrics'][$sort]) ? $sort : $ds['primary'];
    $shareTotal = in_array($shareMetric, $ratio, true) ? 0.0 : (float) ($totalsRow[$shareMetric] ?? 0);

    $outRows = [];
    foreach ($rows as $r) {
        $row = [];
        foreach ($group as $g) {
            $row[$g] = allstat_mcp_query_dim_text($g, $r[$g] ?? '');
        }
        foreach ($metricKeys as $m) {
            $row[$m] = allstat_mcp_query_num($r[$m] ?? null);
        }
        if ($shareTotal > 0) {
            $row['share_pct'] = round((float) ($r[$shareMetric] ?? 0) / $shareTotal * 100, 1);
        }
        $outRows[$keyOf($r)] = $row;
    }

    // Omezení na vrácené řádky pro srovnání a řadu: složený klíč dimenzí v IN (...).
    $keys = array_keys($outRows);
    $keyExpr = count($group) === 1 ? $dims[$group[0]] : 'CONCAT_WS(CHAR(31 USING utf8mb4), ' . $groupSql . ')';
    $inSql = static fn (array $ks): string => $keyExpr . ' IN (' . implode(',', array_fill(0, count($ks), '?')) . ')';

    $result = [
        'group_by' => $group,
        'metrics' => $metricKeys,
        'sort' => $sort,
        'order' => strtolower($q['order']),
        'totals' => $totals,
        'rows_total' => $count,
        'offset' => (int) $q['offset'],
        'returned' => count($outRows),
    ];
    if ($shareTotal > 0) {
        $result['share_of'] = $shareMetric;
    }

    // Srovnání: stejné řádky v srovnávacím období + celkové součty.
    if ($q['compare_range'] !== null && $keys) {
        [$cs, $ce] = $q['compare_range'];
        $cParams = array_merge([(int) $q['site']['id'], $cs, $ce], $extraParams, $filterParams);
        $prevRows = allstat_fetch_all($pdo, 'SELECT ' . implode(', ', array_merge($dimSelect, $metricSelect)) . " FROM $table WHERE $whereSql AND " . $inSql($keys) . " GROUP BY $groupSql", array_merge($cParams, $keys));
        $prev = [];
        foreach ($prevRows as $pr) {
            $prev[$keyOf($pr)] = $pr;
        }
        foreach ($outRows as $k => &$row) {
            $p = $prev[$k] ?? null;
            $row['previous'] = [];
            $row['change_pct'] = [];
            foreach ($metricKeys as $m) {
                $pv = $p !== null ? allstat_mcp_query_num($p[$m] ?? null) : (in_array($m, $ratio, true) ? null : 0);
                $row['previous'][$m] = $pv;
                $row['change_pct'][$m] = allstat_mcp_query_change($row[$m], $pv);
            }
        }
        unset($row);
        $prevTotals = allstat_fetch_one($pdo, 'SELECT ' . implode(', ', $metricSelect) . " FROM $table WHERE $whereSql", $cParams) ?? [];
        $result['totals_previous'] = [];
        $result['totals_change_pct'] = [];
        foreach ($metricKeys as $m) {
            $result['totals_previous'][$m] = allstat_mcp_query_num($prevTotals[$m] ?? null);
            $result['totals_change_pct'][$m] = allstat_mcp_query_change($totals[$m], $result['totals_previous'][$m]);
        }
    }

    // Řada: pro prvních N vrácených řádků, metriky z metrics (bez poměrů se počítá každá perioda zvlášť).
    if ($q['series'] !== 'none' && $keys) {
        $seriesKeys = array_slice($keys, 0, ALLSTAT_MCP_QUERY_SERIES_ROWS);
        $periods = allstat_mcp_query_period_keys($q['start'], $q['end'], $q['series']);
        $points = count($periods) * count($seriesKeys) * count($metricKeys);
        if ($points > ALLSTAT_MCP_QUERY_MAX_POINTS) {
            throw new InvalidArgumentException('Řada by měla ' . $points . ' hodnot (limit ' . ALLSTAT_MCP_QUERY_MAX_POINTS . '). Zvolte hrubší series (week, month), méně metrik (metrics) nebo kratší období.');
        }
        $pk = allstat_period_key_sql('metric_date', $q['series']);
        $sRows = allstat_fetch_all($pdo, "SELECT $pk AS pk, " . implode(', ', array_merge($dimSelect, $metricSelect)) . " FROM $table WHERE $whereSql AND " . $inSql($seriesKeys) . " GROUP BY pk, $groupSql", array_merge($baseParams, $seriesKeys));
        $matrix = [];
        foreach ($sRows as $sr) {
            $matrix[$keyOf($sr)][(string) $sr['pk']] = $sr;
        }
        foreach ($seriesKeys as $k) {
            $outRows[$k]['series'] = [];
            foreach ($metricKeys as $m) {
                $outRows[$k]['series'][$m] = array_map(static fn (string $p) => isset($matrix[$k][$p]) ? allstat_mcp_query_num($matrix[$k][$p][$m] ?? null) : (in_array($m, $ratio, true) ? null : 0), array_keys($periods));
            }
        }
        $result['series_granularity'] = $q['series'];
        $result['series_labels'] = array_values($periods);
        if (count($keys) > count($seriesKeys)) {
            $result['series_note'] = 'Řada je jen u prvních ' . count($seriesKeys) . ' řádků.';
        }
    }

    $result['rows'] = array_values($outRows);
    if ($count > (int) $q['offset'] + count($outRows)) {
        $result['next_offset'] = (int) $q['offset'] + count($outRows);
    }

    return $result;
}

/** Text dimenze přes sanitizér; u cest a adres se skrývají parametry v URL. */
function allstat_mcp_query_dim_text(string $dim, mixed $value): string
{
    $isPath = in_array($dim, ['path', 'page', 'landing_page'], true);

    return allstat_mcp_tool_sanitize((string) $value, $isPath ? ALLSTAT_MCP_TEXT_URL : ALLSTAT_MCP_TEXT_DEFAULT, $isPath ? 'params' : 'none');
}

/* ------------------------------------------------------------------------------------------------
 * web_daily: celkové metriky webu (bez dimenzí) s řadou a srovnáním
 * ---------------------------------------------------------------------------------------------- */

function allstat_mcp_query_web_daily(PDO $pdo, array $q): array
{
    $ds = $q['ds'];
    $metricKeys = $q['metrics'] ?: array_keys($ds['metrics']);
    foreach ($metricKeys as $m) {
        if (!isset($ds['metrics'][$m])) {
            throw new InvalidArgumentException('metrics: neznámá metrika "' . $m . '". Pro web_daily jde: ' . implode(', ', array_keys($ds['metrics'])) . '.');
        }
    }
    $select = implode(', ', array_map(static fn (string $m): string => $ds['metrics'][$m] . ' AS `' . $m . '`', $metricKeys));
    $id = (int) $q['site']['id'];
    $sum = static fn (string $s, string $e): array => allstat_fetch_one($pdo, "SELECT $select FROM metrics_daily WHERE domain_id = ? AND metric_date BETWEEN ? AND ?", [$id, $s, $e]) ?? [];

    $row = $sum($q['start'], $q['end']);
    $totals = [];
    foreach ($metricKeys as $m) {
        $totals[$m] = allstat_mcp_query_num($row[$m] ?? null);
    }
    $result = ['metrics' => $metricKeys, 'totals' => $totals];
    $unique = allstat_period_users_lookup($pdo, $id, $q['start'], $q['end']);
    if ($unique !== null) {
        $result['users_unique'] = $unique['active_users'];
    }

    if ($q['compare_range'] !== null) {
        [$cs, $ce] = $q['compare_range'];
        $prow = $sum($cs, $ce);
        $result['totals_previous'] = [];
        $result['totals_change_pct'] = [];
        foreach ($metricKeys as $m) {
            $result['totals_previous'][$m] = allstat_mcp_query_num($prow[$m] ?? null);
            $result['totals_change_pct'][$m] = allstat_mcp_query_change($totals[$m], $result['totals_previous'][$m]);
        }
        $prevUnique = allstat_period_users_lookup($pdo, $id, $cs, $ce);
        if ($unique !== null && $prevUnique !== null) {
            $result['users_unique_previous'] = $prevUnique['active_users'];
        }
    }

    if ($q['series'] !== 'none') {
        $periods = allstat_mcp_query_period_keys($q['start'], $q['end'], $q['series']);
        if (count($periods) * count($metricKeys) > ALLSTAT_MCP_QUERY_MAX_POINTS) {
            throw new InvalidArgumentException('Řada by byla příliš dlouhá. Zvolte hrubší series (week, month) nebo méně metrik.');
        }
        $pk = allstat_period_key_sql('metric_date', $q['series']);
        $sRows = allstat_fetch_all($pdo, "SELECT $pk AS pk, $select FROM metrics_daily WHERE domain_id = ? AND metric_date BETWEEN ? AND ? GROUP BY pk", [$id, $q['start'], $q['end']]);
        $byPk = [];
        foreach ($sRows as $sr) {
            $byPk[(string) $sr['pk']] = $sr;
        }
        $result['series_granularity'] = $q['series'];
        $result['series_labels'] = array_values($periods);
        $result['series'] = [];
        foreach ($metricKeys as $m) {
            $result['series'][$m] = array_map(static fn (string $p) => isset($byPk[$p]) ? allstat_mcp_query_num($byPk[$p][$m] ?? null) : null, array_keys($periods));
        }
    }

    return $result;
}

/* ------------------------------------------------------------------------------------------------
 * source_metrics: všechny denní metriky napojení
 * ---------------------------------------------------------------------------------------------- */

/** Jak agregovat metriku zdroje přes dny: sum, last (snímek stavu) nebo avg (poměr, průměrný čas). */
function allstat_mcp_query_source_agg(string $providerKey, string $metricKey): string
{
    if ($providerKey === 'seznam_wmt' || in_array($metricKey, ['followers_total', 'fans_total', 'views_total', 'videos_total'], true)) {
        return 'last';
    }
    if (preg_match('/(_pct$|rate|^avg_|_avg$|scroll_depth|active_time|total_time|pages_per_session|frequency|position|duration)/', $metricKey)) {
        return 'avg';
    }

    return 'sum';
}

function allstat_mcp_query_source_metrics(PDO $pdo, array $q): array
{
    $pk = (string) $q['source']['provider_key'];
    $cid = (int) $q['source']['connection_id'];
    $id = (int) $q['site']['id'];
    $meta = allstat_provider_metric_meta($pk);

    $read = static function (string $s, string $e, ?array $onlyKeys) use ($pdo, $id, $cid, $q): array {
        $where = 'domain_id = ? AND connection_id = ? AND dimension = \'\' AND metric_date BETWEEN ? AND ?';
        $params = [$id, $cid, $s, $e];
        if ($q['filter'] !== '') {
            $where .= ' AND metric_key LIKE ?';
            $params[] = allstat_mcp_query_like($q['filter']);
        }
        if ($onlyKeys !== null) {
            if (!$onlyKeys) {
                return [];
            }
            $where .= ' AND metric_key IN (' . implode(',', array_fill(0, count($onlyKeys), '?')) . ')';
            $params = array_merge($params, $onlyKeys);
        }

        return allstat_fetch_all($pdo, "SELECT metric_date, metric_key, metric_value FROM provider_metrics_daily WHERE $where ORDER BY metric_date ASC", $params);
    };
    $aggregate = static function (array $rows) use ($pk): array {
        $acc = [];
        foreach ($rows as $r) {
            $k = (string) $r['metric_key'];
            $v = (float) $r['metric_value'];
            $acc[$k] ??= ['sum' => 0.0, 'n' => 0, 'last' => null, 'first_day' => (string) $r['metric_date'], 'last_day' => ''];
            $acc[$k]['sum'] += $v;
            $acc[$k]['n']++;
            $acc[$k]['last'] = $v;
            $acc[$k]['last_day'] = (string) $r['metric_date'];
        }
        $out = [];
        foreach ($acc as $k => $x) {
            $agg = allstat_mcp_query_source_agg($pk, $k);
            $out[$k] = ['value' => match ($agg) { 'last' => $x['last'], 'avg' => $x['n'] > 0 ? $x['sum'] / $x['n'] : null, default => $x['sum'] },
                'aggregation' => $agg, 'days_with_data' => $x['n'], 'first_day' => $x['first_day'], 'last_day' => $x['last_day']];
        }

        return $out;
    };

    $current = $aggregate($read($q['start'], $q['end'], null));
    $rows = [];
    foreach ($current as $k => $x) {
        $rows[$k] = ['metric_key' => $k, 'label' => (string) ($meta[$k]['label'] ?? $k), 'value' => allstat_mcp_query_num($x['value'])] + $x;
        $rows[$k]['value'] = allstat_mcp_query_num($x['value']);
    }
    $sort = $q['sort'] === 'value' ? 'value' : 'metric_key';
    uasort($rows, static fn (array $a, array $b): int => $sort === 'value'
        ? ($q['order'] === 'ASC' ? 1 : -1) * ((float) $a['value'] <=> (float) $b['value'])
        : strcmp($a['metric_key'], $b['metric_key']));
    $count = count($rows);
    $rows = array_slice($rows, (int) $q['offset'], (int) $q['limit'], true);
    $keys = array_keys($rows);

    if ($q['compare_range'] !== null && $keys) {
        $prev = $aggregate($read($q['compare_range'][0], $q['compare_range'][1], $keys));
        foreach ($rows as $k => &$row) {
            $row['previous'] = isset($prev[$k]) ? allstat_mcp_query_num($prev[$k]['value']) : null;
            $row['change_pct'] = allstat_mcp_query_change($row['value'], $row['previous']);
        }
        unset($row);
    }

    $result = ['sort' => $sort, 'order' => strtolower($q['order']), 'rows_total' => $count, 'offset' => (int) $q['offset'], 'returned' => count($rows)];
    if ($q['series'] !== 'none' && $keys) {
        $seriesKeys = array_slice($keys, 0, ALLSTAT_MCP_QUERY_SERIES_ROWS);
        $periods = allstat_mcp_query_period_keys($q['start'], $q['end'], $q['series']);
        if (count($periods) * count($seriesKeys) > ALLSTAT_MCP_QUERY_MAX_POINTS) {
            throw new InvalidArgumentException('Řada by byla příliš dlouhá. Zvolte hrubší series (week, month), filter na méně metrik nebo kratší období.');
        }
        $byPeriod = [];
        foreach ($read($q['start'], $q['end'], $seriesKeys) as $r) {
            $byPeriod[allstat_period_key((string) $r['metric_date'], $q['series'])][] = $r;
        }
        $agg = [];
        foreach ($byPeriod as $p => $prow) {
            $agg[$p] = $aggregate($prow);
        }
        foreach ($seriesKeys as $k) {
            $rows[$k]['series'] = array_map(static fn (string $p) => isset($agg[$p][$k]) ? allstat_mcp_query_num($agg[$p][$k]['value']) : null, array_keys($periods));
        }
        $result['series_granularity'] = $q['series'];
        $result['series_labels'] = array_values($periods);
        $result['series_note'] = 'Perioda bez řádku má null (zdroj ten den nic neuložil, není to nula).';
    }
    $result['rows'] = array_values($rows);

    return $result;
}

/* ------------------------------------------------------------------------------------------------
 * source_breakdown: rozpady metrik zdroje podle dimenze
 * ---------------------------------------------------------------------------------------------- */

/** Rozpady, které jsou denním snímkem stavu (demografie, země odběratelů): platí poslední den, ne součet. */
function allstat_mcp_query_breakdown_is_snapshot(string $metricKey): bool
{
    return (bool) preg_match('/^(dem_|foll_|pv_|viewer_)/', $metricKey) || $metricKey === 'geo_country';
}

function allstat_mcp_query_source_breakdown(PDO $pdo, array $q): array
{
    $cid = (int) $q['source']['connection_id'];
    $id = (int) $q['site']['id'];

    // Bez metric_key: přehled dostupných rozpadů za období.
    if ($q['metric_key'] === '') {
        $list = allstat_fetch_all($pdo, "SELECT metric_key, COUNT(DISTINCT dimension) AS dims, COUNT(DISTINCT metric_date) AS days, MIN(metric_date) AS first_day, MAX(metric_date) AS last_day
            FROM provider_metrics_daily WHERE domain_id = ? AND connection_id = ? AND dimension <> '' AND metric_date BETWEEN ? AND ?
            GROUP BY metric_key ORDER BY metric_key", [$id, $cid, $q['start'], $q['end']]);

        return [
            'available_breakdowns' => array_map(static fn (array $r): array => [
                'metric_key' => (string) $r['metric_key'],
                'values' => (int) $r['dims'],
                'days_with_data' => (int) $r['days'],
                'kind' => allstat_mcp_query_breakdown_is_snapshot((string) $r['metric_key']) ? 'snapshot' : 'sum',
                'first_day' => (string) $r['first_day'],
                'last_day' => (string) $r['last_day'],
            ], $list),
            'hint' => $list ? 'Zavolejte znovu s metric_key z available_breakdowns. kind=snapshot je stav k poslednímu dni (demografie), kind=sum součet dní.' : 'Zdroj za období nemá žádné rozpady.',
        ];
    }

    $mk = mb_substr($q['metric_key'], 0, 80);
    if (allstat_mcp_query_breakdown_is_snapshot($mk)) {
        return allstat_mcp_query_breakdown_snapshot($pdo, $q, $mk);
    }

    // Součtový rozpad = seskupený dotaz nad provider_metrics_daily s pevným napojením a metric_key.
    $ds = [
        'table' => 'provider_metrics_daily',
        'dims' => ['name' => 'dimension'],
        'text' => 'dimension',
        'metrics' => ['value' => 'SUM(metric_value)', 'days_with_data' => 'COUNT(DISTINCT metric_date)'],
        'ratio' => ['days_with_data'],
        'primary' => 'value',
    ];
    $q['dataset'] = 'source_breakdown (' . $mk . ')';
    if ($q['sort'] === '') {
        $q['sort'] = 'value';
    }
    $q['metrics'] = [];
    $result = allstat_mcp_query_grouped($pdo, $q, $ds, ['connection_id = ?', 'metric_key = ?', "dimension <> ''"], [$cid, $mk]);
    unset($result['group_by']);

    return ['metric_key' => $mk, 'aggregation' => 'sum'] + $result;
}

/** Snímkový rozpad (demografie): hodnoty k poslednímu dni s daty v období, řada = poslední stav v každé periodě. */
function allstat_mcp_query_breakdown_snapshot(PDO $pdo, array $q, string $mk): array
{
    $cid = (int) $q['source']['connection_id'];
    $id = (int) $q['site']['id'];
    $lastDay = static function (string $s, string $e) use ($pdo, $id, $cid, $mk): ?string {
        $d = allstat_fetch_one($pdo, "SELECT MAX(metric_date) AS d FROM provider_metrics_daily WHERE domain_id = ? AND connection_id = ? AND metric_key = ? AND dimension <> '' AND metric_date BETWEEN ? AND ?", [$id, $cid, $mk, $s, $e]);

        return !empty($d['d']) ? (string) $d['d'] : null;
    };
    $values = static function (string $day) use ($pdo, $id, $cid, $mk, $q): array {
        $where = "domain_id = ? AND connection_id = ? AND metric_key = ? AND dimension <> '' AND metric_date = ?";
        $params = [$id, $cid, $mk, $day];
        if ($q['filter'] !== '') {
            $where .= ' AND dimension LIKE ?';
            $params[] = allstat_mcp_query_like($q['filter']);
        }

        return allstat_fetch_all($pdo, "SELECT dimension, metric_value FROM provider_metrics_daily WHERE $where ORDER BY metric_value DESC, dimension ASC", $params);
    };

    $day = $lastDay($q['start'], $q['end']);
    if ($day === null) {
        return ['metric_key' => $mk, 'aggregation' => 'snapshot', 'rows' => [], 'hint' => 'Za období není žádný snímek tohoto rozpadu. Seznam rozpadů vrátí source_breakdown bez metric_key.'];
    }
    $all = $values($day);
    $total = array_sum(array_map(static fn (array $r): float => (float) $r['metric_value'], $all));
    $ordered = $all;
    if ($q['order'] === 'ASC') {
        $ordered = array_reverse($ordered);
    }
    $slice = array_slice($ordered, (int) $q['offset'], (int) $q['limit']);
    $rows = [];
    foreach ($slice as $r) {
        $name = (string) $r['dimension'];
        $rows[$name] = ['name' => allstat_mcp_query_dim_text('name', $name), 'value' => allstat_mcp_query_num($r['metric_value'])];
        if ($total > 0) {
            $rows[$name]['share_pct'] = round((float) $r['metric_value'] / $total * 100, 1);
        }
    }

    $result = ['metric_key' => $mk, 'aggregation' => 'snapshot', 'as_of' => $day, 'total' => allstat_mcp_query_num($total),
        'rows_total' => count($all), 'offset' => (int) $q['offset'], 'returned' => count($rows)];

    if ($q['compare_range'] !== null && ($pday = $lastDay($q['compare_range'][0], $q['compare_range'][1])) !== null) {
        $prev = [];
        foreach ($values($pday) as $r) {
            $prev[(string) $r['dimension']] = (float) $r['metric_value'];
        }
        $result['comparison_as_of'] = $pday;
        foreach ($rows as $name => &$row) {
            $row['previous'] = isset($prev[$name]) ? allstat_mcp_query_num($prev[$name]) : null;
            $row['change_pct'] = allstat_mcp_query_change($row['value'], $row['previous']);
        }
        unset($row);
    }

    if ($q['series'] !== 'none' && $rows) {
        $periods = allstat_mcp_query_period_keys($q['start'], $q['end'], $q['series']);
        $names = array_slice(array_keys($rows), 0, ALLSTAT_MCP_QUERY_SERIES_ROWS);
        if (count($periods) * count($names) > ALLSTAT_MCP_QUERY_MAX_POINTS) {
            throw new InvalidArgumentException('Řada by byla příliš dlouhá. Zvolte hrubší series (week, month) nebo kratší období.');
        }
        $ph = implode(',', array_fill(0, count($names), '?'));
        $hist = allstat_fetch_all($pdo, "SELECT metric_date, dimension, metric_value FROM provider_metrics_daily
            WHERE domain_id = ? AND connection_id = ? AND metric_key = ? AND metric_date BETWEEN ? AND ? AND dimension IN ($ph) ORDER BY metric_date ASC", array_merge([$id, $cid, $mk, $q['start'], $q['end']], $names));
        $state = [];
        foreach ($hist as $h) {
            $state[allstat_period_key((string) $h['metric_date'], $q['series'])][(string) $h['dimension']] = (float) $h['metric_value']; // řazeno ASC → vyhraje poslední den periody
        }
        foreach ($names as $name) {
            $rows[$name]['series'] = array_map(static fn (string $p) => isset($state[$p][$name]) ? allstat_mcp_query_num($state[$p][$name]) : null, array_keys($periods));
        }
        $result['series_granularity'] = $q['series'];
        $result['series_labels'] = array_values($periods);
        $result['series_note'] = 'Hodnota periody = stav k jejímu poslednímu dni se snímkem; null = v periodě žádný snímek.';
    }
    $result['rows'] = array_values($rows);

    return $result;
}

/* ------------------------------------------------------------------------------------------------
 * posts: příspěvky a videa s libovolným řazením, filtrem a souhrnem po obdobích
 * ---------------------------------------------------------------------------------------------- */

function allstat_mcp_query_posts(PDO $pdo, array $q): array
{
    $pk = (string) $q['source']['provider_key'];
    if (!in_array($pk, ['facebook_pages', 'instagram_business', 'linkedin_company', 'youtube'], true)) {
        throw new InvalidArgumentException('Dataset posts je jen pro Facebook, Instagram, LinkedIn a YouTube. Tento zdroj (' . $pk . ') příspěvky nemá.');
    }
    $cid = (int) $q['source']['connection_id'];
    $id = (int) $q['site']['id'];
    $allowed = allstat_mcp_query_post_metrics();
    $isYt = $pk === 'youtube';
    $metricKeys = $q['metrics'] ?: ($isYt
        ? ['video_views', 'watch_time_sec', 'reactions', 'comments', 'shares', 'engagement']
        : ['engagement', 'reactions', 'comments', 'shares', 'saved', 'reach', 'impressions', 'post_clicks', 'link_clicks', 'video_views', 'watch_time_sec']);
    foreach ($metricKeys as $m) {
        if (!in_array($m, $allowed, true)) {
            throw new InvalidArgumentException('metrics: neznámá metrika příspěvku "' . $m . '". Jde: ' . implode(', ', $allowed) . '.');
        }
    }
    $sort = $q['sort'] !== '' ? $q['sort'] : ($isYt ? 'video_views' : 'engagement');
    if (!in_array($sort, array_merge($allowed, ['published_at']), true)) {
        throw new InvalidArgumentException('sort: pro příspěvky jde řadit podle published_at nebo metrik ' . implode(', ', $allowed) . '.');
    }
    if ($sort !== 'published_at' && !in_array($sort, $metricKeys, true)) {
        $metricKeys[] = $sort;
    }

    $where = 'domain_id = ? AND connection_id = ? AND metric_date BETWEEN ? AND ?';
    $params = [$id, $cid, $q['start'], $q['end']];
    if ($q['filter'] !== '') {
        $where .= ' AND message LIKE ?';
        $params[] = allstat_mcp_query_like($q['filter']);
    }
    foreach ($q['filters'] as $fk => $fv) {
        if (!in_array($fk, ['post_type', 'post_format'], true)) {
            throw new InvalidArgumentException('filters: u příspěvků jde filtrovat podle post_type (post, reel, story, video) a post_format (photo, video, carousel_album…).');
        }
        $where .= ' AND ' . $fk . ' LIKE ?';
        $params[] = allstat_mcp_query_like((string) $fv);
    }

    $cols = implode(', ', $metricKeys);
    $rows = allstat_fetch_all($pdo, "SELECT post_id, published_at, metric_date, post_type, post_format, message, permalink, stats_json, $cols
        FROM social_posts WHERE $where ORDER BY $sort " . $q['order'] . ', published_at DESC LIMIT ' . (int) $q['limit'] . ' OFFSET ' . (int) $q['offset'], $params);
    $sums = implode(', ', array_map(static fn (string $m): string => "SUM($m) AS `$m`", $metricKeys));
    $tot = allstat_fetch_one($pdo, "SELECT COUNT(*) AS posts, $sums FROM social_posts WHERE $where", $params) ?? [];

    $out = [];
    foreach ($rows as $r) {
        $item = [
            'post_id' => (string) $r['post_id'],
            'published' => substr((string) ($r['published_at'] ?: $r['metric_date']), 0, 16),
            'type' => (string) $r['post_type'],
            'format' => (string) $r['post_format'],
            'text' => allstat_mcp_tool_sanitize((string) $r['message'], ALLSTAT_MCP_TEXT_POST),
            'url' => allstat_mcp_tool_sanitize((string) $r['permalink'], ALLSTAT_MCP_TEXT_URL),
        ];
        foreach ($metricKeys as $m) {
            $item[$m] = allstat_mcp_query_num($r[$m] ?? null);
        }
        if ($isYt) {
            $st = json_decode((string) ($r['stats_json'] ?? ''), true) ?: [];
            $item['duration_sec'] = isset($st['dur']) ? (int) $st['dur'] : null;
            $item['avg_view_sec'] = isset($st['avg_sec']) ? (int) $st['avg_sec'] : null;
            $item['avg_view_pct'] = isset($st['avg_pct']) ? (float) $st['avg_pct'] : null;
            $item['subscribers_gained'] = isset($st['subs']) ? (int) $st['subs'] : null;
        }
        $out[] = $item;
    }

    $totals = ['posts' => (int) ($tot['posts'] ?? 0)];
    foreach ($metricKeys as $m) {
        $totals[$m] = allstat_mcp_query_num($tot[$m] ?? 0);
    }
    $result = ['metrics' => $metricKeys, 'sort' => $sort, 'order' => strtolower($q['order']), 'totals' => $totals,
        'rows_total' => (int) ($tot['posts'] ?? 0), 'offset' => (int) $q['offset'], 'returned' => count($out)];

    if ($q['compare_range'] !== null) {
        $cParams = $params;
        $cParams[2] = $q['compare_range'][0];
        $cParams[3] = $q['compare_range'][1];
        $ptot = allstat_fetch_one($pdo, "SELECT COUNT(*) AS posts, $sums FROM social_posts WHERE $where", $cParams) ?? [];
        $result['totals_previous'] = ['posts' => (int) ($ptot['posts'] ?? 0)];
        foreach ($metricKeys as $m) {
            $result['totals_previous'][$m] = allstat_mcp_query_num($ptot[$m] ?? 0);
        }
        $result['totals_change_pct'] = [];
        foreach ($result['totals'] as $k => $v) {
            $result['totals_change_pct'][$k] = allstat_mcp_query_change($v, $result['totals_previous'][$k] ?? null);
        }
    }

    // Souhrn po obdobích (počet příspěvků a součty) místo listování všech příspěvků.
    if ($q['series'] !== 'none') {
        $periods = allstat_mcp_query_period_keys($q['start'], $q['end'], $q['series']);
        $pkSql = allstat_period_key_sql('metric_date', $q['series']);
        $byP = [];
        foreach (allstat_fetch_all($pdo, "SELECT $pkSql AS pk, COUNT(*) AS posts, $sums FROM social_posts WHERE $where GROUP BY pk", $params) as $r) {
            $byP[(string) $r['pk']] = $r;
        }
        $result['by_period'] = [];
        foreach ($periods as $p => $label) {
            $row = ['period' => $label, 'posts' => (int) ($byP[$p]['posts'] ?? 0)];
            foreach ($metricKeys as $m) {
                $row[$m] = allstat_mcp_query_num($byP[$p][$m] ?? 0);
            }
            $result['by_period'][] = $row;
        }
    }
    $result['rows'] = $out;
    if ((int) ($tot['posts'] ?? 0) > (int) $q['offset'] + count($out)) {
        $result['next_offset'] = (int) $q['offset'] + count($out);
    }

    return $result;
}
