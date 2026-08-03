<?php

declare(strict_types=1);

/**
 * Simple KUMA - Main Entry Point
 */

// Production: log errors but do not display to users
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Check if config exists, if not redirect to installer
if (!file_exists(__DIR__ . '/../config/config.php')) {
    header('Location: install.php');
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/bootstrap_web_paths.php';

use SimpleKuma\Auth\Auth;
use SimpleKuma\Auth\Permission;
use SimpleKuma\Auth\SingleAdminMode;
use SimpleKuma\Theme\ThemeRegistry;

// Database connection for auth
$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
// Ensure MySQL session timezone is UTC for consistent timestamp storage
$db->query("SET time_zone = '+00:00'");
$auth = new Auth($db);

// Require authentication
$auth->requireAuth();

// Admin UI must not be indexed or associated with public Safe Browsing crawls
header('X-Robots-Tag: noindex, nofollow', true);

// Get permission instance
$permission = $auth->getPermission();

// Reload roles if not in session (for backward compatibility)
if (empty($_SESSION['role_ids']) && $permission) {
    // Force reload by creating new auth instance
    $auth = new Auth($db);
    $permission = $auth->getPermission();
}

// Check if user has no roles (e.g., newly installed admin)
$currentUser = $auth->getCurrentUser();
$hasNoRoles = empty($_SESSION['role_ids'] ?? []);

// Campaign status filter preference (persists across sessions)
$userCampaignStatusFilter = null;
$hasCampaignStatusFilterColumn = false;
$colCheck = $db->query("SHOW COLUMNS FROM users LIKE 'campaign_status_filter'");
if ($colCheck && $colCheck->num_rows > 0 && isset($currentUser['id'])) {
    $hasCampaignStatusFilterColumn = true;
    $uid = (int) $currentUser['id'];
    $stmt = $db->prepare("SELECT campaign_status_filter FROM users WHERE id = ?");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    $raw = $row['campaign_status_filter'] ?? null;
    if ($raw !== null && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $validStatuses = ['active', 'paused', 'archived'];
            $filtered = array_values(array_intersect($decoded, $validStatuses));
            if (count($filtered) > 0 && count($filtered) < 3) {
                $userCampaignStatusFilter = $filtered;
            }
        }
    }
}
$GLOBALS['userCampaignStatusFilter'] = $userCampaignStatusFilter;
$GLOBALS['hasCampaignStatusFilterColumn'] = $hasCampaignStatusFilterColumn;

// UI theme preference (persists across sessions)
$userTheme = ThemeRegistry::DEFAULT_THEME;
$hasThemeColumn = false;
$themeColCheck = $db->query("SHOW COLUMNS FROM users LIKE 'theme'");
if ($themeColCheck && $themeColCheck->num_rows > 0) {
    $hasThemeColumn = true;
    if ($currentUser && isset($currentUser['theme'])) {
        $userTheme = ThemeRegistry::normalize($currentUser['theme']);
    }
}
$GLOBALS['userTheme'] = $userTheme;
$GLOBALS['hasThemeColumn'] = $hasThemeColumn;

// Desktop sidebar collapse + dashboard chart visibility (persist across sessions)
$sidebarCollapsed = false;
if ($currentUser && array_key_exists('sidebar_collapsed', $currentUser)) {
    $sidebarCollapsed = (int) $currentUser['sidebar_collapsed'] === 1;
}
$GLOBALS['sidebarCollapsed'] = $sidebarCollapsed;

$dashboardChartsHidden = false;
if ($currentUser && array_key_exists('dashboard_charts_hidden', $currentUser)) {
    $dashboardChartsHidden = (int) $currentUser['dashboard_charts_hidden'] === 1;
}
$GLOBALS['dashboardChartsHidden'] = $dashboardChartsHidden;

// Simple router
$page = $_GET['page'] ?? 'dashboard';
$currentPage = $page;

// Legacy ?page=campaigns is a known Safe Browsing fingerprint — redirect GET traffic to campaign-list
if ($page === 'campaigns' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $params = $_GET;
    $params['page'] = 'campaign-list';
    header('Location: ' . APP_BASE_URL . '/index.php?' . http_build_query($params));
    exit;
}

