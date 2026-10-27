<?php

/** Microsoft Advertising Reporting v13. Tato větev pouze čte reporty. */

function allstat_microsoft_ads_config(array $connection): array
{
    $config = json_decode((string) ($connection['config_json'] ?? ''), true);
    return is_array($config) ? $config : [];
}

function allstat_microsoft_ads_headers(array $connection, string $token): array
{
    $accountId = (string) ($connection['property_id'] ?? '');
    $config = allstat_microsoft_ads_config($connection);
    $customerId = (string) ($config['customer_id'] ?? '');
    $developerToken = (string) ($config['developer_token'] ?? '');
    if (!ctype_digit($accountId) || !ctype_digit($customerId) || $developerToken === '' || preg_match('/[\r\n]/', $developerToken)) {
        throw new RuntimeException('Microsoft Ads: vyplň Account ID, Customer ID a Developer Token.');
    }
    $identity = allstat_microsoft_ads_identity_provider($connection);
    if ($identity === null) {
        throw new RuntimeException('Microsoft Ads: neplatná dvojice OAuth endpointů.');
    }
    $headers = [
        'Authorization' => 'Bearer ' . $token,
        'DeveloperToken' => $developerToken,
        'CustomerId' => $customerId,
        'CustomerAccountId' => $accountId,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ];
    if ($identity === 'Google') {
        $headers['IdentityProvider'] = 'Google';
    }
    return $headers;
}

function allstat_explain_microsoft_ads_error(array $response): string
{
    $status = (int) ($response['status'] ?? 0);
    $body = is_array($response['json'] ?? null) ? $response['json'] : [];
    $code = strtolower((string) ($body['ErrorCode'] ?? $body['Code'] ?? $body['error'] ?? ''));
    if ($status === 401 || $code === 'invalid_grant') { return 'Microsoft Ads: přihlášení vypršelo. Připoj účet znovu přes OAuth.'; }
    if ($status === 403) { return 'Microsoft Ads: účet nemá oprávnění k reportům nebo je Developer Token neplatný.'; }
    if ($status === 429) { return 'Microsoft Ads: API překročilo limit požadavků. Zkus synchronizaci později.'; }
    if ($status >= 500) { return 'Microsoft Ads: Reporting API je dočasně nedostupné (HTTP ' . $status . ').'; }
    return 'Microsoft Ads: Reporting API odmítlo požadavek (HTTP ' . $status . '). Zkontroluj ID účtu, rozsah dat a přístup.';
}

function allstat_microsoft_ads_report_request(string $accountId, string $startDate, string $endDate, bool $campaigns, string $timezone = 'BelgradeBratislavaBudapestLjubljanaPrague'): array
{
    $date = static function (string $value): array {
        $parts = explode('-', $value);
        return ['Day' => (int) ($parts[2] ?? 0), 'Month' => (int) ($parts[1] ?? 0), 'Year' => (int) ($parts[0] ?? 0)];
    };
    $columns = ['TimePeriod', 'AccountId', 'AccountName', 'CurrencyCode', 'Impressions', 'Clicks', 'Spend', 'ConversionsQualified', 'Revenue'];
    if ($campaigns) { array_splice($columns, 3, 0, ['CampaignId', 'CampaignName']); }
    return ['ReportRequest' => [
        'Type' => $campaigns ? 'CampaignPerformanceReportRequest' : 'AccountPerformanceReportRequest',
        'Format' => 'Csv', 'FormatVersion' => '2.0',
        'ExcludeReportHeader' => true, 'ExcludeReportFooter' => true, 'ExcludeColumnHeaders' => false,
        'ReturnOnlyCompleteData' => false, 'Aggregation' => 'Daily', 'Columns' => $columns,
        'Scope' => ['AccountIds' => [(int) $accountId]], // REST v13 expects a long, not a string
        'Time' => ['CustomDateRangeStart' => $date($startDate), 'CustomDateRangeEnd' => $date($endDate), 'ReportTimeZone' => $timezone],
    ]];
}

/** Microsoft mění doménu podepsaného URL; přijmeme jen Azure Blob přes HTTPS, bez Bearer hlavičky. */
function allstat_microsoft_ads_download_allowed(string $url): bool
{
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') { return false; }
    $host = strtolower((string) ($parts['host'] ?? ''));
    return preg_match('/^[a-z0-9-]+\.blob\.core\.windows\.net$/D', $host) === 1
        && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port']);
}

