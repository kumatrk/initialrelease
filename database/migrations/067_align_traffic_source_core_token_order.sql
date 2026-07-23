-- Align TikTok, Zeropark, and Taboola token order with bundled sources:
-- 1) Target/Keyword  2) Cost (parameter: cost)  3) External ID, then platform-specific tokens.

UPDATE traffic_sources
SET tokens_json = JSON_ARRAY(
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
cost_param_key = 'cost'
WHERE name = 'TikTok';

UPDATE traffic_sources
SET tokens_json = JSON_ARRAY(
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
cost_param_key = 'cost'
WHERE name = 'Zeropark';

UPDATE traffic_sources
SET tokens_json = JSON_ARRAY(
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
cost_param_key = 'cost'
WHERE name = 'Taboola';
