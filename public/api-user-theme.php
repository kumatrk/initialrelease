<?php

declare(strict_types=1);

/**
 * Save per-user UI theme preference (AJAX).
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/bootstrap_web_paths.php';

use SimpleKuma\Auth\Auth;
use SimpleKuma\Auth\Csrf;
use SimpleKuma\Theme\ThemeRegistry;

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

$colCheck = $db->query("SHOW COLUMNS FROM users LIKE 'theme'");
if (!$colCheck || $colCheck->num_rows === 0) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Theme preference is not available until database migration 062 is applied.']);
    exit;
}

$theme = ThemeRegistry::normalize(trim((string) ($_POST['theme'] ?? '')));
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

$stmt = $db->prepare('UPDATE users SET theme = ?, updated_at = NOW() WHERE id = ?');
$stmt->bind_param('si', $theme, $userId);

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to save theme']);
    $stmt->close();
    exit;
}

$stmt->close();

echo json_encode(['ok' => true, 'theme' => $theme]);
