<?php

declare(strict_types=1);

namespace SimpleKuma\Database\Migrations;

use mysqli;
use SimpleKuma\Database\ClicksUnifiedViewSync;
use SimpleKuma\Stats\StatsExclusionFlag;
use SimpleKuma\Stats\StatsViewExclusions;
use SimpleKuma\Tracking\DailySummaryUpdater;

/**
 * Adds and backfills the persisted click exclusion flag used by fast stats.
 */
final class AddStatsExclusionFlag
{
    public static function run(mysqli $db): ?string
    {
        if (!$db->query("SET time_zone = '+00:00'")) {
            return "Could not set UTC database session for migration 081: {$db->error}";
        }
        if (!self::tableExists($db, 'clicks')) {
            return 'clicks table is missing';
        }

        $error = self::ensureColumn($db, 'clicks');
        if ($error !== null) {
            return $error;
        }
        StatsExclusionFlag::clearCache($db, 'clicks');

        $hasArchive = self::tableExists($db, 'clicks_archive');
        if ($hasArchive) {
            $sync = ClicksUnifiedViewSync::sync($db);
            if (!$sync['success']) {
                return implode('; ', $sync['messages']);
            }
            StatsExclusionFlag::clearCache($db, 'clicks_archive');
            StatsExclusionFlag::clearCache($db, 'clicks_unified');
        }

        $tables = $hasArchive ? ['clicks', 'clicks_archive'] : ['clicks'];
        $convertedDates = [];
        foreach ($tables as $table) {
            $error = self::backfillTable($db, $table);
            if ($error !== null) {
                return $error;
            }
            $convertedDates = array_merge($convertedDates, self::convertedExcludedDates($db, $table));
            $error = self::includeConvertedClicks($db, $table);
            if ($error !== null) {
                return $error;
            }

            $error = self::ensureCoveringIndex($db, $table);
            if ($error !== null) {
                return $error;
            }
        }

        foreach (array_values(array_unique($convertedDates)) as $summaryDate) {
            $error = self::rebuildSummaryDate($db, $summaryDate, $hasArchive);
            if ($error !== null) {
                return $error;
            }
            $error = self::rebuildTokenSummaryDate($db, $summaryDate, $hasArchive);
            if ($error !== null) {
                return $error;
            }
        }

        if ($hasArchive) {
            $sync = ClicksUnifiedViewSync::sync($db);
            if (!$sync['success']) {
                return implode('; ', $sync['messages']);
            }
        }

        return null;
    }

    private static function ensureColumn(mysqli $db, string $table): ?string
    {
        if (self::columnExists($db, $table, 'exclude_from_stats')) {
            return null;
        }

        $sql = "ALTER TABLE `{$table}`
                ADD COLUMN exclude_from_stats TINYINT(1) NOT NULL DEFAULT 0
                COMMENT '1 = omit from reporting until a conversion proves the click real'
                AFTER extra_json";

        return $db->query($sql)
            ? null
            : "Could not add {$table}.exclude_from_stats: {$db->error}";
    }

    private static function backfillTable(mysqli $db, string $table): ?string
    {
        if (!self::columnExists($db, $table, 'ad_id')
            || !self::columnExists($db, $table, 'adset_id')) {
            return "{$table} is missing generated ad_id/adset_id columns";
        }

        $sql = "UPDATE `{$table}` cl
                SET cl.exclude_from_stats = CASE
                    WHEN LOWER(COALESCE(cl.ua, '')) LIKE '%facebookexternalhit/1.1%'
                      OR LOWER(COALESCE(cl.ua, '')) LIKE '%meta-externalads/1.1%'
                      OR (
                          cl.traffic_source_id = 4
                          AND (
                              cl.ad_id IS NULL OR cl.ad_id = 0
                              OR cl.adset_id IS NULL OR cl.adset_id = 0
                          )
                      )
                    THEN 1
                    ELSE 0
                END";

        return $db->query($sql)
            ? null
            : "Could not backfill {$table}.exclude_from_stats: {$db->error}";
    }

    /**
     * @return list<string>
     */
    private static function convertedExcludedDates(mysqli $db, string $table): array
    {
        $result = $db->query(
            "SELECT DISTINCT DATE(cl.ts) AS summary_date
             FROM `{$table}` cl
             WHERE cl.exclude_from_stats = 1
               AND EXISTS (SELECT 1 FROM conversions cv WHERE cv.click_id = cl.click_id)"
        );
        if ($result === false) {
            return [];
        }

        $dates = [];
        while ($row = $result->fetch_assoc()) {
            if (!empty($row['summary_date'])) {
                $dates[] = (string)$row['summary_date'];
            }
        }

        return $dates;
    }

    private static function includeConvertedClicks(mysqli $db, string $table): ?string
    {
        $sql = "UPDATE `{$table}` cl
                SET cl.exclude_from_stats = 0
                WHERE cl.exclude_from_stats = 1
                  AND EXISTS (SELECT 1 FROM conversions cv WHERE cv.click_id = cl.click_id)";

        return $db->query($sql)
            ? null
            : "Could not include converted clicks in {$table}: {$db->error}";
    }

