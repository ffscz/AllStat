<?php

require __DIR__ . '/_legal.php';

$org = legal_e(legal_get('org'));
$email = legal_e(legal_get('email'));
$app = legal_e(legal_get('app'));

ob_start();
?>
<h1>Podmínky používání</h1>
<p class="eff">Účinné od: <?= legal_e(legal_get('effective')) ?></p>

<p><strong><?= $app ?></strong> je interní nástroj organizace <?= $org ?>. Není to veřejná služba; používat ho smí pouze pověřené osoby organizace.</p>

<h2>1. Účel</h2>
<p>Nástroj slouží k agregaci a zobrazení statistik vlastních webů a vlastních profilů organizace na sociálních sítích (Google, Microsoft Clarity, Meta, LinkedIn) pro interní reporting.</p>

<h2>2. Přijatelné používání</h2>
<ul>
<li>Přístup je osobní a nepřenosný; přihlašovací údaje se nesdílí.</li>
<li>Data se používají výhradně pro interní reporting organizace.</li>
<li>Uživatel nesmí používat nástroj ani získaná data v rozporu s podmínkami zdrojových platforem ani k účelům zakázaným v <a href="privacy.php">Zásadách ochrany osobních údajů</a> (prodej dat, cílení reklamy na třetí osoby, leady, recruiting, scraping mimo API).</li>
</ul>

<h2>3. Data třetích platforem</h2>
<p>Používání dat získaných z Meta, LinkedIn a Google podléhá také podmínkám těchto platforem (mj. Meta Platform Terms, LinkedIn API Terms of Use, Google API Services User Data Policy). V případě rozporu mají pro příslušná data přednost podmínky dané platformy.</p>

<h2>4. Bez záruky</h2>
<p>Nástroj je poskytován „tak, jak je". Zobrazená data jsou orientační a mohou se lišit od nativních rozhraní platforem (zpoždění, zaokrouhlení, změny API).</p>

<h2>5. Omezení odpovědnosti</h2>
<p>Provozovatel neodpovídá za nepřímé škody vzniklé používáním nástroje ani za výpadky či změny API třetích stran.</p>

<h2>6. Ukončení a odpojení</h2>
<p>Správce může kdykoli odebrat přístup uživateli a odpojit kterýkoli napojený účet; tím dojde i ke smazání příslušných přístupových tokenů (viz <a href="data-deletion.php">Pokyny ke smazání dat</a>).</p>

<h2>7. Kontakt</h2>
<p><a href="mailto:<?= $email ?>"><?= $email ?></a></p>

<span class="lang">English version</span>
<h1>Terms of Service</h1>
<p class="eff">Effective: <?= legal_e(legal_get('effective')) ?></p>

<p><strong><?= $app ?></strong> is an internal tool of <?= $org ?>. It is not a public service and may be used only by the organization's authorized staff.</p>

<h2>1. Purpose</h2>
<p>The tool aggregates and displays statistics of the organization's own websites and own social-media profiles (Google, Microsoft Clarity, Meta, LinkedIn) for internal reporting.</p>

<h2>2. Acceptable use</h2>
<p>Access is personal and non-transferable; credentials must not be shared. Data is used solely for the organization's internal reporting. Users must not use the tool or its data in breach of the source platforms' terms or for purposes prohibited in the <a href="privacy.php">Privacy Policy</a> (selling data, third-party ad targeting, lead databases, recruiting, scraping outside the APIs).</p>

<h2>3. Third-party platform data</h2>
<p>Use of data obtained from Meta, LinkedIn and Google is also subject to those platforms' terms (incl. Meta Platform Terms, LinkedIn API Terms of Use, Google API Services User Data Policy). In case of conflict, the relevant platform's terms prevail for its data.</p>

<h2>4. No warranty</h2>
<p>The tool is provided "as is". Displayed figures are indicative and may differ from the platforms' native interfaces (delays, rounding, API changes).</p>

<h2>5. Limitation of liability</h2>
<p>The operator is not liable for indirect damages arising from use of the tool, nor for third-party API outages or changes.</p>

<h2>6. Termination &amp; disconnection</h2>
<p>The administrator may revoke a user's access and disconnect any connected account at any time, which also deletes the related access tokens (see <a href="data-deletion.php">Data Deletion Instructions</a>).</p>

<h2>7. Contact</h2>
<p><a href="mailto:<?= $email ?>"><?= $email ?></a></p>
<?php
legal_render('Podmínky používání / Terms of Service', ob_get_clean());
