<?php
/**
 * Detail jednoho napojení (Clarity, Meta, LinkedIn, YouTube, reklamy …) v index.php (?source_id=N).
 * Proměnné připravuje index.php: $selectedProvider, $providerPayload, $data a data jednotlivých služeb;
 * sekce konkrétní služby jsou ve views/provider/<provider_key>.php.
 */
?>
<?php /* ---- Generic single-provider view (Meta, LinkedIn, Ads, Clarity…) ---- */ ?>
<?php
// Czech category group + a clear "Meta Ads vs organic" flag for the heading/badges.
$provGroupCz = ['social' => t('Sociální sítě'), 'ppc' => t('Reklama (PPC)'), 'analytics' => t('Analytika'), 'seo' => 'SEO'];
$provGroup = $provGroupCz[$selectedProvider['category']] ?? ($selectedProvider['categoryLabel'] ?? '');
$provIsAds = ($selectedProvider['category'] ?? '') === 'ppc';
?>
<?php if (empty($providerPayload['hasData'])): ?>
<section class="analytics-grid" aria-label="<?= h(t('Zdroj')) ?>">
    <article class="panel panel-wide">
        <div class="panel-header"><h2><?= h($selectedProvider['label']) ?> <span>(<?= h($selectedProvider['name']) ?>)</span></h2></div>
        <?php $sourcesLink = '<a href="admin/sources.php">' . h(t('Zdroje dat')) . '</a>'; ?>
        <?php if (($selectedProvider['provider_key'] ?? '') === 'clarity'): ?>
        <p class="panel-help"><?= t('{group}, tento zdroj zatím nemá data za zvolené období. Napoj a synchronizuj ho ve {sources_link}, nebo nahraj denní CSV přes {import_link}, případně zvol jiné období.', ['group' => h($provGroup), 'sources_link' => $sourcesLink, 'import_link' => '<a href="admin/clarity-import.php?domain_id=' . (int) $data['domain']['id'] . '">' . h(t('Import Clarity CSV')) . '</a>']) ?></p>
        <?php else: ?>
        <p class="panel-help"><?= t('{group}, tento zdroj zatím nemá data za zvolené období. Napoj a synchronizuj ho ve {sources_link}, případně zvol jiné období.', ['group' => h($provGroup), 'sources_link' => $sourcesLink]) ?></p>
        <?php endif; ?>
    </article>
</section>
<?php else: ?>
<section class="analytics-grid" aria-label="<?= h(t('Vybraný zdroj')) ?>">
    <article class="panel panel-wide">
        <div class="panel-header"><h2><?= h($selectedProvider['label']) ?> <span>(<?= h($selectedProvider['name']) ?>)</span></h2></div>
        <p class="panel-help"><?= h(t('{group}, data jen za {scope}. Nemíchá se s ostatními zdroji ani s GA4 přehledem.', ['group' => $provGroup, 'scope' => t($provIsAds ? 'tento reklamní účet' : 'tuto jednu stránku / účet')])) ?><?php if ($provIsAds): ?> <?= ($selectedProvider['provider_key'] ?? '') === 'microsoft_ads'
            ? t('ROAS a konverze vycházejí z atribučního nastavení účtu Microsoft Advertising.')
            : t('ROAS a konverze závisí na <strong>atribučním okně</strong> Meta (výchozí 7 dní klik / 1 den zobrazení; lze změnit v config_json napojení).') ?><?php endif; ?></p>
    </article>
</section>
<section class="kpi-grid" aria-label="<?= h(t('Metriky zdroje')) ?>">
    <?php foreach ($providerPayload['metrics'] as $metric): ?>
        <article class="kpi-card kpi-<?= h($metric['color'] ?? 'cyan') ?>">
            <div class="kpi-head">
                <span class="kpi-icon"><i data-lucide="<?= h($metric['icon'] ?? 'activity') ?>"></i></span>
                <span><strong><?= h($metric['label']) ?></strong> <small>(<?= h($selectedProvider['label']) ?>)</small></span>
                <?php if (!empty($metric['tooltip'])): ?>
                    <span class="kpi-help" tabindex="0" aria-label="<?= h(t('Vysvětlení metriky')) ?>">
                        <i data-lucide="info"></i>
                        <span class="kpi-help-popover"><?= h($metric['tooltip']) ?></span>
                    </span>
                <?php endif; ?>
            </div>
            <div class="kpi-value"><?= h($metric['displayValue'] ?? ($metric['totalLabel'] ?? '0')) ?></div>
            <div class="sparkline-wrap"><canvas data-provider-spark="<?= h($metric['key']) ?>"></canvas></div>
        </article>
    <?php endforeach; ?>
</section>

<?php if (!empty($providerPayload['showChart'])): ?>
<section class="analytics-grid" aria-label="<?= h(t('Trend zdroje')) ?>">
    <article class="panel panel-wide">
        <div class="panel-header"><h2><?= h($selectedProvider['label']) ?>, <?= h(t('trend v čase')) ?> <span>(<?= h($selectedProvider['name']) ?>)</span></h2></div>
        <p class="panel-help"><?= h(t('Vývoj klíčových metrik tohoto zdroje v čase za zvolené období. Jen za tento jeden zdroj (nemíchá se s GA4). U jednodenního období se graf nezobrazuje.')) ?></p>
        <div class="chart-frame chart-frame-line"><canvas id="providerChart"></canvas></div>
    </article>
</section>
<?php endif; ?>

<?php
// Provider-specific breakdown sections (Clarity tables, Meta Ads campaigns/ad-sets/creatives…)
// live in views/provider/<provider_key>.php — adding a provider's rich view = drop a file there.
// The shared KPI grid + trend above render the same for every provider; only these sections differ.
$allstatProviderPartial = __DIR__ . '/provider/' . preg_replace('/[^a-z0-9_]/', '', (string) ($selectedProvider['provider_key'] ?? '')) . '.php';
if (is_file($allstatProviderPartial)) {
    include $allstatProviderPartial;
}
?>

<?php endif; ?>
