<?php

declare(strict_types=1);

namespace SimpleKuma\GeoIP\Providers;

use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use SimpleKuma\GeoIP\GeoProvider;
use SimpleKuma\GeoIP\GeoRecord;
use SimpleKuma\Support\AppDebugLog;

/**
 * DB-IP Lite Provider
 * 
 * Uses DB-IP Lite City database in MMDB format.
 * License: CC BY 4.0
 * Attribution: "IP Geolocation by DB-IP. https://db-ip.com"
 */
class DBIPProvider implements GeoProvider
{
    use AppDebugLog;

    private const SOURCE_NAME = 'dbip';
    private const ATTRIBUTION = 'IP Geolocation by DB-IP. https://db-ip.com';
    
    private ?string $databasePath = null;
    private ?Reader $reader = null;
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
            'dbip-city-lite.mmdb',
            'DBIP-City-Lite.mmdb',
            'dbip-city-lite.mmdb.gz',
            'GeoLite2-City.mmdb', // Legacy MaxMind name
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

                // Also do case-insensitive search
                $files = @scandir($basePath);
                if ($files !== false) {
                    foreach ($files as $file) {
                        if ($file === '.' || $file === '..') {
                            continue;
                        }
                        $lowerFile = strtolower($file);
                        if (strpos($lowerFile, 'dbip') !== false && 
                            (strpos($lowerFile, '.mmdb') !== false || strpos($lowerFile, '.mmdb.gz') !== false)) {
                            $path = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . $file;
                            if (is_readable($path)) {
                                return $path;
                            }
                        }
                    }
                }
            }
        }

        // Log for debugging if not found
        self::logOnce('dbip:notfound', "DBIPProvider: Database not found. Checked paths: " . implode(', ', array_filter($basePaths)));
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
            self::logOnce('dbip:missing', "DBIPProvider: Database file does not exist: {$this->databasePath}");
            $this->available = false;
            return;
        }

        if (!is_readable($this->databasePath)) {
            self::logOnce('dbip:unreadable', "DBIPProvider: Database file is not readable: {$this->databasePath}");
            $this->available = false;
            return;
        }

        if (!class_exists(Reader::class)) {
            self::logOnce('dbip:class', "DBIPProvider: GeoIp2\Database\Reader class not found. Make sure composer dependencies are installed.");
            $this->available = false;
            return;
        }

        try {
            $this->reader = new Reader($this->databasePath);
            $this->available = true;
        } catch (\Exception $e) {
            self::logOnce('dbip:init', "DBIPProvider: Failed to initialize database at {$this->databasePath}: " . $e->getMessage());
            self::logOnce('dbip:size', "DBIPProvider: File size: " . filesize($this->databasePath) . " bytes");
            $this->available = false;
            $this->reader = null;
        }
    }

    /**
     * @inheritDoc
     */
    public function lookup(string $ip): ?GeoRecord
    {
        if (!$this->isAvailable() || !$this->reader) {
            return null;
        }

        try {
            $record = $this->reader->city($ip);
            
            return new GeoRecord(
                ip: $ip,
                country: $record->country->isoCode ?? 'N/A',
                region: $record->mostSpecificSubdivision->name ?? 'N/A',
                city: $record->city->name ?? 'N/A',
                postal: (isset($record->postal) && $record->postal !== null) ? ($record->postal->code ?? 'N/A') : 'N/A',
                latitude: $record->location->latitude ?? null,
                longitude: $record->location->longitude ?? null,
                accuracyKm: null, // DB-IP doesn't provide accuracy
                source: self::SOURCE_NAME
            );
        } catch (AddressNotFoundException $e) {
            // IP not found in database
            return null;
        } catch (\Exception $e) {
            $this->debugLog("DBIPProvider: Lookup error for IP {$ip}: " . $e->getMessage());
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
        return $this->available && $this->reader !== null;
    }

    /**
     * @inheritDoc
     */
    public function supportsIPv6(): bool
    {
        // MMDB format supports IPv6
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

