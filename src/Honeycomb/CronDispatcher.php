<?php

declare(strict_types=1);

namespace SimpleKuma\Honeycomb;

use SimpleKuma\Logger;
use Throwable;

/**
 * Runs CostSyncJob implementations from enabled addons. One crontab for all Honeycomb jobs.
 */
final class CronDispatcher
{
    public function __construct(
        private AddonLoader $loader,
        private ?Logger $logger = null
    ) {
        $this->logger = $logger ?? new Logger();
    }

    /**
     * @return array{jobs: int, ok: int, failed: int, messages: list<string>}
     */
    public function run(): array
    {
        $kernel = $this->loader->kernel();
        $jobs = $kernel->costSyncJobs();
        $messages = [];
        $ok = 0;
        $failed = 0;

        if ($jobs === []) {
            $messages[] = 'No Honeycomb cost-sync jobs registered (no addons with cost_sync enabled).';
            $this->logger->logDetail('Honeycomb cron: no jobs', []);
            return ['jobs' => 0, 'ok' => 0, 'failed' => 0, 'messages' => $messages];
        }

        foreach ($jobs as $job) {
            try {
                $result = $job->run();
                $line = $job->slug() . ': ' . ($result['message'] ?? ($result['ok'] ? 'ok' : 'failed'));
                $messages[] = $line;
                if (!empty($result['ok'])) {
                    $ok++;
                } else {
                    $failed++;
                }
                $this->logger->logDetail('Honeycomb job ' . $job->slug(), $result);
            } catch (Throwable $e) {
                $failed++;
                $messages[] = $job->slug() . ': ' . $e->getMessage();
                $this->logger->logDetail('Honeycomb job exception', [
                    'slug' => $job->slug(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'jobs' => count($jobs),
            'ok' => $ok,
            'failed' => $failed,
            'messages' => $messages,
        ];
    }
}
