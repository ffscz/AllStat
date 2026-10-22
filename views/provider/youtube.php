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
$ytHead = '<thead><tr><th>' . h(t('Video')) . '</th><th>' . h(t('Publikováno')) . '</th><th>' . h(t('Formát')) . '</th><th>' . h(t('Zhlédnutí')) . '</th><th>' . h(t('Sledovaný čas')) . '</th><th>' . h(t('Ø doba (% videa)')) . '</th><th>' . h(t('Líbí se')) . '</th><th>' . h(t('Komentáře')) . '</th><th>' . h(t('Sdílení')) . '</th><th>' . h(t('Odběry')) . '</th><th>' . h(t('Zapojení %')) . '</th></tr></thead>';
?>
<section class="analytics-grid" aria-label="<?= h(t('Videa – informace')) ?>">
    <article class="panel panel-wide">
        <div class="panel-header"><h2><?= h(t('Videa kanálu')) ?> <span>(YouTube)</span></h2></div>
        <p class="panel-help">
            <?= t('Naposledy synchronizováno: {value}.', ['value' => '<strong>' . h($data['meta']['lastSync'] ?? t('nesync.')) . '</strong>']) ?>
            <?= t('Dlaždice nahoře jsou <strong>za zvolené období</strong> (YouTube Analytics po dnech, včetně zhlédnutí starších videí). Tabulky videí níže ukazují videa <strong>publikovaná v období</strong> a u každého <strong>celoživotní</strong> čísla (zhlédnutí, sledovaný čas, Ø doba zhlédnutí, odběry získané z videa), stejně jako YouTube Studio v záložce Obsah. Poslední 2-3 dny YouTube doplňuje se zpožděním, denní sync je přepisuje.') ?>
        </p>
    </article>
</section>

<?php if (!empty($ytV['hasData'])): ?>
<section class="kpi-grid" aria-label="<?= h(t('Videa – souhrn za období')) ?>">
    <article class="kpi-card kpi-violet">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="film"></i></span><span><strong><?= h(t('Publikovaná videa')) ?></strong> <small>(<?= h(t('v období')) ?>)</small></span></div>
        <div class="kpi-value"><?= h(allstat_number((int) $ytV['count'])) ?></div>
        <div class="kpi-meta"><span class="trend trend-none"><?= h(t('celkem na kanálu {count}', ['count' => allstat_number((int) $ytV['allTimeCount'])])) ?></span></div>
    </article>
    <article class="kpi-card kpi-blue">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="eye"></i></span><span><strong><?= h(t('Zhlédnutí těchto videí')) ?></strong> <small>(<?= h(t('celoživotně')) ?>)</small></span></div>
        <div class="kpi-value"><?= $ytV['count'] > 0 ? h(allstat_number((int) $ytV['views'])) : '—' ?></div>
        <div class="kpi-meta"><span class="trend trend-none"><?= h(t('Ø na video {value}', ['value' => $ytV['count'] > 0 ? allstat_number((int) round($ytV['views'] / $ytV['count'])) : '—'])) ?></span></div>
    </article>
    <article class="kpi-card kpi-cyan">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="clock"></i></span><span><strong><?= h(t('Sledovaný čas')) ?></strong> <small>(<?= h(t('tato videa')) ?>)</small></span></div>
        <div class="kpi-value"><?= h($ytV['watchLabel']) ?></div>
        <div class="kpi-meta"><span class="trend trend-none"><?= h(t('Ø doba zhlédnutí {value}', ['value' => $ytV['avgLabel']])) ?></span></div>
    </article>
    <article class="kpi-card kpi-orange">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="activity"></i></span><span><strong><?= h(t('Míra zapojení')) ?></strong> <small>(<?= h(t('líbí se + kom. + sdílení ÷ zhlédnutí')) ?>)</small></span></div>
        <div class="kpi-value"><?= h($ytV['engRateLabel']) ?></div>
        <div class="kpi-meta"><span class="trend trend-none"><?= h(t('{likes} líbí se · {comments} kom. · {shares} sdíl.', ['likes' => allstat_number((int) $ytV['likes']), 'comments' => allstat_number((int) $ytV['comments']), 'shares' => allstat_number((int) $ytV['shares'])])) ?></span></div>
    </article>
    <article class="kpi-card kpi-green">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="user-plus"></i></span><span><strong><?= h(t('Odběry z videí')) ?></strong> <small>(<?= h(t('top videa období')) ?>)</small></span></div>
        <div class="kpi-value"><?= $ytV['subs'] > 0 ? '+' . h(allstat_number((int) $ytV['subs'])) : '—' ?></div>
        <div class="kpi-meta"><span class="trend trend-none"><?= h(t('odběratelé získaní přímo z těchto videí')) ?></span></div>
    </article>
