-- Current Meta ad and ad set status for Campaign Stats pause and activate.
-- This is the object status, not a daily Insights metric.

CREATE TABLE IF NOT EXISTS meta_entity_status (
    ad_account_id INT UNSIGNED NOT NULL COMMENT 'facebook_marketing_integrations.id',
    entity_level VARCHAR(8) NOT NULL COMMENT 'ad or adset',
    entity_id BIGINT UNSIGNED NOT NULL,
    entity_name VARCHAR(512) NOT NULL DEFAULT '',
    meta_campaign_id VARCHAR(32) NOT NULL,
    effective_status VARCHAR(32) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (ad_account_id, entity_level, entity_id),
    KEY idx_meta_entity_campaign (meta_campaign_id, entity_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meta_entity_actions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    campaign_id INT UNSIGNED NOT NULL,
    entity_level VARCHAR(8) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(32) NOT NULL,
    to_status VARCHAR(32) NOT NULL,
    result VARCHAR(16) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_meta_actions_campaign (campaign_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
