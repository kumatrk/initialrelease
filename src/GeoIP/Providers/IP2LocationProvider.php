<?php

declare(strict_types=1);

namespace SimpleKuma\GeoIP\Providers;

use IP2Location\Database;
use SimpleKuma\GeoIP\GeoProvider;
use SimpleKuma\GeoIP\GeoRecord;
use SimpleKuma\Support\AppDebugLog;

/**
 * IP2Location LITE Provider
 * 
 * Uses IP2Location LITE database in BIN format (preferred) or CSV (with conversion).
 * License: Free with redistribution allowed (verify current terms)
 * Attribution: "This product includes IP2Location LITE data available from https://lite.ip2location.com."
 */
class IP2LocationProvider implements GeoProvider
{
    use AppDebugLog;

    private const SOURCE_NAME = 'ip2location';
    private const ATTRIBUTION = 'This product includes IP2Location LITE data available from https://lite.ip2location.com.';
    
    private ?string $databasePath = null;
    private ?Database $db = null;
    private bool $available = false;

    public function __construct(?string $databasePath = null)
    {
        $this->databasePath = $databasePath ?? $this->findDatabase();
        $this->initialize();
    }

    /**
     * Find database file in common locations
     */
    private function findDatabase(): ?string
    {
        // Get root path - go up from src/GeoIP/Providers/ to project root
        $rootPath = dirname(__DIR__, 3); // Goes from src/GeoIP/Providers/ to project root
        
        $basePaths = [
            defined('GEOIP_DATABASE_PATH') && !empty(GEOIP_DATABASE_PATH) ? GEOIP_DATABASE_PATH : null,
            $rootPath . DIRECTORY_SEPARATOR . 'geoip',
            $rootPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'geoip',
            // Also try absolute paths if ROOT_PATH is defined
            defined('ROOT_PATH') ? ROOT_PATH . DIRECTORY_SEPARATOR . 'geoip' : null,
            defined('ROOT_PATH') ? ROOT_PATH . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'geoip' : null,
        ];

        $possibleFilenames = [
            'IP2LOCATION-LITE-DB11.BIN',
            'IP2LOCATION-LITE-DB11.IPV6.BIN',
            'ip2location-lite.bin',
            'IP2Location-Lite-DB11.BIN',
        ];

        foreach ($basePaths as $basePath) {
            if (!$basePath) {
                continue;
            }

            // If basePath is a file, check it directly
            if (is_file($basePath) && is_readable($basePath)) {
                return $basePath;
            }

            // If basePath is a directory, check for files
            if (is_dir($basePath)) {
                foreach ($possibleFilenames as $filename) {
                    $path = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . $filename;
                    if (file_exists($path) && is_readable($path)) {
                        return $path;
                    }
                }

                // Also do case-insensitive search for IP2Location files
                $files = @scandir($basePath);
                if ($files !== false) {
                    foreach ($files as $file) {
                        if ($file === '.' || $file === '..') {
                            continue;
                        }
                        $lowerFile = strtolower($file);
                        if ((strpos($lowerFile, 'ip2location') !== false || strpos($lowerFile, 'ip2_location') !== false) && 
                            strpos($lowerFile, '.bin') !== false) {
                            $path = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . $file;
                            if (is_readable($path)) {
                                return $path;
                            }
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Initialize the database reader
     */
    private function initialize(): void
    {
        if (!$this->databasePath) {
            $this->available = false;
            return;
        }

        // Check if file exists and is readable
        if (!file_exists($this->databasePath)) {
            self::logOnce('ip2:missing', "IP2LocationProvider: Database file does not exist: {$this->databasePath}");
            $this->available = false;
            return;
        }

        if (!is_readable($this->databasePath)) {
            self::logOnce('ip2:unreadable', "IP2LocationProvider: Database file is not readable: {$this->databasePath}");
            $this->available = false;
            return;
        }

        if (!class_exists(Database::class)) {
            self::logOnce('ip2:class', "IP2LocationProvider: IP2Location\Database class not found. Make sure composer dependencies are installed.");
            $this->available = false;
            return;
        }

        try {
            $this->db = new Database($this->databasePath, Database::FILE_IO);
            $this->available = true;
        } catch (\Exception $e) {
            self::logOnce('ip2:init', "IP2LocationProvider: Failed to initialize database at {$this->databasePath}: " . $e->getMessage());
            self::logOnce('ip2:size', "IP2LocationProvider: File size: " . filesize($this->databasePath) . " bytes");
            $this->available = false;
            $this->db = null;
        }
    }

    /**
     * @inheritDoc
     */
    public function lookup(string $ip): ?GeoRecord
    {
        if (!$this->isAvailable() || !$this->db) {
            return null;
        }

        try {
            $record = $this->db->lookup($ip, Database::ALL);
            
            // Check if record is valid (IP2Location returns empty arrays for not found)
            if (empty($record) || !isset($record['countryCode'])) {
                return null;
            }
            
            return new GeoRecord(
                ip: $ip,
                country: $record['countryCode'] ?? 'N/A',
                region: $record['regionName'] ?? 'N/A',
                city: $record['cityName'] ?? 'N/A',
                postal: $record['zipCode'] ?? ($record['zipcode'] ?? ($record['zip_code'] ?? 'N/A')),
                latitude: isset($record['latitude']) && $record['latitude'] !== '' 
                    ? (float)$record['latitude'] 
                    : null,
                longitude: isset($record['longitude']) && $record['longitude'] !== '' 
                    ? (float)$record['longitude'] 
                    : null,
                accuracyKm: null, // IP2Location doesn't provide accuracy
                source: self::SOURCE_NAME
            );
        } catch (\Exception $e) {
            $this->debugLog("IP2LocationProvider: Lookup error for IP {$ip}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * @inheritDoc
     */
    public function getSourceName(): string
    {
        return self::SOURCE_NAME;
    }

    /**
     * @inheritDoc
     */
    public function isAvailable(): bool
    {
        return $this->available && $this->db !== null;
    }

    /**
     * @inheritDoc
     */
    public function supportsIPv6(): bool
    {
        // IP2Location BIN format supports IPv6
        return true;
    }

    /**
     * Get attribution text
     */
    public static function getAttribution(): string
    {
        return self::ATTRIBUTION;
    }
}

