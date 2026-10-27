<?php
/**
 * Detail zdroje – YouTube kanál: odběratelé, videa (souhrn za období, podle formátu, top videa za období
 * i celkově, katalog podle playlistů) a publikum (zdroje návštěvnosti za období, geografie a demografie
 * jako snapshot). Includuje views/provider.php pod záložkami metrik kanálu.
 * V rozsahu: $youtubeVideos, $youtubeAudience, $youtubeCatalog, $providerPayload, $data, $config.
 * Jen prezentace, bez přístupu do DB.
 */
$ytV = is_array($youtubeVideos ?? null) ? $youtubeVideos : [];
$ytA = is_array($youtubeAudience ?? null) ? $youtubeAudience : [];
$ytC = is_array($youtubeCatalog ?? null) ? $youtubeCatalog : [];
$ytRow = static function (array $v): string {
    $title = ($v['permalink'] ?? '') !== ''
        ? '<a class="post-text" href="' . h($v['permalink']) . '" target="_blank" rel="noopener" title="' . h($v['title']) . '">' . h(mb_strimwidth($v['title'], 0, 80, '…')) . '</a>'
        : '<span class="post-text">' . h($v['title']) . '</span>';

    return '<tr>'
        . '<td><div class="post">' . $title . '<span class="post-meta"><span class="chip">' . h($v['formatLabel']) . '</span><span class="num">' . h($v['date']) . '</span><span class="num">' . h($v['durationLabel']) . '</span></span></div></td>'
        . '<td class="num is-strong">' . h($v['viewsLabel']) . '</td>'
        . '<td class="num">' . h($v['watchLabel']) . '</td>'
        . '<td class="num">' . h($v['avgLabel']) . ($v['avgPct'] > 0 ? ' <span class="is-muted">(' . h($v['avgPctLabel']) . ')</span>' : '') . '</td>'
        . '<td class="num">' . h(allstat_number((int) $v['likes'])) . '</td>'
        . '<td class="num">' . h(allstat_number((int) $v['comments'])) . '</td>'
        . '<td class="num">' . h(allstat_number((int) $v['shares'])) . '</td>'
        . '<td class="num">' . ($v['subs'] > 0 ? h(allstat_ui_signed((int) $v['subs'])) : '–') . '</td>'
        . '<td class="num">' . h($v['engRateLabel']) . '</td>'
        . '</tr>';
};
$ytHead = '<thead><tr><th scope="col">' . h(t('Video')) . '</th>'
    . '<th scope="col" style="width: 104px">' . h(t('Zhlédnutí')) . '</th>'
    . '<th scope="col" style="width: 120px">' . h(t('Sledovaný čas')) . '</th>'
    . '<th scope="col" style="width: 152px">' . h(t('Ø doba (% videa)')) . '</th>'
    . '<th scope="col" style="width: 88px">' . h(t('Líbí se')) . '</th>'
    . '<th scope="col" style="width: 104px">' . h(t('Komentáře')) . '</th>'
    . '<th scope="col" style="width: 80px">' . h(t('Sdílení')) . '</th>'
    . '<th scope="col" style="width: 80px">' . h(t('Odběry')) . '</th>'
    . '<th scope="col" style="width: 104px">' . h(t('Zapojení %')) . '</th></tr></thead>';
