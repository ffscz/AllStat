<?php

function allstat_default_providers(): array
{
    return [
        [
            'provider_key' => 'ga4',
            'name' => 'Google Analytics 4',
            'category' => 'analytics',
            'supports_oauth' => 1,
            'default_scopes' => 'https://www.googleapis.com/auth/analytics.readonly',
            'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'api_base_url' => 'https://analyticsdata.googleapis.com',
            'docs_url' => 'https://developers.google.com/analytics/devguides/reporting/data/v1',
        ],
        [
            'provider_key' => 'gsc',
            'name' => 'Google Search Console',
            'category' => 'seo',
            'supports_oauth' => 1,
            'default_scopes' => 'https://www.googleapis.com/auth/webmasters.readonly',
            'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'api_base_url' => 'https://searchconsole.googleapis.com',
            'docs_url' => 'https://developers.google.com/webmaster-tools/v1/how-tos/authorizing',
        ],
        [
            'provider_key' => 'clarity',
            'name' => 'Microsoft Clarity',
            'category' => 'analytics',
            'supports_oauth' => 0,
            'default_scopes' => '',
            'auth_url' => '',
            'token_url' => '',
            'api_base_url' => 'https://www.clarity.ms/export-data/api',
            'docs_url' => 'https://learn.microsoft.com/clarity/setup-and-installation/clarity-api',
        ],
        [
            'provider_key' => 'seznam_wmt',
            'name' => 'Seznam Webmaster',
            'category' => 'seo',
            'supports_oauth' => 0,
            'default_scopes' => '',
            'auth_url' => '',
            'token_url' => '',
            'api_base_url' => 'https://reporter.seznam.cz/wm-api',
            'docs_url' => 'https://blog.seznam.cz/2017/04/seznam-webmaster-novy-komunikacnim-nastroj-mezi-vyhledavacem-a-webmasterem/',
        ],
        [
            'provider_key' => 'facebook_pages',
            'name' => 'Facebook Pages',
            'category' => 'social',
            'supports_oauth' => 1,
            'default_scopes' => 'pages_read_engagement,pages_show_list,read_insights',
            'auth_url' => 'https://www.facebook.com/v25.0/dialog/oauth',
            'token_url' => 'https://graph.facebook.com/v25.0/oauth/access_token',
            'api_base_url' => 'https://graph.facebook.com/v25.0',
            'docs_url' => 'https://developers.facebook.com/docs/pages-api',
        ],
        [
            'provider_key' => 'instagram_business',
            'name' => 'Instagram Business',
            'category' => 'social',
            'supports_oauth' => 1,
            'default_scopes' => 'instagram_basic,instagram_manage_insights,pages_show_list,pages_read_engagement',
            'auth_url' => 'https://www.facebook.com/v25.0/dialog/oauth',
            'token_url' => 'https://graph.facebook.com/v25.0/oauth/access_token',
            'api_base_url' => 'https://graph.facebook.com/v25.0',
            'docs_url' => 'https://developers.facebook.com/docs/instagram-platform',
        ],
        [
            'provider_key' => 'linkedin_company',
            'name' => 'LinkedIn Company',
            'category' => 'social',
            'supports_oauth' => 1,
            // w_member_social patří k produktu "Share on LinkedIn" (publikování), AllStat ho nepoužívá
            // a bez schváleného produktu LinkedIn kvůli němu odmítne CELÝ OAuth (unauthorized_scope_error).
            'default_scopes' => 'r_organization_social,rw_organization_admin',
            'auth_url' => 'https://www.linkedin.com/oauth/v2/authorization',
            'token_url' => 'https://www.linkedin.com/oauth/v2/accessToken',
            'api_base_url' => 'https://api.linkedin.com/v2',
            'docs_url' => 'https://learn.microsoft.com/linkedin/marketing/',
        ],
        [
            'provider_key' => 'google_ads',
            'name' => 'Google Ads',
            'category' => 'ppc',
            'supports_oauth' => 1,
            'default_scopes' => 'https://www.googleapis.com/auth/adwords',
            'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'api_base_url' => 'https://googleads.googleapis.com',
            'docs_url' => 'https://developers.google.com/google-ads/api/docs/oauth/overview',
        ],
        [
            'provider_key' => 'meta_ads',
            'name' => 'Meta Ads',
            'category' => 'ppc',
            'supports_oauth' => 1,
            'default_scopes' => 'ads_read,read_insights,business_management',
            'auth_url' => 'https://www.facebook.com/v25.0/dialog/oauth',
            'token_url' => 'https://graph.facebook.com/v25.0/oauth/access_token',
            'api_base_url' => 'https://graph.facebook.com/v25.0',
            'docs_url' => 'https://developers.facebook.com/docs/marketing-api',
        ],
        [
            // YouTube kanál: YouTube Data API v3 (stav kanálu, seznam videí, veřejné statistiky videí)
            // + YouTube Analytics API v2 (denní zhlédnutí, sledovaný čas, odběratelé, zdroje návštěvnosti,
            // demografie, per-video sledovaný čas). Stejný Google OAuth klient jako GA4/GSC.
            'provider_key' => 'youtube',
            'name' => 'YouTube',
            'category' => 'social',
            'supports_oauth' => 1,
            'default_scopes' => 'https://www.googleapis.com/auth/youtube.readonly https://www.googleapis.com/auth/yt-analytics.readonly',
            'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'api_base_url' => 'https://youtubeanalytics.googleapis.com',
            'docs_url' => 'https://developers.google.com/youtube/analytics',
        ],
    ];
}

