<?php
/**
 * Provider-view partial — YouTube kanál: videa (souhrn za období, podle formátu, Top videa za období i celkově)
 * + publikum (zdroje návštěvnosti za období, geografie a demografie jako snapshot). Sdílené KPI dlaždice
 * (odběratelé, zhlédnutí, sledovaný čas, …) a trend graf rendruje index.php nad tímhle partialem.
 * In scope: $youtubeVideos + $youtubeAudience + $data + h(). Presentation only.
 */
$ytV = is_array($youtubeVideos ?? null) ? $youtubeVideos : [];
$ytA = is_array($youtubeAudience ?? null) ? $youtubeAudience : [];
$ytC = is_array($youtubeCatalog ?? null) ? $youtubeCatalog : [];
$ytPal = ['#FF0000', '#2563eb', '#16a34a', '#8b5cf6', '#f59e0b', '#06b6d4', '#f43f5e', '#14b8a6', '#64748b', '#a16207'];
$ytRow = static function (array $v): string {
    $title = ($v['permalink'] ?? '') !== ''
        ? '<a class="text-link" href="' . h($v['permalink']) . '" target="_blank" rel="noopener">' . h(mb_strimwidth($v['title'], 0, 80, '…')) . '</a>'
        : h($v['title']);

    return '<tr>'
        . '<td>' . $title . '</td>'
        . '<td data-sort="' . h($v['metricDate'] ?? '') . '">' . h($v['date']) . '</td>'
        . '<td>' . h($v['formatLabel']) . ' <small class="table-muted">' . h($v['durationLabel']) . '</small></td>'
        . '<td><strong>' . h($v['viewsLabel']) . '</strong></td>'
        . '<td>' . h($v['watchLabel']) . '</td>'
        . '<td>' . h($v['avgLabel']) . ($v['avgPct'] > 0 ? ' <small class="table-muted">(' . h($v['avgPctLabel']) . ')</small>' : '') . '</td>'
        . '<td>' . h(allstat_number((int) $v['likes'])) . '</td>'
        . '<td>' . h(allstat_number((int) $v['comments'])) . '</td>'
        . '<td>' . h(allstat_number((int) $v['shares'])) . '</td>'
        . '<td>' . ($v['subs'] > 0 ? '+' . h(allstat_number((int) $v['subs'])) : '—') . '</td>'
        . '<td>' . h($v['engRateLabel']) . '</td>'
        . '</tr>';
};
$ytHead = '<thead><tr><th>Video</th><th>Publikováno</th><th>Formát</th><th>Zhlédnutí</th><th>Sledovaný čas</th><th>Ø doba (% videa)</th><th>Líbí se</th><th>Komentáře</th><th>Sdílení</th><th>Odběry</th><th>Zapojení %</th></tr></thead>';
?>
<section class="analytics-grid" aria-label="Videa – informace">
    <article class="panel panel-wide">
        <div class="panel-header"><h2>Videa kanálu <span>(YouTube)</span></h2></div>
        <p class="panel-help">
            Naposledy synchronizováno: <strong><?= h($data['meta']['lastSync'] ?? 'nesync.') ?></strong>.
            Dlaždice nahoře jsou <strong>za zvolené období</strong> (YouTube Analytics po dnech, včetně zhlédnutí starších videí).
            Tabulky videí níže ukazují videa <strong>publikovaná v období</strong> a u každého <strong>celoživotní</strong> čísla
            (zhlédnutí, sledovaný čas, Ø doba zhlédnutí, odběry získané z videa), stejně jako YouTube Studio v záložce Obsah.
            Poslední 2-3 dny YouTube doplňuje se zpožděním, denní sync je přepisuje.
        </p>
    </article>
</section>

