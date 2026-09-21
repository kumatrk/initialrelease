<?php

declare(strict_types=1);

namespace SimpleKuma\GeoIP;

/**
 * GeoProvider Interface
 * 
 * Defines the contract for all geolocation database providers.
 * Each provider must implement this interface to participate in the fallback chain.
 */
interface GeoProvider
{
    /**
     * Lookup IP address in this provider's database
     * 
     * @param string $ip IPv4 or IPv6 address
     * @return GeoRecord|null GeoRecord if found, null if not found
     */
    public function lookup(string $ip): ?GeoRecord;

    /**
     * Get human-readable name of this provider
     * 
     * @return string Provider name (e.g., "DB-IP", "IP2Location", "IPinfo")
     */
    public function getSourceName(): string;

    /**
     * Check if this provider is available (database file exists and readable)
     * 
     * @return bool True if available
     */
    public function isAvailable(): bool;

    /**
     * Check if this provider supports IPv6 lookups
     * 
     * @return bool True if IPv6 supported
     */
    public function supportsIPv6(): bool;
}


