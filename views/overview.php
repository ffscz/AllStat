<?php
/**
 * Přehled GA4 + Search Console v index.php (?view=overview). Data: $data z allstat_get_dashboard_data();
 * dlaždice a tabulky po změně období obnovuje AJAX (assets/js/app.js, api/dashboard.php).
 */
$fmtRangeDate = static fn (string $iso): string => allstat_date_label($iso);
$previousRangeLabel = !empty($data['previousRange'])
    ? $fmtRangeDate($data['previousRange']['start']) . ' – ' . $fmtRangeDate($data['previousRange']['end'])
    : '';
// KPI seskupené do kategorií (Návštěvnost / Zapojení / Konverze / SEO / AI) místo jedné mřížky
// 14 karet — ze 14 metrik se pak čte 5 skupin. AJAX update (app.js) hledá karty přes
// [data-kpi-card] globálně, takže seskupení do sekcí nic nerozbije.
$kpiGroups = [];
foreach ($data['kpis'] as $kpi) { $kpiGroups[$kpi['group'] ?? ''][] = $kpi; }
?>
<div class="overview-top">
<div class="overview-kpis">
<?php foreach ($kpiGroups as $groupName => $groupKpis): ?>
<section class="kpi-group" aria-label="<?= h($groupName !== '' ? $groupName : 'KPI') ?>">
    <?php if ($groupName !== ''): ?><h2 class="kpi-group-title"><?= h($groupName) ?></h2><?php endif; ?>
    <div class="kpi-grid">
        <?php foreach ($groupKpis as $kpi): ?>
        <article class="kpi-card kpi-<?= h($kpi['color']) ?>" data-kpi-card="<?= h($kpi['key']) ?>">
            <div class="kpi-head">
                <span class="kpi-icon"><i data-lucide="<?= h($kpi['icon']) ?>"></i></span>
                <span><strong data-kpi-label><?= h($kpi['label']) ?></strong> <small>(<?= h($kpi['provider']) ?>)</small></span>
                <?php if (!empty($kpi['tooltip'])): ?>
                    <span class="kpi-help" tabindex="0" aria-label="<?= h(t('Vysvětlení metriky')) ?>">
                        <i data-lucide="info"></i>
                        <span class="kpi-help-popover"><?= h($kpi['tooltip']) ?></span>
                    </span>
                <?php endif; ?>
            </div>
            <div class="kpi-value" data-kpi-value><?= h($kpi['displayValue']) ?></div>
            <div class="kpi-meta">
                <?php if ($kpi['trend'] === 'none'): ?>
                    <span class="trend trend-none" data-kpi-change><?= h(t('bez srovnání')) ?></span>
                    <span>(<?= h(t('předchozí období nemá data')) ?>)</span>
                <?php else: ?>
                    <span class="trend <?= $kpi['trend'] === 'up' ? 'trend-up' : 'trend-down' ?>" data-kpi-change><?= $kpi['trend'] === 'up' ? '&uarr;' : '&darr;' ?> <?= h($kpi['changeLabel']) ?></span>
                    <span data-prev-range title="<?= h(t('Změna oproti předchozímu období: celé kalendářní měsíce proti předchozím celým měsícům (září proti srpnu), rozběhnutý měsíc proti stejným dnům minulého měsíce, jinak proti stejně dlouhému období těsně před (u „7 dní" předchozích 7 dní, ne loňský rok).')) ?>"><?= h(t('vs. předchozí období')) ?><?= $previousRangeLabel !== '' ? ' (' . h($previousRangeLabel) . ')' : '' ?></span>
                <?php endif; ?>
            </div>
            <div class="sparkline-wrap"><canvas data-sparkline="<?= h($kpi['key']) ?>"></canvas></div>
        </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endforeach; ?>
