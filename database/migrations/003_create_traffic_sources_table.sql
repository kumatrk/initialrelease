-- Create traffic_sources table
CREATE TABLE IF NOT EXISTS traffic_sources (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    tokens_json JSON NULL COMMENT 'Supported tokens and custom params',
    postback_template TEXT NULL COMMENT 'Default outbound postback template',
    cost_param_key VARCHAR(50) NULL COMMENT 'Parameter key for cost (e.g., cost, cpc, price)',
    cost_currency CHAR(3) NULL COMMENT 'Currency code for cost parameter',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


