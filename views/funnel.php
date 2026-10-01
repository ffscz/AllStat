<?php
/**
 * Pohled „Trychtýř" (index.php?view=funnel&funnel_id=N). Vkládá index.php; data z allstat_funnel_report() ($funnelReport),
 * trychtýř ($funnel) a payload grafu ($funnelChart, graf kreslí assets/js/funnel.js). Pohled je jen ke čtení pro všechny
 * uživatele; odkaz na úpravu vidí jen administrátor.
 *
 * Čísla jsou počty událostí (ne unikátní lidé), proto může být pozdější krok vyšší než předchozí.
 */

// Jen jako součást index.php; přímé otevření šablony z URL nemá data (vypsalo by jen chyby).
if (!isset($funnel, $funnelReport, $funnelChart, $data)) {
    http_response_code(404);
    exit;
}

$fr = $funnelReport;
$fSteps = $fr['steps'];
$fBreak = $fr['breakdown'];
$fWarnings = $fr['warnings'];
$fIsAdmin = ($user['role'] ?? '') === 'admin';
$fDomainId = (int) $data['domain']['id'];
$fmtDate = static fn (string $iso): string => (new DateTimeImmutable($iso))->format('j. n. Y');
// Podíl z počtů událostí (může přesáhnout 100 %), null = nešlo spočítat (dělitel 0).
$fPct = static fn (?float $fraction): string => $fraction === null ? 'bez dat' : allstat_percent($fraction * 100, abs($fraction) >= 10 ? 0 : 1);
$fCount = count($fSteps);
$fMax = 0;
foreach ($fSteps as $step) {
    $fMax = max($fMax, (int) $step['count']);
}
$fHasData = $fMax > 0;

// Největší relativní úbytek mezi sousedními kroky = úzké hrdlo (jen u 3+ kroků a když ztráta není zanedbatelná).
$bottleneck = -1;
if ($fCount >= 3) {
    $lowest = 0.95;
    foreach ($fSteps as $i => $step) {
        if ($i > 0 && $step['fromPrev'] !== null && $step['fromPrev'] < $lowest) {
            $lowest = $step['fromPrev'];
            $bottleneck = $i;
        }
    }
}

