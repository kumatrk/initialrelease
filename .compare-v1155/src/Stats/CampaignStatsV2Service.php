<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

use mysqli;
use SimpleKuma\Database\ClicksTableResolver;
use SimpleKuma\Entity\Campaign;
use SimpleKuma\Entity\TrafficSource;
use SimpleKuma\Facebook\FacebookCostAggregator;
use SimpleKuma\Utils\Formatter;

/**
 * Campaign Stats V2 — summary, chart, and hierarchical breakdown with accurate cost.
 */
class CampaignStatsV2Service
{
    private mysqli $db;
    private string $clicksTable;
    private CampaignStatsBreakdownService $breakdownService;
    private CampaignStatsPreAggregateReader $preAggregateReader;

    /** Max calendar days for raw-click breakdown queries. */
    public const MAX_BREAKDOWN_DAYS = 90;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->clicksTable = ClicksTableResolver::getStatsTable($db);
        $this->breakdownService = new CampaignStatsBreakdownService($db);
        $this->preAggregateReader = new CampaignStatsPreAggregateReader($db);
    }

    /**
     * @return array<string, mixed>
     */
    public function getSummary(
        int $campaignId,
        string $dateFrom,
        string $dateTo,
        string $timezone,
        ?CampaignStatsQueryFilters $filters = null
    ): array {
        $filters ??= new CampaignStatsQueryFilters();
        $utcRange = Formatter::convertDateRangeToUTC($dateFrom, $dateTo, $timezone);
        $segments = TimezoneSummaryBlend::segments($utcRange['from'], $utcRange['to']);
        $campaign = (new Campaign($this->db))->getById($campaignId);

        if ($this->preAggregateReader->canUseSummary($filters)) {
            if (Formatter::canUseUtcSummaryDateRange($dateFrom, $dateTo, $utcRange['from'], $utcRange['to'])) {
                $summaryDateFrom = substr($utcRange['from'], 0, 10);
                $summaryDateTo = substr($utcRange['to'], 0, 10);
                if (TimezoneSummaryBlend::isSummaryReliable($this->db, [$campaignId], $summaryDateFrom, $summaryDateTo)) {
                    $preAgg = $this->preAggregateReader->querySummaryTotals(
                        $campaignId,
                        $summaryDateFrom,
                        $summaryDateTo,
                        $filters
                    );
                    if ($preAgg !== null) {
                        return $this->buildSummaryFromTotals(
                            $campaignId,
                            $dateFrom,
                            $dateTo,
                            $timezone,
                            $preAgg,
                            'pre_aggregate',
                            null,
                            $campaign
                        );
                    }
                }
            } elseif (TimezoneSummaryBlend::resolveSource($segments) !== 'raw_clicks') {
                $blended = $this->querySummaryTotalsBlended(
                    $campaignId,
                    $segments,
                    $dateFrom,
                    $dateTo,
                    $timezone,
                    $filters
                );
                if ($blended !== null) {
                    return $this->buildSummaryFromTotals(
                        $campaignId,
                        $dateFrom,
                        $dateTo,
                        $timezone,
                        $blended['totals'],
                        $blended['source'],
                        null,
                        $campaign
                    );
                }
            }
        }

        $filterKeys = $this->filterableKeys($campaignId, $dateFrom, $dateTo, $timezone);
        $clicksTable = $this->clicksTable;
        $usePersistedFlag = StatsExclusionFlag::columnExists($this->db, $clicksTable);

        $visitors = CampaignStatsExpressions::visitorCountExpr('cl', 'ts', $usePersistedFlag);
        $lpClicks = CampaignStatsExpressions::lpClicksCountExpr('cl', 'ts', $usePersistedFlag);
        $conversions = CampaignStatsExpressions::conversionsCountExpr('cl', 'ts', $usePersistedFlag);
        $lpValid = CampaignStatsExpressions::validClickCase('cl', 'ts', $usePersistedFlag);
        [$filterSql, $filterTypes, $filterParams] = $filters->clickFilterSql($this->db, 'cl', $filterKeys);

        $useScopedCost = $filters->requiresScopedCost();
        $fbCase = CampaignStatsCostSql::perClickFacebookCostCase($clicksTable);
        $gaCase = CampaignStatsCostSql::perClickGoogleCostCase($clicksTable);
        $fbJoins = $useScopedCost ? CampaignStatsCostSql::scopedApiCostJoins($clicksTable)['joins'] : '';

        $costSelect = $useScopedCost
            ? "COALESCE(SUM(cl.cost), 0) AS manual_cost, COALESCE(SUM({$fbCase}), 0) AS fb_cost, COALESCE(SUM({$gaCase}), 0) AS ga_cost"
            : "COALESCE(SUM(cl.cost), 0) AS manual_cost";

        $sql = "
            SELECT
                {$visitors} AS visitors,
                {$lpClicks} AS lp_clicks,
                COUNT(DISTINCT CASE WHEN cl.lp_click = 1 AND cl.landing_page_id IS NULL THEN {$lpValid} ELSE NULL END) AS direct_clicks,
                {$conversions} AS conversions,
                {$costSelect},
                COALESCE(SUM(conv.revenue_sum), 0) AS revenue
            FROM {$clicksTable} cl
            INNER JOIN campaigns cp ON cl.campaign_id = cp.id
            LEFT JOIN traffic_sources ts ON cp.traffic_source_id = ts.id
            " . CampaignStatsExpressions::conversionsAggJoin() . "
            {$fbJoins}
            WHERE cl.campaign_id = ? AND cl.ts >= ? AND cl.ts <= ?{$filterSql}
        ";

        $types = 'iss' . $filterTypes;
        $params = array_merge([$campaignId, $utcRange['from'], $utcRange['to']], $filterParams);
        if ($useScopedCost) {
            [$types, $params] = CampaignStatsCostSql::mergeScopedApiJoinDateBinds(
                $utcRange['from'],
                $utcRange['to'],
                $types,
                $params
            );
        }

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        $manualCost = (float)($row['manual_cost'] ?? 0);

        if ($useScopedCost) {
            $totalCost = $manualCost + (float)($row['fb_cost'] ?? 0) + (float)($row['ga_cost'] ?? 0);
        } else {
            // Same per-click allocator as dashboard KPI (do not use getAggregatedCost —
            // it undercounts when clicks lack both ad_id and adset_id).
            $aggregator = new FacebookCostAggregator($this->db);
            $totalFromAggregator = $aggregator->getCampaignTotalCost(
                $campaignId,
                $utcRange['from'],
                $utcRange['to'],
                $timezone
            );
            $fbCost = max(0.0, $totalFromAggregator - $manualCost);
            $gaAggregator = new \SimpleKuma\GoogleAds\GoogleAdsCostAggregator($this->db);
            $gaCost = $gaAggregator->getTotalGoogleAdsCost(
                $utcRange['from'],
                $utcRange['to'],
                (string)$campaignId
            );
            $totalCost = $manualCost + $fbCost + $gaCost;
        }

        return $this->buildSummaryFromTotals(
            $campaignId,
            $dateFrom,
            $dateTo,
            $timezone,
            [
                'visitors' => (int)($row['visitors'] ?? 0),
                'lp_clicks' => (int)($row['lp_clicks'] ?? 0),
                'direct_clicks' => (int)($row['direct_clicks'] ?? 0),
                'conversions' => (int)($row['conversions'] ?? 0),
                'manual_cost' => $manualCost,
                'revenue' => (float)($row['revenue'] ?? 0),
            ],
            'raw_clicks',
            $totalCost
        );
    }

    /**
     * @param array{visitors: int, lp_clicks: int, direct_clicks?: int, conversions: int, manual_cost: float, revenue: float} $totals
     * @param array<string, mixed>|null $campaign
     */
    private function buildSummaryFromTotals(
        int $campaignId,
        string $dateFrom,
        string $dateTo,
        string $timezone,
        array $totals,
        string $source,
        ?float $totalCostOverride = null,
        ?array $campaign = null
    ): array {
        $manualCost = (float)($totals['manual_cost'] ?? 0);
        if ($totalCostOverride !== null) {
            $totalCost = $totalCostOverride;
        } elseif (!$this->campaignUsesIntegratedApiCost($campaign)) {
            // Manual-cost campaigns: summary/table cost is authoritative — skip FB/GA aggregators.
            $totalCost = $manualCost;
        } else {
            $utcRange = Formatter::convertDateRangeToUTC($dateFrom, $dateTo, $timezone);
            $aggregator = new FacebookCostAggregator($this->db);
            $totalFromAggregator = $aggregator->getCampaignTotalCost(
                $campaignId,
                $utcRange['from'],
                $utcRange['to'],
                $timezone
            );
            $fbCost = max(0.0, $totalFromAggregator - $manualCost);
            $gaAggregator = new \SimpleKuma\GoogleAds\GoogleAdsCostAggregator($this->db);
            $gaCost = $gaAggregator->getTotalGoogleAdsCost(
                $utcRange['from'],
                $utcRange['to'],
                (string)$campaignId
            );
            $totalCost = $manualCost + $fbCost + $gaCost;
        }

        $visitorsCount = (int)($totals['visitors'] ?? 0);
        $lpClicksCount = (int)($totals['lp_clicks'] ?? 0);
        $directClicksCount = (int)($totals['direct_clicks'] ?? 0);
        $conversionsCount = (int)($totals['conversions'] ?? 0);
        $revenue = (float)($totals['revenue'] ?? 0);
        $profit = $revenue - $totalCost;
        $roi = $totalCost > 0 ? (($revenue - $totalCost) / $totalCost) * 100 : 0.0;
        $cr = $visitorsCount > 0 ? ($conversionsCount / $visitorsCount) * 100 : 0.0;
        // CTR is landing-page CTR only (exclude DTO "direct" from the rate)
        $ctr = $visitorsCount > 0 ? ($lpClicksCount / $visitorsCount) * 100 : 0.0;

        return [
            'campaign_id' => $campaignId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'timezone' => $timezone,
            'visitors' => $visitorsCount,
            // Match campaign list: Clicks = LP CTA + direct-to-offer
            'clicks' => $lpClicksCount + $directClicksCount,
            'lp_clicks' => $lpClicksCount,
            'direct_clicks' => $directClicksCount,
            'conversions' => $conversionsCount,
            'conversion_rate' => round($cr, 2),
            'ctr' => round($ctr, 2),
            'cost' => round($totalCost, 4),
            'revenue' => round($revenue, 4),
            'profit' => round($profit, 4),
            'roi' => round($roi, 2),
            'source' => $source,
        ];
    }

    /**
     * @param list<array{type: 'preagg'|'raw', from: string, to: string}> $segments
     * @return array{totals: array{visitors: int, lp_clicks: int, conversions: int, manual_cost: float, revenue: float}, source: string}|null
     */
    private function querySummaryTotalsBlended(
        int $campaignId,
        array $segments,
        string $dateFrom,
        string $dateTo,
        string $timezone,
        CampaignStatsQueryFilters $filters
    ): ?array {
        foreach ($segments as $segment) {
            if ($segment['type'] === 'preagg'
                && !TimezoneSummaryBlend::isSummaryReliable($this->db, [$campaignId], $segment['from'], $segment['to'])
            ) {
                // Incomplete daily summary — caller falls through to full raw path
                return null;
            }
        }

        $totals = [
            'visitors' => 0,
            'lp_clicks' => 0,
            'direct_clicks' => 0,
            'conversions' => 0,
            'manual_cost' => 0.0,
            'revenue' => 0.0,
        ];

        foreach ($segments as $segment) {
            if ($segment['type'] === 'preagg') {
                $part = $this->preAggregateReader->querySummaryTotals(
                    $campaignId,
                    $segment['from'],
                    $segment['to'],
                    $filters
                );
                if ($part === null) {
                    return null;
                }
            } else {
                $part = $this->querySummaryRawWindow(
                    $campaignId,
                    $segment['from'],
                    $segment['to'],
                    $dateFrom,
                    $dateTo,
                    $timezone,
                    $filters
                );
            }
            $totals['visitors'] += (int)($part['visitors'] ?? 0);
            $totals['lp_clicks'] += (int)($part['lp_clicks'] ?? 0);
            $totals['direct_clicks'] += (int)($part['direct_clicks'] ?? 0);
            $totals['conversions'] += (int)($part['conversions'] ?? 0);
            $totals['manual_cost'] += (float)($part['manual_cost'] ?? 0);
            $totals['revenue'] += (float)($part['revenue'] ?? 0);
        }

        return [
            'totals' => $totals,
            'source' => TimezoneSummaryBlend::resolveSource($segments),
        ];
    }

    /**
     * @return array{visitors: int, lp_clicks: int, conversions: int, manual_cost: float, revenue: float}
     */
    private function querySummaryRawWindow(
        int $campaignId,
        string $utcFrom,
        string $utcTo,
        string $dateFrom,
        string $dateTo,
        string $timezone,
        CampaignStatsQueryFilters $filters
    ): array {
        $filterKeys = $this->filterableKeys($campaignId, $dateFrom, $dateTo, $timezone);
        $clicksTable = $this->clicksTable;
        $usePersistedFlag = StatsExclusionFlag::columnExists($this->db, $clicksTable);
        $visitors = CampaignStatsExpressions::visitorCountExpr('cl', 'ts', $usePersistedFlag);
        $lpClicks = CampaignStatsExpressions::lpClicksCountExpr('cl', 'ts', $usePersistedFlag);
        $conversions = CampaignStatsExpressions::conversionsCountExpr('cl', 'ts', $usePersistedFlag);
        $lpValid = CampaignStatsExpressions::validClickCase('cl', 'ts', $usePersistedFlag);
        [$filterSql, $filterTypes, $filterParams] = $filters->clickFilterSql($this->db, 'cl', $filterKeys);

        $sql = "
            SELECT
                {$visitors} AS visitors,
                {$lpClicks} AS lp_clicks,
                COUNT(DISTINCT CASE WHEN cl.lp_click = 1 AND cl.landing_page_id IS NULL THEN {$lpValid} ELSE NULL END) AS direct_clicks,
                {$conversions} AS conversions,
                COALESCE(SUM(cl.cost), 0) AS manual_cost,
                COALESCE(SUM(conv.revenue_sum), 0) AS revenue
            FROM {$clicksTable} cl
            INNER JOIN campaigns cp ON cl.campaign_id = cp.id
            LEFT JOIN traffic_sources ts ON cp.traffic_source_id = ts.id
            " . CampaignStatsExpressions::conversionsAggJoin() . "
            WHERE cl.campaign_id = ? AND cl.ts >= ? AND cl.ts <= ?{$filterSql}
        ";

        $types = 'iss' . $filterTypes;
        $params = array_merge([$campaignId, $utcFrom, $utcTo], $filterParams);
        $stmt = $this->db->prepare($sql);
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
     * True when campaign cost may include Facebook/Google API spend (not in daily summary cost).
     *
     * @param array<string, mixed>|null $campaign
     */
    private function campaignUsesIntegratedApiCost(?array $campaign): bool
    {
        if ($campaign === null) {
            return true;
        }
        if (!empty($campaign['facebook_marketing_ad_account_id'])
            || !empty($campaign['facebook_marketing_integration_id'])
            || !empty($campaign['google_ads_integration_id'])
        ) {
            return true;
        }

        $tsId = (int)($campaign['traffic_source_id'] ?? 0);
        if ($tsId < 1) {
            return false;
        }
        $ts = (new TrafficSource($this->db))->getById($tsId);
        if ($ts === null) {
            return false;
        }

        return ($ts['cost_tracking_method'] ?? '') === 'integrated_api';
    }

    /**
     * Attempt summary/token pre-agg breakdown when safe (manual-cost campaign).
     * Supports offer/landing parent drill-downs on clicks_daily_summary.
     * Date dimension only when user calendar aligns with UTC summary days.
     *
     * @param list<array{dimension: string, value: string}> $parentPath
     * @param list<string> $filterKeys
     * @param array<string, mixed>|null $campaign
     * @return list<array<string, mixed>>|null
     */
    private function tryPreAggregateBreakdownRows(
        int $campaignId,
        string $groupBy,
        string $dateFrom,
        string $dateTo,
        string $userTimezone,
        string $utcFrom,
        string $utcTo,
        array $parentPath,
        CampaignStatsQueryFilters $filters,
        array $filterKeys,
        ?array $campaign
    ): ?array {
        if ($this->campaignUsesIntegratedApiCost($campaign)
            || !$this->preAggregateReader->canUseBreakdown($groupBy, $parentPath, $filters)
        ) {
            return null;
        }

        $utcAligned = Formatter::canUseUtcSummaryDateRange($dateFrom, $dateTo, $utcFrom, $utcTo);

        // summary_date is UTC — never map it onto non-UTC calendar day labels.
        if ($groupBy === 'date' && !$utcAligned) {
            return null;
        }

        if ($utcAligned) {
            $summaryDateFrom = substr($utcFrom, 0, 10);
            $summaryDateTo = substr($utcTo, 0, 10);
            if (!TimezoneSummaryBlend::isSummaryReliable($this->db, [$campaignId], $summaryDateFrom, $summaryDateTo)) {
                return null;
            }

            return $this->preAggregateReader->queryBreakdownRows(
                $campaignId,
                $groupBy,
                $summaryDateFrom,
                $summaryDateTo,
                $parentPath,
                $filters
            );
        }

        $segments = TimezoneSummaryBlend::segments($utcFrom, $utcTo);
        if (TimezoneSummaryBlend::resolveSource($segments) === 'raw_clicks') {
            return null;
        }

        return $this->queryBreakdownRowsBlended(
            $campaignId,
            $groupBy,
            $segments,
            $dateFrom,
            $dateTo,
            $userTimezone,
            $parentPath,
            $filters,
            $filterKeys,
            $campaign
        );
    }

    /**
     * @param list<array{type: 'preagg'|'raw', from: string, to: string}> $segments
     * @param list<array{dimension: string, value: string}> $parentPath
     * @param list<string> $filterKeys
     * @param array<string, mixed>|null $campaign
     * @return list<array<string, mixed>>|null
     */
    private function queryBreakdownRowsBlended(
        int $campaignId,
        string $groupBy,
        array $segments,
        string $dateFrom,
        string $dateTo,
        string $userTimezone,
        array $parentPath,
        CampaignStatsQueryFilters $filters,
        array $filterKeys,
        ?array $campaign = null
    ): ?array {
        foreach ($segments as $segment) {
            if ($segment['type'] === 'preagg'
                && !TimezoneSummaryBlend::isSummaryReliable($this->db, [$campaignId], $segment['from'], $segment['to'])
            ) {
                return null;
            }
        }

        $parts = [];
        foreach ($segments as $segment) {
            if ($segment['type'] === 'preagg') {
                $rows = $this->preAggregateReader->queryBreakdownRows(
                    $campaignId,
                    $groupBy,
                    $segment['from'],
                    $segment['to'],
                    $parentPath,
                    $filters
                );
                if ($rows === null) {
                    return null;
                }
                $parts[] = $rows;
            } else {
                $parts[] = $this->queryRawBreakdownAggregateRows(
                    $campaignId,
                    $groupBy,
                    $segment['from'],
                    $segment['to'],
                    $userTimezone,
                    $dateFrom,
                    $parentPath,
                    $filters,
                    $filterKeys,
                    $campaign
                );
            }
        }

        return TimezoneSummaryBlend::mergeBreakdownRows(...$parts);
    }

    /**
     * @param list<array{dimension: string, value: string}> $parentPath
     * @param list<string> $filterKeys
     * @return list<array<string, mixed>>
     */
    private function queryRawBreakdownAggregateRows(
        int $campaignId,
        string $groupBy,
        string $utcFrom,
        string $utcTo,
        string $userTimezone,
        string $dateFrom,
        array $parentPath,
        CampaignStatsQueryFilters $filters,
        array $filterKeys,
        ?array $campaign = null
    ): array {
        $clicksTable = $this->clicksTable;
        $usePersistedFlag = StatsExclusionFlag::columnExists($this->db, $clicksTable);
        $timezoneOffset = CampaignStatsExpressions::mysqlTimezoneOffset($userTimezone, $dateFrom);
        $parts = CampaignStatsExpressions::groupKeyParts($groupBy, $timezoneOffset);
        $groupExpr = $parts['expr'];
        $labelExpr = $parts['label_expr'];
        $visitors = CampaignStatsExpressions::visitorCountExpr('cl', 'ts', $usePersistedFlag);
        $lpClicks = CampaignStatsExpressions::lpClicksCountExpr('cl', 'ts', $usePersistedFlag);
        $conversions = CampaignStatsExpressions::conversionsCountExpr('cl', 'ts', $usePersistedFlag);
        // Blended edge segments only run for manual-cost pre-agg; keep joins off.
        $useApiCostJoins = $this->campaignUsesIntegratedApiCost($campaign);
        $fbCase = '0';
        $gaCase = '0';
        $fbJoins = '';
        if ($useApiCostJoins) {
            $fbCase = CampaignStatsCostSql::perClickFacebookCostCase($clicksTable);
            $gaCase = CampaignStatsCostSql::perClickGoogleCostCase($clicksTable);
            $fbJoins = CampaignStatsCostSql::scopedApiCostJoins($clicksTable)['joins'];
        }

        $selectLabel = $labelExpr !== null ? ", {$labelExpr} AS group_label" : ', NULL AS group_label';
        $joinOffer = ($groupBy === 'offer') ? 'LEFT JOIN offers o ON o.id = cl.offer_id' : '';
        $joinLp = ($groupBy === 'landing') ? 'LEFT JOIN landing_pages lp ON lp.id = cl.landing_page_id' : '';
        $groupBySql = $groupExpr . ($labelExpr !== null ? ", {$labelExpr}" : '');

        [$parentSql, $parentParams, $parentTypes] = $this->buildParentFilterSql($parentPath, $timezoneOffset);
        [$filterSql, $filterTypes, $filterParams] = $filters->clickFilterSql($this->db, 'cl', $filterKeys);

        $sql = "
            SELECT {$groupExpr} AS group_key
                   {$selectLabel},
                   {$visitors} AS clicks,
                   {$lpClicks} AS lp_clicks,
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
            WHERE cl.campaign_id = ? AND cl.ts >= ? AND cl.ts <= ?{$filterSql}
            {$parentSql}
            GROUP BY {$groupBySql}
        ";

        $types = 'iss' . $filterTypes . $parentTypes;
        $params = array_merge([$campaignId, $utcFrom, $utcTo], $filterParams, $parentParams);
        if ($useApiCostJoins) {
            [$types, $params] = CampaignStatsCostSql::mergeScopedApiJoinDateBinds($utcFrom, $utcTo, $types, $params);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $this->formatBreakdownRow($row);
        }
        $stmt->close();

        return $rows;
    }

    /**
     * @return array{labels: list<string>, datasets: array<string, list<float|int>>}
     */
    public function getChart(
        int $campaignId,
        string $dateFrom,
        string $dateTo,
        string $timezone,
        string $granularity = 'auto',
        ?CampaignStatsQueryFilters $filters = null
    ): array {
        $filters ??= new CampaignStatsQueryFilters();
        $utcRange = Formatter::convertDateRangeToUTC($dateFrom, $dateTo, $timezone);
        $isSingleDay = ($dateFrom === $dateTo);
        $useHourly = ($granularity === 'hour') || ($granularity === 'auto' && $isSingleDay);
        $campaign = (new Campaign($this->db))->getById($campaignId);

        if ($useHourly) {
            return $this->chartHourly(
                $campaignId,
                $dateFrom,
                $dateTo,
                $utcRange['from'],
                $utcRange['to'],
                $timezone,
                $filters,
                $campaign
            );
        }

        return $this->chartDaily(
            $campaignId,
            $dateFrom,
            $dateTo,
            $utcRange['from'],
            $utcRange['to'],
            $timezone,
            $filters,
            $campaign
        );
    }

    /**
     * @param list<string> $dimensions Ordered dimension keys
     * @param list<array{dimension: string, value: string}> $parentPath
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, totals: array<string, mixed>, dimension: string, level: int}
     */
    public function getBreakdown(
        int $campaignId,
        string $dateFrom,
        string $dateTo,
        string $timezone,
        array $dimensions,
        array $parentPath,
        int $page,
        int $perPage,
        string $sort = 'clicks',
        string $order = 'desc',
        ?CampaignStatsQueryFilters $filters = null
    ): array {
        $filters ??= new CampaignStatsQueryFilters();
        $dimensions = CampaignStatsDimensionRegistry::normalizeDimensionList($dimensions);
        if ($dimensions === []) {
            throw new \InvalidArgumentException('At least one breakdown dimension is required.');
        }

        $level = count($parentPath);
        if ($level >= count($dimensions)) {
            throw new \InvalidArgumentException('No further breakdown level available.');
        }

        $days = $this->calendarDaysBetween($dateFrom, $dateTo);
        if ($days > self::MAX_BREAKDOWN_DAYS) {
            throw new \RuntimeException(
                'Date range too large for breakdown (' . $days . ' days). Please use ' . self::MAX_BREAKDOWN_DAYS . ' days or fewer.'
            );
        }

        $dimension = $dimensions[$level];
        $campaign = (new Campaign($this->db))->getById($campaignId);
        if ($campaign === null) {
            return ['rows' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'totals' => [], 'dimension' => $dimension, 'level' => $level];
        }

        $this->breakdownService->assertValidGroupBy($dimension, $campaign, $dateFrom, $dateTo, $timezone);
        $this->assertValidParentPath($parentPath, $campaign, $dateFrom, $dateTo, $timezone);

        $utcRange = Formatter::convertDateRangeToUTC($dateFrom, $dateTo, $timezone);
        $perPage = max(1, min(200, $perPage));
        $page = max(1, $page);

        $filterKeys = $this->filterableKeys($campaignId, $dateFrom, $dateTo, $timezone);

        $result = $this->queryBreakdownLevel(
            $campaignId,
            $dimension,
            $dateFrom,
            $dateTo,
            $timezone,
            $utcRange['from'],
            $utcRange['to'],
            $parentPath,
            $page,
            $perPage,
            $sort,
            $order,
            $filters,
            $filterKeys,
            $campaign
        );

        // Totals row is only needed for L0; expands should not re-run full summary.
        $totals = [];
        if ($level === 0) {
            $summary = $this->getSummary($campaignId, $dateFrom, $dateTo, $timezone, $filters);
            $totals = [
                'visitors' => $summary['visitors'],
                'clicks' => $summary['clicks'],
                'lp_clicks' => $summary['lp_clicks'] ?? 0,
                'direct_clicks' => $summary['direct_clicks'] ?? 0,
                'conversions' => $summary['conversions'],
                'conversion_rate' => $summary['conversion_rate'],
                'ctr' => $summary['ctr'],
                'cost' => $summary['cost'],
                'revenue' => $summary['revenue'],
                'profit' => $summary['profit'],
                'roi' => $summary['roi'],
            ];
        }

        return [
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $perPage,
            'totals' => $totals,
            'dimension' => $dimension,
            'level' => $level,
            'has_children' => $level + 1 < count($dimensions),
        ];
    }

    /**
     * @return list<array{key: string, label: string, group: string, traffic_source?: string}>
     */
    public function getDimensions(
        int $campaignId,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        string $timezone = 'UTC'
    ): array {
        $campaign = (new Campaign($this->db))->getById($campaignId);
        if ($campaign === null) {
            return [];
        }

        return CampaignStatsDimensionRegistry::availableForCampaign(
            $campaign,
            new TrafficSource($this->db),
            $this->db,
            $dateFrom,
            $dateTo,
            $timezone
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getCampaignMeta(
        int $campaignId,
        string $dateFrom,
        string $dateTo,
        string $timezone
    ): array {
        $campaign = (new Campaign($this->db))->getById($campaignId);
        if ($campaign === null) {
            return [
                'campaign_id' => $campaignId,
                'auto_detect' => false,
                'traffic_source_id' => null,
                'traffic_sources' => [],
                'show_traffic_source_filter' => false,
                'offers' => [],
                'landing_pages' => [],
                'filter_tokens' => [],
            ];
        }

        $campaignTsId = (int)($campaign['traffic_source_id'] ?? 0);
        $autoDetect = $campaignTsId < 1;
        $utcRange = Formatter::convertDateRangeToUTC($dateFrom, $dateTo, $timezone);
        $summaryDateFrom = substr($utcRange['from'], 0, 10);
        $summaryDateTo = substr($utcRange['to'], 0, 10);
        $clicksTable = $this->clicksTable;

        // Summary-first discovery; per-dimension raw fallback when summary empty/unavailable.
        $trafficSources = $this->preAggregateReader->queryMetaDistinct(
            $campaignId,
            $summaryDateFrom,
            $summaryDateTo,
            'traffic_source'
        );
        $offers = $this->preAggregateReader->queryMetaDistinct(
            $campaignId,
            $summaryDateFrom,
            $summaryDateTo,
            'offer'
        );
        $landingPages = $this->preAggregateReader->queryMetaDistinct(
            $campaignId,
            $summaryDateFrom,
            $summaryDateTo,
            'landing'
        );

        $dbName = $this->db->query('SELECT DATABASE()')->fetch_row()[0] ?? '';
        $persisted = StatsExclusionFlag::includedWhere($this->db, 'cl', $clicksTable);
        $includedSql = $persisted !== '' ? ' AND ' . $persisted : '';
        $column = 'traffic_source_id';
        $stmt = $this->db->prepare(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
        );
        $hasTsColumn = false;
        if ($stmt !== false) {
            $stmt->bind_param('sss', $dbName, $clicksTable, $column);
            $stmt->execute();
            $hasTsColumn = (bool)$stmt->get_result()->fetch_row();
            $stmt->close();
        }

        if ($trafficSources === null || $trafficSources === []) {
            $trafficSources = [];
            if ($hasTsColumn) {
                $sql = "
                    SELECT DISTINCT cl.traffic_source_id AS id, ts.name
                    FROM {$clicksTable} cl
                    LEFT JOIN traffic_sources ts ON ts.id = cl.traffic_source_id
                    WHERE cl.campaign_id = ? AND cl.ts >= ? AND cl.ts <= ?
                      AND cl.traffic_source_id IS NOT NULL
                      {$includedSql}
                    ORDER BY ts.name
                ";
                $q = $this->db->prepare($sql);
                $q->bind_param('iss', $campaignId, $utcRange['from'], $utcRange['to']);
                $q->execute();
                $res = $q->get_result();
                while ($row = $res->fetch_assoc()) {
                    $id = (int)($row['id'] ?? 0);
                    if ($id > 0) {
                        $trafficSources[] = [
                            'id' => $id,
                            'name' => (string)($row['name'] ?? 'Unknown'),
                        ];
                    }
                }
                $q->close();
            } elseif ($campaignTsId > 0) {
                $ts = (new TrafficSource($this->db))->getById($campaignTsId);
                if ($ts) {
                    $trafficSources[] = [
                        'id' => $campaignTsId,
                        'name' => (string)($ts['name'] ?? 'Traffic Source'),
                    ];
                }
            }
        }

        if ($offers === null || $offers === []) {
            $offers = [];
            $offerSql = "
                SELECT DISTINCT cl.offer_id AS id, o.name
                FROM {$clicksTable} cl
                LEFT JOIN offers o ON o.id = cl.offer_id
                WHERE cl.campaign_id = ? AND cl.ts >= ? AND cl.ts <= ?
                  AND cl.offer_id IS NOT NULL AND cl.offer_id > 0
                  {$includedSql}
                ORDER BY o.name
            ";
            $offerQ = $this->db->prepare($offerSql);
            if ($offerQ !== false) {
                $offerQ->bind_param('iss', $campaignId, $utcRange['from'], $utcRange['to']);
                $offerQ->execute();
                $offerRes = $offerQ->get_result();
                while ($row = $offerRes->fetch_assoc()) {
                    $id = (int)($row['id'] ?? 0);
                    if ($id > 0) {
                        $offers[] = [
                            'id' => $id,
                            'name' => (string)($row['name'] ?? 'Offer #' . $id),
                        ];
                    }
                }
                $offerQ->close();
            }
        }

        if ($landingPages === null || $landingPages === []) {
            $landingPages = [];
            $lpSql = "
                SELECT DISTINCT cl.landing_page_id AS id, lp.name
                FROM {$clicksTable} cl
                LEFT JOIN landing_pages lp ON lp.id = cl.landing_page_id
                WHERE cl.campaign_id = ? AND cl.ts >= ? AND cl.ts <= ?
                  AND cl.landing_page_id IS NOT NULL AND cl.landing_page_id > 0
                  {$includedSql}
                ORDER BY lp.name
            ";
            $lpQ = $this->db->prepare($lpSql);
            if ($lpQ !== false) {
                $lpQ->bind_param('iss', $campaignId, $utcRange['from'], $utcRange['to']);
                $lpQ->execute();
                $lpRes = $lpQ->get_result();
                while ($row = $lpRes->fetch_assoc()) {
                    $id = (int)($row['id'] ?? 0);
                    if ($id > 0) {
                        $landingPages[] = [
                            'id' => $id,
                            'name' => (string)($row['name'] ?? 'Landing #' . $id),
                        ];
                    }
                }
                $lpQ->close();
            }
        }

        return [
            'campaign_id' => $campaignId,
            'auto_detect' => $autoDetect,
            'traffic_source_id' => $campaignTsId > 0 ? $campaignTsId : null,
            'traffic_sources' => $trafficSources,
            'show_traffic_source_filter' => $autoDetect || count($trafficSources) > 1,
            'offers' => $offers,
            'landing_pages' => $landingPages,
            'filter_tokens' => CampaignStatsDimensionRegistry::filterableDimensionsForCampaign(
                $campaign,
                new TrafficSource($this->db),
                $this->db,
                $dateFrom,
                $dateTo,
                $timezone
            ),
        ];
    }

    /**
     * Distinct token values seen in the date range (for filter autocomplete).
     *
     * @return list<string>
     */
    public function getTokenFilterValues(
        int $campaignId,
        string $dateFrom,
        string $dateTo,
        string $timezone,
        string $tokenParam
    ): array {
        $allowed = $this->filterableKeys($campaignId, $dateFrom, $dateTo, $timezone);
        if (!in_array($tokenParam, $allowed, true)) {
            return [];
        }

        $utcRange = Formatter::convertDateRangeToUTC($dateFrom, $dateTo, $timezone);
        $clicksTable = $this->clicksTable;
        $parts = CampaignStatsExpressions::groupKeyParts($tokenParam);
        $groupExpr = $parts['expr'];
        $usePersistedFlag = StatsExclusionFlag::columnExists($this->db, $clicksTable);
        $visitors = CampaignStatsExpressions::visitorCountExpr('cl', 'ts', $usePersistedFlag);
        $includedSql = $usePersistedFlag ? ' AND cl.exclude_from_stats = 0' : '';

        $sql = "
            SELECT ({$groupExpr}) AS token_value, {$visitors} AS cnt
            FROM {$clicksTable} cl
            INNER JOIN campaigns cp ON cl.campaign_id = cp.id
            LEFT JOIN traffic_sources ts ON cp.traffic_source_id = ts.id
            WHERE cl.campaign_id = ? AND cl.ts >= ? AND cl.ts <= ?{$includedSql}
            GROUP BY token_value
            HAVING token_value IS NOT NULL
               AND TRIM(token_value) != ''
               AND token_value != 'N/A'
            ORDER BY cnt DESC
            LIMIT 50
        ";

        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            return [];
        }

        $stmt->bind_param('iss', $campaignId, $utcRange['from'], $utcRange['to']);
        $stmt->execute();
        $result = $stmt->get_result();

        $values = [];
        while ($row = $result->fetch_assoc()) {
            $val = trim((string)($row['token_value'] ?? ''));
            if ($val !== '') {
                $values[] = $val;
            }
        }
        $stmt->close();

        return $values;
    }

    /**
     * @param list<array{dimension: string, value: string}> $parentPath
     * @param array<string, mixed> $campaign
     */
    private function assertValidParentPath(
        array $parentPath,
        array $campaign,
        string $dateFrom,
        string $dateTo,
        string $timezone
    ): void {
        if ($parentPath === []) {
            return;
        }

        $allowed = CampaignStatsDimensionRegistry::allowedKeysForCampaign(
            $campaign,
            new TrafficSource($this->db),
            $this->db,
            $dateFrom,
            $dateTo,
            $timezone
        );

        foreach ($parentPath as $index => $segment) {
            if (!is_array($segment)) {
                throw new \InvalidArgumentException('Invalid parent_path segment at index ' . $index);
            }
            $dim = trim((string)($segment['dimension'] ?? ''));
            if ($dim === '' || !in_array($dim, $allowed, true)) {
                throw new \InvalidArgumentException("Invalid parent_path dimension: {$dim}");
            }
            if (!array_key_exists('value', $segment)) {
                throw new \InvalidArgumentException('parent_path segment missing value at index ' . $index);
            }
            if (!is_scalar($segment['value'])) {
                throw new \InvalidArgumentException('parent_path value must be scalar at index ' . $index);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $allRows
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    private function paginateBreakdownRows(
        array $allRows,
        string $groupBy,
        string $dateFrom,
        string $dateTo,
        int $page,
        int $perPage,
        string $sort,
        string $order
    ): array {
        if ($groupBy === 'date') {
            $allRows = CampaignStatsExpressions::fillDateRangeRows($allRows, $dateFrom, $dateTo);
            if ($sort === 'clicks') {
                $sort = 'group';
                $order = 'asc';
            }
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

        return ['rows' => $pageRows, 'total' => $total];
    }

    /**
     * @param list<array{dimension: string, value: string}> $parentPath
     * @param array<string, mixed>|null $campaign
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    private function queryBreakdownLevel(
        int $campaignId,
        string $groupBy,
        string $dateFrom,
        string $dateTo,
        string $userTimezone,
        string $utcFrom,
        string $utcTo,
        array $parentPath,
        int $page,
        int $perPage,
        string $sort,
        string $order,
        ?CampaignStatsQueryFilters $filters = null,
        array $filterKeys = [],
        ?array $campaign = null
    ): array {
        $filters ??= new CampaignStatsQueryFilters();

        // Manual-cost campaigns: offer/landing/token (and UTC-aligned date) can use summary tables.
        // FB/GA API cost campaigns stay on raw per-click joins so row cost stays accurate.
        $preAggRows = $this->tryPreAggregateBreakdownRows(
            $campaignId,
            $groupBy,
            $dateFrom,
            $dateTo,
            $userTimezone,
            $utcFrom,
            $utcTo,
            $parentPath,
            $filters,
            $filterKeys,
            $campaign
        );
        if ($preAggRows !== null) {
            return $this->paginateBreakdownRows(
                $preAggRows,
                $groupBy,
                $dateFrom,
                $dateTo,
                $page,
                $perPage,
                $sort,
                $order
            );
        }

        // Raw path: skip FB/GA cost joins for manual-cost campaigns (SUM(cl.cost) is enough).
        $clicksTable = $this->clicksTable;
        $usePersistedFlag = StatsExclusionFlag::columnExists($this->db, $clicksTable);
        $timezoneOffset = CampaignStatsExpressions::mysqlTimezoneOffset($userTimezone, $dateFrom);
        $parts = CampaignStatsExpressions::groupKeyParts($groupBy, $timezoneOffset);
        $groupExpr = $parts['expr'];
        $labelExpr = $parts['label_expr'];
        $visitors = CampaignStatsExpressions::visitorCountExpr('cl', 'ts', $usePersistedFlag);
        $lpClicks = CampaignStatsExpressions::lpClicksCountExpr('cl', 'ts', $usePersistedFlag);
        $directClicks = CampaignStatsExpressions::directClicksCountExpr('cl', 'ts', $usePersistedFlag);
        $conversions = CampaignStatsExpressions::conversionsCountExpr('cl', 'ts', $usePersistedFlag);
        $useApiCostJoins = $this->campaignUsesIntegratedApiCost($campaign);
        $fbCase = '0';
        $gaCase = '0';
        $fbJoins = '';
        if ($useApiCostJoins) {
            $fbCase = CampaignStatsCostSql::perClickFacebookCostCase($clicksTable);
            $gaCase = CampaignStatsCostSql::perClickGoogleCostCase($clicksTable);
            $fbJoins = CampaignStatsCostSql::scopedApiCostJoins($clicksTable)['joins'];
        }

        $selectLabel = $labelExpr !== null ? ", {$labelExpr} AS group_label" : ', NULL AS group_label';
        $joinOffer = ($groupBy === 'offer') ? 'LEFT JOIN offers o ON o.id = cl.offer_id' : '';
        $joinLp = ($groupBy === 'landing') ? 'LEFT JOIN landing_pages lp ON lp.id = cl.landing_page_id' : '';
        $groupBySql = $groupExpr . ($labelExpr !== null ? ", {$labelExpr}" : '');

        [$parentSql, $parentParams, $parentTypes] = $this->buildParentFilterSql($parentPath, $timezoneOffset);
        [$filterSql, $filterTypes, $filterParams] = $filters->clickFilterSql($this->db, 'cl', $filterKeys);

        $baseFrom = "
            FROM {$clicksTable} cl
            INNER JOIN campaigns cp ON cl.campaign_id = cp.id
            LEFT JOIN traffic_sources ts ON cp.traffic_source_id = ts.id
            " . CampaignStatsExpressions::conversionsAggJoin() . "
            {$joinOffer}
            {$joinLp}
            {$fbJoins}
            WHERE cl.campaign_id = ? AND cl.ts >= ? AND cl.ts <= ?{$filterSql}
            {$parentSql}
        ";

        $types = 'iss' . $filterTypes . $parentTypes;
        $params = array_merge([$campaignId, $utcFrom, $utcTo], $filterParams, $parentParams);
        if ($useApiCostJoins) {
            [$types, $params] = CampaignStatsCostSql::mergeScopedApiJoinDateBinds($utcFrom, $utcTo, $types, $params);
        }

        // Date breakdown needs fillDateRangeRows — keep in-PHP pagination
        if ($groupBy === 'date') {
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
                {$baseFrom}
                GROUP BY {$groupBySql}
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $allRows = [];
            while ($row = $result->fetch_assoc()) {
                $allRows[] = $this->formatBreakdownRow($row);
            }
            $stmt->close();

            return $this->paginateBreakdownRows($allRows, $groupBy, $dateFrom, $dateTo, $page, $perPage, $sort, $order);
        }

        $countSql = "
            SELECT COUNT(*) AS cnt FROM (
                SELECT {$groupExpr} AS group_key
                {$baseFrom}
                GROUP BY {$groupBySql}
            ) AS grouped_count
        ";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = (int)($countStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $countStmt->close();

        $sortCol = CampaignStatsExpressions::sortColumn($sort);
        $orderDir = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
        // Use full aggregate expressions in ORDER BY — MySQL/MariaDB reject
        // SELECT aliases of group functions inside ORDER BY expressions
        // (e.g. "Reference 'lp_clicks' not supported (reference to group function)").
        $costExpr = '(COALESCE(SUM(cl.cost), 0) + COALESCE(SUM(' . $fbCase . '), 0) + COALESCE(SUM(' . $gaCase . '), 0))';
        $revenueExpr = 'COALESCE(SUM(conv.revenue_sum), 0)';
        $orderExpr = match ($sortCol) {
            'group' => 'group_key',
            'lp_clicks' => "({$lpClicks})",
            'ctr' => "CASE WHEN ({$visitors}) > 0 THEN (({$lpClicks}) / ({$visitors})) ELSE 0 END",
            'conversions' => "({$conversions})",
            'cost' => $costExpr,
            'revenue' => $revenueExpr,
            'profit' => "({$revenueExpr} - {$costExpr})",
            'roi' => "CASE WHEN {$costExpr} > 0 THEN (({$revenueExpr} - {$costExpr}) / {$costExpr}) ELSE 0 END",
            'conversion_rate' => "CASE WHEN ({$visitors}) > 0 THEN (({$conversions}) / ({$visitors})) ELSE 0 END",
            default => "({$visitors})",
        };

        $offset = ($page - 1) * $perPage;
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
            {$baseFrom}
            GROUP BY {$groupBySql}
            ORDER BY {$orderExpr} {$orderDir}, group_key ASC
            LIMIT ? OFFSET ?
        ";
        $pageTypes = $types . 'ii';
        $pageParams = array_merge($params, [$perPage, $offset]);
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($pageTypes, ...$pageParams);
        $stmt->execute();
        $result = $stmt->get_result();

        $pageRows = [];
        while ($row = $result->fetch_assoc()) {
            $pageRows[] = $this->formatBreakdownRow($row);
        }
        $stmt->close();

        return ['rows' => $pageRows, 'total' => $total];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatBreakdownRow(array $row): array
    {
        $cost = (float)($row['manual_cost'] ?? 0) + (float)($row['fb_cost'] ?? 0) + (float)($row['ga_cost'] ?? 0);
        $lpClicks = (int)($row['lp_clicks'] ?? 0);
        $directClicks = (int)($row['direct_clicks'] ?? 0);
        $formatted = CampaignStatsExpressions::formatMetricsRow(
            (string)($row['group_key'] ?? 'N/A'),
            $row['group_label'] ?? null,
            [
                'clicks' => (int)($row['clicks'] ?? 0),
                'lp_clicks' => $lpClicks,
                'conversions' => (int)($row['conversions'] ?? 0),
                'cost' => $cost,
                'revenue' => (float)($row['revenue'] ?? 0),
            ]
        );
        $formatted['group_key'] = $formatted['group'];
        $formatted['name'] = $formatted['group_label'] ?? $formatted['group'];
        $formatted['direct_clicks'] = $directClicks;
        // UI "Clicks" column = LP CTA + direct (same as campaign list / KPI)
        $formatted['action_clicks'] = $lpClicks + $directClicks;

        return $formatted;
    }

    /**
     * @param list<array{dimension: string, value: string}> $parentPath
     * @return array{0: string, 1: list<mixed>, 2: string}
     */
    private function buildParentFilterSql(array $parentPath, string $timezoneOffset = '+00:00'): array
    {
        $sql = '';
        $params = [];
        $types = '';
        $offset = preg_match('/^[+-]\d{2}:\d{2}$/', $timezoneOffset) ? $timezoneOffset : '+00:00';

        foreach ($parentPath as $parent) {
            $dim = (string)($parent['dimension'] ?? '');
            $value = (string)($parent['value'] ?? '');
            if ($dim === '') {
                continue;
            }

            if ($dim === 'offer') {
                if ($value === 'N/A' || $value === '') {
                    $sql .= ' AND cl.offer_id IS NULL';
                } else {
                    $sql .= ' AND cl.offer_id = ?';
                    $params[] = (int)$value;
                    $types .= 'i';
                }
                continue;
            }

            if ($dim === 'landing') {
                if ($value === 'N/A' || $value === '') {
                    $sql .= ' AND cl.landing_page_id IS NULL';
                } else {
                    $sql .= ' AND cl.landing_page_id = ?';
                    $params[] = (int)$value;
                    $types .= 'i';
                }
                continue;
            }

            if ($dim === 'date') {
                $sql .= " AND DATE(CONVERT_TZ(cl.ts, '+00:00', '{$offset}')) = ?";
                $params[] = $value;
                $types .= 's';
                continue;
            }

            $parts = CampaignStatsExpressions::groupKeyParts($dim, $offset);
            $sql .= ' AND (' . $parts['expr'] . ') = ?';
            $params[] = $value;
            $types .= 's';
        }

        return [$sql, $params, $types];
    }

    /**
     * @param array<string, mixed>|null $campaign
     * @return array{labels: list<string>, datasets: array<string, list<float|int>>}
     */
    private function chartHourly(
        int $campaignId,
        string $dateFrom,
        string $dateTo,
        string $utcFrom,
        string $utcTo,
        string $userTimezone,
        CampaignStatsQueryFilters $filters,
        ?array $campaign = null
    ): array {
        $clicksTable = $this->clicksTable;
        $usePersistedFlag = StatsExclusionFlag::columnExists($this->db, $clicksTable);
        $useApiCostJoins = $this->campaignUsesIntegratedApiCost($campaign);
        $fbCase = '0';
        $gaCase = '0';
        $fbJoins = '';
        if ($useApiCostJoins) {
            $fbCase = CampaignStatsCostSql::perClickFacebookCostCase($clicksTable);
            $gaCase = CampaignStatsCostSql::perClickGoogleCostCase($clicksTable);
            $fbJoins = CampaignStatsCostSql::scopedApiCostJoins($clicksTable)['joins'];
        }
        $filterKeys = $this->filterableKeys($campaignId, $dateFrom, $dateTo, $userTimezone);
        $visitors = CampaignStatsExpressions::visitorCountExpr('cl', 'ts', $usePersistedFlag);
        $actionClicks = CampaignStatsExpressions::actionClicksCountExpr('cl', 'ts', $usePersistedFlag);
        $conversions = CampaignStatsExpressions::conversionsCountExpr('cl', 'ts', $usePersistedFlag);
        $timezoneOffset = CampaignStatsExpressions::mysqlTimezoneOffset($userTimezone, $dateFrom);
        $hourExpr = "COALESCE(HOUR(CONVERT_TZ(cl.ts, '+00:00', ?)), -1)";

        [$filterSql, $filterTypes, $filterParams] = $filters->clickFilterSql($this->db, 'cl', $filterKeys);

        $sql = "
            SELECT
                {$hourExpr} AS hour,
                {$visitors} AS visitors,
                {$actionClicks} AS clicks,
                {$conversions} AS conversions,
                COALESCE(SUM(conv.revenue_sum), 0) AS revenue,
                COALESCE(SUM(cl.cost), 0) AS manual_cost,
                COALESCE(SUM({$fbCase}), 0) AS fb_cost,
                COALESCE(SUM({$gaCase}), 0) AS ga_cost
            FROM {$clicksTable} cl
            INNER JOIN campaigns cp ON cl.campaign_id = cp.id
            LEFT JOIN traffic_sources ts ON cp.traffic_source_id = ts.id
            " . CampaignStatsExpressions::conversionsAggJoin() . "
            {$fbJoins}
            WHERE cl.campaign_id = ? AND cl.ts >= ? AND cl.ts <= ?{$filterSql}
            GROUP BY {$hourExpr}
            ORDER BY hour ASC
        ";

        if ($useApiCostJoins) {
            $types = 'sssssiss' . $filterTypes . 's';
            $params = array_merge(
                [$timezoneOffset, $utcFrom, $utcTo, $utcFrom, $utcTo, $campaignId, $utcFrom, $utcTo],
                $filterParams,
                [$timezoneOffset]
            );
        } else {
            $types = 'siss' . $filterTypes . 's';
            $params = array_merge(
                [$timezoneOffset, $campaignId, $utcFrom, $utcTo],
                $filterParams,
                [$timezoneOffset]
            );
        }

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $byHour = array_fill(0, 24, ['visitors' => 0, 'clicks' => 0, 'conversions' => 0, 'revenue' => 0.0, 'cost' => 0.0]);
        while ($row = $result->fetch_assoc()) {
            $hour = (int)($row['hour'] ?? -1);
            if ($hour < 0 || $hour > 23) {
                continue;
            }
            $byHour[$hour] = [
                'visitors' => (int)($row['visitors'] ?? 0),
                'clicks' => (int)($row['clicks'] ?? 0),
                'conversions' => (int)($row['conversions'] ?? 0),
                'revenue' => (float)($row['revenue'] ?? 0),
                'cost' => (float)($row['manual_cost'] ?? 0) + (float)($row['fb_cost'] ?? 0) + (float)($row['ga_cost'] ?? 0),
            ];
        }
        $stmt->close();

        $labels = [];
        $datasets = [
            'visitors' => [],
            'clicks' => [],
            'conversions' => [],
            'revenue' => [],
            'cost' => [],
        ];
        for ($h = 0; $h < 24; $h++) {
            $labels[] = ($h === 0 ? '12 AM' : ($h < 12 ? $h . ' AM' : ($h === 12 ? '12 PM' : ($h - 12) . ' PM')));
            foreach (array_keys($datasets) as $key) {
                $datasets[$key][] = $byHour[$h][$key];
            }
        }

        return ['labels' => $labels, 'datasets' => $datasets, 'granularity' => 'hour'];
    }

    /**
     * @param array<string, mixed>|null $campaign
     * @return array{labels: list<string>, datasets: array<string, list<float|int>>, granularity: string}
     */
    private function chartDaily(
        int $campaignId,
        string $dateFrom,
        string $dateTo,
        string $utcFrom,
        string $utcTo,
        string $userTimezone,
        CampaignStatsQueryFilters $filters,
        ?array $campaign = null
    ): array {
        $fromPreAgg = $this->chartDailyFromPreAggregate(
            $campaignId,
            $dateFrom,
            $dateTo,
            $utcFrom,
            $utcTo,
            $userTimezone,
            $filters,
            $campaign
        );
        if ($fromPreAgg !== null) {
            return $fromPreAgg;
        }

        $clicksTable = $this->clicksTable;
        $usePersistedFlag = StatsExclusionFlag::columnExists($this->db, $clicksTable);
        $useApiCostJoins = $this->campaignUsesIntegratedApiCost($campaign);
        $fbCase = '0';
        $gaCase = '0';
        $fbJoins = '';
        if ($useApiCostJoins) {
            $fbCase = CampaignStatsCostSql::perClickFacebookCostCase($clicksTable);
            $gaCase = CampaignStatsCostSql::perClickGoogleCostCase($clicksTable);
            $fbJoins = CampaignStatsCostSql::scopedApiCostJoins($clicksTable)['joins'];
        }
        $visitors = CampaignStatsExpressions::visitorCountExpr('cl', 'ts', $usePersistedFlag);
        $actionClicks = CampaignStatsExpressions::actionClicksCountExpr('cl', 'ts', $usePersistedFlag);
        $conversions = CampaignStatsExpressions::conversionsCountExpr('cl', 'ts', $usePersistedFlag);
        $filterKeys = $this->filterableKeys($campaignId, $dateFrom, $dateTo, $userTimezone);
        $timezoneOffset = CampaignStatsExpressions::mysqlTimezoneOffset($userTimezone, $dateFrom);
        $dayExpr = "DATE(CONVERT_TZ(cl.ts, '+00:00', '{$timezoneOffset}'))";

        [$filterSql, $filterTypes, $filterParams] = $filters->clickFilterSql($this->db, 'cl', $filterKeys);

        $sql = "
            SELECT
                {$dayExpr} AS day,
                {$visitors} AS visitors,
                {$actionClicks} AS clicks,
                {$conversions} AS conversions,
                COALESCE(SUM(conv.revenue_sum), 0) AS revenue,
                COALESCE(SUM(cl.cost), 0) AS manual_cost,
                COALESCE(SUM({$fbCase}), 0) AS fb_cost,
                COALESCE(SUM({$gaCase}), 0) AS ga_cost
            FROM {$clicksTable} cl
            INNER JOIN campaigns cp ON cl.campaign_id = cp.id
            LEFT JOIN traffic_sources ts ON cp.traffic_source_id = ts.id
            " . CampaignStatsExpressions::conversionsAggJoin() . "
            {$fbJoins}
            WHERE cl.campaign_id = ? AND cl.ts >= ? AND cl.ts <= ?{$filterSql}
            GROUP BY {$dayExpr}
            ORDER BY day ASC
        ";

        $types = 'iss' . $filterTypes;
        $params = array_merge([$campaignId, $utcFrom, $utcTo], $filterParams);
        if ($useApiCostJoins) {
            [$types, $params] = CampaignStatsCostSql::mergeScopedApiJoinDateBinds($utcFrom, $utcTo, $types, $params);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $labels = [];
        $datasets = [
            'visitors' => [],
            'clicks' => [],
            'conversions' => [],
            'revenue' => [],
            'cost' => [],
        ];
        while ($row = $result->fetch_assoc()) {
            $labels[] = (string)($row['day'] ?? '');
            $datasets['visitors'][] = (int)($row['visitors'] ?? 0);
            $datasets['clicks'][] = (int)($row['clicks'] ?? 0);
            $datasets['conversions'][] = (int)($row['conversions'] ?? 0);
            $datasets['revenue'][] = (float)($row['revenue'] ?? 0);
            $datasets['cost'][] = (float)($row['manual_cost'] ?? 0) + (float)($row['fb_cost'] ?? 0) + (float)($row['ga_cost'] ?? 0);
        }
        $stmt->close();

        $filled = CampaignStatsExpressions::fillChartDailySeries($dateFrom, $dateTo, $labels, $datasets);

        return ['labels' => $filled['labels'], 'datasets' => $filled['datasets'], 'granularity' => 'day'];
    }

    /**
     * Multi-day chart from clicks_daily_summary when filters allow (same eligibility as summary pre-agg).
     * Cost series uses summary manual cost only — matches date breakdown pre-agg; scoped/FB-GA charts stay on raw.
     *
     * @param array<string, mixed>|null $campaign
     * @return array{labels: list<string>, datasets: array<string, list<float|int>>, granularity: string}|null
     */
    private function chartDailyFromPreAggregate(
        int $campaignId,
        string $dateFrom,
        string $dateTo,
        string $utcFrom,
        string $utcTo,
        string $userTimezone,
        CampaignStatsQueryFilters $filters,
        ?array $campaign = null
    ): ?array {
        if (!$this->preAggregateReader->canUseSummary($filters)) {
            return null;
        }

        // summary_date is UTC DATE(ts). When the user calendar range does not match the
        // UTC summary_date span (common for non-UTC timezones), fall back to raw CONVERT_TZ
        // so chart day labels stay in the user's timezone.
        if (!Formatter::canUseUtcSummaryDateRange($dateFrom, $dateTo, $utcFrom, $utcTo)) {
            return null;
        }

        $summaryDateFrom = substr($utcFrom, 0, 10);
        $summaryDateTo = substr($utcTo, 0, 10);

        if (!TimezoneSummaryBlend::isSummaryReliable($this->db, [$campaignId], $summaryDateFrom, $summaryDateTo)) {
            return null;
        }

        // Pre-agg chart cost is manual-only. Skip expensive aggregator probe for known
        // manual-cost campaigns; for API-cost campaigns fall back to raw when spend differs.
        if ($this->campaignUsesIntegratedApiCost($campaign)) {
            $manualProbe = $this->preAggregateReader->querySummaryTotals(
                $campaignId,
                $summaryDateFrom,
                $summaryDateTo,
                $filters
            );
            if ($manualProbe !== null) {
                $manualCost = (float)($manualProbe['manual_cost'] ?? 0);
                $fbPlusManual = (new FacebookCostAggregator($this->db))->getCampaignTotalCost(
                    $campaignId,
                    $utcFrom,
                    $utcTo,
                    $userTimezone
                );
                $gaCost = (new \SimpleKuma\GoogleAds\GoogleAdsCostAggregator($this->db))
                    ->getTotalGoogleAdsCost($utcFrom, $utcTo, (string)$campaignId);
                $apiPlusManual = max((float)$fbPlusManual, $manualCost) + (float)$gaCost;
                if (abs($apiPlusManual - $manualCost) > 0.02) {
                    return null;
                }
            }
        }

        $rows = $this->preAggregateReader->queryChartDailyRows(
            $campaignId,
            $summaryDateFrom,
            $summaryDateTo,
            $filters
        );
        if ($rows === null) {
            return null;
        }

        $labels = [];
        $datasets = [
            'visitors' => [],
            'clicks' => [],
            'conversions' => [],
            'revenue' => [],
            'cost' => [],
        ];
        foreach ($rows as $row) {
            $labels[] = (string)($row['day'] ?? '');
            $datasets['visitors'][] = (int)($row['visitors'] ?? 0);
            $datasets['clicks'][] = (int)($row['lp_clicks'] ?? 0) + (int)($row['direct_clicks'] ?? 0);
            $datasets['conversions'][] = (int)($row['conversions'] ?? 0);
            $datasets['revenue'][] = (float)($row['revenue'] ?? 0);
            $datasets['cost'][] = (float)($row['manual_cost'] ?? 0);
        }

        $filled = CampaignStatsExpressions::fillChartDailySeries($dateFrom, $dateTo, $labels, $datasets);

        return ['labels' => $filled['labels'], 'datasets' => $filled['datasets'], 'granularity' => 'day'];
    }

    private function calendarDaysBetween(string $dateFrom, string $dateTo): int
    {
        $from = new \DateTimeImmutable($dateFrom);
        $to = new \DateTimeImmutable($dateTo);
        if ($to < $from) {
            return 0;
        }

        return (int)$from->diff($to)->days + 1;
    }

    /**
     * @return list<string>
     */
    private function filterableKeys(int $campaignId, string $dateFrom, string $dateTo, string $timezone): array
    {
        $campaign = (new Campaign($this->db))->getById($campaignId);
        if ($campaign === null) {
            return [];
        }

        return CampaignStatsDimensionRegistry::filterableKeysForCampaign(
            $campaign,
            new TrafficSource($this->db),
            $this->db,
            $dateFrom,
            $dateTo,
            $timezone
        );
    }
}
