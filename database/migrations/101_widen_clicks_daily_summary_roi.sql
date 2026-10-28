-- Widen clicks_daily_summary.roi so a tiny CPC plus a normal payout
-- cannot overflow DECIMAL(10,4), drop the conversion, and roll back the day.
-- Idempotent.

SET @roi_type = (
    SELECT COLUMN_TYPE
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'clicks_daily_summary'
      AND COLUMN_NAME = 'roi'
    LIMIT 1
);
SET @sql_widen_roi = IF(
    @roi_type IS NOT NULL AND @roi_type <> 'decimal(20,4)',
    'ALTER TABLE clicks_daily_summary MODIFY roi DECIMAL(20,4) NULL COMMENT ''ROI percentage''',
    'SELECT ''clicks_daily_summary.roi already DECIMAL(20,4) or missing'''
);
PREPARE stmt FROM @sql_widen_roi;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
