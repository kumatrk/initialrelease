-- Migration 100: Grizzly Guard campaign destinations, action log, and hold rows.
-- Idempotent: safe when the columns and tables already exist.

SET @col_mode = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'campaigns'
      AND COLUMN_NAME = 'guard_mode'
);
SET @sql_mode = IF(@col_mode = 0,
    'ALTER TABLE campaigns ADD COLUMN guard_mode VARCHAR(16) NOT NULL DEFAULT ''off''
      COMMENT ''Grizzly Guard: off | backups | full''
      AFTER bot_route_url',
    'SELECT ''Column guard_mode already exists''');
PREPARE stmt FROM @sql_mode;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_domain = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'campaigns'
      AND COLUMN_NAME = 'guard_backup_domain_id'
);
SET @sql_domain = IF(@col_domain = 0,
    'ALTER TABLE campaigns ADD COLUMN guard_backup_domain_id INT UNSIGNED NULL DEFAULT NULL
      COMMENT ''Backup tracking domain chosen for Grizzly Guard''
      AFTER guard_mode',
    'SELECT ''Column guard_backup_domain_id already exists''');
PREPARE stmt FROM @sql_domain;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_lp = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'campaigns'
      AND COLUMN_NAME = 'guard_backup_lp_id'
);
SET @sql_lp = IF(@col_lp = 0,
    'ALTER TABLE campaigns ADD COLUMN guard_backup_lp_id INT UNSIGNED NULL DEFAULT NULL
      COMMENT ''Backup landing page chosen for Grizzly Guard''
      AFTER guard_backup_domain_id',
    'SELECT ''Column guard_backup_lp_id already exists''');
PREPARE stmt FROM @sql_lp;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_url = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'campaigns'
      AND COLUMN_NAME = 'guard_last_resort_url'
);
SET @sql_url = IF(@col_url = 0,
    'ALTER TABLE campaigns ADD COLUMN guard_last_resort_url VARCHAR(500) NULL DEFAULT NULL
      COMMENT ''Plain http(s) URL when nothing healthy remains''
      AFTER guard_backup_lp_id',
    'SELECT ''Column guard_last_resort_url already exists''');
PREPARE stmt FROM @sql_url;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_saved = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'campaigns'
      AND COLUMN_NAME = 'guard_saved_domain_id'
);
SET @sql_saved = IF(@col_saved = 0,
    'ALTER TABLE campaigns ADD COLUMN guard_saved_domain_id INT UNSIGNED NULL DEFAULT NULL
      COMMENT ''Tracking domain to restore. 0 means the main tracker domain.''
      AFTER guard_last_resort_url',
    'SELECT ''Column guard_saved_domain_id already exists''');
PREPARE stmt FROM @sql_saved;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS grizzly_guard_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT UNSIGNED NULL,
    action VARCHAR(40) NOT NULL,
    target_label VARCHAR(255) NOT NULL DEFAULT '',
    detail VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_grizzly_guard_log_created (created_at),
    INDEX idx_grizzly_guard_log_campaign (campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS grizzly_guard_holds (
    campaign_id INT UNSIGNED NOT NULL,
    target_type VARCHAR(16) NOT NULL,
    target_id INT UNSIGNED NOT NULL,
    reason VARCHAR(80) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (campaign_id, target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
