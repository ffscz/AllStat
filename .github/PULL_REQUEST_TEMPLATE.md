## What and why / Co a proč

<!-- Describe the change and link the issue (Closes #). Popište změnu a připojte issue (Closes #). -->

## How it was verified / Jak bylo ověřeno

<!-- Commands run, pages checked. Spuštěné příkazy, zkontrolované stránky. -->

## Checklist / Kontrolní seznam

- [ ] Runs on PHP 8.1+ / Běží na PHP 8.1+
- [ ] New texts have Slovak and English translations (`lang/sk`, `lang/en`) / Nové texty mají překlad do slovenštiny a angličtiny
- [ ] Database migrations only add things, `ALLSTAT_SCHEMA_VERSION` raised and `schema.sql` updated / Migrace jen přidávají, zvýšené `ALLSTAT_SCHEMA_VERSION` a doplněné `schema.sql`
- [ ] No credentials, tokens or `config*.php` files / Žádné přístupové údaje, tokeny ani soubory `config*.php`
- [ ] `VERSION`, `lib/manifest.json`, `update.json` unchanged / beze změny
- [ ] Checked in light and dark theme and on mobile (UI changes) / Ověřeno ve světlém i tmavém motivu a na mobilu (u změn vzhledu)
