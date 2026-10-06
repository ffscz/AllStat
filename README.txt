AllStat
=======

Přehledný nástroj, který na jednom místě sbírá statistiky vašeho webu a sociálních sítí
(Google Analytics 4, Search Console, Microsoft Clarity, Facebook, Instagram, LinkedIn,
YouTube, Meta Ads) a ukládá je do vaší vlastní databáze. Data zůstávají u vás na hostingu.
Součástí je i připojení AI: asistenti Claude a ChatGPT si z AllStatu mohou číst data (jen ke čtení,
zapíná a ruší ho administrátor).

Licence: GNU GPL verze 3 (soubor LICENSE). Zdrojový kód a nové verze: https://github.com/ffscz/AllStat


Co potřebujete
--------------
 * webhosting s PHP 8.1 nebo novějším (doporučeno 8.3), HTTPS certifikátem a přístupem přes FTP
 * prázdnou databázi MySQL nebo MariaDB a uživatele, který k ní má plná práva
 * asi 15 minut času


Instalace ve zkratce
--------------------
 1. Nahrajte celou složku "allstat" přes FTP na svůj hosting (například do složky public_html).
 2. V administraci hostingu vytvořte prázdnou databázi a uživatele k ní.
 3. V prohlížeči otevřete adresu složky, například https://www.vase-domena.cz/allstat/
    (nebo adresu subdomény, když jste AllStat nahráli do její složky).
    Zobrazí se instalační průvodce.
 4. Vyplňte údaje o databázi, organizaci a administrátorovi, zvolte umístění připojení AI
    a klikněte na "Nainstalovat".
 5. Zálohujte soubory config.php a config-keys.php a nastavte denní cron podle pokynů na
    poslední stránce průvodce.

Instalaci dokončete hned po nahrání souborů. Dokud není hotová, může průvodce spustit každý,
kdo zná adresu webu.


Podrobný návod
--------------
Návod s obrázky, nastavením cronu, napojením služeb, aktualizací a řešením problémů je v souboru
docs/INSTALACE.html. Otevřete ho dvojklikem ve svém počítači (před nahráním na hosting).
Složku "docs" a tento soubor můžete po instalaci ze serveru smazat.


Změny ve verzi 1.2.5
--------------------
 * Připojení AI: nový nástroj query_data. Asistent se může zeptat na cokoli, co AllStat ukládá:
   konkrétní stránku, kampaň, událost, dotaz, region nebo příspěvek, seznamy až 500 řádků, vývoj
   po dnech, týdnech nebo měsících a srovnání s předchozím obdobím nebo meziročně. Včetně všech
   metrik a rozpadů zdrojů (demografie sledujících, zdroje zhlédnutí YouTube, kampaně Mety…).
 * Synchronizace: když se nepodaří stáhnout seznam příspěvků z Facebooku nebo Instagramu, poznámka
   to řekne i s odpovědí Mety (dřív jen „0 příspěvků“); výpadek spojení se jednou zopakuje.
 * Cron umí zpracovat jen jedno napojení (only=ID) a klíč přijme i v hlavičce X-Cron-Secret.

Změny ve verzi 1.2.4
--------------------
 * Správnější čísla na dashboardu i v MCP:
   - Uživatelé za běžná období (posledních 7, 30, 90 a 365 dní, měsíce, roky) jsou přesný počet
     různých lidí z GA4. U jiných období je číslo poctivě označené jako součet dní.
   - Podíly stránek, odkazujících webů, AI zdrojů a rozpadů Clarity se počítají z celku.
   - Celý měsíc se srovnává s předchozím celým měsícem (září proti srpnu), rozběhnutý měsíc
     se stejnými dny minulého měsíce.
   - Průměrná pozice dotazů v Google je vážená zobrazeními, stejně jako v Search Console.
   - Nenapojený zdroj nebo metrika bez dat se hlásí jako nedostupná, ne jako nula.
   - Meziroční růst se nepočítá přes změnu definice metriky (dosah Facebooku od 15. 6. 2026).
 * MCP: report v Markdownu vidí i Claude, rozpad trychtýře neztrácí řádky, řady po týdnech
   a měsících i u reklam a Clarity, delší tabulky v reportu, pokrytí dní u metrik zdrojů
   a rozlišení zpoždění dat od výpadku synchronizace.
 * Instagram: noví sledující se znovu stahují (stahování historie je omylem vypínalo),
   chybějící dny za poslední měsíc se při synchronizaci doplní samy.
 * Clarity: nově čas zapojení, hloubka scrollu, rage a dead clicks, rychlé návraty, chyby
   JavaScriptu a rozpady podle zařízení, systému a země (ze stejné odpovědi API, bez volání navíc).
 * Google Ads: rozpad podle kampaní na dashboardu i v MCP.

