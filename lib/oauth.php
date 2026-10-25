<?php

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/providers.php';

function allstat_http_request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20, bool $followRedirects = true, ?int $maxResponseBytes = null): array
{
    // Testovací háček: testy synchronizace podstrčí odpovědi API bez sítě (v provozu proměnná nikdy neexistuje).
    if (PHP_SAPI === 'cli' && isset($GLOBALS['allstat_http_mock']) && is_callable($GLOBALS['allstat_http_mock'])) {
        return ($GLOBALS['allstat_http_mock'])($method, $url, $headers, $body);
    }

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
            'follow_location' => $followRedirects ? 1 : 0,
        ],
    ]);

    $response = $maxResponseBytes === null
        ? @file_get_contents($url, false, $context)
        : @file_get_contents($url, false, $context, 0, $maxResponseBytes + 1);
    if (is_string($response) && $maxResponseBytes !== null && strlen($response) > $maxResponseBytes) {
        throw new RuntimeException('Odpověď API přesahuje povolenou velikost.');
    }
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
        return ['ok' => false, 'message' => t('Pro refresh tokenu chybí refresh_token, client_id, client_secret nebo token_url.')];
    }

    $urlError = allstat_connection_urls_error($connection);
    if ($urlError !== null) {
        return ['ok' => false, 'message' => $urlError];
    }

    $response = allstat_http_request(
        'POST',
        (string) $connection['token_url'],
        [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ],
        http_build_query(array_merge([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $connection['client_id'],
            'client_secret' => $clientSecret,
        ], ($connection['provider_key'] ?? '') === 'microsoft_ads'
            && allstat_microsoft_ads_identity_provider($connection) === 'Microsoft'
            ? ['scope' => 'https://ads.microsoft.com/msads.manage offline_access'] : [])),
        20,
        ($connection['provider_key'] ?? '') !== 'microsoft_ads'
    );

    $payload = $response['json'];

    if (!is_array($payload) || empty($payload['access_token'])) {
        if (($connection['provider_key'] ?? '') === 'microsoft_ads') {
            $message = ($payload['error'] ?? '') === 'invalid_grant'
                ? t('Microsoft Ads: přihlášení vypršelo, připojte účet znovu přes OAuth.')
                : t('Microsoft Ads: obnovení přístupu selhalo (HTTP {status}).', ['status' => $response['status']]);
            return ['ok' => false, 'message' => $message];
        }
        $detail = is_array($payload) ? ($payload['error_description'] ?? $payload['error'] ?? '') : substr($response['body'], 0, 200);
        return ['ok' => false, 'message' => t('Refresh selhal (HTTP {status}): {detail}', ['status' => $response['status'], 'detail' => $detail])];
    }

    $newAccess = (string) $payload['access_token'];
    $expiresAt = !empty($payload['expires_in'])
        ? (new DateTimeImmutable('now'))->modify('+' . (int) $payload['expires_in'] . ' seconds')->format('Y-m-d H:i:s')
        : null;

    if (($connection['provider_key'] ?? '') === 'microsoft_ads' && !empty($payload['refresh_token'])) {
        $statement = $pdo->prepare('UPDATE domain_sources SET access_token_enc = ?, refresh_token_enc = ?, token_expires_at = ?, updated_at = NOW() WHERE id = ?');
        $statement->execute([allstat_encrypt_secret($newAccess, $config), allstat_encrypt_secret((string) $payload['refresh_token'], $config), $expiresAt, (int) $connection['id']]);
    } else {
        $statement = $pdo->prepare('UPDATE domain_sources SET access_token_enc = ?, token_expires_at = ?, updated_at = NOW() WHERE id = ?');
        $statement->execute([allstat_encrypt_secret($newAccess, $config), $expiresAt, (int) $connection['id']]);
    }

    return ['ok' => true, 'access_token' => $newAccess, 'expires_at' => $expiresAt];
}

