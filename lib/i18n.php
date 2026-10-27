<?php

/**
 * Překlady rozhraní. Zdrojem je čeština: t('Český text') vrátí překlad do jazyka uživatele ze slovníků
 * lang/<jazyk>/*.php (pole český text => překlad), a když překlad chybí, původní český text.
 *
 * Proměnné se píší do složených závorek: t('Připojeno {count} napojení.', ['count' => 5]). Dosazují se tak, jak
 * jsou, takže do HTML je volající escapuje předem (h()), nebo escapuje celý výsledek, když text HTML nemá.
 * Slovníky jsou součást kódu (důvěryhodné), smí obsahovat stejné HTML značky jako český originál.
 *
 * Jazyk: uživatel (allstat_users.locale) > cookie allstat_lang (přihlášení, instalátor) > výchozí jazyk instalace
 * (nastavení app.locale) > čeština. Data pro AI (MCP, Sdílet pro AI), datové feedy a exporty zůstávají česky,
 * jejich podoba je rozhraní pro jiné programy.
 */

const ALLSTAT_LOCALES = ['cs' => 'Čeština', 'sk' => 'Slovenčina', 'en' => 'English'];

/** Aktuální jazyk; s parametrem ho nastaví (neznámý jazyk = čeština). */
function allstat_locale(?string $set = null): string
{
    static $locale = null;

    if ($set !== null) {
        $locale = isset(ALLSTAT_LOCALES[$set]) ? $set : 'cs';
    } elseif ($locale === null) {
        $cookie = $_COOKIE['allstat_lang'] ?? '';
        $locale = is_string($cookie) && isset(ALLSTAT_LOCALES[$cookie]) ? $cookie : 'cs';
    }

    return $locale;
}

/**
 * Jazyk podle přihlášeného uživatele, jinak podle výchozího jazyka instalace (když návštěvník nemá cookie).
 * Volá se z allstat_current_user, takže platí na všech stránkách po přihlášení.
 */
function allstat_locale_apply(?PDO $pdo, ?array $user): void
{
    // Jazyk přihlášeného uživatele má přednost: pozdější volání bez uživatele (např. _legal.php načtený
    // ze stránky Nastavení) ho už nepřepíše.
    static $fromUser = false;
    if ($user === null && $fromUser) {
        return;
    }
    $userLocale = (string) ($user['locale'] ?? '');
    if (isset(ALLSTAT_LOCALES[$userLocale])) {
        allstat_locale($userLocale);
        $fromUser = true;
        return;
    }
    $cookie = $_COOKIE['allstat_lang'] ?? '';
    if (is_string($cookie) && isset(ALLSTAT_LOCALES[$cookie])) {
        allstat_locale($cookie);
        return;
    }
    if ($pdo && function_exists('allstat_setting')) {
        try {
            allstat_locale((string) allstat_setting($pdo, 'app.locale', 'cs'));
        } catch (Throwable) {
            // bez tabulky nastavení (instalace) zůstane čeština
        }
    }
}

/** Slovník jazyka: sloučené soubory lang/<jazyk>/*.php. Čeština slovník nepotřebuje. */
function allstat_i18n_dictionary(string $locale): array
{
    static $cache = [];

    if ($locale === 'cs' || !isset(ALLSTAT_LOCALES[$locale])) {
        return [];
    }
    if (!isset($cache[$locale])) {
        $dictionary = [];
        foreach (glob(dirname(__DIR__) . '/lang/' . $locale . '/*.php') ?: [] as $file) {
            if (basename($file) === 'js.php') {
                continue; // texty pro JavaScript jdou jen do data-i18n (allstat_i18n_js_attribute)
            }
            $part = (static fn (string $path): mixed => require $path)($file);
            if (is_array($part)) {
                $dictionary += $part;
            }
        }
        $cache[$locale] = $dictionary;
    }

    return $cache[$locale];
}

if (!function_exists('t')) {
    /** Překlad českého textu do jazyka uživatele, s dosazením {proměnných}. */
    function t(string $text, array $vars = []): string
    {
        $translated = allstat_i18n_dictionary(allstat_locale())[$text] ?? $text;
        if ($vars === []) {
            return $translated;
        }
        $replace = [];
        foreach ($vars as $key => $value) {
            $replace['{' . $key . '}'] = (string) $value;
        }

        return strtr($translated, $replace);
    }
}

