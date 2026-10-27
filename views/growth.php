<?php
/**
 * Úvodní Přehled (Růst kanálů). Vkládá index.php; data z allstat_get_channel_growth() ($growth) a
 * allstat_growth_chart_payload() ($growthChart). Souhrnné dlaždice, karty kanálů po skupinách Web / Sociální
 * sítě / Reklama, tabulka Měsíc po měsíci, graf Vývoj v čase a vysvětlení výpočtu. Změnu období a graf
 * obsluhuje assets/js/growth.js (období načte znovu jen tenhle blok).
 */

// Jen jako součást index.php; přímé otevření šablony z URL nemá data (vypsalo by jen chyby).
if (!isset($growth, $growthChart, $data)) {
    http_response_code(404);
    exit;
}

$gw = $growth['window'];
$periodWord = $gw['n'] === 12 ? t('12 měsíců') : ($gw['n'] === 6 ? t('6 měsíců') : t('3 měsíce'));
$recentLabel = $gw['k'] === 1
    ? allstat_growth_month_label($gw['months'][$gw['n'] - 1], true)
    : allstat_growth_month_label($gw['months'][$gw['n'] - $gw['k']], true) . ' – ' . allstat_growth_month_label($gw['months'][$gw['n'] - 1], true);
$growthHow = $gw['k'] === 1
    ? t('Poslední měsíc ({recent}) proti prvnímu ({base}), přepočteno na den s daty.', ['recent' => $recentLabel, 'base' => $gw['baseLabel']])
    : t('Typický měsíc (medián) posledních 3 měsíců ({recent}) proti typickému měsíci prvních 3 ({base}), přepočteno na den s daty.', ['recent' => $recentLabel, 'base' => $gw['baseLabel']]);
$currentUntil = (new DateTimeImmutable($gw['dataEnd']))->format(allstat_locale() === 'en' ? 'j M' : 'j. n.');
$currentHasDays = (new DateTimeImmutable($gw['today']))->format('j') !== '1';
$monthName = static fn (string $ym): string => (string) preg_replace('/ \d{4}$/', '', allstat_growth_month_label($ym, true));
$ucfirst = static fn (string $s): string => mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
$lastYm = $gw['months'][$gw['n'] - 1];
$lastMonth = $monthName($lastYm);
$currentMonth = $monthName($gw['current']);
// Web kanály (GA4, GSC, Clarity) nemají účet, pod názvem je adresa webu.
$domainHost = (string) preg_replace('~^(https?://)?(www\.)?~i', '', rtrim((string) ($data['domain']['url'] ?? ''), '/'));
$accountOf = static fn (array $c): string => $c['account'] !== '' ? $c['account'] : $domainHost;

// Procento bez znaménka do štítku s trojúhelníkem (směr nese trojúhelník a barva).
$pctAbs = static fn (?float $f): string => (string) preg_replace('/^[+−]/u', '', allstat_growth_pct($f));
$tone = static fn (?float $v): string => $v === null ? 'is-muted' : ($v >= 0 ? 'is-up' : 'is-down');
$tri = static fn (string $dir): string => '<svg class="tri" viewBox="0 0 10 8" aria-hidden="true"><path d="' . ($dir === 'up' ? 'M5 0 10 8H0z' : 'M0 0h10L5 8z') . '" fill="currentColor"/></svg>';

