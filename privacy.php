<?php

require __DIR__ . '/_legal.php';

$org = legal_e(legal_get('org'));
$email = legal_e(legal_get('email'));
$app = legal_e(legal_get('app'));

ob_start();
?>
<h1>Zásady ochrany osobních údajů</h1>
<p class="eff">Účinné od: <?= legal_e(legal_get('effective')) ?></p>

<p><strong><?= $app ?></strong> je <strong>interní</strong> analytický nástroj provozovaný organizací <?= $org ?> výhradně pro vlastní potřebu. Nejde o veřejnou službu, nelze se do něj veřejně registrovat a přístup mají pouze pověření zaměstnanci/správci. Nástroj na jednom místě agreguje statistiky <strong>vlastních webů a vlastních profilů na sociálních sítích</strong> organizace.</p>

<h2>1. Správce údajů</h2>
<p><?= $org ?>, kontaktní e-mail <a href="mailto:<?= $email ?>"><?= $email ?></a>.</p>

<h2>2. Jaké údaje zpracováváme</h2>
<h3>a) Agregované statistiky návštěvnosti webů</h3>
<p>Z Google Analytics 4, Google Search Console a Microsoft Clarity přebíráme <strong>pouze denní souhrnné počty</strong>: návštěvy, uživatelé, zobrazení stránek, zdroje/kanály návštěvnosti, odkazující weby, návštěvy z AI nástrojů, vstupní a ostatní stránky, kategorie zařízení, země/region (agregovaně), události, konverze a vyhledávací dotazy. <strong>Nezpracováváme žádné osobní údaje jednotlivých návštěvníků</strong> ani je individuálně nesledujeme, jen denní agregáty.</p>

<h3>b) Statistiky vlastních profilů na sociálních sítích</h3>
<p>Z Facebook stránek, Instagramu a LinkedIn (přes oficiální API, na základě autorizace organizace) přebíráme souhrnné metriky stránky a příspěvků (dosah, imprese, interakce, reakce, komentáře, sdílení) a veřejný obsah <strong>vlastních</strong> příspěvků (text, odkaz, čas publikace). U LinkedIn pouze <strong>agregované organické statistiky organizace</strong>. <strong>Nezpracováváme osobní data jiných uživatelů/členů</strong> sociálních sítí.</p>

<h3>c) Reklamní statistiky vlastních účtů</h3>
<p>Z Meta Ads přebíráme náklady, imprese, kliky, konverze a hodnotu konverzí vlastních reklamních účtů.</p>

<h3>d) Přístupové tokeny a klíče</h3>
<p>Přístupové tokeny a API klíče napojených účtů ukládáme <strong>šifrovaně (AES-256-GCM)</strong>.</p>

<h3>e) Účty správců nástroje</h3>
<p>O interních správcích uchováváme: jméno, e-mail, <strong>bezpečný otisk hesla (bcrypt)</strong>, volitelně šifrovaný klíč pro dvoufaktorové ověření (TOTP), čas posledního přihlášení, záznam akcí s IP adresou (audit log) a záznamy přihlašovacích pokusů (ochrana proti zneužití).</p>

<h2>3. Účel a právní základ</h2>
<p>Údaje zpracováváme <strong>výhradně pro interní reporting a analýzu vlastní webové a sociální prezentace</strong> organizace. Právním základem je oprávněný zájem správce (čl. 6 odst. 1 písm. f GDPR) na vyhodnocování vlastní prezentace; data z platforem jsou daty vlastních účtů, k nimž má organizace oprávnění.</p>

<h2>4. Jak údaje NEpoužíváme</h2>
<ul>
<li>Neprodáváme je a nesdílíme s třetími stranami pro jejich vlastní účely.</li>
<li>Nepoužíváme je k cílení reklamy na třetí osoby, k obohacování CRM ani k tvorbě databází kontaktů/leadů.</li>
<li>Nepoužíváme je k recruitingu.</li>
<li>Neprovádíme scraping ani crawling mimo oficiální API poskytovatelů.</li>
</ul>

<h2>5. Zdroje dat a zpracovatelé</h2>
<p>Data čerpáme přes oficiální API: Google (GA4, Search Console), Microsoft (Clarity), Meta Platforms (Facebook, Instagram, Ads) a LinkedIn. Aplikace běží na webhostingu v EU. S uvedenými platformami nesdílíme zpět žádná data nad rámec autentizace nezbytné pro stažení statistik.</p>

<h2>6. Zabezpečení</h2>
<ul>
<li>Komunikace přes HTTPS.</li>
<li>Tokeny a tajné hodnoty šifrované <strong>AES-256-GCM</strong>; hesla hashovaná <strong>bcrypt</strong>.</li>
<li><strong>Dvoufaktorové ověření (TOTP)</strong>, volitelné a vynutitelné pro správce.</li>
<li>Ochrana proti CSRF, automatické odhlášení při neaktivitě, evidence přihlašovacích pokusů.</li>
<li>Rolemi omezený přístup do administrace a auditní log akcí.</li>
</ul>

