<?php

declare(strict_types=1);

namespace SimpleKuma\Enrichment;

/**
 * Geo Locator
 * 
 * Production-ready geolocation with multiple fallback options:
 * 1. MaxMind GeoLite2 database (primary, recommended for production)
 * 2. Optional API fallback (configurable, disabled by default)
 * 
 * For open source/production use:
 * - MaxMind GeoLite2 is free but has limited IPv6 coverage
 * - API fallbacks have rate limits and should be used sparingly
 * - Consider MaxMind GeoIP2 (paid) for better IPv6 coverage
 * - Geo lookup failures are non-critical and won't break the system
 */
class GeoLocator
{
    private string $ip;
    private ?string $databasePath = null;
    private bool $apiFallbackEnabled = false;
    private ?string $apiProvider = null;
    private ?string $apiKey = null;

    public function __construct(string $ip, ?string $databasePath = null)
    {
        $this->ip = $ip;
        // Check config first, then parameter, then auto-detect
        $configPath = defined('GEOIP_DATABASE_PATH') && !empty(GEOIP_DATABASE_PATH) 
            ? GEOIP_DATABASE_PATH 
            : null;
        $this->databasePath = $databasePath ?? $configPath ?? $this->getDefaultDatabasePath();
        
        // Check if API fallback is enabled (disabled by default for production)
        $this->apiFallbackEnabled = defined('GEOIP_API_FALLBACK_ENABLED') 
            ? (bool)GEOIP_API_FALLBACK_ENABLED 
            : false;
        
        // Get API provider and key from config
        $this->apiProvider = defined('GEOIP_API_PROVIDER') && !empty(GEOIP_API_PROVIDER)
            ? GEOIP_API_PROVIDER
            : 'ip-api'; // Default to ip-api.com (free, but rate limited)
        
        $this->apiKey = defined('GEOIP_API_KEY') && !empty(GEOIP_API_KEY)
            ? GEOIP_API_KEY
            : null;
    }

