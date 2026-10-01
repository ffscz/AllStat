<?php

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_layout.php';

$pdo = allstat_admin_require_database($pdo, $config);
$user = allstat_require_user($pdo, $config);
allstat_require_admin($user);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$sourceId = filter_input(INPUT_GET, 'source_id', FILTER_VALIDATE_INT) ?: 0;
$domainId = filter_input(INPUT_GET, 'domain_id', FILTER_VALIDATE_INT) ?: 0;
$connection = $id ? allstat_get_connection($pdo, $id) : allstat_prefill_connection($pdo, $sourceId);
if (!$id && !$domainId) {
    // Nové napojení bez zvoleného webu → předvolit web zvolený jinde v administraci.
    $domainId = allstat_current_domain_id($pdo, allstat_admin_domains($pdo, false));
}

if (!$connection) {
    $connection = ['status' => 'warning', 'is_enabled' => 1, 'domain_id' => $domainId, 'source_id' => $sourceId];
}

if ($domainId && empty($connection['domain_id'])) {
    $connection['domain_id'] = $domainId;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    allstat_csrf_check();

    if (($_POST['action'] ?? '') === 'inherit_oauth') {
        $inheritId = (int) ($_POST['id'] ?? 0);
        $inherit = allstat_inherit_oauth_credentials($pdo, $inheritId, (int) ($_POST['donor_id'] ?? 0));
        allstat_flash($inherit['ok'] ? 'ok' : 'error', $inherit['message']);
        allstat_redirect($config, 'admin/source-edit.php?id=' . $inheritId);
    }

    // Compose config_json from structured fields (e.g. Google Ads login_customer_id) before saving,
    // preserving the existing (decrypted) config so a blank secret field doesn't wipe it.
    $postSourceId = (int) ($_POST['source_id'] ?? 0);
    if ($postSourceId > 0) {
        $postSource = allstat_fetch_one($pdo, 'SELECT provider_key FROM data_sources WHERE id = ?', [$postSourceId]);
        $postProviderKey = (string) ($postSource['provider_key'] ?? '');
        if ($postProviderKey !== '') {
            $composed = allstat_compose_config_json($postProviderKey, $_POST, $connection['config_json'] ?? null);
            if ($composed !== null) {
                $_POST['config_json'] = $composed;
            }
        }
    }

    $result = allstat_save_connection($pdo, $config, (int) $user['id'], $_POST);

    if ($result['ok']) {
        allstat_flash('ok', $result['message']);
        allstat_redirect($config, 'admin/source-edit.php?id=' . (int) $result['id']);
    }

    allstat_flash('error', $result['message']);
    $connection = array_merge($connection, $_POST);
}

$domains = allstat_admin_domains($pdo, false);
$sources = allstat_data_sources($pdo);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$callbackUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . allstat_url($config, 'admin/oauth-callback.php');

$selectedSourceId = (int) ($connection['source_id'] ?? 0);
$selectedSource = null;
foreach ($sources as $sourceRow) {
    if ((int) $sourceRow['id'] === $selectedSourceId) {
        $selectedSource = $sourceRow;
        break;
    }
}

// The provider is always known when this form is opened (either ?id= of an existing connection,
// or ?source_id= picked on sources.php). With no provider there is nothing to render → send the
// user back to the service picker.
if (!$id && (!$selectedSource || $selectedSourceId <= 0)) {
    allstat_flash('info', 'Vyber službu, kterou chceš připojit.');
    allstat_redirect($config, 'admin/sources.php' . ($domainId ? '?domain_id=' . $domainId : ''));
}

$providerKey = (string) ($selectedSource['provider_key'] ?? '');
$schema = allstat_provider_form_schema($providerKey);
$connect = $schema['connect'] ?? 'oauth';
$guide = allstat_provider_setup_guide($providerKey);

// Field names rendered in the basic section → excluded from "Pokročilé" to avoid duplicates.
$basicNames = ['domain_id', 'source_id', 'account_label'];
foreach ($schema['fields'] as $f) {
    $basicNames[] = $f['name'];
}
$hasStructuredConfig = (bool) array_filter($schema['fields'], static fn ($f) => !empty($f['config']));

