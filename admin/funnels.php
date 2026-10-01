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
        allstat_flash('error', 'Web neexistuje nebo není aktivní.');
        allstat_redirect($config, 'admin/funnels.php');
    }
    allstat_remember_domain_id($postDomain);

    $action = (string) ($_POST['action'] ?? 'save');
    $funnelId = (int) ($_POST['id'] ?? 0);

    if ($action === 'delete') {
        $existing = $funnelId > 0 ? allstat_funnel_get($pdo, $funnelId, $postDomain) : null;
        if ($existing && allstat_funnel_delete($pdo, $funnelId, $postDomain)) {
            allstat_audit($pdo, (int) $user['id'], null, 'funnel_deleted', 'domain_id=' . $postDomain . ', funnel_id=' . $funnelId . ', name=' . $existing['name']);
            allstat_flash('ok', 'Trychtýř „' . $existing['name'] . '“ byl smazán.');
        } else {
            allstat_flash('error', 'Trychtýř se nepodařilo smazat, neexistuje nebo patří jinému webu.');
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
        allstat_flash('ok', $result['message']);
        allstat_redirect($config, 'admin/funnels.php?domain_id=' . $postDomain);
    }

    // Chyba ve formuláři: bez přesměrování, ať o zadané kroky nepřijdeš (hláška se ukáže hned nahoře).
    allstat_flash('error', $result['message']);
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
    allstat_admin_header('Trychtýře', 'funnels', $user, $config);
    echo '<div class="admin-card"><div class="admin-card-body"><p class="table-muted">Zatím není žádný aktivní web. Nejdřív ho přidej v části <a href="domains.php">Weby</a>.</p></div></div>';
    allstat_admin_footer($config);
    exit;
}

$funnels = allstat_funnels_for_domain($pdo, $domainId, false);
$atLimit = count($funnels) >= $limits['max_funnels'];
$mode = $formState !== null ? 'form' : 'list';

