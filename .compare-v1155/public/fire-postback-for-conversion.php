<?php
/**
 * Fire postbacks for a specific conversion ID
 * Usage: ?conversion_id=13
 *
 * SECURITY: Requires authentication - only logged-in users can access this endpoint
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

use SimpleKuma\Auth\Auth;

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($db->connect_error) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body><h1>Database connection failed</h1></body></html>';
    exit;
}

$auth = new Auth($db);
if (!$auth->isAuthenticated()) {
    header('Location: index.php?page=login');
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Fire Postback for Conversion</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 40px auto; padding: 20px; }
        .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 12px; border-radius: 4px; margin: 10px 0; }
        .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 12px; border-radius: 4px; margin: 10px 0; }
        .info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; padding: 12px; border-radius: 4px; margin: 10px 0; }
        .warning { background: #fff3cd; border: 1px solid #ffc107; color: #856404; padding: 12px; border-radius: 4px; margin: 10px 0; }
        pre { background: #f4f4f4; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
        th { background: #f5f5f5; font-weight: 600; }
    </style>
</head>
<body>
    <h2>Fire Postback for Conversion</h2>
    
    <?php
    $conversionId = isset($_GET['conversion_id']) ? (int)$_GET['conversion_id'] : null;
    $forceBypass = isset($_GET['force']) && (int)$_GET['force'] === 1;
    
    if (!$conversionId) {
        echo "<div class='error'>❌ Please provide a conversion_id parameter. Example: ?conversion_id=13</div>";
        echo "<div class='info'>";
        echo "<h3>Recent Conversions (Last 10):</h3>";
        
        $recentStmt = $db->prepare(
            "SELECT c.id, c.click_id, c.value, c.currency, c.status, c.ts,
                    (SELECT COUNT(*) FROM postback_logs pl WHERE pl.conversion_id = c.id) as postback_count
             FROM conversions c
             ORDER BY c.ts DESC
             LIMIT 10"
        );
        
        if ($recentStmt) {
            $recentStmt->execute();
            $recentResult = $recentStmt->get_result();
            
            echo "<table>";
            echo "<thead><tr><th>Conversion ID</th><th>Click ID</th><th>Value</th><th>Status</th><th>Timestamp</th><th>Postback Logs</th><th>Action</th></tr></thead>";
            echo "<tbody>";
            
            while ($row = $recentResult->fetch_assoc()) {
                $postbackCount = $row['postback_count'] ?? 0;
                $hasPostbacks = $postbackCount > 0;
                
                echo "<tr>";
                echo "<td><strong>" . htmlspecialchars($row['id']) . "</strong></td>";
                echo "<td style='font-family: monospace; font-size: 11px;'>" . htmlspecialchars($row['click_id']) . "</td>";
                echo "<td>" . htmlspecialchars($row['value'] ?? '—') . " " . htmlspecialchars($row['currency'] ?? '') . "</td>";
                echo "<td>" . htmlspecialchars($row['status'] ?? '—') . "</td>";
                echo "<td>" . htmlspecialchars($row['ts']) . "</td>";
                echo "<td>";
                if ($hasPostbacks) {
                    echo "<span style='color: #1e8449;'>✓ {$postbackCount} log(s)</span>";
                } else {
                    echo "<span style='color: #d32f2f;'>✗ No logs</span>";
                }
                echo "</td>";
                echo "<td>";
                if (!$hasPostbacks) {
                    echo "<a href='?conversion_id=" . htmlspecialchars($row['id']) . "' style='color: #3d5a26; font-weight: 600;'>Fire Postbacks</a>";
                    echo " | <a href='?conversion_id=" . htmlspecialchars($row['id']) . "&force=1' style='color: #666; font-size: 11px;' title='Ignore minimum payout filter'>Force</a>";
                } else {
                    echo "<a href='?conversion_id=" . htmlspecialchars($row['id']) . "' style='color: #666;'>Re-fire</a>";
                    echo " | <a href='?conversion_id=" . htmlspecialchars($row['id']) . "&force=1' style='color: #666; font-size: 11px;' title='Ignore minimum payout filter'>Force</a>";
                }
                echo "</td>";
                echo "</tr>";
            }
            
            echo "</tbody>";
            echo "</table>";
            $recentStmt->close();
        }
        
        echo "</div>";
        $db->close();
        exit;
    }
    
    // Get conversion details
    $convStmt = $db->prepare(
        "SELECT c.*, cl.campaign_id, cp.facebook_capi_integration_id, cp.name as campaign_name
         FROM conversions c
         INNER JOIN clicks cl ON c.click_id = cl.click_id
         INNER JOIN campaigns cp ON cl.campaign_id = cp.id
         WHERE c.id = ?"
    );
    
    if (!$convStmt) {
        echo "<div class='error'>❌ Failed to prepare query: " . $db->error . "</div>";
        $db->close();
        exit;
    }
    
    $convIdStr = (string)$conversionId;
    $convStmt->bind_param('s', $convIdStr);
    $convStmt->execute();
    $convResult = $convStmt->get_result();
    $conversion = $convResult->fetch_assoc();
    $convStmt->close();
    
    if (!$conversion) {
        echo "<div class='error'>❌ Conversion ID {$conversionId} not found.</div>";
        $db->close();
        exit;
    }
    
    echo "<div class='info'>";
    echo "<h3>Conversion Details:</h3>";
    echo "<strong>Conversion ID:</strong> " . htmlspecialchars($conversion['id']) . "<br>";
    echo "<strong>Click ID:</strong> <code>" . htmlspecialchars($conversion['click_id']) . "</code><br>";
    echo "<strong>Campaign:</strong> " . htmlspecialchars($conversion['campaign_name'] ?? 'N/A') . " (ID: " . htmlspecialchars($conversion['campaign_id']) . ")<br>";
    echo "<strong>Facebook CAPI Integration:</strong> " . ($conversion['facebook_capi_integration_id'] ? '✅ Configured' : '❌ Not configured') . "<br>";
    echo "<strong>Value:</strong> " . htmlspecialchars($conversion['value'] ?? '—') . " " . htmlspecialchars($conversion['currency'] ?? '') . "<br>";
    echo "<strong>Status:</strong> " . htmlspecialchars($conversion['status'] ?? '—') . "<br>";
    echo "<strong>Timestamp:</strong> " . htmlspecialchars($conversion['ts']) . "<br>";
    echo "</div>";
    
    // Check existing postback logs
    $logCheckStmt = $db->prepare("SELECT COUNT(*) as count FROM postback_logs WHERE conversion_id = ?");
    $logCheckStmt->bind_param('s', $convIdStr);
    $logCheckStmt->execute();
    $logCheckResult = $logCheckStmt->get_result();
    $logCheck = $logCheckResult->fetch_assoc();
    $existingLogCount = $logCheck['count'] ?? 0;
    $logCheckStmt->close();
    
    if ($existingLogCount > 0) {
        echo "<div class='warning'>⚠️ This conversion already has {$existingLogCount} postback log(s). Firing again will create additional logs.</div>";
    }
    
    if ($forceBypass) {
        echo "<div class='info'>Force mode: minimum payout filter bypassed.</div>";
    }
    
    // Fire postbacks
    echo "<h3>Firing Postbacks...</h3>";
    
    try {
        $dispatcher = new \SimpleKuma\Tracking\PostbackDispatcher($db);
        $dispatcher->firePostbacks($conversionId, $forceBypass);
        
        echo "<div class='success'>✅ Postbacks fired successfully!</div>";
        
        // Wait a moment for logging
        sleep(1);
        
        // Check new postback logs
        $newLogStmt = $db->prepare(
            "SELECT pl.*, c.click_id as conversion_click_id
             FROM postback_logs pl
             LEFT JOIN conversions c ON pl.conversion_id = c.id
             WHERE pl.conversion_id = ?
             ORDER BY pl.created_at DESC
             LIMIT 10"
        );
        $newLogStmt->bind_param('s', $convIdStr);
        $newLogStmt->execute();
        $newLogResult = $newLogStmt->get_result();
        
        $logs = [];
        while ($row = $newLogResult->fetch_assoc()) {
            $logs[] = $row;
        }
        $newLogStmt->close();
        
        if (!empty($logs)) {
            echo "<h3>Postback Logs (" . count($logs) . " total):</h3>";
            echo "<table>";
            echo "<thead>";
            echo "<tr>";
            echo "<th>ID</th>";
            echo "<th>Type</th>";
            echo "<th>HTTP Code</th>";
            echo "<th>Success</th>";
            echo "<th>Attempt</th>";
            echo "<th>Created At</th>";
            echo "<th>Click ID</th>";
            echo "</tr>";
            echo "</thead>";
            echo "<tbody>";
            
            foreach ($logs as $log) {
                $statusColor = $log['success'] ? '#1e8449' : '#d32f2f';
                $statusText = $log['success'] ? '✓' : '✗';
                
                echo "<tr>";
                echo "<td>" . htmlspecialchars($log['id']) . "</td>";
                echo "<td>" . htmlspecialchars($log['postback_type']) . "</td>";
                echo "<td style='color: {$statusColor}; font-weight: 600;'>" . htmlspecialchars($log['http_code'] ?: '—') . "</td>";
                echo "<td style='color: {$statusColor};'>{$statusText}</td>";
                echo "<td>" . htmlspecialchars($log['attempt_number']) . "</td>";
                echo "<td>" . htmlspecialchars($log['created_at']) . "</td>";
                echo "<td style='font-family: monospace; font-size: 11px;'>" . htmlspecialchars($log['click_id'] ?: ($log['conversion_click_id'] ?: '—')) . "</td>";
                echo "</tr>";
            }
            
            echo "</tbody>";
            echo "</table>";
        } else {
            echo "<div class='warning'>⚠️ No postback logs found. Check PHP error log for details.</div>";
        }
        
    } catch (Exception $e) {
        echo "<div class='error'>❌ Error firing postbacks: " . htmlspecialchars($e->getMessage()) . "</div>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    }
    
    $db->close();
    ?>
    
    <div style="margin-top: 30px;">
        <a href="?">← Back to Conversion List</a> | 
        <a href="index.php?page=settings&tab=postback-urls">Settings → Postback Diagnostics</a>
    </div>
</body>
</html>

