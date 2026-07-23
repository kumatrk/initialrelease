-- Migration 036: Add Traffic Source Postbacks JSON for Auto-Detect Campaigns
-- This migration enables campaigns in auto-detect mode to configure postbacks/pixels per traffic source

-- Add traffic_source_postbacks_json column to campaigns table
ALTER TABLE campaigns 
ADD COLUMN traffic_source_postbacks_json JSON NULL COMMENT 'Per-traffic-source postback/pixel configs for auto-detect campaigns. Format: {"traffic_source_id": {"facebook_capi_integration_id": X, "google_ads_integration_id": Y, "custom_postback_ids": [1,2,3]}}' 
AFTER google_ads_integration_id;

