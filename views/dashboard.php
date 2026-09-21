<?php
// Dashboard - Fetch real data
require_once __DIR__ . '/../src/Utils/Formatter.php';
use SimpleKuma\Utils\Formatter;

// Simple mobile detection function
function isMobileDevice(): bool {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ua = strtolower($userAgent);
    
    // Check for mobile devices
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

// Check for database connection errors
if ($db->connect_error) {
    die("Database connection failed: " . $db->connect_error);
}

// Ensure MySQL session timezone is UTC for consistent timestamp storage
$db->query("SET time_zone = '+00:00'");
\SimpleKuma\Database\DatabaseCompatibility::applyReportingQueryTimeout($db, 20);

// Source timezone from current user first so cost display respects user's selected timezone (not stale global)
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

// Date range (needed for CSV export check) - default to today in user's timezone
$todayInUserTz = Formatter::getTodayInTimezone($userTimezone);
$dateFrom = $_GET['date_from'] ?? $todayInUserTz;
$dateTo = $_GET['date_to'] ?? $todayInUserTz;

// Campaign status filter: active, paused, archived. Empty or all three = show all.
$statusFilterRaw = $_GET['status_filter'] ?? [];
if (!is_array($statusFilterRaw)) {
    $statusFilterRaw = $statusFilterRaw !== '' ? [$statusFilterRaw] : [];
}
$validStatuses = ['active', 'paused', 'archived'];
$allowedStatuses = array_values(array_intersect($statusFilterRaw, $validStatuses));
if (count($allowedStatuses) === 0 || count($allowedStatuses) === 3) {
    $allowedStatuses = null; // show all
}

// Convert user's date range to UTC for database queries
$utcDateRange = Formatter::convertDateRangeToUTC($dateFrom, $dateTo, $userTimezone);
$utcDateFrom = $utcDateRange['from'];
$utcDateTo = $utcDateRange['to'];

// Dashboard data loader (pre-agg + cache)
$campaignsPage = max(1, (int)($_GET['campaigns_page'] ?? 1));
$campaignsPerPage = \SimpleKuma\Stats\DashboardStatsService::DEFAULT_PER_PAGE;
$userId = (int)($GLOBALS['currentUserId'] ?? ($_SESSION['user_id'] ?? 0));

$loadDashboardExport = static function () use ($db, $dateFrom, $dateTo, $userTimezone, $allowedStatuses, $userId): array {
    $cacheKey = \SimpleKuma\Stats\StatsResponseCache::makeKey($userId, 'dashboard_export', [
        $dateFrom,
        $dateTo,
        $userTimezone,
        $allowedStatuses,
        \SimpleKuma\Stats\StatsResponseCache::clicksFreshnessToken($db),
    ]);
    return \SimpleKuma\Stats\StatsResponseCache::remember($cacheKey, static function () use ($db, $dateFrom, $dateTo, $userTimezone, $allowedStatuses) {
        $service = new \SimpleKuma\Stats\DashboardStatsService($db);
        return $service->load($dateFrom, $dateTo, $userTimezone, $allowedStatuses, 1, 25, true);
    }, 30);
};

$loadDashboardOverview = static function () use ($db, $dateFrom, $dateTo, $userTimezone, $allowedStatuses, $userId): array {
    $includeChart = empty($GLOBALS['dashboardChartsHidden']);
    $cacheKey = \SimpleKuma\Stats\StatsResponseCache::makeKey($userId, 'dashboard_overview', [
        $dateFrom,
        $dateTo,
        $userTimezone,
        $allowedStatuses,
        $includeChart ? 'chart' : 'nochart',
        \SimpleKuma\Stats\StatsResponseCache::clicksFreshnessToken($db),
    ]);
    return \SimpleKuma\Stats\StatsResponseCache::remember($cacheKey, static function () use ($db, $dateFrom, $dateTo, $userTimezone, $allowedStatuses, $includeChart) {
        $service = new \SimpleKuma\Stats\DashboardStatsService($db);
        return $service->loadOverviewAndChart($dateFrom, $dateTo, $userTimezone, $allowedStatuses, $includeChart);
    }, \SimpleKuma\Stats\StatsResponseCache::TTL_DASHBOARD);
};

// Handle CSV export FIRST - before any HTML output
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $payload = $loadDashboardExport();
    $campaignStats = $payload['campaignStatsAll'] ?? $payload['campaignStats'];
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="dashboard-' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Campaign', 'Views', 'LP Clicks', 'Direct Clicks', 'Conversions', 'Cost', 'Revenue', 'Profit', 'ROI', 'EPC', 'CTR', 'CR']);

    foreach ($campaignStats as $row) {
        $cViews = (int)$row['views'];
        $cLpClicks = (int)$row['lp_clicks'];
        $cDirectClicks = (int)$row['direct_clicks'];
        $cRevenue = (float)$row['revenue'];
        $cCost = (float)$row['cost'];
        $cProfit = $cRevenue - $cCost;
        $cRoi = $cCost > 0 ? (($cRevenue - $cCost) / $cCost) * 100 : 0;
        $cEpc = $cViews > 0 ? $cRevenue / $cViews : 0;
        $cCtr = $cViews > 0 ? ($cLpClicks / $cViews) * 100 : 0;
        $cCr = $cViews > 0 ? ((int)$row['conversions'] / $cViews) * 100 : 0;

        fputcsv($output, [
            $row['name'],
            $cViews,
            $cLpClicks,
            $cDirectClicks,
            $row['conversions'],
            $cCost,
            $cRevenue,
            $cProfit,
            number_format($cRoi, 2),
            number_format($cEpc, 2),
            number_format($cCtr, 2),
            number_format($cCr, 2),
        ]);
    }

    fclose($output);
    $db->close();
    exit;
}
// Handle dismiss getting started
if (isset($_GET['dismiss_guide'])) {
    $stmt = $db->prepare("INSERT INTO settings (`key`, value) VALUES ('getting_started_dismissed', '1') ON DUPLICATE KEY UPDATE value = '1'");
    $stmt->execute();
    header('Location: ?page=dashboard');
    exit;
}

