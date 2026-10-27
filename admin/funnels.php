<?php

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../lib/funnels.php';

$pdo = allstat_admin_require_database($pdo, $config);
$user = allstat_require_user($pdo, $config);
// Nastavení trychtýřů je jen pro administrátora i pro čtení; běžný uživatel trychtýře vidí jen v Přehledu (čtení).
allstat_require_admin($user);

$limits = allstat_funnel_limits();
$templates = allstat_funnel_templates();
$breakdowns = allstat_funnel_breakdowns();
$domains = allstat_admin_domains($pdo, false);
$domainIds = array_map(static fn (array $d): int => (int) $d['id'], $domains);
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$customEventValue = '__custom__';

/** Skalár z requestu jako text (pole a objekty dávají prázdný řetězec, ne varování „Array to string“). */
function allstat_funnel_form_text(mixed $value): string
{
    return is_scalar($value) ? (string) $value : '';
}

/**
 * Kroky z formuláře → tvar pro allstat_funnel_save. Event je buď z výběru, nebo „vlastní název“ z textového pole
 * (bez JS jsou vidět obě pole: textové pole platí, jen když ve výběru není nic zvoleno nebo je „Vlastní název“).
 */
function allstat_funnel_form_steps(mixed $raw, string $customValue): array
{
    $steps = [];
    foreach (is_array($raw) ? array_values($raw) : [] as $row) {
        if (!is_array($row)) {
            $steps[] = $row;
            continue;
        }
        $event = trim(allstat_funnel_form_text($row['event'] ?? ''));
        if ($event === '' || $event === $customValue) {
            $event = trim(allstat_funnel_form_text($row['event_custom'] ?? ''));
        }
        $steps[] = ['label' => trim(allstat_funnel_form_text($row['label'] ?? '')), 'event' => $event];
    }

    return $steps;
}

$formState = null;

if ($isPost) {
    allstat_csrf_check();
    allstat_require_admin($user);

    $postDomain = (int) ($_POST['domain_id'] ?? 0);
    if (!in_array($postDomain, $domainIds, true)) {
        allstat_flash('error', t('Web neexistuje nebo není aktivní.'));
        allstat_redirect($config, 'admin/funnels.php');
    }
    allstat_remember_domain_id($postDomain);

    $action = (string) ($_POST['action'] ?? 'save');
    $funnelId = (int) ($_POST['id'] ?? 0);

    if ($action === 'delete') {
        $existing = $funnelId > 0 ? allstat_funnel_get($pdo, $funnelId, $postDomain) : null;
        if ($existing && allstat_funnel_delete($pdo, $funnelId, $postDomain)) {
            allstat_audit($pdo, (int) $user['id'], null, 'funnel_deleted', 'domain_id=' . $postDomain . ', funnel_id=' . $funnelId . ', name=' . $existing['name']);
            allstat_flash('ok', t('Trychtýř „{name}“ byl smazán.', ['name' => $existing['name']]));
        } else {
            allstat_flash('error', t('Trychtýř se nepodařilo smazat, neexistuje nebo patří jinému webu.'));
        }
        allstat_redirect($config, 'admin/funnels.php?domain_id=' . $postDomain);
    }

    $input = [
        'name' => allstat_funnel_form_text($_POST['name'] ?? ''),
        'breakdown' => allstat_funnel_form_text($_POST['breakdown'] ?? 'channel'),
        'is_active' => allstat_funnel_form_text($_POST['is_active'] ?? '1') === '0' ? '0' : '1',
        'steps' => allstat_funnel_form_steps($_POST['steps'] ?? [], $customEventValue),
    ];
    $result = allstat_funnel_save($pdo, $postDomain, $funnelId > 0 ? $funnelId : null, $input);
    if ($result['ok']) {
        allstat_audit($pdo, (int) $user['id'], null, 'funnel_saved', 'domain_id=' . $postDomain . ', funnel_id=' . (int) $result['id'] . ', name=' . $input['name']);
        allstat_flash('ok', t($result['message']));
        allstat_redirect($config, 'admin/funnels.php?domain_id=' . $postDomain);
    }

    // Chyba ve formuláři: bez přesměrování, ať o zadané kroky nepřijdeš (hláška se ukáže hned nahoře).
    allstat_flash('error', t($result['message']));
    $formState = [
        'id' => $funnelId > 0 ? $funnelId : null,
        'name' => $input['name'],
        'breakdown' => $input['breakdown'],
        'is_active' => $input['is_active'] === '1',
        'steps' => array_map(static fn ($s): array => is_array($s) ? ['label' => (string) $s['label'], 'event' => (string) $s['event']] : ['label' => '', 'event' => ''], $input['steps']),
    ];
    $domainId = $postDomain;
} else {
    $domainId = $domains
        ? allstat_current_domain_id($pdo, $domains, filter_input(INPUT_GET, 'domain_id', FILTER_VALIDATE_INT) ?: null)
        : 0;
}

