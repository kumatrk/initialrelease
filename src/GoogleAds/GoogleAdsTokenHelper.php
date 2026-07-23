<?php

declare(strict_types=1);

namespace SimpleKuma\GoogleAds;

/**
 * Helpers for reading Google Ads IDs from clicks.extra_json traffic_source_tokens.
 * Google traffic sources use campid ({CampaignId}); campaign_id is a fallback alias.
 */
final class GoogleAdsTokenHelper
{
    public const TOKEN_CAMPID = 'campid';
    public const TOKEN_CAMPAIGN_ID = 'campaign_id';

    /**
     * SQL expression: Google Ads campaign ID from extra_json (campid, then campaign_id).
     */
    public static function campaignIdExtractSql(string $jsonColumn): string
    {
        $prefix = "JSON_UNQUOTE(JSON_EXTRACT({$jsonColumn}, '$.traffic_source_tokens.";
        $campid = $prefix . self::TOKEN_CAMPID . "'))";
        $campaignId = $prefix . self::TOKEN_CAMPAIGN_ID . "'))";

        return "COALESCE(
            NULLIF({$campid}, ''),
            NULLIF({$campid}, 'null'),
            NULLIF({$campaignId}, ''),
            NULLIF({$campaignId}, 'null')
        )";
    }

    /**
     * SQL condition: extra_json has a non-empty Google campaign ID.
     */
    public static function hasCampaignIdSql(string $jsonColumn): string
    {
        return self::campaignIdExtractSql($jsonColumn) . ' IS NOT NULL';
    }

    /**
     * Normalize campaign ID from token array (e.g. decoded extra_json).
     */
    public static function campaignIdFromTokens(array $tokens): ?string
    {
        foreach ([self::TOKEN_CAMPID, self::TOKEN_CAMPAIGN_ID] as $key) {
            $value = trim((string)($tokens[$key] ?? ''));
            if ($value !== '' && $value !== 'null') {
                return $value;
            }
        }

        return null;
    }
}
