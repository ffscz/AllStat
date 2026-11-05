<?php

/**
 * Přehled reklamy napříč platformami (?view=ads, 1.5.5): útrata, výkon a konverze každého reklamního účtu vedle
 * sebe a k tomu návštěvy, konverze a tržby z GA4 podle zdroje. Útrata se sčítá jen v rámci jedné měny (kurzy
 * AllStat nepřepočítává) a konverze platforem se nesčítají: každá platforma je počítá podle vlastní atribuce.
 * Potřebuje lib/growth.php (měna účtu) a lib/repository.php.
 */

/** Reklamní služby, které přehled zná, v pořadí zobrazení. */
function allstat_ads_overview_providers(): array
{
    return [
        'google_ads' => 'Google Ads',
        'meta_ads' => 'Meta Ads',
        'microsoft_ads' => 'Microsoft Ads',
        'tiktok_ads' => 'TikTok Ads',
        'openai_ads' => 'OpenAI Ads',
    ];
}

/**
 * Zda návštěva z GA4 (source / medium z UTM nebo automatického označení) patří placené reklamě dané platformy.
 * Automatické označení dává google / cpc (gclid) a bing / cpc (msclkid); ostatní platformy jen přes UTM.
 */
function allstat_ads_ga4_platform(string $source, string $medium): ?string
{
    $source = mb_strtolower(trim($source));
    $medium = mb_strtolower(trim($medium));
    $paid = (bool) preg_match('/^(cpc|ppc|cpm|cpv|cpa|paid|paid[_ -]?(social|search|media|ads?)|(social|search)[_ -]?paid|ads?|display|banner|retargeting|remarketing)$/', $medium);
    if (!$paid) {
        return null;
    }

    return match (true) {
        (bool) preg_match('/(^|\.)google(\.|$)|^adwords$|^google[_ -]?ads$/', $source) => 'google_ads',
        (bool) preg_match('/^(bing|microsoft|msn|ms[_ -]?ads|microsoft[_ -]?ads)(\.|$)/', $source) => 'microsoft_ads',
        (bool) preg_match('/(facebook|instagram|^fb$|^ig$|^meta$|^meta[_ -]?ads$|messenger)/', $source) => 'meta_ads',
        (bool) preg_match('/tiktok/', $source) => 'tiktok_ads',
        (bool) preg_match('/(chatgpt|openai)/', $source) => 'openai_ads',
        default => null,
    };
}

/**
 * @return array{
 *   accounts: list<array<string, mixed>>,
 *   currencies: array<string, array{spend: float, clicks: float, impressions: float, accounts: int}>,
 *   ga4: array<string, array{sessions: int, conversions: int, revenue: float}>,
 *   ga4Paid: ?array{sessions: int, conversions: int, revenue: float},
 *   hasGa4Revenue: bool,
 *   hasData: bool
 * }
 */
