<?php

declare(strict_types=1);

namespace SimpleKuma\DataRetention;

use mysqli;
use SimpleKuma\Settings\SettingsManager;

// Allow CLI without Composer autoload
if (!class_exists(SettingsManager::class)) {
    require_once dirname(__DIR__) . '/Settings/SettingsManager.php';
}

/**
 * Age-based purge of raw click rows (hot + archive) and their conversions.
 *
 * Intentionally does NOT touch clicks_daily_summary or clicks_stats_by_token_daily.
 * Hermes campaign KPI / chart / offer / LP / token reports stay on the pre-agg fast path
 * after raw data is removed. Geo/device/visitor-log drill-downs for purged days will be empty.
 */
class ClickDataCleanup
{
    private const BATCH_SIZE = 5000;

    public static function run(mysqli $db): int
    {
        $settings = new SettingsManager($db);
        $retentionDays = (int) $settings->get('log_retention_days', '0');

        if ($retentionDays === 0) {
            echo "Log retention is set to 'Never delete'. Skipping raw-click purge (summaries untouched).\n";
            return 0;
        }

        $archiveDays = (int) $settings->get('archive_after_days', '365');
        if ($archiveDays > 0 && $retentionDays < $archiveDays) {
            echo "Warning: log_retention_days ({$retentionDays}) is less than archive_after_days ({$archiveDays}).\n";
            echo "Prefer archive_after_days <= log_retention_days so hot→archive→delete is ordered.\n";
        }

        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
        echo "Purging raw clicks/conversions older than {$retentionDays} days (before {$cutoffDate})...\n";
        echo "Keeping clicks_daily_summary / clicks_stats_by_token_daily for historical KPI reports.\n";

        $hasArchive = false;
        $check = $db->query("SHOW TABLES LIKE 'clicks_archive'");
        if ($check && $check->num_rows > 0) {
            $hasArchive = true;
        }

        try {
            $fromClicks = self::purgeTableByAge($db, 'clicks', $cutoffDate);
            $fromArchive = $hasArchive
                ? self::purgeTableByAge($db, 'clicks_archive', $cutoffDate)
                : ['clicks' => 0, 'conversions' => 0];

            $clicksDeleted = $fromClicks['clicks'] + $fromArchive['clicks'];
            $conversionsDeleted = $fromClicks['conversions'] + $fromArchive['conversions'];

            echo "Purge completed. Deleted {$clicksDeleted} raw click row(s)"
                . " ({$fromClicks['clicks']} hot, {$fromArchive['clicks']} archive),"
                . " {$conversionsDeleted} conversion(s).\n";
            echo "Pre-aggregate summary tables were not modified.\n";

            return 0;
        } catch (\Throwable $e) {
            error_log('ClickDataCleanup: ' . $e->getMessage());
            echo 'Error during cleanup: ' . $e->getMessage() . "\n";
            return 1;
        }
    }

    /**
     * @return array{clicks: int, conversions: int}
     */
    private static function purgeTableByAge(mysqli $db, string $table, string $cutoffDate): array
    {
        $clicksDeleted = 0;
        $conversionsDeleted = 0;
        $lastId = 0;

        while (true) {
            $selectStmt = $db->prepare(
                "SELECT id, click_id FROM `{$table}` WHERE ts < ? AND id > ? ORDER BY id ASC LIMIT ?"
            );
            $batchSize = self::BATCH_SIZE;
            $selectStmt->bind_param('sii', $cutoffDate, $lastId, $batchSize);
            $selectStmt->execute();
            $result = $selectStmt->get_result();
            $ids = [];
            $clickIds = [];
            while ($row = $result->fetch_assoc()) {
                $ids[] = (int) $row['id'];
                $clickIds[] = (string) $row['click_id'];
                $lastId = (int) $row['id'];
            }
            $selectStmt->close();

            if ($ids === []) {
                break;
            }

            $db->begin_transaction();

            foreach (array_chunk($clickIds, 1000) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $deleteConvStmt = $db->prepare(
                    "DELETE FROM conversions WHERE click_id IN ({$placeholders})"
                );
                $types = str_repeat('s', count($chunk));
                $deleteConvStmt->bind_param($types, ...$chunk);
                $deleteConvStmt->execute();
                $conversionsDeleted += $deleteConvStmt->affected_rows;
                $deleteConvStmt->close();
            }

            $idPlaceholders = implode(',', array_fill(0, count($ids), '?'));
            $deleteClicksStmt = $db->prepare(
                "DELETE FROM `{$table}` WHERE id IN ({$idPlaceholders})"
            );
            $idTypes = str_repeat('i', count($ids));
            $deleteClicksStmt->bind_param($idTypes, ...$ids);
            $deleteClicksStmt->execute();
            $clicksDeleted += $deleteClicksStmt->affected_rows;
            $deleteClicksStmt->close();

            $db->commit();

            echo "  {$table}: purged batch of " . count($ids) . " (last id {$lastId})\n";
        }

        return ['clicks' => $clicksDeleted, 'conversions' => $conversionsDeleted];
    }
}
