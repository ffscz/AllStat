<?php
/**
 * Přehled GA4 + Search Console v index.php (?view=overview). Data: $data z allstat_get_dashboard_data();
 * záložky, řádky, měřáky a tabulky po změně období obnovuje AJAX (assets/js/app.js, api/dashboard.php)
 * podle atributů data-kpi-card, data-meters, data-stack a data-*-rows.
 */
require_once __DIR__ . '/_ui.php';

$kpiByKey = array_column($data['kpis'], null, 'key');
$prevPeriod = !empty($data['previousRange']) ? allstat_ui_period_label($data['previousRange']['start'], $data['previousRange']['end']) : '';
$periodLabel = allstat_ui_period_label($data['range']['start'], $data['range']['end']);
// Metriky, u kterých je lepší nižší hodnota (barva změny se otáčí). Stejný seznam má app.js (LOWER_IS_BETTER).
$lowerIsBetter = ['bounce_rate'];
$deltaText = static fn (array $kpi): string => $kpi['trend'] === 'none' ? t('bez srovnání') : (string) $kpi['changeLabel'];
$deltaClass = static fn (array $kpi): string => allstat_ui_delta_class($kpi['trend'] === 'none' ? null : (float) $kpi['change'], !in_array($kpi['key'], $lowerIsBetter, true));
$kpiRow = static function (string $key) use ($kpiByKey, $deltaText, $deltaClass): ?array {
    $kpi = $kpiByKey[$key] ?? null;
    if (!$kpi) {
        return null;
    }

    return [
        'label' => $kpi['label'], 'value' => $kpi['displayValue'], 'delta' => $deltaText($kpi), 'deltaClass' => $deltaClass($kpi),
        'title' => $kpi['tooltip'] ?? '', 'attr' => 'data-kpi-card="' . h($key) . '"', 'valueAttr' => 'data-kpi-value', 'deltaAttr' => 'data-kpi-change',
    ];
};
$reportUrl = static fn (string $anchor): string => 'admin/reports.php?domain_id=' . (int) $data['domain']['id'] . '&amp;start=' . h($data['range']['start']) . '&amp;end=' . h($data['range']['end']) . '#' . $anchor;
$moreLink = static fn (string $href, string $label): string => '<a class="link-more" href="' . $href . '">' . h($label) . allstat_icon('arrow-right', 'icon-sm') . '</a>';
$prevNote = $prevPeriod !== '' ? t('vs. {period}', ['period' => $prevPeriod]) : '';
?>
<section class="card card-clip" aria-label="<?= h(t('Vývoj metriky v čase')) ?>" data-metric-chart="overview">
    <div class="tabs" role="tablist" aria-label="<?= h(t('Metrika v grafu')) ?>">
        <?php foreach (['visits', 'users', 'conversions', 'clicks'] as $i => $key): $kpi = $kpiByKey[$key] ?? null; if (!$kpi) { continue; } ?>
            <?= allstat_ui_metric_tab($key, $key === 'clicks' ? t('Kliknutí z Googlu') : $kpi['label'], $kpi['displayValue'],
                '<span class="' . $deltaClass($kpi) . '" data-kpi-change>' . h($deltaText($kpi)) . '</span>'
                . ($kpi['trend'] !== 'none' && $prevNote !== '' ? '<span data-prev-range>' . h($prevNote) . '</span>' : ''),
                $i === 0, (string) ($kpi['tooltip'] ?? '')) ?>
        <?php endforeach; ?>
    </div>
    <?= allstat_ui_metric_chart('metricChart', $periodLabel) ?>
</section>

