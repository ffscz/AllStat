<?php
/**
 * Provider-view partial — Facebook Pages: post-level analytics (enriched KPIs + reactions + best-time
 * heatmap + by-format breakdown + Top příspěvky + all posts).
 * Included by index.php inside the generic provider view when provider_key = 'facebook_pages'.
 * In scope from index.php: $socialPosts + $socialPostsAll (paginated) + $data + $viewSourceId + h().
 * Presentation only — no DB access here; the shared page-metric KPI grid + trend stay in index.php.
 */
$postBadge = static function (string $type): string {
    return match ($type) {
        'reel' => ' <span class="status-badge status-warning">Reel</span>',
        'story' => ' <span class="status-badge">Story</span>',
        default => '',
    };
};
// Renders the period-over-period delta line for a KPI card from $socialPosts['deltas'][$key].
$delta = static function (string $key) use ($socialPosts): string {
    $d = $socialPosts['deltas'][$key] ?? null;
    if (!$d || ($d['trend'] ?? 'none') === 'none') {
        return '<div class="kpi-meta"><span class="trend trend-none">bez srovnání</span></div>';
    }
    $cls = $d['trend'] === 'up' ? 'trend-up' : 'trend-down';
    $arrow = $d['trend'] === 'up' ? '&uarr;' : '&darr;';

    return '<div class="kpi-meta"><span class="trend ' . $cls . '">' . $arrow . ' ' . h($d['label'])
        . '</span> <span>vs. předchozí období</span></div>';
};
// Per-post reaction breakdown as emoji + count — ONLY reactions that actually occurred (>0).
$rxBreakdown = static function (array $post): string {
    $bd = $post['reactionBreakdown'] ?? [];
    if (!$bd) {
        return '<span class="rx-empty" title="Žádné reakce">—</span>';
    }
    $parts = [];
    foreach ($bd as $r) {
        $parts[] = '<span class="rx" title="' . h($r['label']) . '">' . $r['emoji'] . '&nbsp;' . h(allstat_number((int) $r['count'])) . '</span>';
    }

    return implode(' ', $parts);
};
// Rozbalovací detail příspěvku ve členění Meta Business Suite. Renderuje se jako druhý <tr>, který je
// skrytý, dokud uživatel neklikne na šipku v prvním sloupci (obsluha je ve skriptu na konci šablony).
$detailRowId = 0;
$postDetail = static function (array $post, int $colspan) use (&$detailRowId): string {
    $groups = $post['detail'] ?? [];
    if (!$groups) { return ''; }
    $detailRowId++;
    $id = 'post-detail-' . $detailRowId;
    $html = '<tr class="post-detail-row" id="' . $id . '" hidden><td colspan="' . $colspan . '"><div class="post-detail">';
    foreach ($groups as $g) {
        if (empty($g['items'])) { continue; }
        $html .= '<div class="post-detail-group"><h4>' . h((string) $g['title']) . '</h4><dl>';
        foreach ($g['items'] as $it) {
            $hint = (string) ($it['hint'] ?? '');
            $html .= '<div class="post-detail-item"' . ($hint !== '' ? ' title="' . h($hint) . '"' : '') . '>'
                . '<dt>' . h((string) $it['label']) . '</dt><dd>' . h((string) $it['value']) . '</dd></div>';
        }
        $html .= '</dl></div>';
    }

    return $html . '</div></td></tr>';
};
// Tlačítko, které detail otevírá. Sedí v buňce s textem příspěvku, ať nepřibývá sloupec.
$detailToggle = static function () use (&$detailRowId): string {
    return '<button type="button" class="post-detail-toggle" aria-expanded="false" aria-controls="post-detail-'
        . ($detailRowId + 1) . '" title="Zobrazit podrobné statistiky příspěvku"><span aria-hidden="true">▾</span><span class="sr-only">Detail příspěvku</span></button>';
};
?>
<?php if (!empty($socialPosts)): ?>
<section class="analytics-grid" aria-label="Příspěvky – informace">
    <article class="panel panel-wide">
        <div class="panel-header"><h2>Příspěvky stránky <span>(Facebook)</span></h2></div>
        <p class="panel-help">
            Naposledy synchronizováno: <strong><?= h($data['meta']['lastSync'] ?? 'nesync.') ?></strong>.
            Dnešní příspěvky se započítají až po nejbližší synchronizaci, Facebook nevrací dnešek v reálném čase, data jsou za uzavřené dny.
            Datum a čas jsou v zóně Europe/Prague. <strong>Stories</strong> se do počtu příspěvků ani frekvence nezapočítávají (jsou dočasné).
            Dosah, míra zapojení a prokliky vyžadují oprávnění <em>read_insights</em>; než je stránka udělí, zobrazují se jako „—".
        </p>
    </article>
