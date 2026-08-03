<?php

declare(strict_types=1);

/**
 * Campaign Stats JSON API (session-authenticated UI endpoints).
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/bootstrap_web_paths.php';

use SimpleKuma\Auth\Auth;
use SimpleKuma\Auth\Permission;
use SimpleKuma\Database\DatabaseCompatibility;
use SimpleKuma\Entity\Campaign;
use SimpleKuma\Stats\CampaignStatsQueryFilters;
use SimpleKuma\Stats\CampaignStatsResponseCache;
use SimpleKuma\Stats\CampaignStatsV2Service;
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

// If the browser navigates away, stop PHP work ASAP (MySQL still needs statement timeout below).
ignore_user_abort(false);

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$db->query("SET time_zone = '+00:00'");
DatabaseCompatibility::applyReportingQueryTimeout($db, 20);

$auth = new Auth($db);
$auth->requireAuth();

$permission = $auth->getPermission();
if ($permission && !$permission->hasPermission(Permission::PERM_STATS_VIEW)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Permission denied']);
    exit;
}

$currentUser = $auth->getCurrentUser();
$userId = (int)($currentUser['id'] ?? 0);
$userTimezone = ($currentUser && isset($currentUser['timezone'])) ? (string)$currentUser['timezone'] : 'UTC';
// Long stats queries must not hold the session lock (blocks Campaigns / other tabs).
$auth->releaseSessionLock();

if (connection_aborted()) {
    exit;
}

@set_time_limit(25);

$action = trim((string)($_GET['action'] ?? 'summary'));
$campaignId = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : 0;

if ($campaignId < 1) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'campaign_id is required']);
    exit;
}

$campaignEntity = new Campaign($db);
if ($campaignEntity->getById($campaignId) === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Campaign not found']);
    exit;
}

$today = Formatter::getTodayInTimezone($userTimezone);
$dateFrom = trim((string)($_GET['date_from'] ?? $today));
$dateTo = trim((string)($_GET['date_to'] ?? $today));
$filters = CampaignStatsQueryFilters::fromRequest($_GET);

try {
    $service = new CampaignStatsV2Service($db);

    $cacheParts = [
        'campaign_id' => $campaignId,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'timezone' => $userTimezone,
        'action' => $action,
        'traffic_source_id' => $filters->trafficSourceId,
        'offer_id' => $filters->offerId,
        'landing_page_id' => $filters->landingPageId,
        'token_param' => $filters->tokenParam,
        'token_value' => $filters->tokenValue,
        // Match list/dashboard: invalidate as soon as a new click lands
        'clicks_freshness' => StatsResponseCache::clicksFreshnessToken($db),
    ];

    switch ($action) {
        case 'summary':
            $cacheKey = CampaignStatsResponseCache::makeKey($userId, 'summary', $cacheParts);
            $payload = CampaignStatsResponseCache::remember($cacheKey, static function () use ($service, $campaignId, $dateFrom, $dateTo, $userTimezone, $filters) {
                return ReportingConcurrencyGuard::run(
                    static fn () => $service->getSummary($campaignId, $dateFrom, $dateTo, $userTimezone, $filters)
                );
            }, StatsResponseCache::TTL_SUMMARY);
            echo json_encode(['ok' => true, 'data' => $payload]);
            break;

        case 'chart':
            $granularity = trim((string)($_GET['granularity'] ?? 'auto'));
            $cacheParts['granularity'] = $granularity;
            $cacheKey = CampaignStatsResponseCache::makeKey($userId, 'chart', $cacheParts);
            $payload = CampaignStatsResponseCache::remember($cacheKey, static function () use ($service, $campaignId, $dateFrom, $dateTo, $userTimezone, $granularity, $filters) {
                return ReportingConcurrencyGuard::run(
                    static fn () => $service->getChart($campaignId, $dateFrom, $dateTo, $userTimezone, $granularity, $filters)
                );
            }, StatsResponseCache::TTL_CHART);
            echo json_encode(['ok' => true, 'data' => $payload]);
            break;

        case 'dimensions':
            $cacheKey = CampaignStatsResponseCache::makeKey($userId, 'dimensions', [
                'campaign_id' => $campaignId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'timezone' => $userTimezone,
                'clicks_freshness' => StatsResponseCache::clicksFreshnessToken($db),
            ]);
            $payload = CampaignStatsResponseCache::remember($cacheKey, static function () use (
                $service,
                $campaignId,
                $dateFrom,
                $dateTo,
                $userTimezone
            ) {
                return $service->getDimensions($campaignId, $dateFrom, $dateTo, $userTimezone);
            });
            echo json_encode(['ok' => true, 'data' => $payload]);
            break;

        case 'meta':
            $cacheKey = CampaignStatsResponseCache::makeKey($userId, 'meta', [
                'campaign_id' => $campaignId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'timezone' => $userTimezone,
                'clicks_freshness' => StatsResponseCache::clicksFreshnessToken($db),
            ]);
            $payload = CampaignStatsResponseCache::remember($cacheKey, static function () use (
                $service,
                $campaignId,
                $dateFrom,
                $dateTo,
                $userTimezone
            ) {
                return $service->getCampaignMeta($campaignId, $dateFrom, $dateTo, $userTimezone);
            });
            echo json_encode(['ok' => true, 'data' => $payload]);
            break;

        case 'token_values':
            $tokenParam = trim((string)($_GET['token_param'] ?? ''));
            if ($tokenParam === '') {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'token_param is required']);
                break;
            }
            $cacheKey = CampaignStatsResponseCache::makeKey($userId, 'token_values', [
                'campaign_id' => $campaignId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'timezone' => $userTimezone,
                'token_param' => $tokenParam,
                'clicks_freshness' => StatsResponseCache::clicksFreshnessToken($db),
            ]);
            $payload = CampaignStatsResponseCache::remember($cacheKey, static function () use (
                $service,
                $campaignId,
                $dateFrom,
                $dateTo,
                $userTimezone,
                $tokenParam
            ) {
                return [
                    'token_param' => $tokenParam,
                    'values' => $service->getTokenFilterValues(
                        $campaignId,
                        $dateFrom,
                        $dateTo,
                        $userTimezone,
                        $tokenParam
                    ),
                ];
            });
            echo json_encode(['ok' => true, 'data' => $payload]);
            break;

        case 'breakdown':
            $dimensionsRaw = trim((string)($_GET['dimensions'] ?? ''));
            $dimensions = $dimensionsRaw !== '' ? array_map('trim', explode(',', $dimensionsRaw)) : [];
            $parentRaw = trim((string)($_GET['parent_path'] ?? ''));
            $parentPath = [];
            if ($parentRaw !== '') {
                $decoded = json_decode($parentRaw, true);
                if (is_array($decoded)) {
                    $parentPath = $decoded;
                }
            }
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = max(1, min(200, (int)($_GET['per_page'] ?? 25)));
            $sort = trim((string)($_GET['sort'] ?? 'clicks'));
            $order = trim((string)($_GET['order'] ?? 'desc'));

            $cacheParts['dimensions'] = $dimensions;
            $cacheParts['parent_path'] = $parentPath;
            $cacheParts['page'] = $page;
            $cacheParts['per_page'] = $perPage;
            $cacheParts['sort'] = $sort;
            $cacheParts['order'] = $order;

            $cacheKey = CampaignStatsResponseCache::makeKey($userId, 'breakdown', $cacheParts);
            $payload = CampaignStatsResponseCache::remember($cacheKey, static function () use (
                $service,
                $campaignId,
                $dateFrom,
                $dateTo,
                $userTimezone,
                $dimensions,
                $parentPath,
                $page,
                $perPage,
                $sort,
                $order,
                $filters
            ) {
                return ReportingConcurrencyGuard::run(static function () use (
                    $service,
                    $campaignId,
                    $dateFrom,
                    $dateTo,
                    $userTimezone,
                    $dimensions,
                    $parentPath,
                    $page,
                    $perPage,
                    $sort,
                    $order,
                    $filters
                ) {
                    return $service->getBreakdown(
                        $campaignId,
                        $dateFrom,
                        $dateTo,
                        $userTimezone,
                        $dimensions,
                        $parentPath,
                        $page,
                        $perPage,
                        $sort,
                        $order,
                        $filters
                    );
                });
            }, StatsResponseCache::TTL_BREAKDOWN);
            echo json_encode(['ok' => true, 'data' => $payload]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    }
} catch (ReportingBusyException $e) {
    http_response_code(503);
    header('Retry-After: 2');
    echo json_encode([
        'ok' => false,
        'busy' => true,
        'error' => 'Server is busy running other reports. Wait a moment and try again.',
    ]);
} catch (\InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (\mysqli_sql_exception $e) {
    error_log('Campaign Stats V2 SQL error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Couldn’t load this report. Try again or change your filters.']);
} catch (\RuntimeException $e) {
    $msg = $e->getMessage();
    // Never leak raw SQL / driver errors to the browser.
    if (preg_match('/sql_mode|only_full_group_by|SQLSTATE|mysqli|GROUP BY clause|syntax error/i', $msg)) {
        error_log('Campaign Stats V2 runtime SQL-like error: ' . $msg);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Couldn’t load this report. Try again or change your filters.']);
    } else {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => $msg]);
    }
} catch (\Throwable $e) {
    error_log('Campaign Stats V2 API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'An error occurred loading stats.']);
}
