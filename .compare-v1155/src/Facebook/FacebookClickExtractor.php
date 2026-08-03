<?php

declare(strict_types=1);

namespace SimpleKuma\Facebook;

use mysqli;

/**
 * Facebook Click Extractor
 * Extracts unique adset and ad IDs from recent Facebook clicks
 */
class FacebookClickExtractor
{
    private mysqli $db;
    private FacebookTrafficSourceIdentifier $identifier;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->identifier = new FacebookTrafficSourceIdentifier($db);
    }

    /**
     * Validate if a Meta ID is valid (numeric, not a template token)
     * 
     * @param string $id The ID to validate
     * @return bool True if valid numeric ID, false otherwise
     */
    private function isValidMetaId(string $id): bool
    {
        // Return false if empty, null, or 'null'
        if (empty($id) || $id === 'null' || $id === null) {
            return false;
        }
        
        // Return false if contains template tokens like {{ or {ts:
        if (strpos($id, '{{') !== false || strpos($id, '{ts:') !== false) {
            return false;
        }
        
        // Return true only if all characters are numeric
        return ctype_digit($id);
    }

    /**
     * Get unique adset IDs from recent Facebook clicks (last 7 days, or custom date range)
     * Grouped by ad account ID
     * 
     * @param array $facebookSourceIds Array of Facebook traffic source IDs
     * @param int|null $facebookMarketingIntegrationId Optional: Filter by campaign's facebook_marketing_integration_id
     * @param string|null $adAccountId Optional: Filter by ad_account_id from clicks (legacy, for backward compatibility)
     * @param string|null $utcDateFrom Optional: UTC start timestamp for date range (if provided, replaces 7-day window)
     * @param string|null $utcDateTo Optional: UTC end timestamp for date range (if provided, replaces 7-day window)
     */
    public function extractUniqueAdsets(array $facebookSourceIds, ?int $facebookMarketingIntegrationId = null, ?string $adAccountId = null, ?string $utcDateFrom = null, ?string $utcDateTo = null): array
    {
        if (empty($facebookSourceIds)) {
            return [];
        }

        // Build WHERE clause for Facebook traffic sources
        $placeholders = implode(',', array_fill(0, count($facebookSourceIds), '?'));
        
        // Extract adset_id from extra_json->traffic_source_tokens
        // JOIN with campaigns to filter by facebook_marketing_ad_account_id
        // Use custom date range if provided, otherwise use 7-day window for backward compatibility
        $dateRangeClause = '';
        if ($utcDateFrom !== null && $utcDateTo !== null) {
            $dateRangeClause = "AND c.ts >= ? AND c.ts <= ?";
        } else {
            $dateRangeClause = "AND c.ts >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        }
        
        $query = "
            SELECT DISTINCT
                JSON_UNQUOTE(JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.adset_id')) as adset_id,
                JSON_UNQUOTE(JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.ad_account_id')) as ad_account_id
            FROM clicks c
            INNER JOIN campaigns camp ON c.campaign_id = camp.id
            WHERE camp.traffic_source_id IN ($placeholders)
                AND camp.status = 'active'
                {$dateRangeClause}
                AND JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.adset_id') IS NOT NULL
                AND JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.adset_id') != 'null'
                AND JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.adset_id') != ''
        ";

        // Filter by campaign's facebook_marketing_ad_account_id (preferred method)
        if ($facebookMarketingIntegrationId !== null) {
            // Note: $facebookMarketingIntegrationId now represents facebook_marketing_ad_account_id (internal ID)
            $query .= " AND camp.facebook_marketing_ad_account_id = ?";
        }
        // If null, don't filter by ad account ID (include all campaigns for backward compatibility)

        // Legacy: If adAccountId is provided, also filter by it (for backward compatibility)
        if ($adAccountId !== null) {
            $query .= " AND JSON_UNQUOTE(JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.ad_account_id')) = ?";
        }

        $query .= " ORDER BY adset_id ASC";

        $stmt = $this->db->prepare($query);
        
        // Bind parameters: Facebook source IDs first
        $bindParams = $facebookSourceIds;
        $bindTypes = str_repeat('i', count($bindParams));
        
        // Add date range parameters if provided (must come after source IDs, before other filters)
        if ($utcDateFrom !== null && $utcDateTo !== null) {
            $bindParams[] = $utcDateFrom;
            $bindParams[] = $utcDateTo;
            $bindTypes .= 'ss';
        }
        
        // Add facebook_marketing_integration_id if provided
        if ($facebookMarketingIntegrationId !== null) {
            $bindParams[] = $facebookMarketingIntegrationId;
            $bindTypes .= 'i';
        }
        
        // Add adAccountId if provided (legacy)
        if ($adAccountId !== null) {
            $bindParams[] = (string)$adAccountId;
            $bindTypes .= 's';
        }
        
        $stmt->bind_param($bindTypes, ...$bindParams);
        $stmt->execute();
        $result = $stmt->get_result();

        $adsets = [];
        $validCount = 0;
        $discardedInvalidCount = 0;
        $discardedTemplateCount = 0;
        $discardedSamples = [];
        
        while ($row = $result->fetch_assoc()) {
            $adsetId = trim((string)($row['adset_id'] ?? ''));
            if (!empty($adsetId) && $adsetId !== 'null') {
                // Validate the adset ID
                if ($this->isValidMetaId($adsetId)) {
                $extractedAdAccountId = trim($row['ad_account_id'] ?? '');
                $adsets[] = [
                    'adset_id' => $adsetId,
                    'ad_account_id' => !empty($extractedAdAccountId) && $extractedAdAccountId !== 'null' ? $extractedAdAccountId : null
                ];
                    $validCount++;
                } else {
                    // Track discarded IDs for logging
                    $discardedInvalidCount++;
                    if (strpos($adsetId, '{{') !== false || strpos($adsetId, '{ts:') !== false) {
                        $discardedTemplateCount++;
                    }
                    // Keep first 3 samples for debugging
                    if (count($discardedSamples) < 3) {
                        $discardedSamples[] = $adsetId;
                    }
                }
            }
        }
        
        // Log validation statistics
        if ($discardedInvalidCount > 0 || $validCount > 0) {
            error_log(sprintf(
                "FacebookClickExtractor::extractUniqueAdsets: valid_numeric_ids=%d, discarded_invalid_ids=%d (template_tokens=%d), sample_discarded=%s",
                $validCount,
                $discardedInvalidCount,
                $discardedTemplateCount,
                json_encode($discardedSamples)
            ));
        }

        // Group by ad account if needed (only if no integration ID specified)
        if ($facebookMarketingIntegrationId === null && $adAccountId === null) {
            return $this->groupByAdAccount($adsets);
        }

        return $adsets;
    }

    /**
     * Get unique ad IDs from recent Facebook clicks (last 7 days, or custom date range)
     * Includes parent adset_id for grouping
     * 
     * @param array $facebookSourceIds Array of Facebook traffic source IDs
     * @param int|null $facebookMarketingIntegrationId Optional: Filter by campaign's facebook_marketing_integration_id
     * @param string|null $adAccountId Optional: Filter by ad_account_id from clicks (legacy, for backward compatibility)
     * @param string|null $utcDateFrom Optional: UTC start timestamp for date range (if provided, replaces 7-day window)
     * @param string|null $utcDateTo Optional: UTC end timestamp for date range (if provided, replaces 7-day window)
     */
    public function extractUniqueAds(array $facebookSourceIds, ?int $facebookMarketingIntegrationId = null, ?string $adAccountId = null, ?string $utcDateFrom = null, ?string $utcDateTo = null): array
    {
        if (empty($facebookSourceIds)) {
            return [];
        }

        // Build WHERE clause for Facebook traffic sources
        $placeholders = implode(',', array_fill(0, count($facebookSourceIds), '?'));
        
        // Extract ad_id and adset_id from extra_json->traffic_source_tokens
        // JOIN with campaigns to filter by facebook_marketing_ad_account_id
        // Use custom date range if provided, otherwise use 7-day window for backward compatibility
        $dateRangeClause = '';
        if ($utcDateFrom !== null && $utcDateTo !== null) {
            $dateRangeClause = "AND c.ts >= ? AND c.ts <= ?";
        } else {
            $dateRangeClause = "AND c.ts >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        }
        
        $query = "
            SELECT DISTINCT
                JSON_UNQUOTE(JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.ad_id')) as ad_id,
                JSON_UNQUOTE(JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.adset_id')) as adset_id,
                JSON_UNQUOTE(JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.ad_account_id')) as ad_account_id
            FROM clicks c
            INNER JOIN campaigns camp ON c.campaign_id = camp.id
            WHERE camp.traffic_source_id IN ($placeholders)
                AND camp.status = 'active'
                {$dateRangeClause}
                AND JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.ad_id') IS NOT NULL
                AND JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.ad_id') != 'null'
                AND JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.ad_id') != ''
        ";

        // Filter by campaign's facebook_marketing_ad_account_id (preferred method)
        if ($facebookMarketingIntegrationId !== null) {
            // Note: $facebookMarketingIntegrationId now represents facebook_marketing_ad_account_id (internal ID)
            $query .= " AND camp.facebook_marketing_ad_account_id = ?";
        }
        // If null, don't filter by ad account ID (include all campaigns for backward compatibility)

        // Legacy: If adAccountId is provided, also filter by it (for backward compatibility)
        if ($adAccountId !== null) {
            $query .= " AND JSON_UNQUOTE(JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.ad_account_id')) = ?";
        }

        $query .= " ORDER BY ad_id ASC";

        $stmt = $this->db->prepare($query);
        
        // Bind parameters: Facebook source IDs first
        $bindParams = $facebookSourceIds;
        $bindTypes = str_repeat('i', count($bindParams));
        
        // Add date range parameters if provided (must come after source IDs, before other filters)
        if ($utcDateFrom !== null && $utcDateTo !== null) {
            $bindParams[] = $utcDateFrom;
            $bindParams[] = $utcDateTo;
            $bindTypes .= 'ss';
        }
        
        // Add facebook_marketing_integration_id if provided
        if ($facebookMarketingIntegrationId !== null) {
            $bindParams[] = $facebookMarketingIntegrationId;
            $bindTypes .= 'i';
        }
        
        // Add adAccountId if provided (legacy)
        if ($adAccountId !== null) {
            $bindParams[] = (string)$adAccountId;
            $bindTypes .= 's';
        }
        
        $stmt->bind_param($bindTypes, ...$bindParams);
        $stmt->execute();
        $result = $stmt->get_result();

        $ads = [];
        $validAdCount = 0;
        $validAdsetCount = 0;
        $discardedInvalidAdCount = 0;
        $discardedTemplateAdCount = 0;
        $discardedAdSamples = [];
        
        while ($row = $result->fetch_assoc()) {
            $adId = trim((string)($row['ad_id'] ?? ''));
            $adsetId = trim((string)($row['adset_id'] ?? ''));
            if (!empty($adId) && $adId !== 'null') {
                // Validate the ad ID
                if ($this->isValidMetaId($adId)) {
                    $validAdCount++;
                    
                    // Also validate adset_id if present (for recovery path)
                    $validatedAdsetId = null;
                    if (!empty($adsetId) && $adsetId !== 'null' && $this->isValidMetaId($adsetId)) {
                        $validatedAdsetId = $adsetId;
                        $validAdsetCount++;
                    }
                    
                $extractedAdAccountId = trim($row['ad_account_id'] ?? '');
                $ads[] = [
                    'ad_id' => $adId,
                        'adset_id' => $validatedAdsetId,
                    'ad_account_id' => !empty($extractedAdAccountId) && $extractedAdAccountId !== 'null' ? $extractedAdAccountId : null
                ];
                } else {
                    // Track discarded ad IDs for logging
                    $discardedInvalidAdCount++;
                    if (strpos($adId, '{{') !== false || strpos($adId, '{ts:') !== false) {
                        $discardedTemplateAdCount++;
                    }
                    // Keep first 3 samples for debugging
                    if (count($discardedAdSamples) < 3) {
                        $discardedAdSamples[] = $adId;
                    }
                }
            }
        }
        
        // Log validation statistics
        if ($discardedInvalidAdCount > 0 || $validAdCount > 0) {
            error_log(sprintf(
                "FacebookClickExtractor::extractUniqueAds: valid_numeric_ad_ids=%d, valid_numeric_adset_ids=%d, discarded_invalid_ad_ids=%d (template_tokens=%d), sample_discarded_ads=%s",
                $validAdCount,
                $validAdsetCount,
                $discardedInvalidAdCount,
                $discardedTemplateAdCount,
                json_encode($discardedAdSamples)
            ));
        }

        // Return flat array
        return $ads;
    }

    /**
     * Group extracted items by ad account
     */
    private function groupByAdAccount(array $items): array
    {
        $grouped = [];
        foreach ($items as $item) {
            $adAccountKey = $item['ad_account_id'] ?? 'unknown';
            if (!isset($grouped[$adAccountKey])) {
                $grouped[$adAccountKey] = [];
            }
            $grouped[$adAccountKey][] = $item;
        }
        return $grouped;
    }

    /**
     * Extract unique adset IDs for a specific Facebook Marketing integration
     * Convenience method that combines identification and extraction
     * 
     * @param int|null $facebookMarketingIntegrationId Facebook Marketing integration ID
     */
    public function extractAdsetsForIntegration(?int $facebookMarketingIntegrationId = null): array
    {
        $facebookSourceIds = $this->identifier->getFacebookSourceIds();
        return $this->extractUniqueAdsets($facebookSourceIds, $facebookMarketingIntegrationId);
    }

    /**
     * Extract unique ad IDs for a specific Facebook Marketing integration
     * Convenience method that combines identification and extraction
     * 
     * @param int|null $facebookMarketingIntegrationId Facebook Marketing integration ID
     */
    public function extractAdsForIntegration(?int $facebookMarketingIntegrationId = null): array
    {
        $facebookSourceIds = $this->identifier->getFacebookSourceIds();
        return $this->extractUniqueAds($facebookSourceIds, $facebookMarketingIntegrationId);
    }
}

