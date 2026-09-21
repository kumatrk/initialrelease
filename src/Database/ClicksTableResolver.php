<?php

declare(strict_types=1);

namespace SimpleKuma\Database;

use mysqli;

/**
 * Resolves whether reporting/conversion lookups use clicks vs clicks_unified / archive.
 */
class ClicksTableResolver
{
    private static ?string $statsTable = null;

    private static ?bool $archiveExists = null;

    /**
     * Table or view for aggregate stats queries (includes archived clicks when unified view is current).
     *
     * Prefer the base `clicks` table when the archive is empty: `clicks_unified` is a
     * UNION ALL view and is orders of magnitude slower for the same filters.
     */
    public static function getStatsTable(mysqli $db): string
    {
        if (self::$statsTable !== null) {
            return self::$statsTable;
        }

        if (!self::viewExists($db) || !self::viewHasColumn($db, 'ad_id')) {
            self::$statsTable = 'clicks';
            return self::$statsTable;
        }

        if (!self::archiveTableExists($db) || !self::archiveHasRows($db)) {
            self::$statsTable = 'clicks';
            return self::$statsTable;
        }

        self::$statsTable = 'clicks_unified';
        return self::$statsTable;
    }

    /**
     * True when clicks_archive has at least one row (worth paying for the unified view).
     */
    public static function archiveHasRows(mysqli $db): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        if (!self::archiveTableExists($db)) {
            $cached = false;
            return false;
        }
        $result = $db->query('SELECT 1 FROM clicks_archive LIMIT 1');
        $cached = $result !== false && $result->num_rows > 0;
        return $cached;
    }

    /**
     * Tables to search for a click_id (active first, then archive).
     *
     * @return list<string>
     */
    public static function getClickLookupTables(mysqli $db): array
    {
        $tables = ['clicks'];
        if (self::archiveTableExists($db)) {
            $tables[] = 'clicks_archive';
        }
        return $tables;
    }

    public static function viewExists(mysqli $db): bool
    {
        $dbName = $db->query('SELECT DATABASE()')->fetch_row()[0] ?? '';
        $stmt = $db->prepare(
            "SELECT 1 FROM information_schema.VIEWS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'clicks_unified' LIMIT 1"
        );
        $stmt->bind_param('s', $dbName);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
        return $exists;
    }

    public static function archiveTableExists(mysqli $db): bool
    {
        if (self::$archiveExists !== null) {
            return self::$archiveExists;
        }
        $result = $db->query("SHOW TABLES LIKE 'clicks_archive'");
        self::$archiveExists = $result && $result->num_rows > 0;
        return self::$archiveExists;
    }

    private static function viewHasColumn(mysqli $db, string $column): bool
    {
        $dbName = $db->query('SELECT DATABASE()')->fetch_row()[0] ?? '';
        $stmt = $db->prepare(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'clicks_unified' AND COLUMN_NAME = ? LIMIT 1"
        );
        $stmt->bind_param('ss', $dbName, $column);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
        return $exists;
    }
}
