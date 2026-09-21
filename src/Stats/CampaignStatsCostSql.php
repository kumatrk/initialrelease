<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

use SimpleKuma\GoogleAds\GoogleAdsTokenHelper;

/**
 * Reusable Facebook + Google + manual cost SQL (aligned with campaign-stats queries).
 *
 * Meta allocation is ad-first, then adset fallback. Click-count denominators are
 * pre-aggregated once per (ad|adset, date, hour) and joined — never correlated
 * COUNT(*) per click (that path times out around ~100k clicks).
 */
final class CampaignStatsCostSql
{
    /** Placeholders inside facebookCostJoins (click_data + ad_counts + adset_counts + adset_has_ad_cost). */
    public const FB_JOIN_DATE_BINDS = 8;

    /** Placeholders inside googleCostJoins (ga_click_data + ga_counts). */
    public const GA_JOIN_DATE_BINDS = 4;

    /** FB + Google scoped joins. */
    public const SCOPED_JOIN_DATE_BINDS = self::FB_JOIN_DATE_BINDS + self::GA_JOIN_DATE_BINDS;

    /**
     * Per-click Facebook cost CASE expression (use inside SUM()).
     * Requires facebookCostJoins() so ad_counts / adset_counts / adset_has_ad_cost are present.
     */
    public static function perClickFacebookCostCase(string $clicksTable, string $clickDataAlias = 'click_data'): string
    {
        // $clicksTable kept for call-site compatibility; denominators come from joined pre-aggs.
        unset($clicksTable);

        return "CASE
            WHEN a_cost.delta_spend IS NOT NULL THEN
                a_cost.delta_spend / GREATEST(COALESCE(ad_counts.click_count, 1), 1)
            WHEN as_cost.delta_spend IS NOT NULL
                AND adset_has_ad_cost.adset_id IS NULL THEN
                as_cost.delta_spend / GREATEST(COALESCE(adset_counts.click_count, 1), 1)
            ELSE 0
        END";
    }

    /**
     * Per-click Google Ads cost CASE (campaign hourly costs allocated by campid).
     * Requires googleCostJoins() so ga_counts is present.
     */
    public static function perClickGoogleCostCase(string $clicksTable, string $gaAlias = 'ga_click_data'): string
    {
        unset($clicksTable);
        $gaCampId = "{$gaAlias}.ga_campaign_id";

        return "CASE
            WHEN ga_cost.delta_spend IS NOT NULL AND {$gaCampId} IS NOT NULL THEN
                ga_cost.delta_spend / GREATEST(COALESCE(ga_counts.click_count, 1), 1)
            ELSE 0
        END";
    }

