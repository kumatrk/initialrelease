-- One-shot marker for Fix CPM history (prevent accidental double ÷1000).
-- Safe to re-run.

SET @db := DATABASE();

SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'campaigns'
      AND COLUMN_NAME = 'cpm_history_fixed_at'
);

SET @sql := IF(
    @col_exists = 0,
    'ALTER TABLE campaigns ADD COLUMN cpm_history_fixed_at DATETIME NULL DEFAULT NULL COMMENT ''When Fix CPM history last ran (blocks re-run unless override)'' AFTER cost_model',
    'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_from := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'campaigns'
      AND COLUMN_NAME = 'cpm_history_fixed_from'
);

SET @sql_from := IF(
    @col_from = 0,
    'ALTER TABLE campaigns ADD COLUMN cpm_history_fixed_from DATE NULL DEFAULT NULL COMMENT ''UTC date_from of last Fix CPM history'' AFTER cpm_history_fixed_at',
    'SELECT 1'
);

PREPARE stmt_from FROM @sql_from;
EXECUTE stmt_from;
DEALLOCATE PREPARE stmt_from;

SET @col_to := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'campaigns'
      AND COLUMN_NAME = 'cpm_history_fixed_to'
);

SET @sql_to := IF(
    @col_to = 0,
    'ALTER TABLE campaigns ADD COLUMN cpm_history_fixed_to DATE NULL DEFAULT NULL COMMENT ''UTC date_to of last Fix CPM history'' AFTER cpm_history_fixed_from',
    'SELECT 1'
);

PREPARE stmt_to FROM @sql_to;
EXECUTE stmt_to;
DEALLOCATE PREPARE stmt_to;
