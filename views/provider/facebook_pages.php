<?php
/**
 * Detail zdroje – Facebook stránka (board b2 návrhu): sledující, příspěvky a jejich výkon, formáty
 * obsahu, nejlepší čas pro publikaci, publikum z CSV, rozpad reakcí, Stories a nejúspěšnější příspěvky.
 * Includuje views/provider.php pod záložkami metrik stránky. V rozsahu z index.php: $socialPosts,
 * $socialPostsAll, $socialStories, $fbDemographics, $providerPayload, $data, $config, $viewSourceId.
 * Jen prezentace, bez přístupu do DB.
 */
if (empty($socialPosts)) {
    return;
}
$importUrl = allstat_url($config, 'admin/social-import.php');
$deltaRow = static fn (string $label, string $value, ?array $delta = null, string $title = ''): array => ['label' => $label, 'value' => $value, 'title' => $title] + allstat_ui_social_delta($delta);
?>
<div class="grid grid-3">
    <?php include __DIR__ . '/_followers.php'; ?>
    <section class="card">
        <?= allstat_ui_card_head(t('Příspěvky'), '', h(t('Publikované za období, změna proti předchozímu. Stories se nepočítají.'))) ?>
        <div class="card-body">
            <?= allstat_ui_rows([
                $deltaRow(t('Počet příspěvků'), allstat_number((int) $socialPosts['count']), $socialPosts['deltas']['count'] ?? null),
                ['label' => t('Frekvence'), 'value' => t('{value} týdně', ['value' => allstat_number((float) $socialPosts['perWeek'], 1)])],
                ['label' => t('Ø reakcí na příspěvek'), 'value' => allstat_number((float) $socialPosts['avgReactions'], 1)],
                ['label' => t('Ø komentářů na příspěvek'), 'value' => allstat_number((float) $socialPosts['avgComments'], 1)],
                ['label' => t('Ø sdílení na příspěvek'), 'value' => allstat_number((float) $socialPosts['avgShares'], 1)],
            ]) ?>
        </div>
    </section>
    <section class="card">
        <?= allstat_ui_card_head(t('Výkon příspěvků'), '', h(t('Součty za příspěvky publikované v období. Dosah a prokliky vyžadují oprávnění read_insights.'))) ?>
        <div class="card-body">
            <?php
            $perf = [
                $deltaRow(t('Dosah příspěvků'), $socialPosts['reach'] > 0 ? allstat_number((int) $socialPosts['reach']) : '–', $socialPosts['deltas']['reach'] ?? null, t('Součet unikátního dosahu jednotlivých příspěvků (post_impressions_unique). Skutečný unikátní dosah za období bývá nižší (lidé se opakují). Vyžaduje read_insights.')),
                $deltaRow(t('Engagement'), (string) $socialPosts['engagementLabel'], $socialPosts['deltas']['engagement'] ?? null, t('Reakce + komentáře + sdílení na příspěvcích.')),
                $deltaRow(t('Míra zapojení'), (string) $socialPosts['engRateLabel'], $socialPosts['deltas']['engRate'] ?? null, t('Engagement rate = (reakce + komentáře + sdílení) / dosah. Nejlepší srovnatelná metrika napříč příspěvky. Bez dosahu (read_insights) se nezobrazí.')),
                ['label' => t('Prokliky'), 'value' => $socialPosts['clicks'] > 0 ? allstat_number((int) $socialPosts['clicks']) : '–', 'title' => t('Součet prokliků na příspěvcích (post_clicks), odkazy, „zobrazit více", fotky. Vyžaduje read_insights.')],
            ];
            if ((int) $socialPosts['videoViews'] > 0) {
                $perf[] = ['label' => t('Zhlédnutí videí'), 'value' => allstat_number((int) $socialPosts['videoViews']), 'title' => t('Zhlédnutí videí 3 s a déle.')];
            }
            if ((int) ($socialPosts['watchTimeSec'] ?? 0) > 0) {
                $perf[] = ['label' => t('Doba sledování'), 'value' => (string) $socialPosts['watchTimeLabel'], 'title' => t('Celková doba sledování videí a reelů za období (post_video_view_time). Naplní se ze synchronizace nebo importu CSV z Business Suite.')];
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

<?php if (!empty($fbDemographics['hasData'])):
    $fbAge = $fbDemographics['age'];
    usort($fbAge, static fn ($a, $b) => strcmp((string) $a['label'], (string) $b['label']));
    // Hodnoty demografie jsou podíly v %: pruh = poměr k největší položce, číslo = podíl.
    $demMeters = static fn (array $rows): array => array_map(static fn (array $r, float $w): array => ['label' => $r['label'], 'value' => allstat_percent((float) $r['value'], 1), 'width' => $w], $rows, allstat_ui_widths(array_column($rows, 'value')));
    $fbDemoAge = !empty($fbDemographics['date']) ? (int) floor((time() - strtotime((string) $fbDemographics['date'])) / 86400) : null;
?>
<section class="card">
    <?= allstat_ui_card_head(t('Publikum'), '', h(t('Věk, pohlaví a města sledujících. Doplněno z exportu Meta Business Suite{date}.', ['date' => !empty($fbDemographics['date']) ? ' ' . t('k {date}', ['date' => allstat_date_label($fbDemographics['date'])]) : ''])),
        '<a class="btn btn-secondary btn-sm" href="' . h($importUrl) . '#publikum">' . h(t('Nahrát nový export')) . '</a>') ?>
    <div class="card-body grid">
        <?php if ($fbDemoAge !== null && $fbDemoAge > 35): ?>
        <div class="callout tone-warn"><?= allstat_icon('clock', 'icon-sm') ?><span><?= t('<strong>Demografie je stará {days} dní.</strong> Publikum se mění pomalu, ale pro čerstvá čísla stáhni v Business Suite nový export ({insights_link} → Exportovat → Exportovat jako CSV) a {import_link}.', ['days' => (int) $fbDemoAge, 'insights_link' => '<a href="https://business.facebook.com/latest/insights/people" target="_blank" rel="noopener">' . h(t('Přehledy → Okruh uživatelů')) . '</a>', 'import_link' => '<a href="' . h($importUrl) . '#publikum">' . h(t('importuj ho')) . '</a>']) ?></span></div>
        <?php endif; ?>
        <div class="grid grid-3">
            <?php if ($fbAge): ?><div class="meter"><span class="block-title"><?= h(t('Věk')) ?></span><?= allstat_ui_meters($demMeters($fbAge)) ?></div><?php endif; ?>
            <div class="grid">
                <?php if (!empty($fbDemographics['gender'])): ?>
                    <?= allstat_ui_stack(t('Pohlaví'), '', array_map(static fn (array $g): array => ['label' => $g['label'], 'value' => allstat_percent((float) $g['value'], 1), 'share' => '', 'pct' => (float) $g['value']], $fbDemographics['gender'])) ?>
                <?php endif; ?>
                <?php if (!empty($fbDemographics['country'])): ?><div class="meter"><span class="block-title"><?= h(t('Země')) ?></span><?= allstat_ui_meters($demMeters(array_slice($fbDemographics['country'], 0, 5))) ?></div><?php endif; ?>
            </div>
            <?php if (!empty($fbDemographics['city'])): ?><div class="meter"><span class="block-title"><?= h(t('Nejčastější města')) ?></span><?= allstat_ui_meters($demMeters(array_slice($fbDemographics['city'], 0, 6))) ?></div><?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php
$reactions = $socialPosts['reactions'] ?? [];
$reactionMeta = [
    'like' => ['👍', t('To se mi líbí')], 'love' => ['❤️', t('Super')], 'haha' => ['😂', 'Haha'],
    'wow' => ['😮', t('Paráda')], 'sad' => ['😢', t('To mě mrzí')], 'angry' => ['😡', t('To mě štve')],
];
if (array_sum($reactions) > 0):
?>
<section class="card">
    <?= allstat_ui_card_head(t('Rozpad reakcí'), '', h(t('Jak lidé na příspěvky reagovali, sentiment, ne jen počet. Pomáhá poznat, který obsah vyvolává emoce.'))) ?>
    <div class="card-body">
        <div class="stats">
            <?php foreach ($reactionMeta as $key => [$emoji, $label]): ?>
                <div><span class="stat-label"><?= $emoji ?> <?= h($label) ?></span><span class="stat-value num"><?= h(allstat_number((int) ($reactions[$key] ?? 0))) ?></span></div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php include __DIR__ . '/_stories.php'; ?>

<?php $postsNetwork = 'Facebook'; $postsExtraCol = null; include __DIR__ . '/_posts.php'; ?>

<details class="card note">
    <summary><?= allstat_icon('download', 'icon') ?><?= h(t('Doplnit data z CSV')) ?> <small class="is-muted">Meta Business Suite</small><?= allstat_icon('chevron-down', 'note-chev') ?></summary>
    <div class="note-body">
        <p><?= t('<strong>Co Meta přes API neposkytuje:</strong> rozpad interakcí na sledující a nesledující, dosah u části příspěvků a demografii publika. Demografii a chybějící údaje o obsahu doplníš z exportů Business Suite. Stejné importy jsou i na stránce {link}.', ['link' => '<a href="' . h($importUrl) . '">' . h(t('Import z CSV')) . '</a>']) ?></p>
        <p><?= t('<strong>Obsah příspěvků:</strong> v Business Suite Přehledy → Obsah → Exportovat data. Záložka Facebook, jen tahle stránka, všech 5 předvoleb metrik, Zobrazení dat Dlouhodobě, Úroveň obsahu Příspěvek. Pak Generovat a po chvíli Stáhnout export. Doplní dosah, rozpad organické a placené a dobu sledování. {link}', ['link' => '<a href="' . h($importUrl) . '#obsah">' . h(t('Návod krok za krokem')) . '</a>']) ?></p>
        <form class="callout-actions" method="post" action="<?= h($importUrl) ?>" enctype="multipart/form-data">
            <?= allstat_csrf_field() ?>
            <input type="hidden" name="import_type" value="content">
            <input type="hidden" name="connection_id" value="<?= (int) $viewSourceId ?>">
            <input type="hidden" name="return_to" value="dashboard">
            <input class="input" type="file" name="csv" accept=".csv,text/csv" required aria-label="<?= h(t('CSV s obsahem příspěvků')) ?>">
            <button class="btn btn-primary btn-sm" type="submit"><?= h(t('Importovat obsah')) ?></button>
        </form>
        <p><?= t('<strong>Publikum a demografie:</strong> v Business Suite Přehledy → Okruh uživatelů (Výběr entity: Facebook) → Exportovat → Exportovat jako CSV, soubor se stáhne hned. Doplní věk, pohlaví, města a země do sekce „Demografické údaje". {link}', ['link' => '<a href="' . h($importUrl) . '#publikum">' . h(t('Návod krok za krokem')) . '</a>']) ?></p>
        <form class="callout-actions" method="post" action="<?= h($importUrl) ?>" enctype="multipart/form-data">
            <?= allstat_csrf_field() ?>
            <input type="hidden" name="import_type" value="audience">
            <input type="hidden" name="connection_id" value="<?= (int) $viewSourceId ?>">
            <input type="hidden" name="return_to" value="dashboard">
            <input class="input" type="file" name="csv" accept=".csv,text/csv" required aria-label="<?= h(t('CSV s publikem')) ?>">
            <button class="btn btn-primary btn-sm" type="submit"><?= h(t('Importovat demografii')) ?></button>
        </form>
    </div>
</details>
