-- Migration 056: Add fallback_offer_id to campaigns table
-- Optional offer used when all regular offers are unavailable (cap, time window, disabled)

ALTER TABLE campaigns
ADD COLUMN fallback_offer_id INT UNSIGNED NULL AFTER rotation_json,
ADD CONSTRAINT fk_campaigns_fallback_offer
    FOREIGN KEY (fallback_offer_id) REFERENCES offers(id) ON DELETE SET NULL;
