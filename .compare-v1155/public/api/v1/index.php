<?php

declare(strict_types=1);

/**
 * Simple KUMA REST API v1 entry point.
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../src/bootstrap_web_paths.php';

use SimpleKuma\Api\ApiBootstrap;
use SimpleKuma\Api\Request;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    $db->query("SET time_zone = '+00:00'");

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        http_response_code(204);
        exit;
    }

    $request = new Request();
    $router = ApiBootstrap::createRouter();
    $router->dispatch($db, $request);
} catch (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'error' => [
            'code' => 'server_error',
            'message' => defined('DEBUG_MODE') && DEBUG_MODE
                ? $e->getMessage()
                : 'Internal server error',
        ],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}
