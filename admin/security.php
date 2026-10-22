<?php

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../lib/crypto.php';

$pdo = allstat_admin_require_database($pdo, $config);
$user = allstat_require_user($pdo, $config);
allstat_require_admin($user);

$messages = [];
$errors = [];
$encColumns = [
    'domain_sources' => ['client_secret_enc', 'access_token_enc', 'refresh_token_enc', 'config_json_enc'],
    'allstat_users' => ['totp_secret_enc'],
    'allstat_oauth_accounts' => ['access_token_enc', 'refresh_token_enc'],
];
// Šifrovaná tajemství v nastavení (Meta App Secret, Google OAuth klient). Bez nich by je rotace nechala
// zašifrovaná starým klíčem a po jeho zahození by nešla přečíst.
$encSettings = ['meta.app_secret_enc', 'google.client_secret_enc'];
$keyFilePath = dirname(__DIR__) . '/config-keys.php';

function allstat_write_keystore(string $path, array $keys): void
{
    $exported = "<?php\n\nreturn ['keys' => [\n";
    foreach ($keys as $key) {
        $exported .= "    " . var_export((string) $key, true) . ",\n";
    }
    $exported .= "]];\n";

    if (file_put_contents($path, $exported, LOCK_EX) === false) {
        throw new RuntimeException(t('Nepodařilo se zapsat {path}. Zkontroluj práva zápisu na server.', ['path' => $path]));
    }
    @chmod($path, 0640);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    allstat_csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'rotate') {
        $newKey = trim((string) ($_POST['new_key'] ?? ''));
        $confirm = (string) ($_POST['confirm'] ?? '');

        if (strlen($newKey) < 32) {
            $errors[] = t('Nový klíč musí mít alespoň 32 znaků.');
        } elseif ($newKey === ALLSTAT_DEFAULT_ENCRYPTION_KEY) {
            $errors[] = t('Nemůžeš použít výchozí klíč.');
        } elseif ($confirm !== 'ROTUJ') {
            $errors[] = t('Pro potvrzení napiš velkými písmeny: ROTUJ.');
        } else {
            $currentKeyPlain = (string) ($config['security']['encryption_keys'][0] ?? $config['security']['encryption_key'] ?? ALLSTAT_DEFAULT_ENCRYPTION_KEY);
            $dualConfig = ['security' => ['encryption_keys' => [$newKey, $currentKeyPlain]]];

            $plaintextBuffer = [];
            try {
                foreach ($encColumns as $table => $columns) {
                    foreach ($columns as $column) {
                        $rows = $pdo->query("SELECT id, $column FROM $table WHERE $column IS NOT NULL AND $column LIKE 'enc:%'")->fetchAll();
                        foreach ($rows as $row) {
                            $plain = allstat_decrypt_secret($row[$column], $config);
                            if ($plain === null) {
                                throw new RuntimeException(t('Nelze dešifrovat {table}.{column} id={id}.', ['table' => $table, 'column' => $column, 'id' => $row['id']]));
                            }
                            $plaintextBuffer[] = ['table' => $table, 'column' => $column, 'id' => $row['id'], 'plain' => $plain];
                        }
                    }
                }
                foreach ($encSettings as $settingKey) {
                    $stored = (string) allstat_setting($pdo, $settingKey, '');
                    if (!str_starts_with($stored, 'enc:')) {
                        continue;
                    }
                    $plain = allstat_decrypt_secret($stored, $config);
                    if ($plain === null) {
                        throw new RuntimeException(t('Nelze dešifrovat nastavení {key}.', ['key' => $settingKey]));
                    }
                    $plaintextBuffer[] = ['setting' => $settingKey, 'plain' => $plain];
                }

                allstat_write_keystore($keyFilePath, [$newKey, $currentKeyPlain]);

                $pdo->beginTransaction();
                $rotated = 0;
                foreach ($plaintextBuffer as $item) {
                    $newEnc = allstat_encrypt_secret($item['plain'], $dualConfig);
                    if (isset($item['setting'])) {
                        allstat_set_setting($pdo, $item['setting'], (string) $newEnc);
                    } else {
                        $stmt = $pdo->prepare("UPDATE {$item['table']} SET {$item['column']} = ? WHERE id = ?");
                        $stmt->execute([$newEnc, $item['id']]);
                    }
                    $rotated++;
                }
                $pdo->commit();

                allstat_write_keystore($keyFilePath, [$newKey]);

                allstat_audit($pdo, (int) $user['id'], null, 'encryption_key_rotated', sprintf('rows=%d', $rotated));
                $messages[] = t('✓ Rotováno {count} záznamů. Nový klíč je zapsaný v config-keys.php a aktivní. Banner zmizí po reloadu.', ['count' => $rotated]);
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = t('Rotace selhala: {message}', ['message' => $exception->getMessage()]);
            }
        }
    }
}

