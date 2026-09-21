-- Create password_reset_tokens table for secure password reset functionality
-- Stores reset tokens with expiration and usage tracking

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL COMMENT 'FK to users table',
    token VARCHAR(64) NOT NULL COMMENT 'Secure reset token (hashed)',
    token_plain VARCHAR(64) NOT NULL COMMENT 'Plain token for URL (will be removed after use)',
    expires_at DATETIME NOT NULL COMMENT 'Token expiration time (typically 1 hour)',
    used_at DATETIME NULL DEFAULT NULL COMMENT 'When token was used (NULL if unused)',
    ip_address VARCHAR(45) DEFAULT NULL COMMENT 'IP address that requested reset',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_token (token),
    INDEX idx_token_plain (token_plain),
    INDEX idx_expires_at (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Stores password reset tokens with expiration and usage tracking for security';

