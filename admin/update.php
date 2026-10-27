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
<section class="card">
    <div class="card-head">
        <div>
            <h2 class="card-title"><?= h(t('Verze aplikace')) ?></h2>
            <p class="card-desc"><?= h(t('Nové verze vydává autor na GitHubu. AllStat je kontroluje sám (nejvýš dvakrát denně) a nainstaluje je jedním tlačítkem.')) ?></p>
        </div>
        <?php if ($installed !== null): ?>
            <?= allstat_status_pill($status['available'] ? 'warning' : 'ok', $status['available'] ? t('Je nová verze') : t('Aktuální')) ?>
        <?php endif; ?>
    </div>
    <div class="card-body section">
        <?php if ($installed === null): ?>
            <p class="is-muted"><?= t('Tahle instalace nepochází z veřejného balíčku (chybí soubor <code>VERSION</code>), aktualizace do ní nasazuje vývojář. Automatická aktualizace je jen pro instalace z <a href="https://github.com/ffscz/AllStat/releases" target="_blank" rel="noopener">vydání na GitHubu</a>.') ?></p>
        <?php else: ?>
            <div class="grid grid-kpis">
                <div class="card kpi"><span class="metric-label"><?= h(t('Nainstalovaná verze')) ?></span><span class="metric-value num"><?= h($installed) ?></span></div>
                <div class="card kpi"><span class="metric-label"><?= h(t('Nejnovější verze')) ?></span><span class="metric-value num"><?= h($latest['version'] ?? t('neznámá')) ?></span></div>
                <div class="card kpi"><span class="metric-label"><?= h(t('Poslední kontrola')) ?></span><span class="metric-value num"><?= h($fmtDate($status['checked_at'])) ?></span></div>
                <div class="card kpi"><span class="metric-label"><?= h(t('Databáze')) ?></span><span class="metric-value num"><?= h($schemaOk ? t('aktuální') : t('čeká na doplnění')) ?></span></div>
            </div>
            <?php if ($status['error']): ?><div class="callout tone-down"><?= allstat_icon('info', 'icon-sm') ?><div><?= h(t($status['error'])) ?></div></div><?php endif; ?>
            <form method="post" class="form-actions">
                <?= allstat_csrf_field() ?>
                <input type="hidden" name="action" value="check">
                <button class="btn btn-secondary" type="submit"><?= h(t('Zkontrolovat teď')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php foreach ($protectedPending as $item): ?>
<section class="card">
    <div class="card-head">
        <div>
            <h2 class="card-title"><?= h(t('Soubor {file}: pravidla k doplnění', ['file' => $item['file']])) ?></h2>
            <p class="card-desc"><?= t('AllStat funguje, tohle není chyba. Soubor <code>{file}</code> (pravidla webového serveru) má na hostingu vlastní úpravy, třeba od hostingu nebo blokování robotů, proto ho aktualizace nepřepsala. Automaticky pravidla doplnit nešlo, nová verze je proto vedle jako <code>{file}.new</code> a v tvém souboru z ní chybí bloky níže.', ['file' => h($item['file'])]) ?> <?= allstat_help_link('htaccess', t('Co s tím')) ?></p>
        </div>
        <?= allstat_status_pill('warning', t('Ke kontrole')) ?>
    </div>
    <div class="card-body section">
        <?php foreach ($item['blocks'] as $block): ?>
            <?php if ($block['title'] !== ''): ?><h3 class="block-title"><?= h($block['title']) ?></h3><?php endif; ?>
            <pre class="code"><?= h($block['code']) ?></pre>
        <?php endforeach; ?>
        <p class="field-help"><?= t('Jak na to: přes FTP nebo správce souborů na hostingu otevři <code>{file}</code> a z <code>{file}.new</code> do něj zkopíruj celý blok od <code># BEGIN AllStat</code> po <code># END AllStat</code> (místo starších pravidel AllStatu), vlastní úpravy nech. Když žádné vlastní úpravy nepotřebuješ, smaž <code>{file}</code> a <code>{file}.new</code> přejmenuj na <code>{file}</code>. Jakmile pravidla v souboru budou, karta zmizí sama.', ['file' => h($item['file'])]) ?></p>
        <form method="post" class="form-actions" data-confirm="<?= h(t('Nechat svůj {file} a smazat {file}.new?', ['file' => $item['file']])) ?>">
            <?= allstat_csrf_field() ?>
            <input type="hidden" name="action" value="dismiss_new">
            <input type="hidden" name="file" value="<?= h($item['file']) ?>">
            <button class="btn btn-secondary" type="submit"><?= h(t('Nechat svůj soubor a smazat {file}.new', ['file' => $item['file']])) ?></button>
        </form>
    </div>
</section>
<?php endforeach; ?>

<?php if ($status['available'] && $latest && $preflight): ?>
<section class="card">
    <div class="card-head">
        <div>
            <h2 class="card-title"><?= h(t('Nová verze {version}', ['version' => $latest['version']])) ?></h2>
            <p class="card-desc"><?= $latest['published'] ? h(t('Vydáno {date}.', ['date' => $fmtDate($latest['published'])])) . ' ' : '' ?><?php if ($latest['html_url']): ?><a class="link-more" href="<?= h($latest['html_url']) ?>" target="_blank" rel="noopener"><?= h(t('Podrobnosti na GitHubu')) ?></a><?php endif; ?></p>
        </div>
    </div>
    <div class="card-body section">
        <?php if ($latest['notes'] !== ''): ?>
            <h3 class="block-title"><?= h(t('Co je nového')) ?></h3>
            <div class="callout"><div><?= nl2br(h($latest['notes'])) ?></div></div>
        <?php endif; ?>

        <h3 class="block-title"><?= h(t('Kontrola serveru')) ?></h3>
        <ul class="rows">
            <?php foreach ($preflight['checks'] as $check): ?>
                <li class="row"><span class="row-label"><span class="<?= $check['ok'] ? 'is-up' : 'is-down' ?>" aria-hidden="true"><?= $check['ok'] ? '✓' : '✕' ?></span> <?= h(t($check['label'])) ?></span></li>
            <?php endforeach; ?>
        </ul>
        <?php if ($preflight['modified']): ?>
            <details class="card note">
                <summary><?= allstat_icon('info', 'icon') ?><span><?= h(t('Ručně upravené soubory ({count}): přepíšou se, původní verze zůstanou v záloze', ['count' => count($preflight['modified'])])) ?></span><?= allstat_icon('chevron-down', 'note-chev') ?></summary>
                <div class="note-body"><ul><?php foreach ($preflight['modified'] as $rel): ?><li><code><?= h($rel) ?></code></li><?php endforeach; ?></ul></div>
            </details>
        <?php endif; ?>

        <h3 class="block-title"><?= h(t('Co se stane')) ?></h3>
        <p class="field-help"><?= t('Balíček se stáhne a ověří jeho podpis (bez platného podpisu se nic nenainstaluje). Soubory, které se přepíšou, se zálohují, pak se vymění a databáze se při dalším načtení sama doplní (nové tabulky a sloupce, data se nemažou). Soubory <code>config.php</code> a <code>config-keys.php</code> zůstanou beze změny, v upraveném <code>.htaccess</code> se přepíše jen blok pravidel AllStatu, vlastní úpravy zůstanou (a když by pak AllStat neodpovídal, změna se sama vrátí). Trvá to pár sekund a předchozí verzi jde vrátit.') ?></p>

        <?php if ($preflight['ok']): ?>
            <form method="post" class="section" data-confirm="<?= h(t('Nainstalovat AllStat {version}?', ['version' => $latest['version']])) ?>">
                <?= allstat_csrf_field() ?>
                <input type="hidden" name="action" value="install">
                <label class="check"><input type="checkbox" name="backup_ok" value="1" required><span><?= h(t('Mám zálohu databáze (administrace hostingu, phpMyAdmin, Exportovat).')) ?></span></label>
                <div class="form-actions"><button class="btn btn-primary" type="submit"><?= h(t('Aktualizovat na {version}', ['version' => $latest['version']])) ?></button></div>
            </form>
        <?php else: ?>
            <div class="callout tone-down"><?= allstat_icon('info', 'icon-sm') ?><div><?= h(t('Server zatím aktualizaci nesplňuje, oprav prosím položky označené ✕.')) ?></div></div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($installed !== null && !$status['available'] && $latest && $preflight): ?>
<section class="card">
    <div class="card-head">
        <div>
            <h2 class="card-title"><?= h(t('Obnovit z GitHubu')) ?></h2>
            <p class="card-desc"><?= h(t('Přeinstaluje soubory aplikace z vydání {version} na GitHubu, třeba když se nepovede ruční nasazení nebo se poškodí soubory.', ['version' => $latest['version']])) ?></p>
        </div>
    </div>
    <div class="card-body section">
        <p class="field-help"><?= t('Postup je stejný jako u aktualizace: ověření podpisu, záloha přepisovaných souborů, výměna, předchozí stav jde vrátit tlačítkem v historii. Data, <code>config.php</code>, <code>config-keys.php</code> a upravený <code>.htaccess</code> zůstanou.') ?><?php if (version_compare($latest['version'], $installed, '<')): ?> <?= h(t('Nainstalovaná verze {installed} je novější než vydání na GitHubu, kód se tedy vrátí na {version} (databáze zůstane, starší verze s ní funguje).', ['installed' => $installed, 'version' => $latest['version']])) ?><?php endif; ?></p>
        <?php if ($preflight['ok']): ?>
            <form method="post" class="section" data-confirm="<?= h(t('Přeinstalovat AllStat z vydání {version} na GitHubu?', ['version' => $latest['version']])) ?>">
                <?= allstat_csrf_field() ?>
                <input type="hidden" name="action" value="reinstall">
                <label class="check"><input type="checkbox" name="backup_ok" value="1" required><span><?= h(t('Mám zálohu databáze (administrace hostingu, phpMyAdmin, Exportovat).')) ?></span></label>
                <div class="form-actions"><button class="btn btn-secondary" type="submit"><?= h(t('Přeinstalovat {version} z GitHubu', ['version' => $latest['version']])) ?></button></div>
            </form>
        <?php else: ?>
            <div class="callout tone-down"><?= allstat_icon('info', 'icon-sm') ?><div><?= h(t('Server zatím přeinstalaci nesplňuje: {checks}.', ['checks' => implode(', ', array_map('t', array_column(array_filter($preflight['checks'], static fn (array $c): bool => !$c['ok']), 'label')))])) ?></div></div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($history): ?>
<section class="card">
    <div class="card-head">
        <div>
            <h2 class="card-title"><?= h(t('Historie aktualizací')) ?></h2>
            <p class="card-desc"><?= h(t('Záloha souborů zůstává pro poslední dvě aktualizace. Návrat vrátí soubory, databáze zůstane (starší verze s ní funguje).')) ?></p>
        </div>
        <?php if ($canRollback): ?>
            <form method="post" data-confirm="<?= h(t('Vrátit AllStat na verzi {version}?', ['version' => $history[0]['from']])) ?>">
                <?= allstat_csrf_field() ?>
                <input type="hidden" name="action" value="rollback">
                <button class="btn btn-secondary" type="submit"><?= h(t('Vrátit na {version}', ['version' => $history[0]['from']])) ?></button>
            </form>
        <?php endif; ?>
    </div>
    <div class="table-wrap">
        <table class="table table-data">
            <colgroup><col class="col-lg"><col class="col-md"><col class="col-md"><col></colgroup>
            <thead><tr><th scope="col"><?= h(t('Kdy')) ?></th><th scope="col"><?= h(t('Z verze')) ?></th><th scope="col"><?= h(t('Na verzi')) ?></th><th scope="col"><?= h(t('Poznámka')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($history as $item): ?>
                <tr>
                    <td><?= h($fmtDate($item['at'] ?? null)) ?></td>
                    <td><?= h($item['from'] ?? '') ?></td>
                    <td><?= h($item['to'] ?? '') ?></td>
                    <td class="is-wrap"><?= !empty($item['rollback']) ? h(t('návrat na předchozí verzi')) : h(trim((!empty($item['reinstall']) ? t('obnova z GitHubu.') . ' ' : '') . implode(' ', array_map('t', $item['warnings'] ?? [])))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
<?php allstat_admin_footer($config); ?>
