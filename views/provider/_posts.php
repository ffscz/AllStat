<?php
/**
 * Sdílený partial – „Nejúspěšnější příspěvky“ + rozbalovací výpis všech příspěvků za období se
 * stránkováním. Includuje se z facebook_pages.php a instagram_business.php.
 * V rozsahu: $socialPosts, $socialPostsAll, $data, $viewSourceId, $postsNetwork (Facebook / Instagram),
 * $postsExtraCol (volitelně ['label' => …, 'key' => 'savedLabel'] – sloupec navíc, např. uložení u IG).
 * Řádek příspěvku má rozbalovací detail ve členění Meta Business Suite (druhý <tr>, skrytý).
 */
$postsExtraCol = $postsExtraCol ?? null;
$postsCols = 8 + ($postsExtraCol ? 1 : 0);
$postType = static fn (array $post): string => ($post['type'] ?? 'post') === 'reel' ? 'reel' : 'post';
$postTypeLabel = static fn (array $post): string => match ($post['type'] ?? 'post') {
    'reel' => 'Reel',
    'story' => 'Story',
    default => allstat_social_format_label((string) ($post['format'] ?? '')),
};
$rxTitle = static function (array $post): string {
    $parts = [];
    foreach ($post['reactionBreakdown'] ?? [] as $r) {
        $parts[] = $r['emoji'] . ' ' . $r['label'] . ' ' . allstat_number((int) $r['count']);
    }

    return implode(', ', $parts);
};
$detailId = 0;
$postRow = static function (array $post) use (&$detailId, $postsCols, $postsExtraCol, $postType, $postTypeLabel, $rxTitle): string {
    $groups = array_filter($post['detail'] ?? [], static fn (array $g): bool => !empty($g['items']));
    $id = $groups ? 'post-detail-' . (++$detailId) : '';
    $text = ($post['permalink'] ?? '') !== ''
        ? '<a class="post-text" href="' . h($post['permalink']) . '" target="_blank" rel="noopener">' . h($post['message']) . '</a>'
        : '<span class="post-text">' . h($post['message']) . '</span>';
    $toggle = $groups ? '<button type="button" class="btn-ghost btn-sm" data-post-detail="' . $id . '" aria-expanded="false" aria-controls="' . $id . '">'
        . h(t('Detail')) . allstat_icon('chevron-down', 'icon-sm') . '</button>' : '';
    $rx = $rxTitle($post);
    $html = '<tr data-content-type="' . $postType($post) . '">'
        . '<td><div class="post">' . $text . '<span class="post-meta"><span class="chip">' . h($postTypeLabel($post)) . '</span><span class="num">' . h($post['date']) . '</span>' . $toggle . '</span></div></td>'
        . '<td class="num is-strong">' . h($post['viewsLabel'] ?? '–') . '</td>'
        . '<td class="num">' . h($post['reachLabel'] ?? '–') . '</td>'
        . '<td class="num"' . ($rx !== '' ? ' title="' . h($rx) . '"' : '') . '>' . h(allstat_number((int) $post['reactions'])) . '</td>'
        . '<td class="num">' . h(allstat_number((int) $post['comments'])) . '</td>'
        . '<td class="num">' . h(allstat_number((int) $post['shares'])) . '</td>'
        . ($postsExtraCol ? '<td class="num">' . h($post[$postsExtraCol['key']] ?? '–') . '</td>' : '')
        . '<td class="num">' . h($post['engagementLabel']) . '</td>'
        . '<td class="num">' . h($post['engRateLabel'] ?? '–') . '</td></tr>';
    if ($groups) {
        $html .= '<tr class="row-detail" id="' . $id . '" hidden><td colspan="' . $postsCols . '"><div class="grid grid-tiles">';
        foreach ($groups as $g) {
            $html .= '<div><span class="block-title">' . h((string) $g['title']) . '</span><div class="rows rows-compact">';
            foreach ($g['items'] as $it) {
                $hint = (string) ($it['hint'] ?? '');
                $html .= '<div class="row"' . ($hint !== '' ? ' title="' . h($hint) . '"' : '') . '><span class="row-label">' . h((string) $it['label'])
                    . '</span><span class="row-end"><span class="row-value num">' . h((string) $it['value']) . '</span></span></div>';
            }
            $html .= '</div></div>';
        }
        $html .= '</div></td></tr>';
    }

    return $html;
};
$postsHead = '<thead><tr><th scope="col">' . h(t('Příspěvek')) . '</th>'
    . '<th class="col-sm" scope="col">' . h(t('Zobrazení')) . '</th>'
    . '<th class="col-num" scope="col">' . h(t('Dosah')) . '</th>'
    . '<th class="col-num" scope="col">' . h(t('Reakce')) . '</th>'
    . '<th class="col-sm" scope="col">' . h(t('Komentáře')) . '</th>'
    . '<th class="col-num" scope="col">' . h(t('Sdílení')) . '</th>'
    . ($postsExtraCol ? '<th class="col-num" scope="col">' . h($postsExtraCol['label']) . '</th>' : '')
    . '<th class="col-num" scope="col">' . h(t('Zapojení')) . '</th>'
    . '<th class="col-sm" scope="col">' . h(t('Míra zapojení')) . '</th></tr></thead>';
