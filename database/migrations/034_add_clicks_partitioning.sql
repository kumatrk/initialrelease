-- Migration 034: Add Table Partitioning and Archive System for Clicks Table
-- This migration implements a multi-layered approach to handle millions of clicks:
-- 1. Table partitioning by month (for active data)
-- 2. Archive table system (for old data, still accessible)
-- 3. Enhanced summary tables (for fast reporting)

-- IMPORTANT: This migration requires MySQL 5.7+ or MariaDB 10.2+ for partitioning support
-- Run this migration during a maintenance window as it may take time on large tables

-- Step 1: Create archive table structure (identical to clicks table)
-- Note: Additional columns (slug_id, device_brand, device_model, os_version, browser_version)
-- will be added by later migrations (040, 037) to keep this migration compatible
CREATE TABLE IF NOT EXISTS clicks_archive (
    id BIGINT UNSIGNED NOT NULL,
    campaign_id INT UNSIGNED NOT NULL,
    traffic_source_id INT UNSIGNED NULL,
    offer_id INT UNSIGNED NULL,
    landing_page_id INT UNSIGNED NULL,
    click_id CHAR(36) NOT NULL,
    ts DATETIME NOT NULL,
    ip VARCHAR(45) NULL,
    ua TEXT NULL,
    referrer TEXT NULL,
    country CHAR(2) NULL,
    region VARCHAR(50) NULL,
    city VARCHAR(100) NULL,
    device VARCHAR(20) NULL,
    os VARCHAR(50) NULL,
    browser VARCHAR(50) NULL,
    ts_hour DATETIME NULL,
    lp_click BOOLEAN NOT NULL DEFAULT FALSE,
    ts_lp DATETIME NULL,
    cost DECIMAL(12,6) NULL,
    cost_currency CHAR(3) NULL,
    extra_json JSON NULL,
    INDEX idx_campaign_ts (campaign_id, ts),
    INDEX idx_click_id (click_id),
    INDEX idx_ts (ts),
    INDEX idx_ts_hour (ts_hour),
    INDEX idx_country (country),
    INDEX idx_offer_id (offer_id),
    INDEX idx_landing_page_id (landing_page_id),
    INDEX idx_traffic_source_id (traffic_source_id),
    INDEX idx_archive_year_month (ts, campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Archived clicks older than retention period, still accessible for reporting';

-- Step 2: Create enhanced daily summary table (expanded from hourly rollups)
CREATE TABLE IF NOT EXISTS clicks_daily_summary (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT UNSIGNED NOT NULL,
    traffic_source_id INT UNSIGNED NULL,
    offer_id INT UNSIGNED NULL,
    landing_page_id INT UNSIGNED NULL,
    summary_date DATE NOT NULL COMMENT 'Date of summary',
    clicks INT UNSIGNED NOT NULL DEFAULT 0,
    lp_clicks INT UNSIGNED NOT NULL DEFAULT 0,
    direct_clicks INT UNSIGNED NOT NULL DEFAULT 0,
    conversions INT UNSIGNED NOT NULL DEFAULT 0,
    revenue DECIMAL(12,6) NOT NULL DEFAULT 0.000000,
    cost DECIMAL(12,6) NOT NULL DEFAULT 0.000000,
    profit DECIMAL(12,6) NOT NULL DEFAULT 0.000000,
    roi DECIMAL(10,4) NULL COMMENT 'ROI percentage',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_campaign_date_dimensions (campaign_id, traffic_source_id, offer_id, landing_page_id, summary_date),
    INDEX idx_summary_date (summary_date),
    INDEX idx_campaign_date (campaign_id, summary_date),
    INDEX idx_traffic_source_date (traffic_source_id, summary_date),
    INDEX idx_offer_date (offer_id, summary_date),
    INDEX idx_landing_page_date (landing_page_id, summary_date),
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (traffic_source_id) REFERENCES traffic_sources(id) ON DELETE SET NULL,
    FOREIGN KEY (offer_id) REFERENCES offers(id) ON DELETE SET NULL,
    FOREIGN KEY (landing_page_id) REFERENCES landing_pages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Daily aggregated summaries for fast reporting without scanning raw clicks';

-- Step 3: Add partition_date column to clicks table (for partitioning preparation)
-- Note: We'll use ts column directly for partitioning, but this helps with queries
-- Check if column already exists before adding
SET @dbname = DATABASE();
SET @tablename = 'clicks';
SET @columnname = 'partition_date';
SET @preparedStatement = (SELECT IF(
    (
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE
            (TABLE_SCHEMA = @dbname)
            AND (TABLE_NAME = @tablename)
            AND (COLUMN_NAME = @columnname)
    ) = 0,
    CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' DATE GENERATED ALWAYS AS (DATE(ts)) STORED COMMENT ''Generated column for partition optimization'' AFTER ts, ADD INDEX idx_partition_date (', @columnname, ', campaign_id)'),
    'SELECT 1'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Step 3b: Add partition_date column to clicks_archive table to match clicks structure
SET @tablename = 'clicks_archive';
SET @preparedStatement = (SELECT IF(
    (
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE
            (TABLE_SCHEMA = @dbname)
            AND (TABLE_NAME = @tablename)
            AND (COLUMN_NAME = @columnname)
    ) = 0,
    CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' DATE GENERATED ALWAYS AS (DATE(ts)) STORED COMMENT ''Generated column for partition optimization'' AFTER ts, ADD INDEX idx_partition_date (', @columnname, ', campaign_id)'),
    'SELECT 1'
));
PREPARE alterIfNotExists2 FROM @preparedStatement;
EXECUTE alterIfNotExists2;
DEALLOCATE PREPARE alterIfNotExists2;

-- Step 4: Create view for unified clicks access (active + archive)
-- Note: This view uses only columns that exist in both tables at this migration point
-- Later migrations (037, 040) will add columns to both clicks and clicks_archive tables
-- The view will need to be manually updated after those migrations if you want to include new columns
-- For now, we use explicit column list matching what exists in both tables at migration 034
-- Drop view first if it exists to avoid conflicts
DROP VIEW IF EXISTS clicks_unified;

CREATE VIEW clicks_unified AS
SELECT 
    id, campaign_id, traffic_source_id, offer_id, landing_page_id, click_id, ts, 
    partition_date, ip, ua, referrer, country, region, city, device, os, browser, 
    ts_hour, lp_click, ts_lp, cost, cost_currency, extra_json
FROM clicks
UNION ALL
SELECT 
    id, campaign_id, traffic_source_id, offer_id, landing_page_id, click_id, ts, 
    partition_date, ip, ua, referrer, country, region, city, device, os, browser, 
    ts_hour, lp_click, ts_lp, cost, cost_currency, extra_json
FROM clicks_archive;

-- Note: Table partitioning by RANGE on ts column will be done manually via:
-- ALTER TABLE clicks PARTITION BY RANGE (TO_DAYS(ts)) (
--     PARTITION p202401 VALUES LESS THAN (TO_DAYS('2024-02-01')),
--     PARTITION p202402 VALUES LESS THAN (TO_DAYS('2024-03-01')),
--     ... (add partitions as needed)
--     PARTITION p_future VALUES LESS THAN MAXVALUE
-- );
-- This is done manually because we need to create partitions dynamically based on data

