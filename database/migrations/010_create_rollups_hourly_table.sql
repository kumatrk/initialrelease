-- Create rollups_hourly table (materialized aggregates for fast reporting)
CREATE TABLE IF NOT EXISTS rollups_hourly (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT UNSIGNED NOT NULL,
    hour_ts DATETIME NOT NULL COMMENT 'Hour timestamp',
    clicks INT UNSIGNED NOT NULL DEFAULT 0,
    lp_clicks INT UNSIGNED NOT NULL DEFAULT 0,
    convs INT UNSIGNED NOT NULL DEFAULT 0,
    revenue DECIMAL(12,6) NOT NULL DEFAULT 0.000000,
    cost DECIMAL(12,6) NOT NULL DEFAULT 0.000000,
    profit DECIMAL(12,6) NOT NULL DEFAULT 0.000000,
    roi DECIMAL(10,4) NULL COMMENT 'ROI percentage',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_campaign_hour (campaign_id, hour_ts),
    INDEX idx_hour_ts (hour_ts),
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


