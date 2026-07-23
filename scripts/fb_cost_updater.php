<?php
/**
 * Facebook Cost Updater Cron Script
 * 
 * This script runs hourly to fetch Facebook adset and ad spend data
 * from Facebook Insights API and store it in hourly cost tables.
 * 
 * Usage: php scripts/fb_cost_updater.php
 * Cron: 0 * * * * /usr/bin/php /path/to/scripts/fb_cost_updater.php >> /var/log/fb_cost_updater.log 2>&1
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

use SimpleKuma\Entity\FacebookMarketingIntegration;
use SimpleKuma\Entity\FacebookMarketingAdAccount;
use SimpleKuma\Facebook\FacebookTrafficSourceIdentifier;
use SimpleKuma\Facebook\FacebookClickExtractor;
use SimpleKuma\Facebook\FacebookInsightsClient;
use SimpleKuma\Facebook\FacebookApiCallTracker;
use SimpleKuma\Entity\FacebookAdsetCampaignMap;
use SimpleKuma\Http\ProxyHandler;
use SimpleKuma\Logger;

// CRITICAL: Add error handling at the very top to catch any fatal errors
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Initialize variables outside try block so they're available everywhere
$db = null;
$facebookMarketing = null;
$facebookMarketingAdAccount = null;
$trafficSourceIdentifier = null;
$clickExtractor = null;
$logger = null;

// Initialize database connection
try {
$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

if ($db->connect_error) {
    error_log("Facebook Cost Updater: Database connection failed: " . $db->connect_error);
    exit(1);
}

// Set timezone
date_default_timezone_set('UTC');

// Initialize services
$facebookMarketing = new FacebookMarketingIntegration($db);
$facebookMarketingAdAccount = new FacebookMarketingAdAccount($db);
$trafficSourceIdentifier = new FacebookTrafficSourceIdentifier($db);
$clickExtractor = new FacebookClickExtractor($db);
$logger = new Logger();
    $apiCallTracker = new FacebookApiCallTracker($db);
    $runStartedAt = date('Y-m-d H:i:s');
    $runStartedUnix = time();

// Log script start
$logger->logDetail("=== Facebook Cost Updater Cron Started ===", [
    'date' => date('Y-m-d'),
    'hour' => date('H:i:s'),
        'timezone' => date_default_timezone_get(),
        'php_version' => PHP_VERSION,
        'memory_limit' => ini_get('memory_limit'),
        'run_started_at' => $runStartedAt,
    ]);
} catch (\Throwable $e) {
    // Catch any fatal errors during initialization
    error_log("Facebook Cost Updater: Fatal error during initialization: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    error_log("Stack trace: " . $e->getTraceAsString());
    exit(1);
}

/**
 * Persist last-run diagnostics for Settings → API Cost Updates → View details.
 */
$persistLastRun = static function (
    FacebookApiCallTracker $tracker,
    string $startedAt,
    int $startedUnix,
    array $extra = []
): void {
    $finishedAt = date('Y-m-d H:i:s');
    $breakdown = $tracker->getPurposeBreakdownBetween($startedAt, $finishedAt);
    $totalCalls = 0;
    foreach ($breakdown as $row) {
        $totalCalls += (int)($row['count'] ?? 0);
    }
    $summary = array_merge([
        'started_at' => $startedAt,
        'finished_at' => $finishedAt,
        'duration_seconds' => max(0, time() - $startedUnix),
        'api_calls_total' => $totalCalls,
        'api_calls_by_purpose' => $breakdown,
        'status' => 'completed',
    ], $extra);
    $tracker->saveLastRunSummary($summary);
};

// Get Facebook traffic source IDs
$facebookSourceIds = $trafficSourceIdentifier->getFacebookSourceIds();

if (empty($facebookSourceIds)) {
    $logger->logDetail("No Facebook traffic sources found. Exiting.");
    $persistLastRun($apiCallTracker, $runStartedAt, $runStartedUnix, [
        'status' => 'skipped',
        'skip_reason' => 'No Facebook traffic sources found',
    ]);
    exit(0);
}

$logger->logDetail("Found Facebook traffic sources", ['count' => count($facebookSourceIds), 'ids' => $facebookSourceIds]);

// Get user's timezone from database (or use UTC as default)
$userTimezone = 'UTC';
$userTimezoneQuery = $db->query("SELECT timezone FROM users LIMIT 1");
if ($userTimezoneQuery && $userRow = $userTimezoneQuery->fetch_assoc()) {
    $userTimezone = $userRow['timezone'] ?? 'UTC';
    $logger->logDetail("Retrieved user timezone from database: " . $userTimezone);
} else {
    $logger->logDetail("No timezone found in database, using default: UTC");
}

// Normalize timezone abbreviations
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

// Validate timezone
try {
    $tz = new DateTimeZone($userTimezone);
    $userTimezone = $tz->getName();
} catch (Exception $e) {
    $userTimezone = 'UTC';
}

// Get "today" in user's timezone (this is what the dashboard shows)
$userTz = new DateTimeZone($userTimezone);
$utcTzObj = new DateTimeZone('UTC');
$nowInUserTz = new DateTime('now', $userTz);
$todayInUserTz = $nowInUserTz->format('Y-m-d');

// User "today" spans one or two UTC calendar dates (e.g. Pacific evening is on the next UTC date).
$userTodayStart = new DateTime($todayInUserTz . ' 00:00:00', $userTz);
$userTodayStart->setTimezone($utcTzObj);
$utcStartDate = $userTodayStart->format('Y-m-d');
$utcStartHour = (int)$userTodayStart->format('G');

$userTodayEnd = new DateTime($todayInUserTz . ' 23:59:59', $userTz);
$userTodayEnd->setTimezone($utcTzObj);
$utcEndDate = $userTodayEnd->format('Y-m-d');
$utcEndHour = (int)$userTodayEnd->format('G');

// Current date and hour (use UTC to match clicks table which stores UTC timestamps)
date_default_timezone_set('UTC');
$today = date('Y-m-d');
$currentHour = (int)date('G');

// Process every UTC date that contains hours from the user's "today".
$datesToProcess = ($utcStartDate === $utcEndDate) ? [$utcStartDate] : [$utcStartDate, $utcEndDate];

$GLOBALS['utcStartHour'] = $utcStartHour;
$GLOBALS['utcStartDate'] = $utcStartDate;
$GLOBALS['utcEndDate'] = $utcEndDate;
$GLOBALS['utcEndHour'] = $utcEndHour;

$logger->logDetail("Processing costs for", [
    'dates' => $datesToProcess,
    'user_today' => $todayInUserTz,
    'user_timezone' => $userTimezone,
    'utc_start_date' => $utcStartDate,
    'utc_start_hour' => $utcStartHour,
    'utc_end_date' => $utcEndDate,
    'utc_end_hour' => $utcEndHour,
    'utc_today' => $today,
    'current_hour' => $currentHour,
    'timezone' => 'UTC',
    'note' => 'DB rows use UTC date/hour; Meta Insights queried for user calendar today (dashboard date)',
]);

// Ad accounts: active FB campaigns with ad account linked and (Meta campaign mapped OR recent clicks)
$placeholders = implode(',', array_fill(0, count($facebookSourceIds), '?'));
$hasMetaCampaignColumn = false;
$colCheck = $db->query("SHOW COLUMNS FROM campaigns LIKE 'facebook_marketing_campaign_id'");
if ($colCheck && $colCheck->num_rows > 0) {
    $hasMetaCampaignColumn = true;
}

if ($hasMetaCampaignColumn) {
    $adAccountQuery = "
        SELECT DISTINCT camp.facebook_marketing_ad_account_id
        FROM campaigns camp
        WHERE camp.traffic_source_id IN ($placeholders)
            AND camp.facebook_marketing_ad_account_id IS NOT NULL
            AND camp.status = 'active'
            AND (
                camp.facebook_marketing_campaign_id IS NOT NULL
                OR EXISTS (
                    SELECT 1 FROM clicks c
                    WHERE c.campaign_id = camp.id
                      AND c.ts >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                )
            )
        ORDER BY camp.facebook_marketing_ad_account_id ASC
    ";
} else {
    $adAccountQuery = "
        SELECT DISTINCT camp.facebook_marketing_ad_account_id
        FROM clicks c
        INNER JOIN campaigns camp ON c.campaign_id = camp.id
        WHERE camp.traffic_source_id IN ($placeholders)
            AND camp.facebook_marketing_ad_account_id IS NOT NULL
            AND camp.status = 'active'
            AND c.ts >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY camp.facebook_marketing_ad_account_id ASC
    ";
}

$stmt = $db->prepare($adAccountQuery);
$bindTypes = str_repeat('i', count($facebookSourceIds));
$stmt->bind_param($bindTypes, ...$facebookSourceIds);
$stmt->execute();
$result = $stmt->get_result();

$adAccountIds = [];
while ($row = $result->fetch_assoc()) {
    if (!empty($row['facebook_marketing_ad_account_id'])) {
        $adAccountIds[] = (int)$row['facebook_marketing_ad_account_id'];
    }
}

if (empty($adAccountIds)) {
    $logger->logDetail("No active Facebook campaigns with linked ad accounts (and clicks or Meta campaign mapping). Exiting.");
    $persistLastRun($apiCallTracker, $runStartedAt, $runStartedUnix, [
        'status' => 'skipped',
        'skip_reason' => 'No active Facebook campaigns with linked ad accounts',
        'user_timezone' => $userTimezone ?? null,
        'user_today' => $todayInUserTz ?? null,
        'utc_dates_processed' => $datesToProcess ?? [],
    ]);
    exit(0);
}

$logger->logDetail("Found ad accounts to process", [
    'count' => count($adAccountIds),
    'ad_account_ids' => $adAccountIds
]);

