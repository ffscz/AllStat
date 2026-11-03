<?php

/**
 * OpenAI Ads (reklamy v ChatGPT): denní výkon účtu a kampaní přes Reporting / Insights API.
 * https://developers.openai.com/ads/reporting – GET {base}/v1/ad_account/insights, autorizace
 * „Authorization: Bearer <Ads API key>“ (klíč je vázaný na jeden reklamní účet, žádný OAuth).
 * Ukládá stejné klíče jako Google a Microsoft Ads (cost, impressions, clicks, conversions, conversion_value
 * + campaign_* s názvem kampaně v dimenzi), takže dashboard, sdílení pro AI, MCP i Růst kanálů je použijí beze změny.
 * Pole se posílají kanonicky ({úroveň}.{metrika}, např. campaign.spend, metadata.readable_time), odpověď je plochá
 * (spend, campaign_id, readable_time). Při HTTP 400 se zkusí užší sada a nakonec plochý tvar z průvodce Reporting.
 * Konverze se počítají v atribučním okně API: výchozí 30 dní po kliknutí a 1 den po zobrazení.
 * Částky chodí v hlavních jednotkách měny účtu (12.50 = 12,50 USD, ne mikro); měnu vrací jen endpoint
 * konverzí (account_currency), ukládá se do allstat_settings jako openai_ads.currency.<napojení>.
 */

const ALLSTAT_OPENAI_ADS_BASE = 'https://api.ads.openai.com';

function allstat_openai_ads_headers(string $key): array
{
    return ['Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json'];
}

/**
 * Sady polí od nejširší: kanonické s konverzemi, kanonické bez nich, ploché z průvodce Reporting.
 * Dokumentace (Insights) uvádí jen kanonické názvy a konverze mezi poli GET výslovně nevyjmenovává.
 */
function allstat_openai_ads_field_sets(bool $withConversions = true): array
{
    $base = ['metadata.readable_time', 'campaign.id', 'campaign.name', 'campaign.impressions', 'campaign.clicks', 'campaign.spend'];
    $flat = ['readable_time', 'campaign_id', 'campaign_name', 'impressions', 'clicks', 'spend'];

    return $withConversions
        ? [array_merge($base, ['campaign.conversions', 'campaign.order_created_attributed_sales']), $base, array_merge($flat, ['conversions', 'order_created_attributed_sales'])]
        : [$base, $flat];
}

/**
 * GET insights s poli a časovým oknem; stránkuje přes after = last_id, dokud has_more. Vrací řádky.
 * $fieldSets: když první stránka skončí HTTP 400 (neznámé pole), zkusí se další sada.
 */
function allstat_openai_ads_insights(string $base, string $key, string $startDate, string $endDate, array $fieldSets, string $granularity = 'daily'): array
{
    $lastError = null;
    foreach ($fieldSets as $fields) {
        try {
            return allstat_openai_ads_insights_pages($base, $key, $startDate, $endDate, $fields, $granularity);
        } catch (AllstatOpenAiAdsBadRequest $e) {
            $lastError = $e;
        }
    }
    throw new RuntimeException($lastError ? $lastError->getMessage() : 'OpenAI Ads: žádná sada polí.');
}

final class AllstatOpenAiAdsBadRequest extends RuntimeException {}

