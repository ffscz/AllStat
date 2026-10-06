<?php

/**
 * Temporary "Share for AI" links: a tokenized, short-lived, PUBLIC-while-valid export of ONE dashboard
 * view (overview / FB / IG / Meta Ads / Clarity) for the chosen period, as Markdown (default) or JSON.
 * The user generates a link in the (authenticated) dashboard and pastes it into an AI (ChatGPT / Gemini /
 * Claude); the AI fetches the public URL and gets a clean, structured report + auto-findings to analyse.
 * Security: the token is the only access control — 48 random hex chars, expires in minutes, scoped to one
 * domain + view + range, exposes only aggregated analytics (no credentials/tokens).
 */

function allstat_share_token(): string
{
    return bin2hex(random_bytes(24)); // 48 hex chars
}

function allstat_share_create(PDO $pdo, int $domainId, int $viewSourceId, string $start, string $end, string $format, string $granularity, int $ttlMinutes): array
{
    [$start, $end] = allstat_limited_range($start, $end);
    $token = allstat_share_token();
    $ttlMinutes = max(1, min(15, $ttlMinutes)); // max 15 min, token je jediná ochrana, krátká platnost = nižší expozice
    $format = in_array($format, ['md', 'json'], true) ? $format : 'md';
    // Validate granularity INLINE (don't call allstat_normalize_granularity from repository.php — the admin
    // _bootstrap that runs share-create.php does NOT load repository.php, so that would be undefined).
    // 'growth' = report pohledu Růst kanálů (start/end = okno uzavřených měsíců), bez změny schématu tabulky.
    $granularity = in_array($granularity, ['day', 'week', 'month', 'growth'], true) ? $granularity : 'day';
    $expires = (new DateTimeImmutable('now'))->modify('+' . $ttlMinutes . ' minutes')->format('Y-m-d H:i:s');

    $pdo->prepare('INSERT INTO share_links (token, domain_id, view_source_id, start_date, end_date, format, granularity, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$token, $domainId, $viewSourceId, $start, $end, $format, $granularity, $expires]);

    return ['token' => $token, 'expires_at' => $expires, 'ttl_minutes' => $ttlMinutes, 'format' => $format];
}

function allstat_share_lookup(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
        return null;
    }
    try {
        $row = allstat_fetch_one($pdo, 'SELECT * FROM share_links WHERE token = ? LIMIT 1', [$token]);
    } catch (Throwable) {
        return null;
    }
    if (!$row || strtotime((string) $row['expires_at']) < time()) {
        return null;
    }

    return $row;
}

function allstat_share_cleanup(PDO $pdo): void
{
    try {
        $pdo->prepare('DELETE FROM share_links WHERE expires_at < ?')
            ->execute([(new DateTimeImmutable('now'))->modify('-1 hour')->format('Y-m-d H:i:s')]);
    } catch (Throwable) { /* best-effort */ }
}

/** Build a GitHub-flavoured Markdown table. */
function allstat_md_table(array $cols, array $rows): string
{
    if (!$rows) {
        return "_(žádná data za období)_\n";
    }
    $esc = static fn ($v): string => str_replace(['|', "\n"], ['\\|', ' '], (string) $v);
    $out = '| ' . implode(' | ', array_map($esc, $cols)) . " |\n";
    $out .= '| ' . implode(' | ', array_fill(0, count($cols), '---')) . " |\n";
    foreach ($rows as $r) {
        $out .= '| ' . implode(' | ', array_map($esc, $r)) . " |\n";
    }

    return $out;
}

/**
 * Weekly trend section from provider_metrics_daily (Meta / IG / Ads) — buckets daily metric values into
 * ISO weeks so the AI sees the trajectory (growing / declining), not just a single total. $keyLabels maps
 * metric_key => column label. Returns a section array, or null when there's no data.
 */
