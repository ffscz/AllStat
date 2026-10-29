<?php
/**
 * Detail jednoho napojení (Clarity, Meta, LinkedIn, YouTube, reklamy …) v index.php (?source_id=N).
 * Proměnné připravuje index.php: $selectedProvider, $providerPayload, $data a data jednotlivých služeb;
 * sekce konkrétní služby jsou ve views/provider/<provider_key>.php.
 * Společné pro všechny: záložky metrik s grafem vybrané řady (assets/js/app.js, renderMetricChart).
 */
require_once __DIR__ . '/_ui.php';

$provGroupCz = ['social' => t('Sociální sítě'), 'ppc' => t('Reklama (PPC)'), 'analytics' => t('Analytika'), 'seo' => 'SEO'];
$provGroup = $provGroupCz[$selectedProvider['category']] ?? ($selectedProvider['categoryLabel'] ?? '');
$provIsAds = ($selectedProvider['category'] ?? '') === 'ppc';
$provKey = (string) ($selectedProvider['provider_key'] ?? '');
$periodLabel = allstat_ui_period_label($data['range']['start'], $data['range']['end']);
?>
<?php if (empty($providerPayload['hasData'])): ?>
<section class="card">
    <div class="empty">
        <span class="empty-icon tone-flat"><?= allstat_icon('database', 'icon') ?></span>
        <span class="empty-title"><?= h(t('Za zvolené období zatím nejsou data')) ?></span>
        <?php $sourcesLink = '<a class="link-more" href="admin/sources.php">' . h(t('Zdroje dat')) . '</a>'; ?>
        <?php if ($provKey === 'clarity'): ?>
        <p class="empty-desc"><?= t('{group}, tento zdroj zatím nemá data za zvolené období. Napoj a synchronizuj ho ve {sources_link}, nebo nahraj denní CSV přes {import_link}, případně zvol jiné období.', ['group' => h($provGroup), 'sources_link' => $sourcesLink, 'import_link' => '<a class="link-more" href="admin/clarity-import.php?domain_id=' . (int) $data['domain']['id'] . '">' . h(t('Import Clarity CSV')) . '</a>']) ?></p>
        <?php else: ?>
        <p class="empty-desc"><?= t('{group}, tento zdroj zatím nemá data za zvolené období. Napoj a synchronizuj ho ve {sources_link}, případně zvol jiné období.', ['group' => h($provGroup), 'sources_link' => $sourcesLink]) ?></p>
        <?php endif; ?>
    </div>
</section>
<?php else: ?>
<?php
// Stavové metriky sledujících ukazuje karta „Sledující“ (views/provider/_followers.php), do záložek nepatří.
$followerKeys = ['followers_total', 'fans_total', 'new_follows', 'unfollows', 'subscribers_net'];
$metricKeys = array_column($providerPayload['metrics'], 'key');
$hasFollowersCard = (bool) array_intersect(['followers_total', 'fans_total'], $metricKeys);
$tabMetrics = array_values(array_filter($providerPayload['metrics'], static fn (array $m): bool => !$hasFollowersCard || !in_array($m['key'], $followerKeys, true)));
$showChart = count($providerPayload['labels'] ?? []) > 1;
$tabNote = '<span>' . h(t('za {period}', ['period' => $periodLabel])) . '</span>';
?>
<section class="card card-clip" aria-label="<?= h(t('Metriky zdroje')) ?>"<?= $showChart ? ' data-metric-chart="provider" data-granularity="' . h($granularity ?? 'day') . '"' : '' ?>>
    <div class="tabs" role="tablist" aria-label="<?= h(t('Metrika v grafu')) ?>">
        <?php foreach ($tabMetrics as $i => $metric): ?>
            <?= allstat_ui_metric_tab((string) $metric['key'], (string) $metric['label'], (string) ($metric['displayMain'] ?? $metric['displayValue'] ?? ($metric['totalLabel'] ?? '0')), $tabNote, $i === 0, (string) ($metric['tooltip'] ?? ''), (string) ($metric['displaySuffix'] ?? '')) ?>
        <?php endforeach; ?>
    </div>
    <?php if ($showChart): ?>
        <?= allstat_ui_metric_chart('providerChart', $periodLabel) ?>
    <?php endif; ?>
</section>

<?php
// Rozpady konkrétní služby (Clarity, Meta Ads kampaně, příspěvky FB/IG …) jsou ve views/provider/<provider_key>.php;
// nová služba = nový soubor tam. Záložky metrik a graf nahoře jsou pro všechny služby stejné.
$allstatProviderPartial = __DIR__ . '/provider/' . preg_replace('/[^a-z0-9_]/', '', $provKey) . '.php';
if (is_file($allstatProviderPartial)) {
    include $allstatProviderPartial;
}
?>

<div class="callout">
    <?= allstat_icon('info', 'icon-sm') ?>
    <span><?= h(t('{group}, data jen za {scope}. Nemíchá se s ostatními zdroji ani s GA4 přehledem.', ['group' => $provGroup, 'scope' => t($provIsAds ? 'tento reklamní účet' : 'tuto jednu stránku / účet')])) ?><?php if ($provIsAds): ?> <?= $provKey === 'microsoft_ads'
        ? t('ROAS a konverze vycházejí z atribučního nastavení účtu Microsoft Advertising.')
        : t('ROAS a konverze závisí na <strong>atribučním okně</strong> Meta (výchozí 7 dní klik / 1 den zobrazení; lze změnit v config_json napojení).') ?><?php endif; ?></span>
</div>
<?php endif; ?>
