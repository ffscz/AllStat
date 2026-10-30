AllStat
=======

Přehledný nástroj, který na jednom místě sbírá statistiky vašeho webu a sociálních sítí
(Google Analytics 4, Search Console, Microsoft Clarity, Facebook, Instagram, LinkedIn,
YouTube, Meta Ads, Google Ads, Microsoft Ads) a ukládá je do vaší vlastní databáze. Data zůstávají u vás na hostingu.
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
 4. Průvodce vytvoří ve složce AllStatu soubor install-token.php. Otevřete ho přes FTP nebo
    správce souborů hostingu (v prohlížeči je prázdný) a kód z řádku "KOD:" opište do pole
    "Instalační kód".
 5. Vyplňte údaje o databázi, organizaci a administrátorovi, zvolte umístění připojení AI
    a klikněte na "Nainstalovat".
 6. Zálohujte soubory config.php a config-keys.php a nastavte denní cron podle pokynů na
    poslední stránce průvodce.

Instalaci dokončete hned po nahrání souborů. Bez instalačního kódu ji nikdo cizí dokončit
nemůže, ale dokud není hotová, průvodce zůstává otevřený.


Podrobný návod
--------------
Návod s obrázky, nastavením cronu, napojením služeb, aktualizací a řešením problémů je na webu:
https://ffscz.github.io/AllStat/
Je vždy pro nejnovější verzi a jde vytisknout nebo uložit jako PDF. Tento soubor můžete
po instalaci ze serveru smazat.


Změny ve verzi 1.5.3
--------------------
 * Připojení AI (Claude, ChatGPT) se nově povoluje i kódem z autentizační aplikace. Převzaté přihlášení
   v prohlížeči ani se znalostí hesla už nestačí na připojení, které běží až 180 dní. Dosavadní
   připojení fungují dál.
 * Uživatelé: vytvoření účtu, nové heslo jinému uživateli, povýšení na administrátora a reset cizího
   2FA se potvrzují heslem a kódem z aplikace administrátora (bez 2FA jen heslem). Reset cizího 2FA
   je nově na stránce úpravy uživatele; úprava jména, webů nebo vypnutí účtu potvrzení nepotřebuje.
 * Databáze beze změny.

Změny ve verzi 1.5.2
--------------------
 * Záložní kódy pro dvoufaktorové ověření: 10 jednorázových kódů pro přihlášení bez telefonu. Ukážou
   se jednou po nastavení 2FA (kopírovat nebo stáhnout jako .txt). Kdo už 2FA má, vygeneruje si je
   na stránce Dvoufaktorové ověření, kde vidí i počet zbývajících kódů; nové kódy vyžadují heslo
   a kód z aplikace (samotná otevřená session s heslem nestačí).
   Přihlašovací stránka počet kódů neukazuje, nabízí jen odkaz „Použít záložní kód“.
 * Pole pro kód 2FA má šest políček: kurzor po každé číslici přeskočí dál, vložený kód (Ctrl+V)
   i ručně dopsaná šestá číslice formulář odešlou samy.
 * Vlastní 2FA jde vypnout jen na stránce Dvoufaktorové ověření se zadáním hesla a kódu z aplikace
   nebo záložního kódu (dosud stačila otevřená session v Uživatelích). Reset smaže i záložní kódy
   a odpojí připojení AI.
 * Přepínač jazyka na přihlašovacích stránkách ukazuje celé názvy (Čeština, Slovenčina, English)
   a šipka už nepřekrývá text.
 * Databáze: nová tabulka allstat_totp_backup_codes, vytvoří se sama po aktualizaci.

