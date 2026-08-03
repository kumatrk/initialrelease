<?php

declare(strict_types=1);

namespace SimpleKuma\Facebook;

use mysqli;

/**
 * Facebook Cost Aggregator
 * Aggregates costs from both manual (clicks.cost) and Facebook API (hourly cost tables) sources
 */
class FacebookCostAggregator
{
    private mysqli $db;
    private ?bool $trafficSourceColumnExists = null;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Check if traffic_source_id column exists in clicks table
     */
    private function checkTrafficSourceColumnExists(): bool
    {
        if ($this->trafficSourceColumnExists === null) {
            $checkColumn = $this->db->query("SELECT COUNT(*) as count FROM information_schema.COLUMNS 
                                            WHERE TABLE_SCHEMA = DATABASE() 
                                            AND TABLE_NAME = 'clicks' 
                                            AND COLUMN_NAME = 'traffic_source_id'");
            if ($checkColumn && $row = $checkColumn->fetch_assoc()) {
                $this->trafficSourceColumnExists = ($row['count'] > 0);
            } else {
                $this->trafficSourceColumnExists = false;
            }
        }
        return $this->trafficSourceColumnExists;
    }

    /**
     * Get total cost for a click based on click_id
     * Returns manual cost + Facebook API cost (if applicable)
     */
    public function getTotalCostForClick(string $clickId): float
    {
        // Get manual cost from clicks table
        $clickQuery = $this->db->prepare("SELECT cost FROM clicks WHERE click_id = ?");
        $clickQuery->bind_param('s', $clickId);
        $clickQuery->execute();
        $clickResult = $clickQuery->get_result()->fetch_assoc();
        $manualCost = (float)($clickResult['cost'] ?? 0.0);

        // Get Facebook API cost from hourly tables
        // This requires extracting adset_id and ad_id from extra_json
        $fbCost = $this->getFacebookCostForClick($clickId);

        return $manualCost + $fbCost;
    }

    /**
     * Get total costs for multiple clicks in a single batch query
     * PERFORMANCE: This method batches cost lookups to avoid thousands of individual queries
     * Returns array mapping click_id => total_cost (manual + Facebook API cost)
     * 
     * @param array $clickIds Array of click_id strings
     * @return array Associative array: ['click_id' => total_cost, ...]
     */
    public function getTotalCostsForClicks(array $clickIds): array
    {
        if (empty($clickIds)) {
            return [];
        }

        // Remove duplicates and sanitize
        $clickIds = array_unique(array_filter($clickIds));
        if (empty($clickIds)) {
            return [];
        }

        $results = [];
        
        // Step 1: Get manual costs for all clicks in one query
        $placeholders = str_repeat('?,', count($clickIds) - 1) . '?';
        $manualCostQuery = $this->db->prepare("
            SELECT click_id, cost 
            FROM clicks 
            WHERE click_id IN ($placeholders)
        ");
        $types = str_repeat('s', count($clickIds));
        $manualCostQuery->bind_param($types, ...$clickIds);
        $manualCostQuery->execute();
        $manualCostResult = $manualCostQuery->get_result();
        
        $manualCosts = [];
        while ($row = $manualCostResult->fetch_assoc()) {
            $manualCosts[$row['click_id']] = (float)($row['cost'] ?? 0.0);
            // Initialize results array
            $results[$row['click_id']] = (float)($row['cost'] ?? 0.0);
        }
        
        // Step 2: Get Facebook costs for all clicks in batch
        // PERFORMANCE: Use generated columns (ad_id, adset_id) instead of JSON_EXTRACT for index usage
        $fbCosts = $this->getFacebookCostsForClicks($clickIds);
        
        // Step 3: Combine manual and Facebook costs
        foreach ($clickIds as $clickId) {
            $manualCost = $manualCosts[$clickId] ?? 0.0;
            $fbCost = $fbCosts[$clickId] ?? 0.0;
            $results[$clickId] = $manualCost + $fbCost;
        }
        
        return $results;
    }

    /**
     * Get Facebook API costs for multiple clicks in a single batch query
     * PERFORMANCE: Batches all cost lookups to avoid thousands of individual queries
     * 
     * @param array $clickIds Array of click_id strings
     * @return array Associative array: ['click_id' => facebook_cost, ...]
     */
    private function getFacebookCostsForClicks(array $clickIds): array
    {
        if (empty($clickIds)) {
            return [];
        }

        $results = [];
        $placeholders = str_repeat('?,', count($clickIds) - 1) . '?';
        $types = str_repeat('s', count($clickIds));
        
        // Step 1: Get click data for all clicks (ad_id, adset_id, date, hour, integration_id)
        // PERFORMANCE: Use generated columns (ad_id, adset_id) instead of JSON_EXTRACT for index usage
        $clickDataQuery = $this->db->prepare("
            SELECT 
                c.click_id,
                c.ad_id,
                c.adset_id,
                DATE(c.ts) as click_date,
                HOUR(c.ts) as click_hour,
                fmaa.facebook_marketing_integration_id
            FROM clicks c
            LEFT JOIN campaigns camp ON c.campaign_id = camp.id
            LEFT JOIN facebook_marketing_ad_accounts fmaa ON camp.facebook_marketing_ad_account_id = fmaa.id
            WHERE c.click_id IN ($placeholders)
            AND (c.ad_id IS NOT NULL OR c.adset_id IS NOT NULL)
        ");
        $clickDataQuery->bind_param($types, ...$clickIds);
        $clickDataQuery->execute();
        $clickDataResult = $clickDataQuery->get_result();
        
        $clickData = [];
        $adsetGroups = []; // Group by (adset_id, date, hour, integration_id)
        $adGroups = [];    // Group by (ad_id, date, hour, integration_id)
        
        while ($row = $clickDataResult->fetch_assoc()) {
            $clickId = $row['click_id'];
            // PERFORMANCE: Generated columns return integers, not strings - convert to string for processing
            $adsetIdRaw = $row['adset_id'] ?? null;
            $adIdRaw = $row['ad_id'] ?? null;
            
            // Convert to string and validate (generated columns are integers, but cost tables use string IDs)
            $adsetId = $adsetIdRaw !== null ? trim((string)$adsetIdRaw) : '';
            $adId = $adIdRaw !== null ? trim((string)$adIdRaw) : '';
            
            $clickDate = $row['click_date'];
            $clickHour = (int)($row['click_hour'] ?? 0);
            $integrationId = !empty($row['facebook_marketing_integration_id']) ? (int)$row['facebook_marketing_integration_id'] : null;
            
            // Skip invalid IDs
            if (empty($adsetId) || $adsetId === 'null' || strpos($adsetId, '{{') !== false || strpos($adsetId, '{ts:') !== false) {
                $adsetId = null;
            }
            if (empty($adId) || $adId === 'null' || strpos($adId, '{{') !== false || strpos($adId, '{ts:') !== false) {
                $adId = null;
            }
            
            $clickData[$clickId] = [
                'adset_id' => $adsetId,
                'ad_id' => $adId,
                'date' => $clickDate,
                'hour' => $clickHour,
                'integration_id' => $integrationId
            ];
            
            // Group by adset for batch cost lookup
            if ($adsetId) {
                $adsetKey = $adsetId . '|' . $clickDate . '|' . $clickHour . '|' . ($integrationId ?? 'null');
                if (!isset($adsetGroups[$adsetKey])) {
                    $adsetGroups[$adsetKey] = [
                        'adset_id' => $adsetId,
                        'date' => $clickDate,
                        'hour' => $clickHour,
                        'integration_id' => $integrationId,
                        'click_ids' => []
                    ];
                }
                $adsetGroups[$adsetKey]['click_ids'][] = $clickId;
            }
            
            // Group by ad for batch cost lookup (fallback if no adset cost)
            if ($adId) {
                $adKey = $adId . '|' . $clickDate . '|' . $clickHour . '|' . ($integrationId ?? 'null');
                if (!isset($adGroups[$adKey])) {
                    $adGroups[$adKey] = [
                        'ad_id' => $adId,
                        'date' => $clickDate,
                        'hour' => $clickHour,
                        'integration_id' => $integrationId,
                        'click_ids' => []
                    ];
                }
                $adGroups[$adKey]['click_ids'][] = $clickId;
            }
        }
        
        // Step 2: Batch lookup adset costs
        $adsetCosts = $this->getBatchAdsetHourlyCosts($adsetGroups);
        
        // Step 3: Batch lookup ad costs (for clicks without adset costs)
        $adCosts = $this->getBatchAdHourlyCosts($adGroups);
        
        // Step 4: Assign costs to clicks (prefer adset cost, fallback to ad cost)
        foreach ($clickData as $clickId => $data) {
            $totalCost = 0.0;
            
            // Try adset cost first
            if ($data['adset_id']) {
                $adsetKey = $data['adset_id'] . '|' . $data['date'] . '|' . $data['hour'] . '|' . ($data['integration_id'] ?? 'null');
                if (isset($adsetCosts[$adsetKey])) {
                    $totalCost = $adsetCosts[$adsetKey];
                }
            }
            
            // Fallback to ad cost if no adset cost
            if ($totalCost <= 0 && $data['ad_id']) {
                $adKey = $data['ad_id'] . '|' . $data['date'] . '|' . $data['hour'] . '|' . ($data['integration_id'] ?? 'null');
                if (isset($adCosts[$adKey])) {
                    $totalCost = $adCosts[$adKey];
                }
            }
            
            $results[$clickId] = $totalCost;
        }
        
        return $results;
    }

    /**
     * Batch lookup adset hourly costs for multiple (adset_id, date, hour) combinations
     * Divides hourly spend by number of clicks in that hour for accurate per-click costs
     * 
     * @param array $adsetGroups Array of groups: ['key' => ['adset_id' => ..., 'date' => ..., 'hour' => ..., 'integration_id' => ..., 'click_ids' => [...]], ...]
     * @return array Associative array: ['adset_id|date|hour|integration_id' => per_click_cost, ...]
     */
    private function getBatchAdsetHourlyCosts(array $adsetGroups): array
    {
        if (empty($adsetGroups)) {
            return [];
        }
        
        $results = [];
        
        // Build query to get all adset costs at once
        $adsetConditions = [];
        $adsetParams = [];
        $adsetTypes = '';
        
        foreach ($adsetGroups as $group) {
            if ($group['integration_id'] !== null) {
                $adsetConditions[] = "(adset_id = ? AND date = ? AND hour = ? AND ad_account_id = ?)";
                $adsetParams[] = $group['adset_id'];
                $adsetParams[] = $group['date'];
                $adsetParams[] = $group['hour'];
                $adsetParams[] = $group['integration_id'];
                $adsetTypes .= 'ssii';
            } else {
                $adsetConditions[] = "(adset_id = ? AND date = ? AND hour = ? AND (ad_account_id IS NULL OR ad_account_id = 0))";
                $adsetParams[] = $group['adset_id'];
                $adsetParams[] = $group['date'];
                $adsetParams[] = $group['hour'];
                $adsetTypes .= 'ssi';
            }
        }
        
        if (empty($adsetConditions)) {
            return [];
        }
        
        // Get hourly costs
        $costQuery = "
            SELECT adset_id, date, hour, ad_account_id, SUM(delta_spend) as total_cost
            FROM adset_hourly_costs
            WHERE " . implode(' OR ', $adsetConditions) . "
            GROUP BY adset_id, date, hour, ad_account_id
        ";
        $costStmt = $this->db->prepare($costQuery);
        $costStmt->bind_param($adsetTypes, ...$adsetParams);
        $costStmt->execute();
        $costResult = $costStmt->get_result();
        
        $hourlyCosts = [];
        while ($row = $costResult->fetch_assoc()) {
            $key = $row['adset_id'] . '|' . $row['date'] . '|' . $row['hour'] . '|' . ($row['ad_account_id'] ?? 'null');
            $hourlyCosts[$key] = (float)($row['total_cost'] ?? 0.0);
        }
        
        // Count clicks per (adset_id, date, hour) and calculate per-click costs
        // PERFORMANCE: Use generated columns (adset_id) instead of JSON_EXTRACT for index usage
        foreach ($adsetGroups as $key => $group) {
            $hourlyCost = $hourlyCosts[$key] ?? 0.0;
            
            if ($hourlyCost <= 0) {
                $results[$key] = 0.0;
                continue;
            }
            
            // Count clicks in this hour for this adset
            $countQuery = "
                SELECT COUNT(*) as click_count
                FROM clicks
                WHERE adset_id = ?
                    AND DATE(ts) = ?
                    AND HOUR(ts) = ?
                    AND adset_id IS NOT NULL
            ";
            $countStmt = $this->db->prepare($countQuery);
            $countStmt->bind_param('ssi', $group['adset_id'], $group['date'], $group['hour']);
            $countStmt->execute();
            $countResult = $countStmt->get_result()->fetch_assoc();
            $clickCount = (int)($countResult['click_count'] ?? 1);
            
            // Divide hourly spend by click count to get per-click cost
            $perClickCost = $hourlyCost / max($clickCount, 1);
            $results[$key] = $perClickCost;
        }
        
        return $results;
    }

    /**
     * Batch lookup ad hourly costs for multiple (ad_id, date, hour) combinations
     * Divides hourly spend by number of clicks in that hour for accurate per-click costs
     * 
     * @param array $adGroups Array of groups: ['key' => ['ad_id' => ..., 'date' => ..., 'hour' => ..., 'integration_id' => ..., 'click_ids' => [...]], ...]
     * @return array Associative array: ['ad_id|date|hour|integration_id' => per_click_cost, ...]
     */
    private function getBatchAdHourlyCosts(array $adGroups): array
    {
        if (empty($adGroups)) {
            return [];
        }
        
        $results = [];
        
        // Build query to get all ad costs at once
        $adConditions = [];
        $adParams = [];
        $adTypes = '';
        
        foreach ($adGroups as $group) {
            if ($group['integration_id'] !== null) {
                $adConditions[] = "(ad_id = ? AND date = ? AND hour = ? AND ad_account_id = ?)";
                $adParams[] = $group['ad_id'];
                $adParams[] = $group['date'];
                $adParams[] = $group['hour'];
                $adParams[] = $group['integration_id'];
                $adTypes .= 'ssii';
            } else {
                $adConditions[] = "(ad_id = ? AND date = ? AND hour = ? AND (ad_account_id IS NULL OR ad_account_id = 0))";
                $adParams[] = $group['ad_id'];
                $adParams[] = $group['date'];
                $adParams[] = $group['hour'];
                $adTypes .= 'ssi';
            }
        }
        
        if (empty($adConditions)) {
            return [];
        }
        
        // Get hourly costs
        $costQuery = "
            SELECT ad_id, date, hour, ad_account_id, SUM(delta_spend) as total_cost
            FROM ad_hourly_costs
            WHERE " . implode(' OR ', $adConditions) . "
            GROUP BY ad_id, date, hour, ad_account_id
        ";
        $costStmt = $this->db->prepare($costQuery);
        $costStmt->bind_param($adTypes, ...$adParams);
        $costStmt->execute();
        $costResult = $costStmt->get_result();
        
        $hourlyCosts = [];
        while ($row = $costResult->fetch_assoc()) {
            $key = $row['ad_id'] . '|' . $row['date'] . '|' . $row['hour'] . '|' . ($row['ad_account_id'] ?? 'null');
            $hourlyCosts[$key] = (float)($row['total_cost'] ?? 0.0);
        }
        
        // Count clicks per (ad_id, date, hour) and calculate per-click costs
        // PERFORMANCE: Use generated columns (ad_id) instead of JSON_EXTRACT for index usage
        foreach ($adGroups as $key => $group) {
            $hourlyCost = $hourlyCosts[$key] ?? 0.0;
            
            if ($hourlyCost <= 0) {
                $results[$key] = 0.0;
                continue;
            }
            
            // Count clicks in this hour for this ad
            $countQuery = "
                SELECT COUNT(*) as click_count
                FROM clicks
                WHERE ad_id = ?
                    AND DATE(ts) = ?
                    AND HOUR(ts) = ?
                    AND ad_id IS NOT NULL
            ";
            $countStmt = $this->db->prepare($countQuery);
            $countStmt->bind_param('ssi', $group['ad_id'], $group['date'], $group['hour']);
            $countStmt->execute();
            $countResult = $countStmt->get_result()->fetch_assoc();
            $clickCount = (int)($countResult['click_count'] ?? 1);
            
            // Divide hourly spend by click count to get per-click cost
            $perClickCost = $hourlyCost / max($clickCount, 1);
            $results[$key] = $perClickCost;
        }
        
        return $results;
    }

    /**
     * Get Facebook API cost for a specific click
     */
    public function getFacebookCostForClick(string $clickId): float
    {
        // Extract adset_id, ad_id, campaign_id from clicks
        // Get integration ID from campaign's linked ad account
        $query = "
            SELECT 
                JSON_UNQUOTE(JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.adset_id')) as adset_id,
                JSON_UNQUOTE(JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.ad_id')) as ad_id,
                c.campaign_id,
                DATE(c.ts) as click_date,
                HOUR(c.ts) as click_hour,
                fmaa.facebook_marketing_integration_id
            FROM clicks c
            LEFT JOIN campaigns camp ON c.campaign_id = camp.id
            LEFT JOIN facebook_marketing_ad_accounts fmaa ON camp.facebook_marketing_ad_account_id = fmaa.id
            WHERE c.click_id = ?
        ";

        $stmt = $this->db->prepare($query);
        $stmt->bind_param('s', $clickId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        if (!$result) {
            return 0.0;
        }

        $adsetId = trim($result['adset_id'] ?? '');
        $adId = trim($result['ad_id'] ?? '');
        $clickDate = $result['click_date'];
        $clickHour = (int)($result['click_hour'] ?? 0);
        $integrationId = !empty($result['facebook_marketing_integration_id']) ? (int)$result['facebook_marketing_integration_id'] : null;

        $totalCost = 0.0;
        $adCost = 0.0;
        $adsetCost = 0.0;

        // Get ad-level cost if available
        // Skip placeholder variables - they should never have costs assigned
        if (!empty($adId) && $adId !== 'null' && strpos($adId, '{{') === false && strpos($adId, '{ts:') === false) {
            $adCost = $this->getAdHourlyCost($adId, $clickDate, $clickHour, $integrationId);
        }

        // Get adset-level cost if available
        // Skip placeholder variables - they should never have costs assigned
        if (!empty($adsetId) && $adsetId !== 'null' && strpos($adsetId, '{{') === false && strpos($adsetId, '{ts:') === false) {
            $adsetCost = $this->getAdsetHourlyCost($adsetId, $clickDate, $clickHour, $integrationId);
        }

        // Always prefer adset cost when available, as it represents the true total spend for the adset
        // This ensures accurate cost distribution when multiple ads exist in an adset
        // Example: Adset has $81.12 total, Ad1 has $60.27 (1 click), Ad2 has $20.85 (0 clicks)
        // Using adset cost ($81.12) ensures the click gets the full cost, not just Ad1's portion
        // UPDATED 2025-12-01: Always use adset cost when available to prevent over-counting
        if ($adsetCost > 0) {
            $totalCost = $adsetCost;
        } else {
            $totalCost = $adCost;
        }

        return $totalCost;
    }

    /**
     * Get adset hourly cost for a specific hour
     * Divides hourly spend by number of clicks in that hour for accurate per-click costs
     * @param string $adsetId Facebook adset ID
     * @param string $date Date (YYYY-MM-DD)
     * @param int $hour Hour (0-23)
     * @param int|null $integrationId Integration ID (if available, filters by ad_account_id)
     */
    private function getAdsetHourlyCost(string $adsetId, string $date, int $hour, ?int $integrationId = null): float
    {
        if ($integrationId !== null) {
            // Match by ad_account_id (integration ID) for accurate multi-account support
            $query = "
                SELECT SUM(delta_spend) as total_cost
                FROM adset_hourly_costs
                WHERE adset_id = ? AND date = ? AND hour = ? AND ad_account_id = ?
            ";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ssii', $adsetId, $date, $hour, $integrationId);
        } else {
            // Fallback: match without ad_account_id (for backward compatibility)
            $query = "
                SELECT SUM(delta_spend) as total_cost
                FROM adset_hourly_costs
                WHERE adset_id = ? AND date = ? AND hour = ?
            ";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ssi', $adsetId, $date, $hour);
        }

        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $totalHourlySpend = (float)($result['total_cost'] ?? 0.0);

        if ($totalHourlySpend <= 0) {
            return 0.0;
        }

        // Count clicks in this hour for this adset
        // Exclude placeholder variables - they should not be counted when dividing costs
        $countQuery = "
            SELECT COUNT(*) as click_count
            FROM clicks
            WHERE JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.adset_id')) = ?
                AND DATE(ts) = ?
                AND HOUR(ts) = ?
                AND JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.adset_id')) NOT LIKE '{{%'
                AND JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.adset_id')) NOT LIKE '{ts:%'
        ";
        
        $countStmt = $this->db->prepare($countQuery);
        $countStmt->bind_param('ssi', $adsetId, $date, $hour);
        $countStmt->execute();
        $countResult = $countStmt->get_result()->fetch_assoc();
        $clickCount = (int)($countResult['click_count'] ?? 1);

        // Divide hourly spend by click count to get per-click cost
        // If no clicks found, return 0 (shouldn't happen, but safety check)
        if ($clickCount <= 0) {
            return 0.0;
        }

        return $totalHourlySpend / $clickCount;
    }

    /**
     * Get ad hourly cost for a specific hour
     * Divides hourly spend by number of clicks in that hour for accurate per-click costs
     * @param string $adId Facebook ad ID
     * @param string $date Date (YYYY-MM-DD)
     * @param int $hour Hour (0-23)
     * @param int|null $integrationId Integration ID (if available, filters by ad_account_id)
     */
    private function getAdHourlyCost(string $adId, string $date, int $hour, ?int $integrationId = null): float
    {
        if ($integrationId !== null) {
            // Match by ad_account_id (integration ID) for accurate multi-account support
            $query = "
                SELECT SUM(delta_spend) as total_cost
                FROM ad_hourly_costs
                WHERE ad_id = ? AND date = ? AND hour = ? AND ad_account_id = ?
            ";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ssii', $adId, $date, $hour, $integrationId);
        } else {
            // Fallback: match without ad_account_id (for backward compatibility)
            $query = "
                SELECT SUM(delta_spend) as total_cost
                FROM ad_hourly_costs
                WHERE ad_id = ? AND date = ? AND hour = ?
            ";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ssi', $adId, $date, $hour);
        }

        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $totalHourlySpend = (float)($result['total_cost'] ?? 0.0);

        if ($totalHourlySpend <= 0) {
            return 0.0;
        }

        // Count clicks in this hour for this ad
        // Exclude placeholder variables - they should not be counted when dividing costs
        $countQuery = "
            SELECT COUNT(*) as click_count
            FROM clicks
            WHERE JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.ad_id')) = ?
                AND DATE(ts) = ?
                AND HOUR(ts) = ?
                AND JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.ad_id')) NOT LIKE '{{%'
                AND JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.ad_id')) NOT LIKE '{ts:%'
        ";
        
        $countStmt = $this->db->prepare($countQuery);
        $countStmt->bind_param('ssi', $adId, $date, $hour);
        $countStmt->execute();
        $countResult = $countStmt->get_result()->fetch_assoc();
        $clickCount = (int)($countResult['click_count'] ?? 1);

        // Divide hourly spend by click count to get per-click cost
        // If no clicks found, return 0 (shouldn't happen, but safety check)
        if ($clickCount <= 0) {
            return 0.0;
        }

        return $totalHourlySpend / $clickCount;
    }

    /**
     * Get unmatched costs using cumulative spend (MAX spend per adset)
     * This is used for costs that don't have matching clicks in the date range
     * Uses MAX(spend) instead of SUM(delta_spend) because cumulative is more accurate
     * for costs without clicks (represents true total spend from Meta API)
     * 
     * @param string $dateFrom UTC start timestamp
     * @param string $dateTo UTC end timestamp
     * @param string|null $campaignFilter Optional campaign filter SQL (with ? placeholders)
     * @param array $campaignFilterParams Optional parameters for campaign filter
     * @return float Total unmatched costs using cumulative spend
     */
    private function getUnmatchedCostsWithCumulative(
        string $dateFrom, 
        string $dateTo, 
        ?string $campaignFilter = null, 
        array $campaignFilterParams = [],
        ?string $userTimezone = null,
        ?string $userSelectedDateFrom = null,
        ?string $userSelectedDateTo = null,
        ?string $timezoneOffset = null
    ): float {
        // Extract campaign ID from filter if provided (for attribution)
        $campaignIdForAttribution = null;
        if ($campaignFilter && preg_match('/campaign_id\s*=\s*\?/i', $campaignFilter)) {
            // Find the parameter index for campaign_id
            $beforeCampaignId = substr($campaignFilter, 0, strpos($campaignFilter, 'campaign_id'));
            $paramIndex = preg_match_all('/\?/', $beforeCampaignId);
            $campaignIdForAttribution = $campaignFilterParams[$paramIndex] ?? null;
        }
        
        // Query for unmatched costs using MAX(spend) per adset
        // Unmatched = costs in date range that don't have matching clicks in the date range
        // For campaign attribution: if campaign filter provided, only include costs from adsets
        // that have clicks in that campaign (any time, not just date range)
        // Extract date and hour from timestamps for index-friendly query
        $dateFromDate = substr($dateFrom, 0, 10);
        $dateFromHour = (int)substr($dateFrom, 11, 2);
        $dateToDate = substr($dateTo, 0, 10);
        $dateToHour = (int)substr($dateTo, 11, 2);
        
        // CRITICAL FIX: Calculate missing timezone parameters if userTimezone is provided
        // This ensures timezone date grouping is always used when a user timezone is set
        if ($userTimezone !== null && $userTimezone !== 'UTC') {
            // Calculate timezone offset if missing
            if ($timezoneOffset === null) {
                try {
                    $tz = new \DateTimeZone($userTimezone);
                    $utcTz = new \DateTimeZone('UTC');
                    $testDate = new \DateTime($dateFrom, $utcTz);
                    $testDate->setTimezone($tz);
                    $offset = $tz->getOffset($testDate);
                    $hours = intval($offset / 3600);
                    $minutes = intval(($offset % 3600) / 60);
                    $timezoneOffset = sprintf('%+03d:%02d', $hours, abs($minutes));
                } catch (\Exception $e) {
                    $timezoneOffset = '+00:00';
                }
            }
            
            // Calculate user selected dates if missing
            if ($userSelectedDateFrom === null || $userSelectedDateTo === null) {
                try {
                    $tz = new \DateTimeZone($userTimezone);
                    $utcTz = new \DateTimeZone('UTC');
                    
                    $utcStart = new \DateTime($dateFrom, $utcTz);
                    $utcStart->setTimezone($tz);
                    $userSelectedDateFrom = $utcStart->format('Y-m-d');
                    
                    $utcEnd = new \DateTime($dateTo, $utcTz);
                    $utcEnd->setTimezone($tz);
                    $userSelectedDateTo = $utcEnd->format('Y-m-d');
                } catch (\Exception $e) {
                    // If conversion fails, fall back to UTC
                    $userSelectedDateFrom = null;
                    $userSelectedDateTo = null;
                }
            }
        }
        
        // Determine if we should use timezone date grouping or UTC range
        // CRITICAL: Always use timezone grouping when userTimezone is provided (now that we've calculated missing params)
        $useTimezoneDateGrouping = ($userTimezone !== null && $userTimezone !== 'UTC' && $userSelectedDateFrom !== null && $userSelectedDateTo !== null && $timezoneOffset !== null);
        
        $query = "
            SELECT COALESCE(SUM(max_spend), 0) as unmatched_cumulative_cost
            FROM (
                SELECT MAX(as_cost.spend) as max_spend
                FROM adset_hourly_costs as_cost
                WHERE " . ($useTimezoneDateGrouping ? 
                    "DATE(CONVERT_TZ(CONCAT(as_cost.date, ' ', LPAD(as_cost.hour, 2, '0'), ':00:00'), '+00:00', ?)) >= ? AND DATE(CONVERT_TZ(CONCAT(as_cost.date, ' ', LPAD(as_cost.hour, 2, '0'), ':00:00'), '+00:00', ?)) <= ?" :
                    "(as_cost.date, as_cost.hour) >= (?, ?) AND (as_cost.date, as_cost.hour) <= (?, ?)") . "
                    AND as_cost.adset_id NOT LIKE '{{%' AND as_cost.adset_id NOT LIKE '{ts:%'
                    -- Exclude adsets that have clicks in the date range (already counted in matched costs)
                    AND NOT EXISTS (
                        SELECT 1 FROM clicks c
                        INNER JOIN campaigns camp ON c.campaign_id = camp.id
                        LEFT JOIN facebook_marketing_ad_accounts fmaa ON camp.facebook_marketing_ad_account_id = fmaa.id
                        WHERE c.adset_id = as_cost.adset_id
                            AND c.adset_id IS NOT NULL
                            -- PERFORMANCE: Use range query instead of DATE()/HOUR() to enable index usage
                            -- PERFORMANCE: Use generated columns (adset_id, ad_id) instead of JSON_EXTRACT for index usage
                            AND c.ts >= CONCAT(as_cost.date, ' ', LPAD(as_cost.hour, 2, '0'), ':00:00')
                            AND c.ts < DATE_ADD(CONCAT(as_cost.date, ' ', LPAD(as_cost.hour, 2, '0'), ':00:00'), INTERVAL 1 HOUR)
                            AND c.ts >= ? AND c.ts <= ?
                            AND (fmaa.facebook_marketing_integration_id IS NULL OR as_cost.ad_account_id = fmaa.facebook_marketing_integration_id)
                            " . ($campaignFilter ? str_replace('cl.', 'c.', $campaignFilter) : '') . "
                    )
                    " . ($campaignIdForAttribution !== null ? "
                    -- Campaign attribution: Only include costs from adsets that have clicks in this campaign (any time)
                    -- This attributes unmatched costs to the correct campaign when possible
                    AND EXISTS (
                        SELECT 1 FROM clicks c2
                        WHERE c2.adset_id = as_cost.adset_id
                            AND c2.adset_id IS NOT NULL
                            -- PERFORMANCE: Use generated column (adset_id) instead of JSON_EXTRACT for index usage
                            AND c2.campaign_id = ?
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM clicks c3
                        WHERE c3.adset_id = as_cost.adset_id
                            AND c3.adset_id IS NOT NULL
                            -- PERFORMANCE: Use generated column (adset_id) instead of JSON_EXTRACT for index usage
                            AND c3.campaign_id != ?
                    )
                    " : "
                    -- CRITICAL: Include ALL costs without clicks in date range, even if adset has no clicks at any time
                    -- This ensures gutters and other spend without clicks is included in overall total
                    ") . "
                GROUP BY as_cost.adset_id
            ) as unmatched_adsets
        ";

        $stmt = $this->db->prepare($query);
        
        // Bind parameters based on query type
        if ($useTimezoneDateGrouping) {
            // Timezone date grouping: timezone offset (for first CONVERT_TZ), userSelectedDateFrom, timezone offset (for second CONVERT_TZ), userSelectedDateTo
            // Then full timestamps for NOT EXISTS check (2 params: dateFrom, dateTo)
            $bindParams = [$timezoneOffset, $userSelectedDateFrom, $timezoneOffset, $userSelectedDateTo, $dateFrom, $dateTo];
            $bindTypes = 'ssssss'; // timezone offset (s), userSelectedDateFrom (s), timezone offset (s), userSelectedDateTo (s), dateFrom (s), dateTo (s)
        } else {
            // UTC range: date/hour range for cost table (4 params: dateFrom, hourFrom, dateTo, hourTo)
            // Then full timestamps for NOT EXISTS check (2 params: dateFrom, dateTo)
            $bindParams = [$dateFromDate, $dateFromHour, $dateToDate, $dateToHour, $dateFrom, $dateTo];
            $bindTypes = 'sisiss'; // date (string), hour (int), date (string), hour (int), dateFrom (string), dateTo (string)
        }
        
        // Add campaign filter parameters if provided (for NOT EXISTS check)
        if ($campaignFilter && !empty($campaignFilterParams)) {
            $bindParams = array_merge($bindParams, $campaignFilterParams);
            $bindTypes .= str_repeat('s', count($campaignFilterParams));
        }
        
        // Add campaign attribution parameters if campaign filter provided
        if ($campaignIdForAttribution !== null) {
            $bindParams[] = $campaignIdForAttribution; // EXISTS check
            $bindParams[] = $campaignIdForAttribution; // NOT EXISTS check
            $bindTypes .= 'ii';
        }
        
        $stmt->bind_param($bindTypes, ...$bindParams);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $unmatchedCost = (float)($result['unmatched_cumulative_cost'] ?? 0.0);
        
        // Log midnight hour filter status
        $midnightFilterInfo = $useTimezoneDateGrouping ? " (including all hours in {$userTimezone})" : "";
        error_log("FacebookCostAggregator: Unmatched costs (cumulative) - Cost: {$unmatchedCost}, Date Range: {$dateFrom} to {$dateTo}, Campaign ID: " . ($campaignIdForAttribution ?? 'null') . $midnightFilterInfo);
        
        return $unmatchedCost;
    }

    /**
     * Add unified cost calculation to SQL query
     * Returns SQL fragment that calculates: COALESCE(SUM(cl.cost), 0) + COALESCE(SUM(fb_cost.cost), 0)
     */
    public static function getUnifiedCostSQL(): string
    {
        return "
            COALESCE(SUM(cl.cost), 0) + 
            COALESCE((
                SELECT SUM(
                    CASE 
                        WHEN a_cost.delta_spend IS NOT NULL THEN a_cost.delta_spend
                        WHEN as_cost.delta_spend IS NOT NULL THEN as_cost.delta_spend
                        ELSE 0
                    END
                )
                FROM (
                    SELECT 
                        cl2.click_id,
                        cl2.campaign_id,
                        JSON_UNQUOTE(JSON_EXTRACT(cl2.extra_json, '$.traffic_source_tokens.ad_id')) as ad_id,
                        JSON_UNQUOTE(JSON_EXTRACT(cl2.extra_json, '$.traffic_source_tokens.adset_id')) as adset_id,
                        DATE(cl2.ts) as click_date,
                        HOUR(cl2.ts) as click_hour
                    FROM clicks cl2
                    WHERE cl2.click_id = cl.click_id
                ) as click_data
                LEFT JOIN campaigns camp ON click_data.campaign_id = camp.id
                LEFT JOIN facebook_marketing_ad_accounts fmaa ON camp.facebook_marketing_ad_account_id = fmaa.id
                LEFT JOIN ad_hourly_costs a_cost ON 
                    a_cost.ad_id = click_data.ad_id 
                    AND a_cost.date = click_data.click_date 
                    AND a_cost.hour = click_data.click_hour
                    AND (fmaa.facebook_marketing_integration_id IS NULL OR a_cost.ad_account_id = fmaa.facebook_marketing_integration_id)
                LEFT JOIN adset_hourly_costs as_cost ON 
                    as_cost.adset_id = click_data.adset_id 
                    AND as_cost.date = click_data.click_date 
                    AND as_cost.hour = click_data.click_hour
                    AND (fmaa.facebook_marketing_integration_id IS NULL OR as_cost.ad_account_id = fmaa.facebook_marketing_integration_id)
                    AND a_cost.delta_spend IS NULL
                WHERE click_data.ad_id IS NOT NULL OR click_data.adset_id IS NOT NULL
            ), 0)
        ";
    }

    /**
     * Batch aggregated costs (manual + Facebook API) keyed by campaign_id.
     *
     * Always uses CampaignStatsCostSql::perClickFacebookCostCase (ad OR adset attribution).
     * Do not route N=1 through getAggregatedCost() — that path previously required both
     * ad_id AND adset_id on each click and undercounted when either macro was missing.
     *
     * @param list<int> $campaignIds
     * @return array<int, float> campaign_id => total cost
     */
    public function getAggregatedCostsByCampaignIds(
        array $campaignIds,
        string $dateFrom,
        string $dateTo,
        ?string $userTimezone = null
    ): array {
        $campaignIds = array_values(array_unique(array_filter(array_map('intval', $campaignIds))));
        $result = [];
        foreach ($campaignIds as $id) {
            $result[$id] = 0.0;
        }
        if ($campaignIds === []) {
            return $result;
        }

        $placeholders = implode(',', array_fill(0, count($campaignIds), '?'));
        $clicksTable = 'clicks';
        $fbCase = \SimpleKuma\Stats\CampaignStatsCostSql::perClickFacebookCostCase($clicksTable);
        $joins = \SimpleKuma\Stats\CampaignStatsCostSql::facebookCostJoins($clicksTable, 'cl')['joins'];

        $sql = "
            SELECT cl.campaign_id,
                   COALESCE(SUM(cl.cost), 0) + COALESCE(SUM({$fbCase}), 0) AS total_cost
            FROM {$clicksTable} cl
            {$joins}
            WHERE cl.ts >= ? AND cl.ts <= ?
              AND cl.campaign_id IN ({$placeholders})
            GROUP BY cl.campaign_id
        ";

        $types = 'ssss' . str_repeat('i', count($campaignIds));
        $params = array_merge([$dateFrom, $dateTo, $dateFrom, $dateTo], $campaignIds);

        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            error_log('FacebookCostAggregator::getAggregatedCostsByCampaignIds prepare failed: ' . $this->db->error);
            return $result;
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result();
        while ($row = $rows->fetch_assoc()) {
            $cid = (int)$row['campaign_id'];
            $result[$cid] = (float)($row['total_cost'] ?? 0);
        }
        $stmt->close();

        return $result;
    }

    /**
     * Single-campaign total (manual + Facebook API) using the same allocator as the dashboard KPI.
     */
    public function getCampaignTotalCost(
        int $campaignId,
        string $dateFrom,
        string $dateTo,
        ?string $userTimezone = null
    ): float {
        if ($campaignId <= 0) {
            return 0.0;
        }
        $map = $this->getAggregatedCostsByCampaignIds([$campaignId], $dateFrom, $dateTo, $userTimezone);

        return (float)($map[$campaignId] ?? 0.0);
    }

    /**
     * Get aggregated cost for a date range with optional filters
     * This is more efficient than calculating per-click
     * 
     * @param string $dateFrom UTC timestamp start
     * @param string $dateTo UTC timestamp end
     * @param string|null $campaignFilter Optional SQL filter
     * @param array $campaignFilterParams Parameters for filter
     * @param string|null $userTimezone User's timezone (e.g., 'America/Los_Angeles') for cost matching. If null, uses UTC.
     */
    public function getAggregatedCost(string $dateFrom, string $dateTo, ?string $campaignFilter = null, array $campaignFilterParams = [], ?string $userTimezone = null, bool $skipOverallSum = false): float
    {
        // Extract date and hour from timestamps for index-friendly queries
        // Format: 'YYYY-MM-DD HH:MM:SS' -> extract 'YYYY-MM-DD' and HH
        $dateFromDate = substr($dateFrom, 0, 10); // 'YYYY-MM-DD'
        $dateFromHour = (int)substr($dateFrom, 11, 2); // HH (0-23)
        $dateToDate = substr($dateTo, 0, 10);
        $dateToHour = (int)substr($dateTo, 11, 2);
        
        // Store original filter for use in subqueries (they need different prefixes)
        $originalCampaignFilter = $campaignFilter;
        
        // Normalize the campaign filter to ensure all column references have proper table prefixes
        // This prevents "ambiguous column" errors when multiple tables have the same column names
        // CRITICAL: We normalize to cl. for the main query WHERE clause, but subqueries will convert to their own prefixes
        // NOTE: Even if filter contains JSON_UNQUOTE, we still need to prefix simple column references like offer_id
        // NOTE: traffic_source_id is only prefixed if it exists in clicks table, otherwise it should use campaigns join
        // NOTE: Don't prefix columns that are already in subqueries (e.g., campaign_id IN (SELECT...) or EXISTS (SELECT...))
        if ($campaignFilter !== null) {
            // Check if filter is already in subquery format (campaign_id IN (SELECT...) or EXISTS (SELECT...))
            // For EXISTS subqueries, they already have explicit table references (cl.campaign_id), so don't modify
            // For IN subqueries, prefix with cl. for main query WHERE clause to avoid ambiguity
            if (preg_match('/\bEXISTS\s*\(\s*SELECT/i', $campaignFilter)) {
                // Filter is in EXISTS subquery format like "EXISTS (SELECT 1 FROM campaigns WHERE campaigns.id = cl.campaign_id...)"
                // Already has explicit table references, don't modify
            } elseif (preg_match('/\bcampaign_id\s+IN\s*\(\s*SELECT/i', $campaignFilter)) {
                // Filter is in IN subquery format like "campaign_id IN (SELECT id FROM campaigns WHERE traffic_source_id = ?)"
                // Prefix with cl. for main query WHERE clause to avoid ambiguity
                // The convertFilterForSubquery method will remove cl. when used in subqueries
                $campaignFilter = preg_replace('/(?<!\.)\bcampaign_id\s+IN\s*\(\s*SELECT/i', 'cl.campaign_id IN (SELECT', $campaignFilter);
            } else {
                // Always prefix simple column references (campaign_id, offer_id, landing_page_id, slug_id)
                // that don't already have a table prefix, regardless of whether JSON_UNQUOTE is present
                // The regex uses negative lookbehind to avoid matching columns that already have a prefix
                $campaignFilter = preg_replace('/(?<!\.)\b(campaign_id|landing_page_id|offer_id|slug_id)(\s*=\s*\?|\s+IN\s*\()/i', 'cl.$1$2', $campaignFilter);
                
                // Handle traffic_source_id specially - only prefix if column exists in clicks table
                if ($this->checkTrafficSourceColumnExists()) {
                    // Column exists in clicks, so we can prefix it
                    $campaignFilter = preg_replace('/(?<!\.)\b(traffic_source_id)(\s*=\s*\?|\s+IN\s*\()/i', 'cl.$1$2', $campaignFilter);
                }
            }
        }
        
        // CRITICAL: Extract the user's selected date range from the UTC timestamps
        // The UTC timestamps represent the start/end of the user's selected date in their timezone
        // We need to determine which user timezone dates are being queried to filter costs correctly
        $userSelectedDateFrom = null;
        $userSelectedDateTo = null;
        
        if ($userTimezone !== null && $userTimezone !== 'UTC') {
            try {
                // Convert UTC timestamps back to user timezone to get the selected date range
                // The UTC timestamps represent the start/end of the user's selected date in their timezone
                // Example: User selects "Today" (2025-12-05 PST)
                // Dashboard converts to UTC: 2025-12-05 08:00:00 to 2025-12-06 07:59:59
                // We need to extract which PST dates are being queried: 2025-12-05
                $tz = new \DateTimeZone($userTimezone);
                $utcTz = new \DateTimeZone('UTC');
                
                $utcStart = new \DateTime($dateFrom, $utcTz);
                $utcStart->setTimezone($tz);
                $userSelectedDateFrom = $utcStart->format('Y-m-d');
                
                $utcEnd = new \DateTime($dateTo, $utcTz);
                $utcEnd->setTimezone($tz);
                $userSelectedDateTo = $utcEnd->format('Y-m-d');
                
                // CRITICAL: If the UTC range spans midnight, userSelectedDateTo might be the next day
                // But we want to filter for clicks whose converted date matches the user's selected date
                // So we should use the same date for both from and to if they're querying a single day
                // Actually, we should use the date range that the user selected, not the UTC range
                // But we don't have that information here - we only have the UTC timestamps
                // So we'll use the dates extracted from the UTC timestamps
            } catch (\Exception $e) {
                // If conversion fails, we'll filter by UTC range only
                error_log("Failed to convert UTC range to user timezone: " . $e->getMessage());
            }
        }
        
        // Manual costs from clicks.cost
        // Use full timestamp comparison (dateFrom/dateTo should be UTC timestamps)
        // Note: campaignFilter may use 'cl.campaign_id' or JSON_UNQUOTE expressions - we need to handle both formats
        $campaignFilterForManual = $campaignFilter;
        if ($campaignFilter) {
            // Replace 'cl.' prefix with nothing for the manual cost query (no alias)
            // Also handle 'cl.campaign_id IN (...)' patterns
            // Handle JSON_UNQUOTE(JSON_EXTRACT(cl.extra_json, ...)) by replacing cl.extra_json with extra_json
            $campaignFilterForManual = preg_replace('/\bcl\.(\w+)/', '$1', $campaignFilter);
            // Also replace cl.extra_json references in JSON_UNQUOTE expressions
            $campaignFilterForManual = str_replace('cl.extra_json', 'extra_json', $campaignFilterForManual);
        }
        $manualCostQuery = "
            SELECT COALESCE(SUM(cost), 0) as total_cost
            FROM clicks
            WHERE ts >= ? AND ts <= ?
            " . ($campaignFilterForManual ?? '');
        
        $stmt = $this->db->prepare($manualCostQuery);
        
        $bindTypes = 'ss';
        $bindValues = [$dateFrom, $dateTo];
        if (!empty($campaignFilterParams)) {
            // Support mixed param types: ad_name_value and other tokens can be strings
            foreach ($campaignFilterParams as $param) {
                $bindTypes .= (is_numeric($param) && (string)(int)$param === (string)$param) ? 'i' : 's';
            }
            $bindValues = array_merge($bindValues, $campaignFilterParams);
        }
        
        // Validate bind parameters before binding
        $placeholderCount = substr_count($manualCostQuery, '?');
        $bindTypesCount = strlen($bindTypes);
        $bindValuesCount = count($bindValues);
        if ($placeholderCount !== $bindTypesCount || $placeholderCount !== $bindValuesCount) {
            $errorMessage = "Manual cost query bind mismatch: Types={$bindTypesCount}, Values={$bindValuesCount}, Placeholders={$placeholderCount}. Filter: " . ($campaignFilter ?? 'null');
            error_log("FacebookCostAggregator: " . $errorMessage);
            throw new \Exception($errorMessage);
        }
        
        $stmt->bind_param($bindTypes, ...$bindValues);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $manualCost = (float)($result['total_cost'] ?? 0.0);

        // Facebook API costs - aggregate from hourly tables for clicks in date range
        // Includes ad_account_id matching for accurate multi-account support
        // Divides hourly spend by click count to get accurate per-click costs
        // IMPORTANT: Costs are stored in UTC, so we match using UTC date/hour from clicks
        // BUT: We need to filter costs based on the click's converted date in user timezone
        // to ensure costs appear on the correct day in the dashboard
        
        // Calculate timezone offset for MySQL CONVERT_TZ if user timezone is provided
        $timezoneOffset = '+00:00'; // Default to UTC
        if ($userTimezone !== null && $userTimezone !== 'UTC') {
            try {
                $tz = new \DateTimeZone($userTimezone);
                $utcTz = new \DateTimeZone('UTC');
                // Use the start of the date range to get correct offset (handles DST)
                $testDate = new \DateTime($dateFrom, $utcTz);
                $testDate->setTimezone($tz);
                $offset = $tz->getOffset($testDate);
                $hours = intval($offset / 3600);
                $minutes = intval(($offset % 3600) / 60);
                $timezoneOffset = sprintf('%+03d:%02d', $hours, abs($minutes));
            } catch (\Exception $e) {
                // Fallback to UTC if timezone calculation fails
                $timezoneOffset = '+00:00';
            }
        }
        
        // CRITICAL FIX: Add filter to only include costs for clicks whose converted date (in user timezone)
        // matches the user's selected date range. This ensures costs appear on the correct day.
        // 
        // PROBLEM: Costs are saved with UTC date/hour that matches clicks' UTC date/hour.
        // But when user selects "Today" (2025-12-05 PST), the UTC range is 2025-12-05 08:00:00 to 2025-12-06 07:59:59.
        // This correctly excludes clicks at 2025-12-05 00:00:00 UTC (which are 2025-12-04 16:00:00 PST = yesterday).
        // However, when user selects "Yesterday" (2025-12-04 PST), the UTC range is 2025-12-04 08:00:00 to 2025-12-05 07:59:59.
        // This INCLUDES clicks at 2025-12-05 00:00:00 to 2025-12-05 07:59:59 UTC, which have costs saved with UTC date 2025-12-05.
        // Those costs get matched and show under "Yesterday" (correct!).
        //
        // But the issue is: When user selects "Today", costs saved with UTC date 2025-12-05 hour 8-20 should match
        // clicks at 2025-12-05 08:00:00+ UTC, which ARE in the UTC range. So those costs SHOULD show under "Today".
        // 
        // The filter ensures costs are only included for clicks whose converted date matches the selected date.
        $userTzDateFilter = '';
        if ($userTimezone !== null && $userTimezone !== 'UTC' && $userSelectedDateFrom !== null && $userSelectedDateTo !== null) {
            // Filter: Only include costs for clicks where DATE(CONVERT_TZ(cl.ts, '+00:00', timezoneOffset)) 
            // falls within the user's selected date range
            $userTzDateFilter = " AND DATE(CONVERT_TZ(cl.ts, '+00:00', ?)) >= ? AND DATE(CONVERT_TZ(cl.ts, '+00:00', ?)) <= ?";
        }
        
        // CRITICAL: Define useTimezoneDateFiltering before query construction so it's available in JOIN clauses
        // This flag determines if we should exclude hour 0 (midnight) in user timezone from cost matching
        $useTimezoneDateFiltering = ($userTimezone !== null && $userTimezone !== 'UTC' && $userSelectedDateFrom !== null && $userSelectedDateTo !== null && $timezoneOffset !== null);
        
        // Part 1: Costs matched to clicks (existing logic)
        // IMPORTANT: The subqueries that count clicks for division must also filter by the query range
        // to ensure costs are only divided among clicks in the current query range, not all clicks in that hour
        
        // PERFORMANCE OPTIMIZATION: Pre-calculate click counts to avoid correlated subqueries
        // This dramatically improves performance for long date ranges by calculating counts once
        // instead of running COUNT(*) for every row
        // CRITICAL: adset_counts must NOT filter by ad_name_value/adset_name_value - we need total
        // clicks per adset+hour so each ad gets its proportional share, not the full adset cost
        list($adsetCountsFilter, $adsetCountsFilterParams) = $this->getFilterForAdsetCountSubquery($campaignFilter, $campaignFilterParams ?? []);
        $adsetCountsFilterForSubquery = $adsetCountsFilter ? $this->convertFilterForSubquery($adsetCountsFilter, 'c_counts') : '';
        $adsetCountsSubquery = "
            SELECT 
                adset_id,
                DATE(ts) as click_date,
                HOUR(ts) as click_hour,
                COUNT(*) as click_count
            FROM clicks c_counts
            WHERE c_counts.ts >= ? AND c_counts.ts <= ?
            " . ($adsetCountsFilterForSubquery ?? '') . "
            AND c_counts.adset_id IS NOT NULL
            GROUP BY adset_id, DATE(ts), HOUR(ts)
        ";
        
        $adCountsSubquery = "
            SELECT 
                ad_id,
                DATE(ts) as click_date,
                HOUR(ts) as click_hour,
                COUNT(*) as click_count
            FROM clicks c_counts
            WHERE c_counts.ts >= ? AND c_counts.ts <= ?
            " . ($campaignFilter && !empty($campaignFilterParams) ? $this->convertFilterForSubquery($campaignFilter, 'c_counts') : '') . "
            AND c_counts.ad_id IS NOT NULL
            GROUP BY ad_id, DATE(ts), HOUR(ts)
        ";
        
        // PERFORMANCE: Start directly from filtered clicks (click_data) instead of scanning all clicks first
        // This eliminates the double scan - we only scan clicks once in the click_data subquery
        $fbCostQuery = "
            SELECT COALESCE(SUM(
                -- Always prefer adset cost when available (represents true total spend)
                -- Fall back to ad cost only if no adset cost exists
                -- PERFORMANCE: Using pre-calculated click counts instead of correlated subqueries
                CASE 
                    WHEN as_cost.delta_spend > 0 AND click_data.adset_id IS NOT NULL 
                    THEN 
                        as_cost.delta_spend / GREATEST(COALESCE(adset_counts.click_count, 1), 1)
                    WHEN a_cost.delta_spend > 0 AND click_data.ad_id IS NOT NULL 
                    THEN 
                        a_cost.delta_spend / GREATEST(COALESCE(ad_counts.click_count, 1), 1)
                    ELSE 0
                END
            ), 0) as total_fb_cost
            FROM (
                SELECT 
                    cl2.click_id,
                    cl2.campaign_id,
                    cl2.offer_id,
                    cl2.landing_page_id,
                    cl2.ad_id,
                    cl2.adset_id,
                    DATE(cl2.ts) as click_date,
                    HOUR(cl2.ts) as click_hour,
                    cl2.ts
                FROM clicks cl2
                WHERE cl2.ts >= ? AND cl2.ts <= ?
                " . ($campaignFilter && !empty($campaignFilterParams) ? $this->convertFilterForSubquery($campaignFilter, 'cl2') : '') . "
                " . ($userTzDateFilter !== '' ? str_replace('cl.', 'cl2.', $userTzDateFilter) : '') . "
            ) as click_data
            LEFT JOIN (
                " . $adsetCountsSubquery . "
            ) as adset_counts ON 
                adset_counts.adset_id = click_data.adset_id
                AND adset_counts.click_date = click_data.click_date
                AND adset_counts.click_hour = click_data.click_hour
            LEFT JOIN (
                " . $adCountsSubquery . "
            ) as ad_counts ON 
                ad_counts.ad_id = click_data.ad_id
                AND ad_counts.click_date = click_data.click_date
                AND ad_counts.click_hour = click_data.click_hour
            LEFT JOIN campaigns camp ON click_data.campaign_id = camp.id
            LEFT JOIN facebook_marketing_ad_accounts fmaa ON camp.facebook_marketing_ad_account_id = fmaa.id
            LEFT JOIN ad_hourly_costs a_cost ON 
                a_cost.ad_id = click_data.ad_id 
                AND a_cost.date = click_data.click_date 
                AND a_cost.hour = click_data.click_hour
                AND (fmaa.facebook_marketing_integration_id IS NULL OR a_cost.ad_account_id = fmaa.facebook_marketing_integration_id)
                " . ($useTimezoneDateFiltering ? "AND HOUR(CONVERT_TZ(CONCAT(a_cost.date, ' ', LPAD(a_cost.hour, 2, '0'), ':00:00'), '+00:00', ?)) > 0" : "") . "
            LEFT JOIN adset_hourly_costs as_cost ON 
                as_cost.adset_id = click_data.adset_id 
                AND as_cost.date = click_data.click_date 
                AND as_cost.hour = click_data.click_hour
                AND (fmaa.facebook_marketing_integration_id IS NULL OR as_cost.ad_account_id = fmaa.facebook_marketing_integration_id)
                " . ($useTimezoneDateFiltering ? "AND HOUR(CONVERT_TZ(CONCAT(as_cost.date, ' ', LPAD(as_cost.hour, 2, '0'), ':00:00'), '+00:00', ?)) > 0" : "") . "
            WHERE (click_data.ad_id IS NOT NULL OR click_data.adset_id IS NOT NULL)
        ";

        // PERFORMANCE: Query now starts from click_data (filtered clicks) instead of scanning all clicks
        // Parameters needed: adset_counts params + ad_counts params + click_data params (which includes date range, campaign filter, and userTzDateFilter if any)
        // No separate main WHERE clause params needed since click_data already filters by date range and campaign
        // Determine parameter types for filter params
        // JSON_UNQUOTE filters use string parameters (ad_id, adset_id are strings)
        // Simple column filters use integer parameters (campaign_id, offer_id, etc. are integers)
        // If filter contains both, we need to determine type for each param based on its position
        $getParamTypes = function($filter, $params) {
            if (empty($params)) return '';
            $types = '';
            
            // Count the number of ? placeholders in the filter
            $placeholderCount = substr_count($filter, '?');
            if ($placeholderCount !== count($params)) {
                // Mismatch - use fallback logic
                if (strpos($filter, 'JSON_UNQUOTE') !== false) {
                    $types = str_repeat('s', count($params));
                } else {
                    $types = str_repeat('i', count($params));
                }
                return $types;
            }
            
            // For each parameter, determine its type based on the context around its ? placeholder
            // Find all ? placeholders and check the context
            $offset = 0;
            while (($pos = strpos($filter, '?', $offset)) !== false) {
                // Get the context around this placeholder (look back up to 200 chars)
                $contextStart = max(0, $pos - 200);
                $context = substr($filter, $contextStart, $pos - $contextStart + 1);
                
                // Check if this parameter is a string type: JSON_UNQUOTE, ad_name_value, or adset_name_value
                if (strpos($context, 'JSON_UNQUOTE') !== false
                    || strpos($context, 'ad_name_value') !== false
                    || strpos($context, 'adset_name_value') !== false) {
                    $types .= 's'; // String params
                } else {
                    $types .= 'i'; // Simple column params (campaign_id, offer_id, etc.) are integers
                }
                
                $offset = $pos + 1;
                }
            
            // Fallback: if we couldn't determine types, assume all strings if string-type columns present, else all integers
            if (strlen($types) !== count($params)) {
                if (strpos($filter, 'JSON_UNQUOTE') !== false
                    || strpos($filter, 'ad_name_value') !== false
                    || strpos($filter, 'adset_name_value') !== false) {
                    $types = str_repeat('s', count($params));
                } else {
                    $types = str_repeat('i', count($params));
                }
            }
            return $types;
        };
        
        // PERFORMANCE: click_data subquery now includes all filters (date range, campaign filter, user timezone filter)
        // No separate main WHERE clause needed since we start from filtered clicks
        $clickDataParams = [$dateFrom, $dateTo];
        $clickDataBindTypes = 'ss';
        if ($campaignFilter && !empty($campaignFilterParams)) {
            $clickDataBindTypes .= $getParamTypes($campaignFilter, $campaignFilterParams);
            $clickDataParams = array_merge($clickDataParams, $campaignFilterParams);
        }
        // Add user timezone date filter parameters to click_data if needed
        if ($userTzDateFilter !== '') {
            $clickDataBindTypes .= 'ssss'; // Add 4 more 's' for timezoneOffset (twice) and userSelectedDateFrom/To
            $clickDataParams = array_merge($clickDataParams, [$timezoneOffset, $userSelectedDateFrom, $timezoneOffset, $userSelectedDateTo]);
        }
        
        // Pre-calculated count subquery params (2 date ranges = 4 params, plus campaign filter params if applicable for each subquery)
        // PERFORMANCE: These are pre-calculated once instead of running COUNT(*) for every row
        // First count subquery (adset): dateFrom, dateTo, plus STRIPPED filter params (no ad_name_value/adset_name_value)
        $adsetCountsParams = [$dateFrom, $dateTo];
        $adsetCountsBindTypes = 'ss';
        if ($adsetCountsFilter && !empty($adsetCountsFilterParams)) {
            $adsetCountsBindTypes .= $getParamTypes($adsetCountsFilter, $adsetCountsFilterParams);
            $adsetCountsParams = array_merge($adsetCountsParams, $adsetCountsFilterParams);
        }
        
        // Second count subquery (ad): dateFrom, dateTo, plus campaign filter params if applicable
        $adCountsParams = [$dateFrom, $dateTo];
        $adCountsBindTypes = 'ss';
        if ($campaignFilter && !empty($campaignFilterParams)) {
            $adCountsBindTypes .= $getParamTypes($campaignFilter, $campaignFilterParams);
            $adCountsParams = array_merge($adCountsParams, $campaignFilterParams);
        }
        
        // Combine all params for fbCostQuery (adset_counts + ad_counts + click_data)
        // Order: adset_counts params, ad_counts params, click_data params (which includes userTzDateFilter params if any)
        $fbCostBindTypes = $adsetCountsBindTypes . $adCountsBindTypes . $clickDataBindTypes;
        $fbCostBindValues = array_merge($adsetCountsParams, $adCountsParams, $clickDataParams);
        
        // User timezone date filter parameters are now included in click_data params above
        if ($userTzDateFilter !== '') {
            // Debug logging
            error_log("FacebookCostAggregator: Applying user timezone date filter - timezoneOffset={$timezoneOffset}, userSelectedDateFrom={$userSelectedDateFrom}, userSelectedDateTo={$userSelectedDateTo}, UTC range={$dateFrom} to {$dateTo}");
        } else {
            error_log("FacebookCostAggregator: No user timezone date filter applied - userTimezone=" . ($userTimezone ?? 'null') . ", userSelectedDateFrom=" . ($userSelectedDateFrom ?? 'null') . ", userSelectedDateTo=" . ($userSelectedDateTo ?? 'null'));
        }
        
        // Add timezone offset parameters for midnight hour exclusion in JOIN clauses (if timezone filtering is active)
        if ($useTimezoneDateFiltering) {
            $fbCostBindTypes .= 'ss'; // Add 2 more 's' for timezoneOffset (once for ad costs, once for adset costs)
            $fbCostBindValues = array_merge($fbCostBindValues, [$timezoneOffset, $timezoneOffset]);
            error_log("FacebookCostAggregator: Applying midnight hour exclusion to matched costs - excluding hour 0 in user timezone ({$userTimezone})");
        }
        
        // Validate that the number of placeholders matches the number of parameters
        $placeholderCount = substr_count($fbCostQuery, '?');
        $paramCount = count($fbCostBindValues);
        if ($placeholderCount !== $paramCount) {
            error_log("FacebookCostAggregator: Parameter mismatch detected! Placeholders: {$placeholderCount}, Parameters: {$paramCount}, Filter: " . ($campaignFilter ?? 'null'));
            error_log("FacebookCostAggregator: SQL Query: " . substr($fbCostQuery, 0, 500));
            throw new \Exception("Bind parameter mismatch: Types={$paramCount}, Values={$paramCount}, Placeholders={$placeholderCount}");
        }
        
        $fbStmt = $this->db->prepare($fbCostQuery);
        $fbStmt->bind_param($fbCostBindTypes, ...$fbCostBindValues);
        $fbStmt->execute();
        $fbResult = $fbStmt->get_result()->fetch_assoc();
        $fbCostFromClicks = (float)($fbResult['total_fb_cost'] ?? 0.0);
        
        // Debug logging for all queries
        if ($campaignFilter) {
            $filterType = 'Unknown';
            if (strpos($campaignFilter, 'campaign_id') !== false) {
                $filterType = 'Campaign';
            } elseif (strpos($campaignFilter, 'offer_id') !== false) {
                $filterType = 'Offer';
            } elseif (strpos($campaignFilter, 'landing_page_id') !== false) {
                $filterType = 'Landing Page';
            }
            error_log("FacebookCostAggregator: {$filterType} filter applied - Filter: {$campaignFilter}, Params: " . json_encode($campaignFilterParams) . ", FB Cost from clicks (matched): {$fbCostFromClicks}, Manual cost: {$manualCost}");
        } else {
            error_log("FacebookCostAggregator: No filter - FB Cost from clicks: {$fbCostFromClicks}, Manual cost: {$manualCost}");
        }
        
        // Part 2: Costs without matching clicks (for hours that have costs but no clicks yet)
        // This handles the case where costs are fetched but clicks haven't been recorded yet
        // Only include costs within the date range that don't have matching clicks
        // CRITICAL FIX: Filter by campaign if campaignFilter is provided
        // We filter by checking if there are ANY clicks for this adset_id/date/hour in the filtered campaign
        // If there are clicks in the filtered campaign, the cost was already counted in Part 1
        // If there are NO clicks in the filtered campaign, but clicks exist in other campaigns, exclude the cost
        $campaignFilterForUnmatched = '';
        
        // CRITICAL FIX: Note that $useTimezoneDateFiltering is already defined earlier in the function
        // (after $userTzDateFilter is set, before query construction)
        // This ensures costs are filtered by user timezone date, not just UTC date/hour
        
        // Initial bind params: date/hour ranges for cost table WHERE clauses and LEFT JOIN ON clauses
        // WHERE clause for adset costs: 4 params (dateFrom, hourFrom, dateTo, hourTo) - s, i, s, i
        // CRITICAL FIX: Initialize bind types and values based on timezone filtering
        // When timezone filtering is used, we need timezone offset and user selected dates instead of UTC date/hour
        if ($useTimezoneDateFiltering) {
            // Timezone filtering: Order matches SQL structure
            // Adset WHERE: 4 params (timezoneOffset, userSelectedDateFrom, timezoneOffset, userSelectedDateTo)
            // Adset LEFT JOIN ON date range: 2 params (dateFrom, dateTo)
            // Ad WHERE: 4 params (timezoneOffset, userSelectedDateFrom, timezoneOffset, userSelectedDateTo)
            // Ad LEFT JOIN ON date range: 2 params (dateFrom, dateTo)
            // Total: 12 params (4+2+4+2)
            $unmatchedBindTypes = 'ssssssssssss'; // adset WHERE (ssss), adset LEFT JOIN ON (ss), ad WHERE (ssss), ad LEFT JOIN ON (ss)
            $unmatchedBindValues = [
                $timezoneOffset, $userSelectedDateFrom, $timezoneOffset, $userSelectedDateTo, // adset WHERE clause (timezone filtering)
                $dateFrom, $dateTo, // adset LEFT JOIN ON (c.ts comparison)
                $timezoneOffset, $userSelectedDateFrom, $timezoneOffset, $userSelectedDateTo, // ad WHERE clause (timezone filtering)
                $dateFrom, $dateTo  // ad LEFT JOIN ON (c.ts comparison)
            ];
        } else {
            // UTC date/hour filtering: Order matches SQL structure
            // Adset WHERE: 4 params (dateFrom, hourFrom, dateTo, hourTo) - s, i, s, i
            // Adset LEFT JOIN ON date range: 2 params (dateFrom, dateTo) - s, s
            // Ad WHERE: 4 params (dateFrom, hourFrom, dateTo, hourTo) - s, i, s, i
            // Ad LEFT JOIN ON date range: 2 params (dateFrom, dateTo) - s, s
            // Total: 12 params (4+2+4+2)
            $unmatchedBindTypes = 'sisisssisisss'; // adset WHERE (sisi), adset LEFT JOIN ON (ss), ad WHERE (sisi), ad LEFT JOIN ON (ss)
            $unmatchedBindValues = [
                $dateFromDate, $dateFromHour, $dateToDate, $dateToHour, // adset WHERE clause
                $dateFrom, $dateTo, // adset LEFT JOIN ON (c.ts comparison)
                $dateFromDate, $dateFromHour, $dateToDate, $dateToHour, // ad WHERE clause
                $dateFrom, $dateTo  // ad LEFT JOIN ON (c.ts comparison)
            ];
        }
        
        // Extract filter parameters from filter if provided
        $campaignIdForUnmatched = null;
        $offerIdForUnmatched = null;
        $landingPageIdForUnmatched = null;
        $trafficSourceIdForUnmatched = null;
        $hasExistsTrafficSourceFilter = false;
        
        if ($campaignFilter && !empty($campaignFilterParams)) {
            // Extract filter parameters by finding their position in the filter string
            // This handles cases where multiple filters are combined (e.g., offer_id AND campaign_id)
            
            // Check if filter contains campaign_id (direct = ? or in EXISTS subquery)
            // For EXISTS subqueries with traffic_source_id, extract the traffic_source_id parameter
            if (preg_match('/EXISTS\s*\(\s*SELECT.*traffic_source_id\s*=\s*\?/i', $campaignFilter)) {
                // EXISTS subquery with traffic_source_id - extract the parameter
                $hasExistsTrafficSourceFilter = true;
                // Count ? before traffic_source_id
                $beforeTrafficSource = substr($campaignFilter, 0, strpos($campaignFilter, 'traffic_source_id'));
                $paramIndex = preg_match_all('/\?/', $beforeTrafficSource);
                $trafficSourceIdForUnmatched = $campaignFilterParams[$paramIndex] ?? null;
                error_log("FacebookCostAggregator: EXISTS subquery with traffic_source_id detected - Traffic Source ID: " . ($trafficSourceIdForUnmatched ?? 'null') . ", Param Index: {$paramIndex}");
            } elseif (preg_match('/(?:cl\.)?traffic_source_id\s*=\s*\?/i', $campaignFilter)) {
                // Direct traffic_source_id filter (cl.traffic_source_id = ? or traffic_source_id = ?)
                $hasExistsTrafficSourceFilter = false; // Not an EXISTS subquery
                // Count ? before traffic_source_id
                $beforeTrafficSource = substr($campaignFilter, 0, strpos($campaignFilter, 'traffic_source_id'));
                $paramIndex = preg_match_all('/\?/', $beforeTrafficSource);
                $trafficSourceIdForUnmatched = $campaignFilterParams[$paramIndex] ?? null;
                error_log("FacebookCostAggregator: Direct traffic_source_id filter detected - Traffic Source ID: " . ($trafficSourceIdForUnmatched ?? 'null') . ", Param Index: {$paramIndex}");
            } elseif (preg_match('/campaign_id\s*=\s*\?/i', $campaignFilter)) {
                // Find the parameter index for campaign_id
                $paramIndex = 0;
                $beforeCampaignId = substr($campaignFilter, 0, strpos($campaignFilter, 'campaign_id'));
                if (preg_match_all('/\?/', $beforeCampaignId) > 0) {
                    $paramIndex = preg_match_all('/\?/', $beforeCampaignId);
                }
                $campaignIdForUnmatched = $campaignFilterParams[$paramIndex] ?? null;
                error_log("FacebookCostAggregator: Extracted campaign_id for unmatched costs - Campaign ID: {$campaignIdForUnmatched}, Filter: {$campaignFilter}, Params: " . json_encode($campaignFilterParams) . ", Param Index: {$paramIndex}");
                // CRITICAL: Ensure hasExistsTrafficSourceFilter is false for simple campaign_id filters
                if ($hasExistsTrafficSourceFilter) {
                    error_log("FacebookCostAggregator: WARNING - hasExistsTrafficSourceFilter was incorrectly set to true for simple campaign_id filter. Resetting to false.");
                    $hasExistsTrafficSourceFilter = false;
                }
            }
            // Check if filter contains offer_id
            if (preg_match('/offer_id\s*=\s*\?/i', $campaignFilter)) {
                // Find the parameter index for offer_id
                $paramIndex = 0;
                // Count how many parameters come before offer_id
                $beforeOfferId = substr($campaignFilter, 0, strpos($campaignFilter, 'offer_id'));
                if (preg_match_all('/\?/', $beforeOfferId) > 0) {
                    $paramIndex = preg_match_all('/\?/', $beforeOfferId);
                }
                $offerIdForUnmatched = $campaignFilterParams[$paramIndex] ?? null;
                error_log("FacebookCostAggregator: Extracted offer_id for unmatched costs - Offer ID: {$offerIdForUnmatched}, Filter: {$campaignFilter}, Params: " . json_encode($campaignFilterParams) . ", Param Index: {$paramIndex}");
            }
            // Check if filter contains landing_page_id
            if (preg_match('/landing_page_id\s*=\s*\?/i', $campaignFilter)) {
                // Find the parameter index for landing_page_id
                $paramIndex = 0;
                // Count how many parameters come before landing_page_id
                $beforeLpId = substr($campaignFilter, 0, strpos($campaignFilter, 'landing_page_id'));
                if (preg_match_all('/\?/', $beforeLpId) > 0) {
                    $paramIndex = preg_match_all('/\?/', $beforeLpId);
                }
                $landingPageIdForUnmatched = $campaignFilterParams[$paramIndex] ?? null;
                error_log("FacebookCostAggregator: Extracted landing_page_id for unmatched costs - Landing Page ID: {$landingPageIdForUnmatched}, Filter: {$campaignFilter}, Params: " . json_encode($campaignFilterParams) . ", Param Index: {$paramIndex}");
            }
            // Log final extracted values
            if ($campaignIdForUnmatched !== null || $offerIdForUnmatched !== null || $landingPageIdForUnmatched !== null) {
                error_log("FacebookCostAggregator: Extracted filter params - Campaign ID: " . ($campaignIdForUnmatched ?? 'null') . ", Offer ID: " . ($offerIdForUnmatched ?? 'null') . ", Landing Page ID: " . ($landingPageIdForUnmatched ?? 'null'));
            } else {
                error_log("FacebookCostAggregator: Filter does not match known patterns - Filter: {$campaignFilter}, Params: " . json_encode($campaignFilterParams));
            }
        } else {
            error_log("FacebookCostAggregator: No campaign filter provided for unmatched costs - Filter: " . ($campaignFilter ?? 'null') . ", Params: " . json_encode($campaignFilterParams ?? []));
        }
        
        // CRITICAL FIX: Use timezone date filtering for unmatched costs (already defined above)
        // This ensures costs are filtered by user timezone date, not just UTC date/hour
        
        $unmatchedCostsQuery = "
            SELECT COALESCE(SUM(unmatched.delta_spend), 0) as unmatched_cost
            FROM (
                -- PERFORMANCE OPTIMIZATION: Use LEFT JOIN instead of NOT EXISTS with derived table
                -- This allows MySQL to use indexes on clicks table efficiently
                SELECT DISTINCT 
                    as_cost.adset_id, 
                    as_cost.date, 
                    as_cost.hour, 
                    as_cost.delta_spend
                FROM adset_hourly_costs as_cost
                LEFT JOIN clicks c ON 
                    c.adset_id = as_cost.adset_id
                    AND c.adset_id IS NOT NULL
                    -- PERFORMANCE: Calculate hour range directly in JOIN to enable index usage
                    AND c.ts >= CONCAT(as_cost.date, ' ', LPAD(as_cost.hour, 2, '0'), ':00:00')
                    AND c.ts < DATE_ADD(CONCAT(as_cost.date, ' ', LPAD(as_cost.hour, 2, '0'), ':00:00'), INTERVAL 1 HOUR)
                    AND c.ts >= ? AND c.ts <= ?
                    " . ($campaignIdForUnmatched !== null ? "AND c.campaign_id = ?" : "") . "
                    " . ($offerIdForUnmatched !== null ? "AND c.offer_id = ?" : "") . "
                    " . ($landingPageIdForUnmatched !== null ? "AND c.landing_page_id = ?" : "") . "
                LEFT JOIN campaigns camp ON c.campaign_id = camp.id
                LEFT JOIN facebook_marketing_ad_accounts fmaa ON camp.facebook_marketing_ad_account_id = fmaa.id
                WHERE " . ($useTimezoneDateFiltering ? 
                    "DATE(CONVERT_TZ(CONCAT(as_cost.date, ' ', LPAD(as_cost.hour, 2, '0'), ':00:00'), '+00:00', ?)) >= ? AND DATE(CONVERT_TZ(CONCAT(as_cost.date, ' ', LPAD(as_cost.hour, 2, '0'), ':00:00'), '+00:00', ?)) <= ?" :
                    "(as_cost.date, as_cost.hour) >= (?, ?) AND (as_cost.date, as_cost.hour) <= (?, ?)") . "
                    AND as_cost.delta_spend > 0
                    AND as_cost.adset_id IS NOT NULL
                    AND c.id IS NULL  -- No matching click = unmatched cost
                    AND (fmaa.facebook_marketing_integration_id IS NULL OR as_cost.ad_account_id = fmaa.facebook_marketing_integration_id)
                    -- NOTE: Traffic source filter is handled in EXISTS validation clause below, not here
                    -- because c.campaign_id is NULL when c.id IS NULL, so this EXISTS would never match
                    " . (($campaignIdForUnmatched !== null || $offerIdForUnmatched !== null || $landingPageIdForUnmatched !== null || preg_match('/EXISTS\s*\(\s*SELECT.*traffic_source_id\s*=\s*\?/i', $campaignFilter ?? '')) ? "
                    -- CRITICAL FIX: Only include costs if the adset has clicks matching the filter
                    -- For campaign_id: clicks exist ONLY in the filtered campaign (check ANY time)
                    -- For offer_id/landing_page_id: clicks exist with the filtered offer/landing page (check WITHIN date range)
                    -- NOTE: For campaign_id, we check ANY time to include costs even if clicks haven't arrived yet today
                    -- NOTE: For offer_id/landing_page_id, we check WITHIN date range to ensure costs are only included for the current query
                    AND EXISTS (
                        SELECT 1 FROM clicks c2
                        WHERE c2.adset_id = as_cost.adset_id
                            AND c2.adset_id IS NOT NULL
                            " . ($campaignIdForUnmatched !== null ? "AND c2.campaign_id = ?" : "") . "
                            " . (($offerIdForUnmatched !== null || $landingPageIdForUnmatched !== null) && $campaignIdForUnmatched === null ? "AND c2.ts >= ? AND c2.ts <= ?" : "") . "
                            " . ($offerIdForUnmatched !== null ? "AND c2.offer_id = ?" : "") . "
                            " . ($landingPageIdForUnmatched !== null ? "AND c2.landing_page_id = ?" : "") . "
                            " . (preg_match('/EXISTS\s*\(\s*SELECT.*traffic_source_id\s*=\s*\?/i', $campaignFilter ?? '') ? "AND EXISTS (SELECT 1 FROM campaigns WHERE campaigns.id = c2.campaign_id AND campaigns.traffic_source_id = ?)" : "") . "
                    )
                    " . ($campaignIdForUnmatched !== null ? "
                    AND NOT EXISTS (
                        SELECT 1 FROM clicks c3
                        WHERE c3.adset_id = as_cost.adset_id
                            AND c3.adset_id IS NOT NULL
                            -- PERFORMANCE: Use generated column (adset_id) instead of JSON_EXTRACT for index usage
                            AND c3.campaign_id != ?
                    )
                    " : "") . "
                    " : "
                    -- CRITICAL FIX: Remove EXISTS check requiring clicks in date range
                    -- This allows costs without clicks to be included (e.g., gutters campaign)
                    -- Costs are stored from Meta API even if no clicks exist, and should be shown
                    -- The LEFT JOIN with c.id IS NULL check above already ensures we don't double-count costs that have matching clicks
                    ") . "
                
                UNION ALL
                
                -- Get ad costs without matching clicks (only if no adset cost exists for same adset/date/hour)
                -- PERFORMANCE OPTIMIZATION: Use LEFT JOIN instead of NOT EXISTS with derived table
                SELECT DISTINCT 
                    a_cost.ad_id as adset_id, 
                    a_cost.date, 
                    a_cost.hour, 
                    a_cost.delta_spend
                FROM ad_hourly_costs a_cost
                LEFT JOIN clicks c ON 
                    c.ad_id = a_cost.ad_id
                    AND c.ad_id IS NOT NULL
                    -- PERFORMANCE: Calculate hour range directly in JOIN to enable index usage
                    AND c.ts >= CONCAT(a_cost.date, ' ', LPAD(a_cost.hour, 2, '0'), ':00:00')
                    AND c.ts < DATE_ADD(CONCAT(a_cost.date, ' ', LPAD(a_cost.hour, 2, '0'), ':00:00'), INTERVAL 1 HOUR)
                    AND c.ts >= ? AND c.ts <= ?
                    " . ($campaignIdForUnmatched !== null ? "AND c.campaign_id = ?" : "") . "
                    " . ($offerIdForUnmatched !== null ? "AND c.offer_id = ?" : "") . "
                    " . ($landingPageIdForUnmatched !== null ? "AND c.landing_page_id = ?" : "") . "
                LEFT JOIN campaigns camp ON c.campaign_id = camp.id
                LEFT JOIN facebook_marketing_ad_accounts fmaa ON camp.facebook_marketing_ad_account_id = fmaa.id
                WHERE " . ($useTimezoneDateFiltering ? 
                    "DATE(CONVERT_TZ(CONCAT(a_cost.date, ' ', LPAD(a_cost.hour, 2, '0'), ':00:00'), '+00:00', ?)) >= ? AND DATE(CONVERT_TZ(CONCAT(a_cost.date, ' ', LPAD(a_cost.hour, 2, '0'), ':00:00'), '+00:00', ?)) <= ?" :
                    "(a_cost.date, a_cost.hour) >= (?, ?) AND (a_cost.date, a_cost.hour) <= (?, ?)") . "
                    AND a_cost.delta_spend > 0
                    AND a_cost.ad_id IS NOT NULL
                    AND c.id IS NULL  -- No matching click = unmatched cost
                    AND (fmaa.facebook_marketing_integration_id IS NULL OR a_cost.ad_account_id = fmaa.facebook_marketing_integration_id)
                    -- NOTE: Traffic source filter is handled in EXISTS validation clause below, not here
                    -- because c.campaign_id is NULL when c.id IS NULL, so this EXISTS would never match
                    AND NOT EXISTS (
                        -- Exclude if adset cost exists for same adset/date/hour (we prefer adset cost)
                        SELECT 1 FROM adset_hourly_costs as_cost2
                        INNER JOIN clicks c4 ON c4.adset_id = as_cost2.adset_id
                        WHERE c4.ad_id = a_cost.ad_id
                            AND as_cost2.date = a_cost.date
                            AND as_cost2.hour = a_cost.hour
                        LIMIT 1
                    )
                    " . (($campaignIdForUnmatched !== null || $offerIdForUnmatched !== null || $landingPageIdForUnmatched !== null || preg_match('/EXISTS\s*\(\s*SELECT.*traffic_source_id\s*=\s*\?/i', $campaignFilter ?? '')) ? "
                    -- CRITICAL FIX: Only include costs if the ad has clicks matching the filter
                    -- For campaign_id: clicks exist ONLY in the filtered campaign (check ANY time)
                    -- For offer_id/landing_page_id: clicks exist with the filtered offer/landing page (check WITHIN date range)
                    -- NOTE: For campaign_id, we check ANY time to include costs even if clicks haven't arrived yet today
                    -- NOTE: For offer_id/landing_page_id, we check WITHIN date range to ensure costs are only included for the current query
                    AND EXISTS (
                        SELECT 1 FROM clicks c2
                        WHERE c2.ad_id = a_cost.ad_id
                            AND c2.ad_id IS NOT NULL
                            -- PERFORMANCE: Use generated columns (ad_id, adset_id) instead of JSON_EXTRACT for index usage
                            " . ($campaignIdForUnmatched !== null ? "AND c2.campaign_id = ?" : "") . "
                            " . (($offerIdForUnmatched !== null || $landingPageIdForUnmatched !== null) && $campaignIdForUnmatched === null ? "AND c2.ts >= ? AND c2.ts <= ?" : "") . "
                            " . ($offerIdForUnmatched !== null ? "AND c2.offer_id = ?" : "") . "
                            " . ($landingPageIdForUnmatched !== null ? "AND c2.landing_page_id = ?" : "") . "
                            " . (preg_match('/EXISTS\s*\(\s*SELECT.*traffic_source_id\s*=\s*\?/i', $campaignFilter ?? '') ? "AND EXISTS (SELECT 1 FROM campaigns WHERE campaigns.id = c2.campaign_id AND campaigns.traffic_source_id = ?)" : "") . "
                    )
                    " . ($campaignIdForUnmatched !== null ? "
                    AND NOT EXISTS (
                        SELECT 1 FROM clicks c3
                        WHERE c3.ad_id = a_cost.ad_id
                            AND c3.ad_id IS NOT NULL
                            AND c3.campaign_id != ?
                    )
                    " : "") . "
                    " : "
                    -- When no campaign filter, only include ads that have clicks in SOME campaign WITHIN THE DATE RANGE
                    -- This prevents costs without any clicks in the date range from being included in the overall total
                    -- CRITICAL FIX: Check for clicks in the date range, not at any time, to avoid including costs
                    -- from ads that have clicks outside the date range but no clicks in the date range
                    -- The EXISTS clause already checks for valid ad_id and adset_id tokens, so this should work correctly
                    AND EXISTS (
                        SELECT 1 FROM clicks c2
                        WHERE c2.ad_id = a_cost.ad_id
                            AND c2.ad_id IS NOT NULL
                            AND c2.ts >= ? AND c2.ts <= ?
                    )
                    ") . "
            ) as unmatched
        ";
        
        // Update bind types and values based on whether filter is provided
        // CRITICAL: Check for EXISTS traffic source filter OR other filters
        $hasAnyFilter = ($campaignIdForUnmatched !== null || $offerIdForUnmatched !== null || $landingPageIdForUnmatched !== null || $hasExistsTrafficSourceFilter);
        
        if ($hasAnyFilter) {
            // Create explicit boolean flags for each SQL section based on actual SQL conditions
            // These flags determine whether each section includes the traffic source EXISTS clause
            // CRITICAL: Match the exact SQL conditions to ensure bind types match placeholders
            $includeTrafficSourceInAdsetNotExists = $hasExistsTrafficSourceFilter; // Line 936: always included if filter exists
            
            // Line 938 condition determines if EXISTS section is added, and line 957 adds traffic source EXISTS inside that section
            // Use the same condition logic as the SQL to ensure consistency
            // CRITICAL: When ONLY EXISTS filter is present, this condition must be true to match SQL
            // Use $hasExistsTrafficSourceFilter instead of recalculating with preg_match for consistency
            $existsSectionCondition = ($campaignIdForUnmatched !== null || $offerIdForUnmatched !== null || $landingPageIdForUnmatched !== null || $hasExistsTrafficSourceFilter);
            // If hasExistsTrafficSourceFilter is true but condition is false, and no other filters exist, force it to true
            // This handles the case where ONLY the EXISTS filter is present
            if ($hasExistsTrafficSourceFilter && !$existsSectionCondition && $campaignIdForUnmatched === null && $offerIdForUnmatched === null && $landingPageIdForUnmatched === null) {
                $existsSectionCondition = true; // Force true to match SQL condition
            }
            $includeTrafficSourceInAdsetExists = $hasExistsTrafficSourceFilter && $existsSectionCondition; // Line 957: inside EXISTS section (line 938)
            
            $includeTrafficSourceInAdNotExists = $hasExistsTrafficSourceFilter; // Line 1012: always included if filter exists
            
            // Line 1014 condition determines if EXISTS section is added, and line 1033 adds traffic source EXISTS inside that section
            // Use the same condition logic as the SQL to ensure consistency
            // CRITICAL: When ONLY EXISTS filter is present, this condition must be true to match SQL
            // Use $hasExistsTrafficSourceFilter instead of recalculating with preg_match for consistency
            $adExistsSectionCondition = ($campaignIdForUnmatched !== null || $offerIdForUnmatched !== null || $landingPageIdForUnmatched !== null || $hasExistsTrafficSourceFilter);
            // If hasExistsTrafficSourceFilter is true but condition is false, and no other filters exist, force it to true
            // This handles the case where ONLY the EXISTS filter is present
            if ($hasExistsTrafficSourceFilter && !$adExistsSectionCondition && $campaignIdForUnmatched === null && $offerIdForUnmatched === null && $landingPageIdForUnmatched === null) {
                $adExistsSectionCondition = true; // Force true to match SQL condition
            }
            $includeTrafficSourceInAdExists = $hasExistsTrafficSourceFilter && $adExistsSectionCondition; // Line 1033: inside EXISTS section (line 1014)
            
            // Count parameters needed for each filter type
            $paramsPerFilter = 0;
            if ($campaignIdForUnmatched !== null) $paramsPerFilter++;
            if ($offerIdForUnmatched !== null) $paramsPerFilter++;
            if ($landingPageIdForUnmatched !== null) $paramsPerFilter++;
            
            // Calculate helper variables before building bind types
            $hasDateRangeInExists = (($offerIdForUnmatched !== null || $landingPageIdForUnmatched !== null) && $campaignIdForUnmatched === null);
            // Count offer_id/landing_page_id params for EXISTS (these come after campaign_id and date range)
            $existsFilterParamsCount = 0;
            if ($offerIdForUnmatched !== null) $existsFilterParamsCount++;
            if ($landingPageIdForUnmatched !== null) $existsFilterParamsCount++;
            
            // Build bind types and values independently for adset and ad sections
            // This ensures parameters match the actual SQL structure
            
            // ===== ADSET SECTION =====
            // Adset LEFT JOIN ON filter params: paramsPerFilter integers (campaign_id, offer_id, landing_page_id in that order)
            // Note: Variable name says "NotExists" but these are actually for LEFT JOIN ON clause
            // Note: Traffic source filter is NOT in LEFT JOIN ON clause - it's only in WHERE clause EXISTS
            $adsetNotExistsBindTypes = str_repeat('i', $paramsPerFilter);
            
            // Adset EXISTS: campaign_id (if present) + date range (if offer_id/landing_page_id without campaign_id) + offer_id/landing_page_id (if present)
            $adsetExistsBindTypes = '';
            if ($campaignIdForUnmatched !== null) {
                $adsetExistsBindTypes .= 'i'; // campaign_id comes first in EXISTS
            }
            if ($hasDateRangeInExists) {
                $adsetExistsBindTypes .= 'ss'; // Date range (dateFrom, dateTo) - only if no campaign_id
            }
            if ($existsFilterParamsCount > 0) {
                $adsetExistsBindTypes .= str_repeat('i', $existsFilterParamsCount);
            }
            // CRITICAL GUARD: Only add traffic source bind type if:
            // 1. hasExistsTrafficSourceFilter is true (filter contains EXISTS subquery)
            // 2. The filter actually contains the EXISTS pattern (double-check)
            // This prevents adding extra bind types for simple campaign_id filters
            if ($hasExistsTrafficSourceFilter && $campaignFilter && preg_match('/EXISTS\s*\(\s*SELECT.*traffic_source_id\s*=\s*\?/i', $campaignFilter)) {
                $adsetExistsBindTypes .= 'i'; // Traffic source EXISTS in EXISTS clause
                error_log("FacebookCostAggregator: Adding traffic source bind type to adsetExistsBindTypes. Filter: " . ($campaignFilter ?? 'null'));
            } else {
                // Explicitly do NOT add traffic source bind type for simple filters
                if ($hasExistsTrafficSourceFilter) {
                    error_log("FacebookCostAggregator: WARNING - hasExistsTrafficSourceFilter is true but filter doesn't match EXISTS pattern! Filter: " . ($campaignFilter ?? 'null'));
                }
            }
            
            // Adset NOT EXISTS other campaigns: (if campaign_id) 1 integer
            $adsetNotExistsOtherBindTypes = '';
            if ($campaignIdForUnmatched !== null) {
                $adsetNotExistsOtherBindTypes .= 'i';
            }
            
            // Adset WHERE clause traffic source EXISTS: REMOVED
            // This was removed because c.campaign_id is NULL when c.id IS NULL, so the EXISTS would never match
            // Traffic source filtering is handled in the EXISTS validation clause instead
            $adsetWhereTrafficSourceBindTypes = '';
            
            // Build adset parameter values
            $adsetNotExistsParams = [];
            if ($campaignIdForUnmatched !== null) {
                $adsetNotExistsParams[] = $campaignIdForUnmatched;
            }
            if ($offerIdForUnmatched !== null) {
                $adsetNotExistsParams[] = $offerIdForUnmatched;
            }
            if ($landingPageIdForUnmatched !== null) {
                $adsetNotExistsParams[] = $landingPageIdForUnmatched;
            }
            // Note: Traffic source is NOT added here - it's added separately for WHERE clause
            
            $adsetExistsParams = [];
            if ($campaignIdForUnmatched !== null) {
                $adsetExistsParams[] = $campaignIdForUnmatched;
            }
            if ($hasDateRangeInExists) {
                $adsetExistsParams[] = $dateFrom;
                $adsetExistsParams[] = $dateTo;
            }
            if ($offerIdForUnmatched !== null) {
                $adsetExistsParams[] = $offerIdForUnmatched;
            }
            if ($landingPageIdForUnmatched !== null) {
                $adsetExistsParams[] = $landingPageIdForUnmatched;
            }
            // CRITICAL GUARD: Only add traffic source parameter if bind type was added
            // This ensures bind types and values stay in sync
            if ($hasExistsTrafficSourceFilter && $campaignFilter && preg_match('/EXISTS\s*\(\s*SELECT.*traffic_source_id\s*=\s*\?/i', $campaignFilter)) {
                $adsetExistsParams[] = $trafficSourceIdForUnmatched;
            }
            
            $adsetNotExistsOtherParams = [];
            if ($campaignIdForUnmatched !== null) {
                $adsetNotExistsOtherParams[] = $campaignIdForUnmatched;
            }
            
            // Adset WHERE clause traffic source EXISTS parameter: REMOVED
            // This was removed because c.campaign_id is NULL when c.id IS NULL, so the EXISTS would never match
            $adsetWhereTrafficSourceParams = [];
            
            // ===== AD SECTION =====
            // Ad LEFT JOIN ON filter params: paramsPerFilter integers (campaign_id, offer_id, landing_page_id in that order)
            // Note: Variable name says "NotExists" but these are actually for LEFT JOIN ON clause
            // Note: Traffic source filter is NOT in LEFT JOIN ON clause - it's only in WHERE clause EXISTS
            $adNotExistsBindTypes = str_repeat('i', $paramsPerFilter);
            
            // Ad EXISTS: campaign_id (if present) + date range (if offer_id/landing_page_id without campaign_id) + offer_id/landing_page_id (if present)
            $adExistsBindTypes = '';
            if ($campaignIdForUnmatched !== null) {
                $adExistsBindTypes .= 'i'; // campaign_id comes first in EXISTS
            }
            if ($hasDateRangeInExists) {
                $adExistsBindTypes .= 'ss'; // Date range (dateFrom, dateTo) - only if no campaign_id
            }
            if ($existsFilterParamsCount > 0) {
                $adExistsBindTypes .= str_repeat('i', $existsFilterParamsCount);
            }
            // CRITICAL GUARD: Only add traffic source bind type if:
            // 1. hasExistsTrafficSourceFilter is true (filter contains EXISTS subquery)
            // 2. The filter actually contains the EXISTS pattern (double-check)
            // This prevents adding extra bind types for simple campaign_id filters
            if ($hasExistsTrafficSourceFilter && $campaignFilter && preg_match('/EXISTS\s*\(\s*SELECT.*traffic_source_id\s*=\s*\?/i', $campaignFilter)) {
                $adExistsBindTypes .= 'i'; // Traffic source EXISTS in EXISTS clause
                error_log("FacebookCostAggregator: Adding traffic source bind type to adExistsBindTypes. Filter: " . ($campaignFilter ?? 'null'));
            } else {
                // Explicitly do NOT add traffic source bind type for simple filters
                if ($hasExistsTrafficSourceFilter) {
                    error_log("FacebookCostAggregator: WARNING - hasExistsTrafficSourceFilter is true but filter doesn't match EXISTS pattern! Filter: " . ($campaignFilter ?? 'null'));
                }
            }
            
            // Ad NOT EXISTS other campaigns: (if campaign_id) 1 integer
            $adNotExistsOtherBindTypes = '';
            if ($campaignIdForUnmatched !== null) {
                $adNotExistsOtherBindTypes .= 'i';
            }
            
            // Ad WHERE clause traffic source EXISTS: REMOVED
            // This was removed because c.campaign_id is NULL when c.id IS NULL, so the EXISTS would never match
            // Traffic source filtering is handled in the EXISTS validation clause instead
            $adWhereTrafficSourceBindTypes = '';
            
            // Build ad parameter values independently (don't copy from adset)
            $adNotExistsParams = [];
            if ($campaignIdForUnmatched !== null) {
                $adNotExistsParams[] = $campaignIdForUnmatched;
            }
            if ($offerIdForUnmatched !== null) {
                $adNotExistsParams[] = $offerIdForUnmatched;
            }
            if ($landingPageIdForUnmatched !== null) {
                $adNotExistsParams[] = $landingPageIdForUnmatched;
            }
            // Note: Traffic source is NOT added here - it's added separately for WHERE clause
            
            $adExistsParams = [];
            if ($campaignIdForUnmatched !== null) {
                $adExistsParams[] = $campaignIdForUnmatched;
            }
            if ($hasDateRangeInExists) {
                $adExistsParams[] = $dateFrom;
                $adExistsParams[] = $dateTo;
            }
            if ($offerIdForUnmatched !== null) {
                $adExistsParams[] = $offerIdForUnmatched;
            }
            if ($landingPageIdForUnmatched !== null) {
                $adExistsParams[] = $landingPageIdForUnmatched;
            }
            // CRITICAL GUARD: Only add traffic source parameter if bind type was added
            // This ensures bind types and values stay in sync
            if ($hasExistsTrafficSourceFilter && $campaignFilter && preg_match('/EXISTS\s*\(\s*SELECT.*traffic_source_id\s*=\s*\?/i', $campaignFilter)) {
                $adExistsParams[] = $trafficSourceIdForUnmatched;
            }
            
            $adNotExistsOtherParams = [];
            if ($campaignIdForUnmatched !== null) {
                $adNotExistsOtherParams[] = $campaignIdForUnmatched;
            }
            
            // Ad WHERE clause traffic source EXISTS parameter: REMOVED
            // This was removed because c.campaign_id is NULL when c.id IS NULL, so the EXISTS would never match
            $adWhereTrafficSourceParams = [];
            
            // Combine all bind types and values in the order they appear in SQL
            // Order: Adset LEFT JOIN ON filter -> Adset EXISTS validation -> Adset NOT EXISTS other -> Ad LEFT JOIN ON filter -> Ad EXISTS validation -> Ad NOT EXISTS other
            // NOTE: WHERE clause traffic source EXISTS removed (was always empty, c.campaign_id is NULL when c.id IS NULL)
            // DEBUG: Save initial bind types before concatenation for accurate logging
            $initialBindTypes = $unmatchedBindTypes;
            $initialBindTypesLength = strlen($initialBindTypes);
            
            // DEBUG: Log each bind type string to identify where extra types come from
            $adsetNotExistsLen = strlen($adsetNotExistsBindTypes);
            $adsetExistsLen = strlen($adsetExistsBindTypes);
            $adsetNotExistsOtherLen = strlen($adsetNotExistsOtherBindTypes);
            $adNotExistsLen = strlen($adNotExistsBindTypes);
            $adExistsLen = strlen($adExistsBindTypes);
            $adNotExistsOtherLen = strlen($adNotExistsOtherBindTypes);
            $totalAppended = $adsetNotExistsLen + $adsetExistsLen + $adsetNotExistsOtherLen + $adNotExistsLen + $adExistsLen + $adNotExistsOtherLen;
            
            error_log("FacebookCostAggregator: ===== BIND TYPE BUILDING DEBUG =====");
            error_log("FacebookCostAggregator: Initial bind types: '{$initialBindTypes}' (length: {$initialBindTypesLength})");
            error_log("FacebookCostAggregator: adsetNotExistsBindTypes: '{$adsetNotExistsBindTypes}' (length: {$adsetNotExistsLen})");
            error_log("FacebookCostAggregator: adsetExistsBindTypes: '{$adsetExistsBindTypes}' (length: {$adsetExistsLen})");
            error_log("FacebookCostAggregator: adsetNotExistsOtherBindTypes: '{$adsetNotExistsOtherBindTypes}' (length: {$adsetNotExistsOtherLen})");
            error_log("FacebookCostAggregator: adNotExistsBindTypes: '{$adNotExistsBindTypes}' (length: {$adNotExistsLen})");
            error_log("FacebookCostAggregator: adExistsBindTypes: '{$adExistsBindTypes}' (length: {$adExistsLen})");
            error_log("FacebookCostAggregator: adNotExistsOtherBindTypes: '{$adNotExistsOtherBindTypes}' (length: {$adNotExistsOtherLen})");
            error_log("FacebookCostAggregator: Total appended length: {$totalAppended}, Expected final length: " . ($initialBindTypesLength + $totalAppended));
            
            // Use $hasExistsTrafficSourceFilter (already calculated) for consistency
            error_log("FacebookCostAggregator: Traffic source filter - hasExistsTrafficSourceFilter: " . ($hasExistsTrafficSourceFilter ? 'true' : 'false'));
            error_log("FacebookCostAggregator: Campaign filter: " . ($campaignFilter ?? 'null'));
            
            // CRITICAL GUARD: Verify that traffic source bind types are only added when filter actually contains EXISTS
            // For simple campaign_id filters, hasExistsTrafficSourceFilter should be false
            if ($hasExistsTrafficSourceFilter && $campaignFilter && !preg_match('/EXISTS\s*\(\s*SELECT.*traffic_source_id\s*=\s*\?/i', $campaignFilter)) {
                error_log("FacebookCostAggregator: WARNING - hasExistsTrafficSourceFilter is true but filter doesn't contain EXISTS subquery! Filter: " . $campaignFilter);
                // This shouldn't happen, but if it does, we should not add traffic source bind types
                $hasExistsTrafficSourceFilter = false;
                // Rebuild bind types without traffic source if they were incorrectly added
                if (strlen($adsetExistsBindTypes) > 0 && substr($adsetExistsBindTypes, -1) === 'i' && $campaignIdForUnmatched !== null && $offerIdForUnmatched === null && $landingPageIdForUnmatched === null) {
                    // Check if last character was added for traffic source - if so, remove it
                    $adsetExistsBindTypes = 'i'; // Should only be 'i' for campaign_id
                    $adsetExistsLen = 1;
                    error_log("FacebookCostAggregator: AUTO-FIX - Removed extra traffic source bind type from adsetExistsBindTypes");
                }
                if (strlen($adExistsBindTypes) > 0 && substr($adExistsBindTypes, -1) === 'i' && $campaignIdForUnmatched !== null && $offerIdForUnmatched === null && $landingPageIdForUnmatched === null) {
                    // Check if last character was added for traffic source - if so, remove it
                    $adExistsBindTypes = 'i'; // Should only be 'i' for campaign_id
                    $adExistsLen = 1;
                    error_log("FacebookCostAggregator: AUTO-FIX - Removed extra traffic source bind type from adExistsBindTypes");
                }
                // Recalculate total appended
                $totalAppended = $adsetNotExistsLen + $adsetExistsLen + $adsetNotExistsOtherLen + $adNotExistsLen + $adExistsLen + $adNotExistsOtherLen;
            }
            
            // CRITICAL: Verify bind types match bind values before concatenation
            $adsetNotExistsCount = count($adsetNotExistsParams);
            $adsetExistsCount = count($adsetExistsParams);
            $adsetNotExistsOtherCount = count($adsetNotExistsOtherParams);
            $adNotExistsCount = count($adNotExistsParams);
            $adExistsCount = count($adExistsParams);
            $adNotExistsOtherCount = count($adNotExistsOtherParams);
            
            error_log("FacebookCostAggregator: Bind type vs value counts - adsetNotExists: {$adsetNotExistsLen} types vs {$adsetNotExistsCount} values, adsetExists: {$adsetExistsLen} types vs {$adsetExistsCount} values, adsetNotExistsOther: {$adsetNotExistsOtherLen} types vs {$adsetNotExistsOtherCount} values, adNotExists: {$adNotExistsLen} types vs {$adNotExistsCount} values, adExists: {$adExistsLen} types vs {$adExistsCount} values, adNotExistsOther: {$adNotExistsOtherLen} types vs {$adNotExistsOtherCount} values");
            
            // Verify each bind type string matches its corresponding parameter count
            if ($adsetNotExistsLen !== $adsetNotExistsCount) {
                error_log("FacebookCostAggregator: MISMATCH - adsetNotExistsBindTypes length ({$adsetNotExistsLen}) doesn't match adsetNotExistsParams count ({$adsetNotExistsCount})");
            }
            if ($adsetExistsLen !== $adsetExistsCount) {
                error_log("FacebookCostAggregator: MISMATCH - adsetExistsBindTypes length ({$adsetExistsLen}) doesn't match adsetExistsParams count ({$adsetExistsCount})");
            }
            if ($adsetNotExistsOtherLen !== $adsetNotExistsOtherCount) {
                error_log("FacebookCostAggregator: MISMATCH - adsetNotExistsOtherBindTypes length ({$adsetNotExistsOtherLen}) doesn't match adsetNotExistsOtherParams count ({$adsetNotExistsOtherCount})");
            }
            if ($adNotExistsLen !== $adNotExistsCount) {
                error_log("FacebookCostAggregator: MISMATCH - adNotExistsBindTypes length ({$adNotExistsLen}) doesn't match adNotExistsParams count ({$adNotExistsCount})");
            }
            if ($adExistsLen !== $adExistsCount) {
                error_log("FacebookCostAggregator: MISMATCH - adExistsBindTypes length ({$adExistsLen}) doesn't match adExistsParams count ({$adExistsCount})");
            }
            if ($adNotExistsOtherLen !== $adNotExistsOtherCount) {
                error_log("FacebookCostAggregator: MISMATCH - adNotExistsOtherBindTypes length ({$adNotExistsOtherLen}) doesn't match adNotExistsOtherParams count ({$adNotExistsOtherCount})");
            }
            
            // FINAL VALIDATION: Verify bind types match expected count before concatenation
            // For a simple campaign_id filter, we should have exactly 18 total bind types
            $expectedTotalForCampaignId = 12 + 1 + 1 + 1 + 1 + 1 + 1; // Initial (12) + 6 filter params (1 each)
            $calculatedTotal = $initialBindTypesLength + $totalAppended;
            
            // If we have a simple campaign_id filter, verify the count
            if ($campaignIdForUnmatched !== null && $offerIdForUnmatched === null && $landingPageIdForUnmatched === null && !$hasExistsTrafficSourceFilter) {
                if ($calculatedTotal !== $expectedTotalForCampaignId) {
                    error_log("FacebookCostAggregator: CRITICAL - Expected {$expectedTotalForCampaignId} bind types for simple campaign_id filter, but calculated {$calculatedTotal}");
                    error_log("FacebookCostAggregator: Initial: {$initialBindTypesLength}, Appended: {$totalAppended}");
                    error_log("FacebookCostAggregator: adsetExistsBindTypes: '{$adsetExistsBindTypes}' (should be 'i'), adExistsBindTypes: '{$adExistsBindTypes}' (should be 'i')");
                    
                    // Auto-fix: Ensure adsetExistsBindTypes and adExistsBindTypes are exactly 'i' for simple campaign_id
                    if ($adsetExistsBindTypes !== 'i') {
                        error_log("FacebookCostAggregator: AUTO-FIX - Correcting adsetExistsBindTypes from '{$adsetExistsBindTypes}' to 'i'");
                        $adsetExistsBindTypes = 'i';
                        // Recalculate length
                        $adsetExistsLen = 1;
                        $totalAppended = $adsetNotExistsLen + $adsetExistsLen + $adsetNotExistsOtherLen + $adNotExistsLen + $adExistsLen + $adNotExistsOtherLen;
                    }
                    if ($adExistsBindTypes !== 'i') {
                        error_log("FacebookCostAggregator: AUTO-FIX - Correcting adExistsBindTypes from '{$adExistsBindTypes}' to 'i'");
                        $adExistsBindTypes = 'i';
                        // Recalculate length
                        $adExistsLen = 1;
                        $totalAppended = $adsetNotExistsLen + $adsetExistsLen + $adsetNotExistsOtherLen + $adNotExistsLen + $adExistsLen + $adNotExistsOtherLen;
                    }
                    
                    // Double-check after auto-fix
                    $calculatedTotalAfterFix = $initialBindTypesLength + $totalAppended;
                    if ($calculatedTotalAfterFix !== $expectedTotalForCampaignId) {
                        error_log("FacebookCostAggregator: WARNING - Auto-fix did not resolve mismatch. Expected: {$expectedTotalForCampaignId}, Got: {$calculatedTotalAfterFix}");
                        error_log("FacebookCostAggregator: Component breakdown - adsetNotExists: {$adsetNotExistsLen}, adsetExists: {$adsetExistsLen}, adsetNotExistsOther: {$adsetNotExistsOtherLen}, adNotExists: {$adNotExistsLen}, adExists: {$adExistsLen}, adNotExistsOther: {$adNotExistsOtherLen}");
                    }
                }
            }
            
            // CRITICAL: Before concatenation, count SQL placeholders to validate our bind types
            $sqlPlaceholderCount = substr_count($unmatchedCostsQuery, '?');
            $expectedBindTypesCount = $initialBindTypesLength + $totalAppended;
            
            if ($expectedBindTypesCount !== $sqlPlaceholderCount) {
                error_log("FacebookCostAggregator: CRITICAL PRE-CONCATENATION MISMATCH - Expected bind types count ({$expectedBindTypesCount}) doesn't match SQL placeholder count ({$sqlPlaceholderCount})");
                error_log("FacebookCostAggregator: Filter: " . ($campaignFilter ?? 'null'));
                error_log("FacebookCostAggregator: hasExistsTrafficSourceFilter: " . ($hasExistsTrafficSourceFilter ? 'true' : 'false'));
                error_log("FacebookCostAggregator: Component breakdown - Initial: {$initialBindTypesLength}, adsetNotExists: {$adsetNotExistsLen}, adsetExists: {$adsetExistsLen}, adsetNotExistsOther: {$adsetNotExistsOtherLen}, adNotExists: {$adNotExistsLen}, adExists: {$adExistsLen}, adNotExistsOther: {$adNotExistsOtherLen}");
                
                // If we have exactly one extra bind type, try to identify and remove it
                if ($expectedBindTypesCount === $sqlPlaceholderCount + 1) {
                    error_log("FacebookCostAggregator: Attempting auto-fix for 1 extra bind type");
                    // For simple campaign_id filter, both EXISTS bind types should be exactly 'i'
                    if ($campaignIdForUnmatched !== null && $offerIdForUnmatched === null && $landingPageIdForUnmatched === null) {
                        if (strlen($adsetExistsBindTypes) > 1) {
                            error_log("FacebookCostAggregator: AUTO-FIX - Trimming adsetExistsBindTypes from '{$adsetExistsBindTypes}' to 'i'");
                            $adsetExistsBindTypes = 'i';
                            $adsetExistsLen = 1;
                        }
                        if (strlen($adExistsBindTypes) > 1) {
                            error_log("FacebookCostAggregator: AUTO-FIX - Trimming adExistsBindTypes from '{$adExistsBindTypes}' to 'i'");
                            $adExistsBindTypes = 'i';
                            $adExistsLen = 1;
                        }
                        // Recalculate total
                        $totalAppended = $adsetNotExistsLen + $adsetExistsLen + $adsetNotExistsOtherLen + $adNotExistsLen + $adExistsLen + $adNotExistsOtherLen;
                        $expectedBindTypesCount = $initialBindTypesLength + $totalAppended;
                        error_log("FacebookCostAggregator: After auto-fix - Expected bind types count: {$expectedBindTypesCount}, SQL placeholder count: {$sqlPlaceholderCount}");
                    }
                }
            }
            
            $unmatchedBindTypes .= $adsetNotExistsBindTypes . $adsetExistsBindTypes . $adsetNotExistsOtherBindTypes . $adNotExistsBindTypes . $adExistsBindTypes . $adNotExistsOtherBindTypes;
            
            $finalBindTypesLength = strlen($unmatchedBindTypes);
            error_log("FacebookCostAggregator: Final bind types: '{$unmatchedBindTypes}' (length: {$finalBindTypesLength})");
            error_log("FacebookCostAggregator: ===== END BIND TYPE BUILDING DEBUG =====");
            
            // CRITICAL: Verify bind types match bind values before merging
            $totalAppendedParams = count($adsetNotExistsParams) + count($adsetExistsParams) + count($adsetNotExistsOtherParams) + count($adNotExistsParams) + count($adExistsParams) + count($adNotExistsOtherParams);
            if ($totalAppended !== $totalAppendedParams) {
                error_log("FacebookCostAggregator: CRITICAL MISMATCH - Total appended bind types length ({$totalAppended}) doesn't match total appended params count ({$totalAppendedParams})");
                error_log("FacebookCostAggregator: Breakdown - adsetNotExists: " . strlen($adsetNotExistsBindTypes) . " types vs " . count($adsetNotExistsParams) . " params");
                error_log("FacebookCostAggregator: Breakdown - adsetExists: " . strlen($adsetExistsBindTypes) . " types vs " . count($adsetExistsParams) . " params");
                error_log("FacebookCostAggregator: Breakdown - adsetNotExistsOther: " . strlen($adsetNotExistsOtherBindTypes) . " types vs " . count($adsetNotExistsOtherParams) . " params");
                error_log("FacebookCostAggregator: Breakdown - adNotExists: " . strlen($adNotExistsBindTypes) . " types vs " . count($adNotExistsParams) . " params");
                error_log("FacebookCostAggregator: Breakdown - adExists: " . strlen($adExistsBindTypes) . " types vs " . count($adExistsParams) . " params");
                error_log("FacebookCostAggregator: Breakdown - adNotExistsOther: " . strlen($adNotExistsOtherBindTypes) . " types vs " . count($adNotExistsOtherParams) . " params");
            }
            
            $unmatchedBindValues = array_merge($unmatchedBindValues, 
                $adsetNotExistsParams,  // LEFT JOIN ON filter for adset (campaign_id, offer_id, landing_page_id)
                $adsetExistsParams,     // EXISTS validation for adset (checks if adset has clicks matching filter)
                $adsetNotExistsOtherParams, // NOT EXISTS other campaigns for adset (only if campaign_id filter)
                $adNotExistsParams,     // LEFT JOIN ON filter for ad (campaign_id, offer_id, landing_page_id)
                $adExistsParams,        // EXISTS validation for ad (checks if ad has clicks matching filter)
                $adNotExistsOtherParams  // NOT EXISTS other campaigns for ad (only if campaign_id filter)
            );
        } else {
            // When no campaign filter:
            // - Adset section: No EXISTS clause (line 979-984 just has a comment)
            // - Ad section: HAS an EXISTS clause (lines 1061-1071) with 2 placeholders for date range (line 1070)
            // So we need to add 2 more parameters (ss) for the ad EXISTS clause
            $unmatchedBindTypes .= 'ss'; // 2 strings for ad EXISTS date range
            $unmatchedBindValues = array_merge($unmatchedBindValues, [
                $dateFrom, // EXISTS for ad (dateFrom)
                $dateTo    // EXISTS for ad (dateTo)
            ]);
        }
        
        // Validation: Check bind parameter counts before binding
        $bindTypesCount = strlen($unmatchedBindTypes);
        $bindValuesCount = count($unmatchedBindValues);
        $placeholderCount = substr_count($unmatchedCostsQuery, '?');
        
        // FINAL SAFETY CHECK: If we have exactly one extra bind type, try to fix it
        if ($bindTypesCount === $placeholderCount + 1 && $bindValuesCount === $placeholderCount) {
            error_log("FacebookCostAggregator: FINAL SAFETY CHECK - Detected exactly 1 extra bind type. Attempting auto-fix.");
            error_log("FacebookCostAggregator: Bind types before fix: '{$unmatchedBindTypes}' (length: {$bindTypesCount})");
            error_log("FacebookCostAggregator: Filter: " . ($campaignFilter ?? 'null'));
            
            // For simple campaign_id filters, try removing the last character if it's an 'i'
            if ($campaignIdForUnmatched !== null && $offerIdForUnmatched === null && $landingPageIdForUnmatched === null && !$hasExistsTrafficSourceFilter) {
                if (substr($unmatchedBindTypes, -1) === 'i') {
                    $unmatchedBindTypes = substr($unmatchedBindTypes, 0, -1);
                    $bindTypesCount = strlen($unmatchedBindTypes);
                    error_log("FacebookCostAggregator: AUTO-FIX APPLIED - Removed last 'i' character. New bind types: '{$unmatchedBindTypes}' (length: {$bindTypesCount})");
                }
            }
        }
        
        // CRITICAL: Validate bind parameter counts match
        if ($bindTypesCount !== $bindValuesCount || $bindTypesCount !== $placeholderCount) {
            // Log detailed breakdown for debugging
            error_log("FacebookCostAggregator: ===== BIND PARAMETER MISMATCH DETECTED =====");
            error_log("FacebookCostAggregator: Bind Types Count: {$bindTypesCount}");
            error_log("FacebookCostAggregator: Bind Values Count: {$bindValuesCount}");
            error_log("FacebookCostAggregator: SQL Placeholder Count: {$placeholderCount}");
            error_log("FacebookCostAggregator: Filter: " . ($campaignFilter ?? 'null'));
            error_log("FacebookCostAggregator: Campaign ID: " . ($campaignIdForUnmatched ?? 'null'));
            error_log("FacebookCostAggregator: Offer ID: " . ($offerIdForUnmatched ?? 'null'));
            error_log("FacebookCostAggregator: Landing Page ID: " . ($landingPageIdForUnmatched ?? 'null'));
            error_log("FacebookCostAggregator: Has Traffic Source Filter: " . (isset($hasExistsTrafficSourceFilter) && $hasExistsTrafficSourceFilter ? 'true' : 'false'));
            error_log("FacebookCostAggregator: Final bind types string: '{$unmatchedBindTypes}'");
            error_log("FacebookCostAggregator: Bind values: " . json_encode($unmatchedBindValues));
            error_log("FacebookCostAggregator: ===== END MISMATCH DEBUG =====");
            
            throw new \Exception("Bind parameter mismatch: Types={$bindTypesCount}, Values={$bindValuesCount}, Placeholders={$placeholderCount}. More parameters than placeholders - likely missing SQL condition. Filter: " . ($campaignFilter ?? 'null') . ". See error log for full details.");
        }
        
        // Log additional debug info if filter exists
        if (isset($hasAnyFilter) && $hasAnyFilter) {
            $paramsPerFilter = 0;
            if ($campaignIdForUnmatched !== null) $paramsPerFilter++;
            if ($offerIdForUnmatched !== null) $paramsPerFilter++;
            if ($landingPageIdForUnmatched !== null) $paramsPerFilter++;
            $existsFilterParamsCount = 0;
            if ($offerIdForUnmatched !== null) $existsFilterParamsCount++;
            if ($landingPageIdForUnmatched !== null) $existsFilterParamsCount++;
            $hasDateRangeInExists = (($offerIdForUnmatched !== null || $landingPageIdForUnmatched !== null) && $campaignIdForUnmatched === null);
            error_log("FacebookCostAggregator: Filter params - Campaign ID: " . ($campaignIdForUnmatched ?? 'null') . ", Offer ID: " . ($offerIdForUnmatched ?? 'null') . ", Landing Page ID: " . ($landingPageIdForUnmatched ?? 'null'));
            error_log("FacebookCostAggregator: paramsPerFilter: {$paramsPerFilter}, existsFilterParamsCount: {$existsFilterParamsCount}, hasDateRangeInExists: " . ($hasDateRangeInExists ? 'true' : 'false'));
        }
        
        // Validation check is now done earlier (before this point)
        // Prepare statement
        $unmatchedStmt = $this->db->prepare($unmatchedCostsQuery);
        if (!$unmatchedStmt) {
            error_log("FacebookCostAggregator: Failed to prepare unmatched costs query - Error: " . $this->db->error);
        }
        
        // Additional validation (redundant but kept for safety)
        if ($bindTypesCount !== $bindValuesCount || $bindTypesCount !== $placeholderCount) {
            // Enhanced error logging with detailed information
            $errorDetails = [
                "Bind Types Count: {$bindTypesCount}",
                "Bind Values Count: {$bindValuesCount}",
                "SQL Placeholder Count: {$placeholderCount}",
                "Bind Types: {$unmatchedBindTypes}",
                "Filter: " . ($campaignFilter ?? 'null'),
                "Filter Params: " . json_encode($campaignFilterParams ?? []),
                "Campaign ID: " . ($campaignIdForUnmatched ?? 'null'),
                "Offer ID: " . ($offerIdForUnmatched ?? 'null'),
                "Landing Page ID: " . ($landingPageIdForUnmatched ?? 'null'),
                "Has EXISTS Traffic Source Filter: " . ($hasExistsTrafficSourceFilter ? 'true' : 'false'),
                "Traffic Source ID: " . ($trafficSourceIdForUnmatched ?? 'null'),
                "SQL Preview (first 1000 chars): " . substr($unmatchedCostsQuery, 0, 1000)
            ];
            
            error_log("FacebookCostAggregator: MISMATCH DETECTED!");
            foreach ($errorDetails as $detail) {
                error_log("FacebookCostAggregator: " . $detail);
            }
            
            // Determine which section has the mismatch
            $mismatchSection = "Unknown";
            if ($bindTypesCount > $placeholderCount) {
                $mismatchSection = "More parameters than placeholders - likely missing SQL condition";
            } elseif ($bindTypesCount < $placeholderCount) {
                $mismatchSection = "More placeholders than parameters - likely missing parameter binding";
            } elseif ($bindTypesCount !== $bindValuesCount) {
                $mismatchSection = "Type count doesn't match value count - parameter array issue";
            }
            
            // Don't proceed if there's a mismatch - this will help us debug
            $errorMessage = "Bind parameter mismatch: Types={$bindTypesCount}, Values={$bindValuesCount}, Placeholders={$placeholderCount}. " . $mismatchSection . ". Filter: " . ($campaignFilter ?? 'null') . ". See error log for full details.";
            throw new \Exception($errorMessage);
        }
        
        $unmatchedStmt->bind_param($unmatchedBindTypes, ...$unmatchedBindValues);
        $unmatchedStmt->execute();
        if ($unmatchedStmt->error) {
            error_log("FacebookCostAggregator: Error executing unmatched costs query - Error: " . $unmatchedStmt->error);
        }
        $unmatchedResult = $unmatchedStmt->get_result()->fetch_assoc();
        $fbCostUnmatchedDelta = (float)($unmatchedResult['unmatched_cost'] ?? 0.0);
        
        // CRITICAL FIX: Use cumulative spend for unmatched costs (more accurate for costs without clicks)
        // Cumulative represents true total spend from Meta API, while delta sum can be incomplete
        // Pass timezone parameters to ensure correct date filtering when user timezone is provided
        $fbCostUnmatchedCumulative = $this->getUnmatchedCostsWithCumulative($dateFrom, $dateTo, $campaignFilter, $campaignFilterParams, $userTimezone, $userSelectedDateFrom, $userSelectedDateTo, $timezoneOffset);
        
        // CRITICAL FIX: Prefer delta when it's $0.00 or very small and cumulative is much higher
        // This prevents yesterday's cumulative spend from being included in today's unmatched costs
        // When delta is $0.00 or very small (no/minimal costs today) but cumulative is high (includes yesterday), use delta
        if (($fbCostUnmatchedDelta == 0.0 || $fbCostUnmatchedDelta < 0.10) && $fbCostUnmatchedCumulative > 1.0) {
            // Delta correctly shows $0.00 or very small (no/minimal costs today), but cumulative includes previous days' spend
            // Use delta to avoid showing yesterday's costs in today's total
            $fbCostUnmatched = $fbCostUnmatchedDelta;
            error_log("FacebookCostAggregator: Unmatched costs - MIDNIGHT SAFETY CHECK: Using delta: {$fbCostUnmatchedDelta} instead of cumulative: {$fbCostUnmatchedCumulative}. Delta correctly shows no/minimal costs for today, cumulative includes previous day's final cumulative spend. This prevents yesterday's costs from appearing in today's unmatched costs right after midnight.");
        } elseif ($fbCostUnmatchedCumulative > 0 && $fbCostUnmatchedDelta > 0) {
            // Both have values - prefer cumulative when both are non-zero (more accurate)
            $fbCostUnmatched = $fbCostUnmatchedCumulative;
        } elseif ($fbCostUnmatchedCumulative > 0) {
            // Only cumulative available - use it
            $fbCostUnmatched = $fbCostUnmatchedCumulative;
        } else {
            // Use delta (may be $0.00 or have a value)
            $fbCostUnmatched = $fbCostUnmatchedDelta;
        }
        
        // Debug logging for unmatched costs result
        if ($campaignIdForUnmatched !== null || $offerIdForUnmatched !== null || $landingPageIdForUnmatched !== null) {
            $filterInfo = [];
            if ($campaignIdForUnmatched !== null) $filterInfo[] = "Campaign ID: {$campaignIdForUnmatched}";
            if ($offerIdForUnmatched !== null) $filterInfo[] = "Offer ID: {$offerIdForUnmatched}";
            if ($landingPageIdForUnmatched !== null) $filterInfo[] = "Landing Page ID: {$landingPageIdForUnmatched}";
            error_log("FacebookCostAggregator: Unmatched costs result - " . implode(', ', $filterInfo) . ", Unmatched Cost (delta): {$fbCostUnmatched}, Unmatched Cost (cumulative): {$fbCostUnmatchedCumulative}, Using: {$fbCostUnmatched}");
            // For offer/landing page filters, log more details
            if ($offerIdForUnmatched !== null || $landingPageIdForUnmatched !== null) {
                error_log("FacebookCostAggregator: DEBUG - Matched Cost: {$fbCostFromClicks}, Unmatched Cost (cumulative): {$fbCostUnmatched}, Calculated: " . ($fbCostFromClicks + $fbCostUnmatched));
            }
        } else {
            error_log("FacebookCostAggregator: Unmatched costs result (no filter) - Unmatched Cost (delta): {$fbCostUnmatched}, Unmatched Cost (cumulative): {$fbCostUnmatchedCumulative}, Using: {$fbCostUnmatched}");
            // Log the actual SQL query for debugging (first 500 chars)
            $queryPreview = substr($unmatchedCostsQuery, 0, 500);
            error_log("FacebookCostAggregator: Unmatched costs query preview: " . $queryPreview . "...");
        }

        // Fallback safeguard: use max(total_spend) per adset/ad within the range to avoid undercounting
        // when deltas are incomplete (e.g., missing clicks in hours that still have spend).
        // CRITICAL FIX: Use PST date grouping instead of UTC date range to capture final cumulative value
        // This ensures we get the MAX cumulative spend for the entire PST day, not just within UTC range
        $fbCostByMax = 0.0;
        // Use adset totals only to avoid double-counting (ad rows can represent the same spend)
        
        // CRITICAL FIX: Determine if we should use PST date grouping or UTC range
        // Must check for timezoneOffset to ensure timezone filtering works correctly
        // Calculate timezoneOffset if missing but userTimezone is provided
        if ($userTimezone !== null && $userTimezone !== 'UTC' && $timezoneOffset === null) {
            try {
                $tz = new \DateTimeZone($userTimezone);
                $utcTz = new \DateTimeZone('UTC');
                $testDate = new \DateTime($dateFrom, $utcTz);
                $testDate->setTimezone($tz);
                $offset = $tz->getOffset($testDate);
                $hours = intval($offset / 3600);
                $minutes = intval(($offset % 3600) / 60);
                $timezoneOffset = sprintf('%+03d:%02d', $hours, abs($minutes));
            } catch (\Exception $e) {
                $timezoneOffset = '+00:00';
            }
        }
        $usePstDateGrouping = ($userTimezone !== null && $userTimezone !== 'UTC' && $userSelectedDateFrom !== null && $userSelectedDateTo !== null && $timezoneOffset !== null);
        
        // Build the complete query with conditional date clause
        // Use PST date grouping to find MAX cumulative for the entire PST day when timezone info is available
        // This captures the final cumulative value even if it's saved outside the UTC range
        // For UTC path: use index-friendly tuple comparison
        // For PST path: still need CONVERT_TZ for timezone conversion, but optimize where possible
        // CRITICAL FIX: For unmatched costs, use SUM(delta_spend) instead of MAX(cumulative)
        // Cumulative includes previous days' spend, which causes yesterday's costs to show in today's total
        // Delta spend only counts the incremental cost for the specific date/hour, which is what we want
        // Only use MAX(cumulative) as a fallback when delta_spend is missing or zero
        $maxSpendQuery = "
            SELECT COALESCE(SUM(max_spend), 0) AS sum_spend FROM (
                SELECT MAX(as_cost.spend) AS max_spend
                FROM adset_hourly_costs as_cost
                WHERE " . ($usePstDateGrouping ? 
                    "DATE(CONVERT_TZ(CONCAT(as_cost.date, ' ', LPAD(as_cost.hour, 2, '0'), ':00:00'), '+00:00', ?)) >= ? AND DATE(CONVERT_TZ(CONCAT(as_cost.date, ' ', LPAD(as_cost.hour, 2, '0'), ':00:00'), '+00:00', ?)) <= ?" :
                    "(as_cost.date, as_cost.hour) >= (?, ?) AND (as_cost.date, as_cost.hour) <= (?, ?)") . "
                      AND as_cost.adset_id NOT LIKE '{{%' AND as_cost.adset_id NOT LIKE '{ts:%'
                      " . ($campaignIdForUnmatched !== null ? "
                  -- Only include adsets that have clicks ONLY in the filtered campaign
                  -- NOTE: We check ANY time (not just date range) to include costs even if clicks haven't arrived yet today
                  AND EXISTS (
                      SELECT 1 FROM clicks c2
                      WHERE c2.adset_id = as_cost.adset_id
                          AND c2.adset_id IS NOT NULL
                          -- PERFORMANCE: Use generated column (adset_id) instead of JSON_EXTRACT for index usage
                          AND c2.campaign_id = ?
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM clicks c3
                      WHERE c3.adset_id = as_cost.adset_id
                          AND c3.adset_id IS NOT NULL
                          -- PERFORMANCE: Use generated column (adset_id) instead of JSON_EXTRACT for index usage
                          AND c3.campaign_id != ?
                  )
                  " : (($offerIdForUnmatched !== null || $landingPageIdForUnmatched !== null) ? "
                  -- When filtering by offer_id or landing_page_id, only include adsets that have clicks with that filter
                  AND EXISTS (
                      SELECT 1 FROM clicks c2
                      WHERE c2.adset_id = as_cost.adset_id
                          AND c2.adset_id IS NOT NULL
                          -- PERFORMANCE: Use generated column (adset_id) instead of JSON_EXTRACT for index usage
                          " . ($offerIdForUnmatched !== null ? "AND c2.offer_id = ?" : "") . "
                          " . ($landingPageIdForUnmatched !== null ? "AND c2.landing_page_id = ?" : "") . "
                  )
                  " : (($trafficSourceIdForUnmatched !== null) ? "
                  -- When filtering by traffic_source_id, only include adsets that have clicks with that traffic source
                  AND EXISTS (
                      SELECT 1 FROM clicks c2
                      INNER JOIN campaigns camp2 ON c2.campaign_id = camp2.id
                      WHERE c2.adset_id = as_cost.adset_id
                          AND c2.adset_id IS NOT NULL
                          -- PERFORMANCE: Use generated column (adset_id) instead of JSON_EXTRACT for index usage
                          " . ($this->checkTrafficSourceColumnExists() ? "AND c2.traffic_source_id = ?" : "AND camp2.traffic_source_id = ?") . "
                  )
                  " : "
                  -- When no campaign filter, only include adsets that have clicks in SOME campaign
                  -- This prevents costs without any clicks from being included in the overall total
                  AND EXISTS (
                      SELECT 1 FROM clicks c2
                      WHERE c2.adset_id = as_cost.adset_id
                          AND c2.adset_id IS NOT NULL
                          -- PERFORMANCE: Use generated column (adset_id) instead of JSON_EXTRACT for index usage
                  )
                  "))) . "
                GROUP BY as_cost.adset_id
            ) t
        ";
        
        // Set bind parameters based on query type
        if ($usePstDateGrouping) {
            // PST date grouping: timezone offset (for first CONVERT_TZ), userSelectedDateFrom, timezone offset (for second CONVERT_TZ), userSelectedDateTo
            $maxBindTypes = 'ssss';
            $maxBindValues = [$timezoneOffset, $userSelectedDateFrom, $timezoneOffset, $userSelectedDateTo];
        } else {
            // UTC range: date/hour tuple comparison (dateFrom, hourFrom, dateTo, hourTo)
            $maxBindTypes = 'sisi'; // date (string), hour (int), date (string), hour (int)
            $maxBindValues = [$dateFromDate, $dateFromHour, $dateToDate, $dateToHour];
        }
        if ($campaignIdForUnmatched !== null) {
            // EXISTS: campaign_id (i) = 'i' (no date range, check ANY time)
            // NOT EXISTS: campaign_id (i) = 'i' (no date range, check ANY time)
            // Total: 'ii' = 2 characters for 2 values
            // NOTE: Removed date range params (dateFrom, dateTo) from EXISTS/NOT EXISTS clauses since we now check ANY time
            $maxBindTypes .= 'ii'; 
            $maxBindValues = array_merge($maxBindValues, [
                $campaignIdForUnmatched, // EXISTS campaign_id (ANY time)
                $campaignIdForUnmatched  // NOT EXISTS campaign_id (ANY time)
            ]);
        } elseif ($offerIdForUnmatched !== null || $landingPageIdForUnmatched !== null) {
            // When filtering by offer_id or landing_page_id, add parameters for EXISTS clause
            if ($offerIdForUnmatched !== null) {
                $maxBindTypes .= 'i';
                $maxBindValues[] = $offerIdForUnmatched;
            }
            if ($landingPageIdForUnmatched !== null) {
                $maxBindTypes .= 'i';
                $maxBindValues[] = $landingPageIdForUnmatched;
            }
        } elseif ($trafficSourceIdForUnmatched !== null) {
            // When filtering by traffic_source_id, add parameter for EXISTS clause
            $maxBindTypes .= 'i';
            $maxBindValues[] = $trafficSourceIdForUnmatched;
        }
        
        // Validate bind parameters before binding
        $placeholderCount = substr_count($maxSpendQuery, '?');
        $bindTypesCount = strlen($maxBindTypes);
        $bindValuesCount = count($maxBindValues);
        if ($placeholderCount !== $bindTypesCount || $placeholderCount !== $bindValuesCount) {
            $errorMessage = "Max spend query bind mismatch: Types={$bindTypesCount}, Values={$bindValuesCount}, Placeholders={$placeholderCount}. Filter: " . ($campaignFilter ?? 'null');
            error_log("FacebookCostAggregator: " . $errorMessage);
            throw new \Exception($errorMessage);
        }
        
        $maxStmt = $this->db->prepare($maxSpendQuery);
        $maxStmt->bind_param($maxBindTypes, ...$maxBindValues);
        $maxStmt->execute();
        $maxResult = $maxStmt->get_result()->fetch_assoc();
        $fbCostByMax = (float)($maxResult['sum_spend'] ?? 0.0);
        $maxStmt->close();
        
        // Log midnight hour filter status
        if ($usePstDateGrouping) {
            error_log("FacebookCostAggregator: Max spend query - Including all hours (including hour 0) in user timezone ({$userTimezone}). DATE filtering prevents yesterday's costs. Result: {$fbCostByMax}");
        } else {
            error_log("FacebookCostAggregator: Max spend query - Using UTC date/hour filtering (no timezone conversion). Result: {$fbCostByMax}");
        }

        // Choose the higher between delta-based calculation and max-total safeguard
        // However, if there's no campaign filter, we should trust the calculated value
        // because maxSpendQuery might include costs from multiple campaigns incorrectly
        $calculatedCost = $fbCostFromClicks + $fbCostUnmatched;
        
        // Debug logging
        if ($campaignFilter) {
            $filterType = 'Unknown';
            if (strpos($campaignFilter, 'campaign_id') !== false) {
                $filterType = 'Campaign';
            } elseif (strpos($campaignFilter, 'offer_id') !== false) {
                $filterType = 'Offer';
            } elseif (strpos($campaignFilter, 'landing_page_id') !== false) {
                $filterType = 'Landing Page';
            }
            error_log("FacebookCostAggregator: {$filterType} cost calculation - FromClicks: {$fbCostFromClicks}, Unmatched: {$fbCostUnmatched}, Calculated: {$calculatedCost}, MaxSafeguard: {$fbCostByMax}, Final: " . ($manualCost + max($calculatedCost, $fbCostByMax)));
        } else {
            error_log("FacebookCostAggregator: Overall cost calculation - FromClicks: {$fbCostFromClicks}, Unmatched: {$fbCostUnmatched}, Calculated: {$calculatedCost}, MaxSafeguard: {$fbCostByMax}, Discrepancy: " . abs($fbCostByMax - $calculatedCost));
        }
        
        // CRITICAL FIX: When no campaign filter, calculate overall cost directly using single optimized query
        // This eliminates N+1 query problem and improves performance significantly
        // The existing query logic (manual cost + fb cost + unmatched cost) already works without filters
        if (empty($campaignFilter) && !$skipOverallSum) {
            // Calculate overall cost directly using the same query logic but without campaign filters
            // Manual cost query already works without filters (line 526-530)
            // fbCostQuery works without filters (just won't have campaignFilter conditions)
            // unmatchedCostsQuery works without filters (just won't have campaignFilter conditions)
            // This is much more efficient than looping through all campaigns (N+1 queries)
            
            // Manual cost is already calculated above (line 526-550) and works without filters
            // calculatedCost is already calculated above (line 1516) and includes both matched and unmatched FB costs
            // calculatedCost = fbCostFromClicks + fbCostUnmatched (both already calculated without filters)
            // fbCostByMax uses MAX(spend) cumulative which is the source of truth from Meta API
            
            // CRITICAL FIX: Prefer delta sum when it's $0.00 and cumulative is much higher
            // This prevents yesterday's cumulative spend from appearing in today's total
            // Cumulative includes previous days' spend, so when delta is $0.00 (no costs today),
            // we should trust delta over cumulative to avoid showing yesterday's costs
            $discrepancy = abs($fbCostByMax - $calculatedCost);
            
            // CRITICAL FIX: If calculated cost is $0.00 or very small and cumulative is much higher, 
            // cumulative likely includes yesterday's spend (especially right after midnight)
            // Also check if fbCostFromClicks is 0 (no matched costs today) and discrepancy is large
            // This indicates yesterday's cumulative spend is being included
            // In this case, trust the delta sum which correctly shows no/minimal costs for today
            // This prevents yesterday's cumulative spend from showing in today's total
            if (($calculatedCost == 0.0 || $calculatedCost < 0.10) && $fbCostByMax > 1.0) {
                // Delta sum shows $0.00 or very small (correct - no/minimal costs today), 
                // but cumulative is high (includes yesterday's final cumulative spend)
                // Use delta sum to avoid showing yesterday's costs in today's total
                $fbCost = $calculatedCost;
                error_log("FacebookCostAggregator: Overall cost (no filter) - MIDNIGHT SAFETY CHECK: Using delta sum: {$calculatedCost} instead of cumulative: {$fbCostByMax}. Delta correctly shows no/minimal costs for today, cumulative includes previous day's final cumulative spend. This prevents yesterday's costs from appearing in today's total right after midnight.");
            } elseif ($fbCostFromClicks == 0.0 && $discrepancy > 5.0 && $fbCostByMax > 1.0) {
                // No matched costs today (fbCostFromClicks = 0) but large discrepancy suggests yesterday's cumulative
                // Use delta sum to avoid showing yesterday's costs
                $fbCost = $calculatedCost;
                error_log("FacebookCostAggregator: Overall cost (no filter) - MIDNIGHT SAFETY CHECK (no matched costs): Using delta sum: {$calculatedCost} instead of cumulative: {$fbCostByMax}. No matched costs today (fbCostFromClicks=0), large discrepancy ({$discrepancy}) suggests yesterday's cumulative spend is included. This prevents yesterday's costs from appearing in today's total right after midnight.");
            } elseif ($fbCostByMax > 0 && $calculatedCost > 0) {
                // Both have values - prefer cumulative MAX when available (source of truth from Meta API)
                // Cumulative MAX represents the actual spend from Meta API and is more reliable than summing deltas
                // Delta sum can be inflated if it includes costs from wrong dates or multiple days
                if ($fbCostByMax > 0.01) {
                    // Cumulative MAX is available and reasonable - prefer it as source of truth
                    $fbCost = $fbCostByMax;
                    if ($calculatedCost > $fbCostByMax) {
                        error_log("FacebookCostAggregator: Overall cost (no filter) - Using cumulative (MAX): {$fbCostByMax} instead of delta sum: {$calculatedCost} (discrepancy: {$discrepancy}). Cumulative MAX is source of truth from Meta API. Delta sum is higher but may include costs from wrong dates or be inflated.");
                    } elseif ($discrepancy > 0.01) {
                        error_log("FacebookCostAggregator: Overall cost (no filter) - Using cumulative (MAX): {$fbCostByMax} instead of delta sum: {$calculatedCost} (discrepancy: {$discrepancy}). Cumulative matches Meta API and is source of truth.");
                    } else {
                        error_log("FacebookCostAggregator: Overall cost (no filter) - Using cumulative (MAX): {$fbCostByMax}, Delta sum: {$calculatedCost} (match closely). Cumulative MAX is source of truth from Meta API.");
                    }
                } else {
                    // Cumulative MAX is too small (< $0.01) - use delta sum as fallback
                    $fbCost = $calculatedCost;
                    error_log("FacebookCostAggregator: Overall cost (no filter) - Using delta sum: {$calculatedCost} instead of cumulative: {$fbCostByMax} (cumulative MAX is too small, using delta sum as fallback).");
                }
            } elseif ($fbCostByMax > 0) {
                // Only cumulative available - use it
                $fbCost = $fbCostByMax;
                error_log("FacebookCostAggregator: Overall cost (no filter) - Using cumulative (MAX): {$fbCostByMax} (delta sum not available).");
            } else {
                // Cumulative not available - use calculated (delta sum) as fallback
                $fbCost = $calculatedCost;
                error_log("FacebookCostAggregator: Overall cost (no filter, direct calculation) - Manual: {$manualCost}, FB Cost (calculated): {$calculatedCost}, Total: " . ($manualCost + $calculatedCost) . " (cumulative not available)");
            }
            
            $overallCost = $manualCost + $fbCost;
            
            // Return the cost using cumulative when available
            return $overallCost;
        } else {
            // For filtered queries (campaign, traffic source, offer, landing page), prefer delta sum when it's higher
            // When delta sum is higher than cumulative MAX, it indicates cumulative is missing data (likely hour 0)
            // Cumulative spend matches Meta API and is the source of truth when it's higher
            // For traffic source queries, always prefer cumulative when available (even small discrepancies matter)
            // For other filters, use threshold-based logic
            $discrepancy = abs($fbCostByMax - $calculatedCost);
            $isTrafficSourceQuery = ($trafficSourceIdForUnmatched !== null);
            
            if ($fbCostByMax > 0 && $calculatedCost > 0) {
                // Both have values - prefer cumulative MAX when available (source of truth from Meta API)
                // Cumulative MAX represents the actual spend from Meta API and is more reliable than summing deltas
                // Delta sum can be inflated if it includes costs from wrong dates or multiple days
                if ($fbCostByMax > 0.01) {
                    // Cumulative MAX is available and reasonable - prefer it as source of truth
                    $fbCost = $fbCostByMax;
                    if (!$skipOverallSum) {
                        if ($calculatedCost > $fbCostByMax) {
                            if ($isTrafficSourceQuery) {
                                error_log("FacebookCostAggregator: Traffic source query - Using cumulative (MAX): {$fbCostByMax} instead of delta sum: {$calculatedCost} (discrepancy: {$discrepancy}). Cumulative MAX is source of truth from Meta API. Delta sum is higher but may include costs from wrong dates.");
                            } else {
                                error_log("FacebookCostAggregator: Filtered query - Using cumulative (MAX): {$fbCostByMax} instead of delta sum: {$calculatedCost} (discrepancy: {$discrepancy}). Cumulative MAX is source of truth from Meta API. Delta sum is higher but may include costs from wrong dates or be inflated.");
                            }
                        } elseif ($discrepancy > 1.00) {
                            if ($isTrafficSourceQuery) {
                                error_log("FacebookCostAggregator: Traffic source query - Using cumulative (MAX): {$fbCostByMax} instead of delta sum: {$calculatedCost} (discrepancy: {$discrepancy}). Cumulative matches Meta API and is source of truth.");
                            } else {
                                error_log("FacebookCostAggregator: WARNING - Large discrepancy detected ({$discrepancy}) - Using cumulative (MAX): {$fbCostByMax} instead of delta sum: {$calculatedCost}. Cumulative matches Meta API and is source of truth.");
                            }
                        } elseif ($discrepancy > 0.10) {
                            error_log("FacebookCostAggregator: Filtered query - Using cumulative (MAX): {$fbCostByMax}, Delta sum: {$calculatedCost} (moderate discrepancy: {$discrepancy}). Cumulative MAX is source of truth from Meta API.");
                        } else {
                            error_log("FacebookCostAggregator: Filtered query - Using cumulative (MAX): {$fbCostByMax}, Delta sum: {$calculatedCost} (match closely). Cumulative MAX is source of truth from Meta API.");
                        }
                    }
                } else {
                    // Cumulative MAX is too small (< $0.01) - use delta sum as fallback
                    $fbCost = $calculatedCost;
                    if (!$skipOverallSum) {
                        error_log("FacebookCostAggregator: Filtered query - Using delta sum: {$calculatedCost} instead of cumulative: {$fbCostByMax} (cumulative MAX is too small, using delta sum as fallback).");
                    }
                }
            } elseif ($fbCostByMax > 0) {
                // Only cumulative available - use it
                $fbCost = $fbCostByMax;
                if (!$skipOverallSum) {
                    error_log("FacebookCostAggregator: Filtered query - Using cumulative (MAX): {$fbCostByMax} (delta sum not available).");
                }
            } else {
                // Cumulative not available - use calculated (delta sum) as fallback
                $fbCost = $calculatedCost;
                if (!$skipOverallSum) {
                    error_log("FacebookCostAggregator: Filtered query - Using delta sum: {$calculatedCost} (cumulative not available).");
                }
            }
        }

        $fbCost += $this->getMetaMappedCampaignSpendWithoutClicks(
            $dateFrom,
            $dateTo,
            $dateFromDate,
            $dateFromHour,
            $dateToDate,
            $dateToHour,
            $campaignFilter,
            $campaignFilterParams,
            $userTimezone,
            $userSelectedDateFrom,
            $userSelectedDateTo
        );

        return $manualCost + $fbCost;
    }

    /**
     * Include adset_hourly_costs for a Kuma campaign's mapped Meta campaign when there are no clicks yet.
     */
    private function getMetaMappedCampaignSpendWithoutClicks(
        string $dateFrom,
        string $dateTo,
        string $dateFromDate,
        int $dateFromHour,
        string $dateToDate,
        int $dateToHour,
        ?string $campaignFilter,
        array $campaignFilterParams,
        ?string $userTimezone,
        ?string $userSelectedDateFrom,
        ?string $userSelectedDateTo
    ): float {
        if ($campaignFilter === null || empty($campaignFilterParams)) {
            return 0.0;
        }
        if (!preg_match('/\bcampaign_id\s*=\s*\?/i', $campaignFilter) || preg_match('/\bIN\s*\(/i', $campaignFilter)) {
            return 0.0;
        }
        $kumaCampaignId = null;
        foreach ($campaignFilterParams as $param) {
            if (is_int($param) || (is_string($param) && ctype_digit($param))) {
                $kumaCampaignId = (int)$param;
                break;
            }
        }
        if ($kumaCampaignId === null || $kumaCampaignId <= 0) {
            return 0.0;
        }

        $tableCheck = $this->db->query("SHOW TABLES LIKE 'facebook_adset_campaign_map'");
        if (!$tableCheck || $tableCheck->num_rows === 0) {
            return 0.0;
        }

        $campStmt = $this->db->prepare("
            SELECT camp.facebook_marketing_ad_account_id, fmc.meta_campaign_id, fmaa.facebook_marketing_integration_id
            FROM campaigns camp
            INNER JOIN facebook_marketing_campaigns fmc ON camp.facebook_marketing_campaign_id = fmc.id
            LEFT JOIN facebook_marketing_ad_accounts fmaa ON camp.facebook_marketing_ad_account_id = fmaa.id
            WHERE camp.id = ?
        ");
        $campStmt->bind_param('i', $kumaCampaignId);
        $campStmt->execute();
        $campRow = $campStmt->get_result()->fetch_assoc();
        $campStmt->close();
        if (!$campRow || empty($campRow['meta_campaign_id'])) {
            return 0.0;
        }

        $metaCampaignId = (string)$campRow['meta_campaign_id'];
        $integrationId = !empty($campRow['facebook_marketing_integration_id'])
            ? (int)$campRow['facebook_marketing_integration_id'] : null;

        $adAccountInternalId = (int)($campRow['facebook_marketing_ad_account_id'] ?? 0);
        if ($adAccountInternalId <= 0) {
            return 0.0;
        }

        $costSql = "
            SELECT COALESCE(SUM(ahc.delta_spend), 0) AS total_cost
            FROM adset_hourly_costs ahc
            INNER JOIN facebook_adset_campaign_map facm
                ON facm.adset_id = ahc.adset_id
                AND facm.meta_campaign_id = ?
                AND facm.facebook_marketing_ad_account_id = ?
            WHERE (ahc.date > ? OR (ahc.date = ? AND ahc.hour >= ?))
              AND (ahc.date < ? OR (ahc.date = ? AND ahc.hour <= ?))
              AND ahc.delta_spend > 0
              AND NOT EXISTS (
                SELECT 1 FROM clicks cl
                WHERE cl.campaign_id = ?
                  AND cl.adset_id = ahc.adset_id
                  AND cl.ts >= CONCAT(ahc.date, ' ', LPAD(ahc.hour, 2, '0'), ':00:00')
                  AND cl.ts < DATE_ADD(CONCAT(ahc.date, ' ', LPAD(ahc.hour, 2, '0'), ':00:00'), INTERVAL 1 HOUR)
              )
        ";
        if ($integrationId !== null) {
            $costSql .= " AND ahc.ad_account_id = ?";
        }

        $costStmt = $this->db->prepare($costSql);
        if ($integrationId !== null) {
            $costStmt->bind_param(
                'sisississi',
                $metaCampaignId,
                $adAccountInternalId,
                $dateFromDate,
                $dateFromDate,
                $dateFromHour,
                $dateToDate,
                $dateToDate,
                $dateToHour,
                $kumaCampaignId,
                $integrationId
            );
        } else {
            $costStmt->bind_param(
                'sisississ',
                $metaCampaignId,
                $adAccountInternalId,
                $dateFromDate,
                $dateFromDate,
                $dateFromHour,
                $dateToDate,
                $dateToDate,
                $dateToHour,
                $kumaCampaignId
            );
        }
        $costStmt->execute();
        $costRow = $costStmt->get_result()->fetch_assoc();
        $costStmt->close();

        return (float)($costRow['total_cost'] ?? 0.0);
    }

    /**
     * Get total cost for a traffic source within a date range
     * Uses click's traffic_source_id to include auto-detect campaigns (if column exists)
     * Otherwise falls back to campaign's traffic_source_id
     */
    public function getTrafficSourceCost(int $trafficSourceId, string $dateFrom, string $dateTo, ?string $userTimezone = null): float
    {
        if ($this->checkTrafficSourceColumnExists()) {
            // Use click's traffic_source_id if column exists
            // Prefix with cl. to avoid ambiguity in queries using 'cl' alias
            $campaignFilter = " AND cl.traffic_source_id = ?";
            return $this->getAggregatedCost($dateFrom, $dateTo, $campaignFilter, [$trafficSourceId], $userTimezone);
        } else {
            // Fallback: use campaign's traffic_source_id via join
            // Use EXISTS subquery instead of IN to avoid ambiguity issues
            // This is clearer to MySQL and avoids the "ambiguous column" error
            $campaignFilter = " AND EXISTS (SELECT 1 FROM campaigns WHERE campaigns.id = cl.campaign_id AND campaigns.traffic_source_id = ?)";
            return $this->getAggregatedCost($dateFrom, $dateTo, $campaignFilter, [$trafficSourceId], $userTimezone);
        }
    }

    /**
     * Get total cost for a campaign within a date range
     * @param string|null $userTimezone User's timezone for cost matching (e.g. 'America/Los_Angeles'). If null, uses UTC.
     */
    public function getCampaignCost(int $campaignId, string $dateFrom, string $dateTo, ?string $userTimezone = null): float
    {
        $campaignFilter = " AND campaign_id = ?";
        return $this->getAggregatedCost($dateFrom, $dateTo, $campaignFilter, [$campaignId], $userTimezone);
    }

    /**
     * Strip ad_name_value and adset_name_value from filter for adset_counts subquery.
     * adset_counts must count ALL clicks per (adset_id, date, hour) - not filtered by ad.
     * Otherwise each ad incorrectly receives the full adset cost instead of its proportional share.
     *
     * @return array{0: string|null, 1: array} [strippedFilter, strippedParams] - null filter if nothing left
     */
    private function getFilterForAdsetCountSubquery(?string $campaignFilter, array $campaignFilterParams): array
    {
        if (empty($campaignFilter) || empty($campaignFilterParams)) {
            return [$campaignFilter, $campaignFilterParams];
        }
        $filter = $campaignFilter;
        $params = $campaignFilterParams;
        $paramIndicesToRemove = [];
        $paramIdx = 0;
        $offset = 0;
        while (($pos = strpos($filter, '?', $offset)) !== false) {
            $contextStart = max(0, $pos - 80);
            $context = substr($filter, $contextStart, $pos - $contextStart + 1);
            if (preg_match('/\b(ad_name_value|adset_name_value)\s*=\s*\?/i', $context)) {
                $paramIndicesToRemove[] = $paramIdx;
            }
            $paramIdx++;
            $offset = $pos + 1;
        }
        $strippedFilter = preg_replace('/\s*AND\s+cl\.(ad_name_value|adset_name_value)\s*=\s*\?/i', '', $filter);
        $strippedFilter = preg_replace('/\s*AND\s+\(\s*cl\.(ad_name_value|adset_name_value)\s+IS\s+NULL[^)]*\)/i', '', $strippedFilter);
        $strippedFilter = trim(preg_replace('/^\s*AND\s+/', '', $strippedFilter));
        $strippedFilter = ($strippedFilter !== '') ? ' AND ' . $strippedFilter : null;
        $newParams = [];
        foreach ($params as $i => $p) {
            if (!in_array($i, $paramIndicesToRemove, true)) {
                $newParams[] = $p;
            }
        }
        return [$strippedFilter, $newParams];
    }

    /**
     * Convert filter from one alias to another for subqueries
     * Handles traffic_source_id specially based on whether column exists in clicks table
     */
    private function convertFilterForSubquery(string $filter, string $newAlias): string
    {
        // Convert normalized filter (cl. prefix) to new alias prefix for this subquery
        // If filter has JSON_UNQUOTE, handle it specially
        // CRITICAL: Only replace cl. with new alias for columns that exist in clicks table
        // If traffic_source_id doesn't exist in clicks, it should be in a subquery format already
        // CRITICAL: Don't modify filters with EXISTS or IN (SELECT...) - handle appropriately
        if (preg_match('/\bEXISTS\s*\(\s*SELECT/i', $filter)) {
            // Filter is in EXISTS subquery format like "EXISTS (SELECT 1 FROM campaigns WHERE campaigns.id = cl.campaign_id...)"
            // Replace cl. with new alias for the outer reference
            return str_replace('cl.campaign_id', $newAlias . '.campaign_id', $filter);
        } elseif (preg_match('/\bcampaign_id\s+IN\s*\(\s*SELECT/i', $filter)) {
            // Filter is in IN subquery format like "campaign_id IN (SELECT...)" or "cl.campaign_id IN (SELECT...)"
            // Remove cl. prefix if present - campaign_id will be resolved from subquery context (cl2)
            return preg_replace('/\bcl\.campaign_id\s+IN\s*\(\s*SELECT/i', 'campaign_id IN (SELECT', $filter);
        }
        
        if (strpos($filter, 'JSON_UNQUOTE') !== false) {
            return str_replace(['cl.extra_json', $newAlias . '.extra_json'], $newAlias . '.extra_json', str_replace(['cl.', $newAlias . '.'], $newAlias . '.', $filter));
        } else {
            // Only replace cl. with new alias if the column exists in clicks
            // Check if traffic_source_id is in the filter and if column exists
            if (strpos($filter, 'traffic_source_id') !== false && $this->checkTrafficSourceColumnExists()) {
                return str_replace('cl.', $newAlias . '.', $filter);
            } elseif (strpos($filter, 'traffic_source_id') === false) {
                // No traffic_source_id, safe to replace
                return str_replace('cl.', $newAlias . '.', $filter);
            }
            // If traffic_source_id exists in filter but column doesn't exist in clicks, 
            // filter should already be in subquery format (campaign_id IN (SELECT...))
            // so we don't need to replace anything
            return $filter;
        }
    }
}


