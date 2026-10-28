-- NewsBreak conversion postback + provider key.
-- Cost sync uses Settings → API Cost Updates (newsbreak_integrations).
-- event_type=complete_payment must match Tools → Event Management (edit the URL if needed).

UPDATE traffic_sources
SET postback_template = 'https://business.newsbreak.com/tracking/attribute?callback={ts:NewsBreak:click_id}&event_type=complete_payment&nb_value={payout}'
WHERE (name = 'NewsBreak' OR LOWER(name) LIKE '%newsbreak%')
  AND (postback_template IS NULL OR postback_template = '');

UPDATE traffic_sources
SET provider_key = 'newsbreak',
    cost_tracking_method = 'integrated_api'
WHERE (name = 'NewsBreak' OR LOWER(name) LIKE '%newsbreak%')
  AND (provider_key IS NULL OR provider_key = '');