Změny ve verzi 1.5.1
--------------------
 * Připojení AI (Claude, ChatGPT) může nově povolit jen administrátor s dvoufaktorovým ověřením. Kdo
   ho nemá, uvidí na stránce souhlasu odkaz na jeho nastavení a pak připojení v aplikaci zopakuje.
   Dosavadní připojení dál fungují; nastavení 2FA je ale (jako dosud) odpojí a je potřeba je připojit
   znovu. Na stránce AI konektory na to upozorní hláška.
 * Přístupový token připojení AI platí místo 8 hodin 1 hodinu. AI aplikace si ho obnovuje sama, nikdo
   se nemusí znovu přihlašovat. Vlastní hodnotu jinou než 8 hodin aktualizace nepřepíše.
 * Databáze: jen úprava nastavení mcp.access_ttl, doplní se sama po aktualizaci.

Změny ve verzi 1.5.0
--------------------
 * Nový vzhled celé administrace: postranní panel s přepínačem webu (od 8 webů s hledáním), horní
   lišta s drobečkovou navigací, stavem dat a uživatelskou nabídkou, světlý i tmavý motiv a písmo Geist
   (z vlastního serveru, bez externích služeb).
 * Přehled (Růst kanálů): souhrnné dlaždice, karty kanálů ve skupinách Web, Sociální sítě a Reklama,
   tabulka Měsíc po měsíci a vysvětlení výpočtu růstu.
 * Detaily zdrojů, Trychtýř a stránky správy jsou postavené ze stejných prvků; grafy berou barvy
   z motivu a po jeho přepnutí se překreslí.
 * Přihlášení, dvoufázové ověření, souhlas pro připojení AI a instalační průvodce mají nový vzhled.
 * Obsah stránek je zarovnaný k levému panelu a na širokém monitoru využije víc místa. Ovládání období
   v detailech zdrojů je pod názvem a popisek období má pevnou šířku, takže po přepnutí období nic
   neposkočí (ani v angličtině).
 * Vybraná záložka metriky nad grafem je podbarvená a má odznak „V grafu“. Dlouhé hodnoty se v záložce
   nezalamují: u Clarity je dovětek „relací (111×)“ a u YouTube minuty sledovaného času menším písmem.
 * Kontext pro AI má každý web vlastní (Administrace, AI konektory, karta Kontext pro AI): seznam webů
   s náhledem a stavem, úprava a uložení u každého webu zvlášť, při více webech hledání a filtr. Dosavadní
   společný popis z Nastavení se při aktualizaci zkopíruje ke všem webům, pak ho stačí u každého upravit.
   Připojení AI ho dostane u každého webu i na začátku reportu.
 * Graf v detailu Clarity, Meta Ads, Google Ads a Microsoft Ads se u delšího období sčítá po týdnech
   nebo měsících jako u ostatních zdrojů (dřív zůstal po dnech a nesouhlasil s nadpisem).
 * Report pro AI (tlačítko Analyzovat s AI i report v připojení AI) upozorní na neúplné dny, týdny
   a měsíce na konci období (zpoždění Search Console a YouTube, poslední synchronizace), uvádí, do kdy
   jsou data k dispozici, a u napojených zdrojů i názvy účtů.
 * Databáze: k webům přibyl sloupec pro kontext pro AI, doplní se sám po aktualizaci.
 * Tabulky jdou řadit kliknutím na záhlaví i v přehledu Měsíc po měsíci a ve Zdrojích dat (ve skupinách
   zvlášť), záhlaví se už nepřekrývají.
 * Facebook: rozpad reakcí za období s emoji a u každého příspěvku emoji s počtem reakcí.
 * Demografie (Facebook, Instagram, Meta Ads, LinkedIn) má věk, pohlaví, země a města v samostatných
   blocích.
 * Na mobilu se navigace otevírá tlačítkem v horní liště a široké tabulky se posouvají uvnitř karty.
 * Styly jsou v nových souborech. Dosavadní assets/css/app.css a admin.css aktualizace odstraní; pokud
   v nich byly vaše úpravy, soubor zůstane a aktualizace na to upozorní, nová verze ho ale nenačítá.


