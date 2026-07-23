-- Migration 050: Add Generated Columns and Indexes for Performance Optimization
-- This migration adds generated columns for ad_id and adset_id extracted from JSON
-- to enable index usage and dramatically improve query performance for date ranges
--
-- IMPORTANT: These are computed columns - they don't change your data
-- They extract the same values from JSON that queries already use, just stored as columns
-- Attribution logic remains exactly the same, queries just run much faster

-- Step 1: Add generated columns to clicks table
-- These columns automatically extract values from extra_json->'$.traffic_source_tokens.ad_id' and adset_id
-- Note: Check if columns exist first to avoid errors on re-run
SET @col_exists_ad_id = (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'clicks' 
    AND COLUMN_NAME = 'ad_id');
SET @col_exists_adset_id = (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'clicks' 
    AND COLUMN_NAME = 'adset_id');

SET @sql_ad_id = IF(@col_exists_ad_id = 0,
    'ALTER TABLE clicks ADD COLUMN ad_id BIGINT UNSIGNED GENERATED ALWAYS AS (
        CAST(JSON_UNQUOTE(JSON_EXTRACT(extra_json, ''$.traffic_source_tokens.ad_id'')) AS UNSIGNED)
    ) STORED COMMENT ''Generated from extra_json for indexing''',
    'SELECT ''Column ad_id already exists''');
PREPARE stmt FROM @sql_ad_id;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_adset_id = IF(@col_exists_adset_id = 0,
    'ALTER TABLE clicks ADD COLUMN adset_id BIGINT UNSIGNED GENERATED ALWAYS AS (
        CAST(JSON_UNQUOTE(JSON_EXTRACT(extra_json, ''$.traffic_source_tokens.adset_id'')) AS UNSIGNED)
    ) STORED COMMENT ''Generated from extra_json for indexing''',
    'SELECT ''Column adset_id already exists''');
PREPARE stmt FROM @sql_adset_id;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 2: Add indexes on generated columns for fast lookups
-- These indexes enable the database to quickly find clicks by ad_id/adset_id and date/hour
-- Note: Check if indexes exist first to avoid errors on re-run
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'clicks' 
    AND INDEX_NAME = 'idx_clicks_ad_id_ts');
SET @sql = IF(@idx_exists = 0,
    'CREATE INDEX idx_clicks_ad_id_ts ON clicks(ad_id, ts)',
    'SELECT ''Index idx_clicks_ad_id_ts already exists''');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'clicks' 
    AND INDEX_NAME = 'idx_clicks_adset_id_ts');
SET @sql = IF(@idx_exists = 0,
    'CREATE INDEX idx_clicks_adset_id_ts ON clicks(adset_id, ts)',
    'SELECT ''Index idx_clicks_adset_id_ts already exists''');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Note: Function-based indexes (DATE(ts), HOUR(ts)) are not supported in MySQL
-- The composite indexes on (ad_id, ts) and (adset_id, ts) will still be very efficient
-- MySQL can use these indexes for date range queries and then filter by DATE/HOUR in memory

-- Step 3: Ensure index exists on clicks.ts for date range queries
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'clicks' 
    AND INDEX_NAME = 'idx_clicks_ts');
SET @sql = IF(@idx_exists = 0,
    'CREATE INDEX idx_clicks_ts ON clicks(ts)',
    'SELECT ''Index idx_clicks_ts already exists''');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 4: Add composite indexes on cost tables for faster joins
-- These indexes optimize the joins between clicks and cost tables
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'ad_hourly_costs' 
    AND INDEX_NAME = 'idx_ad_hourly_costs_ad_date_hour_account');
SET @sql = IF(@idx_exists = 0,
    'CREATE INDEX idx_ad_hourly_costs_ad_date_hour_account ON ad_hourly_costs(ad_id, date, hour, ad_account_id)',
    'SELECT ''Index idx_ad_hourly_costs_ad_date_hour_account already exists''');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'adset_hourly_costs' 
    AND INDEX_NAME = 'idx_adset_hourly_costs_adset_date_hour_account');
SET @sql = IF(@idx_exists = 0,
    'CREATE INDEX idx_adset_hourly_costs_adset_date_hour_account ON adset_hourly_costs(adset_id, date, hour, ad_account_id)',
    'SELECT ''Index idx_adset_hourly_costs_adset_date_hour_account already exists''');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 5: Add index for date range queries on cost tables
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'ad_hourly_costs' 
    AND INDEX_NAME = 'idx_ad_hourly_costs_date_range');
SET @sql = IF(@idx_exists = 0,
    'CREATE INDEX idx_ad_hourly_costs_date_range ON ad_hourly_costs(date, hour)',
    'SELECT ''Index idx_ad_hourly_costs_date_range already exists''');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'adset_hourly_costs' 
    AND INDEX_NAME = 'idx_adset_hourly_costs_date_range');
SET @sql = IF(@idx_exists = 0,
    'CREATE INDEX idx_adset_hourly_costs_date_range ON adset_hourly_costs(date, hour)',
    'SELECT ''Index idx_adset_hourly_costs_date_range already exists''');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

