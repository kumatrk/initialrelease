-- Track Google Ads API requests (cost sync SearchStream, conversion uploads, etc.)
CREATE TABLE IF NOT EXISTS google_ads_api_calls (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    endpoint VARCHAR(255) NOT NULL COMMENT 'API operation (e.g., searchStream, uploadClickConversions)',
    method VARCHAR(10) NOT NULL DEFAULT 'POST' COMMENT 'Logical method / RPC name',
    customer_id VARCHAR(50) NULL COMMENT 'Google Ads customer ID if applicable',
    integration_id INT UNSIGNED NULL COMMENT 'google_ads_integrations.id',
    response_code INT NULL COMMENT 'HTTP/status code when known',
    success TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Whether the call completed successfully',
    called_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the API call was made',
    INDEX idx_called_at (called_at),
    INDEX idx_integration_id (integration_id),
    INDEX idx_customer_id (customer_id),
    INDEX idx_success (success)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
