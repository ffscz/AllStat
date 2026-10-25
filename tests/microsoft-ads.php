<?php

/** Run: php tests/microsoft-ads.php. No API credentials or network required. */
require_once __DIR__ . '/../lib/oauth.php';
require_once __DIR__ . '/../lib/microsoft-ads.php';

function check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function zip_csv(string $csv): string
{
    $name = 'report.csv';
    $compressed = gzdeflate($csv);
    $crc = crc32($csv);
    $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 8, 0, 0, $crc, strlen($compressed), strlen($csv), strlen($name), 0)
        . $name . $compressed;
    $central = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 8, 0, 0, $crc, strlen($compressed), strlen($csv), strlen($name), 0, 0, 0, 0, 0, 0)
        . $name;
    $end = pack('VvvvvVVv', 0x06054b50, 0, 0, 1, 1, strlen($central), strlen($local), 0);
    return $local . $central . $end;
}

$csv = "\xef\xbb\xbfTimePeriod,AccountId,AccountName,CurrencyCode,Impressions,Clicks,Spend,ConversionsQualified,Revenue\r\n"
    . "2026-10-24,123,Test,EUR,100,10,\"1,25\",--,N/A\r\n";
$zip = zip_csv($csv);
check(allstat_microsoft_ads_unzip_csv($zip) === $csv, 'ZIP/CSV roundtrip failed');
$rows = allstat_microsoft_ads_csv_rows($csv, ['TimePeriod', 'AccountId', 'Spend', 'ConversionsQualified']);
check(count($rows) === 1 && $rows[0]['CurrencyCode'] === 'EUR', 'CSV BOM or currency failed');
check(allstat_microsoft_ads_number($rows[0]['Spend']) === 1.25, 'Decimal comma failed');
check(allstat_microsoft_ads_number($rows[0]['ConversionsQualified']) === null, 'Unavailable conversion became zero');
check(!allstat_microsoft_ads_download_allowed('https://example.org/report.zip'), 'Download host allowlist failed');

$connection = [
    'property_id' => '123', 'config_json' => json_encode(['customer_id' => '456', 'developer_token' => 'dev']),
    'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token_url' => 'https://oauth2.googleapis.com/token',
    'api_base_url' => 'https://reporting.api.bingads.microsoft.com',
];
$calls = [];
$GLOBALS['allstat_http_mock'] = static function (string $method, string $url, array $headers, ?string $body) use (&$calls, $zip): array {
    $calls[] = $url;
    if (str_ends_with($url, '/Submit')) {
        check($method === 'POST' && ($headers['IdentityProvider'] ?? '') === 'Google', 'Google auth header missing');
        $request = json_decode((string) $body, true);
        check(($request['ReportRequest']['Aggregation'] ?? '') === 'Daily', 'Daily report aggregation missing');
        return ['status' => 200, 'json' => ['ReportRequestId' => 'report-1']];
    }
    if (str_ends_with($url, '/Poll')) {
        $polls = count(array_filter($calls, static fn ($call) => str_ends_with($call, '/Poll')));
        return ['status' => 200, 'json' => ['ReportRequestStatus' => $polls === 1
            ? ['Status' => 'Pending']
            : ['Status' => 'Success', 'ReportDownloadUrl' => 'https://example.blob.core.windows.net/report.zip?sig=test']]];
    }
    check($method === 'GET' && $headers === [], 'Bearer token sent to download URL');
    return ['status' => 200, 'body' => $zip];
};
$report = allstat_microsoft_ads_report($connection, 'access', '2026-10-24', '2026-10-24', false);
check(count($report) === 1 && count($calls) === 4, 'Submit/Poll/download flow failed');
unset($GLOBALS['allstat_http_mock']);

echo "Microsoft Ads mock tests OK (ZIP, CSV, numbers, OAuth header, Submit/Poll/download).\n";
