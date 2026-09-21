<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

/**
 * Combined click exclusions for stats views (FB approval/crawler + hidden IPs).
 *
 * Uses the persisted flag after migration 081, with legacy rule evaluation only while
 * upgrading. Hot dashboard/list paths use StatsExclusionFlag directly so their
 * COUNT(*) queries remain covering-index only.
 */
final class StatsViewExclusions
{
    /**
     * AND-able SQL fragment (no leading AND). Empty string when nothing to exclude.
     */
    public static function clickWhereSql(\mysqli $db, string $clAlias = 'cl'): string
    {
        $persisted = StatsExclusionFlag::includedWhere($db, $clAlias);
        $parts = [
            $persisted !== ''
                ? $persisted
                : CampaignStatsExpressions::excludeInvalidClickWhere($clAlias),
        ];
        $ipSql = (new StatsHiddenIpService($db))->exclusionSql($clAlias);
        if ($ipSql !== '') {
            $parts[] = $ipSql;
        }

        return implode(' AND ', $parts);
    }

    /**
     * Prefix with AND for appending to an existing WHERE clause.
     */
    public static function andClickWhereSql(\mysqli $db, string $clAlias = 'cl'): string
    {
        $sql = self::clickWhereSql($db, $clAlias);

        return $sql === '' ? '' : ' AND ' . $sql;
    }
}
