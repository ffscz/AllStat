<?php
/**
 * Detail zdroje – Instagram Business: sledující, příspěvky a jejich výkon, formáty obsahu, nejlepší čas pro
 * publikaci, okruh uživatelů (sledující vs. nesledující), demografie, Stories, nejúspěšnější příspěvky a
 * top podle uložení. Includuje views/provider.php pod záložkami metrik účtu. V rozsahu z index.php:
 * $socialPosts, $socialPostsAll, $socialStories, $igAudience, $igDemographics, $providerPayload, $data,
 * $config, $viewSourceId. Engagement = lajky + komentáře + sdílení. Sdílení IG sync stahuje až od 29. 9. 2026
 * (insights.metric(shares)), starší příspěvky je dostanou po „Stáhnout historii" nebo importu CSV z Business Suite.
 * Jen prezentace, bez přístupu do DB.
 */
if (empty($socialPosts)) {
    return;
}
$importUrl = allstat_url($config, 'admin/social-import.php');
$deltaRow = static fn (string $label, string $value, ?array $delta = null, string $title = ''): array => ['label' => $label, 'value' => $value, 'title' => $title] + allstat_ui_social_delta($delta);
// Měřáky z řádků label + value (počty): pruh = poměr k největší položce, podíl = z celku řádků.
$igMeters = static function (array $rows): string {
    $values = array_map(static fn (array $r): int => (int) $r['value'], $rows);
    $total = array_sum($values);

    return allstat_ui_meters(array_map(static fn (array $r, int $v, float $w) => [
        'label' => $r['label'],
        'value' => allstat_number($v),
        'share' => $total > 0 ? allstat_percent(round($v / $total * 100), 0) : '',
        'width' => $w,
    ], $rows, $values, allstat_ui_widths($values)));
};
// IG má jen jeden typ reakce (lajk): „rozpad" je ❤️ lajky + 💬 komentáře + 🔁 sdílení.
$igReactions = static function (array $post): string {
    $shares = (int) ($post['shares'] ?? 0);

    return '❤️ ' . allstat_number((int) ($post['reactions'] ?? 0)) . ' · 💬 ' . allstat_number((int) ($post['comments'] ?? 0))
        . ($shares > 0 ? ' · 🔁 ' . allstat_number($shares) : '');
};
?>
<div class="grid grid-3">
    <?php include __DIR__ . '/_followers.php'; ?>
    <section class="card">
        <?= allstat_ui_card_head(t('Příspěvky'), '', h(t('Publikované za období, změna proti předchozímu. Stories se nepočítají.'))) ?>
        <div class="card-body">
            <?= allstat_ui_rows([
                $deltaRow(t('Počet příspěvků'), allstat_number((int) $socialPosts['count']), $socialPosts['deltas']['count'] ?? null),
                ['label' => t('Frekvence / týden'), 'value' => allstat_number((float) $socialPosts['perWeek'], 1)],
                ['label' => t('Ø lajků'), 'value' => allstat_number((float) $socialPosts['avgReactions'], 1)],
                ['label' => t('Ø komentářů'), 'value' => allstat_number((float) $socialPosts['avgComments'], 1)],
            ]) ?>
        </div>
    </section>
    <section class="card">
        <?= allstat_ui_card_head(t('Výkon příspěvků'), '', h(t('Součty za příspěvky publikované v období.'))) ?>
        <div class="card-body">
            <?php
            $perf = [
                $deltaRow(t('Celkový engagement'), (string) $socialPosts['engagementLabel'], $socialPosts['deltas']['engagement'] ?? null, t('Lajky + komentáře + sdílení na příspěvcích.')),
            ];
            if (((int) ($socialPosts['saved'] ?? 0)) > 0) {
                $perf[] = ['label' => t('Uložení'), 'value' => (string) $socialPosts['savedLabel'], 'title' => t('Kolikrát si lidé příspěvek uložili (Instagram „saved"). Nejsilnější signál kvality obsahu, uložení = „tohle si chci najít později".')];
                $perf[] = ['label' => t('Ø uložení na příspěvek'), 'value' => allstat_number((float) ($socialPosts['avgSaved'] ?? 0), 1)];
            }
            ?>
            <?= allstat_ui_rows($perf) ?>
        </div>
    </section>
