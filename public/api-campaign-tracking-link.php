<?php

declare(strict_types=1);

/**
 * Campaign tracking-link modal payload (auth required).
 * Keeps traffic-source tokens and URL templates out of campaign-list HTML.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/bootstrap_web_paths.php';

use SimpleKuma\Auth\ApiAuth;
use SimpleKuma\Auth\Auth;
use SimpleKuma\Auth\Permission;
use SimpleKuma\Campaign\CampaignTrackingLinkPayloadService;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$auth = new Auth($db);

ApiAuth::requirePermission($auth, Permission::PERM_CAMPAIGN_VIEW);

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow', true);
header('Cache-Control: no-store, no-cache, must-revalidate');

$campaignId = isset($_GET['campaign_id']) ? (int) $_GET['campaign_id'] : 0;
if ($campaignId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'campaign_id is required']);
    exit;
}

$service = new CampaignTrackingLinkPayloadService($db);
$payload = $service->buildForCampaignId($campaignId);

if ($payload === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Campaign not found']);
    exit;
}

echo json_encode(['data' => $payload], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
