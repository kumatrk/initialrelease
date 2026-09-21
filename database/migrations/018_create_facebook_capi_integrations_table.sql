-- Create facebook_capi_integrations table for managing multiple Facebook CAPI configurations
CREATE TABLE IF NOT EXISTS facebook_capi_integrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    pixel_id VARCHAR(50) NOT NULL,
    access_token TEXT NOT NULL,
    test_code VARCHAR(50) NULL COMMENT 'Test event code for Events Manager',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_pixel_id (pixel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add facebook_capi_integration_id to campaigns table
ALTER TABLE campaigns
ADD COLUMN facebook_capi_integration_id INT UNSIGNED NULL AFTER facebook_capi_json,
ADD FOREIGN KEY (facebook_capi_integration_id) REFERENCES facebook_capi_integrations(id) ON DELETE SET NULL,
ADD INDEX idx_facebook_capi_integration (facebook_capi_integration_id);

