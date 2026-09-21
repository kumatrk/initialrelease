<?php
/**
 * Combined hourly traffic API cron
 *
 * Runs Facebook cost, Google Ads cost, and Honeycomb traffic-source addons.
 * Safe alongside legacy fb_cost_updater.php / google_ads_cost_updater.php:
 * each job runs at most once per UTC hour.
 *
 * Recommended crontab (hourly):
 *   0 * * * * /usr/bin/php /path/to/simplekuma/scripts/kuma-traffic-api-cron.php >> /path/to/simplekuma/storage/logs/kuma-traffic-api-cron.log 2>&1
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

use SimpleKuma\Cron\TrafficApiCronRunner;
use SimpleKuma\Logger;

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

try {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    if ($db->connect_error) {
        fwrite(STDERR, 'Traffic API cron: database connection failed: ' . $db->connect_error . PHP_EOL);
        exit(1);
    }
    $db->query("SET time_zone = '+00:00'");
    date_default_timezone_set('UTC');

    $logger = new Logger();
    $runner = new TrafficApiCronRunner($db, $logger);
    $result = $runner->run();

    echo 'Facebook cost exit: ' . (int) ($result['fb']['code'] ?? 1) . PHP_EOL;
    echo 'Google Ads cost exit: ' . (int) ($result['google']['code'] ?? 1) . PHP_EOL;
    foreach (['fb', 'google'] as $child) {
        $out = (string) ($result[$child]['output'] ?? '');
        if ($out !== '' && str_contains($out, 'Skipped spawn')) {
            echo $out . PHP_EOL;
            break;
        }
    }
    foreach ($result['honeycomb']['messages'] ?? [] as $message) {
        echo $message . PHP_EOL;
    }

    $failed = ((int) ($result['fb']['code'] ?? 1) !== 0)
        || ((int) ($result['google']['code'] ?? 1) !== 0)
        || ((int) ($result['honeycomb']['failed'] ?? 0) > 0);

    exit($failed ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Traffic API cron fatal: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
