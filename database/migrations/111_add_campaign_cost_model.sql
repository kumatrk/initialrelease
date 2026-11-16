-- Per-campaign CPC vs CPM cost model for manual URL/default cost ingest.
-- Safe to re-run.

SET @db := DATABASE();

SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'campaigns'
      AND COLUMN_NAME = 'cost_model'
);

SET @sql := IF(
    @col_exists = 0,
    'ALTER TABLE campaigns ADD COLUMN cost_model ENUM(''cpc'',''cpm'') NOT NULL DEFAULT ''cpc'' COMMENT ''How URL/default cost tokens are stored on clicks: cpc as-is, cpm = raw/1000'' AFTER default_cpc',
    'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
