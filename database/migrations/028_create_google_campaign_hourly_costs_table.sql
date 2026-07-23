-- Create google_campaign_hourly_costs table for hourly Google Ads campaign cost snapshots
-- This table stores hourly cumulative spend and delta for Google Ads campaigns
-- BETA: Table created but cost tracking disabled until post-beta release

CREATE TABLE IF NOT EXISTS google_campaign_hourly_costs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    integration_id INT UNSIGNED NOT NULL COMMENT 'FK to google_ads_integrations table',
    customer_id VARCHAR(50) NOT NULL COMMENT 'Google Ads customer ID (without dashes)',
    campaign_id BIGINT UNSIGNED NOT NULL COMMENT 'Google Ads campaign ID',
    campaign_name VARCHAR(255),
    advertising_channel_type ENUM('SEARCH', 'VIDEO', 'DISPLAY', 'SHOPPING', 'HOTEL', 'MULTI_CHANNEL', 'UNKNOWN') NOT NULL DEFAULT 'SEARCH',
    date DATE NOT NULL COMMENT 'Snapshot date',
    hour TINYINT UNSIGNED NOT NULL COMMENT 'Hour of the day (0-23)',
    spend DECIMAL(14,6) NOT NULL DEFAULT 0.00 COMMENT 'Total cumulative spend up to this hour',
    delta_spend DECIMAL(14,6) NOT NULL DEFAULT 0.00 COMMENT 'Difference since last hour (spend this hour)',
    clicks BIGINT UNSIGNED DEFAULT 0 COMMENT 'Number of clicks in this hour',
    impressions BIGINT UNSIGNED DEFAULT 0 COMMENT 'Number of impressions in this hour',
    avg_cpc DECIMAL(12,6) DEFAULT 0.00 COMMENT 'Average CPC for SEARCH campaigns',
    avg_cpm DECIMAL(12,6) DEFAULT 0.00 COMMENT 'Average CPM for VIDEO campaigns',
    avg_cpv DECIMAL(12,6) DEFAULT 0.00 COMMENT 'Average CPV for VIDEO campaigns',
    last_synced DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Time of last sync',
    source VARCHAR(50) NOT NULL DEFAULT 'google_ads_api' COMMENT 'Source identifier',
    FOREIGN KEY (integration_id) REFERENCES google_ads_integrations(id) ON DELETE CASCADE,
    UNIQUE KEY uk_campaign_hour (integration_id, campaign_id, date, hour),
    INDEX idx_campaign_date (campaign_id, date),
    INDEX idx_integration_date (integration_id, date, hour),
    INDEX idx_date_hour (date, hour)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Hourly cost snapshots for Google Ads campaigns, used for cost tracking and reporting. BETA: Disabled until post-beta release.';

