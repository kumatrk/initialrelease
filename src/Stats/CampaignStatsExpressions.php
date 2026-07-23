<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

/**
 * Shared SQL fragments for API stats (aligned with campaign-stats.php semantics).
 */
class CampaignStatsExpressions
{
    /** @var list<string> */
    public const FIXED_GROUP_BY = ['date', 'country', 'browser', 'os', 'isp', 'landing', 'offer'];

    /** @var array<string, string> built-in click columns for group_by */
    public const BUILTIN_COLUMN_MAP = [
        'country' => 'cl.country',
        'region' => 'cl.region',
        'city' => 'cl.city',
        'device' => 'cl.device',
        'device_brand' => 'cl.device_brand',
        'device_model' => 'cl.device_model',
        'os' => 'cl.os',
        'os_version' => 'cl.os_version',
        'browser' => 'cl.browser',
        'browser_version' => 'cl.browser_version',
        'ip' => 'cl.ip',
        'offer_id' => 'cl.offer_id',
        'landing_page_id' => 'cl.landing_page_id',
    ];

    /**
     * Prefix for traffic-source token dims whose param collides with a tracker/core key
     * (e.g. RollerAds "TS Device" uses param `device` → dimension key `ts:device`).
     */
    public const TS_TOKEN_PREFIX = 'ts:';

    /** Prefix for campaign custom tokens that collide with reserved keys. */
    public const CUSTOM_TOKEN_PREFIX = 'token:';

    /**
     * Storage / JSON param name for a dimension key (strips ts:/token: namespace).
     */
    public static function unwrapDimensionKey(string $groupBy): string
    {
        if (str_starts_with($groupBy, self::TS_TOKEN_PREFIX)) {
            return substr($groupBy, strlen(self::TS_TOKEN_PREFIX));
        }
        if (str_starts_with($groupBy, self::CUSTOM_TOKEN_PREFIX)) {
            return substr($groupBy, strlen(self::CUSTOM_TOKEN_PREFIX));
        }

        return $groupBy;
    }

    public static function isNamespacedTokenKey(string $groupBy): bool
    {
        return str_starts_with($groupBy, self::TS_TOKEN_PREFIX)
            || str_starts_with($groupBy, self::CUSTOM_TOKEN_PREFIX);
    }

    public const FACEBOOK_TRAFFIC_SOURCE_ID = 4;

    public static function facebookTrafficCondition(string $tsAlias = 'ts'): string
    {
        return "{$tsAlias}.id = " . self::FACEBOOK_TRAFFIC_SOURCE_ID;
    }


    public const FACEBOOK_CRAWLER_UA_FRAGMENT = 'facebookexternalhit/1.1';

    /**
     * Valid Facebook ad_id/adset_id using generated columns (indexed; no JSON_EXTRACT).
     */
    public static function validFacebookIdsCondition(string $clAlias = 'cl'): string
    {
        return "{$clAlias}.ad_id IS NOT NULL
            AND {$clAlias}.ad_id != ''
            AND {$clAlias}.ad_id != 'null'
            AND {$clAlias}.ad_id NOT LIKE '{{%'
            AND {$clAlias}.ad_id NOT LIKE '{ts:%'
            AND {$clAlias}.adset_id IS NOT NULL
            AND {$clAlias}.adset_id != ''
            AND {$clAlias}.adset_id != 'null'
            AND {$clAlias}.adset_id NOT LIKE '{{%'
            AND {$clAlias}.adset_id NOT LIKE '{ts:%'";
    }

    /**
     * Cheap WHERE fragment so list/dashboard raw paths can keep COUNT(*) while omitting
     * Meta approval/crawler clicks (aligned with validClickCase / visitor log).
     * Uses clicks.traffic_source_id — no traffic_sources join required.
     */
    public static function excludeInvalidClickWhere(string $clAlias = 'cl'): string
    {
        $validIds = self::validFacebookIdsCondition($clAlias);
        $fbId = self::FACEBOOK_TRAFFIC_SOURCE_ID;
        $ua = self::FACEBOOK_CRAWLER_UA_FRAGMENT;

        return "{$clAlias}.ua NOT LIKE '%{$ua}%'
            AND NOT (
                {$clAlias}.traffic_source_id = {$fbId}
                AND NOT ({$validIds})
            )";
    }