if (!$domains) {
    allstat_admin_header(t('Trychtýře'), 'funnels', $user, $config);
    echo '<div class="callout"><div>' . t('Zatím není žádný aktivní web. Nejdřív ho přidej v části <a href="domains.php">Weby</a>.') . '</div></div>';
    allstat_admin_footer($config);
    exit;
}

$funnels = allstat_funnels_for_domain($pdo, $domainId, false);
$atLimit = count($funnels) >= $limits['max_funnels'];
$mode = $formState !== null ? 'form' : 'list';

if ($mode === 'list' && isset($_GET['edit'])) {
    $editing = allstat_funnel_get($pdo, (int) $_GET['edit'], $domainId);
    if (!$editing) {
        allstat_flash('error', t('Trychtýř neexistuje nebo patří jinému webu.'));
        allstat_redirect($config, 'admin/funnels.php?domain_id=' . $domainId);
    }
    $formState = ['id' => $editing['id'], 'name' => $editing['name'], 'breakdown' => $editing['breakdown'], 'is_active' => $editing['is_active'], 'steps' => $editing['steps']];
    $mode = 'form';
} elseif ($mode === 'list' && isset($_GET['new'])) {
    if ($atLimit) {
        allstat_flash('error', t('Web může mít nejvýše {count} trychtýřů. Nejdřív některý smaž.', ['count' => $limits['max_funnels']]));
        allstat_redirect($config, 'admin/funnels.php?domain_id=' . $domainId);
    }
    $templateKey = (string) ($_GET['template'] ?? '');
    if (isset($templates[$templateKey])) {
        $formState = ['id' => null, 'name' => $templates[$templateKey]['name'], 'breakdown' => 'channel', 'is_active' => true, 'steps' => $templates[$templateKey]['steps']];
        $mode = 'form';
    } elseif ($templateKey === 'blank') {
        $formState = ['id' => null, 'name' => '', 'breakdown' => 'channel', 'is_active' => true, 'steps' => [['label' => '', 'event' => ''], ['label' => '', 'event' => '']]];
        $mode = 'form';
    } else {
        $mode = 'pick';
    }
}

$eventNames = [];
$activityMap = [];
$stepActivity = [];
if ($mode === 'form') {
    $eventNames = allstat_domain_event_names($pdo, $domainId, 90);
    foreach (allstat_domain_event_names($pdo, $domainId, 28) as $row) {
        $activityMap[strtolower($row['event'])] = $row['count'];
    }
    $stepActivity = allstat_funnel_step_activity($pdo, $domainId, array_column($formState['steps'], 'event'), 28);
}

$currentDomain = null;
foreach ($domains as $domain) {
    if ((int) $domain['id'] === $domainId) {
        $currentDomain = $domain;
    }
}

/** Malá inline SVG ikona (bez závislosti na Lucide, protože se řádky kroků přidávají za běhu). */
$svg = static fn (string $paths): string => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
$iconGrip = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg>';
$iconUp = $svg('<path d="m18 15-6-6-6 6"/>');
$iconDown = $svg('<path d="m6 9 6 6 6-6"/>');
$iconRemove = $svg('<path d="M18 6 6 18"/><path d="m6 6 12 12"/>');