// Redirect legacy API settings tab to Kuma API page
if ($page === 'settings' && ($_GET['tab'] ?? '') === 'api') {
    header('Location: ' . APP_BASE_URL . '/index.php?page=kuma-api');
    exit;
}

// Redirect legacy users page to settings account tab
if ($page === 'users') {
    header('Location: ' . APP_BASE_URL . '/index.php?page=settings&tab=account');
    exit;
}

// campaign-stats-v2 bookmarks → canonical campaign-stats URL
if ($page === 'campaign-stats-v2') {
    $params = $_GET;
    $params['page'] = 'campaign-stats';
    header('Location: ' . APP_BASE_URL . '/index.php?' . http_build_query($params));
    exit;
}

// Allowed pages
$allowedPages = [
    'dashboard',
    'campaigns', // POST/backward compat only (GET redirects to campaign-list)
    'campaign-list',
    'campaign-create',
    'traffic-sources',
    'offers',
    'landing-pages',
    'networks',
    'visitors',
    'conversions',
    'click-lookup',
    'short-links',
    'postback-urls',
    'kuma-api',
    'settings',
    'campaign-stats',
    'campaign-stats-legacy',
    'billing',
];

// Validate page
if (!in_array($page, $allowedPages, true)) {
    $page = 'dashboard';
}

// Permission mapping for pages
$pagePermissions = [
    'dashboard' => Permission::PERM_DASHBOARD_VIEW,
    'campaigns' => Permission::PERM_CAMPAIGN_VIEW,
    'campaign-list' => Permission::PERM_CAMPAIGN_VIEW,
    'campaign-create' => Permission::PERM_CAMPAIGN_CREATE,
    'traffic-sources' => Permission::PERM_TRAFFIC_SOURCE_VIEW,
    'offers' => Permission::PERM_OFFER_VIEW,
    'landing-pages' => Permission::PERM_LANDING_PAGE_VIEW,
    'networks' => Permission::PERM_NETWORK_VIEW,
    'visitors' => Permission::PERM_VISITOR_LOG_VIEW,
    'conversions' => Permission::PERM_VISITOR_LOG_VIEW,
    'click-lookup' => Permission::PERM_CAMPAIGN_VIEW, // Same as campaigns
    'short-links' => Permission::PERM_CAMPAIGN_VIEW, // Same as campaigns
    'postback-urls' => Permission::PERM_POSTBACK_VIEW,
    'kuma-api' => Permission::PERM_SETTINGS_VIEW,
    'settings' => Permission::PERM_SETTINGS_VIEW,
    'campaign-stats' => Permission::PERM_STATS_VIEW,
    'campaign-stats-legacy' => Permission::PERM_STATS_VIEW,
    'billing' => Permission::PERM_BILLING_VIEW,
    'users' => Permission::PERM_USER_MANAGE,
];

// Check permission for page access
// Allow access if:
// 1. Permission system is not active ($permission is null)
// 2. User has the required permission
// 3. User has no roles assigned (fallback for legacy installations created before role assignment was added)
if ($permission && isset($pagePermissions[$page])) {
    $hasPermission = $permission->hasPermission($pagePermissions[$page]);
    // Fallback: Allow access if user has no roles (for legacy installations)
    // Note: New installations will have admin role assigned automatically by installer
    $shouldAllow = $hasPermission
        || SingleAdminMode::isEnabled()
        || ($hasNoRoles && Auth::allowsLegacyNoRolesFallback());
    
    if (!$shouldAllow) {
        // Log permission denied
        $auditLogger = new \SimpleKuma\Auth\AuditLogger($db);
        $auditLogger->logPermissionDenied($pagePermissions[$page], $page);
        
        // Show 403 page
        http_response_code(403);
        include __DIR__ . '/../views/layout/base.php';
        echo '<div class="page-header">';
        echo '<h1 class="page-title">Access Denied</h1>';
        echo '</div>';
        echo '<div class="card">';
        echo '<div class="card-body" style="text-align: center; padding: 60px;">';
        echo '<div style="font-size: 64px; margin-bottom: 16px;">🚫</div>';
        echo '<h2 style="color: #d32f2f; margin-bottom: 16px;">403 - Forbidden</h2>';
        echo '<p style="color: #666; margin-bottom: 24px;">You do not have permission to access this page.</p>';
        echo '<a href="' . APP_BASE_URL . '/index.php?page=dashboard" class="btn btn-primary">Go to Dashboard</a>';
        echo '</div>';
        echo '</div>';
        exit;
    }
}

