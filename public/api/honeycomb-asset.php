<?php

declare(strict_types=1);

/**
 * Serve static files from honeycomb/addons/{slug}/assets/…
 *
 * GET /api/honeycomb-asset.php?slug={addon-slug}&file={relative-path}
 *
 * Only enabled addons; no path traversal; allowlisted extensions.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

use SimpleKuma\Auth\ApiAuth;
use SimpleKuma\Auth\Auth;
use SimpleKuma\Auth\Permission;
use SimpleKuma\Honeycomb\AddonLoader;
use SimpleKuma\Honeycomb\HoneycombPaths;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($db->connect_error) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Database connection failed';
    exit;
}

$auth = new Auth($db);
ApiAuth::requirePermission($auth, Permission::PERM_CAMPAIGN_EDIT);

$slug = strtolower(trim((string) ($_GET['slug'] ?? '')));
$file = str_replace('\\', '/', trim((string) ($_GET['file'] ?? '')));
$file = ltrim($file, '/');

if ($slug === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid slug';
    exit;
}

if ($file === '' || str_contains($file, '..') || preg_match('#^[a-zA-Z0-9._/-]+$#', $file) !== 1) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid file';
    exit;
}

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mimeMap = [
    'js' => 'application/javascript; charset=utf-8',
    'css' => 'text/css; charset=utf-8',
    'map' => 'application/json; charset=utf-8',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'svg' => 'image/svg+xml',
    'webp' => 'image/webp',
    'woff' => 'font/woff',
    'woff2' => 'font/woff2',
    'ico' => 'image/x-icon',
];
if (!isset($mimeMap[$ext])) {
    http_response_code(415);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unsupported file type';
    exit;
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
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Addon not enabled';
        exit;
    }

    // Boot kernel so autoload works if an addon later needs it; assets are filesystem-only.
    $loader->kernel();

    $resolved = HoneycombPaths::resolveAddonAsset($slug, $file);
    if ($resolved === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not found';
        exit;
    }

    $mtime = filemtime($resolved) ?: time();
    header('Content-Type: ' . $mimeMap[$ext]);
    header('Cache-Control: private, max-age=300');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('X-Content-Type-Options: nosniff');
    readfile($resolved);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Asset error';
}
