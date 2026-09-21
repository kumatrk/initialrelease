<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

use mysqli;

/**
 * Focused campaign stats for API v1.
 */
class CampaignStatsService
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getCampaignStats(?int $campaignId, string $dateFrom, string $dateTo, string $timezone): array
    {
        $campaigns = $this->loadCampaignRows($campaignId);
        if ($campaigns === []) {
            return [];
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $campaigns);
        $metrics = (new CampaignListStatsService($this->db))->loadStatsForCampaignIds(
            $ids,
            $dateFrom,
            $dateTo,
            $timezone,
            false
        );

        $rows = [];
        foreach ($campaigns as $campaign) {
            $id = (int) $campaign['id'];
            $m = $metrics[$id] ?? [
                'views' => 0,
                'lp_clicks' => 0,
                'conversions' => 0,
                'cost' => 0.0,
                'revenue' => 0.0,
                'profit' => 0.0,
                'roi' => 0.0,
            ];
            $clicks = (int) $m['views'];
            $lpClicks = (int) $m['lp_clicks'];
            $conversions = (int) $m['conversions'];
            $cost = (float) $m['cost'];
            $revenue = (float) $m['revenue'];
            $profit = (float) $m['profit'];
            $roi = (float) $m['roi'];
            $cr = $clicks > 0 ? ($conversions / $clicks) * 100 : 0.0;

            $rows[] = [
                'campaign_id' => $id,
                'campaign_name' => $campaign['name'],
                'status' => $campaign['status'],
                'clicks' => $clicks,
                'lp_clicks' => $lpClicks,
                'conversions' => $conversions,
                'cost' => round($cost, 4),
                'revenue' => round($revenue, 4),
                'profit' => round($profit, 4),
                'roi' => round($roi, 2),
                'conversion_rate' => round($cr, 2),
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'timezone' => $timezone,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{id: int, name: string, status: string}>
     */
    private function loadCampaignRows(?int $campaignId): array
    {
        if ($campaignId !== null) {
            $stmt = $this->db->prepare(
                'SELECT id, name, status FROM campaigns WHERE id = ? LIMIT 1'
            );
            if ($stmt === false) {
                return [];
            }
            $stmt->bind_param('i', $campaignId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            return $row ? [$row] : [];
        }

        $result = $this->db->query('SELECT id, name, status FROM campaigns ORDER BY name ASC');
        if ($result === false) {
            return [];
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        return $rows;
    }
}
