<?php
/**
 * Sdílený partial „Stories": dočasné příspěvky (post_type='story') se souhrnem + tabulkou.
 * Instagram přidává metriky (dosah / zobrazení / odpovědi / navigace); Facebook je většinou bez
 * metrik, pak se sloupce i souhrn skryjí. V rozsahu z index.php: $socialStories + h().
 */
if (!isset($socialStories)) { return; }
$stHasMetrics = !empty($socialStories['totals']['hasMetrics']);
$stT = $socialStories['totals'] ?? [];
?>
<section class="analytics-grid" aria-label="Stories">
    <article class="panel panel-wide">
        <div class="panel-header"><h2>Stories <span>(<?= h(t('{count} zachyceno', ['count' => (int) ($socialStories['count'] ?? 0)])) ?>)</span></h2></div>
        <p class="panel-help"><?= t('Stories jsou dočasné (mizí po ~24 h) a přes API se vrací jen ty <strong>právě aktivní</strong>, zachytí se tedy jen ty, které byly živé ve chvíli synchronizace (pomáhá častější sync). Nezapočítávají se do „počtu příspěvků" ani frekvence.') ?><?= $stHasMetrics ? ' ' . t('<strong>Navigace</strong> = kolikrát lidé mezi stories posunuli nebo odešli; vyšší číslo = větší úbytek pozornosti.') : '' ?></p>
        <?php if ($stHasMetrics): ?>
        <div class="kpi-grid" style="margin-bottom: 14px;">
            <article class="kpi-card kpi-blue"><div class="kpi-head"><span class="kpi-icon"><i data-lucide="eye"></i></span><span><strong><?= h(t('Dosah')) ?></strong> <small>(<?= h(t('součet')) ?>)</small></span></div><div class="kpi-value"><?= h($stT['reachLabel'] ?? '0') ?></div></article>
            <article class="kpi-card kpi-cyan"><div class="kpi-head"><span class="kpi-icon"><i data-lucide="play"></i></span><span><strong><?= h(t('Zobrazení')) ?></strong></span></div><div class="kpi-value"><?= h($stT['viewsLabel'] ?? '0') ?></div></article>
            <article class="kpi-card kpi-violet"><div class="kpi-head"><span class="kpi-icon"><i data-lucide="message-circle"></i></span><span><strong><?= h(t('Odpovědi')) ?></strong></span></div><div class="kpi-value"><?= h($stT['repliesLabel'] ?? '0') ?></div></article>
            <article class="kpi-card kpi-orange"><div class="kpi-head"><span class="kpi-icon"><i data-lucide="chevrons-right"></i></span><span><strong><?= h(t('Navigace')) ?></strong> <small>(<?= h(t('posuny / odchody')) ?>)</small></span></div><div class="kpi-value"><?= h($stT['navigationLabel'] ?? '0') ?></div></article>
        </div>
        <?php endif; ?>
        <?php if (!empty($socialStories['rows'])): ?>
        <div class="table-scroll">
            <table>
                <thead><tr><th><?= h(t('Datum a čas')) ?></th><th>Story</th><?php if ($stHasMetrics): ?><th><?= h(t('Dosah')) ?></th><th><?= h(t('Zobrazení')) ?></th><th><?= h(t('Odpovědi')) ?></th><th><?= h(t('Navigace')) ?></th><?php endif; ?></tr></thead>
                <tbody>
                    <?php foreach ($socialStories['rows'] as $st): ?>
                        <tr>
                            <td><?= h($st['date']) ?></td>
                            <td><?php if (($st['permalink'] ?? '') !== ''): ?><a class="text-link" href="<?= h($st['permalink']) ?>" target="_blank" rel="noopener"><?= h($st['message']) ?></a><?php else: ?><?= h($st['message']) ?><?php endif; ?></td>
                            <?php if ($stHasMetrics): ?>
                            <td><?= h($st['reachLabel'] ?? '—') ?></td>
                            <td><strong><?= h($st['viewsLabel'] ?? '—') ?></strong></td>
                            <td><?= h($st['repliesLabel'] ?? '—') ?></td>
                            <td><?= h($st['navigationLabel'] ?? '—') ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="table-muted"><?= h(t('Zatím nebyly zachyceny žádné Stories. Aby se nějaká objevila, musí synchronizace proběhnout, dokud je Story živá (do ~24 h od zveřejnění); pomáhá častější sync nebo sync hned po zveřejnění.')) ?></p>
        <?php endif; ?>
    </article>
</section>
