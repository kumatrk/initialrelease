-- Preconfigure Taboola as a traffic source with Taboola URL macros.
-- Core tokens follow Kuma order: Target/Keyword, Cost, External ID, then platform tokens.

INSERT IGNORE INTO traffic_sources (name, tokens_json, cost_tracking_method, cost_param_key, cost_currency, created_at) VALUES
('Taboola',
JSON_ARRAY(
    JSON_OBJECT('name', 'Target/Keyword', 'parameter', 'publisher_site', 'placeholder', '{site}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Cost', 'parameter', 'cost', 'placeholder', '{cpc}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'External ID', 'parameter', 'click_id', 'placeholder', '{click_id}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Creative', 'parameter', 'creative_name', 'placeholder', '{creative_name}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Creative ID', 'parameter', 'custom_id', 'placeholder', '{custom_id}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Title', 'parameter', 'title', 'placeholder', '{title}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Thumbnail', 'parameter', 'thumbnail', 'placeholder', '{thumbnail}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Platform', 'parameter', 'platform', 'placeholder', '{platform}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS Campaign ID', 'parameter', 'campaign_id', 'placeholder', '{campaign_id}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Campaign Item', 'parameter', 'campaign_item', 'placeholder', '{campaign_item_id}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Publisher ID', 'parameter', 'site_id', 'placeholder', '{site_id}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Site Domain', 'parameter', 'site_domain', 'placeholder', '{site_domain}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS Campaign Name', 'parameter', 'campaign_name', 'placeholder', '{campaign_name}', 'pass_to_lp', false, 'pass_to_offer', false)
),
'manual_token', 'cost', 'USD', NOW());
