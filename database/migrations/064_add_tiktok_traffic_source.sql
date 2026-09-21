-- Preconfigure TikTok as a traffic source with TikTok Ads URL macros.
-- Core tokens follow Kuma order: Target/Keyword, Cost, External ID, then platform tokens.

INSERT IGNORE INTO traffic_sources (name, tokens_json, cost_tracking_method, cost_param_key, cost_currency, created_at) VALUES
('TikTok',
JSON_ARRAY(
    JSON_OBJECT('name', 'Target/Keyword', 'parameter', 'placement', 'placeholder', '__PLACEMENT__', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Cost', 'parameter', 'cost', 'placeholder', '', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'External ID', 'parameter', 'ttclid', 'placeholder', '__CLICKID__', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS Campaign ID', 'parameter', 'campaign_id', 'placeholder', '__CAMPAIGN_ID__', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS Campaign Name', 'parameter', 'campaign_name', 'placeholder', '__CAMPAIGN_NAME__', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Ad Group', 'parameter', 'adset', 'placeholder', '__AID__', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Ad Group Name', 'parameter', 'adset_name', 'placeholder', '__AID_NAME__', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Ad Creative ID', 'parameter', 'creative', 'placeholder', '__CID__', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Ad Name', 'parameter', 'creative_name', 'placeholder', '__CID_NAME__', 'pass_to_lp', false, 'pass_to_offer', false)
),
'manual_token', 'cost', 'USD', NOW());
