<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

use mysqli;

/**
 * Fast-path reads from clicks_daily_summary, clicks_stats_by_token_daily,
 * and clicks_stats_by_token_hourly (Phase 2b + token-hourly).
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

    public function tokenHourlyTableExists(): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $result = $this->db->query(
            "SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clicks_stats_by_token_hourly' LIMIT 1"
        );
        $cache = $result !== false && $result->num_rows > 0;

        return $cache;
    }

    private function dailySummaryHasOptins(): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        if (!$this->dailySummaryTableExists()) {
            return $cache = false;
        }
        $result = $this->db->query("SHOW COLUMNS FROM clicks_daily_summary LIKE 'optins'");
        return $cache = ($result !== false && $result->num_rows > 0);
    }

    private function dailySummaryHasBotClicks(): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        if (!$this->dailySummaryTableExists()) {
            return $cache = false;
        }
        $result = $this->db->query("SHOW COLUMNS FROM clicks_daily_summary LIKE 'bot_clicks'");
        return $cache = ($result !== false && $result->num_rows > 0);
    }

    private function tokenDailyHasOptins(): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        if (!$this->tokenDailyTableExists()) {
            return $cache = false;
        }
        $result = $this->db->query("SHOW COLUMNS FROM clicks_stats_by_token_daily LIKE 'optins'");
        return $cache = ($result !== false && $result->num_rows > 0);
    }

    private function tokenDailyHasBotClicks(): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        if (!$this->tokenDailyTableExists()) {
            return $cache = false;
        }
        $result = $this->db->query("SHOW COLUMNS FROM clicks_stats_by_token_daily LIKE 'bot_clicks'");
        return $cache = ($result !== false && $result->num_rows > 0);
    }

    private function botClicksSumExpr(string $alias = 's'): string
    {
        return $this->dailySummaryHasBotClicks() ? "COALESCE(SUM({$alias}.bot_clicks), 0)" : "0";
    }

    private function optinsSumExpr(string $alias = 's'): string
    {
        return $this->dailySummaryHasOptins()
            ? "COALESCE(SUM({$alias}.optins), 0)"
            : '0';
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
     * Pre-agg breakdowns for date/week/offer/landing (daily summary), token dims (token daily),
     * and hour↔token nests (token hourly). Unfiltered Hour L0 stays on lean cover in V2.
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

        $tokenHourlyNest = $this->isTokenHourlyNest($groupBy, $parentPath);
        if ($tokenHourlyNest) {
            return $this->tokenHourlyTableExists() && !$filters->hasTokenFilter();
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
        if ($groupBy === 'date' || $groupBy === 'week' || $groupBy === 'day_of_week') {
            // UTC summary_date / week / weekday — only safe with no parent and UTC-aligned callers.
            return $parentPath === [] && $this->dailySummaryTableExists();
        }
        if ($groupBy === 'hour') {
            // Unfiltered hour uses lean cover; offer/landing×hour fall through to raw.
            return false;
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
     * @param list<array{dimension: string, value: string}> $parentPath
     */
    private function isTokenHourlyNest(string $groupBy, array $parentPath): bool
    {
        if (count($parentPath) !== 1) {
            return false;
        }
        $parentDim = (string)($parentPath[0]['dimension'] ?? '');

        if ($groupBy === 'hour' && $this->isTokenDimensionKey($parentDim)) {
            return true;
        }
        if ($parentDim === 'hour' && $this->isTokenDimensionKey($groupBy)) {
            return true;
        }

        return false;
    }

    private function isTokenDimensionKey(string $dim): bool
    {
        if ($dim === '' || in_array($dim, ['offer', 'landing', 'date', 'hour', 'week', 'day_of_week', 'traffic_source'], true)) {
            return false;
        }
        if (isset(CampaignStatsExpressions::BUILTIN_COLUMN_MAP[$dim])) {
            return false;
        }
        if (in_array($dim, CampaignStatsExpressions::FIXED_GROUP_BY, true)) {
            return false;
        }

        return true;
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
                {$this->optinsSumExpr('s')} AS optins,
                {$this->botClicksSumExpr('s')} AS bot_clicks,
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
            'optins' => (int)($row['optins'] ?? 0),
            'bot_clicks' => (int)($row['bot_clicks'] ?? 0),
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

        if ($this->isTokenHourlyNest($groupBy, $parentPath)) {
            return $this->queryTokenHourlyNestBreakdown(
                $campaignId,
                $groupBy,
                $dateFrom,
                $dateTo,
                $parentPath,
                $filters
            );
        }

        if (in_array($groupBy, ['date', 'week', 'day_of_week', 'offer', 'landing'], true)) {
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
                {$this->optinsSumExpr('s')} AS optins,
                {$this->botClicksSumExpr('s')} AS bot_clicks,
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
                'optins' => (int)($row['optins'] ?? 0),
                'bot_clicks' => (int)($row['bot_clicks'] ?? 0),
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
        $optinsSel = $this->dailySummaryHasOptins() ? 'SUM(s.optins) AS optins,' : '0 AS optins,';
        $botClicksSel = $this->dailySummaryHasBotClicks() ? 'SUM(s.bot_clicks) AS bot_clicks,' : '0 AS bot_clicks,';

        if ($groupBy === 'date') {
            $sql = "
                SELECT s.summary_date AS group_key,
                       NULL AS group_label,
                       SUM(s.clicks) AS clicks,
                       SUM(s.lp_clicks) AS lp_clicks,
                       SUM(s.direct_clicks) AS direct_clicks,
                       SUM(s.conversions) AS conversions,
                       {$optinsSel}
                       {$botClicksSel}
                       SUM(s.cost) AS cost,
                       SUM(s.revenue) AS revenue
                FROM clicks_daily_summary s
                WHERE {$where}
                GROUP BY s.summary_date
            ";
        } elseif ($groupBy === 'week') {
            $weekExpr = 'DATE_SUB(s.summary_date, INTERVAL WEEKDAY(s.summary_date) DAY)';
            $sql = "
                SELECT {$weekExpr} AS group_key,
                       NULL AS group_label,
                       SUM(s.clicks) AS clicks,
                       SUM(s.lp_clicks) AS lp_clicks,
                       SUM(s.direct_clicks) AS direct_clicks,
                       SUM(s.conversions) AS conversions,
                       {$optinsSel}
                       {$botClicksSel}
                       SUM(s.cost) AS cost,
                       SUM(s.revenue) AS revenue
                FROM clicks_daily_summary s
                WHERE {$where}
                GROUP BY {$weekExpr}
            ";
        } elseif ($groupBy === 'day_of_week') {
            $dowExpr = 'WEEKDAY(s.summary_date)';
            $sql = "
                SELECT CAST({$dowExpr} AS CHAR) AS group_key,
                       NULL AS group_label,
                       SUM(s.clicks) AS clicks,
                       SUM(s.lp_clicks) AS lp_clicks,
                       SUM(s.direct_clicks) AS direct_clicks,
                       SUM(s.conversions) AS conversions,
                       {$optinsSel}
                       {$botClicksSel}
                       SUM(s.cost) AS cost,
                       SUM(s.revenue) AS revenue
                FROM clicks_daily_summary s
                WHERE {$where}
                GROUP BY {$dowExpr}
            ";
        } elseif ($groupBy === 'offer') {
            $sql = "
                SELECT COALESCE(CAST(s.offer_id AS CHAR), 'N/A') AS group_key,
                       o.name AS group_label,
                       SUM(s.clicks) AS clicks,
                       SUM(s.lp_clicks) AS lp_clicks,
                       SUM(s.direct_clicks) AS direct_clicks,
                       SUM(s.conversions) AS conversions,
                       {$optinsSel}
                       {$botClicksSel}
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
                       {$optinsSel}
                       {$botClicksSel}
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
            $key = (string)($row['group_key'] ?? 'N/A');
            $label = $row['group_label'] ?? null;
            if ($groupBy === 'day_of_week' && ctype_digit($key)) {
                $label = CampaignStatsExpressions::formatDayOfWeekLabel((int)$key);
            }
            $formatted = CampaignStatsExpressions::formatMetricsRow(
                $key,
                $label,
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
        $storageParam = CampaignStatsExpressions::unwrapDimensionKey($tokenParam);
        $optinsSel = $this->tokenDailyHasOptins() ? 'SUM(optins) AS optins,' : '0 AS optins,';
        $botClicksSel = $this->tokenDailyHasBotClicks() ? 'SUM(bot_clicks) AS bot_clicks,' : '0 AS bot_clicks,';
        $sql = "
            SELECT token_value AS group_key,
                   SUM(visitors) AS clicks,
                   SUM(lp_clicks) AS lp_clicks,
                   SUM(conversions) AS conversions,
                   {$optinsSel}
                   {$botClicksSel}
                   SUM(cost) AS cost,
                   SUM(revenue) AS revenue
            FROM clicks_stats_by_token_daily
            WHERE campaign_id = ? AND summary_date >= ? AND summary_date <= ? AND token_param = ?
        ";
        $types = 'isss';
        $params = [$campaignId, $dateFrom, $dateTo, $storageParam];

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

    /**
     * Token → Hour or Hour → Token from clicks_stats_by_token_hourly.
     *
     * @param list<array{dimension: string, value: string}> $parentPath
     * @return list<array<string, mixed>>
     */
    private function queryTokenHourlyNestBreakdown(
        int $campaignId,
        string $groupBy,
        string $dateFrom,
        string $dateTo,
        array $parentPath,
        CampaignStatsQueryFilters $filters
    ): array {
        $parent = $parentPath[0];
        $parentDim = (string)($parent['dimension'] ?? '');
        $parentValue = (string)($parent['value'] ?? '');
        $optinsSel = $this->tokenDailyHasOptins() ? 'SUM(optins) AS optins,' : '0 AS optins,';
        $botClicksSel = $this->tokenDailyHasBotClicks() ? 'SUM(bot_clicks) AS bot_clicks,' : '0 AS bot_clicks,';

        $types = 'iss';
        $params = [$campaignId, $dateFrom, $dateTo];
        $where = 'campaign_id = ? AND summary_date >= ? AND summary_date <= ?';

        if ($filters->trafficSourceId !== null) {
            $where .= ' AND traffic_source_id = ?';
            $types .= 'i';
            $params[] = $filters->trafficSourceId;
        }

        if ($groupBy === 'hour') {
            $tokenParam = CampaignStatsExpressions::unwrapDimensionKey($parentDim);
            $where .= ' AND token_param = ? AND token_value = ?';
            $types .= 'ss';
            $params[] = $tokenParam;
            $params[] = $parentValue;
            $sql = "
                SELECT CAST(hour AS CHAR) AS group_key,
                       SUM(visitors) AS clicks,
                       SUM(lp_clicks) AS lp_clicks,
                       SUM(conversions) AS conversions,
                       {$optinsSel}
                       {$botClicksSel}
                       SUM(cost) AS cost,
                       SUM(revenue) AS revenue
                FROM clicks_stats_by_token_hourly
                WHERE {$where}
                GROUP BY hour
            ";
        } else {
            $tokenParam = CampaignStatsExpressions::unwrapDimensionKey($groupBy);
            $hour = (int)$parentValue;
            $where .= ' AND token_param = ? AND hour = ?';
            $types .= 'si';
            $params[] = $tokenParam;
            $params[] = $hour;
            $sql = "
                SELECT token_value AS group_key,
                       SUM(visitors) AS clicks,
                       SUM(lp_clicks) AS lp_clicks,
                       SUM(conversions) AS conversions,
                       {$optinsSel}
                       {$botClicksSel}
                       SUM(cost) AS cost,
                       SUM(revenue) AS revenue
                FROM clicks_stats_by_token_hourly
                WHERE {$where}
                GROUP BY token_value
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
            $key = (string)($row['group_key'] ?? 'N/A');
            $label = null;
            if ($groupBy === 'hour' && ctype_digit($key)) {
                $label = CampaignStatsExpressions::formatHourLabel((int)$key);
            }
            $formatted = CampaignStatsExpressions::formatMetricsRow($key, $label, $row);
            $formatted['group_key'] = $formatted['group'];
            $formatted['name'] = $formatted['group_label'] ?? $formatted['group'];
            $rows[] = $formatted;
        }
        $stmt->close();

        return $rows;
    }
}