Změny ve verzi 1.2.3
--------------------
 * MCP hlásí zpoždění dat: Search Console a YouTube Analytics dodávají data o 2 až 3 dny později.
   Nástroje (přehled, dotazy, YouTube, report) nově vrací data_delays s chybějícími dny a označí
   neúplné ukazatele, aby AI nevykládala chybějící dny jako pokles.
 * Popis organizace se v instrukcích MCP už nezkracuje.

Změny ve verzi 1.2.2
--------------------
 * Obnova z GitHubu: na stránce Aktualizace jde přeinstalovat vydání z GitHubu i bez novější verze
   (třeba po nepovedeném ručním nahrání souborů). Se zálohou a možností vrátit předchozí stav.
 * Popis organizace pro AI analýzy je v Nastavení u právních údajů pro všechny instalace.


Změny ve verzi 1.2.1
--------------------
 * Verze AllStatu je vidět v bočním panelu: v přehledu pod stavem synchronizace, v administraci dole.


Změny ve verzi 1.2.0
--------------------
 * Aktualizace jedním tlačítkem: AllStat sám zjistí novou verzi na GitHubu, administrátor ji uvidí
   v bočním panelu a nainstaluje ze stránky Aktualizace (Nastavení, Verze a aktualizace). Balíček je
   podepsaný, přepisované soubory se zálohují a předchozí verzi jde vrátit. Upravený .htaccess zůstane.
 * Z verze 1.1.0 a starší je potřeba na 1.2.0 aktualizovat ještě ručně (docs/INSTALACE.html, kapitola 8),
   další verze už půjdou tlačítkem.


Změny ve verzi 1.1.0
--------------------
 * Trychtýře: konverzní cesty z GA4 eventů (například návštěva, spuštění kalkulačky, dokončení,
   klik na CTA, poptávka). Nastavení v menu Trychtýře (šablony, přetahování kroků, kontrola měření),
   zobrazení v přehledu s úbytkem mezi kroky, vývojem v čase a rozpadem podle kanálu, zdroje nebo kampaně.
 * Připojení AI: nový nástroj get_funnel, asistent umí trychtýře číst a hledat, kde se lidé ztrácejí.
 * Příručka: kapitola o trychtýřích a o přípravě měření v Google Tag Manageru a GA4.
 * Aktualizace z verze 1.0.x: postup je v docs/INSTALACE.html, kapitola 8. Databáze se doplní sama.


Změny ve verzi 1.0.2
--------------------
 * Přístup k webům podle uživatele: u účtu s rolí Uživatel jde zvolit „Jen vybrané weby“, ostatní
   weby neuvidí v přehledu, reportech ani sdílených odkazech. Výchozí je „Všechny weby“.
 * Správa (weby, zdroje, metriky, logy, feed, importy) je jen pro administrátora, i pro čtení.
 * Dávkový režim cronu pro desítky webů: limit napojení nebo sekund na jedno spuštění (Nastavení).
 * Výběr webu má od 8 webů pole pro hledání.
 * Aktualizace z verze 1.0.0 nebo 1.0.1: postup je v docs/INSTALACE.html, kapitola 8.


Změny ve verzi 1.0.1
--------------------
 * Google Ads: napojení přešlo na aktuální verzi Google Ads API (v25). Developer token už není
   potřeba, přístup určuje projekt v Google Cloud (viz docs/INSTALACE.html, část Google Ads).
 * Meta Ads: souhrny za celou dobu kampaně, sestavy a reklamy, skutečná frekvence a cíl kampaně.
 * Aktualizace z verze 1.0.0: postup je v docs/INSTALACE.html, kapitola 8. config.php
   a config-keys.php zůstanou beze změny, databáze se upraví sama při prvním otevření.
