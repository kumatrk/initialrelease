<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

/**
 * Saved show/hide and order for dashboard and Campaign Stats metric columns.
 * Stored as JSON on the user. Missing keys fall back to the current on-screen defaults.
 */
final class ReportColumnPrefs
{
    /** @var list<string> */
    public const DASHBOARD_DEFAULT = [
        'views',
        'lp_clicks',
        'direct',
        'conversions',
        'cost',
        'revenue',
        'profit',
        'roi',
        'epc',
        'ctr',
        'cr',
    ];

    /** @var list<string> */
    public const DASHBOARD_ALLOWED = [
        'views',
        'lp_clicks',
        'direct',
        'clicks',
        'conversions',
        'cost',
        'revenue',
        'profit',
        'roi',
        'epc',
        'cpc',
        'cpm',
        'ecpm',
        'ctr',
        'cr',
    ];

    /** @var list<string> */
    public const STATS_DEFAULT = [
        'visitors',
        'lp_clicks',
        'ctr',
        'conversions',
        'optins',
        'cr',
        'cost',
        'revenue',
        'profit',
        'roi',
        'bot_pct',
    ];

    /** @var list<string> */
    public const STATS_ALLOWED = [
        'visitors',
        'lp_only',
        'lp_clicks',
        'ctr',
        'cpc',
        'cpm',
        'ecpm',
        'epc',
        'conversions',
        'optins',
        'cr',
        'cost',
        'revenue',
        'profit',
        'roi',
        'bot_pct',
        'bot_clicks',
        'meta_impressions',
        'meta_reach',
        'meta_clicks',
        'meta_ctr',
        'meta_cpc',
        'meta_cpm',
        'meta_spend',
    ];

    /**
     * @return array{dashboard: list<string>, stats: list<string>}
     */
    public static function defaults(): array
    {
        return [
            'dashboard' => self::DASHBOARD_DEFAULT,
            'stats' => self::STATS_DEFAULT,
        ];
    }

    /**
     * @return array{hasSaved: bool, dashboard: list<string>, stats: list<string>}
     */
    public static function clientPayload(?string $json): array
    {
        $hasSaved = is_string($json) && trim($json) !== '';
        $parsed = self::decode($json);

        return [
            'hasSaved' => $hasSaved,
            'dashboard' => $parsed['dashboard'],
            'stats' => $parsed['stats'],
        ];
    }

    /**
     * Merge a posted JSON object onto the stored value. Omitted surfaces are kept.
     */
    public static function mergeStored(?string $existingJson, string $postedJson): string
    {
        $base = self::decode($existingJson);
        $posted = json_decode($postedJson, true);
        if (!is_array($posted)) {
            return self::encode($base);
        }
        if (array_key_exists('dashboard', $posted)) {
            $base['dashboard'] = self::sanitizeList($posted['dashboard'], self::DASHBOARD_ALLOWED, self::DASHBOARD_DEFAULT);
        }
        if (array_key_exists('stats', $posted)) {
            $base['stats'] = self::sanitizeList($posted['stats'], self::STATS_ALLOWED, self::STATS_DEFAULT);
        }

        return self::encode($base);
    }

    /**
     * @return array{dashboard: list<string>, stats: list<string>}
     */
    private static function decode(?string $json): array
    {
        $defaults = self::defaults();
        if (!is_string($json) || trim($json) === '') {
            return $defaults;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return $defaults;
        }

        return [
            'dashboard' => self::sanitizeList($data['dashboard'] ?? null, self::DASHBOARD_ALLOWED, self::DASHBOARD_DEFAULT),
            'stats' => self::sanitizeList($data['stats'] ?? null, self::STATS_ALLOWED, self::STATS_DEFAULT),
        ];
    }

    /**
     * @param list<string> $allowed
     * @param list<string> $fallback
     * @return list<string>
     */
    private static function sanitizeList(mixed $value, array $allowed, array $fallback): array
    {
        if (!is_array($value)) {
            return $fallback;
        }
        $allowedMap = array_fill_keys($allowed, true);
        $out = [];
        foreach ($value as $key) {
            if (!is_string($key) || !isset($allowedMap[$key]) || in_array($key, $out, true)) {
                continue;
            }
            $out[] = $key;
        }

        return $out === [] ? $fallback : $out;
    }

    /**
     * @param array{dashboard: list<string>, stats: list<string>} $value
     */
    private static function encode(array $value): string
    {
        return json_encode([
            'dashboard' => $value['dashboard'],
            'stats' => $value['stats'],
        ], JSON_UNESCAPED_SLASHES) ?: '{"dashboard":[],"stats":[]}';
    }
}
