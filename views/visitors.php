<?php
// Visitor Log Page
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

function visitorRowClass(array $click): string
{
    $class = 'visitor-row';
    if (!empty($click['has_conversion'])) {
        return $class . ' visitor-row--converted';
    }
    if (!empty($click['lp_click'])) {
        return $class . ' visitor-row--clicked';
    }
    return $class;
}

function renderClickLookupLink(string $clickId): string {
    $url = APP_BASE_URL . '/index.php?page=click-lookup&click_id=' . rawurlencode($clickId);
    $icon = ASSETS_BASE_URL . '/assets/images/clicklookbear.png';
    return sprintf(
        '<a href="%s" class="click-lookup-link" title="Look up this click" aria-label="Look up click %s">'
        . '<img src="%s" alt="" width="22" height="22">'
        . '</a>',
        htmlspecialchars($url),
        htmlspecialchars($clickId),
        htmlspecialchars($icon)
    );
}

$isMobile = isMobileDevice();

?>
<div class="visitors-page">
<?php

// Use the global database connection if available, otherwise create a new one
$db = $GLOBALS['db'] ?? new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$userTimezone = $GLOBALS['userTimezone'] ?? 'UTC';
$userCurrency = $GLOBALS['userCurrency'] ?? 'USD';
\SimpleKuma\Database\DatabaseCompatibility::applyReportingQueryTimeout($db, 20);

// Pagination
$page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$perPage = isset($_GET['per_page_limit']) ? (int)$_GET['per_page_limit'] : 50;
$offset = ($page - 1) * $perPage;

// Filters
$campaignFilter = isset($_GET['campaign']) ? (int)$_GET['campaign'] : null;
// Default to today when no date is selected (in user's timezone)
$todayInUserTz = Formatter::getTodayInTimezone($userTimezone);
if (!isset($_GET['date_from']) && !isset($_GET['date_to'])) {
    // Default to today
    $dateFrom = $todayInUserTz;
    $dateTo = $todayInUserTz;
} else {
    $dateFrom = $_GET['date_from'] ?? $todayInUserTz;
    $dateTo = $_GET['date_to'] ?? $todayInUserTz;
}
$hasConversion = $_GET['has_conversion'] ?? null; // Keep as string to check for "clicked"
// Default to true (checked) on first load, but respect user's choice when form is submitted
// Hidden input sends '0' when unchecked, checkbox sends '1' when checked (overrides hidden)
if (!isset($_GET['exclude_fb_approval'])) {
    // First page load - default to checked
    $excludeFbApprovalTeam = true;
} else {
    // Form was submitted - use the value (will be '1' if checked, '0' if unchecked)
    $excludeFbApprovalTeam = $_GET['exclude_fb_approval'] === '1';
}

// Convert user's date range to UTC for database queries
$utcDateRange = Formatter::convertDateRangeToUTC($dateFrom, $dateTo, $userTimezone);
$utcDateFrom = $utcDateRange['from'];
$utcDateTo = $utcDateRange['to'];

// Build WHERE clause
$where = ["cl.ts >= ? AND cl.ts <= ?"];
$params = [$utcDateFrom, $utcDateTo];
$types = 'ss';

if ($campaignFilter) {
    $where[] = "cl.campaign_id = ?";
    $params[] = $campaignFilter;
    $types .= 'i';
}

if ($hasConversion === '1' || $hasConversion === 1) {
    $where[] = "conv.id IS NOT NULL";
} elseif ($hasConversion === 'clicked' || $hasConversion === 2) {
    $where[] = "cl.lp_click = TRUE";
}

