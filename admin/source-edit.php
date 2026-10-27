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
    allstat_flash('info', t('Vyber službu, kterou chceš připojit.'));
    allstat_redirect($config, 'admin/sources.php' . ($domainId ? '?domain_id=' . $domainId : ''));
}

$providerKey = (string) ($selectedSource['provider_key'] ?? '');
$schema = allstat_provider_form_schema($providerKey);
if ($providerKey === 'microsoft_ads' && allstat_microsoft_ads_identity_provider($connection) === 'Microsoft') {
    foreach ($schema['fields'] as &$field) {
        if ($field['name'] === 'client_id') {
            $field['label'] = 'Client ID (Microsoft Entra)';
            $field['help'] = t('Microsoft Entra → App registrations → Application (client) ID.');
        }
    }
    unset($field);
}
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
        return t('uloženo, nechte prázdné pro zachování');
    }
    if (!empty($f['secret_config']) && !empty($existingConfig[$f['config']])) {
        return t('uloženo, nechte prázdné pro zachování');
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
        $placeholder = t('uloženo, nechte prázdné pro zachování');
    }
    echo '<label class="field"><span class="field-label">' . h($label) . '</span><input class="input" type="' . h($type) . '" name="' . h($name) . '" value="' . h($value) . '" placeholder="' . h($placeholder) . '" autocomplete="' . ($isSecret ? 'new-password' : 'off') . '"></label>';
};

// Dárci OAuth údajů: jeden za každý odlišný Client ID (= Google projekt). Víc tlačítek jen když jsou projekty dva.
$oauthDonors = ($id && $connect === 'oauth') ? allstat_oauth_donors($pdo, (int) $id, $providerKey) : [];
// Google účty (admin/google-accounts.php): GA4 a Search Console se můžou přihlašovat účtem místo vlastního Client ID.
$googleAccounts = in_array($providerKey, ['ga4', 'gsc'], true)
    ? allstat_fetch_all($pdo, "SELECT id, label, account_email FROM allstat_oauth_accounts WHERE provider = 'google' ORDER BY label, id")
    : [];
$connectionAccount = null;
foreach ($googleAccounts as $googleAccount) {
    if ((int) $googleAccount['id'] === (int) ($connection['oauth_account_id'] ?? 0)) {
        $connectionAccount = $googleAccount;
    }
}
if ($connectionAccount) {
    $oauthDonors = [];
}
$title = $id ? (string) $connection['source_name'] : t('Nové napojení – {name}', ['name' => $selectedSource['name'] ?? t('služba')]);
allstat_admin_header($id ? t('Upravit zdroj') : t('Nové napojení'), 'sources', $user, $config, allstat_help_anchor_for_provider($providerKey), [
    'sub' => $title,
    'actions' => '<a class="btn btn-secondary" href="sources.php">' . '<span>' . h(t('Zpět na zdroje')) . '</span></a>',
]);
?>
<section class="card" aria-labelledby="source-title">
    <div class="card-head">
        <div class="cell">
            <?= allstat_source_tile($providerKey, 'md') ?>
            <div>
                <h2 class="card-title" id="source-title"><?= h($title) ?></h2>
                <?php if (!empty($schema['intro'])): ?><p class="card-desc"><?= h($schema['intro']) ?></p><?php endif; ?>
            </div>
        </div>
    </div>
    <?php if ($id): ?>
    <div class="card-body">
        <div class="form-actions">
            <?php if ($connectionAccount): ?><a class="btn btn-secondary" href="google-accounts.php" title="<?= h(t('Přihlášení a tokeny se spravují u účtu')) ?>"><?= allstat_icon('key-round', 'icon-sm') ?><span><?= h(t('Google účet: {label}', ['label' => $connectionAccount['label']])) ?></span></a>
            <?php elseif ($connect === 'oauth'): ?><a class="btn btn-primary" href="oauth-start.php?connection_id=<?= (int) $id ?>"><?= allstat_icon('key-round', 'icon-sm') ?><span><?= h(t('Spustit OAuth')) ?></span></a><?php endif; ?>
            <?php if ($connect === 'meta'): ?><a class="btn btn-primary" href="meta-connect.php"><?= allstat_meta_button_icon() ?><span><?= h(t('Připojit přes Meta')) ?></span></a><?php endif; ?>
            <?php foreach ($oauthDonors as $oauthDonor): ?>
            <form method="post">
                <?= allstat_csrf_field() ?>
                <input type="hidden" name="action" value="inherit_oauth">
                <input type="hidden" name="id" value="<?= (int) $id ?>">
                <input type="hidden" name="donor_id" value="<?= (int) $oauthDonor['id'] ?>">
                <button type="submit" class="btn btn-secondary" title="<?= h(t('Zkopíruje Client ID i Client secret z napojení „{label}" (Client ID {clientId}…). {hint}', ['label' => $oauthDonor['label'], 'clientId' => substr((string) $oauthDonor['client_id'], 0, 12), 'hint' => count($oauthDonors) > 1 ? t('Každé tlačítko = jiný Google projekt (OAuth aplikace); vyber ten, ve kterém má účet pro přihlášení přístup.') : t('Nemusíš je hledat ručně.')])) ?>"><?= h(t('Převzít údaje z „{name}"', ['name' => count($oauthDonors) > 1 ? $oauthDonor['label'] : $oauthDonor['name']])) ?></button>
            </form>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</section>

