<?php

declare(strict_types=1);

namespace SimpleKuma\Tracking;

use mysqli;
use SimpleKuma\Settings\SettingsManager;
use SimpleKuma\Stats\CampaignStatsExpressions;
use SimpleKuma\Stats\StatsExclusionFlag;

/**
 * Redirectless Tracker
 * Handles tracking when traffic goes directly to landing pages (no redirect)
 */
class RedirectlessTracker
{
    private mysqli $db;
    private ?SettingsManager $settings = null;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->settings = new SettingsManager($db);
    }

    /**
     * Track redirectless click
     * @param int $campaignId Campaign ID
     * @param int $landingPageId Landing page ID
     * @param array $params Tracking parameters (GET/POST)
     * @param string|null $failureReason Optional; when track returns null, set to the reason (campaign_not_found, campaign_not_active, lp_not_in_rotation)
     * @return string|null Returns the click_id if successful, null otherwise
     */
    public function track(int $campaignId, int $landingPageId, array $params, ?string &$failureReason = null): ?string
    {
        // Load campaign
        $campaign = $this->getCampaign($campaignId);

        if (!$campaign) {
            $failureReason = 'campaign_not_found';
            return null;
        }

        if ($campaign['status'] !== 'active') {
            $failureReason = 'campaign_not_active';
            return null;
        }

        // Reuse redirect-stamped click_id before LP-rotation checks (visit already recorded)
        $existingClickId = $this->resolveExistingClickId($campaignId, $landingPageId, $params);
        if ($existingClickId !== null) {
            return $existingClickId;
        }

        // Verify landing page is in campaign rotation
        if (!$this->verifyLandingPage($campaign, $landingPageId)) {
            $failureReason = 'lp_not_in_rotation';
            return null;
        }

        // Remove tracking parameters from params (c=, l=)
        $trackingParams = $params;
        unset($trackingParams['c'], $trackingParams['l'], $trackingParams['click_id'], $trackingParams['format']);

        // Get slug_id if slug parameter is provided
        $slugId = null;
        if (isset($trackingParams['slug']) && !empty($trackingParams['slug'])) {
            $slug = trim($trackingParams['slug']);
            $slugId = $this->getSlugId($slug, $campaignId);
            // Remove slug from tracking params (it's not a tracking parameter)
            unset($trackingParams['slug']);
        }

        // Detect traffic source from Tf parameter or use campaign default
        $detectedTrafficSourceId = $this->detectTrafficSource($campaign, $trackingParams);
        
        // Debug logging
        error_log("RedirectlessTracker: campaign_id=$campaignId, slug_id=" . ($slugId ?? 'NULL') . ", detected_traffic_source_id=" . ($detectedTrafficSourceId ?? 'NULL') . ", Tf_param=" . ($trackingParams['Tf'] ?? 'not set') . ", campaign_default_ts_id=" . ($campaign['traffic_source_id'] ?? 'NULL'));

        // Generate unique click ID
        $clickId = $this->generateClickId();

        // Capture cost
        $cost = $this->captureCost($campaign, $trackingParams, $detectedTrafficSourceId);

        // Store click in database
        $this->storeClick($campaignId, $clickId, $trackingParams, $cost, $campaign, $landingPageId, $detectedTrafficSourceId, $slugId);
        
        return $clickId;
    }

    /**
     * Get detailed failure reason for debugging (does not perform tracking)
     * @return array Diagnostic info: campaign_found, campaign_status, flow_type, lp_in_rotation, lp_ids_in_rotation, error
     */
    public function getTrackDiagnostics(int $campaignId, int $landingPageId): array
    {
        $campaign = $this->getCampaign($campaignId);
        if (!$campaign) {
            return [
                'campaign_found' => false,
                'campaign_status' => null,
                'flow_type' => null,
                'lp_in_rotation' => false,
                'lp_ids_in_rotation' => [],
                'error' => 'Campaign not found'
            ];
        }
        $rotation = json_decode($campaign['rotation_json'] ?? '[]', true) ?: [];
        $lpIds = [];
        if ($campaign['flow_type'] === 'LP' && isset($rotation['landing_pages'])) {
            foreach ($rotation['landing_pages'] as $lp) {
                if (isset($lp['id'])) {
                    $lpIds[] = (int)$lp['id'];
                }
            }
        } elseif ($campaign['flow_type'] === 'Split' && isset($rotation['lp_path']['landing_pages'])) {
            foreach ($rotation['lp_path']['landing_pages'] as $lp) {
                if (isset($lp['id'])) {
                    $lpIds[] = (int)$lp['id'];
                }
            }
        }
        $lpInRotation = in_array($landingPageId, $lpIds, true);
        $status = $campaign['status'] ?? null;
        $flowType = $campaign['flow_type'] ?? null;

        if ($status !== 'active') {
            return [
                'campaign_found' => true,
                'campaign_status' => $status,
                'flow_type' => $flowType,
                'lp_in_rotation' => $lpInRotation,
                'lp_ids_in_rotation' => $lpIds,
                'error' => 'Campaign not active (status: ' . ($status ?? 'null') . ')'
            ];
        }
        if (!$lpInRotation) {
            return [
                'campaign_found' => true,
                'campaign_status' => $status,
                'flow_type' => $flowType,
                'lp_in_rotation' => false,
                'lp_ids_in_rotation' => $lpIds,
                'error' => 'Landing page ' . $landingPageId . ' not in rotation. LPs in rotation: ' . implode(', ', $lpIds)
            ];
        }
        return [
            'campaign_found' => true,
            'campaign_status' => $status,
            'flow_type' => $flowType,
            'lp_in_rotation' => true,
            'lp_ids_in_rotation' => $lpIds,
            'error' => null
        ];
    }

    /**
     * Get campaign by ID
     */
    private function getCampaign(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM campaigns WHERE id = ?");
        if (!$stmt) {
            error_log("RedirectlessTracker::getCampaign prepare failed: " . $this->db->error);
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if ($row) {
            // Decode JSON fields
            $row['custom_tokens_json'] = $row['custom_tokens_json'] ? json_decode($row['custom_tokens_json'], true) : [];
            $row['redirect_rules_json'] = $row['redirect_rules_json'] ? json_decode($row['redirect_rules_json'], true) : [];
        }

        return $row ?: null;
    }

    /**
     * Verify landing page is in campaign rotation
     */
    private function verifyLandingPage(array $campaign, int $landingPageId): bool
    {
        $rotation = json_decode($campaign['rotation_json'] ?? '[]', true) ?: [];
        
        if ($campaign['flow_type'] === 'LP' && isset($rotation['landing_pages'])) {
            foreach ($rotation['landing_pages'] as $lp) {
                if (isset($lp['id']) && (int)$lp['id'] === $landingPageId) {
                    return true;
                }
            }
        } elseif ($campaign['flow_type'] === 'Split' && isset($rotation['lp_path']['landing_pages'])) {
            foreach ($rotation['lp_path']['landing_pages'] as $lp) {
                if (isset($lp['id']) && (int)$lp['id'] === $landingPageId) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * If request already carries a redirect click_id for this campaign (+ LP when set), reuse it.
     * Unknown / wrong-campaign IDs return null so a new click is created as usual.
     */
    private function resolveExistingClickId(int $campaignId, int $landingPageId, array $params): ?string
    {
        $raw = $params['click_id'] ?? '';
        if (!is_string($raw) && !is_numeric($raw)) {
            return null;
        }
        $clickId = trim((string)$raw);
        if ($clickId === '' || strlen($clickId) > 64) {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT click_id, landing_page_id FROM clicks WHERE click_id = ? AND campaign_id = ? LIMIT 1'
        );
        if (!$stmt) {
            error_log('RedirectlessTracker::resolveExistingClickId prepare failed: ' . $this->db->error);
            return null;
        }
        $stmt->bind_param('si', $clickId, $campaignId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return null;
        }

        // Prefer matching LP when the redirect click recorded one; allow NULL lp (edge cases)
        $rowLpId = isset($row['landing_page_id']) ? (int)$row['landing_page_id'] : 0;
        if ($rowLpId > 0 && $rowLpId !== $landingPageId) {
            return null;
        }

        error_log("RedirectlessTracker: reusing existing click_id=$clickId for campaign_id=$campaignId (skipped INSERT)");
        return $clickId;
    }

    /**
     * Generate unique click ID
     */
    private function generateClickId(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    /**
     * Detect traffic source from Tf parameter or use campaign default
     * Returns traffic_source_id or null
     */
    private function detectTrafficSource(array $campaign, array $params): ?int
    {
        // Check for Tf parameter (traffic source ID override)
        if (isset($params['Tf']) && !empty($params['Tf'])) {
            $trafficSourceId = (int)$params['Tf'];
            // Validate that traffic source exists
            $stmt = $this->db->prepare("SELECT id FROM traffic_sources WHERE id = ?");
            if (!$stmt) {
                error_log("RedirectlessTracker::detectTrafficSource prepare failed: " . $this->db->error);
                // Fall through to campaign default
            } else {
            $stmt->bind_param('i', $trafficSourceId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->fetch_assoc()) {
                return $trafficSourceId;
            }
            // Invalid Tf parameter, fall through to campaign default
            }
        }
        
        // Use campaign's default traffic source (if set)
        if (!empty($campaign['traffic_source_id'])) {
            return (int)$campaign['traffic_source_id'];
        }
        
        // No traffic source detected
        return null;
    }

    /**
     * Get traffic source configuration
     */
    private function getTrafficSourceConfig(int $trafficSourceId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM traffic_sources WHERE id = ?"
        );
        if (!$stmt) {
            error_log("RedirectlessTracker::getTrafficSourceConfig prepare failed: " . $this->db->error);
            return null;
        }
        $stmt->bind_param('i', $trafficSourceId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        if ($row) {
            // Decode JSON fields
            $row['tokens_json'] = $row['tokens_json'] ? json_decode($row['tokens_json'], true) : [];
        }
        
        return $row ?: null;
    }

    /**
     * Capture cost from URL parameters
     */
    private function captureCost(array $campaign, array $params, ?int $trafficSourceId = null): ?array
    {
        // If click has a hard cost in URL, use it for this click (takes precedence over everything)
        if (isset($params['cost']) && $params['cost'] !== '' && is_numeric($params['cost'])) {
            return [
                'cost' => (float)$params['cost'],
                'currency' => 'USD',
            ];
        }

        // If no traffic source detected, return null or use default CPC
        if (!$trafficSourceId) {
            if (!empty($campaign['default_cpc'])) {
                return [
                    'cost' => (float)$campaign['default_cpc'],
                    'currency' => 'USD',
                ];
            }
            return null;
        }
        
        // Get traffic source configuration
        $trafficSourceConfig = $this->getTrafficSourceConfig($trafficSourceId);
        if (!$trafficSourceConfig) {
            if (!empty($campaign['default_cpc'])) {
                return [
                    'cost' => (float)$campaign['default_cpc'],
                    'currency' => 'USD',
                ];
            }
            return null;
        }
        
        $costMethod = $trafficSourceConfig['cost_tracking_method'] ?? 'manual_token';

        // Check traffic source cost_param_key (e.g. if it's 'cpc' instead of 'cost') - already checked 'cost' above
        $costParamKey = !empty($trafficSourceConfig['cost_param_key']) ? $trafficSourceConfig['cost_param_key'] : 'cost';
        if ($costParamKey !== 'cost' && isset($params[$costParamKey]) && $params[$costParamKey] !== '' && is_numeric($params[$costParamKey])) {
            return [
                'cost' => (float)$params[$costParamKey],
                'currency' => $trafficSourceConfig['cost_currency'] ?? 'USD',
            ];
        }

        // If using integrated API, cost will be pulled from API later (not from URL params)
        if ($costMethod === 'integrated_api') {
            // For now, fall back to default CPC if available
            if (!empty($campaign['default_cpc'])) {
                return [
                    'cost' => (float)$campaign['default_cpc'],
                    'currency' => $trafficSourceConfig['cost_currency'] ?? 'USD',
                ];
            }
            return null;
        }

        // Manual token method: get cost from URL parameter
        if (!empty($trafficSourceConfig['cost_param_key']) && isset($params[$trafficSourceConfig['cost_param_key']])) {
            // Cost provided in URL
            return [
                'cost' => (float)$params[$trafficSourceConfig['cost_param_key']],
                'currency' => $trafficSourceConfig['cost_currency'] ?? 'USD',
            ];
        }

        // Use default CPC if no cost param found
        if (!empty($campaign['default_cpc'])) {
            return [
                'cost' => (float)$campaign['default_cpc'],
                'currency' => $trafficSourceConfig['cost_currency'] ?? 'USD',
            ];
        }

        return null;
    }

    /**
     * Store click in database
     */
    /**
     * Get slug_id from slug string
     */
    private function getSlugId(string $slug, int $campaignId): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM campaign_slugs WHERE slug = ? AND campaign_id = ?"
        );
        if (!$stmt) {
            error_log("RedirectlessTracker::getSlugId prepare failed: " . $this->db->error);
            return null;
        }
        $stmt->bind_param('si', $slug, $campaignId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        return $row ? (int)$row['id'] : null;
    }

    private function storeClick(int $campaignId, string $clickId, array $params, ?array $cost, array $campaign, int $landingPageId, ?int $trafficSourceId = null, ?int $slugId = null): void
    {
        // Get real IP address
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        
        // Apply IP anonymization if enabled
        $ipAnonymizationEnabled = ($this->settings->get('ip_anonymization', '0') === '1');
        if ($ipAnonymizationEnabled && $ip) {
            $ip = $this->anonymizeIp($ip);
        }
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $referrer = $_SERVER['HTTP_REFERER'] ?? null;

        // Enrich with geo and device data (postal used for Meta CAPI zp when available)
        $geoData = ['country' => null, 'region' => null, 'city' => null, 'postal' => null];
        $deviceData = [
            'device' => null, 
            'device_brand' => null, 
            'device_model' => null, 
            'os' => null, 
            'os_version' => null, 
            'browser' => null, 
            'browser_version' => null
        ];

        // Match Redirector: skip geo lookup for localhost (no useful city/country data)
        if ($ip && $ip !== '::1' && $ip !== '127.0.0.1' && $ip !== '::') {
            try {
                $geoLocator = new \SimpleKuma\Enrichment\GeoLocator($ip);
                $geoData = $geoLocator->getGeoData();
            } catch (\Exception $e) {
                error_log("RedirectlessTracker: GeoLocator error for IP {$ip}: " . $e->getMessage());
            }
        }

        if ($ua) {
            $deviceDetector = new \SimpleKuma\Enrichment\DeviceDetector($ua);
            $deviceData = $deviceDetector->getAll();
        }

        // Structure extra_json for queryable analytics
        $extraData = [
            'custom_tokens' => [],
            'traffic_source_tokens' => [],
            'all_params' => $params,
            'redirectless' => true,
            'landing_page_id' => $landingPageId,
            'cookies' => [] // Store Facebook cookies for CAPI postbacks
        ];

        $botDetection = \SimpleKuma\Enrichment\BotDetector::detect(
            $ua,
            $deviceData,
            $this->getBotDetectionOptions()
        );
        $extraData['bot'] = \SimpleKuma\Enrichment\BotDetector::toExtraJson($botDetection);
        
        // Capture Facebook cookies (_fbc and _fbp) for Conversions API
        // Meta's best practice: Use _fbc cookie directly if available
        // Reference: https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/fbp-and-fbc/
        if (!empty($_COOKIE['_fbc'])) {
            $extraData['cookies']['_fbc'] = $_COOKIE['_fbc'];
            $extraData['all_params']['_fbc'] = $_COOKIE['_fbc']; // Also store in all_params for easy access
        }
        if (!empty($_COOKIE['_fbp'])) {
            $extraData['cookies']['_fbp'] = $_COOKIE['_fbp'];
            $extraData['all_params']['_fbp'] = $_COOKIE['_fbp']; // Also store in all_params for easy access
        }

        // Extract custom token values based on campaign's custom_tokens_json
        $customTokens = $campaign['custom_tokens_json'] ?? [];
        if (!empty($customTokens) && is_array($customTokens)) {
            foreach ($customTokens as $token) {
                $parameter = $token['parameter'] ?? '';
                $name = $token['name'] ?? $parameter;
                
                if (!empty($parameter) && isset($params[$parameter])) {
                    $extraData['custom_tokens'][$parameter] = [
                        'name' => $name,
                        'value' => $params[$parameter]
                    ];
                }
            }
        }

        // Separate traffic source tokens from custom tokens
        // CRITICAL: Filter out placeholder values like '{value}' - these are template placeholders, not real values
        // Facebook automatically appends fbclid, so we should capture the real value from $_GET, not placeholders
        $placeholderPatterns = ['{value}', '{{value}}', '{value}', '{{value}}'];
        
        // CRITICAL: Store fbclid first-seen timestamp for Facebook CAPI postbacks
        // Meta requires: fbc.creation_time_millis = when fbclid was first observed/received
        // Store this explicitly to ensure accurate attribution even if click_ts has timezone issues
        $fbclidFirstSeenEpochMs = null;
        
        foreach ($params as $key => $value) {
            // Skip if value is a placeholder (not a real value)
            if (in_array(trim($value), $placeholderPatterns, true)) {
                continue;
            }
            
            $isCustomToken = false;
            foreach ($customTokens as $token) {
                if (($token['parameter'] ?? '') === $key) {
                    $isCustomToken = true;
                    break;
                }
            }
            
            if (!$isCustomToken) {
                $extraData['traffic_source_tokens'][$key] = $value;
                
                // If this is fbclid, store the first-seen timestamp (current server time in milliseconds)
                if ($key === 'fbclid' && !empty($value)) {
                    $fbclidFirstSeenEpochMs = round(microtime(true) * 1000); // Current time in milliseconds
                    $extraData['fbclid_first_seen_epoch_ms'] = $fbclidFirstSeenEpochMs;
                }
            }
        }

        // Check if traffic_source_id column exists (added in migration 033)
        $trafficSourceColumnExists = false;
        $checkResult = $this->db->query("SHOW COLUMNS FROM clicks LIKE 'traffic_source_id'");
        if ($checkResult && $checkResult->num_rows > 0) {
            $trafficSourceColumnExists = true;
        }

        // Check if clicks.postal column exists (migration 052)
        $postalColumnExists = false;
        $postalCheck = $this->db->query("SHOW COLUMNS FROM clicks LIKE 'postal'");
        if ($postalCheck && $postalCheck->num_rows > 0) {
            $postalColumnExists = true;
        }
        $statsFlagColumnExists = StatsExclusionFlag::columnExists($this->db);
        $excludeFromStats = CampaignStatsExpressions::shouldExcludeClickFromStats(
            $trafficSourceId,
            $extraData,
            $ua
        ) ? 1 : 0;
        $statsFlagColumn = $statsFlagColumnExists ? ', exclude_from_stats' : '';
        $statsFlagValue = $statsFlagColumnExists ? ', ?' : '';
        $statsFlagType = $statsFlagColumnExists ? 'i' : '';

        $geoCols = "country, region, city";
        $geoPlaceholders = "?, ?, ?";
        $geoParamTypes = "sss";
        $geoBind = [$geoData['country'], $geoData['region'], $geoData['city']];
        if ($postalColumnExists) {
            $geoCols .= ", postal";
            $geoPlaceholders .= ", ?";
            $geoParamTypes .= "s";
            $geoBind[] = $geoData['postal'] ?? null;
        }

        // Build INSERT statement based on column existence (must match Redirector - include offer_id)
        // Redirectless: offer_id is NULL (visitor on LP, no offer yet)
        if ($trafficSourceColumnExists) {
            $sql = "INSERT INTO clicks
                (campaign_id, slug_id, traffic_source_id, offer_id, landing_page_id, click_id, ts, ip, ua, referrer, " . $geoCols . ",
                 device, device_brand, device_model, os, os_version, browser, browser_version, cost, cost_currency, extra_json" . $statsFlagColumn . ", ts_hour, lp_click, ts_lp)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, " . $geoPlaceholders . ", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?" . $statsFlagValue . ", DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00'), ?, NULL)";
            $paramTypes = 'iiiiissss' . $geoParamTypes . 'sssssssdss' . $statsFlagType . 'i';
        } else {
            $sql = "INSERT INTO clicks
                (campaign_id, slug_id, offer_id, landing_page_id, click_id, ts, ip, ua, referrer, " . $geoCols . ",
                 device, device_brand, device_model, os, os_version, browser, browser_version, cost, cost_currency, extra_json" . $statsFlagColumn . ", ts_hour, lp_click, ts_lp)
                VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, " . $geoPlaceholders . ", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?" . $statsFlagValue . ", DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00'), ?, NULL)";
            $paramTypes = 'iiiissss' . $geoParamTypes . 'sssssssdss' . $statsFlagType . 'i';
        }

        // For redirectless tracking, set lp_click = 0 initially
        // It will be set to 1 when user clicks the button (via LPRotator)
        $stmt = $this->db->prepare($sql);

        if (!$stmt) {
            error_log("RedirectlessTracker::storeClick prepare failed: " . $this->db->error);
            throw new \Exception("Failed to prepare INSERT statement: " . $this->db->error);
        }

        $costValue = $cost['cost'] ?? null;
        $costCurrency = $cost['currency'] ?? null;
        $extraJson = json_encode($extraData);
        $lpClick = 0; // Will be set to 1 when user clicks button
        
        // Debug logging
        error_log("RedirectlessTracker::storeClick: traffic_source_id=" . ($trafficSourceId ?? 'NULL') . ", campaign_id=$campaignId, click_id=$clickId, slug_id=" . ($slugId ?? 'NULL') . ", traffic_source_column_exists=" . ($trafficSourceColumnExists ? 'yes' : 'no'));

        $offerId = null; // Redirectless: visitor on LP, no offer yet
        $bindValues = $trafficSourceColumnExists
            ? [$campaignId, $slugId, $trafficSourceId, $offerId, $landingPageId, $clickId, $ip, $ua, $referrer]
            : [$campaignId, $slugId, $offerId, $landingPageId, $clickId, $ip, $ua, $referrer];
        $bindValues = array_merge($bindValues, $geoBind, [
            $deviceData['device'],
            $deviceData['device_brand'],
            $deviceData['device_model'],
            $deviceData['os'],
            $deviceData['os_version'],
            $deviceData['browser'],
            $deviceData['browser_version'],
            $costValue,
            $costCurrency,
            $extraJson,
        ]);
        if ($statsFlagColumnExists) {
            $bindValues[] = $excludeFromStats;
        }
        $bindValues[] = $lpClick;
        $stmt->bind_param($paramTypes, ...$bindValues);

        $result = $stmt->execute();
        
        if (!$result) {
            error_log("RedirectlessTracker::storeClick ERROR: " . $stmt->error);
        } else {
            error_log("RedirectlessTracker::storeClick SUCCESS: click_id=$clickId, traffic_source_id=" . ($trafficSourceId ?? 'NULL'));
            // On-write: UPSERT clicks_daily_summary and token aggregates (no cron)
            $updater = new DailySummaryUpdater($this->db);
            $updater->upsertClick(
                $campaignId,
                $trafficSourceId,
                null, // offer_id (redirectless: LP first)
                $landingPageId,
                0,    // lp_click (set to 1 when user clicks button)
                $costValue !== null ? (float) $costValue : null,
                $extraData,
                $ua,
                $ip
            );
            $updater->upsertTokenAggregatesForClick(
                $campaignId,
                $trafficSourceId,
                gmdate('Y-m-d'),
                $extraData,
                0,    // lp_click
                $costValue !== null ? (float) $costValue : null,
                0,
                0.0,
                $ua,
                $ip
            );
        }
    }

    /**
     * Anonymize IP address (mask last octet for IPv4, last 64 bits for IPv6)
     */
    private function anonymizeIp(string $ip): string
    {
        // IPv4: mask last octet (e.g., 192.168.1.100 -> 192.168.1.0)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            if (count($parts) === 4) {
                $parts[3] = '0';
                return implode('.', $parts);
            }
        }
        
        // IPv6: mask last 64 bits (e.g., 2001:0db8::1 -> 2001:0db8::)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // Compress IPv6 and mask last 64 bits
            $expanded = explode('::', $ip);
            if (count($expanded) === 2) {
                return $expanded[0] . '::';
            } else {
                // Full format IPv6 - mask last 4 hextets
                $parts = explode(':', $ip);
                if (count($parts) >= 5) {
                    return implode(':', array_slice($parts, 0, 4)) . '::';
                }
            }
        }
        
        return $ip; // Return as-is if unable to anonymize
    }

    /**
     * @return array{enabled: bool, exclude_known: bool, exclude_suspected: bool, check_headers: bool}
     */
    private function getBotDetectionOptions(): array
    {
        $defaults = [
            'bot_detection_enabled' => '1',
            'bot_exclude_known_from_stats' => '1',
            'bot_exclude_suspected_from_stats' => '0',
        ];
        $vals = $this->settings !== null
            ? $this->settings->getMany(array_keys($defaults), $defaults)
            : $defaults;

        return [
            'enabled' => ($vals['bot_detection_enabled'] ?? '1') === '1',
            'exclude_known' => ($vals['bot_exclude_known_from_stats'] ?? '1') === '1',
            'exclude_suspected' => ($vals['bot_exclude_suspected_from_stats'] ?? '0') === '1',
            'check_headers' => false,
        ];
    }
}

