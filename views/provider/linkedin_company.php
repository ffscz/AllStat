<?php
/**
 * Detail zdroje – LinkedIn stránka: sledující, příspěvky a jejich výkon, výkon podle typu obsahu, nejlepší
 * čas pro publikaci, nejúspěšnější příspěvky, rozpad reakcí a demografie sledujících i návštěvníků.
 * Includuje views/provider.php pod záložkami metrik stránky. V rozsahu z index.php: $socialPosts,
 * $socialPostsAll, $linkedinReactions, $linkedinFollowerDemographics, $linkedinVisitorDemographics,
 * $providerPayload, $data, $viewSourceId. Dosah příspěvku = LinkedIn unikátní zobrazení; engagement =
 * reakce + komentáře + sdílení (bez prokliků). Jen prezentace, bez přístupu do DB.
 */
$deltaRow = static fn (string $label, string $value, ?array $delta = null, string $title = ''): array => ['label' => $label, 'value' => $value, 'title' => $title] + allstat_ui_social_delta($delta);
// Měřák z řádků label + value (+ share v %): pruh = poměr k největší položce.
$liMeters = static function (array $rows): string {
    $values = array_map(static fn (array $r): float => (float) $r['value'], $rows);

    return allstat_ui_meters(array_map(static function (array $r, float $v, float $w): array {
        $share = (float) ($r['share'] ?? 0);

        return [
            'label' => $r['label'],
            'value' => allstat_number((int) round($v)),
            'share' => $share >= 9.95 ? allstat_percent(round($share), 0) : allstat_percent($share, 1),
            'width' => $w,
        ];
    }, $rows, $values, allstat_ui_widths($values)));
};
// Blok demografie: nadpis + měřáky (jen když jsou data).
$liFacet = static fn (string $title, array $rows): string => $rows ? '<div class="meter"><span class="block-title">' . h($title) . '</span>' . $liMeters($rows) . '</div>' : '';
// Řádek tabulky příspěvků (top i všechny).
$liPostRow = static function (array $post, int $maxLen = 0): string {
    $msg = $maxLen > 0 ? mb_strimwidth((string) $post['message'], 0, $maxLen, '…') : (string) $post['message'];
    $text = ($post['permalink'] ?? '') !== ''
        ? '<a class="post-text" href="' . h($post['permalink']) . '" target="_blank" rel="noopener">' . h($msg) . '</a>'
        : '<span class="post-text">' . h($msg) . '</span>';

    return '<tr><td><div class="post">' . $text . '<span class="post-meta"><span class="chip">' . h(allstat_social_format_label((string) ($post['format'] ?? ''))) . '</span><span class="num">' . h($post['date']) . '</span></span></div></td>'
        . '<td class="num">' . h($post['reachLabel'] ?? '–') . '</td>'
        . '<td class="num">' . h(allstat_number((int) ($post['reactions'] ?? 0))) . '</td>'
        . '<td class="num">' . h(allstat_number((int) $post['comments'])) . '</td>'
        . '<td class="num">' . h(allstat_number((int) $post['shares'])) . '</td>'
        . '<td class="num is-strong">' . h($post['engagementLabel']) . '</td>'
        . '<td class="num">' . h($post['engRateLabel'] ?? '–') . '</td></tr>';
};
$liPostsHead = '<thead><tr><th scope="col">' . h(t('Příspěvek')) . '</th>'
    . '<th scope="col" style="width: 88px">' . h(t('Dosah')) . '</th>'
    . '<th scope="col" style="width: 80px">' . h(t('Reakce')) . '</th>'
    . '<th scope="col" style="width: 104px">' . h(t('Komentáře')) . '</th>'
    . '<th scope="col" style="width: 80px">' . h(t('Sdílení')) . '</th>'
    . '<th scope="col" style="width: 96px">Engagement</th>'
    . '<th scope="col" style="width: 120px">' . h(t('Zapojení %')) . '</th></tr></thead>';
