-- Google/YouTube: wbraid/gbraid tokens, conversion delivery mode, API upload tracking

ALTER TABLE google_ads_integrations
    ADD COLUMN delivery_mode ENUM('csv', 'api', 'both') NOT NULL DEFAULT 'csv'
        COMMENT 'csv=scheduled pull, api=ConversionUploadService push, both=both'
        AFTER conversion_key,
    ADD COLUMN conversion_action_id VARCHAR(32) NULL
        COMMENT 'Google Ads conversion action ID for API uploads'
        AFTER delivery_mode;

ALTER TABLE conversions
    ADD COLUMN google_ads_upload_status VARCHAR(20) NULL
        COMMENT 'pending, queued, uploaded, failed, skipped'
        AFTER source_json,
    ADD COLUMN google_ads_upload_attempts INT UNSIGNED NOT NULL DEFAULT 0
        AFTER google_ads_upload_status,
    ADD COLUMN google_ads_upload_next_at DATETIME NULL
        AFTER google_ads_upload_attempts,
    ADD COLUMN google_ads_upload_last_error TEXT NULL
        AFTER google_ads_upload_next_at,
    ADD INDEX idx_google_ads_upload_retry (google_ads_upload_status, google_ads_upload_next_at);

-- Allow additional Google Ads channel types for cost sync
ALTER TABLE google_campaign_hourly_costs
    MODIFY COLUMN advertising_channel_type ENUM(
        'SEARCH', 'VIDEO', 'DISPLAY', 'SHOPPING', 'HOTEL', 'MULTI_CHANNEL',
        'PERFORMANCE_MAX', 'DEMAND_GEN', 'UNKNOWN'
    ) NOT NULL DEFAULT 'SEARCH';

-- Append wbraid/gbraid tokens to Google Search / Google Ads / YouTube sources when missing
UPDATE traffic_sources
SET tokens_json = JSON_ARRAY_APPEND(
    tokens_json,
    '$',
    JSON_OBJECT('name', 'WBRAID', 'parameter', 'wbraid', 'placeholder', '', 'pass_to_lp', false, 'pass_to_offer', false)
)
WHERE (LOWER(name) LIKE '%google%' OR LOWER(name) LIKE '%youtube%')
  AND LOWER(name) NOT LIKE '%facebook%'
  AND tokens_json IS NOT NULL
  AND JSON_SEARCH(tokens_json, 'one', 'wbraid', NULL, '$[*].parameter') IS NULL;

UPDATE traffic_sources
SET tokens_json = JSON_ARRAY_APPEND(
    tokens_json,
    '$',
    JSON_OBJECT('name', 'GBRAID', 'parameter', 'gbraid', 'placeholder', '', 'pass_to_lp', false, 'pass_to_offer', false)
)
WHERE (LOWER(name) LIKE '%google%' OR LOWER(name) LIKE '%youtube%')
  AND LOWER(name) NOT LIKE '%facebook%'
  AND tokens_json IS NOT NULL
  AND JSON_SEARCH(tokens_json, 'one', 'gbraid', NULL, '$[*].parameter') IS NULL;
