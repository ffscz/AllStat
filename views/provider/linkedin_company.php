<?php
/**
 * Provider-view partial — LinkedIn Company: post-level content analytics (souhrn + výkon podle typu obsahu
 * + nejlepší čas + Top příspěvky + všechny příspěvky), reusing the shared social_posts pipeline.
 * Included by index.php when provider_key = 'linkedin_company'. In scope: $socialPosts + $socialPostsAll +
 * $data + $viewSourceId + h(). Presentation only. Per-post DOSAH = LinkedIn unikátní zobrazení;
 * ENGAGEMENT = reakce + komentáře + sdílení (bez prokliků).
 */
$delta = static function (string $key) use ($socialPosts): string {
    $d = $socialPosts['deltas'][$key] ?? null;
    if (!$d || ($d['trend'] ?? 'none') === 'none') {
        return '<div class="kpi-meta"><span class="trend trend-none">' . h(t('bez srovnání')) . '</span></div>';
    }
    $cls = $d['trend'] === 'up' ? 'trend-up' : 'trend-down';
    $arrow = $d['trend'] === 'up' ? '&uarr;' : '&darr;';

    return '<div class="kpi-meta"><span class="trend ' . $cls . '">' . $arrow . ' ' . h($d['label'])
        . '</span> <span>' . h(t('vs. předchozí období')) . '</span></div>';
};
?>
<?php if (!empty($socialPosts)): ?>
<section class="analytics-grid" aria-label="<?= h(t('Příspěvky – informace')) ?>">
    <article class="panel panel-wide">
        <div class="panel-header"><h2><?= h(t('Příspěvky stránky')) ?> <span>(LinkedIn)</span></h2></div>
        <p class="panel-help">
            <?= t('Naposledy synchronizováno: {value}.', ['value' => '<strong>' . h($data['meta']['lastSync'] ?? t('nesync.')) . '</strong>']) ?>
            <?= t('Datum a čas jsou v zóně Europe/Prague. <strong>Dosah</strong> u LinkedInu = unikátní zobrazení příspěvku, <strong>míra zapojení</strong> = (reakce + komentáře + sdílení) ÷ dosah. Načítají se všechny příspěvky publikované ve zvoleném období (stránkováním); detailní statistiky u jednotlivých příspěvků dává LinkedIn jen zhruba 12 měsíců zpět.') ?>
        </p>
    </article>
</section>

<section class="kpi-grid" aria-label="<?= h(t('Příspěvky – souhrn')) ?>">
    <article class="kpi-card kpi-violet">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="award"></i></span><span><strong><?= h(t('Počet příspěvků')) ?></strong> <small>(<?= h(t('za období')) ?>)</small></span></div>
        <div class="kpi-value"><?= h(allstat_number((int) $socialPosts['count'])) ?></div>
        <?= $delta('count') ?>
    </article>
    <article class="kpi-card kpi-blue">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="eye"></i></span><span><strong><?= h(t('Dosah příspěvků')) ?></strong> <small>(<?= h(t('součet unik. zobrazení')) ?>)</small></span></div>
        <div class="kpi-value"><?= $socialPosts['reach'] > 0 ? h(allstat_number((int) $socialPosts['reach'])) : '—' ?></div>
        <?= $delta('reach') ?>
    </article>
    <article class="kpi-card kpi-rose">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="activity"></i></span><span><strong><?= h(t('Míra zapojení')) ?></strong> <small>(<?= h(t('engagement / dosah')) ?>)</small></span></div>
        <div class="kpi-value"><?= h($socialPosts['engRateLabel']) ?></div>
        <?= $delta('engRate') ?>
    </article>
    <article class="kpi-card kpi-orange">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="heart"></i></span><span><strong><?= h(t('Celkový engagement')) ?></strong> <small>(<?= h(t('reakce+kom.+sdíl.')) ?>)</small></span></div>
        <div class="kpi-value"><?= h($socialPosts['engagementLabel']) ?></div>
        <?= $delta('engagement') ?>
    </article>
    <article class="kpi-card kpi-cyan">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="calendar-days"></i></span><span><strong><?= h(t('Frekvence / týden')) ?></strong></span></div>
        <div class="kpi-value"><?= h(allstat_number((float) $socialPosts['perWeek'], 1)) ?></div>
        <div class="kpi-meta"><span class="trend trend-none"><?= h(t('Ø reakcí {reactions} · Ø kom. {comments}', ['reactions' => allstat_number((float) $socialPosts['avgReactions'], 1), 'comments' => allstat_number((float) $socialPosts['avgComments'], 1)])) ?></span></div>
    </article>
    <article class="kpi-card kpi-teal">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="mouse-pointer-click"></i></span><span><strong><?= h(t('Prokliky')) ?></strong> <small>(<?= h(t('z příspěvků')) ?>)</small></span></div>
        <div class="kpi-value"><?= $socialPosts['clicks'] > 0 ? h(allstat_number((int) $socialPosts['clicks'])) : '—' ?></div>
    </article>
