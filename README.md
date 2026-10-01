# AllStat

Unifikovaný analytický dashboard: na jednom místě sbírá statistiky webu a sociálních sítí
a ukládá je do **vaší vlastní databáze**. Běží na běžném webhostingu s PHP a MySQL.

*A self-hosted analytics dashboard (PHP + MySQL) that unifies website and social media statistics
in your own database, with a read-only AI connector (MCP) for Claude and ChatGPT. UI in Czech.*

## Co umí

- **Zdroje dat:** Google Analytics 4, Google Search Console, Microsoft Clarity, Facebook, Instagram,
  Meta Ads, LinkedIn, YouTube, Google Ads, Seznam Webmaster, import CSV z Meta Business Suite.
- **Více webů** pod jednou instalací, u každého uživatele lze omezit, které weby vidí.
- **Růst kanálů**, reporty, sdílené odkazy pro AI analýzu, datové feedy.
- **Připojení AI (MCP)** pro Claude a ChatGPT: jen ke čtení, souhrnná data bez osobních údajů,
  zapíná a ruší administrátor.
- **Bezpečnost:** šifrované přístupové tokeny (AES-256-GCM), dvoufázové ověření, audit log.

## Požadavky

- PHP 8.1 nebo novější (doporučeno 8.3) s rozšířeními `pdo_mysql`, `curl`, `openssl`, `mbstring`, `json`, `ctype`
- MySQL 5.7.8+ nebo MariaDB 10.3+
- Apache nebo LiteSpeed (pravidla v `.htaccess`), HTTPS
- cron (stačí jednou denně)

## Instalace

1. Stáhněte poslední verzi z [Releases](https://github.com/ffscz/AllStat/releases): soubor `allstat-X.Y.Z.zip`
   (a ověřte ho podle `.sha256`).
2. Rozbalte ho a složku `allstat` nahrajte přes FTP na hosting.
3. Otevřete adresu složky v prohlížeči, spustí se instalační průvodce.

Podrobný návod s obrázky je v balíčku (`docs/INSTALACE.html`) a jako PDF u každého vydání.

## Aktualizace

**Od verze 1.2.0 jedním tlačítkem.** AllStat sám zjistí novou verzi (feed [`update.json`](update.json)),
administrátor ji uvidí v bočním panelu a nainstaluje ze stránky Aktualizace. Balíček je podepsaný
(ECDSA P-256, veřejný klíč je v `lib/updater.php`), bez platného podpisu se nic nenainstaluje.
Přepisované soubory se zálohují a předchozí verzi jde vrátit.

**Ručně (verze 1.1.0 a starší, nebo když PHP nesmí zapisovat do složky aplikace):** novou verzi nahrajte
přes stávající instalaci (stejné místo, přepsat soubory). Soubory `config.php` a `config-keys.php`
balíček neobsahuje, takže zůstanou beze změny. Databáze se při prvním otevření doplní sama (migrace jen
přidávají tabulky a sloupce, data nemažou). Postup je v kapitole 8 příručky.

## Repozitář

Repozitář obsahuje přesně to, co instalační balíček: žádné přístupové údaje ani klíče.
Každá verze je tag `vX.Y.Z` a vydání s instalačním ZIPem, kontrolním součtem a příručkou.
Konce řádků se v gitu nemění (`.gitattributes`), aby seděly kontrolní součty v `lib/manifest.json`.

Bezpečnostní chybu prosím nehlaste veřejným issue, použijte
[soukromé hlášení zranitelnosti](https://github.com/ffscz/AllStat/security/advisories/new).

## Licence

[GNU GPL verze 3](LICENSE).