</div>

<div class="grid grid-2">
    <?php include __DIR__ . '/_formats.php'; ?>
    <?php include __DIR__ . '/_heatmap.php'; ?>
</div>

<?php if ($igAudience['hasData'] ?? false):
    $audBuild = static function (array $d): array {
        $rows = [];
        if ((int) ($d['follower'] ?? 0) > 0)    { $rows[] = ['label' => t('Sledující'),   'value' => (int) $d['follower']]; }
        if ((int) ($d['nonFollower'] ?? 0) > 0) { $rows[] = ['label' => t('Nesledující'), 'value' => (int) $d['nonFollower']]; }
        if ((int) ($d['unknown'] ?? 0) > 0)     { $rows[] = ['label' => t('Neznámí'),     'value' => (int) $d['unknown']]; }
        return $rows;
    };
    $audStack = static fn (string $title, array $rows): string => allstat_ui_stack($title, '', array_map(static function (array $r) use ($rows): array {
        $total = max(1, array_sum(array_column($rows, 'value')));
        $pct = (int) $r['value'] / $total * 100;

        return ['label' => $r['label'], 'value' => allstat_number((int) $r['value']), 'share' => allstat_percent(round($pct), 0), 'pct' => $pct];
    }, $rows));
    $audReach = $audBuild($igAudience['reach']);
    $audViews = $audBuild($igAudience['views']);
?>
<section class="card">
    <?= allstat_ui_card_head(t('Okruh uživatelů'), t('sledující vs. nesledující'), h(t('Podíl dosahu a zobrazení za období na sledující a nesledující účty. Vysoký podíl nesledujících znamená, že se obsah šíří dál.'))) ?>
    <div class="card-body grid grid-2">
        <?php if ($audReach): ?><?= $audStack(t('Dosah'), $audReach) ?><?php endif; ?>
        <?php if ($audViews): ?><?= $audStack(t('Zobrazení'), $audViews) ?><?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($igDemographics['hasData'] ?? false):
    $ageRows = $igDemographics['age'];
    usort($ageRows, static fn ($a, $b) => strcmp((string) $a['label'], (string) $b['label']));
?>
<section class="card">
    <?= allstat_ui_card_head(t('Demografické údaje'), t('sledující'), h(t('Věk, pohlaví a lokalita aktuálních sledujících, snímek z Meta API nezávislý na období. Dostupné u účtů se 100+ sledujícími.')),
        !empty($igDemographics['date']) ? allstat_ui_card_total(t('k {date}', ['date' => allstat_date_label($igDemographics['date'])])) : '') ?>
    <div class="card-body grid grid-3">
        <?php if ($ageRows): ?><div class="meter"><span class="block-title"><?= h(t('Věk')) ?></span><?= $igMeters($ageRows) ?></div><?php endif; ?>
        <div class="grid">
            <?php if ($igDemographics['gender']): ?>
                <?php $gTotal = max(1, array_sum(array_map(static fn (array $g): int => (int) $g['value'], $igDemographics['gender']))); ?>
                <?= allstat_ui_stack(t('Pohlaví'), '', array_map(static fn (array $g): array => ['label' => $g['label'], 'value' => allstat_number((int) $g['value']), 'share' => allstat_percent(round((int) $g['value'] / $gTotal * 100), 0), 'pct' => (int) $g['value'] / $gTotal * 100], $igDemographics['gender'])) ?>
            <?php endif; ?>
            <?php if ($igDemographics['country']): ?><div class="meter"><span class="block-title"><?= h(t('Země')) ?></span><?= $igMeters(array_slice($igDemographics['country'], 0, 5)) ?></div><?php endif; ?>
        </div>
        <?php if ($igDemographics['city']): ?><div class="meter"><span class="block-title"><?= h(t('Města (top 8)')) ?></span><?= $igMeters(array_slice($igDemographics['city'], 0, 8)) ?></div><?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php include __DIR__ . '/_stories.php'; ?>

