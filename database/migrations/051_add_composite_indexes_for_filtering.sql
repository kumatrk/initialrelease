-- Migration 051: Add Composite Indexes for Stats View Filtering Performance
-- This migration adds composite indexes to optimize filtering and sorting in the stats view
-- These indexes significantly improve performance when filtering by region, offer_id, and combinations
-- with date ranges at scale (100k+ records)
--
-- IMPORTANT: These indexes are designed for common filter combinations used in campaign-stats.php
-- They enable fast lookups when users filter by multiple variables simultaneously

-- Step 1: Add composite index for campaign + date range + offer filtering
-- This optimizes queries that filter by campaign and offer within a date range
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'clicks' 
    AND INDEX_NAME = 'idx_clicks_campaign_ts_offer');
SET @sql = IF(@idx_exists = 0,
    'CREATE INDEX idx_clicks_campaign_ts_offer ON clicks(campaign_id, ts, offer_id)',
    'SELECT ''Index idx_clicks_campaign_ts_offer already exists''');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 2: Add composite index for region + date range filtering
-- This optimizes queries that filter by state/region within a date range
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'clicks' 
    AND INDEX_NAME = 'idx_clicks_region_ts');
SET @sql = IF(@idx_exists = 0,
    'CREATE INDEX idx_clicks_region_ts ON clicks(region, ts)',
    'SELECT ''Index idx_clicks_region_ts already exists''');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 3: Add composite index for offer + date range filtering
-- This optimizes queries that filter by offer_id within a date range
-- Note: offer_id index may already exist from migration 020, but this composite is more efficient
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'clicks' 
    AND INDEX_NAME = 'idx_clicks_offer_ts');
SET @sql = IF(@idx_exists = 0,
    'CREATE INDEX idx_clicks_offer_ts ON clicks(offer_id, ts)',
    'SELECT ''Index idx_clicks_offer_ts already exists''');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 4: Add composite index for landing_page + date range filtering
-- This optimizes queries that filter by landing_page_id within a date range
-- Note: landing_page_id index may already exist from migration 020, but this composite is more efficient
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'clicks' 
    AND INDEX_NAME = 'idx_clicks_landing_page_ts');
SET @sql = IF(@idx_exists = 0,
    'CREATE INDEX idx_clicks_landing_page_ts ON clicks(landing_page_id, ts)',
    'SELECT ''Index idx_clicks_landing_page_ts already exists''');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 5: Add composite index for country + region + date range filtering
-- This optimizes queries that filter by both country and region within a date range
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'clicks' 
    AND INDEX_NAME = 'idx_clicks_country_region_ts');
SET @sql = IF(@idx_exists = 0,
    'CREATE INDEX idx_clicks_country_region_ts ON clicks(country, region, ts)',
    'SELECT ''Index idx_clicks_country_region_ts already exists''');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Performance Notes:
-- - These composite indexes are most effective when queries filter by the leftmost columns first
-- - The ts (timestamp) column at the end enables efficient date range filtering
-- - MySQL can use these indexes for both filtering and sorting operations
-- - Index maintenance overhead is minimal compared to query performance gains at scale

