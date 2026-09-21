-- Migration 037: Add Enhanced Device Detection Fields
-- Adds device brand, model, OS version, and browser version columns to clicks table
-- This enables more granular device analytics using Matomo DeviceDetector library

-- Add new fields to clicks table
ALTER TABLE clicks 
ADD COLUMN device_brand VARCHAR(50) NULL COMMENT 'Device brand (e.g., Apple, Samsung, Google)' AFTER device,
ADD COLUMN device_model VARCHAR(100) NULL COMMENT 'Device model (e.g., iPhone 12, Galaxy S21)' AFTER device_brand,
ADD COLUMN os_version VARCHAR(20) NULL COMMENT 'OS version (e.g., 14.4, 13.0, Android 12)' AFTER os,
ADD COLUMN browser_version VARCHAR(20) NULL COMMENT 'Browser version (e.g., 120.0, 17.0)' AFTER browser;

-- Add indexes for common queries
ALTER TABLE clicks
ADD INDEX idx_device_brand (device_brand),
ADD INDEX idx_device_model (device_model),
ADD INDEX idx_os_version (os_version);

-- Update archive table to match
ALTER TABLE clicks_archive 
ADD COLUMN device_brand VARCHAR(50) NULL COMMENT 'Device brand (e.g., Apple, Samsung, Google)' AFTER device,
ADD COLUMN device_model VARCHAR(100) NULL COMMENT 'Device model (e.g., iPhone 12, Galaxy S21)' AFTER device_brand,
ADD COLUMN os_version VARCHAR(20) NULL COMMENT 'OS version (e.g., 14.4, 13.0, Android 12)' AFTER os,
ADD COLUMN browser_version VARCHAR(20) NULL COMMENT 'Browser version (e.g., 120.0, 17.0)' AFTER browser;

-- Add indexes to archive table
ALTER TABLE clicks_archive
ADD INDEX idx_device_brand (device_brand),
ADD INDEX idx_device_model (device_model),
ADD INDEX idx_os_version (os_version);

