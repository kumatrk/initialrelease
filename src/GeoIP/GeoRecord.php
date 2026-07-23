<?php

declare(strict_types=1);

namespace SimpleKuma\GeoIP;

/**
 * GeoRecord Data Class
 * 
 * Standardized geolocation result structure used across all providers.
 * All fields use "N/A" for missing string values and null for missing numeric values.
 */
class GeoRecord
{
    public function __construct(
        public string $ip,
        public string $country,      // ISO country code or "N/A"
        public string $region,        // State/Province name or "N/A"
        public string $city,          // City name or "N/A"
        public string $postal = 'N/A', // Postal/ZIP code or "N/A"
        public ?float $latitude = null,      // null if not available
        public ?float $longitude = null,     // null if not available
        public ?float $accuracyKm = null,    // null if not available
        public string $source = 'none'       // Provider name or "none"
    ) {
    }

    /**
     * Create an empty GeoRecord for cases where no data is found
     * 
     * @param string $ip The IP address that was looked up
     * @return GeoRecord Empty record with all fields set to "N/A" or null
     */
    public static function empty(string $ip): self
    {
        return new self(
            ip: $ip,
            country: 'N/A',
            region: 'N/A',
            city: 'N/A',
            postal: 'N/A',
            latitude: null,
            longitude: null,
            accuracyKm: null,
            source: 'none'
        );
    }

    /**
     * Convert to array for JSON serialization
     * 
     * @return array
     */
    public function toArray(): array
    {
        return [
            'ip' => $this->ip,
            'country' => $this->country,
            'region' => $this->region,
            'city' => $this->city,
            'postal' => $this->postal,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'accuracy_km' => $this->accuracyKm,
            'source' => $this->source,
        ];
    }
}


