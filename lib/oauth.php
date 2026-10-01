<?php

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/providers.php';

function allstat_http_request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): array
{
    $headerLines = [];
    foreach ($headers as $key => $value) {
        $headerLines[] = $key . ': ' . $value;
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => $timeout,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    $status = 0;
    if (preg_match('#HTTP/\S+\s+(\d{3})#', $statusLine, $match)) {
        $status = (int) $match[1];
    }

    return [
        'status' => $status,
        'body' => is_string($response) ? $response : '',
        'json' => is_string($response) ? json_decode($response, true) : null,
        'headers' => allstat_parse_headers($http_response_header ?? []),
    ];
}

/**
 * Parse raw $http_response_header lines into a lowercased name => value map (last value wins).
 * Used to read provider rate-limit headers (Meta X-App-Usage / X-Business-Use-Case-Usage etc.).
 */
function allstat_parse_headers(array $rawHeaderLines): array
{
    $out = [];
    foreach ($rawHeaderLines as $line) {
        $pos = strpos((string) $line, ':');
        if ($pos !== false) {
            $out[strtolower(trim(substr((string) $line, 0, $pos)))] = trim(substr((string) $line, $pos + 1));
        }
    }
    return $out;
}

function allstat_refresh_access_token(PDO $pdo, array $config, array $connection): array
{
    $refreshToken = allstat_decrypt_secret($connection['refresh_token_enc'] ?? null, $config);
    $clientSecret = allstat_decrypt_secret($connection['client_secret_enc'] ?? null, $config);

    if (!$refreshToken || empty($connection['client_id']) || !$clientSecret || empty($connection['token_url'])) {
        return ['ok' => false, 'message' => 'Pro refresh tokenu chybí refresh_token, client_id, client_secret nebo token_url.'];
    }

    $response = allstat_http_request(
        'POST',
        (string) $connection['token_url'],
        [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ],
        http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $connection['client_id'],
            'client_secret' => $clientSecret,
        ])
    );

    $payload = $response['json'];

    if (!is_array($payload) || empty($payload['access_token'])) {
        $detail = is_array($payload) ? ($payload['error_description'] ?? $payload['error'] ?? '') : substr($response['body'], 0, 200);
        return ['ok' => false, 'message' => 'Refresh selhal (HTTP ' . $response['status'] . '): ' . $detail];
    }

    $newAccess = (string) $payload['access_token'];
    $expiresAt = !empty($payload['expires_in'])
        ? (new DateTimeImmutable('now'))->modify('+' . (int) $payload['expires_in'] . ' seconds')->format('Y-m-d H:i:s')
        : null;

    $statement = $pdo->prepare('UPDATE domain_sources SET access_token_enc = ?, token_expires_at = ?, updated_at = NOW() WHERE id = ?');
    $statement->execute([allstat_encrypt_secret($newAccess, $config), $expiresAt, (int) $connection['id']]);

    return ['ok' => true, 'access_token' => $newAccess, 'expires_at' => $expiresAt];
}

function allstat_get_valid_access_token(PDO $pdo, array $config, array $connection): array
{
    $token = allstat_decrypt_secret($connection['access_token_enc'] ?? null, $config);

    if (!$token) {
        return ['ok' => false, 'message' => 'Není uložen access_token. Spusťte OAuth flow.'];
    }

    $expiresAt = $connection['token_expires_at'] ?? null;
    $needsRefresh = $expiresAt && strtotime((string) $expiresAt) - time() < 60;

    if ($needsRefresh) {
        $refresh = allstat_refresh_access_token($pdo, $config, $connection);
        if (!$refresh['ok']) {
            return $refresh;
        }
        $token = $refresh['access_token'];
    }

    return ['ok' => true, 'access_token' => $token];
}

function allstat_normalize_ga4_property(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return $raw;
    }
    if (str_starts_with($raw, 'properties/')) {
        return $raw;
    }
    if (ctype_digit($raw)) {
        return 'properties/' . $raw;
    }
    return $raw;
}