    private static function rebuildSummaryDate(mysqli $db, string $summaryDate, bool $hasArchive): ?string
    {
        if (!self::tableExists($db, 'clicks_daily_summary')) {
            return null;
        }

        $db->begin_transaction();
        $delete = $db->prepare('DELETE FROM clicks_daily_summary WHERE summary_date = ?');
        if ($delete === false) {
            $db->rollback();
            return "Could not prepare summary rebuild for {$summaryDate}: {$db->error}";
        }
        $delete->bind_param('s', $summaryDate);
        if (!$delete->execute()) {
            $error = $delete->error;
            $delete->close();
            $db->rollback();
            return "Could not clear summary rows for {$summaryDate}: {$error}";
        }
        $delete->close();

        $archiveUnion = $hasArchive
            ? ' UNION ALL
                SELECT id, campaign_id, traffic_source_id, offer_id, landing_page_id,
                       click_id, ts, ip, lp_click, cost, exclude_from_stats
                FROM clicks_archive WHERE ts >= ? AND ts < DATE_ADD(?, INTERVAL 1 DAY)'
            : '';
        $exclusions = StatsViewExclusions::andClickWhereSql($db, 'cl');
        $sql = "
            INSERT INTO clicks_daily_summary
            (campaign_id, traffic_source_id, offer_id, landing_page_id, summary_date,
             clicks, lp_clicks, direct_clicks, conversions, revenue, cost, profit, roi)
            SELECT
                cl.campaign_id,
                cl.traffic_source_id,
                cl.offer_id,
                cl.landing_page_id,
                DATE(cl.ts),
                COUNT(DISTINCT cl.id),
                COUNT(DISTINCT CASE
                    WHEN cl.lp_click = 1 AND cl.landing_page_id IS NOT NULL THEN cl.id
                END),
                COUNT(DISTINCT CASE
                    WHEN cl.lp_click = 1 AND cl.landing_page_id IS NULL THEN cl.id
                END),
                COALESCE(SUM(conv.conversion_count), 0),
                COALESCE(SUM(conv.revenue_sum), 0),
                COALESCE(SUM(cl.cost), 0),
                COALESCE(SUM(conv.revenue_sum), 0) - COALESCE(SUM(cl.cost), 0),
                CASE WHEN COALESCE(SUM(cl.cost), 0) > 0
                    THEN ((COALESCE(SUM(conv.revenue_sum), 0)
                        - COALESCE(SUM(cl.cost), 0)) / COALESCE(SUM(cl.cost), 0)) * 100
                    ELSE NULL
                END
            FROM (
                SELECT id, campaign_id, traffic_source_id, offer_id, landing_page_id,
                       click_id, ts, ip, lp_click, cost, exclude_from_stats
                FROM clicks WHERE ts >= ? AND ts < DATE_ADD(?, INTERVAL 1 DAY)
                {$archiveUnion}
            ) cl
            LEFT JOIN (
                SELECT click_id, COUNT(*) conversion_count,
                       COALESCE(SUM(COALESCE(payout, value)), 0) revenue_sum
                FROM conversions
                GROUP BY click_id
            ) conv ON conv.click_id = cl.click_id
            WHERE 1=1 {$exclusions}
            GROUP BY cl.campaign_id, cl.traffic_source_id, cl.offer_id,
                     cl.landing_page_id, DATE(cl.ts)";
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            $db->rollback();
            return "Could not prepare summary rebuild for {$summaryDate}: {$db->error}";
        }
        if ($hasArchive) {
            $stmt->bind_param('ssss', $summaryDate, $summaryDate, $summaryDate, $summaryDate);
        } else {
            $stmt->bind_param('ss', $summaryDate, $summaryDate);
        }
        $ok = $stmt->execute();
        $error = $stmt->error;
        $stmt->close();

        if (!$ok) {
            $db->rollback();
            return "Could not rebuild summary rows for {$summaryDate}: {$error}";
        }
        $db->commit();

