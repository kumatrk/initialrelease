<?php

declare(strict_types=1);

namespace SimpleKuma\Honeycomb;

use mysqli;
use RuntimeException;

/**
 * Shared hourly/daily spend snapshots written by CostSyncJob addons.
 */
final class HourlyCostStore
{
    public function __construct(private mysqli $db)
    {
    }

    public function tableExists(): bool
    {
        $result = $this->db->query("SHOW TABLES LIKE 'honeycomb_campaign_hourly_costs'");
        return $result !== false && $result->num_rows > 0;
    }

    /**
     * Upsert one bucket. delta_spend is what stats overlay sums.
     */
    public function upsertBucket(
        string $addonSlug,
        string $remoteAccountId,
        string $remoteCampaignId,
        string $date,
        int $hour,
        float $spend,
        float $deltaSpend,
        ?int $kumaCampaignId = null,
        ?int $credentialId = null,
        ?string $currency = null,
        string $source = 'honeycomb'
    ): bool {
        $this->assertTable();
        $hour = max(0, min(23, $hour));
        // mysqli needs variables for bind_param; nulls are allowed for nullable INT columns.
        $cred = $credentialId;
        $camp = $kumaCampaignId;
        $cur = $currency;
        $stmt = $this->db->prepare(
            'INSERT INTO honeycomb_campaign_hourly_costs
                (addon_slug, credential_id, remote_account_id, remote_campaign_id, campaign_id,
                 date, hour, spend, delta_spend, currency, last_synced, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE
                credential_id = VALUES(credential_id),
                campaign_id = COALESCE(VALUES(campaign_id), campaign_id),
                spend = VALUES(spend),
                delta_spend = VALUES(delta_spend),
                currency = VALUES(currency),
                last_synced = NOW(),
                source = VALUES(source)'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param(
            'sissisiddss',
            $addonSlug,
            $cred,
            $remoteAccountId,
            $remoteCampaignId,
            $camp,
            $date,
            $hour,
            $spend,
            $deltaSpend,
            $cur,
            $source
        );
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    /**
     * Sum delta_spend for Kuma campaigns via bindings (no click scan).
     *
     * @param list<int> $campaignIds
     * @return array<int, float> campaignId => spend
     */
    public function sumDeltaByCampaignIds(
        array $campaignIds,
        string $utcFrom,
        string $utcTo,
        ?string $addonSlug = null
    ): array {
        $campaignIds = array_values(array_unique(array_filter(array_map('intval', $campaignIds))));
        $out = [];
        foreach ($campaignIds as $id) {
            $out[$id] = 0.0;
        }
        if (!$this->tableExists() || $campaignIds === []) {
            return $out;
        }

        $dateFrom = substr($utcFrom, 0, 10);
        $dateTo = substr($utcTo, 0, 10);
        $placeholders = implode(',', array_fill(0, count($campaignIds), '?'));

        // Prefer rows stamped with kuma campaign_id; also join bindings for older rows.
        $sql = "
            SELECT b.campaign_id AS campaign_id, COALESCE(SUM(h.delta_spend), 0) AS spend
            FROM campaign_addon_bindings b
            INNER JOIN honeycomb_campaign_hourly_costs h
                ON h.addon_slug = b.addon_slug
               AND h.remote_account_id = b.remote_account_id
               AND h.remote_campaign_id = b.remote_campaign_id
            WHERE b.campaign_id IN ({$placeholders})
              AND h.date >= ? AND h.date <= ?
        ";
        $types = str_repeat('i', count($campaignIds)) . 'ss';
        $params = array_merge($campaignIds, [$dateFrom, $dateTo]);
        if ($addonSlug !== null && $addonSlug !== '') {
            $sql .= ' AND b.addon_slug = ?';
            $types .= 's';
            $params[] = $addonSlug;
        }
        $sql .= ' GROUP BY b.campaign_id';

        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            return $out;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();

        foreach ($rows as $row) {
            $cid = (int) ($row['campaign_id'] ?? 0);
            if ($cid > 0) {
                $out[$cid] = (float) ($row['spend'] ?? 0);
            }
        }

        return $out;
    }

    private function assertTable(): void
    {
        if (!$this->tableExists()) {
            throw new RuntimeException('honeycomb_campaign_hourly_costs table is missing. Run database migrations.');
        }
    }
}
