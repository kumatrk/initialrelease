-- Migration 054: Pre-aggregated token-level stats and watermark for incremental cron
-- Plan: stats pre-aggregation Phase 2 – future-proof target breakdown (1 variable)
-- One row per (campaign_id, summary_date, token_param, token_value) with aggregated metrics.

-- Token-level daily aggregates (any variable: ad_name, utm_*, custom tokens)
CREATE TABLE IF NOT EXISTS clicks_stats_by_token_daily (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT UNSIGNED NOT NULL,
    summary_date DATE NOT NULL,
    token_param VARCHAR(128) NOT NULL COMMENT 'e.g. ad_name, utm_source, custom key',
    token_value VARCHAR(512) NOT NULL DEFAULT '',
    traffic_source_id INT UNSIGNED NULL,
    visitors INT UNSIGNED NOT NULL DEFAULT 0,
    lp_clicks INT UNSIGNED NOT NULL DEFAULT 0,
    conversions INT UNSIGNED NOT NULL DEFAULT 0,
    revenue DECIMAL(12,6) NOT NULL DEFAULT 0.000000,
    cost DECIMAL(12,6) NOT NULL DEFAULT 0.000000,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_campaign_date_token (campaign_id, summary_date, token_param, token_value),
    INDEX idx_campaign_date (campaign_id, summary_date),
    INDEX idx_campaign_date_param (campaign_id, summary_date, token_param),
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (traffic_source_id) REFERENCES traffic_sources(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Daily aggregates by token (param/value) for Target Performance without scanning clicks';

-- Watermark for incremental token aggregation cron (single row, id=1)
CREATE TABLE IF NOT EXISTS clicks_stats_by_token_daily_meta (
    id INT PRIMARY KEY AUTO_INCREMENT,
    last_processed_click_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Watermark for incremental token aggregate updates';

-- Seed watermark row so cron can start from 0
INSERT IGNORE INTO clicks_stats_by_token_daily_meta (id, last_processed_click_id) VALUES (1, 0);

-- Index for incremental cron: WHERE id > ? ORDER BY id LIMIT N (id is PK; adding (id, ts) can help if we need ts in same read)
-- Only add if not exists (clicks may already have idx_campaign_ts etc.)
SET @dbname = DATABASE();
SET @tablename = 'clicks';
SET @indexname = 'idx_clicks_id_ts';
SET @preparedStatement = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND INDEX_NAME = @indexname) = 0,
    CONCAT('ALTER TABLE ', @tablename, ' ADD INDEX ', @indexname, ' (id, ts)'),
    'SELECT 1'
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
