<?php
/**
 * LP Click Tracker
 * Handles clicks from landing pages to offers
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

use SimpleKuma\Tracking\LPRotator;

// Get click ID from URL
$clickId = $_GET['click_id'] ?? null;

if (!$clickId) {
    http_response_code(400);
    die('Missing click_id parameter');
}

// Create database connection
$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

// Process LP click and redirect to offer
$rotator = new LPRotator($db);
$rotator->process($clickId);

// LPRotator->process() handles the redirect, so this line won't be reached
$db->close();

