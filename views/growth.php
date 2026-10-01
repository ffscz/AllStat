<?php
/**
 * Pohled „Růst kanálů" (úvodní stránka dashboardu). Vkládá index.php; data z allstat_get_channel_growth()
 * ($growth) a allstat_growth_chart_payload() ($growthChart). Karty a Srovnání růstu se přepínají
 * (assets/js/growth.js), tabulka Měsíc po měsíci je pod nimi vždy.
 */

// Jen jako součást index.php; přímé otevření šablony z URL nemá data (vypsalo by jen chyby).
if (!isset($growth, $growthChart, $data)) {
    http_response_code(404);
    exit;
}

$gw = $growth['window'];
$periodWord = $gw['n'] === 12 ? '12 měsíců' : ($gw['n'] === 6 ? '6 měsíců' : '3 měsíce');
$recentLabel = $gw['k'] === 1
    ? allstat_growth_month_label($gw['months'][$gw['n'] - 1], true)
    : allstat_growth_month_label($gw['months'][$gw['n'] - $gw['k']], true) . ' – ' . allstat_growth_month_label($gw['months'][$gw['n'] - 1], true);
$growthHow = $gw['k'] === 1
    ? 'Poslední měsíc (' . $recentLabel . ') proti prvnímu (' . $gw['baseLabel'] . '), přepočteno na den s daty.'
    : 'Typický měsíc (medián) posledních 3 měsíců (' . $recentLabel . ') proti typickému měsíci prvních 3 (' . $gw['baseLabel'] . '), přepočteno na den s daty.';
$currentLong = allstat_growth_month_label($gw['current'], true);
$currentUntil = (new DateTimeImmutable($gw['dataEnd']))->format('j. n.');
$currentHasDays = (new DateTimeImmutable($gw['today']))->format('j') !== '1';

