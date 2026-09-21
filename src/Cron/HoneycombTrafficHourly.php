<?php

declare(strict_types=1);

namespace SimpleKuma\Cron;

use mysqli;
use SimpleKuma\Honeycomb\AddonLoader;
use SimpleKuma\Honeycomb\CronDispatcher;
use SimpleKuma\Logger;
use Throwable;

/**
 * Honeycomb traffic-source cost jobs, at most once per UTC hour.
 */
final class HoneycombTrafficHourly
{
    /**
     * @return array{ran: bool, skipped: bool, jobs: int, ok: int, failed: int, messages: list<string>}
     */
    public static function runIfDue(mysqli $db, ?Logger $logger = null): array
    {
        $logger = $logger ?? new Logger();
        if (!HourlyJobGate::claim('honeycomb_traffic')) {
            $logger->logDetail('Honeycomb traffic jobs skipped: already ran this UTC hour');
            return [
                'ran' => false,
                'skipped' => true,
                'jobs' => 0,
                'ok' => 0,
                'failed' => 0,
                'messages' => ['Honeycomb traffic jobs already ran this UTC hour.'],
            ];
        }

        try {
            $result = (new CronDispatcher(new AddonLoader($db), $logger))->run();
        } catch (Throwable $e) {
            $logger->logDetail('Honeycomb traffic hourly failed', ['error' => $e->getMessage()]);
            return [
                'ran' => true,
                'skipped' => false,
                'jobs' => 0,
                'ok' => 0,
                'failed' => 1,
                'messages' => [$e->getMessage()],
            ];
        }

        return [
            'ran' => true,
            'skipped' => false,
            'jobs' => $result['jobs'],
            'ok' => $result['ok'],
            'failed' => $result['failed'],
            'messages' => $result['messages'],
        ];
    }
}
