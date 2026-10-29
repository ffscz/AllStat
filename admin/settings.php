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
        allstat_audit($pdo, (int) $user['id'], null, 'settings_legal_updated');
        allstat_flash('ok', t('Údaje právních stránek byly uloženy.'));
        allstat_redirect($config, 'admin/settings.php');
    }
    // Každý formulář posílá jen svoje pole - ukládej jen to, co v POSTu opravdu přišlo,
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
    if (is_string($_POST['app_locale'] ?? null) && isset(ALLSTAT_LOCALES[$_POST['app_locale']])) {
        allstat_set_setting($pdo, 'app.locale', $_POST['app_locale']);
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
        allstat_flash('ok', t('Nový cron secret vygenerován. Nezapomeň ho přepsat v cron úloze na hostingu.'));
        allstat_redirect($config, 'admin/settings.php');
    }

    allstat_audit($pdo, (int) $user['id'], null, 'settings_updated');
    allstat_flash('ok', t('Nastavení bylo uloženo.'));
    allstat_redirect($config, 'admin/settings.php');
}

$enforce2fa = allstat_setting($pdo, 'security.enforce_2fa', '0') === '1';
$publicBaseUrl = allstat_setting($pdo, 'app.public_base_url', '/allstat');
// Přímý dotaz (settings.php nenačítá repository.php, kde je allstat_get_domains).
$domains = $pdo->query('SELECT id, name FROM domains WHERE is_active = 1 ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$primaryDomainId = (int) allstat_setting($pdo, 'app.primary_domain_id', '0');
require_once __DIR__ . '/../_legal.php';
$legal = legal_config();
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
allstat_admin_header(t('Nastavení'), 'settings', $user, $config);
?>
<section class="card">
    <div class="card-head">
        <div>
            <h2 class="card-title"><?= h(t('Bezpečnost a systém')) ?></h2>
            <p class="card-desc"><?= h(t('Základní nastavení administrace. Každá položka má u sebe vysvětlení, k čemu slouží, běžně tu není potřeba nic měnit.')) ?></p>
        </div>
    </div>
    <form method="post" class="card-body form-grid">
        <?= allstat_csrf_field() ?>
        <label class="field">
            <span class="field-label"><?= h(t('Vyžadovat 2FA pro všechny uživatele')) ?></span>
            <select class="input" name="security_enforce_2fa"><option value="1" <?= $enforce2fa ? 'selected' : '' ?>><?= h(t('Ano')) ?></option><option value="0" <?= !$enforce2fa ? 'selected' : '' ?>><?= h(t('Ne')) ?></option></select>
            <span class="field-help"><?= h(t('Při „Ano" se každý uživatel bez nastaveného dvoufaktoru musí při příštím přihlášení projít nastavením 2FA (aplikace typu Google Authenticator). Doporučeno, pokud má přístup víc lidí.')) ?></span>
        </label>
        <label class="field">
            <span class="field-label"><?= h(t('Veřejná base URL')) ?></span>
            <input class="input" type="text" name="app_public_base_url" value="<?= h($publicBaseUrl) ?>">
            <span class="field-help"><?= t('Cesta, na které aplikace běží (např. <code>/allstat</code>). Používá se pro odkazy a přesměrování, měň jen při přesunu aplikace na jinou adresu.') ?></span>
        </label>
        <label class="field">
            <span class="field-label"><?= h(t('Primární web (po přihlášení)')) ?></span>
            <select class="input" name="app_primary_domain_id" data-select-search>
                <option value="0"<?= $primaryDomainId === 0 ? ' selected' : '' ?>><?= h(t('Automaticky (web shodný s doménou aplikace, jinak první v abecedě)')) ?></option>
                <?php foreach ($domains as $d): ?>
                <option value="<?= (int) $d['id'] ?>"<?= $primaryDomainId === (int) $d['id'] ? ' selected' : '' ?>><?= h($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="field-help"><?= h(t('Web, který se zobrazí hned po přihlášení. „Automaticky" vybere web se stejnou doménou, na které AllStat běží, jinak první podle názvu. Web přepnutý v přehledu nebo ve filtrech se pak drží napříč celou administrací až do dalšího přepnutí. Nastavit jde i tlačítkem u webu na stránce Weby.')) ?></span>
        </label>
        <label class="field">
            <span class="field-label"><?= h(t('Výchozí jazyk')) ?></span>
            <select class="input" name="app_locale">
                <?php $appLocale = (string) allstat_setting($pdo, 'app.locale', 'cs'); ?>
                <?php foreach (ALLSTAT_LOCALES as $code => $label): ?>
                <option value="<?= h($code) ?>"<?= $appLocale === $code ? ' selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="field-help"><?= h(t('Jazyk přihlašovací stránky, veřejných stránek a uživatelů, kteří si jazyk nevybrali. Každý uživatel si ho může změnit sám v horní liště.')) ?></span>
        </label>
        <div class="form-actions field-full">
            <button class="btn btn-primary" type="submit"><?= h(t('Uložit nastavení')) ?></button>
        </div>
    </form>
</section>

<?php
// Verze a aktualizace jen tam, kde je updater (veřejný balíček); interní instalace ho mít nemusí.
$updateStatus = null;
if (is_file(__DIR__ . '/../lib/updater.php')) {
    require_once __DIR__ . '/../lib/updater.php';
    $updateStatus = allstat_update_check($pdo, false, 4);
}
?>
<?php if ($updateStatus !== null): ?>
<section class="card">
    <div class="card-head">
        <div>
            <h2 class="card-title"><?= h(t('Verze a aktualizace')) ?></h2>
            <p class="card-desc"><?php if ($updateStatus['installed'] === null): ?><?= h(t('Interní instalace (bez souboru VERSION), aktualizace nasazuje vývojář.')) ?><?php elseif ($updateStatus['available']): ?><?= t('Nainstalovaná verze <strong>{installed}</strong>, k dispozici je <strong>{latest}</strong>.', ['installed' => h($updateStatus['installed']), 'latest' => h($updateStatus['latest']['version'])]) ?><?php else: ?><?= t('Nainstalovaná verze <strong>{installed}</strong>, je aktuální.', ['installed' => h($updateStatus['installed'])]) ?><?php endif; ?></p>
        </div>
        <?php if ($updateStatus['installed'] !== null): ?>
            <a class="btn <?= $updateStatus['available'] ? 'btn-primary' : 'btn-secondary' ?>" href="update.php"><?= h($updateStatus['available'] ? t('Aktualizovat') : t('Aktualizace')) ?></a>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<section class="card">
    <div class="card-head">
        <div>
            <h2 class="card-title"><?= h(t('Automatický denní sync (cron)')) ?></h2>
            <p class="card-desc"><?= h(t('Spouští stažení posledních {days} dní pro všechna zapnutá napojení. Historie (16 měsíců) se dělá ručně tlačítkem u zdroje.', ['days' => ALLSTAT_INCREMENTAL_DAYS])) ?> <?= allstat_help_link('cron', t('Jak cron nastavit na hostingu')) ?></p>
        </div>
        <?php
        // Stav běhu: OK do ~26 h od posledního běhu (denní cron + rezerva), jinak upozornění.
        $cronTs = $lastCronRun ? strtotime($lastCronRun) : false;
        $cronStatus = $cronTs === false ? ['warning', t('Zatím neproběhl')] : (time() - $cronTs <= 26 * 3600 ? ['ok', t('Běží')] : ['error', t('Neběžel přes 26 h')]);
        ?>
        <?= allstat_status_pill($cronStatus[0], $cronStatus[1]) ?>
    </div>
    <div class="card-body section">
        <?php if ($cronTs !== false): ?>
        <div>
            <p class="cron-status"><?= t('Poslední běh <strong>{date}</strong>', ['date' => h(allstat_date_label($cronTs, true))]) ?><?= $lastCronSummary ? ': ' . h($lastCronSummary) : '' ?></p>
            <p class="cron-status is-muted"><?= t('Dnes cron zpracoval <strong>{done} z {total}</strong> zapnutých napojení.', ['done' => (int) ($cronProgress['done'] ?? 0), 'total' => (int) ($cronProgress['total'] ?? 0)]) ?></p>
        </div>
        <?php else: ?>
        <p class="cron-status is-muted"><?= h(t('Cron zatím neběžel. Nastav ho podle kroků níže, stav se sem pak propíše sám.')) ?></p>
        <?php endif; ?>

        <ol class="steps">
            <li>
                <?= t('<strong>Zkopíruj cron URL</strong> (obsahuje tajný klíč, drž ji v tajnosti):') ?>
                <code class="code"><?= h($cronUrl) ?></code>
            </li>
            <li>
                <?php if ($cronBatchMode): ?>
                <?= t('<strong>V administraci hostingu (Cron Jobs) přidej úlohu co 10 minut v noci</strong> (dávkový režim je zapnutý, každé spuštění vezme další napojení):') ?>
                <code class="code">*/10 0-5 * * * curl -s "<?= h($cronUrl) ?>" &gt;/dev/null 2&gt;&amp;1</code>
                <?php else: ?>
                <?= t('<strong>V administraci hostingu (sekce Cron nebo Plánované úlohy) přidej úlohu 1× denně ráno</strong> (např. 6:00):') ?>
                <code class="code">0 6 * * * curl -s "<?= h($cronUrl) ?>" &gt;/dev/null 2&gt;&amp;1</code>
                <?php endif; ?>
                <span class="field-help"><?= t('Pokud panel umí přímo URL ping (wget/fetch), stačí zadat samotnou URL z kroku 1. Varianta přes příkazový řádek PHP (spolehlivější u dlouhého stahování): <code>php {path}/cron/sync.php {secret}</code>', ['path' => h(dirname(__DIR__)), 'secret' => h($cronSecret)]) ?></span>
            </li>
        </ol>

        <form method="post" class="form-grid">
            <?= allstat_csrf_field() ?>
            <label class="field">
                <span class="field-label"><?= h(t('Dávka: nejvýš napojení na jedno spuštění')) ?></span>
                <input class="input" type="number" name="sync_cron_max_connections" min="0" max="1000" step="1" value="<?= $cronMaxConnections ?>">
                <span class="field-help"><?= h(t('0 = bez limitu, jedno spuštění projde všechna napojení (stačí cron 1× denně). Při desítkách webů nastav třeba 20 a cron spouštěj co 10 minut v noci: každé spuštění vezme další napojení, která dnes ještě neproběhla, hotová přeskočí.')) ?></span>
            </label>
            <label class="field">
                <span class="field-label"><?= h(t('Dávka: nejvýš sekund na jedno spuštění')) ?></span>
                <input class="input" type="number" name="sync_cron_max_seconds" min="0" max="3600" step="10" value="<?= $cronMaxSeconds ?>">
                <span class="field-help"><?= h(t('0 = bez limitu. Pojistka pro hostingy, které dlouhý skript utnou (často po 5 až 10 minutách): po uplynutí času se další napojení nezačne a dojde na něj příští spuštění.')) ?></span>
            </label>
            <div class="form-actions field-full">
                <button class="btn btn-primary" type="submit"><?= h(t('Uložit dávkový režim')) ?></button>
            </div>
        </form>

        <form method="post" class="form-grid cron-secret-block">
            <?= allstat_csrf_field() ?>
            <label class="field field-full">
                <span class="field-label"><?= h(t('Cron secret')) ?></span>
                <input class="input mono" type="text" value="<?= h($cronSecret) ?>" readonly>
                <span class="field-help"><?= h(t('Tajný klíč, kterým se URL výše prokazuje. Kdokoli s ním může spustit synchronizaci. Po úniku vygeneruj nový a přepiš cron úlohu na hostingu.')) ?></span>
            </label>
            <div class="form-actions field-full">
                <button class="btn btn-secondary" type="submit" name="rotate_cron_secret" value="1"><?= h(t('Vygenerovat nový cron secret')) ?></button>
            </div>
        </form>
    </div>
</section>

<section class="card">
    <div class="card-head">
        <div>
            <h2 class="card-title"><?= h(t('Právní stránky (Privacy / Terms / Smazání dat)')) ?></h2>
            <p class="card-desc"><?= h(t('Údaje správce zobrazené na veřejných právních stránkách, které vyžadují Meta a LinkedIn. Měň je tady, bez zásahu do kódu nebo FTP. Až nástroj poběží pod jinou organizací, jen přepiš název, web a e-mail.')) ?></p>
        </div>
    </div>
    <form method="post" class="card-body form-grid">
        <?= allstat_csrf_field() ?>
        <input type="hidden" name="action" value="legal">
        <label class="field"><span class="field-label"><?= h(t('Název organizace (správce)')) ?></span><input class="input" type="text" name="legal_org" value="<?= h($legal['org']) ?>"></label>
        <label class="field"><span class="field-label"><?= h(t('Název aplikace')) ?></span><input class="input" type="text" name="legal_app" value="<?= h($legal['app']) ?>"></label>
        <label class="field"><span class="field-label"><?= h(t('Web')) ?></span><input class="input" type="text" name="legal_web" value="<?= h($legal['web']) ?>" placeholder="https://www.example.cz"></label>
        <label class="field"><span class="field-label"><?= h(t('Kontaktní e-mail')) ?></span><input class="input" type="email" name="legal_email" value="<?= h($legal['email']) ?>"></label>
        <label class="field"><span class="field-label"><?= h(t('Sídlo / adresa (volitelné)')) ?></span><input class="input" type="text" name="legal_address" value="<?= h($legal['address']) ?>"></label>
        <label class="field"><span class="field-label"><?= h(t('IČO (volitelné)')) ?></span><input class="input" type="text" name="legal_ico" value="<?= h($legal['ico']) ?>"></label>
        <label class="field"><span class="field-label"><?= h(t('Účinné od')) ?></span><input class="input" type="text" name="legal_effective" value="<?= h($legal['effective']) ?>"></label>
        <p class="field-help field-full"><?= h(t('Veřejné URL (tyto vlož do Meta App → Settings → Basic a do LinkedIn app → Settings):')) ?>
            <a class="link-more" href="../privacy.php" target="_blank" rel="noopener">privacy.php</a> ·
            <a class="link-more" href="../terms.php" target="_blank" rel="noopener">terms.php</a> ·
            <a class="link-more" href="../data-deletion.php" target="_blank" rel="noopener">data-deletion.php</a>
        </p>
        <div class="form-actions field-full">
            <button class="btn btn-primary" type="submit"><?= h(t('Uložit údaje právních stránek')) ?></button>
        </div>
    </form>
</section>
<?php allstat_admin_footer($config); ?>
