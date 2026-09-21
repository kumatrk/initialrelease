<?php

declare(strict_types=1);

namespace SimpleKuma\DataRetention;

use mysqli;
use SimpleKuma\Settings\SettingsManager;

/**
 * Best-effort VPS host metrics (CPU load, RAM, disk) for Server Status + in-app warnings.
 * Linux: /proc + sys_getloadavg. Windows/shared hosts: disk only (CPU/RAM marked unavailable).
 * Does not change stats queries — advisory only.
 */
class HostResourceHealth
{
    public const DEFAULT_CPU_WARN_PERCENT = 85;
    public const DEFAULT_RAM_WARN_PERCENT = 90;
    public const DEFAULT_PROBE_INTERVAL_SEC = 120;

    /**
     * Full live snapshot for the Server Status page.
     *
     * @return array{
     *   probed_at: string,
     *   platform: string,
     *   disk: ?array{path: string, total_bytes: int, free_bytes: int, used_percent: float},
     *   cpu: ?array{load_1: float, load_5: float, load_15: float, cores: int, pressure_percent: float},
     *   ram: ?array{total_bytes: int, available_bytes: int, used_bytes: int, used_percent: float},
     *   php: array{memory_usage_bytes: int, memory_peak_bytes: int, memory_limit: string},
     *   notes: list<string>
     * }
     */
    public static function probe(?string $baseDir = null): array
    {
        $notes = [];
        $platform = PHP_OS_FAMILY;

        $disk = StorageHealth::probe($baseDir);
        if ($disk === null) {
            $notes[] = 'Disk usage could not be read for this path.';
        }

        $cpu = self::probeCpu();
        if ($cpu === null) {
            $notes[] = 'CPU load is unavailable on this host (common on Windows / restricted shared hosting).';
        }

        $ram = self::probeRam();
        if ($ram === null) {
            $notes[] = 'RAM usage is unavailable on this host (needs Linux /proc/meminfo).';
        }

        return [
            'probed_at' => gmdate('Y-m-d H:i:s'),
            'platform' => $platform,
            'disk' => $disk,
            'cpu' => $cpu,
            'ram' => $ram,
            'php' => [
                'memory_usage_bytes' => memory_get_usage(true),
                'memory_peak_bytes' => memory_get_peak_usage(true),
                'memory_limit' => (string) ini_get('memory_limit'),
            ],
            'notes' => $notes,
        ];
    }

    /**
     * Approximate MySQL storage for click-related tables (information_schema).
     *
     * @return list<array{table: string, bytes: int, rows: int|null}>
     */
    public static function probeClickTableSizes(mysqli $db): array
    {
        $tables = [
            'clicks',
            'clicks_archive',
            'clicks_daily_summary',
            'clicks_stats_by_token_daily',
            'conversions',
        ];
        $out = [];
        $dbName = defined('DB_NAME') ? (string) DB_NAME : '';
        if ($dbName === '') {
            return $out;
        }

        $placeholders = implode(',', array_fill(0, count($tables), '?'));
        $sql = "SELECT TABLE_NAME AS t, DATA_LENGTH + INDEX_LENGTH AS bytes, TABLE_ROWS AS approx_rows
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ($placeholders)";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return $out;
        }

        $types = 's' . str_repeat('s', count($tables));
        $params = array_merge([$dbName], $tables);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $byName = [];
        while ($row = $result->fetch_assoc()) {
            $name = (string) ($row['t'] ?? '');
            $byName[$name] = [
                'table' => $name,
                'bytes' => (int) ($row['bytes'] ?? 0),
                'rows' => isset($row['approx_rows']) ? (int) $row['approx_rows'] : null,
            ];
        }
        $stmt->close();

        foreach ($tables as $t) {
            if (isset($byName[$t])) {
                $out[] = $byName[$t];
            }
        }

