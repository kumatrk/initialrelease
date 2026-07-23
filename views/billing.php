<?php
// Billing Reports Page
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Utils/Formatter.php';
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

// Get permission instance
$permission = $GLOBALS['permission'] ?? null;
if (!$permission || !$permission->hasPermission(\SimpleKuma\Auth\Permission::PERM_BILLING_VIEW)) {
    http_response_code(403);
    die('Access denied');
}

// Get database connection
$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$userTimezone = $GLOBALS['userTimezone'] ?? 'UTC';
$userCurrency = $GLOBALS['userCurrency'] ?? 'USD';

// Normalize timezone for MySQL CONVERT_TZ (requires proper identifiers)
// This matches the dashboard logic exactly
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

// Check if traffic_source_id column exists in clicks table
$trafficSourceColumnExists = false;
$checkColumn = $db->query("SELECT COUNT(*) as count FROM information_schema.COLUMNS 
                            WHERE TABLE_SCHEMA = DATABASE() 
                            AND TABLE_NAME = 'clicks' 
                            AND COLUMN_NAME = 'traffic_source_id'");
if ($checkColumn && $row = $checkColumn->fetch_assoc()) {
    $trafficSourceColumnExists = ($row['count'] > 0);
}

// Date range - default to this month (in user's timezone)
try {
    $tz = new DateTimeZone($userTimezone);
    $now = new DateTime('now', $tz);
    $firstDayOfMonth = clone $now;
    $firstDayOfMonth->modify('first day of this month');
    $todayInUserTz = $now->format('Y-m-d');
    $firstDayInUserTz = $firstDayOfMonth->format('Y-m-d');
} catch (Exception $e) {
    $tz = new DateTimeZone('UTC');
    $now = new DateTime('now', $tz);
    $firstDayOfMonth = clone $now;
    $firstDayOfMonth->modify('first day of this month');
    $todayInUserTz = $now->format('Y-m-d');
    $firstDayInUserTz = $firstDayOfMonth->format('Y-m-d');
}
$dateFrom = $_GET['date_from'] ?? $todayInUserTz;
$dateTo = $_GET['date_to'] ?? $todayInUserTz;

// Convert user's date range to UTC for database queries (like dashboard and visitor log)
$utcDateRange = Formatter::convertDateRangeToUTC($dateFrom, $dateTo, $userTimezone);
$utcDateFrom = $utcDateRange['from'];
$utcDateTo = $utcDateRange['to'];

// Calculate preset dates in user's timezone (for active preset detection)
// $tz and $now are already set above

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

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    require_once __DIR__ . '/../src/Facebook/FacebookCostAggregator.php';
    $costAggregator = new \SimpleKuma\Facebook\FacebookCostAggregator($db);
    
    // Get traffic source breakdown
    // Convert user's date range to UTC for database queries
    $utcDateRange = Formatter::convertDateRangeToUTC($dateFrom, $dateTo, $userTimezone);
    $utcDateFrom = $utcDateRange['from'];
    $utcDateTo = $utcDateRange['to'];
    
    // Join clicks directly to traffic_sources using click's traffic_source_id (if available) or campaign's traffic_source_id
    // Use subqueries to avoid multiplication from conversions join
    if ($trafficSourceColumnExists) {
        $tsQuery = $db->prepare("
            SELECT 
                click_stats.traffic_source_id as id,
                ts.name,
                COALESCE(click_stats.views, 0) as views,
                COALESCE(click_stats.lp_clicks, 0) as lp_clicks,
                COALESCE(conv_stats.conversions, 0) as conversions,
                COALESCE(click_stats.manual_cost, 0) as manual_cost,
                COALESCE(conv_stats.revenue, 0) as revenue
            FROM (
                SELECT 
                    traffic_source_id,
                    COUNT(DISTINCT id) as views,
                    COUNT(DISTINCT CASE WHEN lp_click = 1 THEN id END) as lp_clicks,
                    COALESCE(SUM(cost), 0) as manual_cost
                FROM clicks
                WHERE ts >= ? AND ts <= ? AND traffic_source_id IS NOT NULL
                GROUP BY traffic_source_id
            ) as click_stats
            INNER JOIN traffic_sources ts ON ts.id = click_stats.traffic_source_id
            LEFT JOIN (
                SELECT 
                    cl.traffic_source_id,
                    COUNT(DISTINCT conv.id) as conversions,
                    COALESCE(SUM(COALESCE(conv.payout, conv.value)), 0) as revenue
                FROM clicks cl
                INNER JOIN conversions conv ON cl.click_id = conv.click_id
                WHERE cl.ts >= ? AND cl.ts <= ? AND cl.traffic_source_id IS NOT NULL
                GROUP BY cl.traffic_source_id
            ) as conv_stats ON click_stats.traffic_source_id = conv_stats.traffic_source_id
            ORDER BY ts.name, click_stats.traffic_source_id
        ");
    } else {
        // Fallback: Use campaign's traffic_source_id
        $tsQuery = $db->prepare("
            SELECT 
                click_stats.traffic_source_id as id,
                ts.name,
                COALESCE(click_stats.views, 0) as views,
                COALESCE(click_stats.lp_clicks, 0) as lp_clicks,
                COALESCE(conv_stats.conversions, 0) as conversions,
                COALESCE(click_stats.manual_cost, 0) as manual_cost,
                COALESCE(conv_stats.revenue, 0) as revenue
            FROM (
                SELECT 
                    cp.traffic_source_id,
                    COUNT(DISTINCT cl.id) as views,
                    COUNT(DISTINCT CASE WHEN cl.lp_click = 1 THEN cl.id END) as lp_clicks,
                    COALESCE(SUM(cl.cost), 0) as manual_cost
                FROM clicks cl
                INNER JOIN campaigns cp ON cl.campaign_id = cp.id
                WHERE cl.ts >= ? AND cl.ts <= ? AND cp.traffic_source_id IS NOT NULL
                GROUP BY cp.traffic_source_id
            ) as click_stats
            INNER JOIN traffic_sources ts ON ts.id = click_stats.traffic_source_id
            LEFT JOIN (
                SELECT 
                    cp.traffic_source_id,
                    COUNT(DISTINCT conv.id) as conversions,
                    COALESCE(SUM(COALESCE(conv.payout, conv.value)), 0) as revenue
                FROM clicks cl
                INNER JOIN campaigns cp ON cl.campaign_id = cp.id
                INNER JOIN conversions conv ON cl.click_id = conv.click_id
                WHERE cl.ts >= ? AND cl.ts <= ? AND cp.traffic_source_id IS NOT NULL
                GROUP BY cp.traffic_source_id
            ) as conv_stats ON click_stats.traffic_source_id = conv_stats.traffic_source_id
            ORDER BY ts.name, click_stats.traffic_source_id
        ");
    }
    $tsQuery->bind_param('ssss', $utcDateFrom, $utcDateTo, $utcDateFrom, $utcDateTo);
    $tsQuery->execute();
    $tsData = $tsQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Get Facebook costs (use UTC dates for aggregator)
    // Note: getTrafficSourceCost returns total cost (manual + Facebook), so we need to extract just Facebook cost
    foreach ($tsData as &$ts) {
        $totalCost = $costAggregator->getTrafficSourceCost($ts['id'], $utcDateFrom, $utcDateTo, $userTimezone);
        $fbCost = $totalCost - (float)$ts['manual_cost'];
        if ($fbCost < 0) {
            $fbCost = 0; // Safety check
        }
        $ts['fb_cost'] = $fbCost;
        $ts['total_cost'] = $totalCost; // Already includes manual + Facebook
    }
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="billing-report-' . $dateFrom . '-to-' . $dateTo . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Summary
    $totalRevenue = array_sum(array_column($tsData, 'revenue'));
    $totalCost = array_sum(array_column($tsData, 'total_cost'));
    $totalProfit = $totalRevenue - $totalCost;
    $totalRoi = $totalCost > 0 ? (($totalProfit / $totalCost) * 100) : 0;
    
    fputcsv($output, ['Billing Report']);
    fputcsv($output, ['Date Range', $dateFrom . ' to ' . $dateTo]);
    fputcsv($output, ['']);
    fputcsv($output, ['Summary']);
    fputcsv($output, ['Total Revenue', Formatter::formatCurrency($totalRevenue, $userCurrency)]);
    fputcsv($output, ['Total Spend', Formatter::formatCurrency($totalCost, $userCurrency)]);
    fputcsv($output, ['Net Profit/Loss', Formatter::formatCurrency($totalProfit, $userCurrency)]);
    fputcsv($output, ['ROI', number_format($totalRoi, 2) . '%']);
    fputcsv($output, ['']);
    fputcsv($output, ['Traffic Source Breakdown']);
    fputcsv($output, ['Traffic Source', 'Views', 'Clicks', 'Conversions', 'Revenue', 'Spend', 'Profit', 'ROI %', 'CPC', 'CPA', 'EPC']);
    
    foreach ($tsData as $ts) {
        $views = (int)$ts['views'];
        $clicks = (int)$ts['lp_clicks'];
        $conv = (int)$ts['conversions'];
        $rev = (float)$ts['revenue'];
        $cost = (float)$ts['total_cost'];
        $profit = $rev - $cost;
        $roi = $cost > 0 ? (($profit / $cost) * 100) : 0;
        $cpc = $views > 0 ? $cost / $views : 0;
        $cpa = $conv > 0 ? $cost / $conv : 0;
        $epc = $clicks > 0 ? $rev / $clicks : 0;
        
        fputcsv($output, [
            $ts['name'],
            $views,
            $clicks,
            $conv,
            Formatter::formatCurrency($rev, $userCurrency),
            Formatter::formatCurrency($cost, $userCurrency),
            Formatter::formatCurrency($profit, $userCurrency),
            number_format($roi, 2),
            Formatter::formatCurrency($cpc, $userCurrency),
            Formatter::formatCurrency($cpa, $userCurrency),
            Formatter::formatCurrency($epc, $userCurrency),
        ]);
    }
    
    fclose($output);
    $db->close();
    exit;
}

require_once __DIR__ . '/../src/Facebook/FacebookCostAggregator.php';
$costAggregator = new \SimpleKuma\Facebook\FacebookCostAggregator($db);

// Get traffic source breakdown
// Join clicks directly to traffic_sources using click's traffic_source_id (if available) or campaign's traffic_source_id
// Use subqueries to avoid multiplication from conversions join
if ($trafficSourceColumnExists) {
        $tsQuery = $db->prepare("
            SELECT 
                click_stats.traffic_source_id as id,
                ts.name,
                COALESCE(click_stats.views, 0) as views,
                COALESCE(click_stats.lp_clicks, 0) as lp_clicks,
                COALESCE(conv_stats.conversions, 0) as conversions,
                COALESCE(click_stats.manual_cost, 0) as manual_cost,
                COALESCE(conv_stats.revenue, 0) as revenue
            FROM (
                SELECT 
                    traffic_source_id,
                    COUNT(DISTINCT id) as views,
                    COUNT(DISTINCT CASE WHEN lp_click = 1 THEN id END) as lp_clicks,
                    COALESCE(SUM(cost), 0) as manual_cost
                FROM clicks
                WHERE ts >= ? AND ts <= ? AND traffic_source_id IS NOT NULL
                GROUP BY traffic_source_id
            ) as click_stats
            INNER JOIN traffic_sources ts ON ts.id = click_stats.traffic_source_id
            LEFT JOIN (
                SELECT 
                    cl.traffic_source_id,
                    COUNT(DISTINCT conv.id) as conversions,
                    COALESCE(SUM(COALESCE(conv.payout, conv.value)), 0) as revenue
                FROM clicks cl
                INNER JOIN conversions conv ON cl.click_id = conv.click_id
                WHERE cl.ts >= ? AND cl.ts <= ? AND cl.traffic_source_id IS NOT NULL
                GROUP BY cl.traffic_source_id
            ) as conv_stats ON ts.id = conv_stats.traffic_source_id
            ORDER BY ts.name, ts.id
        ");
    } else {
        // Fallback: Use campaign's traffic_source_id
        $tsQuery = $db->prepare("
            SELECT 
                click_stats.traffic_source_id as id,
                ts.name,
                COALESCE(click_stats.views, 0) as views,
                COALESCE(click_stats.lp_clicks, 0) as lp_clicks,
                COALESCE(conv_stats.conversions, 0) as conversions,
                COALESCE(click_stats.manual_cost, 0) as manual_cost,
                COALESCE(conv_stats.revenue, 0) as revenue
            FROM (
                SELECT 
                    cp.traffic_source_id,
                    COUNT(DISTINCT cl.id) as views,
                    COUNT(DISTINCT CASE WHEN cl.lp_click = 1 THEN cl.id END) as lp_clicks,
                    COALESCE(SUM(cl.cost), 0) as manual_cost
                FROM clicks cl
                INNER JOIN campaigns cp ON cl.campaign_id = cp.id
                WHERE cl.ts >= ? AND cl.ts <= ? AND cp.traffic_source_id IS NOT NULL
                GROUP BY cp.traffic_source_id
            ) as click_stats
            INNER JOIN traffic_sources ts ON ts.id = click_stats.traffic_source_id
            LEFT JOIN (
                SELECT 
                    cp.traffic_source_id,
                    COUNT(DISTINCT conv.id) as conversions,
                    COALESCE(SUM(COALESCE(conv.payout, conv.value)), 0) as revenue
                FROM clicks cl
                INNER JOIN campaigns cp ON cl.campaign_id = cp.id
                INNER JOIN conversions conv ON cl.click_id = conv.click_id
                WHERE cl.ts >= ? AND cl.ts <= ? AND cp.traffic_source_id IS NOT NULL
                GROUP BY cp.traffic_source_id
            ) as conv_stats ON ts.id = conv_stats.traffic_source_id
            ORDER BY ts.name, ts.id
        ");
    }
$tsQuery->bind_param('ssss', $utcDateFrom, $utcDateTo, $utcDateFrom, $utcDateTo);
$tsQuery->execute();
$tsData = $tsQuery->get_result()->fetch_all(MYSQLI_ASSOC);

// Get Facebook costs and add to totals (use UTC dates for aggregator)
// Note: getTrafficSourceCost returns total cost (manual + Facebook), so we need to extract just Facebook cost
foreach ($tsData as &$ts) {
    $totalCost = $costAggregator->getTrafficSourceCost($ts['id'], $utcDateFrom, $utcDateTo, $userTimezone);
    $fbCost = $totalCost - (float)$ts['manual_cost'];
    if ($fbCost < 0) {
        $fbCost = 0; // Safety check
    }
    $ts['fb_cost'] = $fbCost;
    $ts['total_cost'] = $totalCost; // Already includes manual + Facebook
}

// Calculate summary
// CRITICAL FIX: Sum the traffic source costs we already calculated correctly
// getTrafficSourceCost uses cumulative MAX and includes all costs (including gutters) correctly
// This matches what's shown in the traffic source breakdown table
// The individual traffic source calculations are working correctly (Facebook shows $52.12)
$totalRevenue = array_sum(array_column($tsData, 'revenue'));
$totalCost = array_sum(array_column($tsData, 'total_cost'));
$totalProfit = $totalRevenue - $totalCost;
$totalRoi = $totalCost > 0 ? (($totalProfit / $totalCost) * 100) : 0;
$totalViews = array_sum(array_column($tsData, 'views'));
$totalClicks = array_sum(array_column($tsData, 'lp_clicks'));
$totalConversions = array_sum(array_column($tsData, 'conversions'));

// Get campaign breakdown for each traffic source
// Use same pattern as campaigns overview: start from campaigns, LEFT JOIN clicks and conversions
$campaignData = [];
foreach ($tsData as $ts) {
    if ($trafficSourceColumnExists) {
        // Get all campaigns for this traffic source (like campaigns overview gets all campaigns)
        // Then query stats for campaigns that have clicks with this traffic_source_id
        $allCampsQuery = $db->prepare("
            SELECT DISTINCT cp.id, cp.name
            FROM campaigns cp
            WHERE cp.traffic_source_id = ?
            UNION
            SELECT DISTINCT cp.id, cp.name
            FROM campaigns cp
            INNER JOIN clicks cl ON cp.id = cl.campaign_id
            WHERE cl.traffic_source_id = ?
                AND cl.ts >= ? AND cl.ts <= ?
        ");
        $allCampsQuery->bind_param('iiss', $ts['id'], $ts['id'], $utcDateFrom, $utcDateTo);
        $allCampsQuery->execute();
        $allCampsResult = $allCampsQuery->get_result();
        $allCampaigns = [];
        $campaignNameMap = []; // Map campaign_id => name for lookup
        $duplicateCount = 0;
        while ($row = $allCampsResult->fetch_assoc()) {
            $campId = (int)$row['id'];
            // Deduplicate by ID - only keep first occurrence
            if (!isset($campaignNameMap[$campId])) {
                $allCampaigns[] = ['id' => $campId, 'name' => $row['name']];
                $campaignNameMap[$campId] = $row['name'];
            } else {
                $duplicateCount++;
            }
        }
        $allCampsQuery->close();
        
        $campaignIds = array_column($allCampaigns, 'id');
        $campaignStats = [];
        
        if (!empty($campaignIds)) {
            // Query stats exactly like campaigns overview does
            $idsPlaceholder = implode(',', array_fill(0, count($campaignIds), '?'));
            $statsQuery = $db->prepare("
                SELECT 
                    cl.campaign_id,
                    COUNT(DISTINCT cl.id) as views,
                    COUNT(DISTINCT CASE WHEN cl.lp_click = 1 THEN cl.id END) as lp_clicks,
                    COUNT(DISTINCT conv.id) as conversions,
                    COALESCE(SUM(cl.cost), 0) as manual_cost,
                    COALESCE(SUM(COALESCE(conv.payout, conv.value)), 0) as revenue
                FROM clicks cl
                INNER JOIN campaigns cp ON cl.campaign_id = cp.id
                LEFT JOIN conversions conv ON cl.click_id = conv.click_id
                WHERE cl.campaign_id IN ($idsPlaceholder)
                    AND cl.traffic_source_id = ?
                    AND cl.ts >= ? AND cl.ts <= ?
                GROUP BY cl.campaign_id
            ");
            $types = str_repeat('i', count($campaignIds)) . 'iss';
            $params = array_merge($campaignIds, [$ts['id'], $utcDateFrom, $utcDateTo]);
            $statsQuery->bind_param($types, ...$params);
            $statsQuery->execute();
            $statsResult = $statsQuery->get_result();
            
            while ($stat = $statsResult->fetch_assoc()) {
                $campaignId = (int)$stat['campaign_id'];
                $manualCost = (float)$stat['manual_cost'];
                
                // Get Facebook costs for this campaign (exactly like campaigns overview)
                // Pass userTimezone parameter for proper timezone-aware date filtering (critical for correct cost calculation)
                $totalCostFromAggregator = $costAggregator->getAggregatedCost($utcDateFrom, $utcDateTo, 'AND cl.campaign_id = ? AND traffic_source_id = ?', [$campaignId, $ts['id']], $userTimezone);
                $fbCost = $totalCostFromAggregator - $manualCost;
                if ($fbCost < 0) {
                    $fbCost = 0;
                }
                $totalCost = $manualCost + $fbCost;
                
                $campaignStats[$campaignId] = [
                    'id' => $campaignId,
                    'name' => $campaignNameMap[$campaignId] ?? '',
                    'views' => (int)$stat['views'],
                    'lp_clicks' => (int)$stat['lp_clicks'],
                    'conversions' => (int)$stat['conversions'],
                    'manual_cost' => $manualCost,
                    'revenue' => (float)$stat['revenue'],
                    'total_cost' => $totalCost,
                ];
            }
            $statsQuery->close();
        }
        
        // Merge stats into campaigns (like campaigns overview does)
        // Use a map to ensure no duplicates by campaign ID
        $campsMap = [];
        $mergeDuplicates = 0;
        foreach ($allCampaigns as $camp) {
            $campId = $camp['id'];
            // Skip if we already processed this campaign ID
            if (isset($campsMap[$campId])) {
                $mergeDuplicates++;
                continue;
            }
            
            if (isset($campaignStats[$campId])) {
                $campsMap[$campId] = $campaignStats[$campId];
            } else {
                // Campaign with no clicks - show with 0 stats
                $campsMap[$campId] = [
                    'id' => $campId,
                    'name' => $camp['name'],
                    'views' => 0,
                    'lp_clicks' => 0,
                    'conversions' => 0,
                    'manual_cost' => 0,
                    'revenue' => 0,
                    'total_cost' => 0,
                ];
            }
        }
        
        // Convert map to array (ensures no duplicates)
        $camps = array_values($campsMap);
        
        error_log("Billing DEBUG: Traffic Source {$ts['id']}: Final campaigns array has " . count($camps) . " entries (skipped {$mergeDuplicates} duplicates in merge)");
        foreach ($camps as $c) {
            error_log("Billing DEBUG: Campaign: ID={$c['id']}, Name={$c['name']}, Views={$c['views']}, Clicks={$c['lp_clicks']}, Conv={$c['conversions']}, Cost={$c['total_cost']}, Revenue={$c['revenue']}");
        }
        
        // Skip the foreach loop below since we already processed costs
        $campaignData[$ts['id']] = $camps;
        continue;
    } else {
        // Fallback: Use campaign's traffic_source_id
        $campQuery = $db->prepare("
            SELECT 
                cp.id,
                cp.name,
                    COUNT(DISTINCT cl.id) as views,
                    COUNT(DISTINCT CASE WHEN cl.lp_click = 1 THEN cl.id END) as lp_clicks,
                    COUNT(DISTINCT conv.id) as conversions,
                COALESCE(SUM(cl.cost), 0) as manual_cost,
                    COALESCE(SUM(COALESCE(conv.payout, conv.value)), 0) as revenue
            FROM campaigns cp
            LEFT JOIN clicks cl ON cp.id = cl.campaign_id 
                    AND cl.ts >= ? AND cl.ts <= ?
            LEFT JOIN conversions conv ON cl.click_id = conv.click_id
            WHERE cp.traffic_source_id = ?
            GROUP BY cp.id, cp.name
            ORDER BY cp.name
        ");
        $campQuery->bind_param('ssi', $utcDateFrom, $utcDateTo, $ts['id']);
    }
    
    $campQuery->execute();
    $camps = $campQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    
    foreach ($camps as $campIdx => $campaignItem) {
        // Get Facebook cost for this campaign AND traffic source (not all traffic sources)
        // Use getAggregatedCost with both campaign_id and traffic_source_id filters
        // Note: getAggregatedCost returns total cost (manual + Facebook), so we need to extract just Facebook cost
        if ($trafficSourceColumnExists) {
            $campaignFilter = " AND campaign_id = ? AND traffic_source_id = ?";
            $totalCost = $costAggregator->getAggregatedCost($utcDateFrom, $utcDateTo, $campaignFilter, [$campaignItem['id'], $ts['id']], $userTimezone);
        } else {
            // Fallback: campaign-only — use same allocator as dashboard KPI
            $totalCost = $costAggregator->getCampaignTotalCost(
                (int)$campaignItem['id'],
                $utcDateFrom,
                $utcDateTo,
                $userTimezone
            );
        }
        $fbCost = $totalCost - (float)$campaignItem['manual_cost'];
        if ($fbCost < 0) {
            $fbCost = 0; // Safety check
        }
        $camps[$campIdx]['fb_cost'] = $fbCost;
        $camps[$campIdx]['total_cost'] = $totalCost; // Already includes manual + Facebook
    }
    
    $campaignData[$ts['id']] = $camps;
}
?>

<div class="page-header">
    <h1 class="page-title">Billing Reports</h1>
    <p class="page-description">Financial breakdown by traffic source and campaign</p>
</div>

<!-- Date Range Selector -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-body">
        <form id="billing-date-form" method="GET" action="?page=billing" style="display: flex; flex-direction: column; gap: 8px; align-items: flex-end;">
            <input type="hidden" name="page" value="billing">
            
            <!-- Minimal Preset Buttons -->
            <?php
            // All time preset: 2025-01-01 to today
            $allTimeStart = '2025-01-01';
            
            // Detect active preset
            $activePreset = null;
            if ($dateFrom === $today && $dateTo === $today) $activePreset = 'today';
            elseif ($dateFrom === $yesterday && $dateTo === $yesterday) $activePreset = 'yesterday';
            elseif ($dateFrom === $last7Start && $dateTo === $today) $activePreset = 'last7';
            elseif ($dateFrom === $last14Start && $dateTo === $today) $activePreset = 'last14';
            elseif ($dateFrom === $last30Start && $dateTo === $today) $activePreset = 'last30';
            elseif ($dateFrom === $lastMonthStart && $dateTo === $lastMonthEnd) $activePreset = 'lastmonth';
            elseif ($dateFrom === $thisMonthStart && $dateTo === $today) $activePreset = 'thismonth';
            elseif ($dateFrom === $allTimeStart && $dateTo === $today) $activePreset = 'alltime';
            ?>
            <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                <button type="button" onclick="setBillingDate('today')" style="padding: 4px 8px; font-size: 11px; border: 1px solid #ddd; border-radius: 3px; background: <?= $activePreset === 'today' ? '#3d5a26' : '#fff' ?>; color: <?= $activePreset === 'today' ? '#fff' : '#666' ?>; cursor: pointer;">Today</button>
                <button type="button" onclick="setBillingDate('yesterday')" style="padding: 4px 8px; font-size: 11px; border: 1px solid #ddd; border-radius: 3px; background: <?= $activePreset === 'yesterday' ? '#3d5a26' : '#fff' ?>; color: <?= $activePreset === 'yesterday' ? '#fff' : '#666' ?>; cursor: pointer;">Yesterday</button>
                <button type="button" onclick="setBillingDate('last7')" style="padding: 4px 8px; font-size: 11px; border: 1px solid #ddd; border-radius: 3px; background: <?= $activePreset === 'last7' ? '#3d5a26' : '#fff' ?>; color: <?= $activePreset === 'last7' ? '#fff' : '#666' ?>; cursor: pointer;">7d</button>
                <button type="button" onclick="setBillingDate('last14')" style="padding: 4px 8px; font-size: 11px; border: 1px solid #ddd; border-radius: 3px; background: <?= $activePreset === 'last14' ? '#3d5a26' : '#fff' ?>; color: <?= $activePreset === 'last14' ? '#fff' : '#666' ?>; cursor: pointer;">14d</button>
                <button type="button" onclick="setBillingDate('last30')" style="padding: 4px 8px; font-size: 11px; border: 1px solid #ddd; border-radius: 3px; background: <?= $activePreset === 'last30' ? '#3d5a26' : '#fff' ?>; color: <?= $activePreset === 'last30' ? '#fff' : '#666' ?>; cursor: pointer;">30d</button>
                <button type="button" onclick="setBillingDate('lastmonth')" style="padding: 4px 8px; font-size: 11px; border: 1px solid #ddd; border-radius: 3px; background: <?= $activePreset === 'lastmonth' ? '#3d5a26' : '#fff' ?>; color: <?= $activePreset === 'lastmonth' ? '#fff' : '#666' ?>; cursor: pointer;">Last Mo</button>
                <button type="button" onclick="setBillingDate('thismonth')" style="padding: 4px 8px; font-size: 11px; border: 1px solid #ddd; border-radius: 3px; background: <?= $activePreset === 'thismonth' ? '#3d5a26' : '#fff' ?>; color: <?= $activePreset === 'thismonth' ? '#fff' : '#666' ?>; cursor: pointer;">This Mo</button>
                <button type="button" onclick="setBillingDate('alltime')" style="padding: 4px 8px; font-size: 11px; border: 1px solid #ddd; border-radius: 3px; background: <?= $activePreset === 'alltime' ? '#3d5a26' : '#fff' ?>; color: <?= $activePreset === 'alltime' ? '#fff' : '#666' ?>; cursor: pointer;">ALL TIME</button>
            </div>
            <div style="display: flex; gap: 12px; align-items: center; width: 100%;">
                <input type="date" name="date_from" id="billing_date_from" value="<?= htmlspecialchars($dateFrom) ?>" style="padding: 8px; border: 2px solid #ddd; border-radius: 4px; flex: 1;">
                <span style="color: #666;">to</span>
                <input type="date" name="date_to" id="billing_date_to" value="<?= htmlspecialchars($dateTo) ?>" style="padding: 8px; border: 2px solid #ddd; border-radius: 4px; flex: 1;">
                <button type="submit" class="btn btn-primary">Update</button>
            </div>
            <script>
            // Pre-calculated dates in user's timezone (from PHP)
            const billingDatePresets = {
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
            
            function setBillingDate(preset) {
                let fromDate, toDate;
                switch(preset) {
                    case 'today': 
                        fromDate = toDate = billingDatePresets.today; 
                        break;
                    case 'yesterday': 
                        fromDate = toDate = billingDatePresets.yesterday; 
                        break;
                    case 'last7': 
                        fromDate = billingDatePresets.last7Start; 
                        toDate = billingDatePresets.today; 
                        break;
                    case 'last14': 
                        fromDate = billingDatePresets.last14Start; 
                        toDate = billingDatePresets.today; 
                        break;
                    case 'last30': 
                        fromDate = billingDatePresets.last30Start; 
                        toDate = billingDatePresets.today; 
                        break;
                    case 'lastmonth': 
                        fromDate = billingDatePresets.lastMonthStart; 
                        toDate = billingDatePresets.lastMonthEnd; 
                        break;
                    case 'thismonth': 
                        fromDate = billingDatePresets.thisMonthStart; 
                        toDate = billingDatePresets.today; 
                        break;
                    case 'alltime': 
                        fromDate = billingDatePresets.allTimeStart; 
                        toDate = billingDatePresets.today; 
                        break;
                }
                document.getElementById('billing_date_from').value = fromDate;
                document.getElementById('billing_date_to').value = toDate;
                document.getElementById('billing-date-form').submit();
            }
            </script>
        </form>
    </div>
</div>

<!-- Summary Section -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header">
        <h2 class="card-title">Summary</h2>
    </div>
    <div class="card-body">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
            <div style="text-align: center; padding: 16px; background: #f5f5f5; border-radius: 8px;">
                <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Total Revenue</div>
                <div style="font-size: 32px; font-weight: 700; color: #28a745;"><?= Formatter::formatCurrency($totalRevenue, $userCurrency) ?></div>
            </div>
            
            <div style="text-align: center; padding: 16px; background: #f5f5f5; border-radius: 8px;">
                <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Total Spend</div>
                <div style="font-size: 32px; font-weight: 700; color: #d32f2f;"><?= Formatter::formatCurrency($totalCost, $userCurrency) ?></div>
            </div>
            
            <div style="text-align: center; padding: 16px; background: #f5f5f5; border-radius: 8px;">
                <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Net Profit/Loss</div>
                <div style="font-size: 32px; font-weight: 700; color: <?= $totalProfit >= 0 ? '#28a745' : '#d32f2f' ?>;">
                    <?= Formatter::formatCurrency($totalProfit, $userCurrency) ?>
                </div>
            </div>
            
            <div style="text-align: center; padding: 16px; background: #f5f5f5; border-radius: 8px;">
                <div style="font-size: 12px; color: #666; margin-bottom: 4px;">ROI</div>
                <div style="font-size: 32px; font-weight: 700; color: <?= $totalRoi >= 0 ? '#28a745' : '#d32f2f' ?>;">
                    <?= number_format($totalRoi, 2) ?>%
                </div>
            </div>
        </div>
        
        <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; font-size: 14px;">
                <div><strong>Total Views:</strong> <?= number_format($totalViews) ?></div>
                <div><strong>Total Clicks:</strong> <?= number_format($totalClicks) ?></div>
                <div><strong>Total Conversions:</strong> <?= number_format($totalConversions) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Traffic Source Breakdown -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <h2 class="card-title">Traffic Source Breakdown</h2>
        <a href="?page=billing&export=csv&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>" class="btn btn-secondary">📥 Export CSV</a>
    </div>
    <div class="card-body">
        <!-- Desktop Table View (hidden on mobile) -->
        <div class="table-wrapper desktop-only" style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f5f5f5; border-bottom: 2px solid #ddd;">
                        <th style="padding: 12px; text-align: left;">Traffic Source</th>
                        <th style="padding: 12px; text-align: right;">Views</th>
                        <th style="padding: 12px; text-align: right;">Clicks</th>
                        <th style="padding: 12px; text-align: right;">Conversions</th>
                        <th style="padding: 12px; text-align: right;">Revenue</th>
                        <th style="padding: 12px; text-align: right;">Spend</th>
                        <th style="padding: 12px; text-align: right;">Profit/Loss</th>
                        <th style="padding: 12px; text-align: right;">ROI %</th>
                        <th style="padding: 12px; text-align: right;">CPC</th>
                        <th style="padding: 12px; text-align: right;">CPA</th>
                        <th style="padding: 12px; text-align: right;">EPC</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tsData as $ts): 
                        $views = (int)$ts['views'];
                        $clicks = (int)$ts['lp_clicks'];
                        $conv = (int)$ts['conversions'];
                        $rev = (float)$ts['revenue'];
                        $cost = (float)$ts['total_cost'];
                        $profit = $rev - $cost;
                        $roi = $cost > 0 ? (($profit / $cost) * 100) : 0;
                        $cpc = $views > 0 ? $cost / $views : 0;
                        $cpa = $conv > 0 ? $cost / $conv : 0;
                        $epc = $clicks > 0 ? $rev / $clicks : 0;
                    ?>
                        <tr style="border-bottom: 1px solid #eee; cursor: pointer;" 
                            onclick="toggleCampaigns(<?= $ts['id'] ?>)">
                            <td style="padding: 12px;">
                                <strong><?= htmlspecialchars($ts['name']) ?></strong>
                                <span style="font-size: 12px; color: #999; margin-left: 8px;">▼</span>
                            </td>
                            <td style="padding: 12px; text-align: right;"><?= number_format($views) ?></td>
                            <td style="padding: 12px; text-align: right;"><?= number_format($clicks) ?></td>
                            <td style="padding: 12px; text-align: right;"><?= number_format($conv) ?></td>
                            <td style="padding: 12px; text-align: right; color: #28a745; font-weight: 600;">
                                <?= Formatter::formatCurrency($rev, $userCurrency) ?>
                            </td>
                            <td style="padding: 12px; text-align: right; color: #d32f2f; font-weight: 600;">
                                <?= Formatter::formatCurrency($cost, $userCurrency) ?>
                            </td>
                            <td style="padding: 12px; text-align: right; color: <?= $profit >= 0 ? '#28a745' : '#d32f2f' ?>; font-weight: 600;">
                                <?= Formatter::formatCurrency($profit, $userCurrency) ?>
                            </td>
                            <td style="padding: 12px; text-align: right; color: <?= $roi >= 0 ? '#28a745' : '#d32f2f' ?>; font-weight: 600;">
                                <?= number_format($roi, 2) ?>%
                            </td>
                            <td style="padding: 12px; text-align: right;"><?= Formatter::formatCurrency($cpc, $userCurrency) ?></td>
                            <td style="padding: 12px; text-align: right;"><?= Formatter::formatCurrency($cpa, $userCurrency) ?></td>
                            <td style="padding: 12px; text-align: right;"><?= Formatter::formatCurrency($epc, $userCurrency) ?></td>
                        </tr>
                        
                        <!-- Campaign breakdown (hidden by default) -->
                        <?php if (!empty($campaignData[$ts['id']])): ?>
                            <tr id="campaigns_<?= $ts['id'] ?>" style="display: none; background: #f9f9f9;">
                                <td colspan="11" style="padding: 0;">
                                    <div style="padding: 16px;">
                                        <h4 style="margin: 0 0 12px 0; font-size: 14px; color: #666;">Campaigns</h4>
                                        <table style="width: 100%; border-collapse: collapse;">
                                            <thead>
                                                <tr style="background: #f0f0f0;">
                                                    <th style="padding: 8px; text-align: left; font-size: 12px;">Campaign</th>
                                                    <th style="padding: 8px; text-align: right; font-size: 12px;">Views</th>
                                                    <th style="padding: 8px; text-align: right; font-size: 12px;">Clicks</th>
                                                    <th style="padding: 8px; text-align: right; font-size: 12px;">Conv</th>
                                                    <th style="padding: 8px; text-align: right; font-size: 12px;">Revenue</th>
                                                    <th style="padding: 8px; text-align: right; font-size: 12px;">Spend</th>
                                                    <th style="padding: 8px; text-align: right; font-size: 12px;">Profit</th>
                                                    <th style="padding: 8px; text-align: right; font-size: 12px;">ROI %</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                // Make a fresh copy of the array to avoid any reference issues
                                                $iteratingArray = isset($campaignData[$ts['id']]) ? array_values($campaignData[$ts['id']]) : [];
                                                foreach ($iteratingArray as $campaignItem) {
                                                    // Extract all values immediately to avoid any reference issues
                                                    $cViews = (int)$campaignItem['views'];
                                                    $cClicks = (int)$campaignItem['lp_clicks'];
                                                    $cConv = (int)$campaignItem['conversions'];
                                                    $cRev = (float)$campaignItem['revenue'];
                                                    $cCost = (float)$campaignItem['total_cost'];
                                                    $cProfit = $cRev - $cCost;
                                                    $cRoi = $cCost > 0 ? (($cProfit / $cCost) * 100) : 0;
                                                ?>
                                                    <tr>
                                                        <td style="padding: 8px; font-size: 12px;"><?= htmlspecialchars($campaignItem['name']) ?></td>
                                                        <td style="padding: 8px; text-align: right; font-size: 12px;"><?= number_format($cViews) ?></td>
                                                        <td style="padding: 8px; text-align: right; font-size: 12px;"><?= number_format($cClicks) ?></td>
                                                        <td style="padding: 8px; text-align: right; font-size: 12px;"><?= number_format($cConv) ?></td>
                                                        <td style="padding: 8px; text-align: right; font-size: 12px; color: #28a745;"><?= Formatter::formatCurrency($cRev, $userCurrency) ?></td>
                                                        <td style="padding: 8px; text-align: right; font-size: 12px; color: #d32f2f;"><?= Formatter::formatCurrency($cCost, $userCurrency) ?></td>
                                                        <td style="padding: 8px; text-align: right; font-size: 12px; color: <?= $cProfit >= 0 ? '#28a745' : '#d32f2f' ?>;">
                                                            <?= Formatter::formatCurrency($cProfit, $userCurrency) ?>
                                                        </td>
                                                        <td style="padding: 8px; text-align: right; font-size: 12px; color: <?= $cRoi >= 0 ? '#28a745' : '#d32f2f' ?>;">
                                                            <?= number_format($cRoi, 2) ?>%
                                                        </td>
                                                    </tr>
                                                <?php } ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Mobile Card View (hidden on desktop) -->
        <div class="mobile-billing-cards mobile-only">
            <?php foreach ($tsData as $ts): 
                $views = (int)$ts['views'];
                $clicks = (int)$ts['lp_clicks'];
                $conv = (int)$ts['conversions'];
                $rev = (float)$ts['revenue'];
                $cost = (float)$ts['total_cost'];
                $profit = $rev - $cost;
                $roi = $cost > 0 ? (($profit / $cost) * 100) : 0;
                $cpc = $views > 0 ? $cost / $views : 0;
                $cpa = $conv > 0 ? $cost / $conv : 0;
                $epc = $clicks > 0 ? $rev / $clicks : 0;
                $hasCampaigns = !empty($campaignData[$ts['id']]);
            ?>
                <div class="mobile-billing-card" style="background: var(--color-white); border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); padding: var(--spacing-md); margin-bottom: var(--spacing-md); box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <!-- Header: Traffic Source Name -->
                    <div style="margin-bottom: var(--spacing-sm); border-bottom: 1px solid rgba(0,0,0,0.1); padding-bottom: var(--spacing-xs);">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div style="font-weight: 600; font-size: 16px; color: #3d5a26;">
                                <?= htmlspecialchars($ts['name']) ?>
                            </div>
                            <?php if ($hasCampaigns): ?>
                                <button onclick="toggleMobileCampaigns(<?= $ts['id'] ?>)" 
                                        style="background: none; border: none; color: #666; cursor: pointer; font-size: 18px; padding: 4px 8px;"
                                        id="toggle_<?= $ts['id'] ?>">
                                    ▼
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Stats Grid -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-sm); margin-bottom: var(--spacing-sm);">
                        <div>
                            <div style="font-size: 11px; color: #666; margin-bottom: 4px;"><strong>Views</strong></div>
                            <div style="font-size: 14px; font-weight: 600;"><?= number_format($views) ?></div>
                        </div>
                        <div>
                            <div style="font-size: 11px; color: #666; margin-bottom: 4px;"><strong>Clicks</strong></div>
                            <div style="font-size: 14px; font-weight: 600;"><?= number_format($clicks) ?></div>
                        </div>
                        <div>
                            <div style="font-size: 11px; color: #666; margin-bottom: 4px;"><strong>Conversions</strong></div>
                            <div style="font-size: 14px; font-weight: 600;"><?= number_format($conv) ?></div>
                        </div>
                        <div>
                            <div style="font-size: 11px; color: #666; margin-bottom: 4px;"><strong>ROI</strong></div>
                            <div style="font-size: 14px; font-weight: 600; color: <?= $roi >= 0 ? '#28a745' : '#d32f2f' ?>;">
                                <?= number_format($roi, 2) ?>%
                            </div>
                        </div>
                    </div>
                    
                    <!-- Financial Metrics -->
                    <div style="margin-bottom: var(--spacing-sm); padding: var(--spacing-sm); background: #f9f9f9; border-radius: 4px;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-xs); font-size: 12px;">
                            <div>
                                <span style="color: #666;">Revenue:</span>
                                <span style="color: #28a745; font-weight: 600; margin-left: 4px;">
                                    <?= Formatter::formatCurrency($rev, $userCurrency) ?>
                                </span>
                            </div>
                            <div>
                                <span style="color: #666;">Spend:</span>
                                <span style="color: #d32f2f; font-weight: 600; margin-left: 4px;">
                                    <?= Formatter::formatCurrency($cost, $userCurrency) ?>
                                </span>
                            </div>
                            <div>
                                <span style="color: #666;">Profit:</span>
                                <span style="color: <?= $profit >= 0 ? '#28a745' : '#d32f2f' ?>; font-weight: 600; margin-left: 4px;">
                                    <?= Formatter::formatCurrency($profit, $userCurrency) ?>
                                </span>
                            </div>
                            <div>
                                <span style="color: #666;">CPC:</span>
                                <span style="font-weight: 600; margin-left: 4px;">
                                    <?= Formatter::formatCurrency($cpc, $userCurrency) ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Campaign Breakdown (hidden by default) -->
                    <?php if ($hasCampaigns): ?>
                        <div id="mobile_campaigns_<?= $ts['id'] ?>" style="display: none; margin-top: var(--spacing-sm); padding-top: var(--spacing-sm); border-top: 1px solid rgba(0,0,0,0.1);">
                            <div style="font-size: 12px; font-weight: 600; color: #666; margin-bottom: var(--spacing-xs);">Campaigns</div>
                            <?php foreach ($campaignData[$ts['id']] as $camp): 
                                $cViews = (int)$camp['views'];
                                $cClicks = (int)$camp['lp_clicks'];
                                $cConv = (int)$camp['conversions'];
                                $cRev = (float)$camp['revenue'];
                                $cCost = (float)$camp['total_cost'];
                                $cProfit = $cRev - $cCost;
                                $cRoi = $cCost > 0 ? (($cProfit / $cCost) * 100) : 0;
                            ?>
                                <div style="background: #f9f9f9; padding: var(--spacing-xs); border-radius: 4px; margin-bottom: var(--spacing-xs); font-size: 11px;">
                                    <div style="font-weight: 600; margin-bottom: 4px;"><?= htmlspecialchars($camp['name']) ?></div>
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 4px; color: #666;">
                                        <div>Views: <?= number_format($cViews) ?></div>
                                        <div>Clicks: <?= number_format($cClicks) ?></div>
                                        <div>Conv: <?= number_format($cConv) ?></div>
                                        <div>ROI: <span style="color: <?= $cRoi >= 0 ? '#28a745' : '#d32f2f' ?>;"><?= number_format($cRoi, 2) ?>%</span></div>
                                        <div>Revenue: <span style="color: #28a745;"><?= Formatter::formatCurrency($cRev, $userCurrency) ?></span></div>
                                        <div>Spend: <span style="color: #d32f2f;"><?= Formatter::formatCurrency($cCost, $userCurrency) ?></span></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
function toggleCampaigns(tsId) {
    const row = document.getElementById('campaigns_' + tsId);
    if (row) {
        row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
    }
}

function toggleMobileCampaigns(tsId) {
    const div = document.getElementById('mobile_campaigns_' + tsId);
    const btn = document.getElementById('toggle_' + tsId);
    if (div && btn) {
        const isVisible = div.style.display !== 'none';
        div.style.display = isVisible ? 'none' : 'block';
        btn.textContent = isVisible ? '▼' : '▲';
    }
}
</script>

<?php $db->close(); ?>