function allstat_provider_setup_guide(string $providerKey): array
{
    return match ($providerKey) {
        'ga4' => [
            'console_url' => 'https://console.cloud.google.com/',
            'console_label' => 'Google Cloud Console',
            'enable_apis' => ['Google Analytics Data API'],
            'property_id_format' => 'properties/123456789 (samotné číslo služby, prefix appka doplní)',
            'property_id_where' => 'GA4 (analytics.google.com) → „Administrátor" (ozubené kolo vlevo dole) → „Nastavení služby" → úplně nahoře „ID služby" (číslo). POZOR: NEPLEŤ si s „ID měření" (G-XXXX) ani s „ID streamu".',
            'pricing_note' => 'Zdarma do 200k tokenů na službu za den a 50k requestů na projekt za den.',
            'token_note' => 'Konzole Google i přihlašování jsou česky. V návodu je u každého tlačítka i původní anglický název v závorce, kdyby ti Google ukázal anglické popisky. Po publikaci aplikace (krok 4) se při PRVNÍM připojení každého účtu jednou objeví obrazovka „Google tuto aplikaci neověřil". Je to normální (aplikace používá citlivé oprávnění a není verifikovaná Googlem) a bezpečné, protože je to vaše vlastní aplikace: klikni „Rozšířené" (Advanced) a „Přejít na (název vaší aplikace)". Varování jde později sundat verifikací v „Centru ověření", potřebuje zásady ochrany soukromí a ověřenou doménu a trvá řádově dny.',
            'steps' => [
                'V Google Cloud Console (console.cloud.google.com) nahoře v liště vytvoř nebo vyber projekt.',
                '„Rozhraní API a služby" (APIs & Services) → „Knihovna" (Library) → najdi a povol „Google Analytics Data API".',
                'Otevři „Přehled Google Auth" / „Google Auth Platform" (dřívější „OAuth consent screen"). Když ji zakládáš poprvé, vyplň název aplikace a kontaktní e-mail, typ uživatele nech „Externí" (External).',
                'V „Google Auth Platform" → „Publikum" (Audience) přepni „Stav publikování" na produkci tlačítkem „Publish app" a „Confirm". BEZ TOHO je aplikace v režimu „Testing", kde se přihlásí jen ručně přidaní „Testovací uživatelé" (jinak Google vrátí access_denied), a hlavně přihlašovací tokeny vyprší po 7 dnech, takže se napojení každý týden rozpadne.',
                '„Klienti" (Clients) → „Vytvořit klienta" (Create client), typ „Webová aplikace" (Web application). Do „Autorizované identifikátory URI přesměrování" (Authorized redirect URIs) vlož PŘESNĚ callback URL z rámečku nad tímto návodem (včetně https:// a domény).',
                'Zkopíruj „ID klienta" (Client ID) a „Tajný klíč klienta" (Client secret) do formuláře.',
                'V GA4 (analytics.google.com) → „Administrátor" → „Nastavení služby" → „ID služby" (samotné číslo, prefix „properties/" appka doplní sama).',
                'V GA4 → „Administrátor" → „Správa přístupu ke službě" ověř, že Google účet, kterým budeš dělat OAuth, má u té služby roli aspoň „Zobrazovatel" (Viewer). Bez toho se sice připojíš, ale nenačtou se žádná data.',
                'Ulož napojení a klikni „Spustit OAuth". Projdi přihlášení Google (jednou uvidíš „neověřená aplikace", viz poznámka nahoře: „Rozšířené" a „Přejít na (název vaší aplikace)"), potvrď přístup, tokeny se uloží.',
                'Klikni „Otestovat napojení", appka zavolá GA Data API a řekne, jestli vše sedí.',
            ],
            'troubleshooting' => [
                '„Přístup zablokován" / „Chyba 403: access_denied": aplikace je v režimu „Testing" a tvůj účet není mezi testery. Řešení: „Google Auth Platform" → „Publikum" → „Publish app" (doporučeno, tokeny pak nevyprší), nebo přidej účet do „Testovací uživatelé" (pozor, v testovacím režimu tokeny vyprší po 7 dnech a napojení se rozpadne).',
                'Připojení projde, ale nenačtou se žádná data: účet, kterým jsi autorizoval, nemá přístup k té GA4 službě. Přidej ho v GA4 → „Administrátor" → „Správa přístupu ke službě" jako „Zobrazovatel".',
                '„ID služby" musí být číslo (Property ID), ne „ID měření" (G-XXXX) ani „ID streamu".',
            ],
        ],
        'youtube' => [
            'console_url' => 'https://console.cloud.google.com/',
            'console_label' => 'Google Cloud Console',
            'enable_apis' => ['YouTube Data API v3', 'YouTube Analytics API'],
            'property_id_format' => 'UCxxxxxxxxxxxxxxxxxxxxxx (ID kanálu, volitelné)',
            'property_id_where' => 'YouTube Studio (studio.youtube.com) → „Nastavení" → „Kanál" → „Rozšířená nastavení" → „ID kanálu". Když pole necháš prázdné, použije se kanál účtu, kterým projdeš OAuth.',
            'pricing_note' => 'Zdarma. YouTube Data API má kvótu 10 000 jednotek za den, jedno stažení dat spotřebuje řádově desítky jednotek.',
            'token_note' => 'Používá se stejný OAuth klient jako u GA4 / Search Console (tlačítka „Převzít údaje z …"). POZOR, když má AllStat Google napojení ve DVOU projektech, ukáže se tlačítek víc a každé = jiný Google projekt: projekt typu „Interní" (Workspace) pustí jen účty té organizace (např. @vase-firma.cz), jiný účet dostane „Chyba 403: org_internal". Pro kanál spravovaný z Gmailu proto převezmi údaje z napojení, které jede přes externí publikovaný projekt (poznáš podle Client ID v bublině tlačítka), nebo se přihlas Workspace účtem, který je správcem kanálu. V TOM projektu, jehož klient se použije, musí být povolená YouTube Data API v3 a YouTube Analytics API. Přihlašuj se účtem, který je VLASTNÍK nebo SPRÁVCE kanálu; kanál na účtu značky (Brand Account): vyber při přihlášení účet s názvem kanálu, ne osobní. Při prvním připojení se jednou ukáže „Google tuto aplikaci neověřil": „Rozšířené" (Advanced) a „Přejít na (název vaší aplikace)".',
            'steps' => [
                'V Google Cloud Console (console.cloud.google.com) vyber projekt, jehož OAuth klienta pro YouTube použiješ (viz poznámka nahoře: u účtu Gmail ten externí publikovaný, ne interní Workspace).',
                '„Rozhraní API a služby" (APIs & Services) → „Knihovna" (Library) → najdi a povol „YouTube Data API v3" a potom stejně „YouTube Analytics API". Obě musí být zapnuté, jinak sync skončí chybou 403 „accessNotConfigured".',
                '„Google Auth Platform" → „Přístup k datům" (Data access) → „Přidat nebo odebrat rozsahy" (Add or remove scopes): přidej „…/auth/youtube.readonly" a „…/auth/yt-analytics.readonly" a ulož. (Jen kosmetika pro obrazovku souhlasu, rozsahy si appka žádá sama.)',
                'V AllStatu do formuláře vlož Client ID a Client secret (tlačítko „Převzít údaje" je zkopíruje z existujícího Google napojení) a ověř, že v OAuth klientovi je i callback URL z rámečku nad návodem.',
                'ID kanálu můžeš nechat prázdné. Vyplň ho jen tehdy, když má Google účet víc kanálů (YouTube Studio → Nastavení → Kanál → Rozšířená nastavení → ID kanálu).',
                'Ulož napojení a klikni „Spustit OAuth". Přihlas se Google účtem, který kanál vlastní nebo spravuje (u účtu značky vyber při přihlášení účet s názvem kanálu), potvrď obě oprávnění, tokeny se uloží.',
                'Klikni „Otestovat napojení", appka načte název kanálu a počet odběratelů. Pak „Stáhnout historii" (YouTube Analytics dává data zpětně za celou historii kanálu, AllStat bere 16 měsíců) a případně „Stáhnout aktuální data".',
            ],
            'troubleshooting' => [
                '„Přístup zablokován: aplikaci lze používat pouze v rámci organizace" / „Chyba 403: org_internal": převzatý Client ID patří internímu (Workspace) projektu a přihlašuješ se účtem mimo tu organizaci (např. Gmail). Řešení: „Převzít údaje z …" znovu, ale z napojení v externím projektu (jiné Client ID), a Spustit OAuth znovu; nebo se přihlas Workspace účtem (@vase-firma.cz), který je správcem kanálu.',
                '403 „accessNotConfigured" / „YouTube Data API v3 has not been used in project": v Cloud Console není povolené jedno z API (YouTube Data API v3, YouTube Analytics API) v projektu, jehož klienta používáš. Povol a zkus sync znovu.',
                'Test projde, ale ukáže cizí (osobní) kanál s 0 odběrateli: OAuth proběhl osobním účtem místo účtu značky. Spusť OAuth znovu a na obrazovce výběru účtu vyber účet s názvem kanálu, nebo vyplň ID kanálu.',
                '403 „forbidden" u YouTube Analytics: účet, kterým jsi autorizoval, není vlastník ani správce kanálu (Analytics vyžaduje oprávnění ke kanálu, veřejné statistiky nestačí). Přidej ho v YouTube Studio → Nastavení → Oprávnění.',
                'Data za poslední 2-3 dny jsou nižší: YouTube Analytics se doplňuje se zpožděním, denní sync poslední dny přepisuje.',
            ],
        ],
        'gsc' => [
            'console_url' => 'https://console.cloud.google.com/',
            'console_label' => 'Google Cloud Console',
            'enable_apis' => ['Google Search Console API'],
            'property_id_format' => 'sc-domain:example.com  nebo  https://example.com/',
            'property_id_where' => 'Search Console → „Nastavení" (Settings); přesnou adresu property zkopíruj jako Property ID',
            'pricing_note' => 'Zdarma; limit cca 1 200 requestů/min na projekt.',
            'steps' => [
                'Stejný projekt jako GA4 (nebo nový) v Google Cloud Console.',
                '„Rozhraní API a služby" (APIs & Services) → „Knihovna" (Library) → povol „Google Search Console API".',
                'Publikaci aplikace (a případné testery) řeš stejně jako u GA4: „Google Auth Platform" → „Publikum" → „Publish app".',
                'Použij stejné OAuth údaje (Client ID a Client secret) jako pro GA4, nebo vytvoř nového klienta v „Klienti" se stejným redirect URI z patičky.',
                'V Search Console ověř, že tvůj Google účet má u dané property přístup.',
                'Property ID = přesná adresa property („sc-domain:..." pro doménovou property, „https://..." pro URL prefix).',
                'Ulož a spusť OAuth (stejné varování „neověřená aplikace" jako u GA4: „Rozšířené" a „Přejít na (název vaší aplikace)").',
            ],
        ],
        'clarity' => [
            'console_url' => 'https://clarity.microsoft.com/',
            'console_label' => 'Microsoft Clarity',
            'enable_apis' => [],
            'property_id_format' => 'project-id (alfanumerický řetězec z URL projektu)',
            'property_id_where' => 'Clarity → Settings → Setup → Project ID',
            'pricing_note' => 'Clarity API je zdarma, ale má limit 10 requestů/den/project (zatím beta).',
            'steps' => [
                'V Clarity otevři projekt → Settings → Data Export → Generate API token.',
                'Vlož token do pole Access token (Client ID/Secret jsou prázdné, Clarity nepoužívá OAuth).',
                'Project ID vlož do Property ID.',
                'OAuth tlačítko se nezobrazí, sync běží přes API token přímo.',
            ],
        ],
        'seznam_wmt' => [
            'console_url' => 'https://reporter.seznam.cz/wm/',
            'console_label' => 'Seznam Webmaster (reporter.seznam.cz)',
            'enable_apis' => [],
            'property_id_format' => 'URL webu (např. https://www.example.cz), volitelné, jen pro přehled',
            'property_id_where' => 'Klíč je vázaný na konkrétní web přidaný v reporter.seznam.cz',
            'pricing_note' => 'API je zdarma. Limity: 5 dotazů/s, 100 dotazů/min na web. Vrací stav INDEXACE (kolik stránek je zaindexovaných / s chybou), ne kliknutí/dotazy.',
            'steps' => [
                'Přihlas se na reporter.seznam.cz a otevři svůj web (musí být ověřený).',
                'V nastavení webu najdi „Klíč k API" a vygeneruj/zkopíruj 40znakový klíč.',
                'Klíč vlož do pole API klíč. Client ID/Secret nech prázdné, Seznam nepoužívá OAuth.',
                'Do pole Web (URL) můžeš dát adresu webu (nepovinné). Ulož a spusť „Stáhnout historii", jedním voláním se natáhne celá historie indexace.',
            ],
        ],
        'facebook_pages', 'instagram_business', 'meta_ads' => [
            'console_url' => 'https://developers.facebook.com/apps/',
            'console_label' => 'Meta for Developers',
            'enable_apis' => $providerKey === 'meta_ads'
                ? ['Marketing API']
                : ($providerKey === 'instagram_business' ? ['Instagram → „API setup with Facebook login" (NE „Instagram login")', 'Facebook Login for Business'] : ['Pages API', 'Facebook Login for Business']),
            'property_id_format' => $providerKey === 'meta_ads' ? 'act_123456789' : ($providerKey === 'instagram_business' ? '17841400000000000 (IG Business ID, appka ho najde sama přes propojenou stránku)' : 'Page ID (číselné)'),
            'property_id_where' => $providerKey === 'meta_ads'
                ? 'Ads Manager → Business Settings → Accounts → Ad accounts'
                : ($providerKey === 'instagram_business' ? 'Nemusíš hledat ručně, použij „Připojit Meta" (jedno přihlášení), appka IG najde přes FB stránku, ke které je propojený.' : 'Facebook Page → About → Page ID'),
            'pricing_note' => 'Graph API je zdarma; rate-limit per app/user. V Development módu funguje pro tvoje vlastní účty bez App Review (ten je až pro cizí účty v ostrém provozu).'
                . ($providerKey === 'instagram_business' ? ' INSTAGRAM: účet musí být Business/Creator a PROPOJENÝ s FB stránkou, kterou spravuješ; scopes musí obsahovat instagram_basic + instagram_manage_insights, jinak se IG vůbec nenabídne.' : ''),
            'token_note' => $providerKey === 'instagram_business'
                ? 'Nejjednodušší cesta je „Připojit Meta" (bulk), jedno přihlášení připojí FB stránky i IG účty, nemusíš nic vyplňovat ručně. V Meta App použij produkt Instagram → „API setup with Facebook login".'
                : '',
            'steps' => [
                'developers.facebook.com → Create App → typ Business.',
                'Add Product → „Facebook Login for Business" → Settings → vlož callback URL z patičky do Valid OAuth Redirect URIs.',
                $providerKey === 'instagram_business'
                    ? 'Add Product → „Instagram" → otevři „API setup with Facebook login" (NE „Instagram login"). Tím se zpřístupní oprávnění instagram_basic + instagram_manage_insights.'
                    : 'Přidej příslušný produkt (Pages API / Marketing API).',
                'Settings → Basic → zkopíruj App ID (= Client ID) a App secret (= Client secret).',
                $providerKey === 'instagram_business'
                    ? 'Ověř, že IG je Business/Creator a propojený s FB stránkou (Meta Business Suite). Pak použij „Připojit Meta", Property ID (IG ID) appka doplní sama.'
                    : 'Property ID vyplň podle formátu výše.',
                'V dev módu funguje jen pro role admin/developer; pro ostrý běh projdi App Review na vyžadované scopes.',
                'Ulož a spusť OAuth (nebo rovnou „Připojit Meta").',
            ],
        ],
        'linkedin_company' => [
            'console_url' => 'https://www.linkedin.com/developers/apps',
            'console_label' => 'LinkedIn Developers, tady vytvoříš aplikaci',
            'enable_apis' => ['Community Management API (LinkedIn ji musí schválit, řádově dny)'],
            'property_id_format' => 'urn:li:organization:ČÍSLO   (např. urn:li:organization:12345678)',
            'property_id_where' => 'Otevři svou LinkedIn stránku jako správce, v adrese je „.../company/ČÍSLO/" (potřebuješ to ČÍSELNÉ ID, ne název). Když v adrese vidíš jen název (např. /company/nazev-firmy/), číslo najdeš v aplikaci na linkedin.com/developers → tvoje app → záložka „Auth" v sekci propojené organizace. Můžeš sem vložit i jen samotné ČÍSLO, AllStat ho sám doplní na urn:li:organization:ČÍSLO.',
            'pricing_note' => 'API je zdarma. ALE čtení statistik stránky vyžaduje, aby ti LinkedIn schválil produkt „Community Management API" (trvá pár dní). Než schválí: OAuth projde, ale statistiky vrátí chybu 403, to je normální, počkej na schválení.',
            'token_note' => 'Životnost přihlášení: LinkedIn access token platí 60 dní. Automatické obnovování (refresh token s platností 12 měsíců) dostaneš JEN po schválení produktu Community Management API, pak AllStat token obnovuje sám a ručně se přihlašuješ až ~1× ročně. Bez schváleného produktu refresh token nepřijde a OAuth musíš opakovat ručně každých ~60 dní.',
            'field_map' => [
                'Web' => 'Vyber web, pod který tohle LinkedIn napojení patří.',
                'Služba' => 'Vyber „LinkedIn Company".',
                'Property / Site / Customer ID' => 'HLAVNÍ pole pro LinkedIn, vlož ID organizace ve tvaru urn:li:organization:ČÍSLO.',
                'Client ID' => 'Zkopíruj z LinkedIn app → záložka „Auth" → „Client ID".',
                'Client secret' => 'Zkopíruj z LinkedIn app → záložka „Auth" → „Primary Client Secret".',
                'OAuth scopes (jen smaž w_member_social)' => 'AllStat předvyplní r_organization_social, rw_organization_admin. Pokud ve scopes uvidíš i w_member_social, SMAŽ ho, patří k produktu „Share on LinkedIn" (publikování), AllStat ho nepoužívá a bez toho produktu LinkedIn celý OAuth odmítne (unauthorized_scope_error).',
                'Authorization URL / Token URL / API base URL' => 'Nech být, appka je předvyplní sama.',
                'Externí account ID' => 'Nech prázdné, LinkedIn ho nepoužívá (ID organizace patří do „Property / Site / Customer ID").',
            ],
            'steps' => [
                '1) Na linkedin.com/developers dej „Create app", vyplň název a PŘIŘAĎ app ke své LinkedIn Company Page (povinné). Ulož.',
                '1b) OVĚŘ appku se stránkou: Settings → u propojené stránky „Verify" → vygenerovaný ověřovací odkaz musí otevřít a schválit SPRÁVCE (Super admin) té LinkedIn stránky. Když jsi správce ty, otevři odkaz sám a potvrď. Bez tohoto ověření ti LinkedIn neschválí Community Management API. Ověření je nevratné.',
                '2) V appce otevři záložku „Auth". Najdeš tam „Client ID" a „Primary Client Secret", ty za chvíli zkopíruješ sem do formuláře.',
                '3) Pořád v „Auth" → „OAuth 2.0 settings" → „Authorized redirect URLs" → „Add redirect URL" → vlož přesně callback URL z rámečku nad tímto návodem (vč. https:// a celé domény). Ulož.',
                '4) Záložka „Products" → u „Community Management API" dej „Request access" a vyplň formulář. LinkedIn schvaluje řádově dny, do té doby statistiky nepůjdou (OAuth ale projde). Teprve tento produkt dá scope na čtení statistik (r_organization_social) a zapne refresh token, aby AllStat token obnovoval sám (jinak musíš re-auth ručně po 60 dnech).',
                '5) Zjisti ČÍSELNÉ ID své stránky (viz „Kde najít Property ID" výše) a vlož ho do pole „Property / Site / Customer ID" jako urn:li:organization:ČÍSLO.',
                '6) Zkopíruj „Client ID" a „Primary Client Secret" z kroku 2 do polí „Client ID" a „Client secret" tady ve formuláři.',
                '7) Před spuštěním OAuth zkontroluj pole „OAuth scopes": nech jen r_organization_social, rw_organization_admin. Kdyby tam bylo i w_member_social, smaž ho, jinak LinkedIn OAuth odmítne (unauthorized_scope_error). Pak „Uložit napojení" a „Spustit OAuth" → přihlas se na LinkedIn a potvrď přístup. Tokeny se uloží samy.',
                '8) Dej „Otestovat napojení". Test teď kontroluje přímo statistiky organizace (ne profil), takže když projde, půjde i stahování dat. Dokud LinkedIn neschválí produkt Community Management API, uvidíš 403, to je v pořádku, po schválení test zopakuj.',
            ],
            'troubleshooting' => [
                '403 „ACCESS_DENIED … me.GET.NO_VERSION": tuhle hlášku vracel starší test napojení, volal profilový endpoint /v2/me, na který firemní (organization) token nemá oprávnění. Test už ho nepoužívá (kontroluje rovnou statistiky organizace), takže by se tahle konkrétní hláška neměla vracet. Pokud přesto vidíš 403, jde o některý z bodů níže.',
                '403 u statistik organizace: nejčastěji LinkedIn ještě neschválil produkt „Community Management API" (po žádosti řádově dny, do schválení je 403 normální, počkej). Další příčiny: nejsi správce (admin) té LinkedIn stránky; nebo OAuth proběhl bez scope r_organization_social, zkontroluj pole „OAuth scopes" a spusť OAuth znovu.',
                '400 / „Invalid organizationalEntity": špatné Property ID. Musí být přesně urn:li:organization:ČÍSLO (číselné ID stránky, ne název).',
                '401 / vypršelé přihlášení: token platí 60 dní a bez schváleného produktu se sám neobnovuje. Dej „Spustit OAuth" znovu.',
                'unauthorized_scope_error při OAuth: v poli „OAuth scopes" nech jen r_organization_social, rw_organization_admin a smaž w_member_social.',
            ],
        ],
        'google_ads' => [
            'console_url' => 'https://console.cloud.google.com/',
            'console_label' => 'Google Cloud Console',
            'enable_apis' => ['Google Ads API'],
            'property_id_format' => '1234567890 (Customer ID bez pomlček)',
            'property_id_where' => 'Google Ads → vpravo nahoře vedle účtu',
            'pricing_note' => 'Zdarma. Developer token už není potřeba (Google ho zrušil 9. 9. 2026), přístup teď určuje Google Cloud projekt OAuth klienta: nový projekt má úroveň Test (jen testovací účty), na skutečný účet je potřeba Explorer (2 880 operací/den) nebo Basic (15 000/den).',
            'steps' => [
                'Google Cloud Console → povol "Google Ads API", vytvoř OAuth client (Web), redirect URI z patičky.',
                'Google Cloud Console → Google Ads API → Overview → „Upgrade access level" → požádej o Explorer. Bez něj projekt smí jen na testovací účty, schválení trvá obvykle do 10 pracovních dnů.',
                'Client ID + Client Secret vlož do formuláře.',
                'Customer ID (bez pomlček) vlož do Property ID. Když účet spravuješ přes manažerský (MCC) účet, vyplň i MCC / Login customer ID.',
                'Ulož a spusť OAuth.',
            ],
        ],
        default => [],
    };
}

