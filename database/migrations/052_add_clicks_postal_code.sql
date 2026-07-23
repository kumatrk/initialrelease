-- Migration 052: Add postal/zip code column to clicks
-- This enables Meta CAPI `zp` (zip code) matching for improved Event Match Quality.

ALTER TABLE clicks
ADD COLUMN postal VARCHAR(20) NULL COMMENT 'Postal/ZIP code (raw, un-hashed)' AFTER city;

