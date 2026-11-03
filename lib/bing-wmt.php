<?php

/**
 * Bing Webmaster Tools: výkon webu ve vyhledávání Bing (kliknutí, zobrazení, dotazy, stránky) a procházení
 * (crawl, indexace, zpětné odkazy). https://learn.microsoft.com/bingwebmaster/
 *
 * JSON varianta API (GET https://ssl.bing.com/webmaster/api.svc/json/<Metoda>?apikey=&siteUrl=). SOAP a POX
 * Microsoft ukončil 31. 8. 2026, JSON zůstává se stejnými metodami a klíči. API klíč je na uživatele Bing
 * (Nastavení → Přístup k API), platí pro všechny jeho ověřené weby; OAuth se nepoužívá.
 *
 * Metody nemají parametr období, vrací celou historii, kterou Bing drží (zhruba 6 měsíců). Proto se při
 * každém běhu ukládá všechno, co přijde (recept single_shot), a AllStat si historii skládá dál sám:
 *  - GetRankAndTrafficStats → denní clicks, impressions (aditivní),
 *  - GetCrawlStats → denní crawled_pages, crawl_errors, http_2xx/3xx/4xx/5xx, blocked_robots (aditivní)
 *    a stavové in_index, inlinks (počet stránek v indexu a příchozích odkazů k danému dni, NESČÍTAT),
 *  - GetQueryStats / GetPageStats → TÝDENNÍ řádky top dotazů a stránek (dimension = dotaz / adresa):
 *    query_clicks, query_impressions, query_position (průměrná pozice při zobrazení, vážit zobrazeními),
 *    page_clicks, page_impressions, page_position. Datum řádku = den, ke kterému ho Bing vykazuje.
 * Datum chodí ve formátu WCF „/Date(1316156400000-0700)/“ (ms od epochy + posun zóny Bing); den se bere
 * v té zóně, jinak by se posunul o den.
 */

const ALLSTAT_BING_WMT_BASE = 'https://ssl.bing.com/webmaster/api.svc/json';
/** Kolik nejlepších dotazů a stránek (podle zobrazení) se ukládá za každý týden. */
const ALLSTAT_BING_WMT_TOP = 200;

/** Datum WCF „/Date(ms±hhmm)/“ → Y-m-d v zóně, kterou Bing uvedl. Neplatné = ''. */
function allstat_bing_wmt_date(mixed $value): string
{
    if (!is_string($value) || !preg_match('#/Date\((-?\d+)([+-])(\d{2})(\d{2})\)/#', $value, $m)) {
        if (is_string($value) && preg_match('#/Date\((-?\d+)\)/#', $value, $m2)) {
            return gmdate('Y-m-d', intdiv((int) $m2[1], 1000));
        }
        return '';
    }
    $offset = ((int) $m[3] * 3600 + (int) $m[4] * 60) * ($m[2] === '-' ? -1 : 1);

    return gmdate('Y-m-d', intdiv((int) $m[1], 1000) + $offset);
}

function allstat_bing_wmt_call(string $base, string $key, string $method, array $params = []): array
{
    $url = rtrim($base, '/') . '/' . $method . '?' . http_build_query(['apikey' => $key] + $params);
    $resp = allstat_http_request('GET', $url, ['Accept' => 'application/json'], null, 60);
    if (($resp['status'] ?? 0) !== 200 || !is_array($resp['json'])) {
        throw new RuntimeException(allstat_bing_wmt_error($resp));
    }
    $data = $resp['json']['d'] ?? null;

    return is_array($data) ? $data : [];
}

