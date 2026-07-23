-- Migration 033: Add Auto-Detect Traffic Source Support
-- This migration enables campaigns to use multiple traffic sources via auto-detection
-- and tracks the actual traffic source used for each click.

-- Step 1: Modify campaigns table to allow NULL traffic_source_id for auto-detect mode
ALTER TABLE campaigns 
MODIFY COLUMN traffic_source_id INT UNSIGNED NULL COMMENT 'NULL = auto-detect mode, allows multiple traffic sources';

-- Step 2: Add traffic_source_id column to clicks table
-- Note: If column already exists, this will fail with error 1060, which we'll handle gracefully
ALTER TABLE clicks 
ADD COLUMN traffic_source_id INT UNSIGNED NULL COMMENT 'Detected traffic source ID from Tf parameter or campaign default' AFTER campaign_id;

-- Step 3: Add index for performance
-- Note: If index already exists, this will fail with error 1061, which we'll handle gracefully
CREATE INDEX idx_clicks_traffic_source ON clicks(traffic_source_id);

-- Step 4: Add foreign key constraint
-- Note: If constraint already exists, this will fail, which we'll handle gracefully
ALTER TABLE clicks 
ADD CONSTRAINT fk_clicks_traffic_source 
FOREIGN KEY (traffic_source_id) REFERENCES traffic_sources(id) ON DELETE SET NULL;

-- Step 5: Migrate existing data - set clicks.traffic_source_id from campaign's traffic_source_id
UPDATE clicks c
INNER JOIN campaigns camp ON c.campaign_id = camp.id
SET c.traffic_source_id = camp.traffic_source_id
WHERE c.traffic_source_id IS NULL AND camp.traffic_source_id IS NOT NULL;
