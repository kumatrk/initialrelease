-- Migration 087: Allow multiple conversions on the same click (e.g. Propush multi-earn)
-- Default 0 = legacy one-conversion-per-click when postback has no txid/event_id.
-- Idempotent: safe when column already exists.

SET @col_allow_multi = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'campaigns'
      AND COLUMN_NAME = 'allow_multiple_conversions'
);
SET @sql_allow_multi = IF(@col_allow_multi = 0,
    'ALTER TABLE campaigns ADD COLUMN allow_multiple_conversions TINYINT(1) NOT NULL DEFAULT 0
      COMMENT ''When 1, accept additional postbacks on the same click_id without requiring a new txid (Propush-style multi-earn)''
      AFTER min_postback_payout',
    'SELECT ''Column allow_multiple_conversions already exists''');
PREPARE stmt FROM @sql_allow_multi;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
