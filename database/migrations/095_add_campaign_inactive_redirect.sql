-- Migration 095: Inactive campaign redirect (paused/archived → another campaign or URL)
-- Idempotent: safe when columns already exist.

SET @col_mode = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'campaigns'
      AND COLUMN_NAME = 'inactive_redirect_mode'
);
SET @sql_mode = IF(@col_mode = 0,
    'ALTER TABLE campaigns ADD COLUMN inactive_redirect_mode VARCHAR(20) NOT NULL DEFAULT ''off''
      COMMENT ''When paused/archived: off | campaign | url''
      AFTER allow_multiple_conversions',
    'SELECT ''Column inactive_redirect_mode already exists''');
PREPARE stmt FROM @sql_mode;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_camp = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'campaigns'
      AND COLUMN_NAME = 'inactive_redirect_campaign_id'
);
SET @sql_camp = IF(@col_camp = 0,
    'ALTER TABLE campaigns ADD COLUMN inactive_redirect_campaign_id INT UNSIGNED NULL DEFAULT NULL
      COMMENT ''Target campaign when inactive_redirect_mode=campaign''
      AFTER inactive_redirect_mode',
    'SELECT ''Column inactive_redirect_campaign_id already exists''');
PREPARE stmt FROM @sql_camp;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_url = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'campaigns'
      AND COLUMN_NAME = 'inactive_redirect_url'
);
SET @sql_url = IF(@col_url = 0,
    'ALTER TABLE campaigns ADD COLUMN inactive_redirect_url VARCHAR(2048) NULL DEFAULT NULL
      COMMENT ''Custom URL when inactive_redirect_mode=url''
      AFTER inactive_redirect_campaign_id',
    'SELECT ''Column inactive_redirect_url already exists''');
PREPARE stmt FROM @sql_url;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_camp = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'campaigns'
      AND INDEX_NAME = 'idx_campaigns_inactive_redirect_campaign'
);
SET @sql_idx = IF(@idx_camp = 0,
    'ALTER TABLE campaigns ADD INDEX idx_campaigns_inactive_redirect_campaign (inactive_redirect_campaign_id)',
    'SELECT ''Index idx_campaigns_inactive_redirect_campaign already exists''');
PREPARE stmt FROM @sql_idx;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
