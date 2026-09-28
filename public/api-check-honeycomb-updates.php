<?php

declare(strict_types=1);

/**
 * Background Honeycomb addon update check (session-authenticated).
 * Used by the admin layout so page render never waits on GitHub.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/bootstrap_web_paths.php';

use SimpleKuma\Auth\Auth;
use SimpleKuma\Honeycomb\AddonUpdateChecker;
use SimpleKuma\Settings\SettingsManager;

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
$permission = $auth->getPermission();
$legacyNoRoles = empty($_SESSION['role_ids'] ?? [])
    && Auth::allowsLegacyNoRolesFallback();
$canCheck = \SimpleKuma\Auth\SingleAdminMode::isEnabled()
    || $legacyNoRoles
    || ($permission && (
        $permission->hasPermission(\SimpleKuma\Auth\Permission::PERM_SETTINGS_VIEW)
        || $permission->hasPermission(\SimpleKuma\Auth\Permission::PERM_SETTINGS_EDIT)
        || $permission->hasPermission(\SimpleKuma\Auth\Permission::PERM_UPDATE_MANAGE)
    ));
if (!$canCheck) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}
// Do not hold the session lock while waiting on GitHub.
$auth->releaseSessionLock();

@set_time_limit(30);

$settings = new SettingsManager($db);
$checker = new AddonUpdateChecker($db, $settings);

if ($checker->isCacheFresh()) {
    $cached = $checker->getCachedResult(false) ?? [];
    echo json_encode([
        'ok' => true,
        'from_cache' => true,
        'success' => (bool) ($cached['success'] ?? true),
        'outdated_count' => (int) ($cached['outdated_count'] ?? 0),
        'fingerprint' => (string) ($cached['fingerprint'] ?? ''),
        'outdated' => $cached['outdated'] ?? [],
    ]);
    exit;
}

$result = $checker->checkForUpdates(true);

echo json_encode([
    'ok' => (bool) ($result['success'] ?? false),
    'from_cache' => false,
    'success' => (bool) ($result['success'] ?? false),
    'outdated_count' => (int) ($result['outdated_count'] ?? 0),
    'fingerprint' => (string) ($result['fingerprint'] ?? ''),
    'outdated' => $result['outdated'] ?? [],
    'message' => $result['error'] ?? null,
]);
