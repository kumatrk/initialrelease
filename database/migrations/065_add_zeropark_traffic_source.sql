-- Preconfigure Zeropark as a traffic source with Zeropark URL macros.
-- Core tokens follow Kuma order: Target/Keyword, Cost, External ID, then platform tokens.

INSERT IGNORE INTO traffic_sources (name, tokens_json, cost_tracking_method, cost_param_key, cost_currency, created_at) VALUES
('Zeropark',
JSON_ARRAY(
    JSON_OBJECT('name', 'Target/Keyword', 'parameter', 'keyword', 'placeholder', '{keyword}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Cost', 'parameter', 'cost', 'placeholder', '{visit_cost}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'External ID', 'parameter', 'click_id', 'placeholder', '{cid}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS Campaign ID', 'parameter', 'campaign_id', 'placeholder', '{campaign_id}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS Campaign Name', 'parameter', 'campaign_name', 'placeholder', '{campaign_name}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Source', 'parameter', 'source', 'placeholder', '{source}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Target', 'parameter', 'target', 'placeholder', '{target}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Match Type', 'parameter', 'match_type', 'placeholder', '{match}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Traffic Type', 'parameter', 'traffic_type', 'placeholder', '{traffic_type}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Country', 'parameter', 'country', 'placeholder', '{geo}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Visitor Type', 'parameter', 'visitor_type', 'placeholder', '{visitor_type}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'OS', 'parameter', 'os', 'placeholder', '{os}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Device ID', 'parameter', 'device_id', 'placeholder', '{device_id}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Browser', 'parameter', 'browser', 'placeholder', '{browser}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Carrier', 'parameter', 'carrier', 'placeholder', '{carrier}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Target URL', 'parameter', 'landing_url', 'placeholder', '{target_url}', 'pass_to_lp', false, 'pass_to_offer', false)
),
'manual_token', 'cost', 'USD', NOW());
