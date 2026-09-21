<?php

declare(strict_types=1);

namespace SimpleKuma\DataRetention;

use SimpleKuma\Settings\SettingsManager;

/**
 * Disk usage probe for retention cron / admin warning banner.
 * Does not change stats queries — advisory only unless cron acts on archive settings.
 */
class StorageHealth
{
    /**
     * @return array{path: string, total_bytes: int, free_bytes: int, used_percent: float}|null
     */
    public static function probe(?string $path = null): ?array
    {
        $path = $path ?? dirname(__DIR__, 2);
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        if ($total === false || $free === false || $total <= 0) {
            return null;
        }

        $used = $total - $free;
        $usedPercent = round(($used / $total) * 100, 1);

        return [
            'path' => $path,
            'total_bytes' => (int) $total,
            'free_bytes' => (int) $free,
            'used_percent' => $usedPercent,
        ];
    }

    /**
     * Persist last warning snapshot for Settings UI. Returns true when over threshold.
     */
    public static function evaluateAndRecord(SettingsManager $settings, ?array $probe = null): bool
    {
        $warnPercent = (int) $settings->get('storage_warn_percent', '90');
        if ($warnPercent <= 0) {
            $settings->set('storage_warn_active', '0');
            return false;
        }

        $probe = $probe ?? self::probe();
        if ($probe === null) {
            return false;
        }

        $over = $probe['used_percent'] >= $warnPercent;
        $settings->set('storage_warn_percent', (string) $warnPercent);
        $settings->set('storage_used_percent', (string) $probe['used_percent']);
        $settings->set('storage_warn_active', $over ? '1' : '0');
        if ($over) {
            $settings->set('storage_warn_last_at', gmdate('Y-m-d H:i:s'));
        }

        return $over;
    }

    public static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $n = (float) max(0, $bytes);
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }

        return round($n, 1) . ' ' . $units[$i];
    }
}
