-- Recent vendor API failures for Settings → API Cost Updates.
-- Counts stay on facebook_api_calls / google_ads_api_calls. This table stores
-- only failures, with the vendor message and no tokens.

CREATE TABLE IF NOT EXISTS api_failure_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    called_at DATETIME NOT NULL,
    provider VARCHAR(32) NOT NULL,
    action VARCHAR(160) NOT NULL,
    http_code SMALLINT UNSIGNED NULL,
    message VARCHAR(500) NOT NULL,
    source VARCHAR(80) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_api_failure_called (called_at),
    KEY idx_api_failure_dedupe (provider, action, called_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
