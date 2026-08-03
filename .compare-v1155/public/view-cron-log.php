<?php
/**
 * View Cron Log
 * Displays the actual log file from the cron script execution
 * SECURITY: Requires authentication - only logged-in users can access
 */

declare(strict_types=1);

$baseDir = dirname(__DIR__);
$logFile = null;
$db = null;
$userTimezone = 'UTC';
$utcDateFrom = null;
$utcDateTo = null;
$todayInUserTz = null;
$costAggregator = null;
$bootstrapError = null;

try {
    // Resolve log path (same logic as Logger: storage/logs first, then public/logs)
    $storageLogsPath = $baseDir . '/storage/logs/fb_cost_updater.log';
    $publicLogsPath = $baseDir . '/public/logs/fb_cost_updater.log';
    if (file_exists($storageLogsPath)) {
        $logFile = $storageLogsPath;
    } elseif (file_exists($publicLogsPath)) {
        $logFile = $publicLogsPath;
    } else {
        // Use Logger's default path when file doesn't exist yet
        $defaultLogDir = $baseDir . '/storage/logs';
        $fallbackLogDir = $baseDir . '/public/logs';
        if (is_dir($defaultLogDir) && is_writable($defaultLogDir)) {
            $logFile = $defaultLogDir . '/fb_cost_updater.log';
        } else {
            $logFile = $fallbackLogDir . '/fb_cost_updater.log';
        }
    }

    // Initialize database connection for cost breakdown
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../config/config.php';
    $db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

    // Require authentication - SECURITY: Only logged-in users can view cron logs
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $auth = new \SimpleKuma\Auth\Auth($db);
    if (!$auth->isAuthenticated()) {
        header('Location: login.php');
        exit;
    }
    $permission = $auth->getPermission();
    $legacyNoRoles = empty($_SESSION['role_ids'] ?? [])
        && \SimpleKuma\Auth\Auth::allowsLegacyNoRolesFallback();
    if ($permission && !$permission->hasPermission(\SimpleKuma\Auth\Permission::PERM_SETTINGS_VIEW) && !$legacyNoRoles) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }

    // Get timezone from logged-in user (matches dashboard / api-campaign-stats)
    $currentUser = $auth->getCurrentUser();
    $userTimezone = ($currentUser && isset($currentUser['timezone'])) ? $currentUser['timezone'] : 'UTC';
    $timezoneMap = [
        'PT' => 'America/Los_Angeles', 'PST' => 'America/Los_Angeles', 'PDT' => 'America/Los_Angeles',
        'ET' => 'America/New_York', 'EST' => 'America/New_York', 'EDT' => 'America/New_York',
        'CT' => 'America/Chicago', 'CST' => 'America/Chicago', 'CDT' => 'America/Chicago',
        'MT' => 'America/Denver', 'MST' => 'America/Denver', 'MDT' => 'America/Denver',
    ];
    if (isset($timezoneMap[$userTimezone])) {
        $userTimezone = $timezoneMap[$userTimezone];
    }
    try {
        $userTimezone = (new DateTimeZone($userTimezone))->getName();
    } catch (Exception $e) {
        $userTimezone = 'UTC';
    }

    // Calculate "Today" date range (matching dashboard logic)
    require_once __DIR__ . '/../src/Utils/Formatter.php';
    $todayInUserTz = \SimpleKuma\Utils\Formatter::getTodayInTimezone($userTimezone);
    $utcDateRange = \SimpleKuma\Utils\Formatter::convertDateRangeToUTC($todayInUserTz, $todayInUserTz, $userTimezone);
    $utcDateFrom = $utcDateRange['from'];
    $utcDateTo = $utcDateRange['to'];

    // Initialize cost aggregator
    require_once __DIR__ . '/../src/Facebook/FacebookCostAggregator.php';
    $costAggregator = new \SimpleKuma\Facebook\FacebookCostAggregator($db);
} catch (Throwable $e) {
    $bootstrapError = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>View Cron Log</title>
    <meta charset="utf-8">
    <style>
        body {
            font-family: 'Courier New', monospace;
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
            margin: 0;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: #252526;
            border-radius: 8px;
            padding: 20px;
        }
        h1 {
            color: #4ec9b0;
            border-bottom: 2px solid #4ec9b0;
            padding-bottom: 10px;
        }
        .log-content {
            background: #1e1e1e;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
            white-space: pre-wrap;
            font-size: 13px;
            line-height: 1.6;
            max-height: 80vh;
            overflow-y: auto;
        }
        .error {
            color: #f48771;
        }
        .success {
            color: #4ec9b0;
        }
        .warning {
            color: #dcdcaa;
        }
        .info {
            color: #569cd6;
        }
        .timestamp {
            color: #858585;
        }
        .file-info {
            background: #2d2d30;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
            color: #cccccc;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📋 Facebook Cost Updater Log</h1>
        
        <?php
        // Diagnostic: always show state (helps debug blank content)
        $fileExists = ($logFile !== null && file_exists($logFile));
        echo "<div class='file-info' style='margin-bottom: 15px; font-size: 12px;'>";
        echo "<strong>Status:</strong> " . ($bootstrapError !== null ? "Bootstrap failed" : "OK") . " | ";
        echo "<strong>Log path:</strong> " . htmlspecialchars($logFile ?? 'null') . " | ";
        echo "<strong>File exists:</strong> " . ($fileExists ? "Yes" : "No");
        echo "</div>";

        if ($bootstrapError !== null) {
            echo "<div class='file-info' style='border-left: 4px solid #f48771;'>";
            echo "<strong style='color: #f48771;'>❌ Bootstrap Error (page could not initialize):</strong><br><br>";
            echo "<div class='error' style='margin-top: 8px;'>" . htmlspecialchars($bootstrapError) . "</div>";
            echo "<div style='margin-top: 12px; font-size: 12px; color: #858585;'>Check config, database connection, and that required files exist.</div>";
            echo "</div>";
        } elseif (!file_exists($logFile)) {
            echo "<div class='file-info'>";
            echo "❌ Log file not found: " . htmlspecialchars($logFile);
            echo "<br>📍 Checked: storage/logs and public/logs (Logger uses whichever is writable)";
            echo "<br><br>This means the cron script hasn't run yet or hasn't created the log file.";
            echo "</div>";
        } else {
            $fileSize = filesize($logFile);
            $lastModified = filemtime($logFile);
            try {
                // For large files, read only last ~500KB to avoid memory exhaustion
                $maxReadBytes = 512 * 1024;
                if ($fileSize > $maxReadBytes) {
                    $fh = fopen($logFile, 'rb');
                    fseek($fh, -$maxReadBytes, SEEK_END);
                    $content = fread($fh, $maxReadBytes);
                    fclose($fh);
                } else {
                    $content = file_get_contents($logFile);
                }
            } catch (Throwable $e) {
                echo "<div class='file-info' style='border-left: 4px solid #f48771;'>";
                echo "<strong style='color: #f48771;'>❌ Error reading log file:</strong> " . htmlspecialchars($e->getMessage());
                echo "</div>";
                $content = '';
            }
            $lines = explode("\n", $content);
            $last100Lines = array_slice($lines, -250); // Show last 250 lines
            
            echo "<div class='file-info'>";
            echo "📁 File: " . htmlspecialchars($logFile) . "<br>";
            echo "📍 Reading from: " . (strpos($logFile, 'public/logs') !== false ? 'public/logs' : 'storage/logs') . "<br>";
            echo "📊 Size: " . number_format($fileSize) . " bytes<br>";
            echo "🕐 Last Modified: " . date('Y-m-d H:i:s', $lastModified) . " (" . round((time() - $lastModified) / 60, 1) . " minutes ago)<br>";
            echo "📝 Showing last 250 lines (scroll down for more)";
            echo "</div>";
            
            // Filter for key entries
            $keyEntries = [];
            $allEntries = [];
            foreach ($lines as $line) {
                if (!empty(trim($line))) {
                    $allEntries[] = $line;
                    // Look for important entries
                    if (preg_match('/adset|ad.*insights|querying directly|no spend|error|exception|processed|upserted|completed|Facebook API|Raw response|Pagination|Today in|timezone|SIMPLIFIED|Pre-call setup|cURL setup|Response received|Response received from makeApiCall|Making.*API call|Making pagination call|cURL Error|HTTP Error|API Error|JSON Decode|Breaking pagination loop/i', $line)) {
                        $keyEntries[] = $line;
                    }
                }
            }
            
            echo "<div style='margin-bottom: 15px; padding: 10px; background: #2d2d30; border-radius: 4px;'>";
            echo "<strong style='color: #4ec9b0;'>🔍 Key Entries (filtered):</strong><br>";
            echo "<div class='log-content' style='max-height: 300px; font-size: 12px;'>";
            foreach (array_slice($keyEntries, -50) as $line) {
                $line = htmlspecialchars($line);
                if (preg_match('/cURL Error|HTTP Error|API Error|JSON Decode Error|Breaking pagination loop.*invalid response/i', $line)) {
                    $line = "<span class='error'>$line</span>";
                } elseif (preg_match('/error|failed|exception/i', $line)) {
                    $line = "<span class='error'>$line</span>";
                } elseif (preg_match('/success|completed|processed|upserted|SIMPLIFIED|Response received.*successful|JSON decode successful/i', $line)) {
                    $line = "<span class='success'>$line</span>";
                } elseif (preg_match('/warning|WARNING|Rate Limit|Retrying|no spend|skipping|exiting/i', $line)) {
                    $line = "<span class='warning'>$line</span>";
                } elseif (preg_match('/Pre-call setup|cURL setup|Response received|Making.*API call|Making pagination call|Facebook API|Raw response|Pagination|Today in|timezone/i', $line)) {
                    $line = "<span class='info'>$line</span>";
                }
                echo $line . "\n";
            }
            echo "</div>";
            echo "</div>";
            
            echo "<div class='log-content'>";
            foreach ($last100Lines as $line) {
                $line = htmlspecialchars($line);
                
                // Color code based on content
                if (preg_match('/cURL Error|HTTP Error|API Error|JSON Decode Error|Breaking pagination loop.*invalid response/i', $line)) {
                    $line = "<span class='error'>$line</span>";
                } elseif (preg_match('/error|failed|exception/i', $line)) {
                    $line = "<span class='error'>$line</span>";
                } elseif (preg_match('/success|completed|found|processed|SIMPLIFIED|Response received.*successful|JSON decode successful/i', $line)) {
                    $line = "<span class='success'>$line</span>";
                } elseif (preg_match('/warning|WARNING|Rate Limit|Retrying|no.*found|exiting/i', $line)) {
                    $line = "<span class='warning'>$line</span>";
                } elseif (preg_match('/Pre-call setup|cURL setup|Response received|Making.*API call|Making pagination call|Facebook API|Raw response|Pagination|Today in|timezone/i', $line)) {
                    $line = "<span class='info'>$line</span>";
                } elseif (preg_match('/\[.*\]/', $line)) {
                    // Timestamp
                    $line = preg_replace('/\[([^\]]+)\]/', '<span class="timestamp">[$1]</span>', $line);
                } else {
                    $line = "<span class='info'>$line</span>";
                }
                
                echo $line . "\n";
            }
            echo "</div>";
            
            // Show key indicators
            echo "<div style='margin-top: 20px; padding: 15px; background: #2d2d30; border-radius: 4px;'>";
            echo "<h3 style='color: #4ec9b0; margin-top: 0;'>🔍 Key Indicators:</h3>";
            
            $hasErrors = preg_match('/error|failed|exception/i', $content);
            $hasExits = preg_match('/exiting|exit\(0\)/i', $content);
            $hasNoTrafficSources = preg_match('/no.*facebook.*traffic.*sources/i', $content);
            $hasNoAdAccounts = preg_match('/no.*campaigns.*with.*ad.*accounts/i', $content);
            $hasProcessed = preg_match('/processed|successfully|upserted/i', $content);
            
            if ($hasExits || $hasNoTrafficSources || $hasNoAdAccounts) {
                echo "<div style='color: #f48771; margin: 10px 0;'>";
                echo "⚠️ <strong>Script exited early or found no data to process</strong><br>";
                if ($hasNoTrafficSources) {
                    echo "   - No Facebook traffic sources found<br>";
                }
                if ($hasNoAdAccounts) {
                    echo "   - No campaigns with ad accounts found (check: clicks in last 7 days?)<br>";
                }
                echo "</div>";
            }
            
            if ($hasProcessed) {
                echo "<div style='color: #4ec9b0; margin: 10px 0;'>";
                echo "✅ <strong>Script processed some data</strong><br>";
                echo "</div>";
            }
            
            if ($hasErrors) {
                echo "<div style='color: #f48771; margin: 10px 0;'>";
                echo "❌ <strong>Errors detected in log</strong><br>";
                echo "</div>";
            }
            
            echo "</div>";
        }

        if ($bootstrapError === null):
        // ============================================
        // COST BREAKDOWN REPORT
        // ============================================
        echo "<hr style='margin: 40px 0; border-color: #4ec9b0;'>";
        echo "<div style='margin-top: 30px; padding: 20px; background: #2d2d30; border-radius: 4px;'>";
        echo "<h2 style='color: #4ec9b0; margin-top: 0; border-bottom: 2px solid #4ec9b0; padding-bottom: 10px;'>💰 Cost Breakdown Report - How Dashboard Calculates Total Cost</h2>";
        
        // Get dashboard total cost (exactly as dashboard does)
        try {
            // Try calling with null filter first (standard way)
            try {
                $dashboardTotal = $costAggregator->getAggregatedCost($utcDateFrom, $utcDateTo, null, [], $userTimezone);
            } catch (Exception $e) {
                // If bind parameter mismatch occurs, this is a known issue with getAggregatedCost when filter is null
                // The method has a bug where it binds more parameters than placeholders exist
                if (strpos($e->getMessage(), 'Bind parameter mismatch') !== false) {
                    // Try with empty string as fallback
                    try {
                        $dashboardTotal = $costAggregator->getAggregatedCost($utcDateFrom, $utcDateTo, '', [], $userTimezone);
                    } catch (Exception $e2) {
                        // If that also fails, show a helpful error message
                        throw new Exception("Unable to generate cost breakdown: Bind parameter mismatch in getAggregatedCost() when no filter is applied. This is a known issue in the cost aggregator. Error: " . $e->getMessage());
                    }
                } else {
                    throw $e; // Re-throw if it's a different error
                }
            }
            
            echo "<div style='background: #1e1e1e; padding: 15px; border-radius: 4px; margin-bottom: 20px;'>";
            echo "<h3 style='color: #4ec9b0; margin-top: 0;'>📊 Summary</h3>";
            echo "<p><strong style='color: #dcdcaa;'>User Timezone:</strong> " . htmlspecialchars($userTimezone) . "</p>";
            echo "<p><strong style='color: #dcdcaa;'>Today in User Timezone:</strong> " . htmlspecialchars($todayInUserTz) . "</p>";
            echo "<p><strong style='color: #dcdcaa;'>UTC Date Range:</strong> " . htmlspecialchars($utcDateFrom) . " to " . htmlspecialchars($utcDateTo) . "</p>";
            echo "<p style='font-size: 18px; margin-top: 15px;'><strong style='color: #4ec9b0;'>Dashboard Total Cost:</strong> <span style='color: #4ec9b0; font-size: 24px; font-weight: bold;'>$" . number_format($dashboardTotal, 2) . "</span></p>";
            echo "</div>";
            
            // 1. Manual Costs
            $manualCostQuery = $db->prepare("SELECT COALESCE(SUM(cost), 0) as total_cost FROM clicks WHERE ts >= ? AND ts <= ?");
            $manualCostQuery->bind_param('ss', $utcDateFrom, $utcDateTo);
            $manualCostQuery->execute();
            $manualResult = $manualCostQuery->get_result()->fetch_assoc();
            $manualCost = (float)($manualResult['total_cost'] ?? 0);
            
            // 2. Get all cost records in date range
            $allCostsQuery = $db->prepare("
                SELECT DISTINCT 
                    as_cost.adset_id,
                    as_cost.date,
                    as_cost.hour,
                    as_cost.delta_spend,
                    as_cost.spend,
                    as_cost.ad_account_id,
                    as_cost.last_synced
                FROM adset_hourly_costs as_cost
                WHERE CONCAT(as_cost.date, ' ', LPAD(as_cost.hour, 2, '0'), ':00:00') >= ? 
                    AND CONCAT(as_cost.date, ' ', LPAD(as_cost.hour, 2, '0'), ':00:00') <= ?
                    AND as_cost.delta_spend > 0
                    AND as_cost.adset_id NOT LIKE '{{%' AND as_cost.adset_id NOT LIKE '{ts:%'
                ORDER BY as_cost.date DESC, as_cost.hour DESC, as_cost.delta_spend DESC
            ");
            $allCostsQuery->bind_param('ss', $utcDateFrom, $utcDateTo);
            $allCostsQuery->execute();
            $allCosts = $allCostsQuery->get_result()->fetch_all(MYSQLI_ASSOC);
            
            // 3. Calculate matched costs (costs allocated to clicks)
            $matchedCosts = 0.0;
            $matchedCostsByCampaign = [];
            
            // Get clicks with valid adset/ad IDs
            $clicksWithCostsQuery = $db->prepare("
                SELECT 
                    DATE(cl.ts) as click_date,
                    HOUR(cl.ts) as click_hour,
                    JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.ad_id')) as ad_id,
                    JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.adset_id')) as adset_id,
                    cl.campaign_id
                FROM clicks cl
                WHERE cl.ts >= ? AND cl.ts <= ?
                    AND JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.ad_id')) IS NOT NULL
                    AND JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.ad_id')) != ''
                    AND JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.ad_id')) != 'null'
                    AND JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.adset_id')) IS NOT NULL
                    AND JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.adset_id')) != ''
                    AND JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.adset_id')) != 'null'
                    AND JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.ad_id')) NOT LIKE '{{%'
                    AND JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.adset_id')) NOT LIKE '{ts:%'
            ");
            $clicksWithCostsQuery->bind_param('ss', $utcDateFrom, $utcDateTo);
            $clicksWithCostsQuery->execute();
            $clicksWithCosts = $clicksWithCostsQuery->get_result()->fetch_all(MYSQLI_ASSOC);
            
            $processedCombos = [];
            foreach ($clicksWithCosts as $click) {
                $clickDate = $click['click_date'];
                $clickHour = (int)$click['click_hour'];
                $adId = $click['ad_id'];
                $adsetId = $click['adset_id'];
                $campaignId = (int)$click['campaign_id'];
                
                // Try ad cost first
                $adCostKey = "ad_{$adId}_{$clickDate}_{$clickHour}";
                if (!isset($processedCombos[$adCostKey])) {
                    $adCostQuery = $db->prepare("SELECT delta_spend FROM ad_hourly_costs WHERE ad_id = ? AND date = ? AND hour = ? LIMIT 1");
                    $adCostQuery->bind_param('ssi', $adId, $clickDate, $clickHour);
                    $adCostQuery->execute();
                    $adCostResult = $adCostQuery->get_result()->fetch_assoc();
                    
                    if ($adCostResult && $adCostResult['delta_spend'] > 0) {
                        // Count all clicks for this ad/date/hour
                        $allClicksQuery = $db->prepare("
                            SELECT COUNT(*) as total_clicks
                            FROM clicks
                            WHERE JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.ad_id')) = ?
                                AND DATE(ts) = ? AND HOUR(ts) = ?
                                AND JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.ad_id')) NOT LIKE '{{%'
                                AND JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.ad_id')) NOT LIKE '{ts:%'
                        ");
                        $allClicksQuery->bind_param('ssi', $adId, $clickDate, $clickHour);
                        $allClicksQuery->execute();
                        $allClicksResult = $allClicksQuery->get_result()->fetch_assoc();
                        $totalClicks = max((int)($allClicksResult['total_clicks'] ?? 1), 1);
                        
                        // Count campaign clicks for this ad/date/hour
                        $campaignClicksQuery = $db->prepare("
                            SELECT COUNT(*) as campaign_clicks
                            FROM clicks
                            WHERE JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.ad_id')) = ?
                                AND DATE(ts) = ? AND HOUR(ts) = ?
                                AND campaign_id = ?
                                AND JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.ad_id')) NOT LIKE '{{%'
                                AND JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.ad_id')) NOT LIKE '{ts:%'
                        ");
                        $campaignClicksQuery->bind_param('ssii', $adId, $clickDate, $clickHour, $campaignId);
                        $campaignClicksQuery->execute();
                        $campaignClicksResult = $campaignClicksQuery->get_result()->fetch_assoc();
                        $campaignClicks = (int)($campaignClicksResult['campaign_clicks'] ?? 0);
                        
                        $costPerClick = $adCostResult['delta_spend'] / $totalClicks;
                        $campaignCost = $costPerClick * $campaignClicks;
                        $matchedCosts += $campaignCost;
                        
                        if (!isset($matchedCostsByCampaign[$campaignId])) {
                            $matchedCostsByCampaign[$campaignId] = 0;
                        }
                        $matchedCostsByCampaign[$campaignId] += $campaignCost;
                        
                        $processedCombos[$adCostKey] = true;
                        continue;
                    }
                }
                
                // Try adset cost if no ad cost
                $adsetCostKey = "adset_{$adsetId}_{$clickDate}_{$clickHour}";
                if (!isset($processedCombos[$adsetCostKey])) {
                    $adsetCostQuery = $db->prepare("SELECT delta_spend FROM adset_hourly_costs WHERE adset_id = ? AND date = ? AND hour = ? LIMIT 1");
                    $adsetCostQuery->bind_param('ssi', $adsetId, $clickDate, $clickHour);
                    $adsetCostQuery->execute();
                    $adsetCostResult = $adsetCostQuery->get_result()->fetch_assoc();
                    
                    if ($adsetCostResult && $adsetCostResult['delta_spend'] > 0) {
                        // Count all clicks for this adset/date/hour
                        $allClicksQuery = $db->prepare("
                            SELECT COUNT(*) as total_clicks
                            FROM clicks
                            WHERE JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.adset_id')) = ?
                                AND DATE(ts) = ? AND HOUR(ts) = ?
                                AND JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.adset_id')) NOT LIKE '{{%'
                                AND JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.adset_id')) NOT LIKE '{ts:%'
                        ");
                        $allClicksQuery->bind_param('ssi', $adsetId, $clickDate, $clickHour);
                        $allClicksQuery->execute();
                        $allClicksResult = $allClicksQuery->get_result()->fetch_assoc();
                        $totalClicks = max((int)($allClicksResult['total_clicks'] ?? 1), 1);
                        
                        // Count campaign clicks for this adset/date/hour
                        $campaignClicksQuery = $db->prepare("
                            SELECT COUNT(*) as campaign_clicks
                            FROM clicks
                            WHERE JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.adset_id')) = ?
                                AND DATE(ts) = ? AND HOUR(ts) = ?
                                AND campaign_id = ?
                                AND JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.adset_id')) NOT LIKE '{{%'
                                AND JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.adset_id')) NOT LIKE '{ts:%'
                        ");
                        $campaignClicksQuery->bind_param('ssii', $adsetId, $clickDate, $clickHour, $campaignId);
                        $campaignClicksQuery->execute();
                        $campaignClicksResult = $campaignClicksQuery->get_result()->fetch_assoc();
                        $campaignClicks = (int)($campaignClicksResult['campaign_clicks'] ?? 0);
                        
                        $costPerClick = $adsetCostResult['delta_spend'] / $totalClicks;
                        $campaignCost = $costPerClick * $campaignClicks;
                        $matchedCosts += $campaignCost;
                        
                        if (!isset($matchedCostsByCampaign[$campaignId])) {
                            $matchedCostsByCampaign[$campaignId] = 0;
                        }
                        $matchedCostsByCampaign[$campaignId] += $campaignCost;
                        
                        $processedCombos[$adsetCostKey] = true;
                    }
                }
            }
            
            // 4. Calculate unmatched costs (costs without matching clicks but with clicks in SOME campaign)
            $unmatchedCostsQuery = $db->prepare("
                SELECT COALESCE(SUM(delta_spend), 0) as unmatched_cost
                FROM (
                    SELECT DISTINCT as_cost.adset_id, as_cost.date, as_cost.hour, as_cost.delta_spend
                    FROM adset_hourly_costs as_cost
                    WHERE CONCAT(as_cost.date, ' ', LPAD(as_cost.hour, 2, '0'), ':00:00') >= ? 
                        AND CONCAT(as_cost.date, ' ', LPAD(as_cost.hour, 2, '0'), ':00:00') <= ?
                        AND as_cost.delta_spend > 0
                        AND as_cost.adset_id NOT LIKE '{{%' AND as_cost.adset_id NOT LIKE '{ts:%'
                        AND NOT EXISTS (
                            SELECT 1 FROM clicks c
                            INNER JOIN campaigns camp ON c.campaign_id = camp.id
                            LEFT JOIN facebook_marketing_ad_accounts fmaa ON camp.facebook_marketing_ad_account_id = fmaa.id
                            WHERE JSON_UNQUOTE(JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.adset_id')) = as_cost.adset_id
                                AND JSON_UNQUOTE(JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.adset_id')) IS NOT NULL
                                AND JSON_UNQUOTE(JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.adset_id')) != ''
                                AND JSON_UNQUOTE(JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.adset_id')) != 'null'
                                AND JSON_UNQUOTE(JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.ad_id')) IS NOT NULL
                                AND JSON_UNQUOTE(JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.ad_id')) != ''
                                AND JSON_UNQUOTE(JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.ad_id')) != 'null'
                                AND DATE(c.ts) = as_cost.date
                                AND HOUR(c.ts) = as_cost.hour
                                AND c.ts >= ? AND c.ts <= ?
                                AND (fmaa.facebook_marketing_integration_id IS NULL OR as_cost.ad_account_id = fmaa.facebook_marketing_integration_id)
                        )
                        AND EXISTS (
                            SELECT 1 FROM clicks c2
                            WHERE JSON_UNQUOTE(JSON_EXTRACT(c2.extra_json, '$.traffic_source_tokens.adset_id')) = as_cost.adset_id
                                AND JSON_UNQUOTE(JSON_EXTRACT(c2.extra_json, '$.traffic_source_tokens.adset_id')) IS NOT NULL
                                AND JSON_UNQUOTE(JSON_EXTRACT(c2.extra_json, '$.traffic_source_tokens.adset_id')) != ''
                                AND JSON_UNQUOTE(JSON_EXTRACT(c2.extra_json, '$.traffic_source_tokens.adset_id')) != 'null'
                                AND JSON_UNQUOTE(JSON_EXTRACT(c2.extra_json, '$.traffic_source_tokens.ad_id')) IS NOT NULL
                                AND JSON_UNQUOTE(JSON_EXTRACT(c2.extra_json, '$.traffic_source_tokens.ad_id')) != ''
                                AND JSON_UNQUOTE(JSON_EXTRACT(c2.extra_json, '$.traffic_source_tokens.ad_id')) != 'null'
                                AND c2.ts >= ? AND c2.ts <= ?
                        )
                ) as unmatched
            ");
            $unmatchedCostsQuery->bind_param('ssssss', $utcDateFrom, $utcDateTo, $utcDateFrom, $utcDateTo, $utcDateFrom, $utcDateTo);
            $unmatchedCostsQuery->execute();
            $unmatchedCostsResult = $unmatchedCostsQuery->get_result()->fetch_assoc();
            $unmatchedCosts = (float)($unmatchedCostsResult['unmatched_cost'] ?? 0.0);
            
            // Calculate total from components
            $calculatedTotal = $manualCost + $matchedCosts + $unmatchedCosts;
            $difference = abs($dashboardTotal - $calculatedTotal);
            $status = $difference < 0.01 ? '✅ Match' : '⚠️ Mismatch';
            $statusColor = $difference < 0.01 ? '#4ec9b0' : '#f48771';
            
            // Display component breakdown
            echo "<div style='background: #1e1e1e; padding: 15px; border-radius: 4px; margin-bottom: 20px;'>";
            echo "<h3 style='color: #4ec9b0; margin-top: 0;'>📈 Cost Components Breakdown</h3>";
            echo "<table style='width: 100%; border-collapse: collapse; color: #d4d4d4;'>";
            echo "<tr style='background: #2d2d30;'><th style='padding: 10px; text-align: left; border-bottom: 1px solid #4ec9b0;'>Component</th><th style='padding: 10px; text-align: right; border-bottom: 1px solid #4ec9b0;'>Amount</th></tr>";
            echo "<tr><td style='padding: 8px; color: #569cd6;'>Manual Costs (from clicks.cost)</td><td style='padding: 8px; text-align: right;'>$" . number_format($manualCost, 2) . "</td></tr>";
            echo "<tr style='background: #252526;'><td style='padding: 8px; color: #4ec9b0;'>Matched Facebook Costs (allocated to clicks)</td><td style='padding: 8px; text-align: right;'>$" . number_format($matchedCosts, 2) . "</td></tr>";
            echo "<tr><td style='padding: 8px; color: #dcdcaa;'>Unmatched Facebook Costs (no exact match but has clicks)</td><td style='padding: 8px; text-align: right;'>$" . number_format($unmatchedCosts, 2) . "</td></tr>";
            echo "<tr style='background: #2d2d30; border-top: 2px solid #4ec9b0;'><td style='padding: 10px; font-weight: bold;'>Calculated Total (Sum of Components)</td><td style='padding: 10px; text-align: right; font-weight: bold;'>$" . number_format($calculatedTotal, 2) . "</td></tr>";
            echo "<tr style='background: #2d2d30;'><td style='padding: 8px;'>Dashboard Total (from getAggregatedCost)</td><td style='padding: 8px; text-align: right;'>$" . number_format($dashboardTotal, 2) . "</td></tr>";
            echo "<tr style='background: #2d2d30; border-top: 1px solid #4ec9b0;'><td style='padding: 10px; font-weight: bold; color: $statusColor;'>Status</td><td style='padding: 10px; text-align: right; font-weight: bold; color: $statusColor;'>$status</td></tr>";
            if ($difference >= 0.01) {
                echo "<tr style='background: #2d2d30;'><td style='padding: 8px; color: #f48771;'>Difference</td><td style='padding: 8px; text-align: right; color: #f48771;'>$" . number_format($difference, 2) . "</td></tr>";
            }
            echo "</table>";
            echo "</div>";
            
            // Campaign breakdown
            $allCampaignsQuery = $db->prepare("SELECT id, name FROM campaigns ORDER BY name ASC");
            $allCampaignsQuery->execute();
            $allCampaigns = $allCampaignsQuery->get_result()->fetch_all(MYSQLI_ASSOC);
            
            echo "<div style='background: #1e1e1e; padding: 15px; border-radius: 4px; margin-bottom: 20px;'>";
            echo "<h3 style='color: #4ec9b0; margin-top: 0;'>🎯 Campaign-by-Campaign Breakdown</h3>";
            echo "<table style='width: 100%; border-collapse: collapse; color: #d4d4d4; font-size: 12px;'>";
            echo "<tr style='background: #2d2d30;'><th style='padding: 8px; text-align: left; border-bottom: 1px solid #4ec9b0;'>Campaign</th><th style='padding: 8px; text-align: right; border-bottom: 1px solid #4ec9b0;'>Manual</th><th style='padding: 8px; text-align: right; border-bottom: 1px solid #4ec9b0;'>FB Cost</th><th style='padding: 8px; text-align: right; border-bottom: 1px solid #4ec9b0;'>Total</th></tr>";
            
            $sumCampaignCosts = 0;
            foreach ($allCampaigns as $camp) {
                $campaignId = (int)$camp['id'];
                
                // Get manual cost for this campaign
                $campManualQuery = $db->prepare("SELECT COALESCE(SUM(cost), 0) as manual_cost FROM clicks WHERE campaign_id = ? AND ts >= ? AND ts <= ?");
                $campManualQuery->bind_param('iss', $campaignId, $utcDateFrom, $utcDateTo);
                $campManualQuery->execute();
                $campManualResult = $campManualQuery->get_result()->fetch_assoc();
                $campManualCost = (float)($campManualResult['manual_cost'] ?? 0);
                
                // Get total cost using aggregator
                $campTotalCost = $costAggregator->getAggregatedCost($utcDateFrom, $utcDateTo, 'AND campaign_id = ?', [$campaignId], $userTimezone);
                $campFbCost = $campTotalCost - $campManualCost;
                if ($campFbCost < 0) {
                    $campFbCost = 0;
                }
                
                if ($campTotalCost > 0.01) {
                    $sumCampaignCosts += $campTotalCost;
                    echo "<tr><td style='padding: 6px;'>" . htmlspecialchars($camp['name']) . " (ID: $campaignId)</td>";
                    echo "<td style='padding: 6px; text-align: right; color: #569cd6;'>$" . number_format($campManualCost, 2) . "</td>";
                    echo "<td style='padding: 6px; text-align: right; color: #4ec9b0;'>$" . number_format($campFbCost, 2) . "</td>";
                    echo "<td style='padding: 6px; text-align: right; font-weight: bold;'>$" . number_format($campTotalCost, 2) . "</td></tr>";
                }
            }
            
            $campaignSumDifference = abs($dashboardTotal - $sumCampaignCosts);
            echo "<tr style='background: #2d2d30; border-top: 2px solid #4ec9b0;'><td style='padding: 8px; font-weight: bold;'>Sum of Campaign Costs</td><td colspan='2' style='padding: 8px;'></td><td style='padding: 8px; text-align: right; font-weight: bold;'>$" . number_format($sumCampaignCosts, 2) . "</td></tr>";
            echo "<tr style='background: #2d2d30;'><td style='padding: 8px;'>Dashboard Total</td><td colspan='2' style='padding: 8px;'></td><td style='padding: 8px; text-align: right;'>$" . number_format($dashboardTotal, 2) . "</td></tr>";
            if ($campaignSumDifference >= 0.01) {
                echo "<tr style='background: #2d2d30;'><td style='padding: 8px; color: #f48771;'>Difference (Unattributed Costs)</td><td colspan='2' style='padding: 8px;'></td><td style='padding: 8px; text-align: right; color: #f48771;'>$" . number_format($campaignSumDifference, 2) . "</td></tr>";
            }
            echo "</table>";
            echo "</div>";
            
            // ============================================
            // COST TROUBLESHOOTING: Campaign → Ad account, Adset → Campaign, per-campaign cost source
            // ============================================
            echo "<div style='margin-top: 30px; padding: 20px; background: #1e1e1e; border-radius: 4px; border: 2px solid #dcdcaa;'>";
            echo "<h2 style='color: #dcdcaa; margin-top: 0; border-bottom: 2px solid #dcdcaa; padding-bottom: 10px;'>🔧 Cost Troubleshooting – Per-Campaign & Adset Detail</h2>";
            echo "<p style='color: #858585; font-size: 12px; margin-bottom: 15px;'>Use this to see which ad account each campaign is linked to (campaign setup), which adsets belong to which campaigns (from clicks), which cost rows feed each campaign for &quot;today&quot;, and which adsets have cost but no clicks (spend-only / unattributed).</p>";
            
            // 0. Campaign → Ad account (from campaign setup) – so we can verify e.g. Solar is linked to Spicy #2
            $campaignAdAccountQuery = $db->query("
                SELECT 
                    cp.id AS campaign_id,
                    cp.name AS campaign_name,
                    cp.facebook_marketing_ad_account_id,
                    cp.facebook_marketing_campaign_id,
                    fmaa.id AS ad_account_internal_id,
                    fmaa.ad_account_name,
                    fmaa.ad_account_id AS fb_act_id,
                    fmc.campaign_name AS meta_campaign_name,
                    fmc.meta_campaign_id,
                    fmc.effective_status AS meta_campaign_status
                FROM campaigns cp
                LEFT JOIN facebook_marketing_ad_accounts fmaa ON cp.facebook_marketing_ad_account_id = fmaa.id
                LEFT JOIN facebook_marketing_campaigns fmc ON cp.facebook_marketing_campaign_id = fmc.id
                ORDER BY cp.name ASC
            ");
            $campaignAdAccountMap = [];
            $campaignAdAccountList = [];
            if ($campaignAdAccountQuery) {
                while ($row = $campaignAdAccountQuery->fetch_assoc()) {
                    $cid = (int)$row['campaign_id'];
                    $campaignAdAccountList[] = $row;
                    if (!empty($row['ad_account_internal_id'])) {
                        $campaignAdAccountMap[$cid] = [
                            'ad_account_name' => $row['ad_account_name'] ?? '—',
                            'ad_account_internal_id' => $row['ad_account_internal_id'],
                            'fb_act_id' => $row['fb_act_id'] ?? '—'
                        ];
                    }
                }
            }
            
            echo "<div style='margin-bottom: 25px;'>";
            echo "<h3 style='color: #4ec9b0; margin-top: 0;'>0. Campaign → Ad account (from campaign setup)</h3>";
            echo "<p style='color: #858585; font-size: 11px;'>Shows which Facebook ad account each campaign is linked to. Use this to verify e.g. Solar is linked to Spicy #2.</p>";
            if (!empty($campaignAdAccountList)) {
                echo "<div style='max-height: 300px; overflow-y: auto;'>";
                echo "<table style='width: 100%; border-collapse: collapse; color: #d4d4d4; font-size: 11px;'>";
                echo "<tr style='background: #2d2d30;'><th style='padding: 6px; text-align: left;'>Campaign name</th><th style='padding: 6px; text-align: left;'>Campaign ID</th><th style='padding: 6px; text-align: left;'>Ad account (name)</th><th style='padding: 6px; text-align: left;'>Meta campaign</th><th style='padding: 6px; text-align: left;'>Meta campaign ID</th><th style='padding: 6px; text-align: left;'>Ad account internal ID</th><th style='padding: 6px; text-align: left;'>Facebook act_ ID</th></tr>";
                foreach ($campaignAdAccountList as $row) {
                    $adName = $row['ad_account_name'] ?? null;
                    $adInternalId = $row['ad_account_internal_id'] ?? null;
                    $fbActId = $row['fb_act_id'] ?? null;
                    $metaName = $row['meta_campaign_name'] ?? null;
                    $metaId = $row['meta_campaign_id'] ?? null;
                    $adNameDisp = $adName !== null && $adName !== '' ? htmlspecialchars($adName) : '<span style="color:#f48771;">not set</span>';
                    $adInternalDisp = $adInternalId !== null && $adInternalId !== '' ? htmlspecialchars((string)$adInternalId) : '<span style="color:#f48771;">—</span>';
                    $fbActDisp = $fbActId !== null && $fbActId !== '' ? htmlspecialchars($fbActId) : '<span style="color:#f48771;">—</span>';
                    $metaNameDisp = $metaName !== null && $metaName !== '' ? htmlspecialchars($metaName) : '<span style="color:#858585;">not linked</span>';
                    $metaIdDisp = $metaId !== null && $metaId !== '' ? htmlspecialchars((string)$metaId) : '<span style="color:#858585;">—</span>';
                    echo "<tr><td style='padding: 4px;'>" . htmlspecialchars($row['campaign_name']) . "</td><td style='padding: 4px;'>" . (int)$row['campaign_id'] . "</td><td style='padding: 4px;'>" . $adNameDisp . "</td><td style='padding: 4px;'>" . $metaNameDisp . "</td><td style='padding: 4px; font-family: monospace;'>" . $metaIdDisp . "</td><td style='padding: 4px;'>" . $adInternalDisp . "</td><td style='padding: 4px; font-family: monospace;'>" . $fbActDisp . "</td></tr>";
                }
                echo "</table>";
                echo "</div>";
            } else {
                echo "<p style='color: #f48771;'>Could not load campaign → ad account (check facebook_marketing_ad_accounts table exists).</p>";
            }
            echo "</div>";
            
            // 1. Adset → Campaign mapping (from clicks, any time) – uses JSON_EXTRACT for compatibility
            $adsetCampaignQuery = $db->query("
                SELECT DISTINCT
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.adset_id')) AS UNSIGNED) AS adset_id,
                    c.campaign_id,
                    cp.name AS campaign_name
                FROM clicks c
                INNER JOIN campaigns cp ON c.campaign_id = cp.id
                WHERE JSON_UNQUOTE(JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.adset_id')) IS NOT NULL
                  AND JSON_UNQUOTE(JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.adset_id')) != ''
                  AND JSON_UNQUOTE(JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.adset_id')) REGEXP '^[0-9]+\$'
                ORDER BY cp.name, adset_id
            ");
            $adsetToCampaigns = [];
            if ($adsetCampaignQuery) {
                while ($row = $adsetCampaignQuery->fetch_assoc()) {
                    $aid = $row['adset_id'];
                    if (empty($aid)) continue;
                    $aid = (string)$aid;
                    if (!isset($adsetToCampaigns[$aid])) $adsetToCampaigns[$aid] = [];
                    $adsetToCampaigns[$aid][] = ['campaign_id' => (int)$row['campaign_id'], 'campaign_name' => $row['campaign_name']];
                }
            }
            
            echo "<div style='margin-bottom: 25px;'>";
            echo "<h3 style='color: #4ec9b0; margin-top: 0;'>1. Adset → Campaign(s) (from clicks, any time)</h3>";
            echo "<p style='color: #858585; font-size: 11px;'>Adsets are attributed to a campaign only if that adset has at least one click in that campaign. Unmatched cost is attributed the same way.</p>";
            if (!empty($adsetToCampaigns)) {
                echo "<div style='max-height: 250px; overflow-y: auto;'>";
                echo "<table style='width: 100%; border-collapse: collapse; color: #d4d4d4; font-size: 11px;'>";
                echo "<tr style='background: #2d2d30;'><th style='padding: 6px; text-align: left;'>Adset ID</th><th style='padding: 6px; text-align: left;'>Campaign ID</th><th style='padding: 6px; text-align: left;'>Campaign Name</th></tr>";
                foreach ($adsetToCampaigns as $aid => $campaigns) {
                    foreach ($campaigns as $c) {
                        echo "<tr><td style='padding: 4px; font-family: monospace;'>" . htmlspecialchars($aid) . "</td><td style='padding: 4px;'>" . (int)$c['campaign_id'] . "</td><td style='padding: 4px;'>" . htmlspecialchars($c['campaign_name']) . "</td></tr>";
                    }
                }
                echo "</table>";
                echo "</div>";
            } else {
                echo "<p style='color: #f48771;'>No adset→campaign mapping found (no clicks with valid adset_id).</p>";
            }
            echo "</div>";
            
            // 2. Per-campaign: ad account, adset IDs, clicks today, cost rows today, sum delta, dashboard cost
            echo "<div style='margin-bottom: 25px;'>";
            echo "<h3 style='color: #4ec9b0; margin-top: 0;'>2. Per-campaign cost source for today</h3>";
            echo "<p style='color: #858585; font-size: 11px;'>For each campaign: linked ad account (from setup), adsets linked via clicks, clicks today, cost rows in DB for today (UTC range), sum of delta_spend, and what getAggregatedCost returns. Campaigns with a linked ad account are shown even if they have 0 cost (e.g. Solar).</p>";
            echo "<div style='max-height: 450px; overflow-y: auto;'>";
            echo "<table style='width: 100%; border-collapse: collapse; color: #d4d4d4; font-size: 11px;'>";
            echo "<tr style='background: #2d2d30;'><th style='padding: 6px; text-align: left;'>Campaign</th><th style='padding: 6px; text-align: left;'>Ad account</th><th style='padding: 6px; text-align: left;'>Adset IDs</th><th style='padding: 6px; text-align: right;'>Clicks today</th><th style='padding: 6px; text-align: right;'>Cost rows today</th><th style='padding: 6px; text-align: right;'>Sum delta today</th><th style='padding: 6px; text-align: right;'>Dashboard cost</th></tr>";
            
            foreach ($allCampaigns as $camp) {
                $campaignId = (int)$camp['id'];
                $campaignName = $camp['name'];
                $adsetIdsForCampaign = [];
                foreach ($adsetToCampaigns as $aid => $campaigns) {
                    foreach ($campaigns as $c) {
                        if ((int)$c['campaign_id'] === $campaignId) $adsetIdsForCampaign[] = $aid;
                    }
                }
                $adsetIdsForCampaign = array_unique($adsetIdsForCampaign);
                
                $clicksTodayStmt = $db->prepare("SELECT COUNT(*) AS cnt FROM clicks WHERE campaign_id = ? AND ts >= ? AND ts <= ?");
                $clicksTodayStmt->bind_param('iss', $campaignId, $utcDateFrom, $utcDateTo);
                $clicksTodayStmt->execute();
                $clicksToday = (int)($clicksTodayStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
                
                $costRowsToday = 0;
                $sumDeltaToday = 0.0;
                foreach ($allCosts as $cost) {
                    if (in_array((string)$cost['adset_id'], $adsetIdsForCampaign)) {
                        $costRowsToday++;
                        $sumDeltaToday += (float)$cost['delta_spend'];
                    }
                }
                
                $campTotalCost = $costAggregator->getAggregatedCost($utcDateFrom, $utcDateTo, 'AND campaign_id = ?', [$campaignId], $userTimezone);
                
                $hasLinkedAdAccount = isset($campaignAdAccountMap[$campaignId]);
                $showRow = (count($adsetIdsForCampaign) > 0) || $clicksToday > 0 || $costRowsToday > 0 || $campTotalCost > 0.01 || $hasLinkedAdAccount;
                if (!$showRow) continue;
                
                $adAccountDisplay = $hasLinkedAdAccount ? htmlspecialchars($campaignAdAccountMap[$campaignId]['ad_account_name']) : '<span style="color:#858585;">—</span>';
                $adsetList = count($adsetIdsForCampaign) > 0 ? implode(', ', array_map(function($a) { return substr($a, 0, 15) . '…'; }, array_slice($adsetIdsForCampaign, 0, 5))) : '<span style="color:#f48771;">none</span>';
                if (count($adsetIdsForCampaign) > 5) $adsetList .= ' (+' . (count($adsetIdsForCampaign) - 5) . ')';
                
                echo "<tr><td style='padding: 6px;'>" . htmlspecialchars($campaignName) . " (ID: $campaignId)</td>";
                echo "<td style='padding: 6px;'>" . $adAccountDisplay . "</td>";
                echo "<td style='padding: 6px; font-size: 10px;'>" . $adsetList . "</td>";
                echo "<td style='padding: 6px; text-align: right;'>" . $clicksToday . "</td>";
                echo "<td style='padding: 6px; text-align: right;'>" . $costRowsToday . "</td>";
                echo "<td style='padding: 6px; text-align: right; color: #4ec9b0;'>$" . number_format($sumDeltaToday, 2) . "</td>";
                echo "<td style='padding: 6px; text-align: right; font-weight: bold;'>$" . number_format($campTotalCost, 2) . "</td></tr>";
            }
            echo "</table>";
            echo "</div>";
            echo "</div>";
            
            // 3. Adsets with cost today but NO clicks (any time) – spend-only / unattributed
            // Normalize to string so array_diff is correct (cost table may use int, clicks query uses string keys)
            $adsetIdsWithCostToday = array_unique(array_map('strval', array_column($allCosts, 'adset_id')));
            $adsetIdsWithClicksAnyTime = array_map('strval', array_keys($adsetToCampaigns));
            $spendOnlyAdsets = array_diff($adsetIdsWithCostToday, $adsetIdsWithClicksAnyTime);
            
            echo "<div style='margin-bottom: 25px;'>";
            echo "<h3 style='color: #4ec9b0; margin-top: 0;'>3. Adsets with cost today but NO clicks (any time)</h3>";
            echo "<p style='color: #858585; font-size: 11px;'>These adsets have rows in adset_hourly_costs for today but never had a click in our DB. Their cost is unattributed to any campaign (explains e.g. Solar \$0 if Solar has no clicks).</p>";
            if (!empty($spendOnlyAdsets)) {
                $spendOnlyTotal = 0.0;
                echo "<div style='max-height: 200px; overflow-y: auto;'>";
                echo "<table style='width: 100%; border-collapse: collapse; color: #d4d4d4; font-size: 11px;'>";
                echo "<tr style='background: #2d2d30;'><th style='padding: 6px; text-align: left;'>Adset ID</th><th style='padding: 6px; text-align: right;'>Sum delta today</th><th style='padding: 6px; text-align: right;'>Max spend (cumulative)</th></tr>";
                foreach ($spendOnlyAdsets as $aid) {
                    $sumDelta = 0.0;
                    $maxSpend = 0.0;
                    foreach ($allCosts as $cost) {
                        if ((string)$cost['adset_id'] === (string)$aid) {
                            $sumDelta += (float)$cost['delta_spend'];
                            if ((float)$cost['spend'] > $maxSpend) $maxSpend = (float)$cost['spend'];
                        }
                    }
                    $spendOnlyTotal += $sumDelta;
                    echo "<tr><td style='padding: 4px; font-family: monospace;'>" . htmlspecialchars($aid) . "</td><td style='padding: 4px; text-align: right; color: #f48771;'>$" . number_format($sumDelta, 2) . "</td><td style='padding: 4px; text-align: right;'>$" . number_format($maxSpend, 2) . "</td></tr>";
                }
                echo "<tr style='background: #2d2d30; border-top: 1px solid #dcdcaa;'><td style='padding: 6px; font-weight: bold;'>Total unattributed</td><td style='padding: 6px; text-align: right; font-weight: bold; color: #f48771;'>$" . number_format($spendOnlyTotal, 2) . "</td><td style='padding: 6px;'></td></tr>";
                echo "</table>";
                echo "</div>";
            } else {
                echo "<p style='color: #4ec9b0;'>None – every adset with cost today has at least one click (any time).</p>";
            }
            echo "</div>";
            
            echo "</div>"; // End Cost Troubleshooting section
            
            // All cost records table
            echo "<div style='background: #1e1e1e; padding: 15px; border-radius: 4px; margin-bottom: 20px;'>";
            echo "<h3 style='color: #4ec9b0; margin-top: 0;'>📋 All Cost Records in Database (Today's UTC Range)</h3>";
            echo "<p style='color: #858585; font-size: 12px;'>Total records: " . count($allCosts) . "</p>";
            
            if (count($allCosts) > 0) {
                echo "<div style='max-height: 400px; overflow-y: auto;'>";
                echo "<table style='width: 100%; border-collapse: collapse; color: #d4d4d4; font-size: 11px;'>";
                echo "<tr style='background: #2d2d30; position: sticky; top: 0;'><th style='padding: 6px; text-align: left; border-bottom: 1px solid #4ec9b0;'>Adset ID</th><th style='padding: 6px; text-align: left; border-bottom: 1px solid #4ec9b0;'>Date</th><th style='padding: 6px; text-align: left; border-bottom: 1px solid #4ec9b0;'>Hour</th><th style='padding: 6px; text-align: right; border-bottom: 1px solid #4ec9b0;'>Delta Spend</th><th style='padding: 6px; text-align: right; border-bottom: 1px solid #4ec9b0;'>Cumulative</th><th style='padding: 6px; text-align: left; border-bottom: 1px solid #4ec9b0;'>Last Synced</th><th style='padding: 6px; text-align: left; border-bottom: 1px solid #4ec9b0;'>Campaigns with Clicks</th></tr>";
                
                foreach ($allCosts as $cost) {
                    // Get which campaigns have clicks for this adset
                    $campaignsQuery = $db->prepare("
                        SELECT DISTINCT c.campaign_id, COUNT(*) as click_count
                        FROM clicks c
                        WHERE JSON_UNQUOTE(JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.adset_id')) = ?
                            AND c.ts >= ? AND c.ts <= ?
                            AND JSON_UNQUOTE(JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.adset_id')) IS NOT NULL
                            AND JSON_UNQUOTE(JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.adset_id')) != ''
                            AND JSON_UNQUOTE(JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.adset_id')) != 'null'
                            AND JSON_UNQUOTE(JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.adset_id')) NOT LIKE '{{%'
                            AND JSON_UNQUOTE(JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.adset_id')) NOT LIKE '{ts:%'
                        GROUP BY c.campaign_id
                    ");
                    $campaignsQuery->bind_param('sss', $cost['adset_id'], $utcDateFrom, $utcDateTo);
                    $campaignsQuery->execute();
                    $campaigns = $campaignsQuery->get_result()->fetch_all(MYSQLI_ASSOC);
                    
                    $campaignNames = [];
                    foreach ($campaigns as $camp) {
                        $campNameQuery = $db->prepare("SELECT name FROM campaigns WHERE id = ?");
                        $campNameQuery->bind_param('i', $camp['campaign_id']);
                        $campNameQuery->execute();
                        $campNameResult = $campNameQuery->get_result()->fetch_assoc();
                        $campName = $campNameResult['name'] ?? 'Campaign ' . $camp['campaign_id'];
                        $campaignNames[] = $campName . " (" . $camp['click_count'] . " clicks)";
                    }
                    
                    $lastSynced = $cost['last_synced'] ?? null;
                    $lastSyncedFormatted = $lastSynced ? date('Y-m-d H:i:s', strtotime($lastSynced)) : 'N/A';
                    $hoursAgo = $lastSynced ? round((time() - strtotime($lastSynced)) / 3600, 1) : null;
                    $lastSyncedDisplay = $lastSyncedFormatted;
                    if ($hoursAgo !== null) {
                        $lastSyncedDisplay .= " <span style='color: #858585; font-size: 9px;'>({$hoursAgo}h ago)</span>";
                    }
                    
                    echo "<tr><td style='padding: 4px; font-family: monospace; font-size: 10px;'>" . htmlspecialchars(substr($cost['adset_id'], 0, 20)) . "...</td>";
                    echo "<td style='padding: 4px;'>" . htmlspecialchars($cost['date']) . "</td>";
                    echo "<td style='padding: 4px;'>" . htmlspecialchars($cost['hour']) . "</td>";
                    echo "<td style='padding: 4px; text-align: right; color: #4ec9b0;'>$" . number_format($cost['delta_spend'], 2) . "</td>";
                    echo "<td style='padding: 4px; text-align: right;'>$" . number_format($cost['spend'], 2) . "</td>";
                    echo "<td style='padding: 4px; font-size: 10px;'>" . htmlspecialchars($lastSyncedDisplay) . "</td>";
                    echo "<td style='padding: 4px; font-size: 10px;'>" . (empty($campaignNames) ? '<span style="color: #f48771;">NO CLICKS</span>' : implode(', ', array_map('htmlspecialchars', $campaignNames))) . "</td></tr>";
                }
                
                echo "</table>";
                echo "</div>";
            } else {
                echo "<p style='color: #f48771;'>No cost records found in database for this date range.</p>";
            }
            echo "</div>";
            
            // ============================================
            // COST ATTRIBUTION DIAGNOSTIC SECTION
            // ============================================
            if (count($allCosts) > 0) {
                echo "<div style='background: #1e1e1e; padding: 15px; border-radius: 4px; margin-bottom: 20px; border: 2px solid #4ec9b0;'>";
                echo "<h3 style='color: #4ec9b0; margin-top: 0;'>🔍 Cost Attribution Diagnostic Analysis</h3>";
                echo "<p style='color: #858585; font-size: 12px; margin-bottom: 15px;'>Shows when each cost was saved, what API date was queried, and flags suspicious costs.</p>";
                
                // Parse log entries to match them to cost records
                $logEntries = [];
                if (file_exists($logFile)) {
                    $logContent = file_get_contents($logFile);
                    $logLines = explode("\n", $logContent);
                    
                    foreach ($logLines as $line) {
                        // Look for "Successfully upserted adset hourly cost" entries
                        if (preg_match('/"Successfully upserted adset hourly cost"/', $line)) {
                            if (preg_match('/\{[^}]+\}/', $line, $matches)) {
                                $jsonData = json_decode($matches[0], true);
                                if ($jsonData && isset($jsonData['context'])) {
                                    $context = $jsonData['context'];
                                    $adsetId = $context['adset_id'] ?? null;
                                    $deltaSpend = isset($context['delta_spend']) ? (float)$context['delta_spend'] : null;
                                    $spend = isset($context['spend']) ? (float)$context['spend'] : null;
                                    $date = $context['date'] ?? null;
                                    $hour = isset($context['hour']) ? (int)$context['hour'] : null;
                                    $timestamp = $jsonData['timestamp'] ?? null;
                                    
                                    if ($adsetId && $deltaSpend !== null && $date && $hour !== null) {
                                        $logEntries[] = [
                                            'adset_id' => $adsetId,
                                            'date' => $date,
                                            'hour' => $hour,
                                            'delta_spend' => $deltaSpend,
                                            'spend' => $spend,
                                            'timestamp' => $timestamp
                                        ];
                                    }
                                }
                            }
                        }
                    }
                }
                
                // Match log entries to cost records and find API query dates
                $costAttributions = [];
                foreach ($allCosts as $cost) {
                    $attribution = [
                        'cost' => $cost,
                        'log_entry' => null,
                        'api_date_queried' => null,
                        'date_for_db_used' => null,
                        'cron_timestamp' => null,
                        'pst_date' => null,
                        'is_suspicious' => false,
                        'suspicious_reason' => ''
                    ];
                    
                    // Find matching log entry
                    foreach ($logEntries as $logEntry) {
                        if ($logEntry['adset_id'] == $cost['adset_id'] &&
                            $logEntry['date'] == $cost['date'] &&
                            $logEntry['hour'] == $cost['hour'] &&
                            abs($logEntry['delta_spend'] - $cost['delta_spend']) < 0.01) {
                            $attribution['log_entry'] = $logEntry;
                            $attribution['cron_timestamp'] = $logEntry['timestamp'];
                            break;
                        }
                    }
                    
                    // Parse log to find API query date for this cost
                    if (file_exists($logFile)) {
                        $logContent = file_get_contents($logFile);
                        $logLines = explode("\n", $logContent);
                        
                        // Look for log entries around the time this cost was saved
                        $targetTimestamp = $cost['last_synced'] ?? null;
                        if ($targetTimestamp) {
                            $targetTime = strtotime($targetTimestamp);
                            
                            // Look for "Timezone conversion for API query" or "Fetching account-level adset insights" entries
                            // within 5 minutes of when the cost was saved
                            foreach ($logLines as $line) {
                                if (preg_match('/"Timezone conversion for API query"|"Fetching account-level adset insights"/', $line)) {
                                    if (preg_match('/"timestamp":"([^"]+)"/', $line, $tsMatch)) {
                                        $logTime = strtotime($tsMatch[1]);
                                        if ($logTime && abs($logTime - $targetTime) < 300) { // Within 5 minutes
                                            if (preg_match('/\{[^}]+\}/', $line, $matches)) {
                                                $jsonData = json_decode($matches[0], true);
                                                if ($jsonData && isset($jsonData['context'])) {
                                                    $context = $jsonData['context'];
                                                    $apiDate = $context['api_query_date'] ?? $context['date_for_api'] ?? $context['date'] ?? null;
                                                    $dateForDb = $context['date_for_db'] ?? $context['utc_date_for_db'] ?? null;
                                                    
                                                    if ($apiDate) {
                                                        $attribution['api_date_queried'] = $apiDate;
                                                    }
                                                    if ($dateForDb) {
                                                        $attribution['date_for_db_used'] = $dateForDb;
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                    
                    // Calculate user timezone date for this cost
                    $utcDateTime = new DateTime($cost['date'] . ' ' . str_pad($cost['hour'], 2, '0', STR_PAD_LEFT) . ':00:00', new DateTimeZone('UTC'));
                    $userTzDateTime = clone $utcDateTime;
                    $userTzDateTime->setTimezone(new DateTimeZone($userTimezone));
                    $attribution['pst_date'] = $userTzDateTime->format('Y-m-d');
                    
                    // Check if suspicious
                    $targetUserTzDate = $todayInUserTz;
                    if ($attribution['pst_date'] != $targetUserTzDate) {
                        $attribution['is_suspicious'] = true;
                        $attribution['suspicious_reason'] = "User timezone date ({$attribution['pst_date']}) doesn't match target date ({$targetUserTzDate})";
                    }
                    
                    if ($cost['last_synced']) {
                        $hoursAgo = (time() - strtotime($cost['last_synced'])) / 3600;
                        if ($hoursAgo > 24) {
                            $attribution['is_suspicious'] = true;
                            $attribution['suspicious_reason'] .= ($attribution['suspicious_reason'] ? '; ' : '') . "Saved " . round($hoursAgo, 1) . " hours ago (more than 24h)";
                        }
                    }
                    
                    $costAttributions[] = $attribution;
                }
                
                // Display cost attribution table
                echo "<div style='max-height: 600px; overflow-y: auto;'>";
                echo "<table style='width: 100%; border-collapse: collapse; color: #d4d4d4; font-size: 11px;'>";
                echo "<tr style='background: #2d2d30; position: sticky; top: 0;'>";
                echo "<th style='padding: 6px; text-align: left; border-bottom: 1px solid #4ec9b0;'>Adset ID</th>";
                echo "<th style='padding: 6px; text-align: left; border-bottom: 1px solid #4ec9b0;'>UTC Date/Hour</th>";
                echo "<th style='padding: 6px; text-align: left; border-bottom: 1px solid #4ec9b0;'>" . htmlspecialchars($userTimezone) . " Date</th>";
                echo "<th style='padding: 6px; text-align: right; border-bottom: 1px solid #4ec9b0;'>Delta Spend</th>";
                echo "<th style='padding: 6px; text-align: left; border-bottom: 1px solid #4ec9b0;'>Last Synced</th>";
                echo "<th style='padding: 6px; text-align: left; border-bottom: 1px solid #4ec9b0;'>API Date Queried</th>";
                echo "<th style='padding: 6px; text-align: left; border-bottom: 1px solid #4ec9b0;'>Date For DB</th>";
                echo "<th style='padding: 6px; text-align: left; border-bottom: 1px solid #4ec9b0;'>Status</th>";
                echo "</tr>";
                
                $suspiciousCount = 0;
                foreach ($costAttributions as $attr) {
                    $rowStyle = $attr['is_suspicious'] ? 'background: #4a2d2d;' : '';
                    if ($attr['is_suspicious']) {
                        $suspiciousCount++;
                    }
                    
                    echo "<tr style='{$rowStyle}'>";
                    echo "<td style='padding: 4px; font-family: monospace; font-size: 10px;'>" . htmlspecialchars(substr($attr['cost']['adset_id'], 0, 20)) . "...</td>";
                    echo "<td style='padding: 4px; font-family: monospace;'>" . htmlspecialchars($attr['cost']['date'] . ' ' . str_pad($attr['cost']['hour'], 2, '0', STR_PAD_LEFT) . ':00') . "</td>";
                    echo "<td style='padding: 4px;'>" . htmlspecialchars($attr['pst_date']) . "</td>";
                    echo "<td style='padding: 4px; text-align: right; color: #4ec9b0;'>$" . number_format($attr['cost']['delta_spend'], 2) . "</td>";
                    
                    $lastSynced = $attr['cost']['last_synced'] ?? 'N/A';
                    $lastSyncedDisplay = $lastSynced != 'N/A' ? date('Y-m-d H:i:s', strtotime($lastSynced)) : 'N/A';
                    echo "<td style='padding: 4px; font-size: 10px;'>" . htmlspecialchars($lastSyncedDisplay) . "</td>";
                    
                    $apiDate = $attr['api_date_queried'] ?? 'Not found';
                    echo "<td style='padding: 4px; font-size: 10px;'>" . htmlspecialchars($apiDate) . "</td>";
                    
                    $dateForDb = $attr['date_for_db_used'] ?? 'Not found';
                    echo "<td style='padding: 4px; font-size: 10px;'>" . htmlspecialchars($dateForDb) . "</td>";
                    
                    if ($attr['is_suspicious']) {
                        echo "<td style='padding: 4px;'><span style='color: #f48771; font-weight: bold;'>⚠️ Suspicious</span><br><span style='color: #858585; font-size: 9px;'>" . htmlspecialchars($attr['suspicious_reason']) . "</span></td>";
                    } else {
                        echo "<td style='padding: 4px;'><span style='color: #4ec9b0;'>✅ OK</span></td>";
                    }
                    echo "</tr>";
                }
                
                echo "</table>";
                echo "</div>";
                
                if ($suspiciousCount > 0) {
                    echo "<div style='margin-top: 15px; padding: 10px; background: #4a2d2d; border-radius: 4px; border: 2px solid #f48771;'>";
                    echo "<p style='color: #f48771; font-weight: bold;'>⚠️ Found {$suspiciousCount} suspicious cost record(s) that may be attributed to the wrong date.</p>";
                    echo "</div>";
                } else {
                    echo "<div style='margin-top: 15px; padding: 10px; background: #2d4a2d; border-radius: 4px;'>";
                    echo "<p style='color: #4ec9b0;'>✅ All cost records appear to be correctly attributed.</p>";
                    echo "</div>";
                }
                
                echo "</div>";
            }
            
            // ============================================
            // DIAGNOSTIC SECTIONS FOR MAJOR DISCREPANCY
            // ============================================
            
            // 1. Meta API Spend Comparison
            echo "<div style='background: #1e1e1e; padding: 15px; border-radius: 4px; margin-bottom: 20px;'>";
            echo "<h3 style='color: #4ec9b0; margin-top: 0;'>📡 Meta API Spend Comparison</h3>";
            
            // Parse log file to extract Meta API spend values
            $metaApiSpend = null;
            $previousSpend = null;
            $deltaSpend = null;
            $adsetIdFromLog = null;
            
            if (file_exists($logFile)) {
                $logContent = file_get_contents($logFile);
                $logLines = explode("\n", $logContent);
                
                // Look for the most recent "Retrieved direct adset spend" entry
                foreach (array_reverse($logLines) as $line) {
                    if (preg_match('/"Retrieved direct adset spend"/', $line)) {
                        // Extract JSON context
                        if (preg_match('/\{[^}]+\}/', $line, $matches)) {
                            $jsonData = json_decode($matches[0], true);
                            if ($jsonData && isset($jsonData['context'])) {
                                $metaApiSpend = $jsonData['context']['spend'] ?? null;
                                $adsetIdFromLog = $jsonData['context']['adset_id'] ?? null;
                            }
                        }
                        break;
                    }
                }
                
                // Look for "Calculated delta for adset" to get previous spend and delta
                foreach (array_reverse($logLines) as $line) {
                    if (preg_match('/"Calculated delta for adset"/', $line)) {
                        if (preg_match('/\{[^}]+\}/', $line, $matches)) {
                            $jsonData = json_decode($matches[0], true);
                            if ($jsonData && isset($jsonData['context'])) {
                                $previousSpend = $jsonData['context']['previous_spend'] ?? null;
                                $deltaSpend = $jsonData['context']['delta_spend'] ?? null;
                            }
                        }
                        break;
                    }
                }
            }
            
            // Get database cumulative spend for the adset
            $dbCumulativeSpend = null;
            if ($adsetIdFromLog) {
                $cumulativeQuery = $db->prepare("
                    SELECT MAX(spend) as max_spend
                    FROM adset_hourly_costs
                    WHERE adset_id = ? AND date = ?
                ");
                $cumulativeDate = date('Y-m-d', strtotime($utcDateFrom));
                $cumulativeQuery->bind_param('ss', $adsetIdFromLog, $cumulativeDate);
                $cumulativeQuery->execute();
                $cumulativeResult = $cumulativeQuery->get_result()->fetch_assoc();
                $dbCumulativeSpend = $cumulativeResult['max_spend'] ?? null;
            }
            
            // Calculate total of all hourly deltas in date range
            $totalHourlyDeltas = 0.0;
            foreach ($allCosts as $cost) {
                if ($adsetIdFromLog && $cost['adset_id'] == $adsetIdFromLog) {
                    $totalHourlyDeltas += $cost['delta_spend'];
                }
            }
            
            echo "<table style='width: 100%; border-collapse: collapse; color: #d4d4d4; font-size: 12px;'>";
            echo "<tr style='background: #2d2d30;'><th style='padding: 8px; text-align: left; border-bottom: 1px solid #4ec9b0;'>Metric</th><th style='padding: 8px; text-align: right; border-bottom: 1px solid #4ec9b0;'>Value</th></tr>";
            
            if ($metaApiSpend !== null) {
                echo "<tr><td style='padding: 6px; color: #4ec9b0;'>Meta API Spend (from last cron)</td><td style='padding: 6px; text-align: right; font-weight: bold;'>$" . number_format($metaApiSpend, 2) . "</td></tr>";
            } else {
                echo "<tr><td style='padding: 6px; color: #f48771;'>Meta API Spend (from last cron)</td><td style='padding: 6px; text-align: right; color: #f48771;'>Not found in log</td></tr>";
            }
            
            if ($previousSpend !== null) {
                echo "<tr style='background: #252526;'><td style='padding: 6px;'>Previous Spend in DB</td><td style='padding: 6px; text-align: right;'>$" . number_format($previousSpend, 2) . "</td></tr>";
            }
            
            if ($deltaSpend !== null) {
                echo "<tr><td style='padding: 6px;'>Delta Calculated</td><td style='padding: 6px; text-align: right;'>$" . number_format($deltaSpend, 2) . "</td></tr>";
            }
            
            if ($dbCumulativeSpend !== null) {
                echo "<tr style='background: #252526;'><td style='padding: 6px; color: #4ec9b0;'>DB Cumulative Spend (Max)</td><td style='padding: 6px; text-align: right; font-weight: bold;'>$" . number_format($dbCumulativeSpend, 2) . "</td></tr>";
            }
            
            echo "<tr><td style='padding: 6px; color: #dcdcaa;'>Total Hourly Deltas in Date Range</td><td style='padding: 6px; text-align: right; font-weight: bold; color: #dcdcaa;'>$" . number_format($totalHourlyDeltas, 2) . "</td></tr>";
            
            if ($metaApiSpend !== null && $totalHourlyDeltas > 0) {
                $majorDifference = abs($metaApiSpend - $totalHourlyDeltas);
                echo "<tr style='background: #2d2d30; border-top: 2px solid #f48771;'><td style='padding: 8px; font-weight: bold; color: #f48771;'>⚠️ MAJOR DISCREPANCY</td><td style='padding: 8px; text-align: right; font-weight: bold; color: #f48771;'>$" . number_format($majorDifference, 2) . "</td></tr>";
                echo "<tr style='background: #2d2d30;'><td colspan='2' style='padding: 6px; color: #f48771; font-size: 11px;'>Database shows $" . number_format($totalHourlyDeltas, 2) . " but Meta API shows $" . number_format($metaApiSpend, 2) . "</td></tr>";
            }
            
            if ($metaApiSpend !== null && $dbCumulativeSpend !== null) {
                $cumulativeDiff = abs($metaApiSpend - $dbCumulativeSpend);
                if ($cumulativeDiff > 0.01) {
                    echo "<tr style='background: #2d2d30;'><td style='padding: 6px; color: #f48771;'>Cumulative Spend Mismatch</td><td style='padding: 6px; text-align: right; color: #f48771;'>$" . number_format($cumulativeDiff, 2) . "</td></tr>";
                } else {
                    echo "<tr style='background: #2d2d30;'><td style='padding: 6px; color: #4ec9b0;'>Cumulative Spend Match</td><td style='padding: 6px; text-align: right; color: #4ec9b0;'>✅</td></tr>";
                }
            }
            
            echo "</table>";
            echo "</div>";
            
            // 2. PST Date Attribution Analysis
            echo "<div style='background: #1e1e1e; padding: 15px; border-radius: 4px; margin-bottom: 20px;'>";
            echo "<h3 style='color: #4ec9b0; margin-top: 0;'>🌍 PST Date Attribution Analysis</h3>";
            echo "<p style='color: #858585; font-size: 12px; margin-bottom: 15px;'>Converting each cost record's UTC date/hour to PST to see which PST date it belongs to.</p>";
            
            $pstTz = new \DateTimeZone('America/Los_Angeles');
            $utcTz = new \DateTimeZone('UTC');
            $costsByPstDate = [];
            $costsByPstDateDetailed = [];
            
            foreach ($allCosts as $cost) {
                $utcDateTime = $cost['date'] . ' ' . str_pad($cost['hour'], 2, '0', STR_PAD_LEFT) . ':00:00';
                $dt = new \DateTime($utcDateTime, $utcTz);
                $dt->setTimezone($pstTz);
                $pstDate = $dt->format('Y-m-d');
                $pstHour = (int)$dt->format('H');
                
                if (!isset($costsByPstDate[$pstDate])) {
                    $costsByPstDate[$pstDate] = 0.0;
                    $costsByPstDateDetailed[$pstDate] = [];
                }
                $costsByPstDate[$pstDate] += $cost['delta_spend'];
                $costsByPstDateDetailed[$pstDate][] = [
                    'cost' => $cost,
                    'pst_hour' => $pstHour,
                    'utc_datetime' => $utcDateTime
                ];
            }
            
            echo "<table style='width: 100%; border-collapse: collapse; color: #d4d4d4; font-size: 12px;'>";
            echo "<tr style='background: #2d2d30;'><th style='padding: 8px; text-align: left; border-bottom: 1px solid #4ec9b0;'>PST Date</th><th style='padding: 8px; text-align: right; border-bottom: 1px solid #4ec9b0;'>Total Cost</th><th style='padding: 8px; text-align: center; border-bottom: 1px solid #4ec9b0;'>Records</th><th style='padding: 8px; text-align: left; border-bottom: 1px solid #4ec9b0;'>Status</th></tr>";
            
            ksort($costsByPstDate);
            foreach ($costsByPstDate as $pstDate => $total) {
                $isToday = ($pstDate == $todayInUserTz);
                $bgColor = $isToday ? 'background: #2d4a2d;' : 'background: #4a2d2d;';
                $status = $isToday ? '<span style="color: #4ec9b0;">✅ Today</span>' : '<span style="color: #f48771;">⚠️ Wrong Date</span>';
                
                echo "<tr style='$bgColor'>";
                echo "<td style='padding: 6px; font-weight: bold;'>$pstDate</td>";
                echo "<td style='padding: 6px; text-align: right; font-weight: bold;'>$" . number_format($total, 2) . "</td>";
                echo "<td style='padding: 6px; text-align: center;'>" . count($costsByPstDateDetailed[$pstDate]) . "</td>";
                echo "<td style='padding: 6px;'>$status</td>";
                echo "</tr>";
            }
            
            echo "</table>";
            
            // Show what should be included for "today"
            $todayPstTotal = $costsByPstDate[$todayInUserTz] ?? 0.0;
            echo "<p style='margin-top: 15px;'><strong style='color: #4ec9b0;'>Total for Today (PST " . htmlspecialchars($todayInUserTz) . "):</strong> <span style='font-size: 18px; font-weight: bold;'>$" . number_format($todayPstTotal, 2) . "</span></p>";
            
            if ($metaApiSpend !== null) {
                $expectedDiff = abs($todayPstTotal - $metaApiSpend);
                if ($expectedDiff > 0.01) {
                    echo "<p style='color: #f48771; margin-top: 10px;'><strong>⚠️ Today's PST total ($" . number_format($todayPstTotal, 2) . ") differs from Meta API spend ($" . number_format($metaApiSpend, 2) . ") by $" . number_format($expectedDiff, 2) . "</strong></p>";
                } else {
                    echo "<p style='color: #4ec9b0; margin-top: 10px;'><strong>✅ Today's PST total matches Meta API spend</strong></p>";
                }
            }
            
            echo "</div>";
            
            // 3. Cost Record Timeline Analysis
            echo "<div style='background: #1e1e1e; padding: 15px; border-radius: 4px; margin-bottom: 20px;'>";
            echo "<h3 style='color: #4ec9b0; margin-top: 0;'>⏰ Cost Record Timeline Analysis</h3>";
            echo "<p style='color: #858585; font-size: 12px; margin-bottom: 15px;'>Detailed view of each cost record showing UTC and PST timestamps.</p>";
            
            if (count($allCosts) > 0) {
                echo "<div style='max-height: 500px; overflow-y: auto;'>";
                echo "<table style='width: 100%; border-collapse: collapse; color: #d4d4d4; font-size: 11px;'>";
                echo "<tr style='background: #2d2d30; position: sticky; top: 0;'>";
                echo "<th style='padding: 6px; text-align: left; border-bottom: 1px solid #4ec9b0;'>UTC Date/Hour</th>";
                echo "<th style='padding: 6px; text-align: left; border-bottom: 1px solid #4ec9b0;'>PST Date/Hour</th>";
                echo "<th style='padding: 6px; text-align: right; border-bottom: 1px solid #4ec9b0;'>Delta Spend</th>";
                echo "<th style='padding: 6px; text-align: left; border-bottom: 1px solid #4ec9b0;'>PST Date Match</th>";
                echo "</tr>";
                
                foreach ($allCosts as $cost) {
                    $utcDateTime = $cost['date'] . ' ' . str_pad($cost['hour'], 2, '0', STR_PAD_LEFT) . ':00:00';
                    $dt = new \DateTime($utcDateTime, $utcTz);
                    $dt->setTimezone($pstTz);
                    $pstDate = $dt->format('Y-m-d');
                    $pstHour = (int)$dt->format('H');
                    $pstDateTime = $pstDate . ' ' . str_pad($pstHour, 2, '0', STR_PAD_LEFT) . ':00:00';
                    
                    $isToday = ($pstDate == $todayInUserTz);
                    $bgColor = $isToday ? '' : 'background: #4a2d2d;';
                    $matchStatus = $isToday ? '<span style="color: #4ec9b0;">✅ Today</span>' : '<span style="color: #f48771;">⚠️ ' . $pstDate . '</span>';
                    
                    echo "<tr style='$bgColor'>";
                    echo "<td style='padding: 4px; font-family: monospace;'>$utcDateTime</td>";
                    echo "<td style='padding: 4px; font-family: monospace;'>$pstDateTime</td>";
                    echo "<td style='padding: 4px; text-align: right; color: #4ec9b0;'>$" . number_format($cost['delta_spend'], 2) . "</td>";
                    echo "<td style='padding: 4px;'>$matchStatus</td>";
                    echo "</tr>";
                }
                
                echo "</table>";
                echo "</div>";
            }
            echo "</div>";
            
            // 4. Adset Spend Comparison
            if ($adsetIdFromLog) {
                echo "<div style='background: #1e1e1e; padding: 15px; border-radius: 4px; margin-bottom: 20px;'>";
                echo "<h3 style='color: #4ec9b0; margin-top: 0;'>🎯 Adset Spend Comparison (Adset: " . htmlspecialchars(substr($adsetIdFromLog, 0, 20)) . "...)</h3>";
                
                // Get all costs for this adset in the date range
                $adsetCostsQuery = $db->prepare("
                    SELECT date, hour, delta_spend, spend
                    FROM adset_hourly_costs
                    WHERE adset_id = ?
                        AND CONCAT(date, ' ', LPAD(hour, 2, '0'), ':00:00') >= ?
                        AND CONCAT(date, ' ', LPAD(hour, 2, '0'), ':00:00') <= ?
                        AND delta_spend > 0
                    ORDER BY date, hour
                ");
                $adsetCostsQuery->bind_param('sss', $adsetIdFromLog, $utcDateFrom, $utcDateTo);
                $adsetCostsQuery->execute();
                $adsetCosts = $adsetCostsQuery->get_result()->fetch_all(MYSQLI_ASSOC);
                
                $adsetTotalDeltas = array_sum(array_column($adsetCosts, 'delta_spend'));
                $adsetMaxSpend = $adsetCosts ? max(array_column($adsetCosts, 'spend')) : null;
                
                echo "<table style='width: 100%; border-collapse: collapse; color: #d4d4d4; font-size: 12px;'>";
                echo "<tr style='background: #2d2d30;'><th style='padding: 8px; text-align: left; border-bottom: 1px solid #4ec9b0;'>Metric</th><th style='padding: 8px; text-align: right; border-bottom: 1px solid #4ec9b0;'>Value</th></tr>";
                
                if ($metaApiSpend !== null) {
                    echo "<tr><td style='padding: 6px; color: #4ec9b0;'>Meta API Spend (from cron log)</td><td style='padding: 6px; text-align: right; font-weight: bold;'>$" . number_format($metaApiSpend, 2) . "</td></tr>";
                }
                
                if ($previousSpend !== null) {
                    echo "<tr style='background: #252526;'><td style='padding: 6px;'>Previous Spend in DB</td><td style='padding: 6px; text-align: right;'>$" . number_format($previousSpend, 2) . "</td></tr>";
                }
                
                if ($deltaSpend !== null) {
                    echo "<tr><td style='padding: 6px;'>Delta Calculated by Cron</td><td style='padding: 6px; text-align: right;'>$" . number_format($deltaSpend, 2) . "</td></tr>";
                }
                
                if ($adsetMaxSpend !== null) {
                    echo "<tr style='background: #252526;'><td style='padding: 6px; color: #4ec9b0;'>DB Max Cumulative Spend</td><td style='padding: 6px; text-align: right; font-weight: bold;'>$" . number_format($adsetMaxSpend, 2) . "</td></tr>";
                }
                
                echo "<tr><td style='padding: 6px; color: #dcdcaa;'>Total of All Hourly Deltas in Range</td><td style='padding: 6px; text-align: right; font-weight: bold; color: #dcdcaa;'>$" . number_format($adsetTotalDeltas, 2) . "</td></tr>";
                
                if ($metaApiSpend !== null && $adsetTotalDeltas > 0) {
                    $adsetDiff = abs($metaApiSpend - $adsetTotalDeltas);
                    echo "<tr style='background: #2d2d30; border-top: 2px solid #f48771;'><td style='padding: 8px; font-weight: bold; color: #f48771;'>⚠️ DISCREPANCY</td><td style='padding: 8px; text-align: right; font-weight: bold; color: #f48771;'>$" . number_format($adsetDiff, 2) . "</td></tr>";
                }
                
                if ($metaApiSpend !== null && $adsetMaxSpend !== null) {
                    $cumulativeMatch = abs($metaApiSpend - $adsetMaxSpend) < 0.01;
                    if ($cumulativeMatch) {
                        echo "<tr style='background: #2d2d30;'><td style='padding: 6px; color: #4ec9b0;'>Cumulative Spend Match</td><td style='padding: 6px; text-align: right; color: #4ec9b0;'>✅ Matches Meta API</td></tr>";
                    } else {
                        $cumulativeDiff = abs($metaApiSpend - $adsetMaxSpend);
                        echo "<tr style='background: #2d2d30;'><td style='padding: 6px; color: #f48771;'>Cumulative Spend Mismatch</td><td style='padding: 6px; text-align: right; color: #f48771;'>$" . number_format($cumulativeDiff, 2) . "</td></tr>";
                    }
                }
                
                echo "</table>";
                echo "<p style='margin-top: 10px; color: #858585; font-size: 11px;'>Total hourly deltas: " . count($adsetCosts) . " records</p>";
                echo "</div>";
            }
            
            // 5. Date Range Analysis
            echo "<div style='background: #1e1e1e; padding: 15px; border-radius: 4px; margin-bottom: 20px;'>";
            echo "<h3 style='color: #4ec9b0; margin-top: 0;'>📅 Date Range Analysis</h3>";
            
            // Convert UTC range boundaries to PST
            $utcStartDt = new \DateTime($utcDateFrom, $utcTz);
            $utcStartDt->setTimezone($pstTz);
            $pstStart = $utcStartDt->format('Y-m-d H:i:s');
            
            $utcEndDt = new \DateTime($utcDateTo, $utcTz);
            $utcEndDt->setTimezone($pstTz);
            $pstEnd = $utcEndDt->format('Y-m-d H:i:s');
            
            echo "<p><strong style='color: #dcdcaa;'>UTC Range:</strong> " . htmlspecialchars($utcDateFrom) . " to " . htmlspecialchars($utcDateTo) . "</p>";
            echo "<p><strong style='color: #dcdcaa;'>PST Range:</strong> " . htmlspecialchars($pstStart) . " to " . htmlspecialchars($pstEnd) . "</p>";
            echo "<p><strong style='color: #dcdcaa;'>Target PST Date:</strong> " . htmlspecialchars($todayInUserTz) . "</p>";
            
            // Count costs inside vs outside the range
            $costsInRange = 0;
            $costsOutOfRange = 0;
            $costInRangeTotal = 0.0;
            $costOutOfRangeTotal = 0.0;
            
            foreach ($allCosts as $cost) {
                $costDateTime = $cost['date'] . ' ' . str_pad($cost['hour'], 2, '0', STR_PAD_LEFT) . ':00:00';
                $inRange = ($costDateTime >= $utcDateFrom && $costDateTime <= $utcDateTo);
                
                if ($inRange) {
                    $costsInRange++;
                    $costInRangeTotal += $cost['delta_spend'];
                } else {
                    $costsOutOfRange++;
                    $costOutOfRangeTotal += $cost['delta_spend'];
                }
            }
            
            echo "<table style='width: 100%; border-collapse: collapse; color: #d4d4d4; font-size: 12px; margin-top: 15px;'>";
            echo "<tr style='background: #2d2d30;'><th style='padding: 8px; text-align: left; border-bottom: 1px solid #4ec9b0;'>Status</th><th style='padding: 8px; text-align: right; border-bottom: 1px solid #4ec9b0;'>Records</th><th style='padding: 8px; text-align: right; border-bottom: 1px solid #4ec9b0;'>Total Cost</th></tr>";
            echo "<tr><td style='padding: 6px; color: #4ec9b0;'>✅ In UTC Range</td><td style='padding: 6px; text-align: right;'>$costsInRange</td><td style='padding: 6px; text-align: right; font-weight: bold;'>$" . number_format($costInRangeTotal, 2) . "</td></tr>";
            if ($costsOutOfRange > 0) {
                echo "<tr style='background: #4a2d2d;'><td style='padding: 6px; color: #f48771;'>⚠️ Outside UTC Range</td><td style='padding: 6px; text-align: right;'>$costsOutOfRange</td><td style='padding: 6px; text-align: right; font-weight: bold; color: #f48771;'>$" . number_format($costOutOfRangeTotal, 2) . "</td></tr>";
            }
            echo "</table>";
            echo "</div>";
            
            // 6. Cost Summation by PST Date
            echo "<div style='background: #1e1e1e; padding: 15px; border-radius: 4px; margin-bottom: 20px;'>";
            echo "<h3 style='color: #4ec9b0; margin-top: 0;'>💰 Cost Summation by PST Date</h3>";
            
            echo "<table style='width: 100%; border-collapse: collapse; color: #d4d4d4; font-size: 12px;'>";
            echo "<tr style='background: #2d2d30;'><th style='padding: 8px; text-align: left; border-bottom: 1px solid #4ec9b0;'>PST Date</th><th style='padding: 8px; text-align: right; border-bottom: 1px solid #4ec9b0;'>Total Cost</th><th style='padding: 8px; text-align: center; border-bottom: 1px solid #4ec9b0;'>Records</th><th style='padding: 8px; text-align: left; border-bottom: 1px solid #4ec9b0;'>Comparison</th></tr>";
            
            ksort($costsByPstDate);
            foreach ($costsByPstDate as $pstDate => $total) {
                $isToday = ($pstDate == $todayInUserTz);
                $bgColor = $isToday ? 'background: #2d4a2d;' : '';
                
                $comparison = '';
                if ($isToday && $metaApiSpend !== null) {
                    $diff = abs($total - $metaApiSpend);
                    if ($diff < 0.01) {
                        $comparison = '<span style="color: #4ec9b0;">✅ Matches Meta API</span>';
                    } else {
                        $comparison = '<span style="color: #f48771;">⚠️ Diff: $' . number_format($diff, 2) . ' (Meta: $' . number_format($metaApiSpend, 2) . ')</span>';
                    }
                } else {
                    $comparison = '<span style="color: #858585;">N/A</span>';
                }
                
                echo "<tr style='$bgColor'>";
                echo "<td style='padding: 6px; font-weight: bold;'>$pstDate" . ($isToday ? ' <span style="color: #4ec9b0;">(Today)</span>' : '') . "</td>";
                echo "<td style='padding: 6px; text-align: right; font-weight: bold;'>$" . number_format($total, 2) . "</td>";
                echo "<td style='padding: 6px; text-align: center;'>" . count($costsByPstDateDetailed[$pstDate]) . "</td>";
                echo "<td style='padding: 6px;'>$comparison</td>";
                echo "</tr>";
            }
            
            echo "</table>";
            
            // Summary
            $todayPstCost = $costsByPstDate[$todayInUserTz] ?? 0.0;
            $otherDatesCost = array_sum(array_filter($costsByPstDate, function($date) use ($todayInUserTz) { return $date != $todayInUserTz; }, ARRAY_FILTER_USE_KEY));
            
            echo "<div style='margin-top: 15px; padding: 10px; background: #2d2d30; border-radius: 4px;'>";
            echo "<p><strong style='color: #4ec9b0;'>Today (PST " . htmlspecialchars($todayInUserTz) . ") Total:</strong> <span style='font-size: 18px; font-weight: bold;'>$" . number_format($todayPstCost, 2) . "</span></p>";
            if ($otherDatesCost > 0) {
                echo "<p style='color: #f48771; margin-top: 8px;'><strong>⚠️ Costs from Other Dates:</strong> $" . number_format($otherDatesCost, 2) . " (these should NOT be included in today's total)</p>";
            }
            if ($metaApiSpend !== null) {
                echo "<p><strong style='color: #dcdcaa;'>Meta API Spend:</strong> $" . number_format($metaApiSpend, 2) . "</p>";
                $finalDiff = abs($todayPstCost - $metaApiSpend);
                if ($finalDiff > 0.01) {
                    echo "<p style='color: #f48771; margin-top: 8px; font-weight: bold;'>⚠️ DISCREPANCY: Today's PST total differs from Meta API by $" . number_format($finalDiff, 2) . "</p>";
                    if ($otherDatesCost > 0) {
                        echo "<p style='color: #dcdcaa; font-size: 11px; margin-top: 5px;'>If we exclude costs from other dates, the total would be $" . number_format($todayPstCost, 2) . " (still " . ($finalDiff > 0.01 ? 'different' : 'matches') . ")</p>";
                    }
                } else {
                    echo "<p style='color: #4ec9b0; margin-top: 8px; font-weight: bold;'>✅ Today's PST total matches Meta API spend</p>";
                }
            }
            echo "</div>";
            echo "</div>";
            
            // 7. Cumulative vs Delta Spend Analysis
            echo "<div style='background: #1e1e1e; padding: 15px; border-radius: 4px; margin-bottom: 20px; border: 2px solid #f48771;'>";
            echo "<h3 style='color: #f48771; margin-top: 0;'>🔴 CRITICAL: Cumulative vs Delta Spend Analysis</h3>";
            echo "<p style='color: #858585; font-size: 12px; margin-bottom: 15px;'><strong>Understanding the difference:</strong> Cumulative spend is the running total (what Meta API returns), while Delta spend is the incremental cost per hour. We need to identify if we're using the wrong metric.</p>";
            
            // Get cumulative spend values for all adsets in date range
            $cumulativeAnalysisQuery = $db->prepare("
                SELECT 
                    adset_id,
                    MAX(spend) as max_cumulative_spend,
                    MIN(spend) as min_cumulative_spend,
                    COUNT(*) as record_count
                FROM adset_hourly_costs
                WHERE CONCAT(date, ' ', LPAD(hour, 2, '0'), ':00:00') >= ? 
                    AND CONCAT(date, ' ', LPAD(hour, 2, '0'), ':00:00') <= ?
                    AND adset_id NOT LIKE '{{%' AND adset_id NOT LIKE '{ts:%'
                GROUP BY adset_id
            ");
            $cumulativeAnalysisQuery->bind_param('ss', $utcDateFrom, $utcDateTo);
            $cumulativeAnalysisQuery->execute();
            $cumulativeAnalysis = $cumulativeAnalysisQuery->get_result()->fetch_all(MYSQLI_ASSOC);
            
            // Calculate total delta sum for same adsets
            $deltaSumByAdset = [];
            foreach ($allCosts as $cost) {
                $adsetId = $cost['adset_id'];
                if (!isset($deltaSumByAdset[$adsetId])) {
                    $deltaSumByAdset[$adsetId] = 0.0;
                }
                $deltaSumByAdset[$adsetId] += $cost['delta_spend'];
            }
            
            // Overall totals
            $totalCumulativeMax = 0.0;
            $totalDeltaSum = 0.0;
            
            echo "<table style='width: 100%; border-collapse: collapse; color: #d4d4d4; font-size: 12px;'>";
            echo "<tr style='background: #2d2d30;'><th style='padding: 8px; text-align: left; border-bottom: 1px solid #4ec9b0;'>Adset ID</th><th style='padding: 8px; text-align: right; border-bottom: 1px solid #4ec9b0;'>Max Cumulative</th><th style='padding: 8px; text-align: right; border-bottom: 1px solid #4ec9b0;'>Total Delta Sum</th><th style='padding: 8px; text-align: right; border-bottom: 1px solid #4ec9b0;'>Difference</th><th style='padding: 8px; text-align: left; border-bottom: 1px solid #4ec9b0;'>Which Matches Meta?</th></tr>";
            
            foreach ($cumulativeAnalysis as $analysis) {
                $adsetId = $analysis['adset_id'];
                $maxCumulative = (float)$analysis['max_cumulative_spend'];
                $deltaSum = $deltaSumByAdset[$adsetId] ?? 0.0;
                $diff = abs($maxCumulative - $deltaSum);
                
                $totalCumulativeMax += $maxCumulative;
                $totalDeltaSum += $deltaSum;
                
                // Check if this adset matches Meta API spend
                $matchesMeta = '';
                if ($adsetId == $adsetIdFromLog && $metaApiSpend !== null) {
                    $cumulativeMatch = abs($maxCumulative - $metaApiSpend) < 0.01;
                    $deltaMatch = abs($deltaSum - $metaApiSpend) < 0.01;
                    
                    if ($cumulativeMatch && !$deltaMatch) {
                        $matchesMeta = '<span style="color: #4ec9b0;">✅ Cumulative</span>';
                    } elseif ($deltaMatch && !$cumulativeMatch) {
                        $matchesMeta = '<span style="color: #4ec9b0;">✅ Delta Sum</span>';
                    } elseif ($cumulativeMatch && $deltaMatch) {
                        $matchesMeta = '<span style="color: #4ec9b0;">✅ Both</span>';
                    } else {
                        $matchesMeta = '<span style="color: #f48771;">❌ Neither</span>';
                    }
                } else {
                    $matchesMeta = '<span style="color: #858585;">N/A</span>';
                }
                
                $bgColor = ($diff > 1.00) ? 'background: #4a2d2d;' : '';
                
                echo "<tr style='$bgColor'>";
                echo "<td style='padding: 6px; font-family: monospace; font-size: 10px;'>" . htmlspecialchars(substr($adsetId, 0, 20)) . "...</td>";
                echo "<td style='padding: 6px; text-align: right; color: #4ec9b0;'>$" . number_format($maxCumulative, 2) . "</td>";
                echo "<td style='padding: 6px; text-align: right; color: #dcdcaa;'>$" . number_format($deltaSum, 2) . "</td>";
                echo "<td style='padding: 6px; text-align: right; " . ($diff > 1.00 ? 'color: #f48771; font-weight: bold;' : '') . "'>$" . number_format($diff, 2) . "</td>";
                echo "<td style='padding: 6px;'>$matchesMeta</td>";
                echo "</tr>";
            }
            
            echo "<tr style='background: #2d2d30; border-top: 2px solid #f48771;'>";
            echo "<td style='padding: 8px; font-weight: bold;'>TOTAL</td>";
            echo "<td style='padding: 8px; text-align: right; font-weight: bold; color: #4ec9b0;'>$" . number_format($totalCumulativeMax, 2) . "</td>";
            echo "<td style='padding: 8px; text-align: right; font-weight: bold; color: #dcdcaa;'>$" . number_format($totalDeltaSum, 2) . "</td>";
            echo "<td style='padding: 8px; text-align: right; font-weight: bold; color: #f48771;'>$" . number_format(abs($totalCumulativeMax - $totalDeltaSum), 2) . "</td>";
            echo "<td style='padding: 8px;'></td>";
            echo "</tr>";
            echo "</table>";
            
            // Critical comparison
            echo "<div style='margin-top: 20px; padding: 15px; background: #2d2d30; border-radius: 4px; border: 2px solid #f48771;'>";
            echo "<h4 style='color: #f48771; margin-top: 0;'>🔴 Critical Comparison</h4>";
            
            echo "<table style='width: 100%; border-collapse: collapse; color: #d4d4d4; font-size: 12px;'>";
            echo "<tr style='background: #1e1e1e;'><th style='padding: 8px; text-align: left; border-bottom: 1px solid #f48771;'>Metric</th><th style='padding: 8px; text-align: right; border-bottom: 1px solid #f48771;'>Value</th><th style='padding: 8px; text-align: left; border-bottom: 1px solid #f48771;'>Status</th></tr>";
            
            if ($metaApiSpend !== null) {
                $cumulativeMatch = abs($totalCumulativeMax - $metaApiSpend) < 0.01;
                $deltaMatch = abs($totalDeltaSum - $metaApiSpend) < 0.01;
                
                echo "<tr><td style='padding: 6px;'>Meta API Spend</td><td style='padding: 6px; text-align: right; font-weight: bold;'>$" . number_format($metaApiSpend, 2) . "</td><td style='padding: 6px; color: #4ec9b0;'>✅ Source of Truth</td></tr>";
                echo "<tr style='background: #252526;'><td style='padding: 6px; color: #4ec9b0;'>Total Cumulative (Max)</td><td style='padding: 6px; text-align: right; font-weight: bold;'>$" . number_format($totalCumulativeMax, 2) . "</td><td style='padding: 6px;'>" . ($cumulativeMatch ? '<span style="color: #4ec9b0;">✅ MATCHES META</span>' : '<span style="color: #f48771;">❌ Diff: $' . number_format(abs($totalCumulativeMax - $metaApiSpend), 2) . '</span>') . "</td></tr>";
                echo "<tr><td style='padding: 6px; color: #dcdcaa;'>Total Delta Sum</td><td style='padding: 6px; text-align: right; font-weight: bold;'>$" . number_format($totalDeltaSum, 2) . "</td><td style='padding: 6px;'>" . ($deltaMatch ? '<span style="color: #4ec9b0;">✅ MATCHES META</span>' : '<span style="color: #f48771;">❌ Diff: $' . number_format(abs($totalDeltaSum - $metaApiSpend), 2) . '</span>') . "</td></tr>";
                echo "<tr style='background: #252526;'><td style='padding: 6px;'>Dashboard Total</td><td style='padding: 6px; text-align: right; font-weight: bold;'>$" . number_format($dashboardTotal, 2) . "</td><td style='padding: 6px;'>" . (abs($dashboardTotal - $totalDeltaSum) < 0.01 ? '<span style="color: #dcdcaa;">Using Delta Sum</span>' : (abs($dashboardTotal - $totalCumulativeMax) < 0.01 ? '<span style="color: #4ec9b0;">Using Cumulative</span>' : '<span style="color: #f48771;">Using Neither?</span>')) . "</td></tr>";
                
                // Diagnosis
                echo "<tr style='background: #4a2d2d; border-top: 2px solid #f48771;'><td colspan='3' style='padding: 10px;'>";
                echo "<strong style='color: #f48771;'>🔍 DIAGNOSIS:</strong><br>";
                if ($cumulativeMatch && !$deltaMatch) {
                    echo "<span style='color: #4ec9b0;'>✅ Cumulative spend MATCHES Meta API. Dashboard should use CUMULATIVE (max value), not delta sum.</span><br>";
                    echo "<span style='color: #f48771;'>❌ If dashboard is using delta sum ($" . number_format($totalDeltaSum, 2) . "), that's the problem!</span>";
                } elseif ($deltaMatch && !$cumulativeMatch) {
                    echo "<span style='color: #4ec9b0;'>✅ Delta sum MATCHES Meta API. Dashboard should use DELTA SUM, not cumulative.</span><br>";
                    echo "<span style='color: #f48771;'>❌ If dashboard is using cumulative ($" . number_format($totalCumulativeMax, 2) . "), that's the problem!</span>";
                } elseif (!$cumulativeMatch && !$deltaMatch) {
                    echo "<span style='color: #f48771;'>⚠️ Neither cumulative nor delta sum matches Meta API. There may be additional issues beyond metric selection.</span>";
                } else {
                    echo "<span style='color: #4ec9b0;'>✅ Both match Meta API (unlikely but possible).</span>";
                }
                echo "</td></tr>";
            } else {
                echo "<tr><td colspan='3' style='padding: 6px; color: #f48771;'>Meta API spend not found in log - cannot compare</td></tr>";
            }
            
            echo "</table>";
            echo "</div>";
            
            // Explanation
            echo "<div style='margin-top: 15px; padding: 10px; background: #2d2d30; border-radius: 4px;'>";
            echo "<h4 style='color: #dcdcaa; margin-top: 0;'>📚 Understanding Cumulative vs Delta:</h4>";
            echo "<ul style='color: #cccccc; line-height: 1.8; font-size: 11px;'>";
            echo "<li><strong>Cumulative Spend:</strong> Running total from Meta API. Hour 8: $10.00, Hour 9: $15.00 (cumulative), Hour 10: $20.00 (cumulative). The MAX cumulative value for a day should equal Meta's total spend for that day.</li>";
            echo "<li><strong>Delta Spend:</strong> Incremental cost per hour. Hour 8: $10.00 (delta), Hour 9: $5.00 (delta), Hour 10: $5.00 (delta). Sum of all deltas = total spend, but only if we're looking at a single day's worth of hours.</li>";
            echo "<li><strong>The Problem:</strong> If we sum deltas across multiple days or wrong date ranges, we get inflated totals. Cumulative is always accurate for the date it represents.</li>";
            echo "</ul>";
            echo "</div>";
            
            echo "</div>";
            
            // Potential issues detection
            echo "<div style='background: #1e1e1e; padding: 15px; border-radius: 4px;'>";
            echo "<h3 style='color: #4ec9b0; margin-top: 0;'>🔍 Potential Issues Detection</h3>";
            
            $issues = [];
            
            // Original issues
            if ($difference >= 0.01) {
                $issues[] = "⚠️ <strong>Minor Mismatch:</strong> Dashboard total ($" . number_format($dashboardTotal, 2) . ") differs from calculated total ($" . number_format($calculatedTotal, 2) . ") by $" . number_format($difference, 2);
            }
            if ($campaignSumDifference >= 0.01) {
                $issues[] = "⚠️ <strong>Unattributed costs:</strong> $" . number_format($campaignSumDifference, 2) . " in costs are not attributed to any campaign (could be from deleted campaigns or costs without clicks)";
            }
            if (count($allCosts) == 0 && $dashboardTotal > 0.01) {
                $issues[] = "⚠️ <strong>No cost records but dashboard shows costs:</strong> All costs may be manual costs from clicks table";
            }
            
            // Major discrepancy issues from new diagnostic sections
            if ($metaApiSpend !== null && $totalHourlyDeltas > 0) {
                $majorDiff = abs($metaApiSpend - $totalHourlyDeltas);
                if ($majorDiff > 1.00) {
                    $issues[] = "🚨 <strong>MAJOR DISCREPANCY:</strong> Database total ($" . number_format($totalHourlyDeltas, 2) . ") differs from Meta API spend ($" . number_format($metaApiSpend, 2) . ") by $" . number_format($majorDiff, 2);
                }
            }
            
            if (isset($todayPstCost) && $metaApiSpend !== null) {
                $pstDiff = abs($todayPstCost - $metaApiSpend);
                if ($pstDiff > 1.00) {
                    $issues[] = "🚨 <strong>PST Date Attribution Issue:</strong> Today's PST total ($" . number_format($todayPstCost, 2) . ") differs from Meta API ($" . number_format($metaApiSpend, 2) . ") by $" . number_format($pstDiff, 2);
                }
            }
            
            if (isset($costsByPstDate) && is_array($costsByPstDate)) {
                $otherDatesTotal = 0.0;
                foreach ($costsByPstDate as $pstDate => $total) {
                    if ($pstDate != $todayInUserTz) {
                        $otherDatesTotal += $total;
                    }
                }
                if ($otherDatesTotal > 0.01) {
                    $issues[] = "⚠️ <strong>Wrong Date Attribution:</strong> $" . number_format($otherDatesTotal, 2) . " in costs are attributed to dates other than today (PST " . htmlspecialchars($todayInUserTz) . ")";
                }
            }
            
            if (isset($costsOutOfRange) && $costsOutOfRange > 0) {
                $issues[] = "⚠️ <strong>Out of Range Costs:</strong> $costsOutOfRange cost records are outside the UTC date range but may be included in calculations";
            }
            
            // Cumulative vs Delta analysis findings
            if (isset($totalCumulativeMax) && isset($totalDeltaSum) && $metaApiSpend !== null) {
                $cumulativeMatch = abs($totalCumulativeMax - $metaApiSpend) < 0.01;
                $deltaMatch = abs($totalDeltaSum - $metaApiSpend) < 0.01;
                
                if ($cumulativeMatch && !$deltaMatch) {
                    $issues[] = "🚨 <strong>CRITICAL: Using Wrong Metric!</strong> Cumulative spend ($" . number_format($totalCumulativeMax, 2) . ") matches Meta API, but dashboard may be using delta sum ($" . number_format($totalDeltaSum, 2) . "). Dashboard should use CUMULATIVE (max value).";
                } elseif ($deltaMatch && !$cumulativeMatch) {
                    $issues[] = "🚨 <strong>CRITICAL: Using Wrong Metric!</strong> Delta sum ($" . number_format($totalDeltaSum, 2) . ") matches Meta API, but dashboard may be using cumulative ($" . number_format($totalCumulativeMax, 2) . "). Dashboard should use DELTA SUM.";
                } elseif (!$cumulativeMatch && !$deltaMatch) {
                    $cumulativeDiff = abs($totalCumulativeMax - $metaApiSpend);
                    $deltaDiff = abs($totalDeltaSum - $metaApiSpend);
                    if ($cumulativeDiff < $deltaDiff) {
                        $issues[] = "⚠️ <strong>Metric Selection Issue:</strong> Cumulative ($" . number_format($totalCumulativeMax, 2) . ") is closer to Meta API than delta sum ($" . number_format($totalDeltaSum, 2) . "). Difference: $" . number_format($cumulativeDiff, 2) . " vs $" . number_format($deltaDiff, 2);
                    } else {
                        $issues[] = "⚠️ <strong>Metric Selection Issue:</strong> Delta sum ($" . number_format($totalDeltaSum, 2) . ") is closer to Meta API than cumulative ($" . number_format($totalCumulativeMax, 2) . "). Difference: $" . number_format($deltaDiff, 2) . " vs $" . number_format($cumulativeDiff, 2);
                    }
                }
                
                // Check what dashboard is actually using
                if (isset($dashboardTotal)) {
                    $dashboardUsesCumulative = abs($dashboardTotal - $totalCumulativeMax) < 0.01;
                    $dashboardUsesDelta = abs($dashboardTotal - $totalDeltaSum) < 0.01;
                    
                    if ($dashboardUsesDelta && $cumulativeMatch && !$deltaMatch) {
                        $issues[] = "🚨 <strong>CONFIRMED ISSUE:</strong> Dashboard is using DELTA SUM ($" . number_format($totalDeltaSum, 2) . ") but should use CUMULATIVE ($" . number_format($totalCumulativeMax, 2) . ") which matches Meta API!";
                    } elseif ($dashboardUsesCumulative && $deltaMatch && !$cumulativeMatch) {
                        $issues[] = "🚨 <strong>CONFIRMED ISSUE:</strong> Dashboard is using CUMULATIVE ($" . number_format($totalCumulativeMax, 2) . ") but should use DELTA SUM ($" . number_format($totalDeltaSum, 2) . ") which matches Meta API!";
                    }
                }
            }
            
            if (empty($issues)) {
                echo "<p style='color: #4ec9b0;'>✅ No issues detected. All calculations match.</p>";
            } else {
                echo "<ul style='color: #f48771; line-height: 1.8;'>";
                foreach ($issues as $issue) {
                    echo "<li>$issue</li>";
                }
                echo "</ul>";
            }
            echo "</div>";
            
        } catch (Exception $e) {
            echo "<div style='background: #1e1e1e; padding: 15px; border-radius: 4px; color: #f48771;'>";
            echo "<h3 style='color: #f48771; margin-top: 0;'>❌ Error Generating Cost Breakdown</h3>";
            $errorMsg = htmlspecialchars($e->getMessage());
            if (strpos($errorMsg, 'Bind parameter mismatch') !== false) {
                echo "<p><strong>Known Issue:</strong> Bind parameter mismatch in getAggregatedCost() when no filter is applied.</p>";
                echo "<p style='color: #858585; font-size: 12px; margin-top: 10px;'>This is a bug in the cost aggregator that occurs when calculating total cost without filters. The cost breakdown report cannot be generated, but costs are still updating correctly in the database.</p>";
                echo "<p style='color: #858585; font-size: 12px;'>Technical details: " . $errorMsg . "</p>";
            } else {
                echo "<p>" . $errorMsg . "</p>";
            }
            echo "</div>";
        }
        
        echo "</div>"; // End cost breakdown section
        
        // Gutters Campaign Cost Matching Diagnostic
        echo "<div style='background: #1e1e1e; padding: 15px; border-radius: 4px; margin-top: 20px; border: 2px solid #4ec9b0;'>";
        echo "<h3 style='color: #4ec9b0; margin-top: 0;'>🔍 Gutters Campaign Cost Matching Diagnostic</h3>";
        
        $guttersAdsetId = '120233993018390074';
        $guttersCosts = [];
        $guttersClicks = [];
        $guttersCampaigns = [];
        
        try {
            // Query costs for gutters adset
            $guttersCostQuery = $db->prepare("
            SELECT 
                date,
                hour,
                spend,
                delta_spend,
                last_synced
            FROM adset_hourly_costs
            WHERE adset_id = ?
                AND CONCAT(date, ' ', LPAD(hour, 2, '0'), ':00:00') >= ?
                AND CONCAT(date, ' ', LPAD(hour, 2, '0'), ':00:00') <= ?
            ORDER BY date DESC, hour DESC
        ");
        $guttersCostQuery->bind_param('sss', $guttersAdsetId, $utcDateFrom, $utcDateTo);
        $guttersCostQuery->execute();
        $guttersCosts = $guttersCostQuery->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Query clicks for gutters adset
        $guttersClicksQuery = $db->prepare("
            SELECT 
                cl.id as click_id,
                cl.campaign_id,
                JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.adset_id')) as adset_id,
                JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.ad_id')) as ad_id,
                cl.ts,
                DATE(cl.ts) as click_date,
                HOUR(cl.ts) as click_hour,
                cp.name as campaign_name
            FROM clicks cl
            LEFT JOIN campaigns cp ON cl.campaign_id = cp.id
            WHERE JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.adset_id')) = ?
                AND cl.ts >= ?
                AND cl.ts <= ?
            ORDER BY cl.ts DESC
            LIMIT 50
        ");
        $guttersClicksQuery->bind_param('sss', $guttersAdsetId, $utcDateFrom, $utcDateTo);
        $guttersClicksQuery->execute();
        $guttersClicks = $guttersClicksQuery->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Find campaigns associated with this adset
        $guttersCampaignsQuery = $db->prepare("
            SELECT DISTINCT
                cl.campaign_id,
                cp.name as campaign_name,
                COUNT(DISTINCT cl.id) as click_count
            FROM clicks cl
            LEFT JOIN campaigns cp ON cl.campaign_id = cp.id
            WHERE JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.adset_id')) = ?
                AND cl.ts >= ?
                AND cl.ts <= ?
            GROUP BY cl.campaign_id, cp.name
        ");
            $guttersCampaignsQuery->bind_param('sss', $guttersAdsetId, $utcDateFrom, $utcDateTo);
            $guttersCampaignsQuery->execute();
            $guttersCampaigns = $guttersCampaignsQuery->get_result()->fetch_all(MYSQLI_ASSOC);
        } catch (Exception $e) {
            echo "<p style='color: #f48771;'>❌ Error querying gutters diagnostic data: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
        
        echo "<p style='color: #dcdcaa;'><strong>Adset ID:</strong> " . htmlspecialchars($guttersAdsetId) . "</p>";
        echo "<p style='color: #dcdcaa;'><strong>Date Range:</strong> " . htmlspecialchars($utcDateFrom) . " to " . htmlspecialchars($utcDateTo) . " (UTC)</p>";
        
        // Costs summary
        $totalGuttersCost = 0.0;
        $totalGuttersDelta = 0.0;
        foreach ($guttersCosts as $cost) {
            $totalGuttersCost += (float)$cost['spend'];
            $totalGuttersDelta += (float)$cost['delta_spend'];
        }
        
        echo "<div style='margin-top: 15px;'>";
        echo "<h4 style='color: #4ec9b0;'>Cost Records:</h4>";
        if (empty($guttersCosts)) {
            echo "<p style='color: #f48771;'>❌ NO COST RECORDS found for this adset in the date range</p>";
        } else {
            echo "<p style='color: #4ec9b0;'>✅ Found " . count($guttersCosts) . " cost record(s)</p>";
            echo "<p style='color: #dcdcaa;'><strong>Total Cumulative Spend:</strong> $" . number_format($totalGuttersCost, 2) . "</p>";
            echo "<p style='color: #dcdcaa;'><strong>Total Delta Spend:</strong> $" . number_format($totalGuttersDelta, 2) . "</p>";
            
            echo "<table style='width: 100%; border-collapse: collapse; color: #d4d4d4; font-size: 12px; margin-top: 10px;'>";
            echo "<tr style='background: #2d2d30;'><th style='padding: 6px; text-align: left; border-bottom: 1px solid #4ec9b0;'>Date</th><th style='padding: 6px; text-align: left; border-bottom: 1px solid #4ec9b0;'>Hour</th><th style='padding: 6px; text-align: right; border-bottom: 1px solid #4ec9b0;'>Cumulative</th><th style='padding: 6px; text-align: right; border-bottom: 1px solid #4ec9b0;'>Delta</th></tr>";
            foreach ($guttersCosts as $cost) {
                echo "<tr>";
                echo "<td style='padding: 4px;'>" . htmlspecialchars($cost['date']) . "</td>";
                echo "<td style='padding: 4px;'>" . htmlspecialchars($cost['hour']) . ":00</td>";
                echo "<td style='padding: 4px; text-align: right; color: #4ec9b0;'>$" . number_format($cost['spend'], 2) . "</td>";
                echo "<td style='padding: 4px; text-align: right; color: #dcdcaa;'>$" . number_format($cost['delta_spend'], 2) . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
        echo "</div>";
        
        // Clicks summary
        echo "<div style='margin-top: 15px;'>";
        echo "<h4 style='color: #4ec9b0;'>Click Records:</h4>";
        if (empty($guttersClicks)) {
            echo "<p style='color: #f48771;'>❌ NO CLICKS found for this adset in the date range</p>";
        } else {
            echo "<p style='color: #4ec9b0;'>✅ Found " . count($guttersClicks) . " click(s) (showing first 50)</p>";
        }
        echo "</div>";
        
        // Campaigns summary
        echo "<div style='margin-top: 15px;'>";
        echo "<h4 style='color: #4ec9b0;'>Campaigns Using This Adset:</h4>";
        if (empty($guttersCampaigns)) {
            echo "<p style='color: #f48771;'>❌ NO CAMPAIGNS found using this adset</p>";
        } else {
            echo "<table style='width: 100%; border-collapse: collapse; color: #d4d4d4; font-size: 12px; margin-top: 10px;'>";
            echo "<tr style='background: #2d2d30;'><th style='padding: 6px; text-align: left; border-bottom: 1px solid #4ec9b0;'>Campaign ID</th><th style='padding: 6px; text-align: left; border-bottom: 1px solid #4ec9b0;'>Campaign Name</th><th style='padding: 6px; text-align: right; border-bottom: 1px solid #4ec9b0;'>Click Count</th></tr>";
            foreach ($guttersCampaigns as $camp) {
                $isGutters = stripos($camp['campaign_name'] ?? '', 'gutter') !== false;
                $rowColor = $isGutters ? 'color: #4ec9b0;' : 'color: #f48771;';
                echo "<tr>";
                echo "<td style='padding: 4px; $rowColor'>" . htmlspecialchars($camp['campaign_id']) . "</td>";
                echo "<td style='padding: 4px; $rowColor'>" . htmlspecialchars($camp['campaign_name'] ?? 'N/A') . "</td>";
                echo "<td style='padding: 4px; text-align: right; $rowColor'>" . number_format($camp['click_count']) . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
        echo "</div>";
        
        // Matching analysis
        echo "<div style='margin-top: 15px; padding: 10px; background: #2d2d30; border-radius: 4px;'>";
        echo "<h4 style='color: #4ec9b0;'>Cost Matching Analysis:</h4>";
        
        if (empty($guttersCosts) && empty($guttersClicks)) {
            echo "<p style='color: #858585;'>No costs or clicks found - adset may not be active in this date range.</p>";
        } elseif (empty($guttersCosts) && !empty($guttersClicks)) {
            echo "<p style='color: #f48771;'>❌ <strong>ISSUE:</strong> Clicks exist but NO costs found. This explains why costs don't show in Kuma.</p>";
            echo "<p style='color: #dcdcaa;'>Possible causes:</p>";
            echo "<ul style='color: #dcdcaa;'>";
            echo "<li>Costs were not fetched from Meta API for this adset</li>";
            echo "<li>Costs were fetched but not saved to database</li>";
            echo "<li>Date/hour mismatch between clicks and costs</li>";
            echo "</ul>";
        } elseif (!empty($guttersCosts) && empty($guttersClicks)) {
            echo "<p style='color: #f48771;'>⚠️ <strong>WARNING:</strong> Costs exist but NO clicks found. Costs may not match to any campaign.</p>";
        } else {
            // Check if costs match clicks by date/hour
            $matchedCosts = 0;
            $unmatchedCosts = 0;
            foreach ($guttersCosts as $cost) {
                $costDateTime = $cost['date'] . ' ' . str_pad($cost['hour'], 2, '0', STR_PAD_LEFT) . ':00:00';
                $matched = false;
                foreach ($guttersClicks as $click) {
                    $clickDateTime = $click['click_date'] . ' ' . str_pad($click['click_hour'], 2, '0', STR_PAD_LEFT) . ':00:00';
                    if ($costDateTime === $clickDateTime) {
                        $matched = true;
                        break;
                    }
                }
                if ($matched) {
                    $matchedCosts++;
                } else {
                    $unmatchedCosts++;
                }
            }
            
            if ($unmatchedCosts > 0) {
                echo "<p style='color: #f48771;'>⚠️ <strong>WARNING:</strong> $unmatchedCosts cost record(s) don't match any clicks by date/hour.</p>";
            } else {
                echo "<p style='color: #4ec9b0;'>✅ All cost records match clicks by date/hour.</p>";
            }
            
            // Check campaign attribution
            if (count($guttersCampaigns) > 1) {
                echo "<p style='color: #f48771;'>⚠️ <strong>WARNING:</strong> This adset is used by " . count($guttersCampaigns) . " different campaign(s). Costs may be shared across campaigns.</p>";
            }
        }
        
        echo "</div>";
        echo "</div>"; // End gutters diagnostic section
        
        // Account-Level API Diagnostic
        echo "<div style='background: #1e1e1e; padding: 15px; border-radius: 4px; margin-top: 20px; border: 2px solid #4ec9b0;'>";
        echo "<h3 style='color: #4ec9b0; margin-top: 0;'>📡 Account-Level API Diagnostic</h3>";
        echo "<p style='color: #858585; font-size: 12px; margin-bottom: 15px;'>Analysis of account-level API calls to understand why fallback calls occur.</p>";
        
        try {
            // Parse log content to extract account-level API information
            $logContent = $logContent ?? '';
            
            // Extract account-level API call information
            $accountLevelPattern = '/Fetching account-level adset insights.*?total_adsets_from_api[":\s]*(\d+)/s';
            preg_match_all($accountLevelPattern, $logContent, $accountLevelMatches);
            
            // Extract API request details (date range, target date)
            $apiRequestPattern = '/date_range_since[":\s]*([^\s,}]+).*?date_range_until[":\s]*([^\s,}]+).*?date_for_api[":\s]*([^\s,}]+)/s';
            preg_match_all($apiRequestPattern, $logContent, $apiRequestMatches);
            
            // Extract raw response count and filtering stats
            $rawResponsePattern = '/Raw response count[":\s]*(\d+).*?Target date[":\s]*([^\s,}]+).*?Date range[":\s]*([^\s,}]+) to ([^\s,}]+)/';
            preg_match_all($rawResponsePattern, $logContent, $rawResponseMatches);
            
            // Extract filtering stats
            $filteringPattern = '/Filtered: (\d+) kept, (\d+) skipped \(date range mode\)/';
            preg_match_all($filteringPattern, $logContent, $filteringMatches);
            
            // Extract sample result keys
            $samplePattern = '/Sample result keys[":\s]*\[([^\]]+)\].*?date_start[":\s]*([^\s,}]+).*?adset_id[":\s]*([^\s,}]+)/';
            preg_match_all($samplePattern, $logContent, $sampleMatches);
            
            // Extract fallback query information
            $fallbackPattern = '/Date range query.*?returned 0 results.*?Trying single-date query.*?Fallback single-date query returned (\d+) results/';
            preg_match_all($fallbackPattern, $logContent, $fallbackQueryMatches);
            
            // Extract fallback final count
            $fallbackFinalPattern = '/After fallback, final results count[":\s]*(\d+)/';
            preg_match_all($fallbackFinalPattern, $logContent, $fallbackFinalMatches);
            
            // Extract adset selection window information
            $adsetSelectionPattern = '/Extracting adset and ad IDs from clicks.*?utc_date_from[":\s]*([^\s,}]+).*?utc_date_to[":\s]*([^\s,}]+)/s';
            preg_match_all($adsetSelectionPattern, $logContent, $adsetSelectionMatches);
            
            // Extract fallback call information
            $fallbackPattern = '/Fallback.*?adset_id[":\s]*([^\s,}]+).*?fallback_call_count[":\s]*(\d+)/s';
            preg_match_all($fallbackPattern, $logContent, $fallbackMatches);
            
            // Extract total adsets found
            $totalAdsetsPattern = '/total_adsets_found[":\s]*(\d+)/';
            preg_match_all($totalAdsetsPattern, $logContent, $totalAdsetsMatches);
            
            echo "<div style='margin-top: 15px;'>";
            echo "<h4 style='color: #4ec9b0;'>Adset Selection Window:</h4>";
            
            if (!empty($adsetSelectionMatches[1]) && !empty($adsetSelectionMatches[2])) {
                $utcDateFrom = $adsetSelectionMatches[1][0] ?? 'N/A';
                $utcDateTo = $adsetSelectionMatches[2][0] ?? 'N/A';
                echo "<p style='color: #dcdcaa;'><strong>UTC Date Range:</strong> " . htmlspecialchars($utcDateFrom) . " to " . htmlspecialchars($utcDateTo) . "</p>";
                echo "<p style='color: #4ec9b0;'>✅ Using 'today' UTC range instead of 7-day window</p>";
            } else {
                echo "<p style='color: #f48771;'>⚠️ Could not determine adset selection window from log</p>";
                echo "<p style='color: #858585;'>Expected: UTC date range for 'today'</p>";
                echo "<p style='color: #858585;'>If not found, script may be using 7-day window (old behavior)</p>";
            }
            echo "</div>";
            
            echo "<div style='margin-top: 15px;'>";
            echo "<h4 style='color: #4ec9b0;'>Account-Level API Request Details:</h4>";
            
            if (!empty($apiRequestMatches[1]) && !empty($apiRequestMatches[2]) && !empty($apiRequestMatches[3])) {
                $dateRangeSince = $apiRequestMatches[1][0] ?? 'N/A';
                $dateRangeUntil = $apiRequestMatches[2][0] ?? 'N/A';
                $dateForApi = $apiRequestMatches[3][0] ?? 'N/A';
                
                echo "<p style='color: #dcdcaa;'><strong>Date Range (since):</strong> " . htmlspecialchars($dateRangeSince) . "</p>";
                echo "<p style='color: #dcdcaa;'><strong>Date Range (until):</strong> " . htmlspecialchars($dateRangeUntil) . "</p>";
                echo "<p style='color: #dcdcaa;'><strong>Target Date (for API):</strong> " . htmlspecialchars($dateForApi) . "</p>";
                
                if ($dateRangeSince !== $dateRangeUntil) {
                    echo "<p style='color: #4ec9b0;'>✅ Using date range with time_increment=1 (optimized)</p>";
                } else {
                    echo "<p style='color: #dcdcaa;'>Using single date query</p>";
                }
            } else {
                echo "<p style='color: #f48771;'>⚠️ Could not extract API request details from log</p>";
            }
            echo "</div>";
            
            echo "<div style='margin-top: 15px;'>";
            echo "<h4 style='color: #4ec9b0;'>Account-Level API Response Analysis:</h4>";
            
            if (!empty($rawResponseMatches[1])) {
                $rawResponseCount = (int)($rawResponseMatches[1][0] ?? 0);
                $targetDate = $rawResponseMatches[2][0] ?? 'N/A';
                $dateRangeStart = $rawResponseMatches[3][0] ?? 'N/A';
                $dateRangeEnd = $rawResponseMatches[4][0] ?? 'N/A';
                
                echo "<p style='color: #dcdcaa;'><strong>Raw Response Count:</strong> " . number_format($rawResponseCount) . "</p>";
                echo "<p style='color: #dcdcaa;'><strong>Target Date:</strong> " . htmlspecialchars($targetDate) . "</p>";
                echo "<p style='color: #dcdcaa;'><strong>Date Range:</strong> " . htmlspecialchars($dateRangeStart) . " to " . htmlspecialchars($dateRangeEnd) . "</p>";
                
                if (!empty($filteringMatches[1]) && !empty($filteringMatches[2])) {
                    $kept = (int)($filteringMatches[1][0] ?? 0);
                    $skipped = (int)($filteringMatches[2][0] ?? 0);
                    
                    echo "<p style='color: #dcdcaa;'><strong>After Filtering:</strong> " . number_format($kept) . " kept, " . number_format($skipped) . " skipped</p>";
                    
                    if ($rawResponseCount > 0 && $kept == 0 && $skipped > 0) {
                        echo "<p style='color: #f48771;'>❌ <strong>CRITICAL ISSUE:</strong> API returned " . number_format($rawResponseCount) . " results but ALL were filtered out!</p>";
                        echo "<p style='color: #dcdcaa;'>This suggests a date format mismatch or filtering logic error.</p>";
                        echo "<p style='color: #dcdcaa;'>Check if date_start format from API matches target date format.</p>";
                    }
                }
            }
            
            if (!empty($accountLevelMatches[1])) {
                $totalAdsetsFromApi = (int)($accountLevelMatches[1][0] ?? 0);
                $totalAdsetsFound = !empty($totalAdsetsMatches[1]) ? (int)($totalAdsetsMatches[1][0] ?? 0) : 0;
                
                echo "<p style='color: #dcdcaa;'><strong>Final Results Count:</strong> " . number_format($totalAdsetsFromApi) . "</p>";
                echo "<p style='color: #dcdcaa;'><strong>Total Adsets Found in Clicks:</strong> " . number_format($totalAdsetsFound) . "</p>";
                
                if ($totalAdsetsFromApi == 0 && $totalAdsetsFound > 0) {
                    echo "<p style='color: #f48771;'>❌ <strong>ISSUE:</strong> Account-level API returned 0 results but " . number_format($totalAdsetsFound) . " adsets found in clicks</p>";
                    echo "<p style='color: #dcdcaa;'>This will trigger fallback calls for each adset</p>";
                    echo "<p style='color: #dcdcaa;'><strong>Possible causes:</strong></p>";
                    echo "<ul style='color: #dcdcaa;'>";
                    echo "<li>API returned empty data array (no spend in date range)</li>";
                    echo "<li>Date filtering removed all results (date format mismatch)</li>";
                    echo "<li>API permissions insufficient</li>";
                    echo "<li>Ad account has no active adsets in date range</li>";
                    echo "</ul>";
                } elseif ($totalAdsetsFromApi > 0) {
                    echo "<p style='color: #4ec9b0;'>✅ Account-level API returned results</p>";
                    if ($totalAdsetsFromApi < $totalAdsetsFound) {
                        $missing = $totalAdsetsFound - $totalAdsetsFromApi;
                        echo "<p style='color: #f48771;'>⚠️ <strong>WARNING:</strong> " . number_format($missing) . " adset(s) missing from account-level response (will trigger fallback)</p>";
                    }
                }
            } else {
                echo "<p style='color: #f48771;'>⚠️ Could not extract account-level API results from log</p>";
            }
            echo "</div>";
            
            echo "<div style='margin-top: 15px;'>";
            echo "<h4 style='color: #4ec9b0;'>Fallback Query (Single-Date):</h4>";
            
            if (!empty($fallbackQueryMatches[1])) {
                $fallbackResultCount = (int)($fallbackQueryMatches[1][0] ?? 0);
                $fallbackFinalCount = !empty($fallbackFinalMatches[1]) ? (int)($fallbackFinalMatches[1][0] ?? 0) : 0;
                
                echo "<p style='color: #dcdcaa;'><strong>Fallback Query Triggered:</strong> Yes</p>";
                echo "<p style='color: #dcdcaa;'><strong>Fallback Query Results:</strong> " . number_format($fallbackResultCount) . "</p>";
                echo "<p style='color: #dcdcaa;'><strong>Final Results After Fallback:</strong> " . number_format($fallbackFinalCount) . "</p>";
                
                if ($fallbackResultCount > 0 && $fallbackFinalCount > 0) {
                    echo "<p style='color: #4ec9b0;'>✅ Fallback query successfully retrieved results</p>";
                    echo "<p style='color: #dcdcaa;'>This suggests the date range query with time_increment=1 may not be working correctly.</p>";
                } elseif ($fallbackResultCount == 0) {
                    echo "<p style='color: #f48771;'>❌ Fallback query also returned 0 results</p>";
                    echo "<p style='color: #dcdcaa;'>This suggests there may be no spend data in the date range, or API permissions issue.</p>";
                }
            } else {
                echo "<p style='color: #858585;'>No fallback query detected (date range query may have succeeded, or single-date query was used)</p>";
            }
            echo "</div>";
            
            echo "<div style='margin-top: 15px;'>";
            echo "<h4 style='color: #4ec9b0;'>Per-Adset Fallback Calls:</h4>";
            
            if (!empty($fallbackMatches[2])) {
                $fallbackCallCount = max(array_map('intval', $fallbackMatches[2]));
                echo "<p style='color: #dcdcaa;'><strong>Fallback Calls Made:</strong> " . number_format($fallbackCallCount) . "</p>";
                
                if ($fallbackCallCount > 0) {
                    echo "<p style='color: #f48771;'>⚠️ <strong>WARNING:</strong> Per-adset fallback calls indicate account-level API did not return all expected adsets</p>";
                    echo "<p style='color: #dcdcaa;'>Possible causes:</p>";
                    echo "<ul style='color: #dcdcaa;'>";
                    echo "<li>Adset selection window too wide (7-day vs today)</li>";
                    echo "<li>Zero-spend adsets excluded from account-level response</li>";
                    echo "<li>Date filtering issue in account-level API call</li>";
                    echo "<li>Adsets have clicks but no spend in date range</li>";
                    echo "</ul>";
                } else {
                    echo "<p style='color: #4ec9b0;'>✅ No per-adset fallback calls needed</p>";
                }
            } else {
                echo "<p style='color: #858585;'>No per-adset fallback calls detected in log</p>";
            }
            echo "</div>";
            
            echo "<div style='margin-top: 15px; padding: 10px; background: #2d2d30; border-radius: 4px;'>";
            echo "<h4 style='color: #4ec9b0;'>Expected Behavior:</h4>";
            echo "<ul style='color: #dcdcaa;'>";
            echo "<li>Adset selection should use 'today' UTC range (not 7-day window)</li>";
            echo "<li>Account-level API should return all adsets with clicks 'today'</li>";
            echo "<li>Zero-spend adsets should be included in account-level response</li>";
            echo "<li>Fallback calls should only occur for truly missing adsets (rare)</li>";
            echo "</ul>";
            echo "</div>";
            
        } catch (Exception $e) {
            echo "<p style='color: #f48771;'>❌ Error analyzing account-level API diagnostic: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
        
        echo "</div>"; // End account-level API diagnostic section
        endif; // $bootstrapError === null
        ?>
        
        <div style="margin-top: 20px; padding: 15px; background: #2d2d30; border-radius: 4px;">
            <h3 style="color: #4ec9b0; margin-top: 0;">💡 Troubleshooting Tips:</h3>
            <ul style="color: #cccccc; line-height: 1.8;">
                <li>If you see "No Facebook traffic sources found" - check your traffic sources configuration</li>
                <li>If you see "No campaigns with ad accounts found" - check if:
                    <ul>
                        <li>Your campaigns have <code>facebook_marketing_ad_account_id</code> set</li>
                        <li>There are clicks in the <strong>last 7 days</strong> (this is a requirement)</li>
                        <li>The traffic source is correctly identified as Facebook</li>
                    </ul>
                </li>
                <li>If the log is empty or very short, the script may be exiting early</li>
                <li>Compare with the test script output to see what's different</li>
            </ul>
        </div>
        
        <p style="margin-top: 30px; color: #858585; font-size: 12px;">
            <a href="view-cron-log.php" style="color: #4ec9b0;">🔄 Refresh</a> | 
            <a href="check-cron-status.php" style="color: #4ec9b0;">📊 Check Status</a> |
            <a href="test-fb-cost-updater.php" style="color: #4ec9b0;">🧪 Test Script</a>
        </p>
    </div>
<?php
// Close database connection
if (isset($db)) {
    $db->close();
}
?>
</body>
</html>

