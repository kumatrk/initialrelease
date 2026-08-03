<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

use mysqli;
use SimpleKuma\Database\ClicksTableResolver;
use SimpleKuma\Utils\Formatter;

class ConversionsQueryService
{
    private mysqli $db;

    /** @var bool|null */
    private ?bool $trafficSourceColumnExists = null;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * @return array{where: string, params: list<mixed>, types: string}
     */
    private function buildLogWhere(?int $campaignId, string $dateFrom, string $dateTo, string $timezone): array
    {
        $utcRange = Formatter::convertDateRangeToUTC($dateFrom, $dateTo, $timezone);

        $where = ['conv.ts >= ?', 'conv.ts <= ?'];
        $params = [$utcRange['from'], $utcRange['to']];
        $types = 'ss';

        if ($campaignId !== null && $campaignId > 0) {
            $where[] = 'cl.campaign_id = ?';
            $params[] = $campaignId;
            $types .= 'i';
        }

        return [
            'where' => implode(' AND ', $where),
            'params' => $params,
            'types' => $types,
        ];
    }

    private function trafficSourceColumnExists(): bool
    {
        if ($this->trafficSourceColumnExists !== null) {
            return $this->trafficSourceColumnExists;
        }

        $checkColumn = $this->db->query("SELECT COUNT(*) AS count FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'clicks'
            AND COLUMN_NAME = 'traffic_source_id'");

        $exists = false;
        if ($checkColumn && ($row = $checkColumn->fetch_assoc())) {
            $exists = ((int) $row['count']) > 0;
        }

        $this->trafficSourceColumnExists = $exists;
        return $exists;
    }

    /**
     * @return array{from: string, joins: string, trafficSourceSelect: string}
     */
    private function buildLogFromClause(): array
    {
        $clicksTable = ClicksTableResolver::getStatsTable($this->db);
        $hasTrafficSourceCol = $this->trafficSourceColumnExists();

        $trafficSourceSelect = $hasTrafficSourceCol
            ? 'COALESCE(ts.name, cp_ts.name) AS traffic_source_name'
            : 'cp_ts.name AS traffic_source_name';

        $joins = "
            INNER JOIN {$clicksTable} cl ON conv.click_id = cl.click_id
            LEFT JOIN campaigns cp ON cl.campaign_id = cp.id
            LEFT JOIN traffic_sources cp_ts ON cp.traffic_source_id = cp_ts.id
            LEFT JOIN offers o ON cl.offer_id = o.id
            LEFT JOIN landing_pages lp ON cl.landing_page_id = lp.id";

        if ($hasTrafficSourceCol) {
            $joins .= "
            LEFT JOIN traffic_sources ts ON cl.traffic_source_id = ts.id";
        }

        return [
            'from' => 'conversions conv',
            'joins' => $joins,
            'trafficSourceSelect' => $trafficSourceSelect,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapLogRow(array $row): array
    {
        $payout = isset($row['payout']) && $row['payout'] !== null ? (float) $row['payout'] : null;
        $value = isset($row['value']) && $row['value'] !== null ? (float) $row['value'] : null;

        return [
            'id' => (int) $row['id'],
            'click_id' => $row['click_id'],
            'txid' => $row['txid'],
            'event_id' => $row['event_id'],
            'status' => $row['status'],
            'currency' => $row['currency'],
            'ts' => $row['ts'],
            'payout' => $payout,
            'value' => $value,
            'revenue' => (float) ($payout ?? $value ?? 0),
            'source' => $row['source'] ?? null,
            'campaign_id' => isset($row['campaign_id']) ? (int) $row['campaign_id'] : null,
            'campaign_name' => $row['campaign_name'],
            'offer_id' => isset($row['offer_id']) ? (int) $row['offer_id'] : null,
            'offer_name' => $row['offer_name'],
            'landing_page_name' => $row['landing_page_name'],
            'traffic_source_name' => $row['traffic_source_name'],
            'country' => $row['country'],
            'region' => $row['region'],
            'city' => $row['city'],
            'ip' => $row['ip'],
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, total_revenue: float}
     */
    public function listConversionsForLog(
        ?int $campaignId,
        string $dateFrom,
        string $dateTo,
        string $timezone,
        int $page,
        int $perPage,
        ?int $limit = null,
        ?int $offset = null
    ): array {
        $filter = $this->buildLogWhere($campaignId, $dateFrom, $dateTo, $timezone);
        $clause = $this->buildLogFromClause();

        $perPage = min(max(1, $perPage), 500);
        $page = max(1, $page);
        $useOffset = $offset ?? max(0, ($page - 1) * $perPage);
        $useLimit = $limit ?? $perPage;

        $countSql = "
            SELECT COUNT(*) AS cnt,
                   COALESCE(SUM(COALESCE(conv.payout, conv.value, 0)), 0) AS total_revenue
            FROM {$clause['from']}
            {$clause['joins']}
            WHERE {$filter['where']}
        ";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->bind_param($filter['types'], ...$filter['params']);
        $countStmt->execute();
        $countRow = $countStmt->get_result()->fetch_assoc() ?: ['cnt' => 0, 'total_revenue' => 0];
        $total = (int) ($countRow['cnt'] ?? 0);
        $totalRevenue = (float) ($countRow['total_revenue'] ?? 0);

        $selectSql = "
            SELECT conv.id, conv.click_id, conv.txid, conv.event_id, conv.value, conv.currency,
                   conv.status, conv.ts, conv.payout,
                   JSON_UNQUOTE(JSON_EXTRACT(conv.source_json, '$.source')) AS source,
                   cl.campaign_id, cl.offer_id, cl.country, cl.region, cl.city, cl.ip,
                   cp.name AS campaign_name,
                   o.name AS offer_name,
                   lp.name AS landing_page_name,
                   {$clause['trafficSourceSelect']}
            FROM {$clause['from']}
            {$clause['joins']}
            WHERE {$filter['where']}
            ORDER BY conv.ts DESC
            LIMIT ? OFFSET ?
        ";

        $params = array_merge($filter['params'], [$useLimit, $useOffset]);
        $types = $filter['types'] . 'ii';

        $stmt = $this->db->prepare($selectSql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $this->mapLogRow($row);
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'total_revenue' => $totalRevenue,
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function listConversions(
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

        $where = ['conv.ts >= ?', 'conv.ts <= ?'];
        $params = [$utcRange['from'], $utcRange['to']];
        $types = 'ss';

        if ($campaignId !== null) {
            $where[] = 'cl.campaign_id = ?';
            $params[] = $campaignId;
            $types .= 'i';
        }

        $whereSql = implode(' AND ', $where);

        $countSql = "
            SELECT COUNT(*) AS cnt
            FROM conversions conv
            INNER JOIN {$clicksTable} cl ON conv.click_id = cl.click_id
            WHERE {$whereSql}
        ";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = (int)($countStmt->get_result()->fetch_assoc()['cnt'] ?? 0);

        $sql = "
            SELECT conv.id, conv.click_id, conv.ts, conv.payout, conv.value,
                   cl.campaign_id, cp.name AS campaign_name, cl.offer_id
            FROM conversions conv
            INNER JOIN {$clicksTable} cl ON conv.click_id = cl.click_id
            LEFT JOIN campaigns cp ON cl.campaign_id = cp.id
            WHERE {$whereSql}
            ORDER BY conv.ts DESC
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
                'id' => (int)$row['id'],
                'click_id' => $row['click_id'],
                'campaign_id' => (int)$row['campaign_id'],
                'campaign_name' => $row['campaign_name'],
                'offer_id' => isset($row['offer_id']) ? (int)$row['offer_id'] : null,
                'ts' => $row['ts'],
                'payout' => isset($row['payout']) ? (float)$row['payout'] : null,
                'value' => isset($row['value']) ? (float)$row['value'] : null,
                'revenue' => (float)($row['payout'] ?? $row['value'] ?? 0),
            ];
        }

        return ['rows' => $rows, 'total' => $total];
    }
}