        return null;
    }

    private static function rebuildTokenSummaryDate(
        mysqli $db,
        string $summaryDate,
        bool $hasArchive
    ): ?string {
        if (!self::tableExists($db, 'clicks_stats_by_token_daily')) {
            return null;
        }

        $db->begin_transaction();
        $delete = $db->prepare(
            'DELETE FROM clicks_stats_by_token_daily WHERE summary_date = ?'
        );
        if ($delete === false) {
            $db->rollback();
            return "Could not prepare token summary rebuild for {$summaryDate}: {$db->error}";
        }
        $delete->bind_param('s', $summaryDate);
        if (!$delete->execute()) {
            $error = $delete->error;
            $delete->close();
            $db->rollback();
            return "Could not clear token summaries for {$summaryDate}: {$error}";
        }
        $delete->close();

        $archiveUnion = $hasArchive
            ? ' UNION ALL
                SELECT campaign_id, traffic_source_id, click_id, ts, ip, ua,
                       lp_click, cost, extra_json, exclude_from_stats
                FROM clicks_archive WHERE ts >= ? AND ts < DATE_ADD(?, INTERVAL 1 DAY)'
            : '';
        $exclusions = StatsViewExclusions::andClickWhereSql($db, 'cl');
        $sql = "
            SELECT cl.campaign_id, cl.traffic_source_id, cl.click_id, DATE(cl.ts) summary_date,
                   cl.ip, cl.ua, cl.lp_click, cl.cost, cl.extra_json,
                   COALESCE(conv.conversion_count, 0) conversion_count,
                   COALESCE(conv.revenue_sum, 0) revenue_sum
            FROM (
                SELECT campaign_id, traffic_source_id, click_id, ts, ip, ua,
                       lp_click, cost, extra_json, exclude_from_stats
                FROM clicks WHERE ts >= ? AND ts < DATE_ADD(?, INTERVAL 1 DAY)
                {$archiveUnion}
            ) cl
            LEFT JOIN (
                SELECT click_id, COUNT(*) conversion_count,
                       COALESCE(SUM(COALESCE(payout, value)), 0) revenue_sum
                FROM conversions
                GROUP BY click_id
            ) conv ON conv.click_id = cl.click_id
            WHERE 1=1 {$exclusions}";
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            $db->rollback();
            return "Could not prepare token summary source for {$summaryDate}: {$db->error}";
        }
        if ($hasArchive) {
            $stmt->bind_param('ssss', $summaryDate, $summaryDate, $summaryDate, $summaryDate);
        } else {
            $stmt->bind_param('ss', $summaryDate, $summaryDate);
        }
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            $db->rollback();
            return "Could not read token summary source for {$summaryDate}: {$error}";
        }

        $updater = new DailySummaryUpdater($db);
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $extraData = !empty($row['extra_json'])
                ? json_decode((string)$row['extra_json'], true)
                : null;
            if (!is_array($extraData)) {
                continue;
            }
            $campaignId = (int)$row['campaign_id'];
            $trafficSourceId = $row['traffic_source_id'] !== null
                ? (int)$row['traffic_source_id']
                : null;
            $lpClick = !empty($row['lp_click']) ? 1 : 0;
            $cost = $row['cost'] !== null ? (float)$row['cost'] : null;
            $ua = $row['ua'] !== null ? (string)$row['ua'] : null;
            $ip = $row['ip'] !== null ? (string)$row['ip'] : null;

            $updater->upsertTokenAggregatesForClick(
                $campaignId,
                $trafficSourceId,
                (string)$row['summary_date'],
                $extraData,
                $lpClick,
                $cost,
                0,
                0.0,
                $ua,
                $ip,
                true
            );
            $conversionCount = (int)$row['conversion_count'];
            if ($conversionCount > 0) {
                $updater->upsertTokenAggregatesForClick(
                    $campaignId,
                    $trafficSourceId,
                    (string)$row['summary_date'],
                    $extraData,
                    0,
                    null,
                    $conversionCount,
                    (float)$row['revenue_sum'],
                    $ua,
                    $ip,
                    true
                );
            }
        }
        $stmt->close();
        $db->commit();

        return null;
    }

    private static function ensureCoveringIndex(mysqli $db, string $table): ?string
    {
        $index = $table === 'clicks'
            ? 'idx_clicks_ts_stats_cover'
            : 'idx_clicks_archive_ts_stats_cover';
        // Equality first, then the reporting date range; remaining columns keep
        // dashboard/list raw aggregates index-only.
        $columns = 'exclude_from_stats, ts, campaign_id, lp_click, landing_page_id, cost';
        $expectedColumns = str_replace(' ', '', $columns);
        if (self::indexColumns($db, $table, $index) === $expectedColumns) {
            return null;
        }

        $alter = self::indexExists($db, $table, $index)
            ? "ALTER TABLE `{$table}` DROP INDEX `{$index}`, ADD INDEX `{$index}` ({$columns})"
            : "ALTER TABLE `{$table}` ADD INDEX `{$index}` ({$columns})";

        return $db->query($alter)
            ? null
            : "Could not create {$table}.{$index}: {$db->error}";
    }

    private static function tableExists(mysqli $db, string $table): bool
    {
        $escaped = $db->real_escape_string($table);
        $result = $db->query("SHOW TABLES LIKE '{$escaped}'");

        return $result !== false && $result->num_rows > 0;
    }

    private static function columnExists(mysqli $db, string $table, string $column): bool
    {
        $stmt = $db->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
             LIMIT 1'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();

        return $exists;
    }

    private static function indexExists(mysqli $db, string $table, string $index): bool
    {
        $stmt = $db->prepare(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
             LIMIT 1'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ss', $table, $index);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();

        return $exists;
    }

    private static function indexColumns(mysqli $db, string $table, string $index): string
    {
        $stmt = $db->prepare(
            "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS columns_csv
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?"
        );
        if ($stmt === false) {
            return '';
        }
        $stmt->bind_param('ss', $table, $index);
        $stmt->execute();
        $columns = (string)($stmt->get_result()->fetch_assoc()['columns_csv'] ?? '');
        $stmt->close();

        return $columns;
    }
}
