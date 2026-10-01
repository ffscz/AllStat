<?php
/**
 * Provider-view partial — Meta Ads breakdown tables (campaigns / ad sets / creatives).
 * Included by index.php inside the generic provider view when provider_key = 'meta_ads'.
 * In scope from index.php: $metaAdsBreakdown, $metaAdsAdsets, $metaAdsCreatives, $metaAdsNoConversions + h().
 * Presentation only — no DB access here; the shared KPI grid + trend are still rendered by index.php.
 */
$showConv = empty($metaAdsNoConversions); // hide ROAS/Konverze/CPA columns for accounts with no conversions
// Rozbalovací detail jedné reklamy, ve stejném duchu jako u příspěvků: řádek drží jen to hlavní,
// zbytek metrik + hodnocení se ukáže až po kliknutí na šipku.
$adDetailId = 0;
$adDetailToggle = static function () use (&$adDetailId): string {
    return '<button type="button" class="post-detail-toggle" aria-expanded="false" aria-controls="ad-detail-'
        . ($adDetailId + 1) . '" title="Zobrazit podrobné statistiky reklamy"><span aria-hidden="true">▾</span><span class="sr-only">Detail reklamy</span></button>';
};
$adDetailRow = static function (array $row, int $colspan) use (&$adDetailId): string {
    $adDetailId++;
    $groups = [
        ['title' => 'Náklady', 'items' => [
            ['Útrata', $row['spendLabel'] ?? '—', 'Kolik reklama za období utratila.'],
            ['Za kliknutí na odkaz', $row['cplcLabel'] ?? '—', 'Útrata ÷ kliknutí na odkaz. Stejná definice jako „Za kliknutí na odkaz" v Business Suite.'],
            ['Za kliknutí (všechna)', $row['cpcLabel'] ?? '—', 'Útrata ÷ všechna kliknutí, tedy i lajky, jméno stránky nebo rozbalení textu. Bývá nižší.'],
            ['CPM', $row['cpmLabel'] ?? '—', 'Cena za tisíc zobrazení.'],
        ]],
        ['title' => 'Dosah a prokliky', 'items' => [
            ['Zobrazení', $row['impressionsLabel'] ?? '—', 'Kolikrát se reklama ukázala, i opakovaně.'],
            ['Lidé za celou dobu', $row['reachLifetimeLabel'] ?? '—', 'Kolik různých lidí reklamu vidělo od jejího spuštění (údaj z Mety, ne ze zvoleného období).'],
            ['Dosah (součet dní)', $row['reachLabel'] ?? '—', 'Součet denních dosahů za zvolené období. Kdo reklamu viděl ve více dnech, je tu vícekrát.'],
            ['Kliknutí na odkaz', $row['linkClicksLabel'] ?? '—', 'Kliknutí vedoucí na web. Tohle číslo ukazuje Business Suite.'],
            ['Kliknutí celkem', $row['clicksLabel'] ?? '—', 'Všechna kliknutí do reklamy.'],
        ]],
        ['title' => 'Kvalita', 'items' => [
            ['Kliklo na odkaz', $row['linkCtrLabel'] ?? '—', 'Kolik procent lidí, kterým se reklama ukázala, kliklo na odkaz. Čím víc, tím líp reklama táhne.'],
            ['Kliklo kamkoliv', $row['ctrLabel'] ?? '—', 'Kolik procent lidí kliklo kamkoliv do reklamy, tedy i na lajk nebo jméno stránky. V Business Suite je to „CTR (vše)".'],
            ($row['frequencyScope'] ?? '') === 'lifetime'
                ? ['Kolikrát ji člověk viděl', $row['frequencyLabel'] ?? '—', 'Kolikrát průměrně viděl reklamu tentýž člověk za celou dobu (údaj z Mety). Od 3 výš už ji lidé přestávají vnímat.']
                : ['Kolikrát ji viděl za den', $row['frequencyLabel'] ?? '—', 'Jen denní frekvence: kolikrát ji člověk viděl za jeden den. Frekvence za celou dobu se doplní po dalším syncu.'],
            ['Video sledovalo aspoň 3 s', $row['hookLabel'] ?? '—', 'Kolik procent lidí, kterým se reklama ukázala, sledovalo video aspoň 3 sekundy (hook rate). Samotné spuštění při scrollování se nepočítá. Dobré je zhruba 30 % a víc. U reklam bez videa se neměří.'],
        ]],
    ];
    $html = '<tr class="post-detail-row" id="ad-detail-' . $adDetailId . '" hidden><td colspan="' . $colspan . '"><div class="post-detail">';
    foreach ($groups as $g) {
        $html .= '<div class="post-detail-group"><h4>' . h($g['title']) . '</h4><dl>';
        foreach ($g['items'] as [$label, $value, $hint]) {
            $html .= '<div class="post-detail-item" title="' . h($hint) . '"><dt>' . h($label) . '</dt><dd>' . h((string) $value) . '</dd></div>';
        }
        $html .= '</dl></div>';
    }
    // Hodnocení efektivity: verdikt + důvody, ať je vidět, z čeho vznikl.
    $v = $row['verdict'] ?? null;
    if ($v) {
        $html .= '<div class="post-detail-group ad-verdict ad-verdict-' . h((string) $v['level']) . '"><h4>Hodnocení</h4>'
            . '<p class="ad-verdict-label">' . h((string) $v['label']) . '</p><ul>';
        foreach ($v['reasons'] as $r) { $html .= '<li>' . h((string) $r) . '</li>'; }
        $html .= '</ul><p class="ad-verdict-note">Porovnáno s ostatními reklamami na tomhle účtu za stejné období, ne s čísly za celý obor.</p></div>';
    }

    return $html . '</div></td></tr>';
};
?>
<?php if (!empty($metaAdsRecommendations)):
$recMeta = [
    'bad'  => ['icon' => 'alert-triangle'],
    'warn' => ['icon' => 'alert-circle'],
    'good' => ['icon' => 'check-circle-2'],
    'info' => ['icon' => 'lightbulb'],
];
?>
<section class="analytics-grid" aria-label="Doporučení">
    <article class="panel panel-wide">
        <div class="panel-header"><h2>Doporučení a upozornění <span>(automaticky z dat)</span></h2></div>
        <p class="panel-help">Automatická analýza metrik za zvolené období, kde je reklama neefektivní nebo nejspíš špatně nastavená. Jsou to <strong>orientační upozornění</strong> (kandidáti k prověření), ne verdikty.</p>
        <ul class="rec-list">
            <?php foreach ($metaAdsRecommendations as $r): $icon = $recMeta[$r['level']]['icon'] ?? 'lightbulb'; ?>
            <li class="rec-item rec-<?= h($r['level']) ?>">
                <span class="rec-icon"><i data-lucide="<?= h($icon) ?>"></i></span>
                <div class="rec-body"><strong><?= h($r['title']) ?></strong><p><?= h($r['detail']) ?></p></div>
            </li>
            <?php endforeach; ?>
        </ul>
    </article>
