<?php

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../lib/meta-connect.php';

$pdo = allstat_admin_require_database($pdo, $config);
$user = allstat_require_user($pdo, $config);
allstat_require_admin($user);

$app = allstat_meta_app($pdo, $config);
$domains = allstat_admin_domains($pdo, false);
$currentDomainId = allstat_current_domain_id($pdo, $domains);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$redirectUri = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . allstat_url($config, 'admin/oauth-callback.php');

// Save the app-wide Meta App ID + Secret (once).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_app') {
    allstat_csrf_check();
    $appId = trim((string) ($_POST['app_id'] ?? ''));
    $appSecret = trim((string) ($_POST['app_secret'] ?? ''));
    if ($appId === '' || ($appSecret === '' && !$app['configured'])) {
        allstat_flash('error', 'Vyplň App ID i App Secret.');
    } else {
        allstat_meta_save_app($pdo, $config, $appId, $appSecret);
        $scopes = trim((string) ($_POST['scopes'] ?? ''));
        allstat_set_setting($pdo, 'meta.scopes', $scopes);
        allstat_flash('ok', 'Meta App uložena. Teď vyber web a klikni „Připojit přes Facebook".');
    }
    allstat_redirect($config, 'admin/meta-connect.php');
}

// Start the OAuth (bulk discovery) flow for one domain.
if (($_GET['action'] ?? '') === 'start') {
    if (!$app['configured']) {
        allstat_flash('error', 'Nejdřív ulož Meta App ID a App Secret.');
        allstat_redirect($config, 'admin/meta-connect.php');
    }
    $domainId = filter_input(INPUT_GET, 'domain_id', FILTER_VALIDATE_INT) ?: 0;
    if ($domainId <= 0) {
        allstat_flash('error', 'Vyber web, ke kterému Meta připojit.');
        allstat_redirect($config, 'admin/meta-connect.php');
    }
    $state = bin2hex(random_bytes(24));
    $_SESSION['oauth_state'][$state] = [
        'flow' => 'meta_bulk',
        'domain_id' => $domainId,
        'redirect_uri' => $redirectUri,
        'created_at' => time(),
    ];
    header('Location: ' . allstat_meta_oauth_url($app['app_id'], $redirectUri, $state, allstat_meta_scopes($pdo)));
    exit;
}

allstat_admin_header('Připojit Meta', 'sources', $user, $config);
?>
<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2>Připojit Meta (Facebook + Instagram + Ads)</h2>
            <p>Jedním přihlášením přes Facebook se připojí tvoje FB stránky, Instagram účty i reklamní účty. App ID a Secret se zadávají jen jednou.</p>
        </div>
        <a class="button-secondary" href="sources.php">Zpět na zdroje</a>
    </div>
    <div class="admin-card-body">
        <div class="code-box">
            <strong>OAuth callback URL</strong> (musí být v Meta App → Facebook Login → Settings → Valid OAuth Redirect URIs):<br>
            <?= h($redirectUri) ?>
        </div>
    </div>
</div>

