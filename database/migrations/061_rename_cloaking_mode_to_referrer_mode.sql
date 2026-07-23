-- Rename cloaking_mode to referrer_mode (same values: blank, noreferrer, double)
ALTER TABLE campaigns
    CHANGE COLUMN cloaking_mode referrer_mode VARCHAR(20) NULL
    COMMENT 'Referrer privacy: blank, noreferrer, double (bear-hop)';
