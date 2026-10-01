<?php
/**
 * Provider-view partial — Instagram Business: media-level engagement (KPIs + Top + all posts).
 * Included by index.php inside the generic provider view when provider_key = 'instagram_business'.
 * In scope from index.php: $socialPosts + $socialPostsAll (paginated) + $data + $viewSourceId + h().
 * Engagement = lajky + komentáře + sdílení. Sdílení IG sync stahuje až od 29. 9. 2026 (insights.metric(shares)),
 * starší příspěvky je dostanou po „Stáhnout historii" nebo importu CSV z Business Suite.
 */
// IG má jen jeden typ reakce (lajk) — žádné love/haha/wow jako FB. „Rozpad" je tedy ❤️ lajky + 💬 komentáře + 🔁 sdílení.
$igReactions = static function (array $post): string {
    $shares = (int) ($post['shares'] ?? 0);
    return '<span class="rx" title="Lajky">❤️&nbsp;' . h(allstat_number((int) ($post['reactions'] ?? 0)))
        . '</span> <span class="rx" title="Komentáře">💬&nbsp;' . h(allstat_number((int) ($post['comments'] ?? 0))) . '</span>'
        . ($shares > 0 ? ' <span class="rx" title="Sdílení">🔁&nbsp;' . h(allstat_number($shares)) . '</span>' : '');
};
?>
<?php if (!empty($socialPosts)): ?>
<section class="analytics-grid" aria-label="Poznámka k datům z API">
    <article class="panel panel-wide">
        <p class="panel-help"><strong>Co Meta přes API u Instagramu neposkytuje (a proto to zde není):</strong> rozpad Interakcí na „Od sledujících / nesledujících", <strong>Kliknutí na odkaz</strong> a <strong>Návštěvy profilu</strong> – tyto účtové metriky Meta v lednu 2025 z API zrušila a nechává je jen ve svém Business Suite. <strong>Zobrazení</strong> (views) se u příspěvků doplní po nejbližší synchronizaci; do té doby ukazujeme <strong>Dosah</strong>.</p>
        <details class="more-posts csv-inline">
            <summary><div><h3>Doplnit data příspěvků z CSV <small>(export „Obsah")</small></h3><p>Instagram posílá všechna čísla příspěvků přes API, noční synchronizace ale obnovuje jen posledních 7 dní. Starší příspěvky zaktualizuješ tlačítkem Stáhnout historii u napojení, nebo tímhle exportem: v Business Suite Přehledy → Obsah → Exportovat data, záložka Instagram, jen tenhle účet a volba Příspěvek, pak Generovat a po chvíli Stáhnout export. <a class="text-link" href="<?= h(allstat_url($config, 'admin/social-import.php')) ?>#obsah">Návod krok za krokem</a>.</p></div></summary>
            <form method="post" action="<?= h(allstat_url($config, 'admin/social-import.php')) ?>" enctype="multipart/form-data" class="csv-inline-form">
                <?= allstat_csrf_field() ?>
                <input type="hidden" name="connection_id" value="<?= (int) $viewSourceId ?>">
                <input type="hidden" name="return_to" value="dashboard">
                <input type="file" name="csv" accept=".csv,text/csv" required>
                <button class="button-primary" type="submit">Importovat a doplnit</button>
            </form>
        </details>
    </article>
</section>

<section class="kpi-grid" aria-label="Příspěvky – souhrn">
    <article class="kpi-card kpi-violet">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="award"></i></span><span><strong>Počet příspěvků</strong> <small>(za období)</small></span></div>
        <div class="kpi-value"><?= h(allstat_number((int) $socialPosts['count'])) ?></div>
    </article>
    <article class="kpi-card kpi-rose">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="heart"></i></span><span><strong>Celkový engagement</strong> <small>(lajky, komentáře, sdílení)</small></span></div>
        <div class="kpi-value"><?= h($socialPosts['engagementLabel']) ?></div>
    </article>
    <?php if (((int) ($socialPosts['saved'] ?? 0)) > 0): ?>
    <article class="kpi-card kpi-green">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="bookmark"></i></span><span><strong>Uložení</strong> <small>(součet · Ø <?= h(allstat_number((float) ($socialPosts['avgSaved'] ?? 0), 1)) ?>/přísp.)</small></span>
            <span class="kpi-help" tabindex="0" aria-label="Vysvětlení"><i data-lucide="info"></i><span class="kpi-help-popover">Kolikrát si lidé příspěvek uložili (Instagram „saved"). Nejsilnější signál kvality obsahu, uložení = „tohle si chci najít později".</span></span>
        </div>
        <div class="kpi-value"><?= h($socialPosts['savedLabel']) ?></div>
    </article>
    <?php endif; ?>
    <article class="kpi-card kpi-blue">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="heart"></i></span><span><strong>Ø lajků</strong> <small>(na příspěvek)</small></span></div>
        <div class="kpi-value"><?= h(allstat_number((float) $socialPosts['avgReactions'], 1)) ?></div>
    </article>
    <article class="kpi-card kpi-cyan">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="message-circle"></i></span><span><strong>Ø komentářů</strong></span></div>
        <div class="kpi-value"><?= h(allstat_number((float) $socialPosts['avgComments'], 1)) ?></div>
    </article>
    <article class="kpi-card kpi-orange">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="calendar-days"></i></span><span><strong>Frekvence / týden</strong></span></div>
        <div class="kpi-value"><?= h(allstat_number((float) $socialPosts['perWeek'], 1)) ?></div>
    </article>
</section>

<?php include __DIR__ . '/_content_trends.php'; ?>

<?php
// Sdílený renderer bar grafu pro Okruh + Demografii (vrací řetězec, reuse .bar-chart). Podíl z položky nebo z celku.
$igPal = ['#2563eb', '#16a34a', '#8b5cf6', '#f59e0b', '#06b6d4', '#f43f5e', '#14b8a6'];
$igBar = static function (array $rows, array $pal): string {
    if (!$rows) { return ''; }
    $max = max(1, ...array_map(static fn ($r) => (int) $r['value'], $rows));
    $tot = array_sum(array_map(static fn ($r) => (int) $r['value'], $rows));
    $out = ''; $i = 0;
    foreach ($rows as $r) {
        $v = (int) $r['value'];
        $share = isset($r['share']) ? (int) round((float) $r['share']) : ($tot > 0 ? (int) round($v / $tot * 100) : 0);
        $out .= '<div class="bar-row"><span class="bar-row-label" title="' . h($r['label']) . '">' . h($r['label']) . '</span>'
            . '<div class="bar-row-track"><div class="bar-row-fill" style="width: ' . round(max(2, $v / $max * 100), 1) . '%; background: ' . $pal[$i % count($pal)] . ';"></div></div>'
            . '<span class="bar-row-value"><strong>' . h(allstat_number($v)) . '</strong> <small>(' . $share . ' %)</small></span></div>';
        $i++;
    }
    return '<div class="bar-chart">' . $out . '</div>';
};
?>

<?php if ($igAudience['hasData'] ?? false):
    $audBuild = static function (array $d): array {
        $rows = [];
        if ((int) ($d['follower'] ?? 0) > 0)    { $rows[] = ['label' => 'Sledující',   'value' => (int) $d['follower']]; }
        if ((int) ($d['nonFollower'] ?? 0) > 0) { $rows[] = ['label' => 'Nesledující', 'value' => (int) $d['nonFollower']]; }
        if ((int) ($d['unknown'] ?? 0) > 0)     { $rows[] = ['label' => 'Neznámí',     'value' => (int) $d['unknown']]; }
        return $rows;
    };
?>
<section class="analytics-grid" aria-label="Okruh uživatelů">
    <article class="panel panel-wide">
        <div class="panel-header"><h2>Okruh uživatelů <span>(sledující vs. nesledující)</span></h2></div>
        <p class="panel-help">Kolik z dosahu a zobrazení za zvolené období připadá na tvé <strong>sledující</strong> a kolik na <strong>nesledující</strong> účty. Vysoký podíl nesledujících znamená, že se obsah šíří dál (reels, doporučení, sdílení) a získáváš nové publikum.</p>
        <?php if ($rows = $audBuild($igAudience['reach'])): ?>
            <h3 class="content-subhead">Dosah</h3>
            <?= $igBar($rows, $igPal) ?>
        <?php endif; ?>
        <?php if ($rows = $audBuild($igAudience['views'])): ?>
            <h3 class="content-subhead">Zobrazení</h3>
            <?= $igBar($rows, $igPal) ?>
        <?php endif; ?>
    </article>
</section>
<?php endif; ?>

<?php if ($igDemographics['hasData'] ?? false):
    $ageRows = $igDemographics['age'];
    usort($ageRows, static fn ($a, $b) => strcmp((string) $a['label'], (string) $b['label']));
?>
<section class="analytics-grid" aria-label="Demografické údaje">
    <article class="panel panel-wide">
        <div class="panel-header"><h2>Demografické údaje <span>(sledující)</span></h2><?php if (!empty($igDemographics['date'])): ?><span class="panel-total">k <?= h((new DateTimeImmutable($igDemographics['date']))->format('j. n. Y')) ?></span><?php endif; ?></div>
        <p class="panel-help">Věk, pohlaví a lokalita tvých <strong>aktuálních sledujících</strong> (snímek z Meta API, nezávislý na zvoleném období). Dostupné u účtů se 100+ sledujícími.</p>
        <?php if ($ageRows): ?><h3 class="content-subhead">Věk</h3><?= $igBar($ageRows, $igPal) ?><?php endif; ?>
        <?php if ($igDemographics['gender']): ?><h3 class="content-subhead">Pohlaví</h3><?= $igBar($igDemographics['gender'], $igPal) ?><?php endif; ?>
        <?php if ($igDemographics['country']): ?><h3 class="content-subhead">Země</h3><?= $igBar(array_slice($igDemographics['country'], 0, 8), $igPal) ?><?php endif; ?>
        <?php if ($igDemographics['city']): ?><h3 class="content-subhead">Města (top 8)</h3><?= $igBar(array_slice($igDemographics['city'], 0, 8), $igPal) ?><?php endif; ?>
    </article>
</section>
<?php endif; ?>

<?php include __DIR__ . '/_heatmap.php'; ?>

<?php if (!empty($socialPosts['byFormat'])):
    // Top formáty jako graf: pruh = poměr k nejsilnějšímu formátu. Řadíme dle Zobrazení, s fallbackem
    // na Dosah → Engagement → Počet (IG Zobrazení se plní až po synchronizaci; Dosah bývá k dispozici dřív).
    $fmtRows = $socialPosts['byFormat'];
    $fmtMetric = 'views';
    if (array_sum(array_column($fmtRows, 'views')) <= 0) {
        if (array_sum(array_column($fmtRows, 'reach')) > 0)          { $fmtMetric = 'reach'; }
        elseif (array_sum(array_column($fmtRows, 'engagement')) > 0) { $fmtMetric = 'engagement'; }
        else                                                         { $fmtMetric = 'count'; }
    }
    // České tvary do „dle …", „podíl na …" a „… celkem" (2. pád, ať je to gramaticky správně).
    $fmtForms = [
        'views'      => ['dle' => 'dle zobrazení',       'na' => 'zobrazení',       'unit' => 'zobrazení'],
        'reach'      => ['dle' => 'dle dosahu',          'na' => 'dosahu',          'unit' => 'dosahu'],
        'engagement' => ['dle' => 'dle engagementu',     'na' => 'engagementu',     'unit' => 'engagementu'],
        'count'      => ['dle' => 'dle počtu příspěvků', 'na' => 'počtu příspěvků', 'unit' => 'příspěvků'],
    ][$fmtMetric];
    usort($fmtRows, static fn (array $a, array $b): int => (int) ($b[$fmtMetric] ?? 0) <=> (int) ($a[$fmtMetric] ?? 0));
    $fmtMax = max(1, ...array_map(static fn (array $f): int => (int) ($f[$fmtMetric] ?? 0), $fmtRows));
    $fmtTotal = array_sum(array_map(static fn (array $f): int => (int) ($f[$fmtMetric] ?? 0), $fmtRows));
    $fmtPalette = ['#2563eb', '#16a34a', '#8b5cf6', '#f59e0b', '#06b6d4', '#f43f5e'];
?>
<section class="analytics-grid" aria-label="Top formáty obsahu">
    <article class="panel panel-wide">
        <div class="panel-header">
            <h2>Top formáty obsahu <span>(<?= h($fmtForms['dle']) ?>)</span></h2>
            <?php if ($fmtTotal > 0): ?><span class="panel-total"><strong><?= h(allstat_number($fmtTotal)) ?></strong> <?= h($fmtForms['unit']) ?> celkem</span><?php endif; ?>
        </div>
        <p class="panel-help">Které formáty (foto, album/více fotek, video/reel) mají největší podíl na <?= h($fmtForms['na']) ?>. Delší pruh = silnější formát. Podrobná čísla včetně Ø zapojení na příspěvek jsou v tabulce pod grafem.</p>
        <div class="bar-chart">
            <?php foreach ($fmtRows as $i => $f): $val = (int) ($f[$fmtMetric] ?? 0); ?>
                <div class="bar-row">
                    <span class="bar-row-label" title="<?= h($f['label']) ?>"><?= h($f['label']) ?></span>
                    <div class="bar-row-track"><div class="bar-row-fill" style="width: <?= round(max(2, $val / $fmtMax * 100), 1) ?>%; background: <?= $fmtPalette[$i % count($fmtPalette)] ?>;"></div></div>
                    <span class="bar-row-value"><strong><?= h(allstat_number($val)) ?></strong> <small>(<?= $fmtTotal > 0 ? (int) round($val / $fmtTotal * 100) : 0 ?> %)</small></span>
                </div>
            <?php endforeach; ?>
        </div>
        <details class="more-posts" style="margin-top:14px;">
            <summary><div><h3>Podrobná tabulka formátů</h3><p>Počet příspěvků, zobrazení, Ø zapojení na příspěvek, engagement celkem a dosah pro každý typ obsahu.</p></div></summary>
            <div class="table-scroll" style="margin-top:10px;">
                <table>
                    <thead><tr><th>Typ obsahu</th><th>Počet</th><th>Zobrazení</th><th>Ø engagement</th><th>Engagement celkem</th><th>Dosah</th></tr></thead>
                    <tbody>
                        <?php foreach ($fmtRows as $f): ?>
                            <tr>
                                <td><?= h($f['label']) ?></td>
                                <td><?= h(allstat_number((int) $f['count'])) ?></td>
                                <td><strong><?= h(allstat_number((int) ($f['views'] ?? 0))) ?></strong></td>
                                <td><?= h(allstat_number((float) $f['avgEngagement'], 1)) ?></td>
                                <td><?= h(allstat_number((int) $f['engagement'])) ?></td>
                                <td><?= $f['reach'] > 0 ? h(allstat_number((int) $f['reach'])) : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
    </article>
</section>
<?php endif; ?>

<?php include __DIR__ . '/_stories.php'; ?>

<section class="tables-grid" aria-label="Top příspěvky" id="vsechny-prispevky">
    <article class="panel table-panel table-panel-wide">
        <div class="panel-header"><h2>Přehled obsahu <span>(Instagram)</span></h2></div>
        <p class="panel-help">Nejúspěšnější příspěvky za období podle engagementu (lajky + komentáře + sdílení). Přepni typ obsahu záložkami níže. Sloupec <strong>Reakce</strong> = ❤️ lajky · 💬 komentáře · 🔁 sdílení, Instagram přes API nemá typy reakcí jako Facebook, jen lajky. Klikni na text pro otevření na Instagramu.</p>
        <div class="content-tabs" role="tablist" aria-label="Filtr podle typu obsahu">
            <button type="button" class="content-tab is-active" data-content-filter="all">Vše</button>
            <button type="button" class="content-tab" data-content-filter="post">Příspěvky</button>
            <button type="button" class="content-tab" data-content-filter="reel">Reely</button>
        </div>
        <h3 class="content-subhead">Top 5 podle zapojení</h3>
        <div class="table-scroll">
            <table>
                <thead><tr><th>Příspěvek</th><th>Datum</th><th>Zobrazení</th><th>Dosah</th><th>Reakce</th><th>Zapojení %</th><th>Engagement</th></tr></thead>
                <tbody>
                    <?php foreach ($socialPosts['top'] as $post): ?>
                        <tr class="content-row" data-content-type="<?= ($post['type'] ?? 'post') === 'reel' ? 'reel' : 'post' ?>">
                            <td><?php if (($post['permalink'] ?? '') !== ''): ?><a class="text-link" href="<?= h($post['permalink']) ?>" target="_blank" rel="noopener"><?= h($post['message']) ?></a><?php else: ?><?= h($post['message']) ?><?php endif; ?></td>
                            <td><?= h($post['date']) ?></td>
                            <td class="col-views"><strong><?= h($post['viewsLabel'] ?? '—') ?></strong></td><td><?= h($post['reachLabel'] ?? '—') ?></td>
                            <td class="rx-cell"><?= $igReactions($post) ?></td>
                            <td><?= h($post['engRateLabel'] ?? '—') ?></td>
                            <td><strong><?= h($post['engagementLabel']) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($socialPosts['top'])): ?>
                        <tr><td colspan="7" class="table-muted">Za vybrané období nebyl publikován žádný příspěvek. Spusť „Stáhnout historii" nebo zvol delší období.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if (!empty($socialPosts['topSaved'])): ?>
        <h3 class="content-subhead">Top 5 podle uložení <small>(nejsilnější signál kvality obsahu)</small></h3>
        <div class="table-scroll">
            <table>
                <thead><tr><th>Příspěvek</th><th>Datum</th><th>Uložení</th><th>Zobrazení</th><th>Reakce</th><th>Engagement</th></tr></thead>
                <tbody>
                    <?php foreach ($socialPosts['topSaved'] as $post): ?>
                        <tr>
                            <td><?php if (($post['permalink'] ?? '') !== ''): ?><a class="text-link" href="<?= h($post['permalink']) ?>" target="_blank" rel="noopener"><?= h($post['message']) ?></a><?php else: ?><?= h($post['message']) ?><?php endif; ?></td>
                            <td><?= h($post['date']) ?></td>
                            <td><strong><?= h($post['savedLabel'] ?? '—') ?></strong></td>
                            <td class="col-views"><?= h($post['viewsLabel'] ?? '—') ?></td>
                            <td class="rx-cell"><?= $igReactions($post) ?></td>
                            <td><strong><?= h($post['engagementLabel']) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if (!empty($socialPostsAll) && (int) ($socialPostsAll['total'] ?? 0) > 5): ?>
        <?php
        $ppBase = '?domain_id=' . (int) $data['domain']['id'] . '&start=' . urlencode($data['range']['start']) . '&end=' . urlencode($data['range']['end']) . '&source_id=' . (int) $viewSourceId;
        $ppLink = static fn (int $n): string => $ppBase . '&posts_page=' . $n . '#vsechny-prispevky';
        ?>
        <details class="more-posts" <?= (int) ($socialPostsAll['page'] ?? 1) > 1 ? 'open' : '' ?>>
            <summary><div><h3>Zobrazit všechny příspěvky za období</h3><p>Kompletní výpis příspěvků (<?= (int) $socialPostsAll['total'] ?>) se stránkováním. Filtr typu obsahu výše platí i tady.</p></div></summary>
            <div class="table-scroll" style="margin-top:10px;">
                <table>
                    <thead><tr><th>Příspěvek</th><th>Datum</th><th>Zobrazení</th><th>Dosah</th><th>Reakce</th><th>Zapojení %</th><th>Engagement</th></tr></thead>
                    <tbody>
                        <?php foreach ($socialPostsAll['rows'] as $post): $msg = mb_strimwidth((string) $post['message'], 0, 90, '…'); ?>
                            <tr class="content-row" data-content-type="<?= ($post['type'] ?? 'post') === 'reel' ? 'reel' : 'post' ?>">
                                <td><?php if (($post['permalink'] ?? '') !== ''): ?><a class="text-link" href="<?= h($post['permalink']) ?>" target="_blank" rel="noopener"><?= h($msg) ?></a><?php else: ?><?= h($msg) ?><?php endif; ?></td>
                                <td><?= h($post['date']) ?></td>
                                <td class="col-views"><strong><?= h($post['viewsLabel'] ?? '—') ?></strong></td><td><?= h($post['reachLabel'] ?? '—') ?></td>
                                <td class="rx-cell"><?= $igReactions($post) ?></td>
                                <td><?= h($post['engRateLabel'] ?? '—') ?></td>
                                <td><strong><?= h($post['engagementLabel']) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ((int) ($socialPostsAll['totalPages'] ?? 1) > 1): ?>
            <nav class="pagination" aria-label="Stránkování příspěvků">
                <a class="text-link <?= (int) $socialPostsAll['page'] <= 1 ? 'is-disabled' : '' ?>" href="<?= (int) $socialPostsAll['page'] > 1 ? h($ppLink((int) $socialPostsAll['page'] - 1)) : '#' ?>">← Předchozí</a>
                <span class="pagination-info">Stránka <?= (int) $socialPostsAll['page'] ?> z <?= (int) $socialPostsAll['totalPages'] ?></span>
                <a class="text-link <?= (int) $socialPostsAll['page'] >= (int) $socialPostsAll['totalPages'] ? 'is-disabled' : '' ?>" href="<?= (int) $socialPostsAll['page'] < (int) $socialPostsAll['totalPages'] ? h($ppLink((int) $socialPostsAll['page'] + 1)) : '#' ?>">Další →</a>
            </nav>
            <?php endif; ?>
        </details>
        <?php endif; ?>
    </article>
</section>

<script nonce="<?= h(allstat_nonce()) ?>">
(function () {
    var tabs = document.querySelectorAll('.content-tab');
    if (!tabs.length) { return; }
    tabs.forEach(function (t) {
        t.addEventListener('click', function () {
            var f = t.getAttribute('data-content-filter');
            tabs.forEach(function (x) { x.classList.toggle('is-active', x === t); });
            document.querySelectorAll('.content-row').forEach(function (r) {
                r.style.display = (f === 'all' || r.getAttribute('data-content-type') === f) ? '' : 'none';
            });
        });
    });
})();
</script>
<?php endif; ?>
