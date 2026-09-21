-- Honeycomb runtime: shared hourly spend for traffic-source addons (summary-first overlay).

CREATE TABLE IF NOT EXISTS honeycomb_campaign_hourly_costs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    addon_slug VARCHAR(64) NOT NULL,
    credential_id INT UNSIGNED NULL,
    remote_account_id VARCHAR(191) NOT NULL,
    remote_campaign_id VARCHAR(191) NOT NULL,
    campaign_id INT UNSIGNED NULL COMMENT 'Kuma campaign_id when known via binding',
    date DATE NOT NULL,
    hour TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0-23; daily reports may use hour=0 only',
    spend DECIMAL(14,6) NOT NULL DEFAULT 0.00 COMMENT 'Cumulative or period spend as stored by the addon',
    delta_spend DECIMAL(14,6) NOT NULL DEFAULT 0.00 COMMENT 'Spend attributed to this hour/day bucket',
    currency VARCHAR(8) NULL,
    last_synced DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    source VARCHAR(64) NOT NULL DEFAULT 'honeycomb',
    UNIQUE KEY uk_honeycomb_hour (
        addon_slug,
        remote_account_id,
        remote_campaign_id,
        date,
        hour
    ),
    INDEX idx_honeycomb_cost_campaign (campaign_id, date, hour),
    INDEX idx_honeycomb_cost_slug_date (addon_slug, date),
    INDEX idx_honeycomb_cost_remote (addon_slug, remote_campaign_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
