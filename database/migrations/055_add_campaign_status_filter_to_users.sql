-- Migration 055: Add user preference for campaign status filter (active/paused/archived)
-- Stored as JSON array e.g. ["active","paused"]. NULL or all three = show all.
ALTER TABLE users
ADD COLUMN campaign_status_filter VARCHAR(255) NULL
    COMMENT 'JSON array of allowed campaign statuses. NULL = show all'
    AFTER currency;