<?php if (!empty($ytV['hasData'])): ?>
<section class="kpi-grid" aria-label="Videa – souhrn za období">
    <article class="kpi-card kpi-violet">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="film"></i></span><span><strong>Publikovaná videa</strong> <small>(v období)</small></span></div>
        <div class="kpi-value"><?= h(allstat_number((int) $ytV['count'])) ?></div>
        <div class="kpi-meta"><span class="trend trend-none">celkem na kanálu <?= h(allstat_number((int) $ytV['allTimeCount'])) ?></span></div>
    </article>
    <article class="kpi-card kpi-blue">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="eye"></i></span><span><strong>Zhlédnutí těchto videí</strong> <small>(celoživotně)</small></span></div>
        <div class="kpi-value"><?= $ytV['count'] > 0 ? h(allstat_number((int) $ytV['views'])) : '—' ?></div>
        <div class="kpi-meta"><span class="trend trend-none">Ø na video <?= $ytV['count'] > 0 ? h(allstat_number((int) round($ytV['views'] / $ytV['count']))) : '—' ?></span></div>
    </article>
    <article class="kpi-card kpi-cyan">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="clock"></i></span><span><strong>Sledovaný čas</strong> <small>(tato videa)</small></span></div>
        <div class="kpi-value"><?= h($ytV['watchLabel']) ?></div>
        <div class="kpi-meta"><span class="trend trend-none">Ø doba zhlédnutí <?= h($ytV['avgLabel']) ?></span></div>
    </article>
    <article class="kpi-card kpi-orange">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="activity"></i></span><span><strong>Míra zapojení</strong> <small>(líbí se + kom. + sdílení ÷ zhlédnutí)</small></span></div>
        <div class="kpi-value"><?= h($ytV['engRateLabel']) ?></div>
        <div class="kpi-meta"><span class="trend trend-none"><?= h(allstat_number((int) $ytV['likes'])) ?> líbí se · <?= h(allstat_number((int) $ytV['comments'])) ?> kom. · <?= h(allstat_number((int) $ytV['shares'])) ?> sdíl.</span></div>
    </article>
    <article class="kpi-card kpi-green">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="user-plus"></i></span><span><strong>Odběry z videí</strong> <small>(top videa období)</small></span></div>
        <div class="kpi-value"><?= $ytV['subs'] > 0 ? '+' . h(allstat_number((int) $ytV['subs'])) : '—' ?></div>
        <div class="kpi-meta"><span class="trend trend-none">odběratelé získaní přímo z těchto videí</span></div>
    </article>
</section>