    /**
     * click_data + pre-agg count joins + FB cost tables (append after main FROM clicks cl).
     *
     * Bind order (8× string dates): click_data from/to, ad_counts from/to,
     * adset_counts from/to, adset_has_ad_cost from/to.
     *
     * @return array{joins: string, requires_fb_ids: bool, date_binds: int}
     */
    public static function facebookCostJoins(string $clicksTable, string $clAlias = 'cl'): array
    {
        $joins = "
            LEFT JOIN (
                SELECT
                    click_id,
                    campaign_id,
                    ad_id,
                    adset_id,
                    DATE(ts) AS click_date,
                    HOUR(ts) AS click_hour
                FROM {$clicksTable}
                WHERE ts >= ? AND ts <= ?
            ) AS click_data ON click_data.click_id = {$clAlias}.click_id
            LEFT JOIN (
                SELECT
                    ad_id,
                    DATE(ts) AS click_date,
                    HOUR(ts) AS click_hour,
                    COUNT(*) AS click_count
                FROM {$clicksTable}
                WHERE ts >= ? AND ts <= ?
                  AND ad_id IS NOT NULL
                GROUP BY ad_id, DATE(ts), HOUR(ts)
            ) AS ad_counts ON
                ad_counts.ad_id = click_data.ad_id
                AND ad_counts.click_date = click_data.click_date
                AND ad_counts.click_hour = click_data.click_hour
            LEFT JOIN (
                SELECT
                    adset_id,
                    DATE(ts) AS click_date,
                    HOUR(ts) AS click_hour,
                    COUNT(*) AS click_count
                FROM {$clicksTable}
                WHERE ts >= ? AND ts <= ?
                  AND adset_id IS NOT NULL
                GROUP BY adset_id, DATE(ts), HOUR(ts)
            ) AS adset_counts ON
                adset_counts.adset_id = click_data.adset_id
                AND adset_counts.click_date = click_data.click_date
                AND adset_counts.click_hour = click_data.click_hour
            LEFT JOIN (
                -- Adset-hours that already have ad-level spend for clicks in range
                -- (replaces correlated NOT EXISTS in the old CASE).
                SELECT DISTINCT
                    c_check.adset_id,
                    a_check.date AS cost_date,
                    a_check.hour AS cost_hour
                FROM ad_hourly_costs a_check
                INNER JOIN {$clicksTable} c_check ON c_check.ad_id = a_check.ad_id
                INNER JOIN campaigns camp_check ON c_check.campaign_id = camp_check.id
                LEFT JOIN facebook_marketing_ad_accounts fmaa_check
                    ON camp_check.facebook_marketing_ad_account_id = fmaa_check.id
                WHERE c_check.ts >= ? AND c_check.ts <= ?
                  AND c_check.adset_id IS NOT NULL
                  AND a_check.date = DATE(c_check.ts)
                  AND a_check.hour = HOUR(c_check.ts)
                  AND (
                    fmaa_check.facebook_marketing_integration_id IS NULL
                    OR a_check.ad_account_id = fmaa_check.facebook_marketing_integration_id
                  )
            ) AS adset_has_ad_cost ON
                adset_has_ad_cost.adset_id = click_data.adset_id
                AND adset_has_ad_cost.cost_date = click_data.click_date
                AND adset_has_ad_cost.cost_hour = click_data.click_hour
            LEFT JOIN campaigns camp ON click_data.campaign_id = camp.id
            LEFT JOIN facebook_marketing_ad_accounts fmaa ON camp.facebook_marketing_ad_account_id = fmaa.id
            LEFT JOIN ad_hourly_costs a_cost ON
                a_cost.ad_id = click_data.ad_id
                AND a_cost.date = click_data.click_date
                AND a_cost.hour = click_data.click_hour
                AND (fmaa.facebook_marketing_integration_id IS NULL OR a_cost.ad_account_id = fmaa.facebook_marketing_integration_id)
            LEFT JOIN adset_hourly_costs as_cost ON
                as_cost.adset_id = click_data.adset_id
                AND as_cost.date = click_data.click_date
                AND as_cost.hour = click_data.click_hour
                AND (fmaa.facebook_marketing_integration_id IS NULL OR as_cost.ad_account_id = fmaa.facebook_marketing_integration_id)
                AND a_cost.delta_spend IS NULL
        ";

        return [
            'joins' => $joins,
            'requires_fb_ids' => false,
            'date_binds' => self::FB_JOIN_DATE_BINDS,
        ];
    }