// Check if getting started is dismissed
$stmt = $db->query("SELECT value FROM settings WHERE `key` = 'getting_started_dismissed'");
$guideRow = $stmt ? $stmt->fetch_assoc() : null;
$showGettingStarted = !($guideRow['value'] ?? false);

$payload = $loadDashboardOverview();
$stats = $payload['stats'];
$utcDateFrom = $payload['utcDateFrom'];
$utcDateTo = $payload['utcDateTo'];
$clicks = (int)$payload['clicks'];
$lpClicks = (int)$payload['lpClicks'];
$conversions = (int)$payload['conversions'];
$revenue = (float)$payload['revenue'];
$cost = (float)$payload['cost'];
$profit = (float)$payload['profit'];
$roi = (float)$payload['roi'];
$epc = (float)$payload['epc'];
$ctr = (float)$payload['ctr'];
$cr = (float)$payload['cr'];
$chartLabels = $payload['chartLabels'];
$clicksData = $payload['clicksData'];
$conversionsData = $payload['conversionsData'];
$revenueData = $payload['revenueData'];
$isSingleDay = (bool)$payload['isSingleDay'];
$dashboardChartsHidden = !empty($GLOBALS['dashboardChartsHidden']);
$includeDashboardChart = !$dashboardChartsHidden;

$statusQueryForApi = '';
if ($allowedStatuses !== null) {
    foreach ($allowedStatuses as $st) {
        $statusQueryForApi .= '&status_filter[]=' . rawurlencode($st);
    }
}
$dashboardCampaignsApiUrl = rtrim(APP_BASE_URL, '/') . '/api-dashboard-campaigns.php'
    . '?date_from=' . rawurlencode($dateFrom)
    . '&date_to=' . rawurlencode($dateTo)
    . '&campaigns_page=' . (int)$campaignsPage
    . $statusQueryForApi;
?>

<!-- Motivational Quote -->
<div style="margin-bottom: 16px; padding: 0 16px;">
    <p style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; font-size: 13px; color: #888; font-weight: 400; line-height: 1.5; margin: 0; font-style: italic;">
        Work it harder, make it better. Do it faster, makes us stronger. More than ever, hour after hour. <strong style="font-weight: 700; font-style: normal;"><a href="https://www.youtube.com/watch?v=yydNF8tuVmU" target="_blank" rel="noopener noreferrer" style="color: #888; text-decoration: underline; cursor: pointer; transition: color 0.2s;" onmouseover="this.style.color='#3d5a26'" onmouseout="this.style.color='#888'">Work is never over.</a></strong>
    </p>
</div>

<!-- Quick Actions Bar (Above Title) -->
<div class="dashboard-quick-actions">
    <span class="dashboard-quick-actions-label">Quick Actions:</span>
    <a href="?page=campaign-create" class="btn btn-primary" style="padding: 6px 14px; font-size: 13px; display: inline-flex; align-items: center; gap: 6px;">
        <img src="<?= ASSETS_BASE_URL ?>/assets/images/campaigns.png" alt="Start New Campaign" style="width: 16px; height: 16px;">
        <span>Start New Campaign</span>
    </a>
    <a href="?page=traffic-sources" class="btn btn-outline" style="padding: 6px 14px; font-size: 13px; display: inline-flex; align-items: center; gap: 6px;">
        <img src="<?= ASSETS_BASE_URL ?>/assets/images/trafficsources.png" alt="Traffic Sources" style="width: 16px; height: 16px;">
        <span>Traffic Sources</span>
    </a>
    <a href="?page=offers" class="btn btn-outline" style="padding: 6px 14px; font-size: 13px; display: inline-flex; align-items: center; gap: 6px;">
        <img src="<?= ASSETS_BASE_URL ?>/assets/images/offers.png" alt="Offers" style="width: 16px; height: 16px;">
        <span>Offers</span>
    </a>
    <a href="?page=landing-pages" class="btn btn-outline" style="padding: 6px 14px; font-size: 13px; display: inline-flex; align-items: center; gap: 6px;">
        <img src="<?= ASSETS_BASE_URL ?>/assets/images/landingpages.png" alt="Landing Pages" style="width: 16px; height: 16px;">
        <span>Landing Pages</span>
    </a>
    <a href="?page=campaign-list" class="btn btn-outline" style="padding: 6px 14px; font-size: 13px; display: inline-flex; align-items: center; gap: 6px;">
        <img src="<?= ASSETS_BASE_URL ?>/assets/images/campaigns.png" alt="Campaigns" style="width: 16px; height: 16px;">
        <span>Campaigns</span>
    </a>
