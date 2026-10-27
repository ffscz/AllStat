<?php
/**
 * Záhlaví pohledů v index.php: název, ovládání období a zdroje dat, tlačítko AI reportu a hlášky.
 * Přehled (Růst kanálů) má období v uzavřených měsících (.seg, přepíná assets/js/growth.js bez reloadu);
 * ostatní pohledy mají rychlá období (.seg), kalendář rozsahu a výběr zdroje (.dropdown/.menu), které
 * obsluhuje assets/js/app.js. Ovládání je uvnitř formuláře #dashboardFilters (AJAX obnova přehledu GA4).
 */

$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$curStart = $data['range']['start'];
$curEnd = $data['range']['end'];
?>
<div class="page-head">
    <div>
        <h1 class="h1"><?= h($pageTitle) ?></h1>
        <?php if ($pageSubtitle !== ''): ?><p class="page-sub" data-page-sub><?= h($pageSubtitle) ?></p><?php endif; ?>
    </div>
    <?php if ($isGrowthView):
        $gwHead = $growth['window'];
        // „Říj 2025 – Zář 2026": zkratky měsíců jako v tabulce, rok celý.
        $growthRangeShort = preg_replace('/ (\d{2})$/', ' 20$1', allstat_growth_month_label($gwHead['months'][0])) . ' – '
            . preg_replace('/ (\d{2})$/', ' 20$1', allstat_growth_month_label($gwHead['months'][$gwHead['n'] - 1]));
    ?>
    <div class="page-actions">
        <nav class="seg" aria-label="<?= h(t('Období (uzavřené měsíce)')) ?>" data-growth-months>
            <?php foreach ([3 => t('3 měsíce'), 6 => t('6 měsíců'), 12 => t('12 měsíců')] as $gm => $gmLabel): ?>
                <a class="seg-item" href="?<?= h(http_build_query(['view' => 'growth', 'months' => $gm, 'domain_id' => (int) $data['domain']['id']])) ?>" data-months="<?= $gm ?>"<?= $gwHead['n'] === $gm ? ' aria-current="true"' : '' ?>><?= h($gmLabel) ?></a>
            <?php endforeach; ?>
        </nav>
        <span class="btn btn-secondary" title="<?= h(t('Počítají se jen uzavřené měsíce, rozběhnutý měsíc je v tabulce zvlášť jako „zatím“.')) ?>"><?= allstat_icon('calendar', 'icon-sm') ?><span data-growth-range><?= h($growthRangeShort) ?></span></span>
    <?php else:
        if ($selectedProvider !== null && !empty($selectedProvider['rolling'])) {
            // Clarity-style rolling provider: day-scale ranges (its API only covers the last 1-3 days;
            // longer ranges fill in as the daily cron accumulates).
            $quickRanges = [
                t('Dnes') => [$today, $today],
                t('Včera') => [(new DateTimeImmutable('yesterday'))->format('Y-m-d'), (new DateTimeImmutable('yesterday'))->format('Y-m-d')],
                t('3 dny') => [(new DateTimeImmutable('today'))->modify('-2 days')->format('Y-m-d'), $today],
                t('7 dní') => [(new DateTimeImmutable('today'))->modify('-6 days')->format('Y-m-d'), $today],
                t('Vše') => [(new DateTimeImmutable('today'))->modify('-540 days')->format('Y-m-d'), $today],
            ];
        } else {
            $quickRanges = [
                t('Dnes') => [$today, $today],
                t('7 dní') => [(new DateTimeImmutable('today'))->modify('-6 days')->format('Y-m-d'), $today],
                t('30 dní') => [(new DateTimeImmutable('today'))->modify('-29 days')->format('Y-m-d'), $today],
                t('90 dní') => [(new DateTimeImmutable('today'))->modify('-89 days')->format('Y-m-d'), $today],
                t('Vše') => [(new DateTimeImmutable('today'))->modify('-16 months')->format('Y-m-d'), $today],
            ];
        }
        // Label rozsahu pro tlačítko kalendáře (JS ho pak aktualizuje). En-pomlčka „–" u rozsahu.
        $rlS = new DateTimeImmutable($curStart);
        $rlE = new DateTimeImmutable($curEnd);
        if ($curStart === $curEnd) {
            $rangeLabel = allstat_date_label($curStart);
        } elseif ($rlS->format('Y') === $rlE->format('Y')) {
            $rangeLabel = $rlS->format(allstat_locale() === 'en' ? 'j M' : 'j. n.') . ' – ' . allstat_date_label($curEnd);
        } else {
            $rangeLabel = allstat_date_label($curStart) . ' – ' . allstat_date_label($curEnd);
        }
        // Rychlé volby období se mají vracet na stejný pohled (přehled / trychtýř se zvoleným rozpadem).
        $rangeLinkTail = $isGenericView ? '' : ($isFunnelView ? '&view=funnel&funnel_id=' . (int) $funnel['id'] . '&breakdown=' . rawurlencode($funnelBreakdownKey) : '&view=overview');

        // Výběr zdroje: připojené služby po kategoriích, aby organické sítě a reklama byly zvlášť.
        $catGroupCz = ['social' => t('Sociální sítě'), 'ppc' => t('Reklama (PPC)'), 'analytics' => t('Analytika'), 'seo' => 'SEO'];
        $genericByGroup = [];
        foreach ($providerOptions as $opt) {
            if (empty($opt['generic'])) { continue; }
            $groupLabel = $catGroupCz[$opt['category']] ?? ($opt['categoryLabel'] ?? t('Ostatní'));
            $genericByGroup[$groupLabel][] = $opt;
        }
        $isOverviewSel = !$isGenericView && !$isFunnelView;
        $menuItem = static function (string $icon, string $name, string $sub, bool $selected, array $attrs): string {
            $a = '';
            foreach ($attrs as $k => $v) { $a .= ' data-' . $k . '="' . h((string) $v) . '"'; }

            return '<button type="button" class="menu-item" role="menuitem"' . ($selected ? ' aria-current="true"' : '') . $a . '>' . $icon
                . '<span class="menu-text"><span class="ellipsis">' . h($name) . '</span>' . ($sub !== '' ? '<span class="menu-sub ellipsis">' . h($sub) . '</span>' : '') . '</span>'
                . ($selected ? allstat_icon('check', 'menu-check') : '') . '</button>';
        };
    ?>
    <form class="page-actions" id="dashboardFilters" method="get">
        <nav class="seg" aria-label="<?= h(t('Rychlý výběr období')) ?>" data-quick-ranges>
            <?php foreach ($quickRanges as $label => [$qs, $qe]): ?>
                <a class="seg-item" data-range-start="<?= h($qs) ?>" data-range-end="<?= h($qe) ?>" href="?domain_id=<?= (int) $data['domain']['id'] ?>&amp;start=<?= h($qs) ?>&amp;end=<?= h($qe) ?>&amp;source_id=<?= (int) $viewSourceId ?><?= h($rangeLinkTail) ?>"<?= ($curStart === $qs && $curEnd === $qe) ? ' aria-current="true"' : '' ?>><?= h($label) ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="dropdown" data-date-range>
            <button type="button" class="btn btn-secondary" data-date-range-trigger aria-haspopup="dialog" aria-expanded="false">
                <?= allstat_icon('calendar', 'icon-sm') ?><span data-date-range-label><?= h($rangeLabel) ?></span><?= allstat_icon('chevron-down', 'icon-sm') ?>
            </button>
            <input type="hidden" name="start" value="<?= h($curStart) ?>" data-date-start>
            <input type="hidden" name="end" value="<?= h($curEnd) ?>" data-date-end>
            <div class="menu datepicker" data-date-range-pop role="dialog" aria-label="<?= h(t('Výběr období')) ?>" hidden></div>
        </div>
        <?php if ($isFunnelView): ?>
        <input type="hidden" name="view" value="funnel">
        <input type="hidden" name="funnel_id" value="<?= (int) $funnel['id'] ?>">
        <input type="hidden" name="breakdown" value="<?= h($funnelBreakdownKey) ?>">
        <?php elseif (!$isGenericView): ?><input type="hidden" name="view" value="overview"><?php endif; ?>
        <input type="hidden" name="domain_id" value="<?= (int) $data['domain']['id'] ?>">
        <input type="hidden" name="source_id" value="<?= (int) $viewSourceId ?>">

        <div class="dropdown" data-source-picker data-domain="<?= (int) $data['domain']['id'] ?>" data-start="<?= h($curStart) ?>" data-end="<?= h($curEnd) ?>">
            <button type="button" class="btn btn-secondary" data-menu-toggle="sourceMenu" aria-haspopup="true" aria-expanded="false"><span><?= h(t('Přepnout zdroj')) ?></span><?= allstat_icon('chevron-down', 'icon-sm') ?></button>
            <div class="menu menu-end menu-scroll" id="sourceMenu" role="menu" aria-label="<?= h(t('Zdroj dat')) ?>" hidden>
                <?= $menuItem(allstat_icon('trending-up', 'icon-sm'), t('Přehled'), t('všechny kanály po měsících'), false, ['view' => 'growth', 'value' => 0]) ?>
                <?= $menuItem(allstat_icon('line-chart', 'icon-sm'), t('Přehled (GA4 + GSC)'), '', $isOverviewSel, ['view' => 'overview', 'value' => 0]) ?>
                <?php if ($funnelOptions): ?>
                    <div class="menu-label"><?= h(t('Trychtýře')) ?></div>
                    <?php foreach ($funnelOptions as $fOpt): $fCount = count($fOpt['steps']); ?>
                        <?= $menuItem(allstat_icon('filter', 'icon-sm'), (string) $fOpt['name'], tn($fCount, '{count} krok', '{count} kroky', '{count} kroků'), $isFunnelView && (int) $fOpt['id'] === (int) $funnel['id'], ['view' => 'funnel', 'funnel' => (int) $fOpt['id'], 'value' => 0]) ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php foreach ($genericByGroup as $groupLabel => $opts): ?>
                    <div class="menu-label"><?= h($groupLabel) ?></div>
                    <?php foreach ($opts as $opt): ?>
                        <?= $menuItem(allstat_source_tile((string) $opt['provider_key'], 'sm'), (string) $opt['name'], (string) $opt['label'], $viewSourceId === (int) $opt['connection_id'], ['value' => (int) $opt['connection_id']]) ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>

        <button class="btn-icon" type="submit" title="<?= h(t('Obnovit data')) ?>" aria-label="<?= h(t('Obnovit data')) ?>"><?= allstat_icon('refresh-cw', 'icon') ?></button>
    <?php endif; ?>
        <?php // Sdílení pro AI umí přehled, růst a zdroje; trychtýř do něj nepatří (AI čte trychtýře přes MCP get_funnel). ?>
        <?php if (!$isFunnelView): ?>
        <button class="btn btn-primary" type="button" id="shareAiBtn" title="<?= h(t('Sdílet pro AI (dočasný odkaz)')) ?>"
            data-domain="<?= (int) $data['domain']['id'] ?>" data-source="<?= (int) $viewSourceId ?>"
            data-start="<?= h($curStart) ?>" data-end="<?= h($curEnd) ?>"
            data-gran="<?= h($granularity) ?>" data-csrf="<?= h(allstat_csrf_token()) ?>"
            <?php if ($isGrowthView): ?>data-view="growth" data-months="<?= (int) $growth['window']['n'] ?>"<?php endif; ?>><?= allstat_icon('sparkles', 'icon-sm') ?><span><?= h(t('Analyzovat s AI')) ?></span></button>
        <?php endif; ?>
    <?= $isGrowthView ? '</div>' : '</form>' ?>
