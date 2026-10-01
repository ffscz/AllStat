<?php

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../lib/meta-connect.php';

$pdo = allstat_admin_require_database($pdo, $config);
$user = allstat_require_user($pdo, $config);
allstat_require_admin($user);

$disc = $_SESSION['meta_discovery'] ?? null;
if (!$disc || (time() - (int) ($disc['created_at'] ?? 0)) > 1800) {
    allstat_flash('error', 'Připojení Meta vypršelo nebo chybí. Spusť to znovu.');
    allstat_redirect($config, 'admin/meta-connect.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    allstat_csrf_check();
    $selection = [
        'pages' => array_values((array) ($_POST['pages'] ?? [])),
        'ig' => array_values((array) ($_POST['ig'] ?? [])),
        'ads' => array_values((array) ($_POST['ads'] ?? [])),
    ];
    $assets = ['pages' => $disc['pages'], 'adaccounts' => $disc['adaccounts'], 'user_token' => $disc['user_token']];
    $res = allstat_meta_create_connections($pdo, $config, (int) $disc['domain_id'], $selection, $assets);
    $domainId = (int) $disc['domain_id'];
    unset($_SESSION['meta_discovery']);
    allstat_audit($pdo, (int) $user['id'], null, 'meta_connections_created', (string) ($res['created'] ?? 0));
    allstat_flash('ok', 'Připojeno ' . (int) ($res['created'] ?? 0) . ' napojení. Otevři jejich detail a klikni „Stáhnout historii (16 měsíců)".');
    allstat_redirect($config, 'admin/sources.php?domain_id=' . $domainId);
}

$pages = $disc['pages'] ?? [];
$adaccounts = $disc['adaccounts'] ?? [];
$dbg = $disc['debug'] ?? [];
$igCount = 0;
foreach ($pages as $p) { if (!empty($p['instagram_business_account']['id'])) { $igCount++; } }

allstat_admin_header('Vyber účty Meta', 'sources', $user, $config);
?>
<?php if (!empty($dbg)): ?>
<div class="admin-card">
    <div class="admin-card-header"><div><h2>Diagnostika připojení</h2><p>Co vrátilo Meta Graph API, pomáhá zjistit, proč se případně stránka nenačetla.</p></div></div>
    <div class="admin-card-body table-scroll">
        <table class="admin-table">
            <thead><tr><th>Volání</th><th>HTTP</th><th>Počet</th><th>Detail / chyba</th></tr></thead>
            <tbody>
            <?php foreach ($dbg as $key => $info): ?>
                <tr>
                    <td><code><?= h((string) $key) ?></code></td>
                    <td><?= is_array($info) ? (int) ($info['status'] ?? 0) : '' ?></td>
                    <td><?= is_array($info) ? (isset($info['count']) ? (int) $info['count'] : '') : h((string) $info) ?></td>
                    <td><?php
                        if (is_array($info)) {
                            $bits = [];
                            if (!empty($info['name'])) { $bits[] = 'me=' . $info['name']; }
                            if (!empty($info['id'])) { $bits[] = 'id=' . $info['id']; }
                            if (!empty($info['error'])) { $bits[] = 'CHYBA: ' . $info['error']; }
                            echo h(implode(' · ', $bits));
                        }
                    ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<div class="admin-card">
    <div class="admin-card-header">
        <div>
            <h2>Vyber, co připojit</h2>
            <p>Našli jsme <strong><?= count($pages) ?></strong> FB stránek, <strong><?= $igCount ?></strong> Instagram účtů a <strong><?= count($adaccounts) ?></strong> reklamních účtů. Zaškrtni, co chceš na webu sledovat.</p>
        </div>
        <a class="button-secondary" href="meta-connect.php">Zrušit</a>
    </div>
    <div class="admin-card-body">
        <form method="post" class="form-stack">
            <?= allstat_csrf_field() ?>

            <h3 style="margin:6px 0;font-size:14px;">Facebook stránky</h3>
            <div class="table-scroll">
                <table class="admin-table">
                    <thead><tr><th></th><th>Stránka</th><th>Page ID</th><th>Instagram</th></tr></thead>
                    <tbody>
                        <?php foreach ($pages as $p):
                            $pid = (string) ($p['id'] ?? '');
                            $ig = $p['instagram_business_account'] ?? null;
                        ?>
                        <tr>
                            <td><input type="checkbox" name="pages[]" value="<?= h($pid) ?>" checked></td>
                            <td><strong><?= h((string) ($p['name'] ?? '')) ?></strong></td>
                            <td class="table-muted"><?= h($pid) ?></td>
                            <td>
                                <?php if ($ig && !empty($ig['id'])): ?>
                                    <label style="display:inline-flex;gap:6px;align-items:center;">
                                        <input type="checkbox" name="ig[]" value="<?= h($pid) ?>" checked>
                                        @<?= h((string) ($ig['username'] ?? $ig['id'])) ?>
                                    </label>
                                <?php else: ?>
                                    <span class="table-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$pages): ?><tr><td colspan="4" class="table-muted">Žádné FB stránky pod tímto účtem (nebo chybí oprávnění/role).</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>

            <h3 style="margin:16px 0 6px;font-size:14px;">Reklamní účty (Meta Ads)</h3>
            <div class="table-scroll">
                <table class="admin-table">
                    <thead><tr><th></th><th>Účet</th><th>ID</th></tr></thead>
                    <tbody>
                        <?php foreach ($adaccounts as $a):
                            $aid = (string) ($a['id'] ?? '');
                        ?>
                        <tr>
                            <td><input type="checkbox" name="ads[]" value="<?= h($aid) ?>" checked></td>
                            <td><strong><?= h((string) ($a['name'] ?? $aid)) ?></strong></td>
                            <td class="table-muted"><?= h($aid) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$adaccounts): ?><tr><td colspan="3" class="table-muted">Žádné reklamní účty (nebo chybí scope ads_read / role).</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="form-actions" style="margin-top:16px;">
                <button class="button-primary" type="submit">Připojit vybrané</button>
                <a class="button-secondary" href="meta-connect.php">Zrušit</a>
            </div>
        </form>
    </div>
</div>
<?php allstat_admin_footer($config); ?>
