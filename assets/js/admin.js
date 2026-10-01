(() => {
    function refreshIcons() {
        window.lucide?.createIcons();
    }

    function bindTheme() {
        const button = document.getElementById('themeToggle');

        if (!button) {
            return;
        }

        const sync = () => {
            const dark = document.documentElement.dataset.theme === 'dark';
            button.innerHTML = `<i data-lucide="${dark ? 'sun' : 'moon'}"></i>`;
            refreshIcons();
        };

        button.addEventListener('click', () => {
            const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
            document.documentElement.dataset.theme = next;
            localStorage.setItem('allstat-theme', next);
            sync();
        });

        sync();
    }

    function bindSidebar() {
        const shell = document.querySelector('[data-admin-shell]');
        const button = document.querySelector('[data-sidebar-toggle]');

        if (!shell || !button) {
            return;
        }

        const backdrop = document.querySelector('[data-admin-backdrop]');
        const setOpen = (open) => {
            shell.classList.toggle('sidebar-open', open);
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
            document.body.classList.toggle('nav-locked', open); // app.css: overflow hidden při otevřeném draweru
        };

        button.addEventListener('click', () => setOpen(!shell.classList.contains('sidebar-open')));
        backdrop?.addEventListener('click', () => setOpen(false));
        shell.querySelectorAll('.admin-nav a').forEach((a) => a.addEventListener('click', () => setOpen(false)));
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') { setOpen(false); }
        });
    }

    // "Synchronizovat vše" na sources.php: projede všechna napojení SEKVENČNĚ (šetrné k API limitům)
    // stejným incremental syncem (7 dní) jako tlačítko na detailu napojení, s průběžným výpisem výsledků.
    function bindSyncAll() {
        const btn = document.querySelector('[data-sync-all]');
        const box = document.querySelector('[data-sync-all-result]');

        if (!btn || !box) {
            return;
        }

        // Strukturovaný výpis místo prostého textu — řádek = ikona služby (klon z tabulky) + název
        // + badge stavu + hláška; ladí s admin designem (status-badge, source-picker-ic).
        const esc = (value) => String(value).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

        const itemHtml = (item) => {
            const badge = item.state === 'pending'
                ? '<span class="status-badge status-warning">Běží…</span>'
                : (item.state === 'ok' ? '<span class="status-badge status-ok">OK</span>' : '<span class="status-badge status-error">Chyba</span>');
            return `
                <div class="sync-result-item">
                    ${item.icon || '<span class="source-picker-ic"></span>'}
                    <div class="sync-result-text">
                        <strong>${esc(item.label)}</strong>
                        ${item.msg ? `<small>${esc(item.msg)}</small>` : ''}
                    </div>
                    ${badge}
                </div>
            `;
        };

        const render = (items, headHtml) => {
            box.innerHTML = `<div class="sync-result-head">${headHtml}</div><div class="sync-result-list">${items.map(itemHtml).join('')}</div>`;
        };

        btn.addEventListener('click', async () => {
            const rows = [...document.querySelectorAll('tr[data-conn-id]')];
            if (!rows.length) {
                return;
            }
            btn.disabled = true;
            const originalHtml = btn.innerHTML;
            const csrf = btn.getAttribute('data-csrf');
            const items = [];
            let okCount = 0;
            let failCount = 0;
            box.hidden = false;
            box.className = 'connection-result sync-all-result connection-result-pending';

            for (let i = 0; i < rows.length; i++) {
                const id = rows[i].getAttribute('data-conn-id');
                const label = rows[i].getAttribute('data-conn-label') || ('#' + id);
                const icon = rows[i].querySelector('.source-picker-ic')?.outerHTML || '';
                btn.textContent = 'Synchronizuji ' + (i + 1) + '/' + rows.length + '…';
                items.push({ icon, label, msg: '', state: 'pending' });
                render(items, `Synchronizuji ${i + 1}/${rows.length}…`);
                const item = items[items.length - 1];
                try {
                    const res = await fetch('connection-sync.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'id=' + encodeURIComponent(id) + '&mode=incremental&csrf=' + encodeURIComponent(csrf),
                    });
                    const ct = res.headers.get('content-type') || '';
                    if (!ct.includes('application/json')) {
                        throw new Error('relace vypršela, obnov stránku (F5) a přihlas se znovu');
                    }
                    const data = await res.json();
                    if (data.ok) { okCount++; item.state = 'ok'; item.msg = data.message || 'OK'; }
                    else { failCount++; item.state = 'error'; item.msg = data.message || 'chyba bez hlášky'; }
                } catch (err) {
                    failCount++;
                    item.state = 'error';
                    item.msg = err.message;
                }
                render(items, `Synchronizuji ${Math.min(i + 2, rows.length)}/${rows.length}…`);
            }

            box.className = 'connection-result sync-all-result ' + (failCount ? 'connection-result-error' : 'connection-result-ok');
            // České skloňování: 1 chyba, 2-4 chyby, 5+ (a 0) chyb.
            const chybaWord = (n) => (n === 1 ? 'chyba' : (n >= 2 && n <= 4 ? 'chyby' : 'chyb'));
            const headBadge = failCount
                ? `<span class="status-badge status-error">${failCount === 1 ? 'Chyba' : 'Chyby'}</span>`
                : '<span class="status-badge status-ok">Hotovo</span>';
            render(items, `${headBadge} <strong>${okCount} OK</strong>${failCount ? `, ${failCount} ${chybaWord(failCount)}` : ''}`);
            btn.disabled = false;
            btn.innerHTML = originalHtml;
            refreshIcons();
        });
    }

    function bindProviderDefaults() {
        const select = document.querySelector('[data-provider-select]');

        if (!select) {
            return;
        }

        const guides = document.querySelectorAll('[data-guide-for]');

        const swapGuide = (key) => {
            guides.forEach((guide) => {
                guide.hidden = guide.getAttribute('data-guide-for') !== key;
            });
        };

        // Show/hide OAuth-only fields + relabel the token field for API-key providers (e.g. Clarity).
        const toggleOauth = (supportsOauth) => {
            document.querySelectorAll('[data-oauth-only]').forEach((el) => {
                el.hidden = !supportsOauth;
            });
            const tokenInput = document.querySelector('[name="access_token"]');
            if (tokenInput) {
                const span = tokenInput.closest('label')?.querySelector('span');
                if (span) {
                    span.textContent = supportsOauth ? 'Access token' : 'API token';
                }
            }
        };

        select.addEventListener('change', () => {
            const option = select.options[select.selectedIndex];
            const fields = ['scopes', 'auth_url', 'token_url', 'api_base_url'];

            fields.forEach((field) => {
                const input = document.querySelector(`[name="${field}"]`);
                const value = option.getAttribute(`data-${field}`);
                if (input && value && !input.value) {
                    input.value = value;
                }
            });

            swapGuide(option.getAttribute('data-provider_key') || '');
            toggleOauth(option.getAttribute('data-supports_oauth') !== '0');
        });
    }

    function bindConnectionActions() {
        const resultBox = document.querySelector('[data-connection-result]');
        if (!resultBox) return;

        const show = (cls, text) => {
            resultBox.hidden = false;
            resultBox.className = 'connection-result connection-result-' + cls;
            resultBox.textContent = text;
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
                throw new Error('Relace pravděpodobně vypršela. Obnov stránku (F5), přihlas se znovu a akci zopakuj.');
            }
            return res.json();
        };

        const runSimple = async (endpoint, button, body) => {
            button.disabled = true;
            const original = button.textContent;
            button.textContent = 'Pracuju…';
            show('pending', 'Volám API…');
            try {
                const data = await postJson(endpoint, body);
                show(data.ok ? 'ok' : 'error', data.message || (data.ok ? 'Hotovo.' : 'Chyba bez hlášky.'));
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
            button.textContent = 'Stahuji historii…';
            let chunkStart = '';
            let totalRows = 0, totalPosts = 0, totalLanding = 0, iterations = 0;
            try {
                while (true) {
                    iterations++;
                    const body = 'id=' + encodeURIComponent(id) + '&mode=backfill&csrf=' + encodeURIComponent(csrf) +
                        (chunkStart ? '&chunk_start=' + encodeURIComponent(chunkStart) : '');
                    show('pending', 'Stahuji ' + (chunkStart || 'začátek') + '… (' + iterations + ' dávek hotovo)');
                    const data = await postJson('connection-sync.php', body);
                    if (!data.ok) { show('error', data.message || 'Backfill selhal.'); break; }
                    if (data.stats) {
                        // Different engines report different stat fields: GA4 = metrics_daily_rows/landing_pages_rows,
                        // Meta/IG = rows (metrics) + posts (social posts). Accumulate generically so the summary
                        // isn't "0 dní" for non-GA4 providers that actually wrote data.
                        totalRows += (data.stats.metrics_daily_rows || data.stats.rows || 0);
                        totalPosts += (data.stats.posts || 0);
                        totalLanding += (data.stats.landing_pages_rows || 0);
                    }
                    if (data.done || !data.next_chunk_start) {
                        const parts = [totalRows + ' záznamů metrik'];
                        if (totalPosts) { parts.push(totalPosts + ' příspěvků'); }
                        if (totalLanding) { parts.push(totalLanding + ' landing řádků'); }
                        show('ok', 'Hotovo. Staženo ' + parts.join(', ') + ' od ' + (data.backfill_start || '') + '. Obnov dashboard.');
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
                if (!confirm('Stáhnout celou historii (klouzavých 16 měsíců)? Může to trvat několik minut – nezavírej stránku.')) return;
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
            btn.closest('.admin-card')?.querySelectorAll('tr.row-extra').forEach((row) => row.classList.remove('row-extra'));
            btn.remove();
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
        const esc = (value) => String(value).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

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
                `).join('') : '<tr><td colspan="3" class="table-muted">Žádná data pro toto období.</td></tr>';
                card.querySelectorAll('[data-lp-page]').forEach((el) => { el.textContent = String(d.page); });
                card.querySelectorAll('[data-lp-pages]').forEach((el) => { el.textContent = String(d.pageCount); });
                const total = card.querySelector('[data-lp-total]');
                if (total) { total.textContent = String(d.total); }
                if (nav) { nav.hidden = d.pageCount <= 1; }
                prev?.classList.toggle('is-disabled', page <= 1);
                next?.classList.toggle('is-disabled', page >= pageCount);
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
                btn.classList.toggle('is-active', btn.dataset.reportTabBtn === key);
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
                count.textContent = `vybráno ${checks.filter((c) => c.checked).length} z ${checks.length}`;
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

            badge.className = `status-badge ${count > 0 ? 'status-ok' : 'status-warning'}`;
            if (count > 0) {
                badge.textContent = `${count.toLocaleString('cs-CZ')} za 28 dní`;
            } else {
                badge.textContent = 'za 28 dní nepřišel';
                badge.title = 'Event za posledních 28 dní nepřišel, zkontroluj měření (GTM).';
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
                counter.textContent = `${all.length} z ${max} kroků`;
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

    document.addEventListener('DOMContentLoaded', () => {
        bindTheme();
        bindSidebar();
        bindSyncAll();
        bindProviderDefaults();
        bindConnectionActions();
        bindInlineReplacements();
        bindReportTabs();
        bindLandingPagesAjax();
        bindDomainAccess();
        bindFunnelEditor();
        refreshIcons();
    });
})();
