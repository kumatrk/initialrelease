-- Widen settings.value and update_check_history.changelog so update-check
-- cache JSON (and release notes) cannot overflow classic TEXT (65,535 bytes).
-- Safe to re-run. Matches the pattern of migration 097 for update_logs.error_log.

SET @settings_exists := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'settings'
);

SET @sql := IF(
    @settings_exists = 1,
    'ALTER TABLE settings MODIFY value MEDIUMTEXT NULL',
    'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @history_exists := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'update_check_history'
);

SET @sql := IF(
    @history_exists = 1,
    'ALTER TABLE update_check_history MODIFY changelog MEDIUMTEXT NULL',
    'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