/**
 * Per-provider connection form: only the fields a given provider actually needs, with
 * provider-specific labels and short inline help. Everything else (endpoints, scopes, raw
 * tokens, status…) lives in the collapsed "Pokročilé nastavení" of source-edit.php.
 *
 * Field spec keys:
 *   name        POST/column name (or virtual name for config fields)
 *   label       visible label
 *   type        text | password
 *   placeholder optional placeholder
 *   help        short hint under the input
 *   required    informational only (rendered as "· nutné"; not a hard HTML required — keeps
 *               partial saves / "Převzít údaje" flow working)
 *   secret      name of the *_enc column to check → shows "uloženo" placeholder, value stays blank
 *   config      stores this field into config_json[<key>] instead of a column
 *   secret_config  config field that is a secret (blank on edit = keep existing)
 *
 * connect: 'oauth' (Client ID/Secret + Spustit OAuth), 'token' (API klíč, bez OAuth) nebo
 *          'meta' (připojuje se přes "Připojit přes Meta").
 */
function allstat_provider_form_schema(string $providerKey): array
{
    $clientId = [
        'name' => 'client_id', 'label' => 'Client ID', 'type' => 'text',
        'help' => 'Z OAuth aplikace providera (viz „Podrobný návod" níže).', 'required' => true,
    ];
    $googleClientId = [
        'name' => 'client_id', 'label' => 'Client ID (Google OAuth)', 'type' => 'text',
        'help' => 'Google Cloud Console → Credentials → OAuth client. Stejný pro GA4/GSC/Ads, u dalšího Google napojení použij tlačítko „Převzít údaje".', 'required' => true,
    ];
    $clientSecret = [
        'name' => 'client_secret', 'label' => 'Client secret', 'type' => 'password',
        'secret' => 'client_secret_enc', 'help' => 'Ukládá se šifrovaně. Při úpravě nech prázdné pro zachování.', 'required' => true,
    ];

    return match ($providerKey) {
        'ga4' => [
            'connect' => 'oauth',
            'intro' => 'Návštěvnost, zdroje, konverze a chování uživatelů z Google Analytics 4.',
            'fields' => [
                ['name' => 'property_id', 'label' => 'GA4 Property ID', 'type' => 'text', 'placeholder' => '123456789',
                 'help' => 'Jen číslo: GA4 → Admin → Property settings → Property ID. NE Measurement ID (G-…) ani Stream ID. Prefix „properties/" doplní appka.', 'required' => true],
                $googleClientId,
                $clientSecret,
            ],
        ],
        'gsc' => [
            'connect' => 'oauth',
            'intro' => 'Dotazy, prokliky, zobrazení a pozice ve vyhledávání z Google Search Console.',
            'fields' => [
                ['name' => 'property_id', 'label' => 'Search Console property', 'type' => 'text', 'placeholder' => 'sc-domain:example.com',
                 'help' => 'Přesný siteUrl: „sc-domain:example.com" (doménová property) nebo „https://example.com/" (URL-prefix). Zkopíruj ze Search Console → Settings.', 'required' => true],
                $googleClientId,
                $clientSecret,
            ],
        ],
        'google_ads' => [
            'connect' => 'oauth',
            'intro' => 'Útrata, prokliky, konverze a kampaně z Google Ads.',
            'fields' => [
                ['name' => 'property_id', 'label' => 'Customer ID (bez pomlček)', 'type' => 'text', 'placeholder' => '1234567890',
                 'help' => 'Číslo účtu z Google Ads (vpravo nahoře vedle názvu), bez pomlček.', 'required' => true],
                $googleClientId,
                $clientSecret,
                ['name' => 'login_customer_id', 'label' => 'MCC / Login customer ID (volitelné)', 'type' => 'text', 'config' => 'login_customer_id',
                 'help' => 'Jen pokud přistupuješ přes manažerský účet. Bez pomlček.', 'required' => false],
            ],
        ],
        'youtube' => [
            'connect' => 'oauth',
            'intro' => 'Statistiky YouTube kanálu: odběratelé, zhlédnutí, sledovaný čas, zdroje návštěvnosti, demografie a výkon jednotlivých videí.',
            'fields' => [
                ['name' => 'property_id', 'label' => 'ID kanálu', 'type' => 'text', 'placeholder' => 'UCxxxxxxxxxxxxxxxxxxxxxx',
                 'help' => 'Nech prázdné, appka vezme kanál Google účtu, kterým projdeš OAuth (u kanálu na účtu značky vyber při přihlášení ten účet značky). Vyplň jen, když má účet víc kanálů: YouTube Studio → Nastavení → Kanál → Rozšířená nastavení → „ID kanálu" (začíná UC).', 'required' => false],
                $googleClientId,
                $clientSecret,
            ],
        ],
        'clarity' => [
            'connect' => 'token',
            'intro' => 'Návštěvy a chování z Microsoft Clarity. (Heatmapy a nahrávky zůstávají v Clarity.)',
            'fields' => [
                ['name' => 'property_id', 'label' => 'Clarity Project ID', 'type' => 'text', 'placeholder' => 'abcd1234ef',
                 'help' => 'Clarity → Settings → Overview / Setup → Project ID.', 'required' => true],
                ['name' => 'access_token', 'label' => 'API token', 'type' => 'password', 'secret' => 'access_token_enc',
                 'help' => 'Clarity → Settings → Data Export → Generate API token. Ukládá se šifrovaně. Clarity nepoužívá OAuth.', 'required' => true],
            ],
        ],
        'seznam_wmt' => [
            'connect' => 'token',
            'intro' => 'Stav INDEXACE webu ve vyhledávači Seznam.cz (kolik stránek je zaindexovaných, obsahových, s chybou) a jeho vývoj v čase. Pozn.: Seznam Webmaster API nedává návštěvnost/dotazy, jen indexaci.',
            'fields' => [
                ['name' => 'property_id', 'label' => 'Web (URL)', 'type' => 'text', 'placeholder' => 'https://www.example.cz',
                 'help' => 'Web, ke kterému klíč patří, jen pro přehled (klíč je vázaný na konkrétní web).', 'required' => false],
                ['name' => 'access_token', 'label' => 'API klíč', 'type' => 'password', 'secret' => 'access_token_enc',
                 'help' => 'reporter.seznam.cz → tvůj web → Nastavení → „Klíč k API" (40 znaků). Ukládá se šifrovaně. Seznam nepoužívá OAuth.', 'required' => true],
            ],
        ],
        'facebook_pages', 'instagram_business', 'meta_ads' => [
            'connect' => 'meta',
            'intro' => match ($providerKey) {
                'meta_ads' => 'Reklamní výkon z Meta Ads (útrata, ROAS, kampaně, kreativy).',
                'instagram_business' => 'Příspěvky, dosah, zapojení a sledující z Instagram Business účtu.',
                default => 'Příspěvky, dosah, reakce a sledující z Facebook stránky.',
            },
            'fields' => [
                ['name' => 'property_id',
                 'label' => $providerKey === 'meta_ads' ? 'Ad account ID' : ($providerKey === 'instagram_business' ? 'Instagram Business ID' : 'Page ID'),
                 'type' => 'text',
                 'placeholder' => $providerKey === 'meta_ads' ? 'act_123456789' : '123456789',
                 'help' => 'Obvykle vyplní „Připojit přes Meta" automaticky, ručně měnit není potřeba.', 'required' => false],
            ],
        ],
        'linkedin_company' => [
            'connect' => 'oauth',
            'intro' => 'Statistiky firemní LinkedIn stránky. Vyžaduje schválení produktu Community Management API (do schválení vrací 403, to je normální).',
            'fields' => [
                ['name' => 'property_id', 'label' => 'ID organizace (URN)', 'type' => 'text', 'placeholder' => 'urn:li:organization:12345678',
                 'help' => 'Ve tvaru urn:li:organization:ČÍSLO. Číslo najdeš v URL své stránky jako správce (/company/ČÍSLO/) nebo v LinkedIn Developers → tvoje app → Auth.', 'required' => true],
                $clientId,
                $clientSecret,
                ['name' => 'scopes', 'label' => 'OAuth scopes', 'type' => 'text',
                 'help' => 'Nech POUZE: r_organization_social, rw_organization_admin. Kdyby tu bylo w_member_social, SMAŽ ho, jinak LinkedIn OAuth odmítne (unauthorized_scope_error).', 'required' => false],
            ],
        ],
        default => [
            'connect' => 'oauth',
            'intro' => '',
            'fields' => [$clientId, $clientSecret],
        ],
    };
}

