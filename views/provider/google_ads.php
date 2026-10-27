<?php
/**
 * Detail zdroje – Google Ads (a Microsoft Ads, viz microsoft_ads.php): kampaně za období podle útraty.
 * Záložky metrik (Útrata/ROAS/PNO/CPA/CPC/CTR/CPM…) a graf kreslí views/provider.php.
 * V rozsahu z index.php: $googleAdsNoConversions, $googleAdsCampaigns, $selectedProvider. Jen prezentace.
 */
$adsProviderName = ($selectedProvider['provider_key'] ?? '') === 'microsoft_ads' ? 'Microsoft Ads' : 'Google Ads';
?>
<?php if (!empty($googleAdsNoConversions)): ?>
<div class="callout tone-warn">
    <?= allstat_icon('info', 'icon-sm') ?>
    <span><strong><?= h(t('Pozn.: tenhle účet nehlásí hodnotu konverzí')) ?></strong> <?= str_replace('Google Ads', h($adsProviderName), t('Google Ads účet za toto období <strong>nehlásí konverze ani jejich hodnotu</strong> (chybí konverzní sledování / hodnota konverzí, nebo účet jede jen na provoz). Proto jsme dlaždice <strong>ROAS, PNO, Konverze, CPA a Hodnotu konverzí skryli</strong>, nemělo by smysl ukazovat samé nuly, <strong>není to chyba</strong>. Sleduj místo toho <strong>CTR, CPC, CPM</strong> a Kliknutí. Jakmile v Google Ads nastavíš sledování hodnoty konverzí, dopočítají se i ROAS / PNO / CPA.')) ?></span>
</div>
<?php endif; ?>
<?php if (!empty($googleAdsCampaigns)): ?>
<section class="card card-clip">
    <?= allstat_ui_card_head(t('Kampaně'), $adsProviderName, h(str_replace('Google Ads', $adsProviderName, t('Kampaně za zvolené období seřazené podle útraty, součet dní s útratou. Částky v měně účtu Google Ads.'))) . (empty($googleAdsNoConversions) ? ' ' . h(t('ROAS a CPA dávají smysl jen u kampaní s měřením konverzí.')) : '')) ?>
    <div class="table-wrap">
        <table class="table table-wide">
            <thead><tr><th scope="col"><?= h(t('Kampaň')) ?></th><th scope="col" style="width: 104px"><?= h(t('Útrata')) ?></th><th scope="col" style="width: 104px"><?= h(t('Zobrazení')) ?></th><th scope="col" style="width: 88px"><?= h(t('Kliknutí')) ?></th><th scope="col" style="width: 72px">CTR</th><th scope="col" style="width: 72px">CPC</th><?php if (empty($googleAdsNoConversions)): ?><th scope="col" style="width: 88px"><?= h(t('Konverze')) ?></th><th scope="col" style="width: 72px">CPA</th><th scope="col" style="width: 72px">ROAS</th><?php endif; ?></tr></thead>
            <tbody>
                <?php foreach ($googleAdsCampaigns as $campaign): ?>
                    <tr>
                        <td><div class="post"><span class="post-text"><?= h($campaign['name']) ?></span><span class="post-meta"><?= h($campaign['runLabel']) ?></span></div></td>
                        <td class="num is-strong"><?= h(allstat_number($campaign['cost'], $adsProviderName === 'Microsoft Ads' ? 2 : 0)) ?></td>
                        <td class="num"><?= h(allstat_number($campaign['impressions'], 0)) ?></td>
                        <td class="num"><?= h(allstat_number($campaign['clicks'], 0)) ?></td>
                        <td class="num"><?= h($campaign['ctr'] !== null ? allstat_percent($campaign['ctr'], 2) : '–') ?></td>
                        <td class="num"><?= h($campaign['cpc'] !== null ? allstat_number($campaign['cpc'], 2) : '–') ?></td>
                        <?php if (empty($googleAdsNoConversions)): ?>
                        <td class="num"><?= h(allstat_number($campaign['conversions'], 1)) ?></td>
                        <td class="num"><?= h($campaign['cpa'] !== null ? allstat_number($campaign['cpa'], 2) : '–') ?></td>
                        <td class="num"><?= h($campaign['roas'] !== null ? allstat_number($campaign['roas'], 2) . '×' : '–') ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
