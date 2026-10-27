// Shell AllStatu (lib/layout.php): rozbalovací nabídky a přepínání motivu. Načítá se na každé stránce.
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
                if (open) { menu.querySelector('a, button')?.focus({ preventScroll: true }); }
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

    bindMenus();
    bindTheme();
    window.lucide?.createIcons();
})();
