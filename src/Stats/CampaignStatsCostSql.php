<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

use SimpleKuma\GoogleAds\GoogleAdsTokenHelper;

/**
 * Reusable Facebook + Google + manual cost SQL (aligned with campaign-stats queries).
 */
final class CampaignStatsCostSql
{
    /**
     * Per-click Facebook cost CASE expression (use inside SUM()).
     */
    public static function perClickFacebookCostCase(string $clicksTable, string $clickDataAlias = 'click_data'): string
    {
        $adId = "{$clickDataAlias}.ad_id";
        $adsetId = "{$clickDataAlias}.adset_id";
        $clickDate = "{$clickDataAlias}.click_date";
        $clickHour = "{$clickDataAlias}.click_hour";

        return "CASE
            WHEN a_cost.delta_spend IS NOT NULL THEN
                a_cost.delta_spend / GREATEST((
                    SELECT COUNT(*)
                    FROM {$clicksTable} c2
                    WHERE c2.ad_id = {$adId}
                        AND DATE(c2.ts) = {$clickDate}
                        AND HOUR(c2.ts) = {$clickHour}
                ), 1)
            WHEN as_cost.delta_spend IS NOT NULL
                AND NOT EXISTS (
                    SELECT 1
                    FROM ad_hourly_costs a_check
                    INNER JOIN {$clicksTable} c_check ON c_check.ad_id = a_check.ad_id
                    INNER JOIN campaigns camp_check ON c_check.campaign_id = camp_check.id
                    LEFT JOIN facebook_marketing_ad_accounts fmaa_check ON camp_check.facebook_marketing_ad_account_id = fmaa_check.id
                    WHERE c_check.adset_id = {$adsetId}
                        AND a_check.date = {$clickDate}
                        AND a_check.hour = {$clickHour}
                        AND (fmaa_check.facebook_marketing_integration_id IS NULL OR a_check.ad_account_id = fmaa_check.facebook_marketing_integration_id)
                ) THEN
                as_cost.delta_spend / GREATEST((
                    SELECT COUNT(*)
                    FROM {$clicksTable} c2
                    WHERE c2.adset_id = {$adsetId}
                        AND DATE(c2.ts) = {$clickDate}
                        AND HOUR(c2.ts) = {$clickHour}
                ), 1)
            ELSE 0
        END";
    }

    /**
     * Per-click Google Ads cost CASE (campaign hourly costs allocated by campid).
     */
    public static function perClickGoogleCostCase(string $clicksTable, string $gaAlias = 'ga_click_data'): string
    {
        $gaCampId = "{$gaAlias}.ga_campaign_id";
        $clickDate = "{$gaAlias}.click_date";
        $clickHour = "{$gaAlias}.click_hour";
        $campIdSql = GoogleAdsTokenHelper::campaignIdExtractSql('c2.extra_json');

        return "CASE
            WHEN ga_cost.delta_spend IS NOT NULL AND {$gaCampId} IS NOT NULL THEN
                ga_cost.delta_spend / GREATEST((
                    SELECT COUNT(*)
                    FROM {$clicksTable} c2
                    WHERE {$campIdSql} = {$gaCampId}
                        AND DATE(c2.ts) = {$clickDate}
                        AND HOUR(c2.ts) = {$clickHour}
                ), 1)
            ELSE 0
        END";
    }

    /**
     * click_data subquery + FB cost table joins (append after main FROM clicks cl).
     *
     * @return array{joins: string, requires_fb_ids: bool}
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

        return ['joins' => $joins, 'requires_fb_ids' => false];
    }

    /**
     * Google campaign hourly cost joins (separate date binds from facebookCostJoins).
     *
     * @return array{joins: string}
     */
    public static function googleCostJoins(string $clicksTable, string $clAlias = 'cl'): array
    {
        $campIdExtract = GoogleAdsTokenHelper::campaignIdExtractSql('extra_json');

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

        return ['joins' => $joins];
    }

    /**
     * FB + Google joins. Adds four date bind placeholders (ss for FB, ss for Google).
     *
     * @return array{joins: string}
     */
    public static function scopedApiCostJoins(string $clicksTable, string $clAlias = 'cl'): array
    {
        $fb = self::facebookCostJoins($clicksTable, $clAlias)['joins'];
        $ga = self::googleCostJoins($clicksTable, $clAlias)['joins'];

        return ['joins' => $fb . $ga];
    }

    /**
     * facebookCostJoins embeds a subquery with ts >= ? AND ts <= ? before main WHERE placeholders.
     *
     * @param list<mixed> $params
     * @return array{0: string, 1: list<mixed>}
     */
    public static function mergeJoinDateBinds(string $utcFrom, string $utcTo, string $types, array $params): array
    {
        return ['ss' . $types, array_merge([$utcFrom, $utcTo], $params)];
    }

    /**
     * Prefixed date binds for FB+Google scoped joins (four placeholders).
     *
     * @param list<mixed> $params
     * @return array{0: string, 1: list<mixed>}
     */
    public static function mergeScopedApiJoinDateBinds(string $utcFrom, string $utcTo, string $types, array $params): array
    {
        return ['ssss' . $types, array_merge([$utcFrom, $utcTo, $utcFrom, $utcTo], $params)];
    }

    public static function totalCostSelectExpr(string $clicksTable, string $clAlias = 'cl'): string
    {
        $fbCase = self::perClickFacebookCostCase($clicksTable);
        $gaCase = self::perClickGoogleCostCase($clicksTable);

        return 'COALESCE(SUM(' . $clAlias . '.cost), 0) + COALESCE(SUM(' . $fbCase . '), 0) + COALESCE(SUM(' . $gaCase . '), 0)';
    }
}
