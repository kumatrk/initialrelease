-- Add unique campaign_key column for secure tracking links
ALTER TABLE campaigns 
ADD COLUMN campaign_key VARCHAR(20) UNIQUE NULL AFTER id,
ADD INDEX idx_campaign_key (campaign_key);

-- Generate unique keys for existing campaigns (if any)
-- This uses a combination of ID and random string for uniqueness


