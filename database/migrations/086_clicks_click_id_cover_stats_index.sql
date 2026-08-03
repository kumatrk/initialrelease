-- Covering index for conversion→click attribution joins.
-- Unique click_id alone forces a PK lookup into fat click rows (extra_json), which
-- is ~1s per ~1.5k conversions and makes single-day non-UTC dashboard KPIs crawl.
-- This index lets FORCE INDEX joins resolve ts/exclude/campaign_id index-only.

SET @db := DATABASE();
SET @exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'clicks'
      AND INDEX_NAME = 'idx_clicks_click_id_cover_stats'
);

SET @sql := IF(
    @exists = 0,
    'CREATE INDEX idx_clicks_click_id_cover_stats ON clicks (click_id, exclude_from_stats, ts, campaign_id)',
    'SELECT ''idx_clicks_click_id_cover_stats already exists'' AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
