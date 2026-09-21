-- Create custom_postbacks table for user-defined postback URLs with token placeholders
CREATE TABLE IF NOT EXISTS custom_postbacks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE COMMENT 'Unique name for the postback configuration',
    postback_url TEXT NOT NULL COMMENT 'Postback URL with token placeholders (e.g., {click_id}, {campaign_id}, {location})',
    description TEXT NULL COMMENT 'Optional description of what this postback is used for',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create junction table to associate multiple custom postbacks with campaigns
CREATE TABLE IF NOT EXISTS campaign_custom_postbacks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT UNSIGNED NOT NULL,
    custom_postback_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_campaign_postback (campaign_id, custom_postback_id),
    INDEX idx_campaign_id (campaign_id),
    INDEX idx_custom_postback_id (custom_postback_id),
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (custom_postback_id) REFERENCES custom_postbacks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