/** Jeden řádek editoru kroků ($index = číslo z PHP nebo zástupný __INDEX__ pro šablonu). Bez JS fungují šipky jako skryté. */
$renderStepRow = static function (string $index, array $step, ?int $activity, int $position) use ($eventNames, $customEventValue, $limits, $iconGrip, $iconUp, $iconDown, $iconRemove): string {
    $event = (string) ($step['event'] ?? '');
    $known = false;
    foreach ($eventNames as $row) {
        if ($row['event'] === $event) {
            $known = true;
            break;
        }
    }
    ob_start();
    ?>
    <li class="card funnel-step-row" data-funnel-row>
        <button type="button" class="btn-icon btn-sm funnel-step-handle" data-funnel-handle title="<?= h(t('Přetáhni pro změnu pořadí (nebo použij šipky nahoru a dolů)')) ?>" aria-label="<?= h(t('Přetáhnout krok, pořadí lze měnit i šipkami nahoru a dolů')) ?>"><?= $iconGrip ?></button>
        <span class="tile tile-sm tile-round funnel-step-no" data-funnel-no><?= (int) $position ?></span>
        <label class="field funnel-step-label">
            <span class="field-label funnel-step-caption"><?= h(t('Popisek kroku')) ?></span>
            <input class="input" type="text" name="steps[<?= h($index) ?>][label]" maxlength="<?= (int) $limits['label_length'] ?>" value="<?= h($step['label'] ?? '') ?>" placeholder="<?= h(t('Např. Odeslání formuláře')) ?>">
        </label>
        <div class="funnel-step-event">
            <label class="field">
                <span class="field-label funnel-step-caption">GA4 event</span>
                <select class="input" name="steps[<?= h($index) ?>][event]" data-funnel-event>
                    <option value=""<?= $event === '' ? ' selected' : '' ?>><?= h(t('Vyber event…')) ?></option>
                    <?php foreach ($eventNames as $row): ?>
                        <option value="<?= h($row['event']) ?>"<?= $row['event'] === $event ? ' selected' : '' ?>><?= h($row['event']) ?> (<?= h(t('{count} za 90 dní', ['count' => allstat_number($row['count'])])) ?>)</option>
                    <?php endforeach; ?>
                    <?php if ($event !== '' && !$known): ?>
                        <option value="<?= h($event) ?>" selected><?= h($event) ?> (<?= h(t('za 90 dní nepřišel')) ?>)</option>
                    <?php endif; ?>
                    <option value="<?= h($customEventValue) ?>"><?= h(t('Vlastní název eventu…')) ?></option>
                </select>
            </label>
            <input type="text" class="input funnel-step-custom" name="steps[<?= h($index) ?>][event_custom]" data-funnel-custom maxlength="<?= (int) $limits['event_length'] ?>" pattern="[A-Za-z][A-Za-z0-9_]*" title="<?= h(t('Začíná písmenem, dál jen písmena, číslice a podtržítko')) ?>" placeholder="<?= h(t('nebo napiš vlastní název eventu')) ?>" aria-label="<?= h(t('Vlastní název eventu')) ?>" autocomplete="off">
        </div>
        <span class="funnel-step-activity" data-funnel-activity><?php
        if ($event !== '' && $activity !== null):
            if ($activity > 0): ?><span class="status tone-up"><?= h(t('{count} za 28 dní', ['count' => allstat_number($activity)])) ?></span><?php else: ?><span class="status tone-warn" title="<?= h(t('Event za posledních 28 dní nepřišel, zkontroluj měření (GTM).')) ?>"><?= h(t('za 28 dní nepřišel')) ?></span><?php endif;
        endif; ?></span>
        <div class="funnel-step-controls">
            <button type="button" class="btn-icon btn-sm" data-funnel-up title="<?= h(t('Posunout nahoru')) ?>" aria-label="<?= h(t('Posunout krok nahoru')) ?>"><?= $iconUp ?></button>
            <button type="button" class="btn-icon btn-sm" data-funnel-down title="<?= h(t('Posunout dolů')) ?>" aria-label="<?= h(t('Posunout krok dolů')) ?>"><?= $iconDown ?></button>
            <button type="button" class="btn-icon btn-sm funnel-icon-btn-danger" data-funnel-remove title="<?= h(t('Odebrat krok')) ?>" aria-label="<?= h(t('Odebrat krok')) ?>"><?= $iconRemove ?></button>
        </div>
    </li>
    <?php

    return (string) ob_get_clean();
};