allstat_admin_header(t('Bezpečnost'), 'settings', $user, $config, 'bezpecnost');
$usesDefault = allstat_crypto_uses_default_key($config);
$keystoreExists = is_file($keyFilePath);
$suggestedKey = bin2hex(random_bytes(32));
?>
<div class="admin-card">
    <div class="admin-card-header"><h2><?= h(t('Šifrovací klíč')) ?></h2></div>
    <div class="admin-card-body">
        <?php foreach ($messages as $m): ?><div class="notice notice-ok"><?= h($m) ?></div><?php endforeach; ?>
        <?php foreach ($errors as $e): ?><div class="notice notice-error"><?= h($e) ?></div><?php endforeach; ?>

        <p><strong><?= h(t('Aktuální stav:')) ?></strong>
            <?php if ($usesDefault): ?>
                <span style="color:#dc2626"><?= h(t('⚠ DEFAULTNÍ klíč. Všechny secrets jsou dešifrovatelné kýmkoli s přístupem ke zdrojovému kódu (open-source).')) ?></span>
            <?php else: ?>
                <span style="color:#16a34a"><?= h(t('✓ Vlastní klíč')) ?></span> <?= h($keystoreExists ? t('(z config-keys.php)') : t('(z config.php / env var)')) ?>
            <?php endif; ?>
        </p>

        <p><?= t('Šifrované sloupce: <code>domain_sources.client_secret_enc</code>, <code>access_token_enc</code>, <code>refresh_token_enc</code>, <code>config_json_enc</code>, <code>allstat_users.totp_secret_enc</code>, <code>allstat_oauth_accounts.access_token_enc</code>, <code>refresh_token_enc</code> a nastavení <code>meta.app_secret_enc</code>, <code>google.client_secret_enc</code>.') ?></p>

        <h3 style="margin-top: 24px;"><?= h(t('Rotace klíče')) ?></h3>
        <p><?= h(t('Tlačítko níže provede atomicky:')) ?></p>
        <ol style="margin-bottom: 16px;">
            <li><?= h(t('Dešifruje všechny secrets aktuálním klíčem (do RAM, ne na disk).')) ?></li>
            <li><?= t('Zapíše <code>config-keys.php</code> s <strong>oběma</strong> klíči (nový + starý). Aplikace v tu chvíli umí dešifrovat data v obou stavech.') ?></li>
            <li><?= h(t('Re-šifruje všechny secrets v DB novým klíčem (transakční).')) ?></li>
            <li><?= t('Přepíše <code>config-keys.php</code> jen s novým klíčem (starý už nebude potřeba).') ?></li>
        </ol>
        <p style="font-size: 13px; color: var(--muted);"><?= h(t('Pokud krok 3 selže, transakce se vrátí a v keystore zůstanou oba klíče, aplikace dál funguje s daty zašifrovanými starým. Můžeš rotaci spustit znovu.')) ?></p>

        <form method="post" class="form-stack" data-confirm="<?= h(t('Spustit rotaci klíče?')) ?>">
            <?= allstat_csrf_field() ?>
            <input type="hidden" name="action" value="rotate">
            <label>
                <span><?= h(t('Nový klíč (předvyplněn náhodný 64 hex, můžeš nahradit vlastním)')) ?></span>
                <input type="text" name="new_key" required minlength="32" autocomplete="off" value="<?= h($suggestedKey) ?>" style="font-family: ui-monospace, monospace;">
            </label>
            <label>
                <span><?= t('Potvrzení, napiš velkými písmeny: <strong>ROTUJ</strong>') ?></span>
                <input type="text" name="confirm" required pattern="ROTUJ" autocomplete="off" placeholder="ROTUJ">
            </label>
            <div class="form-actions">
                <button class="button-primary" type="submit"><?= h(t('Rotovat klíč')) ?></button>
            </div>
        </form>

        <details style="margin-top: 20px;">
            <summary><?= h(t('Manuální cesta (bez UI)')) ?></summary>
            <p><?= h(t('Pokud nechceš použít tento formulář, klíč můžeš nastavit přímo:')) ?></p>
            <ol>
                <li><?= t('V hosting panelu nastav env proměnnou <code>ALLSTAT_ENCRYPTION_KEY=&lt;64 hex znaků&gt;</code>.') ?></li>
                <li><?= t('Nebo natvrdo v <code>config.php</code> přepiš <code>encryption_key</code>.') ?></li>
            </ol>
            <p><?= h(t('POZOR: pokud změníš klíč touto cestou bez re-encryptu, ZTRATÍŠ přístup k existujícím tokenům v DB (budou ve starém šifrování).')) ?></p>
        </details>
    </div>
</div>
<?php allstat_admin_footer($config); ?>
