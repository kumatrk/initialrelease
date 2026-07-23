<?php
/**
 * Update Progress Endpoint
 * Provides real-time update progress via AJAX
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth/Auth.php';
require_once __DIR__ . '/../src/Auth/Permission.php';

header('Content-Type: application/json');

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$auth = new Auth($db);

if (!$auth->isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$permission = $auth->getPermission();
if (!$permission || !$permission->hasPermission(Permission::PERM_UPDATE_MANAGE)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$logId = isset($_GET['log_id']) ? (int)$_GET['log_id'] : 0;

if ($logId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid log ID']);
    exit;
}

// Get update log status
$stmt = $db->prepare("
    SELECT id, status, files_updated, migrations_applied, error_log, execution_time, started_at, completed_at
    FROM update_logs
    WHERE id = ?
");
$stmt->bind_param('i', $logId);
$stmt->execute();
$result = $stmt->get_result();
$log = $result->fetch_assoc();

if (!$log) {
    http_response_code(404);
    echo json_encode(['error' => 'Update log not found']);
    exit;
}

$filesUpdated = json_decode($log['files_updated'], true) ?? [];
$migrationsApplied = json_decode($log['migrations_applied'], true) ?? [];

echo json_encode([
    'log_id' => $log['id'],
    'status' => $log['status'],
    'files_updated' => $filesUpdated,
    'files_count' => count($filesUpdated),
    'migrations_applied' => $migrationsApplied,
    'migrations_count' => count($migrationsApplied),
    'error_log' => $log['error_log'],
    'execution_time' => $log['execution_time'],
    'started_at' => $log['started_at'],
    'completed_at' => $log['completed_at']
], JSON_PRETTY_PRINT);

