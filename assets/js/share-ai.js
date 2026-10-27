// Tlačítko „Analyzovat s AI“ na přehledu: vytvoří dočasný report pro AI (admin/share-create.php),
// nabídne zkopírování textu a odkaz. Na stránce je jen s tlačítkem #shareAiBtn.
(function () {
    var btn = document.getElementById('shareAiBtn');
    var box = document.getElementById('shareResult');
    if (!btn || !box) { return; }
    var lastFmt = 'md';
    function gen(fmt) {
        lastFmt = fmt;
        btn.disabled = true;
        // Období + web čteme ŽIVĚ z filtru (skrytá pole rozsahu / výběr webu), ne ze zastaralých
        // data-atributů tlačítka. Po AJAX změně období na přehledu se ty atributy neaktualizují, takže
        // by report jinak vždy vzal původní rozsah. Na provider views to funguje taky (form se re-rendruje).
        var form = document.getElementById('dashboardFilters');
        var live = function (sel, fb) { var el = form && form.querySelector(sel); return (el && el.value) ? el.value : fb; };
        var body = new URLSearchParams({
            domain_id: live('[name="domain_id"]', btn.dataset.domain),
            source_id: btn.dataset.source,
            start: live('[data-date-start]', btn.dataset.start),
            end: live('[data-date-end]', btn.dataset.end),
            // Růst kanálů: report za měsíční okno pohledu (share-create.php si ho dopočítá z months).
            view: btn.dataset.view || '',
            months: btn.dataset.months || '',
            format: fmt, ttl: '15', csrf: btn.dataset.csrf
        });
        fetch('admin/share-create.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                btn.disabled = false;
                if (!d.ok) { alert(d.message || window.allstatT('Odkaz se nepodařilo vytvořit.')); return; }
                box.querySelector('[data-share-url]').value = d.url;
                var exp = (d.expires_at || '').slice(11, 16);
                box.querySelector('[data-share-expiry]').textContent = window.allstatT(' · platí {minutes} min', { minutes: d.ttl_minutes }) + (exp ? window.allstatT(' (do {time})', { time: exp }) : '');
                box.hidden = false;
            })
            .catch(function () { btn.disabled = false; alert(window.allstatT('Chyba sítě.')); });
    }
    btn.addEventListener('click', function () { gen(lastFmt); });
    box.querySelectorAll('[data-share-fmt]').forEach(function (a) {
        a.addEventListener('click', function (e) { e.preventDefault(); gen(a.dataset.shareFmt); });
    });
    function flash(el, msg, orig, ms) { el.textContent = msg; setTimeout(function () { el.textContent = orig; }, ms || 1600); }
    // "Kopírovat text pro AI": stáhne obsah reportu (same-origin) a dá ho do schránky; funguje v každé AI.
    var copyTextBtn = box.querySelector('[data-share-copy-text]');
    if (copyTextBtn) {
        copyTextBtn.addEventListener('click', function () {
            var url = box.querySelector('[data-share-url]').value;
            if (!url) { return; }
            copyTextBtn.disabled = true;
            copyTextBtn.textContent = window.allstatT('Stahuji…');
            fetch(url, { headers: { 'Accept': 'text/plain' } })
                .then(function (r) { if (!r.ok) { throw new Error('http'); } return r.text(); })
                .then(function (text) {
                    if (navigator.clipboard) { return navigator.clipboard.writeText(text); }
                    var ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
                })
                .then(function () { copyTextBtn.disabled = false; flash(copyTextBtn, window.allstatT('Text zkopírován ✓'), window.allstatT('Kopírovat text pro AI'), 1800); })
                .catch(function () { copyTextBtn.disabled = false; copyTextBtn.textContent = window.allstatT('Kopírovat text pro AI'); alert(window.allstatT('Text se nepodařilo načíst, odkaz možná vypršel. Vygeneruj nový (klikni na ✨).')); });
        });
    }
    var closeBtn = box.querySelector('[data-share-close]');
    if (closeBtn) { closeBtn.addEventListener('click', function () { box.hidden = true; }); }
})();
