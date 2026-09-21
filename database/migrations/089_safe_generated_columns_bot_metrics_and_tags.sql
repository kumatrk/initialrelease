-- Migration 089: Safe generated columns for ad/adset tokens, bot metrics in stats summaries, and tags on assets.
-- Idempotent.

-- 1. Modify clicks.ad_id and clicks.adset_id to safely test numeric regex before casting (prevents "Truncated incorrect INTEGER value" on raw macros like {{ad.id}})
SET @col_clicks_ad_id = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'clicks'
      AND COLUMN_NAME = 'ad_id'
);
SET @sql_clicks_ad_id = IF(@col_clicks_ad_id > 0,
    'ALTER TABLE clicks MODIFY COLUMN ad_id BIGINT UNSIGNED GENERATED ALWAYS AS (
        CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(extra_json, ''$.traffic_source_tokens.ad_id'')) REGEXP ''^[0-9]{1,20}$''
             THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(extra_json, ''$.traffic_source_tokens.ad_id'')) AS UNSIGNED)
             ELSE NULL
        END
    ) STORED COMMENT ''Generated from extra_json for indexing (safe numeric cast)''',
    'SELECT ''Column clicks.ad_id does not exist''');
PREPARE stmt FROM @sql_clicks_ad_id;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_clicks_adset_id = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'clicks'
      AND COLUMN_NAME = 'adset_id'
);
SET @sql_clicks_adset_id = IF(@col_clicks_adset_id > 0,
    'ALTER TABLE clicks MODIFY COLUMN adset_id BIGINT UNSIGNED GENERATED ALWAYS AS (
        CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(extra_json, ''$.traffic_source_tokens.adset_id'')) REGEXP ''^[0-9]{1,20}$''
             THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(extra_json, ''$.traffic_source_tokens.adset_id'')) AS UNSIGNED)
             ELSE NULL
        END
    ) STORED COMMENT ''Generated from extra_json for indexing (safe numeric cast)''',
    'SELECT ''Column clicks.adset_id does not exist''');
PREPARE stmt FROM @sql_clicks_adset_id;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Modify clicks_archive generated columns if table exists
SET @col_archive_ad_id = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'clicks_archive'
      AND COLUMN_NAME = 'ad_id'
);
SET @sql_archive_ad_id = IF(@col_archive_ad_id > 0,
    'ALTER TABLE clicks_archive MODIFY COLUMN ad_id BIGINT UNSIGNED GENERATED ALWAYS AS (
        CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(extra_json, ''$.traffic_source_tokens.ad_id'')) REGEXP ''^[0-9]{1,20}$''
             THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(extra_json, ''$.traffic_source_tokens.ad_id'')) AS UNSIGNED)
             ELSE NULL
        END
    ) STORED',
    'SELECT ''Column clicks_archive.ad_id does not exist''');
PREPARE stmt FROM @sql_archive_ad_id;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_archive_adset_id = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'clicks_archive'
      AND COLUMN_NAME = 'adset_id'
);
SET @sql_archive_adset_id = IF(@col_archive_adset_id > 0,
    'ALTER TABLE clicks_archive MODIFY COLUMN adset_id BIGINT UNSIGNED GENERATED ALWAYS AS (
        CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(extra_json, ''$.traffic_source_tokens.adset_id'')) REGEXP ''^[0-9]{1,20}$''
             THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(extra_json, ''$.traffic_source_tokens.adset_id'')) AS UNSIGNED)
             ELSE NULL
        END
    ) STORED',
    'SELECT ''Column clicks_archive.adset_id does not exist''');
PREPARE stmt FROM @sql_archive_adset_id;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Add bot_clicks column to pre-aggregated summary tables
SET @col_daily_bot_clicks = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'clicks_daily_summary'
      AND COLUMN_NAME = 'bot_clicks'
);
SET @sql_daily_bot_clicks = IF(@col_daily_bot_clicks = 0,
    'ALTER TABLE clicks_daily_summary ADD COLUMN bot_clicks INT UNSIGNED NOT NULL DEFAULT 0 AFTER optins',
    'SELECT ''Column clicks_daily_summary.bot_clicks already exists''');
PREPARE stmt FROM @sql_daily_bot_clicks;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_token_bot_clicks = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'clicks_stats_by_token_daily'
      AND COLUMN_NAME = 'bot_clicks'
);
SET @sql_token_bot_clicks = IF(@col_token_bot_clicks = 0,
    'ALTER TABLE clicks_stats_by_token_daily ADD COLUMN bot_clicks INT UNSIGNED NOT NULL DEFAULT 0 AFTER optins',
    'SELECT ''Column clicks_stats_by_token_daily.bot_clicks already exists''');
PREPARE stmt FROM @sql_token_bot_clicks;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Add tags column to campaigns, offers, and landing_pages
SET @col_camp_tags = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'campaigns'
      AND COLUMN_NAME = 'tags'
);
SET @sql_camp_tags = IF(@col_camp_tags = 0,
    'ALTER TABLE campaigns ADD COLUMN tags VARCHAR(255) NULL AFTER name',
    'SELECT ''Column campaigns.tags already exists''');
PREPARE stmt FROM @sql_camp_tags;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_offer_tags = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'offers'
      AND COLUMN_NAME = 'tags'
);
SET @sql_offer_tags = IF(@col_offer_tags = 0,
    'ALTER TABLE offers ADD COLUMN tags VARCHAR(255) NULL AFTER name',
    'SELECT ''Column offers.tags already exists''');
PREPARE stmt FROM @sql_offer_tags;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_lp_tags = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'landing_pages'
      AND COLUMN_NAME = 'tags'
);
SET @sql_lp_tags = IF(@col_lp_tags = 0,
    'ALTER TABLE landing_pages ADD COLUMN tags VARCHAR(255) NULL AFTER name',
    'SELECT ''Column landing_pages.tags already exists''');
PREPARE stmt FROM @sql_lp_tags;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
