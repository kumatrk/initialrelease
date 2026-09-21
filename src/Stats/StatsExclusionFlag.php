<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

use mysqli;

/**
 * Schema-aware access to the persisted click stats-exclusion flag.
 *
 * Hot reporting paths use this single indexed boolean instead of re-evaluating
 * user-agent and traffic-token rules for every historical click.
 */
final class StatsExclusionFlag
{
    /** @var array<string, bool> */
    private static array $columnCache = [];

    public static function columnExists(mysqli $db, string $table = 'clicks'): bool
    {
        $cacheKey = self::cacheKey($db, $table);
        if (array_key_exists($cacheKey, self::$columnCache)) {
            return self::$columnCache[$cacheKey];
        }

        $stmt = $db->prepare(
            "SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = 'exclude_from_stats'
             LIMIT 1"
        );
        if ($stmt === false) {
            return self::$columnCache[$cacheKey] = false;
        }

        $stmt->bind_param('s', $table);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();

        return self::$columnCache[$cacheKey] = $exists;
    }

    /**
     * AND-able predicate. Empty while upgrading from a schema without the flag.
     */
    public static function includedWhere(mysqli $db, string $alias = 'cl', string $table = 'clicks'): string
    {
        return self::columnExists($db, $table)
            ? "{$alias}.exclude_from_stats = 0"
            : '';
    }

    public static function clearCache(mysqli $db, string $table): void
    {
        unset(self::$columnCache[self::cacheKey($db, $table)]);
    }

    private static function cacheKey(mysqli $db, string $table): string
    {
        return spl_object_id($db) . ':' . $db->thread_id . ':' . $table;
    }
}
