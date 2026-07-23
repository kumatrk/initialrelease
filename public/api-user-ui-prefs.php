<?php

declare(strict_types=1);

/**
 * Save per-user UI layout preferences (AJAX).
 * Supports sidebar_collapsed and dashboard_charts_hidden.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/bootstrap_web_paths.php';

use SimpleKuma\Auth\Auth;
use SimpleKuma\Auth\Csrf;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$auth = new Auth($db);
$auth->requireAuth();

if (!Csrf::validate()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => Csrf::invalidRequestMessage()]);
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

$allowedKeys = ['sidebar_collapsed', 'dashboard_charts_hidden'];
$updates = [];
$types = '';
$values = [];

foreach ($allowedKeys as $key) {
    if (!array_key_exists($key, $_POST)) {
        continue;
    }

    $colCheck = $db->query("SHOW COLUMNS FROM users LIKE '{$key}'");
    if (!$colCheck || $colCheck->num_rows === 0) {
        http_response_code(503);
        echo json_encode([
            'ok' => false,
            'error' => "Preference '{$key}' is not available until database migration 076 is applied.",
        ]);
        exit;
    }

    $raw = $_POST[$key];
    $value = ($raw === true || $raw === 1 || $raw === '1' || $raw === 'true') ? 1 : 0;
    $updates[] = "{$key} = ?";
    $types .= 'i';
    $values[] = $value;
}

if ($updates === []) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No valid preferences provided']);
    exit;
}

$types .= 'i';
$values[] = $userId;
$sql = 'UPDATE users SET ' . implode(', ', $updates) . ', updated_at = NOW() WHERE id = ?';
$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$values);

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to save preferences']);
    $stmt->close();
    exit;
}

$stmt->close();

$response = ['ok' => true];
foreach ($allowedKeys as $key) {
    if (array_key_exists($key, $_POST)) {
        $raw = $_POST[$key];
        $response[$key] = ($raw === true || $raw === 1 || $raw === '1' || $raw === 'true') ? 1 : 0;
    }
}

echo json_encode($response);
