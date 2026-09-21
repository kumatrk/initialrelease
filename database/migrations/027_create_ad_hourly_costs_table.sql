-- Create ad_hourly_costs table for hourly Facebook ad spend snapshots
-- This table stores hourly cumulative spend and delta for individual Facebook ads

CREATE TABLE IF NOT EXISTS ad_hourly_costs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ad_account_id INT UNSIGNED NOT NULL COMMENT 'FK to facebook_marketing_integrations table',
    ad_id BIGINT UNSIGNED NOT NULL COMMENT 'Facebook Ad ID',
    adset_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'Parent Adset ID (for grouping)',
    date DATE NOT NULL COMMENT 'Snapshot date',
    hour TINYINT UNSIGNED NOT NULL COMMENT 'Hour of the day (0-23)',
    spend DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Total cumulative spend up to this hour',
    delta_spend DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Difference since last hour (spend this hour)',
    last_synced DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Time of last sync',
    source VARCHAR(10) NOT NULL DEFAULT 'fb_api' COMMENT 'Source identifier',
    FOREIGN KEY (ad_account_id) REFERENCES facebook_marketing_integrations(id) ON DELETE CASCADE,
    UNIQUE KEY uk_ad_hour (ad_account_id, ad_id, date, hour),
    INDEX idx_adset_id (adset_id),
    INDEX idx_adset_date_hour (adset_id, date, hour),
    INDEX idx_ad_date_hour (ad_id, date, hour),
    INDEX idx_date_hour (date, hour)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Hourly cost snapshots for individual Facebook ads, used for per-ad performance tracking';

