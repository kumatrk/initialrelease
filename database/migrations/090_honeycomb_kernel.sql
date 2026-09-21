-- Honeycomb kernel: installed addons, credentials, campaign bindings, traffic source provider_key.

CREATE TABLE IF NOT EXISTS honeycomb_addons (
    slug VARCHAR(64) NOT NULL,
    name VARCHAR(191) NOT NULL,
    version VARCHAR(32) NOT NULL,
    type VARCHAR(32) NOT NULL DEFAULT 'traffic_source',
    provider_key VARCHAR(64) NULL,
    status ENUM('enabled', 'disabled') NOT NULL DEFAULT 'enabled',
    manifest_json JSON NULL,
    installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (slug),
    INDEX idx_honeycomb_addons_type (type),
    INDEX idx_honeycomb_addons_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS honeycomb_credentials (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    addon_slug VARCHAR(64) NOT NULL,
    label VARCHAR(191) NOT NULL,
    payload_encrypted TEXT NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_honeycomb_credentials_slug (addon_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaign_addon_bindings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT UNSIGNED NOT NULL,
    addon_slug VARCHAR(64) NOT NULL,
    remote_account_id VARCHAR(191) NULL,
    remote_campaign_id VARCHAR(191) NULL,
    extra_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_campaign_addon (campaign_id, addon_slug),
    INDEX idx_campaign_addon_slug (addon_slug),
    CONSTRAINT fk_campaign_addon_bindings_campaign
        FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @col_ts_provider_key = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'traffic_sources'
      AND COLUMN_NAME = 'provider_key'
);
SET @sql_ts_provider_key = IF(@col_ts_provider_key = 0,
    'ALTER TABLE traffic_sources ADD COLUMN provider_key VARCHAR(64) NULL AFTER name, ADD INDEX idx_traffic_sources_provider_key (provider_key)',
    'SELECT ''Column traffic_sources.provider_key already exists''');
PREPARE stmt FROM @sql_ts_provider_key;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE traffic_sources
SET provider_key = 'facebook'
WHERE provider_key IS NULL AND LOWER(name) LIKE '%facebook%';

UPDATE traffic_sources
SET provider_key = 'google_ads'
WHERE provider_key IS NULL
  AND (LOWER(name) LIKE '%google%' OR LOWER(name) LIKE '%youtube%')
  AND LOWER(name) NOT LIKE '%facebook%';

UPDATE traffic_sources
SET provider_key = 'taboola'
WHERE provider_key IS NULL AND LOWER(name) LIKE '%taboola%';

UPDATE traffic_sources
SET provider_key = 'tiktok'
WHERE provider_key IS NULL AND LOWER(name) LIKE '%tiktok%';

UPDATE traffic_sources
SET provider_key = 'outbrain'
WHERE provider_key IS NULL AND LOWER(name) LIKE '%outbrain%';

UPDATE traffic_sources
SET provider_key = 'bing'
WHERE provider_key IS NULL AND LOWER(name) LIKE '%bing%';