</section>
<?php endif; ?>

<?php if (!empty($metaAdsNoConversions)): ?>
<section class="analytics-grid" aria-label="Poznámka ke konverzím">
    <article class="panel panel-wide">
        <div class="panel-header"><h2>Pozn.: tenhle účet nemá konverze</h2></div>
        <p class="panel-help">
            Reklamní účet <strong>nemá nastavené konverzní sledování</strong> (žádný pixel ani konverzní události) a nehlásí žádné tržby, jede na <strong>dosah a provoz</strong> (awareness / traffic). Proto jsme dlaždice i sloupce <strong>ROAS, Konverze, CPA a Hodnota konverzí skryli</strong> (nemělo by smysl ukazovat samé nuly), <strong>není to chyba</strong>.
            Sleduj místo toho <strong>prokliky na odkaz a cenu za ně</strong> (u kampaní na návštěvnost) a <strong>cenu za 1 000 zobrazení a frekvenci</strong> (u kampaní na povědomí), k tomu přehrání videa.
        </p>
    </article>
</section>
<?php endif; ?>

<?php if (($metaAdsDemographics['hasData'] ?? false)):
    $adPal = ['#2563eb', '#16a34a', '#8b5cf6', '#f59e0b', '#06b6d4', '#f43f5e', '#14b8a6'];
    $adBar = static function (array $rows, array $pal): string {
        if (!$rows) { return ''; }
        $max = max(1, ...array_map(static fn ($r) => (int) $r['value'], $rows));
        $out = ''; $i = 0;
        foreach ($rows as $r) {
            $v = (int) $r['value'];
            $share = (int) round((float) ($r['share'] ?? 0));
            $out .= '<div class="bar-row"><span class="bar-row-label" title="' . h($r['label']) . '">' . h($r['label']) . '</span>'
                . '<div class="bar-row-track"><div class="bar-row-fill" style="width: ' . round(max(2, $v / $max * 100), 1) . '%; background: ' . $pal[$i % count($pal)] . ';"></div></div>'
                . '<span class="bar-row-value"><strong>' . h(allstat_number($v)) . '</strong> <small>(' . $share . ' %)</small></span></div>';
            $i++;
        }
        return '<div class="bar-chart">' . $out . '</div>';
    };
