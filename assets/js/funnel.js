/*
 * Pohled „Trychtýř" (views/funnel.php): čárový graf vývoje počtu událostí jednotlivých kroků v čase.
 * Data posílá index.php v window.ALLSTAT_FUNNEL (labels, longLabels, series[{name, event, data}]).
 * Barvy řad jsou tokeny --series-1..6 (tokens.css); u víc kroků se barvy opakují a řady jsou čárkované.
 * Po přepnutí motivu (allstat:themechange) se graf překreslí s barvami pro nový motiv.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-funnel]');
    if (!root) { return; }

    var data = window.ALLSTAT_FUNNEL || { labels: [], longLabels: [], series: [] };
    var SERIES = 6;
    var number = new Intl.NumberFormat(window.allstatLocale);
    var chart = null;

    function cssVar(name) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    }

    function render() {
        var canvas = document.getElementById('funnelChart');
        if (!canvas || !window.Chart) { return; }
        if (chart) { chart.destroy(); chart = null; }

        var muted = cssVar('--muted');
        var surface = cssVar('--surface');

        chart = new Chart(canvas, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: data.series.map(function (s, i) {
                    var color = cssVar('--series-' + ((i % SERIES) + 1));
                    return {
                        label: (i + 1) + '. ' + s.name,
                        data: s.data,
                        borderColor: color,
                        backgroundColor: color,
                        borderWidth: 2.5,
                        borderDash: i >= SERIES ? [6, 4] : [],
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
                        labels: { usePointStyle: true, pointStyle: 'line', boxWidth: 28, color: cssVar('--text-2'), font: { family: cssVar('--font'), weight: 500 } }
                    },
                    tooltip: {
                        backgroundColor: cssVar('--tip-bg'),
                        titleColor: cssVar('--tip-fg'),
                        bodyColor: cssVar('--tip-fg'),
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
                        grid: { color: cssVar('--grid') },
                        ticks: { color: muted, precision: 0 },
                        title: { display: true, text: window.allstatT('Počet událostí'), color: muted }
                    }
                }
            }
        });
    }

    document.addEventListener('allstat:themechange', function () { if (chart) { render(); } });

    render();
})();
