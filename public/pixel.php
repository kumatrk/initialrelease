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
$clickId = $_GET['click_id'] ?? $_POST['click_id'] ?? null;
$value = isset($_GET['value']) ? (float)$_GET['value'] : (isset($_POST['value']) ? (float)$_POST['value'] : null);
$currency = $_GET['currency'] ?? $_POST['currency'] ?? 'USD';
$eventId = $_GET['event_id'] ?? $_POST['event_id'] ?? null;

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
    'source' => 'pixel',
    'value' => $value,
    'currency' => $currency,
    'event_id' => $eventId,
]);

$db->close();

// Return response
if ($result['success']) {
    http_response_code(200);
    
    // Support both image pixel and script
    if (isset($_GET['format']) && $_GET['format'] === 'json') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'message' => $result['message']]);
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


