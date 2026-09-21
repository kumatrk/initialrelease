<?php

declare(strict_types=1);

namespace SimpleKuma\Cron;

use mysqli;
use SimpleKuma\Logger;

/**
 * Combined hourly traffic API runner: Facebook cost, Google cost, Honeycomb traffic addons.
 * Legacy fb_cost_updater.php / google_ads_cost_updater.php stay valid; HourlyJobGate prevents doubles.
 */
final class TrafficApiCronRunner
{
    public function __construct(
        private mysqli $db,
        private ?Logger $logger = null
    ) {
        $this->logger = $logger ?? new Logger();
    }

    /**
     * @return array{fb: array{code: int, skipped_spawn?: bool}, google: array{code: int}, honeycomb: array<string, mixed>}
     */
    public function run(): array
    {
        $this->logger->logDetail('=== Traffic API cron started ===', [
            'utc_hour' => gmdate('Y-m-d H:00'),
        ]);

        $fb = $this->runCliScript('fb_cost_updater.php');
        $google = $this->runCliScript('google_ads_cost_updater.php');
        $honeycomb = HoneycombTrafficHourly::runIfDue($this->db, $this->logger);

        $this->logger->logDetail('=== Traffic API cron finished ===', [
            'fb_exit' => $fb['code'],
            'google_exit' => $google['code'],
            'honeycomb' => $honeycomb,
        ]);

        return [
            'fb' => $fb,
            'google' => $google,
            'honeycomb' => $honeycomb,
        ];
    }

    /**
     * @return array{code: int, output: string}
     */
    private function runCliScript(string $scriptName): array
    {
        if (!$this->canSpawn()) {
            $this->logger->logDetail('Traffic API cron cannot spawn children (exec disabled); keep legacy FB/Google crontab lines', [
                'script' => $scriptName,
            ]);
            return [
                'code' => 0,
                'output' => 'Skipped spawn: exec() is disabled. Keep fb_cost_updater.php / google_ads_cost_updater.php crontab lines.',
            ];
        }

        $script = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . $scriptName;
        if (!is_file($script)) {
            $this->logger->logDetail('Traffic API cron missing script', ['script' => $scriptName]);
            return ['code' => 1, 'output' => 'Script not found: ' . $scriptName];
        }

        $cmd = escapeshellarg($this->phpBinary()) . ' ' . escapeshellarg($script) . ' --from-combined-cron';
        $output = [];
        $code = 0;
        exec($cmd . ' 2>&1', $output, $code);
        $text = implode("\n", $output);
        $this->logger->logDetail('Traffic API cron child finished', [
            'script' => $scriptName,
            'exit' => $code,
        ]);

        return ['code' => (int) $code, 'output' => $text];
    }

    private function phpBinary(): string
    {
        $bin = PHP_BINARY;
        $lower = strtolower($bin);
        if ($bin !== '' && is_file($bin) && !str_contains($lower, 'cgi')) {
            return $bin;
        }

        return 'php';
    }

    private function canSpawn(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return !in_array('exec', $disabled, true);
    }
}
