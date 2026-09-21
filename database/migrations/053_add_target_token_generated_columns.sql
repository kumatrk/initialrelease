-- Migration 053: Add Generated Columns for Target Token Breakdown (ad_name, adset_name)
-- Enables index usage for GROUP BY in campaign stats "Target Performance" view.
-- Without these, GROUP BY JSON_EXTRACT(extra_json, '$.traffic_source_tokens.ad_name') forces
-- a full scan and JSON parse per row; with indexed columns, target breakdown is 10-50x faster.
--
-- Pattern follows migration 050 (ad_id, adset_id). These are computed columns - no data change.

-- Step 1: Add generated columns for ad_name and adset_name (VARCHAR for display values)
SET @col_exists_ad_name = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'clicks'
    AND COLUMN_NAME = 'ad_name_value');
SET @col_exists_adset_name = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'clicks'
    AND COLUMN_NAME = 'adset_name_value');

SET @sql_ad_name = IF(@col_exists_ad_name = 0,
    'ALTER TABLE clicks ADD COLUMN ad_name_value VARCHAR(255) GENERATED ALWAYS AS (
        JSON_UNQUOTE(JSON_EXTRACT(extra_json, ''$.traffic_source_tokens.ad_name''))
    ) STORED COMMENT ''Generated from extra_json for target breakdown indexing''',
    'SELECT ''Column ad_name_value already exists''');
PREPARE stmt FROM @sql_ad_name;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_adset_name = IF(@col_exists_adset_name = 0,
    'ALTER TABLE clicks ADD COLUMN adset_name_value VARCHAR(255) GENERATED ALWAYS AS (
        JSON_UNQUOTE(JSON_EXTRACT(extra_json, ''$.traffic_source_tokens.adset_name''))
    ) STORED COMMENT ''Generated from extra_json for target breakdown indexing''',
    'SELECT ''Column adset_name_value already exists''');
PREPARE stmt FROM @sql_adset_name;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 2: Add composite indexes for fast GROUP BY / WHERE in target breakdown
-- campaign_id + ts narrow by campaign and date; *_value enables index-only grouping
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'clicks'
    AND INDEX_NAME = 'idx_clicks_campaign_ts_ad_name_value');
SET @sql = IF(@idx_exists = 0,
    'CREATE INDEX idx_clicks_campaign_ts_ad_name_value ON clicks(campaign_id, ts, ad_name_value)',
    'SELECT ''Index idx_clicks_campaign_ts_ad_name_value already exists''');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'clicks'
    AND INDEX_NAME = 'idx_clicks_campaign_ts_adset_name_value');
SET @sql = IF(@idx_exists = 0,
    'CREATE INDEX idx_clicks_campaign_ts_adset_name_value ON clicks(campaign_id, ts, adset_name_value)',
    'SELECT ''Index idx_clicks_campaign_ts_adset_name_value already exists''');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
