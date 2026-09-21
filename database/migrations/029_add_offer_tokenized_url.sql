-- Add tokenized_url field to offers table
-- This allows offers to have URLs with dynamic tokens that get replaced at click-time
ALTER TABLE offers
ADD COLUMN tokenized_url VARCHAR(1024) NULL DEFAULT NULL
    COMMENT 'Tokenized URL template with placeholders like {click_id}, {campaign_id}, etc. If set, this takes precedence over url + parameter appending'
    AFTER url;