/** Rozbalí právě jeden CSV soubor z ZIPu do paměti; nepoužívá ZipArchive ani cesty z archivu. */
function allstat_microsoft_ads_unzip_csv(string $zip): string
{
    if (strlen($zip) > 12_000_000) { throw new RuntimeException('Microsoft Ads: report je příliš velký.'); }
    $eocd = strrpos($zip, "PK\x05\x06");
    if ($eocd === false || strlen($zip) < $eocd + 22) { throw new RuntimeException('Microsoft Ads: poškozený ZIP report.'); }
    $end = unpack('ventries/vtotal/Vsize/Voffset', substr($zip, $eocd + 8, 12));
    if (($end['entries'] ?? 0) < 1 || ($end['entries'] ?? 0) > 20 || ($end['offset'] ?? 0) >= strlen($zip)) {
        throw new RuntimeException('Microsoft Ads: nepodporovaný ZIP report.');
    }
    $position = (int) $end['offset'];
    for ($i = 0; $i < $end['entries']; $i++) {
        if (substr($zip, $position, 4) !== "PK\x01\x02") { break; }
        $meta = unpack('vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vexpanded/vname/vextra/vcomment/vdisk/vinternal/Vexternal/Vlocal', substr($zip, $position + 8, 38));
        $name = substr($zip, $position + 46, $meta['name']);
        $position += 46 + $meta['name'] + $meta['extra'] + $meta['comment'];
        if (!str_ends_with(strtolower($name), '.csv')) { continue; }
        if (($meta['flags'] & 1) !== 0 || $meta['expanded'] > 25_000_000 || $meta['compressed'] > 12_000_000) {
            throw new RuntimeException('Microsoft Ads: ZIP je šifrovaný nebo příliš velký.');
        }
        $local = (int) $meta['local'];
        if (substr($zip, $local, 4) !== "PK\x03\x04") { throw new RuntimeException('Microsoft Ads: poškozený ZIP report.'); }
        $localLengths = unpack('vname/vextra', substr($zip, $local + 26, 4));
        $offset = $local + 30 + $localLengths['name'] + $localLengths['extra'];
        $compressed = substr($zip, $offset, $meta['compressed']);
        if (strlen($compressed) !== $meta['compressed']) { throw new RuntimeException('Microsoft Ads: neúplný ZIP report.'); }
        $csv = match ($meta['method']) { 0 => $compressed, 8 => @gzinflate($compressed, 25_000_000), default => false };
        if (!is_string($csv) || strlen($csv) !== $meta['expanded'] || sprintf('%u', crc32($csv)) !== sprintf('%u', $meta['crc'])) {
            throw new RuntimeException('Microsoft Ads: neplatný CSV v ZIP reportu.');
        }
        return $csv;
    }
    throw new RuntimeException('Microsoft Ads: ZIP report neobsahuje CSV.');
}

function allstat_microsoft_ads_number($value): ?float
{
    $value = trim((string) $value);
    if ($value === '' || $value === '--' || $value === 'N/A') { return null; }
    $value = str_replace(["\xc2\xa0", ' '], '', $value);
    $comma = strrpos($value, ',');
    $dot = strrpos($value, '.');
    if ($comma !== false && $dot !== false) {
        $value = $comma > $dot ? str_replace(',', '.', str_replace('.', '', $value)) : str_replace(',', '', $value);
    } elseif ($comma !== false) {
        $value = preg_match('/,\d{1,2}$/', $value) ? str_replace(',', '.', $value) : str_replace(',', '', $value);
    }
    return is_numeric($value) ? (float) $value : null;
}

