CREATE TABLE IF NOT EXISTS domains (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    url VARCHAR(180) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY domains_url_unique (url),
    KEY domains_active_name_idx (is_active, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS metrics_daily (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id INT UNSIGNED NOT NULL,
    metric_date DATE NOT NULL,
    visits INT UNSIGNED NOT NULL DEFAULT 0,
    engaged_sessions INT UNSIGNED NOT NULL DEFAULT 0,
    engagement_time_sec INT UNSIGNED NOT NULL DEFAULT 0,
    users_count INT UNSIGNED NOT NULL DEFAULT 0,
    new_users INT UNSIGNED NOT NULL DEFAULT 0,
    clicks INT UNSIGNED NOT NULL DEFAULT 0,
    impressions INT UNSIGNED NOT NULL DEFAULT 0,
    conversions INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY metrics_daily_unique (domain_id, metric_date),
    KEY metrics_daily_date_idx (metric_date),
    CONSTRAINT metrics_daily_domain_fk FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS traffic_sources_daily (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id INT UNSIGNED NOT NULL,
    metric_date DATE NOT NULL,
    source VARCHAR(80) NOT NULL,
    sessions INT UNSIGNED NOT NULL DEFAULT 0,
    conversions INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY traffic_sources_daily_unique (domain_id, metric_date, source),
    KEY traffic_sources_daily_source_idx (source),
    CONSTRAINT traffic_sources_daily_domain_fk FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS ai_sources_daily (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id INT UNSIGNED NOT NULL,
    metric_date DATE NOT NULL,
    source VARCHAR(80) NOT NULL,
    sessions INT UNSIGNED NOT NULL DEFAULT 0,
    conversions INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY ai_sources_daily_unique (domain_id, metric_date, source),
    KEY ai_sources_daily_domain_date_idx (domain_id, metric_date),
    CONSTRAINT ai_sources_daily_domain_fk FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS referrers_daily (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id INT UNSIGNED NOT NULL,
    metric_date DATE NOT NULL,
    source VARCHAR(190) NOT NULL,
    sessions INT UNSIGNED NOT NULL DEFAULT 0,
    conversions INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY referrers_daily_unique (domain_id, metric_date, source),
    KEY referrers_daily_domain_date_idx (domain_id, metric_date),
    CONSTRAINT referrers_daily_domain_fk FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS landing_pages_daily (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id INT UNSIGNED NOT NULL,
    metric_date DATE NOT NULL,
    path VARCHAR(220) NOT NULL,
    sessions INT UNSIGNED NOT NULL DEFAULT 0,
    conversions INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY landing_pages_daily_unique (domain_id, metric_date, path),
    KEY landing_pages_daily_path_idx (path),
    CONSTRAINT landing_pages_daily_domain_fk FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS device_daily (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id INT UNSIGNED NOT NULL,
    metric_date DATE NOT NULL,
    device VARCHAR(40) NOT NULL DEFAULT '(other)',
    sessions INT UNSIGNED NOT NULL DEFAULT 0,
    users INT UNSIGNED NOT NULL DEFAULT 0,
    conversions INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY device_daily_unique (domain_id, metric_date, device),
    KEY device_daily_domain_date_idx (domain_id, metric_date),
    CONSTRAINT device_daily_domain_fk FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS pages_daily (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id INT UNSIGNED NOT NULL,
    metric_date DATE NOT NULL,
    path VARCHAR(220) NOT NULL,
    views INT UNSIGNED NOT NULL DEFAULT 0,
    sessions INT UNSIGNED NOT NULL DEFAULT 0,
    conversions INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY pages_daily_unique (domain_id, metric_date, path),
    KEY pages_daily_path_idx (path),
    KEY pages_daily_domain_date_idx (domain_id, metric_date),
    CONSTRAINT pages_daily_domain_fk FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS social_posts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id INT UNSIGNED NOT NULL,
    connection_id INT UNSIGNED NOT NULL,
    metric_date DATE NOT NULL,
    network VARCHAR(20) NOT NULL DEFAULT 'facebook',
    post_type VARCHAR(20) NOT NULL DEFAULT 'post',
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
    KEY social_posts_domain_date_idx (domain_id, metric_date),
    CONSTRAINT social_posts_domain_fk FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS social_collections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    connection_id INT UNSIGNED NOT NULL,
    collection_id VARCHAR(150) NOT NULL,
    title VARCHAR(255) NOT NULL,
    post_id VARCHAR(150) NOT NULL,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY social_collections_unique (connection_id, collection_id, post_id),
    KEY social_collections_post_idx (connection_id, post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS provider_metrics_daily (
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
    KEY provider_metrics_source_idx (source_id),
    CONSTRAINT provider_metrics_domain_fk FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE,
    CONSTRAINT provider_metrics_source_fk FOREIGN KEY (source_id) REFERENCES data_sources (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS events_daily (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id INT UNSIGNED NOT NULL,
    metric_date DATE NOT NULL,
    event_name VARCHAR(120) NOT NULL,
    event_count INT UNSIGNED NOT NULL DEFAULT 0,
    key_events INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY events_daily_unique (domain_id, metric_date, event_name),
    KEY events_daily_domain_date_idx (domain_id, metric_date),
    CONSTRAINT events_daily_domain_fk FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS gsc_pages_daily (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id INT UNSIGNED NOT NULL,
    metric_date DATE NOT NULL,
    page VARCHAR(255) NOT NULL,
    clicks INT UNSIGNED NOT NULL DEFAULT 0,
    impressions INT UNSIGNED NOT NULL DEFAULT 0,
    position DECIMAL(6,2) NOT NULL DEFAULT 0,
    UNIQUE KEY gsc_pages_daily_unique (domain_id, metric_date, page),
    KEY gsc_pages_daily_domain_date_idx (domain_id, metric_date),
    CONSTRAINT gsc_pages_daily_domain_fk FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS geo_daily (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id INT UNSIGNED NOT NULL,
    metric_date DATE NOT NULL,
    country VARCHAR(80) NOT NULL DEFAULT '(not set)',
    region VARCHAR(120) NOT NULL DEFAULT '(not set)',
    sessions INT UNSIGNED NOT NULL DEFAULT 0,
    users INT UNSIGNED NOT NULL DEFAULT 0,
    conversions INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY geo_daily_unique (domain_id, metric_date, country, region),
    KEY geo_daily_domain_date_idx (domain_id, metric_date),
    CONSTRAINT geo_daily_domain_fk FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS search_queries_daily (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id INT UNSIGNED NOT NULL,
    metric_date DATE NOT NULL,
    query_text VARCHAR(220) NOT NULL,
    clicks INT UNSIGNED NOT NULL DEFAULT 0,
    impressions INT UNSIGNED NOT NULL DEFAULT 0,
    position DECIMAL(6,2) NOT NULL DEFAULT 0,
    UNIQUE KEY search_queries_daily_unique (domain_id, metric_date, query_text),
    KEY search_queries_daily_query_idx (query_text),
    CONSTRAINT search_queries_daily_domain_fk FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS data_sources (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider_key VARCHAR(60) NOT NULL,
    name VARCHAR(120) NOT NULL,
    category VARCHAR(40) NOT NULL,
    supports_oauth TINYINT(1) NOT NULL DEFAULT 1,
    default_scopes TEXT NULL,
    auth_url VARCHAR(255) NULL,
    token_url VARCHAR(255) NULL,
    api_base_url VARCHAR(255) NULL,
    docs_url VARCHAR(255) NULL,
    UNIQUE KEY data_sources_provider_unique (provider_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS domain_sources (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id INT UNSIGNED NOT NULL,
    source_id INT UNSIGNED NOT NULL,
    account_label VARCHAR(160) NULL,
    property_id VARCHAR(120) NULL,
    external_account_id VARCHAR(160) NULL,
    client_id VARCHAR(255) NULL,
    client_secret_enc TEXT NULL,
    access_token_enc TEXT NULL,
    refresh_token_enc TEXT NULL,
    scopes TEXT NULL,
    auth_url VARCHAR(255) NULL,
    token_url VARCHAR(255) NULL,
    api_base_url VARCHAR(255) NULL,
    config_json TEXT NULL,
    status ENUM('ok', 'warning', 'error') NOT NULL DEFAULT 'ok',
    last_sync_at DATETIME NULL,
    last_cron_at DATETIME NULL,
    token_expires_at DATETIME NULL,
    note VARCHAR(220) NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY domain_sources_provider_property_unique (domain_id, source_id, property_id),
    CONSTRAINT domain_sources_domain_fk FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE,
    CONSTRAINT domain_sources_source_fk FOREIGN KEY (source_id) REFERENCES data_sources (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS domain_source_deletions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id INT UNSIGNED NOT NULL,
    source_id INT UNSIGNED NULL,
    deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reason VARCHAR(80) NOT NULL DEFAULT 'source_deleted',
    KEY domain_source_deletions_domain_idx (domain_id, deleted_at),
    KEY domain_source_deletions_source_idx (source_id),
    CONSTRAINT domain_source_deletions_domain_fk FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE,
    CONSTRAINT domain_source_deletions_source_fk FOREIGN KEY (source_id) REFERENCES data_sources (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS allstat_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    name VARCHAR(120) NOT NULL,
    role ENUM('admin','user') NOT NULL DEFAULT 'user',
    domain_access ENUM('all','selected') NOT NULL DEFAULT 'all',
    password_hash VARCHAR(255) NOT NULL,
    totp_secret_enc TEXT NULL,
    totp_last_window BIGINT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY allstat_users_email_unique (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS allstat_user_audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_user_id INT UNSIGNED NULL,
    target_user_id INT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    detail TEXT NULL,
    ip_address VARCHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY allstat_user_audit_actor_idx (actor_user_id, created_at),
    KEY allstat_user_audit_target_idx (target_user_id, created_at),
    KEY allstat_user_audit_created_idx (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS allstat_user_domains (
    user_id INT UNSIGNED NOT NULL,
    domain_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, domain_id),
    KEY allstat_user_domains_domain_idx (domain_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS allstat_login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(64) NOT NULL,
    email VARCHAR(190) NULL,
    was_success TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY allstat_login_attempts_ip_idx (ip_address, created_at),
    KEY allstat_login_attempts_email_created_idx (email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS allstat_settings (
    setting_key VARCHAR(120) PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS sync_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id INT UNSIGNED NOT NULL,
    source_id INT UNSIGNED NOT NULL,
    level ENUM('info', 'warning', 'error') NOT NULL DEFAULT 'info',
    message VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY sync_logs_domain_source_idx (domain_id, source_id, created_at),
    KEY sync_logs_created_idx (created_at),
    CONSTRAINT sync_logs_domain_fk FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE CASCADE,
    CONSTRAINT sync_logs_source_fk FOREIGN KEY (source_id) REFERENCES data_sources (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
