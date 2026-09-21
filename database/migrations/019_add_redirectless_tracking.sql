-- Add redirectless tracking support to campaigns
ALTER TABLE campaigns
ADD COLUMN redirectless_tracking BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Enable redirectless tracking for direct landing page traffic' AFTER cloaking_mode;

