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

        $host = HostResourceHealth::probe($baseDir);
        $disk = $host['disk'] ?? null;
        if (is_array($disk)) {
            echo sprintf(
                "Disk: %s used (%s free of %s) at %s\n",
                $disk['used_percent'] . '%',
                StorageHealth::formatBytes($disk['free_bytes']),
                StorageHealth::formatBytes($disk['total_bytes']),
                $disk['path']
            );
        } else {
            echo "Disk probe unavailable; continuing retention steps.\n";
        }

        if (is_array($host['cpu'] ?? null)) {
            $cpu = $host['cpu'];
            echo sprintf(
                "CPU: load %s / %s / %s (%d cores, pressure %s%%)\n",
                $cpu['load_1'],
                $cpu['load_5'],
                $cpu['load_15'],
                $cpu['cores'],
                $cpu['pressure_percent']
            );
        }
        if (is_array($host['ram'] ?? null)) {
            $ram = $host['ram'];
            echo sprintf(
                "RAM: %s%% used (%s available of %s)\n",
                $ram['used_percent'],
                StorageHealth::formatBytes($ram['available_bytes']),
                StorageHealth::formatBytes($ram['total_bytes'])
            );
        }

        $over = HostResourceHealth::evaluateAndRecord($settings, $host, $baseDir);
        if ($over) {
            foreach (HostResourceHealth::activeWarnings($settings) as $w) {
                echo 'WARNING: ' . $w['label'] . ' — ' . $w['detail'] . "\n";
            }

            $diskOver = ($settings->get('storage_warn_active', '0') === '1');
            if ($diskOver) {
                $archiveDays = (int) $settings->get('archive_after_days', '365');
                if ($archiveDays === 0) {
                    $emergencyDays = (int) $settings->get('storage_emergency_archive_days', '90');
                    if ($emergencyDays > 0) {
                        echo "Emergency archive: archive_after_days is 0; moving clicks older than {$emergencyDays} days.\n";
                        $exitCode = max($exitCode, ClickDataArchiver::run($db, $emergencyDays));
                    }
                }
            }
        } else {
            echo "Host resources under warning thresholds.\n";
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