function allstat_openai_ads_insights_pages(string $base, string $key, string $startDate, string $endDate, array $fields, string $granularity): array
{
    $range = json_encode(['type' => 'date_range', 'since' => $startDate, 'until' => $endDate, 'timezone' => 'Europe/Prague']);
    $query = 'aggregation_level=campaign&time_granularity=' . rawurlencode($granularity) . '&time_ranges[]=' . rawurlencode((string) $range) . '&limit=2000';
    foreach ($fields as $f) {
        $query .= '&fields[]=' . rawurlencode($f);
    }
    $rows = [];
    $after = '';
    $seen = [];
    for ($page = 0; ; $page++) {
        if ($page >= 50) {
            throw new RuntimeException('OpenAI Ads: odpověď má víc než 50 stránek, zkrať období.'); // radši chyba než oříznutá data
        }
        $url = rtrim($base, '/') . '/v1/ad_account/insights?' . $query . ($after !== '' ? '&after=' . rawurlencode($after) : '');
        $resp = allstat_http_request('GET', $url, allstat_openai_ads_headers($key), null, 60);
        if (($resp['status'] ?? 0) !== 200) {
            if (($resp['status'] ?? 0) === 400 && $page === 0) {
                throw new AllstatOpenAiAdsBadRequest(allstat_openai_ads_error($resp)); // zkusí se jiná sada polí
            }
            throw new RuntimeException(allstat_openai_ads_error($resp));
        }
        foreach ($resp['json']['data'] ?? [] as $row) {
            if (is_array($row)) { $rows[] = $row; }
        }
        $after = (string) ($resp['json']['last_id'] ?? '');
        if (empty($resp['json']['has_more']) || $after === '' || isset($seen[$after])) {
            break; // opakovaný kurzor = API stránkování ignoruje, další stránky by jen zdvojily řádky
        }
        $seen[$after] = true;
    }

    return $rows;
}

function allstat_openai_ads_error(array $resp): string
{
    $status = (int) ($resp['status'] ?? 0);
    $msg = (string) ($resp['json']['error']['message'] ?? $resp['json']['message'] ?? mb_substr((string) ($resp['body'] ?? ''), 0, 160));

    return match (true) {
        $status === 401 => 'OpenAI Ads: API klíč neplatí (HTTP 401). ' . $msg,
        // Ověřeno živě 3. 11. 2026: konverzní klíč (Conversions API) vrací u insights „403: Unauthorized to read ads data.“
        $status === 403 => 'OpenAI Ads: klíč nemá přístup k datům reklam (HTTP 403). Použij klíč Správce reklam (Ads Manager API key), ne konverzní klíč.',
        $status === 429 => 'OpenAI Ads: vyčerpán limit API (HTTP 429), zkus to později.',
        default => 'OpenAI Ads API HTTP ' . $status . ': ' . $msg,
    };
}

