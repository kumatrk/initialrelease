-- Add cost_tracking_method to traffic_sources table
ALTER TABLE traffic_sources
ADD COLUMN cost_tracking_method ENUM('manual_token', 'integrated_api') NOT NULL DEFAULT 'manual_token'
    COMMENT 'Cost tracking method: manual_token (URL parameter) or integrated_api (API-based, future)'
    AFTER cost_currency;

-- Update existing records to maintain current behavior
-- If cost_param_key exists, it's manual_token, otherwise default
UPDATE traffic_sources 
SET cost_tracking_method = IF(cost_param_key IS NOT NULL AND cost_param_key != '', 'manual_token', 'manual_token');

