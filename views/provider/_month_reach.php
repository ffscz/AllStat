<?php
/**
 * Různí lidé za kalendářní měsíc (FB „Diváci“, IG dosah bez dvojího počítání). Denní dosah v dlaždicích je
 * součet dní, takže stejný člověk se počítá vícekrát; tady je číslo, které ukazuje Business Suite za měsíc.
 * V rozsahu: $monthReach (allstat_get_month_unique_reach), $last28Reach (allstat_get_last28_unique_reach),
 * $monthReachLabel. Jen prezentace.
 */
if (empty($monthReach) && empty($last28Reach)) {
    return;
}
$monthReachRows = array_map(static function (array $m): array {
    $d = new DateTimeImmutable($m['month'] . '-01');
    $partial = $m['days'] !== null && $m['days'] < $m['monthDays'];

    return [
        'label' => $d->format('n/Y') . ($partial ? ' ' . t('(1.–{day}.)', ['day' => (int) $m['days']]) : ''),
        'value' => allstat_number((int) $m['value']),
        'title' => $partial ? t('Číslo pokrývá jen prvních {days} dní měsíce. Běžný měsíc končí včerejškem, Instagram navíc bere nejvýš 30 dní.', ['days' => (int) $m['days']]) : '',
    ];
}, $monthReach ?? []);
if (!empty($last28Reach)) {
    $l28End = new DateTimeImmutable($last28Reach['end']);
    array_unshift($monthReachRows, [
        'label' => t('Posledních 28 dní (do {date})', ['date' => $l28End->format('j. n.')]),
        'value' => allstat_number((int) $last28Reach['value']),
        'title' => t('Stejné okno jako výchozí pohled Business Suite, čísla jdou porovnat přímo.'),
    ]);
}
?>
<section class="card">
    <?= allstat_ui_card_head($monthReachLabel, t('různí lidé za období'), h(t('Kolik různých lidí obsah vidělo, každý se počítá jednou, stejně jako v Business Suite. Dosah v dlaždicích výše je součet dní, proto bývá vyšší.'))) ?>
    <div class="card-body">
        <?= allstat_ui_rows($monthReachRows) ?>
    </div>
</section>
