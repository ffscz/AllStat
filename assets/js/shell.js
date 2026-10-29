// Shell AllStatu (lib/layout.php): rozbalovací nabídky, mobilní navigace a přepínání motivu. Načítá se na každé stránce.
(() => {
    // Nabídky: tlačítko [data-menu-toggle="id"] otevře/zavře .menu s tím id. Zavře je klik mimo, Escape
    // nebo otevření jiné nabídky. Stránky můžou použít stejný vzor (nabídka „…“ u řádku tabulky apod.).
    function bindMenus() {
        const toggles = () => document.querySelectorAll('[data-menu-toggle]');
        const menuOf = (toggle) => document.getElementById(toggle.dataset.menuToggle);

        const closeAll = (except) => {
            toggles().forEach((toggle) => {
                const menu = menuOf(toggle);
                if (!menu || menu === except) { return; }
                menu.hidden = true;
                toggle.setAttribute('aria-expanded', 'false');
            });
        };

        document.addEventListener('click', (event) => {
            const toggle = event.target.closest('[data-menu-toggle]');
            if (toggle) {
                const menu = menuOf(toggle);
                if (!menu) { return; }
                const open = menu.hidden;
                closeAll(menu);
                menu.hidden = !open;
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (open) {
                    // Nabídka s hledáním: fokus do pole (na dotykových zařízeních ne, vyskočila by klávesnice).
                    const filter = window.matchMedia('(pointer: fine)').matches ? menu.querySelector('[data-menu-filter]') : null;
                    (filter || menu.querySelector('a, button'))?.focus({ preventScroll: true });
                    menu.querySelector('[aria-current="true"]')?.scrollIntoView({ block: 'nearest' });
                }
                return;
            }
            if (!event.target.closest('.menu')) { closeAll(null); }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') { return; }
            const open = [...toggles()].find((t) => t.getAttribute('aria-expanded') === 'true');
            closeAll(null);
            open?.focus();
        });
    }

    // Hledání v nabídce (přepínač webů od 9 webů): pole [data-menu-filter] skryje položky [data-filter-text],
    // které hledaný text neobsahují; Enter otevře první nalezenou.
    function bindMenuFilters() {
        document.querySelectorAll('[data-menu-filter]').forEach((input) => {
            const menu = input.closest('.menu');
            const items = [...menu.querySelectorAll('[data-filter-text]')];
            const empty = menu.querySelector('[data-menu-empty]');
            input.addEventListener('input', () => {
                const q = input.value.trim().toLowerCase();
                let shown = 0;
                items.forEach((item) => {
                    item.hidden = q !== '' && !item.dataset.filterText.includes(q);
                    if (!item.hidden) { shown += 1; }
                });
                if (empty) { empty.hidden = shown > 0; }
            });
            input.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter') { return; }
                event.preventDefault();
                items.find((item) => !item.hidden)?.click();
            });
        });
    }

    // Motiv: <html data-theme> + localStorage „allstat-theme“ (výchozí světlý nastaví inline skript v <head>).
    // Po přepnutí pošle událost allstat:themechange, na kterou grafy překreslí barvy.
    function bindTheme() {
        const button = document.querySelector('[data-theme-toggle]');
        if (!button) { return; }

        const sync = () => {
            const dark = document.documentElement.dataset.theme === 'dark';
            button.setAttribute('aria-pressed', dark ? 'true' : 'false');
        };

        button.addEventListener('click', () => {
            const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
            document.documentElement.dataset.theme = next;
            try { localStorage.setItem('allstat-theme', next); } catch (e) { /* soukromé okno: motiv jen do reloadu */ }
            sync();
            document.dispatchEvent(new CustomEvent('allstat:themechange', { detail: { theme: next } }));
        });

        sync();
    }

    // Mobilní navigace: hamburger v horní liště vysune postranní panel přes obsah. Zavře ho křížek,
    // klik na ztmavené pozadí, Escape nebo návrat na širokou obrazovku (panel je pak zase vedle obsahu).
    function bindNav() {
        const shell = document.querySelector('.shell');
        const toggle = document.querySelector('[data-nav-toggle]');
        const side = document.getElementById('shell-side');
        const scrim = document.querySelector('.shell-scrim');
        if (!shell || !toggle || !side || !scrim) { return; }

        const setOpen = (open) => {
            shell.classList.toggle('is-nav-open', open);
            scrim.hidden = !open;
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            document.documentElement.classList.toggle('is-scroll-locked', open);
            if (open) {
                // Panel je při otevírání viditelný hned (layout.css), takže fokus přijme bez čekání na animaci.
                (side.querySelector('.nav-item[aria-current="page"]') || side.querySelector('a, button'))?.focus({ preventScroll: true });
            }
        };

        toggle.addEventListener('click', () => setOpen(true));
        document.querySelectorAll('[data-nav-close]').forEach((el) => el.addEventListener('click', () => {
            setOpen(false);
            toggle.focus();
        }));
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && shell.classList.contains('is-nav-open')) {
                setOpen(false);
                toggle.focus();
            }
        });
        window.matchMedia('(min-width: 808px)').addEventListener('change', (event) => {
            if (event.matches) { setOpen(false); }
        });
    }

    bindMenus();
    bindMenuFilters();
    bindNav();
    bindTheme();
    window.lucide?.createIcons();
})();
