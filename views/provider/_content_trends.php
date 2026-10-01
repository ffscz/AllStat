<?php
/**
 * Sdílený partial — „Trendy v čase": grafy vývoje klíčových metrik, každá zvlášť (z $providerPayload).
 * Includuje se z views/provider/facebook_pages.php a instagram_business.php.
 * V rozsahu z index.php: $providerPayload (labels + metrics se 'series'), h(), allstat_trend_svg().
 * Prezentace only — žádný přístup do DB. Série jsou stejné, jaké kreslí generický trend graf.
 */
$trendLabels = $providerPayload['labels'] ?? [];
$trendMetrics = $providerPayload['metrics'] ?? [];
if (count($trendLabels) > 1 && $trendMetrics):
    // Pořadí a výběr metrik obsahu/publika; vezmeme jen ty, co existují a mají nenulovou sérii.
    $trendOrder = ['page_impressions', 'reach', 'engagements', 'engagement', 'views', 'new_follows', 'followers_total', 'page_views'];
    $trendColors = ['blue' => '#2563eb', 'green' => '#16a34a', 'violet' => '#8b5cf6', 'orange' => '#f59e0b', 'cyan' => '#06b6d4', 'rose' => '#f43f5e', 'teal' => '#14b8a6'];
    $trendCards = [];
    $trendSeen = [];
    foreach ($trendOrder as $wantKey) {
        foreach ($trendMetrics as $m) {
            $k = (string) ($m['key'] ?? '');
            if ($k !== $wantKey || isset($trendSeen[$k])) {
                continue;
            }
            $series = array_map('floatval', $m['series'] ?? []);
            if (!$series || array_sum(array_map('abs', $series)) <= 0) {
                continue;
            }
            $trendSeen[$k] = true;
            $trendCards[] = $m;
        }
    }
    if ($trendCards):
        $trendFrom = (string) ($trendLabels[0] ?? '');
        $trendTo = (string) (end($trendLabels) ?: '');
?>
<section class="analytics-grid" aria-label="Trendy v čase">
    <article class="panel panel-wide">
        <div class="panel-header"><h2>Trendy v čase</h2><?php if ($trendFrom !== '' && $trendTo !== ''): ?><span class="panel-total"><?= h($trendFrom) ?> – <?= h($trendTo) ?></span><?php endif; ?></div>
        <p class="panel-help">Vývoj klíčových metrik za zvolené období, každá zvlášť pro lepší čitelnost. Křivka vychází z aktuálně synchronizovaných dat; propady k nule jsou období bez stažených dat. Čím víc stáhneš přes „Stáhnout historii", tím úplnější trend.</p>
        <div class="trend-grid">
            <?php foreach ($trendCards as $m): $col = $trendColors[$m['color'] ?? 'blue'] ?? '#2563eb'; ?>
                <div class="trend-card">
                    <div class="trend-card-head">
                        <span class="trend-card-label"><?= h($m['label']) ?></span>
                        <span class="trend-card-total"><?= h($m['displayValue'] ?? ($m['totalLabel'] ?? '')) ?></span>
                    </div>
                    <?= allstat_trend_svg($m['series'] ?? [], $col) ?>
                </div>
            <?php endforeach; ?>
        </div>
    </article>
</section>
<?php endif; endif; ?>