// Štítek růstu podle stavu (viz allstat_get_channel_growth): up/down = jednoznačná změna, stable = do ±10 %,
// volatile = směr otáčí jeden mimořádný měsíc, new = základ byl nulový, none = málo dat pro srovnání.
// Vrací [tón štítku, směr trojúhelníku, text, text pro čtečku, třída textu v tabulce, text v tabulce].
$growthBadge = static function (array $c) use ($pctAbs): array {
    return match ($c['growthState']) {
        'up' => ['tone-up', 'up', $pctAbs($c['growth']), t('Růst o'), 'is-up', allstat_growth_pct($c['growth'])],
        'down' => ['tone-down', 'down', $pctAbs($c['growth']), t('Pokles o'), 'is-down', allstat_growth_pct($c['growth'])],
        'stable' => ['tone-flat', '', t('Stabilní'), '', 'is-flat', t('stabilní')],
        'volatile' => ['tone-warn', '', t('Kolísá'), '', 'is-warn', t('kolísá')],
        'new' => ['tone-accent', '', t('Nově'), '', 'is-muted', t('nově')],
        default => ['tone-flat', '', t('Málo dat'), '', 'is-muted', t('málo dat')],
    };
};
// Tooltip k růstu: jak se počítá, u „kolísá" i proč nejde určit směr.
$growthTitle = static function (array $c) use ($growthHow): string {
    if ($c['growthState'] === 'volatile') {
        return t('Směr nejde spolehlivě určit: typický měsíc {typical}, ale průměr by ukázal {mean}. Výsledek otáčí jeden mimořádný měsíc ({spike}), typicky kampaň nebo výpadek.', [
            'typical' => allstat_growth_pct($c['growth']),
            'mean' => allstat_growth_pct($c['meanGrowth']),
            'spike' => $c['spike'] ?? '?',
        ]) . ' ' . $growthHow;
    }
    if ($c['growthState'] === 'stable') {
        return t('Změna typického měsíce je do ±10 %, proto „stabilní".') . ' ' . $growthHow;
    }

    return $growthHow;
};
$yoyTitle = static function (array $c) use ($gw): string {
    $y = $c['yoy'];
    if (!$y) {
        return $c['yoyNote'] ?? t('Meziroční srovnání se ukáže, až budou data za stejné měsíce loni ({label}).', ['label' => $gw['yoyLabel']]);
    }
    $t = t('Meziročně: typický měsíc {label} (stejná sezóna), přepočteno na den s daty.', ['label' => $gw['yoyLabel']]);
    if ($y['state'] === 'volatile') {
        $t .= ' ' . t('Směr nejde spolehlivě určit: typický měsíc {typical}, průměr {mean}, výkyv {spike}.', [
            'typical' => allstat_growth_pct($y['growth']),
            'mean' => allstat_growth_pct($y['mean']),
            'spike' => $y['spike'] ?? '?',
        ]);
    }

    return $t;
};
// Meziročně do karty: [text, třída].
$yoyView = static function (?array $y) use ($tone): array {
    if (!$y) {
        return ['–', 'is-muted'];
    }

    return match ($y['state']) {
        'up', 'down' => [allstat_growth_pct($y['growth']), $tone($y['growth'])],
        'stable' => [allstat_growth_pct($y['growth']), 'is-flat'],
        default => [t('kolísá'), 'is-warn'],
    };
};
$partialTitle = static fn (array $e): string => t('Data jen za {days} z {expected} dní. Změny se počítají z průměru na den, takže srovnání neúplný měsíc nezkresluje.', ['days' => $e['days'], 'expected' => $e['expected']]);

// Skupiny karet a řádků tabulky podle typu kanálu (pořadí kanálů uvnitř skupiny drží allstat_get_channel_growth).
$groupOf = [
    'ga4' => 'web', 'gsc' => 'web', 'clarity' => 'web',
    'facebook_pages' => 'social', 'instagram_business' => 'social', 'linkedin_company' => 'social', 'youtube' => 'social',
    'meta_ads' => 'ads', 'google_ads' => 'ads', 'microsoft_ads' => 'ads',
];
$groups = [
    'web' => ['title' => t('Web'), 'desc' => t('Návštěvnost a vyhledávání'), 'icon' => 'globe', 'items' => []],
    'social' => ['title' => t('Sociální sítě'), 'desc' => t('Dosah, zobrazení a sledující'), 'icon' => 'share-2', 'items' => []],
    'ads' => ['title' => t('Reklama'), 'desc' => t('Placené kampaně v měně účtu'), 'icon' => 'megaphone', 'items' => []],
];
foreach ($growth['channels'] as $c) {
    $groups[$groupOf[$c['provider']] ?? 'social']['items'][] = $c;
}
$groups = array_filter($groups, static fn (array $g): bool => $g['items'] !== []);

