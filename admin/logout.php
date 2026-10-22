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
<div class="admin-card">
    <div class="admin-card-header"><h2><?= h(t('Odhlásit se')) ?></h2></div>
    <div class="admin-card-body">
        <p class="form-help"><?= h(t('Aktuální session bude ukončena a pro další práci bude potřeba nové přihlášení.')) ?></p>
        <form method="post" class="form-actions">
            <?= allstat_csrf_field() ?>
            <button class="button-danger" type="submit"><?= h(t('Odhlásit')) ?></button>
            <a class="button-secondary" href="<?= h(allstat_url($config, '')) ?>"><?= h(t('Zpět')) ?></a>
        </form>
    </div>
</div>
<?php allstat_admin_footer($config); ?>
