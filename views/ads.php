<?php
/**
 * Pohled „Reklama napříč platformami“ (?view=ads): účty vedle sebe a srovnání s GA4.
 * V rozsahu z index.php: $adsOverview (allstat_get_ads_overview), $data. Jen prezentace.
 * Útrata se sčítá jen v rámci měny, konverze platforem se nesčítají (každá má vlastní atribuci).
 */
require_once __DIR__ . '/_ui.php';

$periodLabel = allstat_ui_period_label($data['range']['start'], $data['range']['end']);
$money = static fn (?float $v, string $cur, int $dec = 0): string => $v === null ? '–' : trim(allstat_number($v, $dec) . ' ' . $cur);
$ga4Names = allstat_ads_overview_providers();
$platformConversions = [];
foreach ($adsOverview['accounts'] as $a) {
    $p = $platformConversions[$a['provider']] ?? ['conversions' => 0.0, 'value' => [], 'clicks' => 0.0];
    $p['conversions'] += $a['conversions'];
    $p['clicks'] += $a['clicks'];
    if ($a['conversionValue'] > 0) {
        $p['value'][$a['currencyLabel']] = ($p['value'][$a['currencyLabel']] ?? 0.0) + $a['conversionValue'];
    }
    $platformConversions[$a['provider']] = $p;
}
?>
<div class="page-stack">
<?php if (!$adsOverview['accounts']): ?>
    <section class="card empty">
        <span class="empty-icon tone-accent"><?= allstat_icon('megaphone', 'icon') ?></span>
        <h2 class="empty-title"><?= h(t('Zatím žádná napojená reklama')) ?></h2>
        <p class="empty-desc"><?= t('Napoj reklamní účet (Google Ads, Meta Ads, Microsoft Ads, TikTok Ads nebo OpenAI Ads) ve {link}.', ['link' => '<a class="link-more" href="admin/sources.php?domain_id=' . (int) $data['domain']['id'] . '">' . h(t('Zdroje dat')) . '</a>']) ?></p>
    </section>
