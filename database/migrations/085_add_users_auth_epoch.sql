-- Session invalidation after password change/reset (auth_epoch)
-- Safe to re-run: checks column before add

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND column_name = 'auth_epoch'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE users ADD COLUMN auth_epoch INT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''Bumped on password change to invalidate other sessions'' AFTER pass_hash',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
