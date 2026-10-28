<?php

declare(strict_types=1);

namespace SimpleKuma\NewsBreak;

use mysqli;

/**
 * Summary-first NewsBreak spend overlay from newsbreak_campaign_hourly_costs.
 * Does not scan clicks for unfiltered totals.
 */
final class NewsBreakCostAggregator
{
    public function __construct(private mysqli $db)
    {
    }

    public function tableExists(): bool
    {
        $r = $this->db->query("SHOW TABLES LIKE 'newsbreak_campaign_hourly_costs'");
        return $r && $r->num_rows > 0;
    }

    public function getCampaignTotalCost(int $campaignId, string $utcFrom, string $utcTo): float
    {
        if ($campaignId < 1 || !$this->tableExists()) {
            return 0.0;
        }
        $map = $this->getCostsByCampaignIds([$campaignId], $utcFrom, $utcTo);
        return (float) ($map[$campaignId] ?? 0.0);
    }

    /**
     * @param list<int> $campaignIds
     * @return array<int, float>
     */
    public function getCostsByCampaignIds(array $campaignIds, string $utcFrom, string $utcTo): array
    {
        $campaignIds = array_values(array_unique(array_filter(array_map('intval', $campaignIds), static fn(int $id): bool => $id > 0)));
        $out = array_fill_keys($campaignIds, 0.0);
        if ($campaignIds === [] || !$this->tableExists()) {
            return $out;
        }

        $fromDate = substr($utcFrom, 0, 10);
        $toDate = substr($utcTo, 0, 10);
        $in = implode(',', $campaignIds);
        $sql = "
            SELECT kuma_campaign_id, SUM(spend) AS total_spend
            FROM newsbreak_campaign_hourly_costs
            WHERE kuma_campaign_id IN ({$in})
              AND cost_date >= ?
              AND cost_date <= ?
            GROUP BY kuma_campaign_id
        ";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return $out;
        }
        $stmt->bind_param('ss', $fromDate, $toDate);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $id = (int) ($row['kuma_campaign_id'] ?? 0);
            if ($id > 0) {
                $out[$id] = (float) ($row['total_spend'] ?? 0);
            }
        }
        $stmt->close();

        return $out;
    }
}
