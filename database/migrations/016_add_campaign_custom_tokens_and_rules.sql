-- Add custom tokens and redirect rules support to campaigns table
ALTER TABLE campaigns 
ADD COLUMN custom_tokens_json JSON NULL COMMENT 'Campaign-specific custom tokens (up to 10)' AFTER facebook_capi_json,
ADD COLUMN redirect_rules_json JSON NULL COMMENT 'Rule-based redirects based on custom token values' AFTER custom_tokens_json;

