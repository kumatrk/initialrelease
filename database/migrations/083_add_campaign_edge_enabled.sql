-- Migration 083: Edge Redirect Engine — per-campaign edge flag + sync timestamp
-- Idempotent: safe when columns already exist (e.g. local/dev DB ahead of migrations table).

SET @col_edge_enabled = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'campaigns'
      AND COLUMN_NAME = 'edge_enabled'
);
SET @sql_edge_enabled = IF(@col_edge_enabled = 0,
    'ALTER TABLE campaigns ADD COLUMN edge_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER redirectless_tracking',
    'SELECT ''Column edge_enabled already exists''');
PREPARE stmt FROM @sql_edge_enabled;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_edge_synced_at = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'campaigns'
      AND COLUMN_NAME = 'edge_synced_at'
);
SET @sql_edge_synced_at = IF(@col_edge_synced_at = 0,
    'ALTER TABLE campaigns ADD COLUMN edge_synced_at DATETIME NULL DEFAULT NULL AFTER edge_enabled',
    'SELECT ''Column edge_synced_at already exists''');
PREPARE stmt FROM @sql_edge_synced_at;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_edge_sync_error = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'campaigns'
      AND COLUMN_NAME = 'edge_sync_error'
);
SET @sql_edge_sync_error = IF(@col_edge_sync_error = 0,
    'ALTER TABLE campaigns ADD COLUMN edge_sync_error VARCHAR(500) NULL DEFAULT NULL AFTER edge_synced_at',
    'SELECT ''Column edge_sync_error already exists''');
PREPARE stmt FROM @sql_edge_sync_error;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