function allstat_test_connection(PDO $pdo, array $config, array $connection): array
{
    $providerKey = (string) ($connection['provider_key'] ?? '');

    if ($providerKey === 'clarity') {
        $token = allstat_decrypt_secret($connection['access_token_enc'] ?? null, $config);
        if (!$token) {
            return ['ok' => false, 'message' => 'Vyplňte Clarity API token do pole Access token.'];
        }
        $response = allstat_http_request(
            'GET',
            'https://www.clarity.ms/export-data/api/v1/project-live-insights?numOfDays=1',
            ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json']
        );
        if ($response['status'] === 200) {
            return ['ok' => true, 'message' => 'Clarity API odpovídá.', 'http' => 200];
        }
        return ['ok' => false, 'http' => $response['status'], 'message' => 'Clarity API: ' . substr($response['body'], 0, 200)];
    }

    $tokenResult = allstat_get_valid_access_token($pdo, $config, $connection);
    if (!$tokenResult['ok']) {
        return $tokenResult;
    }
    $token = $tokenResult['access_token'];
    $authHeader = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];

    switch ($providerKey) {
        case 'ga4':
            $property = allstat_normalize_ga4_property((string) ($connection['property_id'] ?? ''));
            if (!str_starts_with($property, 'properties/')) {
                return ['ok' => false, 'message' => 'Property ID musí být ve formátu "properties/123456789".'];
            }
            $url = 'https://analyticsdata.googleapis.com/v1beta/' . rawurlencode($property) . '/metadata';
            $url = str_replace('properties%2F', 'properties/', $url);
            $response = allstat_http_request('GET', $url, $authHeader);
            if ($response['status'] === 200 && is_array($response['json'])) {
                $dims = count($response['json']['dimensions'] ?? []);
                $metrics = count($response['json']['metrics'] ?? []);
                return [
                    'ok' => true,
                    'http' => 200,
                    'message' => sprintf('GA4 property "%s" odpovídá. Dostupné dimenze: %d, metriky: %d.', $property, $dims, $metrics),
                ];
            }
            return [
                'ok' => false,
                'http' => $response['status'],
                'message' => allstat_explain_google_error($response, $property),
            ];

        case 'gsc':
            $response = allstat_http_request('GET', 'https://searchconsole.googleapis.com/webmasters/v3/sites', $authHeader);
            if ($response['status'] === 200) {
                $sites = $response['json']['siteEntry'] ?? [];
                $property = (string) ($connection['property_id'] ?? '');
                $match = false;
                foreach ($sites as $site) {
                    if (($site['siteUrl'] ?? '') === $property) { $match = true; break; }
                }
                return [
                    'ok' => $match,
                    'http' => 200,
                    'message' => $match
                        ? 'Search Console: účet má přístup k property "' . $property . '".'
                        : 'Search Console odpovídá, ale property "' . $property . '" v účtu není. Dostupné: ' . implode(', ', array_map(fn($s) => $s['siteUrl'] ?? '?', $sites)),
                ];
            }
            return ['ok' => false, 'http' => $response['status'], 'message' => allstat_explain_google_error($response, $connection['property_id'] ?? '')];

        case 'google_ads':
            $customerId = preg_replace('/[^0-9]/', '', (string) ($connection['property_id'] ?? ''));
            if ($customerId === '') {
                return ['ok' => false, 'message' => 'Property ID (Customer ID bez pomlček) chybí.'];
            }
            // Stejná cesta jako sync (GAQL searchStream nad zdrojem customer); samostatné GET na zdroj API už nemá.
            $response = allstat_http_request(
                'POST',
                allstat_google_ads_url($connection, 'customers/' . $customerId . '/googleAds:searchStream'),
                allstat_google_ads_headers($connection, $token),
                json_encode(['query' => 'SELECT customer.descriptive_name, customer.currency_code FROM customer LIMIT 1'])
            );
            if ($response['status'] !== 200) {
                return ['ok' => false, 'http' => $response['status'], 'message' => allstat_explain_google_ads_error($response)];
            }
            $customer = $response['json'][0]['results'][0]['customer'] ?? [];
            return [
                'ok' => true,
                'http' => 200,
                'message' => 'Google Ads odpovídá: účet „' . ($customer['descriptiveName'] ?? $customerId) . '"'
                    . (!empty($customer['currencyCode']) ? ', měna ' . $customer['currencyCode'] : '') . '.',
            ];

        case 'youtube':
            // Stejný dotaz, jakým začíná sync: kanál autorizovaného účtu (mine=true), nebo zadané ID kanálu.
            $channelId = trim((string) ($connection['property_id'] ?? ''));
            $url = 'https://www.googleapis.com/youtube/v3/channels?part=snippet,statistics&'
                . ($channelId !== '' ? 'id=' . rawurlencode($channelId) : 'mine=true');
            $response = allstat_http_request('GET', $url, $authHeader);
            if ($response['status'] === 200 && is_array($response['json'])) {
                $item = $response['json']['items'][0] ?? null;
                if (!$item) {
                    return ['ok' => false, 'http' => 200, 'message' => $channelId !== ''
                        ? 'YouTube: kanál s ID "' . $channelId . '" neexistuje nebo k němu účet nemá přístup.'
                        : 'YouTube: autorizovaný Google účet nemá žádný kanál. Spusť OAuth znovu a vyber účet značky (Brand Account) s kanálem, nebo vyplň ID kanálu.'];
                }
                $stats = $item['statistics'] ?? [];
                return ['ok' => true, 'http' => 200, 'message' => sprintf('YouTube: kanál „%s" (%s), odběratelů: %s, videí: %s, zhlédnutí celkem: %s.',
                    (string) ($item['snippet']['title'] ?? '?'), (string) ($item['id'] ?? '?'),
                    allstat_number((int) ($stats['subscriberCount'] ?? 0)), allstat_number((int) ($stats['videoCount'] ?? 0)), allstat_number((int) ($stats['viewCount'] ?? 0)))];
            }
            return ['ok' => false, 'http' => $response['status'], 'message' => allstat_explain_youtube_error($response)];

        case 'facebook_pages':
        case 'instagram_business':
        case 'meta_ads':
            $response = allstat_http_request('GET', 'https://graph.facebook.com/v25.0/me?access_token=' . urlencode($token), []);
            return [
                'ok' => $response['status'] === 200,
                'http' => $response['status'],
                'message' => $response['status'] === 200 ? 'Meta Graph API odpovídá (me=' . ($response['json']['id'] ?? '?') . ').' : substr($response['body'], 0, 240),
            ];

        case 'linkedin_company':
            // Test the SAME capability the sync uses — organization share statistics — NOT /v2/me.
            // /v2/me is the member-profile endpoint (needs r_liteprofile/openid); a company token
            // (r_organization_social, rw_organization_admin) has no access to it, so it always 403'd with
            // "ACCESS_DENIED … me.GET.NO_VERSION" — saying nothing about whether the org sync would work.
            $base = rtrim((string) ($connection['api_base_url'] ?: 'https://api.linkedin.com/v2'), '/');
            $urn = trim((string) ($connection['property_id'] ?? ''));
            if ($urn === '') {
                return ['ok' => false, 'message' => 'Vyplň Property ID = URN organizace (urn:li:organization:ČÍSLO) a ulož napojení; teprve pak jde test spustit.'];
            }
            // Accept a bare numeric id or a urn:li:company: URN and normalise to urn:li:organization:ID — jinak
            // LinkedIn vrátí "Data Processing Exception … [/organizationalEntity]" (validace hodnoty, ne práva).
            if (preg_match('/^\d+$/', $urn)) {
                $urn = 'urn:li:organization:' . $urn;
            } elseif (preg_match('/urn:li:(?:organization|company):(\d+)/i', $urn, $m)) {
                $urn = 'urn:li:organization:' . $m[1];
            }
            $url = $base . '/organizationalEntityShareStatistics?q=organizationalEntity&organizationalEntity=' . rawurlencode($urn);
            $response = allstat_http_request('GET', $url, array_merge($authHeader, ['X-Restli-Protocol-Version' => '2.0.0']));
            if ($response['status'] === 200) {
                return ['ok' => true, 'http' => 200, 'message' => 'LinkedIn: token má přístup ke statistikám organizace ' . $urn . '. Napojení funguje.'];
            }
            return ['ok' => false, 'http' => $response['status'], 'message' => allstat_explain_linkedin_error($response, $urn)];
    }

    return ['ok' => false, 'message' => 'Pro providera "' . $providerKey . '" zatím není test definovaný.'];
}

