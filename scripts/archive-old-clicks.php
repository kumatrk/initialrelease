<?php

declare(strict_types=1);

/**
 * Archive old clicks into clicks_archive (same DB). Keeps hot clicks small.
 * Summaries are not modified — Hermes pre-agg reporting stays intact.
 *
 * Cron (standalone):
 *   0 3 * * * php /path/to/simplekuma/scripts/archive-old-clicks.php
 *
 * Prefer scripts/run-data-retention-cron.php (sync → archive → purge → disk check).
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

use SimpleKuma\DataRetention\ClickDataArchiver;

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($db->connect_error) {
    fwrite(STDERR, 'Database connection failed: ' . $db->connect_error . "\n");
    exit(1);
}

$db->query("SET time_zone = '+00:00'");

exit(ClickDataArchiver::run($db));
