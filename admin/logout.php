<?php

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_layout.php';

$pdo = allstat_admin_require_database($pdo, $config);
$user = allstat_current_user($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    allstat_csrf_check();
    if ($user) {
        allstat_audit($pdo, (int) $user['id'], (int) $user['id'], 'logout');
    }
    allstat_logout();
    allstat_redirect($config, 'admin/login.php');
}

if (!$user) {
    allstat_redirect($config, 'admin/login.php');
}

allstat_admin_header(t('Odhlášení'), 'settings', $user, $config);
?>
<section class="card">
    <div class="card-head"><h2 class="card-title"><?= h(t('Odhlásit se')) ?></h2></div>
    <div class="card-body section">
        <p class="field-help"><?= h(t('Aktuální session bude ukončena a pro další práci bude potřeba nové přihlášení.')) ?></p>
        <form method="post" class="form-actions">
            <?= allstat_csrf_field() ?>
            <button class="btn btn-danger" type="submit"><?= h(t('Odhlásit')) ?></button>
            <a class="btn btn-secondary" href="<?= h(allstat_url($config, '')) ?>"><?= h(t('Zpět')) ?></a>
        </form>
    </div>
</section>
<?php allstat_admin_footer($config); ?>
