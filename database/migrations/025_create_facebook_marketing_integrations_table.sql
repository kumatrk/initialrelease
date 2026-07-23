-- Create facebook_marketing_integrations table for storing multiple Facebook Marketing API access tokens
-- Each integration represents one ad account

CREATE TABLE IF NOT EXISTS facebook_marketing_integrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL COMMENT 'Friendly name for this integration (e.g., "Main Account", "Client A")',
    access_token TEXT NOT NULL COMMENT 'Facebook Marketing API access token',
    ad_account_id VARCHAR(50) DEFAULT NULL COMMENT 'Optional: Facebook Ad Account ID for reference',
    status ENUM('active', 'paused') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_ad_account_id (ad_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Stores multiple Facebook Marketing API integrations, one per ad account';