</section>

<section class="analytics-grid" aria-label="Poznámka k datům z API">
    <article class="panel panel-wide">
        <p class="panel-help"><strong>Co Meta přes API neposkytuje (a proto to zde není):</strong> rozpad Interakcí na „Od sledujících / Od nesledujících" – Meta ho ukazuje jen ve svém Business Suite, přes Graph API ho pro engagement nelze získat. <strong>Dosah</strong> u některých příspěvků chybí („—"), protože Facebook ho u příspěvků přes API nevrací vždy; klíčová metrika je proto <strong>Zobrazení</strong> (views), kterou vracíme spolehlivě. <strong>Demografické údaje</strong> (věk / pohlaví / lokalita) Meta z Facebook API odebrala, proto je doplňujeme z CSV exportu „Okruh uživatelů" (viz sekce Demografické údaje níže). Rozpad publika na sledující/nesledující přes Facebook API nejde vůbec (na Instagramu obojí funguje přímo z API).</p>
        <details class="more-posts csv-inline">
            <summary><div><h3>Doplnit data z CSV <small>(Meta Business Suite)</small></h3><p>Nahraj exporty z Business Suite, které přes Graph API nejdou. Stejné importy jsou i na stránce <a class="text-link" href="<?= h(allstat_url($config, 'admin/social-import.php')) ?>">Import z CSV</a>.</p></div></summary>
            <div class="csv-inline-block">
                <p class="csv-inline-cap"><strong>Obsah příspěvků:</strong> v Business Suite Přehledy → Obsah → Exportovat data. Záložka Facebook, jen tahle stránka, všech 5 předvoleb metrik, Zobrazení dat Dlouhodobě, Úroveň obsahu Příspěvek. Pak Generovat a po chvíli Stáhnout export. Doplní dosah, rozpad organické a placené a dobu sledování. <a class="text-link" href="<?= h(allstat_url($config, 'admin/social-import.php')) ?>#obsah">Návod krok za krokem</a></p>
                <form method="post" action="<?= h(allstat_url($config, 'admin/social-import.php')) ?>" enctype="multipart/form-data" class="csv-inline-form">
                    <?= allstat_csrf_field() ?>
                    <input type="hidden" name="import_type" value="content">
                    <input type="hidden" name="connection_id" value="<?= (int) $viewSourceId ?>">
                    <input type="hidden" name="return_to" value="dashboard">
                    <input type="file" name="csv" accept=".csv,text/csv" required>
                    <button class="button-primary" type="submit">Importovat obsah</button>
                </form>
            </div>
            <div class="csv-inline-block">
                <p class="csv-inline-cap"><strong>Publikum a demografie:</strong> v Business Suite Přehledy → Okruh uživatelů (Výběr entity: Facebook) → Exportovat → Exportovat jako CSV, soubor se stáhne hned. Doplní věk, pohlaví, města a země do sekce „Demografické údaje". <a class="text-link" href="<?= h(allstat_url($config, 'admin/social-import.php')) ?>#publikum">Návod krok za krokem</a></p>
                <form method="post" action="<?= h(allstat_url($config, 'admin/social-import.php')) ?>" enctype="multipart/form-data" class="csv-inline-form">
                    <?= allstat_csrf_field() ?>
                    <input type="hidden" name="import_type" value="audience">
                    <input type="hidden" name="connection_id" value="<?= (int) $viewSourceId ?>">
                    <input type="hidden" name="return_to" value="dashboard">
                    <input type="file" name="csv" accept=".csv,text/csv" required>
                    <button class="button-primary" type="submit">Importovat demografii</button>
                </form>
            </div>
        </details>
    </article>
