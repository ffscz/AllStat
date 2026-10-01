<?php

/**
 * „Růst kanálů" – úvodní pohled dashboardu (návrh „AllStat – Přehled růstu kanálů", schváleno 29. 9. 2026).
 * Hlavní metrika každého napojeného kanálu po kalendářních měsících: karty, žebříček růstu, index v čase
 * a tabulka Měsíc po měsíci. Čte jen agregáty, které už plní synchronizace (metrics_daily + provider_metrics_daily).
 *
 * Pravidla výpočtu (odsouhlasená s uživatelem):
 * - Počítají se jen UZAVŘENÉ měsíce. Rozběhnutý měsíc se ukazuje zvlášť jako „zatím" a do růstu nevstupuje,
 *   jinak by na začátku každého měsíce všechno „padalo".
 * - Růst = průměr posledních 3 měsíců období proti prvním 3 měsícům (u období 3 měsíce poslední vs. první).
 *   Jeden měsíc s kampaní tak výsledek neotočí.
 * - Srovnání (růst, m/m, index) se počítá z průměru NA DEN S DATY. Neúplné měsíce (Clarity s mezerami, první
 *   měsíc po napojení, zpožděná GSC) ani různě dlouhé měsíce tak čísla nezkreslí. Zobrazené hodnoty jsou
 *   skutečné měsíční součty, měsíc bez dat za všechny dny je označený.
 * - Reklamní účty mají řádek jen ve dnech s útratou → u nich chybějící den = 0 (od prvního dne s daty).
 * - Každý účet (FB stránka, IG profil…) je samostatný kanál. Barva patří typu kanálu; pořadí typů a odstíny
 *   jsou ověřené validátorem palety na barvoslepost ve světlém i tmavém režimu (dataviz skill), další účet
 *   stejné sítě se v grafu kreslí čárkovaně.
 */

// Měsíc s méně dny dat se do srovnání (růst, m/m, index) nepočítá, jen se zobrazí jeho součet.
const ALLSTAT_GROWTH_MIN_DAYS = 7;
// Změna typického měsíce do ±10 % = „stabilní" (šedě), ne růst/pokles – drobné výkyvy nejsou trend.
const ALLSTAT_GROWTH_STABLE = 0.10;
// „Kolísá": průměr a medián ukazují opačný směr a liší se aspoň o 25 p. b. → výsledek otáčí jeden mimořádný měsíc.
const ALLSTAT_GROWTH_VOLATILE_GAP = 0.25;
// …ale jen když změna typického měsíce není výrazná: od ±50 % medián nese jasný trend a zkreslený je průměr
// (např. IG z ~30 na ~300 dosahu denně, jen říjnová kampaň 2025 v základu otočila průměr).
const ALLSTAT_GROWTH_VOLATILE_MAX = 0.50;

function allstat_growth_median(array $values): ?float
{
    $v = array_values(array_filter($values, static fn ($x): bool => $x !== null));
    if (!$v) {
        return null;
    }
    sort($v);
    $n = count($v);

    return $n % 2 ? (float) $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
}

/**
 * Srovnání dvou skupin měsíců jednoho kanálu: TYPICKÝ měsíc (medián průměrů na den) skupiny $recentYms proti
 * $baseYms. Medián ignoruje jeden mimořádný měsíc (kampaň, výpadek) – průměr 3 vs 3 dřív otočil např. LinkedIn
 * na −20 % jen kvůli silnému květnu v základu (29. 9. 2026). U reklamy ($zeroFill) průměr: dny bez kampaně jsou
 * skutečné nuly a medián by je zahodil. Průměr se počítá vždy – jako kontrola stavu „kolísá".
 * Stavy: up | down | stable (do ±10 %) | volatile (průměr ukazuje opačný směr a změna je pod 50 %) | new | none.
 * $spikeYms = měsíce, ve kterých se u „kolísá" hledá mimořádný měsíc (nejvzdálenější poměrem od mediánu).
 */
