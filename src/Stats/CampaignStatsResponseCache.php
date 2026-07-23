<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

/**
 * Short-TTL response cache for Campaign Stats V2.
 * Delegates to StatsResponseCache (APCu / file / session).
 */
final class CampaignStatsResponseCache
{
    private const TTL_SECONDS = StatsResponseCache::TTL_SUMMARY;

    /**
     * @param callable(): mixed $producer
     */
    public static function remember(string $cacheKey, callable $producer, ?int $ttlSeconds = null): mixed
    {
        return StatsResponseCache::remember($cacheKey, $producer, $ttlSeconds ?? self::TTL_SECONDS);
    }

    public static function makeKey(int $userId, string $action, array $parts): string
    {
        return StatsResponseCache::makeKey($userId, $action, $parts);
    }
}
