-- Add purpose column so cost-sync diagnostics can explain why each Graph call was made
ALTER TABLE facebook_api_calls
    ADD COLUMN purpose VARCHAR(64) NULL DEFAULT NULL
        COMMENT 'Why this call was made (account_insights, adset_fallback, etc.)'
        AFTER success;

ALTER TABLE facebook_api_calls
    ADD INDEX idx_purpose (purpose);

ALTER TABLE facebook_api_calls
    ADD INDEX idx_called_at_purpose (called_at, purpose);
