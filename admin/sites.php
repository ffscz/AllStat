<?php

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../lib/sites.php';

$pdo = allstat_admin_require_database($pdo, $config);
$user = allstat_require_user($pdo, $config);

$periods = allstat_sites_periods();
$periodKey = is_string($_GET['p'] ?? null) && isset($periods[$_GET['p']]) ? $_GET['p'] : '30d';
$sort = is_string($_GET['sort'] ?? null) && in_array($_GET['sort'], ['visits', 'change', 'name'], true) ? $_GET['sort'] : 'visits';
$accountFilter = is_string($_GET['account'] ?? null) ? trim($_GET['account']) : '';

$domains = allstat_get_domains($pdo);
if (!$domains && allstat_domain_scope() !== null) {
    http_response_code(403);
    allstat_admin_header(t('Všechny weby'), 'sites', $user, $config);
    echo '<div class="card"><div class="empty"><span class="empty-title">' . h(t('Zatím nemáte přidělený web')) . '</span>'
        . '<span class="empty-desc">' . h(t('Účet je aktivní, ale administrátor vám zatím nepřidělil žádný web, nebo jsou přidělené weby vypnuté. Požádejte ho o přístup.')) . '</span>'
        . '</div></div>';
    allstat_admin_footer($config);
    exit;
}

// Filtr podle Google účtu se použije už na weby, takže součty odpovídají zobrazeným kartám.
$accountsById = allstat_sites_google_accounts($pdo, array_map(static fn (array $d): int => (int) $d['id'], $domains));
$accounts = array_values(array_unique(array_merge([], ...array_values($accountsById))));
sort($accounts, SORT_NATURAL | SORT_FLAG_CASE);
if ($accountFilter !== '' && in_array($accountFilter, $accounts, true)) {
    $domains = array_values(array_filter($domains, static fn (array $d): bool => in_array($accountFilter, $accountsById[(int) $d['id']] ?? [], true)));
} else {
    $accountFilter = '';
}
$overview = allstat_sites_overview($pdo, $domains, $periods[$periodKey]['start'], $periods[$periodKey]['end']);
$sites = allstat_sites_sort($overview['sites'], $sort);

// Tržby a kliknutí z Googlu jen tehdy, když je má aspoň jeden web (jinak by všude svítila nula).
$metrics = ALLSTAT_SITES_METRICS;
foreach (['revenue', 'clicks'] as $optional) {
    if ($overview['totals']['current'][$optional] <= 0 && $overview['totals']['previous'][$optional] <= 0) {
        unset($metrics[$optional]);
    }
}

$fmtDate = static fn (string $date): string => allstat_date_label($date);
// Změna proti předchozímu období: štítek (souhrn, hlavní číslo karty) nebo jen barevný text (malé údaje).
$fmtChange = static function (?float $change, bool $badge = true): string {
    if ($change === null) {
        return $badge ? '<span class="badge tone-flat">' . h(t('nové')) . '</span>' : '<span class="is-muted">' . h(t('nové')) . '</span>';
    }
    $text = ($change > 0 ? '+' : '') . h(allstat_number($change, 1)) . ' %';
    if ($badge) {
        $tone = $change > 0.5 ? 'tone-up' : ($change < -0.5 ? 'tone-down' : 'tone-flat');

        return '<span class="badge ' . $tone . '">' . $text . '</span>';
    }
    $class = $change > 0.5 ? 'is-up' : ($change < -0.5 ? 'is-down' : 'is-muted');

    return '<span class="' . $class . '">' . $text . '</span>';
};
$fmtValue = static fn (float $value): string => allstat_number($value);
$sitesUrl = allstat_url($config, 'admin/sites.php');

allstat_admin_header(t('Všechny weby'), 'sites', $user, $config, null, [
    'sub' => t('Google Analytics a Search Console za období {start} až {end}, srovnání s obdobím {prev_start} až {prev_end}. Data jsou z poslední synchronizace.', ['start' => $fmtDate($overview['start']), 'end' => $fmtDate($overview['end']), 'prev_start' => $fmtDate($overview['previous']['start']), 'prev_end' => $fmtDate($overview['previous']['end'])]),
]);
?>
<section class="card">
    <div class="filterbar">
        <nav class="seg" aria-label="<?= h(t('Období')) ?>">
            <?php foreach ($periods as $key => $period): ?>
                <a class="seg-item"<?= $key === $periodKey ? ' aria-current="page"' : '' ?> href="<?= h($sitesUrl . '?' . http_build_query(array_filter(['p' => $key, 'sort' => $sort, 'account' => $accountFilter], 'strlen'))) ?>"><?= h(t($period['label'])) ?></a>
            <?php endforeach; ?>
        </nav>
        <form method="get" class="form-actions">
            <input type="hidden" name="p" value="<?= h($periodKey) ?>">
            <label><span class="sr-only"><?= h(t('Řadit')) ?></span>
                <select class="input" name="sort" data-autosubmit>
                    <option value="visits"<?= $sort === 'visits' ? ' selected' : '' ?>><?= h(t('nejvíc návštěv')) ?></option>
                    <option value="change"<?= $sort === 'change' ? ' selected' : '' ?>><?= h(t('největší pokles')) ?></option>
                    <option value="name"<?= $sort === 'name' ? ' selected' : '' ?>><?= h(t('podle názvu')) ?></option>
                </select>
            </label>
            <?php if ($accounts): ?>
            <label class="form-actions"><span class="is-muted"><?= h(t('Google účet')) ?></span>
                <select class="input" name="account" data-autosubmit>
                    <option value=""><?= h(t('všechny')) ?></option>
                    <?php foreach ($accounts as $label): ?>
                        <option value="<?= h($label) ?>"<?= $label === $accountFilter ? ' selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>
        </form>
        <label class="search">
            <span class="sr-only"><?= h(t('Hledat')) ?></span>
            <?= allstat_icon('search', 'icon-sm') ?>
            <input class="input" type="search" data-table-filter="sites-grid" placeholder="<?= h(t('název nebo doména')) ?>">
        </label>
    </div>
