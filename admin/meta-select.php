<?php

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../lib/meta-connect.php';

$pdo = allstat_admin_require_database($pdo, $config);
$user = allstat_require_user($pdo, $config);
allstat_require_admin($user);

$disc = $_SESSION['meta_discovery'] ?? null;
if (!$disc || (time() - (int) ($disc['created_at'] ?? 0)) > 1800) {
    allstat_flash('error', t('Připojení Meta vypršelo nebo chybí. Spusť to znovu.'));
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
    allstat_flash('ok', t('Připojeno {count} napojení. Otevři jejich detail a klikni „Stáhnout historii (16 měsíců)".', ['count' => (int) ($res['created'] ?? 0)]));
    allstat_redirect($config, 'admin/sources.php?domain_id=' . $domainId);
}

$pages = $disc['pages'] ?? [];
$adaccounts = $disc['adaccounts'] ?? [];
$dbg = $disc['debug'] ?? [];
$igCount = 0;
foreach ($pages as $p) { if (!empty($p['instagram_business_account']['id'])) { $igCount++; } }

allstat_admin_header(t('Vyber účty Meta'), 'sources', $user, $config, 'meta', [
    'actions' => '<a class="btn btn-secondary" href="meta-connect.php"><span>' . h(t('Zrušit')) . '</span></a>',
]);
?>
<?php if (!empty($dbg)): ?>
<section class="card card-clip" aria-labelledby="meta-diag-title">
    <div class="card-head">
        <div>
            <h2 class="card-title" id="meta-diag-title"><?= h(t('Diagnostika připojení')) ?></h2>
            <p class="card-desc"><?= h(t('Co vrátilo Meta Graph API, pomáhá zjistit, proč se případně stránka nenačetla.')) ?></p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="table table-data">
            <colgroup><col class="col-xl"><col class="col-sm"><col class="col-sm"><col></colgroup>
            <thead><tr><th scope="col"><?= h(t('Volání')) ?></th><th scope="col">HTTP</th><th scope="col"><?= h(t('Počet')) ?></th><th scope="col"><?= h(t('Detail / chyba')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($dbg as $key => $info): ?>
                <tr>
                    <td><code class="ellipsis"><?= h((string) $key) ?></code></td>
                    <td class="num"><?= is_array($info) ? (int) ($info['status'] ?? 0) : '' ?></td>
                    <td class="num"><?= is_array($info) ? (isset($info['count']) ? (int) $info['count'] : '') : h((string) $info) ?></td>
                    <td class="is-wrap"><?php
                        if (is_array($info)) {
                            $bits = [];
                            if (!empty($info['name'])) { $bits[] = 'me=' . $info['name']; }
                            if (!empty($info['id'])) { $bits[] = 'id=' . $info['id']; }
                            if (!empty($info['error'])) { $bits[] = t('CHYBA: {error}', ['error' => $info['error']]); }
                            echo h(implode(' · ', $bits));
                        }
                    ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<form method="post" class="section">
    <?= allstat_csrf_field() ?>
    <div class="section-head">
        <h2 class="h2"><?= h(t('Vyber, co připojit')) ?></h2>
        <span class="section-desc"><?= t('Našli jsme <strong>{pages}</strong> FB stránek, <strong>{ig}</strong> Instagram účtů a <strong>{ads}</strong> reklamních účtů. Zaškrtni, co chceš na webu sledovat.', ['pages' => count($pages), 'ig' => $igCount, 'ads' => count($adaccounts)]) ?></span>
    </div>

    <section class="card card-clip" aria-labelledby="meta-pages-title">
        <div class="card-head">
            <div><h3 class="card-title" id="meta-pages-title"><?= h(t('Facebook stránky')) ?></h3></div>
        </div>
        <?php if ($pages): ?>
        <div class="table-wrap">
            <table class="table table-data">
                <colgroup><col class="col-xs"><col><col class="col-lg"><col class="col-lg"></colgroup>
                <thead><tr><th scope="col" data-no-sort><span class="sr-only"><?= h(t('Připojit vybrané')) ?></span></th><th scope="col"><?= h(t('Stránka')) ?></th><th scope="col">Page ID</th><th scope="col">Instagram</th></tr></thead>
                <tbody>
                    <?php foreach ($pages as $p):
                        $pid = (string) ($p['id'] ?? '');
                        $ig = $p['instagram_business_account'] ?? null;
                    ?>
                    <tr>
                        <td><input type="checkbox" name="pages[]" value="<?= h($pid) ?>" checked aria-label="<?= h((string) ($p['name'] ?? $pid)) ?>"></td>
                        <td><span class="name-main ellipsis"><?= h((string) ($p['name'] ?? '')) ?></span></td>
                        <td class="is-muted mono"><?= h($pid) ?></td>
                        <td>
                            <?php if ($ig && !empty($ig['id'])): ?>
                                <label class="check">
                                    <input type="checkbox" name="ig[]" value="<?= h($pid) ?>" checked>
                                    <span>@<?= h((string) ($ig['username'] ?? $ig['id'])) ?></span>
                                </label>
                            <?php else: ?>
                                <span class="is-muted">–</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty"><span class="empty-title"><?= h(t('Žádné FB stránky pod tímto účtem (nebo chybí oprávnění/role).')) ?></span></div>
        <?php endif; ?>
    </section>

    <section class="card card-clip" aria-labelledby="meta-ads-title">
        <div class="card-head">
            <div><h3 class="card-title" id="meta-ads-title"><?= h(t('Reklamní účty (Meta Ads)')) ?></h3></div>
        </div>
        <?php if ($adaccounts): ?>
        <div class="table-wrap">
            <table class="table table-data">
                <colgroup><col class="col-xs"><col><col class="col-xl"></colgroup>
                <thead><tr><th scope="col" data-no-sort><span class="sr-only"><?= h(t('Připojit vybrané')) ?></span></th><th scope="col"><?= h(t('Účet')) ?></th><th scope="col">ID</th></tr></thead>
                <tbody>
                    <?php foreach ($adaccounts as $a):
                        $aid = (string) ($a['id'] ?? '');
                    ?>
                    <tr>
                        <td><input type="checkbox" name="ads[]" value="<?= h($aid) ?>" checked aria-label="<?= h((string) ($a['name'] ?? $aid)) ?>"></td>
                        <td><span class="name-main ellipsis"><?= h((string) ($a['name'] ?? $aid)) ?></span></td>
                        <td class="is-muted mono"><?= h($aid) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty"><span class="empty-title"><?= h(t('Žádné reklamní účty (nebo chybí scope ads_read / role).')) ?></span></div>
        <?php endif; ?>
    </section>

    <div class="form-actions">
        <button class="btn btn-primary" type="submit"><?= h(t('Připojit vybrané')) ?></button>
        <a class="btn btn-secondary" href="meta-connect.php"><?= h(t('Zrušit')) ?></a>
    </div>
</form>
<?php allstat_admin_footer($config); ?>
