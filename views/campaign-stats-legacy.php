<?php
// Campaign Stats Page - Advanced Performance Analytics
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Utils/Formatter.php';
require_once __DIR__ . '/../src/Database/ClicksTableResolver.php';
use SimpleKuma\Database\ClicksTableResolver;
use SimpleKuma\Utils\Formatter;

// Mobile detection function
function isMobileDevice(): bool {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ua = strtolower($userAgent);
    
    $mobilePatterns = [
        'mobile', 'android', 'iphone', 'ipod', 'ipad', 'blackberry',
        'windows phone', 'opera mini', 'palm', 'smartphone', 'tablet'
    ];
    
    foreach ($mobilePatterns as $pattern) {
        if (strpos($ua, $pattern) !== false) {
            return true;
        }
    }
    return false;
}

$isMobile = isMobileDevice();

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
// Ensure MySQL session timezone is UTC for consistent timestamp storage
$db->query("SET time_zone = '+00:00'");

// Include archived clicks in stats when clicks_unified view is up to date
$clicksTable = ClicksTableResolver::getStatsTable($db);

// Check if traffic_source_id column exists in clicks table
$trafficSourceColumnExists = false;
$checkColumn = $db->query("SELECT COUNT(*) as count FROM information_schema.COLUMNS 
                            WHERE TABLE_SCHEMA = DATABASE() 
                            AND TABLE_NAME = 'clicks' 
                            AND COLUMN_NAME = 'traffic_source_id'");
if ($checkColumn && $row = $checkColumn->fetch_assoc()) {
    $trafficSourceColumnExists = ($row['count'] > 0);
}

$userTimezone = $GLOBALS['userTimezone'] ?? 'UTC';
$userCurrency = $GLOBALS['userCurrency'] ?? 'USD';

// Normalize timezone for MySQL CONVERT_TZ (requires proper identifiers)
$timezoneMap = [
    'PT' => 'America/Los_Angeles',
    'PST' => 'America/Los_Angeles',
    'PDT' => 'America/Los_Angeles',
    'ET' => 'America/New_York',
    'EST' => 'America/New_York',
    'EDT' => 'America/New_York',
    'CT' => 'America/Chicago',
    'CST' => 'America/Chicago',
    'CDT' => 'America/Chicago',
    'MT' => 'America/Denver',
    'MST' => 'America/Denver',
    'MDT' => 'America/Denver',
];
if (isset($timezoneMap[$userTimezone])) {
    $userTimezone = $timezoneMap[$userTimezone];
}
try {
    $tz = new DateTimeZone($userTimezone);
    $userTimezone = $tz->getName(); // Get canonical name
} catch (Exception $e) {
    $userTimezone = 'UTC';
}
$campaign = new \SimpleKuma\Entity\Campaign($db);
$offer = new \SimpleKuma\Entity\Offer($db);
$landingPage = new \SimpleKuma\Entity\LandingPage($db);
$trafficSource = new \SimpleKuma\Entity\TrafficSource($db);
$costAggregator = new \SimpleKuma\Facebook\FacebookCostAggregator($db);

// PERFORMANCE: Request-level cache for getAggregatedCost to avoid duplicate queries (plan 3.3)
$requestCostCache = [];

// Get campaign ID from query string (if coming from campaign overview)
$selectedCampaignId = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : null;

// Date range defaults (in user's timezone)
$todayInUserTz = Formatter::getTodayInTimezone($userTimezone);
// Default to today's date for both from and to
$dateFrom = $_GET['date_from'] ?? $todayInUserTz;
$dateTo = $_GET['date_to'] ?? $todayInUserTz;

// Convert user's date range to UTC for database queries
$utcDateRange = Formatter::convertDateRangeToUTC($dateFrom, $dateTo, $userTimezone);
$utcDateFrom = $utcDateRange['from'];
$utcDateTo = $utcDateRange['to'];

// Status filter (single value for dropdown; array from saved preference when 2 statuses)
$statusFilter = $_GET['status'] ?? 'all'; // all, active, paused, archived
$statusFilterArray = $GLOBALS['campaignStatsStatusFilterArray'] ?? null; // 1–2 statuses for WHERE IN when saved preference is used

// Chart view selector
$chartView = $_GET['chart_view'] ?? 'visitors_clicks_conversions'; // visitors_clicks_conversions, revenue, etc.

// Group by selector
$groupBy = $_GET['group_by'] ?? 'campaign'; // campaign, offer, landing_page, token, slug

// Token-based filtering (support up to 3 tokens for drill-down)
$selectedTokens = [];
if (!empty($_GET['token1'])) {
    $selectedTokens[] = $_GET['token1'];
    if (!empty($_GET['token2'])) {
        $selectedTokens[] = $_GET['token2'];
        if (!empty($_GET['token3'])) {
            $selectedTokens[] = $_GET['token3'];
        }
    }
}
// Keep backward compatibility with single token
$selectedToken = $selectedTokens[0] ?? $_GET['token'] ?? null;
$tokenValueFilter = $_GET['token_value'] ?? null; // Specific token value to filter

// Pre-aggregation: use clicks_daily_summary when no token/target filter (plan: stats pre-aggregation)
$useDailySummaryForStats = (empty($selectedTokens) && ($tokenValueFilter === null || $tokenValueFilter === ''));
$dailySummaryTableExists = false;
if ($useDailySummaryForStats) {
    $checkTable = $db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clicks_daily_summary' LIMIT 1");
    $dailySummaryTableExists = ($checkTable && $checkTable->num_rows > 0);
    if (!$dailySummaryTableExists) {
        $useDailySummaryForStats = false;
    }
}

// Selected traffic source filter (separate from tokens)
$selectedTrafficSourceId = !empty($_GET['traffic_source_id']) ? (int)$_GET['traffic_source_id'] : null;
$selectedTrafficSourceName = null;
if ($selectedTrafficSourceId) {
    $tsEntity = new \SimpleKuma\Entity\TrafficSource($db);
    $tsData = $tsEntity->getById($selectedTrafficSourceId);
    $selectedTrafficSourceName = $tsData['name'] ?? null;
}

// View mode: standard or target (token breakdown)
$viewMode = $_GET['view'] ?? 'standard'; // standard, target

// Search and pagination
$searchQuery = $_GET['search'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(10, min(500, (int)($_GET['per_page'] ?? 50)));
$offset = ($page - 1) * $perPage;

// Get all campaigns for dropdown
$allCampaigns = $campaign->getAll();

// Get selected campaign details (do this early so it's available for token extraction)
$selectedCampaign = null;
$isAutoDetectCampaign = false;
if ($selectedCampaignId) {
    $selectedCampaign = $campaign->getById($selectedCampaignId);
    $isAutoDetectCampaign = empty($selectedCampaign['traffic_source_id']);
}

// Build campaign filter (if campaign selected)
$campaignFilter = '';
$campaignFilterParams = [];
if ($selectedCampaignId) {
    $campaignFilter = 'AND cl.campaign_id = ?';
    $campaignFilterParams[] = $selectedCampaignId;
}

// Build traffic source filter (if traffic source selected)
$trafficSourceFilter = '';
$trafficSourceFilterParams = [];
if ($selectedTrafficSourceId) {
    if ($trafficSourceColumnExists) {
        $trafficSourceFilter = 'AND cl.traffic_source_id = ?';
    } else {
        // Fallback: filter by campaign's traffic_source_id (use c_summary alias for summary query)
        $trafficSourceFilter = 'AND c_summary.traffic_source_id = ?';
    }
    $trafficSourceFilterParams[] = $selectedTrafficSourceId;
}

// Build date filter
$dateFilter = 'cl.ts >= ? AND cl.ts <= ?';
$dateParams = [$utcDateFrom, $utcDateTo];

// Check if this is an AJAX request for data loading (needed early for query skipping)
$isAjaxRequest = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$wantsJson = !empty($_GET['format']) && $_GET['format'] === 'json';
$isDataRequest = $isAjaxRequest || $wantsJson;

// PERFORMANCE: Server-side timing for AJAX/data requests (plan 3.1 Option B)
$timing = [];
$timingSegmentStart = null;
if ($isDataRequest) {
    $timing['_start'] = microtime(true);
    $timingSegmentStart = $timing['_start'];
}

// PERFORMANCE: When only target (stats overview) is needed, skip offer/LP/campaign and chart (plan 3.2)
$targetOnlyRequest = $isDataRequest && !empty($_GET['target_only']) && $_GET['target_only'] === '1';

// TESTING: Skip specific sections via GET to isolate slowdown (e.g. ?no_chart=1&no_offer=1)
$skipChart = !empty($_GET['no_chart']);
$skipOffer = !empty($_GET['no_offer']);
$skipLp = !empty($_GET['no_lp']);
$skipCampaign = !empty($_GET['no_campaign']);
$skipTarget = !empty($_GET['no_target']);

// LP offer breakdown: show which offers received clicks from each LP (persisted via GET + localStorage)
$showLpOfferBreakdown = !empty($_GET['show_lp_offer_breakdown']) && $_GET['show_lp_offer_breakdown'] === '1';

// Determine if we should use AJAX loading (target mode with tokens - heavy calculation)
// Check if we have cached data from AJAX request (to avoid recalculation on reload)
$hasCachedData = false;
$cachedStatsData = null;

// Ensure session is started for caching
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Create cache key from request parameters to ensure cache matches current request
$cacheKeyParams = [
    'campaign_id' => $selectedCampaignId,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'token1' => $selectedTokens[0] ?? null,
    'token2' => $selectedTokens[1] ?? null,
    'token3' => $selectedTokens[2] ?? null,
    'traffic_source_id' => $selectedTrafficSourceId ?? null,
    'view_mode' => $viewMode,
    'no_chart' => $skipChart,
    'no_offer' => $skipOffer,
    'no_lp' => $skipLp,
    'no_campaign' => $skipCampaign,
    'no_target' => $skipTarget,
    'show_lp_offer_breakdown' => $showLpOfferBreakdown
];
$currentCacheKey = md5(json_encode($cacheKeyParams));

// Check cache with parameter validation
if (isset($_SESSION['stats_data_cache']) && 
    isset($_SESSION['stats_data_cache_key']) && 
    isset($_SESSION['stats_data_timestamp'])) {
    // Use cached data if it matches current request AND is less than 30 seconds old
    if ($_SESSION['stats_data_cache_key'] === $currentCacheKey && 
        time() - $_SESSION['stats_data_timestamp'] < 30) {
        $hasCachedData = true;
        $cachedStatsData = $_SESSION['stats_data_cache'];
    } else {
        // Clear mismatched or expired cache
        unset($_SESSION['stats_data_cache']);
        unset($_SESSION['stats_data_cache_key']);
        unset($_SESSION['stats_data_timestamp']);
    }
}

$useAjaxLoading = ($viewMode === 'target' && !empty($selectedTokens) && !$isDataRequest && !$hasCachedData);

// Plan 2.3: Raise memory and timeout for target+token view to avoid OOM and timeouts on 14-day
if ($viewMode === 'target' && !empty($selectedTokens)) {
    @ini_set('memory_limit', '256M');
}

// Check detected traffic sources for auto-detect campaigns (populate $detectedTrafficSources)
// This needs to be checked early so we can use it for token loading
$hasMultipleTrafficSources = false;
$detectedTrafficSources = []; // List of traffic sources with clicks for this campaign
if ($selectedCampaignId) {
    if ($trafficSourceColumnExists) {
        $checkMultipleTrafficSources = $db->prepare("
            SELECT 
                COUNT(DISTINCT cl.traffic_source_id) as traffic_source_count,
                cl.traffic_source_id,
                ts.name as traffic_source_name
            FROM {$clicksTable} cl
            LEFT JOIN traffic_sources ts ON cl.traffic_source_id = ts.id
            WHERE {$dateFilter}
            {$campaignFilter}
            AND cl.traffic_source_id IS NOT NULL
            GROUP BY cl.traffic_source_id, ts.name
            ORDER BY ts.name
        ");
    } else {
        // Fallback: use campaign's traffic_source_id
        $checkMultipleTrafficSources = $db->prepare("
            SELECT 
                1 as traffic_source_count,
                c.traffic_source_id,
                ts.name as traffic_source_name
            FROM {$clicksTable} cl
            INNER JOIN campaigns c ON cl.campaign_id = c.id
            LEFT JOIN traffic_sources ts ON c.traffic_source_id = ts.id
            WHERE {$dateFilter}
            {$campaignFilter}
            AND c.traffic_source_id IS NOT NULL
            GROUP BY c.traffic_source_id, ts.name
            ORDER BY ts.name
        ");
    }
    $checkBindTypes = 'ss';
    $checkBindValues = $dateParams;
    if (!empty($campaignFilterParams)) {
        $checkBindTypes .= 'i';
        $checkBindValues = array_merge($dateParams, $campaignFilterParams);
    }
    $checkMultipleTrafficSources->bind_param($checkBindTypes, ...$checkBindValues);
    $checkMultipleTrafficSources->execute();
    $trafficSourceResults = $checkMultipleTrafficSources->get_result();
    $trafficSourceCount = 0;
    while ($row = $trafficSourceResults->fetch_assoc()) {
        $detectedTrafficSources[] = [
            'id' => (int)$row['traffic_source_id'],
            'name' => $row['traffic_source_name'] ?? 'Unknown'
        ];
        $trafficSourceCount++;
    }
    $hasMultipleTrafficSources = $trafficSourceCount > 1;
    
    // If traffic source selected, get its name
    if ($selectedTrafficSourceId) {
        foreach ($detectedTrafficSources as $ts) {
            if ($ts['id'] === $selectedTrafficSourceId) {
                $selectedTrafficSourceName = $ts['name'];
                break;
            }
        }
        // Fallback: query if not in detected list
        if (!$selectedTrafficSourceName) {
            $tsStmt = $db->prepare("SELECT name FROM traffic_sources WHERE id = ?");
            $tsStmt->bind_param('i', $selectedTrafficSourceId);
            $tsStmt->execute();
            $tsResult = $tsStmt->get_result();
            $tsRow = $tsResult->fetch_assoc();
            $selectedTrafficSourceName = $tsRow['name'] ?? 'Unknown';
        }
    }
}

// Helper function to convert adset_name to adset_id for indexed queries
// This optimizes performance by using indexed adset_id instead of JSON_EXTRACT on adset_name
function getAdsetIdFromName($db, $adsetName, $dateFrom = null, $dateTo = null) {
    if (empty($adsetName)) {
        return null;
    }
    
    $query = "SELECT DISTINCT adset_id FROM {$clicksTable} 
              WHERE adset_id IS NOT NULL 
              AND JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.adset_name')) = ?";
    $params = [$adsetName];
    $types = 's';
    
    if ($dateFrom && $dateTo) {
        $query .= " AND ts >= ? AND ts <= ?";
        $params[] = $dateFrom;
        $params[] = $dateTo;
        $types .= 'ss';
    }
    
    $query .= " LIMIT 1";
    
    $stmt = $db->prepare($query);
    if (!$stmt) {
        return null;
    }
    
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    return $row ? $row['adset_id'] : null;
}

// Helper function to convert ad_name to ad_id for indexed queries
// This optimizes performance by using indexed ad_id instead of JSON_EXTRACT on ad_name
function getAdIdFromName($db, $adName, $dateFrom = null, $dateTo = null) {
    if (empty($adName)) {
        return null;
    }
    
    $query = "SELECT DISTINCT ad_id FROM {$clicksTable} 
              WHERE ad_id IS NOT NULL 
              AND JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.ad_name')) = ?";
    $params = [$adName];
    $types = 's';
    
    if ($dateFrom && $dateTo) {
        $query .= " AND ts >= ? AND ts <= ?";
        $params[] = $dateFrom;
        $params[] = $dateTo;
        $types .= 'ss';
    }
    
    $query .= " LIMIT 1";
    
    $stmt = $db->prepare($query);
    if (!$stmt) {
        return null;
    }
    
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    return $row ? $row['ad_id'] : null;
}

// Universal token filter builder - future-proof solution for all token types
// Automatically routes to optimal filtering method (generated columns, built-in columns, or JSON_EXTRACT)
// This ensures performance optimization works for current AND future tokens
function buildTokenFilter($tokenParam, $tokenValue, $db, $dateFrom = null, $dateTo = null) {
    if (empty($tokenParam) || $tokenValue === null || $tokenValue === '') {
        return null;
    }
    
    // Built-in tokens that have actual table columns (already optimized)
    $builtInTokens = [
        ['parameter' => 'country', 'column' => 'cl.country'],
        ['parameter' => 'region', 'column' => 'cl.region'],
        ['parameter' => 'city', 'column' => 'cl.city'],
        ['parameter' => 'device', 'column' => 'cl.device'],
        ['parameter' => 'device_brand', 'column' => 'cl.device_brand'],
        ['parameter' => 'device_model', 'column' => 'cl.device_model'],
        ['parameter' => 'os', 'column' => 'cl.os'],
        ['parameter' => 'os_version', 'column' => 'cl.os_version'],
        ['parameter' => 'browser', 'column' => 'cl.browser'],
        ['parameter' => 'browser_version', 'column' => 'cl.browser_version'],
        ['parameter' => 'ip', 'column' => 'cl.ip'],
        ['parameter' => 'campaign_id', 'column' => 'cl.campaign_id'],
        ['parameter' => 'offer_id', 'column' => 'cl.offer_id'],
        ['parameter' => 'landing_page_id', 'column' => 'cl.landing_page_id'],
    ];
    
    // ad_id/adset_id: use same expression as display (JSON first, then generated column) for non-numeric values
    if ($tokenParam === 'ad_id' || $tokenParam === 'adset_id') {
        $jsonPath = "'\$." . "traffic_source_tokens.{$tokenParam}'";
        $tokenExpr = "COALESCE(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, {$jsonPath}))), ''), CAST(cl.{$tokenParam} AS CHAR), '0')";
        return [
            "{$tokenExpr} = ?",
            [$tokenValue]
        ];
    }
    
    // Use adset_name_value directly to include ALL clicks with this adset name.
    // The previous getAdsetIdFromName approach used LIMIT 1, so multiple adset_ids sharing the same
    // name would only match one - undercounting cost in breakdown.
    if ($tokenParam === 'adset_name') {
        if ($tokenValue === 'N/A') {
            return ["(cl.adset_name_value IS NULL OR cl.adset_name_value = '')", []];
        }
        return [
            "cl.adset_name_value = ?",
            [$tokenValue]
        ];
    }
    
    // Use ad_name_value directly to include ALL clicks with this ad name.
    // The previous getAdIdFromName approach used LIMIT 1, so multiple ad_ids sharing the same
    // name (or {{ad.name}} placeholder) would only match one ad - undercounting cost.
    if ($tokenParam === 'ad_name') {
        if ($tokenValue === 'N/A') {
            return ["(cl.ad_name_value IS NULL OR cl.ad_name_value = '')", []];
        }
        return [
            "cl.ad_name_value = ?",
            [$tokenValue]
        ];
    }
    
    // Check for built-in tokens (use actual table columns - already indexed)
    foreach ($builtInTokens as $builtIn) {
        if ($builtIn['parameter'] === $tokenParam) {
            return [
                "{$builtIn['column']} = ?",
                [$tokenValue]
            ];
        }
    }
    
    // Fallback: JSON_EXTRACT for custom tokens (rarely used in WHERE clauses)
    // Most custom tokens are extracted in PHP after fetching rows, not filtered in SQL
    // This is acceptable performance-wise since custom tokens are typically not used for filtering
    return [
        "JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.{$tokenParam}')) = ?",
        [$tokenValue]
    ];
}

// Get available tokens from the selected campaign's traffic source (not all traffic sources)
// Store as array with name and parameter for display
$availableTokens = [];

// If a traffic source is selected in the filter, use tokens from that specific traffic source
if ($selectedTrafficSourceId) {
    // User selected a specific traffic source - only show tokens from that source
    $filterTrafficSource = $trafficSource->getById($selectedTrafficSourceId);
    if ($filterTrafficSource) {
        $tokensJson = $filterTrafficSource['tokens_json'] ?? [];
        // Handle if it's already decoded or still a string
        if (is_string($tokensJson)) {
            $tokens = json_decode($tokensJson, true) ?? [];
        } else {
            $tokens = is_array($tokensJson) ? $tokensJson : [];
        }
        
        foreach ($tokens as $token) {
            $paramName = $token['parameter'] ?? '';
            $tokenName = $token['name'] ?? $paramName; // Use name if available, fallback to parameter
            if ($paramName) {
                // Check if we already have this parameter
                $exists = false;
                foreach ($availableTokens as $existing) {
                    if ($existing['parameter'] === $paramName) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $availableTokens[] = [
                        'name' => $tokenName,
                        'parameter' => $paramName
                    ];
                }
            }
        }
    }
} elseif ($selectedCampaignId && $selectedCampaign) {
    // No traffic source filter selected - show tokens based on campaign type
    if ($isAutoDetectCampaign) {
        // Auto-detect campaign: show tokens from all detected traffic sources for this campaign
        if (!empty($detectedTrafficSources)) {
            foreach ($detectedTrafficSources as $detectedTs) {
                $tsData = $trafficSource->getById($detectedTs['id']);
                if ($tsData) {
                    $tokensJson = $tsData['tokens_json'] ?? [];
                    // Handle if it's already decoded or still a string
                    if (is_string($tokensJson)) {
                        $tokens = json_decode($tokensJson, true) ?? [];
                    } else {
                        $tokens = is_array($tokensJson) ? $tokensJson : [];
                    }
                    
                    foreach ($tokens as $token) {
                        $paramName = $token['parameter'] ?? '';
                        $tokenName = $token['name'] ?? $paramName;
                        if ($paramName) {
                            // Check if we already have this parameter
                            $exists = false;
                            foreach ($availableTokens as $existing) {
                                if ($existing['parameter'] === $paramName) {
                                    $exists = true;
                                    break;
                                }
                            }
                            if (!$exists) {
                                $availableTokens[] = [
                                    'name' => $tokenName,
                                    'parameter' => $paramName
                                ];
                            }
                        }
                    }
                }
            }
        }
    } elseif (!empty($selectedCampaign['traffic_source_id'])) {
        // Campaign has a specific traffic source - only get tokens from that traffic source
        $campaignTrafficSource = $trafficSource->getById((int)$selectedCampaign['traffic_source_id']);
        
        if ($campaignTrafficSource) {
            $tokensJson = $campaignTrafficSource['tokens_json'] ?? [];
            // Handle if it's already decoded or still a string
            if (is_string($tokensJson)) {
                $tokens = json_decode($tokensJson, true) ?? [];
            } else {
                $tokens = is_array($tokensJson) ? $tokensJson : [];
            }
            
            foreach ($tokens as $token) {
                $paramName = $token['parameter'] ?? '';
                $tokenName = $token['name'] ?? $paramName; // Use name if available, fallback to parameter
                if ($paramName) {
                    // Check if we already have this parameter
                    $exists = false;
                    foreach ($availableTokens as $existing) {
                        if ($existing['parameter'] === $paramName) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        $availableTokens[] = [
                            'name' => $tokenName,
                            'parameter' => $paramName
                        ];
                    }
                }
            }
        }
    }
} else {
    // If no campaign selected, show tokens from all traffic sources
    $allTrafficSources = $trafficSource->getAll();
    foreach ($allTrafficSources as $ts) {
        $tokensJson = $ts['tokens_json'] ?? [];
        if (is_string($tokensJson)) {
            $tokens = json_decode($tokensJson, true) ?? [];
        } else {
            $tokens = is_array($tokensJson) ? $tokensJson : [];
        }
        
        foreach ($tokens as $token) {
            $paramName = $token['parameter'] ?? '';
            $tokenName = $token['name'] ?? $paramName; // Use name if available, fallback to parameter
            if ($paramName) {
                // Check if we already have this parameter
                $exists = false;
                foreach ($availableTokens as $existing) {
                    if ($existing['parameter'] === $paramName) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $availableTokens[] = [
                        'name' => $tokenName,
                        'parameter' => $paramName
                    ];
                }
            }
        }
    }
}

// Get custom campaign tokens from selected campaign or all campaigns
$customTokens = [];
if ($selectedCampaignId && $selectedCampaign) {
    $customTokensJson = $selectedCampaign['custom_tokens_json'] ?? [];
    // Handle if it's already decoded or still a string
    if (is_string($customTokensJson)) {
        $customTokensArray = json_decode($customTokensJson, true) ?? [];
    } else {
        $customTokensArray = is_array($customTokensJson) ? $customTokensJson : [];
    }
    
    foreach ($customTokensArray as $token) {
        $paramName = $token['parameter'] ?? '';
        if ($paramName) {
            $customTokens[] = [
                'name' => $token['name'] ?? $paramName,
                'parameter' => $paramName
            ];
        }
    }
}

// Built-in tracker variables (stored directly in clicks table columns)
// NOTE: traffic_source_id removed - it's now a separate filter dropdown
$builtInTokens = [
    ['name' => 'Location (Country)', 'parameter' => 'country', 'column' => 'cl.country'],
    ['name' => 'State/Region', 'parameter' => 'region', 'column' => 'cl.region'],
    ['name' => 'City', 'parameter' => 'city', 'column' => 'cl.city'],
    ['name' => 'Device', 'parameter' => 'device', 'column' => 'cl.device'],
    ['name' => 'Device Brand', 'parameter' => 'device_brand', 'column' => 'cl.device_brand'],
    ['name' => 'Device Model', 'parameter' => 'device_model', 'column' => 'cl.device_model'],
    ['name' => 'Operating System', 'parameter' => 'os', 'column' => 'cl.os'],
    ['name' => 'OS Version', 'parameter' => 'os_version', 'column' => 'cl.os_version'],
    ['name' => 'Browser', 'parameter' => 'browser', 'column' => 'cl.browser'],
    ['name' => 'Browser Version', 'parameter' => 'browser_version', 'column' => 'cl.browser_version'],
    ['name' => 'IP Address', 'parameter' => 'ip', 'column' => 'cl.ip'],
];

// Helper function to get traffic source icon
$getTrafficSourceIcon = function($trafficSourceName) {
    $name = strtolower($trafficSourceName);
    // Check for Kuma Auto Detected first
    if (strpos($name, 'kuma') !== false && (strpos($name, 'auto') !== false || strpos($name, 'detect') !== false)) {
        return ASSETS_BASE_URL . '/assets/images/autodetectbear.png';
    } elseif (strpos($name, 'facebook') !== false) {
        return ASSETS_BASE_URL . '/assets/images/tfacebook.png';
    } elseif (strpos($name, 'google') !== false) {
        return ASSETS_BASE_URL . '/assets/images/tgoogle.png';
    } elseif (strpos($name, 'youtube') !== false) {
        return ASSETS_BASE_URL . '/assets/images/tyoutube.png';
    } elseif (strpos($name, 'bing') !== false) {
        return ASSETS_BASE_URL . '/assets/images/tbing.png';
    }
    return null; // No icon for other sources
};

// Get summary statistics
// PERFORMANCE: Skip heavy summary query if we have cached target performance data (to avoid duplicate work)
// Pre-aggregation: when no token filter, read from clicks_daily_summary (plan: stats pre-aggregation Phase 1)
$summary = null;
if (!$hasCachedData) {
    if ($useDailySummaryForStats) {
        // Fast path: aggregate from clicks_daily_summary (no raw clicks scan)
        $summaryDateFrom = date('Y-m-d', strtotime($utcDateFrom));
        $summaryDateTo = date('Y-m-d', strtotime($utcDateTo));
        $summaryDailyStmt = $db->prepare("
            SELECT 
                COALESCE(SUM(s.clicks), 0) as total_visitors,
                (SELECT COALESCE(SUM(s2.lp_clicks), 0) FROM clicks_daily_summary s2
                    WHERE s2.summary_date >= ? AND s2.summary_date <= ?
                    AND s2.offer_id IS NOT NULL AND s2.landing_page_id IS NOT NULL
                    " . ($campaignFilter ? ' ' . str_replace('cl.campaign_id', 's2.campaign_id', $campaignFilter) : '') . "
                    " . ($trafficSourceFilter ? ' ' . str_replace(['cl.traffic_source_id', 'c.traffic_source_id', 'c_summary.traffic_source_id'], 's2.traffic_source_id', $trafficSourceFilter) : '') . "
                ) as total_clicks,
                COALESCE(SUM(s.conversions), 0) as total_conversions,
                COALESCE(SUM(s.cost), 0) as total_manual_cost,
                COALESCE(SUM(s.revenue), 0) as total_revenue
            FROM clicks_daily_summary s
            WHERE s.summary_date >= ? AND s.summary_date <= ? AND ((s.offer_id IS NULL) OR (s.landing_page_id IS NULL))
            " . ($campaignFilter ? ' ' . str_replace('cl.campaign_id', 's.campaign_id', $campaignFilter) : '') . "
            " . ($trafficSourceFilter ? ' ' . str_replace('cl.traffic_source_id', 's.traffic_source_id', str_replace('c_summary.traffic_source_id', 's.traffic_source_id', $trafficSourceFilter)) : '') . "
        ");
        $summaryDailyTypes = 'ss';
        $summaryDailyValues = [$summaryDateFrom, $summaryDateTo];
        if (!empty($campaignFilterParams)) {
            $summaryDailyTypes .= str_repeat('i', count($campaignFilterParams));
            $summaryDailyValues = array_merge($summaryDailyValues, $campaignFilterParams);
        }
        if (!empty($trafficSourceFilterParams)) {
            $summaryDailyTypes .= str_repeat('i', count($trafficSourceFilterParams));
            $summaryDailyValues = array_merge($summaryDailyValues, $trafficSourceFilterParams);
        }
        $summaryDailyTypes .= 'ss';
        $summaryDailyValues = array_merge($summaryDailyValues, [$summaryDateFrom, $summaryDateTo]);
        if (!empty($campaignFilterParams)) {
            $summaryDailyTypes .= str_repeat('i', count($campaignFilterParams));
            $summaryDailyValues = array_merge($summaryDailyValues, $campaignFilterParams);
        }
        if (!empty($trafficSourceFilterParams)) {
            $summaryDailyTypes .= str_repeat('i', count($trafficSourceFilterParams));
            $summaryDailyValues = array_merge($summaryDailyValues, $trafficSourceFilterParams);
        }
        $summaryDailyStmt->bind_param($summaryDailyTypes, ...$summaryDailyValues);
        $summaryDailyStmt->execute();
        $row = $summaryDailyStmt->get_result()->fetch_assoc();
        $summaryDailyStmt->close();
        $summary = [
            'total_visitors' => (int)($row['total_visitors'] ?? 0),
            'total_clicks' => (int)($row['total_clicks'] ?? 0),
            'total_conversions' => (int)($row['total_conversions'] ?? 0),
            'total_manual_cost' => (float)($row['total_manual_cost'] ?? 0),
            'total_revenue' => (float)($row['total_revenue'] ?? 0),
            'invalid_clicks' => 0,
            'mobile_visitors' => 0,
            'desktop_visitors' => 0,
            'tablet_visitors' => 0,
        ];
    } else {
        // Build join for traffic source check
        $summaryTrafficSourceJoin = $trafficSourceColumnExists
            ? ""
            : "LEFT JOIN campaigns c_summary ON cl.campaign_id = c_summary.id";
        // Exclude test/Meta approval clicks at WHERE so we don't scan those rows (Facebook = 4)
        $summaryInvalidClickExclusion = "AND (" . ($trafficSourceColumnExists ? "cl.traffic_source_id != 4" : "c_summary.traffic_source_id != 4") . " OR (cl.ad_id IS NOT NULL AND cl.adset_id IS NOT NULL))";

        $summaryQuery = $db->prepare("
    SELECT 
        COUNT(DISTINCT CASE 
            -- For Facebook traffic source, require both ad_id AND adset_id (exclude invalid clicks)
            -- PERFORMANCE: Use generated columns (ad_id, adset_id) instead of JSON_EXTRACT for index usage
            WHEN (" . ($trafficSourceColumnExists ? "cl.traffic_source_id = 4" : "c_summary.traffic_source_id = 4") . ") THEN 
                CASE 
                    WHEN cl.ad_id IS NOT NULL 
                        AND cl.adset_id IS NOT NULL
                    THEN cl.id
                    ELSE NULL
                END
            -- For other traffic sources, count all clicks
            ELSE cl.id
        END) as total_visitors,
        COUNT(DISTINCT CASE 
            WHEN cl.lp_click = 1 THEN
                CASE 
                    WHEN (" . ($trafficSourceColumnExists ? "cl.traffic_source_id = 4" : "c_summary.traffic_source_id = 4") . ") THEN 
                        CASE 
                            -- PERFORMANCE: Use generated columns (ad_id, adset_id) instead of JSON_EXTRACT for index usage
                            WHEN cl.ad_id IS NOT NULL 
                                AND cl.adset_id IS NOT NULL
                            THEN cl.id
                            ELSE NULL
                        END
                    ELSE cl.id
                END
            ELSE NULL
        END) as total_clicks,
        COUNT(DISTINCT CASE 
            WHEN (" . ($trafficSourceColumnExists ? "cl.traffic_source_id = 4" : "c_summary.traffic_source_id = 4") . ") THEN
                CASE 
                    -- PERFORMANCE: Use generated columns (ad_id, adset_id) instead of JSON_EXTRACT for index usage
                    WHEN cl.ad_id IS NOT NULL
                        AND cl.adset_id IS NOT NULL
                    THEN conv.id
                    ELSE NULL
                END
            ELSE conv.id
        END) as total_conversions,
        COALESCE(SUM(cl.cost), 0) as total_manual_cost,
        COALESCE(SUM(COALESCE(conv.payout, conv.value)), 0) as total_revenue,
        COUNT(DISTINCT CASE 
            WHEN cl.device = 'mobile' THEN
                CASE 
                    WHEN (" . ($trafficSourceColumnExists ? "cl.traffic_source_id = 4" : "c_summary.traffic_source_id = 4") . ") THEN 
                        CASE 
                            -- PERFORMANCE: Use generated columns (ad_id, adset_id) instead of JSON_EXTRACT for index usage
                            WHEN cl.ad_id IS NOT NULL 
                                AND cl.adset_id IS NOT NULL
                            THEN cl.id
                            ELSE NULL
                        END
                    ELSE cl.id
                END
            ELSE NULL
        END) as mobile_visitors,
        COUNT(DISTINCT CASE 
            WHEN cl.device = 'desktop' THEN
                CASE 
                    WHEN (" . ($trafficSourceColumnExists ? "cl.traffic_source_id = 4" : "c_summary.traffic_source_id = 4") . ") THEN 
                        CASE 
                            -- PERFORMANCE: Use generated columns (ad_id, adset_id) instead of JSON_EXTRACT for index usage
                            WHEN cl.ad_id IS NOT NULL 
                                AND cl.adset_id IS NOT NULL
                            THEN cl.id
                            ELSE NULL
                        END
                    ELSE cl.id
                END
            ELSE NULL
        END) as desktop_visitors,
        COUNT(DISTINCT CASE 
            WHEN cl.device = 'tablet' THEN
                CASE 
                    WHEN (" . ($trafficSourceColumnExists ? "cl.traffic_source_id = 4" : "c_summary.traffic_source_id = 4") . ") THEN 
                        CASE 
                            -- PERFORMANCE: Use generated columns (ad_id, adset_id) instead of JSON_EXTRACT for index usage
                            WHEN cl.ad_id IS NOT NULL 
                                AND cl.adset_id IS NOT NULL
                            THEN cl.id
                            ELSE NULL
                        END
                    ELSE cl.id
                END
            ELSE NULL
        END) as tablet_visitors,
        -- Count invalid clicks for display
        -- PERFORMANCE: Use generated columns (ad_id, adset_id) instead of JSON_EXTRACT for index usage
        COUNT(DISTINCT CASE 
            WHEN (" . ($trafficSourceColumnExists ? "cl.traffic_source_id = 4" : "c_summary.traffic_source_id = 4") . ") THEN 
                CASE 
                    WHEN cl.ad_id IS NULL 
                        OR cl.adset_id IS NULL
                    THEN cl.id
                    ELSE NULL
                END
            ELSE NULL
        END) as invalid_clicks
    FROM {$clicksTable} cl
    LEFT JOIN conversions conv ON cl.click_id = conv.click_id
    {$summaryTrafficSourceJoin}
    WHERE {$dateFilter}
    {$campaignFilter}
    {$trafficSourceFilter}
    {$summaryInvalidClickExclusion}
");

        $bindTypes = 'ss';
        $bindValues = $dateParams;
        if (!empty($campaignFilterParams)) {
            $bindTypes .= 'i';
            $bindValues = array_merge($dateParams, $campaignFilterParams);
        }
        if (!empty($trafficSourceFilterParams)) {
            $bindTypes .= 'i';
            $bindValues = array_merge($bindValues, $trafficSourceFilterParams);
        }

        $summaryQuery->bind_param($bindTypes, ...$bindValues);
        $summaryQuery->execute();
        $summary = $summaryQuery->get_result()->fetch_assoc();
    }

// Calculate summary metrics with unified cost
$totalVisitors = (int)($summary['total_visitors'] ?? 0);
$totalClicks = (int)($summary['total_clicks'] ?? 0);
$totalConversions = (int)($summary['total_conversions'] ?? 0);
$manualCost = (float)($summary['total_manual_cost'] ?? 0);
$totalInvalidClicks = (int)($summary['invalid_clicks'] ?? 0);

// Get Facebook API costs for summary using FacebookCostAggregator
// This ensures consistency with dashboard and prevents double counting
// getAggregatedCost already includes manual costs, so we need to subtract it
// Convert campaignFilter to format without 'cl.' prefix for getAggregatedCost
$summaryCampaignFilter = $campaignFilter ? str_replace('cl.campaign_id', 'campaign_id', $campaignFilter) : null;
$summaryCostCacheKey = md5($utcDateFrom . '|' . $utcDateTo . '|' . $userTimezone . '|' . ($summaryCampaignFilter ?? '') . '|' . json_encode($campaignFilterParams));
if (!isset($requestCostCache[$summaryCostCacheKey])) {
    $requestCostCache[$summaryCostCacheKey] = $costAggregator->getAggregatedCost($utcDateFrom, $utcDateTo, $summaryCampaignFilter, $campaignFilterParams, $userTimezone);
}
$totalCostFromAggregator = $requestCostCache[$summaryCostCacheKey];
$fbCost = $totalCostFromAggregator - $manualCost;
if ($fbCost < 0) {
    $fbCost = 0; // Safety check
}
$totalCost = $manualCost + $fbCost; // Unified cost
$totalRevenue = (float)($summary['total_revenue'] ?? 0);
$totalProfit = $totalRevenue - $totalCost;
$mobileVisitors = (int)($summary['mobile_visitors'] ?? 0);

    if ($isDataRequest) {
        $timing['summary_ms'] = round((microtime(true) - $timingSegmentStart) * 1000, 2);
        $timingSegmentStart = microtime(true);
    }
    $desktopVisitors = (int)($summary['desktop_visitors'] ?? 0);
    $tabletVisitors = (int)($summary['tablet_visitors'] ?? 0);
} else {
    // Restore summary and derived metrics from cache
    $summary = $cachedStatsData['summary'] ?? null;
    if ($summary) {
        $totalVisitors = (int)($summary['total_visitors'] ?? 0);
        $totalClicks = (int)($summary['total_clicks'] ?? 0);
        $totalConversions = (int)($summary['total_conversions'] ?? 0);
        $manualCost = (float)($summary['total_manual_cost'] ?? 0);
        $totalInvalidClicks = (int)($summary['invalid_clicks'] ?? 0);
        $summaryCampaignFilter = $campaignFilter ? str_replace('cl.campaign_id', 'campaign_id', $campaignFilter) : null;
        $summaryCostCacheKey = md5($utcDateFrom . '|' . $utcDateTo . '|' . $userTimezone . '|' . ($summaryCampaignFilter ?? '') . '|' . json_encode($campaignFilterParams));
        if (!isset($requestCostCache[$summaryCostCacheKey])) {
            $requestCostCache[$summaryCostCacheKey] = $costAggregator->getAggregatedCost($utcDateFrom, $utcDateTo, $summaryCampaignFilter, $campaignFilterParams, $userTimezone);
        }
        $totalCostFromAggregator = $requestCostCache[$summaryCostCacheKey];
        $fbCost = $totalCostFromAggregator - $manualCost;
        if ($fbCost < 0) {
            $fbCost = 0;
        }
        $totalCost = $manualCost + $fbCost;
        $totalRevenue = (float)($summary['total_revenue'] ?? 0);
        $totalProfit = $totalRevenue - $totalCost;
        $mobileVisitors = (int)($summary['mobile_visitors'] ?? 0);
        $desktopVisitors = (int)($summary['desktop_visitors'] ?? 0);
        $tabletVisitors = (int)($summary['tablet_visitors'] ?? 0);
    }
}

// Calculate percentages
$campaignPercent = $totalVisitors > 0 ? ($totalClicks / $totalVisitors) * 100 : 0;
$directPercent = 100 - $campaignPercent; // Simplified for now
$mobilePercent = $totalVisitors > 0 ? ($mobileVisitors / $totalVisitors) * 100 : 0;
$blockedPercent = 0; // Placeholder - would need to track blocked clicks

// Get time-series data for chart
// PERFORMANCE: Only calculate chart data if not using cached data
// Chart data is included in cache to avoid recalculation
$chartLabels = [];
$chartVisitors = [];
$chartClicks = [];
$chartConversions = [];
$chartRevenue = [];
$chartCost = [];

// If we have cached chart data or target_only request or no_chart testing flag, skip chart queries (plan 3.2)
if ((!$hasCachedData || !isset($cachedStatsData['chartLabels'])) && !$targetOnlyRequest && !$skipChart) {
    // Generate date range array (in user's timezone)
    try {
        $tz = new DateTimeZone($userTimezone);
        $startDate = new DateTime($dateFrom . ' 00:00:00', $tz);
        $endDate = new DateTime($dateTo . ' 23:59:59', $tz);
    } catch (Exception $e) {
        // Fallback to server timezone
        error_log("Campaign stats chart: Date range calculation failed: " . $e->getMessage());
        $startDate = new DateTime($dateFrom);
        $endDate = new DateTime($dateTo);
    }
    $isSingleDay = ($startDate->format('Y-m-d') === $endDate->format('Y-m-d'));

    if ($isSingleDay) {
        // For single day: group by hour (0-23) in user's timezone
        for ($hour = 0; $hour < 24; $hour++) {
            $hourLabel = sprintf('%02d:00', $hour);
            $chartLabels[] = $hourLabel;
            $chartVisitors[$hour] = 0;
            $chartClicks[$hour] = 0;
            $chartConversions[$hour] = 0;
            $chartRevenue[$hour] = 0;
            $chartCost[$hour] = 0;
        }
        
        // Get actual data grouped by hour (convert UTC to user timezone)
        // MySQL session timezone is set to UTC above, so cl.ts is in UTC
        // Include cost data for cost chart view (manual + Facebook API costs)
        // Use timezone offset format for CONVERT_TZ (e.g., '-08:00' for PST)
        // Calculate offset from UTC for the current date (handles DST)
        try {
            $tz = new DateTimeZone($userTimezone);
            // Use the start of the selected day to get correct offset (handles DST)
            $testDate = new DateTime($dateFrom . ' 12:00:00', $tz);
            $offset = $tz->getOffset($testDate);
            $hours = intval($offset / 3600);
            $minutes = intval(($offset % 3600) / 60);
            $timezoneOffset = sprintf('%+03d:%02d', $hours, abs($minutes));
        } catch (Exception $e) {
            // Fallback to UTC if timezone calculation fails
            $timezoneOffset = '+00:00';
            error_log("Campaign stats chart: Timezone calculation failed for '{$userTimezone}': " . $e->getMessage());
        }
        
        $hourlyQuery = $db->prepare("
        SELECT 
            COALESCE(HOUR(CONVERT_TZ(cl.ts, '+00:00', ?)), -1) as hour,
            COUNT(DISTINCT cl.id) as visitors,
            COUNT(DISTINCT CASE WHEN cl.lp_click = 1 THEN cl.id END) as clicks,
            COUNT(DISTINCT conv.id) as conversions,
            COALESCE(SUM(COALESCE(conv.payout, conv.value)), 0) as revenue,
            COALESCE(SUM(cl.cost), 0) as manual_cost,
            COALESCE(SUM(
                CASE 
                    WHEN a_cost.delta_spend IS NOT NULL THEN 
                        a_cost.delta_spend / GREATEST((
                            SELECT COUNT(*) 
                            FROM {$clicksTable} c2 
                            WHERE JSON_UNQUOTE(JSON_EXTRACT(c2.extra_json, '$.traffic_source_tokens.ad_id')) = click_data.ad_id
                                AND DATE(c2.ts) = click_data.click_date
                                AND HOUR(c2.ts) = click_data.click_hour
                        ), 1)
                    WHEN as_cost.delta_spend IS NOT NULL 
                        AND NOT EXISTS (
                            -- Only use adset cost if NO ad costs exist for this adset in this hour
                            SELECT 1 
                            FROM ad_hourly_costs a_check
                            INNER JOIN {$clicksTable} c_check ON JSON_UNQUOTE(JSON_EXTRACT(c_check.extra_json, '$.traffic_source_tokens.ad_id')) = a_check.ad_id
                            INNER JOIN campaigns camp_check ON c_check.campaign_id = camp_check.id
                            LEFT JOIN facebook_marketing_ad_accounts fmaa_check ON camp_check.facebook_marketing_ad_account_id = fmaa_check.id
                            WHERE JSON_UNQUOTE(JSON_EXTRACT(c_check.extra_json, '$.traffic_source_tokens.adset_id')) = click_data.adset_id
                                AND a_check.date = click_data.click_date
                                AND a_check.hour = click_data.click_hour
                                AND (fmaa_check.facebook_marketing_integration_id IS NULL OR a_check.ad_account_id = fmaa_check.facebook_marketing_integration_id)
                        ) THEN 
                        as_cost.delta_spend / GREATEST((
                            SELECT COUNT(*) 
                            FROM {$clicksTable} c2 
                            WHERE JSON_UNQUOTE(JSON_EXTRACT(c2.extra_json, '$.traffic_source_tokens.adset_id')) = click_data.adset_id
                                AND DATE(c2.ts) = click_data.click_date
                                AND HOUR(c2.ts) = click_data.click_hour
                        ), 1)
                    ELSE 0
                END
            ), 0) as fb_cost
        FROM {$clicksTable} cl
        LEFT JOIN conversions conv ON cl.click_id = conv.click_id
        LEFT JOIN (
            SELECT 
                click_id,
                campaign_id,
                ad_id,
                adset_id,
                DATE(ts) as click_date,
                HOUR(ts) as click_hour
            FROM {$clicksTable} c_sub
            WHERE c_sub.ts >= ? AND c_sub.ts <= ?
            " . (!empty($campaignFilter) ? str_replace('cl.', 'c_sub.', $campaignFilter) : '') . "
            " . (!empty($trafficSourceFilter) ? str_replace('cl.', 'c_sub.', $trafficSourceFilter) : '') . "
            -- PERFORMANCE: Use generated columns (ad_id, adset_id) instead of JSON_EXTRACT for index usage
        ) as click_data ON click_data.click_id = cl.click_id
        LEFT JOIN campaigns camp ON click_data.campaign_id = camp.id
        LEFT JOIN facebook_marketing_ad_accounts fmaa ON camp.facebook_marketing_ad_account_id = fmaa.id
        LEFT JOIN ad_hourly_costs a_cost ON 
            a_cost.ad_id = click_data.ad_id 
            AND a_cost.date = click_data.click_date 
            AND a_cost.hour = click_data.click_hour
            AND (fmaa.facebook_marketing_integration_id IS NULL OR a_cost.ad_account_id = fmaa.facebook_marketing_integration_id)
        LEFT JOIN adset_hourly_costs as_cost ON 
            as_cost.adset_id = click_data.adset_id 
            AND as_cost.date = click_data.click_date 
            AND as_cost.hour = click_data.click_hour
            AND (fmaa.facebook_marketing_integration_id IS NULL OR as_cost.ad_account_id = fmaa.facebook_marketing_integration_id)
            AND a_cost.delta_spend IS NULL
        WHERE cl.ts >= ? AND cl.ts <= ?
        {$campaignFilter}
        {$trafficSourceFilter}
        GROUP BY HOUR(CONVERT_TZ(cl.ts, '+00:00', ?))
        ORDER BY hour ASC
        ");
        
        // Bind parameters in order:
        // 1. timezoneOffset (for HOUR in SELECT)
        // 2-3. utcDateFrom, utcDateTo (for WHERE in subquery)
        // 4+. campaignFilterParams (if any) - for subquery
        // 5+. trafficSourceFilterParams (if any) - for subquery
        // 6-7. utcDateFrom, utcDateTo (for WHERE in main query)
        // 8+. campaignFilterParams (if any) - for main query
        // 9+. trafficSourceFilterParams (if any) - for main query
        // 10. timezoneOffset (for GROUP BY)
        // Note: Cost matching uses UTC date/hour (cost tables store UTC)
        $bindTypes = 'sss';
        $bindValues = [$timezoneOffset, $utcDateFrom, $utcDateTo];
        if (!empty($campaignFilterParams)) {
            $bindTypes .= str_repeat('i', count($campaignFilterParams));
            $bindValues = array_merge($bindValues, $campaignFilterParams);
        }
        if (!empty($trafficSourceFilterParams)) {
            $bindTypes .= str_repeat('i', count($trafficSourceFilterParams));
            $bindValues = array_merge($bindValues, $trafficSourceFilterParams);
        }
        $bindTypes .= 'ss';
        $bindValues = array_merge($bindValues, [$utcDateFrom, $utcDateTo]);
        if (!empty($campaignFilterParams)) {
            $bindTypes .= str_repeat('i', count($campaignFilterParams));
            $bindValues = array_merge($bindValues, $campaignFilterParams);
        }
        if (!empty($trafficSourceFilterParams)) {
            $bindTypes .= str_repeat('i', count($trafficSourceFilterParams));
            $bindValues = array_merge($bindValues, $trafficSourceFilterParams);
        }
        $bindTypes .= 's';
        $bindValues[] = $timezoneOffset;
        
        $hourlyQuery->bind_param($bindTypes, ...$bindValues);
        $hourlyQuery->execute();
        $hourlyResult = $hourlyQuery->get_result();
        
        // Debug: Log query parameters
        error_log("Campaign stats hourly chart query - Timezone offset: {$timezoneOffset}, UTC range: {$utcDateFrom} to {$utcDateTo}, User timezone: {$userTimezone}");
        error_log("Campaign stats hourly chart - Campaign filter: '{$campaignFilter}', Campaign filter params: " . json_encode($campaignFilterParams));
        error_log("Campaign stats hourly chart - Traffic source filter: '{$trafficSourceFilter}', Traffic source filter params: " . json_encode($trafficSourceFilterParams));
        
        $queryResults = [];
        $rowCount = 0;
        while ($row = $hourlyResult->fetch_assoc()) {
            $rowCount++;
            $hour = (int)$row['hour'];
            // Only process valid hours (0-23), skip invalid ones
            if ($hour >= 0 && $hour <= 23) {
                $queryResults[$hour] = [
                    'visitors' => (int)($row['visitors'] ?? 0),
                    'clicks' => (int)($row['clicks'] ?? 0),
                    'conversions' => (int)($row['conversions'] ?? 0),
                    'revenue' => (float)($row['revenue'] ?? 0),
                    'cost' => (float)($row['manual_cost'] ?? 0) + (float)($row['fb_cost'] ?? 0)
                ];
                error_log("Campaign stats hourly chart - Hour {$hour}: visitors={$row['visitors']}, clicks={$row['clicks']}, conversions={$row['conversions']}, revenue={$row['revenue']}, cost=" . ($queryResults[$hour]['cost']));
            } else {
                // Log invalid hours for debugging
                error_log("Campaign stats chart: Invalid hour returned from query: {$hour} (visitors: {$row['visitors']}, clicks: {$row['clicks']})");
            }
        }
        error_log("Campaign stats hourly chart - Total rows returned: {$rowCount}, Valid hours mapped: " . count($queryResults));
        
        // Map query results to chart arrays
        foreach ($queryResults as $hour => $data) {
            $chartVisitors[$hour] = $data['visitors'];
            $chartClicks[$hour] = $data['clicks'];
            $chartConversions[$hour] = $data['conversions'];
            $chartRevenue[$hour] = $data['revenue'];
            $chartCost[$hour] = $data['cost'];
        }
        
        // Build arrays in order (0-23) - rebuild as sequential arrays
        $visitorsData = [];
        $clicksData = [];
        $conversionsData = [];
        $revenueData = [];
        $costData = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $visitorsData[] = $chartVisitors[$hour] ?? 0;
            $clicksData[] = $chartClicks[$hour] ?? 0;
            $conversionsData[] = $chartConversions[$hour] ?? 0;
            $revenueData[] = $chartRevenue[$hour] ?? 0;
            $costData[] = $chartCost[$hour] ?? 0;
        }
        // Replace indexed arrays with sequential arrays
        $chartVisitors = $visitorsData;
        $chartClicks = $clicksData;
        $chartConversions = $conversionsData;
        $chartRevenue = $revenueData;
        $chartCost = $costData;
    } else {
        // For multiple days: group by day
        $interval = new DateInterval('P1D');
        // Create a copy of endDate before modifying it
        $endDateForPeriod = clone $endDate;
        $endDateForPeriod->modify('+1 day');
        $period = new DatePeriod($startDate, $interval, $endDateForPeriod);

        // Initialize all dates with zero values
        $chartVisitorsByDate = [];
        $chartClicksByDate = [];
        $chartConversionsByDate = [];
        $chartRevenueByDate = [];
        $chartCostByDate = [];
        
        foreach ($period as $date) {
            $dateStr = $date->format('Y-m-d');
            $chartLabels[] = $date->format('M d');
            $chartVisitorsByDate[$dateStr] = 0;
            $chartClicksByDate[$dateStr] = 0;
            $chartConversionsByDate[$dateStr] = 0;
            $chartRevenueByDate[$dateStr] = 0;
            $chartCostByDate[$dateStr] = 0;
        }
        
        // Get timezone offset for MySQL CONVERT_TZ (e.g., '-08:00' for PST)
        // Calculate offset from UTC for the date range (handles DST)
        try {
            $tz = new DateTimeZone($userTimezone);
            // Use the start of the date range to get correct offset (handles DST)
            $testDate = new DateTime($dateFrom . ' 12:00:00', $tz);
            $offset = $tz->getOffset($testDate);
            $hours = intval($offset / 3600);
            $minutes = intval(($offset % 3600) / 60);
            $timezoneOffset = sprintf('%+03d:%02d', $hours, abs($minutes));
        } catch (Exception $e) {
            // Fallback to UTC if timezone calculation fails
            $timezoneOffset = '+00:00';
            error_log("Campaign stats daily chart: Timezone calculation failed for '{$userTimezone}': " . $e->getMessage());
        }
        
        // Get daily stats grouped by date (convert UTC to user timezone)
        // Use a single query with GROUP BY instead of looping
        $dailyQuery = $db->prepare("
        SELECT 
            DATE(CONVERT_TZ(cl.ts, '+00:00', ?)) as date,
            COUNT(DISTINCT cl.id) as visitors,
            COUNT(DISTINCT CASE WHEN cl.lp_click = 1 THEN cl.id END) as clicks,
            COUNT(DISTINCT conv.id) as conversions,
            COALESCE(SUM(COALESCE(conv.payout, conv.value)), 0) as revenue,
            COALESCE(SUM(cl.cost), 0) as manual_cost,
            COALESCE(SUM(
                CASE 
                    WHEN a_cost.delta_spend IS NOT NULL THEN 
                        a_cost.delta_spend / GREATEST((
                            SELECT COUNT(*) 
                            FROM {$clicksTable} c2 
                            WHERE JSON_UNQUOTE(JSON_EXTRACT(c2.extra_json, '$.traffic_source_tokens.ad_id')) = click_data.ad_id
                                AND DATE(c2.ts) = click_data.click_date
                                AND HOUR(c2.ts) = click_data.click_hour
                        ), 1)
                    WHEN as_cost.delta_spend IS NOT NULL 
                        AND NOT EXISTS (
                            -- Only use adset cost if NO ad costs exist for this adset in this hour
                            SELECT 1 
                            FROM ad_hourly_costs a_check
                            INNER JOIN {$clicksTable} c_check ON JSON_UNQUOTE(JSON_EXTRACT(c_check.extra_json, '$.traffic_source_tokens.ad_id')) = a_check.ad_id
                            INNER JOIN campaigns camp_check ON c_check.campaign_id = camp_check.id
                            LEFT JOIN facebook_marketing_ad_accounts fmaa_check ON camp_check.facebook_marketing_ad_account_id = fmaa_check.id
                            WHERE JSON_UNQUOTE(JSON_EXTRACT(c_check.extra_json, '$.traffic_source_tokens.adset_id')) = click_data.adset_id
                                AND a_check.date = click_data.click_date
                                AND a_check.hour = click_data.click_hour
                                AND (fmaa_check.facebook_marketing_integration_id IS NULL OR a_check.ad_account_id = fmaa_check.facebook_marketing_integration_id)
                        ) THEN 
                        as_cost.delta_spend / GREATEST((
                            SELECT COUNT(*) 
                            FROM {$clicksTable} c2 
                            WHERE JSON_UNQUOTE(JSON_EXTRACT(c2.extra_json, '$.traffic_source_tokens.adset_id')) = click_data.adset_id
                                AND DATE(c2.ts) = click_data.click_date
                                AND HOUR(c2.ts) = click_data.click_hour
                        ), 1)
                    ELSE 0
                END
            ), 0) as fb_cost
        FROM {$clicksTable} cl
        LEFT JOIN conversions conv ON cl.click_id = conv.click_id
        LEFT JOIN (
            SELECT 
                click_id,
                campaign_id,
                ad_id,
                adset_id,
                DATE(ts) as click_date,
                HOUR(ts) as click_hour
            FROM {$clicksTable} c_sub
            WHERE c_sub.ts >= ? AND c_sub.ts <= ?
            " . (!empty($campaignFilter) ? str_replace('cl.', 'c_sub.', $campaignFilter) : '') . "
            " . (!empty($trafficSourceFilter) ? str_replace('cl.', 'c_sub.', $trafficSourceFilter) : '') . "
            -- PERFORMANCE: Use generated columns (ad_id, adset_id) instead of JSON_EXTRACT for index usage
        ) as click_data ON click_data.click_id = cl.click_id
        LEFT JOIN campaigns camp ON click_data.campaign_id = camp.id
        LEFT JOIN facebook_marketing_ad_accounts fmaa ON camp.facebook_marketing_ad_account_id = fmaa.id
        LEFT JOIN ad_hourly_costs a_cost ON 
            a_cost.ad_id = click_data.ad_id 
            AND a_cost.date = click_data.click_date 
            AND a_cost.hour = click_data.click_hour
            AND (fmaa.facebook_marketing_integration_id IS NULL OR a_cost.ad_account_id = fmaa.facebook_marketing_integration_id)
        LEFT JOIN adset_hourly_costs as_cost ON 
            as_cost.adset_id = click_data.adset_id 
            AND as_cost.date = click_data.click_date 
            AND as_cost.hour = click_data.click_hour
            AND (fmaa.facebook_marketing_integration_id IS NULL OR as_cost.ad_account_id = fmaa.facebook_marketing_integration_id)
            AND a_cost.delta_spend IS NULL
        WHERE cl.ts >= ? AND cl.ts <= ?
        {$campaignFilter}
        {$trafficSourceFilter}
        GROUP BY DATE(CONVERT_TZ(cl.ts, '+00:00', ?))
        ORDER BY date ASC
        ");
        
        // Bind parameters in order:
        // 1. timezoneOffset (for DATE in SELECT)
        // 2-3. utcDateFrom, utcDateTo (for WHERE in subquery)
        // 4+. campaignFilterParams (if any) - for subquery
        // 5+. trafficSourceFilterParams (if any) - for subquery
        // 6-7. utcDateFrom, utcDateTo (for WHERE in main query)
        // 8+. campaignFilterParams (if any) - for main query
        // 9+. trafficSourceFilterParams (if any) - for main query
        // 10. timezoneOffset (for GROUP BY)
        $bindTypes = 'sss';
        $bindValues = [$timezoneOffset, $utcDateFrom, $utcDateTo];
        if (!empty($campaignFilterParams)) {
            $bindTypes .= str_repeat('i', count($campaignFilterParams));
            $bindValues = array_merge($bindValues, $campaignFilterParams);
        }
        if (!empty($trafficSourceFilterParams)) {
            $bindTypes .= str_repeat('i', count($trafficSourceFilterParams));
            $bindValues = array_merge($bindValues, $trafficSourceFilterParams);
        }
        $bindTypes .= 'ss';
        $bindValues = array_merge($bindValues, [$utcDateFrom, $utcDateTo]);
        if (!empty($campaignFilterParams)) {
            $bindTypes .= str_repeat('i', count($campaignFilterParams));
            $bindValues = array_merge($bindValues, $campaignFilterParams);
        }
        if (!empty($trafficSourceFilterParams)) {
            $bindTypes .= str_repeat('i', count($trafficSourceFilterParams));
            $bindValues = array_merge($bindValues, $trafficSourceFilterParams);
        }
        $bindTypes .= 's';
        $bindValues[] = $timezoneOffset;
        
        $dailyQuery->bind_param($bindTypes, ...$bindValues);
        
        // Debug: Log query details before execution
        error_log("Campaign stats daily chart query - Timezone offset: {$timezoneOffset}, UTC range: {$utcDateFrom} to {$utcDateTo}, User timezone: {$userTimezone}, Date range: {$dateFrom} to {$dateTo}");
        error_log("Campaign stats daily chart - Campaign filter: '{$campaignFilter}', Campaign filter params: " . json_encode($campaignFilterParams));
        error_log("Campaign stats daily chart - Traffic source filter: '{$trafficSourceFilter}', Traffic source filter params: " . json_encode($trafficSourceFilterParams));
        error_log("Campaign stats daily chart - Bind types: {$bindTypes}, Bind values count: " . count($bindValues));
        
        $dailyQuery->execute();
        
        // Check for SQL errors
        if ($dailyQuery->error) {
            error_log("Campaign stats daily chart SQL error: " . $dailyQuery->error);
        }
        
        $dailyResult = $dailyQuery->get_result();
        
        $queryResults = [];
        $rowCount = 0;
        while ($row = $dailyResult->fetch_assoc()) {
            $rowCount++;
            $dateStr = $row['date'] ?? '';
            if (!empty($dateStr)) {
                $queryResults[$dateStr] = [
                    'visitors' => (int)($row['visitors'] ?? 0),
                    'clicks' => (int)($row['clicks'] ?? 0),
                    'conversions' => (int)($row['conversions'] ?? 0),
                    'revenue' => (float)($row['revenue'] ?? 0),
                    'cost' => (float)($row['manual_cost'] ?? 0) + (float)($row['fb_cost'] ?? 0)
                ];
                error_log("Campaign stats daily chart - Date {$dateStr}: visitors={$row['visitors']}, clicks={$row['clicks']}, conversions={$row['conversions']}, revenue={$row['revenue']}, cost=" . $queryResults[$dateStr]['cost']);
            } else {
                error_log("Campaign stats daily chart: Empty date string in query result (visitors: {$row['visitors']}, clicks: {$row['clicks']})");
            }
        }
        error_log("Campaign stats daily chart - Total rows returned: {$rowCount}, Dates mapped: " . count($queryResults) . ", Expected dates: " . count($chartLabels));
        
        // Map query results to chart arrays
        foreach ($queryResults as $dateStr => $data) {
            $chartVisitorsByDate[$dateStr] = $data['visitors'];
            $chartClicksByDate[$dateStr] = $data['clicks'];
            $chartConversionsByDate[$dateStr] = $data['conversions'];
            $chartRevenueByDate[$dateStr] = $data['revenue'];
            $chartCostByDate[$dateStr] = $data['cost'];
        }
        
        // Build arrays in the same order as labels
        // Reset period for iteration (in user's timezone) - period iterator is exhausted after first use
        try {
            $tz = new DateTimeZone($userTimezone);
            $startDateForIteration = new DateTime($dateFrom . ' 00:00:00', $tz);
            $endDateForIteration = new DateTime($dateTo . ' 23:59:59', $tz);
        } catch (Exception $e) {
            // Fallback to server timezone
            $startDateForIteration = new DateTime($dateFrom);
            $endDateForIteration = new DateTime($dateTo);
        }
        // Create a copy before modifying
        $endDateForIterationCopy = clone $endDateForIteration;
        $endDateForIterationCopy->modify('+1 day');
        $periodForIteration = new DatePeriod($startDateForIteration, $interval, $endDateForIterationCopy);
        
        foreach ($periodForIteration as $date) {
            $dateStr = $date->format('Y-m-d');
            $chartVisitors[] = $chartVisitorsByDate[$dateStr] ?? 0;
            $chartClicks[] = $chartClicksByDate[$dateStr] ?? 0;
            $chartConversions[] = $chartConversionsByDate[$dateStr] ?? 0;
            $chartRevenue[] = $chartRevenueByDate[$dateStr] ?? 0;
            $chartCost[] = $chartCostByDate[$dateStr] ?? 0;
        }
        
        // Debug: Log date matching
        $queryDates = array_keys($queryResults);
        $expectedDates = array_map(function($date) { return $date->format('Y-m-d'); }, iterator_to_array($periodForIteration));
        $missingDates = array_diff($expectedDates, $queryDates);
        $extraDates = array_diff($queryDates, $expectedDates);
        if (!empty($missingDates)) {
            error_log("Campaign stats daily chart - Dates in expected range but not in query results: " . implode(', ', $missingDates));
        }
        if (!empty($extraDates)) {
            error_log("Campaign stats daily chart - Dates in query results but not in expected range: " . implode(', ', $extraDates));
        }
    }
} else {
    // Using cached chart data - ensure arrays are initialized even if cache is empty
    if ($isDataRequest && $timingSegmentStart !== null) {
        $timing['chart_ms'] = round((microtime(true) - $timingSegmentStart) * 1000, 2);
        $timingSegmentStart = microtime(true);
    }
    if (empty($chartLabels)) {
        error_log("Campaign stats chart: Cached chart data is empty, initializing with default values");
        // Initialize with at least one empty label to prevent "No chart data available" warning
        $chartLabels = ['No Data'];
        $chartVisitors = [0];
        $chartClicks = [0];
        $chartConversions = [0];
        $chartRevenue = [0];
        $chartCost = [0];
    }
}

// Get offer performance data using tracked offer_id
$offerCampaignFilter = '';
$offerCampaignFilterParams = [];
if ($selectedCampaignId) {
    $offerCampaignFilter = 'AND cl.campaign_id = ?';
    $offerCampaignFilterParams[] = $selectedCampaignId;
}

$offerTrafficSourceFilter = '';
$offerTrafficSourceFilterParams = [];
if ($selectedTrafficSourceId) {
    if ($trafficSourceColumnExists) {
        $offerTrafficSourceFilter = 'AND cl.traffic_source_id = ?';
    } else {
        $offerTrafficSourceFilter = 'AND c.traffic_source_id = ?';
    }
    $offerTrafficSourceFilterParams[] = $selectedTrafficSourceId;
}

$offerTrafficSourceSelect = $trafficSourceColumnExists 
    ? "cl.traffic_source_id,"
    : "c.traffic_source_id,";
$offerTrafficSourceJoin = $trafficSourceColumnExists
    ? "LEFT JOIN traffic_sources ts ON cl.traffic_source_id = ts.id"
    : "INNER JOIN campaigns c ON cl.campaign_id = c.id\n    LEFT JOIN traffic_sources ts ON c.traffic_source_id = ts.id";
// Exclude test/Meta approval clicks at WHERE so we don't scan those rows (Facebook = 4)
$offerInvalidClickExclusion = "AND (" . ($trafficSourceColumnExists ? "cl.traffic_source_id != 4" : "c.traffic_source_id != 4") . " OR (cl.ad_id IS NOT NULL AND cl.adset_id IS NOT NULL))";

$offerPerformanceQuery = $db->prepare("
    SELECT 
        o.id as offer_id,
        o.name as offer_name,
        {$offerTrafficSourceSelect}
        COALESCE(ts.name, 'Unknown') as traffic_source_name,
        COUNT(DISTINCT CASE 
            -- For Facebook traffic source, require both ad_id AND adset_id (exclude invalid clicks)
            -- PERFORMANCE: Use generated columns (ad_id, adset_id) instead of JSON_EXTRACT for index usage
            WHEN (" . ($trafficSourceColumnExists ? "cl.traffic_source_id = 4" : "c.traffic_source_id = 4") . ") THEN 
                CASE 
                    WHEN cl.ad_id IS NOT NULL 
                        AND cl.adset_id IS NOT NULL
                    THEN cl.id
                    ELSE NULL
                END
            -- For other traffic sources, count all clicks
            ELSE cl.id
        END) as visitors,
        COALESCE(SUM(cl.cost), 0) as manual_cost,
        COUNT(DISTINCT CASE 
            WHEN cl.lp_click = 1 THEN
                CASE 
                    WHEN (" . ($trafficSourceColumnExists ? "cl.traffic_source_id = 4" : "c.traffic_source_id = 4") . ") THEN 
                        CASE 
                            -- PERFORMANCE: Use generated columns (ad_id, adset_id) instead of JSON_EXTRACT for index usage
                            WHEN cl.ad_id IS NOT NULL 
                                AND cl.adset_id IS NOT NULL
                            THEN cl.id
                            ELSE NULL
                        END
                    ELSE cl.id
                END
            ELSE NULL
        END) as lp_clicks,
        COUNT(DISTINCT CASE 
            WHEN (" . ($trafficSourceColumnExists ? "cl.traffic_source_id = 4" : "c.traffic_source_id = 4") . ") THEN 
                CASE 
                    -- PERFORMANCE: Use generated columns (ad_id, adset_id) instead of JSON_EXTRACT for index usage
                    WHEN cl.ad_id IS NOT NULL 
                        AND cl.adset_id IS NOT NULL
                    THEN conv.id
                    ELSE NULL
                END
            ELSE conv.id
        END) as conversions,
        COALESCE(SUM(COALESCE(conv.payout, conv.value)), 0) as revenue,
        -- Count invalid clicks for display
        -- PERFORMANCE: Use generated columns (ad_id, adset_id) instead of JSON_EXTRACT for index usage
        COUNT(DISTINCT CASE 
            WHEN (" . ($trafficSourceColumnExists ? "cl.traffic_source_id = 4" : "c.traffic_source_id = 4") . ") THEN 
                CASE 
                    WHEN cl.ad_id IS NULL 
                        OR cl.adset_id IS NULL
                    THEN cl.id
                    ELSE NULL
                END
            ELSE NULL
        END) as invalid_clicks
    FROM offers o
    INNER JOIN {$clicksTable} cl ON o.id = cl.offer_id
    LEFT JOIN conversions conv ON cl.click_id = conv.click_id
    {$offerTrafficSourceJoin}
    WHERE cl.ts >= ? AND cl.ts <= ?
    {$offerCampaignFilter}
    {$offerTrafficSourceFilter}
    {$offerInvalidClickExclusion}
    GROUP BY o.id, o.name
    ORDER BY visitors DESC
");

$offerBindTypes = 'ss';
$offerBindValues = $dateParams;
if (!empty($offerCampaignFilterParams)) {
    $offerBindTypes .= 'i';
    $offerBindValues = array_merge($dateParams, $offerCampaignFilterParams);
}
if (!empty($offerTrafficSourceFilterParams)) {
    $offerBindTypes .= 'i';
    $offerBindValues = array_merge($offerBindValues, $offerTrafficSourceFilterParams);
}

if (!$hasCachedData && !$targetOnlyRequest && !$skipOffer) {
    if ($useDailySummaryForStats) {
        // Fast path: offer performance from clicks_daily_summary (plan: stats pre-aggregation Phase 1)
        $summaryDateFrom = date('Y-m-d', strtotime($utcDateFrom));
        $summaryDateTo = date('Y-m-d', strtotime($utcDateTo));
        $offerDailyFilter = 's.summary_date >= ? AND s.summary_date <= ? AND s.offer_id IS NOT NULL';
        if ($offerCampaignFilter) {
            $offerDailyFilter .= ' ' . str_replace('cl.campaign_id', 's.campaign_id', $offerCampaignFilter);
        }
        if ($offerTrafficSourceFilter) {
            $offerDailyFilter .= ' ' . str_replace(['cl.traffic_source_id', 'c.traffic_source_id'], 's.traffic_source_id', $offerTrafficSourceFilter);
        }
        $offerDailyStmt = $db->prepare("
            SELECT 
                o.id as offer_id,
                o.name as offer_name,
                MAX(s.traffic_source_id) as traffic_source_id,
                COALESCE(MAX(ts.name), 'Unknown') as traffic_source_name,
                SUM(s.clicks) as visitors,
                SUM(s.cost) as manual_cost,
                SUM(s.lp_clicks) as lp_clicks,
                SUM(s.conversions) as conversions,
                SUM(s.revenue) as revenue,
                0 as invalid_clicks
            FROM clicks_daily_summary s
            INNER JOIN offers o ON o.id = s.offer_id
            LEFT JOIN traffic_sources ts ON ts.id = s.traffic_source_id
            WHERE {$offerDailyFilter}
            GROUP BY o.id, o.name
            ORDER BY visitors DESC
        ");
        $offerDailyTypes = 'ss';
        $offerDailyValues = [$summaryDateFrom, $summaryDateTo];
        if (!empty($offerCampaignFilterParams)) {
            $offerDailyTypes .= 'i';
            $offerDailyValues = array_merge($offerDailyValues, $offerCampaignFilterParams);
        }
        if (!empty($offerTrafficSourceFilterParams)) {
            $offerDailyTypes .= 'i';
            $offerDailyValues = array_merge($offerDailyValues, $offerTrafficSourceFilterParams);
        }
        $offerDailyStmt->bind_param($offerDailyTypes, ...$offerDailyValues);
        $offerDailyStmt->execute();
        $offerPerformance = $offerDailyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $offerDailyStmt->close();
    } else {
        $offerPerformanceQuery->bind_param($offerBindTypes, ...$offerBindValues);
        $offerPerformanceQuery->execute();
        $offerPerformance = $offerPerformanceQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    }
} else {
    // Use cached offer performance, or empty when target_only (plan 3.2)
    $offerPerformance = $targetOnlyRequest ? [] : ($cachedStatsData['offerPerformance'] ?? []);
}

// Get Facebook costs per offer and merge (only if we have offer performance data)
// Note: $offerCampaignFilter and $offerTrafficSourceFilter are defined above (lines 1238-1254)
// PERFORMANCE: Use generated columns ad_id/adset_id (migration 050) for index usage (plan 3.3)
$offerFbCostMap = [];
if (!empty($offerPerformance) && !$hasCachedData) {
    $offerFbCostQuery = $db->prepare("
    SELECT 
        cl.offer_id,
        COALESCE(SUM(
            CASE 
                WHEN a_cost.delta_spend IS NOT NULL THEN 
                    a_cost.delta_spend / GREATEST((
                        SELECT COUNT(*) 
                        FROM {$clicksTable} c2 
                        WHERE c2.ad_id = click_data.ad_id
                            AND DATE(c2.ts) = click_data.click_date
                            AND HOUR(c2.ts) = click_data.click_hour
                    ), 1)
                WHEN as_cost.delta_spend IS NOT NULL 
                    AND NOT EXISTS (
                        SELECT 1 
                        FROM ad_hourly_costs a_check
                        INNER JOIN {$clicksTable} c_check ON c_check.ad_id = a_check.ad_id
                        INNER JOIN campaigns camp_check ON c_check.campaign_id = camp_check.id
                        LEFT JOIN facebook_marketing_ad_accounts fmaa_check ON camp_check.facebook_marketing_ad_account_id = fmaa_check.id
                        WHERE c_check.adset_id = click_data.adset_id
                            AND a_check.date = click_data.click_date
                            AND a_check.hour = click_data.click_hour
                            AND (fmaa_check.facebook_marketing_integration_id IS NULL OR a_check.ad_account_id = fmaa_check.facebook_marketing_integration_id)
                    ) THEN 
                    as_cost.delta_spend / GREATEST((
                        SELECT COUNT(*) 
                        FROM {$clicksTable} c2 
                        WHERE c2.adset_id = click_data.adset_id
                            AND DATE(c2.ts) = click_data.click_date
                            AND HOUR(c2.ts) = click_data.click_hour
                    ), 1)
                ELSE 0
            END
        ), 0) as fb_cost
    FROM {$clicksTable} cl
    LEFT JOIN (
        SELECT 
            click_id,
            campaign_id,
            ad_id,
            adset_id,
            DATE(ts) as click_date,
            HOUR(ts) as click_hour,
            offer_id
        FROM {$clicksTable}
        WHERE DATE(ts) BETWEEN ? AND ?
    ) as click_data ON click_data.click_id = cl.click_id
    LEFT JOIN campaigns camp ON click_data.campaign_id = camp.id
    LEFT JOIN facebook_marketing_ad_accounts fmaa ON camp.facebook_marketing_ad_account_id = fmaa.id
    LEFT JOIN ad_hourly_costs a_cost ON 
        a_cost.ad_id = click_data.ad_id 
        AND a_cost.date = click_data.click_date 
        AND a_cost.hour = click_data.click_hour
        AND (fmaa.facebook_marketing_integration_id IS NULL OR a_cost.ad_account_id = fmaa.facebook_marketing_integration_id)
    LEFT JOIN adset_hourly_costs as_cost ON 
        as_cost.adset_id = click_data.adset_id 
        AND as_cost.date = click_data.click_date 
        AND as_cost.hour = click_data.click_hour
        AND (fmaa.facebook_marketing_integration_id IS NULL OR as_cost.ad_account_id = fmaa.facebook_marketing_integration_id)
    WHERE cl.ts >= ? AND cl.ts <= ?
    AND click_data.ad_id IS NOT NULL
    AND click_data.adset_id IS NOT NULL
    " . (!empty($offerCampaignFilter) ? $offerCampaignFilter : '') . "
    GROUP BY cl.offer_id
");

    $offerFbBindTypes = 'ssss'; // Two date params for subquery, two for main WHERE clause
    $offerFbBindValues = array_merge($dateParams, $dateParams); // Date params for subquery and main query
    if (!empty($offerCampaignFilterParams)) {
        $offerFbBindTypes .= 'i';
        $offerFbBindValues = array_merge($offerFbBindValues, $offerCampaignFilterParams);
    }

    $offerFbCostQuery->bind_param($offerFbBindTypes, ...$offerFbBindValues);
    $offerFbCostQuery->execute();
    $offerFbCosts = $offerFbCostQuery->get_result()->fetch_all(MYSQLI_ASSOC);

    // Create lookup map (batch FB cost per offer - plan: use batch instead of N getAggregatedCost calls)
    foreach ($offerFbCosts as $fbCost) {
        $offerFbCostMap[(int)$fbCost['offer_id']] = (float)$fbCost['fb_cost'];
    }

    // Merge Facebook costs into offer performance using batch map (avoids N getAggregatedCost calls)
    foreach ($offerPerformance as &$offer) {
        $offerId = (int)$offer['offer_id'];
        $manualCost = (float)($offer['manual_cost'] ?? 0);
        $fbCost = $offerFbCostMap[$offerId] ?? 0.0;
        if ($fbCost < 0) {
            $fbCost = 0;
        }
        $offer['cost'] = $manualCost + $fbCost;
    }
    unset($offer);
    if ($isDataRequest && $timingSegmentStart !== null) {
        $timing['offer_ms'] = round((microtime(true) - $timingSegmentStart) * 1000, 2);
        $timingSegmentStart = microtime(true);
    }
}

// Get landing page performance data using tracked landing_page_id
$lpCampaignFilter = '';
$lpCampaignFilterParams = [];
if ($selectedCampaignId) {
    $lpCampaignFilter = 'AND cl.campaign_id = ?';
    $lpCampaignFilterParams[] = $selectedCampaignId;
}

$lpTrafficSourceFilter = '';
$lpTrafficSourceFilterParams = [];
if ($selectedTrafficSourceId) {
    if ($trafficSourceColumnExists) {
        $lpTrafficSourceFilter = 'AND cl.traffic_source_id = ?';
    } else {
        $lpTrafficSourceFilter = 'AND c.traffic_source_id = ?';
    }
    $lpTrafficSourceFilterParams[] = $selectedTrafficSourceId;
}

// PERFORMANCE: Skip heavy landing page performance query if we have cached data or target_only (plan 3.2)
// Pre-aggregation: when no token filter, read from clicks_daily_summary (plan: stats pre-aggregation Phase 1)
$landingPagePerformance = [];
if (!$hasCachedData && !$targetOnlyRequest && !$skipLp) {
    if ($useDailySummaryForStats) {
        // Fast path: LP performance from clicks_daily_summary
        $lpSummaryDateFrom = date('Y-m-d', strtotime($utcDateFrom));
        $lpSummaryDateTo = date('Y-m-d', strtotime($utcDateTo));
        // Include ALL rows with landing_page_id (offer_id null or not) so conversions from LP->offer flow are counted in LP main row
        $lpDailyFilter = 's.summary_date >= ? AND s.summary_date <= ? AND s.landing_page_id IS NOT NULL';
        if ($lpCampaignFilter) {
            $lpDailyFilter .= ' ' . str_replace('cl.campaign_id', 's.campaign_id', $lpCampaignFilter);
        }
        if ($lpTrafficSourceFilter) {
            $lpDailyFilter .= ' ' . str_replace(['cl.traffic_source_id', 'c.traffic_source_id'], 's.traffic_source_id', $lpTrafficSourceFilter);
        }
        $lpDailyStmt = $db->prepare("
            SELECT 
                lp.id as lp_id,
                lp.name as lp_name,
                lp.url as lp_url,
                MAX(s.traffic_source_id) as traffic_source_id,
                COALESCE(MAX(ts.name), 'Unknown') as traffic_source_name,
                SUM(CASE WHEN s.offer_id IS NULL THEN s.clicks ELSE 0 END) as visitors,
                SUM(s.cost) as manual_cost,
                SUM(CASE WHEN s.offer_id IS NULL THEN s.lp_clicks ELSE 0 END) as lp_clicks,
                SUM(s.conversions) as conversions,
                SUM(s.revenue) as revenue,
                0 as invalid_clicks
            FROM clicks_daily_summary s
            INNER JOIN landing_pages lp ON lp.id = s.landing_page_id
            LEFT JOIN traffic_sources ts ON ts.id = s.traffic_source_id
            WHERE {$lpDailyFilter}
            GROUP BY lp.id, lp.name, lp.url
            ORDER BY visitors DESC
        ");
        $lpDailyTypes = 'ss';
        $lpDailyValues = [$lpSummaryDateFrom, $lpSummaryDateTo];
        if (!empty($lpCampaignFilterParams)) {
            $lpDailyTypes .= 'i';
            $lpDailyValues = array_merge($lpDailyValues, $lpCampaignFilterParams);
        }
        if (!empty($lpTrafficSourceFilterParams)) {
            $lpDailyTypes .= 'i';
            $lpDailyValues = array_merge($lpDailyValues, $lpTrafficSourceFilterParams);
        }
        $lpDailyStmt->bind_param($lpDailyTypes, ...$lpDailyValues);
        $lpDailyStmt->execute();
        $landingPagePerformance = $lpDailyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $lpDailyStmt->close();
    } else {
        $lpTrafficSourceSelect = $trafficSourceColumnExists 
            ? "cl.traffic_source_id,"
            : "c.traffic_source_id,";
        $lpTrafficSourceJoin = $trafficSourceColumnExists
            ? "LEFT JOIN traffic_sources ts ON cl.traffic_source_id = ts.id"
            : "INNER JOIN campaigns c ON cl.campaign_id = c.id\n    LEFT JOIN traffic_sources ts ON c.traffic_source_id = ts.id";

        $landingPagePerformanceQuery = $db->prepare("
    SELECT 
        lp.id as lp_id,
        lp.name as lp_name,
        lp.url as lp_url,
        {$lpTrafficSourceSelect}
        COALESCE(ts.name, 'Unknown') as traffic_source_name,
        COUNT(DISTINCT CASE 
            -- For Facebook traffic source, require both ad_id AND adset_id (exclude invalid clicks)
            -- PERFORMANCE: Use generated columns (ad_id, adset_id) instead of JSON_EXTRACT for index usage
            WHEN (" . ($trafficSourceColumnExists ? "cl.traffic_source_id = 4" : "c.traffic_source_id = 4") . ") THEN 
                CASE 
                    WHEN cl.ad_id IS NOT NULL 
                        AND cl.adset_id IS NOT NULL
                    THEN cl.id
                    ELSE NULL
                END
            -- For other traffic sources, count all clicks
            ELSE cl.id
        END) as visitors,
        COALESCE(SUM(cl.cost), 0) as manual_cost,
        COUNT(DISTINCT CASE 
            WHEN cl.lp_click = 1 THEN
                CASE 
                    WHEN (" . ($trafficSourceColumnExists ? "cl.traffic_source_id = 4" : "c.traffic_source_id = 4") . ") THEN 
                        CASE 
                            -- PERFORMANCE: Use generated columns (ad_id, adset_id) instead of JSON_EXTRACT for index usage
                            WHEN cl.ad_id IS NOT NULL 
                                AND cl.adset_id IS NOT NULL
                            THEN cl.id
                            ELSE NULL
                        END
                    ELSE cl.id
                END
            ELSE NULL
        END) as lp_clicks,
        COUNT(DISTINCT CASE 
            WHEN (" . ($trafficSourceColumnExists ? "cl.traffic_source_id = 4" : "c.traffic_source_id = 4") . ") THEN 
                CASE 
                    -- PERFORMANCE: Use generated columns (ad_id, adset_id) instead of JSON_EXTRACT for index usage
                    WHEN cl.ad_id IS NOT NULL 
                        AND cl.adset_id IS NOT NULL
                    THEN conv.id
                    ELSE NULL
                END
            ELSE conv.id
        END) as conversions,
        COALESCE(SUM(COALESCE(conv.payout, conv.value)), 0) as revenue,
        -- Count invalid clicks for display
        -- PERFORMANCE: Use generated columns (ad_id, adset_id) instead of JSON_EXTRACT for index usage
        COUNT(DISTINCT CASE 
            WHEN (" . ($trafficSourceColumnExists ? "cl.traffic_source_id = 4" : "c.traffic_source_id = 4") . ") THEN 
                CASE 
                    WHEN cl.ad_id IS NULL 
                        OR cl.adset_id IS NULL
                    THEN cl.id
                    ELSE NULL
                END
            ELSE NULL
        END) as invalid_clicks
    FROM landing_pages lp
    INNER JOIN {$clicksTable} cl ON lp.id = cl.landing_page_id
    LEFT JOIN conversions conv ON cl.click_id = conv.click_id
    {$lpTrafficSourceJoin}
    WHERE cl.ts >= ? AND cl.ts <= ?
    {$lpCampaignFilter}
    {$lpTrafficSourceFilter}
    GROUP BY lp.id, lp.name, lp.url
    ORDER BY visitors DESC
        ");

        $lpBindTypes = 'ss';
        $lpBindValues = $dateParams;
        if (!empty($lpCampaignFilterParams)) {
            $lpBindTypes .= 'i';
            $lpBindValues = array_merge($dateParams, $lpCampaignFilterParams);
        }
        if (!empty($lpTrafficSourceFilterParams)) {
            $lpBindTypes .= 'i';
            $lpBindValues = array_merge($lpBindValues, $lpTrafficSourceFilterParams);
        }

        $landingPagePerformanceQuery->bind_param($lpBindTypes, ...$lpBindValues);
        $landingPagePerformanceQuery->execute();
        $landingPagePerformance = $landingPagePerformanceQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    }
} else {
    // Use cached landing page performance, or empty when target_only (plan 3.2)
    $landingPagePerformance = $targetOnlyRequest ? [] : ($cachedStatsData['landingPagePerformance'] ?? []);
}

// Get Facebook costs per landing page and merge
// PERFORMANCE: Use generated columns ad_id/adset_id (migration 050) for index usage (plan 3.3)
$lpFbCostQuery = $db->prepare("
    SELECT 
        cl.landing_page_id,
        COALESCE(SUM(
            CASE 
                WHEN a_cost.delta_spend IS NOT NULL THEN 
                    a_cost.delta_spend / GREATEST((
                        SELECT COUNT(*) 
                        FROM {$clicksTable} c2 
                        WHERE c2.ad_id = click_data.ad_id
                            AND DATE(c2.ts) = click_data.click_date
                            AND HOUR(c2.ts) = click_data.click_hour
                    ), 1)
                WHEN as_cost.delta_spend IS NOT NULL 
                    AND NOT EXISTS (
                        SELECT 1 
                        FROM ad_hourly_costs a_check
                        INNER JOIN {$clicksTable} c_check ON c_check.ad_id = a_check.ad_id
                        INNER JOIN campaigns camp_check ON c_check.campaign_id = camp_check.id
                        LEFT JOIN facebook_marketing_ad_accounts fmaa_check ON camp_check.facebook_marketing_ad_account_id = fmaa_check.id
                        WHERE c_check.adset_id = click_data.adset_id
                            AND a_check.date = click_data.click_date
                            AND a_check.hour = click_data.click_hour
                            AND (fmaa_check.facebook_marketing_integration_id IS NULL OR a_check.ad_account_id = fmaa_check.facebook_marketing_integration_id)
                    ) THEN 
                    as_cost.delta_spend / GREATEST((
                        SELECT COUNT(*) 
                        FROM {$clicksTable} c2 
                        WHERE c2.adset_id = click_data.adset_id
                            AND DATE(c2.ts) = click_data.click_date
                            AND HOUR(c2.ts) = click_data.click_hour
                    ), 1)
                ELSE 0
            END
        ), 0) as fb_cost
    FROM {$clicksTable} cl
    LEFT JOIN (
        SELECT 
            click_id,
            campaign_id,
            ad_id,
            adset_id,
            DATE(ts) as click_date,
            HOUR(ts) as click_hour,
            landing_page_id
        FROM {$clicksTable}
        WHERE DATE(ts) BETWEEN ? AND ?
    ) as click_data ON click_data.click_id = cl.click_id
    LEFT JOIN campaigns camp ON click_data.campaign_id = camp.id
    LEFT JOIN facebook_marketing_ad_accounts fmaa ON camp.facebook_marketing_ad_account_id = fmaa.id
    LEFT JOIN ad_hourly_costs a_cost ON 
        a_cost.ad_id = click_data.ad_id 
        AND a_cost.date = click_data.click_date 
        AND a_cost.hour = click_data.click_hour
        AND (fmaa.facebook_marketing_integration_id IS NULL OR a_cost.ad_account_id = fmaa.facebook_marketing_integration_id)
    LEFT JOIN adset_hourly_costs as_cost ON 
        as_cost.adset_id = click_data.adset_id 
        AND as_cost.date = click_data.click_date 
        AND as_cost.hour = click_data.click_hour
        AND (fmaa.facebook_marketing_integration_id IS NULL OR as_cost.ad_account_id = fmaa.facebook_marketing_integration_id)
    WHERE cl.ts >= ? AND cl.ts <= ?
    AND click_data.ad_id IS NOT NULL
    AND click_data.adset_id IS NOT NULL
    {$lpCampaignFilter}
    GROUP BY cl.landing_page_id
");

$lpFbBindTypes = 'ssss'; // Two date params for subquery, two for main WHERE clause
$lpFbBindValues = array_merge($dateParams, $dateParams); // Date params for subquery and main query
if (!empty($lpCampaignFilterParams)) {
    $lpFbBindTypes .= 'i';
    $lpFbBindValues = array_merge($lpFbBindValues, $lpCampaignFilterParams);
}

$lpFbCostQuery->bind_param($lpFbBindTypes, ...$lpFbBindValues);
$lpFbCostQuery->execute();
$lpFbCosts = $lpFbCostQuery->get_result()->fetch_all(MYSQLI_ASSOC);

// Create lookup map (batch FB cost per LP - plan: use batch instead of N getAggregatedCost calls)
$lpFbCostMap = [];
foreach ($lpFbCosts as $fbCost) {
    $lpFbCostMap[(int)$fbCost['landing_page_id']] = (float)$fbCost['fb_cost'];
}

// Merge Facebook costs into landing page performance using batch map (avoids N getAggregatedCost calls)
if (!$hasCachedData) {
foreach ($landingPagePerformance as &$lp) {
    $lpId = (int)$lp['lp_id'];
    $manualCost = (float)($lp['manual_cost'] ?? 0);
    $fbCost = $lpFbCostMap[$lpId] ?? 0.0;
    if ($fbCost < 0) {
        $fbCost = 0;
    }
    $lp['cost'] = $manualCost + $fbCost;
}
unset($lp);
}

// DIRECT LINK row for split campaigns: show direct-to-offer click performance
$directLinkPerformance = null;
$directLinkClicks = 0;
if ($selectedCampaignId && $selectedCampaign && ($selectedCampaign['flow_type'] ?? '') === 'Split' && !$skipLp) {
    if ($useDailySummaryForStats && $dailySummaryTableExists) {
        $directSummaryFilter = 's.summary_date >= ? AND s.summary_date <= ? AND s.landing_page_id IS NULL AND s.offer_id IS NOT NULL';
        if ($lpCampaignFilter) {
            $directSummaryFilter .= ' ' . str_replace('cl.campaign_id', 's.campaign_id', $lpCampaignFilter);
        }
        if ($lpTrafficSourceFilter) {
            $directSummaryFilter .= ' ' . str_replace(['cl.traffic_source_id', 'c.traffic_source_id'], 's.traffic_source_id', $lpTrafficSourceFilter);
        }
        $directStmt = $db->prepare("
            SELECT 
                SUM(s.clicks) as visitors,
                SUM(s.direct_clicks) as direct_clicks,
                SUM(s.cost) as manual_cost,
                SUM(s.conversions) as conversions,
                SUM(s.revenue) as revenue
            FROM clicks_daily_summary s
            WHERE {$directSummaryFilter}
        ");
        $directTypes = 'ss';
        $directSummaryDateFrom = date('Y-m-d', strtotime($utcDateFrom));
        $directSummaryDateTo = date('Y-m-d', strtotime($utcDateTo));
        $directValues = [$directSummaryDateFrom, $directSummaryDateTo];
        if (!empty($lpCampaignFilterParams)) {
            $directTypes .= 'i';
            $directValues = array_merge($directValues, $lpCampaignFilterParams);
        }
        if (!empty($lpTrafficSourceFilterParams)) {
            $directTypes .= 'i';
            $directValues = array_merge($directValues, $lpTrafficSourceFilterParams);
        }
        $directStmt->bind_param($directTypes, ...$directValues);
        $directStmt->execute();
        $directRow = $directStmt->get_result()->fetch_assoc();
        $directStmt->close();
        $directVisitors = (int)($directRow['visitors'] ?? 0);
        $directLinkClicks = (int)($directRow['direct_clicks'] ?? 0);
        if ($directVisitors > 0 || $directLinkClicks > 0) {
            $directManualCost = (float)($directRow['manual_cost'] ?? 0);
            $directFbCost = $lpFbCostMap[0] ?? 0.0; // FB cost for landing_page_id IS NULL grouped as 0
            if ($directFbCost < 0) {
                $directFbCost = 0;
            }
            $directLinkPerformance = [
                'lp_id' => 0,
                'lp_name' => 'DIRECT LINK (to offer)',
                'lp_url' => '',
                'visitors' => $directVisitors,
                'lp_clicks' => $directLinkClicks,
                'manual_cost' => $directManualCost,
                'cost' => $directManualCost + $directFbCost,
                'conversions' => (int)($directRow['conversions'] ?? 0),
                'revenue' => (float)($directRow['revenue'] ?? 0),
            ];
            $hasDirectLink = false;
            foreach ($landingPagePerformance as $lp) {
                if ((int)($lp['lp_id'] ?? -1) === 0 || ($lp['lp_name'] ?? '') === 'DIRECT LINK (to offer)') {
                    $hasDirectLink = true;
                    break;
                }
            }
            if (!$hasDirectLink) {
                $landingPagePerformance[] = $directLinkPerformance;
            }
        }
    } else {
        $lpTrafficSourceJoin = $trafficSourceColumnExists
            ? "LEFT JOIN traffic_sources ts ON cl.traffic_source_id = ts.id"
            : "INNER JOIN campaigns c ON cl.campaign_id = c.id\n    LEFT JOIN traffic_sources ts ON c.traffic_source_id = ts.id";
        $directRawStmt = $db->prepare("
            SELECT 
                COUNT(DISTINCT CASE 
                    WHEN (" . ($trafficSourceColumnExists ? "cl.traffic_source_id = 4" : "c.traffic_source_id = 4") . ") THEN 
                        CASE WHEN cl.ad_id IS NOT NULL AND cl.adset_id IS NOT NULL THEN cl.id ELSE NULL END
                    ELSE cl.id
                END) as visitors,
                COUNT(DISTINCT CASE WHEN cl.lp_click = 1 THEN
                    CASE 
                        WHEN (" . ($trafficSourceColumnExists ? "cl.traffic_source_id = 4" : "c.traffic_source_id = 4") . ") THEN 
                            CASE WHEN cl.ad_id IS NOT NULL AND cl.adset_id IS NOT NULL THEN cl.id ELSE NULL END
                        ELSE cl.id
                    END
                ELSE NULL END) as direct_clicks,
                SUM(cl.cost) as manual_cost,
                COUNT(DISTINCT conv.id) as conversions,
                SUM(COALESCE(conv.payout, conv.value)) as revenue
            FROM {$clicksTable} cl
            LEFT JOIN conversions conv ON cl.click_id = conv.click_id
            {$lpTrafficSourceJoin}
            WHERE cl.ts >= ? AND cl.ts <= ?
            AND cl.landing_page_id IS NULL
            AND cl.lp_click = 1
            {$lpCampaignFilter}
            {$lpTrafficSourceFilter}
        ");
        $directRawTypes = 'ss';
        $directRawValues = $dateParams;
        if (!empty($lpCampaignFilterParams)) {
            $directRawTypes .= 'i';
            $directRawValues = array_merge($directRawValues, $lpCampaignFilterParams);
        }
        if (!empty($lpTrafficSourceFilterParams)) {
            $directRawTypes .= 'i';
            $directRawValues = array_merge($directRawValues, $lpTrafficSourceFilterParams);
        }
        $directRawStmt->bind_param($directRawTypes, ...$directRawValues);
        $directRawStmt->execute();
        $directRow = $directRawStmt->get_result()->fetch_assoc();
        $directRawStmt->close();
        $directVisitors = (int)($directRow['visitors'] ?? 0);
        $directLinkClicks = (int)($directRow['direct_clicks'] ?? 0);
        if ($directVisitors > 0 || $directLinkClicks > 0) {
            $directManualCost = (float)($directRow['manual_cost'] ?? 0);
            $directFbCost = $lpFbCostMap[0] ?? 0.0;
            if ($directFbCost < 0) {
                $directFbCost = 0;
            }
            $directLinkPerformance = [
                'lp_id' => 0,
                'lp_name' => 'DIRECT LINK (to offer)',
                'lp_url' => '',
                'visitors' => $directVisitors,
                'lp_clicks' => $directLinkClicks,
                'manual_cost' => $directManualCost,
                'cost' => $directManualCost + $directFbCost,
                'conversions' => (int)($directRow['conversions'] ?? 0),
                'revenue' => (float)($directRow['revenue'] ?? 0),
            ];
            $hasDirectLink = false;
            foreach ($landingPagePerformance as $lp) {
                if ((int)($lp['lp_id'] ?? -1) === 0 || ($lp['lp_name'] ?? '') === 'DIRECT LINK (to offer)') {
                    $hasDirectLink = true;
                    break;
                }
            }
            if (!$hasDirectLink) {
                $landingPagePerformance[] = $directLinkPerformance;
            }
        }
    }
}

// LP→offer breakdown: which offers received clicks from each LP (when checkbox "Show offer breakdown" is checked)
$lpOfferBreakdown = [];
if ($showLpOfferBreakdown && !$skipLp && !$targetOnlyRequest) {
    if ($hasCachedData && isset($cachedStatsData['lpOfferBreakdown'])) {
        $lpOfferBreakdown = $cachedStatsData['lpOfferBreakdown'];
    } elseif ($useDailySummaryForStats && $dailySummaryTableExists) {
        $lpOfferFilter = 's.summary_date >= ? AND s.summary_date <= ? AND s.landing_page_id IS NOT NULL AND s.offer_id IS NOT NULL';
        if ($lpCampaignFilter) {
            $lpOfferFilter .= ' ' . str_replace('cl.campaign_id', 's.campaign_id', $lpCampaignFilter);
        }
        if ($lpTrafficSourceFilter) {
            $lpOfferFilter .= ' ' . str_replace(['cl.traffic_source_id', 'c.traffic_source_id'], 's.traffic_source_id', $lpTrafficSourceFilter);
        }
        $lpOfferStmt = $db->prepare("
            SELECT 
                s.landing_page_id as lp_id,
                s.offer_id,
                o.name as offer_name,
                SUM(s.clicks) as visitors,
                SUM(s.lp_clicks) as lp_clicks,
                SUM(s.cost) as cost,
                SUM(s.conversions) as conversions,
                SUM(s.revenue) as revenue
            FROM clicks_daily_summary s
            INNER JOIN offers o ON o.id = s.offer_id
            WHERE {$lpOfferFilter}
            GROUP BY s.landing_page_id, s.offer_id, o.name
            ORDER BY s.landing_page_id, lp_clicks DESC
        ");
        $lpOfferTypes = 'ss';
        $lpOfferDateFrom = date('Y-m-d', strtotime($utcDateFrom));
        $lpOfferDateTo = date('Y-m-d', strtotime($utcDateTo));
        $lpOfferValues = [$lpOfferDateFrom, $lpOfferDateTo];
        if (!empty($lpCampaignFilterParams)) {
            $lpOfferTypes .= 'i';
            $lpOfferValues = array_merge($lpOfferValues, $lpCampaignFilterParams);
        }
        if (!empty($lpTrafficSourceFilterParams)) {
            $lpOfferTypes .= 'i';
            $lpOfferValues = array_merge($lpOfferValues, $lpTrafficSourceFilterParams);
        }
        $lpOfferStmt->bind_param($lpOfferTypes, ...$lpOfferValues);
        $lpOfferStmt->execute();
        $lpOfferRows = $lpOfferStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $lpOfferStmt->close();
        foreach ($lpOfferRows as $row) {
            $lpId = (int)$row['lp_id'];
            $offerId = (int)$row['offer_id'];
            if (!isset($lpOfferBreakdown[$lpId])) {
                $lpOfferBreakdown[$lpId] = [];
            }
            $lpOfferBreakdown[$lpId][$offerId] = [
                'offer_name' => $row['offer_name'] ?? 'Unknown',
                'visitors' => (int)($row['visitors'] ?? 0),
                'lp_clicks' => (int)($row['lp_clicks'] ?? 0),
                'cost' => (float)($row['cost'] ?? 0),
                'conversions' => (int)($row['conversions'] ?? 0),
                'revenue' => (float)($row['revenue'] ?? 0),
            ];
        }
        // Direct-link offer breakdown (lp_id 0): landing_page_id IS NULL, use direct_clicks
        $directOfferFilter = 's.summary_date >= ? AND s.summary_date <= ? AND s.landing_page_id IS NULL AND s.offer_id IS NOT NULL';
        if ($lpCampaignFilter) {
            $directOfferFilter .= ' ' . str_replace('cl.campaign_id', 's.campaign_id', $lpCampaignFilter);
        }
        if ($lpTrafficSourceFilter) {
            $directOfferFilter .= ' ' . str_replace(['cl.traffic_source_id', 'c.traffic_source_id'], 's.traffic_source_id', $lpTrafficSourceFilter);
        }
        $directOfferStmt = $db->prepare("
            SELECT 
                s.offer_id,
                o.name as offer_name,
                SUM(s.clicks) as visitors,
                SUM(s.direct_clicks) as lp_clicks,
                SUM(s.cost) as cost,
                SUM(s.conversions) as conversions,
                SUM(s.revenue) as revenue
            FROM clicks_daily_summary s
            INNER JOIN offers o ON o.id = s.offer_id
            WHERE {$directOfferFilter}
            GROUP BY s.offer_id, o.name
            ORDER BY lp_clicks DESC
        ");
        $directOfferTypes = 'ss';
        $directOfferValues = [$lpOfferDateFrom, $lpOfferDateTo];
        if (!empty($lpCampaignFilterParams)) {
            $directOfferTypes .= 'i';
            $directOfferValues = array_merge($directOfferValues, $lpCampaignFilterParams);
        }
        if (!empty($lpTrafficSourceFilterParams)) {
            $directOfferTypes .= 'i';
            $directOfferValues = array_merge($directOfferValues, $lpTrafficSourceFilterParams);
        }
        $directOfferStmt->bind_param($directOfferTypes, ...$directOfferValues);
        $directOfferStmt->execute();
        $directOfferRows = $directOfferStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $directOfferStmt->close();
        foreach ($directOfferRows as $row) {
            $offerId = (int)$row['offer_id'];
            if (!isset($lpOfferBreakdown[0])) {
                $lpOfferBreakdown[0] = [];
            }
            $lpOfferBreakdown[0][$offerId] = [
                'offer_name' => $row['offer_name'] ?? 'Unknown',
                'visitors' => (int)($row['visitors'] ?? 0),
                'lp_clicks' => (int)($row['lp_clicks'] ?? 0),
                'cost' => (float)($row['cost'] ?? 0),
                'conversions' => (int)($row['conversions'] ?? 0),
                'revenue' => (float)($row['revenue'] ?? 0),
            ];
        }
    } else {
        $lpRawOfferTrafficJoin = $trafficSourceColumnExists
            ? "LEFT JOIN traffic_sources ts ON cl.traffic_source_id = ts.id"
            : "INNER JOIN campaigns c ON cl.campaign_id = c.id\n    LEFT JOIN traffic_sources ts ON c.traffic_source_id = ts.id";
        $lpRawOfferStmt = $db->prepare("
            SELECT 
                cl.landing_page_id as lp_id,
                cl.offer_id,
                o.name as offer_name,
                COUNT(DISTINCT CASE 
                    WHEN (" . ($trafficSourceColumnExists ? "cl.traffic_source_id = 4" : "c.traffic_source_id = 4") . ") THEN 
                        CASE WHEN cl.ad_id IS NOT NULL AND cl.adset_id IS NOT NULL THEN cl.id ELSE NULL END
                    ELSE cl.id
                END) as visitors,
                COUNT(DISTINCT CASE WHEN cl.lp_click = 1 THEN
                    CASE 
                        WHEN (" . ($trafficSourceColumnExists ? "cl.traffic_source_id = 4" : "c.traffic_source_id = 4") . ") THEN 
                            CASE WHEN cl.ad_id IS NOT NULL AND cl.adset_id IS NOT NULL THEN cl.id ELSE NULL END
                        ELSE cl.id
                    END
                ELSE NULL END) as lp_clicks,
                SUM(cl.cost) as cost,
                COUNT(DISTINCT conv.id) as conversions,
                SUM(COALESCE(conv.payout, conv.value)) as revenue
            FROM {$clicksTable} cl
            INNER JOIN offers o ON o.id = cl.offer_id
            LEFT JOIN conversions conv ON cl.click_id = conv.click_id
            {$lpRawOfferTrafficJoin}
            WHERE cl.ts >= ? AND cl.ts <= ?
            AND cl.landing_page_id IS NOT NULL
            AND cl.offer_id IS NOT NULL
            {$lpCampaignFilter}
            {$lpTrafficSourceFilter}
            GROUP BY cl.landing_page_id, cl.offer_id, o.name
            ORDER BY cl.landing_page_id, lp_clicks DESC
        ");
        $lpRawOfferTypes = 'ss';
        $lpRawOfferValues = $dateParams;
        if (!empty($lpCampaignFilterParams)) {
            $lpRawOfferTypes .= 'i';
            $lpRawOfferValues = array_merge($lpRawOfferValues, $lpCampaignFilterParams);
        }
        if (!empty($lpTrafficSourceFilterParams)) {
            $lpRawOfferTypes .= 'i';
            $lpRawOfferValues = array_merge($lpRawOfferValues, $lpTrafficSourceFilterParams);
        }
        $lpRawOfferStmt->bind_param($lpRawOfferTypes, ...$lpRawOfferValues);
        $lpRawOfferStmt->execute();
        $lpOfferRows = $lpRawOfferStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $lpRawOfferStmt->close();
        foreach ($lpOfferRows as $row) {
            $lpId = (int)$row['lp_id'];
            $offerId = (int)$row['offer_id'];
            if (!isset($lpOfferBreakdown[$lpId])) {
                $lpOfferBreakdown[$lpId] = [];
            }
            $lpOfferBreakdown[$lpId][$offerId] = [
                'offer_name' => $row['offer_name'] ?? 'Unknown',
                'visitors' => (int)($row['visitors'] ?? 0),
                'lp_clicks' => (int)($row['lp_clicks'] ?? 0),
                'cost' => (float)($row['cost'] ?? 0),
                'conversions' => (int)($row['conversions'] ?? 0),
                'revenue' => (float)($row['revenue'] ?? 0),
            ];
        }
        // Direct-link offer breakdown (lp_id 0): landing_page_id IS NULL
        $directRawOfferStmt = $db->prepare("
            SELECT 
                cl.offer_id,
                o.name as offer_name,
                COUNT(DISTINCT CASE 
                    WHEN (" . ($trafficSourceColumnExists ? "cl.traffic_source_id = 4" : "c.traffic_source_id = 4") . ") THEN 
                        CASE WHEN cl.ad_id IS NOT NULL AND cl.adset_id IS NOT NULL THEN cl.id ELSE NULL END
                    ELSE cl.id
                END) as visitors,
                COUNT(DISTINCT CASE WHEN cl.lp_click = 1 THEN
                    CASE 
                        WHEN (" . ($trafficSourceColumnExists ? "cl.traffic_source_id = 4" : "c.traffic_source_id = 4") . ") THEN 
                            CASE WHEN cl.ad_id IS NOT NULL AND cl.adset_id IS NOT NULL THEN cl.id ELSE NULL END
                        ELSE cl.id
                    END
                ELSE NULL END) as lp_clicks,
                SUM(cl.cost) as cost,
                COUNT(DISTINCT conv.id) as conversions,
                SUM(COALESCE(conv.payout, conv.value)) as revenue
            FROM {$clicksTable} cl
            INNER JOIN offers o ON o.id = cl.offer_id
            LEFT JOIN conversions conv ON cl.click_id = conv.click_id
            {$lpRawOfferTrafficJoin}
            WHERE cl.ts >= ? AND cl.ts <= ?
            AND cl.landing_page_id IS NULL
            AND cl.offer_id IS NOT NULL
            {$lpCampaignFilter}
            {$lpTrafficSourceFilter}
            GROUP BY cl.offer_id, o.name
            ORDER BY lp_clicks DESC
        ");
        $directRawOfferTypes = 'ss';
        $directRawOfferValues = $dateParams;
        if (!empty($lpCampaignFilterParams)) {
            $directRawOfferTypes .= 'i';
            $directRawOfferValues = array_merge($directRawOfferValues, $lpCampaignFilterParams);
        }
        if (!empty($lpTrafficSourceFilterParams)) {
            $directRawOfferTypes .= 'i';
            $directRawOfferValues = array_merge($directRawOfferValues, $lpTrafficSourceFilterParams);
        }
        $directRawOfferStmt->bind_param($directRawOfferTypes, ...$directRawOfferValues);
        $directRawOfferStmt->execute();
        $directOfferRows = $directRawOfferStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $directRawOfferStmt->close();
        foreach ($directOfferRows as $row) {
            $offerId = (int)$row['offer_id'];
            if (!isset($lpOfferBreakdown[0])) {
                $lpOfferBreakdown[0] = [];
            }
            $lpOfferBreakdown[0][$offerId] = [
                'offer_name' => $row['offer_name'] ?? 'Unknown',
                'visitors' => (int)($row['visitors'] ?? 0),
                'lp_clicks' => (int)($row['lp_clicks'] ?? 0),
                'cost' => (float)($row['cost'] ?? 0),
                'conversions' => (int)($row['conversions'] ?? 0),
                'revenue' => (float)($row['revenue'] ?? 0),
            ];
        }
    }
}

if ($isDataRequest && $timingSegmentStart !== null && !$hasCachedData) {
    $timing['lp_ms'] = round((microtime(true) - $timingSegmentStart) * 1000, 2);
    $timingSegmentStart = microtime(true);
}

// Get campaign performance data (this we CAN do accurately)
$campaignPerformanceWhere = [];
$campaignPerformanceWhereParams = [];

if ($selectedCampaignId) {
    $campaignPerformanceWhere[] = "c.id = ?";
    $campaignPerformanceWhereParams[] = $selectedCampaignId;
}
if (!empty($statusFilterArray) && is_array($statusFilterArray)) {
    $placeholders = implode(',', array_fill(0, count($statusFilterArray), '?'));
    $campaignPerformanceWhere[] = "c.status IN ({$placeholders})";
    foreach ($statusFilterArray as $s) {
        $campaignPerformanceWhereParams[] = $s;
    }
} elseif ($statusFilter !== 'all') {
    $campaignPerformanceWhere[] = "c.status = ?";
    $campaignPerformanceWhereParams[] = $statusFilter;
}

$campaignPerformanceWhereClause = !empty($campaignPerformanceWhere) 
    ? "WHERE " . implode(" AND ", $campaignPerformanceWhere) 
    : "";

// Build traffic source filter for campaign performance clicks
$campaignPerformanceTrafficSourceFilter = '';
$campaignPerformanceTrafficSourceFilterParams = [];
if ($selectedTrafficSourceId) {
    if ($trafficSourceColumnExists) {
        $campaignPerformanceTrafficSourceFilter = 'AND cl.traffic_source_id = ?';
    } else {
        $campaignPerformanceTrafficSourceFilter = 'AND c.traffic_source_id = ?';
    }
    // Note: This will be added to the bind values later
}
// Exclude test/Meta approval clicks at JOIN so we don't scan those rows (Facebook = 4)
$campaignPerformanceInvalidClickExclusion = "AND (" . ($trafficSourceColumnExists ? "cl.traffic_source_id != 4" : "c.traffic_source_id != 4") . " OR (cl.ad_id IS NOT NULL AND cl.adset_id IS NOT NULL))";

// Build campaign performance query - support slug grouping if requested
// Exclude invalid clicks for Facebook traffic source (require both ad_id AND adset_id)
// Also exclude clicks missing key UTM parameters (approval clicks typically lack these)
// Also exclude clicks with Facebook external hit user agent
$campaignPerformanceSelect = "
        c.id as campaign_id,
        c.name as campaign_name,
        c.status,
        COUNT(DISTINCT CASE 
            -- Exclude Facebook external hit user agent
            WHEN cl.ua LIKE '%facebookexternalhit/1.1%' THEN NULL
            -- For Facebook traffic source, require both ad_id AND adset_id AND key UTM parameters (exclude invalid/approval clicks)
            -- PERFORMANCE: Use generated columns (ad_id, adset_id) instead of JSON_EXTRACT for index usage
            WHEN c.traffic_source_id = 4 THEN 
                CASE 
                    WHEN cl.ad_id IS NOT NULL 
                        AND cl.adset_id IS NOT NULL
                    THEN cl.id
                    ELSE NULL
                END
            -- For other traffic sources, count all clicks
            ELSE cl.id
        END) as visitors,
        COALESCE(SUM(cl.cost), 0) as manual_cost,
        COUNT(DISTINCT CASE 
            -- Exclude Facebook external hit user agent
            WHEN cl.ua LIKE '%facebookexternalhit/1.1%' THEN NULL
            WHEN cl.lp_click = 1 THEN
                CASE 
                    WHEN c.traffic_source_id = 4 THEN 
                        CASE 
                            -- PERFORMANCE: Use generated columns (ad_id, adset_id) instead of JSON_EXTRACT for index usage
                            WHEN cl.ad_id IS NOT NULL 
                                AND cl.adset_id IS NOT NULL
                            THEN cl.id
                            ELSE NULL
                        END
                    ELSE cl.id
                END
            ELSE NULL
        END) as lp_clicks,
        COUNT(DISTINCT CASE 
            -- Exclude conversions from clicks with Facebook external hit user agent
            WHEN cl.ua LIKE '%facebookexternalhit/1.1%' THEN NULL
            WHEN c.traffic_source_id = 4 THEN 
                CASE 
                    -- PERFORMANCE: Use generated columns (ad_id, adset_id) instead of JSON_EXTRACT for index usage
                    WHEN cl.ad_id IS NOT NULL 
                        AND cl.adset_id IS NOT NULL
                    THEN conv.id
                    ELSE NULL
                END
            ELSE conv.id
        END) as conversions,
        COALESCE(SUM(COALESCE(conv.payout, conv.value)), 0) as revenue,
        -- Count invalid clicks for display
        COUNT(DISTINCT CASE 
            WHEN c.traffic_source_id = 4 THEN 
                CASE 
                    WHEN JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.ad_id')) IS NULL 
                        OR JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.ad_id')) = ''
                        OR JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.ad_id')) = 'null'
                        OR JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.ad_id')) LIKE '{{%'
                        OR JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.ad_id')) LIKE '{ts:%'
                        OR JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.adset_id')) IS NULL
                        OR JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.adset_id')) = ''
                        OR JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.adset_id')) = 'null'
                        OR JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.adset_id')) LIKE '{{%'
                        OR JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.adset_id')) LIKE '{ts:%'
                    THEN cl.id
                    ELSE NULL
                END
            ELSE NULL
        END) as invalid_clicks";
$campaignPerformanceGroupBy = "GROUP BY c.id, c.name, c.status";
$campaignPerformanceJoin = "";

// If grouping by slug and a campaign is selected, group by slug instead
if ($groupBy === 'slug' && $selectedCampaignId) {
    $campaignPerformanceSelect = "
        cs.id as slug_id,
        cs.slug,
        cs.slug_label,
        c.id as campaign_id,
        c.name as campaign_name,
        c.status,
        COUNT(DISTINCT CASE 
            -- Exclude Facebook external hit user agent
            WHEN cl.ua LIKE '%facebookexternalhit/1.1%' THEN NULL
            WHEN c.traffic_source_id = 4 THEN 
                CASE 
                    -- PERFORMANCE: Use generated columns (ad_id, adset_id) instead of JSON_EXTRACT for index usage
                    WHEN cl.ad_id IS NOT NULL 
                        AND cl.adset_id IS NOT NULL
                    THEN cl.id
                    ELSE NULL
                END
            ELSE cl.id
        END) as visitors,
        COALESCE(SUM(cl.cost), 0) as manual_cost,
        COUNT(DISTINCT CASE 
            -- Exclude Facebook external hit user agent
            WHEN cl.ua LIKE '%facebookexternalhit/1.1%' THEN NULL
            WHEN cl.lp_click = 1 THEN
                CASE 
                    WHEN c.traffic_source_id = 4 THEN 
                        CASE 
                            -- PERFORMANCE: Use generated columns (ad_id, adset_id) instead of JSON_EXTRACT for index usage
                            WHEN cl.ad_id IS NOT NULL 
                                AND cl.adset_id IS NOT NULL
                            THEN cl.id
                            ELSE NULL
                        END
                    ELSE cl.id
                END
            ELSE NULL
        END) as lp_clicks,
        COUNT(DISTINCT CASE 
            -- Exclude conversions from clicks with Facebook external hit user agent
            WHEN cl.ua LIKE '%facebookexternalhit/1.1%' THEN NULL
            WHEN c.traffic_source_id = 4 THEN 
                CASE 
                    -- PERFORMANCE: Use generated columns (ad_id, adset_id) instead of JSON_EXTRACT for index usage
                    WHEN cl.ad_id IS NOT NULL 
                        AND cl.adset_id IS NOT NULL
                    THEN conv.id
                    ELSE NULL
                END
            ELSE conv.id
        END) as conversions,
        COALESCE(SUM(COALESCE(conv.payout, conv.value)), 0) as revenue,
        COUNT(DISTINCT CASE 
            WHEN c.traffic_source_id = 4 THEN 
                CASE 
                    WHEN JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.ad_id')) IS NULL 
                        OR JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.ad_id')) = ''
                        OR JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.ad_id')) = 'null'
                        OR JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.ad_id')) LIKE '{{%'
                        OR JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.ad_id')) LIKE '{ts:%'
                        OR JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.adset_id')) IS NULL
                        OR JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.adset_id')) = ''
                        OR JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.adset_id')) = 'null'
                        OR JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.adset_id')) LIKE '{{%'
                        OR JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.adset_id')) LIKE '{ts:%'
                    THEN cl.id
                    ELSE NULL
                END
            ELSE NULL
        END) as invalid_clicks";
    $campaignPerformanceGroupBy = "GROUP BY cs.id, cs.slug, cs.slug_label, c.id, c.name, c.status";
    $campaignPerformanceJoin = "LEFT JOIN campaign_slugs cs ON cl.slug_id = cs.id";
}

// PERFORMANCE: Skip heavy campaign performance query if we have cached data
$campaignPerformance = [];
if (!$hasCachedData && !$targetOnlyRequest && !$skipCampaign) {
    $campaignPerformanceQuery = $db->prepare("
    SELECT 
        {$campaignPerformanceSelect}
    FROM campaigns c
    LEFT JOIN {$clicksTable} cl ON c.id = cl.campaign_id 
        AND cl.ts >= ? AND cl.ts <= ?
        {$campaignPerformanceTrafficSourceFilter}
        {$campaignPerformanceInvalidClickExclusion}
    {$campaignPerformanceJoin}
    LEFT JOIN conversions conv ON cl.click_id = conv.click_id
    {$campaignPerformanceWhereClause}
    {$campaignPerformanceGroupBy}
    ORDER BY visitors DESC
");

    // Get Facebook costs per campaign (or per slug if grouping by slug)
    // Uses new join structure (campaigns -> facebook_marketing_ad_accounts) and divides by click count
    $campaignFbCostQuery = $db->prepare("
    SELECT 
        " . ($groupBy === 'slug' && $selectedCampaignId ? "cl.slug_id," : "") . "
        cl.campaign_id,
        COALESCE(SUM(
            CASE 
                WHEN a_cost.delta_spend IS NOT NULL THEN 
                    a_cost.delta_spend / GREATEST((
                        SELECT COUNT(*) 
                        FROM {$clicksTable} c2 
                        WHERE JSON_UNQUOTE(JSON_EXTRACT(c2.extra_json, '$.traffic_source_tokens.ad_id')) = click_data.ad_id
                            AND DATE(c2.ts) = click_data.click_date
                            AND HOUR(c2.ts) = click_data.click_hour
                    ), 1)
                WHEN as_cost.delta_spend IS NOT NULL 
                    AND NOT EXISTS (
                        -- Only use adset cost if NO ad costs exist for this adset in this hour
                        SELECT 1 
                        FROM ad_hourly_costs a_check
                        INNER JOIN {$clicksTable} c_check ON JSON_UNQUOTE(JSON_EXTRACT(c_check.extra_json, '$.traffic_source_tokens.ad_id')) = a_check.ad_id
                        INNER JOIN campaigns camp_check ON c_check.campaign_id = camp_check.id
                        LEFT JOIN facebook_marketing_ad_accounts fmaa_check ON camp_check.facebook_marketing_ad_account_id = fmaa_check.id
                        WHERE JSON_UNQUOTE(JSON_EXTRACT(c_check.extra_json, '$.traffic_source_tokens.adset_id')) = click_data.adset_id
                            AND a_check.date = click_data.click_date
                            AND a_check.hour = click_data.click_hour
                            AND (fmaa_check.facebook_marketing_integration_id IS NULL OR a_check.ad_account_id = fmaa_check.facebook_marketing_integration_id)
                    ) THEN 
                    as_cost.delta_spend / GREATEST((
                        SELECT COUNT(*) 
                        FROM {$clicksTable} c2 
                        WHERE JSON_UNQUOTE(JSON_EXTRACT(c2.extra_json, '$.traffic_source_tokens.adset_id')) = click_data.adset_id
                            AND DATE(c2.ts) = click_data.click_date
                            AND HOUR(c2.ts) = click_data.click_hour
                    ), 1)
                ELSE 0
            END
        ), 0) as fb_cost
    FROM {$clicksTable} cl
    LEFT JOIN (
        SELECT 
            click_id,
            campaign_id,
            JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.ad_id')) as ad_id,
            JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.adset_id')) as adset_id,
            DATE(ts) as click_date,
            HOUR(ts) as click_hour
        FROM {$clicksTable}
        WHERE DATE(ts) BETWEEN ? AND ?
    ) as click_data ON click_data.click_id = cl.click_id
    LEFT JOIN campaigns camp ON click_data.campaign_id = camp.id
    LEFT JOIN facebook_marketing_ad_accounts fmaa ON camp.facebook_marketing_ad_account_id = fmaa.id
    LEFT JOIN ad_hourly_costs a_cost ON 
        a_cost.ad_id = click_data.ad_id 
        AND a_cost.date = click_data.click_date 
        AND a_cost.hour = click_data.click_hour
        AND (fmaa.facebook_marketing_integration_id IS NULL OR a_cost.ad_account_id = fmaa.facebook_marketing_integration_id)
    LEFT JOIN adset_hourly_costs as_cost ON 
        as_cost.adset_id = click_data.adset_id 
        AND as_cost.date = click_data.click_date 
        AND as_cost.hour = click_data.click_hour
        AND (fmaa.facebook_marketing_integration_id IS NULL OR as_cost.ad_account_id = fmaa.facebook_marketing_integration_id)
    WHERE cl.ts >= ? AND cl.ts <= ?
    AND click_data.ad_id IS NOT NULL AND click_data.ad_id != '' AND click_data.ad_id != 'null'
    AND click_data.adset_id IS NOT NULL AND click_data.adset_id != '' AND click_data.adset_id != 'null'
    AND click_data.ad_id NOT LIKE '{{%' AND click_data.ad_id NOT LIKE '{ts:%'
    AND click_data.adset_id NOT LIKE '{{%' AND click_data.adset_id NOT LIKE '{ts:%'
    {$campaignPerformanceTrafficSourceFilter}
    " . (!empty($campaignPerformanceWhere) ? "AND " . implode(" AND ", array_map(function($w) { return str_replace('c.', 'camp.', $w); }, $campaignPerformanceWhere)) : '') . "
    GROUP BY " . ($groupBy === 'slug' && $selectedCampaignId ? "cl.slug_id, " : "") . "cl.campaign_id
");

$campaignPerformanceBindTypes = 'ss';
$campaignPerformanceBindValues = $dateParams; // dateFrom, dateTo
if ($selectedTrafficSourceId) {
    $campaignPerformanceBindTypes .= 'i';
    $campaignPerformanceBindValues[] = $selectedTrafficSourceId;
}
if (!empty($campaignPerformanceWhereParams)) {
    // Campaign ID and status are strings/int in WHERE clause
    foreach ($campaignPerformanceWhereParams as $param) {
        if (is_int($param)) {
            $campaignPerformanceBindTypes .= 'i';
        } else {
            $campaignPerformanceBindTypes .= 's';
        }
    }
    $campaignPerformanceBindValues = array_merge($campaignPerformanceBindValues, $campaignPerformanceWhereParams);
}

    $campaignPerformanceQuery->bind_param($campaignPerformanceBindTypes, ...$campaignPerformanceBindValues);
    $campaignPerformanceQuery->execute();
    $campaignPerformance = $campaignPerformanceQuery->get_result()->fetch_all(MYSQLI_ASSOC);

    // Get Facebook costs per campaign and merge
$campaignFbBindTypes = 'ssss'; // Two date params for subquery, two for main WHERE clause
$campaignFbBindValues = array_merge($dateParams, $dateParams); // Date params for subquery and main query
if ($selectedTrafficSourceId) {
    $campaignFbBindTypes .= 'i';
    $campaignFbBindValues[] = $selectedTrafficSourceId;
}
if (!empty($campaignPerformanceWhereParams)) {
    // Campaign ID and status params
    foreach ($campaignPerformanceWhereParams as $param) {
        if (is_int($param)) {
            $campaignFbBindTypes .= 'i';
        } else {
            $campaignFbBindTypes .= 's';
        }
    }
    $campaignFbBindValues = array_merge($campaignFbBindValues, $campaignPerformanceWhereParams);
}
$campaignFbCostQuery->bind_param($campaignFbBindTypes, ...$campaignFbBindValues);
$campaignFbCostQuery->execute();
$campaignFbCosts = $campaignFbCostQuery->get_result()->fetch_all(MYSQLI_ASSOC);

// Create lookup map (support slug grouping)
$campaignFbCostMap = [];
foreach ($campaignFbCosts as $fbCost) {
    if ($groupBy === 'slug' && $selectedCampaignId && isset($fbCost['slug_id'])) {
        $campaignFbCostMap[(int)$fbCost['slug_id']] = (float)$fbCost['fb_cost'];
    } else {
        $campaignFbCostMap[(int)$fbCost['campaign_id']] = (float)$fbCost['fb_cost'];
    }
}

// Merge Facebook costs into campaign performance using getAggregatedCost for consistency
foreach ($campaignPerformance as &$camp) {
    $manualCost = (float)($camp['manual_cost'] ?? 0);
    
    // Build filter for this campaign
    // Note: getAggregatedCost expects filters that work for both manual cost query (no alias) 
    // and Facebook cost query (uses 'cl.' alias). It will replace 'cl.campaign_id' with 'campaign_id' for manual query.
    $campFilter = '';
    $campFilterParams = [];
    
    if ($groupBy === 'slug' && $selectedCampaignId && isset($camp['slug_id'])) {
        // For slug grouping, we need to filter by campaign_id that has this slug_id
        // Since slug_id is in clicks table, we can't easily filter it in getAggregatedCost
        // So we'll get all campaign_ids for this slug and filter by those
        $slugId = (int)$camp['slug_id'];
        $slugCampaignsQuery = $db->prepare("SELECT DISTINCT campaign_id FROM {$clicksTable} WHERE slug_id = ? AND ts >= ? AND ts <= ?");
        $slugCampaignsQuery->bind_param('iss', $slugId, $utcDateFrom, $utcDateTo);
        $slugCampaignsQuery->execute();
        $slugCampaigns = $slugCampaignsQuery->get_result()->fetch_all(MYSQLI_ASSOC);
        $campaignIds = array_map('intval', array_column($slugCampaigns, 'campaign_id'));
        if (empty($campaignIds)) {
            $campFilter = 'AND 1=0'; // No campaigns, return 0
            $campFilterParams = [];
        } else {
            $placeholders = str_repeat('?,', count($campaignIds) - 1) . '?';
            $campFilter = 'AND campaign_id IN (' . $placeholders . ')';
            $campFilterParams = $campaignIds;
        }
    } else {
        $campaignId = (int)$camp['campaign_id'];
        $campFilter = 'AND campaign_id = ?';
        $campFilterParams = [$campaignId];
    }
    
    // Don't add traffic source or campaignPerformanceWhereParams here - 
    // those are already handled in the main query that filters the campaigns
    
    // Use getAggregatedCost for this specific campaign (with request-level cache - plan 3.3)
    $campCostCacheKey = md5($utcDateFrom . '|' . $utcDateTo . '|' . $campFilter . '|' . json_encode($campFilterParams));
    if (!isset($requestCostCache[$campCostCacheKey])) {
        $requestCostCache[$campCostCacheKey] = $costAggregator->getAggregatedCost($utcDateFrom, $utcDateTo, $campFilter, $campFilterParams, $userTimezone);
    }
    $totalCost = $requestCostCache[$campCostCacheKey];
    $fbCost = $totalCost - $manualCost;
    if ($fbCost < 0) {
        $fbCost = 0; // Safety check
    }
    
    $camp['cost'] = $manualCost + $fbCost;
}
unset($camp);
    if ($isDataRequest && $timingSegmentStart !== null && !$hasCachedData) {
        $timing['campaign_ms'] = round((microtime(true) - $timingSegmentStart) * 1000, 2);
        $timingSegmentStart = microtime(true);
    }
} else {
    // Use cached campaign performance, or empty when target_only (plan 3.2)
    $campaignPerformance = $targetOnlyRequest ? [] : ($cachedStatsData['campaignPerformance'] ?? []);
}

    // Get token display names for all selected tokens
    $selectedTokenNames = [];
    foreach ($selectedTokens as $idx => $tokenParam) {
        $tokenName = $tokenParam; // Default to parameter if name not found
        // Check in available tokens (traffic source tokens)
        foreach ($availableTokens as $token) {
            $tokenParamCheck = is_array($token) ? $token['parameter'] : $token;
            if ($tokenParamCheck === $tokenParam) {
                $tokenName = is_array($token) ? $token['name'] : $token;
                break;
            }
        }
        // Check in custom tokens if not found
        if ($tokenName === $tokenParam) {
            foreach ($customTokens as $token) {
                if (($token['parameter'] ?? '') === $tokenParam) {
                    $tokenName = $token['name'] ?? $tokenParam;
                    break;
                }
            }
        }
        // Check in built-in tokens if still not found
        if ($tokenName === $tokenParam) {
            foreach ($builtInTokens as $token) {
                if (($token['parameter'] ?? '') === $tokenParam) {
                    $tokenName = $token['name'] ?? $tokenParam;
                    break;
                }
            }
        }
        $selectedTokenNames[] = $tokenName;
    }
    // Keep backward compatibility for single token display
    $selectedTokenName = $selectedTokenNames[0] ?? $selectedToken;

    // Note: $hasCachedData, $cachedStatsData, $isDataRequest, and $useAjaxLoading are already defined earlier in the file (around line 165)
    
    // Get target performance data (token-based breakdown) - Support up to 3 tokens for drill-down
    $targetPerformance = [];
    $targetLPPerformance = []; // LP performance by token values (nested)
    $targetOfferPerformance = []; // Offer performance by token values (nested)
    $lpTokenPerformance = []; // [lp_id][token1][token2][token3] => stats (for nested display)
    $offerTokenPerformance = []; // [offer_id][token1][token2][token3] => stats (for nested display)
    
    // If we have cached data, use it instead of recalculating
    if ($hasCachedData && $cachedStatsData) {
        $targetPerformance = $cachedStatsData['targetPerformance'] ?? [];
        $targetLPPerformance = $cachedStatsData['lpPerformance'] ?? [];
        $targetOfferPerformance = $cachedStatsData['offerPerformance'] ?? [];
        $lpTokenPerformance = $cachedStatsData['lpTokenPerformance'] ?? [];
        $offerTokenPerformance = $cachedStatsData['offerTokenPerformance'] ?? [];
        // Restore chart data from cache if available
        if (isset($cachedStatsData['chartLabels'])) {
            $chartLabels = $cachedStatsData['chartLabels'];
        }
        if (isset($cachedStatsData['chartVisitors'])) {
            $chartVisitors = $cachedStatsData['chartVisitors'];
        }
        if (isset($cachedStatsData['chartClicks'])) {
            $chartClicks = $cachedStatsData['chartClicks'];
        }
        if (isset($cachedStatsData['chartConversions'])) {
            $chartConversions = $cachedStatsData['chartConversions'];
        }
        if (isset($cachedStatsData['chartRevenue'])) {
            $chartRevenue = $cachedStatsData['chartRevenue'];
        }
        if (isset($cachedStatsData['chartCost'])) {
            $chartCost = $cachedStatsData['chartCost'];
        }
    }
    // PERFORMANCE: Skip heavy stats calculation on initial page load if using AJAX
    // TESTING: no_target=1 skips target/token query to isolate slowdown
    elseif ($viewMode === 'target' && !empty($selectedTokens) && !$useAjaxLoading && !$skipTarget) {
        if ($isDataRequest) {
            $timingSegmentStart = microtime(true);
        }
        // Helper function to extract token value from row
        $extractTokenValue = function($tokenParam, $row, $builtInTokens) use ($db) {
            // Special handling for traffic_source_id
            if ($tokenParam === 'traffic_source_id') {
                $trafficSourceId = $row['traffic_source_id'] ?? null;
                if ($trafficSourceId) {
                    // Try to get name from traffic_sources table
                    $stmt = $db->prepare("SELECT name FROM traffic_sources WHERE id = ?");
                    $stmt->bind_param('i', $trafficSourceId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $ts = $result->fetch_assoc();
                    return $ts ? $ts['name'] : 'Unknown';
                }
                return 'N/A';
            }
            
            // Check if it's a built-in token
            $isBuiltInToken = false;
            $builtInColumn = null;
            foreach ($builtInTokens as $builtIn) {
                if ($builtIn['parameter'] === $tokenParam) {
                    $isBuiltInToken = true;
                    $builtInColumn = $builtIn['column'];
                    break;
                }
            }
            
            if ($isBuiltInToken && $builtInColumn) {
                return $row[$builtInColumn] ?? 'N/A';
            }
            
            // Extract from extra_json
            $extraJsonRaw = $row['extra_json'] ?? '{}';
            $extraJson = json_decode($extraJsonRaw, true);
            
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($extraJson)) {
                return 'N/A';
            }
            
            $allParams = $extraJson['all_params'] ?? [];
            $trafficSourceTokens = $extraJson['traffic_source_tokens'] ?? [];
            $customTokens = $extraJson['custom_tokens'] ?? [];
            
            // Try multiple sources
            $tokenValue = $allParams[$tokenParam] ?? null;
            if ($tokenValue === null || $tokenValue === '') {
                $tokenValue = $trafficSourceTokens[$tokenParam] ?? null;
            }
            if (($tokenValue === null || $tokenValue === '') && isset($customTokens[$tokenParam])) {
                $tokenValue = $customTokens[$tokenParam]['value'] ?? null;
            }
            
            return ($tokenValue === null || $tokenValue === '') ? 'N/A' : $tokenValue;
        };
        
        // Check which tokens are built-in (need columns in SELECT)
        $builtInColumns = [];
        // Note: $hasMultipleTrafficSources is already checked earlier, but we may need to refresh it here
        // if the query didn't run earlier (e.g., no campaign selected initially)
        if (!$hasMultipleTrafficSources && $selectedCampaignId) {
            if ($trafficSourceColumnExists) {
                $checkMultipleTrafficSources = $db->prepare("
                    SELECT COUNT(DISTINCT cl.traffic_source_id) as traffic_source_count
                    FROM {$clicksTable} cl
                    WHERE {$dateFilter}
                    {$campaignFilter}
                    AND cl.traffic_source_id IS NOT NULL
                ");
            } else {
                $checkMultipleTrafficSources = $db->prepare("
                    SELECT COUNT(DISTINCT c.traffic_source_id) as traffic_source_count
                    FROM {$clicksTable} cl
                    INNER JOIN campaigns c ON cl.campaign_id = c.id
                    WHERE {$dateFilter}
                    {$campaignFilter}
                    AND c.traffic_source_id IS NOT NULL
                ");
            }
            $checkBindTypes = 'ss';
            $checkBindValues = $dateParams;
            if (!empty($campaignFilterParams)) {
                $checkBindTypes .= 'i';
                $checkBindValues = array_merge($dateParams, $campaignFilterParams);
            }
            $checkMultipleTrafficSources->bind_param($checkBindTypes, ...$checkBindValues);
            $checkMultipleTrafficSources->execute();
            $trafficSourceCheck = $checkMultipleTrafficSources->get_result()->fetch_assoc();
            $hasMultipleTrafficSources = ($trafficSourceCheck['traffic_source_count'] ?? 0) > 1;
        }
        
        // Always join traffic_sources if we have multiple traffic sources OR if traffic_source_id is selected as a token
        $needsTrafficSourceJoin = $hasMultipleTrafficSources;
        foreach ($selectedTokens as $tokenParam) {
            if ($tokenParam === 'traffic_source_id') {
                $needsTrafficSourceJoin = true;
                break;
            }
        }
        
        // Only include traffic_source_id in SELECT if we need it (for joins or if column exists)
        $trafficSourceSelect = "";
        if ($needsTrafficSourceJoin || $trafficSourceColumnExists) {
            $trafficSourceSelect = $trafficSourceColumnExists
                ? "cl.traffic_source_id,"
                : "c.traffic_source_id,";
        }
        
        // PERFORMANCE: Refactor to use SQL GROUP BY instead of loading all rows
        // Extract token values in SQL SELECT for GROUP BY aggregation
        $tokenSelectColumns = [];
        $groupByColumns = [];
        
        foreach ($selectedTokens as $idx => $tokenParam) {
            $columnAlias = "token{$idx}_value";
            $found = false;
            
            // Check built-in tokens first
            foreach ($builtInTokens as $builtIn) {
                if ($builtIn['parameter'] === $tokenParam) {
                    // For traffic_source_id, select name instead of ID
                    if ($tokenParam === 'traffic_source_id') {
                        $tokenSelectColumns[] = "COALESCE(ts.name, 'Unknown') as {$columnAlias}";
                        $groupByColumns[] = "COALESCE(ts.name, 'Unknown')";
                    } else {
                        $tokenSelectColumns[] = "{$builtIn['column']} as {$columnAlias}";
                        $groupByColumns[] = $builtIn['column'];
                    }
                    $builtInColumns[$idx] = $columnAlias;
                    $found = true;
                    break;
                }
            }
            
            // Check for ad_id/adset_id: use raw JSON for display so "ad21"/"adset5" show correctly
            // (generated columns cast to BIGINT so non-numeric values become 0)
            if (!$found && ($tokenParam === 'ad_id' || $tokenParam === 'adset_id')) {
                $jsonPath = "'\$." . "traffic_source_tokens.{$tokenParam}'";
                $tokenExpr = "COALESCE(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, {$jsonPath}))), ''), CAST(cl.{$tokenParam} AS CHAR), '0')";
                $tokenSelectColumns[] = "{$tokenExpr} as {$columnAlias}";
                $groupByColumns[] = $tokenExpr;
                $builtInColumns[$idx] = $columnAlias;
                $found = true;
            }
            
            // For name tokens (adset_name, ad_name), use generated columns for index usage (migration 053)
            if (!$found && $tokenParam === 'ad_name') {
                $tokenSelectColumns[] = "COALESCE(cl.ad_name_value, 'N/A') as {$columnAlias}";
                $groupByColumns[] = "cl.ad_name_value";
                $builtInColumns[$idx] = $columnAlias;
                $found = true;
            }
            if (!$found && $tokenParam === 'adset_name') {
                $tokenSelectColumns[] = "COALESCE(cl.adset_name_value, 'N/A') as {$columnAlias}";
                $groupByColumns[] = "cl.adset_name_value";
                $builtInColumns[$idx] = $columnAlias;
                $found = true;
            }
            
            // For custom tokens, use JSON_EXTRACT
            if (!$found) {
                // Try traffic_source_tokens first
                $tokenSelectColumns[] = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.{$tokenParam}')), JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.custom_tokens.{$tokenParam}.value')), 'N/A') as {$columnAlias}";
                $groupByColumns[] = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.{$tokenParam}')), JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.custom_tokens.{$tokenParam}.value')), 'N/A')";
            }
        }

        // Join and bind params for token queries (used in both aggregate and non-aggregate paths for aligned offer breakdown)
        $joinClause = "";
        if ($needsTrafficSourceJoin) {
            if ($trafficSourceColumnExists) {
                $joinClause = "LEFT JOIN traffic_sources ts ON cl.traffic_source_id = ts.id";
            } else {
                $joinClause = "INNER JOIN campaigns c ON cl.campaign_id = c.id\n            LEFT JOIN traffic_sources ts ON c.traffic_source_id = ts.id";
            }
        }
        $bindTypes = 'ss';
        $bindValues = $dateParams;
        if (!empty($campaignFilterParams)) {
            $bindTypes .= 'i';
            $bindValues = array_merge($dateParams, $campaignFilterParams);
        }
        if (!empty($trafficSourceFilterParams)) {
            $bindTypes .= 'i';
            $bindValues = array_merge($bindValues, $trafficSourceFilterParams);
        }
        $buildTokenFilterForGroup = function($tokenValues, $selectedTokens) use ($db, $utcDateFrom, $utcDateTo) {
            $filterParts = [];
            $filterParams = [];
            foreach ($selectedTokens as $idx => $tokenParam) {
                $tokenValue = $tokenValues[$idx] ?? null;
                if ($tokenValue === null) {
                    continue;
                }
                // Include N/A - buildTokenFilter handles it for ad_name/adset_name (NULL/empty in DB)
                $filter = buildTokenFilter($tokenParam, $tokenValue, $db, $utcDateFrom, $utcDateTo);
                if ($filter) {
                    $filterParts[] = $filter[0];
                    $filterParams = array_merge($filterParams, $filter[1]);
                }
            }
            if (empty($filterParts)) {
                return [null, []];
            }
            return ["AND " . implode(" AND ", $filterParts), $filterParams];
        };

        // Unified parent cost via getAggregatedCost (target mode only — bounded: one query per offer/LP, not per token)
        $getUnifiedOfferCost = function(int $offerId, float $manualFallback = 0.0) use ($costAggregator, $utcDateFrom, $utcDateTo, $userTimezone, $selectedCampaignId, $selectedTrafficSourceId, $trafficSourceColumnExists, &$requestCostCache, $offerFbCostMap): float {
            $parts = [];
            $params = [];
            if ($selectedCampaignId) {
                $parts[] = 'cl.campaign_id = ?';
                $params[] = $selectedCampaignId;
            }
            $parts[] = 'cl.offer_id = ?';
            $params[] = $offerId;
            if ($selectedTrafficSourceId && $trafficSourceColumnExists) {
                $parts[] = 'cl.traffic_source_id = ?';
                $params[] = $selectedTrafficSourceId;
            }
            $filterStr = 'AND ' . implode(' AND ', $parts);
            $cacheKey = md5($utcDateFrom . '|' . $utcDateTo . '|' . $filterStr . '|' . json_encode($params) . '|offer-unified');
            if (!isset($requestCostCache[$cacheKey])) {
                try {
                    $requestCostCache[$cacheKey] = (float)$costAggregator->getAggregatedCost($utcDateFrom, $utcDateTo, $filterStr, $params, $userTimezone);
                } catch (Exception $e) {
                    $fb = $offerFbCostMap[$offerId] ?? 0.0;
                    $requestCostCache[$cacheKey] = $manualFallback + max(0.0, $fb);
                }
            }
            return (float)$requestCostCache[$cacheKey];
        };

        $getUnifiedLpCost = function(int $lpId, float $manualFallback = 0.0) use ($costAggregator, $utcDateFrom, $utcDateTo, $userTimezone, $selectedCampaignId, $selectedTrafficSourceId, $trafficSourceColumnExists, &$requestCostCache, $lpFbCostMap): float {
            $parts = [];
            $params = [];
            if ($selectedCampaignId) {
                $parts[] = 'cl.campaign_id = ?';
                $params[] = $selectedCampaignId;
            }
            if ($lpId > 0) {
                $parts[] = 'cl.landing_page_id = ?';
                $params[] = $lpId;
            } else {
                $parts[] = 'cl.landing_page_id IS NULL';
            }
            if ($selectedTrafficSourceId && $trafficSourceColumnExists) {
                $parts[] = 'cl.traffic_source_id = ?';
                $params[] = $selectedTrafficSourceId;
            }
            $filterStr = 'AND ' . implode(' AND ', $parts);
            $cacheKey = md5($utcDateFrom . '|' . $utcDateTo . '|' . $filterStr . '|' . json_encode($params) . '|lp-unified');
            if (!isset($requestCostCache[$cacheKey])) {
                try {
                    $requestCostCache[$cacheKey] = (float)$costAggregator->getAggregatedCost($utcDateFrom, $utcDateTo, $filterStr, $params, $userTimezone);
                } catch (Exception $e) {
                    $fb = $lpFbCostMap[$lpId] ?? 0.0;
                    $requestCostCache[$cacheKey] = $manualFallback + max(0.0, $fb);
                }
            }
            return (float)$requestCostCache[$cacheKey];
        };

        // Distribute a parent cost to token leaf rows by visitor share (no extra DB queries)
        $countVisitorsInTree = function($tree) use (&$countVisitorsInTree): int {
            if (!is_array($tree)) {
                return 0;
            }
            $hasNested = false;
            foreach ($tree as $v) {
                if (is_array($v)) {
                    $hasNested = true;
                    break;
                }
            }
            if (isset($tree['visitors']) && !$hasNested) {
                return (int)($tree['visitors'] ?? 0);
            }
            $sum = 0;
            foreach ($tree as $child) {
                if (is_array($child)) {
                    $sum += $countVisitorsInTree($child);
                }
            }
            return $sum;
        };

        $distributeProportionalCost = function(array &$tree, float $totalCost) use (&$distributeProportionalCost, &$countVisitorsInTree): void {
            if ($totalCost <= 0 || !is_array($tree)) {
                return;
            }
            $leaves = [];
            $branches = [];
            foreach ($tree as $key => &$child) {
                if (!is_array($child)) {
                    continue;
                }
                $hasNested = false;
                foreach ($child as $v) {
                    if (is_array($v)) {
                        $hasNested = true;
                        break;
                    }
                }
                if (isset($child['visitors']) && !$hasNested) {
                    $leaves[$key] = &$child;
                } else {
                    $branches[$key] = &$child;
                }
            }
            unset($child);

            if (!empty($leaves)) {
                $totalVisitors = 0;
                foreach ($leaves as $leaf) {
                    $totalVisitors += (int)($leaf['visitors'] ?? 0);
                }
                if ($totalVisitors > 0) {
                    foreach ($leaves as &$leaf) {
                        $leaf['cost'] = ((int)($leaf['visitors'] ?? 0) / $totalVisitors) * $totalCost;
                    }
                    unset($leaf);
                }
                return;
            }

            $totalVisitors = 0;
            $branchVisitorCounts = [];
            foreach ($branches as $key => &$branch) {
                $v = $countVisitorsInTree($branch);
                $branchVisitorCounts[$key] = $v;
                $totalVisitors += $v;
            }
            unset($branch);
            foreach ($branches as $key => &$branch) {
                $share = $totalVisitors > 0 ? ($branchVisitorCounts[$key] / $totalVisitors) * $totalCost : 0.0;
                $distributeProportionalCost($branch, $share);
            }
            unset($branch);
        };

        // Phase 5: One token → read from clicks_stats_by_token_daily when available (no raw clicks scan)
        $targetPerformanceFromAggregate = [];
        $aggregateOfferByToken = []; // token-first structure for $buildOfferTokenPerformance when using aggregate
        if (count($selectedTokens) === 1 && $selectedCampaignId) {
            $tokenTableCheck = $db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clicks_stats_by_token_daily' LIMIT 1");
            if ($tokenTableCheck && $tokenTableCheck->num_rows > 0) {
                $tokenParamOne = $selectedTokens[0];
                $summaryDateFromT = date('Y-m-d', strtotime($utcDateFrom));
                $summaryDateToT = date('Y-m-d', strtotime($utcDateTo));
                $aggStmt = $db->prepare("
                    SELECT token_value, SUM(visitors) as visitors, SUM(lp_clicks) as lp_clicks, SUM(conversions) as conversions, SUM(revenue) as revenue, SUM(cost) as cost
                    FROM clicks_stats_by_token_daily
                    WHERE campaign_id = ? AND summary_date >= ? AND summary_date <= ? AND token_param = ?
                    GROUP BY token_value
                    ORDER BY visitors DESC
                ");
                $aggStmt->bind_param('isss', $selectedCampaignId, $summaryDateFromT, $summaryDateToT, $tokenParamOne);
                $aggStmt->execute();
                $aggResult = $aggStmt->get_result();
                while ($row = $aggResult->fetch_assoc()) {
                    $targetPerformanceFromAggregate[] = $row;
                }
                $aggStmt->close();
            }
        }

        if (!empty($targetPerformanceFromAggregate)) {
            $targetPerformance = [];
            $targetOfferPerformance = [];
            $firstOfferId = null;
            if (!empty($offerPerformance)) {
                $firstOfferId = (int)($offerPerformance[0]['offer_id'] ?? 0);
            }
            foreach ($targetPerformanceFromAggregate as $r) {
                $tv = $r['token_value'] ?? 'N/A';
                $visitors = (int)($r['visitors'] ?? 0);
                $lpClicks = (int)($r['lp_clicks'] ?? 0);
                $conversions = (int)($r['conversions'] ?? 0);
                $revenue = (float)($r['revenue'] ?? 0);
                $cost = (float)($r['cost'] ?? 0);
                $profit = $revenue - $cost;
                $roi = $cost > 0 ? (($revenue - $cost) / $cost) * 100 : null;
                $lpCtr = $visitors > 0 ? ($lpClicks / $visitors) * 100 : 0;
                $cr = $visitors > 0 ? ($conversions / $visitors) * 100 : 0;
                $cpa = $conversions > 0 ? $cost / $conversions : null;
                $targetPerformance[] = [
                    'path' => [$tv],
                    'level' => 0,
                    'target_value' => $tv,
                    'token_value' => $tv,
                    'visitors' => $visitors,
                    'lp_clicks' => $lpClicks,
                    'conversions' => $conversions,
                    'revenue' => $revenue,
                    'cost' => $cost,
                    'manual_cost' => $cost,
                    'profit' => $profit,
                    'roi' => $roi,
                    'lp_ctr' => $lpCtr,
                    'cr' => $cr,
                    'cpa' => $cpa
                ];
                if ($firstOfferId !== null) {
                    $row = [
                        'offer_id' => $firstOfferId,
                        'visitors' => $visitors,
                        'lp_clicks' => $lpClicks,
                        'conversions' => $conversions,
                        'revenue' => $revenue,
                        'cost' => $cost,
                    ];
                    // Template expects $targetOfferPerformance[$offerId] for row totals
                    $targetOfferPerformance[$firstOfferId][$tv] = $row;
                    // Builder expects token-first: [token_value][offer_id] -> stats
                    $aggregateOfferByToken[$tv][$firstOfferId] = $row;
                }
            }
            $targetStats = [];
            $targetLPPerformance = [];
            // Always run aligned offer token query for offer breakdown so it matches the offer row (34 not 40/20/11 from aggregate)
            if (!empty($selectedTokens)) {
                $offerTokenSelectColumns = ["cl.offer_id"];
                $offerGroupByColumns = ["cl.offer_id"];
                foreach ($tokenSelectColumns as $tokenCol) {
                    $offerTokenSelectColumns[] = $tokenCol;
                }
                foreach ($groupByColumns as $groupCol) {
                    $offerGroupByColumns[] = $groupCol;
                }
                $offerTokenVisitorsExpr = "COUNT(DISTINCT CASE WHEN (" . ($trafficSourceColumnExists ? "cl.traffic_source_id = 4" : "c.traffic_source_id = 4") . ") THEN CASE WHEN cl.ad_id IS NOT NULL AND cl.adset_id IS NOT NULL THEN cl.id ELSE NULL END ELSE cl.id END)";
                $offerTokenLpClicksExpr = "COUNT(DISTINCT CASE WHEN cl.lp_click = 1 THEN CASE WHEN (" . ($trafficSourceColumnExists ? "cl.traffic_source_id = 4" : "c.traffic_source_id = 4") . ") THEN CASE WHEN cl.ad_id IS NOT NULL AND cl.adset_id IS NOT NULL THEN cl.id ELSE NULL END ELSE cl.id END ELSE NULL END)";
                $offerTokenConversionsExpr = "COUNT(DISTINCT CASE WHEN (" . ($trafficSourceColumnExists ? "cl.traffic_source_id = 4" : "c.traffic_source_id = 4") . ") THEN CASE WHEN cl.ad_id IS NOT NULL AND cl.adset_id IS NOT NULL THEN conv.id ELSE NULL END ELSE conv.id END)";
                $offerTokenStmt = $db->prepare("
                    SELECT " . implode(",\n        ", $offerTokenSelectColumns) . ",
                    {$offerTokenVisitorsExpr} as visitors,
                    {$offerTokenLpClicksExpr} as lp_clicks,
                    {$offerTokenConversionsExpr} as conversions,
                    SUM(COALESCE(conv.payout, conv.value)) as revenue,
                    SUM(cl.cost) as manual_cost
                    FROM {$clicksTable} cl
                    INNER JOIN offers o ON o.id = cl.offer_id
                    LEFT JOIN conversions conv ON cl.click_id = conv.click_id
                    {$joinClause}
                    WHERE {$dateFilter}
                    {$campaignFilter}
                    {$trafficSourceFilter}
                    {$offerInvalidClickExclusion}
                    GROUP BY " . implode(", ", $offerGroupByColumns) . "
                ");
                $offerTokenStmt->bind_param($bindTypes, ...$bindValues);
                $offerTokenStmt->execute();
                $offerTokenRes = $offerTokenStmt->get_result();
                $targetOfferPerformance = [];
                while ($offerRow = $offerTokenRes->fetch_assoc()) {
                    $offerId = (int)$offerRow['offer_id'];
                    $offerTokenValues = [];
                    foreach ($selectedTokens as $idx => $tokenParam) {
                        $columnAlias = "token{$idx}_value";
                        $offerTokenValues[] = $offerRow[$columnAlias] ?? 'N/A';
                    }
                    $current = &$targetOfferPerformance;
                    foreach ($offerTokenValues as $tokenValue) {
                        if (!isset($current[$tokenValue])) {
                            $current[$tokenValue] = [];
                        }
                        $current = &$current[$tokenValue];
                    }
                    if (!isset($current[$offerId])) {
                        $current[$offerId] = [
                            'offer_id' => $offerId,
                            'offer_name' => 'Unknown Offer',
                            'offer_url' => '',
                            'visitors' => 0,
                            'cost' => 0,
                            'manual_cost' => 0,
                            'lp_clicks' => 0,
                            'conversions' => 0,
                            'revenue' => 0
                        ];
                    }
                    $current[$offerId]['visitors'] = (int)($offerRow['visitors'] ?? 0);
                    $current[$offerId]['lp_clicks'] = (int)($offerRow['lp_clicks'] ?? 0);
                    $current[$offerId]['conversions'] = (int)($offerRow['conversions'] ?? 0);
                    $current[$offerId]['revenue'] = (float)($offerRow['revenue'] ?? 0);
                    $current[$offerId]['manual_cost'] = (float)($offerRow['manual_cost'] ?? 0);
                    $current[$offerId]['cost'] = 0;
                }
                $offerTokenStmt->close();
            }
            // LP token query: show token breakdown under each LP (same query as raw path, uses manual_cost to avoid slowdown)
            if (!empty($selectedTokens)) {
                $lpTokenSelectColumns = ["cl.landing_page_id"];
                $lpGroupByColumns = ["cl.landing_page_id"];
                foreach ($tokenSelectColumns as $tokenCol) {
                    $lpTokenSelectColumns[] = $tokenCol;
                }
                foreach ($groupByColumns as $groupCol) {
                    $lpGroupByColumns[] = $groupCol;
                }
                $lpPerformanceStmt = $db->prepare("
                    SELECT 
                        " . implode(",\n        ", $lpTokenSelectColumns) . ",
                        COUNT(DISTINCT cl.id) as visitors,
                        COUNT(DISTINCT CASE WHEN cl.lp_click = 1 THEN cl.id END) as lp_clicks,
                        COUNT(DISTINCT conv.id) as conversions,
                        SUM(COALESCE(conv.payout, conv.value)) as revenue,
                        SUM(cl.cost) as manual_cost
                    FROM {$clicksTable} cl
                    LEFT JOIN conversions conv ON cl.click_id = conv.click_id
                    {$joinClause}
                    WHERE {$dateFilter}
                    {$campaignFilter}
                    {$trafficSourceFilter}
                    AND cl.ad_id IS NOT NULL
                    AND cl.adset_id IS NOT NULL
                    AND cl.landing_page_id IS NOT NULL
                    GROUP BY " . implode(", ", $lpGroupByColumns) . "
                ");
                $lpPerformanceStmt->bind_param($bindTypes, ...$bindValues);
                $lpPerformanceStmt->execute();
                $lpPerformanceRes = $lpPerformanceStmt->get_result();
                while ($lpRow = $lpPerformanceRes->fetch_assoc()) {
                    $lpId = (int)$lpRow['landing_page_id'];
                    $lpTokenValues = [];
                    foreach ($selectedTokens as $idx => $tokenParam) {
                        $columnAlias = "token{$idx}_value";
                        $lpTokenValues[] = $lpRow[$columnAlias] ?? 'N/A';
                    }
                    $current = &$targetLPPerformance;
                    foreach ($lpTokenValues as $tokenValue) {
                        if (!isset($current[$tokenValue])) {
                            $current[$tokenValue] = [];
                        }
                        $current = &$current[$tokenValue];
                    }
                    if (!isset($current[$lpId])) {
                        $current[$lpId] = [
                            'lp_id' => $lpId,
                            'lp_name' => 'Unknown LP',
                            'lp_url' => '',
                            'visitors' => 0,
                            'cost' => 0,
                            'lp_clicks' => 0,
                            'conversions' => 0,
                            'revenue' => 0
                        ];
                    }
                    $current[$lpId]['visitors'] = (int)($lpRow['visitors'] ?? 0);
                    $current[$lpId]['lp_clicks'] = (int)($lpRow['lp_clicks'] ?? 0);
                    $current[$lpId]['conversions'] = (int)($lpRow['conversions'] ?? 0);
                    $current[$lpId]['revenue'] = (float)($lpRow['revenue'] ?? 0);
                    $current[$lpId]['cost'] = 0;
                }
                $lpPerformanceStmt->close();
                // Direct-link token breakdown (lp_id 0): when Split campaign, add token breakdown for direct-to-offer clicks
                if ($selectedCampaignId && $selectedCampaign && ($selectedCampaign['flow_type'] ?? '') === 'Split' && !$skipLp) {
                    $directLpTokenStmt = $db->prepare("
                        SELECT 
                            " . implode(",\n        ", $tokenSelectColumns) . ",
                            COUNT(DISTINCT cl.id) as visitors,
                            COUNT(DISTINCT CASE WHEN cl.lp_click = 1 THEN cl.id END) as lp_clicks,
                            COUNT(DISTINCT conv.id) as conversions,
                            SUM(COALESCE(conv.payout, conv.value)) as revenue,
                            SUM(cl.cost) as manual_cost
                        FROM {$clicksTable} cl
                        LEFT JOIN conversions conv ON cl.click_id = conv.click_id
                        {$joinClause}
                        WHERE {$dateFilter}
                        {$campaignFilter}
                        {$trafficSourceFilter}
                        AND cl.ad_id IS NOT NULL
                        AND cl.adset_id IS NOT NULL
                        AND cl.landing_page_id IS NULL
                        AND cl.lp_click = 1
                        GROUP BY " . implode(", ", $groupByColumns) . "
                    ");
                    $directLpTokenStmt->bind_param($bindTypes, ...$bindValues);
                    $directLpTokenStmt->execute();
                    $directLpTokenRes = $directLpTokenStmt->get_result();
                    while ($directRow = $directLpTokenRes->fetch_assoc()) {
                        $directTokenValues = [];
                        foreach ($selectedTokens as $idx => $tokenParam) {
                            $columnAlias = "token{$idx}_value";
                            $directTokenValues[] = $directRow[$columnAlias] ?? 'N/A';
                        }
                        $current = &$targetLPPerformance;
                        foreach ($directTokenValues as $tokenValue) {
                            if (!isset($current[$tokenValue])) {
                                $current[$tokenValue] = [];
                            }
                            $current = &$current[$tokenValue];
                        }
                        if (!isset($current[0])) {
                            $current[0] = [
                                'lp_id' => 0,
                                'lp_name' => 'DIRECT LINK (to offer)',
                                'lp_url' => '',
                                'visitors' => 0,
                                'cost' => 0,
                                'lp_clicks' => 0,
                                'conversions' => 0,
                                'revenue' => 0
                            ];
                        }
                        $current[0]['visitors'] = (int)($directRow['visitors'] ?? 0);
                        $current[0]['lp_clicks'] = (int)($directRow['lp_clicks'] ?? 0);
                        $current[0]['conversions'] = (int)($directRow['conversions'] ?? 0);
                        $current[0]['revenue'] = (float)($directRow['revenue'] ?? 0);
                        $current[0]['cost'] = 0;
                    }
                    $directLpTokenStmt->close();
                }
            }
        } else {
        
        // Build SELECT with aggregations
        $selectColumns = implode(",\n        ", $tokenSelectColumns);
        
        // Add aggregated stats
        $selectColumns .= ",
        COUNT(DISTINCT cl.id) as visitors,
        COUNT(DISTINCT CASE WHEN cl.lp_click = 1 THEN cl.id END) as lp_clicks,
        COUNT(DISTINCT conv.id) as conversions,
        SUM(COALESCE(conv.payout, conv.value)) as revenue,
        SUM(cl.cost) as manual_cost";
        
        // Add traffic_source_name to SELECT if we're joining traffic_sources (for display)
        if ($needsTrafficSourceJoin) {
            $selectColumns .= ",\n        COALESCE(ts.name, 'Unknown') as traffic_source_name";
        }
        
        // Build GROUP BY clause
        $groupByClause = !empty($groupByColumns) ? "GROUP BY " . implode(", ", $groupByColumns) : "";
        
        // If we're joining traffic_sources and need the name in GROUP BY, add it
        if ($needsTrafficSourceJoin && !empty($groupByColumns)) {
            // Check if traffic_source_name is already in groupByColumns
            $hasTrafficSourceInGroupBy = false;
            foreach ($groupByColumns as $col) {
                if (strpos($col, 'ts.name') !== false || strpos($col, 'traffic_source') !== false) {
                    $hasTrafficSourceInGroupBy = true;
                    break;
                }
            }
            if (!$hasTrafficSourceInGroupBy) {
                $groupByClause .= ", COALESCE(ts.name, 'Unknown')";
            }
        }
        
        $targetQuery = $db->prepare("
            SELECT 
                {$selectColumns}
            FROM {$clicksTable} cl
            LEFT JOIN conversions conv ON cl.click_id = conv.click_id
            {$joinClause}
            WHERE {$dateFilter}
            {$campaignFilter}
            {$trafficSourceFilter}
            AND cl.ad_id IS NOT NULL
            AND cl.adset_id IS NOT NULL
            -- PERFORMANCE: Use generated columns (ad_id, adset_id) instead of JSON_EXTRACT for index usage
            {$groupByClause}
        ");
        
        // Set timeout protection for heavy queries (plan 2.3: 120s for long ranges)
        $targetTimeoutSec = 90;
        $daysInRange = (strtotime($dateTo) - strtotime($dateFrom)) / 86400;
        if ($daysInRange > 7) {
            $targetTimeoutSec = 120;
        }
        set_time_limit($targetTimeoutSec);
        $db->query("SET SESSION max_execution_time = " . ((int)$targetTimeoutSec * 1000));
        
        try {
            $targetQuery->bind_param($bindTypes, ...$bindValues);
            $targetQuery->execute();
            $targetResult = $targetQuery->get_result();
        } catch (mysqli_sql_exception $e) {
            if (strpos($e->getMessage(), 'timeout') !== false || 
                strpos($e->getMessage(), 'exceeded') !== false) {
                // Return error response for AJAX
                if ($isDataRequest) {
                    http_response_code(504);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'success' => false,
                        'error' => 'Query timeout: Date range too large. Please try a shorter date range (7 days or less).'
                    ]);
                    exit;
                }
                // For regular page load, set error message
                $errorMessage = "Query timeout: Date range too large. Please try a shorter date range (7 days or less).";
                error_log("Stats view query timeout: " . $e->getMessage());
                // Continue with empty result set
                $targetResult = null;
            } else {
                throw $e; // Re-throw if not a timeout error
            }
        }
        
        if (!$targetResult) {
            // Handle timeout case - return empty stats
            $targetStats = [];
        } else {
            // PERFORMANCE: Process aggregated results from SQL GROUP BY
            // Each row represents one unique token combination with pre-aggregated stats
            $targetStats = []; // Nested array: [token1][token2][token3] => stats
        
        // CRITICAL FIX: Batch cost calculation to avoid N+1 query problem
        // Instead of calling getAggregatedCost() for each row (50-100+ queries),
        // collect all token combinations first, then calculate costs in batch
        $rowsByTokenKey = [];
        $tokenCombinationMap = [];
        
        // First pass: Collect all rows grouped by token combinations
        while ($row = $targetResult->fetch_assoc()) {
            // Extract token values from aggregated row
            $tokenValues = [];
            foreach ($selectedTokens as $idx => $tokenParam) {
                $columnAlias = "token{$idx}_value";
                $tokenValues[] = $row[$columnAlias] ?? 'N/A';
            }
            
            // Build unique key for this token combination
            $tokenKey = md5(json_encode($tokenValues));
            if (!isset($rowsByTokenKey[$tokenKey])) {
                $rowsByTokenKey[$tokenKey] = [];
                $tokenCombinationMap[$tokenKey] = $tokenValues;
            }
            $rowsByTokenKey[$tokenKey][] = $row;
        }
        
        // Step 2: Batch calculate costs — only for top N combos by visitors to keep response fast (LCP)
        // Remaining rows use manual_cost so the page can paint in ~1–2s instead of 5–7s
        $costCache = []; // Maps filter key => cost
        $costsByTokenKey = [];
        $maxCostCalculations = 25; // Cap getAggregatedCost calls; rest use manual_cost
        $tokenKeysByVisitors = [];
        foreach ($rowsByTokenKey as $tk => $rows) {
            $visitors = 0;
            foreach ($rows as $r) {
                $visitors += (int)($r['visitors'] ?? 0);
            }
            $tokenKeysByVisitors[] = ['key' => $tk, 'visitors' => $visitors];
        }
        usort($tokenKeysByVisitors, function ($a, $b) { return $b['visitors'] - $a['visitors']; });
        $topKeys = array_flip(array_slice(array_column($tokenKeysByVisitors, 'key'), 0, $maxCostCalculations));
        
        foreach ($tokenCombinationMap as $tokenKey => $tokenValues) {
            list($tokenFilter, $tokenFilterParams) = $buildTokenFilterForGroup($tokenValues, $selectedTokens);
            
            if ($tokenFilter && isset($topKeys[$tokenKey])) {
                $filterCacheKey = md5($tokenFilter . json_encode($tokenFilterParams));
                $requestCostKey = md5($utcDateFrom . '|' . $utcDateTo . '|' . $tokenFilter . '|' . json_encode($tokenFilterParams));
                if (!isset($costCache[$filterCacheKey])) {
                    if (isset($requestCostCache[$requestCostKey])) {
                        $costCache[$filterCacheKey] = $requestCostCache[$requestCostKey];
                    } else {
                        try {
                            $totalCost = $costAggregator->getAggregatedCost($utcDateFrom, $utcDateTo, $tokenFilter, $tokenFilterParams, $userTimezone);
                            $costCache[$filterCacheKey] = $totalCost;
                            $requestCostCache[$requestCostKey] = $totalCost;
                        } catch (Exception $e) {
                            error_log("Error calculating cost for token group: " . $e->getMessage());
                            $costCache[$filterCacheKey] = null;
                        }
                    }
                }
                $costsByTokenKey[$tokenKey] = $costCache[$filterCacheKey];
            } else {
                $costsByTokenKey[$tokenKey] = null; // use manual_cost fallback
            }
        }
        
        // Step 3: Process rows with pre-calculated costs
        foreach ($rowsByTokenKey as $tokenKey => $rows) {
            $tokenValues = $tokenCombinationMap[$tokenKey];
            $totalCost = $costsByTokenKey[$tokenKey];
            
            // Build nested array path
            $current = &$targetStats;
            foreach ($tokenValues as $idx => $tokenValue) {
                if (!isset($current[$tokenValue])) {
                    $current[$tokenValue] = ($idx < count($tokenValues) - 1) ? [] : [
                        'visitors' => 0,
                        'cost' => 0,
                        'lp_clicks' => 0,
                        'conversions' => 0,
                        'revenue' => 0
                    ];
                }
                if ($idx < count($tokenValues) - 1) {
                    $current = &$current[$tokenValue];
                } else {
                    // Last level - aggregate stats from all rows for this token combination
                    foreach ($rows as $row) {
                        $current[$tokenValue]['visitors'] += (int)($row['visitors'] ?? 0);
                        $current[$tokenValue]['lp_clicks'] += (int)($row['lp_clicks'] ?? 0);
                        $current[$tokenValue]['conversions'] += (int)($row['conversions'] ?? 0);
                        $current[$tokenValue]['revenue'] += (float)($row['revenue'] ?? 0);
                    }
                    
                    // Use pre-calculated cost or fallback to manual_cost
                    if ($totalCost !== null) {
                        $current[$tokenValue]['cost'] = $totalCost;
                    } else {
                        // Fallback: sum manual_cost from all rows for this token combination
                        $manualCostSum = 0;
                        foreach ($rows as $row) {
                            $manualCostSum += (float)($row['manual_cost'] ?? 0);
                        }
                        $current[$tokenValue]['cost'] = $manualCostSum;
                    }
                }
            }
        }
        // Plan 2.3: Free large temporaries after targetStats is built
        unset($rowsByTokenKey, $tokenCombinationMap, $costsByTokenKey);
        } // End if ($targetResult) - close the else block from timeout protection
        
        // PERFORMANCE: Query LP performance using SQL GROUP BY
        // Group by landing_page_id + token columns
        if (!empty($selectedTokens)) {
            $lpTokenSelectColumns = ["cl.landing_page_id"];
            $lpGroupByColumns = ["cl.landing_page_id"];
            
            // Add token columns to SELECT and GROUP BY
            foreach ($tokenSelectColumns as $tokenCol) {
                $lpTokenSelectColumns[] = $tokenCol;
            }
            foreach ($groupByColumns as $groupCol) {
                $lpGroupByColumns[] = $groupCol;
            }
            
            $lpPerformanceQuery = $db->prepare("
                SELECT 
                    " . implode(",\n        ", $lpTokenSelectColumns) . ",
                    COUNT(DISTINCT cl.id) as visitors,
                    COUNT(DISTINCT CASE WHEN cl.lp_click = 1 THEN cl.id END) as lp_clicks,
                    COUNT(DISTINCT conv.id) as conversions,
                    SUM(COALESCE(conv.payout, conv.value)) as revenue,
                    SUM(cl.cost) as manual_cost
                FROM {$clicksTable} cl
                LEFT JOIN conversions conv ON cl.click_id = conv.click_id
                {$joinClause}
                WHERE {$dateFilter}
                {$campaignFilter}
                {$trafficSourceFilter}
                AND cl.ad_id IS NOT NULL
                AND cl.adset_id IS NOT NULL
                AND cl.landing_page_id IS NOT NULL
                GROUP BY " . implode(", ", $lpGroupByColumns) . "
            ");
            
            $lpPerformanceQuery->bind_param($bindTypes, ...$bindValues);
            $lpPerformanceQuery->execute();
            $lpPerformanceResult = $lpPerformanceQuery->get_result();
            
            while ($lpRow = $lpPerformanceResult->fetch_assoc()) {
                $lpId = (int)$lpRow['landing_page_id'];
                $lpTokenValues = [];
                foreach ($selectedTokens as $idx => $tokenParam) {
                    $columnAlias = "token{$idx}_value";
                    $lpTokenValues[] = $lpRow[$columnAlias] ?? 'N/A';
                }
                
                // Build nested structure
                $current = &$targetLPPerformance;
                foreach ($lpTokenValues as $tokenValue) {
                    if (!isset($current[$tokenValue])) {
                        $current[$tokenValue] = [];
                    }
                    $current = &$current[$tokenValue];
                }
                
                if (!isset($current[$lpId])) {
                    $current[$lpId] = [
                        'lp_id' => $lpId,
                        'lp_name' => 'Unknown LP',
                        'lp_url' => '',
                        'visitors' => 0,
                        'cost' => 0,
                        'lp_clicks' => 0,
                        'conversions' => 0,
                        'revenue' => 0
                    ];
                }
                
                $current[$lpId]['visitors'] = (int)($lpRow['visitors'] ?? 0);
                $current[$lpId]['lp_clicks'] = (int)($lpRow['lp_clicks'] ?? 0);
                $current[$lpId]['conversions'] = (int)($lpRow['conversions'] ?? 0);
                $current[$lpId]['revenue'] = (float)($lpRow['revenue'] ?? 0);
                $current[$lpId]['cost'] = 0;
            }
            $lpPerformanceQuery->close();
            // Direct-link token breakdown (lp_id 0): when Split campaign, add token breakdown for direct-to-offer clicks
            if ($selectedCampaignId && $selectedCampaign && ($selectedCampaign['flow_type'] ?? '') === 'Split' && !$skipLp) {
                $directLpTokenQuery = $db->prepare("
                    SELECT 
                        " . implode(",\n        ", $tokenSelectColumns) . ",
                        COUNT(DISTINCT cl.id) as visitors,
                        COUNT(DISTINCT CASE WHEN cl.lp_click = 1 THEN cl.id END) as lp_clicks,
                        COUNT(DISTINCT conv.id) as conversions,
                        SUM(COALESCE(conv.payout, conv.value)) as revenue,
                        SUM(cl.cost) as manual_cost
                    FROM {$clicksTable} cl
                    LEFT JOIN conversions conv ON cl.click_id = conv.click_id
                    {$joinClause}
                    WHERE {$dateFilter}
                    {$campaignFilter}
                    {$trafficSourceFilter}
                    AND cl.ad_id IS NOT NULL
                    AND cl.adset_id IS NOT NULL
                    AND cl.landing_page_id IS NULL
                    AND cl.lp_click = 1
                    GROUP BY " . implode(", ", $groupByColumns) . "
                ");
                $directLpTokenQuery->bind_param($bindTypes, ...$bindValues);
                $directLpTokenQuery->execute();
                $directLpTokenResult = $directLpTokenQuery->get_result();
                while ($directRow = $directLpTokenResult->fetch_assoc()) {
                    $directTokenValues = [];
                    foreach ($selectedTokens as $idx => $tokenParam) {
                        $columnAlias = "token{$idx}_value";
                        $directTokenValues[] = $directRow[$columnAlias] ?? 'N/A';
                    }
                    $current = &$targetLPPerformance;
                    foreach ($directTokenValues as $tokenValue) {
                        if (!isset($current[$tokenValue])) {
                            $current[$tokenValue] = [];
                        }
                        $current = &$current[$tokenValue];
                    }
                    if (!isset($current[0])) {
                        $current[0] = [
                            'lp_id' => 0,
                            'lp_name' => 'DIRECT LINK (to offer)',
                            'lp_url' => '',
                            'visitors' => 0,
                            'cost' => 0,
                            'lp_clicks' => 0,
                            'conversions' => 0,
                            'revenue' => 0
                        ];
                    }
                    $current[0]['visitors'] = (int)($directRow['visitors'] ?? 0);
                    $current[0]['lp_clicks'] = (int)($directRow['lp_clicks'] ?? 0);
                    $current[0]['conversions'] = (int)($directRow['conversions'] ?? 0);
                    $current[0]['revenue'] = (float)($directRow['revenue'] ?? 0);
                    $current[0]['cost'] = 0;
                }
                $directLpTokenQuery->close();
            }
        }
        
        // PERFORMANCE: Query Offer performance using SQL GROUP BY
        // Group by offer_id + token columns
        if (!empty($selectedTokens)) {
            $offerTokenSelectColumns = ["cl.offer_id"];
            $offerGroupByColumns = ["cl.offer_id"];
            
            // Add token columns to SELECT and GROUP BY
            foreach ($tokenSelectColumns as $tokenCol) {
                $offerTokenSelectColumns[] = $tokenCol;
            }
            foreach ($groupByColumns as $groupCol) {
                $offerGroupByColumns[] = $groupCol;
            }
            
            // Align with main offer query: same FROM (only clicks whose offer exists), same WHERE ($offerInvalidClickExclusion), same COUNT(CASE) for visitors/lp_clicks/conversions
            $offerTokenVisitorsExpr = "COUNT(DISTINCT CASE WHEN (" . ($trafficSourceColumnExists ? "cl.traffic_source_id = 4" : "c.traffic_source_id = 4") . ") THEN CASE WHEN cl.ad_id IS NOT NULL AND cl.adset_id IS NOT NULL THEN cl.id ELSE NULL END ELSE cl.id END)";
            $offerTokenLpClicksExpr = "COUNT(DISTINCT CASE WHEN cl.lp_click = 1 THEN CASE WHEN (" . ($trafficSourceColumnExists ? "cl.traffic_source_id = 4" : "c.traffic_source_id = 4") . ") THEN CASE WHEN cl.ad_id IS NOT NULL AND cl.adset_id IS NOT NULL THEN cl.id ELSE NULL END ELSE cl.id END ELSE NULL END)";
            $offerTokenConversionsExpr = "COUNT(DISTINCT CASE WHEN (" . ($trafficSourceColumnExists ? "cl.traffic_source_id = 4" : "c.traffic_source_id = 4") . ") THEN CASE WHEN cl.ad_id IS NOT NULL AND cl.adset_id IS NOT NULL THEN conv.id ELSE NULL END ELSE conv.id END)";
            $offerPerformanceQuery = $db->prepare("
                SELECT 
                    " . implode(",\n        ", $offerTokenSelectColumns) . ",
                    {$offerTokenVisitorsExpr} as visitors,
                    {$offerTokenLpClicksExpr} as lp_clicks,
                    {$offerTokenConversionsExpr} as conversions,
                    SUM(COALESCE(conv.payout, conv.value)) as revenue,
                    SUM(cl.cost) as manual_cost
                FROM {$clicksTable} cl
                INNER JOIN offers o ON o.id = cl.offer_id
                LEFT JOIN conversions conv ON cl.click_id = conv.click_id
                {$joinClause}
                WHERE {$dateFilter}
                {$campaignFilter}
                {$trafficSourceFilter}
                {$offerInvalidClickExclusion}
                GROUP BY " . implode(", ", $offerGroupByColumns) . "
            ");
            
            $offerPerformanceQuery->bind_param($bindTypes, ...$bindValues);
            $offerPerformanceQuery->execute();
            $offerPerformanceResult = $offerPerformanceQuery->get_result();
            
            while ($offerRow = $offerPerformanceResult->fetch_assoc()) {
                $offerId = (int)$offerRow['offer_id'];
                $offerTokenValues = [];
                foreach ($selectedTokens as $idx => $tokenParam) {
                    $columnAlias = "token{$idx}_value";
                    $offerTokenValues[] = $offerRow[$columnAlias] ?? 'N/A';
                }
                
                // Build nested structure
                $current = &$targetOfferPerformance;
                foreach ($offerTokenValues as $tokenValue) {
                    if (!isset($current[$tokenValue])) {
                        $current[$tokenValue] = [];
                    }
                    $current = &$current[$tokenValue];
                }
                
                if (!isset($current[$offerId])) {
                    $current[$offerId] = [
                        'offer_id' => $offerId,
                        'offer_name' => 'Unknown Offer',
                        'offer_url' => '',
                        'visitors' => 0,
                        'cost' => 0,
                        'manual_cost' => 0,
                        'lp_clicks' => 0,
                        'conversions' => 0,
                        'revenue' => 0
                    ];
                }
                
                $current[$offerId]['visitors'] = (int)($offerRow['visitors'] ?? 0);
                $current[$offerId]['lp_clicks'] = (int)($offerRow['lp_clicks'] ?? 0);
                $current[$offerId]['conversions'] = (int)($offerRow['conversions'] ?? 0);
                $current[$offerId]['revenue'] = (float)($offerRow['revenue'] ?? 0);
                $current[$offerId]['manual_cost'] = (float)($offerRow['manual_cost'] ?? 0);
                $current[$offerId]['cost'] = 0;
            }
        }
        
        // Flatten nested structure for display (recursive function)
        $flattenTargetStats = function($stats, $level = 0, $path = []) use (&$flattenTargetStats, $selectedTokens) {
            $result = [];
            foreach ($stats as $tokenValue => $data) {
                $currentPath = array_merge($path, [$tokenValue]);
                if (isset($data['visitors'])) {
                    // Leaf node - calculate metrics
                    $visitors = $data['visitors'];
                    $cost = $data['cost'];
                    $lpClicks = $data['lp_clicks'];
                    $conversions = $data['conversions'];
                    $revenue = $data['revenue'];
                    
                    $lpCtr = $visitors > 0 ? ($lpClicks / $visitors) * 100 : 0;
                    $cr = $lpClicks > 0 ? ($conversions / $lpClicks) * 100 : 0;
                    $cpa = $conversions > 0 ? $cost / $conversions : 0;
                    $profit = $revenue - $cost;
                    $roi = $cost > 0 ? (($profit / $cost) * 100) : 0;
                    
                    $result[] = [
                        'path' => $currentPath,
                        'level' => $level,
                        'target_value' => $tokenValue,
                        'visitors' => $visitors,
                        'cost' => $cost,
                        'lp_clicks' => $lpClicks,
                        'lp_ctr' => $lpCtr,
                        'conversions' => $conversions,
                        'cr' => $cr,
                        'cpa' => $cpa,
                        'revenue' => $revenue,
                        'profit' => $profit,
                        'roi' => $roi
                    ];
                } else {
                    // Intermediate node - recurse
                    $result = array_merge($result, $flattenTargetStats($data, $level + 1, $currentPath));
                }
            }
            return $result;
        };
        
        $targetPerformance = $flattenTargetStats($targetStats);
        
        } // end else (raw clicks target query)

        // Sort by visitors descending
        usort($targetPerformance, function($a, $b) {
            return $b['visitors'] <=> $a['visitors'];
        });
        
        // Get LP names for target LP performance
        if (!empty($targetLPPerformance)) {
            $lpIds = [];
            $collectLpIds = function($data) use (&$collectLpIds, &$lpIds) {
                foreach ($data as $key => $value) {
                    if (isset($value['lp_id'])) {
                        $lpIds[] = (int)$value['lp_id'];
                    } elseif (is_array($value)) {
                        $collectLpIds($value);
                    }
                }
            };
            $collectLpIds($targetLPPerformance);
            
            if (!empty($lpIds)) {
                $lpIdList = implode(',', array_unique($lpIds));
                $lpQuery = $db->query("SELECT id, name, url FROM landing_pages WHERE id IN ($lpIdList)");
                $lpNames = [];
                while ($lp = $lpQuery->fetch_assoc()) {
                    $lpNames[(int)$lp['id']] = ['name' => $lp['name'], 'url' => $lp['url']];
                }
                
                $updateLpNames = function(&$data) use (&$updateLpNames, $lpNames) {
                    foreach ($data as $key => &$value) {
                        if (isset($value['lp_id']) && isset($lpNames[(int)$value['lp_id']])) {
                            $value['lp_name'] = $lpNames[(int)$value['lp_id']]['name'];
                            $value['lp_url'] = $lpNames[(int)$value['lp_id']]['url'];
                        } elseif (is_array($value)) {
                            $updateLpNames($value);
                        }
                    }
                };
                $updateLpNames($targetLPPerformance);
            }
        }
        
        // Get Offer names for target Offer performance
        if (!empty($targetOfferPerformance)) {
            $offerIds = [];
            $collectOfferIds = function($data) use (&$collectOfferIds, &$offerIds) {
                foreach ($data as $key => $value) {
                    if (isset($value['offer_id'])) {
                        $offerIds[] = (int)$value['offer_id'];
                    } elseif (is_array($value)) {
                        $collectOfferIds($value);
                    }
                }
            };
            $collectOfferIds($targetOfferPerformance);
            
            if (!empty($offerIds)) {
                $offerIdList = implode(',', array_unique($offerIds));
                $offerQuery = $db->query("SELECT id, name, url FROM offers WHERE id IN ($offerIdList)");
                $offerNames = [];
                while ($off = $offerQuery->fetch_assoc()) {
                    $offerNames[(int)$off['id']] = ['name' => $off['name'], 'url' => $off['url']];
                }
                
                $updateOfferNames = function(&$data) use (&$updateOfferNames, $offerNames) {
                    foreach ($data as $key => &$value) {
                        if (isset($value['offer_id']) && isset($offerNames[(int)$value['offer_id']])) {
                            $value['offer_name'] = $offerNames[(int)$value['offer_id']]['name'];
                            $value['offer_url'] = $offerNames[(int)$value['offer_id']]['url'];
                        } elseif (is_array($value)) {
                            $updateOfferNames($value);
                        }
                    }
                };
                $updateOfferNames($targetOfferPerformance);
            }
        }
        
        // Reorganize target performance data by LP/Offer for nested display
        // Build lookup maps: $lpTokenPerformance[$lpId][traffic_source?][token1][token2][token3] and $offerTokenPerformance[$offerId][traffic_source?][token1][token2][token3]
        // Note: If multiple traffic sources detected, data structure is [traffic_source][token1][token2][token3]
        $lpTokenPerformance = [];
        $offerTokenPerformance = [];
        
        $buildLpTokenPerformance = function($lpData, $tokenPath = []) use (&$buildLpTokenPerformance, &$lpTokenPerformance, $hasMultipleTrafficSources) {
            foreach ($lpData as $key => $value) {
                if (isset($value['lp_id'])) {
                    // Leaf node - LP stats
                    $lpId = $value['lp_id'];
                    if (!isset($lpTokenPerformance[$lpId])) {
                        $lpTokenPerformance[$lpId] = [];
                    }
                    $current = &$lpTokenPerformance[$lpId];
                    foreach ($tokenPath as $tokenValue) {
                        if (!isset($current[$tokenValue])) {
                            $current[$tokenValue] = [];
                        }
                        $current = &$current[$tokenValue];
                    }
                    $current = $value;
                } elseif (is_array($value)) {
                    // Intermediate node - recurse
                    // Keep traffic source level in path if multiple traffic sources detected
                    $buildLpTokenPerformance($value, array_merge($tokenPath, [$key]));
                }
            }
        };
        
        $buildOfferTokenPerformance = function($offerData, $tokenPath = []) use (&$buildOfferTokenPerformance, &$offerTokenPerformance, $hasMultipleTrafficSources) {
            foreach ($offerData as $key => $value) {
                if (isset($value['offer_id'])) {
                    // Leaf node - Offer stats
                    $offerId = $value['offer_id'];
                    if (!isset($offerTokenPerformance[$offerId])) {
                        $offerTokenPerformance[$offerId] = [];
                    }
                    $current = &$offerTokenPerformance[$offerId];
                    foreach ($tokenPath as $tokenValue) {
                        if (!isset($current[$tokenValue])) {
                            $current[$tokenValue] = [];
                        }
                        $current = &$current[$tokenValue];
                    }
                    $current = $value;
                } elseif (is_array($value)) {
                    // Intermediate node - recurse
                    // Keep traffic source level in path if multiple traffic sources detected
                    $buildOfferTokenPerformance($value, array_merge($tokenPath, [$key]));
                }
            }
        };
        
        if (!empty($targetLPPerformance)) {
            $buildLpTokenPerformance($targetLPPerformance);
        }
        
        // Prefer aligned offer breakdown (from offer token query) so breakdown sums match offer row; fallback to aggregate when no tokens
        $offerDataForBuild = !empty($targetOfferPerformance) ? $targetOfferPerformance : $aggregateOfferByToken;
        if (!empty($offerDataForBuild)) {
            $buildOfferTokenPerformance($offerDataForBuild);
        }

        // Resolve unified parent costs (one getAggregatedCost per offer/LP) and distribute to token rows by visitors
        foreach ($offerPerformance as &$offerRow) {
            $oid = (int)($offerRow['offer_id'] ?? 0);
            $manualCost = (float)($offerRow['manual_cost'] ?? 0);
            $unifiedCost = $getUnifiedOfferCost($oid, $manualCost);
            $offerRow['cost'] = $unifiedCost;
            if (!empty($offerTokenPerformance[$oid])) {
                $distributeProportionalCost($offerTokenPerformance[$oid], $unifiedCost);
            }
        }
        unset($offerRow);
        foreach ($landingPagePerformance as &$lpRow) {
            $lpIdSync = (int)($lpRow['lp_id'] ?? 0);
            $manualCost = (float)($lpRow['manual_cost'] ?? 0);
            $unifiedCost = $getUnifiedLpCost($lpIdSync, $manualCost);
            $lpRow['cost'] = $unifiedCost;
            if (!empty($lpTokenPerformance[$lpIdSync])) {
                $distributeProportionalCost($lpTokenPerformance[$lpIdSync], $unifiedCost);
            }
        }
        unset($lpRow);
    }
    
    // If this is an AJAX request, return JSON and cache it in session
    if ($isDataRequest) {
        if ($viewMode === 'target' && !empty($selectedTokens) && $timingSegmentStart !== null) {
            $timing['target_ms'] = round((microtime(true) - $timingSegmentStart) * 1000, 2);
        }
        $timing['total_ms'] = round((microtime(true) - $timing['_start']) * 1000, 2);
        unset($timing['_start']);
        $responseData = [
            'success' => true,
            'timing' => $timing,
            'targetPerformance' => $targetPerformance,
            'lpPerformance' => $targetLPPerformance,
            'offerPerformance' => $targetOfferPerformance,
            'lpTokenPerformance' => $lpTokenPerformance,
            'offerTokenPerformance' => $offerTokenPerformance,
            'selectedTokens' => $selectedTokens,
            'selectedTokenNames' => $selectedTokenNames ?? [],
            'summary' => $summary ?? null,
            'campaignPerformance' => $campaignPerformance ?? [],
            'landingPagePerformance' => $landingPagePerformance ?? [],
            'lpOfferBreakdown' => $lpOfferBreakdown ?? [],
            'offerPerformance' => $offerPerformance ?? [],
            // Include chart data in cache
            'chartLabels' => $chartLabels ?? [],
            'chartVisitors' => $chartVisitors ?? [],
            'chartClicks' => $chartClicks ?? [],
            'chartConversions' => $chartConversions ?? [],
            'chartRevenue' => $chartRevenue ?? [],
            'chartCost' => $chartCost ?? []
        ];
        
        // Cache in session to avoid recalculation on page reload
        // Include cache key to validate request parameters match
        $_SESSION['stats_data_cache'] = $responseData;
        $_SESSION['stats_data_cache_key'] = $currentCacheKey;
        $_SESSION['stats_data_timestamp'] = time();
        
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($responseData);
        exit;
    }

?>

<style>
/* Earthy green theme for stats page - matching Kuma style */
.campaign-stats-page {
    background: #f5f1e8;
    min-height: 100vh;
    padding: 20px;
    color: #2c3e2d;
}

.stats-filter-panel {
    background: #ffffff;
    border-radius: 8px;
    padding: 16px 20px;
    margin-bottom: 20px;
    border: 1px solid #d4d4d4;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.stats-table-container {
    background: #ffffff;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
    border: 1px solid #d4d4d4;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

/* Loading spinner */
.stats-loading-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 300px;
    padding: 40px;
}

.stats-loading-spinner {
    width: 50px;
    height: 50px;
    border: 4px solid #f3f3f3;
    border-top: 4px solid #3d5a26;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin-bottom: 20px;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.stats-loading-text {
    color: #666;
    font-size: 16px;
    font-weight: 500;
}

.stats-data-container {
    display: none;
}

.stats-data-container.loaded {
    display: block;
}

.stats-table {
    width: 100%;
    border-collapse: collapse;
    color: #2c3e2d;
}

.stats-table th {
    background: linear-gradient(135deg, #3d5a26 0%, #5a7a3a 100%);
    color: #ffffff;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    border-bottom: 2px solid #3d5a26;
}

.stats-table .sortable-col {
    cursor: pointer;
    user-select: none;
}

.stats-table .sort-indicator {
    margin-left: 4px;
    opacity: 0.9;
    font-size: 0.85em;
}

.stats-table td {
    padding: 12px;
    border-bottom: 1px solid #e8e8e8;
}

.stats-table tbody tr {
    transition: all 0.2s ease;
}

.stats-table tbody tr:hover {
    background: #f0f7ed !important;
    box-shadow: 0 0 12px rgba(61, 90, 38, 0.15), inset 0 0 0 1px rgba(61, 90, 38, 0.1);
    transform: translateY(-1px);
    cursor: pointer;
}

.stats-filter-panel select,
.stats-filter-panel input[type="date"],
.stats-filter-panel input[type="text"] {
    background: #ffffff;
    border: 1.5px solid #d4d4d4;
    border-radius: 6px;
    padding: 10px 14px;
    color: #2c3e2d;
    width: 100%;
    font-size: 14px;
    transition: all 0.2s;
    height: 40px;
    box-sizing: border-box;
}

.stats-filter-panel select:hover,
.stats-filter-panel input[type="date"]:hover {
    border-color: #9ccc65;
}

.stats-filter-panel select:focus,
.stats-filter-panel input[type="date"]:focus,
.stats-filter-panel input[type="text"]:focus {
    outline: none;
    border-color: #4caf50;
    box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
}

.stats-filter-panel select:disabled {
    background-color: #f5f5f5;
    color: #999;
    cursor: not-allowed;
    opacity: 0.7;
}

.stats-filter-panel label {
    display: block;
    margin-bottom: 6px;
    font-weight: 600;
    color: #3d5a26;
    font-size: 11px;
    line-height: 1.3;
    letter-spacing: 0.3px;
    text-transform: uppercase;
}

.filter-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    margin-bottom: 0;
}

/* Streamlined horizontal filter layout */
.filter-compact-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 12px;
}

/* Filter row - horizontal layout */
.filter-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 16px;
    align-items: end;
}

/* Filter field wrapper */
.filter-field {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.filter-field label {
    font-size: 11px;
    font-weight: 600;
    color: #3d5a26;
    margin: 0;
    line-height: 1.2;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}

/* Campaign field - special styling, prominent */
.campaign-field {
    grid-column: 1 / -1;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.campaign-select-row {
    display: flex;
    gap: 10px;
    align-items: center;
}

.campaign-select-row > select {
    flex: 1;
    min-width: 300px;
}

.campaign-edit-btn {
    flex-shrink: 0;
    width: 44px;
    height: 40px;
    padding: 0;
}

/* Date fields - side by side */
.date-field-group {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

/* Status and Chart - side by side */
.status-chart-group {
    display: grid;
    grid-template-columns: 140px 1fr;
    gap: 16px;
}

/* View mode row - 50/50 split */
.view-mode-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

/* Token row for Token 2 & Token 3 - 50/50 split with padding */
.token-row-2-3 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-top: 16px;
}

/* Edit button styling */
.campaign-edit-btn {
    flex-shrink: 0;
    padding: 0;
    background: #3d5a26;
    border-radius: 6px;
    color: #ffffff;
    text-decoration: none;
    font-size: 14px;
    white-space: nowrap;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    height: 40px;
    width: 48px;
    box-shadow: 0 2px 4px rgba(61, 90, 38, 0.15);
    margin: 0;
    vertical-align: middle;
}

.campaign-edit-btn:hover {
    background: #5a7a3a;
    transform: translateY(-1px);
    box-shadow: 0 3px 6px rgba(61, 90, 38, 0.25);
}

/* Apply button - left-aligned, under everything, tighter spacing */
.apply-button-row {
    display: flex;
    justify-content: flex-start;
    margin-top: 2px;
}

.apply-button-wrapper {
    min-width: 180px;
}

.apply-button-wrapper button {
    padding: 12px 36px;
    background: linear-gradient(135deg, #3d5a26 0%, #5a7a3a 100%);
    border: none;
    border-radius: 8px;
    color: #ffffff;
    font-weight: 600;
    cursor: pointer;
    font-size: 15px;
    transition: all 0.2s;
    white-space: nowrap;
    width: 100%;
    box-shadow: 0 3px 6px rgba(61, 90, 38, 0.2);
    letter-spacing: 0.5px;
}

.apply-button-wrapper button:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 12px rgba(61, 90, 38, 0.3);
    background: linear-gradient(135deg, #5a7a3a 0%, #6a8a4a 100%);
}

.apply-button-wrapper button:active {
    transform: translateY(0);
}

/* Ensure selects show full text */
.stats-filter-panel select {
    text-overflow: ellipsis;
    overflow: hidden;
}

.stats-filter-panel select option {
    white-space: normal;
}

/* Responsive adjustments */
@media (max-width: 1400px) {
    .filter-row {
        grid-template-columns: 1fr;
        gap: 18px;
    }
    
    .filter-row-second {
        grid-template-columns: 1fr;
        gap: 18px;
    }
    
    .campaign-filter-group-wrapper,
    .date-range-group,
    .status-wrapper,
    .chart-view-controls,
    .token-select-wrapper,
    .view-controls-group {
        grid-column: 1;
    }
    
    .date-range-group {
        grid-template-columns: 1fr 1fr;
    }
}

.chart-container {
    background: #ffffff;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
    border: 1px solid #d4d4d4;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}
</style>

<link rel="stylesheet" href="<?= ASSETS_BASE_URL ?>/assets/css/mobile-campaign-stats-legacy.css">

<div class="campaign-stats-page">
    <div class="page-header" style="margin-bottom: 16px;">
        <h1 class="page-title" style="color: #3d5a26; font-size: 24px; font-weight: 700; margin: 0 0 4px 0;">
            Campaign Stats
        </h1>
        <p style="color: #666; margin: 0; font-size: 13px;">Advanced performance analytics and reporting</p>
    </div>

    <?php if ($skipChart || $skipOffer || $skipLp || $skipCampaign || $skipTarget): ?>
    <div class="stats-testing-notice" style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 6px; padding: 10px 14px; margin-bottom: 16px; font-size: 13px;">
        <strong>Testing mode:</strong> Some sections are disabled to isolate slowdown.
        Active: <?php
        $parts = [];
        if ($skipChart) $parts[] = 'no_chart=1';
        if ($skipOffer) $parts[] = 'no_offer=1';
        if ($skipLp) $parts[] = 'no_lp=1';
        if ($skipCampaign) $parts[] = 'no_campaign=1';
        if ($skipTarget) $parts[] = 'no_target=1';
        echo implode(', ', $parts);
        ?>
    </div>
    <?php endif; ?>

    <!-- Compact Filter Panel -->
    <div class="stats-filter-panel">
        <form method="get" action="" id="stats-filter-form">
            <input type="hidden" name="page" value="campaign-stats">
            <?php if ($selectedCampaignId): ?>
            <input type="hidden" name="campaign_id" value="<?= $selectedCampaignId ?>">
            <?php endif; ?>
            <input type="hidden" name="per_page" value="<?= $perPage ?>">
            <input type="hidden" name="search" value="<?= htmlspecialchars($searchQuery) ?>">
            
            <div class="filter-compact-grid">
                <!-- Campaign - Full width, prominent -->
                <div class="campaign-field">
                    <label>Campaign</label>
                    <div class="campaign-select-row">
                        <select name="campaign_id" id="campaign-select">
                            <option value="">All Campaigns</option>
                            <?php foreach ($allCampaigns as $camp): ?>
                                <option value="<?= $camp['id'] ?>" <?= $selectedCampaignId == $camp['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($camp['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($selectedCampaignId): ?>
                            <a href="?page=campaign-list&action=edit&id=<?= $selectedCampaignId ?>" 
                               class="campaign-edit-btn"
                               title="Edit Campaign">
                                ✏️
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Date Range Presets -->
                <?php
                // Determine which preset is active (using user's timezone)
                $activePreset = null;
                try {
                    $tz = new DateTimeZone($userTimezone);
                    $now = new DateTime('now', $tz);
                    $today = $now->format('Y-m-d');
                    
                    $yesterdayDt = clone $now;
                    $yesterdayDt->modify('-1 day');
                    $yesterday = $yesterdayDt->format('Y-m-d');
                    
                    $last7Dt = clone $now;
                    $last7Dt->modify('-6 days');
                    $last7Start = $last7Dt->format('Y-m-d');
                    
                    $last14Dt = clone $now;
                    $last14Dt->modify('-13 days');
                    $last14Start = $last14Dt->format('Y-m-d');
                    
                    $last30Dt = clone $now;
                    $last30Dt->modify('-29 days');
                    $last30Start = $last30Dt->format('Y-m-d');
                    
                    $lastMonthStartDt = clone $now;
                    $lastMonthStartDt->modify('first day of last month');
                    $lastMonthStart = $lastMonthStartDt->format('Y-m-d');
                    
                    $lastMonthEndDt = clone $now;
                    $lastMonthEndDt->modify('last day of last month');
                    $lastMonthEnd = $lastMonthEndDt->format('Y-m-d');
                    
                    $thisMonthStartDt = clone $now;
                    $thisMonthStartDt->modify('first day of this month');
                    $thisMonthStart = $thisMonthStartDt->format('Y-m-d');
                    
                    // All time preset: 2025-01-01 to today
                    $allTimeStart = '2025-01-01';
                } catch (Exception $e) {
                    // Fallback to server timezone
                    $today = date('Y-m-d');
                    $yesterday = date('Y-m-d', strtotime('-1 day'));
                    $last7Start = date('Y-m-d', strtotime('-6 days'));
                    $last14Start = date('Y-m-d', strtotime('-13 days'));
                    $last30Start = date('Y-m-d', strtotime('-29 days'));
                    $lastMonthStart = date('Y-m-01', strtotime('first day of last month'));
                    $lastMonthEnd = date('Y-m-t', strtotime('last month'));
                    $thisMonthStart = date('Y-m-01');
                    $allTimeStart = '2025-01-01';
                }
                
                if ($dateFrom === $today && $dateTo === $today) {
                    $activePreset = 'today';
                } elseif ($dateFrom === $yesterday && $dateTo === $yesterday) {
                    $activePreset = 'yesterday';
                } elseif ($dateFrom === $last7Start && $dateTo === $today) {
                    $activePreset = 'last7';
                } elseif ($dateFrom === $last14Start && $dateTo === $today) {
                    $activePreset = 'last14';
                } elseif ($dateFrom === $last30Start && $dateTo === $today) {
                    $activePreset = 'last30';
                } elseif ($dateFrom === $lastMonthStart && $dateTo === $lastMonthEnd) {
                    $activePreset = 'lastmonth';
                } elseif ($dateFrom === $thisMonthStart && $dateTo === $today) {
                    $activePreset = 'thismonth';
                } elseif ($dateFrom === $allTimeStart && $dateTo === $today) {
                    $activePreset = 'alltime';
                }
                ?>
                <div style="grid-column: 1 / -1; margin-bottom: 12px;">
                    <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                        <label style="font-size: 13px; font-weight: 600; color: #666; display: flex; align-items: center; margin-right: 4px;">Presets:</label>
                        <button type="button" onclick="setStatsDateRange('today')" 
                                style="padding: 6px 12px; background: <?= $activePreset === 'today' ? '#3d5a26' : '#fff' ?>; color: <?= $activePreset === 'today' ? '#fff' : '#666' ?>; border: 1px solid #ddd; border-radius: 4px; font-size: 12px; cursor: pointer; white-space: nowrap;">
                            Today
                        </button>
                        <button type="button" onclick="setStatsDateRange('yesterday')" 
                                style="padding: 6px 12px; background: <?= $activePreset === 'yesterday' ? '#3d5a26' : '#fff' ?>; color: <?= $activePreset === 'yesterday' ? '#fff' : '#666' ?>; border: 1px solid #ddd; border-radius: 4px; font-size: 12px; cursor: pointer; white-space: nowrap;">
                            Yesterday
                        </button>
                        <button type="button" onclick="setStatsDateRange('last7')" 
                                style="padding: 6px 12px; background: <?= $activePreset === 'last7' ? '#3d5a26' : '#fff' ?>; color: <?= $activePreset === 'last7' ? '#fff' : '#666' ?>; border: 1px solid #ddd; border-radius: 4px; font-size: 12px; cursor: pointer; white-space: nowrap;">
                            Last 7 Days
                        </button>
                        <button type="button" onclick="setStatsDateRange('last14')" 
                                style="padding: 6px 12px; background: <?= $activePreset === 'last14' ? '#3d5a26' : '#fff' ?>; color: <?= $activePreset === 'last14' ? '#fff' : '#666' ?>; border: 1px solid #ddd; border-radius: 4px; font-size: 12px; cursor: pointer; white-space: nowrap;">
                            Last 14 Days
                        </button>
                        <button type="button" onclick="setStatsDateRange('last30')" 
                                style="padding: 6px 12px; background: <?= $activePreset === 'last30' ? '#3d5a26' : '#fff' ?>; color: <?= $activePreset === 'last30' ? '#fff' : '#666' ?>; border: 1px solid #ddd; border-radius: 4px; font-size: 12px; cursor: pointer; white-space: nowrap;">
                            Last 30 Days
                        </button>
                        <button type="button" onclick="setStatsDateRange('lastmonth')" 
                                style="padding: 6px 12px; background: <?= $activePreset === 'lastmonth' ? '#3d5a26' : '#fff' ?>; color: <?= $activePreset === 'lastmonth' ? '#fff' : '#666' ?>; border: 1px solid #ddd; border-radius: 4px; font-size: 12px; cursor: pointer; white-space: nowrap;">
                            Last Month
                        </button>
                        <button type="button" onclick="setStatsDateRange('thismonth')" 
                                style="padding: 6px 12px; background: <?= $activePreset === 'thismonth' ? '#3d5a26' : '#fff' ?>; color: <?= $activePreset === 'thismonth' ? '#fff' : '#666' ?>; border: 1px solid #ddd; border-radius: 4px; font-size: 12px; cursor: pointer; white-space: nowrap;">
                            This Month
                        </button>
                        <button type="button" onclick="setStatsDateRange('alltime')" 
                                style="padding: 6px 12px; background: <?= $activePreset === 'alltime' ? '#3d5a26' : '#fff' ?>; color: <?= $activePreset === 'alltime' ? '#fff' : '#666' ?>; border: 1px solid #ddd; border-radius: 4px; font-size: 12px; cursor: pointer; white-space: nowrap;">
                            ALL TIME
                        </button>
                    </div>
                </div>
                
                <!-- Date Range - Side by side -->
                <div class="date-field-group">
                    <div class="filter-field">
                        <label>Date From</label>
                        <input type="date" name="date_from" id="stats_date_from" value="<?= htmlspecialchars($dateFrom) ?>">
                    </div>
                    <div class="filter-field">
                        <label>Date To</label>
                        <input type="date" name="date_to" id="stats_date_to" value="<?= htmlspecialchars($dateTo) ?>">
                    </div>
                </div>
                
                <!-- Status Filter -->
                <?php if (!$selectedCampaignId): ?>
                <div class="filter-field">
                    <label>Campaign Status</label>
                    <select name="status" title="Filter campaigns by status in the performance table below">
                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Campaigns</option>
                        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active Only</option>
                        <option value="paused" <?= $statusFilter === 'paused' ? 'selected' : '' ?>>Paused Only</option>
                        <option value="archived" <?= $statusFilter === 'archived' ? 'selected' : '' ?>>Archived Only</option>
                    </select>
                </div>
                <?php endif; ?>
                
                <!-- Chart View - Full width on its own row -->
                <div class="filter-field" style="grid-column: 1 / -1;">
                    <label>Chart View</label>
                    <select name="chart_view" id="chart-view-select" onchange="this.form.submit()">
                        <option value="visitors_clicks_conversions" <?= $chartView === 'visitors_clicks_conversions' ? 'selected' : '' ?>>
                            Visitors, Clicks & Conversions
                        </option>
                        <option value="revenue" <?= $chartView === 'revenue' ? 'selected' : '' ?>>Revenue</option>
                        <option value="cost" <?= $chartView === 'cost' ? 'selected' : '' ?>>Cost</option>
                    </select>
                </div>
                
                <!-- Traffic Source Filter (show if campaign is in auto-detect mode) -->
                <?php if ($selectedCampaignId && $isAutoDetectCampaign): ?>
                <div class="filter-field" style="grid-column: 1 / -1;">
                    <label style="display: inline-flex; align-items: center; gap: 6px;">
                        <img src="<?= ASSETS_BASE_URL ?>/assets/images/autodetectbear.png" alt="Auto Detected" style="width: 18px; height: 18px; object-fit: contain; vertical-align: middle;">
                        Filter by Traffic Source
                    </label>
                    <select name="traffic_source_id" id="traffic-source-select" onchange="this.form.submit()" 
                            style="width: 100%; padding: 10px; border: 2px solid #3d5a26; border-radius: 4px; font-size: 14px; background: #fff;"
                            <?= empty($detectedTrafficSources) ? 'disabled' : '' ?>>
                        <option value="">All Traffic Sources</option>
                        <?php if (!empty($detectedTrafficSources)): ?>
                            <?php foreach ($detectedTrafficSources as $ts): ?>
                                <option value="<?= $ts['id'] ?>" <?= $selectedTrafficSourceId === $ts['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($ts['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="" disabled>No traffic sources detected yet</option>
                        <?php endif; ?>
                    </select>
                    <p style="margin: 8px 0 0 0; color: #666; font-size: 12px;">
                        <?php if (empty($detectedTrafficSources)): ?>
                            <em>No clicks have been recorded yet. The dropdown will be enabled once traffic sources are detected.</em>
                        <?php else: ?>
                            Select a traffic source to filter stats. Then use Group By Tokens below to drill down further.
                        <?php endif; ?>
                    </p>
                </div>
                <?php endif; ?>
                
                <!-- Group By Slug (only show if campaign is selected and has slugs) -->
                <?php if ($selectedCampaignId): 
                    $campaignSlug = new \SimpleKuma\Entity\CampaignSlug($db);
                    $campaignSlugs = $campaignSlug->getByCampaignId($selectedCampaignId);
                    if (!empty($campaignSlugs)): ?>
                    <div class="filter-field" style="grid-column: 1 / -1;">
                        <label>Group By</label>
                        <select name="group_by" id="group-by-select">
                            <option value="campaign" <?= $groupBy === 'campaign' ? 'selected' : '' ?>>Campaign</option>
                            <option value="slug" <?= $groupBy === 'slug' ? 'selected' : '' ?>>Slug (<?= count($campaignSlugs) ?> available)</option>
                        </select>
                        <p style="margin: 8px 0 0 0; color: #666; font-size: 12px;">
                            <?php if ($groupBy === 'slug'): ?>
                                Viewing performance broken down by slug. Each slug routes to the same campaign but allows you to differentiate traffic sources.
                            <?php else: ?>
                                Switch to "Slug" to see performance broken down by individual tracking slugs for this campaign.
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
                
                <!-- View Mode & Token 1 - Side by side, 50/50 -->
                <div class="view-mode-row">
                    <div class="filter-field">
                        <label>View Mode</label>
                        <select name="view" id="view-mode-select">
                            <option value="standard" <?= $viewMode === 'standard' ? 'selected' : '' ?>>Standard View</option>
                            <option value="target" <?= $viewMode === 'target' ? 'selected' : '' ?>>Target Performance</option>
                        </select>
                    </div>
                    
                    <div class="filter-field token-field">
                        <label>Group By Token 1</label>
                        <select name="token1" id="token1-select" <?= $viewMode !== 'target' ? 'disabled' : '' ?>>
                            <option value="">Select Token...</option>
                            <?php if (!empty($availableTokens)): ?>
                                <optgroup label="Traffic Source Tokens">
                                    <?php foreach ($availableTokens as $token): ?>
                                        <?php 
                                        $tokenParam = is_array($token) ? $token['parameter'] : $token;
                                        $tokenName = is_array($token) ? $token['name'] : $token;
                                        ?>
                                        <option value="<?= htmlspecialchars($tokenParam) ?>" <?= ($selectedTokens[0] ?? '') === $tokenParam ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($tokenName) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                            <?php if (!empty($customTokens)): ?>
                                <optgroup label="Campaign Custom Tokens">
                                    <?php foreach ($customTokens as $token): ?>
                                        <?php 
                                        $tokenParam = $token['parameter'] ?? '';
                                        $tokenName = $token['name'] ?? $tokenParam;
                                        if ($tokenParam): ?>
                                            <option value="<?= htmlspecialchars($tokenParam) ?>" <?= ($selectedTokens[0] ?? '') === $tokenParam ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($tokenName) ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                            <?php if (!empty($builtInTokens)): ?>
                                <optgroup label="Tracker Variables">
                                    <?php foreach ($builtInTokens as $token): ?>
                                        <?php 
                                        $tokenParam = $token['parameter'] ?? '';
                                        $tokenName = $token['name'] ?? $tokenParam;
                                        // Skip traffic_source_id here since it's already shown at top if auto-detect
                                        if ($tokenParam && $tokenParam !== 'traffic_source_id'): ?>
                                            <option value="<?= htmlspecialchars($tokenParam) ?>" <?= ($selectedTokens[0] ?? '') === $tokenParam ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($tokenName) ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
                
                <!-- Token 2 & Token 3 - Side by side, 50/50, always visible -->
                <div class="token-row-2-3">
                    <div class="filter-field token-field">
                        <label>Group By Token 2</label>
                        <select name="token2" id="token2-select" <?= $viewMode !== 'target' || empty($selectedTokens[0] ?? '') ? 'disabled' : '' ?>>
                            <option value="">Select Token...</option>
                            <?php if (!empty($availableTokens)): ?>
                                <optgroup label="Traffic Source Tokens">
                                    <?php foreach ($availableTokens as $token): ?>
                                        <?php 
                                        $tokenParam = is_array($token) ? $token['parameter'] : $token;
                                        $tokenName = is_array($token) ? $token['name'] : $token;
                                        // Don't allow selecting the same token twice
                                        if ($tokenParam !== ($selectedTokens[0] ?? '')): ?>
                                            <option value="<?= htmlspecialchars($tokenParam) ?>" <?= ($selectedTokens[1] ?? '') === $tokenParam ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($tokenName) ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                            <?php if (!empty($customTokens)): ?>
                                <optgroup label="Campaign Custom Tokens">
                                    <?php foreach ($customTokens as $token): ?>
                                        <?php 
                                        $tokenParam = $token['parameter'] ?? '';
                                        $tokenName = $token['name'] ?? $tokenParam;
                                        if ($tokenParam && $tokenParam !== ($selectedTokens[0] ?? '')): ?>
                                            <option value="<?= htmlspecialchars($tokenParam) ?>" <?= ($selectedTokens[1] ?? '') === $tokenParam ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($tokenName) ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                            <?php if (!empty($builtInTokens)): ?>
                                <optgroup label="Tracker Variables">
                                    <?php foreach ($builtInTokens as $token): ?>
                                        <?php 
                                        $tokenParam = $token['parameter'] ?? '';
                                        $tokenName = $token['name'] ?? $tokenParam;
                                        // Don't allow selecting same token twice
                                        if ($tokenParam && $tokenParam !== ($selectedTokens[0] ?? '')): ?>
                                            <option value="<?= htmlspecialchars($tokenParam) ?>" <?= ($selectedTokens[1] ?? '') === $tokenParam ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($tokenName) ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                        </select>
                    </div>
                    
                    <div class="filter-field token-field">
                        <label>Group By Token 3</label>
                        <select name="token3" id="token3-select" <?= $viewMode !== 'target' || empty($selectedTokens[1] ?? '') ? 'disabled' : '' ?>>
                            <option value="">Select Token...</option>
                            <?php if (!empty($availableTokens)): ?>
                                <optgroup label="Traffic Source Tokens">
                                    <?php foreach ($availableTokens as $token): ?>
                                        <?php 
                                        $tokenParam = is_array($token) ? $token['parameter'] : $token;
                                        $tokenName = is_array($token) ? $token['name'] : $token;
                                        // Don't allow selecting tokens already selected
                                        if ($tokenParam !== ($selectedTokens[0] ?? '') && $tokenParam !== ($selectedTokens[1] ?? '')): ?>
                                            <option value="<?= htmlspecialchars($tokenParam) ?>" <?= ($selectedTokens[2] ?? '') === $tokenParam ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($tokenName) ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                            <?php if (!empty($customTokens)): ?>
                                <optgroup label="Campaign Custom Tokens">
                                    <?php foreach ($customTokens as $token): ?>
                                        <?php 
                                        $tokenParam = $token['parameter'] ?? '';
                                        $tokenName = $token['name'] ?? $tokenParam;
                                        if ($tokenParam && $tokenParam !== ($selectedTokens[0] ?? '') && $tokenParam !== ($selectedTokens[1] ?? '')): ?>
                                            <option value="<?= htmlspecialchars($tokenParam) ?>" <?= ($selectedTokens[2] ?? '') === $tokenParam ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($tokenName) ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                            <?php if (!empty($builtInTokens)): ?>
                                <optgroup label="Tracker Variables">
                                    <?php foreach ($builtInTokens as $token): ?>
                                        <?php 
                                        $tokenParam = $token['parameter'] ?? '';
                                        $tokenName = $token['name'] ?? $tokenParam;
                                        // Don't allow selecting same token twice
                                        if ($tokenParam && $tokenParam !== ($selectedTokens[0] ?? '') && $tokenParam !== ($selectedTokens[1] ?? '')): ?>
                                            <option value="<?= htmlspecialchars($tokenParam) ?>" <?= ($selectedTokens[2] ?? '') === $tokenParam ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($tokenName) ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
                
                <!-- Apply Button -->
                <div class="apply-button-row">
                    <div class="apply-button-wrapper">
                        <button type="submit">
                            Apply Filters
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Performance Chart -->
    <div class="chart-container" style="margin-top: 20px;">
        <h3 style="margin-top: 0; color: #3d5a26; font-size: 18px; font-weight: 600; margin-bottom: 16px;">
            Performance Chart
        </h3>
        <div style="position: relative; height: 400px;">
            <canvas id="performanceChart"></canvas>
        </div>
    </div>

    <!-- Loading Spinner Container (shown during AJAX load) -->
    <div id="stats-loading-container" class="stats-loading-container" style="display: <?= $useAjaxLoading ? 'flex' : 'none' ?>;">
        <div class="stats-loading-spinner"></div>
        <div class="stats-loading-text">Loading stats data...<?= $useAjaxLoading ? ' (AJAX mode)' : '' ?></div>
        <?php if ($useAjaxLoading): ?>
        <div style="margin-top: 10px; font-size: 12px; color: #999;">
            Page structure loaded instantly. Fetching data in background...
        </div>
        <?php endif; ?>
    </div>

    <!-- Data Container (hidden until AJAX data loads) -->
    <div id="stats-data-container" class="stats-data-container <?= $useAjaxLoading ? '' : 'loaded' ?>">
    
    <!-- Offer Performance Table -->
    <div class="stats-table-container">
        <h3 style="margin-top: 0; color: #3d5a26; font-size: 18px; font-weight: 600; margin-bottom: 20px;">
            Offer Performance<?= ($viewMode === 'target' && !empty($selectedTokens)) ? ' - ' . htmlspecialchars(implode(' → ', $selectedTokenNames)) : '' ?>
        </h3>
        <p style="color: #666; margin-bottom: 16px; font-size: 14px;">
            <?php if ($viewMode === 'target' && !empty($selectedTokens)): ?>
                Performance metrics grouped by offer, with breakdown by <strong><?= htmlspecialchars(implode(' → ', $selectedTokenNames)) ?></strong> below each offer.
            <?php else: ?>
                Performance metrics grouped by offer. Additional views coming soon.
            <?php endif; ?>
        </p>
        
        <!-- Traffic Source Header Banner (if traffic source selected) -->
        <?php if ($selectedTrafficSourceId && $selectedTrafficSourceName): ?>
            <?php $trafficSourceIcon = $getTrafficSourceIcon($selectedTrafficSourceName); ?>
            <div style="background: linear-gradient(135deg, #3d5a26 0%, #5a7a3a 100%); color: #ffffff; padding: 28px 32px; border-radius: 12px; margin: 20px 0 24px 0; box-shadow: 0 4px 12px rgba(61, 90, 38, 0.2); text-align: center;">
                <h2 style="margin: 0; font-size: 32px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; color: #ffffff; text-shadow: 0 2px 4px rgba(0,0,0,0.2); display: flex; align-items: center; justify-content: center; gap: 16px; flex-wrap: wrap;">
                    <?php if ($trafficSourceIcon): ?>
                        <img src="<?= htmlspecialchars($trafficSourceIcon) ?>" alt="<?= htmlspecialchars($selectedTrafficSourceName) ?>" style="width: 48px; height: 48px; object-fit: contain; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));">
                    <?php else: ?>
                        <img src="<?= ASSETS_BASE_URL ?>/assets/images/autodetectbear.png" alt="Auto Detected" style="width: 48px; height: 48px; object-fit: contain; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));">
                    <?php endif; ?>
                    <?= htmlspecialchars($selectedTrafficSourceName) ?>
                </h2>
                <p style="margin: 12px 0 0 0; font-size: 16px; opacity: 0.95; color: #ffffff; text-shadow: 0 1px 2px rgba(0,0,0,0.2);">
                    Viewing stats filtered by this traffic source
                </p>
            </div>
        <?php endif; ?>
        
        <!-- Desktop Table -->
        <div class="table-wrapper desktop-only">
        <table class="stats-table stats-table-sortable" data-sort-key="visitors" data-sort-dir="desc">
            <thead>
                <tr>
                    <th class="sortable-col" data-sort-key="offer_name" data-sort-type="string" title="Click to sort">Offer <span class="sort-indicator" aria-hidden="true">↕</span></th>
                    <th class="sortable-col" data-sort-key="visitors" data-sort-type="number" title="Click to sort">Visitors <span class="sort-indicator" aria-hidden="true">↕</span></th>
                    <th class="sortable-col" data-sort-key="cost" data-sort-type="number" title="Click to sort">Cost <span class="sort-indicator" aria-hidden="true">↕</span></th>
                    <th class="sortable-col" data-sort-key="cpc" data-sort-type="number" title="Click to sort">CPC <span class="sort-indicator" aria-hidden="true">↕</span></th>
                    <th class="sortable-col" data-sort-key="lp_clicks" data-sort-type="number" title="Click to sort">LP Clicks <span class="sort-indicator" aria-hidden="true">↕</span></th>
                    <th class="sortable-col" data-sort-key="conversions" data-sort-type="number" title="Click to sort">Conversions <span class="sort-indicator" aria-hidden="true">↕</span></th>
                    <th class="sortable-col" data-sort-key="cr" data-sort-type="number" title="Click to sort">CR <span class="sort-indicator" aria-hidden="true">↕</span></th>
                    <th class="sortable-col" data-sort-key="cpa" data-sort-type="number" title="Click to sort">CPA <span class="sort-indicator" aria-hidden="true">↕</span></th>
                    <th class="sortable-col" data-sort-key="revenue" data-sort-type="number" title="Click to sort">Revenue <span class="sort-indicator" aria-hidden="true">↕</span></th>
                    <th class="sortable-col" data-sort-key="pl" data-sort-type="number" title="Click to sort">P/L <span class="sort-indicator" aria-hidden="true">↕</span></th>
                    <th class="sortable-col" data-sort-key="roi" data-sort-type="number" title="Click to sort">ROI <span class="sort-indicator" aria-hidden="true">↕</span></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($offerPerformance)): ?>
                    <tr>
                        <td colspan="11" style="text-align: center; padding: 40px; color: #999;">
                            No offers found in campaigns for the selected filters. Add offers to campaigns to see performance data.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php
                    // Aggregate helper for target breakdown (used for Total row and per-offer when in target mode)
                    $aggregateTargetStats = function($data) use (&$aggregateTargetStats) {
                        if (isset($data['visitors'])) {
                            return [
                                'visitors' => (int)($data['visitors'] ?? 0),
                                'cost' => (float)($data['cost'] ?? 0),
                                'lp_clicks' => (int)($data['lp_clicks'] ?? 0),
                                'conversions' => (int)($data['conversions'] ?? 0),
                                'revenue' => (float)($data['revenue'] ?? 0)
                            ];
                        }
                        $aggregated = ['visitors' => 0, 'cost' => 0, 'lp_clicks' => 0, 'conversions' => 0, 'revenue' => 0];
                        foreach ($data as $value) {
                            if (is_array($value)) {
                                $childStats = $aggregateTargetStats($value);
                                $aggregated['visitors'] += $childStats['visitors'];
                                $aggregated['cost'] += $childStats['cost'];
                                $aggregated['lp_clicks'] += $childStats['lp_clicks'];
                                $aggregated['conversions'] += $childStats['conversions'];
                                $aggregated['revenue'] += $childStats['revenue'];
                            }
                        }
                        return $aggregated;
                    };
                    // Offer-table totals = sum of offer rows (so Total equals sum of displayed rows; one offer => Total = that offer)
                    $offerTableTotalVisitors = 0;
                    $offerTableTotalCost = 0.0;
                    $offerTableTotalClicks = 0;
                    $offerTableTotalConversions = 0;
                    $offerTableTotalRevenue = 0.0;
                    foreach ($offerPerformance as $offerRow) {
                        $oid = (int)($offerRow['offer_id'] ?? 0);
                        if ($viewMode === 'target' && !empty($selectedTokens) && !empty($offerTokenPerformance[$oid])) {
                            $rowStats = $aggregateTargetStats($offerTokenPerformance[$oid]);
                            $offerTableTotalVisitors += $rowStats['visitors'];
                            $offerTableTotalCost += $rowStats['cost'];
                            $offerTableTotalClicks += $rowStats['lp_clicks'];
                            $offerTableTotalConversions += $rowStats['conversions'];
                            $offerTableTotalRevenue += $rowStats['revenue'];
                        } else {
                            $offerTableTotalVisitors += (int)($offerRow['visitors'] ?? 0);
                            $offerTableTotalCost += (float)($offerRow['cost'] ?? 0);
                            $offerTableTotalClicks += (int)($offerRow['lp_clicks'] ?? 0);
                            $offerTableTotalConversions += (int)($offerRow['conversions'] ?? 0);
                            $offerTableTotalRevenue += (float)($offerRow['revenue'] ?? 0);
                        }
                    }
                    // Grand total row (sum of offer rows), pinned at top when sorting
                    $totalCpc = $offerTableTotalVisitors > 0 ? $offerTableTotalCost / $offerTableTotalVisitors : 0;
                    $totalCr = $offerTableTotalVisitors > 0 ? ($offerTableTotalConversions / $offerTableTotalVisitors) * 100 : 0;
                    $totalCpa = $offerTableTotalConversions > 0 ? $offerTableTotalCost / $offerTableTotalConversions : 0;
                    $totalPl = $offerTableTotalRevenue - $offerTableTotalCost;
                    $totalRoi = $offerTableTotalCost > 0 ? (($totalPl / $offerTableTotalCost) * 100) : 0;
                    ?>
                    <tr data-block-id="offer-total" data-total-row="1" data-sort-offer_name="Total" data-sort-visitors="<?= (int)$offerTableTotalVisitors ?>" data-sort-cost="<?= (float)$offerTableTotalCost ?>" data-sort-cpc="<?= (float)$totalCpc ?>" data-sort-lp_clicks="<?= (int)$offerTableTotalClicks ?>" data-sort-conversions="<?= (int)$offerTableTotalConversions ?>" data-sort-cr="<?= (float)$totalCr ?>" data-sort-cpa="<?= (float)$totalCpa ?>" data-sort-revenue="<?= (float)$offerTableTotalRevenue ?>" data-sort-pl="<?= (float)$totalPl ?>" data-sort-roi="<?= (float)$totalRoi ?>" style="background: #e8f0e8; font-weight: 600;">
                        <td><strong>Total</strong></td>
                        <td><?= number_format($offerTableTotalVisitors) ?></td>
                        <td><?= Formatter::formatCurrency($offerTableTotalCost, $userCurrency) ?></td>
                        <td><?= Formatter::formatCurrency($totalCpc, $userCurrency) ?></td>
                        <td><?= number_format($offerTableTotalClicks) ?></td>
                        <td><?= number_format($offerTableTotalConversions) ?></td>
                        <td><?= number_format($totalCr, 2) ?>%</td>
                        <td><?= Formatter::formatCurrency($totalCpa, $userCurrency) ?></td>
                        <td><?= Formatter::formatCurrency($offerTableTotalRevenue, $userCurrency) ?></td>
                        <td style="color: <?= $totalPl >= 0 ? '#28a745' : '#dc3545' ?>;">
                            <?= Formatter::formatCurrency($totalPl, $userCurrency) ?>
                        </td>
                        <td style="color: <?= $totalRoi >= 0 ? '#28a745' : '#dc3545' ?>;">
                            <?= number_format($totalRoi, 1) ?>%
                        </td>
                    </tr>
                    <?php foreach ($offerPerformance as $offer): ?>
                                    <?php
                                    $offerId = (int)($offer['offer_id'] ?? 0);
                                    $offerTrafficSourceId = (int)($offer['traffic_source_id'] ?? 0);
                                    
                                    // In target mode, use aggregated cost from breakdown if available (more accurate)
                                    if ($viewMode === 'target' && !empty($selectedTokens) && !empty($offerTokenPerformance[$offerId])) {
                                        $targetStats = $aggregateTargetStats($offerTokenPerformance[$offerId]);
                                        $visitors = $targetStats['visitors'];
                                        $cost = $targetStats['cost'];
                                        $lpClicks = $targetStats['lp_clicks'];
                                        $conversions = $targetStats['conversions'];
                                        $revenue = $targetStats['revenue'];
                                    } else {
                                        // Standard mode - use SQL query results
                                        $visitors = (int)($offer['visitors'] ?? 0);
                                        $cost = (float)($offer['cost'] ?? 0);
                                        $lpClicks = (int)($offer['lp_clicks'] ?? 0);
                                        $conversions = (int)($offer['conversions'] ?? 0);
                                        $revenue = (float)($offer['revenue'] ?? 0);
                                    }
                                    
                                    $cpc = $visitors > 0 ? $cost / $visitors : 0;
                                    $cr = $visitors > 0 ? ($conversions / $visitors) * 100 : 0;
                                    $cpa = $conversions > 0 ? $cost / $conversions : 0;
                                    $pl = $revenue - $cost;
                                    $roi = $cost > 0 ? (($pl / $cost) * 100) : 0;
                                    ?>
                                    <tr data-block-id="offer-<?= $offerId ?>" data-offer-total-row="1" data-sort-offer_name="<?= htmlspecialchars($offer['offer_name']) ?>" data-sort-visitors="<?= (int)$visitors ?>" data-sort-cost="<?= (float)$cost ?>" data-sort-cpc="<?= (float)$cpc ?>" data-sort-lp_clicks="<?= (int)$lpClicks ?>" data-sort-conversions="<?= (int)$conversions ?>" data-sort-cr="<?= (float)$cr ?>" data-sort-cpa="<?= (float)$cpa ?>" data-sort-revenue="<?= (float)$revenue ?>" data-sort-pl="<?= (float)$pl ?>" data-sort-roi="<?= (float)$roi ?>">
                                        <td><strong><?= htmlspecialchars($offer['offer_name']) ?></strong></td>
                                        <td><?= number_format($visitors) ?></td>
                                        <td><?= Formatter::formatCurrency($cost, $userCurrency) ?></td>
                                        <td><?= Formatter::formatCurrency($cpc, $userCurrency) ?></td>
                                        <td><?= number_format($lpClicks) ?></td>
                                        <td><?= number_format($conversions) ?></td>
                                        <td><?= number_format($cr, 2) ?>%</td>
                                        <td><?= Formatter::formatCurrency($cpa, $userCurrency) ?></td>
                                        <td><?= Formatter::formatCurrency($revenue, $userCurrency) ?></td>
                                        <td style="color: <?= $pl >= 0 ? '#28a745' : '#dc3545' ?>; font-weight: 600;">
                                            <?= Formatter::formatCurrency($pl, $userCurrency) ?>
                                        </td>
                                        <td style="color: <?= $roi >= 0 ? '#28a745' : '#dc3545' ?>; font-weight: 600;">
                                            <?= number_format($roi, 1) ?>%
                                        </td>
                                    </tr>
                                    <?php if ($viewMode === 'target' && !empty($selectedTokens) && !empty($offerTokenPerformance[$offerId])): ?>
                                        <?php
                                        // Helper function to aggregate stats from nested data
                                        $aggregateStats = function($data) use (&$aggregateStats) {
                                            // Check if this is a leaf node (has stats directly)
                                            // A leaf node has 'offer_id' or has both 'visitors' and 'cost' as direct properties
                                            if (isset($data['offer_id']) || (isset($data['visitors']) && isset($data['cost']))) {
                                                return [
                                                    'visitors' => (int)($data['visitors'] ?? 0),
                                                    'cost' => (float)($data['cost'] ?? 0),
                                                    'lp_clicks' => (int)($data['lp_clicks'] ?? 0),
                                                    'conversions' => (int)($data['conversions'] ?? 0),
                                                    'revenue' => (float)($data['revenue'] ?? 0)
                                                ];
                                            }
                                            // Intermediate node - aggregate from children
                                            $aggregated = [
                                                'visitors' => 0,
                                                'cost' => 0,
                                                'lp_clicks' => 0,
                                                'conversions' => 0,
                                                'revenue' => 0
                                            ];
                                            foreach ($data as $key => $value) {
                                                if (is_array($value)) {
                                                    $childStats = $aggregateStats($value);
                                                    $aggregated['visitors'] += $childStats['visitors'];
                                                    $aggregated['cost'] += $childStats['cost'];
                                                    $aggregated['lp_clicks'] += $childStats['lp_clicks'];
                                                    $aggregated['conversions'] += $childStats['conversions'];
                                                    $aggregated['revenue'] += $childStats['revenue'];
                                                }
                                            }
                                            return $aggregated;
                                        };
                                        
                                        // Helper function to get parent adset cost and distribute proportionally for ads
                                        $getProportionalAdCost = function($adId, $adsetId, $adVisitors, $allTokenData) use ($costAggregator, $utcDateFrom, $utcDateTo, $userTimezone, $offerId, $selectedTokens) {
                                            if (empty($adsetId) || $adVisitors <= 0) {
                                                return 0;
                                            }
                                            
                                            // Get total adset cost
                                            $adsetFilters = [];
                                            $adsetFilterParams = [];
                                            
                                            // Find adset_id in selectedTokens
                                            $adsetTokenIndex = array_search('adset_id', $selectedTokens);
                                            if ($adsetTokenIndex !== false && isset($allTokenData['adset_id'])) {
                                                // Use indexed column
                                                $adsetFilters[] = "cl.adset_id = ?";
                                                $adsetFilterParams[] = $allTokenData['adset_id'];
                                            } else {
                                                // Try to find adset_id from the data structure - use indexed column
                                                $adsetFilters[] = "cl.adset_id = ?";
                                                $adsetFilterParams[] = $adsetId;
                                            }
                                            
                                            $adsetFilters[] = "offer_id = ?";
                                            $adsetFilterParams[] = $offerId;
                                            
                                            $adsetFilterStr = "AND " . implode(" AND ", $adsetFilters);
                                            $adsetTotalCost = $costAggregator->getAggregatedCost($utcDateFrom, $utcDateTo, $adsetFilterStr, $adsetFilterParams, $userTimezone);
                                            
                                            // Get total visitors for the adset
                                            $adsetTotalVisitors = 0;
                                            if (isset($allTokenData['adset_visitors'])) {
                                                $adsetTotalVisitors = $allTokenData['adset_visitors'];
                                            }
                                            
                                            // If we can't get adset visitors, calculate proportionally based on available data
                                            if ($adsetTotalVisitors <= 0) {
                                                // Fallback: use ad-level cost if available
                                                $adFilters = [];
                                                $adFilterParams = [];
                                                // Use indexed column
                                                $adFilters[] = "cl.ad_id = ?";
                                                $adFilterParams[] = $adId;
                                                $adFilters[] = "offer_id = ?";
                                                $adFilterParams[] = $offerId;
                                                $adFilterStr = "AND " . implode(" AND ", $adFilters);
                                                return $costAggregator->getAggregatedCost($utcDateFrom, $utcDateTo, $adFilterStr, $adFilterParams, $userTimezone);
                                            }
                                            
                                            // Distribute proportionally
                                            return ($adVisitors / $adsetTotalVisitors) * $adsetTotalCost;
                                        };
                                        
                                        // Offer-level totals for proportional cost fallback (no extra queries)
                                        $offerVisitors = $visitors;
                                        $offerCost = $cost;
                                        // Recursive function to render nested token breakdowns
                                        $renderOfferTokenBreakdown = function($tokenData, $level = 0, $path = [], $parentAdsetData = null) use (&$renderOfferTokenBreakdown, &$aggregateStats, $selectedTokens, $selectedTokenNames, $userCurrency, $costAggregator, $utcDateFrom, $utcDateTo, $userTimezone, $offerId, &$getProportionalAdCost, $db, $trafficSourceColumnExists, $offerVisitors, $offerCost) {
                                            foreach ($tokenData as $tokenValue => $data) {
                                                $currentPath = array_merge($path, [$tokenValue]);
                                                $indent = $level * 30;
                                                $prefix = str_repeat('└─ ', $level + 1);
                                                
                                                // Check if this is a leaf node (has stats directly)
                                                // A leaf node has 'offer_id' or has both 'visitors' and 'cost' as direct properties
                                                $isLeaf = isset($data['offer_id']) || (isset($data['visitors']) && isset($data['cost']));
                                                
                                                if ($isLeaf) {
                                                    $tokenVisitors = (int)($data['visitors'] ?? 0);
                                                    $tokenLpClicks = (int)($data['lp_clicks'] ?? 0);
                                                    $tokenConversions = (int)($data['conversions'] ?? 0);
                                                    $tokenRevenue = (float)($data['revenue'] ?? 0);
                                                    $tokenCost = (float)($data['cost'] ?? 0);
                                                    // Skip render-time getAggregatedCost when cost is already set (including 0 from build phase)
                                                    $usePreAggregatedCost = isset($data['cost']) && is_numeric($data['cost']);
                                                    if ($usePreAggregatedCost) {
                                                        $tokenCost = (float)$data['cost'];
                                                    }
                                                    if (!$usePreAggregatedCost) {
                                                    // Calculate cost using getAggregatedCost with token filters for leaf nodes too
                                                    $tokenFilters = [];
                                                    $tokenFilterParams = [];
                                                    
                                                    // Build filters for all tokens in the current path (including this leaf node)
                                                    for ($i = 0; $i < count($currentPath) && $i < count($selectedTokens); $i++) {
                                                        $tokenParam = $selectedTokens[$i];
                                                        $tokenVal = $currentPath[$i];
                                                        
                                                        // Build filter for this token using universal token filter builder
                                                        // PERFORMANCE: Automatically uses best available method (generated columns, built-in columns, or JSON_EXTRACT)
                                                        // FUTURE-PROOF: Works for any token type, current or future
                                                        $tokenFilter = buildTokenFilter($tokenParam, $tokenVal, $db, $utcDateFrom, $utcDateTo);
                                                        if ($tokenFilter) {
                                                            $tokenFilters[] = $tokenFilter[0];
                                                            $tokenFilterParams = array_merge($tokenFilterParams, $tokenFilter[1]);
                                                        }
                                                    }
                                                    
                                                    // Add offer filter
                                                    $tokenFilters[] = "offer_id = ?";
                                                    $tokenFilterParams[] = $offerId;
                                                    
                                                    $tokenCost = 0;
                                                    
                                                    // Check if there's an adset level in the path above this level
                                                    // If so, we need to distribute the adset cost proportionally
                                                    // This works for ANY token breakdown (city, ad_name, etc.) when there's an adset parent
                                                    $hasAdsetInPath = false;
                                                    $adsetIdFromPath = null;
                                                    $adsetNameFromPath = null;
                                                    $adsetLevelIndex = null;
                                                    
                                                    // Find adset_id or adset_name in the path
                                                    $adsetTokenIndex = array_search('adset_id', $selectedTokens);
                                                    $adsetNameTokenIndex = array_search('adset_name', $selectedTokens);
                                                    
                                                    if ($adsetTokenIndex !== false && $adsetTokenIndex < count($currentPath)) {
                                                        $hasAdsetInPath = true;
                                                        $adsetLevelIndex = $adsetTokenIndex;
                                                        $adsetIdFromPath = $currentPath[$adsetTokenIndex];
                                                    } elseif ($adsetNameTokenIndex !== false && $adsetNameTokenIndex < count($currentPath)) {
                                                        // If we have adset_name, we need to find the actual adset_id
                                                        $hasAdsetInPath = true;
                                                        $adsetLevelIndex = $adsetNameTokenIndex;
                                                        $adsetNameFromPath = $currentPath[$adsetNameTokenIndex];
                                                        
                                                        // Try to find adset_id from clicks that have this adset_name
                                                        // We'll query for it if needed
                                                    }
                                                    
                                                    // If there's an adset level above this, distribute proportionally
                                                    // This works for ANY token breakdown (city, ad_name, etc.) when there's an adset parent
                                                    if ($hasAdsetInPath) {
                                                        $adsetTotalCost = 0;
                                                        $adsetTotalVisitors = 0;
                                                        
                                                        // Try to get adset data from parent first
                                                        if (!empty($parentAdsetData)) {
                                                            $adsetTotalCost = (float)($parentAdsetData['cost'] ?? 0);
                                                            $adsetTotalVisitors = (int)($parentAdsetData['visitors'] ?? 0);
                                                        }
                                                        
                                                        // If we don't have parent adset data, calculate it from the adset_id or adset_name in the path
                                                        if (($adsetTotalCost <= 0 || $adsetTotalVisitors <= 0)) {
                                                            $adsetFilters = [];
                                                            $adsetFilterParams = [];
                                                            
                                                            if (!empty($adsetIdFromPath)) {
                                                                // We have adset_id directly - use indexed column
                                                                $adsetFilters[] = "cl.adset_id = ?";
                                                                $adsetFilterParams[] = $adsetIdFromPath;
                                                            } elseif (!empty($adsetNameFromPath)) {
                                                                // PERFORMANCE: Convert adset_name to adset_id for indexed query
                                                                $adsetIdFromName = getAdsetIdFromName($db, $adsetNameFromPath, $utcDateFrom, $utcDateTo);
                                                                if ($adsetIdFromName) {
                                                                    $adsetFilters[] = "cl.adset_id = ?";
                                                                    $adsetFilterParams[] = $adsetIdFromName;
                                                                } else {
                                                                    // Fallback: if no adset_id found, use JSON_EXTRACT (shouldn't happen in practice)
                                                                $adsetFilters[] = "JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.adset_name')) = ?";
                                                                $adsetFilterParams[] = $adsetNameFromPath;
                                                                }
                                                            }
                                                            
                                                            if (!empty($adsetFilters)) {
                                                                $adsetFilters[] = "offer_id = ?";
                                                                $adsetFilterParams[] = $offerId;
                                                                $adsetFilterStr = "AND " . implode(" AND ", $adsetFilters);
                                                                $adsetTotalCost = $costAggregator->getAggregatedCost($utcDateFrom, $utcDateTo, $adsetFilterStr, $adsetFilterParams, $userTimezone);
                                                                
                                                                // Get total visitors for the adset by querying the database
                                                                if ($adsetTotalVisitors <= 0) {
                                                                    if (!empty($adsetIdFromPath)) {
                                                                        // Query by adset_id - use indexed column
                                                                        $adsetVisitorsQuery = $db->prepare("
                                                                            SELECT COUNT(DISTINCT cl.id) as total_visitors
                                                                            FROM {$clicksTable} cl
                                                                            WHERE cl.ts >= ? AND cl.ts <= ?
                                                                            AND cl.adset_id = ?
                                                                            AND cl.offer_id = ?
                                                                            AND cl.ad_id IS NOT NULL
                                                                            -- PERFORMANCE: Use generated columns instead of JSON_EXTRACT for index usage
                                                                        ");
                                                                        $adsetVisitorsQuery->bind_param('sssi', $utcDateFrom, $utcDateTo, $adsetIdFromPath, $offerId);
                                                                    } else {
                                                                        // Query by adset_name - PERFORMANCE: Convert to adset_id for indexed query
                                                                        $adsetIdFromName = getAdsetIdFromName($db, $adsetNameFromPath, $utcDateFrom, $utcDateTo);
                                                                        if ($adsetIdFromName) {
                                                                            $adsetVisitorsQuery = $db->prepare("
                                                                                SELECT COUNT(DISTINCT cl.id) as total_visitors
                                                                                FROM {$clicksTable} cl
                                                                                WHERE cl.ts >= ? AND cl.ts <= ?
                                                                                AND cl.adset_id = ?
                                                                                AND cl.offer_id = ?
                                                                                AND cl.ad_id IS NOT NULL
                                                                                AND cl.adset_id IS NOT NULL
                                                                                -- PERFORMANCE: Use generated columns instead of JSON_EXTRACT for index usage
                                                                            ");
                                                                            $adsetVisitorsQuery->bind_param('ssii', $utcDateFrom, $utcDateTo, $adsetIdFromName, $offerId);
                                                                        } else {
                                                                            // Fallback: if no adset_id found, use JSON_EXTRACT (shouldn't happen in practice)
                                                                        $adsetVisitorsQuery = $db->prepare("
                                                                            SELECT COUNT(DISTINCT cl.id) as total_visitors
                                                                            FROM {$clicksTable} cl
                                                                            WHERE cl.ts >= ? AND cl.ts <= ?
                                                                            AND JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.adset_name')) = ?
                                                                            AND cl.offer_id = ?
                                                                                AND cl.ad_id IS NOT NULL
                                                                                AND cl.adset_id IS NOT NULL
                                                                                -- PERFORMANCE: Use generated columns for validation instead of JSON_EXTRACT
                                                                        ");
                                                                        $adsetVisitorsQuery->bind_param('sssi', $utcDateFrom, $utcDateTo, $adsetNameFromPath, $offerId);
                                                                        }
                                                                    }
                                                                    $adsetVisitorsQuery->execute();
                                                                    $adsetVisitorsResult = $adsetVisitorsQuery->get_result()->fetch_assoc();
                                                                    $adsetTotalVisitors = (int)($adsetVisitorsResult['total_visitors'] ?? 0);
                                                                }
                                                            }
                                                        }
                                                        
                                                        // Distribute proportionally if we have valid data
                                                        if ($adsetTotalVisitors > 0 && $adsetTotalCost > 0) {
                                                            $tokenCost = ($tokenVisitors / $adsetTotalVisitors) * $adsetTotalCost;
                                                        } else {
                                                            // Fallback: use getAggregatedCost with ad_id filter (will return adset cost, but better than 0)
                                                            if (!empty($tokenFilters)) {
                                                                $filterStr = "AND " . implode(" AND ", $tokenFilters);
                                                                $tokenCost = $costAggregator->getAggregatedCost($utcDateFrom, $utcDateTo, $filterStr, $tokenFilterParams, $userTimezone);
                                                            } else {
                                                                $tokenCost = 0;
                                                            }
                                                        }
                                                    } else {
                                                        // For non-ad levels without adset in path, distribute proportionally from parent level
                                                        // Get parent level total cost and visitors to distribute proportionally
                                                        $parentTotalCost = 0;
                                                        $parentTotalVisitors = 0;
                                                        
                                                        // Build parent filter (all tokens except the current one)
                                                        $parentFilters = [];
                                                        $parentFilterParams = [];
                                                        
                                                        // Add all parent tokens (everything before current level)
                                                        // PERFORMANCE: Use universal filter builder for ALL token types (not just ad_id/adset_id)
                                                        // FUTURE-PROOF: Works for any current or future token type automatically
                                                        for ($i = 0; $i < $level && $i < count($selectedTokens); $i++) {
                                                            $parentTokenParam = $selectedTokens[$i];
                                                            $parentTokenVal = $currentPath[$i];
                                                            
                                                            // Use universal filter builder for ALL token types
                                                            $parentFilter = buildTokenFilter($parentTokenParam, $parentTokenVal, $db, $utcDateFrom, $utcDateTo);
                                                            if ($parentFilter) {
                                                                $parentFilters[] = $parentFilter[0];
                                                                $parentFilterParams = array_merge($parentFilterParams, $parentFilter[1]);
                                                            }
                                                        }
                                                        
                                                        // Add offer filter
                                                        $parentFilters[] = "offer_id = ?";
                                                        $parentFilterParams[] = $offerId;
                                                        
                                                        if (!empty($parentFilters)) {
                                                            $parentFilterStr = "AND " . implode(" AND ", $parentFilters);
                                                            $parentTotalCost = $costAggregator->getAggregatedCost($utcDateFrom, $utcDateTo, $parentFilterStr, $parentFilterParams, $userTimezone);
                                                            
                                                            // Get parent total visitors (exclude Facebook approval clicks to match breakdown counts)
                                                            $parentVisitorsQuery = $db->prepare("
                                                                SELECT COUNT(DISTINCT CASE 
                                                                    -- For Facebook traffic source, require both ad_id AND adset_id (exclude invalid clicks)
                                                                    WHEN (" . ($trafficSourceColumnExists ? "cl.traffic_source_id = 4" : "c_parent.traffic_source_id = 4") . ") THEN 
                                                                        CASE 
                                                                            WHEN cl.ad_id IS NOT NULL 
                                                                                AND cl.adset_id IS NOT NULL
                                                                            THEN cl.id
                                                                            ELSE NULL
                                                                        END
                                                                    -- For other traffic sources, count all clicks
                                                                    ELSE cl.id
                                                                END) as total_visitors
                                                                FROM {$clicksTable} cl
                                                                " . ($trafficSourceColumnExists ? "" : "INNER JOIN campaigns c_parent ON cl.campaign_id = c_parent.id") . "
                                                                WHERE cl.ts >= ? AND cl.ts <= ?
                                                                " . $parentFilterStr . "
                                                            ");
                                                            // PERFORMANCE: Determine bind parameter types based on actual parameter values
                                                            // Since universal filter builder may return different types (int for IDs, string for names/custom tokens),
                                                            // we need to detect types from the actual parameter values
                                                            $parentVisitorsBindTypes = 'ss';
                                                            foreach ($parentFilterParams as $param) {
                                                                // Check if parameter is numeric (integer) or string
                                                                if (is_numeric($param) && (int)$param == $param) {
                                                                    $parentVisitorsBindTypes .= 'i';
                                                            } else {
                                                                    $parentVisitorsBindTypes .= 's';
                                                                }
                                                            }
                                                            $parentVisitorsBindValues = array_merge([$utcDateFrom, $utcDateTo], $parentFilterParams);
                                                            $parentVisitorsQuery->bind_param($parentVisitorsBindTypes, ...$parentVisitorsBindValues);
                                                            $parentVisitorsQuery->execute();
                                                            $parentVisitorsResult = $parentVisitorsQuery->get_result()->fetch_assoc();
                                                            $parentTotalVisitors = (int)($parentVisitorsResult['total_visitors'] ?? 0);
                                                        } else {
                                                            // No parent filters - use offer total
                                                            $offerFilterStr = "AND offer_id = ?";
                                                            $parentTotalCost = $costAggregator->getAggregatedCost($utcDateFrom, $utcDateTo, $offerFilterStr, [$offerId], $userTimezone);
                                                            
                                                            // Get parent total visitors (exclude Facebook approval clicks to match breakdown counts)
                                                            $parentVisitorsQuery = $db->prepare("
                                                                SELECT COUNT(DISTINCT CASE 
                                                                    -- For Facebook traffic source, require both ad_id AND adset_id (exclude invalid clicks)
                                                                    WHEN (" . ($trafficSourceColumnExists ? "cl.traffic_source_id = 4" : "c_parent.traffic_source_id = 4") . ") THEN 
                                                                        CASE 
                                                                            WHEN cl.ad_id IS NOT NULL 
                                                                                AND cl.adset_id IS NOT NULL
                                                                            THEN cl.id
                                                                            ELSE NULL
                                                                        END
                                                                    -- For other traffic sources, count all clicks
                                                                    ELSE cl.id
                                                                END) as total_visitors
                                                                FROM {$clicksTable} cl
                                                                " . ($trafficSourceColumnExists ? "" : "INNER JOIN campaigns c_parent ON cl.campaign_id = c_parent.id") . "
                                                                WHERE cl.ts >= ? AND cl.ts <= ?
                                                                AND cl.offer_id = ?
                                                            ");
                                                            $parentVisitorsQuery->bind_param('ssi', $utcDateFrom, $utcDateTo, $offerId);
                                                            $parentVisitorsQuery->execute();
                                                            $parentVisitorsResult = $parentVisitorsQuery->get_result()->fetch_assoc();
                                                            $parentTotalVisitors = (int)($parentVisitorsResult['total_visitors'] ?? 0);
                                                        }
                                                        
                                                        // Distribute proportionally if we have valid data
                                                        if ($parentTotalVisitors > 0 && $parentTotalCost > 0) {
                                                            $tokenCost = ($tokenVisitors / $parentTotalVisitors) * $parentTotalCost;
                                                        } else {
                                                            // Fallback: use getAggregatedCost with token filters
                                                            if (!empty($tokenFilters)) {
                                                                $filterStr = "AND " . implode(" AND ", $tokenFilters);
                                                                $tokenCost = $costAggregator->getAggregatedCost($utcDateFrom, $utcDateTo, $filterStr, $tokenFilterParams, $userTimezone);
                                                            } else {
                                                                // Fallback to cost from structure
                                                                $tokenCost = (float)($data['cost'] ?? 0);
                                                            }
                                                        }
                                                    }
                                                    } // end if (!$usePreAggregatedCost) - cost already from aggregate/targetStats
                                                    
                                                    // Proportional fallback: when token cost is 0 but offer has cost, distribute by visitors (no extra queries)
                                                    if ($tokenCost == 0 && $offerVisitors > 0 && $offerCost > 0) {
                                                        $tokenCost = ($tokenVisitors / $offerVisitors) * $offerCost;
                                                    }
                                                    
                                                    $tokenCpc = $tokenVisitors > 0 ? $tokenCost / $tokenVisitors : 0;
                                                    $tokenCr = $tokenVisitors > 0 ? ($tokenConversions / $tokenVisitors) * 100 : 0;
                                                    $tokenCpa = $tokenConversions > 0 ? $tokenCost / $tokenConversions : 0;
                                                    $tokenPl = $tokenRevenue - $tokenCost;
                                                    $tokenRoi = $tokenCost > 0 ? (($tokenPl / $tokenCost) * 100) : 0;
                                                    
                                                    $tokenName = $tokenValue;
                                                    ?>
                                                    <tr data-block-id="offer-<?= $offerId ?>" data-sort-offer_name="<?= htmlspecialchars($tokenName) ?>" data-sort-visitors="<?= (int)$tokenVisitors ?>" data-sort-cost="<?= (float)$tokenCost ?>" data-sort-cpc="<?= (float)$tokenCpc ?>" data-sort-lp_clicks="<?= (int)$tokenLpClicks ?>" data-sort-conversions="<?= (int)$tokenConversions ?>" data-sort-cr="<?= (float)$tokenCr ?>" data-sort-cpa="<?= (float)$tokenCpa ?>" data-sort-revenue="<?= (float)$tokenRevenue ?>" data-sort-pl="<?= (float)$tokenPl ?>" data-sort-roi="<?= (float)$tokenRoi ?>" style="background: #f8f9fa; border-left: 3px solid #3d5a26;">
                                                        <td style="padding-left: <?= $indent + 30 ?>px;">
                                                            <span style="color: #5a7a3a; font-size: 13px;"><?= $prefix ?></span>
                                                            <strong style="color: #5a7a3a; font-size: 13px;"><?= htmlspecialchars($tokenName) ?></strong>
                                                        </td>
                                                        <td><?= number_format($tokenVisitors) ?></td>
                                                        <td><?= Formatter::formatCurrency($tokenCost, $userCurrency) ?></td>
                                                        <td><?= Formatter::formatCurrency($tokenCpc, $userCurrency, 3) ?></td>
                                                        <td><?= number_format($tokenLpClicks) ?></td>
                                                        <td><?= number_format($tokenConversions) ?></td>
                                                        <td><?= number_format($tokenCr, 2) ?>%</td>
                                                        <td><?= Formatter::formatCurrency($tokenCpa, $userCurrency, 3) ?></td>
                                                        <td style="color: <?= $tokenRevenue >= 0 ? '#28a745' : '#d32f2f' ?>;">
                                                            <?= Formatter::formatCurrency($tokenRevenue, $userCurrency) ?>
                                                        </td>
                                                        <td style="color: <?= $tokenPl >= 0 ? '#28a745' : '#d32f2f' ?>;">
                                                            <?= Formatter::formatCurrency($tokenPl, $userCurrency) ?>
                                                        </td>
                                                        <td style="color: <?= $tokenRoi >= 0 ? '#28a745' : '#d32f2f' ?>;">
                                                            <?= number_format($tokenRoi, 2) ?>%
                                                        </td>
                                                    </tr>
                                                    <?php
                                                } else {
                                                    $aggStats = $aggregateStats($data);
                                                    $tokenVisitors = $aggStats['visitors'];
                                                    $tokenLpClicks = $aggStats['lp_clicks'];
                                                    $tokenConversions = $aggStats['conversions'];
                                                    $tokenRevenue = $aggStats['revenue'];
                                                    // PERFORMANCE: Use pre-aggregated cost from children when available to avoid N× getAggregatedCost and raw clicks queries
                                                    $tokenCost = (float)($aggStats['cost'] ?? 0);
                                                    if ($tokenCost == 0) {
                                                    // Calculate cost using getAggregatedCost with token filters when no cost from children
                                                    $tokenFilters = [];
                                                    $tokenFilterParams = [];
                                                    
                                                    // Build filters for all tokens in the current path
                                                    for ($i = 0; $i < count($currentPath) && $i < count($selectedTokens); $i++) {
                                                        $tokenParam = $selectedTokens[$i];
                                                        $tokenVal = $currentPath[$i];
                                                        
                                                        // Build filter for this token using universal token filter builder
                                                        // PERFORMANCE: Automatically uses best available method (generated columns, built-in columns, or JSON_EXTRACT)
                                                        // FUTURE-PROOF: Works for any token type, current or future
                                                        $tokenFilter = buildTokenFilter($tokenParam, $tokenVal, $db, $utcDateFrom, $utcDateTo);
                                                        if ($tokenFilter) {
                                                            $tokenFilters[] = $tokenFilter[0];
                                                            $tokenFilterParams = array_merge($tokenFilterParams, $tokenFilter[1]);
                                                        }
                                                    }
                                                    
                                                    // Add offer filter
                                                    $tokenFilters[] = "offer_id = ?";
                                                    $tokenFilterParams[] = $offerId;
                                                    
                                                    $tokenCost = 0;
                                                    
                                                    // Check if there's an adset level in the path above this level
                                                    $hasAdsetInPath = false;
                                                    $adsetIdFromPath = null;
                                                    $adsetNameFromPath = null;
                                                    
                                                    // Find adset_id or adset_name in the path
                                                    $adsetTokenIndex = array_search('adset_id', $selectedTokens);
                                                    $adsetNameTokenIndex = array_search('adset_name', $selectedTokens);
                                                    
                                                    if ($adsetTokenIndex !== false && $adsetTokenIndex < count($currentPath)) {
                                                        $hasAdsetInPath = true;
                                                        $adsetIdFromPath = $currentPath[$adsetTokenIndex];
                                                    } elseif ($adsetNameTokenIndex !== false && $adsetNameTokenIndex < count($currentPath)) {
                                                        $hasAdsetInPath = true;
                                                        $adsetNameFromPath = $currentPath[$adsetNameTokenIndex];
                                                    }
                                                    
                                                    // If there's an adset level above this, distribute proportionally
                                                    if ($hasAdsetInPath) {
                                                        $adsetTotalCost = 0;
                                                        $adsetTotalVisitors = 0;
                                                        
                                                        // Try to get adset data from parent first
                                                        if (!empty($parentAdsetData)) {
                                                            $adsetTotalCost = (float)($parentAdsetData['cost'] ?? 0);
                                                            $adsetTotalVisitors = (int)($parentAdsetData['visitors'] ?? 0);
                                                        }
                                                        
                                                        // If we don't have parent adset data, calculate it
                                                        if (($adsetTotalCost <= 0 || $adsetTotalVisitors <= 0)) {
                                                            $adsetFilters = [];
                                                            $adsetFilterParams = [];
                                                            
                                                            if (!empty($adsetIdFromPath)) {
                                                                // Use indexed column
                                                                $adsetFilters[] = "cl.adset_id = ?";
                                                                $adsetFilterParams[] = $adsetIdFromPath;
                                                            } elseif (!empty($adsetNameFromPath)) {
                                                                // PERFORMANCE: Convert adset_name to adset_id for indexed query
                                                                $adsetIdFromName = getAdsetIdFromName($db, $adsetNameFromPath, $utcDateFrom, $utcDateTo);
                                                                if ($adsetIdFromName) {
                                                                    $adsetFilters[] = "cl.adset_id = ?";
                                                                    $adsetFilterParams[] = $adsetIdFromName;
                                                                } else {
                                                                    // Fallback: if no adset_id found, use JSON_EXTRACT (shouldn't happen in practice)
                                                                $adsetFilters[] = "JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.adset_name')) = ?";
                                                                $adsetFilterParams[] = $adsetNameFromPath;
                                                                }
                                                            }
                                                            
                                                            if (!empty($adsetFilters)) {
                                                                $adsetFilters[] = "offer_id = ?";
                                                                $adsetFilterParams[] = $offerId;
                                                                $adsetFilterStr = "AND " . implode(" AND ", $adsetFilters);
                                                                $adsetTotalCost = $costAggregator->getAggregatedCost($utcDateFrom, $utcDateTo, $adsetFilterStr, $adsetFilterParams, $userTimezone);
                                                                
                                                                // Get total visitors for the adset
                                                                if ($adsetTotalVisitors <= 0) {
                                                                    if (!empty($adsetIdFromPath)) {
                                                                        // Query by adset_id - use indexed column
                                                                        $adsetVisitorsQuery = $db->prepare("
                                                                            SELECT COUNT(DISTINCT cl.id) as total_visitors
                                                                            FROM {$clicksTable} cl
                                                                            WHERE cl.ts >= ? AND cl.ts <= ?
                                                                            AND cl.adset_id = ?
                                                                            AND cl.offer_id = ?
                                                                            AND cl.ad_id IS NOT NULL
                                                                            -- PERFORMANCE: Use generated columns instead of JSON_EXTRACT for index usage
                                                                        ");
                                                                        $adsetVisitorsQuery->bind_param('sssi', $utcDateFrom, $utcDateTo, $adsetIdFromPath, $offerId);
                                                                    } else {
                                                                        $adsetVisitorsQuery = $db->prepare("
                                                                            SELECT COUNT(DISTINCT cl.id) as total_visitors
                                                                            FROM {$clicksTable} cl
                                                                            WHERE cl.ts >= ? AND cl.ts <= ?
                                                                            AND JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.adset_name')) = ?
                                                                            AND cl.offer_id = ?
                                                                            AND JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.ad_id')) IS NOT NULL
                                                                            AND JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.ad_id')) != ''
                                                                            AND JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.ad_id')) != 'null'
                                                                            AND JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.ad_id')) NOT LIKE '{{%'
                                                                            AND JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.ad_id')) NOT LIKE '{ts:%'
                                                                            AND JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.adset_id')) IS NOT NULL
                                                                            AND JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.adset_id')) != ''
                                                                            AND JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.adset_id')) != 'null'
                                                                            AND JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.adset_id')) NOT LIKE '{{%'
                                                                            AND JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.adset_id')) NOT LIKE '{ts:%'
                                                                        ");
                                                                        $adsetVisitorsQuery->bind_param('sssi', $utcDateFrom, $utcDateTo, $adsetNameFromPath, $offerId);
                                                                    }
                                                                    $adsetVisitorsQuery->execute();
                                                                    $adsetVisitorsResult = $adsetVisitorsQuery->get_result()->fetch_assoc();
                                                                    $adsetTotalVisitors = (int)($adsetVisitorsResult['total_visitors'] ?? 0);
                                                                }
                                                            }
                                                        }
                                                        
                                                        // Distribute proportionally if we have valid data
                                                        if ($adsetTotalVisitors > 0 && $adsetTotalCost > 0) {
                                                            $tokenCost = ($tokenVisitors / $adsetTotalVisitors) * $adsetTotalCost;
                                                        } else {
                                                            // Fallback: use getAggregatedCost
                                                            if (!empty($tokenFilters)) {
                                                                $filterStr = "AND " . implode(" AND ", $tokenFilters);
                                                                $tokenCost = $costAggregator->getAggregatedCost($utcDateFrom, $utcDateTo, $filterStr, $tokenFilterParams, $userTimezone);
                                                            } else {
                                                                $tokenCost = $aggStats['cost'];
                                                            }
                                                        }
                                                    } else {
                                                        // For non-ad levels without adset in path, distribute proportionally from parent level
                                                        // Get parent level total cost and visitors to distribute proportionally
                                                        $parentTotalCost = 0;
                                                        $parentTotalVisitors = 0;
                                                        
                                                        // Build parent filter (all tokens except the current one)
                                                        $parentFilters = [];
                                                        $parentFilterParams = [];
                                                        
                                                        // Add all parent tokens (everything before current level)
                                                        // PERFORMANCE: Use universal filter builder for ALL token types (not just ad_id/adset_id)
                                                        // FUTURE-PROOF: Works for any current or future token type automatically
                                                        for ($i = 0; $i < $level && $i < count($selectedTokens); $i++) {
                                                            $parentTokenParam = $selectedTokens[$i];
                                                            $parentTokenVal = $currentPath[$i];
                                                            
                                                            // Use universal filter builder for ALL token types
                                                            $parentFilter = buildTokenFilter($parentTokenParam, $parentTokenVal, $db, $utcDateFrom, $utcDateTo);
                                                            if ($parentFilter) {
                                                                $parentFilters[] = $parentFilter[0];
                                                                $parentFilterParams = array_merge($parentFilterParams, $parentFilter[1]);
                                                            }
                                                        }
                                                        
                                                        // Add offer filter
                                                        $parentFilters[] = "offer_id = ?";
                                                        $parentFilterParams[] = $offerId;
                                                        
                                                        if (!empty($parentFilters)) {
                                                            $parentFilterStr = "AND " . implode(" AND ", $parentFilters);
                                                            $parentTotalCost = $costAggregator->getAggregatedCost($utcDateFrom, $utcDateTo, $parentFilterStr, $parentFilterParams, $userTimezone);
                                                            
                                                            // Get parent total visitors (exclude Facebook approval clicks to match breakdown counts)
                                                            $parentVisitorsQuery = $db->prepare("
                                                                SELECT COUNT(DISTINCT CASE 
                                                                    -- For Facebook traffic source, require both ad_id AND adset_id (exclude invalid clicks)
                                                                    WHEN (" . ($trafficSourceColumnExists ? "cl.traffic_source_id = 4" : "c_parent.traffic_source_id = 4") . ") THEN 
                                                                        CASE 
                                                                            WHEN cl.ad_id IS NOT NULL 
                                                                                AND cl.adset_id IS NOT NULL
                                                                            THEN cl.id
                                                                            ELSE NULL
                                                                        END
                                                                    -- For other traffic sources, count all clicks
                                                                    ELSE cl.id
                                                                END) as total_visitors
                                                                FROM {$clicksTable} cl
                                                                " . ($trafficSourceColumnExists ? "" : "INNER JOIN campaigns c_parent ON cl.campaign_id = c_parent.id") . "
                                                                WHERE cl.ts >= ? AND cl.ts <= ?
                                                                " . $parentFilterStr . "
                                                            ");
                                                            // PERFORMANCE: Determine bind parameter types based on actual parameter values
                                                            // Since universal filter builder may return different types (int for IDs, string for names/custom tokens),
                                                            // we need to detect types from the actual parameter values
                                                            $parentVisitorsBindTypes = 'ss';
                                                            foreach ($parentFilterParams as $param) {
                                                                // Check if parameter is numeric (integer) or string
                                                                if (is_numeric($param) && (int)$param == $param) {
                                                                    $parentVisitorsBindTypes .= 'i';
                                                            } else {
                                                                    $parentVisitorsBindTypes .= 's';
                                                                }
                                                            }
                                                            $parentVisitorsBindValues = array_merge([$utcDateFrom, $utcDateTo], $parentFilterParams);
                                                            $parentVisitorsQuery->bind_param($parentVisitorsBindTypes, ...$parentVisitorsBindValues);
                                                            $parentVisitorsQuery->execute();
                                                            $parentVisitorsResult = $parentVisitorsQuery->get_result()->fetch_assoc();
                                                            $parentTotalVisitors = (int)($parentVisitorsResult['total_visitors'] ?? 0);
                                                        } else {
                                                            // No parent filters - use offer total
                                                            $offerFilterStr = "AND offer_id = ?";
                                                            $parentTotalCost = $costAggregator->getAggregatedCost($utcDateFrom, $utcDateTo, $offerFilterStr, [$offerId], $userTimezone);
                                                            
                                                            // Get parent total visitors (exclude Facebook approval clicks to match breakdown counts)
                                                            $parentVisitorsQuery = $db->prepare("
                                                                SELECT COUNT(DISTINCT CASE 
                                                                    -- For Facebook traffic source, require both ad_id AND adset_id (exclude invalid clicks)
                                                                    WHEN (" . ($trafficSourceColumnExists ? "cl.traffic_source_id = 4" : "c_parent.traffic_source_id = 4") . ") THEN 
                                                                        CASE 
                                                                            WHEN cl.ad_id IS NOT NULL 
                                                                                AND cl.adset_id IS NOT NULL
                                                                            THEN cl.id
                                                                            ELSE NULL
                                                                        END
                                                                    -- For other traffic sources, count all clicks
                                                                    ELSE cl.id
                                                                END) as total_visitors
                                                                FROM {$clicksTable} cl
                                                                " . ($trafficSourceColumnExists ? "" : "INNER JOIN campaigns c_parent ON cl.campaign_id = c_parent.id") . "
                                                                WHERE cl.ts >= ? AND cl.ts <= ?
                                                                AND cl.offer_id = ?
                                                            ");
                                                            $parentVisitorsQuery->bind_param('ssi', $utcDateFrom, $utcDateTo, $offerId);
                                                            $parentVisitorsQuery->execute();
                                                            $parentVisitorsResult = $parentVisitorsQuery->get_result()->fetch_assoc();
                                                            $parentTotalVisitors = (int)($parentVisitorsResult['total_visitors'] ?? 0);
                                                        }
                                                        
                                                        // Distribute proportionally if we have valid data
                                                        if ($parentTotalVisitors > 0 && $parentTotalCost > 0) {
                                                            $tokenCost = ($tokenVisitors / $parentTotalVisitors) * $parentTotalCost;
                                                        } else {
                                                            // Fallback: use getAggregatedCost
                                                            if (!empty($tokenFilters)) {
                                                                $filterStr = "AND " . implode(" AND ", $tokenFilters);
                                                                $tokenCost = $costAggregator->getAggregatedCost($utcDateFrom, $utcDateTo, $filterStr, $tokenFilterParams, $userTimezone);
                                                            } else {
                                                                $tokenCost = $aggStats['cost'];
                                                            }
                                                        }
                                                    }
                                                    } // end if ($tokenCost == 0) - use pre-aggregated cost from children
                                                    
                                                    // Store adset data for children if this is an adset level
                                                    // If this is an adset level, store its cost and visitors to pass down
                                                    $currentAdsetData = $parentAdsetData; // Default: pass parent data down
                                                    $currentTokenParam = $selectedTokens[$level] ?? null;
                                                    if ($currentTokenParam === 'adset_id' || $currentTokenParam === 'adset_name') {
                                                        // This is an adset level - store its data for children (ads)
                                                        $currentAdsetData = [
                                                            'cost' => $tokenCost,
                                                            'visitors' => $tokenVisitors
                                                        ];
                                                    }
                                                    
                                                    $tokenCpc = $tokenVisitors > 0 ? $tokenCost / $tokenVisitors : 0;
                                                    $tokenCr = $tokenVisitors > 0 ? ($tokenConversions / $tokenVisitors) * 100 : 0;
                                                    $tokenCpa = $tokenConversions > 0 ? $tokenCost / $tokenConversions : 0;
                                                    $tokenPl = $tokenRevenue - $tokenCost;
                                                    $tokenRoi = $tokenCost > 0 ? (($tokenPl / $tokenCost) * 100) : 0;
                                                    
                                                    $tokenName = $tokenValue;
                                                    if ($level < count($selectedTokenNames)) {
                                                        $tokenName = ($selectedTokenNames[$level] ?? '') . ': ' . $tokenValue;
                                                    }
                                                    ?>
                                                    <tr data-block-id="offer-<?= $offerId ?>" data-sort-offer_name="<?= htmlspecialchars($tokenName) ?>" data-sort-visitors="<?= (int)$tokenVisitors ?>" data-sort-cost="<?= (float)$tokenCost ?>" data-sort-cpc="<?= (float)$tokenCpc ?>" data-sort-lp_clicks="<?= (int)$tokenLpClicks ?>" data-sort-conversions="<?= (int)$tokenConversions ?>" data-sort-cr="<?= (float)$tokenCr ?>" data-sort-cpa="<?= (float)$tokenCpa ?>" data-sort-revenue="<?= (float)$tokenRevenue ?>" data-sort-pl="<?= (float)$tokenPl ?>" data-sort-roi="<?= (float)$tokenRoi ?>" style="background: #f8f9fa; border-left: 3px solid #3d5a26;">
                                                        <td style="padding-left: <?= $indent + 30 ?>px;">
                                                            <span style="color: #5a7a3a; font-size: 13px;"><?= $prefix ?></span>
                                                            <strong style="color: #5a7a3a; font-size: 13px;"><?= htmlspecialchars($tokenName) ?></strong>
                                                        </td>
                                                        <td><?= number_format($tokenVisitors) ?></td>
                                                        <td><?= Formatter::formatCurrency($tokenCost, $userCurrency) ?></td>
                                                        <td><?= Formatter::formatCurrency($tokenCpc, $userCurrency, 3) ?></td>
                                                        <td><?= number_format($tokenLpClicks) ?></td>
                                                        <td><?= number_format($tokenConversions) ?></td>
                                                        <td><?= number_format($tokenCr, 2) ?>%</td>
                                                        <td><?= Formatter::formatCurrency($tokenCpa, $userCurrency, 3) ?></td>
                                                        <td style="color: <?= $tokenRevenue >= 0 ? '#28a745' : '#d32f2f' ?>;">
                                                            <?= Formatter::formatCurrency($tokenRevenue, $userCurrency) ?>
                                                        </td>
                                                        <td style="color: <?= $tokenPl >= 0 ? '#28a745' : '#d32f2f' ?>;">
                                                            <?= Formatter::formatCurrency($tokenPl, $userCurrency) ?>
                                                        </td>
                                                        <td style="color: <?= $tokenRoi >= 0 ? '#28a745' : '#d32f2f' ?>;">
                                                            <?= number_format($tokenRoi, 2) ?>%
                                                        </td>
                                                    </tr>
                                                    <?php
                                                    $renderOfferTokenBreakdown($data, $level + 1, $currentPath, $currentAdsetData);
                                                }
                                            }
                                        };
                                        if (!empty($offerTokenPerformance[$offerId])) {
                                            $renderOfferTokenBreakdown($offerTokenPerformance[$offerId], 0, [], null);
                                        }
                                        ?>
                                    <?php endif; ?>
                                    <?php if ((int)($offer['invalid_clicks'] ?? 0) > 0): ?>
                                        <tr data-block-id="offer-<?= $offerId ?>" style="background: #fff3e0;">
                                            <td style="padding-left: 40px; color: #ff9800; font-weight: 500;">
                                                <span style="opacity: 0.0;">└─</span> ⚠️ Invalid/Filtered Clicks (FB Test Clicks)
                                            </td>
                                            <td><?= number_format((int)($offer['invalid_clicks'] ?? 0)) ?></td>
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
        </div>
        
        <!-- Mobile Offer Performance Cards -->
        <div class="mobile-offer-performance-cards mobile-only">
            <?php if (empty($offerPerformance)): ?>
                <div class="empty-state">
                    No offers found in campaigns for the selected filters. Add offers to campaigns to see performance data.
                </div>
            <?php else: ?>
                <?php
                $mobileTotalCpc = $totalVisitors > 0 ? $totalCost / $totalVisitors : 0;
                $mobileTotalCr = $totalVisitors > 0 ? ($totalConversions / $totalVisitors) * 100 : 0;
                $mobileTotalCpa = $totalConversions > 0 ? $totalCost / $totalConversions : 0;
                $mobileTotalPl = $totalRevenue - $totalCost;
                $mobileTotalRoi = $totalCost > 0 ? (($mobileTotalPl / $totalCost) * 100) : 0;
                ?>
                <div class="mobile-offer-performance-card" style="background: #e8f0e8; border-left: 4px solid #3d5a26;">
                    <div class="offer-name" style="font-weight: 700;">Total</div>
                    <div class="offer-stats">
                        <div class="offer-stat"><span class="offer-stat-label">Visitors</span><span class="offer-stat-value"><?= number_format($totalVisitors) ?></span></div>
                        <div class="offer-stat"><span class="offer-stat-label">Cost</span><span class="offer-stat-value"><?= Formatter::formatCurrency($totalCost, $userCurrency) ?></span></div>
                        <div class="offer-stat"><span class="offer-stat-label">CPC</span><span class="offer-stat-value"><?= Formatter::formatCurrency($mobileTotalCpc, $userCurrency) ?></span></div>
                        <div class="offer-stat"><span class="offer-stat-label">LP Clicks</span><span class="offer-stat-value"><?= number_format($totalClicks) ?></span></div>
                        <div class="offer-stat"><span class="offer-stat-label">Conversions</span><span class="offer-stat-value"><?= number_format($totalConversions) ?></span></div>
                        <div class="offer-stat"><span class="offer-stat-label">CR</span><span class="offer-stat-value"><?= number_format($mobileTotalCr, 2) ?>%</span></div>
                        <div class="offer-stat"><span class="offer-stat-label">CPA</span><span class="offer-stat-value"><?= Formatter::formatCurrency($mobileTotalCpa, $userCurrency) ?></span></div>
                        <div class="offer-stat"><span class="offer-stat-label">Revenue</span><span class="offer-stat-value"><?= Formatter::formatCurrency($totalRevenue, $userCurrency) ?></span></div>
                        <div class="offer-stat"><span class="offer-stat-label">P/L</span><span class="offer-stat-value <?= $mobileTotalPl >= 0 ? 'positive' : 'negative' ?>"><?= Formatter::formatCurrency($mobileTotalPl, $userCurrency) ?></span></div>
                        <div class="offer-stat"><span class="offer-stat-label">ROI</span><span class="offer-stat-value <?= $mobileTotalRoi >= 0 ? 'positive' : 'negative' ?>"><?= number_format($mobileTotalRoi, 1) ?>%</span></div>
                    </div>
                </div>
                <?php foreach ($offerPerformance as $offer): ?>
                    <?php
                    $offerId = (int)($offer['offer_id'] ?? 0);
                    
                    // In target mode, use aggregated cost from breakdown if available (more accurate)
                    if ($viewMode === 'target' && !empty($selectedTokens) && !empty($offerTokenPerformance[$offerId])) {
                        // Aggregate stats from nested target breakdown
                        $aggregateTargetStats = function($data) use (&$aggregateTargetStats) {
                            if (isset($data['visitors'])) {
                                return [
                                    'visitors' => (int)($data['visitors'] ?? 0),
                                    'cost' => (float)($data['cost'] ?? 0),
                                    'lp_clicks' => (int)($data['lp_clicks'] ?? 0),
                                    'conversions' => (int)($data['conversions'] ?? 0),
                                    'revenue' => (float)($data['revenue'] ?? 0)
                                ];
                            }
                            $aggregated = ['visitors' => 0, 'cost' => 0, 'lp_clicks' => 0, 'conversions' => 0, 'revenue' => 0];
                            foreach ($data as $value) {
                                if (is_array($value)) {
                                    $childStats = $aggregateTargetStats($value);
                                    $aggregated['visitors'] += $childStats['visitors'];
                                    $aggregated['cost'] += $childStats['cost'];
                                    $aggregated['lp_clicks'] += $childStats['lp_clicks'];
                                    $aggregated['conversions'] += $childStats['conversions'];
                                    $aggregated['revenue'] += $childStats['revenue'];
                                }
                            }
                            return $aggregated;
                        };
                        $targetStats = $aggregateTargetStats($offerTokenPerformance[$offerId]);
                        $visitors = $targetStats['visitors'];
                        $cost = $targetStats['cost'];
                        $lpClicks = $targetStats['lp_clicks'];
                        $conversions = $targetStats['conversions'];
                        $revenue = $targetStats['revenue'];
                    } else {
                        // Standard mode - use SQL query results
                        $visitors = (int)($offer['visitors'] ?? 0);
                        $cost = (float)($offer['cost'] ?? 0);
                        $lpClicks = (int)($offer['lp_clicks'] ?? 0);
                        $conversions = (int)($offer['conversions'] ?? 0);
                        $revenue = (float)($offer['revenue'] ?? 0);
                    }
                    
                    $cpc = $visitors > 0 ? $cost / $visitors : 0;
                    $cr = $visitors > 0 ? ($conversions / $visitors) * 100 : 0;
                    $cpa = $conversions > 0 ? $cost / $conversions : 0;
                    $pl = $revenue - $cost;
                    $roi = $cost > 0 ? (($pl / $cost) * 100) : 0;
                    ?>
                    <div class="mobile-offer-performance-card">
                        <div class="offer-name"><?= htmlspecialchars($offer['offer_name']) ?></div>
                        <div class="offer-stats">
                            <div class="offer-stat">
                                <span class="offer-stat-label">Visitors</span>
                                <span class="offer-stat-value"><?= number_format($visitors) ?></span>
                            </div>
                            <div class="offer-stat">
                                <span class="offer-stat-label">Cost</span>
                                <span class="offer-stat-value"><?= Formatter::formatCurrency($cost, $userCurrency) ?></span>
                            </div>
                            <div class="offer-stat">
                                <span class="offer-stat-label">CPC</span>
                                <span class="offer-stat-value"><?= Formatter::formatCurrency($cpc, $userCurrency) ?></span>
                            </div>
                            <div class="offer-stat">
                                <span class="offer-stat-label">LP Clicks</span>
                                <span class="offer-stat-value"><?= number_format($lpClicks) ?></span>
                            </div>
                            <div class="offer-stat">
                                <span class="offer-stat-label">Conversions</span>
                                <span class="offer-stat-value"><?= number_format($conversions) ?></span>
                            </div>
                            <div class="offer-stat">
                                <span class="offer-stat-label">CR</span>
                                <span class="offer-stat-value"><?= number_format($cr, 2) ?>%</span>
                            </div>
                            <div class="offer-stat">
                                <span class="offer-stat-label">CPA</span>
                                <span class="offer-stat-value"><?= Formatter::formatCurrency($cpa, $userCurrency) ?></span>
                            </div>
                            <div class="offer-stat">
                                <span class="offer-stat-label">Revenue</span>
                                <span class="offer-stat-value"><?= Formatter::formatCurrency($revenue, $userCurrency) ?></span>
                            </div>
                            <div class="offer-stat">
                                <span class="offer-stat-label">P/L</span>
                                <span class="offer-stat-value <?= $pl >= 0 ? 'positive' : 'negative' ?>"><?= Formatter::formatCurrency($pl, $userCurrency) ?></span>
                            </div>
                            <div class="offer-stat">
                                <span class="offer-stat-label">ROI</span>
                                <span class="offer-stat-value <?= $roi >= 0 ? 'positive' : 'negative' ?>"><?= number_format($roi, 1) ?>%</span>
                            </div>
                        </div>
                        <?php if ($viewMode === 'target' && !empty($selectedTokens) && !empty($offerTokenPerformance[$offerId])): ?>
                            <?php
                            // Helper function to aggregate stats from nested data
                            $aggregateStats = function($data) use (&$aggregateStats) {
                                if (isset($data['visitors'])) {
                                    return [
                                        'visitors' => (int)($data['visitors'] ?? 0),
                                        'cost' => (float)($data['cost'] ?? 0),
                                        'lp_clicks' => (int)($data['lp_clicks'] ?? 0),
                                        'conversions' => (int)($data['conversions'] ?? 0),
                                        'revenue' => (float)($data['revenue'] ?? 0)
                                    ];
                                }
                                $aggregated = [
                                    'visitors' => 0,
                                    'cost' => 0,
                                    'lp_clicks' => 0,
                                    'conversions' => 0,
                                    'revenue' => 0
                                ];
                                foreach ($data as $value) {
                                    if (is_array($value)) {
                                        $childStats = $aggregateStats($value);
                                        $aggregated['visitors'] += $childStats['visitors'];
                                        $aggregated['cost'] += $childStats['cost'];
                                        $aggregated['lp_clicks'] += $childStats['lp_clicks'];
                                        $aggregated['conversions'] += $childStats['conversions'];
                                        $aggregated['revenue'] += $childStats['revenue'];
                                    }
                                }
                                return $aggregated;
                            };
                            
                            // Recursive function to render nested token breakdowns for mobile
                            $renderMobileOfferTokenBreakdown = function($tokenData, $level = 0, $path = []) use (&$renderMobileOfferTokenBreakdown, &$aggregateStats, $selectedTokens, $selectedTokenNames, $userCurrency, $visitors, $cost) {
                                foreach ($tokenData as $tokenValue => $data) {
                                    $currentPath = array_merge($path, [$tokenValue]);
                                    $prefix = str_repeat('└─ ', $level);
                                    
                                    $isLeaf = isset($data['visitors']);
                                    
                                    if ($isLeaf) {
                                        $tokenVisitors = (int)($data['visitors'] ?? 0);
                                        $tokenCost = (float)($data['cost'] ?? 0);
                                        if ($tokenCost == 0 && $visitors > 0 && $cost > 0) {
                                            $tokenCost = ($tokenVisitors / $visitors) * $cost;
                                        }
                                        $tokenLpClicks = (int)($data['lp_clicks'] ?? 0);
                                        $tokenConversions = (int)($data['conversions'] ?? 0);
                                        $tokenRevenue = (float)($data['revenue'] ?? 0);
                                        
                                        $tokenCpc = $tokenVisitors > 0 ? $tokenCost / $tokenVisitors : 0;
                                        $tokenCr = $tokenVisitors > 0 ? ($tokenConversions / $tokenVisitors) * 100 : 0;
                                        $tokenCpa = $tokenConversions > 0 ? $tokenCost / $tokenConversions : 0;
                                        $tokenPl = $tokenRevenue - $tokenCost;
                                        $tokenRoi = $tokenCost > 0 ? (($tokenPl / $tokenCost) * 100) : 0;
                                        
                                        $tokenName = $tokenValue;
                                        ?>
                                        <div class="mobile-token-breakdown token-level-<?= $level ?>">
                                            <div class="token-name">
                                                <?= htmlspecialchars($tokenName) ?>
                                            </div>
                                            <div class="offer-stats">
                                                <div class="offer-stat">
                                                    <span class="offer-stat-label">Visitors</span>
                                                    <span class="offer-stat-value"><?= number_format($tokenVisitors) ?></span>
                                                </div>
                                                <div class="offer-stat">
                                                    <span class="offer-stat-label">Cost</span>
                                                    <span class="offer-stat-value"><?= Formatter::formatCurrency($tokenCost, $userCurrency) ?></span>
                                                </div>
                                                <div class="offer-stat">
                                                    <span class="offer-stat-label">CPC</span>
                                                    <span class="offer-stat-value"><?= Formatter::formatCurrency($tokenCpc, $userCurrency, 3) ?></span>
                                                </div>
                                                <div class="offer-stat">
                                                    <span class="offer-stat-label">LP Clicks</span>
                                                    <span class="offer-stat-value"><?= number_format($tokenLpClicks) ?></span>
                                                </div>
                                                <div class="offer-stat">
                                                    <span class="offer-stat-label">Conversions</span>
                                                    <span class="offer-stat-value"><?= number_format($tokenConversions) ?></span>
                                                </div>
                                                <div class="offer-stat">
                                                    <span class="offer-stat-label">CR</span>
                                                    <span class="offer-stat-value"><?= number_format($tokenCr, 2) ?>%</span>
                                                </div>
                                                <div class="offer-stat">
                                                    <span class="offer-stat-label">CPA</span>
                                                    <span class="offer-stat-value"><?= Formatter::formatCurrency($tokenCpa, $userCurrency, 3) ?></span>
                                                </div>
                                                <div class="offer-stat">
                                                    <span class="offer-stat-label">Revenue</span>
                                                    <span class="offer-stat-value"><?= Formatter::formatCurrency($tokenRevenue, $userCurrency) ?></span>
                                                </div>
                                                <div class="offer-stat">
                                                    <span class="offer-stat-label">P/L</span>
                                                    <span class="offer-stat-value <?= $tokenPl >= 0 ? 'positive' : 'negative' ?>"><?= Formatter::formatCurrency($tokenPl, $userCurrency) ?></span>
                                                </div>
                                                <div class="offer-stat">
                                                    <span class="offer-stat-label">ROI</span>
                                                    <span class="offer-stat-value <?= $tokenRoi >= 0 ? 'positive' : 'negative' ?>"><?= number_format($tokenRoi, 2) ?>%</span>
                                                </div>
                                            </div>
                                        </div>
                                        <?php
                                    } else {
                                        $aggStats = $aggregateStats($data);
                                        $tokenVisitors = $aggStats['visitors'];
                                        $tokenCost = $aggStats['cost'];
                                        $tokenLpClicks = $aggStats['lp_clicks'];
                                        $tokenConversions = $aggStats['conversions'];
                                        $tokenRevenue = $aggStats['revenue'];
                                        
                                        $tokenCpc = $tokenVisitors > 0 ? $tokenCost / $tokenVisitors : 0;
                                        $tokenCr = $tokenVisitors > 0 ? ($tokenConversions / $tokenVisitors) * 100 : 0;
                                        $tokenCpa = $tokenConversions > 0 ? $tokenCost / $tokenConversions : 0;
                                        $tokenPl = $tokenRevenue - $tokenCost;
                                        $tokenRoi = $tokenCost > 0 ? (($tokenPl / $tokenCost) * 100) : 0;
                                        
                                        $tokenName = $tokenValue;
                                        if ($level < count($selectedTokenNames)) {
                                            $tokenName = ($selectedTokenNames[$level] ?? '') . ': ' . $tokenValue;
                                        }
                                        ?>
                                        <div class="mobile-token-breakdown token-level-<?= $level ?>">
                                            <div class="token-name">
                                                <?= htmlspecialchars($tokenName) ?>
                                            </div>
                                            <div class="offer-stats">
                                                <div class="offer-stat">
                                                    <span class="offer-stat-label">Visitors</span>
                                                    <span class="offer-stat-value"><?= number_format($tokenVisitors) ?></span>
                                                </div>
                                                <div class="offer-stat">
                                                    <span class="offer-stat-label">Cost</span>
                                                    <span class="offer-stat-value"><?= Formatter::formatCurrency($tokenCost, $userCurrency) ?></span>
                                                </div>
                                                <div class="offer-stat">
                                                    <span class="offer-stat-label">CPC</span>
                                                    <span class="offer-stat-value"><?= Formatter::formatCurrency($tokenCpc, $userCurrency, 3) ?></span>
                                                </div>
                                                <div class="offer-stat">
                                                    <span class="offer-stat-label">LP Clicks</span>
                                                    <span class="offer-stat-value"><?= number_format($tokenLpClicks) ?></span>
                                                </div>
                                                <div class="offer-stat">
                                                    <span class="offer-stat-label">Conversions</span>
                                                    <span class="offer-stat-value"><?= number_format($tokenConversions) ?></span>
                                                </div>
                                                <div class="offer-stat">
                                                    <span class="offer-stat-label">CR</span>
                                                    <span class="offer-stat-value"><?= number_format($tokenCr, 2) ?>%</span>
                                                </div>
                                                <div class="offer-stat">
                                                    <span class="offer-stat-label">CPA</span>
                                                    <span class="offer-stat-value"><?= Formatter::formatCurrency($tokenCpa, $userCurrency, 3) ?></span>
                                                </div>
                                                <div class="offer-stat">
                                                    <span class="offer-stat-label">Revenue</span>
                                                    <span class="offer-stat-value"><?= Formatter::formatCurrency($tokenRevenue, $userCurrency) ?></span>
                                                </div>
                                                <div class="offer-stat">
                                                    <span class="offer-stat-label">P/L</span>
                                                    <span class="offer-stat-value <?= $tokenPl >= 0 ? 'positive' : 'negative' ?>"><?= Formatter::formatCurrency($tokenPl, $userCurrency) ?></span>
                                                </div>
                                                <div class="offer-stat">
                                                    <span class="offer-stat-label">ROI</span>
                                                    <span class="offer-stat-value <?= $tokenRoi >= 0 ? 'positive' : 'negative' ?>"><?= number_format($tokenRoi, 2) ?>%</span>
                                                </div>
                                            </div>
                                        </div>
                                        <?php
                                        $renderMobileOfferTokenBreakdown($data, $level + 1, $currentPath);
                                    }
                                }
                            };
                            if (!empty($offerTokenPerformance[$offerId])) {
                                $renderMobileOfferTokenBreakdown($offerTokenPerformance[$offerId]);
                            }
                            ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
                </div>

    <!-- Landing Page Performance Table -->
    <div class="stats-table-container">
        <h3 style="margin-top: 0; color: #3d5a26; font-size: 18px; font-weight: 600; margin-bottom: 20px;">
            Landing Page Performance<?= ($viewMode === 'target' && !empty($selectedTokens)) ? ' - ' . htmlspecialchars(implode(' → ', $selectedTokenNames)) : '' ?>
        </h3>
        <p style="color: #666; margin-bottom: 16px; font-size: 14px;">
            <?php if ($viewMode === 'target' && !empty($selectedTokens)): ?>
                Performance metrics grouped by landing page, with breakdown by <strong><?= htmlspecialchars(implode(' → ', $selectedTokenNames)) ?></strong> below each landing page.
            <?php else: ?>
                Performance metrics grouped by landing page.
            <?php endif; ?>
        </p>
        
        <!-- Traffic Source Header Banner (if traffic source selected) -->
        <?php if ($selectedTrafficSourceId && $selectedTrafficSourceName): ?>
            <?php $trafficSourceIcon = $getTrafficSourceIcon($selectedTrafficSourceName); ?>
            <div style="background: linear-gradient(135deg, #3d5a26 0%, #5a7a3a 100%); color: #ffffff; padding: 28px 32px; border-radius: 12px; margin: 20px 0 24px 0; box-shadow: 0 4px 12px rgba(61, 90, 38, 0.2); text-align: center;">
                <h2 style="margin: 0; font-size: 32px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; color: #ffffff; text-shadow: 0 2px 4px rgba(0,0,0,0.2); display: flex; align-items: center; justify-content: center; gap: 16px; flex-wrap: wrap;">
                    <?php if ($trafficSourceIcon): ?>
                        <img src="<?= htmlspecialchars($trafficSourceIcon) ?>" alt="<?= htmlspecialchars($selectedTrafficSourceName) ?>" style="width: 48px; height: 48px; object-fit: contain; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));">
                    <?php else: ?>
                        <img src="<?= ASSETS_BASE_URL ?>/assets/images/autodetectbear.png" alt="Auto Detected" style="width: 48px; height: 48px; object-fit: contain; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));">
                    <?php endif; ?>
                    <?= htmlspecialchars($selectedTrafficSourceName) ?>
                </h2>
                <p style="margin: 12px 0 0 0; font-size: 16px; opacity: 0.95; color: #ffffff; text-shadow: 0 1px 2px rgba(0,0,0,0.2);">
                    Viewing stats filtered by this traffic source
                </p>
            </div>
        <?php endif; ?>
        
        <?php if (!$skipLp && !empty($landingPagePerformance)): ?>
        <div class="lp-offer-breakdown-toggle" style="margin-bottom: 16px;">
            <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-size: 14px; color: #333;">
                <input type="checkbox" id="show-lp-offer-breakdown" name="show_lp_offer_breakdown" value="1" <?= $showLpOfferBreakdown ? 'checked' : '' ?>>
                Show offer breakdown under landing pages
            </label>
        </div>
        <?php endif; ?>
        
        <!-- Desktop Table -->
        <div class="table-wrapper desktop-only">
        <table class="stats-table stats-table-sortable" data-sort-key="visitors" data-sort-dir="desc">
            <thead>
                <tr>
                    <th class="sortable-col" data-sort-key="lp_name" data-sort-type="string" title="Click to sort">Landing Page <span class="sort-indicator" aria-hidden="true">↕</span></th>
                    <th class="sortable-col" data-sort-key="visitors" data-sort-type="number" title="Click to sort">Visitors <span class="sort-indicator" aria-hidden="true">↕</span></th>
                    <th class="sortable-col" data-sort-key="cost" data-sort-type="number" title="Click to sort">Cost <span class="sort-indicator" aria-hidden="true">↕</span></th>
                    <th class="sortable-col" data-sort-key="lp_clicks" data-sort-type="number" title="Click to sort">LP Clicks <span class="sort-indicator" aria-hidden="true">↕</span></th>
                    <th class="sortable-col" data-sort-key="lp_ctr" data-sort-type="number" title="Click to sort">LP CTR <span class="sort-indicator" aria-hidden="true">↕</span></th>
                    <th class="sortable-col" data-sort-key="conversions" data-sort-type="number" title="Click to sort">Conversions <span class="sort-indicator" aria-hidden="true">↕</span></th>
                    <th class="sortable-col" data-sort-key="cr" data-sort-type="number" title="Click to sort">CR <span class="sort-indicator" aria-hidden="true">↕</span></th>
                    <th class="sortable-col" data-sort-key="revenue" data-sort-type="number" title="Click to sort">Revenue <span class="sort-indicator" aria-hidden="true">↕</span></th>
                    <th class="sortable-col" data-sort-key="epc" data-sort-type="number" title="Click to sort">EPC <span class="sort-indicator" aria-hidden="true">↕</span></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($landingPagePerformance)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 40px; color: #999;">
                            No landing pages found in campaigns for the selected filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php
                    // Grand total row for Landing Page Performance (include direct clicks when DIRECT LINK row present)
                    $lpSectionTotalClicks = $totalClicks + (isset($directLinkPerformance) ? (int)($directLinkPerformance['lp_clicks'] ?? 0) : 0);
                    $lpTotalCtr = $totalVisitors > 0 ? ($lpSectionTotalClicks / $totalVisitors) * 100 : 0;
                    $lpTotalCr = $lpSectionTotalClicks > 0 ? ($totalConversions / $lpSectionTotalClicks) * 100 : 0;
                    $lpTotalEpc = $lpSectionTotalClicks > 0 ? ($totalRevenue / $lpSectionTotalClicks) : 0;
                    ?>
                    <tr data-block-id="lp-total" data-total-row="1" data-sort-lp_name="Total" data-sort-visitors="<?= (int)$totalVisitors ?>" data-sort-cost="<?= (float)$totalCost ?>" data-sort-lp_clicks="<?= (int)$lpSectionTotalClicks ?>" data-sort-lp_ctr="<?= (float)$lpTotalCtr ?>" data-sort-conversions="<?= (int)$totalConversions ?>" data-sort-cr="<?= (float)$lpTotalCr ?>" data-sort-revenue="<?= (float)$totalRevenue ?>" data-sort-epc="<?= (float)$lpTotalEpc ?>" style="background: #e8f0e8; font-weight: 600;">
                        <td><strong>Total</strong></td>
                        <td><?= number_format($totalVisitors) ?></td>
                        <td><?= Formatter::formatCurrency($totalCost, $userCurrency) ?></td>
                        <td><?= number_format($lpSectionTotalClicks) ?></td>
                        <td><?= number_format($lpTotalCtr, 2) ?>%</td>
                        <td><?= number_format($totalConversions) ?></td>
                        <td><?= number_format($lpTotalCr, 2) ?>%</td>
                        <td><?= Formatter::formatCurrency($totalRevenue, $userCurrency) ?></td>
                        <td><?= Formatter::formatCurrency($lpTotalEpc, $userCurrency) ?></td>
                    </tr>
                    <?php foreach ($landingPagePerformance as $lp): ?>
                        <?php
                        $visitors = (int)($lp['visitors'] ?? 0);
                        $lpClicks = (int)($lp['lp_clicks'] ?? 0);
                        $conversions = (int)($lp['conversions'] ?? 0);
                        $revenue = (float)($lp['revenue'] ?? 0);
                        $cost = (float)($lp['cost'] ?? 0);
                        
                        $lpCtr = $visitors > 0 ? ($lpClicks / $visitors) * 100 : 0;
                        $cr = $lpClicks > 0 ? ($conversions / $lpClicks) * 100 : 0;
                        $epc = $lpClicks > 0 ? ($revenue / $lpClicks) : 0;
                        $lpId = (int)($lp['lp_id'] ?? 0);
                        ?>
                        <tr data-block-id="lp-<?= $lpId ?>" data-sort-lp_name="<?= htmlspecialchars($lp['lp_name']) ?>" data-sort-visitors="<?= (int)$visitors ?>" data-sort-cost="<?= (float)$cost ?>" data-sort-lp_clicks="<?= (int)$lpClicks ?>" data-sort-lp_ctr="<?= (float)$lpCtr ?>" data-sort-conversions="<?= (int)$conversions ?>" data-sort-cr="<?= (float)$cr ?>" data-sort-revenue="<?= (float)$revenue ?>" data-sort-epc="<?= (float)$epc ?>">
                            <td>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <strong><?= htmlspecialchars($lp['lp_name']) ?></strong>
                                    <?php if (!empty($lp['lp_url'])): ?>
                                        <a href="<?= htmlspecialchars($lp['lp_url']) ?>" 
                                           target="_blank" 
                                           rel="noopener noreferrer"
                                           title="View landing page"
                                           style="color: #3d5a26; text-decoration: none; font-size: 16px; display: inline-flex; align-items: center; transition: opacity 0.2s;"
                                           onmouseover="this.style.opacity='0.7'"
                                           onmouseout="this.style.opacity='1'">
                                            👁️
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($lp['lp_url'])): ?>
                                <small style="color: #999; font-size: 11px;"><?= htmlspecialchars(substr($lp['lp_url'], 0, 50)) ?>...</small>
                                <?php endif; ?>
                            </td>
                            <td><?= number_format($visitors) ?></td>
                            <td><?= Formatter::formatCurrency($cost, $userCurrency) ?></td>
                            <td><?= number_format($lpClicks) ?></td>
                            <td><?= number_format($lpCtr, 2) ?>%</td>
                            <td><?= number_format($conversions) ?></td>
                            <td><?= number_format($cr, 2) ?>%</td>
                            <td><?= Formatter::formatCurrency($revenue, $userCurrency) ?></td>
                            <td><?= Formatter::formatCurrency($epc, $userCurrency) ?></td>
                        </tr>
                        <?php if ($showLpOfferBreakdown && !empty($lpOfferBreakdown[$lpId])): ?>
                            <?php foreach ($lpOfferBreakdown[$lpId] as $offerData): ?>
                                <?php
                                $oVisitors = (int)($offerData['visitors'] ?? 0);
                                $oLpClicks = (int)($offerData['lp_clicks'] ?? 0);
                                $oCost = (float)($offerData['cost'] ?? 0);
                                $oConversions = (int)($offerData['conversions'] ?? 0);
                                $oRevenue = (float)($offerData['revenue'] ?? 0);
                                $oLpCtr = $oVisitors > 0 ? ($oLpClicks / $oVisitors) * 100 : 0;
                                $oCr = $oLpClicks > 0 ? ($oConversions / $oLpClicks) * 100 : 0;
                                $oEpc = $oLpClicks > 0 ? ($oRevenue / $oLpClicks) : 0;
                                ?>
                                <tr data-block-id="lp-<?= $lpId ?>" class="lp-offer-breakdown-row" style="background: #f8f9fa; border-left: 3px solid #3d5a26;">
                                    <td style="padding-left: 30px;">
                                        <span style="color: #5a7a3a; font-size: 13px;">└─ </span>
                                        <span style="color: #5a7a3a; font-size: 13px;"><?= htmlspecialchars($offerData['offer_name']) ?>: <?= number_format($oLpClicks) ?> LP Clicks</span>
                                    </td>
                                    <td><?= number_format($oVisitors) ?></td>
                                    <td><?= Formatter::formatCurrency($oCost, $userCurrency) ?></td>
                                    <td><?= number_format($oLpClicks) ?></td>
                                    <td><?= number_format($oLpCtr, 2) ?>%</td>
                                    <td><?= number_format($oConversions) ?></td>
                                    <td><?= number_format($oCr, 2) ?>%</td>
                                    <td><?= Formatter::formatCurrency($oRevenue, $userCurrency) ?></td>
                                    <td><?= Formatter::formatCurrency($oEpc, $userCurrency) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if ($viewMode === 'target' && !empty($selectedTokens) && !empty($lpTokenPerformance[$lpId])): ?>
                            <?php
                            // Helper function to aggregate stats from nested data
                            $aggregateLpStats = function($data) use (&$aggregateLpStats) {
                                if (isset($data['visitors'])) {
                                    // Leaf node - return stats directly
                                    return [
                                        'visitors' => (int)($data['visitors'] ?? 0),
                                        'cost' => (float)($data['cost'] ?? 0),
                                        'lp_clicks' => (int)($data['lp_clicks'] ?? 0),
                                        'conversions' => (int)($data['conversions'] ?? 0),
                                        'revenue' => (float)($data['revenue'] ?? 0)
                                    ];
                                }
                                // Intermediate node - aggregate from children
                                $aggregated = [
                                    'visitors' => 0,
                                    'cost' => 0,
                                    'lp_clicks' => 0,
                                    'conversions' => 0,
                                    'revenue' => 0
                                ];
                                foreach ($data as $value) {
                                    if (is_array($value)) {
                                        $childStats = $aggregateLpStats($value);
                                        $aggregated['visitors'] += $childStats['visitors'];
                                        $aggregated['cost'] += $childStats['cost'];
                                        $aggregated['lp_clicks'] += $childStats['lp_clicks'];
                                        $aggregated['conversions'] += $childStats['conversions'];
                                        $aggregated['revenue'] += $childStats['revenue'];
                                    }
                                }
                                return $aggregated;
                            };
                            
                            // Recursive function to render nested token breakdowns
                            $renderLpTokenBreakdown = function($tokenData, $level = 0, $path = []) use (&$renderLpTokenBreakdown, &$aggregateLpStats, $selectedTokens, $selectedTokenNames, $userCurrency, $lpId, $cost, $visitors) {
                                foreach ($tokenData as $tokenValue => $data) {
                                    $currentPath = array_merge($path, [$tokenValue]);
                                    $indent = $level * 30;
                                    $prefix = str_repeat('└─ ', $level + 1);
                                    
                                    // Check if this is a leaf node (has stats) or intermediate node (has nested arrays)
                                    // Leaf node: has 'visitors' key (stats object)
                                    // Intermediate node: array of token values (no 'visitors' key at top level)
                                    $isLeaf = isset($data['visitors']);
                                    
                                    if ($isLeaf) {
                                        // Leaf node - display stats
                                        $tokenVisitors = (int)($data['visitors'] ?? 0);
                                        $tokenCost = (float)($data['cost'] ?? 0);
                                        if ($tokenCost == 0 && $visitors > 0 && $cost > 0) {
                                            $tokenCost = ($tokenVisitors / $visitors) * $cost;
                                        }
                                        $tokenLpClicks = (int)($data['lp_clicks'] ?? 0);
                                        $tokenConversions = (int)($data['conversions'] ?? 0);
                                        $tokenRevenue = (float)($data['revenue'] ?? 0);
                                        
                                        $tokenLpCtr = $tokenVisitors > 0 ? ($tokenLpClicks / $tokenVisitors) * 100 : 0;
                                        $tokenCr = $tokenLpClicks > 0 ? ($tokenConversions / $tokenLpClicks) * 100 : 0;
                                        $tokenEpc = $tokenLpClicks > 0 ? ($tokenRevenue / $tokenLpClicks) : 0;
                                        
                                        // Get token name for display
                                        $tokenName = $tokenValue;
                                        ?>
                                        <tr data-block-id="lp-<?= $lpId ?>" data-sort-lp_name="<?= htmlspecialchars($tokenName) ?>" data-sort-visitors="<?= (int)$tokenVisitors ?>" data-sort-cost="<?= (float)$tokenCost ?>" data-sort-lp_clicks="<?= (int)$tokenLpClicks ?>" data-sort-lp_ctr="<?= (float)$tokenLpCtr ?>" data-sort-conversions="<?= (int)$tokenConversions ?>" data-sort-cr="<?= (float)$tokenCr ?>" data-sort-revenue="<?= (float)$tokenRevenue ?>" data-sort-epc="<?= (float)$tokenEpc ?>" style="background: #f8f9fa; border-left: 3px solid #3d5a26;">
                                            <td style="padding-left: <?= $indent + 30 ?>px;">
                                                <span style="color: #5a7a3a; font-size: 13px;"><?= $prefix ?></span>
                                                <strong style="color: #5a7a3a; font-size: 13px;"><?= htmlspecialchars($tokenName) ?></strong>
                                            </td>
                                            <td><?= number_format($tokenVisitors) ?></td>
                                            <td><?= Formatter::formatCurrency($tokenCost, $userCurrency) ?></td>
                                            <td><?= number_format($tokenLpClicks) ?></td>
                                            <td><?= number_format($tokenLpCtr, 2) ?>%</td>
                                            <td><?= number_format($tokenConversions) ?></td>
                                            <td><?= number_format($tokenCr, 2) ?>%</td>
                                            <td style="color: <?= $tokenRevenue >= 0 ? '#28a745' : '#d32f2f' ?>;">
                                                <?= Formatter::formatCurrency($tokenRevenue, $userCurrency) ?>
                                            </td>
                                            <td><?= Formatter::formatCurrency($tokenEpc, $userCurrency) ?></td>
                                        </tr>
                                        <?php
                                    } else {
                                        // Intermediate node - aggregate stats and show, then recurse
                                        $aggStats = $aggregateLpStats($data);
                                        $tokenVisitors = $aggStats['visitors'];
                                        $tokenCost = $aggStats['cost'];
                                        if ($tokenCost == 0 && $visitors > 0 && $cost > 0) {
                                            $tokenCost = ($tokenVisitors / $visitors) * $cost;
                                        }
                                        $tokenLpClicks = $aggStats['lp_clicks'];
                                        $tokenConversions = $aggStats['conversions'];
                                        $tokenRevenue = $aggStats['revenue'];
                                        
                                        $tokenLpCtr = $tokenVisitors > 0 ? ($tokenLpClicks / $tokenVisitors) * 100 : 0;
                                        $tokenCr = $tokenLpClicks > 0 ? ($tokenConversions / $tokenLpClicks) * 100 : 0;
                                        $tokenEpc = $tokenLpClicks > 0 ? ($tokenRevenue / $tokenLpClicks) : 0;
                                        
                                        // Get token name for display
                                        $tokenName = $tokenValue;
                                        if ($level < count($selectedTokenNames)) {
                                            $tokenName = ($selectedTokenNames[$level] ?? '') . ': ' . $tokenValue;
                                        }
                                        ?>
                                        <tr data-block-id="lp-<?= $lpId ?>" data-sort-lp_name="<?= htmlspecialchars($tokenName) ?>" data-sort-visitors="<?= (int)$tokenVisitors ?>" data-sort-cost="<?= (float)$tokenCost ?>" data-sort-lp_clicks="<?= (int)$tokenLpClicks ?>" data-sort-lp_ctr="<?= (float)$tokenLpCtr ?>" data-sort-conversions="<?= (int)$tokenConversions ?>" data-sort-cr="<?= (float)$tokenCr ?>" data-sort-revenue="<?= (float)$tokenRevenue ?>" data-sort-epc="<?= (float)$tokenEpc ?>" style="background: #f8f9fa; border-left: 3px solid #3d5a26;">
                                            <td style="padding-left: <?= $indent + 30 ?>px;">
                                                <span style="color: #5a7a3a; font-size: 13px;"><?= $prefix ?></span>
                                                <strong style="color: #5a7a3a; font-size: 13px;"><?= htmlspecialchars($tokenName) ?></strong>
                                            </td>
                                            <td><?= number_format($tokenVisitors) ?></td>
                                            <td><?= Formatter::formatCurrency($tokenCost, $userCurrency) ?></td>
                                            <td><?= number_format($tokenLpClicks) ?></td>
                                            <td><?= number_format($tokenLpCtr, 2) ?>%</td>
                                            <td><?= number_format($tokenConversions) ?></td>
                                            <td><?= number_format($tokenCr, 2) ?>%</td>
                                            <td style="color: <?= $tokenRevenue >= 0 ? '#28a745' : '#d32f2f' ?>;">
                                                <?= Formatter::formatCurrency($tokenRevenue, $userCurrency) ?>
                                            </td>
                                            <td><?= Formatter::formatCurrency($tokenEpc, $userCurrency) ?></td>
                                        </tr>
                                        <?php
                                        // Recurse into nested data
                                        $renderLpTokenBreakdown($data, $level + 1, $currentPath);
                                    }
                                }
                            };
                            $renderLpTokenBreakdown($lpTokenPerformance[$lpId]);
                            ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
        
        <!-- Mobile Landing Page Performance Cards -->
        <div class="mobile-landing-page-performance-cards mobile-only">
            <?php if (empty($landingPagePerformance)): ?>
                <div class="empty-state">
                    No landing pages found in campaigns for the selected filters.
                </div>
            <?php else: ?>
                <?php foreach ($landingPagePerformance as $lp): ?>
                    <?php
                    $lpVisitors = (int)($lp['visitors'] ?? 0);
                    $lpLpClicks = (int)($lp['lp_clicks'] ?? 0);
                    $lpCost = (float)($lp['cost'] ?? 0);
                    $lpLpCtr = $lpVisitors > 0 ? ($lpLpClicks / $lpVisitors) * 100 : 0;
                    $lpConversions = (int)($lp['conversions'] ?? 0);
                    $lpCr = $lpVisitors > 0 ? ($lpConversions / $lpVisitors) * 100 : 0;
                    $lpRevenue = (float)($lp['revenue'] ?? 0);
                    $lpEpc = $lpLpClicks > 0 ? ($lpRevenue / $lpLpClicks) : 0;
                    $lpId = (int)($lp['lp_id'] ?? 0);
                    ?>
                    <div class="mobile-landing-page-performance-card">
                        <div class="lp-name" style="display: flex; align-items: center; gap: 8px;">
                            <span><?= htmlspecialchars($lp['lp_name']) ?></span>
                            <?php if (!empty($lp['lp_url'])): ?>
                                <a href="<?= htmlspecialchars($lp['lp_url']) ?>" 
                                   target="_blank" 
                                   rel="noopener noreferrer"
                                   title="View landing page"
                                   style="color: #3d5a26; text-decoration: none; font-size: 16px; display: inline-flex; align-items: center; transition: opacity 0.2s;"
                                   onmouseover="this.style.opacity='0.7'"
                                   onmouseout="this.style.opacity='1'">
                                    👁️
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="lp-stats">
                            <div class="lp-stat">
                                <span class="lp-stat-label">Visitors</span>
                                <span class="lp-stat-value"><?= number_format($lpVisitors) ?></span>
                            </div>
                            <div class="lp-stat">
                                <span class="lp-stat-label">Cost</span>
                                <span class="lp-stat-value"><?= Formatter::formatCurrency($lpCost, $userCurrency) ?></span>
                            </div>
                            <div class="lp-stat">
                                <span class="lp-stat-label">LP Clicks</span>
                                <span class="lp-stat-value"><?= number_format($lpLpClicks) ?></span>
                            </div>
                            <div class="lp-stat">
                                <span class="lp-stat-label">LP CTR</span>
                                <span class="lp-stat-value"><?= number_format($lpLpCtr, 2) ?>%</span>
                            </div>
                            <div class="lp-stat">
                                <span class="lp-stat-label">Conversions</span>
                                <span class="lp-stat-value"><?= number_format($lpConversions) ?></span>
                            </div>
                            <div class="lp-stat">
                                <span class="lp-stat-label">CR</span>
                                <span class="lp-stat-value"><?= number_format($lpCr, 2) ?>%</span>
                            </div>
                            <div class="lp-stat">
                                <span class="lp-stat-label">Revenue</span>
                                <span class="lp-stat-value"><?= Formatter::formatCurrency($lpRevenue, $userCurrency) ?></span>
                            </div>
                            <div class="lp-stat">
                                <span class="lp-stat-label">EPC</span>
                                <span class="lp-stat-value"><?= Formatter::formatCurrency($lpEpc, $userCurrency) ?></span>
                            </div>
                        </div>
                        <?php if ($showLpOfferBreakdown && !empty($lpOfferBreakdown[$lpId])): ?>
                            <div class="mobile-lp-offer-breakdown" style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e0e0e0;">
                                <?php foreach ($lpOfferBreakdown[$lpId] as $offerData): ?>
                                    <div class="mobile-offer-breakdown-item" style="font-size: 13px; color: #5a7a3a; margin-bottom: 6px;">
                                        └─ <?= htmlspecialchars($offerData['offer_name']) ?>: <?= number_format((int)($offerData['lp_clicks'] ?? 0)) ?> LP Clicks
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($viewMode === 'target' && !empty($selectedTokens) && !empty($lpTokenPerformance[$lpId])): ?>
                            <?php
                            // Recursive function to render nested token breakdowns for mobile landing pages
                            $renderMobileLpTokenBreakdown = function($tokenData, $level = 0, $path = []) use (&$renderMobileLpTokenBreakdown, $selectedTokenNames, $userCurrency, $lpCost, $lpVisitors) {
                                foreach ($tokenData as $tokenValue => $data) {
                                    $currentPath = array_merge($path, [$tokenValue]);
                                    $prefix = str_repeat('└─ ', $level);
                                    
                                    $isLeaf = isset($data['visitors']);
                                    
                                    if ($isLeaf) {
                                        $tokenVisitors = (int)($data['visitors'] ?? 0);
                                        $tokenCost = (float)($data['cost'] ?? 0);
                                        if ($tokenCost == 0 && $lpVisitors > 0 && $lpCost > 0) {
                                            $tokenCost = ($tokenVisitors / $lpVisitors) * $lpCost;
                                        }
                                        $tokenLpClicks = (int)($data['lp_clicks'] ?? 0);
                                        $tokenLpCtr = $tokenVisitors > 0 ? ($tokenLpClicks / $tokenVisitors) * 100 : 0;
                                        $tokenConversions = (int)($data['conversions'] ?? 0);
                                        $tokenCr = $tokenVisitors > 0 ? ($tokenConversions / $tokenVisitors) * 100 : 0;
                                        $tokenRevenue = (float)($data['revenue'] ?? 0);
                                        $tokenEpc = $tokenLpClicks > 0 ? ($tokenRevenue / $tokenLpClicks) : 0;
                                        
                                        $tokenName = $tokenValue;
                                        ?>
                                        <div class="mobile-token-breakdown token-level-<?= $level ?>">
                                            <div class="token-name">
                                                <?= htmlspecialchars($tokenName) ?>
                                            </div>
                                            <div class="lp-stats">
                                                <div class="lp-stat">
                                                    <span class="lp-stat-label">Visitors</span>
                                                    <span class="lp-stat-value"><?= number_format($tokenVisitors) ?></span>
                                                </div>
                                                <div class="lp-stat">
                                                    <span class="lp-stat-label">Cost</span>
                                                    <span class="lp-stat-value"><?= Formatter::formatCurrency($tokenCost, $userCurrency) ?></span>
                                                </div>
                                                <div class="lp-stat">
                                                    <span class="lp-stat-label">LP Clicks</span>
                                                    <span class="lp-stat-value"><?= number_format($tokenLpClicks) ?></span>
                                                </div>
                                                <div class="lp-stat">
                                                    <span class="lp-stat-label">LP CTR</span>
                                                    <span class="lp-stat-value"><?= number_format($tokenLpCtr, 2) ?>%</span>
                                                </div>
                                                <div class="lp-stat">
                                                    <span class="lp-stat-label">Conversions</span>
                                                    <span class="lp-stat-value"><?= number_format($tokenConversions) ?></span>
                                                </div>
                                                <div class="lp-stat">
                                                    <span class="lp-stat-label">CR</span>
                                                    <span class="lp-stat-value"><?= number_format($tokenCr, 2) ?>%</span>
                                                </div>
                                                <div class="lp-stat">
                                                    <span class="lp-stat-label">Revenue</span>
                                                    <span class="lp-stat-value"><?= Formatter::formatCurrency($tokenRevenue, $userCurrency) ?></span>
                                                </div>
                                                <div class="lp-stat">
                                                    <span class="lp-stat-label">EPC</span>
                                                    <span class="lp-stat-value"><?= Formatter::formatCurrency($tokenEpc, $userCurrency) ?></span>
                                                </div>
                                            </div>
                                        </div>
                                        <?php
                                    } else {
                                        // Aggregate stats for non-leaf nodes
                                        $aggVisitors = 0;
                                        $aggCost = 0;
                                        $aggLpClicks = 0;
                                        $aggConversions = 0;
                                        $aggRevenue = 0;
                                        
                                        $aggregateLpStats = function($data) use (&$aggregateLpStats, &$aggVisitors, &$aggCost, &$aggLpClicks, &$aggConversions, &$aggRevenue) {
                                            if (isset($data['visitors'])) {
                                                $aggVisitors += (int)($data['visitors'] ?? 0);
                                                $aggCost += (float)($data['cost'] ?? 0);
                                                $aggLpClicks += (int)($data['lp_clicks'] ?? 0);
                                                $aggConversions += (int)($data['conversions'] ?? 0);
                                                $aggRevenue += (float)($data['revenue'] ?? 0);
                                            } else {
                                                foreach ($data as $value) {
                                                    if (is_array($value)) {
                                                        $aggregateLpStats($value);
                                                    }
                                                }
                                            }
                                        };
                                        $aggregateLpStats($data);
                                        
                                        $tokenLpCtr = $aggVisitors > 0 ? ($aggLpClicks / $aggVisitors) * 100 : 0;
                                        if ($aggCost == 0 && $lpVisitors > 0 && $lpCost > 0) {
                                            $aggCost = ($aggVisitors / $lpVisitors) * $lpCost;
                                        }
                                        $tokenCr = $aggVisitors > 0 ? ($aggConversions / $aggVisitors) * 100 : 0;
                                        $tokenEpc = $aggLpClicks > 0 ? ($aggRevenue / $aggLpClicks) : 0;
                                        
                                        $tokenName = $tokenValue;
                                        if ($level < count($selectedTokenNames)) {
                                            $tokenName = ($selectedTokenNames[$level] ?? '') . ': ' . $tokenValue;
                                        }
                                        ?>
                                        <div class="mobile-token-breakdown token-level-<?= $level ?>">
                                            <div class="token-name">
                                                <?= htmlspecialchars($tokenName) ?>
                                            </div>
                                            <div class="lp-stats">
                                                <div class="lp-stat">
                                                    <span class="lp-stat-label">Visitors</span>
                                                    <span class="lp-stat-value"><?= number_format($aggVisitors) ?></span>
                                                </div>
                                                <div class="lp-stat">
                                                    <span class="lp-stat-label">Cost</span>
                                                    <span class="lp-stat-value"><?= Formatter::formatCurrency($aggCost, $userCurrency) ?></span>
                                                </div>
                                                <div class="lp-stat">
                                                    <span class="lp-stat-label">LP Clicks</span>
                                                    <span class="lp-stat-value"><?= number_format($aggLpClicks) ?></span>
                                                </div>
                                                <div class="lp-stat">
                                                    <span class="lp-stat-label">LP CTR</span>
                                                    <span class="lp-stat-value"><?= number_format($tokenLpCtr, 2) ?>%</span>
                                                </div>
                                                <div class="lp-stat">
                                                    <span class="lp-stat-label">Conversions</span>
                                                    <span class="lp-stat-value"><?= number_format($aggConversions) ?></span>
                                                </div>
                                                <div class="lp-stat">
                                                    <span class="lp-stat-label">CR</span>
                                                    <span class="lp-stat-value"><?= number_format($tokenCr, 2) ?>%</span>
                                                </div>
                                                <div class="lp-stat">
                                                    <span class="lp-stat-label">Revenue</span>
                                                    <span class="lp-stat-value"><?= Formatter::formatCurrency($aggRevenue, $userCurrency) ?></span>
                                                </div>
                                                <div class="lp-stat">
                                                    <span class="lp-stat-label">EPC</span>
                                                    <span class="lp-stat-value"><?= Formatter::formatCurrency($tokenEpc, $userCurrency) ?></span>
                                                </div>
                                            </div>
                                        </div>
                                        <?php
                                        $renderMobileLpTokenBreakdown($data, $level + 1, $currentPath);
                                    }
                                }
                            };
                            $renderMobileLpTokenBreakdown($lpTokenPerformance[$lpId]);
                            ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Campaign Performance Table -->
    <?php if ($viewMode === 'standard'): ?>
    <div class="stats-table-container">
        <h3 style="margin-top: 0; color: #3d5a26; font-size: 18px; font-weight: 600; margin-bottom: 20px;">
            Campaign Performance
        </h3>
        
        <!-- Traffic Source Header Banner (if traffic source selected) -->
        <?php if ($selectedTrafficSourceId && $selectedTrafficSourceName): ?>
            <?php $trafficSourceIcon = $getTrafficSourceIcon($selectedTrafficSourceName); ?>
            <div style="background: linear-gradient(135deg, #3d5a26 0%, #5a7a3a 100%); color: #ffffff; padding: 28px 32px; border-radius: 12px; margin: 0 0 24px 0; box-shadow: 0 4px 12px rgba(61, 90, 38, 0.2); text-align: center;">
                <h2 style="margin: 0; font-size: 32px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; color: #ffffff; text-shadow: 0 2px 4px rgba(0,0,0,0.2); display: flex; align-items: center; justify-content: center; gap: 16px; flex-wrap: wrap;">
                    <?php if ($trafficSourceIcon): ?>
                        <img src="<?= htmlspecialchars($trafficSourceIcon) ?>" alt="<?= htmlspecialchars($selectedTrafficSourceName) ?>" style="width: 48px; height: 48px; object-fit: contain; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));">
                    <?php else: ?>
                        <img src="<?= ASSETS_BASE_URL ?>/assets/images/autodetectbear.png" alt="Auto Detected" style="width: 48px; height: 48px; object-fit: contain; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));">
                    <?php endif; ?>
                    <?= htmlspecialchars($selectedTrafficSourceName) ?>
                </h2>
                <p style="margin: 12px 0 0 0; font-size: 16px; opacity: 0.95; color: #ffffff; text-shadow: 0 1px 2px rgba(0,0,0,0.2);">
                    Viewing stats filtered by this traffic source
                </p>
            </div>
        <?php endif; ?>
        
        <p style="color: #666; margin-bottom: 16px; font-size: 14px;">
            <?php if ($groupBy === 'slug' && $selectedCampaignId): ?>
                Performance metrics broken down by slug for this campaign. Each slug routes to the same campaign but allows you to differentiate traffic sources.
            <?php else: ?>
                Performance metrics by campaign.
            <?php endif; ?>
        </p>
        <!-- Desktop Table -->
        <div class="table-wrapper desktop-only">
        <table class="stats-table">
            <thead>
                <tr>
                    <th><?= $groupBy === 'slug' && $selectedCampaignId ? 'Slug' : 'Campaign' ?></th>
                    <th>Status</th>
                    <th>Visitors</th>
                    <th>LP Clicks</th>
                    <th>LP CTR</th>
                    <th>Conversions</th>
                    <th>CR</th>
                    <th>Cost</th>
                    <th>Revenue</th>
                    <th>P/L</th>
                    <th>ROI</th>
                    <th>EPC</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($campaignPerformance)): ?>
                    <tr>
                        <td colspan="12" style="text-align: center; padding: 40px; color: #999;">
                            No campaign performance data found for the selected filters.
                        </td>
                    </tr>
                <?php else: 
                    // Apply search filter if provided
                    $filteredCampaignPerformance = $campaignPerformance;
                    if (!empty($searchQuery)) {
                        $filteredCampaignPerformance = array_filter($campaignPerformance, function($camp) use ($searchQuery, $groupBy, $selectedCampaignId) {
                            $searchText = '';
                            if ($groupBy === 'slug' && $selectedCampaignId && !empty($camp['slug_label'])) {
                                $searchText = ($camp['slug_label'] ?? '') . ' ' . ($camp['slug'] ?? '');
                            } else {
                                $searchText = $camp['campaign_name'] ?? '';
                            }
                            return stripos($searchText, $searchQuery) !== false;
                        });
                        // Re-index array after filtering
                        $filteredCampaignPerformance = array_values($filteredCampaignPerformance);
                    }
                    
                    // Paginate
                    $totalCampaigns = count($filteredCampaignPerformance);
                    $totalPages = $totalCampaigns > 0 ? ceil($totalCampaigns / $perPage) : 1;
                    $paginatedCampaigns = array_slice($filteredCampaignPerformance, $offset, $perPage);
                ?>
                    <?php if (empty($paginatedCampaigns)): ?>
                        <tr>
                            <td colspan="12" style="text-align: center; padding: 40px; color: #999;">
                                No campaigns found<?= !empty($searchQuery) ? ' matching "' . htmlspecialchars($searchQuery) . '"' : '' ?>.
                            </td>
                        </tr>
                    <?php else: ?>
                    <?php foreach ($paginatedCampaigns as $camp): 
                        $visitors = (int)$camp['visitors'];
                        $cost = (float)$camp['cost'];
                        $lpClicks = (int)$camp['lp_clicks'];
                        $conversions = (int)$camp['conversions'];
                        $revenue = (float)$camp['revenue'];
                        $lpCtr = $visitors > 0 ? ($lpClicks / $visitors) * 100 : 0;
                        $cr = $lpClicks > 0 ? ($conversions / $lpClicks) * 100 : 0;
                        $epc = $lpClicks > 0 ? $revenue / $lpClicks : 0;
                        $profit = $revenue - $cost;
                        $roi = $cost > 0 ? (($profit / $cost) * 100) : 0;
                        $statusColor = $camp['status'] === 'active' ? '#28a745' : ($camp['status'] === 'paused' ? '#ffc107' : '#6c757d');
                        
                        // Display slug info if grouping by slug
                        $displayName = $camp['campaign_name'] ?? '';
                        if ($groupBy === 'slug' && $selectedCampaignId && !empty($camp['slug_label'])) {
                            $displayName = htmlspecialchars($camp['slug_label']) . ' (' . htmlspecialchars($camp['slug'] ?? '') . ')';
                        }
                    ?>
                        <tr>
                            <td><strong><?= $displayName ?></strong></td>
                            <td>
                                <span style="padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; 
                                             background: <?= $statusColor ?>20; color: <?= $statusColor ?>;">
                                    <?= strtoupper(htmlspecialchars($camp['status'])) ?>
                                </span>
                            </td>
                            <td><?= number_format($visitors) ?></td>
                            <td><?= number_format($lpClicks) ?></td>
                            <td><?= number_format($lpCtr, 2) ?>%</td>
                            <td><?= number_format($conversions) ?></td>
                            <td><?= number_format($cr, 2) ?>%</td>
                            <td><?= Formatter::formatCurrency($cost, $userCurrency) ?></td>
                            <td style="color: <?= $revenue >= 0 ? '#28a745' : '#d32f2f' ?>;">
                                <?= Formatter::formatCurrency($revenue, $userCurrency) ?>
                            </td>
                            <td style="color: <?= $profit >= 0 ? '#28a745' : '#d32f2f' ?>;">
                                <?= Formatter::formatCurrency($profit, $userCurrency) ?>
                            </td>
                            <td style="color: <?= $roi >= 0 ? '#28a745' : '#d32f2f' ?>;">
                                <?= number_format($roi, 2) ?>%
                            </td>
                            <td><?= Formatter::formatCurrency($epc, $userCurrency) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
        
        <!-- Mobile Campaign Performance Cards -->
        <div class="mobile-campaign-performance-cards mobile-only">
            <?php if (empty($campaignPerformance)): ?>
                <div class="empty-state">
                    No campaign performance data found for the selected filters.
                </div>
            <?php else: 
                // Apply search filter if provided
                $filteredCampaignPerformance = $campaignPerformance;
                if (!empty($searchQuery)) {
                    $filteredCampaignPerformance = array_filter($campaignPerformance, function($camp) use ($searchQuery, $groupBy, $selectedCampaignId) {
                        $searchText = '';
                        if ($groupBy === 'slug' && $selectedCampaignId && !empty($camp['slug_label'])) {
                            $searchText = ($camp['slug_label'] ?? '') . ' ' . ($camp['slug'] ?? '');
                        } else {
                            $searchText = $camp['campaign_name'] ?? '';
                        }
                        return stripos($searchText, $searchQuery) !== false;
                    });
                    // Re-index array after filtering
                    $filteredCampaignPerformance = array_values($filteredCampaignPerformance);
                }
                
                // Paginate
                $totalCampaigns = count($filteredCampaignPerformance);
                $totalPages = $totalCampaigns > 0 ? ceil($totalCampaigns / $perPage) : 1;
                $paginatedCampaigns = array_slice($filteredCampaignPerformance, $offset, $perPage);
            ?>
                <?php if (empty($paginatedCampaigns)): ?>
                    <div class="empty-state">
                        No campaigns found<?= !empty($searchQuery) ? ' matching "' . htmlspecialchars($searchQuery) . '"' : '' ?>.
                    </div>
                <?php else: ?>
                    <?php foreach ($paginatedCampaigns as $camp): 
                        $visitors = (int)$camp['visitors'];
                        $cost = (float)$camp['cost'];
                        $lpClicks = (int)$camp['lp_clicks'];
                        $conversions = (int)$camp['conversions'];
                        $revenue = (float)$camp['revenue'];
                        $lpCtr = $visitors > 0 ? ($lpClicks / $visitors) * 100 : 0;
                        $cr = $lpClicks > 0 ? ($conversions / $lpClicks) * 100 : 0;
                        $epc = $lpClicks > 0 ? $revenue / $lpClicks : 0;
                        $profit = $revenue - $cost;
                        $roi = $cost > 0 ? (($profit / $cost) * 100) : 0;
                        $statusClass = $camp['status'] === 'active' ? 'active' : ($camp['status'] === 'paused' ? 'paused' : 'archived');
                        
                        // Display slug info if grouping by slug
                        $displayName = $camp['campaign_name'] ?? '';
                        if ($groupBy === 'slug' && $selectedCampaignId && !empty($camp['slug_label'])) {
                            $displayName = htmlspecialchars($camp['slug_label']) . ' (' . htmlspecialchars($camp['slug'] ?? '') . ')';
                        }
                    ?>
                        <div class="mobile-campaign-performance-card">
                            <div class="campaign-name"><?= $displayName ?></div>
                            <div class="campaign-status <?= $statusClass ?>"><?= strtoupper(htmlspecialchars($camp['status'])) ?></div>
                            <div class="campaign-stats">
                                <div class="campaign-stat">
                                    <span class="campaign-stat-label">Visitors</span>
                                    <span class="campaign-stat-value"><?= number_format($visitors) ?></span>
                                </div>
                                <div class="campaign-stat">
                                    <span class="campaign-stat-label">LP Clicks</span>
                                    <span class="campaign-stat-value"><?= number_format($lpClicks) ?></span>
                                </div>
                                <div class="campaign-stat">
                                    <span class="campaign-stat-label">LP CTR</span>
                                    <span class="campaign-stat-value"><?= number_format($lpCtr, 2) ?>%</span>
                                </div>
                                <div class="campaign-stat">
                                    <span class="campaign-stat-label">Conversions</span>
                                    <span class="campaign-stat-value"><?= number_format($conversions) ?></span>
                                </div>
                                <div class="campaign-stat">
                                    <span class="campaign-stat-label">CR</span>
                                    <span class="campaign-stat-value"><?= number_format($cr, 2) ?>%</span>
                                </div>
                                <div class="campaign-stat">
                                    <span class="campaign-stat-label">Cost</span>
                                    <span class="campaign-stat-value"><?= Formatter::formatCurrency($cost, $userCurrency) ?></span>
                                </div>
                                <div class="campaign-stat">
                                    <span class="campaign-stat-label">Revenue</span>
                                    <span class="campaign-stat-value"><?= Formatter::formatCurrency($revenue, $userCurrency) ?></span>
                                </div>
                                <div class="campaign-stat">
                                    <span class="campaign-stat-label">P/L</span>
                                    <span class="campaign-stat-value <?= $profit >= 0 ? 'positive' : 'negative' ?>"><?= Formatter::formatCurrency($profit, $userCurrency) ?></span>
                                </div>
                                <div class="campaign-stat">
                                    <span class="campaign-stat-label">ROI</span>
                                    <span class="campaign-stat-value <?= $roi >= 0 ? 'positive' : 'negative' ?>"><?= number_format($roi, 2) ?>%</span>
                                </div>
                                <div class="campaign-stat">
                                    <span class="campaign-stat-label">EPC</span>
                                    <span class="campaign-stat-value"><?= Formatter::formatCurrency($epc, $userCurrency) ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <!-- Pagination -->
        <?php if (isset($totalPages) && $totalPages > 1): ?>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px; padding-top: 16px; border-top: 1px solid #e8e8e8;">
            <div style="color: #666; font-size: 14px;">
                Showing <?= $offset + 1 ?> to <?= min($offset + $perPage, $totalCampaigns) ?> of <?= number_format($totalCampaigns) ?> campaigns
            </div>
            <div style="display: flex; gap: 8px;">
                <?php if ($page > 1): ?>
                    <button type="submit" name="page" value="<?= $page - 1 ?>" 
                            style="padding: 6px 12px; background: #fff; border: 1px solid #d4d4d4; border-radius: 4px; cursor: pointer; color: #3d5a26;"
                            onmouseover="this.style.background='#f5f5f5'" onmouseout="this.style.background='#fff'">
                        Previous
                    </button>
                <?php endif; ?>
                
                <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                    <?php if ($p == $page): ?>
                        <span style="padding: 6px 12px; background: #3d5a26; color: #fff; border-radius: 4px; font-weight: 600;">
                            <?= $p ?>
                        </span>
                    <?php else: ?>
                        <button type="submit" name="page" value="<?= $p ?>" 
                                style="padding: 6px 12px; background: #fff; border: 1px solid #d4d4d4; border-radius: 4px; cursor: pointer; color: #3d5a26;"
                                onmouseover="this.style.background='#f5f5f5'" onmouseout="this.style.background='#fff'">
                            <?= $p ?>
                        </button>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <button type="submit" name="page" value="<?= $page + 1 ?>" 
                            style="padding: 6px 12px; background: #fff; border: 1px solid #d4d4d4; border-radius: 4px; cursor: pointer; color: #3d5a26;"
                            onmouseover="this.style.background='#f5f5f5'" onmouseout="this.style.background='#fff'">
                        Next
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
// Handle view mode change - enable/disable token dropdowns
document.addEventListener('DOMContentLoaded', function() {
    const viewModeSelect = document.getElementById('view-mode-select');
    const token1Select = document.getElementById('token1-select');
    const token2Select = document.getElementById('token2-select');
    const token3Select = document.getElementById('token3-select');
    
    function updateTokenSelectStates() {
        const isTargetMode = viewModeSelect && viewModeSelect.value === 'target';
        
        if (token1Select) {
            if (isTargetMode) {
                token1Select.removeAttribute('disabled');
            } else {
                token1Select.setAttribute('disabled', 'disabled');
                token1Select.value = '';
            }
        }
        
        if (token2Select) {
            const token1HasValue = token1Select && token1Select.value !== '';
            if (isTargetMode && token1HasValue) {
                token2Select.removeAttribute('disabled');
            } else {
                token2Select.setAttribute('disabled', 'disabled');
                token2Select.value = '';
            }
        }
        
        if (token3Select) {
            const token2HasValue = token2Select && token2Select.value !== '';
            if (isTargetMode && token2HasValue) {
                token3Select.removeAttribute('disabled');
            } else {
                token3Select.setAttribute('disabled', 'disabled');
                token3Select.value = '';
            }
        }
    }
    
    if (viewModeSelect) {
        // Set initial state
        updateTokenSelectStates();
        
        viewModeSelect.addEventListener('change', function() {
            updateTokenSelectStates();
            // Don't auto-submit - user must click Apply Filters button
        });
    }
    
    if (token1Select) {
        token1Select.addEventListener('change', function() {
            updateTokenSelectStates();
            // Don't auto-submit - user must click Apply Filters button
        });
    }
    
    if (token2Select) {
        token2Select.addEventListener('change', function() {
            updateTokenSelectStates();
            // Don't auto-submit - user must click Apply Filters button
        });
    }
    
    if (token3Select) {
        token3Select.addEventListener('change', function() {
            updateTokenSelectStates();
            // Don't auto-submit - user must click Apply Filters button
        });
    }
});

// Client-side table sorting (no page refresh) — use delegation in capture phase so it runs before other handlers
(function() {
    function parseSortVal(val, type) {
        if (val == null || val === '') return type === 'string' ? '' : 0;
        if (type === 'string') return String(val).toLowerCase();
        var s = String(val).replace(/,/g, '');
        var n = parseFloat(s);
        return isNaN(n) ? 0 : n;
    }
    function sortLastSentinel(type, dir) {
        if (type === 'string') return dir === 'asc' ? '\uffff' : '';
        return dir === 'asc' ? Infinity : -Infinity;
    }
    function getRowSortVal(tr, attr, type, dir) {
        var val = tr.getAttribute(attr);
        if (val == null || val === '') return sortLastSentinel(type, dir);
        return parseSortVal(val, type);
    }
    function refreshSortIndicators(table) {
        var key = table.getAttribute('data-sort-key');
        var dir = table.getAttribute('data-sort-dir') || 'desc';
        var headers = table.querySelectorAll('.sortable-col');
        for (var i = 0; i < headers.length; i++) {
            var th = headers[i];
            var span = th.querySelector('.sort-indicator');
            if (!span) continue;
            var thKey = th.getAttribute('data-sort-key');
            span.textContent = (thKey === key) ? (dir === 'asc' ? '\u2191' : '\u2193') : '\u21d5';
        }
    }
    function handleSortClick(e) {
        var from = e.target && e.target.nodeType === 1 ? e.target : (e.target && e.target.parentElement);
        if (!from || !from.closest) return;
        var th = from.closest('.sortable-col');
        if (!th) return;
        var table = th.closest('.stats-table-sortable');
        if (!table) return;
        e.preventDefault();
        e.stopPropagation();
        var tbody = table.querySelector('tbody');
        if (!tbody) return;
        var key = th.getAttribute('data-sort-key');
        var type = (th.getAttribute('data-sort-type') || 'number');
        var currentKey = table.getAttribute('data-sort-key');
        var currentDir = table.getAttribute('data-sort-dir') || 'desc';
        var dir = (key === currentKey && currentDir === 'desc') ? 'asc' : 'desc';
        table.setAttribute('data-sort-key', key);
        table.setAttribute('data-sort-dir', dir);
        var rows = Array.from(tbody.querySelectorAll('tr[data-block-id]'));
        if (rows.length === 0) return;
        var groups = [];
        var lastId = null;
        var grp = [];
        for (var i = 0; i < rows.length; i++) {
            var tr = rows[i];
            var bid = tr.getAttribute('data-block-id');
            if (bid !== lastId && grp.length) {
                groups.push(grp);
                grp = [];
            }
            grp.push(tr);
            lastId = bid;
        }
        if (grp.length) groups.push(grp);
        var totalGroups = [];
        var sortableGroups = [];
        for (var g = 0; g < groups.length; g++) {
            var firstRow = groups[g][0];
            if (firstRow && (firstRow.getAttribute('data-total-row') === '1' || firstRow.getAttribute('data-block-id') === 'offer-total' || firstRow.getAttribute('data-block-id') === 'lp-total')) {
                totalGroups.push(groups[g]);
            } else {
                sortableGroups.push(groups[g]);
            }
        }
        var attr = 'data-sort-' + key;
        for (var g = 0; g < sortableGroups.length; g++) {
            var grp = sortableGroups[g];
            var offerTotalRow = null;
            var blockId = grp[0] && grp[0].getAttribute('data-block-id');
            if (blockId && blockId.indexOf('offer-') === 0 && blockId !== 'offer-total') {
                for (var r = 0; r < grp.length; r++) {
                    if (grp[r].getAttribute('data-offer-total-row') === '1') {
                        offerTotalRow = grp.splice(r, 1)[0];
                        break;
                    }
                }
            }
            grp.sort(function(a, b) {
                var parsedA = getRowSortVal(a, attr, type, dir);
                var parsedB = getRowSortVal(b, attr, type, dir);
                if (type === 'string') {
                    var c = String(parsedA).localeCompare(String(parsedB));
                    return dir === 'asc' ? c : -c;
                }
                return dir === 'asc' ? (parsedA - parsedB) : (parsedB - parsedA);
            });
            if (offerTotalRow) grp.unshift(offerTotalRow);
        }
        sortableGroups.sort(function(a, b) {
            var firstA = a[0];
            var firstB = b[0];
            var parsedA = getRowSortVal(firstA, attr, type, dir);
            var parsedB = getRowSortVal(firstB, attr, type, dir);
            if (type === 'string') {
                var c = String(parsedA).localeCompare(String(parsedB));
                return dir === 'asc' ? c : -c;
            }
            return dir === 'asc' ? (parsedA - parsedB) : (parsedB - parsedA);
        });
        for (var g = 0; g < totalGroups.length; g++) {
            for (var r = 0; r < totalGroups[g].length; r++) {
                tbody.appendChild(totalGroups[g][r]);
            }
        }
        for (var g = 0; g < sortableGroups.length; g++) {
            for (var r = 0; r < sortableGroups[g].length; r++) {
                tbody.appendChild(sortableGroups[g][r]);
            }
        }
        refreshSortIndicators(table);
    }
    document.addEventListener('click', handleSortClick, true);
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.stats-table-sortable').forEach(function(table) {
            refreshSortIndicators(table);
        });
    });
})();
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.stats-table-sortable .sortable-col').forEach(function(th) { th.style.cursor = 'pointer'; });
});

// LP offer breakdown checkbox: persist via localStorage, reload with GET param
document.addEventListener('DOMContentLoaded', function() {
    const cb = document.getElementById('show-lp-offer-breakdown');
    if (!cb) return;
    const STORAGE_KEY = 'campaign_stats_show_lp_offer_breakdown';
    const urlParams = new URLSearchParams(window.location.search);
    const urlHasParam = urlParams.get('show_lp_offer_breakdown') === '1';
    const stored = localStorage.getItem(STORAGE_KEY) === 'true';
    if (stored && !urlHasParam) {
        urlParams.set('show_lp_offer_breakdown', '1');
        window.location.href = window.location.pathname + '?' + urlParams.toString();
        return;
    }
    if (!stored && urlHasParam) {
        localStorage.setItem(STORAGE_KEY, 'true');
    } else if (!urlHasParam) {
        localStorage.setItem(STORAGE_KEY, cb.checked ? 'true' : 'false');
    }
    cb.addEventListener('change', function() {
        localStorage.setItem(STORAGE_KEY, this.checked ? 'true' : 'false');
        if (this.checked) {
            urlParams.set('show_lp_offer_breakdown', '1');
        } else {
            urlParams.delete('show_lp_offer_breakdown');
        }
        window.location.href = window.location.pathname + '?' + urlParams.toString();
    });
});

// Pre-calculated dates in user's timezone (from PHP)
const datePresets = {
    today: '<?= $today ?>',
    yesterday: '<?= $yesterday ?>',
    last7Start: '<?= $last7Start ?>',
    last14Start: '<?= $last14Start ?>',
    last30Start: '<?= $last30Start ?>',
    lastMonthStart: '<?= $lastMonthStart ?>',
    lastMonthEnd: '<?= $lastMonthEnd ?>',
    thisMonthStart: '<?= $thisMonthStart ?>',
    allTimeStart: '<?= $allTimeStart ?>'
};

// Date range preset function
function setStatsDateRange(preset) {
    const dateFromInput = document.getElementById('stats_date_from');
    const dateToInput = document.getElementById('stats_date_to');
    let fromDate, toDate;
    
    switch(preset) {
        case 'today':
            fromDate = datePresets.today;
            toDate = datePresets.today;
            break;
        case 'yesterday':
            fromDate = datePresets.yesterday;
            toDate = datePresets.yesterday;
            break;
        case 'last7':
            fromDate = datePresets.last7Start;
            toDate = datePresets.today;
            break;
        case 'last14':
            fromDate = datePresets.last14Start;
            toDate = datePresets.today;
            break;
        case 'last30':
            fromDate = datePresets.last30Start;
            toDate = datePresets.today;
            break;
        case 'lastmonth':
            fromDate = datePresets.lastMonthStart;
            toDate = datePresets.lastMonthEnd;
            break;
        case 'thismonth':
            fromDate = datePresets.thisMonthStart;
            toDate = datePresets.today;
            break;
        case 'alltime':
            fromDate = datePresets.allTimeStart;
            toDate = datePresets.today;
            break;
    }
    
    if (dateFromInput && dateToInput) {
        dateFromInput.value = fromDate;
        dateToInput.value = toDate;
        
        // Submit the form
        const form = document.getElementById('stats-filter-form');
        if (form) {
            form.submit();
        }
    }
}
</script>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('performanceChart');
    if (!ctx) {
        console.error('Chart canvas element not found');
        return;
    }
    
    // Destroy existing chart instance if it exists
    if (window.campaignStatsChartInstance) {
        window.campaignStatsChartInstance.destroy();
    }
    
    // Get chart data from PHP
    const chartLabels = <?= json_encode($chartLabels ?? []) ?>;
    const chartVisitors = <?= json_encode($chartVisitors ?? []) ?>;
    const chartClicks = <?= json_encode($chartClicks ?? []) ?>;
    const chartConversions = <?= json_encode($chartConversions ?? []) ?>;
    const chartRevenue = <?= json_encode($chartRevenue ?? []) ?>;
    const chartCost = <?= json_encode($chartCost ?? []) ?>;
    
    // Debug logging
    console.log('Campaign stats chart data loaded:', {
        labelsCount: chartLabels.length,
        labelsArray: chartLabels,
        visitorsCount: chartVisitors.length,
        visitorsArray: chartVisitors,
        clicksCount: chartClicks.length,
        clicksArray: chartClicks,
        conversionsCount: chartConversions.length,
        conversionsArray: chartConversions,
        revenueCount: chartRevenue.length,
        revenueArray: chartRevenue,
        costCount: chartCost.length,
        costArray: chartCost,
        labelsSample: chartLabels.slice(0, 5),
        clicksSample: chartClicks.slice(0, 5),
        visitorsSample: chartVisitors.slice(0, 5),
        conversionsSample: chartConversions.slice(0, 5)
    });
    
    // Validate data arrays exist and are arrays (empty arrays are OK)
    if (!Array.isArray(chartLabels)) {
        console.error('Chart labels are invalid:', chartLabels);
        return;
    }
    
    const chartType = '<?= $chartView ?>';
    let datasets = [];
    
    if (chartType === 'visitors_clicks_conversions') {
        // Validate arrays
        if (!Array.isArray(chartVisitors) || !Array.isArray(chartClicks) || !Array.isArray(chartConversions)) {
            console.error('Chart data arrays are invalid');
            return;
        }
        
        // Ensure all arrays have the same length (pad with zeros if needed)
        const maxLength = Math.max(chartLabels.length, chartVisitors.length, chartClicks.length, chartConversions.length);
        const visitorsData = [...chartVisitors];
        const clicksData = [...chartClicks];
        const conversionsData = [...chartConversions];
        
        while (visitorsData.length < maxLength) visitorsData.push(0);
        while (clicksData.length < maxLength) clicksData.push(0);
        while (conversionsData.length < maxLength) conversionsData.push(0);
        
        datasets = [
            {
                label: 'Visitors',
                data: visitorsData,
                borderColor: '#3d5a26',
                backgroundColor: 'rgba(61, 90, 38, 0.1)',
                borderWidth: 2,
                fill: false,
                tension: 0.4
            },
            {
                label: 'Clicks',
                data: clicksData,
                borderColor: '#8a2be2',
                backgroundColor: 'rgba(138, 43, 226, 0.1)',
                borderWidth: 2,
                fill: false,
                tension: 0.4
            },
            {
                label: 'Conversions',
                data: conversionsData,
                borderColor: '#ff8c00',
                backgroundColor: 'rgba(255, 140, 0, 0.1)',
                borderWidth: 2,
                fill: false,
                tension: 0.4
            }
        ];
    } else if (chartType === 'revenue') {
        if (!Array.isArray(chartRevenue)) {
            console.error('Revenue data is invalid');
            return;
        }
        
        // Ensure array has the same length as labels
        const revenueData = [...chartRevenue];
        while (revenueData.length < chartLabels.length) revenueData.push(0);
        
        datasets = [
            {
                label: 'Revenue',
                data: revenueData,
                borderColor: '#28a745',
                backgroundColor: 'rgba(40, 167, 69, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4
            }
        ];
    } else if (chartType === 'cost') {
        if (!Array.isArray(chartCost)) {
            console.error('Cost data is invalid');
            return;
        }
        
        // Ensure array has the same length as labels
        const costData = [...chartCost];
        while (costData.length < chartLabels.length) costData.push(0);
        
        datasets = [
            {
                label: 'Cost',
                data: costData,
                borderColor: '#d32f2f',
                backgroundColor: 'rgba(211, 47, 47, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4
            }
        ];
    }
    
    if (datasets.length === 0) {
        console.warn('No datasets configured for chart type:', chartType);
        return;
    }
    
    if (chartLabels.length === 0) {
        console.warn('No chart data available - rendering empty chart');
    }
    
    try {
        console.log('Creating campaign stats chart with data:', {
            labelsCount: chartLabels.length,
            datasetsCount: datasets.length,
            firstDatasetSample: datasets[0]?.data?.slice(0, 3)
        });
        
        window.campaignStatsChartInstance = new Chart(ctx.getContext('2d'), {
        type: 'line',
        data: {
            labels: chartLabels,
            datasets: datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        color: '#2c3e2d',
                        font: {
                            size: 12,
                            family: 'Arial, sans-serif'
                        },
                        padding: 15
                    }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 12,
                    titleColor: '#fff',
                    bodyColor: '#fff',
                    borderColor: '#3d5a26',
                    borderWidth: 1
                }
            },
            scales: {
                x: {
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)',
                        display: true
                    },
                    ticks: {
                        color: '#666',
                        font: {
                            size: 11
                        }
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)',
                        display: true
                    },
                    ticks: {
                        color: '#666',
                        font: {
                            size: 11
                        }
                    }
                }
            },
            interaction: {
                mode: 'nearest',
                axis: 'x',
                intersect: false
            }
        }
    });
    
    console.log('Campaign stats chart created successfully:', window.campaignStatsChartInstance);
    } catch (error) {
        console.error('Error initializing campaign stats chart:', error);
        // Show error message to user
        const errorDiv = document.createElement('div');
        errorDiv.style.cssText = 'padding: 20px; background: #ffebee; color: #c62828; border-radius: 4px; margin: 20px 0;';
        errorDiv.textContent = 'Error loading chart: ' + error.message;
        ctx.parentElement.appendChild(errorDiv);
    }
});
</script>

<script>
// AJAX loading for stats data
(function() {
    const useAjaxLoading = <?= $useAjaxLoading ? 'true' : 'false' ?>;
    console.log('AJAX Loading Check:', {
        useAjaxLoading: useAjaxLoading,
        viewMode: '<?= $viewMode ?>',
        hasTokens: <?= !empty($selectedTokens) ? 'true' : 'false' ?>,
        isDataRequest: <?= $isDataRequest ? 'true' : 'false' ?>,
        hasCachedData: <?= $hasCachedData ? 'true' : 'false' ?>
    });
    
    if (useAjaxLoading) {
        console.log('Starting AJAX data fetch...');
        const loadingContainer = document.getElementById('stats-loading-container');
        const dataContainer = document.getElementById('stats-data-container');
        
        if (!loadingContainer || !dataContainer) {
            console.error('Loading container or data container not found!');
            return;
        }
        
        // Ensure loading spinner is visible and data container is hidden
        loadingContainer.style.display = 'flex';
        dataContainer.classList.remove('loaded');
        
        // Build URL with current parameters (omit target_only so server returns full data: offer, LP, campaign, chart)
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.set('format', 'json');
        const apiUrl = window.location.pathname + '?' + urlParams.toString();
        
        console.log('Fetching from:', apiUrl);
        
        // Fetch data via AJAX
        fetch(apiUrl, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin' // Include cookies for session
        })
        .then(response => {
            console.log('Response status:', response.status);
            if (!response.ok) {
                throw new Error('Network response was not ok: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            console.log('Data received:', data);
            if (data.success) {
                // Reload page to show data (data is cached in session)
                urlParams.delete('format');
                const cleanUrl = window.location.pathname + '?' + urlParams.toString();
                console.log('Reloading with data:', cleanUrl);
                window.location.href = cleanUrl;
            } else {
                throw new Error(data.error || 'Failed to load data');
            }
        })
        .catch(error => {
            console.error('Error loading stats:', error);
            let errorMsg = 'Error loading stats: ' + error.message;
            // Detect timeout errors (524 = Cloudflare timeout, 504 = Gateway timeout)
            if (error.message.includes('524') || error.message.includes('504') || error.message.includes('timeout')) {
                errorMsg = 'Query timeout: The date range is too large. Please try a shorter range (7 days or less) or remove token filters.';
            }
            loadingContainer.innerHTML = '<div style="color: #dc3545; padding: 20px; text-align: center; background: #ffebee; border-radius: 8px; margin: 20px;">' + 
                errorMsg + 
                '<br><button onclick="window.location.reload()" style="margin-top: 10px; padding: 8px 16px; background: #3d5a26; color: white; border: none; border-radius: 4px; cursor: pointer;">Retry</button></div>';
        });
    } else {
        console.log('Stats page loaded - data calculated synchronously (useAjaxLoading=false)');
    }
})();
</script>

    </div> <!-- End stats-data-container -->

<?php $db->close(); ?>

