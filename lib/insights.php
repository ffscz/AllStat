<?php

/**
 * Detekce neobvyklých změn (MCP nástroj detect_changes, 1.5.5). Deterministická, bez AI: poslední okno
 * (7, 14 nebo 28 dní) každé denní řady se porovná s mediánem stejně dlouhých oken před ním. Okna jsou násobky
 * týdne, takže víkendy a pracovní dny se srovnávají se stejnými. Odchylka se měří robustním z-skóre
 * (medián a MAD), takže jeden mimořádný týden v historii srovnání nezkreslí.
 * Potřebuje lib/growth.php (allstat_growth_defs, allstat_growth_median) a lib/repository.php.
 */

/** Nejmenší typická hodnota okna, pod kterou se změny nehlásí (malá čísla skáčou náhodou). */
const ALLSTAT_CHANGES_MIN_VOLUME = 20;

/**
 * Denní řady webu: GA4 a Search Console z metrics_daily, kanály GA4 a hlavní metriky napojených zdrojů.
 *
 * @return list<array{id: string, label: string, source: string, unit: string, zeroFill: bool, higherIsBetter: bool, days: array<string, float>}>
 */
function allstat_changes_series(PDO $pdo, int $domainId, string $from, string $to): array
{
    $series = [];
    $web = allstat_fetch_all($pdo, 'SELECT metric_date, visits, conversions, clicks, impressions, revenue FROM metrics_daily WHERE domain_id = ? AND metric_date BETWEEN ? AND ?', [$domainId, $from, $to]);
    $webDefs = [
        'visits' => ['Návštěvy webu', 'Google Analytics', 'návštěv'],
        'conversions' => ['Klíčové události', 'Google Analytics', 'konverzí'],
        'revenue' => ['Tržby', 'Google Analytics', 'v měně property'],
        'clicks' => ['Kliknutí z Google hledání', 'Search Console', 'kliknutí'],
        'impressions' => ['Zobrazení v Google hledání', 'Search Console', 'zobrazení'],
    ];
    foreach ($webDefs as $col => [$label, $source, $unit]) {
        $days = [];
        foreach ($web as $r) {
            // GA4 a Search Console mají každé jiné zpoždění: den bez dat zdroje není nula.
            $present = in_array($col, ['clicks', 'impressions'], true) ? (int) $r['impressions'] > 0 : (int) $r['visits'] > 0;
            if ($present) {
                $days[(string) $r['metric_date']] = (float) $r[$col];
            }
        }
        if ($days) {
            $series[] = ['id' => 'web.' . $col, 'label' => $label, 'source' => $source, 'unit' => $unit, 'zeroFill' => false, 'higherIsBetter' => true, 'days' => $days];
        }
    }

    // Kanály GA4 (Organické vyhledávání, Placené vyhledávání …): návštěvy.
    $channelDays = [];
    foreach (allstat_fetch_all($pdo, 'SELECT metric_date, source, SUM(sessions) AS s FROM traffic_sources_daily WHERE domain_id = ? AND metric_date BETWEEN ? AND ? GROUP BY metric_date, source', [$domainId, $from, $to]) as $r) {
        $channelDays[(string) $r['source']][(string) $r['metric_date']] = (float) $r['s'];
    }
    $webVisits = [];
    foreach ($series as $s) {
        if ($s['id'] === 'web.visits') {
            $webVisits = $s['days'];
        }
    }
    foreach ($channelDays as $channel => $days) {
        // Den, kdy GA4 mělo data, ale kanál ne, je nula (kanál ten den nikoho nepřivedl).
        foreach ($webVisits as $d => $_) {
            $days[$d] ??= 0.0;
        }
        $series[] = ['id' => 'channel.' . $channel, 'label' => 'Návštěvy z kanálu ' . $channel, 'source' => 'Google Analytics', 'unit' => 'návštěv', 'zeroFill' => false, 'higherIsBetter' => true, 'days' => $days];
    }

    // Napojené zdroje: hlavní metrika z Růstu kanálů, u reklamy navíc útrata a konverze.
    $defs = allstat_growth_defs();
    $connections = allstat_fetch_all($pdo, 'SELECT ds.id, ds.account_label, s.provider_key FROM domain_sources ds INNER JOIN data_sources s ON s.id = ds.source_id
        WHERE ds.domain_id = ? AND ds.is_enabled = 1', [$domainId]);
    foreach ($connections as $c) {
        $pk = (string) $c['provider_key'];
        $def = $defs[$pk] ?? null;
        if ($def === null || empty($def['key'])) {
            continue;
        }
        $keys = [(string) $def['key'] => [(string) $def['metric'], true]];
        if (!empty($def['spend'])) {
            $keys[(string) $def['spend']] = ['Útrata za reklamu', false];
            $keys['conversions'] = ['Konverze podle platformy', true];
        }
        $ph = implode(',', array_fill(0, count($keys), '?'));
        $byKey = [];
        foreach (allstat_fetch_all($pdo, "SELECT metric_key, metric_date, SUM(metric_value) AS v FROM provider_metrics_daily
            WHERE domain_id = ? AND connection_id = ? AND COALESCE(dimension, '') = '' AND metric_key IN ($ph) AND metric_date BETWEEN ? AND ?
            GROUP BY metric_key, metric_date", array_merge([$domainId, (int) $c['id']], array_keys($keys), [$from, $to])) as $r) {
            $byKey[(string) $r['metric_key']][(string) $r['metric_date']] = (float) $r['v'];
        }
        $account = trim((string) ($c['account_label'] ?? ''));
        foreach ($keys as $key => [$label, $higherIsBetter]) {
            if (empty($byKey[$key])) {
                continue;
            }
            $series[] = [
                'id' => 'c' . (int) $c['id'] . '.' . $key,
                'label' => $label,
                'source' => (string) $def['name'] . ($account !== '' ? ' (' . $account . ')' : ''),
                'unit' => $key === ($def['spend'] ?? '') ? 'v měně účtu' : (string) $def['short'],
                // Reklama zapisuje jen dny s provozem: den bez řádku je nula, ne výpadek.
                'zeroFill' => !empty($def['zeroFill']),
                'higherIsBetter' => $higherIsBetter,
                'days' => $byKey[$key],
            ];
        }
    }

    return $series;
}

/**
 * @return array{changes: list<array<string, mixed>>, checked: int, skipped: list<array{label: string, source: string, reason: string}>, window_days: int, baseline_windows: int}
 */
function allstat_detect_changes(PDO $pdo, int $domainId, int $days = 7, ?DateTimeImmutable $today = null): array
{
    $days = in_array($days, [7, 14, 28], true) ? $days : 7;
    $windows = $days === 28 ? 6 : 8;
    $yesterday = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0)->modify('-1 day');
    $from = $yesterday->modify('-' . ($days * ($windows + 1) + 7) . ' days')->format('Y-m-d');
    $out = ['changes' => [], 'checked' => 0, 'skipped' => [], 'window_days' => $days, 'baseline_windows' => $windows];

    try {
        $all = allstat_changes_series($pdo, $domainId, $from, $yesterday->format('Y-m-d'));
    } catch (Throwable) {
        return $out;
    }

    $sum = static function (array $byDay, DateTimeImmutable $end, int $len, bool $zeroFill): ?float {
        $total = 0.0;
        $present = 0;
        for ($i = 0; $i < $len; $i++) {
            $d = $end->modify('-' . $i . ' days')->format('Y-m-d');
            if (isset($byDay[$d])) {
                $total += $byDay[$d];
                $present++;
            }
        }
        // Okno, kde chybí víc než čtvrtina dní, se nepočítá (výpadek synchronizace, ne skutečný pokles).
        return ($zeroFill || $present >= $len * 0.75) ? $total : null;
    };

    foreach ($all as $s) {
        $dates = array_keys($s['days']);
        if (!$dates) {
            continue;
        }
        // Konec okna: u reklamy včerejšek, u ostatních poslední den s daty (zdroje se zpožděním, např. Search Console).
        $last = $s['zeroFill'] ? $yesterday : new DateTimeImmutable(max($dates));
        if ($yesterday->diff($last)->days > 7) {
            $out['skipped'][] = ['label' => $s['label'], 'source' => $s['source'], 'reason' => 'Poslední data jsou z ' . $last->format('j. n. Y') . ', zdroj se nejspíš nesynchronizuje.'];
            continue;
        }
        $current = $sum($s['days'], $last, $days, $s['zeroFill']);
        $baseline = [];
        for ($w = 1; $w <= $windows; $w++) {
            $v = $sum($s['days'], $last->modify('-' . ($w * $days) . ' days'), $days, $s['zeroFill']);
            if ($v !== null) {
                $baseline[] = $v;
            }
        }
        if ($current === null || count($baseline) < 3) {
            continue;
        }
        $out['checked']++;
        $median = (float) allstat_growth_median($baseline);
        if ($median < ALLSTAT_CHANGES_MIN_VOLUME && $current < ALLSTAT_CHANGES_MIN_VOLUME) {
            continue;
        }
        $mad = (float) allstat_growth_median(array_map(static fn (float $v): float => abs($v - $median), $baseline));
        $z = $mad > 0 ? ($current - $median) / (1.4826 * $mad) : null;
        $changePct = $median > 0 ? ($current - $median) / $median * 100 : null;
        $absChange = $changePct === null ? INF : abs($changePct);
        $unusual = $absChange >= 25 && ($z === null ? $absChange >= 50 : abs($z) >= 2.5);
        if (!$unusual) {
            continue;
        }
        $up = $current > $median;
        $out['changes'][] = [
            'series' => $s['id'],
            'label' => $s['label'],
            'source' => $s['source'],
            'unit' => $s['unit'],
            'direction' => $up ? 'up' : 'down',
            'good' => $up === $s['higherIsBetter'],
            'severity' => ($z !== null && abs($z) >= 4) || $absChange >= 100 ? 'high' : 'medium',
            'window' => ['start' => $last->modify('-' . ($days - 1) . ' days')->format('Y-m-d'), 'end' => $last->format('Y-m-d')],
            'value' => round($current, 2),
            'typical' => round($median, 2),
            'change_pct' => $changePct === null ? null : round($changePct, 1),
            'z' => $z === null ? null : round($z, 1),
            'baseline_values' => array_map(static fn (float $v): float => round($v, 2), $baseline),
        ];
    }

    usort($out['changes'], static fn (array $a, array $b): int => [$b['severity'] === 'high', abs($b['z'] ?? 99), abs($b['change_pct'] ?? 999)]
        <=> [$a['severity'] === 'high', abs($a['z'] ?? 99), abs($a['change_pct'] ?? 999)]);

    return $out;
}