/**
 * Merge structured config inputs (currently Google Ads: login_customer_id)
 * into a config_json string, preserving an existing secret value when its field is left blank.
 * Returns the JSON string to store, '' to clear, or NULL when the provider has no structured
 * config (caller then leaves config_json untouched / handled by the raw textarea).
 */
function allstat_compose_config_json(string $providerKey, array $post, ?string $existingJson): ?string
{
    $schema = allstat_provider_form_schema($providerKey);
    $configFields = array_filter($schema['fields'] ?? [], static fn ($f) => !empty($f['config']));

    if (!$configFields) {
        return null;
    }

    $out = [];
    if ($existingJson !== null && $existingJson !== '') {
        $decoded = json_decode($existingJson, true);
        if (is_array($decoded)) {
            $out = $decoded;
        }
    }

    foreach ($configFields as $field) {
        $key = (string) $field['config'];
        $value = trim((string) ($post[$field['name']] ?? ''));

        if ($value !== '') {
            $out[$key] = $value;
        } elseif (empty($field['secret_config'])) {
            unset($out[$key]); // non-secret cleared → remove; secret blank → keep existing
        }
    }

    return $out ? json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
}

function allstat_provider_categories(): array
{
    return [
        'analytics' => 'Analytics',
        'seo' => 'SEO',
        'social' => 'Social',
        'ppc' => 'PPC',
    ];
}

