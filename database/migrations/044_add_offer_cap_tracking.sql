-- Add offer CAP (lead limit) tracking fields
ALTER TABLE offers 
ADD COLUMN cap_enabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = cap tracking enabled, 0 = no cap',
ADD COLUMN cap_limit INT UNSIGNED NULL COMMENT 'Maximum number of leads allowed (required if cap_enabled = 1)',
ADD COLUMN cap_period ENUM('day', 'week', 'month', 'lifetime') NULL COMMENT 'Period for cap reset',
ADD COLUMN cap_timezone VARCHAR(50) NULL DEFAULT 'UTC' COMMENT 'Timezone for cap period calculations (e.g., America/New_York)';

