-- Migration 063: Per-user saved breakdown views for Campaign Stats V2
CREATE TABLE IF NOT EXISTS campaign_stats_saved_views (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    campaign_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    config JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_campaign_view_name (user_id, campaign_id, name),
    INDEX idx_user_campaign (user_id, campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
