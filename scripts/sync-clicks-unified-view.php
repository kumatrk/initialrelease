<?php
/**
 * Rebuild clicks_unified and align clicks_archive columns with clicks.
 * Run after migrations 034–053, or before relying on archived data in stats.
 *
 * CLI: php scripts/sync-clicks-unified-view.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Database/ClicksUnifiedViewSync.php';

use SimpleKuma\Database\ClicksUnifiedViewSync;

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($db->connect_error) {
    fwrite(STDERR, "Database connection failed: {$db->connect_error}\n");
    exit(1);
}

$result = ClicksUnifiedViewSync::sync($db);
foreach ($result['messages'] as $message) {
    echo $message . "\n";
}

$db->close();
exit($result['success'] ? 0 : 1);
