<?php

declare(strict_types=1);

namespace SimpleKuma\Tracking;

use mysqli;
use SimpleKuma\Settings\SettingsManager;
use SimpleKuma\Stats\CampaignStatsExpressions;
use SimpleKuma\Stats\StatsExclusionFlag;

/**
 * Redirector
 * Handles click tracking and redirection
 */
class Redirector
{
    private mysqli $db;
    private ?SettingsManager $settings = null;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->settings = new SettingsManager($db);
    }

    /**
     * Process click and redirect
     */
    public function process(string $campaignKey, array $params): void
    {
        // Load campaign by key
        $campaign = $this->getCampaignByKey($campaignKey);

        if (!$campaign) {
            error_log("Redirector: Campaign lookup failed for key/slug: {$campaignKey}. Checked both campaign_slugs table and campaign_key.");
            http_response_code(404);
            if (!headers_sent()) {
                header('Content-Type: text/plain; charset=utf-8');
            }
            die('Campaign not found');
        }
        
        // Log successful lookup for debugging
        error_log("Redirector: Campaign found - ID: {$campaign['id']}, Name: {$campaign['name']}, Key: " . ($campaign['campaign_key'] ?? 'N/A') . ", Slug ID: " . ($campaign['slug_id'] ?? 'N/A'));

        if ($campaign['status'] !== 'active') {
            http_response_code(403);
            die('Campaign is not active');
        }

        // Detect traffic source from Tf parameter or use campaign default
        $detectedTrafficSourceId = $this->detectTrafficSource($campaign, $params);
        
        // Load traffic source configuration
        $trafficSourceConfig = null;
        if ($detectedTrafficSourceId) {
            $trafficSourceConfig = $this->getTrafficSourceConfig($detectedTrafficSourceId);
        }

        // Generate unique click ID
        $clickId = $this->generateClickId();

        // Check rule-based redirects (on campaign click)
        $ruleRedirect = $this->checkRedirectRules($campaign, $params, 'campaign_click');
        if ($ruleRedirect) {
            // Store click before redirect
            // For rule redirects, we can't determine if it's an offer, so leave lp_click as false
            $cost = $this->captureCost($campaign, $params, $detectedTrafficSourceId, $trafficSourceConfig);
            $slugId = isset($campaign['slug_id']) ? (int)$campaign['slug_id'] : null;
            $this->storeClick($campaign['id'], $clickId, $params, $cost, $campaign, false, null, null, $detectedTrafficSourceId, $slugId, true);
            // Traffic-source click: no cloaking on redirect (LP or rule target)
            $this->redirect($ruleRedirect, null);
            return;
        }

        // Capture cost using detected traffic source
        $cost = $this->captureCost($campaign, $params, $detectedTrafficSourceId, $trafficSourceConfig);

        // Resolve destination (returns array with url, offer_id, landing_page_id)
        $destinationData = $this->resolveDestination($campaign);

        if (!$destinationData || !isset($destinationData['url'])) {
            // No offers available (all outside time window or disabled)
            // Still store the click for tracking, then show friendly message
            $campaignId = $campaign['id'] ?? 'unknown';
            $flowType = $campaign['flow_type'] ?? 'unknown';
            $rotationJson = is_array($campaign['rotation_json'] ?? null) 
                ? json_encode($campaign['rotation_json']) 
                : ($campaign['rotation_json'] ?? '[]');
            error_log("Redirector: No available offers for campaign ID {$campaignId} (flow_type: {$flowType}). All offers are outside time window or disabled. Rotation JSON: {$rotationJson}");
            
            // Store click even though no destination (for tracking purposes)
            $slugId = isset($campaign['slug_id']) ? (int)$campaign['slug_id'] : null;
            $this->storeClick($campaign['id'], $clickId, $params, $cost, $campaign, false, null, null, $detectedTrafficSourceId, $slugId);
            
            // Show friendly "no offers available" page instead of error
            $this->showNoOffersAvailablePage($campaign);
            return;
        }

        $destination = $destinationData['url'];
        $offerId = $destinationData['offer_id'] ?? null;
        $landingPageId = $destinationData['landing_page_id'] ?? null;

        // Determine if this is a direct to offer (DTO or Split direct path) flow
        $isDirectToOffer = $this->isDirectToOffer($campaign, $destination);

        // For DTO/Split direct path flows, also check redirect rules for 'offer_click' 
        // (since user goes directly to offer, not through LP)
        if ($isDirectToOffer) {
            $ruleRedirect = $this->checkRedirectRules($campaign, $params, 'offer_click');
            if ($ruleRedirect) {
                // Store click before redirect
                $slugId = isset($campaign['slug_id']) ? (int)$campaign['slug_id'] : null;
                $this->storeClick($campaign['id'], $clickId, $params, $cost, $campaign, $isDirectToOffer, $offerId, $landingPageId, $detectedTrafficSourceId, $slugId, true);
                $this->redirect(
                    $ruleRedirect,
                    KumaHopRedirect::modeForOutboundOffer(true, $this->referrerModeFromCampaign($campaign))
                );
                return;
            }
        }

        // Store click in database (async would be better for performance)
        $slugId = isset($campaign['slug_id']) ? (int)$campaign['slug_id'] : null;
        $this->storeClick($campaign['id'], $clickId, $params, $cost, $campaign, $isDirectToOffer, $offerId, $landingPageId, $detectedTrafficSourceId, $slugId);

        // Check if URL contains tokens - if so, use ClickTokenReplacer; otherwise append parameters
        if ($isDirectToOffer && $this->urlContainsTokens($destination)) {
            // Use ClickTokenReplacer for URLs with tokens
            $clickTokenReplacer = new \SimpleKuma\Tracking\ClickTokenReplacer();
            
            // Get click data for context (we need to fetch it since we just stored it)
            $stmt = $this->db->prepare("SELECT * FROM clicks WHERE click_id = ?");
            $stmt->bind_param('s', $clickId);
            $stmt->execute();
            $result = $stmt->get_result();
            $click = $result->fetch_assoc();
            
            // Get traffic source tokens for context (use detected traffic source)
            $trafficSourceTokens = [];
            if ($trafficSourceConfig && !empty($trafficSourceConfig['tokens_json'])) {
                // tokens_json is already decoded by getTrafficSourceConfig()
                $trafficSourceTokens = is_array($trafficSourceConfig['tokens_json']) 
                    ? $trafficSourceConfig['tokens_json'] 
                    : (json_decode($trafficSourceConfig['tokens_json'], true) ?? []);
            }
            
            // Get all traffic sources for unique token matching
            $allTrafficSources = [];
            $tsStmt = $this->db->prepare("SELECT id, name, tokens_json FROM traffic_sources");
            if ($tsStmt) {
                $tsStmt->execute();
                $tsResult = $tsStmt->get_result();
                while ($ts = $tsResult->fetch_assoc()) {
                    $allTrafficSources[] = $ts;
                }
                $tsStmt->close();
            }
            
            // Build context for token replacement
            $context = [
                'click' => $click ?? [],
                'campaign' => array_merge($campaign, ['traffic_source_tokens' => $trafficSourceTokens]),
                'all_traffic_sources' => $allTrafficSources,
            ];
            
            $destination = $clickTokenReplacer->replace($destination, $context);
        } else {
            // Append parameters to destination (filtered by pass_to_lp/pass_to_offer settings)
            // Use detected traffic source config
            $destination = $this->appendParameters($destination, $params, $clickId, $campaign, $isDirectToOffer, $trafficSourceConfig);
        }

        // Cloaking only when this hop goes straight to an offer (DTO / split direct path)
        $this->redirect(
            $destination,
            KumaHopRedirect::modeForOutboundOffer($isDirectToOffer, $this->referrerModeFromCampaign($campaign))
        );
    }

    /**
     * Get campaign from database by key
     */
    private function getCampaignByKey(string $key): ?array
    {
        // First check campaign_slugs table for multi-slug support
        // This handles URLs like /km/myslug where myslug is a custom slug
        $stmt = $this->db->prepare(
            "SELECT c.*, cs.id as slug_id, cs.slug, cs.slug_label
             FROM campaigns c
             INNER JOIN campaign_slugs cs ON c.id = cs.campaign_id
             WHERE cs.slug = ? AND c.status = 'active'
             LIMIT 1"
        );
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $result = $stmt->get_result();
        $campaign = $result->fetch_assoc();

        // Fallback to legacy campaign_key if not found in slugs
        // This handles URLs like /km/dnVlJoT7 where dnVlJoT7 is the campaign_key
        if (!$campaign) {
            $stmt = $this->db->prepare(
                "SELECT * FROM campaigns WHERE campaign_key = ? AND status = 'active'"
            );
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $result = $stmt->get_result();
            $campaign = $result->fetch_assoc();
        }

        if ($campaign) {
            $campaign['rotation_json'] = json_decode($campaign['rotation_json'] ?? '[]', true);
            $campaign['pass_through_json'] = json_decode($campaign['pass_through_json'] ?? '[]', true);
            $campaign['custom_tokens_json'] = json_decode($campaign['custom_tokens_json'] ?? '[]', true) ?? [];
            $campaign['redirect_rules_json'] = json_decode($campaign['redirect_rules_json'] ?? '[]', true) ?? [];
        } else {
            // Log for debugging when campaign is not found
            error_log("Redirector: Campaign not found for key/slug: {$key}. Checked both campaign_slugs table and campaign_key.");
        }

        return $campaign ?: null;
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
            $stmt->bind_param('i', $trafficSourceId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->fetch_assoc()) {
                return $trafficSourceId;
            }
            // Invalid Tf parameter, fall through to campaign default
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
            "SELECT id, name, cost_tracking_method, cost_param_key, cost_currency, tokens_json 
             FROM traffic_sources WHERE id = ?"
        );
        $stmt->bind_param('i', $trafficSourceId);
        $stmt->execute();
        $result = $stmt->get_result();
        $config = $result->fetch_assoc();
        
        if ($config && !empty($config['tokens_json'])) {
            $config['tokens_json'] = json_decode($config['tokens_json'], true) ?? [];
        }
        
        return $config ?: null;
    }

    /**
     * Generate unique click ID (UUID v4)
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
     * Capture cost from params or use default
     */
    private function captureCost(array $campaign, array $params, ?int $trafficSourceId = null, ?array $trafficSourceConfig = null): ?array
    {
        // If click has a hard cost in URL, use it for this click (takes precedence over everything)
        // Check 'cost' (common param) and traffic source's cost_param_key
        $costParamKey = ($trafficSourceConfig && !empty($trafficSourceConfig['cost_param_key']))
            ? $trafficSourceConfig['cost_param_key']
            : 'cost';
        $costValue = $params[$costParamKey] ?? $params['cost'] ?? null;
        if ($costValue !== null && $costValue !== '' && is_numeric($costValue)) {
            return [
                'cost' => (float)$costValue,
                'currency' => $trafficSourceConfig ? ($trafficSourceConfig['cost_currency'] ?? 'USD') : 'USD',
            ];
        }

        // If no traffic source detected, return null or default
        if (!$trafficSourceId || !$trafficSourceConfig) {
            if (!empty($campaign['default_cpc'])) {
                return [
                    'cost' => (float)$campaign['default_cpc'],
                    'currency' => 'USD',
                ];
            }
            return null;
        }

        $costMethod = $trafficSourceConfig['cost_tracking_method'] ?? 'manual_token';

        // If using integrated API, cost will be pulled from API later (not from URL params)
        if ($costMethod === 'integrated_api') {
            // For now, fall back to default CPC if available
            // TODO: Implement API cost fetching (Facebook/Google API integration)
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
     * Resolve destination URL based on flow and weights
     * Returns array with 'url', 'offer_id', and 'landing_page_id' keys
     */
    private function resolveDestination(array $campaign): ?array
    {
        $rotation = $campaign['rotation_json'] ?? [];

        if (empty($rotation)) {
            return null;
        }

        // Helper function to filter enabled items
        $filterEnabled = function($items) {
            return array_filter($items, function($item) {
                return !isset($item['enabled']) || $item['enabled'] !== false;
            });
        };

        // Check if this is Split test (LP path vs Direct path)
        if (isset($rotation['split_traffic'])) {
            $lpPercent = $rotation['split_traffic']['lp_percent'] ?? 50;
            $random = mt_rand(1, 100);
            
            if ($random <= $lpPercent) {
                // Go to LP path - pick a landing page from rotation (only enabled)
                $lps = $filterEnabled($rotation['lp_path']['landing_pages'] ?? []);
                if (!empty($lps)) {
                    $lps = array_values($lps); // Re-index after filtering
                    if (count($lps) === 1) {
                        return $this->getDestinationData($lps[0]);
                    }
                    // Rotate LPs
                    $totalWeight = array_sum(array_column($lps, 'weight'));
                    $lpRandom = mt_rand(1, $totalWeight);
                    $currentWeight = 0;
                    foreach ($lps as $lp) {
                        $currentWeight += $lp['weight'];
                        if ($lpRandom <= $currentWeight) {
                            return $this->getDestinationData($lp);
                        }
                    }
                    return $this->getDestinationData($lps[0]);
                }
            } else {
                // Go to Direct path - pick an offer from rotation (only enabled)
                $offers = $filterEnabled($rotation['direct_path']['offers'] ?? []);
                if (!empty($offers)) {
                    // Filter offers by schedule availability and CAP status
                    $offerEntity = new \SimpleKuma\Entity\Offer($this->db);
                    $availableOffers = [];
                    foreach ($offers as $offerConfig) {
                        $offerId = $offerConfig['id'] ?? null;
                        if (!$offerId) {
                            continue;
                        }
                        
                        // Load full offer data to check schedule and CAP
                        $offerData = $offerEntity->getById($offerId);
                        if ($offerData && $offerEntity->isAvailableForRotation($offerData)) {
                            $availableOffers[] = $offerConfig;
                        }
                    }
                    
                    if (!empty($availableOffers)) {
                        $availableOffers = array_values($availableOffers); // Re-index after filtering
                        if (count($availableOffers) === 1) {
                            return $this->getDestinationData($availableOffers[0]);
                        }
                        // Rotate offers - weights automatically recalculate based on available offers only
                        $totalWeight = array_sum(array_column($availableOffers, 'weight'));
                        if ($totalWeight > 0) {
                            $offerRandom = mt_rand(1, $totalWeight);
                            $currentWeight = 0;
                            foreach ($availableOffers as $offer) {
                                $currentWeight += $offer['weight'];
                                if ($offerRandom <= $currentWeight) {
                                    return $this->getDestinationData($offer);
                                }
                            }
                            return $this->getDestinationData($availableOffers[0]);
                        }
                    }
                    // No available offers in direct path - try fallback
                    $fallback = $this->tryFallbackOffer($campaign);
                    if ($fallback) {
                        return $fallback;
                    }
                }
            }
        }
        
        // Check if this is LP flow (has landing_pages array)
        if (isset($rotation['landing_pages'])) {
            // LP Flow: redirect to landing page (with rotation if multiple) - only enabled
            $landingPages = $filterEnabled($rotation['landing_pages']);
            
            if (empty($landingPages)) {
                return null;
            }
            
            $landingPages = array_values($landingPages); // Re-index after filtering
            
            // Single LP
            if (count($landingPages) === 1) {
                return $this->getDestinationData($landingPages[0]);
            }
            
            // Multiple LPs - weighted rotation
            $totalWeight = array_sum(array_column($landingPages, 'weight'));
            $random = mt_rand(1, $totalWeight);
            $currentWeight = 0;

            foreach ($landingPages as $lp) {
                $currentWeight += $lp['weight'];
                if ($random <= $currentWeight) {
                    return $this->getDestinationData($lp);
                }
            }
            
            // Fallback
            return $this->getDestinationData($landingPages[0]);
        }

        // For single destination or DTO - filter enabled offers
        // DTO campaigns: rotation_json is an array of offer objects directly
        // Each offer object should have: id, type='offer', weight, enabled
        $enabledOffers = $filterEnabled($rotation);
        
        // Ensure we have an array of offer objects (not empty, and has 'id' keys)
        if (empty($enabledOffers)) {
            error_log("Redirector: No enabled offers in rotation_json for DTO campaign. Rotation structure: " . json_encode($rotation));
            return null;
        }
        
        // Validate that items have 'id' and 'type' fields
        $validOffers = [];
        foreach ($enabledOffers as $offerConfig) {
            $offerId = $offerConfig['id'] ?? null;
            $offerType = $offerConfig['type'] ?? 'offer';
            
            if (!$offerId) {
                error_log("Redirector: Offer config missing 'id' field: " . json_encode($offerConfig));
                continue;
            }
            
            // Ensure type is 'offer' for DTO
            if ($offerType !== 'offer') {
                error_log("Redirector: Invalid type '{$offerType}' for DTO offer, expected 'offer'. Config: " . json_encode($offerConfig));
                continue;
            }
            
            $validOffers[] = $offerConfig;
        }
        
        if (empty($validOffers)) {
            error_log("Redirector: No valid offers found in rotation_json for DTO campaign. Rotation: " . json_encode($rotation));
            return null;
        }
        
        // Filter offers by schedule availability
        try {
            $offerEntity = new \SimpleKuma\Entity\Offer($this->db);
        } catch (Throwable $e) {
            error_log("Redirector: Failed to instantiate Offer entity: " . $e->getMessage());
            return null;
        }
        
        $availableOffers = [];
        foreach ($validOffers as $offerConfig) {
            $offerId = $offerConfig['id'] ?? null;
            
            try {
                // Load full offer data to check schedule
                $offerData = $offerEntity->getById($offerId);
                if (!$offerData) {
                    error_log("Redirector: Offer ID {$offerId} not found in database");
                    continue;
                }
                
                // Check if offer is available (schedule and CAP check)
                $isAvailable = $offerEntity->isAvailableForRotation($offerData);
                if ($isAvailable) {
                    $availableOffers[] = $offerConfig;
                    error_log("Redirector: Offer ID {$offerId} is available and added to rotation");
                } else {
                    error_log("Redirector: Offer ID {$offerId} is not available now (schedule or CAP check failed). Offer data: " . json_encode([
                        'is_24_7' => $offerData['is_24_7'] ?? null,
                        'schedule_days' => $offerData['schedule_days'] ?? null,
                        'schedule_start_time' => $offerData['schedule_start_time'] ?? null,
                        'schedule_end_time' => $offerData['schedule_end_time'] ?? null,
                        'schedule_timezone' => $offerData['schedule_timezone'] ?? null,
                        'cap_enabled' => $offerData['cap_enabled'] ?? null,
                        'cap_limit' => $offerData['cap_limit'] ?? null,
                        'cap_period' => $offerData['cap_period'] ?? null
                    ]));
                }
            } catch (Throwable $e) {
                error_log("Redirector: Error checking offer ID {$offerId}: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
                error_log("Redirector: Stack trace: " . $e->getTraceAsString());
                // Continue to next offer instead of failing completely
                continue;
            }
        }
        
        $availableOffers = array_values($availableOffers); // Re-index after filtering
        
        if (empty($availableOffers)) {
            error_log("Redirector: No available offers found for DTO campaign after schedule check. Campaign ID: " . ($campaign['id'] ?? 'unknown'));
            $fallback = $this->tryFallbackOffer($campaign);
            if ($fallback) {
                return $fallback;
            }
            return null;
        }
        
        if (count($availableOffers) === 1) {
            return $this->getDestinationData($availableOffers[0]);
        }

        // Weighted rotation (for DTO with multiple enabled offers)
        $totalWeight = array_sum(array_column($availableOffers, 'weight'));
        if ($totalWeight <= 0) {
            return null;
        }
        
        $random = mt_rand(1, $totalWeight);
        $currentWeight = 0;

        foreach ($availableOffers as $item) {
            $currentWeight += $item['weight'];
            if ($random <= $currentWeight) {
                return $this->getDestinationData($item);
            }
        }

        // Fallback to first item
        return $this->getDestinationData($availableOffers[0]);
    }

    /**
     * Try campaign fallback offer when all regular offers are unavailable
     */
    private function tryFallbackOffer(array $campaign): ?array
    {
        $fallbackId = !empty($campaign['fallback_offer_id']) ? (int)$campaign['fallback_offer_id'] : null;
        if (!$fallbackId) {
            return null;
        }

        $offerEntity = new \SimpleKuma\Entity\Offer($this->db);
        $offerData = $offerEntity->getById($fallbackId);
        if (!$offerData || !$offerEntity->isAvailableForRotation($offerData)) {
            return null;
        }

        return $this->getDestinationData(['id' => $fallbackId, 'type' => 'offer']);
    }

    /**
     * Get destination URL from rotation item
     * @deprecated Use getDestinationData() instead
     */
    private function getDestinationUrl(array $item): ?string
    {
        $data = $this->getDestinationData($item);
        return $data['url'] ?? null;
    }

    /**
     * Get destination data (URL, offer_id, landing_page_id) from rotation item
     */
    private function getDestinationData(array $item): ?array
    {
        // For DTO campaigns, if type is not set, assume it's an offer
        $itemType = $item['type'] ?? 'offer';
        
        if ($itemType === 'offer') {
            try {
                $stmt = $this->db->prepare("SELECT url FROM offers WHERE id = ?");
                if (!$stmt) {
                    error_log("Redirector: Failed to prepare offer query: " . $this->db->error);
                    return null;
                }
                $stmt->bind_param('i', $item['id']);
                $stmt->execute();
                $result = $stmt->get_result();
                $offer = $result->fetch_assoc();
                if (!$offer || !isset($offer['url'])) {
                    error_log("Redirector: Offer ID {$item['id']} not found or has no URL");
                    return null;
                }
                return [
                    'url' => $offer['url'],
                    'offer_id' => (int)$item['id'],
                    'landing_page_id' => null
                ];
            } catch (Throwable $e) {
                error_log("Redirector: Error fetching offer ID {$item['id']}: " . $e->getMessage());
                return null;
            }
        } elseif ($item['type'] === 'landing_page') {
            try {
                $stmt = $this->db->prepare("SELECT url FROM landing_pages WHERE id = ?");
                if (!$stmt) {
                    error_log("Redirector: Failed to prepare landing page query: " . $this->db->error);
                    return null;
                }
                $stmt->bind_param('i', $item['id']);
                $stmt->execute();
                $result = $stmt->get_result();
                $lp = $result->fetch_assoc();
                if (!$lp || !isset($lp['url'])) {
                    error_log("Redirector: Landing page ID {$item['id']} not found or has no URL");
                    return null;
                }
                return [
                    'url' => $lp['url'],
                    'offer_id' => null,
                    'landing_page_id' => (int)$item['id']
                ];
            } catch (Throwable $e) {
                error_log("Redirector: Error fetching landing page ID {$item['id']}: " . $e->getMessage());
                return null;
            }
        }

        error_log("Redirector: Unknown item type in getDestinationData: " . ($item['type'] ?? 'unknown'));
        return null;
    }

    /**
     * Check if URL contains token placeholders
     */
    private function urlContainsTokens(string $url): bool
    {
        // Check for token patterns like {click_id}, {token1}, {ts_token1}, etc.
        return preg_match('/\{[a-zA-Z0-9_]+\}/', $url) === 1;
    }

    /**
     * Check rule-based redirects
     * Returns redirect URL if rule matches, null otherwise
     */
    private function checkRedirectRules(array $campaign, array $params, string $executionPoint): ?string
    {
        $redirectRules = $campaign['redirect_rules_json'] ?? [];
        
        // Debug: Log redirect rules
        error_log("=== REDIRECT RULES CHECK ===");
        error_log("Execution point: " . $executionPoint);
        error_log("Redirect rules count: " . count($redirectRules));
        error_log("Redirect rules: " . json_encode($redirectRules, JSON_PRETTY_PRINT));
        
        if (empty($redirectRules) || !is_array($redirectRules)) {
            error_log("No redirect rules to check or rules is not an array");
            return null;
        }

        // Build comprehensive token map: token display name -> (type, parameter/column)
        $tokenMap = [];
        
        // 1. Custom campaign tokens
        $customTokens = $campaign['custom_tokens_json'] ?? [];
        if (is_array($customTokens)) {
            foreach ($customTokens as $token) {
                $displayName = $token['name'] ?? '';
                $paramName = $token['parameter'] ?? '';
                if ($displayName && $paramName) {
                    $tokenMap[$displayName] = ['type' => 'custom', 'param' => $paramName];
                }
            }
        }
        
        // 2. Built-in tracker tokens (map display names to column names)
        $builtInTokens = [
            'Location (Country)' => ['type' => 'builtin', 'column' => 'country'],
            'State/Region' => ['type' => 'builtin', 'column' => 'region'],
            'City' => ['type' => 'builtin', 'column' => 'city'],
            'Device' => ['type' => 'builtin', 'column' => 'device'],
            'Device Brand' => ['type' => 'builtin', 'column' => 'device_brand'],
            'Device Model' => ['type' => 'builtin', 'column' => 'device_model'],
            'Operating System' => ['type' => 'builtin', 'column' => 'os'],
            'OS Version' => ['type' => 'builtin', 'column' => 'os_version'],
            'Browser' => ['type' => 'builtin', 'column' => 'browser'],
            'Browser Version' => ['type' => 'builtin', 'column' => 'browser_version'],
            'IP Address' => ['type' => 'builtin', 'column' => 'ip'],
        ];
        foreach ($builtInTokens as $displayName => $config) {
            $tokenMap[$displayName] = $config;
        }
        
        // 3. Traffic source tokens
        $trafficSourceId = $campaign['traffic_source_id'] ?? null;
        $isAutoDetect = empty($trafficSourceId);
        
        if ($isAutoDetect) {
            // Auto-detect: Get tokens from ALL traffic sources
            $stmt = $this->db->prepare("SELECT id, name, tokens_json FROM traffic_sources");
            $stmt->execute();
            $result = $stmt->get_result();
            while ($ts = $result->fetch_assoc()) {
                $tsTokens = is_array($ts['tokens_json']) 
                    ? $ts['tokens_json'] 
                    : json_decode($ts['tokens_json'] ?? '[]', true) ?? [];
                foreach ($tsTokens as $tsToken) {
                    $displayName = $tsToken['name'] ?? '';
                    $paramName = $tsToken['parameter'] ?? '';
                    if ($displayName && $paramName) {
                        // For auto-detect, we need to check all traffic sources
                        // Store with source name for uniqueness if needed
                        $key = $displayName;
                        // Don't overwrite built-in tokens with traffic source tokens
                        if (isset($tokenMap[$key]) && $tokenMap[$key]['type'] === 'builtin') {
                            // Built-in tokens take priority - skip this traffic source token
                            continue;
                        }
                        if (isset($tokenMap[$key]) && $tokenMap[$key]['type'] === 'traffic_source') {
                            // Multiple traffic sources have same token name - use first one found
                            // In practice, parameter names should be unique per traffic source
                            continue;
                        }
                        $tokenMap[$key] = ['type' => 'traffic_source', 'param' => $paramName];
                    }
                }
            }
        } else {
            // Specific traffic source: Get tokens from selected traffic source only
            $stmt = $this->db->prepare("SELECT tokens_json FROM traffic_sources WHERE id = ?");
            $stmt->bind_param('i', $trafficSourceId);
            $stmt->execute();
            $result = $stmt->get_result();
            $ts = $result->fetch_assoc();
            if ($ts) {
                $tsTokens = is_array($ts['tokens_json']) 
                    ? $ts['tokens_json'] 
                    : json_decode($ts['tokens_json'] ?? '[]', true) ?? [];
                foreach ($tsTokens as $tsToken) {
                    $displayName = $tsToken['name'] ?? '';
                    $paramName = $tsToken['parameter'] ?? '';
                    if ($displayName && $paramName) {
                        // Don't overwrite built-in tokens with traffic source tokens
                        if (isset($tokenMap[$displayName]) && $tokenMap[$displayName]['type'] === 'builtin') {
                            // Built-in tokens take priority - skip this traffic source token
                            continue;
                        }
                        $tokenMap[$displayName] = ['type' => 'traffic_source', 'param' => $paramName];
                    }
                }
            }
        }
        
        // Enrich built-in token data on-the-fly (since we don't have click record yet)
        $builtInValues = [];
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ip) {
            // Apply IP anonymization if enabled
            $ipAnonymizationEnabled = ($this->settings->get('ip_anonymization', '0') === '1');
            if ($ipAnonymizationEnabled) {
                $ip = $this->anonymizeIp($ip);
            }
            $builtInValues['ip'] = $ip;
            
            // Get geo data
            $geoLocator = new \SimpleKuma\Enrichment\GeoLocator($ip);
            $geoData = $geoLocator->getGeoData();
            $builtInValues['country'] = $geoData['country'] ?? '';
            $builtInValues['region'] = $geoData['region'] ?? '';
            $builtInValues['city'] = $geoData['city'] ?? '';
        }
        
        // Get device data
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        if ($ua) {
            try {
                $deviceDetector = new \SimpleKuma\Enrichment\DeviceDetector($ua);
                $deviceData = $deviceDetector->getAll();
                $builtInValues['device'] = !empty($deviceData['device']) ? $deviceData['device'] : 'desktop'; // Default to desktop if empty
                $builtInValues['device_brand'] = $deviceData['device_brand'] ?? '';
                $builtInValues['device_model'] = $deviceData['device_model'] ?? '';
                $builtInValues['os'] = $deviceData['os'] ?? '';
                $builtInValues['os_version'] = $deviceData['os_version'] ?? '';
                $builtInValues['browser'] = $deviceData['browser'] ?? '';
                $builtInValues['browser_version'] = $deviceData['browser_version'] ?? '';
                
                // Debug logging
                error_log("Device detection result: " . json_encode($deviceData));
                error_log("Built-in values for redirect rules: device=" . $builtInValues['device']);
            } catch (\Exception $e) {
                error_log("Device detection error: " . $e->getMessage());
                // Fallback to desktop if detection fails
                $builtInValues['device'] = 'desktop';
            }
        } else {
            // No user agent - default to desktop
            $builtInValues['device'] = 'desktop';
            error_log("No user agent provided, defaulting device to 'desktop'");
        }

        // Check each rule in order (first match wins)
        foreach ($redirectRules as $ruleIndex => $rule) {
            error_log("--- Checking Rule #" . ($ruleIndex + 1) . " ---");
            error_log("Rule data: " . json_encode($rule, JSON_PRETTY_PRINT));
            
            // Check if rule applies to this execution point
            $executeOn = $rule['execute_on'] ?? [];
            error_log("Rule execute_on: " . json_encode($executeOn) . ", Looking for: " . $executionPoint);
            
            if (!in_array($executionPoint, $executeOn, true)) {
                error_log("Rule #" . ($ruleIndex + 1) . " does not apply to execution point '{$executionPoint}' - skipping");
                continue;
            }
            
            error_log("Rule #" . ($ruleIndex + 1) . " applies to execution point '{$executionPoint}' - evaluating");

            // Get token display name from rule
            $tokenDisplayName = $rule['token_name'] ?? '';
            if (empty($tokenDisplayName)) {
                continue;
            }
            
            // Get token value based on token type
            $tokenValue = '';
            if (isset($tokenMap[$tokenDisplayName])) {
                $tokenInfo = $tokenMap[$tokenDisplayName];
                error_log("Token '{$tokenDisplayName}' found in map. Type: {$tokenInfo['type']}, Info: " . json_encode($tokenInfo));
                
                if ($tokenInfo['type'] === 'custom' || $tokenInfo['type'] === 'traffic_source') {
                    // Get from params using parameter name
                    $paramName = $tokenInfo['param'];
                    // Try exact match first
                    $tokenValue = $params[$paramName] ?? '';
                    
                    // If empty, try common variations
                    if (empty($tokenValue) && !isset($params[$paramName])) {
                        // Try parameter + '_id' suffix (e.g., 'ad' -> 'ad_id', 'placement' -> 'placement_id')
                        $paramNameWithId = $paramName . '_id';
                        if (isset($params[$paramNameWithId])) {
                            $tokenValue = $params[$paramNameWithId];
                            error_log("Token '{$tokenDisplayName}': Tried param '{$paramName}', found value in '{$paramNameWithId}': '{$tokenValue}'");
                        } else {
                            // Try common parameter name mappings
                            $commonMappings = [
                                'campid' => ['campaign_id', 'campaign'],
                                'adgroup' => ['adset_id', 'adset', 'ad_group_id'],
                                'bann' => ['ad_id', 'ad', 'banner_id'],
                                'subid' => ['sub_id', 'sub'],
                                'zoneid' => ['zone_id', 'zone'],
                                'itemid' => ['item_id', 'item'],
                                'prodid' => ['product_id', 'product'],
                                'targetid' => ['target_id', 'target'],
                                'dev' => ['device', 'device_id'],
                                'brw' => ['browser', 'browser_id'],
                                'os' => ['os', 'os_id'],
                                'ctry' => ['country', 'country_id'],
                                'regi' => ['region', 'region_id'],
                                'conn' => ['connection', 'connection_id'],
                                'acti' => ['activity', 'activity_id'],
                                'ztp' => ['zone_type', 'zone_type_id'],
                            ];
                            
                            if (isset($commonMappings[$paramName])) {
                                foreach ($commonMappings[$paramName] as $mappedParam) {
                                    if (isset($params[$mappedParam])) {
                                        $tokenValue = $params[$mappedParam];
                                        error_log("Token '{$tokenDisplayName}': Tried param '{$paramName}', found value in mapped param '{$mappedParam}': '{$tokenValue}'");
                                        break;
                                    }
                                }
                            }
                        }
                    }
                    
                    // Log available params for debugging if still empty
                    if (empty($tokenValue)) {
                        $availableParams = array_keys($params);
                        error_log("Token '{$tokenDisplayName}' is custom/traffic_source. Param: '{$paramName}', Value from params: '(empty)'. Available params: " . implode(', ', $availableParams));
                    } else {
                        error_log("Token '{$tokenDisplayName}' is custom/traffic_source. Param: '{$paramName}', Value from params: '{$tokenValue}'");
                    }
                } elseif ($tokenInfo['type'] === 'builtin') {
                    // Get from enriched built-in values
                    $column = $tokenInfo['column'];
                    error_log("Token '{$tokenDisplayName}' is builtin. Column: '{$column}'");
                    error_log("Built-in values array keys: " . implode(', ', array_keys($builtInValues)));
                    error_log("Built-in values array: " . json_encode($builtInValues));
                    $tokenValue = $builtInValues[$column] ?? '';
                    error_log("Token value retrieved from builtInValues[{$column}]: '" . ($tokenValue ?: '(empty)') . "'");
                }
            } else {
                // Token not found in map - log for debugging
                error_log("Redirect rule: Token '{$tokenDisplayName}' not found in token map. Available tokens: " . implode(', ', array_keys($tokenMap)));
                continue;
            }
            
            // Convert to string and trim (handle null/empty cases)
            $tokenValue = trim((string)$tokenValue);
            $operator = $rule['operator'] ?? '';
            error_log("Token value after trim: '" . ($tokenValue !== '' ? $tokenValue : '(empty)') . "'");

            // Missing URL params must still be evaluated for not_equals (e.g. hidden absent => redirect)
            if ($tokenValue === '' && $operator !== 'not_equals') {
                error_log("Redirect rule: Token '{$tokenDisplayName}' has empty value. Rule skipped. TokenInfo: " . json_encode($tokenInfo ?? []));
                continue;
            }

            // Get rule values
            $ruleValue = $rule['value'] ?? '';
            $caseSensitive = !empty($rule['case_sensitive']);

            // Normalize for case-insensitive comparison
            if (!$caseSensitive) {
                $tokenValue = mb_strtolower($tokenValue);
                $ruleValue = mb_strtolower($ruleValue);
            }

            // Evaluate condition
            $matches = false;
            switch ($operator) {
                case 'equals':
                    $matches = ($tokenValue === $ruleValue);
                    break;
                case 'not_equals':
                    $matches = ($tokenValue !== $ruleValue);
                    break;
                case 'contains':
                    $matches = (strpos($tokenValue, $ruleValue) !== false);
                    break;
                case 'starts_with':
                    $matches = (strpos($tokenValue, $ruleValue) === 0);
                    break;
                case 'ends_with':
                    $matches = (substr($tokenValue, -strlen($ruleValue)) === $ruleValue);
                    break;
            }
            
            // Debug logging for rule evaluation
            error_log(sprintf(
                "Redirect rule check: Token='%s', Value='%s', Operator='%s', RuleValue='%s', CaseSensitive=%s, Matches=%s",
                $tokenDisplayName,
                $tokenValue,
                $operator,
                $ruleValue,
                $caseSensitive ? 'yes' : 'no',
                $matches ? 'yes' : 'no'
            ));

            // First matching rule triggers redirect
            if ($matches && !empty($rule['redirect_url'])) {
                error_log("Redirect rule MATCHED: Redirecting to " . $rule['redirect_url']);
                return $rule['redirect_url'];
            }
        }

        return null;
    }

    /**
     * Determine if destination is an offer (DTO flow or Split direct path)
     * Returns true if this click should count as a "click" (lp_click = 1)
     */
    private function isDirectToOffer(array $campaign, string $destination): bool
    {
        $flowType = $campaign['flow_type'] ?? '';
        $rotation = $campaign['rotation_json'] ?? [];
        
        // DTO flows always go directly to offer
        if ($flowType === 'DTO') {
            return true;
        }
        
        // Split flows: check if going to direct path (offers)
        if ($flowType === 'Split' && isset($rotation['split_traffic'])) {
            // Check if destination matches an offer in the direct_path
            $directOffers = $rotation['direct_path']['offers'] ?? [];
            foreach ($directOffers as $offer) {
                $stmt = $this->db->prepare("SELECT url FROM offers WHERE id = ?");
                $stmt->bind_param('i', $offer['id']);
                $stmt->execute();
                $result = $stmt->get_result();
                $offerUrl = $result->fetch_assoc()['url'] ?? null;
                
                // Compare base URLs (ignore query params)
                if ($offerUrl) {
                    $offerHost = parse_url($offerUrl, PHP_URL_HOST);
                    $offerPath = parse_url($offerUrl, PHP_URL_PATH);
                    $destHost = parse_url($destination, PHP_URL_HOST);
                    $destPath = parse_url($destination, PHP_URL_PATH);
                    
                    if ($offerHost === $destHost && $offerPath === $destPath) {
                        return true;
                    }
                }
            }
        }
        
        // LP flows go to landing page first, so lp_click will be set later when they click through
        return false;
    }

    /**
     * Store click in database
     */
    private function storeClick(int $campaignId, string $clickId, array $params, ?array $cost, array $campaign = [], bool $isDirectToOffer = false, ?int $offerId = null, ?int $landingPageId = null, ?int $trafficSourceId = null, ?int $slugId = null, bool $isRedirectRuleMatch = false): void
        {
            // Get real IP address
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            
            // Validate IP before processing
            if ($ip && !filter_var($ip, FILTER_VALIDATE_IP)) {
                error_log("Redirector: Invalid IP address format: {$ip}");
                $ip = null; // Set to null if invalid
            }
            
            // Apply IP anonymization if enabled (but not for localhost)
            $ipAnonymizationEnabled = ($this->settings->get('ip_anonymization', '0') === '1');
            if ($ipAnonymizationEnabled && $ip) {
                // Don't anonymize localhost addresses
                if ($ip !== '::1' && $ip !== '127.0.0.1' && strpos($ip, '::') !== 0) {
                    $anonymizedIp = $this->anonymizeIp($ip);
                    // Only use anonymized IP if it's valid
                    if ($anonymizedIp && $anonymizedIp !== '::' && filter_var($anonymizedIp, FILTER_VALIDATE_IP)) {
                        $ip = $anonymizedIp;
                    }
                }
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

            // Only do geolocation lookup if we have a valid, non-localhost IP
            if ($ip && $ip !== '::1' && $ip !== '127.0.0.1' && $ip !== '::') {
                try {
                    $geoLocator = new \SimpleKuma\Enrichment\GeoLocator($ip);
                    $geoData = $geoLocator->getGeoData();
                } catch (\Exception $e) {
                    error_log("Redirector: GeoLocator error for IP {$ip}: " . $e->getMessage());
                }
            } else {
                error_log("Redirector: Skipping geolocation - IP is localhost or invalid: " . ($ip ?? 'NULL'));
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
                'redirect_rule_matched' => $isRedirectRuleMatch,
                'cookies' => [] // Store Facebook cookies for CAPI postbacks
            ];
            
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
                    $name = $token['name'] ?? $parameter; // Use name for display in stats
                    
                    if (!empty($parameter) && isset($params[$parameter])) {
                        $extraData['custom_tokens'][$parameter] = [
                            'name' => $name, // Display name for stats view
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
                // Skip Tf parameter (internal use only)
                if ($key === 'Tf') {
                    continue;
                }
                
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

            // Set lp_click and ts_lp for DTO flows (they count as "clicked")
            $lpClick = $isDirectToOffer ? 1 : 0;
            $lpClickTime = $isDirectToOffer ? 'NOW()' : 'NULL';

            // Check if traffic_source_id column exists
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

            // Build INSERT statement based on column existence
            if ($trafficSourceColumnExists) {
                $sql = "INSERT INTO clicks
                    (campaign_id, slug_id, traffic_source_id, offer_id, landing_page_id, click_id, ts, ip, ua, referrer, " . $geoCols . ",
                     device, device_brand, device_model, os, os_version, browser, browser_version, cost, cost_currency, extra_json" . $statsFlagColumn . ", ts_hour, lp_click, ts_lp)
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, " . $geoPlaceholders . ", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?" . $statsFlagValue . ", DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00'), ?, " . ($isDirectToOffer ? "NOW()" : "NULL") . ")";
                $paramTypes = 'iiiiissss' . $geoParamTypes . 'sssssssdss' . $statsFlagType . 'i';
            } else {
                $sql = "INSERT INTO clicks
                    (campaign_id, slug_id, offer_id, landing_page_id, click_id, ts, ip, ua, referrer, " . $geoCols . ",
                     device, device_brand, device_model, os, os_version, browser, browser_version, cost, cost_currency, extra_json" . $statsFlagColumn . ", ts_hour, lp_click, ts_lp)
                    VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, " . $geoPlaceholders . ", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?" . $statsFlagValue . ", DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00'), ?, " . ($isDirectToOffer ? "NOW()" : "NULL") . ")";
                $paramTypes = 'iiiissss' . $geoParamTypes . 'sssssssdss' . $statsFlagType . 'i';
            }

            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                error_log("Redirector: Failed to prepare INSERT statement: " . $this->db->error);
                return;
            }

            $costValue = $cost['cost'] ?? null;
            $costCurrency = $cost['currency'] ?? null;
            $extraJson = json_encode($extraData);

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

            $stmt->execute();

            // On-write: UPSERT clicks_daily_summary and token aggregates (no cron)
            $updater = new DailySummaryUpdater($this->db);
            $updater->upsertClick(
                $campaignId,
                $trafficSourceId,
                $offerId,
                $landingPageId,
                $lpClick,
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
                $lpClick,
                $costValue !== null ? (float) $costValue : null,
                0,
                0.0,
                $ua,
                $ip
            );

            // Optional Meta PageView (non-blocking; failures never affect redirect)
            $this->maybeQueueMetaPageView($campaignId, $clickId, $ip, $ua, $extraData);
        }

    /**
     * Fire Meta CAPI PageView after click insert when integration enables it.
     * Prefer after response commit so redirect latency is unaffected.
     */
    private function maybeQueueMetaPageView(int $campaignId, string $clickId, string $ip, string $ua, array $extraData): void
    {
        try {
            $sender = new MetaCapiPageViewSender($this->db);
            $fbc = null;
            $fbp = null;
            if (!empty($extraData['all_params']['_fbc'])) {
                $fbc = (string)$extraData['all_params']['_fbc'];
            } elseif (!empty($extraData['traffic_source_tokens']['_fbc'])) {
                $fbc = (string)$extraData['traffic_source_tokens']['_fbc'];
            }
            if (!empty($extraData['all_params']['_fbp'])) {
                $fbp = (string)$extraData['all_params']['_fbp'];
            } elseif (!empty($extraData['traffic_source_tokens']['_fbp'])) {
                $fbp = (string)$extraData['traffic_source_tokens']['_fbp'];
            }

            // Defer until after response when possible (redirect already in flight / about to send)
            if (function_exists('fastcgi_finish_request')) {
                // Caller may still be building redirect; register shutdown so we never block Location header
                register_shutdown_function(static function () use ($sender, $campaignId, $clickId, $ip, $ua, $fbc, $fbp): void {
                    $sender->maybeSendForClick($campaignId, $clickId, [
                        'ip' => $ip,
                        'ua' => $ua,
                        'fbc' => $fbc,
                        'fbp' => $fbp,
                    ]);
                });
                return;
            }

            register_shutdown_function(static function () use ($sender, $campaignId, $clickId, $ip, $ua, $fbc, $fbp): void {
                $sender->maybeSendForClick($campaignId, $clickId, [
                    'ip' => $ip,
                    'ua' => $ua,
                    'fbc' => $fbc,
                    'fbp' => $fbp,
                ]);
            });
        } catch (\Throwable $e) {
            error_log('Redirector::maybeQueueMetaPageView: ' . $e->getMessage());
        }
    }

    /**
     * Append parameters to destination URL
     * Only passes parameters that are explicitly marked to pass to LP/Offer
     */
    private function appendParameters(string $url, array $params, string $clickId, array $campaign, bool $isToOffer = false, ?array $trafficSourceConfig = null): string
    {
        // Build list of allowed parameters
        $allowedParams = [];
        
        // Always include click_id for tracking
        $allowedParams['click_id'] = $clickId;

        // Get traffic source tokens to check pass_to_lp/pass_to_offer settings
        $trafficSourceTokens = [];
        
        if ($trafficSourceConfig && !empty($trafficSourceConfig['tokens_json'])) {
            $trafficSourceTokens = $trafficSourceConfig['tokens_json'];
        }

        // Add traffic source tokens that are marked to pass
        foreach ($trafficSourceTokens as $token) {
            $paramName = $token['parameter'] ?? '';
            $passToLp = !empty($token['pass_to_lp']);
            $passToOffer = !empty($token['pass_to_offer']);
            
            // Check if this token should be passed based on destination
            if ($paramName && isset($params[$paramName])) {
                if ($isToOffer && $passToOffer) {
                    $allowedParams[$paramName] = $params[$paramName];
                } elseif (!$isToOffer && $passToLp) {
                    $allowedParams[$paramName] = $params[$paramName];
                }
            }
        }

        // Get campaign custom tokens to check pass_to_lp/pass_to_offer settings
        $customTokens = $campaign['custom_tokens_json'] ?? [];
        if (!is_array($customTokens)) {
            $customTokens = [];
        }

        // Add custom tokens that are marked to pass
        foreach ($customTokens as $token) {
            $paramName = $token['parameter'] ?? '';
            $passToLp = !empty($token['pass_to_lp']);
            $passToOffer = !empty($token['pass_to_offer']);
            
            // Check if this token should be passed based on destination
            if ($paramName && isset($params[$paramName])) {
                if ($isToOffer && $passToOffer) {
                    $allowedParams[$paramName] = $params[$paramName];
                } elseif (!$isToOffer && $passToLp) {
                    $allowedParams[$paramName] = $params[$paramName];
                }
            }
        }

        // Build query string only with allowed parameters
        if (empty($allowedParams)) {
            return $url; // No parameters to add
        }

        $queryString = http_build_query($allowedParams);

        // Check if URL already has query string
        $separator = strpos($url, '?') !== false ? '&' : '?';

        return $url . $separator . $queryString;
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
            // Handle special cases first
            if ($ip === '::1' || $ip === '::') {
                // Localhost - return as-is (don't anonymize localhost)
                return $ip;
            }
            
            // Expand IPv6 to full format for processing
            $expanded = explode('::', $ip);
            
            if (count($expanded) === 2) {
                // Compressed format (e.g., 2001:db8::1)
                $left = $expanded[0];
                $right = $expanded[1];
                
                // Split left and right parts
                $leftParts = $left ? explode(':', $left) : [];
                $rightParts = $right ? explode(':', $right) : [];
                
                // Calculate how many parts we need to keep (first 4 hextets = 64 bits)
                $totalParts = count($leftParts) + count($rightParts);
                $partsToKeep = min(4, count($leftParts));
                
                if ($partsToKeep > 0) {
                    $keptParts = array_slice($leftParts, 0, $partsToKeep);
                    return implode(':', $keptParts) . '::';
                } else {
                    // If no left parts, keep first part of right side
                    if (count($rightParts) > 0) {
                        return $rightParts[0] . '::';
                    }
                    // Fallback: return as-is if we can't process
                    return $ip;
                }
            } else {
                // Full format IPv6 (e.g., 2001:0db8:0000:0000:0000:0000:0000:0001)
                $parts = explode(':', $ip);
                if (count($parts) >= 4) {
                    // Keep first 4 hextets (64 bits), mask the rest
                    return implode(':', array_slice($parts, 0, 4)) . '::';
                }
            }
        }
        
        return $ip; // Return as-is if unable to anonymize
    }

    /**
     * Perform redirect; $referrerMode should be null unless this is an outbound-offer hop.
     */
    private function redirect(string $url, ?string $referrerMode): void
    {
        KumaHopRedirect::redirect($url, $referrerMode);
    }

    private function referrerModeFromCampaign(array $campaign): ?string
    {
        $mode = $campaign['referrer_mode'] ?? $campaign['cloaking_mode'] ?? '';

        return $mode !== '' ? (string) $mode : null;
    }

    /**
     * Show friendly "no offers available" page when all offers are outside time window
     * This prevents losing clicks to dead-end errors
     */
    private function showNoOffersAvailablePage(array $campaign): void
    {
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: text/html; charset=utf-8');
        }
        
        $campaignName = htmlspecialchars($campaign['name'] ?? 'Campaign', ENT_QUOTES, 'UTF-8');
        
        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Offer Currently Unavailable</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: #333;
        }
        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            max-width: 500px;
            width: 100%;
            padding: 40px;
            text-align: center;
        }
        .icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        h1 {
            font-size: 24px;
            margin-bottom: 16px;
            color: #333;
        }
        p {
            font-size: 16px;
            line-height: 1.6;
            color: #666;
            margin-bottom: 24px;
        }
        .info {
            background: #f8f9fa;
            border-left: 4px solid #667eea;
            padding: 16px;
            border-radius: 4px;
            text-align: left;
            font-size: 14px;
            color: #555;
            margin-top: 24px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">⏰</div>
        <h1>Offer Currently Unavailable</h1>
        <p>We're sorry, but the offers for <strong>{$campaignName}</strong> are currently outside their scheduled time window.</p>
        <p>Please check back later when the offers become available.</p>
        <div class="info">
            <strong>Note:</strong> Your click has been recorded for tracking purposes. This helps us optimize campaign performance.
        </div>
    </div>
</body>
</html>
HTML;
        exit;
    }
}

