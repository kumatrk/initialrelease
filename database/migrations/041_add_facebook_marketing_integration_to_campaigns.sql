-- Add facebook_marketing_integration_id to campaigns table
-- Migration 041: Facebook Marketing Integration Selection for Campaigns
-- Allows campaigns to be linked to specific Facebook Marketing API integrations (ad accounts) for accurate cost tracking

ALTER TABLE campaigns
ADD COLUMN facebook_marketing_integration_id INT UNSIGNED NULL AFTER google_ads_integration_id,
ADD FOREIGN KEY (facebook_marketing_integration_id) REFERENCES facebook_marketing_integrations(id) ON DELETE SET NULL,
ADD INDEX idx_facebook_marketing_integration (facebook_marketing_integration_id);

