// Kód 2FA a záložní kódy (admin/login-2fa.php, admin/setup-2fa.php).
(() => {
    // Pole [data-otp]: jedno skutečné pole (vložení, správci hesel, čtečky obrazovky), nad ním šest políček jen pro
    // oko. Bere jen číslice, vložený kód i s mezerou nebo pomlčkou očistí. [data-otp-autosubmit] odešle formulář
    // po šesté číslici, ale stejnou hodnotu jen jednou (chybný kód by jinak zbytečně spotřeboval pokusy).
    function bindOtp(input) {
        const length = Number(input.getAttribute('maxlength')) || 6;
        const wrap = document.createElement('div');
        wrap.className = 'otp';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        input.classList.add('otp-input');

        const slots = Array.from({ length }, () => {
            const slot = document.createElement('span');
            slot.className = 'otp-slot';
            slot.setAttribute('aria-hidden', 'true');
            wrap.appendChild(slot);
            return slot;
        });

        let submitted = '';
        const render = () => {
            const focused = document.activeElement === input;
            const caret = Math.min(input.value.length, length - 1);
            slots.forEach((slot, i) => {
                slot.textContent = input.value[i] || '';
                slot.classList.toggle('is-active', focused && i === caret);
            });
        };
        const clean = (value) => value.replace(/\D+/g, '').slice(0, length);
        const maybeSubmit = () => {
            if (!input.hasAttribute('data-otp-autosubmit') || input.value.length !== length || input.value === submitted) { return; }
            submitted = input.value;
            input.form?.requestSubmit();
        };

        input.addEventListener('paste', (event) => {
            const text = event.clipboardData?.getData('text');
            if (typeof text !== 'string') { return; }
            event.preventDefault();
            input.value = clean(text);
            input.setSelectionRange(input.value.length, input.value.length);
            render();
            maybeSubmit();
        });
        input.addEventListener('input', () => {
            const value = clean(input.value);
            if (value !== input.value) { input.value = value; }
            render();
            maybeSubmit();
        });
        // Kurzor vždy na konci: políčka ukazují pořadí číslic, editace uprostřed by vypadala jinak, než se chová.
        const toEnd = () => requestAnimationFrame(() => { input.setSelectionRange(input.value.length, input.value.length); render(); });
        ['focus', 'click', 'keyup'].forEach((type) => input.addEventListener(type, toEnd));
        input.addEventListener('blur', render);
        // Během odesílání tlačítko vypnout: druhý klik by poslal stejný kód se starou session a skončil na přihlášení.
        input.form?.addEventListener('submit', () => {
            submitted = input.value;
            input.form.querySelectorAll('button[type=submit]').forEach((button) => { button.disabled = true; });
        });
        // Návrat tlačítkem Zpět (bfcache) vrátí stránku i s vypnutým tlačítkem.
        window.addEventListener('pageshow', (event) => {
            if (event.persisted) { input.form?.querySelectorAll('button[type=submit]').forEach((button) => { button.disabled = false; }); }
        });
        render();
    }

    // Záložní kódy: kopírovat do schránky a stáhnout jako .txt (zobrazené jednou po vygenerování).
    function codesText(button) {
        const list = document.querySelector('[data-backup-codes]');
        return list ? Array.from(list.querySelectorAll('code'), (code) => code.textContent.trim()).join('\n') : '';
    }

    document.addEventListener('click', (event) => {
        const copy = event.target.closest('[data-backup-copy]');
        if (copy) {
            navigator.clipboard.writeText(codesText(copy)).then(() => {
                const label = copy.querySelector('span');
                if (!label) { return; }
                const original = label.textContent;
                label.textContent = copy.dataset.done || original;
                setTimeout(() => { label.textContent = original; }, 2000);
            }).catch(() => {});
            return;
        }
        const download = event.target.closest('[data-backup-download]');
        if (download) {
            const text = (download.dataset.title ? download.dataset.title + '\n\n' : '') + codesText(download) + '\n';
            const link = document.createElement('a');
            link.href = URL.createObjectURL(new Blob([text], { type: 'text/plain;charset=utf-8' }));
            link.download = download.dataset.backupDownload || 'allstat-backup-codes.txt';
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(link.href), 1000);
        }
    });

    // Schránka jde jen přes HTTPS (secure context); bez ní tlačítko Kopírovat schovat, zbude Stáhnout .txt.
    if (!navigator.clipboard) {
        document.querySelectorAll('[data-backup-copy]').forEach((button) => { button.hidden = true; });
    }
    // Kódy se ukazují jen jednou: po návratu tlačítkem Zpět z bfcache je skrýt (no-store Chrome v bfcache nechává).
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            document.querySelectorAll('[data-backup-codes]').forEach((list) => { list.replaceChildren(); });
        }
    });

    document.querySelectorAll('input[data-otp]').forEach(bindOtp);
})();
