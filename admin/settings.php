<?php

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../lib/sync.php';

$pdo = allstat_admin_require_database($pdo, $config);
$user = allstat_require_user($pdo, $config);
allstat_require_admin($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    allstat_csrf_check();

    // Legal-pages form posts action=legal; handle + redirect so it never overwrites system settings.
    if (($_POST['action'] ?? '') === 'legal') {
        foreach (['app', 'org', 'web', 'email', 'address', 'ico', 'effective'] as $lk) {
            allstat_set_setting($pdo, 'legal.' . $lk, trim((string) ($_POST['legal_' . $lk] ?? '')));
        }
        if (array_key_exists('org_context', $_POST)) {
            allstat_set_setting($pdo, 'share.org_context', trim((string) $_POST['org_context']));
        }
        allstat_audit($pdo, (int) $user['id'], null, 'settings_legal_updated');
        allstat_flash('ok', 'Údaje právních stránek byly uloženy.');
        allstat_redirect($config, 'admin/settings.php');
    }
    // Každý formulář posílá jen svoje pole — ukládej jen to, co v POSTu opravdu přišlo,
    // aby samostatné tlačítko (rotace cron secretu) neresetovalo ostatní nastavení.
    if (isset($_POST['security_enforce_2fa'])) {
        allstat_set_setting($pdo, 'security.enforce_2fa', $_POST['security_enforce_2fa'] === '1' ? '1' : '0');
    }
    if (array_key_exists('app_public_base_url', $_POST)) {
        allstat_set_setting($pdo, 'app.public_base_url', trim((string) $_POST['app_public_base_url']) ?: '/allstat');
    }
    if (array_key_exists('app_primary_domain_id', $_POST)) {
        allstat_set_setting($pdo, 'app.primary_domain_id', (string) ((int) $_POST['app_primary_domain_id']));
    }
    // Dávkový režim cronu (0 = bez limitu, jedno spuštění projde všechna napojení).
    if (array_key_exists('sync_cron_max_connections', $_POST)) {
        allstat_set_setting($pdo, 'sync.cron_max_connections', (string) min(1000, max(0, (int) $_POST['sync_cron_max_connections'])));
    }
    if (array_key_exists('sync_cron_max_seconds', $_POST)) {
        allstat_set_setting($pdo, 'sync.cron_max_seconds', (string) min(3600, max(0, (int) $_POST['sync_cron_max_seconds'])));
    }

    if (!empty($_POST['rotate_cron_secret'])) {
        allstat_set_setting($pdo, 'sync.cron_secret', bin2hex(random_bytes(16)));
        allstat_audit($pdo, (int) $user['id'], null, 'settings_updated');
        allstat_flash('ok', 'Nový cron secret vygenerován. Nezapomeň ho přepsat v cron úloze na hostingu.');
        allstat_redirect($config, 'admin/settings.php');
    }

    allstat_audit($pdo, (int) $user['id'], null, 'settings_updated');
    allstat_flash('ok', 'Nastavení bylo uloženo.');
    allstat_redirect($config, 'admin/settings.php');
}

