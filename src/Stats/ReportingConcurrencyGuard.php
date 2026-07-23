<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

/**
 * Limits concurrent heavy reporting work so rapid admin navigation cannot
 * stack abandoned MySQL queries and starve a shared host (Cloudflare 522s).
 *
 * Uses non-blocking flock slots under storage/cache.
 */
final class ReportingConcurrencyGuard
{
    /** Keep low on shared hosting — each slot may hold a long SELECT. */
    public const MAX_SLOTS = 2;

    /**
     * @return resource|null Open lock handle to pass to release(), or null if busy
     */
    public static function tryAcquire(): mixed
    {
        $dir = self::lockDir();
        if ($dir === null) {
            return true; // no lock dir — do not block reporting entirely
        }

        for ($i = 0; $i < self::MAX_SLOTS; $i++) {
            $path = $dir . '/sk_report_slot_' . $i . '.lock';
            $fp = @fopen($path, 'c+');
            if ($fp === false) {
                continue;
            }
            if (@flock($fp, LOCK_EX | LOCK_NB)) {
                return $fp;
            }
            fclose($fp);
        }

        return null;
    }

    /**
     * @param resource|true|null $handle
     */
    public static function release(mixed $handle): void
    {
        if ($handle === true || $handle === null) {
            return;
        }
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    private static function lockDir(): ?string
    {
        static $dir = false;
        if ($dir !== false) {
            return $dir;
        }

        $candidates = [
            dirname(__DIR__, 2) . '/storage/cache',
            sys_get_temp_dir() . '/simplekuma-report-locks',
        ];
        foreach ($candidates as $candidate) {
            if (!is_dir($candidate)) {
                @mkdir($candidate, 0755, true);
            }
            if (is_dir($candidate) && is_writable($candidate)) {
                $dir = $candidate;

                return $dir;
            }
        }
        $dir = null;

        return null;
    }

    /**
     * Run heavy reporting work under a concurrency slot, or throw ReportingBusyException.
     *
     * @template T
     * @param callable(): T $producer
     * @return T
     */
    public static function run(callable $producer): mixed
    {
        $slot = self::tryAcquire();
        if ($slot === null) {
            throw new ReportingBusyException();
        }
        try {
            return $producer();
        } finally {
            self::release($slot);
        }
    }
}