/**
 * Human-readable Czech explanation of common LinkedIn API errors from the connection test, so the raw
 * {"code":"ACCESS_DENIED",…} body doesn't leave the user guessing. $urn = the configured organization URN.
 */
function allstat_explain_linkedin_error(array $response, string $urn): string
{
    $status = (int) ($response['status'] ?? 0);
    $body = (string) ($response['body'] ?? '');
    $code = (string) ($response['json']['code'] ?? '');
    $apiMsg = (string) ($response['json']['message'] ?? '');
    $detail = $apiMsg !== '' ? ' Detail z LinkedIn: ' . $apiMsg : ($body !== '' ? ' Detail: ' . substr($body, 0, 160) : '');
    $hay = $apiMsg . ' ' . $body;

    // LinkedIn vrací 403 i pro NEPLATNÉ Property ID (problém s hodnotou, ne s oprávněním):
    // "Field Value validation failed … Data Processing Exception while processing fields [/organizationalEntity]".
    if (stripos($hay, 'Field Value validation') !== false || stripos($hay, 'Data Processing Exception') !== false || stripos($hay, '[/organizationalEntity]') !== false) {
        return 'Property ID „' . $urn . '" LinkedIn nepřijal, není to platné URN organizace. Musí být přesně ve tvaru urn:li:organization:ČÍSLO (číselné ID stránky, ne název ani odkaz). Číslo najdeš v LinkedIn Developers → tvoje app → Settings (u propojené stránky) nebo v adrese stránky jako správce (.../company/ČÍSLO/). Stačí zadat i samotné číslo, AllStat ho na URN doplní sám.' . $detail;
    }
    if ($status === 403 || $code === 'ACCESS_DENIED' || stripos($hay, 'Not enough permissions') !== false) {
        return 'HTTP 403 – token nemá oprávnění ke statistikám organizace. Nejčastější příčina: LinkedIn ještě neschválil produkt „Community Management API" (po podání žádosti to trvá řádově dny; do schválení je 403 normální, počkej). Další možnosti: nejsi správce (admin) této LinkedIn stránky, nebo OAuth proběhl bez scope r_organization_social – zkontroluj pole „OAuth scopes" a spusť OAuth znovu.' . $detail;
    }
    if ($status === 401) {
        return 'HTTP 401 – přihlášení k LinkedIn vypršelo nebo je neplatné (token platí 60 dní a bez schváleného produktu se sám neobnovuje). Dej „Spustit OAuth" znovu.' . $detail;
    }
    if ($status === 400) {
        return 'HTTP 400 – nejspíš špatné Property ID. Musí být přesně ve tvaru urn:li:organization:ČÍSLO (číselné ID stránky, ne název).' . $detail;
    }
    if ($status === 429) {
        return 'HTTP 429 – vyčerpán denní limit LinkedIn API (Development tier 500/app/den, 100/člen/den, reset o půlnoci UTC). Zkus to zítra.';
    }

    return 'HTTP ' . $status . ' z LinkedIn API.' . $detail;
}