// Text + třída + doplňující řádek růstu podle stavu (viz allstat_get_channel_growth):
// up/down = jednoznačná změna, stable = do ±10 %, volatile = směr otáčí jeden mimořádný měsíc,
// new = základ byl nulový, none = málo dat pro srovnání.
$growthView = static function (array $c) use ($periodWord): array {
    return match ($c['growthState']) {
        'up' => ['↑ ' . allstat_growth_pct($c['growth']), 'positive', 'za ' . $periodWord],
        'down' => ['↓ ' . allstat_growth_pct($c['growth']), 'negative', 'za ' . $periodWord],
        'stable' => ['→ stabilní', 'trend-none', allstat_growth_pct($c['growth']) . ' za ' . $periodWord],
        'volatile' => ['↕ kolísá', 'trend-none', 'výkyv: ' . ($c['spike'] ?? 'jeden měsíc')],
        'new' => ['nově', 'trend-none', 'za ' . $periodWord],
        default => ['málo dat', 'trend-none', 'za ' . $periodWord],
    };
};
// Tooltip k růstu: jak se počítá, u „kolísá" i proč nejde určit směr.
$growthTitle = static function (array $c) use ($growthHow): string {
    if ($c['growthState'] === 'volatile') {
        return 'Směr nejde spolehlivě určit: typický měsíc ' . allstat_growth_pct($c['growth']) . ', ale průměr by ukázal ' . allstat_growth_pct($c['meanGrowth'])
            . '. Výsledek otáčí jeden mimořádný měsíc (' . ($c['spike'] ?? '?') . '), typicky kampaň nebo výpadek. ' . $growthHow;
    }
    if ($c['growthState'] === 'stable') {
        return 'Změna typického měsíce je do ±10 %, proto „stabilní". ' . $growthHow;
    }

    return $growthHow;
};
$chStyle = static fn (array $c): string => '--c: var(--ch-' . preg_replace('/[^a-z0-9]/', '', $c['color']) . ')';
$momClass = static fn (?float $m): string => $m === null ? 'trend-none' : ($m >= 0 ? 'positive' : 'negative');
// Řádek „Sledující" jen když ho má aspoň jedna karta; ostatní karty pak dostanou prázdný řádek, ať čísla v řadě lícují.
$anyFollowers = (bool) array_filter($growth['channels'], static fn (array $c): bool => !empty($c['followers']));
// Meziročně (stejné 3 měsíce loni) – stejná logika řádku jako u sledujících.
$anyYoy = (bool) array_filter($growth['channels'], static fn (array $c): bool => !empty($c['yoy']));
$yoyView = static function (array $y): array {
    return match ($y['state']) {
        'up' => ['↑ ' . allstat_growth_pct($y['growth']), 'positive'],
        'down' => ['↓ ' . allstat_growth_pct($y['growth']), 'negative'],
        'stable' => ['stabilní ' . allstat_growth_pct($y['growth']), 'trend-none'],
        default => ['kolísá', 'trend-none'],
    };
};
$yoyTitle = static function (array $c) use ($gw): string {
    $t = 'Meziročně: typický měsíc ' . $gw['yoyLabel'] . ' (stejná sezóna), přepočteno na den s daty.';
    $y = $c['yoy'];
    if ($y && $y['state'] === 'volatile') {
        $t .= ' Směr nejde spolehlivě určit: typický měsíc ' . allstat_growth_pct($y['growth']) . ', průměr ' . allstat_growth_pct($y['mean']) . ', výkyv ' . ($y['spike'] ?? '?') . '.';
    }

    return $t;
};
// Meziroční číslo do věty nahoře, ať pokles/růst za celé okno nepůsobí bez kontextu sezóny.
$insightYoy = static function (array $c) use ($yoyTitle): string {
    $y = $c['yoy'];
    if (!$y) {
        return '';
    }
    [$text, $class] = match ($y['state']) {
        'up' => [allstat_growth_pct($y['growth']), 'positive'],
        'down' => [allstat_growth_pct($y['growth']), 'negative'],
        'stable' => ['stabilní ' . allstat_growth_pct($y['growth']), 'trend-none'],
        default => ['kolísá', 'trend-none'],
    };

    return ' <span class="growth-insight-yoy" title="' . h($yoyTitle($c)) . '">(meziročně <b class="' . $class . '">' . h($text) . '</b>)</span>';
};
$partialTitle =static fn (array $e): string => 'Data jen za ' . $e['days'] . ' z ' . $e['expected'] . ' dní. Změny se počítají z průměru na den, takže srovnání neúplný měsíc nezkresluje.';
?>
<div class="growth-root" data-growth>
<?php if (!$growth['channels']): ?>
    <section class="panel">
        <div class="panel-header"><h2>Růst kanálů</h2></div>
        <p class="panel-help">Tento web zatím nemá napojený žádný kanál s měsíčními daty (GA4, Search Console, sociální sítě, YouTube, reklamy). Napoj ho ve <a class="text-link" href="admin/sources.php?domain_id=<?= (int) $data['domain']['id'] ?>">Zdroje dat</a>.</p>
    </section>
