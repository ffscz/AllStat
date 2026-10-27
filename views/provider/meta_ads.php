<?php
/**
 * Detail zdroje – Meta Ads: doporučení, hláška o účtu bez konverzí, demografie a umístění reklam
 * a tabulky kampaní, sad reklam (ad sets) a kreativ s rozbalovacím detailem reklamy.
 * Includuje views/provider.php pod záložkami metrik účtu.
 * V rozsahu: $metaAdsBreakdown, $metaAdsAdsets, $metaAdsCreatives, $metaAdsNoConversions,
 * $metaAdsRecommendations, $metaAdsDemographics. Jen prezentace, bez přístupu do DB.
 */
$showConv = empty($metaAdsNoConversions); // u účtů bez konverzí skrýt sloupce ROAS / Konverze / CPA
$dash = '–';
$verdictTone = ['good' => 'tone-up', 'ok' => 'tone-flat', 'warn' => 'tone-warn', 'bad' => 'tone-down', 'none' => 'tone-flat'];
// Rozbalovací detail jedné reklamy: řádek drží jen to hlavní, zbytek metrik + hodnocení se ukáže po kliknutí.
$adDetailId = 0;
$adDetailToggle = static function () use (&$adDetailId): string {
    $id = 'ad-detail-' . ($adDetailId + 1);

    return '<button type="button" class="btn-ghost btn-sm" data-ad-detail="' . $id . '" aria-expanded="false" aria-controls="' . $id . '" title="' . h(t('Zobrazit podrobné statistiky reklamy')) . '">'
        . h(t('Detail')) . allstat_icon('chevron-down', 'icon-sm') . '</button>';
};
$adDetailRow = static function (array $row, int $colspan) use (&$adDetailId, $dash, $verdictTone): string {
    $adDetailId++;
    $groups = [
        ['title' => t('Náklady'), 'items' => [
            [t('Útrata'), $row['spendLabel'] ?? $dash, t('Kolik reklama za období utratila.')],
            [t('Za kliknutí na odkaz'), $row['cplcLabel'] ?? $dash, t('Útrata ÷ kliknutí na odkaz. Stejná definice jako „Za kliknutí na odkaz" v Business Suite.')],
            [t('Za kliknutí (všechna)'), $row['cpcLabel'] ?? $dash, t('Útrata ÷ všechna kliknutí, tedy i lajky, jméno stránky nebo rozbalení textu. Bývá nižší.')],
            ['CPM', $row['cpmLabel'] ?? $dash, t('Cena za tisíc zobrazení.')],
        ]],
        ['title' => t('Dosah a prokliky'), 'items' => [
            [t('Zobrazení'), $row['impressionsLabel'] ?? $dash, t('Kolikrát se reklama ukázala, i opakovaně.')],
            [t('Lidé za celou dobu'), $row['reachLifetimeLabel'] ?? $dash, t('Kolik různých lidí reklamu vidělo od jejího spuštění (údaj z Mety, ne ze zvoleného období).')],
            [t('Dosah (součet dní)'), $row['reachLabel'] ?? $dash, t('Součet denních dosahů za zvolené období. Kdo reklamu viděl ve více dnech, je tu vícekrát.')],
            [t('Kliknutí na odkaz'), $row['linkClicksLabel'] ?? $dash, t('Kliknutí vedoucí na web. Tohle číslo ukazuje Business Suite.')],
            [t('Kliknutí celkem'), $row['clicksLabel'] ?? $dash, t('Všechna kliknutí do reklamy.')],
        ]],
        ['title' => t('Kvalita'), 'items' => [
            [t('Kliklo na odkaz'), $row['linkCtrLabel'] ?? $dash, t('Kolik procent lidí, kterým se reklama ukázala, kliklo na odkaz. Čím víc, tím líp reklama táhne.')],
            [t('Kliklo kamkoliv'), $row['ctrLabel'] ?? $dash, t('Kolik procent lidí kliklo kamkoliv do reklamy, tedy i na lajk nebo jméno stránky. V Business Suite je to „CTR (vše)".')],
            ($row['frequencyScope'] ?? '') === 'lifetime'
                ? [t('Kolikrát ji člověk viděl'), $row['frequencyLabel'] ?? $dash, t('Kolikrát průměrně viděl reklamu tentýž člověk za celou dobu (údaj z Mety). Od 3 výš už ji lidé přestávají vnímat.')]
                : [t('Kolikrát ji viděl za den'), $row['frequencyLabel'] ?? $dash, t('Jen denní frekvence: kolikrát ji člověk viděl za jeden den. Frekvence za celou dobu se doplní po dalším syncu.')],
            [t('Video sledovalo aspoň 3 s'), $row['hookLabel'] ?? $dash, t('Kolik procent lidí, kterým se reklama ukázala, sledovalo video aspoň 3 sekundy (hook rate). Samotné spuštění při scrollování se nepočítá. Dobré je zhruba 30 % a víc. U reklam bez videa se neměří.')],
        ]],
    ];
    $html = '<tr class="row-detail" id="ad-detail-' . $adDetailId . '" hidden><td colspan="' . $colspan . '"><div class="grid grid-tiles">';
    foreach ($groups as $g) {
        $html .= '<div><span class="block-title">' . h($g['title']) . '</span><div class="rows rows-compact">';
        foreach ($g['items'] as [$label, $value, $hint]) {
            $html .= '<div class="row" title="' . h($hint) . '"><span class="row-label">' . h($label) . '</span><span class="row-end"><span class="row-value num">' . h((string) $value) . '</span></span></div>';
        }
        $html .= '</div></div>';
    }
    // Hodnocení efektivity: verdikt + důvody, ať je vidět, z čeho vznikl.
    $v = $row['verdict'] ?? null;
    if ($v) {
        $html .= '<div><span class="block-title">' . h(t('Hodnocení')) . '</span>'
            . '<p><span class="chip ' . ($verdictTone[(string) $v['level']] ?? 'tone-flat') . '">' . h((string) $v['label']) . '</span></p><ul>';
        foreach ($v['reasons'] as $r) { $html .= '<li>' . h((string) $r) . '</li>'; }
        $html .= '</ul><p class="card-desc">' . h(t('Porovnáno s ostatními reklamami na tomhle účtu za stejné období, ne s čísly za celý obor.')) . '</p></div>';
    }

    return $html . '</div></td></tr>';
};
// Frekvence: za celou dobu číslo, jinak jen denní hodnota tlumeně.
$freqCell = static fn (array $row): string => ($row['frequencyScope'] ?? '') === 'lifetime'
    ? h($row['frequencyLabel'])
    : '<span class="is-muted">(' . h(t('{value} denně', ['value' => $row['frequencyLabel']])) . ')</span>';
