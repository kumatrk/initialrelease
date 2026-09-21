-- Honeycomb conversion export outbox (Whop Ads and future conversion_export addons)
CREATE TABLE IF NOT EXISTS honeycomb_conversion_exports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    addon_slug VARCHAR(64) NOT NULL,
    conversion_id BIGINT UNSIGNED NOT NULL,
    click_id VARCHAR(64) NULL,
    campaign_id INT UNSIGNED NULL,
    event_name VARCHAR(64) NOT NULL,
    event_id VARCHAR(191) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_http_status INT NULL,
    last_error VARCHAR(512) NULL,
    remote_event_id VARCHAR(191) NULL,
    delivered_at DATETIME NULL,
    next_attempt_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_honeycomb_export_event (addon_slug, event_name, event_id),
    KEY idx_honeycomb_export_conversion (conversion_id),
    KEY idx_honeycomb_export_status (status, next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
