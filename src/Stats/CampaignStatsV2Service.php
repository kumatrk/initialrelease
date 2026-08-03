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
        ReportingQueryCancel::throwIfAborted();
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
                        ReportingQueryCancel::throwIfAborted();
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
            } else {
                $source = TimezoneSummaryBlend::resolveSource($segments);
                if ($source !== 'raw_clicks') {
                    $blended = $this->querySummaryTotalsBlended(
                        $campaignId,
                        $segments,
                        $dateFrom,
                        $dateTo,
                        $timezone,
                        $filters
                    );
                    if ($blended !== null) {
                        ReportingQueryCancel::throwIfAborted();
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

                // Single non-UTC day: no full UTC summary days to blend. Covering-index
                // COUNT(*) stays timezone-accurate and avoids fat-row timeouts at 100k+.
                if (!$filters->requiresScopedCost() && !$filters->hasTokenFilter()) {
                    $lean = $this->queryLeanSummaryTotals(
                        $campaignId,
                        $utcRange['from'],
                        $utcRange['to'],
                        $filters
                    );
                    if ($lean !== null) {
                        ReportingQueryCancel::throwIfAborted();
                        return $this->buildSummaryFromTotals(
                            $campaignId,
                            $dateFrom,
                            $dateTo,
                            $timezone,
                            $lean,
                            'raw_clicks_cover',
                            null,
                            $campaign
                        );
                    }
                }
            }
        }

        ReportingQueryCancel::throwIfAborted();
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
     * Attempt summary/token pre-agg breakdown when safe.
     * Meta/Google API-cost campaigns may use pre-agg for unfiltered L0 rows;
     * Meta spend is overlaid from hourly cost tables (no per-click scan).
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
        if (!$this->preAggregateReader->canUseBreakdown($groupBy, $parentPath, $filters)) {
            return null;
        }

        $apiCost = $this->campaignUsesIntegratedApiCost($campaign);
        // Filtered / nested expands still need scoped per-click cost allocation.
        if ($apiCost && ($parentPath !== [] || $filters->requiresScopedCost())) {
            return null;
        }

        $utcAligned = Formatter::canUseUtcSummaryDateRange($dateFrom, $dateTo, $utcFrom, $utcTo);

        // summary_date is UTC — never map it onto non-UTC calendar day labels for date dim.
        if ($groupBy === 'date' && !$utcAligned) {
            return null;
        }

        $rows = null;
        if ($utcAligned) {
            $summaryDateFrom = substr($utcFrom, 0, 10);
            $summaryDateTo = substr($utcTo, 0, 10);
            if (!TimezoneSummaryBlend::isSummaryReliable($this->db, [$campaignId], $summaryDateFrom, $summaryDateTo)) {
                return null;
            }

            $rows = $this->preAggregateReader->queryBreakdownRows(
                $campaignId,
                $groupBy,
                $summaryDateFrom,
                $summaryDateTo,
                $parentPath,
                $filters
            );
        } else {
            $segments = TimezoneSummaryBlend::segments($utcFrom, $utcTo);
            // Edges-only non-UTC windows have no full UTC summary days — fall through to
            // lean covering-index raw (timezone-accurate). Do not expand to UTC summary
            // dates (that over-counts PT "today" by including adjacent calendar hours).
            if (TimezoneSummaryBlend::resolveSource($segments) === 'raw_clicks') {
                return null;
            }

            $rows = $this->queryBreakdownRowsBlended(
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

        if ($rows === null || $rows === []) {
            return $rows;
        }

        if ($apiCost) {
            $rows = $this->overlayMappedMetaCostsOnBreakdownRows(
                $campaignId,
                $groupBy,
                $rows,
                $utcFrom,
                $utcTo,
                $campaign
            );
        }

        return $rows;
    }

    /**
     * Replace pre-agg (usually $0) cost with Meta hourly spend for ad/adset dimensions.
     * Other dimensions get visitor-proportional share of campaign Meta total.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>|null $campaign
     * @return list<array<string, mixed>>
     */
    private function overlayMappedMetaCostsOnBreakdownRows(
        int $campaignId,
        string $groupBy,
        array $rows,
        string $utcFrom,
        string $utcTo,
        ?array $campaign,
        array $parentPath = []
    ): array {
        $dim = CampaignStatsExpressions::unwrapDimensionKey($groupBy);
        $aggregator = new FacebookCostAggregator($this->db);
        $campaignTotal = $aggregator->getCampaignTotalCost($campaignId, $utcFrom, $utcTo, null);
        $manual = 0.0;
        foreach ($rows as $row) {
            $manual += (float) ($row['cost'] ?? 0);
        }
        $fbTotal = max(0.0, $campaignTotal - $manual);

        // Nested under Ad Set / Ad: allocate that parent's mapped Meta spend, not campaign total.
        $parentPool = $this->parentMappedMetaSpendPool($campaignId, $parentPath, $utcFrom, $utcTo, $campaign);
        if ($parentPool !== null) {
            $fbTotal = max(0.0, $parentPool);
        }

        if ($fbTotal <= 0) {
            return $rows;
        }

        if ($dim === 'adset_name' || $dim === 'ad_name') {
            $byName = $this->mappedMetaSpendByDimensionName($campaignId, $dim, $utcFrom, $utcTo, $campaign);
            if ($byName !== []) {
                foreach ($rows as &$row) {
                    $key = (string) ($row['group'] ?? $row['group_key'] ?? '');
                    $fb = (float) ($byName[$key] ?? 0);
                    $row['cost'] = round((float) ($row['cost'] ?? 0) + $fb, 4);
                    $rev = (float) ($row['revenue'] ?? 0);
                    $cost = (float) $row['cost'];
                    $row['profit'] = round($rev - $cost, 4);
                    $row['roi'] = $cost > 0 ? round((($rev - $cost) / $cost) * 100, 2) : 0.0;
                }
                unset($row);

                return $rows;
            }
        }

        $visitorSum = 0;
        foreach ($rows as $row) {
            $visitorSum += (int) ($row['clicks'] ?? $row['visitors'] ?? 0);
        }
        if ($visitorSum <= 0) {
            return $rows;
        }
        foreach ($rows as &$row) {
            $share = ((int) ($row['clicks'] ?? $row['visitors'] ?? 0)) / $visitorSum;
            $row['cost'] = round((float) ($row['cost'] ?? 0) + ($fbTotal * $share), 4);
            $rev = (float) ($row['revenue'] ?? 0);
            $cost = (float) $row['cost'];
            $row['profit'] = round($rev - $cost, 4);
            $row['roi'] = $cost > 0 ? round((($rev - $cost) / $cost) * 100, 2) : 0.0;
        }
        unset($row);

        return $rows;
    }

    /**
     * When drilling under adset_name / ad_name, Meta pool is that parent's mapped spend.
     *
     * @param list<array{dimension: string, value: string}> $parentPath
     * @param array<string, mixed>|null $campaign
     */
    private function parentMappedMetaSpendPool(
        int $campaignId,
        array $parentPath,
        string $utcFrom,
        string $utcTo,
        ?array $campaign
    ): ?float {
        if ($parentPath === []) {
            return null;
        }
        $last = $parentPath[count($parentPath) - 1];
        $dim = CampaignStatsExpressions::unwrapDimensionKey((string) ($last['dimension'] ?? ''));
        if ($dim !== 'adset_name' && $dim !== 'ad_name') {
            return null;
        }
        $name = (string) ($last['value'] ?? '');
        if ($name === '') {
            return null;
        }
        $byName = $this->mappedMetaSpendByDimensionName($campaignId, $dim, $utcFrom, $utcTo, $campaign);

        return isset($byName[$name]) ? (float) $byName[$name] : 0.0;
    }

    /**
     * @param array<string, mixed>|null $campaign
     * @return array<string, float> dimension name => spend
     */
    private function mappedMetaSpendByDimensionName(
        int $campaignId,
        string $dim,
        string $utcFrom,
        string $utcTo,
        ?array $campaign
    ): array {
        if ($campaign === null || empty($campaign['facebook_marketing_campaign_id'])) {
            return [];
        }

        $adAccountRowId = (int) ($campaign['facebook_marketing_ad_account_id'] ?? 0);
        $stmt = $this->db->prepare(
            'SELECT meta_campaign_id FROM facebook_marketing_campaigns WHERE id = ? LIMIT 1'
        );
        if ($stmt === false) {
            return [];
        }
        $fmcId = (int) $campaign['facebook_marketing_campaign_id'];
        $stmt->bind_param('i', $fmcId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || empty($row['meta_campaign_id'])) {
            return [];
        }
        $metaCampaignId = (string) $row['meta_campaign_id'];

        $integrationId = null;
        if ($adAccountRowId > 0) {
            $s = $this->db->prepare(
                'SELECT facebook_marketing_integration_id FROM facebook_marketing_ad_accounts WHERE id = ? LIMIT 1'
            );
            if ($s !== false) {
                $s->bind_param('i', $adAccountRowId);
                $s->execute();
                $ar = $s->get_result()->fetch_assoc();
                $s->close();
                if ($ar && $ar['facebook_marketing_integration_id'] !== null) {
                    $integrationId = (int) $ar['facebook_marketing_integration_id'];
                }
            }
        }

        $dateFromDay = substr($utcFrom, 0, 10);
        $dateToDay = substr($utcTo, 0, 10);
        $dateFromHour = (int) substr($utcFrom, 11, 2);
        $dateToHour = (int) substr($utcTo, 11, 2);
        $idCol = $dim === 'ad_name' ? 'ad_id' : 'adset_id';
        $nameCol = $dim === 'ad_name' ? 'ad_name_value' : 'adset_name_value';
        $accountSql = ($integrationId !== null && $integrationId > 0) ? ' AND a.ad_account_id = ?' : '';

        if ($dim === 'ad_name') {
            $sql = "
                SELECT a.ad_id AS entity_id, COALESCE(SUM(a.delta_spend), 0) AS spend
                FROM ad_hourly_costs a
                INNER JOIN facebook_adset_campaign_map facm
                    ON facm.adset_id = a.adset_id
                    AND facm.meta_campaign_id = ?
                    AND facm.facebook_marketing_ad_account_id = ?
                WHERE (a.date > ? OR (a.date = ? AND a.hour >= ?))
                  AND (a.date < ? OR (a.date = ? AND a.hour <= ?))
                  {$accountSql}
                GROUP BY a.ad_id
            ";
        } else {
            $sql = "
                SELECT entity_id, SUM(spend) AS spend FROM (
                    SELECT a.adset_id AS entity_id, SUM(a.delta_spend) AS spend
                    FROM ad_hourly_costs a
                    INNER JOIN facebook_adset_campaign_map facm
                        ON facm.adset_id = a.adset_id
                        AND facm.meta_campaign_id = ?
                        AND facm.facebook_marketing_ad_account_id = ?
                    WHERE (a.date > ? OR (a.date = ? AND a.hour >= ?))
                      AND (a.date < ? OR (a.date = ? AND a.hour <= ?))
                      {$accountSql}
                    GROUP BY a.adset_id
                    UNION ALL
                    SELECT as_cost.adset_id AS entity_id, SUM(as_cost.delta_spend) AS spend
                    FROM adset_hourly_costs as_cost
                    INNER JOIN facebook_adset_campaign_map facm
                        ON facm.adset_id = as_cost.adset_id
                        AND facm.meta_campaign_id = ?
                        AND facm.facebook_marketing_ad_account_id = ?
                    WHERE (as_cost.date > ? OR (as_cost.date = ? AND as_cost.hour >= ?))
                      AND (as_cost.date < ? OR (as_cost.date = ? AND as_cost.hour <= ?))
                      " . (($integrationId !== null && $integrationId > 0) ? ' AND as_cost.ad_account_id = ?' : '') . "
                      AND NOT EXISTS (
                        SELECT 1 FROM ad_hourly_costs a
                        WHERE a.adset_id = as_cost.adset_id
                          AND a.date = as_cost.date
                          AND a.hour = as_cost.hour
                          " . (($integrationId !== null && $integrationId > 0) ? ' AND a.ad_account_id = as_cost.ad_account_id' : '') . "
                      )
                    GROUP BY as_cost.adset_id
                ) x
                GROUP BY entity_id
            ";
        }

        $types = 'sississi';
        $params = [
            $metaCampaignId,
            $adAccountRowId,
            $dateFromDay,
            $dateFromDay,
            $dateFromHour,
            $dateToDay,
            $dateToDay,
            $dateToHour,
        ];
        if ($integrationId !== null && $integrationId > 0) {
            $types .= 'i';
            $params[] = $integrationId;
        }
        if ($dim === 'adset_name') {
            $types .= 'sississi';
            $params = array_merge($params, [
                $metaCampaignId,
                $adAccountRowId,
                $dateFromDay,
                $dateFromDay,
                $dateFromHour,
                $dateToDay,
                $dateToDay,
                $dateToHour,
            ]);
            if ($integrationId !== null && $integrationId > 0) {
                $types .= 'i';
                $params[] = $integrationId;
            }
        }

        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $spendById = [];
        while ($r = $res->fetch_assoc()) {
            $spendById[(int) $r['entity_id']] = (float) $r['spend'];
        }
        $stmt->close();
        if ($spendById === []) {
            return [];
        }

        $out = [];
        foreach ($spendById as $entityId => $spend) {
            $q = $this->db->prepare(
                "SELECT {$nameCol} AS n FROM clicks
                 WHERE campaign_id = ? AND {$idCol} = ? AND {$nameCol} IS NOT NULL AND {$nameCol} != ''
                 LIMIT 1"
            );
            if ($q === false) {
                continue;
            }
            $q->bind_param('ii', $campaignId, $entityId);
            $q->execute();
            $nr = $q->get_result()->fetch_assoc();
            $q->close();
            $name = trim((string) ($nr['n'] ?? ''));
            if ($name === '') {
                $name = (string) $entityId;
            }
            $out[$name] = ($out[$name] ?? 0.0) + $spend;
        }

        return $out;
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
        unset($campaign);

        // Metrics only — Meta overlay is applied once by tryPreAggregate / queryBreakdownLevel.
        return $this->queryLeanBreakdownAggregateRows(
            $campaignId,
            $groupBy,
            $utcFrom,
            $utcTo,
            $userTimezone,
            $dateFrom,
            $parentPath,
            $filters,
            $filterKeys
        );
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
        ReportingQueryCancel::throwIfAborted();
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
        ReportingQueryCancel::throwIfAborted();
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

        ReportingQueryCancel::throwIfAborted();
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
            ReportingQueryCancel::throwIfAborted();
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

        // Prefer summary/token pre-agg when eligible (Hermes summary-first path).
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

        // Raw path: metrics from clicks; Meta/Google spend overlaid from hourly tables unless
        // advanced filters require scoped per-click allocation (never scan cost joins at 100k+).
        $apiCost = $this->campaignUsesIntegratedApiCost($campaign);
        $useApiCostJoins = $apiCost && $filters->requiresScopedCost();

        if (!$useApiCostJoins) {
            $allRows = $this->queryLeanBreakdownAggregateRows(
                $campaignId,
                $groupBy,
                $utcFrom,
                $utcTo,
                $userTimezone,
                $dateFrom,
                $parentPath,
                $filters,
                $filterKeys
            );
            if ($apiCost) {
                $allRows = $this->overlayMappedMetaCostsOnBreakdownRows(
                    $campaignId,
                    $groupBy,
                    $allRows,
                    $utcFrom,
                    $utcTo,
                    $campaign,
                    $parentPath
                );
            }

            return $this->paginateBreakdownRows($allRows, $groupBy, $dateFrom, $dateTo, $page, $perPage, $sort, $order);
        }

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
        $fbCase = CampaignStatsCostSql::perClickFacebookCostCase($clicksTable);
        $gaCase = CampaignStatsCostSql::perClickGoogleCostCase($clicksTable);
        $fbJoins = CampaignStatsCostSql::scopedApiCostJoins($clicksTable)['joins'];

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
        [$types, $params] = CampaignStatsCostSql::mergeScopedApiJoinDateBinds($utcFrom, $utcTo, $types, $params);

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

    /**
     * Timezone-accurate summary via covering index (no COUNT DISTINCT / fat joins).
     *
     * @return array{visitors: int, lp_clicks: int, direct_clicks: int, conversions: int, manual_cost: float, revenue: float}|null
     */
    private function queryLeanSummaryTotals(
        int $campaignId,
        string $utcFrom,
        string $utcTo,
        CampaignStatsQueryFilters $filters
    ): ?array {
        $force = '';
        $idx = $this->db->query(
            "SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clicks'
               AND INDEX_NAME = 'idx_clicks_ts_stats_cover' LIMIT 1"
        );
        if ($idx && $idx->num_rows > 0) {
            $force = ' FORCE INDEX (idx_clicks_ts_stats_cover)';
        }
        $includedSql = StatsExclusionFlag::columnExists($this->db, 'clicks')
            ? ' AND cl.exclude_from_stats = 0'
            : '';
        [$filterSql, $filterTypes, $filterParams] = $filters->clickFilterSql($this->db, 'cl', []);

        $sql = "
            SELECT COUNT(*) AS visitors,
                   SUM(CASE WHEN cl.lp_click = 1 THEN 1 ELSE 0 END) AS lp_clicks,
                   SUM(CASE WHEN cl.lp_click = 1 AND cl.landing_page_id IS NULL THEN 1 ELSE 0 END) AS direct_clicks,
                   COALESCE(SUM(cl.cost), 0) AS manual_cost
            FROM clicks cl{$force}
            WHERE cl.campaign_id = ? AND cl.ts >= ? AND cl.ts <= ?{$includedSql}{$filterSql}
        ";
        $types = 'iss' . $filterTypes;
        $params = array_merge([$campaignId, $utcFrom, $utcTo], $filterParams);
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        $clCover = ClicksIndexHints::clickIdCoverAlias($this->db, 'cl', 'clicks');
        $convSql = "
            SELECT COUNT(*) AS conversions,
                   COALESCE(SUM(COALESCE(cv.payout, cv.value)), 0) AS revenue
            FROM conversions cv
            INNER JOIN clicks {$clCover} ON cl.click_id = cv.click_id
            WHERE cl.campaign_id = ? AND cl.ts >= ? AND cl.ts <= ?{$includedSql}{$filterSql}
        ";
        $conversions = 0;
        $revenue = 0.0;
        $convStmt = $this->db->prepare($convSql);
        if ($convStmt !== false) {
            $convStmt->bind_param($types, ...$params);
            $convStmt->execute();
            $convRow = $convStmt->get_result()->fetch_assoc() ?: [];
            $convStmt->close();
            $conversions = (int) ($convRow['conversions'] ?? 0);
            $revenue = (float) ($convRow['revenue'] ?? 0);
        }

        return [
            'visitors' => (int) ($row['visitors'] ?? 0),
            'lp_clicks' => (int) ($row['lp_clicks'] ?? 0),
            'direct_clicks' => (int) ($row['direct_clicks'] ?? 0),
            'conversions' => $conversions,
            'manual_cost' => (float) ($row['manual_cost'] ?? 0),
            'revenue' => $revenue,
        ];
    }

    /**
     * Fast raw breakdown: covering/index-only GROUP BY + COUNT(*), conversions in a second
     * pass. Avoids COUNT(DISTINCT), full conversions derived table, and fat extra_json reads.
     *
     * @param list<array{dimension: string, value: string}> $parentPath
     * @param list<string> $filterKeys
     * @return list<array<string, mixed>>
     */
    private function queryLeanBreakdownAggregateRows(
        int $campaignId,
        string $groupBy,
        string $utcFrom,
        string $utcTo,
        string $userTimezone,
        string $dateFrom,
        array $parentPath,
        CampaignStatsQueryFilters $filters,
        array $filterKeys
    ): array {
        $timezoneOffset = CampaignStatsExpressions::mysqlTimezoneOffset($userTimezone, $dateFrom);
        $parts = CampaignStatsExpressions::groupKeyParts($groupBy, $timezoneOffset);
        $groupExpr = $parts['expr'];
        $labelExpr = $parts['label_expr'];
        $force = $this->leanBreakdownForceIndex($groupBy);
        $includedSql = '';
        // exclude_from_stats is missing from most dimension indexes; adding it forces
        // fat-row lookups (~13s / 50k). Only apply when the window actually has exclusions.
        if (StatsExclusionFlag::columnExists($this->db, 'clicks')
            && $this->rangeHasExcludedClicks($campaignId, $utcFrom, $utcTo)
        ) {
            $includedSql = ' AND cl.exclude_from_stats = 0';
        }

        $selectLabel = $labelExpr !== null ? ", {$labelExpr} AS group_label" : ', NULL AS group_label';
        $joinOffer = ($groupBy === 'offer') ? 'LEFT JOIN offers o ON o.id = cl.offer_id' : '';
        $joinLp = ($groupBy === 'landing') ? 'LEFT JOIN landing_pages lp ON lp.id = cl.landing_page_id' : '';
        $groupBySql = $groupExpr . ($labelExpr !== null ? ", {$labelExpr}" : '');

        [$parentSql, $parentParams, $parentTypes] = $this->buildParentFilterSql($parentPath, $timezoneOffset);
        // Do not let clickFilterSql inject exclude_from_stats — that predicate is applied
        // via $includedSql only when exclusions exist (keeps adset/region index-only).
        [$filterSql, $filterTypes, $filterParams] = $filters->clickFilterSql($this->db, 'cl', $filterKeys, false);

        $dim = CampaignStatsExpressions::unwrapDimensionKey($groupBy);
        $indexOnlyCounts = in_array($dim, ['adset_name', 'ad_name', 'adset_id', 'ad_id'], true);
        // Dimension indexes for Meta tokens omit lp_click/cost — aggregating those columns
        // forces fat extra_json row reads (~12s / 50k). COUNT(*) stays index-only; Meta
        // spend is overlaid after; lp/direct come from a campaign-level cover ratio.
        if ($indexOnlyCounts) {
            $sql = "
                SELECT {$groupExpr} AS group_key
                       {$selectLabel},
                       COUNT(*) AS clicks,
                       0 AS lp_clicks,
                       0 AS direct_clicks,
                       0 AS manual_cost
                FROM clicks cl{$force}
                {$joinOffer}
                {$joinLp}
                WHERE cl.campaign_id = ? AND cl.ts >= ? AND cl.ts <= ?{$includedSql}{$filterSql}
                {$parentSql}
                GROUP BY {$groupBySql}
            ";
        } else {
            $sql = "
                SELECT {$groupExpr} AS group_key
                       {$selectLabel},
                       COUNT(*) AS clicks,
                       SUM(CASE WHEN cl.lp_click = 1 THEN 1 ELSE 0 END) AS lp_clicks,
                       SUM(CASE WHEN cl.lp_click = 1 AND cl.landing_page_id IS NULL THEN 1 ELSE 0 END) AS direct_clicks,
                       COALESCE(SUM(cl.cost), 0) AS manual_cost
                FROM clicks cl{$force}
                {$joinOffer}
                {$joinLp}
                WHERE cl.campaign_id = ? AND cl.ts >= ? AND cl.ts <= ?{$includedSql}{$filterSql}
                {$parentSql}
                GROUP BY {$groupBySql}
            ";
        }
        $types = 'iss' . $filterTypes . $parentTypes;
        $params = array_merge([$campaignId, $utcFrom, $utcTo], $filterParams, $parentParams);
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $byKey = [];
        while ($row = $result->fetch_assoc()) {
            $key = (string) ($row['group_key'] ?? 'N/A');
            $byKey[$key] = [
                'group_key' => $key,
                'group_label' => $row['group_label'] ?? null,
                'clicks' => (int) ($row['clicks'] ?? 0),
                'lp_clicks' => (int) ($row['lp_clicks'] ?? 0),
                'direct_clicks' => (int) ($row['direct_clicks'] ?? 0),
                'conversions' => 0,
                'manual_cost' => (float) ($row['manual_cost'] ?? 0),
                'fb_cost' => 0.0,
                'ga_cost' => 0.0,
                'revenue' => 0.0,
            ];
        }
        $stmt->close();

        if ($indexOnlyCounts && $byKey !== []) {
            // Skip clicks⋈conversions GROUP BY adset_name (forces fat-row reads).
            $campaignTotals = $this->queryLeanSummaryTotals($campaignId, $utcFrom, $utcTo, $filters);
            if ($campaignTotals !== null) {
                $visitorSum = 0;
                foreach ($byKey as $row) {
                    $visitorSum += (int) $row['clicks'];
                }
                if ($visitorSum > 0) {
                    $lpTotal = (int) $campaignTotals['lp_clicks'];
                    $directTotal = (int) $campaignTotals['direct_clicks'];
                    $manualTotal = (float) $campaignTotals['manual_cost'];
                    $convTotal = (int) $campaignTotals['conversions'];
                    $revTotal = (float) $campaignTotals['revenue'];
                    foreach ($byKey as &$row) {
                        $share = $row['clicks'] / $visitorSum;
                        $row['lp_clicks'] = (int) round($lpTotal * $share);
                        $row['direct_clicks'] = (int) round($directTotal * $share);
                        $row['manual_cost'] = round($manualTotal * $share, 4);
                        $row['conversions'] = (int) round($convTotal * $share);
                        $row['revenue'] = round($revTotal * $share, 4);
                    }
                    unset($row);
                }
            }
        } else {
            $clCover = ClicksIndexHints::clickIdCoverAlias($this->db, 'cl', 'clicks');
            $convSql = "
                SELECT {$groupExpr} AS group_key,
                       COUNT(*) AS conversions,
                       COALESCE(SUM(COALESCE(cv.payout, cv.value)), 0) AS revenue
                FROM conversions cv
                STRAIGHT_JOIN clicks {$clCover} ON cl.click_id = cv.click_id
                {$joinOffer}
                {$joinLp}
                WHERE cl.campaign_id = ? AND cl.ts >= ? AND cl.ts <= ?{$includedSql}{$filterSql}
                {$parentSql}
                GROUP BY {$groupExpr}
            ";
            $convStmt = $this->db->prepare($convSql);
            if ($convStmt !== false) {
                $convStmt->bind_param($types, ...$params);
                $convStmt->execute();
                $convRes = $convStmt->get_result();
                while ($row = $convRes->fetch_assoc()) {
                    $key = (string) ($row['group_key'] ?? 'N/A');
                    if (!isset($byKey[$key])) {
                        $byKey[$key] = [
                            'group_key' => $key,
                            'group_label' => null,
                            'clicks' => 0,
                            'lp_clicks' => 0,
                            'direct_clicks' => 0,
                            'conversions' => 0,
                            'manual_cost' => 0.0,
                            'fb_cost' => 0.0,
                            'ga_cost' => 0.0,
                            'revenue' => 0.0,
                        ];
                    }
                    $byKey[$key]['conversions'] = (int) ($row['conversions'] ?? 0);
                    $byKey[$key]['revenue'] = (float) ($row['revenue'] ?? 0);
                }
                $convStmt->close();
            }
        }

        $rows = [];
        foreach ($byKey as $row) {
            $rows[] = $this->formatBreakdownRow($row);
        }

        return $rows;
    }

    /**
     * Cheap covering-index probe: any exclude_from_stats=1 rows in the window?
     */
    private function rangeHasExcludedClicks(int $campaignId, string $utcFrom, string $utcTo): bool
    {
        static $memo = [];
        $key = $campaignId . '|' . $utcFrom . '|' . $utcTo;
        if (array_key_exists($key, $memo)) {
            return $memo[$key];
        }
        $force = '';
        $idx = $this->db->query(
            "SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clicks'
               AND INDEX_NAME = 'idx_clicks_ts_stats_cover' LIMIT 1"
        );
        if ($idx && $idx->num_rows > 0) {
            $force = ' FORCE INDEX (idx_clicks_ts_stats_cover)';
        }
        $stmt = $this->db->prepare(
            "SELECT 1 FROM clicks cl{$force}
             WHERE cl.campaign_id = ? AND cl.ts >= ? AND cl.ts <= ? AND cl.exclude_from_stats = 1
             LIMIT 1"
        );
        if ($stmt === false) {
            $memo[$key] = true;

            return true;
        }
        $stmt->bind_param('iss', $campaignId, $utcFrom, $utcTo);
        $stmt->execute();
        $has = $stmt->get_result()->fetch_assoc() !== null;
        $stmt->close();
        $memo[$key] = $has;

        return $has;
    }

    private function leanBreakdownForceIndex(string $groupBy): string
    {
        $dim = CampaignStatsExpressions::unwrapDimensionKey($groupBy);
        $index = match ($dim) {
            'region', 'country', 'city' => 'idx_clicks_region_ts',
            'landing', 'offer', 'date' => 'idx_clicks_ts_stats_cover',
            'adset_name' => 'idx_clicks_campaign_ts_adset_name_value',
            'ad_name' => 'idx_clicks_campaign_ts_ad_name_value',
            default => 'idx_clicks_ts_stats_cover',
        };
        $check = $this->db->query(
            "SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clicks'
               AND INDEX_NAME = '" . $this->db->real_escape_string($index) . "' LIMIT 1"
        );
        if ($check && $check->num_rows > 0) {
            return ' FORCE INDEX (' . $index . ')';
        }

        return '';
    }

    /**
     * Conversions aggregate scoped to this campaign's click window (not the whole table).
     */
    private function scopedConversionsAggJoin(int $campaignId, string $utcFrom, string $utcTo): string
    {
        $cid = (int) $campaignId;
        $from = $this->db->real_escape_string($utcFrom);
        $to = $this->db->real_escape_string($utcTo);
        $clCover = ClicksIndexHints::clickIdCoverAlias($this->db, 'clx', 'clicks');

        return "LEFT JOIN (
            SELECT cv.click_id,
                   COUNT(*) AS conversion_count,
                   SUM(COALESCE(cv.payout, cv.value)) AS revenue_sum
            FROM conversions cv
            INNER JOIN clicks {$clCover} ON clx.click_id = cv.click_id
            WHERE clx.campaign_id = {$cid}
              AND clx.ts >= '{$from}'
              AND clx.ts <= '{$to}'
            GROUP BY cv.click_id
        ) conv ON conv.click_id = cl.click_id";
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
        ReportingQueryCancel::throwIfAborted();
        $needsScopedCost = $this->campaignUsesIntegratedApiCost($campaign) && $filters->requiresScopedCost();

        // Unfiltered: covering-index hour buckets (avoids COUNT DISTINCT + fat conversions derived join).
        if (!$needsScopedCost && !$filters->hasActiveFilters()) {
            return $this->chartHourlyFastUnfiltered(
                $campaignId,
                $dateFrom,
                $utcFrom,
                $utcTo,
                $userTimezone,
                $campaign
            );
        }

        $clicksTable = $this->clicksTable;
        $usePersistedFlag = StatsExclusionFlag::columnExists($this->db, $clicksTable);
        $fbCase = '0';
        $gaCase = '0';
        $fbJoins = '';
        if ($needsScopedCost) {
            $fbCase = CampaignStatsCostSql::perClickFacebookCostCase($clicksTable);
            $gaCase = CampaignStatsCostSql::perClickGoogleCostCase($clicksTable);
            $fbJoins = CampaignStatsCostSql::scopedApiCostJoins($clicksTable)['joins'];
        }
        $filterKeys = $this->filterableKeys($campaignId, $dateFrom, $dateTo, $userTimezone);
        $visitors = CampaignStatsExpressions::visitorCountExpr('cl', 'ts', $usePersistedFlag);
        $actionClicks = CampaignStatsExpressions::actionClicksCountExpr('cl', 'ts', $usePersistedFlag);
        $conversions = CampaignStatsExpressions::conversionsCountExpr('cl', 'ts', $usePersistedFlag);
        $timezoneOffset = CampaignStatsExpressions::mysqlTimezoneOffset($userTimezone, $dateFrom);
        $useUtcHour = ($timezoneOffset === '+00:00' || strtoupper($userTimezone) === 'UTC');

        try {
            $userTz = new \DateTimeZone($userTimezone);
            $utcTz = new \DateTimeZone('UTC');
            $dayStartLocal = new \DateTimeImmutable($dateFrom . ' 00:00:00', $userTz);
            $utcDayStart = $dayStartLocal->setTimezone($utcTz)->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            $utcDayStart = $utcFrom;
            $useUtcHour = true;
        }

        if ($useUtcHour) {
            $hourExpr = 'COALESCE(HOUR(cl.ts), -1)';
            $hourBindPrefix = '';
            $hourBindParams = [];
        } else {
            $hourExpr = 'FLOOR(TIMESTAMPDIFF(SECOND, ?, cl.ts) / 3600)';
            $hourBindPrefix = 's';
            $hourBindParams = [$utcDayStart];
        }

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
            GROUP BY hour
            ORDER BY hour ASC
        ";

        if ($needsScopedCost) {
            $types = $hourBindPrefix;
            $params = $hourBindParams;
            $joinPrefix = str_repeat('s', CampaignStatsCostSql::SCOPED_JOIN_DATE_BINDS);
            $joinDates = [];
            for ($i = 0; $i < (int) (CampaignStatsCostSql::SCOPED_JOIN_DATE_BINDS / 2); $i++) {
                $joinDates[] = $utcFrom;
                $joinDates[] = $utcTo;
            }
            $types .= $joinPrefix . 'iss' . $filterTypes;
            $params = array_merge(
                $params,
                $joinDates,
                [$campaignId, $utcFrom, $utcTo],
                $filterParams
            );
        } else {
            $types = $hourBindPrefix . 'iss' . $filterTypes;
            $params = array_merge(
                $hourBindParams,
                [$campaignId, $utcFrom, $utcTo],
                $filterParams
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

        if (!$needsScopedCost && $this->campaignUsesIntegratedApiCost($campaign)) {
            $apiByHour = $this->metaApiSpendByUserHour(
                $campaignId,
                $dateFrom,
                $utcFrom,
                $utcTo,
                $userTimezone,
                $campaign
            );
            for ($h = 0; $h < 24; $h++) {
                $byHour[$h]['cost'] = round((float) $byHour[$h]['cost'] + (float) ($apiByHour[$h] ?? 0), 4);
            }
        }

        return $this->formatHourlyChartPayload($byHour);
    }

    /**
     * Fast unfiltered hourly chart: covering-index COUNT(*) + conversion join via click_id cover.
     *
     * @param array<string, mixed>|null $campaign
     * @return array{labels: list<string>, datasets: array<string, list<float|int>>, granularity: string}
     */
    private function chartHourlyFastUnfiltered(
        int $campaignId,
        string $dateFrom,
        string $utcFrom,
        string $utcTo,
        string $userTimezone,
        ?array $campaign
    ): array {
        $byHour = array_fill(0, 24, ['visitors' => 0, 'clicks' => 0, 'conversions' => 0, 'revenue' => 0.0, 'cost' => 0.0]);
        $includedSql = '';
        if (StatsExclusionFlag::columnExists($this->db, 'clicks')) {
            $includedSql = ' AND cl.exclude_from_stats = 0';
        }
        $timezoneOffset = CampaignStatsExpressions::mysqlTimezoneOffset($userTimezone, $dateFrom);
        $useUtcHour = ($timezoneOffset === '+00:00' || strtoupper($userTimezone) === 'UTC');

        try {
            $userTz = new \DateTimeZone($userTimezone);
            $utcTz = new \DateTimeZone('UTC');
            $dayStartLocal = new \DateTimeImmutable($dateFrom . ' 00:00:00', $userTz);
            $utcDayStart = $dayStartLocal->setTimezone($utcTz)->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            $utcDayStart = $utcFrom;
            $useUtcHour = true;
        }

        $force = '';
        $idxCheck = $this->db->query(
            "SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clicks'
               AND INDEX_NAME = 'idx_clicks_ts_stats_cover' LIMIT 1"
        );
        if ($idxCheck && $idxCheck->num_rows > 0) {
            $force = ' FORCE INDEX (idx_clicks_ts_stats_cover)';
        }

        if ($useUtcHour) {
            $hourExpr = 'HOUR(cl.ts)';
            $types = 'iss';
            $params = [$campaignId, $utcFrom, $utcTo];
        } else {
            $hourExpr = 'FLOOR(TIMESTAMPDIFF(SECOND, ?, cl.ts) / 3600)';
            $types = 'siss';
            $params = [$utcDayStart, $campaignId, $utcFrom, $utcTo];
        }

        $sql = "
            SELECT {$hourExpr} AS hour,
                   COUNT(*) AS visitors,
                   SUM(CASE WHEN cl.lp_click = 1 THEN 1 ELSE 0 END) AS clicks,
                   COALESCE(SUM(cl.cost), 0) AS manual_cost
            FROM clicks cl{$force}
            WHERE cl.campaign_id = ? AND cl.ts >= ? AND cl.ts <= ?
            {$includedSql}
            GROUP BY hour
            ORDER BY hour ASC
        ";
        // Bind order: optional midnight, then WHERE campaign/from/to — but hourExpr ? comes first when present.
        if ($useUtcHour) {
            // WHERE uses campaign, from, to — hourExpr has no bind. Fix: campaign is first in WHERE but types iss means i,s,s for campaign,from,to. hourExpr has no ?.
            // SQL has WHERE cl.campaign_id = ? — params order matches.
        } else {
            // SELECT has ? for midnight first, then WHERE three binds.
        }
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            return $this->formatHourlyChartPayload($byHour);
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $hour = (int) ($row['hour'] ?? -1);
            if ($hour < 0 || $hour > 23) {
                continue;
            }
            $byHour[$hour]['visitors'] = (int) ($row['visitors'] ?? 0);
            $byHour[$hour]['clicks'] = (int) ($row['clicks'] ?? 0);
            $byHour[$hour]['cost'] = (float) ($row['manual_cost'] ?? 0);
        }
        $stmt->close();

        $clCover = ClicksIndexHints::clickIdCoverAlias($this->db, 'cl', 'clicks');
        if ($useUtcHour) {
            $convHourExpr = 'HOUR(cl.ts)';
            $convTypes = 'iss';
            $convParams = [$campaignId, $utcFrom, $utcTo];
        } else {
            $convHourExpr = 'FLOOR(TIMESTAMPDIFF(SECOND, ?, cl.ts) / 3600)';
            $convTypes = 'siss';
            $convParams = [$utcDayStart, $campaignId, $utcFrom, $utcTo];
        }
        $convSql = "
            SELECT {$convHourExpr} AS hour,
                   COUNT(*) AS conversions,
                   COALESCE(SUM(COALESCE(cv.payout, cv.value)), 0) AS revenue
            FROM conversions cv
            INNER JOIN clicks {$clCover} ON cl.click_id = cv.click_id
            WHERE cl.campaign_id = ? AND cl.ts >= ? AND cl.ts <= ?
            {$includedSql}
            GROUP BY hour
        ";
        $convStmt = $this->db->prepare($convSql);
        if ($convStmt !== false) {
            $convStmt->bind_param($convTypes, ...$convParams);
            $convStmt->execute();
            $convRes = $convStmt->get_result();
            while ($row = $convRes->fetch_assoc()) {
                $hour = (int) ($row['hour'] ?? -1);
                if ($hour < 0 || $hour > 23) {
                    continue;
                }
                $byHour[$hour]['conversions'] = (int) ($row['conversions'] ?? 0);
                $byHour[$hour]['revenue'] = (float) ($row['revenue'] ?? 0);
            }
            $convStmt->close();
        }

        if ($this->campaignUsesIntegratedApiCost($campaign)) {
            $apiByHour = $this->metaApiSpendByUserHour(
                $campaignId,
                $dateFrom,
                $utcFrom,
                $utcTo,
                $userTimezone,
                $campaign
            );
            for ($h = 0; $h < 24; $h++) {
                $byHour[$h]['cost'] = round((float) $byHour[$h]['cost'] + (float) ($apiByHour[$h] ?? 0), 4);
            }
        }

        return $this->formatHourlyChartPayload($byHour);
    }

    /**
     * @param array<int, array{visitors: int, clicks: int, conversions: int, revenue: float, cost: float}> $byHour
     * @return array{labels: list<string>, datasets: array<string, list<float|int>>, granularity: string}
     */
    private function formatHourlyChartPayload(array $byHour): array
    {
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
        ReportingQueryCancel::throwIfAborted();
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

        $needsScopedCost = $this->campaignUsesIntegratedApiCost($campaign) && $filters->requiresScopedCost();
        if (!$needsScopedCost && !$filters->hasActiveFilters()) {
            return $this->chartDailyFastUnfiltered(
                $campaignId,
                $dateFrom,
                $dateTo,
                $utcFrom,
                $utcTo,
                $userTimezone,
                $campaign
            );
        }

        $clicksTable = $this->clicksTable;
        $usePersistedFlag = StatsExclusionFlag::columnExists($this->db, $clicksTable);
        $fbCase = '0';
        $gaCase = '0';
        $fbJoins = '';
        if ($needsScopedCost) {
            $fbCase = CampaignStatsCostSql::perClickFacebookCostCase($clicksTable);
            $gaCase = CampaignStatsCostSql::perClickGoogleCostCase($clicksTable);
            $fbJoins = CampaignStatsCostSql::scopedApiCostJoins($clicksTable)['joins'];
        }
        $visitors = CampaignStatsExpressions::visitorCountExpr('cl', 'ts', $usePersistedFlag);
        $actionClicks = CampaignStatsExpressions::actionClicksCountExpr('cl', 'ts', $usePersistedFlag);
        $conversions = CampaignStatsExpressions::conversionsCountExpr('cl', 'ts', $usePersistedFlag);
        $filterKeys = $this->filterableKeys($campaignId, $dateFrom, $dateTo, $userTimezone);
        $timezoneOffset = CampaignStatsExpressions::mysqlTimezoneOffset($userTimezone, $dateFrom);
        $useUtcDay = ($timezoneOffset === '+00:00' || strtoupper($userTimezone) === 'UTC'
            || Formatter::canUseUtcSummaryDateRange($dateFrom, $dateTo, $utcFrom, $utcTo));

        [$filterSql, $filterTypes, $filterParams] = $filters->clickFilterSql($this->db, 'cl', $filterKeys);

        // Build CASE day buckets for non-UTC (same pattern as dashboard).
        $dayKeys = [];
        try {
            $userTz = new \DateTimeZone($userTimezone);
            $start = new \DateTimeImmutable($dateFrom . ' 00:00:00', $userTz);
            $end = new \DateTimeImmutable($dateTo . ' 00:00:00', $userTz);
            for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
                $dayKeys[] = $d->format('Y-m-d');
            }
        } catch (\Exception $e) {
            $dayKeys = [];
            $useUtcDay = true;
        }

        $dayExpr = 'DATE(cl.ts)';
        $caseBindTypes = '';
        $caseBindParams = [];
        if (!$useUtcDay && $dayKeys !== []) {
            $utcTz = new \DateTimeZone('UTC');
            $parts = [];
            foreach ($dayKeys as $dayKey) {
                $dayStart = new \DateTimeImmutable($dayKey . ' 00:00:00', $userTz);
                $dayEndEx = $dayStart->modify('+1 day');
                $parts[] = 'WHEN cl.ts >= ? AND cl.ts < ? THEN ?';
                $caseBindParams[] = $dayStart->setTimezone($utcTz)->format('Y-m-d H:i:s');
                $caseBindParams[] = $dayEndEx->setTimezone($utcTz)->format('Y-m-d H:i:s');
                $caseBindParams[] = $dayKey;
                $caseBindTypes .= 'sss';
            }
            $dayExpr = 'CASE ' . implode(' ', $parts) . ' END';
        }

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
            GROUP BY day
            ORDER BY day ASC
        ";

        $types = $caseBindTypes . 'iss' . $filterTypes;
        $params = array_merge($caseBindParams, [$campaignId, $utcFrom, $utcTo], $filterParams);
        if ($needsScopedCost) {
            // Join date binds sit between SELECT CASE placeholders and WHERE binds.
            $joinTypes = str_repeat('s', CampaignStatsCostSql::SCOPED_JOIN_DATE_BINDS);
            $joinDates = [];
            for ($i = 0; $i < (int) (CampaignStatsCostSql::SCOPED_JOIN_DATE_BINDS / 2); $i++) {
                $joinDates[] = $utcFrom;
                $joinDates[] = $utcTo;
            }
            $types = $caseBindTypes . $joinTypes . 'iss' . $filterTypes;
            $params = array_merge($caseBindParams, $joinDates, [$campaignId, $utcFrom, $utcTo], $filterParams);
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

        if (!$needsScopedCost && $this->campaignUsesIntegratedApiCost($campaign)) {
            $this->applyMetaSpendToDailyChartDatasets(
                $filled,
                $campaignId,
                $dateFrom,
                $dateTo,
                $utcFrom,
                $utcTo,
                $userTimezone,
                $campaign
            );
        }

        return ['labels' => $filled['labels'], 'datasets' => $filled['datasets'], 'granularity' => 'day'];
    }

    /**
     * Unfiltered multi-day chart without COUNT(DISTINCT) / conversions derived join.
     *
     * @param array<string, mixed>|null $campaign
     * @return array{labels: list<string>, datasets: array<string, list<float|int>>, granularity: string}
     */
    private function chartDailyFastUnfiltered(
        int $campaignId,
        string $dateFrom,
        string $dateTo,
        string $utcFrom,
        string $utcTo,
        string $userTimezone,
        ?array $campaign
    ): array {
        $includedSql = StatsExclusionFlag::columnExists($this->db, 'clicks')
            ? ' AND cl.exclude_from_stats = 0'
            : '';
        $timezoneOffset = CampaignStatsExpressions::mysqlTimezoneOffset($userTimezone, $dateFrom);
        $useUtcDay = ($timezoneOffset === '+00:00' || strtoupper($userTimezone) === 'UTC'
            || Formatter::canUseUtcSummaryDateRange($dateFrom, $dateTo, $utcFrom, $utcTo));

        $dayKeys = [];
        $userTz = null;
        try {
            $userTz = new \DateTimeZone($userTimezone);
            $start = new \DateTimeImmutable($dateFrom . ' 00:00:00', $userTz);
            $end = new \DateTimeImmutable($dateTo . ' 00:00:00', $userTz);
            for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
                $dayKeys[] = $d->format('Y-m-d');
            }
        } catch (\Exception $e) {
            $dayKeys = [];
            $useUtcDay = true;
        }

        $dayExpr = 'DATE(cl.ts)';
        $caseBindTypes = '';
        $caseBindParams = [];
        if (!$useUtcDay && $dayKeys !== [] && $userTz instanceof \DateTimeZone) {
            $utcTz = new \DateTimeZone('UTC');
            $parts = [];
            foreach ($dayKeys as $dayKey) {
                $dayStart = new \DateTimeImmutable($dayKey . ' 00:00:00', $userTz);
                $dayEndEx = $dayStart->modify('+1 day');
                $parts[] = 'WHEN cl.ts >= ? AND cl.ts < ? THEN ?';
                $caseBindParams[] = $dayStart->setTimezone($utcTz)->format('Y-m-d H:i:s');
                $caseBindParams[] = $dayEndEx->setTimezone($utcTz)->format('Y-m-d H:i:s');
                $caseBindParams[] = $dayKey;
                $caseBindTypes .= 'sss';
            }
            $dayExpr = 'CASE ' . implode(' ', $parts) . ' END';
        }

        $force = '';
        $idxCheck = $this->db->query(
            "SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clicks'
               AND INDEX_NAME = 'idx_clicks_ts_stats_cover' LIMIT 1"
        );
        if ($idxCheck && $idxCheck->num_rows > 0) {
            $force = ' FORCE INDEX (idx_clicks_ts_stats_cover)';
        }

        $sql = "
            SELECT {$dayExpr} AS day,
                   COUNT(*) AS visitors,
                   SUM(CASE WHEN cl.lp_click = 1 THEN 1 ELSE 0 END) AS clicks,
                   COALESCE(SUM(cl.cost), 0) AS manual_cost
            FROM clicks cl{$force}
            WHERE cl.campaign_id = ? AND cl.ts >= ? AND cl.ts <= ?
            {$includedSql}
            GROUP BY day
            ORDER BY day ASC
        ";
        $types = $caseBindTypes . 'iss';
        $params = array_merge($caseBindParams, [$campaignId, $utcFrom, $utcTo]);
        $stmt = $this->db->prepare($sql);
        $labels = [];
        $datasets = [
            'visitors' => [],
            'clicks' => [],
            'conversions' => [],
            'revenue' => [],
            'cost' => [],
        ];
        if ($stmt !== false) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $labels[] = (string) ($row['day'] ?? '');
                $datasets['visitors'][] = (int) ($row['visitors'] ?? 0);
                $datasets['clicks'][] = (int) ($row['clicks'] ?? 0);
                $datasets['conversions'][] = 0;
                $datasets['revenue'][] = 0.0;
                $datasets['cost'][] = (float) ($row['manual_cost'] ?? 0);
            }
            $stmt->close();
        }

        $clCover = ClicksIndexHints::clickIdCoverAlias($this->db, 'cl', 'clicks');
        $convSql = "
            SELECT {$dayExpr} AS day,
                   COUNT(*) AS conversions,
                   COALESCE(SUM(COALESCE(cv.payout, cv.value)), 0) AS revenue
            FROM conversions cv
            INNER JOIN clicks {$clCover} ON cl.click_id = cv.click_id
            WHERE cl.campaign_id = ? AND cl.ts >= ? AND cl.ts <= ?
            {$includedSql}
            GROUP BY day
        ";
        $convStmt = $this->db->prepare($convSql);
        $convByDay = [];
        if ($convStmt !== false) {
            $convStmt->bind_param($types, ...$params);
            $convStmt->execute();
            $convRes = $convStmt->get_result();
            while ($row = $convRes->fetch_assoc()) {
                $day = (string) ($row['day'] ?? '');
                if ($day !== '') {
                    $convByDay[$day] = [
                        'conversions' => (int) ($row['conversions'] ?? 0),
                        'revenue' => (float) ($row['revenue'] ?? 0),
                    ];
                }
            }
            $convStmt->close();
        }
        foreach ($labels as $i => $day) {
            if (isset($convByDay[$day])) {
                $datasets['conversions'][$i] = $convByDay[$day]['conversions'];
                $datasets['revenue'][$i] = $convByDay[$day]['revenue'];
            }
        }

        $filled = CampaignStatsExpressions::fillChartDailySeries($dateFrom, $dateTo, $labels, $datasets);
        if ($this->campaignUsesIntegratedApiCost($campaign)) {
            $this->applyMetaSpendToDailyChartDatasets(
                $filled,
                $campaignId,
                $dateFrom,
                $dateTo,
                $utcFrom,
                $utcTo,
                $userTimezone,
                $campaign
            );
        }

        return ['labels' => $filled['labels'], 'datasets' => $filled['datasets'], 'granularity' => 'day'];
    }

    /**
     * @param array{labels: list<string>, datasets: array<string, list<float|int>>} $filled
     * @param array<string, mixed>|null $campaign
     */
    private function applyMetaSpendToDailyChartDatasets(
        array &$filled,
        int $campaignId,
        string $dateFrom,
        string $dateTo,
        string $utcFrom,
        string $utcTo,
        string $userTimezone,
        ?array $campaign
    ): void {
        $apiByDay = $this->metaApiSpendByUserDay(
            $campaignId,
            $dateFrom,
            $dateTo,
            $utcFrom,
            $utcTo,
            $userTimezone,
            $campaign
        );
        $dayIndex = 0;
        try {
            $userTz = new \DateTimeZone($userTimezone);
            $start = new \DateTimeImmutable($dateFrom . ' 00:00:00', $userTz);
            $end = new \DateTimeImmutable($dateTo . ' 00:00:00', $userTz);
            for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
                $key = $d->format('Y-m-d');
                if (isset($filled['datasets']['cost'][$dayIndex])) {
                    $filled['datasets']['cost'][$dayIndex] = round(
                        (float) $filled['datasets']['cost'][$dayIndex] + (float) ($apiByDay[$key] ?? 0),
                        4
                    );
                }
                $dayIndex++;
            }
        } catch (\Exception $e) {
            // leave manual-only
        }
    }

    /**
     * Multi-day chart from clicks_daily_summary when filters allow (same eligibility as summary pre-agg).
     * Manual cost from summary; Meta API spend overlaid from hourly tables when present.
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
        // UTC summary_date span (common for non-UTC timezones), fall back to raw day buckets.
        if (!Formatter::canUseUtcSummaryDateRange($dateFrom, $dateTo, $utcFrom, $utcTo)) {
            return null;
        }

        $summaryDateFrom = substr($utcFrom, 0, 10);
        $summaryDateTo = substr($utcTo, 0, 10);

        if (!TimezoneSummaryBlend::isSummaryReliable($this->db, [$campaignId], $summaryDateFrom, $summaryDateTo)) {
            return null;
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

        if ($this->campaignUsesIntegratedApiCost($campaign) && !$filters->requiresScopedCost()) {
            $apiByDay = $this->metaApiSpendByUserDay(
                $campaignId,
                $dateFrom,
                $dateTo,
                $utcFrom,
                $utcTo,
                $userTimezone,
                $campaign
            );
            $dayIndex = 0;
            try {
                $userTz = new \DateTimeZone($userTimezone);
                $start = new \DateTimeImmutable($dateFrom . ' 00:00:00', $userTz);
                $end = new \DateTimeImmutable($dateTo . ' 00:00:00', $userTz);
                for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
                    $key = $d->format('Y-m-d');
                    if (isset($filled['datasets']['cost'][$dayIndex])) {
                        $filled['datasets']['cost'][$dayIndex] = round(
                            (float) $filled['datasets']['cost'][$dayIndex] + (float) ($apiByDay[$key] ?? 0),
                            4
                        );
                    }
                    $dayIndex++;
                }
            } catch (\Exception $e) {
                // leave manual-only
            }
        }

        return ['labels' => $filled['labels'], 'datasets' => $filled['datasets'], 'granularity' => 'day'];
    }

    /**
     * Meta hourly spend bucketed into user-timezone hours (single calendar day).
     * Runs over ad_hourly_costs rows only — never scans clicks.
     *
     * @param array<string, mixed>|null $campaign
     * @return array<int, float> hour (0-23) => spend
     */
    private function metaApiSpendByUserHour(
        int $campaignId,
        string $dateFrom,
        string $utcFrom,
        string $utcTo,
        string $userTimezone,
        ?array $campaign
    ): array {
        $out = array_fill(0, 24, 0.0);
        $meta = $this->resolveMetaCampaignMapContext($campaign);
        if ($meta === null) {
            return $out;
        }

        $offset = CampaignStatsExpressions::mysqlTimezoneOffset($userTimezone, $dateFrom);
        $dateFromDay = substr($utcFrom, 0, 10);
        $dateToDay = substr($utcTo, 0, 10);
        $dateFromHour = (int) substr($utcFrom, 11, 2);
        $dateToHour = (int) substr($utcTo, 11, 2);
        $accountSql = ($meta['integration_id'] !== null && $meta['integration_id'] > 0)
            ? ' AND a.ad_account_id = ?'
            : '';

        $hourExpr = "COALESCE(HOUR(CONVERT_TZ(TIMESTAMP(a.date, MAKETIME(a.hour, 0, 0)), '+00:00', ?)), -1)";
        $sql = "
            SELECT {$hourExpr} AS hour, COALESCE(SUM(a.delta_spend), 0) AS spend
            FROM ad_hourly_costs a
            INNER JOIN facebook_adset_campaign_map facm
                ON facm.adset_id = a.adset_id
                AND facm.meta_campaign_id = ?
                AND facm.facebook_marketing_ad_account_id = ?
            WHERE (a.date > ? OR (a.date = ? AND a.hour >= ?))
              AND (a.date < ? OR (a.date = ? AND a.hour <= ?))
              {$accountSql}
            GROUP BY hour
        ";
        $types = 'ssississi';
        $params = [
            $offset,
            $meta['meta_campaign_id'],
            $meta['ad_account_row_id'],
            $dateFromDay,
            $dateFromDay,
            $dateFromHour,
            $dateToDay,
            $dateToDay,
            $dateToHour,
        ];
        if ($meta['integration_id'] !== null && $meta['integration_id'] > 0) {
            $types .= 'i';
            $params[] = $meta['integration_id'];
        }

        $stmt = $this->db->prepare($sql);
        if ($stmt !== false) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $h = (int) ($row['hour'] ?? -1);
                if ($h >= 0 && $h <= 23) {
                    $out[$h] += (float) ($row['spend'] ?? 0);
                }
            }
            $stmt->close();
        }

        // Adset-only hours (no ad-level cost that hour).
        $asAccountSql = ($meta['integration_id'] !== null && $meta['integration_id'] > 0)
            ? ' AND as_cost.ad_account_id = ?'
            : '';
        $asHourExpr = "COALESCE(HOUR(CONVERT_TZ(TIMESTAMP(as_cost.date, MAKETIME(as_cost.hour, 0, 0)), '+00:00', ?)), -1)";
        $asSql = "
            SELECT {$asHourExpr} AS hour, COALESCE(SUM(as_cost.delta_spend), 0) AS spend
            FROM adset_hourly_costs as_cost
            INNER JOIN facebook_adset_campaign_map facm
                ON facm.adset_id = as_cost.adset_id
                AND facm.meta_campaign_id = ?
                AND facm.facebook_marketing_ad_account_id = ?
            WHERE (as_cost.date > ? OR (as_cost.date = ? AND as_cost.hour >= ?))
              AND (as_cost.date < ? OR (as_cost.date = ? AND as_cost.hour <= ?))
              {$asAccountSql}
              AND NOT EXISTS (
                SELECT 1 FROM ad_hourly_costs a
                WHERE a.adset_id = as_cost.adset_id
                  AND a.date = as_cost.date
                  AND a.hour = as_cost.hour
                  " . (($meta['integration_id'] !== null && $meta['integration_id'] > 0)
                    ? ' AND a.ad_account_id = as_cost.ad_account_id'
                    : '') . "
              )
            GROUP BY hour
        ";
        $asTypes = 'ssississi';
        $asParams = [
            $offset,
            $meta['meta_campaign_id'],
            $meta['ad_account_row_id'],
            $dateFromDay,
            $dateFromDay,
            $dateFromHour,
            $dateToDay,
            $dateToDay,
            $dateToHour,
        ];
        if ($meta['integration_id'] !== null && $meta['integration_id'] > 0) {
            $asTypes .= 'i';
            $asParams[] = $meta['integration_id'];
        }
        $asStmt = $this->db->prepare($asSql);
        if ($asStmt !== false) {
            $asStmt->bind_param($asTypes, ...$asParams);
            $asStmt->execute();
            $asRes = $asStmt->get_result();
            while ($row = $asRes->fetch_assoc()) {
                $h = (int) ($row['hour'] ?? -1);
                if ($h >= 0 && $h <= 23) {
                    $out[$h] += (float) ($row['spend'] ?? 0);
                }
            }
            $asStmt->close();
        }

        return $out;
    }

    /**
     * Meta hourly spend bucketed into user calendar days.
     *
     * @param array<string, mixed>|null $campaign
     * @return array<string, float> Y-m-d => spend
     */
    private function metaApiSpendByUserDay(
        int $campaignId,
        string $dateFrom,
        string $dateTo,
        string $utcFrom,
        string $utcTo,
        string $userTimezone,
        ?array $campaign
    ): array {
        $out = [];
        $meta = $this->resolveMetaCampaignMapContext($campaign);
        if ($meta === null) {
            return $out;
        }

        $offset = CampaignStatsExpressions::mysqlTimezoneOffset($userTimezone, $dateFrom);
        $dateFromDay = substr($utcFrom, 0, 10);
        $dateToDay = substr($utcTo, 0, 10);
        $dateFromHour = (int) substr($utcFrom, 11, 2);
        $dateToHour = (int) substr($utcTo, 11, 2);
        $accountSql = ($meta['integration_id'] !== null && $meta['integration_id'] > 0)
            ? ' AND a.ad_account_id = ?'
            : '';

        $dayExpr = "DATE(CONVERT_TZ(TIMESTAMP(a.date, MAKETIME(a.hour, 0, 0)), '+00:00', ?))";
        $sql = "
            SELECT {$dayExpr} AS day, COALESCE(SUM(a.delta_spend), 0) AS spend
            FROM ad_hourly_costs a
            INNER JOIN facebook_adset_campaign_map facm
                ON facm.adset_id = a.adset_id
                AND facm.meta_campaign_id = ?
                AND facm.facebook_marketing_ad_account_id = ?
            WHERE (a.date > ? OR (a.date = ? AND a.hour >= ?))
              AND (a.date < ? OR (a.date = ? AND a.hour <= ?))
              {$accountSql}
            GROUP BY day
        ";
        $types = 'ssississi';
        $params = [
            $offset,
            $meta['meta_campaign_id'],
            $meta['ad_account_row_id'],
            $dateFromDay,
            $dateFromDay,
            $dateFromHour,
            $dateToDay,
            $dateToDay,
            $dateToHour,
        ];
        if ($meta['integration_id'] !== null && $meta['integration_id'] > 0) {
            $types .= 'i';
            $params[] = $meta['integration_id'];
        }

        $stmt = $this->db->prepare($sql);
        if ($stmt !== false) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $day = (string) ($row['day'] ?? '');
                if ($day !== '') {
                    $out[$day] = ($out[$day] ?? 0.0) + (float) ($row['spend'] ?? 0);
                }
            }
            $stmt->close();
        }

        $asAccountSql = ($meta['integration_id'] !== null && $meta['integration_id'] > 0)
            ? ' AND as_cost.ad_account_id = ?'
            : '';
        $asDayExpr = "DATE(CONVERT_TZ(TIMESTAMP(as_cost.date, MAKETIME(as_cost.hour, 0, 0)), '+00:00', ?))";
        $asSql = "
            SELECT {$asDayExpr} AS day, COALESCE(SUM(as_cost.delta_spend), 0) AS spend
            FROM adset_hourly_costs as_cost
            INNER JOIN facebook_adset_campaign_map facm
                ON facm.adset_id = as_cost.adset_id
                AND facm.meta_campaign_id = ?
                AND facm.facebook_marketing_ad_account_id = ?
            WHERE (as_cost.date > ? OR (as_cost.date = ? AND as_cost.hour >= ?))
              AND (as_cost.date < ? OR (as_cost.date = ? AND as_cost.hour <= ?))
              {$asAccountSql}
              AND NOT EXISTS (
                SELECT 1 FROM ad_hourly_costs a
                WHERE a.adset_id = as_cost.adset_id
                  AND a.date = as_cost.date
                  AND a.hour = as_cost.hour
                  " . (($meta['integration_id'] !== null && $meta['integration_id'] > 0)
                    ? ' AND a.ad_account_id = as_cost.ad_account_id'
                    : '') . "
              )
            GROUP BY day
        ";
        $asTypes = 'ssississi';
        $asParams = [
            $offset,
            $meta['meta_campaign_id'],
            $meta['ad_account_row_id'],
            $dateFromDay,
            $dateFromDay,
            $dateFromHour,
            $dateToDay,
            $dateToDay,
            $dateToHour,
        ];
        if ($meta['integration_id'] !== null && $meta['integration_id'] > 0) {
            $asTypes .= 'i';
            $asParams[] = $meta['integration_id'];
        }
        $asStmt = $this->db->prepare($asSql);
        if ($asStmt !== false) {
            $asStmt->bind_param($asTypes, ...$asParams);
            $asStmt->execute();
            $asRes = $asStmt->get_result();
            while ($row = $asRes->fetch_assoc()) {
                $day = (string) ($row['day'] ?? '');
                if ($day !== '') {
                    $out[$day] = ($out[$day] ?? 0.0) + (float) ($row['spend'] ?? 0);
                }
            }
            $asStmt->close();
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|null $campaign
     * @return array{meta_campaign_id: string, ad_account_row_id: int, integration_id: int|null}|null
     */
    private function resolveMetaCampaignMapContext(?array $campaign): ?array
    {
        if ($campaign === null || empty($campaign['facebook_marketing_campaign_id'])) {
            return null;
        }
        $mapCheck = $this->db->query("SHOW TABLES LIKE 'facebook_adset_campaign_map'");
        if (!$mapCheck || $mapCheck->num_rows === 0) {
            return null;
        }

        $adAccountRowId = (int) ($campaign['facebook_marketing_ad_account_id'] ?? 0);
        $stmt = $this->db->prepare(
            'SELECT meta_campaign_id FROM facebook_marketing_campaigns WHERE id = ? LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $fmcId = (int) $campaign['facebook_marketing_campaign_id'];
        $stmt->bind_param('i', $fmcId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || empty($row['meta_campaign_id'])) {
            return null;
        }

        $integrationId = null;
        if ($adAccountRowId > 0) {
            $s = $this->db->prepare(
                'SELECT facebook_marketing_integration_id FROM facebook_marketing_ad_accounts WHERE id = ? LIMIT 1'
            );
            if ($s !== false) {
                $s->bind_param('i', $adAccountRowId);
                $s->execute();
                $ar = $s->get_result()->fetch_assoc();
                $s->close();
                if ($ar && $ar['facebook_marketing_integration_id'] !== null) {
                    $integrationId = (int) $ar['facebook_marketing_integration_id'];
                }
            }
        }

        return [
            'meta_campaign_id' => (string) $row['meta_campaign_id'],
            'ad_account_row_id' => $adAccountRowId,
            'integration_id' => $integrationId,
        ];
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
