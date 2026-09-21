<?php

declare(strict_types=1);

namespace SimpleKuma\Support;

/**
 * Gate verbose diagnostics behind APP_DEBUG so production hot paths
 * (redirects, cost aggregation) do not flood error.log.
 */
trait AppDebugLog
{
    private function debugLog(string $message): void
    {
        if (defined('APP_DEBUG') && APP_DEBUG) {
            error_log($message);
        }
    }

    /**
     * Emit an operational warning at most once per PHP process (e.g. GeoIP init).
     */
    private static function logOnce(string $key, string $message): void
    {
        static $seen = [];
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        error_log($message);
    }
}
