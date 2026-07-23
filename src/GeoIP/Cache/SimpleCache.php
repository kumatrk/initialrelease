<?php

declare(strict_types=1);

namespace SimpleKuma\GeoIP\Cache;

use SimpleKuma\GeoIP\GeoRecord;

/**
 * Simple In-Memory Cache for GeoIP Lookups
 * 
 * Provides LRU-style caching with configurable size and TTL.
 * This is a lightweight implementation that doesn't require external dependencies.
 */
class SimpleCache
{
    private array $cache = [];
    private array $timestamps = [];
    private int $maxSize;
    private int $ttl;
    private int $hits = 0;
    private int $misses = 0;

    public function __construct(int $maxSize = 1000, int $ttl = 3600)
    {
        $this->maxSize = $maxSize;
        $this->ttl = $ttl;
    }

    /**
     * Get cached GeoRecord for IP
     * 
     * @param string $ip IP address
     * @return GeoRecord|null Cached record or null if not found/expired
     */
    public function get(string $ip): ?GeoRecord
    {
        if (!isset($this->cache[$ip])) {
            $this->misses++;
            return null;
        }

        // Check if expired
        if (isset($this->timestamps[$ip]) && (time() - $this->timestamps[$ip]) > $this->ttl) {
            unset($this->cache[$ip], $this->timestamps[$ip]);
            $this->misses++;
            return null;
        }

        $this->hits++;
        return $this->cache[$ip];
    }

    /**
     * Store GeoRecord in cache
     * 
     * @param string $ip IP address
     * @param GeoRecord $record GeoRecord to cache
     */
    public function set(string $ip, GeoRecord $record): void
    {
        // Evict oldest entry if cache is full
        if (count($this->cache) >= $this->maxSize && !isset($this->cache[$ip])) {
            $this->evictOldest();
        }

        $this->cache[$ip] = $record;
        $this->timestamps[$ip] = time();
    }

    /**
     * Evict the oldest entry from cache
     */
    private function evictOldest(): void
    {
        if (empty($this->timestamps)) {
            return;
        }

        // Find oldest entry
        $oldestIp = null;
        $oldestTime = time() + 1;

        foreach ($this->timestamps as $ip => $timestamp) {
            if ($timestamp < $oldestTime) {
                $oldestTime = $timestamp;
                $oldestIp = $ip;
            }
        }

        if ($oldestIp !== null) {
            unset($this->cache[$oldestIp], $this->timestamps[$oldestIp]);
        }
    }

    /**
     * Clear all cached entries
     */
    public function clear(): void
    {
        $this->cache = [];
        $this->timestamps = [];
    }

    /**
     * Get cache statistics
     * 
     * @return array
     */
    public function getStats(): array
    {
        $total = $this->hits + $this->misses;
        $hitRate = $total > 0 ? ($this->hits / $total) * 100 : 0;

        return [
            'size' => count($this->cache),
            'max_size' => $this->maxSize,
            'hits' => $this->hits,
            'misses' => $this->misses,
            'hit_rate' => round($hitRate, 2),
            'ttl' => $this->ttl,
        ];
    }
}