function allstat_bing_wmt_error(array $resp): string
{
    $status = (int) ($resp['status'] ?? 0);
    $message = (string) ($resp['json']['Message'] ?? $resp['json']['message'] ?? mb_substr((string) ($resp['body'] ?? ''), 0, 160));

    return match (true) {
        stripos($message, 'InvalidApiKey') !== false => 'Bing Webmaster: API klíč neplatí. Vygeneruj nový v Bing Webmaster Tools → Nastavení → Přístup k API.',
        stripos($message, 'NotAuthorized') !== false || stripos($message, 'not verified') !== false || stripos($message, 'UserNotAuthorized') !== false
            => 'Bing Webmaster: web není v účtu ověřený nebo k němu klíč nemá přístup. Zkontroluj adresu webu (musí přesně odpovídat webu v Bing Webmaster Tools).',
        $status === 429 => 'Bing Webmaster: vyčerpán limit API (HTTP 429), zkus to později.',
        default => 'Bing Webmaster API HTTP ' . $status . ($message !== '' ? ': ' . $message : ''),
    };
}

function allstat_engine_bing_wmt(PDO $pdo, array $config, array $connection, string $token, string $startDate, string $endDate, array $recipe): array
{
    $siteUrl = trim((string) ($connection['property_id'] ?? ''));
    if (trim($token) === '' || $siteUrl === '') {
        throw new RuntimeException('Bing Webmaster: vyplň adresu webu a API klíč.');
    }
    $base = (string) ($connection['api_base_url'] ?: ALLSTAT_BING_WMT_BASE);
    $params = ['siteUrl' => $siteUrl];
    $traffic = allstat_bing_wmt_call($base, $token, 'GetRankAndTrafficStats', $params);
    $crawl = allstat_bing_wmt_call($base, $token, 'GetCrawlStats', $params);
    // Dotazy a stránky jsou bonus: chyba tady nesmí zahodit denní čísla výše.
    $note = '';
    try {
        $queries = allstat_bing_wmt_call($base, $token, 'GetQueryStats', $params);
        $pages = allstat_bing_wmt_call($base, $token, 'GetPageStats', $params);
    } catch (Throwable $e) {
        $queries = $pages = [];
        $note = '; dotazy: ' . mb_substr($e->getMessage(), 0, 80);
    }

    $stmt = allstat_pm_statement($pdo);
    $domainId = (int) $connection['domain_id'];
    $sourceId = (int) $connection['source_id'];
    $connectionId = (int) $connection['id'];
    $num = static fn ($v): float => is_numeric($v) ? (float) $v : 0.0;
    $written = 0;
    $days = [];

    $pdo->beginTransaction();
    try {
        foreach ($traffic as $row) {
            $date = allstat_bing_wmt_date($row['Date'] ?? null);
            foreach (['clicks' => 'Clicks', 'impressions' => 'Impressions'] as $key => $field) {
                if (allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $date, $key, $num($row[$field] ?? null))) { $written++; $days[$date] = true; }
            }
        }
        foreach ($crawl as $row) {
            $date = allstat_bing_wmt_date($row['Date'] ?? null);
            $values = [
                'crawled_pages' => $num($row['CrawledPages'] ?? null),
                'crawl_errors' => $num($row['CrawlErrors'] ?? null),
                'http_2xx' => $num($row['Code2xx'] ?? null),
                'http_3xx' => $num($row['Code301'] ?? null) + $num($row['Code302'] ?? null),
                'http_4xx' => $num($row['Code4xx'] ?? null),
                'http_5xx' => $num($row['Code5xx'] ?? null),
                'blocked_robots' => $num($row['BlockedByRobotsTxt'] ?? null),
                'in_index' => $num($row['InIndex'] ?? null),
                'inlinks' => $num($row['InLinks'] ?? null),
            ];
            foreach ($values as $key => $value) {
                if (allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $date, $key, $value)) { $written++; }
            }
        }

        // Dotazy a stránky: dny, které API vrátilo, se nejdřív smažou (jinak by zůstaly dotazy, které vypadly z top N).
        $dimRows = 0;
        foreach (['query' => $queries, 'page' => $pages] as $prefix => $rows) {
            $byDate = [];
            foreach ($rows as $row) {
                $date = allstat_bing_wmt_date($row['Date'] ?? null);
                $label = mb_substr(trim((string) ($row['Query'] ?? '')), 0, 120);
                if ($date === '' || $label === '') { continue; }
                $impressions = $num($row['Impressions'] ?? null);
                $agg = $byDate[$date][$label] ?? ['clicks' => 0.0, 'impressions' => 0.0, 'posw' => 0.0];
                $position = $num($row['AvgImpressionPosition'] ?? null);
                $agg['clicks'] += $num($row['Clicks'] ?? null);
                $agg['impressions'] += $impressions;
                $agg['posw'] += $position > 0 ? $position * $impressions : 0.0;
                $byDate[$date][$label] = $agg;
            }
            if (!$byDate) { continue; }
            $placeholders = implode(',', array_fill(0, count($byDate), '?'));
            $pdo->prepare("DELETE FROM provider_metrics_daily WHERE domain_id = ? AND connection_id = ? AND metric_key IN (?, ?, ?) AND metric_date IN ($placeholders)")
                ->execute(array_merge([$domainId, $connectionId, $prefix . '_clicks', $prefix . '_impressions', $prefix . '_position'], array_keys($byDate)));
            foreach ($byDate as $date => $labels) {
                uasort($labels, static fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions']);
                foreach (array_slice($labels, 0, ALLSTAT_BING_WMT_TOP, true) as $label => $agg) {
                    allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, (string) $date, $prefix . '_clicks', $agg['clicks'], (string) $label);
                    allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, (string) $date, $prefix . '_impressions', $agg['impressions'], (string) $label);
                    if ($agg['impressions'] > 0 && $agg['posw'] > 0) {
                        allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, (string) $date, $prefix . '_position', round($agg['posw'] / $agg['impressions'], 2), (string) $label);
                    }
                    $dimRows++;
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }

    $span = $days ? min(array_keys($days)) . ' → ' . max(array_keys($days)) : 'bez dat';

    return ['rows' => $written + $dimRows, 'summary' => sprintf('%d denních hodnot, %d řádků dotazů a stránek%s (%s)', $written, $dimRows, $note, $span)];
}