$headActions = '';
if (!$atLimit) {
    $headActions = '<a class="btn btn-primary" href="funnels.php?domain_id=' . (int) $domainId . '&amp;new=1">' . allstat_icon('plus', 'icon-sm') . '<span>' . h(t('Nový trychtýř')) . '</span></a>';
}
allstat_admin_header(t('Trychtýře'), 'funnels', $user, $config, null, [
    'sub' => t('Konverzní cesty z GA4 eventů. Aktivní trychtýře se nabízejí v Přehledu ve výběru „Zdroj dat“, vidí je i běžní uživatelé (jen čtení).'),
    'actions' => $headActions,
]);
?>
<?php if ($mode === 'pick'): ?>
<section class="card">
    <div class="card-head">
        <div>
            <h2 class="card-title"><?= h(t('Nový trychtýř: vyber šablonu')) ?></h2>
            <p class="card-desc"><?= h(t('Šablona jen předvyplní kroky, všechno můžeš ve formuláři upravit. Eventy, které na webu nechodí, se označí varováním.')) ?></p>
        </div>
        <div class="form-actions">
            <a class="btn btn-secondary" href="funnels.php?domain_id=<?= (int) $domainId ?>"><span><?= h(t('Zpět na seznam')) ?></span></a>
        </div>
    </div>
    <div class="card-body">
        <div class="grid grid-cards">
            <?php foreach ($templates as $key => $template): ?>
                <a class="card channel funnel-template" href="funnels.php?domain_id=<?= (int) $domainId ?>&amp;new=1&amp;template=<?= h($key) ?>">
                    <span class="channel-name"><?= h($template['name']) ?></span>
                    <span class="card-desc"><?= h($template['description']) ?></span>
                    <span class="channel-meta"><?= h(implode(' → ', array_column($template['steps'], 'label'))) ?></span>
                </a>
            <?php endforeach; ?>
            <a class="card channel funnel-template" href="funnels.php?domain_id=<?= (int) $domainId ?>&amp;new=1&amp;template=blank">
                <span class="channel-name"><?= h(t('Prázdný trychtýř')) ?></span>
                <span class="card-desc"><?= h(t('Kroky si poskládáš sám z eventů, které na webu chodí.')) ?></span>
                <span class="channel-meta"><?= h(t('Začneš se dvěma prázdnými kroky')) ?></span>
            </a>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($mode === 'form'): $isNew = $formState['id'] === null; ?>
