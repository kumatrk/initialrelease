<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

/**
 * Short-TTL response cache for dashboard and campaign stats.
 * Prefers APCu, then file cache under storage/cache, then session.
 */
final class StatsResponseCache
{
    public const TTL_SUMMARY = 30;
    public const TTL_CHART = 30;
    public const TTL_BREAKDOWN = 20;
    /** Keep short — new clicks must surface quickly on dashboard/list. */
    public const TTL_DASHBOARD = 20;

    /** Bump when dashboard/list aggregate date logic changes so stale zero payloads are not reused. */
    private const KEY_VERSION = 'v10-hot-clicks-table';

    private const FILE_PREFIX = 'sk_stats_';

    /**
     * Cheap token so stats caches invalidate when clicks, LP marks, conversions, or cost rows change.
     *
     * Uses MAX(id) only on clicks — scanning MAX(UNIX_TIMESTAMP(COALESCE(ts_lp, ts)))
     * forces a full table read and times out / 500s under large click volumes.
     * LP CTA updates that do not insert a new row may lag by one cache TTL (20–30s).
     */
    public static function clicksFreshnessToken(\mysqli $db): string
    {
        $clickMax = 0;
        $convMax = 0;
        $fbCostMax = 0;
        $gaCostMax = 0;

        $result = $db->query('SELECT COALESCE(MAX(id), 0) AS m FROM clicks');
        if ($result) {
            $row = $result->fetch_assoc() ?: [];
            $clickMax = (int)($row['m'] ?? 0);
        }

        $result = $db->query('SELECT COALESCE(MAX(id), 0) AS m FROM conversions');
        if ($result) {
            $row = $result->fetch_assoc() ?: [];
            $convMax = (int)($row['m'] ?? 0);
        }

        $result = @$db->query('SELECT COALESCE(MAX(id), 0) AS m FROM ad_hourly_costs');
        if ($result) {
            $row = $result->fetch_assoc() ?: [];
            $fbCostMax = (int)($row['m'] ?? 0);
        }

        $result = @$db->query('SELECT COALESCE(MAX(id), 0) AS m FROM google_campaign_hourly_costs');
        if ($result) {
            $row = $result->fetch_assoc() ?: [];
            $gaCostMax = (int)($row['m'] ?? 0);
        }

        return $clickMax . ':' . $convMax . ':' . $fbCostMax . ':' . $gaCostMax;
    }

    /**
     * @param callable(): mixed $producer
     */
    public static function remember(string $cacheKey, callable $producer, int $ttlSeconds = 60): mixed
    {
        $now = time();
        $hit = self::get($cacheKey, $now);
        if ($hit !== null) {
            return $hit;
        }

        $payload = $producer();
        self::set($cacheKey, $payload, $now + $ttlSeconds);

        return $payload;
    }

    public static function makeKey(int $userId, string $action, array $parts): string
    {
        return hash('sha256', self::KEY_VERSION . '|' . $userId . '|' . $action . '|' . json_encode($parts));
    }

    /**
     * Drop file-backed stats cache entries (e.g. after a date/timezone logic fix).
     */
    public static function clearFileCache(): int
    {
        $dir = self::cacheDir();
        if ($dir === null) {
            return 0;
        }
        $removed = 0;
        foreach (glob($dir . '/' . self::FILE_PREFIX . '*.cache') ?: [] as $file) {
            if (@unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    private static function get(string $cacheKey, int $now): mixed
    {
        if (function_exists('apcu_fetch')) {
            $ok = false;
            $entry = apcu_fetch(self::FILE_PREFIX . $cacheKey, $ok);
            if ($ok && is_array($entry) && ($entry['expires_at'] ?? 0) > $now) {
                return $entry['payload'];
            }
        }

        $path = self::filePath($cacheKey);
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            if ($raw !== false) {
                $entry = @unserialize($raw);
                if (is_array($entry) && ($entry['expires_at'] ?? 0) > $now) {
                    return $entry['payload'];
                }
            }
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $store = $_SESSION['stats_response_cache'] ?? [];
            if (isset($store[$cacheKey]) && ($store[$cacheKey]['expires_at'] ?? 0) > $now) {
                return $store[$cacheKey]['payload'];
            }
        }

        return null;
    }

    private static function set(string $cacheKey, mixed $payload, int $expiresAt): void
    {
        $entry = ['expires_at' => $expiresAt, 'payload' => $payload];

        if (function_exists('apcu_store')) {
            apcu_store(self::FILE_PREFIX . $cacheKey, $entry, max(1, $expiresAt - time()));
        }

        $dir = self::cacheDir();
        if ($dir !== null) {
            @file_put_contents(self::filePath($cacheKey), serialize($entry), LOCK_EX);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $store = $_SESSION['stats_response_cache'] ?? [];
            $store[$cacheKey] = $entry;
            // Cap session store size
            if (count($store) > 40) {
                uasort($store, static fn ($a, $b) => ($a['expires_at'] ?? 0) <=> ($b['expires_at'] ?? 0));
                $store = array_slice($store, -20, null, true);
            }
            $_SESSION['stats_response_cache'] = $store;
        }
    }

    private static function cacheDir(): ?string
    {
        static $dir = false;
        if ($dir !== false) {
            return $dir;
        }

        $candidates = [
            dirname(__DIR__, 2) . '/storage/cache',
            sys_get_temp_dir() . '/simplekuma-stats-cache',
        ];
        foreach ($candidates as $candidate) {
            if (!is_dir($candidate)) {
                @mkdir($candidate, 0755, true);
            }
            if (is_dir($candidate) && is_writable($candidate)) {
                $dir = $candidate;

                return $dir;
            }
        }
        $dir = null;

        return null;
    }

    private static function filePath(string $cacheKey): string
    {
        return (self::cacheDir() ?? sys_get_temp_dir()) . '/' . self::FILE_PREFIX . $cacheKey . '.cache';
    }
}
