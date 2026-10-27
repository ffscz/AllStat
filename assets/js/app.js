const AllStat = (() => {
    const charts = new Map();
    const statusLabels = { ok: 'OK', warning: window.allstatT('Varování'), error: window.allstatT('Chyba') };
    const statusTones = { ok: 'tone-up', warning: 'tone-warn', error: 'tone-down' };
    const formatter = new Intl.NumberFormat(window.allstatLocale);
    const decimalFormatter = new Intl.NumberFormat(window.allstatLocale, { maximumFractionDigits: 2 });
    // Metriky, u kterých je lepší nižší hodnota (barva změny se otáčí). Stejný seznam má views/overview.php.
    const LOWER_IS_BETTER = ['bounce_rate'];

    function cssVariable(name) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    }

    // Barvy grafů z tokenů aktuálního motivu (tokens.css); čte se při každém kreslení, takže po
    // allstat:themechange stačí grafy překreslit.
    function chartTheme() {
        return {
            line: cssVariable('--bar'),
            area: cssVariable('--area'),
            grid: cssVariable('--grid'),
            muted: cssVariable('--muted'),
            surface: cssVariable('--surface'),
            tipBg: cssVariable('--tip-bg'),
            tipFg: cssVariable('--tip-fg'),
            tipMuted: cssVariable('--tip-muted'),
            font: cssVariable('--font')
        };
    }

    // Pohledy, které se překreslují serverově (plný reload, bez AJAX obnovy přehledu): zdroj (provider), Růst kanálů, Trychtýř.
    function isServerView() {
        return Boolean(window.ALLSTAT_PROVIDER) || window.ALLSTAT_VIEW === 'growth' || window.ALLSTAT_VIEW === 'funnel';
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
        return '<i data-lucide="refresh-cw" class="icon-sm"></i>';
    }

    function destroyChart(key) {
        if (charts.has(key)) {
            charts.get(key).destroy();
            charts.delete(key);
        }
    }

    // Popisek období: celý měsíc „září 2026“, jinak „1. 9. – 15. 9. 2026“ (stejně jako allstat_ui_period_label v views/_ui.php).
    const MONTH_NAMES = ['leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];

    function periodLabel(startIso, endIso) {
        const parse = (iso) => { const p = String(iso).split('-').map(Number); return new Date(p[0], p[1] - 1, p[2]); };
        const s = parse(startIso);
        const e = parse(endIso);
        const en = (window.allstatLocale || '').startsWith('en');
        if (s.getDate() === 1 && e.getTime() === new Date(s.getFullYear(), s.getMonth() + 1, 0).getTime()) {
            return window.allstatT(MONTH_NAMES[s.getMonth()]) + ' ' + s.getFullYear();
        }
        const dm = (d) => (en ? d.getDate() + ' ' + d.toLocaleString('en-GB', { month: 'short' }) : d.getDate() + '. ' + (d.getMonth() + 1) + '.');
        if (startIso === endIso) {
            return dm(s) + ' ' + s.getFullYear();
        }
        return (s.getFullYear() === e.getFullYear() ? dm(s) : dm(s) + ' ' + s.getFullYear()) + ' – ' + dm(e) + ' ' + e.getFullYear();
    }

    function capitalize(text) {
        return text.charAt(0).toUpperCase() + text.slice(1);
    }

    function deltaClass(change, higherIsBetter = true) {
        if (change === null || change === undefined) {
            return 'is-muted';
        }
        if (Math.abs(change) < 0.05) {
            return 'is-flat';
        }
        return (change > 0) === higherIsBetter ? 'is-up' : 'is-down';
    }

    // Čárový graf jedné řady s plochou pod křivkou (záložky metrik) nebo bez os (malý graf sledujících).
    function renderLine(key, canvas, labels, series, label, small = false) {
        if (!canvas || !window.Chart) {
            return;
        }

        const theme = chartTheme();
        destroyChart(key);
        charts.set(key, new Chart(canvas, {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label,
                    data: series,
                    borderColor: theme.line,
                    backgroundColor: theme.area,
                    fill: true,
                    tension: 0.2,
                    borderWidth: 2,
                    spanGaps: true,
                    pointRadius: 0,
                    pointHoverRadius: small ? 0 : 4,
                    pointHoverBackgroundColor: theme.line,
                    pointHoverBorderColor: theme.surface,
                    pointHoverBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        enabled: !small,
                        backgroundColor: theme.tipBg,
                        titleColor: theme.tipMuted,
                        bodyColor: theme.tipFg,
                        titleFont: { family: theme.font, weight: 500 },
                        bodyFont: { family: theme.font, weight: 600 },
                        padding: 10,
                        cornerRadius: 8,
                        displayColors: false,
                        callbacks: { label: (ctx) => `${label}: ${decimalFormatter.format(ctx.parsed.y)}` }
                    }
                },
                scales: small ? { x: { display: false }, y: { display: false } } : {
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: { color: theme.muted, font: { family: theme.font, size: 11 }, maxRotation: 0, autoSkipPadding: 24 }
                    },
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        grid: { color: theme.grid },
                        ticks: { color: theme.muted, font: { family: theme.font, size: 11 }, maxTicksLimit: 5, callback: (v) => decimalFormatter.format(v) }
                    }
                }
            }
        }));
    }

    // Záložky metrik nad grafem (přehled GA4 i detail zdroje): vybraná záložka určuje řadu v grafu.
    function renderMetricChart() {
        const wrap = document.querySelector('[data-metric-chart]');

        if (!wrap) {
            return;
        }

        const tab = wrap.querySelector('[data-metric-tab][aria-selected="true"]') || wrap.querySelector('[data-metric-tab]');
        const canvas = wrap.querySelector('canvas');

        if (!tab || !canvas) {
            return;
        }

        const key = tab.dataset.metricTab;
        const label = tab.querySelector('[data-metric-label]')?.textContent.trim() || key;
        let labels = [];
        let series = [];
        let granularity = wrap.dataset.granularity || 'day';

        if (wrap.dataset.metricChart === 'overview') {
            const visits = (window.ALLSTAT_DATA && window.ALLSTAT_DATA.charts && window.ALLSTAT_DATA.charts.visits) || {};
            labels = visits.labels || [];
            series = visits[key] || [];
            granularity = window.ALLSTAT_DATA.granularity || granularity;
        } else {
            const provider = window.ALLSTAT_PROVIDER || {};
            const metric = (provider.metrics || []).find((m) => m.key === key);
            labels = provider.labels || [];
            series = metric ? metric.series || [] : [];
        }

        const titles = {
            day: window.allstatT('{metric} po dnech', { metric: label }),
            week: window.allstatT('{metric} po týdnech', { metric: label }),
            month: window.allstatT('{metric} po měsících', { metric: label })
        };
        const title = wrap.querySelector('[data-chart-title]');
        if (title) {
            title.textContent = titles[granularity] || titles.day;
        }
        canvas.setAttribute('role', 'img');
        canvas.setAttribute('aria-label', title ? title.textContent : label);
        renderLine('metric', canvas, labels, series, label);
    }

    function bindMetricTabs() {
        const wrap = document.querySelector('[data-metric-chart]');

        if (!wrap) {
            return;
        }

        const tabs = [...wrap.querySelectorAll('[data-metric-tab]')];
        const select = (tab, focus) => {
            tabs.forEach((t) => {
                t.setAttribute('aria-selected', t === tab ? 'true' : 'false');
                t.tabIndex = t === tab ? 0 : -1;
            });
            if (focus) {
                tab.focus();
            }
            renderMetricChart();
        };

        tabs.forEach((tab, index) => {
            tab.addEventListener('click', () => select(tab, false));
            tab.addEventListener('keydown', (event) => {
                const step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[event.key];
                if (step) {
                    event.preventDefault();
                    select(tabs[(index + step + tabs.length) % tabs.length], true);
                } else if (event.key === 'Home' || event.key === 'End') {
                    event.preventDefault();
                    select(tabs[event.key === 'Home' ? 0 : tabs.length - 1], true);
                }
            });
        });
    }

    // Malý graf vývoje sledujících v detailu sociální sítě. Dny bez uloženého stavu (0) se přeskakují.
    function renderFollowersSpark() {
        const canvas = document.querySelector('[data-followers-spark]');
        const provider = window.ALLSTAT_PROVIDER;

        if (!canvas || !provider) {
            return;
        }

        const metric = (provider.metrics || []).find((m) => m.key === canvas.dataset.followersSpark);
        if (!metric) {
            return;
        }

        renderLine('followers', canvas, provider.labels || [], (metric.series || []).map((v) => (Number(v) > 0 ? v : null)), metric.label, true);
    }

    // Měřák a složený pruh: stejný markup jako allstat_ui_meter() / allstat_ui_stack_body() ve views/_ui.php.
    function meterHtml(row) {
        const width = Math.max(0, Math.min(100, Number(row.width) || 0));
        return `<div class="meter"><div class="meter-top">`
            + `<span class="meter-name ellipsis" title="${escapeHtml(row.title || row.label)}">${escapeHtml(row.label)}${row.extraHtml || ''}</span>`
            + `<span class="row-end"><span class="row-value num">${escapeHtml(row.value)}</span>`
            + (row.share ? `<span class="row-share num">${escapeHtml(row.share)}</span>` : '') + `</span></div>`
            + `<div class="track"><div class="track-fill" style="width: ${Math.round(Math.max(width, width > 0 ? 1 : 0) * 10) / 10}%"></div></div>`
            + (row.noteHtml ? `<span class="block-desc">${row.noteHtml}</span>` : '')
            + `</div>`;
    }

    function widths(values) {
        const max = Math.max(1, ...values.map((v) => Number(v) || 0));
        return values.map((v) => (Number(v) || 0) / max * 100);
    }

    function renderMeters(name, rows, emptyText) {
        const holder = document.querySelector(`[data-meters="${name}"]`);
        if (holder) {
            holder.innerHTML = rows.length ? rows.map(meterHtml).join('') : `<p class="card-desc">${escapeHtml(emptyText)}</p>`;
        }
    }

    function renderStack(name, segments, totalLabel, emptyText) {
        const holder = document.querySelector(`[data-stack="${name}"]`);
        if (!holder) {
            return;
        }

        const total = holder.querySelector('[data-stack-total]');
        if (total) {
            total.textContent = totalLabel;
        }
        holder.querySelectorAll('.stack, .rows, .card-desc').forEach((el) => el.remove());
        if (!segments.length) {
            holder.insertAdjacentHTML('beforeend', `<p class="card-desc">${escapeHtml(emptyText)}</p>`);
            return;
        }
        const fill = (i) => `fill-${Math.min(3, i + 1)}`;
        const aria = segments.map((s) => `${s.label} ${s.share}`).join(', ');
        holder.insertAdjacentHTML('beforeend',
            `<div class="stack" role="img" aria-label="${escapeHtml(aria)}">`
            + segments.map((s, i) => `<span class="${fill(i)}" style="width: ${Math.round(Math.max(0, s.pct) * 10) / 10}%"></span>`).join('')
            + `</div><div class="rows rows-compact">`
            + segments.map((s, i) => `<div class="row"><span class="swatch ${fill(i)}"></span><span class="row-label">${escapeHtml(s.label)}</span>`
                + `<span class="row-end"><span class="row-value num">${escapeHtml(s.value)}</span>`
                + (s.share && s.share !== s.value ? `<span class="row-share num">${escapeHtml(s.share)}</span>` : '') + `</span></div>`).join('')
            + `</div>`);
    }

    function renderBreakdowns(data) {
        const c = data.charts || {};
        const tables = data.tables || {};
        const traffic = (c.traffic && c.traffic.sources) || [];
        const trafficW = widths(traffic.map((s) => s.sessions));
        renderMeters('traffic', traffic.map((s, i) => ({ label: s.source, value: s.sessionsLabel, share: s.shareLabel, width: trafficW[i] })), window.allstatT('Zatím bez dat o kanálech, spusť GA4 sync.'));
        const trafficTotal = document.querySelector('[data-traffic-total]');
        if (trafficTotal && c.traffic) {
            trafficTotal.textContent = window.allstatT('{count} celkem', { count: c.traffic.totalLabel });
        }

        const geo = tables.geo || [];
        const geoW = widths(geo.map((g) => g.sessions));
        renderMeters('geo', geo.map((g, i) => ({ label: g.region, title: `${g.region}, ${g.country}`, value: g.sessionsLabel, share: g.shareLabel, width: geoW[i] })), window.allstatT('Zatím bez geo dat, spusť sync.'));

        const refs = tables.referrers || [];
        const refW = widths(refs.map((r) => r.sessions));
        renderMeters('referrers', refs.map((r, i) => ({
            label: r.source, value: r.sessionsLabel, share: r.shareLabel, width: refW[i],
            extraHtml: r.ai ? ` <span class="chip tone-accent">AI · ${escapeHtml(r.ai)}</span>` : ''
        })), window.allstatT('Zatím bez referral dat, spusť GA4 sync.'));

        const devices = (c.devices && c.devices.items) || [];
        renderStack('devices', devices.map((d) => ({ label: d.device, value: d.sessionsLabel, share: d.shareLabel, pct: Number(d.share) || 0 })),
            window.allstatT('{count} návštěv', { count: c.devices ? c.devices.totalLabel : '0' }), window.allstatT('Zatím bez dat o zařízeních, spusť GA4 sync.'));

        const nr = (c.newReturning && c.newReturning.segments) || [];
        const nrVals = nr.map((s, i) => Number(s.value ?? ((c.newReturning.values || [])[i])) || 0);
        const nrTotal = Math.max(1, nrVals.reduce((a, b) => a + b, 0));
        renderStack('newreturning', nr.map((s, i) => ({ label: s.label, value: s.valueLabel, share: s.shareLabel, pct: nrVals[i] / nrTotal * 100 })),
            window.allstatT('{count} uživatelů', { count: c.newReturning ? c.newReturning.totalLabel : '0' }), window.allstatT('Zatím bez dat, spusť GA4 sync.'));

        const aiTop = document.querySelector('[data-ai-top]');
        if (aiTop) {
            const top = (tables.aiSources || [])[0];
            aiTop.querySelector('.row-value').textContent = top ? top.source : '–';
            const count = aiTop.querySelector('.row-delta');
            if (count) {
                count.textContent = top ? window.allstatT('{count} návštěv', { count: formatter.format(top.sessions) }) : '';
            }
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
        const th = (text, width) => `<th scope="col"${width ? ` style="width: ${width}px"` : ''}>${escapeHtml(text)}</th>`;
        const empty = (cols) => `<tr><td colspan="${cols}" class="is-muted">${window.allstatT('Zatím bez dat o stránkách, spusť GA4 sync.')}</td></tr>`;

        if (pagesMode === 'vsechny') {
            if (head) {
                head.innerHTML = th(window.allstatT('Stránka')) + th(window.allstatT('Zobrazení'), 96) + th(window.allstatT('Podíl'), 84);
            }
            if (help) {
                help.textContent = window.allstatT('Nejnavštěvovanější stránky podle počtu zobrazení za zvolené období.');
            }
            body.innerHTML = all.length ? all.map((p) => `
                <tr>
                    <td class="ellipsis" title="${escapeHtml(p.path)}">${escapeHtml(p.path)}</td>
                    <td class="num is-strong">${escapeHtml(p.viewsLabel)}</td>
                    <td class="num">${escapeHtml(p.shareLabel)}</td>
                </tr>
            `).join('') : empty(3);
        } else {
            if (head) {
                head.innerHTML = th(window.allstatT('Stránka')) + th(window.allstatT('Návštěvy'), 84) + th(window.allstatT('Konverze'), 84) + th(window.allstatT('Změna'), 84);
            }
            if (help) {
                help.textContent = window.allstatT('Kde návštěva začala. Změna proti předchozímu období.');
            }
            body.innerHTML = landing.length ? landing.map((p) => `
                <tr>
                    <td class="ellipsis" title="${escapeHtml(p.path)}">${escapeHtml(p.path)}</td>
                    <td class="num is-strong">${formatter.format(p.sessions)}</td>
                    <td class="num">${formatter.format(p.conversions || 0)}</td>
                    <td class="num ${deltaClass(p.change)}">${escapeHtml(p.changeLabel)}</td>
                </tr>
            `).join('') : empty(4);
        }
    }

    function bindPagesToggle() {
        const buttons = document.querySelectorAll('[data-pages-toggle]');

        buttons.forEach((btn) => {
            btn.addEventListener('click', () => {
                pagesMode = btn.dataset.pagesToggle === 'vsechny' ? 'vsechny' : 'vstupni';
                buttons.forEach((b) => b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'));
                renderPagesTable(window.ALLSTAT_DATA);
            });
        });
    }

    function renderCharts() {
        renderMetricChart();
        renderFollowersSpark();
    }

    function updateKpis(data) {
        const prev = data.previousRange;
        const prevText = (prev && prev.start && prev.end) ? window.allstatT('vs. {period}', { period: periodLabel(prev.start, prev.end) }) : '';

        data.kpis.forEach((kpi) => {
            document.querySelectorAll(`[data-kpi-card="${CSS.escape(kpi.key)}"]`).forEach((card) => {
                const value = card.querySelector('[data-kpi-value]');
                const change = card.querySelector('[data-kpi-change]');

                if (value) {
                    value.textContent = kpi.displayValue;
                }

                if (change) {
                    const keep = [...change.classList].filter((c) => !c.startsWith('is-'));
                    change.className = [...keep, deltaClass(kpi.trend === 'none' ? null : kpi.change, !LOWER_IS_BETTER.includes(kpi.key))].join(' ');
                    change.textContent = kpi.trend === 'none' ? window.allstatT('bez srovnání') : kpi.changeLabel;
                }

                const prevEl = card.querySelector('[data-prev-range]');
                if (prevEl) {
                    prevEl.textContent = kpi.trend === 'none' ? '' : prevText;
                }
            });
        });

        const period = document.querySelector('[data-period-label]');
        if (period && data.range) {
            period.textContent = capitalize(periodLabel(data.range.start, data.range.end));
        }
    }

    function updateTables(data) {
        renderPagesTable(data);
        const queryRows = document.querySelector('[data-query-rows]');
        const sourceRows = document.querySelector('[data-source-rows]');
        const eventRows = document.querySelector('[data-event-rows]');
        const decimal1 = new Intl.NumberFormat(window.allstatLocale, { minimumFractionDigits: 1, maximumFractionDigits: 1 });

        if (eventRows) {
            const events = data.tables.events || [];
            eventRows.innerHTML = events.length ? events.map((e) => `
                <tr>
                    <td class="ellipsis" title="${escapeHtml(e.event)}">${escapeHtml(e.event)}${e.isKey ? ' <span class="chip tone-up">key</span>' : ''}</td>
                    <td class="num is-strong">${escapeHtml(e.countLabel)}</td>
                    <td class="num">${escapeHtml(e.keyEventsLabel)}</td>
                </tr>
            `).join('') : '<tr><td colspan="3" class="is-muted">' + window.allstatT('Zatím bez event dat, spusť sync.') + '</td></tr>';
        }

        if (queryRows) {
            const queries = data.tables.queries || [];
            queryRows.innerHTML = queries.length ? queries.map((query) => `
                <tr>
                    <td class="ellipsis" title="${escapeHtml(query.query)}">${escapeHtml(query.query)}</td>
                    <td class="num is-strong">${formatter.format(query.clicks)}</td>
                    <td class="num">${formatter.format(query.impressions)}</td>
                    <td class="num" title="CTR ${escapeHtml(query.ctrLabel)}">${decimal1.format(query.position || 0)}</td>
                </tr>
            `).join('') : '<tr><td colspan="4" class="is-muted">' + window.allstatT('Zatím bez dat ze Search Console.') + '</td></tr>';
        }

        const utmList = document.querySelector('[data-utm-list]');
        if (utmList) {
            // Zrcadlí serverový markup kampaní ve views/overview.php, při změně upravit obojí.
            const utm = data.tables.utm || [];
            const utmRow = (c) => meterHtml({
                label: c.campaignLabel,
                title: `utm_campaign: ${c.campaignLabel}`,
                value: c.sessionsLabel,
                share: c.shareLabel,
                width: Number(c.share) || 0,
                noteHtml: `<span class="chip" title="utm_source / utm_medium">${escapeHtml(c.channelLabel)}</span>`
                    + (c.contentLabel !== '—' ? ` <span class="chip" title="${escapeHtml(window.allstatT('utm_content, A/B varianta'))}">${escapeHtml(window.allstatT('varianta: {value}', { value: c.contentLabel }))}</span>` : '')
                    + ` <span class="chip${c.conversions > 0 ? ' tone-up' : ''}" title="${escapeHtml(window.allstatT('Konverze z této kampaně (a podíl z jejích návštěv)'))}">${escapeHtml(window.allstatT('konverze: {value}', { value: c.conversionsLabel + (c.convRate !== '—' ? ` (${c.convRate})` : '') }))}</span>`
                    + ((c.pages || []).length ? `<br>${escapeHtml(window.allstatT('Kam přišli:'))} ${c.pages.map((p) => `${escapeHtml(p.path)} (${escapeHtml(p.sessionsLabel)})`).join(' · ')}` : '')
            });
            utmList.innerHTML = utm.slice(0, 8).map(utmRow).join('');
            const more = document.querySelector('[data-utm-more]');
            if (more) {
                more.hidden = utm.length <= 8;
                more.querySelector('[data-utm-more-label]').textContent = window.allstatT('a dalších {count}', { count: Math.max(0, utm.length - 8) });
                more.querySelector('[data-utm-rest]').innerHTML = utm.slice(8).map(utmRow).join('');
            }
            const empty = document.querySelector('[data-utm-empty]');
            if (empty) {
                empty.hidden = utm.length > 0;
            }
        }

        if (sourceRows) {
            sourceRows.innerHTML = data.tables.sources.length ? data.tables.sources.map((source) => `
                <tr>
                    <td class="ellipsis">${escapeHtml(source.source)}</td>
                    <td><span class="status ${statusTones[source.status] || 'tone-flat'}"><span class="dot"></span>${escapeHtml(statusLabels[source.status] || source.status)}</span></td>
                    <td class="num">${escapeHtml(source.lastSync)}</td>
                    <td><button class="btn-icon btn-sm" type="button" title="${window.allstatT('Obnovit')}" aria-label="${window.allstatT('Obnovit')}" data-refresh-dashboard>${iconRefresh()}</button></td>
                </tr>
            `).join('') : '<tr><td colspan="4" class="is-muted">' + window.allstatT('Zatím není napojený žádný zdroj dat.') + '</td></tr>';
        }
    }

    function updateMeta(data) {
        const mode = document.querySelector('[data-data-mode]');
        const lastSync = document.querySelector('[data-last-sync]');
        const dot = document.querySelector('.sync-card .status-dot');

        if (mode) {
            mode.textContent = data.meta.databaseMode
                ? (data.meta.sourceCount > 0 ? window.allstatT('Všechny zdroje jsou synchronizované') : window.allstatT('Zdroje zatím nejsou napojené'))
                : window.allstatT('Demo data jsou aktivní');
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
        renderBreakdowns(data);
        renderCharts();
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
        // plain full-reload GET form instead of the overview's AJAX refresh. Růst kanálů a Trychtýř taky (serverové pohledy).
        if (isServerView()) {
            return;
        }

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            // Manuální rozsah = žádné rychlé období není aktivní (rozsah nemusí odpovídat žádnému rychlému výběru).
            clearQuickRanges();
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

    // Rychlá období (.seg v záhlaví, views/_dashboard-head.php): aktivní položka má aria-current.
    function quickRangeLinks() {
        return document.querySelectorAll('[data-quick-ranges] [data-range-start]');
    }

    function clearQuickRanges() {
        quickRangeLinks().forEach((link) => link.removeAttribute('aria-current'));
    }

    function bindQuickRanges() {
        const form = document.getElementById('dashboardFilters');

        // Provider views a Trychtýř se překreslují serverově (jiný payload) → tam rychlá období zůstávají jako plné odkazy.
        if (!form || isServerView()) {
            return;
        }

        const startInput = form.querySelector('input[name="start"]');
        const endInput = form.querySelector('input[name="end"]');

        quickRangeLinks().forEach((link) => {
            link.addEventListener('click', (event) => {
                event.preventDefault();
                if (startInput) { startInput.value = link.dataset.rangeStart; }
                if (endInput) { endInput.value = link.dataset.rangeEnd; }
                quickRangeLinks().forEach((l) => {
                    if (l === link) { l.setAttribute('aria-current', 'true'); } else { l.removeAttribute('aria-current'); }
                });
                refreshDashboard(new URLSearchParams(new FormData(form)));
            });
        });
    }

    function bindSourceSwitch() {
        // Nabídka „Přepnout zdroj" (.dropdown/.menu): otevírání a zavírání řeší assets/js/shell.js
        // ([data-menu-toggle]), tady jen přechod na zvolený zdroj.
        const picker = document.querySelector('[data-source-picker]');

        if (!picker) {
            return;
        }

        picker.querySelectorAll('.menu-item[data-value]').forEach((item) => {
            item.addEventListener('click', () => {
                const q = new URLSearchParams({ domain_id: picker.dataset.domain || '' });
                if (item.dataset.view === 'growth') {
                    // Přehled (Růst kanálů) má vlastní měsíční období, denní rozsah se nepřenáší.
                    q.set('view', 'growth');
                } else {
                    // Z Růstu kanálů picker nemá denní rozsah → prázdné start/end = výchozích 7 dní (u trychtýře 30 dní).
                    if (picker.dataset.start) { q.set('start', picker.dataset.start); }
                    if (picker.dataset.end) { q.set('end', picker.dataset.end); }
                    if (item.dataset.view === 'overview') {
                        q.set('view', 'overview');
                    } else if (item.dataset.view === 'funnel') {
                        // Trychtýř patří webu: picker nese id trychtýře, období se přenáší stejně jako u přehledu.
                        q.set('view', 'funnel');
                        q.set('funnel_id', item.dataset.funnel || '0');
                    } else {
                        q.set('source_id', item.dataset.value || '0');
                    }
                }
                window.location.href = '?' + q.toString();
            });
        });
    }

    // Přepnutí motivu (assets/js/shell.js) → grafy znovu s barvami nového motivu (tokeny čte chartTheme()).
    function bindThemeCharts() {
        document.addEventListener('allstat:themechange', renderCharts);
    }

    // Aplikuje aktuální filtr: přehled (GA4) = AJAX refresh, provider view = serverový plný reload.
    function applyFilters() {
        const form = document.getElementById('dashboardFilters');
        if (!form) { return; }
        if (isServerView()) { form.submit(); return; }
        clearQuickRanges();
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

        const MONTHS = [window.allstatT('leden'), window.allstatT('únor'), window.allstatT('březen'), window.allstatT('duben'), window.allstatT('květen'), window.allstatT('červen'), window.allstatT('červenec'), window.allstatT('srpen'), window.allstatT('září'), window.allstatT('říjen'), window.allstatT('listopad'), window.allstatT('prosinec')];
        const WD = [window.allstatT('Po'), window.allstatT('Út'), window.allstatT('St'), window.allstatT('Čt'), window.allstatT('Pá'), window.allstatT('So'), window.allstatT('Ne')];
        const pad = (n) => String(n).padStart(2, '0');
        const fmt = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
        const parse = (s) => { const p = String(s).split('-').map(Number); return new Date(p[0], (p[1] || 1) - 1, p[2] || 1); };
        const today = (() => { const n = new Date(); return new Date(n.getFullYear(), n.getMonth(), n.getDate()); })();
        const same = (a, b) => !!a && !!b && a.getTime() === b.getTime();
        const czRange = (a, b) => {
            // Angličtina: 16 Oct; čeština a slovenština: 16. 10.
            const dm = (x) => (window.allstatLocale || 'cs-CZ').startsWith('en')
                ? x.getDate() + ' ' + x.toLocaleString('en-GB', { month: 'short' })
                : x.getDate() + '. ' + (x.getMonth() + 1) + '.';
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
                    <button type="button" class="dp-nav" data-prev aria-label="${window.allstatT('Předchozí měsíc')}">‹</button>
                    <span class="dp-title">${MONTHS[m]} ${y}</span>
                    <button type="button" class="dp-nav" data-next aria-label="${window.allstatT('Další měsíc')}">›</button>
                </div>
                <div class="dp-grid dp-weekdays">${WD.map((w) => `<span>${w}</span>`).join('')}</div>
                <div class="dp-grid dp-days">${cells}</div>
                <div class="dp-foot">
                    <span class="dp-hint">${picking ? window.allstatT('Vyber konec období') : window.allstatT('Klikni na začátek a konec')}</span>
                    <button type="button" class="dp-clear" data-jump>${window.allstatT('Dnešní měsíc')}</button>
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

        // Klik na rychlé období v přehledu (AJAX) záhlaví nereloaduje → sync labelu.
        quickRangeLinks().forEach((link) => {
            link.addEventListener('click', () => {
                start = parse(link.dataset.rangeStart); end = parse(link.dataset.rangeEnd); picking = false;
                updateLabel();
            });
        });

        updateLabel();
    }

    function init() {
        bindFilters();
        bindQuickRanges();
        bindAutoApply();
        bindDateRange();
        bindSourceSwitch();
        bindPagesToggle();
        bindMetricTabs();
        bindThemeCharts();
        renderCharts();
        window.lucide?.createIcons();
    }

    return { init, refreshDashboard };
})();

document.addEventListener('DOMContentLoaded', AllStat.init);
