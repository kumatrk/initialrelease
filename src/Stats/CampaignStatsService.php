<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

use mysqli;
use SimpleKuma\Database\ClicksTableResolver;
use SimpleKuma\Utils\Formatter;

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
        $utcRange = Formatter::convertDateRangeToUTC($dateFrom, $dateTo, $timezone);
        $clicksTable = ClicksTableResolver::getStatsTable($this->db);

        $visitors = CampaignStatsExpressions::visitorCountExpr();
        $lpClicks = CampaignStatsExpressions::lpClicksCountExpr();

        $sql = "
            SELECT
                cp.id AS campaign_id,
                cp.name AS campaign_name,
                cp.status,
                {$visitors} AS clicks,
                {$lpClicks} AS lp_clicks,
                " . CampaignStatsExpressions::conversionsCountExpr() . " AS conversions,
                COALESCE(SUM(cl.cost), 0) AS cost,
                COALESCE(SUM(conv.revenue_sum), 0) AS revenue
            FROM campaigns cp
            LEFT JOIN {$clicksTable} cl ON cl.campaign_id = cp.id
                AND cl.ts >= ? AND cl.ts <= ?
            LEFT JOIN traffic_sources ts ON cp.traffic_source_id = ts.id
            " . CampaignStatsExpressions::conversionsAggJoin() . "
        ";

        $params = [$utcRange['from'], $utcRange['to']];
        $types = 'ss';

        if ($campaignId !== null) {
            $sql .= ' WHERE cp.id = ?';
            $params[] = $campaignId;
            $types .= 'i';
        }

        $sql .= ' GROUP BY cp.id, cp.name, cp.status ORDER BY cp.name ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $clicks = (int)$row['clicks'];
            $lpClicks = (int)($row['lp_clicks'] ?? 0);
            $conversions = (int)$row['conversions'];
            $cost = (float)$row['cost'];
            $revenue = (float)$row['revenue'];
            $profit = $revenue - $cost;
            $roi = $cost > 0 ? (($revenue - $cost) / $cost) * 100 : 0.0;
            $cr = $clicks > 0 ? ($conversions / $clicks) * 100 : 0.0;

            $rows[] = [
                'campaign_id' => (int)$row['campaign_id'],
                'campaign_name' => $row['campaign_name'],
                'status' => $row['status'],
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
}
