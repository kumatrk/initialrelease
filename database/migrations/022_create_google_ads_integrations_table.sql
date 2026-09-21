-- Create google_ads_integrations table for managing multiple Google Ads integration configurations
CREATE TABLE IF NOT EXISTS google_ads_integrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    conversion_key VARCHAR(100) NOT NULL COMMENT 'Random key for API authentication',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_conversion_key (conversion_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add google_ads_integration_id to campaigns table
ALTER TABLE campaigns
ADD COLUMN google_ads_integration_id INT UNSIGNED NULL AFTER facebook_capi_integration_id,
ADD FOREIGN KEY (google_ads_integration_id) REFERENCES google_ads_integrations(id) ON DELETE SET NULL,
ADD INDEX idx_google_ads_integration (google_ads_integration_id);

