-- Remove optional pre-seeded traffic sources that are not used by any campaign.
-- Fresh installs keep only migration 031 defaults (Google, YouTube, Bing, Facebook, PropellerAds).
-- Optional networks remain available via Traffic Sources → Create from Template.

DELETE ts FROM traffic_sources ts
LEFT JOIN campaigns c ON c.traffic_source_id = ts.id
WHERE ts.name IN (
    'TikTok',
    'Zeropark',
    'Taboola',
    'Rumble',
    'RollerAds',
    'RichAds',
    'Outbrain'
)
AND c.id IS NULL;
