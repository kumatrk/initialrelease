<?php

declare(strict_types=1);

/**
 * Async click ingest for Simple Kuma Edge Redirect Worker.
 * POST JSON with Bearer ingest secret + optional HMAC replay headers.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

use SimpleKuma\Edge\EdgeClickIngest;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed', 'message' => 'POST required']);
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    if (defined('DB_CHARSET')) {
        $db->set_charset(DB_CHARSET);
    }
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['error' => 'db_unavailable', 'message' => 'Database unavailable']);
    exit;
}

$rawBody = file_get_contents('php://input') ?: '';
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_json', 'message' => 'Body must be JSON object']);
    exit;
}

try {
    $ingest = new EdgeClickIngest($db);
    $result = $ingest->handle(
        $payload,
        $rawBody,
        $_SERVER['HTTP_AUTHORIZATION'] ?? null,
        $_SERVER['HTTP_X_EDGE_TIMESTAMP'] ?? null,
        $_SERVER['HTTP_X_EDGE_SIGNATURE'] ?? null
    );
} catch (Throwable $e) {
    error_log('edge-click ingest: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'ingest_failed',
        'message' => 'Click ingest failed',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code($result['http']);
echo json_encode($result['body'], JSON_UNESCAPED_SLASHES);
