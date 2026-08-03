<?php
/**
 * API endpoint to fire a Facebook CAPI postback with optional test event code
 * Usage: POST with conversion_id and optional test_event_code
 * 
 * SECURITY: Requires authentication - only logged-in users can access this endpoint
 */

// Disable error display, but log errors
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start output buffering to catch any unexpected output
ob_start();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

use SimpleKuma\Auth\ApiAuth;
use SimpleKuma\Auth\Auth;
use SimpleKuma\Auth\Permission;
use SimpleKuma\Database\DbTimezone;

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear any output that might have been generated
ob_clean();

// Database connection for auth
$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

if ($db->connect_error) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

DbTimezone::init($db);

$auth = new Auth($db);

ApiAuth::requirePermission($auth, Permission::PERM_POSTBACK_MANAGE);

// Clear any output before setting headers
ob_clean();
header('Content-Type: application/json; charset=utf-8');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

$conversionId = isset($_POST['conversion_id']) ? (int)$_POST['conversion_id'] : null;
$testEventCode = isset($_POST['test_event_code']) ? trim($_POST['test_event_code']) : '';

if (!$conversionId) {
    ob_clean();
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'conversion_id is required']);
    exit;
}

// Get conversion to verify it exists
$checkStmt = $db->prepare("SELECT id, click_id FROM conversions WHERE id = ?");
$checkStmt->bind_param('i', $conversionId);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();
$conversion = $checkResult->fetch_assoc();
$checkStmt->close();

if (!$conversion) {
    ob_clean();
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => "Conversion ID {$conversionId} not found"]);
    exit;
}

// Get the campaign's Facebook CAPI integration
$clicksTable = \SimpleKuma\Database\ClicksTableResolver::getStatsTable($db);
$campaignStmt = $db->prepare(
    "SELECT cp.facebook_capi_integration_id 
     FROM conversions c
     INNER JOIN `{$clicksTable}` cl ON c.click_id = cl.click_id
     INNER JOIN campaigns cp ON cl.campaign_id = cp.id
     WHERE c.id = ?"
);
if (!$campaignStmt) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Database error: ' . $db->error]);
    exit;
}

$campaignStmt->bind_param('i', $conversionId);
if (!$campaignStmt->execute()) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Database error: ' . $campaignStmt->error]);
    $campaignStmt->close();
    exit;
}

$campaignResult = $campaignStmt->get_result();
$campaign = $campaignResult->fetch_assoc();
$campaignStmt->close();

if (!$campaign || empty($campaign['facebook_capi_integration_id'])) {
    ob_clean();
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'No Facebook CAPI integration configured for this conversion\'s campaign']);
    exit;
}

// Check if we should force production mode (strip test codes)
$forceProductionMode = !empty($_POST['force_production']) && $_POST['force_production'] === '1';

// If test event code is provided, temporarily update the integration
// IMPORTANT: This is TEMPORARY - the original test_code will be restored after firing
// This ensures the test code only applies to THIS ONE postback, not all future ones
$originalTestCode = null;
$integrationIdForRestore = null;
$testCodeWasUpdated = false;

// Get current test code first (we'll need it for restoration)
$testCodeStmt = $db->prepare("SELECT test_code FROM facebook_capi_integrations WHERE id = ?");
$testCodeStmt->bind_param('i', $campaign['facebook_capi_integration_id']);
$testCodeStmt->execute();
$testCodeResult = $testCodeStmt->get_result();
$testCodeRow = $testCodeResult->fetch_assoc();
$originalTestCode = $testCodeRow['test_code'] ?? null;
$integrationIdForRestore = $campaign['facebook_capi_integration_id'];
$testCodeStmt->close();

