-- Create offers table
CREATE TABLE IF NOT EXISTS offers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    url TEXT NOT NULL,
    payout_type VARCHAR(20) NOT NULL COMMENT 'CPL, CPS, CPA',
    payout_value DECIMAL(12,6) NOT NULL DEFAULT 0.000000,
    network_id INT UNSIGNED NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_network_id (network_id),
    FOREIGN KEY (network_id) REFERENCES networks(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


