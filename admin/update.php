<?php

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../lib/updater.php';

$pdo = allstat_admin_require_database($pdo, $config);
$user = allstat_require_user($pdo, $config);
// Aktualizace mění soubory aplikace: jen administrátor, i pro čtení.
allstat_require_admin($user);

// Adresa přihlášení: na ní updater po změně .htaccess ověří, že AllStat odpovídá (jinak změnu vrátí).
$selfTestUrl = allstat_update_selftest_url($config);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    allstat_csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'check') {
        $status = allstat_update_check($pdo, true);
        allstat_flash($status['error'] ? 'error' : 'ok', $status['error'] ?? ($status['available'] ? t('Je k dispozici verze {version}.', ['version' => $status['latest']['version']]) : t('Máš nejnovější verzi.')));
    } elseif ($action === 'install' || $action === 'reinstall') {
        if (empty($_POST['backup_ok'])) {
            allstat_flash('error', t('Potvrď, že máš zálohu databáze.'));
        } else {
            $result = allstat_update_install($pdo, (int) $user['id'], $action === 'reinstall', $selfTestUrl);
            allstat_flash($result['ok'] ? 'ok' : 'error', $result['message']);
            foreach ($result['warnings'] as $warning) {
                allstat_flash('warn', $warning);
            }
        }
    } elseif ($action === 'dismiss_new') {
        $file = (string) ($_POST['file'] ?? '');
        $dismissed = allstat_update_protected_dismiss($file);
        allstat_flash($dismissed ? 'ok' : 'error', $dismissed ? t('Soubor {file}.new je smazaný, tvůj {file} zůstává beze změny.', ['file' => $file]) : t('Soubor se nepodařilo smazat, zkus to přes FTP.'));
    } elseif ($action === 'rollback') {
        $result = allstat_update_rollback($pdo, (int) $user['id']);
        allstat_flash($result['ok'] ? 'ok' : 'error', $result['message']);
    }
    // Další požadavek už běží s novým kódem (a doplní databázi), proto vždy přesměrovat.
    allstat_redirect($config, 'admin/update.php');
}

$status = allstat_update_check($pdo, false, 10);
$installed = $status['installed'];
$latest = $status['latest'];
$preflight = $latest ? allstat_update_preflight($latest) : null;
$history = allstat_update_history($pdo);
$lastBackup = glob(allstat_update_root() . '/_update/backup-*', GLOB_ONLYDIR) ?: [];
$canRollback = $installed !== null && $lastBackup && ($history[0]['to'] ?? null) === $installed && empty($history[0]['rollback']);
$schemaOk = allstat_schema_is_current($pdo);
// Upravený .htaccess s čekající novou verzí (nechala ji tam starší verze updateru): doplnit pravidla automaticky.
$autofix = $installed !== null ? allstat_update_htaccess_autofix($pdo, $selfTestUrl) : null;
if ($autofix === 'merged') {
    $_SESSION['flashes'] = array_values(array_filter($_SESSION['flashes'] ?? [], static fn (array $flash): bool => !str_contains((string) ($flash['message'] ?? ''), '.htaccess')));
    allstat_flash('ok', t('Pravidla AllStatu v souboru .htaccess jsou doplněná automaticky, vlastní úpravy zůstaly beze změny.'));
}
$protectedPending = $installed !== null ? allstat_update_protected_pending() : [];
if ($autofix === 'failed' && in_array('.htaccess', array_column($protectedPending, 'file'), true)) {
    allstat_flash('warn', t('Pravidla do souboru .htaccess nešlo doplnit automaticky (AllStat pak neodpovídal nebo to nešlo ověřit), soubor zůstal beze změny. Co doplnit, ukazuje karta níže.'));
}
$fmtDate = static fn (?string $value): string => $value ? allstat_date_label($value, true) : t('zatím ne');

