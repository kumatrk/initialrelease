<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

use mysqli;

/**
 * Fast-path reads from clicks_daily_summary and clicks_stats_by_token_daily (Phase 2b).
 */
final class CampaignStatsPreAggregateReader
{
    public function __construct(private mysqli $db)
    {
    }

    public function dailySummaryTableExists(): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $result = $this->db->query(
            "SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clicks_daily_summary' LIMIT 1"
        );
        $cache = $result !== false && $result->num_rows > 0;

        return $cache;
    }

    public function tokenDailyTableExists(): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $result = $this->db->query(
            "SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clicks_stats_by_token_daily' LIMIT 1"
        );
        $cache = $result !== false && $result->num_rows > 0;

        return $cache;
    }

    /**
     * Pre-agg summary works when token filter is off (other filters map to summary columns).
     */
    public function canUseSummary(CampaignStatsQueryFilters $filters): bool
    {
        if (!$this->dailySummaryTableExists() || $filters->requiresScopedCost()) {
            return false;
        }

        return !$filters->hasTokenFilter();
    }

    /**
     * Pre-agg breakdowns for date/offer/landing (daily summary) and token dims (token daily).
     * Allows offer/landing parent drill-downs on daily summary (manual-cost path).
     * Token dims and geo/device stay raw when a parent path is present (token table has no offer/LP).
     *
     * @param list<array{dimension: string, value: string}> $parentPath
     */
    public function canUseBreakdown(
        string $groupBy,
        array $parentPath,
        CampaignStatsQueryFilters $filters
    ): bool {
        if ($filters->requiresScopedCost()) {
            return false;
        }

        foreach ($parentPath as $parent) {
            $dim = (string)($parent['dimension'] ?? '');
            if (!in_array($dim, ['offer', 'landing', 'traffic_source'], true)) {
                return false;
            }
        }

        if (in_array($groupBy, ['offer', 'landing'], true)) {
            return $this->dailySummaryTableExists();
        }
        if ($groupBy === 'date') {
            // Date buckets are UTC summary_date — only safe with no parent and UTC-aligned callers.
            return $parentPath === [] && $this->dailySummaryTableExists();
        }
        // Built-in geo/device columns are not in daily summary — keep raw path
        if (isset(CampaignStatsExpressions::BUILTIN_COLUMN_MAP[$groupBy])) {
            return false;
        }
        if (in_array($groupBy, CampaignStatsExpressions::FIXED_GROUP_BY, true)) {
            return false;
        }
        // Token daily has no offer/landing columns — parents would be wrong.
        if ($parentPath !== []) {
            return false;
        }

        return $this->tokenDailyTableExists();
    }

    /**
     * @return array{visitors: int, lp_clicks: int, conversions: int, manual_cost: float, revenue: float}|null
     */
    public function querySummaryTotals(
        int $campaignId,
        string $dateFrom,
        string $dateTo,
        CampaignStatsQueryFilters $filters
    ): ?array {
        if (!$this->canUseSummary($filters)) {
            return null;
        }

        [$where, $types, $params] = $this->summaryWhere($campaignId, $dateFrom, $dateTo, $filters);

        $sql = "
            SELECT
                COALESCE(SUM(s.clicks), 0) AS visitors,
                COALESCE(SUM(s.lp_clicks), 0) AS lp_clicks,
                COALESCE(SUM(s.direct_clicks), 0) AS direct_clicks,
                COALESCE(SUM(s.conversions), 0) AS conversions,
                COALESCE(SUM(s.cost), 0) AS manual_cost,
                COALESCE(SUM(s.revenue), 0) AS revenue
            FROM clicks_daily_summary s
            WHERE {$where}
        ";

        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            return null;
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        return [
            'visitors' => (int)($row['visitors'] ?? 0),
            'lp_clicks' => (int)($row['lp_clicks'] ?? 0),
            'direct_clicks' => (int)($row['direct_clicks'] ?? 0),
            'conversions' => (int)($row['conversions'] ?? 0),
            'manual_cost' => (float)($row['manual_cost'] ?? 0),
            'revenue' => (float)($row['revenue'] ?? 0),
        ];
    }

    /**
     * @param list<array{dimension: string, value: string}> $parentPath
     * @return list<array<string, mixed>>|null
     */
    public function queryBreakdownRows(
        int $campaignId,
        string $groupBy,
        string $dateFrom,
        string $dateTo,
        array $parentPath,
        CampaignStatsQueryFilters $filters
    ): ?array {
        if (!$this->canUseBreakdown($groupBy, $parentPath, $filters)) {
            return null;
        }

        if (in_array($groupBy, ['date', 'offer', 'landing'], true)) {
            return $this->queryDailySummaryBreakdown($campaignId, $groupBy, $dateFrom, $dateTo, $filters, $parentPath);
        }

        return $this->queryTokenDailyBreakdown($campaignId, $groupBy, $dateFrom, $dateTo, $filters);
    }

    /**
     * @return list<array{day: string, visitors: int, lp_clicks: int, conversions: int, manual_cost: float, revenue: float}>
     */
    public function queryChartDailyRows(
        int $campaignId,
        string $dateFrom,
        string $dateTo,
        CampaignStatsQueryFilters $filters
    ): ?array {
        if (!$this->canUseSummary($filters)) {
            return null;
        }

        [$where, $types, $params] = $this->summaryWhere($campaignId, $dateFrom, $dateTo, $filters);

        $sql = "
            SELECT
                s.summary_date AS day,
                COALESCE(SUM(s.clicks), 0) AS visitors,
                COALESCE(SUM(s.lp_clicks), 0) AS lp_clicks,
                COALESCE(SUM(s.direct_clicks), 0) AS direct_clicks,
                COALESCE(SUM(s.conversions), 0) AS conversions,
                COALESCE(SUM(s.cost), 0) AS manual_cost,
                COALESCE(SUM(s.revenue), 0) AS revenue
            FROM clicks_daily_summary s
            WHERE {$where}
            GROUP BY s.summary_date
            ORDER BY s.summary_date ASC
        ";

        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            return null;
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'day' => (string)($row['day'] ?? ''),
                'visitors' => (int)($row['visitors'] ?? 0),
                'lp_clicks' => (int)($row['lp_clicks'] ?? 0),
                'direct_clicks' => (int)($row['direct_clicks'] ?? 0),
                'conversions' => (int)($row['conversions'] ?? 0),
                'manual_cost' => (float)($row['manual_cost'] ?? 0),
                'revenue' => (float)($row['revenue'] ?? 0),
            ];
        }
        $stmt->close();

        return $rows;
    }

    /**
     * @return array{0: string, 1: string, 2: list<mixed>}
     */
    private function summaryWhere(
        int $campaignId,
        string $dateFrom,
        string $dateTo,
        CampaignStatsQueryFilters $filters,
        array $parentPath = []
    ): array {
        $sql = 's.campaign_id = ? AND s.summary_date >= ? AND s.summary_date <= ?';
        $types = 'iss';
        $params = [$campaignId, $dateFrom, $dateTo];

        if ($filters->trafficSourceId !== null) {
            $sql .= ' AND s.traffic_source_id = ?';
            $types .= 'i';
            $params[] = $filters->trafficSourceId;
        }

        if ($filters->offerId !== null) {
            $sql .= ' AND s.offer_id = ?';
            $types .= 'i';
            $params[] = $filters->offerId;
        }

        if ($filters->landingPageId !== null) {
            $sql .= ' AND s.landing_page_id = ?';
            $types .= 'i';
            $params[] = $filters->landingPageId;
        }

        foreach ($parentPath as $parent) {
            $dim = (string)($parent['dimension'] ?? '');
            $value = (string)($parent['value'] ?? '');
            if ($dim === 'offer') {
                if ($value === 'N/A' || $value === '') {
                    $sql .= ' AND s.offer_id IS NULL';
                } else {
                    $sql .= ' AND s.offer_id = ?';
                    $types .= 'i';
                    $params[] = (int)$value;
                }
            } elseif ($dim === 'landing') {
                if ($value === 'N/A' || $value === '') {
                    $sql .= ' AND s.landing_page_id IS NULL';
                } else {
                    $sql .= ' AND s.landing_page_id = ?';
                    $types .= 'i';
                    $params[] = (int)$value;
                }
            } elseif ($dim === 'traffic_source') {
                if ($value === 'N/A' || $value === '') {
                    $sql .= ' AND s.traffic_source_id IS NULL';
                } else {
                    $sql .= ' AND s.traffic_source_id = ?';
                    $types .= 'i';
                    $params[] = (int)$value;
                }
            }
        }

        return [$sql, $types, $params];
    }

    /**
     * @param list<array{dimension: string, value: string}> $parentPath
     * @return list<array<string, mixed>>
     */
    private function queryDailySummaryBreakdown(
        int $campaignId,
        string $groupBy,
        string $dateFrom,
        string $dateTo,
        CampaignStatsQueryFilters $filters,
        array $parentPath = []
    ): array {
        [$where, $types, $params] = $this->summaryWhere($campaignId, $dateFrom, $dateTo, $filters, $parentPath);

        if ($groupBy === 'date') {
            $sql = "
                SELECT s.summary_date AS group_key,
                       NULL AS group_label,
                       SUM(s.clicks) AS clicks,
                       SUM(s.lp_clicks) AS lp_clicks,
                       SUM(s.direct_clicks) AS direct_clicks,
                       SUM(s.conversions) AS conversions,
                       SUM(s.cost) AS cost,
                       SUM(s.revenue) AS revenue
                FROM clicks_daily_summary s
                WHERE {$where}
                GROUP BY s.summary_date
            ";
        } elseif ($groupBy === 'offer') {
            $sql = "
                SELECT COALESCE(CAST(s.offer_id AS CHAR), 'N/A') AS group_key,
                       o.name AS group_label,
                       SUM(s.clicks) AS clicks,
                       SUM(s.lp_clicks) AS lp_clicks,
                       SUM(s.direct_clicks) AS direct_clicks,
                       SUM(s.conversions) AS conversions,
                       SUM(s.cost) AS cost,
                       SUM(s.revenue) AS revenue
                FROM clicks_daily_summary s
                LEFT JOIN offers o ON o.id = s.offer_id
                WHERE {$where}
                GROUP BY s.offer_id, o.name
            ";
        } else {
            $sql = "
                SELECT COALESCE(CAST(s.landing_page_id AS CHAR), 'N/A') AS group_key,
                       lp.name AS group_label,
                       SUM(s.clicks) AS clicks,
                       SUM(s.lp_clicks) AS lp_clicks,
                       SUM(s.direct_clicks) AS direct_clicks,
                       SUM(s.conversions) AS conversions,
                       SUM(s.cost) AS cost,
                       SUM(s.revenue) AS revenue
                FROM clicks_daily_summary s
                LEFT JOIN landing_pages lp ON lp.id = s.landing_page_id
                WHERE {$where}
                GROUP BY s.landing_page_id, lp.name
            ";
        }

        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            return [];
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $formatted = CampaignStatsExpressions::formatMetricsRow(
                (string)($row['group_key'] ?? 'N/A'),
                $row['group_label'] ?? null,
                $row
            );
            $formatted['group_key'] = $formatted['group'];
            $formatted['name'] = $formatted['group_label'] ?? $formatted['group'];
            $direct = (int)($row['direct_clicks'] ?? 0);
            $formatted['direct_clicks'] = $direct;
            $formatted['action_clicks'] = (int)($row['lp_clicks'] ?? 0) + $direct;
            $rows[] = $formatted;
        }
        $stmt->close();

        return $rows;
    }

    /**
     * Distinct filter entities from clicks_daily_summary (summary-first meta discovery).
     * Uses UTC summary_date bounds for the converted report window.
     *
     * @param 'traffic_source'|'offer'|'landing' $kind
     * @return list<array{id: int, name: string}>|null null when summary table unavailable
     */
    public function queryMetaDistinct(
        int $campaignId,
        string $dateFrom,
        string $dateTo,
        string $kind
    ): ?array {
        if (!$this->dailySummaryTableExists()) {
            return null;
        }

        if ($kind === 'traffic_source') {
            $sql = "
                SELECT DISTINCT s.traffic_source_id AS id, ts.name
                FROM clicks_daily_summary s
                LEFT JOIN traffic_sources ts ON ts.id = s.traffic_source_id
                WHERE s.campaign_id = ? AND s.summary_date >= ? AND s.summary_date <= ?
                  AND s.traffic_source_id IS NOT NULL AND s.traffic_source_id > 0
                ORDER BY ts.name
            ";
        } elseif ($kind === 'offer') {
            $sql = "
                SELECT DISTINCT s.offer_id AS id, o.name
                FROM clicks_daily_summary s
                LEFT JOIN offers o ON o.id = s.offer_id
                WHERE s.campaign_id = ? AND s.summary_date >= ? AND s.summary_date <= ?
                  AND s.offer_id IS NOT NULL AND s.offer_id > 0
                ORDER BY o.name
            ";
        } elseif ($kind === 'landing') {
            $sql = "
                SELECT DISTINCT s.landing_page_id AS id, lp.name
                FROM clicks_daily_summary s
                LEFT JOIN landing_pages lp ON lp.id = s.landing_page_id
                WHERE s.campaign_id = ? AND s.summary_date >= ? AND s.summary_date <= ?
                  AND s.landing_page_id IS NOT NULL AND s.landing_page_id > 0
                ORDER BY lp.name
            ";
        } else {
            return null;
        }

        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            return null;
        }

        $stmt->bind_param('iss', $campaignId, $dateFrom, $dateTo);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $fallback = $kind === 'traffic_source' ? 'Unknown'
                    : ($kind === 'offer' ? 'Offer #' . $id : 'Landing #' . $id);
                $rows[] = [
                    'id' => $id,
                    'name' => (string)($row['name'] ?? $fallback),
                ];
            }
        }
        $stmt->close();

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function queryTokenDailyBreakdown(
        int $campaignId,
        string $tokenParam,
        string $dateFrom,
        string $dateTo,
        CampaignStatsQueryFilters $filters
    ): array {
        $sql = "
            SELECT token_value AS group_key,
                   SUM(visitors) AS clicks,
                   SUM(lp_clicks) AS lp_clicks,
                   SUM(conversions) AS conversions,
                   SUM(cost) AS cost,
                   SUM(revenue) AS revenue
            FROM clicks_stats_by_token_daily
            WHERE campaign_id = ? AND summary_date >= ? AND summary_date <= ? AND token_param = ?
        ";
        $types = 'isss';
        $params = [$campaignId, $dateFrom, $dateTo, $tokenParam];

        if ($filters->trafficSourceId !== null) {
            $sql .= ' AND traffic_source_id = ?';
            $types .= 'i';
            $params[] = $filters->trafficSourceId;
        }

        $sql .= ' GROUP BY token_value';

        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            return [];
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $formatted = CampaignStatsExpressions::formatMetricsRow(
                (string)($row['group_key'] ?? 'N/A'),
                null,
                $row
            );
            $formatted['group_key'] = $formatted['group'];
            $formatted['name'] = $formatted['group'];
            $rows[] = $formatted;
        }
        $stmt->close();

        return $rows;
    }
}
