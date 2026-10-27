<?php
/**
 * Sdílený partial „Stories“: dočasné příspěvky (post_type='story') se souhrnem + tabulkou.
 * Instagram přidává metriky (dosah / zobrazení / odpovědi / navigace); Facebook je většinou bez
 * metrik, pak se sloupce i souhrn skryjí. V rozsahu z index.php: $socialStories + h().
 */
if (!isset($socialStories)) { return; }
$stHasMetrics = !empty($socialStories['totals']['hasMetrics']);
$stT = $socialStories['totals'] ?? [];
?>
<section class="card">
    <?= allstat_ui_card_head('Stories', t('{count} zachyceno', ['count' => (int) ($socialStories['count'] ?? 0)]), t('Stories jsou dočasné (mizí po ~24 h) a přes API se vrací jen ty <strong>právě aktivní</strong>, zachytí se tedy jen ty, které byly živé ve chvíli synchronizace (pomáhá častější sync). Nezapočítávají se do „počtu příspěvků" ani frekvence.') . ($stHasMetrics ? ' ' . t('<strong>Navigace</strong> = kolikrát lidé mezi stories posunuli nebo odešli; vyšší číslo = větší úbytek pozornosti.') : '')) ?>
    <div class="card-body">
        <?php if ($stHasMetrics): ?>
        <div class="stats">
            <div><span class="stat-label"><?= h(t('Dosah')) ?></span><span class="stat-value num"><?= h($stT['reachLabel'] ?? '0') ?></span></div>
            <div><span class="stat-label"><?= h(t('Zobrazení')) ?></span><span class="stat-value num"><?= h($stT['viewsLabel'] ?? '0') ?></span></div>
            <div><span class="stat-label"><?= h(t('Odpovědi')) ?> · <?= h(t('Navigace')) ?></span><span class="stat-value num"><?= h($stT['repliesLabel'] ?? '0') ?> · <?= h($stT['navigationLabel'] ?? '0') ?></span></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($socialStories['rows'])): ?>
        <div class="table-wrap">
            <table class="table table-plain">
                <thead><tr><th scope="col">Story</th><th class="col-md" scope="col"><?= h(t('Datum a čas')) ?></th><?php if ($stHasMetrics): ?><th class="col-num" scope="col"><?= h(t('Dosah')) ?></th><th class="col-num" scope="col"><?= h(t('Zobrazení')) ?></th><th class="col-num" scope="col"><?= h(t('Odpovědi')) ?></th><th class="col-num" scope="col"><?= h(t('Navigace')) ?></th><?php endif; ?></tr></thead>
                <tbody>
                    <?php foreach ($socialStories['rows'] as $st): ?>
                        <tr>
                            <td class="ellipsis"><?php if (($st['permalink'] ?? '') !== ''): ?><a href="<?= h($st['permalink']) ?>" target="_blank" rel="noopener"><?= h($st['message']) ?></a><?php else: ?><?= h($st['message']) ?><?php endif; ?></td>
                            <td class="num"><?= h($st['date']) ?></td>
                            <?php if ($stHasMetrics): ?>
                            <td class="num"><?= h($st['reachLabel'] ?? '–') ?></td>
                            <td class="num is-strong"><?= h($st['viewsLabel'] ?? '–') ?></td>
                            <td class="num"><?= h($st['repliesLabel'] ?? '–') ?></td>
                            <td class="num"><?= h($st['navigationLabel'] ?? '–') ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="card-desc"><?= h(t('Zatím nebyly zachyceny žádné Stories. Aby se nějaká objevila, musí synchronizace proběhnout, dokud je Story živá (do ~24 h od zveřejnění); pomáhá častější sync nebo sync hned po zveřejnění.')) ?></p>
        <?php endif; ?>
    </div>
</section>