</section>

<?php if (!empty($socialPosts['byFormat'])): ?>
<section class="analytics-grid" aria-label="<?= h(t('Podle typu obsahu')) ?>">
    <article class="panel panel-wide">
        <div class="panel-header"><h2><?= h(t('Výkon podle typu obsahu')) ?> <span>(<?= h(t('co funguje')) ?>)</span></h2></div>
        <p class="panel-help"><?= h(t('Které formáty (text / foto / video / dokument / odkaz / album / anketa) sbírají nejvíc zapojení v průměru na příspěvek. Ø engagement řadí typy podle účinnosti, ne podle počtu.')) ?></p>
        <div class="table-scroll">
            <table>
                <thead><tr><th><?= h(t('Typ obsahu')) ?></th><th><?= h(t('Počet')) ?></th><th><?= h(t('Ø engagement')) ?></th><th><?= h(t('Engagement celkem')) ?></th><th><?= h(t('Dosah')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($socialPosts['byFormat'] as $f): ?>
                        <tr>
                            <td><?= h($f['label']) ?></td>
                            <td><?= h(allstat_number((int) $f['count'])) ?></td>
                            <td><strong><?= h(allstat_number((float) $f['avgEngagement'], 1)) ?></strong></td>
                            <td><?= h(allstat_number((int) $f['engagement'])) ?></td>
                            <td><?= $f['reach'] > 0 ? h(allstat_number((int) $f['reach'])) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>
<?php endif; ?>

<?php include __DIR__ . '/_heatmap.php'; ?>

<section class="tables-grid" aria-label="<?= h(t('Top příspěvky')) ?>" id="vsechny-prispevky">
    <article class="panel table-panel table-panel-wide">
        <div class="panel-header"><h2><?= h(t('Top 5 příspěvků dle engagementu')) ?> <span>(LinkedIn)</span></h2></div>
        <p class="panel-help"><?= h(t('Nejúspěšnější příspěvky za zvolené období podle součtu reakcí, komentářů a sdílení. Klikni na text příspěvku pro jeho otevření na LinkedInu.')) ?></p>
        <div class="table-scroll">
            <table>
                <thead><tr><th><?= h(t('Příspěvek')) ?></th><th><?= h(t('Datum')) ?></th><th><?= h(t('Typ')) ?></th><th><?= h(t('Dosah')) ?></th><th><?= h(t('Reakce')) ?></th><th><?= h(t('Komentáře')) ?></th><th><?= h(t('Sdílení')) ?></th><th><?= h(t('Zapojení %')) ?></th><th>Engagement</th></tr></thead>
                <tbody>
                    <?php foreach ($socialPosts['top'] as $post): ?>
                        <tr>
                            <td><?php if (($post['permalink'] ?? '') !== ''): ?><a class="text-link" href="<?= h($post['permalink']) ?>" target="_blank" rel="noopener"><?= h($post['message']) ?></a><?php else: ?><?= h($post['message']) ?><?php endif; ?></td>
                            <td><?= h($post['date']) ?></td>
                            <td><?= h(allstat_social_format_label((string) ($post['format'] ?? ''))) ?></td>
                            <td><?= h($post['reachLabel'] ?? '—') ?></td>
                            <td><?= h(allstat_number((int) ($post['reactions'] ?? 0))) ?></td>
                            <td><?= h(allstat_number($post['comments'])) ?></td>
                            <td><?= h(allstat_number($post['shares'])) ?></td>
                            <td><?= h($post['engRateLabel'] ?? '—') ?></td>
                            <td><strong><?= h($post['engagementLabel']) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($socialPosts['top'])): ?>
                        <tr><td colspan="9" class="table-muted"><?= h(t('Za vybrané období není žádný příspěvek. Zvol delší období nebo spusť „Stáhnout aktuální data".')) ?></td></tr>
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
            <summary><div><h3><?= h(t('Zobrazit všechny příspěvky za období')) ?></h3><p><?= h(t('Kompletní výpis příspěvků ({total}) s možností stránkování.', ['total' => (int) $socialPostsAll['total']])) ?></p></div></summary>
            <div class="table-scroll" style="margin-top:10px;">
                <table>
                    <thead><tr><th><?= h(t('Příspěvek')) ?></th><th><?= h(t('Datum')) ?></th><th><?= h(t('Typ')) ?></th><th><?= h(t('Dosah')) ?></th><th><?= h(t('Reakce')) ?></th><th><?= h(t('Komentáře')) ?></th><th><?= h(t('Sdílení')) ?></th><th><?= h(t('Zapojení %')) ?></th><th>Engagement</th></tr></thead>
                    <tbody>
                        <?php foreach ($socialPostsAll['rows'] as $post): $msg = mb_strimwidth((string) $post['message'], 0, 90, '…'); ?>
                            <tr>
                                <td><?php if (($post['permalink'] ?? '') !== ''): ?><a class="text-link" href="<?= h($post['permalink']) ?>" target="_blank" rel="noopener"><?= h($msg) ?></a><?php else: ?><?= h($msg) ?><?php endif; ?></td>
                                <td><?= h($post['date']) ?></td>
                                <td><?= h(allstat_social_format_label((string) ($post['format'] ?? ''))) ?></td>
                                <td><?= h($post['reachLabel'] ?? '—') ?></td>
                                <td><?= h(allstat_number((int) ($post['reactions'] ?? 0))) ?></td>
                                <td><?= h(allstat_number($post['comments'])) ?></td>
                                <td><?= h(allstat_number($post['shares'])) ?></td>
                                <td><?= h($post['engRateLabel'] ?? '—') ?></td>
                                <td><strong><?= h($post['engagementLabel']) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ((int) ($socialPostsAll['totalPages'] ?? 1) > 1): ?>
            <nav class="pagination" aria-label="<?= h(t('Stránkování příspěvků')) ?>">
                <a class="text-link <?= (int) $socialPostsAll['page'] <= 1 ? 'is-disabled' : '' ?>" href="<?= (int) $socialPostsAll['page'] > 1 ? h($ppLink((int) $socialPostsAll['page'] - 1)) : '#' ?>"><?= h(t('← Předchozí')) ?></a>
                <span class="pagination-info"><?= h(t('Stránka {page} z {pages}', ['page' => (int) $socialPostsAll['page'], 'pages' => (int) $socialPostsAll['totalPages']])) ?></span>
                <a class="text-link <?= (int) $socialPostsAll['page'] >= (int) $socialPostsAll['totalPages'] ? 'is-disabled' : '' ?>" href="<?= (int) $socialPostsAll['page'] < (int) $socialPostsAll['totalPages'] ? h($ppLink((int) $socialPostsAll['page'] + 1)) : '#' ?>"><?= h(t('Další →')) ?></a>
            </nav>
            <?php endif; ?>
        </details>
        <?php endif; ?>
    </article>
