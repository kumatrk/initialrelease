-- Preconfigure RollerAds as a traffic source with RollerAds URL macros.
-- Core tokens follow Kuma order: Target/Keyword, Cost, External ID, then platform tokens.

INSERT IGNORE INTO traffic_sources (name, tokens_json, cost_tracking_method, cost_param_key, cost_currency, created_at) VALUES
('RollerAds',
JSON_ARRAY(
    JSON_OBJECT('name', 'Target/Keyword', 'parameter', 'zone_id', 'placeholder', '{zoneId}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Cost', 'parameter', 'cost', 'placeholder', '{cost}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'External ID', 'parameter', 'click_id', 'placeholder', '{clickId}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Creative ID', 'parameter', 'creative_id', 'placeholder', '{creativeId}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Feed ID', 'parameter', 'feed_id', 'placeholder', '{feedId}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS Campaign ID', 'parameter', 'campaign_id', 'placeholder', '{campaignId}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS Device', 'parameter', 'device', 'placeholder', '{device}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS Browser', 'parameter', 'browser', 'placeholder', '{browser}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS OS', 'parameter', 'os', 'placeholder', '{os}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS Country Code', 'parameter', 'country_code', 'placeholder', '{country}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS ISP', 'parameter', 'isp', 'placeholder', '{isp}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS Carrier', 'parameter', 'carrier', 'placeholder', '{carrier}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS Browser Version', 'parameter', 'browser_version', 'placeholder', '{browserVersion}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS OS Version', 'parameter', 'os_version', 'placeholder', '{osVersion}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS Country Name', 'parameter', 'country_name', 'placeholder', '{countryName}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS Connection Type', 'parameter', 'connection_type', 'placeholder', '{connectionType}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS User Agent', 'parameter', 'user_agent', 'placeholder', '{UserAgent}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS Age Group', 'parameter', 'age_group', 'placeholder', '{ageGroup}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS Format', 'parameter', 'format', 'placeholder', '{format}', 'pass_to_lp', false, 'pass_to_offer', false)
),
'manual_token', 'cost', 'USD', NOW());
