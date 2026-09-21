<?php

declare(strict_types=1);

namespace SimpleKuma\Database;

use mysqli;

/**
 * MySQL / MariaDB version and feature checks for install and migrations.
 */
class DatabaseCompatibility
{
    /** Minimum for JSON generated columns and modern installer migrations */
    public const MIN_MYSQL_VERSION = '8.0.0';
    public const MIN_MARIADB_VERSION = '10.3.0';

    /**
     * @return array{isMariaDb: bool, version: string, raw: string}
     */
    public static function parseServerInfo(string $serverInfo): array
    {
        $isMariaDb = stripos($serverInfo, 'mariadb') !== false;
        $version = '0.0.0';

        if (preg_match('/(\d+)\.(\d+)\.(\d+)/', $serverInfo, $matches)) {
            $version = $matches[1] . '.' . $matches[2] . '.' . $matches[3];
        }

        return [
            'isMariaDb' => $isMariaDb,
            'version' => $version,
            'raw' => $serverInfo,
        ];
    }

    /**
     * Human-readable requirement line for installer UI.
     */
    public static function getRequirementLabel(): string
    {
        return 'MySQL 8.0+ or MariaDB 10.3+ (required for performance migrations)';
    }

    /**
     * Returns an error message if the server does not meet requirements, or null if OK.
     */
    public static function validateServer(string $serverInfo): ?string
    {
        $parsed = self::parseServerInfo($serverInfo);

        if ($parsed['version'] === '0.0.0') {
            return 'Could not detect database server version from: ' . $serverInfo;
        }

        if ($parsed['isMariaDb']) {
            if (version_compare($parsed['version'], self::MIN_MARIADB_VERSION, '<')) {
                return sprintf(
                    'MariaDB %s is too old (detected: %s). %s or higher is required.',
                    $parsed['version'],
                    $parsed['raw'],
                    self::MIN_MARIADB_VERSION
                );
            }
            return null;
        }

        if (version_compare($parsed['version'], self::MIN_MYSQL_VERSION, '<')) {
            return sprintf(
                'MySQL %s is too old (detected: %s). %s or higher is required.',
                $parsed['version'],
                $parsed['raw'],
                self::MIN_MYSQL_VERSION
            );
        }

        return null;
    }

    public static function validateConnection(mysqli $db): ?string
    {
        return self::validateServer($db->server_info ?? '');
    }

    /**
     * Cap SELECT runtime for reporting APIs so abandoned browser requests cannot
     * leave MySQL grinding after the user navigates away (shared hosts / Cloudflare 522s).
     *
     * MariaDB: max_statement_time (seconds). MySQL 5.7.8+: MAX_EXECUTION_TIME (milliseconds).
     */
    public static function applyReportingQueryTimeout(mysqli $db, int $seconds = 20): void
    {
        $seconds = max(5, min(60, $seconds));
        $info = (string)($db->server_info ?? '');
        $parsed = self::parseServerInfo($info);

        if ($parsed['isMariaDb']) {
            @$db->query('SET SESSION max_statement_time = ' . (int)$seconds);
            return;
        }

        // MySQL SELECT max execution time is in milliseconds
        @$db->query('SET SESSION MAX_EXECUTION_TIME = ' . ((int)$seconds * 1000));
    }

    /**
     * Short summary for display after a successful DB test.
     */
    public static function formatServerSummary(string $serverInfo): string
    {
        $parsed = self::parseServerInfo($serverInfo);
        $label = $parsed['isMariaDb'] ? 'MariaDB' : 'MySQL';

        return $label . ' ' . $parsed['version'] . ' (' . $parsed['raw'] . ')';
    }
}
