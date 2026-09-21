<?php

declare(strict_types=1);

/**
 * Simple KUMA - Pixel Conversion Tracking
 * Client-side conversion endpoint
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

use SimpleKuma\Database\DbTimezone;
use SimpleKuma\Tracking\ConversionTracker;

// Get parameters
$params = array_merge($_GET, $_POST);

$clickId = $params['click_id'] ?? null;
$value = isset($params['value']) && $params['value'] !== '' ? (float)$params['value'] : null;
$currency = $params['currency'] ?? 'USD';
$eventId = $params['event_id'] ?? null;

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
$result = $tracker->trackConversion((string)$clickId, [
    'source' => 'pixel',
    'value' => $value,
    'currency' => $currency,
    'event_id' => $eventId,
    'et' => $params['et'] ?? null,
    'event_type' => $params['event_type'] ?? null,
    'event' => $params['event'] ?? null,
]);

$db->close();

// Return response
if ($result['success']) {
    http_response_code(200);

    // Support both image pixel and script
    if (isset($_GET['format']) && $_GET['format'] === 'json') {
        header('Content-Type: application/json');
        $payload = ['status' => 'ok', 'message' => $result['message']];
        if (array_key_exists('event_key', $result)) {
            $payload['event_key'] = $result['event_key'];
        }
        echo json_encode($payload);
    } else {
        // 1x1 transparent GIF
        header('Content-Type: image/gif');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    }
} else {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => $result['message']]);
}
