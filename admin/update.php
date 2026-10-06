<?php

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../lib/updater.php';

$pdo = allstat_admin_require_database($pdo, $config);
$user = allstat_require_user($pdo, $config);
// Aktualizace mění soubory aplikace: jen administrátor, i pro čtení.
allstat_require_admin($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    allstat_csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'check') {
        $status = allstat_update_check($pdo, true);
        allstat_flash($status['error'] ? 'error' : 'ok', $status['error'] ?? ($status['available'] ? 'Je k dispozici verze ' . $status['latest']['version'] . '.' : 'Máš nejnovější verzi.'));
    } elseif ($action === 'install' || $action === 'reinstall') {
        if (empty($_POST['backup_ok'])) {
            allstat_flash('error', 'Potvrď, že máš zálohu databáze.');
        } else {
            $result = allstat_update_install($pdo, (int) $user['id'], $action === 'reinstall');
            allstat_flash($result['ok'] ? 'ok' : 'error', $result['message']);
            foreach ($result['warnings'] as $warning) {
                allstat_flash('error', $warning);
            }
        }
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
$fmtDate = static fn (?string $value): string => $value ? date('j. n. Y H:i', strtotime($value)) : 'zatím ne';

allstat_admin_header('Aktualizace', 'update', $user, $config);
?>
<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2>Verze aplikace</h2>
            <p>Nové verze vydává autor na GitHubu. AllStat je kontroluje sám (nejvýš dvakrát denně) a nainstaluje je jedním tlačítkem.</p>
        </div>
        <?php if ($installed !== null): ?>
            <span class="status-badge status-<?= $status['available'] ? 'warning' : 'ok' ?>"><?= $status['available'] ? 'Je nová verze' : 'Aktuální' ?></span>
        <?php endif; ?>
    </div>
    <div class="admin-card-body">
        <?php if ($installed === null): ?>
            <p>Tahle instalace nepochází z veřejného balíčku (chybí soubor <code>VERSION</code>), aktualizace do ní nasazuje vývojář. Automatická aktualizace je jen pro instalace z <a href="https://github.com/ffscz/AllStat/releases" target="_blank" rel="noopener">vydání na GitHubu</a>.</p>
        <?php else: ?>
            <dl class="update-facts">
                <div><dt>Nainstalovaná verze</dt><dd><?= h($installed) ?></dd></div>
                <div><dt>Nejnovější verze</dt><dd><?= h($latest['version'] ?? 'neznámá') ?></dd></div>
                <div><dt>Poslední kontrola</dt><dd><?= h($fmtDate($status['checked_at'])) ?></dd></div>
                <div><dt>Databáze</dt><dd><?= $schemaOk ? 'aktuální' : 'čeká na doplnění' ?></dd></div>
            </dl>
            <?php if ($status['error']): ?><p class="notice notice-error"><?= h($status['error']) ?></p><?php endif; ?>
            <form method="post" class="form-actions">
                <?= allstat_csrf_field() ?>
                <input type="hidden" name="action" value="check">
                <button class="button-secondary" type="submit">Zkontrolovat teď</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($status['available'] && $latest && $preflight): ?>
<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2>Nová verze <?= h($latest['version']) ?></h2>
            <p><?= $latest['published'] ? 'Vydáno ' . h($fmtDate($latest['published'])) . '. ' : '' ?><?php if ($latest['html_url']): ?><a href="<?= h($latest['html_url']) ?>" target="_blank" rel="noopener">Podrobnosti na GitHubu</a><?php endif; ?></p>
        </div>
    </div>
    <div class="admin-card-body">
        <?php if ($latest['notes'] !== ''): ?>
            <h3 class="update-subhead">Co je nového</h3>
            <div class="update-notes"><?= nl2br(h($latest['notes'])) ?></div>
        <?php endif; ?>

        <h3 class="update-subhead">Kontrola serveru</h3>
        <ul class="update-checks">
            <?php foreach ($preflight['checks'] as $check): ?>
                <li class="<?= $check['ok'] ? 'is-ok' : 'is-bad' ?>"><span aria-hidden="true"><?= $check['ok'] ? '✓' : '✕' ?></span> <?= h($check['label']) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php if ($preflight['modified']): ?>
            <details class="update-modified">
                <summary>Ručně upravené soubory (<?= count($preflight['modified']) ?>): přepíšou se, původní verze zůstanou v záloze</summary>
                <ul><?php foreach ($preflight['modified'] as $rel): ?><li><code><?= h($rel) ?></code></li><?php endforeach; ?></ul>
            </details>
        <?php endif; ?>

        <h3 class="update-subhead">Co se stane</h3>
        <p class="field-help">Balíček se stáhne a ověří jeho podpis (bez platného podpisu se nic nenainstaluje). Soubory, které se přepíšou, se zálohují, pak se vymění a databáze se při dalším načtení sama doplní (nové tabulky a sloupce, data se nemažou). Soubory <code>config.php</code> a <code>config-keys.php</code> zůstanou beze změny, upravený <code>.htaccess</code> se nepřepíše. Trvá to pár sekund a předchozí verzi jde vrátit.</p>

        <?php if ($preflight['ok']): ?>
            <form method="post" class="form-stack update-install" data-confirm="Nainstalovat AllStat <?= h($latest['version']) ?>?">
                <?= allstat_csrf_field() ?>
                <input type="hidden" name="action" value="install">
                <label class="update-confirm"><input type="checkbox" name="backup_ok" value="1" required> Mám zálohu databáze (administrace hostingu, phpMyAdmin, Exportovat).</label>
                <div class="form-actions"><button class="button-primary" type="submit">Aktualizovat na <?= h($latest['version']) ?></button></div>
            </form>
        <?php else: ?>
            <p class="notice notice-error">Server zatím aktualizaci nesplňuje, oprav prosím položky označené ✕.</p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($installed !== null && !$status['available'] && $latest && $preflight): ?>
<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2>Obnovit z GitHubu</h2>
            <p>Přeinstaluje soubory aplikace z vydání <?= h($latest['version']) ?> na GitHubu, třeba když se nepovede ruční nasazení nebo se poškodí soubory.</p>
        </div>
    </div>
    <div class="admin-card-body">
        <p class="field-help">Postup je stejný jako u aktualizace: ověření podpisu, záloha přepisovaných souborů, výměna, předchozí stav jde vrátit tlačítkem v historii. Data, <code>config.php</code>, <code>config-keys.php</code> a upravený <code>.htaccess</code> zůstanou.<?php if (version_compare($latest['version'], $installed, '<')): ?> Nainstalovaná verze <?= h($installed) ?> je novější než vydání na GitHubu, kód se tedy vrátí na <?= h($latest['version']) ?> (databáze zůstane, starší verze s ní funguje).<?php endif; ?></p>
        <?php if ($preflight['ok']): ?>
            <form method="post" class="form-stack update-install" data-confirm="Přeinstalovat AllStat z vydání <?= h($latest['version']) ?> na GitHubu?">
                <?= allstat_csrf_field() ?>
                <input type="hidden" name="action" value="reinstall">
                <label class="update-confirm"><input type="checkbox" name="backup_ok" value="1" required> Mám zálohu databáze (administrace hostingu, phpMyAdmin, Exportovat).</label>
                <div class="form-actions"><button class="button-secondary" type="submit">Přeinstalovat <?= h($latest['version']) ?> z GitHubu</button></div>
            </form>
        <?php else: ?>
            <p class="notice notice-error">Server zatím přeinstalaci nesplňuje: <?= h(implode(', ', array_column(array_filter($preflight['checks'], static fn (array $c): bool => !$c['ok']), 'label'))) ?>.</p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($history): ?>
<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2>Historie aktualizací</h2>
            <p>Záloha souborů zůstává pro poslední dvě aktualizace. Návrat vrátí soubory, databáze zůstane (starší verze s ní funguje).</p>
        </div>
        <?php if ($canRollback): ?>
            <form method="post" data-confirm="Vrátit AllStat na verzi <?= h($history[0]['from']) ?>?">
                <?= allstat_csrf_field() ?>
                <input type="hidden" name="action" value="rollback">
                <button class="button-secondary" type="submit">Vrátit na <?= h($history[0]['from']) ?></button>
            </form>
        <?php endif; ?>
    </div>
    <div class="admin-card-body table-scroll">
        <table class="admin-table update-history">
            <thead><tr><th>Kdy</th><th>Z verze</th><th>Na verzi</th><th>Poznámka</th></tr></thead>
            <tbody>
            <?php foreach ($history as $item): ?>
                <tr>
                    <td><?= h($fmtDate($item['at'] ?? null)) ?></td>
                    <td><?= h($item['from'] ?? '') ?></td>
                    <td><?= h($item['to'] ?? '') ?></td>
                    <td><?= !empty($item['rollback']) ? 'návrat na předchozí verzi' : h(trim((!empty($item['reinstall']) ? 'obnova z GitHubu. ' : '') . implode(' ', $item['warnings'] ?? []))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php allstat_admin_footer($config); ?>