function allstat_provider_allowed_hosts(string $providerKey): array
{
    return match ($providerKey) {
        'ga4', 'gsc', 'google_ads' => [
            'accounts.google.com',
            'oauth2.googleapis.com',
            'analyticsdata.googleapis.com',
            'searchconsole.googleapis.com',
            'googleads.googleapis.com',
        ],
        'youtube' => [
            'accounts.google.com',
            'oauth2.googleapis.com',
            'www.googleapis.com',
            'youtubeanalytics.googleapis.com',
        ],
        'facebook_pages', 'instagram_business', 'meta_ads' => [
            'www.facebook.com',
            'graph.facebook.com',
        ],
        'linkedin_company' => [
            'www.linkedin.com',
            'api.linkedin.com',
        ],
        'clarity' => [
            'www.clarity.ms',
        ],
        'seznam_wmt' => [
            'reporter.seznam.cz',
        ],
        default => [],
    };
}

function allstat_provider_url_allowed(string $providerKey, ?string $url): bool
{
    $url = trim((string) $url);

    if ($url === '') {
        return true;
    }

    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));

    if ($scheme !== 'https' || $host === '') {
        return false;
    }

    return in_array($host, allstat_provider_allowed_hosts($providerKey), true);
}

/**
 * Compact inline SVG brand mark for the dashboard source picker (native <option> can't render SVG,
 * so the picker is a custom listbox). 16×16 rendering of a 24-grid, brand colours, aria-hidden.
 * Key 'overview' = the GA4+GSC overview entry; unknown provider keys get a neutral chart glyph.
 */