/** Test napojení: seznam webů účtu (GetUserSites) a kontrola, že zadaný web v něm je a je ověřený. */
function allstat_bing_wmt_test(array $connection, string $token): array
{
    $siteUrl = trim((string) ($connection['property_id'] ?? ''));
    if (trim($token) === '' || $siteUrl === '') {
        return ['ok' => false, 'message' => t('Vyplň adresu webu a API klíč Bing Webmaster Tools a ulož napojení.')];
    }
    try {
        $sites = allstat_bing_wmt_call((string) ($connection['api_base_url'] ?: ALLSTAT_BING_WMT_BASE), $token, 'GetUserSites');
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
    $normalize = static fn (string $url): string => strtolower(rtrim($url, '/'));
    $available = [];
    foreach ($sites as $site) {
        $url = (string) ($site['Url'] ?? '');
        $available[] = $url;
        if ($normalize($url) === $normalize($siteUrl)) {
            return empty($site['IsVerified']) && array_key_exists('IsVerified', $site)
                ? ['ok' => false, 'http' => 200, 'message' => t('Bing Webmaster: web „{url}“ je v účtu, ale není ověřený. Dokonči ověření v Bing Webmaster Tools.', ['url' => $url])]
                : ['ok' => true, 'http' => 200, 'message' => t('Bing Webmaster odpovídá, klíč má přístup k webu „{url}“.', ['url' => $url])];
        }
    }

    return ['ok' => false, 'http' => 200, 'message' => t('Bing Webmaster odpovídá, ale web „{url}“ v účtu není. Dostupné weby: {available}', ['url' => $siteUrl, 'available' => $available ? implode(', ', $available) : t('žádné')])];
}