<?php if ($id): ?>
<section class="card" id="sync" aria-labelledby="sync-title">
    <div class="card-head">
        <div>
            <h2 class="card-title" id="sync-title"><?= h(t('Synchronizace')) ?></h2>
        </div>
    </div>
    <div class="card-body">
        <div class="section">
            <?php
            $hasToken = allstat_secret_present($connection['access_token_enc'] ?? null) || $connectionAccount;
            $backfillDone = !empty($connection['backfill_completed_at']);
            if ($hasToken && !$backfillDone):
            ?>
            <div class="callout">
                <?= allstat_icon('info', 'icon-sm') ?>
                <div>
                    <strong><?= h(t('Napojení je aktivní, ale historie zatím nebyla stažena.')) ?></strong>
                    <?= t('Klikni na <em>„Stáhnout historii (16 měsíců)"</em> níže pro jednorázový import. Poté stačí „Stáhnout aktuální data" (nebo cron).') ?>
                </div>
            </div>
            <?php elseif ($backfillDone): ?>
            <div class="callout tone-up"><?= allstat_icon('check', 'icon-sm') ?><div><?= h(t('Historie stažena {date}. Aktuálnost udržuje „Stáhnout aktuální data" (nebo denní cron).', ['date' => $connection['backfill_completed_at']])) ?></div></div>
            <?php endif; ?>
            <p class="field-help">
                <?= t('<strong>Ruční aktualizace</strong> (cron zatím neběží): tlačítko <em>„Stáhnout aktuální data (7 dní)"</em> stáhne posledních 7 dní <strong>včetně dneška a včerejška</strong>.') ?>
                <?= t('Klikat můžeš klidně i víckrát denně, dny se jen <strong>přepíšou aktuálními hodnotami, nic se neduplikuje</strong>.') ?>
                <?= t('Když některý den zapomeneš, příští stažení dotáhne, co chybí (do 7 dní zpět). Starší období řeš přes „Stáhnout historii".') ?>
            </p>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" data-connection-test="<?= (int) $id ?>" data-csrf="<?= h(allstat_csrf_token()) ?>"><?= h(t('Otestovat napojení')) ?></button>
                <button type="button" class="btn btn-primary" data-connection-sync="<?= (int) $id ?>" data-csrf="<?= h(allstat_csrf_token()) ?>" title="<?= h(t('Stáhne posledních 7 dní VČETNĚ dneška a včerejška. Bezpečné kdykoli: existující dny jen přepíše aktuálními hodnotami, nic neduplikuje.')) ?>"><?= h(t('Stáhnout aktuální data (7 dní)')) ?></button>
                <button type="button" class="btn btn-secondary" data-connection-backfill="<?= (int) $id ?>" data-csrf="<?= h(allstat_csrf_token()) ?>"><?= h(t('Stáhnout historii (16 měsíců)')) ?></button>
                <?php if ($providerKey === 'clarity'): ?>
                <a class="btn btn-secondary" href="clarity-import.php?domain_id=<?= (int) $connection['domain_id'] ?>"><?= h(t('Import CSV (denní)')) ?></a>
                <?php endif; ?>
                <?php if ($providerKey === 'facebook_pages' || $providerKey === 'instagram_business'): ?>
                <a class="btn btn-secondary" href="social-import.php?connection_id=<?= (int) $id ?>" title="<?= h(t('Doplní z exportu Business Suite, co API nedává (dosah, organické a placené), a obnoví čísla starších příspěvků')) ?>"><?= h(t('Import obsahu z CSV')) ?></a>
                <?php endif; ?>
            </div>
            <div class="callout" data-connection-result role="status" hidden></div>
        </div>
    </div>
