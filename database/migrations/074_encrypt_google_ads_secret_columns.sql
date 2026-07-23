-- Widen Google Ads secret columns for AES-GCM ciphertext (base64)
-- oauth_client_secret / developer_token were VARCHAR(255); encrypted payloads can be longer.

ALTER TABLE google_ads_integrations
    MODIFY COLUMN developer_token TEXT NULL
        COMMENT 'Google Ads developer token (encrypted at rest with APP_KEY)',
    MODIFY COLUMN oauth_client_secret TEXT NULL
        COMMENT 'OAuth2 client secret (encrypted at rest with APP_KEY)',
    MODIFY COLUMN oauth_refresh_token TEXT NULL
        COMMENT 'OAuth2 refresh token (encrypted at rest with APP_KEY)';
