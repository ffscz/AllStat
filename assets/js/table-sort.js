/*
 * Řazení tabulek kliknutím na záhlaví (celý AllStat: přehled, provider views i administrace).
 * Bez konfigurace: každá <table> s <thead> a <tbody> je řaditelná, klik na sloupec = vzestupně,
 * druhý klik = sestupně. Hodnoty se rozpoznávají z textu buňky v českém formátu: čísla „4 970",
 * „2,71 %", „+3"; doby „10 h 53 min", „2 min 32 s"; data „10.09. 12:30", „1. 9. 2026", ISO.
 * Přesnou hodnotu lze vnutit atributem data-sort na <td> (např. ISO datum s rokem). Vypnutí:
 * data-no-sort na <table> nebo na <th>. Řádky s colspan (hlášky „bez dat") zůstávají na místě.
 * Delegováno na document, takže funguje i pro tabulky překreslené AJAXem (přehled GA4).
 */
(function () {
    'use strict';

    var SPACES = /[\s\u00a0\u202f]/g;
    var collator = new Intl.Collator('cs', { numeric: true, sensitivity: 'base' });

    function parseNumber(text) {
        var t = text.replace(SPACES, '').replace(/\u2212/g, '-').replace(/[%\u00d7x]$/i, '');
        if (!/^[+-]?\d+(?:[.,]\d+)?$/.test(t)) { return null; }
        return parseFloat(t.replace(',', '.'));
    }

    function parseDuration(text) {
        var m = text.match(/^(?:(\d+)\s*h)?\s*(?:(\d+)\s*min)?\s*(?:(\d+)\s*s)?$/);
        if (!m || (m[1] === undefined && m[2] === undefined && m[3] === undefined)) { return null; }
        return (+m[1] || 0) * 3600 + (+m[2] || 0) * 60 + (+m[3] || 0);
    }

    function parseDate(text) {
        var m = text.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
        if (m) { return Date.UTC(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0)); }
        m = text.match(/^(\d{1,2})\.\s?(\d{1,2})\.(?:\s?(\d{4}))?(?:\s+(\d{1,2}):(\d{2}))?$/);
        if (m) { return Date.UTC(m[3] ? +m[3] : 2000, +m[2] - 1, +m[1], +(m[4] || 0), +(m[5] || 0)); }
        return null;
    }

    function cellValue(td) {
        if (!td) { return { num: null, text: '' }; }
        if (td.dataset.sort !== undefined) {
            var forced = td.dataset.sort;
            var n = parseNumber(forced);
            if (n === null) { n = parseDate(forced); }
            return { num: n, text: forced };
        }
        var text = td.textContent.replace(/[\u2191\u2193]/g, '').trim();
        if (text === '' || text === '\u2014' || text === '-') { return { num: null, text: '' }; }
        var num = parseNumber(text);
        if (num === null) { num = parseDuration(text); }
        if (num === null) { num = parseDate(text); }
        return { num: num, text: text };
    }

    function compare(a, b, dir) {
        // Prázdné („\u2014") vždy na konec bez ohledu na směr.
        var aEmpty = a.num === null && a.text === '';
        var bEmpty = b.num === null && b.text === '';
        if (aEmpty || bEmpty) { return aEmpty === bEmpty ? 0 : (aEmpty ? 1 : -1); }
        var r;
        if (a.num !== null && b.num !== null) { r = a.num - b.num; }
        else if (a.num !== null) { r = -1; }
        else if (b.num !== null) { r = 1; }
        else { r = collator.compare(a.text, b.text); }
        return dir === 'desc' ? -r : r;
    }

    function sortTable(table, th, dir) {
        var headRow = th.parentNode;
        var index = Array.prototype.indexOf.call(headRow.children, th);
        Array.prototype.forEach.call(table.tBodies, function (tbody) {
            var rows = Array.prototype.slice.call(tbody.rows);
            var sortable = rows.filter(function (row) {
                return row.cells.length > 1 && !Array.prototype.some.call(row.cells, function (c) { return c.colSpan > 1; });
            });
            if (sortable.length < 2) { return; }
            var keyed = sortable.map(function (row, i) { return { row: row, i: i, v: cellValue(row.cells[index]) }; });
            keyed.sort(function (x, y) { return compare(x.v, y.v, dir) || (x.i - y.i); });
            var anchor = sortable[sortable.length - 1].nextSibling; // řádky s colspan za seřazenými zůstanou na konci
            keyed.forEach(function (k) { tbody.insertBefore(k.row, anchor); });
        });
        Array.prototype.forEach.call(headRow.children, function (cell) {
            cell.classList.remove('is-sorted-asc', 'is-sorted-desc');
            cell.removeAttribute('aria-sort');
        });
        th.classList.add(dir === 'desc' ? 'is-sorted-desc' : 'is-sorted-asc');
        th.setAttribute('aria-sort', dir === 'desc' ? 'descending' : 'ascending');
    }

    function isSortableHeader(th) {
        if (!th || th.tagName !== 'TH' || th.hasAttribute('data-no-sort')) { return false; }
        var table = th.closest('table');
        if (!table || table.hasAttribute('data-no-sort') || !table.tHead || !table.tBodies.length) { return false; }
        if (th.closest('thead') !== table.tHead || th.parentNode !== table.tHead.rows[0]) { return false; }
        if (th.colSpan > 1 || th.textContent.trim() === '') { return false; }
        return true;
    }

    function decorate(root) {
        var ths = (root || document).querySelectorAll('table > thead > tr:first-child > th');
        Array.prototype.forEach.call(ths, function (th) {
            if (isSortableHeader(th)) {
                th.classList.add('is-sortable');
                th.tabIndex = 0;
                th.title = th.title || 'Kliknutím seřadíš (vzestupně / sestupně)';
            }
        });
    }

    document.addEventListener('click', function (event) {
        var th = event.target.closest('th');
        if (!isSortableHeader(th) || event.target.closest('a, button, input, select')) { return; }
        var table = th.closest('table');
        var dir = th.classList.contains('is-sorted-asc') ? 'desc' : 'asc';
        sortTable(table, th, dir);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') { return; }
        var th = event.target.closest && event.target.closest('th');
        if (!isSortableHeader(th)) { return; }
        event.preventDefault();
        sortTable(th.closest('table'), th, th.classList.contains('is-sorted-asc') ? 'desc' : 'asc');
    });

    // Vizuální označení řaditelných záhlaví i po AJAX překreslení (přehled) a rozbalení <details>.
    var pending = false;
    var observer = new MutationObserver(function () {
        if (pending) { return; }
        pending = true;
        window.requestAnimationFrame(function () { pending = false; decorate(document); });
    });
    document.addEventListener('DOMContentLoaded', function () {
        decorate(document);
        observer.observe(document.body, { childList: true, subtree: true });
    });
})();