// Podíl v %: pruh = poměr k největší položce, číslo = podíl.
$ytPercentMeters = static fn (array $rows): array => array_map(static fn (array $r, float $w): array => ['label' => (string) $r['label'], 'value' => allstat_percent((float) $r['value'], 1), 'width' => $w], $rows, allstat_ui_widths(array_column($rows, 'value')));
?>
<?php if (!empty($ytV['hasData'])): ?>
<div class="grid grid-3">
    <?php $followersTitle = t('Odběratelé'); include __DIR__ . '/_followers.php'; ?>
    <section class="card">
        <?= allstat_ui_card_head(t('Publikovaná videa'), '', h(t('Videa publikovaná v období a jejich celoživotní čísla.'))) ?>
        <div class="card-body">
            <?= allstat_ui_rows([
                ['label' => t('Publikovaná videa'), 'value' => allstat_number((int) $ytV['count']), 'delta' => t('celkem na kanálu {count}', ['count' => allstat_number((int) $ytV['allTimeCount'])]), 'deltaClass' => 'is-muted'],
                ['label' => t('Zhlédnutí těchto videí'), 'value' => $ytV['count'] > 0 ? allstat_number((int) $ytV['views']) : '–', 'delta' => t('Ø na video {value}', ['value' => $ytV['count'] > 0 ? allstat_number((int) round($ytV['views'] / $ytV['count'])) : '–']), 'deltaClass' => 'is-muted'],
                ['label' => t('Sledovaný čas'), 'value' => (string) $ytV['watchLabel'], 'delta' => t('Ø doba zhlédnutí {value}', ['value' => $ytV['avgLabel']]), 'deltaClass' => 'is-muted'],
            ]) ?>
        </div>
    </section>
    <section class="card">
        <?= allstat_ui_card_head(t('Míra zapojení'), '', h(t('líbí se + kom. + sdílení ÷ zhlédnutí'))) ?>
        <div class="card-body">
            <?= allstat_ui_rows([
                ['label' => t('Míra zapojení'), 'value' => (string) $ytV['engRateLabel']],
                ['label' => t('Líbí se'), 'value' => allstat_number((int) $ytV['likes'])],
                ['label' => t('Komentáře'), 'value' => allstat_number((int) $ytV['comments'])],
                ['label' => t('Sdílení'), 'value' => allstat_number((int) $ytV['shares'])],
                ['label' => t('Odběry z videí'), 'value' => $ytV['subs'] > 0 ? allstat_ui_signed((int) $ytV['subs']) : '–', 'title' => t('odběratelé získaní přímo z těchto videí')],
            ]) ?>
        </div>
    </section>
</div>

