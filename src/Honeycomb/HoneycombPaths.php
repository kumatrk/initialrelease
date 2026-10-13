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

    /**
     * Resolve a file under honeycomb/addons/{slug}/assets/ with path-traversal guards.
     * $relative is relative to assets/ (e.g. campaign-tiktok.js).
     */
    public static function resolveAddonAsset(string $slug, string $relative): ?string
    {
        $relative = str_replace('\\', '/', trim($relative));
        $relative = ltrim($relative, '/');
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }
        if (preg_match('#^[a-zA-Z0-9._/-]+$#', $relative) !== 1) {
            return null;
        }

        try {
            $addonDir = self::addonDir($slug);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $assetsRoot = $addonDir . DIRECTORY_SEPARATOR . 'assets';
        $assetsReal = realpath($assetsRoot);
        if ($assetsReal === false || !is_dir($assetsReal)) {
            return null;
        }

        $candidate = $assetsReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $fileReal = realpath($candidate);
        if ($fileReal === false || !is_file($fileReal)) {
            return null;
        }

        $prefix = $assetsReal . DIRECTORY_SEPARATOR;
        if (!str_starts_with($fileReal, $prefix) && $fileReal !== $assetsReal) {
            return null;
        }

        return $fileReal;
    }
}