<section class="card">
    <div class="card-head">
        <div>
            <h2 class="card-title"><?= h($isNew ? t('Nový trychtýř') : t('Upravit trychtýř')) ?> <small>(<?= h($currentDomain['url'] ?? '') ?>)</small></h2>
            <p class="card-desc"><?= h(t('Trychtýř je řada GA4 eventů od vstupu až po cíl. Počty kroků se berou z denních součtů eventů, takže fungují hned i zpětně.')) ?></p>
        </div>
        <div class="form-actions">
            <?php if (!$isNew): ?><a class="btn btn-secondary" href="../?view=funnel&amp;funnel_id=<?= (int) $formState['id'] ?>&amp;domain_id=<?= (int) $domainId ?>"><span><?= h(t('Zobrazit v přehledu')) ?></span></a><?php endif; ?>
            <a class="btn btn-secondary" href="funnels.php?domain_id=<?= (int) $domainId ?>"><span><?= h(t('Zpět na seznam')) ?></span></a>
        </div>
    </div>
    <div class="card-body">
        <form method="post" class="funnel-form section" data-funnel-form>
            <?= allstat_csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="domain_id" value="<?= (int) $domainId ?>">
            <input type="hidden" name="id" value="<?= (int) ($formState['id'] ?? 0) ?>">
            <div class="form-grid">
                <label class="field"><span class="field-label"><?= h(t('Název')) ?></span><input class="input" type="text" name="name" required maxlength="<?= (int) $limits['name_length'] ?>" value="<?= h($formState['name']) ?>" placeholder="<?= h(t('Např. Poptávkový formulář')) ?>"></label>
                <label class="field">
                    <span class="field-label"><?= h(t('Rozpad podle')) ?></span>
                    <select class="input" name="breakdown">
                        <?php foreach ($breakdowns as $key => $label): ?><option value="<?= h($key) ?>"<?= $formState['breakdown'] === $key ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                    </select>
                    <span class="field-help"><?= h(t('Výchozí rozpad v přehledu, přepnout ho tam jde i později.')) ?></span>
                </label>
                <label class="field">
                    <span class="field-label"><?= h(t('Stav')) ?></span>
                    <select class="input" name="is_active">
                        <option value="1"<?= $formState['is_active'] ? ' selected' : '' ?>><?= h(t('Aktivní')) ?></option>
                        <option value="0"<?= !$formState['is_active'] ? ' selected' : '' ?>><?= h(t('Vypnutý')) ?></option>
                    </select>
                    <span class="field-help"><?= h(t('Jen aktivní trychtýř je v přehledu v nabídce a GA4 sync pro něj stahuje rozpad.')) ?></span>
                </label>
            </div>

            <div class="funnel-editor section" data-funnel-editor data-min="<?= (int) $limits['min_steps'] ?>" data-max="<?= (int) $limits['max_steps'] ?>" data-activity="<?= h(allstat_json($activityMap)) ?>">
                <div class="funnel-editor-head">
                    <h3 class="block-title"><?= h(t('Kroky trychtýře')) ?></h3>
                    <p class="field-help"><?= h(t('Pořadí shora dolů je pořadí v trychtýři. Změníš ho přetažením úchytu vlevo nebo šipkami. Kroků může být {min} až {max}.', ['min' => (int) $limits['min_steps'], 'max' => (int) $limits['max_steps']])) ?></p>
                </div>
                <ol class="funnel-steps" data-funnel-steps>
                    <?php foreach (array_values($formState['steps']) as $i => $step): ?>
                        <?= $renderStepRow((string) $i, $step, ($step['event'] ?? '') !== '' ? ($stepActivity[$step['event']] ?? 0) : null, $i + 1) ?>
                    <?php endforeach; ?>
                </ol>
                <template data-funnel-template><?= $renderStepRow('__INDEX__', ['label' => '', 'event' => ''], null, 0) ?></template>
                <div class="form-actions funnel-editor-foot">
                    <button type="button" class="btn btn-secondary btn-sm" data-funnel-add><?= allstat_icon('plus', 'icon-sm') ?><span><?= h(t('Přidat krok')) ?></span></button>
                    <span class="field-help" data-funnel-count></span>
                </div>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit"><?= h(t('Uložit trychtýř')) ?></button>
                <a class="btn btn-secondary" href="funnels.php?domain_id=<?= (int) $domainId ?>"><?= h(t('Zrušit')) ?></a>
            </div>
        </form>
    </div>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="funnels-title">
    <div class="card-head">
        <div>
            <h2 class="card-title" id="funnels-title"><?= h(t('Trychtýře webu')) ?></h2>
        </div>
    </div>
    <div class="filterbar">
        <form method="get">
            <label class="sr-only" for="funnels-domain"><?= h(t('Web')) ?></label>
            <select class="input" id="funnels-domain" name="domain_id" data-autosubmit aria-label="<?= h(t('Web')) ?>">
                <?php foreach ($domains as $domain): ?><option value="<?= (int) $domain['id'] ?>"<?= (int) $domain['id'] === $domainId ? ' selected' : '' ?>><?= h($domain['url']) ?></option><?php endforeach; ?>
            </select>
        </form>
    </div>
    <?php if ($funnels): ?>
    <div class="table-wrap">
        <table class="table table-data">
            <colgroup><col class="col-lg"><col><col class="col-md"><col class="col-sm"><col class="col-sm"></colgroup>
            <thead><tr><th scope="col"><?= h(t('Název')) ?></th><th scope="col"><?= h(t('Kroky')) ?></th><th scope="col"><?= h(t('Rozpad')) ?></th><th scope="col"><?= h(t('Stav')) ?></th><th scope="col" data-no-sort><span class="sr-only"><?= h(t('Akce')) ?></span></th></tr></thead>
            <tbody>
            <?php foreach ($funnels as $funnel): $fid = (int) $funnel['id']; ?>
                <tr>
                    <td class="ellipsis" title="<?= h($funnel['name']) ?>"><span class="name-main"><?= h($funnel['name']) ?></span></td>
                    <td class="is-wrap">
                        <div class="name-stack">
                            <span class="is-muted"><?= h(tn(count($funnel['steps']), '{count} krok', '{count} kroky', '{count} kroků')) ?></span>
                            <span class="funnel-list-steps" title="<?= h(implode(' → ', array_column($funnel['steps'], 'event'))) ?>"><?= h(implode(' → ', array_column($funnel['steps'], 'label'))) ?></span>
                        </div>
                    </td>
                    <td class="ellipsis"><?= h($breakdowns[$funnel['breakdown']] ?? $funnel['breakdown']) ?></td>
                    <td><?= $funnel['is_active'] ? allstat_status_pill('ok', t('Aktivní')) : allstat_status_pill('off', t('Vypnutý')) ?></td>
                    <td>
                        <div class="cell-actions dropdown">
                            <a class="btn-icon btn-sm" href="funnels.php?domain_id=<?= (int) $domainId ?>&amp;edit=<?= $fid ?>" title="<?= h(t('Upravit')) ?>" aria-label="<?= h(t('Upravit')) ?>: <?= h($funnel['name']) ?>"><?= allstat_icon('pencil', 'icon-sm') ?></a>
                            <button type="button" class="btn-icon btn-sm" aria-label="<?= h(t('Další akce pro {name}', ['name' => $funnel['name']])) ?>" aria-haspopup="true" aria-expanded="false" data-menu-toggle="funnel-menu-<?= $fid ?>"><?= allstat_icon('ellipsis', 'icon-sm') ?></button>
                            <div class="menu menu-end" id="funnel-menu-<?= $fid ?>" hidden>
                                <a class="menu-item" href="../?view=funnel&amp;funnel_id=<?= $fid ?>&amp;domain_id=<?= (int) $domainId ?>"><?= allstat_icon('line-chart', 'icon-sm') ?><span><?= h(t('Zobrazit v přehledu')) ?></span></a>
                                <div class="menu-sep"></div>
                                <form method="post" data-confirm="<?= h(t('Smazat trychtýř „{name}“? Nastavení se nedá vrátit, naměřená data zůstanou.', ['name' => $funnel['name']])) ?>">
                                    <?= allstat_csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="domain_id" value="<?= (int) $domainId ?>">
                                    <input type="hidden" name="id" value="<?= $fid ?>">
                                    <button class="menu-item is-danger" type="submit"><?= allstat_icon('trash-2', 'icon-sm') ?><span><?= h(t('Smazat')) ?></span></button>
                                </form>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty">
        <span class="empty-desc"><?= h(t('Tento web zatím nemá žádný trychtýř. Klikni na „Nový trychtýř“ a vyber šablonu, třeba poptávkový formulář.')) ?></span>
    </div>
    <?php endif; ?>
    <?php if ($atLimit): ?><div class="card-foot"><?= h(t('Web má maximální počet trychtýřů ({count}). Nový přidáš po smazání některého ze stávajících.', ['count' => (int) $limits['max_funnels']])) ?></div><?php endif; ?>
