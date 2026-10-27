<?php
/**
 * Sdílený partial – karta „Sledující“ (odběratelé): stav k poslednímu dni, malý graf vývoje a noví /
 * odhlášení / čistý přírůstek za období. Z metrik $providerPayload (followers_total nebo fans_total,
 * new_follows, unfollows, subscribers_net). Includuje se z partialů sociálních sítí; graf kreslí
 * assets/js/app.js (renderFollowersSpark). $followersTitle může partial přepsat (YouTube: Odběratelé).
 */
$fMetrics = array_column($providerPayload['metrics'] ?? [], null, 'key');
$fTotal = $fMetrics['followers_total'] ?? ($fMetrics['fans_total'] ?? null);
if (!$fTotal) {
    return;
}
$fNew = isset($fMetrics['new_follows']) ? (int) $fMetrics['new_follows']['total'] : null;
$fLost = isset($fMetrics['unfollows']) ? (int) $fMetrics['unfollows']['total'] : null;
$fNet = isset($fMetrics['subscribers_net']) ? (int) $fMetrics['subscribers_net']['total'] : ($fNew !== null ? $fNew - (int) $fLost : null);
$fPeriod = allstat_ui_period_label($data['range']['start'], $data['range']['end']);
$fAsOf = !empty($fTotal['lastDay']) ? allstat_date_label((string) $fTotal['lastDay']) : allstat_date_label($data['range']['end']);
?>
<section class="card">
    <?= allstat_ui_card_head($followersTitle ?? t('Sledující'), '', h(t('Stav k {date} a vývoj za {period}.', ['date' => $fAsOf, 'period' => $fPeriod]))) ?>
    <div class="card-body">
        <div class="metric-note">
            <span class="metric-value num"><?= h($fTotal['displayValue'] ?? $fTotal['totalLabel']) ?></span>
            <?php if ($fNet !== null): ?><span class="num <?= allstat_ui_delta_class((float) $fNet) ?>"><?= h(t('{value} za {period}', ['value' => allstat_ui_signed($fNet), 'period' => $fPeriod])) ?></span><?php endif; ?>
        </div>
        <div class="chart chart-sm"><canvas data-followers-spark="<?= h($fTotal['key']) ?>" role="img" aria-label="<?= h(t('Vývoj počtu sledujících za {period}', ['period' => $fPeriod])) ?>"></canvas></div>
        <?php if ($fNew !== null || $fNet !== null): ?>
        <div class="stats">
            <div><span class="stat-label"><?= h(t('Noví')) ?></span><span class="stat-value num is-up"><?= $fNew !== null ? h(allstat_ui_signed($fNew)) : '–' ?></span></div>
            <div><span class="stat-label"><?= h(t('Odhlášení')) ?></span><span class="stat-value num is-down"><?= $fLost !== null ? h(allstat_ui_signed(-$fLost)) : '–' ?></span></div>
            <div><span class="stat-label"><?= h(t('Čistý přírůstek')) ?></span><span class="stat-value num"><?= $fNet !== null ? h(allstat_ui_signed($fNet)) : '–' ?></span></div>
        </div>
        <?php endif; ?>
    </div>
</section>
