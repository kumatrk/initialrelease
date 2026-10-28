-- Deep Funnels: path/step daily aggregates (summary-first Funnel tab).
-- flow_type remains VARCHAR; DeepFunnel is a new allowed value in app validation only.
-- Use 0 (not NULL) for unused landing_page_id / offer_id so UNIQUE upserts work in MySQL.

CREATE TABLE IF NOT EXISTS clicks_funnel_step_daily (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    campaign_id INT UNSIGNED NOT NULL,
    summary_date DATE NOT NULL,
    level TINYINT UNSIGNED NOT NULL COMMENT '1-5 LP level; 0 = offer exit',
    slot TINYINT UNSIGNED NOT NULL COMMENT '1-5 within level or offer slot',
    landing_page_id INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 when N/A (offer exit)',
    offer_id INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 when N/A (LP step)',
    entries INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Reached this node',
    continues INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Left this node to a deeper hop or offer',
    offer_clicks INT UNSIGNED NOT NULL DEFAULT 0,
    conversions INT UNSIGNED NOT NULL DEFAULT 0,
    revenue DECIMAL(12,4) NOT NULL DEFAULT 0,
    cost DECIMAL(12,4) NOT NULL DEFAULT 0 COMMENT 'Only counted on level-1 entry',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_funnel_step_daily (campaign_id, summary_date, level, slot, landing_page_id, offer_id),
    KEY idx_funnel_step_campaign_date (campaign_id, summary_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clicks_funnel_path_daily (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    campaign_id INT UNSIGNED NOT NULL,
    summary_date DATE NOT NULL,
    path_key VARCHAR(191) NOT NULL COMMENT 'e.g. 1:3>2:4>o:1',
    visitors INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Current path occupancy (moved as path grows)',
    offer_clicks INT UNSIGNED NOT NULL DEFAULT 0,
    conversions INT UNSIGNED NOT NULL DEFAULT 0,
    revenue DECIMAL(12,4) NOT NULL DEFAULT 0,
    cost DECIMAL(12,4) NOT NULL DEFAULT 0 COMMENT 'Attributed once at L1',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_funnel_path_daily (campaign_id, summary_date, path_key),
    KEY idx_funnel_path_campaign_date (campaign_id, summary_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
