<?php

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/providers.php';

function allstat_admin_domains(PDO $pdo, bool $includeInactive = true): array
{
    $sql = 'SELECT d.*, COUNT(ds.id) AS source_count FROM domains d LEFT JOIN domain_sources ds ON ds.domain_id = d.id';
    $where = [];
    $params = [];

    if (!$includeInactive) {
        $where[] = 'd.is_active = 1';
    }

    // Uživatel s přístupem jen k vybraným webům (Reporty) vidí jen je, administrátor všechny.
    $scope = function_exists('allstat_domain_scope') ? allstat_domain_scope() : null;
    if ($scope === []) {
        return [];
    }
    if ($scope !== null) {
        $where[] = 'd.id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')';
        $params = $scope;
    }

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' GROUP BY d.id ORDER BY d.is_active DESC, d.name ASC';

    return allstat_fetch_all($pdo, $sql, $params);
}

function allstat_save_domain(PDO $pdo, ?int $actorId, array $data): array
{
    $id = (int) ($data['id'] ?? 0);
    $name = trim((string) ($data['name'] ?? ''));
    $url = trim((string) ($data['url'] ?? ''));
    $isActive = !empty($data['is_active']) ? 1 : 0;

    if ($name === '' || $url === '') {
        return ['ok' => false, 'message' => 'Vyplňte název i doménu.'];
    }

    $url = preg_replace('#^https?://#', '', $url) ?? $url;
    $url = trim($url, '/');

    try {
        if ($id > 0) {
            $statement = $pdo->prepare('UPDATE domains SET name = ?, url = ?, is_active = ? WHERE id = ?');
            $statement->execute([$name, $url, $isActive, $id]);
            allstat_audit($pdo, $actorId, null, 'domain_updated', $url);
            return ['ok' => true, 'message' => 'Doména byla uložena.'];
        }

        $statement = $pdo->prepare('INSERT INTO domains (name, url, is_active) VALUES (?, ?, ?)');
        $statement->execute([$name, $url, $isActive]);
        allstat_audit($pdo, $actorId, null, 'domain_created', $url);
        return ['ok' => true, 'message' => 'Doména byla vytvořena.'];
    } catch (Throwable $exception) {
        return ['ok' => false, 'message' => str_contains($exception->getMessage(), 'Duplicate') ? 'Tato doména už existuje.' : 'Doménu se nepodařilo uložit.'];
    }
}

function allstat_delete_domain(PDO $pdo, ?int $actorId, int $id): array
{
    $pdo->beginTransaction();

    try {
        allstat_purge_domain_metrics($pdo, $id);
        $pdo->prepare('DELETE FROM allstat_user_domains WHERE domain_id = ?')->execute([$id]);
        $statement = $pdo->prepare('DELETE FROM domains WHERE id = ?');
        $statement->execute([$id]);
        allstat_audit($pdo, $actorId, null, 'domain_deleted', (string) $id);
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        return ['ok' => false, 'message' => 'Doménu se nepodařilo odstranit.'];
    }

    return ['ok' => true, 'message' => 'Doména byla odstraněna včetně navázaných dat.'];
}

function allstat_data_sources(PDO $pdo): array
{
    return allstat_fetch_all($pdo, 'SELECT * FROM data_sources ORDER BY category ASC, name ASC');
}