</div>
<aside class="overview-rail" aria-label="<?= h(t('Grafy')) ?>">
    <article class="panel panel-wide">
        <div class="panel-header">
            <h2><?= h(t('Návštěvy')) ?></h2>
            <span class="chart-interval" aria-label="<?= h(t('Interval grafu')) ?>" title="<?= h(t('Interval se volí automaticky podle délky období')) ?>"><i data-lucide="calendar-range"></i><?= h(t('Interval:')) ?> <strong><?= h($granularityLabels[$data['granularity']] ?? t('Denně')) ?></strong></span>
        </div>
        <p class="panel-help"><?= t('Trend návštěv (modrá, GA4 sessions), uživatelů (zelená, GA4 active users) a konverzí (fialová, GA4 key events, čte se na <strong>pravé ose</strong>, protože bývají v jiném řádu). Interval grafu se volí <strong>automaticky</strong> podle délky období (krátké → denně, delší → týdně/měsíčně).') ?></p>
        <div class="chart-frame chart-frame-line"><canvas id="visitsChart"></canvas></div>
    </article>

    <article class="panel">
        <div class="panel-header">
            <h2><?= h(t('Zdroje návštěv')) ?> <span>(GA4)</span></h2>
            <span class="panel-total"><strong data-traffic-total><?= h($data['charts']['traffic']['totalLabel']) ?></strong> <?= h(t('celkem')) ?></span>
        </div>
        <p class="panel-help"><?= t('Rozdělení návštěv podle kanálu (Organic Search, Direct, Referral, Social…). Data z GA4 dimenze <code>sessionDefaultChannelGroup</code>. Délka pruhu = poměr k největšímu kanálu.') ?></p>
        <?php
        $barPalette = ['#2563eb', '#16a34a', '#8b5cf6', '#f59e0b', '#06b6d4', '#f43f5e'];
        $trafficRows = $data['charts']['traffic']['sources'] ?? [];
        $trafficMax = 1;
        foreach ($trafficRows as $r) { $trafficMax = max($trafficMax, (int) $r['sessions']); }
        ?>
        <div class="bar-chart" data-bar-traffic>
            <?php foreach ($trafficRows as $i => $r): ?>
                <div class="bar-row">
                    <span class="bar-row-label" title="<?= h($r['source']) ?>"><?= h($r['source']) ?></span>
                    <div class="bar-row-track"><div class="bar-row-fill" style="width: <?= round(max(2, (int) $r['sessions'] / $trafficMax * 100), 1) ?>%; background: <?= $barPalette[$i % 6] ?>;"></div></div>
                    <span class="bar-row-value"><strong><?= h($r['sessionsLabel']) ?></strong> <small>(<?= h($r['shareLabel']) ?>)</small></span>
                </div>
            <?php endforeach; ?>
            <?php if (!$trafficRows): ?><p class="table-muted"><?= h(t('Zatím bez dat o kanálech, spusť GA4 sync.')) ?></p><?php endif; ?>
        </div>
        <a class="text-link" href="admin/reports.php?domain_id=<?= (int) $data['domain']['id'] ?>&start=<?= h($data['range']['start']) ?>&end=<?= h($data['range']['end']) ?>#traffic-sources"><?= h(t('Zobrazit celý report')) ?> <i data-lucide="arrow-right"></i></a>
    </article>

    <article class="panel">
        <div class="panel-header">
            <h2><?= h(t('Zařízení')) ?> <span>(GA4)</span></h2>
            <span class="panel-total"><strong data-device-total><?= h($data['charts']['devices']['totalLabel'] ?? '0') ?></strong> <?= h(t('návštěv')) ?></span>
        </div>
        <p class="panel-help"><?= t('Rozdělení návštěv podle typu zařízení (GA4 dimenze <code>deviceCategory</code>). Mobil / Desktop / Tablet.') ?></p>
        <?php
        $deviceRows = $data['charts']['devices']['items'] ?? [];
        $deviceVals = $data['charts']['devices']['values'] ?? [];
        $deviceMax = 1;
        foreach ($deviceRows as $i => $r) { $deviceMax = max($deviceMax, (int) ($r['sessions'] ?? ($deviceVals[$i] ?? 0))); }
        ?>
        <div class="bar-chart" data-bar-devices>
            <?php foreach ($deviceRows as $i => $r): $v = (int) ($r['sessions'] ?? ($deviceVals[$i] ?? 0)); ?>
                <div class="bar-row">
                    <span class="bar-row-label" title="<?= h($r['device']) ?>"><?= h($r['device']) ?></span>
                    <div class="bar-row-track"><div class="bar-row-fill" style="width: <?= round(max(2, $v / $deviceMax * 100), 1) ?>%; background: <?= $barPalette[$i % 6] ?>;"></div></div>
                    <span class="bar-row-value"><strong><?= h($r['sessionsLabel']) ?></strong> <small>(<?= h($r['shareLabel']) ?>)</small></span>
                </div>
            <?php endforeach; ?>
            <?php if (!$deviceRows): ?><p class="table-muted"><?= h(t('Zatím bez dat o zařízeních, spusť GA4 sync.')) ?></p><?php endif; ?>
        </div>
    </article>

    <article class="panel">
        <div class="panel-header">
            <h2><?= h(t('Noví vs vracející se')) ?> <span>(GA4)</span></h2>
            <span class="panel-total"><strong data-newreturning-total><?= h($data['charts']['newReturning']['totalLabel'] ?? '0') ?></strong> <?= h(t('uživatelů')) ?></span>
        </div>
        <p class="panel-help"><?= t('Poměr nových a vracejících se uživatelů za období. Vracející se = uživatelé − noví uživatelé (GA4 <code>newUsers</code>).') ?></p>
        <?php
        $nrRows = $data['charts']['newReturning']['segments'] ?? [];
        $nrVals = $data['charts']['newReturning']['values'] ?? [];
        $nrMax = 1;
        foreach ($nrRows as $i => $s) { $nrMax = max($nrMax, (int) ($s['value'] ?? ($nrVals[$i] ?? 0))); }
        ?>
        <div class="bar-chart" data-bar-newreturning>
            <?php foreach ($nrRows as $i => $s): $v = (int) ($s['value'] ?? ($nrVals[$i] ?? 0)); ?>
                <div class="bar-row">
                    <span class="bar-row-label" title="<?= h($s['label']) ?>"><?= h($s['label']) ?></span>
                    <div class="bar-row-track"><div class="bar-row-fill" style="width: <?= round(max(2, $v / $nrMax * 100), 1) ?>%; background: <?= $barPalette[$i % 6] ?>;"></div></div>
                    <span class="bar-row-value"><strong><?= h($s['valueLabel']) ?></strong> <small>(<?= h($s['shareLabel']) ?>)</small></span>
                </div>
            <?php endforeach; ?>
            <?php if (!$nrRows): ?><p class="table-muted"><?= h(t('Zatím bez dat, spusť GA4 sync.')) ?></p><?php endif; ?>
        </div>
    </article>