/**
 * Česky vysvětlené chyby YouTube Data / Analytics API (test napojení i sync). Google vrací
 * {"error":{"code":403,"message":"…","errors":[{"reason":"accessNotConfigured"}]}} (Data API v3) nebo
 * {"error":{"code":403,"status":"PERMISSION_DENIED","details":[{"reason":"…"}]}} (Analytics v2).
 */
function allstat_explain_youtube_error(array $response): string
{
    $status = (int) ($response['status'] ?? 0);
    $err = is_array($response['json'] ?? null) ? ($response['json']['error'] ?? null) : null;
    $reason = '';
    $msg = '';
    if (is_array($err)) {
        $reason = (string) ($err['errors'][0]['reason'] ?? $err['details'][0]['reason'] ?? $err['status'] ?? '');
        $msg = (string) ($err['message'] ?? '');
    }
    $detail = $msg !== '' ? ' Detail: ' . mb_substr($msg, 0, 200) : ' ' . mb_substr((string) ($response['body'] ?? ''), 0, 160);
    $hay = $reason . ' ' . $msg;

    if (stripos($hay, 'accessNotConfigured') !== false || stripos($hay, 'has not been used in project') !== false || stripos($hay, 'is disabled') !== false) {
        return 'HTTP ' . $status . ' [accessNotConfigured]: v Google Cloud projektu není povolené YouTube API. Povol „YouTube Data API v3" i „YouTube Analytics API" (APIs & Services → Library) a zkus to znovu.' . $detail;
    }
    if (stripos($hay, 'insufficientPermissions') !== false || stripos($hay, 'ACCESS_TOKEN_SCOPE_INSUFFICIENT') !== false || stripos($hay, 'insufficient authentication scopes') !== false) {
        return 'HTTP ' . $status . ': token nemá oprávnění k YouTube (chybí scope youtube.readonly / yt-analytics.readonly). Dej „Spustit OAuth" znovu a potvrď obě oprávnění.' . $detail;
    }
    if (stripos($hay, 'quotaExceeded') !== false || stripos($hay, 'RATE_LIMIT') !== false || $status === 429) {
        return 'HTTP ' . $status . ': vyčerpaná denní kvóta YouTube API (resetuje se o půlnoci pacifického času). Zkus to později.' . $detail;
    }
    if ($status === 403) {
        return 'HTTP 403 [' . $reason . ']: autorizovaný Google účet nemá ke kanálu oprávnění (YouTube Analytics vyžaduje vlastníka nebo správce kanálu). Spusť OAuth znovu správným účtem, u účtu značky vyber při přihlášení účet s názvem kanálu.' . $detail;
    }
    if ($status === 401) {
        return 'HTTP 401: přihlášení ke Googlu vypršelo nebo bylo odvoláno. Dej „Spustit OAuth" znovu.' . $detail;
    }
    if ($status === 400) {
        return 'HTTP 400 [' . $reason . ']: YouTube API odmítlo dotaz (neplatné ID kanálu nebo parametry).' . $detail;
    }

    return 'HTTP ' . $status . ($reason !== '' ? ' [' . $reason . ']' : '') . ' z YouTube API.' . $detail;
}

