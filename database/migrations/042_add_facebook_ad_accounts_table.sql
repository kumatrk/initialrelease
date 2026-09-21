-- Migration 042: Add Facebook Marketing Ad Accounts table and link campaigns to ad accounts
-- This allows storing all accessible ad accounts per integration and linking campaigns directly to ad accounts

-- Create table to store ad accounts accessible via each integration
CREATE TABLE IF NOT EXISTS facebook_marketing_ad_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    facebook_marketing_integration_id INT UNSIGNED NOT NULL,
    ad_account_id VARCHAR(50) NOT NULL COMMENT 'Facebook ad account ID (e.g., act_123456789)',
    ad_account_name VARCHAR(255) NOT NULL COMMENT 'Display name of the ad account',
    account_id VARCHAR(50) NULL COMMENT 'Numeric account ID',
    currency VARCHAR(10) NULL COMMENT 'Account currency code',
    business_id VARCHAR(50) NULL COMMENT 'Business Manager ID if available',
    business_name VARCHAR(255) NULL COMMENT 'Business Manager name if available',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_integration (facebook_marketing_integration_id),
    INDEX idx_ad_account_id (ad_account_id),
    UNIQUE KEY unique_integration_account (facebook_marketing_integration_id, ad_account_id),
    FOREIGN KEY (facebook_marketing_integration_id) 
        REFERENCES facebook_marketing_integrations(id) 
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add facebook_marketing_ad_account_id to campaigns table
-- This links campaigns directly to specific ad accounts for cost tracking
ALTER TABLE campaigns
ADD COLUMN facebook_marketing_ad_account_id INT UNSIGNED NULL AFTER facebook_marketing_integration_id,
ADD INDEX idx_facebook_marketing_ad_account (facebook_marketing_ad_account_id),
ADD CONSTRAINT fk_campaigns_facebook_marketing_ad_account
    FOREIGN KEY (facebook_marketing_ad_account_id)
    REFERENCES facebook_marketing_ad_accounts(id)
    ON DELETE SET NULL;

