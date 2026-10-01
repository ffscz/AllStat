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
        return '<div class="kpi-meta"><span class="trend trend-none">bez srovnání</span></div>';
    }
    $cls = $d['trend'] === 'up' ? 'trend-up' : 'trend-down';
    $arrow = $d['trend'] === 'up' ? '&uarr;' : '&darr;';

    return '<div class="kpi-meta"><span class="trend ' . $cls . '">' . $arrow . ' ' . h($d['label'])
        . '</span> <span>vs. předchozí období</span></div>';
};
?>
<?php if (!empty($socialPosts)): ?>
<section class="analytics-grid" aria-label="Příspěvky – informace">
    <article class="panel panel-wide">
        <div class="panel-header"><h2>Příspěvky stránky <span>(LinkedIn)</span></h2></div>
        <p class="panel-help">
            Naposledy synchronizováno: <strong><?= h($data['meta']['lastSync'] ?? 'nesync.') ?></strong>.
            Datum a čas jsou v zóně Europe/Prague. <strong>Dosah</strong> u LinkedInu = unikátní zobrazení příspěvku,
            <strong>míra zapojení</strong> = (reakce + komentáře + sdílení) ÷ dosah. Načítají se všechny příspěvky
            publikované ve zvoleném období (stránkováním); detailní statistiky u jednotlivých příspěvků dává LinkedIn jen zhruba 12 měsíců zpět.
        </p>
    </article>
</section>

<section class="kpi-grid" aria-label="Příspěvky – souhrn">
    <article class="kpi-card kpi-violet">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="award"></i></span><span><strong>Počet příspěvků</strong> <small>(za období)</small></span></div>
        <div class="kpi-value"><?= h(allstat_number((int) $socialPosts['count'])) ?></div>
        <?= $delta('count') ?>
    </article>
    <article class="kpi-card kpi-blue">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="eye"></i></span><span><strong>Dosah příspěvků</strong> <small>(součet unik. zobrazení)</small></span></div>
        <div class="kpi-value"><?= $socialPosts['reach'] > 0 ? h(allstat_number((int) $socialPosts['reach'])) : '—' ?></div>
        <?= $delta('reach') ?>
    </article>
    <article class="kpi-card kpi-rose">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="activity"></i></span><span><strong>Míra zapojení</strong> <small>(engagement / dosah)</small></span></div>
        <div class="kpi-value"><?= h($socialPosts['engRateLabel']) ?></div>
        <?= $delta('engRate') ?>
    </article>
    <article class="kpi-card kpi-orange">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="heart"></i></span><span><strong>Celkový engagement</strong> <small>(reakce+kom.+sdíl.)</small></span></div>
        <div class="kpi-value"><?= h($socialPosts['engagementLabel']) ?></div>
        <?= $delta('engagement') ?>
    </article>
    <article class="kpi-card kpi-cyan">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="calendar-days"></i></span><span><strong>Frekvence / týden</strong></span></div>
        <div class="kpi-value"><?= h(allstat_number((float) $socialPosts['perWeek'], 1)) ?></div>
        <div class="kpi-meta"><span class="trend trend-none">Ø reakcí <?= h(allstat_number((float) $socialPosts['avgReactions'], 1)) ?> · Ø kom. <?= h(allstat_number((float) $socialPosts['avgComments'], 1)) ?></span></div>
    </article>
    <article class="kpi-card kpi-teal">
        <div class="kpi-head"><span class="kpi-icon"><i data-lucide="mouse-pointer-click"></i></span><span><strong>Prokliky</strong> <small>(z příspěvků)</small></span></div>
        <div class="kpi-value"><?= $socialPosts['clicks'] > 0 ? h(allstat_number((int) $socialPosts['clicks'])) : '—' ?></div>
    </article>
</section>

<?php if (!empty($socialPosts['byFormat'])): ?>
<section class="analytics-grid" aria-label="Podle typu obsahu">
    <article class="panel panel-wide">
        <div class="panel-header"><h2>Výkon podle typu obsahu <span>(co funguje)</span></h2></div>
        <p class="panel-help">Které formáty (text / foto / video / dokument / odkaz / album / anketa) sbírají nejvíc zapojení v průměru na příspěvek. Ø engagement řadí typy podle účinnosti, ne podle počtu.</p>
        <div class="table-scroll">
            <table>
                <thead><tr><th>Typ obsahu</th><th>Počet</th><th>Ø engagement</th><th>Engagement celkem</th><th>Dosah</th></tr></thead>
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

