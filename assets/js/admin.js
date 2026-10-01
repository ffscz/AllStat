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
        refreshIcons();
    });
})();
