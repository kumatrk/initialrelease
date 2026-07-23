<?php

declare(strict_types=1);

/**
 * Daily data retention cleanup (CLI only).
 * Usage: php scripts/run-data-retention-cron.php
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
require_once $baseDir . '/src/DataRetention/ClickDataCleanup.php';

use SimpleKuma\DataRetention\ClickDataCleanup;

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($db->connect_error) {
    fwrite(STDERR, 'Database connection failed: ' . $db->connect_error . "\n");
    exit(1);
}

$db->query("SET time_zone = '+00:00'");

exit(ClickDataCleanup::run($db));
