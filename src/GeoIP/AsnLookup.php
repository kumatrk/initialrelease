<?php

declare(strict_types=1);

namespace SimpleKuma\GeoIP;

use MaxMind\Db\Reader as DbReader;

/**
 * Local ASN/ISP lookup (write-time only; store result on clicks.isp).
 *
 * Prefers redistributable DB-IP ASN Lite (CC BY 4.0), which is GeoLite2-ASN compatible.
 * Also accepts MaxMind GeoLite2-ASN if the operator installs it privately (do not ship
 * MaxMind GeoLite in the customer zip without a commercial redistribution license).
 */
final class AsnLookup
{
    private static ?self $instance = null;
    private ?DbReader $reader = null;
    private bool $initialized = false;
    private ?string $databasePath = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * @return array{isp: ?string, asn: ?int, connection_type: ?string}
     */
    public function lookup(string $ip): array
    {
        $empty = ['isp' => null, 'asn' => null, 'connection_type' => null];

        if ($ip === '' || IpUtils::isPrivateIp($ip) || !IpUtils::isValidIp($ip)) {
            return $empty;
        }

        $reader = $this->getReader();
        if ($reader === null) {
            return $empty;
        }

        try {
            $raw = $reader->get($ip);
            if (!is_array($raw)) {
                return $empty;
            }

            $org = $raw['autonomous_system_organization']
                ?? $raw['as_organization']
                ?? $raw['organization']
                ?? null;
            $org = is_string($org) ? trim($org) : null;
            if ($org === '') {
                $org = null;
            }

            $asn = $raw['autonomous_system_number'] ?? $raw['as_number'] ?? $raw['asn'] ?? null;
            $asn = is_numeric($asn) ? (int) $asn : null;

            return [
                'isp' => $org,
                'asn' => $asn,
                'connection_type' => \SimpleKuma\Enrichment\ConnectionTypeNormalizer::fromAsnOrganization($org),
            ];
        } catch (\Throwable $e) {
            error_log('AsnLookup: ' . $e->getMessage());
            return $empty;
        }
    }

    public function getDatabasePath(): ?string
    {
        $this->getReader();
        return $this->databasePath;
    }

    private function getReader(): ?DbReader
    {
        if ($this->initialized) {
            return $this->reader;
        }
        $this->initialized = true;

        if (!class_exists(DbReader::class)) {
            return null;
        }

        $path = $this->findDatabase();
        if ($path === null) {
            return null;
        }

        try {
            $this->reader = new DbReader($path);
            $this->databasePath = $path;
        } catch (\Throwable $e) {
            error_log('AsnLookup: failed to open ASN DB: ' . $e->getMessage());
            $this->reader = null;
            $this->databasePath = null;
        }

        return $this->reader;
    }

    private function findDatabase(): ?string
    {
        $rootPath = dirname(__DIR__, 2);
        $dirs = [
            defined('GEOIP_DATABASE_PATH') && is_string(GEOIP_DATABASE_PATH) && GEOIP_DATABASE_PATH !== ''
                ? (is_dir(GEOIP_DATABASE_PATH) ? GEOIP_DATABASE_PATH : dirname(GEOIP_DATABASE_PATH))
                : null,
            $rootPath . DIRECTORY_SEPARATOR . 'geoip',
            $rootPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'geoip',
            $rootPath . DIRECTORY_SEPARATOR . 'storage',
            defined('ROOT_PATH') ? ROOT_PATH . DIRECTORY_SEPARATOR . 'geoip' : null,
            defined('ROOT_PATH') ? ROOT_PATH . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'geoip' : null,
        ];

        // Prefer redistributable DB-IP ASN Lite for packaging with Kuma.
        $names = [
            'DBIP-ASN-Lite.mmdb',
            'dbip-asn-lite.mmdb',
            'GeoLite2-ASN.mmdb',
            'geolite2-asn.mmdb',
            'GeoIP2-ASN.mmdb',
        ];

        foreach ($dirs as $dir) {
            if ($dir === null || !is_dir($dir)) {
                continue;
            }
            foreach ($names as $name) {
                $path = $dir . DIRECTORY_SEPARATOR . $name;
                if (is_file($path) && is_readable($path)) {
                    return $path;
                }
            }
        }

        return null;
    }
}