function allstat_share_weekly_section(PDO $pdo, int $domainId, int $connectionId, array $keyLabels, string $start, string $end): ?array
{
    $keys = array_keys($keyLabels);
    if (!$keys) {
        return null;
    }
    try {
        $ph = implode(',', array_fill(0, count($keys), '?'));
        $rows = allstat_fetch_all($pdo, "SELECT metric_date, metric_key, SUM(metric_value) AS v
            FROM provider_metrics_daily
            WHERE domain_id = ? AND connection_id = ? AND dimension = '' AND metric_key IN ($ph) AND metric_date BETWEEN ? AND ?
            GROUP BY metric_date, metric_key", array_merge([$domainId, $connectionId], $keys, [$start, $end]));
    } catch (Throwable) {
        return null;
    }
    if (!$rows) {
        return null;
    }
    // Dlouhé období → měsíční agregace (jinak by týdenní tabulka měla desítky řádků a byla by useknutá);
    // krátké → týdny. Tím je „Vývoj" kompletní za celé zvolené období, ne jen posledních 12 týdnů.
    $spanDays = (int) floor((strtotime($end) - strtotime($start)) / 86400) + 1;
    $byMonth = $spanDays > 120;
    $groups = [];
    foreach ($rows as $r) {
        $date = (string) $r['metric_date'];
        try { $bucket = $byMonth ? (new DateTimeImmutable($date))->format('Y-m') : (new DateTimeImmutable($date))->format('o-W'); } catch (Throwable) { continue; }
        if (!isset($groups[$bucket])) { $groups[$bucket] = ['start' => $date, 'v' => []]; }
        if ($date < $groups[$bucket]['start']) { $groups[$bucket]['start'] = $date; }
        $groups[$bucket]['v'][(string) $r['metric_key']] = ($groups[$bucket]['v'][(string) $r['metric_key']] ?? 0.0) + (float) $r['v'];
    }
    ksort($groups);
    $groups = array_slice($groups, $byMonth ? -24 : -16); // až 24 měsíců / 16 týdnů
    if (count($groups) < 2) {
        return null; // jeden bod není trend
    }
    $tableRows = [];
    foreach ($groups as $g) {
        try { $lab = $byMonth ? (new DateTimeImmutable($g['start']))->format('n/Y') : ('od ' . allstat_iso_to_cz_date($g['start'])); } catch (Throwable) { $lab = $g['start']; }
        $row = [$lab];
        foreach ($keys as $k) { $row[] = allstat_number((float) ($g['v'][$k] ?? 0)); }
        $tableRows[] = $row;
    }

    return ['title' => $byMonth ? 'Vývoj po měsících' : 'Vývoj po týdnech', 'cols' => array_merge([$byMonth ? 'Měsíc' : 'Týden'], array_values($keyLabels)), 'rows' => $tableRows];
}

/**
 * Top sloty pro publikaci (den × 4h okno) podle Ø engagementu, z heatmapy allstat_social_build_heatmap.
 * Vrací řádky [Den, Čas, Příspěvků, Ø engagement] seřazené od nejlepšího; prázdné, když heatmapa chybí.
 */
function allstat_share_best_slots(?array $heatmap, int $limit = 6): array
{
    if (!$heatmap || empty($heatmap['grid'])) {
        return [];
    }
    $days = $heatmap['days'] ?? ['Po', 'Út', 'St', 'Čt', 'Pá', 'So', 'Ne'];
    $buckets = $heatmap['buckets'] ?? [];
    $slots = [];
    foreach ($heatmap['grid'] as $w => $cells) {
        foreach ($cells as $b => $cell) {
            if ((int) ($cell['count'] ?? 0) > 0) {
                $slots[] = ['day' => $days[$w] ?? '', 'slot' => ($buckets[$b] ?? '') . ' h', 'count' => (int) $cell['count'], 'avg' => (float) ($cell['avg'] ?? 0)];
            }
        }
    }
    // Stejné řazení jako heatmapa v UI: sloty s jedním příspěvkem až za vícekrát ověřené (jeden šťastný
    // post nemá vést žebříček), skóre 0-100 relativně k nejsilnějšímu oknu.
    usort($slots, static function (array $a, array $b): int {
        $aMulti = $a['count'] >= 2 ? 1 : 0;
        $bMulti = $b['count'] >= 2 ? 1 : 0;
        return $bMulti <=> $aMulti ?: $b['avg'] <=> $a['avg'];
    });
    $maxAvg = (float) ($heatmap['maxAvg'] ?? 0);

    return array_map(static fn (array $s): array => [
        $s['day'], $s['slot'],
        allstat_number($s['count']) . ($s['count'] < 2 ? ' (malý vzorek)' : ''),
        allstat_number($s['avg'], 1),
        $maxAvg > 0 ? (string) (int) round($s['avg'] / $maxAvg * 100) : '—',
    ], array_slice($slots, 0, max(1, $limit)));
}

/**
 * Tailored, expert-level AI instruction per view type — a paid-media specialist reads ads differently than
 * an SEO/analytics consultant reads GA4/GSC or a social manager reads FB/IG. Each sets the right expert role
 * and the domain-specific problems to hunt for.
 */
/**
 * Kontext organizace pro AI prompt. Bez něj AI hodnotí data optikou e-shopu/korporátu (ROAS, tržby,
 * enterprise playbooky), což pro veřejnou příspěvkovou organizaci nedává smysl. Přepsatelné přes
 * allstat_settings klíč `share.org_context` (stejný vzor jako právní stránky → snadný rebrand),
 * výchozí text je obecný.
 */
function allstat_share_org_context(PDO $pdo): string
{
    $text = '';
    try {
        $st = $pdo->query("SELECT setting_value FROM allstat_settings WHERE setting_key = 'share.org_context' LIMIT 1");
        $text = trim((string) ($st ? $st->fetchColumn() : ''));
    } catch (Throwable) {
        // Setting je bonus; bez DB řádku se použije default níže.
    }
    if ($text !== '') {
        return $text;
    }

    return 'KONTEXT ORGANIZACE: Popis organizace nebyl vyplněn (Nastavení, sekce Právní stránky, pole Popis organizace pro AI analýzy). '
        . 'Nevymýšlej si informace o organizaci, jejím oboru ani cílech. Hodnoť data z reportu objektivně a u každého doporučení '
        . 'uveď, z jakého čísla vychází. Doporučení drž konkrétní, levná a zvládnutelná malým marketingovým týmem v řádu hodin '
        . 'týdně; nenavrhuj kroky vyžadující agenturu nebo velký reklamní rozpočet. Cíl webu (prodej, registrace, povědomí) '
        . 'odvozuj opatrně z toho, jaké události a konverze se v datech objevují, a uveď, že jde o odhad.';
}

/**
 * Sdílená metodika čtení reportu — připojuje se ke každému promptu. Řeší chyby, které AI nad reportem
 * reálně dělaly: míchání metrik s různým rozsahem (úroveň stránky vs. součty přes příspěvky), čtení
 * „—" jako nuly a ignorování definic ve sloupci „Co přesně měří".
 */
function allstat_share_methodology(): string
{
    return "JAK ČÍST REPORT: Vyhodnocuj výhradně období z řádku „Obdobi\" v Kontextu; sekce „Srovnání s předchozím obdobím\" porovnává celé kalendářní měsíce s předchozími celými měsíci, rozběhnutý měsíc se stejnými dny minulého měsíce a jiná období se stejně dlouhým obdobím těsně před ním. "
        . "Sloupec „Co přesně měří\" u metrik uvádí zdroj, definici a jmenovatel — řiď se jím: metriky „úroveň celé stránky\" a „součty přes příspěvky\" mají různý rozsah a nesmí se kombinovat v jednom výpočtu ani zaměňovat. "
        . "Pomlčka „—\" znamená nedostupné/neměřené, ne nulu. Než prohlásíš metriku za chybějící, projdi celý report — může být v jiné sekci. Kde data reálně chybí, napiš přesně, co a jak doměřit.";
}

/**
 * Povinná struktura výstupu analýzy — připojuje se ke každému promptu. Exekutivní shrnutí (top 5 pozitiv,
 * top 5 slabin, prioritní kroky) PŘED detailním rozborem: čtenář reportu je vedení/tým, který potřebuje
 * závěry na začátku; detail slouží k obhajobě závěrů. Každé hodnocení musí citovat číslo z reportu,
 * ať je výsledek profesionální a PRAVDIVÝ (žádná dojmologie, žádná vymyšlená čísla).
 */
function allstat_share_output_format(): string
{
    return "STRUKTURA TVÉ ANALÝZY (dodrž pořadí):\n"
        . "1. SHRNUTÍ — nejdřív „Top 5: co funguje\" (každý bod = konkrétní metrika + číslo z reportu + proč je to dobře), pak „Top 5: nejslabší místa\" (stejně konkrétně), pak „Prioritní doporučení\" (3–5 kroků seřazených podle dopadu, u každého co přesně udělat a co tím zlepšíme).\n"
        . "2. ROZBOR PO METRIKÁCH — projdi jednotlivé sekce reportu; u každé řekni hodnocení (pozitivní/negativní/neutrální), oporu v číslech a srovnání (benchmark, předchozí období, vlastní průměr).\n"
        . "3. Každé tvrzení musí jít dohledat v reportu (cituj hodnotu). Nesměšuj pochvaly a výtky dohromady, drž strukturu. Piš česky.";
}

/**
 * Oborové benchmarky sociálních sítí: kurátorovaná externí referenční čísla + NAŠE hodnota přepočtená
 * ve STEJNÉ definici (jinak je srovnání jablka–hrušky; např. Rival IQ měří engagement na SLEDUJÍCÍHO,
 * my jinde na dosah). Zdroje a edice jsou přímo v řádcích, ať AI necituje benchmark bez původu:
 * LinkedIn = Oktopost B2B Benchmark Hub Q1 2026; FB/IG = Rival IQ/Quid Benchmark Report 2026 (kategorie
 * Nonprofits, nejbližší veřejné organizaci) + Socialinsider 2026 (70M příspěvků). Čísla jsou statická
 * v kódu → při vydání nových edicí aktualizovat (roční rytmus).
 */
function allstat_share_benchmark_section(string $network, array $ctx): ?array
{
    $followers = (float) ($ctx['followers'] ?? 0);
    $posts = (int) ($ctx['posts'] ?? 0);
    $engagement = (float) ($ctx['engagement'] ?? 0);
    $pct = static fn (?float $v): string => $v === null ? '—' : allstat_number($v, 2) . ' %';
    // Engagement na sledujícího na příspěvek (definice Rival IQ): (engagement / počet příspěvků) / sledující.
    $erFollower = ($followers > 0 && $posts > 0) ? $engagement / $posts / $followers * 100 : null;

    $rows = match ($network) {
        'linkedin' => [
            ['Míra zapojení na zobrazení (naše = bez prokliků)', $pct($ctx['engRate'] ?? null), 'medián 5,10 %, p75 8,61 %', 'Oktopost B2B LinkedIn Benchmark, Q1 2026 (přesný jmenovatel zdroj neuvádí, ber orientačně)'],
            ['Zobrazení na příspěvek', $posts > 0 ? allstat_number((float) ($ctx['impressions'] ?? 0) / $posts, 0) : '—', 'medián 927', 'Oktopost Q1 2026'],
            ['Engagement na příspěvek', $posts > 0 ? allstat_number($engagement / $posts, 1) : '—', 'medián 51', 'Oktopost Q1 2026'],
            ['Růst sledujících za měsíc', $pct(($followers > 0 && ($ctx['periodDays'] ?? 0) > 0 && ($ctx['newFollows'] ?? null) !== null) ? ((float) $ctx['newFollows'] / (float) $ctx['periodDays'] * 30.44) / $followers * 100 : null), 'medián 1,16 % měsíčně', 'Oktopost Q1 2026'],
        ],
        'instagram' => [
            ['Engagement na sledujícího na příspěvek', $pct($erFollower), 'neziskový sektor 0,62 %; všechna odvětví ~0,45 %', 'Rival IQ/Quid 2026 (Nonprofits); Socialinsider Q1 2026'],
            ['Frekvence publikace', allstat_number((float) ($ctx['perWeek'] ?? 0), 1) . ' / týden', 'neziskový sektor ~4,9 / týden', 'Rival IQ/Quid 2026'],
            ['Dosah příspěvku vs. počet sledujících', $pct(($followers > 0 && $posts > 0) ? ((float) ($ctx['reach'] ?? 0) / $posts) / $followers * 100 : null), 'účty do 10 tis.: foto/feed 18–28 %, Reels 45–65 %', 'Socialinsider 2026'],
        ],
        'facebook' => [
            ['Engagement na sledujícího na příspěvek', $pct($erFollower), 'neziskový sektor 0,046 %; všechna odvětví ~0,15 %', 'Rival IQ/Quid 2026 (Nonprofits); Socialinsider Q1 2026'],
            ['Frekvence publikace', allstat_number((float) ($ctx['perWeek'] ?? 0), 1) . ' / týden', 'neziskový sektor ~5,5 / týden', 'Rival IQ/Quid 2026'],
        ],
        default => [],
    };
    if (!$rows) {
        return null;
    }

    return ['title' => 'Oborové benchmarky (externí reference)', 'cols' => ['Metrika (v definici zdroje)', 'Naše hodnota', 'Reference', 'Zdroj'], 'rows' => $rows];
}

/**
 * Cross-provider můstek: co o dané sociální síti říká WEB (GA4 data téhož domain_id) — skutečné příchody
 * (sessions podle referreru), UTM označený provoz a konverze. Uzavírá díru „síť hlásí prokliky, ale nikdo
 * neví, kolik lidí reálně dorazilo": clickCount sítí počítá i interní kliky (rozbalit text, jméno stránky),
 * GA4 session je skutečný příchod. Vrací null, když web nemá GA4 data za období (sekce se vynechá).
 */
function allstat_share_web_from_network(PDO $pdo, int $domainId, string $network, string $start, string $end): ?array
{
    $map = [
        'linkedin' => [['linkedin.com', 'lnkd.in'], 'linkedin|lnkd', 'LinkedInu'],
        'facebook' => [['facebook.com', 'm.facebook.com', 'l.facebook.com', 'lm.facebook.com', 'fb.me', 'fb.com'], 'facebook|^fb$|meta', 'Facebooku'],
        'instagram' => [['instagram.com', 'l.instagram.com'], 'instagram|^ig$', 'Instagramu'],
        'youtube' => [['youtube.com', 'm.youtube.com', 'www.youtube.com', 'youtu.be'], 'youtube|^yt$', 'YouTube'],
    ];
    if (!isset($map[$network])) {
        return null;
    }
    [$domains, $utmPattern, $genitiv] = $map[$network];
    try {
        // Bez GA4 dat za období sekci nevyrábět (na webu nemusí být GA4 napojená).
        $hasGa = (int) allstat_fetch_one($pdo, 'SELECT COUNT(*) c FROM traffic_sources_daily WHERE domain_id = ? AND metric_date BETWEEN ? AND ?', [$domainId, $start, $end])['c'];
        if ($hasGa === 0) {
            return null;
        }
        $rows = [];
        $ph = implode(',', array_fill(0, count($domains), '?'));
        $refs = allstat_fetch_all($pdo, "SELECT source, SUM(sessions) s, SUM(conversions) c FROM referrers_daily
            WHERE domain_id = ? AND metric_date BETWEEN ? AND ? AND source IN ($ph) GROUP BY source ORDER BY s DESC",
            array_merge([$domainId, $start, $end], $domains));
        $sumS = 0; $sumC = 0;
        foreach ($refs as $r) {
            $sumS += (int) $r['s']; $sumC += (int) $r['c'];
            $rows[] = ['Referrer ' . $r['source'], allstat_number((int) $r['s']), allstat_number((int) $r['c'])];
        }
        $rows[] = ['Celkem příchody z ' . $genitiv . ' (referrer)', allstat_number($sumS), allstat_number($sumC)];
        // UTM označený provoz z téhle sítě — když chybí, je to samo o sobě zjištění (posty bez UTM).
        $utm = allstat_fetch_all($pdo, 'SELECT source, medium, campaign, SUM(sessions) s, SUM(conversions) c FROM utm_daily
            WHERE domain_id = ? AND metric_date BETWEEN ? AND ? AND source REGEXP ? GROUP BY source, medium, campaign ORDER BY s DESC LIMIT 10',
            [$domainId, $start, $end, $utmPattern]);
        if ($utm) {
            foreach ($utm as $u) {
                $rows[] = ['UTM ' . $u['source'] . ' / ' . $u['medium'] . ' / ' . ((string) $u['campaign'] !== '' ? $u['campaign'] : '(bez kampaně)'), allstat_number((int) $u['s']), allstat_number((int) $u['c'])];
            }
        } else {
            $rows[] = ['UTM označený provoz z ' . $genitiv, 'žádný (příspěvky nenesou UTM parametry)', '—'];
        }
        // Kontext: celý kanál Organic Social (všechny sítě dohromady, GA4 default channel grouping).
        $chan = allstat_fetch_one($pdo, "SELECT SUM(sessions) s, SUM(conversions) c FROM traffic_sources_daily
            WHERE domain_id = ? AND metric_date BETWEEN ? AND ? AND source = 'Organic Social'", [$domainId, $start, $end]);
        $rows[] = ['Kanál Organic Social celkem (všechny sítě)', allstat_number((int) ($chan['s'] ?? 0)), allstat_number((int) ($chan['c'] ?? 0))];

        return ['title' => 'Web z této sítě (GA4): skutečné příchody a konverze', 'cols' => ['Zdroj', 'Návštěvy webu (GA4 sessions)', 'Konverze (GA4 klíčové události)'], 'rows' => $rows];
    } catch (Throwable) {
        return null;
    }
}

function allstat_share_instructions(string $providerKey, int $viewSourceId): string
{
    $type = $providerKey === 'growth'
        ? 'growth'
        : (($viewSourceId === 0 || in_array($providerKey, ['overview', 'ga4', 'gsc'], true)) ? 'overview' : $providerKey);

    return match ($type) {
        'growth' => "Jsi marketingový analytik. Níže je měsíční vývoj hlavní metriky každého kanálu jednoho webu (web, vyhledávání, sociální sítě, YouTube, reklama) za uzavřené měsíce. Zhodnoť, jak se kanály vyvíjejí, a navrhni prioritizované kroky. Zaměř se hlavně na:\n"
            . "- Které kanály dlouhodobě rostou a které klesají (sekce „Růst kanálů\"), a zda jde o trend, nebo jednorázový výkyv (sekce „Měsíc po měsíci\" a „Změna m/m\").\n"
            . "- Vliv placených kampaní: srovnej měsíce s útratou a kliknutími na reklamy (řádek Meta Ads) s výkyvy dosahu a návštěvnosti; odděl organický vývoj od efektu kampaní.\n"
            . "- Sezónnost a souvislosti mezi kanály (roste vyhledávání a web spolu? přivádějí sítě návštěvnost?).\n"
            . "- Klesající kanály: co je pravděpodobná příčina a co konkrétně udělat (frekvence, formáty, témata), nebo zda kanál utlumit.\n"
            . "- Sledující (sekce souhrn): roste komunita tam, kde roste dosah?\n"
            . "Piš česky, stručně, v odrážkách, od nejdůležitějšího, s konkrétními akcemi pro malý tým.",

        'overview' => "Jsi senior webový analytik a SEO konzultant (GA4 + Search Console). Níže jsou data jednoho webu za zvolené období. Najdi konkrétní SLABINY a příležitosti a navrhni prioritizované kroky. Zaměř se hlavně na:\n"
            . "- Kvalitu měření: měří se klíčové události (objednávky, registrace, poptávky, odeslané formuláře)? velký podíl „Přímá/Nepřiřazeno\" = ztracená atribuce; sedí GA4 vs GSC?\n"
            . "- Strukturu návštěvnosti: závislost na jednom kanálu, slabý organic/social, poměr nových vs vracejících se, konkrétní odkazující weby (referrery) — kdo relevantní odkazuje a kdo by měl (partneři, média, odborné weby).\n"
            . "- Konverze a zapojení: konverzní poměr ke klíčovým událostem, čas na webu, míra zapojení, kde se ztrácí potenciál.\n"
            . "- SEO dotazy (GSC): vysoké imprese + NÍZKÉ CTR = lepší titulek/snippet; pozice 5–15 = „skoro první stránka\", kde stačí přitlačit.\n"
            . "- SEO stránky (GSC, sekce „Top stránky ve vyhledávání\"): KTERÉ URL se zobrazují a neklikají; spáruj dotazy se stránkami a navrhni, kterou stránku přepsat.\n"
            . "- Kampaně (UTM): drží tým UTM disciplínu (tagují se newslettery/sociální sítě/partnerské odkazy)? které kampaně vedou k akci?\n"
            . "- AI návštěvy (ChatGPT/Perplexity…): rostou? je web připravený být citovaný (strukturovaný obsah, jasné odpovědi)?\n"
            . "- Nejnavštěvovanější i VSTUPNÍ stránky vs klíčové události; geografii (zasahuje web region nebo trh z Kontextu organizace, nebo se návštěvnost tvoří jinde?); týdenní vývoj a srovnání s předchozím obdobím.\n"
            . "Piš česky, stručně, v odrážkách, od nejdůležitějšího. U každého problému dej KONKRÉTNÍ akci (kterou stránku, který titulek, který kanál).",

        'facebook_pages' => "Jsi social media stratég. Níže jsou data jedné Facebook stránky za období. Najdi slabiny a navrhni konkrétní zlepšení organického obsahu. Zaměř se na:\n"
            . "- Míru zapojení příspěvků (engagement ÷ dosah příspěvků), je zdravá? co ji sráží?\n"
            . "- Dosah a jeho VÝVOJ v čase (klesá organický dosah? co se stalo v týdnech s propadem — viz „Vývoj po týdnech\").\n"
            . "- Výkon podle typu obsahu (foto/video/reel/odkaz), co posílit a co utlumit; u malého týmu navrhni udržitelný mix, ne „točte denně video\".\n"
            . "- Frekvenci a NEJLEPŠÍ ČAS publikace (publikuje se konzistentně a ve správný čas?).\n"
            . "- Růst sledujících (noví vs. odhlášení) a sentiment reakcí (objevuje se 😡/😢 a u čeho?).\n"
            . "- Demografii publika (věk/pohlaví/města): odpovídá cílové skupině z Kontextu organizace? (Pozn.: datum snímku demografie je v Kontextu.)\n"
            . "- Srovnání se sekcí „Oborové benchmarky\": jsme nad/pod referencí? Respektuj definici metriky v prvním sloupci a poznámku o velikosti stránek.\n"
            . "- Sekci „Web z této sítě (GA4)\": kolik lidí z FB reálně došlo na web a kolik konvertovalo; chybí-li UTM, navrhni konvenci značení.\n"
            . "Piš česky, stručně, v odrážkách, prioritizovaně, s konkrétními akcemi. Organický dosah FB dlouhodobě klesá — doporuč formáty, které to kompenzují, a jasné CTA na hlavní cíl webu z Kontextu organizace.",

        'instagram_business' => "Jsi expert na Instagram pro organizace. Níže jsou data jednoho IG účtu za období. Najdi slabiny a navrhni konkrétní zlepšení. Zaměř se na:\n"
            . "- Dosah a míru zapojení NA PŘÍSPĚVEK (které příspěvky táhnou a proč — viz Top 5 a kompletní seznam).\n"
            . "- UKLÁDÁNÍ (saved): nejsilnější signál užitečného obsahu; co lidé ukládají a čeho dělat víc.\n"
            . "- Okruh: poměr dosahu u sledujících vs. nesledujících — objevuje účet nové publikum, nebo vaří ve vlastní bublině?\n"
            . "- Formáty (Reels vs. foto vs. carousel): co má největší dosah na příspěvek; u malého týmu udržitelný mix.\n"
            . "- Frekvenci, konzistenci, nejlepší čas publikace; růst sledujících v čase.\n"
            . "- Demografii publika (věk/pohlaví/města): sedí na komunitu, kterou má organizace budovat?\n"
            . "- Srovnání se sekcí „Oborové benchmarky\": dosah vs. sledující, engagement na sledujícího, frekvence. Respektuj definici metriky v prvním sloupci.\n"
            . "- Sekci „Web z této sítě (GA4)\": kolik lidí z IG reálně došlo na web (link in bio, stories odkazy) a kolik konvertovalo; chybí-li UTM, navrhni konvenci.\n"
            . "Piš česky, stručně, v odrážkách, prioritizovaně, s konkrétními akcemi. Pozn.: IG přes API nedává typy reakcí ani profilové návštěvy — kde data chybí, řekni, co sledovat přímo v aplikaci Instagram.",

        'meta_ads' => "Jsi specialista na výkonnostní reklamu (Meta Ads) pro organizaci. Cíl kampaní (prodej, registrace, návštěvnost, povědomí) vyčti z KONTEXTU ORGANIZACE a neodhaduj ho. ROAS nehodnoť, pokud v datech není hodnota konverzí; efektivitu posuzuj přes cenu za výsledek. Najdi neefektivity a navrhni prioritizované optimalizace:\n"
            . "- Hodnoť každou kampaň podle jejího CÍLE (sloupec „Cíl\"): povědomí podle ceny za 1 000 zobrazení (CPM), počtu lidí a frekvence, NE podle kliknutí (Meta ji ukazuje co nejvíc lidem, ne těm, kdo kliknou); návštěvnost podle ceny za proklik NA ODKAZ a míry prokliku na odkaz. „CTR (vše)\" a „Cena za kliknutí (vše)\" počítají i lajky, komentáře a rozbalení textu, na web vede jen proklik na odkaz.\n"
            . "- Zacílení/aukce: CPM, lidé a FREKVENCE ZA CELOU KAMPAŇ (≥3 = únava publika – přestat přelévat rozpočet do vyčerpané sestavy). „Denní frekvence\" říká jen, kolikrát člověk reklamu viděl za jeden den, o opakování za celé období nic neříká.\n"
            . "- Kreativa: míra prokliku na odkaz, hook rate u videí (přehrání aspoň 3 s ÷ zobrazení, cíl ≥ ~30 %); slabá kreativa = vyhozený rozpočet; viz sekce „Kreativy\".\n"
            . "- Přechod na web: prokliky vs. skutečné návštěvy vstupní stránky (pomalý web / špatná URL?).\n"
            . "- Měření: jsou nastavené konverzní události (registrace, objednávky, formuláře) a pixel? bez nich nejde počítat cenu za konverzi.\n"
            . "- Alokace rozpočtu: které kampaně/sestavy/kreativy táhnou a kam přesunout peníze; pojmenuj outliery.\n"
            . "- Demografii a platformy: zasahují reklamy správné publikum a region z Kontextu organizace, nebo se rozpouštějí mimo cílovou skupinu?\n"
            . "- Vývoj v čase: kde výkon kulminoval/klesl (viz týdenní tabulka).\n"
            . "Piš česky, stručně, v odrážkách, od největšího dopadu, s konkrétními akcemi (co POZASTAVIT, co ŠKÁLOVAT, co PŘEDĚLAT). Zohledni i automatická doporučení níže.",

        'linkedin_company' => "Jsi B2B stratég pro LinkedIn firemní stránky. Níže jsou data LinkedIn stránky organizace za období. Cílovou skupinu a cíle stránky vyčti z KONTEXTU ORGANIZACE. Najdi slabiny a navrhni konkrétní zlepšení. Zaměř se na:\n"
            . "- Míru zapojení (definice ve sloupci „Co přesně měří\" — zdejší je bez prokliků, oficiální LinkedIn formule s prokliky vyjde výš; nezaměňuj je).\n"
            . "- Vývoj zobrazení, prokliků a NOVÝCH SLEDUJÍCÍCH v čase (roste zásah i komunita? viz „Vývoj po týdnech\").\n"
            . "- Demografii sledujících (seniorita/funkce/obor/lokalita): sleduje stránku publikum, které organizace potřebuje (cílová skupina z Kontextu organizace), nebo náhodní lidé? Srovnej se složením NÁVŠTĚVNÍKŮ stránky (koho obsah přitahuje nově).\n"
            . "- Výkon podle typu obsahu (text/odkaz/foto/video/dokument), co táhne a co utlumit.\n"
            . "- Konkrétní příspěvky: nejlepší vs. nejslabší a PROČ (téma, formát, délka, CTA).\n"
            . "- Frekvenci a konzistenci; poměr prokliků k zobrazením a HLAVNĚ k sekci „Web z této sítě (GA4)\": kolik prokliků reálně dorazilo na web (sessions) a kolik konvertovalo. Extrémní nepoměr rozklíčuj: příspěvky bez odkazů, chybějící UTM, kliky jen uvnitř sítě.\n"
            . "- Srovnání se sekcí „Oborové benchmarky\" (Oktopost B2B Q1 2026): míra zapojení, zobrazení a engagement na příspěvek, růst sledujících. Respektuj definice a poznámku, že benchmark měří výrazně větší stránky.\n"
            . "Piš česky, stručně, v odrážkách, prioritizovaně, s konkrétními akcemi. Zohledni B2B kontext (delší rozhodovací cyklus, hodnota expertního obsahu, dosah přes osobní profily zaměstnanců).",

        'youtube' => "Jsi stratég pro YouTube kanály a video obsah. Níže jsou data YouTube kanálu organizace za období. Cílovou skupinu a cíle kanálu vyčti z KONTEXTU ORGANIZACE. Najdi slabiny a navrhni konkrétní zlepšení. Zaměř se na:\n"
            . "- Sledovaný čas a Ø dobu zhlédnutí (klíč k doporučování YouTube) a jejich vývoj; u Top videí sloupec „Ø doba (% videa)\" = udržení pozornosti, nízké % = slabý úvod nebo příliš dlouhé video.\n"
            . "- Zdroje návštěvnosti: podíl vyhledávání a navrhovaných videí (organický dosah YouTube) vs. externí zdroje (web, sítě, e-mail) vs. odběratelé. Nízké vyhledávání = slabé titulky/popisky/klíčová slova.\n"
            . "- Odběratele: čistý přírůstek, která videa odběratele přinášejí (sloupec Odběry) a co z toho plyne pro témata.\n"
            . "- Formáty: videa vs. Shorts vs. živá vysílání, co táhne zhlédnutí a co sledovaný čas (Shorts dávají zhlédnutí, dlouhá videa čas).\n"
            . "- Konkrétní videa: nejlepší vs. nejslabší a PROČ (téma, délka, titulek, náhled, CTA). Rozliš videa publikovaná v období (celoživotní čísla) od evergreenu v „Top videa celkově\".\n"
            . "- Kategorie obsahu (sekce „Kategorie (playlisty kanálu)\" a sloupec Playlisty v katalogu): které tematické řady mají nejvyšší Ø zhlédnutí a udržení, které dlouhodobě nefungují a mají se utlumit nebo přeformátovat.\n"
            . "- Frekvenci publikování a konzistenci; publikum (země, věk) vs. cílová skupina organizace.\n"
            . "Piš česky, stručně, v odrážkách, prioritizovaně, s konkrétními akcemi (jaká témata natočit, co zkrátit, jak upravit titulky/náhledy, kde video nasadit mimo YouTube).",

        'google_ads' => "Jsi specialista na výkonnostní reklamu (Google Ads) pro organizaci. Co je konverze a jaký je cíl kampaní, vyčti z KONTEXTU ORGANIZACE. Níže jsou data jednoho účtu za období. Najdi neefektivity a navrhni prioritizované optimalizace:\n"
            . "- Ekonomika: cena za konverzi (CPA) vs. útrata; kde se pálí rozpočet bez výsledku? ROAS/PNO hodnoť jen pokud účet měří hodnotu konverzí.\n"
            . "- Kvalita provozu: CTR, CPC, CPM, sedí na typ kampaní (Search vs PMax/Display)?\n"
            . "- Konverze a měření: měří účet klíčové události (registrace, objednávky, formuláře)? bez toho nejde řídit na výsledek.\n"
            . "- Vývoj v čase (viz týdenní/měsíční tabulka), kde výkon kulminoval/klesl.\n"
            . "Piš česky, stručně, v odrážkách, od největšího dopadu, s konkrétními akcemi (co ŠKÁLOVAT, co OSEKAT, co doměřit).",

        'clarity' => "Jsi analytik chování uživatelů na webu (Microsoft Clarity). Níže jsou behaviorální data webu za období. Najdi slabiny UX a kvality provozu a navrhni konkrétní zlepšení. Zaměř se na:\n"
            . "- Kvalitu provozu: podíl botů vs. lidí, nepokřivují boti čísla?\n"
            . "- Zapojení: aktivní čas, scroll depth, stránky/relace, čtou lidé obsah, nebo odcházejí?\n"
            . "- Vstupní body: top referrery a nejnavštěvovanější stránky, kde zlepšit obsah/rychlost.\n"
            . "- Smart events (prokliky, rage/dead clicks apod.), kde uživatelé narážejí na problém.\n"
            . "Piš česky, stručně, v odrážkách, prioritizovaně, s konkrétními akcemi na UX a obsah. Pozn.: Clarity nedává tržby ani atribuci, kombinuj s GA4 pro celkový obrázek.",

        'seznam_wmt' => "Jsi technický SEO konzultant pro český vyhledávač Seznam.cz (Seznam Webmaster). Níže jsou data o INDEXACI webu za období (ne návštěvnost, počty zaindexovaných/stažených stránek, chyby, přesměrování). Vyhodnoť zdraví indexace a navrhni kroky. Zaměř se na:\n"
            . "- Pokrytí: kolik stránek je zaindexovaných vs. celkem známých (doc_count), indexuje se web dobře?\n"
            . "- Chyby a přesměrování: rostou? co je pravděpodobná příčina (404, chybné redirecty, nedostupnost)?\n"
            . "- Vývoj v čase, zlepšuje se, nebo klesá index coverage?\n"
            . "Piš česky, stručně, v odrážkách, prioritizovaně, s konkrétními technickými kroky. Pozn.: tohle je Seznam, ne Google; data jsou o indexaci, ne o kliknutích/dotazech, pro ty použij Search Console.",

        default => "Jsi datový a marketingový analytik. Níže jsou metriky jednoho zdroje za období. Najdi slabiny a navrhni konkrétní, prioritizované kroky ke zlepšení. Piš česky, stručně, v odrážkách. Kde data chybí, řekni, co doměřit.",
    };
}

/**
 * Gather one share's data into a structured payload (the JSON form); the MD renderer formats the same.
 */
function allstat_share_build(PDO $pdo, array $config, array $share): array
{
    $domainId = (int) $share['domain_id'];
    $viewSourceId = (int) $share['view_source_id'];
    $start = (string) $share['start_date'];
    $end = (string) $share['end_date'];
    $granularity = (string) ($share['granularity'] ?? 'day');

    $domains = allstat_get_domains($pdo);
    $domain = null;
    foreach ($domains as $d) {
        if ((int) $d['id'] === $domainId) { $domain = $d; break; }
    }
    $domainName = (string) ($domain['name'] ?? ('web #' . $domainId));
    $domainUrl = (string) ($domain['url'] ?? '');
    $periodDays = (int) floor((strtotime($end) - strtotime($start)) / 86400) + 1;
    $periodLabel = allstat_iso_to_cz_date($start) . ' – ' . allstat_iso_to_cz_date($end) . ' (' . $periodDays . ' dní)';

    $conn = $viewSourceId > 0 ? allstat_fetch_one($pdo, "SELECT ds.account_label, s.provider_key, s.name
        FROM domain_sources ds JOIN data_sources s ON s.id = ds.source_id WHERE ds.id = ? LIMIT 1", [$viewSourceId]) : null;
    $providerKey = (string) ($conn['provider_key'] ?? 'overview');
    $viewLabel = $conn ? ((string) $conn['name'] . ', ' . (string) ($conn['account_label'] ?? '')) : 'Přehled (GA4 + GSC)';
    if ($granularity === 'growth') {
        // Růst kanálů: okno uzavřených měsíců (start = 1. den prvního, end = poslední den posledního).
        $providerKey = 'growth';
        $viewLabel = 'Růst kanálů (po měsících)';
        $periodLabel = allstat_growth_window(allstat_share_growth_months($start, $end), (new DateTimeImmutable($end))->modify('first day of next month'))['rangeLabel']
            . ' (uzavřené měsíce, ' . allstat_iso_to_cz_date($start) . ' – ' . allstat_iso_to_cz_date($end) . ')';
    }

    $payload = [
        'title' => sprintf('AllStat report, %s, %s', $domainName, $viewLabel),
        'instructions' => allstat_share_org_context($pdo) . "\n\n"
            . allstat_share_instructions($providerKey, $viewSourceId) . "\n\n"
            . allstat_share_output_format() . "\n\n"
            . allstat_share_methodology(),
        'meta' => [
            'web' => $domainName,
            'url' => $domainUrl,
            'pohled' => $viewLabel,
            'obdobi' => $periodLabel,
            'vygenerovano' => (new DateTimeImmutable('now'))->format('Y-m-d H:i'),
        ],
        'sections' => [],
        'recommendations' => [],
    ];

    if ($providerKey === 'growth') {
        allstat_share_section_growth($pdo, $domainId, $start, $end, $payload);
    } elseif ($viewSourceId === 0 || $providerKey === 'overview' || in_array($providerKey, ['ga4', 'gsc'], true)) {
        allstat_share_section_overview($pdo, $domainId, $start, $end, $granularity, $payload);
    } elseif ($providerKey === 'meta_ads') {
        allstat_share_section_meta_ads($pdo, $domainId, $viewSourceId, $start, $end, $payload);
    } elseif (in_array($providerKey, ['facebook_pages', 'instagram_business'], true)) {
        allstat_share_section_social($pdo, $domainId, $viewSourceId, $start, $end, $granularity, $providerKey, $payload);
    } elseif ($providerKey === 'linkedin_company') {
        allstat_share_section_linkedin($pdo, $domainId, $viewSourceId, $start, $end, $granularity, $payload);
    } elseif ($providerKey === 'youtube') {
        allstat_share_section_youtube($pdo, $domainId, $viewSourceId, $start, $end, $granularity, $payload);
    } elseif ($providerKey === 'google_ads') {
        allstat_share_section_google_ads($pdo, $domainId, $viewSourceId, $start, $end, $payload);
    } elseif ($providerKey === 'clarity') {
        allstat_share_section_clarity($pdo, $domainId, $viewSourceId, $start, $end, $payload);
    } elseif ($providerKey === 'seznam_wmt') {
        allstat_share_section_seznam($pdo, $domainId, $viewSourceId, $start, $end, $payload);
    } else {
        allstat_share_section_generic($pdo, $domainId, $viewSourceId, $start, $end, $granularity, $providerKey, $payload);
    }

    return $payload;
}

function allstat_iso_to_cz_date(?string $value): string
{
    try {
        return (new DateTimeImmutable((string) $value))->format('j. n. Y');
    } catch (Throwable) {
        return (string) $value;
    }
}

function allstat_share_section_overview(PDO $pdo, int $domainId, string $start, string $end, string $gran, array &$payload): void
{
    $data = allstat_get_dashboard_data($pdo, $domainId, $start, $end, $gran);
    $prevLabel = !empty($data['previousRange']) ? allstat_iso_to_cz_date($data['previousRange']['start']) . '–' . allstat_iso_to_cz_date($data['previousRange']['end']) : 'předchozí období';

    $kpiRows = [];
    foreach (($data['kpis'] ?? []) as $k) {
        $trend = ($k['trend'] ?? 'none') === 'none' ? '—' : (($k['trend'] === 'up' ? '↑ ' : '↓ ') . ($k['changeLabel'] ?? ''));
        $kpiRows[] = [$k['label'] ?? '', $k['displayValue'] ?? '', $trend];
    }
    $payload['sections'][] = ['title' => 'Klíčové metriky (vs. ' . $prevLabel . ')', 'cols' => ['Metrika', 'Hodnota', 'Trend'], 'rows' => $kpiRows];

    // Vývoj návštěvnosti za celé období: po týdnech, u období delšího než půl roku po měsících (ne jen posledních 16 týdnů).
    $trendGran = ((new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days + 1) > 183 ? 'month' : 'week';
    $wkSeries = allstat_query_series($pdo, $domainId, $start, $end, $trendGran);
    $wkLabels = $wkSeries['labels'] ?? [];
    if (count($wkLabels) >= 2) {
        $trendRows = [];
        foreach ($wkLabels as $i => $lab) {
            $trendRows[] = [$lab, allstat_number((int) ($wkSeries['visits'][$i] ?? 0)), allstat_number((int) ($wkSeries['users'][$i] ?? 0)), allstat_number((int) ($wkSeries['conversions'][$i] ?? 0))];
        }
        $payload['sections'][] = ['title' => ($trendGran === 'month' ? 'Měsíční' : 'Týdenní') . ' vývoj návštěvnosti', 'cols' => [$trendGran === 'month' ? 'Měsíc' : 'Týden', 'Návštěvy', 'Uživatelé (součet dní)', 'Konverze'], 'rows' => $trendRows];
    }

    // Noví vs vracející se — rozpad KPI „Uživatelé" (instrukce pro AI se na tento poměr výslovně ptá).
    $nr = $data['charts']['newReturning'] ?? [];
    $usersDailySum = ($data['summary']['users_basis'] ?? 'daily_sum') !== 'unique';
    if (!empty($nr['segments']) && ($nr['total'] ?? 0) > 0) {
        $payload['sections'][] = ['title' => 'Noví vs. vracející se uživatelé' . ($usersDailySum ? ' (součet dní, vícedenní návštěvník vícekrát)' : ''), 'cols' => ['Typ', 'Uživatelé', 'Podíl'],
            'rows' => array_map(static fn (array $s): array => [$s['label'] ?? '', $s['valueLabel'] ?? '', $s['shareLabel'] ?? ''], $nr['segments'])];
    }

    $src = $data['charts']['traffic']['sources'] ?? [];
    $payload['sections'][] = ['title' => 'Zdroje návštěvnosti', 'cols' => ['Zdroj', 'Návštěvy', 'Konverze'],
        'rows' => array_map(static fn (array $r): array => [$r['source'] ?? '', allstat_number((int) ($r['sessions'] ?? 0)), allstat_number((int) ($r['conversions'] ?? 0))], array_slice($src, 0, 12))];

    // Konkrétní odkazující weby (referrery) — rozpad kanálu „Odkazující weby" na skutečné domény (kdo přivádí provoz).
    $ref = $data['tables']['referrers'] ?? [];
    if ($ref) {
        $payload['sections'][] = ['title' => 'Konkrétní odkazující weby (referrery)', 'cols' => ['Web', 'Návštěvy', 'Konverze', 'Podíl'],
            'rows' => array_map(static fn (array $r): array => [$r['source'] ?? '', $r['sessionsLabel'] ?? allstat_number((int) ($r['sessions'] ?? 0)), $r['conversionsLabel'] ?? '0', $r['shareLabel'] ?? ''], array_slice($ref, 0, 12))];
    }

    $ai = $data['tables']['aiSources'] ?? [];
    if ($ai) {
        $payload['sections'][] = ['title' => 'AI zdroje návštěv (ChatGPT, Perplexity…)', 'cols' => ['Zdroj', 'Návštěvy', 'Konverze'],
            'rows' => array_map(static fn (array $r): array => [$r['source'] ?? '', allstat_number((int) ($r['sessions'] ?? 0)), allstat_number((int) ($r['conversions'] ?? 0))], array_slice($ai, 0, 10))];
    }

    // Delší seznamy než na dashboardu (ten drží 5–8 řádků): report je podklad pro analýzu.
    $q = allstat_query_search_queries($pdo, $domainId, $start, $end, 20);
    $payload['sections'][] = ['title' => 'Top dotazy v Google (GSC)', 'cols' => ['Dotaz', 'Kliknutí', 'Imprese', 'CTR', 'Pozice'],
        'rows' => array_map(static fn (array $r): array => [$r['query'] ?? '', allstat_number((int) ($r['clicks'] ?? 0)), allstat_number((int) ($r['impressions'] ?? 0)), $r['ctrLabel'] ?? '', allstat_number((float) ($r['position'] ?? 0), 1)], $q)];

    // Stránky ve vyhledávání (GSC) — párová sekce k dotazům: dotazy = CO se hledá, stránky = KTERÁ URL
    // se zobrazuje. Vysoké imprese + nízké CTR = kandidát na lepší titulek/snippet.
    $gscPages = allstat_query_gsc_pages($pdo, $domainId, $start, $end, 15);
    if ($gscPages) {
        $payload['sections'][] = ['title' => 'Top stránky ve vyhledávání Google (GSC)', 'cols' => ['Stránka', 'Kliknutí', 'Imprese', 'CTR', 'Pozice'],
            'rows' => array_map(static fn (array $r): array => [$r['page'], $r['clicksLabel'], $r['impressionsLabel'], $r['ctrLabel'], $r['positionLabel']], $gscPages)];
    }

    $pages = allstat_query_all_pages($pdo, $domainId, $start, $end, 20);
    $payload['sections'][] = ['title' => 'Nejnavštěvovanější stránky', 'cols' => ['Stránka', 'Zobrazení', 'Podíl ze všech zobrazení'],
        'rows' => array_map(static fn (array $r): array => [$r['path'] ?? '', $r['viewsLabel'] ?? allstat_number((int) ($r['views'] ?? 0)), $r['shareLabel'] ?? ''], $pages)];

    // Vstupní stránky — kudy návštěvníci na web VSTUPUJÍ (jiné než nejnavštěvovanější; landing_pages_daily).
    [$prevStart, $prevEnd] = allstat_previous_range($start, $end);
    $landing = allstat_query_landing_pages($pdo, $domainId, $start, $end, $prevStart, $prevEnd, 15);
    if ($landing) {
        $payload['sections'][] = ['title' => 'Vstupní stránky (kudy lidé přicházejí)', 'cols' => ['Stránka', 'Vstupy', 'Konverze', 'Trend vs. min.'],
            'rows' => array_map(static fn (array $r): array => [$r['path'] ?? '', allstat_number((int) ($r['sessions'] ?? 0)), allstat_number((int) ($r['conversions'] ?? 0)), ($r['changeLabel'] ?? '') ?: '—'], $landing)];
    }

    // GA4 události — klíčové (konverzní) i běžné interakce. V závorce surový název z GA4 (kroky trychtýřů ho používají).
    $ev = allstat_query_events($pdo, $domainId, $start, $end, 15);
    if ($ev) {
        $payload['sections'][] = ['title' => 'GA4 události', 'cols' => ['Událost', 'Počet', 'Klíčové (konverze)'],
            'rows' => array_map(static fn (array $r): array => [($r['event'] ?? '') . (($r['name'] ?? '') !== '' && ($r['name'] ?? '') !== ($r['event'] ?? '') ? ' (' . $r['name'] . ')' : ''), $r['countLabel'] ?? allstat_number((int) ($r['count'] ?? 0)), ($r['keyEvents'] ?? 0) > 0 ? ($r['keyEventsLabel'] ?? '') : '—'], $ev)];
    }

    // E-commerce (jen když web reálně měří): nákupní trychtýř + top produkty. U webů bez e-shopu se přeskočí.
    $funnel = $data['tables']['funnel'] ?? [];
    if (!empty($funnel['hasData'])) {
        $payload['sections'][] = ['title' => 'Nákupní trychtýř (GA4 e-commerce)', 'cols' => ['Krok', 'Počet', 'Podíl z 1. kroku'],
            'rows' => array_map(static fn (array $s): array => [$s['label'] ?? '', $s['countLabel'] ?? '', $s['shareLabel'] ?? ''], $funnel['steps'] ?? [])];
    }
    $products = $data['tables']['topProducts'] ?? [];
    if ($products) {
        $payload['sections'][] = ['title' => 'Top produkty (GA4 e-commerce)', 'cols' => ['Produkt', 'Zobrazeno', 'Do košíku', 'Nákupy', 'Tržby', 'Konverze zobrazení→nákup'],
            'rows' => array_map(static fn (array $p): array => [$p['name'] ?? '', $p['viewedLabel'] ?? '', $p['addedLabel'] ?? '', $p['purchasedLabel'] ?? '', $p['revenueLabel'] ?? '', $p['buyRateLabel'] ?? '—'], array_slice($products, 0, 10))];
    }

    $geo = $data['tables']['geo'] ?? [];
    if ($geo) {
        $payload['sections'][] = ['title' => 'Geografie', 'cols' => ['Země / region', 'Návštěvy'],
            'rows' => array_map(static fn (array $r): array => [trim(((string) ($r['country'] ?? '')) . ' ' . ((string) ($r['region'] ?? ''))), allstat_number((int) ($r['sessions'] ?? 0))], array_slice($geo, 0, 8))];
    }

    $dev = $data['charts']['devices']['items'] ?? [];
    if ($dev) {
        $payload['sections'][] = ['title' => 'Zařízení', 'cols' => ['Zařízení', 'Návštěvy'],
            'rows' => array_map(static fn (array $r): array => [$r['device'] ?? '', allstat_number((int) ($r['sessions'] ?? 0))], $dev)];
    }

    // Kampaně (UTM) — kam otagovaný traffic přišel (kampaň → kanál → obsah → návštěvy/konverze).
    $utm = allstat_query_utm($pdo, $domainId, $start, $end, 15, 0);
    if ($utm) {
        $payload['sections'][] = ['title' => 'Kampaně (UTM)', 'cols' => ['Kampaň', 'Kanál', 'Obsah', 'Návštěvy', 'Konverze', 'Konv. %', 'Podíl'],
            'rows' => array_map(static fn (array $g): array => [$g['campaignLabel'] ?? '', $g['channelLabel'] ?? '', $g['contentLabel'] ?? '—', $g['sessionsLabel'] ?? '', $g['conversionsLabel'] ?? '0', $g['convRate'] ?? '—', $g['shareLabel'] ?? ''], $utm)];
    }

    // Demografie publika (GA4, modelovaný odhad z Google Signals; u malého trafficu prahováno).
    $demo = allstat_query_demographics($pdo, $domainId, $start, $end);
    if (!empty($demo['hasData'])) {
        if (!empty($demo['age'])) {
            $payload['sections'][] = ['title' => 'Věk publika (GA4)', 'cols' => ['Věk', 'Uživatelé', 'Podíl'],
                'rows' => array_map(static fn (array $r): array => [$r['label'] ?? '', $r['usersLabel'] ?? '', $r['shareLabel'] ?? ''], $demo['age'])];
        }
        if (!empty($demo['gender'])) {
            $payload['sections'][] = ['title' => 'Pohlaví publika (GA4)', 'cols' => ['Pohlaví', 'Uživatelé', 'Podíl'],
                'rows' => array_map(static fn (array $r): array => [$r['label'] ?? '', $r['usersLabel'] ?? '', $r['shareLabel'] ?? ''], $demo['gender'])];
        }
    }

    // Data-quality note: which sources are connected (so the AI can flag analytics gaps).
    $statuses = $data['tables']['sources'] ?? [];
    $connected = array_map(static function (array $s): string {
        $name = (string) ($s['source'] ?? '?');
        $st = (string) ($s['status'] ?? '');
        return $st !== '' && $st !== 'ok' ? $name . ' (' . $st . ')' : $name;
    }, $statuses);
    $payload['meta']['napojene_zdroje'] = $connected ? implode(', ', $connected) : 'žádné (demo data?)';
    $payload['meta']['posledni_sync'] = (string) ($data['meta']['lastSync'] ?? '—');
}

function allstat_share_section_meta_ads(PDO $pdo, int $domainId, int $viewSourceId, string $start, string $end, array &$payload): void
{
    $kpis = allstat_get_meta_ads_kpis($pdo, $domainId, $viewSourceId, $start, $end);
    $payload['sections'][] = ['title' => 'KPI reklamy', 'cols' => ['Metrika', 'Hodnota'],
        'rows' => array_map(static fn (array $m): array => [$m['label'] ?? '', $m['displayValue'] ?? ''], $kpis['metrics'] ?? [])];
    if (!empty($kpis['noConversions'])) {
        $payload['meta']['poznamka'] = 'Účet nemá nastavené konverzní sledování (awareness/traffic), ROAS/CPA/Konverze proto nejsou.';
    }

    $payload['meta']['poznamka_metriky'] = 'CTR (vše) a Cena za kliknutí (vše) počítají všechna kliknutí včetně lajků, komentářů a rozbalení textu; na web vede jen proklik na odkaz. Frekvence a lidé za celou kampaň / dobu jsou z Mety za celou dobu běhu (skuteční různí lidé), ne za zvolené období. Denní frekvence a Dosah (součet dní) počítají téhož člověka každý den znovu. Hook rate = přehrání videa aspoň 3 s ÷ zobrazení. Kde cíl nebo frekvence za celou dobu chybí, údaj z Mety ještě nebyl stažen.';

    $showConv = empty($kpis['noConversions']);
    $camps = allstat_get_meta_ads_breakdowns($pdo, $domainId, $viewSourceId, $start, $end, 15);
    $campCols = ['Kampaň', 'Cíl', 'Běžela', 'Útrata', 'Zobrazení', 'CPM', 'Prokliky na odkaz', 'Cena za proklik na odkaz', 'CTR odkazu', 'CTR (vše)', 'Cena za kliknutí (vše)', 'Přehrání videa (3 s)', 'Lidé za celou kampaň', 'Frekvence za celou kampaň'];
    if ($showConv) { array_push($campCols, 'Konverze', 'ROAS'); }
    $payload['sections'][] = ['title' => 'Kampaně', 'cols' => $campCols,
        'rows' => array_map(static function (array $c) use ($showConv): array {
            $row = [$c['name'] ?? '', ($c['goalLabel'] ?? '') !== '' ? $c['goalLabel'] : '—', ($c['runLabel'] ?? '') !== '' ? $c['runLabel'] : '—',
                $c['spendLabel'] ?? '', $c['impressionsLabel'] ?? '—', $c['cpmLabel'] ?? '—', $c['linkClicksLabel'] ?? '—', $c['cplcLabel'] ?? '—',
                $c['linkCtrLabel'] ?? '—', $c['ctrLabel'] ?? '—', $c['cpcLabel'] ?? '—', $c['videoViewsLabel'] ?? '—', $c['reachLifetimeLabel'] ?? '—', $c['frequencyLifetimeLabel'] ?? '—'];
            if ($showConv) { array_push($row, $c['conversionsLabel'] ?? '0', $c['roasLabel'] ?? '—'); }
            return $row;
        }, $camps)];

    $sets = allstat_get_meta_ads_subbreakdown($pdo, $domainId, $viewSourceId, $start, $end, 'adset', 12);
    if ($sets) {
        $payload['sections'][] = ['title' => 'Sestavy (ad sets)', 'cols' => ['Sestava', 'Optimalizace', 'Útrata', 'Zobrazení', 'Prokliky na odkaz', 'Cena za proklik na odkaz', 'CTR (vše)', 'Frekvence za celou dobu', 'Denní frekvence'],
            'rows' => array_map(static fn (array $r): array => [$r['name'] ?? '', ($r['optimizationLabel'] ?? '') !== '' ? $r['optimizationLabel'] : '—', $r['spendLabel'] ?? '', $r['impressionsLabel'] ?? '—',
                $r['linkClicksLabel'] ?? '—', $r['cplcLabel'] ?? '—', $r['ctrLabel'] ?? '—', $r['frequencyLifetimeLabel'] ?? '—', $r['frequencyDailyLabel'] ?? '—'], $sets)];
    }

    // Kreativy (jednotlivé reklamy) — prompt po AI chce rozhodnutí co POZASTAVIT/ŠKÁLOVAT/PŘEDĚLAT,
    // a to se děje na úrovni kreativy. Hook rate = přehrání videa aspoň 3 s ÷ zobrazení (ne pouhé spuštění
    // videa); frekvence za celou dobu ≥ 3 = únava. Hodnocení = verdikt proti ostatním reklamám se stejným cílem.
    $ads = allstat_get_meta_ads_subbreakdown($pdo, $domainId, $viewSourceId, $start, $end, 'ad', 12);
    if ($ads) {
        $payload['sections'][] = ['title' => 'Kreativy (jednotlivé reklamy)', 'cols' => ['Kreativa', 'Cíl', 'Útrata', 'Prokliky na odkaz', 'Cena za proklik na odkaz', 'CTR (vše)', 'CPM', 'Frekvence za celou dobu (≥3 = únava publika)', 'Hook rate videa (3 s)', 'Hodnocení'],
            'rows' => array_map(static fn (array $r): array => [$r['name'] ?? '', ($r['goalLabel'] ?? '') !== '' ? $r['goalLabel'] : '—', $r['spendLabel'] ?? '', $r['linkClicksLabel'] ?? '—',
                $r['cplcLabel'] ?? '—', $r['ctrLabel'] ?? '—', $r['cpmLabel'] ?? '—', $r['frequencyLifetimeLabel'] ?? '—', $r['hookLabel'] ?? '—', (string) ($r['verdict']['label'] ?? '—')], $ads)];
    }

    $wk = allstat_share_weekly_section($pdo, $domainId, $viewSourceId, ['spend' => 'Útrata', 'link_clicks' => 'Prokliky na odkaz', 'impressions' => 'Zobrazení', 'reach' => 'Dosah'], $start, $end);
    if ($wk) { $payload['sections'][] = $wk; }

    // Demografie a umístění reklam z API (age/gender/region/platform, podíl zobrazení v %).
    $adDemo = allstat_get_meta_ads_demographics($pdo, $viewSourceId, $start, $end);
    if (!empty($adDemo['hasData'])) {
        foreach ([['age', 'Věk'], ['gender', 'Pohlaví'], ['platform', 'Platforma'], ['region', 'Region']] as [$slot, $lab]) {
            if (empty($adDemo[$slot])) { continue; }
            $payload['sections'][] = ['title' => 'Reklamy, publikum podle ' . mb_strtolower($lab === 'Věk' ? 'věku' : ($lab === 'Pohlaví' ? 'pohlaví' : ($lab === 'Platforma' ? 'platformy' : 'regionu'))), 'cols' => [$lab, 'Podíl zobrazení'],
                'rows' => array_map(static fn (array $r): array => [$r['label'] ?? '', allstat_percent((float) ($r['share'] ?? 0), 1)], $adDemo[$slot])];
        }
    }

    $payload['recommendations'] = allstat_get_meta_ads_recommendations($pdo, $domainId, $viewSourceId, $start, $end);
}

/**
 * Sekce „metriky stránky" z provider view. Kromě hodnoty veze i tooltip z metric_meta, protože je v něm
 * zdroj a hlavně JMENOVATEL metriky (např. že míra zapojení u LinkedInu je bez prokliků, kdežto engagement
 * u Facebooku je včetně nich). Bez toho AI netuší, proti čemu číslo benchmarkovat, a míchá dohromady
 * úroveň stránky se součty přes příspěvky, které mají jiný rozsah i jmenovatele.
 */
function allstat_share_page_metrics_section(string $title, array $pv): array
{
    return ['title' => $title, 'cols' => ['Metrika', 'Hodnota', 'Co přesně měří'],
        'rows' => array_map(static fn (array $m): array => [
            $m['label'] ?? '',
            $m['totalLabel'] ?? $m['displayValue'] ?? '',
            (string) ($m['tooltip'] ?? ''),
        ], $pv['metrics'] ?? [])];
}

/**
 * Popisek čistého přírůstku sledujících. Když číslo vzniklo rozdílem snapshotů „Sledující celkem"
 * (síť denní přírůstky nedává), musí být vidět, za KTERÉ dny to reálně je: snapshoty běží až od nasazení
 * metriky, takže u delšího období nepokrývají celý rozsah a AI by je jinak četla jako přírůstek za celek.
 */
function allstat_share_followers_label(?array $fol): string
{
    $source = $fol['source'] ?? 'none';
    if (!$fol || $source === 'none') { return '(neměřeno)'; }
    $net = (int) ($fol['net'] ?? 0);
    $label = ($net >= 0 ? '+' : '') . allstat_number($net);
    if ($source === 'snapshot') {
        $label .= ' (dopočteno z „Sledující celkem" za ' . allstat_iso_to_cz_date((string) $fol['from'])
            . ' – ' . allstat_iso_to_cz_date((string) $fol['to']) . ', tj. ' . (int) ($fol['days'] ?? 0)
            . ' dní, ne celé období; za zbytek období nejsou denní přírůstky naměřené)';
    }

    return $label;
}

function allstat_share_section_social(PDO $pdo, int $domainId, int $viewSourceId, string $start, string $end, string $gran, string $providerKey, array &$payload): void
{
    $netLabel = $providerKey === 'instagram_business' ? 'Instagram' : 'Facebook stránka';
    $pv = allstat_get_provider_view($pdo, $domainId, $viewSourceId, $start, $end, $gran, $providerKey);
    $payload['sections'][] = allstat_share_page_metrics_section($netLabel . ', metriky stránky (úroveň celé stránky za období)', $pv);

    $spanDays = (int) floor((strtotime($end) - strtotime($start)) / 86400) + 1;
    allstat_share_append_post_sections($pdo, $viewSourceId, $start, $end, $spanDays, $payload, $providerKey === 'instagram_business' ? 'instagram' : 'facebook');

    // Demografie publika (IG = z API, FB = z CSV „Publikum"): věk / pohlaví / země / města, podíl v %.
    $isIg = $providerKey === 'instagram_business';
    $demo = allstat_get_ig_demographics($pdo, $viewSourceId);
    if (!empty($demo['hasData'])) {
        foreach ([['age', 'Věk'], ['gender', 'Pohlaví'], ['country', 'Země'], ['city', 'Města']] as [$slot, $lab]) {
            if (empty($demo[$slot])) { continue; }
            $payload['sections'][] = ['title' => $netLabel . ', publikum podle ' . mb_strtolower($lab === 'Věk' ? 'věku' : ($lab === 'Pohlaví' ? 'pohlaví' : ($lab === 'Země' ? 'zemí' : 'měst'))), 'cols' => [$lab, 'Podíl'],
                'rows' => array_map(static fn (array $r): array => [$r['label'] ?? '', allstat_percent((float) ($r['share'] ?? 0), 1)], array_slice($demo[$slot], 0, 10))];
        }
        if (!empty($demo['date'])) { $payload['meta']['demografie_publika_z'] = allstat_iso_to_cz_date($demo['date']); }
    }

    // Okruh (IG): dosah a zobrazení v rozpadu sledující / nesledující.
    if ($isIg) {
        $aud = allstat_get_ig_audience($pdo, $viewSourceId, $start, $end);
        if (!empty($aud['hasData'])) {
            $split = static fn (array $s): string => 'sledující ' . allstat_number((int) ($s['follower'] ?? 0)) . ', nesledující ' . allstat_number((int) ($s['nonFollower'] ?? 0)) . (((int) ($s['unknown'] ?? 0)) > 0 ? ', neznámí ' . allstat_number((int) $s['unknown']) : '');
            $payload['sections'][] = ['title' => 'Instagram okruh (sledující vs. nesledující)', 'cols' => ['Metrika', 'Rozpad'], 'rows' => [
                ['Dosah', $split($aud['reach'])],
                ['Zobrazení', $split($aud['views'])],
            ]];
        }
    }

    // Stories (zachycené, s metrikami dosah/zobrazení/odpovědi/navigace, pokud je API dalo).
    $stories = allstat_get_social_stories($pdo, $viewSourceId, $start, $end, 20);
    if (!empty($stories['count']) && !empty($stories['totals']['hasMetrics'])) {
        $st = $stories['totals'];
        $payload['sections'][] = ['title' => 'Stories, souhrn (' . (int) $stories['count'] . ' zachyceno)', 'cols' => ['Metrika', 'Hodnota'], 'rows' => [
            ['Dosah (součet)', $st['reachLabel'] ?? '0'],
            ['Zobrazení (součet)', $st['viewsLabel'] ?? '0'],
            ['Odpovědi', $st['repliesLabel'] ?? '0'],
            ['Navigace (posuny/odchody)', $st['navigationLabel'] ?? '0'],
        ]];
    }

    $wk = allstat_share_weekly_section($pdo, $domainId, $viewSourceId,
        $isIg ? ['reach' => 'Dosah', 'new_follows' => 'Noví sledující'] : ['page_impressions' => 'Zobrazení', 'reach' => 'Dosah', 'engagements' => 'Engagement', 'new_follows' => 'Noví sledující'],
        $start, $end);
    if ($wk) { $payload['sections'][] = $wk; }

    // Oborové benchmarky: naše hodnoty přepočtené do definic zdrojů + reference (viz builder).
    $sp2 = allstat_get_social_posts($pdo, $viewSourceId, $start, $end, 1);
    $bench = allstat_share_benchmark_section($isIg ? 'instagram' : 'facebook', [
        'followers' => allstat_share_pv_total($pv, 'followers_total'),
        'posts' => (int) ($sp2['count'] ?? 0),
        'engagement' => (float) ($sp2['engagement'] ?? 0),
        'reach' => (float) ($sp2['reach'] ?? 0),
        'perWeek' => (float) ($sp2['perWeek'] ?? 0),
    ]);
    if ($bench && !empty($sp2['hasData'])) {
        $payload['sections'][] = $bench;
        $payload['meta']['poznamka_benchmarky'] = 'Benchmarky jsou orientační: zdroje měří převážně větší stránky a malé stránky mívají přirozeně vyšší míru zapojení na sledujícího. Srovnávej vždy v definici uvedené v prvním sloupci.';
    }

    // Cross-provider: skutečné příchody na web z téhle sítě dle GA4 (sessions + konverze).
    $web = allstat_share_web_from_network($pdo, $domainId, $isIg ? 'instagram' : 'facebook', $start, $end);
    if ($web) {
        $payload['sections'][] = $web;
        $payload['meta']['poznamka_web_ze_site'] = 'Prokliky ze statistik sítě počítají i kliky uvnitř sítě (rozbalení textu, jméno stránky, fotky). Skutečné příchody na web ukazuje sekce „Web z této sítě (GA4)". Velký nepoměr = příspěvky bez odkazů na web, chybějící UTM, nebo kliky nevedoucí ven.';
    }
}

/**
 * Total jedné metriky z provider view podle jejího key (metriky nesou 'key' + 'total'). Null když chybí.
 */
function allstat_share_pv_total(array $pv, string $key): ?float
{
    foreach ($pv['metrics'] ?? [] as $m) {
        if (($m['key'] ?? '') === $key) {
            return (float) ($m['total'] ?? 0);
        }
    }

    return null;
}

/**
 * Post-level rozbor příspěvků pro libovolné napojení se social_posts (FB / IG / LinkedIn): souhrn,
 * rozpad reakcí, podle typu obsahu, nejlepší čas, Top 5 a KOMPLETNÍ seznam za období + srovnání.
 * $network ('facebook'|'instagram'|'linkedin') jen ladí znění poznámky o kompletnosti historie.
 */
function allstat_share_append_post_sections(PDO $pdo, int $viewSourceId, string $start, string $end, int $spanDays, array &$payload, string $network = 'facebook'): void
{
    // Top 5 dle engagementu (limit 5); kompletní seznam se tahá zvlášť přes stránkovací dotaz.
    $sp = allstat_get_social_posts($pdo, $viewSourceId, $start, $end, 5);
    // POZN.: LinkedIn se od opravy stránkování (start/count, sortBy=CREATED) tahá přes celé okno syncu,
    // strop je 20 stránek po 100. Poznámka proto NESMÍ tvrdit „jen ~50 nejnovějších", jak říkala dřív:
    // export vypsal desítky příspěvků a hned pod tím AI radil, ať je nebere jako kompletní.
    $apiNote = $network === 'linkedin'
        ? 'Příspěvky se z LinkedIn API stahují se stránkováním přes celé zvolené období, seznam výše je tedy kompletní (za období, které pokryl sync nebo backfill). Agregované metriky stránky jsou z page statistics a zahrnují i obsah mimo tyto příspěvky, proto jsou vyšší než součty přes příspěvky.'
        : 'Facebook/Instagram API vrací hlavně nejnovější příspěvky, u delšího období nemusí být historie kompletní. Chybí-li starší příspěvky, spusť u napojení „Stáhnout historii".';
    if (!empty($sp['hasData'])) {
        $reachMissing = ((int) $sp['reach']) <= 0;

        // Souhrn příspěvků. POZOR na rozsah: tohle jsou součty PŘES PŘÍSPĚVKY stažené za období, kdežto
        // sekce „metriky stránky" výše je úroveň celé stránky. Čísla se liší (stránka počítá i obsah mimo
        // příspěvky) a jmenovatel míry zapojení je jiný, proto to popisky říkají natvrdo.
        $payload['sections'][] = ['title' => 'Příspěvky, souhrn (součty přes příspěvky, ne úroveň stránky)', 'cols' => ['Metrika', 'Hodnota'], 'rows' => [
            ['Počet příspěvků', allstat_number((int) $sp['count'])],
            ['Dosah příspěvků (součet)', $reachMissing ? '(neměřeno)' : allstat_number((int) $sp['reach'])],
            ['Zobrazení příspěvků (součet)', ((int) $sp['impressions']) > 0 ? allstat_number((int) $sp['impressions']) : '—'],
            ['Míra zapojení příspěvků (engagement příspěvků ÷ dosah příspěvků)', $sp['engRateLabel'] ?? '—'],
            ['Engagement příspěvků (reakce + komentáře + sdílení, bez prokliků)', $sp['engagementLabel'] ?? '0'],
            ['Ø reakcí / komentářů / sdílení na příspěvek', implode(' / ', [allstat_number((float) $sp['avgReactions'], 1), allstat_number((float) $sp['avgComments'], 1), allstat_number((float) $sp['avgShares'], 1)])],
            ['Uložení (součet)', ((int) ($sp['saved'] ?? 0)) > 0 ? allstat_number((int) $sp['saved']) . ' (Ø ' . allstat_number((float) ($sp['avgSaved'] ?? 0), 1) . ' / příspěvek)' : '—'],
            ['Frekvence / týden', allstat_number((float) $sp['perWeek'], 1)],
            ['Čistý přírůstek sledujících', allstat_share_followers_label($sp['followers'] ?? null)],
        ]];

        // Rozpad reakcí za období (FB má typy; IG/LinkedIn většinou jen „like" → sekce se pak skryje)
        $rx = $sp['reactions'] ?? [];
        $rxMeta = [['like', '👍 To se mi líbí'], ['love', '❤️ Super'], ['haha', '😂 Haha'], ['wow', '😮 Paráda'], ['sad', '😢 To mě mrzí'], ['angry', '😡 To mě štve']];
        $rxRows = [];
        $rxTotal = 0;
        foreach ($rxMeta as [$k, $lab]) {
            $n = (int) ($rx[$k] ?? 0);
            $rxTotal += $n;
            if ($n > 0) { $rxRows[] = [$lab, allstat_number($n)]; }
        }
        if ($rxRows) {
            $rxRows[] = ['Celkem reakcí', allstat_number($rxTotal)];
            $payload['sections'][] = ['title' => 'Rozpad reakcí (za období)', 'cols' => ['Reakce', 'Počet'], 'rows' => $rxRows];
        }

        // Podle typu obsahu (co funguje)
        if (!empty($sp['byFormat'])) {
            $payload['sections'][] = ['title' => 'Podle typu obsahu (co funguje)', 'cols' => ['Typ', 'Počet', 'Ø engagement', 'Zobrazení', 'Dosah (součet)'],
                'rows' => array_map(static fn (array $f): array => [$f['label'] ?? '', allstat_number((int) $f['count']), allstat_number((float) $f['avgEngagement'], 1), ((int) ($f['views'] ?? 0)) > 0 ? allstat_number((int) $f['views']) : '—', ((int) $f['reach']) > 0 ? allstat_number((int) $f['reach']) : '—'], $sp['byFormat'])];
        }

        // Nejlepší čas pro publikaci (dle Ø engagementu) — z VLASTNÍCH příspěvků, ne z oborových průměrů.
        $slots = allstat_share_best_slots($sp['heatmap'] ?? null, 6);
        if ($slots) {
            $payload['sections'][] = ['title' => 'Nejlepší čas pro publikaci (dle Ø engagementu vlastních příspěvků)', 'cols' => ['Den', 'Čas', 'Příspěvků', 'Ø engagement', 'Skóre (100 = nejsilnější okno)'], 'rows' => $slots];
        }

        // Top 5 příspěvků dle engagementu
        $postCols = ['Datum', 'Typ', 'Dosah', 'Reakce/Kom./Sdíl.', 'Engagement', 'Příspěvek'];
        $postRow = static fn (array $p, int $msgWidth): array => [
            $p['date'] ?? '',
            allstat_social_format_label((string) ($p['format'] ?? '')),
            $p['reachLabel'] ?? '—',
            ((int) ($p['reactions'] ?? 0)) . '/' . ((int) ($p['comments'] ?? 0)) . '/' . ((int) ($p['shares'] ?? 0)),
            $p['engagementLabel'] ?? '0',
            mb_strimwidth((string) ($p['message'] ?? ''), 0, $msgWidth, '…'),
        ];
        $payload['sections'][] = ['title' => 'Top 5 příspěvků dle engagementu', 'cols' => $postCols,
            'rows' => array_map(static fn (array $p): array => $postRow($p, 80), $sp['top'] ?? [])];

        // Top podle uložení (IG „saved" = nejsilnější signál kvality obsahu; u FB/LinkedIn prázdné → skryto).
        if (!empty($sp['topSaved'])) {
            $savedRow = static fn (array $p): array => [
                $p['date'] ?? '', allstat_social_format_label((string) ($p['format'] ?? '')),
                ($p['savedLabel'] ?? '—'), $p['engagementLabel'] ?? '0', $p['reachLabel'] ?? '—',
                mb_strimwidth((string) ($p['message'] ?? ''), 0, 80, '…'),
            ];
            $payload['sections'][] = ['title' => 'Top příspěvky podle uložení (saved)', 'cols' => ['Datum', 'Typ', 'Uložení', 'Engagement', 'Dosah', 'Příspěvek'],
                'rows' => array_map($savedRow, $sp['topSaved'])];
        }

        // Kompletní seznam příspěvků za období (chronologicky, nejnovější první) — CELÁ historie zvoleného
        // období; stránkujeme přes všechny stránky se stropem 1000 řádků (pojistka proti extrémně velkým účtům).
        $listRows = [];
        $listTotal = 0;
        $listPage = 1;
        $LIST_MAX = 1000;
        do {
            $chunk = allstat_get_social_posts_page($pdo, $viewSourceId, $start, $end, $listPage, 100);
            $listTotal = (int) $chunk['total'];
            foreach ($chunk['rows'] as $lr) {
                $listRows[] = $lr;
                if (count($listRows) >= $LIST_MAX) { break; }
            }
            $listPage++;
        } while ($listPage <= (int) ($chunk['totalPages'] ?? 1) && count($listRows) < $LIST_MAX);
        if ($listRows) {
            $shown = count($listRows);
            $suffix = $listTotal > $shown ? ' (prvních ' . allstat_number($shown) . ' z ' . allstat_number($listTotal) . ')' : ' (' . allstat_number($listTotal) . ')';
            $payload['sections'][] = ['title' => 'Kompletní seznam příspěvků za období' . $suffix, 'cols' => $postCols,
                'rows' => array_map(static fn (array $p): array => $postRow($p, 70), $listRows)];
        }

        // Srovnání s předchozím obdobím
        if (!empty($sp['deltas'])) {
            $dmap = ['count' => 'Počet příspěvků', 'engagement' => 'Engagement příspěvků', 'reach' => 'Dosah příspěvků',
                'engRate' => 'Míra zapojení příspěvků', 'followers' => 'Přírůstek sledujících'];
            $drows = [];
            foreach ($dmap as $k => $lab) {
                $d = $sp['deltas'][$k] ?? null;
                if (!$d) { continue; }
                $drows[] = [$lab, ($d['trend'] ?? 'none') === 'none' ? '—' : (($d['trend'] === 'up' ? '↑ ' : '↓ ') . ($d['label'] ?? ''))];
            }
            $payload['sections'][] = ['title' => 'Srovnání s předchozím obdobím', 'cols' => ['Metrika', 'Změna'], 'rows' => $drows];
        }

        if (!empty($sp['heatmap']['best'])) {
            $b = $sp['heatmap']['best'];
            $payload['meta']['nejlepsi_cas_publikace'] = ($b['day'] ?? '') . ' ' . ($b['slot'] ?? '') . ' h (Ø ' . allstat_number((float) ($b['avg'] ?? 0), 1) . ' zapojení / příspěvek)';
        }
        if ($reachMissing) {
            $payload['meta']['poznamka_dosah'] = 'Dosah u příspěvků není z API dostupný (chybí oprávnění read_insights nebo je potřeba znovu „Stáhnout historii") → u příspěvků je „—" a nejde spočítat míru zapojení na příspěvek.';
        }
        if ($spanDays > 60 || $network === 'linkedin') {
            $payload['meta']['poznamka_historie'] = $apiNote;
        }
    } else {
        $payload['sections'][] = ['title' => 'Příspěvky', 'cols' => ['Info'], 'rows' => [
            [$network === 'linkedin'
                ? 'Za zvolené období nejsou stažené žádné příspěvky. Spusť u napojení „Stáhnout historii" (LinkedIn stahuje příspěvky jen za období, které sync pokryl).'
                : 'Za zvolené období nejsou stažené žádné příspěvky. Spusť u napojení „Stáhnout historii" (API vrací hlavně nejnovější příspěvky; starší historie nemusí být kompletní).'],
        ]];
    }
}

/**
 * LinkedIn firemní stránka: agregované metriky stránky (Zobrazení/Prokliky/reakce/engagement/míra) +
 * plný rozbor příspěvků (sdílený s FB/IG) + trend zobrazení/prokliků.
 */
function allstat_share_section_linkedin(PDO $pdo, int $domainId, int $viewSourceId, string $start, string $end, string $gran, array &$payload): void
{
    $pv = allstat_get_provider_view($pdo, $domainId, $viewSourceId, $start, $end, $gran, 'linkedin_company');
    $payload['sections'][] = allstat_share_page_metrics_section('LinkedIn, metriky stránky (úroveň celé stránky za období)', $pv);

    $spanDays = (int) floor((strtotime($end) - strtotime($start)) / 86400) + 1;
    allstat_share_append_post_sections($pdo, $viewSourceId, $start, $end, $spanDays, $payload, 'linkedin');

    $wk = allstat_share_weekly_section($pdo, $domainId, $viewSourceId, ['impressions' => 'Zobrazení', 'clicks' => 'Prokliky', 'likes' => 'To se mi líbí', 'page_views' => 'Návštěvy stránky', 'new_follows' => 'Noví sledující'], $start, $end);
    if ($wk) { $payload['sections'][] = $wk; }

    // Oborové benchmarky (Oktopost B2B Q1 2026): naše hodnoty v definicích zdroje + reference.
    $sp2 = allstat_get_social_posts($pdo, $viewSourceId, $start, $end, 1);
    if (!empty($sp2['hasData'])) {
        $bench = allstat_share_benchmark_section('linkedin', [
            'followers' => allstat_share_pv_total($pv, 'followers_total'),
            'newFollows' => allstat_share_pv_total($pv, 'new_follows'),
            'engRate' => allstat_share_pv_total($pv, 'engagement_rate_derived'),
            'posts' => (int) ($sp2['count'] ?? 0),
            'engagement' => (float) ($sp2['engagement'] ?? 0),
            'impressions' => (float) ($sp2['impressions'] ?? 0),
            'periodDays' => $spanDays,
        ]);
        if ($bench) {
            $payload['sections'][] = $bench;
            $payload['meta']['poznamka_benchmarky'] = 'Benchmark Oktopostu měří B2B firemní stránky s mediánem ~15 tis. sledujících; malé stránky mívají přirozeně vyšší míru zapojení a nižší absolutní čísla. Srovnávej vždy v definici uvedené v prvním sloupci.';
        }
    }

    // Cross-provider: skutečné příchody na web z LinkedInu dle GA4 — protiváha širokého clickCount.
    $web = allstat_share_web_from_network($pdo, $domainId, 'linkedin', $start, $end);
    if ($web) {
        $payload['sections'][] = $web;
        $payload['meta']['poznamka_web_ze_site'] = 'LinkedIn clickCount počítá i kliky uvnitř LinkedInu (rozbalení textu, jméno stránky, fotky). Skutečné příchody na web ukazuje sekce „Web z této sítě (GA4)". Velký nepoměr = příspěvky bez odkazů na web, chybějící UTM, nebo kliky nevedoucí ven.';
    }

    // Demografie sledujících (Fáze C) — celoživotní facety, do exportu jako přehledné tabulky pro AI.
    // Datum snímku per facet: obor/země/lokalita závisí na URN resolveru s vlastním denním limitem
    // a umí zamrznout na starším snímku než zbytek — bez data by to nebylo poznat.
    $demo = allstat_get_linkedin_follower_demographics($pdo, $viewSourceId);
    if (!empty($demo['hasData'])) {
        foreach ([['seniority', 'Sledující podle seniority'], ['function', 'Sledující podle funkce'],
                  ['industry', 'Sledující podle oboru'], ['region', 'Sledující podle lokality'],
                  ['country', 'Sledující podle země'], ['staff', 'Sledující podle velikosti firmy']] as [$slot, $title]) {
            if (empty($demo[$slot])) { continue; }
            $snapDate = !empty($demo['dates'][$slot]) ? ', stav k ' . allstat_iso_to_cz_date($demo['dates'][$slot]) : '';
            $payload['sections'][] = ['title' => $title . ' (celoživotní stav' . $snapDate . ')', 'cols' => ['Kategorie', 'Sledující', 'Podíl'],
                'rows' => array_map(static fn (array $r): array => [
                    (string) ($r['label'] ?? ''),
                    allstat_number((int) round((float) ($r['value'] ?? 0))),
                    number_format((float) ($r['share'] ?? 0), 1, ',', ' ') . ' %',
                ], $demo[$slot])];
        }
    }

    // Složení návštěvníků stránky (Fáze C) — celoživotní facety, datum snímku per facet (viz výše).
    $vis = allstat_get_linkedin_visitor_demographics($pdo, $viewSourceId);
    if (!empty($vis['hasData'])) {
        foreach ([['function', 'Návštěvníci podle funkce'], ['seniority', 'Návštěvníci podle seniority'],
                  ['industry', 'Návštěvníci podle oboru'], ['region', 'Návštěvníci podle lokality'],
                  ['staff', 'Návštěvníci podle velikosti firmy']] as [$slot, $title]) {
            if (empty($vis[$slot])) { continue; }
            $snapDate = !empty($vis['dates'][$slot]) ? ', stav k ' . allstat_iso_to_cz_date($vis['dates'][$slot]) : '';
            $payload['sections'][] = ['title' => $title . ' (celoživotní stav' . $snapDate . ')', 'cols' => ['Kategorie', 'Návštěvy', 'Podíl'],
                'rows' => array_map(static fn (array $r): array => [
                    (string) ($r['label'] ?? ''),
                    allstat_number((int) round((float) ($r['value'] ?? 0))),
                    number_format((float) ($r['share'] ?? 0), 1, ',', ' ') . ' %',
                ], $vis[$slot])];
        }
    }

    // Rozpad reakcí podle typu (Fáze C) za období.
    $reactions = allstat_get_linkedin_reactions($pdo, $viewSourceId, $start, $end);
    if (!empty($reactions['hasData'])) {
        $payload['sections'][] = ['title' => 'Reakce podle typu', 'cols' => ['Typ reakce', 'Počet', 'Podíl'],
            'rows' => array_map(static fn (array $r): array => [
                (string) ($r['label'] ?? ''),
                allstat_number((int) round((float) ($r['value'] ?? 0))),
                number_format((float) ($r['share'] ?? 0), 1, ',', ' ') . ' %',
            ], $reactions['rows'])];
    }
}

/**
 * YouTube: metriky kanálu za období (vč. odvozených) + videa publikovaná v období a Top videa celkově
 * (celoživotní čísla, výslovně označené) + podle formátu + zdroje návštěvnosti za období + publikum (snapshot
 * za 90 dní s datem) + vývoj po týdnech.
 */
function allstat_share_section_youtube(PDO $pdo, int $domainId, int $viewSourceId, string $start, string $end, string $gran, array &$payload): void
{
    $pv = allstat_get_provider_view($pdo, $domainId, $viewSourceId, $start, $end, $gran, 'youtube');
    $payload['sections'][] = allstat_share_page_metrics_section('YouTube, metriky kanálu za období (Analytics po dnech; „celkem" položky = stav k poslednímu syncu)', $pv);

    $videoCols = ['Video', 'Publikováno', 'Formát', 'Délka', 'Zhlédnutí', 'Sledovaný čas', 'Ø doba zhlédnutí', '% délky videa', 'Líbí se', 'Komentáře', 'Sdílení', 'Odběry z videa', 'Zapojení %'];
    $videoRow = static fn (array $v): array => [
        $v['title'] . (($v['permalink'] ?? '') !== '' ? ' (' . $v['permalink'] . ')' : ''), $v['date'], $v['formatLabel'], $v['durationLabel'],
        $v['viewsLabel'], $v['watchLabel'], $v['avgLabel'], $v['avgPctLabel'], allstat_number((int) $v['likes']), allstat_number((int) $v['comments']),
        allstat_number((int) $v['shares']), $v['subs'] > 0 ? '+' . allstat_number((int) $v['subs']) : '—', $v['engRateLabel'],
    ];
    $videos = allstat_get_youtube_videos($pdo, $viewSourceId, $start, $end, 10);
    if (!empty($videos['hasData'])) {
        $payload['sections'][] = ['title' => 'Videa publikovaná v období, souhrn (čísla u videí jsou celoživotní stav k poslednímu syncu, ne za období)', 'cols' => ['Metrika', 'Hodnota'], 'rows' => [
            ['Počet publikovaných videí', allstat_number((int) $videos['count'])],
            ['Zhlédnutí těchto videí (celoživotně)', allstat_number((int) $videos['views'])],
            ['Sledovaný čas těchto videí', $videos['watchLabel']],
            ['Ø doba zhlédnutí', $videos['avgLabel']],
            ['To se mi líbí / komentáře / sdílení', allstat_number((int) $videos['likes']) . ' / ' . allstat_number((int) $videos['comments']) . ' / ' . allstat_number((int) $videos['shares'])],
            ['Míra zapojení (líbí se + kom. + sdílení ÷ zhlédnutí)', $videos['engRateLabel']],
            ['Videí na kanálu celkem', allstat_number((int) $videos['allTimeCount'])],
        ]];
        if (count($videos['byFormat'] ?? []) > 1) {
            $payload['sections'][] = ['title' => 'Podle formátu (videa publikovaná v období; Shorts = odhad podle délky ≤ 60 s)', 'cols' => ['Formát', 'Počet', 'Ø zhlédnutí', 'Zhlédnutí celkem', 'Sledovaný čas', 'Engagement'],
                'rows' => array_map(static fn (array $f): array => [$f['label'], allstat_number((int) $f['count']), allstat_number((int) $f['avgViews']), allstat_number((int) $f['views']), $f['watchLabel'], allstat_number((int) $f['engagement'])], $videos['byFormat'])];
        }
        if (!empty($videos['top'])) {
            $payload['sections'][] = ['title' => 'Top videa publikovaná v období (podle zhlédnutí, celoživotní čísla)', 'cols' => $videoCols, 'rows' => array_map($videoRow, $videos['top'])];
        }
        if (!empty($videos['allTime'])) {
            $payload['sections'][] = ['title' => 'Top videa celkově (celý kanál nezávisle na období, evergreen)', 'cols' => $videoCols, 'rows' => array_map($videoRow, $videos['allTime'])];
        }
    }

    // Kategorie = playlisty kanálu: srovnání skupin + kompletní katalog (celoživotní čísla, video ve více
    // playlistech se počítá v každém).
    $catalog = allstat_get_youtube_catalog($pdo, $viewSourceId);
    if (!empty($catalog['hasData'])) {
        $payload['sections'][] = ['title' => 'Kategorie (playlisty kanálu), srovnání skupin (celoživotní čísla všech videí, nezávisle na období)',
            'cols' => ['Playlist', 'Videí', 'Zhlédnutí celkem', 'Ø zhlédnutí / video', 'Sledovaný čas', 'Ø doba zhlédnutí', '% délky videa', 'Zapojení %', 'Nejnovější video'],
            'rows' => array_map(static fn (array $g): array => [$g['title'], allstat_number((int) $g['count']), $g['viewsLabel'], allstat_number((int) $g['avgViews']), $g['watchLabel'], $g['avgLabel'], $g['avgPctLabel'], $g['engRateLabel'], $g['newest']], $catalog['groups'])];
        $payload['sections'][] = ['title' => 'Kompletní katalog videí (' . allstat_number((int) $catalog['count']) . ' videí, podle zhlédnutí; sloupec Playlisty = kategorie)',
            'cols' => array_merge(['Playlisty'], $videoCols),
            'rows' => array_map(static fn (array $v): array => array_merge([$v['playlists'] ? implode(', ', $v['playlists']) : '(nezařazeno)'], $videoRow($v)), $catalog['videos'])];
    }

    $aud = allstat_get_youtube_audience($pdo, $viewSourceId, $start, $end);
    $pct = static fn (float $v): string => number_format($v, 1, ',', ' ') . ' %';
    if (!empty($aud['traffic'])) {
        $payload['sections'][] = ['title' => 'Zdroje návštěvnosti (zhlédnutí za období podle toho, kde divák video našel)', 'cols' => ['Zdroj', 'Zhlédnutí', 'Podíl'],
            'rows' => array_map(static fn (array $r): array => [(string) $r['label'], allstat_number((int) round((float) $r['value'])), $pct((float) ($r['share'] ?? 0))], $aud['traffic'])];
    }
    if (!empty($aud['geo'])) {
        $payload['sections'][] = ['title' => 'Země diváků (zhlédnutí za posledních 90 dní, stav k ' . $aud['geoDate'] . ', ne za zvolené období)', 'cols' => ['Země', 'Zhlédnutí', 'Podíl'],
            'rows' => array_map(static fn (array $r): array => [(string) $r['label'], allstat_number((int) round((float) $r['value'])), $pct((float) ($r['share'] ?? 0))], array_slice($aud['geo'], 0, 12))];
    }
    if (!empty($aud['age']) || !empty($aud['gender'])) {
        $rows = [];
        foreach ($aud['age'] as $r) { $rows[] = ['Věk ' . $r['label'], $pct((float) $r['value'])]; }
        foreach ($aud['gender'] as $r) { $rows[] = [(string) $r['label'], $pct((float) $r['value'])]; }
        $payload['sections'][] = ['title' => 'Věk a pohlaví diváků (% zhlédnutí za posledních 90 dní, stav k ' . $aud['demoDate'] . '; jen přihlášení diváci)', 'cols' => ['Skupina', 'Podíl zhlédnutí'], 'rows' => $rows];
    }

    $wk = allstat_share_weekly_section($pdo, $domainId, $viewSourceId, ['views' => 'Zhlédnutí', 'watch_time_min' => 'Sledovaný čas (min)', 'new_follows' => 'Noví odběratelé', 'unfollows' => 'Odhlášení', 'likes' => 'To se mi líbí', 'comments' => 'Komentáře', 'shares' => 'Sdílení'], $start, $end);
    if ($wk) { $payload['sections'][] = $wk; }

    // Skutečné příchody na web z YouTube dle GA4 (stejný můstek jako u FB/IG/LinkedIn).
    $web = allstat_share_web_from_network($pdo, $domainId, 'youtube', $start, $end);
    if ($web) {
        $payload['sections'][] = $web;
        $payload['meta']['poznamka_web_ze_site'] = 'Sekce „Web z této sítě (GA4)" ukazuje, kolik návštěv webu reálně přišlo z YouTube (odkazy v popiscích, kartách, komentářích). Bez UTM se video provoz v GA4 ukáže jen jako referrer youtube.com.';
    }
}

/**
 * Google Ads: KPI (útrata/ROAS/PNO/CPA/CTR/CPC/CPM…) + trend útraty/prokliků/zobrazení/konverzí.
 */
function allstat_share_section_google_ads(PDO $pdo, int $domainId, int $viewSourceId, string $start, string $end, array &$payload): void
{
    $kpis = allstat_get_google_ads_kpis($pdo, $domainId, $viewSourceId, $start, $end);
    $payload['sections'][] = ['title' => 'KPI Google Ads', 'cols' => ['Metrika', 'Hodnota'],
        'rows' => array_map(static fn (array $m): array => [$m['label'] ?? '', $m['displayValue'] ?? ''], $kpis['metrics'] ?? [])];
    if (!empty($kpis['noConversions'])) {
        $payload['meta']['poznamka'] = 'Účet nemá měření hodnoty konverzí, ROAS/PNO/CPA/Konverze proto nejsou; hodnoť CTR/CPC/objem.';
    }
    $wk = allstat_share_weekly_section($pdo, $domainId, $viewSourceId, ['cost' => 'Útrata', 'clicks' => 'Kliknutí', 'impressions' => 'Zobrazení', 'conversions' => 'Konverze'], $start, $end);
    if ($wk) { $payload['sections'][] = $wk; }
}

/**
 * Microsoft Clarity: behaviorální KPI (návštěvy/boti/aktivní čas/scroll/stránky-relace) + breakdowny
 * (top referrery/stránky/prohlížeče/smart events) + trend návštěv/botů.
 */
function allstat_share_section_clarity(PDO $pdo, int $domainId, int $viewSourceId, string $start, string $end, array &$payload): void
{
    $kpis = allstat_get_clarity_kpis($pdo, $domainId, $viewSourceId, $start, $end);
    $payload['sections'][] = ['title' => 'KPI Clarity', 'cols' => ['Metrika', 'Hodnota'],
        'rows' => array_map(static fn (array $m): array => [$m['label'] ?? '', $m['displayValue'] ?? ''], $kpis['metrics'] ?? [])];

    foreach (allstat_get_clarity_breakdowns($pdo, $domainId, $viewSourceId, $start, $end, 12) as $sec) {
        if (empty($sec['items'])) { continue; }
        $payload['sections'][] = ['title' => (string) ($sec['title'] ?? ''), 'cols' => ['Název', 'Relace', 'Podíl'],
            'rows' => array_map(static fn (array $it): array => [$it['name'] ?? '', $it['sessionsLabel'] ?? allstat_number((int) ($it['sessions'] ?? 0)), $it['shareLabel'] ?? ''], $sec['items'])];
    }

    $wk = allstat_share_weekly_section($pdo, $domainId, $viewSourceId, ['sessions' => 'Návštěvy', 'bot_sessions' => 'Boti'], $start, $end);
    if ($wk) { $payload['sections'][] = $wk; }
}

/**
 * Seznam Webmaster: STAV indexace k poslednímu dni období (zaindexováno/chyby/přesměrování/známé stránky).
 * Bez týdenního součtu — hodnoty jsou snapshot (state), sčítat je přes období nedává smysl.
 */
function allstat_share_section_seznam(PDO $pdo, int $domainId, int $viewSourceId, string $start, string $end, array &$payload): void
{
    $kpis = allstat_get_seznam_wmt_kpis($pdo, $domainId, $viewSourceId, $start, $end);
    $payload['sections'][] = ['title' => 'Seznam Webmaster, indexace (nejnovější stav)', 'cols' => ['Metrika', 'Hodnota'],
        'rows' => array_map(static fn (array $m): array => [$m['label'] ?? '', $m['displayValue'] ?? ''], $kpis['metrics'] ?? [])];
    $payload['meta']['poznamka'] = 'Hodnoty jsou STAV indexace k poslednímu dni období (ne součet). Jde o indexaci na Seznam.cz, ne o kliknutí/dotazy.';
}

function allstat_share_section_generic(PDO $pdo, int $domainId, int $viewSourceId, string $start, string $end, string $gran, string $providerKey, array &$payload): void
{
    $pv = allstat_get_provider_view($pdo, $domainId, $viewSourceId, $start, $end, $gran, $providerKey);
    $payload['sections'][] = allstat_share_page_metrics_section('Metriky zdroje', $pv);
}

/** Počet uzavřených měsíců okna Růstu kanálů z uloženého start/end (3 / 6 / 12). */
function allstat_share_growth_months(string $start, string $end): int
{
    $s = new DateTimeImmutable($start);
    $e = new DateTimeImmutable($end);

    return ((int) $e->format('Y') * 12 + (int) $e->format('n')) - ((int) $s->format('Y') * 12 + (int) $s->format('n')) + 1;
}

/**
 * Report pohledu Růst kanálů: souhrn růstu, měsíční součty, změny m/m a pokrytí dat. Počítá se stejně
 * jako na dashboardu (lib/growth.php), okno končí posledním měsícem reportu (ne dneškem).
 */
function allstat_share_section_growth(PDO $pdo, int $domainId, string $start, string $end, array &$payload): void
{
    $g = allstat_get_channel_growth($pdo, $domainId, allstat_share_growth_months($start, $end), (new DateTimeImmutable($end))->modify('first day of next month'));
    $w = $g['window'];
    $name = static fn (array $c): string => $c['name'] . ($c['account'] !== '' ? ' (' . $c['account'] . ')' : '');
    $growthTxt = static fn (array $c): string => match ($c['growthState']) {
        'up', 'down' => allstat_growth_pct($c['growth']),
        'stable' => 'stabilní (' . allstat_growth_pct($c['growth']) . ')',
        'volatile' => 'kolísá, směr nelze určit (typický měsíc ' . allstat_growth_pct($c['growth']) . ', průměr ' . allstat_growth_pct($c['meanGrowth'])
            . '; mimořádný měsíc: ' . ($c['spike'] ?? '?') . ')',
        'new' => 'nově (na začátku období nulové)',
        default => '– (málo dat)',
    };
    $how = $w['k'] === 1 ? 'poslední vs. první měsíc' : 'typický měsíc (medián) posledních 3 měsíců vs. prvních 3';

    // Meziročně: stejné 3 měsíce loni (stejná sezóna); stejné stavy jako hlavní růst.
    $yoyTxt = static fn (array $c): string => $c['yoy'] === null ? '– (zatím bez loňských dat)' : match ($c['yoy']['state']) {
        'up', 'down' => allstat_growth_pct($c['yoy']['growth']),
        'stable' => 'stabilní (' . allstat_growth_pct($c['yoy']['growth']) . ')',
        default => 'kolísá (typický měsíc ' . allstat_growth_pct($c['yoy']['growth']) . ', průměr ' . allstat_growth_pct($c['yoy']['mean']) . ')',
    };
    $rows = [];
    foreach ($g['channels'] as $c) {
        $last = $c['last'];
        $rows[] = [
            $name($c),
            $c['metric'],
            ($last['value'] === null ? '–' : allstat_number($last['value'])) . ' (' . $last['longLabel'] . ')',
            $growthTxt($c),
            $yoyTxt($c),
            $c['mom'] === null ? '–' : allstat_growth_pct($c['mom']),
            $c['extra'] !== '' ? $c['extra'] : '–',
        ];
    }
    $payload['sections'][] = [
        'title' => 'Růst kanálů (' . $how . ', přepočteno na den s daty; meziročně = ' . $w['yoyLabel'] . ')',
        'cols' => ['Kanál', 'Hlavní metrika', 'Poslední uzavřený měsíc', 'Růst za období', 'Meziročně (stejná sezóna)', 'Změna m/m', 'Sledující / útrata / rozpětí'],
        'rows' => $rows,
    ];

    $monthCols = array_map(static fn (string $ym): string => allstat_growth_month_label($ym), $w['months']);
    $valRows = [];
    $momRows = [];
    $coverage = [];
    foreach ($g['channels'] as $c) {
        $vals = [$name($c) . ', ' . $c['short']];
        $moms = [$name($c)];
        foreach ($c['months'] as $e) {
            $vals[] = $e['value'] === null ? '–' : allstat_number($e['value']) . ($e['partial'] ? '*' : '');
            $moms[] = $e['mom'] === null ? '–' : allstat_growth_pct($e['mom']);
            if ($e['partial']) {
                $coverage[] = [$name($c), $e['longLabel'], $e['days'] . ' z ' . $e['expected']];
            }
        }
        $vals[] = $growthTxt($c);
        $valRows[] = $vals;
        $momRows[] = $moms;
    }
    $payload['sections'][] = ['title' => 'Měsíc po měsíci (měsíční součty hlavní metriky; * = data jen za část dní)', 'cols' => array_merge(['Kanál'], $monthCols, ['Růst']), 'rows' => $valRows];
    $payload['sections'][] = ['title' => 'Změna m/m (z průměru na den s daty, srovnatelné i u neúplných měsíců)', 'cols' => array_merge(['Kanál'], $monthCols), 'rows' => $momRows];
    if ($coverage) {
        $payload['sections'][] = ['title' => 'Pokrytí dat (měsíce, kdy zdroj nesbíral data každý den)', 'cols' => ['Kanál', 'Měsíc', 'Dní s daty'], 'rows' => $coverage];
    }

    // Stejně jako věta nahoře na dashboardu: u nejlepšího/nejhoršího kanálu i meziroční číslo (stejná sezóna).
    $yoyNote = static fn (array $c): string => $c['yoy'] === null ? '' : '; meziročně ' . $yoyTxt($c) . ' (' . $w['yoyLabel'] . ')';
    if ($g['best']) {
        $payload['recommendations'][] = ['level' => 'good', 'title' => 'Nejrychleji roste ' . $name($g['best']), 'detail' => 'růst ' . allstat_growth_pct($g['best']['growth']) . ' (' . $how . ')' . $yoyNote($g['best']) . '.'];
    }
    if ($g['worst']) {
        $payload['recommendations'][] = ['level' => 'bad', 'title' => 'Nejvíc klesá ' . $name($g['worst']), 'detail' => 'růst ' . allstat_growth_pct($g['worst']['growth']) . ' (' . $how . ')' . $yoyNote($g['worst']) . '.'];
    }
    $payload['recommendations'][] = ['level' => 'info', 'title' => 'Metodika', 'detail' => 'Počítají se jen uzavřené měsíce. Růst = typický měsíc (medián průměrů na den), jeden mimořádný měsíc (kampaň, výpadek) ho neotočí; u reklamy průměr (dny bez kampaně jsou skutečné nuly). Změna do ±10 % = stabilní. „Kolísá" = průměr a medián ukazují opačný směr a změna typického měsíce je pod 50 %, trend z dat nelze spolehlivě určit. Meziročně = typický měsíc posledních 3 uzavřených měsíců proti stejným měsícům loni (stejná sezóna, bez vlivu léta); jen kde jsou loňská data, měsíce s nulou před startem kanálu se nepočítají. Při hodnocení vývoje dávej meziročnímu srovnání přednost před srovnáním různých sezón. Dosah na Facebooku a Instagramu je součet denního dosahu (ne unikátní lidé za měsíc). Meta Ads = kliknutí na placené reklamy, jejich vývoj řídí rozpočet.'];
}

function allstat_share_to_md(array $payload): string
{
    $md = '# ' . ($payload['title'] ?? 'AllStat report') . "\n\n";
    $md .= '> ' . str_replace("\n", "\n> ", (string) ($payload['instructions'] ?? '')) . "\n\n";
    $md .= "## Kontext\n";
    foreach (($payload['meta'] ?? []) as $k => $v) {
        if ((string) $v === '') { continue; }
        $md .= '- **' . ucfirst(str_replace('_', ' ', (string) $k)) . ':** ' . $v . "\n";
    }
    $md .= "\n";
    foreach (($payload['sections'] ?? []) as $sec) {
        $md .= '## ' . ($sec['title'] ?? '') . "\n";
        $md .= allstat_md_table($sec['cols'] ?? [], $sec['rows'] ?? []) . "\n";
    }
    if (!empty($payload['recommendations'])) {
        $md .= "## Automatická doporučení (z dat)\n";
        $lvl = ['bad' => '🔴 Problém', 'warn' => '🟠 Pozor', 'good' => '🟢 OK', 'info' => '🔵 Tip'];
        foreach ($payload['recommendations'] as $r) {
            $md .= '- **' . ($lvl[$r['level']] ?? '•') . ', ' . ($r['title'] ?? '') . '** ' . ($r['detail'] ?? '') . "\n";
        }
        $md .= "\n";
    }
    $md .= "---\n_Vygenerováno AllStatem. Dočasný odkaz, platnost max 15 minut, pak přestane platit._\n";

    return $md;
}