<div class="grid grid-3">
    <section class="card">
        <?= allstat_ui_card_head(t('Zapojení'), 'GA4', h(t('Kvalita návštěv a konverze.'))) ?>
        <div class="card-body">
            <?php
            $engagementKeys = ['new_users', 'engagement_rate', 'bounce_rate', 'avg_engagement_time', 'conversion_rate'];
            if ((float) ($kpiByKey['revenue']['value'] ?? 0) > 0) {
                $engagementKeys[] = 'revenue';
            }
            ?>
            <?= allstat_ui_rows(array_values(array_filter(array_map($kpiRow, $engagementKeys)))) ?>
        </div>
    </section>
    <section class="card">
        <?= allstat_ui_card_head(t('Vyhledávání'), 'Search Console', h(t('Jak web vidí lidé v Googlu.'))) ?>
        <div class="card-body"><?= allstat_ui_rows(array_values(array_filter(array_map($kpiRow, ['clicks', 'impressions', 'ctr'])))) ?></div>
    </section>
    <section class="card">
        <?= allstat_ui_card_head(t('AI vyhledávání'), 'GA4', h(t('Návštěvy z AI asistentů.'))) ?>
        <div class="card-body">
            <?php
            $aiTop = $data['tables']['aiSources'][0] ?? null;
            $aiRows = array_values(array_filter(array_map($kpiRow, ['ai_sessions', 'ai_share'])));
            $aiRows[] = [
                'label' => t('Hlavní zdroj'), 'attr' => 'data-ai-top',
                'valueHtml' => $aiTop ? h($aiTop['source']) : '–',
                'delta' => $aiTop ? tn((int) $aiTop['sessions'], '{count} návštěva', '{count} návštěvy', '{count} návštěv') : '', 'deltaClass' => 'is-muted',
            ];
            ?>
            <?= allstat_ui_rows($aiRows) ?>
        </div>
    </section>
</div>

<div class="grid grid-2">
    <section class="card">
        <?php $traffic = $data['charts']['traffic']['sources'] ?? []; $trafficW = allstat_ui_widths(array_column($traffic, 'sessions')); ?>
        <?= allstat_ui_card_head(t('Zdroje návštěv'), '', h(t('Odkud návštěvníci přišli, podle výchozího seskupení kanálů GA4.')), allstat_ui_card_total(t('{count} celkem', ['count' => $data['charts']['traffic']['totalLabel'] ?? '0']), 'data-traffic-total')) ?>
        <div class="card-body">
            <?= allstat_ui_meters(array_map(static fn (array $r, float $w): array => ['label' => $r['source'], 'value' => $r['sessionsLabel'], 'share' => $r['shareLabel'], 'width' => $w], $traffic, $trafficW), 'data-meters="traffic"', t('Zatím bez dat o kanálech, spusť GA4 sync.')) ?>
            <?= $moreLink($reportUrl('traffic-sources'), t('Zobrazit celý report')) ?>
        </div>
    </section>
    <section class="card">
        <?= allstat_ui_card_head(t('Zařízení a návštěvníci'), '', h(t('Typ zařízení podle návštěv a podíl nových uživatelů.'))) ?>
        <div class="card-body grid">
            <?php
            $devices = $data['charts']['devices']['items'] ?? [];
            $deviceVals = $data['charts']['devices']['values'] ?? [];
            $nr = $data['charts']['newReturning']['segments'] ?? [];
            $nrVals = $data['charts']['newReturning']['values'] ?? [];
            $nrTotal = max(1, array_sum(array_map(static fn ($s, $i) => (int) ($s['value'] ?? ($nrVals[$i] ?? 0)), $nr, array_keys($nr))));
            ?>
            <?= allstat_ui_stack(t('Zařízení'), t('{count} návštěv', ['count' => $data['charts']['devices']['totalLabel'] ?? '0']),
                array_map(static fn (array $d, int $i): array => ['label' => $d['device'], 'value' => $d['sessionsLabel'], 'share' => $d['shareLabel'], 'pct' => (float) ($d['share'] ?? 0)], $devices, array_keys($devices)),
                'data-stack="devices"', t('Zatím bez dat o zařízeních, spusť GA4 sync.')) ?>
            <?= allstat_ui_stack(t('Noví a vracející se'), t('{count} uživatelů', ['count' => $data['charts']['newReturning']['totalLabel'] ?? '0']),
                array_map(static fn (array $s, int $i): array => ['label' => $s['label'], 'value' => $s['valueLabel'], 'share' => $s['shareLabel'], 'pct' => (int) ($s['value'] ?? ($nrVals[$i] ?? 0)) / $nrTotal * 100], $nr, array_keys($nr)),
                'data-stack="newreturning"', t('Zatím bez dat, spusť GA4 sync.')) ?>
        </div>
    </section>
