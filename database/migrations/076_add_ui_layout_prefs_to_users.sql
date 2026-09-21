-- Migration 076: Per-user UI layout preferences (sidebar rail, dashboard chart visibility)
ALTER TABLE users
ADD COLUMN sidebar_collapsed TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = desktop sidebar collapsed to icon rail'
    AFTER theme,
ADD COLUMN dashboard_charts_hidden TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '1 = hide dashboard Performance Trends chart (skip load)'
    AFTER sidebar_collapsed;
