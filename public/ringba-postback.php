<?php

declare(strict_types=1);

/**
 * Ringba-friendly S2S postback alias → same ConversionTracker pipeline as postback.php.
 *
 * Accepts common Ringba / tracker query names and normalizes to click_id, txid, payout, et.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

use SimpleKuma\Database\DbTimezone;
use SimpleKuma\Tracking\ConversionTracker;

$params = array_merge($_GET, $_POST);

$pick = static function (array $params, array $keys): ?string {
    foreach ($keys as $key) {
        if (!array_key_exists($key, $params)) {
            continue;
        }
        $v = $params[$key];
        if ($v === null || $v === '') {
            continue;
        }
        return is_scalar($v) ? (string) $v : null;
    }
    return null;
};

$clickId = $pick($params, ['click_id', 'clickid', 'clickId', 'cid', 'ClickID']);
$txid = $pick($params, ['txid', 'call_id', 'callId', 'inboundCallId', 'InboundCallId', 'inbound_call_id']);
$eventId = $pick($params, ['event_id', 'eventId']);
$et = $pick($params, ['et', 'event_type', 'event', 'event_name']);
$payoutRaw = $pick($params, ['payout', 'call_revenue', 'callRevenue', 'revenue', 'ConversionPayout', 'ConversionAmount']);
$valueRaw = $pick($params, ['value']);
$currency = $pick($params, ['currency']) ?? 'USD';
$status = $pick($params, ['status']) ?? 'approved';

if ($clickId === null || $clickId === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'click_id is required (also accepts clickid, cid)']);
    exit;
}

$payout = $payoutRaw !== null && $payoutRaw !== '' ? (float) $payoutRaw : null;
$value = $valueRaw !== null && $valueRaw !== '' ? (float) $valueRaw : null;

mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($db->connect_error) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Service unavailable']);
    exit;
}

DbTimezone::init($db);

$tracker = new ConversionTracker($db);
$result = $tracker->trackConversion($clickId, [
    'source' => 's2s',
    'txid' => $txid,
    'event_id' => $eventId,
    'value' => $value,
    'currency' => $currency,
    'status' => $status,
    'payout' => $payout,
    'et' => $et,
    'event_type' => $params['event_type'] ?? null,
    'event' => $params['event'] ?? null,
]);

$db->close();

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
