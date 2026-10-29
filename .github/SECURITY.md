# Security Policy

**English** | [Čeština](#cestina)

## Reporting a vulnerability

Please do not report security issues in a public issue.

Use a private report instead:

1. Open https://github.com/ffscz/AllStat/security/advisories/new (the Security tab, "Report a vulnerability").
2. Only the maintainers can see the report.

Please include:

- the AllStat version (shown at the bottom of the sidebar in the app),
- what the problem affects,
- how to reproduce it.

## What happens next

- The maintainers review the report as soon as they can.
- The fix is released as a new version.
- Details are published only after the fix is released.

## Supported versions

Fixes are released for the latest version only. Update with one button (Settings, Version and updates). See the guide: https://ffscz.github.io/AllStat/en/#aktualizace

## How AllStat protects your data

- Access tokens for connected services are encrypted in the database (AES-256-GCM). The key is stored in the `config-keys.php` file, outside the database.
- Updates are signed (ECDSA P-256). Nothing is installed without a valid signature.
- Two-factor authentication, an audit log, and the option to limit users to selected websites.
- The AI connection (MCP) is read-only. An administrator turns it on and can revoke it.

---

<a id="cestina"></a>

# Bezpečnostní zásady

[English](#security-policy) | **Čeština**

## Hlášení zranitelnosti

Bezpečnostní chyby prosím nehlaste veřejným issue.

Použijte soukromé hlášení:

1. Otevřete https://github.com/ffscz/AllStat/security/advisories/new (záložka Security, „Report a vulnerability“).
2. Hlášení uvidí jen správci.

Do hlášení uveďte:

- verzi AllStatu (je dole v bočním panelu aplikace),
- čeho se chyba týká,
- jak ji zopakovat.

## Co bude dál

- Správci hlášení projdou co nejdřív.
- Opravu vydají jako novou verzi.
- Podrobnosti zveřejní až po vydání opravy.

## Podporované verze

Opravy vycházejí pro nejnovější verzi. Aktualizujete jedním tlačítkem (Nastavení, Verze a aktualizace). Postup najdete v návodu: https://ffscz.github.io/AllStat/#aktualizace

## Jak AllStat chrání vaše data

- Přístupové tokeny ke službám jsou v databázi šifrované (AES-256-GCM). Klíč je v souboru `config-keys.php`, mimo databázi.
- Aktualizace jsou podepsané (ECDSA P-256). Bez platného podpisu se nic nenainstaluje.
- Dvoufázové ověření, audit log a možnost omezit uživatele jen na vybrané weby.
- Připojení AI (MCP) je jen ke čtení. Zapíná ho a ruší administrátor.