$postsTotal = (int) ($socialPostsAll['total'] ?? 0);
?>
<section class="card card-clip" id="vsechny-prispevky" data-posts>
    <?= allstat_ui_card_head(t('Nejúspěšnější příspěvky'), $postsNetwork ?? '',
        h(t('Pět příspěvků za období s nejvyšším zapojením (reakce, komentáře, sdílení). Reakce i komentáře se počítají jen na originálu příspěvku, stejně jako v Business Suite.')),
        '<div class="seg" role="group" aria-label="' . h(t('Filtr podle typu obsahu')) . '">'
        . '<button type="button" class="seg-item" aria-pressed="true" data-content-filter="all">' . h(t('Vše')) . '</button>'
        . '<button type="button" class="seg-item" aria-pressed="false" data-content-filter="post">' . h(t('Příspěvky')) . '</button>'
        . '<button type="button" class="seg-item" aria-pressed="false" data-content-filter="reel">' . h(t('Reely')) . '</button></div>') ?>
    <div class="table-wrap">
        <table class="table table-wide">
            <?= $postsHead ?>
            <tbody>
                <?php foreach ($socialPosts['top'] as $post): ?><?= $postRow($post) ?><?php endforeach; ?>
                <?php if (empty($socialPosts['top'])): ?>
                    <tr><td colspan="<?= $postsCols ?>" class="is-muted"><?= h(t('Za vybrané období nebyl publikován žádný příspěvek. Spusť „Stáhnout historii" nebo zvol delší období (FB příspěvky nejsou pro dnešek/realtime).')) ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (!empty($socialPostsAll) && $postsTotal > 5):
        $ppBase = '?domain_id=' . (int) $data['domain']['id'] . '&start=' . urlencode($data['range']['start']) . '&end=' . urlencode($data['range']['end']) . '&source_id=' . (int) $viewSourceId;
        $ppLink = static fn (int $n): string => $ppBase . '&posts_page=' . $n . '#vsechny-prispevky';
        $ppPage = (int) ($socialPostsAll['page'] ?? 1);
        $ppPages = (int) ($socialPostsAll['totalPages'] ?? 1);
    ?>
    <details class="note" <?= $ppPage > 1 ? 'open' : '' ?>>
        <summary><?= allstat_icon('list', 'icon') ?><?= h(t('Všech {count} příspěvků za období', ['count' => $postsTotal])) ?><?= allstat_icon('chevron-down', 'note-chev') ?></summary>
        <div class="table-wrap">
            <table class="table table-wide">
                <?= $postsHead ?>
                <tbody>
                    <?php foreach ($socialPostsAll['rows'] as $post): $post['message'] = mb_strimwidth((string) $post['message'], 0, 90, '…'); ?><?= $postRow($post) ?><?php endforeach; ?>
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

<script nonce="<?= h(allstat_nonce()) ?>">
(function () {
    var root = document.querySelector('[data-posts]');
    if (!root) { return; }
    // Detail příspěvku: druhý řádek pod příspěvkem, skrytý do kliknutí.
    var setDetail = function (btn, open) {
        var row = document.getElementById(btn.getAttribute('data-post-detail'));
        if (!row) { return; }
        row.hidden = !open;
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    };
    root.querySelectorAll('[data-post-detail]').forEach(function (btn) {
        btn.addEventListener('click', function () { setDetail(btn, btn.getAttribute('aria-expanded') !== 'true'); });
    });
    // Filtr typu obsahu; skrytý řádek zavře i svůj detail.
    var filters = root.querySelectorAll('[data-content-filter]');
    filters.forEach(function (f) {
        f.addEventListener('click', function () {
            var type = f.getAttribute('data-content-filter');
            filters.forEach(function (x) { x.setAttribute('aria-pressed', x === f ? 'true' : 'false'); });
            root.querySelectorAll('tr[data-content-type]').forEach(function (row) {
                var hide = !(type === 'all' || row.getAttribute('data-content-type') === type);
                row.hidden = hide;
                var btn = row.querySelector('[data-post-detail]');
                if (hide && btn) { setDetail(btn, false); }
            });
        });
    });
})();
</script>
