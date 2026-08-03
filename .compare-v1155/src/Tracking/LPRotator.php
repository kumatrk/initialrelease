<?php

declare(strict_types=1);

namespace SimpleKuma\Tracking;

use mysqli;

/**
 * LP Rotator
 * Handles Landing Page → Offer clicks with rotation
 */
class LPRotator
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Process LP click and redirect to offer
     */
    public function process(string $clickId): void
    {
        // Get click and campaign data
        $click = $this->getClick($clickId);

        if (!$click) {
            http_response_code(404);
            die('Click not found');
        }

        // Get campaign
        $campaign = $this->getCampaign($click['campaign_id']);

        if (!$campaign || $campaign['status'] !== 'active') {
            http_response_code(403);
            die('Campaign not active');
        }

        // Mark LP click in database (idempotent) — only update pre-agg on first successful mark
        $firstLpClick = $this->markLPClick($clickId);

        $updater = new DailySummaryUpdater($this->db);
        if ($firstLpClick) {
            // Update clicks_daily_summary: lp_clicks on landing page row (once per click)
            $updater->upsertLpClickUpdate($clickId);
        }

        // Get original click params for rule checking and parameter passing
        $extraJson = json_decode($click['extra_json'] ?? '{}', true) ?? [];
        $clickParams = $extraJson['all_params'] ?? []; // Extract original params from extra_json

        // Check rule-based redirects (on offer click)
        $ruleRedirect = $this->checkRedirectRules($campaign, $clickParams, 'offer_click');
        if ($ruleRedirect) {
            // Update click record to mark redirect rule match
            $this->markRedirectRuleMatch($clickId);
            // Outbound offer click: apply campaign cloaking
            $this->redirect($ruleRedirect, KumaHopRedirect::modeForOutboundOffer(true, $this->referrerModeFromCampaign($campaign)));
            return;
        }

        // Resolve offer based on campaign rotation (returns array with url and offer_id)
        $offerData = $this->resolveOffer($campaign);

        if (!$offerData || !isset($offerData['url'])) {
            // No offers available (all outside time window or disabled)
            // Still update click record, then show friendly message
            error_log("LPRotator: No available offers for campaign ID {$campaign['id']}. All offers are outside time window or disabled.");
            $this->showNoOffersAvailablePage($campaign);
            return;
        }

        $offerUrl = $offerData['url'];
        $offerId = $offerData['offer_id'] ?? null;

        // Update click record with offer_id and clicks_daily_summary offer row (first CTA only)
        if ($offerId && $firstLpClick) {
            $this->updateClickOfferId($clickId, $offerId);
            $updater->upsertOfferUpdate($clickId, $offerId);
        } elseif ($offerId) {
            // Retry: still ensure offer_id is set if missing, but do not bump summary again
            $this->updateClickOfferId($clickId, $offerId);
        }

        // Check if URL contains tokens - if so, use ClickTokenReplacer; otherwise append parameters
        if ($this->urlContainsTokens($offerUrl)) {
            // Use ClickTokenReplacer for URLs with tokens
            $clickTokenReplacer = new \SimpleKuma\Tracking\ClickTokenReplacer();
            
            // Get traffic source tokens for context
            $trafficSourceId = $campaign['traffic_source_id'] ?? null;
            $trafficSourceTokens = [];
            if ($trafficSourceId) {
                $stmt = $this->db->prepare("SELECT tokens_json FROM traffic_sources WHERE id = ?");
                $stmt->bind_param('i', $trafficSourceId);
                $stmt->execute();
                $result = $stmt->get_result();
                $ts = $result->fetch_assoc();
                if ($ts && !empty($ts['tokens_json'])) {
                    $trafficSourceTokens = json_decode($ts['tokens_json'], true) ?? [];
                }
                $stmt->close();
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
                'click' => $click,
                'campaign' => array_merge($campaign, ['traffic_source_tokens' => $trafficSourceTokens]),
                'all_traffic_sources' => $allTrafficSources,
            ];
            
            $offerUrl = $clickTokenReplacer->replace($offerUrl, $context);
        } else {
            // Append parameters to offer URL (filtered by pass_to_offer settings)
            $offerUrl = $this->appendParameters($offerUrl, $clickParams, $clickId, $campaign, true);
        }

        // LP → offer: always an outbound offer hop
        $this->redirect($offerUrl, KumaHopRedirect::modeForOutboundOffer(true, $this->referrerModeFromCampaign($campaign)));
    }

    /**
     * Check rule-based redirects (same logic as Redirector)
     */
    private function checkRedirectRules(array $campaign, array $params, string $executionPoint): ?string
    {
        $redirectRules = $campaign['redirect_rules_json'] ?? [];
        if (empty($redirectRules) || !is_array($redirectRules)) {
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
                        $key = $displayName;
                        if (isset($tokenMap[$key]) && $tokenMap[$key]['type'] === 'traffic_source') {
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
                        $tokenMap[$displayName] = ['type' => 'traffic_source', 'param' => $paramName];
                    }
                }
            }
        }
        
        // Get click record to access built-in token values
        $clickId = $params['click_id'] ?? null;
        $builtInValues = [];
        if ($clickId) {
            $click = $this->getClick($clickId);
            if ($click) {
                $builtInValues['country'] = $click['country'] ?? '';
                $builtInValues['region'] = $click['region'] ?? '';
                $builtInValues['city'] = $click['city'] ?? '';
                $builtInValues['device'] = $click['device'] ?? '';
                $builtInValues['device_brand'] = $click['device_brand'] ?? '';
                $builtInValues['device_model'] = $click['device_model'] ?? '';
                $builtInValues['os'] = $click['os'] ?? '';
                $builtInValues['os_version'] = $click['os_version'] ?? '';
                $builtInValues['browser'] = $click['browser'] ?? '';
                $builtInValues['browser_version'] = $click['browser_version'] ?? '';
                $builtInValues['ip'] = $click['ip'] ?? '';
            }
        }

        foreach ($redirectRules as $rule) {
            if (!in_array($executionPoint, $rule['execute_on'] ?? [], true)) {
                continue;
            }

            // Get token display name from rule
            $tokenDisplayName = $rule['token_name'] ?? '';
            if (empty($tokenDisplayName)) {
                continue;
            }
            
            // Get token value based on token type
            $tokenValue = '';
            if (isset($tokenMap[$tokenDisplayName])) {
                $tokenInfo = $tokenMap[$tokenDisplayName];
                
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
                                        break;
                                    }
                                }
                            }
                        }
                    }
                } elseif ($tokenInfo['type'] === 'builtin') {
                    // Get from click record
                    $column = $tokenInfo['column'];
                    $tokenValue = $builtInValues[$column] ?? '';
                }
            }
            
            $tokenValue = trim((string)$tokenValue);
            $operator = $rule['operator'] ?? '';

            // Missing URL params must still be evaluated for not_equals (e.g. hidden absent => redirect)
            if ($tokenValue === '' && $operator !== 'not_equals') {
                continue;
            }

            $ruleValue = $rule['value'] ?? '';
            $caseSensitive = !empty($rule['case_sensitive']);

            if (!$caseSensitive) {
                $tokenValue = mb_strtolower($tokenValue);
                $ruleValue = mb_strtolower($ruleValue);
            }

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

            if ($matches && !empty($rule['redirect_url'])) {
                return $rule['redirect_url'];
            }
        }

        return null;
    }

    /**
     * Get click record
     */
    private function getClick(string $clickId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM clicks WHERE click_id = ?"
        );
        $stmt->bind_param('s', $clickId);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc() ?: null;
    }

    /**
     * Get campaign
     */
    private function getCampaign(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM campaigns WHERE id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $campaign = $result->fetch_assoc();

        if ($campaign) {
            $campaign['rotation_json'] = json_decode($campaign['rotation_json'], true) ?? [];
            $campaign['custom_tokens_json'] = json_decode($campaign['custom_tokens_json'], true) ?? [];
            $campaign['redirect_rules_json'] = json_decode($campaign['redirect_rules_json'], true) ?? [];
        }

        return $campaign ?: null;
    }

    /**
     * Mark LP click. Returns true when this call was the first mark (pre-agg should increment).
     */
    private function markLPClick(string $clickId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE clicks 
            SET lp_click = TRUE, ts_lp = NOW() 
            WHERE click_id = ? AND lp_click = FALSE"
        );
        $stmt->bind_param('s', $clickId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        return $affected > 0;
    }

    /**
     * Resolve offer from campaign rotation
     */
    /**
     * Resolve offer based on campaign rotation
     * Returns array with 'url' and 'offer_id' keys
     */
    private function resolveOffer(array $campaign): ?array
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

        // For LP flow, offers are nested under 'offers' key
        if (isset($rotation['offers']) && is_array($rotation['offers'])) {
            $offers = $filterEnabled($rotation['offers']);
        } elseif (isset($rotation['landing_pages'])) {
            // New structure with landing_pages array - offers are separate
            $offers = $filterEnabled($rotation['offers'] ?? []);
        } elseif (isset($rotation['lp_path']['offers'])) {
            // Split flow: offers for LP path
            $offers = $filterEnabled($rotation['lp_path']['offers']);
        } else {
            // For DTO or Split flow (shouldn't hit this, but fallback)
            $offers = $filterEnabled(is_array($rotation) ? $rotation : []);
        }

        if (empty($offers)) {
            return null;
        }

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

        if (empty($availableOffers)) {
            $fallback = $this->tryFallbackOffer($campaign);
            if ($fallback) {
                return $fallback;
            }
            return null;
        }

        $availableOffers = array_values($availableOffers); // Re-index after filtering

        // Single offer - just return it
        if (count($availableOffers) === 1) {
            return $this->getOfferData($availableOffers[0]['id']);
        }

        // Weighted rotation
        $totalWeight = array_sum(array_column($availableOffers, 'weight'));
        if ($totalWeight <= 0) {
            return null;
        }
        
        $random = mt_rand(1, $totalWeight);
        $currentWeight = 0;

        foreach ($availableOffers as $offer) {
            $currentWeight += $offer['weight'];
            if ($random <= $currentWeight) {
                return $this->getOfferData($offer['id']);
            }
        }

        // Fallback to first offer
        return $this->getOfferData($availableOffers[0]['id']);
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

        return $this->getOfferData($fallbackId);
    }

    /**
     * Get offer data (URL and ID) from database
     */
    private function getOfferData(int $offerId): ?array
    {
        $stmt = $this->db->prepare("SELECT url FROM offers WHERE id = ?");
        $stmt->bind_param('i', $offerId);
        $stmt->execute();
        $result = $stmt->get_result();
        $offer = $result->fetch_assoc();
        if (!$offer || !isset($offer['url'])) {
            return null;
        }
        return [
            'url' => $offer['url'],
            'offer_id' => $offerId
        ];
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
     * Update click record with offer_id
     */
    private function updateClickOfferId(string $clickId, int $offerId): void
    {
        $stmt = $this->db->prepare("UPDATE clicks SET offer_id = ? WHERE click_id = ?");
        $stmt->bind_param('is', $offerId, $clickId);
        $stmt->execute();
    }

    /**
     * Mark click record as redirect rule match
     */
    private function markRedirectRuleMatch(string $clickId): void
    {
        // Get current extra_json
        $stmt = $this->db->prepare("SELECT extra_json FROM clicks WHERE click_id = ?");
        $stmt->bind_param('s', $clickId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        if ($row) {
            $extraJson = json_decode($row['extra_json'] ?? '{}', true) ?? [];
            $extraJson['redirect_rule_matched'] = true;
            
            // Update with new extra_json
            $updatedJson = json_encode($extraJson);
            $updateStmt = $this->db->prepare("UPDATE clicks SET extra_json = ? WHERE click_id = ?");
            $updateStmt->bind_param('ss', $updatedJson, $clickId);
            $updateStmt->execute();
        }
    }

    /**
     * Append parameters to destination URL
     * Only passes parameters that are explicitly marked to pass to LP/Offer
     */
    private function appendParameters(string $url, array $params, string $clickId, array $campaign, bool $isToOffer = false): string
    {
        // Build list of allowed parameters
        $allowedParams = [];
        
        // Always include click_id for tracking
        $allowedParams['click_id'] = $clickId;

        // Get traffic source tokens to check pass_to_lp/pass_to_offer settings
        $trafficSourceId = $campaign['traffic_source_id'] ?? null;
        $trafficSourceTokens = [];
        
        if ($trafficSourceId) {
            $stmt = $this->db->prepare("SELECT tokens_json FROM traffic_sources WHERE id = ?");
            $stmt->bind_param('i', $trafficSourceId);
            $stmt->execute();
            $result = $stmt->get_result();
            $ts = $result->fetch_assoc();
            
            if ($ts && !empty($ts['tokens_json'])) {
                $trafficSourceTokens = json_decode($ts['tokens_json'], true) ?? [];
            }
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
        if (count($allowedParams) === 1 && isset($allowedParams['click_id'])) {
            // Only click_id, no other params
            $separator = strpos($url, '?') !== false ? '&' : '?';
            return $url . $separator . 'click_id=' . urlencode($clickId);
        }

        if (empty($allowedParams)) {
            return $url; // No parameters to add
        }

        $queryString = http_build_query($allowedParams);

        // Check if URL already has query string
        $separator = strpos($url, '?') !== false ? '&' : '?';

        return $url . $separator . $queryString;
    }

    /**
     * Perform redirect with referrer privacy (see KumaHopRedirect).
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

