<?php
/**
 * LP Click Tracker
 * Handles clicks from landing pages to offers
 *
 * Optional query params:
 *   o=2          — force offer rotation slot (normal LP/Split only)
 *   offer_id=123 — force catalog offer id if present in campaign rotation
 *   k=abcdef12   — Deep Funnel opaque offer link_key (preferred; slots ignored for DeepFunnel)
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

use SimpleKuma\Database\DbTimezone;
use SimpleKuma\Tracking\LPRotator;

$clickId = $_GET['click_id'] ?? null;

if (!$clickId) {
    http_response_code(400);
    die('Missing click_id parameter');
}

$forceSlot = null;
if (isset($_GET['o']) && $_GET['o'] !== '') {
    $forceSlot = (int) $_GET['o'];
    if ($forceSlot < 1) {
        $forceSlot = null;
    }
}

$forceOfferId = null;
if (isset($_GET['offer_id']) && $_GET['offer_id'] !== '') {
    $forceOfferId = (int) $_GET['offer_id'];
    if ($forceOfferId < 1) {
        $forceOfferId = null;
    }
}

$forceLinkKey = null;
if (isset($_GET['k']) && is_string($_GET['k'])) {
    $k = strtolower(trim($_GET['k']));
    if (preg_match('/^[a-z0-9]{6,16}$/', $k)) {
        $forceLinkKey = $k;
    }
}

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
DbTimezone::init($db);

$rotator = new LPRotator($db);
$rotator->process($clickId, $forceSlot, $forceOfferId, $forceLinkKey);

$db->close();
