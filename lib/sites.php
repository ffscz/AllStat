<?php

/**
 * Přehled všech webů (admin/sites.php a MCP nástroj get_websites_summary): souhrn Google Analytics a Search Console
 * za období pro každý web, ke kterému má uživatel přístup, srovnání s předchozím obdobím a denní řada návštěv.
 * Čte jen uložená data (metrics_daily), Google se nevolá.
 */

require_once __DIR__ . '/repository.php';

/** Metriky karty webu: klíč => popisek. Tržby a kliknutí se ukazují jen tam, kde nějaká data jsou. */
const ALLSTAT_SITES_METRICS = [
    'visits' => 'Návštěvy',
    'conversions' => 'Klíčové události',
    'revenue' => 'Tržby',
    'clicks' => 'Kliknutí z Googlu',
];

/**
 * Předvolby období. Všechny končí včerejškem (poslední kompletní den, stejně jako období v MCP); jen první den
 * měsíce je „Tento měsíc“ dnešek, protože uzavřený den ještě nemá.
 *
 * @return array<string, array{label:string, start:string, end:string}>
 */
function allstat_sites_periods(?DateTimeImmutable $today = null): array
{
    $today ??= new DateTimeImmutable('today');
    $yesterday = $today->modify('-1 day');
    $monthStart = $today->modify('first day of this month');

    return [
        '7d' => ['label' => t('7 dní'), 'start' => $yesterday->modify('-6 days')->format('Y-m-d'), 'end' => $yesterday->format('Y-m-d')],
        '30d' => ['label' => t('30 dní'), 'start' => $yesterday->modify('-29 days')->format('Y-m-d'), 'end' => $yesterday->format('Y-m-d')],
        '90d' => ['label' => t('90 dní'), 'start' => $yesterday->modify('-89 days')->format('Y-m-d'), 'end' => $yesterday->format('Y-m-d')],
        'month' => ['label' => t('Tento měsíc'), 'start' => $monthStart->format('Y-m-d'), 'end' => max($monthStart, $yesterday)->format('Y-m-d')],
        'prev_month' => ['label' => t('Minulý měsíc'), 'start' => $today->modify('first day of last month')->format('Y-m-d'), 'end' => $today->modify('last day of last month')->format('Y-m-d')],
    ];
}

/**
 * Názvy Google účtů (admin/google-accounts.php), přes které jsou weby napojené: website_id => názvy. Jen zapnutá
 * napojení, stejně pro stránku Všechny weby i pro MCP.
 *
 * @param list<int> $ids
 * @return array<int, list<string>>
 */
function allstat_sites_google_accounts(PDO $pdo, array $ids): array
{
    if (!$ids) {
        return [];
    }
    $out = [];
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        foreach (allstat_fetch_all($pdo, "SELECT DISTINCT ds.domain_id, a.label FROM domain_sources ds
                INNER JOIN allstat_oauth_accounts a ON a.id = ds.oauth_account_id
                WHERE ds.domain_id IN ($in) AND ds.is_enabled = 1 ORDER BY a.label", array_values($ids)) as $row) {
            $out[(int) $row['domain_id']][] = (string) $row['label'];
        }
    } catch (Throwable) {
        // databáze bez Google účtů: weby jen nebudou mít názvy účtů
    }

    return $out;
}

/** Změna proti předchozímu období v procentech; null když předchozí období nemá data. */
function allstat_sites_change(float $current, float $previous): ?float
{
    return $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null;
}

/**
 * @param list<array{id:int|string, name:string, url:string}> $domains weby, ke kterým má uživatel přístup
 * @param bool $withSeries denní řada návštěv pro graf karty (MCP ji nepotřebuje, u dlouhých období je velká)
 * @return array{start:string, end:string, previous:array{start:string, end:string}, sites:list<array>, totals:array}
 */
