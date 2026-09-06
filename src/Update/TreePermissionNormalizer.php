<?php

declare(strict_types=1);

namespace SimpleKuma\Update;

/**
 * Normalises file modes after ZIP extract / one-click update.
 * ZipArchive::extractTo() (and PHP copy under a permissive umask) can leave
 * application sources world-writable (0666); that must never ship to disk.
 */
final class TreePermissionNormalizer
{
    private const FILE_MODE = 0644;
    private const DIR_MODE = 0755;

    /**
     * Paths (relative to install root, forward-slash) that keep their existing mode.
     * config.php is deliberately 0600 via ConfigWriter.
     *
     * @var list<string>
     */
    private const SKIP_RELATIVE = [
        'config/config.php',
    ];

    /**
     * Recursively chmod directories to 0755 and files to 0644 under $root.
     * Skips Windows (chmod is not meaningful for this threat model there).
     */
    public static function normalizeTree(string $root): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !is_dir($root)) {
            return;
        }

        $rootReal = realpath($root);
        if ($rootReal === false) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootReal, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $path = $item->getPathname();
            if (self::shouldSkip($rootReal, $path)) {
                continue;
            }
            if ($item->isDir()) {
                @chmod($path, self::DIR_MODE);
            } elseif ($item->isFile()) {
                @chmod($path, self::FILE_MODE);
            }
        }

        @chmod($rootReal, self::DIR_MODE);
    }

    /**
     * @param list<string> $relativePaths Forward-slash paths relative to $installRoot
     */
    public static function normalizeRelativeFiles(string $installRoot, array $relativePaths): void
    {
        if (PHP_OS_FAMILY === 'Windows' || $relativePaths === []) {
            return;
        }

        $rootReal = realpath($installRoot);
        if ($rootReal === false) {
            return;
        }

        $dirs = [];
        foreach ($relativePaths as $rel) {
            $rel = str_replace('\\', '/', ltrim($rel, '/'));
            if ($rel === '' || in_array($rel, self::SKIP_RELATIVE, true)) {
                continue;
            }
            $full = $rootReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (is_file($full)) {
                @chmod($full, self::FILE_MODE);
                $dir = dirname($full);
                while ($dir !== false && $dir !== $rootReal && str_starts_with($dir, $rootReal)) {
                    $dirs[$dir] = true;
                    $parent = dirname($dir);
                    if ($parent === $dir) {
                        break;
                    }
                    $dir = $parent;
                }
            }
        }

        foreach (array_keys($dirs) as $dir) {
            @chmod($dir, self::DIR_MODE);
        }
    }

    private static function shouldSkip(string $rootReal, string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);
        $rootNorm = str_replace('\\', '/', $rootReal);
        foreach (self::SKIP_RELATIVE as $skip) {
            $target = $rootNorm . '/' . $skip;
            if ($normalized === $target || str_ends_with($normalized, '/' . $skip)) {
                return true;
            }
        }

        return false;
    }
}