    /**
     * Google campaign hourly cost joins (separate date binds from facebookCostJoins).
     *
     * Bind order (4× string dates): ga_click_data from/to, ga_counts from/to.
     *
     * @return array{joins: string, date_binds: int}
     */
    public static function googleCostJoins(string $clicksTable, string $clAlias = 'cl'): array
    {
        $campIdExtract = GoogleAdsTokenHelper::campaignIdExtractSql('extra_json');
        $campIdExtractC2 = GoogleAdsTokenHelper::campaignIdExtractSql('c2.extra_json');

        $joins = "
            LEFT JOIN (
                SELECT
                    click_id,
                    {$campIdExtract} AS ga_campaign_id,
                    DATE(ts) AS click_date,
                    HOUR(ts) AS click_hour
                FROM {$clicksTable}
                WHERE ts >= ? AND ts <= ?
            ) AS ga_click_data ON ga_click_data.click_id = {$clAlias}.click_id
            LEFT JOIN (
                SELECT
                    {$campIdExtractC2} AS ga_campaign_id,
                    DATE(c2.ts) AS click_date,
                    HOUR(c2.ts) AS click_hour,
                    COUNT(*) AS click_count
                FROM {$clicksTable} c2
                WHERE c2.ts >= ? AND c2.ts <= ?
                  AND {$campIdExtractC2} IS NOT NULL
                GROUP BY {$campIdExtractC2}, DATE(c2.ts), HOUR(c2.ts)
            ) AS ga_counts ON
                ga_counts.ga_campaign_id = ga_click_data.ga_campaign_id
                AND ga_counts.click_date = ga_click_data.click_date
                AND ga_counts.click_hour = ga_click_data.click_hour
            LEFT JOIN campaigns ga_camp ON {$clAlias}.campaign_id = ga_camp.id
            LEFT JOIN google_campaign_hourly_costs ga_cost ON
                CAST(ga_cost.campaign_id AS CHAR) = ga_click_data.ga_campaign_id
                AND ga_cost.date = ga_click_data.click_date
                AND ga_cost.hour = ga_click_data.click_hour
                AND (
                    ga_camp.google_ads_integration_id IS NULL
                    OR ga_cost.integration_id = ga_camp.google_ads_integration_id
                )
        ";

        return ['joins' => $joins, 'date_binds' => self::GA_JOIN_DATE_BINDS];
    }

    /**
     * FB + Google joins.
     *
     * @return array{joins: string, date_binds: int}
     */
    public static function scopedApiCostJoins(string $clicksTable, string $clAlias = 'cl'): array
    {
        $fb = self::facebookCostJoins($clicksTable, $clAlias)['joins'];
        $ga = self::googleCostJoins($clicksTable, $clAlias)['joins'];

        return ['joins' => $fb . $ga, 'date_binds' => self::SCOPED_JOIN_DATE_BINDS];
    }

    /**
     * Prefixed date binds for facebookCostJoins (8 placeholders).
     *
     * @param list<mixed> $params
     * @return array{0: string, 1: list<mixed>}
     */
    public static function mergeJoinDateBinds(string $utcFrom, string $utcTo, string $types, array $params): array
    {
        $prefix = str_repeat('s', self::FB_JOIN_DATE_BINDS);
        $dates = [];
        for ($i = 0; $i < self::FB_JOIN_DATE_BINDS / 2; $i++) {
            $dates[] = $utcFrom;
            $dates[] = $utcTo;
        }

        return [$prefix . $types, array_merge($dates, $params)];
    }

    /**
     * Prefixed date binds for FB+Google scoped joins.
     *
     * @param list<mixed> $params
     * @return array{0: string, 1: list<mixed>}
     */
    public static function mergeScopedApiJoinDateBinds(string $utcFrom, string $utcTo, string $types, array $params): array
    {
        $prefix = str_repeat('s', self::SCOPED_JOIN_DATE_BINDS);
        $dates = [];
        for ($i = 0; $i < self::SCOPED_JOIN_DATE_BINDS / 2; $i++) {
            $dates[] = $utcFrom;
            $dates[] = $utcTo;
        }

        return [$prefix . $types, array_merge($dates, $params)];
    }

    public static function totalCostSelectExpr(string $clicksTable, string $clAlias = 'cl'): string
    {
        $fbCase = self::perClickFacebookCostCase($clicksTable);
        $gaCase = self::perClickGoogleCostCase($clicksTable);

        return 'COALESCE(SUM(' . $clAlias . '.cost), 0) + COALESCE(SUM(' . $fbCase . '), 0) + COALESCE(SUM(' . $gaCase . '), 0)';
    }
}