?>
<?php if (!empty($socialPosts)): ?>
<div class="grid grid-3">
    <?php include __DIR__ . '/_followers.php'; ?>
    <section class="card">
        <?= allstat_ui_card_head(t('Příspěvky'), '', h(t('Publikované za období, změna proti předchozímu.'))) ?>
        <div class="card-body">
            <?= allstat_ui_rows([
                $deltaRow(t('Počet příspěvků'), allstat_number((int) $socialPosts['count']), $socialPosts['deltas']['count'] ?? null),
                ['label' => t('Frekvence / týden'), 'value' => allstat_number((float) $socialPosts['perWeek'], 1)],
                ['label' => t('Ø reakcí na příspěvek'), 'value' => allstat_number((float) $socialPosts['avgReactions'], 1)],
                ['label' => t('Ø komentářů na příspěvek'), 'value' => allstat_number((float) $socialPosts['avgComments'], 1)],
            ]) ?>
        </div>
    </section>
    <section class="card">
        <?= allstat_ui_card_head(t('Výkon příspěvků'), '', h(t('Součty za příspěvky publikované v období.'))) ?>
        <div class="card-body">
            <?= allstat_ui_rows([
                $deltaRow(t('Dosah příspěvků'), $socialPosts['reach'] > 0 ? allstat_number((int) $socialPosts['reach']) : '–', $socialPosts['deltas']['reach'] ?? null, t('součet unik. zobrazení')),
                $deltaRow(t('Celkový engagement'), (string) $socialPosts['engagementLabel'], $socialPosts['deltas']['engagement'] ?? null, t('reakce+kom.+sdíl.')),
                $deltaRow(t('Míra zapojení'), (string) $socialPosts['engRateLabel'], $socialPosts['deltas']['engRate'] ?? null, t('engagement / dosah')),
                ['label' => t('Prokliky'), 'value' => $socialPosts['clicks'] > 0 ? allstat_number((int) $socialPosts['clicks']) : '–', 'title' => t('z příspěvků')],
            ]) ?>
        </div>
    </section>
</div>

<div class="grid grid-2">
    <?php if (!empty($socialPosts['byFormat'])): ?>
    <section class="card">
        <?= allstat_ui_card_head(t('Výkon podle typu obsahu'), '', h(t('Které formáty sbírají nejvíc zapojení v průměru na příspěvek.'))) ?>
        <div class="card-body">
            <div class="table-wrap">
                <table class="table table-plain">
                    <thead><tr><th scope="col"><?= h(t('Typ obsahu')) ?></th><th scope="col" style="width: 64px"><?= h(t('Počet')) ?></th><th scope="col" style="width: 96px"><?= h(t('Ø engagement')) ?></th><th scope="col" style="width: 120px"><?= h(t('Engagement celkem')) ?></th><th scope="col" style="width: 72px"><?= h(t('Dosah')) ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($socialPosts['byFormat'] as $f): ?>
                            <tr>
                                <td><?= h($f['label']) ?></td>
                                <td class="num"><?= h(allstat_number((int) $f['count'])) ?></td>
                                <td class="num is-strong"><?= h(allstat_number((float) $f['avgEngagement'], 1)) ?></td>
                                <td class="num"><?= h(allstat_number((int) $f['engagement'])) ?></td>
                                <td class="num"><?= $f['reach'] > 0 ? h(allstat_number((int) $f['reach'])) : '–' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
    <?php endif; ?>
    <?php include __DIR__ . '/_heatmap.php'; ?>
</div>

<section class="card card-clip" id="vsechny-prispevky">
    <?= allstat_ui_card_head(t('Top 5 příspěvků dle engagementu'), 'LinkedIn', h(t('Nejúspěšnější příspěvky za období podle součtu reakcí, komentářů a sdílení.'))) ?>
    <div class="table-wrap">
        <table class="table table-wide">
            <?= $liPostsHead ?>
            <tbody>
                <?php foreach ($socialPosts['top'] as $post): ?><?= $liPostRow($post) ?><?php endforeach; ?>
                <?php if (empty($socialPosts['top'])): ?>
                    <tr><td colspan="7" class="is-muted"><?= h(t('Za vybrané období není žádný příspěvek. Zvol delší období nebo spusť „Stáhnout aktuální data".')) ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (!empty($socialPostsAll) && (int) ($socialPostsAll['total'] ?? 0) > 5):
        $ppBase = '?domain_id=' . (int) $data['domain']['id'] . '&start=' . urlencode($data['range']['start']) . '&end=' . urlencode($data['range']['end']) . '&source_id=' . (int) $viewSourceId;
        $ppLink = static fn (int $n): string => $ppBase . '&posts_page=' . $n . '#vsechny-prispevky';
        $ppPage = (int) ($socialPostsAll['page'] ?? 1);
        $ppPages = (int) ($socialPostsAll['totalPages'] ?? 1);
    ?>
    <details class="note" <?= $ppPage > 1 ? 'open' : '' ?>>
        <summary><?= allstat_icon('list', 'icon') ?><?= h(t('Všech {count} příspěvků za období', ['count' => (int) $socialPostsAll['total']])) ?><?= allstat_icon('chevron-down', 'note-chev') ?></summary>
        <div class="table-wrap">
            <table class="table table-wide">
                <?= $liPostsHead ?>
                <tbody>
                    <?php foreach ($socialPostsAll['rows'] as $post): ?><?= $liPostRow($post, 90) ?><?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($ppPages > 1): ?>
        <nav class="card-foot" aria-label="<?= h(t('Stránkování příspěvků')) ?>">
            <?php if ($ppPage > 1): ?><a class="btn btn-secondary btn-sm" href="<?= h($ppLink($ppPage - 1)) ?>"><?= h(t('← Předchozí')) ?></a><?php endif; ?>
            <span><?= h(t('Stránka {page} z {pages}', ['page' => $ppPage, 'pages' => $ppPages])) ?></span>
            <?php if ($ppPage < $ppPages): ?><a class="btn btn-secondary btn-sm" href="<?= h($ppLink($ppPage + 1)) ?>"><?= h(t('Další →')) ?></a><?php endif; ?>
        </nav>
        <?php endif; ?>
    </details>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php
