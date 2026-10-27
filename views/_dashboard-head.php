<?php
/**
 * Záhlaví pohledů v index.php: název, ovládání období a zdroje dat, tlačítko AI reportu a hlášky.
 * Ovládání zatím používá legacy třídy (quick-ranges, filter-control, source-picker), na které je
 * navázaný assets/js/app.js; převede se při převodu pohledů.
 */
?>
<div class="page-head">
    <div>
        <h1 class="h1"><?= h($pageTitle) ?></h1>
        <?php if ($pageSubtitle !== ''): ?><p class="page-sub"><?= h($pageSubtitle) ?></p><?php endif; ?>
    </div>
    <div class="page-actions">
        <button class="btn-icon" type="submit" form="dashboardFilters" title="<?= h(t('Obnovit data')) ?>" aria-label="<?= h(t('Obnovit data')) ?>"><?= allstat_icon('refresh-cw', 'icon') ?></button>
        <?php // Sdílení pro AI umí přehled, růst a zdroje; trychtýř do něj nepatří (AI čte trychtýře přes MCP get_funnel). ?>
        <?php if (!$isFunnelView): ?>
        <button class="btn btn-primary" type="button" id="shareAiBtn" title="<?= h(t('Sdílet pro AI (dočasný odkaz)')) ?>"
            data-domain="<?= (int) $data['domain']['id'] ?>" data-source="<?= (int) $viewSourceId ?>"
            data-start="<?= h($data['range']['start']) ?>" data-end="<?= h($data['range']['end']) ?>"
            data-gran="<?= h($granularity) ?>" data-csrf="<?= h(allstat_csrf_token()) ?>"
            <?php if ($isGrowthView): ?>data-view="growth" data-months="<?= (int) $growth['window']['n'] ?>"<?php endif; ?>><?= allstat_icon('sparkles', 'icon-sm') ?><span><?= h(t('Analyzovat s AI')) ?></span></button>
        <?php endif; ?>
    </div>
</div>

