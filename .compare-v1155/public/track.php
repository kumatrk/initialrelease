<?php

declare(strict_types=1);

/**
 * Simple KUMA - Redirectless Tracking Endpoint
 * Receives tracking data from landing pages when redirectless tracking is enabled
 * Supports both client-side (JavaScript) and server-side (PHP curl) requests
 */

/**
 * Allow browser XHR from landing pages hosted on a different origin than the tracker
 * (e.g. LP on example.com calling track.php on srv.example.com).
 * Reflects Origin because redirectless.js uses withCredentials=true (wildcard * is invalid then).
 */
function sendRedirectlessCorsHeaders(): void
{
    if (headers_sent()) {
        return;
    }

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (
        $origin !== ''
        && !preg_match('/[\r\n]/', $origin)
        && preg_match('#^https?://[^\s/]+$#i', $origin)
    ) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    } else {
        header('Access-Control-Allow-Origin: *');
    }

    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-Kuma-Server-Side');
    header('Access-Control-Max-Age: 86400');
}

sendRedirectlessCorsHeaders();

// Preflight for credentialed cross-origin requests
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Set up error handling for fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Detect if this is a server-side request
        $isServerSide = !empty($_GET['server_side']) || !empty($_POST['server_side']);
        
        if ($isServerSide && !headers_sent()) {
            sendRedirectlessCorsHeaders();
            header('Content-Type: application/json');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            echo json_encode([
                'success' => false,
                'error' => 'Fatal error: ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line'],
                'click_id' => null
            ]);
        }
    }
});

try {
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../config/config.php';
} catch (Throwable $e) {
    $isServerSide = !empty($_GET['server_side']) || !empty($_POST['server_side']);
    if ($isServerSide && !headers_sent()) {
        sendRedirectlessCorsHeaders();
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo json_encode([
            'success' => false,
            'error' => 'Failed to load required files: ' . $e->getMessage(),
            'click_id' => null
        ]);
    }
    exit;
}

use SimpleKuma\Tracking\RedirectlessTracker;

// Detect if this is a server-side request (from PHP curl on landing page)
$isServerSide = !empty($_GET['server_side']) || !empty($_POST['server_side']) || 
                (!empty($_SERVER['HTTP_X_KUMA_SERVER_SIDE']) && $_SERVER['HTTP_X_KUMA_SERVER_SIDE'] === '1');

// Get campaign ID and landing page ID from parameters
$campaignId = isset($_GET['c']) ? (int)$_GET['c'] : (isset($_POST['c']) ? (int)$_POST['c'] : 0);
$landingPageId = isset($_GET['l']) ? (int)$_GET['l'] : (isset($_POST['l']) ? (int)$_POST['l'] : 0);

if (!$campaignId || !$landingPageId) {
    // Return JSON error for server-side, pixel for client-side
    if ($isServerSide) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Missing campaign ID or landing page ID', 'click_id' => null]);
    } else {
        header('Content-Type: image/png');
        echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    }
    exit;
}

// Debug=5: Output clicks table structure and INSERT SQL for diagnosing column mismatch
// SECURITY: Only available when TRACK_DEBUG is explicitly enabled in config (disabled by default)
if (defined('TRACK_DEBUG') && TRACK_DEBUG && !empty($_GET['debug']) && $_GET['debug'] == '5' && !empty($_GET['format']) && $_GET['format'] === 'json') {
    $db = @new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    $diag = ['columns' => [], 'column_count' => 0, 'sql' => null, 'placeholder_count' => 0, 'bind_count' => 0];
    if (!$db->connect_error) {
        $r = $db->query("SHOW COLUMNS FROM clicks");
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $diag['columns'][] = $row['Field'] . ' (' . $row['Type'] . ')';
            }
            $diag['column_count'] = count($diag['columns']);
        }
        $tsCheck = $db->query("SHOW COLUMNS FROM clicks LIKE 'traffic_source_id'");
        $tsExists = $tsCheck && $tsCheck->num_rows > 0;
        $postalCheck = $db->query("SHOW COLUMNS FROM clicks LIKE 'postal'");
        $postalExists = $postalCheck && $postalCheck->num_rows > 0;
        $geoCols = "country, region, city" . ($postalExists ? ", postal" : "");
        $geoPlaceholders = $postalExists ? "?, ?, ?, ?" : "?, ?, ?";
        $geoBindCount = $postalExists ? 4 : 3;
        if ($tsExists) {
            $sql = "INSERT INTO clicks (campaign_id, slug_id, traffic_source_id, offer_id, landing_page_id, click_id, ts, ip, ua, referrer, " . $geoCols . ", device, device_brand, device_model, os, os_version, browser, browser_version, cost, cost_currency, extra_json, ts_hour, lp_click, ts_lp) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, " . $geoPlaceholders . ", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00'), ?, NULL)";
            $diag['bind_count'] = 9 + $geoBindCount + 11;
        } else {
            $sql = "INSERT INTO clicks (campaign_id, slug_id, offer_id, landing_page_id, click_id, ts, ip, ua, referrer, " . $geoCols . ", device, device_brand, device_model, os, os_version, browser, browser_version, cost, cost_currency, extra_json, ts_hour, lp_click, ts_lp) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, " . $geoPlaceholders . ", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00'), ?, NULL)";
            $diag['bind_count'] = 8 + $geoBindCount + 11;
        }
        $diag['sql'] = $sql;
        $diag['placeholder_count'] = substr_count($sql, '?');
        $diag['traffic_source_exists'] = (bool)$tsExists;
        $diag['postal_exists'] = (bool)$postalExists;
        $db->close();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'debug' => $diag, '_v' => 5]);
    exit;
}

