<?php

require_once __DIR__ . '/providers.php';

/**
 * Verze schématu — fast-path guard: když uložená verze v allstat_settings sedí, allstat_migrate()
 * přeskočí všech ~97 kontrolních dotazů (CREATE TABLE IF NOT EXISTS + information_schema) a stojí
 * 2 dotazy místo ~25 ms na KAŽDÉM requestu.
 *
 * DŮLEŽITÉ: při JAKÉKOLI změně migrací (nová tabulka / sloupec / index, změna katalogu providerů
 * v allstat_default_providers, nové výchozí settings) ZVEDNI tuhle konstantu — jinak se změna na
 * produkci neprovede. Formát: YYYY-MM-DD.N.
 */
const ALLSTAT_SCHEMA_VERSION = '2026-10-01.3';

function allstat_schema_is_current(PDO $pdo): bool
{
    try {
        $statement = $pdo->prepare('SELECT setting_value FROM allstat_settings WHERE setting_key = ?');
        $statement->execute(['schema.version']);

        return $statement->fetchColumn() === ALLSTAT_SCHEMA_VERSION;
    } catch (Throwable) {
        return false; // čerstvá instalace (allstat_settings ještě neexistuje) → plná migrace
    }
}

function allstat_table_exists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $statement->execute([$table]);

    return (int) $statement->fetchColumn() > 0;
}

function allstat_column_exists(PDO $pdo, string $table, string $column): bool
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $statement->execute([$table, $column]);

    return (int) $statement->fetchColumn() > 0;
}

function allstat_add_column(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!allstat_column_exists($pdo, $table, $column)) {
        $pdo->exec('ALTER TABLE `' . str_replace('`', '``', $table) . '` ADD COLUMN ' . $definition);
    }
}

function allstat_index_exists(PDO $pdo, string $table, string $index): bool
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $statement->execute([$table, $index]);

    return (int) $statement->fetchColumn() > 0;
}

function allstat_add_index(PDO $pdo, string $table, string $index, string $definition): void
{
    if (allstat_table_exists($pdo, $table) && !allstat_index_exists($pdo, $table, $index)) {
        $pdo->exec('ALTER TABLE `' . str_replace('`', '``', $table) . '` ADD ' . $definition);
    }
}

function allstat_drop_index(PDO $pdo, string $table, string $index): void
{
    if (allstat_table_exists($pdo, $table) && allstat_index_exists($pdo, $table, $index)) {
        try {
            $pdo->exec('ALTER TABLE `' . str_replace('`', '``', $table) . '` DROP INDEX `' . str_replace('`', '``', $index) . '`');
        } catch (Throwable) {
            // A leftover index may still back a foreign key on some engines — leaving it is harmless
            // because the newly-added key already governs writes. Never fatal the request over it.
        }
    }
}

/**
 * Marker migrace tabulek MCP konektoru: nezávislý na schema.version. Dokud sedí, DDL MCP se přeskakuje; zapisuje se až
 * po úspěšném doběhnutí, takže selhání se zkouší znovu při dalších požadavcích.
 */
const ALLSTAT_MCP_SCHEMA_VERSION = '2026-09-30.1';

function allstat_mcp_schema_is_current(PDO $pdo): bool
{
    try {
        $statement = $pdo->prepare('SELECT setting_value FROM allstat_settings WHERE setting_key = ?');
        $statement->execute(['mcp.schema.version']);

        return $statement->fetchColumn() === ALLSTAT_MCP_SCHEMA_VERSION;
    } catch (Throwable) {
        return false;
    }
}

/**
 * Tabulky MCP konektoru (OAuth server Claude / ChatGPT, log požadavků, limiter) a výchozí nastavení. Je izolovaná od
 * zbytku migrace: chyba (např. chybějící právo CREATE) se jen zapíše do error_log a vrací false, zbytek migrace i celá
 * aplikace běží dál. Marker mcp.schema.version se zapíše až po úspěchu; po chybě se další pokus odloží o 5 minut
 * (mcp.schema.retry_after), aby se DDL nezkoušelo při každém požadavku. Dokud marker chybí, allstat_mcp_is_enabled()
 * vrací false.
 */