// Barva čáry kanálu v grafu Vývoj v čase: barva popředí dlaždice služby (--src-*-fg), stejně ji bere assets/js/growth.js.
$lineToken = static fn (string $color): string => '--src-' . match ($color) {
    'ga4' => 'ga', 'gsc' => 'sc', 'clarity' => 'cl', 'youtube' => 'yt', 'facebook' => 'fb',
    'linkedin' => 'li', 'instagram' => 'ig', default => 'ma',
} . '-fg';

// Souhrnné dlaždice.
$states = array_count_values(array_column($growth['channels'], 'growthState'));
$upCount = $states['up'] ?? 0;
$kpiParts = [];
if (!empty($states['down'])) { $kpiParts[] = tn($states['down'], '{count} klesá', '{count} klesají', '{count} klesá'); }
if (!empty($states['stable'])) { $kpiParts[] = t('{count} stabilní', ['count' => $states['stable']]); }
if (!empty($states['volatile'])) { $kpiParts[] = t('{count} kolísá', ['count' => $states['volatile']]); }
$fewData = ($states['none'] ?? 0) + ($states['new'] ?? 0);
if ($fewData) { $kpiParts[] = tn($fewData, '{count} má málo dat', '{count} mají málo dat', '{count} má málo dat'); }
$best = $growth['best'];
$worst = $growth['worst'];
$adChannels = array_values(array_filter($growth['channels'], static fn (array $c): bool => ($groupOf[$c['provider']] ?? '') === 'ads'));
// Útrata po měnách (Microsoft Ads může mít účet v EUR); dlaždice ukáže první měnu, ostatní připíše.
$spendBy = [];
$adClicks = 0.0;
foreach ($adChannels as $c) {
    if ($c['spend'] !== null) {
        $cur = $c['spendCurrency'] !== '' ? $c['spendCurrency'] : 'Kč';
        $spendBy[$cur] = ($spendBy[$cur] ?? 0.0) + $c['spend'];
    }
    $adClicks += (float) ($c['last']['value'] ?? 0);
}
$spendCurrencies = array_keys($spendBy);
?>
<div class="page-stack" data-growth>
<script type="application/json" data-growth-json><?= allstat_json($growthChart) ?></script>
<?php if (!$growth['channels']): ?>
    <section class="card empty">
        <span class="empty-icon tone-accent"><?= allstat_icon('trending-up', 'icon') ?></span>
        <h2 class="empty-title"><?= h(t('Zatím žádné kanály')) ?></h2>
        <p class="empty-desc"><?= t('Tento web zatím nemá napojený žádný kanál s měsíčními daty (GA4, Search Console, sociální sítě, YouTube, reklamy). Napoj ho ve {link}.', ['link' => '<a class="link-more" href="admin/sources.php?domain_id=' . (int) $data['domain']['id'] . '">' . h(t('Zdroje dat')) . '</a>']) ?></p>
    </section>
