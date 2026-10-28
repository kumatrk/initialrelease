<?php

declare(strict_types=1);

/**
 * Save per-user UI layout preferences (AJAX).
 * Supports sidebar_collapsed, dashboard_charts_hidden, dashboard_period_hidden, and report_columns.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/bootstrap_web_paths.php';

use SimpleKuma\Auth\Auth;
use SimpleKuma\Auth\Csrf;
use SimpleKuma\Stats\ReportColumnPrefs;

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

$allowedKeys = ['sidebar_collapsed', 'dashboard_charts_hidden', 'dashboard_period_hidden'];
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
        $migration = $key === 'dashboard_period_hidden' ? '102' : '076';
        echo json_encode([
            'ok' => false,
            'error' => "Preference '{$key}' is not available until database migration {$migration} is applied.",
        ]);
        exit;
    }

    $raw = $_POST[$key];
    $value = ($raw === true || $raw === 1 || $raw === '1' || $raw === 'true') ? 1 : 0;
    $updates[] = "{$key} = ?";
    $types .= 'i';
    $values[] = $value;
}

$savedReportColumns = null;
if (array_key_exists('report_columns', $_POST)) {
    $colCheck = $db->query("SHOW COLUMNS FROM users LIKE 'report_columns_json'");
    if (!$colCheck || $colCheck->num_rows === 0) {
        http_response_code(503);
        echo json_encode([
            'ok' => false,
            'error' => "Preference 'report_columns' is not available until database migration 099 is applied.",
        ]);
        exit;
    }

    $posted = $_POST['report_columns'];
    if (!is_string($posted) || strlen($posted) > 8000) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid column preferences']);
        exit;
    }

    $existingJson = null;
    $read = $db->prepare('SELECT report_columns_json FROM users WHERE id = ?');
    if ($read) {
        $read->bind_param('i', $userId);
        $read->execute();
        $existingRow = $read->get_result()->fetch_assoc();
        $read->close();
        if (is_array($existingRow) && is_string($existingRow['report_columns_json'] ?? null)) {
            $existingJson = $existingRow['report_columns_json'];
        }
    }

    $savedReportColumns = ReportColumnPrefs::mergeStored($existingJson, $posted);
    $updates[] = 'report_columns_json = ?';
    $types .= 's';
    $values[] = $savedReportColumns;
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
if ($savedReportColumns !== null) {
    $response['report_columns'] = json_decode($savedReportColumns, true);
}

echo json_encode($response);
