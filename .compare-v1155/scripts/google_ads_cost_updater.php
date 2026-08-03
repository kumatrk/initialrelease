<?php
/**
 * Google Ads Cost Updater Cron Script
 *
 * Fetches Google Ads campaign spend via GAQL and stores hourly snapshots.
 *
 * Usage: php scripts/google_ads_cost_updater.php
 * Cron: 0 * * * * /usr/bin/php /path/to/scripts/google_ads_cost_updater.php >> /var/log/google_ads_cost_updater.log 2>&1
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

use SimpleKuma\Entity\GoogleAdsIntegration;
use SimpleKuma\GoogleAds\GoogleAdsTrafficSourceIdentifier;
use SimpleKuma\GoogleAds\GoogleAdsClickExtractor;
use SimpleKuma\GoogleAds\GoogleAdsInsightsClient;
use SimpleKuma\GoogleAds\GoogleAdsApiCallTracker;
use SimpleKuma\Logger;

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

if ($db->connect_error) {
    error_log('Google Ads Cost Updater: Database connection failed: ' . $db->connect_error);
    exit(1);
}

date_default_timezone_set('UTC');

$googleAdsIntegration = new GoogleAdsIntegration($db);
$trafficSourceIdentifier = new GoogleAdsTrafficSourceIdentifier($db);
$clickExtractor = new GoogleAdsClickExtractor($db);
$logger = new Logger();

$logger->logDetail('=== Google Ads Cost Updater Cron Started ===', [
    'date' => date('Y-m-d'),
    'hour' => date('H:i:s'),
    'timezone' => date_default_timezone_get(),
]);

$userTimezone = 'UTC';
$userTimezoneQuery = $db->query('SELECT timezone FROM users LIMIT 1');
if ($userTimezoneQuery && $userRow = $userTimezoneQuery->fetch_assoc()) {
    $userTimezone = $userRow['timezone'] ?? 'UTC';
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

$userTz = new DateTimeZone($userTimezone);
$nowInUserTz = new DateTime('now', $userTz);
$queryDate = $nowInUserTz->format('Y-m-d');

date_default_timezone_set('UTC');
$storageDate = date('Y-m-d');
$currentHour = (int)date('G');

$logger->logDetail('Processing Google Ads costs', [
    'query_date' => $queryDate,
    'storage_date' => $storageDate,
    'current_hour' => $currentHour,
    'user_timezone' => $userTimezone,
    'note' => 'Query Google API for user calendar date; store costs on UTC date to match clicks.ts',
]);

$integrations = $googleAdsIntegration->getActiveForCostTracking();

if (empty($integrations)) {
    $logger->logDetail('No active Google Ads cost integrations found. Exiting.');
    exit(0);
}

$googleSourceIds = $trafficSourceIdentifier->getGoogleSourceIds();

if (empty($googleSourceIds)) {
    $logger->logDetail('No Google/YouTube traffic sources found. Exiting.');
    exit(0);
}

$stats = [
    'integrations_processed' => 0,
    'campaigns_processed' => 0,
    'campaigns_success' => 0,
    'campaigns_failed' => 0,
    'errors' => [],
];

$allCampaigns = $clickExtractor->extractUniqueCampaigns($googleSourceIds);
$trackedCampaignIds = [];
foreach ($allCampaigns as $campaignData) {
    $trackedCampaignIds[(string)$campaignData['campaign_id']] = true;
}

foreach ($integrations as $integration) {
    $stats['integrations_processed']++;

    $integrationId = (int)$integration['id'];
    $customerId = GoogleAdsIntegration::normalizeCustomerId($integration['customer_id'] ?? '') ?? '';
    $integrationName = $integration['name'];

    $logger->logDetail("Processing Google Ads integration", [
        'name' => $integrationName,
        'id' => $integrationId,
        'customer_id' => $customerId,
    ]);

    if (!$googleAdsIntegration->saveConfigFile($integration)) {
        $stats['errors'][] = "Failed to save config file for integration {$integrationId}";
        continue;
    }

    $configPath = $googleAdsIntegration->getConfigFilePath($integrationId);
    $apiCallTracker = new GoogleAdsApiCallTracker($db);
    $apiClient = new GoogleAdsInsightsClient($configPath, $customerId, $apiCallTracker, $integrationId);

    if (!$apiClient->isInitialized()) {
        $stats['errors'][] = "Failed to initialize Google Ads client for integration {$integrationId}";
        continue;
    }

    $campaignsForCustomer = [];
    foreach ($allCampaigns as $campaignData) {
        $extractedCustomerId = $campaignData['customer_id'] ?? null;
        if ($extractedCustomerId === null || $extractedCustomerId === $customerId) {
            $campaignsForCustomer[] = $campaignData;
        }
    }

    if (empty($campaignsForCustomer) && empty($trackedCampaignIds)) {
        $logger->logDetail('No tracked campaigns in clicks for this integration; skipping API calls.', [
            'integration_id' => $integrationId,
        ]);
        continue;
    }

    $channelTypes = ['SEARCH', 'VIDEO', 'DISPLAY', 'PERFORMANCE_MAX', 'DEMAND_GEN'];

    foreach ($channelTypes as $channelType) {
        $apiResults = $apiClient->fetchCampaignSpend($queryDate, $channelType);

        foreach ($apiResults as $campaignData) {
            $campaignId = (string)($campaignData['campaign_id'] ?? '');
            if ($campaignId === '') {
                continue;
            }

            if (!empty($trackedCampaignIds) && !isset($trackedCampaignIds[$campaignId])) {
                continue;
            }

            $stats['campaigns_processed']++;

            $resolvedChannel = strtoupper((string)($campaignData['channel_type'] ?? $channelType));
            $allowedChannels = ['SEARCH', 'VIDEO', 'DISPLAY', 'SHOPPING', 'HOTEL', 'MULTI_CHANNEL', 'PERFORMANCE_MAX', 'DEMAND_GEN', 'UNKNOWN'];
            if (!in_array($resolvedChannel, $allowedChannels, true)) {
                $resolvedChannel = 'UNKNOWN';
            }

            try {
                $currentSpend = (float)($campaignData['cost'] ?? 0.0);
                $previousSpend = getLastHourSpend($db, $integrationId, $campaignId, $storageDate, $currentHour);
                $deltaSpend = $currentSpend - ($previousSpend ?? 0.0);
                if ($deltaSpend < 0) {
                    $deltaSpend = 0.0;
                }

                if (upsertCampaignHourlyCost(
                    $db,
                    $integrationId,
                    $customerId,
                    $campaignId,
                    (string)($campaignData['campaign_name'] ?? ''),
                    $resolvedChannel,
                    $storageDate,
                    $currentHour,
                    $currentSpend,
                    $deltaSpend,
                    (int)($campaignData['clicks'] ?? 0),
                    (int)($campaignData['impressions'] ?? 0),
                    (float)($campaignData['avg_cpc'] ?? 0.0),
                    (float)($campaignData['avg_cpm'] ?? 0.0),
                    (float)($campaignData['avg_cpv'] ?? 0.0)
                )) {
                    $stats['campaigns_success']++;
                } else {
                    $stats['campaigns_failed']++;
                    $stats['errors'][] = "Failed to upsert hourly cost for campaign {$campaignId}";
                }
            } catch (Throwable $e) {
                $stats['campaigns_failed']++;
                $stats['errors'][] = "Exception for campaign {$campaignId}: " . $e->getMessage();
                error_log("Google Ads Cost Updater: {$e->getMessage()}");
            }
        }
    }
}

$logger->logDetail('Google Ads Cost Updater completed', $stats);
error_log('Google Ads Cost Updater: Completed. Stats: ' . json_encode($stats));

$db->close();
exit(0);

function getLastHourSpend(mysqli $db, int $integrationId, string $campaignId, string $date, int $currentHour): ?float
{
    if ($currentHour > 0) {
        $previousHour = $currentHour - 1;
        $previousDate = $date;
    } else {
        $previousHour = 23;
        $previousDate = date('Y-m-d', strtotime($date . ' -1 day'));
    }

    $stmt = $db->prepare(
        'SELECT spend FROM google_campaign_hourly_costs
         WHERE integration_id = ? AND campaign_id = ? AND date = ? AND hour = ?
         ORDER BY id DESC LIMIT 1'
    );

    $stmt->bind_param('issi', $integrationId, $campaignId, $previousDate, $previousHour);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    return $row ? (float)$row['spend'] : null;
}

function upsertCampaignHourlyCost(
    mysqli $db,
    int $integrationId,
    string $customerId,
    string $campaignId,
    string $campaignName,
    string $channelType,
    string $date,
    int $hour,
    float $spend,
    float $deltaSpend,
    int $clicks,
    int $impressions,
    float $avgCpc,
    float $avgCpm,
    float $avgCpv
): bool {
    $stmt = $db->prepare(
        "INSERT INTO google_campaign_hourly_costs
         (integration_id, customer_id, campaign_id, campaign_name, advertising_channel_type,
          date, hour, spend, delta_spend, clicks, impressions, avg_cpc, avg_cpm, avg_cpv,
          last_synced, source)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'google_ads_api')
         ON DUPLICATE KEY UPDATE
         spend = VALUES(spend),
         delta_spend = VALUES(delta_spend),
         clicks = VALUES(clicks),
         impressions = VALUES(impressions),
         avg_cpc = VALUES(avg_cpc),
         avg_cpm = VALUES(avg_cpm),
         avg_cpv = VALUES(avg_cpv),
         campaign_name = VALUES(campaign_name),
         last_synced = NOW()"
    );

    $stmt->bind_param(
        'isssssidddiidd',
        $integrationId,
        $customerId,
        $campaignId,
        $campaignName,
        $channelType,
        $date,
        $hour,
        $spend,
        $deltaSpend,
        $clicks,
        $impressions,
        $avgCpc,
        $avgCpm,
        $avgCpv
    );

    return $stmt->execute();
}
