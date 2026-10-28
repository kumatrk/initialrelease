<?php

declare(strict_types=1);

/**
 * Pause or activate one mapped Meta ad or ad set.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/bootstrap_web_paths.php';

use SimpleKuma\Auth\Auth;
use SimpleKuma\Auth\Csrf;
use SimpleKuma\Auth\Permission;
use SimpleKuma\Database\DatabaseCompatibility;
use SimpleKuma\Database\DbTimezone;
use SimpleKuma\Facebook\MetaEntityControl;

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow', true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
DbTimezone::init($db);
DatabaseCompatibility::applyReportingQueryTimeout($db, 20);

$auth = new Auth($db);
$auth->requireAuth();

$permission = $auth->getPermission();
if ($permission && !$permission->hasPermission(Permission::PERM_CAMPAIGN_EDIT)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'You do not have permission to change ads.']);
    exit;
}

if (!Csrf::validate()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => Csrf::invalidRequestMessage()]);
    exit;
}

$user = $auth->getCurrentUser();
$userId = isset($user['id']) ? (int)$user['id'] : null;
if ($userId !== null && $userId <= 0) {
    $userId = null;
}

try {
    $result = MetaEntityControl::change(
        $db,
        $userId,
        (int)($_POST['campaign_id'] ?? 0),
        (string)($_POST['level'] ?? ''),
        (string)($_POST['entity_id'] ?? ''),
        (string)($_POST['status'] ?? '')
    );
    echo json_encode([
        'ok' => true,
        'status' => $result['status'],
        'preview' => $result['preview'],
        'name' => $result['name'],
        'level' => $result['level'],
    ]);
} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (\RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('Meta entity control failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'That change could not be saved.']);
}
