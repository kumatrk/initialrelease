-- Migrate existing campaign_group text values to campaign_groups table
-- Step 1: Insert unique campaign_group values into campaign_groups (normalize case and trim)
INSERT INTO campaign_groups (name)
SELECT DISTINCT TRIM(campaign_group) as name
FROM campaigns
WHERE campaign_group IS NOT NULL 
  AND campaign_group != ''
  AND TRIM(campaign_group) != ''
GROUP BY TRIM(campaign_group)
ON DUPLICATE KEY UPDATE name=name;

-- Step 2: Update campaigns to reference campaign_group_id based on matching names
UPDATE campaigns c
INNER JOIN campaign_groups cg ON TRIM(c.campaign_group) = cg.name
SET c.campaign_group_id = cg.id
WHERE c.campaign_group IS NOT NULL 
  AND c.campaign_group != ''
  AND TRIM(c.campaign_group) != '';

-- Step 3: Remove the old campaign_group text column after migration is verified
-- NOTE: This is commented out to allow manual verification before dropping the column
-- ALTER TABLE campaigns DROP COLUMN campaign_group;