?>
<?php if (!empty($metaAdsRecommendations)):
    $recTone = ['bad' => 'tone-down', 'warn' => 'tone-warn', 'good' => 'tone-up', 'info' => ''];
    $recIcon = ['bad' => 'info', 'warn' => 'info', 'good' => 'check', 'info' => 'info'];
?>
<section class="card">
    <?= allstat_ui_card_head(t('Doporučení a upozornění'), '', h(t('automaticky z dat')) . ' ' . t('Automatická analýza metrik za zvolené období, kde je reklama neefektivní nebo nejspíš špatně nastavená. Jsou to <strong>orientační upozornění</strong> (kandidáti k prověření), ne verdikty.')) ?>
    <div class="card-body grid">
        <?php foreach ($metaAdsRecommendations as $r): $lvl = (string) ($r['level'] ?? 'info'); ?>
        <div class="callout <?= h($recTone[$lvl] ?? '') ?>"><?= allstat_icon($recIcon[$lvl] ?? 'info', 'icon-sm') ?><span><strong><?= h($r['title']) ?></strong> <?= h($r['detail']) ?></span></div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($metaAdsNoConversions)): ?>
<div class="callout"><?= allstat_icon('info', 'icon-sm') ?><span><?= t('Reklamní účet <strong>nemá nastavené konverzní sledování</strong> (žádný pixel ani konverzní události) a nehlásí žádné tržby, jede na <strong>dosah a provoz</strong> (awareness / traffic). Proto jsme dlaždice i sloupce <strong>ROAS, Konverze, CPA a Hodnota konverzí skryli</strong> (nemělo by smysl ukazovat samé nuly), <strong>není to chyba</strong>.') ?> <?= t('Sleduj místo toho <strong>prokliky na odkaz a cenu za ně</strong> (u kampaní na návštěvnost) a <strong>cenu za 1 000 zobrazení a frekvenci</strong> (u kampaní na povědomí), k tomu přehrání videa.') ?></span></div>
<?php endif; ?>