<?php else: ?>
    <div class="grid grid-kpis">
        <div class="card kpi">
            <span class="metric-label"><?= h(t('Kanály v růstu')) ?></span>
            <span class="metric-value"><?= h(allstat_number($upCount)) ?><span class="metric-unit"> <?= h(t('z {count}', ['count' => allstat_number(count($growth['channels']))])) ?></span></span>
            <span class="metric-note ellipsis"><?= h($kpiParts ? implode(' · ', $kpiParts) : t('Všechny kanály rostou')) ?></span>
        </div>
        <div class="card kpi"<?= $best ? ' title="' . h($growthTitle($best)) . '"' : '' ?>>
            <span class="metric-label"><?= h(t('Nejrychleji roste')) ?></span>
            <span class="metric-value <?= $best ? 'is-up' : 'is-muted' ?>"><?= h($best ? allstat_growth_pct($best['growth']) : '–') ?></span>
            <span class="metric-note ellipsis"><?= h($best ? $best['name'] . ' · ' . $accountOf($best) : t('Žádný kanál výrazně neroste')) ?></span>
        </div>
        <div class="card kpi"<?= $worst ? ' title="' . h($growthTitle($worst)) . '"' : '' ?>>
            <span class="metric-label"><?= h(t('Nejvíc klesá')) ?></span>
            <span class="metric-value <?= $worst ? 'is-down' : 'is-muted' ?>"><?= h($worst ? allstat_growth_pct($worst['growth']) : '–') ?></span>
            <span class="metric-note ellipsis"><?= h($worst ? $worst['name'] . ' · ' . $accountOf($worst) : t('Žádný kanál výrazně neklesá')) ?></span>
        </div>
        <div class="card kpi">
            <span class="metric-label"><?= h(t('Útrata za reklamu za {month}', ['month' => $lastMonth])) ?></span>
            <?php if ($spendBy): ?>
                <span class="metric-value"><?= h(allstat_number($spendBy[$spendCurrencies[0]])) ?><span class="metric-unit"> <?= h($spendCurrencies[0]) ?></span></span>
                <span class="metric-note ellipsis"><?php
                    $others = array_map(static fn (string $cur): string => '+ ' . allstat_number($spendBy[$cur]) . ' ' . $cur, array_slice($spendCurrencies, 1));
                    echo h(implode(' · ', array_merge(
                        [count($adChannels) === 1 ? $adChannels[0]['name'] : tn(count($adChannels), '{count} účet', '{count} účty', '{count} účtů')],
                        $others,
                        [tn((int) $adClicks, '{count} kliknutí', '{count} kliknutí', '{count} kliknutí')]
                    )));
                ?></span>
            <?php else: ?>
                <span class="metric-value is-muted">–</span>
                <span class="metric-note ellipsis"><?= h(t('Žádná napojená reklama')) ?></span>
            <?php endif; ?>
        </div>
    </div>

    <?php foreach ($groups as $gKey => $g): ?>
        <section class="section" aria-labelledby="growth-<?= h($gKey) ?>">
            <div class="section-head">
                <span class="section-icon" aria-hidden="true"><?= allstat_icon($g['icon'], 'icon-sm') ?></span>
                <h2 class="h2" id="growth-<?= h($gKey) ?>"><?= h($g['title']) ?></h2>
                <span class="count"><?= count($g['items']) ?></span>
                <span class="section-desc"><?= h($g['desc']) ?></span>
            </div>
            <div class="grid grid-cards">
                <?php foreach ($g['items'] as $c):
                    [$bTone, $bDir, $bText, $bSr] = $growthBadge($c);
                    $last = $c['last'];
                    $max = max(1.0, (float) $c['max']);
                    [$yText, $yClass] = $yoyView($c['yoy']);
                    $first = $c['months'][0];
                ?>
                    <article class="card channel">
                        <div class="channel-head">
                            <?= allstat_source_tile($c['provider']) ?>
                            <div class="name-stack">
                                <h3 class="channel-name ellipsis"><a class="channel-link" href="<?= h($c['href']) ?>"><?= h($c['name']) ?></a></h3>
                                <span class="name-sub ellipsis" title="<?= h($accountOf($c)) ?>"><?= h($accountOf($c)) ?></span>
                            </div>
                            <?= allstat_icon('chevron-right', 'channel-chev') ?>
                        </div>
                        <div class="channel-meta"><span class="ellipsis"><?= h(t('{metric} za {month}', ['metric' => $c['metric'], 'month' => $lastMonth])) ?></span><span><?= h(t('Růst · {period}', ['period' => $periodWord])) ?></span></div>
                        <div class="channel-value">
                            <span class="metric-value"><?= $last['value'] === null ? '–' : h(allstat_number($last['value'])) ?></span>
                            <span class="badge <?= $bTone ?>" title="<?= h($growthTitle($c)) ?>"><?= $bDir !== '' ? $tri($bDir) : '' ?><?php if ($bSr !== ''): ?><span class="sr-only"><?= h($bSr) ?></span><?php endif; ?><span><?= h($bText) ?></span></span>
                        </div>
                        <div class="bars" role="img" aria-label="<?= h(t('{metric}, {from} až {to}. Za {month} {value}.', ['metric' => $c['metric'], 'from' => $first['longLabel'], 'to' => $last['longLabel'], 'month' => $lastMonth, 'value' => $last['value'] === null ? t('bez dat') : allstat_number($last['value'])])) ?>">
                            <?php foreach ($c['months'] as $i => $e):
                                $cls = 'bar' . ($i === count($c['months']) - 1 ? ' is-last' : '') . ($e['partial'] ? ' is-partial' : '') . ($e['value'] === null ? ' is-empty' : '');
                                $tip = $e['longLabel'] . ': ' . ($e['value'] === null ? t('bez dat') : allstat_number($e['value'])) . ($e['partial'] ? ' (' . t('{days} z {expected} dní', ['days' => $e['days'], 'expected' => $e['expected']]) . ')' : '');
                            ?>
                                <span class="<?= $cls ?>"<?= $e['value'] === null ? '' : ' style="height: ' . max(4, round((float) $e['value'] / $max * 100, 1)) . '%"' ?> title="<?= h($tip) ?>"></span>
                            <?php endforeach; ?>
                        </div>
                        <div class="axis" aria-hidden="true"><span><?= h($first['label']) ?></span><b><?= h($last['label']) ?></b></div>
                        <div class="stats">
                            <div>
                                <span class="stat-label ellipsis"><?= h(t('Měsíc/měsíc')) ?></span>
                                <span class="stat-value <?= $tone($c['mom']) ?>"><?= h(allstat_growth_pct($c['mom'])) ?></span>
                            </div>
                            <div title="<?= h($yoyTitle($c)) ?>">
                                <span class="stat-label ellipsis"><?= h(t('Meziročně')) ?></span>
                                <span class="stat-value <?= $yClass ?>"><?= h($yText) ?></span>
                            </div>
                            <?php if ($c['followers']):
                                $f = $c['followers'];
                                $fPct = $f['pct'] === null ? (($f['diff'] >= 0 ? '+' : '−') . allstat_number(abs($f['diff']))) : allstat_growth_pct($f['pct']);
                                $fTitle = $f['label'] . ': ' . allstat_number($f['fromValue']) . ' (' . allstat_date_label($f['fromDate']) . ') → '
                                    . allstat_number($f['current']) . ' (' . allstat_date_label($f['toDate']) . '), ' . ($f['diff'] >= 0 ? '+' : '−') . allstat_number(abs($f['diff']))
                                    . ' ' . $f['since'] . '. ' . t('AllStat ukládá počet sledujících od 8. 7. 2026, u delšího období se proto růst počítá od prvního uloženého dne.');
                            ?>
                                <div title="<?= h($fTitle) ?>">
                                    <span class="stat-label ellipsis"><?= h($f['label']) ?></span>
                                    <span class="stat-value"><?= h(allstat_number($f['current'])) ?><small class="<?= $f['diff'] > 0 ? 'is-up' : ($f['diff'] < 0 ? 'is-down' : 'is-muted') ?>"><?= h($fPct) ?></small></span>
                                </div>
                            <?php elseif ($c['spend'] !== null): ?>
                                <div>
                                    <span class="stat-label ellipsis"><?= h(t('Útrata za {month}', ['month' => $lastMonth])) ?></span>
                                    <span class="stat-value"><?= h(trim(allstat_number($c['spend']) . ' ' . $c['spendCurrency'])) ?></span>
                                </div>
                            <?php elseif ($last['partial']): ?>
                                <div title="<?= h($partialTitle($last)) ?>">
                                    <span class="stat-label ellipsis"><?= h(t('Dny s daty za {month}', ['month' => $lastMonth])) ?></span>
                                    <span class="stat-value"><?= h(t('{days} z {expected}', ['days' => (int) $last['days'], 'expected' => (int) $last['expected']])) ?></span>
                                </div>
                            <?php elseif ($currentHasDays): ?>
                                <div title="<?= h(t('Rozběhnutý měsíc, data do {until}', ['until' => $currentUntil])) ?>">
                                    <span class="stat-label ellipsis"><?= h(t('{month} zatím', ['month' => $ucfirst($currentMonth)])) ?></span>
                                    <span class="stat-value"><?= $c['current']['value'] === null ? '–' : h(allstat_number($c['current']['value'])) ?></span>
                                </div>
                            <?php else: ?>
                                <div>
                                    <span class="stat-label ellipsis"><?= h(t('Maximum')) ?></span>
                                    <span class="stat-value"><?= h(allstat_number($c['max'])) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <section class="card card-clip" aria-labelledby="growth-table">
        <div class="card-head">
            <div>
                <h2 class="card-title" id="growth-table"><?= h(t('Měsíc po měsíci')) ?></h2>
                <p class="card-desc"><?= h(t('Součet hlavní metriky v měsíci, pod ním změna proti předchozímu měsíci přepočtená na den. Hvězdička značí měsíc s daty jen za část dní.')) ?></p>
            </div>
            <div class="legend"><span class="legend-item"><span class="swatch swatch-current" aria-hidden="true"></span><?= h(t('Poslední uzavřený měsíc')) ?></span></div>
        </div>
        <div class="table-wrap">
            <table class="table table-matrix" data-no-sort>
                <colgroup><col style="width: 248px"><col style="width: 104px"><?php foreach ($gw['months'] as $ym): ?><col><?php endforeach; ?></colgroup>
                <thead>
                    <tr>
                        <th scope="col"><?= h(t('Kanál')) ?></th>
                        <th scope="col" title="<?= h($growthHow) ?>"><?= h(t('Růst {period}', ['period' => $gw['n'] . ' m'])) ?></th>
                        <?php foreach ($gw['months'] as $ym): ?><th scope="col"<?= $ym === $lastYm ? ' class="is-current"' : '' ?>><?= h(allstat_growth_month_label($ym)) ?></th><?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($groups as $g): ?>
                        <tr class="table-group"><th scope="colgroup" colspan="<?= 2 + $gw['n'] ?>"><?= h($g['title']) ?></th></tr>
                        <?php foreach ($g['items'] as $c): $b = $growthBadge($c); ?>
                            <tr>
                                <th scope="row">
                                    <div class="cell">
                                        <?= allstat_source_tile($c['provider'], 'xs') ?>
                                        <div class="name-stack"><span class="name-main ellipsis"><?= h($c['name']) ?></span><span class="name-sub ellipsis"><?= h($accountOf($c)) ?></span></div>
                                    </div>
                                </th>
                                <td class="num <?= $b[4] ?>" title="<?= h($growthTitle($c)) ?>"><?= h($b[5]) ?></td>
                                <?php foreach ($c['months'] as $e): ?>
                                    <td class="num<?= $e['ym'] === $lastYm ? ' is-current' : '' ?>" title="<?= h($e['longLabel'] . ($e['partial'] ? ', ' . t('data za {days} z {expected} dní', ['days' => $e['days'], 'expected' => $e['expected']]) : '')) ?>">
                                        <span class="cell-value"><?= $e['value'] === null ? '–' : h(allstat_number($e['value'])) ?><?php if ($e['partial']): ?><sup>*</sup><?php endif; ?></span>
                                        <?php if ($e['value'] !== null && $e['mom'] !== null): ?><span class="cell-delta <?= $tone($e['mom']) ?>"><?= h(allstat_growth_pct($e['mom'])) ?></span><?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($currentHasDays): ?>
            <div class="card-foot"><?= allstat_icon('clock', 'icon-sm') ?><span><?= h(t('Rozběhnutý {month} (data do {until}) se do růstu nepočítá.', ['month' => $currentMonth, 'until' => $currentUntil])) ?></span></div>
        <?php endif; ?>
    </section>

    <?php if ($growthChart['series']): ?>
        <section class="card chart-wrap" aria-labelledby="growth-lines">
            <div class="chart-head">
                <div>
                    <h2 class="chart-title" id="growth-lines"><?= h(t('Vývoj v čase')) ?></h2>
                    <p class="card-desc"><?= h(t('Index: 100 = {base_name} období ({base}), přepočteno na den s daty. Kanály s různým měřítkem jsou tak na jedné ose. Kliknutím na kanál ho skryjete nebo zobrazíte.', ['base_name' => t($gw['k'] === 1 ? 'první měsíc' : 'typický měsíc (medián) prvních 3 měsíců'), 'base' => $gw['baseLabel']])) ?><?php if (array_filter($growthChart['series'], static fn (array $s): bool => $s['color'] === 'ads')): ?> <?= h(t('Reklama je zpočátku skrytá: kampaně skáčou o stovky procent a ostatní křivky by se slily u nuly.')) ?><?php endif; ?></p>
                </div>
                <div class="legend" aria-label="<?= h(t('Kanály v grafu')) ?>">
                    <?php foreach ($growthChart['series'] as $i => $s): $token = $lineToken($s['color']); ?>
                        <button type="button" class="legend-item" aria-pressed="<?= $s['color'] === 'ads' ? 'false' : 'true' ?>" data-growth-series="<?= (int) $i ?>" data-color-token="<?= h($token) ?>">
                            <span class="<?= $s['dashed'] ? 'swatch-dash' : 'swatch-line' ?>" style="<?= $s['dashed'] ? 'border-top-color' : 'background' ?>: var(<?= h($token) ?>)" aria-hidden="true"></span><?= h($s['name']) ?><?php if ($s['account'] !== ''): ?> <span class="name-sub"><?= h($s['account']) ?></span><?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="chart"><canvas id="growthChart" role="img" aria-label="<?= h(t('Vývoj hlavní metriky kanálů v čase jako index (100 = začátek období). Přesná čísla jsou v tabulce Měsíc po měsíci.')) ?>"></canvas></div>
        </section>
    <?php endif; ?>

    <details class="card note">
        <summary><?= allstat_icon('info', 'icon') ?><span><?= h(t('Jak se počítá růst')) ?></span><?= allstat_icon('chevron-down', 'note-chev') ?></summary>
        <div class="note-body">
            <p><strong><?= h(t('Růst')) ?></strong> <?= h($growthHow) ?><?php if ($gw['k'] > 1): ?> <?= h(t('Medián znamená, že jeden mimořádný měsíc (kampaň, výpadek) výsledek neotočí; u reklamy se počítá průměr, protože dny bez kampaně jsou skutečné nuly.')) ?><?php endif; ?></p>
            <p><?= h(t('Změnu do ±10 % ukazujeme jako „stabilní". Když jeden mimořádný měsíc otočí směr (průměr by ukázal opak) a změna typického měsíce není výrazná (pod 50 %), kanál je „kolísá" a směr se z dat nedá spolehlivě určit.')) ?> <?= t('<strong>Meziročně</strong> = typický měsíc {label}, tedy stejná sezóna bez vlivu léta nebo Vánoc; ukazuje se jen tam, kde máme data i za loňské měsíce (měsíce s nulovými daty před startem kanálu se nepočítají).', ['label' => h($gw['yoyLabel'])]) ?></p>
            <?php foreach (array_unique(array_filter(array_merge(array_column($growth['channels'], 'breakNote'), array_column($growth['channels'], 'yoyNote')))) as $note): ?>
                <p><?= h($note) ?></p>
            <?php endforeach; ?>
            <p><?= h(t('Počítají se jen uzavřené měsíce. Přepočet na den s daty srovná i měsíce různé délky a měsíce, kdy zdroj nesbíral data každý den (třeba Clarity nebo první měsíc po napojení). Dosah na Facebooku a Instagramu je součet denního dosahu, stejně jako v detailu kanálu. Růst sledujících vychází z denních snímků počtu sledujících, které AllStat ukládá od 8. 7. 2026 (YouTube od 15. 9. 2026).')) ?></p>
        </div>
    </details>
<?php endif; ?>
</div>
