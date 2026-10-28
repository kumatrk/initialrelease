<?php

declare(strict_types=1);

/**
 * Public proof that a tracking hostname reaches this Kuma install.
 * Returns a random token stored in settings. It grants no access.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

use SimpleKuma\Database\DbTimezone;
use SimpleKuma\Entity\DomainOriginProof;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

try {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    DbTimezone::init($db);
    $token = DomainOriginProof::ensureToken($db);
    $db->close();
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'unavailable']);
    exit;
}

echo json_encode(['ok' => true, 'token' => $token], JSON_UNESCAPED_SLASHES);