<?php if (($metaAdsDemographics['hasData'] ?? false)):
    // Hodnota = zobrazení, podíl v %, pruh = poměr k největší položce.
    $adMeters = static fn (array $rows): array => array_map(static fn (array $r, float $w): array => ['label' => (string) $r['label'], 'value' => allstat_number((int) $r['value']), 'share' => (int) round((float) ($r['share'] ?? 0)) . ' %', 'width' => $w], $rows, allstat_ui_widths(array_column($rows, 'value')));
?>
<section class="card">
    <?= allstat_ui_card_head(t('Demografie a umístění reklam'), 'Meta Ads', h(t('Kdo reklamy viděl, podle zobrazení: věk, pohlaví, region a na jaké platformě (Facebook / Instagram / …). Automaticky z Meta Ads API, bez CSV. Delší pruh = větší podíl zobrazení.'))) ?>
    <div class="card-body grid grid-3">
        <?php if ($metaAdsDemographics['age']): ?><div class="meter"><span class="block-title"><?= h(t('Věk')) ?></span><?= allstat_ui_meters($adMeters($metaAdsDemographics['age'])) ?></div><?php endif; ?>
        <?php if ($metaAdsDemographics['gender'] || $metaAdsDemographics['platform']): ?>
        <div class="grid">
            <?php if ($metaAdsDemographics['gender']): ?><?= allstat_ui_stack(t('Pohlaví'), '', array_map(static fn (array $g): array => ['label' => (string) $g['label'], 'value' => allstat_number((int) $g['value']), 'share' => (int) round((float) ($g['share'] ?? 0)) . ' %', 'pct' => (float) ($g['share'] ?? 0)], $metaAdsDemographics['gender'])) ?><?php endif; ?>
            <?php if ($metaAdsDemographics['platform']): ?><div class="meter"><span class="block-title"><?= h(t('Platforma')) ?></span><?= allstat_ui_meters($adMeters($metaAdsDemographics['platform'])) ?></div><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($metaAdsDemographics['region']): ?><div class="meter"><span class="block-title"><?= h(t('Region (top 8)')) ?></span><?= allstat_ui_meters($adMeters($metaAdsDemographics['region'])) ?></div><?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($metaAdsBreakdown)): ?>
<section class="card card-clip">
    <?= allstat_ui_card_head(t('Top kampaně'), 'Meta Ads', t('Nejvíce utrácející kampaně za období. <strong>Proklik na odkaz</strong> = člověk z reklamy přešel na web (v Business Suite „Kliknutí na odkaz"), <strong>CTR (vše)</strong> počítá i lajky, komentáře a rozbalení textu. <strong>Frekvence za celou kampaň</strong> = kolikrát průměrně viděl reklamu tentýž člověk od začátku kampaně (údaj z Mety, ne ze zvoleného období). Kampaně na <strong>povědomí</strong> hodnoť cenou za 1 000 zobrazení a frekvencí, ne kliknutím: Meta je ukazuje co nejvíc lidem, ne těm, kdo kliknou.') . ($showConv ? ' ' . h(t('ROAS a CPA dávají smysl jen u konverzních kampaní.')) : '')) ?>
    <div class="table-wrap">
        <table class="table table-wide">
            <thead><tr>
                <th scope="col"><?= h(t('Kampaň')) ?></th>
                <th scope="col" style="width: 96px"><?= h(t('Útrata')) ?></th>
                <th scope="col" style="width: 104px"><?= h(t('Zobrazení')) ?></th>
                <th scope="col" style="width: 80px" title="<?= h(t('Cena za 1 000 zobrazení (CPM)')) ?>">CPM</th>
                <th scope="col" style="width: 88px" title="<?= h(t('Prokliky na odkaz')) ?>"><?= h(t('Prokliky')) ?></th>
                <th scope="col" style="width: 96px" title="<?= h(t('Za proklik na odkaz')) ?>"><?= h(t('Za proklik')) ?></th>
                <th scope="col" style="width: 96px" title="<?= h(t('Prokliky na odkaz ÷ zobrazení')) ?>"><?= h(t('CTR odkazu')) ?></th>
                <th scope="col" style="width: 96px" title="<?= h(t('Všechna kliknutí ÷ zobrazení, i lajky a rozbalení textu')) ?>"><?= h(t('CTR (vše)')) ?></th>
                <th scope="col" style="width: 96px" title="<?= h(t('Kolikrát průměrně viděl reklamu tentýž člověk za celou dobu kampaně')) ?>"><?= h(t('Frekvence')) ?></th>
                <?php if ($showConv): ?><th scope="col" style="width: 96px"><?= h(t('Konverze')) ?></th><th scope="col" style="width: 80px">ROAS</th><th scope="col" style="width: 80px">CPA</th><?php endif; ?>
            </tr></thead>
            <tbody>
                <?php foreach ($metaAdsBreakdown as $campaign):
                    $campaignSub = implode(' · ', array_filter([(string) ($campaign['goalLabel'] ?? ''), (string) ($campaign['runLabel'] ?? '')])); ?>
                    <tr>
                        <td><div class="post"><span class="post-text" title="<?= h($campaign['name']) ?>"><?= h($campaign['name']) ?></span><?php if ($campaignSub !== ''): ?><span class="post-meta"><?= h($campaignSub) ?></span><?php endif; ?></div></td>
                        <td class="num is-strong"><?= h($campaign['spendLabel']) ?></td>
                        <td class="num"><?= h($campaign['impressionsLabel'] ?? $dash) ?></td>
                        <td class="num"><?= h($campaign['cpmLabel'] ?? $dash) ?></td>
                        <td class="num"><?= h($campaign['linkClicksLabel'] ?? $dash) ?></td>
                        <td class="num"><?= h($campaign['cplcLabel'] ?? $dash) ?></td>
                        <td class="num"><?= h($campaign['linkCtrLabel'] ?? $dash) ?></td>
                        <td class="num"><?= h($campaign['ctrLabel'] ?? $dash) ?></td>
                        <td class="num" title="<?= h(($campaign['reachLifetimeLabel'] ?? '—') !== '—' ? t('{value} lidí za celou dobu', ['value' => $campaign['reachLifetimeLabel']]) : t('Doplní se po dalším syncu')) ?>"><?= h($campaign['frequencyLifetimeLabel'] ?? $dash) ?><?= !empty($campaign['fatigue']) ? ' <span class="chip tone-warn">' . h(t('saturace')) . '</span>' : '' ?></td>
                        <?php if ($showConv): ?>
                        <td class="num"><?= h($campaign['conversionsLabel']) ?></td>
                        <td class="num"><?= h($campaign['roasLabel']) ?></td>
                        <td class="num"><?= h($campaign['cpaLabel']) ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($metaAdsAdsets)): ?>
