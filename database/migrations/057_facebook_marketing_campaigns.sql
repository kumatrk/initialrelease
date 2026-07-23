-- Migration 057: Meta campaign cache, adset-to-campaign map, link Kuma campaigns to Meta campaigns

CREATE TABLE IF NOT EXISTS facebook_marketing_campaigns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    facebook_marketing_ad_account_id INT UNSIGNED NOT NULL,
    meta_campaign_id VARCHAR(32) NOT NULL COMMENT 'Facebook campaign ID',
    campaign_name VARCHAR(512) NOT NULL DEFAULT '',
    effective_status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',
    synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_ad_account_meta_campaign (facebook_marketing_ad_account_id, meta_campaign_id),
    INDEX idx_ad_account_status (facebook_marketing_ad_account_id, effective_status),
    FOREIGN KEY (facebook_marketing_ad_account_id)
        REFERENCES facebook_marketing_ad_accounts(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS facebook_adset_campaign_map (
    adset_id BIGINT UNSIGNED NOT NULL,
    meta_campaign_id VARCHAR(32) NOT NULL,
    facebook_marketing_ad_account_id INT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (adset_id, facebook_marketing_ad_account_id),
    INDEX idx_meta_campaign (meta_campaign_id, facebook_marketing_ad_account_id),
    FOREIGN KEY (facebook_marketing_ad_account_id)
        REFERENCES facebook_marketing_ad_accounts(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE campaigns
ADD COLUMN facebook_marketing_campaign_id INT UNSIGNED NULL AFTER facebook_marketing_ad_account_id,
ADD INDEX idx_facebook_marketing_campaign (facebook_marketing_campaign_id),
ADD CONSTRAINT fk_campaigns_facebook_marketing_campaign
    FOREIGN KEY (facebook_marketing_campaign_id)
    REFERENCES facebook_marketing_campaigns(id)
    ON DELETE SET NULL;