?>
<section class="analytics-grid" aria-label="Demografie a umístění reklam">
    <article class="panel panel-wide">
        <div class="panel-header"><h2>Demografie a umístění reklam <span>(Meta Ads)</span></h2></div>
        <p class="panel-help">Kdo reklamy viděl, podle zobrazení: věk, pohlaví, region a na jaké platformě (Facebook / Instagram / …). Automaticky z Meta Ads API, bez CSV. Delší pruh = větší podíl zobrazení.</p>
        <?php if ($metaAdsDemographics['age']): ?><h3 class="content-subhead">Věk</h3><?= $adBar($metaAdsDemographics['age'], $adPal) ?><?php endif; ?>
        <?php if ($metaAdsDemographics['gender']): ?><h3 class="content-subhead">Pohlaví</h3><?= $adBar($metaAdsDemographics['gender'], $adPal) ?><?php endif; ?>
        <?php if ($metaAdsDemographics['platform']): ?><h3 class="content-subhead">Platforma</h3><?= $adBar($metaAdsDemographics['platform'], $adPal) ?><?php endif; ?>
        <?php if ($metaAdsDemographics['region']): ?><h3 class="content-subhead">Region (top 8)</h3><?= $adBar($metaAdsDemographics['region'], $adPal) ?><?php endif; ?>
    </article>
</section>
<?php endif; ?>

