-- Migration 098: Campaign bot and proxy routing (allow | record_only | safe_url)
-- Idempotent: safe when columns already exist.

SET @col_mode = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'campaigns'
      AND COLUMN_NAME = 'bot_route_mode'
);
SET @sql_mode = IF(@col_mode = 0,
    'ALTER TABLE campaigns ADD COLUMN bot_route_mode VARCHAR(20) NOT NULL DEFAULT ''allow''
      COMMENT ''Bot/proxy traffic: allow | record_only | safe_url''
      AFTER inactive_redirect_url',
    'SELECT ''Column bot_route_mode already exists''');
PREPARE stmt FROM @sql_mode;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_url = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'campaigns'
      AND COLUMN_NAME = 'bot_route_url'
);
SET @sql_url = IF(@col_url = 0,
    'ALTER TABLE campaigns ADD COLUMN bot_route_url VARCHAR(2048) NULL DEFAULT NULL
      COMMENT ''http(s) URL when bot_route_mode=safe_url''
      AFTER bot_route_mode',
    'SELECT ''Column bot_route_url already exists''');
PREPARE stmt FROM @sql_url;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