function allstat_explain_google_error(array $response, string $property): string
{
    $err = $response['json']['error'] ?? null;
    if (is_array($err)) {
        $reason = '';
        if (!empty($err['details'][0]['reason'])) {
            $reason = ' [' . $err['details'][0]['reason'] . ']';
        }
        $msg = (string) ($err['message'] ?? '');
        if ($response['status'] === 403) {
            return 'HTTP 403' . $reason . ': uživatel nemá v této property oprávnění. Přidej Google účet do GA4 (Admin → Property access management) jako Viewer. Detail: ' . $msg;
        }
        if ($response['status'] === 404) {
            return 'HTTP 404: property "' . $property . '" neexistuje. Zkontroluj Property ID. Detail: ' . $msg;
        }
        if ($response['status'] === 401) {
            return 'HTTP 401: access token vypršel nebo je neplatný. Spusť OAuth znovu. Detail: ' . $msg;
        }
        return 'HTTP ' . $response['status'] . ': ' . $msg;
    }
    return 'HTTP ' . $response['status'] . ': ' . substr($response['body'], 0, 240);
}

/**
 * Google Ads API: při upgradu se mění jen major verze v URL (minor verze v25.x běží na stejném endpointu samy).
 * Google vypíná verzi zhruba rok po vydání a vypnutá verze vrací HTTP 404 s HTML stránkou.
 * Konce verzí: https://developers.google.com/google-ads/api/docs/sunset-dates (v25 vyšla 22. 7. 2026, konec cca 8/2027).
 */
const ALLSTAT_GOOGLE_ADS_API_VERSION = 'v25';

