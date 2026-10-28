-- Migration 099: Per-user report column order (dashboard + campaign stats)
-- Idempotent: safe when the column already exists.

SET @col_report = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'report_columns_json'
);
SET @sql_report = IF(@col_report = 0,
    'ALTER TABLE users ADD COLUMN report_columns_json TEXT NULL
      COMMENT ''JSON column order for dashboard and campaign stats''
      AFTER dashboard_charts_hidden',
    'SELECT ''Column report_columns_json already exists''');
PREPARE stmt FROM @sql_report;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