<section class="tables-grid" aria-label="Top příspěvky" id="vsechny-prispevky">
    <article class="panel table-panel table-panel-wide">
        <div class="panel-header"><h2>Top 5 příspěvků dle engagementu <span>(LinkedIn)</span></h2></div>
        <p class="panel-help">Nejúspěšnější příspěvky za zvolené období podle součtu reakcí, komentářů a sdílení. Klikni na text příspěvku pro jeho otevření na LinkedInu.</p>
        <div class="table-scroll">
            <table>
                <thead><tr><th>Příspěvek</th><th>Datum</th><th>Typ</th><th>Dosah</th><th>Reakce</th><th>Komentáře</th><th>Sdílení</th><th>Zapojení %</th><th>Engagement</th></tr></thead>
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
                        <tr><td colspan="9" class="table-muted">Za vybrané období není žádný příspěvek. Zvol delší období nebo spusť „Stáhnout aktuální data".</td></tr>
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
            <summary><div><h3>Zobrazit všechny příspěvky za období</h3><p>Kompletní výpis příspěvků (<?= (int) $socialPostsAll['total'] ?>) s možností stránkování.</p></div></summary>
            <div class="table-scroll" style="margin-top:10px;">
                <table>
                    <thead><tr><th>Příspěvek</th><th>Datum</th><th>Typ</th><th>Dosah</th><th>Reakce</th><th>Komentáře</th><th>Sdílení</th><th>Zapojení %</th><th>Engagement</th></tr></thead>
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
<section class="analytics-grid" aria-label="Rozpad reakcí">
    <article class="panel panel-wide">
        <div class="panel-header"><h2>Rozpad reakcí <span>(podle typu)</span></h2></div>
        <p class="panel-help">Jak lidé reagovali na příspěvky publikované ve zvoleném období, podle typu reakce (To se mi líbí, Gratuluji, Podpora, Zajímavé…). Celkem <strong><?= h(allstat_number((int) ($linkedinReactions['total'] ?? 0))) ?></strong> reakcí. Delší pruh = větší podíl. Načítá se z profilů příspěvků z tohoto období.</p>
        <?= $liBar($linkedinReactions['rows'], $liPal) ?>
    </article>
</section>
<?php endif; ?>
<?php if ($liHasDemo): $liD = $linkedinFollowerDemographics; ?>
<section class="analytics-grid" aria-label="Demografie sledujících">
    <article class="panel panel-wide">
        <div class="panel-header"><h2>Demografie sledujících <span>(LinkedIn)</span></h2></div>
        <p class="panel-help">Kdo sleduje firemní stránku, podle profesního profilu. Je to <strong>celoživotní stav</strong> z LinkedIn API (ne za zvolené období), aktualizuje se při každém stažení dat<?= ($liD['date'] ?? '') !== '' ? ', naposledy ' . h($liD['date']) : '' ?>. Delší pruh = větší podíl. Rozpad je jen z profilů sledujících, které LinkedIn zná, součet nemusí dát 100 % všech sledujících.</p>
        <div class="demo-facets">
            <?php if ($liD['seniority']): ?><div><h3 class="content-subhead">Seniorita</h3><?= $liBar($liD['seniority'], $liPal) ?></div><?php endif; ?>
            <?php if ($liD['function']): ?><div><h3 class="content-subhead">Funkce</h3><?= $liBar($liD['function'], $liPal) ?></div><?php endif; ?>
            <?php if ($liD['industry']): ?><div><h3 class="content-subhead">Obor (top 12)</h3><?= $liBar($liD['industry'], $liPal) ?></div><?php endif; ?>
            <?php if ($liD['region']): ?><div><h3 class="content-subhead">Lokalita (top 12)</h3><?= $liBar($liD['region'], $liPal) ?></div><?php endif; ?>
            <?php if ($liD['country']): ?><div><h3 class="content-subhead">Země (top 12)</h3><?= $liBar($liD['country'], $liPal) ?></div><?php endif; ?>
            <?php if ($liD['staff']): ?><div><h3 class="content-subhead">Velikost firmy sledujícího</h3><?= $liBar($liD['staff'], $liPal) ?></div><?php endif; ?>
        </div>
    </article>
</section>
<?php endif; ?>
<?php if ($liHasVisitors): $liV = $linkedinVisitorDemographics; ?>
<section class="analytics-grid" aria-label="Složení návštěvníků">
    <article class="panel panel-wide">
        <div class="panel-header"><h2>Složení návštěvníků stránky <span>(LinkedIn)</span></h2></div>
        <p class="panel-help">Kdo navštěvuje firemní stránku, podle profesního profilu (z návštěv stránky, ne ze sledujících). Celoživotní stav z LinkedIn API<?= ($liV['date'] ?? '') !== '' ? ', naposledy ' . h($liV['date']) : '' ?>. Delší pruh = větší podíl návštěv.</p>
        <div class="demo-facets">
            <?php if ($liV['function']): ?><div><h3 class="content-subhead">Pracovní funkce</h3><?= $liBar($liV['function'], $liPal) ?></div><?php endif; ?>
            <?php if ($liV['seniority']): ?><div><h3 class="content-subhead">Seniorita</h3><?= $liBar($liV['seniority'], $liPal) ?></div><?php endif; ?>
            <?php if ($liV['industry']): ?><div><h3 class="content-subhead">Obor (top 12)</h3><?= $liBar($liV['industry'], $liPal) ?></div><?php endif; ?>
            <?php if ($liV['region']): ?><div><h3 class="content-subhead">Lokalita (top 12)</h3><?= $liBar($liV['region'], $liPal) ?></div><?php endif; ?>
            <?php if ($liV['staff']): ?><div><h3 class="content-subhead">Velikost firmy návštěvníka</h3><?= $liBar($liV['staff'], $liPal) ?></div><?php endif; ?>
        </div>
    </article>
</section>
<?php endif; ?>
<?php endif; ?>
