<?php
/**
 * API endpoint to fetch detailed postback information for modal display
 * Usage: ?conversion_id=24 or ?postback_log_id=123
 * 
 * SECURITY: Requires authentication - only logged-in users can access this endpoint
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

use SimpleKuma\Auth\ApiAuth;
use SimpleKuma\Auth\Auth;
use SimpleKuma\Auth\Permission;
use SimpleKuma\Tracking\ClickPath;
use SimpleKuma\Tracking\MetaCapiTimestamps;

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection for auth
$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$auth = new Auth($db);

ApiAuth::requirePermission($auth, Permission::PERM_STATS_VIEW);

header('Content-Type: application/json; charset=utf-8');

$conversionId = isset($_GET['conversion_id']) ? (int)$_GET['conversion_id'] : null;
$postbackLogId = isset($_GET['postback_log_id']) ? (int)$_GET['postback_log_id'] : null;

if (!$conversionId && !$postbackLogId) {
    http_response_code(400);
    echo json_encode(['error' => 'conversion_id or postback_log_id required']);
    exit;
}

// If we have postback_log_id, get conversion_id from it
if ($postbackLogId && !$conversionId) {
    $stmt = $db->prepare("SELECT conversion_id FROM postback_logs WHERE id = ?");
    $stmt->bind_param('i', $postbackLogId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $conversionId = (int)$row['conversion_id'];
    }
    $stmt->close();
}

if (!$conversionId) {
    http_response_code(404);
    echo json_encode(['error' => 'Conversion not found']);
    exit;
}

// Get conversion with click data and campaign/integration info
// Need campaign_id for payload reconstruction
$convStmt = $db->prepare(
    "SELECT c.*, cl.extra_json as click_extra_json, cl.ts as click_ts,
            cl.ip, cl.ua, cl.referrer, cl.landing_page_id, cl.campaign_id,
            cp.facebook_capi_integration_id, cp.name as campaign_name,
            cp.tracking_domain_id, cp.campaign_key,
            td.domain as tracking_domain,
            fci.id as integration_id, fci.name as integration_name,
            fci.pixel_id, fci.access_token, fci.test_code, fci.event_type
     FROM conversions c
     INNER JOIN clicks cl ON c.click_id = cl.click_id
     LEFT JOIN campaigns cp ON cl.campaign_id = cp.id
     LEFT JOIN tracking_domains td ON cp.tracking_domain_id = td.id
     LEFT JOIN facebook_capi_integrations fci ON cp.facebook_capi_integration_id = fci.id
     WHERE c.id = ?"
);

$convIdStr = (string)$conversionId;
$convStmt->bind_param('s', $convIdStr);
$convStmt->execute();
$convResult = $convStmt->get_result();
$conversion = $convResult->fetch_assoc();
$convStmt->close();

if (!$conversion) {
    http_response_code(404);
    echo json_encode(['error' => 'Conversion not found']);
    exit;
}

// Parse extra_json
$clickExtra = json_decode($conversion['click_extra_json'], true);

// Extract fbc/fbclid info
$fbcCookie = null;
$fbcCookieSource = null;
if (!empty($clickExtra['traffic_source_tokens']['_fbc'])) {
    $fbcCookie = trim($clickExtra['traffic_source_tokens']['_fbc']);
    $fbcCookieSource = 'traffic_source_tokens._fbc';
} elseif (!empty($clickExtra['all_params']['_fbc'])) {
    $fbcCookie = trim($clickExtra['all_params']['_fbc']);
    $fbcCookieSource = 'all_params._fbc';
} elseif (!empty($clickExtra['cookies']['_fbc'])) {
    $fbcCookie = trim($clickExtra['cookies']['_fbc']);
    $fbcCookieSource = 'cookies._fbc';
}

$fbclid = null;
$fbclidSource = null;
if (!empty($clickExtra['traffic_source_tokens']['fbclid'])) {
    $fbclid = trim($clickExtra['traffic_source_tokens']['fbclid']);
    $fbclidSource = 'traffic_source_tokens.fbclid';
} elseif (!empty($clickExtra['all_params']['fbclid'])) {
    $fbclid = trim($clickExtra['all_params']['fbclid']);
    $fbclidSource = 'all_params.fbclid';
}

// Get postback logs
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
     WHERE pl.conversion_id = ?
     ORDER BY pl.created_at DESC"
);
$logStmt->bind_param('s', $convIdStr);
$logStmt->execute();
$logResult = $logStmt->get_result();

$logs = [];
while ($row = $logResult->fetch_assoc()) {
    $logs[] = $row;
}
$logStmt->close();

// Reconstruct request payload for Facebook CAPI postbacks that don't have request_body stored
// This allows us to see what the payload WOULD look like based on current logic
foreach ($logs as &$log) {
    if ($log['postback_type'] === 'facebook_capi' && empty($log['request_body'])) {
        // Reconstruct the payload using the same logic as PostbackDispatcher
        $reconstructedPayload = reconstructFacebookCAPIPayload($conversion, $clickExtra, $db);
        if ($reconstructedPayload) {
            $log['reconstructed_request_body'] = json_encode($reconstructedPayload, JSON_PRETTY_PRINT);
            $log['has_reconstructed_payload'] = true;
        }
    }
}
unset($log); // Break reference

/**
 * Reconstruct Facebook CAPI payload using the same logic as PostbackDispatcher
 * This allows us to see what the payload would look like for old postbacks
 */