</div>

<div class="grid grid-2">
    <section class="card">
        <?= allstat_ui_card_head(t('Vstupní stránky'), '', '<span data-pages-help>' . h(t('Kde návštěva začala. Změna proti předchozímu období.')) . '</span>',
            '<div class="seg" role="group" aria-label="' . h(t('Typ stránek')) . '"><button type="button" class="seg-item" aria-pressed="true" data-pages-toggle="vstupni">' . h(t('Vstupní')) . '</button><button type="button" class="seg-item" aria-pressed="false" data-pages-toggle="vsechny">' . h(t('Všechny')) . '</button></div>') ?>
        <div class="card-body">
            <div class="table-wrap">
                <table class="table table-plain">
                    <thead><tr data-pages-head><th scope="col"><?= h(t('Stránka')) ?></th><th scope="col" style="width: 84px"><?= h(t('Návštěvy')) ?></th><th scope="col" style="width: 84px"><?= h(t('Konverze')) ?></th><th scope="col" style="width: 84px"><?= h(t('Změna')) ?></th></tr></thead>
                    <tbody data-pages-rows>
                        <?php foreach ($data['tables']['landingPages'] as $page): ?>
                            <tr>
                                <td class="ellipsis" title="<?= h($page['path']) ?>"><?= h($page['path']) ?></td>
                                <td class="num is-strong"><?= h(allstat_number($page['sessions'])) ?></td>
                                <td class="num"><?= h(allstat_number($page['conversions'] ?? 0)) ?></td>
                                <td class="num <?= allstat_ui_delta_class($page['change'] === null ? null : (float) $page['change']) ?>"><?= h($page['changeLabel']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$data['tables']['landingPages']): ?><tr><td colspan="4" class="is-muted"><?= h(t('Zatím bez dat o stránkách, spusť GA4 sync.')) ?></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?= $moreLink($reportUrl('pages'), t('Zobrazit všechny stránky')) ?>
        </div>
    </section>
    <section class="card">
        <?= allstat_ui_card_head(t('Dotazy z Googlu'), 'Search Console', t('Co lidé hledali, než klikli na web. Vyplní se po napojení GSC v <a href="admin/sources.php">Zdrojích dat</a>.')) ?>
        <div class="card-body">
            <div class="table-wrap">
                <table class="table table-plain">
                    <thead><tr><th scope="col"><?= h(t('Dotaz')) ?></th><th scope="col" style="width: 84px"><?= h(t('Kliknutí')) ?></th><th scope="col" style="width: 96px"><?= h(t('Zobrazení')) ?></th><th scope="col" style="width: 72px"><?= h(t('Pozice')) ?></th></tr></thead>
                    <tbody data-query-rows>
                        <?php foreach ($data['tables']['queries'] as $query): ?>
                            <tr>
                                <td class="ellipsis" title="<?= h($query['query']) ?>"><?= h($query['query']) ?></td>
                                <td class="num is-strong"><?= h(allstat_number($query['clicks'])) ?></td>
                                <td class="num"><?= h(allstat_number($query['impressions'])) ?></td>
                                <td class="num" title="CTR <?= h($query['ctrLabel']) ?>"><?= h(allstat_number((float) ($query['position'] ?? 0), 1)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$data['tables']['queries']): ?><tr><td colspan="4" class="is-muted"><?= h(t('Zatím bez dat ze Search Console.')) ?></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?= $moreLink($reportUrl('queries'), t('Zobrazit všechny dotazy')) ?>
        </div>
    </section>
</div>

