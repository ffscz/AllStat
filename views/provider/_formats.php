<?php
/**
 * Sdílený partial – „Formáty obsahu“: podíl formátů (foto, video, reel, odkaz …) na zobrazeních a Ø
 * zapojení na příspěvek. Řadí se dle zobrazení, bez nich dle dosahu → engagementu → počtu (zobrazení
 * a dosah se u části dat plní až po synchronizaci nebo CSV importu). Includuje se z facebook_pages.php
 * a instagram_business.php; očekává $socialPosts['byFormat'].
 */
if (empty($socialPosts['byFormat'])) {
    return;
}
$fmtRows = $socialPosts['byFormat'];
$fmtMetric = 'views';
if (array_sum(array_column($fmtRows, 'views')) <= 0) {
    if (array_sum(array_column($fmtRows, 'reach')) > 0)          { $fmtMetric = 'reach'; }
    elseif (array_sum(array_column($fmtRows, 'engagement')) > 0) { $fmtMetric = 'engagement'; }
    else                                                         { $fmtMetric = 'count'; }
}
// Tvary do „podíl na …“ a „… celkem“ (2. pád).
$fmtForms = [
    'views'      => ['na' => t('zobrazeních'),      'unit' => t('zobrazení')],
    'reach'      => ['na' => t('dosahu'),           'unit' => t('dosahu')],
    'engagement' => ['na' => t('engagementu'),      'unit' => t('engagementu')],
    'count'      => ['na' => t('počtu příspěvků'),  'unit' => t('příspěvků')],
][$fmtMetric];
usort($fmtRows, static fn (array $a, array $b): int => (int) ($b[$fmtMetric] ?? 0) <=> (int) ($a[$fmtMetric] ?? 0));
$fmtValues = array_map(static fn (array $f): int => (int) ($f[$fmtMetric] ?? 0), $fmtRows);
$fmtTotal = array_sum($fmtValues);
$fmtWidths = allstat_ui_widths($fmtValues);
?>
<section class="card">
    <?= allstat_ui_card_head(t('Formáty obsahu'), '', h(t('Podíl na {metric} a průměrné zapojení na příspěvek.', ['metric' => $fmtForms['na']])),
        $fmtTotal > 0 ? allstat_ui_card_total(allstat_number($fmtTotal) . ' ' . $fmtForms['unit']) : '') ?>
    <div class="card-body">
        <?= allstat_ui_meters(array_map(static fn (array $f, int $v, float $w): array => [
            'label' => $f['label'],
            'extraHtml' => ' <span class="is-muted">· ' . h(tn((int) $f['count'], '{count} příspěvek', '{count} příspěvky', '{count} příspěvků')) . '</span>',
            'value' => allstat_number($v),
            'share' => $fmtTotal > 0 ? allstat_percent(round($v / $fmtTotal * 100), 0) : '',
            'width' => $w,
            'noteHtml' => h(t('Ø zapojení {value} na příspěvek', ['value' => allstat_number((float) $f['avgEngagement'], 1)]))
                . ((int) $f['reach'] > 0 ? ' · ' . h(t('dosah {value}', ['value' => allstat_number((int) $f['reach'])])) : ''),
        ], $fmtRows, $fmtValues, $fmtWidths)) ?>
    </div>
</section>
