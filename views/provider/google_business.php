<?php
/**
 * Detail zdroje – Google Business Profile: hledané výrazy, které vedly na profil.
 * Záložky metrik (zobrazení, kliknutí na web, hovory, trasy) a graf kreslí views/provider.php.
 * V rozsahu z index.php: $gbpKeywords. Jen prezentace.
 */
?>
<?php if (!empty($gbpKeywords['rows'])): ?>
<section class="card card-clip">
    <?= allstat_ui_card_head(t('Hledané výrazy'), allstat_ui_period_label($gbpKeywords['from'], (new DateTimeImmutable($gbpKeywords['to']))->modify('last day of this month')->format('Y-m-d')), h(t('Výrazy, po kterých lidé profil našli v Google hledání a Mapách. Google je dává jen za celé měsíce a malá čísla jen jako horní hranici („méně než“). Aktuální měsíc se doplní po jeho skončení.'))) ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th scope="col"><?= h(t('Výraz')) ?></th><th class="col-num" scope="col"><?= h(t('Zobrazení')) ?></th></tr></thead>
            <tbody>
                <?php foreach ($gbpKeywords['rows'] as $row): ?>
                    <tr>
                        <td><span class="post-text"><?= h($row['keyword']) ?></span></td>
                        <td class="num"><?= h(($row['below'] ? t('méně než') . ' ' : '') . allstat_number($row['impressions'], 0)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