<?php else: ?>
    <div class="grid grid-kpis">
        <?php foreach (array_slice($adsOverview['currencies'], 0, 3, true) as $cur => $c): ?>
            <?php $curLabel = $cur === '?' ? t('neznámá měna') : allstat_currency_label((string) $cur); ?>
            <div class="card kpi">
                <span class="metric-label"><?= h(t('Útrata za reklamu')) ?></span>
                <span class="metric-value"><?= h(allstat_number($c['spend'])) ?><span class="metric-unit"> <?= h($curLabel) ?></span></span>
                <span class="metric-note ellipsis"><?= h(implode(' · ', [tn($c['accounts'], '{count} účet', '{count} účty', '{count} účtů'), tn((int) $c['clicks'], '{count} kliknutí', '{count} kliknutí', '{count} kliknutí')])) ?></span>
            </div>
        <?php endforeach; ?>
        <?php if ($adsOverview['ga4Paid'] !== null): ?>
            <div class="card kpi" title="<?= h(t('Kanály GA4 začínající na Paid, Cross-network a Display. Konverze = klíčové události GA4.')) ?>">
                <span class="metric-label"><?= h(t('Placená návštěvnost podle GA4')) ?></span>
                <span class="metric-value"><?= h(allstat_number($adsOverview['ga4Paid']['sessions'])) ?><span class="metric-unit"> <?= h(t('návštěv')) ?></span></span>
                <span class="metric-note ellipsis"><?= h(implode(' · ', array_filter([
                    tn($adsOverview['ga4Paid']['conversions'], '{count} konverze', '{count} konverze', '{count} konverzí'),
                    $adsOverview['hasGa4Revenue'] ? t('tržby {amount}', ['amount' => allstat_number($adsOverview['ga4Paid']['revenue'])]) : '',
                ]))) ?></span>
            </div>
        <?php endif; ?>
    </div>

    <section class="card card-clip">
        <?= allstat_ui_card_head(t('Reklamní účty'), $periodLabel, h(t('Výkon každého účtu za období v jeho měně. Podíl na útratě se počítá jen mezi účty ve stejné měně, kurzy AllStat nepřepočítává. Konverze a ROAS hlásí každá platforma podle vlastní atribuce, proto se nesčítají.'))) ?>
        <div class="table-wrap">
            <table class="table table-wide">
                <thead><tr>
                    <th scope="col"><?= h(t('Účet')) ?></th>
                    <th class="col-sm" scope="col"><?= h(t('Útrata')) ?></th>
                    <th class="col-xs" scope="col"><?= h(t('Podíl')) ?></th>
                    <th class="col-num" scope="col"><?= h(t('Zobrazení')) ?></th>
                    <th class="col-num" scope="col"><?= h(t('Kliknutí')) ?></th>
                    <th class="col-xs" scope="col">CTR</th>
                    <th class="col-xs" scope="col">CPC</th>
                    <th class="col-xs" scope="col">CPM</th>
                    <th class="col-num" scope="col"><?= h(t('Konverze')) ?></th>
                    <th class="col-xs" scope="col">CPA</th>
                    <th class="col-xs" scope="col">ROAS</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($adsOverview['accounts'] as $a): ?>
                        <tr>
                            <td><div class="post"><span class="post-text"><a href="?<?= h(http_build_query(['domain_id' => (int) $data['domain']['id'], 'source_id' => $a['connection_id'], 'start' => $data['range']['start'], 'end' => $data['range']['end']])) ?>"><?= h($a['name']) ?></a></span><span class="post-meta"><?= h($a['account'] !== '' ? $a['account'] : '–') ?></span></div></td>
                            <td class="num is-strong"><?= h($money($a['cost'], $a['currencyLabel'])) ?></td>
                            <td class="num"><?= h($a['share'] !== null ? allstat_percent($a['share'], 0) : '–') ?></td>
                            <td class="num"><?= h(allstat_number($a['impressions'])) ?></td>
                            <td class="num"><?= h(allstat_number($a['clicks'])) ?></td>
                            <td class="num"><?= h($a['ctr'] !== null ? allstat_percent($a['ctr'], 2) : '–') ?></td>
                            <td class="num"><?= h($money($a['cpc'], '', 2)) ?></td>
                            <td class="num"><?= h($money($a['cpm'], '', 2)) ?></td>
                            <td class="num"><?= h($a['conversions'] > 0 ? allstat_number($a['conversions'], 1) : '–') ?></td>
                            <td class="num"><?= h($money($a['cpa'], '', 2)) ?></td>
                            <td class="num"><?= h($a['roas'] !== null ? allstat_number($a['roas'], 2) . '×' : '–') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card card-clip">
        <?= allstat_ui_card_head(t('Konverze platforem a GA4'), $periodLabel, h(t('Vlevo konverze, jak je hlásí platforma (její atribuce, včetně zobrazení a delších oken). Vpravo návštěvy, klíčové události a tržby, které GA4 přiřadilo zdroji platformy podle UTM nebo automatického označení (gclid, msclkid). Čísla se liší záměrně, nesčítají se.'))) ?>
        <div class="table-wrap">
            <table class="table table-wide">
                <thead><tr>
                    <th scope="col"><?= h(t('Platforma')) ?></th>
                    <th class="col-num" scope="col"><?= h(t('Kliknutí')) ?></th>
                    <th class="col-num" scope="col"><?= h(t('Konverze platformy')) ?></th>
                    <th class="col-sm" scope="col"><?= h(t('Hodnota konverzí platformy')) ?></th>
                    <th class="col-num" scope="col"><?= h(t('Návštěvy GA4')) ?></th>
                    <th class="col-num" scope="col"><?= h(t('Konverze GA4')) ?></th>
                    <?php if ($adsOverview['hasGa4Revenue']): ?><th class="col-sm" scope="col"><?= h(t('Tržby GA4')) ?></th><?php endif; ?>
                </tr></thead>
                <tbody>
                    <?php foreach ($platformConversions as $pk => $p): $g = $adsOverview['ga4'][$pk] ?? null; ?>
                        <tr>
                            <td><?= h($ga4Names[$pk] ?? $pk) ?></td>
                            <td class="num"><?= h(allstat_number($p['clicks'])) ?></td>
                            <td class="num"><?= h($p['conversions'] > 0 ? allstat_number($p['conversions'], 1) : '–') ?></td>
                            <td class="num"><?= h($p['value'] ? implode(' + ', array_map(static fn (string $cur, float $v): string => trim(allstat_number($v) . ' ' . $cur), array_keys($p['value']), $p['value'])) : '–') ?></td>
                            <td class="num"><?= h($g ? allstat_number($g['sessions']) : '–') ?></td>
                            <td class="num"><?= h($g ? allstat_number($g['conversions']) : '–') ?></td>
                            <?php if ($adsOverview['hasGa4Revenue']): ?><td class="num"><?= h($g ? allstat_number($g['revenue']) : '–') ?></td><?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="callout">
        <?= allstat_icon('info', 'icon-sm') ?>
        <span><?= h(t('Platforma bez řádku v GA4 (pomlčka) nemá v odkazech UTM parametry, nebo GA4 ještě nemá data. Google Ads a Microsoft Ads se v GA4 poznají i bez UTM, když je zapnuté automatické označování. Tržby GA4 jsou v měně GA4 property a ukládají se od verze 1.5.5, starší dny doplní nová synchronizace GA4.')) ?></span>
    </div>
<?php endif; ?>
</div>