if (!function_exists('tc')) {
    /**
     * Překlad s kontextem pro český text s víc významy (Odhlášení = z účtu i odhlášení sledujících). Ve slovníku
     * je klíč 'kontext|text'; bez něj platí běžný překlad textu, česky se zobrazí text beze změny.
     */
    function tc(string $context, string $text, array $vars = []): string
    {
        $dictionary = allstat_i18n_dictionary(allstat_locale());
        if (!isset($dictionary[$context . '|' . $text])) {
            return t($text, $vars);
        }
        $replace = [];
        foreach ($vars as $key => $value) {
            $replace['{' . $key . '}'] = (string) $value;
        }

        return strtr($dictionary[$context . '|' . $text], $replace);
    }
}

if (!function_exists('tn')) {
    /**
     * Množné číslo. Čeština a slovenština mají tvary pro 1, 2 až 4 a 5 a víc; každý tvar je ve slovníku zvlášť
     * (v angličtině mají 2 až 4 i 5+ stejný překlad). {count} se dosadí naformátované.
     */
    function tn(int $count, string $one, string $few, string $many, array $vars = []): string
    {
        $abs = abs($count);
        $form = $abs === 1 ? $one : ($abs >= 2 && $abs <= 4 ? $few : $many);

        return t($form, $vars + ['count' => function_exists('allstat_number') ? allstat_number($count) : (string) $count]);
    }
}

/** Oddělovače čísel podle jazyka: [desetinný, tisíce]. */
function allstat_locale_separators(): array
{
    return allstat_locale() === 'en' ? ['.', ','] : [',', ' '];
}

/** Názvy měsíců v 1. pádě (leden...) podle jazyka. */
function allstat_month_names(): array
{
    return match (allstat_locale()) {
        'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        'sk' => ['január', 'február', 'marec', 'apríl', 'máj', 'jún', 'júl', 'august', 'september', 'október', 'november', 'december'],
        default => ['leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'],
    };
}

/** Datum pro zobrazení: 22. 10. 2026 (cs, sk), 22 Oct 2026 (en). $withTime přidá čas. */
function allstat_date_label(string|int|null $value, bool $withTime = false): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $timestamp = is_int($value) ? $value : strtotime((string) $value);
    if ($timestamp === false) {
        return (string) $value;
    }

    return date(allstat_locale() === 'en' ? ($withTime ? 'j M Y H:i' : 'j M Y') : ($withTime ? 'j. n. Y H:i' : 'j. n. Y'), $timestamp);
}

/** Kód jazyka pro Intl v prohlížeči (Chart.js, formátování čísel v JS). */
function allstat_locale_bcp47(): string
{
    return ['cs' => 'cs-CZ', 'sk' => 'sk-SK', 'en' => 'en-GB'][allstat_locale()] ?? 'cs-CZ';
}

/**
 * Texty pro JavaScript (soubory lang/<jazyk>/js.php) jako JSON do atributu data-i18n na <html>. Skripty si je
 * čtou přes window.allstatT (assets/js/i18n.js); bez slovníku vrací český originál.
 */
function allstat_i18n_js_attribute(): string
{
    $locale = allstat_locale();
    if ($locale === 'cs') {
        return '';
    }
    $file = dirname(__DIR__) . '/lang/' . $locale . '/js.php';
    $dictionary = is_file($file) ? (static fn (string $path): mixed => require $path)($file) : [];

    return is_array($dictionary) && $dictionary
        ? ' data-i18n="' . htmlspecialchars((string) json_encode($dictionary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') . '"'
        : '';
}

/** Přepínač jazyka (formulář na admin/locale.php); select odešle formulář sám (assets/js/i18n.js). */
function allstat_locale_switcher(array $config): string
{
    $options = '';
    foreach (ALLSTAT_LOCALES as $code => $label) {
        // Krátké kódy (CS, SK, EN), ať se přepínač vejde i do lišty na mobilu; celý název je v title.
        $options .= '<option value="' . $code . '" title="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"' . (allstat_locale() === $code ? ' selected' : '') . '>' . strtoupper($code) . '</option>';
    }

    return '<form class="locale-switch" method="post" action="' . htmlspecialchars(allstat_url($config, 'admin/locale.php'), ENT_QUOTES, 'UTF-8') . '">'
        . (function_exists('allstat_csrf_field') ? allstat_csrf_field() : '')
        . '<input type="hidden" name="back" value="' . htmlspecialchars((string) ($_SERVER['REQUEST_URI'] ?? ''), ENT_QUOTES, 'UTF-8') . '">'
        . '<select class="input" name="locale" data-locale-switch aria-label="Jazyk / Language">' . $options . '</select></form>';
}
