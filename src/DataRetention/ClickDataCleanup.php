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
 * Deletes clicks and conversions older than log_retention_days.
 */
class ClickDataCleanup
{
    public static function run(mysqli $db): int
    {
        $settings = new SettingsManager($db);
        $retentionDays = (int) $settings->get('log_retention_days', '0');

        if ($retentionDays === 0) {
            echo "Log retention is set to 'Never delete'. Skipping cleanup.\n";
            return 0;
        }

        $archiveDays = (int) $settings->get('archive_after_days', '365');
        if ($archiveDays > 0 && $retentionDays < $archiveDays) {
            echo "Warning: log_retention_days ({$retentionDays}) is less than archive_after_days ({$archiveDays}).\n";
            echo "Data may be deleted before it can be archived. Adjust settings or run archive first.\n";
        }

        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
        echo "Starting cleanup of logs older than {$retentionDays} days (before {$cutoffDate})...\n";

        $db->begin_transaction();

        try {
            $selectStmt = $db->prepare('SELECT click_id FROM clicks WHERE ts < ?');
            $selectStmt->bind_param('s', $cutoffDate);
            $selectStmt->execute();
            $result = $selectStmt->get_result();
            $clickIds = [];
            while ($row = $result->fetch_assoc()) {
                $clickIds[] = $row['click_id'];
            }
            $selectStmt->close();

            $conversionsDeleted = 0;
            if ($clickIds !== []) {
                foreach (array_chunk($clickIds, 1000) as $chunk) {
                    $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                    $deleteConvStmt = $db->prepare("DELETE FROM conversions WHERE click_id IN ({$placeholders})");
                    $types = str_repeat('s', count($chunk));
                    $deleteConvStmt->bind_param($types, ...$chunk);
                    $deleteConvStmt->execute();
                    $conversionsDeleted += $deleteConvStmt->affected_rows;
                    $deleteConvStmt->close();
                }
                echo "Deleted {$conversionsDeleted} conversion(s).\n";
            }

            $deleteClicksStmt = $db->prepare('DELETE FROM clicks WHERE ts < ?');
            $deleteClicksStmt->bind_param('s', $cutoffDate);
            $deleteClicksStmt->execute();
            $clicksDeleted = $deleteClicksStmt->affected_rows;
            $deleteClicksStmt->close();

            $db->commit();
            echo "Cleanup completed successfully. Total deleted: {$clicksDeleted} clicks, {$conversionsDeleted} conversions.\n";

            return 0;
        } catch (\Throwable $e) {
            $db->rollback();
            error_log('ClickDataCleanup: ' . $e->getMessage());
            echo 'Error during cleanup: ' . $e->getMessage() . "\n";
            return 1;
        }
    }
}
