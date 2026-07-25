<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

use mysqli;
use SimpleKuma\Database\ClicksTableResolver;
use SimpleKuma\Facebook\FacebookCostAggregator;
use SimpleKuma\Utils\Formatter;

/**
 * Campaign list date-range metrics — prefers clicks_daily_summary + selective API costs.
 * Raw fallback matches DashboardStatsService (COUNT(*) + scoped conversions).
 */
final class CampaignListStatsService
{
    private mysqli $db;
    private ?bool $summaryExists = null;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    private function includedClickSql(string $table, string $alias = 'cl'): string
    {
        $predicate = StatsExclusionFlag::includedWhere($this->db, $alias, $table);

        return $predicate !== '' ? ' AND ' . $predicate : '';
    }

    /**
     * @param list<int> $campaignIds
     * @return array<int, array{
     *   views: int,
     *   lp_clicks: int,
     *   direct_clicks: int,
     *   conversions: int,
     *   cost: float,
     *   revenue: float,
     *   profit: float,
     *   roi: float
     * }>
     */
    public function loadStatsForCampaignIds(
        array $campaignIds,
        string $dateFrom,
        string $dateTo,
        string $userTimezone
    ): array {
        $campaignIds = array_values(array_unique(array_filter(array_map('intval', $campaignIds))));
        $empty = [];
        foreach ($campaignIds as $id) {
            $empty[$id] = $this->emptyStats();
        }
        if ($campaignIds === []) {
            return $empty;
        }

        $utcRange = Formatter::convertDateRangeToUTC($dateFrom, $dateTo, $userTimezone);
        $utcFrom = $utcRange['from'];
        $utcTo = $utcRange['to'];
        // summary_date is the UTC calendar date of the click (DATE(ts) with session +00:00).
        // Only use pre-agg when those UTC days align with the user calendar (no edge-day overcount).
        $summaryDateFrom = substr($utcFrom, 0, 10);
        $summaryDateTo = substr($utcTo, 0, 10);

        if (!$this->dailySummaryExists()) {
            $metrics = $this->queryMetricsFromRaw($campaignIds, $utcFrom, $utcTo);
        } elseif (Formatter::canUseUtcSummaryDateRange($dateFrom, $dateTo, $utcFrom, $utcTo)) {
            if (TimezoneSummaryBlend::isSummaryReliable($this->db, $campaignIds, $summaryDateFrom, $summaryDateTo)) {
                $metrics = $this->queryMetricsFromSummary($campaignIds, $summaryDateFrom, $summaryDateTo);
            } else {
                $metrics = $this->queryMetricsFromRaw($campaignIds, $utcFrom, $utcTo);
            }
        } else {
            if (!TimezoneSummaryBlend::areSegmentsReliable(
                $this->db,
                $campaignIds,
                TimezoneSummaryBlend::segments($utcFrom, $utcTo)
            )) {
                $metrics = $this->queryMetricsFromRaw($campaignIds, $utcFrom, $utcTo);
            } else {
                $metrics = $this->queryMetricsBlended($campaignIds, $utcFrom, $utcTo);
            }
        }

        // Only campaigns with activity need cost resolution; skip empty rows.
        $idsForCost = [];
        foreach ($campaignIds as $id) {
            $m = $metrics[$id] ?? null;
            if ($m === null) {
                continue;
            }
            if ((int)$m['views'] > 0 || (float)$m['manual_cost'] > 0 || (float)$m['revenue'] > 0) {
                $idsForCost[] = $id;
            }
        }

        // Match DashboardStatsService: FB/GA aggregators only for API-cost campaigns.
        // Manual campaigns keep summary/raw manual_cost (no 10s+ allocator scan).
        $costMaps = $this->batchApiCosts($idsForCost, $utcFrom, $utcTo, $userTimezone);
        $fbCostMap = $costMaps['fb'];
        $gaCostMap = $costMaps['ga'];

        $out = $empty;
        foreach ($campaignIds as $id) {
            $m = $metrics[$id] ?? [
                'views' => 0,
                'lp_clicks' => 0,
                'direct_clicks' => 0,
                'conversions' => 0,
                'manual_cost' => 0.0,
                'revenue' => 0.0,
            ];
            $manual = (float)$m['manual_cost'];
            $revenue = (float)$m['revenue'];
            $cost = $manual;
            if (isset($fbCostMap[$id])) {
                // Aggregator returns FB API + manual; peel manual already counted.
                $apiTotal = (float)$fbCostMap[$id];
                $cost = $manual + max(0.0, $apiTotal - $manual);
            }
            if (isset($gaCostMap[$id])) {
                $cost += (float)$gaCostMap[$id];
            }
            $profit = $revenue - $cost;
            $roi = $cost > 0
                ? (($revenue - $cost) / $cost) * 100
                : ($revenue > 0 ? 99999.9 : 0.0);

            $out[$id] = [
                'views' => (int)$m['views'],
                'lp_clicks' => (int)$m['lp_clicks'],
                'direct_clicks' => (int)$m['direct_clicks'],
                'conversions' => (int)$m['conversions'],
                'cost' => $cost,
                'revenue' => $revenue,
                'profit' => $profit,
                'roi' => $roi,
            ];
        }

        return $out;
    }

