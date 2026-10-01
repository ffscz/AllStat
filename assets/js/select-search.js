// Výběr webu s vyhledáváním. Každý <select name="domain_id"> (nebo s atributem data-select-search) s aspoň MIN_OPTIONS weby dostane tlačítko
// a rozbalovací seznam s polem „Hledat web". Nativní select zůstává ve formuláři (jen skrytý): volba ho přepne
// a vyvolá událost change, takže automatické odeslání (app.js bindAutoApply, admin.js data-autosubmit) i čtení
// hodnoty fungují beze změny. Při menším počtu webů zůstává obyčejný select.
(function () {
    'use strict';

    const MIN_OPTIONS = 8;
    const CHEVRON = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
        + 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';
    let uid = 0;

    // Hledání bez ohledu na velikost písmen a diakritiku („zapad" najde „Západ").
    const normalize = (text) => text.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');

    function enhance(select) {
        if (select.dataset.selectSearchReady || select.options.length < MIN_OPTIONS) {
            return;
        }
        select.dataset.selectSearchReady = '1';

        const listId = 'select-search-list-' + (++uid);
        const wrap = document.createElement('div');
        wrap.className = 'select-search' + (select.closest('.filter-control') ? ' is-inline' : '');

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'select-search-btn';
        btn.setAttribute('aria-haspopup', 'listbox');
        btn.setAttribute('aria-expanded', 'false');
        btn.setAttribute('aria-label', select.getAttribute('aria-label') || 'Web');
        const label = document.createElement('span');
        label.className = 'select-search-label';
        btn.append(label);
        btn.insertAdjacentHTML('beforeend', CHEVRON);

        const pop = document.createElement('div');
        pop.className = 'select-search-pop';
        pop.hidden = true;
        const input = document.createElement('input');
        input.type = 'search';
        input.className = 'select-search-input';
        input.placeholder = 'Hledat web';
        input.autocomplete = 'off';
        input.setAttribute('aria-label', 'Hledat web');
        input.setAttribute('aria-controls', listId);
        const list = document.createElement('div');
        list.className = 'select-search-list';
        list.id = listId;
        list.setAttribute('role', 'listbox');
        const empty = document.createElement('p');
        empty.className = 'select-search-empty';
        empty.textContent = 'Žádný web neodpovídá hledání.';
        empty.hidden = true;

        const items = Array.from(select.options).map((option) => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'select-search-item';
            item.setAttribute('role', 'option');
            item.dataset.value = option.value;
            item.dataset.search = normalize(option.textContent);
            item.textContent = option.textContent.trim();
            list.append(item);
            return item;
        });
        const visible = () => items.filter((item) => !item.hidden);

        const sync = () => {
            label.textContent = (select.selectedOptions[0]?.textContent || '').trim();
            items.forEach((item) => {
                const selected = item.dataset.value === select.value;
                item.classList.toggle('is-selected', selected);
                item.setAttribute('aria-selected', String(selected));
            });
        };
        const filter = () => {
            const query = normalize(input.value.trim());
            items.forEach((item) => { item.hidden = query !== '' && !item.dataset.search.includes(query); });
            empty.hidden = visible().length > 0;
        };
        const close = (focusButton) => {
            pop.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
            if (focusButton) { btn.focus(); }
        };
        const open = () => {
            input.value = '';
            filter();
            pop.hidden = false;
            pop.classList.remove('is-right');
            // U pravého okraje okna (topbar, mobil) se seznam otevře doleva.
            if (pop.getBoundingClientRect().right > window.innerWidth - 8) { pop.classList.add('is-right'); }
            btn.setAttribute('aria-expanded', 'true');
            items.find((item) => item.classList.contains('is-selected'))?.scrollIntoView({ block: 'nearest' });
            input.focus();
        };
        const choose = (value) => {
            close(true);
            if (select.value !== value) {
                select.value = value;
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
            sync();
        };

        btn.addEventListener('click', () => (pop.hidden ? open() : close(false)));
        input.addEventListener('input', filter);
        input.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown') { event.preventDefault(); visible()[0]?.focus(); }
            if (event.key === 'Enter') { event.preventDefault(); const first = visible()[0]; if (first) { choose(first.dataset.value); } }
        });
        items.forEach((item) => {
            item.addEventListener('click', () => choose(item.dataset.value));
            item.addEventListener('keydown', (event) => {
                if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') { return; }
                event.preventDefault();
                const shown = visible();
                const next = shown[shown.indexOf(item) + (event.key === 'ArrowDown' ? 1 : -1)];
                (next || (event.key === 'ArrowUp' ? input : item)).focus();
            });
        });
        pop.addEventListener('keydown', (event) => { if (event.key === 'Escape') { event.preventDefault(); close(true); } });
        document.addEventListener('click', (event) => { if (!pop.hidden && !wrap.contains(event.target)) { close(false); } });
        select.addEventListener('change', sync);

        pop.append(input, list, empty);
        wrap.append(btn, pop);
        select.classList.add('select-search-native');
        select.after(wrap);
        sync();
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('select[name="domain_id"], select[data-select-search]').forEach(enhance);
    });
})();