</div>

<!-- Getting Started Modal -->
<?php if ($showGettingStarted): ?>
<div id="getting-started-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.85); backdrop-filter: blur(4px); z-index: 9999; align-items: center; justify-content: center; animation: fadeIn 0.3s ease;">
    <div class="modal-content-wrapper" style="background: #fff; border-radius: 12px; max-width: 900px; width: 95%; max-height: 90vh; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.5); position: relative; animation: slideUp 0.3s ease; display: flex; flex-direction: column;">
        <!-- Modal Header -->
        <div style="background: linear-gradient(135deg, #3d5a26 0%, #5a7a3a 100%); color: #fff; padding: 20px 24px; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <img src="<?= ASSETS_BASE_URL ?>/assets/images/dashboard.png" alt="Dashboard" style="width: 24px; height: 24px; filter: brightness(0) invert(1);">
                <h2 style="margin: 0; font-size: 20px; font-weight: 600; color: #fff;">Getting Started with Simple KUMA</h2>
            </div>
            <button onclick="closeGettingStartedModal()" style="background: rgba(255,255,255,0.2); border: none; color: #fff; width: 36px; height: 36px; border-radius: 50%; cursor: pointer; font-size: 24px; display: flex; align-items: center; justify-content: center; transition: all 0.2s; line-height: 1;" 
                    onmouseover="this.style.background='rgba(255,255,255,0.3)'; this.style.transform='rotate(90deg)'" onmouseout="this.style.background='rgba(255,255,255,0.2)'; this.style.transform='rotate(0deg)'">
                ×
            </button>
        </div>
        
        <p style="margin: 0; padding: 12px 24px; background: #f5f5f5; font-size: 14px; color: #555; text-align: center;">
            Tip: Fast forward past the install section to see the user guide.
        </p>
        
        <!-- Modal Body - Video Container -->
        <div class="modal-content" style="padding: 0; flex: 1; display: flex; align-items: center; justify-content: center; background: #000; position: relative; overflow: hidden;">
            <div style="position: relative; width: 100%; padding-bottom: 56.25%; height: 0; background: #000;">
                <iframe id="getting-started-video" 
                        src="" 
                        data-src="https://www.youtube.com/embed/lGGHeYGJS1E?autoplay=1&rel=0&modestbranding=1&playsinline=1" 
                        frameborder="0" 
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" 
                        allowfullscreen
                        style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: none;">
                </iframe>
            </div>
        </div>
        
        <!-- Modal Footer -->
        <div style="padding: 16px 24px; border-top: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; background: #fafafa; flex-shrink: 0;">
            <button onclick="closeGettingStartedModal()" class="btn btn-outline" style="padding: 8px 20px; font-size: 14px;">
                Close
            </button>
            <a href="?page=dashboard&dismiss_guide=1" 
               class="btn btn-outline" 
               style="padding: 8px 20px; font-size: 14px; border-color: #d32f2f; color: #d32f2f;"
               onclick="return confirm('Hide this guide permanently?')">
                ✕ Dismiss Permanently
            </a>
        </div>
    </div>
</div>

<style>
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideUp {
    from { 
        opacity: 0;
        transform: translateY(30px);
    }
    to { 
        opacity: 1;
        transform: translateY(0);
    }
}

#getting-started-modal {
    animation: fadeIn 0.3s ease;
}

#getting-started-modal .modal-content-wrapper {
    animation: slideUp 0.3s ease;
}

/* Responsive video container */
@media (max-width: 768px) {
    #getting-started-modal .modal-content-wrapper {
        width: 98%;
        max-height: 95vh;
        border-radius: 8px;
    }
    
    #getting-started-modal .modal-content-wrapper h2 {
        font-size: 16px !important;
    }
}

/* Performance chart container - desktop height */
.performance-chart-container {
    height: 400px;
}

