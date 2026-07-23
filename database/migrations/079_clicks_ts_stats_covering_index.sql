-- Covering index for dashboard/stats date-range aggregates on clicks.
-- Without this, WHERE ts BETWEEN ... AND SUM(lp_click)/SUM(cost) must read full rows
-- and becomes multi-second (or timeout) once the table grows into hundreds of thousands.
-- Safe to run multiple times (checks information_schema first via runner, or IF NOT EXISTS pattern).

SET @db := DATABASE();
SET @exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'clicks'
      AND INDEX_NAME = 'idx_clicks_ts_stats_cover'
);

SET @sql := IF(
    @exists = 0,
    'CREATE INDEX idx_clicks_ts_stats_cover ON clicks (ts, campaign_id, lp_click, landing_page_id, cost)',
    'SELECT ''idx_clicks_ts_stats_cover already exists'' AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
