<?php

declare(strict_types=1);

/**
 * Generic Honeycomb addon JSON API.
 *
 * GET/POST /api/honeycomb-addon.php?slug={addon-slug}&action={action}
 *
 * Addons register AddonHttpHandler via HoneycombKernel::addHttpHandler().
 * New networks do not need a new core endpoint file.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

use SimpleKuma\Auth\ApiAuth;
use SimpleKuma\Auth\Auth;
use SimpleKuma\Auth\Permission;
use SimpleKuma\Honeycomb\AddonLoader;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($db->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$auth = new Auth($db);
ApiAuth::requirePermission($auth, Permission::PERM_CAMPAIGN_EDIT);

$slug = strtolower(trim((string) ($_GET['slug'] ?? '')));
$action = strtolower(trim((string) ($_GET['action'] ?? 'status')));

if ($slug === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing or invalid slug.']);
    exit;
}

$body = [];
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $body = $decoded;
        }
    }
    if ($body === [] && is_array($_POST) && $_POST !== []) {
        $body = $_POST;
    }
}

try {
    $loader = new AddonLoader($db);
    $enabled = false;
    foreach ($loader->describeInstalled() as $row) {
        if (($row['slug'] ?? '') === $slug && ($row['status'] ?? '') === 'enabled' && !empty($row['valid'])) {
            $enabled = true;
            break;
        }
    }
    if (!$enabled) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Honeycomb addon is not enabled: ' . $slug]);
        exit;
    }

    $handler = $loader->kernel()->httpHandler($slug);
    if ($handler === null) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Addon does not expose an HTTP API (missing http_api handler).',
        ]);
        exit;
    }

    /** @var array<string, mixed> $query */
    $query = $_GET;
    $result = $handler->handle($action, $query, $body);
    if (!is_array($result)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Handler returned an invalid payload.']);
        exit;
    }

    $status = 200;
    if (isset($result['_http_status'])) {
        $status = (int) $result['_http_status'];
        unset($result['_http_status']);
    }
    $errorText = '';
    if (isset($result['error']) && is_string($result['error']) && $result['error'] !== '') {
        $errorText = $result['error'];
    } elseif (($result['success'] ?? true) === false && isset($result['message']) && is_string($result['message'])) {
        $errorText = $result['message'];
    }
    if ($errorText !== '' || $status >= 400) {
        \SimpleKuma\Support\ApiFailureLog::record(
            $db,
            'honeycomb',
            $action,
            $status >= 400 ? $status : null,
            $errorText !== '' ? $errorText : 'Addon request failed.',
            $slug
        );
    }
    http_response_code($status > 0 ? $status : 200);
    echo json_encode($result);
} catch (Throwable $e) {
    error_log('honeycomb-addon: ' . $e->getMessage());
    \SimpleKuma\Support\ApiFailureLog::record($db, 'honeycomb', $action, 500, $e->getMessage(), $slug);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Addon request failed.']);
}
