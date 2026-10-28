-- Widen update_logs.error_log so a long upgrade failure can be stored.
-- Safe to re-run.

SET @exists := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'update_logs'
);

SET @sql := IF(
    @exists = 1,
    'ALTER TABLE update_logs MODIFY error_log MEDIUMTEXT NULL COMMENT ''Error details if update failed''',
    'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
