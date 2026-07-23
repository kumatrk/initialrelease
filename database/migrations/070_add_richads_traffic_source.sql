-- Preconfigure RichAds as a traffic source with RichAds URL macros.
-- Core tokens follow Kuma order: Target/Keyword, Cost, External ID, then platform tokens.

INSERT IGNORE INTO traffic_sources (name, tokens_json, cost_tracking_method, cost_param_key, cost_currency, created_at) VALUES
('RichAds',
JSON_ARRAY(
    JSON_OBJECT('name', 'Target/Keyword', 'parameter', 'site_id', 'placeholder', '[SITE_ID]', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Cost', 'parameter', 'cost', 'placeholder', '[BID_PRICE]', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'External ID', 'parameter', 'click_id', 'placeholder', '[CLICK_ID]', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Creative ID', 'parameter', 'creative_id', 'placeholder', '[CREATIVE_ID]', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Subscriber List ID', 'parameter', 'sublist_id', 'placeholder', '[SUB_LIST_ID]', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Publisher ID', 'parameter', 'publisher_id', 'placeholder', '[PUBLISHER_ID]', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS Campaign ID', 'parameter', 'campaign_id', 'placeholder', '[CAMPAIGN_ID]', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS Campaign Name', 'parameter', 'campaign_name', 'placeholder', '[CAMPAIGN_NAME]', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'OS', 'parameter', 'os', 'placeholder', '[OS]', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Country', 'parameter', 'country', 'placeholder', '[COUNTRY]', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Region', 'parameter', 'region', 'placeholder', '[REGION]', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'City', 'parameter', 'city', 'placeholder', '[CITY]', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'IP', 'parameter', 'ip', 'placeholder', '[IP]', 'pass_to_lp', false, 'pass_to_offer', false)
),
'manual_token', 'cost', 'USD', NOW());
