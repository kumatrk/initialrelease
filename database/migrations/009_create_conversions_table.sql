-- Create conversions table
CREATE TABLE IF NOT EXISTS conversions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    click_id CHAR(36) NOT NULL COMMENT 'References clicks.click_id',
    txid VARCHAR(100) NULL COMMENT 'Transaction ID from affiliate network',
    event_id VARCHAR(100) NULL COMMENT 'Event ID for deduplication',
    value DECIMAL(12,6) NULL COMMENT 'Conversion value',
    currency CHAR(3) NULL COMMENT 'Currency code',
    status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, approved, rejected',
    ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    payout DECIMAL(12,6) NULL COMMENT 'Payout amount',
    source_json JSON NULL COMMENT 'Raw postback data',
    INDEX idx_click_id (click_id),
    INDEX idx_txid (txid),
    INDEX idx_event_id (event_id),
    INDEX idx_ts (ts),
    INDEX idx_status (status),
    UNIQUE KEY unique_dedup (click_id, txid, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


