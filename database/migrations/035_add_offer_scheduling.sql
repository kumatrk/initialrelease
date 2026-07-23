-- Add offer scheduling fields
ALTER TABLE offers 
ADD COLUMN is_24_7 TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = available 24/7, 0 = time-restricted',
ADD COLUMN schedule_days JSON NULL COMMENT 'Array of days when offer is available (e.g., ["monday", "tuesday", "wednesday"])',
ADD COLUMN schedule_start_time TIME NULL COMMENT 'Start time for schedule (e.g., 09:00:00)',
ADD COLUMN schedule_end_time TIME NULL COMMENT 'End time for schedule (e.g., 17:00:00)',
ADD COLUMN schedule_timezone VARCHAR(50) NULL DEFAULT 'UTC' COMMENT 'Timezone for schedule (e.g., America/New_York)';