/** Parse a CSV one row at a time; php://temp spills to disk instead of retaining a second large copy. */
function allstat_microsoft_ads_csv_rows(string $csv, array $expectedColumns = []): Generator
{
    $stream = fopen('php://temp/maxmemory:1048576', 'w+');
    if ($stream === false) { throw new RuntimeException('Microsoft Ads: CSV nelze otevřít.'); }
    try {
        if (fwrite($stream, $csv) !== strlen($csv)) { throw new RuntimeException('Microsoft Ads: CSV nelze načíst.'); }
        unset($csv);
        // Reporting API puts a UTF-8 BOM before the quoted first heading ("TimePeriod"). It must be skipped before
        // parsing: after it fgetcsv no longer sees the opening quote and would keep the quotes in the column name.
        rewind($stream);
        if (fread($stream, 3) !== "\xef\xbb\xbf") { rewind($stream); }
        $head = fgetcsv($stream);
        if (!is_array($head) || !$head) { return; }
        $head = array_map(static fn ($s) => trim((string) $s, " \r\n"), $head);
        if (array_diff($expectedColumns, $head)) {
            throw new RuntimeException('Microsoft Ads: CSV report neobsahuje očekávané sloupce.');
        }
        $count = 0;
        while (($line = fgetcsv($stream)) !== false) {
            if (count($line) !== count($head)) { continue; }
            $row = array_combine($head, $line);
            if (empty($row['TimePeriod'])) { continue; }
            if (++$count > 200_000) { throw new RuntimeException('Microsoft Ads: CSV report přesahuje limit řádků.'); }
            yield $row;
        }
    } finally {
        fclose($stream);
    }
}

/** Send a report request and return its ID; the connection test uses this without waiting. */
function allstat_microsoft_ads_submit(array $connection, string $token, string $startDate, string $endDate, bool $campaigns): string
{
    $headers = allstat_microsoft_ads_headers($connection, $token);
    $base = (string) ($connection['api_base_url'] ?? '');
    if (!allstat_microsoft_ads_api_base_allowed($base)) { throw new RuntimeException('Microsoft Ads: nepovolená adresa API.'); }
    $url = $base . '/Reporting/v13/GenerateReport/';
    $config = allstat_microsoft_ads_config($connection);
    $timezone = (string) ($config['report_timezone'] ?? 'BelgradeBratislavaBudapestLjubljanaPrague');
    if (!preg_match('/^[A-Za-z]{3,80}$/D', $timezone)) { throw new RuntimeException('Microsoft Ads: neplatná časová zóna reportu.'); }
    $request = allstat_microsoft_ads_report_request((string) $connection['property_id'], $startDate, $endDate, $campaigns, $timezone);
    $submit = allstat_http_request('POST', $url . 'Submit', $headers, json_encode($request), 30, false);
    if ($submit['status'] !== 200 || empty($submit['json']['ReportRequestId'])) {
        throw new RuntimeException(allstat_explain_microsoft_ads_error($submit));
    }
    $id = (string) $submit['json']['ReportRequestId'];
    if (strlen($id) > 40) { throw new RuntimeException('Microsoft Ads: neplatné ID reportu.'); }
    return $id;
}