</div>

<?php // Hlášky z akcí, které se vrací na dashboard (např. import CSV z rozbalovacího boxu u FB/IG). ?>
<?php foreach (($_SESSION['flashes'] ?? []) as $flash): ?>
    <div class="callout <?= ($flash['type'] ?? '') === 'error' ? 'tone-down' : 'tone-up' ?>" role="status"><?= h((string) ($flash['message'] ?? '')) ?></div>
<?php endforeach; unset($_SESSION['flashes']); ?>

<section id="shareResult" class="card" hidden>
    <div class="card-head">
        <div>
            <h2 class="card-title"><?= h(t('Report pro AI')) ?><small data-share-expiry></small></h2>
            <p class="card-desc"><?= t('<strong>Klikni „Kopírovat text pro AI"</strong> a vlož do ChatGPT / Claude / Perplexity. Text přečte každá AI hned. Report platí max 15 minut. Odkaz níže je jen pro ruční použití, běžný chat ho sám neotevře.') ?></p>
        </div>
        <button type="button" class="btn-icon" data-share-close title="<?= h(t('Zavřít')) ?>" aria-label="<?= h(t('Zavřít')) ?>"><?= allstat_icon('x', 'icon') ?></button>
    </div>
    <div class="card-body form-grid">
        <div class="form-actions field-full">
            <button type="button" class="btn btn-primary btn-sm" data-share-copy-text><?= h(t('Kopírovat text pro AI')) ?></button>
            <span class="field-help"><?= h(t('Formát:')) ?> <a href="#" data-share-fmt="md">Markdown</a> · <a href="#" data-share-fmt="json">JSON</a></span>
        </div>
        <input type="text" class="input mono field-full" readonly data-share-url aria-label="<?= h(t('Odkaz (pro ruční použití)')) ?>">
    </div>
</section>
</div>
