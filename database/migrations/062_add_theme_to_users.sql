-- Migration 062: Add per-user UI theme preference (light, dark, future premade themes)
ALTER TABLE users
ADD COLUMN theme VARCHAR(32) NOT NULL DEFAULT 'light'
    COMMENT 'UI theme: light, dark, or future premade theme ids'
    AFTER currency;
