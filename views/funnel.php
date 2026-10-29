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

require_once __DIR__ . '/_ui.php';

$fr = $funnelReport;
$fSteps = $fr['steps'];
$fBreak = $fr['breakdown'];
$fWarnings = $fr['warnings'];
$fIsAdmin = ($user['role'] ?? '') === 'admin';
$fDomainId = (int) $data['domain']['id'];
$fmtDate = static fn (string $iso): string => allstat_date_label($iso);
// Podíl z počtů událostí (může přesáhnout 100 %), null = nešlo spočítat (dělitel 0).
$fPct = static fn (?float $fraction): string => $fraction === null ? t('bez dat') : allstat_percent($fraction * 100, abs($fraction) >= 10 ? 0 : 1);
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
$dimensionHead = ['channel' => t('Kanál'), 'source_medium' => t('Zdroj / médium'), 'campaign' => t('Kampaň')][$fBreak['key']] ?? t('Skupina');
$dimensionOf = ['channel' => t('kanálů'), 'source_medium' => t('dvojic zdroj / médium'), 'campaign' => t('kampaní')][$fBreak['key']] ?? t('skupin');
$coveredLate = $fBreak['available'] && $fBreak['coveredFrom'] !== null && $fBreak['coveredFrom'] > $fr['range']['start'];
$fFirst = $fCount ? $fSteps[0] : null;
$fLast = $fCount ? $fSteps[$fCount - 1] : null;
$fMuted = static fn (string $text): string => '<span class="is-muted">' . h($text) . '</span>';
?>
<div class="section-head" data-funnel>
    <p class="section-desc">
        <?= h($fmtDate($fr['range']['start'])) ?> – <?= h($fmtDate($fr['range']['end'])) ?> · <?= h(tn($fCount, '{count} krok', '{count} kroky', '{count} kroků')) ?> · <?= h(t('rozpad: {value}', ['value' => $fBreak['label']])) ?>
    </p>
    <?php if (!$funnel['is_active']): ?><span class="status tone-flat" title="<?= h(t('Vypnutý trychtýř vidíš jen ty jako administrátor. Rozpad podle zdrojů se pro něj nestahuje.')) ?>"><span class="dot"></span><?= h(t('Vypnutý')) ?></span><?php endif; ?>
    <?php if ($fIsAdmin): ?>
        <a class="btn btn-secondary btn-sm" href="admin/funnels.php?domain_id=<?= $fDomainId ?>&amp;edit=<?= (int) $funnel['id'] ?>"><?= allstat_icon('pencil', 'icon-sm') ?><span><?= h(t('Upravit trychtýř')) ?></span></a>
    <?php endif; ?>
</div>

<div class="grid grid-kpis">
    <div class="card kpi" title="<?= h(t('Počet událostí posledního kroku ku počtu událostí prvního kroku')) ?>">
        <span class="metric-label"><?= h(t('Celková konverze')) ?></span>
        <span class="metric-value num"><?= h($fPct($fr['overall'])) ?></span>
        <span class="metric-note ellipsis"><?= h($firstLabel) ?> → <?= h($lastLabel) ?></span>
    </div>
    <?php foreach ($fCount > 1 ? [$fFirst, $fLast] : ($fFirst ? [$fFirst] : []) as $edge): ?>
    <div class="card kpi">
        <span class="metric-label ellipsis" title="<?= h($edge['event']) ?>"><?= h($edge['label']) ?></span>
        <span class="metric-value num"><?= h(allstat_number($edge['count'])) ?></span>
        <span class="metric-note"><?= h(t('událostí')) ?></span>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($fWarnings): ?>
<div class="callout tone-warn" role="status">
    <?= allstat_icon('info', 'icon-sm') ?>
    <div>
        <strong><?= h(t('Pozor na měření a čtení čísel')) ?></strong>
        <ul class="bullets">
            <?php foreach ($fWarnings as $warning): ?><li><?= h($warning['message']) ?></li><?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<section class="card">
    <?= allstat_ui_card_head(t('Kroky trychtýře'), t('GA4 eventy'), t('Šířka pruhu je počet událostí vůči nejvyššímu kroku. <strong>Z předchozího</strong> a <strong>z prvního</strong> jsou podíly počtů událostí, <strong>úbytek</strong> je rozdíl proti předchozímu kroku. Počítají se <strong>události, ne unikátní lidé</strong>, takže pozdější krok může mít víc než 100 %.')) ?>
    <div class="card-body">
        <?php if (!$fHasData): ?>
            <p class="card-desc"><?= h(t('Za zvolené období nepřišel žádný event z tohoto trychtýře. Zkus delší období, případně zkontroluj měření eventů.')) ?></p>
        <?php endif; ?>
        <ol class="meters">
            <?php foreach ($fSteps as $i => $step):
                $width = $fMax > 0 ? max($step['count'] > 0 ? 2 : 0, round($step['count'] / $fMax * 100, 1)) : 0;
                $isGoal = $i === $fCount - 1 && $i > 0;
                $dropPct = ($i > 0 && $fSteps[$i - 1]['count'] > 0) ? $step['dropOff'] / $fSteps[$i - 1]['count'] : null;
                ?>
                <li class="meter">
                    <div class="meter-top">
                        <span class="name-stack">
                            <span class="name-main"><?= $i + 1 ?>. <?= h($step['label']) ?>
                                <?php if ($i === $bottleneck): ?> <span class="badge tone-warn" title="<?= h(t('Největší relativní úbytek mezi sousedními kroky')) ?>"><?= h(t('největší úbytek')) ?></span><?php endif; ?>
                            </span>
                            <span class="name-sub mono"><?= h($step['event']) ?></span>
                        </span>
                        <span class="row-end"><span class="row-value num"><?= h(allstat_number($step['count'])) ?></span><span class="row-share"><?= h(t('událostí')) ?></span></span>
                    </div>
                    <div class="track" aria-hidden="true"><div class="track-fill<?= $isGoal ? ' is-up' : '' ?>" style="width: <?= $width ?>%"></div></div>
                    <dl class="stats">
                        <div>
                            <dt class="stat-label"><?= h(t('z předchozího')) ?></dt>
                            <dd class="stat-value num"<?= $step['fromPrev'] !== null && $step['fromPrev'] > 1 ? ' title="' . h(t('Víc událostí než v předchozím kroku (počítají se události, ne lidé)')) . '"' : '' ?>><?= $i === 0 ? $fMuted(t('vstup')) : h($fPct($step['fromPrev'])) ?></dd>
                        </div>
                        <div>
                            <dt class="stat-label"><?= h(t('z prvního')) ?></dt>
                            <dd class="stat-value num"><?= $i === 0 ? '100 %' : h($fPct($step['fromFirst'])) ?></dd>
                        </div>
                        <div>
                            <dt class="stat-label"><?= h(t('úbytek')) ?></dt>
                            <dd class="stat-value num"><?php if ($i === 0): ?><?= $fMuted('–') ?><?php elseif ($step['dropOff'] > 0): ?><span class="is-down">−<?= h(allstat_number($step['dropOff'])) ?></span><?= $dropPct !== null ? '<small>' . h(allstat_percent($dropPct * 100, 1)) . '</small>' : '' ?><?php else: ?><?= $fMuted(t('žádný')) ?><?php endif; ?></dd>
                        </div>
                    </dl>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
