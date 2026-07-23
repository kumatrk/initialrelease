<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

use mysqli;
use SimpleKuma\Database\ClicksTableResolver;
use SimpleKuma\Entity\Campaign;
use SimpleKuma\Entity\TrafficSource;
use SimpleKuma\Utils\Formatter;

/**
 * Per-dimension campaign stats for API v1 group_by.
 */
class CampaignStatsBreakdownService
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, totals: array<string, mixed>}
     */
    public function getGroupedStats(
        int $campaignId,
        string $groupBy,
        string $dateFrom,
        string $dateTo,
        string $timezone,
        int $page,
        int $perPage,
        string $sort = 'clicks',
        string $order = 'desc'
    ): array {
        $campaign = (new Campaign($this->db))->getById($campaignId);
        if ($campaign === null) {
            return ['rows' => [], 'total' => 0, 'totals' => []];
        }

        $this->assertValidGroupBy($groupBy, $campaign);

        $utcRange = Formatter::convertDateRangeToUTC($dateFrom, $dateTo, $timezone);

        // Drill-down rows must include scoped FB/GA cost — always aggregate from raw clicks.
        if (in_array($groupBy, ['date', 'offer', 'landing'], true)) {
            if ($groupBy === 'date') {
                $allRows = $this->queryDateFromClicks(
                    $campaignId,
                    $utcRange['from'],
                    $utcRange['to'],
                    $timezone,
                    $dateFrom
                );
            } else {
                $allRows = $this->queryRawClicksGroup(
                    $campaignId,
                    $groupBy,
                    $utcRange['from'],
                    $utcRange['to']
                );
            }
        } else {
            $allRows = $this->queryGroupedClicks(
                $campaignId,
                $groupBy,
                $utcRange['from'],
                $utcRange['to'],
                '',
                '',
                false
            );
        }

        if ($groupBy === 'date') {
            $allRows = CampaignStatsExpressions::fillDateRangeRows($allRows, $dateFrom, $dateTo);
        }

        $sortCol = CampaignStatsExpressions::sortColumn($sort);
        $orderDir = strtolower($order) === 'asc' ? 1 : -1;

        usort($allRows, static function (array $a, array $b) use ($sortCol, $orderDir): int {
            $av = $a[$sortCol] ?? 0;
            $bv = $b[$sortCol] ?? 0;
            if ($av === $bv) {
                return strcmp((string)($a['group'] ?? ''), (string)($b['group'] ?? '')) * $orderDir;
            }
            if (is_numeric($av) && is_numeric($bv)) {
                return ($av <=> $bv) * $orderDir;
            }

            return strcmp((string)$av, (string)$bv) * $orderDir;
        });

        $total = count($allRows);
        $offset = ($page - 1) * $perPage;
        $pageRows = array_slice($allRows, $offset, $perPage);

        $summaryService = new CampaignStatsService($this->db);
        $summaryRows = $summaryService->getCampaignStats($campaignId, $dateFrom, $dateTo, $timezone);
        $totals = [];
        if ($summaryRows !== []) {
            $s = $summaryRows[0];
            $totals = CampaignStatsExpressions::formatMetricsRow('', null, [
                'clicks' => (int)$s['clicks'],
                'lp_clicks' => (int)($s['lp_clicks'] ?? 0),
                'conversions' => (int)$s['conversions'],
                'cost' => (float)$s['cost'],
                'revenue' => (float)$s['revenue'],
            ]);
            unset($totals['group']);
        }

        return [
            'rows' => $pageRows,
            'total' => $total,
            'totals' => $totals,
        ];
    }

    /**
     * @param array<string, mixed> $campaign
     */
    public function assertValidGroupBy(
        string $groupBy,
        array $campaign,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        string $timezone = 'UTC'
    ): void {
        $allowed = $this->allowedTokensForCampaign($campaign, $dateFrom, $dateTo, $timezone);
        if (!in_array($groupBy, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Invalid group_by '{$groupBy}'. Choose a dimension available for this campaign's traffic source."
            );
        }
    }

    /**
     * @param array<string, mixed> $campaign
     * @return list<string>
     */
    public function allowedTokensForCampaign(
        array $campaign,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        string $timezone = 'UTC'
    ): array {
        $tsEntity = new TrafficSource($this->db);

        return CampaignStatsDimensionRegistry::allowedKeysForCampaign(
            $campaign,
            $tsEntity,
            $this->db,
            $dateFrom,
            $dateTo,
            $timezone
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function queryDailySummary(int $campaignId, string $dateFrom, string $dateTo, string $dimension): array
    {
        if (!$this->tableExists('clicks_daily_summary')) {
            return $this->queryGroupedClicks($campaignId, $dimension, $dateFrom . ' 00:00:00', $dateTo . ' 23:59:59', $dateFrom, $dateTo);
        }

        if ($dimension === 'date') {
            $sql = "
                SELECT summary_date AS group_key,
                       NULL AS group_label,
                       SUM(clicks) AS clicks,
                       SUM(lp_clicks) AS lp_clicks,
                       SUM(conversions) AS conversions,
                       SUM(cost) AS cost,
                       SUM(revenue) AS revenue
                FROM clicks_daily_summary
                WHERE campaign_id = ? AND summary_date >= ? AND summary_date <= ?
                GROUP BY summary_date
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('iss', $campaignId, $dateFrom, $dateTo);
        } elseif ($dimension === 'offer') {
            $sql = "
                SELECT COALESCE(CAST(s.offer_id AS CHAR), 'N/A') AS group_key,
                       o.name AS group_label,
                       SUM(s.clicks) AS clicks,
                       SUM(s.lp_clicks) AS lp_clicks,
                       SUM(s.conversions) AS conversions,
                       SUM(s.cost) AS cost,
                       SUM(s.revenue) AS revenue
                FROM clicks_daily_summary s
                LEFT JOIN offers o ON o.id = s.offer_id
                WHERE s.campaign_id = ? AND s.summary_date >= ? AND s.summary_date <= ?
                GROUP BY s.offer_id, o.name
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('iss', $campaignId, $dateFrom, $dateTo);
        } else {
            $sql = "
                SELECT COALESCE(CAST(s.landing_page_id AS CHAR), 'N/A') AS group_key,
                       lp.name AS group_label,
                       SUM(s.clicks) AS clicks,
                       SUM(s.lp_clicks) AS lp_clicks,
                       SUM(s.conversions) AS conversions,
                       SUM(s.cost) AS cost,
                       SUM(s.revenue) AS revenue
                FROM clicks_daily_summary s
                LEFT JOIN landing_pages lp ON lp.id = s.landing_page_id
                WHERE s.campaign_id = ? AND s.summary_date >= ? AND s.summary_date <= ?
                GROUP BY s.landing_page_id, lp.name
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('iss', $campaignId, $dateFrom, $dateTo);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = CampaignStatsExpressions::formatMetricsRow(
                (string)($row['group_key'] ?? 'N/A'),
                $row['group_label'] ?? null,
                $row
            );
        }
        $stmt->close();

        return $rows;
    }

    /**
     * @param list<array{type: 'preagg'|'raw', from: string, to: string}> $segments
     * @return list<array<string, mixed>>
     */
    private function queryDailySummaryBlended(int $campaignId, string $groupBy, array $segments): array
    {
        $parts = [];
        foreach ($segments as $segment) {
            if ($segment['type'] === 'preagg') {
                $parts[] = $this->queryDailySummary($campaignId, $segment['from'], $segment['to'], $groupBy);
            } else {
                $parts[] = $this->queryRawClicksGroup($campaignId, $groupBy, $segment['from'], $segment['to']);
            }
        }

        return TimezoneSummaryBlend::mergeBreakdownRows(...$parts);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function queryGroupedClicks(
        int $campaignId,
        string $groupBy,
        string $utcFrom,
        string $utcTo,
        string $summaryDateFrom,
        string $summaryDateTo,
        bool $usePreAgg = true,
        ?array $segments = null
    ): array {
        if (
            $groupBy !== 'country' && $groupBy !== 'browser' && $groupBy !== 'os'
            && $groupBy !== 'landing' && $groupBy !== 'offer' && $groupBy !== 'date'
            && $this->canUseTokenAggregate($groupBy)
        ) {
            if ($segments !== null && TimezoneSummaryBlend::resolveSource($segments) === 'pre_aggregate_hybrid') {
                $parts = [];
                foreach ($segments as $segment) {
                    if ($segment['type'] === 'preagg') {
                        $parts[] = $this->queryTokenAggregate($campaignId, $groupBy, $segment['from'], $segment['to']);
                    } else {
                        $parts[] = $this->queryRawClicksGroup($campaignId, $groupBy, $segment['from'], $segment['to']);
                    }
                }

                return TimezoneSummaryBlend::mergeBreakdownRows(...$parts);
            }

            if ($usePreAgg) {
                $aggRows = $this->queryTokenAggregate($campaignId, $groupBy, $summaryDateFrom, $summaryDateTo);
                if ($aggRows !== []) {
                    return $aggRows;
                }
            }
        }

        if ($groupBy === 'date') {
            return $this->queryDateFromClicks($campaignId, $utcFrom, $utcTo);
        }

        return $this->queryRawClicksGroup($campaignId, $groupBy, $utcFrom, $utcTo);
    }

    private function canUseTokenAggregate(string $groupBy): bool
    {
        if (in_array($groupBy, ['date', 'country', 'browser', 'os', 'landing', 'offer'], true)) {
            return false;
        }

        if (isset(CampaignStatsExpressions::BUILTIN_COLUMN_MAP[$groupBy])) {
            return false;
        }

        return $this->tableExists('clicks_stats_by_token_daily');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function queryTokenAggregate(int $campaignId, string $tokenParam, string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT token_value AS group_key,
                   SUM(visitors) AS clicks,
                   SUM(lp_clicks) AS lp_clicks,
                   SUM(conversions) AS conversions,
                   SUM(cost) AS cost,
                   SUM(revenue) AS revenue
            FROM clicks_stats_by_token_daily
            WHERE campaign_id = ? AND summary_date >= ? AND summary_date <= ? AND token_param = ?
            GROUP BY token_value
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('isss', $campaignId, $dateFrom, $dateTo, $tokenParam);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = CampaignStatsExpressions::formatMetricsRow(
                (string)($row['group_key'] ?? 'N/A'),
                null,
                $row
            );
        }
        $stmt->close();

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function queryDateFromClicks(
        int $campaignId,
        string $utcFrom,
        string $utcTo,
        string $timezone = 'UTC',
        string $dateFrom = ''
    ): array {
        $clicksTable = ClicksTableResolver::getStatsTable($this->db);
        $visitors = CampaignStatsExpressions::visitorCountExpr();
        $lpClicks = CampaignStatsExpressions::lpClicksCountExpr();
        $directClicks = CampaignStatsExpressions::directClicksCountExpr();
        $conversions = CampaignStatsExpressions::conversionsCountExpr();
        $fbCase = CampaignStatsCostSql::perClickFacebookCostCase($clicksTable);
        $gaCase = CampaignStatsCostSql::perClickGoogleCostCase($clicksTable);
        $fbJoins = CampaignStatsCostSql::scopedApiCostJoins($clicksTable)['joins'];
        $refDate = $dateFrom !== '' ? $dateFrom : substr($utcFrom, 0, 10);
        $timezoneOffset = CampaignStatsExpressions::mysqlTimezoneOffset($timezone, $refDate);
        $dayExpr = "DATE(CONVERT_TZ(cl.ts, '+00:00', '{$timezoneOffset}'))";

        $sql = "
            SELECT {$dayExpr} AS group_key,
                   {$visitors} AS clicks,
                   {$lpClicks} AS lp_clicks,
                   {$directClicks} AS direct_clicks,
                   {$conversions} AS conversions,
                   COALESCE(SUM(cl.cost), 0) AS manual_cost,
                   COALESCE(SUM({$fbCase}), 0) AS fb_cost,
                   COALESCE(SUM({$gaCase}), 0) AS ga_cost,
                   COALESCE(SUM(conv.revenue_sum), 0) AS revenue
            FROM {$clicksTable} cl
            INNER JOIN campaigns cp ON cl.campaign_id = cp.id
            LEFT JOIN traffic_sources ts ON cp.traffic_source_id = ts.id
            " . CampaignStatsExpressions::conversionsAggJoin() . "
            {$fbJoins}
            WHERE cl.campaign_id = ? AND cl.ts >= ? AND cl.ts <= ?
            GROUP BY {$dayExpr}
        ";
        $types = 'iss';
        $params = [$campaignId, $utcFrom, $utcTo];
        [$types, $params] = CampaignStatsCostSql::mergeScopedApiJoinDateBinds($utcFrom, $utcTo, $types, $params);
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $cost = (float)($row['manual_cost'] ?? 0) + (float)($row['fb_cost'] ?? 0) + (float)($row['ga_cost'] ?? 0);
            $formatted = CampaignStatsExpressions::formatMetricsRow(
                (string)($row['group_key'] ?? 'N/A'),
                null,
                [
                    'clicks' => (int)($row['clicks'] ?? 0),
                    'lp_clicks' => (int)($row['lp_clicks'] ?? 0),
                    'conversions' => (int)($row['conversions'] ?? 0),
                    'cost' => $cost,
                    'revenue' => (float)($row['revenue'] ?? 0),
                ]
            );
            $formatted['direct_clicks'] = (int)($row['direct_clicks'] ?? 0);
            $formatted['action_clicks'] = (int)($row['lp_clicks'] ?? 0) + (int)($row['direct_clicks'] ?? 0);
            $rows[] = $formatted;
        }
        $stmt->close();

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function queryRawClicksGroup(int $campaignId, string $groupBy, string $utcFrom, string $utcTo): array
    {
        $clicksTable = ClicksTableResolver::getStatsTable($this->db);
        $parts = CampaignStatsExpressions::groupKeyParts($groupBy);
        $groupExpr = $parts['expr'];
        $labelExpr = $parts['label_expr'];
        $visitors = CampaignStatsExpressions::visitorCountExpr();
        $lpClicks = CampaignStatsExpressions::lpClicksCountExpr();
        $directClicks = CampaignStatsExpressions::directClicksCountExpr();
        $conversions = CampaignStatsExpressions::conversionsCountExpr();
        $fbCase = CampaignStatsCostSql::perClickFacebookCostCase($clicksTable);
        $gaCase = CampaignStatsCostSql::perClickGoogleCostCase($clicksTable);
        $fbJoins = CampaignStatsCostSql::scopedApiCostJoins($clicksTable)['joins'];

        $selectLabel = $labelExpr !== null ? ", {$labelExpr} AS group_label" : ', NULL AS group_label';
        $joinOffer = ($groupBy === 'offer') ? 'LEFT JOIN offers o ON o.id = cl.offer_id' : '';
        $joinLp = ($groupBy === 'landing') ? 'LEFT JOIN landing_pages lp ON lp.id = cl.landing_page_id' : '';
        $groupBySql = $groupExpr . ($labelExpr !== null ? ", {$labelExpr}" : '');

        $sql = "
            SELECT {$groupExpr} AS group_key
                   {$selectLabel},
                   {$visitors} AS clicks,
                   {$lpClicks} AS lp_clicks,
                   {$directClicks} AS direct_clicks,
                   {$conversions} AS conversions,
                   COALESCE(SUM(cl.cost), 0) AS manual_cost,
                   COALESCE(SUM({$fbCase}), 0) AS fb_cost,
                   COALESCE(SUM({$gaCase}), 0) AS ga_cost,
                   COALESCE(SUM(conv.revenue_sum), 0) AS revenue
            FROM {$clicksTable} cl
            INNER JOIN campaigns cp ON cl.campaign_id = cp.id
            LEFT JOIN traffic_sources ts ON cp.traffic_source_id = ts.id
            " . CampaignStatsExpressions::conversionsAggJoin() . "
            {$joinOffer}
            {$joinLp}
            {$fbJoins}
            WHERE cl.campaign_id = ? AND cl.ts >= ? AND cl.ts <= ?
            GROUP BY {$groupBySql}
        ";

        $types = 'iss';
        $params = [$campaignId, $utcFrom, $utcTo];
        [$types, $params] = CampaignStatsCostSql::mergeScopedApiJoinDateBinds($utcFrom, $utcTo, $types, $params);

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $cost = (float)($row['manual_cost'] ?? 0) + (float)($row['fb_cost'] ?? 0) + (float)($row['ga_cost'] ?? 0);
            $formatted = CampaignStatsExpressions::formatMetricsRow(
                (string)($row['group_key'] ?? 'N/A'),
                $row['group_label'] ?? null,
                [
                    'clicks' => (int)($row['clicks'] ?? 0),
                    'lp_clicks' => (int)($row['lp_clicks'] ?? 0),
                    'conversions' => (int)($row['conversions'] ?? 0),
                    'cost' => $cost,
                    'revenue' => (float)($row['revenue'] ?? 0),
                ]
            );
            $formatted['direct_clicks'] = (int)($row['direct_clicks'] ?? 0);
            $formatted['action_clicks'] = (int)($row['lp_clicks'] ?? 0) + (int)($row['direct_clicks'] ?? 0);
            $rows[] = $formatted;
        }
        $stmt->close();

        return $rows;
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1"
        );
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();

        return $exists;
    }
}
