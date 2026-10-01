<?php

require __DIR__ . '/_legal.php';

$org = legal_e(legal_get('org'));
$email = legal_e(legal_get('email'));
$app = legal_e(legal_get('app'));

ob_start();
?>
<h1>Pokyny ke smazání dat</h1>
<p class="eff">Účinné od: <?= legal_e(legal_get('effective')) ?></p>

<p><strong><?= $app ?></strong> je interní nástroj bez veřejných uživatelských účtů. „Data" zde znamenají statistiky a přístupové tokeny <strong>napojených účtů/stránek</strong> organizace <?= $org ?> a účty interních správců. Smazání lze provést kterýmkoli z následujících způsobů.</p>

<h2>Způsob 1, Odpojení přímo v aplikaci</h2>
<p>Správce v administraci otevře <strong>Zdroje</strong>, u příslušného napojení zvolí <strong>Smazat</strong>. Tím se odstraní napojení včetně <strong>uložených přístupových tokenů</strong>; smazání se eviduje v interním logu. Statistiky daného účtu lze odstranit současně na vyžádání (viz Způsob 3).</p>

<h2>Způsob 2, Odebrání přístupu na straně platformy</h2>
<ul>
<li><strong>Facebook / Instagram:</strong> Facebook → <em>Nastavení a soukromí → Nastavení → Obchodní integrace (Business Integrations)</em> → najdi <strong><?= $app ?></strong> a odeber. Tím se okamžitě zneplatní přístupové tokeny.</li>
<li><strong>LinkedIn:</strong> LinkedIn → <em>Já (Me) → Nastavení a soukromí → Datové soukromí → Oprávnění aplikací / Povolené služby</em> → odeber <strong><?= $app ?></strong>.</li>
<li><strong>Google:</strong> <a href="https://myaccount.google.com/permissions" target="_blank" rel="noopener">myaccount.google.com/permissions</a> → odeber přístup aplikace.</li>
</ul>

<h2>Způsob 3, Žádost e-mailem</h2>
<p>Napiš na <a href="mailto:<?= $email ?>?subject=Smazani%20dat%20-%20AllStat"><?= $email ?></a> s předmětem <code>Smazání dat – AllStat</code> a uveď, které stránky / účet / web se mají smazat. Příslušná data smažeme bez zbytečného odkladu, <strong>nejpozději do 30 dnů</strong>, a smazání ti potvrdíme.</p>

<h2>Co se smaže</h2>
<ul>
<li>napojení a všechny uložené přístupové tokeny daného účtu,</li>
<li>uložené statistiky a obsah příspěvků daného účtu,</li>
<li>na žádost i účet interního správce.</li>
</ul>

<span class="lang">English version</span>
<h1>Data Deletion Instructions</h1>
<p class="eff">Effective: <?= legal_e(legal_get('effective')) ?></p>

<p><strong><?= $app ?></strong> is an internal tool with no public user accounts. "Data" here means the statistics and access tokens of the <strong>connected accounts/pages</strong> of <?= $org ?>, and internal administrator accounts. Deletion can be done in any of the following ways.</p>

<h2>Option 1, Disconnect inside the app</h2>
<p>An administrator opens <strong>Sources</strong> in the admin and chooses <strong>Delete</strong> on the relevant connection. This removes the connection including its <strong>stored access tokens</strong>; the deletion is recorded in an internal log. The account's stored statistics can be removed at the same time on request (see Option 3).</p>

<h2>Option 2, Revoke access on the platform</h2>
<ul>
<li><strong>Facebook / Instagram:</strong> Facebook → <em>Settings &amp; privacy → Settings → Business Integrations</em> → find <strong><?= $app ?></strong> and remove it. This immediately invalidates the access tokens.</li>
<li><strong>LinkedIn:</strong> LinkedIn → <em>Me → Settings &amp; Privacy → Data privacy → Permitted services</em> → remove <strong><?= $app ?></strong>.</li>
<li><strong>Google:</strong> <a href="https://myaccount.google.com/permissions" target="_blank" rel="noopener">myaccount.google.com/permissions</a> → remove the app's access.</li>
</ul>

<h2>Option 3, Request by e-mail</h2>
<p>E-mail <a href="mailto:<?= $email ?>?subject=Data%20deletion%20-%20AllStat"><?= $email ?></a> with the subject <code>Data deletion – AllStat</code>, stating which page / account / website to delete. We will delete the relevant data without undue delay and <strong>within 30 days at the latest</strong>, and confirm the deletion to you.</p>

<h2>What gets deleted</h2>
<p>The connection and all stored access tokens of the account; the account's stored statistics and post content; and, on request, the internal administrator account.</p>
<?php
legal_render('Pokyny ke smazání dat / Data Deletion Instructions', ob_get_clean());
