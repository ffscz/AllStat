const AllStat = (() => {
    const charts = new Map();
    const palette = {
        blue: '#2563eb',
        green: '#16a34a',
        violet: '#8b5cf6',
        orange: '#f59e0b',
        cyan: '#06b6d4',
        rose: '#f43f5e',
        teal: '#14b8a6'
    };
    const statusLabels = { ok: 'OK', warning: 'Varování', error: 'Chyba' };
    const formatter = new Intl.NumberFormat('cs-CZ');

    function cssVariable(name) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function iconRefresh() {
        return '<i data-lucide="refresh-cw"></i>';
    }

    function destroyChart(key) {
        if (charts.has(key)) {
            charts.get(key).destroy();
            charts.delete(key);
        }
    }

    function renderLineChart(data) {
        const canvas = document.getElementById('visitsChart');

        if (!canvas || !window.Chart) {
            return;
        }

        destroyChart('visits');
        charts.set('visits', new Chart(canvas, {
            type: 'line',
            data: {
                labels: data.charts.visits.labels,
                datasets: [
                    {
                        label: 'Návštěvy (GA4)',
                        data: data.charts.visits.visits,
                        borderColor: palette.blue,
                        backgroundColor: 'transparent',
                        tension: 0.38,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        borderWidth: 3
                    },
                    {
                        label: 'Uživatelé (GA4)',
                        data: data.charts.visits.users,
                        borderColor: palette.green,
                        backgroundColor: 'transparent',
                        tension: 0.38,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        borderWidth: 3
                    },
                    {
                        label: 'Konverze (GA4)',
                        data: data.charts.visits.conversions,
                        borderColor: palette.violet,
                        backgroundColor: 'transparent',
                        tension: 0.38,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        borderWidth: 3,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        align: 'start',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'line',
                            boxWidth: 28,
                            color: cssVariable('--muted'),
                            font: { weight: 700 }
                        }
                    },
                    tooltip: {
                        backgroundColor: cssVariable('--text'),
                        titleColor: cssVariable('--surface'),
                        bodyColor: cssVariable('--surface')
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: cssVariable('--muted'), maxTicksLimit: 7 }
                    },
                    y: {
                        beginAtZero: true,
                        position: 'left',
                        grid: { color: cssVariable('--chart-grid') },
                        ticks: { color: cssVariable('--muted') },
                        title: { display: true, text: 'Návštěvy / uživatelé', color: cssVariable('--muted') }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        grid: { drawOnChartArea: false },
                        ticks: { color: cssVariable('--muted') },
                        title: { display: true, text: 'Konverze', color: cssVariable('--muted') }
                    }
                }
            }
        }));
    }

    // Horizontální bar grafy (Zdroje návštěv / Zařízení / Noví vs vracející) — šířka pruhu je poměr
    // k NEJVĚTŠÍ položce (max čitelnost rozdílů), podíl z celku říká procento v závorce.
    function renderBarRows(selector, rows, colors) {
        const holder = document.querySelector(selector);

        if (!holder) {
            return;
        }

        if (!rows.length) {
            holder.innerHTML = '<p class="table-muted">Zatím bez dat, spusť GA4 sync.</p>';
            return;
        }

        const max = Math.max(1, ...rows.map((r) => r.value || 0));
        holder.innerHTML = rows.map((r, i) => `
            <div class="bar-row">
                <span class="bar-row-label" title="${escapeHtml(r.label)}">${escapeHtml(r.label)}</span>
                <div class="bar-row-track"><div class="bar-row-fill" style="width: ${Math.max(2, (r.value || 0) / max * 100).toFixed(1)}%; background: ${colors[i % colors.length]};"></div></div>
                <span class="bar-row-value"><strong>${escapeHtml(r.valueLabel)}</strong> <small>(${escapeHtml(r.shareLabel)})</small></span>
            </div>
        `).join('');
    }

    function renderTrafficChart(data) {
        const chart = data.charts && data.charts.traffic;

        if (!chart) {
            return;
        }

        renderBarRows('[data-bar-traffic]', (chart.sources || []).map((s) => ({
            label: s.source, value: s.sessions, valueLabel: s.sessionsLabel, shareLabel: s.shareLabel
        })), [palette.blue, palette.green, palette.violet, palette.orange, palette.cyan, palette.rose]);

        const total = document.querySelector('[data-traffic-total]');
        if (total) {
            total.textContent = chart.totalLabel;
        }
    }

    function renderSparklines(data) {
        document.querySelectorAll('[data-sparkline]').forEach((canvas, index) => {
            const key = canvas.dataset.sparkline;
            const kpi = data.kpis.find((item) => item.key === key);

            if (!kpi || !window.Chart) {
                return;
            }

            const chartKey = `sparkline-${key}-${index}`;
            destroyChart(chartKey);
            charts.set(chartKey, new Chart(canvas, {
                type: 'line',
                data: {
                    labels: data.charts.visits.labels,
                    datasets: [{
                        data: kpi.sparkline,
                        borderColor: palette[kpi.color] || palette.blue,
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        tension: 0.42,
                        pointRadius: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: { enabled: false } },
                    scales: { x: { display: false }, y: { display: false } },
                    elements: { line: { capBezierPoints: true } }
                }
            }));
        });
    }

    function renderDeviceChart(data) {
        const chart = data.charts && data.charts.devices;

        if (!chart) {
            return;
        }

        renderBarRows('[data-bar-devices]', (chart.items || []).map((d, i) => ({
            label: d.device, value: d.sessions ?? (chart.values || [])[i], valueLabel: d.sessionsLabel, shareLabel: d.shareLabel
        })), [palette.blue, palette.green, palette.violet, palette.orange, palette.cyan, palette.rose]);

        const total = document.querySelector('[data-device-total]');
        if (total) {
            total.textContent = chart.totalLabel;
        }
    }

    function renderNewReturningChart(data) {
        const chart = data.charts && data.charts.newReturning;

        if (!chart) {
            return;
        }

        renderBarRows('[data-bar-newreturning]', (chart.segments || []).map((s, i) => ({
            label: s.label, value: s.value ?? (chart.values || [])[i], valueLabel: s.valueLabel, shareLabel: s.shareLabel
        })), [palette.blue, palette.green]);

        const total = document.querySelector('[data-newreturning-total]');
        if (total) {
            total.textContent = chart.totalLabel;
        }
    }

    let pagesMode = 'vstupni';

    function renderPagesTable(data) {
        const head = document.querySelector('[data-pages-head]');
        const body = document.querySelector('[data-pages-rows]');
        const help = document.querySelector('[data-pages-help]');

        if (!body) {
            return;
        }

        const landing = (data.tables && data.tables.landingPages) || [];
        const all = (data.tables && data.tables.allPages) || [];
        const emptyRow = '<tr><td colspan="3" class="table-muted">Zatím bez dat o stránkách, spusť GA4 sync.</td></tr>';

        if (pagesMode === 'vsechny') {
            if (head) {
                head.innerHTML = '<th>Stránka</th><th>Zobrazení</th><th>Podíl</th>';
            }
            if (help) {
                help.innerHTML = 'Nejnavštěvovanější stránky podle počtu zobrazení (GA4 dimenze <code>pagePath</code> / <code>screenPageViews</code>) za zvolené období.';
            }
            body.innerHTML = all.length ? all.map((p) => `
                <tr>
                    <td>${escapeHtml(p.path)}</td>
                    <td>${escapeHtml(p.viewsLabel)}</td>
                    <td>${escapeHtml(p.shareLabel)}</td>
                </tr>
            `).join('') : emptyRow;
        } else {
            if (head) {
                head.innerHTML = '<th>Stránka</th><th>Návštěvy</th><th>Změna</th>';
            }
            if (help) {
                help.innerHTML = 'Top vstupní stránky, kde návštěva začala. GA4 dimenze <code>landingPage</code>. „Změna" = rozdíl proti předchozímu stejně dlouhému období.';
            }
            body.innerHTML = landing.length ? landing.map((p) => `
                <tr>
                    <td>${escapeHtml(p.path)}</td>
                    <td>${formatter.format(p.sessions)}</td>
                    <td class="${p.change >= 0 ? 'positive' : 'negative'}">${p.change >= 0 ? '&uarr;' : '&darr;'} ${escapeHtml(p.changeLabel)}</td>
                </tr>
            `).join('') : emptyRow;
        }
    }

    function bindPagesToggle() {
        const buttons = document.querySelectorAll('[data-pages-toggle]');

        buttons.forEach((btn) => {
            btn.addEventListener('click', () => {
                pagesMode = btn.dataset.pagesToggle === 'vsechny' ? 'vsechny' : 'vstupni';
                buttons.forEach((b) => b.classList.toggle('is-active', b === btn));
                renderPagesTable(window.ALLSTAT_DATA);
            });
        });
    }

    function renderProviderView() {
        const provider = window.ALLSTAT_PROVIDER;

        if (!provider || !Array.isArray(provider.metrics) || !window.Chart) {
            return;
        }

        const lineColors = [palette.blue, palette.green, palette.violet, palette.orange, palette.cyan, palette.rose, palette.teal];
        const canvas = document.getElementById('providerChart');
        const chartMetrics = Array.isArray(provider.chartKeys)
            ? provider.metrics.filter((m) => provider.chartKeys.includes(m.key))
            : provider.metrics;

        if (canvas && provider.showChart !== false && chartMetrics.length) {
            destroyChart('provider');
            charts.set('provider', new Chart(canvas, {
                type: 'line',
                data: {
                    labels: provider.labels,
                    datasets: chartMetrics.map((metric, index) => ({
                        label: metric.label,
                        data: metric.series,
                        borderColor: lineColors[index % lineColors.length],
                        backgroundColor: 'transparent',
                        tension: 0.38,
                        pointRadius: 2,
                        pointHoverRadius: 5,
                        borderWidth: 2
                    }))
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: {
                            align: 'start',
                            labels: { usePointStyle: true, pointStyle: 'line', boxWidth: 28, color: cssVariable('--muted'), font: { weight: 700 } }
                        },
                        tooltip: { backgroundColor: cssVariable('--text'), titleColor: cssVariable('--surface'), bodyColor: cssVariable('--surface') }
                    },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: cssVariable('--muted'), maxTicksLimit: 8 } },
                        y: { beginAtZero: true, grid: { color: cssVariable('--chart-grid') }, ticks: { color: cssVariable('--muted') } }
                    }
                }
            }));
        }

        provider.metrics.forEach((metric, index) => {
            const spark = document.querySelector(`[data-provider-spark="${CSS.escape(metric.key)}"]`);

            if (!spark) {
                return;
            }

            const chartKey = `provider-spark-${metric.key}-${index}`;
            destroyChart(chartKey);
            charts.set(chartKey, new Chart(spark, {
                type: 'line',
                data: {
                    labels: provider.labels,
                    datasets: [{ data: metric.series, borderColor: palette.cyan, backgroundColor: 'transparent', borderWidth: 2, tension: 0.42, pointRadius: 0 }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: { enabled: false } },
                    scales: { x: { display: false }, y: { display: false } }
                }
            }));
        });
    }

    function renderCharts(data) {
        renderLineChart(data);
        renderTrafficChart(data);
        renderSparklines(data);
        renderDeviceChart(data);
        renderNewReturningChart(data);
        renderProviderView();
    }

    function updateKpis(data) {
        data.kpis.forEach((kpi) => {
            const card = document.querySelector(`[data-kpi-card="${CSS.escape(kpi.key)}"]`);

            if (!card) {
                return;
            }

            const value = card.querySelector('[data-kpi-value]');
            const change = card.querySelector('[data-kpi-change]');

            if (value) {
                value.textContent = kpi.displayValue;
            }

            if (change) {
                change.classList.toggle('trend-up', kpi.trend === 'up');
                change.classList.toggle('trend-down', kpi.trend === 'down');
                change.classList.toggle('trend-none', kpi.trend === 'none');
                if (kpi.trend === 'none') {
                    change.innerHTML = 'bez srovnání';
                } else {
                    change.innerHTML = `${kpi.trend === 'up' ? '&uarr;' : '&darr;'} ${escapeHtml(kpi.changeLabel)}`;
                }
            }
        });

        // Keep "vs. předchozí období (dates)" in sync on AJAX range changes (server renders it only on load).
        const prev = data.previousRange;
        const fmtDate = (iso) => { const d = new Date(iso + 'T00:00:00'); return `${d.getDate()}. ${d.getMonth() + 1}. ${d.getFullYear()}`; };
        const prevLabel = (prev && prev.start && prev.end) ? ` (${fmtDate(prev.start)} – ${fmtDate(prev.end)})` : '';
        document.querySelectorAll('[data-prev-range]').forEach((el) => {
            el.textContent = 'vs. předchozí období' + prevLabel;
        });
    }

    function updateTables(data) {
        renderPagesTable(data);
        const landingRows = document.querySelector('[data-landing-rows]');
        const queryRows = document.querySelector('[data-query-rows]');
        const sourceRows = document.querySelector('[data-source-rows]');
        const geoRows = document.querySelector('[data-geo-rows]');
        const eventRows = document.querySelector('[data-event-rows]');
        const referrerRows = document.querySelector('[data-referrer-rows]');

        if (eventRows) {
            const events = data.tables.events || [];
            eventRows.innerHTML = events.length ? events.map((e) => `
                <tr>
                    <td>${escapeHtml(e.event)}${e.isKey ? ' <span class="status-badge status-ok">key</span>' : ''}</td>
                    <td>${escapeHtml(e.countLabel)}</td>
                    <td>${escapeHtml(e.keyEventsLabel)}</td>
                </tr>
            `).join('') : '<tr><td colspan="3" class="table-muted">Zatím bez event dat, spusť sync.</td></tr>';
        }

        if (referrerRows) {
            const referrers = data.tables.referrers || [];
            referrerRows.innerHTML = referrers.length ? referrers.map((r) => `
                <tr>
                    <td>${escapeHtml(r.source)}${r.ai ? ` <span class="status-badge status-ai">AI · ${escapeHtml(r.ai)}</span>` : ''}</td>
                    <td>${escapeHtml(r.sessionsLabel)}</td>
                    <td>${escapeHtml(r.shareLabel)}</td>
                </tr>
            `).join('') : '<tr><td colspan="3" class="table-muted">Zatím bez referral dat, spusť GA4 sync.</td></tr>';
        }

        if (geoRows) {
            const geo = data.tables.geo || [];
            geoRows.innerHTML = geo.length ? geo.map((g) => `
                <tr>
                    <td>${escapeHtml(g.region)}</td>
                    <td>${escapeHtml(g.country)}</td>
                    <td>${escapeHtml(g.sessionsLabel)}</td>
                    <td>${escapeHtml(g.shareLabel)}</td>
                </tr>
            `).join('') : '<tr><td colspan="4" class="table-muted">Zatím bez geo dat, spusť sync.</td></tr>';
        }

        if (landingRows) {
            landingRows.innerHTML = data.tables.landingPages.map((page) => `
                <tr>
                    <td>${escapeHtml(page.path)}</td>
                    <td>${formatter.format(page.sessions)}</td>
                    <td class="${page.change >= 0 ? 'positive' : 'negative'}">${page.change >= 0 ? '&uarr;' : '&darr;'} ${escapeHtml(page.changeLabel)}</td>
                </tr>
            `).join('');
        }

        if (queryRows) {
            queryRows.innerHTML = data.tables.queries.map((query) => `
                <tr>
                    <td>${escapeHtml(query.query)}</td>
                    <td>${formatter.format(query.clicks)}</td>
                    <td>${formatter.format(query.impressions)}</td>
                    <td>${escapeHtml(query.ctrLabel)}</td>
                </tr>
            `).join('');
        }

        const utmList = document.querySelector('[data-utm-list]');
        if (utmList) {
            // Zrcadlí serverový markup .utm-item v index.php — při změně markupu upravit obojí.
            const utm = data.tables.utm || [];
            utmList.innerHTML = utm.length ? utm.map((c) => `
                <div class="utm-item">
                    <div class="utm-item-top">
                        <span class="utm-name" title="utm_campaign">${escapeHtml(c.campaignLabel)}</span>
                        <span class="utm-value"><strong>${escapeHtml(c.sessionsLabel)}</strong> <small>návštěv · ${escapeHtml(c.shareLabel)}</small></span>
                    </div>
                    <div class="utm-track"><div class="utm-fill" style="width: ${Math.round(Math.max(3, c.share || 0) * 10) / 10}%;"></div></div>
                    <div class="utm-tags">
                        <span class="utm-chip" title="utm_source / utm_medium">${escapeHtml(c.channelLabel)}</span>
                        ${c.contentLabel !== '—' ? `<span class="utm-chip" title="utm_content, A/B varianta">varianta: ${escapeHtml(c.contentLabel)}</span>` : ''}
                        <span class="utm-chip${c.conversions > 0 ? ' utm-chip-ok' : ''}" title="Konverze z této kampaně (a podíl z jejích návštěv)">konverze: ${escapeHtml(c.conversionsLabel)}${c.convRate !== '—' ? ` (${escapeHtml(c.convRate)})` : ''}</span>
                    </div>
                    ${(c.pages || []).length ? `<div class="utm-pages">Kam přišli: ${c.pages.map((p) => `${escapeHtml(p.path)} <span class="table-muted">(${escapeHtml(p.sessionsLabel)})</span>`).join(' · ')}</div>` : ''}
                </div>
            `).join('') : '<p class="table-muted">Zatím žádná návštěvnost s UTM parametry za zvolené období. Otaguj odkazy (např. <code>?utm_campaign=jaro&amp;utm_source=facebook&amp;utm_medium=cpc&amp;utm_content=banner1</code>) a po dalším GA4 syncu se kampaně objeví zde.</p>';
        }

        if (sourceRows) {
            sourceRows.innerHTML = data.tables.sources.length ? data.tables.sources.map((source) => `
                <tr>
                    <td>${escapeHtml(source.source)}</td>
                    <td><span class="status-badge status-${escapeHtml(source.status)}">${escapeHtml(statusLabels[source.status] || source.status)}</span></td>
                    <td>${escapeHtml(source.lastSync)}</td>
                    <td><button class="table-icon-button" type="button" title="Obnovit" aria-label="Obnovit" data-refresh-dashboard>${iconRefresh()}</button></td>
                </tr>
            `).join('') : '<tr><td colspan="4" class="table-muted">Zatím není napojený žádný zdroj dat.</td></tr>';
        }
    }

    function updateMeta(data) {
        const mode = document.querySelector('[data-data-mode]');
        const lastSync = document.querySelector('[data-last-sync]');
        const dot = document.querySelector('.sync-card .status-dot');

        if (mode) {
            mode.textContent = data.meta.databaseMode
                ? (data.meta.sourceCount > 0 ? 'Všechny zdroje jsou synchronizované' : 'Zdroje zatím nejsou napojené')
                : 'Demo data jsou aktivní';
        }

        if (lastSync) {
            lastSync.textContent = data.meta.lastSync;
        }

        if (dot) {
            dot.classList.toggle('status-dot-ok', data.meta.databaseMode);
            dot.classList.toggle('status-dot-warning', !data.meta.databaseMode);
        }
    }

    function updateDashboard(data) {
        window.ALLSTAT_DATA = data;
        updateKpis(data);
        updateTables(data);
        updateMeta(data);
        renderCharts(data);
        window.lucide?.createIcons();
    }

    async function refreshDashboard(params) {
        const shell = document.querySelector('[data-dashboard]');

        shell?.setAttribute('aria-busy', 'true');
        shell?.classList.add('is-loading');

        try {
            const response = await fetch(`api/dashboard.php?${params.toString()}`, {
                headers: { Accept: 'application/json' }
            });

            if (response.status === 401) {
                window.location.href = 'admin/login.php';
                return;
            }

            const payload = await response.json();

            if (!payload.ok) {
                throw new Error('Dashboard API error');
            }

            updateDashboard(payload.data);
            history.replaceState(null, '', `?${params.toString()}`);

            // Picker zdroje dat naviguje s obdobím ze svých data-atributů — po AJAX změně rozsahu
            // je aktualizovat, jinak by přepnutí zdroje vrátilo staré datum.
            const picker = document.querySelector('[data-source-picker]');
            if (picker) {
                picker.dataset.domain = params.get('domain_id') || picker.dataset.domain;
                picker.dataset.start = params.get('start') || picker.dataset.start;
                picker.dataset.end = params.get('end') || picker.dataset.end;
            }
        } finally {
            shell?.removeAttribute('aria-busy');
            shell?.classList.remove('is-loading');
        }
    }

    function bindFilters() {
        const form = document.getElementById('dashboardFilters');

        if (!form) {
            return;
        }

        // Generic provider view re-renders server-side (different payload shape), so it uses a
        // plain full-reload GET form instead of the overview's AJAX refresh. Růst kanálů taky (serverový pohled).
        if (window.ALLSTAT_PROVIDER || window.ALLSTAT_VIEW === 'growth') {
            return;
        }

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            // Manuální rozsah = žádný chip není aktivní (rozsah nemusí odpovídat žádnému rychlému výběru).
            document.querySelectorAll('.quick-ranges .chip.is-active').forEach((c) => c.classList.remove('is-active'));
            refreshDashboard(new URLSearchParams(new FormData(form)));
        });

        document.querySelectorAll('[data-auto-refresh]').forEach((control) => {
            control.addEventListener('change', () => {
                refreshDashboard(new URLSearchParams(new FormData(form)));
            });
        });

        document.addEventListener('click', (event) => {
            const button = event.target.closest('[data-refresh-dashboard]');

            if (button) {
                refreshDashboard(new URLSearchParams(new FormData(form)));
            }
        });
    }

    function bindQuickRanges() {
        const form = document.getElementById('dashboardFilters');

        // Provider views a Růst kanálů se překreslují serverově (jiný payload) → tam chipy zůstávají jako plné odkazy.
        if (!form || window.ALLSTAT_PROVIDER || window.ALLSTAT_VIEW === 'growth') {
            return;
        }

        const startInput = form.querySelector('input[name="start"]');
        const endInput = form.querySelector('input[name="end"]');

        document.querySelectorAll('.quick-ranges .chip[data-range-start]').forEach((chip) => {
            chip.addEventListener('click', (event) => {
                event.preventDefault();
                if (startInput) { startInput.value = chip.dataset.rangeStart; }
                if (endInput) { endInput.value = chip.dataset.rangeEnd; }
                document.querySelectorAll('.quick-ranges .chip').forEach((c) => c.classList.toggle('is-active', c === chip));
                refreshDashboard(new URLSearchParams(new FormData(form)));
            });
        });
    }

    function bindDatePickers() {
        // Nativní ikonky pickeru jsou skryté (CSS) — kalendář otevře klik/tap kamkoli do pole.
        // showPicker() funguje v Chrome/Edge/Firefox/Safari 16+; kde není, pole zůstává normálně editovatelné.
        document.querySelectorAll('.date-filter input[type="date"]').forEach((input) => {
            input.addEventListener('click', () => {
                try { input.showPicker(); } catch (e) { /* fallback: ruční zápis */ }
            });
        });
    }

    function bindSourceSwitch() {
        // Přepínač „Zdroj dat" — vlastní listbox s SVG ikonami providerů (nativní <select> je neumí).
        const picker = document.querySelector('[data-source-picker]');

        if (!picker) {
            return;
        }

        const btn = picker.querySelector('.source-picker-btn');
        const menu = picker.querySelector('.source-picker-menu');

        const close = () => {
            menu.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
        };

        btn.addEventListener('click', () => {
            menu.hidden = !menu.hidden;
            btn.setAttribute('aria-expanded', String(!menu.hidden));
            if (!menu.hidden) {
                (menu.querySelector('.source-picker-item.is-selected') || menu.querySelector('.source-picker-item'))?.focus();
            }
        });

        document.addEventListener('click', (event) => {
            if (!picker.contains(event.target)) {
                close();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !menu.hidden) {
                close();
                btn.focus();
            }
        });

        menu.querySelectorAll('.source-picker-item').forEach((item) => {
            item.addEventListener('click', () => {
                const q = new URLSearchParams({ domain_id: picker.dataset.domain || '' });
                if (item.dataset.view === 'growth') {
                    // Růst kanálů má vlastní měsíční období, denní rozsah se nepřenáší.
                    q.set('view', 'growth');
                } else {
                    // Z Růstu kanálů picker nemá denní rozsah → prázdné start/end = výchozích 7 dní.
                    if (picker.dataset.start) { q.set('start', picker.dataset.start); }
                    if (picker.dataset.end) { q.set('end', picker.dataset.end); }
                    if (item.dataset.view === 'overview') {
                        q.set('view', 'overview');
                    } else {
                        q.set('source_id', item.dataset.value || '0');
                    }
                }
                window.location.href = '?' + q.toString();
            });
        });
    }

    function bindNav() {
        const toggle = document.getElementById('navToggle');
        const shell = document.querySelector('.app-shell');

        if (!toggle || !shell) {
            return;
        }

        const backdrop = document.querySelector('[data-nav-backdrop]');
        const setOpen = (open) => {
            shell.classList.toggle('nav-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            document.body.classList.toggle('nav-locked', open); // zámek scrollu při otevřeném draweru
        };

        toggle.addEventListener('click', () => setOpen(!shell.classList.contains('nav-open')));
        backdrop?.addEventListener('click', () => setOpen(false));
        shell.querySelectorAll('.sidebar-nav a').forEach((a) => a.addEventListener('click', () => setOpen(false)));
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') { setOpen(false); }
        });
    }

    function bindTheme() {
        const button = document.getElementById('themeToggle');

        if (!button) {
            return;
        }

        const syncIcon = () => {
            const isDark = document.documentElement.dataset.theme === 'dark';
            button.innerHTML = `<i data-lucide="${isDark ? 'sun' : 'moon'}"></i>`;
            window.lucide?.createIcons();
            renderCharts(window.ALLSTAT_DATA);
        };

        button.addEventListener('click', () => {
            const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
            document.documentElement.dataset.theme = next;
            localStorage.setItem('allstat-theme', next);
            syncIcon();
        });

        syncIcon();
    }

    // Vyváží počet sloupců KPI mřížek podle počtu karet, aby řady byly souměrné: 7 karet → 4+3
    // (ne 6+1), 8 → 4+4, 6 → 3+3. GA4 přehledové mřížky (.kpi-group) mají vlastní auto-fit layout,
    // ty nechává být.
    function balanceKpiGrids() {
        document.querySelectorAll('.kpi-grid').forEach((grid) => {
            if (grid.closest('.kpi-group')) { return; }
            const n = grid.querySelectorAll('.kpi-card').length;
            if (n < 1) { return; }
            const cols = n <= 4 ? n : Math.min(6, Math.ceil(n / 2));
            grid.style.setProperty('--kpi-cols', String(cols));
        });
    }

    // Aplikuje aktuální filtr: přehled (GA4) = AJAX refresh, provider view = serverový plný reload.
    function applyFilters() {
        const form = document.getElementById('dashboardFilters');
        if (!form) { return; }
        if (window.ALLSTAT_PROVIDER || window.ALLSTAT_VIEW === 'growth') { form.submit(); return; }
        document.querySelectorAll('.quick-ranges .chip.is-active').forEach((c) => c.classList.remove('is-active'));
        refreshDashboard(new URLSearchParams(new FormData(form)));
    }

    // Doména se aplikuje hned po změně (datum řeší vlastní datepicker → bindDateRange).
    // Změna webu = vždy plný reload (form.submit() obchází AJAX submit handler): picker „Zdroj dat"
    // s napojeními daného webu, odkazy na reporty i zapamatovaný web v session se rendrují serverově,
    // AJAX refresh by je nechal u původního webu (picker pak přepínal zdroje starého webu).
    function bindAutoApply() {
        const form = document.getElementById('dashboardFilters');
        if (!form) { return; }
        form.querySelectorAll('select[name="domain_id"]').forEach((sel) => {
            sel.addEventListener('change', () => form.submit());
        });
    }

    // Vlastní datepicker rozsahu: kalendářový popover v designu AllStatu (výběr klikem začátek→konec,
    // navigace měsíců, dark-mode). Nahrazuje nativní pole. Po dokončení rozsahu aplikuje filtr sám.
    function bindDateRange() {
        const wrap = document.querySelector('[data-date-range]');
        if (!wrap) { return; }
        const trigger = wrap.querySelector('[data-date-range-trigger]');
        const pop = wrap.querySelector('[data-date-range-pop]');
        const labelEl = wrap.querySelector('[data-date-range-label]');
        const startInput = wrap.querySelector('[data-date-start]');
        const endInput = wrap.querySelector('[data-date-end]');
        if (!trigger || !pop || !labelEl || !startInput || !endInput) { return; }

        const MONTHS = ['leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];
        const WD = ['Po', 'Út', 'St', 'Čt', 'Pá', 'So', 'Ne'];
        const pad = (n) => String(n).padStart(2, '0');
        const fmt = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
        const parse = (s) => { const p = String(s).split('-').map(Number); return new Date(p[0], (p[1] || 1) - 1, p[2] || 1); };
        const today = (() => { const n = new Date(); return new Date(n.getFullYear(), n.getMonth(), n.getDate()); })();
        const same = (a, b) => !!a && !!b && a.getTime() === b.getTime();
        const czRange = (a, b) => {
            const dm = (x) => x.getDate() + '. ' + (x.getMonth() + 1) + '.';
            if (!b || same(a, b)) { return dm(a) + ' ' + a.getFullYear(); }
            if (a.getFullYear() === b.getFullYear()) { return dm(a) + ' – ' + dm(b) + ' ' + b.getFullYear(); }
            return dm(a) + ' ' + a.getFullYear() + ' – ' + dm(b) + ' ' + b.getFullYear();
        };

        let start = parse(startInput.value);
        let end = parse(endInput.value);
        let picking = false;
        let view = new Date(start.getFullYear(), start.getMonth(), 1);

        const updateLabel = () => { labelEl.textContent = czRange(start, end); };

        function render() {
            const y = view.getFullYear(), m = view.getMonth();
            const lo = (start && end) ? (start < end ? start : end) : start;
            const hi = (start && end) ? (start < end ? end : start) : (picking ? null : start);
            let cells = '';
            const lead = (new Date(y, m, 1).getDay() + 6) % 7;
            for (let i = 0; i < lead; i++) { cells += '<span class="dp-empty"></span>'; }
            const dim = new Date(y, m + 1, 0).getDate();
            for (let d = 1; d <= dim; d++) {
                const date = new Date(y, m, d);
                const future = date > today;
                const isEdge = same(date, start) || same(date, end);
                const inRange = lo && hi && date >= lo && date <= hi;
                let cls = 'dp-day';
                if (future) { cls += ' dp-disabled'; }
                if (inRange && !isEdge) { cls += ' dp-in'; }
                if (inRange && same(date, lo)) { cls += ' dp-in dp-edge-start'; }
                if (inRange && same(date, hi)) { cls += ' dp-in dp-edge-end'; }
                if (isEdge) { cls += ' dp-sel'; }
                if (same(date, today)) { cls += ' dp-today'; }
                cells += `<button type="button" class="${cls}" data-day="${fmt(date)}"${future ? ' disabled' : ''}>${d}</button>`;
            }
            pop.innerHTML = `
                <div class="dp-head">
                    <button type="button" class="dp-nav" data-prev aria-label="Předchozí měsíc">‹</button>
                    <span class="dp-title">${MONTHS[m]} ${y}</span>
                    <button type="button" class="dp-nav" data-next aria-label="Další měsíc">›</button>
                </div>
                <div class="dp-grid dp-weekdays">${WD.map((w) => `<span>${w}</span>`).join('')}</div>
                <div class="dp-grid dp-days">${cells}</div>
                <div class="dp-foot">
                    <span class="dp-hint">${picking ? 'Vyber konec období' : 'Klikni na začátek a konec'}</span>
                    <button type="button" class="dp-clear" data-jump>Dnešní měsíc</button>
                </div>`;
            pop.querySelector('[data-prev]').addEventListener('click', () => { view = new Date(y, m - 1, 1); render(); });
            pop.querySelector('[data-next]').addEventListener('click', () => { view = new Date(y, m + 1, 1); render(); });
            pop.querySelector('[data-jump]').addEventListener('click', () => { view = new Date(today.getFullYear(), today.getMonth(), 1); render(); });
            pop.querySelectorAll('[data-day]').forEach((btn) => {
                btn.addEventListener('click', () => pick(parse(btn.dataset.day)));
            });
        }

        function pick(day) {
            if (day > today) { return; }
            if (!picking) {
                start = day; end = null; picking = true;
                view = new Date(day.getFullYear(), day.getMonth(), 1);
                render();
                return;
            }
            let s = start, e = day;
            if (e < s) { const t = s; s = e; e = t; }
            start = s; end = e; picking = false;
            startInput.value = fmt(s); endInput.value = fmt(e);
            updateLabel();
            close();
            applyFilters();
        }

        function open() {
            start = parse(startInput.value); end = parse(endInput.value); picking = false;
            view = new Date(start.getFullYear(), start.getMonth(), 1);
            wrap.setAttribute('data-open', '');
            trigger.setAttribute('aria-expanded', 'true');
            pop.hidden = false;
            render();
        }
        function close() {
            wrap.removeAttribute('data-open');
            trigger.setAttribute('aria-expanded', 'false');
            pop.hidden = true;
        }

        // Kliky uvnitř popoveru nesmí probublat na document „klik-mimo" handler. Nutné hlavně proto, že
        // render() přepíše innerHTML → kliknutý den se odpojí z DOM a wrap.contains(e.target) by pak
        // vyhodnotil klik jako „ven" a popover zavřel dřív, než uživatel vybere konec.
        pop.addEventListener('click', (e) => { e.stopPropagation(); });
        trigger.addEventListener('click', (e) => { e.stopPropagation(); if (pop.hidden) { open(); } else { close(); } });
        document.addEventListener('click', (e) => { if (!wrap.contains(e.target)) { close(); } });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !pop.hidden) { close(); } });

        // Klik na rychlé období (chip) v přehledu (AJAX) topbar nereloaduje → sync labelu.
        document.querySelectorAll('.quick-ranges .chip[data-range-start]').forEach((chip) => {
            chip.addEventListener('click', () => {
                start = parse(chip.dataset.rangeStart); end = parse(chip.dataset.rangeEnd); picking = false;
                updateLabel();
            });
        });

        updateLabel();
    }

    function init() {
        balanceKpiGrids();
        bindFilters();
        bindQuickRanges();
        bindAutoApply();
        bindDateRange();
        bindDatePickers();
        bindSourceSwitch();
        bindNav();
        bindPagesToggle();
        bindTheme();
        renderCharts(window.ALLSTAT_DATA);
        window.lucide?.createIcons();
    }

    return { init, refreshDashboard };
})();

document.addEventListener('DOMContentLoaded', AllStat.init);
