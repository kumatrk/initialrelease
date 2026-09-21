-- Preconfigure popular traffic sources with their tokens
-- This migration inserts Google Search, YouTube, Bing, Facebook, PropellerAds, and TikTok with preconfigured tokens

INSERT IGNORE INTO traffic_sources (name, tokens_json, cost_tracking_method, cost_param_key, cost_currency, created_at) VALUES

-- Google Search (Google Ads)
('Google Search', 
JSON_ARRAY(
    JSON_OBJECT('name', 'Target/Keyword', 'parameter', 'keyword', 'placeholder', '{keyword}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Cost', 'parameter', 'cost', 'placeholder', '', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'External ID', 'parameter', 'gclid', 'placeholder', '', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Ad / Creative', 'parameter', 'ad', 'placeholder', '{creative}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Ad Group ID', 'parameter', 'adgroup', 'placeholder', '{AdGroupId}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Campaign ID', 'parameter', 'campid', 'placeholder', '{CampaignId}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Network', 'parameter', 'network', 'placeholder', '{network}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Device', 'parameter', 'device', 'placeholder', '{Device}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Interest Location', 'parameter', 'locinter', 'placeholder', '{loc_interest_ms}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Physical Location', 'parameter', 'locphys', 'placeholder', '{loc_physical_ms}', 'pass_to_lp', false, 'pass_to_offer', false)
),
'integrated_api', '', 'USD', NOW()),

-- YouTube
('YouTube',
JSON_ARRAY(
    JSON_OBJECT('name', 'Target/Keyword', 'parameter', 'placement', 'placeholder', '{placement}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Cost', 'parameter', 'cost', 'placeholder', '', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'External ID', 'parameter', 'gclid', 'placeholder', '', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Ad / Creative', 'parameter', 'ad', 'placeholder', '{creative}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Ad Group ID', 'parameter', 'adgroup', 'placeholder', '{AdGroupId}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Campaign ID', 'parameter', 'campid', 'placeholder', '{CampaignId}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Network', 'parameter', 'network', 'placeholder', '{network}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Device', 'parameter', 'device', 'placeholder', '{Device}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Interest Location', 'parameter', 'locinter', 'placeholder', '{loc_interest_ms}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Physical Location', 'parameter', 'locphys', 'placeholder', '{loc_physical_ms}', 'pass_to_lp', false, 'pass_to_offer', false)
),
'integrated_api', '', 'USD', NOW()),

-- Bing Ads
('Bing Ads',
JSON_ARRAY(
    JSON_OBJECT('name', 'Target/Keyword', 'parameter', 'keyword', 'placeholder', '{keyword}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Cost', 'parameter', 'cost', 'placeholder', '', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'External ID', 'parameter', 'msclkid', 'placeholder', '', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Ad ID', 'parameter', 'ad', 'placeholder', '{AdId}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Ad Group ID', 'parameter', 'adgroup', 'placeholder', '{AdGroupId}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Campaign ID', 'parameter', 'campid', 'placeholder', '{CampaignId}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Match Type', 'parameter', 'match', 'placeholder', '{MatchType}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Order Item ID', 'parameter', 'itemid', 'placeholder', '{OrderItemId}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Product ID', 'parameter', 'prodid', 'placeholder', '{ProductId}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Target ID', 'parameter', 'targetid', 'placeholder', '{TargetId}', 'pass_to_lp', false, 'pass_to_offer', false)
),
'manual_token', 'cost', 'USD', NOW()),

-- Facebook
('Facebook',
JSON_ARRAY(
    JSON_OBJECT('name', 'Target/Keyword', 'parameter', 'source', 'placeholder', '{{site_source_name}}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Cost', 'parameter', 'cost', 'placeholder', '', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'External ID', 'parameter', 'fbclid', 'placeholder', '', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Ad ID', 'parameter', 'ad_id', 'placeholder', '{{ad.id}}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Ad Set ID', 'parameter', 'adset_id', 'placeholder', '{{adset.id}}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Campaign ID', 'parameter', 'campaign_id', 'placeholder', '{{campaign.id}}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Ad Name', 'parameter', 'ad_name', 'placeholder', '{{ad.name}}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Ad Set Name', 'parameter', 'adset_name', 'placeholder', '{{adset.name}}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Campaign Name', 'parameter', 'campaign_name', 'placeholder', '{{campaign.name}}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Placement', 'parameter', 'placement', 'placeholder', '{{placement}}', 'pass_to_lp', false, 'pass_to_offer', false)
),
'integrated_api', '', 'USD', NOW()),

-- PropellerAds (based on screenshot - 19 tokens total)
('PropellerAds',
JSON_ARRAY(
    JSON_OBJECT('name', 'Keyword Token', 'parameter', 'zoneid', 'placeholder', '{zoneid}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Cost Token', 'parameter', 'cost', 'placeholder', '{cost}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'External ID Token', 'parameter', 'subid', 'placeholder', '${SUBID}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Ad Token', 'parameter', 'bann', 'placeholder', '{bannerid}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Device Name', 'parameter', 'dev', 'placeholder', '{device}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Campaign ID', 'parameter', 'campid', 'placeholder', '{campaignid}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Browser', 'parameter', 'brw', 'placeholder', '{browser}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Browser Version', 'parameter', 'brwv', 'placeholder', '{browserversion}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'OS', 'parameter', 'os', 'placeholder', '{os}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'OS Version', 'parameter', 'osv', 'placeholder', '{osversion}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Country Code', 'parameter', 'ctry', 'placeholder', '{country}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Country Name', 'parameter', 'ctryname', 'placeholder', '{countryname}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Language', 'parameter', 'lang', 'placeholder', '{language}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'ISP', 'parameter', 'isp', 'placeholder', '{isp}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Carrier', 'parameter', 'carrier', 'placeholder', '{carrier}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Region', 'parameter', 'regi', 'placeholder', '{region}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Connection Type', 'parameter', 'conn', 'placeholder', '{connection.type}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'User Activity', 'parameter', 'acti', 'placeholder', '{user_activity}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Zone Type', 'parameter', 'ztp', 'placeholder', '{zone_type}', 'pass_to_lp', false, 'pass_to_offer', false)
),
'manual_token', 'cost', 'USD', NOW());