function allstat_get_ads_overview(PDO $pdo, int $domainId, string $start, string $end): array
{
    $names = allstat_ads_overview_providers();
    $keys = array_keys($names);
    $ph = implode(',', array_fill(0, count($keys), '?'));
    $out = ['accounts' => [], 'currencies' => [], 'ga4' => [], 'ga4Paid' => null, 'hasGa4Revenue' => false, 'hasData' => false];

    try {
        $connections = allstat_fetch_all($pdo, "SELECT ds.id, ds.account_label, s.provider_key
            FROM domain_sources ds INNER JOIN data_sources s ON s.id = ds.source_id
            WHERE ds.domain_id = ? AND ds.is_enabled = 1 AND s.provider_key IN ($ph)
            ORDER BY ds.id", array_merge([$domainId], $keys));
    } catch (Throwable) {
        return $out;
    }
    if (!$connections) {
        return $out;
    }

    $ids = array_map(static fn (array $c): int => (int) $c['id'], $connections);
    $sums = [];
    try {
        $rows = allstat_fetch_all($pdo, "SELECT connection_id, metric_key, SUM(metric_value) AS v
            FROM provider_metrics_daily
            WHERE domain_id = ? AND connection_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
              AND COALESCE(dimension, '') = '' AND metric_date BETWEEN ? AND ?
              AND metric_key IN ('cost', 'spend', 'impressions', 'clicks', 'conversions', 'conversion_value')
            GROUP BY connection_id, metric_key", array_merge([$domainId], $ids, [$start, $end]));
        foreach ($rows as $r) {
            $key = (string) $r['metric_key'] === 'spend' ? 'cost' : (string) $r['metric_key'];
            $sums[(int) $r['connection_id']][$key] = ($sums[(int) $r['connection_id']][$key] ?? 0.0) + (float) $r['v'];
        }
    } catch (Throwable) {
        // Bez dat se účty ukážou s nulami.
    }

    $ratio = static fn (float $a, float $b, float $mul = 1.0): ?float => $b > 0 ? $a / $b * $mul : null;
    foreach ($connections as $c) {
        $cid = (int) $c['id'];
        $pk = (string) $c['provider_key'];
        $s = $sums[$cid] ?? [];
        $cost = (float) ($s['cost'] ?? 0);
        $clicks = (float) ($s['clicks'] ?? 0);
        $impressions = (float) ($s['impressions'] ?? 0);
        $conversions = (float) ($s['conversions'] ?? 0);
        $value = (float) ($s['conversion_value'] ?? 0);
        // Bez odhadu CZK: účet s dosud neuloženou měnou se nesmí sečíst s korunovými účty.
        $currency = allstat_ads_currency($pdo, $pk, $cid, (string) ($c['account_label'] ?? ''), false);
        $curKey = $currency !== '' ? $currency : '?';
        if ($cost > 0 || $clicks > 0 || $impressions > 0) {
            $out['hasData'] = true;
        }
        $out['accounts'][] = [
            'connection_id' => $cid,
            'provider' => $pk,
            'name' => $names[$pk],
            'account' => trim((string) ($c['account_label'] ?? '')),
            'currency' => $currency,
            'currencyLabel' => allstat_currency_label($currency),
            'cost' => $cost,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'conversions' => $conversions,
            'conversionValue' => $value,
            'ctr' => $ratio($clicks, $impressions, 100.0),
            'cpc' => $ratio($cost, $clicks),
            'cpm' => $ratio($cost, $impressions, 1000.0),
            'cpa' => $conversions > 0 ? $cost / $conversions : null,
            'roas' => $value > 0 ? $ratio($value, $cost) : null,
            'share' => null,
        ];
        $cur = $out['currencies'][$curKey] ?? ['spend' => 0.0, 'clicks' => 0.0, 'impressions' => 0.0, 'accounts' => 0];
        $cur['spend'] += $cost;
        $cur['clicks'] += $clicks;
        $cur['impressions'] += $impressions;
        $cur['accounts']++;
        $out['currencies'][$curKey] = $cur;
    }
    // Podíl na útratě jen v rámci stejné měny.
    foreach ($out['accounts'] as &$a) {
        $total = $out['currencies'][$a['currency'] !== '' ? $a['currency'] : '?']['spend'] ?? 0.0;
        $a['share'] = $total > 0 ? $a['cost'] / $total * 100 : null;
    }
    unset($a);
    usort($out['accounts'], static fn (array $x, array $y): int => [$x['currency'], -$x['cost']] <=> [$y['currency'], -$y['cost']]);
    uasort($out['currencies'], static fn (array $x, array $y): int => $y['spend'] <=> $x['spend']);

    // GA4: návštěvy, konverze a tržby z placených zdrojů podle platformy (UTM / automatické označení).
    try {
        $utm = allstat_fetch_all($pdo, 'SELECT source, medium, SUM(sessions) AS s, SUM(conversions) AS c, SUM(revenue) AS r
            FROM utm_daily WHERE domain_id = ? AND metric_date BETWEEN ? AND ? GROUP BY source, medium', [$domainId, $start, $end]);
        foreach ($utm as $r) {
            $platform = allstat_ads_ga4_platform((string) $r['source'], (string) $r['medium']);
            if ($platform === null) {
                continue;
            }
            $g = $out['ga4'][$platform] ?? ['sessions' => 0, 'conversions' => 0, 'revenue' => 0.0];
            $g['sessions'] += (int) $r['s'];
            $g['conversions'] += (int) $r['c'];
            $g['revenue'] += (float) $r['r'];
            $out['ga4'][$platform] = $g;
        }
        $paid = allstat_fetch_one($pdo, "SELECT SUM(sessions) AS s, SUM(conversions) AS c, SUM(revenue) AS r FROM traffic_sources_daily
            WHERE domain_id = ? AND metric_date BETWEEN ? AND ? AND (source LIKE 'Paid%' OR source IN ('Cross-network', 'Display'))", [$domainId, $start, $end]);
        if ($paid && $paid['s'] !== null) {
            $out['ga4Paid'] = ['sessions' => (int) $paid['s'], 'conversions' => (int) $paid['c'], 'revenue' => (float) $paid['r']];
        }
        $rev = allstat_fetch_one($pdo, 'SELECT SUM(revenue) AS r FROM traffic_sources_daily WHERE domain_id = ? AND metric_date BETWEEN ? AND ?', [$domainId, $start, $end]);
        $out['hasGa4Revenue'] = (float) ($rev['r'] ?? 0) > 0;
    } catch (Throwable) {
        // Starší instalace bez sloupce revenue (migrace ještě neproběhla): GA4 část vynechat.
    }

    return $out;
}
