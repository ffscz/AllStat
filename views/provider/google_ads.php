<?php
/**
 * Provider-view partial — Google Ads. Included by index.php inside the generic provider view when
 * provider_key = 'google_ads'. In scope from index.php: $googleAdsNoConversions, $googleAdsCampaigns + h().
 * Presentation only — the shared KPI grid (Útrata/ROAS/PNO/CPA/CPC/CTR/CPM…) + trend are rendered by index.php.
 */
?>
<?php if (!empty($googleAdsCampaigns)): ?>
<section class="tables-grid" aria-label="Google Ads kampaně">
    <article class="panel table-panel table-panel-wide">
        <div class="panel-header"><h2>Kampaně <span>(Google Ads)</span></h2></div>
        <p class="panel-help">Kampaně za zvolené období seřazené podle útraty, součet dní s útratou. Částky v měně účtu Google Ads.<?= empty($googleAdsNoConversions) ? ' ROAS a CPA dávají smysl jen u kampaní s měřením konverzí.' : '' ?></p>
        <div class="table-scroll">
            <table>
                <thead><tr><th>Kampaň</th><th>Útrata</th><th>Zobrazení</th><th>Kliknutí</th><th>CTR</th><th>CPC</th><?php if (empty($googleAdsNoConversions)): ?><th>Konverze</th><th>CPA</th><th>ROAS</th><?php endif; ?></tr></thead>
                <tbody>
                    <?php foreach ($googleAdsCampaigns as $campaign): ?>
                        <tr>
                            <td><?= h($campaign['name']) ?><br><small class="table-muted"><?= h($campaign['runLabel']) ?></small></td>
                            <td><?= h(allstat_number($campaign['cost'], 0)) ?></td>
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
<section class="analytics-grid" aria-label="Poznámka ke konverzím">
    <article class="panel panel-wide">
        <div class="panel-header"><h2>Pozn.: tenhle účet nehlásí hodnotu konverzí</h2></div>
        <p class="panel-help">
            Google Ads účet za toto období <strong>nehlásí konverze ani jejich hodnotu</strong> (chybí konverzní sledování / hodnota konverzí, nebo účet jede jen na provoz). Proto jsme dlaždice <strong>ROAS, PNO, Konverze, CPA a Hodnotu konverzí skryli</strong>, nemělo by smysl ukazovat samé nuly, <strong>není to chyba</strong>. Sleduj místo toho <strong>CTR, CPC, CPM</strong> a Kliknutí. Jakmile v Google Ads nastavíš sledování hodnoty konverzí, dopočítají se i ROAS / PNO / CPA.
        </p>
    </article>
</section>
<?php endif; ?>
