-- Add campaign group support
ALTER TABLE campaigns 
ADD COLUMN campaign_group VARCHAR(100) NULL AFTER name,
ADD INDEX idx_campaign_group (campaign_group);


