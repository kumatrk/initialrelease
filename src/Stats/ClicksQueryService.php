<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

use mysqli;
use SimpleKuma\Database\ClicksTableResolver;
use SimpleKuma\Utils\Formatter;

class ClicksQueryService
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function listClicks(
        ?int $campaignId,
        string $dateFrom,
        string $dateTo,
        string $timezone,
        int $page,
        int $perPage
    ): array {
        $utcRange = Formatter::convertDateRangeToUTC($dateFrom, $dateTo, $timezone);
        $clicksTable = ClicksTableResolver::getStatsTable($this->db);
        $offset = max(0, ($page - 1) * $perPage);
        $perPage = min(max(1, $perPage), 200);

        $where = ['cl.ts >= ?', 'cl.ts <= ?'];
        $params = [$utcRange['from'], $utcRange['to']];
        $types = 'ss';

        if ($campaignId !== null) {
            $where[] = 'cl.campaign_id = ?';
            $params[] = $campaignId;
            $types .= 'i';
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $this->db->prepare("SELECT COUNT(*) AS cnt FROM {$clicksTable} cl WHERE {$whereSql}");
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = (int)($countStmt->get_result()->fetch_assoc()['cnt'] ?? 0);

        $sql = "
            SELECT cl.click_id, cl.campaign_id, cp.name AS campaign_name,
                   cl.offer_id, cl.landing_page_id, cl.traffic_source_id,
                   cl.ip, cl.country, cl.ts, cl.cost, cl.lp_click,
                   conv.id AS conversion_id,
                   COALESCE(conv.payout, conv.value) AS conversion_payout
            FROM {$clicksTable} cl
            LEFT JOIN campaigns cp ON cl.campaign_id = cp.id
            LEFT JOIN conversions conv ON cl.click_id = conv.click_id
            WHERE {$whereSql}
            ORDER BY cl.ts DESC
            LIMIT ? OFFSET ?
        ";

        $params[] = $perPage;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'click_id' => $row['click_id'],
                'campaign_id' => (int)$row['campaign_id'],
                'campaign_name' => $row['campaign_name'],
                'offer_id' => isset($row['offer_id']) ? (int)$row['offer_id'] : null,
                'landing_page_id' => isset($row['landing_page_id']) ? (int)$row['landing_page_id'] : null,
                'traffic_source_id' => isset($row['traffic_source_id']) ? (int)$row['traffic_source_id'] : null,
                'ip' => $row['ip'],
                'country' => $row['country'],
                'ts' => $row['ts'],
                'cost' => isset($row['cost']) ? (float)$row['cost'] : 0.0,
                'lp_click' => (bool)(int)($row['lp_click'] ?? 0),
                'has_conversion' => $row['conversion_id'] !== null,
                'conversion_payout' => $row['conversion_payout'] !== null ? (float)$row['conversion_payout'] : null,
            ];
        }

        return ['rows' => $rows, 'total' => $total];
    }
}