<div class="grid grid-2">
    <section class="card">
        <?php $geo = $data['tables']['geo']; $geoW = allstat_ui_widths(array_column($geo, 'sessions')); ?>
        <?= allstat_ui_card_head(t('Regiony'), '', h(t('Odkud návštěvníci jsou. Ukazuje, jestli web zasahuje správnou oblast.'))) ?>
        <div class="card-body">
            <?= allstat_ui_meters(array_map(static fn (array $g, float $w): array => ['label' => $g['region'], 'title' => $g['region'] . ', ' . $g['country'], 'value' => $g['sessionsLabel'], 'share' => $g['shareLabel'], 'width' => $w], $geo, $geoW), 'data-meters="geo"', t('Zatím bez geo dat, spusť sync.')) ?>
        </div>
    </section>
    <section class="card">
        <?php $refs = $data['tables']['referrers']; $refW = allstat_ui_widths(array_column($refs, 'sessions')); ?>
        <?= allstat_ui_card_head(t('Odkazující weby'), '', h(t('Konkrétní weby, ze kterých lidé přišli odkazem. Návštěvy z AI asistentů mají štítek AI.'))) ?>
        <div class="card-body">
            <?= allstat_ui_meters(array_map(static fn (array $r, float $w): array => ['label' => $r['source'], 'value' => $r['sessionsLabel'], 'share' => $r['shareLabel'], 'width' => $w,
                'extraHtml' => !empty($r['ai']) ? ' <span class="chip tone-accent">AI · ' . h($r['ai']) . '</span>' : ''], $refs, $refW), 'data-meters="referrers"', t('Zatím bez referral dat, spusť GA4 sync.')) ?>
        </div>
    </section>
</div>

<div class="grid grid-2">
    <section class="card">
        <?= allstat_ui_card_head(t('Klíčové akce'), 'GA4', h(t('Které události uživatelé spouští. Štítek „key“ = akce označená jako konverze.'))) ?>
        <div class="card-body">
            <div class="table-wrap">
                <table class="table table-plain">
                    <thead><tr><th scope="col"><?= h(t('Akce')) ?></th><th scope="col" style="width: 84px"><?= h(t('Počet')) ?></th><th scope="col" style="width: 140px"><?= h(t('Z toho key events')) ?></th></tr></thead>
                    <tbody data-event-rows>
                        <?php foreach ($data['tables']['events'] as $event): ?>
                            <tr>
                                <td class="ellipsis" title="<?= h($event['event']) ?>"><?= h($event['event']) ?><?= $event['isKey'] ? ' <span class="chip tone-up">key</span>' : '' ?></td>
                                <td class="num is-strong"><?= h($event['countLabel']) ?></td>
                                <td class="num"><?= h($event['keyEventsLabel']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$data['tables']['events']): ?><tr><td colspan="3" class="is-muted"><?= h(t('Zatím bez event dat, spusť sync.')) ?></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
    <section class="card">
        <?php $utmCampaigns = $data['tables']['utm'] ?? []; ?>
        <?= allstat_ui_card_head(t('Kampaně (UTM)'), 'GA4', t('Návštěvy z odkazů označených UTM parametry (<code>utm_campaign</code>, <code>utm_source</code>…). Organická a přímá návštěvnost se sem nezahrnuje.')) ?>
        <div class="card-body">
            <?php $utmRow = static function (array $c): array {
                $chips = '<span class="chip" title="utm_source / utm_medium">' . h($c['channelLabel']) . '</span>';
                if ($c['contentLabel'] !== '—') {
                    $chips .= ' <span class="chip" title="' . h(t('utm_content, A/B varianta')) . '">' . h(t('varianta: {value}', ['value' => $c['contentLabel']])) . '</span>';
                }
                $chips .= ' <span class="chip' . ((int) $c['conversions'] > 0 ? ' tone-up' : '') . '" title="' . h(t('Konverze z této kampaně (a podíl z jejích návštěv)')) . '">'
                    . h(t('konverze: {value}', ['value' => $c['conversionsLabel'] . ($c['convRate'] !== '—' ? ' (' . $c['convRate'] . ')' : '')])) . '</span>';
                if ($c['pages']) {
                    $chips .= '<br>' . h(t('Kam přišli:')) . ' ' . implode(' · ', array_map(static fn (array $p): string => h($p['path']) . ' (' . h($p['sessionsLabel']) . ')', $c['pages']));
                }

                return ['label' => $c['campaignLabel'], 'title' => 'utm_campaign: ' . $c['campaignLabel'], 'value' => $c['sessionsLabel'], 'share' => $c['shareLabel'], 'width' => (float) $c['share'], 'noteHtml' => $chips];
            }; ?>
            <?php // Prvních 8 kampaní hned, zbytek v rozbalovacím seznamu (stejně skládá app.js po AJAX obnově). ?>
            <?= allstat_ui_meters(array_map($utmRow, array_slice($utmCampaigns, 0, 8)), 'data-utm-list') ?>
            <details data-utm-more<?= count($utmCampaigns) > 8 ? '' : ' hidden' ?>>
                <summary class="link-more" data-utm-more-label><?= h(t('a dalších {count}', ['count' => max(0, count($utmCampaigns) - 8)])) ?></summary>
                <?= allstat_ui_meters(array_map($utmRow, array_slice($utmCampaigns, 8)), 'data-utm-rest') ?>
            </details>
            <?php if (!$utmCampaigns): ?>
                <p class="card-desc" data-utm-empty><?= t('Zatím žádná návštěvnost s UTM parametry za zvolené období. Otaguj odkazy (např. <code>?utm_campaign=jaro&amp;utm_source=facebook&amp;utm_medium=cpc&amp;utm_content=banner1</code>) a po dalším GA4 syncu se kampaně objeví zde.') ?></p>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php $funnel = $data['tables']['funnel'] ?? []; if (!empty($funnel['hasData'])): ?>
