<?php

declare(strict_types=1);

namespace SimpleKuma\TrafficSource;

use SimpleKuma\Release\TrafficSourceReleaseHelper;

/**
 * Describes how cost is (or is not) tracked per traffic source for UI labels.
 */
class TrafficSourceCostStatus
{
    public const TIER_LIVE_API = 'live_api';
    public const TIER_MANUAL_URL = 'manual_url';
    public const TIER_VARIABLES_ONLY = 'variables_only';
    public const TIER_NONE = 'none';

    /** Sources with token maps ready but no Kuma cost API cron yet. */
    private const VARIABLES_ONLY_NAMES = [
        'bing',
        'rumble',
        'outbrain',
        'newsbreak',
    ];

    /** Pre-seeded by migration 031 only; optional sources use templates (072 removes unused seeds). */
    public const DEFAULT_SEED_NAMES = [
        'Google Search',
        'YouTube',
        'Bing Ads',
        'Facebook',
        'PropellerAds',
    ];

    /** Optional sources — available as templates, not auto-seeded after cleanup migration. */
    public const OPTIONAL_TEMPLATE_NAMES = [
        'TikTok',
        'Zeropark',
        'Taboola',
        'Rumble',
        'RollerAds',
        'RichAds',
        'Outbrain',
        'NewsBreak',
    ];

    public static function getTier(array $ts): string
    {
        if (self::isVariablesOnlyPendingCostApi($ts)) {
            return self::TIER_VARIABLES_ONLY;
        }

        if (self::hasLiveCostApi($ts)) {
            return self::TIER_LIVE_API;
        }

        $costMethod = $ts['cost_tracking_method'] ?? 'manual_token';
        $costKey = trim((string)($ts['cost_param_key'] ?? ''));

        if ($costMethod === 'manual_token' && $costKey !== '') {
            return self::TIER_MANUAL_URL;
        }

        return self::TIER_NONE;
    }

    public static function hasLiveCostApi(array $ts): bool
    {
        $name = strtolower($ts['name'] ?? '');

        if (strpos($name, 'facebook') !== false) {
            return true;
        }

        return TrafficSourceReleaseHelper::usesGoogleAdsIntegration($ts);
    }

    public static function isVariablesOnlyPendingCostApi(array $ts): bool
    {
        $name = strtolower(trim($ts['name'] ?? ''));

        foreach (self::VARIABLES_ONLY_NAMES as $needle) {
            if ($name === $needle || strpos($name, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    public static function getBadgeLabel(array $ts): string
    {
        return match (self::getTier($ts)) {
            self::TIER_LIVE_API => 'Live API cost',
            self::TIER_MANUAL_URL => self::formatManualLabel($ts),
            self::TIER_VARIABLES_ONLY => 'Variables only',
            default => 'Not configured',
        };
    }

    public static function getBadgeStyle(array $ts): string
    {
        return match (self::getTier($ts)) {
            self::TIER_LIVE_API => 'background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;',
            self::TIER_MANUAL_URL => 'background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;',
            self::TIER_VARIABLES_ONLY => 'background:#fff8e1;color:#f57f17;border:1px solid #ffe082;',
            default => 'background:#e3f2fd;color:#1565c0;border:1px solid #90caf9;',
        };
    }

    public static function getHelpNote(array $ts): ?string
    {
        return match (self::getTier($ts)) {
            self::TIER_LIVE_API => 'Cost syncs via the built-in Facebook or Google Ads integration (Settings → Integrations).',
            self::TIER_MANUAL_URL => 'Cost is read from the cost parameter in your tracking URL when the network sends it.',
            self::TIER_VARIABLES_ONLY => 'Click and conversion tracking work with these tokens. Automatic API cost sync for this network is not built into Kuma yet — cost in reports may stay at zero until that integration ships.',
            default => null,
        };
    }

    public static function renderBadge(array $ts, string $extraStyle = ''): string
    {
        $tier = self::getTier($ts);
        $label = htmlspecialchars(self::getBadgeLabel($ts), ENT_QUOTES, 'UTF-8');
        $class = 'ts-cost-badge ts-cost-badge--' . htmlspecialchars($tier, ENT_QUOTES, 'UTF-8');

        return '<span class="' . $class . '" style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;' . $extraStyle . '">' . $label . '</span>';
    }

    public static function renderNotice(array $ts, string $margin = '0 0 16px 0'): string
    {
        $note = self::getHelpNote($ts);
        if ($note === null) {
            return '';
        }

        $tier = self::getTier($ts);
        $isWarning = $tier === self::TIER_VARIABLES_ONLY;
        $title = $isWarning ? 'Tracking variables ready — API cost coming soon' : 'Cost tracking';

        return '<div class="ts-cost-notice' . ($isWarning ? ' ts-cost-notice--warning' : '') . '" style="margin:' . $margin . ';">'
            . '<strong>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</strong><br>'
            . htmlspecialchars($note, ENT_QUOTES, 'UTF-8')
            . '</div>';
    }

    private static function formatManualLabel(array $ts): string
    {
        $key = htmlspecialchars((string)($ts['cost_param_key'] ?? 'cost'), ENT_QUOTES, 'UTF-8');
        $currency = htmlspecialchars((string)($ts['cost_currency'] ?? 'USD'), ENT_QUOTES, 'UTF-8');

        return 'Manual cost (' . $key . ' · ' . $currency . ')';
    }
}