// Decoded existing config (for prefilling structured config fields like login_customer_id).
$existingConfig = [];
if (!empty($connection['config_json'])) {
    $decodedCfg = json_decode((string) $connection['config_json'], true);
    if (is_array($decodedCfg)) {
        $existingConfig = $decodedCfg;
    }
}

// Value for a basic field (secrets stay blank; a saved secret only shows a placeholder).
$basicValue = static function (array $f) use ($connection, $existingConfig): string {
    if (!empty($f['config'])) {
        return !empty($f['secret_config']) ? '' : (string) ($existingConfig[$f['config']] ?? '');
    }
    if (!empty($f['secret'])) {
        return '';
    }
    return (string) ($connection[$f['name']] ?? '');
};
$basicPlaceholder = static function (array $f) use ($connection, $existingConfig): string {
    if (!empty($f['secret']) && allstat_secret_present($connection[$f['secret']] ?? null)) {
        return 'uloženo, nechte prázdné pro zachování';
    }
    if (!empty($f['secret_config']) && !empty($existingConfig[$f['config']])) {
        return 'uloženo, nechte prázdné pro zachování';
    }
    return (string) ($f['placeholder'] ?? '');
};

// Advanced (expert) text/secret input — rendered only if not already shown in the basic section.
$advInput = static function (string $name, string $label, string $type = 'text', string $placeholder = '') use ($connection, $basicNames): void {
    if (in_array($name, $basicNames, true)) {
        return;
    }
    $secretCols = ['client_secret' => 'client_secret_enc', 'access_token' => 'access_token_enc', 'refresh_token' => 'refresh_token_enc'];
    $isSecret = isset($secretCols[$name]);
    $value = '';
    if (!$isSecret) {
        $value = in_array($name, ['token_expires_at', 'last_sync_at'], true)
            ? allstat_datetime_local($connection[$name] ?? null)
            : (string) ($connection[$name] ?? '');
    }
    if ($isSecret && allstat_secret_present($connection[$secretCols[$name]] ?? null)) {
        $placeholder = 'uloženo, nechte prázdné pro zachování';
    }
    echo '<label><span>' . h($label) . '</span><input type="' . h($type) . '" name="' . h($name) . '" value="' . h($value) . '" placeholder="' . h($placeholder) . '" autocomplete="' . ($isSecret ? 'new-password' : 'off') . '"></label>';
};