function reconstructFacebookCAPIPayload(array $conversion, array $clickExtra, mysqli $db): ?array {
    // Check if we have the required integration data
    if (empty($conversion['pixel_id']) || empty($conversion['access_token'])) {
        return null;
    }
    
    // Calculate event_time FIRST (same as PostbackDispatcher)
    $eventTimeMs = MetaCapiTimestamps::resolveEventTimeMs($conversion);
    $eventTime = (int) floor($eventTimeMs / 1000);
    
    // Build event source URL (same logic as PostbackDispatcher)
    $trackingDomainUrl = null;
    if (!empty($conversion['tracking_domain'])) {
        $trackingDomainUrl = rtrim($conversion['tracking_domain'], '/');
    } else {
        $trackingDomainUrl = rtrim(BASE_URL, '/');
    }
    
    $eventSourceUrl = null;
    $extra = $clickExtra;
    $isRedirectless = !empty($extra['c']) || !empty($extra['l']);
    
    if ($isRedirectless && !empty($conversion['campaign_id'])) {
        $trackerParams = ['c' => $conversion['campaign_id']];
        if (!empty($extra['l'])) {
            $trackerParams['l'] = $extra['l'];
        } elseif (!empty($conversion['landing_page_id'])) {
            $trackerParams['l'] = $conversion['landing_page_id'];
        }
        $fbParams = ['fbclid', 'ad_id', 'adset_id', 'campaign_id', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'utm_id'];
        foreach ($fbParams as $param) {
            if (!empty($extra['traffic_source_tokens'][$param])) {
                $trackerParams[$param] = $extra['traffic_source_tokens'][$param];
            } elseif (!empty($extra['all_params'][$param])) {
                $trackerParams[$param] = $extra['all_params'][$param];
            }
        }
        $eventSourceUrl = $trackingDomainUrl . '/track.php?' . http_build_query($trackerParams);
    } elseif (!empty($conversion['campaign_key'])) {
        $trackerParams = [];
        $fbParams = ['fbclid', 'ad_id', 'adset_id', 'campaign_id', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'utm_id'];
        foreach ($fbParams as $param) {
            if (!empty($extra['traffic_source_tokens'][$param])) {
                $trackerParams[$param] = $extra['traffic_source_tokens'][$param];
            } elseif (!empty($extra['all_params'][$param])) {
                $trackerParams[$param] = $extra['all_params'][$param];
            }
        }
        $eventSourceUrl = ClickPath::url($trackingDomainUrl, (string) $conversion['campaign_key']);
        if (!empty($trackerParams)) {
            $separator = str_contains($eventSourceUrl, '?') ? '&' : '?';
            $eventSourceUrl .= $separator . http_build_query($trackerParams);
        }
    } else {
        if (!empty($conversion['referrer']) && filter_var($conversion['referrer'], FILTER_VALIDATE_URL)) {
            $eventSourceUrl = $conversion['referrer'];
        } else {
            $eventSourceUrl = $trackingDomainUrl . '/';
        }
    }
    
    // Build user_data (same logic as PostbackDispatcher)
    $userData = [];
    
    // IP address
    if (!empty($conversion['ip']) && filter_var($conversion['ip'], FILTER_VALIDATE_IP)) {
        $userData['client_ip_address'] = $conversion['ip'];
    }
    
    // User agent
    if (!empty($conversion['ua'])) {
        $userData['client_user_agent'] = $conversion['ua'];
    }
    
    // Email (hashed)
    foreach (['email', 'em', 'e', 'user_email'] as $emailKey) {
        $email = null;
        if (!empty($extra['traffic_source_tokens'][$emailKey])) {
            $email = trim($extra['traffic_source_tokens'][$emailKey]);
        } elseif (!empty($extra['all_params'][$emailKey])) {
            $email = trim($extra['all_params'][$emailKey]);
        }
        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $userData['em'] = [hash('sha256', strtolower($email))];
            break;
        }
    }
    
    // Phone (hashed)
    foreach (['phone', 'ph', 'tel', 'phone_number'] as $phoneKey) {
        $phone = null;
        if (!empty($extra['traffic_source_tokens'][$phoneKey])) {
            $phone = $extra['traffic_source_tokens'][$phoneKey];
        } elseif (!empty($extra['all_params'][$phoneKey])) {
            $phone = $extra['all_params'][$phoneKey];
        }
        if ($phone) {
            $phoneDigits = preg_replace('/[^0-9]/', '', $phone);
            if (!empty($phoneDigits)) {
                $userData['ph'] = [hash('sha256', $phoneDigits)];
                break;
            }
        }
    }
    
    // FBC parameter (same logic as PostbackDispatcher)
    $conversionId = (int) ($conversion['id'] ?? 0);
    $fbc = MetaCapiTimestamps::buildFbc($conversion, $extra, $eventTimeMs, $conversionId);
    if ($fbc !== null) {
        $userData['fbc'] = $fbc;
    }
    MetaCapiTimestamps::logElapsedTime($conversionId, $eventTime, $fbc);

    // FBP cookie
    $fbpCookie = null;
    if (!empty($extra['traffic_source_tokens']['_fbp'])) {
        $fbpCookie = trim($extra['traffic_source_tokens']['_fbp']);
    } elseif (!empty($extra['all_params']['_fbp'])) {
        $fbpCookie = trim($extra['all_params']['_fbp']);
    } elseif (!empty($extra['cookies']['_fbp'])) {
        $fbpCookie = trim($extra['cookies']['_fbp']);
    }
    if ($fbpCookie && $fbpCookie !== '') {
        $userData['fbp'] = $fbpCookie;
    }
    
    // Custom data
    // Note: For Lead events, value can be 0. For Purchase events, value should reflect purchase amount.
    // CRITICAL: Use 'value' field first, but fallback to 'payout' if value is NULL/empty.
    // This is because conversions may store the monetary amount in 'payout' instead of 'value'.
    $conversionValue = $conversion['value'] ?? null;
    
    // If value is NULL/empty, use payout as fallback (payout is the actual revenue amount)
    if (($conversionValue === null || $conversionValue === '' || $conversionValue === false) && !empty($conversion['payout'])) {
        $conversionValue = $conversion['payout'];
    }
    
    if ($conversionValue === null || $conversionValue === '' || $conversionValue === false) {
        $customData['value'] = 0.0; // Default to 0 if not set (acceptable for Lead events)
    } else {
        $floatValue = (float)$conversionValue;
        $customData['value'] = is_finite($floatValue) ? $floatValue : 0.0;
    }
    $customData['currency'] = strtoupper($conversion['currency'] ?? 'USD');
    
    // Build event data
    $eventType = $conversion['event_type'] ?? 'Purchase';
    
    $eventData = [
        'data' => [[
            'event_name' => $eventType,
            'event_time' => $eventTime,
            'event_id' => $conversion['event_id'] ?? $conversion['click_id'],
            'action_source' => 'website',
            'event_source_url' => $eventSourceUrl,
            'user_data' => $userData,
            'custom_data' => $customData,
        ]],
        'access_token' => $conversion['access_token'], // Will be masked in display
    ];
    
    // Add test event code if configured
    if (!empty($conversion['test_code'])) {
        $eventData['test_event_code'] = $conversion['test_code'];
    }
    
    return $eventData;
}