// Set page title
$pageTitles = [
    'dashboard' => 'Dashboard',
    'campaigns' => 'Campaigns',
    'campaign-list' => 'Campaigns',
    'traffic-sources' => 'Traffic Sources',
    'offers' => 'Offers',
    'landing-pages' => 'Landing Pages',
    'networks' => 'Networks',
    'visitors' => 'Visitor Log',
    'conversions' => 'Conversion Log',
    'click-lookup' => 'Click Lookup',
    'short-links' => 'Short Links',
    'postback-urls' => 'Postback URLs',
    'kuma-api' => 'Kuma API',
    'settings' => 'Settings',
    'campaign-stats' => 'Campaign Stats',
    'billing' => 'Billing Reports',
];

$pageTitle = $pageTitles[$page] ?? 'Dashboard';

// Handle CSV export for visitors page BEFORE rendering
if ($page === 'visitors' && isset($_GET['export']) && $_GET['export'] === 'csv') {
    require_once __DIR__ . '/../src/Utils/Formatter.php';
    $csvDb = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    $csvDb->query("SET time_zone = '+00:00'");

    $csvTz = ($currentUser && isset($currentUser['timezone'])) ? $currentUser['timezone'] : 'UTC';
    $todayInTz = \SimpleKuma\Utils\Formatter::getTodayInTimezone($csvTz);
    $dateFrom = $_GET['date_from'] ?? $todayInTz;
    $dateTo = $_GET['date_to'] ?? $todayInTz;
    $utcRange = \SimpleKuma\Utils\Formatter::convertDateRangeToUTC($dateFrom, $dateTo, $csvTz);

    // Filters
    $campaignFilter = isset($_GET['campaign']) ? (int)$_GET['campaign'] : null;
    $hasConversion = $_GET['has_conversion'] ?? null;

    // Build WHERE clause - use UTC bounds for correct timezone-aware filtering
    $where = ["cl.ts >= ? AND cl.ts <= ?"];
    $params = [$utcRange['from'], $utcRange['to']];
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
    
    $whereClause = implode(' AND ', $where);
    
    // Fetch all matching clicks (no pagination for export)
    $sql = "SELECT 
                cl.ts, cl.click_id, cp.name as campaign, cl.ip, cl.country, cl.region, cl.city,
                cl.device, cl.os, cl.browser, ts.name as traffic_source, cl.lp_click,
                conv.value as conv_value, conv.payout as conv_payout, conv.currency,
                conv.id as has_conversion
            FROM clicks cl
            INNER JOIN campaigns cp ON cl.campaign_id = cp.id
            LEFT JOIN traffic_sources ts ON cp.traffic_source_id = ts.id
            LEFT JOIN conversions conv ON cl.click_id = conv.click_id
            WHERE {$whereClause}
            ORDER BY cl.ts DESC";
    
    $stmt = $csvDb->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    // Output CSV headers (filename date in user timezone)
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="visitor-log-' . $todayInTz . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Timestamp', 'Click ID', 'Campaign', 'IP', 'Country', 'City', 'State', 'Device', 'OS', 'Browser', 'Traffic Source', 'LP Click', 'Conversion', 'Revenue']);
    
    while ($row = $result->fetch_assoc()) {
        // Determine conversion status - check if conv.id exists
        $hasConversion = !empty($row['has_conversion']);
        
        // Revenue: prefer payout over value (same logic as visitor log page)
        $revenue = '';
        if ($hasConversion) {
            if (!empty($row['conv_payout'])) {
                $revenue = $row['conv_payout'];
            } elseif (!empty($row['conv_value'])) {
                $revenue = $row['conv_value'];
            }
        }
        
        fputcsv($output, [
            \SimpleKuma\Utils\Formatter::formatDateTime($row['ts'], $csvTz),
            $row['click_id'],
            $row['campaign'],
            $row['ip'],
            $row['country'] ?? '',
            $row['city'] ?? '',
            $row['region'] ?? '',
            $row['device'] ?? '',
            $row['os'] ?? '',
            $row['browser'] ?? '',
            $row['traffic_source'] ?? 'N/A',
            $row['lp_click'] ? 'Yes' : 'No',
            $hasConversion ? 'Yes' : 'No',
            $revenue,
        ]);
    }
    
    fclose($output);
    $csvDb->close();
    exit;
}