function allstat_growth_compare(array $entries, array $baseYms, array $recentYms, bool $zeroFill, array $spikeYms): array
{
    // Celý měsíc s nulou (dosah, zobrazení, návštěvy) = kanál ještě nefungoval nebo API data nevracelo
    // (např. Žijeme kreativitou před startem v 7/2025), ne skutečná nula → do srovnání nevstupuje.
    // U reklamy jsou nuly skutečné (měsíc bez kampaně), tam se počítají.
    $rateOf = static fn (string $ym): ?float => (($r = $entries[$ym]['rate'] ?? null) !== null && ($zeroFill || $r > 0)) ? $r : null;
    $mean = static function (array $yms) use ($entries, $rateOf): ?float {
        $v = 0.0;
        $d = 0;
        foreach ($yms as $ym) {
            if ($rateOf($ym) === null) {
                continue;
            }
            $v += (float) $entries[$ym]['value'];
            $d += $entries[$ym]['days'];
        }

        return $d > 0 ? $v / $d : null;
    };
    $rates = static fn (array $yms): array => array_map($rateOf, $yms);
    $meanBase = $mean($baseYms);
    $meanRecent = $mean($recentYms);
    $base = $zeroFill ? $meanBase : allstat_growth_median($rates($baseYms));
    $recent = $zeroFill ? $meanRecent : allstat_growth_median($rates($recentYms));
    $meanGrowth = ($meanBase !== null && $meanRecent !== null && $meanBase > 0) ? $meanRecent / $meanBase - 1 : null;
    $out = ['growth' => null, 'mean' => $meanGrowth, 'state' => 'none', 'spike' => null, 'base' => $base];
    if ($base === null || $recent === null) {
        return $out;
    }
    if ($base <= 0) {
        $out['state'] = $recent > 0 ? 'new' : 'none';

        return $out;
    }
    $growth = $recent / $base - 1;
    $out['growth'] = $growth;
    if (abs($growth) < ALLSTAT_GROWTH_STABLE) {
        $out['state'] = 'stable';
    } elseif ($meanGrowth !== null && ($meanGrowth > 0) !== ($growth > 0) && abs($meanGrowth - $growth) >= ALLSTAT_GROWTH_VOLATILE_GAP
        && abs($growth) < ALLSTAT_GROWTH_VOLATILE_MAX) {
        $out['state'] = 'volatile';
        $median = allstat_growth_median($rates($spikeYms));
        $maxDev = -1.0;
        foreach ($spikeYms as $ym) {
            $r = $entries[$ym]['rate'] ?? null;
            if ($r === null || $r <= 0 || !$median) { continue; }
            $dev = abs(log($r / $median));
            if ($dev > $maxDev) { $maxDev = $dev; $out['spike'] = $ym; }
        }
    } else {
        $out['state'] = $growth > 0 ? 'up' : 'down';
    }

    return $out;
}

/**
 * Hlavní metrika podle typu kanálu. Pořadí = pořadí karet, řádků tabulky i legendy grafu (neměnit bez
 * nového ověření palety). 'color' odkazuje na CSS proměnnou --ch-<color> v .growth-root (app.css).
 */