/** Reuse a pending ID on the next run; Microsoft keeps it for at most one day. */
function allstat_microsoft_ads_report(array $connection, string $token, string $startDate, string $endDate, bool $campaigns, callable $onRow, ?PDO $pdo = null): int
{
    $headers = allstat_microsoft_ads_headers($connection, $token);
    $base = (string) ($connection['api_base_url'] ?? '');
    if (!allstat_microsoft_ads_api_base_allowed($base)) { throw new RuntimeException('Microsoft Ads: nepovolená adresa API.'); }
    $url = $base . '/Reporting/v13/GenerateReport/';
    $config = allstat_microsoft_ads_config($connection);
    $timezone = (string) ($config['report_timezone'] ?? 'BelgradeBratislavaBudapestLjubljanaPrague');
    if (!preg_match('/^[A-Za-z]{3,80}$/D', $timezone)) { throw new RuntimeException('Microsoft Ads: neplatná časová zóna reportu.'); }
    $request = allstat_microsoft_ads_report_request((string) $connection['property_id'], $startDate, $endDate, $campaigns, $timezone);
    $job = [(int) ($connection['id'] ?? 0), $campaigns ? 'campaign' : 'account', $startDate, $endDate];
    $id = '';
    if ($pdo !== null) {
        // Requests that never finished are useless after a day (Microsoft keeps an ID for at most one day).
        $pdo->exec('DELETE FROM microsoft_ads_report_jobs WHERE created_at < NOW() - INTERVAL 1 DAY');
        $find = $pdo->prepare('SELECT report_request_id FROM microsoft_ads_report_jobs WHERE connection_id = ? AND report_type = ? AND start_date = ? AND end_date = ? AND created_at >= NOW() - INTERVAL 23 HOUR');
        $find->execute($job);
        $id = (string) ($find->fetchColumn() ?: '');
    }
    if ($id === '') {
        $id = allstat_microsoft_ads_submit($connection, $token, $startDate, $endDate, $campaigns);
        if ($pdo !== null) {
            $pdo->prepare('INSERT INTO microsoft_ads_report_jobs (connection_id, report_type, start_date, end_date, report_request_id, created_at) VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE report_request_id = VALUES(report_request_id), created_at = NOW()')
                ->execute([...$job, $id]);
        }
    }
    $clear = static function () use ($pdo, $job, $id): void {
        if ($pdo !== null) {
            $pdo->prepare('DELETE FROM microsoft_ads_report_jobs WHERE connection_id = ? AND report_type = ? AND start_date = ? AND end_date = ? AND report_request_id = ?')
                ->execute([...$job, $id]);
        }
    };
    for ($attempt = 0; $attempt < 5; $attempt++) {
        if ($attempt > 0) { sleep(min(2 ** $attempt, 12)); }
        $poll = allstat_http_request('POST', $url . 'Poll', $headers, json_encode(['ReportRequestId' => $id]), 30, false);
        if ($poll['status'] !== 200) { throw new RuntimeException(allstat_explain_microsoft_ads_error($poll)); }
        $status = (string) ($poll['json']['ReportRequestStatus']['Status'] ?? '');
        if ($status === 'Pending') { continue; }
        if ($status !== 'Success') { $clear(); throw new RuntimeException('Microsoft Ads: generování reportu selhalo.'); }
        $downloadUrl = (string) ($poll['json']['ReportRequestStatus']['ReportDownloadUrl'] ?? '');
        if ($downloadUrl === '') { $clear(); return 0; }
        if (!allstat_microsoft_ads_download_allowed($downloadUrl)) {
            throw new RuntimeException('Microsoft Ads: report má nepovolenou adresu ke stažení.');
        }
        $download = allstat_http_request('GET', $downloadUrl, [], null, 30, false, 12_000_000);
        if ($download['status'] !== 200) { throw new RuntimeException('Microsoft Ads: stažení reportu selhalo (HTTP ' . $download['status'] . ').'); }
        $count = 0;
        foreach (allstat_microsoft_ads_csv_rows(allstat_microsoft_ads_unzip_csv((string) $download['body']), $request['ReportRequest']['Columns']) as $row) {
            $onRow($row);
            $count++;
        }
        $clear();
        return $count;
    }
    throw new RuntimeException('Microsoft Ads: report ještě není připravený. Zkus synchronizaci později.');
}

