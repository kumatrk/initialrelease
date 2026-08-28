<?php

declare(strict_types=1);

namespace SimpleKuma\DataRetention;

use mysqli;
use SimpleKuma\Database\ClicksUnifiedViewSync;
use SimpleKuma\Settings\SettingsManager;

/**
 * Moves old rows from hot clicks into clicks_archive (same DB).
 * Does not touch clicks_daily_summary / token daily — Hermes pre-agg stays intact.
 */
class ClickDataArchiver
{
    private const BATCH_SIZE = 10000;

    /**
     * @param int|null $archiveDaysOverride When set (e.g. emergency disk run), use instead of settings
     */
    public static function run(mysqli $db, ?int $archiveDaysOverride = null): int
    {
        $settings = new SettingsManager($db);
        $archiveDays = $archiveDaysOverride ?? (int) $settings->get('archive_after_days', '365');

        if ($archiveDays <= 0) {
            echo "Archiving is disabled (archive_after_days=0). Skipping.\n";
            return 0;
        }

        $tableCheck = $db->query("SHOW TABLES LIKE 'clicks_archive'");
        if (!$tableCheck || $tableCheck->num_rows === 0) {
            echo "clicks_archive missing — run migration 034. Skipping archive.\n";
            return 1;
        }

        $sync = ClicksUnifiedViewSync::sync($db);
        foreach ($sync['messages'] as $message) {
            echo $message . "\n";
        }
        if (!$sync['success']) {
            echo "Archive aborted: could not sync clicks_archive / clicks_unified.\n";
            return 1;
        }

        $insertCols = ClicksUnifiedViewSync::getInsertableSharedColumns($db);
        if ($insertCols === []) {
            echo "No insertable shared columns between clicks and clicks_archive.\n";
            return 1;
        }

        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$archiveDays} days"));
        echo "Archiving clicks older than {$archiveDays} days (before {$cutoffDate})...\n";

        $colList = implode(', ', array_map(static fn (string $c) => "`{$c}`", $insertCols));
        $lastId = 0;
        $archived = 0;

        try {
            while (true) {
                $selectStmt = $db->prepare(
                    'SELECT id FROM clicks WHERE ts < ? AND id > ? ORDER BY id ASC LIMIT ?'
                );
                $batchSize = self::BATCH_SIZE;
                $selectStmt->bind_param('sii', $cutoffDate, $lastId, $batchSize);
                $selectStmt->execute();
                $result = $selectStmt->get_result();
                $ids = [];
                while ($row = $result->fetch_assoc()) {
                    $ids[] = (int) $row['id'];
                }
                $selectStmt->close();

                if ($ids === []) {
                    break;
                }

                $lastId = $ids[count($ids) - 1];
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $types = str_repeat('i', count($ids));

                $db->begin_transaction();

                $insertSql = "INSERT INTO clicks_archive ({$colList})
                    SELECT {$colList} FROM clicks WHERE id IN ({$placeholders})";
                $insertStmt = $db->prepare($insertSql);
                if (!$insertStmt) {
                    throw new \RuntimeException('Archive INSERT prepare failed: ' . $db->error);
                }
                $insertStmt->bind_param($types, ...$ids);
                if (!$insertStmt->execute()) {
                    $err = $insertStmt->error;
                    $insertStmt->close();
                    // Duplicate primary key: row already archived; still remove from hot table
                    if (!str_contains(strtolower($err), 'duplicate')) {
                        throw new \RuntimeException('Archive INSERT failed: ' . $err);
                    }
                } else {
                    $insertStmt->close();
                }

                $deleteStmt = $db->prepare("DELETE FROM clicks WHERE id IN ({$placeholders})");
                $deleteStmt->bind_param($types, ...$ids);
                $deleteStmt->execute();
                $deleted = $deleteStmt->affected_rows;
                $deleteStmt->close();

                $db->commit();

                $archived += $deleted;
                echo "Archived batch: {$deleted} clicks (total {$archived}, last id {$lastId})\n";
            }
        } catch (\Throwable $e) {
            try {
                $db->rollback();
            } catch (\Throwable $ignored) {
            }
            error_log('ClickDataArchiver: ' . $e->getMessage());
            echo 'Error during archiving: ' . $e->getMessage() . "\n";
            return 1;
        }

        if ($archived === 0) {
            echo "No clicks to archive.\n";
        } else {
            echo "Archive completed. Total archived: {$archived} clicks.\n";
            echo "Summaries unchanged; campaign KPI fast path still uses clicks_daily_summary.\n";
        }

        return 0;
    }
}
