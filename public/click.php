<?php

declare(strict_types=1);

/**
 * Simple KUMA - LP Click Rotator
 * Records LP→Offer clicks and rotates to offers
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

use SimpleKuma\Tracking\LPRotator;

// Get click_id
$clickId = $_GET['click_id'] ?? $_POST['click_id'] ?? null;

if (!$clickId) {
    http_response_code(404);
    die('Invalid click');
}

// Database connection
mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

if ($db->connect_error) {
    http_response_code(500);
    die('Service unavailable');
}

// Process LP click and redirect to offer
$rotator = new LPRotator($db);
$rotator->process($clickId);

$db->close();

// Should never reach here (redirect happens in process)
http_response_code(500);
die('Redirect failed');