// Statistics tracking
$stats = [
    'ad_accounts_processed' => 0,
    'adsets_processed' => 0,
    'ads_processed' => 0,
    'adsets_success' => 0,
    'adsets_failed' => 0,
    'ads_success' => 0,
    'ads_failed' => 0,
    'adset_fallback_attempts' => 0,
    'adset_fallback_capped' => 0,
    'errors' => []
];

// Process each ad account that has campaigns with clicks
foreach ($adAccountIds as $adAccountInternalId) {
    $stats['ad_accounts_processed']++;
    
    // Get ad account details (includes integration info)
    $adAccount = $facebookMarketingAdAccount->getById($adAccountInternalId);
    
    if (!$adAccount) {
        $logger->logDetail("Ad account not found, skipping", ['ad_account_id' => $adAccountInternalId]);
        continue;
    }
    
    // Get the integration to get access token
    $integrationId = (int)$adAccount['facebook_marketing_integration_id'];
    $fullIntegration = $facebookMarketing->getById($integrationId);
    
    if (!$fullIntegration || $fullIntegration['status'] !== 'active') {
        $logger->logDetail("Integration not found or inactive, skipping", [
            'ad_account_id' => $adAccountInternalId,
            'integration_id' => $integrationId
        ]);
        continue;
    }
    
    $accessToken = $fullIntegration['access_token'];
    $apiAdAccountId = $adAccount['ad_account_id']; // Facebook ad account ID (e.g., act_12345)
    $adAccountName = $adAccount['ad_account_name'];
    $integrationName = $adAccount['integration_name'] ?? $fullIntegration['name'];
    
    // Get ad account timezone (Facebook API uses ad account timezone to interpret dates)
    // Default to UTC if not set
    $adAccountTimezone = $adAccount['timezone'] ?? 'UTC';
    if (empty($adAccountTimezone)) {
        $adAccountTimezone = 'UTC';
    }
    
    $logger->logDetail("Processing ad account", [
        'ad_account_name' => $adAccountName,
        'ad_account_internal_id' => $adAccountInternalId,
        'facebook_ad_account_id' => $apiAdAccountId,
        'integration_name' => $integrationName,
        'integration_id' => $integrationId,
        'ad_account_timezone' => $adAccountTimezone
    ]);
    
    // Get proxy configuration if enabled
    $proxyConfig = ProxyHandler::getProxyConfig($fullIntegration);
    
    if (!empty($proxyConfig['use_proxy'])) {
        $logger->logDetail("Using proxy", [
            'ad_account_name' => $adAccountName,
            'proxy_host' => $proxyConfig['proxy_host'],
            'proxy_port' => $proxyConfig['proxy_port'],
            'proxy_type' => $proxyConfig['proxy_type']
        ]);
    }
    
    // Initialize Facebook API client with proxy config, API call tracker, and logger
    $apiClient = new FacebookInsightsClient($accessToken, $proxyConfig, $apiCallTracker, $integrationId, $logger);
    $apiClient->setAdAccountId($apiAdAccountId);

    $metaCampaignIdsForAccount = [];
    $metaCampaignIdByKumaCampaign = [];
    $mappedMetaCampaignTotal = 0;
    $skippedInactiveMetaCampaigns = 0;
    if ($hasMetaCampaignColumn) {
        $metaMapStmt = $db->prepare("
            SELECT camp.id AS kuma_campaign_id, fmc.meta_campaign_id, fmc.effective_status
            FROM campaigns camp
            INNER JOIN facebook_marketing_campaigns fmc ON camp.facebook_marketing_campaign_id = fmc.id
            WHERE camp.facebook_marketing_ad_account_id = ?
              AND camp.status = 'active'
              AND camp.traffic_source_id IN ($placeholders)
        ");
        $metaBindTypes = 'i' . str_repeat('i', count($facebookSourceIds));
        $metaBindParams = array_merge([$adAccountInternalId], $facebookSourceIds);
        $metaMapStmt->bind_param($metaBindTypes, ...$metaBindParams);
        $metaMapStmt->execute();
        $metaMapResult = $metaMapStmt->get_result();
        $seenMeta = [];
        while ($metaRow = $metaMapResult->fetch_assoc()) {
            $metaId = (string)($metaRow['meta_campaign_id'] ?? '');
            $kumaId = (int)($metaRow['kuma_campaign_id'] ?? 0);
            if ($metaId === '' || $kumaId <= 0) {
                continue;
            }
            $mappedMetaCampaignTotal++;
            $metaEffectiveStatus = strtoupper((string)($metaRow['effective_status'] ?? ''));
            // Only include Meta-live ACTIVE campaigns in Insights scope; paused/archived Meta
            // campaigns still linked to ACTIVE tracker campaigns must not drive API traffic.
            if ($metaEffectiveStatus !== 'ACTIVE') {
                $skippedInactiveMetaCampaigns++;
                $logger->logDetail('Skipping Meta campaign not ACTIVE for Insights filter', [
                    'ad_account_name' => $adAccountName,
                    'meta_campaign_id' => $metaId,
                    'kuma_campaign_id' => $kumaId,
                    'effective_status' => $metaRow['effective_status'] ?? null,
                ]);
                continue;
            }
            if (isset($seenMeta[$metaId]) && $seenMeta[$metaId] !== $kumaId) {
                $logger->logDetail('WARNING: Multiple Kuma campaigns map to same Meta campaign', [
                    'ad_account_name' => $adAccountName,
                    'meta_campaign_id' => $metaId,
                    'kuma_campaign_ids' => [$seenMeta[$metaId], $kumaId],
                ]);
            }
            $seenMeta[$metaId] = $kumaId;
            $metaCampaignIdsForAccount[$metaId] = $metaId;
            $metaCampaignIdByKumaCampaign[$kumaId] = $metaId;
        }
        $metaMapStmt->close();
        $metaCampaignIdsForAccount = array_values($metaCampaignIdsForAccount);
        if ($skippedInactiveMetaCampaigns > 0) {
            $logger->logDetail('Filtered non-ACTIVE Meta campaigns from Insights scope', [
                'ad_account_name' => $adAccountName,
                'mapped_total' => $mappedMetaCampaignTotal,
                'active_meta_included' => count($metaCampaignIdsForAccount),
                'skipped_inactive_meta' => $skippedInactiveMetaCampaigns,
            ]);
        }
    }
    $useScopedMetaCampaignFetch = !empty($metaCampaignIdsForAccount);
    // Tracker campaigns map to Meta, but none are Meta-ACTIVE — do not fall back to account-wide Insights.
    $skipInsightsBecauseMetaPaused = $mappedMetaCampaignTotal > 0 && empty($metaCampaignIdsForAccount);
    
    // Extract unique adset and ad IDs from clicks for campaigns linked to this ad account
    // This batches all campaigns for this ad account together
    // CRITICAL FIX: Only extract adsets with clicks "today" (not 7-day window)
    // This aligns with what Meta API will return for "today", eliminating fallback calls
    $utcTz = new DateTimeZone('UTC');
    $userTz = new DateTimeZone($userTimezone);
    
    // Calculate UTC date range for "today" in user timezone
    $todayStartInUserTz = new DateTime($todayInUserTz . ' 00:00:00', $userTz);
    $todayStartInUserTz->setTimezone($utcTz);
    $utcDateFrom = $todayStartInUserTz->format('Y-m-d H:i:s');
    
    $todayEndInUserTz = new DateTime($todayInUserTz . ' 23:59:59', $userTz);
    $todayEndInUserTz->setTimezone($utcTz);
    $utcDateTo = $todayEndInUserTz->format('Y-m-d H:i:s');
    
    // Use 7-day window for click extraction so adsets with recent clicks (e.g. Solar) are always
    // included even if "today" boundary or timezone edge excludes them. We still only write cost for today.
    $clickWindowStart = clone $todayStartInUserTz;
    $clickWindowStart->modify('-7 days');
    $clickWindowStart->setTimezone($utcTz);
    $utcDateFromClicks = $clickWindowStart->format('Y-m-d H:i:s');
    
    $logger->logDetail("Extracting adset and ad IDs from clicks", [
        'ad_account_name' => $adAccountName,
        'facebook_source_ids' => $facebookSourceIds,
        'facebook_marketing_ad_account_id' => $adAccountInternalId,
        'user_today' => $todayInUserTz,
        'utc_date_from_clicks' => $utcDateFromClicks,
        'utc_date_to_clicks' => $utcDateTo,
        'note' => 'Using 7-day window so adsets with recent clicks (e.g. Solar) are included; cost is still written for today only'
    ]);
    
    $allAdsets = $clickExtractor->extractUniqueAdsets($facebookSourceIds, $adAccountInternalId, null, $utcDateFromClicks, $utcDateTo);
    $allAds = $clickExtractor->extractUniqueAds($facebookSourceIds, $adAccountInternalId, null, $utcDateFromClicks, $utcDateTo);
    
    $logger->logDetail("Extracted from clicks", [
        'ad_account_name' => $adAccountName,
        'total_adsets_found' => count($allAdsets),
        'total_ads_found' => count($allAds),
        'meta_campaign_ids_mapped' => count($metaCampaignIdsForAccount),
        'scoped_fetch' => $useScopedMetaCampaignFetch,
    ]);

    if ($useScopedMetaCampaignFetch && !empty($metaCampaignIdByKumaCampaign)) {
        $clickCampStmt = $db->prepare("
            SELECT DISTINCT camp.id AS kuma_campaign_id,
                JSON_UNQUOTE(JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.campaign_id')) AS click_campaign_id
            FROM clicks c
            INNER JOIN campaigns camp ON c.campaign_id = camp.id
            WHERE camp.facebook_marketing_ad_account_id = ?
              AND camp.traffic_source_id IN ($placeholders)
              AND c.ts >= ?
              AND c.ts <= ?
              AND JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.campaign_id') IS NOT NULL
              AND JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.campaign_id') != ''
              AND JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.campaign_id') != 'null'
        ");
        $clickCampTypes = 'i' . str_repeat('i', count($facebookSourceIds)) . 'ss';
        $clickCampParams = array_merge([$adAccountInternalId], $facebookSourceIds, [$utcDateFromClicks, $utcDateTo]);
        $clickCampStmt->bind_param($clickCampTypes, ...$clickCampParams);
        $clickCampStmt->execute();
        $clickCampRes = $clickCampStmt->get_result();
        while ($clickCampRow = $clickCampRes->fetch_assoc()) {
            $kumaId = (int)($clickCampRow['kuma_campaign_id'] ?? 0);
            $clickCampaignId = (string)($clickCampRow['click_campaign_id'] ?? '');
            if ($kumaId <= 0 || $clickCampaignId === '' || !ctype_digit($clickCampaignId)) {
                continue;
            }
            $expected = $metaCampaignIdByKumaCampaign[$kumaId] ?? null;
            if ($expected !== null && $expected !== $clickCampaignId) {
                $logger->logDetail('WARNING: Click campaign_id token does not match assigned Meta campaign', [
                    'ad_account_name' => $adAccountName,
                    'kuma_campaign_id' => $kumaId,
                    'assigned_meta_campaign_id' => $expected,
                    'click_campaign_id' => $clickCampaignId,
                ]);
            }
        }
        $clickCampStmt->close();
    }
    
    // Build lookup maps (already filtered by ad account at database level)
    // Note: Validation counts are logged by FacebookClickExtractor internally via error_log()
    $adsetLookup = [];
    $invalidAdsetIds = [];
    foreach ($allAdsets as $adsetData) {
            $adsetId = isset($adsetData['adset_id']) ? (string)$adsetData['adset_id'] : '';
            if ($adsetId !== '') {
                // Double-check validation: ensure only numeric IDs make it through
                if (ctype_digit($adsetId) && strpos($adsetId, '{{') === false && strpos($adsetId, '{ts:') === false) {
                $adsetLookup[$adsetId] = true;
                } else {
                    $invalidAdsetIds[] = $adsetId;
                }
        }
    }
    
    $adLookup = [];
    $invalidAdIds = [];
    foreach ($allAds as $adData) {
            $adId = isset($adData['ad_id']) ? (string)$adData['ad_id'] : '';
            if ($adId !== '') {
                // Double-check validation: ensure only numeric IDs make it through
                if (ctype_digit($adId) && strpos($adId, '{{') === false && strpos($adId, '{ts:') === false) {
                $adLookup[$adId] = $adData; // Store full data for adset_id
                } else {
                    $invalidAdIds[] = $adId;
                }
            }
    }
    
    // Log any invalid IDs that somehow made it through validation
    if (!empty($invalidAdsetIds) || !empty($invalidAdIds)) {
        $logger->logDetail("WARNING: Invalid IDs filtered out after extraction", [
            'ad_account_name' => $adAccountName,
            'invalid_adset_ids_count' => count($invalidAdsetIds),
            'invalid_adset_ids' => array_slice($invalidAdsetIds, 0, 5), // First 5 samples
            'invalid_ad_ids_count' => count($invalidAdIds),
            'invalid_ad_ids' => array_slice($invalidAdIds, 0, 5), // First 5 samples
            'note' => 'These IDs should have been filtered by FacebookClickExtractor validation'
        ]);
    }
    
    // Recovery: If no adsets but valid ads exist, try to recover adsets from ads
    if (empty($adsetLookup) && !empty($adLookup)) {
        // Filter to only numeric ad IDs
        $validAdIds = array_filter(array_keys($adLookup), function($id) {
            return ctype_digit((string)$id);
        });
        
        if (!empty($validAdIds)) {
            $logger->logDetail("Attempting adset recovery from ad IDs", [
                'ad_account_name' => $adAccountName,
                'valid_ad_ids_count' => count($validAdIds),
                'sample_ad_ids' => array_slice($validAdIds, 0, 5)
            ]);
            
            try {
                $recoveredAdsets = $apiClient->fetchAdDetailsBatch($validAdIds, 50);
                
                if (!empty($recoveredAdsets)) {
                    // Merge recovered adsets into adsetLookup
                    foreach ($recoveredAdsets as $adId => $adsetId) {
                        $adsetLookup[$adsetId] = true;
                    }
                    
                    $logger->logDetail("Adset recovery successful", [
                        'ad_account_name' => $adAccountName,
                        'recovered_adsets_count' => count($recoveredAdsets),
                        'recovered_adset_ids' => array_values($recoveredAdsets)
                    ]);
                } else {
                    $logger->logDetail("Adset recovery returned no results", [
                        'ad_account_name' => $adAccountName,
                        'note' => 'API may not have adset_id data for these ads'
                    ]);
                }
            } catch (\Exception $e) {
                $logger->logDetail("Adset recovery failed", [
                    'ad_account_name' => $adAccountName,
                    'error' => $e->getMessage()
                ]);
            }
            }
    }
    
    $logger->logDetail("Prepared for API calls", [
        'ad_account_name' => $adAccountName,
        'adsets_to_query' => count($adsetLookup),
        'ads_to_query' => count($adLookup),
        'adset_ids' => array_keys($adsetLookup),
        'ad_ids' => array_keys($adLookup)
    ]);
    
    // SPEND-ONLY FIX: Do NOT skip when adsetLookup is empty. We still call the account-level API
    // and merge in adsets with spend from the API response, so spend-only adsets (e.g. gutters with 0 clicks)
    // get their cost written to adset_hourly_costs and appear in the dashboard total.
    
        // Process current hour and backfill any missed hours for today only
        // Note: Facebook API only provides daily totals, not hourly breakdowns
        // We check which hours already have records and only process missing ones
        // This allows the script to catch up if it missed 1-2 hours, but only for today
        foreach ($datesToProcess as $processDate) {
            $isToday = true; // Only processing today now
            
            // CRITICAL: $processDate is UTC "today" (where clicks are stored)
            // We save costs to this date so they match clicks: DATE(c2.ts) = cost.date
            $utcTz = new DateTimeZone('UTC');
            $adAccountTz = new DateTimeZone($adAccountTimezone);
            
            // CRITICAL: Save costs to UTC "today" (where clicks are actually stored)
            $utcDateForThisDate = $processDate; // UTC "today" - this is where clicks are stored
            
            // Query Meta for the user's calendar "today" (matches dashboard), NOT ad-account midnight.
            // Meta interprets the date string in the ad account timezone, but using the user's date
            // prevents spend from resetting early when ad account TZ is ahead (e.g. Eastern vs Pacific).
            $adAccountTzObj = new DateTimeZone($adAccountTimezone);
            $nowInAdAccountTz = new DateTime('now', $adAccountTzObj);
            $todayInAdAccountTz = $nowInAdAccountTz->format('Y-m-d');
            $processDateForApi = $todayInUserTz;
            
            // CRITICAL: DO NOT clear costs by date - this wipes out yesterday's costs at midnight
            // At midnight PST (08:00 UTC), Dec 29 UTC contains:
            //   - Hours 0-7: Clicks from Dec 28 PST (yesterday)
            //   - Hours 8+: Clicks from Dec 29 PST (today)
            // Clearing Dec 29 UTC would wipe out yesterday's costs!
            // We use UPSERT (ON DUPLICATE KEY UPDATE) which updates existing records without clearing
            // This preserves yesterday's costs while updating today's costs
            $logger->logDetail("Using UPSERT to update costs without clearing (preserves yesterday's data)", [
                'ad_account_name' => $adAccountName,
                'utc_date_for_db' => $utcDateForThisDate,
                'user_today' => $todayInUserTz,
                'ad_account_today' => $todayInAdAccountTz,
                'utc_today' => $today,
                'date_for_api' => $processDateForApi,
                'ad_account_timezone' => $adAccountTimezone,
                'integration_id' => $integrationId,
                'note' => 'UPSERT updates existing records without clearing. API query uses user calendar today (dashboard date).'
            ]);

            // Max UTC hour to backfill on this processDate (full day if date is in the past).
            if ($processDate < $today) {
                $maxHourForDate = 23;
            } elseif ($processDate > $today) {
                $maxHourForDate = -1;
            } else {
                $maxHourForDate = $currentHour;
            }

            if ($maxHourForDate < 0) {
                $logger->logDetail('Skipping future UTC date for user today', [
                    'ad_account_name' => $adAccountName,
                    'utc_date_for_db' => $utcDateForThisDate,
                    'user_today' => $todayInUserTz,
                ]);
                continue;
            }

            $hoursToProcess = getHoursToProcess($db, $processDate, $maxHourForDate, $adsetLookup, $adLookup, $integrationId, $logger);
            $hoursToProcess = filterHoursForUserToday(
                $hoursToProcess,
                $processDate,
                $utcStartDate,
                $utcStartHour,
                $utcEndDate,
                $utcEndHour,
                $adAccountName,
                $todayInUserTz,
                $logger
            );

            $logger->logDetail("Hours to process for backfill (after user-timezone filter)", [
                'ad_account_name' => $adAccountName,
                'utc_date_for_db' => $utcDateForThisDate,
                'user_today' => $todayInUserTz,
                'date_for_api' => $processDateForApi,
                'max_hour_for_date' => $maxHourForDate,
                'utc_start_date' => $utcStartDate,
                'utc_start_hour' => $utcStartHour,
                'utc_end_date' => $utcEndDate,
                'utc_end_hour' => $utcEndHour,
                'hours_to_process' => $hoursToProcess,
                'total_hours_needed' => count($hoursToProcess),
                'note' => 'Only hours that fall within the user calendar day are processed',
            ]);
        
        // Convert UTC date to ad account timezone for API query
        // Facebook API interprets dates based on the ad account's timezone
        // CRITICAL: We need to save costs with the UTC date that matches when clicks actually occurred
        // CRITICAL FIX: Query Facebook API for the user's "today" date, not the UTC "today" converted to ad account timezone
        // This ensures we get costs for the day the user sees in the dashboard
        // NOTE: $processDateForApi and $utcDateForThisDate are already calculated above
        try {
            // $processDateForApi is already calculated above (ad account timezone date for API query)
            // $utcDateForThisDate is already set to $processDate (UTC date for user's "today")
            
            // Also get the end of this date in ad account timezone, converted to UTC
            $dateEndInAdAccountTz = new DateTime($processDateForApi . ' 23:59:59', $adAccountTz);
            $dateEndInUtc = clone $dateEndInAdAccountTz;
            $dateEndInUtc->setTimezone($utcTz);
            $utcDateForThisDateEnd = $dateEndInUtc->format('Y-m-d');
            
            // The UTC date range for this ad account date spans from utcDateForThisDate to utcDateForThisDateEnd
            // Most clicks will have DATE(ts) = utcDateForThisDate, but some might be utcDateForThisDateEnd
            // Use the UTC date that corresponds to the start of this date in ad account timezone
            // This ensures costs are saved with the UTC date that matches most clicks
            $processDateForDb = $utcDateForThisDate;
            
            // SIMPLIFIED: Always query for just "today" in ad account timezone (single date, no range)
            // This eliminates date filtering complexity and ensures reliable results
            // Each ad account gets "today" in their timezone → stored as UTC → displayed in user timezone
            $apiQueryDate = $processDateForApi; // Today in ad account timezone
            
            $logger->logDetail("Timezone conversion for API query", [
                'ad_account_name' => $adAccountName,
                'original_utc_date' => $processDate,
                'user_today' => $todayInUserTz,
                'user_timezone' => $userTimezone,
                'ad_account_today' => $todayInAdAccountTz,
                'ad_account_tz_date' => $processDateForApi,
                'ad_account_timezone' => $adAccountTimezone,
                'utc_date_for_db' => $processDateForDb,
                'api_query_date' => $apiQueryDate,
                'note' => 'API query uses user calendar today; Meta interprets date in ad account timezone. Stored as UTC to match clicks.',
            ]);
        } catch (Exception $e) {
            // Fallback to UTC date if timezone conversion fails
            $processDateForApi = $processDate;
            $processDateForDb = $processDate;
            $logger->logDetail("Timezone conversion failed, using UTC date", [
                'ad_account_name' => $adAccountName,
                'error' => $e->getMessage(),
                'date' => $processDate
            ]);
        }
        
        $logger->logDetail("Processing costs for date", [
            'ad_account_name' => $adAccountName,
            'date_utc' => $processDate,
            'date_ad_account_tz' => $processDateForApi,
            'ad_account_timezone' => $adAccountTimezone,
            'is_today' => $isToday,
            'current_hour_utc' => $currentHour,
            'hours_to_process' => $hoursToProcess
        ]);
        
        // Fetch account-level adset insights (1 API call for all adsets)
        // Use ad account timezone date for API query
        // CRITICAL: Initialize accountAdsetSpend to empty array so foreach always runs (matches test script)
        $accountAdsetSpend = [];

        if ($skipInsightsBecauseMetaPaused) {
            $logger->logDetail('Skipping Insights — mapped Meta campaigns are not ACTIVE', [
                'integration_name' => $adAccountName,
                'mapped_meta_total' => $mappedMetaCampaignTotal,
                'skipped_inactive_meta' => $skippedInactiveMetaCampaigns,
                'note' => 'Avoids account-wide Insights when tracker ACTIVE campaigns map only to paused/archived Meta campaigns',
            ]);
        } else {
        try {
            $logger->logDetail("Fetching account-level adset insights from Facebook API", [
                'integration_name' => $adAccountName,
                'api_ad_account_id' => $apiAdAccountId,
                'date_utc' => $processDate,
                'date_for_api' => $processDateForApi,
                'ad_account_timezone' => $adAccountTimezone,
                'note' => 'Single-date query for user calendar today (dashboard date).'
            ]);
            
            // SIMPLIFIED: Always query for just "today" in ad account timezone (single date, no range)
            // This eliminates date filtering complexity and ensures reliable results
            // Pass null for sinceDate and untilDate to use single-date query
            $accountAdsetSpend = $apiClient->fetchAccountAdsetSpend(
                $apiAdAccountId,
                $processDateForApi,
                null,
                null,
                $useScopedMetaCampaignFetch ? $metaCampaignIdsForAccount : null
            );

            $adsetCampaignMap = new FacebookAdsetCampaignMap($db);
            if ($adsetCampaignMap->tableExists()) {
                $mapRows = [];
                foreach ($apiClient->getLastAdsetInsights() as $adsetId => $insight) {
                    if (!empty($insight['campaign_id'])) {
                        $mapRows[(string)$adsetId] = (string)$insight['campaign_id'];
                    }
                }
                $adsetCampaignMap->upsertBatch($adAccountInternalId, $mapRows);
            }
        
        $logger->logDetail("Retrieved adset insights from API", [
            'integration_name' => $adAccountName,
            'total_adsets_from_api' => count($accountAdsetSpend),
            'scoped_by_meta_campaign' => $useScopedMetaCampaignFetch,
            'meta_campaign_filter_count' => count($metaCampaignIdsForAccount),
            'adset_ids_from_api' => array_slice(array_keys($accountAdsetSpend), 0, 20), // First 20 only
            'total_adset_ids' => count($accountAdsetSpend),
            'sample_spend_values' => array_slice($accountAdsetSpend, 0, 5, true) // First 5 with spend values
        ]);

            // Empty scoped result usually means Active Meta campaigns have no ACTIVE adsets /
            // no spend for the date. Do NOT fall back to account-wide Insights (wastes API calls
            // and can pull spend for unrelated paused campaigns on the same ad account).
            if ($useScopedMetaCampaignFetch && empty($accountAdsetSpend)) {
                $logger->logDetail('Scoped Meta campaign fetch returned no adsets — not falling back to account-wide Insights', [
                    'integration_name' => $adAccountName,
                    'meta_campaign_filter_count' => count($metaCampaignIdsForAccount),
                ]);
            }
        } catch (\Exception $e) {
            // On hard API failure of a scoped call, retry account-wide once so click-linked
            // adsets can still get spend via direct fallbacks when the filter request itself fails.
            if ($useScopedMetaCampaignFetch) {
                try {
                    $logger->logDetail('Scoped Meta campaign Insights failed — retrying account-wide fetch', [
                        'integration_name' => $adAccountName,
                        'error' => $e->getMessage(),
                    ]);
                    $accountAdsetSpend = $apiClient->fetchAccountAdsetSpend(
                        $apiAdAccountId,
                        $processDateForApi,
                        null,
                        null,
                        null
                    );
                    $useScopedMetaCampaignFetch = false;
                } catch (\Exception $fallbackEx) {
                    $logger->logDetail("Account-wide fallback also failed, will use direct queries", [
                        'integration_name' => $adAccountName,
                        'error' => $fallbackEx->getMessage(),
                    ]);
                    $accountAdsetSpend = [];
                }
            } else {
                $logger->logDetail("Exception during account-level API call, will use direct queries", [
                    'integration_name' => $adAccountName,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'note' => 'Continuing with direct queries for each adset'
                ]);
                $accountAdsetSpend = [];
            }
        }
        } // end else (!$skipInsightsBecauseMetaPaused)
        
        // SPEND-ONLY FIX: Merge adsets from clicks with adsets from account-level API (spend >= 0).
        // Include spend >= 0 (not just > 0) so we process and overwrite with 0 when API returns 0 (fixes stale cost e.g. Scale Bid Cap).
        // Also include adsets that have existing rows in adset_hourly_costs for today so we can zero them out when API returns 0.
        $adsetIdsFromClicks = array_keys($adsetLookup);
        $adsetIdsFromApiWithSpend = [];
        foreach ($accountAdsetSpend as $aid => $spend) {
            if ($spend >= 0 && ctype_digit((string)$aid) && strpos((string)$aid, '{{') === false && strpos((string)$aid, '{ts:') === false) {
                $adsetIdsFromApiWithSpend[] = (string)$aid;
            }
        }
        // Include adsets that have existing cost rows for today so we can overwrite stale cost with 0 when API returns 0
        $adsetIdsWithExistingCostRows = [];
        $existingCostRowsQuery = $db->prepare("SELECT DISTINCT adset_id FROM adset_hourly_costs WHERE ad_account_id = ? AND date = ?");
        $existingCostRowsQuery->bind_param('is', $integrationId, $processDate);
        $existingCostRowsQuery->execute();
        $existingCostRowsResult = $existingCostRowsQuery->get_result();
        while ($row = $existingCostRowsResult->fetch_assoc()) {
            $aid = (string)($row['adset_id'] ?? '');
            if ($aid !== '' && ctype_digit($aid) && strpos($aid, '{{') === false && strpos($aid, '{ts:') === false) {
                $adsetIdsWithExistingCostRows[] = $aid;
            }
        }
        if ($useScopedMetaCampaignFetch) {
            $adsetIdsFromApiWithSpendFiltered = $adsetIdsFromApiWithSpend;
            $adsetIdsWithExistingCostRowsFiltered = $adsetIdsWithExistingCostRows;
            $logger->logDetail('Using scoped Meta campaign fetch — processing all adsets from API response', [
                'ad_account_name' => $adAccountName,
                'adsets_from_api' => count($adsetIdsFromApiWithSpendFiltered),
            ]);
        } else {
            $adsetIdsFromApiWithSpendFiltered = array_values(array_intersect($adsetIdsFromApiWithSpend, $adsetIdsFromClicks));
            $adsetIdsWithExistingCostRowsFiltered = array_values(array_intersect($adsetIdsWithExistingCostRows, $adsetIdsFromClicks));
            $excludedFromApi = count($adsetIdsFromApiWithSpend) - count($adsetIdsFromApiWithSpendFiltered);
            $excludedFromExisting = count($adsetIdsWithExistingCostRows) - count($adsetIdsWithExistingCostRowsFiltered);
            if ($excludedFromApi > 0 || $excludedFromExisting > 0) {
                $logger->logDetail("Excluded adsets from paused/archived campaigns (only process active campaigns)", [
                    'ad_account_name' => $adAccountName,
                    'excluded_from_api' => $excludedFromApi,
                    'excluded_from_existing_cost_rows' => $excludedFromExisting,
                    'note' => 'Fallback path: intersect API adsets with click adsets'
                ]);
            }
        }
        $mergedAdsetIds = array_unique(array_merge($adsetIdsFromClicks, $adsetIdsFromApiWithSpendFiltered, $adsetIdsWithExistingCostRowsFiltered));
        $spendOnlyCount = count($mergedAdsetIds) - count($adsetIdsFromClicks);
        if ($spendOnlyCount > 0 || count($adsetIdsWithExistingCostRowsFiltered) > 0) {
            $logger->logDetail("Merged spend-only and zero-spend adsets from API and existing cost rows", [
                'ad_account_name' => $adAccountName,
                'adsets_from_clicks' => count($adsetIdsFromClicks),
                'adsets_from_api_with_spend' => count($adsetIdsFromApiWithSpendFiltered),
                'adsets_with_existing_cost_rows' => count($adsetIdsWithExistingCostRowsFiltered),
                'spend_only_added' => $spendOnlyCount,
                'total_adsets_to_process' => count($mergedAdsetIds),
                'note' => 'Includes spend >= 0 from API and existing cost rows so stale cost can be overwritten with 0. Filtered to active campaigns only.'
            ]);
        }
        
        // Process adsets from clicks + spend-only from API - use account-level data if available, otherwise query directly
        // CRITICAL: This code ALWAYS runs, whether API call succeeded or failed (matches test script)
        $logger->logDetail("Starting to process adsets from clicks", [
            'ad_account_name' => $adAccountName,
            'total_adsets_in_lookup' => count($adsetLookup),
            'adset_ids_in_lookup' => array_keys($adsetLookup),
            'account_level_results' => count($accountAdsetSpend),
            'merged_adsets_to_process' => count($mergedAdsetIds),
            'note' => 'This always runs, even if API call failed. Includes spend-only adsets from API.'
        ]);
        
        // If account-level API returned 0 results, log as anomaly and set up fallback cap (only when we had adsets from clicks)
        $fallbackCallCount = 0;
        $maxFallbackCalls = 20; // Cap per-adset fallback calls to protect hourly API limit
        if (count($accountAdsetSpend) === 0 && count($adsetLookup) > 0) {
            $logger->logDetail("WARNING: Account-level API returned 0 results - this is an anomaly", [
                'ad_account_name' => $adAccountName,
                'integration_id' => $integrationId,
                'api_ad_account_id' => $apiAdAccountId,
                'total_adsets_in_lookup' => count($adsetLookup),
                'fallback_cap' => $maxFallbackCalls,
                'note' => 'Will cap fallback calls to protect API rate limit'
            ]);
        }
        
        foreach ($mergedAdsetIds as $adsetId) {
            // CRITICAL: Reset previousHourSpend for each adset (not shared across adsets)
            $previousHourSpend = null;
            
            // Log that we're entering the loop
            $logger->logDetail("Processing adset in loop", [
                'ad_account_name' => $adAccountName,
                'adset_id' => $adsetId,
                'loop_iteration' => 'start',
                'adset_id_type' => gettype($adsetId)
            ]);
            
            // CRITICAL: Convert to string for strpos check (matches test script)
            $adsetId = (string)$adsetId;
            
            // Skip template variables
            if (strpos($adsetId, '{{') !== false || strpos($adsetId, '{ts:') !== false) {
                $logger->logDetail("Skipping template variable adset", [
                    'ad_account_name' => $adAccountName,
                    'adset_id' => $adsetId
                ]);
                continue;
            }
            
            // Try to get spend from account-level response first
            // CRITICAL: accountAdsetSpend keys are strings, so use string key for lookup
            // CRITICAL: Zero-spend adsets are valid and should NOT trigger fallback calls
            if (isset($accountAdsetSpend[$adsetId])) {
                $currentSpend = $accountAdsetSpend[$adsetId];
                $logger->logDetail("Using account-level spend for adset", [
                    'ad_account_name' => $adAccountName,
                    'adset_id' => $adsetId,
                    'spend' => $currentSpend,
                    'is_zero_spend' => ($currentSpend == 0.0),
                    'note' => 'Zero-spend is valid and does not trigger fallback'
                ]);
            } else {
                // Fallback: Query adset directly if not in account-level response
                // SKIP FALLBACK when account-level returned 0 - means all adsets are paused/archived in Facebook.
                // We should not burn API calls for paused/archived adsets.
                if (count($accountAdsetSpend) === 0) {
                    $currentSpend = 0.0;
                    $logger->logDetail("Skipping API call - account-level returned 0 (adsets paused/archived in Facebook)", [
                        'ad_account_name' => $adAccountName,
                        'adset_id' => $adsetId
                    ]);
                } elseif ($fallbackCallCount >= $maxFallbackCalls) {
                    $stats['adset_fallback_capped']++;
                    $logger->logDetail("WARNING: Fallback call cap reached, skipping remaining adsets", [
                        'ad_account_name' => $adAccountName,
                        'adset_id' => $adsetId,
                        'fallback_call_count' => $fallbackCallCount,
                        'max_fallback_calls' => $maxFallbackCalls,
                        'total_adsets_remaining' => count($adsetLookup) - $fallbackCallCount,
                        'note' => 'This protects API rate limit. Account-level API should be fixed to avoid this.'
                    ]);
                    continue; // Skip this and remaining adsets
                } else {
                $fallbackCallCount++;
                $stats['adset_fallback_attempts']++;
                $logger->logDetail("Adset not in account-level response, querying directly", [
                    'ad_account_name' => $adAccountName,
                    'adset_id' => $adsetId,
                    'date_for_api' => $processDateForApi,
                    'ad_account_timezone' => $adAccountTimezone,
                    'date_utc' => $processDate,
                    'user_today' => $todayInUserTz,
                    'fallback_call_number' => $fallbackCallCount,
                    'max_fallback_calls' => $maxFallbackCalls
                ]);
                
                try {
                    // Use ad account timezone date for API query (matches test script)
                    $logger->logDetail("Calling fetchAdsetSpend", [
                        'ad_account_name' => $adAccountName,
                        'adset_id' => $adsetId,
                        'date' => $processDateForApi,
                        'ad_account_timezone' => $adAccountTimezone
                    ]);
                    $currentSpend = $apiClient->fetchAdsetSpend($adsetId, $processDateForApi);
                    if ($currentSpend === null) {
                        // Treat null as 0 so we overwrite stale cost rows (e.g. Scale Bid Cap showing 0.19 when actual is 0)
                        $currentSpend = 0.0;
                        $logger->logDetail("Adset has no spend for {$processDateForApi} (ad account TZ), treating as 0 to overwrite stale cost", [
                            'ad_account_name' => $adAccountName,
                            'adset_id' => $adsetId,
                            'date_utc' => $processDate,
                            'date_ad_account_tz' => $processDateForApi,
                            'note' => 'Facebook API returned null; writing spend=0 to overwrite any stale cost row'
                        ]);
                    }
                    $logger->logDetail("Retrieved direct adset spend", [
                        'ad_account_name' => $adAccountName,
                        'adset_id' => $adsetId,
                        'spend' => $currentSpend,
                        'date' => $processDateForApi
                    ]);
                } catch (\Exception $e) {
                    $logger->logDetail("Error querying adset directly", [
                        'ad_account_name' => $adAccountName,
                        'adset_id' => $adsetId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    continue;
                }
                }
            }
            
            // Process hours from getHoursToProcess (matches test script approach)
            // Note: Facebook API only provides daily totals, not per-hour breakdowns.
            // We store the total daily spend in each hour, and delta is calculated from previous hour.
            
            // CRITICAL FIX: Always check clicks date first to ensure costs are saved to the correct UTC date
            // This prevents yesterday's costs from appearing in today's total and vice versa
            // We check both utcStartDate (user's "today" in UTC) and processDate (UTC "today") to find where clicks actually are
            $dateForDbForThisAdset = null;
            $hoursWithClicks = [];
            
            if ($currentSpend !== null && $currentSpend > 0) {
                // Query database to find which UTC date and hours have clicks for this adset
                // Check utcStartDate first (user's "today" in UTC), then processDate (UTC "today")
                $datesToCheck = array_values(array_unique([$utcStartDate, $utcEndDate, $processDate]));
                
                foreach ($datesToCheck as $dateToCheck) {
                    $clickHoursQuery = $db->prepare("
                        SELECT DISTINCT HOUR(ts) as click_hour
                        FROM clicks
                        WHERE JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.adset_id')) = ?
                            AND DATE(ts) = ?
                            AND JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.adset_id')) IS NOT NULL
                            AND JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.adset_id')) != ''
                            AND JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.adset_id')) != 'null'
                            AND JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.adset_id')) NOT LIKE '{{%'
                            AND JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.adset_id')) NOT LIKE '{ts:%'
                        ORDER BY click_hour ASC
                    ");
                    $clickHoursQuery->bind_param('ss', $adsetId, $dateToCheck);
                    $clickHoursQuery->execute();
                    $clickHoursResult = $clickHoursQuery->get_result();
                    
                    $hoursForThisDate = [];
                    while ($row = $clickHoursResult->fetch_assoc()) {
                        $hoursForThisDate[] = (int)$row['click_hour'];
                    }
                    
                    if (!empty($hoursForThisDate)) {
                        // Found clicks on this date - use this date and these hours
                        $dateForDbForThisAdset = $dateToCheck;
                        $hoursWithClicks = $hoursForThisDate;
                        break; // Stop checking other dates once we find clicks
                    }
                }
                
                if (!empty($hoursWithClicks)) {
                    // Found clicks - determine which hours to process
                    if (empty($hoursToProcess)) {
                        // hoursToProcess was empty (midnight case) - use ONLY the FIRST hour with clicks
                        // CRITICAL: We should NOT save the same total spend to multiple hours
                        // Facebook API returns cumulative daily spend, not per-hour spend
                        // Saving to multiple hours would duplicate the cost in the dashboard
                        $hoursToProcess = [min($hoursWithClicks)]; // Use only the first (earliest) hour
                        $logger->logDetail("hours_to_process was empty but found clicks - using first hour with clicks", [
                            'ad_account_name' => $adAccountName,
                            'adset_id' => $adsetId,
                            'spend' => $currentSpend,
                            'hours_with_clicks' => $hoursWithClicks,
                            'first_hour_with_clicks' => min($hoursWithClicks),
                            'utc_date' => $dateForDbForThisAdset,
                            'utc_start_date' => $utcStartDate ?? 'not set',
                            'process_date' => $processDate,
                            'current_hour' => $currentHour,
                            'utc_start_hour' => $utcStartHour ?? 'not set',
                            'note' => 'Saving cost to first hour with clicks only (prevents duplicate costs in dashboard)'
                        ]);
                    } else {
                        // CRITICAL: Use ALL hours that have clicks so the dashboard can attribute cost to every click.
                        // The dashboard joins cost to clicks by (adset_id, date, hour); if we only write to one hour,
                        // clicks in other hours get $0 (e.g. Solar showing 0). We distribute delta_spend evenly below.
                        $hoursToProcess = array_values(array_unique($hoursWithClicks));
                        sort($hoursToProcess);
                        $hoursToProcess = filterHoursForUserToday(
                            $hoursToProcess,
                            $dateForDbForThisAdset ?? $processDate,
                            $utcStartDate,
                            $utcStartHour,
                            $utcEndDate,
                            $utcEndHour,
                            $adAccountName,
                            $todayInUserTz,
                            $logger
                        );
                        $logger->logDetail("Found clicks - using clicks date and ALL hours with clicks", [
                            'ad_account_name' => $adAccountName,
                            'adset_id' => $adsetId,
                            'spend' => $currentSpend,
                            'hours_with_clicks' => $hoursWithClicks,
                            'hours_to_process' => $hoursToProcess,
                            'utc_date' => $dateForDbForThisAdset,
                            'utc_start_date' => $utcStartDate ?? 'not set',
                            'process_date' => $processDate,
                            'note' => 'Writing cost to every hour with clicks so dashboard can attribute (delta distributed evenly)'
                        ]);
                    }
                } else {
                    // No clicks found yet - use fallback logic
                    if (empty($hoursToProcess)) {
                        // hoursToProcess was empty - use utcStartHour or currentHour as fallback
                        $hourToUse = resolveFallbackHourForUserToday(
                            $processDate,
                            $currentHour,
                            $utcStartDate,
                            $utcStartHour,
                            $utcEndDate,
                            $utcEndHour
                        );
                        $hoursToProcess = [$hourToUse];
                        $dateForDbForThisAdset = $processDate;
                        $logger->logDetail("No clicks found - using fallback hour and date", [
                            'ad_account_name' => $adAccountName,
                            'adset_id' => $adsetId,
                            'spend' => $currentSpend,
                            'hour_to_use' => $hourToUse,
                            'current_hour' => $currentHour,
                            'utc_start_hour' => $utcStartHour ?? 'not set',
                            'utc_start_date' => $utcStartDate ?? 'not set',
                            'process_date' => $processDate,
                            'utc_date' => $dateForDbForThisAdset,
                            'note' => 'Using fallback hour (utcStartHour or currentHour) since no clicks exist yet. Cost will be saved and matched when clicks arrive.'
                        ]);
                    } else {
                        // hoursToProcess is not empty - use processDate but ONLY one hour for spend-only adsets
                        // CRITICAL: Writing to all hours would risk over-counting (same spend in multiple rows).
                        // Use current hour (or utcStartHour) so we write one row per spend-only adset per day.
                        $dateForDbForThisAdset = $processDate;
                        $hourToUse = resolveFallbackHourForUserToday(
                            $processDate,
                            $currentHour,
                            $utcStartDate,
                            $utcStartHour,
                            $utcEndDate,
                            $utcEndHour
                        );
                        $hoursToProcess = [$hourToUse];
                        $logger->logDetail("No clicks found but hoursToProcess not empty - using single hour for spend-only adset", [
                            'ad_account_name' => $adAccountName,
                            'adset_id' => $adsetId,
                            'spend' => $currentSpend,
                            'hour_to_use' => $hourToUse,
                            'utc_date' => $dateForDbForThisAdset,
                            'process_date' => $processDate,
                            'note' => 'Spend-only adset: writing to one hour only to avoid over-counting cost in dashboard'
                        ]);
                    }
                }
            } else {
                // No spend data - use processDate as default
                $dateForDbForThisAdset = $processDate;
            }
            
            // When we write to multiple hours with clicks, distribute delta so dashboard sum = total spend (no double-count)
            $distributeDeltaAcrossHours = (!empty($hoursWithClicks) && count($hoursToProcess) > 1);
            
            foreach ($hoursToProcess as $hour) {
                $stats['adsets_processed']++;
                
                // CRITICAL: Costs must be saved with UTC date/hour that matches clicks' DATE(ts) and HOUR(ts)
                // The aggregator matches costs using: a_cost.date = DATE(cl2.ts) AND a_cost.hour = HOUR(cl2.ts)
                // So we must use the UTC date/hour directly, not convert based on ad account timezone
                // CRITICAL FIX: When processing adsets without clicks (when hoursToProcess was empty),
                // use $utcStartDate (UTC date for user's "today") instead of $processDate (UTC "today")
                // This ensures costs are saved to the correct UTC date that matches the user's timezone
                // At early morning hours (e.g., 2 AM UTC), UTC "today" is already the next day,
                // but the user's "today" is still the previous day, so we need $utcStartDate
                $dateForDb = $dateForDbForThisAdset;
                
                // Log date being used (clicks date vs processDate)
                if ($dateForDb !== $processDate) {
                    // Using clicks date (different from processDate) - ensures costs match clicks
                    $logger->logDetail("Processing adset - using clicks date", [
                        'ad_account_name' => $adAccountName,
                        'adset_id' => $adsetId,
                        'hour' => $hour,
                        'date_for_db' => $dateForDb,
                        'process_date' => $processDate,
                        'spend' => $currentSpend,
                        'note' => 'Using clicks date to ensure costs are saved to same date as clicks'
                    ]);
                } else {
                    // Using processDate (normal case or fallback when no clicks found)
                    $logger->logDetail("Processing adset", [
                        'integration_name' => $adAccountName,
                        'adset_id' => $adsetId,
                        'current_spend' => $currentSpend,
                        'date' => $dateForDb,
                        'hour' => $hour
                    ]);
                }
                
                try {
                    // CRITICAL: Get previous hour's spend - use in-memory value if available, otherwise query database
                    // This matches test script behavior and prevents timing issues when processing hours in a loop
                    if ($previousHourSpend === null) {
                        // First hour in loop - query database for previous hour
                        // CRITICAL: Use $dateForDb (which may be $utcStartDate) instead of $processDate for consistency
                        if (isset($utcStartHour) && $hour === $utcStartHour) {
                            // First hour of user's day: use hour 23 of previous UTC day
                            $previousHour = 23;
                            $previousDateForDb = date('Y-m-d', strtotime($dateForDb . ' -1 day'));
                        } else {
                            $previousHour = ($hour > 0) ? $hour - 1 : 23;
                            $previousDateForDb = ($hour > 0) ? $dateForDb : date('Y-m-d', strtotime($dateForDb . ' -1 day'));
                        }
                        $previousSpend = getLastHourSpend($db, 'adset', $adsetId, $previousDateForDb, $previousHour, $integrationId);
                    } else {
                        // Use in-memory previous hour's spend (from the hour we just processed)
                        $previousSpend = $previousHourSpend;
                    }
                    
                    // Check if this hour already has a record
                    $existingRecord = getExistingHourRecord($db, 'adset', $adsetId, $dateForDb, $hour, $integrationId);
                    
                    // Calculate delta (matches test script logic exactly)
                // If this hour already exists and the spend hasn't changed, keep the existing delta
                // (Skip when distributing across hours - we always use the distributed delta.)
                if (!$distributeDeltaAcrossHours && $existingRecord && abs($existingRecord['spend'] - $currentSpend) < 0.01) {
                    // Spend hasn't changed, keep existing delta
                        $deltaSpend = (float)$existingRecord['delta_spend'];
                    $logger->logDetail("Hour already exists with same spend, keeping existing delta", [
                        'ad_account_name' => $adAccountName,
                        'adset_id' => $adsetId,
                            'hour' => $hour,
                        'existing_delta' => $deltaSpend
                        ]);
                } else {
                    // Calculate new delta
                    if ($distributeDeltaAcrossHours) {
                        // Dashboard joins cost to clicks by (adset_id, date, hour). Distribute daily spend
                        // evenly so each hour has a cost row; sum of deltas = currentSpend, so no double-count.
                        $deltaSpend = round($currentSpend / count($hoursToProcess), 2);
                    } else {
                        $deltaSpend = $currentSpend - ($previousSpend ?? 0.0);
                    }
                    
                    if ($deltaSpend < 0) {
                        // Ad account calendar may have rolled ahead of user calendar (e.g. Eastern vs Pacific).
                        // API was queried for user today, so treat current cumulative as the day total baseline.
                        if ($todayInAdAccountTz !== $todayInUserTz && $currentSpend < ($previousSpend ?? 0.0)) {
                            $deltaSpend = $currentSpend;
                            $logger->logDetail('Negative delta reset — ad account day ahead of user day', [
                                'ad_account_name' => $adAccountName,
                                'adset_id' => $adsetId,
                                'hour' => $hour,
                                'calculated_delta' => $deltaSpend,
                                'current_spend' => $currentSpend,
                                'previous_spend' => $previousSpend,
                                'user_today' => $todayInUserTz,
                                'ad_account_today' => $todayInAdAccountTz,
                            ]);
                        } else {
                        // Negative delta means we're processing hours out of order during backfilling
                            // Find the actual previous hour with lower spend (search backwards from current hour)
                        $actualPreviousSpend = null;
                            for ($checkHour = $hour - 1; $checkHour >= 0; $checkHour--) {
                                $checkDateForDb = $processDate; // Use UTC date directly
                                $checkRecord = getExistingHourRecord($db, 'adset', $adsetId, $checkDateForDb, $checkHour, $integrationId);
                                if ($checkRecord && (float)$checkRecord['spend'] < $currentSpend) {
                                    $actualPreviousSpend = (float)$checkRecord['spend'];
                                break;
                            }
                        }
                        
                        if ($actualPreviousSpend !== null) {
                            // Found a previous hour with lower spend - use it for delta calculation
                            $deltaSpend = $currentSpend - $actualPreviousSpend;
                            $logger->logDetail("Negative delta recalculated using actual previous hour", [
                                'ad_account_name' => $adAccountName,
                                'adset_id' => $adsetId,
                                'hour' => $hour,
                                'calculated_delta' => $deltaSpend,
                                'current_spend' => $currentSpend,
                                'previous_spend' => $previousSpend,
                                'actual_previous_spend' => $actualPreviousSpend
                            ]);
                        } else {
                            // No previous hour with lower spend found - this is likely the first hour with this spend
                            // Check hour 0 of today, or if this IS hour 0, check hour 23 of yesterday
                            $baseSpend = 0.0;
                                if ($hour > 0) {
                                    $hour0DateForDb = $processDate; // Use UTC date directly
                                    $hour0Record = getExistingHourRecord($db, 'adset', $adsetId, $hour0DateForDb, 0, $integrationId);
                                    $baseSpend = $hour0Record ? (float)$hour0Record['spend'] : 0.0;
                            } else {
                                // Hour 0: check hour 23 of previous day
                                $previousDate = date('Y-m-d', strtotime($dateForDb . ' -1 day'));
                                    $hour23DateForDb = $previousDate; // Use UTC date directly
                                    $hour23Record = getExistingHourRecord($db, 'adset', $adsetId, $hour23DateForDb, 23, $integrationId);
                                    $baseSpend = $hour23Record ? (float)$hour23Record['spend'] : 0.0;
                                }
                                
                                // If base spend is greater than the current spend (likely from a prior day), reset so the first in-range hour gets full delta
                                if ($baseSpend > $currentSpend) {
                                $baseSpend = 0.0;
                            }
                            
                            $deltaSpend = $currentSpend - $baseSpend;
                            $logger->logDetail("Negative delta recalculated using base spend", [
                                'ad_account_name' => $adAccountName,
                                'adset_id' => $adsetId,
                                    'hour' => $hour,
                                'calculated_delta' => $deltaSpend,
                                'current_spend' => $currentSpend,
                                'base_spend' => $baseSpend
                            ]);
                        }
                        
                        // Final safety check
                        if ($deltaSpend < 0) {
                            $deltaSpend = 0.0;
                        }
                        }
                    } elseif (abs($deltaSpend) < 0.01 && $currentSpend > 0.01) {
                        // Delta is 0 but there's actual spend - this means current spend equals previous spend
                        // This is correct: no new spend occurred in this hour
                        $deltaSpend = 0.0;
                    }
                }
                
                // Log delta calculation - distinguish between adsets with and without clicks
                if ($dateForDb !== $processDate) {
                    // This is an adset without clicks
                    $logger->logDetail("Calculated delta for adset without clicks", [
                        'ad_account_name' => $adAccountName,
                        'adset_id' => $adsetId,
                        'delta_spend' => $deltaSpend,
                        'current_spend' => $currentSpend,
                        'previous_spend' => $previousSpend,
                        'date_for_db' => $dateForDb,
                        'hour' => $hour
                    ]);
                } else {
                    // Normal case - adset with clicks
                    $logger->logDetail("Calculated delta for adset", [
                        'ad_account_name' => $adAccountName,
                        'adset_id' => $adsetId,
                        'delta_spend' => $deltaSpend,
                        'current_spend' => $currentSpend,
                        'previous_spend' => $previousSpend,
                        'date_for_db' => $dateForDb
                    ]);
                }
                
                    // Upsert into adset_hourly_costs table
                    // Use integration_id (not adAccountInternalId) because the FK references facebook_marketing_integrations
                    // dateForDb uses UTC date directly to match clicks' DATE(ts)
                    // Use $hour directly (matches test script - no saveHour workaround needed since we clear costs first)
                    if (upsertAdsetHourlyCost($db, $integrationId, $adsetId, $dateForDb, $hour, $currentSpend, $deltaSpend)) {
                    $stats['adsets_success']++;
                    // Log success - distinguish between adsets with and without clicks
                    if ($dateForDb !== $processDate) {
                        // This is an adset without clicks
                        $logger->logDetail("Successfully upserted adset hourly cost (no clicks)", [
                            'ad_account_name' => $adAccountName,
                            'adset_id' => $adsetId,
                            'spend' => $currentSpend,
                            'delta_spend' => $deltaSpend,
                            'hour' => $hour,
                            'date' => $dateForDb
                        ]);
                    } else {
                        // Normal case - adset with clicks
                        $logger->logDetail("Successfully upserted adset hourly cost", [
                            'ad_account_name' => $adAccountName,
                            'adset_id' => $adsetId,
                            'spend' => $currentSpend,
                            'delta_spend' => $deltaSpend
                        ]);
                    }
                    
                    // CRITICAL: Update in-memory previous hour's spend for next iteration
                    // This ensures the next hour uses the correct previous spend without querying database
                    $previousHourSpend = $currentSpend;
                } else {
                    $stats['adsets_failed']++;
                    $errorMsg = "Failed to upsert adset hourly cost for adset {$adsetId}";
                    $stats['errors'][] = $errorMsg;
                    $logger->logDetail("Failed to upsert adset hourly cost", [
                        'ad_account_name' => $adAccountName,
                        'adset_id' => $adsetId,
                        'error' => $db->error ?? 'Unknown database error'
                    ]);
                }
                } catch (\Exception $e) {
                    $stats['adsets_failed']++;
                    $errorMsg = "Exception processing adset {$adsetId}: " . $e->getMessage();
                    $stats['errors'][] = $errorMsg;
                    $logger->logDetail("Exception processing adset", [
                        'ad_account_name' => $adAccountName,
                        'adset_id' => $adsetId,
                        'date' => $processDate,
                        'hour' => $hour,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            } // End foreach $hoursToProcess
        } // End foreach $adsetLookup
        
        $logger->logDetail("Finished processing all adsets from clicks", [
            'ad_account_name' => $adAccountName,
            'adsets_processed' => $stats['adsets_processed'],
            'adsets_success' => $stats['adsets_success'],
            'adsets_failed' => $stats['adsets_failed']
        ]);
        
        // OPTIMIZED: Removed individual ad queries - we only need adset-level spend
        // Ad costs are calculated proportionally from adset spend based on click counts
        // This is handled in FacebookCostAggregator which divides adset spend by click count
        // This eliminates 100+ individual ad API calls per account
        $logger->logDetail("Skipping individual ad queries - using adset-level spend only", [
                'integration_name' => $adAccountName,
            'total_ads_in_clicks' => count($adLookup),
            'note' => 'Ad costs calculated from adset spend proportionally based on clicks'
        ]);
    } // End foreach $datesToProcess
    
    // CRITICAL: Log final stats and warn if no costs were processed
    // OPTIMIZED: Only check adsets since we no longer process individual ads
    $totalProcessed = $stats['adsets_processed'];
    if ($totalProcessed === 0) {
        $logger->logDetail("WARNING: No costs were processed - costs may have been cleared but not rebuilt!", [
            'ad_account_name' => $adAccountName,
            'integration_id' => $integrationId,
            'utc_date_for_db' => $utcDateForThisDate ?? 'unknown',
            'user_today' => $todayInUserTz,
            'utc_today' => $today,
            'total_adsets_in_lookup' => count($adsetLookup ?? []),
            'total_ads_in_lookup' => count($adLookup ?? []),
            'account_level_results' => count($accountAdsetSpend ?? []),
            'hours_to_process_count' => count($hoursToProcess ?? []),
            'hours_to_process' => $hoursToProcess ?? [],
            'current_hour' => $currentHour,
            'utc_start_hour' => $utcStartHour ?? 'not set',
            'note' => 'This is a critical warning - costs were cleared but nothing was processed!'
        ]);
    }
    
    $logger->logDetail("Completed processing ad account", [
        'ad_account_name' => $adAccountName,
        'ad_account_internal_id' => $adAccountInternalId,
        'utc_date_for_db' => $utcDateForThisDate ?? 'unknown',
        'user_today' => $todayInUserTz,
        'utc_today' => $today,
        'adsets_processed' => $stats['adsets_processed'],
        'adsets_success' => $stats['adsets_success'],
        'adsets_failed' => $stats['adsets_failed'],
        'note' => 'Individual ad costs calculated from adset spend proportionally based on clicks'
    ]);
}

// Log summary using Logger
$logger->logCostSyncRun($stats);

$persistLastRun($apiCallTracker, $runStartedAt, $runStartedUnix, [
    'status' => 'completed',
    'user_timezone' => $userTimezone,
    'user_today' => $todayInUserTz,
    'utc_dates_processed' => $datesToProcess,
    'ad_accounts' => count($adAccountIds),
    'ad_accounts_processed' => $stats['ad_accounts_processed'],
    'adsets_processed' => $stats['adsets_processed'],
    'adsets_success' => $stats['adsets_success'],
    'adsets_failed' => $stats['adsets_failed'],
    'adset_fallback_attempts' => $stats['adset_fallback_attempts'],
    'adset_fallback_capped' => $stats['adset_fallback_capped'],
    'errors' => array_slice($stats['errors'] ?? [], 0, 10),
]);

// Rotate old logs
$logger->rotateLogs();

$logger->logDetail("=== Facebook Cost Updater Cron Completed ===", [
    'final_stats' => $stats
]);

$db->close();
exit(0);

/**
 * Keep only UTC hours that belong to the user's calendar day.
 */
function filterHoursForUserToday(
    array $hours,
    string $processDate,
    string $utcStartDate,
    int $utcStartHour,
    string $utcEndDate,
    int $utcEndHour,
    string $adAccountName,
    string $todayInUserTz,
    $logger
): array {
    $filtered = [];
    foreach ($hours as $hour) {
        $hour = (int)$hour;
        $include = false;

        if ($utcStartDate === $utcEndDate && $processDate === $utcStartDate) {
            $include = ($hour >= $utcStartHour && $hour <= $utcEndHour);
        } elseif ($processDate === $utcStartDate) {
            $include = ($hour >= $utcStartHour);
        } elseif ($processDate === $utcEndDate) {
            $include = ($hour <= $utcEndHour);
        }

        if ($include) {
            $filtered[] = $hour;
        } else {
            $logger->logDetail('SKIPPING hour outside user calendar day', [
                'ad_account_name' => $adAccountName,
                'hour' => $hour,
                'utc_date_for_db' => $processDate,
                'user_today' => $todayInUserTz,
                'utc_start_date' => $utcStartDate,
                'utc_start_hour' => $utcStartHour,
                'utc_end_date' => $utcEndDate,
                'utc_end_hour' => $utcEndHour,
            ]);
        }
    }

    return $filtered;
}

/**
 * Pick a single UTC hour for spend-only / no-click fallbacks within the user calendar day.
 */
function resolveFallbackHourForUserToday(
    string $processDate,
    int $currentHour,
    string $utcStartDate,
    int $utcStartHour,
    string $utcEndDate,
    int $utcEndHour
): int {
    if ($processDate === $utcEndDate && $processDate !== $utcStartDate) {
        return min($currentHour, $utcEndHour);
    }
    if ($processDate === $utcStartDate && $processDate !== $utcEndDate) {
        return max($utcStartHour, min($currentHour, 23));
    }

    return max($utcStartHour, min($currentHour, $utcEndHour));
}

/**
 * Get hours that need to be processed (backfill missed hours for today only)
 * Returns array of hours (0 to current hour) that don't have records yet
 * Always includes current hour to ensure it's updated
 * 
 * @param mysqli $db Database connection
 * @param string $date UTC date (Y-m-d format)
 * @param int $currentHour Current hour (0-23) in UTC
 * @param array $adsetLookup Array of adset IDs to check
 * @param array $adLookup Array of ad IDs to check
 * @param int $integrationId Integration ID (used as ad_account_id in cost tables)
 * @param object $logger Logger instance
 * @return array Array of hours to process (sorted)
 */
function getHoursToProcess(mysqli $db, string $date, int $currentHour, array $adsetLookup, array $adLookup, int $integrationId, $logger): array
{
    $allHours = range(0, $currentHour);
    $existingHours = [];
    
    // OPTIMIZED: Only check adset table since we no longer store individual ad costs
    // Ad costs are calculated on-the-fly from adset spend based on click counts
    $query = "
        SELECT DISTINCT hour FROM adset_hourly_costs 
        WHERE ad_account_id = ? AND date = ?
    ";
    $stmt = $db->prepare($query);
    $stmt->bind_param('is', $integrationId, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $existingHours[] = (int)$row['hour'];
    }
    
    // Find missing hours (hours 0 to current that don't have records)
    $missingHours = array_diff($allHours, $existingHours);
    
    // Start with missing hours (backfill)
    $hoursToProcess = array_values($missingHours);
    
    // Only add current hour if it's missing OR if we need to update it
    // But we should check if current hour already exists for ALL entities
    // If it exists, we still want to update it to get the latest spend
    // However, we need to be careful not to recalculate delta incorrectly
    if (!in_array($currentHour, $hoursToProcess)) {
        $hoursToProcess[] = $currentHour;
    }
    
    // Sort hours to process in order (0, 1, 2, ... current)
    sort($hoursToProcess);
    
    return $hoursToProcess;
}

/**
 * Get existing hour record from database
 * Returns the record if it exists, null otherwise
 */
function getExistingHourRecord(mysqli $db, string $type, string $entityId, string $date, int $hour, int $adAccountId): ?array
{
    $table = $type === 'adset' ? 'adset_hourly_costs' : 'ad_hourly_costs';
    $idField = $type === 'adset' ? 'adset_id' : 'ad_id';
    
    $stmt = $db->prepare(
        "SELECT spend, delta_spend FROM {$table} 
         WHERE {$idField} = ? AND date = ? AND hour = ? AND ad_account_id = ?
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->bind_param('ssii', $entityId, $date, $hour, $adAccountId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    return $row ? $row : null;
}

/**
 * Get last hour's spend from database
 * Handles cross-day boundaries (hour 0 checks hour 23 of previous day)
 */
function getLastHourSpend(mysqli $db, string $type, string $entityId, string $date, int $currentHour, int $adAccountId): ?float
{
    $table = $type === 'adset' ? 'adset_hourly_costs' : 'ad_hourly_costs';
    $idField = $type === 'adset' ? 'adset_id' : 'ad_id';
    
    if ($currentHour > 0) {
        // Same day, previous hour
        $previousHour = $currentHour - 1;
        $stmt = $db->prepare(
            "SELECT spend FROM {$table} 
             WHERE {$idField} = ? AND date = ? AND hour = ? AND ad_account_id = ?
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->bind_param('ssii', $entityId, $date, $previousHour, $adAccountId);
    } else {
        // Hour 0: check hour 23 of previous day
        $previousDate = date('Y-m-d', strtotime($date . ' -1 day'));
        $previousHour = 23;
        $stmt = $db->prepare(
            "SELECT spend FROM {$table} 
             WHERE {$idField} = ? AND date = ? AND hour = ? AND ad_account_id = ?
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->bind_param('ssii', $entityId, $previousDate, $previousHour, $adAccountId);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    return $row ? (float)$row['spend'] : null;
}

/**
 * Upsert adset hourly cost
 */
function upsertAdsetHourlyCost(mysqli $db, int $adAccountId, string $adsetId, string $date, int $hour, float $spend, float $deltaSpend): bool
{
    $stmt = $db->prepare(
        "INSERT INTO adset_hourly_costs 
         (ad_account_id, adset_id, date, hour, spend, delta_spend, last_synced, source) 
         VALUES (?, ?, ?, ?, ?, ?, NOW(), 'fb_api')
         ON DUPLICATE KEY UPDATE 
         spend = VALUES(spend), 
         delta_spend = VALUES(delta_spend), 
         last_synced = NOW()"
    );
    
    $stmt->bind_param('issidd', $adAccountId, $adsetId, $date, $hour, $spend, $deltaSpend);
    return $stmt->execute();
}

/**
 * Upsert ad hourly cost
 */
function upsertAdHourlyCost(mysqli $db, int $adAccountId, string $adId, ?string $adsetId, string $date, int $hour, float $spend, float $deltaSpend): bool
{
    $stmt = $db->prepare(
        "INSERT INTO ad_hourly_costs 
         (ad_account_id, ad_id, adset_id, date, hour, spend, delta_spend, last_synced, source) 
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'fb_api')
         ON DUPLICATE KEY UPDATE 
         spend = VALUES(spend), 
         delta_spend = VALUES(delta_spend), 
         last_synced = NOW()"
    );
    
    // Handle NULL adset_id properly - use bind_param with 'b' for binary/NULL
    if ($adsetId === null || $adsetId === '') {
        // Use NULL in database
        $null = null;
        $stmt->bind_param('isbidd', $adAccountId, $adId, $null, $date, $hour, $spend, $deltaSpend);
    } else {
        $stmt->bind_param('isssidd', $adAccountId, $adId, $adsetId, $date, $hour, $spend, $deltaSpend);
    }
    return $stmt->execute();
}