Změny ve verzi 1.4.0
--------------------
 * Nový zdroj Microsoft Ads: denní výkon účtu a kampaní (zobrazení, kliknutí, útrata, konverze,
   hodnota konverzí) přes Reporting API, jen ke čtení. Přihlášení Google účtem nebo účtem Microsoftu
   (Microsoft Entra, i s vlastním tenantem). Data jsou v přehledu, v Růstu kanálů, v reportech pro AI
   i v připojení AI. Připravený report si další synchronizace vyzvedne, i když ho Microsoft
   generuje déle. Funkci přispěl proseo (pull request #1), děkujeme.
 * Odkazy Nápověda vedou uživatele s anglickým rozhraním na anglický návod
   (https://ffscz.github.io/AllStat/en/). Doplněné překlady v instalačním průvodci a bočním panelu.
 * Cron: napojení se označí jako zpracované už před synchronizací, takže pád jednoho napojení
   (například došlá paměť) nezablokuje další běhy pro ostatní.
 * Bezpečnost: složka tests se do balíčku nedává a v .htaccess je z webu zakázaná.


Změny ve verzi 1.3.0
--------------------
 * Google účty: jeden nebo víc Google účtů pro celou instalaci (menu Google účty). Z účtu se načte
   seznam GA4 property a webů ze Search Console a vybrané weby se připojí najednou, bez opisování
   Property ID a Client ID. Stávající napojení fungují dál a jde je na účet přepnout.
 * Všechny weby: nová stránka se souhrnem návštěv, klíčových událostí, tržeb a kliknutí z Googlu
   u všech webů za zvolené období, se srovnáním s předchozím obdobím, filtrem podle Google účtu
   a hledáním.
 * Jazyk rozhraní: čeština, slovenština a angličtina. Každý uživatel si jazyk vybere v horní liště,
   výchozí jazyk instalace je v Nastavení a volí se i v instalačním průvodci. Data pro AI,
   datový feed a exporty zůstávají česky.
 * Připojení AI (MCP): nový nástroj get_websites_summary (souhrn všech webů jedním dotazem) a filtr
   podle Google účtu v list_websites, například „weby v účtu CITADELA“.
 * Rotace šifrovacího klíče nově přešifruje i Meta App Secret a tokeny Google účtů. Dřív Meta App
   Secret po rotaci nešel přečíst.


Změny ve verzi 1.2.10
---------------------
 * Bezpečnost: opravena chyba, kvůli které šlo dvoufázové ověření (2FA) za určitých podmínek obejít,
   a to i vynucené. Doporučujeme aktualizovat co nejdřív. Adresy s cestou za názvem skriptu
   (například .../users.php/cokoli) teď vracejí chybu 404.
 * Bezpečnost: po 5 chybných kódech 2FA během 15 minut se ověření účtu na 15 minut zablokuje
   a přihlášení se zruší.
 * Bezpečnost: instalační průvodce chce instalační kód ze souboru install-token.php, který vytvoří
   ve složce AllStatu. Rozpracovanou instalaci tak nedokončí nikdo bez přístupu k souborům na serveru.
 * Bezpečnost: přístupové tokeny služeb se posílají jen na ověřené adresy poskytovatelů, a to při
   každé synchronizaci. Zdroj dat u existujícího napojení už nejde přepnout na jiný.


Změny ve verzi 1.2.9
--------------------
 * Aktualizace: nový kód se použije hned po výměně souborů, i když hosting drží PHP v mezipaměti
   (OPcache). Dřív mohla stránka hned po aktualizaci ještě pár sekund běžet ve staré verzi.
 * Doplnění pravidel do upraveného .htaccess po aktualizaci ze starší verze se spustí na kterékoli
   stránce administrátora (přehled, administrace), ne jen na stránce Aktualizace.


Změny ve verzi 1.2.8
--------------------
 * Soubor .htaccess: pravidla AllStatu jsou v bloku mezi řádky # BEGIN AllStat a # END AllStat.
   Aktualizace přepisuje jen tento blok a když v souboru chybí, doplní ho. Vlastní pravidla hostingu
   nebo správce mimo blok zůstanou. Po změně AllStat ověří, že web odpovídá, a když ne, změnu sám
   vrátí. Ruční doplňování pravidel do .htaccess už není potřeba.
 * Při aktualizaci z 1.2.7 a starší s upraveným .htaccess doplní pravidla stránka Aktualizace hned
   po aktualizaci (se stejnou kontrolou).


Změny ve verzi 1.2.7
--------------------
 * Aktualizace a soubor .htaccess: upravený .htaccess (vlastní pravidla, blokování robotů, nastavení PHP
   od hostingu) zůstává. Když ho nová verze nemění, aktualizace už nic nehlásí a nevytváří .htaccess.new.
   Když ho mění, stránka Aktualizace ukáže, která pravidla ve vašem souboru chybí a k čemu slouží
   (návod na webu, kapitola 8, Hláška o souboru .htaccess).
 * Upozornění po aktualizaci jsou žlutá, ne červená jako chyba.
 * Při aktualizaci z 1.2.6 se u upraveného .htaccess ukáže ještě naposledy stará hláška „porovnejte je“.
   Stránka Aktualizace pak sama ukáže, jestli je co doplnit, a zbytečný .htaccess.new smaže.


Změny ve verzi 1.2.6
--------------------
 * Nápověda: otazník v záhlaví každé stránky otevře kapitolu návodu, která k ní patří. Formulář
   napojení služby a nastavení cronu odkazují přímo na postup, odkazy na návod má i instalátor.
 * Návod je nově na webu https://ffscz.github.io/AllStat/ (vždy pro nejnovější verzi, jde vytisknout
   nebo uložit jako PDF). Balíček už neobsahuje složku docs ani PDF příručku, starou složku docs
   můžete ze serveru smazat.


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
 * Z verze 1.1.0 a starší je potřeba na 1.2.0 aktualizovat ještě ručně (návod na webu, kapitola 8),
   další verze už půjdou tlačítkem.


Změny ve verzi 1.1.0
--------------------
 * Trychtýře: konverzní cesty z GA4 eventů (například návštěva, spuštění kalkulačky, dokončení,
   klik na CTA, poptávka). Nastavení v menu Trychtýře (šablony, přetahování kroků, kontrola měření),
   zobrazení v přehledu s úbytkem mezi kroky, vývojem v čase a rozpadem podle kanálu, zdroje nebo kampaně.
 * Připojení AI: nový nástroj get_funnel, asistent umí trychtýře číst a hledat, kde se lidé ztrácejí.
 * Příručka: kapitola o trychtýřích a o přípravě měření v Google Tag Manageru a GA4.
 * Aktualizace z verze 1.0.x: postup je v návodu na webu, kapitola 8. Databáze se doplní sama.


Změny ve verzi 1.0.2
--------------------
 * Přístup k webům podle uživatele: u účtu s rolí Uživatel jde zvolit „Jen vybrané weby“, ostatní
   weby neuvidí v přehledu, reportech ani sdílených odkazech. Výchozí je „Všechny weby“.
 * Správa (weby, zdroje, metriky, logy, feed, importy) je jen pro administrátora, i pro čtení.
 * Dávkový režim cronu pro desítky webů: limit napojení nebo sekund na jedno spuštění (Nastavení).
 * Výběr webu má od 8 webů pole pro hledání.
 * Aktualizace z verze 1.0.0 nebo 1.0.1: postup je v návodu na webu, kapitola 8.


Změny ve verzi 1.0.1
--------------------
 * Google Ads: napojení přešlo na aktuální verzi Google Ads API (v25). Developer token už není
   potřeba, přístup určuje projekt v Google Cloud (viz návod na webu, část Google Ads).
 * Meta Ads: souhrny za celou dobu kampaně, sestavy a reklamy, skutečná frekvence a cíl kampaně.
 * Aktualizace z verze 1.0.0: postup je v návodu na webu, kapitola 8. config.php
   a config-keys.php zůstanou beze změny, databáze se upraví sama při prvním otevření.
