// Překlady pro skripty: slovník z atributu data-i18n na <html> (lib/i18n.php, soubory lang/<jazyk>/js.php).
// window.allstatT('Český text', {promenna: 1}) vrátí překlad, bez slovníku český originál.
(function () {
    var dictionary = {};
    try {
        dictionary = JSON.parse(document.documentElement.getAttribute('data-i18n') || '{}');
    } catch (e) {
        dictionary = {};
    }

    window.allstatT = function (text, vars) {
        var out = Object.prototype.hasOwnProperty.call(dictionary, text) ? dictionary[text] : text;
        if (vars) {
            Object.keys(vars).forEach(function (key) {
                out = out.split('{' + key + '}').join(String(vars[key]));
            });
        }
        return out;
    };

    // Kód jazyka pro Intl (čísla, data, řazení): cs-CZ, sk-SK, en-GB.
    window.allstatLocale = ({ cs: 'cs-CZ', sk: 'sk-SK', en: 'en-GB' })[document.documentElement.getAttribute('lang') || 'cs'] || 'cs-CZ';

    // Přepínač jazyka (allstat_locale_switcher): změna výběru odešle formulář.
    document.addEventListener('change', function (event) {
        var select = event.target && event.target.closest ? event.target.closest('select[data-locale-switch]') : null;
        if (select && select.form) {
            select.form.submit();
        }
    });
})();