<section class="card card-clip">
    <?= allstat_ui_card_head(t('Sestavy (ad sets)'), 'Meta Ads', t('Výkon podle reklamních sestav = publik. Pod názvem je, na co sestava optimalizuje. <strong>Frekvence za celou dobu</strong> = kolikrát průměrně viděl reklamu tentýž člověk od spuštění sestavy (údaj z Mety); nad ~3 hrozí únava publika. Kde údaj z Mety zatím chybí, je v závorce jen denní frekvence.')) ?>
    <div class="table-wrap">
        <table class="table table-wide">
            <thead><tr>
                <th scope="col"><?= h(t('Sestava')) ?></th>
                <th scope="col" style="width: 96px"><?= h(t('Útrata')) ?></th>
                <th scope="col" style="width: 104px"><?= h(t('Zobrazení')) ?></th>
                <th scope="col" style="width: 88px" title="<?= h(t('Prokliky na odkaz')) ?>"><?= h(t('Prokliky')) ?></th>
                <th scope="col" style="width: 96px" title="<?= h(t('Za proklik na odkaz')) ?>"><?= h(t('Za proklik')) ?></th>
                <th scope="col" style="width: 96px" title="<?= h(t('Všechna kliknutí ÷ zobrazení, i lajky a rozbalení textu')) ?>"><?= h(t('CTR (vše)')) ?></th>
                <?php if ($showConv): ?><th scope="col" style="width: 96px"><?= h(t('Konverze')) ?></th><th scope="col" style="width: 80px">ROAS</th><th scope="col" style="width: 80px">CPA</th><?php endif; ?>
                <th scope="col" style="width: 96px" title="<?= h(t('Kolikrát průměrně viděl reklamu tentýž člověk za celou dobu')) ?>"><?= h(t('Frekvence')) ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ($metaAdsAdsets as $row): ?>
                    <tr>
                        <td><div class="post"><span class="post-text" title="<?= h($row['name']) ?>"><?= h($row['name']) ?></span><?php if (($row['optimizationLabel'] ?? '') !== ''): ?><span class="post-meta"><?= h($row['optimizationLabel']) ?></span><?php endif; ?></div></td>
                        <td class="num is-strong"><?= h($row['spendLabel']) ?></td>
                        <td class="num"><?= h($row['impressionsLabel'] ?? $dash) ?></td>
                        <td class="num"><?= h($row['linkClicksLabel'] ?? $dash) ?></td>
                        <td class="num"><?= h($row['cplcLabel'] ?? $dash) ?></td>
                        <td class="num"><?= h($row['ctrLabel'] ?? $dash) ?></td>
                        <?php if ($showConv): ?>
                        <td class="num"><?= h($row['conversionsLabel']) ?></td>
                        <td class="num"><?= h($row['roasLabel']) ?></td>
                        <td class="num"><?= h($row['cpaLabel']) ?></td>
                        <?php endif; ?>
                        <td class="num"><?= $freqCell($row) ?><?= $row['fatigue'] ? ' <span class="chip tone-warn">' . h(t('saturace')) . '</span>' : '' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($metaAdsCreatives)): ?>