function allstat_engine_microsoft_ads(PDO $pdo, array $config, array $connection, string $token, string $startDate, string $endDate, array $recipe): array
{
    $stmt = allstat_pm_statement($pdo);
    $domainId = (int) $connection['domain_id'];
    $sourceId = (int) $connection['source_id'];
    $connectionId = (int) $connection['id'];
    $written = 0;
    $currency = '';
    $accountName = '';
    $accountStarted = false;
    $startAccount = static function () use ($pdo, $domainId, $connectionId, $startDate, $endDate, &$accountStarted): void {
        if ($accountStarted) { return; }
        $pdo->beginTransaction();
        $accountStarted = true;
        $pdo->prepare("DELETE FROM provider_metrics_daily WHERE domain_id = ? AND connection_id = ? AND metric_date BETWEEN ? AND ? AND metric_key IN ('impressions','clicks','cost','conversions','conversion_value')")
            ->execute([$domainId, $connectionId, $startDate, $endDate]);
    };
    try {
        allstat_microsoft_ads_report($connection, $token, $startDate, $endDate, false, static function (array $row) use ($stmt, $domainId, $sourceId, $connectionId, $startDate, $endDate, $startAccount, &$written, &$currency, &$accountName): void {
            $date = (string) ($row['TimePeriod'] ?? '');
            if ($date < $startDate || $date > $endDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return; }
            $startAccount();
            $currency = $currency ?: (string) ($row['CurrencyCode'] ?? '');
            $accountName = $accountName ?: trim((string) ($row['AccountName'] ?? ''));
            foreach (['Impressions' => 'impressions', 'Clicks' => 'clicks', 'Spend' => 'cost', 'ConversionsQualified' => 'conversions', 'Revenue' => 'conversion_value'] as $column => $key) {
                $value = allstat_microsoft_ads_number($row[$column] ?? null);
                if ($value !== null && allstat_pm_put($stmt, $domainId, $sourceId, $connectionId, $date, $key, $value)) { $written++; }
            }
        }, $pdo);
        $startAccount(); // An empty successful report also removes stale values in this range.
        $pdo->commit();
    } catch (Throwable $error) {
        if ($accountStarted && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }
    // Currency is not secret: a plain setting lets Channel growth read it without the encryption key, and the
    // sync does not rewrite config_json_enc (which could overwrite a form saved at the same moment).
    if (preg_match('/^[A-Z]{3}$/', $currency)) {
        $pdo->prepare('INSERT INTO allstat_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute(['microsoft_ads.currency.' . $connectionId, $currency]);
        $label = trim((string) ($connection['account_label'] ?? ''));
        if ($label === '' || $label === 'Microsoft Ads účet') {
            $pdo->prepare('UPDATE domain_sources SET account_label = ?, updated_at = NOW() WHERE id = ?')
                ->execute([mb_substr($accountName ?: 'Microsoft Ads', 0, 160), $connectionId]);
        }
    }
    $campaignCount = 0;
    $campaignNote = '';
    $campaignStarted = false;
    try {
        $campaignStmt = $pdo->prepare('INSERT INTO provider_metrics_daily (domain_id, source_id, connection_id, metric_date, metric_key, dimension, metric_value) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE metric_value = metric_value + VALUES(metric_value)');
        $startCampaign = static function () use ($pdo, $domainId, $connectionId, $startDate, $endDate, &$campaignStarted): void {
            if ($campaignStarted) { return; }
            $pdo->beginTransaction();
            $campaignStarted = true;
            $pdo->prepare("DELETE FROM provider_metrics_daily WHERE domain_id = ? AND connection_id = ? AND metric_date BETWEEN ? AND ? AND metric_key IN ('campaign_impressions','campaign_clicks','campaign_cost','campaign_conversions','campaign_value')")
                ->execute([$domainId, $connectionId, $startDate, $endDate]);
        };
        allstat_microsoft_ads_report($connection, $token, $startDate, $endDate, true, static function (array $row) use ($campaignStmt, $domainId, $sourceId, $connectionId, $startDate, $endDate, $startCampaign): void {
            $date = (string) ($row['TimePeriod'] ?? '');
            $name = mb_substr(trim((string) ($row['CampaignName'] ?? '')), 0, 120);
            if ($date < $startDate || $date > $endDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $name === '') { return; }
            // A row may have 0 impressions but clicks, spend or conversions (ad shown before the period); skip only empty rows.
            $hasData = false;
            foreach (['Impressions', 'Clicks', 'Spend', 'ConversionsQualified', 'Revenue'] as $column) {
                if ((allstat_microsoft_ads_number($row[$column] ?? null) ?? 0) != 0) { $hasData = true; break; }
            }
            if (!$hasData) { return; }
            $startCampaign();
            foreach (['Impressions' => 'campaign_impressions', 'Clicks' => 'campaign_clicks', 'Spend' => 'campaign_cost', 'ConversionsQualified' => 'campaign_conversions', 'Revenue' => 'campaign_value'] as $column => $key) {
                $value = allstat_microsoft_ads_number($row[$column] ?? null);
                if ($value !== null) {
                    $campaignStmt->execute([$domainId, $sourceId, $connectionId, $date, $key, $name, $value]);
                }
            }
        }, $pdo);
        $startCampaign();
        $pdo->commit();
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM provider_metrics_daily WHERE domain_id = ? AND connection_id = ? AND metric_date BETWEEN ? AND ? AND metric_key IN ('campaign_impressions','campaign_clicks','campaign_cost','campaign_conversions','campaign_value')");
        $countStmt->execute([$domainId, $connectionId, $startDate, $endDate]);
        $campaignCount = (int) $countStmt->fetchColumn();
    } catch (Throwable $error) {
        if ($campaignStarted && $pdo->inTransaction()) { $pdo->rollBack(); }
        $campaignNote = '; kampaně: ' . mb_substr($error->getMessage(), 0, 60);
    }
    return ['rows' => $written + $campaignCount, 'summary' => sprintf('%d metrik, %d řádků kampaní%s (%s → %s)', $written, $campaignCount, $campaignNote, $startDate, $endDate)];
}
