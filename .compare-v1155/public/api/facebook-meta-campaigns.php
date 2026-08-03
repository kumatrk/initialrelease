<?php
/**
 * List or sync cached Meta campaigns for a Facebook ad account (campaign editor).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

use SimpleKuma\Auth\ApiAuth;
use SimpleKuma\Auth\Auth;
use SimpleKuma\Auth\Permission;
use SimpleKuma\Facebook\FacebookCampaignSyncService;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($db->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$auth = new Auth($db);
ApiAuth::requirePermission($auth, Permission::PERM_CAMPAIGN_EDIT);

$adAccountId = !empty($_GET['ad_account_id']) ? (int)$_GET['ad_account_id'] : 0;
$action = strtolower(trim((string)($_GET['action'] ?? 'list')));
$force = isset($_GET['force']) && ($_GET['force'] === '1' || $_GET['force'] === 'true');

if ($adAccountId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ad_account_id is required']);
    exit;
}

$service = new FacebookCampaignSyncService($db);

if ($action === 'sync') {
    echo json_encode($service->sync($adAccountId, $force));
    exit;
}

echo json_encode($service->listActive($adAccountId));