<?php else: ?>
    <div class="growth-head">
        <div class="growth-head-text">
            <h2 class="growth-kicker">Růst kanálů · <?= h($gw['rangeLabel']) ?></h2>
            <?php if ($growth['best'] || $growth['worst']): $b = $growth['best']; $wo = $growth['worst']; ?>
                <p class="growth-insight">
                    <?php if ($b): ?><span>Nejrychleji roste <strong><?= h($b['name']) ?></strong><?php if ($b['account'] !== ''): ?> <small><?= h($b['account']) ?></small><?php endif; ?> <b class="positive"><?= h(allstat_growth_pct($b['growth'])) ?></b><?= $insightYoy($b) ?></span><?php endif; ?>
                    <?php if ($wo): ?><span>Nejvíc klesá <strong><?= h($wo['name']) ?></strong><?php if ($wo['account'] !== ''): ?> <small><?= h($wo['account']) ?></small><?php endif; ?> <b class="negative"><?= h(allstat_growth_pct($wo['growth'])) ?></b><?= $insightYoy($wo) ?></span><?php endif; ?>
                </p>
            <?php elseif (count($growth['channels']) > 1): ?>
                <p class="growth-insight"><span>Žádný kanál se za období výrazně nezměnil (typický měsíc do ±10 %, nebo jen jednorázové výkyvy).</span></p>
            <?php endif; ?>
        </div>
        <div class="seg-toggle growth-switch" role="tablist" aria-label="Zobrazení růstu">
            <button type="button" class="seg-btn is-active" role="tab" aria-selected="true" data-growth-tab="cards">Růst kanálů</button>
            <button type="button" class="seg-btn" role="tab" aria-selected="false" data-growth-tab="compare">Srovnání růstu</button>
        </div>
    </div>

    <section class="growth-cards" data-growth-panel="cards" aria-label="Růst kanálů">
        <?php foreach ($growth['channels'] as $c):
            [$gText, $gClass, $gSub] = $growthView($c);
            $last = $c['last'];
            $max = max(1.0, (float) $c['max']);
        ?>
            <a class="growth-card" href="<?= h($c['href']) ?>" style="<?= h($chStyle($c)) ?>" title="Otevřít detail: <?= h($c['name'] . ($c['account'] !== '' ? ', ' . $c['account'] : '')) ?>">
                <span class="growth-card-head">
                    <span class="growth-ic" aria-hidden="true"><?= allstat_provider_icon_svg($c['provider']) ?></span>
                    <span class="growth-card-title">
                        <strong><?= h($c['name']) ?></strong><?php if ($c['account'] !== ''): ?> <span class="growth-acct"><?= h($c['account']) ?></span><?php endif; ?>
                        <small><?= h($c['metric']) ?></small>
                    </span>
                    <span class="growth-swatch<?= $c['dashed'] ? ' is-dashed' : '' ?>" aria-hidden="true"></span>
                </span>
                <span class="growth-card-main">
                    <span>
                        <span class="growth-card-value"><?= $last['value'] === null ? '–' : h(allstat_number($last['value'])) ?></span>
                        <span class="growth-card-label"><?= h($last['longLabel']) ?><?php if ($last['partial']): ?> <span class="growth-partial" title="<?= h($partialTitle($last)) ?>">(<?= (int) $last['days'] ?> z <?= (int) $last['expected'] ?> dní)</span><?php endif; ?></span>
                    </span>
                    <span class="growth-card-growth" title="<?= h($growthTitle($c)) ?>">
                        <span class="<?= h($gClass) ?>"><?= h($gText) ?></span>
                        <small><?= h($gSub) ?></small>
                    </span>
                </span>
                <?php if ($anyYoy): ?>
                    <?php if ($c['yoy']): [$yText, $yClass] = $yoyView($c['yoy']); ?>
                        <span class="growth-yoy" title="<?= h($yoyTitle($c)) ?>">meziročně <strong class="<?= $yClass ?>"><?= h($yText) ?></strong></span>
                    <?php else: ?>
                        <span class="growth-yoy" title="Meziroční srovnání se ukáže, až budou data za stejné měsíce loni (<?= h($gw['yoyLabel']) ?>).">meziročně <strong class="trend-none">–</strong></span>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($anyFollowers): ?>
                    <?php if ($c['followers']):
                        $f = $c['followers'];
                        $fClass = $f['diff'] > 0 ? 'positive' : ($f['diff'] < 0 ? 'negative' : 'trend-none');
                        $fArrow = $f['diff'] > 0 ? '↑ ' : ($f['diff'] < 0 ? '↓ ' : '');
                        $fPct = $f['pct'] === null ? (($f['diff'] >= 0 ? '+' : '−') . allstat_number(abs($f['diff']))) : allstat_growth_pct($f['pct']);
                        $fTitle = $f['label'] . ': ' . allstat_number($f['fromValue']) . ' (' . (new DateTimeImmutable($f['fromDate']))->format('j. n. Y') . ') → '
                            . allstat_number($f['current']) . ' (' . (new DateTimeImmutable($f['toDate']))->format('j. n. Y') . '), ' . ($f['diff'] >= 0 ? '+' : '−') . allstat_number(abs($f['diff']))
                            . '. AllStat ukládá počet sledujících od 8. 7. 2026, u delšího období se proto růst počítá od prvního uloženého dne.';
                    ?>
                        <span class="growth-followers" title="<?= h($fTitle) ?>">
                            <span><?= h($f['label']) ?> <strong><?= h(allstat_number($f['current'])) ?></strong></span>
                            <span class="<?= $fClass ?>"><?= h($fArrow . $fPct) ?></span>
                            <small><?= h($f['since']) ?></small>
                        </span>
                    <?php else: ?>
                        <span class="growth-followers is-empty" aria-hidden="true"></span>
                    <?php endif; ?>
                <?php endif; ?>
                <span class="growth-bars" aria-hidden="true">
                    <?php foreach ($c['months'] as $i => $e):
                        $hPct = $e['value'] === null ? 0 : max(4, round((float) $e['value'] / $max * 100, 1));
                        $cls = 'growth-bar' . ($i === count($c['months']) - 1 ? ' is-last' : '') . ($e['partial'] ? ' is-partial' : '') . ($e['value'] === null ? ' is-empty' : '');
                        $tip = $e['label'] . ': ' . ($e['value'] === null ? 'bez dat' : allstat_number($e['value'])) . ($e['partial'] ? ' (' . $e['days'] . ' z ' . $e['expected'] . ' dní)' : '');
                    ?>
                        <span class="<?= $cls ?>" style="height: <?= $hPct ?>%" title="<?= h($tip) ?>"></span>
                    <?php endforeach; ?>
                </span>
                <span class="growth-card-foot">
                    <span>m/m <strong class="<?= $momClass($c['mom']) ?>"><?= h(allstat_growth_pct($c['mom'])) ?></strong></span>
                    <span class="growth-card-extra"><?= h($c['spendText'] ?? $c['range']) ?></span>
                </span>
            </a>
        <?php endforeach; ?>
    </section>

    <section class="panel growth-compare" data-growth-panel="compare" aria-label="Srovnání růstu" hidden>
        <div class="growth-compare-head">
            <div>
                <h2>Srovnání růstu</h2>
                <p class="panel-help" data-growth-note-rank>Kanály seřazené podle růstu hlavní metriky. <?= h($growthHow) ?> Změna do ±10 % je „stabilní" (šedě), „kolísá" = směr otáčí jeden mimořádný měsíc. Křivka vpravo ukazuje průběh po měsících.</p>
                <p class="panel-help" data-growth-note-lines hidden>Index: 100 = <?= $gw['k'] === 1 ? 'první měsíc' : 'typický měsíc (medián) prvních 3 měsíců' ?> období (<?= h($gw['baseLabel']) ?>), přepočteno na den s daty. Kanály s různým měřítkem jsou tak na jedné ose. Kliknutím na kanál ho skryjete nebo zobrazíte.<?php if (array_filter($growthChart['series'], static fn (array $s): bool => $s['color'] === 'ads')): ?> Reklama je zpočátku skrytá: kampaně skáčou o stovky procent a ostatní křivky by se slily u nuly.<?php endif; ?></p>
            </div>
            <div class="seg-toggle" role="tablist" aria-label="Typ srovnání">
                <button type="button" class="seg-btn is-active" role="tab" aria-selected="true" data-growth-view="rank">Žebříček růstu</button>
                <button type="button" class="seg-btn" role="tab" aria-selected="false" data-growth-view="lines">Vývoj v čase</button>
            </div>
        </div>

        <div class="growth-rank" data-growth-view-panel="rank">
            <?php
            // Na konec žebříčku kanály bez jednoznačného čísla: nejdřív „kolísá", pak nové a nakonec bez dat.
            $rankRows = $growth['ranked'];
            foreach (['volatile', 'new', 'none'] as $tailState) {
                foreach ($growth['channels'] as $c) {
                    if ($c['growthState'] === $tailState) { $rankRows[] = $c; }
                }
            }
            $gs = array_map(static fn (array $c): float => (float) $c['growth'], $growth['ranked']);
            $negMax = $gs ? max(0.0, -min($gs)) : 0.0;
            $posMax = $gs ? max(0.0, max($gs)) : 0.0;
            $tot = ($negMax + $posMax) ?: 1.0;
            $lp = $negMax > 0 ? 16.0 : 1.0;
            $usable = 100.0 - $lp - 16.0;
            $zero = $lp + $negMax / $tot * $usable;
            ?>
            <div class="growth-rank-row growth-rank-headrow" aria-hidden="true">
                <span>Kanál</span><span>Růst za <?= h($periodWord) ?></span><span class="growth-rank-spark">Průběh</span><span class="growth-rank-mom">m/m</span>
            </div>
            <?php foreach ($rankRows as $c):
                $last = $c['last'];
                $ok = in_array($c['growthState'], ['up', 'stable', 'down'], true);
                $gv = (float) $c['growth'];
                $wPct = $ok ? abs($gv) / $tot * $usable : 0.0;
                $left = $ok && $gv < 0 ? $zero - $wPct : $zero;
                // Barva popisku nese směr jen u jednoznačné změny; „stabilní" je šedě.
                $labelClass = $c['growthState'] === 'up' ? 'positive' : ($c['growthState'] === 'down' ? 'negative' : 'trend-none');
                $naText = match ($c['growthState']) {
                    'volatile' => 'kolísá: směr otáčí jeden mimořádný měsíc (' . ($c['spike'] ?? '?') . ')',
                    'new' => 'nově (na začátku období nulové)',
                    default => 'málo dat pro srovnání',
                };
            ?>
                <div class="growth-rank-row" style="<?= h($chStyle($c)) ?>">
                    <span class="growth-rank-name">
                        <span class="growth-ic growth-ic-sm" aria-hidden="true"><?= allstat_provider_icon_svg($c['provider']) ?></span>
                        <span class="growth-card-title">
                            <strong><?= h($c['name']) ?></strong><?php if ($c['account'] !== ''): ?> <span class="growth-acct"><?= h($c['account']) ?></span><?php endif; ?>
                            <small><?= h($c['short']) ?> · <?= $last['value'] === null ? '–' : h(allstat_number($last['value'])) ?> (<?= h(preg_replace('/ \d{4}$/', '', $last['longLabel'])) ?>)</small>
                        </span>
                    </span>
                    <span class="growth-rank-track" title="<?= h($growthTitle($c)) ?>">
                        <?php if ($ok): ?>
                            <span class="growth-rank-zero" style="left: <?= round($zero, 2) ?>%"></span>
                            <span class="growth-rank-bar<?= $c['growthState'] === 'stable' ? ' is-stable' : '' ?>" style="left: <?= round($left, 2) ?>%; width: <?= round(max(0.6, $wPct), 2) ?>%"></span>
                            <span class="growth-rank-label <?= $labelClass ?> <?= $gv >= 0 ? 'is-right' : 'is-left' ?>" style="left: <?= round($gv >= 0 ? $zero + $wPct : $zero - $wPct, 2) ?>%"><?= h(($c['growthState'] === 'stable' ? 'stabilní ' : '') . allstat_growth_pct($gv)) ?></span>
                        <?php else: ?>
                            <span class="growth-rank-na"><?= h($naText) ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="growth-rank-spark"><?= allstat_growth_spark_svg($c['spark']) ?></span>
                    <span class="growth-rank-mom <?= $momClass($c['mom']) ?>"><?= h(allstat_growth_pct($c['mom'])) ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="growth-lines" data-growth-view-panel="lines" hidden>
            <div class="growth-chips" aria-label="Kanály v grafu">
                <?php foreach ($growthChart['series'] as $i => $s): ?>
                    <button type="button" class="growth-chip" aria-pressed="<?= $s['color'] === 'ads' ? 'false' : 'true' ?>" data-growth-series="<?= (int) $i ?>" style="--c: var(--ch-<?= h(preg_replace('/[^a-z0-9]/', '', $s['color'])) ?>)">
                        <span class="growth-line-swatch<?= $s['dashed'] ? ' is-dashed' : '' ?>" aria-hidden="true"></span><?= h($s['name']) ?><?php if ($s['account'] !== ''): ?> <small><?= h($s['account']) ?></small><?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <div class="chart-frame growth-chart-frame"><canvas id="growthChart" role="img" aria-label="Vývoj hlavní metriky kanálů v čase jako index (100 = začátek období). Přesná čísla jsou v tabulce Měsíc po měsíci."></canvas></div>
        </div>
    </section>

    <section class="panel growth-table-panel" aria-label="Měsíc po měsíci">
        <div class="panel-header"><h2>Měsíc po měsíci</h2></div>
        <p class="panel-help">Měsíční součet hlavní metriky a změna proti předchozímu měsíci (z průměru na den). Hvězdička = měsíc s daty jen za část dní<?= $currentHasDays ? ', poslední sloupec je rozběhnutý měsíc (' . h($currentLong) . ' do ' . h($currentUntil) . '), do růstu se nepočítá' : '' ?>.</p>
        <div class="table-scroll">
            <table class="growth-table">
                <thead>
                    <tr>
                        <th>Kanál</th>
                        <th class="<?= $anyYoy ? '' : 'growth-cell-sep' ?>" title="<?= h($growthHow) ?>">Růst</th>
                        <?php if ($anyYoy): ?><th class="growth-cell-sep" title="Typický měsíc <?= h($gw['yoyLabel']) ?> (stejná sezóna)">Meziročně</th><?php endif; ?>
                        <?php foreach ($gw['months'] as $ym): ?><th><?= h(allstat_growth_month_label($ym)) ?></th><?php endforeach; ?>
                        <?php if ($currentHasDays): ?><th class="is-current" title="Rozběhnutý měsíc, data do <?= h($currentUntil) ?>"><?= h(allstat_growth_month_label($gw['current'])) ?> <small>zatím</small></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($growth['channels'] as $c):
                        [$gText, $gClass] = $growthView($c);
                        $cells = $c['months'];
                        if ($currentHasDays) { $cells[] = $c['current']; }
                        // Sloupec Růst: u „stabilní" i číslo (≈ +4 %), u „kolísá" měsíc výkyvu; řazení podle čísla.
                        $totalText = match ($c['growthState']) {
                            'up', 'down' => allstat_growth_pct($c['growth']),
                            'stable' => 'stabilní ' . allstat_growth_pct($c['growth']),
                            'volatile' => 'kolísá',
                            default => str_replace(['↑ ', '↓ '], '', $gText),
                        };
                    ?>
                        <tr style="<?= h($chStyle($c)) ?>">
                            <td>
                                <span class="growth-row-name"><span class="growth-dot<?= $c['dashed'] ? ' is-dashed' : '' ?>" aria-hidden="true"></span><strong><?= h($c['name']) ?></strong>
                                <small><?= $c['account'] !== '' ? h($c['account']) . ' · ' : '' ?><?= h($c['short']) ?></small></span>
                            </td>
                            <?php // Souhrnné sloupce hned za názvem: u 12 měsíců by na konci široké tabulky zůstaly za posuvníkem. ?>
                            <td class="growth-cell-total<?= $anyYoy ? '' : ' growth-cell-sep' ?>" data-sort="<?= $c['growth'] !== null ? h((string) round($c['growth'] * 1000)) : '' ?>" title="<?= h($growthTitle($c)) ?>"><span class="<?= h($gClass) ?>"><?= h($totalText) ?></span></td>
                            <?php if ($anyYoy): [$yText, $yClass] = $c['yoy'] ? $yoyView($c['yoy']) : ['–', 'trend-none']; ?>
                                <td class="growth-cell-total growth-cell-sep" data-sort="<?= $c['yoy'] && $c['yoy']['growth'] !== null ? h((string) round($c['yoy']['growth'] * 1000)) : '' ?>" title="<?= h($c['yoy'] ? $yoyTitle($c) : 'Zatím nemáme data za stejné měsíce loni.') ?>"><span class="<?= h($yClass) ?>"><?= h(str_replace(['↑ ', '↓ '], '', $yText)) ?></span></td>
                            <?php endif; ?>
                            <?php foreach ($cells as $e):
                                $mom = $e['mom'];
                                $tint = '';
                                if ($mom !== null) {
                                    // Jemné podbarvení podle síly změny (max 18 %), barva nese jen směr; číslo se znaménkem ho vždy doplňuje.
                                    $alpha = round(min(abs($mom) / 0.5, 1) * 18, 1);
                                    $tint = 'background: color-mix(in srgb, var(' . ($mom >= 0 ? '--green' : '--rose') . ') ' . $alpha . '%, transparent);';
                                }
                            ?>
                                <td class="<?= $e['isCurrent'] ? 'is-current' : '' ?>" data-sort="<?= $e['value'] === null ? '' : h((string) round((float) $e['value'])) ?>" style="<?= $tint ?>">
                                    <span class="growth-cell-val"><?= $e['value'] === null ? '–' : h(allstat_number($e['value'])) ?><?php if ($e['partial']): ?><sup class="growth-partial" title="<?= h($partialTitle($e)) ?>">*</sup><?php endif; ?></span>
                                    <span class="growth-cell-mom <?= $momClass($mom) ?>"><?= h(allstat_growth_pct($mom)) ?></span>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php // Stejný rámeček jako „Poznámka k datům z API" v detailu FB/IG (panel + nápověda), jen přes celou šířku. ?>
    <section class="analytics-grid growth-note" aria-label="Jak se počítá růst">
        <article class="panel panel-wide">
            <p class="panel-help"><strong>Jak se počítá růst:</strong> <?= h($growthHow) ?><?php if ($gw['k'] > 1): ?> Medián znamená, že jeden mimořádný měsíc (kampaň, výpadek) výsledek neotočí; u reklamy se počítá průměr, protože dny bez kampaně jsou skutečné nuly.<?php endif; ?> Změnu do ±10 % ukazujeme jako „stabilní". Když jeden mimořádný měsíc otočí směr (průměr by ukázal opak) a změna typického měsíce není výrazná (pod 50 %), kanál je „kolísá" a směr se z dat nedá spolehlivě určit. <strong>Meziročně</strong> = typický měsíc <?= h($gw['yoyLabel']) ?>, tedy stejná sezóna bez vlivu léta nebo Vánoc; ukazuje se jen tam, kde máme data i za loňské měsíce (měsíce s nulovými daty před startem kanálu se nepočítají). Počítají se jen uzavřené měsíce. Přepočet na den s daty srovná i měsíce různé délky a měsíce, kdy zdroj nesbíral data každý den (třeba Clarity nebo první měsíc po napojení). Dosah na Facebooku a Instagramu je součet denního dosahu, stejně jako v detailu kanálu. Růst sledujících vychází z denních snímků počtu sledujících, které AllStat ukládá od 8. 7. 2026 (YouTube od 15. 9. 2026).</p>
        </article>
    </section>
<?php endif; ?>
</div>
