-- Migration 048: Add request_body column to postback_logs table
-- This column stores the full request payload (JSON) sent to APIs like Facebook CAPI

ALTER TABLE postback_logs 
ADD COLUMN request_body TEXT NULL COMMENT 'Full request payload sent to the API (JSON for POST requests, null for GET requests)' 
AFTER response_body;