</section>

<?php
$quota = !empty($connection['quota_json']) ? json_decode((string) $connection['quota_json'], true) : null;
$quotaUpdated = $connection['quota_updated_at'] ?? null;
$levelStatus = static fn (string $level): string => $level === 'danger' ? 'error' : ($level === 'warn' ? 'warning' : 'ok');
if (is_array($quota) && isset($quota['meta_usage_pct'])):
    $mpct = (float) $quota['meta_usage_pct'];
    $mlevel = $mpct >= 90 ? 'danger' : ($mpct >= 70 ? 'warn' : 'ok');
?>
<section class="card" aria-labelledby="quota-title">
    <div class="card-head">
        <div>
            <h2 class="card-title" id="quota-title"><?= h(t('Využití Meta API limitu')) ?></h2>
            <p class="card-desc"><?= t('Stav po posledním syncu{updated}. Meta jede na <strong>klouzavém 1h okně</strong>, limit se průběžně uvolňuje (hodinu po nárazu je zase skoro na nule). Při ≥ 90 % se sync sám zastaví na ochranu limitu.', ['updated' => $quotaUpdated ? ' (' . h($quotaUpdated) . ')' : '']) ?></p>
        </div>
    </div>
    <div class="card-body">
        <div class="meters">
            <div class="meter">
                <div class="meter-top">
                    <span class="meter-name"><?= h(t('Spotřeba limitu')) ?></span>
                    <?= allstat_status_pill($levelStatus($mlevel), allstat_number($mpct, 1) . ' %') ?>
                </div>
                <div class="track"><div class="track-fill <?= ['ok' => 'is-up', 'warn' => 'is-warn', 'danger' => 'is-down'][$mlevel] ?? '' ?>" style="width: <?= min(100, $mpct) ?>%"></div></div>
                <span class="block-desc"><?= h(t('nejvyšší z volání / CPU času / celkového času')) ?></span>
            </div>
        </div>
    </div>
</section>
<?php elseif (is_array($quota)):
    $quotaLabels = [
        'tokensPerDay' => t('Tokeny / den'),
        'tokensPerHour' => t('Tokeny / hodinu'),
        'tokensPerProjectPerHour' => t('Tokeny / projekt / hodinu'),
        'concurrentRequests' => t('Souběžné requesty'),
        'serverErrorsPerProjectPerHour' => t('Server errors / projekt / hodinu'),
        'potentiallyThresholdedRequestsPerHour' => t('Thresholded requests / hodinu'),
    ];
?>
<section class="card" aria-labelledby="quota-title">
    <div class="card-head">
        <div>
            <h2 class="card-title" id="quota-title"><?= h(t('Využití API kvóty {name}', ['name' => $connection['source_name']])) ?></h2>
            <p class="card-desc"><?= t('Stav po posledním syncu{updated}. Kvóty se resetují každý den/hodinu.', ['updated' => $quotaUpdated ? ' (' . h($quotaUpdated) . ')' : '']) ?></p>
        </div>
    </div>
    <div class="card-body">
        <div class="meters">
            <?php foreach ($quotaLabels as $key => $label):
                if (!isset($quota[$key])) continue;
                $consumed = (int) ($quota[$key]['consumed'] ?? 0);
                $remaining = (int) ($quota[$key]['remaining'] ?? 0);
                $total = $consumed + $remaining;
                $pct = $total > 0 ? min(100, round($consumed / $total * 100, 1)) : 0;
                $level = $pct >= 90 ? 'danger' : ($pct >= 70 ? 'warn' : 'ok');
            ?>
            <div class="meter">
                <div class="meter-top">
                    <span class="meter-name"><?= h($label) ?></span>
                    <?= allstat_status_pill($levelStatus($level), $pct . ' %') ?>
                </div>
                <div class="track"><div class="track-fill <?= ['ok' => 'is-up', 'warn' => 'is-warn', 'danger' => 'is-down'][$level] ?? '' ?>" style="width: <?= $pct ?>%"></div></div>
                <span class="block-desc"><?= h(t('spotřebováno {used} z {total}', ['used' => allstat_number($consumed), 'total' => allstat_number($total)])) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php elseif ($providerKey === 'ga4'): ?>
