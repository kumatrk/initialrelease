-- Create facebook_api_calls table to track API usage
CREATE TABLE IF NOT EXISTS facebook_api_calls (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    endpoint VARCHAR(255) NOT NULL COMMENT 'API endpoint called (e.g., /insights)',
    method VARCHAR(10) NOT NULL DEFAULT 'GET' COMMENT 'HTTP method',
    ad_account_id VARCHAR(50) NULL COMMENT 'Ad account ID if applicable',
    integration_id INT UNSIGNED NULL COMMENT 'Facebook marketing integration ID',
    response_code INT NULL COMMENT 'HTTP response code',
    success TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Whether the call was successful',
    called_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the API call was made',
    INDEX idx_called_at (called_at),
    INDEX idx_integration_id (integration_id),
    INDEX idx_ad_account_id (ad_account_id),
    INDEX idx_success (success)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