</section>

<section class="kpi-grid" aria-label="Příspěvky – souhrn">
    <article class="kpi-card kpi-violet">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="award"></i></span><span><strong>Počet příspěvků</strong> <small>(za období)</small></span></div>
        <div class="kpi-value"><?= h(allstat_number((int) $socialPosts['count'])) ?></div>
        <?= $delta('count') ?>
    </article>
    <article class="kpi-card kpi-blue">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="eye"></i></span><span><strong>Dosah příspěvků</strong> <small>(součet)</small></span>
            <span class="kpi-help" tabindex="0" aria-label="Vysvětlení"><i data-lucide="info"></i><span class="kpi-help-popover">Součet unikátního dosahu jednotlivých příspěvků (post_impressions_unique). Skutečný unikátní dosah za období bývá nižší (lidé se opakují). Vyžaduje read_insights.</span></span>
        </div>
        <div class="kpi-value"><?= $socialPosts['reach'] > 0 ? h(allstat_number((int) $socialPosts['reach'])) : '—' ?></div>
        <?= $delta('reach') ?>
    </article>
    <article class="kpi-card kpi-rose">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="activity"></i></span><span><strong>Míra zapojení</strong> <small>(engagement / dosah)</small></span>
            <span class="kpi-help" tabindex="0" aria-label="Vysvětlení"><i data-lucide="info"></i><span class="kpi-help-popover">Engagement rate = (reakce + komentáře + sdílení) / dosah. Nejlepší srovnatelná metrika napříč příspěvky. Bez dosahu (read_insights) se nezobrazí.</span></span>
        </div>
        <div class="kpi-value"><?= h($socialPosts['engRateLabel']) ?></div>
        <?= $delta('engRate') ?>
    </article>
    <article class="kpi-card kpi-orange">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="heart"></i></span><span><strong>Celkový engagement</strong> <small>(reakce+kom.+sdíl.)</small></span></div>
        <div class="kpi-value"><?= h($socialPosts['engagementLabel']) ?></div>
        <?= $delta('engagement') ?>
    </article>
    <article class="kpi-card kpi-green">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="user-plus"></i></span><span><strong>Čistý přírůstek fanoušků</strong> <small>(noví − odhlášení)</small></span></div>
        <div class="kpi-value"><?= ($socialPosts['followers']['net'] >= 0 ? '+' : '') . h(allstat_number((int) $socialPosts['followers']['net'])) ?></div>
        <div class="kpi-meta"><span class="trend trend-none"><?= h(allstat_number((int) $socialPosts['followers']['new'])) ?> nových · <?= h(allstat_number((int) $socialPosts['followers']['lost'])) ?> odhl.</span></div>
    </article>
    <article class="kpi-card kpi-cyan">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="calendar-days"></i></span><span><strong>Frekvence / týden</strong></span></div>
        <div class="kpi-value"><?= h(allstat_number((float) $socialPosts['perWeek'], 1)) ?></div>
        <div class="kpi-meta"><span class="trend trend-none">Ø reakcí <?= h(allstat_number((float) $socialPosts['avgReactions'], 1)) ?> · Ø kom. <?= h(allstat_number((float) $socialPosts['avgComments'], 1)) ?></span></div>
    </article>
    <article class="kpi-card kpi-teal">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="mouse-pointer-click"></i></span><span><strong>Prokliky na web</strong> <small>(z příspěvků)</small></span>
            <span class="kpi-help" tabindex="0" aria-label="Vysvětlení"><i data-lucide="info"></i><span class="kpi-help-popover">Součet prokliků na příspěvcích (post_clicks), odkazy, „zobrazit více", fotky. Vyžaduje read_insights.</span></span>
        </div>
        <div class="kpi-value"><?= $socialPosts['clicks'] > 0 ? h(allstat_number((int) $socialPosts['clicks'])) : '—' ?></div>
    </article>
    <?php if ((int) $socialPosts['videoViews'] > 0): ?>
    <article class="kpi-card kpi-violet">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="play-circle"></i></span><span><strong>Zhlédnutí videa</strong> <small>(3 s+)</small></span></div>
        <div class="kpi-value"><?= h(allstat_number((int) $socialPosts['videoViews'])) ?></div>
    </article>
    <?php endif; ?>
    <?php if ((int) ($socialPosts['watchTimeSec'] ?? 0) > 0): ?>
    <article class="kpi-card kpi-violet">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="clock"></i></span><span><strong>Doba sledování</strong> <small>(videa a reely celkem)</small></span>
            <span class="kpi-help" tabindex="0" aria-label="Vysvětlení"><i data-lucide="info"></i><span class="kpi-help-popover">Celková doba sledování videí a reelů za období (post_video_view_time). Naplní se ze synchronizace nebo importu CSV z Business Suite.</span></span>
        </div>
        <div class="kpi-value"><?= h($socialPosts['watchTimeLabel']) ?></div>
    </article>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/_content_trends.php'; ?>