<div class="admin-card">
    <div class="admin-card-header"><div><h2>1) Meta App údaje</h2><p>Z developers.facebook.com → tvoje appka → Settings → Basic. Secret se ukládá šifrovaně.</p></div></div>
    <div class="admin-card-body">
        <form method="post" class="form-stack">
            <?= allstat_csrf_field() ?>
            <input type="hidden" name="action" value="save_app">
            <div class="form-grid">
                <label><span>App ID</span><input type="text" name="app_id" value="<?= h($app['app_id']) ?>" placeholder="1234567890" autocomplete="off"></label>
                <label><span>App Secret</span><input type="password" name="app_secret" value="" autocomplete="new-password" placeholder="<?= $app['configured'] ? 'uloženo, nechte prázdné pro zachování' : '' ?>"></label>
            </div>
            <label><span>Požadovaná oprávnění (scopes)</span><textarea name="scopes" rows="2"><?= h(allstat_meta_scopes($pdo)) ?></textarea></label>
            <p class="form-help">Čárkou/řádky oddělené. Žádej jen ta, která máš v Meta App skutečně povolená, když jedno chybí, Facebook odmítne <strong>všechna naráz</strong> („Invalid Scopes"). Pro samotnou FB stránku stačí <code>pages_show_list, pages_read_engagement, read_insights</code>; pro <strong>Instagram</strong> přidej navíc <code>instagram_basic, instagram_manage_insights</code> (bez nich se IG účty vůbec nenabídnou); pro <strong>reklamy</strong> <code>ads_read</code>.</p>
            <div class="form-actions">
                <button class="button-primary" type="submit">Uložit Meta App</button>
                <?php if ($app['configured']): ?><span class="status-badge status-ok">App nastavená</span><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<details class="admin-card-collapsible">
    <summary>
        <div>
            <h2>Instagram se nenabízí? Co ověřit</h2>
            <p>AllStat vidí IG jen přes propojenou FB stránku, když chybí jediná z podmínek, v nabídce nebude.</p>
        </div>
    </summary>
    <div class="admin-card-body">
        <ol class="provider-guide-steps">
            <li><strong>Typ účtu:</strong> IG musí být <strong>Business nebo Creator</strong> (ne osobní). Přepneš v IG appce → Nastavení → Typ účtu / Přepnout na profesionální účet.</li>
            <li><strong>Propojení s FB stránkou:</strong> IG musí být <strong>propojený s FB stránkou</strong>, kterou spravuješ (Meta Business Suite → Nastavení → Účty, nebo IG appka → Nastavení → Propojené účty → Facebook). Stránka může být i „prázdná", slouží jen jako most pro API.</li>
            <li><strong>Oprávnění (scopes):</strong> v poli „Požadovaná oprávnění" výše musí být <code>instagram_basic</code> a <code>instagram_manage_insights</code>.</li>
            <li><strong>Meta App:</strong> v appce na developers.facebook.com přidej produkt <strong>Instagram</strong> a použij <strong>„API setup with Facebook login"</strong> (NE „Instagram login", ta je pro jiný typ napojení). V <strong>Development</strong> módu funguje pro tvoje vlastní účty bez App Review.</li>
            <li>Pak dej <strong>„Připojit přes Facebook"</strong> níže a při přihlášení <strong>potvrď i Instagram</strong> oprávnění → IG se objeví pod svou stránkou → zaškrtni a ulož.</li>
        </ol>
        <p class="form-help">Když je IG <strong>samostatný</strong> a nechceš ho párovat s FB stránkou, existuje i přímé „Instagram Login" napojení (bez FB stránky), to ale AllStat zatím neumí. Napiš a doděláme ho jako samostatného providera.</p>
    </div>
</details>

<div class="admin-card">
    <div class="admin-card-header"><div><h2>2) Připojit účty</h2><p>Vyber web a přihlas se přes Facebook. Pak vybereš, které stránky / IG / reklamní účty připojit.</p></div></div>
    <div class="admin-card-body">
        <?php if (!$app['configured']): ?>
            <p class="table-muted">Nejdřív ulož Meta App ID a Secret výše.</p>
        <?php elseif (!$domains): ?>
            <p class="table-muted">Nejdřív přidej web ve <a href="domains.php">Weby</a>.</p>
        <?php else: ?>
            <form method="get" class="toolbar" action="meta-connect.php">
                <input type="hidden" name="action" value="start">
                <label class="filter-control"><span>Web</span>
                    <select name="domain_id" required>
                        <?php foreach ($domains as $domain): ?>
                            <option value="<?= (int) $domain['id'] ?>"<?= (int) $domain['id'] === $currentDomainId ? ' selected' : '' ?>><?= h($domain['name']) ?> (<?= h($domain['url']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="button-primary" type="submit"><?= allstat_meta_button_icon() ?> Připojit přes Facebook</button>
            </form>
            <p class="form-help" style="margin-top:10px;">Appka je v Development módu, funguje pro účty s rolí na appce (ty + případně klienti přidaní jako testeři). App Review není potřeba.</p>
        <?php endif; ?>
    </div>
</div>
<?php allstat_admin_footer($config); ?>