function allstat_growth_defs(): array
{
    return [
        'ga4' => ['name' => 'Google Analytics', 'metric' => 'Návštěvy webu', 'short' => 'návštěvy', 'color' => 'ga4', 'web' => 'visits'],
        'gsc' => ['name' => 'Search Console', 'metric' => 'Kliknutí z Google hledání', 'short' => 'kliknutí', 'color' => 'gsc', 'web' => 'clicks'],
        'clarity' => ['name' => 'MS Clarity', 'metric' => 'Relace na webu', 'short' => 'relace', 'color' => 'clarity', 'key' => 'sessions'],
        'youtube' => ['name' => 'YouTube', 'metric' => 'Zhlédnutí videí', 'short' => 'zhlédnutí', 'color' => 'youtube', 'key' => 'views', 'followers' => 'Odběratelé'],
        'facebook_pages' => ['name' => 'Facebook', 'metric' => 'Dosah stránky', 'short' => 'dosah', 'color' => 'facebook', 'key' => 'reach', 'followers' => 'Sledující'],
        'linkedin_company' => ['name' => 'LinkedIn', 'metric' => 'Zobrazení příspěvků', 'short' => 'zobrazení', 'color' => 'linkedin', 'key' => 'impressions', 'followers' => 'Sledující'],
        'instagram_business' => ['name' => 'Instagram', 'metric' => 'Dosah účtu', 'short' => 'dosah', 'color' => 'instagram', 'key' => 'reach', 'followers' => 'Sledující'],
        'meta_ads' => ['name' => 'Meta Ads', 'metric' => 'Kliknutí na reklamy', 'short' => 'kliknutí', 'color' => 'ads', 'key' => 'clicks', 'spend' => 'spend', 'zeroFill' => true],
        'google_ads' => ['name' => 'Google Ads', 'metric' => 'Kliknutí na reklamy', 'short' => 'kliknutí', 'color' => 'ads', 'key' => 'clicks', 'spend' => 'cost', 'zeroFill' => true],
    ];
}

