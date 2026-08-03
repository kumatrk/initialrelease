<?php

declare(strict_types=1);

namespace SimpleKuma\Release;

/**
 * Traffic source rules for campaign create/edit.
 * Selectable: Facebook, Google Ads, YouTube (live API cost), Rumble/Outbrain/NewsBreak (variables only),
 * TikTok, Zeropark, Taboola, RollerAds, RichAds, PropellerAds (manual URL cost). Bing: seeded, not selectable in campaigns yet.
 * Bing is shown in lists as disabled with "(Coming soon)" until supported.
 * Google/YouTube: clicks and conversions track normally; conversions export via scheduled CSV import.
 */
class TrafficSourceReleaseHelper
{
    public static function isBingTrafficSource(array $ts): bool
    {
        return strpos(strtolower($ts['name'] ?? ''), 'bing') !== false;
    }

    /**
     * True when this traffic source uses Google Ads API cost tracking integrations.
     */
    public static function usesGoogleAdsIntegration(array $ts): bool
    {
        $name = strtolower($ts['name'] ?? '');

        if (strpos($name, 'youtube') !== false) {
            return true;
        }

        if (strpos($name, 'google') !== false && strpos($name, 'facebook') === false) {
            return true;
        }

        return false;
    }

    /**
     * Returns true if the traffic source can be selected for new/edited campaigns.
     */
    public static function isSelectableForRelease(array $ts): bool
    {
        if (self::isBingTrafficSource($ts)) {
            return false;
        }

        $name = strtolower($ts['name'] ?? '');
        $costMethod = $ts['cost_tracking_method'] ?? 'manual_token';

        if (strpos($name, 'facebook') !== false) {
            return true;
        }

        if (self::usesGoogleAdsIntegration($ts)) {
            return true;
        }

        if (strpos($name, 'rumble') !== false || strpos($name, 'outbrain') !== false || strpos($name, 'newsbreak') !== false) {
            return true;
        }

        if ($costMethod === 'manual_token') {
            return true;
        }

        return false;
    }

    /**
     * True when this traffic source is Facebook (CAPI / Marketing API integrations apply).
     */
    public static function usesFacebookIntegration(array $ts): bool
    {
        return stripos($ts['name'] ?? '', 'facebook') !== false;
    }

    /**
     * Google Ads integration ID from POST when traffic source is Google/YouTube; otherwise null.
     */
    public static function resolveGoogleAdsIntegrationId(?array $trafficSource, $postedValue): ?int
    {
        if (!$trafficSource || !self::usesGoogleAdsIntegration($trafficSource)) {
            return null;
        }

        $id = is_numeric($postedValue) ? (int)$postedValue : 0;

        return $id > 0 ? $id : null;
    }

    /**
     * Facebook-related ID from POST when traffic source is Facebook; otherwise null.
     * Use for CAPI integration, marketing ad account, and Meta campaign IDs.
     */
    public static function resolveFacebookIntegrationId(?array $trafficSource, $postedValue): ?int
    {
        if (!$trafficSource || !self::usesFacebookIntegration($trafficSource)) {
            return null;
        }

        $id = is_numeric($postedValue) ? (int)$postedValue : 0;

        return $id > 0 ? $id : null;
    }

    /**
     * Returns the first selectable traffic source from the list, or null.
     */
    public static function getFirstSelectable(array $trafficSources): ?array
    {
        foreach ($trafficSources as $ts) {
            if (self::isSelectableForRelease($ts)) {
                return $ts;
            }
        }

        return null;
    }
}