/* Compact bar when Performance Trends is hidden */
.dashboard-chart-collapsed {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 16px;
    padding: 8px 12px;
    background: transparent;
    border: 1px dashed var(--color-gray-300, #d1d5db);
    border-radius: var(--radius-sm, 4px);
}

.dashboard-chart-collapsed-label {
    font-size: 13px;
    color: var(--color-gray-600, #666);
}

[data-theme-base="dark"] .dashboard-chart-collapsed {
    border-color: rgba(255, 255, 255, 0.15);
}

[data-theme-base="dark"] .dashboard-chart-collapsed-label {
    color: var(--chart-tick, #adbac7);
}

/* Performance chart container - mobile: no fixed height */
@media (max-width: 768px) {
    .performance-chart-container {
        height: auto;
    }
}
</style>

<script>
function openGettingStartedModal() {
    const modal = document.getElementById('getting-started-modal');
    const video = document.getElementById('getting-started-video');
    if (modal) {
        modal.style.display = 'flex';
        // Prevent body scroll when modal is open
        document.body.style.overflow = 'hidden';
        // Lazy load video only when modal opens
        if (video) {
            const dataSrc = video.getAttribute('data-src');
            if (dataSrc) {
                // Always load from data-src when opening (we clear it on close)
                video.src = dataSrc;
            }
        }
    }
}

function closeGettingStartedModal() {
    const modal = document.getElementById('getting-started-modal');
    const video = document.getElementById('getting-started-video');
    if (modal) {
        modal.style.display = 'none';
        // Restore body scroll
        document.body.style.overflow = '';
    }
    // Stop video playback and unload iframe when closing
    if (video) {
        video.src = '';
    }
}

// Close modal when clicking outside
document.getElementById('getting-started-modal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeGettingStartedModal();
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('getting-started-modal');
        if (modal && modal.style.display === 'flex') {
            closeGettingStartedModal();
        }
    }
});
</script>
<?php endif; ?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center;">
    <div>
        <h1 class="page-title">Bearboard</h1>
        <p class="page-description">Follow the tracks. Find the profit.</p>
    </div>
    <form id="dashboard-date-form" method="get" style="display: flex; flex-direction: column; gap: 8px; align-items: flex-end;">
        <input type="hidden" name="page" value="dashboard">
        <?php if (defined('DEBUG_MODE') && DEBUG_MODE && isset($_GET['debug']) && $_GET['debug'] === 'costs'): ?><input type="hidden" name="debug" value="costs"><?php endif; ?>
        <!-- Minimal Preset Buttons -->
        <?php
        // Calculate dates in user's timezone
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
        <div class="dash-date-presets">
            <button type="button" class="dash-date-preset<?= $activePreset === 'today' ? ' dash-date-preset--active' : '' ?>" onclick="setDashDate('today')">Today</button>
            <button type="button" class="dash-date-preset<?= $activePreset === 'yesterday' ? ' dash-date-preset--active' : '' ?>" onclick="setDashDate('yesterday')">Yesterday</button>
            <button type="button" class="dash-date-preset<?= $activePreset === 'last7' ? ' dash-date-preset--active' : '' ?>" onclick="setDashDate('last7')">7d</button>
            <button type="button" class="dash-date-preset<?= $activePreset === 'last14' ? ' dash-date-preset--active' : '' ?>" onclick="setDashDate('last14')">14d</button>
            <button type="button" class="dash-date-preset<?= $activePreset === 'last30' ? ' dash-date-preset--active' : '' ?>" onclick="setDashDate('last30')">30d</button>
            <button type="button" class="dash-date-preset<?= $activePreset === 'lastmonth' ? ' dash-date-preset--active' : '' ?>" onclick="setDashDate('lastmonth')">Last Mo</button>
            <button type="button" class="dash-date-preset<?= $activePreset === 'thismonth' ? ' dash-date-preset--active' : '' ?>" onclick="setDashDate('thismonth')">This Mo</button>
            <button type="button" class="dash-date-preset<?= $activePreset === 'alltime' ? ' dash-date-preset--active' : '' ?>" onclick="setDashDate('alltime')">ALL TIME</button>
        </div>
        <div class="dash-date-range">
            <input type="date" name="date_from" id="dash_date_from" class="dash-date-input" value="<?= $dateFrom ?>">
            <span class="dash-date-separator">to</span>
            <input type="date" name="date_to" id="dash_date_to" class="dash-date-input" value="<?= $dateTo ?>">
            <button type="submit" class="btn btn-primary">Update</button>
        </div>
        <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
            <span style="font-size: 12px; color: #666; font-weight: 600;">Show:</span>
            <label style="display: inline-flex; align-items: center; gap: 4px; font-size: 12px; color: #666; cursor: pointer;">
                <input type="checkbox" name="status_filter[]" value="active" <?= ($allowedStatuses === null || in_array('active', $allowedStatuses)) ? 'checked' : '' ?>>
                Active
            </label>
            <label style="display: inline-flex; align-items: center; gap: 4px; font-size: 12px; color: #666; cursor: pointer;">
                <input type="checkbox" name="status_filter[]" value="paused" <?= ($allowedStatuses === null || in_array('paused', $allowedStatuses)) ? 'checked' : '' ?>>
                Paused
            </label>
            <label style="display: inline-flex; align-items: center; gap: 4px; font-size: 12px; color: #666; cursor: pointer;">
                <input type="checkbox" name="status_filter[]" value="archived" <?= ($allowedStatuses === null || in_array('archived', $allowedStatuses)) ? 'checked' : '' ?>>
                Archived
            </label>
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
        
        function setDashDate(preset) {
            let fromDate, toDate;
            switch(preset) {
                case 'today': 
                    fromDate = toDate = datePresets.today; 
                    break;
                case 'yesterday': 
                    fromDate = toDate = datePresets.yesterday; 
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
            document.getElementById('dash_date_from').value = fromDate;
            document.getElementById('dash_date_to').value = toDate;
            document.getElementById('dashboard-date-form').submit();
        }
        </script>
    </form>
</div>

<?php if (defined('DEBUG_MODE') && DEBUG_MODE && isset($_GET['debug']) && $_GET['debug'] === 'costs'): ?>
<?php
    $debugCountStmt = $db->prepare("SELECT COUNT(*) as cnt FROM clicks WHERE ts >= ? AND ts <= ?");
    $debugCountStmt->bind_param('ss', $utcDateFrom, $utcDateTo);
    $debugCountStmt->execute();
    $debugRawCount = (int)($debugCountStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $debugCountStmt->close();
?>
<!-- DEBUG: Timezone/Costs - only visible with ?debug=costs -->
<div class="card" style="margin-bottom: 16px; background: #fff8e1; border: 1px solid #ffc107;">
    <div class="card-body" style="padding: 16px;">
        <h4 style="margin: 0 0 12px 0; color: #795548;">🔧 Debug: Timezone & Date Range</h4>
        <table style="font-size: 12px; font-family: monospace;">
            <tr><td style="padding: 2px 8px 2px 0;">GLOBALS userTimezone</td><td><strong><?= htmlspecialchars($GLOBALS['userTimezone'] ?? '(not set)') ?></strong></td></tr>
            <tr><td style="padding: 2px 8px 2px 0;">currentUser timezone (DB)</td><td><strong><?= htmlspecialchars(($GLOBALS['currentUser'] && isset($GLOBALS['currentUser']['timezone'])) ? $GLOBALS['currentUser']['timezone'] : '(null/not set)') ?></strong></td></tr>
            <tr><td style="padding: 2px 8px 2px 0;">$userTimezone (after normalize)</td><td><strong><?= htmlspecialchars($userTimezone) ?></strong></td></tr>
            <tr><td style="padding: 2px 8px 2px 0;">dateFrom / dateTo</td><td><?= htmlspecialchars($dateFrom) ?> → <?= htmlspecialchars($dateTo) ?></td></tr>
            <tr><td style="padding: 2px 8px 2px 0;">utcDateFrom / utcDateTo</td><td><?= htmlspecialchars($utcDateFrom) ?> → <?= htmlspecialchars($utcDateTo) ?></td></tr>
            <tr><td style="padding: 2px 8px 2px 0;">todayInUserTz</td><td><?= htmlspecialchars($todayInUserTz) ?></td></tr>
            <tr><td style="padding: 2px 8px 2px 0;">Raw clicks in range (verification)</td><td><strong><?= number_format($debugRawCount) ?></strong> (views shown: <?= number_format($clicks) ?>)</td></tr>
        </table>
        <p style="margin: 12px 0 0 0; font-size: 11px; color: #666;">If userTimezone is UTC or wrong, check Settings → your profile timezone is saved. Change timezone, save, then <strong>hard refresh (Ctrl+F5)</strong> or open dashboard in incognito. Use <strong>Today</strong> button to refresh dates.</p>
        <p style="margin: 8px 0 0 0; font-size: 11px; color: #666;"><strong>Stats don't change when switching timezones?</strong> If all your clicks fall in the overlapping hours (e.g. 10am–6pm local), PST/EST/GMT "today" might include the same data. Try <strong>Yesterday</strong> or a wider range (7d) to see a difference—data near midnight will shift between days.</p>
    </div>
</div>
<?php endif; ?>

<!-- Stats Cards -->
<div class="dashboard-stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 32px;">
    <div class="card" style="padding: 16px;">
        <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Views</div>
        <div style="font-size: 28px; font-weight: 700; color: #3d5a26;"><?= number_format($clicks) ?></div>
        <div style="font-size: 11px; color: #999; margin-top: 4px;">👁️ Campaign link clicks</div>
    </div>
    
    <div class="card" style="padding: 16px;">
        <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Conversions</div>
        <div style="font-size: 28px; font-weight: 700; color: #3d5a26;"><?= number_format($conversions) ?></div>
        <div style="font-size: 11px; color: #999; margin-top: 4px;">💰 CR: <?= number_format($cr, 2) ?>%</div>
    </div>
    
    <div class="card" style="padding: 16px;">
        <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Revenue</div>
        <div style="font-size: 28px; font-weight: 700; color: #28a745;"><?= Formatter::formatCurrency($revenue, $userCurrency) ?></div>
        <div style="font-size: 11px; color: #999; margin-top: 4px;">📈 EPC: <?= Formatter::formatCurrency($epc, $userCurrency) ?></div>
    </div>
    
    <div class="card" style="padding: 16px;">
        <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Cost</div>
        <div style="font-size: 28px; font-weight: 700; color: #d32f2f;"><?= Formatter::formatCurrency($cost, $userCurrency) ?></div>
        <div style="font-size: 11px; color: #999; margin-top: 4px;">💸 Spend</div>
    </div>
    
    <div class="card" style="padding: 16px;">
        <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Profit</div>
        <div style="font-size: 28px; font-weight: 700; color: <?= $profit >= 0 ? '#28a745' : '#d32f2f' ?>;">
            <?= Formatter::formatCurrency($profit, $userCurrency) ?>
        </div>
        <div style="font-size: 11px; color: #999; margin-top: 4px;">🎯 ROI: <?= number_format($roi, 1) ?>%</div>
    </div>
</div>




<!-- Performance Charts -->
<?php if ($includeDashboardChart): ?>
<div class="card" style="margin-bottom: 32px;" id="dashboard-performance-chart-card">
    <div class="card-header" style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
        <h2 class="card-title" style="margin-bottom: 0;">Performance Trends</h2>
        <button type="button" class="btn btn-sm btn-secondary" data-dashboard-chart-toggle="hide">Hide chart</button>
    </div>
    <div class="card-body performance-chart-container" style="padding: 24px; position: relative;">
        <canvas id="performanceChart"></canvas>
    </div>
</div>
<?php else: ?>
<div class="dashboard-chart-collapsed" id="dashboard-performance-chart-card">
    <span class="dashboard-chart-collapsed-label">Performance Trends hidden</span>
    <button type="button" class="btn btn-sm btn-secondary" data-dashboard-chart-toggle="show">Show chart</button>
</div>
<?php endif; ?>

<?php if ($includeDashboardChart): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
function getDashboardChartTheme() {
    const root = getComputedStyle(document.documentElement);
    const css = (name, fallback) => {
        const v = root.getPropertyValue(name).trim();
        return v || fallback;
    };
    const meta = (window.KumaTheme && typeof window.KumaTheme.meta === 'function')
        ? window.KumaTheme.meta()
        : { base: document.documentElement.getAttribute('data-theme-base') || 'light' };
    const isDark = meta.base === 'dark';
    return {
        isDark: isDark,
        gridColor: css('--chart-grid', isDark ? 'rgba(255, 255, 255, 0.08)' : 'rgba(0, 0, 0, 0.05)'),
        tickColor: css('--chart-tick', isDark ? '#adbac7' : '#666'),
        legendColor: css('--chart-legend', isDark ? '#adbac7' : '#666'),
        pointBorder: css('--chart-point-border', isDark ? '#2d333b' : '#fff'),
        clicksColor: css('--chart-clicks', isDark ? '#7fd67e' : '#3d5a26'),
        clicksFill: css('--chart-clicks-fill', isDark ? 'rgba(127, 214, 126, 0.15)' : 'rgba(61, 90, 38, 0.1)'),
        tooltipBorder: css('--chart-tooltip-border', isDark ? '#6abf69' : '#3d5a26'),
        tooltipBg: css('--chart-tooltip-bg', isDark ? 'rgba(45, 51, 59, 0.95)' : 'rgba(0, 0, 0, 0.8)')
    };
}

function initPerformanceChart() {
    const ctx = document.getElementById('performanceChart');
    if (!ctx) {
        return;
    }

    if (window.performanceChartInstance) {
        window.performanceChartInstance.destroy();
        window.performanceChartInstance = null;
    }

    const theme = getDashboardChartTheme();
    const chartLabels = <?= json_encode($chartLabels ?? []) ?>;
    const clicksData = <?= json_encode($clicksData ?? []) ?>;
    const conversionsData = <?= json_encode($conversionsData ?? []) ?>;
    const revenueData = <?= json_encode($revenueData ?? []) ?>;

    if (!Array.isArray(chartLabels) || !Array.isArray(clicksData) || !Array.isArray(conversionsData) || !Array.isArray(revenueData)) {
        console.error('Chart data is invalid');
        return;
    }

    const maxLength = Math.max(chartLabels.length, clicksData.length, conversionsData.length, revenueData.length);
    while (clicksData.length < maxLength) clicksData.push(0);
    while (conversionsData.length < maxLength) conversionsData.push(0);
    while (revenueData.length < maxLength) revenueData.push(0);

    try {
        window.performanceChartInstance = new Chart(ctx.getContext('2d'), {
        type: 'line',
        data: {
            labels: chartLabels,
            datasets: [
                {
                    label: 'Clicks',
                    data: clicksData,
                    borderColor: theme.clicksColor,
                    backgroundColor: theme.clicksFill,
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    pointBackgroundColor: theme.clicksColor,
                    pointBorderColor: theme.pointBorder,
                    pointBorderWidth: 2,
                    yAxisID: 'y'
                },
                {
                    label: 'Conversions',
                    data: conversionsData,
                    borderColor: '#0066cc',
                    backgroundColor: 'rgba(0, 102, 204, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    pointBackgroundColor: '#0066cc',
                    pointBorderColor: theme.pointBorder,
                    pointBorderWidth: 2,
                    yAxisID: 'y'
                },
                {
                    label: 'Revenue ($)',
                    data: revenueData,
                    borderColor: '#ff9800',
                    backgroundColor: 'rgba(255, 152, 0, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    pointBackgroundColor: '#ff9800',
                    pointBorderColor: theme.pointBorder,
                    pointBorderWidth: 2,
                    yAxisID: 'y1'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        padding: 15,
                        font: {
                            size: 13,
                            weight: '600',
                            family: 'var(--font-family)'
                        },
                        color: theme.legendColor
                    }
                },
                tooltip: {
                    backgroundColor: theme.tooltipBg,
                    padding: 12,
                    titleFont: {
                        size: 14,
                        weight: '600'
                    },
                    bodyFont: {
                        size: 13
                    },
                    borderColor: theme.tooltipBorder,
                    borderWidth: 1,
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            if (context.parsed.y !== null) {
                                if (label.includes('Revenue')) {
                                    label += '$' + context.parsed.y.toFixed(2);
                                } else {
                                    label += context.parsed.y.toLocaleString();
                                }
                            }
                            return label;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        display: true,
                        color: theme.gridColor,
                        drawBorder: false
                    },
                    ticks: {
                        font: {
                            size: 11
                        },
                        color: theme.tickColor
                    }
                },
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    beginAtZero: true,
                    grid: {
                        display: true,
                        color: theme.gridColor,
                        drawBorder: false
                    },
                    ticks: {
                        font: {
                            size: 11
                        },
                        color: theme.tickColor,
                        callback: function(value) {
                            return value.toLocaleString();
                        }
                    },
                    title: {
                        display: true,
                        text: 'Clicks & Conversions',
                        font: {
                            size: 12,
                            weight: '600'
                        },
                        color: theme.tickColor
                    }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    beginAtZero: true,
                    grid: {
                        display: false
                    },
                    ticks: {
                        font: {
                            size: 11
                        },
                        color: theme.tickColor,
                        callback: function(value) {
                            return '$' + value.toFixed(0);
                        }
                    },
                    title: {
                        display: true,
                        text: 'Revenue ($)',
                        font: {
                            size: 12,
                            weight: '600'
                        },
                        color: theme.tickColor
                    }
                }
            }
        }
    });
    } catch (error) {
        console.error('Error initializing chart:', error);
        const errorDiv = document.createElement('div');
        errorDiv.style.cssText = 'padding: 20px; background: #ffebee; color: #c62828; border-radius: 4px; margin: 20px 0;';
        errorDiv.textContent = 'Error loading chart: ' + error.message;
        ctx.parentElement.appendChild(errorDiv);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    initPerformanceChart();
});

