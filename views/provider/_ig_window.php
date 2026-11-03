<?php
/**
 * Instagram za posledních 28 dní ve stejném členění jako přehled Meta Business Suite: zobrazení, dosah,
 * sledování a interakce, vždy s rozpadem na sledující / nesledující nebo typ obsahu. Čísla jsou za celé
 * okno (každý účet jednou), ne součet dní. V rozsahu: $igWindow (allstat_get_ig_window28). Jen prezentace.
 */
if (empty($igWindow['values'])) {
    return;
}
$wv = $igWindow['values'];
$num = static fn (?float $v): string => $v === null ? '–' : allstat_number((int) round($v));
$pct = static function (?float $part, ?float $whole): string {
    return ($part === null || !$whole) ? '–' : allstat_percent(round($part / $whole * 100, 1), 1);
};
$views = $wv['w28_views'] ?? null;
$viewsSplit = ($wv['w28_views_follower'] ?? 0) + ($wv['w28_views_nonfollower'] ?? 0);
$inter = $wv['w28_total_interactions'] ?? null;
$interRows = [];
foreach (['w28_inter_post' => t('Příspěvky'), 'w28_inter_reel' => t('Reels'), 'w28_inter_story' => t('Stories')] as $k => $label) {
    if (isset($wv[$k])) {
        $interRows[] = ['label' => $label, 'value' => $num($wv[$k]), 'title' => $pct($wv[$k], $inter)];
    }
}
if (isset($wv['w28_accounts_engaged'])) {
    $interRows[] = ['label' => t('Zapojené účty'), 'value' => $num($wv['w28_accounts_engaged']), 'title' => t('Kolik různých účtů s obsahem reagovalo.')];
}
$blocks = [
    [t('Zobrazení'), $num($views), [
        ['label' => t('Od sledujících'), 'value' => $pct($wv['w28_views_follower'] ?? null, $viewsSplit)],
        ['label' => t('Od nesledujících'), 'value' => $pct($wv['w28_views_nonfollower'] ?? null, $viewsSplit)],
    ]],
    [t('Dosah'), $num($wv['reach_28d_unique'] ?? null), [
        ['label' => t('Od sledujících'), 'value' => $num($wv['w28_reach_follower'] ?? null)],
        ['label' => t('Od nesledujících'), 'value' => $num($wv['w28_reach_nonfollower'] ?? null)],
    ]],
    [t('Sledování'), $num($wv['w28_follows'] ?? null), [
        ['label' => t('Zrušení sledování'), 'value' => $num($wv['w28_unfollows'] ?? null)],
        ['label' => t('Čistý přírůstek'), 'value' => isset($wv['w28_follows'], $wv['w28_unfollows']) ? allstat_number((int) ($wv['w28_follows'] - $wv['w28_unfollows'])) : '–'],
    ]],
    [t('Interakce'), $num($inter), $interRows],
];
$from = new DateTimeImmutable($igWindow['start']);
$to = new DateTimeImmutable($igWindow['end']);
?>
<section class="card">
    <?= allstat_ui_card_head(t('Rozpad za posledních 28 dní'), $from->format('j. n.') . ' – ' . $to->format('j. n. Y'),
        h(t('Stejné okno a stejné členění jako přehled Meta Business Suite, každý účet se počítá jednou. Business Suite si čísla drží uložená, proto se od čerstvých dat z API může o pár procent lišit.'))) ?>
    <div class="card-body grid grid-facets">
        <?php foreach ($blocks as [$title, $value, $rows]): ?>
            <div>
                <span class="block-title"><?= h($title) ?></span>
                <span class="w28-value num"><?= h($value) ?></span>
                <?= $rows ? allstat_ui_rows($rows) : '' ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>