function allstat_source_connections(PDO $pdo, ?int $domainId = null): array
{
    $params = [];
    $where = '';

    if ($domainId) {
        $where = 'WHERE ds.domain_id = ?';
        $params[] = $domainId;
    }

    return allstat_fetch_all($pdo, "
        SELECT ds.*, d.name AS domain_name, d.url AS domain_url, s.provider_key, s.name AS source_name, s.category, s.supports_oauth, s.docs_url
        FROM domain_sources ds
        INNER JOIN domains d ON d.id = ds.domain_id
        INNER JOIN data_sources s ON s.id = ds.source_id
        $where
        ORDER BY d.name ASC, s.category ASC, s.name ASC
    ", $params);
}

function allstat_get_connection(PDO $pdo, int $id): ?array
{
    $row = allstat_fetch_one($pdo, '
        SELECT ds.*, d.name AS domain_name, d.url AS domain_url, s.provider_key, s.name AS source_name, s.category, s.supports_oauth, s.default_scopes, s.docs_url
        FROM domain_sources ds
        INNER JOIN domains d ON d.id = ds.domain_id
        INNER JOIN data_sources s ON s.id = ds.source_id
        WHERE ds.id = ?
    ', [$id]);

    if ($row && !empty($row['config_json_enc'])) {
        global $config;
        if (is_array($config)) {
            $decrypted = allstat_decrypt_secret($row['config_json_enc'], $config);
            if ($decrypted !== null) {
                $row['config_json'] = $decrypted;
            }
        }
    }

    return $row;
}

function allstat_prefill_connection(PDO $pdo, int $sourceId): array
{
    $source = allstat_fetch_one($pdo, 'SELECT * FROM data_sources WHERE id = ?', [$sourceId]);

    if (!$source) {
        return [];
    }

    return [
        'source_id' => (int) $source['id'],
        'source_name' => $source['name'],
        'category' => $source['category'],
        'scopes' => $source['default_scopes'] ?? '',
        'auth_url' => $source['auth_url'] ?? '',
        'token_url' => $source['token_url'] ?? '',
        'api_base_url' => $source['api_base_url'] ?? '',
        'status' => 'warning',
        'is_enabled' => 1,
    ];
}

/**
 * OAuth "family" = providers that share one OAuth app/client, so credentials can be reused:
 * all Google APIs share a Google Cloud OAuth client; all Meta APIs share a Meta app.
 */
function allstat_oauth_family(string $providerKey): ?string
{
    if (in_array($providerKey, ['ga4', 'gsc', 'google_ads', 'youtube'], true)) { return 'google'; }
    if (in_array($providerKey, ['facebook_pages', 'instagram_business', 'meta_ads'], true)) { return 'meta'; }

    return null;
}

function allstat_oauth_family_providers(string $family): array
{
    return match ($family) {
        'google' => ['ga4', 'gsc', 'google_ads', 'youtube'],
        'meta' => ['facebook_pages', 'instagram_business', 'meta_ads'],
        default => [],
    };
}

/**
 * Find an existing connection in the same OAuth family that already has Client ID + Secret,
 * so a new connection can reuse the same OAuth app (e.g. GA4 → GSC). GA4 is preferred as donor.
 */
function allstat_oauth_donor(PDO $pdo, int $excludeConnectionId, string $providerKey): ?array
{
    return allstat_oauth_donors($pdo, $excludeConnectionId, $providerKey)[0] ?? null;
}

/**
 * All same-family donors, one per DISTINCT Client ID (= one per OAuth app / Google project). Two Google
 * connections can legitimately live in different projects (jeden web = interní Workspace projekt jen pro
 * @vase-firma.cz účty, jiný web = externí publikovaný projekt); the user must be able to pick which one to
 * reuse, otherwise a Gmail-authorized OAuth on the internal app ends with "403 org_internal".
 * Each row: id, client_id, client_secret_enc, name (provider), domain_url, account_label, label (for buttons).
 */
function allstat_oauth_donors(PDO $pdo, int $excludeConnectionId, string $providerKey): array
{
    $family = allstat_oauth_family($providerKey);
    if ($family === null) {
        return [];
    }
    $providers = allstat_oauth_family_providers($family);
    $placeholders = implode(',', array_fill(0, count($providers), '?'));

    $rows = allstat_fetch_all($pdo, "
        SELECT ds.id, ds.client_id, ds.client_secret_enc, ds.account_label, s.name, d.url AS domain_url
        FROM domain_sources ds
        INNER JOIN data_sources s ON s.id = ds.source_id
        INNER JOIN domains d ON d.id = ds.domain_id
        WHERE s.provider_key IN ($placeholders)
          AND ds.id <> ?
          AND ds.client_id IS NOT NULL AND ds.client_id <> ''
          AND ds.client_secret_enc IS NOT NULL AND ds.client_secret_enc <> ''
        ORDER BY (s.provider_key = 'ga4') DESC, ds.updated_at DESC
    ", array_merge($providers, [$excludeConnectionId]));

    $out = [];
    foreach ($rows as $row) {
        $cid = (string) $row['client_id'];
        if (isset($out[$cid])) {
            continue;
        }
        $row['label'] = (string) $row['name'] . ' (' . (string) $row['domain_url'] . ')';
        $out[$cid] = $row;
    }

    return array_values($out);
}

/**
 * Copy Client ID + (encrypted) Client secret from a same-family donor connection into this one.
 * The encrypted secret is copied as-is (same encryption key), so no decrypt/re-encrypt is needed.
 */
function allstat_inherit_oauth_credentials(PDO $pdo, int $connectionId, int $donorId = 0): array
{
    $connection = allstat_get_connection($pdo, $connectionId);
    if (!$connection) {
        return ['ok' => false, 'message' => 'Napojení neexistuje.'];
    }

    $donors = allstat_oauth_donors($pdo, $connectionId, (string) $connection['provider_key']);
    $donor = $donors[0] ?? null;
    if ($donorId > 0) {
        // Konkrétní dárce z tlačítka (víc Google projektů) – musí být mezi platnými kandidáty.
        $donor = null;
        foreach ($donors as $d) {
            if ((int) $d['id'] === $donorId) { $donor = $d; break; }
        }
        if (!$donor) {
            return ['ok' => false, 'message' => 'Zvolené napojení nejde použít jako zdroj údajů (chybí Client ID/Secret nebo je z jiné rodiny).'];
        }
    }
    if (!$donor) {
        return ['ok' => false, 'message' => 'Nenašel jsem jiné napojení (stejná rodina) s vyplněným Client ID a Secret, ze kterého převzít údaje.'];
    }

    $pdo->prepare('UPDATE domain_sources SET client_id = ?, client_secret_enc = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$donor['client_id'], $donor['client_secret_enc'], $connectionId]);

    return ['ok' => true, 'message' => 'Převzato Client ID i Client secret z „' . ($donor['label'] ?? $donor['name']) . '" (Client ID ' . substr((string) $donor['client_id'], 0, 12) . '…). Teď už stačí jen Spustit OAuth.'];
}

function allstat_save_connection(PDO $pdo, array $config, ?int $actorId, array $data): array
{
    $id = (int) ($data['id'] ?? 0);
    $domainId = (int) ($data['domain_id'] ?? 0);
    $sourceId = (int) ($data['source_id'] ?? 0);
    $status = in_array(($data['status'] ?? 'warning'), ['ok', 'warning', 'error'], true) ? $data['status'] : 'warning';
    $existing = $id > 0 ? allstat_get_connection($pdo, $id) : null;

    if ($domainId <= 0 || $sourceId <= 0) {
        return ['ok' => false, 'message' => 'Vyberte web i zdroj dat.'];
    }

    $source = allstat_fetch_one($pdo, 'SELECT provider_key FROM data_sources WHERE id = ?', [$sourceId]);
    $providerKey = (string) ($source['provider_key'] ?? '');

    foreach (['auth_url' => 'Authorization URL', 'token_url' => 'Token URL', 'api_base_url' => 'API Base URL'] as $field => $label) {
        if (!allstat_provider_url_allowed($providerKey, (string) ($data[$field] ?? ''))) {
            return ['ok' => false, 'message' => $label . ' musí používat HTTPS a povolenou doménu providera.'];
        }
    }

    $configJson = trim((string) ($data['config_json'] ?? ''));
    if ($configJson !== '') {
        json_decode($configJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['ok' => false, 'message' => 'Doplňková konfigurace musí být validní JSON.'];
        }
    }

    $secretFields = ['client_secret' => 'client_secret_enc', 'access_token' => 'access_token_enc', 'refresh_token' => 'refresh_token_enc'];
    $encrypted = [];

    foreach ($secretFields as $input => $column) {
        $newValue = trim((string) ($data[$input] ?? ''));
        $encrypted[$column] = $newValue !== '' ? allstat_encrypt_secret($newValue, $config) : ($existing[$column] ?? null);
    }

    $propertyId = trim((string) ($data['property_id'] ?? ''));
    if ($providerKey === 'ga4' && $propertyId !== '' && !str_starts_with($propertyId, 'properties/') && ctype_digit($propertyId)) {
        $propertyId = 'properties/' . $propertyId;
    }

    $values = [
        'domain_id' => $domainId,
        'source_id' => $sourceId,
        'account_label' => trim((string) ($data['account_label'] ?? '')) ?: null,
        'property_id' => $propertyId ?: null,
        'external_account_id' => trim((string) ($data['external_account_id'] ?? '')) ?: null,
        'client_id' => trim((string) ($data['client_id'] ?? '')) ?: null,
        'client_secret_enc' => $encrypted['client_secret_enc'],
        'access_token_enc' => $encrypted['access_token_enc'],
        'refresh_token_enc' => $encrypted['refresh_token_enc'],
        'scopes' => trim((string) ($data['scopes'] ?? '')) ?: null,
        'auth_url' => trim((string) ($data['auth_url'] ?? '')) ?: null,
        'token_url' => trim((string) ($data['token_url'] ?? '')) ?: null,
        'api_base_url' => trim((string) ($data['api_base_url'] ?? '')) ?: null,
        'config_json' => null,
        'config_json_enc' => $configJson !== '' ? allstat_encrypt_secret($configJson, $config) : null,
        'status' => $status,
        'last_sync_at' => str_replace('T', ' ', trim((string) ($data['last_sync_at'] ?? ''))) ?: null,
        'token_expires_at' => str_replace('T', ' ', trim((string) ($data['token_expires_at'] ?? ''))) ?: null,
        'note' => trim((string) ($data['note'] ?? '')) ?: null,
        'is_enabled' => !empty($data['is_enabled']) ? 1 : 0,
    ];

    if ($id > 0) {
        $sql = 'UPDATE domain_sources SET domain_id = :domain_id, source_id = :source_id, account_label = :account_label, property_id = :property_id, external_account_id = :external_account_id, client_id = :client_id, client_secret_enc = :client_secret_enc, access_token_enc = :access_token_enc, refresh_token_enc = :refresh_token_enc, scopes = :scopes, auth_url = :auth_url, token_url = :token_url, api_base_url = :api_base_url, config_json = :config_json, config_json_enc = :config_json_enc, status = :status, last_sync_at = :last_sync_at, token_expires_at = :token_expires_at, note = :note, is_enabled = :is_enabled WHERE id = :id';
        $values['id'] = $id;
        $statement = $pdo->prepare($sql);
        $statement->execute($values);
        allstat_audit($pdo, $actorId, null, 'source_connection_updated', (string) $id);

        return ['ok' => true, 'id' => $id, 'message' => 'Napojení zdroje bylo uloženo.'];
    }

    try {
        $sql = 'INSERT INTO domain_sources (domain_id, source_id, account_label, property_id, external_account_id, client_id, client_secret_enc, access_token_enc, refresh_token_enc, scopes, auth_url, token_url, api_base_url, config_json, config_json_enc, status, last_sync_at, token_expires_at, note, is_enabled) VALUES (:domain_id, :source_id, :account_label, :property_id, :external_account_id, :client_id, :client_secret_enc, :access_token_enc, :refresh_token_enc, :scopes, :auth_url, :token_url, :api_base_url, :config_json, :config_json_enc, :status, :last_sync_at, :token_expires_at, :note, :is_enabled)';
        $statement = $pdo->prepare($sql);
        $statement->execute($values);
        $id = (int) $pdo->lastInsertId();
        allstat_audit($pdo, $actorId, null, 'source_connection_created', (string) $id);

        return ['ok' => true, 'id' => $id, 'message' => 'Napojení zdroje bylo vytvořeno.'];
    } catch (Throwable $exception) {
        return ['ok' => false, 'message' => str_contains($exception->getMessage(), 'Duplicate') ? 'Tento zdroj už je pro daný web založený.' : 'Napojení se nepodařilo uložit.'];
    }
}

function allstat_delete_connection(PDO $pdo, ?int $actorId, int $id): array
{
    $connection = allstat_fetch_one($pdo, 'SELECT domain_id, source_id FROM domain_sources WHERE id = ?', [$id]);

    if (!$connection) {
        return ['ok' => false, 'message' => 'Napojení neexistuje.'];
    }

    $domainId = (int) $connection['domain_id'];
    $pdo->beginTransaction();

    try {
        $statement = $pdo->prepare('INSERT INTO domain_source_deletions (domain_id, source_id, reason) VALUES (?, ?, ?)');
        $statement->execute([$domainId, (int) $connection['source_id'], 'source_deleted']);

        $statement = $pdo->prepare('DELETE FROM domain_sources WHERE id = ?');
        $statement->execute([$id]);

        allstat_audit($pdo, $actorId, null, 'source_connection_deleted', (string) $id);
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        return ['ok' => false, 'message' => 'Napojení se nepodařilo odstranit.'];
    }

    return ['ok' => true, 'message' => 'Napojení zdroje bylo odstraněno. Statistiky zůstanou v DB minimálně 30 dnů pro export.'];
}

function allstat_purge_domain_metrics(PDO $pdo, int $domainId): void
{
    foreach (['search_queries_daily', 'landing_pages_daily', 'traffic_sources_daily', 'metrics_daily', 'sync_logs'] as $table) {
        $statement = $pdo->prepare("DELETE FROM $table WHERE domain_id = ?");
        $statement->execute([$domainId]);
    }
}

function allstat_sync_logs(PDO $pdo, int $limit = 100): array
{
    return allstat_fetch_all($pdo, '
        SELECT l.*, d.name AS domain_name, d.url AS domain_url, s.name AS source_name
        FROM sync_logs l
        INNER JOIN domains d ON d.id = l.domain_id
        INNER JOIN data_sources s ON s.id = l.source_id
        ORDER BY l.created_at DESC
        LIMIT ' . max(1, min(500, $limit))
    );
}

function allstat_add_sync_log(PDO $pdo, int $domainId, int $sourceId, string $level, string $message): void
{
    $level = in_array($level, ['info', 'warning', 'error'], true) ? $level : 'info';
    $statement = $pdo->prepare('INSERT INTO sync_logs (domain_id, source_id, level, message) VALUES (?, ?, ?, ?)');
    $statement->execute([$domainId, $sourceId, $level, $message]);
}
