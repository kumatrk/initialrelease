-- Add event_type column to facebook_capi_integrations table
ALTER TABLE facebook_capi_integrations
ADD COLUMN event_type VARCHAR(50) NOT NULL DEFAULT 'Purchase' 
    COMMENT 'Facebook event type (Purchase, Lead, etc. or custom event name)' 
    AFTER test_code;

