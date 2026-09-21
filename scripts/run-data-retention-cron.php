<?php

declare(strict_types=1);

/**
 * Daily data lifecycle (CLI only):
 *   1) Disk usage check / warning
 *   2) Sync clicks_archive columns + clicks_unified
 *   3) Archive old hot clicks (archive_after_days)
 *   4) Purge raw clicks+archive+conversions by age (log_retention_days) — keeps summaries
 *
 * Usage: php scripts/run-data-retention-cron.php
 *
 * Same steps as Settings → Privacy → “Run archive & retention now”.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

$baseDir = dirname(__DIR__);

if (!file_exists($baseDir . '/config/config.php')) {
    fwrite(STDERR, "config/config.php not found. Run the installer first.\n");
    exit(1);
}

require_once $baseDir . '/vendor/autoload.php';
require_once $baseDir . '/config/config.php';

use SimpleKuma\DataRetention\RetentionLifecycleRunner;

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($db->connect_error) {
    fwrite(STDERR, 'Database connection failed: ' . $db->connect_error . "\n");
    exit(1);
}

$db->query("SET time_zone = '+00:00'");

$result = RetentionLifecycleRunner::run($db, $baseDir);
if ($result['log'] !== '') {
    echo $result['log'] . "\n";
}

exit($result['exit_code']);