allstat_admin_header(t('Aktualizace'), 'update', $user, $config);
?>
<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2><?= h(t('Verze aplikace')) ?></h2>
            <p><?= h(t('Nové verze vydává autor na GitHubu. AllStat je kontroluje sám (nejvýš dvakrát denně) a nainstaluje je jedním tlačítkem.')) ?></p>
        </div>
        <?php if ($installed !== null): ?>
            <span class="status-badge status-<?= $status['available'] ? 'warning' : 'ok' ?>"><?= h($status['available'] ? t('Je nová verze') : t('Aktuální')) ?></span>
        <?php endif; ?>
    </div>
    <div class="admin-card-body">
        <?php if ($installed === null): ?>
            <p><?= t('Tahle instalace nepochází z veřejného balíčku (chybí soubor <code>VERSION</code>), aktualizace do ní nasazuje vývojář. Automatická aktualizace je jen pro instalace z <a href="https://github.com/ffscz/AllStat/releases" target="_blank" rel="noopener">vydání na GitHubu</a>.') ?></p>
        <?php else: ?>
            <dl class="update-facts">
                <div><dt><?= h(t('Nainstalovaná verze')) ?></dt><dd><?= h($installed) ?></dd></div>
                <div><dt><?= h(t('Nejnovější verze')) ?></dt><dd><?= h($latest['version'] ?? t('neznámá')) ?></dd></div>
                <div><dt><?= h(t('Poslední kontrola')) ?></dt><dd><?= h($fmtDate($status['checked_at'])) ?></dd></div>
                <div><dt><?= h(t('Databáze')) ?></dt><dd><?= h($schemaOk ? t('aktuální') : t('čeká na doplnění')) ?></dd></div>
            </dl>
            <?php if ($status['error']): ?><p class="notice notice-error"><?= h(t($status['error'])) ?></p><?php endif; ?>
            <form method="post" class="form-actions">
                <?= allstat_csrf_field() ?>
                <input type="hidden" name="action" value="check">
                <button class="button-secondary" type="submit"><?= h(t('Zkontrolovat teď')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php foreach ($protectedPending as $item): ?>
<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2><?= h(t('Soubor {file}: pravidla k doplnění', ['file' => $item['file']])) ?></h2>
            <p><?= t('AllStat funguje, tohle není chyba. Soubor <code>{file}</code> (pravidla webového serveru) má na hostingu vlastní úpravy, třeba od hostingu nebo blokování robotů, proto ho aktualizace nepřepsala. Automaticky pravidla doplnit nešlo, nová verze je proto vedle jako <code>{file}.new</code> a v tvém souboru z ní chybí bloky níže.', ['file' => h($item['file'])]) ?> <?= allstat_help_link('htaccess', t('Co s tím')) ?></p>
        </div>
        <span class="status-badge status-warning"><?= h(t('Ke kontrole')) ?></span>
    </div>
    <div class="admin-card-body">
        <?php foreach ($item['blocks'] as $block): ?>
            <?php if ($block['title'] !== ''): ?><h3 class="update-subhead"><?= h($block['title']) ?></h3><?php endif; ?>
            <pre class="update-code"><code><?= h($block['code']) ?></code></pre>
        <?php endforeach; ?>
        <p class="field-help"><?= t('Jak na to: přes FTP nebo správce souborů na hostingu otevři <code>{file}</code> a z <code>{file}.new</code> do něj zkopíruj celý blok od <code># BEGIN AllStat</code> po <code># END AllStat</code> (místo starších pravidel AllStatu), vlastní úpravy nech. Když žádné vlastní úpravy nepotřebuješ, smaž <code>{file}</code> a <code>{file}.new</code> přejmenuj na <code>{file}</code>. Jakmile pravidla v souboru budou, karta zmizí sama.', ['file' => h($item['file'])]) ?></p>
        <form method="post" class="form-actions" data-confirm="<?= h(t('Nechat svůj {file} a smazat {file}.new?', ['file' => $item['file']])) ?>">
            <?= allstat_csrf_field() ?>
            <input type="hidden" name="action" value="dismiss_new">
            <input type="hidden" name="file" value="<?= h($item['file']) ?>">
            <button class="button-secondary" type="submit"><?= h(t('Nechat svůj soubor a smazat {file}.new', ['file' => $item['file']])) ?></button>
        </form>
    </div>
</div>
<?php endforeach; ?>

<?php if ($status['available'] && $latest && $preflight): ?>
<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2><?= h(t('Nová verze {version}', ['version' => $latest['version']])) ?></h2>
            <p><?= $latest['published'] ? h(t('Vydáno {date}.', ['date' => $fmtDate($latest['published'])])) . ' ' : '' ?><?php if ($latest['html_url']): ?><a href="<?= h($latest['html_url']) ?>" target="_blank" rel="noopener"><?= h(t('Podrobnosti na GitHubu')) ?></a><?php endif; ?></p>
        </div>
    </div>
    <div class="admin-card-body">
        <?php if ($latest['notes'] !== ''): ?>
            <h3 class="update-subhead"><?= h(t('Co je nového')) ?></h3>
            <div class="update-notes"><?= nl2br(h($latest['notes'])) ?></div>
        <?php endif; ?>

        <h3 class="update-subhead"><?= h(t('Kontrola serveru')) ?></h3>
        <ul class="update-checks">
            <?php foreach ($preflight['checks'] as $check): ?>
                <li class="<?= $check['ok'] ? 'is-ok' : 'is-bad' ?>"><span aria-hidden="true"><?= $check['ok'] ? '✓' : '✕' ?></span> <?= h(t($check['label'])) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php if ($preflight['modified']): ?>
            <details class="update-modified">
                <summary><?= h(t('Ručně upravené soubory ({count}): přepíšou se, původní verze zůstanou v záloze', ['count' => count($preflight['modified'])])) ?></summary>
                <ul><?php foreach ($preflight['modified'] as $rel): ?><li><code><?= h($rel) ?></code></li><?php endforeach; ?></ul>
            </details>
        <?php endif; ?>

        <h3 class="update-subhead"><?= h(t('Co se stane')) ?></h3>
        <p class="field-help"><?= t('Balíček se stáhne a ověří jeho podpis (bez platného podpisu se nic nenainstaluje). Soubory, které se přepíšou, se zálohují, pak se vymění a databáze se při dalším načtení sama doplní (nové tabulky a sloupce, data se nemažou). Soubory <code>config.php</code> a <code>config-keys.php</code> zůstanou beze změny, v upraveném <code>.htaccess</code> se přepíše jen blok pravidel AllStatu, vlastní úpravy zůstanou (a když by pak AllStat neodpovídal, změna se sama vrátí). Trvá to pár sekund a předchozí verzi jde vrátit.') ?></p>

        <?php if ($preflight['ok']): ?>
            <form method="post" class="form-stack update-install" data-confirm="<?= h(t('Nainstalovat AllStat {version}?', ['version' => $latest['version']])) ?>">
                <?= allstat_csrf_field() ?>
                <input type="hidden" name="action" value="install">
                <label class="update-confirm"><input type="checkbox" name="backup_ok" value="1" required> <?= h(t('Mám zálohu databáze (administrace hostingu, phpMyAdmin, Exportovat).')) ?></label>
                <div class="form-actions"><button class="button-primary" type="submit"><?= h(t('Aktualizovat na {version}', ['version' => $latest['version']])) ?></button></div>
            </form>
        <?php else: ?>
            <p class="notice notice-error"><?= h(t('Server zatím aktualizaci nesplňuje, oprav prosím položky označené ✕.')) ?></p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($installed !== null && !$status['available'] && $latest && $preflight): ?>
<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2><?= h(t('Obnovit z GitHubu')) ?></h2>
            <p><?= h(t('Přeinstaluje soubory aplikace z vydání {version} na GitHubu, třeba když se nepovede ruční nasazení nebo se poškodí soubory.', ['version' => $latest['version']])) ?></p>
        </div>
    </div>
    <div class="admin-card-body">
        <p class="field-help"><?= t('Postup je stejný jako u aktualizace: ověření podpisu, záloha přepisovaných souborů, výměna, předchozí stav jde vrátit tlačítkem v historii. Data, <code>config.php</code>, <code>config-keys.php</code> a upravený <code>.htaccess</code> zůstanou.') ?><?php if (version_compare($latest['version'], $installed, '<')): ?> <?= h(t('Nainstalovaná verze {installed} je novější než vydání na GitHubu, kód se tedy vrátí na {version} (databáze zůstane, starší verze s ní funguje).', ['installed' => $installed, 'version' => $latest['version']])) ?><?php endif; ?></p>
        <?php if ($preflight['ok']): ?>
            <form method="post" class="form-stack update-install" data-confirm="<?= h(t('Přeinstalovat AllStat z vydání {version} na GitHubu?', ['version' => $latest['version']])) ?>">
                <?= allstat_csrf_field() ?>
                <input type="hidden" name="action" value="reinstall">
                <label class="update-confirm"><input type="checkbox" name="backup_ok" value="1" required> <?= h(t('Mám zálohu databáze (administrace hostingu, phpMyAdmin, Exportovat).')) ?></label>
                <div class="form-actions"><button class="button-secondary" type="submit"><?= h(t('Přeinstalovat {version} z GitHubu', ['version' => $latest['version']])) ?></button></div>
            </form>
        <?php else: ?>
            <p class="notice notice-error"><?= h(t('Server zatím přeinstalaci nesplňuje: {checks}.', ['checks' => implode(', ', array_map('t', array_column(array_filter($preflight['checks'], static fn (array $c): bool => !$c['ok']), 'label')))])) ?></p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($history): ?>
<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2><?= h(t('Historie aktualizací')) ?></h2>
            <p><?= h(t('Záloha souborů zůstává pro poslední dvě aktualizace. Návrat vrátí soubory, databáze zůstane (starší verze s ní funguje).')) ?></p>
        </div>
        <?php if ($canRollback): ?>
            <form method="post" data-confirm="<?= h(t('Vrátit AllStat na verzi {version}?', ['version' => $history[0]['from']])) ?>">
                <?= allstat_csrf_field() ?>
                <input type="hidden" name="action" value="rollback">
                <button class="button-secondary" type="submit"><?= h(t('Vrátit na {version}', ['version' => $history[0]['from']])) ?></button>
            </form>
        <?php endif; ?>
    </div>
    <div class="admin-card-body table-scroll">
        <table class="admin-table update-history">
            <thead><tr><th><?= h(t('Kdy')) ?></th><th><?= h(t('Z verze')) ?></th><th><?= h(t('Na verzi')) ?></th><th><?= h(t('Poznámka')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($history as $item): ?>
                <tr>
                    <td><?= h($fmtDate($item['at'] ?? null)) ?></td>
                    <td><?= h($item['from'] ?? '') ?></td>
                    <td><?= h($item['to'] ?? '') ?></td>
                    <td><?= !empty($item['rollback']) ? h(t('návrat na předchozí verzi')) : h(trim((!empty($item['reinstall']) ? t('obnova z GitHubu.') . ' ' : '') . implode(' ', array_map('t', $item['warnings'] ?? [])))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php allstat_admin_footer($config); ?>