<?php if (!empty($ytV['byFormat']) && count($ytV['byFormat']) > 1): ?>
<section class="analytics-grid" aria-label="Podle formátu">
    <article class="panel panel-wide">
        <div class="panel-header"><h2>Výkon podle formátu <span>(videa vs. Shorts vs. živě)</span></h2></div>
        <p class="panel-help">Videa publikovaná v období podle formátu. Shorts = videa do 60 s (YouTube API formát neoznačuje, je to odhad z délky). Ø zhlédnutí řadí formáty podle účinnosti, ne podle počtu.</p>
        <div class="table-scroll">
            <table>
                <thead><tr><th>Formát</th><th>Počet</th><th>Ø zhlédnutí</th><th>Zhlédnutí celkem</th><th>Sledovaný čas</th><th>Engagement</th></tr></thead>
                <tbody>
                <?php foreach ($ytV['byFormat'] as $f): ?>
                    <tr>
                        <td><?= h($f['label']) ?></td>
                        <td><?= h(allstat_number((int) $f['count'])) ?></td>
                        <td><strong><?= h(allstat_number((int) $f['avgViews'])) ?></strong></td>
                        <td><?= h(allstat_number((int) $f['views'])) ?></td>
                        <td><?= h($f['watchLabel']) ?></td>
                        <td><?= h(allstat_number((int) $f['engagement'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>
<?php endif; ?>

<section class="tables-grid" aria-label="Top videa za období">
    <article class="panel table-panel table-panel-wide">
        <div class="panel-header"><h2>Top 10 videí publikovaných v období <span>(podle zhlédnutí)</span></h2></div>
        <p class="panel-help">Čísla jsou celoživotní stav videa k poslednímu syncu. „Ø doba" = průměrná doba jednoho zhlédnutí a v závorce kolik procent délky videa to je (udržení pozornosti). „Odběry" = odběratelé, kteří se přihlásili přímo z tohoto videa. Klikni na název pro otevření na YouTube.</p>
        <div class="table-scroll">
            <table>
                <?= $ytHead ?>
                <tbody>
                    <?php foreach ($ytV['top'] as $v) { echo $ytRow($v); } ?>
                    <?php if (empty($ytV['top'])): ?>
                        <tr><td colspan="11" class="table-muted">V tomto období nebylo publikované žádné video. Zvol delší období, nebo se podívej na Top videa celkově níže.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>

<?php if (!empty($ytV['allTime'])): ?>
<section class="tables-grid" aria-label="Top videa celkově">
    <article class="panel table-panel table-panel-wide">
        <div class="panel-header"><h2>Top 10 videí celkově <span>(celý kanál, nezávisle na období)</span></h2></div>
        <p class="panel-help">Nejsledovanější videa kanálu za celou dobu, evergreen obsah, který sbírá zhlédnutí bez ohledu na datum publikace. Celkem sledujeme <?= h(allstat_number((int) $ytV['allTimeCount'])) ?> videí.</p>
        <div class="table-scroll">
            <table>
                <?= $ytHead ?>
                <tbody><?php foreach ($ytV['allTime'] as $v) { echo $ytRow($v); } ?></tbody>
            </table>
        </div>
    </article>
</section>
<?php endif; ?>
<?php if (!empty($ytC['hasData'])): ?>
<section class="tables-grid" aria-label="Všechna videa podle playlistů" id="vsechna-videa">
    <article class="panel table-panel table-panel-wide">
        <div class="panel-header"><h2>Všechna videa podle playlistů <span>(<?= h(allstat_number((int) $ytC['count'])) ?> videí, <?= (int) $ytC['playlistCount'] ?> playlistů)</span></h2></div>
        <p class="panel-help">Kompletní katalog kanálu nezávisle na zvoleném období, seskupený podle playlistů z YouTube (Motivační videa, Žijeme regionem…). Čísla jsou celoživotní stav k poslednímu syncu. Souhrn nahoře porovnává kategorie mezi sebou (Ø zhlédnutí na video = která témata táhnou), rozbalením playlistu se zobrazí všechna jeho videa od nejnovějšího.<?php if ((int) $ytC['multiCount'] > 0): ?> <?= (int) $ytC['multiCount'] ?> videí je ve více playlistech, počítají se v každém z nich.<?php endif; ?><?php if ((int) $ytC['unassignedCount'] > 0): ?> Videa mimo playlisty jsou ve skupině „Nezařazená".<?php endif; ?></p>
        <div class="table-scroll">
            <table>
                <thead><tr><th>Playlist (kategorie)</th><th>Videí</th><th>Zhlédnutí celkem</th><th>Ø zhlédnutí / video</th><th>Sledovaný čas</th><th>Ø doba (% videa)</th><th>Zapojení %</th><th>Nejnovější video</th></tr></thead>
                <tbody>
                <?php foreach ($ytC['groups'] as $g): ?>
                    <tr>
                        <td><strong><?= h($g['title']) ?></strong></td>
                        <td><?= h(allstat_number((int) $g['count'])) ?></td>
                        <td><?= h($g['viewsLabel']) ?></td>
                        <td><strong><?= h(allstat_number((int) $g['avgViews'])) ?></strong></td>
                        <td><?= h($g['watchLabel']) ?></td>
                        <td><?= h($g['avgLabel']) ?><?= $g['avgPctLabel'] !== '—' ? ' <small class="table-muted">(' . h($g['avgPctLabel']) . ')</small>' : '' ?></td>
                        <td><?= h($g['engRateLabel']) ?></td>
                        <td><?= h($g['newest']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php foreach ($ytC['groups'] as $g): ?>
        <details class="more-posts">
            <summary><div><h3><?= h($g['title']) ?></h3><p><?= h(allstat_number((int) $g['count'])) ?> videí · <?= h($g['viewsLabel']) ?> zhlédnutí · <?= h($g['watchLabel']) ?> sledovaného času · Ø <?= h(allstat_number((int) $g['avgViews'])) ?> zhlédnutí na video</p></div></summary>
            <div class="table-scroll">
                <table>
                    <?= $ytHead ?>
                    <tbody><?php foreach ($g['items'] as $v) { echo $ytRow($v); } ?></tbody>
                </table>
            </div>
        </details>
        <?php endforeach; ?>
    </article>
</section>
<?php endif; ?>
<?php else: ?>
<section class="analytics-grid" aria-label="Videa">
    <article class="panel panel-wide">
        <div class="panel-header"><h2>Videa <span>(YouTube)</span></h2></div>
        <p class="panel-help">Zatím nejsou stažená žádná videa. Spusť „Stáhnout aktuální data" nebo „Stáhnout historii" u tohoto napojení ve <a href="admin/sources.php">Zdroje dat</a>.</p>
    </article>
</section>
<?php endif; ?>

<?php if (!empty($ytA['hasData'])): ?>
<?php if (!empty($ytA['traffic'])): ?>
<section class="analytics-grid" aria-label="Zdroje návštěvnosti">
    <article class="panel panel-wide">
        <div class="panel-header"><h2>Odkud přišla zhlédnutí <span>(zdroje návštěvnosti, za období)</span></h2></div>
        <p class="panel-help">Součet zhlédnutí za zvolené období podle toho, kde divák video našel (YouTube Analytics insightTrafficSourceType). Celkem <strong><?= h(allstat_number((int) $ytA['trafficTotal'])) ?></strong> zhlédnutí. Vyhledávání a navrhovaná videa = organický dosah YouTube, externí = odkazy z webu, sociálních sítí a e-mailů.</p>
        <?= allstat_bar_rows_html($ytA['traffic'], $ytPal) ?>
    </article>
</section>
<?php endif; ?>
<?php if (!empty($ytA['geo']) || !empty($ytA['age']) || !empty($ytA['gender'])): ?>
<section class="analytics-grid" aria-label="Publikum">
    <article class="panel panel-wide">
        <div class="panel-header"><h2>Publikum kanálu <span>(YouTube Analytics)</span></h2></div>
        <p class="panel-help">Země podle zhlédnutí a věk/pohlaví diváků. YouTube tyhle reporty nedává po dnech, proto jde o <strong>stav za posledních 90 dní</strong> k datu syncu<?= ($ytA['geoDate'] ?? '') !== '' ? ' (geografie k ' . h($ytA['geoDate']) . ')' : '' ?><?= ($ytA['demoDate'] ?? '') !== '' ? ' (demografie k ' . h($ytA['demoDate']) . ')' : '' ?>, ne za zvolené období. Demografii YouTube ukazuje jen u přihlášených diváků, součet nemusí dát 100 %.</p>
        <div class="demo-facets">
            <?php if (!empty($ytA['geo'])): ?><div><h3 class="content-subhead">Země (top <?= count($ytA['geo']) ?>, zhlédnutí)</h3><?= allstat_bar_rows_html(array_slice($ytA['geo'], 0, 12), $ytPal) ?></div><?php endif; ?>
            <?php if (!empty($ytA['age'])): ?><div><h3 class="content-subhead">Věk diváků (% zhlédnutí)</h3><?= allstat_bar_rows_html($ytA['age'], $ytPal, true) ?></div><?php endif; ?>
            <?php if (!empty($ytA['gender'])): ?><div><h3 class="content-subhead">Pohlaví diváků (% zhlédnutí)</h3><?= allstat_bar_rows_html($ytA['gender'], $ytPal, true) ?></div><?php endif; ?>
        </div>
    </article>
</section>
<?php endif; ?>
<?php endif; ?>
