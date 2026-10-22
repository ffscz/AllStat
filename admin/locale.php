<?php

// Přepnutí jazyka rozhraní (lib/i18n.php): přihlášenému uživateli se uloží k účtu, vždy i do cookie, aby
// platil i na přihlašovací stránce. Vrací zpět na stránku, ze které přišel (jen v rámci AllStatu).

require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    allstat_redirect($config, '');
}
allstat_csrf_check();

$locale = is_string($_POST['locale'] ?? null) ? $_POST['locale'] : '';
if (!isset(ALLSTAT_LOCALES[$locale])) {
    allstat_redirect($config, '');
}

$user = $pdo && allstat_tables_ready($pdo) ? allstat_current_user($pdo) : null;
if ($user) {
    $pdo->prepare('UPDATE allstat_users SET locale = ? WHERE id = ?')->execute([$locale, (int) $user['id']]);
}
setcookie('allstat_lang', $locale, [
    'expires' => time() + 365 * 86400,
    'path' => allstat_base_path($config) !== '' ? allstat_base_path($config) : '/', // instalace v kořeni webu (base_path „/“)
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);

$back = is_string($_POST['back'] ?? null) ? $_POST['back'] : '';
$base = allstat_base_path($config) . '/';
if ($back === '' || !str_starts_with($back, $base) || str_starts_with($back, '//') || preg_match('~[\r\n\\\\]~', $back) === 1) {
    $back = allstat_url($config, '');
}
header('Location: ' . $back);
exit;
