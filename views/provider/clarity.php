<?php
/**
 * Detail zdroje – Microsoft Clarity: rozpady (odkazující weby, stránky, prohlížeče, chytré události)
 * jako měřáky ve dvou sloupcích. Záložky metrik a graf kreslí views/provider.php.
 * V rozsahu z index.php: $clarityBreakdowns (allstat_get_clarity_breakdowns). Jen prezentace.
 */
if (empty($clarityBreakdowns)) {
    return;
}
?>
<div class="grid grid-2">
    <?php foreach ($clarityBreakdowns as $breakdown): $cbW = allstat_ui_widths(array_column($breakdown['items'], 'sessions')); ?>
        <section class="card">
            <?= allstat_ui_card_head((string) $breakdown['title'], 'Clarity', h($breakdown['help'])) ?>
            <div class="card-body">
                <?= allstat_ui_meters(array_map(static fn (array $item, float $w): array => ['label' => $item['name'], 'value' => $item['sessionsLabel'], 'share' => $item['shareLabel'], 'width' => $w], $breakdown['items'], $cbW),
                    '', t('Bez dat za toto období, naimportuj denní Clarity CSV nebo počkej na sync.')) ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>
