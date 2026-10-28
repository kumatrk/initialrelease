<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

use mysqli;
use SimpleKuma\Database\SchemaProbe;

/**
 * Schema-aware access to the persisted click stats-exclusion flag.
 *
 * Hot reporting paths use this single indexed boolean instead of re-evaluating
 * user-agent and traffic-token rules for every historical click.
 */
final class StatsExclusionFlag
{
    public static function columnExists(mysqli $db, string $table = 'clicks'): bool
    {
        return SchemaProbe::columnExists($db, $table, 'exclude_from_stats');
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
        SchemaProbe::forget($db, $table, 'exclude_from_stats');
    }
}
