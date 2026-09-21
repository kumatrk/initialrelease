-- Migration 040: Add Multi-Slug Support for Campaigns
-- Creates campaign_slugs table and adds slug_id to clicks table for attribution

-- Create campaign_slugs table
CREATE TABLE IF NOT EXISTS campaign_slugs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT UNSIGNED NOT NULL,
    slug VARCHAR(50) NOT NULL COMMENT 'URL-safe identifier, unique across all campaigns',
    slug_label VARCHAR(100) NOT NULL COMMENT 'Human-readable label for the slug',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_slug (slug),
    INDEX idx_campaign_id (campaign_id),
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add slug_id column to clicks table for attribution
ALTER TABLE clicks 
ADD COLUMN slug_id INT UNSIGNED NULL AFTER campaign_id,
ADD INDEX idx_slug_id (slug_id),
ADD FOREIGN KEY (slug_id) REFERENCES campaign_slugs(id) ON DELETE SET NULL;