$enforce2fa = allstat_setting($pdo, 'security.enforce_2fa', '0') === '1';
$publicBaseUrl = allstat_setting($pdo, 'app.public_base_url', '/allstat');
// Přímý dotaz (settings.php nenačítá repository.php, kde je allstat_get_domains).
$domains = $pdo->query('SELECT id, name FROM domains WHERE is_active = 1 ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$primaryDomainId = (int) allstat_setting($pdo, 'app.primary_domain_id', '0');
require_once __DIR__ . '/../_legal.php';
$legal = legal_config();
$orgContext = (string) allstat_setting($pdo, 'share.org_context', '');
$cronSecret = allstat_setting($pdo, 'sync.cron_secret', '');
$lastCronRun = allstat_setting($pdo, 'sync.last_cron_run', '');
$lastCronSummary = allstat_setting($pdo, 'sync.last_cron_summary', '');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$cronUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . allstat_url($config, 'cron/sync.php') . '?secret=' . $cronSecret;
$cronMaxConnections = (int) allstat_setting($pdo, 'sync.cron_max_connections', '0');
$cronMaxSeconds = (int) allstat_setting($pdo, 'sync.cron_max_seconds', '0');
$cronBatchMode = $cronMaxConnections > 0 || $cronMaxSeconds > 0;
// Průběh dnešního cyklu: kolik zapnutých napojení už dnes cron zpracoval (dávkový režim bere jen zbytek).
$cronProgress = allstat_fetch_one($pdo, 'SELECT COUNT(*) AS total, SUM(last_cron_at >= ?) AS done FROM domain_sources WHERE is_enabled = 1', [(new DateTimeImmutable('today'))->format('Y-m-d H:i:s')]);
allstat_admin_header('Nastavení', 'settings', $user, $config);
?>
<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2>Bezpečnost a systém</h2>
            <p>Základní nastavení administrace. Každá položka má u sebe vysvětlení, k čemu slouží, běžně tu není potřeba nic měnit.</p>
        </div>
    </div>
    <div class="admin-card-body">
        <form method="post" class="form-stack">
            <?= allstat_csrf_field() ?>
            <div class="form-grid">
                <label>
                    <span>Vyžadovat 2FA pro všechny uživatele</span>
                    <select name="security_enforce_2fa"><option value="1" <?= $enforce2fa ? 'selected' : '' ?>>Ano</option><option value="0" <?= !$enforce2fa ? 'selected' : '' ?>>Ne</option></select>
                    <small class="field-help">Při „Ano" se každý uživatel bez nastaveného dvoufaktoru musí při příštím přihlášení projít nastavením 2FA (aplikace typu Google Authenticator). Doporučeno, pokud má přístup víc lidí.</small>
                </label>
                <label>
                    <span>Veřejná base URL</span>
                    <input type="text" name="app_public_base_url" value="<?= h($publicBaseUrl) ?>">
                    <small class="field-help">Cesta, na které aplikace běží (např. <code>/allstat</code>). Používá se pro odkazy a přesměrování, měň jen při přesunu aplikace na jinou adresu.</small>
                </label>
                <label>
                    <span>Primární web (po přihlášení)</span>
                    <select name="app_primary_domain_id" data-select-search>
                        <option value="0"<?= $primaryDomainId === 0 ? ' selected' : '' ?>>Automaticky (web shodný s doménou aplikace, jinak první v abecedě)</option>
                        <?php foreach ($domains as $d): ?>
                        <option value="<?= (int) $d['id'] ?>"<?= $primaryDomainId === (int) $d['id'] ? ' selected' : '' ?>><?= h($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="field-help">Web, který se zobrazí hned po přihlášení. „Automaticky" vybere web se stejnou doménou, na které AllStat běží, jinak první podle názvu. Web přepnutý v přehledu nebo ve filtrech se pak drží napříč celou administrací až do dalšího přepnutí. Nastavit jde i tlačítkem u webu na stránce Weby.</small>
                </label>
            </div>
            <div class="form-actions">
                <button class="button-primary" type="submit">Uložit nastavení</button>
            </div>
        </form>
    </div>
</div>

<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2>Automatický denní sync (cron)</h2>
            <p>Spouští stažení posledních <?= ALLSTAT_INCREMENTAL_DAYS ?> dní pro všechna zapnutá napojení. Historie (16 měsíců) se dělá ručně tlačítkem u zdroje.</p>
        </div>
        <?php
        // Stav běhu s badge: OK do ~26 h od posledního běhu (denní cron + rezerva), jinak upozornění.
        $cronTs = $lastCronRun ? strtotime($lastCronRun) : false;
        $cronStatus = $cronTs === false ? ['warning', 'Zatím neproběhl'] : (time() - $cronTs <= 26 * 3600 ? ['ok', 'Běží'] : ['error', 'Neběžel přes 26 h']);
        ?>
        <span class="status-badge status-<?= h($cronStatus[0]) ?>"><?= h($cronStatus[1]) ?></span>
    </div>
    <div class="admin-card-body">
        <?php if ($cronTs !== false): ?>
        <p class="cron-status">Poslední běh <strong><?= h(date('j. n. Y H:i', $cronTs)) ?></strong><?= $lastCronSummary ? ': ' . h($lastCronSummary) : '' ?></p>
        <p class="cron-status table-muted">Dnes cron zpracoval <strong><?= (int) ($cronProgress['done'] ?? 0) ?> z <?= (int) ($cronProgress['total'] ?? 0) ?></strong> zapnutých napojení.</p>
        <?php else: ?>
        <p class="cron-status table-muted">Cron zatím neběžel. Nastav ho podle kroků níže, stav se sem pak propíše sám.</p>
        <?php endif; ?>

        <ol class="setup-steps">
            <li>
                <strong>Zkopíruj cron URL</strong> (obsahuje tajný klíč, drž ji v tajnosti):
                <div class="code-box"><?= h($cronUrl) ?></div>
            </li>
            <li>
                <?php if ($cronBatchMode): ?>
                <strong>V administraci hostingu (Cron Jobs) přidej úlohu co 10 minut v noci</strong> (dávkový režim je zapnutý, každé spuštění vezme další napojení):
                <div class="code-box">*/10 0-5 * * * curl -s "<?= h($cronUrl) ?>" &gt;/dev/null 2&gt;&amp;1</div>
                <?php else: ?>
                <strong>V administraci hostingu (sekce Cron nebo Plánované úlohy) přidej úlohu 1× denně ráno</strong> (např. 6:00):
                <div class="code-box">0 6 * * * curl -s "<?= h($cronUrl) ?>" &gt;/dev/null 2&gt;&amp;1</div>
                <?php endif; ?>
                <small class="field-help">Pokud panel umí přímo URL ping (wget/fetch), stačí zadat samotnou URL z kroku 1. Varianta přes příkazový řádek PHP (spolehlivější u dlouhého stahování): <code>php <?= h(dirname(__DIR__)) ?>/cron/sync.php <?= h($cronSecret) ?></code></small>
            </li>
        </ol>

        <form method="post" class="form-stack">
            <?= allstat_csrf_field() ?>
            <div class="form-grid">
                <label>
                    <span>Dávka: nejvýš napojení na jedno spuštění</span>
                    <input type="number" name="sync_cron_max_connections" min="0" max="1000" step="1" value="<?= $cronMaxConnections ?>">
                    <small class="field-help">0 = bez limitu, jedno spuštění projde všechna napojení (stačí cron 1× denně). Při desítkách webů nastav třeba 20 a cron spouštěj co 10 minut v noci: každé spuštění vezme další napojení, která dnes ještě neproběhla, hotová přeskočí.</small>
                </label>
                <label>
                    <span>Dávka: nejvýš sekund na jedno spuštění</span>
                    <input type="number" name="sync_cron_max_seconds" min="0" max="3600" step="10" value="<?= $cronMaxSeconds ?>">
                    <small class="field-help">0 = bez limitu. Pojistka pro hostingy, které dlouhý skript utnou (často po 5 až 10 minutách): po uplynutí času se další napojení nezačne a dojde na něj příští spuštění.</small>
                </label>
            </div>
            <div class="form-actions">
                <button class="button-primary" type="submit">Uložit dávkový režim</button>
            </div>
        </form>

        <form method="post" class="form-stack cron-secret-block">
            <?= allstat_csrf_field() ?>
            <label>
                <span>Cron secret</span>
                <input type="text" value="<?= h($cronSecret) ?>" readonly>
                <small class="field-help">Tajný klíč, kterým se URL výše prokazuje. Kdokoli s ním může spustit synchronizaci. Po úniku vygeneruj nový a přepiš cron úlohu na hostingu.</small>
            </label>
            <div class="form-actions">
                <button class="button-secondary" type="submit" name="rotate_cron_secret" value="1">Vygenerovat nový cron secret</button>
            </div>
        </form>
    </div>
</div>
<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2>Právní stránky (Privacy / Terms / Smazání dat)</h2>
            <p>Údaje správce zobrazené na veřejných právních stránkách, které vyžadují Meta a LinkedIn. Měň je tady, bez zásahu do kódu nebo FTP. Až nástroj poběží pod jinou organizací, jen přepiš název, web a e-mail.</p>
        </div>
    </div>
    <div class="admin-card-body">
        <form method="post" class="form-stack">
            <?= allstat_csrf_field() ?>
            <input type="hidden" name="action" value="legal">
            <div class="form-grid">
                <label><span>Název organizace (správce)</span><input type="text" name="legal_org" value="<?= h($legal['org']) ?>"></label>
                <label><span>Název aplikace</span><input type="text" name="legal_app" value="<?= h($legal['app']) ?>"></label>
                <label><span>Web</span><input type="text" name="legal_web" value="<?= h($legal['web']) ?>" placeholder="https://www.example.cz"></label>
                <label><span>Kontaktní e-mail</span><input type="email" name="legal_email" value="<?= h($legal['email']) ?>"></label>
                <label><span>Sídlo / adresa (volitelné)</span><input type="text" name="legal_address" value="<?= h($legal['address']) ?>"></label>
                <label><span>IČO (volitelné)</span><input type="text" name="legal_ico" value="<?= h($legal['ico']) ?>"></label>
                <label><span>Účinné od</span><input type="text" name="legal_effective" value="<?= h($legal['effective']) ?>"></label>
                <label style="grid-column:1/-1"><span>Popis organizace pro AI analýzy (volitelné)</span><textarea name="org_context" rows="4" maxlength="4000" style="font-family:inherit" placeholder="Například: Jsme e-shop s outdoorovým vybavením, hlavním cílem je prodej, marketing dělají dva lidé."><?= h($orgContext) ?></textarea><small class="field-help">Stručně napiš, kdo jste, komu web slouží a co je jeho cíl (prodej, registrace, povědomí). AI asistent tím dostane kontext k rozboru dat z exportu. Prázdné pole znamená obecný kontext.</small></label>
            </div>
            <p class="form-help">Veřejné URL (tyto vlož do Meta App → Settings → Basic a do LinkedIn app → Settings):
                <a href="../privacy.php" target="_blank" rel="noopener">privacy.php</a> ·
                <a href="../terms.php" target="_blank" rel="noopener">terms.php</a> ·
                <a href="../data-deletion.php" target="_blank" rel="noopener">data-deletion.php</a>
            </p>
            <div class="form-actions">
                <button class="button-primary" type="submit">Uložit údaje právních stránek</button>
            </div>
        </form>
    </div>
</div>
<?php allstat_admin_footer($config); ?>
