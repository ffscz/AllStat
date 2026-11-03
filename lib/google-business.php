<?php

/**
 * Google Business Profile (dříve Moje firma na Googlu): jak lidé našli a použili profil firmy v Hledání a Mapách.
 * Business Profile Performance API v1, OAuth scope business.manage, stejný Google OAuth klient jako GA4 / GSC.
 * https://developers.google.com/my-business/reference/performance/rest
 *
 * POZOR: projekt v Google Cloud má k těmto API kvótu 0, dokud Google neschválí žádost o přístup („Application
 * for Basic API Access“, profil ověřený aspoň 60 dní a s webem). Do té doby vrací API 403 / 429 a AllStat to
 * hlásí srozumitelně (allstat_google_business_error).
 *
 *  - locations/{id}:fetchMultiDailyMetricsTimeSeries → denní hodnoty po metrikách. Zobrazení profilu jsou
 *    „různí uživatelé za den“ (sčítat přes dny lze, je to denní dosah). Den bez hodnoty API vynechá = 0.
 *    Klíče: impressions_{search|maps}_{desktop|mobile}, součty impressions_search, impressions_maps, impressions,
 *    website_clicks, call_clicks, direction_requests, bookings, menu_clicks, messages.
 *  - locations/{id}/searchkeywords/impressions/monthly → hledané výrazy za MĚSÍC (součet za rozsah měsíců, proto
 *    se volá po jednom uzavřeném měsíci). Klíč keyword_impressions, dimension = výraz, datum = 1. den měsíce.
 *    Pod prahem Google číslo neprozradí, vrátí jen „méně než N“: uloží se N a keyword_threshold = 1.
 *    Přes měsíce se výrazy nesčítají (různí lidé).
 *  - Bez Location ID ve formuláři se najde první profil účtu (Account Management + Business Information API).
 */

const ALLSTAT_GBP_PERF_BASE = 'https://businessprofileperformance.googleapis.com';

/** API metrika → lokální klíč. */
function allstat_google_business_metrics(): array
{
    return [
        'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH' => 'impressions_search_desktop',
        'BUSINESS_IMPRESSIONS_MOBILE_SEARCH' => 'impressions_search_mobile',
        'BUSINESS_IMPRESSIONS_DESKTOP_MAPS' => 'impressions_maps_desktop',
        'BUSINESS_IMPRESSIONS_MOBILE_MAPS' => 'impressions_maps_mobile',
        'WEBSITE_CLICKS' => 'website_clicks',
        'CALL_CLICKS' => 'call_clicks',
        'BUSINESS_DIRECTION_REQUESTS' => 'direction_requests',
        'BUSINESS_BOOKINGS' => 'bookings',
        'BUSINESS_FOOD_MENU_CLICKS' => 'menu_clicks',
        'BUSINESS_CONVERSATIONS' => 'messages',
    ];
}

function allstat_google_business_error(array $resp): string
{
    $status = (int) ($resp['status'] ?? 0);
    $message = (string) ($resp['json']['error']['message'] ?? mb_substr((string) ($resp['body'] ?? ''), 0, 160));
    $lower = strtolower($message);

    return match (true) {
        str_contains($lower, 'has not been used') || str_contains($lower, 'is disabled') || str_contains($lower, 'accessnotconfigured')
            => 'Google Business Profile: v projektu Google Cloud nejsou povolená API „Business Profile Performance API“, „My Business Account Management API“ a „My Business Business Information API“. Povol je a zkus to znovu.',
        $status === 429 || str_contains($lower, 'quota')
            => 'Google Business Profile: projekt zatím nemá schválený přístup k API (kvóta 0). Požádej Google o přístup formulářem „Application for Basic API Access“ (návod u napojení), schválení trvá řádově dny.',
        $status === 401 => 'Google Business Profile: přihlášení vypršelo, spusť OAuth znovu.',
        $status === 403 => 'Google Business Profile: účet nemá přístup k profilu firmy, nebo projekt ještě nemá schválený přístup k API (HTTP 403). ' . $message,
        $status === 404 => 'Google Business Profile: profil (Location ID) neexistuje nebo k němu účet nemá přístup.',
        default => 'Google Business Profile API HTTP ' . $status . ($message !== '' ? ': ' . $message : ''),
    };
}