    /**
     * Whether a token value is missing or a traffic-source placeholder (approval/test clicks).
     */
    public static function isInvalidFacebookTokenValue(?string $value): bool
    {
        $value = trim((string) $value);
        if ($value === '' || strcasecmp($value, 'null') === 0) {
            return true;
        }
        if (str_starts_with($value, '{{') || str_starts_with($value, '{ts:')) {
            return true;
        }

        return false;
    }

    public static function isFacebookCrawlerUa(?string $ua): bool
    {
        if ($ua === null || $ua === '') {
            return false;
        }

        return stripos($ua, self::FACEBOOK_CRAWLER_UA_FRAGMENT) !== false;
    }

    /**
     * PHP write-path mirror of excludeInvalidClickWhere / validClickCase.
     *
     * @param array<string, mixed>|null $extraData
     */
    public static function shouldExcludeClickFromStats(
        ?int $trafficSourceId,
        ?array $extraData,
        ?string $ua = null
    ): bool {
        if (self::isFacebookCrawlerUa($ua)) {
            return true;
        }
        if ($trafficSourceId !== self::FACEBOOK_TRAFFIC_SOURCE_ID) {
            return false;
        }
        $tokens = is_array($extraData) ? ($extraData['traffic_source_tokens'] ?? []) : [];
        if (!is_array($tokens)) {
            $tokens = [];
        }
        $adId = isset($tokens['ad_id']) ? (string) $tokens['ad_id'] : '';
        $adsetId = isset($tokens['adset_id']) ? (string) $tokens['adset_id'] : '';

        return self::isInvalidFacebookTokenValue($adId) || self::isInvalidFacebookTokenValue($adsetId);
    }

    /**
     * Click id expression for a valid visitor (excludes FB bots and invalid FB clicks).
     */
    public static function validClickCase(string $clAlias = 'cl', string $tsAlias = 'ts'): string
    {
        $fb = self::facebookTrafficCondition($tsAlias);
        $validIds = self::validFacebookIdsCondition($clAlias);
        $ua = self::FACEBOOK_CRAWLER_UA_FRAGMENT;

        return "CASE
            WHEN {$clAlias}.ua LIKE '%{$ua}%' THEN NULL
            WHEN {$fb} THEN
                CASE
                    WHEN {$validIds}
                    THEN {$clAlias}.id
                    ELSE NULL
                END
            ELSE {$clAlias}.id
        END";
    }

    public static function visitorCountExpr(string $clAlias = 'cl', string $tsAlias = 'ts'): string
    {
        return 'COUNT(DISTINCT ' . self::validClickCase($clAlias, $tsAlias) . ')';
    }

    public static function lpClicksCountExpr(string $clAlias = 'cl', string $tsAlias = 'ts'): string
    {
        $valid = self::validClickCase($clAlias, $tsAlias);

        // True LP CTR only (landing page present). DTO sets lp_click=1 with NULL landing_page_id
        // and is tracked separately as direct_clicks in daily summary — do not count it here.
        return "COUNT(DISTINCT CASE WHEN {$clAlias}.lp_click = 1 AND {$clAlias}.landing_page_id IS NOT NULL THEN {$valid} ELSE NULL END)";
    }

    /**
     * Direct-to-offer "clicks" (DTO / no landing page).
     */
    public static function directClicksCountExpr(string $clAlias = 'cl', string $tsAlias = 'ts'): string
    {
        $valid = self::validClickCase($clAlias, $tsAlias);

        return "COUNT(DISTINCT CASE WHEN {$clAlias}.lp_click = 1 AND {$clAlias}.landing_page_id IS NULL THEN {$valid} ELSE NULL END)";
    }

    /**
     * Action clicks = LP CTA + direct-to-offer (matches campaign list "Clicks" and KPI clicks).
     */
    public static function actionClicksCountExpr(string $clAlias = 'cl', string $tsAlias = 'ts'): string
    {
        $valid = self::validClickCase($clAlias, $tsAlias);

        return "COUNT(DISTINCT CASE WHEN {$clAlias}.lp_click = 1 THEN {$valid} ELSE NULL END)";
    }

