-- Add proxy support fields to Facebook Marketing Integrations table
-- Migration 039: Proxy Support for Facebook/Meta API Requests
-- Adds optional proxy configuration to enable region-specific routing and avoid rate limits

ALTER TABLE facebook_marketing_integrations
ADD COLUMN use_proxy TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Enable proxy for API requests' AFTER status,
ADD COLUMN proxy_host VARCHAR(255) NULL COMMENT 'Proxy hostname or IP address' AFTER use_proxy,
ADD COLUMN proxy_port INT UNSIGNED NULL COMMENT 'Proxy port (1-65535)' AFTER proxy_host,
ADD COLUMN proxy_type ENUM('HTTP', 'SOCKS5') NULL COMMENT 'Proxy type: HTTP or SOCKS5' AFTER proxy_port,
ADD COLUMN proxy_user VARCHAR(255) NULL COMMENT 'Proxy username (optional)' AFTER proxy_type,
ADD COLUMN proxy_pass_encrypted TEXT NULL COMMENT 'Encrypted proxy password (never stored in plain text)' AFTER proxy_user,
ADD INDEX idx_use_proxy (use_proxy);

-- Add proxy support fields to Facebook CAPI Integrations table
ALTER TABLE facebook_capi_integrations
ADD COLUMN use_proxy TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Enable proxy for API requests' AFTER test_code,
ADD COLUMN proxy_host VARCHAR(255) NULL COMMENT 'Proxy hostname or IP address' AFTER use_proxy,
ADD COLUMN proxy_port INT UNSIGNED NULL COMMENT 'Proxy port (1-65535)' AFTER proxy_host,
ADD COLUMN proxy_type ENUM('HTTP', 'SOCKS5') NULL COMMENT 'Proxy type: HTTP or SOCKS5' AFTER proxy_port,
ADD COLUMN proxy_user VARCHAR(255) NULL COMMENT 'Proxy username (optional)' AFTER proxy_type,
ADD COLUMN proxy_pass_encrypted TEXT NULL COMMENT 'Encrypted proxy password (never stored in plain text)' AFTER proxy_user,
ADD INDEX idx_use_proxy (use_proxy);