</section>

<?php if (count($funnelChart['labels']) > 1): ?>
<section class="card">
    <?= allstat_ui_card_head(t('Vývoj kroků v čase'), $funnelChart['granularity'] === 'week' ? t('po týdnech') : t('po dnech'), h(t('Počet událostí každého kroku v čase. Kliknutím na položku legendy krok skryješ nebo ukážeš.')) . ($funnelChart['granularity'] === 'week' ? ' ' . h(t('Týdny začínají v pondělí, první a poslední týden mohou být neúplné.')) : '')) ?>
    <div class="card-body">
        <div class="chart"><canvas id="funnelChart" role="img" aria-label="<?= h(t('Vývoj počtu událostí jednotlivých kroků trychtýře v čase')) ?>"></canvas></div>
    </div>
</section>
<?php endif; ?>

<?php
$fSeg = '<div class="seg" role="group" aria-label="' . h(t('Rozpad trychtýře')) . '">';
foreach (allstat_funnel_breakdowns() as $key => $label) {
    $fSeg .= '<a class="seg-item" href="' . h($fUrl(['breakdown' => $key])) . '"' . ($key === $fBreak['key'] ? ' aria-current="true"' : '') . '>' . h($label) . '</a>';
}
$fSeg .= '</div>';
?>
<section class="card">
    <?= allstat_ui_card_head(t('Rozpad podle: {value}', ['value' => $fBreak['label']]), '', t('Kolik událostí každého kroku přišlo z jednotlivých {dimension}. Řazeno podle prvního kroku, <strong>celková konverze</strong> je poslední krok ku prvnímu. Zdroj a kampaň se berou ze zdroje <em>relace</em> (GA4), ne z posledního kliknutí.', ['dimension' => h($dimensionOf)]), $fSeg) ?>
    <div class="card-body">
        <?php if (!$fBreak['available']): ?>
            <div class="callout tone-warn"><p><?= h(t('Rozpad podle zdrojů zatím nemáme. Plní se od příští synchronizace GA4 po vytvoření trychtýře')) ?><?php if ($fIsAdmin): ?>; <?= t('starší období jde doplnit přes „Stáhnout historii“ u zdroje GA4 ve {link}', ['link' => '<a href="admin/sources.php?domain_id=' . $fDomainId . '">' . h(t('Zdrojích dat')) . '</a>']) ?><?php endif; ?>. <?= h(t('Počty kroků výše jsou přesto kompletní.')) ?></p></div>
        <?php else: ?>
            <?php if ($coveredLate): ?>
                <p class="callout tone-warn"><?= h(t('Rozpad je k dispozici od {date}, dřívější část zvoleného období v něm chybí. Součty řádků proto mohou být nižší než počty kroků výše.', ['date' => $fmtDate($fBreak['coveredFrom'])])) ?></p>
            <?php endif; ?>
            <div class="table-wrap">
                <table class="table table-plain<?= $fCount > 4 ? ' table-wide' : '' ?>">
                    <thead>
                        <tr>
                            <th scope="col"><?= h($dimensionHead) ?></th>
                            <?php foreach ($fSteps as $step): ?><th scope="col" title="<?= h($step['event']) ?>"><?= h($step['label']) ?></th><?php endforeach; ?>
                            <th scope="col" title="<?= h(t('Poslední krok ku prvnímu')) ?>"><?= h(t('Celková konverze')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fBreak['rows'] as $row): ?>
                            <tr>
                                <td class="ellipsis" title="<?= h($row['label']) ?>"><?= h($row['label']) ?></td>
                                <?php foreach ($row['counts'] as $count): ?><td class="num"><?= $count > 0 ? h(allstat_number($count)) : $fMuted('0') ?></td><?php endforeach; ?>
                                <td class="num is-strong"><?= $row['overall'] === null ? $fMuted(t('bez dat')) : h($fPct($row['overall'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
