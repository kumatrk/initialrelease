<?php

declare(strict_types=1);

/**
 * Subscribe / unsubscribe this browser for Account → Notifications → Browser alerts.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/bootstrap_web_paths.php';

use SimpleKuma\Auth\Auth;
use SimpleKuma\Auth\Csrf;
use SimpleKuma\Notify\PushSubscriptionStore;
use SimpleKuma\Notify\WebPushSender;
use SimpleKuma\Settings\SettingsManager;

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow', true);

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$auth = new Auth($db);
$auth->requireAuth();

$user = $auth->getCurrentUser();
$userId = isset($user['id']) ? (int) $user['id'] : 0;
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

$settings = new SettingsManager($db);
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $publicKey = WebPushSender::publicKey($settings);
    echo json_encode([
        'ok' => true,
        'vapidPublicKey' => $publicKey,
        'supported' => WebPushSender::libraryReady() && $publicKey !== '',
        'deviceCount' => PushSubscriptionStore::count($db),
        'userDeviceCount' => PushSubscriptionStore::countForUser($db, $userId),
        'tableReady' => PushSubscriptionStore::tableExists($db),
    ]);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!Csrf::validate()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => Csrf::invalidRequestMessage()]);
    exit;
}

$action = strtolower(trim((string) ($_POST['action'] ?? '')));
$endpoint = trim((string) ($_POST['endpoint'] ?? ''));

if ($action === 'unsubscribe') {
    if ($endpoint === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing subscription endpoint.']);
        exit;
    }
    PushSubscriptionStore::deleteByEndpoint($db, $endpoint, $userId);
    echo json_encode([
        'ok' => true,
        'subscribed' => false,
        'deviceCount' => PushSubscriptionStore::count($db),
        'userDeviceCount' => PushSubscriptionStore::countForUser($db, $userId),
    ]);
    exit;
}

if ($action !== 'subscribe') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
    exit;
}

$p256dh = trim((string) ($_POST['p256dh'] ?? ''));
$authKey = trim((string) ($_POST['auth'] ?? ''));
$userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
$error = PushSubscriptionStore::upsert($db, $userId, $endpoint, $p256dh, $authKey, $userAgent);
if ($error !== null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}

$publicKey = WebPushSender::publicKey($settings);
echo json_encode([
    'ok' => true,
    'subscribed' => true,
    'vapidPublicKey' => $publicKey,
    'deviceCount' => PushSubscriptionStore::count($db),
    'userDeviceCount' => PushSubscriptionStore::countForUser($db, $userId),
]);