<?php if (!empty($fbDemographics['hasData'])):
    $demPal = ['#2563eb', '#16a34a', '#8b5cf6', '#f59e0b', '#06b6d4', '#f43f5e', '#14b8a6'];
    $fbAge = $fbDemographics['age'];
    usort($fbAge, static fn ($a, $b) => strcmp((string) $a['label'], (string) $b['label']));
?>
<section class="analytics-grid" aria-label="Demografické údaje">
    <article class="panel panel-wide">
        <div class="panel-header"><h2>Demografické údaje <span>(publikum)</span></h2><?php if (!empty($fbDemographics['date'])): ?><span class="panel-total">k <?= h((new DateTimeImmutable($fbDemographics['date']))->format('j. n. Y')) ?></span><?php endif; ?></div>
        <p class="panel-help">Věk, pohlaví a lokalita publika Facebook stránky (podíl v %). Facebook tuto demografii přes API neposkytuje, doplňuje se z exportu <strong>Okruh uživatelů</strong> z Business Suite přes <a class="text-link" href="<?= h(allstat_url($config, 'admin/social-import.php')) ?>">Import z CSV</a>.</p>
        <?php
        // Staleness výzva: publikum se mění pomalu, ale export starší než ~35 dní se vyplatí obnovit.
        $fbDemoAge = !empty($fbDemographics['date']) ? (int) floor((time() - strtotime((string) $fbDemographics['date'])) / 86400) : null;
        if ($fbDemoAge !== null && $fbDemoAge > 35):
        ?>
        <div class="notice notice-warn"><strong>Demografie je stará <?= (int) $fbDemoAge ?> dní.</strong> Publikum se mění pomalu, ale pro čerstvá čísla stáhni v Business Suite nový export (<a class="text-link" href="https://business.facebook.com/latest/insights/people" target="_blank" rel="noopener">Přehledy → Okruh uživatelů</a> → Exportovat → Exportovat jako CSV) a <a class="text-link" href="<?= h(allstat_url($config, 'admin/social-import.php')) ?>#publikum">importuj ho</a>.</div>
        <?php endif; ?>
        <?php if ($fbAge): ?><h3 class="content-subhead">Věk</h3><?= allstat_bar_rows_html($fbAge, $demPal, true) ?><?php endif; ?>
        <?php if (!empty($fbDemographics['gender'])): ?><h3 class="content-subhead">Pohlaví</h3><?= allstat_bar_rows_html($fbDemographics['gender'], $demPal, true) ?><?php endif; ?>
        <?php if (!empty($fbDemographics['country'])): ?><h3 class="content-subhead">Země</h3><?= allstat_bar_rows_html(array_slice($fbDemographics['country'], 0, 8), $demPal, true) ?><?php endif; ?>
        <?php if (!empty($fbDemographics['city'])): ?><h3 class="content-subhead">Nejčastější města</h3><?= allstat_bar_rows_html(array_slice($fbDemographics['city'], 0, 10), $demPal, true) ?><?php endif; ?>
    </article>
</section>
<?php endif; ?>

