-- Migration 049: Create update_logs table
-- Tracks actual update installations, not just checks

CREATE TABLE IF NOT EXISTS update_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    version_from VARCHAR(20) NOT NULL,
    version_to VARCHAR(20) NOT NULL,
    update_type VARCHAR(20) NOT NULL COMMENT 'patch, minor, major, hotfix',
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    status ENUM('pending', 'in_progress', 'success', 'failed', 'rolled_back') NOT NULL DEFAULT 'pending',
    files_updated JSON NOT NULL COMMENT 'Array of file paths that were updated',
    migrations_applied JSON NULL COMMENT 'Array of migration files that were applied',
    error_log TEXT NULL COMMENT 'Error details if update failed',
    admin_user_id INT UNSIGNED NULL COMMENT 'User who triggered the update',
    rollback_available BOOLEAN DEFAULT FALSE COMMENT 'Whether rollback is available for this update',
    execution_time INT NULL COMMENT 'Total execution time in seconds',
    backup_location VARCHAR(500) NULL COMMENT 'Path to backup directory',
    validation_checks JSON NULL COMMENT 'Results of pre-flight validation checks',
    INDEX idx_status (status),
    INDEX idx_started_at (started_at),
    INDEX idx_version_to (version_to),
    FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Logs of actual update installations and their status';