</section>

<?php if (!empty($ytV['byFormat']) && count($ytV['byFormat']) > 1): ?>
<section class="analytics-grid" aria-label="<?= h(t('Podle formátu')) ?>">
    <article class="panel panel-wide">
        <div class="panel-header"><h2><?= h(t('Výkon podle formátu')) ?> <span>(<?= h(t('videa vs. Shorts vs. živě')) ?>)</span></h2></div>
        <p class="panel-help"><?= h(t('Videa publikovaná v období podle formátu. Shorts = videa do 60 s (YouTube API formát neoznačuje, je to odhad z délky). Ø zhlédnutí řadí formáty podle účinnosti, ne podle počtu.')) ?></p>
        <div class="table-scroll">
            <table>
                <thead><tr><th><?= h(t('Formát')) ?></th><th><?= h(t('Počet')) ?></th><th><?= h(t('Ø zhlédnutí')) ?></th><th><?= h(t('Zhlédnutí celkem')) ?></th><th><?= h(t('Sledovaný čas')) ?></th><th>Engagement</th></tr></thead>
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

<section class="tables-grid" aria-label="<?= h(t('Top videa za období')) ?>">
    <article class="panel table-panel table-panel-wide">
        <div class="panel-header"><h2><?= h(t('Top 10 videí publikovaných v období')) ?> <span>(<?= h(t('podle zhlédnutí')) ?>)</span></h2></div>
        <p class="panel-help"><?= h(t('Čísla jsou celoživotní stav videa k poslednímu syncu. „Ø doba" = průměrná doba jednoho zhlédnutí a v závorce kolik procent délky videa to je (udržení pozornosti). „Odběry" = odběratelé, kteří se přihlásili přímo z tohoto videa. Klikni na název pro otevření na YouTube.')) ?></p>
        <div class="table-scroll">
            <table>
                <?= $ytHead ?>
                <tbody>
                    <?php foreach ($ytV['top'] as $v) { echo $ytRow($v); } ?>
                    <?php if (empty($ytV['top'])): ?>
                        <tr><td colspan="11" class="table-muted"><?= h(t('V tomto období nebylo publikované žádné video. Zvol delší období, nebo se podívej na Top videa celkově níže.')) ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>

<?php if (!empty($ytV['allTime'])): ?>
<section class="tables-grid" aria-label="<?= h(t('Top videa celkově')) ?>">
    <article class="panel table-panel table-panel-wide">
        <div class="panel-header"><h2><?= h(t('Top 10 videí celkově')) ?> <span>(<?= h(t('celý kanál, nezávisle na období')) ?>)</span></h2></div>
        <p class="panel-help"><?= h(t('Nejsledovanější videa kanálu za celou dobu, evergreen obsah, který sbírá zhlédnutí bez ohledu na datum publikace. Celkem sledujeme {count} videí.', ['count' => allstat_number((int) $ytV['allTimeCount'])])) ?></p>
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
<section class="tables-grid" aria-label="<?= h(t('Všechna videa podle playlistů')) ?>" id="vsechna-videa">
    <article class="panel table-panel table-panel-wide">
        <div class="panel-header"><h2><?= h(t('Všechna videa podle playlistů')) ?> <span>(<?= h(t('{videos} videí, {playlists} playlistů', ['videos' => allstat_number((int) $ytC['count']), 'playlists' => (int) $ytC['playlistCount']])) ?>)</span></h2></div>
        <p class="panel-help"><?= h(t('Kompletní katalog kanálu nezávisle na zvoleném období, seskupený podle playlistů z YouTube (Motivační videa, Žijeme regionem…). Čísla jsou celoživotní stav k poslednímu syncu. Souhrn nahoře porovnává kategorie mezi sebou (Ø zhlédnutí na video = která témata táhnou), rozbalením playlistu se zobrazí všechna jeho videa od nejnovějšího.')) ?><?php if ((int) $ytC['multiCount'] > 0): ?> <?= h(t('{count} videí je ve více playlistech, počítají se v každém z nich.', ['count' => (int) $ytC['multiCount']])) ?><?php endif; ?><?php if ((int) $ytC['unassignedCount'] > 0): ?> <?= h(t('Videa mimo playlisty jsou ve skupině „Nezařazená".')) ?><?php endif; ?></p>
        <div class="table-scroll">
            <table>
                <thead><tr><th><?= h(t('Playlist (kategorie)')) ?></th><th><?= h(t('Videí')) ?></th><th><?= h(t('Zhlédnutí celkem')) ?></th><th><?= h(t('Ø zhlédnutí / video')) ?></th><th><?= h(t('Sledovaný čas')) ?></th><th><?= h(t('Ø doba (% videa)')) ?></th><th><?= h(t('Zapojení %')) ?></th><th><?= h(t('Nejnovější video')) ?></th></tr></thead>
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
            <summary><div><h3><?= h($g['title']) ?></h3><p><?= h(t('{videos} videí · {views} zhlédnutí · {watch} sledovaného času · Ø {avg} zhlédnutí na video', ['videos' => allstat_number((int) $g['count']), 'views' => $g['viewsLabel'], 'watch' => $g['watchLabel'], 'avg' => allstat_number((int) $g['avgViews'])])) ?></p></div></summary>
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
<section class="analytics-grid" aria-label="<?= h(t('Videa')) ?>">
    <article class="panel panel-wide">
        <div class="panel-header"><h2><?= h(t('Videa')) ?> <span>(YouTube)</span></h2></div>
        <p class="panel-help"><?= t('Zatím nejsou stažená žádná videa. Spusť „Stáhnout aktuální data" nebo „Stáhnout historii" u tohoto napojení ve {link}.', ['link' => '<a href="admin/sources.php">' . h(t('Zdroje dat')) . '</a>']) ?></p>
    </article>