if ($mode === 'list' && isset($_GET['edit'])) {
    $editing = allstat_funnel_get($pdo, (int) $_GET['edit'], $domainId);
    if (!$editing) {
        allstat_flash('error', 'Trychtýř neexistuje nebo patří jinému webu.');
        allstat_redirect($config, 'admin/funnels.php?domain_id=' . $domainId);
    }
    $formState = ['id' => $editing['id'], 'name' => $editing['name'], 'breakdown' => $editing['breakdown'], 'is_active' => $editing['is_active'], 'steps' => $editing['steps']];
    $mode = 'form';
} elseif ($mode === 'list' && isset($_GET['new'])) {
    if ($atLimit) {
        allstat_flash('error', 'Web může mít nejvýše ' . $limits['max_funnels'] . ' trychtýřů. Nejdřív některý smaž.');
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
    <li class="funnel-step-row" data-funnel-row>
        <button type="button" class="funnel-step-handle" data-funnel-handle title="Přetáhni pro změnu pořadí (nebo použij šipky nahoru a dolů)" aria-label="Přetáhnout krok, pořadí lze měnit i šipkami nahoru a dolů"><?= $iconGrip ?></button>
        <span class="funnel-step-no" data-funnel-no><?= (int) $position ?></span>
        <label class="funnel-step-label">
            <span class="funnel-step-caption">Popisek kroku</span>
            <input type="text" name="steps[<?= h($index) ?>][label]" maxlength="<?= (int) $limits['label_length'] ?>" value="<?= h($step['label'] ?? '') ?>" placeholder="Např. Odeslání formuláře">
        </label>
        <div class="funnel-step-event">
            <label>
                <span class="funnel-step-caption">GA4 event</span>
                <select name="steps[<?= h($index) ?>][event]" data-funnel-event>
                    <option value=""<?= $event === '' ? ' selected' : '' ?>>Vyber event…</option>
                    <?php foreach ($eventNames as $row): ?>
                        <option value="<?= h($row['event']) ?>"<?= $row['event'] === $event ? ' selected' : '' ?>><?= h($row['event']) ?> (<?= h(allstat_number($row['count'])) ?> za 90 dní)</option>
                    <?php endforeach; ?>
                    <?php if ($event !== '' && !$known): ?>
                        <option value="<?= h($event) ?>" selected><?= h($event) ?> (za 90 dní nepřišel)</option>
                    <?php endif; ?>
                    <option value="<?= h($customEventValue) ?>">Vlastní název eventu…</option>
                </select>
            </label>
            <input type="text" class="funnel-step-custom" name="steps[<?= h($index) ?>][event_custom]" data-funnel-custom maxlength="<?= (int) $limits['event_length'] ?>" pattern="[A-Za-z][A-Za-z0-9_]*" title="Začíná písmenem, dál jen písmena, číslice a podtržítko" placeholder="nebo napiš vlastní název eventu" aria-label="Vlastní název eventu" autocomplete="off">
        </div>
        <span class="funnel-step-activity" data-funnel-activity><?php
        if ($event !== '' && $activity !== null):
            if ($activity > 0): ?><span class="status-badge status-ok"><?= h(allstat_number($activity)) ?> za 28 dní</span><?php else: ?><span class="status-badge status-warning" title="Event za posledních 28 dní nepřišel, zkontroluj měření (GTM).">za 28 dní nepřišel</span><?php endif;
        endif; ?></span>
        <div class="funnel-step-controls">
            <button type="button" class="funnel-icon-btn" data-funnel-up title="Posunout nahoru" aria-label="Posunout krok nahoru"><?= $iconUp ?></button>
            <button type="button" class="funnel-icon-btn" data-funnel-down title="Posunout dolů" aria-label="Posunout krok dolů"><?= $iconDown ?></button>
            <button type="button" class="funnel-icon-btn funnel-icon-btn-danger" data-funnel-remove title="Odebrat krok" aria-label="Odebrat krok"><?= $iconRemove ?></button>
        </div>
    </li>
    <?php

    return (string) ob_get_clean();
};

allstat_admin_header('Trychtýře', 'funnels', $user, $config);
?>
<?php if ($mode === 'pick'): ?>
<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2>Nový trychtýř: vyber šablonu</h2>
            <p>Šablona jen předvyplní kroky, všechno můžeš ve formuláři upravit. Eventy, které na webu nechodí, se označí varováním.</p>
        </div>
        <a class="button-secondary" href="funnels.php?domain_id=<?= (int) $domainId ?>">Zpět na seznam</a>
    </div>
    <div class="admin-card-body">
        <div class="funnel-templates">
            <?php foreach ($templates as $key => $template): ?>
                <a class="funnel-template" href="funnels.php?domain_id=<?= (int) $domainId ?>&amp;new=1&amp;template=<?= h($key) ?>">
                    <strong><?= h($template['name']) ?></strong>
                    <span><?= h($template['description']) ?></span>
                    <small><?= h(implode(' → ', array_column($template['steps'], 'label'))) ?></small>
                </a>
            <?php endforeach; ?>
            <a class="funnel-template" href="funnels.php?domain_id=<?= (int) $domainId ?>&amp;new=1&amp;template=blank">
                <strong>Prázdný trychtýř</strong>
                <span>Kroky si poskládáš sám z eventů, které na webu chodí.</span>
                <small>Začneš se dvěma prázdnými kroky</small>
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($mode === 'form'): $isNew = $formState['id'] === null; ?>
<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2><?= $isNew ? 'Nový trychtýř' : 'Upravit trychtýř' ?> <span class="table-muted">(<?= h($currentDomain['url'] ?? '') ?>)</span></h2>
            <p>Trychtýř je řada GA4 eventů od vstupu až po cíl. Počty kroků se berou z denních součtů eventů, takže fungují hned i zpětně.</p>
        </div>
        <div class="card-header-actions">
            <?php if (!$isNew): ?><a class="button-secondary" href="../?view=funnel&amp;funnel_id=<?= (int) $formState['id'] ?>&amp;domain_id=<?= (int) $domainId ?>">Zobrazit v přehledu</a><?php endif; ?>
            <a class="button-secondary" href="funnels.php?domain_id=<?= (int) $domainId ?>">Zpět na seznam</a>
        </div>
    </div>
    <div class="admin-card-body">
        <form method="post" class="funnel-form" data-funnel-form>
            <?= allstat_csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="domain_id" value="<?= (int) $domainId ?>">
            <input type="hidden" name="id" value="<?= (int) ($formState['id'] ?? 0) ?>">
            <div class="form-grid form-grid-3">
                <label><span>Název</span><input type="text" name="name" required maxlength="<?= (int) $limits['name_length'] ?>" value="<?= h($formState['name']) ?>" placeholder="Např. Poptávkový formulář"></label>
                <label>
                    <span>Rozpad podle</span>
                    <select name="breakdown">
                        <?php foreach ($breakdowns as $key => $label): ?><option value="<?= h($key) ?>"<?= $formState['breakdown'] === $key ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                    </select>
                    <span class="field-help">Výchozí rozpad v přehledu, přepnout ho tam jde i později.</span>
                </label>
                <label>
                    <span>Stav</span>
                    <select name="is_active">
                        <option value="1"<?= $formState['is_active'] ? ' selected' : '' ?>>Aktivní</option>
                        <option value="0"<?= !$formState['is_active'] ? ' selected' : '' ?>>Vypnutý</option>
                    </select>
                    <span class="field-help">Jen aktivní trychtýř je v přehledu v nabídce a GA4 sync pro něj stahuje rozpad.</span>
                </label>
            </div>

            <div class="funnel-editor" data-funnel-editor data-min="<?= (int) $limits['min_steps'] ?>" data-max="<?= (int) $limits['max_steps'] ?>" data-activity="<?= h(allstat_json($activityMap)) ?>">
                <div class="funnel-editor-head">
                    <h3>Kroky trychtýře</h3>
                    <p class="field-help">Pořadí shora dolů je pořadí v trychtýři. Změníš ho přetažením úchytu vlevo nebo šipkami. Kroků může být <?= (int) $limits['min_steps'] ?> až <?= (int) $limits['max_steps'] ?>.</p>
                </div>
                <ol class="funnel-steps" data-funnel-steps>
                    <?php foreach (array_values($formState['steps']) as $i => $step): ?>
                        <?= $renderStepRow((string) $i, $step, ($step['event'] ?? '') !== '' ? ($stepActivity[$step['event']] ?? 0) : null, $i + 1) ?>
                    <?php endforeach; ?>
                </ol>
                <template data-funnel-template><?= $renderStepRow('__INDEX__', ['label' => '', 'event' => ''], null, 0) ?></template>
                <div class="funnel-editor-foot">
                    <button type="button" class="button-secondary" data-funnel-add>Přidat krok</button>
                    <span class="field-help" data-funnel-count></span>
                </div>
            </div>

            <div class="form-actions">
                <button class="button-primary" type="submit">Uložit trychtýř</button>
                <a class="button-secondary" href="funnels.php?domain_id=<?= (int) $domainId ?>">Zrušit</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2>Trychtýře webu</h2>
            <p>Konverzní cesty z GA4 eventů. Aktivní trychtýře se nabízejí v Přehledu ve výběru „Zdroj dat“, vidí je i běžní uživatelé (jen čtení).</p>
        </div>
        <div class="card-header-actions">
            <form method="get" class="metrics-filter">
                <label><span class="table-muted">Web</span>
                    <select name="domain_id" data-autosubmit aria-label="Web">
                        <?php foreach ($domains as $domain): ?><option value="<?= (int) $domain['id'] ?>"<?= (int) $domain['id'] === $domainId ? ' selected' : '' ?>><?= h($domain['url']) ?></option><?php endforeach; ?>
                    </select>
                </label>
            </form>
            <?php if (!$atLimit): ?>
                <a class="button-primary" href="funnels.php?domain_id=<?= (int) $domainId ?>&amp;new=1">Nový trychtýř</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="admin-card-body table-scroll">
        <table class="admin-table">
            <thead><tr><th>Název</th><th>Kroky</th><th>Rozpad</th><th>Stav</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($funnels as $funnel): ?>
                <tr>
                    <td><strong><?= h($funnel['name']) ?></strong></td>
                    <td>
                        <span class="table-muted"><?= count($funnel['steps']) ?> <?= count($funnel['steps']) === 1 ? 'krok' : (count($funnel['steps']) < 5 ? 'kroky' : 'kroků') ?></span><br>
                        <span class="funnel-list-steps" title="<?= h(implode(' → ', array_column($funnel['steps'], 'event'))) ?>"><?= h(implode(' → ', array_column($funnel['steps'], 'label'))) ?></span>
                    </td>
                    <td><?= h($breakdowns[$funnel['breakdown']] ?? $funnel['breakdown']) ?></td>
                    <td><?= $funnel['is_active'] ? '<span class="status-badge status-ok">Aktivní</span>' : '<span class="status-badge status-info">Vypnutý</span>' ?></td>
                    <td>
                        <div class="table-actions">
                            <a class="button-secondary" href="funnels.php?domain_id=<?= (int) $domainId ?>&amp;edit=<?= (int) $funnel['id'] ?>">Upravit</a>
                            <a class="button-secondary" href="../?view=funnel&amp;funnel_id=<?= (int) $funnel['id'] ?>&amp;domain_id=<?= (int) $domainId ?>">Zobrazit v přehledu</a>
                            <form method="post" data-confirm="<?= h('Smazat trychtýř „' . $funnel['name'] . '“? Nastavení se nedá vrátit, naměřená data zůstanou.') ?>">
                                <?= allstat_csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="domain_id" value="<?= (int) $domainId ?>">
                                <input type="hidden" name="id" value="<?= (int) $funnel['id'] ?>">
                                <button class="button-danger" type="submit">Smazat</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$funnels): ?>
                <tr><td colspan="5" class="table-muted">Tento web zatím nemá žádný trychtýř. Klikni na „Nový trychtýř“ a vyber šablonu, třeba poptávkový formulář.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <?php if ($atLimit): ?><p class="table-muted">Web má maximální počet trychtýřů (<?= (int) $limits['max_funnels'] ?>). Nový přidáš po smazání některého ze stávajících.</p><?php endif; ?>
    </div>
