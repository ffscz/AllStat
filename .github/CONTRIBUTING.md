# Contributing

**English** | [Čeština](#cestina)

Thank you for your interest in AllStat.

## Bugs and ideas

- Use the issue forms: https://github.com/ffscz/AllStat/issues/new/choose. The form asks for your version and hosting.
- The forms are in Czech. You can write in Czech, Slovak or English.
- Never report security issues publicly. See [SECURITY.md](SECURITY.md).
- For questions about installation or connecting services, check the guide first: https://ffscz.github.io/AllStat/en/

## Pull requests

- Pull requests are welcome.
- For a bigger change, open an issue first and agree on the approach.
- One PR, one thing. Keep changes small and readable.

## Technical rules

- The code must run on PHP 8.1 or newer (8.3 recommended) and MySQL 5.7.8+ or MariaDB 10.3+.
- It must work on common shared hosting: no Composer, npm or build step. Use plain PHP, vanilla JavaScript and CSS. Libraries such as Chart.js are bundled in `assets/vendor`.
- Follow the style and naming of the surrounding code.
- UI texts are written in Czech through `t('...')`. Every new text needs a Slovak and English translation in `lang/sk/<group>.php` and `lang/en/<group>.php`.
- Database changes only add things (new tables and columns, never delete data). Put the migration in `lib/migrations.php`, raise the `ALLSTAT_SCHEMA_VERSION` constant, and update `schema.sql` for clean installs.
- Do not change line endings (they are turned off in `.gitattributes`), because the checksums in `lib/manifest.json` must match.
- `VERSION`, `lib/manifest.json`, `update.json`, the changelog and the signed release are handled by the maintainers. Do not change them in a PR.
- Never commit `config.php`, `config-keys.php`, credentials, tokens or data exports.

## Tests

- The `tests/` folder has CLI tests that need no network and no credentials. Run them with `php tests/<file>.php`, for example `php tests/microsoft-ads.php`.
- Check UI changes in a browser in the light and dark theme and on mobile.

## License

Contributions are licensed under the project license (GNU GPL v3).

---

<a id="cestina"></a>

# Jak přispět

[English](#contributing) | **Čeština**

Děkujeme za zájem o AllStat.

## Chyby a nápady

- Použijte formuláře v Issues: https://github.com/ffscz/AllStat/issues/new/choose. Formulář se zeptá na verzi a hosting.
- Formuláře jsou česky. Psát můžete česky, slovensky i anglicky.
- Bezpečnostní chyby nikdy nehlaste veřejně. Viz [SECURITY.md](SECURITY.md).
- Dotazy k instalaci a napojení služeb nejdřív řešte podle návodu: https://ffscz.github.io/AllStat/

## Pull requesty

- Pull requesty jsou vítané.
- U větší změny nejdřív založte issue a domluvte se.
- Jeden PR je jedna věc. Změny držte malé a čitelné.

## Technické zásady

- Kód musí běžet na PHP 8.1 a novějším (doporučeno 8.3) a na MySQL 5.7.8+ nebo MariaDB 10.3+.
- Musí fungovat na běžném sdíleném hostingu: žádný Composer, npm ani build krok. Používejte čisté PHP, vanilla JavaScript a CSS. Knihovny jako Chart.js jsou přibalené v `assets/vendor`.
- Styl a pojmenování se řiďte okolním kódem.
- Texty rozhraní se píšou česky přes funkci `t('...')`. Každý nový text potřebuje překlad do slovenštiny a angličtiny v `lang/sk/<skupina>.php` a `lang/en/<skupina>.php`.
- Změny databáze jen přidávají (nové tabulky a sloupce, data nikdy nemažte). Migraci dejte do `lib/migrations.php`, zvyšte konstantu `ALLSTAT_SCHEMA_VERSION` a pro čistou instalaci doplňte `schema.sql`.
- Neměňte konce řádků (repozitář je má vypnuté v `.gitattributes`), protože kontrolní součty v `lib/manifest.json` musí sedět.
- `VERSION`, `lib/manifest.json`, `update.json`, changelog a podepsané vydání řeší správci. V PR je neměňte.
- Do repozitáře nikdy nedávejte `config.php`, `config-keys.php`, přístupové údaje, tokeny ani exporty dat.

## Testy

- Ve složce `tests/` jsou CLI testy bez sítě a bez přístupových údajů. Spouštějí se `php tests/<soubor>.php`, například `php tests/microsoft-ads.php`.
- Změnu rozhraní ověřte v prohlížeči ve světlém i tmavém motivu a na mobilu.

## Licence

Příspěvky jsou pod licencí projektu (GNU GPL v3).