</section>

<details class="card note">
    <summary><?= allstat_icon('info', 'icon') ?><span><?= h(t('Jak nastavit měření')) ?></span><?= allstat_icon('chevron-down', 'note-chev') ?></summary>
    <div class="note-body">
        <p><?= h(t('Trychtýř je jen tak dobrý, jak dobře se měří jeho kroky.')) ?></p>
        <p><?= t('<strong>Kroky jsou GA4 eventy.</strong> Event musí chodit do Google Analytics 4, typicky přes Google Tag Manager. Názvy piš malými písmeny s podtržítky, například <code>form_submit</code>. U každého kroku vidíš, kolikrát event za posledních 28 dní přišel; když nepřišel vůbec, zkontroluj měření.') ?></p>
        <p><?= t('<strong>Rozpad podle zdroje se plní od dalšího syncu GA4.</strong> Počty kroků fungují hned i zpětně, ale rozpad podle kanálu, zdroje a média nebo kampaně se začne ukládat až při příští synchronizaci GA4 po vytvoření trychtýře. Starší období doplníš přes „Stáhnout historii“ u zdroje GA4 (Zdroje dat, úprava napojení).') ?></p>
        <p><?= t('<strong>Obchodní krok z CRM přidáš jako event.</strong> Když chceš na konec trychtýře krok, který se děje mimo web (například podepsaná smlouva), pošli ho z CRM do GA4 jako event přes Measurement Protocol a pak ho vyber jako poslední krok.') ?></p>
        <p><?= t('<strong>Čísla jsou počty událostí, ne lidí.</strong> Jeden návštěvník může event vyvolat vícekrát, proto může být pozdější krok vyšší než předchozí. Takový případ trychtýř označí upozorněním.') ?></p>
    </div>
</details>
<?php allstat_admin_footer($config); ?>