/** „locations/123“, „accounts/1/locations/123“ i holé číslo → „123“. */
function allstat_google_business_location_id(string $raw): string
{
    return preg_match('#(?:^|/)locations/(\d+)#', $raw, $m) ? $m[1] : (ctype_digit(trim($raw)) ? trim($raw) : '');
}

/**
 * Najde profily (lokace) přihlášeného účtu: [['id' => '123', 'title' => 'Firma']]. Prázdné pole = účet žádný nemá.
 */
function allstat_google_business_locations(string $token): array
{
    $headers = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
    $resp = allstat_http_request('GET', 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts?pageSize=20', $headers, null, 30);
    if ($resp['status'] !== 200) {
        throw new RuntimeException(allstat_google_business_error($resp));
    }
    $out = [];
    foreach (array_slice($resp['json']['accounts'] ?? [], 0, 20) as $account) {
        $name = (string) ($account['name'] ?? '');
        if (!preg_match('#^accounts/\d+$#', $name)) { continue; }
        $lresp = allstat_http_request('GET', 'https://mybusinessbusinessinformation.googleapis.com/v1/' . $name . '/locations?readMask=name,title&pageSize=100', $headers, null, 30);
        if ($lresp['status'] !== 200) {
            throw new RuntimeException(allstat_google_business_error($lresp));
        }
        foreach ($lresp['json']['locations'] ?? [] as $location) {
            $id = allstat_google_business_location_id((string) ($location['name'] ?? ''));
            if ($id !== '') {
                $out[] = ['id' => $id, 'title' => (string) ($location['title'] ?? $id)];
            }
        }
    }

    return $out;
}

/** Location ID z napojení, jinak první profil účtu (uloží se do napojení i s názvem). */
function allstat_google_business_resolve_location(PDO $pdo, array $connection, string $token): string
{
    $id = allstat_google_business_location_id((string) ($connection['property_id'] ?? ''));
    if ($id !== '') {
        return $id;
    }
    $locations = allstat_google_business_locations($token);
    if (!$locations) {
        throw new RuntimeException('Google Business Profile: přihlášený účet nespravuje žádný profil firmy. Spusť OAuth účtem, který je vlastník nebo správce profilu.');
    }
    $pdo->prepare("UPDATE domain_sources SET property_id = ?, account_label = COALESCE(NULLIF(account_label, ''), ?) WHERE id = ?")
        ->execute(['locations/' . $locations[0]['id'], mb_substr($locations[0]['title'], 0, 190), (int) $connection['id']]);

    return $locations[0]['id'];
}

/** Denní řady pro dané metriky: [lokální klíč => [Y-m-d => hodnota]]. */
function allstat_google_business_daily(string $base, string $token, string $locationId, array $apiMetrics, string $startDate, string $endDate): array
{
    [$sy, $sm, $sd] = array_map('intval', explode('-', $startDate));
    [$ey, $em, $ed] = array_map('intval', explode('-', $endDate));
    $query = 'dailyRange.start_date.year=' . $sy . '&dailyRange.start_date.month=' . $sm . '&dailyRange.start_date.day=' . $sd
        . '&dailyRange.end_date.year=' . $ey . '&dailyRange.end_date.month=' . $em . '&dailyRange.end_date.day=' . $ed;
    foreach ($apiMetrics as $metric) {
        $query .= '&dailyMetrics=' . rawurlencode($metric);
    }
    $resp = allstat_http_request('GET', rtrim($base, '/') . '/v1/locations/' . rawurlencode($locationId) . ':fetchMultiDailyMetricsTimeSeries?' . $query,
        ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'], null, 60);
    if ($resp['status'] !== 200) {
        throw new AllstatGoogleBusinessHttp(allstat_google_business_error($resp), (int) $resp['status']);
    }
    $map = allstat_google_business_metrics();
    $out = [];
    foreach ($resp['json']['multiDailyMetricTimeSeries'] ?? [] as $group) {
        foreach ($group['dailyMetricTimeSeries'] ?? [] as $series) {
            $key = $map[(string) ($series['dailyMetric'] ?? '')] ?? null;
            if ($key === null) { continue; }
            foreach ($series['timeSeries']['datedValues'] ?? [] as $dv) {
                $d = $dv['date'] ?? [];
                if (empty($d['year']) || empty($d['month']) || empty($d['day'])) { continue; }
                $date = sprintf('%04d-%02d-%02d', $d['year'], $d['month'], $d['day']);
                $out[$key][$date] = is_numeric($dv['value'] ?? null) ? (float) $dv['value'] : 0.0;
            }
        }
    }

    return $out;
}

final class AllstatGoogleBusinessHttp extends RuntimeException {}

function allstat_engine_google_business(PDO $pdo, array $config, array $connection, string $token, string $startDate, string $endDate, array $recipe): array
{
    // Performance API drží 18 měsíců; starší část „Stáhnout historii“ se přeskočí místo chyby.
    $floor = (new DateTimeImmutable('today'))->modify('-18 months')->modify('+1 day')->format('Y-m-d');
    if ($endDate < $floor) {
        return ['rows' => 0, 'summary' => sprintf('mimo 18 měsíců API (%s → %s), přeskočeno', $startDate, $endDate)];
    }
    $startDate = max($startDate, $floor);
    $base = (string) ($connection['api_base_url'] ?: ALLSTAT_GBP_PERF_BASE);
    $locationId = allstat_google_business_resolve_location($pdo, $connection, $token);
    $apiMetrics = array_keys(allstat_google_business_metrics());

    // Všechny metriky jedním voláním; když API některou odmítne (HTTP 400), zkusí se po jedné a odmítnuté se vynechají.
    $skipped = [];
    try {
        $series = allstat_google_business_daily($base, $token, $locationId, $apiMetrics, $startDate, $endDate);
    } catch (AllstatGoogleBusinessHttp $e) {
        if ($e->getCode() !== 400) {
            throw $e;
        }
        $series = [];
        foreach ($apiMetrics as $metric) {
            try {
                $series += allstat_google_business_daily($base, $token, $locationId, [$metric], $startDate, $endDate);
            } catch (AllstatGoogleBusinessHttp $one) {
                if ($one->getCode() !== 400) { throw $one; }
                $skipped[] = $metric;
            }
        }
    }

    $stmt = allstat_pm_statement($pdo);
    $domainId = (int) $connection['domain_id'];
    $sourceId = (int) $connection['source_id'];
    $connectionId = (int) $connection['id'];
    $written = 0;
    // Dny bez hodnoty API vynechává (= 0): zapíše se nula, aby po opravě dat nezůstala stará hodnota.
    // Jen do posledního dne, pro který API vrátilo aspoň jednu hodnotu (bez ní do dneška minus 6 dní):
    // Google dny doplňuje se zpožděním a nuly za ještě nevykázané dny by vypadaly jako propad.
    $lastWithData = '';
    foreach ($series as $byDate) {
        foreach (array_keys($byDate) as $d) {
            $lastWithData = max($lastWithData, (string) $d);
        }
    }
    if ($lastWithData === '') {
        $lastWithData = (new DateTimeImmutable('today'))->modify('-6 days')->format('Y-m-d');
    }
    $day = new DateTimeImmutable($startDate);
    $last = new DateTimeImmutable(min($endDate, $lastWithData, (new DateTimeImmutable('today'))->format('Y-m-d')));
    $pdo->beginTransaction();
    try {
        for (; $day <= $last; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $v = static fn (string $k): float => (float) ($series[$k][$date] ?? 0.0);
            $values = [];
            foreach (allstat_google_business_metrics() as $localKey) {
                if (isset($series[$localKey])) { $values[$localKey] = $v($localKey); }
            }
            $values['impressions_search'] = $v('impressions_search_desktop') + $v('impressions_search_mobile');
            $values['impressions_maps'] = $v('impressions_maps_desktop') + $v('impressions_maps_mobile');
            $values['impressions'] = $values['impressions_search'] + $values['impressions_maps'];
            foreach ($values as $key => $value) {
                if (allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $date, $key, $value)) { $written++; }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }

    // Hledané výrazy po uzavřených měsících okna. Best-effort, chyba nesmí zahodit denní čísla.
    $keywordRows = 0;
    $note = $skipped ? '; API nevrací ' . implode(', ', $skipped) : '';
    try {
        // Do poloviny měsíce se zkouší i předchozí měsíc: Google měsíční výrazy publikuje se zpožděním.
        $keywordStart = $startDate;
        if ((int) date('j') <= 15) {
            $keywordStart = min($keywordStart, (new DateTimeImmutable('first day of last month'))->format('Y-m-d'));
        }
        $keywordRows = allstat_google_business_keywords($pdo, $base, $token, $locationId, $connection, max($keywordStart, $floor), $endDate);
    } catch (Throwable $e) {
        $note .= '; výrazy: ' . mb_substr($e->getMessage(), 0, 80);
    }

    return ['rows' => $written + $keywordRows, 'summary' => sprintf('%d denních hodnot, %d hledaných výrazů%s (%s → %s)', $written, $keywordRows, $note, $startDate, $endDate)];
}

/** Hledané výrazy za každý UZAVŘENÝ měsíc, do kterého zasahuje okno [start, end]. Vrací počet uložených řádků. */
function allstat_google_business_keywords(PDO $pdo, string $base, string $token, string $locationId, array $connection, string $startDate, string $endDate): int
{
    $currentMonth = (new DateTimeImmutable('first day of this month'))->format('Y-m');
    $month = new DateTimeImmutable(substr($startDate, 0, 7) . '-01');
    $lastMonth = substr($endDate, 0, 7);
    $domainId = (int) $connection['domain_id'];
    $sourceId = (int) $connection['source_id'];
    $connectionId = (int) $connection['id'];
    $stmt = allstat_pm_statement($pdo);
    $written = 0;
    for ($guard = 0; $month->format('Y-m') <= $lastMonth && $month->format('Y-m') < $currentMonth && $guard < 24; $month = $month->modify('+1 month'), $guard++) {
        $y = (int) $month->format('Y');
        $m = (int) $month->format('n');
        $range = 'monthlyRange.start_month.year=' . $y . '&monthlyRange.start_month.month=' . $m . '&monthlyRange.end_month.year=' . $y . '&monthlyRange.end_month.month=' . $m;
        $rows = [];
        $pageToken = '';
        for ($page = 0; $page < 10; $page++) {
            $resp = allstat_http_request('GET', rtrim($base, '/') . '/v1/locations/' . rawurlencode($locationId) . '/searchkeywords/impressions/monthly?' . $range . '&pageSize=100'
                . ($pageToken !== '' ? '&pageToken=' . rawurlencode($pageToken) : ''), ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'], null, 60);
            if ($resp['status'] !== 200) {
                throw new RuntimeException(allstat_google_business_error($resp));
            }
            foreach ($resp['json']['searchKeywordsCounts'] ?? [] as $row) {
                $keyword = mb_substr(trim((string) ($row['searchKeyword'] ?? '')), 0, 120);
                if ($keyword === '') { continue; }
                $value = $row['insightsValue']['value'] ?? null;
                $threshold = $row['insightsValue']['threshold'] ?? null;
                $rows[$keyword] = is_numeric($value) ? [(float) $value, false] : [(float) (is_numeric($threshold) ? $threshold : 0), true];
            }
            $pageToken = (string) ($resp['json']['nextPageToken'] ?? '');
            if ($pageToken === '') { break; }
        }
        $date = $month->format('Y-m-d');
        if (!$rows) {
            continue; // měsíc ještě není zveřejněný nebo je prázdný: dřív stažená data nepřepisovat
        }
        $pdo->prepare("DELETE FROM provider_metrics_daily WHERE domain_id = ? AND connection_id = ? AND metric_date = ? AND metric_key IN ('keyword_impressions', 'keyword_threshold')")
            ->execute([$domainId, $connectionId, $date]);
        foreach ($rows as $keyword => [$value, $belowThreshold]) {
            allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $date, 'keyword_impressions', $value, (string) $keyword);
            if ($belowThreshold) {
                allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $date, 'keyword_threshold', 1.0, (string) $keyword);
            }
            $written++;
        }
    }

    return $written;
}

/** Test napojení: najde profil a stáhne včerejší zobrazení (ověří i schválený přístup k Performance API). */
function allstat_google_business_test(PDO $pdo, array $connection, string $token): array
{
    try {
        $locationId = allstat_google_business_resolve_location($pdo, $connection, $token);
        $day = (new DateTimeImmutable('-3 days'))->format('Y-m-d');
        allstat_google_business_daily((string) ($connection['api_base_url'] ?: ALLSTAT_GBP_PERF_BASE), $token, $locationId, ['WEBSITE_CLICKS'], $day, $day);
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }

    return ['ok' => true, 'http' => 200, 'message' => t('Google Business Profile odpovídá: profil {id}, přístup k Performance API je schválený.', ['id' => 'locations/' . $locationId])];
}
