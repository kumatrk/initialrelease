<?php

declare(strict_types=1);

namespace SimpleKuma\Tracking;

/**
 * Epoch-based timestamps for Meta CAPI (fbc + event_time) so elapsed click→conversion time is accurate.
 */
class MetaCapiTimestamps
{
    public static function resolveEventTimeMs(array $conversion): int
    {
        $epochMs = self::extractConversionEpochMs($conversion);
        if ($epochMs !== null) {
            return $epochMs;
        }

        if (!empty($conversion['ts'])) {
            $seconds = self::parseDbDatetimeToEpochSeconds((string) $conversion['ts']);
            if ($seconds !== null) {
                return $seconds * 1000;
            }
        }

        return (int) round(microtime(true) * 1000);
    }

    public static function resolveEventTimeSeconds(array $conversion): int
    {
        return (int) floor(self::resolveEventTimeMs($conversion) / 1000);
    }

    public static function extractConversionEpochMs(array $conversion): ?int
    {
        $source = $conversion['source_json'] ?? null;
        if (is_string($source)) {
            $decoded = json_decode($source, true);
            $source = is_array($decoded) ? $decoded : null;
        }

        if (is_array($source) && !empty($source['conversion_epoch_ms'])) {
            return (int) $source['conversion_epoch_ms'];
        }

        return null;
    }