</aside>
</div>

<section class="tables-grid" aria-label="<?= h(t('Tabulky')) ?>">
    <article class="panel table-panel">
        <div class="panel-header"><h2><?= h(t('Top stránky')) ?></h2><div class="seg-toggle" role="group" aria-label="<?= h(t('Typ stránek')) ?>"><button type="button" class="seg-btn is-active" data-pages-toggle="vstupni"><?= h(t('Vstupní')) ?></button><button type="button" class="seg-btn" data-pages-toggle="vsechny"><?= h(t('Všechny')) ?></button></div></div>
        <p class="panel-help" data-pages-help><?= t('Top vstupní stránky, kde návštěva začala. GA4 dimenze <code>landingPage</code>. „Změna" = rozdíl proti předchozímu období (u celého měsíce proti předchozímu měsíci, jinak proti stejně dlouhému období těsně před). Přepni na „Všechny" pro nejnavštěvovanější stránky podle zobrazení.') ?></p>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr data-pages-head><th><?= h(t('Stránka')) ?></th><th><?= h(t('Návštěvy')) ?></th><th><?= h(t('Změna')) ?></th></tr>
                </thead>
                <tbody data-pages-rows>
                    <?php foreach ($data['tables']['landingPages'] as $page):
                        $changeClass = $page['change'] === null ? '' : ($page['change'] >= 0 ? 'positive' : 'negative');
                        $arrow = $page['change'] === null ? '' : ($page['change'] >= 0 ? '&uarr;' : '&darr;');
                    ?>
                        <tr>
                            <td><?= h($page['path']) ?></td>
                            <td><?= h(allstat_number($page['sessions'])) ?></td>
                            <td class="<?= $changeClass ?>"><?= $arrow ?> <?= h($page['changeLabel']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <a class="text-link" href="admin/reports.php?domain_id=<?= (int) $data['domain']['id'] ?>&start=<?= h($data['range']['start']) ?>&end=<?= h($data['range']['end']) ?>#pages"><?= h(t('Zobrazit všechny stránky')) ?> <i data-lucide="arrow-right"></i></a>
    </article>

    <article class="panel table-panel">
        <div class="panel-header"><h2><?= h(t('Nejčastější vyhledávací dotazy')) ?> <span>(GSC)</span></h2></div>
        <p class="panel-help"><?= t('Co lidé hledali v Googlu předtím, než klikli na web. Z Google Search Console, vyplní se až po napojení GSC v <a href="admin/sources.php">Zdrojích dat</a>.') ?></p>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr><th><?= h(t('Dotaz')) ?></th><th><?= h(t('Kliknutí')) ?></th><th><?= h(t('Zobrazení')) ?></th><th>CTR</th></tr>
                </thead>
                <tbody data-query-rows>
                    <?php foreach ($data['tables']['queries'] as $query): ?>
                        <tr>
                            <td><?= h($query['query']) ?></td>
                            <td><?= h(allstat_number($query['clicks'])) ?></td>
                            <td><?= h(allstat_number($query['impressions'])) ?></td>
                            <td><?= h($query['ctrLabel']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <a class="text-link" href="admin/reports.php?domain_id=<?= (int) $data['domain']['id'] ?>&start=<?= h($data['range']['start']) ?>&end=<?= h($data['range']['end']) ?>#queries"><?= h(t('Zobrazit všechny dotazy')) ?> <i data-lucide="arrow-right"></i></a>
    </article>

    <article class="panel table-panel">
        <div class="panel-header"><h2><?= h(t('Konkrétní referrery')) ?> <span>(GA4)</span></h2></div>
        <p class="panel-help"><?= t('Reálné odkazující weby (GA4 dimenze <code>sessionSource</code>, medium = referral). Návštěvy z AI asistentů jsou označené odznakem <span class="status-badge status-ai">AI</span>.') ?></p>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr><th>Referrer</th><th><?= h(t('Návštěvy')) ?></th><th><?= h(t('Podíl')) ?></th></tr>
                </thead>
                <tbody data-referrer-rows>
                    <?php foreach ($data['tables']['referrers'] as $ref): ?>
                        <tr>
                            <td><?= h($ref['source']) ?><?= !empty($ref['ai']) ? ' <span class="status-badge status-ai">AI · ' . h($ref['ai']) . '</span>' : '' ?></td>
                            <td><?= h($ref['sessionsLabel']) ?></td>
                            <td><?= h($ref['shareLabel']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$data['tables']['referrers']): ?>
                        <tr><td colspan="3" class="table-muted"><?= h(t('Zatím bez referral dat, spusť GA4 sync.')) ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>

    <article class="panel table-panel">
        <div class="panel-header"><h2><?= h(t('Návštěvy podle regionu')) ?> <span>(GA4)</span></h2></div>
        <p class="panel-help"><?= t('Odkud návštěvníci jsou (GA4 dimenze <code>region</code>). Ukazuje, jestli zasahuješ správnou oblast.') ?></p>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr><th><?= h(t('Region')) ?></th><th><?= h(t('Země')) ?></th><th><?= h(t('Návštěvy')) ?></th><th><?= h(t('Podíl')) ?></th></tr>
                </thead>
                <tbody data-geo-rows>
                    <?php foreach ($data['tables']['geo'] as $geo): ?>
                        <tr>
                            <td><?= h($geo['region']) ?></td>
                            <td><?= h($geo['country']) ?></td>
                            <td><?= h($geo['sessionsLabel']) ?></td>
                            <td><?= h($geo['shareLabel']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$data['tables']['geo']): ?>
                        <tr><td colspan="4" class="table-muted"><?= h(t('Zatím bez geo dat, spusť sync.')) ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>

    <article class="panel table-panel">
        <div class="panel-header"><h2><?= h(t('Klíčové akce')) ?> <span>(GA4)</span></h2></div>
        <p class="panel-help"><?= t('Které události uživatelé spouští (GA4 <code>eventName</code>). „Key events" = akce označené jako konverze (registrace, kontakt, stažení…).') ?></p>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr><th><?= h(t('Akce')) ?></th><th><?= h(t('Počet')) ?></th><th><?= h(t('Z toho key events')) ?></th></tr>
                </thead>
                <tbody data-event-rows>
                    <?php foreach ($data['tables']['events'] as $event): ?>
                        <tr>
                            <td><?= h($event['event']) ?><?= $event['isKey'] ? ' <span class="status-badge status-ok">key</span>' : '' ?></td>
                            <td><?= h($event['countLabel']) ?></td>
                            <td><?= h($event['keyEventsLabel']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$data['tables']['events']): ?>
                        <tr><td colspan="3" class="table-muted"><?= h(t('Zatím bez event dat, spusť sync.')) ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>

    <?php $utmCampaigns = $data['tables']['utm'] ?? []; ?>
    <article class="panel table-panel">
        <div class="panel-header"><h2><?= h(t('Kampaně (UTM)')) ?> <span>(GA4)</span></h2></div>
        <p class="panel-help"><?= t('Návštěvy z odkazů označených UTM parametry (<code>utm_campaign</code>, <code>utm_source</code>…), funguje pro Meta reklamy, newslettery i jakýkoli otagovaný odkaz. Štítek „varianta" (<code>utm_content</code>) odlišuje A/B verze stejné kampaně. Organická a přímá návštěvnost se sem nezahrnuje.') ?></p>
        <div class="utm-list" data-utm-list>
            <?php foreach ($utmCampaigns as $c): ?>
                <div class="utm-item">
                    <div class="utm-item-top">
                        <span class="utm-name" title="utm_campaign"><?= h($c['campaignLabel']) ?></span>
                        <span class="utm-value"><strong><?= h($c['sessionsLabel']) ?></strong> <small><?= h(t('návštěv')) ?> · <?= h($c['shareLabel']) ?></small></span>
                    </div>
                    <div class="utm-track"><div class="utm-fill" style="width: <?= round(max(3, (float) $c['share']), 1) ?>%;"></div></div>
                    <div class="utm-tags">
                        <span class="utm-chip" title="utm_source / utm_medium"><?= h($c['channelLabel']) ?></span>
                        <?php if ($c['contentLabel'] !== '—'): ?><span class="utm-chip" title="<?= h(t('utm_content, A/B varianta')) ?>"><?= h(t('varianta: {value}', ['value' => $c['contentLabel']])) ?></span><?php endif; ?>
                        <span class="utm-chip<?= (int) $c['conversions'] > 0 ? ' utm-chip-ok' : '' ?>" title="<?= h(t('Konverze z této kampaně (a podíl z jejích návštěv)')) ?>"><?= h(t('konverze: {value}', ['value' => $c['conversionsLabel'] . ($c['convRate'] !== '—' ? ' (' . $c['convRate'] . ')' : '')])) ?></span>
                    </div>
                    <?php if ($c['pages']): ?>
                        <div class="utm-pages"><?= h(t('Kam přišli:')) ?> <?= implode(' · ', array_map(static fn (array $p): string => h($p['path']) . ' <span class="table-muted">(' . h($p['sessionsLabel']) . ')</span>', $c['pages'])) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (!$utmCampaigns): ?>
                <p class="table-muted"><?= t('Zatím žádná návštěvnost s UTM parametry za zvolené období. Otaguj odkazy (např. <code>?utm_campaign=jaro&amp;utm_source=facebook&amp;utm_medium=cpc&amp;utm_content=banner1</code>) a po dalším GA4 syncu se kampaně objeví zde.') ?></p>
            <?php endif; ?>
        </div>
    </article>

    <?php $funnel = $data['tables']['funnel'] ?? []; if (!empty($funnel['hasData'])): ?>
    <article class="panel table-panel table-panel-wide">
        <div class="panel-header"><h2><?= h(t('Nákupní trychtýř')) ?> <span>(e-commerce)</span></h2></div>
        <p class="panel-help"><?= t('Kroky nákupu z GA4 e-commerce událostí za období. <strong>Pokles</strong> = kolik % lidí mezi kroky odpadlo; největší pokles je <strong>úzké hrdlo</strong>.') ?> <?= h($funnel['overallLabel']) ?><?= $funnel['removeFromCart'] > 0 ? ' · ' . h(t('Odebrání z košíku: {value}', ['value' => $funnel['removeFromCartLabel']])) : '' ?>.</p>
        <div class="table-scroll">
            <table>
                <thead><tr><th><?= h(t('Krok')) ?></th><th><?= h(t('Počet')) ?></th><th><?= h(t('Podíl z vrcholu')) ?></th><th><?= h(t('Pokles vs. předchozí')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($funnel['steps'] as $step): ?>
                        <tr>
                            <td><?= h($step['label']) ?><?= $step['isBottleneck'] ? ' <span class="status-badge status-warning">' . h(t('úzké hrdlo')) . '</span>' : '' ?></td>
                            <td><?= h($step['countLabel']) ?></td>
                            <td>
                                <span style="display:inline-block;height:8px;border-radius:4px;background:#6366f1;width:<?= (int) round($step['share']) ?>%;min-width:3px;vertical-align:middle"></span>
                                <?= h($step['shareLabel']) ?>
                            </td>
                            <td><?= $step['dropLabel'] !== '' ? h($step['dropLabel']) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>
    <?php endif; ?>

    <?php $products = $data['tables']['topProducts'] ?? []; if ($products): ?>
    <article class="panel table-panel table-panel-wide">
        <div class="panel-header"><h2><?= h(t('Top produkty')) ?> <span>(e-commerce)</span></h2></div>
        <p class="panel-help"><?= t('Nejprodávanější produkty podle tržeb (GA4 <code>itemName</code>). <strong>Konverze</strong> = kolik % zobrazení produktu skončilo nákupem.') ?></p>
        <div class="table-scroll">
            <table>
                <thead><tr><th><?= h(t('Produkt')) ?></th><th><?= h(t('Zobrazení')) ?></th><th><?= h(t('Do košíku')) ?></th><th><?= h(t('Prodáno')) ?></th><th><?= h(t('Tržby')) ?></th><th><?= h(t('Konverze')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($products as $p): ?>
                        <tr>
                            <td><?= h($p['name']) ?></td>
                            <td><?= h($p['viewedLabel']) ?></td>
                            <td><?= h($p['addedLabel']) ?></td>
                            <td><?= h($p['purchasedLabel']) ?></td>
                            <td><?= h($p['revenueLabel']) ?></td>
                            <td><?= h($p['buyRateLabel']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>
    <?php endif; ?>

    <?php $demo = $data['tables']['demographics'] ?? []; if (!empty($demo['hasData'])): ?>
    <article class="panel table-panel">
        <div class="panel-header"><h2><?= h(t('Věk')) ?> <span>(GA4)</span></h2></div>
        <p class="panel-help"><?= t('Věkové složení publika, <strong>modelovaný odhad</strong> (Google Signals). GA4 malé segmenty skrývá kvůli ochraně soukromí, takže součet nemusí dát 100 % a část je „Neurčeno".') ?></p>
        <div class="table-scroll">
            <table>
                <thead><tr><th><?= h(t('Věk')) ?></th><th><?= h(t('Uživatelé')) ?></th><th><?= h(t('Podíl')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($demo['age'] as $a): ?>
                        <tr><td><?= h($a['label']) ?></td><td><?= h($a['usersLabel']) ?></td><td><?= h($a['shareLabel']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$demo['age']): ?><tr><td colspan="3" class="table-muted"><?= h(t('Bez věkových dat (GA4 práh / Signals).')) ?></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
    <article class="panel table-panel">
        <div class="panel-header"><h2><?= h(t('Pohlaví')) ?> <span>(GA4)</span></h2></div>
        <p class="panel-help"><?= h(t('Rozdělení publika podle pohlaví, modelovaný odhad (Google Signals). Slouží k cílení na segmenty, ne k identifikaci jednotlivců.')) ?></p>
        <div class="table-scroll">
            <table>
                <thead><tr><th><?= h(t('Pohlaví')) ?></th><th><?= h(t('Uživatelé')) ?></th><th><?= h(t('Podíl')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($demo['gender'] as $g): ?>
                        <tr><td><?= h($g['label']) ?></td><td><?= h($g['usersLabel']) ?></td><td><?= h($g['shareLabel']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$demo['gender']): ?><tr><td colspan="3" class="table-muted"><?= h(t('Bez dat o pohlaví (GA4 práh / Signals).')) ?></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
    <?php endif; ?>

    <article class="panel table-panel table-panel-wide">
        <div class="panel-header"><h2><?= h(t('Přehled zdrojů dat')) ?></h2></div>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr><th><?= h(t('Zdroj')) ?></th><th><?= h(t('Stav')) ?></th><th><?= h(t('Poslední sync')) ?></th><th></th></tr>
                </thead>
                <tbody data-source-rows>
                    <?php foreach ($data['tables']['sources'] as $source): ?>
                        <tr>
                            <td><?= h($source['source']) ?></td>
                            <td><span class="status-badge status-<?= h($source['status']) ?>"><?= h($statusLabels[$source['status']] ?? $source['status']) ?></span></td>
                            <td><?= h($source['lastSync']) ?></td>
                            <td><button class="table-icon-button" type="button" title="<?= h(t('Obnovit')) ?>" aria-label="<?= h(t('Obnovit')) ?>" data-refresh-dashboard><i data-lucide="refresh-cw"></i></button></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$data['tables']['sources']): ?>
                        <tr><td colspan="4" class="table-muted"><?= h(t('Zatím není napojený žádný zdroj dat.')) ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <a class="text-link" href="admin/sources.php?domain_id=<?= (int) $data['domain']['id'] ?>"><?= h(t('Správa zdrojů')) ?> <i data-lucide="arrow-right"></i></a>
    </article>
</section>
