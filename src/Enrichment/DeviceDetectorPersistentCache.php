<?php

declare(strict_types=1);

namespace SimpleKuma\Enrichment;

use DeviceDetector\Cache\CacheInterface;
use DeviceDetector\DeviceDetector as MatomoDeviceDetector;

/**
 * Persistent caches for Matomo device detection.
 *
 * Edge ingest re-detects every click. Matomo's StaticCache lives for one
 * request, so each click re-parsed the YAML regex set (~hundreds of ms).
 *
 * Two layers, both keyed by the Matomo library version:
 * - Finished result as JSON, one file per UA hash, 30-day TTL.
 * - Matomo's regex cache as OPcache-friendly PHP files.
 *
 * Any failure falls through to a normal parse. A click is never dropped
 * because the cache could not be read or written.
 */
final class DeviceDetectorPersistentCache
{
    private const TTL_DAYS = 30;

    public static function baseDir(): string
    {
        $version = 'unknown';
        if (defined(MatomoDeviceDetector::class . '::VERSION')) {
            $version = (string) MatomoDeviceDetector::VERSION;
        }
        $safe = preg_replace('/[^0-9A-Za-z._-]/', '', $version);
        if (!is_string($safe) || $safe === '') {
            $safe = 'unknown';
        }

        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'cache'
            . DIRECTORY_SEPARATOR . 'devicedetector'
            . DIRECTORY_SEPARATOR . $safe;
    }

    public static function uaPath(string $uaHash): string
    {
        return self::baseDir()
            . DIRECTORY_SEPARATOR . 'ua'
            . DIRECTORY_SEPARATOR . substr($uaHash, 0, 2)
            . DIRECTORY_SEPARATOR . $uaHash . '.json';
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getUa(string $uaHash): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $uaHash)) {
            return null;
        }

        try {
            $file = self::uaPath($uaHash);
            if (!is_file($file)) {
                return null;
            }
            $mtime = @filemtime($file);
            if ($mtime === false || $mtime < time() - self::TTL_DAYS * 86400) {
                return null;
            }
            $raw = @file_get_contents($file);
            if ($raw === false || $raw === '') {
                return null;
            }
            $data = json_decode($raw, true);
            if (!is_array($data) || !isset($data['device']) || !is_string($data['device'])) {
                return null;
            }
            foreach (['os', 'browser', 'is_bot'] as $required) {
                if (!array_key_exists($required, $data)) {
                    return null;
                }
            }

            return $data;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    public static function putUa(string $uaHash, array $result): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $uaHash) || !isset($result['device'])) {
            return;
        }

        try {
            $file = self::uaPath($uaHash);
            $dir = dirname($file);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                return;
            }
            $json = json_encode($result);
            if ($json === false) {
                return;
            }
            self::atomicWrite($file, $json);
        } catch (\Throwable $e) {
            // Cache is optional.
        }
    }

    public static function regexCache(): CacheInterface
    {
        return new DeviceDetectorRegexCache(self::baseDir() . DIRECTORY_SEPARATOR . 'regex');
    }

    /**
     * Drop the in-request regex copy. Disk files stay. Used by tests.
     */
    public static function resetRegexMemory(): void
    {
        DeviceDetectorRegexCache::clearMemory();
    }

    public static function atomicWrite(string $file, string $contents): bool
    {
        $tmp = $file . '.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $contents, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }
        if (PHP_OS_FAMILY === 'Windows' && is_file($file)) {
            @unlink($file);
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }
}

/**
 * Matomo regex cache persisted as PHP files so OPcache keeps them compiled.
 */
final class DeviceDetectorRegexCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private static array $mem = [];

    public function __construct(private string $dir)
    {
    }

    public static function clearMemory(): void
    {
        self::$mem = [];
    }

    private function file(string $id): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . md5($id) . '.php';
    }

    public function fetch(string $id)
    {
        if (array_key_exists($id, self::$mem)) {
            return self::$mem[$id];
        }
        $file = $this->file($id);
        if (!is_file($file)) {
            return false;
        }
        try {
            $data = include $file;
        } catch (\Throwable $e) {
            return false;
        }
        if ($data === false) {
            return false;
        }
        self::$mem[$id] = $data;

        return $data;
    }

    public function contains(string $id): bool
    {
        return array_key_exists($id, self::$mem) || is_file($this->file($id));
    }

    public function save(string $id, $data, int $lifeTime = 0): bool
    {
        self::$mem[$id] = $data;
        try {
            if (!is_dir($this->dir) && !@mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
                return true;
            }
            $exported = var_export($data, true);
            if (!is_string($exported) || $exported === '') {
                return true;
            }
            DeviceDetectorPersistentCache::atomicWrite(
                $this->file($id),
                '<?php return ' . $exported . ';'
            );
        } catch (\Throwable $e) {
            // Cache is optional. The in-request copy is already set.
        }

        return true;
    }

    public function delete(string $id): bool
    {
        unset(self::$mem[$id]);

        return @unlink($this->file($id));
    }

    public function flushAll(): bool
    {
        self::$mem = [];
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            @unlink($file);
        }

        return true;
    }
}
