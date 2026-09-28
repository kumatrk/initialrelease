<?php

declare(strict_types=1);

/**
 * Honeycomb Ringba API helpers for campaign editor pickers.
 *
 * GET ?action=campaigns
 * GET ?action=tags&campaign_id=CA…
 * GET ?action=status
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

use SimpleKuma\Auth\ApiAuth;
use SimpleKuma\Auth\Auth;
use SimpleKuma\Auth\Permission;
use SimpleKuma\Honeycomb\AddonLoader;

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

$action = strtolower(trim((string) ($_GET['action'] ?? 'status')));

try {
    $loader = new AddonLoader($db);
    $enabled = false;
    foreach ($loader->describeInstalled() as $row) {
        if (($row['slug'] ?? '') === 'ringba' && ($row['status'] ?? '') === 'enabled' && !empty($row['valid'])) {
            $enabled = true;
            break;
        }
    }
    if (!$enabled) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Ringba Honeycomb addon is not enabled.']);
        exit;
    }

    // Ensure addon classes are autoloaded via kernel boot.
    $loader->kernel();

    $creds = new \Honeycomb\Ringba\RingbaCredentials($db);
    $active = $creds->active();
    if ($active === null) {
        echo json_encode([
            'success' => true,
            'connected' => false,
            'campaigns' => [],
            'tags' => [],
            'message' => 'No Ringba API credential. Paste a CA… id on the campaign, or connect under Honeycomb → Ringba.',
        ]);
        exit;
    }

    $client = new \Honeycomb\Ringba\RingbaClient($active['account_id'], $active['api_token']);

    if ($action === 'status') {
        $test = $client->testConnection();
        echo json_encode([
            'success' => $test['ok'],
            'connected' => $test['ok'],
            'message' => $test['message'],
            'campaign_count' => $test['campaign_count'],
            'account_id' => $active['account_id'],
        ]);
        exit;
    }

    if ($action === 'campaigns') {
        $campaigns = $client->listCampaigns();
        $slim = [];
        foreach ($campaigns as $c) {
            $slim[] = [
                'id' => $c['id'],
                'name' => $c['name'],
                'enabled' => $c['enabled'],
                // Script src uses campaign id per Ringba number-pool docs.
                'script_id' => $c['id'],
            ];
        }
        echo json_encode([
            'success' => true,
            'connected' => true,
            'campaigns' => $slim,
            'account_id' => $active['account_id'],
        ]);
        exit;
    }

    if ($action === 'tags') {
        $campaignId = trim((string) ($_GET['campaign_id'] ?? ''));
        if ($campaignId === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'campaign_id is required']);
            exit;
        }
        $tags = $client->listCampaignTags($campaignId);
        echo json_encode([
            'success' => true,
            'connected' => true,
            'campaign_id' => $campaignId,
            'tags' => $tags,
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown action. Use status, campaigns, or tags.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
