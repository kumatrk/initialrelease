-- Migration 058: Optional minimum payout to fire outbound postbacks
-- NULL = no filter (default). When set, conversions are still recorded but outbound postbacks are skipped below this amount.

ALTER TABLE campaigns
ADD COLUMN min_postback_payout DECIMAL(12,6) NULL
  COMMENT 'Optional minimum conversion value/payout to fire outbound postbacks (NULL = no filter)'
AFTER facebook_capi_integration_id;
