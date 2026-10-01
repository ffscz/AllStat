<?php

// Bez config.php ještě neproběhla instalace: přesměrovat na instalačního průvodce.
if (!is_file(__DIR__ . '/../config.php')) {
    header('Location: ' . rtrim(str_replace('\\', '/', dirname(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php')))), '/') . '/install.php');
    exit;
}

$config = require __DIR__ . '/../config.php';
date_default_timezone_set($config['app']['timezone']);

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/database.php';
require_once __DIR__ . '/../lib/migrations.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/users.php';
require_once __DIR__ . '/../lib/admin-repository.php';

allstat_session_start($config); // posílá i bezpečnostní hlavičky + CSP (allstat_send_security_headers)

$pdo = allstat_db($config);

if ($pdo && allstat_tables_ready($pdo)) {
    allstat_migrate($pdo);
}

function allstat_admin_require_database(?PDO $pdo, array $config): PDO
{
    if (!$pdo || !allstat_tables_ready($pdo)) {
        allstat_redirect($config, 'install.php');
    }

    return $pdo;
}
