-- Migration 046: Create postback_logs table for tracking postback attempts
-- This table stores all postback attempts (traffic sources, Facebook CAPI, custom postbacks)

CREATE TABLE IF NOT EXISTS postback_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    postback_type VARCHAR(50) NOT NULL COMMENT 'Type of postback: traffic_source, facebook_capi, custom_postback',
    conversion_id BIGINT UNSIGNED NULL COMMENT 'Conversion ID that triggered this postback',
    click_id VARCHAR(255) NULL COMMENT 'Click ID associated with the conversion',
    url TEXT NOT NULL COMMENT 'Full URL that was called',
    http_method VARCHAR(10) NOT NULL DEFAULT 'GET' COMMENT 'HTTP method used (GET, POST)',
    http_code INT NULL COMMENT 'HTTP response code received',
    success TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether the postback was successful (HTTP 200-299)',
    attempt_number TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Attempt number (1, 2, 3 for retries)',
    error_message TEXT NULL COMMENT 'Error message if postback failed',
    response_body TEXT NULL COMMENT 'Response body from the server (truncated if too long)',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the postback attempt was made',
    INDEX idx_conversion_id (conversion_id),
    INDEX idx_click_id (click_id),
    INDEX idx_postback_type (postback_type),
    INDEX idx_success (success),
    INDEX idx_created_at (created_at),
    INDEX idx_type_success (postback_type, success),
    FOREIGN KEY (conversion_id) REFERENCES conversions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