function allstat_provider_icon_svg(string $providerKey): string
{
    $svg = static fn (string $body): string => '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">' . $body . '</svg>';

    return match ($providerKey) {
        'overview' => $svg('<path d="M12 3 2.5 8 12 13l9.5-5z" fill="#2563eb"/><path d="m4.5 11.5-2 1L12 17.5l9.5-5-2-1L12 15z" fill="#60a5fa"/><path d="m4.5 16-2 1L12 22l9.5-5-2-1L12 20z" fill="#93c5fd"/>'),
        'ga4' => $svg('<rect x="16.5" y="3" width="5" height="18" rx="2.5" fill="#F9AB00"/><rect x="9.5" y="10" width="5" height="11" rx="2.5" fill="#E37400"/><circle cx="5" cy="18.5" r="2.5" fill="#E37400"/>'),
        'gsc' => $svg('<circle cx="10.5" cy="10.5" r="6" fill="none" stroke="#4285F4" stroke-width="2.6"/><path d="m15 15 5.5 5.5" stroke="#4285F4" stroke-width="2.6" stroke-linecap="round"/>'),
        // Clarity = modré trojúhelníkové „prisma" se třemi fasetami (světlá špička / střední klín /
        // tmavá základna); stroke v barvě výplně + round join = zakulacené rohy jako v originálu.
        'clarity' => $svg('<path d="M12 2.6 21.5 20.6H2.5z" fill="#3078E7" stroke="#3078E7" stroke-width="2.6" stroke-linejoin="round"/><path d="M7.1 11.7 19.2 15.7l2.3 4.9H2.5z" fill="#1B4DC1" stroke="#1B4DC1" stroke-width="2.6" stroke-linejoin="round"/><path d="M12 2.6 16.7 10.9 7.1 11.7z" fill="#9CC3F5" stroke="#9CC3F5" stroke-width="2.6" stroke-linejoin="round"/>'),
        'facebook_pages' => $svg('<path fill="#1877F2" d="M22 12a10 10 0 1 0-11.6 9.9v-7H7.9V12h2.5V9.8c0-2.5 1.5-3.9 3.8-3.9 1.1 0 2.2.2 2.2.2v2.5h-1.3c-1.2 0-1.6.8-1.6 1.6V12h2.8l-.4 2.9h-2.4v7A10 10 0 0 0 22 12z"/>'),
        'instagram_business' => $svg('<rect x="3" y="3" width="18" height="18" rx="5" fill="none" stroke="#E4405F" stroke-width="2.2"/><circle cx="12" cy="12" r="4" fill="none" stroke="#E4405F" stroke-width="2.2"/><circle cx="17.2" cy="6.8" r="1.4" fill="#E4405F"/>'),
        'linkedin_company' => $svg('<rect x="2.5" y="2.5" width="19" height="19" rx="3" fill="#0A66C2"/><rect x="6" y="10" width="2.8" height="8" fill="#fff"/><circle cx="7.4" cy="6.9" r="1.6" fill="#fff"/><path d="M11.5 10h2.7v1.2c.5-.8 1.4-1.4 2.7-1.4 2.1 0 3.1 1.3 3.1 3.6V18h-2.8v-4.1c0-1.1-.4-1.7-1.3-1.7-1 0-1.6.7-1.6 1.9V18h-2.8z" fill="#fff"/>'),
        'meta_ads' => $svg('<circle cx="7.5" cy="12" r="4.4" fill="none" stroke="#0081FB" stroke-width="2.2"/><circle cx="16.5" cy="12" r="4.4" fill="none" stroke="#0081FB" stroke-width="2.2"/>'),
        'google_ads' => $svg('<rect x="9.4" y="3.2" width="5.2" height="15.4" rx="2.6" transform="rotate(-30 12 11)" fill="#4285F4"/><rect x="9.4" y="3.2" width="5.2" height="15.4" rx="2.6" transform="rotate(30 12 11)" fill="#FBBC04"/><circle cx="5.4" cy="18.3" r="2.7" fill="#34A853"/>'),
        'youtube' => $svg('<rect x="2" y="5" width="20" height="14" rx="4" fill="#FF0000"/><path d="M10 9v6l5-3z" fill="#fff"/>'),
        'seznam_wmt' => $svg('<circle cx="12" cy="12" r="9.5" fill="#CC0000"/><path d="M15.6 8.4c-.8-.8-2-1.2-3.4-1.2-2.3 0-3.9 1.2-3.9 3 0 3.4 5.6 2.3 5.6 4.1 0 .7-.7 1.1-1.8 1.1-1.2 0-2.3-.5-3.1-1.3l-1.3 1.7c1.1 1 2.7 1.6 4.4 1.6 2.4 0 4-1.2 4-3 0-3.5-5.6-2.5-5.6-4.2 0-.6.6-1 1.6-1 1 0 1.9.4 2.6 1z" fill="#fff"/>'),
        default => $svg('<path d="M4 19V5m0 14h16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="m7 14 4-4 3 3 5-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'),
    };
}

/**
 * Bílé Facebook „f" pro modrá CTA tlačítka „Připojit přes Meta/Facebook" — lucide brandové ikony
 * odstranil, takže <i data-lucide="facebook"> se nevykresloval (prázdná mezera + console warning).
 */
function allstat_meta_button_icon(): string
{
    return '<svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true" focusable="false"><path fill="currentColor" d="M22 12a10 10 0 1 0-11.6 9.9v-7H7.9V12h2.5V9.8c0-2.5 1.5-3.9 3.8-3.9 1.1 0 2.2.2 2.2.2v2.5h-1.3c-1.2 0-1.6.8-1.6 1.6V12h2.8l-.4 2.9h-2.4v7A10 10 0 0 0 22 12z"/></svg>';
}
