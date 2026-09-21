<?php

declare(strict_types=1);

namespace SimpleKuma\Cron;

use mysqli;
use SimpleKuma\Logger;
use Throwable;

/**
 * Shared boot for Facebook / Google cost crons: once-per-UTC-hour gate + Honeycomb hitch.
 */
final class TrafficApiCronHooks
{
    public static function boot(mysqli $db, Logger $logger, string $jobKey): bool
    {
        global $argv;
        $fromCombined = is_array($argv) && in_array('--from-combined-cron', $argv, true);
        if (!$fromCombined) {
            self::attachHoneycombHitch($db, $logger);
        }

        if (!HourlyJobGate::claim($jobKey)) {
            $logger->logDetail('Traffic cost updater skipped: already ran this UTC hour', [
                'job' => $jobKey,
            ]);
            return false;
        }

        return true;
    }

    public static function attachHoneycombHitch(mysqli $db, Logger $logger): void
    {
        register_shutdown_function(static function () use ($db, $logger): void {
            try {
                HoneycombTrafficHourly::runIfDue($db, $logger);
            } catch (Throwable $e) {
                error_log('Honeycomb hitch after traffic cost cron: ' . $e->getMessage());
            }
        });
    }
}
