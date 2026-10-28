-- Totals by period starts collapsed, the same way Performance Trends does.
-- Idempotent.

SET @col_period_hidden = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'dashboard_period_hidden'
);
SET @sql_period_hidden = IF(@col_period_hidden = 0,
    'ALTER TABLE users ADD COLUMN dashboard_period_hidden TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''1 = hide dashboard Totals by period'' AFTER dashboard_charts_hidden',
    'SELECT ''Column users.dashboard_period_hidden already exists''');
PREPARE stmt FROM @sql_period_hidden;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