$fUrl = static fn (array $extra = []): string => '?' . http_build_query(array_merge([
    'view' => 'funnel',
    'funnel_id' => (int) $funnel['id'],
    'domain_id' => $fDomainId,
    'start' => $fr['range']['start'],
    'end' => $fr['range']['end'],
], $extra));
$firstLabel = $fCount ? $fSteps[0]['label'] : '';
$lastLabel = $fCount ? $fSteps[$fCount - 1]['label'] : '';
$dimensionHead = ['channel' => 'Kanál', 'source_medium' => 'Zdroj / médium', 'campaign' => 'Kampaň'][$fBreak['key']] ?? 'Skupina';
$dimensionOf = ['channel' => 'kanálů', 'source_medium' => 'dvojic zdroj / médium', 'campaign' => 'kampaní'][$fBreak['key']] ?? 'skupin';
$coveredLate = $fBreak['available'] && $fBreak['coveredFrom'] !== null && $fBreak['coveredFrom'] > $fr['range']['start'];
?>
<section class="funnel-root" data-funnel aria-label="Trychtýř">
    <div class="panel funnel-head">
        <div class="funnel-head-main">
            <p class="funnel-kicker">Konverzní trychtýř</p>
            <h2><?= h($funnel['name']) ?></h2>
            <p class="funnel-meta">
                <?= h($fmtDate($fr['range']['start'])) ?> – <?= h($fmtDate($fr['range']['end'])) ?> · <?= $fCount ?> <?= $fCount === 1 ? 'krok' : ($fCount < 5 ? 'kroky' : 'kroků') ?> · rozpad: <?= h($fBreak['label']) ?>
                <?php if (!$funnel['is_active']): ?> <span class="status-badge status-info" title="Vypnutý trychtýř vidíš jen ty jako administrátor. Rozpad podle zdrojů se pro něj nestahuje.">Vypnutý</span><?php endif; ?>
            </p>
            <?php if ($fIsAdmin): ?>
                <a class="text-link" href="admin/funnels.php?domain_id=<?= $fDomainId ?>&amp;edit=<?= (int) $funnel['id'] ?>">Upravit trychtýř <i data-lucide="pencil"></i></a>
            <?php endif; ?>
        </div>
        <div class="funnel-total" title="Počet událostí posledního kroku ku počtu událostí prvního kroku">
            <strong><?= h($fPct($fr['overall'])) ?></strong>
            <span>celková konverze<br><small><?= h($firstLabel) ?> → <?= h($lastLabel) ?></small></span>
        </div>
    </div>

    <?php if ($fWarnings): ?>
    <div class="notice notice-warn funnel-warnings" role="status">
        <strong>Pozor na měření a čtení čísel</strong>
        <ul>
            <?php foreach ($fWarnings as $warning): ?><li><?= h($warning['message']) ?></li><?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <article class="panel">
        <div class="panel-header"><h2>Kroky trychtýře <span>(GA4 eventy)</span></h2></div>
        <p class="panel-help">Šířka pruhu je počet událostí vůči nejvyššímu kroku. <strong>Z předchozího</strong> a <strong>z prvního</strong> jsou podíly počtů událostí, <strong>úbytek</strong> je rozdíl proti předchozímu kroku. Počítají se <strong>události, ne unikátní lidé</strong>, takže pozdější krok může mít víc než 100 %.</p>
        <?php if (!$fHasData): ?>
            <p class="table-muted">Za zvolené období nepřišel žádný event z tohoto trychtýře. Zkus delší období, případně zkontroluj měření eventů.</p>
        <?php endif; ?>
        <ol class="funnel-bars">
            <?php foreach ($fSteps as $i => $step):
                $width = $fMax > 0 ? max($step['count'] > 0 ? 2 : 0, round($step['count'] / $fMax * 100, 1)) : 0;
                $isLast = $i === $fCount - 1;
                $dropPct = ($i > 0 && $fSteps[$i - 1]['count'] > 0) ? $step['dropOff'] / $fSteps[$i - 1]['count'] : null;
                ?>
                <li class="funnel-bar">
                    <div class="funnel-bar-name">
                        <span class="funnel-bar-n"><?= $i + 1 ?></span>
                        <span>
                            <strong><?= h($step['label']) ?></strong>
                            <small><?= h($step['event']) ?></small>
                            <?php if ($i === $bottleneck): ?><span class="status-badge status-warning" title="Největší relativní úbytek mezi sousedními kroky">největší úbytek</span><?php endif; ?>
                        </span>
                    </div>
                    <div class="funnel-bar-track" aria-hidden="true"><div class="funnel-bar-fill<?= $isLast && $i > 0 ? ' is-goal' : '' ?>" style="width: <?= $width ?>%;"></div></div>
                    <div class="funnel-bar-count"><strong><?= h(allstat_number($step['count'])) ?></strong> <small>událostí</small></div>
                    <dl class="funnel-bar-stats">
                        <div>
                            <dt>z předchozího</dt>
                            <dd<?= $step['fromPrev'] !== null && $step['fromPrev'] > 1 ? ' title="Víc událostí než v předchozím kroku (počítají se události, ne lidé)"' : '' ?>><?= $i === 0 ? '<span class="table-muted">vstup</span>' : h($fPct($step['fromPrev'])) ?></dd>
                        </div>
                        <div>
                            <dt>z prvního</dt>
                            <dd><?= $i === 0 ? '100 %' : h($fPct($step['fromFirst'])) ?></dd>
                        </div>
                        <div>
                            <dt>úbytek</dt>
                            <dd><?php if ($i === 0): ?><span class="table-muted">–</span><?php elseif ($step['dropOff'] > 0): ?><span class="negative">−<?= h(allstat_number($step['dropOff'])) ?></span><?= $dropPct !== null ? ' <small>(' . h(allstat_percent($dropPct * 100, 1)) . ')</small>' : '' ?><?php else: ?><span class="table-muted">žádný</span><?php endif; ?></dd>
                        </div>
                    </dl>
                </li>
            <?php endforeach; ?>
        </ol>
    </article>

    <?php if (count($funnelChart['labels']) > 1): ?>
    <article class="panel">
        <div class="panel-header">
            <h2>Vývoj kroků v čase <span>(<?= $funnelChart['granularity'] === 'week' ? 'po týdnech' : 'po dnech' ?>)</span></h2>
        </div>
        <p class="panel-help">Počet událostí každého kroku v čase. Kliknutím na položku legendy krok skryješ nebo ukážeš.<?= $funnelChart['granularity'] === 'week' ? ' Týdny začínají v pondělí, první a poslední týden mohou být neúplné.' : '' ?></p>
        <div class="chart-frame chart-frame-line"><canvas id="funnelChart" role="img" aria-label="Vývoj počtu událostí jednotlivých kroků trychtýře v čase"></canvas></div>
    </article>
    <?php endif; ?>

    <article class="panel table-panel">
        <div class="panel-header">
            <h2>Rozpad podle: <?= h($fBreak['label']) ?></h2>
            <div class="seg-toggle" role="group" aria-label="Rozpad trychtýře">
                <?php foreach (allstat_funnel_breakdowns() as $key => $label): ?>
                    <a class="seg-btn<?= $key === $fBreak['key'] ? ' is-active' : '' ?>" href="<?= h($fUrl(['breakdown' => $key])) ?>"<?= $key === $fBreak['key'] ? ' aria-current="true"' : '' ?>><?= h($label) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <p class="panel-help">Kolik událostí každého kroku přišlo z jednotlivých <?= h($dimensionOf) ?>. Řazeno podle prvního kroku, <strong>celková konverze</strong> je poslední krok ku prvnímu. Zdroj a kampaň se berou ze zdroje <em>relace</em> (GA4), ne z posledního kliknutí.</p>
        <?php if (!$fBreak['available']): ?>
            <p class="notice notice-warn">Rozpad podle zdrojů zatím nemáme. Plní se od příští synchronizace GA4 po vytvoření trychtýře<?php if ($fIsAdmin): ?>; starší období jde doplnit přes „Stáhnout historii“ u zdroje GA4 ve <a href="admin/sources.php?domain_id=<?= $fDomainId ?>">Zdrojích dat</a><?php endif; ?>. Počty kroků výše jsou přesto kompletní.</p>
        <?php else: ?>
            <?php if ($coveredLate): ?>
                <p class="notice notice-warn">Rozpad je k dispozici od <?= h($fmtDate($fBreak['coveredFrom'])) ?>, dřívější část zvoleného období v něm chybí. Součty řádků proto mohou být nižší než počty kroků výše.</p>
            <?php endif; ?>
            <div class="table-scroll">
                <table class="funnel-table">
                    <thead>
                        <tr>
                            <th><?= h($dimensionHead) ?></th>
                            <?php foreach ($fSteps as $step): ?><th class="num" title="<?= h($step['event']) ?>"><?= h($step['label']) ?></th><?php endforeach; ?>
                            <th class="num" title="Poslední krok ku prvnímu">Celková konverze</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fBreak['rows'] as $row): ?>
                            <tr>
                                <td><?= h($row['label']) ?></td>
                                <?php foreach ($row['counts'] as $count): ?><td class="num"><?= $count > 0 ? h(allstat_number($count)) : '<span class="table-muted">0</span>' ?></td><?php endforeach; ?>
                                <td class="num"><strong><?= $row['overall'] === null ? '<span class="table-muted">bez dat</span>' : h($fPct($row['overall'])) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>
</section>
