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
        . '<title>AllStat: starší verze PHP</title></head>'
        . '<body style="font-family:system-ui,Segoe UI,Arial,sans-serif;max-width:42rem;margin:3rem auto;padding:0 1rem;line-height:1.6;color:#111827">'
        . '<h1 style="font-size:1.5rem">Server používá starou verzi PHP</h1>'
        . '<p>AllStat vyžaduje PHP 8.1 nebo novější (doporučujeme 8.3). Tento server používá PHP ' . htmlspecialchars(PHP_VERSION) . '.</p>'
        . '<p>Verzi PHP změníte v administraci hostingu, obvykle v sekci „Nastavení PHP", „Verze PHP", '
        . '„MultiPHP Manager" nebo „Select PHP Version". Přepněte složku, ve které je AllStat, na PHP 8.3 '
        . '(nebo aspoň 8.1) a tuto stránku obnovte.</p>'
        . '</body></html>';
    exit;
}

require __DIR__ . '/lib/install-wizard.php';
