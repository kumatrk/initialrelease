<?php

declare(strict_types=1);

/**
 * Campaign list performance stats (session-authenticated, lazy-loaded).
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/bootstrap_web_paths.php';

use SimpleKuma\Auth\Auth;
use SimpleKuma\Auth\Permission;
use SimpleKuma\Database\DatabaseCompatibility;
use SimpleKuma\Stats\CampaignListStatsService;
use SimpleKuma\Stats\ReportingBusyException;
use SimpleKuma\Stats\ReportingConcurrencyGuard;
use SimpleKuma\Stats\ReportingQueryCancel;
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
ReportingQueryCancel::arm($db);

$auth = new Auth($db);
$auth->requireAuth();

$permission = $auth->getPermission();
if ($permission && !$permission->hasPermission(Permission::PERM_CAMPAIGN_VIEW)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Permission denied']);
    exit;
}

$currentUser = $auth->getCurrentUser();
$userId = (int)($currentUser['id'] ?? 0);
$userTimezone = ($currentUser && isset($currentUser['timezone'])) ? (string)$currentUser['timezone'] : 'UTC';
$userCurrency = ($currentUser && isset($currentUser['currency'])) ? (string)$currentUser['currency'] : 'USD';
// List stats can scan a wide range — release session so navigation stays responsive.
$auth->releaseSessionLock();

if (connection_aborted()) {
    exit;
}

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

$campaignIdsRaw = $_GET['campaign_ids'] ?? [];
if (!is_array($campaignIdsRaw)) {
    $campaignIdsRaw = $campaignIdsRaw !== '' ? explode(',', (string)$campaignIdsRaw) : [];
}
$campaignIds = array_values(array_unique(array_filter(array_map('intval', $campaignIdsRaw), static fn (int $id): bool => $id > 0)));

if ($campaignIds === []) {
    echo json_encode(['ok' => true, 'stats' => new stdClass(), 'currency' => $userCurrency]);
    $db->close();
    exit;
}

// Cap to keep abuse / accidental huge requests bounded
if (count($campaignIds) > 200) {
    $campaignIds = array_slice($campaignIds, 0, 200);
}

$cacheKey = StatsResponseCache::makeKey($userId, 'campaign_list_stats', [
    $dateFrom,
    $dateTo,
    $userTimezone,
    $campaignIds,
    StatsResponseCache::clicksFreshnessToken($db),
]);

try {
    $stats = StatsResponseCache::remember($cacheKey, static function () use ($db, $campaignIds, $dateFrom, $dateTo, $userTimezone) {
        return ReportingConcurrencyGuard::run(static function () use ($db, $campaignIds, $dateFrom, $dateTo, $userTimezone) {
            $service = new CampaignListStatsService($db);
            return $service->loadStatsForCampaignIds($campaignIds, $dateFrom, $dateTo, $userTimezone);
        });
    }, StatsResponseCache::TTL_DASHBOARD);

    // JSON object keys must be strings
    $statsOut = [];
    foreach ($stats as $id => $row) {
        $statsOut[(string)$id] = $row;
    }

    echo json_encode([
        'ok' => true,
        'stats' => $statsOut,
        'currency' => $userCurrency,
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
    error_log('api-campaign-list-stats: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to load campaign stats']);
} finally {
    $db->close();
}
