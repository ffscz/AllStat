# Bezpečnost

## Jak nahlásit zranitelnost

Bezpečnostní chybu prosím nehlaste veřejným issue. Použijte
[soukromé hlášení zranitelnosti](https://github.com/ffscz/AllStat/security/advisories/new)
(záložka Security, „Report a vulnerability“). Hlášení uvidí jen správci repozitáře.

Do hlášení uveďte verzi AllStatu (je dole v bočním panelu), čeho se chyba týká a jak ji zopakovat.
Hlášení si co nejdřív projdeme, opravu vydáme jako novou verzi a podrobnosti zveřejníme až po vydání opravy.

*Please report security issues privately via
[GitHub private vulnerability reporting](https://github.com/ffscz/AllStat/security/advisories/new), not in public issues.*

## Podporované verze

Opravy vycházejí pro nejnovější verzi. Aktualizaci nainstalujete jedním tlačítkem
(Nastavení, Verze a aktualizace), postup je v [návodu](https://ffscz.github.io/AllStat/#aktualizace).

## Jak AllStat chrání data

- Přístupové tokeny ke službám jsou v databázi šifrované (AES-256-GCM), klíč je v souboru `config-keys.php` mimo databázi.
- Aktualizace jsou podepsané (ECDSA P-256). Bez platného podpisu se nic nenainstaluje.
- Dvoufázové ověření, audit log a omezení přístupu uživatelů jen na vybrané weby.
- Připojení AI (MCP) je jen ke čtení a zapíná ho a ruší administrátor.