function allstat_engine_openai_ads(PDO $pdo, array $config, array $connection, string $token, string $startDate, string $endDate, array $recipe): array
{
    if (trim($token) === '') {
        throw new RuntimeException('OpenAI Ads: vyplň API klíč reklamního účtu (Ads API key).');
    }
    // Reporting API drží 365 dní; starší část dávky „Stáhnout historii“ se přeskočí, jinak by chyba API zastavila celý backfill.
    $floor = (new DateTimeImmutable('today'))->modify('-364 days')->format('Y-m-d');
    if ($endDate < $floor) {
        return ['rows' => 0, 'summary' => sprintf('mimo 365 dní API (%s → %s), přeskočeno', $startDate, $endDate)];
    }
    $startDate = max($startDate, $floor);
    $base = (string) ($connection['api_base_url'] ?: ALLSTAT_OPENAI_ADS_BASE);
    $rows = allstat_openai_ads_insights($base, $token, $startDate, $endDate, allstat_openai_ads_field_sets());

    $stmt = allstat_pm_statement($pdo);
    // Kampaně: okno se nejdřív smaže a hodnoty se sčítají (stejný vzor jako Microsoft Ads). Jinak by po přejmenování
    // kampaně zůstaly staré řádky pod starým názvem a dvě kampaně stejného jména by se přepsaly.
    $campaignStmt = $pdo->prepare('INSERT INTO provider_metrics_daily (domain_id, source_id, connection_id, metric_date, metric_key, dimension, metric_value) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE metric_value = metric_value + VALUES(metric_value)');
    $domainId = (int) $connection['domain_id'];
    $sourceId = (int) $connection['source_id'];
    $connectionId = (int) $connection['id'];
    $num = static fn ($v): float => is_numeric($v) ? (float) $v : 0.0; // null = nedostupné, ukládá se jako 0 jen u součtu
    $account = [];
    $campaignIds = [];
    $campaignRows = 0;
    $pdo->beginTransaction();
    try {
    $pdo->prepare("DELETE FROM provider_metrics_daily WHERE domain_id = ? AND connection_id = ? AND metric_date BETWEEN ? AND ? AND metric_key IN ('campaign_impressions','campaign_clicks','campaign_cost','campaign_conversions','campaign_value')")
        ->execute([$domainId, $connectionId, $startDate, $endDate]);
    foreach ($rows as $r) {
        $date = (string) ($r['readable_time'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { continue; }
        $name = trim((string) ($r['campaign_name'] ?? $r['campaign_id'] ?? ''));
        if (($r['campaign_id'] ?? '') !== '') { $campaignIds[(string) $r['campaign_id']] = true; }
        $values = [
            'impressions' => $num($r['impressions'] ?? null),
            'clicks' => $num($r['clicks'] ?? null),
            'cost' => $num($r['spend'] ?? null),
            'conversions' => $num($r['conversions'] ?? null),
            'conversion_value' => $num($r['order_created_attributed_sales'] ?? null),
        ];
        foreach ($values as $k => $v) {
            $account[$date][$k] = ($account[$date][$k] ?? 0.0) + $v;
        }
        // I řádek s 0 zobrazeními může nést útratu, kliky nebo konverze (reklama zobrazená dřív); vynechá se jen prázdný.
        if ($name !== '' && array_sum($values) > 0) {
            foreach (['impressions' => 'campaign_impressions', 'clicks' => 'campaign_clicks', 'cost' => 'campaign_cost', 'conversions' => 'campaign_conversions', 'conversion_value' => 'campaign_value'] as $k => $ck) {
                $campaignStmt->execute([$domainId, $sourceId, $connectionId, $date, $ck, mb_substr($name, 0, 120), $values[$k]]);
                $campaignRows++;
            }
        }
    }
    $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    // Účet = součet kampaní po dnech. Dny bez řádku se nezapisují (reklama neběžela), Růst kanálů je doplní nulou.
    $written = 0;
    foreach ($account as $date => $values) {
        foreach ($values as $k => $v) {
            if (allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $date, $k, $v)) { $written++; }
        }
    }

    // Měna účtu: GET insights ji nevrací, endpoint konverzí ano (account_currency). Best-effort, jednou za běh.
    if ($campaignIds) {
        try {
            $resp = allstat_http_request('POST', rtrim($base, '/') . '/v1/conversions/insights', allstat_openai_ads_headers($token) + ['Content-Type' => 'application/json'],
                (string) json_encode(['aggregation_level' => 'campaign', 'time_granularity' => 'none',
                    'time_ranges' => [json_encode(['type' => 'date_range', 'since' => $startDate, 'until' => $endDate, 'timezone' => 'Europe/Prague'])],
                    'entity_ids' => array_slice(array_keys($campaignIds), 0, 100)]), 30);
            $currency = strtoupper((string) ($resp['json']['account_currency'] ?? ''));
            if (($resp['status'] ?? 0) === 200 && preg_match('/^[A-Z]{3}$/', $currency)) {
                $pdo->prepare('INSERT INTO allstat_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
                    ->execute(['openai_ads.currency.' . $connectionId, $currency]);
            }
        } catch (Throwable) { /* měna je bonus */ }
    }

    return ['rows' => $written + $campaignRows, 'summary' => sprintf('%d metrik účtu, %d řádků kampaní (%s → %s)', $written, $campaignRows, $startDate, $endDate)];
}

/** Test napojení: jedna malá žádost o včerejší souhrn účtu. */
function allstat_openai_ads_test(array $connection, string $token): array
{
    if (trim($token) === '') {
        return ['ok' => false, 'message' => t('Vyplň API klíč reklamního účtu OpenAI Ads a ulož napojení.')];
    }
    $day = (new DateTimeImmutable('yesterday'))->format('Y-m-d');
    try {
        $rows = allstat_openai_ads_insights((string) ($connection['api_base_url'] ?: ALLSTAT_OPENAI_ADS_BASE), $token, $day, $day, allstat_openai_ads_field_sets(false), 'none');
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }

    return ['ok' => true, 'http' => 200, 'message' => t('OpenAI Ads odpovídá, klíč má přístup k reportingu účtu ({count} řádků za včerejšek).', ['count' => count($rows)])];
}