window.addEventListener('kuma-theme-change', function() {
    initPerformanceChart();
});
</script>
<?php endif; ?>

<!-- Campaign Performance Table (lazy-loaded) -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">Campaign Performance</h2>
        <a href="?page=dashboard&export=csv&date_from=<?= htmlspecialchars($dateFrom) ?>&date_to=<?= htmlspecialchars($dateTo) ?>" class="btn btn-secondary">📥 Export CSV</a>
    </div>
    <div class="card-body" id="dashboard-campaigns-body"
         data-campaigns-url="<?= htmlspecialchars($dashboardCampaignsApiUrl) ?>">
        <div id="dashboard-campaigns-loading" style="text-align:center; padding:40px; color:#666;">
            <div style="font-size:14px; margin-bottom:8px;">Loading campaign performance…</div>
            <div style="font-size:12px; color:#999;">Overview and chart loaded first for faster page start.</div>
        </div>
        <div id="dashboard-campaigns-error" style="display:none; text-align:center; padding:24px; color:#c62828;"></div>
        <div id="dashboard-campaigns-content"></div>
    </div>
</div>

<script>
(function() {
    var activeCampaignsAbort = null;
    var activeCampaignsPageHide = null;

    function loadDashboardCampaigns(url) {
        var body = document.getElementById('dashboard-campaigns-body');
        var loading = document.getElementById('dashboard-campaigns-loading');
        var errorEl = document.getElementById('dashboard-campaigns-error');
        var content = document.getElementById('dashboard-campaigns-content');
        if (!body || !content) return;

        if (!url) {
            url = body.getAttribute('data-campaigns-url');
        }
        if (!url) return;

        // Abort any in-flight campaigns fetch (pagination / rapid reloads).
        if (activeCampaignsAbort) {
            try { activeCampaignsAbort.abort(); } catch (ignore) {}
            activeCampaignsAbort = null;
        }
        if (activeCampaignsPageHide) {
            window.removeEventListener('pagehide', activeCampaignsPageHide);
            activeCampaignsPageHide = null;
        }

        if (loading) loading.style.display = 'block';
        if (errorEl) {
            errorEl.style.display = 'none';
            errorEl.textContent = '';
        }
        content.innerHTML = '';

        var controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        activeCampaignsAbort = controller;
        var onPageHide = function() {
            if (controller) controller.abort();
        };
        activeCampaignsPageHide = onPageHide;
        window.addEventListener('pagehide', onPageHide);

        function fetchCampaigns(attempt) {
            return fetch(url, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
                signal: controller ? controller.signal : undefined
            }).then(function(res) {
                return res.json().then(function(data) {
                    return { ok: res.ok, status: res.status, data: data };
                });
            }).then(function(result) {
                if (result.data && result.data.busy && attempt < 2) {
                    return new Promise(function(resolve) {
                        setTimeout(function() { resolve(fetchCampaigns(attempt + 1)); }, 800);
                    });
                }
                return result;
            });
        }

        fetchCampaigns(0)
            .then(function(result) {
                if (activeCampaignsPageHide === onPageHide) {
                    window.removeEventListener('pagehide', onPageHide);
                    activeCampaignsPageHide = null;
                }
                if (activeCampaignsAbort === controller) {
                    activeCampaignsAbort = null;
                }
                if (loading) loading.style.display = 'none';
                if (!result.ok || !result.data || !result.data.ok) {
                    var msg = (result.data && result.data.error) ? result.data.error : 'Failed to load campaign performance';
                    if (errorEl) {
                        errorEl.style.display = 'block';
                        errorEl.textContent = msg;
                    }
                    return;
                }
                content.innerHTML = result.data.html || '';
                body.setAttribute('data-campaigns-url', url);
                wireDashboardCampaignPagination(content);
            })
            .catch(function(err) {
                if (activeCampaignsPageHide === onPageHide) {
                    window.removeEventListener('pagehide', onPageHide);
                    activeCampaignsPageHide = null;
                }
                if (activeCampaignsAbort === controller) {
                    activeCampaignsAbort = null;
                }
                if (err && (err.name === 'AbortError' || err.code === 20)) {
                    return;
                }
                if (loading) loading.style.display = 'none';
                if (errorEl) {
                    errorEl.style.display = 'block';
                    errorEl.textContent = 'Failed to load campaign performance';
                }
                console.error(err);
            });
    }

    function wireDashboardCampaignPagination(root) {
        if (!root) return;
        root.querySelectorAll('a[data-dashboard-campaigns-page]').forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                var page = link.getAttribute('data-dashboard-campaigns-page');
                var body = document.getElementById('dashboard-campaigns-body');
                var base = body ? body.getAttribute('data-campaigns-url') : '';
                if (!base || !page) return;
                var u = new URL(base, window.location.origin);
                u.searchParams.set('campaigns_page', page);
                loadDashboardCampaigns(u.toString());
                try {
                    var pageUrl = new URL(window.location.href);
                    pageUrl.searchParams.set('campaigns_page', page);
                    history.replaceState(null, '', pageUrl.toString());
                } catch (ignore) {}
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        loadDashboardCampaigns();
    });

    window.toggleMobileGroup = function(groupId) {
        var groupDiv = document.getElementById('mobile-group-' + groupId);
        var toggle = document.getElementById('mobile-toggle-' + groupId);
        if (groupDiv && toggle) {
            var isVisible = groupDiv.style.display !== 'none';
            groupDiv.style.display = isVisible ? 'none' : 'block';
            toggle.textContent = isVisible ? '▼' : '▲';
        }
    };

    window.toggleMobileTrafficSources = function(campaignId) {
        var tsDiv = document.getElementById(campaignId);
        var toggle = document.getElementById('mobile-ts-toggle-' + campaignId.replace('mobile-campaign-', ''));
        if (tsDiv && toggle) {
            var isVisible = tsDiv.style.display !== 'none';
            tsDiv.style.display = isVisible ? 'none' : 'block';
            toggle.textContent = isVisible ? '▶' : '▼';
        }
    };

    window.toggleDashboardGroup = function(groupId) {
        var groupRows = document.querySelectorAll('.group-' + groupId);
        var toggle = document.getElementById('toggle-' + groupId);
        if (groupRows.length === 0) return;
        var campaignRows = Array.from(groupRows).filter(function(row) {
            return !row.classList.contains('traffic-source-row');
        });
        if (campaignRows.length === 0) return;
        var isVisible = campaignRows[0].style.display !== 'none';
        campaignRows.forEach(function(row) {
            row.style.display = isVisible ? 'none' : '';
        });
        if (isVisible) {
            Array.from(groupRows).filter(function(row) {
                return row.classList.contains('traffic-source-row');
            }).forEach(function(row) {
                row.style.display = 'none';
                var campaignIdMatch = row.className.match(/campaign-(\d+)/);
                if (campaignIdMatch) {
                    var toggleBtn = document.getElementById('toggle-btn-campaign-' + campaignIdMatch[1]);
                    if (toggleBtn) toggleBtn.textContent = '▶';
                }
            });
        }
        if (toggle) toggle.textContent = isVisible ? '▶' : '▼';
    };

    window.toggleTrafficSources = function(campaignId) {
        var trafficSourceRows = document.querySelectorAll('.traffic-source-row.' + campaignId);
        var toggleBtn = document.getElementById('toggle-btn-' + campaignId);
        if (trafficSourceRows.length === 0 || !toggleBtn) return;
        var isVisible = trafficSourceRows[0].style.display !== 'none';
        trafficSourceRows.forEach(function(row) {
            row.style.display = isVisible ? 'none' : '';
        });
        toggleBtn.textContent = isVisible ? '▶' : '▼';
    };
})();
</script>

<?php $db->close(); ?>

