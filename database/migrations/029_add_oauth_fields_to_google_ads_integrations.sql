-- Add OAuth2 and API configuration fields to google_ads_integrations table
-- These fields are required for Google Ads API cost tracking
-- BETA: Fields added but cost tracking disabled until post-beta release

ALTER TABLE google_ads_integrations
ADD COLUMN customer_id VARCHAR(50) NULL COMMENT 'Google Ads customer ID (without dashes)' AFTER conversion_key,
ADD COLUMN developer_token VARCHAR(255) NULL COMMENT 'Google Ads developer token' AFTER customer_id,
ADD COLUMN oauth_client_id VARCHAR(255) NULL COMMENT 'OAuth2 client ID' AFTER developer_token,
ADD COLUMN oauth_client_secret VARCHAR(255) NULL COMMENT 'OAuth2 client secret (encrypted)' AFTER oauth_client_id,
ADD COLUMN oauth_refresh_token TEXT NULL COMMENT 'OAuth2 refresh token' AFTER oauth_client_secret,
ADD COLUMN login_customer_id VARCHAR(50) NULL COMMENT 'MCC (Manager) customer ID (optional)' AFTER oauth_refresh_token,
ADD COLUMN status ENUM('active', 'inactive') NOT NULL DEFAULT 'active' COMMENT 'Integration status' AFTER login_customer_id,
ADD INDEX idx_customer_id (customer_id),
ADD INDEX idx_status (status);

