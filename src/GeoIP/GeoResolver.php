<?php

declare(strict_types=1);

namespace SimpleKuma\GeoIP;

use SimpleKuma\GeoIP\Providers\DBIPProvider;
use SimpleKuma\GeoIP\Providers\IP2LocationProvider;
use SimpleKuma\GeoIP\Providers\IPinfoProvider;
use SimpleKuma\GeoIP\Cache\SimpleCache;

/**
 * GeoResolver
 * 
 * Orchestrates the multi-database fallback chain for IP geolocation.
 * Queries providers in order: DB-IP → IP2Location → IPinfo
 */
class GeoResolver
{
    /** @var GeoProvider[] */
    private array $providers = [];
    
    private ?string $geoipPath = null;
    private ?SimpleCache $cache = null;
    private bool $cacheEnabled = true;

    /**
     * Initialize resolver with available providers
     * 
     * @param string|null $geoipPath Custom path to GeoIP databases (optional)
     * @param bool $enableCache Enable in-memory caching (default: true)
     * @param int $cacheSize Maximum cache entries (default: 1000)
     * @param int $cacheTtl Cache TTL in seconds (default: 3600)
     */
    public function __construct(?string $geoipPath = null, bool $enableCache = true, int $cacheSize = 1000, int $cacheTtl = 3600)
    {
        $this->geoipPath = $geoipPath;
        $this->cacheEnabled = $enableCache && (defined('GEOIP_CACHE_ENABLED') ? (bool)GEOIP_CACHE_ENABLED : true);
        
        if ($this->cacheEnabled) {
            $cacheSize = defined('GEOIP_CACHE_SIZE') ? (int)GEOIP_CACHE_SIZE : $cacheSize;
            $cacheTtl = defined('GEOIP_CACHE_TTL') ? (int)GEOIP_CACHE_TTL : $cacheTtl;
            $this->cache = new SimpleCache($cacheSize, $cacheTtl);
        }
        
        $this->initializeProviders();
    }

    /**
     * Initialize all providers in fallback order.
     * A concrete legacy file path (e.g. storage/GeoLite2-City.mmdb) is only
     * passed to providers whose format matches; others call findDatabase().
     */
    private function initializeProviders(): void
    {
        $hint = $this->geoipPath;
        $dir = ($hint !== null && is_dir($hint)) ? $hint : null;
        $file = ($hint !== null && is_file($hint)) ? $hint : null;

        // Provider order: DB-IP (primary) → IP2Location (secondary) → IPinfo (tertiary)
        $this->providers = [
            new DBIPProvider($this->pathForProvider($dir, $file, ['dbip', 'geolite2', '.mmdb'])),
            new IP2LocationProvider($this->pathForProvider($dir, $file, ['ip2location', '.bin'])),
            new IPinfoProvider($this->pathForProvider($dir, $file, ['ipinfo'])),
        ];
    }

    /**
     * @param list<string> $needles Case-insensitive basename markers for this provider
     */
    private function pathForProvider(?string $dir, ?string $file, array $needles): ?string
    {
        if ($dir !== null) {
            return $dir;
        }
        if ($file === null) {
            return null;
        }

        $base = strtolower(basename($file));
        foreach ($needles as $needle) {
            $n = strtolower($needle);
            if ($n !== '' && str_contains($base, $n)) {
                // IPinfo must not claim generic GeoLite2 / DB-IP MMDB files
                if ($n === 'ipinfo' && (str_contains($base, 'geolite') || str_contains($base, 'dbip'))) {
                    continue;
                }
                // DB-IP accepts GeoLite2 as a legacy MaxMind-compatible MMDB
                if ($n === '.mmdb' && str_contains($base, 'ipinfo')) {
                    continue;
                }
                return $file;
            }
        }

        return null;
    }

    /**
     * Resolve IP address using fallback chain
     * 
     * Always returns a GeoRecord (never null). If no provider finds the IP,
     * returns an empty record with source="none".
     * 
     * @param string $ip IPv4 or IPv6 address
     * @return GeoRecord Always returns a GeoRecord
     */
    public function resolve(string $ip): GeoRecord
    {
        // Normalize IP address
        $ip = IpUtils::normalizeIp($ip);
        
        // Validate IP format
        if (!IpUtils::isValidIp($ip)) {
            error_log("GeoResolver: Invalid IP address format: {$ip}");
            return GeoRecord::empty($ip);
        }
        
        // Check for private IPs (return immediately, no database lookup, no caching)
        if (IpUtils::isPrivateIp($ip)) {
            return GeoRecord::empty($ip);
        }
        
        // Check cache first
        if ($this->cacheEnabled && $this->cache) {
            $cached = $this->cache->get($ip);
            if ($cached !== null) {
                return $cached;
            }
        }
        
        // Try each provider in order
        foreach ($this->providers as $provider) {
            if (!$provider->isAvailable()) {
                continue; // Skip unavailable providers
            }
            
            $result = $provider->lookup($ip);
            
            if ($result !== null) {
                // Found a match, cache it and return
                if ($this->cacheEnabled && $this->cache) {
                    $this->cache->set($ip, $result);
                }
                return $result;
            }
            
            // Not found in this provider, continue to next
        }
        
        // No provider found the IP
        $emptyRecord = GeoRecord::empty($ip);
        
        // Cache empty results too (to avoid repeated lookups for unknown IPs)
        if ($this->cacheEnabled && $this->cache) {
            $this->cache->set($ip, $emptyRecord);
        }
        
        return $emptyRecord;
    }

    /**
     * Get list of available providers
     * 
     * @return array Array of provider names that are currently available
     */
    public function getAvailableProviders(): array
    {
        $available = [];
        
        foreach ($this->providers as $provider) {
            if ($provider->isAvailable()) {
                $available[] = $provider->getSourceName();
            }
        }
        
        return $available;
    }

    /**
     * Get all providers (for testing/debugging)
     * 
     * @return GeoProvider[]
     */
    public function getAllProviders(): array
    {
        return $this->providers;
    }

    /**
     * Get cache statistics (if caching is enabled)
     * 
     * @return array|null Cache stats or null if caching disabled
     */
    public function getCacheStats(): ?array
    {
        return $this->cache ? $this->cache->getStats() : null;
    }

    /**
     * Clear the cache
     */
    public function clearCache(): void
    {
        if ($this->cache) {
            $this->cache->clear();
        }
    }
}

