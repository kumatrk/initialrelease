<?php

declare(strict_types=1);

/**
 * Simple KUMA - Google Ads Conversions API Endpoint
 * CSV for scheduled imports / Data Manager HTTP connectors.
 *
 * Data Manager URL (path must end in .csv):
 *   /api/google-conversions.csv?key=...&camp=google
 * Apache rewrites that path here; do not put .csv on query params.
 *
 * Formats:
 *   format=datamanager (default) — GCLID, GBRAID, WBRAID as separate columns
 *   format=legacy — Google Click ID column (gclid-oriented schedules)
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

use SimpleKuma\Settings\SettingsManager;
use SimpleKuma\Entity\GoogleAdsIntegration;
use SimpleKuma\GoogleAds\GoogleAdsClickIdHelper;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

$settings = new SettingsManager($db);
$legacyGoogleConversionKey = $settings->get('google_conversion_key', '');

$googleAdsIntegration = new GoogleAdsIntegration($db);

$integrationKeyMap = [];
$integrationStmt = $db->prepare("SELECT id, conversion_key FROM google_ads_integrations");
$integrationStmt->execute();
$integrationResult = $integrationStmt->get_result();
while ($integrationRow = $integrationResult->fetch_assoc()) {
    $integrationKeyMap[$integrationRow['conversion_key']] = $integrationRow['id'];
}

$providedKey = (string) ($_GET['key'] ?? '');
$matchedIntegrationId = null;
$keyAccepted = false;

if ($providedKey !== '') {
    foreach ($integrationKeyMap as $conversionKey => $integrationId) {
        if (is_string($conversionKey) && $conversionKey !== '' && hash_equals($conversionKey, $providedKey)) {
            $matchedIntegrationId = $integrationId;
            $keyAccepted = true;
            break;
        }
    }
}

if (
    !$keyAccepted
    && !empty($legacyGoogleConversionKey)
    && $providedKey !== ''
    && hash_equals((string) $legacyGoogleConversionKey, $providedKey)
) {
    $matchedIntegrationId = null;
    $keyAccepted = true;
}

if (!$keyAccepted) {
    http_response_code(401);
    header('Content-Type: text/plain');
    echo "Unauthorized: Invalid or missing conversion key";
    exit;
}

$currency = strtoupper(trim($_GET['currency'] ?? 'USD'));
$customRevenue = isset($_GET['cstrev']) ? (float)$_GET['cstrev'] : null;
$days = (int)($_GET['days'] ?? 7);
$campaignFilter = trim($_GET['camp'] ?? 'google');
$eventType = trim($_GET['event'] ?? 'conversions');
$format = strtolower(trim($_GET['format'] ?? 'datamanager'));
if (!in_array($format, ['datamanager', 'legacy'], true)) {
    $format = 'datamanager';
}

if (!in_array($eventType, ['conversions', 'subscribers', 'clicks'], true)) {
    $eventType = 'conversions';
}

$dateFrom = date('Y-m-d H:i:s', strtotime("-{$days} days"));

$whereConditions = [];
$bindParams = [];
$bindTypes = '';

if ($campaignFilter === 'google') {
    // Match cost identifier: Google + YouTube traffic sources
    $whereConditions[] = "cp.traffic_source_id IN (
        SELECT id FROM traffic_sources
        WHERE (LOWER(name) LIKE '%google%' OR LOWER(name) LIKE '%youtube%')
          AND LOWER(name) NOT LIKE '%facebook%'
    )";
} elseif (is_numeric($campaignFilter)) {
    $whereConditions[] = "cp.id = ?";
    $bindParams[] = (int)$campaignFilter;
    $bindTypes .= 'i';
} elseif (strpos($campaignFilter, ',') !== false) {
    $campaignIds = array_filter(array_map('intval', explode(',', $campaignFilter)));
    if (!empty($campaignIds)) {
        $placeholders = implode(',', array_fill(0, count($campaignIds), '?'));
        $whereConditions[] = "cp.id IN ($placeholders)";
        foreach ($campaignIds as $campId) {
            $bindParams[] = (int)$campId;
            $bindTypes .= 'i';
        }
    }
}

// Any of gclid / gbraid / wbraid in tokens or all_params
$whereConditions[] = "(
    (
        JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.gclid')) IS NOT NULL
        AND JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.gclid')) != ''
        AND JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.gclid')) != 'null'
    ) OR (
        JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.gbraid')) IS NOT NULL
        AND JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.gbraid')) != ''
        AND JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.gbraid')) != 'null'
    ) OR (
        JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.wbraid')) IS NOT NULL
        AND JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.wbraid')) != ''
        AND JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.traffic_source_tokens.wbraid')) != 'null'
    ) OR (
        JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.all_params.gclid')) IS NOT NULL
        AND JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.all_params.gclid')) != ''
        AND JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.all_params.gclid')) != 'null'
    ) OR (
        JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.all_params.gbraid')) IS NOT NULL
        AND JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.all_params.gbraid')) != ''
        AND JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.all_params.gbraid')) != 'null'
    ) OR (
        JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.all_params.wbraid')) IS NOT NULL
        AND JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.all_params.wbraid')) != ''
        AND JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, '$.all_params.wbraid')) != 'null'
    )
)";

if ($matchedIntegrationId !== null) {
    $whereConditions[] = "cp.google_ads_integration_id = ?";
    $bindParams[] = $matchedIntegrationId;
    $bindTypes .= 'i';
}

if ($eventType === 'conversions') {
    $query = "
        SELECT
            c.id as order_id,
            c.click_id,
            c.ts as conversion_time,
            c.value as conversion_value,
            c.currency,
            cl.extra_json,
            cp.traffic_source_id,
            ts.name as traffic_source_name
        FROM conversions c
        INNER JOIN clicks cl ON c.click_id = cl.click_id
        INNER JOIN campaigns cp ON cl.campaign_id = cp.id
        LEFT JOIN traffic_sources ts ON cp.traffic_source_id = ts.id
        WHERE c.ts >= ?
            AND c.status = 'approved'
            AND " . implode(' AND ', $whereConditions) . "
        ORDER BY c.ts DESC
    ";
} elseif ($eventType === 'subscribers') {
    $query = "
        SELECT
            cl.click_id as order_id,
            cl.click_id,
            cl.ts_lp as conversion_time,
            0 as conversion_value,
            'USD' as currency,
            cl.extra_json,
            cp.traffic_source_id,
            ts.name as traffic_source_name
        FROM clicks cl
        INNER JOIN campaigns cp ON cl.campaign_id = cp.id
        LEFT JOIN traffic_sources ts ON cp.traffic_source_id = ts.id
        WHERE cl.ts >= ?
            AND cl.lp_click = 1
            AND " . implode(' AND ', $whereConditions) . "
        ORDER BY cl.ts_lp DESC
    ";
} else {
    $query = "
        SELECT
            cl.click_id as order_id,
            cl.click_id,
            cl.ts as conversion_time,
            0 as conversion_value,
            'USD' as currency,
            cl.extra_json,
            cp.traffic_source_id,
            ts.name as traffic_source_name
        FROM clicks cl
        INNER JOIN campaigns cp ON cl.campaign_id = cp.id
        LEFT JOIN traffic_sources ts ON cp.traffic_source_id = ts.id
        WHERE cl.ts >= ?
            AND " . implode(' AND ', $whereConditions) . "
        ORDER BY cl.ts DESC
    ";
}

$stmt = $db->prepare($query);
if (!$stmt) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "Database query error: " . $db->error;
    exit;
}

$allBindParams = [$dateFrom];
$allBindTypes = 's' . $bindTypes;
if (!empty($bindParams)) {
    $allBindParams = array_merge($allBindParams, $bindParams);
}
$stmt->bind_param($allBindTypes, ...$allBindParams);
$stmt->execute();
$result = $stmt->get_result();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: inline; filename="google-conversions.csv"');

if ($format === 'legacy') {
    echo "Google Click ID,Conversion Time,Conversion Value,Currency,Order ID\n";
} else {
    echo "GCLID,GBRAID,WBRAID,Conversion Time,Conversion Value,Conversion Currency,Order ID\n";
}

while ($row = $result->fetch_assoc()) {
    $ids = GoogleAdsClickIdHelper::extractFromExtraJson($row['extra_json'] ?? null);
    if (!GoogleAdsClickIdHelper::hasAny($ids)) {
        continue;
    }

    // Legacy schedules expect gclid in Google Click ID; skip braid-only rows
    if ($format === 'legacy' && empty($ids['gclid'])) {
        continue;
    }

    $conversionTime = date('Y-m-d H:i:s+00:00', strtotime($row['conversion_time']));

    if ($customRevenue !== null) {
        $conversionValue = max(0, $customRevenue);
        $conversionCurrency = $currency;
    } else {
        $conversionValue = max(0, (float)($row['conversion_value'] ?? 0));
        $conversionCurrency = strtoupper($row['currency'] ?? $currency);
    }

    $orderId = (string)($row['order_id'] ?? $row['click_id'] ?? '');

    if ($format === 'legacy') {
        echo sprintf(
            "%s,%s,%.2f,%s,%s\n",
            $ids['gclid'],
            $conversionTime,
            $conversionValue,
            $conversionCurrency,
            $orderId
        );
    } else {
        echo sprintf(
            "%s,%s,%s,%s,%.2f,%s,%s\n",
            $ids['gclid'] ?? '',
            $ids['gbraid'] ?? '',
            $ids['wbraid'] ?? '',
            $conversionTime,
            $conversionValue,
            $conversionCurrency,
            $orderId
        );
    }
}

$stmt->close();
$db->close();