// Mimo guard $socialPosts: rozpad reakcí (za období) + demografie sledujících + složení návštěvníků
// (obě demografie = celoživotní stav z LinkedIn API).
if (!empty($linkedinReactions['hasData'])):
?>
<section class="card">
    <?= allstat_ui_card_head(t('Rozpad reakcí'), '', h(t('Reakce na příspěvky z období podle typu. Celkem {total}.', ['total' => allstat_number((int) ($linkedinReactions['total'] ?? 0))]))) ?>
    <div class="card-body">
        <div class="stats">
            <?php foreach ($linkedinReactions['rows'] as $r): ?>
                <div title="<?= h(allstat_percent((float) ($r['share'] ?? 0), 1)) ?>"><span class="stat-label"><?= h($r['label']) ?></span><span class="stat-value num"><?= h(allstat_number((int) round((float) $r['value']))) ?></span></div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($linkedinFollowerDemographics['hasData'])): $liD = $linkedinFollowerDemographics; ?>
<section class="card">
    <?= allstat_ui_card_head(t('Demografie sledujících'), 'LinkedIn', h(t('Kdo sleduje firemní stránku, podle profesního profilu. Celoživotní stav z LinkedIn API, ne za zvolené období. Součet nemusí dát 100 % sledujících.')),
        ($liD['date'] ?? '') !== '' ? allstat_ui_card_total(t('naposledy {date}', ['date' => allstat_date_label($liD['date'])])) : '') ?>
    <div class="card-body grid grid-3">
        <?= $liFacet(t('Seniorita'), $liD['seniority']) ?>
        <?= $liFacet(t('Funkce'), $liD['function']) ?>
        <?= $liFacet(t('Obor (top 12)'), $liD['industry']) ?>
        <?= $liFacet(t('Lokalita (top 12)'), $liD['region']) ?>
        <?= $liFacet(t('Země (top 12)'), $liD['country']) ?>
        <?= $liFacet(t('Velikost firmy sledujícího'), $liD['staff']) ?>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($linkedinVisitorDemographics['hasData'])): $liV = $linkedinVisitorDemographics; ?>
<section class="card">
    <?= allstat_ui_card_head(t('Složení návštěvníků stránky'), 'LinkedIn', h(t('Kdo navštěvuje firemní stránku, podle profesního profilu (z návštěv, ne ze sledujících). Celoživotní stav z LinkedIn API.')),
        ($liV['date'] ?? '') !== '' ? allstat_ui_card_total(t('naposledy {date}', ['date' => allstat_date_label($liV['date'])])) : '') ?>
    <div class="card-body grid grid-3">
        <?= $liFacet(t('Pracovní funkce'), $liV['function']) ?>
        <?= $liFacet(t('Seniorita'), $liV['seniority']) ?>
        <?= $liFacet(t('Obor (top 12)'), $liV['industry']) ?>
        <?= $liFacet(t('Lokalita (top 12)'), $liV['region']) ?>
        <?= $liFacet(t('Velikost firmy návštěvníka'), $liV['staff']) ?>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($socialPosts)): ?>
<details class="card note">
    <summary><?= allstat_icon('info', 'icon') ?><?= h(t('Příspěvky – informace')) ?> <small class="is-muted">LinkedIn</small><?= allstat_icon('chevron-down', 'note-chev') ?></summary>
    <div class="note-body">
        <p><?= t('Naposledy synchronizováno: {value}.', ['value' => '<strong>' . h($data['meta']['lastSync'] ?? t('nesync.')) . '</strong>']) ?></p>
        <p><?= t('Datum a čas jsou v zóně Europe/Prague. <strong>Dosah</strong> u LinkedInu = unikátní zobrazení příspěvku, <strong>míra zapojení</strong> = (reakce + komentáře + sdílení) ÷ dosah. Načítají se všechny příspěvky publikované ve zvoleném období (stránkováním); detailní statistiky u jednotlivých příspěvků dává LinkedIn jen zhruba 12 měsíců zpět.') ?></p>
    </div>
</details>
<?php endif; ?>