<h2>7. Doba uložení</h2>
<p>Agregované statistiky uchováváme po dobu nezbytnou pro reporting. Přístupové tokeny uchováváme do odpojení účtu nebo do smazání na žádost. Data z LinkedIn jsou dostupná jen v rozsahu, který poskytuje jeho API (rolling 12 měsíců).</p>

<h2>8. Vaše práva</h2>
<p>Máte právo na přístup, opravu, výmaz, omezení zpracování a vznesení námitky. Kontaktujte nás na <a href="mailto:<?= $email ?>"><?= $email ?></a>. Postup smazání dat popisuje stránka <a href="data-deletion.php">Pokyny ke smazání dat</a>.</p>

<h2>9. Změny</h2>
<p>Tyto zásady můžeme aktualizovat; platí verze s nejnovějším datem účinnosti uvedeným nahoře.</p>

<span class="lang">English version</span>
<h1>Privacy Policy</h1>
<p class="eff">Effective: <?= legal_e(legal_get('effective')) ?></p>

<p><strong><?= $app ?></strong> is an <strong>internal</strong> analytics tool operated by <?= $org ?> solely for its own use. It is <strong>not a public service</strong>, there is no public sign-up and access is limited to authorized staff/administrators. The tool aggregates statistics of the organization's <strong>own websites and own social-media profiles</strong> in one place.</p>

<h2>1. Data controller</h2>
<p><?= $org ?>, contact e-mail <a href="mailto:<?= $email ?>"><?= $email ?></a>.</p>

<h2>2. Data we process</h2>
<p><strong>a) Aggregated website statistics</strong>, from Google Analytics 4, Google Search Console and Microsoft Clarity we ingest <strong>daily aggregate counts only</strong> (visits, users, pageviews, traffic channels/referrers, AI-tool referrals, landing/other pages, device categories, country/region in aggregate, events, conversions, search queries). <strong>We do not process any personal data of individual visitors</strong> and do not track them individually.</p>
<p><strong>b) Own social-media statistics</strong>, from Facebook Pages, Instagram and LinkedIn (via official APIs, authorized by the organization) we ingest page- and post-level metrics (reach, impressions, engagement, reactions, comments, shares) and the public content of the organization's <strong>own</strong> posts. For LinkedIn, only <strong>aggregated organic organization statistics</strong>. <strong>We do not process personal data of other users/members.</strong></p>
<p><strong>c) Own ad statistics</strong>, from Meta Ads: spend, impressions, clicks, conversions and conversion value of the organization's own ad accounts.</p>
<p><strong>d) Access tokens &amp; keys</strong>, stored <strong>encrypted (AES-256-GCM)</strong>.</p>
<p><strong>e) Administrator accounts</strong>, name, e-mail, a secure password hash (bcrypt), an optional encrypted two-factor (TOTP) secret, last-login time, an audit log of actions with IP address, and login-attempt records.</p>

<h2>3. Purpose &amp; legal basis</h2>
<p>Data is processed <strong>solely for the organization's internal reporting and analysis of its own web and social presence</strong>. The legal basis is the controller's legitimate interest (Art. 6(1)(f) GDPR); platform data belongs to the organization's own accounts which it is authorized to access.</p>

<h2>4. How we do NOT use the data</h2>
<p>We do not sell or share it with third parties for their purposes; we do not use it for ad targeting of third parties, CRM enrichment, building contact/lead databases, or recruiting; and we do not scrape or crawl outside the providers' official APIs.</p>

<h2>5. Data sources &amp; processors</h2>
<p>Data is obtained via official APIs of Google (GA4, Search Console), Microsoft (Clarity), Meta Platforms (Facebook, Instagram, Ads) and LinkedIn. The application is hosted on EU web hosting. We send no data back to these platforms beyond the authentication required to fetch statistics.</p>

<h2>6. Security</h2>
<p>HTTPS transport; tokens/secrets encrypted with AES-256-GCM; passwords hashed with bcrypt; optional/enforceable two-factor authentication (TOTP); CSRF protection; idle session timeout; login-attempt logging; role-restricted admin access and an audit log.</p>

<h2>7. Retention</h2>
<p>Aggregated statistics are retained as needed for reporting. Access tokens are kept until the account is disconnected or deleted on request. LinkedIn data is available only within the rolling 12-month window its API provides.</p>

<h2>8. Your rights</h2>
<p>You may request access, rectification, erasure, restriction and objection at <a href="mailto:<?= $email ?>"><?= $email ?></a>. See the <a href="data-deletion.php">Data Deletion Instructions</a> for erasure.</p>

<h2>9. Changes</h2>
<p>We may update this policy; the version with the latest effective date above applies.</p>
<?php
legal_render('Zásady ochrany osobních údajů / Privacy Policy', ob_get_clean());