function allstat_growth_month_label(string $ym, bool $long = false): string
{
    static $short = ['Led', 'Úno', 'Bře', 'Dub', 'Kvě', 'Čvn', 'Čvc', 'Srp', 'Zář', 'Říj', 'Lis', 'Pro'];
    static $full = ['leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];
    [$y, $m] = array_map('intval', explode('-', $ym));
    $m = max(1, min(12, $m));

    return $long ? $full[$m - 1] . ' ' . $y : $short[$m - 1] . ' ' . substr((string) $y, 2);
}

/**
 * Okno období: $months uzavřených měsíců končících posledním uzavřeným měsícem + rozběhnutý měsíc.
 * $today jde vnutit (sdílený report pro AI počítá okno ke konci svého období).
 */
function allstat_growth_window(int $months, ?DateTimeImmutable $today = null): array
{
    // Výchozí 12 měsíců (od 29. 9. 2026 na přání uživatele, dřív 6).
    $n = in_array($months, [3, 6, 12], true) ? $months : 12;
    $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
    $current = $today->modify('first day of this month');
    $list = [];
    for ($i = $n; $i >= 1; $i--) {
        $list[] = $current->modify('-' . $i . ' months')->format('Y-m');
    }
    $first = $list[0];
    $last = $list[$n - 1];
    $firstLong = allstat_growth_month_label($first, true);
    $lastLong = allstat_growth_month_label($last, true);
    // „duben – září 2026", přes přelom roku „říjen 2025 – září 2026" (en-pomlčka jako jinde v AllStatu).
    $rangeLabel = substr($first, 0, 4) === substr($last, 0, 4)
        ? preg_replace('/ \d{4}$/', '', $firstLong) . ' – ' . $lastLong
        : $firstLong . ' – ' . $lastLong;
    $k = $n >= 6 ? 3 : 1;
    // Meziročně: vždy poslední 3 uzavřené měsíce proti stejným 3 měsícům o rok dřív (stejná sezóna),
    // nezávisle na zvoleném období. Data se proto načítají od prvního z loňských měsíců.
    $yoyRecent = [];
    $yoyBase = [];
    for ($i = 3; $i >= 1; $i--) {
        $yoyRecent[] = $current->modify('-' . $i . ' months')->format('Y-m');
        $yoyBase[] = $current->modify('-' . ($i + 12) . ' months')->format('Y-m');
    }
    $yoyLabel = preg_replace('/ \d{4}$/', '', allstat_growth_month_label($yoyRecent[0], true)) . ' – '
        . preg_replace('/ \d{4}$/', '', allstat_growth_month_label($yoyRecent[2], true));

    return [
        'n' => $n,
        'k' => $k,
        'months' => $list,
        'yoyRecent' => $yoyRecent,
        'yoyBase' => $yoyBase,
        // „červenec – září" + roky zvlášť: „červenec – září 2026 proti 2025" (přes přelom roku jen orientačně)
        'yoyLabel' => $yoyLabel . ' ' . substr($yoyRecent[2], 0, 4) . ' proti ' . substr($yoyBase[2], 0, 4),
        'queryStart' => min($first, $yoyBase[0]) . '-01',
        'current' => $current->format('Y-m'),
        'start' => $first . '-01',
        'end' => $current->modify('-1 day')->format('Y-m-d'),
        // Rozběhnutý měsíc jen do včerejška: dnešní den je po syncu v průběhu dne neúplný.
        'dataEnd' => $today->modify('-1 day')->format('Y-m-d'),
        'today' => $today->format('Y-m-d'),
        'rangeLabel' => $rangeLabel,
        'baseLabel' => $k === 1 ? $firstLong : $firstLong . ' – ' . allstat_growth_month_label($list[$k - 1], true),
    ];
}

/** Procenta ze zlomku: „+65 %", „−4,2 %" (pod 10 % s desetinou, typografické minus jako v návrhu). */
function allstat_growth_pct(?float $fraction): string
{
    if ($fraction === null) {
        return '–';
    }
    $p = $fraction * 100;
    $dec = abs($p) < 10 ? 1 : 0;
    $txt = allstat_number(abs($p), $dec);
    if ($txt === '0' || $txt === '0,0') {
        return '0 %';
    }

    return ($p >= 0 ? '+' : '−') . $txt . ' %';
}

/** Malá spojnice z měsíčních hodnot; null = měsíc bez dat (čára se přeruší). Barva přes currentColor. */
function allstat_growth_spark_svg(array $vals): string
{
    $valid = array_values(array_filter($vals, static fn ($v): bool => $v !== null));
    if (count($valid) < 2) {
        return '';
    }
    $n = count($vals);
    $min = min($valid);
    $span = (max($valid) - $min) ?: 1.0;
    $segments = [];
    $cur = [];
    foreach (array_values($vals) as $i => $v) {
        if ($v === null) {
            if ($cur) { $segments[] = $cur; $cur = []; }
            continue;
        }
        $cur[] = [round($i / max(1, $n - 1) * 100, 2), round(28 - ($v - $min) / $span * 24, 2)];
    }
    if ($cur) {
        $segments[] = $cur;
    }
    $line = '';
    $area = '';
    foreach ($segments as $s) {
        $d = 'M' . implode(' L', array_map(static fn (array $p): string => $p[0] . ' ' . $p[1], $s));
        $line .= $d . ' ';
        if (count($s) > 1) {
            $area .= $d . ' L' . $s[count($s) - 1][0] . ' 32 L' . $s[0][0] . ' 32 Z ';
        }
    }

    return '<svg class="growth-spark" viewBox="0 0 100 32" preserveAspectRatio="none" aria-hidden="true" focusable="false">'
        . ($area !== '' ? '<path d="' . trim($area) . '" fill="currentColor" fill-opacity="0.12"/>' : '')
        . '<path d="' . trim($line) . '" fill="none" stroke="currentColor" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round"/>'
        . '</svg>';
}

/**
 * Data pohledu Růst kanálů pro jeden web. $months = 3 | 6 | 12 uzavřených měsíců.
 * Vrací okno období, kanály v pevném pořadí (s měsíci, růstem, m/m, indexem) a podklady pro graf.
 */
function allstat_get_channel_growth(PDO $pdo, int $domainId, int $months, ?DateTimeImmutable $today = null): array
{
    $w = allstat_growth_window($months, $today);
    $defs = allstat_growth_defs();
    $order = array_flip(array_keys($defs));
    // Souvislá řada měsíců od prvního potřebného (začátek okna, nebo loňské měsíce pro meziroční srovnání)
    // po rozběhnutý měsíc – m/m se tak vždy počítá proti skutečně předchozímu měsíci.
    $monthsAll = [];
    for ($m = new DateTimeImmutable($w['queryStart']); $m->format('Y-m') <= $w['current']; $m = $m->modify('+1 month')) {
        $monthsAll[] = $m->format('Y-m');
    }

    $conns = array_values(array_filter(
        allstat_dashboard_provider_options($pdo, $domainId),
        static fn (array $o): bool => isset($defs[$o['provider_key']])
    ));
    usort($conns, static fn (array $a, array $b): int => [$order[$a['provider_key']], $a['connection_id']] <=> [$order[$b['provider_key']], $b['connection_id']]);

    // GA4 + GSC žijí v metrics_daily po webech (ne po napojeních) → každý nejvýš jednou.
    $webRows = [];
    if (array_filter($conns, static fn (array $o): bool => in_array($o['provider_key'], ['ga4', 'gsc'], true))) {
        foreach (allstat_fetch_all($pdo, "SELECT DATE_FORMAT(metric_date, '%Y-%m') AS ym,
                SUM(visits) AS ga, SUM(visits > 0) AS ga_days, SUM(clicks) AS gsc, SUM(impressions > 0) AS gsc_days
            FROM metrics_daily WHERE domain_id = ? AND metric_date BETWEEN ? AND ? GROUP BY ym",
            [$domainId, $w['queryStart'], $w['dataEnd']]) as $r) {
            $webRows['ga4'][$r['ym']] = [(float) $r['ga'], (int) $r['ga_days']];
            $webRows['gsc'][$r['ym']] = [(float) $r['gsc'], (int) $r['gsc_days']];
        }
    }

    $provConns = array_values(array_filter($conns, static fn (array $o): bool => !isset($defs[$o['provider_key']]['web'])));
    $byConn = [];
    $spendByConn = [];
    $firstDate = [];
    $followers = [];
    if ($provConns) {
        $ids = array_map(static fn (array $o): int => (int) $o['connection_id'], $provConns);
        $keys = [];
        foreach ($provConns as $o) {
            $d = $defs[$o['provider_key']];
            $keys[$d['key']] = true;
            if (!empty($d['spend'])) {
                $keys[$d['spend']] = true;
            }
        }
        $idPh = implode(',', array_fill(0, count($ids), '?'));
        $keyPh = implode(',', array_fill(0, count($keys), '?'));
        // Jeden řádek na den a metriku (unikátní klíč), takže COUNT(*) = počet dní s daty.
        foreach (allstat_fetch_all($pdo, "SELECT connection_id, metric_key, DATE_FORMAT(metric_date, '%Y-%m') AS ym,
                SUM(metric_value) AS v, COUNT(*) AS days
            FROM provider_metrics_daily
            WHERE domain_id = ? AND connection_id IN ($idPh) AND metric_key IN ($keyPh) AND dimension = ''
              AND metric_date BETWEEN ? AND ?
            GROUP BY connection_id, metric_key, ym",
            array_merge([$domainId], $ids, array_keys($keys), [$w['queryStart'], $w['dataEnd']])) as $r) {
            $byConn[(int) $r['connection_id']][(string) $r['metric_key']][(string) $r['ym']] = [(float) $r['v'], (int) $r['days']];
        }

        $zeroIds = array_values(array_map(static fn (array $o): int => (int) $o['connection_id'],
            array_filter($provConns, static fn (array $o): bool => !empty($defs[$o['provider_key']]['zeroFill']))));
        if ($zeroIds) {
            $zPh = implode(',', array_fill(0, count($zeroIds), '?'));
            foreach (allstat_fetch_all($pdo, "SELECT connection_id, MIN(metric_date) AS d FROM provider_metrics_daily
                WHERE domain_id = ? AND connection_id IN ($zPh) AND dimension = '' GROUP BY connection_id",
                array_merge([$domainId], $zeroIds)) as $r) {
                $firstDate[(int) $r['connection_id']] = (string) $r['d'];
            }
        }

        $folIds = array_values(array_map(static fn (array $o): int => (int) $o['connection_id'],
            array_filter($provConns, static fn (array $o): bool => !empty($defs[$o['provider_key']]['followers']))));
        if ($folIds) {
            $fPh = implode(',', array_fill(0, count($folIds), '?'));
            foreach (allstat_fetch_all($pdo, "SELECT connection_id, metric_date, metric_value FROM provider_metrics_daily
                WHERE domain_id = ? AND connection_id IN ($fPh) AND metric_key = 'followers_total' AND dimension = ''
                  AND metric_date >= ? ORDER BY connection_id, metric_date",
                array_merge([$domainId], $folIds, [$w['start']])) as $r) {
                $cid = (int) $r['connection_id'];
                $followers[$cid]['first'] ??= [(string) $r['metric_date'], (float) $r['metric_value']];
                $followers[$cid]['last'] = [(string) $r['metric_date'], (float) $r['metric_value']];
            }
        }
    }

    $expectedDays = static function (string $ym) use ($w): int {
        if ($ym === $w['current']) {
            // Rozběhnutý měsíc: dny od 1. do včerejška (1. den v měsíci = 0).
            return max(0, (int) (new DateTimeImmutable($w['today']))->format('j') - 1);
        }

        return (int) (new DateTimeImmutable($ym . '-01'))->format('t');
    };

    $channels = [];
    $countByProvider = [];
    foreach ($conns as $o) {
        $pk = (string) $o['provider_key'];
        $def = $defs[$pk];
        $cid = (int) $o['connection_id'];
        if (isset($def['web'])) {
            if (isset($countByProvider[$pk])) {
                continue; // GA4/GSC napojené dvakrát na jeden web = pořád jedna řada metrics_daily
            }
            $raw = $webRows[$pk] ?? [];
        } else {
            $raw = $byConn[$cid][$def['key']] ?? [];
        }
        $zeroFill = !empty($def['zeroFill']);
        $fd = $firstDate[$cid] ?? null;

        $entries = [];
        $prevRate = null;
        foreach ($monthsAll as $ym) {
            $expected = $expectedDays($ym);
            [$value, $days] = $raw[$ym] ?? [0.0, 0];
            if ($zeroFill) {
                // Reklama: den bez řádku = den bez útraty (0), ale jen od prvního dne, kdy účet vůbec má data.
                if ($fd === null || $fd > $ym . '-31') {
                    $days = 0;
                } else {
                    $fromDay = substr($fd, 0, 7) === $ym ? (int) substr($fd, 8, 2) : 1;
                    $days = max(0, $expected - $fromDay + 1);
                }
            }
            $rate = $days >= ALLSTAT_GROWTH_MIN_DAYS ? $value / $days : null;
            $entries[$ym] = [
                'ym' => $ym,
                'label' => allstat_growth_month_label($ym),
                'longLabel' => allstat_growth_month_label($ym, true),
                'value' => $days > 0 ? $value : null,
                'days' => $days,
                'expected' => $expected,
                'rate' => $rate,
                // Označit až výpadek nad 10 % dnů: den bez návštěv/řádku se občas stane i bez chyby syncu
                // a hvězdička u skoro každého měsíce by přestala nést informaci. Rozběhnutý měsíc je „zatím" sám o sobě.
                'partial' => $ym !== $w['current'] && $days > 0 && $days < $expected * 0.9,
                'mom' => ($rate !== null && $prevRate !== null && $prevRate > 0) ? $rate / $prevRate - 1 : null,
                'isCurrent' => $ym === $w['current'],
            ];
            $prevRate = $rate;
        }

        // Růst za období: typický měsíc prvních k proti posledním k měsícům okna (viz allstat_growth_compare).
        $main = allstat_growth_compare($entries, array_slice($w['months'], 0, $w['k']), array_slice($w['months'], -$w['k']), $zeroFill, $w['months']);
        $growth = $main['growth'];
        $state = $main['state'];
        $meanGrowth = $main['mean'];
        $spikeYm = $main['spike'];
        $base = $main['base']; // základ indexu grafu Vývoj v čase (100 = typický měsíc začátku okna)
        // Meziročně: poslední 3 uzavřené měsíce proti stejným 3 měsícům loni (stejná sezóna). Jen když loni
        // i letos jsou aspoň 2 ze 3 měsíců s nenulovými daty – nuly před startem účtu nebo před tím, než API
        // data vůbec vracelo (FB/IG, léto 2025), by jinak vyrobily nesmyslné tisíce procent.
        $positive = static fn (array $yms): int => count(array_filter($yms, static fn (string $ym): bool => ($entries[$ym]['rate'] ?? 0) > 0));
        $yoy = null;
        if ($zeroFill || ($positive($w['yoyBase']) >= 2 && $positive($w['yoyRecent']) >= 2)) {
            $yoy = allstat_growth_compare($entries, $w['yoyBase'], $w['yoyRecent'], $zeroFill, array_merge($w['yoyBase'], $w['yoyRecent']));
            if (in_array($yoy['state'], ['none', 'new'], true)) {
                $yoy = null;
            }
        }

        $window = array_map(static fn (string $ym): array => $entries[$ym], $w['months']);
        $last = $entries[$w['months'][$w['n'] - 1]];
        $values = array_values(array_filter(array_column($window, 'value'), static fn ($v): bool => $v !== null));

        $range = $values ? 'min. ' . allstat_number(min($values)) . ' · max. ' . allstat_number(max($values)) : '';
        $followersInfo = null;
        $spendText = null;
        if (!empty($def['followers']) && isset($followers[$cid])) {
            [$fDate, $fVal] = $followers[$cid]['first'];
            [$lDate, $lVal] = $followers[$cid]['last'];
            $diff = $lVal - $fVal;
            // Snímky sledujících existují od 8. 7. 2026 (u YouTube od 15. 9.) → u delšího období změna „od" data.
            $since = $fDate > (new DateTimeImmutable($w['start']))->modify('+3 days')->format('Y-m-d')
                ? 'od ' . (new DateTimeImmutable($fDate))->format('j. n.')
                : 'za období';
            $followersInfo = [
                'label' => $def['followers'],
                'current' => $lVal,
                'diff' => $diff,
                'pct' => $fVal > 0 ? $diff / $fVal : null,
                'since' => $since,
                'fromDate' => $fDate,
                'fromValue' => $fVal,
                'toDate' => $lDate,
            ];
            $extra = $def['followers'] . ' ' . allstat_number($lVal) . ' (' . ($diff >= 0 ? '+' : '−') . allstat_number(abs($diff))
                . ($fVal > 0 ? ', ' . allstat_growth_pct($diff / $fVal) : '') . ' ' . $since . ')';
        } elseif (!empty($def['spend'])) {
            [$spend] = $byConn[$cid][$def['spend']][$last['ym']] ?? [0.0, 0];
            $spendText = 'Útrata za ' . preg_replace('/ \d{4}$/', '', $last['longLabel']) . ' ' . allstat_number($spend) . ' Kč';
            $extra = $spendText;
        } else {
            $extra = $range;
        }

        $countByProvider[$pk] = ($countByProvider[$pk] ?? 0) + 1;
        $account = trim((string) ($o['label'] ?? ''));
        if ($account === (string) $o['name'] || $account === $def['name'] || isset($def['web'])) {
            $account = '';
        }
        $partialMonths = array_values(array_filter($window, static fn (array $e): bool => $e['partial']));

        $channels[] = [
            'id' => isset($def['web']) ? $pk : 'c' . $cid,
            'provider' => $pk,
            'name' => $def['name'],
            'account' => $account,
            'metric' => $def['metric'],
            'short' => $def['short'],
            'color' => $def['color'],
            'dashed' => $countByProvider[$pk] > 1,
            'href' => '?' . http_build_query(isset($def['web'])
                ? ['view' => 'overview', 'domain_id' => $domainId]
                : ['source_id' => $cid, 'domain_id' => $domainId]),
            'months' => $window,
            'current' => $entries[$w['current']],
            'last' => $last,
            'growth' => $growth,
            // up | down | stable (do ±10 %) | volatile (směr otáčí jeden mimořádný měsíc) | new | none (málo dat)
            'growthState' => $state,
            'meanGrowth' => $meanGrowth,
            'spike' => $spikeYm !== null ? allstat_growth_month_label($spikeYm, true) : null,
            // Meziroční srovnání (null = zatím nemáme data za stejné měsíce loni).
            'yoy' => $yoy === null ? null : [
                'growth' => $yoy['growth'],
                'state' => $yoy['state'],
                'mean' => $yoy['mean'],
                'spike' => $yoy['spike'] !== null ? allstat_growth_month_label($yoy['spike'], true) : null,
            ],
            'mom' => $last['mom'],
            'max' => $values ? max($values) : 0.0,
            'index' => array_map(static fn (array $e): ?float => ($e['rate'] !== null && $base !== null && $base > 0) ? round($e['rate'] / $base * 100, 1) : null, $window),
            'spark' => array_column($window, 'rate'),
            'extra' => $extra,
            'range' => $range,
            'spendText' => $spendText,
            'followers' => $followersInfo,
            'partialMonths' => $partialMonths,
        ];
    }

    // Žebříček: kanály s jednoznačným číslem (roste / stabilní / klesá) podle růstu; „kolísá", nové a bez dat na konec.
    $ranked = array_values(array_filter($channels, static fn (array $c): bool => in_array($c['growthState'], ['up', 'stable', 'down'], true)));
    usort($ranked, static fn (array $a, array $b): int => $b['growth'] <=> $a['growth']);
    $ups = array_values(array_filter($ranked, static fn (array $c): bool => $c['growthState'] === 'up'));
    $downs = array_values(array_filter($ranked, static fn (array $c): bool => $c['growthState'] === 'down'));

    return [
        'window' => $w,
        'channels' => $channels,
        'ranked' => $ranked,
        // Souhrnná věta nahoře jen z jednoznačných změn: „stabilní" a „kolísá" nejsou růst ani pokles.
        'best' => $ups[0] ?? null,
        'worst' => $downs ? $downs[count($downs) - 1] : null,
    ];
}

/** Payload pro graf „Vývoj v čase" (assets/js/growth.js). */
function allstat_growth_chart_payload(array $growth): array
{
    $w = $growth['window'];

    return [
        'labels' => array_map(static fn (string $ym): string => allstat_growth_month_label($ym), $w['months']),
        'longLabels' => array_map(static fn (string $ym): string => allstat_growth_month_label($ym, true), $w['months']),
        'series' => array_values(array_map(static fn (array $c): array => [
            'name' => $c['name'],
            'account' => $c['account'],
            'color' => $c['color'],
            'dashed' => $c['dashed'],
            'index' => $c['index'],
            'raw' => array_map(static fn (array $e): string => $e['value'] === null ? '–' : allstat_number($e['value']) . ' ' . $c['short'], $c['months']),
        ], array_filter($growth['channels'], static fn (array $c): bool => array_filter($c['index'], static fn ($v): bool => $v !== null) !== []))),
    ];
}