function allstat_get_valid_access_token(PDO $pdo, array $config, array $connection): array
{
    // Napojení z Google účtu (Google účty, import webů) nemá vlastní tokeny, bere je z účtu.
    if (!empty($connection['oauth_account_id'])) {
        require_once __DIR__ . '/google-accounts.php';
        $result = allstat_google_account_token($pdo, $config, (int) $connection['oauth_account_id']);

        return $result['ok'] ? ['ok' => true, 'access_token' => (string) $result['access_token']] : ['ok' => false, 'message' => (string) $result['message']];
    }

    $token = allstat_decrypt_secret($connection['access_token_enc'] ?? null, $config);

    if (!$token) {
        return ['ok' => false, 'message' => t('Není uložen access_token. Spusťte OAuth flow.')];
    }

    $expiresAt = $connection['token_expires_at'] ?? null;
    $needsRefresh = $expiresAt && strtotime((string) $expiresAt) - time() < 60;

    if ($needsRefresh) {
        if (($connection['provider_key'] ?? '') === 'microsoft_ads') {
            // Rotující refresh token se nesmí použít dvakrát. Zámek je oddělený pro každou databázi a napojení.
            $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
            $lock = 'allstat_msads_' . substr(hash('sha256', $dbName . ':' . (int) $connection['id']), 0, 40);
            $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 10)');
            $lockStmt->execute([$lock]);
            if ((int) $lockStmt->fetchColumn() !== 1) {
                return ['ok' => false, 'message' => t('Microsoft Ads: token právě obnovuje jiný běh. Zkuste to za chvíli.')];
            }
            try {
                $freshStmt = $pdo->prepare('SELECT access_token_enc, refresh_token_enc, token_expires_at FROM domain_sources WHERE id = ?');
                $freshStmt->execute([(int) $connection['id']]);
                $fresh = $freshStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                if (!empty($fresh['token_expires_at']) && strtotime((string) $fresh['token_expires_at']) - time() >= 60) {
                    return ['ok' => true, 'access_token' => (string) allstat_decrypt_secret($fresh['access_token_enc'] ?? null, $config)];
                }
                $refresh = allstat_refresh_access_token($pdo, $config, array_merge($connection, $fresh));
                return $refresh['ok'] ? ['ok' => true, 'access_token' => $refresh['access_token']] : $refresh;
            } finally {
                $unlock = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $unlock->execute([$lock]);
            }
        }
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

    $urlError = allstat_connection_urls_error($connection);
    if ($urlError !== null) {
        return ['ok' => false, 'message' => $urlError];
    }

    if ($providerKey === 'clarity') {
        $token = allstat_decrypt_secret($connection['access_token_enc'] ?? null, $config);
        if (!$token) {
            return ['ok' => false, 'message' => t('Vyplňte Clarity API token do pole Access token.')];
        }
        $response = allstat_http_request(
            'GET',
            'https://www.clarity.ms/export-data/api/v1/project-live-insights?numOfDays=1',
            ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json']
        );
        if ($response['status'] === 200) {
            return ['ok' => true, 'message' => t('Clarity API odpovídá.'), 'http' => 200];
        }
        return ['ok' => false, 'http' => $response['status'], 'message' => t('Clarity API: {detail}', ['detail' => substr($response['body'], 0, 200)])];
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
                return ['ok' => false, 'message' => t('Property ID musí být ve formátu "properties/123456789".')];
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
                    'message' => t('GA4 property "{property}" odpovídá. Dostupné dimenze: {dims}, metriky: {metrics}.', ['property' => $property, 'dims' => $dims, 'metrics' => $metrics]),
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
                        ? t('Search Console: účet má přístup k property "{property}".', ['property' => $property])
                        : t('Search Console odpovídá, ale property "{property}" v účtu není. Dostupné: {available}', ['property' => $property, 'available' => implode(', ', array_map(fn($s) => $s['siteUrl'] ?? '?', $sites))]),
                ];
            }
            return ['ok' => false, 'http' => $response['status'], 'message' => allstat_explain_google_error($response, $connection['property_id'] ?? '')];

        case 'google_ads':
            $customerId = preg_replace('/[^0-9]/', '', (string) ($connection['property_id'] ?? ''));
            if ($customerId === '') {
                return ['ok' => false, 'message' => t('Property ID (Customer ID bez pomlček) chybí.')];
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
                'message' => t('Google Ads odpovídá: účet „{name}"{currency}.', [
                    'name' => $customer['descriptiveName'] ?? $customerId,
                    'currency' => !empty($customer['currencyCode']) ? t(', měna {currency}', ['currency' => $customer['currencyCode']]) : '',
                ]),
            ];

        case 'microsoft_ads':
            require_once __DIR__ . '/microsoft-ads.php';
            try {
                $day = (new DateTimeImmutable('yesterday'))->format('Y-m-d');
                $rows = allstat_microsoft_ads_report($connection, $token, $day, $day, false);
                $currency = (string) ($rows[0]['CurrencyCode'] ?? '');
                $name = (string) ($rows[0]['AccountName'] ?? $connection['property_id'] ?? '');
                return ['ok' => true, 'http' => 200, 'message' => t('Microsoft Ads odpovídá: účet „{name}"{currency}.', [
                    'name' => $name,
                    'currency' => $currency !== '' ? t(', měna {currency}', ['currency' => $currency]) : '',
                ])];
            } catch (Throwable $error) {
                return ['ok' => false, 'message' => mb_substr($error->getMessage(), 0, 190)];
            }

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
                        ? t('YouTube: kanál s ID "{id}" neexistuje nebo k němu účet nemá přístup.', ['id' => $channelId])
                        : t('YouTube: autorizovaný Google účet nemá žádný kanál. Spusť OAuth znovu a vyber účet značky (Brand Account) s kanálem, nebo vyplň ID kanálu.')];
                }
                $stats = $item['statistics'] ?? [];
                return ['ok' => true, 'http' => 200, 'message' => t('YouTube: kanál „{title}" ({id}), odběratelů: {subscribers}, videí: {videos}, zhlédnutí celkem: {views}.', [
                    'title' => (string) ($item['snippet']['title'] ?? '?'), 'id' => (string) ($item['id'] ?? '?'),
                    'subscribers' => allstat_number((int) ($stats['subscriberCount'] ?? 0)), 'videos' => allstat_number((int) ($stats['videoCount'] ?? 0)), 'views' => allstat_number((int) ($stats['viewCount'] ?? 0)),
                ])];
            }
            return ['ok' => false, 'http' => $response['status'], 'message' => allstat_explain_youtube_error($response)];

        case 'facebook_pages':
        case 'instagram_business':
        case 'meta_ads':
            $response = allstat_http_request('GET', 'https://graph.facebook.com/v25.0/me?access_token=' . urlencode($token), []);
            return [
                'ok' => $response['status'] === 200,
                'http' => $response['status'],
                'message' => $response['status'] === 200 ? t('Meta Graph API odpovídá (me={id}).', ['id' => $response['json']['id'] ?? '?']) : substr($response['body'], 0, 240),
            ];

        case 'linkedin_company':
            // Test the SAME capability the sync uses — organization share statistics — NOT /v2/me.
            // /v2/me is the member-profile endpoint (needs r_liteprofile/openid); a company token
            // (r_organization_social, rw_organization_admin) has no access to it, so it always 403'd with
            // "ACCESS_DENIED … me.GET.NO_VERSION" — saying nothing about whether the org sync would work.
            $base = rtrim((string) ($connection['api_base_url'] ?: 'https://api.linkedin.com/v2'), '/');
            $urn = trim((string) ($connection['property_id'] ?? ''));
            if ($urn === '') {
                return ['ok' => false, 'message' => t('Vyplň Property ID = URN organizace (urn:li:organization:ČÍSLO) a ulož napojení; teprve pak jde test spustit.')];
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
                return ['ok' => true, 'http' => 200, 'message' => t('LinkedIn: token má přístup ke statistikám organizace {urn}. Napojení funguje.', ['urn' => $urn])];
            }
            return ['ok' => false, 'http' => $response['status'], 'message' => allstat_explain_linkedin_error($response, $urn)];
    }

    return ['ok' => false, 'message' => t('Pro providera "{provider}" zatím není test definovaný.', ['provider' => $providerKey])];
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
    $detail = $apiMsg !== '' ? ' ' . t('Detail z LinkedIn: {detail}', ['detail' => $apiMsg]) : ($body !== '' ? ' ' . t('Detail: {detail}', ['detail' => substr($body, 0, 160)]) : '');
    $hay = $apiMsg . ' ' . $body;

    // LinkedIn vrací 403 i pro NEPLATNÉ Property ID (problém s hodnotou, ne s oprávněním):
    // "Field Value validation failed … Data Processing Exception while processing fields [/organizationalEntity]".
    if (stripos($hay, 'Field Value validation') !== false || stripos($hay, 'Data Processing Exception') !== false || stripos($hay, '[/organizationalEntity]') !== false) {
        return t('Property ID „{urn}" LinkedIn nepřijal, není to platné URN organizace. Musí být přesně ve tvaru urn:li:organization:ČÍSLO (číselné ID stránky, ne název ani odkaz). Číslo najdeš v LinkedIn Developers → tvoje app → Settings (u propojené stránky) nebo v adrese stránky jako správce (.../company/ČÍSLO/). Stačí zadat i samotné číslo, AllStat ho na URN doplní sám.', ['urn' => $urn]) . $detail;
    }
    if ($status === 403 || $code === 'ACCESS_DENIED' || stripos($hay, 'Not enough permissions') !== false) {
        return t('HTTP 403 – token nemá oprávnění ke statistikám organizace. Nejčastější příčina: LinkedIn ještě neschválil produkt „Community Management API" (po podání žádosti to trvá řádově dny; do schválení je 403 normální, počkej). Další možnosti: nejsi správce (admin) této LinkedIn stránky, nebo OAuth proběhl bez scope r_organization_social – zkontroluj pole „OAuth scopes" a spusť OAuth znovu.') . $detail;
    }
    if ($status === 401) {
        return t('HTTP 401 – přihlášení k LinkedIn vypršelo nebo je neplatné (token platí 60 dní a bez schváleného produktu se sám neobnovuje). Dej „Spustit OAuth" znovu.') . $detail;
    }
    if ($status === 400) {
        return t('HTTP 400 – nejspíš špatné Property ID. Musí být přesně ve tvaru urn:li:organization:ČÍSLO (číselné ID stránky, ne název).') . $detail;
    }
    if ($status === 429) {
        return t('HTTP 429 – vyčerpán denní limit LinkedIn API (Development tier 500/app/den, 100/člen/den, reset o půlnoci UTC). Zkus to zítra.');
    }

    return t('HTTP {status} z LinkedIn API.', ['status' => $status]) . $detail;
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
    $detail = $msg !== '' ? ' ' . t('Detail: {detail}', ['detail' => mb_substr($msg, 0, 200)]) : ' ' . mb_substr((string) ($response['body'] ?? ''), 0, 160);
    $hay = $reason . ' ' . $msg;

    if (stripos($hay, 'accessNotConfigured') !== false || stripos($hay, 'has not been used in project') !== false || stripos($hay, 'is disabled') !== false) {
        return t('HTTP {status} [accessNotConfigured]: v Google Cloud projektu není povolené YouTube API. Povol „YouTube Data API v3" i „YouTube Analytics API" (APIs & Services → Library) a zkus to znovu.', ['status' => $status]) . $detail;
    }
    if (stripos($hay, 'insufficientPermissions') !== false || stripos($hay, 'ACCESS_TOKEN_SCOPE_INSUFFICIENT') !== false || stripos($hay, 'insufficient authentication scopes') !== false) {
        return t('HTTP {status}: token nemá oprávnění k YouTube (chybí scope youtube.readonly / yt-analytics.readonly). Dej „Spustit OAuth" znovu a potvrď obě oprávnění.', ['status' => $status]) . $detail;
    }
    if (stripos($hay, 'quotaExceeded') !== false || stripos($hay, 'RATE_LIMIT') !== false || $status === 429) {
        return t('HTTP {status}: vyčerpaná denní kvóta YouTube API (resetuje se o půlnoci pacifického času). Zkus to později.', ['status' => $status]) . $detail;
    }
    if ($status === 403) {
        return t('HTTP 403 [{reason}]: autorizovaný Google účet nemá ke kanálu oprávnění (YouTube Analytics vyžaduje vlastníka nebo správce kanálu). Spusť OAuth znovu správným účtem, u účtu značky vyber při přihlášení účet s názvem kanálu.', ['reason' => $reason]) . $detail;
    }
    if ($status === 401) {
        return t('HTTP 401: přihlášení ke Googlu vypršelo nebo bylo odvoláno. Dej „Spustit OAuth" znovu.') . $detail;
    }
    if ($status === 400) {
        return t('HTTP 400 [{reason}]: YouTube API odmítlo dotaz (neplatné ID kanálu nebo parametry).', ['reason' => $reason]) . $detail;
    }

    return t('HTTP {status}{reason} z YouTube API.', ['status' => $status, 'reason' => $reason !== '' ? ' [' . $reason . ']' : '']) . $detail;
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
            return t('HTTP 403{reason}: uživatel nemá v této property oprávnění. Přidej Google účet do GA4 (Admin → Property access management) jako Viewer. Detail: {detail}', ['reason' => $reason, 'detail' => $msg]);
        }
        if ($response['status'] === 404) {
            return t('HTTP 404: property "{property}" neexistuje. Zkontroluj Property ID. Detail: {detail}', ['property' => $property, 'detail' => $msg]);
        }
        if ($response['status'] === 401) {
            return t('HTTP 401: access token vypršel nebo je neplatný. Spusť OAuth znovu. Detail: {detail}', ['detail' => $msg]);
        }
        return t('HTTP {status}: {detail}', ['status' => $response['status'], 'detail' => $msg]);
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
            return t('HTTP 404: Google Ads API {version} není dostupná (Google tuto verzi nejspíš vypnul). AllStat je potřeba aktualizovat.', ['version' => ALLSTAT_GOOGLE_ADS_API_VERSION]);
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
        return t('HTTP {status}: Google Cloud projekt OAuth klienta má jen testovací přístup a na skutečné účty nesmí. Cloud Console → Google Ads API → Overview → „Upgrade access level" → požádej o Explorer (schválení obvykle do 10 pracovních dnů).', ['status' => $status]);
    }
    if ($has('SERVICE_DISABLED') || str_contains($msg, 'has not been used in project')) {
        return t('HTTP {status}: v Google Cloud projektu není povolené Google Ads API. Cloud Console → APIs & Services → Library → Google Ads API → Enable.', ['status' => $status]);
    }
    if ($has('USER_PERMISSION_DENIED')) {
        return t('HTTP {status}: Google účet, kterým jsi prošel OAuth, nemá k tomuto Customer ID přístup. Když účet spravuješ přes manažerský (MCC) účet, vyplň MCC / Login customer ID.', ['status' => $status]);
    }
    if ($has('CUSTOMER_NOT_ENABLED')) {
        return t('HTTP {status}: účet Google Ads není aktivní (zrušený nebo nedokončený).', ['status' => $status]);
    }
    if ($status === 401) {
        return t('HTTP 401: přihlášení vypršelo nebo je neplatné. Spusť OAuth znovu.');
    }
    return 'Google Ads API HTTP ' . $status . ($codes ? ' [' . implode(', ', array_unique($codes)) . ']' : '') . ': ' . substr($msg, 0, 200);
}