<?php if (!empty($ytV['byFormat']) && count($ytV['byFormat']) > 1): ?>
<section class="card">
    <?= allstat_ui_card_head(t('Výkon podle formátu'), '', h(t('Videa publikovaná v období: videa vs. Shorts vs. živě.')) ) ?>
    <div class="card-body">
        <table class="table table-plain" title="<?= h(t('Shorts = videa do 60 s (YouTube API formát neoznačuje, je to odhad z délky). Ø zhlédnutí řadí formáty podle účinnosti, ne podle počtu.')) ?>">
            <thead><tr><th scope="col"><?= h(t('Formát')) ?></th><th scope="col"><?= h(t('Počet')) ?></th><th scope="col"><?= h(t('Ø zhlédnutí')) ?></th><th scope="col"><?= h(t('Zhlédnutí celkem')) ?></th><th scope="col"><?= h(t('Sledovaný čas')) ?></th><th scope="col">Engagement</th></tr></thead>
            <tbody>
            <?php foreach ($ytV['byFormat'] as $f): ?>
                <tr>
                    <td><?= h($f['label']) ?></td>
                    <td class="num"><?= h(allstat_number((int) $f['count'])) ?></td>
                    <td class="num is-strong"><?= h(allstat_number((int) $f['avgViews'])) ?></td>
                    <td class="num"><?= h(allstat_number((int) $f['views'])) ?></td>
                    <td class="num"><?= h($f['watchLabel']) ?></td>
                    <td class="num"><?= h(allstat_number((int) $f['engagement'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<section class="card card-clip">
    <?= allstat_ui_card_head(t('Top 10 videí publikovaných v období'), '', h(t('podle zhlédnutí')) ) ?>
    <div class="table-wrap">
        <table class="table table-wide">
            <?= $ytHead ?>
            <tbody>
                <?php foreach ($ytV['top'] as $v) { echo $ytRow($v); } ?>
                <?php if (empty($ytV['top'])): ?>
                    <tr><td colspan="9" class="is-muted"><?= h(t('V tomto období nebylo publikované žádné video. Zvol delší období, nebo se podívej na Top videa celkově níže.')) ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if (!empty($ytV['allTime'])): ?>
<section class="card card-clip">
    <?= allstat_ui_card_head(t('Top 10 videí celkově'), '', h(t('celý kanál, nezávisle na období')), allstat_ui_card_total(t('Celkem sledujeme {count} videí.', ['count' => allstat_number((int) $ytV['allTimeCount'])]))) ?>
    <div class="table-wrap">
        <table class="table table-wide">
            <?= $ytHead ?>
            <tbody><?php foreach ($ytV['allTime'] as $v) { echo $ytRow($v); } ?></tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($ytC['hasData'])): ?>
<section class="card card-clip" id="vsechna-videa">
    <?= allstat_ui_card_head(t('Všechna videa podle playlistů'), '', h(t('{videos} videí, {playlists} playlistů', ['videos' => allstat_number((int) $ytC['count']), 'playlists' => (int) $ytC['playlistCount']])
        . ((int) $ytC['multiCount'] > 0 ? ' · ' . t('{count} videí je ve více playlistech, počítají se v každém z nich.', ['count' => (int) $ytC['multiCount']]) : '')
        . ((int) $ytC['unassignedCount'] > 0 ? ' · ' . t('Videa mimo playlisty jsou ve skupině „Nezařazená".') : ''))) ?>
    <div class="table-wrap">
        <table class="table table-wide">
            <thead><tr><th scope="col"><?= h(t('Playlist (kategorie)')) ?></th><th scope="col" style="width: 80px"><?= h(t('Videí')) ?></th><th scope="col" style="width: 128px"><?= h(t('Zhlédnutí celkem')) ?></th><th scope="col" style="width: 136px"><?= h(t('Ø zhlédnutí / video')) ?></th><th scope="col" style="width: 120px"><?= h(t('Sledovaný čas')) ?></th><th scope="col" style="width: 152px"><?= h(t('Ø doba (% videa)')) ?></th><th scope="col" style="width: 104px"><?= h(t('Zapojení %')) ?></th><th scope="col" style="width: 136px"><?= h(t('Nejnovější video')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($ytC['groups'] as $g): ?>
                <tr>
                    <td class="is-strong"><?= h($g['title']) ?></td>
                    <td class="num"><?= h(allstat_number((int) $g['count'])) ?></td>
                    <td class="num"><?= h($g['viewsLabel']) ?></td>
                    <td class="num is-strong"><?= h(allstat_number((int) $g['avgViews'])) ?></td>
                    <td class="num"><?= h($g['watchLabel']) ?></td>
                    <td class="num"><?= h($g['avgLabel']) ?><?= $g['avgPctLabel'] !== '—' ? ' <span class="is-muted">(' . h($g['avgPctLabel']) . ')</span>' : '' ?></td>
                    <td class="num"><?= h($g['engRateLabel']) ?></td>
                    <td class="num"><?= h($g['newest']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php foreach ($ytC['groups'] as $g): ?>
    <details class="note">
        <summary><?= allstat_icon('list', 'icon') ?><?= h($g['title']) ?> <small class="is-muted"><?= h(t('{videos} videí · {views} zhlédnutí · {watch} sledovaného času · Ø {avg} zhlédnutí na video', ['videos' => allstat_number((int) $g['count']), 'views' => $g['viewsLabel'], 'watch' => $g['watchLabel'], 'avg' => allstat_number((int) $g['avgViews'])])) ?></small><?= allstat_icon('chevron-down', 'note-chev') ?></summary>
        <div class="table-wrap">
            <table class="table table-wide">
                <?= $ytHead ?>
                <tbody><?php foreach ($g['items'] as $v) { echo $ytRow($v); } ?></tbody>
            </table>
        </div>
    </details>
    <?php endforeach; ?>
</section>
<?php endif; ?>
<?php else: ?>
<section class="card">
    <?= allstat_ui_card_head(t('Videa'), 'YouTube') ?>
    <div class="card-body">
        <p class="card-desc"><?= t('Zatím nejsou stažená žádná videa. Spusť „Stáhnout aktuální data" nebo „Stáhnout historii" u tohoto napojení ve {link}.', ['link' => '<a href="admin/sources.php">' . h(t('Zdroje dat')) . '</a>']) ?></p>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($ytA['hasData'])): ?>
<div class="grid grid-3">
    <?php if (!empty($ytA['traffic'])):
        $ytTrafficTotal = array_sum(array_map(static fn ($r) => (float) ($r['value'] ?? 0), $ytA['traffic']));
        $ytTraffic = array_map(static fn (array $r, float $w): array => [
            'label' => (string) $r['label'],
            'value' => allstat_number((int) $r['value']),
            'share' => allstat_percent($ytTrafficTotal > 0 ? (float) $r['value'] / $ytTrafficTotal * 100 : 0.0, 0),
            'width' => $w,
        ], $ytA['traffic'], allstat_ui_widths(array_column($ytA['traffic'], 'value')));
    ?>
    <section class="card">
        <?= allstat_ui_card_head(t('Odkud přišla zhlédnutí'), '', h(t('zdroje návštěvnosti, za období')), allstat_ui_card_total(t('{value} zhlédnutí', ['value' => allstat_number((int) $ytA['trafficTotal'])]))) ?>
        <div class="card-body"><?= allstat_ui_meters($ytTraffic) ?></div>
    </section>
    <?php endif; ?>
    <?php if (!empty($ytA['geo'])): $ytGeo = array_slice($ytA['geo'], 0, 12); $ytGeoTotal = array_sum(array_map(static fn ($r) => (float) ($r['value'] ?? 0), $ytA['geo'])); ?>
    <section class="card">
        <?= allstat_ui_card_head(t('Publikum kanálu'), 'YouTube Analytics', h(t('Země (top {count}, zhlédnutí)', ['count' => count($ytA['geo'])]) . (($ytA['geoDate'] ?? '') !== '' ? ' · ' . t('geografie k {date}', ['date' => allstat_date_label($ytA['geoDate'])]) : ''))) ?>
        <div class="card-body"><?= allstat_ui_meters(array_map(static fn (array $r, float $w): array => [
            'label' => (string) $r['label'],
            'value' => allstat_number((int) $r['value']),
            'share' => allstat_percent($ytGeoTotal > 0 ? (float) $r['value'] / $ytGeoTotal * 100 : 0.0, 0),
            'width' => $w,
        ], $ytGeo, allstat_ui_widths(array_column($ytGeo, 'value')))) ?></div>
    </section>
    <?php endif; ?>
    <?php if (!empty($ytA['age']) || !empty($ytA['gender'])): ?>
    <section class="card">
        <?= allstat_ui_card_head(t('Věk a pohlaví diváků'), 'YouTube Analytics', h(t('% zhlédnutí') . (($ytA['demoDate'] ?? '') !== '' ? ' · ' . t('demografie k {date}', ['date' => allstat_date_label($ytA['demoDate'])]) : ''))) ?>
        <div class="card-body grid">
            <?php if (!empty($ytA['age'])): ?><div class="meter"><span class="block-title"><?= h(t('Věk diváků (% zhlédnutí)')) ?></span><?= allstat_ui_meters($ytPercentMeters($ytA['age'])) ?></div><?php endif; ?>
            <?php if (!empty($ytA['gender'])): ?><?= allstat_ui_stack(t('Pohlaví diváků (% zhlédnutí)'), '', array_map(static fn (array $g): array => ['label' => (string) $g['label'], 'value' => allstat_percent((float) $g['value'], 1), 'share' => '', 'pct' => (float) $g['value']], $ytA['gender'])) ?><?php endif; ?>
        </div>
    </section>
    <?php endif; ?>
</div>
<?php endif; ?>

<details class="card note">
    <summary><?= allstat_icon('info', 'icon') ?><?= h(t('Jak číst data videí')) ?> <small class="is-muted"><?= h(t('Naposledy synchronizováno: {value}.', ['value' => $data['meta']['lastSync'] ?? t('nesync.')])) ?></small><?= allstat_icon('chevron-down', 'note-chev') ?></summary>
    <div class="note-body">
        <p><?= t('Dlaždice nahoře jsou <strong>za zvolené období</strong> (YouTube Analytics po dnech, včetně zhlédnutí starších videí). Tabulky videí níže ukazují videa <strong>publikovaná v období</strong> a u každého <strong>celoživotní</strong> čísla (zhlédnutí, sledovaný čas, Ø doba zhlédnutí, odběry získané z videa), stejně jako YouTube Studio v záložce Obsah. Poslední 2-3 dny YouTube doplňuje se zpožděním, denní sync je přepisuje.') ?></p>
        <p><?= h(t('Čísla jsou celoživotní stav videa k poslednímu syncu. „Ø doba" = průměrná doba jednoho zhlédnutí a v závorce kolik procent délky videa to je (udržení pozornosti). „Odběry" = odběratelé, kteří se přihlásili přímo z tohoto videa. Klikni na název pro otevření na YouTube.')) ?></p>
        <p><?= t('Země podle zhlédnutí a věk/pohlaví diváků. YouTube tyhle reporty nedává po dnech, proto jde o <strong>stav za posledních 90 dní</strong> k datu syncu') ?><?= h(t(', ne za zvolené období. Demografii YouTube ukazuje jen u přihlášených diváků, součet nemusí dát 100 %.')) ?></p>
    </div>
</details>
