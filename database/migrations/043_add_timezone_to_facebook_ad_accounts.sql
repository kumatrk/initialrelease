-- Migration 043: Add timezone field to facebook_marketing_ad_accounts table
-- Facebook API uses the ad account's timezone to interpret dates in insights queries
-- This field stores the timezone (e.g., 'America/Chicago' for Central Time)

ALTER TABLE facebook_marketing_ad_accounts
ADD COLUMN timezone VARCHAR(50) NULL DEFAULT NULL 
    COMMENT 'Ad account timezone (e.g., America/Chicago). Used for Facebook API date queries. If NULL, defaults to UTC.'
    AFTER business_name;