    /**
     * FB/GA cost only for campaigns that use integrated API spend (same split as dashboard).
     *
     * @param list<int> $campaignIds
     * @return array{fb: array<int, float>, ga: array<int, float>}
     */
    private function batchApiCosts(
        array $campaignIds,
        string $utcFrom,
        string $utcTo,
        string $userTimezone
    ): array {
        if ($campaignIds === []) {
            return ['fb' => [], 'ga' => []];
        }

        $fbIds = [];
        $gaIds = [];
        $placeholders = implode(',', array_fill(0, count($campaignIds), '?'));
        $types = str_repeat('i', count($campaignIds));
        $stmt = $this->db->prepare(
            "SELECT id,
                    facebook_marketing_ad_account_id,
                    google_ads_integration_id
             FROM campaigns
             WHERE id IN ({$placeholders})"
        );
        if ($stmt !== false) {
            $stmt->bind_param($types, ...$campaignIds);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $id = (int)$row['id'];
                if (!empty($row['facebook_marketing_ad_account_id'])) {
                    $fbIds[] = $id;
                }
                if (!empty($row['google_ads_integration_id'])) {
                    $gaIds[] = $id;
                }
            }
            $stmt->close();
        }

        $fbMap = [];
        if ($fbIds !== []) {
            try {
                $apiFb = (new FacebookCostAggregator($this->db))
                    ->getAggregatedCostsByCampaignIds($fbIds, $utcFrom, $utcTo, $userTimezone);
                foreach ($apiFb as $cid => $amount) {
                    $fbMap[(int)$cid] = (float)$amount;
                }
            } catch (\Exception $e) {
                error_log('CampaignListStatsService: batch FB cost error: ' . $e->getMessage());
            }
        }

        $gaMap = [];
        if ($gaIds !== []) {
            try {
                $apiGa = (new \SimpleKuma\GoogleAds\GoogleAdsCostAggregator($this->db))
                    ->getGoogleAdsCostsByCampaignIds($gaIds, $utcFrom, $utcTo);
                foreach ($apiGa as $cid => $amount) {
                    $gaMap[(int)$cid] = (float)$amount;
                }
            } catch (\Exception $e) {
                error_log('CampaignListStatsService: batch Google Ads cost error: ' . $e->getMessage());
            }
        }