// Dárci OAuth údajů: jeden za každý odlišný Client ID (= Google projekt). Víc tlačítek jen když jsou projekty dva.
$oauthDonors = ($id && $connect === 'oauth') ? allstat_oauth_donors($pdo, (int) $id, $providerKey) : [];
$title = $id ? (string) $connection['source_name'] : 'Nové napojení – ' . ($selectedSource['name'] ?? 'služba');
allstat_admin_header($id ? 'Upravit zdroj' : 'Nové napojení', 'sources', $user, $config);
?>
<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2><?= h($title) ?></h2>
            <?php if (!empty($schema['intro'])): ?><p><?= h($schema['intro']) ?></p><?php endif; ?>
        </div>
    </div>
    <?php if ($id): ?>
    <div class="source-toolbar">
        <div class="source-toolbar-group">
            <span class="source-toolbar-label">Připojení</span>
            <?php if ($connect === 'oauth'): ?><a class="button-primary" href="oauth-start.php?connection_id=<?= (int) $id ?>">Spustit OAuth</a><?php endif; ?>
            <?php if ($connect === 'meta'): ?><a class="button-primary" href="meta-connect.php"><?= allstat_meta_button_icon() ?> Připojit přes Meta</a><?php endif; ?>
            <?php foreach ($oauthDonors as $oauthDonor): ?>
            <form method="post" style="display:inline">
                <?= allstat_csrf_field() ?>
                <input type="hidden" name="action" value="inherit_oauth">
                <input type="hidden" name="id" value="<?= (int) $id ?>">
                <input type="hidden" name="donor_id" value="<?= (int) $oauthDonor['id'] ?>">
                <button type="submit" class="button-secondary" title="Zkopíruje Client ID i Client secret z napojení „<?= h($oauthDonor['label']) ?>" (Client ID <?= h(substr((string) $oauthDonor['client_id'], 0, 12)) ?>…). <?= count($oauthDonors) > 1 ? 'Každé tlačítko = jiný Google projekt (OAuth aplikace); vyber ten, ve kterém má účet pro přihlášení přístup.' : 'Nemusíš je hledat ručně.' ?>">Převzít údaje z „<?= h(count($oauthDonors) > 1 ? $oauthDonor['label'] : $oauthDonor['name']) ?>"</button>
            </form>
            <?php endforeach; ?>
            <button type="button" class="button-secondary" data-connection-test="<?= (int) $id ?>" data-csrf="<?= h(allstat_csrf_token()) ?>">Otestovat napojení</button>
        </div>
        <div class="source-toolbar-group">
            <span class="source-toolbar-label">Data</span>
            <button type="button" class="button-primary" data-connection-sync="<?= (int) $id ?>" data-csrf="<?= h(allstat_csrf_token()) ?>" title="Stáhne posledních 7 dní VČETNĚ dneška a včerejška. Bezpečné kdykoli: existující dny jen přepíše aktuálními hodnotami, nic neduplikuje.">Stáhnout aktuální data (7 dní)</button>
            <button type="button" class="button-secondary" data-connection-backfill="<?= (int) $id ?>" data-csrf="<?= h(allstat_csrf_token()) ?>">Stáhnout historii (16 měsíců)</button>
            <?php if ($providerKey === 'clarity'): ?>
            <a class="button-secondary" href="clarity-import.php?domain_id=<?= (int) $connection['domain_id'] ?>">Import CSV (denní)</a>
            <?php endif; ?>
            <?php if ($providerKey === 'facebook_pages' || $providerKey === 'instagram_business'): ?>
            <a class="button-secondary" href="social-import.php?connection_id=<?= (int) $id ?>" title="Doplní z exportu Business Suite, co API nedává (dosah, organické a placené), a obnoví čísla starších příspěvků">Import obsahu z CSV</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($id): ?>
    <div class="admin-card-body">
        <?php
        $hasToken = allstat_secret_present($connection['access_token_enc'] ?? null);
        $backfillDone = !empty($connection['backfill_completed_at']);
        if ($hasToken && !$backfillDone):
        ?>
        <div class="notice notice-info backfill-cta">
            <strong>Napojení je aktivní, ale historie zatím nebyla stažena.</strong>
            Klikni na <em>„Stáhnout historii (16 měsíců)"</em> nahoře pro jednorázový import. Poté stačí „Stáhnout aktuální data" (nebo cron).
        </div>
        <?php elseif ($backfillDone): ?>
        <div class="notice notice-ok backfill-cta">Historie stažena <?= h($connection['backfill_completed_at']) ?>. Aktuálnost udržuje „Stáhnout aktuální data" (nebo denní cron).</div>
        <?php endif; ?>
        <p class="form-help" style="margin:0 0 6px;">
            <strong>Ruční aktualizace</strong> (cron zatím neběží): tlačítko <em>„Stáhnout aktuální data (7 dní)"</em> stáhne posledních 7 dní <strong>včetně dneška a včerejška</strong>.
            Klikat můžeš klidně i víckrát denně, dny se jen <strong>přepíšou aktuálními hodnotami, nic se neduplikuje</strong>.
            Když některý den zapomeneš, příští stažení dotáhne, co chybí (do 7 dní zpět). Starší období řeš přes „Stáhnout historii".
        </p>
        <div class="connection-result" data-connection-result hidden></div>
        <?php
        $quota = !empty($connection['quota_json']) ? json_decode((string) $connection['quota_json'], true) : null;
        $quotaUpdated = $connection['quota_updated_at'] ?? null;
        if (is_array($quota) && isset($quota['meta_usage_pct'])):
            $mpct = (float) $quota['meta_usage_pct'];
            $mlevel = $mpct >= 90 ? 'danger' : ($mpct >= 70 ? 'warn' : 'ok');
        ?>
        <section class="quota-panel">
            <header>
                <h3>Využití Meta API limitu</h3>
                <p>Stav po posledním syncu<?= $quotaUpdated ? ' (' . h($quotaUpdated) . ')' : '' ?>. Meta jede na <strong>klouzavém 1h okně</strong>, limit se průběžně uvolňuje (hodinu po nárazu je zase skoro na nule). Při ≥ 90 % se sync sám zastaví na ochranu limitu.</p>
            </header>
            <div class="quota-grid">
                <div class="quota-item">
                    <div class="quota-item-head">
                        <span class="quota-item-label">Spotřeba limitu</span>
                        <span class="quota-badge quota-badge-<?= $mlevel ?>"><?= number_format($mpct, 1, ',', ' ') ?> %</span>
                    </div>
                    <div class="quota-bar"><div class="quota-bar-fill quota-bar-<?= $mlevel ?>" style="width: <?= min(100, $mpct) ?>%"></div></div>
                    <div class="quota-item-foot">nejvyšší z volání / CPU času / celkového času</div>
                </div>
            </div>
        </section>
        <?php elseif (is_array($quota)):
            $quotaLabels = [
                'tokensPerDay' => 'Tokeny / den',
                'tokensPerHour' => 'Tokeny / hodinu',
                'tokensPerProjectPerHour' => 'Tokeny / projekt / hodinu',
                'concurrentRequests' => 'Souběžné requesty',
                'serverErrorsPerProjectPerHour' => 'Server errors / projekt / hodinu',
                'potentiallyThresholdedRequestsPerHour' => 'Thresholded requests / hodinu',
            ];
        ?>
        <section class="quota-panel">
            <header>
                <h3>Využití API kvóty <?= h($connection['source_name']) ?></h3>
                <p>Stav po posledním syncu<?= $quotaUpdated ? ' (' . h($quotaUpdated) . ')' : '' ?>. Kvóty se resetují každý den/hodinu.</p>
            </header>
            <div class="quota-grid">
                <?php foreach ($quotaLabels as $key => $label):
                    if (!isset($quota[$key])) continue;
                    $consumed = (int) ($quota[$key]['consumed'] ?? 0);
                    $remaining = (int) ($quota[$key]['remaining'] ?? 0);
                    $total = $consumed + $remaining;
                    $pct = $total > 0 ? min(100, round($consumed / $total * 100, 1)) : 0;
                    $level = $pct >= 90 ? 'danger' : ($pct >= 70 ? 'warn' : 'ok');
                ?>
                <div class="quota-item">
                    <div class="quota-item-head">
                        <span class="quota-item-label"><?= h($label) ?></span>
                        <span class="quota-badge quota-badge-<?= $level ?>"><?= $pct ?> %</span>
                    </div>
                    <div class="quota-bar"><div class="quota-bar-fill quota-bar-<?= $level ?>" style="width: <?= $pct ?>%"></div></div>
                    <div class="quota-item-foot">spotřebováno <?= number_format($consumed, 0, ',', ' ') ?> z <?= number_format($total, 0, ',', ' ') ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php elseif ($providerKey === 'ga4'): ?>
        <section class="quota-panel quota-panel-empty">
            <p>API kvóty se zobrazí po prvním proběhlém syncu.</p>
        </section>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="admin-card-body">
        <form method="post" class="form-stack">
            <?= allstat_csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) ($connection['id'] ?? 0) ?>">
            <input type="hidden" name="source_id" value="<?= (int) $selectedSourceId ?>">

            <div class="form-grid">
                <label>
                    <span>Web</span>
                    <select name="domain_id" required><?php foreach ($domains as $domain): ?><option value="<?= (int) $domain['id'] ?>" <?= (int) ($connection['domain_id'] ?? 0) === (int) $domain['id'] ? 'selected' : '' ?>><?= h($domain['name']) ?> (<?= h($domain['url']) ?>)</option><?php endforeach; ?></select>
                </label>
                <label>
                    <span>Název účtu <small>(volitelné)</small></span>
                    <input type="text" name="account_label" value="<?= h($connection['account_label'] ?? '') ?>" placeholder="<?= h($selectedSource['name'] ?? '') ?>" autocomplete="off">
                    <small class="field-help">Jak se napojení zobrazí v přehledu (např. název stránky/účtu).</small>
                </label>
            </div>

            <div class="form-grid">
                <?php foreach ($schema['fields'] as $f):
                    $type = $f['type'] ?? 'text';
                ?>
                <label>
                    <span><?= h($f['label']) ?><?php if (!empty($f['required'])): ?> <small class="field-req">· nutné</small><?php else: ?> <small>(volitelné)</small><?php endif; ?></span>
                    <input type="<?= h($type) ?>" name="<?= h($f['name']) ?>" value="<?= h($basicValue($f)) ?>" placeholder="<?= h($basicPlaceholder($f)) ?>" autocomplete="<?= $type === 'password' ? 'new-password' : 'off' ?>">
                    <?php if (!empty($f['help'])): ?><small class="field-help"><?= h($f['help']) ?></small><?php endif; ?>
                </label>
                <?php endforeach; ?>
            </div>

            <?php if ($connect === 'oauth'): ?>
            <div class="code-box">
                <strong>OAuth callback URL</strong> (zkopíruj do nastavení OAuth aplikace providera, pole „Authorized redirect URLs/URIs"; přesné místo viz „Podrobný návod" níže):<br>
                <?= h($callbackUrl) ?>
            </div>
            <?php if (!$id): ?><p class="form-help">Po uložení se nahoře objeví tlačítko <strong>„Spustit OAuth"</strong>: tím se přihlásíš a uloží se tokeny.</p><?php endif; ?>
            <?php elseif ($connect === 'token'): ?>
            <p class="form-help"><?= h($selectedSource['name'] ?? 'Služba') ?> nepoužívá OAuth, stačí API token výše. Po uložení dej <strong>„Otestovat napojení"</strong> a pak „Stáhnout historii".</p>
            <?php elseif ($connect === 'meta'): ?>
            <div class="notice notice-info">
                Tohle napojení se připojuje a obnovuje přes <strong>„Připojit přes Meta"</strong>: jedním přihlášením přes Facebook připojíš stránky, Instagram i reklamní účty (App ID/Secret zadáváš jen jednou). Ruční vyplňování polí tady většinou není potřeba.
                <a href="meta-connect.php">Otevřít „Připojit přes Meta" →</a>
            </div>
            <?php endif; ?>

            <details class="admin-card-collapsible form-advanced">
                <summary>
                    <div>
                        <h2>Pokročilé nastavení</h2>
                        <p>Endpointy, scopes, tokeny a stav napojení. Většinou není potřeba sem chodit, appka to vyplní sama z OAuthu.</p>
                    </div>
                </summary>
                <div class="admin-card-body">
                    <div class="form-grid">
                        <?php
                        $advInput('external_account_id', 'Externí account ID', 'text', 'act_123 / urn:li:organization:123');
                        $advInput('client_id', 'Client ID', 'text');
                        $advInput('client_secret', 'Client secret', 'password');
                        $advInput('access_token', $connect === 'token' ? 'API token' : 'Access token', 'password');
                        $advInput('refresh_token', 'Refresh token', 'password');
                        $advInput('auth_url', 'Authorization URL', 'url');
                        $advInput('token_url', 'Token URL', 'url');
                        $advInput('api_base_url', 'API base URL', 'url');
                        $advInput('token_expires_at', 'Token expiruje', 'datetime-local');
                        $advInput('last_sync_at', 'Poslední sync', 'datetime-local');
                        ?>
                        <?php if (!in_array('status', $basicNames, true)): ?>
                        <label><span>Stav</span><select name="status"><option value="ok" <?= ($connection['status'] ?? '') === 'ok' ? 'selected' : '' ?>>OK</option><option value="warning" <?= ($connection['status'] ?? 'warning') === 'warning' ? 'selected' : '' ?>>Varování</option><option value="error" <?= ($connection['status'] ?? '') === 'error' ? 'selected' : '' ?>>Chyba</option></select></label>
                        <?php endif; ?>
                        <label><span>Zapnuto</span><select name="is_enabled"><option value="1" <?= (int) ($connection['is_enabled'] ?? 1) === 1 ? 'selected' : '' ?>>Ano</option><option value="0" <?= isset($connection['is_enabled']) && (int) $connection['is_enabled'] === 0 ? 'selected' : '' ?>>Ne</option></select></label>
                    </div>
                    <?php if (!in_array('scopes', $basicNames, true)): ?>
                    <label><span>OAuth scopes</span><textarea name="scopes"><?= h($connection['scopes'] ?? '') ?></textarea></label>
                    <?php endif; ?>
                    <?php if (!$hasStructuredConfig): ?>
                    <label><span>Doplňková konfigurace JSON</span><textarea name="config_json" placeholder='{"klic":"hodnota"}'><?= h($connection['config_json'] ?? '') ?></textarea></label>
                    <?php endif; ?>
                    <label><span>Poznámka / health detail</span><textarea name="note"><?= h($connection['note'] ?? '') ?></textarea></label>
                </div>
            </details>

            <?php if ($guide): ?>
            <details class="admin-card-collapsible form-guide"<?= $providerKey === 'linkedin_company' ? ' open' : '' ?>>
                <summary>
                    <div>
                        <h2>Podrobný návod krok za krokem</h2>
                        <p>Kde vzít údaje a jak projít OAuth pro „<?= h($selectedSource['name'] ?? '') ?>".</p>
                    </div>
                </summary>
                <div class="admin-card-body">
                    <section class="provider-guide">
                        <?php if (!empty($guide['pricing_note'])): ?><p class="provider-guide-pricing"><?= h($guide['pricing_note']) ?></p><?php endif; ?>
                        <?php if (!empty($guide['token_note'])): ?><p class="provider-guide-token"><?= h($guide['token_note']) ?></p><?php endif; ?>
                        <dl class="provider-guide-meta">
                            <?php if (!empty($guide['console_url'])): ?>
                            <div><dt>Konzole</dt><dd><a href="<?= h($guide['console_url']) ?>" target="_blank" rel="noopener"><?= h($guide['console_label'] ?? $guide['console_url']) ?></a></dd></div>
                            <?php endif; ?>
                            <?php if (!empty($guide['enable_apis'])): ?>
                            <div><dt>Povolit API</dt><dd><?= h(implode(', ', $guide['enable_apis'])) ?></dd></div>
                            <?php endif; ?>
                            <?php if (!empty($guide['property_id_format'])): ?>
                            <div><dt>Formát ID</dt><dd><code><?= h($guide['property_id_format']) ?></code></dd></div>
                            <?php endif; ?>
                            <?php if (!empty($guide['property_id_where'])): ?>
                            <div><dt>Kde ID najít</dt><dd><?= h($guide['property_id_where']) ?></dd></div>
                            <?php endif; ?>
                            <?php if (!empty($selectedSource['docs_url'])): ?>
                            <div><dt>Dokumentace</dt><dd><a href="<?= h($selectedSource['docs_url']) ?>" target="_blank" rel="noopener"><?= h($selectedSource['docs_url']) ?></a></dd></div>
                            <?php endif; ?>
                        </dl>
                        <?php if (!empty($guide['steps'])): ?>
                        <ol class="provider-guide-steps">
                            <?php foreach ($guide['steps'] as $step): ?><li><?= h($step) ?></li><?php endforeach; ?>
                        </ol>
                        <?php endif; ?>
                        <?php if (!empty($guide['troubleshooting'])): ?>
                        <h3 class="provider-guide-trouble-title">Časté chyby a jak je vyřešit</h3>
                        <ul class="provider-guide-trouble">
                            <?php foreach ($guide['troubleshooting'] as $tip): ?><li><?= h($tip) ?></li><?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </section>
                </div>
            </details>
            <?php endif; ?>

            <div class="form-actions">
                <button class="button-primary" type="submit">Uložit napojení</button>
                <a class="button-secondary" href="sources.php">Zpět na zdroje</a>
            </div>
        </form>
    </div>
</div>
<?php allstat_admin_footer($config); ?>
