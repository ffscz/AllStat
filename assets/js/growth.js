/*
 * Pohled „Růst kanálů" (views/growth.php): přepínač Růst kanálů / Srovnání růstu, uvnitř Srovnání
 * Žebříček růstu / Vývoj v čase (index 100 = začátek období) a skrývání kanálů v grafu. Data grafu
 * posílá index.php v window.ALLSTAT_GROWTH. Barvy kanálů se čtou z CSS proměnných --ch-* (.growth-root),
 * takže graf po přepnutí motivu převezme tmavé odstíny.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-growth]');
    if (!root) { return; }

    var data = window.ALLSTAT_GROWTH || { labels: [], longLabels: [], series: [] };
    var chart = null;
    var view = 'rank';
    var hidden = {};
    // Reklama je ve výchozím stavu skrytá: kampaně skáčou v řádu stovek procent a zmáčkly by ostatní
    // křivky u nuly (chip zůstává, jedním klikem se zapne). Stav chipu rendruje views/growth.php stejně.
    data.series.forEach(function (s, i) { if (s.color === 'ads') { hidden[i] = true; } });

    function cssVar(el, name) {
        return getComputedStyle(el).getPropertyValue(name).trim();
    }

    function selectTab(buttons, active, attr) {
        buttons.forEach(function (b) {
            var on = b === active;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        return active.getAttribute(attr);
    }

    // Čára na indexu 100 (= úroveň začátku období), přerušovaná a tlumená, pod křivkami.
    var baseline = {
        id: 'growthBaseline',
        beforeDatasetsDraw: function (c) {
            var y = c.scales.y.getPixelForValue(100);
            if (y < c.chartArea.top || y > c.chartArea.bottom) { return; }
            var ctx = c.ctx;
            ctx.save();
            ctx.strokeStyle = cssVar(document.documentElement, '--muted');
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
        var canvas = document.getElementById('growthChart');
        if (!canvas || !window.Chart) { return; }
        if (chart) { chart.destroy(); chart = null; }
        var muted = cssVar(document.documentElement, '--muted');
        var grid = cssVar(document.documentElement, '--chart-grid');
        var text = cssVar(document.documentElement, '--text');
        var surface = cssVar(document.documentElement, '--surface');

        chart = new Chart(canvas, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: data.series.map(function (s, i) {
                    var color = cssVar(root, '--ch-' + s.color) || muted;
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
                    legend: { display: false }, // legendu tvoří vlastní chipy nad grafem (skrývání kanálů)
                    tooltip: {
                        backgroundColor: text,
                        titleColor: surface,
                        bodyColor: surface,
                        padding: 10,
                        itemSort: function (a, b) { return b.parsed.y - a.parsed.y; },
                        callbacks: {
                            // Plný barevný čtvereček kanálu (výchozí by vzal bílou výplň bodu).
                            labelColor: function (item) {
                                var c = item.dataset.borderColor;
                                return { borderColor: c, backgroundColor: c, borderWidth: 0, borderRadius: 2 };
                            },
                            title: function (items) { return items.length ? data.longLabels[items[0].dataIndex] : ''; },
                            label: function (item) {
                                var s = data.series[item.datasetIndex];
                                var raw = (s.raw && s.raw[item.dataIndex]) || '';
                                return ' ' + s.name + (s.account ? ' · ' + s.account : '') + ': index ' + Math.round(item.parsed.y) + (raw ? ' (' + raw + ')' : '');
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: muted } },
                    y: {
                        grid: { color: grid },
                        ticks: { color: muted },
                        title: { display: true, text: 'Index (100 = začátek období)', color: muted }
                    }
                }
            },
            plugins: [baseline]
        });
    }

    // Růst kanálů | Srovnání růstu
    var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-growth-tab]'));
    tabs.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tab = selectTab(tabs, btn, 'data-growth-tab');
            root.querySelectorAll('[data-growth-panel]').forEach(function (p) {
                p.hidden = p.getAttribute('data-growth-panel') !== tab;
            });
            if (tab === 'compare' && view === 'lines') { renderChart(); }
        });
    });

    // Žebříček růstu | Vývoj v čase
    var views = Array.prototype.slice.call(root.querySelectorAll('[data-growth-view]'));
    views.forEach(function (btn) {
        btn.addEventListener('click', function () {
            view = selectTab(views, btn, 'data-growth-view');
            root.querySelectorAll('[data-growth-view-panel]').forEach(function (p) {
                p.hidden = p.getAttribute('data-growth-view-panel') !== view;
            });
            var noteRank = root.querySelector('[data-growth-note-rank]');
            var noteLines = root.querySelector('[data-growth-note-lines]');
            if (noteRank) { noteRank.hidden = view !== 'rank'; }
            if (noteLines) { noteLines.hidden = view !== 'lines'; }
            if (view === 'lines') { renderChart(); }
        });
    });

    // Chipy kanálů = legenda + skrývání čar.
    root.querySelectorAll('[data-growth-series]').forEach(function (chip) {
        chip.addEventListener('click', function () {
            var i = Number(chip.getAttribute('data-growth-series'));
            var visible = chip.getAttribute('aria-pressed') !== 'false';
            chip.setAttribute('aria-pressed', visible ? 'false' : 'true');
            hidden[i] = visible;
            if (chart) {
                chart.setDatasetVisibility(i, !visible);
                chart.update();
            }
        });
    });

    // Přepnutí motivu (app.js mění data-theme na <html>) → graf znovu s barvami pro nový motiv.
    new MutationObserver(function () {
        if (chart) { renderChart(); }
    }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
})();