<section class="card card-clip" data-ad-creatives>
    <?= allstat_ui_card_head(t('Kreativy (reklamy)'), 'Meta Ads', t('Výkon jednotlivých reklam. <strong>Za proklik</strong> = útrata ÷ kliknutí na odkaz, tedy stejné číslo, jaké u reklamy ukazuje Meta Business Suite („Za kliknutí na odkaz"). Pozor, celoúčtové CPC v dlaždicích nahoře počítá <em>všechna</em> kliknutí včetně lajků, proto bývá nižší. Šipka vlevo rozbalí kompletní metriky a hodnocení efektivity. Reklamy na povědomí se hodnotí cenou za 1 000 zobrazení a zájmem o video, ne prokliky. <strong>Únava</strong> = tentýž člověk viděl reklamu za celou dobu 3× a víc.')) ?>
    <div class="table-wrap">
        <table class="table table-wide">
            <thead><tr>
                <th scope="col"><?= h(t('Kreativa')) ?></th>
                <th scope="col" style="width: 96px"><?= h(t('Útrata')) ?></th>
                <th scope="col" style="width: 88px" title="<?= h(t('Kliknutí na odkaz')) ?>"><?= h(t('Prokliky')) ?></th>
                <th scope="col" style="width: 96px"><?= h(t('Za proklik')) ?></th>
                <?php if ($showConv): ?><th scope="col" style="width: 80px">ROAS</th><?php endif; ?>
                <th scope="col" style="width: 96px"><?= h(t('CTR (vše)')) ?></th>
                <th scope="col" style="width: 96px" title="<?= h(t('Kolikrát průměrně viděl reklamu tentýž člověk za celou dobu')) ?>"><?= h(t('Frekvence')) ?></th>
                <th scope="col" style="width: 136px"><?= h(t('Hodnocení')) ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ($metaAdsCreatives as $row): $v = $row['verdict'] ?? null; ?>
                    <tr>
                        <td><div class="post"><span class="post-text" title="<?= h($row['name']) ?>"><?= h($row['name']) ?></span><span class="post-meta"><?= $row['fatigue'] ? '<span class="chip tone-warn">' . h(t('únava')) . '</span>' : '' ?><?= $adDetailToggle() ?></span></div></td>
                        <td class="num is-strong"><?= h($row['spendLabel']) ?></td>
                        <td class="num"><?= h($row['linkClicksLabel'] ?? $dash) ?></td>
                        <td class="num is-strong"><?= h($row['cplcLabel'] ?? $dash) ?></td>
                        <?php if ($showConv): ?><td class="num"><?= h($row['roasLabel']) ?></td><?php endif; ?>
                        <td class="num"><?= h($row['ctrLabel']) ?></td>
                        <td class="num"><?= $freqCell($row) ?></td>
                        <td class="num"><?php if ($v): ?><span class="chip <?= h($verdictTone[(string) $v['level']] ?? 'tone-flat') ?>"><?= h((string) $v['label']) ?></span><?php else: ?><?= $dash ?><?php endif; ?></td>
                    </tr>
                    <?= $adDetailRow($row, $showConv ? 8 : 7) ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <script nonce="<?= h(allstat_nonce()) ?>">
    (function () {
        var root = document.querySelector('[data-ad-creatives]');
        if (!root) { return; }
        root.querySelectorAll('[data-ad-detail]').forEach(function (btn) {
            if (btn.dataset.bound) { return; }
            btn.dataset.bound = '1';
            btn.addEventListener('click', function () {
                var detail = document.getElementById(btn.getAttribute('data-ad-detail'));
                if (!detail) { return; }
                var open = detail.hidden;
                detail.hidden = !open;
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        });
    })();
    </script>
</section>
<?php endif; ?>