if ($forceProductionMode) {
    // Force production mode: explicitly set test_code to NULL to strip any existing test codes
    $updateStmt = $db->prepare("UPDATE facebook_capi_integrations SET test_code = NULL WHERE id = ?");
    $updateStmt->bind_param('i', $campaign['facebook_capi_integration_id']);
    $updateStmt->execute();
    $updateStmt->close();
    $testCodeWasUpdated = true;
    error_log("Production postback: Explicitly set test_code to NULL for integration {$integrationIdForRestore} (was: " . ($originalTestCode ?? 'NULL') . ")");
} elseif (!empty($testEventCode)) {
    // Temporarily update test code (will be restored after postback fires)
    $updateStmt = $db->prepare("UPDATE facebook_capi_integrations SET test_code = ? WHERE id = ?");
    $updateStmt->bind_param('si', $testEventCode, $campaign['facebook_capi_integration_id']);
    $updateStmt->execute();
    $updateStmt->close();
    $testCodeWasUpdated = true;
    error_log("Test postback: Temporarily set test_code to '{$testEventCode}' for integration {$integrationIdForRestore} (original: " . ($originalTestCode ?? 'NULL') . ")");
}

try {
    // Fire the postback
    require_once __DIR__ . '/../src/Tracking/PostbackDispatcher.php';
    
    // Capture any output from PostbackDispatcher
    $outputBuffer = ob_get_level();
    if ($outputBuffer > 0) {
        ob_end_clean();
    }
    ob_start();
    
    $forceBypass = !empty($_GET['force']) || !empty($_POST['force']);
    $dispatcher = new \SimpleKuma\Tracking\PostbackDispatcher($db);
    $dispatcher->firePostbacks($conversionId, $forceBypass);
    
    // Get and clean any output
    $output = ob_get_clean();
    if ($outputBuffer > 0) {
        ob_start();
    }
    
    // Get the latest postback log for this conversion
    // Check if request_body column exists
    $requestBodyColumnExists = false;
    $columnCheck = $db->query("SHOW COLUMNS FROM postback_logs LIKE 'request_body'");
    if ($columnCheck && $columnCheck->num_rows > 0) {
        $requestBodyColumnExists = true;
    }
    
    $logStmt = $db->prepare(
        "SELECT pl.*, pl.response_body, pl.url as request_url, pl.error_message, pl.http_method" .
        ($requestBodyColumnExists ? ", pl.request_body" : "") . "
         FROM postback_logs pl
         WHERE pl.conversion_id = ? AND pl.postback_type = 'facebook_capi'
         ORDER BY pl.created_at DESC
         LIMIT 1"
    );
    $convIdStr = (string)$conversionId;
    $logStmt->bind_param('s', $convIdStr);
    $logStmt->execute();
    $logResult = $logStmt->get_result();
    $postbackResult = $logResult->fetch_assoc();
    $logStmt->close();
    
    // Restore original test code if we updated it (CRITICAL: ensures test code is only temporary)
    // ALWAYS restore if we updated it, even if originalTestCode is NULL (means we need to set it back to NULL)
    if ($testCodeWasUpdated && $integrationIdForRestore !== null) {
        // Handle NULL properly - MySQLi requires special handling for NULL values
        if ($originalTestCode === null || $originalTestCode === '') {
            $restoreStmt = $db->prepare("UPDATE facebook_capi_integrations SET test_code = NULL WHERE id = ?");
            $restoreStmt->bind_param('i', $integrationIdForRestore);
        } else {
            $restoreStmt = $db->prepare("UPDATE facebook_capi_integrations SET test_code = ? WHERE id = ?");
            $restoreStmt->bind_param('si', $originalTestCode, $integrationIdForRestore);
        }
        $restoreStmt->execute();
        $restoreStmt->close();
        error_log("Postback: Restored original test_code (" . ($originalTestCode ?? 'NULL') . ") for integration {$integrationIdForRestore}");
        
        // Verify restoration worked
        $verifyStmt = $db->prepare("SELECT test_code FROM facebook_capi_integrations WHERE id = ?");
        $verifyStmt->bind_param('i', $integrationIdForRestore);
        $verifyStmt->execute();
        $verifyResult = $verifyStmt->get_result();
        $verifyRow = $verifyResult->fetch_assoc();
        $verifyStmt->close();
        $restoredValue = $verifyRow['test_code'] ?? null;
        if ($restoredValue !== $originalTestCode) {
            error_log("CRITICAL WARNING: Test code restoration verification failed! Expected: " . ($originalTestCode ?? 'NULL') . ", Got: " . ($restoredValue ?? 'NULL'));
        } else {
            error_log("Postback: Verified test_code restoration successful for integration {$integrationIdForRestore}");
        }
    }
    
    // Parse response body if available
    $responseBody = null;
    $metaResponseSummary = null;
    if (!empty($postbackResult['response_body'])) {
        $responseBody = json_decode($postbackResult['response_body'], true);
        // Surface Meta CAPI diagnostics for attribution debugging (events_received, fbtrace_id, warnings)
        if (is_array($responseBody)) {
            $metaResponseSummary = [
                'events_received' => $responseBody['events_received'] ?? null,
                'fbtrace_id' => $responseBody['fbtrace_id'] ?? null,
                'messages' => $responseBody['messages'] ?? null,
                'warnings' => $responseBody['warnings'] ?? null,
            ];
        }
    }
    
    // Parse request body if available
    $requestBody = null;
    if (!empty($postbackResult['request_body'])) {
        $requestBody = json_decode($postbackResult['request_body'], true);
    }
    
    // Clear any output before sending JSON
    ob_clean();
    
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'postback_log' => [
            'id' => $postbackResult['id'] ?? null,
            'http_code' => $postbackResult['http_code'] ?? null,
            'success' => (bool)($postbackResult['success'] ?? false),
            'error_message' => $postbackResult['error_message'] ?? null,
            'created_at' => $postbackResult['created_at'] ?? null,
            'request_url' => $postbackResult['request_url'] ?? null,
            'response_body' => $responseBody,
            'request_body' => $requestBody
        ],
        'meta_response_summary' => $metaResponseSummary,
        'test_event_code_used' => !empty($testEventCode),
        'test_event_code' => $testEventCode
    ]);
    exit;
    
} catch (Exception $e) {
    // Restore original test code if we updated it (CRITICAL: ensures test code is only temporary, even on error)
    // ALWAYS restore if we updated it, even if originalTestCode is NULL
    if ($testCodeWasUpdated && $integrationIdForRestore !== null) {
        try {
            // Handle NULL properly - MySQLi requires special handling for NULL values
            if ($originalTestCode === null || $originalTestCode === '') {
                $restoreStmt = $db->prepare("UPDATE facebook_capi_integrations SET test_code = NULL WHERE id = ?");
                $restoreStmt->bind_param('i', $integrationIdForRestore);
            } else {
                $restoreStmt = $db->prepare("UPDATE facebook_capi_integrations SET test_code = ? WHERE id = ?");
                $restoreStmt->bind_param('si', $originalTestCode, $integrationIdForRestore);
            }
            $restoreStmt->execute();
            $restoreStmt->close();
            error_log("Postback: Restored original test_code (" . ($originalTestCode ?? 'NULL') . ") for integration {$integrationIdForRestore} after exception");
        } catch (Exception $restoreError) {
            error_log("CRITICAL ERROR: Failed to restore test_code for integration {$integrationIdForRestore}: " . $restoreError->getMessage());
        }
    }
    
    // Clear any output
    ob_clean();
    
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'Error firing postback: ' . $e->getMessage()
    ]);
    exit;
} catch (Error $e) {
    // Restore original test code if we updated it (CRITICAL: ensures test code is only temporary, even on fatal error)
    // ALWAYS restore if we updated it, even if originalTestCode is NULL
    if ($testCodeWasUpdated && $integrationIdForRestore !== null) {
        try {
            // Handle NULL properly - MySQLi requires special handling for NULL values
            if ($originalTestCode === null || $originalTestCode === '') {
                $restoreStmt = $db->prepare("UPDATE facebook_capi_integrations SET test_code = NULL WHERE id = ?");
                $restoreStmt->bind_param('i', $integrationIdForRestore);
            } else {
                $restoreStmt = $db->prepare("UPDATE facebook_capi_integrations SET test_code = ? WHERE id = ?");
                $restoreStmt->bind_param('si', $originalTestCode, $integrationIdForRestore);
            }
            $restoreStmt->execute();
            $restoreStmt->close();
            error_log("Postback: Restored original test_code (" . ($originalTestCode ?? 'NULL') . ") for integration {$integrationIdForRestore} after fatal error");
        } catch (Exception $restoreError) {
            error_log("CRITICAL ERROR: Failed to restore test_code for integration {$integrationIdForRestore}: " . $restoreError->getMessage());
        }
    }
    
    // Clear any output
    ob_clean();
    
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'Fatal error firing postback: ' . $e->getMessage()
    ]);
    exit;
}

