-- Daily Meta delivery for mapped ads and ad sets.
-- Written by the cost updater from the Insights response. Campaign Stats reads it
-- only when a Meta column is turned on. Reach is one day's estimate and is not a sum.

CREATE TABLE IF NOT EXISTS meta_delivery_daily (
    ad_account_id INT UNSIGNED NOT NULL COMMENT 'facebook_marketing_integrations.id',
    entity_level VARCHAR(8) NOT NULL COMMENT 'ad or adset',
    entity_id BIGINT UNSIGNED NOT NULL COMMENT 'Meta ad or ad set id',
    summary_date DATE NOT NULL COMMENT 'Calendar date sent to Insights',
    entity_name VARCHAR(512) NOT NULL DEFAULT '' COMMENT 'Name returned with that Insights row',
    meta_campaign_id VARCHAR(32) NOT NULL COMMENT 'Meta campaign id',
    impressions BIGINT UNSIGNED NOT NULL DEFAULT 0,
    reach BIGINT UNSIGNED NULL COMMENT 'One day only. Not summed across days.',
    clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
    spend DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (ad_account_id, entity_level, entity_id, summary_date),
    KEY idx_meta_delivery_lookup (meta_campaign_id, entity_level, summary_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Daily Meta impressions, reach, clicks, and spend for optional Campaign Stats columns';