// Build response
$response = [
    'conversion' => [
        'id' => $conversion['id'],
        'click_id' => $conversion['click_id'],
        'campaign_name' => $conversion['campaign_name'] ?? 'N/A',
        'click_ts' => $conversion['click_ts'],
        'conversion_ts' => $conversion['ts'],
        'ip' => $conversion['ip'],
        'ua' => $conversion['ua'],
        'referrer' => $conversion['referrer'],
        'ip_version' => !empty($conversion['ip']) ? (filter_var($conversion['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'IPv6' : 'IPv4') : null,
    ],
    'integration' => [
        'id' => $conversion['integration_id'],
        'name' => $conversion['integration_name'] ?? null,
        'pixel_id' => $conversion['pixel_id'] ?? null,
        'access_token_set' => !empty($conversion['access_token']),
        'test_code' => $conversion['test_code'] ?? null,
        'event_type' => $conversion['event_type'] ?? 'Purchase',
    ],
    'fbc_info' => [
        'fbc_cookie' => $fbcCookie,
        'fbc_cookie_source' => $fbcCookieSource,
        'fbclid' => $fbclid,
        'fbclid_source' => $fbclidSource,
    ],
    'click_extra_json' => $clickExtra,
    'postback_logs' => $logs,
];

echo json_encode($response, JSON_PRETTY_PRINT);
$db->close();
?>

