/*
 * Úvodní Přehled (views/growth.php): změna období bez reloadu stránky, graf Vývoj v čase (index 100 =
 * začátek období) a skrývání kanálů v grafu přes legendu. Data grafu jsou v <script type="application/json"
 * data-growth-json> uvnitř bloku, takže po načtení jiného období přijdou spolu s novým markupem.
 * Barvy grafu se čtou z tokenů na :root (tokens.css), po přepnutí motivu (allstat:themechange) se graf překreslí.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-growth]');
    if (!root) { return; }

    var data = null;
    var chart = null;
    var hidden = {};

    function token(name) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    }

    // Data grafu z bloku; reklama je ve výchozím stavu skrytá: kampaně skáčou v řádu stovek procent
    // a zmáčkly by ostatní křivky u nuly (legenda ji jedním klikem zapne, stav rendruje views/growth.php stejně).
    function readData() {
        var el = root.querySelector('[data-growth-json]');
        try {
            data = el ? JSON.parse(el.textContent) : null;
        } catch (e) {
            data = null;
        }
        data = data || { labels: [], longLabels: [], series: [] };
        hidden = {};
        data.series.forEach(function (s, i) { if (s.color === 'ads') { hidden[i] = true; } });
    }

    // Barva čáry kanálu: token z legendy (data-color-token, barva služby, např. --src-fb-fg).
    function seriesColor(i) {
        var item = root.querySelector('[data-growth-series="' + i + '"]');
        return (item && token(item.getAttribute('data-color-token'))) || token('--muted');
    }

    // Čára na indexu 100 (= úroveň začátku období), přerušovaná a tlumená, pod křivkami.
    var baseline = {
        id: 'growthBaseline',
        beforeDatasetsDraw: function (c) {
            var y = c.scales.y.getPixelForValue(100);
            if (y < c.chartArea.top || y > c.chartArea.bottom) { return; }
            var ctx = c.ctx;
            ctx.save();
            ctx.strokeStyle = token('--muted');
            ctx.globalAlpha = 0.7;
            ctx.lineWidth = 1;
            ctx.setLineDash([4, 4]);
            ctx.beginPath();
            ctx.moveTo(c.chartArea.left, y);
            ctx.lineTo(c.chartArea.right, y);
            ctx.stroke();
            ctx.restore();
        }
    };

    function renderChart() {
        if (chart) { chart.destroy(); chart = null; }
        var canvas = document.getElementById('growthChart');
        if (!canvas || !window.Chart || !data.series.length) { return; }
        var muted = token('--muted');
        var surface = token('--surface');
        var tipBg = token('--tip-bg');
        var tipFg = token('--tip-fg');

        chart = new Chart(canvas, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: data.series.map(function (s, i) {
                    var color = seriesColor(i);
                    return {
                        label: s.name + (s.account ? ' · ' + s.account : ''),
                        data: s.index,
                        borderColor: color,
                        backgroundColor: color,
                        borderWidth: 2,
                        borderDash: s.dashed ? [6, 4] : [],
                        pointRadius: 3,
                        pointHoverRadius: 6,
                        pointBackgroundColor: surface,
                        pointBorderWidth: 2,
                        // Monotónní křivka: žádné vymyšlené vrcholy/propady mezi měsíci (běžné zaoblení přestřeluje).
                        cubicInterpolationMode: 'monotone',
                        spanGaps: false,
                        hidden: !!hidden[i]
                    };
                })
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false }, // legendu tvoří tlačítka nad grafem (skrývání kanálů)
                    tooltip: {
                        backgroundColor: tipBg,
                        titleColor: tipFg,
                        bodyColor: tipFg,
                        padding: 10,
                        itemSort: function (a, b) { return b.parsed.y - a.parsed.y; },
                        callbacks: {
                            // Plný barevný čtvereček kanálu (výchozí by vzal výplň bodu).
                            labelColor: function (item) {
                                var c = item.dataset.borderColor;
                                return { borderColor: c, backgroundColor: c, borderWidth: 0, borderRadius: 2 };
                            },
                            title: function (items) { return items.length ? data.longLabels[items[0].dataIndex] : ''; },
                            label: function (item) {
                                var s = data.series[item.datasetIndex];
                                var raw = (s.raw && s.raw[item.dataIndex]) || '';
                                return ' ' + s.name + (s.account ? ' · ' + s.account : '') + ': ' + window.allstatT('index') + ' ' + Math.round(item.parsed.y) + (raw ? ' (' + raw + ')' : '');
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, border: { color: token('--border') }, ticks: { color: muted } },
                    y: {
                        grid: { color: token('--grid') },
                        border: { display: false },
                        ticks: { color: muted },
                        title: { display: true, text: window.allstatT('Index (100 = začátek období)'), color: muted }
                    }
                }
            },
            plugins: [baseline]
        });
    }

    // Legenda = skrývání čar.
    root.addEventListener('click', function (event) {
        var item = event.target.closest('[data-growth-series]');
        if (!item) { return; }
        var i = Number(item.getAttribute('data-growth-series'));
        var visible = item.getAttribute('aria-pressed') !== 'false';
        item.setAttribute('aria-pressed', visible ? 'false' : 'true');
        hidden[i] = visible;
        if (chart) {
            chart.setDatasetVisibility(i, !visible);
            chart.update();
        }
    });

    // Období 3 / 6 / 12 měsíců: načte stránku nového období a vymění jen tenhle blok a údaje v záhlaví.
    // Odkazy fungují i bez JS (plný reload), při chybě se na odkaz přejde normálně.
    var seg = document.querySelector('[data-growth-months]');
    var loading = null;

    function syncHead(doc, months) {
        ['[data-page-sub]', '[data-growth-range]'].forEach(function (sel) {
            var cur = document.querySelector(sel);
            var next = doc.querySelector(sel);
            if (cur && next) { cur.textContent = next.textContent; }
        });
        seg.querySelectorAll('[data-months]').forEach(function (link) {
            if (link.getAttribute('data-months') === months) { link.setAttribute('aria-current', 'true'); } else { link.removeAttribute('aria-current'); }
        });
        var share = document.getElementById('shareAiBtn');
        if (share) { share.setAttribute('data-months', months); }
    }

    function loadPeriod(url, months, push) {
        if (loading) { loading.abort(); }
        loading = new AbortController();
        root.setAttribute('aria-busy', 'true');
        root.classList.add('is-loading');
        return fetch(url, { headers: { Accept: 'text/html' }, credentials: 'same-origin', signal: loading.signal })
            .then(function (response) {
                if (!response.ok) { throw new Error('HTTP ' + response.status); }
                return response.text();
            })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var next = doc.querySelector('[data-growth]');
                // Přesměrování na přihlášení apod. = jiná stránka → normální navigace.
                if (!next) { throw new Error('missing view'); }
                root.innerHTML = next.innerHTML;
                syncHead(doc, months);
                if (push) { history.pushState({ growthMonths: months }, '', url); }
                readData();
                renderChart();
            })
            .catch(function (error) {
                if (error.name !== 'AbortError') { window.location.href = url; }
            })
            .finally(function () {
                root.removeAttribute('aria-busy');
                root.classList.remove('is-loading');
            });
    }

    if (seg) {
        seg.addEventListener('click', function (event) {
            var link = event.target.closest('a[data-months]');
            if (!link || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) { return; }
            event.preventDefault();
            if (link.getAttribute('aria-current') === 'true') { return; }
            loadPeriod(link.href, link.getAttribute('data-months'), true);
        });
        // Zpět/vpřed v historii: načíst období z adresy.
        window.addEventListener('popstate', function () {
            var months = new URLSearchParams(window.location.search).get('months') || '12';
            loadPeriod(window.location.href, months, false);
        });
    }

    // Přepnutí motivu (assets/js/shell.js) → graf znovu s barvami nového motivu.
    document.addEventListener('allstat:themechange', function () {
        if (chart) { renderChart(); }
    });

    readData();
    renderChart();
})();