</section>

<div class="grid grid-kpis">
    <?php foreach ($metrics as $metric => $label): ?>
        <div class="card kpi">
            <span class="metric-label"><?= h(t('{label} celkem', ['label' => t($label)])) ?></span>
            <span class="metric-value num"><?= h($fmtValue($overview['totals']['current'][$metric])) ?></span>
            <span class="metric-note"><?= $fmtChange($overview['totals']['change'][$metric]) ?></span>
        </div>
    <?php endforeach; ?>
    <div class="card kpi">
        <span class="metric-label"><?= h(t('Weby')) ?></span>
        <span class="metric-value num"><?= count($sites) ?></span>
    </div>
</div>

<?php if (!$sites): ?>
<div class="card"><div class="empty"><span class="empty-desc"><?= h(t('Žádné weby k zobrazení.')) ?><?= ($user['role'] ?? '') === 'admin' ? ' ' . h(t('Přidejte je ve Webech nebo přes Google účty.')) : '' ?></span></div></div>
<?php endif; ?>

<div class="grid grid-cards" id="sites-grid">
    <?php foreach ($sites as $site):
        $dashboard = allstat_url($config, 'index.php') . '?' . http_build_query(['view' => 'overview', 'domain_id' => $site['id'], 'start' => $overview['start'], 'end' => $overview['end']]);
        $lagging = $site['has_ga4'] && $site['last_date'] !== null && $site['last_date'] < (new DateTimeImmutable($overview['end']))->modify('-1 day')->format('Y-m-d');
        $mainMetric = array_key_first($metrics);
        $otherMetrics = array_slice($metrics, 1, null, true);
    ?>
    <article class="card channel" data-filter-text="<?= h(mb_strtolower($site['name'] . ' ' . $site['url'] . ' ' . implode(' ', $site['accounts']))) ?>">
        <header class="channel-head">
            <span class="tile tile-md" aria-hidden="true"><?= h(allstat_site_initial($site)) ?></span>
            <div class="name-stack">
                <a class="channel-link channel-name ellipsis" href="<?= h($dashboard) ?>"><?= h($site['name']) ?></a>
                <span class="name-sub ellipsis"><?= h($site['url']) ?></span>
            </div>
        </header>
        <?php if ($site['accounts']): ?>
        <div class="channel-meta">
            <span class="legend"><?php foreach ($site['accounts'] as $label): ?><span class="chip"><?= h($label) ?></span><?php endforeach; ?></span>
        </div>
        <?php endif; ?>
        <div class="channel-meta"><span><?= h(t($metrics[$mainMetric])) ?></span></div>
        <div class="channel-value">
            <span class="metric-value num"><?= h($fmtValue($site['current'][$mainMetric])) ?></span>
            <?= $site['current'][$mainMetric] > 0 || $site['previous'][$mainMetric] > 0 ? $fmtChange($site['change'][$mainMetric]) : '' ?>
        </div>
        <?= allstat_sites_sparkline($site['series']) ?>
        <?php if ($otherMetrics): ?>
        <div class="stats">
            <?php foreach ($otherMetrics as $metric => $label): ?>
            <div>
                <span class="stat-label"><?= h(t($label)) ?></span>
                <?php if ($metric === 'clicks' && !$site['has_gsc']): ?>
                <span class="stat-value is-muted"><?= h(t('bez Search Console')) ?></span>
                <?php else: ?>
                <span class="stat-value num"><?= h($fmtValue($site['current'][$metric])) ?><?= $site['current'][$metric] > 0 || $site['previous'][$metric] > 0 ? ' <small>' . $fmtChange($site['change'][$metric], false) . '</small>' : '' ?></span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if (!$site['has_ga4']): ?>
            <p class="metric-note"><?= h(t('Google Analytics není napojené.')) ?></p>
        <?php elseif ($site['last_date'] === null): ?>
            <p class="metric-note"><?= h(t('Za toto období zatím nejsou stažená data.')) ?></p>
        <?php elseif ($lagging): ?>
            <p class="metric-note"><?= h(t('Data jen do {date}.', ['date' => $fmtDate($site['last_date'])])) ?></p>
        <?php endif; ?>
    </article>
    <?php endforeach; ?>
</div>
<?php allstat_admin_footer($config); ?>
