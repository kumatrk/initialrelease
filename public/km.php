<?php
/**
 * Simple KUMA - Click Redirector
 * High-performance endpoint for tracking clicks and redirecting
 * Target: <150ms p95 latency
 */

// CRITICAL: Set error reporting BEFORE any other code
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// CRITICAL: Clean any existing output buffers FIRST
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Get campaign key from URL
// This can be either:
// 1. A campaign_key (legacy): /{prefix}/dnVlJoT7
// 2. A custom slug: /{prefix}/myslug
// 3. Campaign key + slug (for tracking): /{prefix}/dnVlJoT7/myslug
$campaignKey = $_GET['key'] ?? $_GET['k'] ?? null;
$slugParam = $_GET['slug'] ?? null;

if (!$campaignKey) {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $path = parse_url($requestUri, PHP_URL_PATH) ?? $requestUri;

    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../src/bootstrap_web_paths.php';

    $prefixPattern = implode('|', array_map(
        static fn (string $p): string => preg_quote($p, '#'),
        \SimpleKuma\Tracking\ClickPath::rewritePrefixes()
    ));

    if ($prefixPattern !== '' && preg_match('#/(?:' . $prefixPattern . ')/([a-zA-Z0-9]+)(?:/(.+))?#', $path, $matches)) {
        $campaignKey = $matches[1];
        if (isset($matches[2]) && !$slugParam) {
            $slugParam = $matches[2];
        }
    }
}

if (!$campaignKey) {
    error_log("KM Redirector: No campaign key or slug found in URL. REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A') . ", GET: " . json_encode($_GET));
    http_response_code(404);
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
    }
    die('Campaign not found');
}

// Log for debugging (can be removed in production)
error_log("KM Redirector: Processing request - key: {$campaignKey}, slug param: " . ($slugParam ?? 'none') . ", REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A'));

// Minimal bootstrap (if not already loaded for path fallback)
if (!class_exists(\SimpleKuma\Tracking\Redirector::class)) {
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../config/config.php';
}

use SimpleKuma\Tracking\Redirector;

// Create database connection
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
} catch (mysqli_sql_exception $e) {
    error_log("KM Redirector: Database connection failed: " . $e->getMessage());
    http_response_code(500);
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
    }
    die("Database connection failed: " . htmlspecialchars($e->getMessage()));
}

if ($db->connect_error) {
    error_log("KM Redirector: Database connection failed: " . $db->connect_error);
    http_response_code(500);
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
    }
    die('Service unavailable');
}

// Ensure MySQL session timezone is UTC
$db->query("SET time_zone = '+00:00'");

// Create Redirector and process
try {
    $redirector = new Redirector($db);
    $redirector->process($campaignKey, $_GET);
    // Should never reach here
    error_log("KM Redirector: process() completed without redirect (unexpected)");
    http_response_code(500);
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
    }
    die("Redirect failed");
} catch (Throwable $e) {
    error_log("KM Redirector: Error in process(): " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    error_log("KM Redirector: Stack trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
    }
    
    if (ini_get('display_errors')) {
        die("KM Redirector Error:\n\n" . 
            "Message: " . $e->getMessage() . "\n" .
            "File: " . $e->getFile() . "\n" .
            "Line: " . $e->getLine() . "\n" .
            "Campaign Key: " . $campaignKey . "\n" .
            "GET Params: " . json_encode($_GET, JSON_PRETTY_PRINT) . "\n\n" .
            "Stack Trace:\n" . $e->getTraceAsString());
    }
    die("Service temporarily unavailable");
}

$db->close();
