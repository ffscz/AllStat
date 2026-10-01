/*
 * Pohled „Trychtýř" (views/funnel.php): čárový graf vývoje počtu událostí jednotlivých kroků v čase.
 * Data posílá index.php v window.ALLSTAT_FUNNEL (labels, longLabels, series[{name, event, data}]).
 * Barvy řad jsou stejné jako v ostatních grafech přehledu (app.js); u víc než 7 kroků se barvy opakují a
 * řady jsou čárkované. Po přepnutí motivu se graf překreslí s barvami pro nový motiv.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-funnel]');
    if (!root) { return; }

    var data = window.ALLSTAT_FUNNEL || { labels: [], longLabels: [], series: [] };
    var palette = ['#2563eb', '#16a34a', '#8b5cf6', '#f59e0b', '#06b6d4', '#f43f5e', '#14b8a6'];
    var number = new Intl.NumberFormat('cs-CZ');
    var chart = null;

    function cssVar(name) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    }

    function render() {
        var canvas = document.getElementById('funnelChart');
        if (!canvas || !window.Chart) { return; }
        if (chart) { chart.destroy(); chart = null; }

        var muted = cssVar('--muted');
        var text = cssVar('--text');
        var surface = cssVar('--surface');

        chart = new Chart(canvas, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: data.series.map(function (s, i) {
                    var color = palette[i % palette.length];
                    return {
                        label: (i + 1) + '. ' + s.name,
                        data: s.data,
                        borderColor: color,
                        backgroundColor: color,
                        borderWidth: 2.5,
                        borderDash: i >= palette.length ? [6, 4] : [],
                        tension: 0.3,
                        pointRadius: data.labels.length > 40 ? 0 : 3,
                        pointHoverRadius: 5,
                        pointBackgroundColor: surface,
                        pointBorderWidth: 2
                    };
                })
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        align: 'start',
                        labels: { usePointStyle: true, pointStyle: 'line', boxWidth: 28, color: muted, font: { weight: 700 } }
                    },
                    tooltip: {
                        backgroundColor: text,
                        titleColor: surface,
                        bodyColor: surface,
                        padding: 10,
                        callbacks: {
                            title: function (items) { return items.length ? data.longLabels[items[0].dataIndex] : ''; },
                            label: function (item) { return ' ' + item.dataset.label + ': ' + number.format(item.parsed.y); }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: muted, maxTicksLimit: 8 } },
                    y: {
                        beginAtZero: true,
                        grid: { color: cssVar('--chart-grid') },
                        ticks: { color: muted, precision: 0 },
                        title: { display: true, text: 'Počet událostí', color: muted }
                    }
                }
            }
        });
    }

    // app.js přepíná data-theme na <html> → graf znovu s barvami pro nový motiv.
    new MutationObserver(function () { if (chart) { render(); } })
        .observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

    render();
})();