</section>
<?php endif; ?>

<?php if (!empty($ytA['hasData'])): ?>
<?php if (!empty($ytA['traffic'])): ?>
<section class="analytics-grid" aria-label="<?= h(t('Zdroje návštěvnosti')) ?>">
    <article class="panel panel-wide">
        <div class="panel-header"><h2><?= h(t('Odkud přišla zhlédnutí')) ?> <span>(<?= h(t('zdroje návštěvnosti, za období')) ?>)</span></h2></div>
        <p class="panel-help"><?= t('Součet zhlédnutí za zvolené období podle toho, kde divák video našel (YouTube Analytics insightTrafficSourceType). Celkem <strong>{total}</strong> zhlédnutí. Vyhledávání a navrhovaná videa = organický dosah YouTube, externí = odkazy z webu, sociálních sítí a e-mailů.', ['total' => h(allstat_number((int) $ytA['trafficTotal']))]) ?></p>
        <?= allstat_bar_rows_html($ytA['traffic'], $ytPal) ?>
    </article>
</section>
<?php endif; ?>
<?php if (!empty($ytA['geo']) || !empty($ytA['age']) || !empty($ytA['gender'])): ?>
<section class="analytics-grid" aria-label="<?= h(t('Publikum')) ?>">
    <article class="panel panel-wide">
        <div class="panel-header"><h2><?= h(t('Publikum kanálu')) ?> <span>(YouTube Analytics)</span></h2></div>
        <p class="panel-help"><?= t('Země podle zhlédnutí a věk/pohlaví diváků. YouTube tyhle reporty nedává po dnech, proto jde o <strong>stav za posledních 90 dní</strong> k datu syncu') ?><?= ($ytA['geoDate'] ?? '') !== '' ? ' (' . h(t('geografie k {date}', ['date' => allstat_date_label($ytA['geoDate'])])) . ')' : '' ?><?= ($ytA['demoDate'] ?? '') !== '' ? ' (' . h(t('demografie k {date}', ['date' => allstat_date_label($ytA['demoDate'])])) . ')' : '' ?><?= h(t(', ne za zvolené období. Demografii YouTube ukazuje jen u přihlášených diváků, součet nemusí dát 100 %.')) ?></p>
        <div class="demo-facets">
            <?php if (!empty($ytA['geo'])): ?><div><h3 class="content-subhead"><?= h(t('Země (top {count}, zhlédnutí)', ['count' => count($ytA['geo'])])) ?></h3><?= allstat_bar_rows_html(array_slice($ytA['geo'], 0, 12), $ytPal) ?></div><?php endif; ?>
            <?php if (!empty($ytA['age'])): ?><div><h3 class="content-subhead"><?= h(t('Věk diváků (% zhlédnutí)')) ?></h3><?= allstat_bar_rows_html($ytA['age'], $ytPal, true) ?></div><?php endif; ?>
            <?php if (!empty($ytA['gender'])): ?><div><h3 class="content-subhead"><?= h(t('Pohlaví diváků (% zhlédnutí)')) ?></h3><?= allstat_bar_rows_html($ytA['gender'], $ytPal, true) ?></div><?php endif; ?>
        </div>
    </article>
</section>
<?php endif; ?>
<?php endif; ?>
