-- Migration 077: Dashboard Performance Trends chart starts hidden by default for new users
ALTER TABLE users
MODIFY COLUMN dashboard_charts_hidden TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '1 = hide dashboard Performance Trends chart (skip load)';
