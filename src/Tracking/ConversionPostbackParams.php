<?php

declare(strict_types=1);

namespace SimpleKuma\Tracking;

/**
 * Extract optional inbound postback extras (sub1–sub10 + non-reserved params)
 * for storage on conversions.source_json.
 */
final class ConversionPostbackParams
{
    public const MAX_VALUE_LENGTH = 255;
    public const MAX_EXTRA_KEYS = 20;

    /** Keys used for core tracking — never treated as free-form extras overwrite targets differently. */
    private const RESERVED_KEYS = [
        'click_id',
        'clickid',
        'clickId',
        'cid',
        'ClickID',
        'txid',
        'call_id',
        'callId',
        'inboundCallId',
        'InboundCallId',
        'inbound_call_id',
        'event_id',
        'eventId',
        'value',
        'currency',
        'status',
        'payout',
        'call_revenue',
        'callRevenue',
        'revenue',
        'ConversionPayout',
        'ConversionAmount',
        'et',
        'event_type',
        'event',
        'event_name',
        'source',
        'format',
        // Internal / never accept from query as extras
        'conversion_epoch_ms',
        'event_key',
        'seed',
    ];

    /**
     * Keys surfaced as Conversion Log columns / filters (subset of stored extras).
     *
     * @return list<string>
     */
    public static function reportKeys(): array
    {
        $keys = [];
        for ($i = 1; $i <= 10; $i++) {
            $keys[] = 'sub' . $i;
        }
        $keys[] = 'offer_name';
        $keys[] = 'offer_id';
        $keys[] = 'lander_id';

        return $keys;
    }

    /**
     * @param array<string, mixed> $params Raw GET+POST
     * @return array<string, string> Flat extras to merge into trackConversion $data
     */
    public static function extractExtras(array $params): array
    {
        $reserved = array_fill_keys(self::RESERVED_KEYS, true);
        $extras = [];

        // Prefer sub1–sub10 first so they always win a slot if present
        for ($i = 1; $i <= 10; $i++) {
            $key = 'sub' . $i;
            if (!array_key_exists($key, $params)) {
                continue;
            }
            $normalized = self::normalizeValue($params[$key]);
            if ($normalized !== null) {
                $extras[$key] = $normalized;
            }
        }

        $addExtra = static function (string $key, mixed $raw) use (&$extras, $reserved): void {
            if (isset($reserved[$key]) || isset($extras[$key])) {
                return;
            }
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/', $key) !== 1) {
                return;
            }
            if (count($extras) >= self::MAX_EXTRA_KEYS) {
                return;
            }
            $normalized = self::normalizeValue($raw);
            if ($normalized === null) {
                return;
            }
            $extras[$key] = $normalized;
        };

        // Prioritize common affiliate param names used as Conversion Log columns
        foreach (['offer_name', 'offer_id', 'lander_id'] as $priorityKey) {
            if (array_key_exists($priorityKey, $params)) {
                $addExtra($priorityKey, $params[$priorityKey]);
            }
        }

        foreach ($params as $key => $raw) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (preg_match('/^sub([1-9]|10)$/', $key) === 1) {
                continue;
            }
            $addExtra($key, $raw);
        }

        return $extras;
    }

    /**
     * Parse Conversion Log filter query params for report keys.
     *
     * @param array<string, mixed> $query Typically $_GET
     * @return array<string, string> source_json key => filter value
     */
    public static function parseLogFilters(array $query): array
    {
        $filters = [];
        foreach (self::reportKeys() as $key) {
            // Avoid clashing with unrelated GET keys: network offer_name uses pb_offer_name
            $getKey = match ($key) {
                'offer_name' => 'pb_offer_name',
                'offer_id' => 'pb_offer_id',
                default => $key,
            };
            if (!array_key_exists($getKey, $query)) {
                continue;
            }
            $normalized = self::normalizeValue($query[$getKey]);
            if ($normalized !== null) {
                $filters[$key] = $normalized;
            }
        }

        return $filters;
    }

    private static function normalizeValue(mixed $raw): ?string
    {
        if ($raw === null || is_array($raw) || is_object($raw)) {
            return null;
        }
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > self::MAX_VALUE_LENGTH) {
            $value = mb_substr($value, 0, self::MAX_VALUE_LENGTH);
        }

        return $value;
    }
}