function allstat_migrate_mcp(PDO $pdo): bool
{
    try {
        if ((int) allstat_migration_setting_value($pdo, 'mcp.schema.retry_after', '0') > time()) {
            return false;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS allstat_mcp_clients (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            client_id VARCHAR(512) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            type ENUM('dcr','cimd') NOT NULL,
            client_name VARCHAR(190) NOT NULL DEFAULT '',
            redirect_uris TEXT NOT NULL,
            metadata MEDIUMTEXT NULL,
            created_at DATETIME NOT NULL,
            fetched_at DATETIME NULL,
            cache_until DATETIME NULL,
            last_used_at DATETIME NULL,
            created_ip VARCHAR(45) NULL,
            UNIQUE KEY uq_mcp_client (client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS allstat_mcp_grants (
            grant_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            client_id VARCHAR(512) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            client_name VARCHAR(190) NOT NULL DEFAULT '',
            redirect_host VARCHAR(190) NOT NULL DEFAULT '',
            scope VARCHAR(190) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            last_used_at DATETIME NULL,
            revoked_at DATETIME NULL,
            created_ip VARCHAR(45) NULL,
            KEY idx_mcp_grant_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS allstat_mcp_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            kind ENUM('code','access','refresh') NOT NULL,
            grant_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            client_id VARCHAR(512) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            scope VARCHAR(190) NOT NULL DEFAULT '',
            resource VARCHAR(512) NOT NULL DEFAULT '',
            redirect_uri VARCHAR(1024) NULL,
            code_challenge VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            last_used_at DATETIME NULL,
            UNIQUE KEY uq_mcp_token (token_hash),
            KEY idx_mcp_token_grant (grant_id),
            KEY idx_mcp_token_exp (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS allstat_mcp_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            created_at DATETIME NOT NULL,
            user_id INT UNSIGNED NULL,
            grant_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
            client_name VARCHAR(190) NOT NULL DEFAULT '',
            method VARCHAR(64) NOT NULL,
            tool VARCHAR(64) NULL,
            args_json TEXT NULL,
            status ENUM('ok','error','denied') NOT NULL,
            error VARCHAR(255) NULL,
            duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            response_bytes INT UNSIGNED NOT NULL DEFAULT 0,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(190) NULL,
            KEY idx_mcp_log_time (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS allstat_mcp_ratelimit (
            bucket VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            window_start INT UNSIGNED NOT NULL,
            hits INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (bucket, window_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci");

        // Výchozí stav: vypnuto; adresy (mcp.host_url, mcp.app_url) nastaví administrátor na stránce AI konektory.
        allstat_setting_default($pdo, 'mcp.enabled', '0');
        allstat_setting_default($pdo, 'mcp.access_ttl', '28800');
        allstat_setting_default($pdo, 'mcp.refresh_ttl', '5184000');

        allstat_migration_set_setting($pdo, 'mcp.schema.version', ALLSTAT_MCP_SCHEMA_VERSION);
        $pdo->prepare('DELETE FROM allstat_settings WHERE setting_key = ?')->execute(['mcp.schema.retry_after']);

        return true;
    } catch (Throwable $exception) {
        error_log('AllStat: migrace tabulek MCP selhala, MCP zůstává vypnuté a zkusí se to znovu: ' . $exception->getMessage());

        try {
            allstat_migration_set_setting($pdo, 'mcp.schema.retry_after', (string) (time() + 300));
        } catch (Throwable) {
            // bez zápisu se pokus prostě zopakuje při dalším požadavku
        }

        return false;
    }
}

function allstat_migrate(PDO $pdo): void
{
    // Fast-path: schéma už je na aktuální verzi → přeskočit DDL kontroly (viz ALLSTAT_SCHEMA_VERSION).
    // Denní údržba (retenční mazání) běží dál — sama je gated na 1× denně, takže stojí 1 dotaz.
    if (allstat_schema_is_current($pdo)) {
        if (!allstat_mcp_schema_is_current($pdo)) {
            allstat_migrate_mcp($pdo);
        }

        allstat_run_light_maintenance($pdo);

        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS allstat_users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL,
        name VARCHAR(120) NOT NULL,
        role ENUM('admin','user') NOT NULL DEFAULT 'user',
        password_hash VARCHAR(255) NOT NULL,
        totp_secret_enc TEXT NULL,
        totp_last_window BIGINT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        last_login_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY allstat_users_email_unique (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS allstat_user_audit_log (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        actor_user_id INT UNSIGNED NULL,
        target_user_id INT UNSIGNED NULL,
        action VARCHAR(80) NOT NULL,
        detail TEXT NULL,
        ip_address VARCHAR(64) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY allstat_user_audit_actor_idx (actor_user_id, created_at),
        KEY allstat_user_audit_target_idx (target_user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci");

    // Přístup uživatele k webům (2026-10-01): 'all' = všechny weby (výchozí, platí i pro dřívější účty),
    // 'selected' = jen weby z allstat_user_domains. Administrátor vidí vždy všechny (allstat_user_domain_ids v auth.php).
    allstat_add_column($pdo, 'allstat_users', 'domain_access', "domain_access ENUM('all','selected') NOT NULL DEFAULT 'all' AFTER role");
    $pdo->exec("CREATE TABLE IF NOT EXISTS allstat_user_domains (
        user_id INT UNSIGNED NOT NULL,
        domain_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, domain_id),
        KEY allstat_user_domains_domain_idx (domain_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS allstat_login_attempts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ip_address VARCHAR(64) NOT NULL,
        email VARCHAR(190) NULL,
        was_success TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY allstat_login_attempts_ip_idx (ip_address, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS allstat_settings (
        setting_key VARCHAR(120) PRIMARY KEY,
        setting_value TEXT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci");

    if (allstat_table_exists($pdo, 'data_sources')) {
        allstat_add_column($pdo, 'data_sources', 'supports_oauth', 'supports_oauth TINYINT(1) NOT NULL DEFAULT 1 AFTER category');
        allstat_add_column($pdo, 'data_sources', 'default_scopes', 'default_scopes TEXT NULL AFTER supports_oauth');
        allstat_add_column($pdo, 'data_sources', 'auth_url', 'auth_url VARCHAR(255) NULL AFTER default_scopes');
        allstat_add_column($pdo, 'data_sources', 'token_url', 'token_url VARCHAR(255) NULL AFTER auth_url');
        allstat_add_column($pdo, 'data_sources', 'api_base_url', 'api_base_url VARCHAR(255) NULL AFTER token_url');
        allstat_add_column($pdo, 'data_sources', 'docs_url', 'docs_url VARCHAR(255) NULL AFTER api_base_url');
    }

    if (allstat_table_exists($pdo, 'metrics_daily')) {
        allstat_add_column($pdo, 'metrics_daily', 'engaged_sessions', 'engaged_sessions INT UNSIGNED NOT NULL DEFAULT 0 AFTER visits');
        allstat_add_column($pdo, 'metrics_daily', 'engagement_time_sec', 'engagement_time_sec INT UNSIGNED NOT NULL DEFAULT 0 AFTER engaged_sessions');
        allstat_add_column($pdo, 'metrics_daily', 'new_users', 'new_users INT UNSIGNED NOT NULL DEFAULT 0 AFTER users_count');
        allstat_add_column($pdo, 'metrics_daily', 'revenue', 'revenue DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER conversions');
    }

    if (allstat_table_exists($pdo, 'domain_sources')) {
        allstat_add_column($pdo, 'domain_sources', 'account_label', 'account_label VARCHAR(160) NULL AFTER source_id');
        allstat_add_column($pdo, 'domain_sources', 'property_id', 'property_id VARCHAR(120) NULL AFTER account_label');
        allstat_add_column($pdo, 'domain_sources', 'external_account_id', 'external_account_id VARCHAR(160) NULL AFTER property_id');
        allstat_add_column($pdo, 'domain_sources', 'client_id', 'client_id VARCHAR(255) NULL AFTER external_account_id');
        allstat_add_column($pdo, 'domain_sources', 'client_secret_enc', 'client_secret_enc TEXT NULL AFTER client_id');
        allstat_add_column($pdo, 'domain_sources', 'access_token_enc', 'access_token_enc TEXT NULL AFTER client_secret_enc');
        allstat_add_column($pdo, 'domain_sources', 'refresh_token_enc', 'refresh_token_enc TEXT NULL AFTER access_token_enc');
        allstat_add_column($pdo, 'domain_sources', 'scopes', 'scopes TEXT NULL AFTER refresh_token_enc');
        allstat_add_column($pdo, 'domain_sources', 'auth_url', 'auth_url VARCHAR(255) NULL AFTER scopes');
        allstat_add_column($pdo, 'domain_sources', 'token_url', 'token_url VARCHAR(255) NULL AFTER auth_url');
        allstat_add_column($pdo, 'domain_sources', 'api_base_url', 'api_base_url VARCHAR(255) NULL AFTER token_url');
        allstat_add_column($pdo, 'domain_sources', 'config_json', 'config_json TEXT NULL AFTER api_base_url');
        allstat_add_column($pdo, 'domain_sources', 'is_enabled', 'is_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER config_json');
        allstat_add_column($pdo, 'domain_sources', 'created_at', 'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER note');
        allstat_add_column($pdo, 'domain_sources', 'updated_at', 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');
        allstat_add_column($pdo, 'domain_sources', 'quota_json', 'quota_json TEXT NULL AFTER note');
        allstat_add_column($pdo, 'domain_sources', 'quota_updated_at', 'quota_updated_at DATETIME NULL AFTER quota_json');
        allstat_add_column($pdo, 'domain_sources', 'config_json_enc', 'config_json_enc TEXT NULL AFTER config_json');
        allstat_add_column($pdo, 'domain_sources', 'backfill_completed_at', 'backfill_completed_at DATETIME NULL AFTER quota_updated_at');
        // Kdy napojení naposledy zpracoval cron (i neúspěšně): dávkový režim podle toho bere jen napojení, která dnes ještě nedošla.
        allstat_add_column($pdo, 'domain_sources', 'last_cron_at', 'last_cron_at DATETIME NULL AFTER last_sync_at');

        // Allow multiple connections of the same provider per domain (two Facebook pages, two
        // Instagram accounts — possibly on different Meta accounts). Re-key uniqueness to include
        // property_id so distinct pages/accounts coexist, yet the exact same page can't be added
        // twice. Add the new (domain_id-leftmost) unique before dropping the old one so the
        // domain_id foreign key never loses its supporting index.
        allstat_add_index($pdo, 'domain_sources', 'domain_sources_provider_property_unique', 'UNIQUE KEY domain_sources_provider_property_unique (domain_id, source_id, property_id)');
        allstat_drop_index($pdo, 'domain_sources', 'domain_sources_unique');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS provider_metrics_daily (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        domain_id INT UNSIGNED NOT NULL,
        source_id INT UNSIGNED NOT NULL,
        connection_id INT UNSIGNED NULL,
        metric_date DATE NOT NULL,
        metric_key VARCHAR(80) NOT NULL,
        dimension VARCHAR(120) NOT NULL DEFAULT '',
        metric_value DECIMAL(20,4) NOT NULL DEFAULT 0,
        UNIQUE KEY provider_metrics_conn_unique (domain_id, connection_id, metric_date, metric_key, dimension),
        KEY provider_metrics_conn_date_idx (domain_id, connection_id, metric_date),
        KEY provider_metrics_source_idx (source_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci");

    // Existing installs: re-key provider_metrics_daily from the provider grain (source_id =
    // data_sources.id) to the connection grain (connection_id = domain_sources.id). Without this,
    // two connections of the same provider on one domain (e.g. two Facebook pages, two Instagram
    // accounts on different Meta accounts) would overwrite each other's daily metrics.
    if (allstat_table_exists($pdo, 'provider_metrics_daily')) {
        allstat_add_column($pdo, 'provider_metrics_daily', 'connection_id', 'connection_id INT UNSIGNED NULL AFTER source_id');

        if (allstat_column_exists($pdo, 'provider_metrics_daily', 'connection_id') && allstat_table_exists($pdo, 'domain_sources')) {
            // Backfill from the historically-unique (domain_id, source_id) connection — a clean 1:1 today.
            try {
                $pdo->exec("
                    UPDATE provider_metrics_daily pm
                    INNER JOIN domain_sources ds ON ds.domain_id = pm.domain_id AND ds.source_id = pm.source_id
                    SET pm.connection_id = ds.id
                    WHERE pm.connection_id IS NULL
                ");
            } catch (Throwable) {
                // Mid-migration race on a fresh DB — the backfill simply retries on the next request.
            }
            // Add the connection-grain keys first, so the (domain_id-leftmost) FK never loses an index,
            // then drop the old provider-grain unique that would otherwise still merge two pages.
            allstat_add_index($pdo, 'provider_metrics_daily', 'provider_metrics_conn_unique', 'UNIQUE KEY provider_metrics_conn_unique (domain_id, connection_id, metric_date, metric_key, dimension)');
            allstat_add_index($pdo, 'provider_metrics_daily', 'provider_metrics_conn_date_idx', 'KEY provider_metrics_conn_date_idx (domain_id, connection_id, metric_date)');
            allstat_add_index($pdo, 'provider_metrics_daily', 'provider_metrics_source_idx', 'KEY provider_metrics_source_idx (source_id)');
            allstat_drop_index($pdo, 'provider_metrics_daily', 'provider_metrics_unique');
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_sources_daily (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        domain_id INT UNSIGNED NOT NULL,
        metric_date DATE NOT NULL,
        source VARCHAR(80) NOT NULL,
        sessions INT UNSIGNED NOT NULL DEFAULT 0,
        conversions INT UNSIGNED NOT NULL DEFAULT 0,
        UNIQUE KEY ai_sources_daily_unique (domain_id, metric_date, source),
        KEY ai_sources_daily_domain_date_idx (domain_id, metric_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS referrers_daily (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        domain_id INT UNSIGNED NOT NULL,
        metric_date DATE NOT NULL,
        source VARCHAR(190) NOT NULL,
        sessions INT UNSIGNED NOT NULL DEFAULT 0,
        conversions INT UNSIGNED NOT NULL DEFAULT 0,
        UNIQUE KEY referrers_daily_unique (domain_id, metric_date, source),
        KEY referrers_daily_domain_date_idx (domain_id, metric_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS events_daily (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        domain_id INT UNSIGNED NOT NULL,
        metric_date DATE NOT NULL,
        event_name VARCHAR(120) NOT NULL,
        event_count INT UNSIGNED NOT NULL DEFAULT 0,
        key_events INT UNSIGNED NOT NULL DEFAULT 0,
        UNIQUE KEY events_daily_unique (domain_id, metric_date, event_name),
        KEY events_daily_domain_date_idx (domain_id, metric_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci");

    // Trychtýře (2026-10-01): events_source_daily = eventy z trychtýřů rozpadlé podle zdroje/média/kampaně
    // (plní GA4 sync jen pro eventy z aktivních trychtýřů), allstat_funnels = konfigurace trychtýřů (kroky v JSON).
    // Unikátní klíč: 4 + 3 + 4 × 120 + 4 × 190 + 4 × 120 + 4 × 190 + 4 × 2 B = 2 495 B (< 3 072 B, MySQL 8.4 i MariaDB 11.4).
    $pdo->exec("CREATE TABLE IF NOT EXISTS events_source_daily (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        domain_id INT UNSIGNED NOT NULL,
        metric_date DATE NOT NULL,
        event_name VARCHAR(120) NOT NULL,
        channel VARCHAR(80) NOT NULL DEFAULT '',
        source VARCHAR(190) NOT NULL DEFAULT '',
        medium VARCHAR(120) NOT NULL DEFAULT '',
        campaign VARCHAR(190) NOT NULL DEFAULT '',
        event_count INT UNSIGNED NOT NULL DEFAULT 0,
        total_users INT UNSIGNED NOT NULL DEFAULT 0,
        UNIQUE KEY events_source_daily_unique (domain_id, metric_date, event_name, source, medium, campaign),
        KEY events_source_daily_domain_date_idx (domain_id, metric_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS allstat_funnels (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        domain_id INT UNSIGNED NOT NULL,
        name VARCHAR(120) NOT NULL,
        steps_json TEXT NOT NULL,
        breakdown VARCHAR(20) NOT NULL DEFAULT 'channel',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY allstat_funnels_domain_sort_idx (domain_id, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS gsc_pages_daily (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        domain_id INT UNSIGNED NOT NULL,
        metric_date DATE NOT NULL,
        page VARCHAR(255) NOT NULL,
        clicks INT UNSIGNED NOT NULL DEFAULT 0,
        impressions INT UNSIGNED NOT NULL DEFAULT 0,
        position DECIMAL(6,2) NOT NULL DEFAULT 0,
        UNIQUE KEY gsc_pages_daily_unique (domain_id, metric_date, page),
        KEY gsc_pages_daily_domain_date_idx (domain_id, metric_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS geo_daily (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        domain_id INT UNSIGNED NOT NULL,
        metric_date DATE NOT NULL,
        country VARCHAR(80) NOT NULL DEFAULT '(not set)',
        region VARCHAR(120) NOT NULL DEFAULT '(not set)',
        sessions INT UNSIGNED NOT NULL DEFAULT 0,
        users INT UNSIGNED NOT NULL DEFAULT 0,
        conversions INT UNSIGNED NOT NULL DEFAULT 0,
        UNIQUE KEY geo_daily_unique (domain_id, metric_date, country, region),
        KEY geo_daily_domain_date_idx (domain_id, metric_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS device_daily (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        domain_id INT UNSIGNED NOT NULL,
        metric_date DATE NOT NULL,
        device VARCHAR(40) NOT NULL DEFAULT '(other)',
        sessions INT UNSIGNED NOT NULL DEFAULT 0,
        users INT UNSIGNED NOT NULL DEFAULT 0,
        conversions INT UNSIGNED NOT NULL DEFAULT 0,
        UNIQUE KEY device_daily_unique (domain_id, metric_date, device),
        KEY device_daily_domain_date_idx (domain_id, metric_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pages_daily (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        domain_id INT UNSIGNED NOT NULL,
        metric_date DATE NOT NULL,
        path VARCHAR(220) NOT NULL,
        views INT UNSIGNED NOT NULL DEFAULT 0,
        sessions INT UNSIGNED NOT NULL DEFAULT 0,
        conversions INT UNSIGNED NOT NULL DEFAULT 0,
        UNIQUE KEY pages_daily_unique (domain_id, metric_date, path),
        KEY pages_daily_path_idx (path),
        KEY pages_daily_domain_date_idx (domain_id, metric_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci");

    // UTM campaign tracking (GA4): campaign × source × medium × landing page per day → lets the
    // dashboard answer "people from utm_campaign=X landed on page Y and converted Z times".
    $pdo->exec("CREATE TABLE IF NOT EXISTS utm_daily (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        domain_id INT UNSIGNED NOT NULL,
        metric_date DATE NOT NULL,
        campaign VARCHAR(150) NOT NULL DEFAULT '(not set)',
        source VARCHAR(120) NOT NULL DEFAULT '(not set)',
        medium VARCHAR(60) NOT NULL DEFAULT '(none)',
        content VARCHAR(120) NOT NULL DEFAULT '(not set)',
        landing_page VARCHAR(190) NOT NULL DEFAULT '',
        sessions INT UNSIGNED NOT NULL DEFAULT 0,
        conversions INT UNSIGNED NOT NULL DEFAULT 0,
        engaged_sessions INT UNSIGNED NOT NULL DEFAULT 0,
        UNIQUE KEY utm_daily_unique (domain_id, metric_date, campaign, source, medium, content, landing_page),
        KEY utm_daily_domain_date_idx (domain_id, metric_date),
        KEY utm_daily_campaign_idx (campaign)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci");

    // utm_content (GA4 sessionManualAdContent) for A/B variant tracking. Added after the table so existing
    // installs self-upgrade; the unique key is rebuilt ONCE to include it (table stays empty until the
    // first GA4 sync, so nothing is lost). The COLUMN_NAME check keeps the ALTER from running every request.
    allstat_add_column($pdo, 'utm_daily', 'content', "content VARCHAR(120) NOT NULL DEFAULT '(not set)' AFTER medium");
    $utmKeyHasContent = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'utm_daily' AND INDEX_NAME = 'utm_daily_unique' AND COLUMN_NAME = 'content'")->fetchColumn();
    if ($utmKeyHasContent === 0) {
        try { $pdo->exec("ALTER TABLE utm_daily DROP INDEX utm_daily_unique"); } catch (Throwable) {}
        $pdo->exec("ALTER TABLE utm_daily ADD UNIQUE KEY utm_daily_unique (domain_id, metric_date, campaign, source, medium, content, landing_page)");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS social_posts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        domain_id INT UNSIGNED NOT NULL,
        connection_id INT UNSIGNED NOT NULL,
        metric_date DATE NOT NULL,
        network VARCHAR(20) NOT NULL DEFAULT 'facebook',
        post_id VARCHAR(150) NOT NULL,
        message VARCHAR(500) NULL,
        permalink VARCHAR(400) NULL,
        reactions INT UNSIGNED NOT NULL DEFAULT 0,
        comments INT UNSIGNED NOT NULL DEFAULT 0,
        shares INT UNSIGNED NOT NULL DEFAULT 0,
        impressions INT UNSIGNED NOT NULL DEFAULT 0,
        reach INT UNSIGNED NOT NULL DEFAULT 0,
        engagement INT UNSIGNED NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY social_posts_unique (connection_id, post_id),
        KEY social_posts_conn_date_idx (connection_id, metric_date),
        KEY social_posts_domain_date_idx (domain_id, metric_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci");

    // Post type (post / reel / story) so the dashboard can label Reels/Stories — added after the
    // table so existing installs pick it up too.
    allstat_add_column($pdo, 'social_posts', 'post_type', "post_type VARCHAR(20) NOT NULL DEFAULT 'post' AFTER network");
    // Enriched post-level fields (added after the base table so existing prod installs self-upgrade):
    //  - published_at: EXACT publish datetime in the app timezone (Europe/Prague). metric_date stays the
    //    local date derived from it, but the full timestamp lets us show the real time + a best-time heatmap.
    //  - post_format: photo/video/reel/link/status/album/share — media format (vs post_type post/reel/story).
    //  - post_clicks / video_views: post-level insights (need read_insights; best-effort, 0 when unavailable).
    //  - r_*: reactions broken down by type (like/love/haha/wow/sad/angry) for the sentiment card.
    allstat_add_column($pdo, 'social_posts', 'published_at', "published_at DATETIME NULL AFTER metric_date");
    allstat_add_column($pdo, 'social_posts', 'post_format', "post_format VARCHAR(24) NOT NULL DEFAULT '' AFTER post_type");
    allstat_add_column($pdo, 'social_posts', 'post_clicks', "post_clicks INT UNSIGNED NOT NULL DEFAULT 0 AFTER impressions");
    allstat_add_column($pdo, 'social_posts', 'video_views', "video_views INT UNSIGNED NOT NULL DEFAULT 0 AFTER post_clicks");
    // Reels: total watch time (seconds) + plays. Populated by the FB video_reels / IG reels sync branch.
    allstat_add_column($pdo, 'social_posts', 'watch_time_sec', "watch_time_sec INT UNSIGNED NOT NULL DEFAULT 0 AFTER video_views");
    allstat_add_column($pdo, 'social_posts', 'plays', "plays INT UNSIGNED NOT NULL DEFAULT 0 AFTER watch_time_sec");
    // IG kvalita obsahu: uložení (saved) + celkové interakce (total_interactions = like+comment+save+share).
    // „Uložení" je nejsilnější signál kvality; plní IG media-insight batch v meta_graph enginu.
    allstat_add_column($pdo, 'social_posts', 'saved', "saved INT UNSIGNED NOT NULL DEFAULT 0 AFTER shares");
    allstat_add_column($pdo, 'social_posts', 'total_interactions', "total_interactions INT UNSIGNED NOT NULL DEFAULT 0 AFTER saved");
    // IG Stories: odpovědi + navigace (celkem posunů/odchodů) — retence stories. Plní story branch meta_graph enginu.
    allstat_add_column($pdo, 'social_posts', 'story_replies', "story_replies INT UNSIGNED NOT NULL DEFAULT 0 AFTER total_interactions");
    allstat_add_column($pdo, 'social_posts', 'story_navigation', "story_navigation INT UNSIGNED NOT NULL DEFAULT 0 AFTER story_replies");
    // Organic/paid split (Business Suite "Zobrazení/Dosah z Propagované"). Not in the Graph API since
    // 15.6.2026, so these are filled by the CSV import; organic = total (impressions/reach) minus paid.
    allstat_add_column($pdo, 'social_posts', 'views_paid', "views_paid INT UNSIGNED NOT NULL DEFAULT 0 AFTER impressions");
    allstat_add_column($pdo, 'social_posts', 'reach_paid', "reach_paid INT UNSIGNED NOT NULL DEFAULT 0 AFTER reach");
    foreach (['r_like', 'r_love', 'r_haha', 'r_wow', 'r_sad', 'r_angry'] as $rcol) {
        allstat_add_column($pdo, 'social_posts', $rcol, "$rcol INT UNSIGNED NOT NULL DEFAULT 0 AFTER reactions");
    }
    // FB „virální" protějšky: reakce/komentáře VČETNĚ přesdílení příspěvku. Sloupce reactions/comments drží
    // nově jen originál (= číslo z Business Suite), tyhle dva nesou širší insight (post_reactions_by_type_total
    // resp. post_activity_by_action_type), aby se dal rozdíl ukázat v detailu a nezmizel z dat.
    allstat_add_column($pdo, 'social_posts', 'reactions_viral', "reactions_viral INT UNSIGNED NOT NULL DEFAULT 0 AFTER r_angry");
    allstat_add_column($pdo, 'social_posts', 'comments_viral', "comments_viral INT UNSIGNED NOT NULL DEFAULT 0 AFTER comments");
    // Detail příspěvku po vzoru Business Suite: prokliky na odkaz zvlášť od ostatních klikům a dosah mezi sledujícími.
    allstat_add_column($pdo, 'social_posts', 'link_clicks', "link_clicks INT UNSIGNED NOT NULL DEFAULT 0 AFTER post_clicks");
    allstat_add_column($pdo, 'social_posts', 'fan_reach', "fan_reach INT UNSIGNED NOT NULL DEFAULT 0 AFTER reach_paid");
    allstat_add_column($pdo, 'social_posts', 'clicks_json', "clicks_json VARCHAR(255) NOT NULL DEFAULT '' AFTER link_clicks");
    // YouTube videa (network='youtube'): délka videa, průměrná doba/procento zhlédnutí, odběratelé získaní
    // z videa a náhled — JSON, ať kvůli každé další per-video metrice nevzniká nový sloupec.
    allstat_add_column($pdo, 'social_posts', 'stats_json', "stats_json VARCHAR(500) NOT NULL DEFAULT '' AFTER clicks_json");
    // One-time cleanup (naturally idempotent): the now-retired video_reels edge stored reels under a BARE
    // video id (no underscore), on which post insights 400 → reach stayed 0; after a re-backfill they would
    // also duplicate the real pageid_postid reels that come from published_posts. The current engine never
    // creates bare-id reels, so this DELETE removes the orphans on the first run and then matches nothing.
    try {
        $pdo->exec("DELETE FROM social_posts WHERE network = 'facebook' AND post_type = 'reel' AND post_id NOT LIKE '%\\_%'");
    } catch (Throwable) { /* pre-post_type install, nothing to clean */ }

    // Zařazení příspěvků do kolekcí sítě (YouTube playlisty = „kategorie" kanálu). Jeden příspěvek smí být
    // ve více kolekcích; membership se při každém aktuálním syncu přepisuje celá (malá tabulka).
    $pdo->exec("CREATE TABLE IF NOT EXISTS social_collections (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        connection_id INT UNSIGNED NOT NULL,
        collection_id VARCHAR(150) NOT NULL,
        title VARCHAR(255) NOT NULL,
        post_id VARCHAR(150) NOT NULL,
        position INT UNSIGNED NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY social_collections_unique (connection_id, collection_id, post_id),
        KEY social_collections_post_idx (connection_id, post_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci");

    // Meta Ads souhrny za CELOU dobu kampaně / sestavy / reklamy (insights bez time_increment). Jen odtud jde
    // skutečná frekvence (zobrazení ÷ unikátní dosah): denní řádky v provider_metrics_daily počítají téhož
    // člověka každý den znovu. Plus cíl kampaně (objective) a optimalizace sestavy, podle kterých se kampaň
    // hodnotí (povědomí cenou za zobrazení, návštěvnost cenou za proklik). Přepisuje se při každém aktuálním syncu.
    $pdo->exec("CREATE TABLE IF NOT EXISTS meta_ads_totals (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        connection_id INT UNSIGNED NOT NULL,
        level VARCHAR(10) NOT NULL,
        entity_id VARCHAR(40) NOT NULL,
        name VARCHAR(120) NOT NULL,
        objective VARCHAR(40) NOT NULL DEFAULT '',
        optimization_goal VARCHAR(40) NOT NULL DEFAULT '',
        impressions BIGINT UNSIGNED NOT NULL DEFAULT 0,
        reach BIGINT UNSIGNED NOT NULL DEFAULT 0,
        spend DECIMAL(14,2) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY meta_ads_totals_unique (connection_id, level, entity_id),
        KEY meta_ads_totals_name_idx (connection_id, level, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci");

    // Temporary "Share for AI" links — tokenized, short-lived, public-while-valid export of one view.
    $pdo->exec("CREATE TABLE IF NOT EXISTS share_links (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        token CHAR(48) NOT NULL,
        domain_id INT UNSIGNED NOT NULL,
        view_source_id INT UNSIGNED NOT NULL DEFAULT 0,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        format VARCHAR(8) NOT NULL DEFAULT 'md',
        granularity VARCHAR(10) NOT NULL DEFAULT 'day',
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY share_links_token (token),
        KEY share_links_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci");

    // Long-lived, revocable read-only feed tokens for Google Sheets =IMPORTDATA (see lib/feed.php).
    // No fixed date range — the rolling window (range_key) resolves at request time.
    $pdo->exec("CREATE TABLE IF NOT EXISTS feed_tokens (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        token CHAR(48) NOT NULL,
        domain_id INT UNSIGNED NOT NULL,
        view_source_id INT UNSIGNED NOT NULL DEFAULT 0,
        shape VARCHAR(12) NOT NULL DEFAULT 'series',
        granularity VARCHAR(10) NOT NULL DEFAULT 'month',
        range_key VARCHAR(24) NOT NULL DEFAULT 'last_12_months',
        format VARCHAR(8) NOT NULL DEFAULT 'csv',
        label VARCHAR(120) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_access_at DATETIME NULL,
        revoked_at DATETIME NULL,
        UNIQUE KEY feed_tokens_token (token),
        KEY feed_tokens_domain (domain_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci");

    // E-commerce items (GA4 itemName report) → top products. Only populated on e-commerce properties.
    $pdo->exec("CREATE TABLE IF NOT EXISTS items_daily (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        domain_id INT UNSIGNED NOT NULL,
        metric_date DATE NOT NULL,
        item_name VARCHAR(200) NOT NULL,
        items_viewed INT UNSIGNED NOT NULL DEFAULT 0,
        items_added_to_cart INT UNSIGNED NOT NULL DEFAULT 0,
        items_purchased INT UNSIGNED NOT NULL DEFAULT 0,
        item_revenue DECIMAL(14,2) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY items_daily_unique (domain_id, metric_date, item_name),
        KEY items_daily_domain_date (domain_id, metric_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci");

    // Audience demographics (GA4 userAgeBracket × userGender). Needs Google Signals; aggregate-only estimate.
    $pdo->exec("CREATE TABLE IF NOT EXISTS demographics_daily (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        domain_id INT UNSIGNED NOT NULL,
        metric_date DATE NOT NULL,
        age_bracket VARCHAR(20) NOT NULL DEFAULT 'unknown',
        gender VARCHAR(20) NOT NULL DEFAULT 'unknown',
        users INT UNSIGNED NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY demographics_daily_unique (domain_id, metric_date, age_bracket, gender),
        KEY demographics_daily_domain_date (domain_id, metric_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS domain_source_deletions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        domain_id INT UNSIGNED NOT NULL,
        source_id INT UNSIGNED NULL,
        deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reason VARCHAR(80) NOT NULL DEFAULT 'source_deleted',
        KEY domain_source_deletions_domain_idx (domain_id, deleted_at),
        KEY domain_source_deletions_source_idx (source_id),
        CONSTRAINT domain_source_deletions_domain_fk FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE,
        CONSTRAINT domain_source_deletions_source_fk FOREIGN KEY (source_id) REFERENCES data_sources (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci");

    allstat_migrate_mcp($pdo); // izolované: selhání DDL MCP nesmí zastavit zbytek migrace ani aplikaci

    allstat_seed_provider_catalog($pdo);
    allstat_setting_default($pdo, 'security.enforce_2fa', '0');
    allstat_setting_default($pdo, 'sync.cron_secret', bin2hex(random_bytes(16)));
    allstat_setting_default($pdo, 'app.public_base_url', '/allstat');
    allstat_setting_default($pdo, 'storage.metric_retention_days', '1095');
    allstat_setting_default($pdo, 'storage.deleted_connection_retention_days', '30');

    allstat_add_index($pdo, 'domains', 'domains_active_name_idx', 'KEY domains_active_name_idx (is_active, name)');
    allstat_add_index($pdo, 'sync_logs', 'sync_logs_created_idx', 'KEY sync_logs_created_idx (created_at)');
    allstat_add_index($pdo, 'allstat_user_audit_log', 'allstat_user_audit_created_idx', 'KEY allstat_user_audit_created_idx (created_at)');
    allstat_add_index($pdo, 'allstat_login_attempts', 'allstat_login_attempts_email_created_idx', 'KEY allstat_login_attempts_email_created_idx (email, created_at)');

    // One-time (naturally idempotent): Graph API v20.0 končí 24. 9. 2026 (Marketing API v20 je mrtvé
    // už od 5/2025 a jen se tiše auto-přesouvalo na novější) → uložené URL napojení přepnout na v25.0
    // (vydáno 2/2026, nejnovější k 7/2026). Katalog data_sources se přepíše sám z providers.php přes
    // upsert v allstat_seed_provider_catalog; tady se opraví per-connection kopie v domain_sources.
    // Po přepnutí už REPLACE nic nenajde (WHERE nic nematchne).
    try {
        $pdo->exec("UPDATE domain_sources
            SET auth_url = REPLACE(auth_url, '/v20.0', '/v25.0'),
                token_url = REPLACE(token_url, '/v20.0', '/v25.0'),
                api_base_url = REPLACE(api_base_url, '/v20.0', '/v25.0')
            WHERE api_base_url LIKE '%facebook.com/v20.0%' OR token_url LIKE '%facebook.com/v20.0%' OR auth_url LIKE '%facebook.com/v20.0%'");
    } catch (Throwable) { /* stará instalace bez těch sloupců */ }

    // Až po úspěšném doběhnutí VŠECH kroků — kdyby cokoli výše selhalo, verze se nezapíše
    // a příští request migraci zopakuje (všechny kroky jsou idempotentní).
    allstat_migration_set_setting($pdo, 'schema.version', ALLSTAT_SCHEMA_VERSION);

    allstat_run_light_maintenance($pdo);
}

function allstat_seed_provider_catalog(PDO $pdo): void
{
    if (!allstat_table_exists($pdo, 'data_sources')) {
        return;
    }

    $statement = $pdo->prepare("INSERT INTO data_sources
        (provider_key, name, category, supports_oauth, default_scopes, auth_url, token_url, api_base_url, docs_url)
        VALUES (:provider_key, :name, :category, :supports_oauth, :default_scopes, :auth_url, :token_url, :api_base_url, :docs_url)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            category = VALUES(category),
            supports_oauth = VALUES(supports_oauth),
            default_scopes = VALUES(default_scopes),
            auth_url = VALUES(auth_url),
            token_url = VALUES(token_url),
            api_base_url = VALUES(api_base_url),
            docs_url = VALUES(docs_url)");

    foreach (allstat_default_providers() as $provider) {
        $statement->execute($provider);
    }
}

function allstat_setting_default(PDO $pdo, string $key, string $value): void
{
    $statement = $pdo->prepare('INSERT IGNORE INTO allstat_settings (setting_key, setting_value) VALUES (?, ?)');
    $statement->execute([$key, $value]);
}

function allstat_migration_setting_value(PDO $pdo, string $key, string $fallback): string
{
    $row = allstat_fetch_one($pdo, 'SELECT setting_value FROM allstat_settings WHERE setting_key = ?', [$key]);

    return (string) ($row['setting_value'] ?? $fallback);
}

function allstat_migration_set_setting(PDO $pdo, string $key, string $value): void
{
    $statement = $pdo->prepare('INSERT INTO allstat_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $statement->execute([$key, $value]);
}

function allstat_batched_delete(PDO $pdo, string $sql, array $params, int $batch = 5000, int $maxBatches = 200): void
{
    // DELETE ... LIMIT in a loop → never one giant locking statement on shared hosting.
    $statement = $pdo->prepare($sql . ' LIMIT ' . (int) $batch);
    for ($i = 0; $i < $maxBatches; $i++) {
        $statement->execute($params);
        if ($statement->rowCount() < $batch) {
            break;
        }
    }
}

function allstat_run_light_maintenance(PDO $pdo): void
{
    $today = (new DateTimeImmutable('today'))->format('Y-m-d');

    // Daily-gated: skip the whole scan/purge if already run today (keeps per-request cost ~0).
    if (allstat_migration_setting_value($pdo, 'storage.last_maintenance_date', '') === $today) {
        return;
    }

    allstat_mark_orphan_metric_domains($pdo);

    $metricRetention = max(30, min(3650, (int) allstat_migration_setting_value($pdo, 'storage.metric_retention_days', '1095')));
    $deletedConnectionRetention = max(30, min(3650, (int) allstat_migration_setting_value($pdo, 'storage.deleted_connection_retention_days', '30')));
    $metricCutoff = (new DateTimeImmutable('today'))->modify('-' . $metricRetention . ' days')->format('Y-m-d');
    $deletedConnectionCutoff = (new DateTimeImmutable('today'))->modify('-' . $deletedConnectionRetention . ' days')->format('Y-m-d H:i:s');
    $syncCutoff = (new DateTimeImmutable('today'))->modify('-180 days')->format('Y-m-d H:i:s');
    $auditCutoff = (new DateTimeImmutable('today'))->modify('-365 days')->format('Y-m-d H:i:s');

    foreach (['search_queries_daily', 'landing_pages_daily', 'pages_daily', 'device_daily', 'traffic_sources_daily', 'ai_sources_daily', 'referrers_daily', 'metrics_daily', 'geo_daily', 'events_daily', 'events_source_daily', 'gsc_pages_daily', 'provider_metrics_daily', 'items_daily', 'demographics_daily', 'social_posts'] as $table) {
        if (allstat_table_exists($pdo, $table)) {
            allstat_batched_delete($pdo, "DELETE FROM $table WHERE metric_date < ?", [$metricCutoff]);

            if (allstat_table_exists($pdo, 'domain_sources') && allstat_table_exists($pdo, 'domain_source_deletions')) {
                allstat_batched_delete($pdo, "
                    DELETE FROM $table
                    WHERE domain_id IN (
                        SELECT domain_id FROM (
                            SELECT dsd.domain_id
                            FROM domain_source_deletions dsd
                            LEFT JOIN domain_sources ds ON ds.domain_id = dsd.domain_id
                            WHERE ds.id IS NULL
                            GROUP BY dsd.domain_id
                            HAVING MAX(dsd.deleted_at) < ?
                        ) expired_domains
                    )
                ", [$deletedConnectionCutoff]);
            }
        }
    }

    if (allstat_table_exists($pdo, 'sync_logs')) {
        allstat_batched_delete($pdo, 'DELETE FROM sync_logs WHERE created_at < ?', [$syncCutoff]);
    }

    // Kolekce (YouTube playlisty) po smazaném napojení: nemají domain_id ani metric_date, uklidit podle FK.
    if (allstat_table_exists($pdo, 'social_collections') && allstat_table_exists($pdo, 'domain_sources')) {
        allstat_batched_delete($pdo, 'DELETE FROM social_collections WHERE connection_id NOT IN (SELECT id FROM domain_sources)', []);
    }
    if (allstat_table_exists($pdo, 'meta_ads_totals') && allstat_table_exists($pdo, 'domain_sources')) {
        allstat_batched_delete($pdo, 'DELETE FROM meta_ads_totals WHERE connection_id NOT IN (SELECT id FROM domain_sources)', []);
    }
    // Přidělené weby po smazaném uživateli nebo webu (mazání je uklízí samo, tohle je pojistka).
    if (allstat_table_exists($pdo, 'allstat_user_domains')) {
        allstat_batched_delete($pdo, 'DELETE FROM allstat_user_domains WHERE user_id NOT IN (SELECT id FROM allstat_users) OR domain_id NOT IN (SELECT id FROM domains)', []);
    }
    // Trychtýře po smazaném webu (smazání webu je uklízí samo, tohle je pojistka).
    if (allstat_table_exists($pdo, 'allstat_funnels')) {
        allstat_batched_delete($pdo, 'DELETE FROM allstat_funnels WHERE domain_id NOT IN (SELECT id FROM domains)', []);
    }

    if (allstat_table_exists($pdo, 'allstat_login_attempts')) {
        allstat_batched_delete($pdo, 'DELETE FROM allstat_login_attempts WHERE created_at < ?', [$syncCutoff]);
    }

    if (allstat_table_exists($pdo, 'allstat_user_audit_log')) {
        allstat_batched_delete($pdo, 'DELETE FROM allstat_user_audit_log WHERE created_at < ?', [$auditCutoff]);
    }

    // MCP konektor: prošlé kódy a tokeny, odvolaná připojení, starý log. Funkce žije v lib/mcp-oauth.php; chybějící
    // nebo poškozený soubor (např. rozpracované FTP nahrání) nesmí shodit request.
    try {
        if (!function_exists('allstat_mcp_purge') && is_file(__DIR__ . '/mcp-oauth.php')) {
            require_once __DIR__ . '/mcp-oauth.php';
        }

        if (function_exists('allstat_mcp_purge') && allstat_mcp_schema_is_current($pdo)) {
            allstat_mcp_purge($pdo);
        }
    } catch (Throwable) {
        // úklid MCP tabulek je nepovinný
    }

    allstat_migration_set_setting($pdo, 'storage.last_maintenance_date', $today);
}

function allstat_mark_orphan_metric_domains(PDO $pdo): void
{
    if (!allstat_table_exists($pdo, 'domain_source_deletions') || !allstat_table_exists($pdo, 'domain_sources')) {
        return;
    }

    foreach (['metrics_daily', 'traffic_sources_daily', 'ai_sources_daily', 'referrers_daily', 'landing_pages_daily', 'pages_daily', 'device_daily', 'search_queries_daily', 'geo_daily', 'events_daily', 'events_source_daily', 'gsc_pages_daily', 'provider_metrics_daily', 'items_daily', 'demographics_daily', 'social_posts'] as $table) {
        if (!allstat_table_exists($pdo, $table)) {
            continue;
        }

        $pdo->exec("
            INSERT INTO domain_source_deletions (domain_id, source_id, reason)
            SELECT DISTINCT m.domain_id, NULL, 'orphan_detected'
            FROM $table m
            LEFT JOIN domain_sources ds ON ds.domain_id = m.domain_id
            LEFT JOIN domain_source_deletions dsd ON dsd.domain_id = m.domain_id
            WHERE ds.id IS NULL AND dsd.id IS NULL
        ");
    }
}