    /**
     * Get default database path
     * Checks common locations for GeoLite2-City.mmdb
     */
    private function getDefaultDatabasePath(): ?string
    {
        $possiblePaths = [
            __DIR__ . '/../../storage/GeoLite2-City.mmdb',
            __DIR__ . '/../../GeoLite2-City.mmdb',
            __DIR__ . '/../../../GeoLite2-City.mmdb',
            '/usr/share/GeoIP/GeoLite2-City.mmdb',
            '/var/lib/GeoIP/GeoLite2-City.mmdb',
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path) && is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Get geo data from IP using the new multi-database GeoResolver system.
     * 
     * This method now delegates to GeoLocatorAdapter which uses:
     * 1. DB-IP Lite (primary)
     * 2. IP2Location LITE (secondary)
     * 3. IPinfo DB-Lite (tertiary)
     * 4. Optional API fallback (if enabled)
     * 
     * All databases are checked with automatic fallback for maximum coverage.
     */
    public function getGeoData(): array
    {
        // Use the new GeoLocatorAdapter for multi-database fallback
        if (class_exists(\SimpleKuma\GeoIP\GeoLocatorAdapter::class)) {
            $adapter = new \SimpleKuma\GeoIP\GeoLocatorAdapter(
                $this->ip,
                $this->databasePath, // Pass legacy path as hint (for MaxMind if still desired)
                $this->apiFallbackEnabled,
                $this->apiProvider,
                $this->apiKey
            );
            return $adapter->getGeoData();
        }
        
        // Fallback to legacy MaxMind-only implementation if new system not available
        if ($this->isPrivateIP()) {
            return [
                'country' => null,
                'region' => null,
                'city' => null,
                'postal' => null,
            ];
        }

        // Try MaxMind GeoLite2 first (legacy fallback)
        if ($this->databasePath && class_exists('\GeoIp2\Database\Reader')) {
            try {
                $reader = new \GeoIp2\Database\Reader($this->databasePath);
                $record = $reader->city($this->ip);
                
                return [
                    'country' => $record->country->isoCode ?? null,
                    'region' => $record->mostSpecificSubdivision->name ?? null,
                    'city' => $record->city->name ?? null,
                    'postal' => (isset($record->postal) && $record->postal !== null) ? ($record->postal->code ?? null) : null,
                ];
            } catch (\GeoIp2\Exception\AddressNotFoundException $e) {
                // IP not found in database - try API fallback
                error_log("GeoLocator: IP '{$this->ip}' not found in MaxMind database. Trying API fallback.");
            } catch (\Exception $e) {
                error_log("GeoLocator: MaxMind database error for IP '{$this->ip}': " . $e->getMessage());
            }
        }

        // Optional API fallback (disabled by default for production)
        if ($this->apiFallbackEnabled) {
            $apiData = $this->tryApiFallback();
            if ($apiData) {
                return $apiData;
            }
        }

        return [
            'country' => null,
            'region' => null,
            'city' => null,
            'postal' => null,
        ];
    }

    /**
     * Check if IP is private/local
     */
    private function isPrivateIP(): bool
    {
        // Use new IpUtils if available for consistency
        if (class_exists(\SimpleKuma\GeoIP\IpUtils::class)) {
            return \SimpleKuma\GeoIP\IpUtils::isPrivateIp($this->ip);
        }
        
        // Fallback to legacy implementation
        // Check if it's a valid IP first
        if (!filter_var($this->ip, FILTER_VALIDATE_IP)) {
            return true; // Invalid IPs are treated as private
        }
        
        // Check for IPv4 private ranges
        if (filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return !filter_var(
                $this->ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
        }
        
        // Check for IPv6 private ranges (link-local, unique-local, etc.)
        if (filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // IPv6 private ranges:
            // - fe80::/10 (link-local)
            // - fc00::/7 (unique-local)
            // - ::1 (localhost)
            // - ::ffff:0:0/96 (IPv4-mapped)
            if (strpos($this->ip, 'fe80:') === 0 || 
                strpos($this->ip, 'fc00:') === 0 || 
                strpos($this->ip, 'fd00:') === 0 ||
                $this->ip === '::1' ||
                strpos($this->ip, '::ffff:') === 0) {
                return true;
            }
            return false; // Public IPv6
        }
        
        return true; // Unknown format, treat as private
    }

    /**
     * Anonymize IP address
     */
    public function anonymizeIP(): string
    {
        // Use the new IpUtils class if available for consistency
        if (class_exists(\SimpleKuma\GeoIP\IpUtils::class)) {
            return \SimpleKuma\GeoIP\IpUtils::normalizeIp($this->ip);
        }
        
        // Fallback to legacy implementation
        if (filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // IPv4: Zero out last octet
            $parts = explode('.', $this->ip);
            $parts[3] = '0';
            return implode('.', $parts);
        } elseif (filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // IPv6: Zero out last 80 bits
            $parts = explode(':', $this->ip);
            for ($i = 5; $i < 8; $i++) {
                $parts[$i] = '0';
            }
            return implode(':', $parts);
        }

        return $this->ip;
    }

    /**
     * Try API fallback for geolocation
     * Supports multiple providers with optional API keys
     * 
     * @return array|null Geo data or null if lookup failed
     */
    private function tryApiFallback(): ?array
    {
        if (!$this->apiFallbackEnabled) {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 2, // 2 second timeout
                'ignore_errors' => true,
                'user_agent' => 'SimpleKUMA-GeoLocator/1.0',
            ]
        ]);

        try {
            switch ($this->apiProvider) {
                case 'ip-api':
                    // Free tier: 45 requests/minute, no API key needed
                    // Pro tier: 1000 requests/minute with API key
                    $url = "http://ip-api.com/json/{$this->ip}?fields=status,country,countryCode,region,regionName,city";
                    if ($this->apiKey) {
                        $url .= "&key={$this->apiKey}";
                    }
                    $data = @file_get_contents($url, false, $context);
                    
                    if ($data) {
                        $json = json_decode($data, true);
                        if ($json && isset($json['status']) && $json['status'] === 'success') {
                            return [
                                'country' => $json['countryCode'] ?? null,
                                'region' => $json['regionName'] ?? null,
                                'city' => $json['city'] ?? null,
                                'postal' => $json['zip'] ?? $json['postal'] ?? null,
                            ];
                        }
                    }
                    break;

                case 'ipapi':
                    // ipapi.co - Free tier: 1000 requests/day, no API key needed
                    // Pro tier: Higher limits with API key
                    $url = "https://ipapi.co/{$this->ip}/json/";
                    if ($this->apiKey) {
                        $url .= "?key={$this->apiKey}";
                    }
                    $data = @file_get_contents($url, false, $context);
                    
                    if ($data) {
                        $json = json_decode($data, true);
                        if ($json && !isset($json['error'])) {
                            return [
                                'country' => $json['country_code'] ?? null,
                                'region' => $json['region'] ?? null,
                                'city' => $json['city'] ?? null,
                                'postal' => $json['postal'] ?? $json['zip'] ?? null,
                            ];
                        }
                    }
                    break;

                case 'ipgeolocation':
                    // ipgeolocation.io - Requires API key (free tier available)
                    if (!$this->apiKey) {
                        error_log("GeoLocator: ipgeolocation.io requires an API key");
                        return null;
                    }
                    $url = "https://api.ipgeolocation.io/ipgeo?ip={$this->ip}&apiKey={$this->apiKey}";
                    $data = @file_get_contents($url, false, $context);
                    
                    if ($data) {
                        $json = json_decode($data, true);
                        if ($json && !isset($json['message'])) {
                            return [
                                'country' => $json['country_code2'] ?? null,
                                'region' => $json['state_prov'] ?? null,
                                'city' => $json['city'] ?? null,
                                'postal' => $json['zipcode'] ?? $json['postal'] ?? null,
                            ];
                        }
                    }
                    break;

                default:
                    error_log("GeoLocator: Unknown API provider: {$this->apiProvider}");
                    return null;
            }
        } catch (\Exception $e) {
            // Silently fail, geo is optional
            error_log("GeoLocator: API fallback error ({$this->apiProvider}): " . $e->getMessage());
        }

        return null;
    }
}


