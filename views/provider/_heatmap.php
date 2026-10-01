<?php
/**
 * Sdílený partial — „Nejlepší čas pro publikaci": heatmapa den × část dne z VLASTNÍCH příspěvků
 * + žebříček Top 3 oken se skóre 0–100 (100 = nejsilnější okno) a označením malého vzorku.
 * Includuje se z views/provider/facebook_pages.php, instagram_business.php a linkedin_company.php.
 * Očekává $socialPosts (repository allstat_get_social_posts), h(), allstat_number().
 * Sloty s 1 příspěvkem se řadí až za vícekrát ověřené (repository), tady se značí „malý vzorek".
 */
if (empty($socialPosts['heatmap']) || (int) ($socialPosts['heatmap']['total'] ?? 0) <= 0) {
    return;
}
$hm = $socialPosts['heatmap'];
$hmMedals = ['1.', '2.', '3.'];
?>
<section class="analytics-grid" aria-label="Nejlepší čas pro publikaci">
    <article class="panel panel-wide">
        <div class="panel-header"><h2>Nejlepší čas pro publikaci <span>(dle Ø engagementu)</span></h2></div>
        <p class="panel-help">Kdy příspěvky historicky sbíraly nejvíc zapojení (den v týdnu × část dne, čas Europe/Prague). Sytější políčko = vyšší průměrný engagement. Počítá se z vlastních příspěvků za zvolené období, ne z obecných oborových dat.</p>
        <div class="post-heatmap" role="img" aria-label="Heatmapa nejlepšího času pro publikaci">
            <div class="heatmap-corner"></div>
            <?php foreach ($hm['buckets'] as $b): ?><div class="heatmap-col-label"><?= h($b) ?></div><?php endforeach; ?>
            <?php foreach ($hm['days'] as $w => $dayLabel): ?>
                <div class="heatmap-row-label"><?= h($dayLabel) ?></div>
                <?php foreach ($hm['buckets'] as $b => $_): $cell = $hm['grid'][$w][$b] ?? ['count' => 0, 'avg' => 0];
                    $intensity = ($hm['maxAvg'] > 0 && $cell['count'] > 0) ? max(8, (int) round($cell['avg'] / $hm['maxAvg'] * 100)) : 0; ?>
                    <div class="heatmap-cell<?= $cell['count'] > 0 ? ' has-posts' : '' ?>"
                         style="<?= $intensity > 0 ? 'background: color-mix(in srgb, var(--violet) ' . $intensity . '%, transparent);' : '' ?>"
                         title="<?= h($dayLabel . ' ' . $hm['buckets'][$b] . ' h, ' . $cell['count'] . ' příspěvků, Ø ' . allstat_number((float) ($cell['avg'] ?? 0), 1) . ' zapojení') ?>">
                        <?= $cell['count'] > 0 ? (int) $cell['count'] : '' ?>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($hm['top'])): ?>
        <div class="heatmap-top3">
            <?php foreach ($hm['top'] as $i => $slot): ?>
                <div class="heatmap-top3-item<?= $i === 0 ? ' is-first' : '' ?>">
                    <span class="heatmap-top3-rank"><?= h($hmMedals[$i] ?? '') ?></span>
                    <span class="heatmap-top3-when"><?= h($slot['day']) ?> <?= h($slot['slot']) ?> h</span>
                    <span class="heatmap-top3-meta">skóre <?= (int) ($slot['score'] ?? 0) ?>/100 · Ø <?= h(allstat_number((float) $slot['avg'], 1)) ?> zapojení · <?= (int) $slot['count'] ?> přísp.<?= !empty($slot['lowSample']) ? ' · malý vzorek' : '' ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </article>
</section>
