-- Migration 094: Pre-aggregated token-level hourly stats (UTC hour 0–23).
-- Mirrors clicks_stats_by_token_daily with an hour dimension for Breakdown Hour nests.
-- On-write via DailySummaryUpdater; never purge this table in age retention.

CREATE TABLE IF NOT EXISTS clicks_stats_by_token_hourly (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT UNSIGNED NOT NULL,
    summary_date DATE NOT NULL,
    hour TINYINT UNSIGNED NOT NULL COMMENT 'UTC hour 0-23',
    token_param VARCHAR(128) NOT NULL COMMENT 'e.g. ad_name, utm_source, custom key',
    token_value VARCHAR(512) NOT NULL DEFAULT '',
    traffic_source_id INT UNSIGNED NULL,
    visitors INT UNSIGNED NOT NULL DEFAULT 0,
    lp_clicks INT UNSIGNED NOT NULL DEFAULT 0,
    conversions INT UNSIGNED NOT NULL DEFAULT 0,
    optins INT UNSIGNED NOT NULL DEFAULT 0,
    revenue DECIMAL(12,6) NOT NULL DEFAULT 0.000000,
    cost DECIMAL(12,6) NOT NULL DEFAULT 0.000000,
    bot_clicks INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_campaign_date_hour_token (campaign_id, summary_date, hour, token_param, token_value),
    INDEX idx_campaign_date_hour (campaign_id, summary_date, hour),
    INDEX idx_campaign_date_param (campaign_id, summary_date, token_param),
    INDEX idx_campaign_date_hour_param (campaign_id, summary_date, hour, token_param),
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (traffic_source_id) REFERENCES traffic_sources(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Hourly aggregates by token (param/value) for Breakdown Hour without scanning clicks';
