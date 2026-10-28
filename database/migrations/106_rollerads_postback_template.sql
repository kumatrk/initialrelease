-- RollerAds conversion postback. Cost stays the click URL parameter.
-- The account number and tracking key come from RollerAds → Campaigns → Tracking.
-- Kuma does not send this URL until those two placeholders are replaced.

UPDATE traffic_sources
SET postback_template = 'https://trk.roller.ad/conversion/aid/YOUR_ACCOUNT_NUMBER/YOUR_TRACKING_KEY?click_id={ts:RollerAds:click_id}&payout={payout}&goal=1'
WHERE name = 'RollerAds'
  AND (postback_template IS NULL OR postback_template = '');

UPDATE traffic_sources
SET provider_key = 'rollerads'
WHERE name = 'RollerAds'
  AND (provider_key IS NULL OR provider_key = '');