// Handle CSV export for conversions page BEFORE rendering
if ($page === 'conversions' && isset($_GET['export']) && $_GET['export'] === 'csv') {
    require_once __DIR__ . '/../src/Utils/Formatter.php';
    require_once __DIR__ . '/../src/Stats/ConversionsQueryService.php';

    $csvTz = ($currentUser && isset($currentUser['timezone'])) ? $currentUser['timezone'] : 'UTC';
    $todayInTz = \SimpleKuma\Utils\Formatter::getTodayInTimezone($csvTz);
    $dateFrom = $_GET['date_from'] ?? $todayInTz;
    $dateTo = $_GET['date_to'] ?? $todayInTz;
    $campaignFilter = isset($_GET['campaign']) && $_GET['campaign'] !== '' ? (int) $_GET['campaign'] : null;
    if ($campaignFilter === 0) {
        $campaignFilter = null;
    }

    $service = new \SimpleKuma\Stats\ConversionsQueryService($db);
    $result = $service->listConversionsForLog(
        $campaignFilter,
        $dateFrom,
        $dateTo,
        $csvTz,
        1,
        500,
        100000,
        0
    );

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="conversion-log-' . $todayInTz . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'Conversion Time',
        'Conversion ID',
        'Click ID',
        'Campaign',
        'Offer',
        'Landing Page',
        'Status',
        'Payout',
        'Value',
        'Revenue',
        'Currency',
        'TXID',
        'Event ID',
        'Traffic Source',
        'Country',
        'City',
        'State',
        'IP',
        'Source',
    ]);

    foreach ($result['rows'] as $row) {
        fputcsv($output, [
            \SimpleKuma\Utils\Formatter::formatDateTime($row['ts'], $csvTz),
            $row['id'],
            $row['click_id'],
            $row['campaign_name'] ?? '',
            $row['offer_name'] ?? '',
            $row['landing_page_name'] ?? '',
            $row['status'] ?? '',
            $row['payout'] !== null ? $row['payout'] : '',
            $row['value'] !== null ? $row['value'] : '',
            $row['revenue'],
            $row['currency'] ?? '',
            $row['txid'] ?? '',
            $row['event_id'] ?? '',
            $row['traffic_source_name'] ?? '',
            $row['country'] ?? '',
            $row['city'] ?? '',
            $row['region'] ?? '',
            $row['ip'] ?? '',
            $row['source'] ?? '',
        ]);
    }

    fclose($output);
    exit;
}

// Get current user for timezone/currency (already defined above)
$userTimezone = ($currentUser && isset($currentUser['timezone'])) ? $currentUser['timezone'] : 'UTC';
$userCurrency = ($currentUser && isset($currentUser['currency'])) ? $currentUser['currency'] : 'USD';