// Debug-only endpoint: ?c=8&l=3&format=json&debug=2 skips track() and returns diagnostics only
// SECURITY: Only available when TRACK_DEBUG is explicitly enabled in config (disabled by default)
if (defined('TRACK_DEBUG') && TRACK_DEBUG && !empty($_GET['debug']) && $_GET['debug'] == '2' && !empty($_GET['format']) && $_GET['format'] === 'json') {
    $db = @new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    $diag = ['campaign_found' => false, 'campaign_status' => null, 'flow_type' => null, 'lp_in_rotation' => false, 'lp_ids_in_rotation' => [], 'error' => 'Unknown', '_source' => 'debug2'];
    if ($db->connect_error) {
        $diag['error'] = 'DB connect failed: ' . $db->connect_error;
    } else {
        $stmt = $db->prepare("SELECT id, status, flow_type, rotation_json FROM campaigns WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $campaignId);
            $stmt->execute();
            $camp = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$camp) {
                $diag['error'] = 'Campaign not found';
            } else {
                $diag['campaign_found'] = true;
                $diag['campaign_status'] = $camp['status'] ?? null;
                $diag['flow_type'] = $camp['flow_type'] ?? null;
                $rot = json_decode($camp['rotation_json'] ?? '[]', true) ?: [];
                $lpIds = [];
                if (($camp['flow_type'] ?? '') === 'LP' && isset($rot['landing_pages'])) {
                    foreach ($rot['landing_pages'] as $lp) { if (isset($lp['id'])) $lpIds[] = (int)$lp['id']; }
                } elseif (($camp['flow_type'] ?? '') === 'Split' && isset($rot['lp_path']['landing_pages'])) {
                    foreach ($rot['lp_path']['landing_pages'] as $lp) { if (isset($lp['id'])) $lpIds[] = (int)$lp['id']; }
                }
                $diag['lp_ids_in_rotation'] = $lpIds;
                $diag['lp_in_rotation'] = in_array($landingPageId, $lpIds, true);
                $diag['error'] = ($camp['status'] ?? '') !== 'active' ? 'Campaign not active' : (!$diag['lp_in_rotation'] ? 'LP not in rotation' : 'OK');
            }
        } else {
            $diag['error'] = 'DB prepare failed';
        }
        $db->close();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $diag['error'], 'click_id' => null, 'debug' => $diag, '_v' => 5]);
    exit;
}

// Create database connection
mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

if ($db->connect_error) {
    // Return JSON error for server-side, pixel for client-side
    if ($isServerSide) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Database connection failed', 'click_id' => null]);
    } else {
        header('Content-Type: image/png');
        echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    }
    exit;
}

// Ensure MySQL session timezone is UTC for consistent timestamp storage
$db->query("SET time_zone = '+00:00'");

// For server-side requests, we need to forward the visitor's IP, User-Agent, and Referrer
// These are sent as headers or POST data from the landing page's curl request
if ($isServerSide) {
    // Override $_SERVER values with forwarded visitor data
    if (!empty($_POST['visitor_ip']) || !empty($_GET['visitor_ip'])) {
        $_SERVER['REMOTE_ADDR'] = $_POST['visitor_ip'] ?? $_GET['visitor_ip'];
    }
    if (!empty($_POST['visitor_ua']) || !empty($_GET['visitor_ua'])) {
        $_SERVER['HTTP_USER_AGENT'] = $_POST['visitor_ua'] ?? $_GET['visitor_ua'];
    }
    if (!empty($_POST['visitor_referrer']) || !empty($_GET['visitor_referrer'])) {
        $_SERVER['HTTP_REFERER'] = $_POST['visitor_referrer'] ?? $_GET['visitor_referrer'];
    }
    
    // Merge GET and POST parameters (POST takes precedence)
    $trackingParams = array_merge($_GET, $_POST);
    // Remove server-side specific parameters
    unset($trackingParams['server_side'], $trackingParams['visitor_ip'], $trackingParams['visitor_ua'], $trackingParams['visitor_referrer']);
} else {
    // Client-side request - use GET parameters as normal
    $trackingParams = $_GET;
}

