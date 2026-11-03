<?php
/**
 * Detail zdroje – Bing Webmaster Tools: nejčastější dotazy a stránky za období.
 * Záložky metrik (kliknutí, zobrazení, index, procházení) a graf kreslí views/provider.php.
 * V rozsahu z index.php: $bingQueries, $bingPages. Jen prezentace.
 */
$bingTables = [
    ['rows' => $bingQueries, 'title' => t('Dotazy'), 'col' => t('Dotaz')],
    ['rows' => $bingPages, 'title' => t('Stránky'), 'col' => t('Stránka')],
];
?>
<?php foreach ($bingTables as $bingTable): ?>
<?php if ($bingTable['rows'] !== []): ?>
<section class="card card-clip">
    <?= allstat_ui_card_head($bingTable['title'], 'Bing', h(t('Nejvíc kliknutí za zvolené období. Bing dává dotazy a stránky po týdnech, takže okraje období mohou zahrnout celý týden. Pozice je průměr vážený zobrazeními.'))) ?>
    <div class="table-wrap">
        <table class="table table-wide">
            <thead><tr><th scope="col"><?= h($bingTable['col']) ?></th><th class="col-num" scope="col"><?= h(t('Kliknutí')) ?></th><th class="col-num" scope="col"><?= h(t('Zobrazení')) ?></th><th class="col-xs" scope="col">CTR</th><th class="col-xs" scope="col"><?= h(t('Pozice')) ?></th></tr></thead>
            <tbody>
                <?php foreach ($bingTable['rows'] as $row): ?>
                    <tr>
                        <td><span class="post-text"><?= h($row['label']) ?></span></td>
                        <td class="num is-strong"><?= h(allstat_number($row['clicks'], 0)) ?></td>
                        <td class="num"><?= h(allstat_number($row['impressions'], 0)) ?></td>
                        <td class="num"><?= h($row['ctr'] !== null ? allstat_percent($row['ctr'], 2) : '–') ?></td>
                        <td class="num"><?= h($row['position'] !== null ? allstat_number($row['position'], 1) : '–') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
<?php endforeach; ?>
