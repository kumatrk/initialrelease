<?php

declare(strict_types=1);

/**
 * Dashboard Campaign Performance fragment (session-authenticated).
 * Lazy-loaded after overview + chart paint.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/bootstrap_web_paths.php';

use SimpleKuma\Auth\Auth;
use SimpleKuma\Auth\Permission;
use SimpleKuma\Database\DatabaseCompatibility;
use SimpleKuma\Stats\DashboardStatsService;
use SimpleKuma\Stats\ReportingBusyException;
use SimpleKuma\Stats\ReportingConcurrencyGuard;
use SimpleKuma\Stats\StatsResponseCache;
use SimpleKuma\Utils\Formatter;

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow', true);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

ignore_user_abort(false);

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($db->connect_error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database connection failed']);
    exit;
}
$db->query("SET time_zone = '+00:00'");
DatabaseCompatibility::applyReportingQueryTimeout($db, 20);

$auth = new Auth($db);
$auth->requireAuth();

$permission = $auth->getPermission();
if ($permission && !$permission->hasPermission(Permission::PERM_DASHBOARD_VIEW)
    && !$permission->hasPermission(Permission::PERM_STATS_VIEW)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Permission denied']);
    exit;
}

$currentUser = $auth->getCurrentUser();
$userId = (int)($currentUser['id'] ?? 0);
$userTimezone = ($currentUser && isset($currentUser['timezone'])) ? (string)$currentUser['timezone'] : 'UTC';
$auth->releaseSessionLock();

if (connection_aborted()) {
    exit;
}

$userCurrency = ($currentUser && isset($currentUser['currency'])) ? (string)$currentUser['currency'] : 'USD';

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
    $userTimezone = (new DateTimeZone($userTimezone))->getName();
} catch (Exception $e) {
    $userTimezone = 'UTC';
}

@set_time_limit(60);

$todayInUserTz = Formatter::getTodayInTimezone($userTimezone);
$dateFrom = trim((string)($_GET['date_from'] ?? $todayInUserTz));
$dateTo = trim((string)($_GET['date_to'] ?? $todayInUserTz));

$statusFilterRaw = $_GET['status_filter'] ?? [];
if (!is_array($statusFilterRaw)) {
    $statusFilterRaw = $statusFilterRaw !== '' ? [$statusFilterRaw] : [];
}
$validStatuses = ['active', 'paused', 'archived'];
$allowedStatuses = array_values(array_intersect($statusFilterRaw, $validStatuses));
if (count($allowedStatuses) === 0 || count($allowedStatuses) === 3) {
    $allowedStatuses = null;
}

$campaignsPage = max(1, (int)($_GET['campaigns_page'] ?? 1));
$campaignsPerPage = DashboardStatsService::DEFAULT_PER_PAGE;

$cacheKey = StatsResponseCache::makeKey($userId, 'dashboard_campaigns', [
    $dateFrom,
    $dateTo,
    $userTimezone,
    $allowedStatuses,
    $campaignsPage,
    $campaignsPerPage,
    StatsResponseCache::clicksFreshnessToken($db),
]);

try {
    $payload = StatsResponseCache::remember($cacheKey, static function () use (
        $db,
        $dateFrom,
        $dateTo,
        $userTimezone,
        $allowedStatuses,
        $campaignsPage,
        $campaignsPerPage
    ) {
        return ReportingConcurrencyGuard::run(static function () use (
            $db,
            $dateFrom,
            $dateTo,
            $userTimezone,
            $allowedStatuses,
            $campaignsPage,
            $campaignsPerPage
        ) {
            $service = new DashboardStatsService($db);
            return $service->loadCampaignTable(
                $dateFrom,
                $dateTo,
                $userTimezone,
                $allowedStatuses,
                $campaignsPage,
                $campaignsPerPage,
                false
            );
        });
    }, StatsResponseCache::TTL_DASHBOARD);

    $campaignStats = $payload['campaignStats'] ?? [];
    $campaignStatsTotal = (int)($payload['campaignStatsTotal'] ?? 0);
    $campaignsPage = (int)($payload['campaignsPage'] ?? $campaignsPage);
    $campaignsPerPage = (int)($payload['campaignsPerPage'] ?? $campaignsPerPage);

    ob_start();
    include __DIR__ . '/../views/partials/dashboard-campaign-performance.php';
    $html = ob_get_clean();

    echo json_encode([
        'ok' => true,
        'html' => $html,
        'campaignStatsTotal' => $campaignStatsTotal,
        'campaignsPage' => $campaignsPage,
        'campaignsPerPage' => $campaignsPerPage,
    ]);
} catch (ReportingBusyException $e) {
    http_response_code(503);
    header('Retry-After: 2');
    echo json_encode([
        'ok' => false,
        'busy' => true,
        'error' => 'Server is busy running other reports. Wait a moment and try again.',
    ]);
} catch (Throwable $e) {
    error_log('api-dashboard-campaigns: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to load campaign performance']);
} finally {
    $db->close();
}