    public static function parseDbDatetimeToEpochSeconds(string $datetime): ?int
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $datetime, new \DateTimeZone('UTC'));
        return $dt ? $dt->getTimestamp() : null;
    }

    /**
     * Meta requires fbc_creation_ms <= event_time_ms. When click appears after conversion (TZ skew),
     * preserve at least a 1-second gap instead of zeroing out attribution.
     */
    public static function resolveFbcTimestampMs(
        int $clickTimestampMs,
        int $eventTimeMs,
        int $conversionId,
        string $source = 'click'
    ): int {
        if ($clickTimestampMs <= $eventTimeMs) {
            return $clickTimestampMs;
        }

        $adjustedMs = max(0, $eventTimeMs - 1000);
        error_log(
            "Facebook CAPI WARNING: {$source} timestamp ({$clickTimestampMs} ms) is after "
            . "event_time ({$eventTimeMs} ms) for conversion {$conversionId}. "
            . "Using {$adjustedMs} ms to preserve attribution gap."
        );

        return $adjustedMs;
    }

    public static function resolveClickTimestampMs(array $conversion, array $extra): ?int
    {
        if (!empty($extra['fbclid_first_seen_epoch_ms'])) {
            return (int) $extra['fbclid_first_seen_epoch_ms'];
        }

        if (!empty($conversion['click_ts'])) {
            $seconds = self::parseDbDatetimeToEpochSeconds((string) $conversion['click_ts']);
            if ($seconds !== null) {
                return $seconds * 1000;
            }
        }

        return null;
    }

    public static function formatFbc(int $timestampMs, string $fbclid): string
    {
        return 'fb.1.' . $timestampMs . '.' . $fbclid;
    }

    /**
     * Build user_data.fbc using the same priority as PostbackDispatcher.
     */
    public static function buildFbc(array $conversion, array $extra, int $eventTimeMs, int $conversionId): ?string
    {
        $eventTimeSec = (int) floor($eventTimeMs / 1000);

        $fbcCookie = null;
        if (!empty($extra['traffic_source_tokens']['_fbc'])) {
            $fbcCookie = trim((string) $extra['traffic_source_tokens']['_fbc']);
        } elseif (!empty($extra['all_params']['_fbc'])) {
            $fbcCookie = trim((string) $extra['all_params']['_fbc']);
        } elseif (!empty($extra['cookies']['_fbc'])) {
            $fbcCookie = trim((string) $extra['cookies']['_fbc']);
        }

        if ($fbcCookie !== null && $fbcCookie !== '' && str_starts_with($fbcCookie, 'fb.')) {
            $fbcParts = explode('.', $fbcCookie, 4);
            if (count($fbcParts) >= 3 && is_numeric($fbcParts[2])) {
                $fbcTimestampMs = (int) $fbcParts[2];
                $fbcTimestampSec = (int) floor($fbcTimestampMs / 1000);

                if ($fbcTimestampSec <= $eventTimeSec) {
                    error_log(
                        "Facebook CAPI: Using _fbc cookie for conversion {$conversionId}: "
                        . substr($fbcCookie, 0, 50) . '...'
                    );
                    return $fbcCookie;
                }

                $fbclidFromCookie = $fbcParts[3] ?? end($fbcParts);
                if ($fbclidFromCookie !== '') {
                    error_log(
                        "Facebook CAPI WARNING: _fbc cookie timestamp ({$fbcTimestampSec}) is after "
                        . "event_time ({$eventTimeSec}) for conversion {$conversionId}. Rebuilding fbc."
                    );
                    $clickMs = self::resolveClickTimestampMs($conversion, $extra) ?? $fbcTimestampMs;
                    $fbcMs = self::resolveFbcTimestampMs($clickMs, $eventTimeMs, $conversionId, '_fbc cookie');
                    return self::formatFbc($fbcMs, (string) $fbclidFromCookie);
                }
            } else {
                error_log(
                    "Facebook CAPI WARNING: _fbc cookie has invalid format for conversion {$conversionId}, "
                    . 'will construct from fbclid'
                );
            }
        }

        $fbclid = null;
        if (!empty($extra['traffic_source_tokens']['fbclid'])) {
            $fbclid = trim((string) $extra['traffic_source_tokens']['fbclid']);
        } elseif (!empty($extra['all_params']['fbclid'])) {
            $fbclid = trim((string) $extra['all_params']['fbclid']);
        }

        if ($fbclid === null || $fbclid === '') {
            error_log("Facebook CAPI WARNING: No fbclid found for conversion {$conversionId} - fbc will not be set");
            return null;
        }

        if (str_starts_with($fbclid, 'fb.1.')) {
            $parts = explode('.', $fbclid, 4);
            if (count($parts) >= 4 && is_numeric($parts[2])) {
                $embeddedMs = (int) $parts[2];
                $fbclidValue = (string) $parts[3];
                $clickMs = self::resolveClickTimestampMs($conversion, $extra) ?? $embeddedMs;
                $fbcMs = self::resolveFbcTimestampMs($clickMs, $eventTimeMs, $conversionId, 'formatted fbclid');
                return self::formatFbc($fbcMs, $fbclidValue);
            }

            $actualFbclid = (string) end($parts);
            if ($actualFbclid === '') {
                return null;
            }
            $fbclid = $actualFbclid;
        }

        $clickMs = self::resolveClickTimestampMs($conversion, $extra);
        if ($clickMs === null) {
            $clickMs = $eventTimeMs;
            error_log(
                "Facebook CAPI: No fbclid_first_seen_epoch_ms or click_ts for conversion {$conversionId}, "
                . "using event_time for fbc"
            );
        } else {
            error_log(
                "Facebook CAPI: Using click timestamp {$clickMs} ms for fbc, event_time {$eventTimeMs} ms "
                . "for conversion {$conversionId}"
            );
        }

        $fbcMs = self::resolveFbcTimestampMs($clickMs, $eventTimeMs, $conversionId, 'fbclid_first_seen');
        $fbc = self::formatFbc($fbcMs, $fbclid);
        error_log(
            "Facebook CAPI: Constructed fbc for conversion {$conversionId} (event_time: {$eventTimeSec})"
        );

        return $fbc;
    }

    public static function logElapsedTime(int $conversionId, int $eventTimeSeconds, ?string $fbc): void
    {
        if ($fbc === null || $fbc === '' || !str_starts_with($fbc, 'fb.')) {
            return;
        }

        $fbcParts = explode('.', $fbc, 4);
        if (count($fbcParts) < 3 || !is_numeric($fbcParts[2])) {
            return;
        }

        $fbcMs = (int) $fbcParts[2];
        $elapsedSec = $eventTimeSeconds - (int) floor($fbcMs / 1000);
        error_log(
            "Facebook CAPI timing for conversion {$conversionId}: "
            . "event_time={$eventTimeSeconds}, fbc_ms={$fbcMs}, elapsed_sec={$elapsedSec}"
        );
    }
}
