<?php
/**
 * Deep Funnel hop: same click_id → another LP by opaque link_key.
 * Core endpoint (not Honeycomb). Only valid for flow_type=DeepFunnel.
 *
 * Query: click_id, k={link_key}
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

use SimpleKuma\Database\DbTimezone;
use SimpleKuma\Tracking\FunnelHop;

$clickId = $_GET['click_id'] ?? null;
$linkKey = isset($_GET['k']) ? strtolower(trim((string) $_GET['k'])) : '';

if (!$clickId || $linkKey === '' || !preg_match('/^[a-z0-9]{6,16}$/', $linkKey)) {
    http_response_code(400);
    die('Missing or invalid click_id / k');
}

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
DbTimezone::init($db);

$hop = new FunnelHop($db);
$hop->process($clickId, $linkKey);

$db->close();
