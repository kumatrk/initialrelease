<?php

declare(strict_types=1);

namespace SimpleKuma\DataRetention;

use mysqli;
use SimpleKuma\Settings\SettingsManager;

/**
 * Shared archive → purge → disk check used by CLI cron and Settings “Run now”.
 * Does not touch clicks_daily_summary / token daily (Hermes pre-agg safe).
 */
class RetentionLifecycleRunner
{
    /**
     * @return array{ok: bool, exit_code: int, log: string}
     */
    public static function run(mysqli $db, ?string $baseDir = null): array
    {
        $baseDir = $baseDir ?? dirname(__DIR__, 2);
        $settings = new SettingsManager($db);
        $exitCode = 0;

        ob_start();

        $probe = StorageHealth::probe($baseDir);
        if ($probe !== null) {
            echo sprintf(
                "Disk: %s used (%s free of %s) at %s\n",
                $probe['used_percent'] . '%',
                StorageHealth::formatBytes($probe['free_bytes']),
                StorageHealth::formatBytes($probe['total_bytes']),
                $probe['path']
            );
            $over = StorageHealth::evaluateAndRecord($settings, $probe);
            if ($over) {
                $warnAt = (int) $settings->get('storage_warn_percent', '90');
                echo "WARNING: disk usage >= {$warnAt}% — archive/purge settings below free space; summaries stay for KPIs.\n";

                $archiveDays = (int) $settings->get('archive_after_days', '365');
                if ($archiveDays === 0) {
                    $emergencyDays = (int) $settings->get('storage_emergency_archive_days', '90');
                    if ($emergencyDays > 0) {
                        echo "Emergency archive: archive_after_days is 0; moving clicks older than {$emergencyDays} days.\n";
                        $exitCode = max($exitCode, ClickDataArchiver::run($db, $emergencyDays));
                    }
                }
            } else {
                echo "Disk usage under warning threshold.\n";
            }
        } else {
            echo "Disk probe unavailable; continuing retention steps.\n";
        }

        $archiveDays = (int) $settings->get('archive_after_days', '365');
        if ($archiveDays > 0) {
            $exitCode = max($exitCode, ClickDataArchiver::run($db));
        } else {
            echo "archive_after_days=0 — skipping scheduled archive (unless emergency already ran).\n";
        }

        $exitCode = max($exitCode, ClickDataCleanup::run($db));

        $log = (string) ob_get_clean();

        return [
            'ok' => $exitCode === 0,
            'exit_code' => $exitCode,
            'log' => trim($log),
        ];
    }
}