    public static function conversionsCountExpr(string $clAlias = 'cl', string $tsAlias = 'ts'): string
    {
        $fb = self::facebookTrafficCondition($tsAlias);
        $validIds = self::validFacebookIdsCondition($clAlias);

        // Requires conversionsAggJoin() — uses pre-aggregated conversion_count so SUM(cl.cost)
        // on the outer query is not inflated by multi-conversion clicks.
        return "COALESCE(SUM(CASE
            WHEN {$fb} THEN
                CASE
                    WHEN {$validIds} THEN COALESCE(conv.conversion_count, 0)
                    ELSE 0
                END
            ELSE COALESCE(conv.conversion_count, 0)
        END), 0)";
    }

    /**
     * 1:1 conversions join (one row per click) so click-level SUMs (cost/FB/GA) stay accurate.
     */
    public static function conversionsAggJoin(string $clAlias = 'cl'): string
    {
        return "LEFT JOIN (
            SELECT click_id,
                   COUNT(*) AS conversion_count,
                   SUM(COALESCE(payout, value)) AS revenue_sum
            FROM conversions
            GROUP BY click_id
        ) conv ON conv.click_id = {$clAlias}.click_id";
    }

    public static function revenueSumExpr(string $convAlias = 'conv'): string
    {
        return "COALESCE(SUM({$convAlias}.revenue_sum), 0)";
    }

    /**
     * Invalid Facebook clicks count (approval/test clicks missing ad tokens).
     */
    public static function invalidClicksCountExpr(string $clAlias = 'cl', string $tsAlias = 'ts'): string
    {
        $fb = self::facebookTrafficCondition($tsAlias);
        $validIds = self::validFacebookIdsCondition($clAlias);

        return "COUNT(DISTINCT CASE
            WHEN {$fb} AND NOT ({$validIds}) THEN {$clAlias}.id
            ELSE NULL
        END)";
    }

    /**
     * MySQL CONVERT_TZ offset string (e.g. `-08:00`) for a user timezone.
     */
    public static function mysqlTimezoneOffset(string $userTimezone, ?string $referenceDate = null): string
    {
        try {
            $tz = new \DateTimeZone($userTimezone);
            $ref = $referenceDate ?? date('Y-m-d');
            $testDate = new \DateTime($ref . ' 12:00:00', $tz);
            $offset = $tz->getOffset($testDate);
            $hours = intdiv($offset, 3600);
            $minutes = intdiv($offset % 3600, 60);

            return sprintf('%+03d:%02d', $hours, abs($minutes));
        } catch (\Exception $e) {
            return '+00:00';
        }
    }

    /**
     * SQL expression for the group key (SELECT + GROUP BY).
     *
     * @return array{expr: string, label_expr: ?string}
     */
    public static function groupKeyParts(string $groupBy, ?string $timezoneOffset = null): array
    {
        if ($groupBy === 'date') {
            $offset = self::sanitizeTimezoneOffset($timezoneOffset ?? '+00:00');

            return [
                'expr' => "DATE(CONVERT_TZ(cl.ts, '+00:00', '{$offset}'))",
                'label_expr' => null,
            ];
        }

        if ($groupBy === 'landing') {
            return [
                'expr' => "COALESCE(NULLIF(CAST(cl.landing_page_id AS CHAR), ''), 'N/A')",
                'label_expr' => 'lp.name',
            ];
        }

        if ($groupBy === 'offer') {
            return [
                'expr' => "COALESCE(NULLIF(CAST(cl.offer_id AS CHAR), ''), 'N/A')",
                'label_expr' => 'o.name',
            ];
        }

        // Namespaced token dims must use JSON even when the bare param matches a click column
        // (e.g. ts:device → $.traffic_source_tokens.device, not cl.device).
        if (self::isNamespacedTokenKey($groupBy)) {
            $param = self::sanitizeJsonTokenParam(self::unwrapDimensionKey($groupBy));
            if ($param === '') {
                return [
                    'expr' => "'N/A'",
                    'label_expr' => null,
                ];
            }

            if (str_starts_with($groupBy, self::CUSTOM_TOKEN_PREFIX)) {
                $customScalar = "'\$.custom_tokens.{$param}'";
                $customValue = "'\$.custom_tokens.{$param}.value'";
                $tsPath = "'\$.traffic_source_tokens.{$param}'";

                return [
                    'expr' => "COALESCE("
                        . "NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, {$customScalar}))), ''), "
                        . "NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, {$customValue}))), ''), "
                        . "NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, {$tsPath}))), ''), "
                        . "'N/A')",
                    'label_expr' => null,
                ];
            }

            $jsonPath = "'\$.traffic_source_tokens.{$param}'";

            return [
                'expr' => "COALESCE(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, {$jsonPath}))), ''), 'N/A')",
                'label_expr' => null,
            ];
        }

        if (isset(self::BUILTIN_COLUMN_MAP[$groupBy])) {
            $col = self::BUILTIN_COLUMN_MAP[$groupBy];

            return [
                'expr' => "COALESCE(NULLIF(TRIM({$col}), ''), 'N/A')",
                'label_expr' => null,
            ];
        }

        if ($groupBy === 'ad_id' || $groupBy === 'adset_id') {
            return [
                'expr' => "COALESCE(NULLIF(TRIM(CAST(cl.{$groupBy} AS CHAR)), ''), 'N/A')",
                'label_expr' => null,
            ];
        }

        if ($groupBy === 'ad_name') {
            return [
                'expr' => "COALESCE(NULLIF(TRIM(cl.ad_name_value), ''), 'N/A')",
                'label_expr' => null,
            ];
        }

        if ($groupBy === 'adset_name') {
            return [
                'expr' => "COALESCE(NULLIF(TRIM(cl.adset_name_value), ''), 'N/A')",
                'label_expr' => null,
            ];
        }

        $param = self::sanitizeJsonTokenParam($groupBy);
        if ($param === '') {
            return [
                'expr' => "'N/A'",
                'label_expr' => null,
            ];
        }

        $jsonPath = "'\$.traffic_source_tokens.{$param}'";

        return [
            'expr' => "COALESCE(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, {$jsonPath}))), ''), 'N/A')",
            'label_expr' => null,
        ];
    }

    /**
     * Allow only safe JSON path key characters (param names from traffic source config).
     */
    public static function sanitizeJsonTokenParam(string $param): string
    {
        $param = trim($param);
        if ($param === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $param)) {
            return '';
        }

        return $param;
    }

    public static function normalizeGroupValue(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return 'N/A';
        }

        return $value;
    }

    /**
     * @param array{clicks: int, lp_clicks?: int, conversions: int, cost: float, revenue: float} $raw
     * @return array<string, mixed>
     */
    public static function formatMetricsRow(string $group, ?string $groupLabel, array $raw): array
    {
        $clicks = (int)($raw['clicks'] ?? 0);
        $lpClicks = (int)($raw['lp_clicks'] ?? 0);
        $conversions = (int)($raw['conversions'] ?? 0);
        $cost = (float)($raw['cost'] ?? 0);
        $revenue = (float)($raw['revenue'] ?? 0);
        $profit = $revenue - $cost;
        $roi = $cost > 0 ? (($revenue - $cost) / $cost) * 100 : 0.0;
        $cr = $clicks > 0 ? ($conversions / $clicks) * 100 : 0.0;
        $ctr = $clicks > 0 ? ($lpClicks / $clicks) * 100 : 0.0;

        $row = [
            'group' => self::normalizeGroupValue($group),
            'clicks' => $clicks,
            'lp_clicks' => $lpClicks,
            'conversions' => $conversions,
            'cost' => round($cost, 4),
            'revenue' => round($revenue, 4),
            'profit' => round($profit, 4),
            'roi' => round($roi, 2),
            'conversion_rate' => round($cr, 2),
            'ctr' => round($ctr, 2),
        ];

        if ($groupLabel !== null && $groupLabel !== '') {
            $row['group_label'] = $groupLabel;
        }

        return $row;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public static function sumMetricsRows(array $rows): array
    {
        $clicks = 0;
        $lpClicks = 0;
        $conversions = 0;
        $cost = 0.0;
        $revenue = 0.0;

        foreach ($rows as $row) {
            $clicks += (int)($row['clicks'] ?? 0);
            $lpClicks += (int)($row['lp_clicks'] ?? 0);
            $conversions += (int)($row['conversions'] ?? 0);
            $cost += (float)($row['cost'] ?? 0);
            $revenue += (float)($row['revenue'] ?? 0);
        }

        return self::formatMetricsRow('', null, [
            'clicks' => $clicks,
            'lp_clicks' => $lpClicks,
            'conversions' => $conversions,
            'cost' => $cost,
            'revenue' => $revenue,
        ]);
    }

    public static function isFixedGroupBy(string $groupBy): bool
    {
        return in_array($groupBy, self::FIXED_GROUP_BY, true);
    }

    public static function sortColumn(string $sort): string
    {
        $allowed = [
            'clicks' => 'clicks',
            'visitors' => 'clicks',
            'lp_clicks' => 'lp_clicks',
            'ctr' => 'ctr',
            'conversions' => 'conversions',
            'cost' => 'cost',
            'revenue' => 'revenue',
            'profit' => 'profit',
            'roi' => 'roi',
            'conversion_rate' => 'conversion_rate',
            'cr' => 'conversion_rate',
            'group' => 'group',
            'name' => 'group',
        ];

        return $allowed[$sort] ?? 'clicks';
    }

    private static function sanitizeTimezoneOffset(string $offset): string
    {
        if (preg_match('/^[+-]\d{2}:\d{2}$/', $offset) === 1) {
            return $offset;
        }

        return '+00:00';
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function fillDateRangeRows(array $rows, string $dateFrom, string $dateTo): array
    {
        $byDate = [];
        foreach ($rows as $row) {
            $key = (string)($row['group'] ?? $row['group_key'] ?? '');
            if ($key !== '') {
                $byDate[$key] = $row;
            }
        }

        $filled = [];
        try {
            $from = new \DateTimeImmutable($dateFrom);
            $to = new \DateTimeImmutable($dateTo);
        } catch (\Exception $e) {
            return $rows;
        }

        if ($to < $from) {
            return $rows;
        }

        for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
            $key = $d->format('Y-m-d');
            if (isset($byDate[$key])) {
                $filled[] = $byDate[$key];
                continue;
            }

            $empty = self::formatMetricsRow($key, null, [
                'clicks' => 0,
                'lp_clicks' => 0,
                'conversions' => 0,
                'cost' => 0.0,
                'revenue' => 0.0,
            ]);
            $empty['group_key'] = $key;
            $empty['name'] = $key;
            $filled[] = $empty;
        }

        return $filled;
    }

    /**
     * Zero-fill daily chart series so every calendar day in the range has a label and data point.
     *
     * @param list<string> $labels
     * @param array<string, list<int|float>> $datasets
     * @return array{labels: list<string>, datasets: array<string, list<int|float>>}
     */
    public static function fillChartDailySeries(
        string $dateFrom,
        string $dateTo,
        array $labels,
        array $datasets
    ): array {
        $byDay = [];
        foreach ($labels as $i => $day) {
            $row = [];
            foreach ($datasets as $key => $series) {
                $row[$key] = $series[$i] ?? 0;
            }
            $byDay[(string)$day] = $row;
        }

        try {
            $from = new \DateTimeImmutable($dateFrom);
            $to = new \DateTimeImmutable($dateTo);
        } catch (\Exception $e) {
            return ['labels' => $labels, 'datasets' => $datasets];
        }

        if ($to < $from) {
            return ['labels' => $labels, 'datasets' => $datasets];
        }

        $filledLabels = [];
        $filledDatasets = [];
        foreach (array_keys($datasets) as $key) {
            $filledDatasets[$key] = [];
        }

        for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
            $key = $d->format('Y-m-d');
            $filledLabels[] = $key;
            $row = $byDay[$key] ?? [];
            foreach (array_keys($datasets) as $metric) {
                $filledDatasets[$metric][] = $row[$metric] ?? 0;
            }
        }

        return ['labels' => $filledLabels, 'datasets' => $filledDatasets];
    }
}