<?php
$reactions = $socialPosts['reactions'] ?? [];
$reactionMeta = [
    'like' => ['👍', 'To se mi líbí'], 'love' => ['❤️', 'Super'], 'haha' => ['😂', 'Haha'],
    'wow' => ['😮', 'Paráda'], 'sad' => ['😢', 'To mě mrzí'], 'angry' => ['😡', 'To mě štve'],
];
if (array_sum($reactions) > 0):
?>
<section class="analytics-grid" aria-label="Rozpad reakcí">
    <article class="panel panel-wide">
        <div class="panel-header"><h2>Rozpad reakcí <span>(za období)</span></h2></div>
        <p class="panel-help">Jak lidé na příspěvky reagovali, sentiment, ne jen počet. Pomáhá poznat, který obsah vyvolává emoce.</p>
        <div class="reaction-row">
            <?php foreach ($reactionMeta as $key => [$emoji, $label]): ?>
                <div class="reaction-cell" title="<?= h($label) ?>">
                    <span class="reaction-emoji"><?= $emoji ?></span>
                    <span class="reaction-count"><?= h(allstat_number((int) ($reactions[$key] ?? 0))) ?></span>
                    <span class="reaction-label"><?= h($label) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </article>
</section>
<?php endif; ?>

<?php include __DIR__ . '/_heatmap.php'; ?>