// Persist and backfill campaign status filter for dashboard, campaigns, campaign-stats
$filterPages = ['dashboard', 'campaigns', 'campaign-list', 'campaign-stats'];
if (in_array($page, $filterPages, true) && $GLOBALS['hasCampaignStatusFilterColumn'] && $currentUser && isset($currentUser['id'])) {
    $validStatuses = ['active', 'paused', 'archived'];
    $userId = (int) $currentUser['id'];

    if ($page === 'campaign-stats') {
        if (array_key_exists('status', $_GET)) {
            $statusVal = $_GET['status'] ?? 'all';
            $toSave = null;
            if ($statusVal === 'active' || $statusVal === 'paused' || $statusVal === 'archived') {
                $toSave = [$statusVal];
            }
            $json = $toSave === null ? null : json_encode($toSave);
            $stmt = $db->prepare("UPDATE users SET campaign_status_filter = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param('si', $json, $userId);
            $stmt->execute();
            $stmt->close();
            $GLOBALS['userCampaignStatusFilter'] = $toSave;
            $GLOBALS['campaignStatsStatusFilterArray'] = null;
        } else {
            $saved = $GLOBALS['userCampaignStatusFilter'];
            if ($saved === null || count($saved) === 3) {
                $_GET['status'] = 'all';
                $GLOBALS['campaignStatsStatusFilterArray'] = null;
            } elseif (count($saved) === 1) {
                $_GET['status'] = $saved[0];
                $GLOBALS['campaignStatsStatusFilterArray'] = null;
            } else {
                $_GET['status'] = 'all';
                $GLOBALS['campaignStatsStatusFilterArray'] = $saved;
            }
        }
    } else {
        // dashboard or campaigns: status_filter (array)
        if (array_key_exists('status_filter', $_GET)) {
            $raw = $_GET['status_filter'];
            if (!is_array($raw)) {
                $raw = $raw !== '' ? [$raw] : [];
            }
            $allowed = array_values(array_intersect($raw, $validStatuses));
            $toSave = null;
            if (count($allowed) > 0 && count($allowed) < 3) {
                $toSave = $allowed;
            }
            $json = $toSave === null ? null : json_encode($toSave);
            $stmt = $db->prepare("UPDATE users SET campaign_status_filter = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param('si', $json, $userId);
            $stmt->execute();
            $stmt->close();
            $GLOBALS['userCampaignStatusFilter'] = $toSave;
        } else {
            $saved = $GLOBALS['userCampaignStatusFilter'];
            if ($saved !== null) {
                $_GET['status_filter'] = $saved;
            }
        }
    }
}

// Make formatter available to views
require_once __DIR__ . '/../src/Utils/Formatter.php';
use SimpleKuma\Utils\Formatter;

// Map public route names to view files (campaign-list avoids GSB-fingerprinted ?page=campaigns URL)
$pageViewMap = [
    'campaign-list' => 'campaigns',
    'campaigns' => 'campaigns',
];
$viewPage = $pageViewMap[$page] ?? $page;

// Preference updates done — release session so other admin pages/APIs are not blocked
// while this request runs heavy dashboard/visitors/stats SQL.
$auth->releaseSessionLock();

// Render page
ob_start();
$viewFile = __DIR__ . "/../views/{$viewPage}.php";
if (file_exists($viewFile)) {
    // Make permission, user data, and formatter available to views
    $GLOBALS['permission'] = $permission;
    $GLOBALS['currentUser'] = $currentUser;
    $GLOBALS['userTimezone'] = $userTimezone;
    $GLOBALS['userCurrency'] = $userCurrency;
    $GLOBALS['currentPage'] = $currentPage;
    $GLOBALS['db'] = $db;
    
    // Capture any errors
    try {
        include $viewFile;
    } catch (Throwable $e) {
        // Log the error
        error_log("View error in {$viewFile}: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        // Always show error when error reporting is enabled (for troubleshooting)
        if (ini_get('display_errors')) {
            echo '<div class="card" style="background: #ffebee; border-color: #c62828; margin: 20px;">';
            echo '<div class="card-body">';
            echo '<h3 style="color: #c62828;">Error Loading Page</h3>';
            echo '<p><strong>File:</strong> ' . htmlspecialchars($viewFile) . '</p>';
            echo '<p><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '<p><strong>Line:</strong> ' . $e->getLine() . '</p>';
            echo '<pre style="background: #fff; padding: 10px; overflow: auto;">' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
            echo '</div>';
            echo '</div>';
        }
    }
} else {
    // Placeholder for pages not yet implemented
    echo '<div class="page-header">';
    echo '<h1 class="page-title">' . htmlspecialchars($pageTitle) . '</h1>';
    echo '<p class="page-description">This page is coming soon.</p>';
    echo '</div>';
    echo '<div class="card">';
    echo '<div class="card-body" style="text-align: center; padding: 60px;">';
    echo '<div style="font-size: 64px; margin-bottom: 16px;">🚧</div>';
    echo '<p style="color: #999;">This feature is under construction.</p>';
    echo '</div>';
    echo '</div>';
}
$content = ob_get_clean();

// Debug: Log if content is empty
if (empty($content) && $page === 'dashboard') {
    error_log("WARNING: Dashboard content is empty. View file: {$viewFile}, Exists: " . (file_exists($viewFile) ? 'yes' : 'no'));
}

// Include layout
include __DIR__ . '/../views/layout/base.php';

