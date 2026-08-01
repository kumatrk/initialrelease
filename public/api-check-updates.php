<?php

declare(strict_types=1);

/**
 * Background update check (session-authenticated).
 * Used by the admin layout so page render never waits on GitHub.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/bootstrap_web_paths.php';
require_once __DIR__ . '/../src/Update/UpdateChecker.php';

use SimpleKuma\Auth\Auth;
use SimpleKuma\Update\UpdateChecker;

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow', true);
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($db->connect_error) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Database unavailable']);
    exit;
}

$auth = new Auth($db);
$auth->requireAuth();
// Do not hold the session lock while waiting on GitHub.
$auth->releaseSessionLock();

@set_time_limit(30);

$checker = new UpdateChecker($db);

if (!$checker->isUpdateCheckEnabled()) {
    echo json_encode([
        'ok' => true,
        'skipped' => true,
        'reason' => 'disabled',
        'update_available' => false,
    ]);
    exit;
}

// Fresh cache: return immediately (another tab may have just refreshed).
if ($checker->isCacheFresh()) {
    $cached = $checker->getCachedResult(false) ?? [];
    echo json_encode([
        'ok' => true,
        'from_cache' => true,
        'success' => (bool) ($cached['success'] ?? true),
        'update_available' => (bool) ($cached['update_available'] ?? false),
        'current_version' => $cached['current_version'] ?? $checker->getCurrentVersion(),
        'latest_version' => $cached['latest_version'] ?? null,
        'update_type' => $cached['update_type'] ?? null,
    ]);
    exit;
}

$result = $checker->checkForUpdates();

echo json_encode([
    'ok' => (bool) ($result['success'] ?? false),
    'from_cache' => false,
    'success' => (bool) ($result['success'] ?? false),
    'update_available' => (bool) ($result['update_available'] ?? false),
    'current_version' => $result['current_version'] ?? $checker->getCurrentVersion(),
    'latest_version' => $result['latest_version'] ?? null,
    'update_type' => $result['update_type'] ?? null,
    'message' => $result['message'] ?? null,
]);