<div class="topbar-controls">
    <?php
    $today = (new DateTimeImmutable('today'))->format('Y-m-d');
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
    $curStart = $data['range']['start'];
    $curEnd = $data['range']['end'];
    // Label rozsahu pro trigger vlastního datepickeru (JS ho pak aktualizuje). En-pomlčka „–" u rozsahu.
    $rlS = new DateTimeImmutable($curStart);
    $rlE = new DateTimeImmutable($curEnd);
    if ($curStart === $curEnd) {
        $rangeLabel = allstat_date_label($curStart);
    } elseif ($rlS->format('Y') === $rlE->format('Y')) {
        $rangeLabel = $rlS->format(allstat_locale() === 'en' ? 'j M' : 'j. n.') . ' – ' . allstat_date_label($curEnd);
    } else {
        $rangeLabel = allstat_date_label($curStart) . ' – ' . allstat_date_label($curEnd);
    }
    ?>
    <?php
    // Rychlé volby období se mají vracet na stejný pohled (přehled / trychtýř se zvoleným rozpadem).
    $rangeLinkTail = $isGenericView ? '' : ($isFunnelView ? '&view=funnel&funnel_id=' . (int) $funnel['id'] . '&breakdown=' . rawurlencode($funnelBreakdownKey) : '&view=overview');
    ?>
    <div class="topbar-controls">
    <?php if ($isGrowthView): ?>
    <div class="quick-ranges" aria-label="<?= h(t('Období (uzavřené měsíce)')) ?>">
        <?php foreach ([3 => t('3 měsíce'), 6 => t('6 měsíců'), 12 => t('12 měsíců')] as $gm => $gmLabel): ?>
            <a class="chip <?= $growth['window']['n'] === $gm ? 'is-active' : '' ?>" href="?<?= h(http_build_query(['view' => 'growth', 'months' => $gm, 'domain_id' => (int) $data['domain']['id']])) ?>"><?= h($gmLabel) ?></a>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="quick-ranges" aria-label="<?= h(t('Rychlý výběr období')) ?>">
        <?php foreach ($quickRanges as $label => [$qs, $qe]): ?>
            <a class="chip <?= ($curStart === $qs && $curEnd === $qe) ? 'is-active' : '' ?>" data-range-start="<?= h($qs) ?>" data-range-end="<?= h($qe) ?>" href="?domain_id=<?= (int) $data['domain']['id'] ?>&start=<?= h($qs) ?>&end=<?= h($qe) ?>&source_id=<?= (int) $viewSourceId ?><?= $rangeLinkTail ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form class="toolbar" id="dashboardFilters" method="get">
        <?php if ($isGrowthView): ?>
        <div class="filter-control" title="<?= h(t('Počítají se jen uzavřené měsíce, rozběhnutý měsíc je v tabulce zvlášť jako „zatím“.')) ?>">
            <i data-lucide="calendar-days"></i>
            <span class="growth-range-label"><?= h($growth['window']['rangeLabel']) ?> <small>· <?= h(t('měsíčně')) ?></small></span>
        </div>
        <input type="hidden" name="view" value="growth">
        <input type="hidden" name="months" value="<?= (int) $growth['window']['n'] ?>">
        <?php else: ?>
        <div class="filter-control date-range" data-date-range>
            <i data-lucide="calendar-days"></i>
            <button type="button" class="date-range-trigger" data-date-range-trigger aria-haspopup="dialog" aria-expanded="false">
                <span data-date-range-label><?= h($rangeLabel) ?></span>
                <span class="date-range-chev" aria-hidden="true"><i data-lucide="chevron-down"></i></span>
            </button>
            <input type="hidden" name="start" value="<?= h($data['range']['start']) ?>" data-date-start>
            <input type="hidden" name="end" value="<?= h($data['range']['end']) ?>" data-date-end>
            <div class="date-range-pop" data-date-range-pop role="dialog" aria-label="<?= h(t('Výběr období')) ?>" hidden></div>
        </div>
        <?php if ($isFunnelView): ?>
        <input type="hidden" name="view" value="funnel">
        <input type="hidden" name="funnel_id" value="<?= (int) $funnel['id'] ?>">
        <input type="hidden" name="breakdown" value="<?= h($funnelBreakdownKey) ?>">
        <?php elseif (!$isGenericView): ?><input type="hidden" name="view" value="overview"><?php endif; ?>
        <?php endif; ?>

        <input type="hidden" name="domain_id" value="<?= (int) $data['domain']['id'] ?>">

        <?php
        // Group connected providers by category so organic social and Meta Ads (PPC) are
        // clearly separated; each connection is one option, labelled by its account/page name.
        $catGroupCz = ['social' => t('Sociální sítě'), 'ppc' => t('Reklama (PPC)'), 'analytics' => t('Analytika'), 'seo' => 'SEO'];
        $genericByGroup = [];
        foreach ($providerOptions as $opt) {
            if (empty($opt['generic'])) { continue; }
            $groupLabel = $catGroupCz[$opt['category']] ?? ($opt['categoryLabel'] ?? t('Ostatní'));
            $genericByGroup[$groupLabel][] = $opt;
        }
        ?>
        <?php
        // Vlastní listbox místo <select> — nativní <option> neumí SVG ikony providerů.
        $pickerLabel = $isGenericView ? $selectedProvider['label'] . ', ' . $selectedProvider['name'] : ($isGrowthView ? t('Růst kanálů') : ($isFunnelView ? t('Trychtýř: {name}', ['name' => $funnel['name']]) : t('Přehled (GA4 + GSC)')));
        $pickerIcon = $isGrowthView ? '<i data-lucide="trending-up"></i>' : ($isFunnelView ? '<i data-lucide="filter"></i>' : allstat_provider_icon_svg($isGenericView ? (string) $selectedProvider['provider_key'] : 'overview'));
        $isOverviewSel = !$isGenericView && !$isGrowthView && !$isFunnelView;
        ?>
        <div class="filter-control source-picker" data-source-picker data-domain="<?= (int) $data['domain']['id'] ?>" data-start="<?= $isGrowthView ? '' : h($data['range']['start']) ?>" data-end="<?= $isGrowthView ? '' : h($data['range']['end']) ?>">
            <button type="button" class="source-picker-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="<?= h(t('Zdroj dat')) ?>">
                <span class="source-picker-ic"><?= $pickerIcon ?></span>
                <span class="source-picker-label"><?= h($pickerLabel) ?></span>
                <i data-lucide="chevron-down"></i>
            </button>
            <div class="source-picker-menu" role="listbox" aria-label="<?= h(t('Zdroj dat')) ?>" hidden>
                <button type="button" role="option" class="source-picker-item<?= $isGrowthView ? ' is-selected' : '' ?>" aria-selected="<?= $isGrowthView ? 'true' : 'false' ?>" data-value="0" data-view="growth">
                    <span class="source-picker-ic"><i data-lucide="trending-up"></i></span>
                    <span><?= h(t('Růst kanálů')) ?> <small>· <?= h(t('všechny kanály po měsících')) ?></small></span>
                </button>
                <button type="button" role="option" class="source-picker-item<?= $isOverviewSel ? ' is-selected' : '' ?>" aria-selected="<?= $isOverviewSel ? 'true' : 'false' ?>" data-value="0" data-view="overview">
                    <span class="source-picker-ic"><?= allstat_provider_icon_svg('overview') ?></span>
                    <span><?= h(t('Přehled (GA4 + GSC)')) ?></span>
                </button>
                <?php if ($funnelOptions): ?>
                    <div class="source-picker-group"><?= h(t('Trychtýře')) ?></div>
                    <?php foreach ($funnelOptions as $fOpt): $fSel = $isFunnelView && (int) $fOpt['id'] === (int) $funnel['id']; $fCount = count($fOpt['steps']); ?>
                        <button type="button" role="option" class="source-picker-item<?= $fSel ? ' is-selected' : '' ?>" aria-selected="<?= $fSel ? 'true' : 'false' ?>" data-value="0" data-view="funnel" data-funnel="<?= (int) $fOpt['id'] ?>">
                            <span class="source-picker-ic"><i data-lucide="filter"></i></span>
                            <span><?= h($fOpt['name']) ?> <small>· <?= h(tn($fCount, '{count} krok', '{count} kroky', '{count} kroků')) ?></small></span>
                        </button>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php foreach ($genericByGroup as $groupLabel => $opts): ?>
                    <div class="source-picker-group"><?= h($groupLabel) ?></div>
                    <?php foreach ($opts as $opt): $optSelected = $viewSourceId === (int) $opt['connection_id']; ?>
                        <button type="button" role="option" class="source-picker-item<?= $optSelected ? ' is-selected' : '' ?>" aria-selected="<?= $optSelected ? 'true' : 'false' ?>" data-value="<?= (int) $opt['connection_id'] ?>">
                            <span class="source-picker-ic"><?= allstat_provider_icon_svg((string) $opt['provider_key']) ?></span>
                            <span><?= h($opt['label']) ?> <small>· <?= h($opt['name']) ?></small></span>
                        </button>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>

        <input type="hidden" name="source_id" value="<?= (int) $viewSourceId ?>">
    </form>
