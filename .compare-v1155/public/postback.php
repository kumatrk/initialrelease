<?php

declare(strict_types=1);

/**
 * Simple KUMA - S2S Postback Endpoint
 * Server-to-server conversion tracking
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

use SimpleKuma\Database\DbTimezone;
use SimpleKuma\Tracking\ConversionTracker;

// Get parameters (support both GET and POST)
$clickId = $_GET['click_id'] ?? $_POST['click_id'] ?? null;
$txid = $_GET['txid'] ?? $_POST['txid'] ?? null;
$eventId = $_GET['event_id'] ?? $_POST['event_id'] ?? null;
$value = isset($_GET['value']) ? (float)$_GET['value'] : (isset($_POST['value']) ? (float)$_POST['value'] : null);
$currency = $_GET['currency'] ?? $_POST['currency'] ?? 'USD';
$status = $_GET['status'] ?? $_POST['status'] ?? 'approved';
$payout = isset($_GET['payout']) ? (float)$_GET['payout'] : (isset($_POST['payout']) ? (float)$_POST['payout'] : null);

// Validate required parameter
if (!$clickId) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'click_id is required']);
    exit;
}

// Create database connection
mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

if ($db->connect_error) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Service unavailable']);
    exit;
}

DbTimezone::init($db);

// Track conversion
$tracker = new ConversionTracker($db);
$result = $tracker->trackConversion($clickId, [
    'source' => 's2s',
    'txid' => $txid,
    'event_id' => $eventId,
    'value' => $value,
    'currency' => $currency,
    'status' => $status,
    'payout' => $payout,
]);

$db->close();

// Return response
header('Content-Type: application/json');
if ($result['success']) {
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'message' => $result['message']]);
} else {
    http_response_code(400);
    echo json_encode(['error' => $result['message']]);
}


