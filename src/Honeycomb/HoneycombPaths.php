<?php

declare(strict_types=1);

namespace SimpleKuma\Honeycomb;

/**
 * Filesystem locations for Honeycomb (outside public/).
 */
final class HoneycombPaths
{
    public static function projectRoot(): string
    {
        $resolved = realpath(dirname(__DIR__, 2));
        return $resolved !== false ? $resolved : dirname(__DIR__, 2);
    }

    public static function honeycombRoot(): string
    {
        return self::projectRoot() . DIRECTORY_SEPARATOR . 'honeycomb';
    }

    public static function addonsRoot(): string
    {
        return self::honeycombRoot() . DIRECTORY_SEPARATOR . 'addons';
    }

    public static function addonDir(string $slug): string
    {
        $slug = trim($slug);
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            throw new \InvalidArgumentException('Invalid Honeycomb addon slug.');
        }
        return self::addonsRoot() . DIRECTORY_SEPARATOR . $slug;
    }

    public static function catalogCacheFile(): string
    {
        return self::honeycombRoot() . DIRECTORY_SEPARATOR . 'catalog-cache.json';
    }

    public static function ensureRuntimeDirs(): void
    {
        $addons = self::addonsRoot();
        if (!is_dir($addons)) {
            mkdir($addons, 0755, true);
        }
    }
}
