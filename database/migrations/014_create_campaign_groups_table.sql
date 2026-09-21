-- Create campaign_groups table for managed campaign groups
CREATE TABLE IF NOT EXISTS campaign_groups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add campaign_group_id foreign key to campaigns table
ALTER TABLE campaigns 
ADD COLUMN campaign_group_id INT UNSIGNED NULL AFTER name,
ADD FOREIGN KEY (campaign_group_id) REFERENCES campaign_groups(id) ON DELETE SET NULL,
ADD INDEX idx_campaign_group_id (campaign_group_id);

