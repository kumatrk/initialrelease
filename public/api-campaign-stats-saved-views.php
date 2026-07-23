<?php

declare(strict_types=1);

/**
 * Campaign Stats saved views API (list / save / delete).
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/bootstrap_web_paths.php';

use SimpleKuma\Auth\Auth;
use SimpleKuma\Auth\Csrf;
use SimpleKuma\Auth\Permission;
use SimpleKuma\Entity\Campaign;
use SimpleKuma\Stats\CampaignStatsSavedViewRepository;

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow', true);

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$auth = new Auth($db);
$auth->requireAuth();

$permission = $auth->getPermission();
if ($permission && !$permission->hasPermission(Permission::PERM_STATS_VIEW)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Permission denied']);
    exit;
}

$currentUser = $auth->getCurrentUser();
$userId = (int)($currentUser['id'] ?? 0);
$campaignId = isset($_REQUEST['campaign_id']) ? (int)$_REQUEST['campaign_id'] : 0;

if ($campaignId < 1) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'campaign_id is required']);
    exit;
}

if ((new Campaign($db))->getById($campaignId) === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Campaign not found']);
    exit;
}

$repo = new CampaignStatsSavedViewRepository($db);

if (!$repo->tableExists()) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'error' => 'Saved views require database migration 063.',
        'fallback' => 'local',
    ]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $auth->releaseSessionLock();
    $views = $repo->listForUserCampaign($userId, $campaignId);
    echo json_encode(['ok' => true, 'data' => ['views' => $views]]);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!Csrf::validate()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => Csrf::invalidRequestMessage()]);
    exit;
}

// CSRF validated; session no longer needed for DB write below.
$auth->releaseSessionLock();

$action = trim((string)($_POST['action'] ?? ''));

if ($action === 'save') {
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'name is required']);
        exit;
    }

    $configRaw = $_POST['config'] ?? '';
    if (is_array($configRaw)) {
        $config = $configRaw;
    } else {
        $config = json_decode((string)$configRaw, true);
    }
    if (!is_array($config)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid config JSON']);
        exit;
    }

    $view = $repo->create($userId, $campaignId, $name, $config);
    if ($view === null) {
        $errno = $db->errno;
        if ($errno === 1062) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'A saved view with that name already exists for this campaign.']);
            exit;
        }
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to save view']);
        exit;
    }

    echo json_encode(['ok' => true, 'data' => ['view' => $view]]);
    exit;
}

if ($action === 'delete') {
    $viewId = (int)($_POST['view_id'] ?? 0);
    if ($viewId < 1) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'view_id is required']);
        exit;
    }

    if (!$repo->delete($userId, $viewId)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Saved view not found']);
        exit;
    }

    echo json_encode(['ok' => true, 'data' => ['deleted' => $viewId]]);
    exit;
}

http_response_code(422);
echo json_encode(['ok' => false, 'error' => 'Unknown action']);