<?php $postsNetwork = 'Instagram'; $postsExtraCol = ['label' => t('Uložení'), 'key' => 'savedLabel']; include __DIR__ . '/_posts.php'; ?>

<?php if (!empty($socialPosts['topSaved'])): ?>
<section class="card">
    <?= allstat_ui_card_head(t('Top 5 podle uložení'), '', h(t('Nejsilnější signál kvality obsahu.'))) ?>
    <div class="card-body">
        <div class="table-wrap">
            <table class="table table-plain">
                <thead><tr><th scope="col"><?= h(t('Příspěvek')) ?></th><th class="col-sm" scope="col"><?= h(t('Datum')) ?></th><th class="col-num" scope="col"><?= h(t('Uložení')) ?></th><th class="col-sm" scope="col"><?= h(t('Zobrazení')) ?></th><th class="col-md" scope="col"><?= h(t('Reakce')) ?></th><th class="col-num" scope="col">Engagement</th></tr></thead>
                <tbody>
                    <?php foreach ($socialPosts['topSaved'] as $post): ?>
                        <tr>
                            <td><div class="post"><?php if (($post['permalink'] ?? '') !== ''): ?><a class="post-text" href="<?= h($post['permalink']) ?>" target="_blank" rel="noopener"><?= h($post['message']) ?></a><?php else: ?><span class="post-text"><?= h($post['message']) ?></span><?php endif; ?></div></td>
                            <td class="num"><?= h($post['date']) ?></td>
                            <td class="num is-strong"><?= h($post['savedLabel'] ?? '–') ?></td>
                            <td class="num"><?= h($post['viewsLabel'] ?? '–') ?></td>
                            <td class="num"><?= h($igReactions($post)) ?></td>
                            <td class="num"><?= h($post['engagementLabel']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php endif; ?>

<details class="card note">
    <summary><?= allstat_icon('download', 'icon') ?><?= h(t('Doplnit data z CSV')) ?> <small class="is-muted">Meta Business Suite</small><?= allstat_icon('chevron-down', 'note-chev') ?></summary>
    <div class="note-body">
        <p><?= t('<strong>Co Meta přes API u Instagramu neposkytuje (a proto to zde není):</strong> rozpad Interakcí na „Od sledujících / nesledujících", <strong>Kliknutí na odkaz</strong> a <strong>Návštěvy profilu</strong> – tyto účtové metriky Meta v lednu 2025 z API zrušila a nechává je jen ve svém Business Suite. <strong>Zobrazení</strong> (views) se u příspěvků doplní po nejbližší synchronizaci; do té doby ukazujeme <strong>Dosah</strong>.') ?></p>
        <p><?= t('Instagram posílá všechna čísla příspěvků přes API, noční synchronizace ale obnovuje jen posledních 7 dní. Starší příspěvky zaktualizuješ tlačítkem Stáhnout historii u napojení, nebo tímhle exportem: v Business Suite Přehledy → Obsah → Exportovat data, záložka Instagram, jen tenhle účet a volba Příspěvek, pak Generovat a po chvíli Stáhnout export. {link}.', ['link' => '<a href="' . h($importUrl) . '#obsah">' . h(t('Návod krok za krokem')) . '</a>']) ?></p>
        <form class="callout-actions" method="post" action="<?= h($importUrl) ?>" enctype="multipart/form-data">
            <?= allstat_csrf_field() ?>
            <input type="hidden" name="connection_id" value="<?= (int) $viewSourceId ?>">
            <input type="hidden" name="return_to" value="dashboard">
            <input class="input" type="file" name="csv" accept=".csv,text/csv" required aria-label="<?= h(t('CSV s obsahem příspěvků')) ?>">
            <button class="btn btn-primary btn-sm" type="submit"><?= h(t('Importovat a doplnit')) ?></button>
        </form>
    </div>
</details>
