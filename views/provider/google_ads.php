<?php
/**
 * Provider-view partial — Google Ads. Included by index.php inside the generic provider view when
 * provider_key = 'google_ads'. In scope from index.php: $googleAdsNoConversions, $googleAdsCampaigns + h().
 * Presentation only — the shared KPI grid (Útrata/ROAS/PNO/CPA/CPC/CTR/CPM…) + trend are rendered by index.php.
 */
?>
<?php $adsProviderName = ($selectedProvider['provider_key'] ?? '') === 'microsoft_ads' ? 'Microsoft Ads' : 'Google Ads'; ?>
<?php if (!empty($googleAdsCampaigns)): ?>
<section class="tables-grid" aria-label="<?= h($adsProviderName === 'Google Ads' ? t('Google Ads kampaně') : t('Microsoft Ads kampaně')) ?>">
    <article class="panel table-panel table-panel-wide">
        <div class="panel-header"><h2><?= h(t('Kampaně')) ?> <span>(<?= h($adsProviderName) ?>)</span></h2></div>
        <p class="panel-help"><?= h(str_replace('Google Ads', $adsProviderName, t('Kampaně za zvolené období seřazené podle útraty, součet dní s útratou. Částky v měně účtu Google Ads.'))) ?><?= empty($googleAdsNoConversions) ? ' ' . h(t('ROAS a CPA dávají smysl jen u kampaní s měřením konverzí.')) : '' ?></p>
        <div class="table-scroll">
            <table>
                <thead><tr><th><?= h(t('Kampaň')) ?></th><th><?= h(t('Útrata')) ?></th><th><?= h(t('Zobrazení')) ?></th><th><?= h(t('Kliknutí')) ?></th><th>CTR</th><th>CPC</th><?php if (empty($googleAdsNoConversions)): ?><th><?= h(t('Konverze')) ?></th><th>CPA</th><th>ROAS</th><?php endif; ?></tr></thead>
                <tbody>
                    <?php foreach ($googleAdsCampaigns as $campaign): ?>
                        <tr>
                            <td><?= h($campaign['name']) ?><br><small class="table-muted"><?= h($campaign['runLabel']) ?></small></td>
                            <td><?= h(allstat_number($campaign['cost'], $adsProviderName === 'Microsoft Ads' ? 2 : 0)) ?></td>
                            <td><?= h(allstat_number($campaign['impressions'], 0)) ?></td>
                            <td><?= h(allstat_number($campaign['clicks'], 0)) ?></td>
                            <td><?= h($campaign['ctr'] !== null ? allstat_percent($campaign['ctr'], 2) : '—') ?></td>
                            <td><?= h($campaign['cpc'] !== null ? allstat_number($campaign['cpc'], 2) : '—') ?></td>
                            <?php if (empty($googleAdsNoConversions)): ?>
                            <td><?= h(allstat_number($campaign['conversions'], 1)) ?></td>
                            <td><?= h($campaign['cpa'] !== null ? allstat_number($campaign['cpa'], 2) : '—') ?></td>
                            <td><?= h($campaign['roas'] !== null ? allstat_number($campaign['roas'], 2) . '×' : '—') ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>
<?php endif; ?>
<?php if (!empty($googleAdsNoConversions)): ?>
<section class="analytics-grid" aria-label="<?= h(t('Poznámka ke konverzím')) ?>">
    <article class="panel panel-wide">
        <div class="panel-header"><h2><?= h(t('Pozn.: tenhle účet nehlásí hodnotu konverzí')) ?></h2></div>
        <p class="panel-help">
            <?= str_replace('Google Ads', h($adsProviderName), t('Google Ads účet za toto období <strong>nehlásí konverze ani jejich hodnotu</strong> (chybí konverzní sledování / hodnota konverzí, nebo účet jede jen na provoz). Proto jsme dlaždice <strong>ROAS, PNO, Konverze, CPA a Hodnotu konverzí skryli</strong>, nemělo by smysl ukazovat samé nuly, <strong>není to chyba</strong>. Sleduj místo toho <strong>CTR, CPC, CPM</strong> a Kliknutí. Jakmile v Google Ads nastavíš sledování hodnoty konverzí, dopočítají se i ROAS / PNO / CPA.')) ?>
        </p>
    </article>
</section>
<?php endif; ?>
