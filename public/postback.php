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
$params = array_merge($_GET, $_POST);

$clickId = $params['click_id'] ?? null;
$txid = $params['txid'] ?? null;
$eventId = $params['event_id'] ?? null;
$value = isset($params['value']) && $params['value'] !== '' ? (float)$params['value'] : null;
$currency = $params['currency'] ?? 'USD';
$status = $params['status'] ?? 'approved';
$payout = isset($params['payout']) && $params['payout'] !== '' ? (float)$params['payout'] : null;

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

// Track conversion (et / event / event_type resolved inside ConversionTracker)
$tracker = new ConversionTracker($db);
$result = $tracker->trackConversion((string)$clickId, [
    'source' => 's2s',
    'txid' => $txid,
    'event_id' => $eventId,
    'value' => $value,
    'currency' => $currency,
    'status' => $status,
    'payout' => $payout,
    // Pass through alias keys for ConversionEventKey precedence
    'et' => $params['et'] ?? null,
    'event_type' => $params['event_type'] ?? null,
    'event' => $params['event'] ?? null,
]);

$db->close();

// Return response
header('Content-Type: application/json');
if ($result['success']) {
    http_response_code(200);
    $payload = ['status' => 'ok', 'message' => $result['message']];
    if (array_key_exists('event_key', $result)) {
        $payload['event_key'] = $result['event_key'];
    }
    echo json_encode($payload);
} else {
    http_response_code(400);
    echo json_encode(['error' => $result['message']]);
}
