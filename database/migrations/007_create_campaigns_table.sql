-- Create campaigns table
CREATE TABLE IF NOT EXISTS campaigns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    traffic_source_id INT UNSIGNED NOT NULL,
    flow_type VARCHAR(20) NOT NULL COMMENT 'DTO, LP, Split',
    rotation_json JSON NULL COMMENT 'LP and Offer rotations with weights',
    tracking_domain_id INT UNSIGNED NULL,
    cloaking_mode VARCHAR(20) NULL COMMENT 'blank, noreferrer, double',
    pass_through_json JSON NULL COMMENT 'Param pass-through toggles',
    facebook_capi_json JSON NULL COMMENT 'Facebook CAPI settings (pixel_id, token, etc)',
    timezone VARCHAR(50) NOT NULL DEFAULT 'UTC',
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active, paused, archived',
    default_cpc DECIMAL(12,6) NULL COMMENT 'Default cost per click when not provided',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_traffic_source (traffic_source_id),
    INDEX idx_tracking_domain (tracking_domain_id),
    INDEX idx_status (status),
    FOREIGN KEY (traffic_source_id) REFERENCES traffic_sources(id) ON DELETE RESTRICT,
    FOREIGN KEY (tracking_domain_id) REFERENCES tracking_domains(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


