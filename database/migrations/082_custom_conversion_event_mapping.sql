-- Migration 082: Custom conversion event mapping (inbound et → Meta CAPI event_name)
-- Adds conversions.event_key and Meta CAPI mapping / PageView-on-click flags.
-- Idempotent: skips columns/indexes that already exist.

SET @db := DATABASE();

-- conversions.event_key
SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'conversions' AND COLUMN_NAME = 'event_key'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE conversions ADD COLUMN event_key VARCHAR(64) NULL COMMENT ''Canonical inbound funnel event key (from et/event/event_type)'' AFTER event_id',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'conversions' AND INDEX_NAME = 'idx_event_key'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE conversions ADD INDEX idx_event_key (event_key)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'conversions' AND INDEX_NAME = 'idx_click_txid_event_key'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE conversions ADD INDEX idx_click_txid_event_key (click_id, txid, event_key)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- facebook_capi_integrations.event_mapping_json
SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'facebook_capi_integrations' AND COLUMN_NAME = 'event_mapping_json'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE facebook_capi_integrations ADD COLUMN event_mapping_json JSON NULL COMMENT ''Map of inbound event_key → Meta event_name'' AFTER event_type',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- facebook_capi_integrations.send_pageview_on_click
SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'facebook_capi_integrations' AND COLUMN_NAME = 'send_pageview_on_click'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE facebook_capi_integrations ADD COLUMN send_pageview_on_click TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''When 1, fire CAPI PageView asynchronously on tracked click'' AFTER event_mapping_json',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
