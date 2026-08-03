<?php

declare(strict_types=1);

namespace SimpleKuma\Installer;

/**
 * Load Composer autoloader without scanning vendor on every request.
 * Recursive chmod is only used on Unix when autoload fails (e.g. after FTP upload).
 */
class VendorPermissionFixer
{
    public static function loadAutoloader(string $autoloadPath): bool
    {
        if (!is_file($autoloadPath)) {
            return false;
        }

        try {
            require_once $autoloadPath;
            return true;
        } catch (\Throwable $first) {
            if (PHP_OS_FAMILY === 'Windows') {
                throw $first;
            }

            self::fixVendorPermissions(dirname($autoloadPath));

            require_once $autoloadPath;
            return true;
        }
    }

    private static function fixVendorPermissions(string $vendorPath): void
    {
        if (!is_dir($vendorPath)) {
            return;
        }

        $fixDirPerms = function (string $dir) use (&$fixDirPerms): void {
            if (!is_dir($dir)) {
                return;
            }

            @chmod($dir, 0755);

            $items = @scandir($dir);
            if ($items === false) {
                return;
            }

            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                $itemPath = $dir . DIRECTORY_SEPARATOR . $item;

                if (is_dir($itemPath)) {
                    $fixDirPerms($itemPath);
                } else {
                    @chmod($itemPath, 0644);
                }
            }
        };

        $fixDirPerms($vendorPath);
    }
}