function allstat_sites_overview(PDO $pdo, array $domains, string $start, string $end, bool $withSeries = true): array
{
    [$start, $end] = allstat_limited_range($start, $end);
    [$previousStart, $previousEnd] = allstat_previous_range($start, $end);
    $ids = array_values(array_unique(array_map(static fn (array $d): int => (int) $d['id'], $domains)));
    $zero = array_fill_keys(array_keys(ALLSTAT_SITES_METRICS), 0.0);
    $result = ['start' => $start, 'end' => $end, 'previous' => ['start' => $previousStart, 'end' => $previousEnd], 'sites' => [], 'totals' => []];
    if (!$ids) {
        $result['totals'] = ['current' => $zero, 'previous' => $zero, 'change' => array_fill_keys(array_keys($zero), null)];
        return $result;
    }

    $in = implode(',', array_fill(0, count($ids), '?'));
    $sum = static function (string $from, string $to) use ($pdo, $in, $ids): array {
        $out = [];
        foreach (allstat_fetch_all($pdo, "SELECT domain_id, COALESCE(SUM(visits), 0) AS visits, COALESCE(SUM(conversions), 0) AS conversions,
                COALESCE(SUM(revenue), 0) AS revenue, COALESCE(SUM(clicks), 0) AS clicks, MAX(metric_date) AS last_date
            FROM metrics_daily WHERE domain_id IN ($in) AND metric_date BETWEEN ? AND ? GROUP BY domain_id", [...$ids, $from, $to]) as $row) {
            $out[(int) $row['domain_id']] = $row;
        }

        return $out;
    };
    $current = $sum($start, $end);
    $previous = $sum($previousStart, $previousEnd);

    $days = [];
    $series = [];
    if ($withSeries) {
        for ($day = new DateTimeImmutable($start), $last = new DateTimeImmutable($end); $day <= $last; $day = $day->modify('+1 day')) {
            $days[$day->format('Y-m-d')] = 0;
        }
        foreach (allstat_fetch_all($pdo, "SELECT domain_id, metric_date, visits FROM metrics_daily WHERE domain_id IN ($in) AND metric_date BETWEEN ? AND ?", [...$ids, $start, $end]) as $row) {
            $series[(int) $row['domain_id']][(string) $row['metric_date']] = (int) $row['visits'];
        }
    }

    // Které služby jsou u webu napojené a přes jaký Google účet (účty jsou až od verze s Google účty).
    $sources = [];
    try {
        foreach (allstat_fetch_all($pdo, "SELECT ds.domain_id, s.provider_key, a.label FROM domain_sources ds
                INNER JOIN data_sources s ON s.id = ds.source_id
                LEFT JOIN allstat_oauth_accounts a ON a.id = ds.oauth_account_id
                WHERE ds.domain_id IN ($in) AND ds.is_enabled = 1 AND s.provider_key IN ('ga4', 'gsc')", $ids) as $row) {
            $sources[(int) $row['domain_id']]['providers'][(string) $row['provider_key']] = true;
            if ($row['label'] !== null) {
                $sources[(int) $row['domain_id']]['accounts'][(string) $row['label']] = true;
            }
        }
    } catch (Throwable) {
        // bez tabulky účtů (starší databáze) se jen nezobrazí názvy účtů
    }

    $totals = ['current' => $zero, 'previous' => $zero];
    foreach ($domains as $domain) {
        $id = (int) $domain['id'];
        $cur = $zero;
        $prev = $zero;
        foreach (array_keys($zero) as $metric) {
            $cur[$metric] = (float) ($current[$id][$metric] ?? 0);
            $prev[$metric] = (float) ($previous[$id][$metric] ?? 0);
            $totals['current'][$metric] += $cur[$metric];
            $totals['previous'][$metric] += $prev[$metric];
        }
        $result['sites'][] = [
            'id' => $id,
            'name' => (string) $domain['name'],
            'url' => (string) $domain['url'],
            'has_ga4' => !empty($sources[$id]['providers']['ga4']),
            'has_gsc' => !empty($sources[$id]['providers']['gsc']),
            'accounts' => array_keys($sources[$id]['accounts'] ?? []),
            'last_date' => isset($current[$id]['last_date']) ? (string) $current[$id]['last_date'] : null,
            'current' => $cur,
            'previous' => $prev,
            'change' => array_map(static fn (string $m): ?float => allstat_sites_change($cur[$m], $prev[$m]), array_combine(array_keys($zero), array_keys($zero))),
            'series' => array_values(array_replace($days, $series[$id] ?? [])),
        ];
    }
    $totals['change'] = array_map(static fn (string $m): ?float => allstat_sites_change($totals['current'][$m], $totals['previous'][$m]), array_combine(array_keys($zero), array_keys($zero)));
    $result['totals'] = $totals;

    return $result;
}

/** Seřadí weby: 'visits' (výchozí, nejnavštěvovanější první), 'change' (největší pokles první), 'name'. */
function allstat_sites_sort(array $sites, string $sort): array
{
    usort($sites, match ($sort) {
        'name' => static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']),
        'change' => static fn (array $a, array $b): int => [$a['change']['visits'] ?? PHP_FLOAT_MAX, $a['name']] <=> [$b['change']['visits'] ?? PHP_FLOAT_MAX, $b['name']],
        default => static fn (array $a, array $b): int => [$b['current']['visits'], $a['name']] <=> [$a['current']['visits'], $b['name']],
    });

    return $sites;
}

/** Jednoduchý SVG graf denních návštěv do karty webu (bez knihoven, barvy z CSS). */
function allstat_sites_sparkline(array $values, int $width = 260, int $height = 44): string
{
    $count = count($values);
    if ($count < 2 || max($values) <= 0) {
        return '<svg class="site-spark" width="100%" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="none" aria-hidden="true"><line x1="0" y1="' . ($height - 1) . '" x2="' . $width . '" y2="' . ($height - 1) . '" class="site-spark-base" stroke="var(--border-strong)" stroke-width="1"/></svg>';
    }
    $max = max($values);
    $points = [];
    foreach (array_values($values) as $i => $value) {
        $x = round($i / ($count - 1) * $width, 1);
        $y = round($height - 2 - ($value / $max) * ($height - 6), 1);
        $points[] = $x . ',' . $y;
    }

    return '<svg class="site-spark" width="100%" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="none" aria-hidden="true">'
        . '<line x1="0" y1="' . ($height - 1) . '" x2="' . $width . '" y2="' . ($height - 1) . '" class="site-spark-base" stroke="var(--border-strong)" stroke-width="1"/>'
        . '<polyline points="' . implode(' ', $points) . '" class="site-spark-line" fill="none" stroke="var(--accent)" stroke-width="1.5" stroke-linejoin="round" vector-effect="non-scaling-stroke"/></svg>';
}
