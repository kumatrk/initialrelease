<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

/**
 * Combined click exclusions for stats views (FB approval/crawler + hidden IPs).
 *
 * Use on visitor log, cron rebuilds, and similar non-hot paths.
 * Do NOT apply to dashboard/list reliability checks or covering-index COUNT(*)
 * aggregates — ua/ad_id predicates force full row reads and destroy the fast path.
 * Those surfaces rely on DailySummaryUpdater write-time exclusion instead.
 */
final class StatsViewExclusions
{
    /**
     * AND-able SQL fragment (no leading AND). Empty string when nothing to exclude.
     */
    public static function clickWhereSql(\mysqli $db, string $clAlias = 'cl'): string
    {
        $parts = [CampaignStatsExpressions::excludeInvalidClickWhere($clAlias)];
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