</section>
<?php endif; ?>

<?php
// Fáze C sekce (mimo guard $socialPosts): rozpad reakcí (za období) + demografie sledujících + složení
// návštěvníků (obě demografie = celoživotní stav z LinkedIn API).
$liHasDemo = !empty($linkedinFollowerDemographics['hasData']);
$liHasReactions = !empty($linkedinReactions['hasData']);
$liHasVisitors = !empty($linkedinVisitorDemographics['hasData']);
if ($liHasDemo || $liHasReactions || $liHasVisitors):
    // Sdílený bar-chart (paleta AllStatu, LinkedIn modrá první; vzor jako Meta Ads demografie).
    $liPal = ['#0A66C2', '#16a34a', '#8b5cf6', '#f59e0b', '#06b6d4', '#f43f5e', '#14b8a6', '#64748b'];
    $liBar = static function (array $rows, array $pal): string {
        if (!$rows) { return ''; }
        $max = max(1.0, ...array_map(static fn ($r) => (float) $r['value'], $rows));
        $out = ''; $i = 0;
        foreach ($rows as $r) {
            $v = (float) $r['value'];
            $share = (float) ($r['share'] ?? 0);
            $shareLabel = $share >= 9.95 ? (string) round($share) : number_format($share, 1, ',', ' ');
            $out .= '<div class="bar-row"><span class="bar-row-label" title="' . h($r['label']) . '">' . h($r['label']) . '</span>'
                . '<div class="bar-row-track"><div class="bar-row-fill" style="width: ' . round(max(2.0, $v / $max * 100), 1) . '%; background: ' . $pal[$i % count($pal)] . ';"></div></div>'
                . '<span class="bar-row-value"><strong>' . h(allstat_number((int) round($v))) . '</strong> <small>(' . $shareLabel . ' %)</small></span></div>';
            $i++;
        }
        return '<div class="bar-chart">' . $out . '</div>';
    };
?>
<?php if ($liHasReactions): ?>
<section class="analytics-grid" aria-label="<?= h(t('Rozpad reakcí')) ?>">
    <article class="panel panel-wide">
        <div class="panel-header"><h2><?= h(t('Rozpad reakcí')) ?> <span>(<?= h(t('podle typu')) ?>)</span></h2></div>
        <p class="panel-help"><?= t('Jak lidé reagovali na příspěvky publikované ve zvoleném období, podle typu reakce (To se mi líbí, Gratuluji, Podpora, Zajímavé…). Celkem <strong>{total}</strong> reakcí. Delší pruh = větší podíl. Načítá se z profilů příspěvků z tohoto období.', ['total' => h(allstat_number((int) ($linkedinReactions['total'] ?? 0)))]) ?></p>
        <?= $liBar($linkedinReactions['rows'], $liPal) ?>
    </article>
