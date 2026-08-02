-- Hash-only password reset tokens; clear legacy plaintext column
-- Also enforce unique emails for unambiguous password reset targeting

UPDATE password_reset_tokens
SET token_plain = ''
WHERE token_plain IS NOT NULL AND token_plain != '';

-- Drop legacy plaintext matching index if present
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'password_reset_tokens'
      AND index_name = 'idx_token_plain'
);
SET @sql := IF(@idx_exists > 0, 'ALTER TABLE password_reset_tokens DROP INDEX idx_token_plain', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Make token_plain nullable unused legacy column (kept for older installs; no longer matched)
ALTER TABLE password_reset_tokens
    MODIFY COLUMN token_plain VARCHAR(64) NULL DEFAULT NULL COMMENT 'Deprecated; unused (hash-only tokens)';

-- Deduplicate emails before unique index (keep lowest id per email)
UPDATE users u
INNER JOIN (
    SELECT email, MIN(id) AS keep_id
    FROM users
    WHERE email IS NOT NULL AND email != ''
    GROUP BY email
    HAVING COUNT(*) > 1
) d ON u.email = d.email AND u.id != d.keep_id
SET u.email = CONCAT(u.email, '+dup', u.id);

SET @email_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND index_name = 'uniq_users_email'
);
SET @sql2 := IF(@email_idx = 0,
    'ALTER TABLE users ADD UNIQUE KEY uniq_users_email (email)',
    'SELECT 1');
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