// Check if traffic_source_id column exists in clicks table (needed for filter logic)
$trafficSourceColumnExists = false;
$checkColumn = $db->query("SELECT COUNT(*) as count FROM information_schema.COLUMNS 
                            WHERE TABLE_SCHEMA = DATABASE() 
                            AND TABLE_NAME = 'clicks' 
                            AND COLUMN_NAME = 'traffic_source_id'");
if ($checkColumn && $row = $checkColumn->fetch_assoc()) {
    $trafficSourceColumnExists = ((int)$row['count'] > 0);
}

// Filter out Facebook approval team clicks (aligned with CampaignStatsExpressions)
if ($excludeFbApprovalTeam) {
    if (\SimpleKuma\Stats\StatsExclusionFlag::columnExists($db)) {
        $where[] = 'cl.exclude_from_stats = 0';
    } elseif ($trafficSourceColumnExists) {
        $where[] = \SimpleKuma\Stats\CampaignStatsExpressions::excludeInvalidClickWhere('cl');
    } else {
        $where[] = "NOT (
            EXISTS (
                SELECT 1 FROM campaigns cp_filter 
                INNER JOIN traffic_sources ts_filter ON cp_filter.traffic_source_id = ts_filter.id
                WHERE cp_filter.id = cl.campaign_id AND ts_filter.id = 4
            ) AND (
                JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.ad_id')) IS NULL 
                OR JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.ad_id')) = ''
                OR JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.ad_id')) = 'null'
                OR JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.ad_id')) LIKE '{{%'
                OR JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.ad_id')) LIKE '{ts:%'
                OR JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.adset_id')) IS NULL
                OR JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.adset_id')) = ''
                OR JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.adset_id')) = 'null'
                OR JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.adset_id')) LIKE '{{%'
                OR JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.adset_id')) LIKE '{ts:%'
            )
        )";
        $where[] = "cl.ua NOT LIKE '%facebookexternalhit/1.1%'";
    }
}

// Always omit account-wide stats-hidden IPs from the visitor log
try {
    $hiddenIpSql = (new \SimpleKuma\Stats\StatsHiddenIpService($db))->exclusionSql('cl');
    if ($hiddenIpSql !== '') {
        $where[] = $hiddenIpSql;
    }
} catch (\Throwable $e) {
    // ignore if migration not applied
}

$whereClause = implode(' AND ', $where);

// Count total
$countSql = "SELECT COUNT(*) as total 
             FROM clicks cl
             LEFT JOIN conversions conv ON cl.click_id = conv.click_id
             WHERE {$whereClause}";
$stmt = $db->prepare($countSql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$totalRows = $stmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalRows / $perPage);

// Fetch clicks with offer and landing page names
// Use COALESCE to get traffic source from click or campaign
// Note: cp_ts is used in the WHERE clause for the exclude_fb_approval filter
$sql = "SELECT 
            cl.*,
            cp.name as campaign_name,
            " . ($trafficSourceColumnExists 
                ? "COALESCE(ts.name, cp_ts.name) as traffic_source_name"
                : "cp_ts.name as traffic_source_name") . ",
            o.name as offer_name,
            lp.name as landing_page_name,
            conv.value as conv_value,
            conv.payout as conv_payout,
            conv.currency as conv_currency,
            conv.id as has_conversion
        FROM clicks cl
        INNER JOIN campaigns cp ON cl.campaign_id = cp.id
        LEFT JOIN traffic_sources cp_ts ON cp.traffic_source_id = cp_ts.id" . 
        ($trafficSourceColumnExists ? "
        LEFT JOIN traffic_sources ts ON cl.traffic_source_id = ts.id" : "") . "
        LEFT JOIN offers o ON cl.offer_id = o.id
        LEFT JOIN landing_pages lp ON cl.landing_page_id = lp.id
        LEFT JOIN conversions conv ON cl.click_id = conv.click_id
        WHERE {$whereClause}
        ORDER BY cl.ts DESC
        LIMIT ? OFFSET ?";

$params[] = $perPage;
$params[] = $offset;
$types .= 'ii';

$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Fetch clicks
$clicks = [];
while ($row = $result->fetch_assoc()) {
    $clicks[] = $row;
}

// Get campaigns for filter
$campaigns = $db->query("SELECT id, name FROM campaigns ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// Only close the database connection if we created it (not if it came from globals)
if (!isset($GLOBALS['db'])) {
    $db->close();
}

// Build pagination URL helper function
function buildPaginationUrl($pageNum, $currentParams) {
    $params = array_diff_key($currentParams, ['p' => '', 'page' => '']);
    $params['page'] = 'visitors';
    $params['p'] = $pageNum;
    return '?' . http_build_query($params);
}

// Generate pagination HTML (reusable)
function generatePagination($currentPage, $totalPages, $getParams) {
    if ($totalPages <= 1) {
        return '';
    }
    
    $html = '<div style="display: flex; justify-content: center; align-items: center; gap: 8px; flex-wrap: wrap;">';
    
    // First page button (only show if not on first page)
    if ($currentPage > 1) {
        $firstUrl = buildPaginationUrl(1, $getParams);
        $html .= '<a href="' . htmlspecialchars($firstUrl) . '" class="btn btn-outline" style="min-width: 80px;">⏮ First</a>';
    }
    
    // Previous button
    if ($currentPage > 1) {
        $prevUrl = buildPaginationUrl($currentPage - 1, $getParams);
        $html .= '<a href="' . htmlspecialchars($prevUrl) . '" class="btn btn-outline">← Previous</a>';
    }
    
    // Page indicator
    $html .= '<span style="padding: 8px 16px; color: #666; font-weight: 500;">Page ' . $currentPage . ' of ' . $totalPages . '</span>';
    
    // Next button
    if ($currentPage < $totalPages) {
        $nextUrl = buildPaginationUrl($currentPage + 1, $getParams);
        $html .= '<a href="' . htmlspecialchars($nextUrl) . '" class="btn btn-outline">Next →</a>';
    }
    
    $html .= '</div>';
    
    return $html;
}
?>

<div class="page-header">
    <h1 class="page-title">Visitor Log</h1>
    <p class="page-description">Real-time click tracking and visitor analytics.</p>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-body">
        <!-- Preset Buttons -->
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
        <div class="visitor-date-presets" style="display: flex; gap: 6px; flex-wrap: wrap;">
            <button type="button" onclick="setVisitorDate('today')" style="padding: 4px 8px; font-size: 11px; border: 1px solid #ddd; border-radius: 3px; background: <?= $activePreset === 'today' ? '#3d5a26' : '#fff' ?>; color: <?= $activePreset === 'today' ? '#fff' : '#666' ?>; cursor: pointer;">Today</button>
            <button type="button" onclick="setVisitorDate('yesterday')" style="padding: 4px 8px; font-size: 11px; border: 1px solid #ddd; border-radius: 3px; background: <?= $activePreset === 'yesterday' ? '#3d5a26' : '#fff' ?>; color: <?= $activePreset === 'yesterday' ? '#fff' : '#666' ?>; cursor: pointer;">Yesterday</button>
            <button type="button" onclick="setVisitorDate('last7')" style="padding: 4px 8px; font-size: 11px; border: 1px solid #ddd; border-radius: 3px; background: <?= $activePreset === 'last7' ? '#3d5a26' : '#fff' ?>; color: <?= $activePreset === 'last7' ? '#fff' : '#666' ?>; cursor: pointer;">7d</button>
            <button type="button" onclick="setVisitorDate('last14')" style="padding: 4px 8px; font-size: 11px; border: 1px solid #ddd; border-radius: 3px; background: <?= $activePreset === 'last14' ? '#3d5a26' : '#fff' ?>; color: <?= $activePreset === 'last14' ? '#fff' : '#666' ?>; cursor: pointer;">14d</button>
            <button type="button" onclick="setVisitorDate('last30')" style="padding: 4px 8px; font-size: 11px; border: 1px solid #ddd; border-radius: 3px; background: <?= $activePreset === 'last30' ? '#3d5a26' : '#fff' ?>; color: <?= $activePreset === 'last30' ? '#fff' : '#666' ?>; cursor: pointer;">30d</button>
            <button type="button" onclick="setVisitorDate('lastmonth')" style="padding: 4px 8px; font-size: 11px; border: 1px solid #ddd; border-radius: 3px; background: <?= $activePreset === 'lastmonth' ? '#3d5a26' : '#fff' ?>; color: <?= $activePreset === 'lastmonth' ? '#fff' : '#666' ?>; cursor: pointer;">Last Mo</button>
            <button type="button" onclick="setVisitorDate('thismonth')" style="padding: 4px 8px; font-size: 11px; border: 1px solid #ddd; border-radius: 3px; background: <?= $activePreset === 'thismonth' ? '#3d5a26' : '#fff' ?>; color: <?= $activePreset === 'thismonth' ? '#fff' : '#666' ?>; cursor: pointer;">This Mo</button>
            <button type="button" onclick="setVisitorDate('alltime')" style="padding: 4px 8px; font-size: 11px; border: 1px solid #ddd; border-radius: 3px; background: <?= $activePreset === 'alltime' ? '#3d5a26' : '#fff' ?>; color: <?= $activePreset === 'alltime' ? '#fff' : '#666' ?>; cursor: pointer;">ALL TIME</button>
        </div>
        
        <form method="get" action="" id="visitor-date-form" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; align-items: end;">
            <input type="hidden" name="page" value="visitors">
            
            <div>
                <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 14px;">Date From</label>
                <input type="date" name="date_from" id="visitor_date_from" value="<?= htmlspecialchars($dateFrom) ?>" 
                       style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px;">
            </div>
            
            <div>
                <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 14px;">Date To</label>
                <input type="date" name="date_to" id="visitor_date_to" value="<?= htmlspecialchars($dateTo) ?>" 
                       style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px;">
            </div>
            
            <div>
                <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 14px;">Campaign</label>
                <select name="campaign" style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px;">
                    <option value="">All Campaigns</option>
                    <?php foreach ($campaigns as $camp): ?>
                        <option value="<?= $camp['id'] ?>" <?= $campaignFilter == $camp['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($camp['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 14px;">Filter Type</label>
                <select name="has_conversion" style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px;">
                    <option value="">All Clicks</option>
                    <option value="clicked" <?= ($hasConversion === 'clicked' || $hasConversion === 2) ? 'selected' : '' ?>>Clicked Through LP</option>
                    <option value="1" <?= ($hasConversion === '1' || $hasConversion === 1) ? 'selected' : '' ?>>Converted</option>
                </select>
            </div>
            
            <div>
                <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 14px;">Per Page</label>
                <select name="per_page_limit" style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px;">
                    <option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50</option>
                    <option value="100" <?= $perPage === 100 ? 'selected' : '' ?>>100</option>
                    <option value="200" <?= $perPage === 200 ? 'selected' : '' ?>>200</option>
                    <option value="500" <?= $perPage === 500 ? 'selected' : '' ?>>500</option>
                </select>
            </div>
            
            <div style="display: flex; align-items: center; padding-top: 24px;">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 14px;">
                    <input type="hidden" name="exclude_fb_approval" value="0">
                    <input type="checkbox" name="exclude_fb_approval" value="1" <?= $excludeFbApprovalTeam ? 'checked' : '' ?> 
                           style="width: 18px; height: 18px; cursor: pointer;">
                    <span>Exclude Facebook Approval Team Clicks</span>
                </label>
                <span style="margin-left: 8px; font-size: 12px; color: #666;" title="Excludes clicks from Facebook's approval team that lack valid ad_id and adset_id tokens">ℹ️</span>
            </div>
            
            <button type="submit" class="btn btn-primary">Apply Filters</button>
        </form>
    </div>
</div>

<script>
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

function setVisitorDate(preset) {
    const dateFromInput = document.getElementById('visitor_date_from');
    const dateToInput = document.getElementById('visitor_date_to');
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
    
    dateFromInput.value = fromDate;
    dateToInput.value = toDate;
    
    // Submit the form
    document.getElementById('visitor-date-form').submit();
}
</script>

</div>

<!-- Stats Summary -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <div class="card" style="padding: 16px;">
        <div style="font-size: 12px; color: #666;">Total Clicks</div>
        <div style="font-size: 28px; font-weight: 700; color: #3d5a26;"><?= number_format($totalRows) ?></div>
    </div>
    <div class="card" style="padding: 16px;">
        <div style="font-size: 12px; color: #666;">Showing</div>
        <div style="font-size: 28px; font-weight: 700; color: #3d5a26;"><?= min($perPage, count($clicks)) ?></div>
    </div>
    <div class="card" style="padding: 16px;">
        <div style="font-size: 12px; color: #666;">Page</div>
        <div style="font-size: 28px; font-weight: 700; color: #3d5a26;"><?= $page ?> / <?= max(1, $totalPages) ?></div>
    </div>
</div>

<!-- Top Pagination -->
<?php if ($totalPages > 1): ?>
    <div style="margin-bottom: 24px;">
        <?= generatePagination($page, $totalPages, $_GET) ?>
    </div>
<?php endif; ?>

<!-- Visitor Table -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">Click Events (<?= number_format($totalRows) ?>)</h2>
        <a href="?page=visitors&export=csv&<?= http_build_query($_GET) ?>" class="btn btn-secondary">📥 Export CSV</a>
    </div>
    <div class="card-body">
        <?php if (empty($clicks)): ?>
            <div style="text-align: center; padding: 60px; color: #999;">
                <div style="font-size: 64px; margin-bottom: 16px;">📭</div>
                <p>No click events found for the selected filters.</p>
            </div>
        <?php else: ?>
            <!-- Desktop Table View (hidden on mobile) -->
            <div class="table-wrapper desktop-only">
                <table class="table">
                        <thead>
                            <tr>
                                <th style="width: 70px; text-align: center;">CLICKED</th>
                                <th style="width: 70px; text-align: center;">CONV</th>
                                <th>TIMESTAMP</th>
                                <th>CLICK ID</th>
                                <th>CAMPAIGN</th>
                                <th>LANDING PAGE</th>
                                <th>OFFER</th>
                                <th>CITY</th>
                                <th>STATE</th>
                                <th>DEVICE</th>
                                <th>IP</th>
                                <th>TRAFFIC SOURCE</th>
                                <th>REVENUE</th>
                            </tr>
                        </thead>
                    <tbody>
                        <?php foreach ($clicks as $click): ?>
                        <tr class="<?= visitorRowClass($click) ?>">
                            <td style="text-align: center;">
                                <?php if ($click['lp_click']): ?>
                                    <img src="<?= ASSETS_BASE_URL ?>/assets/images/clickbear.png" alt="Clicked" style="width: 36px; height: 36px;" title="Clicked through LP">
                                <?php else: ?>
                                    <span class="visitor-empty" aria-hidden="true">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($click['has_conversion']): ?>
                                    <img src="<?= ASSETS_BASE_URL ?>/assets/images/conversionbear.png" alt="Converted" style="width: 36px; height: 36px;" title="Converted">
                                <?php else: ?>
                                    <span class="visitor-empty" aria-hidden="true">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="white-space: nowrap; font-family: monospace; font-size: 12px;">
                                <?= Formatter::formatDateTime($click['ts'], $userTimezone) ?>
                            </td>
                            <td>
                                <div class="click-id-row">
                                    <code 
                                        class="click-id-copyable" 
                                        data-click-id="<?= htmlspecialchars($click['click_id']) ?>"
                                        onclick="copyClickId(this)"
                                        style="font-size: 11px; padding: 6px 10px; overflow: hidden; text-overflow: ellipsis; cursor: pointer; user-select: none; transition: background 0.2s, color 0.2s; font-family: 'Courier New', monospace; color: #333; white-space: nowrap; display: block;"
                                        title="Click to copy: <?= htmlspecialchars($click['click_id']) ?>">
                                        <?= htmlspecialchars($click['click_id']) ?>
                                    </code>
                                    <?= renderClickLookupLink($click['click_id']) ?>
                                </div>
                            </td>
                            <td><strong><?= htmlspecialchars($click['campaign_name']) ?></strong></td>
                            <td><?= htmlspecialchars($click['landing_page_name'] ?? '-') ?></td>
                            <td>
                                <?php
                                $extraJson = json_decode($click['extra_json'] ?? '{}', true) ?? [];
                                if (!empty($extraJson['redirect_rule_matched'])) {
                                    echo '<span style="color: #d32f2f; font-weight: 600;">Redirect Profile Matched</span>';
                                } else {
                                    echo htmlspecialchars($click['offer_name'] ?? '-');
                                }
                                ?>
                            </td>
                            <td>
                                <?php if ($click['city']): ?>
                                    <?= htmlspecialchars($click['city']) ?>
                                <?php else: ?>
                                    <span style="color: #999;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($click['region']): ?>
                                    <?= htmlspecialchars($click['region']) ?>
                                <?php else: ?>
                                    <span style="color: #999;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="font-size: 12px;" title="<?= htmlspecialchars(
                                    ($click['device_brand'] ? $click['device_brand'] . ' ' : '') .
                                    ($click['device_model'] ? $click['device_model'] . ' / ' : '') .
                                    ($click['os'] ?? 'Unknown') . 
                                    ($click['os_version'] ? ' ' . $click['os_version'] : '') . 
                                    ' / ' . 
                                    ($click['browser'] ?? 'Unknown') .
                                    ($click['browser_version'] ? ' ' . $click['browser_version'] : '')
                                ) ?>">
                                    <?php
                                    // Display device info with new fields if available
                                    $deviceParts = [];
                                    if (!empty($click['device_brand']) && !empty($click['device_model'])) {
                                        $deviceParts[] = $click['device_brand'] . ' ' . $click['device_model'];
                                    } elseif (!empty($click['device_brand'])) {
                                        $deviceParts[] = $click['device_brand'];
                                    } elseif (!empty($click['device'])) {
                                        $deviceParts[] = $click['device'];
                                    }
                                    
                                    $osParts = [];
                                    if (!empty($click['os'])) {
                                        $osParts[] = $click['os'];
                                        if (!empty($click['os_version'])) {
                                            $osParts[] = $click['os_version'];
                                        }
                                    }
                                    
                                    $browserParts = [];
                                    if (!empty($click['browser'])) {
                                        $browserParts[] = $click['browser'];
                                        if (!empty($click['browser_version'])) {
                                            $browserParts[] = $click['browser_version'];
                                        }
                                    }
                                    
                                    $display = [];
                                    if (!empty($deviceParts)) {
                                        $display[] = implode(' ', $deviceParts);
                                    }
                                    if (!empty($osParts)) {
                                        $display[] = implode(' ', $osParts);
                                    }
                                    if (!empty($browserParts)) {
                                        $display[] = implode(' ', $browserParts);
                                    }
                                    
                                    echo htmlspecialchars(implode(' / ', $display) ?: 'Unknown');
                                    ?>
                                </span>
                            </td>
                            <td style="font-family: monospace; font-size: 11px;">
                                <span class="visitor-ip-cell" style="display:inline-flex; align-items:center; gap:6px;">
                                    <span><?= htmlspecialchars($click['ip']) ?></span>
                                    <?php if (!empty($click['ip'])): ?>
                                    <button type="button"
                                            class="btn btn-secondary btn-sm visitor-hide-ip-btn"
                                            data-ip="<?= htmlspecialchars($click['ip'], ENT_QUOTES, 'UTF-8') ?>"
                                            title="Hide this IP from all stats views (does not block traffic)"
                                            style="padding:2px 6px; font-size:10px; line-height:1.2;">
                                        Hide
                                    </button>
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td style="font-size: 13px;">
                                <?= htmlspecialchars($click['traffic_source_name'] ?? 'N/A') ?>
                            </td>
                            <td style="font-weight: 600;">
                                <?php if ($click['conv_payout']): ?>
                                    <span style="color: #2ecc71;"><?= Formatter::formatCurrency($click['conv_payout'], $userCurrency) ?></span>
                                <?php elseif ($click['conv_value']): ?>
                                    <span style="color: #2ecc71;"><?= Formatter::formatCurrency($click['conv_value'], $userCurrency) ?></span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Mobile Card View (hidden on desktop) -->
            <div class="mobile-visitor-cards mobile-only">
                <?php foreach ($clicks as $click): ?>
                <?php
                // Device info
                $deviceParts = [];
                if (!empty($click['device_brand']) && !empty($click['device_model'])) {
                    $deviceParts[] = $click['device_brand'] . ' ' . $click['device_model'];
                } elseif (!empty($click['device_brand'])) {
                    $deviceParts[] = $click['device_brand'];
                } elseif (!empty($click['device'])) {
                    $deviceParts[] = $click['device'];
                }
                
                $osParts = [];
                if (!empty($click['os'])) {
                    $osParts[] = $click['os'];
                    if (!empty($click['os_version'])) {
                        $osParts[] = $click['os_version'];
                    }
                }
                
                $browserParts = [];
                if (!empty($click['browser'])) {
                    $browserParts[] = $click['browser'];
                    if (!empty($click['browser_version'])) {
                        $browserParts[] = $click['browser_version'];
                    }
                }
                
                $deviceDisplay = [];
                if (!empty($deviceParts)) {
                    $deviceDisplay[] = implode(' ', $deviceParts);
                }
                if (!empty($osParts)) {
                    $deviceDisplay[] = implode(' ', $osParts);
                }
                if (!empty($browserParts)) {
                    $deviceDisplay[] = implode(' ', $browserParts);
                }
                $deviceText = implode(' / ', $deviceDisplay) ?: 'Unknown';
                ?>
                <div class="mobile-visitor-card <?= visitorRowClass($click) ?>">
                    <!-- Header: Status icons and timestamp -->
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--spacing-sm); border-bottom: 1px solid rgba(0,0,0,0.1); padding-bottom: var(--spacing-xs);">
                        <div style="display: flex; gap: var(--spacing-xs); align-items: center;">
                            <?php if ($click['lp_click']): ?>
                                <img src="<?= ASSETS_BASE_URL ?>/assets/images/clickbear.png" alt="Clicked" style="width: 24px; height: 24px;" title="Clicked through LP">
                            <?php endif; ?>
                            <?php if ($click['has_conversion']): ?>
                                <img src="<?= ASSETS_BASE_URL ?>/assets/images/conversionbear.png" alt="Converted" style="width: 24px; height: 24px;" title="Converted">
                            <?php endif; ?>
                        </div>
                        <div style="font-size: 11px; color: #666; font-family: monospace;">
                            <?= Formatter::formatDateTime($click['ts'], $userTimezone) ?>
                        </div>
                    </div>
                    
                    <!-- Main info: Campaign, LP, Offer -->
                    <div style="margin-bottom: var(--spacing-sm);">
                        <div style="font-weight: 600; font-size: 14px; margin-bottom: 4px; color: #3d5a26;">
                            <?= htmlspecialchars($click['campaign_name']) ?>
                        </div>
                        <div style="font-size: 12px; color: #666; display: flex; flex-direction: column; gap: 2px;">
                            <div><strong>LP:</strong> <?= htmlspecialchars($click['landing_page_name'] ?? '-') ?></div>
                            <div><strong>Offer:</strong> 
                                <?php
                                $extraJson = json_decode($click['extra_json'] ?? '{}', true) ?? [];
                                if (!empty($extraJson['redirect_rule_matched'])) {
                                    echo '<span style="color: #d32f2f; font-weight: 600;">Redirect Profile Matched</span>';
                                } else {
                                    echo htmlspecialchars($click['offer_name'] ?? '-');
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Location and Device -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-xs); font-size: 11px; color: #666; margin-bottom: var(--spacing-sm);">
                        <div><strong>Location:</strong> <?= htmlspecialchars($click['city'] ?? '-') ?><?= $click['region'] ? ', ' . htmlspecialchars($click['region']) : '' ?></div>
                        <div><strong>IP:</strong>
                            <code style="font-size: 10px;"><?= htmlspecialchars($click['ip']) ?></code>
                            <?php if (!empty($click['ip'])): ?>
                            <button type="button"
                                    class="btn btn-secondary btn-sm visitor-hide-ip-btn"
                                    data-ip="<?= htmlspecialchars($click['ip'], ENT_QUOTES, 'UTF-8') ?>"
                                    title="Hide this IP from all stats views (does not block traffic)"
                                    style="padding:2px 6px; font-size:10px; margin-left:4px;">
                                Hide
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div style="font-size: 11px; color: #666; margin-bottom: var(--spacing-sm);">
                        <strong>Device:</strong> <?= htmlspecialchars($deviceText) ?>
                    </div>
                    
                    <!-- Footer: Click ID and Revenue -->
                    <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid rgba(0,0,0,0.1); padding-top: var(--spacing-xs); gap: 8px;">
                        <div class="click-id-row" style="flex: 1; min-width: 0;">
                            <div 
                                class="click-id-copyable" 
                                data-click-id="<?= htmlspecialchars($click['click_id']) ?>"
                                onclick="copyClickId(this)"
                                style="font-size: 11px; padding: 8px 10px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; cursor: pointer; user-select: none; transition: background 0.2s, color 0.2s; font-family: 'Courier New', monospace; color: #333; touch-action: manipulation; -webkit-tap-highlight-color: rgba(0,0,0,0.1); display: flex; align-items: center; box-sizing: border-box;"
                                title="Tap to copy: <?= htmlspecialchars($click['click_id']) ?>">
                                <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= htmlspecialchars($click['click_id']) ?></span>
                            </div>
                            <?= renderClickLookupLink($click['click_id']) ?>
                        </div>
                        <div style="font-weight: 600; font-size: 13px; flex-shrink: 0; margin: 0; display: flex; align-items: center; padding: 8px 0; box-sizing: border-box;">
                            <?php if ($click['conv_payout']): ?>
                                <span style="color: #2ecc71;"><?= Formatter::formatCurrency($click['conv_payout'], $userCurrency) ?></span>
                            <?php elseif ($click['conv_value']): ?>
                                <span style="color: #2ecc71;"><?= Formatter::formatCurrency($click['conv_value'], $userCurrency) ?></span>
                            <?php else: ?>
                                <span style="color: #999;">-</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Bottom Pagination -->
            <?php if ($totalPages > 1): ?>
                <div style="margin-top: 24px;">
                    <?= generatePagination($page, $totalPages, $_GET) ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
function showCopyFeedback(element, status, message) {
    const spanElement = element.querySelector('span');
    const row = element.closest('.click-id-row');

    if (!element._copyState) {
        element._copyState = {
            text: spanElement ? spanElement.textContent : element.textContent,
            color: element.style.color,
            weight: element.style.fontWeight,
            bg: element.style.background
        };
    }

    if (spanElement) {
        spanElement.textContent = message;
    } else {
        element.textContent = message;
    }

    row?.classList.remove('is-copy-failed', 'is-copied');
    element.classList.remove('is-copy-failed', 'is-copied');

    if (status === 'success') {
        if (row) {
            row.classList.add('is-copied');
        } else {
            element.style.background = '#e8f5e9';
            element.style.color = '#1b5e20';
            element.style.fontWeight = '600';
        }
    } else if (status === 'error') {
        if (row) {
            row.classList.add('is-copy-failed');
        } else {
            element.style.background = '#ffebee';
            element.style.color = '#b71c1c';
            element.style.fontWeight = '600';
        }
    }
}

function resetCopyFeedback(element) {
    const spanElement = element.querySelector('span');
    const row = element.closest('.click-id-row');
    const state = element._copyState;

    if (!state) {
        return;
    }

    if (spanElement) {
        spanElement.textContent = state.text;
    } else {
        element.textContent = state.text;
    }

    row?.classList.remove('is-copy-failed', 'is-copied');
    element.classList.remove('is-copy-failed', 'is-copied');
    element.style.color = state.color;
    element.style.fontWeight = state.weight;
    element.style.background = state.bg;
    delete element._copyState;
}

function copyClickId(element) {
    const clickId = element.getAttribute('data-click-id');

    function onCopySuccess() {
        showCopyFeedback(element, 'success', '✓ Copied!');
        setTimeout(function() {
            resetCopyFeedback(element);
        }, 2000);
    }

    function onCopyError() {
        showCopyFeedback(element, 'error', 'Copy failed');
        setTimeout(function() {
            resetCopyFeedback(element);
        }, 2000);
    }

    function fallbackCopy() {
        const textArea = document.createElement('textarea');
        textArea.value = clickId;
        textArea.style.position = 'fixed';
        textArea.style.opacity = '0';
        document.body.appendChild(textArea);
        textArea.select();
        try {
            document.execCommand('copy');
            onCopySuccess();
        } catch (err) {
            onCopyError();
        }
        document.body.removeChild(textArea);
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(clickId).then(onCopySuccess).catch(fallbackCopy);
    } else {
        fallbackCopy();
    }
}

// Add hover effect for desktop (optional enhancement)
document.addEventListener('DOMContentLoaded', function() {
    const clickIdElements = document.querySelectorAll('.click-id-copyable');
    clickIdElements.forEach(function(element) {
        element.addEventListener('mouseenter', function() {
            if (window.innerWidth > 768 && !this.closest('.click-id-row.is-copied, .click-id-row.is-copy-failed')) {
                this.style.background = '#e8e8e8';
            }
        });
        element.addEventListener('mouseleave', function() {
            if (window.innerWidth > 768 && !this._copyState) {
                this.style.background = '';
            }
        });
    });

    const hideApiUrl = <?= json_encode(APP_BASE_URL . '/api-stats-hidden-ips.php', JSON_THROW_ON_ERROR) ?>;
    function getVisitorCsrf() {
        if (window.KUMA_UI_PREFS_CONFIG && window.KUMA_UI_PREFS_CONFIG.csrfToken) {
            return window.KUMA_UI_PREFS_CONFIG.csrfToken;
        }
        if (window.KUMA_THEME_CONFIG && window.KUMA_THEME_CONFIG.csrfToken) {
            return window.KUMA_THEME_CONFIG.csrfToken;
        }
        return '';
    }
    document.querySelectorAll('.visitor-hide-ip-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const ip = this.getAttribute('data-ip') || '';
            if (!ip) {
                return;
            }
            if (!confirm('Hide IP ' + ip + ' from all stats views?\n\nThis does not block traffic or delete clicks — it only omits the IP from reporting.')) {
                return;
            }
            const csrf = getVisitorCsrf();
            if (!csrf) {
                alert('Missing CSRF token. Refresh the page and try again.');
                return;
            }
            this.disabled = true;
            const body = new URLSearchParams();
            body.set('action', 'add');
            body.set('ip', ip);
            body.set('app_csrf', csrf);
            fetch(hideApiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
                credentials: 'same-origin'
            }).then(function(res) { return res.json(); }).then(function(data) {
                if (!data || !data.ok) {
                    alert((data && data.error) ? data.error : 'Failed to hide IP');
                    btn.disabled = false;
                    return;
                }
                window.location.reload();
            }).catch(function() {
                alert('Failed to hide IP');
                btn.disabled = false;
            });
        });
    });
});
</script>

