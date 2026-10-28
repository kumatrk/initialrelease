<?php

declare(strict_types=1);

/**
 * Grizzly Guard. Run about every 5 minutes.
 *
 * Crontab, every 5 minutes:
 *   php /path/to/simplekuma/scripts/grizzly-guard-cron.php
 *
 * Does nothing until Settings → Grizzly Guard is turned on.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

use SimpleKuma\Database\DbTimezone;
use SimpleKuma\Guard\GrizzlyGuardRunner;

try {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    if ($db->connect_error) {
        fwrite(STDERR, "Grizzly Guard: database connection failed\n");
        exit(1);
    }
    DbTimezone::init($db);
    $result = GrizzlyGuardRunner::run($db, 40);
    $db->close();
    echo 'Grizzly Guard: ' . $result['status']
        . ' checked=' . $result['checked']
        . ' actions=' . $result['actions'] . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Grizzly Guard failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