</div>

<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2>Jak nastavit měření</h2>
            <p>Trychtýř je jen tak dobrý, jak dobře se měří jeho kroky.</p>
        </div>
    </div>
    <div class="admin-card-body">
        <ul class="funnel-help">
            <li><strong>Kroky jsou GA4 eventy.</strong> Event musí chodit do Google Analytics 4, typicky přes Google Tag Manager. Názvy piš malými písmeny s podtržítky, například <code>form_submit</code>. U každého kroku vidíš, kolikrát event za posledních 28 dní přišel; když nepřišel vůbec, zkontroluj měření.</li>
            <li><strong>Rozpad podle zdroje se plní od dalšího syncu GA4.</strong> Počty kroků fungují hned i zpětně, ale rozpad podle kanálu, zdroje a média nebo kampaně se začne ukládat až při příští synchronizaci GA4 po vytvoření trychtýře. Starší období doplníš přes „Stáhnout historii“ u zdroje GA4 (Zdroje dat, úprava napojení).</li>
            <li><strong>Obchodní krok z CRM přidáš jako event.</strong> Když chceš na konec trychtýře krok, který se děje mimo web (například podepsaná smlouva), pošli ho z CRM do GA4 jako event přes Measurement Protocol a pak ho vyber jako poslední krok.</li>
            <li><strong>Čísla jsou počty událostí, ne lidí.</strong> Jeden návštěvník může event vyvolat vícekrát, proto může být pozdější krok vyšší než předchozí. Takový případ trychtýř označí upozorněním.</li>
        </ul>
    </div>
</div>
<?php allstat_admin_footer($config); ?>