</div>

<?php // Hlášky z akcí, které se vrací na dashboard (např. import CSV z rozbalovacího boxu u FB/IG). ?>
<?php foreach (($_SESSION['flashes'] ?? []) as $flash): ?>
    <div class="callout <?= ($flash['type'] ?? '') === 'error' ? 'tone-down' : 'tone-up' ?>" role="status"><?= h((string) ($flash['message'] ?? '')) ?></div>
<?php endforeach; unset($_SESSION['flashes']); ?>

<div id="shareResult" class="share-result" hidden>
    <div class="share-result-head"><strong><?= h(t('Report pro AI')) ?></strong> <span data-share-expiry></span><button type="button" class="share-close" data-share-close aria-label="<?= h(t('Zavřít')) ?>">×</button></div>
    <p class="share-hint"><?= t('<strong>Klikni „Kopírovat text pro AI"</strong> a vlož do ChatGPT / Claude / Perplexity. Text přečte každá AI hned. Report platí max 15 minut. Odkaz níže je jen pro ruční použití, běžný chat ho sám neotevře.') ?></p>
    <div style="margin-bottom:8px;"><button type="button" class="button-primary" data-share-copy-text><?= h(t('Kopírovat text pro AI')) ?></button></div>
    <div class="share-url-row"><input type="text" readonly data-share-url aria-label="<?= h(t('Odkaz (pro ruční použití)')) ?>"></div>
    <div class="share-fmt"><?= h(t('Formát:')) ?> <a href="#" data-share-fmt="md">Markdown</a> · <a href="#" data-share-fmt="json">JSON</a></div>
</div>