        return $out;
    }

    /**
     * Persist warning flags from a probe. Returns true when any resource is over threshold.
     *
     * @param array<string, mixed>|null $snapshot
     */
    public static function evaluateAndRecord(SettingsManager $settings, ?array $snapshot = null, ?string $baseDir = null): bool
    {
        $snapshot = $snapshot ?? self::probe($baseDir);

        $cpuWarn = (int) $settings->get('cpu_warn_percent', (string) self::DEFAULT_CPU_WARN_PERCENT);
        $ramWarn = (int) $settings->get('ram_warn_percent', (string) self::DEFAULT_RAM_WARN_PERCENT);
        $cpuWarn = max(0, min(99, $cpuWarn));
        $ramWarn = max(0, min(99, $ramWarn));

        $diskOver = StorageHealth::evaluateAndRecord($settings, $snapshot['disk'] ?? null);

        $cpuOver = false;
        $cpu = $snapshot['cpu'] ?? null;
        if (is_array($cpu) && $cpuWarn > 0) {
            $pressure = (float) ($cpu['pressure_percent'] ?? 0);
            $cpuOver = $pressure >= $cpuWarn;
            $settings->set('host_cpu_percent', (string) round($pressure, 1));
            $settings->set('host_cpu_load_1', (string) round((float) ($cpu['load_1'] ?? 0), 2));
            $settings->set('host_cpu_cores', (string) (int) ($cpu['cores'] ?? 0));
        } else {
            $settings->set('host_cpu_percent', '');
        }
        $settings->set('cpu_warn_active', $cpuOver ? '1' : '0');

        $ramOver = false;
        $ram = $snapshot['ram'] ?? null;
        if (is_array($ram) && $ramWarn > 0) {
            $used = (float) ($ram['used_percent'] ?? 0);
            $ramOver = $used >= $ramWarn;
            $settings->set('host_ram_percent', (string) round($used, 1));
            $settings->set('host_ram_total_bytes', (string) (int) ($ram['total_bytes'] ?? 0));
            $settings->set('host_ram_available_bytes', (string) (int) ($ram['available_bytes'] ?? 0));
        } else {
            $settings->set('host_ram_percent', '');
        }
        $settings->set('ram_warn_active', $ramOver ? '1' : '0');

        $settings->set('host_resources_probed_at', (string) ($snapshot['probed_at'] ?? gmdate('Y-m-d H:i:s')));
        $settings->set('cpu_warn_percent', (string) $cpuWarn);
        $settings->set('ram_warn_percent', (string) $ramWarn);

        $any = $diskOver || $cpuOver || $ramOver;
        if ($any) {
            $settings->set('host_resources_warn_last_at', gmdate('Y-m-d H:i:s'));
        }

        return $any;
    }

    /**
     * Throttled probe for admin layout banners (avoids hitting /proc on every request).
     */
    public static function maybeEvaluateAndRecord(
        SettingsManager $settings,
        ?string $baseDir = null,
        int $intervalSec = self::DEFAULT_PROBE_INTERVAL_SEC
    ): bool {
        $last = (string) $settings->get('host_resources_probed_at', '');
        if ($last !== '') {
            $ts = strtotime($last . ' UTC');
            if ($ts !== false && (time() - $ts) < max(30, $intervalSec)) {
                return self::anyWarnActive($settings);
            }
        }

        return self::evaluateAndRecord($settings, null, $baseDir);
    }

    public static function anyWarnActive(SettingsManager $settings): bool
    {
        return ($settings->get('storage_warn_active', '0') === '1')
            || ($settings->get('cpu_warn_active', '0') === '1')
            || ($settings->get('ram_warn_active', '0') === '1');
    }

    /**
     * @return list<array{kind: string, label: string, detail: string}>
     */
    public static function activeWarnings(SettingsManager $settings): array
    {
        $out = [];
        if ($settings->get('cpu_warn_active', '0') === '1') {
            $pct = $settings->get('host_cpu_percent', '?');
            $load = $settings->get('host_cpu_load_1', '?');
            $cores = $settings->get('host_cpu_cores', '?');
            $out[] = [
                'kind' => 'cpu',
                'label' => 'High CPU load',
                'detail' => "Load pressure {$pct}% (1-min load {$load} on {$cores} cores). Lower traffic before the VPS stalls.",
            ];
        }
        if ($settings->get('ram_warn_active', '0') === '1') {
            $pct = $settings->get('host_ram_percent', '?');
            $out[] = [
                'kind' => 'ram',
                'label' => 'High RAM usage',
                'detail' => "Memory {$pct}% used. Reduce traffic or free memory before the host starts swapping/OOM.",
            ];
        }
        if ($settings->get('storage_warn_active', '0') === '1') {
            $pct = $settings->get('storage_used_percent', '?');
            $out[] = [
                'kind' => 'disk',
                'label' => 'Disk space low',
                'detail' => "Disk {$pct}% used. Archive/purge old raw clicks under Data Retention — report summaries stay.",
            ];
        }

        return $out;
    }

    /**
     * @return array{load_1: float, load_5: float, load_15: float, cores: int, pressure_percent: float}|null
     */
    private static function probeCpu(): ?array
    {
        if (!function_exists('sys_getloadavg')) {
            return null;
        }
        $loads = @sys_getloadavg();
        if (!is_array($loads) || count($loads) < 3) {
            return null;
        }

        $cores = self::detectCpuCores();
        if ($cores < 1) {
            $cores = 1;
        }

        $load1 = (float) $loads[0];
        $pressure = round(($load1 / $cores) * 100, 1);

        return [
            'load_1' => round($load1, 2),
            'load_5' => round((float) $loads[1], 2),
            'load_15' => round((float) $loads[2], 2),
            'cores' => $cores,
            'pressure_percent' => $pressure,
        ];
    }

    private static function detectCpuCores(): int
    {
        if (is_readable('/proc/cpuinfo')) {
            $raw = @file_get_contents('/proc/cpuinfo');
            if (is_string($raw) && $raw !== '') {
                $n = preg_match_all('/^processor\s*:/m', $raw);
                if (is_int($n) && $n > 0) {
                    return $n;
                }
            }
        }

        $nproc = getenv('NUMBER_OF_PROCESSORS');
        if ($nproc !== false && is_numeric($nproc) && (int) $nproc > 0) {
            return (int) $nproc;
        }

        return 1;
    }

    /**
     * @return array{total_bytes: int, available_bytes: int, used_bytes: int, used_percent: float}|null
     */
    private static function probeRam(): ?array
    {
        if (!is_readable('/proc/meminfo')) {
            return null;
        }
        $raw = @file_get_contents('/proc/meminfo');
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $kb = [];
        foreach (explode("\n", $raw) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s+kB/i', $line, $m)) {
                $kb[$m[1]] = (int) $m[2];
            }
        }

        $totalKb = $kb['MemTotal'] ?? 0;
        if ($totalKb <= 0) {
            return null;
        }

        // Prefer MemAvailable (accounts for reclaimable cache)
        $availableKb = $kb['MemAvailable'] ?? null;
        if ($availableKb === null) {
            $availableKb = ($kb['MemFree'] ?? 0) + ($kb['Buffers'] ?? 0) + ($kb['Cached'] ?? 0);
        }

        $total = $totalKb * 1024;
        $available = max(0, $availableKb) * 1024;
        $used = max(0, $total - $available);
        $usedPercent = round(($used / $total) * 100, 1);

        return [
            'total_bytes' => $total,
            'available_bytes' => $available,
            'used_bytes' => $used,
            'used_percent' => $usedPercent,
        ];
    }
}
