<?php
/**
 * Honeycomb traffic-source cron (fallback)
 *
 * Prefer scripts/kuma-traffic-api-cron.php (Facebook + Google + Honeycomb).
 * This script still works: it runs Honeycomb traffic jobs at most once per UTC hour,
 * the same lock used by the combined cron and by FB/Google hitch-hiking.
 *
 * Cron (hourly, fallback):
 *   0 * * * * /usr/bin/php /path/to/simplekuma/scripts/honeycomb-cron.php >> /path/to/simplekuma/storage/logs/honeycomb-cron.log 2>&1
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

use SimpleKuma\Cron\HoneycombTrafficHourly;
use SimpleKuma\Logger;

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

try {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    if ($db->connect_error) {
        fwrite(STDERR, 'Honeycomb cron: database connection failed: ' . $db->connect_error . PHP_EOL);
        exit(1);
    }
    $db->query("SET time_zone = '+00:00'");
    date_default_timezone_set('UTC');

    $logger = new Logger();
    $logger->logDetail('=== Honeycomb cron started ===', [
        'date' => date('Y-m-d H:i:s'),
    ]);

    $result = HoneycombTrafficHourly::runIfDue($db, $logger);

    foreach ($result['messages'] as $message) {
        echo $message . PHP_EOL;
    }
    $logger->logDetail('=== Honeycomb cron finished ===', $result);

    exit($result['failed'] > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Honeycomb cron fatal: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
