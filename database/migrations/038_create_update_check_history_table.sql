-- Migration 038: Create update check history table
-- Tracks update checks and available updates

CREATE TABLE IF NOT EXISTS update_check_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    current_version VARCHAR(20) NOT NULL,
    latest_version VARCHAR(20) NULL,
    update_available BOOLEAN NOT NULL DEFAULT FALSE,
    update_type VARCHAR(20) NULL COMMENT 'patch, minor, major, hotfix',
    changelog TEXT NULL,
    check_successful BOOLEAN NOT NULL DEFAULT FALSE,
    error_message TEXT NULL,
    INDEX idx_checked_at (checked_at),
    INDEX idx_update_available (update_available)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='History of update checks and available updates';

