<?php

require __DIR__ . '/_bootstrap.php';
$pdo = allstat_admin_require_database($pdo, $config);
allstat_require_user($pdo, $config);
allstat_redirect($config, '');