<div class="callout"><?= allstat_icon('info', 'icon-sm') ?><div><?= h(t('API kvóty se zobrazí po prvním proběhlém syncu.')) ?></div></div>
<?php endif; ?>
<?php endif; ?>

<form method="post" class="section">
    <?= allstat_csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) ($connection['id'] ?? 0) ?>">
    <input type="hidden" name="source_id" value="<?= (int) $selectedSourceId ?>">

    <section class="card" aria-labelledby="form-title">
        <div class="card-head">
            <div>
                <h2 class="card-title" id="form-title"><?= h($id ? t('Upravit napojení') : t('Nové napojení')) ?></h2>
            </div>
        </div>
        <div class="card-body form-grid">
            <label class="field">
                <span class="field-label"><?= h(t('Web')) ?></span>
                <select class="input" name="domain_id" required><?php foreach ($domains as $domain): ?><option value="<?= (int) $domain['id'] ?>" <?= (int) ($connection['domain_id'] ?? 0) === (int) $domain['id'] ? 'selected' : '' ?>><?= h($domain['name']) ?> (<?= h($domain['url']) ?>)</option><?php endforeach; ?></select>
            </label>
            <label class="field">
                <span class="field-label"><?= h(t('Název účtu')) ?> <small class="is-muted">(<?= h(t('volitelné')) ?>)</small></span>
                <input class="input" type="text" name="account_label" value="<?= h($connection['account_label'] ?? '') ?>" placeholder="<?= h($selectedSource['name'] ?? '') ?>" autocomplete="off">
                <span class="field-help"><?= h(t('Jak se napojení zobrazí v přehledu (např. název stránky/účtu).')) ?></span>
            </label>

            <?php if ($googleAccounts): ?>
            <label class="field field-full">
                <span class="field-label"><?= h(t('Přihlášení')) ?></span>
                <select class="input" name="oauth_account_id">
                    <option value="0"><?= h(t('Vlastní OAuth u tohoto napojení (Client ID a „Spustit OAuth“)')) ?></option>
                    <?php foreach ($googleAccounts as $googleAccount): ?>
                    <option value="<?= (int) $googleAccount['id'] ?>"<?= $connectionAccount && (int) $connectionAccount['id'] === (int) $googleAccount['id'] ? ' selected' : '' ?>><?= h(t('Google účet: {label}', ['label' => $googleAccount['label']])) ?><?= $googleAccount['account_email'] ? ' (' . h($googleAccount['account_email']) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="field-help"><?= t('S Google účtem se Client ID ani tokeny u napojení nevyplňují, přihlášení se spravuje v <a href="google-accounts.php">Google účtech</a>.') ?></span>
            </label>
            <?php endif; ?>

            <?php foreach ($schema['fields'] as $f):
                $type = $f['type'] ?? 'text';
            ?>
            <label class="field">
                <span class="field-label"><?= h($f['label']) ?><?php if (!empty($f['required'])): ?> <small class="is-muted">· <?= h(t('nutné')) ?></small><?php else: ?> <small class="is-muted">(<?= h(t('volitelné')) ?>)</small><?php endif; ?></span>
                <input class="input" type="<?= h($type) ?>" name="<?= h($f['name']) ?>" value="<?= h($basicValue($f)) ?>" placeholder="<?= h($basicPlaceholder($f)) ?>" autocomplete="<?= $type === 'password' ? 'new-password' : 'off' ?>">
                <?php if (!empty($f['help'])): ?><span class="field-help"><?= h($f['help']) ?></span><?php endif; ?>
            </label>
            <?php endforeach; ?>

            <?php if ($connect === 'oauth' && !$connectionAccount): ?>
            <div class="field field-full">
                <span class="field-help"><?= t('<strong>OAuth callback URL</strong> (zkopíruj do nastavení OAuth aplikace providera, pole „Authorized redirect URLs/URIs"; přesné místo viz „Podrobný návod" níže):') ?></span>
                <code class="code"><?= h($callbackUrl) ?></code>
            </div>
            <?php if (!$id): ?><p class="field-help field-full"><?= t('Po uložení se nahoře objeví tlačítko <strong>„Spustit OAuth"</strong>: tím se přihlásíš a uloží se tokeny.') ?></p><?php endif; ?>
            <?php elseif ($connect === 'token'): ?>
            <p class="field-help field-full"><?= t('{name} nepoužívá OAuth, stačí API token výše. Po uložení dej <strong>„Otestovat napojení"</strong> a pak „Stáhnout historii".', ['name' => h($selectedSource['name'] ?? t('Služba'))]) ?></p>
            <?php elseif ($connect === 'meta'): ?>
            <div class="callout field-full">
                <?= allstat_icon('info', 'icon-sm') ?>
                <div>
                    <?= t('Tohle napojení se připojuje a obnovuje přes <strong>„Připojit přes Meta"</strong>: jedním přihlášením přes Facebook připojíš stránky, Instagram i reklamní účty (App ID/Secret zadáváš jen jednou). Ruční vyplňování polí tady většinou není potřeba.') ?>
                    <a href="meta-connect.php"><?= h(t('Otevřít „Připojit přes Meta" →')) ?></a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <details class="card note form-advanced">
        <summary>
            <?= allstat_icon('settings', 'icon') ?>
            <span><?= h(t('Pokročilé nastavení')) ?></span>
            <?= allstat_icon('chevron-down', 'note-chev') ?>
        </summary>
        <div class="note-body">
            <p><?= h(t('Endpointy, scopes, tokeny a stav napojení. Většinou není potřeba sem chodit, appka to vyplní sama z OAuthu.')) ?></p>
            <div class="form-grid">
                <?php
                $advInput('external_account_id', t('Externí account ID'), 'text', 'act_123 / urn:li:organization:123');
                $advInput('client_id', 'Client ID', 'text');
                $advInput('client_secret', 'Client secret', 'password');
                $advInput('access_token', $connect === 'token' ? 'API token' : 'Access token', 'password');
                $advInput('refresh_token', 'Refresh token', 'password');
                $advInput('auth_url', 'Authorization URL', 'url');
                $advInput('token_url', 'Token URL', 'url');
                $advInput('api_base_url', 'API base URL', 'url');
                $advInput('token_expires_at', t('Token expiruje'), 'datetime-local');
                $advInput('last_sync_at', t('Poslední sync'), 'datetime-local');
                ?>
                <?php if (!in_array('status', $basicNames, true)): ?>
                <label class="field"><span class="field-label"><?= h(t('Stav')) ?></span><select class="input" name="status"><option value="ok" <?= ($connection['status'] ?? '') === 'ok' ? 'selected' : '' ?>>OK</option><option value="warning" <?= ($connection['status'] ?? 'warning') === 'warning' ? 'selected' : '' ?>><?= h(t('Varování')) ?></option><option value="error" <?= ($connection['status'] ?? '') === 'error' ? 'selected' : '' ?>><?= h(t('Chyba')) ?></option></select></label>
                <?php endif; ?>
                <label class="field"><span class="field-label"><?= h(t('Zapnuto')) ?></span><select class="input" name="is_enabled"><option value="1" <?= (int) ($connection['is_enabled'] ?? 1) === 1 ? 'selected' : '' ?>><?= h(t('Ano')) ?></option><option value="0" <?= isset($connection['is_enabled']) && (int) $connection['is_enabled'] === 0 ? 'selected' : '' ?>><?= h(t('Ne')) ?></option></select></label>
                <?php if (!in_array('scopes', $basicNames, true)): ?>
                <label class="field field-full"><span class="field-label">OAuth scopes</span><textarea class="input" name="scopes"><?= h($connection['scopes'] ?? '') ?></textarea></label>
                <?php endif; ?>
                <?php if (!$hasStructuredConfig): ?>
                <label class="field field-full"><span class="field-label"><?= h(t('Doplňková konfigurace JSON')) ?></span><textarea class="input" name="config_json" placeholder='{"klic":"hodnota"}'><?= h($connection['config_json'] ?? '') ?></textarea></label>
                <?php endif; ?>
                <label class="field field-full"><span class="field-label"><?= h(t('Poznámka / health detail')) ?></span><textarea class="input" name="note"><?= h($connection['note'] ?? '') ?></textarea></label>
            </div>
        </div>
    </details>

    <?php if ($guide): ?>
    <details class="card note form-guide"<?= $providerKey === 'linkedin_company' ? ' open' : '' ?>>
        <summary>
            <?= allstat_icon('info', 'icon') ?>
            <span><?= h(t('Podrobný návod krok za krokem')) ?></span>
            <?= allstat_icon('chevron-down', 'note-chev') ?>
        </summary>
        <div class="note-body">
            <p><?= h(t('Kde vzít údaje a jak projít OAuth pro „{name}".', ['name' => $selectedSource['name'] ?? ''])) ?></p>
            <p><?= t('Postup s obrázky a řešení častých chyb najdeš i v návodu: {link}.', ['link' => allstat_help_link(allstat_help_anchor_for_provider($providerKey), t('napojení služby krok za krokem'))]) ?></p>
            <?php if (!empty($guide['pricing_note'])): ?><p><?= h($guide['pricing_note']) ?></p><?php endif; ?>
            <?php if (!empty($guide['token_note'])): ?><p><?= h($guide['token_note']) ?></p><?php endif; ?>
            <div class="rows">
                <?php if (!empty($guide['console_url'])): ?>
                <div class="row"><span class="row-label"><?= h(t('Konzole')) ?></span><a class="link-more" href="<?= h($guide['console_url']) ?>" target="_blank" rel="noopener"><?= h($guide['console_label'] ?? $guide['console_url']) ?></a></div>
                <?php endif; ?>
                <?php if (!empty($guide['enable_apis'])): ?>
                <div class="row"><span class="row-label"><?= h(t('Povolit API')) ?></span><span><?= h(implode(', ', $guide['enable_apis'])) ?></span></div>
                <?php endif; ?>
                <?php if (!empty($guide['property_id_format'])): ?>
                <div class="row"><span class="row-label"><?= h(t('Formát ID')) ?></span><code><?= h($guide['property_id_format']) ?></code></div>
                <?php endif; ?>
                <?php if (!empty($guide['property_id_where'])): ?>
                <div class="row"><span class="row-label"><?= h(t('Kde ID najít')) ?></span><span><?= h($guide['property_id_where']) ?></span></div>
                <?php endif; ?>
                <?php if (!empty($selectedSource['docs_url'])): ?>
                <div class="row"><span class="row-label"><?= h(t('Dokumentace')) ?></span><a class="link-more" href="<?= h($selectedSource['docs_url']) ?>" target="_blank" rel="noopener"><?= h($selectedSource['docs_url']) ?></a></div>
                <?php endif; ?>
            </div>
            <?php if (!empty($guide['steps'])): ?>
            <ol class="steps">
                <?php foreach ($guide['steps'] as $step): ?><li><?= h($step) ?></li><?php endforeach; ?>
            </ol>
            <?php endif; ?>
            <?php if (!empty($guide['troubleshooting'])): ?>
            <h3 class="block-title"><?= h(t('Časté chyby a jak je vyřešit')) ?></h3>
            <ul>
                <?php foreach ($guide['troubleshooting'] as $tip): ?><li><?= h($tip) ?></li><?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </details>
    <?php endif; ?>

    <div class="form-actions">
        <button class="btn btn-primary" type="submit"><?= h(t('Uložit napojení')) ?></button>
        <a class="btn btn-secondary" href="sources.php"><?= h(t('Zpět na zdroje')) ?></a>
    </div>
</form>
<?php allstat_admin_footer($config); ?>
