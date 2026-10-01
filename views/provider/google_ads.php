<?php
/**
 * Provider-view partial — Google Ads. Included by index.php inside the generic provider view when
 * provider_key = 'google_ads'. In scope from index.php: $googleAdsNoConversions + h(). Presentation only —
 * the shared KPI grid (Útrata/ROAS/PNO/CPA/CPC/CTR/CPM…) + trend are rendered by index.php.
 */
?>
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
