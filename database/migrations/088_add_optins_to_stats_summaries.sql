-- Migration 088: Separate opt-in counts on summary-first stats tables (BeMob-style).
-- Opt-ins stay in `conversions` rows but do not inflate conversions/revenue aggregates.
-- Idempotent. Historical backfill is applied by scripts/backfill-optins-summary.php (or verify).

SET @col_daily_optins = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'clicks_daily_summary'
      AND COLUMN_NAME = 'optins'
);
SET @sql_daily_optins = IF(@col_daily_optins = 0,
    'ALTER TABLE clicks_daily_summary ADD COLUMN optins INT UNSIGNED NOT NULL DEFAULT 0 AFTER conversions',
    'SELECT ''Column clicks_daily_summary.optins already exists''');
PREPARE stmt FROM @sql_daily_optins;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_token_optins = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'clicks_stats_by_token_daily'
      AND COLUMN_NAME = 'optins'
);
SET @sql_token_optins = IF(@col_token_optins = 0,
    'ALTER TABLE clicks_stats_by_token_daily ADD COLUMN optins INT UNSIGNED NOT NULL DEFAULT 0 AFTER conversions',
    'SELECT ''Column clicks_stats_by_token_daily.optins already exists''');
PREPARE stmt FROM @sql_token_optins;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