function allstat_google_ads_url(array $connection, string $path): string
{
    $base = rtrim((string) (($connection['api_base_url'] ?? '') ?: 'https://googleads.googleapis.com'), '/');
    return $base . '/' . ALLSTAT_GOOGLE_ADS_API_VERSION . '/' . ltrim($path, '/');
}

/**
 * Developer token se od 9. 9. 2026 neposílá: Google ho ignoruje a v některé příští major verzi ho začne odmítat.
 * Úroveň přístupu (Test / Explorer / Basic / Standard) teď určuje Google Cloud projekt, ve kterém je OAuth klient.
 * login-customer-id jen při přístupu přes manažerský (MCC) účet.
 */
function allstat_google_ads_headers(array $connection, string $token): array
{
    $headers = [
        'Authorization' => 'Bearer ' . $token,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ];
    $cfg = json_decode((string) ($connection['config_json'] ?? ''), true);
    $loginCustomerId = is_array($cfg) ? preg_replace('/[^0-9]/', '', (string) ($cfg['login_customer_id'] ?? '')) : '';
    if ($loginCustomerId !== '') {
        $headers['login-customer-id'] = $loginCustomerId;
    }
    return $headers;
}

function allstat_explain_google_ads_error(array $response): string
{
    $status = (int) $response['status'];
    $json = $response['json'];
    if (is_array($json) && array_is_list($json)) {
        $json = $json[0] ?? null; // searchStream vrací i chybu v poli dávek
    }
    $err = is_array($json) ? ($json['error'] ?? null) : null;
    if (!is_array($err)) {
        if ($status === 404) {
            return 'HTTP 404: Google Ads API ' . ALLSTAT_GOOGLE_ADS_API_VERSION . ' není dostupná (Google tuto verzi nejspíš vypnul). AllStat je potřeba aktualizovat.';
        }
        return 'Google Ads API HTTP ' . $status . ': ' . substr(trim(strip_tags($response['body'])), 0, 180);
    }

    $codes = [];
    $detailMsg = '';
    foreach ($err['details'] ?? [] as $detail) {
        if (!empty($detail['reason'])) {
            $codes[] = (string) $detail['reason'];
        }
        foreach ($detail['errors'] ?? [] as $e) {
            foreach ((array) ($e['errorCode'] ?? []) as $code) {
                $codes[] = (string) $code;
            }
            if ($detailMsg === '' && !empty($e['message'])) {
                $detailMsg = (string) $e['message'];
            }
        }
    }
    $msg = $detailMsg !== '' ? $detailMsg : (string) ($err['message'] ?? '');
    $has = static fn (string ...$c): bool => (bool) array_intersect($c, $codes);

    if ($has('CLOUD_PROJECT_NOT_APPROVED_FOR_PRODUCTION', 'DEVELOPER_TOKEN_NOT_APPROVED')) {
        return 'HTTP ' . $status . ': Google Cloud projekt OAuth klienta má jen testovací přístup a na skutečné účty nesmí. Cloud Console → Google Ads API → Overview → „Upgrade access level" → požádej o Explorer (schválení obvykle do 10 pracovních dnů).';
    }
    if ($has('SERVICE_DISABLED') || str_contains($msg, 'has not been used in project')) {
        return 'HTTP ' . $status . ': v Google Cloud projektu není povolené Google Ads API. Cloud Console → APIs & Services → Library → Google Ads API → Enable.';
    }
    if ($has('USER_PERMISSION_DENIED')) {
        return 'HTTP ' . $status . ': Google účet, kterým jsi prošel OAuth, nemá k tomuto Customer ID přístup. Když účet spravuješ přes manažerský (MCC) účet, vyplň MCC / Login customer ID.';
    }
    if ($has('CUSTOMER_NOT_ENABLED')) {
        return 'HTTP ' . $status . ': účet Google Ads není aktivní (zrušený nebo nedokončený).';
    }
    if ($status === 401) {
        return 'HTTP 401: přihlášení vypršelo nebo je neplatné. Spusť OAuth znovu.';
    }
    return 'Google Ads API HTTP ' . $status . ($codes ? ' [' . implode(', ', array_unique($codes)) . ']' : '') . ': ' . substr($msg, 0, 200);
}
