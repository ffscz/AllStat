<?php
/**
 * Sdílený partial – „Nejlepší čas pro publikaci“: heatmapa den × část dne z VLASTNÍCH příspěvků
 * (barva = Ø zapojení, číslo = počet příspěvků) + tři nejsilnější okna se skóre 0–100.
 * Includuje se z views/provider/facebook_pages.php, instagram_business.php a linkedin_company.php.
 * Očekává $socialPosts (repository allstat_get_social_posts). Sloty s 1 příspěvkem se řadí až za
 * vícekrát ověřené (repository), tady se značí „malý vzorek“.
 */
if (empty($socialPosts['heatmap']) || (int) ($socialPosts['heatmap']['total'] ?? 0) <= 0) {
    return;
}
$hm = $socialPosts['heatmap'];
$hmBest = $hm['top'][0] ?? null;
?>
<section class="card">
    <?= allstat_ui_card_head(t('Nejlepší čas pro publikaci'), '', h(t('Barva = průměrné zapojení, číslo = počet příspěvků. Čas Europe/Prague.'))) ?>
    <div class="card-body">
        <div class="heatmap" role="img" aria-label="<?= h($hmBest ? t('Nejvyšší zapojení mají příspěvky v čase {day} {slot} h', ['day' => $hmBest['day'], 'slot' => $hmBest['slot']]) : t('Heatmapa nejlepšího času pro publikaci')) ?>">
            <span></span>
            <?php foreach ($hm['buckets'] as $b): ?><span class="heatmap-head"><?= h($b) ?></span><?php endforeach; ?>
            <?php foreach ($hm['days'] as $w => $dayLabel): ?>
                <span class="heatmap-day"><?= h($dayLabel) ?></span>
                <?php foreach ($hm['buckets'] as $b => $_): $cell = $hm['grid'][$w][$b] ?? ['count' => 0, 'avg' => 0];
                    $level = ($hm['maxAvg'] > 0 && $cell['count'] > 0) ? max(1, (int) ceil((float) $cell['avg'] / (float) $hm['maxAvg'] * 4)) : 0; ?>
                    <span class="heatmap-cell num is-<?= $level ?>" title="<?= h(t('{day} {slot} h, {posts}, Ø {avg} zapojení', ['day' => $dayLabel, 'slot' => $hm['buckets'][$b], 'posts' => tn((int) $cell['count'], '{count} příspěvek', '{count} příspěvky', '{count} příspěvků'), 'avg' => allstat_number((float) ($cell['avg'] ?? 0), 1)])) ?>"><?= $cell['count'] > 0 ? (int) $cell['count'] : '' ?></span>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($hm['top'])): ?>
        <div class="slots">
            <?php foreach ($hm['top'] as $i => $slot): ?>
                <div class="slot">
                    <span class="slot-title"><span><?= $i + 1 ?>.</span><?= h($slot['day']) ?> <?= h($slot['slot']) ?> h</span>
                    <span class="block-desc"><?= h(t('Ø {avg} zapojení · {posts} · skóre {score}/100', ['avg' => allstat_number((float) $slot['avg'], 1), 'posts' => tn((int) $slot['count'], '{count} příspěvek', '{count} příspěvky', '{count} příspěvků'), 'score' => (int) ($slot['score'] ?? 0)])) ?><?= !empty($slot['lowSample']) ? ' · ' . h(t('malý vzorek')) : '' ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>
