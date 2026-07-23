-- Preconfigure Rumble as a traffic source with Rumble URL macros.
-- No cost token: Rumble uses API cost updates only (integrated_api).
-- Core tokens: Target/Keyword, External ID, then platform tokens.

INSERT IGNORE INTO traffic_sources (name, tokens_json, cost_tracking_method, cost_param_key, cost_currency, created_at) VALUES
('Rumble',
JSON_ARRAY(
    JSON_OBJECT('name', 'Target/Keyword', 'parameter', 'site_domain', 'placeholder', '{site.domain}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'External ID', 'parameter', 'click_id', 'placeholder', '{click}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Creative', 'parameter', 'creative', 'placeholder', '{creative}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Site ID', 'parameter', 'site_id', 'placeholder', '{site}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Ad Zone', 'parameter', 'ad_zone', 'placeholder', '{adzone}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS Campaign ID', 'parameter', 'campaign_id', 'placeholder', '{campaign}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Lander ID', 'parameter', 'lander_id', 'placeholder', '{lander}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS Country ID', 'parameter', 'country_id', 'placeholder', '{country.id}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS Country Name', 'parameter', 'country_name', 'placeholder', '{country}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS Region ID', 'parameter', 'region_id', 'placeholder', '{region}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS City ID', 'parameter', 'city_id', 'placeholder', '{city}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS City Name', 'parameter', 'city_name', 'placeholder', '{city.name}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS Carrier ID', 'parameter', 'carrier_id', 'placeholder', '{carrier}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS Device ID', 'parameter', 'device_id', 'placeholder', '{device}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS Device Type', 'parameter', 'device_type', 'placeholder', '{device.type}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS OS ID', 'parameter', 'os_id', 'placeholder', '{os}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS OS Name', 'parameter', 'os_name', 'placeholder', '{os.name}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS OS Version', 'parameter', 'os_version', 'placeholder', '{os.version}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS Language ID', 'parameter', 'language_id', 'placeholder', '{language}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Keywords', 'parameter', 'keywords', 'placeholder', '{keywords}', 'pass_to_lp', false, 'pass_to_offer', false)
),
'integrated_api', '', 'USD', NOW());
