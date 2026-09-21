-- Create clicks table (main tracking table, optimized for high volume)
CREATE TABLE IF NOT EXISTS clicks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT UNSIGNED NOT NULL,
    click_id CHAR(36) NOT NULL UNIQUE COMMENT 'UUID for tracking',
    ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip VARCHAR(45) NULL COMMENT 'IPv4 or IPv6 address',
    ua TEXT NULL COMMENT 'User agent string',
    referrer TEXT NULL,
    country CHAR(2) NULL COMMENT 'ISO 3166-1 alpha-2',
    region VARCHAR(50) NULL,
    city VARCHAR(100) NULL,
    device VARCHAR(20) NULL COMMENT 'mobile, tablet, desktop',
    os VARCHAR(50) NULL,
    browser VARCHAR(50) NULL,
    ts_hour DATETIME NULL COMMENT 'Truncated to hour for reporting',
    lp_click BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether LP was clicked',
    ts_lp DATETIME NULL COMMENT 'Timestamp of LP click',
    cost DECIMAL(12,6) NULL COMMENT 'Cost of this click',
    cost_currency CHAR(3) NULL COMMENT 'Currency of cost',
    extra_json JSON NULL COMMENT 'Additional tracking parameters',
    INDEX idx_campaign_ts (campaign_id, ts),
    INDEX idx_click_id (click_id),
    INDEX idx_ts_hour (ts_hour),
    INDEX idx_country (country),
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