        return ['fb' => $fbMap, 'ga' => $gaMap];
    }

    /**
     * @return array{views: int, lp_clicks: int, direct_clicks: int, conversions: int, cost: float, revenue: float, profit: float, roi: float}
     */
    private function emptyStats(): array
    {
        return [
            'views' => 0,
            'lp_clicks' => 0,
            'direct_clicks' => 0,
            'conversions' => 0,
            'cost' => 0.0,
            'revenue' => 0.0,
            'profit' => 0.0,
            'roi' => 0.0,
        ];
    }

    private function dailySummaryExists(): bool
    {
        if ($this->summaryExists !== null) {
            return $this->summaryExists;
        }
        $result = $this->db->query(
            "SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clicks_daily_summary' LIMIT 1"
        );
        $this->summaryExists = $result !== false && $result->num_rows > 0;

        return $this->summaryExists;
    }

    /**
     * @param list<int> $campaignIds
     * @return array<int, array{views: int, lp_clicks: int, direct_clicks: int, conversions: int, manual_cost: float, revenue: float}>
     */
    private function queryMetricsFromSummary(array $campaignIds, string $dateFrom, string $dateTo): array
    {
        $placeholders = implode(',', array_fill(0, count($campaignIds), '?'));
        $types = str_repeat('i', count($campaignIds)) . 'ss';
        $params = array_merge($campaignIds, [$dateFrom, $dateTo]);

        $sql = "
            SELECT
                s.campaign_id,
                COALESCE(SUM(s.clicks), 0) AS views,
                COALESCE(SUM(s.lp_clicks), 0) AS lp_clicks,
                COALESCE(SUM(s.direct_clicks), 0) AS direct_clicks,
                COALESCE(SUM(s.conversions), 0) AS conversions,
                COALESCE(SUM(s.cost), 0) AS manual_cost,
                COALESCE(SUM(s.revenue), 0) AS revenue
            FROM clicks_daily_summary s
            WHERE s.campaign_id IN ({$placeholders})
              AND s.summary_date >= ?
              AND s.summary_date <= ?
            GROUP BY s.campaign_id
        ";

        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();

        $out = [];
        foreach ($rows as $row) {
            $out[(int)$row['campaign_id']] = [
                'views' => (int)$row['views'],
                'lp_clicks' => (int)$row['lp_clicks'],
                'direct_clicks' => (int)$row['direct_clicks'],
                'conversions' => (int)$row['conversions'],
                'manual_cost' => (float)$row['manual_cost'],
                'revenue' => (float)$row['revenue'],
            ];
        }

        return $out;
    }

    /**
     * Fast raw path aligned with DashboardStatsService (COUNT(*) + scoped conversions).
     * Avoids COUNT(DISTINCT) + full conversionsAggJoin which scanned ~60s on 800k clicks.
     *
     * @param list<int> $campaignIds
     * @return array<int, array{views: int, lp_clicks: int, direct_clicks: int, conversions: int, manual_cost: float, revenue: float}>
     */
    private function queryMetricsFromRaw(array $campaignIds, string $utcFrom, string $utcTo): array
    {
        $placeholders = implode(',', array_fill(0, count($campaignIds), '?'));
        $idTypes = str_repeat('i', count($campaignIds));
        $clicksTable = ClicksTableResolver::getStatsTable($this->db);
        $includedSql = $this->includedClickSql($clicksTable);

        $clickTypes = $idTypes . 'ss';
        $clickParams = array_merge($campaignIds, [$utcFrom, $utcTo]);
        // Covering-index friendly: the persisted boolean is covered; never reintroduce
        // ua/ad_id/IP predicates because those force full row reads.
        $clickSql = "
            SELECT
                cl.campaign_id,
                COUNT(*) AS views,
                SUM(CASE WHEN cl.lp_click = 1 AND cl.landing_page_id IS NOT NULL THEN 1 ELSE 0 END) AS lp_clicks,
                SUM(CASE WHEN cl.lp_click = 1 AND cl.landing_page_id IS NULL THEN 1 ELSE 0 END) AS direct_clicks,
                COALESCE(SUM(cl.cost), 0) AS manual_cost
            FROM {$clicksTable} cl
            WHERE cl.campaign_id IN ({$placeholders})
              AND cl.ts >= ? AND cl.ts <= ?
              {$includedSql}
            GROUP BY cl.campaign_id
        ";

        $out = [];
        $stmt = $this->db->prepare($clickSql);
        if ($stmt !== false) {
            $stmt->bind_param($clickTypes, ...$clickParams);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmt->close();
            foreach ($rows as $row) {
                $cid = (int)$row['campaign_id'];
                $out[$cid] = [
                    'views' => (int)$row['views'],
                    'lp_clicks' => (int)$row['lp_clicks'],
                    'direct_clicks' => (int)$row['direct_clicks'],
                    'conversions' => 0,
                    'manual_cost' => (float)$row['manual_cost'],
                    'revenue' => 0.0,
                ];
            }
        }

        $convTypes = 'ss' . $idTypes;
        $convParams = array_merge([$utcFrom, $utcTo], $campaignIds);
        $convSql = "
            SELECT
                cl.campaign_id,
                COUNT(*) AS conversions,
                COALESCE(SUM(COALESCE(cv.payout, cv.value)), 0) AS revenue
            FROM conversions cv
            INNER JOIN {$clicksTable} cl ON cl.click_id = cv.click_id
            WHERE cl.ts >= ? AND cl.ts <= ?
              AND cl.campaign_id IN ({$placeholders})
              {$includedSql}
            GROUP BY cl.campaign_id
        ";
        $convStmt = $this->db->prepare($convSql);
        if ($convStmt !== false) {
            $convStmt->bind_param($convTypes, ...$convParams);
            $convStmt->execute();
            $convRows = $convStmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $convStmt->close();
            foreach ($convRows as $row) {
                $cid = (int)$row['campaign_id'];
                if (!isset($out[$cid])) {
                    $out[$cid] = [
                        'views' => 0,
                        'lp_clicks' => 0,
                        'direct_clicks' => 0,
                        'conversions' => 0,
                        'manual_cost' => 0.0,
                        'revenue' => 0.0,
                    ];
                }
                $out[$cid]['conversions'] = (int)$row['conversions'];
                $out[$cid]['revenue'] = (float)$row['revenue'];
            }
        }

        return $out;
    }

    /**
     * Pre-agg full UTC days + raw scans for partial edge days (timezone-accurate, fast on 7-day+).
     *
     * @param list<int> $campaignIds
     * @return array<int, array{views: int, lp_clicks: int, direct_clicks: int, conversions: int, manual_cost: float, revenue: float}>
     */
    private function queryMetricsBlended(array $campaignIds, string $utcFrom, string $utcTo): array
    {
        $segments = TimezoneSummaryBlend::segments($utcFrom, $utcTo);
        foreach ($segments as $segment) {
            if ($segment['type'] === 'preagg'
                && !TimezoneSummaryBlend::isSummaryReliable($this->db, $campaignIds, $segment['from'], $segment['to'])
            ) {
                return $this->queryMetricsFromRaw($campaignIds, $utcFrom, $utcTo);
            }
        }

        $maps = [];
        foreach ($segments as $segment) {
            if ($segment['type'] === 'preagg') {
                $maps[] = $this->queryMetricsFromSummary($campaignIds, $segment['from'], $segment['to']);
            } else {
                $maps[] = $this->queryMetricsFromRaw($campaignIds, $segment['from'], $segment['to']);
            }
        }

        return TimezoneSummaryBlend::mergeCampaignMetrics(...$maps);
    }
}