<section class="card">
    <?= allstat_ui_card_head(t('Nákupní trychtýř'), 'e-commerce', t('Kroky nákupu z GA4 e-commerce událostí za období. <strong>Pokles</strong> = kolik % lidí mezi kroky odpadlo; největší pokles je <strong>úzké hrdlo</strong>.') . ' ' . h($funnel['overallLabel']) . ($funnel['removeFromCart'] > 0 ? ' · ' . h(t('Odebrání z košíku: {value}', ['value' => $funnel['removeFromCartLabel']])) : '') . '.') ?>
    <div class="card-body">
        <div class="table-wrap">
            <table class="table table-plain">
                <thead><tr><th scope="col"><?= h(t('Krok')) ?></th><th scope="col" style="width: 96px"><?= h(t('Počet')) ?></th><th scope="col" style="width: 30%"><?= h(t('Podíl z vrcholu')) ?></th><th scope="col" style="width: 152px"><?= h(t('Pokles vs. předchozí')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($funnel['steps'] as $step): ?>
                        <tr>
                            <td class="ellipsis"><?= h($step['label']) ?><?= $step['isBottleneck'] ? ' <span class="chip tone-warn">' . h(t('úzké hrdlo')) . '</span>' : '' ?></td>
                            <td class="num is-strong"><?= h($step['countLabel']) ?></td>
                            <td><div class="meter-top"><div class="track" style="flex: 1"><div class="track-fill" style="width: <?= round(max(1, (float) $step['share']), 1) ?>%"></div></div><span class="row-share num"><?= h($step['shareLabel']) ?></span></div></td>
                            <td class="num"><?= $step['dropLabel'] !== '' ? h($step['dropLabel']) : '–' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php endif; ?>

<?php $products = $data['tables']['topProducts'] ?? []; if ($products): ?>
<section class="card">
    <?= allstat_ui_card_head(t('Top produkty'), 'e-commerce', t('Nejprodávanější produkty podle tržeb (GA4 <code>itemName</code>). <strong>Konverze</strong> = kolik % zobrazení produktu skončilo nákupem.')) ?>
    <div class="card-body">
        <div class="table-wrap">
            <table class="table table-plain">
                <thead><tr><th scope="col"><?= h(t('Produkt')) ?></th><th scope="col" style="width: 96px"><?= h(t('Zobrazení')) ?></th><th scope="col" style="width: 96px"><?= h(t('Do košíku')) ?></th><th scope="col" style="width: 96px"><?= h(t('Prodáno')) ?></th><th scope="col" style="width: 112px"><?= h(t('Tržby')) ?></th><th scope="col" style="width: 96px"><?= h(t('Konverze')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($products as $p): ?>
                        <tr>
                            <td class="ellipsis" title="<?= h($p['name']) ?>"><?= h($p['name']) ?></td>
                            <td class="num"><?= h($p['viewedLabel']) ?></td>
                            <td class="num"><?= h($p['addedLabel']) ?></td>
                            <td class="num"><?= h($p['purchasedLabel']) ?></td>
                            <td class="num is-strong"><?= h($p['revenueLabel']) ?></td>
                            <td class="num"><?= h($p['buyRateLabel']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php endif; ?>

<?php $demo = $data['tables']['demographics'] ?? []; if (!empty($demo['hasData'])): ?>
<section class="card">
    <?= allstat_ui_card_head(t('Publikum'), 'GA4', t('Věk a pohlaví návštěvníků, <strong>modelovaný odhad</strong> (Google Signals). GA4 malé segmenty skrývá kvůli ochraně soukromí, takže součet nemusí dát 100 % a část je „Neurčeno“.')) ?>
    <div class="card-body grid grid-2">
        <div class="meter">
            <span class="block-title"><?= h(t('Věk')) ?></span>
            <?= allstat_ui_meters(array_map(static fn (array $a, float $w): array => ['label' => $a['label'], 'value' => $a['usersLabel'], 'share' => $a['shareLabel'], 'width' => $w], $demo['age'], allstat_ui_widths(array_column($demo['age'], 'users'))), '', t('Bez věkových dat (GA4 práh / Signals).')) ?>
        </div>
        <?php $genderTotal = max(1, array_sum(array_column($demo['gender'], 'users'))); ?>
        <?= allstat_ui_stack(t('Pohlaví'), '', array_map(static fn (array $g): array => ['label' => $g['label'], 'value' => $g['usersLabel'], 'share' => $g['shareLabel'], 'pct' => (int) $g['users'] / $genderTotal * 100], $demo['gender']), '', t('Bez dat o pohlaví (GA4 práh / Signals).')) ?>
    </div>
</section>
<?php endif; ?>

<section class="card">
    <?= allstat_ui_card_head(t('Přehled zdrojů dat'), '', h(t('Stav synchronizace služeb napojených na tento web.')), '<a class="btn btn-secondary btn-sm" href="admin/sources.php?domain_id=' . (int) $data['domain']['id'] . '">' . h(t('Správa zdrojů')) . '</a>') ?>
    <div class="card-body">
        <div class="table-wrap">
            <table class="table table-plain">
                <thead><tr><th scope="col"><?= h(t('Zdroj')) ?></th><th scope="col" style="width: 120px"><?= h(t('Stav')) ?></th><th scope="col" style="width: 140px"><?= h(t('Poslední sync')) ?></th><th scope="col" style="width: 48px"><span class="sr-only"><?= h(t('Obnovit')) ?></span></th></tr></thead>
                <tbody data-source-rows>
                    <?php $statusTone = ['ok' => 'tone-up', 'warning' => 'tone-warn', 'error' => 'tone-down']; ?>
                    <?php foreach ($data['tables']['sources'] as $source): ?>
                        <tr>
                            <td class="ellipsis"><?= h($source['source']) ?></td>
                            <td><span class="status <?= $statusTone[$source['status']] ?? 'tone-flat' ?>"><span class="dot"></span><?= h($statusLabels[$source['status']] ?? $source['status']) ?></span></td>
                            <td class="num"><?= h($source['lastSync']) ?></td>
                            <td><button class="btn-icon btn-sm" type="button" title="<?= h(t('Obnovit')) ?>" aria-label="<?= h(t('Obnovit')) ?>" data-refresh-dashboard><?= allstat_icon('refresh-cw', 'icon-sm') ?></button></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$data['tables']['sources']): ?><tr><td colspan="4" class="is-muted"><?= h(t('Zatím není napojený žádný zdroj dat.')) ?></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
