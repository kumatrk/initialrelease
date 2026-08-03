<?php

declare(strict_types=1);

namespace SimpleKuma\GeoIP;

use SimpleKuma\Enrichment\GeoLocator as LegacyGeoLocator;

/**
 * GeoLocator Adapter
 * 
 * Provides backward compatibility wrapper for the new GeoResolver system.
 * This allows existing code using GeoLocator to work with the new multi-database system.
 * 
 * Usage:
 *   $adapter = new GeoLocatorAdapter('8.8.8.8');
 *   $data = $adapter->getGeoData(); // Returns same format as legacy GeoLocator
 */
class GeoLocatorAdapter
{
    private GeoResolver $resolver;
    private string $ip;

    public function __construct(string $ip, ?string $databasePath = null, bool $apiFallbackEnabled = false, ?string $apiProvider = null, ?string $apiKey = null)
    {
        $this->ip = $ip;
        // Note: GeoResolver handles API fallback internally via config constants
        // The parameters are kept for backward compatibility but GeoResolver reads from config
        $this->resolver = new GeoResolver($databasePath);
    }

    /**
     * Get geo data in legacy format (for backward compatibility)
     * 
     * Returns the same array format as the original GeoLocator:
     * ['country' => string|null, 'region' => string|null, 'city' => string|null]
     * 
     * @return array
     */
    public function getGeoData(): array
    {
        $record = $this->resolver->resolve($this->ip);
        
        return [
            'country' => $record->country !== 'N/A' ? $record->country : null,
            'region' => $record->region !== 'N/A' ? $record->region : null,
            'city' => $record->city !== 'N/A' ? $record->city : null,
            'postal' => $record->postal !== 'N/A' ? $record->postal : null,
        ];
    }

    /**
     * Get full GeoRecord (new format)
     * 
     * @return GeoRecord
     */
    public function getGeoRecord(): GeoRecord
    {
        return $this->resolver->resolve($this->ip);
    }

    /**
     * Check if IP is private (delegates to IpUtils)
     * 
     * @return bool
     */
    public function isPrivateIP(): bool
    {
        return IpUtils::isPrivateIp($this->ip);
    }

    /**
     * Anonymize IP address (delegates to IpUtils)
     * 
     * @return string
     */
    public function anonymizeIP(): string
    {
        return IpUtils::normalizeIp($this->ip);
    }
}

