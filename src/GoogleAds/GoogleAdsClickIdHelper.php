<?php

declare(strict_types=1);

namespace SimpleKuma\GoogleAds;

/**
 * Extract Google click identifiers (gclid / gbraid / wbraid) from clicks.extra_json.
 */
final class GoogleAdsClickIdHelper
{
    /**
     * @param array<string, mixed>|string|null $extraJson
     * @return array{gclid: ?string, gbraid: ?string, wbraid: ?string}
     */
    public static function extractFromExtraJson($extraJson): array
    {
        if (is_string($extraJson)) {
            $decoded = json_decode($extraJson, true);
            $extraJson = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($extraJson)) {
            $extraJson = [];
        }

        $tokens = $extraJson['traffic_source_tokens'] ?? [];
        $all = $extraJson['all_params'] ?? [];
        if (!is_array($tokens)) {
            $tokens = [];
        }
        if (!is_array($all)) {
            $all = [];
        }

        return [
            'gclid' => self::nonEmptyString($tokens['gclid'] ?? $all['gclid'] ?? null),
            'gbraid' => self::nonEmptyString($tokens['gbraid'] ?? $all['gbraid'] ?? null),
            'wbraid' => self::nonEmptyString($tokens['wbraid'] ?? $all['wbraid'] ?? null),
        ];
    }

    /**
     * @param array{gclid: ?string, gbraid: ?string, wbraid: ?string} $ids
     */
    public static function hasAny(array $ids): bool
    {
        return $ids['gclid'] !== null || $ids['gbraid'] !== null || $ids['wbraid'] !== null;
    }

    /**
     * Single ID for legacy CSV "Google Click ID" column (gclid preferred).
     *
     * @param array{gclid: ?string, gbraid: ?string, wbraid: ?string} $ids
     */
    public static function primaryForLegacyCsv(array $ids): ?string
    {
        return $ids['gclid'] ?? $ids['gbraid'] ?? $ids['wbraid'] ?? null;
    }

    private static function nonEmptyString($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string)$value);
        return $s !== '' ? $s : null;
    }
}
