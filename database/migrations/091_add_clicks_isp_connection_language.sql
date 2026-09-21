-- Migration 091: ISP, connection type, and browser language on clicks
-- Enables tracker breakdowns for GeoIP ASN ISP, connection type, and Accept-Language.

ALTER TABLE clicks
    ADD COLUMN isp VARCHAR(255) NULL COMMENT 'ISP / ASN organization' AFTER postal,
    ADD COLUMN connection_type VARCHAR(32) NULL COMMENT 'Cellular, Broadband, Corporate, or Unknown' AFTER isp,
    ADD COLUMN language VARCHAR(16) NULL COMMENT 'Primary Accept-Language tag' AFTER connection_type;

ALTER TABLE clicks
    ADD INDEX idx_clicks_campaign_isp (campaign_id, isp(64)),
    ADD INDEX idx_clicks_campaign_connection_type (campaign_id, connection_type),
    ADD INDEX idx_clicks_campaign_language (campaign_id, language);

-- Archive may lack postal depending on install age; place after city for safety.
ALTER TABLE clicks_archive
    ADD COLUMN isp VARCHAR(255) NULL COMMENT 'ISP / ASN organization' AFTER city,
    ADD COLUMN connection_type VARCHAR(32) NULL COMMENT 'Cellular, Broadband, Corporate, or Unknown' AFTER isp,
    ADD COLUMN language VARCHAR(16) NULL COMMENT 'Primary Accept-Language tag' AFTER connection_type;
