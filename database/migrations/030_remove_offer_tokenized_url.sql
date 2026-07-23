-- Remove tokenized_url field from offers table
-- We now use the main url field for tokenized URLs
-- This migration is safe to run multiple times (checks if column exists first)

SET @dbname = DATABASE();
SET @tablename = 'offers';
SET @columnname = 'tokenized_url';
SET @preparedStatement = (SELECT IF(
    (
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE
            (TABLE_SCHEMA = @dbname)
            AND (TABLE_NAME = @tablename)
            AND (COLUMN_NAME = @columnname)
    ) > 0,
    CONCAT('ALTER TABLE ', @tablename, ' DROP COLUMN ', @columnname),
    'SELECT 1'
));
PREPARE alterIfExists FROM @preparedStatement;
EXECUTE alterIfExists;
DEALLOCATE PREPARE alterIfExists;