<?php if (!empty($metaAdsBreakdown)): ?>
<section class="tables-grid" aria-label="Meta Ads kampaně">
    <article class="panel table-panel table-panel-wide">
        <div class="panel-header"><h2>Top kampaně <span>(Meta Ads)</span></h2></div>
        <p class="panel-help">Nejvíce utrácející kampaně za období. <strong>Proklik na odkaz</strong> = člověk z reklamy přešel na web (v Business Suite „Kliknutí na odkaz"), <strong>CTR (vše)</strong> počítá i lajky, komentáře a rozbalení textu. <strong>Frekvence za celou kampaň</strong> = kolikrát průměrně viděl reklamu tentýž člověk od začátku kampaně (údaj z Mety, ne ze zvoleného období). Kampaně na <strong>povědomí</strong> hodnoť cenou za 1 000 zobrazení a frekvencí, ne kliknutím: Meta je ukazuje co nejvíc lidem, ne těm, kdo kliknou.<?= $showConv ? ' ROAS a CPA dávají smysl jen u konverzních kampaní.' : '' ?></p>
        <div class="table-scroll">
            <table>
                <thead><tr><th>Kampaň</th><th>Útrata</th><th>Zobrazení</th><th title="Cena za 1 000 zobrazení (CPM)">Za 1 000 zobrazení</th><th>Prokliky na odkaz</th><th>Za proklik na odkaz</th><th title="Prokliky na odkaz ÷ zobrazení">CTR odkazu</th><th title="Všechna kliknutí ÷ zobrazení, i lajky a rozbalení textu">CTR (vše)</th><th title="Kolikrát průměrně viděl reklamu tentýž člověk za celou dobu kampaně">Frekvence za celou kampaň</th><?php if ($showConv): ?><th>Konverze</th><th>ROAS</th><th>CPA</th><?php endif; ?></tr></thead>
                <tbody>
                    <?php foreach ($metaAdsBreakdown as $campaign):
                        $campaignSub = implode(' · ', array_filter([(string) ($campaign['goalLabel'] ?? ''), (string) ($campaign['runLabel'] ?? '')])); ?>
                        <tr>
                            <td><?= h($campaign['name']) ?><?php if ($campaignSub !== ''): ?><br><small class="table-muted"><?= h($campaignSub) ?></small><?php endif; ?></td>
                            <td><?= h($campaign['spendLabel']) ?></td>
                            <td><?= h($campaign['impressionsLabel'] ?? '—') ?></td>
                            <td><?= h($campaign['cpmLabel'] ?? '—') ?></td>
                            <td><?= h($campaign['linkClicksLabel'] ?? '—') ?></td>
                            <td><?= h($campaign['cplcLabel'] ?? '—') ?></td>
                            <td><?= h($campaign['linkCtrLabel'] ?? '—') ?></td>
                            <td><?= h($campaign['ctrLabel'] ?? '—') ?></td>
                            <td title="<?= h(($campaign['reachLifetimeLabel'] ?? '—') !== '—' ? $campaign['reachLifetimeLabel'] . ' lidí za celou dobu' : 'Doplní se po dalším syncu') ?>"><?= h($campaign['frequencyLifetimeLabel'] ?? '—') ?><?= !empty($campaign['fatigue']) ? ' <span class="status-badge status-warning">saturace</span>' : '' ?></td>
                            <?php if ($showConv): ?>
                            <td><?= h($campaign['conversionsLabel']) ?></td>
                            <td><?= h($campaign['roasLabel']) ?></td>
                            <td><?= h($campaign['cpaLabel']) ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>
<?php endif; ?>

<?php if (!empty($metaAdsAdsets)): ?>
<section class="tables-grid" aria-label="Meta Ads sestavy">
    <article class="panel table-panel table-panel-wide">
        <div class="panel-header"><h2>Sestavy (ad sets) <span>(Meta Ads)</span></h2></div>
        <p class="panel-help">Výkon podle reklamních sestav = publik. Pod názvem je, na co sestava optimalizuje. <strong>Frekvence za celou dobu</strong> = kolikrát průměrně viděl reklamu tentýž člověk od spuštění sestavy (údaj z Mety); nad ~3 hrozí únava publika. Kde údaj z Mety zatím chybí, je v závorce jen denní frekvence.</p>
        <div class="table-scroll">
            <table>
                <thead><tr><th>Sestava</th><th>Útrata</th><th>Zobrazení</th><th>Prokliky na odkaz</th><th>Za proklik na odkaz</th><th title="Všechna kliknutí ÷ zobrazení, i lajky a rozbalení textu">CTR (vše)</th><?php if ($showConv): ?><th>Konverze</th><th>ROAS</th><th>CPA</th><?php endif; ?><th title="Kolikrát průměrně viděl reklamu tentýž člověk za celou dobu">Frekvence za celou dobu</th></tr></thead>
                <tbody>
                    <?php foreach ($metaAdsAdsets as $row): ?>
                        <tr>
                            <td><?= h($row['name']) ?><?php if (($row['optimizationLabel'] ?? '') !== ''): ?><br><small class="table-muted"><?= h($row['optimizationLabel']) ?></small><?php endif; ?></td>
                            <td><?= h($row['spendLabel']) ?></td>
                            <td><?= h($row['impressionsLabel'] ?? '—') ?></td>
                            <td><?= h($row['linkClicksLabel'] ?? '—') ?></td>
                            <td><?= h($row['cplcLabel'] ?? '—') ?></td>
                            <td><?= h($row['ctrLabel'] ?? '—') ?></td>
                            <?php if ($showConv): ?>
                            <td><?= h($row['conversionsLabel']) ?></td>
                            <td><?= h($row['roasLabel']) ?></td>
                            <td><?= h($row['cpaLabel']) ?></td>
                            <?php endif; ?>
                            <td><?= ($row['frequencyScope'] ?? '') === 'lifetime' ? h($row['frequencyLabel']) : '<small class="table-muted">(' . h($row['frequencyLabel']) . ' denně)</small>' ?><?= $row['fatigue'] ? ' <span class="status-badge status-warning">saturace</span>' : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>
<?php endif; ?>

<?php if (!empty($metaAdsCreatives)): ?>
<section class="tables-grid" aria-label="Meta Ads kreativy">
    <article class="panel table-panel table-panel-wide">
        <div class="panel-header"><h2>Kreativy (reklamy) <span>(Meta Ads)</span></h2></div>
        <p class="panel-help">Výkon jednotlivých reklam. <strong>Za proklik</strong> = útrata ÷ kliknutí na odkaz, tedy stejné číslo, jaké u reklamy ukazuje Meta Business Suite („Za kliknutí na odkaz"). Pozor, celoúčtové CPC v dlaždicích nahoře počítá <em>všechna</em> kliknutí včetně lajků, proto bývá nižší. Šipka vlevo rozbalí kompletní metriky a hodnocení efektivity. Reklamy na povědomí se hodnotí cenou za 1 000 zobrazení a zájmem o video, ne prokliky. <strong>Únava</strong> = tentýž člověk viděl reklamu za celou dobu 3× a víc.</p>
        <div class="table-scroll">
            <table>
                <thead><tr><th>Kreativa</th><th>Útrata</th><th>Kliknutí na odkaz</th><th>Za proklik</th><?php if ($showConv): ?><th>ROAS</th><?php endif; ?><th>CTR (vše)</th><th title="Kolikrát průměrně viděl reklamu tentýž člověk za celou dobu">Frekvence za celou dobu</th><th>Hodnocení</th></tr></thead>
                <tbody>
                    <?php foreach ($metaAdsCreatives as $row): $v = $row['verdict'] ?? null; ?>
                        <tr>
                            <td><?= $adDetailToggle() ?><?= h($row['name']) ?><?= $row['fatigue'] ? ' <span class="status-badge status-warning">únava</span>' : '' ?></td>
                            <td><?= h($row['spendLabel']) ?></td>
                            <td><?= h($row['linkClicksLabel'] ?? '—') ?></td>
                            <td><strong><?= h($row['cplcLabel'] ?? '—') ?></strong></td>
                            <?php if ($showConv): ?><td><?= h($row['roasLabel']) ?></td><?php endif; ?>
                            <td><?= h($row['ctrLabel']) ?></td>
                            <td><?= ($row['frequencyScope'] ?? '') === 'lifetime' ? h($row['frequencyLabel']) : '<small class="table-muted">(' . h($row['frequencyLabel']) . ' denně)</small>' ?></td>
                            <td><?php if ($v): ?><span class="ad-verdict-chip ad-verdict-<?= h((string) $v['level']) ?>"><?= h((string) $v['label']) ?></span><?php else: ?>—<?php endif; ?></td>
                        </tr>
                        <?= $adDetailRow($row, $showConv ? 8 : 7) ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <script nonce="<?= h(allstat_nonce()) ?>">
        (function () {
            document.querySelectorAll('.table-panel .post-detail-toggle').forEach(function (btn) {
                if (btn.dataset.bound) { return; }
                btn.dataset.bound = '1';
                btn.addEventListener('click', function () {
                    var row = btn.closest('tr');
                    var detail = row && row.nextElementSibling;
                    if (!detail || !detail.classList.contains('post-detail-row')) { return; }
                    var open = detail.hidden;
                    detail.hidden = !open;
                    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                    btn.classList.toggle('is-open', open);
                });
            });
        })();
        </script>
    </article>
</section>
<?php endif; ?>