</section>
<?php endif; ?>
<?php if ($liHasDemo): $liD = $linkedinFollowerDemographics; ?>
<section class="analytics-grid" aria-label="<?= h(t('Demografie sledujících')) ?>">
    <article class="panel panel-wide">
        <div class="panel-header"><h2><?= h(t('Demografie sledujících')) ?> <span>(LinkedIn)</span></h2></div>
        <p class="panel-help"><?= t('Kdo sleduje firemní stránku, podle profesního profilu. Je to <strong>celoživotní stav</strong> z LinkedIn API (ne za zvolené období), aktualizuje se při každém stažení dat') ?><?= ($liD['date'] ?? '') !== '' ? ', ' . h(t('naposledy {date}', ['date' => allstat_date_label($liD['date'])])) : '' ?>. <?= h(t('Delší pruh = větší podíl. Rozpad je jen z profilů sledujících, které LinkedIn zná, součet nemusí dát 100 % všech sledujících.')) ?></p>
        <div class="demo-facets">
            <?php if ($liD['seniority']): ?><div><h3 class="content-subhead"><?= h(t('Seniorita')) ?></h3><?= $liBar($liD['seniority'], $liPal) ?></div><?php endif; ?>
            <?php if ($liD['function']): ?><div><h3 class="content-subhead"><?= h(t('Funkce')) ?></h3><?= $liBar($liD['function'], $liPal) ?></div><?php endif; ?>
            <?php if ($liD['industry']): ?><div><h3 class="content-subhead"><?= h(t('Obor (top 12)')) ?></h3><?= $liBar($liD['industry'], $liPal) ?></div><?php endif; ?>
            <?php if ($liD['region']): ?><div><h3 class="content-subhead"><?= h(t('Lokalita (top 12)')) ?></h3><?= $liBar($liD['region'], $liPal) ?></div><?php endif; ?>
            <?php if ($liD['country']): ?><div><h3 class="content-subhead"><?= h(t('Země (top 12)')) ?></h3><?= $liBar($liD['country'], $liPal) ?></div><?php endif; ?>
            <?php if ($liD['staff']): ?><div><h3 class="content-subhead"><?= h(t('Velikost firmy sledujícího')) ?></h3><?= $liBar($liD['staff'], $liPal) ?></div><?php endif; ?>
        </div>
    </article>
</section>
<?php endif; ?>
<?php if ($liHasVisitors): $liV = $linkedinVisitorDemographics; ?>
<section class="analytics-grid" aria-label="<?= h(t('Složení návštěvníků')) ?>">
    <article class="panel panel-wide">
        <div class="panel-header"><h2><?= h(t('Složení návštěvníků stránky')) ?> <span>(LinkedIn)</span></h2></div>
        <p class="panel-help"><?= h(t('Kdo navštěvuje firemní stránku, podle profesního profilu (z návštěv stránky, ne ze sledujících). Celoživotní stav z LinkedIn API')) ?><?= ($liV['date'] ?? '') !== '' ? ', ' . h(t('naposledy {date}', ['date' => allstat_date_label($liV['date'])])) : '' ?>. <?= h(t('Delší pruh = větší podíl návštěv.')) ?></p>
        <div class="demo-facets">
            <?php if ($liV['function']): ?><div><h3 class="content-subhead"><?= h(t('Pracovní funkce')) ?></h3><?= $liBar($liV['function'], $liPal) ?></div><?php endif; ?>
            <?php if ($liV['seniority']): ?><div><h3 class="content-subhead"><?= h(t('Seniorita')) ?></h3><?= $liBar($liV['seniority'], $liPal) ?></div><?php endif; ?>
            <?php if ($liV['industry']): ?><div><h3 class="content-subhead"><?= h(t('Obor (top 12)')) ?></h3><?= $liBar($liV['industry'], $liPal) ?></div><?php endif; ?>
            <?php if ($liV['region']): ?><div><h3 class="content-subhead"><?= h(t('Lokalita (top 12)')) ?></h3><?= $liBar($liV['region'], $liPal) ?></div><?php endif; ?>
            <?php if ($liV['staff']): ?><div><h3 class="content-subhead"><?= h(t('Velikost firmy návštěvníka')) ?></h3><?= $liBar($liV['staff'], $liPal) ?></div><?php endif; ?>
        </div>
    </article>
</section>
<?php endif; ?>
<?php endif; ?>
