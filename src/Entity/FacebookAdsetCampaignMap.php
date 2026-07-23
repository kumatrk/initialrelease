<?php

declare(strict_types=1);

namespace SimpleKuma\Entity;

use mysqli;

/**
 * Maps Facebook adset IDs to Meta campaign IDs (updated by cost cron).
 */
class FacebookAdsetCampaignMap
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function tableExists(): bool
    {
        $r = $this->db->query("SHOW TABLES LIKE 'facebook_adset_campaign_map'");
        return $r && $r->num_rows > 0;
    }

    /**
     * @param array<string, string> $adsetIdToMetaCampaignId adset_id => meta_campaign_id
     */
    public function upsertBatch(int $adAccountInternalId, array $adsetIdToMetaCampaignId): void
    {
        if (!$this->tableExists() || empty($adsetIdToMetaCampaignId)) {
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO facebook_adset_campaign_map (adset_id, meta_campaign_id, facebook_marketing_ad_account_id, updated_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE meta_campaign_id = VALUES(meta_campaign_id), updated_at = NOW()'
        );

        foreach ($adsetIdToMetaCampaignId as $adsetId => $metaCampaignId) {
            if (!ctype_digit((string)$adsetId) || $metaCampaignId === '') {
                continue;
            }
            $adsetIdInt = (int)$adsetId;
            $stmt->bind_param('isi', $adsetIdInt, $metaCampaignId, $adAccountInternalId);
            $stmt->execute();
        }
        $stmt->close();
    }
}
