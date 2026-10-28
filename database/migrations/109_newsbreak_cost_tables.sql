-- Built-in NewsBreak cost sync (Access-Token → getIntegratedReport → hourly overlay).

CREATE TABLE IF NOT EXISTS newsbreak_integrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    access_token_encrypted TEXT NOT NULL COMMENT 'SecretEncryption ciphertext',
    timezone VARCHAR(64) NOT NULL DEFAULT 'UTC' COMMENT 'Report timezone for getIntegratedReport',
    ad_account_id VARCHAR(64) NULL COMMENT 'Optional AD_ACCOUNT filter id',
    status ENUM('active', 'paused') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_nb_status (status),
    INDEX idx_nb_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS newsbreak_campaign_hourly_costs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    integration_id INT UNSIGNED NOT NULL,
    kuma_campaign_id INT UNSIGNED NOT NULL COMMENT 'Local campaigns.id for summary-first overlay',
    remote_campaign_id VARCHAR(64) NOT NULL COMMENT 'NewsBreak campaignId',
    remote_campaign_name VARCHAR(255) NULL,
    cost_date DATE NOT NULL,
    cost_hour TINYINT UNSIGNED NOT NULL COMMENT '0-23 in report timezone',
    spend DECIMAL(14,6) NOT NULL DEFAULT 0 COMMENT 'Dollars for this hour (API cost is cents)',
    impressions BIGINT UNSIGNED NOT NULL DEFAULT 0,
    clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_synced DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_nb_camp_hour (integration_id, kuma_campaign_id, remote_campaign_id, cost_date, cost_hour),
    INDEX idx_nb_kuma_date (kuma_campaign_id, cost_date, cost_hour),
    INDEX idx_nb_remote_date (remote_campaign_id, cost_date),
    CONSTRAINT fk_nb_hourly_integration
        FOREIGN KEY (integration_id) REFERENCES newsbreak_integrations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