<?php if (!empty($socialPosts['byFormat'])):
    // Top formáty jako graf: pruh = poměr k nejsilnějšímu formátu. Řadíme dle Zobrazení, s fallbackem
    // na Dosah → Engagement → Počet (Zobrazení/Dosah se u části dat plní až po synchronizaci nebo CSV importu).
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
        <p class="panel-help">Které formáty příspěvků (foto, video, reel, odkaz…) mají největší podíl na <?= h($fmtForms['na']) ?>. Delší pruh = silnější formát. Podrobná čísla včetně Ø zapojení na příspěvek jsou v tabulce pod grafem.</p>
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
        <div class="panel-header"><h2>Přehled obsahu <span>(Facebook)</span></h2></div>
        <p class="panel-help">Nejúspěšnější příspěvky za období podle zapojení. Přepni typ obsahu záložkami níže. Klikni na text příspěvku pro otevření na Facebooku, reels mají odznak. Šipka vlevo rozbalí podrobné statistiky ve stejném členění, jaké ukazuje Meta Business Suite. Reakce i komentáře jsou počítané jen na originálu příspěvku, tedy přesně jako v Business Suite; reakce nasbírané na cizích přesdíleních najdeš v detailu.</p>
        <div class="content-tabs" role="tablist" aria-label="Filtr podle typu obsahu">
            <button type="button" class="content-tab is-active" data-content-filter="all">Vše</button>
            <button type="button" class="content-tab" data-content-filter="post">Příspěvky</button>
            <button type="button" class="content-tab" data-content-filter="reel">Reely</button>
        </div>
        <h3 class="content-subhead">Top 5 podle zapojení</h3>
        <div class="table-scroll">
            <table>
                <thead><tr><th>Příspěvek</th><th>Datum</th><th>Zobrazení</th><th>Dosah</th><th>Reakce</th><th>Komentáře</th><th>Sdílení</th><th>Zapojení %</th><th>Engagement</th></tr></thead>
                <tbody>
                    <?php foreach ($socialPosts['top'] as $post): ?>
                        <tr class="content-row" data-content-type="<?= ($post['type'] ?? 'post') === 'reel' ? 'reel' : 'post' ?>">
                            <td><?= $detailToggle() ?><?php if (($post['permalink'] ?? '') !== ''): ?><a class="text-link" href="<?= h($post['permalink']) ?>" target="_blank" rel="noopener"><?= h($post['message']) ?></a><?php else: ?><?= h($post['message']) ?><?php endif; ?><?= $postBadge((string) ($post['type'] ?? 'post')) ?></td>
                            <td><?= h($post['date']) ?></td>
                            <td><strong><?= h($post['viewsLabel'] ?? '—') ?></strong></td>
                            <td><?= h($post['reachLabel'] ?? '—') ?></td>
                            <td class="rx-cell"><?= $rxBreakdown($post) ?></td>
                            <td><?= h(allstat_number($post['comments'])) ?></td>
                            <td><?= h(allstat_number($post['shares'])) ?></td>
                            <td><?= h($post['engRateLabel'] ?? '—') ?></td>
                            <td><strong><?= h($post['engagementLabel']) ?></strong></td>
                        </tr>
                        <?= $postDetail($post, 9) ?>
                    <?php endforeach; ?>
                    <?php if (empty($socialPosts['top'])): ?>
                        <tr><td colspan="9" class="table-muted">Za vybrané období nebyl publikován žádný příspěvek. Spusť „Stáhnout historii" nebo zvol delší období (FB příspěvky nejsou pro dnešek/realtime).</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if (!empty($socialPostsAll) && (int) ($socialPostsAll['total'] ?? 0) > 5): ?>
        <?php
        $ppBase = '?domain_id=' . (int) $data['domain']['id'] . '&start=' . urlencode($data['range']['start']) . '&end=' . urlencode($data['range']['end']) . '&source_id=' . (int) $viewSourceId;
        $ppLink = static fn (int $n): string => $ppBase . '&posts_page=' . $n . '#vsechny-prispevky';
        ?>
        <details class="more-posts" <?= (int) ($socialPostsAll['page'] ?? 1) > 1 ? 'open' : '' ?>>
            <summary><div><h3>Zobrazit všechny příspěvky za období</h3><p>Kompletní výpis příspěvků (<?= (int) $socialPostsAll['total'] ?>) s možností stránkování. Filtr typu obsahu výše platí i tady.</p></div></summary>
            <div class="table-scroll" style="margin-top:10px;">
                <table>
                    <thead><tr><th>Příspěvek</th><th>Datum</th><th>Zobrazení</th><th>Dosah</th><th>Reakce</th><th>Komentáře</th><th>Sdílení</th><th>Zapojení %</th><th>Engagement</th></tr></thead>
                    <tbody>
                        <?php foreach ($socialPostsAll['rows'] as $post): $msg = mb_strimwidth((string) $post['message'], 0, 90, '…'); ?>
                            <tr class="content-row" data-content-type="<?= ($post['type'] ?? 'post') === 'reel' ? 'reel' : 'post' ?>">
                                <td><?= $detailToggle() ?><?php if (($post['permalink'] ?? '') !== ''): ?><a class="text-link" href="<?= h($post['permalink']) ?>" target="_blank" rel="noopener"><?= h($msg) ?></a><?php else: ?><?= h($msg) ?><?php endif; ?><?= $postBadge((string) ($post['type'] ?? 'post')) ?></td>
                                <td><?= h($post['date']) ?></td>
                                <td><strong><?= h($post['viewsLabel'] ?? '—') ?></strong></td>
                                <td><?= h($post['reachLabel'] ?? '—') ?></td>
                                <td class="rx-cell"><?= $rxBreakdown($post) ?></td>
                                <td><?= h(allstat_number($post['comments'])) ?></td>
                                <td><?= h(allstat_number($post['shares'])) ?></td>
                                <td><?= h($post['engRateLabel'] ?? '—') ?></td>
                                <td><strong><?= h($post['engagementLabel']) ?></strong></td>
                            </tr>
                            <?= $postDetail($post, 9) ?>
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
    // Skrytí odfiltrovaného řádku musí schovat i jeho detail, jinak by osiřelý detail zůstal viset v tabulce.
    var hideRow = function (row, hidden) {
        row.style.display = hidden ? 'none' : '';
        var d = row.nextElementSibling;
        if (d && d.classList.contains('post-detail-row') && hidden) { d.hidden = true; }
        if (d && d.classList.contains('post-detail-row') && hidden) {
            var b = row.querySelector('.post-detail-toggle');
            if (b) { b.setAttribute('aria-expanded', 'false'); b.classList.remove('is-open'); }
        }
    };
    tabs.forEach(function (t) {
        t.addEventListener('click', function () {
            var f = t.getAttribute('data-content-filter');
            tabs.forEach(function (x) { x.classList.toggle('is-active', x === t); });
            document.querySelectorAll('.content-row').forEach(function (r) {
                hideRow(r, !(f === 'all' || r.getAttribute('data-content-type') === f));
            });
        });
    });
})();
(function () {
    document.querySelectorAll('.post-detail-toggle').forEach(function (btn) {
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
<?php endif; ?>