// Process redirectless tracking
$debugInfo = null;
$trackFailureReason = null;
try {
    $tracker = new RedirectlessTracker($db);
    $clickId = $tracker->track($campaignId, $landingPageId, $trackingParams, $trackFailureReason);
    
    // When tracking fails, always get detailed diagnostics (inlined - no RedirectlessTracker dependency)
    if (!$clickId) {
        $debugInfo = ['campaign_found' => false, 'campaign_status' => null, 'flow_type' => null, 'lp_in_rotation' => false, 'lp_ids_in_rotation' => [], 'error' => 'Unknown'];
        $campStmt = $db->prepare("SELECT id, status, flow_type, rotation_json FROM campaigns WHERE id = ?");
        if ($campStmt) {
            $campStmt->bind_param('i', $campaignId);
            $campStmt->execute();
            $camp = $campStmt->get_result()->fetch_assoc();
            $campStmt->close();
            if (!$camp) {
                $debugInfo['error'] = 'Campaign not found';
            } else {
                $debugInfo['campaign_found'] = true;
                $debugInfo['campaign_status'] = $camp['status'] ?? null;
                $debugInfo['flow_type'] = $camp['flow_type'] ?? null;
                $rotation = json_decode($camp['rotation_json'] ?? '[]', true) ?: [];
                $lpIds = [];
                if (($camp['flow_type'] ?? '') === 'LP' && isset($rotation['landing_pages'])) {
                    foreach ($rotation['landing_pages'] as $lp) {
                        if (isset($lp['id'])) $lpIds[] = (int)$lp['id'];
                    }
                } elseif (($camp['flow_type'] ?? '') === 'Split' && isset($rotation['lp_path']['landing_pages'])) {
                    foreach ($rotation['lp_path']['landing_pages'] as $lp) {
                        if (isset($lp['id'])) $lpIds[] = (int)$lp['id'];
                    }
                }
                $debugInfo['lp_ids_in_rotation'] = $lpIds;
                $debugInfo['lp_in_rotation'] = in_array($landingPageId, $lpIds, true);
                if (($camp['status'] ?? '') !== 'active') {
                    $debugInfo['error'] = 'Campaign not active (status: ' . ($camp['status'] ?? 'null') . ')';
                } elseif (!$debugInfo['lp_in_rotation']) {
                    $debugInfo['error'] = 'Landing page ' . $landingPageId . ' not in rotation. LPs in rotation: ' . implode(', ', $lpIds);
                } else {
                    $debugInfo['error'] = $trackFailureReason
                        ? 'track() failed: ' . $trackFailureReason
                        : 'Unknown (checks passed but track() returned null)';
                }
                if ($trackFailureReason) {
                    $debugInfo['track_failure_reason'] = $trackFailureReason;
                }
            }
        } else {
            $debugInfo['error'] = 'DB prepare failed: ' . $db->error;
        }
    }
    
    // Log for debugging (remove in production)
    error_log("Redirectless tracking: campaign_id=$campaignId, landing_page_id=$landingPageId, click_id=" . ($clickId ?? 'NULL') . ", server_side=" . ($isServerSide ? 'yes' : 'no'));
} catch (Throwable $e) {
    // Log the full error
    error_log("Redirectless tracking error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    $clickId = null;
    $debugInfo = [
        'error' => 'Exception: ' . $e->getMessage(),
        'exception' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ];
    
    // Return error for server-side requests
    if ($isServerSide) {
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        $payload = ['success' => false, 'error' => 'Tracking error: ' . $e->getMessage(), 'click_id' => null, '_v' => 5];
        if (defined('TRACK_DEBUG') && TRACK_DEBUG) {
            $payload['debug'] = $debugInfo;
        }
        echo json_encode($payload);
        $db->close();
        exit;
    }
}

$db->close();

// Server-side requests always return JSON
if ($isServerSide) {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    if ($clickId) {
        echo json_encode(['success' => true, 'click_id' => $clickId]);
    } else {
        $errMsg = ($debugInfo !== null && isset($debugInfo['error'])) ? $debugInfo['error'] : 'Tracking failed - campaign may not be active or landing page not in rotation';
        $errorPayload = ['success' => false, 'error' => $errMsg, 'click_id' => null, '_v' => 5];
        if (defined('TRACK_DEBUG') && TRACK_DEBUG) {
            $errorPayload['debug'] = $debugInfo;
        }
        echo json_encode($errorPayload);
    }
    exit;
}

// Client-side requests: Check if this is an AJAX request (wants JSON response with click_id)
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$wantsJson = !empty($_GET['format']) && $_GET['format'] === 'json';

if ($isAjax || $wantsJson) {
    // Return JSON with click_id for JavaScript to use
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    if ($clickId) {
        echo json_encode(['success' => true, 'click_id' => $clickId]);
    } else {
        // If tracking failed, return error (but don't break the page)
        $errMsg = ($debugInfo !== null && isset($debugInfo['error'])) ? $debugInfo['error'] : 'Tracking failed - campaign may not be active or landing page not in rotation';
        $errorPayload = ['success' => false, 'error' => $errMsg, 'click_id' => null, '_v' => 5];
        if (defined('TRACK_DEBUG') && TRACK_DEBUG) {
            $errorPayload['debug'] = $debugInfo;
        }
        echo json_encode($errorPayload);
    }
    exit;
}

// Return 1x1 transparent pixel (default)
header('Content-Type: image/png');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
// Also set click_id in cookie for chomp.js to read
if ($clickId) {
    setcookie('kuma_click_id', $clickId, time() + 3600, '/'); // Expires in 1 hour
}
echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

