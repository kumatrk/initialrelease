-- Preconfigure Outbrain as a traffic source with Outbrain URL macros.
-- No cost token: Outbrain uses API cost updates only (integrated_api).
-- Core tokens: Target/Keyword, External ID, then platform tokens.

INSERT IGNORE INTO traffic_sources (name, tokens_json, cost_tracking_method, cost_param_key, cost_currency, created_at) VALUES
('Outbrain',
JSON_ARRAY(
    JSON_OBJECT('name', 'Target/Keyword', 'parameter', 'publisher_name', 'placeholder', '{{publisher_name}}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'External ID', 'parameter', 'click_id', 'placeholder', '{{ob_click_id}}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Ad ID', 'parameter', 'ad_id', 'placeholder', '{{ad_id}}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Document Title', 'parameter', 'doc_title', 'placeholder', '{{doc_title}}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Document Author', 'parameter', 'doc_author', 'placeholder', '{{doc_author}}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Ad Title', 'parameter', 'ad_title', 'placeholder', '{{ad_title}}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Source ID', 'parameter', 'source_id', 'placeholder', '{{source_id}}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Promoted Link ID', 'parameter', 'promoted_link_id', 'placeholder', '{{promoted_link_id}}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'TS Campaign ID', 'parameter', 'campaign_id', 'placeholder', '{{campaign_id}}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Section Name', 'parameter', 'section_name', 'placeholder', '{{section_name}}', 'pass_to_lp', false, 'pass_to_offer', false),
    JSON_OBJECT('name', 'Publisher ID', 'parameter', 'publisher_id', 'placeholder', '{{publisher_id}}', 'pass_to_lp', false, 'pass_to_offer', false)
),
'integrated_api', '', 'USD', NOW());
