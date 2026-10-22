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
    echo '<div class="admin-card"><div class="admin-card-header"><div><h2>' . h(t('Zatím nemáte přidělený web')) . '</h2>'
        . '<p>' . h(t('Účet je aktivní, ale administrátor vám zatím nepřidělil žádný web, nebo jsou přidělené weby vypnuté. Požádejte ho o přístup.')) . '</p>'
        . '</div></div></div>';
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
$fmtChange = static function (?float $change): string {
    if ($change === null) {
        return '<span class="site-change">' . h(t('nové')) . '</span>';
    }
    $class = $change > 0.5 ? 'site-change-up' : ($change < -0.5 ? 'site-change-down' : '');

    return '<span class="site-change ' . $class . '">' . ($change > 0 ? '+' : '') . h(allstat_number($change, 1)) . ' %</span>';
};
$fmtValue = static fn (float $value): string => allstat_number($value);

allstat_admin_header(t('Všechny weby'), 'sites', $user, $config);
?>
<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2><?= h(t('Všechny weby')) ?></h2>
            <p><?= h(t('Google Analytics a Search Console za období {start} až {end}, srovnání s obdobím {prev_start} až {prev_end}. Data jsou z poslední synchronizace.', ['start' => $fmtDate($overview['start']), 'end' => $fmtDate($overview['end']), 'prev_start' => $fmtDate($overview['previous']['start']), 'prev_end' => $fmtDate($overview['previous']['end'])])) ?></p>
        </div>
    </div>
    <div class="admin-card-body">
        <div class="sites-toolbar">
            <nav class="quick-ranges" aria-label="<?= h(t('Období')) ?>">
                <?php foreach ($periods as $key => $period): ?>
                    <a class="chip<?= $key === $periodKey ? ' is-active' : '' ?>" href="<?= h(allstat_url($config, 'admin/sites.php') . '?' . http_build_query(array_filter(['p' => $key, 'sort' => $sort, 'account' => $accountFilter], 'strlen'))) ?>"><?= h(t($period['label'])) ?></a>
                <?php endforeach; ?>
            </nav>
            <form method="get" class="toolbar">
                <input type="hidden" name="p" value="<?= h($periodKey) ?>">
                <label class="filter-control"><span><?= h(t('Řadit')) ?></span>
                    <select name="sort" data-autosubmit>
                        <option value="visits"<?= $sort === 'visits' ? ' selected' : '' ?>><?= h(t('nejvíc návštěv')) ?></option>
                        <option value="change"<?= $sort === 'change' ? ' selected' : '' ?>><?= h(t('největší pokles')) ?></option>
                        <option value="name"<?= $sort === 'name' ? ' selected' : '' ?>><?= h(t('podle názvu')) ?></option>
                    </select>
                </label>
                <?php if ($accounts): ?>
                <label class="filter-control"><span><?= h(t('Google účet')) ?></span>
                    <select name="account" data-autosubmit>
                        <option value=""><?= h(t('všechny')) ?></option>
                        <?php foreach ($accounts as $label): ?>
                            <option value="<?= h($label) ?>"<?= $label === $accountFilter ? ' selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php endif; ?>
            </form>
            <div class="toolbar">
                <label class="filter-control"><span><?= h(t('Hledat')) ?></span><input type="search" data-table-filter="sites-grid" placeholder="<?= h(t('název nebo doména')) ?>"></label>
            </div>
        </div>

        <div class="sites-totals">
            <?php foreach ($metrics as $metric => $label): ?>
                <div class="site-total">
                    <span class="site-total-label"><?= h(t('{label} celkem', ['label' => t($label)])) ?></span>
                    <strong><?= h($fmtValue($overview['totals']['current'][$metric])) ?></strong>
                    <?= $fmtChange($overview['totals']['change'][$metric]) ?>
                </div>
            <?php endforeach; ?>
            <div class="site-total">
                <span class="site-total-label"><?= h(t('Weby')) ?></span>
                <strong><?= count($sites) ?></strong>
            </div>
        </div>
    </div>
</div>

<?php if (!$sites): ?>
<div class="admin-card"><div class="admin-card-body"><p class="table-muted"><?= h(t('Žádné weby k zobrazení.')) ?><?= ($user['role'] ?? '') === 'admin' ? ' ' . h(t('Přidejte je ve Webech nebo přes Google účty.')) : '' ?></p></div></div>
<?php endif; ?>

<div class="sites-grid" id="sites-grid">
    <?php foreach ($sites as $site):
        $dashboard = allstat_url($config, 'index.php') . '?' . http_build_query(['view' => 'overview', 'domain_id' => $site['id'], 'start' => $overview['start'], 'end' => $overview['end']]);
        $lagging = $site['has_ga4'] && $site['last_date'] !== null && $site['last_date'] < (new DateTimeImmutable($overview['end']))->modify('-1 day')->format('Y-m-d');
    ?>
    <article class="site-card" data-filter-text="<?= h(mb_strtolower($site['name'] . ' ' . $site['url'] . ' ' . implode(' ', $site['accounts']))) ?>">
        <header class="site-card-head">
            <a href="<?= h($dashboard) ?>"><strong><?= h($site['name']) ?></strong></a>
            <span class="table-muted"><?= h($site['url']) ?></span>
            <?php foreach ($site['accounts'] as $label): ?><span class="site-account"><?= h($label) ?></span><?php endforeach; ?>
        </header>
        <dl class="site-metrics">
            <?php foreach ($metrics as $metric => $label): ?>
            <div>
                <dt><?= h(t($label)) ?></dt>
                <?php if ($metric === 'clicks' && !$site['has_gsc']): ?>
                <dd class="table-muted"><?= h(t('bez Search Console')) ?></dd>
                <?php else: ?>
                <dd><strong><?= h($fmtValue($site['current'][$metric])) ?></strong> <?= $site['current'][$metric] > 0 || $site['previous'][$metric] > 0 ? $fmtChange($site['change'][$metric]) : '' ?></dd>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </dl>
        <?= allstat_sites_sparkline($site['series']) ?>
        <?php if (!$site['has_ga4']): ?>
            <p class="site-note"><?= h(t('Google Analytics není napojené.')) ?></p>
        <?php elseif ($site['last_date'] === null): ?>
            <p class="site-note"><?= h(t('Za toto období zatím nejsou stažená data.')) ?></p>
        <?php elseif ($lagging): ?>
            <p class="site-note"><?= h(t('Data jen do {date}.', ['date' => $fmtDate($site['last_date'])])) ?></p>
        <?php endif; ?>
    </article>
    <?php endforeach; ?>
</div>
<?php allstat_admin_footer($config); ?>
