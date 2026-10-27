(() => {
    function refreshIcons() {
        window.lucide?.createIcons();
    }

    // Štítek stavu (.status + tón), stejný jako allstat_status_pill() v admin/_layout.php.
    const TONES = { ok: 'tone-up', error: 'tone-down', warning: 'tone-warn', pending: 'tone-accent' };
    const esc = (value) => String(value).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const statusPill = (state, label) => `<span class="status ${TONES[state] || 'tone-flat'}"><span class="dot"></span>${esc(label)}</span>`;

    // Hláška výsledku akce (.callout s tónem podle stavu: pending | ok | error).
    function showResult(box, state, text) {
        box.hidden = false;
        box.className = 'callout ' + (TONES[state] || '');
        box.textContent = text;
    }

    // Výpis synchronizace po napojeních do .callout: řádek = dlaždice služby (klon z tabulky) + název + hláška + stav.
    function renderSyncList(box, items, headHtml) {
        const itemHtml = (item) => {
            const badge = item.state === 'pending'
                ? statusPill('pending', window.allstatT('Běží…'))
                : (item.state === 'ok' ? statusPill('ok', 'OK') : statusPill('error', window.allstatT('Chyba')));
            return `
                <div class="row">
                    ${item.icon}
                    <div class="name-stack"><span class="name-main">${esc(item.label)}</span>${item.msg ? `<span class="name-sub">${esc(item.msg)}</span>` : ''}</div>
                    ${badge}
                </div>
            `;
        };
        box.hidden = false;
        box.className = 'callout';
        box.innerHTML = `<div class="name-stack">${headHtml ? `<div>${headHtml}</div>` : ''}<div class="rows rows-compact">${items.map(itemHtml).join('')}</div></div>`;
    }

    async function postSync(body) {
        const res = await fetch('connection-sync.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
        });
        if (!(res.headers.get('content-type') || '').includes('application/json')) {
            throw new Error(window.allstatT('relace vypršela, obnov stránku (F5) a přihlas se znovu'));
        }
        return res.json();
    }

    // "Synchronizovat vše" na sources.php: projede všechna napojení SEKVENČNĚ (šetrné k API limitům)
    // stejným incremental syncem (7 dní) jako tlačítko na detailu napojení, s průběžným výpisem výsledků.
    function bindSyncAll() {
        const btn = document.querySelector('[data-sync-all]');
        const box = document.querySelector('[data-sync-all-result]');

        if (!btn || !box) {
            return;
        }

        btn.addEventListener('click', async () => {
            const rows = [...document.querySelectorAll('tr[data-conn-id]')].filter((row) => !row.hidden);
            if (!rows.length) {
                return;
            }
            btn.disabled = true;
            const originalHtml = btn.innerHTML;
            const csrf = btn.getAttribute('data-csrf');
            const items = [];
            let okCount = 0;
            let failCount = 0;

            for (let i = 0; i < rows.length; i++) {
                const id = rows[i].getAttribute('data-conn-id');
                const label = rows[i].getAttribute('data-conn-label') || ('#' + id);
                const icon = rows[i].querySelector('.tile')?.outerHTML || '';
                btn.textContent = window.allstatT('Synchronizuji {i}/{n}…', { i: i + 1, n: rows.length });
                items.push({ icon, label, msg: '', state: 'pending' });
                renderSyncList(box, items, esc(window.allstatT('Synchronizuji {i}/{n}…', { i: i + 1, n: rows.length })));
                const item = items[items.length - 1];
                try {
                    const data = await postSync('id=' + encodeURIComponent(id) + '&mode=incremental&csrf=' + encodeURIComponent(csrf));
                    if (data.ok) { okCount++; item.state = 'ok'; item.msg = data.message || 'OK'; }
                    else { failCount++; item.state = 'error'; item.msg = data.message || window.allstatT('chyba bez hlášky'); }
                } catch (err) {
                    failCount++;
                    item.state = 'error';
                    item.msg = err.message;
                }
                renderSyncList(box, items, esc(window.allstatT('Synchronizuji {i}/{n}…', { i: Math.min(i + 2, rows.length), n: rows.length })));
            }

            // České skloňování: 1 chyba, 2-4 chyby, 5+ (a 0) chyb.
            const chybaWord = (n) => (n === 1 ? window.allstatT('chyba') : (n >= 2 && n <= 4 ? window.allstatT('chyby') : window.allstatT('chyb')));
            const headBadge = failCount
                ? statusPill('error', failCount === 1 ? window.allstatT('Chyba') : window.allstatT('Chyby'))
                : statusPill('ok', window.allstatT('Hotovo'));
            renderSyncList(box, items, `${headBadge} <strong>${okCount} OK</strong>${failCount ? `, ${failCount} ${chybaWord(failCount)}` : ''}`);
            btn.disabled = false;
            btn.innerHTML = originalHtml;
            refreshIcons();
        });
    }

    // Zdroje dat: tlačítko synchronizace v řádku (incremental 7 dní), výsledek do stejného výpisu jako „Synchronizovat vše".
    function bindRowSync() {
        const box = document.querySelector('[data-sync-all-result]');

        document.querySelectorAll('[data-row-sync]').forEach((btn) => {
            btn.addEventListener('click', async () => {
                const row = btn.closest('tr');
                const csrf = btn.closest('table')?.getAttribute('data-csrf') || '';
                const item = { icon: row.querySelector('.tile')?.outerHTML || '', label: row.getAttribute('data-conn-label') || '', msg: '', state: 'pending' };
                btn.disabled = true;
                btn.querySelector('svg')?.classList.add('spin');
                try {
                    const data = await postSync('id=' + encodeURIComponent(btn.getAttribute('data-row-sync')) + '&mode=incremental&csrf=' + encodeURIComponent(csrf));
                    item.state = data.ok ? 'ok' : 'error';
                    item.msg = data.message || (data.ok ? 'OK' : window.allstatT('chyba bez hlášky'));
                } catch (err) {
                    item.state = 'error';
                    item.msg = err.message;
                }
                if (box) { renderSyncList(box, [item], ''); }
                btn.disabled = false;
                btn.querySelector('svg')?.classList.remove('spin');
            });
        });
    }

    // Filtr tabulky: hledání ([data-table-filter="id tabulky nebo mřížky"], položky s data-filter-text) a stav
    // (.seg [data-status-filter-for] s tlačítky data-status-filter, řádky s data-status). Skupinové řádky
    // (tr.table-group[data-group]) se schovají, když v nich nic nezbylo; prázdný stav [data-filter-empty].
    function bindTableFilters() {
        const tables = new Map();
        const stateOf = (id) => {
            if (!tables.has(id)) { tables.set(id, { q: '', status: 'all' }); }
            return tables.get(id);
        };
        const apply = (id) => {
            const table = document.getElementById(id);
            if (!table) { return; }
            const state = stateOf(id);
            const rows = [...table.querySelectorAll('[data-filter-text]')];
            let visible = 0;
            rows.forEach((row) => {
                const okText = state.q === '' || row.getAttribute('data-filter-text').includes(state.q);
                const okStatus = state.status === 'all' || row.getAttribute('data-status') === state.status;
                row.hidden = !(okText && okStatus);
                if (!row.hidden) { visible += 1; }
            });
            table.querySelectorAll('tr.table-group[data-group]').forEach((group) => {
                const shown = rows.filter((row) => row.dataset.group === group.dataset.group && !row.hidden).length;
                group.hidden = shown === 0;
                const count = group.querySelector('[data-group-count]');
                if (count) { count.textContent = String(shown); }
            });
            const empty = table.closest('section, .card')?.querySelector('[data-filter-empty]');
            if (empty) {
                empty.hidden = visible > 0;
                table.closest('.table-wrap')?.toggleAttribute('hidden', visible === 0);
            }
        };

        document.querySelectorAll('[data-table-filter]').forEach((input) => {
            const id = input.getAttribute('data-table-filter');
            input.addEventListener('input', () => {
                stateOf(id).q = input.value.trim().toLowerCase();
                apply(id);
            });
        });

        document.querySelectorAll('[data-status-filter-for]').forEach((seg) => {
            const id = seg.getAttribute('data-status-filter-for');
            seg.addEventListener('click', (event) => {
                const btn = event.target.closest('[data-status-filter]');
                if (!btn) { return; }
                seg.querySelectorAll('[data-status-filter]').forEach((b) => b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'));
                stateOf(id).status = btn.getAttribute('data-status-filter');
                apply(id);
            });
        });
    }

    // Nabídka akcí v řádku tabulky (.cell-actions): když by přetekla dolů z nejbližšího ořezávajícího předka nebo okna
    // a nahoře je pro ni místo, otevře se nahoru. Když se nevejde ani tam (krátká tabulka v posuvném obalu na úzkém
    // displeji), vyskočí z obalu jako position: fixed u tlačítka a při posunu stránky se zavře.
    // Otevírá ji shell.js (tento posluchač běží po něm).
    function bindMenuFlip() {
        let floating = null;
        const closeFloating = () => {
            if (!floating) { return; }
            floating.menu.hidden = true;
            floating.toggle.setAttribute('aria-expanded', 'false');
            floating = null;
        };
        window.addEventListener('scroll', closeFloating, true);
        window.addEventListener('resize', closeFloating);
        document.addEventListener('click', (event) => {
            const toggle = event.target.closest('.cell-actions [data-menu-toggle]');
            const menu = toggle && document.getElementById(toggle.dataset.menuToggle);
            if (!menu || menu.hidden) { return; }
            menu.classList.remove('menu-up');
            menu.style.position = menu.style.top = menu.style.right = menu.style.bottom = '';
            floating = null;
            let top = 0;
            let bottom = window.innerHeight;
            for (let el = toggle.parentElement; el && el !== document.body; el = el.parentElement) {
                if (getComputedStyle(el).overflowY !== 'visible') {
                    const rect = el.getBoundingClientRect();
                    top = Math.max(top, rect.top);
                    bottom = Math.min(bottom, rect.bottom);
                    break;
                }
            }
            const menuRect = menu.getBoundingClientRect();
            const toggleRect = toggle.getBoundingClientRect();
            if (menuRect.bottom <= bottom) { return; }
            if (toggleRect.top - top >= menuRect.height + 6) {
                menu.classList.add('menu-up');
                return;
            }
            const up = toggleRect.bottom + menuRect.height + 6 > window.innerHeight && toggleRect.top >= menuRect.height + 6;
            menu.style.position = 'fixed';
            menu.style.right = (document.documentElement.clientWidth - toggleRect.right) + 'px';
            if (up) {
                menu.style.top = 'auto';
                menu.style.bottom = (window.innerHeight - toggleRect.top + 4) + 'px';
            } else {
                menu.style.top = (toggleRect.bottom + 4) + 'px';
                menu.style.bottom = 'auto';
            }
            floating = { menu, toggle };
        });
    }

    function bindConnectionActions() {
        const resultBox = document.querySelector('[data-connection-result]');
        if (!resultBox) return;

        const show = (cls, text) => {
            showResult(resultBox, cls, text);
            resultBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        };

        // Single POST returning JSON; throws on auth-expiry / non-JSON with a clear message.
        const postJson = async (endpoint, body) => {
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body,
                redirect: 'follow',
            });
            const ct = res.headers.get('content-type') || '';
            if (!ct.includes('application/json')) {
                throw new Error(window.allstatT('Relace pravděpodobně vypršela. Obnov stránku (F5), přihlas se znovu a akci zopakuj.'));
            }
            return res.json();
        };

        const runSimple = async (endpoint, button, body) => {
            button.disabled = true;
            const original = button.textContent;
            button.textContent = window.allstatT('Pracuju…');
            show('pending', window.allstatT('Volám API…'));
            try {
                const data = await postJson(endpoint, body);
                show(data.ok ? 'ok' : 'error', data.message || (data.ok ? window.allstatT('Hotovo.') : window.allstatT('Chyba bez hlášky.')));
            } catch (err) {
                show('error', err.message);
            } finally {
                button.disabled = false;
                button.textContent = original;
            }
        };

        // Backfill: loop month-by-month following next_chunk_start until done.
        const runBackfill = async (button, id, csrf) => {
            button.disabled = true;
            const original = button.textContent;
            button.textContent = window.allstatT('Stahuji historii…');
            let chunkStart = '';
            let totalRows = 0, totalPosts = 0, totalLanding = 0, iterations = 0;
            try {
                while (true) {
                    iterations++;
                    const body = 'id=' + encodeURIComponent(id) + '&mode=backfill&csrf=' + encodeURIComponent(csrf) +
                        (chunkStart ? '&chunk_start=' + encodeURIComponent(chunkStart) : '');
                    show('pending', window.allstatT('Stahuji {from}… ({n} dávek hotovo)', { from: chunkStart || window.allstatT('začátek'), n: iterations }));
                    const data = await postJson('connection-sync.php', body);
                    if (!data.ok) { show('error', data.message || window.allstatT('Backfill selhal.')); break; }
                    if (data.stats) {
                        // Different engines report different stat fields: GA4 = metrics_daily_rows/landing_pages_rows,
                        // Meta/IG = rows (metrics) + posts (social posts). Accumulate generically so the summary
                        // isn't "0 dní" for non-GA4 providers that actually wrote data.
                        totalRows += (data.stats.metrics_daily_rows || data.stats.rows || 0);
                        totalPosts += (data.stats.posts || 0);
                        totalLanding += (data.stats.landing_pages_rows || 0);
                    }
                    if (data.done || !data.next_chunk_start) {
                        const parts = [window.allstatT('{n} záznamů metrik', { n: totalRows })];
                        if (totalPosts) { parts.push(window.allstatT('{n} příspěvků', { n: totalPosts })); }
                        if (totalLanding) { parts.push(window.allstatT('{n} landing řádků', { n: totalLanding })); }
                        show('ok', window.allstatT('Hotovo. Staženo {parts} od {from}. Obnov dashboard.', { parts: parts.join(', '), from: data.backfill_start || '' }));
                        break;
                    }
                    chunkStart = data.next_chunk_start;
                }
            } catch (err) {
                show('error', err.message);
            } finally {
                button.disabled = false;
                button.textContent = original;
            }
        };

        document.querySelectorAll('[data-connection-test]').forEach((btn) => {
            btn.addEventListener('click', () => {
                runSimple('connection-test.php', btn, 'id=' + encodeURIComponent(btn.getAttribute('data-connection-test')) + '&csrf=' + encodeURIComponent(btn.getAttribute('data-csrf')));
            });
        });

        document.querySelectorAll('[data-connection-sync]').forEach((btn) => {
            btn.addEventListener('click', () => {
                runSimple('connection-sync.php', btn, 'id=' + encodeURIComponent(btn.getAttribute('data-connection-sync')) + '&mode=incremental&csrf=' + encodeURIComponent(btn.getAttribute('data-csrf')));
            });
        });

        document.querySelectorAll('[data-connection-backfill]').forEach((btn) => {
            btn.addEventListener('click', () => {
                if (!confirm(window.allstatT('Stáhnout celou historii (klouzavých 16 měsíců)? Může to trvat několik minut – nezavírej stránku.'))) return;
                runBackfill(btn, btn.getAttribute('data-connection-backfill'), btn.getAttribute('data-csrf'));
            });
        });
    }

    // Náhrada inline on*= handlerů (odstraněny kvůli přísnému CSP bez 'unsafe-inline'):
    // potvrzovací dialogy, selecty s auto-odesláním, inputy s označením obsahu při kliknutí.
    function bindInlineReplacements() {
        document.querySelectorAll('form[data-confirm]').forEach((form) => {
            form.addEventListener('submit', (event) => {
                if (!window.confirm(form.getAttribute('data-confirm'))) { event.preventDefault(); }
            });
        });

        document.querySelectorAll('[data-autosubmit]').forEach((el) => {
            el.addEventListener('change', () => { if (el.form) { el.form.submit(); } });
        });

        document.querySelectorAll('[data-select-all]').forEach((input) => {
            const selectAll = () => input.select();
            input.addEventListener('click', selectAll);
            input.addEventListener('focus', selectAll);
        });

        // Reporty: dlouhé tabulky ukazují top 10, tlačítko odkryje schované .row-extra řádky.
        document.addEventListener('click', (event) => {
            const btn = event.target.closest('[data-show-more]');
            if (!btn) { return; }
            btn.closest('.card')?.querySelectorAll('tr.row-extra').forEach((row) => row.classList.remove('row-extra'));
            (btn.closest('.card-foot') || btn).remove();
        });
    }

    // Reporty: stránkování vstupních stránek přes AJAX — server vrací jen JSON té jedné tabulky
    // (reports.php?partial=landing_pages, 2 SQL dotazy), takže se nepřekresluje celá stránka
    // a hlavně se neztratí zvolená záložka. Bez JS fungují odkazy dál (fallback #tab-pages).
    function bindLandingPagesAjax() {
        const card = document.querySelector('[data-landing-pages]');

        if (!card) {
            return;
        }

        const form = card.querySelector('form');
        const rowsEl = card.querySelector('[data-lp-rows]');
        const nav = card.querySelector('[data-lp-nav]');
        const prev = card.querySelector('[data-lp-prev]');
        const next = card.querySelector('[data-lp-next]');
        let page = parseInt(card.querySelector('[data-lp-page]')?.textContent || '1', 10) || 1;
        let pageCount = parseInt(card.querySelector('[data-lp-pages]')?.textContent || '1', 10) || 1;
        let loading = false;

        const load = async (nextPage) => {
            if (loading) { return; }
            loading = true;
            card.setAttribute('aria-busy', 'true');
            try {
                const q = new URLSearchParams(new FormData(form));
                q.set('partial', 'landing_pages');
                q.set('page', String(nextPage));
                const response = await fetch('reports.php?' + q.toString(), { headers: { Accept: 'application/json' } });
                const d = await response.json();

                if (!d.ok) { return; }

                page = d.page;
                pageCount = d.pageCount;
                rowsEl.innerHTML = d.rows.length ? d.rows.map((r) => `
                    <tr><td>${esc(r.path)}</td><td>${esc(r.sessions)}</td><td>${esc(r.conversions)}</td></tr>
                `).join('') : '<tr><td colspan="3" class="is-muted">' + window.allstatT('Žádná data pro toto období.') + '</td></tr>';
                card.querySelectorAll('[data-lp-page]').forEach((el) => { el.textContent = String(d.page); });
                card.querySelectorAll('[data-lp-pages]').forEach((el) => { el.textContent = String(d.pageCount); });
                const total = card.querySelector('[data-lp-total]');
                if (total) { total.textContent = String(d.total); }
                if (nav) { nav.hidden = d.pageCount <= 1; }
                prev?.setAttribute('aria-disabled', page <= 1 ? 'true' : 'false');
                next?.setAttribute('aria-disabled', page >= pageCount ? 'true' : 'false');
            } catch (e) { /* při chybě zůstává aktuální stránka */ } finally {
                loading = false;
                card.removeAttribute('aria-busy');
            }
        };

        prev?.addEventListener('click', (event) => { event.preventDefault(); if (page > 1) { load(page - 1); } });
        next?.addEventListener('click', (event) => { event.preventDefault(); if (page < pageCount) { load(page + 1); } });
        card.querySelector('[data-lp-perpage]')?.addEventListener('change', () => { load(1); });
    }

    // Reporty: záložky sekcí — filtrují karty podle data-report-tab, volba se drží v URL hashi
    // (#tab-…), takže přežije reload i sdílení odkazu. „Vše" ukazuje kompletní report.
    function bindReportTabs() {
        const nav = document.querySelector('[data-report-tabs]');

        if (!nav) {
            return;
        }

        const cards = document.querySelectorAll('[data-report-tab]');

        const activate = (key) => {
            nav.querySelectorAll('[data-report-tab-btn]').forEach((btn) => {
                btn.setAttribute('aria-pressed', btn.dataset.reportTabBtn === key ? 'true' : 'false');
            });
            cards.forEach((card) => {
                card.hidden = key !== 'all' && card.dataset.reportTab !== key;
            });
            // Prázdný .report-grid by nechal v layoutu dvojitou mezeru — schovat i obal.
            document.querySelectorAll('.report-grid').forEach((grid) => {
                grid.hidden = ![...grid.children].some((child) => !child.hidden);
            });
        };

        nav.addEventListener('click', (event) => {
            const btn = event.target.closest('[data-report-tab-btn]');

            if (!btn) {
                return;
            }

            activate(btn.dataset.reportTabBtn);
            history.replaceState(null, '', '#tab-' + btn.dataset.reportTabBtn);
        });

        const fromHash = window.location.hash.match(/^#tab-([a-z]+)$/);

        if (fromHash && nav.querySelector(`[data-report-tab-btn="${fromHash[1]}"]`)) {
            activate(fromHash[1]);
        }
    }

    // Uživatelé: přístup k webům. Seznam webů jen u „Jen vybrané weby", hledání, hromadný výběr a počítadlo.
    function bindDomainAccess() {
        const box = document.querySelector('[data-domain-access]');

        if (!box) {
            return;
        }

        const pick = box.querySelector('[data-domain-pick]');
        const items = Array.from(box.querySelectorAll('[data-domain-item]'));
        const checks = items.map((item) => item.querySelector('input[type="checkbox"]'));
        const count = box.querySelector('[data-domain-count]');
        const filter = box.querySelector('[data-domain-filter]');
        const empty = box.querySelector('[data-domain-empty]');
        const adminNote = box.querySelector('[data-domain-admin-note]');
        const role = box.closest('form')?.querySelector('[data-domain-role]');

        const updateCount = () => {
            if (count) {
                count.textContent = window.allstatT('vybráno {i} z {n}', { i: checks.filter((c) => c.checked).length, n: checks.length });
            }
        };
        const updateMode = () => {
            const selected = box.querySelector('input[name="domain_access"]:checked')?.value === 'selected';
            if (pick) { pick.hidden = !selected; }
        };

        box.querySelectorAll('input[name="domain_access"]').forEach((radio) => radio.addEventListener('change', updateMode));
        checks.forEach((check) => check.addEventListener('change', updateCount));
        // Hromadný výběr platí jen pro weby, které právě odpovídají hledání.
        box.querySelectorAll('[data-domain-check]').forEach((btn) => {
            btn.addEventListener('click', () => {
                items.forEach((item, i) => {
                    if (!item.hidden) { checks[i].checked = btn.dataset.domainCheck === 'all'; }
                });
                updateCount();
            });
        });
        filter?.addEventListener('input', () => {
            const q = filter.value.trim().toLowerCase();
            let visible = 0;
            items.forEach((item) => {
                item.hidden = q !== '' && !item.dataset.search.includes(q);
                if (!item.hidden) { visible += 1; }
            });
            if (empty) { empty.hidden = visible > 0; }
        });
        // Enter v hledání nesmí odeslat celý formulář uživatele.
        filter?.addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); } });
        role?.addEventListener('change', () => { if (adminNote) { adminNote.hidden = role.value !== 'admin'; } });

        updateCount();
        updateMode();
    }

    // Trychtýře: editor kroků. Řádky se přidávají a odebírají, pořadí se mění přetažením (HTML5 drag & drop,
    // jen za úchyt, aby šel normálně označovat text v polích) nebo šipkami; před odesláním se pole přečíslují
    // na steps[i][label|event|event_custom]. Bez JS formulář funguje dál: pole jsou indexovaná už na serveru.
    function bindFunnelEditor() {
        const editor = document.querySelector('[data-funnel-editor]');

        if (!editor) {
            return;
        }

        const list = editor.querySelector('[data-funnel-steps]');
        const template = editor.querySelector('template[data-funnel-template]');
        const addButton = editor.querySelector('[data-funnel-add]');
        const counter = editor.querySelector('[data-funnel-count]');
        const form = editor.closest('form');
        const min = Number(editor.dataset.min) || 2;
        const max = Number(editor.dataset.max) || 10;
        const CUSTOM = '__custom__';
        let activity = {};

        try {
            activity = JSON.parse(editor.dataset.activity || '{}') || {};
        } catch (error) {
            activity = {};
        }
        editor.classList.add('is-enhanced');

        const rows = () => Array.from(list.querySelectorAll(':scope > [data-funnel-row]'));
        const rowEvent = (row) => {
            const select = row.querySelector('[data-funnel-event]');

            return (select.value === CUSTOM ? row.querySelector('[data-funnel-custom]').value : select.value).trim();
        };

        // Aktivita eventu za 28 dní z mapy, kterou poslal server (malá písmena => počet).
        function renderActivity(row) {
            const box = row.querySelector('[data-funnel-activity]');
            const event = rowEvent(row);

            box.textContent = '';
            if (!event) {
                return;
            }

            const count = Number(activity[event.toLowerCase()] || 0);
            const badge = document.createElement('span');

            badge.className = `status ${count > 0 ? 'tone-up' : 'tone-warn'}`;
            if (count > 0) {
                badge.textContent = window.allstatT('{count} za 28 dní', { count: count.toLocaleString(window.allstatLocale) });
            } else {
                badge.textContent = window.allstatT('za 28 dní nepřišel');
                badge.title = window.allstatT('Event za posledních 28 dní nepřišel, zkontroluj měření (GTM).');
            }
            box.appendChild(badge);
        }

        // Textové pole pro vlastní název je aktivní jen při volbě „Vlastní název eventu…" (jinak by skrytá
        // neplatná hodnota blokovala odeslání formuláře).
        function syncCustom(row) {
            const isCustom = row.querySelector('[data-funnel-event]').value === CUSTOM;

            row.classList.toggle('is-custom', isCustom);
            row.querySelector('[data-funnel-custom]').disabled = !isCustom;
        }

        function refresh() {
            const all = rows();

            all.forEach((row, index) => {
                row.querySelector('[data-funnel-no]').textContent = String(index + 1);
                row.querySelectorAll('[name^="steps["]').forEach((field) => {
                    field.name = field.name.replace(/^steps\[[^\]]*\]/, `steps[${index}]`);
                });
                row.querySelector('[data-funnel-up]').disabled = index === 0;
                row.querySelector('[data-funnel-down]').disabled = index === all.length - 1;
                row.querySelector('[data-funnel-remove]').disabled = all.length <= min;
            });
            addButton.disabled = all.length >= max;
            if (counter) {
                counter.textContent = window.allstatT('{i} z {n} kroků', { i: all.length, n: max });
            }
        }

        function move(row, direction) {
            const sibling = direction < 0 ? row.previousElementSibling : row.nextElementSibling;

            if (!sibling) {
                return;
            }
            list.insertBefore(direction < 0 ? row : sibling, direction < 0 ? sibling : row);
            refresh();
        }

        list.addEventListener('click', (event) => {
            const button = event.target.closest('button');
            const row = button?.closest('[data-funnel-row]');

            if (!button || !row) {
                return;
            }

            const isUp = button.hasAttribute('data-funnel-up');
            const isDown = button.hasAttribute('data-funnel-down');

            if (isUp || isDown) {
                move(row, isUp ? -1 : 1);
                // Fokus zůstává na tlačítku, které uživatel mačká; na kraji seznamu přeskočí na protější.
                const same = row.querySelector(isUp ? '[data-funnel-up]' : '[data-funnel-down]');
                (same.disabled ? row.querySelector(isUp ? '[data-funnel-down]' : '[data-funnel-up]') : same).focus();
            } else if (button.hasAttribute('data-funnel-remove') && rows().length > min) {
                const next = row.nextElementSibling || row.previousElementSibling;

                row.remove();
                refresh();
                (next ? next.querySelector('[data-funnel-handle]') : addButton).focus();
            }
        });

        list.addEventListener('change', (event) => {
            const row = event.target.closest('[data-funnel-row]');

            if (row && event.target.matches('[data-funnel-event]')) {
                syncCustom(row);
                renderActivity(row);
                if (event.target.value === CUSTOM) {
                    row.querySelector('[data-funnel-custom]').focus();
                }
            }
        });

        list.addEventListener('input', (event) => {
            const row = event.target.closest('[data-funnel-row]');

            if (row && event.target.matches('[data-funnel-custom]')) {
                renderActivity(row);
            }
        });

        // Klávesnice na úchytu: šipka nahoru / dolů posune krok (přístupná varianta přetahování).
        list.addEventListener('keydown', (event) => {
            const handle = event.target.closest('[data-funnel-handle]');

            if (handle && (event.key === 'ArrowUp' || event.key === 'ArrowDown')) {
                event.preventDefault();
                move(handle.closest('[data-funnel-row]'), event.key === 'ArrowUp' ? -1 : 1);
                handle.focus();
            }
        });

        addButton.addEventListener('click', () => {
            if (rows().length >= max) {
                return;
            }
            list.insertAdjacentHTML('beforeend', template.innerHTML.replace(/__INDEX__/g, String(rows().length)));

            const row = rows().pop();

            syncCustom(row);
            refresh();
            row.querySelector('input[type="text"]').focus();
        });

        // Přetahování: řádek je přetažitelný jen po stisku úchytu.
        let dragged = null;
        let dropRow = null;
        let dropAfter = false;
        const clearMarks = () => rows().forEach((row) => row.classList.remove('is-drop-before', 'is-drop-after'));
        const finish = () => {
            if (dragged) {
                dragged.classList.remove('is-dragging');
                dragged.draggable = false;
            }
            dragged = null;
            dropRow = null;
            clearMarks();
        };

        list.addEventListener('mousedown', (event) => {
            const handle = event.target.closest('[data-funnel-handle]');

            if (handle) {
                handle.closest('[data-funnel-row]').draggable = true;
            }
        });
        document.addEventListener('mouseup', () => {
            if (!dragged) {
                rows().forEach((row) => { row.draggable = false; });
            }
        });

        list.addEventListener('dragstart', (event) => {
            const row = event.target.closest?.('[data-funnel-row]');

            if (!row || !row.draggable) {
                return;
            }
            dragged = row;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', 'funnel-step');
            window.requestAnimationFrame(() => row.classList.add('is-dragging'));
        });

        list.addEventListener('dragover', (event) => {
            if (!dragged) {
                return;
            }
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';

            const row = event.target.closest('[data-funnel-row]');

            clearMarks();
            dropRow = null;
            if (!row || row === dragged) {
                return;
            }

            const box = row.getBoundingClientRect();

            dropAfter = event.clientY > box.top + box.height / 2;
            dropRow = row;
            row.classList.add(dropAfter ? 'is-drop-after' : 'is-drop-before');
        });

        list.addEventListener('dragleave', (event) => {
            if (!list.contains(event.relatedTarget)) {
                clearMarks();
                dropRow = null;
            }
        });

        list.addEventListener('drop', (event) => {
            if (!dragged) {
                return;
            }
            event.preventDefault();
            if (dropRow) {
                list.insertBefore(dragged, dropAfter ? dropRow.nextElementSibling : dropRow);
                refresh();
            }
            finish();
        });

        list.addEventListener('dragend', finish);

        // Před odesláním pole znovu přečíslovat podle pořadí v DOM (pojistka, kdyby se názvy rozešly).
        form?.addEventListener('submit', refresh);

        rows().forEach(syncCustom);
        refresh();
    }

    // Import webů z Google účtu: výběr všech řádků (hledání dělá bindTableFilters) a stažení historie všech nových napojení
    // (napojení po napojení, každé po měsících přes connection-sync.php jako tlačítko „Stáhnout historii“).
    function bindGoogleImport() {
        document.querySelectorAll('[data-check-all]').forEach((master) => {
            const form = master.closest('form');
            const boxes = () => Array.from(form ? form.querySelectorAll('input[type="checkbox"][name="' + master.getAttribute('data-check-all') + '"]') : [])
                .filter((box) => !box.disabled && !box.closest('tr')?.hidden);
            master.addEventListener('change', () => boxes().forEach((box) => { box.checked = master.checked; }));
        });

        const button = document.querySelector('[data-bulk-backfill]');
        const status = document.querySelector('[data-bulk-backfill-status]');
        if (!button || !status) return;

        const show = (cls, text) => showResult(status, cls, text);
        const post = async (body) => {
            const res = await fetch('connection-sync.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
            if (!(res.headers.get('content-type') || '').includes('application/json')) {
                throw new Error(window.allstatT('Relace pravděpodobně vypršela. Obnov stránku (F5), přihlas se znovu a akci zopakuj.'));
            }
            return res.json();
        };

        button.addEventListener('click', async () => {
            let ids = [];
            try { ids = JSON.parse(button.getAttribute('data-bulk-backfill') || '[]'); } catch (e) { ids = []; }
            const csrf = button.getAttribute('data-csrf') || '';
            const failed = [];
            button.disabled = true;
            try {
                for (let i = 0; i < ids.length; i++) {
                    let chunkStart = '';
                    for (let guard = 0; guard < 40; guard++) {
                        show('pending', window.allstatT('Napojení {i} z {n}: stahuji {from}…', { i: i + 1, n: ids.length, from: chunkStart || window.allstatT('začátek historie') }));
                        const data = await post('id=' + encodeURIComponent(ids[i]) + '&mode=backfill&csrf=' + encodeURIComponent(csrf)
                            + (chunkStart ? '&chunk_start=' + encodeURIComponent(chunkStart) : ''));
                        if (!data.ok) { failed.push((i + 1) + ': ' + (data.message || window.allstatT('chyba bez hlášky'))); break; }
                        if (data.done || !data.next_chunk_start) break;
                        chunkStart = data.next_chunk_start;
                    }
                }
                show(failed.length ? 'error' : 'ok', failed.length
                    ? window.allstatT('Hotovo s chybami u {i} z {n} napojení.', { i: failed.length, n: ids.length }) + ' ' + failed.join(' | ')
                    : window.allstatT('Hotovo. Historie je stažená u všech {n} napojení.', { n: ids.length }));
            } catch (err) {
                show('error', err.message);
            } finally {
                button.disabled = false;
            }
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        bindSyncAll();
        bindRowSync();
        bindTableFilters();
        bindMenuFlip();
        bindConnectionActions();
        bindInlineReplacements();
        bindReportTabs();
        bindLandingPagesAjax();
        bindDomainAccess();
        bindFunnelEditor();
        bindGoogleImport();
        refreshIcons();
    });
})();
