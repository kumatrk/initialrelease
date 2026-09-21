<?php

declare(strict_types=1);

namespace SimpleKuma\Cron;

/**
 * Ensures a named traffic-API job runs at most once per UTC hour,
 * even when both the new combined cron and legacy FB/Google crontabs fire.
 */
final class HourlyJobGate
{
    public static function claim(string $jobKey): bool
    {
        $safe = preg_replace('/[^a-z0-9_-]+/i', '', $jobKey) ?: 'job';
        $hour = gmdate('YmdH');
        $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'cron-locks';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return true;
        }
        self::pruneOldLocks($dir);

        $path = $dir . DIRECTORY_SEPARATOR . $safe . '-' . $hour . '.lock';
        $fh = fopen($path, 'c+');
        if ($fh === false) {
            return true;
        }
        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            return true;
        }
        rewind($fh);
        $existing = trim((string) stream_get_contents($fh));
        if ($existing === '1') {
            flock($fh, LOCK_UN);
            fclose($fh);
            return false;
        }
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, '1');
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);

        return true;
    }

    private static function pruneOldLocks(string $dir): void
    {
        $cutoff = time() - 172800;
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.lock') ?: [];
        foreach ($files as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($file);
            }
        }
    }
}
