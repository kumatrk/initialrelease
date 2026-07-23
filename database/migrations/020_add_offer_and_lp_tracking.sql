-- Add offer_id and landing_page_id tracking to clicks table
-- This enables accurate offer and landing page performance stats

ALTER TABLE clicks 
ADD COLUMN offer_id INT UNSIGNED NULL COMMENT 'Offer that was shown to this click (after rotation)' AFTER campaign_id,
ADD COLUMN landing_page_id INT UNSIGNED NULL COMMENT 'Landing page that was shown to this click (after rotation)' AFTER offer_id,
ADD INDEX idx_offer_id (offer_id),
ADD INDEX idx_landing_page_id (landing_page_id),
ADD FOREIGN KEY (offer_id) REFERENCES offers(id) ON DELETE SET NULL,
ADD FOREIGN KEY (landing_page_id) REFERENCES landing_pages(id) ON DELETE SET NULL;

