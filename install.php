<?php
/**
 * AllStat: vstup do instalátoru.
 *
 * Tenhle soubor je schválně napsaný jednoduše (bez novějších rysů PHP), aby se dal spustit i na
 * starém hostingu a srozumitelně vysvětlil, že je potřeba novější PHP. Vlastní průvodce je v
 * lib/install-wizard.php.
 */

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="cs"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>AllStat: starší verze PHP</title>'
        // Samostatná stránka bez souborů z assets/ (na starém PHP nemusí nic dalšího fungovat); hodnoty odpovídají tokenům světlého motivu.
        . '<style>body{margin:0;padding:24px 16px;background:#F6F7F9;color:#101828;font:14px/20px Geist,system-ui,Segoe UI,Arial,sans-serif}'
        . 'main{max-width:560px;margin:48px auto;padding:28px;border:1px solid #E4E7EC;border-radius:12px;background:#fff;box-shadow:0 1px 2px rgba(16,24,40,.05)}'
        . 'h1{margin:0 0 12px;font-size:24px;line-height:32px;font-weight:650;letter-spacing:-.02em}p{margin:0 0 12px}.muted{color:#5F6878}</style></head>'
        . '<body><main><h1>Server používá starou verzi PHP</h1>'
        . '<p>AllStat vyžaduje PHP 8.1 nebo novější (doporučujeme 8.3). Tento server používá PHP ' . htmlspecialchars(PHP_VERSION) . '.</p>'
        . '<p>Verzi PHP změníte v administraci hostingu, obvykle v sekci „Nastavení PHP", „Verze PHP", '
        . '„MultiPHP Manager" nebo „Select PHP Version". Přepněte složku, ve které je AllStat, na PHP 8.3 '
        . '(nebo aspoň 8.1) a tuto stránku obnovte.</p>'
        . '<p class="muted">AllStat requires PHP 8.1 or newer. Please switch this folder to PHP 8.3 (or at least 8.1) in your hosting control panel and reload this page.</p>'
        . '</main></body></html>';
    exit;
}

require __DIR__ . '/lib/install-wizard.php';
