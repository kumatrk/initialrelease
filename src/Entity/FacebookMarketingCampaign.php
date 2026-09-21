<?php

declare(strict_types=1);

namespace SimpleKuma\Entity;

use mysqli;

/**
 * Cached Meta (Facebook) campaigns per marketing ad account.
 */
class FacebookMarketingCampaign
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function tableExists(): bool
    {
        $r = $this->db->query("SHOW TABLES LIKE 'facebook_marketing_campaigns'");
        return $r && $r->num_rows > 0;
    }

    public function getById(int $id): ?array
    {
        if (!$this->tableExists()) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT fmc.*, fmaa.ad_account_id AS facebook_act_id
             FROM facebook_marketing_campaigns fmc
             INNER JOIN facebook_marketing_ad_accounts fmaa ON fmc.facebook_marketing_ad_account_id = fmaa.id
             WHERE fmc.id = ?'
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getByAdAccountId(int $adAccountInternalId, ?string $statusFilter = 'ACTIVE'): array
    {
        if (!$this->tableExists()) {
            return [];
        }
        $sql = 'SELECT * FROM facebook_marketing_campaigns WHERE facebook_marketing_ad_account_id = ?';
        if ($statusFilter !== null && $statusFilter !== '') {
            $sql .= ' AND effective_status = ?';
        }
        $sql .= ' ORDER BY campaign_name ASC';
        $stmt = $this->db->prepare($sql);
        if ($statusFilter !== null && $statusFilter !== '') {
            $stmt->bind_param('is', $adAccountInternalId, $statusFilter);
        } else {
            $stmt->bind_param('i', $adAccountInternalId);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    public function resolveMetaCampaignId(int $internalCampaignRowId): ?string
    {
        $row = $this->getById($internalCampaignRowId);
        return $row['meta_campaign_id'] ?? null;
    }

    /**
     * Replace cached campaigns for an ad account (same pattern as ad account sync).
     *
     * @param array<int, array{meta_campaign_id: string, campaign_name: string, effective_status: string}> $campaigns
     */
    public function syncForAdAccount(int $adAccountInternalId, array $campaigns): bool
    {
        if (!$this->tableExists()) {
            return false;
        }

        $this->db->begin_transaction();
        try {
            $delete = $this->db->prepare(
                'DELETE FROM facebook_marketing_campaigns WHERE facebook_marketing_ad_account_id = ?'
            );
            $delete->bind_param('i', $adAccountInternalId);
            $delete->execute();
            $delete->close();

            $insert = $this->db->prepare(
                'INSERT INTO facebook_marketing_campaigns
                 (facebook_marketing_ad_account_id, meta_campaign_id, campaign_name, effective_status, synced_at, created_at)
                 VALUES (?, ?, ?, ?, NOW(), NOW())'
            );

            foreach ($campaigns as $campaign) {
                $metaId = (string)($campaign['meta_campaign_id'] ?? $campaign['id'] ?? '');
                if ($metaId === '') {
                    continue;
                }
                $name = (string)($campaign['campaign_name'] ?? $campaign['name'] ?? 'Unknown');
                $status = (string)($campaign['effective_status'] ?? 'ACTIVE');
                $insert->bind_param('isss', $adAccountInternalId, $metaId, $name, $status);
                if (!$insert->execute()) {
                    throw new \RuntimeException('Insert failed: ' . $this->db->error);
                }
            }
            $insert->close();
            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollback();
            error_log('FacebookMarketingCampaign sync failed: ' . $e->getMessage());
            return false;
        }
    }

    public function getLastSyncedAt(int $adAccountInternalId): ?string
    {
        if (!$this->tableExists()) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT MAX(synced_at) AS synced_at FROM facebook_marketing_campaigns WHERE facebook_marketing_ad_account_id = ?'
        );
        $stmt->bind_param('i', $adAccountInternalId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row['synced_at'] ?? null;
    }
}
