<?php
// Campaigns CRUD Page
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Utils/Formatter.php';
use SimpleKuma\Utils\Formatter;

// Mobile detection function
function isMobileDevice(): bool {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ua = strtolower($userAgent);
    
    $mobilePatterns = [
        'mobile', 'android', 'iphone', 'ipod', 'ipad', 'blackberry',
        'windows phone', 'opera mini', 'palm', 'smartphone', 'tablet'
    ];
    
    foreach ($mobilePatterns as $pattern) {
        if (strpos($ua, $pattern) !== false) {
            return true;
        }
    }
    return false;
}
$isMobile = isMobileDevice();

// Get permission instance
$permission = $GLOBALS['permission'] ?? null;
use SimpleKuma\Auth\Auth;
use SimpleKuma\Auth\Csrf;
use SimpleKuma\Auth\Permission;
use SimpleKuma\Release\TrafficSourceReleaseHelper;

// Check if user has no roles (fallback for legacy installations outside production only)
$hasNoRoles = empty($_SESSION['role_ids'] ?? []) && Auth::allowsLegacyNoRolesFallback();

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$userTimezone = $GLOBALS['userTimezone'] ?? 'UTC';
$userCurrency = $GLOBALS['userCurrency'] ?? 'USD';
$campaign = new \SimpleKuma\Entity\Campaign($db);
$trafficSource = new \SimpleKuma\Entity\TrafficSource($db);
$offer = new \SimpleKuma\Entity\Offer($db);
$landingPage = new \SimpleKuma\Entity\LandingPage($db);
$campaignGroup = new \SimpleKuma\Entity\CampaignGroup($db);
$facebookCapi = new \SimpleKuma\Entity\FacebookCapiIntegration($db);
$facebookMarketing = new \SimpleKuma\Entity\FacebookMarketingIntegration($db);
$googleAds = new \SimpleKuma\Entity\GoogleAdsIntegration($db);
$customPostback = new \SimpleKuma\Entity\CustomPostback($db);
$trackingDomain = new \SimpleKuma\Entity\TrackingDomain($db);

// Get action and id from both GET and POST (POST takes precedence if form submitted)
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';
$id = isset($_POST['id']) ? (int)$_POST['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : null);
$errors = [];
$success = '';

// Handle clone action (POST + CSRF + create permission only — no GET clone)
if ($action === 'clone' && $id && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate()) {
        $errors['general'] = Csrf::invalidRequestMessage();
        $action = 'list';
    } elseif ($permission && !$permission->hasPermission(Permission::PERM_CAMPAIGN_CREATE) && !$hasNoRoles) {
        $errors['general'] = 'You do not have permission to clone campaigns';
        $action = 'list';
    } else {
    // Clone campaign
    $originalCampaign = $campaign->getById($id);
    if ($originalCampaign) {
        // Ensure rotation_json is properly preserved
        // getById already decodes it, so we have an array
        $rotation = $originalCampaign['rotation_json'] ?? [];
        
        // Ensure rotation is properly structured based on flow type
        if (!is_array($rotation)) {
    $rotation = [];
        }
        
        // Debug: Log rotation structure BEFORE clone
        error_log('=== CLONING CAMPAIGN ===');
        error_log('Original Campaign ID: ' . $id);
        error_log('Flow Type: ' . ($originalCampaign['flow_type'] ?? 'unknown'));
        error_log('Rotation Type: ' . gettype($rotation));
        error_log('Rotation Is Empty: ' . (empty($rotation) ? 'YES' : 'NO'));
        error_log('Rotation JSON: ' . json_encode($rotation, JSON_PRETTY_PRINT));
        error_log('Rotation Array: ' . print_r($rotation, true));
        
        $cloneData = [
            'name' => $originalCampaign['name'] . ' (Copy)',
            'campaign_group_id' => $originalCampaign['campaign_group_id'],
            'traffic_source_id' => $originalCampaign['traffic_source_id'],
            'flow_type' => $originalCampaign['flow_type'],
            'rotation' => $rotation, // This should preserve the full structure
            'tracking_domain_id' => $originalCampaign['tracking_domain_id'],
            'referrer_mode' => $originalCampaign['referrer_mode'] ?? $originalCampaign['cloaking_mode'] ?? '',
            'redirectless_tracking' => !empty($originalCampaign['redirectless_tracking']),
            'edge_enabled' => !empty($originalCampaign['edge_enabled']),
            'pass_through' => $originalCampaign['pass_through_json'] ?? [],
            'facebook_capi_integration_id' => $originalCampaign['facebook_capi_integration_id'],
            'facebook_marketing_integration_id' => $originalCampaign['facebook_marketing_integration_id'] ?? null,
            'facebook_marketing_ad_account_id' => $originalCampaign['facebook_marketing_ad_account_id'] ?? null,
            'facebook_marketing_campaign_id' => $originalCampaign['facebook_marketing_campaign_id'] ?? null,
            'google_ads_integration_id' => $originalCampaign['google_ads_integration_id'],
            'status' => 'paused', // Clone as paused by default
            'default_cpc' => $originalCampaign['default_cpc'],
            'min_postback_payout' => $originalCampaign['min_postback_payout'] ?? null,
            'allow_multiple_conversions' => !empty($originalCampaign['allow_multiple_conversions']),
            'fallback_offer_id' => $originalCampaign['fallback_offer_id'] ?? null,
            'custom_tokens' => $originalCampaign['custom_tokens_json'] ?? [],
            'redirect_rules' => $originalCampaign['redirect_rules_json'] ?? []
        ];
        
        // Debug: Log what we're about to save
        error_log('=== CLONE DATA ===');
        error_log('Clone Data Rotation Type: ' . gettype($cloneData['rotation']));
        error_log('Clone Data Rotation Is Empty: ' . (empty($cloneData['rotation']) ? 'YES' : 'NO'));
        error_log('Clone Data Rotation JSON: ' . json_encode($cloneData['rotation'], JSON_PRETTY_PRINT));
        error_log('Clone Data Rotation Array: ' . print_r($cloneData['rotation'], true));
        
        // Validate the cloned data
        $validationErrors = $campaign->validate($cloneData);
        
        if (!empty($validationErrors)) {
            error_log('=== CLONE VALIDATION ERRORS ===');
            error_log(print_r($validationErrors, true));
            // If validation fails, show errors but don't create
            foreach ($validationErrors as $field => $error) {
                $errors[$field] = $error;
            }
        } else {
            // Validation passed, create the clone
            error_log('Validation passed, creating clone...');
            $newId = $campaign->create($cloneData);
            if ($newId > 0) {
                // Copy custom postback selections
                $originalPostbacks = $customPostback->getForCampaign($id);
                $originalPostbackIds = array_column($originalPostbacks, 'id');
                if (!empty($originalPostbackIds)) {
                    $customPostback->setForCampaign($newId, $originalPostbackIds);
                }
                
                error_log('Clone created successfully with ID: ' . $newId);
                // Redirect to prevent form re-submission
                header('Location: ?page=campaigns&success=cloned');
                exit;
            } else {
                error_log('Failed to create clone');
                $errors['general'] = 'Failed to clone campaign';
            }
        }
    } else {
        $errors['general'] = 'Campaign not found';
    }
    } // end clone CSRF/permission else
}

// Handle success message from redirect
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'cloned') {
        $success = 'Campaign cloned successfully';
        $action = 'list';
    } elseif ($_GET['success'] === 'updated') {
        $success = 'Campaign updated successfully';
        // Keep action as 'edit' if we're on the edit page
        if ($action === 'edit' && $id) {
            // Already on edit page, just show success message
        } else {
            $action = 'list';
        }
    } elseif ($_GET['success'] === 'conversions_reset') {
        $count = isset($_GET['count']) ? (int)$_GET['count'] : 0;
        $success = "Successfully reset {$count} conversion(s) for the campaign";
        $action = 'list';
    } elseif ($_GET['success'] === 'clicks_reset') {
        $count = isset($_GET['count']) ? (int)$_GET['count'] : 0;
        $success = "Successfully reset {$count} click(s) for the campaign";
        $action = 'list';
    }
}

// Handle form submissions
Csrf::ensureToken();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Clone already validated CSRF above
    if ($action === 'clone') {
        // no-op: handled in clone block
    } elseif (!Csrf::validate()) {
        $errors['general'] = Csrf::invalidRequestMessage();
        $action = 'list';
    } else {
    // Debug: Log POST data
    error_log('POST request received. POST data: ' . print_r($_POST, true));
    error_log('GET data: ' . print_r($_GET, true));
    
    // Check if this is a campaign save/update submission
    if (isset($_POST['save_campaign'])) {
        // Action and ID should come from hidden fields or URL
        $action = $_POST['action'] ?? $_GET['action'] ?? 'add';
        $id = isset($_POST['id']) ? (int)$_POST['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : null);
        error_log('Save campaign POST - Action: ' . $action . ', ID: ' . ($id ?? 'null'));
    }
    
    if ($action === 'delete') {
        if (!$permission || !$permission->hasPermission(Permission::PERM_CAMPAIGN_DELETE)) {
            $errors['general'] = 'You do not have permission to delete campaigns';
            $action = 'list';
        } elseif ($campaign->delete($id)) {
            $success = 'Campaign deleted successfully';
            $action = 'list';
        } else {
            $errors['general'] = 'Failed to delete campaign';
        }
    } elseif ($action === 'reset_campaign_conversions') {
        $campaignId = (int)($_POST['campaign_id'] ?? 0);
        
        if (!$permission || !$permission->hasPermission(Permission::PERM_CAMPAIGN_EDIT)) {
            $errors['general'] = 'You do not have permission to reset conversions';
            $action = 'list';
        } elseif ($campaignId <= 0) {
            $errors['general'] = 'Invalid campaign ID';
            $action = 'list';
        } else {
            // Delete conversions for clicks belonging to this campaign
            // Use JOIN for better MySQL compatibility
            $stmt = $db->prepare("DELETE c FROM conversions c INNER JOIN clicks cl ON c.click_id = cl.click_id WHERE cl.campaign_id = ?");
            $stmt->bind_param('i', $campaignId);
            if ($stmt->execute()) {
                $deletedCount = $stmt->affected_rows;
                $stmt->close();
                // Redirect with success message
                header('Location: ?page=campaigns&success=conversions_reset&count=' . $deletedCount);
                exit;
            } else {
                $errors['general'] = 'Failed to reset conversions: ' . $stmt->error;
                $stmt->close();
                $action = 'list';
            }
        }
    } elseif ($action === 'reset_campaign_clicks') {
        $campaignId = (int)($_POST['campaign_id'] ?? 0);

        if (!$permission || !$permission->hasPermission(Permission::PERM_CAMPAIGN_EDIT)) {
            $errors['general'] = 'You do not have permission to reset clicks';
            $action = 'list';
        } elseif ($campaignId <= 0) {
            $errors['general'] = 'Invalid campaign ID';
            $action = 'list';
        } else {
            $db->begin_transaction();
            try {
                $stmt = $db->prepare("DELETE c FROM conversions c INNER JOIN clicks cl ON c.click_id = cl.click_id WHERE cl.campaign_id = ?");
                $stmt->bind_param('i', $campaignId);
                $stmt->execute();
                $stmt->close();

                $stmt = $db->prepare("DELETE FROM clicks_daily_summary WHERE campaign_id = ?");
                $stmt->bind_param('i', $campaignId);
                $stmt->execute();
                $stmt->close();

                $tables = $db->query("SHOW TABLES LIKE 'clicks_stats_by_token_daily'");
                if ($tables && $tables->num_rows > 0) {
                    $stmt = $db->prepare("DELETE FROM clicks_stats_by_token_daily WHERE campaign_id = ?");
                    $stmt->bind_param('i', $campaignId);
                    $stmt->execute();
                    $stmt->close();
                }

                $tables = $db->query("SHOW TABLES LIKE 'rollups_hourly'");
                if ($tables && $tables->num_rows > 0) {
                    $stmt = $db->prepare("DELETE FROM rollups_hourly WHERE campaign_id = ?");
                    $stmt->bind_param('i', $campaignId);
                    $stmt->execute();
                    $stmt->close();
                }

                $stmt = $db->prepare("DELETE FROM clicks WHERE campaign_id = ?");
                $stmt->bind_param('i', $campaignId);
                $stmt->execute();
                $deletedCount = $stmt->affected_rows;
                $stmt->close();

                $db->commit();
                header('Location: ?page=campaigns&success=clicks_reset&count=' . $deletedCount);
                exit;
            } catch (\Throwable $e) {
                $db->rollback();
                $errors['general'] = 'Failed to reset clicks: ' . $e->getMessage();
                error_log('reset_campaign_clicks failed: ' . $e->getMessage());
                $action = 'list';
            }
        }
    } else {
        // Parse rotation data based on flow type
        // All flows now use unified offer_id[] and offer_weight[] fields
        $rotation = [];
        $flowType = $_POST['flow_type'] ?? 'DTO';
        
        // Debug: Log POST data
        error_log('Campaign POST data: ' . print_r($_POST, true));
        error_log('Flow type: ' . $flowType);
        error_log('Offer IDs: ' . print_r($_POST['offer_id'] ?? [], true));
        error_log('Offer Weights: ' . print_r($_POST['offer_weight'] ?? [], true));
        
        // Parse unified offer rotation (used by all flow types)
        $offers = [];
        $offerIds = $_POST['offer_id'] ?? [];
        $offerWeights = $_POST['offer_weight'] ?? [];
        $offerEnabled = $_POST['offer_enabled'] ?? []; // Array of values from hidden inputs
        
        error_log('DEBUG: offer_enabled POST data: ' . print_r($offerEnabled, true));
        
        if (!empty($offerIds) && is_array($offerIds)) {
            foreach ($offerIds as $idx => $offerId) {
                $offerId = trim($offerId);
                if (!empty($offerId) && $offerId !== '') {
                    // Hidden input sends value '1' when enabled, '0' when disabled
                    // Check if the hidden input value is '1'
                    $isEnabled = isset($offerEnabled[$idx]) && (
                        $offerEnabled[$idx] === '1' || 
                        $offerEnabled[$idx] === 1 || 
                        $offerEnabled[$idx] === 'true' ||
                        $offerEnabled[$idx] === true
                    );
                    
                    error_log("DEBUG: Offer idx=$idx, offerId=$offerId, enabled value=" . ($offerEnabled[$idx] ?? 'NOT SET') . ", isEnabled=" . ($isEnabled ? 'true' : 'false'));
                    
                $offers[] = [
                        'type' => 'offer',
                    'id' => (int)$offerId,
                        'weight' => (int)($offerWeights[$idx] ?? 0),
                        'enabled' => $isEnabled
                    ];
                }
            }
        }
        
        error_log('Parsed offers array: ' . print_r($offers, true));
        
        if ($flowType === 'DTO') {
            // Direct to Offer - use unified offer rotation directly
            $rotation = $offers;
        } elseif ($flowType === 'LP') {
            // Landing Page → Offer flow
            $rotation = [
                'landing_pages' => [],
                'offers' => $offers
            ];
            
            // Parse landing page rotation
            $lpIds = $_POST['lp_id'] ?? [];
            $lpWeights = $_POST['lp_weight'] ?? [];
            $lpEnabled = $_POST['lp_enabled'] ?? []; // Array of checked checkbox indices
            foreach ($lpIds as $idx => $lpId) {
            if (!empty($lpId)) {
                    // Checkbox is enabled if the value is '1'
                    // When checked: checkbox value '1' overrides hidden input
                    // When unchecked: only hidden input with value '0' is sent
                    $isEnabled = isset($lpEnabled[$idx]) && ($lpEnabled[$idx] === '1' || $lpEnabled[$idx] === 1 || $lpEnabled[$idx] === 'true');
                    
                    $rotation['landing_pages'][] = [
                        'type' => 'landing_page',
                    'id' => (int)$lpId,
                        'weight' => (int)($lpWeights[$idx] ?? 0),
                        'enabled' => $isEnabled
                    ];
                }
            }
        } elseif ($flowType === 'Split') {
            // Split test: % to LP path, % to Direct path
            // Uses the SAME LPs from the LP section (lp_id) and SAME offers from unified offer rotation
            $trafficToLP = (int)($_POST['split_traffic_to_lp'] ?? 50);
            $trafficToDirect = 100 - $trafficToLP;
            
            // LP Path: Use the same LPs from the regular LP section (lp_id, not split_lp_id)
            $lpPathLPs = [];
            $lpIds = $_POST['lp_id'] ?? [];
            $lpWeights = $_POST['lp_weight'] ?? [];
            $lpEnabled = $_POST['lp_enabled'] ?? []; // Array of checked checkbox indices
            foreach ($lpIds as $idx => $lpId) {
                if (!empty($lpId)) {
                    // Checkbox is enabled if the value is '1'
                    // When checked: checkbox value '1' overrides hidden input
                    // When unchecked: only hidden input with value '0' is sent
                    $isEnabled = isset($lpEnabled[$idx]) && ($lpEnabled[$idx] === '1' || $lpEnabled[$idx] === 1 || $lpEnabled[$idx] === 'true');
                    
                    $lpPathLPs[] = [
                        'type' => 'landing_page',
                        'id' => (int)$lpId,
                        'weight' => (int)($lpWeights[$idx] ?? 0),
                        'enabled' => $isEnabled
                    ];
                }
            }
            
            $rotation = [
                'split_traffic' => [
                    'lp_percent' => $trafficToLP,
                    'direct_percent' => $trafficToDirect
                ],
                'lp_path' => [
                    'landing_pages' => $lpPathLPs,
                    'offers' => $offers  // Unified offers used for LP path
                ],
                'direct_path' => [
                    'offers' => $offers  // Unified offers used for direct path
                ]
            ];
        }
        
        error_log('Final rotation before data array: ' . print_r($rotation, true));

        $trafficSourceId = !empty($_POST['traffic_source_id']) && (int)$_POST['traffic_source_id'] > 0 ? (int)$_POST['traffic_source_id'] : null;

        $tsData = $trafficSourceId ? $trafficSource->getById($trafficSourceId) : null;
        $googleAdsIntegrationId = TrafficSourceReleaseHelper::resolveGoogleAdsIntegrationId(
            $tsData,
            $_POST['google_ads_integration_id'] ?? null
        );

        $data = [
            'name' => $_POST['name'] ?? '',
            'campaign_group_id' => !empty($_POST['campaign_group_id']) ? (int)$_POST['campaign_group_id'] : null,
            'traffic_source_id' => $trafficSourceId,
            'flow_type' => $_POST['flow_type'] ?? 'DTO',
        'rotation' => $rotation,
        'tracking_domain_id' => !empty($_POST['tracking_domain_id']) ? (int)$_POST['tracking_domain_id'] : null,
            'referrer_mode' => $_POST['referrer_mode'] ?? $_POST['cloaking_mode'] ?? '',
            'redirectless_tracking' => false, // Removed checkbox - now just informational
            'edge_enabled' => !empty($_POST['edge_enabled']),
            'pass_through' => [], // Will implement in detail later
            'facebook_capi_integration_id' => TrafficSourceReleaseHelper::resolveFacebookIntegrationId(
                $tsData,
                $_POST['facebook_capi_integration_id'] ?? null
            ),
            'facebook_marketing_integration_id' => !empty($_POST['facebook_marketing_integration_id'])
                ? TrafficSourceReleaseHelper::resolveFacebookIntegrationId($tsData, $_POST['facebook_marketing_integration_id'])
                : null,
            'facebook_marketing_ad_account_id' => TrafficSourceReleaseHelper::resolveFacebookIntegrationId(
                $tsData,
                $_POST['facebook_marketing_ad_account_id'] ?? null
            ),
            'facebook_marketing_campaign_id' => TrafficSourceReleaseHelper::resolveFacebookIntegrationId(
                $tsData,
                $_POST['facebook_marketing_campaign_id'] ?? null
            ),
            'google_ads_integration_id' => $googleAdsIntegrationId, // Explicitly null for non-Google Ads traffic sources
        'status' => $_POST['status'] ?? 'active',
        'default_cpc' => !empty($_POST['default_cpc']) ? (float)$_POST['default_cpc'] : null,
        'min_postback_payout' => (isset($_POST['min_postback_payout']) && $_POST['min_postback_payout'] !== '' && (float)$_POST['min_postback_payout'] >= 0)
            ? (float)$_POST['min_postback_payout'] : null,
        'allow_multiple_conversions' => !empty($_POST['allow_multiple_conversions']),
        'fallback_offer_id' => !empty($_POST['fallback_offer_id']) ? (int)$_POST['fallback_offer_id'] : null,
        'tags' => !empty($_POST['tags']) ? trim((string)$_POST['tags']) : null,
    ];

        // Parse traffic source postbacks for auto-detect campaigns
        $trafficSourcePostbacks = [];
        if (empty($data['traffic_source_id'])) {
            // Auto-detect mode: parse per-traffic-source postback configs
            $tsPostbackConfigs = $_POST['traffic_source_postbacks'] ?? [];
            foreach ($tsPostbackConfigs as $tsId => $config) {
                $tsId = (int)$tsId;
                if ($tsId > 0) {
                    $tsPostbackConfig = [
                        'facebook_capi_integration_id' => !empty($config['facebook_capi_integration_id']) ? (int)$config['facebook_capi_integration_id'] : null,
                        'google_ads_integration_id' => !empty($config['google_ads_integration_id']) ? (int)$config['google_ads_integration_id'] : null,
                    ];
                    // Only add if at least one integration is configured
                    if ($tsPostbackConfig['facebook_capi_integration_id'] || $tsPostbackConfig['google_ads_integration_id']) {
                        $trafficSourcePostbacks[$tsId] = $tsPostbackConfig;
                    }
                }
            }
        }
        $data['traffic_source_postbacks'] = $trafficSourcePostbacks;

        // Parse custom tokens
        $customTokens = [];
        $tokenNames = $_POST['custom_token_name'] ?? [];
        $tokenParameters = $_POST['custom_token_parameter'] ?? [];
        $tokenPlaceholders = $_POST['custom_token_placeholder'] ?? [];
        
        foreach ($tokenNames as $idx => $tokenName) {
            $parameter = trim($tokenParameters[$idx] ?? '');
            $placeholder = trim($tokenPlaceholders[$idx] ?? '');
            
            // Skip if parameter is empty (name and placeholder are optional for display)
            if (empty($parameter)) {
                continue;
            }
            
            // Get pass-through options
            $passToLpKey = 'custom_token_pass_to_lp_' . $idx;
            $passToOfferKey = 'custom_token_pass_to_offer_' . $idx;
            // Checkboxes with [] return arrays, so check if array exists and has value
            $passToLp = isset($_POST[$passToLpKey]) && is_array($_POST[$passToLpKey]) && !empty($_POST[$passToLpKey][0]);
            $passToOffer = isset($_POST[$passToOfferKey]) && is_array($_POST[$passToOfferKey]) && !empty($_POST[$passToOfferKey][0]);
            
            $customTokens[] = [
                'name' => trim($tokenName),
                'parameter' => $parameter,
                'placeholder' => $placeholder,
                'pass_to_lp' => $passToLp,
                'pass_to_offer' => $passToOffer
            ];
        }
        
        $data['custom_tokens'] = $customTokens;
        
        error_log('Parsed custom tokens: ' . print_r($customTokens, true));
        error_log('Custom tokens count: ' . count($customTokens));
        
        // Parse redirect rules
        $redirectRules = [];
        $ruleTokens = $_POST['redirect_rule_token'] ?? [];
        $ruleOperators = $_POST['redirect_rule_operator'] ?? [];
        $ruleValues = $_POST['redirect_rule_value'] ?? [];
        $ruleUrls = $_POST['redirect_rule_url'] ?? [];
        $ruleCaseSensitive = $_POST['redirect_rule_case_sensitive'] ?? [];
        
        foreach ($ruleTokens as $idx => $tokenIdentifier) {
            if (empty($tokenIdentifier) || empty($ruleOperators[$idx]) || empty($ruleValues[$idx]) || empty($ruleUrls[$idx])) {
                continue; // Skip incomplete rules
            }
            
            // Parse token identifier: format is "type:name" or "type:source:name"
            // Examples: "builtin:Device", "custom:MyToken", "traffic_source:YouTube:Device"
            $tokenParts = explode(':', $tokenIdentifier, 3);
            $tokenName = '';
            if (count($tokenParts) === 2) {
                // Format: "type:name" (builtin or custom)
                $tokenName = $tokenParts[1];
            } elseif (count($tokenParts) === 3) {
                // Format: "type:source:name" (traffic_source)
                $tokenName = $tokenParts[2];
            } else {
                // Fallback: treat as plain name (backward compatibility)
                $tokenName = $tokenIdentifier;
            }
            
            // Get execution points for this rule
            $executeOn = [];
            $executeOnKey = 'redirect_rule_execute_on_' . $idx;
            if (isset($_POST[$executeOnKey]) && is_array($_POST[$executeOnKey])) {
                $executeOn = $_POST[$executeOnKey];
            }
            
            if (empty($executeOn)) {
                continue; // Rule must have at least one execution point
            }
            
            // Store token source info for exact matching when loading
            $tokenSource = '';
            if (count($tokenParts) === 2) {
                // Format: "type:name" (builtin or custom)
                $tokenSource = $tokenParts[0]; // 'builtin' or 'custom'
            } elseif (count($tokenParts) === 3) {
                // Format: "type:source:name" (traffic_source)
                $tokenSource = $tokenParts[0] . ':' . $tokenParts[1]; // 'traffic_source:YouTube'
            }
            
            $redirectRules[] = [
                'token_name' => trim($tokenName),
                'token_source' => $tokenSource, // Store source for exact matching
                'operator' => trim($ruleOperators[$idx]),
                'value' => trim($ruleValues[$idx]),
                'case_sensitive' => !empty($ruleCaseSensitive[$idx]),
                'redirect_url' => trim($ruleUrls[$idx]),
                'execute_on' => $executeOn
            ];
        }
        
        $data['redirect_rules'] = $redirectRules;
        
        error_log('Parsed redirect rules: ' . print_r($redirectRules, true));
        error_log('Redirect rules count: ' . count($redirectRules));
        error_log('Data array keys before validation: ' . print_r(array_keys($data), true));

        $errors = $campaign->validate($data);

        if (empty($errors)) {
            if ($trafficSourceId === null || $trafficSourceId === 0) {
                $errors['traffic_source_id'] = 'Please select a traffic source.';
            } elseif ($trafficSourceId > 0) {
                if (!$tsData || !TrafficSourceReleaseHelper::isSelectableForRelease($tsData)) {
                    $errors['traffic_source_id'] = 'Please select a traffic source (Bing is not available for campaigns yet).';
                }
            }
        }
        
        error_log('Validation errors: ' . print_r($errors, true));
        error_log('Action before update check: ' . $action);
        error_log('ID before update check: ' . ($id ?? 'null'));

    if (empty($errors)) {
            // Check permissions before create/update
            if ($action === 'add' && $permission && !$permission->hasPermission(Permission::PERM_CAMPAIGN_CREATE) && !$hasNoRoles) {
                $errors['general'] = 'You do not have permission to create campaigns';
            } elseif ($action === 'edit' && (!$permission || !$permission->hasPermission(Permission::PERM_CAMPAIGN_EDIT))) {
                $errors['general'] = 'You do not have permission to edit campaigns';
            }
        }
        
        if (empty($errors)) {
            // Check if this is an edit operation
            if ($action === 'edit' && $id) {
                error_log('Attempting to update campaign ID: ' . $id);
                try {
                if ($campaign->update($id, $data)) {
                    // Save custom postback selections
                    $customPostbackIds = !empty($_POST['custom_postback_ids']) && is_array($_POST['custom_postback_ids']) 
                        ? array_map('intval', $_POST['custom_postback_ids']) 
                        : [];
                    $customPostback->setForCampaign($id, $customPostbackIds);
                    
                    // Handle slug management
                    $campaignSlug = new \SimpleKuma\Entity\CampaignSlug($db);
                    $slugIds = !empty($_POST['slug_id']) && is_array($_POST['slug_id']) ? array_map('intval', $_POST['slug_id']) : [];
                    $slugs = !empty($_POST['slug']) && is_array($_POST['slug']) ? $_POST['slug'] : [];
                    $slugLabels = !empty($_POST['slug_label']) && is_array($_POST['slug_label']) ? $_POST['slug_label'] : [];
                    
                    // Get existing slugs for this campaign
                    $existingSlugIds = [];
                    $existingSlugs = $campaignSlug->getByCampaignId($id);
                    foreach ($existingSlugs as $existing) {
                        $existingSlugIds[] = (int)$existing['id'];
                    }
                    
                    // Process each slug
                    foreach ($slugs as $idx => $slug) {
                        $slug = trim($slug);
                        $slugLabel = isset($slugLabels[$idx]) ? trim($slugLabels[$idx]) : '';
                        
                        // Skip empty slugs
                        if (empty($slug) || empty($slugLabel)) {
                            continue;
                        }
                        
                        $slugId = isset($slugIds[$idx]) && $slugIds[$idx] > 0 ? (int)$slugIds[$idx] : null;
                        
                        if ($slugId && in_array($slugId, $existingSlugIds, true)) {
                            // Update existing slug
                            try {
                                $campaignSlug->update($slugId, $slug, $slugLabel);
                            } catch (\InvalidArgumentException $e) {
                                $errors['slugs'] = $errors['slugs'] ?? [];
                                $errors['slugs'][] = "Slug '{$slug}': " . $e->getMessage();
                                error_log('Error updating slug: ' . $e->getMessage());
                            } catch (Exception $e) {
                                $errors['slugs'] = $errors['slugs'] ?? [];
                                $errors['slugs'][] = "Slug '{$slug}': " . $e->getMessage();
                                error_log('Error updating slug: ' . $e->getMessage());
                            }
                        } else {
                            // Create new slug
                            try {
                                $campaignSlug->create($id, $slug, $slugLabel);
                            } catch (\InvalidArgumentException $e) {
                                $errors['slugs'] = $errors['slugs'] ?? [];
                                $errors['slugs'][] = "Slug '{$slug}': " . $e->getMessage();
                                error_log('Error creating slug: ' . $e->getMessage());
                            } catch (Exception $e) {
                                $errors['slugs'] = $errors['slugs'] ?? [];
                                $errors['slugs'][] = "Slug '{$slug}': " . $e->getMessage();
                                error_log('Error creating slug: ' . $e->getMessage());
                            }
                        }
                    }
                    
                    // Delete slugs that were removed (exist in DB but not in POST)
                    foreach ($existingSlugIds as $existingSlugId) {
                        if (!in_array($existingSlugId, $slugIds, true)) {
                            $campaignSlug->delete($existingSlugId);
                        }
                    }
                    
                    error_log('Campaign updated successfully');
                    // Redirect back to edit screen to prevent re-submission
                    header('Location: ?page=campaigns&action=edit&id=' . $id . '&success=updated');
                    exit;
            } else {
                    $errors['general'] = 'Failed to update campaign: ' . $db->error;
                    error_log('Failed to update campaign: ' . $db->error);
                    }
                } catch (Exception $e) {
                    $errors['general'] = 'Error updating campaign: ' . $e->getMessage();
                    error_log('Error updating campaign: ' . $e->getMessage());
            }
        } else {
                // This is a create operation (not edit)
                try {
                    $newId = $campaign->create($data);
                    if ($newId > 0) {
                        // Save custom postback selections
                        $customPostbackIds = !empty($_POST['custom_postback_ids']) && is_array($_POST['custom_postback_ids']) 
                            ? array_map('intval', $_POST['custom_postback_ids']) 
                            : [];
                        $customPostback->setForCampaign($newId, $customPostbackIds);
                        
                        // Handle slug creation
                        $campaignSlug = new \SimpleKuma\Entity\CampaignSlug($db);
                        $slugs = !empty($_POST['slug']) && is_array($_POST['slug']) ? $_POST['slug'] : [];
                        $slugLabels = !empty($_POST['slug_label']) && is_array($_POST['slug_label']) ? $_POST['slug_label'] : [];
                        
                        foreach ($slugs as $idx => $slug) {
                            $slug = trim($slug);
                            $slugLabel = isset($slugLabels[$idx]) ? trim($slugLabels[$idx]) : '';
                            
                            // Skip empty slugs
                            if (empty($slug) || empty($slugLabel)) {
                                continue;
                            }
                            
                            try {
                                $campaignSlug->create($newId, $slug, $slugLabel);
                            } catch (\InvalidArgumentException $e) {
                                $errors['slugs'] = $errors['slugs'] ?? [];
                                $errors['slugs'][] = "Slug '{$slug}': " . $e->getMessage();
                                error_log('Error creating slug: ' . $e->getMessage());
                            } catch (Exception $e) {
                                $errors['slugs'] = $errors['slugs'] ?? [];
                                $errors['slugs'][] = "Slug '{$slug}': " . $e->getMessage();
                                error_log('Error creating slug: ' . $e->getMessage());
                            }
                        }
                        
                        $success = 'Campaign created successfully';
                $action = 'list';
            } else {
                        $errors['general'] = 'Failed to create campaign: ' . $db->error;
                    }
                } catch (Exception $e) {
                    $errors['general'] = 'Error creating campaign: ' . $e->getMessage();
                }
            }
        } else {
            $errors['general'] = 'Please fix the validation errors';
        }
        
        // If there are slug errors, add them to general errors for display
        if (!empty($errors['slugs'])) {
            $errors['general'] = ($errors['general'] ?? 'Please fix the validation errors') . ' ' . implode(' ', $errors['slugs']);
        }
    }
    } // end CSRF-validated POST body
}

$editCampaign = null;
$editRotation = [];
if ($action === 'edit' && $id) {
    $editCampaign = $campaign->getById($id);
    if ($editCampaign) {
        $editRotation = $editCampaign['rotation_json'] ?? [];
        // Debug: Log what we're loading for edit
        error_log('Edit mode - Campaign ID: ' . $id . ', Flow type: ' . ($editCampaign['flow_type'] ?? 'unknown') . ', Rotation structure: ' . print_r($editRotation, true));
    }
}

// Form catalogs are only needed for add/edit — skip on list for faster first paint
$trafficSources = [];
$offers = [];
$landingPages = [];
$campaignGroups = [];
$facebookIntegrations = [];
$facebookMarketingIntegrations = [];
$allFacebookAdAccounts = [];
$googleAdsIntegrations = [];
$allCustomPostbacks = [];
$verifiedTrackingDomains = [];
$firstSelectableTrafficSource = null;
$isLegacyAutoDetectCampaign = $action === 'edit' && $editCampaign && empty($editCampaign['traffic_source_id']);

if ($action !== 'list') {
    $trafficSources = $trafficSource->getAll();
    $offers = $offer->getAll();
    $landingPages = $landingPage->getAll();
    $campaignGroups = $campaignGroup->getAll();
    $facebookIntegrations = $facebookCapi->getAll();
    $facebookMarketingIntegrations = $facebookMarketing->getAllIncludingPaused();
    require_once __DIR__ . '/../src/Entity/FacebookMarketingAdAccount.php';
    $facebookAdAccountEntity = new \SimpleKuma\Entity\FacebookMarketingAdAccount($db);
    $allFacebookAdAccounts = $facebookAdAccountEntity->getAll();
    $googleAdsIntegrations = $googleAds->getAll();
    $allCustomPostbacks = $customPostback->getAll();
    $verifiedTrackingDomains = $trackingDomain->getVerified();
    $firstSelectableTrafficSource = TrafficSourceReleaseHelper::getFirstSelectable($trafficSources);
}

// Initialize CampaignSlug entity
$campaignSlug = new \SimpleKuma\Entity\CampaignSlug($db);

// Load existing slugs if editing
$existingSlugs = [];
if ($action === 'edit' && $id) {
    $existingSlugs = $campaignSlug->getByCampaignId($id);
}

// Get selected custom postbacks for this campaign (if editing)
$selectedCustomPostbackIds = [];
if ($editCampaign && isset($editCampaign['id'])) {
    $selectedCustomPostbacks = $customPostback->getForCampaign((int)$editCampaign['id']);
    $selectedCustomPostbackIds = array_column($selectedCustomPostbacks, 'id');
}

// Don't close DB connection here - it may be needed by the layout
// $db->close();
?>

<div class="campaigns-page">
<div class="page-header">
    <h1 class="page-title">Campaigns</h1>
    <p class="page-description">Create and manage your tracking campaigns.</p>
</div>

<?php if ($success): ?>
<div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 4px; margin-bottom: 20px; border-left: 4px solid #28a745;">
        ✅ <?= htmlspecialchars($success) ?>
    </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 4px; margin-bottom: 20px; border-left: 4px solid #d32f2f;">
    <strong>⚠️ Errors:</strong><br>
    <?php foreach ($errors as $field => $error): ?>
        • <?= htmlspecialchars($error) ?><br>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($action === 'list'): ?>
    <?php
    $listPageSlug = ($GLOBALS['currentPage'] ?? 'campaign-list') === 'campaigns' ? 'campaigns' : 'campaign-list';
    $canCreateCampaign = (!$permission || $permission->hasPermission(Permission::PERM_CAMPAIGN_CREATE) || $hasNoRoles);
    ?>
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Your Campaigns</h2>
            <?php if ($canCreateCampaign): ?>
            <a href="?page=campaign-create" class="btn btn-primary campaign-filter-create-desktop">+ Create Campaign</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php
            // Date range for performance stats - default to today in user's timezone (must be defined before preset detection)
            $todayInUserTz = Formatter::getTodayInTimezone($userTimezone);
            $dateFrom = $_GET['date_from'] ?? $todayInUserTz;
            $dateTo = $_GET['date_to'] ?? $todayInUserTz;

            // Campaign status filter: active, paused, archived (shared preference with dashboard)
            $statusFilterRaw = $_GET['status_filter'] ?? [];
            if (!is_array($statusFilterRaw)) {
                $statusFilterRaw = $statusFilterRaw !== '' ? [$statusFilterRaw] : [];
            }
            $validStatuses = ['active', 'paused', 'archived'];
            $allowedStatuses = array_values(array_intersect($statusFilterRaw, $validStatuses));
            if (count($allowedStatuses) === 0 || count($allowedStatuses) === 3) {
                $allowedStatuses = null; // show all
            }
            ?>
            
            <!-- Filter Campaigns -->
            <?php
            // Determine which preset is active (using user's timezone)
            $activePreset = null;
            try {
                $tz = new DateTimeZone($userTimezone);
                $now = new DateTime('now', $tz);
                $today = $now->format('Y-m-d');

                $yesterdayDt = clone $now;
                $yesterdayDt->modify('-1 day');
                $yesterday = $yesterdayDt->format('Y-m-d');

                $last7Dt = clone $now;
                $last7Dt->modify('-6 days');
                $last7Start = $last7Dt->format('Y-m-d');

                $last14Dt = clone $now;
                $last14Dt->modify('-13 days');
                $last14Start = $last14Dt->format('Y-m-d');

                $last30Dt = clone $now;
                $last30Dt->modify('-29 days');
                $last30Start = $last30Dt->format('Y-m-d');

                $lastMonthStartDt = clone $now;
                $lastMonthStartDt->modify('first day of last month');
                $lastMonthStart = $lastMonthStartDt->format('Y-m-d');

                $lastMonthEndDt = clone $now;
                $lastMonthEndDt->modify('last day of last month');
                $lastMonthEnd = $lastMonthEndDt->format('Y-m-d');

                $thisMonthStartDt = clone $now;
                $thisMonthStartDt->modify('first day of this month');
                $thisMonthStart = $thisMonthStartDt->format('Y-m-d');

                // All time preset: 2025-01-01 to today
                $allTimeStart = '2025-01-01';
            } catch (Exception $e) {
                // Fallback to server timezone
                $today = date('Y-m-d');
                $yesterday = date('Y-m-d', strtotime('-1 day'));
                $last7Start = date('Y-m-d', strtotime('-6 days'));
                $last14Start = date('Y-m-d', strtotime('-13 days'));
                $last30Start = date('Y-m-d', strtotime('-29 days'));
                $lastMonthStart = date('Y-m-01', strtotime('first day of last month'));
                $lastMonthEnd = date('Y-m-t', strtotime('last month'));
                $thisMonthStart = date('Y-m-01');
                $allTimeStart = '2025-01-01';
            }

            if ($dateFrom === $today && $dateTo === $today) {
                $activePreset = 'today';
            } elseif ($dateFrom === $yesterday && $dateTo === $yesterday) {
                $activePreset = 'yesterday';
            } elseif ($dateFrom === $last7Start && $dateTo === $today) {
                $activePreset = 'last7';
            } elseif ($dateFrom === $last14Start && $dateTo === $today) {
                $activePreset = 'last14';
            } elseif ($dateFrom === $last30Start && $dateTo === $today) {
                $activePreset = 'last30';
            } elseif ($dateFrom === $lastMonthStart && $dateTo === $lastMonthEnd) {
                $activePreset = 'lastmonth';
            } elseif ($dateFrom === $thisMonthStart && $dateTo === $today) {
                $activePreset = 'thismonth';
            } elseif ($dateFrom === $allTimeStart && $dateTo === $today) {
                $activePreset = 'alltime';
            }

            $statusActiveChecked = ($allowedStatuses === null || in_array('active', $allowedStatuses, true));
            $statusPausedChecked = ($allowedStatuses === null || in_array('paused', $allowedStatuses, true));
            $statusArchivedChecked = ($allowedStatuses === null || in_array('archived', $allowedStatuses, true));
            ?>
            <div class="campaign-filter-wrap">
                <div class="campaign-filter-panel" id="campaign-filter-panel">
                    <?php if ($canCreateCampaign): ?>
                    <a href="?page=campaign-create" class="campaign-filter-create campaign-filter-create-mobile">+ Create Campaign</a>
                    <?php endif; ?>

                    <form method="get" action="" id="campaign-list-date-filter-form" class="campaign-filter-form">
                        <input type="hidden" name="page" value="<?= htmlspecialchars($listPageSlug) ?>">

                        <div class="campaign-filter-section campaign-filter-section--presets">
                            <div class="campaign-filter-label campaign-filter-label--mobile">Date Presets</div>
                            <div class="campaign-date-presets campaign-date-presets--wrap" role="group" aria-label="Date presets">
                                <button type="button" class="campaign-preset-btn<?= $activePreset === 'today' ? ' is-active' : '' ?>" data-preset="today" onclick="setDateRange('today')">Today</button>
                                <button type="button" class="campaign-preset-btn<?= $activePreset === 'yesterday' ? ' is-active' : '' ?>" data-preset="yesterday" onclick="setDateRange('yesterday')">Yesterday</button>
                                <button type="button" class="campaign-preset-btn<?= $activePreset === 'last7' ? ' is-active' : '' ?>" data-preset="last7" onclick="setDateRange('last7')">7d</button>
                                <button type="button" class="campaign-preset-btn<?= $activePreset === 'last14' ? ' is-active' : '' ?>" data-preset="last14" onclick="setDateRange('last14')">14d</button>
                                <button type="button" class="campaign-preset-btn<?= $activePreset === 'last30' ? ' is-active' : '' ?>" data-preset="last30" onclick="setDateRange('last30')">30d</button>
                                <button type="button" class="campaign-preset-btn<?= $activePreset === 'lastmonth' ? ' is-active' : '' ?>" data-preset="lastmonth" onclick="setDateRange('lastmonth')">Last Mo</button>
                                <button type="button" class="campaign-preset-btn<?= $activePreset === 'thismonth' ? ' is-active' : '' ?>" data-preset="thismonth" onclick="setDateRange('thismonth')">This Mo</button>
                                <button type="button" class="campaign-preset-btn<?= $activePreset === 'alltime' ? ' is-active' : '' ?>" data-preset="alltime" onclick="setDateRange('alltime')">ALL TIME</button>
                            </div>
                        </div>

                        <div class="campaign-filter-section campaign-filter-section--range">
                            <div class="campaign-filter-label campaign-filter-label--mobile">Custom Date Range</div>
                            <div class="campaign-filter-label campaign-filter-label--desktop">Custom Range:</div>
                            <div class="campaign-filter-dates">
                                <label class="campaign-filter-date-field" for="date_from">
                                    <span class="campaign-filter-date-icon" aria-hidden="true">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                    </span>
                                    <input type="date" name="date_from" id="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
                                </label>
                                <span class="campaign-filter-to">to</span>
                                <div class="campaign-filter-dates-row">
                                    <label class="campaign-filter-date-field" for="date_to">
                                        <span class="campaign-filter-date-icon" aria-hidden="true">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                        </span>
                                        <input type="date" name="date_to" id="date_to" value="<?= htmlspecialchars($dateTo) ?>">
                                    </label>
                                    <button type="submit" class="campaign-filter-apply campaign-filter-apply--desktop">
                                        Apply Filters
                                    </button>
                                    <a href="?page=<?= htmlspecialchars($listPageSlug) ?>" class="campaign-filter-reset">Reset</a>
                                </div>
                            </div>
                        </div>

                        <div class="campaign-filter-section campaign-filter-section--status">
                            <div class="campaign-filter-label campaign-filter-label--mobile">Show Status</div>
                            <div class="campaign-filter-label campaign-filter-label--desktop">Show:</div>
                            <div class="campaign-status-cards" role="group" aria-label="Campaign status">
                                <label class="campaign-status-card<?= $statusActiveChecked ? ' is-checked' : '' ?>">
                                    <span class="campaign-status-card__label">Active</span>
                                    <span class="campaign-status-check" aria-hidden="true"></span>
                                    <input type="checkbox" name="status_filter[]" value="active" class="campaign-status-input"<?= $statusActiveChecked ? ' checked' : '' ?>>
                                </label>
                                <label class="campaign-status-card<?= $statusPausedChecked ? ' is-checked' : '' ?>">
                                    <span class="campaign-status-card__label">Paused</span>
                                    <span class="campaign-status-check" aria-hidden="true"></span>
                                    <input type="checkbox" name="status_filter[]" value="paused" class="campaign-status-input"<?= $statusPausedChecked ? ' checked' : '' ?>>
                                </label>
                                <label class="campaign-status-card<?= $statusArchivedChecked ? ' is-checked' : '' ?>">
                                    <span class="campaign-status-card__label">Archived</span>
                                    <span class="campaign-status-check" aria-hidden="true"></span>
                                    <input type="checkbox" name="status_filter[]" value="archived" class="campaign-status-input"<?= $statusArchivedChecked ? ' checked' : '' ?>>
                                </label>
                            </div>
                        </div>

                        <div class="campaign-filter-actions campaign-filter-actions--mobile">
                            <button type="submit" class="campaign-filter-apply campaign-filter-apply--mobile">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                Apply Filters
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <script>
                // Pre-calculated dates in user's timezone (from PHP)
                const datePresets = {
                    today: '<?= $today ?>',
                    yesterday: '<?= $yesterday ?>',
                    last7Start: '<?= $last7Start ?>',
                    last14Start: '<?= $last14Start ?>',
                    last30Start: '<?= $last30Start ?>',
                    lastMonthStart: '<?= $lastMonthStart ?>',
                    lastMonthEnd: '<?= $lastMonthEnd ?>',
                    thisMonthStart: '<?= $thisMonthStart ?>',
                    allTimeStart: '<?= $allTimeStart ?>'
                };
                
                function setDateRange(preset) {
                    const form = document.getElementById('campaign-list-date-filter-form');
                    const dateFromInput = document.getElementById('date_from');
                    const dateToInput = document.getElementById('date_to');
                    if (!form || !dateFromInput || !dateToInput) return;
                    let fromDate, toDate;
                    
                    switch(preset) {
                        case 'today':
                            fromDate = datePresets.today;
                            toDate = datePresets.today;
                            break;
                        case 'yesterday':
                            fromDate = datePresets.yesterday;
                            toDate = datePresets.yesterday;
                            break;
                        case 'last7':
                            fromDate = datePresets.last7Start;
                            toDate = datePresets.today;
                            break;
                        case 'last14':
                            fromDate = datePresets.last14Start;
                            toDate = datePresets.today;
                            break;
                        case 'last30':
                            fromDate = datePresets.last30Start;
                            toDate = datePresets.today;
                            break;
                        case 'lastmonth':
                            fromDate = datePresets.lastMonthStart;
                            toDate = datePresets.lastMonthEnd;
                            break;
                        case 'thismonth':
                            fromDate = datePresets.thisMonthStart;
                            toDate = datePresets.today;
                            break;
                        case 'alltime':
                            fromDate = datePresets.allTimeStart;
                            toDate = datePresets.today;
                            break;
                    }
                    
                    dateFromInput.value = fromDate;
                    dateToInput.value = toDate;

                    form.querySelectorAll('.campaign-preset-btn').forEach(function (btn) {
                        btn.classList.toggle('is-active', btn.getAttribute('data-preset') === preset);
                    });

                    form.submit();
                }

                (function () {
                    var form = document.getElementById('campaign-list-date-filter-form');
                    if (!form) return;
                    form.querySelectorAll('.campaign-status-card input[type="checkbox"]').forEach(function (cb) {
                        cb.addEventListener('change', function () {
                            var card = cb.closest('.campaign-status-card');
                            if (card) card.classList.toggle('is-checked', cb.checked);
                        });
                    });
                })();
            </script>
            <?php
            // Reuse existing DB connection when possible; list only needs campaign metadata
            if (!isset($db) || !($db instanceof mysqli) || $db->connect_errno) {
                $db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
                $campaign = new \SimpleKuma\Entity\Campaign($db);
            }

            $campaignsListPage = max(1, (int)($_GET['campaigns_page'] ?? 1));
            $campaignsPerPage = 25;

            $allCampaigns = $campaign->getAll();
            if ($allowedStatuses !== null) {
                $allCampaigns = array_values(array_filter(
                    $allCampaigns,
                    static function (array $camp) use ($allowedStatuses): bool {
                        return in_array($camp['status'] ?? 'active', $allowedStatuses, true);
                    }
                ));
            }

            $campaignStatsTotal = count($allCampaigns);
            $campaignsTotalPages = max(1, (int)ceil($campaignStatsTotal / max(1, $campaignsPerPage)));
            if ($campaignsListPage > $campaignsTotalPages) {
                $campaignsListPage = $campaignsTotalPages;
            }
            $pageOffset = ($campaignsListPage - 1) * $campaignsPerPage;
            $pageCampaigns = array_slice($allCampaigns, $pageOffset, $campaignsPerPage);

            // Empty stats shell — filled asynchronously via api-campaign-list-stats.php
            $emptyStats = [
                'views' => 0,
                'lp_clicks' => 0,
                'direct_clicks' => 0,
                'conversions' => 0,
                'cost' => 0,
                'revenue' => 0,
                'profit' => 0,
                'roi' => 0,
            ];
            foreach ($pageCampaigns as &$camp) {
                $camp['stats'] = $emptyStats;
                $camp['slugs'] = [];
            }
            unset($camp);

            $pageCampaignIds = array_map(static fn (array $c): int => (int)$c['id'], $pageCampaigns);

            $statusQueryForApi = '';
            if ($allowedStatuses !== null) {
                foreach ($allowedStatuses as $st) {
                    $statusQueryForApi .= '&status_filter[]=' . rawurlencode($st);
                }
            }
            $campaignListStatsApiUrl = rtrim(APP_BASE_URL, '/') . '/api-campaign-list-stats.php'
                . '?date_from=' . rawurlencode($dateFrom)
                . '&date_to=' . rawurlencode($dateTo)
                . '&campaign_ids=' . rawurlencode(implode(',', $pageCampaignIds));

            // Group campaigns (current page only)
            $groupedCampaigns = [];
            $ungroupedCampaigns = [];
            foreach ($pageCampaigns as $camp) {
                if (!empty($camp['campaign_group_name'])) {
                    if (!isset($groupedCampaigns[$camp['campaign_group_name']])) {
                        $groupedCampaigns[$camp['campaign_group_name']] = [];
                    }
                    $groupedCampaigns[$camp['campaign_group_name']][] = $camp;
                } else {
                    $ungroupedCampaigns[] = $camp;
                }
            }

            // Keep $allCampaigns as page slice for empty check / render
            $allCampaigns = $pageCampaigns;

            $listStatusQuery = '';
            if ($allowedStatuses !== null) {
                foreach ($allowedStatuses as $st) {
                    $listStatusQuery .= '&status_filter[]=' . urlencode($st);
                }
            }
            $listBaseQs = 'page=' . urlencode($listPageSlug)
                . '&date_from=' . urlencode($dateFrom)
                . '&date_to=' . urlencode($dateTo)
                . $listStatusQuery;
            ?>

            <?php if ($campaignStatsTotal === 0): ?>
                <div style="text-align: center; padding: 60px; color: #999;">
                    <img src="<?= ASSETS_BASE_URL ?>/assets/images/campaigns.png" alt="Campaigns" style="width: 64px; height: 64px; margin-bottom: 16px;">
                    <?php if ($allowedStatuses !== null): ?>
                    <p>No campaigns match the selected status filters.</p>
                    <p style="font-size: 13px; margin-top: 8px;">Try enabling Active, Paused, or Archived above.</p>
                    <?php else: ?>
                    <p>No campaigns yet. Create your first campaign to start tracking!</p>
                    <?php if (!$permission || $permission->hasPermission(Permission::PERM_CAMPAIGN_CREATE) || $hasNoRoles): ?>
                    <a href="?page=campaign-create" class="btn btn-primary" style="margin-top: 20px;">+ Create Campaign</a>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php if ($campaignsTotalPages > 1): ?>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; font-size:13px; color:#666;">
                    <span>Showing <?= count($allCampaigns) ?> of <?= (int)$campaignStatsTotal ?> campaigns (page <?= (int)$campaignsListPage ?>/<?= (int)$campaignsTotalPages ?>)</span>
                    <span style="display:flex; gap:8px;">
                        <?php if ($campaignsListPage > 1): ?>
                            <a class="btn btn-outline" style="padding:4px 10px; font-size:12px;" href="?<?= $listBaseQs ?>&campaigns_page=<?= $campaignsListPage - 1 ?>">Previous</a>
                        <?php endif; ?>
                        <?php if ($campaignsListPage < $campaignsTotalPages): ?>
                            <a class="btn btn-outline" style="padding:4px 10px; font-size:12px;" href="?<?= $listBaseQs ?>&campaigns_page=<?= $campaignsListPage + 1 ?>">Next</a>
                        <?php endif; ?>
                    </span>
                </div>
                <?php endif; ?>
                <div id="campaign-list-stats-status" style="font-size:12px; color:#888; margin-bottom:8px;">Loading performance stats…</div>
                <!-- Search and Filter Bar -->
                <div style="margin-bottom: 16px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                    <div style="position: relative; flex: 1; min-width: 260px; max-width: 450px;">
                        <input type="text" id="campaignSearchInput" placeholder="🔍 Search campaigns by name, tag, #ID, source..." 
                               oninput="filterCampaignsTable()"
                               class="campaign-search-input"
                               style="width: 100%; padding: 9px 14px; border: 2px solid #ddd; border-radius: 6px; font-size: 13px; background: #fff;">
                    </div>
                    <span id="campaignSearchCount" style="font-size: 13px; color: #666; font-weight: 500;"></span>
                </div>
                <!-- Desktop Table View (hidden on mobile) -->
                <div class="table-wrapper desktop-only" data-campaign-list-stats-url="<?= htmlspecialchars($campaignListStatsApiUrl) ?>">
                    <?php
                    // Function to render campaign row
                    function renderCampaignRow($camp, $indent = false, $trafficSources = [], $groupId = null) {
                        $userCurrency = $GLOBALS['userCurrency'] ?? 'USD';
                        // Get permission from global scope
                        $permission = $GLOBALS['permission'] ?? null;
                        
                        $stats = $camp['stats'] ?? [];
                        $trafficLabel = (empty($camp['traffic_source_id']) || empty($camp['traffic_source_name']))
                            ? 'Auto Detected'
                            : (string)$camp['traffic_source_name'];
                        $searchData = strtolower(
                            ($camp['name'] ?? '') . ' ' .
                            ($camp['tags'] ?? '') . ' #' .
                            ($camp['id'] ?? '') . ' ' .
                            $trafficLabel . ' ' .
                            ($camp['campaign_group_name'] ?? '') . ' ' .
                            ($camp['flow_type'] ?? '') . ' ' .
                            ($camp['status'] ?? '')
                        );
                        $rowStyle = $groupId !== null ? 'display: none;' : '';
                        ?>
                        <tr class="campaign-row"
                            data-campaign-id="<?= (int)$camp['id'] ?>"
                            <?= $groupId !== null ? 'data-group-id="' . htmlspecialchars($groupId, ENT_QUOTES) . '"' : '' ?>
                            data-search="<?= htmlspecialchars($searchData, ENT_QUOTES) ?>"
                            data-sort-name="<?= htmlspecialchars((string)$camp['name'], ENT_QUOTES) ?>"
                            data-sort-traffic_source="<?= htmlspecialchars($trafficLabel, ENT_QUOTES) ?>"
                            data-sort-flow_type="<?= htmlspecialchars((string)$camp['flow_type'], ENT_QUOTES) ?>"
                            data-sort-status="<?= htmlspecialchars((string)$camp['status'], ENT_QUOTES) ?>"
                            data-sort-views="<?= (int)($stats['views'] ?? 0) ?>"
                            data-sort-clicks="<?= (int)(($stats['lp_clicks'] ?? 0) + ($stats['direct_clicks'] ?? 0)) ?>"
                            data-sort-conv="<?= (int)($stats['conversions'] ?? 0) ?>"
                            data-sort-cost="<?= (float)($stats['cost'] ?? 0) ?>"
                            data-sort-revenue="<?= (float)($stats['revenue'] ?? 0) ?>"
                            data-sort-roi="<?= (float)($stats['roi'] ?? 0) ?>"
                            style="<?= $rowStyle ?>">
                            <td class="col-name" style="<?= $indent ? 'padding-left: 40px;' : '' ?>">
                                <span class="badge" style="background: #eef2f5; color: #475569; font-weight: 600; font-size: 11px; padding: 2px 6px; border-radius: 4px; margin-right: 6px;" title="Campaign ID: <?= (int)$camp['id'] ?>">#<?= (int)$camp['id'] ?></span>
                                <strong style="font-size: 14px;"><?= htmlspecialchars($camp['name']) ?></strong>
                                <?php if ($camp['campaign_group_name'] && !$indent): ?>
                                    <span class="badge badge-info" style="margin-left: 8px; font-size: 10px;">
                                        <?= htmlspecialchars($camp['campaign_group_name']) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($camp['tags'])): ?>
                                    <div style="margin-top: 3px; display: flex; flex-wrap: wrap; gap: 4px;">
                                        <?php foreach (explode(',', (string)$camp['tags']) as $t): $t = trim($t); if ($t === '') continue; ?>
                                            <span style="display: inline-block; font-size: 10px; background: #e0f2fe; color: #0369a1; padding: 1px 5px; border-radius: 3px; font-weight: 500;">🏷️ <?= htmlspecialchars($t) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="col-source" style="font-size: 13px;">
                                <?php if (empty($camp['traffic_source_id']) || empty($camp['traffic_source_name'])): ?>
                                    <span style="color: #558b2f; font-weight: 500; display: inline-flex; align-items: center; gap: 6px;">
                                        <img src="<?= ASSETS_BASE_URL ?>/assets/images/autodetectbear.png" alt="Auto Detected" style="width: 20px; height: 20px; object-fit: contain; vertical-align: middle;">
                                        Auto Detected
                                    </span>
                                <?php else: ?>
                                    <?= htmlspecialchars($camp['traffic_source_name']) ?>
                                <?php endif; ?>
                            </td>
                            <td class="col-flow"><span class="badge badge-info" style="font-size: 11px;"><?= htmlspecialchars($camp['flow_type']) ?></span></td>
                            <td class="col-status">
                                <?php if ($camp['status'] === 'active'): ?>
                                    <span class="badge badge-success" style="font-size: 11px;">Active</span>
                                <?php elseif ($camp['status'] === 'paused'): ?>
                                    <span class="badge badge-warning" style="font-size: 11px;">Paused</span>
                                <?php else: ?>
                                    <span class="badge" style="font-size: 11px;">Archived</span>
                                <?php endif; ?>
                            </td>
                            <td class="cl-stat cl-stat-views col-num" style="font-size: 12px; color: #666;">—</td>
                            <td class="cl-stat cl-stat-clicks col-num" style="font-size: 12px; color: #666;">—</td>
                            <td class="cl-stat cl-stat-conv col-num" style="font-size: 12px; color: #666;">—</td>
                            <td class="cl-stat cl-stat-cost col-money" style="font-size: 12px; color: #d32f2f; font-weight: 500;">—</td>
                            <td class="cl-stat cl-stat-revenue col-money" style="font-size: 12px; color: #666;">—</td>
                            <td class="cl-stat cl-stat-roi col-roi" style="font-size: 12px; color: #666; font-weight: 600;">—</td>
                            <td class="col-actions">
                                <div class="campaign-actions">
                                    <!-- Stats Button -->
                                    <a href="?page=campaign-stats&campaign_id=<?= $camp['id'] ?>" 
                                       class="campaign-action-btn"
                                       data-action="stats"
                                       title="View Stats">
                                        📊
                                    </a>
                                    <?php if (!$permission || $permission->hasPermission(\SimpleKuma\Auth\Permission::PERM_CAMPAIGN_EDIT)): ?>
                                    <!-- Edit Button -->
                                    <a href="?page=campaigns&action=edit&id=<?= $camp['id'] ?>" 
                                       class="campaign-action-btn"
                                       data-action="edit"
                                       title="Edit Campaign">
                                        ✏️
                                    </a>
                                    
                                    <!-- Reset Conversions Button -->
                                    <form method="post" action="?page=campaigns" style="display: inline; margin: 0;">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="reset_campaign_conversions">
                                        <input type="hidden" name="campaign_id" value="<?= $camp['id'] ?>">
                                        <button type="submit" 
                                                class="campaign-action-btn"
                                                data-action="reset-conv"
                                                title="Reset Campaign Conversions"
                                                onclick="return confirm('Are you sure you want to reset all conversions for this campaign?\\n\\nThis will permanently delete all conversion records and cannot be undone.');">
                                            🔄
                                        </button>
                                    </form>

                                    <!-- Reset Clicks Button -->
                                    <form method="post" action="?page=campaigns" style="display: inline; margin: 0;">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="reset_campaign_clicks">
                                        <input type="hidden" name="campaign_id" value="<?= $camp['id'] ?>">
                                        <button type="submit" 
                                                class="campaign-action-btn"
                                                data-action="reset-clicks"
                                                title="Reset Campaign Clicks"
                                                onclick="return confirm('Are you sure you want to reset all clicks for this campaign?\\n\\nThis will permanently delete all click records, conversions, and related stats for this campaign. This cannot be undone.');">
                                            🖱️
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    
                                    <?php if (!$permission || $permission->hasPermission(\SimpleKuma\Auth\Permission::PERM_CAMPAIGN_CREATE)): ?>
                                    <!-- Clone Button -->
                                    <form method="post" action="?page=campaigns&action=clone&id=<?= $camp['id'] ?>" style="display: inline; margin: 0;">
                                        <?= Csrf::field() ?>
                                        <button type="submit" 
                                                class="campaign-action-btn"
                                                data-action="clone"
                                                title="Clone Campaign">
                                            📋
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    
                                    <?php if (!$permission || $permission->hasPermission(\SimpleKuma\Auth\Permission::PERM_CAMPAIGN_DELETE)): ?>
                                    <!-- Delete Button -->
                                    <form method="post" action="?page=campaigns&action=delete&id=<?= $camp['id'] ?>" 
                                          style="display: inline; margin: 0;" 
                                          onsubmit="return confirm('Are you sure you want to delete this campaign?\\n\\nThis will permanently delete all associated click data and cannot be undone.');">
                                        <?= Csrf::field() ?>
                                        <button type="submit" 
                                                class="campaign-action-btn"
                                                data-action="delete"
                                                title="Delete Campaign">
                                            🗑️
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php
                    }
                    ?>
                    
                <style>
                    .table-wrapper.desktop-only[data-campaign-list-stats-url] {
                        overflow-x: hidden;
                        max-width: 100%;
                    }
                    .campaigns-table {
                        table-layout: fixed;
                        width: 100%;
                        max-width: 100%;
                    }
                    .campaigns-table th,
                    .campaigns-table td {
                        box-sizing: border-box;
                    }
                    .campaigns-table thead th {
                        vertical-align: middle;
                        line-height: 1.3;
                        /* keep default .table th font-size (sm), do not shrink */
                    }
                    .campaigns-table .sortable-col {
                        cursor: pointer;
                        user-select: none;
                        white-space: nowrap;
                    }
                    .campaigns-table .sortable-col:hover {
                        background: rgba(255, 255, 255, 0.12);
                    }
                    .campaigns-table .sort-indicator {
                        margin-left: 3px;
                        opacity: 0.85;
                        font-size: 0.85em;
                    }
                    /* Balanced % widths so Name isn't greedy and Actions stay on-screen */
                    .campaigns-table col.col-name,
                    .campaigns-table th.col-name,
                    .campaigns-table td.col-name {
                        width: 20%;
                    }
                    .campaigns-table col.col-source,
                    .campaigns-table th.col-source,
                    .campaigns-table td.col-source {
                        width: 9%;
                    }
                    .campaigns-table col.col-flow,
                    .campaigns-table th.col-flow,
                    .campaigns-table td.col-flow {
                        width: 7%;
                    }
                    .campaigns-table col.col-status,
                    .campaigns-table th.col-status,
                    .campaigns-table td.col-status {
                        width: 8%;
                    }
                    .campaigns-table col.col-num,
                    .campaigns-table th.col-num,
                    .campaigns-table td.col-num {
                        width: 6%;
                    }
                    .campaigns-table th.col-num,
                    .campaigns-table td.col-num {
                        text-align: right;
                        white-space: nowrap;
                    }
                    .campaigns-table col.col-money,
                    .campaigns-table th.col-money,
                    .campaigns-table td.col-money {
                        width: 7%;
                    }
                    .campaigns-table th.col-money,
                    .campaigns-table td.col-money {
                        text-align: right;
                        white-space: nowrap;
                    }
                    .campaigns-table col.col-roi,
                    .campaigns-table th.col-roi,
                    .campaigns-table td.col-roi {
                        width: 6%;
                    }
                    .campaigns-table th.col-roi,
                    .campaigns-table td.col-roi {
                        text-align: right;
                        white-space: nowrap;
                    }
                    .campaigns-table col.col-actions,
                    .campaigns-table th.col-actions,
                    .campaigns-table td.col-actions {
                        width: 18%;
                    }
                    .campaigns-table th.col-actions,
                    .campaigns-table td.col-actions {
                        padding-left: 8px;
                        padding-right: 12px;
                    }
                    .campaigns-table td.col-name strong {
                        word-break: break-word;
                    }
                    .campaigns-table td.col-source {
                        white-space: nowrap;
                        overflow: hidden;
                        text-overflow: ellipsis;
                    }
                    .campaign-actions {
                        display: flex;
                        gap: 4px;
                        align-items: center;
                        flex-wrap: nowrap;
                        justify-content: flex-end;
                        max-width: 100%;
                    }
                    .campaign-action-btn {
                        width: 28px;
                        height: 28px;
                        padding: 0;
                        border: 1px solid #ddd;
                        border-radius: 5px;
                        background: #fff;
                        cursor: pointer;
                        display: inline-flex;
                        align-items: center;
                        justify-content: center;
                        transition: all 0.2s;
                        color: #666;
                        text-decoration: none;
                        flex-shrink: 0;
                        font-size: 13px;
                        line-height: 1;
                        box-sizing: border-box;
                    }
                    .campaign-action-btn[data-action="stats"]:hover {
                        background: #f5f5f5;
                        border-color: #4caf50;
                        color: #4caf50;
                    }
                    .campaign-action-btn[data-action="edit"]:hover {
                        background: #e3f2fd;
                        border-color: #2196F3;
                        color: #2196F3;
                    }
                    .campaign-action-btn[data-action="reset-conv"]:hover,
                    .campaign-action-btn[data-action="delete"]:hover {
                        background: #ffebee;
                        border-color: #d32f2f;
                        color: #d32f2f;
                    }
                    .campaign-action-btn[data-action="reset-clicks"]:hover {
                        background: #fff3e0;
                        border-color: #ef6c00;
                        color: #ef6c00;
                    }
                    .campaign-action-btn[data-action="clone"]:hover {
                        background: #fff3e0;
                        border-color: #ff9800;
                        color: #ff9800;
                    }
                    .campaign-action-btn[data-action="delete"]:hover {
                        border-color: #f44336;
                        color: #f44336;
                    }
                </style>
                <table class="table campaigns-table campaigns-table-sortable" data-sort-key="" data-sort-dir="asc">
                    <colgroup>
                        <col class="col-name">
                        <col class="col-source">
                        <col class="col-flow">
                        <col class="col-status">
                        <col class="col-num">
                        <col class="col-num">
                        <col class="col-num">
                        <col class="col-money">
                        <col class="col-money">
                        <col class="col-roi">
                        <col class="col-actions">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="sortable-col col-name" data-sort-key="name" data-sort-type="string" title="Click to sort">Name <span class="sort-indicator" aria-hidden="true">↕</span></th>
                            <th class="sortable-col col-source" data-sort-key="traffic_source" data-sort-type="string" title="Click to sort">Source <span class="sort-indicator" aria-hidden="true">↕</span></th>
                            <th class="sortable-col col-flow" data-sort-key="flow_type" data-sort-type="string" title="Click to sort">Flow <span class="sort-indicator" aria-hidden="true">↕</span></th>
                            <th class="sortable-col col-status" data-sort-key="status" data-sort-type="string" title="Click to sort">Status <span class="sort-indicator" aria-hidden="true">↕</span></th>
                            <th class="sortable-col col-num" data-sort-key="views" data-sort-type="number" title="Click to sort">Views <span class="sort-indicator" aria-hidden="true">↕</span></th>
                            <th class="sortable-col col-num" data-sort-key="clicks" data-sort-type="number" title="Click to sort">Clicks <span class="sort-indicator" aria-hidden="true">↕</span></th>
                            <th class="sortable-col col-num" data-sort-key="conv" data-sort-type="number" title="Click to sort">Conv <span class="sort-indicator" aria-hidden="true">↕</span></th>
                            <th class="sortable-col col-money" data-sort-key="cost" data-sort-type="number" title="Click to sort">Cost <span class="sort-indicator" aria-hidden="true">↕</span></th>
                            <th class="sortable-col col-money" data-sort-key="revenue" data-sort-type="number" title="Click to sort">Revenue <span class="sort-indicator" aria-hidden="true">↕</span></th>
                            <th class="sortable-col col-roi" data-sort-key="roi" data-sort-type="number" title="Click to sort">ROI <span class="sort-indicator" aria-hidden="true">↕</span></th>
                            <th class="col-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                            <?php
                            // Render grouped campaigns
                            foreach ($groupedCampaigns as $groupName => $groupCampaigns):
                            ?>
                                <?php
                                $groupId = md5($groupName);
                                // Calculate group totals
                                $groupViews = 0;
                                $groupClicks = 0;
                                $groupConversions = 0;
                                $groupRevenue = 0;
                                $groupCost = 0;
                                foreach ($groupCampaigns as $gCamp) {
                                    $gStats = $gCamp['stats'] ?? [];
                                    $groupViews += $gStats['views'] ?? 0;
                                    $groupClicks += $gStats['lp_clicks'] ?? 0;
                                    $groupConversions += $gStats['conversions'] ?? 0;
                                    $groupRevenue += $gStats['revenue'] ?? 0;
                                    $groupCost += $gStats['cost'] ?? 0;
                                }
                                $groupProfit = $groupRevenue - $groupCost;
                                $groupRoi = $groupCost > 0 
                                    ? (($groupRevenue - $groupCost) / $groupCost) * 100 
                                    : ($groupRevenue > 0 ? 99999.9 : 0);
                                ?>
                                <tr class="group-header"
                                    data-group-id="<?= htmlspecialchars($groupId, ENT_QUOTES) ?>"
                                    data-sort-name="<?= htmlspecialchars($groupName, ENT_QUOTES) ?>"
                                    data-sort-traffic_source=""
                                    data-sort-flow_type=""
                                    data-sort-status=""
                                    data-sort-views="0"
                                    data-sort-clicks="0"
                                    data-sort-conv="0"
                                    data-sort-cost="0"
                                    data-sort-revenue="0"
                                    data-sort-roi="0"
                                    onclick="toggleGroup('<?= htmlspecialchars($groupId, ENT_QUOTES) ?>')"
                                    style="cursor: pointer; background: #f9f9f9;">
                                    <td colspan="4" class="col-name" style="font-weight: 600; color: #3d5a26; padding: 12px;">
                                        <span id="toggle-<?= htmlspecialchars($groupId, ENT_QUOTES) ?>">▶</span>
                                        📁 <?= htmlspecialchars($groupName) ?>
                                        <span style="color: #999; font-weight: normal; margin-left: 8px;">(<?= count($groupCampaigns) ?> campaigns)</span>
                                </td>
                                    <td class="cl-group-stat cl-stat-views col-num" style="font-size: 12px; font-weight: 600; color: #666;">—</td>
                                    <td class="cl-group-stat cl-stat-clicks col-num" style="font-size: 12px; font-weight: 600; color: #666;">—</td>
                                    <td class="cl-group-stat cl-stat-conv col-num" style="font-size: 12px; font-weight: 600; color: #666;">—</td>
                                    <td class="cl-group-stat cl-stat-cost col-money" style="font-size: 12px; font-weight: 600; color: #d32f2f;">—</td>
                                    <td class="cl-group-stat cl-stat-revenue col-money" style="font-size: 12px; font-weight: 600; color: #666;">—</td>
                                    <td class="cl-group-stat cl-stat-roi col-roi" style="font-size: 12px; font-weight: 600; color: #666;">—</td>
                                    <td class="col-actions"></td>
                            </tr>
                                    <?php foreach ($groupCampaigns as $camp): ?>
                                        <?php renderCampaignRow($camp, true, $trafficSources, $groupId); ?>
                        <?php endforeach; ?>
                            <?php endforeach; ?>
                            
                            <?php
                            // Render ungrouped campaigns
                            foreach ($ungroupedCampaigns as $camp):
                                renderCampaignRow($camp, false, $trafficSources, null);
                            endforeach;
                            ?>
                    </tbody>
                </table>
                </div>
                
                <!-- Mobile Card View (hidden on desktop) -->
                <div class="mobile-campaign-cards mobile-only">
                    <?php
                    // Render grouped campaigns as mobile cards
                    foreach ($groupedCampaigns as $groupName => $groupCampaigns):
                        // Calculate group totals
                        $groupViews = 0;
                        $groupClicks = 0;
                        $groupConversions = 0;
                        $groupRevenue = 0;
                        $groupCost = 0;
                        foreach ($groupCampaigns as $gCamp) {
                            $gStats = $gCamp['stats'] ?? [];
                            $groupViews += $gStats['views'] ?? 0;
                            $groupClicks += $gStats['lp_clicks'] ?? 0;
                            $groupConversions += $gStats['conversions'] ?? 0;
                            $groupRevenue += $gStats['revenue'] ?? 0;
                            $groupCost += $gStats['cost'] ?? 0;
                        }
                        $groupProfit = $groupRevenue - $groupCost;
                        $groupRoi = $groupCost > 0 ? (($groupProfit / $groupCost) * 100) : ($groupRevenue > 0 ? 99999.9 : 0);
                    ?>
                    <div class="mobile-group-card" style="margin-bottom: var(--spacing-md); background: var(--color-white); border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); overflow: hidden;">
                        <div class="mobile-group-header" onclick="toggleMobileGroup('<?= md5($groupName) ?>')" style="background: var(--color-forest); color: var(--color-cream); padding: var(--spacing-md); cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <strong style="font-size: var(--font-size-base);">📁 <?= htmlspecialchars($groupName) ?></strong>
                                <div style="font-size: var(--font-size-xs); opacity: 0.9; margin-top: 4px;"><?= count($groupCampaigns) ?> campaigns</div>
                            </div>
                            <span id="mobile-toggle-<?= md5($groupName) ?>" style="font-size: 20px;">▶</span>
                        </div>
                        <div class="mobile-group-summary" data-group-id="<?= md5($groupName) ?>" style="background: var(--color-cream); padding: var(--spacing-sm) var(--spacing-md); border-left: 3px solid var(--color-forest); display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--spacing-xs); font-size: var(--font-size-xs);">
                            <div><strong>Views:</strong> <span class="cl-group-stat cl-stat-views">—</span></div>
                            <div><strong>Clicks:</strong> <span class="cl-group-stat cl-stat-clicks">—</span></div>
                            <div><strong>Conv:</strong> <span class="cl-group-stat cl-stat-conv">—</span></div>
                            <div><strong>Revenue:</strong> <span class="cl-group-stat cl-stat-revenue" style="color: #28a745;">—</span></div>
                            <div><strong>Cost:</strong> <span class="cl-group-stat cl-stat-cost">—</span></div>
                            <div><strong>ROI:</strong> <span class="cl-group-stat cl-stat-roi">—</span></div>
                        </div>
                        <div id="mobile-group-<?= md5($groupName) ?>" class="mobile-group-campaigns" style="display: none;">
                            <?php foreach ($groupCampaigns as $camp): ?>
                                <?php
                                $isAutoDetect = empty($camp['traffic_source_id']);
                                $trafficLabel = (empty($camp['traffic_source_id']) || empty($camp['traffic_source_name']))
                                    ? 'Auto Detected'
                                    : (string)$camp['traffic_source_name'];
                                $searchData = strtolower(
                                    ($camp['name'] ?? '') . ' ' .
                                    ($camp['tags'] ?? '') . ' #' .
                                    ($camp['id'] ?? '') . ' ' .
                                    $trafficLabel . ' ' .
                                    ($camp['campaign_group_name'] ?? '') . ' ' .
                                    ($camp['flow_type'] ?? '') . ' ' .
                                    ($camp['status'] ?? '')
                                );
                                ?>
                                <div class="mobile-campaign-card" data-campaign-id="<?= (int)$camp['id'] ?>" data-search="<?= htmlspecialchars($searchData, ENT_QUOTES) ?>" style="padding: var(--spacing-md); border-bottom: 1px solid var(--color-gray-200);">
                                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: var(--spacing-sm);">
                                        <div style="flex: 1;">
                                            <div style="font-weight: 600; font-size: 14px; color: #3d5a26; margin-bottom: 4px;">
                                                <span class="badge" style="background: #eef2f5; color: #475569; font-weight: 600; font-size: 11px; padding: 2px 6px; border-radius: 4px; margin-right: 6px;" title="Campaign ID: <?= (int)$camp['id'] ?>">#<?= (int)$camp['id'] ?></span>
                                                <?= htmlspecialchars($camp['name']) ?>
                                            </div>
                                            <?php if (!empty($camp['tags'])): ?>
                                                <div style="margin-top: 3px; margin-bottom: 4px; display: flex; flex-wrap: wrap; gap: 4px;">
                                                    <?php foreach (explode(',', (string)$camp['tags']) as $t): $t = trim($t); if ($t === '') continue; ?>
                                                        <span style="display: inline-block; font-size: 10px; background: #e0f2fe; color: #0369a1; padding: 1px 5px; border-radius: 3px; font-weight: 500;">🏷️ <?= htmlspecialchars($t) ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                            <div style="font-size: 11px; color: #666; display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px;">
                                                <?php if ($isAutoDetect): ?>
                                                    <span style="color: #558b2f; font-weight: 500; display: inline-flex; align-items: center; gap: 4px;">
                                                        <img src="<?= ASSETS_BASE_URL ?>/assets/images/autodetectbear.png" alt="Auto Detected" style="width: 16px; height: 16px;">
                                                        Auto Detected
                                                    </span>
                                                <?php else: ?>
                                                    <span><?= htmlspecialchars($camp['traffic_source_name']) ?></span>
                                                <?php endif; ?>
                                                <span class="badge badge-info" style="font-size: 10px;"><?= htmlspecialchars($camp['flow_type']) ?></span>
                                                <?php if ($camp['status'] === 'active'): ?>
                                                    <span class="badge badge-success" style="font-size: 10px;">Active</span>
                                                <?php elseif ($camp['status'] === 'paused'): ?>
                                                    <span class="badge badge-warning" style="font-size: 10px;">Paused</span>
                                                <?php else: ?>
                                                    <span class="badge" style="font-size: 10px;">Archived</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--spacing-xs); font-size: 11px; color: #666; margin-bottom: var(--spacing-sm);">
                                        <div><strong>Views:</strong> <span class="cl-stat cl-stat-views">—</span></div>
                                        <div><strong>Clicks:</strong> <span class="cl-stat cl-stat-clicks">—</span></div>
                                        <div><strong>Conv:</strong> <span class="cl-stat cl-stat-conv">—</span></div>
                                        <div><strong>Cost:</strong> <span class="cl-stat cl-stat-cost" style="color: #d32f2f;">—</span></div>
                                        <div><strong>Revenue:</strong> <span class="cl-stat cl-stat-revenue" style="color: #28a745;">—</span></div>
                                        <div><strong>ROI:</strong> <span class="cl-stat cl-stat-roi" style="font-weight: 600;">—</span></div>
                                    </div>
                                    
                                    <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                        <a href="?page=campaign-stats&campaign_id=<?= $camp['id'] ?>" 
                                           style="padding: 8px 14px; min-height: 44px; font-size: 13px; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; text-decoration: none; color: #666; display: inline-flex; align-items: center; justify-content: center; touch-action: manipulation; -webkit-tap-highlight-color: transparent;">
                                            📊 Stats
                                        </a>
                                        <?php if (!$permission || $permission->hasPermission(\SimpleKuma\Auth\Permission::PERM_CAMPAIGN_EDIT)): ?>
                                        <a href="?page=campaigns&action=edit&id=<?= $camp['id'] ?>" 
                                           style="padding: 8px 14px; min-height: 44px; font-size: 13px; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; text-decoration: none; color: #666; display: inline-flex; align-items: center; justify-content: center; touch-action: manipulation; -webkit-tap-highlight-color: transparent;">
                                            ✏️ Edit
                                        </a>
                                        <form method="post" action="?page=campaigns" style="display: inline; margin: 0;">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="action" value="reset_campaign_conversions">
                                            <input type="hidden" name="campaign_id" value="<?= $camp['id'] ?>">
                                            <button type="submit" 
                                                    style="padding: 8px 14px; min-height: 44px; font-size: 13px; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; color: #666; display: inline-flex; align-items: center; justify-content: center; touch-action: manipulation; -webkit-tap-highlight-color: transparent;"
                                                    onclick="return confirm('Are you sure you want to reset all conversions for this campaign?\\n\\nThis will permanently delete all conversion records and cannot be undone.');">
                                                🔄 Reset Conv
                                            </button>
                                        </form>
                                        <form method="post" action="?page=campaigns" style="display: inline; margin: 0;">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="action" value="reset_campaign_clicks">
                                            <input type="hidden" name="campaign_id" value="<?= $camp['id'] ?>">
                                            <button type="submit" 
                                                    style="padding: 8px 14px; min-height: 44px; font-size: 13px; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; color: #666; display: inline-flex; align-items: center; justify-content: center; touch-action: manipulation; -webkit-tap-highlight-color: transparent;"
                                                    title="Reset Campaign Clicks"
                                                    onclick="return confirm('Are you sure you want to reset all clicks for this campaign?\\n\\nThis will permanently delete all click records, conversions, and related stats for this campaign. This cannot be undone.');">
                                                🖱️ Reset Clicks
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <?php
                    // Render ungrouped campaigns
                    foreach ($ungroupedCampaigns as $camp):
                        $isAutoDetect = empty($camp['traffic_source_id']);
                        $trafficLabel = (empty($camp['traffic_source_id']) || empty($camp['traffic_source_name']))
                            ? 'Auto Detected'
                            : (string)$camp['traffic_source_name'];
                        $searchData = strtolower(
                            ($camp['name'] ?? '') . ' ' .
                            ($camp['tags'] ?? '') . ' #' .
                            ($camp['id'] ?? '') . ' ' .
                            $trafficLabel . ' ' .
                            ($camp['campaign_group_name'] ?? '') . ' ' .
                            ($camp['flow_type'] ?? '') . ' ' .
                            ($camp['status'] ?? '')
                        );
                    ?>
                    <div class="mobile-campaign-card" data-campaign-id="<?= (int)$camp['id'] ?>" data-search="<?= htmlspecialchars($searchData, ENT_QUOTES) ?>" style="background: var(--color-white); border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); padding: var(--spacing-md); margin-bottom: var(--spacing-md); box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: var(--spacing-sm);">
                            <div style="flex: 1;">
                                <div style="font-weight: 600; font-size: 14px; color: #3d5a26; margin-bottom: 4px;">
                                    <span class="badge" style="background: #eef2f5; color: #475569; font-weight: 600; font-size: 11px; padding: 2px 6px; border-radius: 4px; margin-right: 6px;" title="Campaign ID: <?= (int)$camp['id'] ?>">#<?= (int)$camp['id'] ?></span>
                                    <?= htmlspecialchars($camp['name']) ?>
                                </div>
                                <?php if (!empty($camp['tags'])): ?>
                                    <div style="margin-top: 3px; margin-bottom: 4px; display: flex; flex-wrap: wrap; gap: 4px;">
                                        <?php foreach (explode(',', (string)$camp['tags']) as $t): $t = trim($t); if ($t === '') continue; ?>
                                            <span style="display: inline-block; font-size: 10px; background: #e0f2fe; color: #0369a1; padding: 1px 5px; border-radius: 3px; font-weight: 500;">🏷️ <?= htmlspecialchars($t) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <div style="font-size: 11px; color: #666; display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px;">
                                    <?php if ($isAutoDetect): ?>
                                        <span style="color: #558b2f; font-weight: 500; display: inline-flex; align-items: center; gap: 4px;">
                                            <img src="<?= ASSETS_BASE_URL ?>/assets/images/autodetectbear.png" alt="Auto Detected" style="width: 16px; height: 16px;">
                                            Auto Detected
                                        </span>
                                    <?php else: ?>
                                        <span><?= htmlspecialchars($camp['traffic_source_name']) ?></span>
                                    <?php endif; ?>
                                    <span class="badge badge-info" style="font-size: 10px;"><?= htmlspecialchars($camp['flow_type']) ?></span>
                                    <?php if ($camp['status'] === 'active'): ?>
                                        <span class="badge badge-success" style="font-size: 10px;">Active</span>
                                    <?php elseif ($camp['status'] === 'paused'): ?>
                                        <span class="badge badge-warning" style="font-size: 10px;">Paused</span>
                                    <?php else: ?>
                                        <span class="badge" style="font-size: 10px;">Archived</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--spacing-xs); font-size: 11px; color: #666; margin-bottom: var(--spacing-sm);">
                            <div><strong>Views:</strong> <span class="cl-stat cl-stat-views">—</span></div>
                            <div><strong>Clicks:</strong> <span class="cl-stat cl-stat-clicks">—</span></div>
                            <div><strong>Conv:</strong> <span class="cl-stat cl-stat-conv">—</span></div>
                            <div><strong>Cost:</strong> <span class="cl-stat cl-stat-cost" style="color: #d32f2f;">—</span></div>
                            <div><strong>Revenue:</strong> <span class="cl-stat cl-stat-revenue" style="color: #28a745;">—</span></div>
                            <div><strong>ROI:</strong> <span class="cl-stat cl-stat-roi" style="font-weight: 600;">—</span></div>
                        </div>
                        
                        <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                            <a href="?page=campaign-stats&campaign_id=<?= $camp['id'] ?>" 
                               style="padding: 8px 14px; min-height: 44px; font-size: 13px; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; text-decoration: none; color: #666; display: inline-flex; align-items: center; justify-content: center; touch-action: manipulation; -webkit-tap-highlight-color: transparent;">
                                📊 Stats
                            </a>
                            <?php if (!$permission || $permission->hasPermission(\SimpleKuma\Auth\Permission::PERM_CAMPAIGN_EDIT)): ?>
                            <a href="?page=campaigns&action=edit&id=<?= $camp['id'] ?>" 
                               style="padding: 8px 14px; min-height: 44px; font-size: 13px; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; text-decoration: none; color: #666; display: inline-flex; align-items: center; justify-content: center; touch-action: manipulation; -webkit-tap-highlight-color: transparent;">
                                ✏️ Edit
                            </a>
                            <form method="post" action="?page=campaigns" style="display: inline; margin: 0;">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="reset_campaign_conversions">
                                <input type="hidden" name="campaign_id" value="<?= $camp['id'] ?>">
                                <button type="submit" 
                                        style="padding: 8px 14px; min-height: 44px; font-size: 13px; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; color: #666; display: inline-flex; align-items: center; justify-content: center; touch-action: manipulation; -webkit-tap-highlight-color: transparent;"
                                        onclick="return confirm('Are you sure you want to reset all conversions for this campaign?\\n\\nThis will permanently delete all conversion records and cannot be undone.');">
                                    🔄 Reset Conv
                                </button>
                            </form>
                            <form method="post" action="?page=campaigns" style="display: inline; margin: 0;">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="reset_campaign_clicks">
                                <input type="hidden" name="campaign_id" value="<?= $camp['id'] ?>">
                                <button type="submit" 
                                        style="padding: 8px 14px; min-height: 44px; font-size: 13px; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; color: #666; display: inline-flex; align-items: center; justify-content: center; touch-action: manipulation; -webkit-tap-highlight-color: transparent;"
                                        title="Reset Campaign Clicks"
                                        onclick="return confirm('Are you sure you want to reset all clicks for this campaign?\\n\\nThis will permanently delete all click records, conversions, and related stats for this campaign. This cannot be undone.');">
                                    🖱️ Reset Clicks
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <script>
                    function toggleMobileGroup(groupId) {
                        const groupDiv = document.getElementById('mobile-group-' + groupId);
                        const toggle = document.getElementById('mobile-toggle-' + groupId);
                        
                        if (groupDiv.style.display === 'none') {
                            groupDiv.style.display = 'block';
                            toggle.textContent = '▼';
                        } else {
                            groupDiv.style.display = 'none';
                            toggle.textContent = '▶';
                        }
                    }

                    function filterCampaignsTable() {
                        var input = document.getElementById('campaignSearchInput');
                        var query = (input ? input.value : '').toLowerCase().trim();
                        var rows = document.querySelectorAll('.campaigns-table tbody tr.campaign-row');
                        var groupHeaders = document.querySelectorAll('.campaigns-table tbody tr.group-header');
                        var groupCards = document.querySelectorAll('.mobile-campaign-cards .mobile-group-card');
                        var ungroupedCards = document.querySelectorAll('.mobile-campaign-cards > .mobile-campaign-card');

                        var visibleCount = 0;
                        var totalCount = rows.length || (groupCards.length + ungroupedCards.length);
                        var matchingGroupIds = new Set();

                        // 1. Desktop table rows
                        rows.forEach(function(row) {
                            var searchData = row.getAttribute('data-search') || '';
                            var match = !query || searchData.indexOf(query) !== -1;
                            var groupId = row.getAttribute('data-group-id');

                            if (match) {
                                visibleCount++;
                                if (groupId) {
                                    matchingGroupIds.add(groupId);
                                }
                            }

                            if (!query) {
                                if (groupId) {
                                    var toggle = document.getElementById('toggle-' + groupId);
                                    var isOpen = toggle && toggle.textContent === '▼';
                                    row.style.display = isOpen ? '' : 'none';
                                } else {
                                    row.style.display = '';
                                }
                            } else {
                                row.style.display = match ? '' : 'none';
                            }
                        });

                        // 2. Desktop group header rows
                        groupHeaders.forEach(function(hdr) {
                            var gId = hdr.getAttribute('data-group-id');
                            if (!query) {
                                hdr.style.display = '';
                            } else {
                                hdr.style.display = matchingGroupIds.has(gId) ? '' : 'none';
                            }
                        });

                        // 3. Mobile cards
                        groupCards.forEach(function(card) {
                            var campaignsContainer = card.querySelector('.mobile-group-campaigns');
                            var toggle = card.querySelector('.mobile-group-header span[id^="mobile-toggle-"]');
                            var childCards = card.querySelectorAll('.mobile-campaign-card');
                            var groupHasMatch = false;

                            childCards.forEach(function(childCard) {
                                var searchData = childCard.getAttribute('data-search') || '';
                                var match = !query || searchData.indexOf(query) !== -1;
                                childCard.style.display = match ? '' : 'none';
                                if (match) groupHasMatch = true;
                            });

                            if (!query) {
                                card.style.display = '';
                                if (campaignsContainer && toggle) {
                                    campaignsContainer.style.display = toggle.textContent === '▼' ? 'block' : 'none';
                                }
                            } else {
                                card.style.display = groupHasMatch ? '' : 'none';
                                if (campaignsContainer) {
                                    campaignsContainer.style.display = groupHasMatch ? 'block' : 'none';
                                }
                            }
                        });

                        ungroupedCards.forEach(function(card) {
                            var searchData = card.getAttribute('data-search') || '';
                            var match = !query || searchData.indexOf(query) !== -1;
                            card.style.display = match ? '' : 'none';
                        });

                        // 4. Update count text
                        var countEl = document.getElementById('campaignSearchCount');
                        if (countEl) {
                            if (query) {
                                countEl.textContent = 'Showing ' + visibleCount + ' of ' + totalCount + ' campaigns';
                            } else {
                                countEl.textContent = totalCount + ' total campaigns';
                            }
                        }
                    }

                    document.addEventListener('DOMContentLoaded', function() {
                        if (document.getElementById('campaignSearchInput')) {
                            filterCampaignsTable();
                        }
                    });
                </script>
                
</div>
                <script>
                    function toggleGroup(groupId) {
                        const rows = document.querySelectorAll('tr.campaign-row[data-group-id="' + groupId + '"]');
                        const toggle = document.getElementById('toggle-' + groupId);
                        if (!toggle || !rows.length) return;

                        const opening = toggle.textContent === '▶';
                        rows.forEach(function(row) {
                            row.style.display = opening ? '' : 'none';
                        });
                        toggle.textContent = opening ? '▼' : '▶';
                    }

                    (function loadCampaignListStats() {
                        var wrap = document.querySelector('[data-campaign-list-stats-url]');
                        var statusEl = document.getElementById('campaign-list-stats-status');
                        if (!wrap) {
                            if (statusEl) statusEl.style.display = 'none';
                            return;
                        }
                        var url = wrap.getAttribute('data-campaign-list-stats-url');
                        if (!url) return;

                        function fmtMoney(n) {
                            n = Number(n) || 0;
                            return '$' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                        }
                        function fmtInt(n) {
                            return (Number(n) || 0).toLocaleString();
                        }
                        function fmtRoi(n) {
                            n = Number(n) || 0;
                            if (n >= 99999) return '∞';
                            return n.toFixed(1) + '%';
                        }

                        function applyCampaignStats(id, s) {
                            var views = s.views || 0;
                            // LP CTA + direct-to-offer actions (DTO campaigns have lp_clicks=0)
                            var clicks = (s.lp_clicks || 0) + (s.direct_clicks || 0);
                            var conv = s.conversions || 0;
                            var cost = s.cost || 0;
                            var revenue = s.revenue || 0;
                            var roi = s.roi || 0;

                            document.querySelectorAll('[data-campaign-id="' + id + '"]').forEach(function(root) {
                                root.setAttribute('data-sort-views', views);
                                root.setAttribute('data-sort-clicks', clicks);
                                root.setAttribute('data-sort-conv', conv);
                                root.setAttribute('data-sort-cost', cost);
                                root.setAttribute('data-sort-revenue', revenue);
                                root.setAttribute('data-sort-roi', roi);

                                var el;
                                el = root.querySelector('.cl-stat-views'); if (el) el.textContent = fmtInt(views);
                                el = root.querySelector('.cl-stat-clicks'); if (el) el.textContent = fmtInt(clicks);
                                el = root.querySelector('.cl-stat-conv'); if (el) el.textContent = fmtInt(conv);
                                el = root.querySelector('.cl-stat-cost'); if (el) el.textContent = fmtMoney(cost);
                                el = root.querySelector('.cl-stat-revenue');
                                if (el) {
                                    el.textContent = fmtMoney(revenue);
                                    el.style.color = revenue > 0 ? '#28a745' : '#666';
                                    el.style.fontWeight = revenue > 0 ? '600' : '400';
                                }
                                el = root.querySelector('.cl-stat-roi');
                                if (el) {
                                    el.textContent = fmtRoi(roi);
                                    el.style.color = roi >= 0 ? '#28a745' : '#d32f2f';
                                }
                            });
                        }

                        function refreshGroupTotals() {
                            document.querySelectorAll('tr.group-header[data-group-id]').forEach(function(header) {
                                var gid = header.getAttribute('data-group-id');
                                var rows = document.querySelectorAll('tr.campaign-row[data-group-id="' + gid + '"]');
                                var views = 0, clicks = 0, conv = 0, cost = 0, revenue = 0;
                                rows.forEach(function(row) {
                                    views += parseFloat(row.getAttribute('data-sort-views') || '0') || 0;
                                    clicks += parseFloat(row.getAttribute('data-sort-clicks') || '0') || 0;
                                    conv += parseFloat(row.getAttribute('data-sort-conv') || '0') || 0;
                                    cost += parseFloat(row.getAttribute('data-sort-cost') || '0') || 0;
                                    revenue += parseFloat(row.getAttribute('data-sort-revenue') || '0') || 0;
                                });
                                var roi = cost > 0 ? ((revenue - cost) / cost) * 100 : (revenue > 0 ? 99999.9 : 0);
                                header.setAttribute('data-sort-views', views);
                                header.setAttribute('data-sort-clicks', clicks);
                                header.setAttribute('data-sort-conv', conv);
                                header.setAttribute('data-sort-cost', cost);
                                header.setAttribute('data-sort-revenue', revenue);
                                header.setAttribute('data-sort-roi', roi);
                                var el;
                                el = header.querySelector('.cl-stat-views'); if (el) el.textContent = fmtInt(views);
                                el = header.querySelector('.cl-stat-clicks'); if (el) el.textContent = fmtInt(clicks);
                                el = header.querySelector('.cl-stat-conv'); if (el) el.textContent = fmtInt(conv);
                                el = header.querySelector('.cl-stat-cost'); if (el) el.textContent = fmtMoney(cost);
                                el = header.querySelector('.cl-stat-revenue');
                                if (el) {
                                    el.textContent = fmtMoney(revenue);
                                    el.style.color = revenue > 0 ? '#28a745' : '#666';
                                }
                                el = header.querySelector('.cl-stat-roi');
                                if (el) {
                                    el.textContent = fmtRoi(roi);
                                    el.style.color = roi >= 0 ? '#28a745' : '#d32f2f';
                                }

                                var mobileSummary = document.querySelector('.mobile-group-summary[data-group-id="' + gid + '"]');
                                if (mobileSummary) {
                                    el = mobileSummary.querySelector('.cl-stat-views'); if (el) el.textContent = fmtInt(views);
                                    el = mobileSummary.querySelector('.cl-stat-clicks'); if (el) el.textContent = fmtInt(clicks);
                                    el = mobileSummary.querySelector('.cl-stat-conv'); if (el) el.textContent = fmtInt(conv);
                                    el = mobileSummary.querySelector('.cl-stat-cost'); if (el) el.textContent = fmtMoney(cost);
                                    el = mobileSummary.querySelector('.cl-stat-revenue'); if (el) el.textContent = fmtMoney(revenue);
                                    el = mobileSummary.querySelector('.cl-stat-roi'); if (el) el.textContent = fmtRoi(roi);
                                }
                            });
                        }

                        var listAbort = (typeof AbortController !== 'undefined') ? new AbortController() : null;
                        window.addEventListener('pagehide', function() {
                            if (listAbort) listAbort.abort();
                        });

                        function fetchListStats(attempt) {
                            return fetch(url, {
                                credentials: 'same-origin',
                                headers: { 'Accept': 'application/json' },
                                signal: listAbort ? listAbort.signal : undefined
                            }).then(function(res) {
                                return res.json().then(function(data) {
                                    return { status: res.status, data: data };
                                });
                            }).then(function(result) {
                                if (result.data && result.data.busy && attempt < 2) {
                                    return new Promise(function(resolve) {
                                        setTimeout(function() { resolve(fetchListStats(attempt + 1)); }, 800);
                                    });
                                }
                                return result;
                            });
                        }

                        fetchListStats(0)
                            .then(function(result) {
                                var data = result.data;
                                if (!data || !data.ok || !data.stats) {
                                    if (statusEl) statusEl.textContent = (data && data.error) ? data.error : 'Could not load performance stats.';
                                    return;
                                }
                                Object.keys(data.stats).forEach(function(id) {
                                    applyCampaignStats(id, data.stats[id]);
                                });
                                refreshGroupTotals();
                                if (statusEl) statusEl.style.display = 'none';
                            })
                            .catch(function(err) {
                                if (err && (err.name === 'AbortError' || err.code === 20)) {
                                    return;
                                }
                                if (statusEl) statusEl.textContent = 'Could not load performance stats.';
                            });
                    })();

                    (function initCampaignsTableSort() {
                        var table = document.querySelector('.campaigns-table-sortable');
                        if (!table) return;
                        var tbody = table.tBodies[0];
                        if (!tbody) return;
                        var stringKeys = { name: 1, traffic_source: 1, flow_type: 1, status: 1 };

                        function parseVal(raw, type) {
                            if (raw == null || raw === '') {
                                return type === 'string' ? '' : 0;
                            }
                            if (type === 'string') return String(raw).toLowerCase();
                            var n = parseFloat(raw);
                            return isNaN(n) ? 0 : n;
                        }

                        function compareRows(a, b, attr, type, dir) {
                            var va = parseVal(a.getAttribute(attr), type);
                            var vb = parseVal(b.getAttribute(attr), type);
                            if (type === 'string') {
                                var c = String(va).localeCompare(String(vb), undefined, { sensitivity: 'base', numeric: true });
                                return dir === 'asc' ? c : -c;
                            }
                            return dir === 'asc' ? (va - vb) : (vb - va);
                        }

                        function refreshIndicators() {
                            var key = table.getAttribute('data-sort-key') || '';
                            var dir = table.getAttribute('data-sort-dir') || 'asc';
                            table.querySelectorAll('.sortable-col').forEach(function(th) {
                                var span = th.querySelector('.sort-indicator');
                                if (!span) return;
                                span.textContent = (th.getAttribute('data-sort-key') === key)
                                    ? (dir === 'asc' ? '↑' : '↓')
                                    : '↕';
                            });
                        }

                        function sortTable(key, type, dir) {
                            var attr = 'data-sort-' + key;
                            var headers = Array.from(tbody.querySelectorAll('tr.group-header'));
                            var units = headers.map(function(header) {
                                var gid = header.getAttribute('data-group-id');
                                var members = Array.from(tbody.querySelectorAll('tr.campaign-row[data-group-id="' + gid + '"]'));
                                members.sort(function(a, b) {
                                    return compareRows(a, b, attr, type, dir);
                                });
                                return { header: header, members: members };
                            });

                            units.sort(function(a, b) {
                                return compareRows(a.header, b.header, attr, type, dir);
                            });

                            var ungrouped = Array.from(tbody.querySelectorAll('tr.campaign-row:not([data-group-id])'));
                            ungrouped.sort(function(a, b) {
                                return compareRows(a, b, attr, type, dir);
                            });

                            var frag = document.createDocumentFragment();
                            units.forEach(function(unit) {
                                frag.appendChild(unit.header);
                                unit.members.forEach(function(row) {
                                    frag.appendChild(row);
                                });
                            });
                            ungrouped.forEach(function(row) {
                                frag.appendChild(row);
                            });
                            tbody.appendChild(frag);
                        }

                        table.querySelector('thead').addEventListener('click', function(e) {
                            var th = e.target && e.target.closest ? e.target.closest('.sortable-col') : null;
                            if (!th || !table.contains(th)) return;
                            e.preventDefault();
                            var key = th.getAttribute('data-sort-key');
                            var type = th.getAttribute('data-sort-type') || (stringKeys[key] ? 'string' : 'number');
                            var currentKey = table.getAttribute('data-sort-key') || '';
                            var currentDir = table.getAttribute('data-sort-dir') || 'asc';
                            var dir;
                            if (key === currentKey) {
                                dir = currentDir === 'asc' ? 'desc' : 'asc';
                            } else {
                                dir = type === 'string' ? 'asc' : 'desc';
                            }
                            table.setAttribute('data-sort-key', key);
                            table.setAttribute('data-sort-dir', dir);
                            sortTable(key, type, dir);
                            refreshIndicators();
                            if (typeof filterCampaignsTable === 'function') {
                                filterCampaignsTable();
                            }
                        });
                    })();
                </script>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($action === 'add' || $action === 'edit'): 
    // Check permissions
            if ($action === 'add' && $permission && !$permission->hasPermission(Permission::PERM_CAMPAIGN_CREATE) && !$hasNoRoles) {
                $errors['general'] = 'You do not have permission to create campaigns';
                $action = 'list';
    } elseif ($action === 'edit' && (!$permission || !$permission->hasPermission(Permission::PERM_CAMPAIGN_EDIT))) {
        $errors['general'] = 'You do not have permission to edit campaigns';
        $action = 'list';
    }
    
    if ($action === 'list'): ?>
    <!-- Redirect handled above -->
<?php else: ?>
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">
                <?php if ($action === 'edit' && isset($editCampaign['id'])): ?>
                    Edit Campaign #<?= (int)$editCampaign['id'] ?> (<?= htmlspecialchars($editCampaign['name']) ?>) <span style="font-size: 13px; font-weight: normal; color: #666; margin-left: 8px;">Key: <code><?= htmlspecialchars($editCampaign['campaign_key'] ?? '') ?></code></span>
                <?php elseif ($action === 'edit'): ?>
                    Edit Campaign
                <?php else: ?>
                    Create Campaign
                <?php endif; ?>
            </h2>
            <div style="display: flex; gap: 8px; align-items: center;">
                <a href="?page=campaigns" class="btn btn-secondary">← Back</a>
                <?php if ($action === 'edit' && isset($editCampaign['id'])): ?>
                    <a href="?page=campaign-stats&campaign_id=<?= (int)$editCampaign['id'] ?>" 
                       class="btn btn-secondary"
                       style="display: inline-flex; align-items: center; gap: 6px;">
                        📊 Stats
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <form method="post" action="?page=campaigns<?= $action === 'edit' && $id ? '&action=edit&id=' . (int)$id : ($action === 'add' ? '&action=add' : '') ?>" id="campaign-form">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="<?= htmlspecialchars($action) ?>">
                <?php if ($id): ?>
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <?php endif; ?>
                <!-- Main Settings Box -->
                <div style="background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%); 
                             border: 3px solid #3d5a26; 
                             border-radius: 12px; 
                             padding: 0; 
                             margin-bottom: 24px;
                             box-shadow: 0 4px 12px rgba(61, 90, 38, 0.15);">
                    <!-- Styled Header -->
                    <div style="background: linear-gradient(135deg, #3d5a26 0%, #558b2f 100%); 
                                padding: 16px 24px; 
                                border-radius: 9px 9px 0 0;
                                border-bottom: 2px solid #2d451f;">
                        <h3 style="margin: 0; color: #ffffff; font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 10px;">
                            <span style="font-size: 20px;">⚙️</span>
                            Main Settings
                        </h3>
                </div>

                    <!-- Settings Content -->
                    <div style="padding: 24px;">
                        <!-- Campaign Name & Default CPC Row -->
                        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 16px; margin-bottom: 20px;">
                            <div>
                                <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">
                                    Campaign Name <span style="color: #d32f2f;">*</span>
                                </label>
                                <input type="text" name="name" value="<?= htmlspecialchars($editCampaign['name'] ?? '') ?>" 
                                       required placeholder="e.g., FB Keto Campaign"
                                   style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-size: 14px;">
                            </div>
                            <div>
                                <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">Default CPC</label>
                                <?php
                                $editDefaultCpc = $editCampaign['default_cpc'] ?? null;
                                $editDefaultCpcValue = ($editDefaultCpc === null || $editDefaultCpc === '')
                                    ? ''
                                    : rtrim(rtrim(number_format((float) $editDefaultCpc, 6, '.', ''), '0'), '.');
                                ?>
                                <input type="number" name="default_cpc" step="any" min="0"
                                       value="<?= htmlspecialchars($editDefaultCpcValue) ?>"
                                       placeholder="0.00"
                                       style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-size: 14px;">
                                <div style="font-size: 12px; color: #666; margin-top: 4px;">Used when cost param not provided</div>
                            </div>
                        </div>

                        <!-- Tags Row -->
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">
                                Tags
                            </label>
                            <input type="text" name="tags" value="<?= htmlspecialchars($editCampaign['tags'] ?? '') ?>" 
                                   placeholder="e.g. sweeps, tier1, test (comma-separated)"
                                   style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-size: 14px;">
                            <div style="font-size: 12px; color: #666; margin-top: 4px;">Comma-separated tags for filtering and organizing</div>
                        </div>

                        <!-- Status, Group, Referrer privacy Row -->
                        <div class="campaign-settings-row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #e0e0e0;">
                            <div>
                                <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 13px; color: #333;">Status</label>
                                <select name="status" style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px; font-size: 14px;">
                                    <option value="active" <?= ($editCampaign['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="paused" <?= ($editCampaign['status'] ?? '') === 'paused' ? 'selected' : '' ?>>Paused</option>
                                    <option value="archived" <?= ($editCampaign['status'] ?? '') === 'archived' ? 'selected' : '' ?>>Archived</option>
                                </select>
                            </div>
                            <div>
                                <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 13px; color: #333;">Group (Optional)</label>
                                <select name="campaign_group_id" 
                                        style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px; font-size: 14px;">
                                    <option value="">No Group</option>
                                    <?php foreach ($campaignGroups as $group): ?>
                                        <option value="<?= $group['id'] ?>" <?= ($editCampaign['campaign_group_id'] ?? 0) == $group['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($group['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div style="font-size: 11px; color: #666; margin-top: 3px;">
                                    <a href="?page=settings&tab=campaign-groups" style="color: #3d5a26; text-decoration: none;">Manage Groups</a>
                                </div>
                            </div>
                            <?php $editReferrerMode = $editCampaign['referrer_mode'] ?? $editCampaign['cloaking_mode'] ?? ''; ?>
                            <div>
                                <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 13px; color: #333;">Referrer privacy</label>
                                <select name="referrer_mode" style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px; font-size: 14px;">
                                    <option value="" <?= $editReferrerMode === '' ? 'selected' : '' ?>>Standard redirect</option>
                                    <option value="blank" <?= $editReferrerMode === 'blank' ? 'selected' : '' ?>>Strip referrer (meta refresh)</option>
                                    <option value="noreferrer" <?= $editReferrerMode === 'noreferrer' ? 'selected' : '' ?>>No referrer header</option>
                                    <option value="double" <?= $editReferrerMode === 'double' ? 'selected' : '' ?>>Bear hop (two-step)</option>
                                </select>
                            </div>
                        </div>

                        <?php
                        $editCampaignSafe = is_array($editCampaign) ? $editCampaign : [];
                        $editEdgeEnabled = !empty($editCampaignSafe['edge_enabled']);
                        $edgeEligibility = \SimpleKuma\Edge\EdgeEligibility::evaluate(array_merge($editCampaignSafe, [
                            'edge_enabled' => true,
                            'status' => $editCampaignSafe['status'] ?? 'active',
                            'referrer_mode' => $editReferrerMode,
                            'redirectless_tracking' => !empty($editCampaignSafe['redirectless_tracking']),
                        ]));
                        $edgeSyncedAt = $editCampaignSafe['edge_synced_at'] ?? null;
                        $edgeSyncError = $editCampaignSafe['edge_sync_error'] ?? null;
                        ?>
                        <div class="campaign-edge-redirect-box" style="margin-bottom: 20px; padding: 14px 16px; border-radius: 6px;">
                            <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer; margin-bottom: 0;">
                                <input type="checkbox" name="edge_enabled" value="1" <?= $editEdgeEnabled ? 'checked' : '' ?>
                                       style="margin-top: 3px; width: 16px; height: 16px; flex-shrink: 0;">
                                <span>
                                    <strong class="edge-box-title" style="display: block; margin-bottom: 4px;">Edge redirect (Cloudflare Worker)</strong>
                                    <span class="edge-box-desc" style="font-size: 13px; line-height: 1.4; display: block;">
                                        Serve redirects from Cloudflare’s edge for much lower latency worldwide.
                                        Requires Edge Redirect setup under Settings. Phase 1 supports standard 302 only (no referrer privacy modes).
                                    </span>
                                </span>
                            </label>
                            <?php if ($action === 'edit' && $editEdgeEnabled): ?>
                                <div class="edge-box-status" style="margin-top: 10px; font-size: 12px;">
                                    <?php if (!$edgeEligibility['eligible']): ?>
                                        <div class="edge-status-ineligible" style="font-weight: 500;">Not eligible while enabled: <?= htmlspecialchars((string) $edgeEligibility['reason']) ?></div>
                                    <?php elseif ($edgeSyncError): ?>
                                        <div class="edge-status-error" style="font-weight: 500;">Last sync error: <?= htmlspecialchars((string) $edgeSyncError) ?></div>
                                    <?php elseif ($edgeSyncedAt): ?>
                                        <div class="edge-status-synced" style="font-weight: 500;">Last synced to edge: <?= htmlspecialchars((string) $edgeSyncedAt) ?> UTC</div>
                                    <?php else: ?>
                                        <div class="edge-status-waiting">Waiting for first edge sync…</div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <div style="margin-top: 8px; font-size: 12px;">
                                <a href="?page=settings&tab=edge-redirect" class="edge-box-link">Configure Edge Redirect →</a>
                            </div>
                        </div>

                        <!-- Traffic Source -->
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">
                                Traffic Source <span style="color:#d32f2f;">*</span>
                            </label>
                            <?php if ($isLegacyAutoDetectCampaign): ?>
                            <div style="background: #fff3e0; border: 1px solid #ff9800; border-radius: 6px; padding: 12px 14px; margin-bottom: 12px; font-size: 13px; color: #5d4037; line-height: 1.45;">
                                This campaign was using <strong>Kuma Auto Detected</strong>, which is no longer available. Select a specific traffic source before saving.
                            </div>
                            <?php endif; ?>
                            <select name="traffic_source_id" id="traffic_source_id" 
                                    onchange="updateTrackingLink(); toggleFacebookIntegration(); toggleGoogleAdsIntegration(); toggleTrafficSourceSelector();"
                                    style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-size: 14px;"
                                    aria-label="Select traffic source" required>
                                <?php if ($isLegacyAutoDetectCampaign): ?>
                                <option value="">Select traffic source...</option>
                                <?php endif; ?>
                                <?php foreach ($trafficSources as $ts):
                                    $isSelectable = TrafficSourceReleaseHelper::isSelectableForRelease($ts);
                                    $isFacebook = stripos($ts['name'], 'facebook') !== false;
                                    $isGoogle = TrafficSourceReleaseHelper::usesGoogleAdsIntegration($ts);
                                    $isSelected = ($editCampaign['traffic_source_id'] ?? 0) == $ts['id']
                                        || ($action === 'add' && $firstSelectableTrafficSource && (int)$firstSelectableTrafficSource['id'] === (int)$ts['id']);
                                ?>
                                    <option value="<?= $ts['id'] ?>" 
                                            data-tokens='<?= htmlspecialchars(json_encode($ts['tokens_json'] ?? [])) ?>'
                                            data-cost-param='<?= htmlspecialchars($ts['cost_param_key'] ?? '') ?>'
                                            data-is-facebook="<?= $isFacebook ? '1' : '0' ?>"
                                            data-is-google="<?= $isGoogle ? '1' : '0' ?>"
                                            <?= !$isSelectable ? ' disabled' : '' ?>
                                            <?= $isSelected ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ts['name']) ?><?= !$isSelectable ? ' (Coming soon)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div style="font-size: 12px; color: #666; margin-top: 4px; line-height: 1.45;">
                        Facebook, Google Ads, YouTube, or a custom source with manual cost in the URL.
                        Google/YouTube conversions use scheduled CSV import (Settings → Integrations). API cost sync is optional.
                    </div>
                </div>

                        <!-- Minimum payout to fire postbacks -->
                        <div style="margin-bottom: 20px; padding: 14px; background: #f9faf7; border: 1px solid #e0e6d8; border-radius: 6px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">Minimum payout to fire postbacks (optional)</label>
                            <?php
                            $editMinPostbackPayout = $editCampaign['min_postback_payout'] ?? null;
                            $editMinPostbackPayoutValue = ($editMinPostbackPayout === null || $editMinPostbackPayout === '')
                                ? ''
                                : rtrim(rtrim(number_format((float)$editMinPostbackPayout, 6, '.', ''), '0'), '.');
                            ?>
                            <input type="number" name="min_postback_payout" step="any" min="0"
                                   value="<?= htmlspecialchars($editMinPostbackPayoutValue) ?>"
                                   placeholder="No minimum — fire all postbacks"
                                   style="width:100%;max-width:280px;padding:10px;border:2px solid #ddd;border-radius:4px;">
                            <p style="font-size: 12px; color: #666; margin-top: 6px; line-height: 1.45;">
                                Optional. All conversions always appear in Kuma. When set, outbound postbacks only fire when value or payout meets this minimum.
                            </p>
                        </div>

                        <div style="margin-bottom: 20px; padding: 14px; background: #f9faf7; border: 1px solid #e0e6d8; border-radius: 6px;">
                            <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                                <input type="checkbox" name="allow_multiple_conversions" value="1"
                                       <?= !empty($editCampaign['allow_multiple_conversions']) ? 'checked' : '' ?>
                                       style="margin-top: 3px;">
                                <span>
                                    <span style="display: block; font-weight: 600; color: #333; margin-bottom: 4px;">Allow multiple conversions on the same click</span>
                                    <span style="display: block; font-size: 12px; color: #666; line-height: 1.45;">
                                        For networks like Propush that can send several payouts on one click ID.
                                        Prefer a unique <code>txid</code> when the network provides one. Same <code>txid</code>/<code>event_id</code> is still treated as a duplicate.
                                    </span>
                                </span>
                            </label>
                        </div>

                        <!-- Tracking Domain Selection -->
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">
                                Tracking Domain (Optional)
                            </label>
                            <select name="tracking_domain_id" 
                                    id="campaign-tracking-domain-select"
                                    onchange="updateChompJSCode()"
                                    style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-size: 14px;">
                                <option value="" data-domain-url="<?= htmlspecialchars(BASE_URL) ?>">Use Main Tracker Domain (<?= parse_url(BASE_URL, PHP_URL_HOST) ?>)</option>
                                <?php if (!empty($verifiedTrackingDomains)): ?>
                                    <option value="">─────────────────────────</option>
                                    <?php foreach ($verifiedTrackingDomains as $domain): ?>
                                        <?php $domainStatusLabel = ($domain['status'] ?? '') === 'verified_manual' ? ' (Manual)' : ''; ?>
                                        <option value="<?= $domain['id'] ?>" 
                                                data-domain-url="<?= htmlspecialchars($domain['domain']) ?>"
                                                <?= ($editCampaign['tracking_domain_id'] ?? null) == $domain['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($domain['domain']) ?><?= $domainStatusLabel ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <div style="font-size: 12px; color: #666; margin-top: 4px;">
                                Select a custom tracking domain to use for this campaign's tracking links. Only verified domains are shown.
                                <a href="?page=settings&tab=domains" target="_blank" style="color: #3d5a26;">Manage domains</a>
                            </div>
                        </div>

                        <!-- Facebook integrations (only visible when Facebook is selected) -->
                        <div id="facebook_integration_field" style="margin-bottom: 24px; display: none;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">
                                Facebook CAPI Integration (Optional)
                            </label>
                            <select name="facebook_capi_integration_id" id="facebook_capi_integration_id"
                                    style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-size: 14px;">
                                <option value="">No Facebook Integration</option>
                                <?php foreach ($facebookIntegrations as $fbIntegration): ?>
                                    <option value="<?= $fbIntegration['id'] ?>" 
                                            <?= ($editCampaign['facebook_capi_integration_id'] ?? null) == $fbIntegration['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($fbIntegration['name']) ?> (<?= htmlspecialchars($fbIntegration['pixel_id']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div style="font-size: 12px; color: #666; margin-top: 4px;">
                                Select a Facebook CAPI integration to use for this campaign. 
                                <a href="?page=settings&tab=integrations" target="_blank" style="color: #3d5a26;">Manage integrations</a>
                            </div>

                            <!-- Facebook Marketing Ad Account (cost tracking + Meta campaign linking) -->
                            <div style="margin-top: 20px;">
                                <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">
                                    Facebook Ad Account (For Cost Tracking)
                                </label>
                                <select name="facebook_marketing_ad_account_id" id="facebook_marketing_ad_account_id"
                                        style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-size: 14px;">
                                    <option value="">No Facebook Ad Account</option>
                                    <?php foreach ($allFacebookAdAccounts as $adAccount): ?>
                                        <option value="<?= $adAccount['id'] ?>" 
                                                <?= ($editCampaign['facebook_marketing_ad_account_id'] ?? null) == $adAccount['id'] ? 'selected' : '' ?>
                                                <?= ($adAccount['integration_status'] ?? 'active') !== 'active' ? 'style="color: #999;"' : '' ?>>
                                            <?= htmlspecialchars($adAccount['ad_account_name']) ?>
                                            <?= !empty($adAccount['ad_account_id']) ? ' (' . htmlspecialchars($adAccount['ad_account_id']) . ')' : '' ?>
                                            <?= !empty($adAccount['currency']) ? ' - ' . htmlspecialchars($adAccount['currency']) : '' ?>
                                            <?= !empty($adAccount['integration_name']) ? ' [' . htmlspecialchars($adAccount['integration_name']) . ']' : '' ?>
                                            <?= ($adAccount['integration_status'] ?? 'active') !== 'active' ? ' [Integration Paused]' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div style="font-size: 12px; color: #666; margin-top: 4px;">
                                    Select the specific Facebook ad account for this campaign. This ensures cost tracking queries the correct ad account. 
                                    <a href="?page=settings&tab=api-costs" target="_blank" style="color: #3d5a26;">Manage integrations</a>
                                </div>

                                <div id="facebook_meta_campaign_field" style="margin-top: 16px;">
                                    <div style="background: #f5f8f2; border: 1px solid #c5d4b8; border-radius: 6px; padding: 12px 14px; margin-bottom: 12px; font-size: 13px; color: #444; line-height: 1.45;">
                                        <strong style="color: #3d5a26;">Meta campaign for cost tracking</strong><br>
                                        Choose the Facebook/Meta campaign whose ad spend you want Kuma to pull into reports.
                                        Pick your ad account above first, click <strong>Refresh Meta campaigns</strong>, then select the matching campaign.
                                        Optional — leave blank to infer costs from clicks only (slower, less accurate on large ad accounts).
                                    </div>
                                    <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">
                                        Meta Campaign (optional)
                                    </label>
                                    <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 8px;">
                                        <button type="button" id="fb_refresh_meta_campaigns_btn" class="btn btn-secondary" style="padding: 8px 14px;">
                                            Refresh Meta campaigns
                                        </button>
                                        <span id="fb_meta_campaign_status" style="font-size: 12px; color: #666;"></span>
                                    </div>
                                    <select name="facebook_marketing_campaign_id" id="facebook_marketing_campaign_id"
                                            style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-size: 14px;" disabled>
                                        <option value="">Select ad account first</option>
                                    </select>
                                    <p style="font-size: 12px; color: #666; margin-top: 6px;">
                                        Only <strong>ACTIVE</strong> campaigns are listed. Sync pulls the latest from Meta for the selected ad account.
                                        <a href="?page=settings&tab=api-costs" target="_blank" style="color: #3d5a26;">Manage ad accounts</a>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Google Ads Integration Dropdown (only when Google/YouTube is selected) -->
                        <div id="google_ads_integration_field" style="margin-bottom: 24px; display: none;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">
                                Google Ads Integration (Optional)
                            </label>
                            <select name="google_ads_integration_id" id="google_ads_integration_id"
                                    style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-size: 14px;">
                                <option value="">No Google Ads Integration</option>
                                <?php foreach ($googleAdsIntegrations as $gaIntegration): ?>
                                    <option value="<?= (int)$gaIntegration['id'] ?>"
                                            <?= ($editCampaign['google_ads_integration_id'] ?? null) == $gaIntegration['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($gaIntegration['name']) ?>
                                        <?php if (!empty($gaIntegration['customer_id'])): ?>
                                            (<?= htmlspecialchars($gaIntegration['customer_id']) ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div style="font-size: 12px; color: #666; margin-top: 4px; line-height: 1.45;">
                                Optional. Link an integration for CSV/Data Manager import and/or API conversion upload. Cost sync uses the Google Ads API cost cron when credentials are configured.
                                <a href="?page=settings&tab=api-costs" target="_blank" style="color: #3d5a26;">Manage integrations</a>
                            </div>
                        </div>

                        <!-- Per-Traffic-Source Integration Selection (only visible for auto-detect campaigns) -->
                        <div id="traffic_source_postbacks_section" style="margin-bottom: 24px; display: none;">
                            <div style="background: #fff3e0; border: 2px solid #ff9800; border-radius: 6px; padding: 16px;">
                                <h4 style="margin: 0 0 12px 0; color: #e65100; font-size: 16px;">
                                    🎯 Integration Selection for Auto-Detect Campaign
                                </h4>
                                <p style="margin: 0 0 16px 0; color: #666; font-size: 12px; line-height: 1.5;">
                                    Select which integrations to use for each traffic source type. When a conversion occurs, Kuma will automatically use the correct integration based on the detected traffic source.
                                </p>
                                
                                <?php
                                // Load existing traffic source postback configs if editing
                                $existingTsPostbacks = [];
                                if ($action === 'edit' && $editCampaign && !empty($editCampaign['traffic_source_postbacks_json'])) {
                                    $existingTsPostbacks = is_array($editCampaign['traffic_source_postbacks_json']) 
                                        ? $editCampaign['traffic_source_postbacks_json'] 
                                        : json_decode($editCampaign['traffic_source_postbacks_json'], true) ?? [];
                                }
                                
                                // Group traffic sources by type for simpler UI
                                $trafficSourceGroups = [];
                                foreach ($trafficSources as $ts) {
                                    if (empty($ts['id'])) continue;
                                    $name = strtolower($ts['name'] ?? '');
                                    $group = 'other';
                                    if (strpos($name, 'facebook') !== false) $group = 'facebook';
                                    elseif (strpos($name, 'google') !== false && strpos($name, 'youtube') === false) $group = 'google';
                                    elseif (strpos($name, 'youtube') !== false) $group = 'youtube';
                                    elseif (strpos($name, 'bing') !== false) $group = 'bing';
                                    
                                    if (!isset($trafficSourceGroups[$group])) {
                                        $trafficSourceGroups[$group] = [];
                                    }
                                    $trafficSourceGroups[$group][] = $ts;
                                }
                                
                                // Show integration selectors for main traffic source types
                                $integrationGroups = [
                                    'facebook' => ['label' => 'Facebook', 'integrations' => $facebookIntegrations, 'type' => 'facebook_capi_integration_id'],
                                    'google' => ['label' => 'Google Ads', 'integrations' => $googleAdsIntegrations, 'type' => 'google_ads_integration_id'],
                                    'youtube' => ['label' => 'YouTube', 'integrations' => $googleAdsIntegrations, 'type' => 'google_ads_integration_id'],
                                    'bing' => ['label' => 'Bing', 'integrations' => [], 'type' => null], // Bing doesn't have integrations yet
                                ];
                                
                                foreach ($integrationGroups as $groupKey => $groupConfig):
                                    if (empty($groupConfig['integrations']) && $groupConfig['type'] !== null) continue;
                                    
                                    // Find traffic sources in this group
                                    $groupTrafficSources = $trafficSourceGroups[$groupKey] ?? [];
                                    if (empty($groupTrafficSources)) continue;
                                    
                                    // Use first traffic source ID as the key (they'll all use the same integration)
                                    $firstTsId = $groupTrafficSources[0]['id'];
                                    $tsConfig = $existingTsPostbacks[$firstTsId] ?? [];
                                ?>
                                <div style="margin-bottom: 12px;">
                                    <label style="display: block; font-weight: 600; margin-bottom: 6px; color: #333; font-size: 13px;">
                                        <?= htmlspecialchars($groupConfig['label']) ?> Integration
                                    </label>
                                    <?php if ($groupConfig['type'] === 'facebook_capi_integration_id'): ?>
                                        <select name="traffic_source_postbacks[<?= $firstTsId ?>][facebook_capi_integration_id]"
                                                style="width: 100%; max-width: 400px; padding: 8px; border: 2px solid #ddd; border-radius: 4px; font-size: 13px;">
                                            <option value="">None</option>
                                            <?php foreach ($groupConfig['integrations'] as $integration): ?>
                                                <option value="<?= $integration['id'] ?>" 
                                                        <?= ($tsConfig['facebook_capi_integration_id'] ?? null) == $integration['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($integration['name']) ?> (<?= htmlspecialchars($integration['pixel_id']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($groupConfig['type'] === 'google_ads_integration_id'): ?>
                                        <select name="traffic_source_postbacks[<?= $firstTsId ?>][google_ads_integration_id]"
                                                style="width: 100%; max-width: 400px; padding: 8px; border: 2px solid #ddd; border-radius: 4px; font-size: 13px;">
                                            <option value="">None</option>
                                            <?php foreach ($groupConfig['integrations'] as $integration): ?>
                                                <option value="<?= $integration['id'] ?>"
                                                        <?= ($tsConfig['google_ads_integration_id'] ?? null) == $integration['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($integration['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div style="font-size: 11px; color: #666; margin-top: 6px; line-height: 1.4;">
                                            Used for scheduled CSV conversion import. Configure the import URL under
                                            <a href="?page=settings&tab=integrations" style="color: #3d5a26;">Settings → Integrations</a>.
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                                
                                <div style="margin-top: 12px; padding: 10px; background: #fff3cd; border-radius: 4px; border-left: 3px solid #ff9800;">
                                    <p style="margin: 0; font-size: 11px; color: #856404; line-height: 1.4;">
                                        <strong>Note:</strong> Custom postbacks configured below will fire for all traffic sources. Use this section only to select platform-specific integrations (Facebook CAPI, Google Ads) per traffic source type.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Custom Postbacks — always available for every traffic source -->
                        <div id="custom_postbacks_section" style="margin-bottom: 24px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">
                                Custom Postbacks (Optional)
                            </label>
                            <?php if (!empty($allCustomPostbacks)): ?>
                            <div class="custom-postbacks-container" style="border: 2px solid #ddd; border-radius: 4px; padding: 12px; background: #fff; max-height: 200px; overflow-y: auto;">
                                <?php foreach ($allCustomPostbacks as $postback): ?>
                                    <label class="custom-postback-item" style="display: flex; align-items: flex-start; gap: 10px; padding: 10px; border-radius: 4px; cursor: pointer; transition: background 0.2s; margin-bottom: 4px;"
                                           onmouseover="this.style.background='#f5f5f5';"
                                           onmouseout="this.style.background='transparent';">
                                        <input type="checkbox" 
                                               name="custom_postback_ids[]" 
                                               value="<?= $postback['id'] ?>"
                                               <?= in_array($postback['id'], $selectedCustomPostbackIds) ? 'checked' : '' ?>
                                               style="margin-top: 2px; cursor: pointer; width: 18px; height: 18px; flex-shrink: 0;">
                                        <div class="custom-postback-content" style="flex: 1; min-width: 0;">
                                            <div style="font-weight: 500; color: #333; margin-bottom: 2px; word-wrap: break-word; overflow-wrap: break-word;">
                                                <?= htmlspecialchars($postback['name']) ?>
                                            </div>
                                            <?php if (!empty($postback['description'])): ?>
                                                <div style="font-size: 12px; color: #666; word-wrap: break-word; overflow-wrap: break-word;">
                                                    <?= htmlspecialchars($postback['description']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div style="font-size: 12px; color: #666; margin-top: 4px;">
                                Select one or more postbacks to fire when conversions occur for this campaign (any traffic source).
                                <a href="?page=settings&tab=integrations" target="_blank" style="color: #3d5a26;">Manage postbacks</a>
                            </div>
                            <?php else: ?>
                            <div style="border: 2px dashed #ddd; border-radius: 4px; padding: 14px; background: #fafafa; color: #666; font-size: 13px; line-height: 1.45;">
                                No custom postbacks yet. Create outbound postback URLs under
                                <a href="?page=settings&tab=integrations" target="_blank" style="color: #3d5a26;">Settings → Integrations</a>,
                                then attach them here — they work for every traffic source (including PropellerAds).
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Redirect Rules (Accordion) -->
                <details style="margin-bottom: 24px;">
                    <summary style="cursor: pointer; font-weight: 600; padding: 12px; background: #f9f9f9; border-radius: 4px; display: flex; align-items: center; gap: 8px; border: 2px solid #ddd;">
                        <img src="<?= ASSETS_BASE_URL ?>/assets/images/redirectrulesbear.png" alt="Redirect Rules" style="width: 20px; height: 20px;">
                        Redirect Rules (Optional)
                    </summary>
                    <div style="padding: 16px; border: 2px solid #ddd; border-top: none; border-radius: 0 0 4px 4px;">
                        <p style="font-size: 13px; color: #666; margin-bottom: 16px;">
                            Create rules to redirect traffic based on custom token values. Rules are checked in order - first matching rule triggers redirect.
                        </p>
                        
                        <div id="redirect_rules_container">
                            <?php
                            $editRedirectRules = [];
                            if ($action === 'edit' && $editCampaign && !empty($editCampaign['redirect_rules_json'])) {
                                $editRedirectRules = is_array($editCampaign['redirect_rules_json']) 
                                    ? $editCampaign['redirect_rules_json'] 
                                    : json_decode($editCampaign['redirect_rules_json'], true) ?? [];
                            }
                            // Get all available tokens for redirect rule dropdown
                            // 1. Custom campaign tokens
                            $customTokensForRules = [];
                            if ($action === 'edit' && $editCampaign && !empty($editCampaign['custom_tokens_json'])) {
                                $customTokensForRules = is_array($editCampaign['custom_tokens_json']) 
                                    ? $editCampaign['custom_tokens_json'] 
                                    : json_decode($editCampaign['custom_tokens_json'], true) ?? [];
                            }
                            
                            // 2. Built-in tracker tokens
                            $builtInTokensForRules = [
                                ['name' => 'Location (Country)', 'parameter' => 'country', 'source' => 'Built-in'],
                                ['name' => 'State/Region', 'parameter' => 'region', 'source' => 'Built-in'],
                                ['name' => 'City', 'parameter' => 'city', 'source' => 'Built-in'],
                                ['name' => 'Device', 'parameter' => 'device', 'source' => 'Built-in'],
                                ['name' => 'Device Brand', 'parameter' => 'device_brand', 'source' => 'Built-in'],
                                ['name' => 'Device Model', 'parameter' => 'device_model', 'source' => 'Built-in'],
                                ['name' => 'Operating System', 'parameter' => 'os', 'source' => 'Built-in'],
                                ['name' => 'OS Version', 'parameter' => 'os_version', 'source' => 'Built-in'],
                                ['name' => 'Browser', 'parameter' => 'browser', 'source' => 'Built-in'],
                                ['name' => 'Browser Version', 'parameter' => 'browser_version', 'source' => 'Built-in'],
                                ['name' => 'IP Address', 'parameter' => 'ip', 'source' => 'Built-in'],
                            ];
                            
                            // 3. Traffic source tokens
                            $trafficSourceTokensForRules = [];
                            // Get traffic source ID from edit campaign or from POST (for new campaigns)
                            $selectedTrafficSourceId = null;
                            if ($action === 'edit' && $editCampaign) {
                                $selectedTrafficSourceId = $editCampaign['traffic_source_id'] ?? null;
                            } elseif ($action === 'add' && isset($_POST['traffic_source_id'])) {
                                $selectedTrafficSourceId = !empty($_POST['traffic_source_id']) ? (int)$_POST['traffic_source_id'] : null;
                            }
                            $isAutoDetect = empty($selectedTrafficSourceId);
                            
                            if ($isAutoDetect) {
                                // Auto-detect: Get tokens from ALL traffic sources
                                foreach ($trafficSources as $ts) {
                                    $tsTokens = is_array($ts['tokens_json']) 
                                        ? $ts['tokens_json'] 
                                        : json_decode($ts['tokens_json'] ?? '[]', true) ?? [];
                                    foreach ($tsTokens as $tsToken) {
                                        if (!empty($tsToken['name']) && !empty($tsToken['parameter'])) {
                                            $trafficSourceTokensForRules[] = [
                                                'name' => $tsToken['name'],
                                                'parameter' => $tsToken['parameter'],
                                                'source' => $ts['name'] ?? 'Unknown Traffic Source'
                                            ];
                                        }
                                    }
                                }
                            } else {
                                // Specific traffic source: Get tokens from selected traffic source only
                                foreach ($trafficSources as $ts) {
                                    if ($ts['id'] == $selectedTrafficSourceId) {
                                        $tsTokens = is_array($ts['tokens_json']) 
                                            ? $ts['tokens_json'] 
                                            : json_decode($ts['tokens_json'] ?? '[]', true) ?? [];
                                        foreach ($tsTokens as $tsToken) {
                                            if (!empty($tsToken['name']) && !empty($tsToken['parameter'])) {
                                                $trafficSourceTokensForRules[] = [
                                                    'name' => $tsToken['name'],
                                                    'parameter' => $tsToken['parameter'],
                                                    'source' => $ts['name'] ?? 'Traffic Source'
                                                ];
                                            }
                                        }
                                        break;
                                    }
                                }
                            }
                            
                            // Combine all tokens for the dropdown
                            $allTokensForRules = [];
                            
                            // Add custom tokens
                            foreach ($customTokensForRules as $token) {
                                $allTokensForRules[] = [
                                    'name' => $token['name'] ?? '',
                                    'parameter' => $token['parameter'] ?? '',
                                    'source' => 'Custom Token'
                                ];
                            }
                            
                            // Add built-in tokens
                            foreach ($builtInTokensForRules as $token) {
                                $allTokensForRules[] = $token;
                            }
                            
                            // Add traffic source tokens
                            foreach ($trafficSourceTokensForRules as $token) {
                                $allTokensForRules[] = $token;
                            }
                            
                            foreach ($editRedirectRules as $idx => $rule): 
                                $rule = $rule ?? [];
                            ?>
                            <div class="redirect-rule-row" style="background: #f9f9f9; padding: 16px; border: 2px solid #ddd; border-radius: 4px; margin-bottom: 12px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                                    <strong style="color: #3d5a26;">Rule #<?= $idx + 1 ?></strong>
                                    <button type="button" class="btn btn-outline" onclick="removeRedirectRule(this)" 
                                            style="padding: 4px 8px; font-size: 12px; color: #d32f2f;">× Remove</button>
                        </div>
                                <div style="display: grid; grid-template-columns: 2fr 1.5fr 2fr 3fr; gap: 12px; margin-bottom: 12px;">
                                    <div>
                                        <label style="display: block; font-size: 12px; font-weight: 500; margin-bottom: 4px;">Token Name</label>
                                        <select name="redirect_rule_token[]"
                                                style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px;">
                                            <option value="">Select token...</option>
                                            <?php if (!empty($customTokensForRules)): ?>
                                                <optgroup label="Custom Tokens">
                                                    <?php foreach ($customTokensForRules as $token): ?>
                                                        <?php 
                                                        $tokenIdentifier = 'custom:' . htmlspecialchars($token['name']);
                                                        $savedTokenName = $rule['token_name'] ?? '';
                                                        $savedTokenSource = $rule['token_source'] ?? '';
                                                        // Match: exact source match, or backward compatibility (name match with no source = don't auto-select custom, prioritize built-in)
                                                        $isSelected = ($savedTokenSource === 'custom' && $savedTokenName === $token['name']);
                                                        ?>
                                                        <option value="<?= $tokenIdentifier ?>" 
                                                                <?= $isSelected ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($token['name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($builtInTokensForRules)): ?>
                                                <optgroup label="Built-in Tokens">
                                                    <?php foreach ($builtInTokensForRules as $token): ?>
                                                        <?php 
                                                        $tokenIdentifier = 'builtin:' . htmlspecialchars($token['name']);
                                                        $savedTokenName = $rule['token_name'] ?? '';
                                                        $savedTokenSource = $rule['token_source'] ?? '';
                                                        // Match: exact source match, or backward compatibility (name match with no source = assume built-in)
                                                        $isSelected = ($savedTokenSource === 'builtin' && $savedTokenName === $token['name']) 
                                                            || (empty($savedTokenSource) && $savedTokenName === $token['name']);
                                                        ?>
                                                        <option value="<?= $tokenIdentifier ?>" 
                                                                <?= $isSelected ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($token['name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($trafficSourceTokensForRules)): ?>
                                                <?php 
                                                // Group traffic source tokens by source name
                                                $groupedTsTokens = [];
                                                foreach ($trafficSourceTokensForRules as $token) {
                                                    $source = $token['source'] ?? 'Unknown';
                                                    if (!isset($groupedTsTokens[$source])) {
                                                        $groupedTsTokens[$source] = [];
                                                    }
                                                    $groupedTsTokens[$source][] = $token;
                                                }
                                                ?>
                                                <?php foreach ($groupedTsTokens as $sourceName => $tokens): ?>
                                                    <optgroup label="Traffic Source: <?= htmlspecialchars($sourceName) ?>">
                                                        <?php foreach ($tokens as $token): ?>
                                                            <?php 
                                                            $tokenIdentifier = 'traffic_source:' . htmlspecialchars($sourceName) . ':' . htmlspecialchars($token['name']);
                                                            $savedTokenName = $rule['token_name'] ?? '';
                                                            $savedTokenSource = $rule['token_source'] ?? '';
                                                            $expectedSource = 'traffic_source:' . htmlspecialchars($sourceName);
                                                            // Match: exact source and name match
                                                            $isSelected = ($savedTokenSource === $expectedSource && $savedTokenName === $token['name']);
                                                            ?>
                                                            <option value="<?= $tokenIdentifier ?>" 
                                                                    <?= $isSelected ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($token['name']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </optgroup>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </select>
                </div>
                                    <div>
                                        <label style="display: block; font-size: 12px; font-weight: 500; margin-bottom: 4px;">Operator</label>
                                        <select name="redirect_rule_operator[]"
                                                style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px;">
                                            <option value="equals" <?= ($rule['operator'] ?? '') === 'equals' ? 'selected' : '' ?>>Equals (=)</option>
                                            <option value="not_equals" <?= ($rule['operator'] ?? '') === 'not_equals' ? 'selected' : '' ?>>Not Equals (≠)</option>
                                            <option value="contains" <?= ($rule['operator'] ?? '') === 'contains' ? 'selected' : '' ?>>Contains</option>
                                            <option value="starts_with" <?= ($rule['operator'] ?? '') === 'starts_with' ? 'selected' : '' ?>>Starts With</option>
                                            <option value="ends_with" <?= ($rule['operator'] ?? '') === 'ends_with' ? 'selected' : '' ?>>Ends With</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label style="display: block; font-size: 12px; font-weight: 500; margin-bottom: 4px;">Value to Match</label>
                                        <input type="text" name="redirect_rule_value[]" 
                                               value="<?= htmlspecialchars($rule['value'] ?? '') ?>"
                                               style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px;"
                                               placeholder="Value">
                                    </div>
                                    <div>
                                        <label style="display: block; font-size: 12px; font-weight: 500; margin-bottom: 4px;">Redirect URL</label>
                                        <input type="url" name="redirect_rule_url[]" 
                                               value="<?= htmlspecialchars($rule['redirect_url'] ?? '') ?>"
                                               style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px;"
                                               placeholder="https://example.com">
                                    </div>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                                    <div>
                                        <label style="display: block; font-size: 12px; font-weight: 500; margin-bottom: 4px;">Case Sensitive</label>
                                        <select name="redirect_rule_case_sensitive[]"
                                                style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px;">
                                            <option value="0" <?= empty($rule['case_sensitive']) ? 'selected' : '' ?>>No (Default)</option>
                                            <option value="1" <?= !empty($rule['case_sensitive']) ? 'selected' : '' ?>>Yes</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label style="display: block; font-size: 12px; font-weight: 500; margin-bottom: 4px;">Execute On</label>
                                        <div style="display: flex; gap: 16px; margin-top: 8px;">
                                            <label style="display: flex; align-items: center; gap: 4px; cursor: pointer; font-size: 12px;">
                                                <input type="checkbox" name="redirect_rule_execute_on_<?= $idx ?>[]" 
                                                       value="campaign_click"
                                                       <?= !empty($rule['execute_on']) && in_array('campaign_click', $rule['execute_on']) ? 'checked' : '' ?>>
                                                Campaign Click
                                            </label>
                                            <label style="display: flex; align-items: center; gap: 4px; cursor: pointer; font-size: 12px;">
                                                <input type="checkbox" name="redirect_rule_execute_on_<?= $idx ?>[]" 
                                                       value="offer_click"
                                                       <?= !empty($rule['execute_on']) && in_array('offer_click', $rule['execute_on']) ? 'checked' : '' ?>>
                                                Offer Click
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn btn-outline" onclick="addRedirectRule()" id="add_rule_btn" 
                                style="margin-top: 12px;" 
                                aria-label="Add another redirect rule">
                            + Add Redirect Rule
                        </button>
                        <div style="font-size: 12px; color: #666; margin-top: 8px;">
                            💡 Rules are evaluated in order. First matching rule redirects traffic to the specified URL.
                        </div>
                    </div>
                </details>
                
                <!-- Initialize redirect rule tokens for JavaScript (outside details tag) -->
                <script>
                    // Make token data available to JavaScript for addRedirectRule function
                    window.redirectRuleTokens = {
                        custom: <?= json_encode(array_map(function($t) { return ['name' => $t['name'] ?? '', 'parameter' => $t['parameter'] ?? '']; }, $customTokensForRules ?? [])) ?>,
                        builtIn: <?= json_encode($builtInTokensForRules ?? []) ?>,
                        trafficSource: <?= json_encode($trafficSourceTokensForRules ?? []) ?>
                    };
                </script>

                <!-- Custom Campaign Tokens (Accordion) -->
                <details style="margin-bottom: 24px;">
                    <summary style="cursor: pointer; font-weight: 600; padding: 12px; background: #f9f9f9; border-radius: 4px; display: flex; align-items: center; gap: 8px; border: 2px solid #ddd;">
                        <img src="<?= ASSETS_BASE_URL ?>/assets/images/customtokensbear.png" alt="Custom Tokens" style="width: 20px; height: 20px;">
                        Custom Campaign Tokens (Optional)
                    </summary>
                    <div style="padding: 16px; border: 2px solid #ddd; border-top: none; border-radius: 0 0 4px 4px;">
                        <p style="font-size: 13px; color: #666; margin-bottom: 16px;">
                            Define custom tokens specific to this campaign. These are separate from traffic source tokens and can be used for rule-based redirects and analytics filtering.
                        </p>
                        
                        <!-- Table Header -->
                        <div class="custom-tokens-header desktop-only" style="display: grid; grid-template-columns: 60px 2fr 1.5fr 2fr 2.5fr 100px auto; gap: 12px; margin-bottom: 8px; padding: 8px; background: #f0f0f0; border-radius: 4px; font-size: 12px; font-weight: 600;">
                            <div>Token</div>
                            <div>Name</div>
                            <div>Parameter</div>
                            <div>Placeholder</div>
                            <div>URL Append</div>
                            <div style="text-align: center;">Pass To</div>
                            <div></div>
                        </div>
                        
                        <div id="custom_tokens_container">
                            <?php
                            $editCustomTokens = [];
                            if ($action === 'edit' && $editCampaign && !empty($editCampaign['custom_tokens_json'])) {
                                $editCustomTokens = is_array($editCampaign['custom_tokens_json']) 
                                    ? $editCampaign['custom_tokens_json'] 
                                    : json_decode($editCampaign['custom_tokens_json'], true) ?? [];
                            }
                            // Default to 3 rows, but show existing tokens if editing
                            $tokenCount = max(count($editCustomTokens), 3);
                            if ($tokenCount > 20) $tokenCount = 20;
                            
                            // Fill with existing tokens or empty placeholders
                            for ($i = 0; $i < $tokenCount; $i++) {
                                $token = $editCustomTokens[$i] ?? ['name' => '', 'parameter' => '', 'placeholder' => '', 'pass_to_lp' => false, 'pass_to_offer' => false];
                                $tokenNum = $i + 1;
                                $urlAppend = '';
                                if (!empty($token['parameter']) && !empty($token['placeholder'])) {
                                    $urlAppend = '&' . $token['parameter'] . '=' . $token['placeholder'];
                                }
                            ?>
                            <div class="custom-token-row" style="display: grid; grid-template-columns: 60px 2fr 1.5fr 2fr 2.5fr 100px auto; gap: 12px; margin-bottom: 8px; align-items: center;">
                                <div style="text-align: center; font-weight: 600; color: #666;">Token <?= $tokenNum ?></div>
                                <div>
                                    <input type="text" name="custom_token_name[]" 
                                           value="<?= htmlspecialchars($token['name'] ?? '') ?>"
                                           placeholder="Display name"
                                           maxlength="100"
                                           style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px; font-size: 13px;"
                                           aria-label="Token name">
                                </div>
                                <div>
                                    <input type="text" name="custom_token_parameter[]" 
                                           value="<?= htmlspecialchars($token['parameter'] ?? '') ?>"
                                           placeholder="e.g., sub1"
                                           maxlength="50"
                                           onchange="updateTokenUrlAppend(this); updateTrackingLink();"
                                           style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px; font-size: 13px;"
                                           aria-label="Parameter name">
                                </div>
                                <div>
                                    <input type="text" name="custom_token_placeholder[]" 
                                           value="<?= htmlspecialchars($token['placeholder'] ?? '') ?>"
                                           placeholder="e.g., {value}"
                                           maxlength="100"
                                           onchange="updateTokenUrlAppend(this); updateTrackingLink();"
                                           style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px; font-size: 13px;"
                                           aria-label="Placeholder">
                                </div>
                                <div>
                                    <input type="text" class="url-append-display" 
                                           value="<?= htmlspecialchars($urlAppend) ?>"
                                           readonly
                                           style="width: 100%; padding: 8px; background: #f9f9f9; border: 1px solid #ccc; border-radius: 4px; font-size: 12px; font-family: monospace; color: #3d5a26;"
                                           aria-label="URL append preview">
                                </div>
                                <div style="display: flex; gap: 8px; justify-content: center;">
                                    <label style="display: flex; align-items: center; gap: 4px; cursor: pointer; font-size: 12px;">
                                        <input type="checkbox" name="custom_token_pass_to_lp_<?= $i ?>[]" 
                                               value="1"
                                               <?= !empty($token['pass_to_lp']) ? 'checked' : '' ?>
                                               style="width: 16px; height: 16px;">
                                        LP
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 4px; cursor: pointer; font-size: 12px;">
                                        <input type="checkbox" name="custom_token_pass_to_offer_<?= $i ?>[]" 
                                               value="1"
                                               <?= !empty($token['pass_to_offer']) ? 'checked' : '' ?>
                                               style="width: 16px; height: 16px;">
                                        Offer
                                    </label>
                                </div>
                                <button type="button" class="btn btn-outline remove-token-btn" onclick="removeCustomTokenRow(this)" 
                                        style="padding: 6px 10px; font-size: 12px;" 
                                        aria-label="Remove token row">× Remove</button>
                            </div>
                            <?php } ?>
                        </div>
                        <button type="button" class="btn btn-outline" onclick="addCustomTokenRow()" id="add_token_btn" 
                                style="margin-top: 12px;" 
                                aria-label="Add another token row">
                            + Add Token
                        </button>
                        <div style="font-size: 12px; color: #666; margin-top: 8px;">
                            💡 Maximum 10 tokens. Parameter names must be unique. Use placeholders like {value} or {{custom_var}} for dynamic values.
                        </div>
                    </div>
                </details>


                <!-- Flow Type -->
                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">
                        Flow Type <span style="color: #d32f2f;">*</span>
                    </label>
                    <select name="flow_type" id="flow_type" required onchange="updateFlowFields()"
                            style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                        <option value="DTO" <?= ($editCampaign['flow_type'] ?? 'DTO') === 'DTO' ? 'selected' : '' ?>>
                            Direct to Offer (DTO)
                        </option>
                        <option value="LP" <?= ($editCampaign['flow_type'] ?? '') === 'LP' ? 'selected' : '' ?>>
                            Landing Page → Offer
                        </option>
                        <option value="Split" <?= ($editCampaign['flow_type'] ?? '') === 'Split' ? 'selected' : '' ?>>
                            Split Test
                        </option>
                    </select>
                </div>


                <!-- Unified Offer Rotation Section (Always Visible) -->
                <fieldset style="margin-bottom: 32px; background: linear-gradient(135deg, #f1f8e9 0%, #dcedc8 100%); padding: 20px; border-radius: 8px; border: 2px solid #8bc34a;">
                    <legend style="font-weight: 600; color: #33691e; display: flex; align-items: center; gap: 8px; padding: 0 8px;">
                        <img src="<?= ASSETS_BASE_URL ?>/assets/images/offers.png" alt="Offers" style="width: 20px; height: 20px;">
                        Offer Rotation (Weights must sum to 100%)
                        <button type="button" onclick="equalizeOfferWeights()" class="btn btn-outline" 
                                style="margin-left: auto; padding: 6px 16px; font-size: 13px; background: white; border-color: #8bc34a; color: #33691e; font-weight: 600;"
                                title="Distribute weights evenly">
                            = Equal Weights
                    </button>
                    </legend>
                    <div id="offer_rotation_items">
                        <?php
                        // Determine which offers to pre-populate based on campaign type
                        $editOffers = [];
                        if ($action === 'edit' && $editCampaign && !empty($editRotation)) {
                            // Debug: Log rotation for troubleshooting
                            error_log('Loading offers for edit - Flow type: ' . ($editCampaign['flow_type'] ?? 'unknown') . ', Rotation: ' . print_r($editRotation, true));
                            
                            if ($editCampaign['flow_type'] === 'DTO') {
                                // For DTO, offers are in rotation array directly (array of offer objects)
                                $editOffers = is_array($editRotation) && isset($editRotation[0]) && isset($editRotation[0]['id']) ? $editRotation : [];
                            } elseif ($editCampaign['flow_type'] === 'LP') {
                                // For LP, offers are in rotation['offers']
                                if (isset($editRotation['offers']) && is_array($editRotation['offers'])) {
                                    $editOffers = $editRotation['offers'];
                                }
                            } elseif ($editCampaign['flow_type'] === 'Split') {
                                // For Split: Check for offers in lp_path first (most common), then direct_path, then top-level
                                $lpPathOffers = $editRotation['lp_path']['offers'] ?? [];
                                $directPathOffers = $editRotation['direct_path']['offers'] ?? [];
                                $topLevelOffers = $editRotation['offers'] ?? [];
                                
                                // Use LP path offers if available (they're the same as direct path in unified system)
                                if (!empty($lpPathOffers)) {
                                    $editOffers = $lpPathOffers;
                                } elseif (!empty($directPathOffers)) {
                                    $editOffers = $directPathOffers;
                                } elseif (!empty($topLevelOffers)) {
                                    $editOffers = $topLevelOffers;
                                }
                            }
                            
                            // If still empty, ensure at least one row
                            if (empty($editOffers)) {
                                $editOffers = [['id' => '', 'weight' => 100]];
                            }
                        } else {
                            // New campaign - default to one empty row
                            $editOffers = [['id' => '', 'weight' => 100]];
                        }
                        
                        foreach ($editOffers as $idx => $editOffer): 
                            // Extract offer ID (handles both ['id'] and ['type' => 'offer', 'id'] formats)
                            $offerId = isset($editOffer['id']) ? (int)$editOffer['id'] : 0;
                            $offerWeight = isset($editOffer['weight']) ? (int)$editOffer['weight'] : 100;
                            $offerEnabled = !isset($editOffer['enabled']) || $editOffer['enabled'] !== false; // Default to enabled
                            
                            // Debug: Log what we're trying to match
                            if ($idx === 0 && $action === 'edit') {
                                error_log('Editing offer #' . $idx . ' - ID: ' . $offerId . ', Weight: ' . $offerWeight . ', Enabled: ' . ($offerEnabled ? 'yes' : 'no') . ', Full data: ' . print_r($editOffer, true));
                            }
                        ?>
                        <div class="offer-rotation-row" style="display: grid; grid-template-columns: auto 2fr 1fr auto; gap: 12px; margin-bottom: 8px; align-items: center;">
                            <!-- Hidden input is the source of truth (only this gets submitted) -->
                            <input type="hidden" name="offer_enabled[<?= $idx ?>]" id="offer_enabled_hidden_<?= $idx ?>" value="<?= $offerEnabled ? '1' : '0' ?>">
                            <label style="display: flex; align-items: center; cursor: pointer; white-space: nowrap;">
                                <input type="checkbox" id="offer_checkbox_<?= $idx ?>" <?= $offerEnabled ? 'checked' : '' ?> 
                                       style="width: 18px; height: 18px; margin-right: 6px; cursor: pointer;" 
                                       aria-label="Enable/disable offer">
                                <span style="font-size: 12px; color: #666;">Enable</span>
                            </label>
                            <!-- Do not use HTML disabled here: disabled controls are omitted from POST and
                                 reindex offer_id[]/offer_weight[] against offer_enabled[n], breaking weight validation. -->
                            <select name="offer_id[]" id="offer_select_<?= $idx ?>"
                                    style="padding: 8px; border: 2px solid #ddd; border-radius: 4px; <?= !$offerEnabled ? 'background: #f5f5f5; color: #999; cursor: not-allowed; pointer-events: none;' : '' ?>"
                                    <?= !$offerEnabled ? 'tabindex="-1" aria-disabled="true"' : '' ?>
                                    aria-label="Select offer">
                                <option value="">Select offer...</option>
                                <?php foreach ($offers as $off): ?>
                                    <option value="<?= $off['id'] ?>" <?= ($offerId > 0 && $offerId == $off['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($off['name']) ?> (<?= Formatter::formatCurrency($off['payout_value'], $userCurrency) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="number" name="offer_weight[]" id="offer_weight_<?= $idx ?>" placeholder="Weight %" min="0" max="100"
                                   value="<?= htmlspecialchars($offerWeight) ?>"
                                   <?= !$offerEnabled ? 'readonly' : '' ?>
                                   style="padding: 8px; border: 2px solid #ddd; border-radius: 4px; <?= !$offerEnabled ? 'background: #f5f5f5; color: #999; cursor: not-allowed;' : '' ?>"
                                   aria-label="Offer weight percentage">
                            <button type="button" class="btn btn-outline" onclick="addOfferRotationItem()" aria-label="Add another offer">
                                +
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="font-size: 12px; color: #666; margin-top: 8px;">
                        💡 All flow types use the same offer rotation. Add multiple offers to rotate (weights must sum to 100%).
                    </div>
                    <div style="margin-top: 16px;">
                        <label for="fallback_offer_id" style="font-weight: 600; color: #33691e;">Fallback Offer (optional)</label>
                        <select name="fallback_offer_id" id="fallback_offer_id" style="padding: 8px; border: 2px solid #ddd; border-radius: 4px; min-width: 280px; margin-top: 8px; display: block;">
                            <option value="">None</option>
                            <?php foreach ($offers as $off): ?>
                                <option value="<?= $off['id'] ?>" <?= ((int)($editCampaign['fallback_offer_id'] ?? 0) === (int)$off['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($off['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div style="font-size: 12px; color: #666; margin-top: 6px;">Used when no enabled offer in rotation is available.</div>
                    </div>
                </fieldset>

                <!-- LP: Landing Page Rotation Only (Blue Box) -->
                <!-- Also shown for Split flow type (uses same LPs) -->
                <div id="lp_fields" style="margin-bottom: 32px; display: <?= in_array($editCampaign['flow_type'] ?? '', ['LP', 'Split']) ? 'block' : 'none' ?>;">
                    <fieldset style="background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%); padding: 20px; border-radius: 8px; border: 2px solid #2196F3;">
                        <legend style="font-weight: 600; color: #0d47a1; display: flex; align-items: center; gap: 8px; padding: 0 8px;">
                            <img src="<?= ASSETS_BASE_URL ?>/assets/images/landingpages.png" alt="Landing Pages" style="width: 20px; height: 20px;">
                            Landing Page Rotation (Weights must sum to 100%)
                            <button type="button" onclick="equalizeLPWeights()" class="btn btn-outline" 
                                    style="margin-left: auto; padding: 6px 16px; font-size: 13px; background: white; border-color: #2196F3; color: #2196F3; font-weight: 600;"
                                    title="Distribute weights evenly">
                                = Equal Weights
                            </button>
                        </legend>
                        <div id="lp_items">
                            <?php 
                            // Load landing pages based on flow type
                            $editLPs = [];
                            if (!empty($editCampaign) && isset($editCampaign['flow_type'])) {
                                if ($editCampaign['flow_type'] === 'LP' && !empty($editRotation) && isset($editRotation['landing_pages'])) {
                                    // LP flow: LPs are in rotation['landing_pages']
                                    $editLPs = is_array($editRotation['landing_pages']) ? $editRotation['landing_pages'] : [];
                                } elseif ($editCampaign['flow_type'] === 'Split' && !empty($editRotation) && isset($editRotation['lp_path']['landing_pages'])) {
                                    // Split flow: LPs are in rotation['lp_path']['landing_pages']
                                    $editLPs = is_array($editRotation['lp_path']['landing_pages']) ? $editRotation['lp_path']['landing_pages'] : [];
                                }
                            }
                            if (empty($editLPs)) {
                                $editLPs = [['id' => '', 'weight' => 100]];
                            }
                            foreach ($editLPs as $idx => $editLP): 
                            ?>
                            <?php 
                            // Check enabled state - default to true if not set (backward compatibility)
                            $lpEnabled = !isset($editLP['enabled']) || $editLP['enabled'] === true || $editLP['enabled'] === 1 || $editLP['enabled'] === '1';
                            ?>
                            <div style="display: grid; grid-template-columns: auto 2fr 1fr auto; gap: 12px; margin-bottom: 8px; align-items: center;">
                                <!-- Hidden input is the source of truth (only this gets submitted) -->
                                <input type="hidden" name="lp_enabled[<?= $idx ?>]" id="lp_enabled_hidden_<?= $idx ?>" value="<?= $lpEnabled ? '1' : '0' ?>">
                                <label style="display: flex; align-items: center; cursor: pointer; white-space: nowrap;">
                                    <input type="checkbox" id="lp_checkbox_<?= $idx ?>" <?= $lpEnabled ? 'checked' : '' ?> 
                                           style="width: 18px; height: 18px; margin-right: 6px; cursor: pointer;" 
                                           aria-label="Enable/disable landing page">
                                    <span style="font-size: 12px; color: #666;">Enable</span>
                                </label>
                                <select name="lp_id[]" id="lp_select_<?= $idx ?>"
                                        style="padding: 8px; border: 2px solid #ddd; border-radius: 4px; <?= !$lpEnabled ? 'background: #f5f5f5; color: #999; cursor: not-allowed; pointer-events: none;' : '' ?>"
                                        <?= !$lpEnabled ? 'tabindex="-1" aria-disabled="true"' : '' ?>
                                        aria-label="Select landing page">
                                    <option value="">Select landing page...</option>
                                    <?php 
                                    // Extract LP ID from the stored structure
                                    $currentLpId = isset($editLP['id']) ? (int)$editLP['id'] : 0;
                                    foreach ($landingPages as $lp): ?>
                                        <option value="<?= $lp['id'] ?>" <?= ($currentLpId > 0 && $currentLpId == $lp['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($lp['name']) ?> (ID: <?= $lp['id'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="number" name="lp_weight[]" id="lp_weight_<?= $idx ?>" placeholder="Weight %" min="0" max="100" 
                                       value="<?= htmlspecialchars(isset($editLP['weight']) ? (int)$editLP['weight'] : 100) ?>"
                                       <?= !$lpEnabled ? 'readonly' : '' ?>
                                       style="padding: 8px; border: 2px solid #ddd; border-radius: 4px; <?= !$lpEnabled ? 'background: #f5f5f5; color: #999; cursor: not-allowed;' : '' ?>"
                                       aria-label="Landing page weight percentage">
                                <button type="button" class="btn btn-outline" onclick="addLPItem()" aria-label="Add another landing page">+</button>
                            </div>
                    <?php endforeach; ?>
                        </div>
                        <div style="font-size: 12px; color: #666; margin-top: 8px;">
                            💡 Make sure your LPs include the click tracker script
                        </div>
                    </fieldset>
                </div>

                <!-- Split: Split percentage configuration -->
                <div id="split_fields" style="margin-bottom: 32px; display: <?= ($editCampaign['flow_type'] ?? '') === 'Split' ? 'block' : 'none' ?>;">
                    <!-- Split Percentage -->
                    <div style="background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%); padding: 16px; border-radius: 8px; border: 2px solid #ff9800; margin-bottom: 16px;">
                        <label style="display: block; font-weight: 600; margin-bottom: 12px; color: #e65100;">
                            ⚖️ Split Test: LP Path vs Direct Path
                        </label>
                        <div style="display: grid; grid-template-columns: auto auto auto; gap: 12px; align-items: center; background: rgba(255,255,255,0.5); padding: 12px; border-radius: 4px;">
                            <strong style="color: #e65100;">Traffic to LP Path:</strong>
                            <?php
                            $splitLPPercent = 50; // Default
                            if ($action === 'edit' && $editCampaign && $editCampaign['flow_type'] === 'Split' && isset($editRotation['split_traffic']['lp_percent'])) {
                                $splitLPPercent = (int)$editRotation['split_traffic']['lp_percent'];
                            }
                            ?>
                            <input type="number" name="split_traffic_to_lp" placeholder="%" min="0" max="100" value="<?= $splitLPPercent ?>"
                                   id="split_traffic_to_lp_input"
                                   oninput="updateSplitPercentage()"
                                   style="width: 80px; padding: 8px; border: 2px solid #e65100; border-radius: 4px; font-weight: 600;">
                            <span id="split_remaining_percent" style="color: #666; font-size: 14px;">
                                (<?= 100 - $splitLPPercent ?>% Direct)
                            </span>
                        </div>
                        <div style="font-size: 12px; color: #666; margin-top: 8px;">
                            LP Path uses Landing Pages above → then Offers above. Direct Path goes straight to Offers above.
                        </div>
                    </div>
                </div>

                <!-- Multi-Slug Management Section -->
                <details style="margin-bottom: 24px;">
                    <summary style="cursor: pointer; font-weight: 600; padding: 12px; background: #f9f9f9; border-radius: 4px; display: flex; align-items: center; gap: 8px; border: 2px solid #ddd;">
                        <span style="font-size: 18px;">🔗</span>
                        Campaign Slugs (Optional)
                    </summary>
                    <div style="padding: 16px; border: 2px solid #ddd; border-top: none; border-radius: 0 0 4px 4px;">
                        <p style="font-size: 13px; color: #666; margin-bottom: 16px;">
                            Create multiple tracking slugs for this campaign to scale across multiple ad accounts and traffic sources. Each slug routes to the same campaign but provides unique tracking links for different ad accounts or traffic sources. Slugs must be unique across all campaigns and can only contain letters, numbers, hyphens, and underscores.
                        </p>
                        
                        <div id="slug-items-container">
                            <?php if (empty($existingSlugs)): ?>
                                <!-- Default empty row -->
                                <div class="slug-row" style="display: grid; grid-template-columns: 2fr 3fr auto; gap: 12px; margin-bottom: 12px; align-items: center;">
                                    <div>
                                        <label style="display: block; font-weight: 600; margin-bottom: 4px; font-size: 13px;">Slug <span style="color: #d32f2f;">*</span></label>
                                        <input type="text" name="slug[]" class="slug-input" placeholder="e.g., fb-ad-1" 
                                               pattern="[a-zA-Z0-9_-]+" 
                                               style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px; font-family: monospace; font-size: 13px;"
                                               oninput="validateSlugInput(this)">
                                        <div class="slug-error" style="font-size: 11px; color: #d32f2f; margin-top: 4px; display: none;"></div>
                                    </div>
                                    <div>
                                        <label style="display: block; font-weight: 600; margin-bottom: 4px; font-size: 13px;">Label <span style="color: #d32f2f;">*</span></label>
                                        <input type="text" name="slug_label[]" class="slug-label-input" placeholder="e.g., Facebook Ad Account 1" 
                                               style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px; font-size: 13px;">
                                    </div>
                                    <div style="display: flex; gap: 8px; align-items: flex-end;">
                                        <button type="button" class="btn btn-outline" onclick="addSlugRow()" style="padding: 8px 16px; font-size: 13px;">+ Add</button>
                                    </div>
                                </div>
                            <?php else: ?>
                                <!-- Existing slugs -->
                                <?php foreach ($existingSlugs as $idx => $slug): ?>
                                <div class="slug-row" style="display: grid; grid-template-columns: 2fr 3fr auto; gap: 12px; margin-bottom: 12px; align-items: center;">
                                    <input type="hidden" name="slug_id[]" value="<?= (int)$slug['id'] ?>">
                                    <div>
                                        <label style="display: block; font-weight: 600; margin-bottom: 4px; font-size: 13px;">Slug <span style="color: #d32f2f;">*</span></label>
                                        <input type="text" name="slug[]" class="slug-input" value="<?= htmlspecialchars($slug['slug']) ?>" 
                                               pattern="[a-zA-Z0-9_-]+" 
                                               style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px; font-family: monospace; font-size: 13px;"
                                               oninput="validateSlugInput(this)">
                                        <div class="slug-error" style="font-size: 11px; color: #d32f2f; margin-top: 4px; display: none;"></div>
                                    </div>
                                    <div>
                                        <label style="display: block; font-weight: 600; margin-bottom: 4px; font-size: 13px;">Label <span style="color: #d32f2f;">*</span></label>
                                        <input type="text" name="slug_label[]" class="slug-label-input" value="<?= htmlspecialchars($slug['slug_label']) ?>" 
                                               placeholder="e.g., Facebook Ad Account 1" 
                                               style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px; font-size: 13px;">
                                    </div>
                                    <div style="display: flex; gap: 8px; align-items: flex-end;">
                                        <button type="button" class="btn btn-outline" onclick="addSlugRow()" style="padding: 8px 16px; font-size: 13px;">+ Add</button>
                                        <button type="button" class="btn btn-outline" onclick="removeSlugRow(this)" style="padding: 8px 16px; font-size: 13px; background: #ffebee; border-color: #d32f2f; color: #d32f2f;">Remove</button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </details>

                <div style="display: flex; gap: 12px;">
                    <button type="submit" class="btn btn-primary" name="save_campaign" value="1" onclick="console.log('Submit button clicked'); return true;">
                        <?= $action === 'edit' ? '💾 Update' : '✨ Create' ?> Campaign
                    </button>
                    <a href="?page=campaigns" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
            <script>
                // Slug management functions
                function addSlugRow() {
                    const container = document.getElementById('slug-items-container');
                    if (!container) return;
                    
                    const existingRows = container.querySelectorAll('.slug-row');
                    const firstRow = existingRows[0];
                    if (!firstRow) return;
                    
                    const newRow = firstRow.cloneNode(true);
                    
                    // Reset form fields
                    const slugInput = newRow.querySelector('input[name="slug[]"]');
                    const labelInput = newRow.querySelector('input[name="slug_label[]"]');
                    const errorDiv = newRow.querySelector('.slug-error');
                    const hiddenIdInput = newRow.querySelector('input[name="slug_id[]"]');
                    
                    if (slugInput) {
                        slugInput.value = '';
                        slugInput.style.borderColor = '#ddd';
                    }
                    if (labelInput) labelInput.value = '';
                    if (errorDiv) {
                        errorDiv.textContent = '';
                        errorDiv.style.display = 'none';
                    }
                    if (hiddenIdInput) hiddenIdInput.remove();
                    
                    // Update button - ensure Remove button exists
                    const buttonContainer = newRow.querySelector('div[style*="display: flex"]');
                    if (buttonContainer) {
                        const removeBtn = buttonContainer.querySelector('button[onclick*="removeSlugRow"]');
                        if (!removeBtn) {
                            const addBtn = buttonContainer.querySelector('button[onclick*="addSlugRow"]');
                            if (addBtn) {
                                const removeButton = document.createElement('button');
                                removeButton.type = 'button';
                                removeButton.className = 'btn btn-outline';
                                removeButton.textContent = 'Remove';
                                removeButton.onclick = function() { removeSlugRow(this); };
                                removeButton.style.cssText = 'padding: 8px 16px; font-size: 13px; background: #ffebee; border-color: #d32f2f; color: #d32f2f;';
                                addBtn.parentNode.insertBefore(removeButton, addBtn.nextSibling);
                            }
                        }
                    }
                    
                    container.appendChild(newRow);
                }
                
                function removeSlugRow(button) {
                    const row = button.closest('.slug-row');
                    if (row) {
                        row.remove();
                    }
                }
                
                function validateSlugInput(input) {
                    const slug = input.value.trim();
                    const errorDiv = input.parentElement.querySelector('.slug-error');
                    
                    if (!errorDiv) return;
                    
                    // Reset border
                    input.style.borderColor = '#ddd';
                    errorDiv.style.display = 'none';
                    errorDiv.textContent = '';
                    
                    if (slug === '') {
                        return; // Empty is OK (will be validated on submit)
                    }
                    
                    // Check format
                    if (!/^[a-zA-Z0-9_-]+$/.test(slug)) {
                        input.style.borderColor = '#d32f2f';
                        errorDiv.textContent = 'Slug can only contain letters, numbers, hyphens, and underscores.';
                        errorDiv.style.display = 'block';
                        return;
                    }
                    
                    // Check length
                    if (slug.length > 50) {
                        input.style.borderColor = '#d32f2f';
                        errorDiv.textContent = 'Slug cannot exceed 50 characters.';
                        errorDiv.style.display = 'block';
                        return;
                    }
                    
                    // Check reserved routes
                    const reserved = ['c', 'api', 'admin', 'install', 'public', 'track', 'lp'];
                    if (reserved.includes(slug.toLowerCase())) {
                        input.style.borderColor = '#d32f2f';
                        errorDiv.textContent = 'This slug is reserved and cannot be used.';
                        errorDiv.style.display = 'block';
                        return;
                    }
                    
                    // Check for duplicates within the form (client-side check)
                    const container = document.getElementById('slug-items-container');
                    if (container) {
                        const allSlugInputs = container.querySelectorAll('input[name="slug[]"]');
                        const currentRow = input.closest('.slug-row');
                        const currentSlugId = currentRow ? (currentRow.querySelector('input[name="slug_id[]"]')?.value || null) : null;
                        
                        let duplicateFound = false;
                        allSlugInputs.forEach(otherInput => {
                            if (otherInput === input) return; // Skip self
                            
                            const otherRow = otherInput.closest('.slug-row');
                            const otherSlugId = otherRow ? (otherRow.querySelector('input[name="slug_id[]"]')?.value || null) : null;
                            
                            // If it's the same slug and not the same row (or one is new), it's a duplicate
                            if (otherInput.value.trim().toLowerCase() === slug.toLowerCase()) {
                                // If both are existing slugs with same ID, it's the same slug (not duplicate)
                                if (currentSlugId && otherSlugId && currentSlugId === otherSlugId) {
                                    return; // Same slug, same row
                                }
                                duplicateFound = true;
                            }
                        });
                        
                        if (duplicateFound) {
                            input.style.borderColor = '#d32f2f';
                            errorDiv.textContent = 'This slug is already used in another row. Each slug must be unique.';
                            errorDiv.style.display = 'block';
                            return;
                        }
                    }
                }
                
                // Validate all slugs before form submission
                function validateAllSlugs() {
                    const container = document.getElementById('slug-items-container');
                    if (!container) return true;
                    
                    const allSlugInputs = container.querySelectorAll('input[name="slug[]"]');
                    const slugs = new Set();
                    let hasErrors = false;
                    
                    allSlugInputs.forEach(input => {
                        const slug = input.value.trim();
                        if (slug === '') return; // Skip empty slugs
                        
                        // Check for duplicates
                        if (slugs.has(slug.toLowerCase())) {
                            input.style.borderColor = '#d32f2f';
                            const errorDiv = input.parentElement.querySelector('.slug-error');
                            if (errorDiv) {
                                errorDiv.textContent = 'This slug is duplicated. Each slug must be unique.';
                                errorDiv.style.display = 'block';
                            }
                            hasErrors = true;
                        } else {
                            slugs.add(slug.toLowerCase());
                        }
                    });
                    
                    return !hasErrors;
                }
                
                // Validate slugs before form submission
                document.addEventListener('DOMContentLoaded', function() {
                    const form = document.getElementById('campaign-form');
                    if (form) {
                        form.addEventListener('submit', function(e) {
                            if (!validateAllSlugs()) {
                                e.preventDefault();
                                alert('Please fix duplicate slug errors before submitting.');
                                return false;
                            }
                        });
                    }
                });
            </script>
        </div>
    </div>

    <?php if ($action === 'edit' && $editCampaign): ?>
    <!-- Tracking Link Display -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title" style="display: flex; align-items: center; gap: 8px;">
                <img src="<?= ASSETS_BASE_URL ?>/assets/images/trackinglinkbear.png" alt="Tracking Link" style="width: 24px; height: 24px;">
                Your Tracking Links
            </h2>
        </div>
        <div class="card-body">
            <div style="background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%); border-left: 4px solid #4caf50; border-radius: 6px; padding: 14px 16px; margin-bottom: 16px; box-shadow: 0 2px 4px rgba(76, 175, 80, 0.1);">
                <p style="margin: 0; color: #2e7d32; font-size: 15px; font-weight: 700; line-height: 1.5;">
                    <span style="font-size: 18px; margin-right: 8px;">🔗</span>
                    <strong>USE THESE LINKS IN YOUR TRAFFIC SOURCE</strong> (Facebook, Google, etc.)
                </p>
                <p style="margin: 8px 0 0 0; color: #388e3c; font-size: 14px; font-weight: 500; line-height: 1.4;">
                    <?php if (!empty($existingSlugs)): ?>
                        Multiple slugs available for this campaign. Each slug routes to the same campaign but allows you to differentiate traffic sources.
                    <?php else: ?>
                        Default tracking link (using campaign key). Add slugs in the campaign settings above to create multiple tracking links.
                    <?php endif; ?>
                </p>
            </div>
            
            <?php
            // Get campaign slugs
            $campaignSlugs = $campaignSlug->getByCampaignId($editCampaign['id']);
            $campaignKey = $editCampaign['campaign_key'] ?? $editCampaign['id'];
            $baseUrl = Formatter::getCampaignBaseUrl($editCampaign);
            
            if (!empty($campaignSlugs)): ?>
                <!-- Slug Selector Dropdown -->
                <div style="margin-bottom: 24px; padding: 16px; background: #f9f9f9; border: 2px solid #ddd; border-radius: 8px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 10px; color: #333; font-size: 14px;">
                        Select Campaign Slug:
                    </label>
                    <div style="position: relative;">
                        <select id="campaign-slug-selector" 
                                onchange="updateTrackingLinkOnSlugChange()"
                                style="width: 100%; padding: 12px 40px 12px 16px; border: 2px solid #4caf50; border-radius: 6px; font-size: 14px; background: #fff; cursor: pointer; appearance: none; -webkit-appearance: none; -moz-appearance: none; color: #333; font-weight: 500; box-shadow: 0 2px 4px rgba(76, 175, 80, 0.15); transition: all 0.2s;" 
                                onfocus="this.style.borderColor='#3d5a26'" 
                                onblur="this.style.borderColor='#4caf50'">
                            <option value="" data-slug="">Default (Campaign Key: <?= htmlspecialchars($campaignKey) ?>)</option>
                            <?php foreach ($campaignSlugs as $slug): ?>
                                <option value="<?= (int)$slug['id'] ?>" 
                                        data-slug="<?= htmlspecialchars($slug['slug'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-slug-label="<?= htmlspecialchars($slug['slug_label'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($slug['slug_label']) ?> (<?= htmlspecialchars($slug['slug']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div style="position: absolute; right: 14px; top: 50%; transform: translateY(-50%); pointer-events: none; color: #4caf50; font-size: 18px; font-weight: bold;">
                            ▼
                        </div>
                    </div>
                    <p style="font-size: 12px; color: #666; margin: 8px 0 0 0;">
                        Select a slug to use in the tracking link generator below. Each slug routes to the same campaign but allows you to differentiate traffic sources.
                    </p>
                </div>
            <?php endif; ?>
            
            <!-- Traffic Source Selector for Link Generation (show/hide dynamically based on campaign traffic source) -->
            <div id="link-traffic-source-selector-container" style="background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%); border: 2px solid #ff9800; border-radius: 8px; padding: 20px; margin-bottom: 24px; <?= !empty($editCampaign['traffic_source_id']) ? 'display: none;' : '' ?>">
                <p style="margin: 0 0 12px 0; color: #e65100; font-weight: 600; font-size: 15px; display: flex; align-items: center; gap: 8px;">
                    <img src="<?= ASSETS_BASE_URL ?>/assets/images/autodetectbear.png" alt="Auto Detected" style="width: 20px; height: 20px; object-fit: contain; vertical-align: middle;">
                    Select Traffic Source for Link Generation
                </p>
                <p style="margin: 0 0 16px 0; color: #bf360c; font-size: 13px; line-height: 1.5;">
                    This campaign uses auto-detect mode. Select a traffic source below to generate a link with the appropriate parameters. The link will update automatically when you make a selection.
                </p>
                <div style="display: flex; gap: 12px; align-items: center;">
                    <select id="link-traffic-source-select" 
                            onchange="updateTrackingLinkWithTrafficSource()"
                            style="flex: 1; padding: 10px; border: 2px solid #ff9800; border-radius: 4px; font-size: 14px; background: #fff;">
                        <option value="">-- Select Traffic Source --</option>
                        <?php foreach ($trafficSources as $ts): ?>
                            <option value="<?= $ts['id'] ?>"
                                    data-tokens='<?= htmlspecialchars(json_encode($ts['tokens_json'] ?? [])) ?>'
                                    data-cost-param='<?= htmlspecialchars($ts['cost_param_key'] ?? '') ?>'
                                    data-ts-name="<?= htmlspecialchars($ts['name'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($ts['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div style="background: #fff; border: 2px solid #e0e0e0; border-radius: 8px; padding: 20px; margin-bottom: 24px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                <div style="display: flex; justify-content: space-between; align-items: center; gap: 16px;">
                    <code id="full-tracking-url" style="font-size: 14px; color: #333; word-break: break-all; flex: 1; white-space: pre-wrap; font-family: 'Courier New', monospace; background: #f5f5f5; padding: 12px; border-radius: 4px; border: 1px solid #ddd;">
                        <?= htmlspecialchars(BASE_URL) ?>/km/<?= htmlspecialchars($editCampaign['campaign_key'] ?? $editCampaign['id']) ?>
                    </code>
                    <button id="copy-url-btn" onclick="copyFullTrackingUrl()" class="btn btn-primary" style="white-space: nowrap; padding: 12px 24px; background: #4caf50; border: none; border-radius: 6px; color: #fff; font-weight: 600; cursor: pointer; transition: all 0.2s; box-shadow: 0 2px 4px rgba(76, 175, 80, 0.3);" aria-label="Copy full tracking URL" onmouseover="this.style.background='#3d5a26'; this.style.boxShadow='0 3px 6px rgba(61, 90, 38, 0.4)'" onmouseout="this.style.background='#4caf50'; this.style.boxShadow='0 2px 4px rgba(76, 175, 80, 0.3)'">
                        📋 Copy
                    </button>
                </div>
            </div>
            
            <?php /* Hidden: cloaker CTA retired from campaign editor */ if (false): ?>
            <!-- Cloaking Option -->
            <div style="background: #e7f3ff; border: 2px solid #2196F3; border-radius: 6px; padding: 16px; margin-bottom: 24px;">
                <p style="margin: 0 0 12px 0; color: #004085; font-weight: 600; font-size: 14px; display: flex; align-items: center; gap: 8px;">
                    <span>🔒</span>
                    Optional: Cloak Your Campaign Link
                </p>
                <p style="margin: 0 0 12px 0; color: #004085; font-size: 13px; line-height: 1.6;">
                    Want to hide your tracking URL from visitors? Use Simple KUMA's <strong>Short Links</strong> feature to generate a cloaked redirect file. 
                    Upload it to your server and use the cloaked URL in your traffic source (Facebook, Google, etc.) instead of the tracking link above.
                </p>
                <a href="<?= APP_BASE_URL ?>/index.php?page=short-links" 
                   style="display: inline-block; padding: 8px 16px; background: #2196F3; color: white; text-decoration: none; border-radius: 4px; font-size: 13px; font-weight: 600;">
                    ✨ Create Cloaked Redirect →
                </a>
            </div>
            <?php endif; ?>

            <!-- CTA Configuration Guide (Only show for LP and Split flows, not DTO) -->
            <?php if (!empty($editCampaign['flow_type']) && $editCampaign['flow_type'] !== 'DTO'): ?>
            <div style="background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border: 2px solid #3d5a26; border-radius: 8px; padding: 20px; margin-top: 24px;">
                <h3 style="margin: 0 0 16px 0; color: #3d5a26; font-size: 16px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                    <span>🔗</span>
                    Configure Your Landing Page CTA
                </h3>
                
                <div style="margin-bottom: 20px;">
                    <p style="color: #333; margin-bottom: 12px; font-weight: 600; font-size: 14px;">Step 1: Add Simple KUMA's Click Tracker to Your Landing Page</p>
                    <div style="background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px; padding: 12px; margin-bottom: 12px;">
                        <p style="margin: 0; color: #856404; font-size: 12px; line-height: 1.5;">
                            <strong>For regular redirect tracking</strong> (when visitors click your <code>/km/</code> tracking link first). If you use <strong>Redirectless Tracking</strong> below, skip Steps 1–2 — that snippet already includes everything.
                        </p>
                    </div>
                    <div style="background: #ffffff; border: 1px solid #ddd; border-radius: 4px; padding: 12px; margin-bottom: 12px;">
                        <code id="chomp-js-code-display" style="font-size: 13px; color: #3d5a26; word-break: break-all;">&lt;script src="<?= htmlspecialchars(Formatter::getCampaignBaseUrl($editCampaign)) ?>/chomp.js"&gt;&lt;/script&gt;</code>
                        <button id="copy-chomp-js-btn" onclick="copyChompJSCode()" 
                                style="margin-left: 8px; padding: 4px 12px; font-size: 12px; background: #3d5a26; color: white; border: none; border-radius: 4px; cursor: pointer;">
                            📋 Copy
                        </button>
                    </div>
                    <p style="font-size: 12px; color: #666; margin: 0;">Add this script tag to your landing page HTML, typically in the <code>&lt;head&gt;</code> or before the closing <code>&lt;/body&gt;</code> tag.</p>
                </div>

                <div style="margin-bottom: 20px;">
                    <p style="color: #333; margin-bottom: 12px; font-weight: 600; font-size: 14px;">Step 2: Configure Your CTA Button</p>
                    <div style="background: #ffffff; border: 1px solid #ddd; border-radius: 4px; padding: 12px; margin-bottom: 12px;">
                        <code style="font-size: 13px; color: #3d5a26; display: block; white-space: pre-wrap;">&lt;a href="#" onclick="kTrack(); return false;"&gt;
    Click Here
&lt;/a&gt;</code>
                        <button onclick="copyToClipboard('&lt;a href=&quot;#&quot; onclick=&quot;kTrack(); return false;&quot;&gt;\n    Click Here\n&lt;/a&gt;')" 
                                style="margin-top: 8px; padding: 4px 12px; font-size: 12px; background: #3d5a26; color: white; border: none; border-radius: 4px; cursor: pointer;">
                            📋 Copy
                        </button>
                    </div>
                    <p style="font-size: 12px; color: #666; margin: 0;">Replace your CTA button with this code. The <code>kTrack()</code> function will automatically track the click and redirect to your offer.</p>
                </div>

                <?php
                // Show redirectless tracking option for LP/Split flows
                $showRedirectless = in_array($editCampaign['flow_type'] ?? '', ['LP', 'Split']);
                if ($showRedirectless):
                    $campaignIdForRedirectless = $editCampaign['id'];
                    
                    // Get landing page IDs from rotation
                    $landingPageIdsForRedirectless = [];
                    if (!empty($editRotation)) {
                        if ($editCampaign['flow_type'] === 'LP' && isset($editRotation['landing_pages'])) {
                            foreach ($editRotation['landing_pages'] as $lp) {
                                if (!empty($lp['id'])) {
                                    $landingPageIdsForRedirectless[] = $lp['id'];
                                }
                            }
                        } elseif ($editCampaign['flow_type'] === 'Split' && isset($editRotation['lp_path']['landing_pages'])) {
                            foreach ($editRotation['lp_path']['landing_pages'] as $lp) {
                                if (!empty($lp['id'])) {
                                    $landingPageIdsForRedirectless[] = $lp['id'];
                                }
                            }
                        }
                    }
                ?>
                <!-- Optional: Redirectless Tracking -->
                <details style="margin-top: 24px;">
                    <summary style="cursor: pointer; font-weight: 600; padding: 14px 16px; background: linear-gradient(135deg, #2e7d32 0%, #4caf50 100%); border-radius: 8px; display: flex; align-items: center; gap: 8px; border: 2px solid #3d5a26; color: #fff; font-size: 15px; box-shadow: 0 2px 4px rgba(61, 90, 38, 0.2);">
                        <span>📡</span>
                        Optional: Redirectless Tracking (For Google Ads & Similar)
                    </summary>
                    <div style="padding: 24px; border: 2px solid #e0e0e0; border-top: none; border-radius: 0 0 8px 8px; background: #fafafa;">
                        <div style="background: #e8f5e9; border-left: 4px solid #4caf50; border-radius: 4px; padding: 16px; margin-bottom: 20px;">
                            <p style="margin: 0 0 8px 0; color: #2e7d32; font-size: 14px; line-height: 1.6;">
                                <strong>When to use:</strong> Some platforms like Google Ads don't allow redirect tracking links. 
                                With redirectless tracking, traffic goes <strong>directly to your landing page URL</strong>, 
                                and the tracking code below captures visitor data on the page itself.
                            </p>
                            <p style="margin: 0 0 8px 0; color: #388e3c; font-size: 13px; line-height: 1.5;">
                                Use your landing page URL directly in your traffic source (no tracking link). Copy the <strong>complete JavaScript snippet below</strong> — it includes visit tracking, CTA click tracking (<code>chomp.js</code>), and the CTA button markup. You do not need Steps 1–2 above.
                            </p>
                            <p style="margin: 0; color: #388e3c; font-size: 13px; line-height: 1.5;">
                                <strong>Same LP for redirect rotation:</strong> You can keep this code on pages that also receive traffic from the campaign redirect link (for LP rotation). If the visitor already has a <code>click_id</code> from the redirect, Kuma reuses it and does <strong>not</strong> create a second visit.
                            </p>
                        </div>

                        <?php if (!empty($landingPageIdsForRedirectless)): ?>
                        <!-- Landing Page Selector -->
                        <div style="margin-bottom: 24px; padding-bottom: 16px;">
                            <label style="display: block; color: #333; margin-bottom: 10px; font-weight: 600; font-size: 14px;">
                                Select Landing Page (Code will update automatically):
                            </label>
                            <div style="position: relative;">
                                <select id="redirectless-lp-selector" onchange="updateRedirectlessCode()" style="width: 100%; padding: 12px 40px 12px 16px; border: 2px solid #4caf50; border-radius: 6px; font-size: 14px; background: #fff; cursor: pointer; appearance: none; -webkit-appearance: none; -moz-appearance: none; color: #333; font-weight: 500; box-shadow: 0 2px 4px rgba(76, 175, 80, 0.15); transition: all 0.2s;" onfocus="this.style.borderColor='#3d5a26'" onblur="this.style.borderColor='#4caf50'">
                                    <option value="">-- Select a landing page --</option>
                                <?php 
                                // Get full LP data for dropdown (including URLs)
                                $redirectlessLPData = [];
                                if ($editCampaign['flow_type'] === 'LP' && isset($editRotation['landing_pages'])) {
                                    foreach ($editRotation['landing_pages'] as $lp) {
                                        if (!empty($lp['id'])) {
                                            // Find LP name and URL from landingPages array
                                            foreach ($landingPages as $fullLP) {
                                                if ($fullLP['id'] == $lp['id']) {
                                                    $redirectlessLPData[] = [
                                                        'id' => $lp['id'], 
                                                        'name' => $fullLP['name'],
                                                        'url' => $fullLP['url'] ?? ''
                                                    ];
                                                    break;
                                                }
                                            }
                                        }
                                    }
                                } elseif ($editCampaign['flow_type'] === 'Split' && isset($editRotation['lp_path']['landing_pages'])) {
                                    foreach ($editRotation['lp_path']['landing_pages'] as $lp) {
                                        if (!empty($lp['id'])) {
                                            foreach ($landingPages as $fullLP) {
                                                if ($fullLP['id'] == $lp['id']) {
                                                    $redirectlessLPData[] = [
                                                        'id' => $lp['id'], 
                                                        'name' => $fullLP['name'],
                                                        'url' => $fullLP['url'] ?? ''
                                                    ];
                                                    break;
                                                }
                                            }
                                        }
                                    }
                                }
                                foreach ($redirectlessLPData as $lpData): 
                                ?>
                                    <option value="<?= $lpData['id'] ?>" data-lp-id="<?= $lpData['id'] ?>" data-lp-name="<?= htmlspecialchars($lpData['name']) ?>" data-lp-url="<?= htmlspecialchars($lpData['url'] ?? '') ?>">
                                        <?= htmlspecialchars($lpData['name']) ?> (ID: <?= $lpData['id'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div style="position: absolute; right: 14px; top: 50%; transform: translateY(-50%); pointer-events: none; color: #4caf50; font-size: 18px; font-weight: bold;">
                                ▼
                            </div>
                        </div>
                        
                        <?php if (!empty($verifiedTrackingDomains)): ?>
                        <!-- Tracking Domain Selector for Redirectless -->
                        <div style="margin-bottom: 24px; padding-top: 16px;">
                            <label style="display: block; color: #333; margin-bottom: 10px; font-weight: 600; font-size: 14px;">
                                Select Tracking Domain (Optional):
                            </label>
                            <div style="position: relative;">
                                <select id="redirectless-tracking-domain-select" 
                                        onchange="updateRedirectlessCode()"
                                        style="width: 100%; padding: 12px 40px 12px 16px; border: 2px solid #4caf50; border-radius: 6px; font-size: 14px; background: #fff; cursor: pointer; appearance: none; -webkit-appearance: none; -moz-appearance: none; color: #333; font-weight: 500; box-shadow: 0 2px 4px rgba(76, 175, 80, 0.15); transition: all 0.2s;" 
                                        onfocus="this.style.borderColor='#3d5a26'" 
                                        onblur="this.style.borderColor='#4caf50'">
                                    <option value="">Default (<?= htmlspecialchars(parse_url(BASE_URL, PHP_URL_HOST) ?: 'Current Domain') ?>)</option>
                                    <?php foreach ($verifiedTrackingDomains as $domain): ?>
                                        <?php $domainStatusLabel = ($domain['status'] ?? '') === 'verified_manual' ? 'Manual' : 'Verified'; ?>
                                        <option value="<?= htmlspecialchars($domain['domain']) ?>" data-domain-id="<?= $domain['id'] ?>">
                                            <?= htmlspecialchars($domain['domain']) ?> (<?= $domainStatusLabel ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div style="position: absolute; right: 14px; top: 50%; transform: translateY(-50%); pointer-events: none; color: #4caf50; font-size: 18px; font-weight: bold;">
                                    ▼
                                </div>
                            </div>
                            <p style="font-size: 12px; color: #666; margin: 8px 0 0 0;">
                                Used for the JavaScript/pixel tracking snippets only. The Direct Campaign Link always uses your landing page URL.
                            </p>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Traffic Source Selector for Redirectless Link -->
                        <div style="margin-bottom: 24px; padding-top: 16px;">
                            <label style="display: block; color: #333; margin-bottom: 10px; font-weight: 600; font-size: 14px;">
                                Select Traffic Source (for token parameters):
                            </label>
                            <div style="position: relative;">
                                <select id="redirectless-traffic-source-select" 
                                        onchange="updateRedirectlessCode()"
                                        style="width: 100%; padding: 12px 40px 12px 16px; border: 2px solid #4caf50; border-radius: 6px; font-size: 14px; background: #fff; cursor: pointer; appearance: none; -webkit-appearance: none; -moz-appearance: none; color: #333; font-weight: 500; box-shadow: 0 2px 4px rgba(76, 175, 80, 0.15); transition: all 0.2s;" 
                                        onfocus="this.style.borderColor='#3d5a26'" 
                                        onblur="this.style.borderColor='#4caf50'">
                                    <option value="">-- Select Traffic Source --</option>
                                    <?php 
                                    // Get traffic sources for dropdown (same as main link generator)
                                    $redirectlessTrafficSources = $trafficSource->getAll();
                                    foreach ($redirectlessTrafficSources as $ts): 
                                        $tsTokens = $ts['tokens_json'] ?? [];
                                        if (is_string($tsTokens)) {
                                            $tsTokens = json_decode($tsTokens, true) ?? [];
                                        }
                                    ?>
                                        <option value="<?= $ts['id'] ?>"
                                                data-tokens='<?= htmlspecialchars(json_encode($tsTokens)) ?>'
                                                data-cost-param='<?= htmlspecialchars($ts['cost_param_key'] ?? '') ?>'
                                                data-ts-name="<?= htmlspecialchars($ts['name'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars($ts['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div style="position: absolute; right: 14px; top: 50%; transform: translateY(-50%); pointer-events: none; color: #4caf50; font-size: 18px; font-weight: bold;">
                                    ▼
                                </div>
                            </div>
                            <p style="font-size: 12px; color: #666; margin: 8px 0 0 0;">
                                Select a traffic source to append its tracking tokens to the direct link. Custom campaign tokens will be added after.
                            </p>
                        </div>
                        
                        <!-- Direct Campaign Link Display -->
                        <div id="redirectless-direct-link-container" style="margin-bottom: 24px; display: none;">
                            <label style="display: block; color: #333; margin-bottom: 10px; font-weight: 600; font-size: 14px;">
                                Direct Campaign Link (Use this URL in your traffic source):
                            </label>
                            <div style="background: #fff; border: 2px solid #4caf50; border-radius: 8px; padding: 16px; box-shadow: 0 2px 4px rgba(76, 175, 80, 0.15);">
                                <div style="display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap;">
                                    <code id="redirectless-direct-link" style="font-size: 13px; color: #333; word-break: break-all; flex: 1; white-space: pre-wrap; font-family: 'Courier New', monospace; background: #f5f5f5; padding: 12px; border-radius: 4px; border: 1px solid #ddd; min-width: 200px;">
                                        Select a landing page to generate link
                                    </code>
                                    <button id="copy-redirectless-direct-link-btn" onclick="copyRedirectlessDirectLink()" style="white-space: nowrap; padding: 12px 24px; background: #4caf50; border: none; border-radius: 6px; color: #fff; font-weight: 600; cursor: pointer; transition: all 0.2s; box-shadow: 0 2px 4px rgba(76, 175, 80, 0.3);" onmouseover="this.style.background='#3d5a26'; this.style.boxShadow='0 3px 6px rgba(61, 90, 38, 0.4)'" onmouseout="this.style.background='#4caf50'; this.style.boxShadow='0 2px 4px rgba(76, 175, 80, 0.3)'">
                                        📋 Copy
                                    </button>
                                </div>
                            </div>
                            <p style="font-size: 12px; color: #666; margin: 8px 0 0 0;">
                                This is your landing page URL with all tracking parameters. Use this directly in your traffic source (Google Ads, Facebook, etc.) instead of the tracking link.
                            </p>
                        </div>
                    </div>
<?php endif; ?>

                        <!-- JavaScript Version -->
                        <div style="margin-bottom: 20px;">
                            <p style="color: #333; margin-bottom: 12px; font-weight: 600; font-size: 14px;">Complete Redirectless Code (Preferred - works on any page):</p>
                            <div style="background: #fff; border: 2px solid #e0e0e0; border-radius: 8px; padding: 20px; margin-bottom: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                                <div class="redirectless-code-container" style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px;">
                                    <code id="redirectless-js-code" class="redirectless-code" style="font-size: 13px; color: #333; word-break: break-all; flex: 1; white-space: pre-wrap; font-family: 'Courier New', monospace; background: #f5f5f5; padding: 12px; border-radius: 4px; border: 1px solid #ddd;">
&lt;!-- Visit tracking --&gt;
&lt;script&gt;
var kumaConfig = { "root": "<?= htmlspecialchars(BASE_URL) ?>/", };
&lt;/script&gt;
&lt;script src="<?= htmlspecialchars(APP_BASE_URL) ?>/redirectless.js"&gt;&lt;/script&gt;
&lt;script&gt;kumaTrack(<?= $campaignIdForRedirectless ?>, LP_ID);&lt;/script&gt;
&lt;!-- CTA click tracking + offer redirect --&gt;
&lt;script src="<?= htmlspecialchars(APP_BASE_URL) ?>/chomp.js"&gt;&lt;/script&gt;

&lt;!-- Use this markup (or onclick) on your CTA button --&gt;
&lt;a href="#" onclick="kTrack(); return false;"&gt;Click Here&lt;/a&gt;</code>
                                    <button id="copy-redirectless-js-btn" class="redirectless-copy-btn btn btn-primary" onclick="copyRedirectlessJSCode()" style="white-space: nowrap; padding: 12px 24px; background: #4caf50; border: none; border-radius: 6px; color: #fff; font-weight: 600; cursor: pointer; transition: all 0.2s; box-shadow: 0 2px 4px rgba(76, 175, 80, 0.3);" aria-label="Copy JavaScript redirectless tracking code" onmouseover="this.style.background='#3d5a26'; this.style.boxShadow='0 3px 6px rgba(61, 90, 38, 0.4)'" onmouseout="this.style.background='#4caf50'; this.style.boxShadow='0 2px 4px rgba(76, 175, 80, 0.3)'">
                                        📋 Copy
                                    </button>
                                </div>
                            </div>
                            <p style="font-size: 12px; color: #666; margin: 0;">Paste the scripts before the closing <code>&lt;/body&gt;</code> tag and put <code>onclick="kTrack(); return false;"</code> on your CTA. Select a landing page above to auto-populate the LP ID. This one block is all you need for redirectless. Safe to leave on LPs that also get redirect traffic — <code>kumaTrack()</code> skips creating a new click when <code>click_id</code> is already in the URL.</p>
                        </div>

                        <!-- PHP Pixel Version -->
                        <div style="margin-bottom: 20px;">
                            <p style="color: #333; margin-bottom: 12px; font-weight: 600; font-size: 14px;">PHP Pixel (Only if your page is .php):</p>
                            <div style="background: #fff; border: 2px solid #e0e0e0; border-radius: 8px; padding: 20px; margin-bottom: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                                <div class="redirectless-code-container" style="display: flex; justify-content: space-between; align-items: center; gap: 16px;">
                                    <code id="redirectless-php-code" class="redirectless-code" style="font-size: 13px; color: #333; word-break: break-all; flex: 1; white-space: pre-wrap; font-family: 'Courier New', monospace; background: #f5f5f5; padding: 12px; border-radius: 4px; border: 1px solid #ddd;">
&lt;?php if (empty($_GET['click_id'])): ?&gt;
&lt;img src="<?= htmlspecialchars(APP_BASE_URL) ?>/track.php?c=<?= $campaignIdForRedirectless ?>&amp;l=LP_ID&amp;&lt;?php echo htmlspecialchars($_SERVER['QUERY_STRING']); ?&gt;" 
     width="1" height="1" style="display:none;"&gt;
&lt;?php endif; ?&gt;</code>
                                    <button id="copy-redirectless-php-btn" class="redirectless-copy-btn btn btn-primary" onclick="copyRedirectlessPHPCode()" style="white-space: nowrap; padding: 12px 24px; background: #4caf50; border: none; border-radius: 6px; color: #fff; font-weight: 600; cursor: pointer; transition: all 0.2s; box-shadow: 0 2px 4px rgba(76, 175, 80, 0.3);" aria-label="Copy PHP redirectless tracking code" onmouseover="this.style.background='#3d5a26'; this.style.boxShadow='0 3px 6px rgba(61, 90, 38, 0.4)'" onmouseout="this.style.background='#4caf50'; this.style.boxShadow='0 2px 4px rgba(76, 175, 80, 0.3)'">
                                        📋 Copy
                                    </button>
                                </div>
                            </div>
                            <p style="font-size: 12px; color: #666; margin: 0;">For PHP pages only. Select a landing page above to auto-populate the LP ID.</p>
                        </div>

                        <?php /* Hidden: server-side PHP tracking snippet not yet tested */ if (false): ?>
                        <!-- PHP Server-Side Version (Google-Safe) -->
                        <div style="margin-bottom: 0;">
                            <p style="color: #333; margin-bottom: 12px; font-weight: 600; font-size: 14px;">PHP Server-Side (Recommended for Google Ads - No JavaScript Required):</p>
                            <div style="background: #fff; border: 2px solid #e0e0e0; border-radius: 8px; padding: 20px; margin-bottom: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                                <div style="background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px; padding: 12px; margin-bottom: 12px;">
                                    <p style="margin: 0; color: #856404; font-size: 12px; line-height: 1.5;">
                                        <strong>✅ Google-Safe & JavaScript-Free:</strong> This method runs server-side before any HTML output, completely avoiding JavaScript callouts that Google flags. Your landing page will have <strong>zero JavaScript</strong> - tracking happens via simple links. Paste the code at the <strong>very top</strong> of your PHP landing page, before any HTML or whitespace.
                                    </p>
                                </div>
                                <div class="redirectless-code-container" style="display: flex; justify-content: space-between; align-items: center; gap: 16px;">
                                    <code id="redirectless-server-php-code" class="redirectless-code" style="font-size: 13px; color: #333; word-break: break-all; flex: 1; white-space: pre-wrap; font-family: 'Courier New', monospace; background: #f5f5f5; padding: 12px; border-radius: 4px; border: 1px solid #ddd;">
&lt;?php
// Place this code at the VERY TOP of your PHP landing page (before any HTML)
$kumaTrackingUrl = "<?= htmlspecialchars(APP_BASE_URL) ?>/track.php";
$campaignId = <?= $campaignIdForRedirectless ?>;
$landingPageId = LP_ID; // Replace LP_ID with your actual landing page ID

// Already arrived via campaign redirect — reuse click_id, skip track.php
$kumaClickId = null;
if (!empty($_GET['click_id'])) {
    $kumaClickId = (string)$_GET['click_id'];
    setcookie('kuma_click_id', $kumaClickId, time() + 3600, '/');
} else {
    // Collect all URL parameters
    $params = $_GET;
    $params['c'] = $campaignId;
    $params['l'] = $landingPageId;
    $params['server_side'] = 1;

    // Forward visitor data
    $params['visitor_ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
    $params['visitor_ua'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $params['visitor_referrer'] = $_SERVER['HTTP_REFERER'] ?? '';

    // Make server-to-server tracking call
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $kumaTrackingUrl . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Parse response and store click_id in PHP variable (and cookie as backup)
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['success']) && $data['success'] && isset($data['click_id'])) {
            $kumaClickId = $data['click_id'];
            setcookie('kuma_click_id', $kumaClickId, time() + 3600, '/'); // Cookie as backup
        }
    }
}
?&gt;</code>
                                    <button id="copy-redirectless-server-php-btn" class="redirectless-copy-btn btn btn-primary" onclick="copyRedirectlessServerPHPCode()" style="white-space: nowrap; padding: 12px 24px; background: #ff9800; border: none; border-radius: 6px; color: #fff; font-weight: 600; cursor: pointer; transition: all 0.2s; box-shadow: 0 2px 4px rgba(255, 152, 0, 0.3);" aria-label="Copy PHP server-side redirectless tracking code" onmouseover="this.style.background='#f57c00'; this.style.boxShadow='0 3px 6px rgba(245, 124, 0, 0.4)'" onmouseout="this.style.background='#ff9800'; this.style.boxShadow='0 2px 4px rgba(255, 152, 0, 0.3)'">
                                        📋 Copy
                                    </button>
                                </div>
                            </div>
                            <p style="font-size: 12px; color: #666; margin: 0;">Paste this code at the <strong>very top</strong> of your PHP landing page (before any HTML). Select a landing page above to auto-populate the LP ID.</p>
                            
                            <!-- Simple CTA Link Instructions -->
                            <div style="background: #e8f5e9; border-left: 4px solid #4caf50; border-radius: 4px; padding: 16px; margin-top: 16px;">
                                <p style="margin: 0 0 12px 0; color: #2e7d32; font-size: 13px; font-weight: 600;">✅ How to Use (No JavaScript Required):</p>
                                
                                <div style="background: #fff; border: 1px solid #4caf50; border-radius: 4px; padding: 12px; margin: 8px 0;">
                                    <code style="font-size: 12px; color: #2e7d32; font-family: 'Courier New', monospace; display: block; white-space: pre-wrap;">
&lt;?php
$trackerUrl = "<?= htmlspecialchars(Formatter::getCampaignBaseUrl($editCampaign)) ?>/lp/click.php";
// Get click_id from multiple sources (in order of priority):
// 1) URL parameter (if already in URL from redirect)
// 2) PHP variable set by tracking code ($kumaClickId)
// 3) Cookie (backup)
$clickId = $_GET['click_id'] ?? $kumaClickId ?? $_COOKIE['kuma_click_id'] ?? '';
?&gt;
&lt;a href="&lt;?php echo htmlspecialchars($trackerUrl); ?&gt;?click_id=&lt;?php echo htmlspecialchars($clickId); ?&gt;"&gt;Click Here&lt;/a&gt;
                                    </code>
                                </div>
                                
                                <p style="margin: 8px 0 0 0; color: #2e7d32; font-size: 11px; line-height: 1.5;">
                                    <strong>Important:</strong> Make sure your landing page file has a <code>.php</code> extension (not <code>.html</code>) for PHP code to execute. The <code>$kumaClickId</code> variable is available after the tracking code runs. The tracker domain will automatically use your selected tracking domain (or default if none selected).
                                </p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </details>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <script>
        // Copy to clipboard helper for code snippets
        function copyToClipboard(text, buttonElement) {
            // Decode HTML entities for actual copy
            const textarea = document.createElement('textarea');
            textarea.value = text.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"');
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            
            // Show feedback
            const btn = buttonElement || event.target;
            const originalText = btn.innerHTML;
            const originalBg = btn.style.background;
            btn.innerHTML = '✓ Copied!';
            btn.style.background = '#28a745';
            setTimeout(() => {
                btn.innerHTML = originalText;
                btn.style.background = originalBg || '#3d5a26';
            }, 2000);
        }
    
        // Store base tracking URL - use custom domain if available
        const campaignBaseUrl = <?= json_encode(Formatter::getCampaignBaseUrl($editCampaign)) ?>;
        const baseTrackingUrl = campaignBaseUrl + '/km/<?= htmlspecialchars($editCampaign['campaign_key'] ?? $editCampaign['id']) ?>';
        const baseUrl = campaignBaseUrl;
        
        // Get selected tracking domain URL from the campaign tracking domain selector
        function getCampaignTrackingDomainUrl() {
            const trackingDomainSelect = document.getElementById('campaign-tracking-domain-select');
            if (trackingDomainSelect) {
                const selectedOption = trackingDomainSelect.options[trackingDomainSelect.selectedIndex];
                if (selectedOption && selectedOption.getAttribute('data-domain-url')) {
                    return selectedOption.getAttribute('data-domain-url');
                }
            }
            // Fallback to campaign base URL
            return campaignBaseUrl;
        }
        
        // Update chomp.js code when tracking domain changes
        function updateChompJSCode() {
            const chompCodeElement = document.getElementById('chomp-js-code-display');
            if (chompCodeElement) {
                const trackingDomainUrl = getCampaignTrackingDomainUrl();
                const chompCode = `&lt;script src="${trackingDomainUrl}/chomp.js"&gt;&lt;/script&gt;`;
                chompCodeElement.textContent = chompCode;
            }
        }
        
        // NEW FUNCTION: Fix the display of chomp.js code to show actual script tags
        // This function reads the current content, decodes HTML entities, and displays properly
        // Call this after updateChompJSCode() or on page load to fix the display
        function fixChompJSDisplay() {
            const chompCodeElement = document.getElementById('chomp-js-code-display');
            if (!chompCodeElement) return;
            
            // Get the current text content (which may have HTML entities)
            const currentText = chompCodeElement.textContent.trim();
            
            // Decode HTML entities to get the actual script tag
            const actualCode = currentText
                .replace(/&lt;/g, '<')
                .replace(/&gt;/g, '>')
                .replace(/&quot;/g, '"')
                .replace(/&amp;/g, '&');
            
            // Store the actual code in a data attribute for copying
            chompCodeElement.setAttribute('data-actual-code', actualCode);
            
            // Display using textContent with actual script tags (browser will display them correctly)
            chompCodeElement.textContent = actualCode;
        }
        
        // Copy chomp.js code
        function copyChompJSCode() {
            const chompCodeElement = document.getElementById('chomp-js-code-display');
            const copyButton = document.getElementById('copy-chomp-js-btn');
            if (!chompCodeElement) return;
            
            // Get the actual code from data attribute if available, otherwise decode from textContent
            let codeToCopy = chompCodeElement.getAttribute('data-actual-code');
            if (!codeToCopy) {
                // Fallback: decode HTML entities from textContent
            const code = chompCodeElement.textContent.trim();
                codeToCopy = code.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&amp;/g, '&');
            }
            
            copyToClipboard(codeToCopy, copyButton);
        }
        
        // Get current slug from selector (or use campaign key as default)
        function getCurrentSlugUrl() {
            const slugSelector = document.getElementById('campaign-slug-selector');
            if (slugSelector && slugSelector.value) {
                const selectedOption = slugSelector.options[slugSelector.selectedIndex];
                const slug = selectedOption ? selectedOption.getAttribute('data-slug') : '';
                if (slug) {
                    return baseUrl + '/km/' + slug;
                }
            }
            // Fallback to campaign key
            return baseTrackingUrl;
        }

        // Function to update tracking link when slug changes (works for both auto-detect and specific traffic sources)
        function updateTrackingLinkOnSlugChange() {
            const campaignTrafficSourceSelect = document.getElementById('traffic_source_id');
            const linkTrafficSourceSelect = document.getElementById('link-traffic-source-select');
            
            // Check if campaign has a specific traffic source selected (not auto-detect)
            if (campaignTrafficSourceSelect && campaignTrafficSourceSelect.value && campaignTrafficSourceSelect.value !== '0') {
                // Use updateTrackingLink for specific traffic source campaigns
                updateTrackingLink();
            } else if (linkTrafficSourceSelect && linkTrafficSourceSelect.value) {
                // Use updateTrackingLinkWithTrafficSource for auto-detect campaigns with selected traffic source
                updateTrackingLinkWithTrafficSource();
            } else {
                // No traffic source selected, just show base URL with slug
                const trackingUrlElement = document.getElementById('full-tracking-url');
                if (trackingUrlElement) {
                    trackingUrlElement.textContent = getCurrentSlugUrl();
                }
            }
            
            // Also update redirectless code if slug selector exists there
            const redirectlessSlugSelector = document.getElementById('redirectless-slug-selector');
            if (redirectlessSlugSelector) {
                updateRedirectlessCode();
            }
        }

        // Function to update tracking link with selected traffic source (for auto-detect campaigns)
        function updateTrackingLinkWithTrafficSource() {
            const trafficSourceSelect = document.getElementById('link-traffic-source-select');
            const trackingUrlElement = document.getElementById('full-tracking-url');
            
            if (!trafficSourceSelect || !trackingUrlElement) return;
            
            const selectedOption = trafficSourceSelect.options[trafficSourceSelect.selectedIndex];
            
            // Get current slug URL (or campaign key as fallback)
            let trackingUrl = getCurrentSlugUrl();
            
            // If no traffic source selected, check if campaign has a default
            if (!selectedOption || !selectedOption.value) {
                // Check if campaign has a default traffic source from the form
                const campaignTrafficSourceSelect = document.getElementById('traffic_source_id');
                if (campaignTrafficSourceSelect && campaignTrafficSourceSelect.value && campaignTrafficSourceSelect.value !== '0') {
                    // Use campaign default by calling the existing updateTrackingLink function
                    updateTrackingLink();
                } else {
                    // No default, show base URL (with slug if selected)
                    trackingUrlElement.textContent = trackingUrl;
                }
                return;
            }
            
            const trafficSourceId = selectedOption.value;
            
            // Get tokens and cost param from data attributes
            const tokensJson = selectedOption.getAttribute('data-tokens');
            const costParam = selectedOption.getAttribute('data-cost-param') || '';
            const trafficSourceName = selectedOption.getAttribute('data-ts-name') || 'Unknown';
            const tsNameSanitized = trafficSourceName.replace(/[^a-zA-Z0-9]/g, '');
            
            let tokens = [];
            try {
                tokens = tokensJson ? JSON.parse(tokensJson) : [];
            } catch (e) {
                console.error('Failed to parse tokens JSON:', e);
            }
            
            // Build URL with all parameters (using slug URL if selected)
            const params = [];
            
            // Add Tf parameter (traffic source ID for auto-detection/override)
            // This tells Kuma to use this specific traffic source, overriding campaign default if any
            params.push('Tf=' + trafficSourceId);
            
            // Add cost parameter if specified
            if (costParam) {
                params.push(costParam + '={value}');
            }
            
            // Add traffic source token parameters using the token's placeholder (Facebook template variables)
            // CRITICAL: Exclude 'fbclid' - Facebook automatically appends this parameter to URLs when users click ads
            // We should NOT include it in the campaign link template
            if (Array.isArray(tokens)) {
                tokens.forEach(token => {
                    const parameter = token.parameter || token.key || '';
                    const placeholder = token.placeholder || '{value}';
                    // Skip fbclid - Facebook adds it automatically
                    if (parameter && parameter.toLowerCase() !== 'fbclid') {
                        // Use the token's placeholder which contains Facebook template variables like {{ad.id}}, {{adset.id}}, etc.
                        params.push(parameter + '=' + placeholder);
                    }
                });
            }
            
            // Add custom campaign tokens (if on edit page)
            const customTokenRows = document.querySelectorAll('.custom-token-row');
            if (customTokenRows) {
                customTokenRows.forEach(row => {
                    const paramInput = row.querySelector('input[name="custom_token_parameter[]"]');
                    const placeholderInput = row.querySelector('input[name="custom_token_placeholder[]"]');
                    if (paramInput && placeholderInput) {
                        const param = paramInput.value.trim();
                        const placeholder = placeholderInput.value.trim();
                        if (param && placeholder) {
                            params.push(param + '=' + placeholder);
                        }
                    }
                });
            }
            
            // Append parameters to URL
            if (params.length > 0) {
                trackingUrl += '?' + params.join('&');
            }
            
            // Update displayed URL
            trackingUrlElement.textContent = trackingUrl;
        }

        // Show/hide traffic source selector dropdown based on campaign traffic source
        function toggleTrafficSourceSelector() {
            const campaignTrafficSourceSelect = document.getElementById('traffic_source_id');
            const selectorContainer = document.getElementById('link-traffic-source-selector-container');
            const tsPostbacksSection = document.getElementById('traffic_source_postbacks_section');
            
            if (campaignTrafficSourceSelect) {
                // Auto-detect removed for this release — hide per-source link/postback UI
                if (selectorContainer) {
                    selectorContainer.style.display = 'none';
                }
                if (tsPostbacksSection) {
                    tsPostbacksSection.style.display = 'none';
                }
                
                toggleFacebookIntegration();
                toggleGoogleAdsIntegration();
            }
        }

        // Initialize tracking link on page load
        document.addEventListener('DOMContentLoaded', function() {
            // If campaign has a default traffic source, show it initially
            const campaignTrafficSourceSelect = document.getElementById('traffic_source_id');
            if (campaignTrafficSourceSelect) {
                // Watch for changes to hide/show selector dropdown
                campaignTrafficSourceSelect.addEventListener('change', toggleTrafficSourceSelector);
                
                // Set initial state
                toggleTrafficSourceSelector();
                
                if (campaignTrafficSourceSelect.value && campaignTrafficSourceSelect.value !== '0') {
                    updateTrackingLink();
                } else {
                    // Check if link-traffic-source-select has a selection
                    const linkTrafficSourceSelect = document.getElementById('link-traffic-source-select');
                    if (linkTrafficSourceSelect && linkTrafficSourceSelect.value) {
                        updateTrackingLinkWithTrafficSource();
                    }
                }
            }
        });

        function updateTrackingLink() {
            const trafficSourceSelect = document.getElementById('traffic_source_id');
            const trackingUrlElement = document.getElementById('full-tracking-url');
            
            if (!trafficSourceSelect || !trackingUrlElement) return;
            
            const selectedOption = trafficSourceSelect.options[trafficSourceSelect.selectedIndex];
            
            // Get current slug URL (or campaign key as fallback)
            let trackingUrl = getCurrentSlugUrl();
            
            // If "Kuma Auto Detected" (value="0" or empty) is selected, show base URL only
            if (!selectedOption || !selectedOption.value || selectedOption.value === '0') {
                trackingUrlElement.textContent = trackingUrl;
                return;
            }
            
            // Get tokens and cost param from data attributes
            const tokensJson = selectedOption.getAttribute('data-tokens');
            const costParam = selectedOption.getAttribute('data-cost-param') || '';
            
            let tokens = [];
            try {
                tokens = tokensJson ? JSON.parse(tokensJson) : [];
            } catch (e) {
                console.error('Failed to parse tokens JSON:', e);
            }
            
            // Build URL with all parameters (using slug URL if selected)
            const params = [];
            
            // Add cost parameter if specified
            if (costParam) {
                params.push(costParam + '={value}');
            }
            
            // Add traffic source token parameters (new detailed structure)
            if (Array.isArray(tokens)) {
                tokens.forEach(token => {
                    const parameter = token.parameter || token.key || '';
                    const placeholder = token.placeholder || '{value}';
                    if (parameter) {
                        params.push(parameter + '=' + placeholder);
                    }
                });
            }
            
            // Add custom campaign tokens (if on edit page)
            const customTokenRows = document.querySelectorAll('.custom-token-row');
            if (customTokenRows) {
                customTokenRows.forEach(row => {
                    const paramInput = row.querySelector('input[name="custom_token_parameter[]"]');
                    const placeholderInput = row.querySelector('input[name="custom_token_placeholder[]"]');
                    if (paramInput && placeholderInput) {
                        const param = paramInput.value.trim();
                        const placeholder = placeholderInput.value.trim();
                        if (param && placeholder) {
                            params.push(param + '=' + placeholder);
                        }
                    }
                });
            }
            
            // Append parameters if any
            if (params.length > 0) {
                trackingUrl += '?' + params.join('&');
            }
            
            trackingUrlElement.textContent = trackingUrl;
        }

        function toggleFacebookIntegration() {
            const trafficSourceSelect = document.getElementById('traffic_source_id');
            const facebookField = document.getElementById('facebook_integration_field');
            
            if (!trafficSourceSelect || !facebookField) return;
            
            const selectedOption = trafficSourceSelect.options[trafficSourceSelect.selectedIndex];
            const isFacebook = selectedOption && selectedOption.getAttribute('data-is-facebook') === '1';
            
            // Whole Facebook block (CAPI, ad account, Meta campaign linking) only when FB is source
            facebookField.style.display = isFacebook ? 'block' : 'none';
        }

        function toggleGoogleAdsIntegration() {
            const trafficSourceSelect = document.getElementById('traffic_source_id');
            const googleAdsField = document.getElementById('google_ads_integration_field');
            if (!trafficSourceSelect || !googleAdsField) return;

            const selectedOption = trafficSourceSelect.options[trafficSourceSelect.selectedIndex];
            const isGoogle = selectedOption && selectedOption.getAttribute('data-is-google') === '1';
            googleAdsField.style.display = isGoogle ? 'block' : 'none';
        }

        function copyFullTrackingUrl() {
            const element = document.getElementById('full-tracking-url');
            const button = document.getElementById('copy-url-btn');
            const url = element.textContent.trim();
            const originalText = button.innerHTML;
            
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(() => {
                    button.innerHTML = '✓ COPIED!';
                    setTimeout(() => {
                        button.innerHTML = originalText;
                    }, 2000);
                }).catch(() => {
                    fallbackCopy(url, button, originalText);
                });
            } else {
                fallbackCopy(url, button, originalText);
            }
        }
        
        function fallbackCopy(text, button, originalText) {
            const input = document.createElement('textarea');
            input.value = text;
            document.body.appendChild(input);
            input.select();
            document.execCommand('copy');
            document.body.removeChild(input);
            
            button.innerHTML = '✓ COPIED!';
            setTimeout(() => {
                button.innerHTML = originalText;
            }, 2000);
        }

        function copyRedirectlessJSCode() {
            const codeElement = document.getElementById('redirectless-js-code');
            if (!codeElement) return;
            
            const code = codeElement.textContent.trim();
            if (!code) {
                alert('No code available');
                return;
            }
            
            // Decode HTML entities for actual copy
            const textarea = document.createElement('textarea');
            textarea.value = code.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&amp;/g, '&');
            document.body.appendChild(textarea);
            textarea.select();
            
            const button = document.getElementById('copy-redirectless-js-btn');
            const originalText = button.innerHTML;
            
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(textarea.value).then(() => {
                    button.innerHTML = '✓ COPIED!';
                    setTimeout(() => {
                        button.innerHTML = originalText;
                    }, 2000);
                    document.body.removeChild(textarea);
                }).catch(() => {
                    document.execCommand('copy');
                    document.body.removeChild(textarea);
                    button.innerHTML = '✓ COPIED!';
                    setTimeout(() => {
                        button.innerHTML = originalText;
                    }, 2000);
                });
            } else {
                document.execCommand('copy');
                document.body.removeChild(textarea);
                button.innerHTML = '✓ COPIED!';
                setTimeout(() => {
                    button.innerHTML = originalText;
                }, 2000);
            }
        }

        function copyRedirectlessPHPCode() {
            const codeElement = document.getElementById('redirectless-php-code');
            if (!codeElement) return;
            
            const code = codeElement.textContent.trim();
            if (!code) {
                alert('No code available');
                return;
            }
            
            // Decode HTML entities for actual copy
            const textarea = document.createElement('textarea');
            textarea.value = code.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&amp;/g, '&');
            document.body.appendChild(textarea);
            textarea.select();
            
            const button = document.getElementById('copy-redirectless-php-btn');
            const originalText = button.innerHTML;
            
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(textarea.value).then(() => {
                    button.innerHTML = '✓ COPIED!';
                    setTimeout(() => {
                        button.innerHTML = originalText;
                    }, 2000);
                }).catch(() => {
                    fallbackCopy(textarea.value, button, originalText);
                });
            } else {
                fallbackCopy(textarea.value, button, originalText);
            }
            
            document.body.removeChild(textarea);
        }

        function copyRedirectlessServerPHPCode() {
            const codeElement = document.getElementById('redirectless-server-php-code');
            if (!codeElement) return;
            
            const code = codeElement.textContent.trim();
            if (!code) {
                alert('No code available');
                return;
            }
            
            // Decode HTML entities for actual copy
            const textarea = document.createElement('textarea');
            textarea.value = code.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&amp;/g, '&');
            document.body.appendChild(textarea);
            textarea.select();
            
            const button = document.getElementById('copy-redirectless-server-php-btn');
            const originalText = button.innerHTML;
            
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(textarea.value).then(() => {
                    button.innerHTML = '✓ COPIED!';
                    setTimeout(() => {
                        button.innerHTML = originalText;
                    }, 2000);
                    document.body.removeChild(textarea);
                }).catch(() => {
                    fallbackCopy(textarea.value, button, originalText);
                    document.body.removeChild(textarea);
                });
            } else {
                fallbackCopy(textarea.value, button, originalText);
                document.body.removeChild(textarea);
            }
        }

        // Initialize tracking link on page load (for edit mode)
        if (document.getElementById('traffic_source_id') && document.getElementById('full-tracking-url')) {
            updateTrackingLink();
        }
        
        // Initialize chomp.js code on page load
        if (document.getElementById('chomp-js-code-display')) {
            updateChompJSCode();
            // Fix the display to show actual script tags (not HTML entities)
            setTimeout(function() {
                fixChompJSDisplay();
            }, 100);
            
            // Also fix display when tracking domain selector changes
            const trackingDomainSelect = document.getElementById('campaign-tracking-domain-select');
            if (trackingDomainSelect) {
                trackingDomainSelect.addEventListener('change', function() {
                    // Wait a bit for updateChompJSCode() to finish, then fix display
                    setTimeout(function() {
                        fixChompJSDisplay();
                    }, 50);
                });
            }
        }

        // Redirectless tracking code updater
        function updateRedirectlessCode() {
            const selector = document.getElementById('redirectless-lp-selector');
            if (!selector) return;
            
            const selectedOption = selector.options[selector.selectedIndex];
            const lpId = selectedOption ? selectedOption.getAttribute('data-lp-id') : null;
            const lpUrl = selectedOption ? selectedOption.getAttribute('data-lp-url') : null;
            const campaignId = <?= $campaignIdForRedirectless ?? 0 ?>;
            const baseUrl = '<?= htmlspecialchars(BASE_URL) ?>';
            const appBaseUrl = '<?= htmlspecialchars(APP_BASE_URL) ?>';
            
            // Get selected tracking domain (if any) - use for all redirectless code sections
            let trackingDomainUrl = appBaseUrl;
            const trackingDomainSelect = document.getElementById('redirectless-tracking-domain-select');
            if (trackingDomainSelect && trackingDomainSelect.value) {
                trackingDomainUrl = trackingDomainSelect.value;
            }
            
            // Get selected slug (if any) - check both redirectless-specific and main slug selector
            let selectedSlug = '';
            let slugSelector = document.getElementById('redirectless-slug-selector');
            if (!slugSelector) {
                // Fallback to main campaign slug selector
                slugSelector = document.getElementById('campaign-slug-selector');
            }
            if (slugSelector && slugSelector.value) {
                const slugOption = slugSelector.options[slugSelector.selectedIndex];
                if (slugOption) {
                    selectedSlug = slugOption.getAttribute('data-slug') || '';
                }
            }
            
            // Get traffic source data for building direct link (from redirectless traffic source selector)
            const redirectlessTrafficSourceSelect = document.getElementById('redirectless-traffic-source-select');
            let tokens = [];
            let costParam = '';
            
            if (redirectlessTrafficSourceSelect) {
                const tsOption = redirectlessTrafficSourceSelect.options[redirectlessTrafficSourceSelect.selectedIndex];
                if (tsOption && tsOption.value) {
                    const tokensJson = tsOption.getAttribute('data-tokens');
                    costParam = tsOption.getAttribute('data-cost-param') || '';
                    
                    try {
                        tokens = tokensJson ? JSON.parse(tokensJson) : [];
                    } catch (e) {
                        console.error('Failed to parse tokens JSON:', e);
                    }
                }
            }
            
            // Get custom campaign tokens
            const customTokenRows = document.querySelectorAll('.custom-token-row');
            const customTokens = [];
            if (customTokenRows) {
                customTokenRows.forEach(row => {
                    const paramInput = row.querySelector('input[name="custom_token_parameter[]"]');
                    const placeholderInput = row.querySelector('input[name="custom_token_placeholder[]"]');
                    if (paramInput && placeholderInput) {
                        const param = paramInput.value.trim();
                        const placeholder = placeholderInput.value.trim();
                        if (param && placeholder) {
                            customTokens.push({ parameter: param, placeholder: placeholder });
                        }
                    }
                });
            }
            
            if (!lpId || lpId === '') {
                // Reset to placeholder
                const jsCodeElement = document.getElementById('redirectless-js-code');
                const phpCodeElement = document.getElementById('redirectless-php-code');
                const directLinkContainer = document.getElementById('redirectless-direct-link-container');
                
                if (jsCodeElement) {
                    jsCodeElement.innerHTML = `&lt;!-- Visit tracking --&gt;
&lt;script&gt;
var kumaConfig = { "root": "${trackingDomainUrl}/", };
&lt;/script&gt;
&lt;script src="${trackingDomainUrl}/redirectless.js"&gt;&lt;/script&gt;
&lt;script&gt;kumaTrack(${campaignId}, LP_ID);&lt;/script&gt;
&lt;!-- CTA click tracking + offer redirect --&gt;
&lt;script src="${trackingDomainUrl}/chomp.js"&gt;&lt;/script&gt;

&lt;!-- Use this markup (or onclick) on your CTA button --&gt;
&lt;a href="#" onclick="kTrack(); return false;"&gt;Click Here&lt;/a&gt;`;
                }
                
                if (phpCodeElement) {
                    phpCodeElement.innerHTML = `&lt;?php if (empty(\$_GET['click_id'])): ?&gt;
&lt;img src="${trackingDomainUrl}/track.php?c=${campaignId}&amp;l=LP_ID&amp;&lt;?php echo htmlspecialchars(\$_SERVER['QUERY_STRING']); ?&gt;" 
     width="1" height="1" style="display:none;"&gt;
&lt;?php endif; ?&gt;`;
                }
                
                const serverPhpCodeElement = document.getElementById('redirectless-server-php-code');
                if (serverPhpCodeElement) {
                    
                    serverPhpCodeElement.innerHTML = `&lt;?php
// Place this code at the VERY TOP of your PHP landing page (before any HTML)
$kumaTrackingUrl = "${trackingDomainUrl}/track.php";
$campaignId = ${campaignId};
$landingPageId = LP_ID; // Replace LP_ID with your actual landing page ID

// Already arrived via campaign redirect — reuse click_id, skip track.php
$kumaClickId = null;
if (!empty(\$_GET['click_id'])) {
    $kumaClickId = (string)\$_GET['click_id'];
    setcookie('kuma_click_id', $kumaClickId, time() + 3600, '/');
} else {
    // Collect all URL parameters
    $params = \$_GET;
    $params['c'] = $campaignId;
    $params['l'] = $landingPageId;
    $params['server_side'] = 1;

    // Forward visitor data
    $params['visitor_ip'] = \$_SERVER['REMOTE_ADDR'] ?? '';
    $params['visitor_ua'] = \$_SERVER['HTTP_USER_AGENT'] ?? '';
    $params['visitor_referrer'] = \$_SERVER['HTTP_REFERER'] ?? '';

    // Make server-to-server tracking call
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $kumaTrackingUrl . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Parse response and store click_id in PHP variable, cookie, and URL parameter
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['success']) && $data['success'] && isset($data['click_id'])) {
            $kumaClickId = $data['click_id'];
            setcookie('kuma_click_id', $kumaClickId, time() + 3600, '/'); // Cookie as backup
            
            // Also add click_id to \$_GET for URL parameter access (works for static HTML too)
            if (!isset(\$_GET['click_id'])) {
                \$_GET['click_id'] = $kumaClickId;
            }
        }
    }
}
?&gt;`;
                }
                
                if (directLinkContainer) {
                    directLinkContainer.style.display = 'none';
                }
                return;
            }
            
            // Build direct campaign link from the actual LP URL + tracking params.
            // Tracking domain is only for JS/pixel snippets (root/track.php), not the visitor-facing URL.
            let directLink = lpUrl || '';
            if (directLink) {
                const params = [];
                
                // Add campaign ID and landing page ID FIRST (required for redirectless tracking)
                // These are needed by track.php to create the click
                params.push('c=' + campaignId);
                params.push('l=' + lpId);
                
                // Add slug parameter if a slug is selected (for redirectless tracking)
                if (selectedSlug) {
                    // For redirectless, we need to pass the slug to track.php
                    // The slug will be used to identify which slug was used for this click
                    params.push('slug=' + encodeURIComponent(selectedSlug));
                }
                
                // Add Tf parameter (traffic source ID for auto-detection/override)
                // This tells Kuma to use this specific traffic source, overriding campaign default if any
                if (redirectlessTrafficSourceSelect && redirectlessTrafficSourceSelect.value) {
                    params.push('Tf=' + redirectlessTrafficSourceSelect.value);
                }
                
                // Add traffic source token parameters using the token's placeholder (Facebook template variables)
                // CRITICAL: Exclude 'fbclid' - Facebook automatically appends this parameter to URLs when users click ads
                // We should NOT include it in the campaign link template
                if (Array.isArray(tokens)) {
                    tokens.forEach(token => {
                        const parameter = token.parameter || token.key || '';
                        const placeholder = token.placeholder || '{value}';
                        // Skip fbclid - Facebook adds it automatically
                        if (parameter && parameter.toLowerCase() !== 'fbclid') {
                            // Use the token's placeholder which contains Facebook template variables like {{ad.id}}, {{adset.id}}, etc.
                            params.push(parameter + '=' + placeholder);
                        }
                    });
                }
                
                // Add cost parameter if specified (after traffic source tokens)
                if (costParam) {
                    params.push(costParam + '={value}');
                }
                
                // Add custom campaign tokens LAST
                if (Array.isArray(customTokens)) {
                    customTokens.forEach(token => {
                        const parameter = token.parameter || '';
                        const placeholder = token.placeholder || '{value}';
                        if (parameter) {
                            params.push(parameter + '=' + placeholder);
                        }
                    });
                }
                
                // Append parameters to LP URL
                if (params.length > 0) {
                    const separator = directLink.includes('?') ? '&' : '?';
                    directLink += separator + params.join('&');
                }
            }
            
            // Update direct link display
            const directLinkElement = document.getElementById('redirectless-direct-link');
            const directLinkContainer = document.getElementById('redirectless-direct-link-container');
            if (directLinkElement && directLinkContainer) {
                if (directLink && lpUrl) {
                    directLinkElement.textContent = directLink;
                    directLinkContainer.style.display = 'block';
                } else {
                    directLinkElement.textContent = 'Landing page URL not available';
                    directLinkContainer.style.display = 'none';
                }
            }
            
            // Update JavaScript code (include slug if selected)
            const jsCodeElement = document.getElementById('redirectless-js-code');
            if (jsCodeElement) {
                let slugParam = '';
                if (selectedSlug) {
                    slugParam = `, "${selectedSlug}"`;
                }
                jsCodeElement.innerHTML = `&lt;!-- Visit tracking --&gt;
&lt;script&gt;
var kumaConfig = { "root": "${trackingDomainUrl}/", };
&lt;/script&gt;
&lt;script src="${trackingDomainUrl}/redirectless.js"&gt;&lt;/script&gt;
&lt;script&gt;kumaTrack(${campaignId}, ${lpId}${slugParam});&lt;/script&gt;
&lt;!-- CTA click tracking + offer redirect --&gt;
&lt;script src="${trackingDomainUrl}/chomp.js"&gt;&lt;/script&gt;

&lt;!-- Use this markup (or onclick) on your CTA button --&gt;
&lt;a href="#" onclick="kTrack(); return false;"&gt;Click Here&lt;/a&gt;`;
            }
            
            // Update PHP pixel code
            const phpCodeElement = document.getElementById('redirectless-php-code');
            if (phpCodeElement) {
                phpCodeElement.innerHTML = `&lt;?php if (empty(\$_GET['click_id'])): ?&gt;
&lt;img src="${trackingDomainUrl}/track.php?c=${campaignId}&amp;l=${lpId}&amp;&lt;?php echo htmlspecialchars(\$_SERVER['QUERY_STRING']); ?&gt;" 
     width="1" height="1" style="display:none;"&gt;
&lt;?php endif; ?&gt;`;
            }
            
            // Update PHP server-side code
            const serverPhpCodeElement = document.getElementById('redirectless-server-php-code');
            if (serverPhpCodeElement) {
                
                // Build parameter assignments
                let paramAssignments = [];
                
                // Add slug parameter if selected
                if (selectedSlug) {
                    paramAssignments.push(`$params['slug'] = "${selectedSlug}";`);
                }
                
                // Add Tf parameter (traffic source ID) if selected
                if (redirectlessTrafficSourceSelect && redirectlessTrafficSourceSelect.value) {
                    paramAssignments.push(`$params['Tf'] = ${redirectlessTrafficSourceSelect.value};`);
                }
                
                // Add traffic source tokens
                if (Array.isArray(tokens) && tokens.length > 0) {
                    tokens.forEach(token => {
                        const parameter = token.parameter || token.key || '';
                        const placeholder = token.placeholder || '{value}';
                        if (parameter) {
                            // Read from $_GET (Facebook will have replaced template variables like {{ad.id}} with actual values)
                            // The placeholder shows what Facebook template variable is used in the URL
                            const escapedPlaceholder = placeholder.replace(/"/g, '\\"').replace(/\$/g, '\\$');
                            paramAssignments.push(`$params['${parameter}'] = \$_GET['${parameter}'] ?? ''; // Traffic source token (from ${escapedPlaceholder})`);
                        }
                    });
                }
                
                // Add cost parameter if specified
                if (costParam) {
                    paramAssignments.push(`$params['${costParam}'] = '{value}'; // Cost parameter`);
                }
                
                // Add custom campaign tokens
                if (Array.isArray(customTokens) && customTokens.length > 0) {
                    customTokens.forEach(token => {
                        const parameter = token.parameter || '';
                        const placeholder = token.placeholder || '{value}';
                        if (parameter) {
                            paramAssignments.push(`$params['${parameter}'] = '${placeholder}'; // Custom token`);
                        }
                    });
                }
                
                const paramAssignmentsCode = paramAssignments.length > 0 ? '\n' + paramAssignments.join('\n') : '';
                
                serverPhpCodeElement.innerHTML = `&lt;?php
// Place this code at the VERY TOP of your PHP landing page (before any HTML)
$kumaTrackingUrl = "${trackingDomainUrl}/track.php";

// Use campaign ID and landing page ID from URL if present, otherwise use hardcoded values
$campaignId = isset(\$_GET['c']) ? (int)\$_GET['c'] : ${campaignId};
$landingPageId = isset(\$_GET['l']) ? (int)\$_GET['l'] : ${lpId};

// Already arrived via campaign redirect — reuse click_id, skip track.php
$kumaClickId = null;
if (!empty(\$_GET['click_id'])) {
    $kumaClickId = (string)\$_GET['click_id'];
    setcookie('kuma_click_id', $kumaClickId, time() + 3600, '/');
} else {
    // Collect all URL parameters (Facebook will have replaced template variables with actual values)
    $params = \$_GET;
    // Ensure c and l are set (use URL params if present, otherwise use hardcoded)
    $params['c'] = $campaignId;
    $params['l'] = $landingPageId;
    $params['server_side'] = 1;${paramAssignmentsCode}

    // Forward visitor data
    $params['visitor_ip'] = \$_SERVER['REMOTE_ADDR'] ?? '';
    $params['visitor_ua'] = \$_SERVER['HTTP_USER_AGENT'] ?? '';
    $params['visitor_referrer'] = \$_SERVER['HTTP_REFERER'] ?? '';

    // Make server-to-server tracking call
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $kumaTrackingUrl . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Allow self-signed certs if needed
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Parse response and store click_id in PHP variable, cookie, and URL parameter
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['success']) && $data['success'] && isset($data['click_id'])) {
            $kumaClickId = $data['click_id'];
            setcookie('kuma_click_id', $kumaClickId, time() + 3600, '/'); // Cookie as backup
            
            // Also add click_id to \$_GET for URL parameter access (works for static HTML too)
            if (!isset(\$_GET['click_id'])) {
                \$_GET['click_id'] = $kumaClickId;
            }
        } else {
            // Log error for debugging
            error_log("Kuma tracking failed: HTTP $httpCode, Response: " . substr($response, 0, 200));
        }
    } else {
        // Log error for debugging
        error_log("Kuma tracking curl error: HTTP $httpCode, Error: $curlError");
    }
}
?&gt;`;
            }
        }
        
        // Copy redirectless direct link
        function copyRedirectlessDirectLink() {
            const linkElement = document.getElementById('redirectless-direct-link');
            const button = document.getElementById('copy-redirectless-direct-link-btn');
            if (!linkElement || !button) return;
            
            const link = linkElement.textContent.trim();
            if (!link || link === 'Select a landing page to generate link' || link === 'Landing page URL not available') {
                alert('No link available. Please select a landing page first.');
                return;
            }
            
            const originalText = button.innerHTML;
            
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(link).then(() => {
                    button.innerHTML = '✓ COPIED!';
                    setTimeout(() => {
                        button.innerHTML = originalText;
                    }, 2000);
                }).catch(() => {
                    fallbackCopy(link, button, originalText);
                });
            } else {
                fallbackCopy(link, button, originalText);
            }
        }

        // Auto-select first LP on page load if available
        document.addEventListener('DOMContentLoaded', function() {
            const selector = document.getElementById('redirectless-lp-selector');
            if (selector && selector.options.length > 1) {
                // Select first LP (skip the "-- Select --" option)
                selector.selectedIndex = 1;
                updateRedirectlessCode();
            }
            
            // Update redirectless code when redirectless traffic source selector changes
            const redirectlessTrafficSourceSelect = document.getElementById('redirectless-traffic-source-select');
            if (redirectlessTrafficSourceSelect) {
                redirectlessTrafficSourceSelect.addEventListener('change', function() {
                    updateRedirectlessCode();
                });
            }
            
            // Update redirectless code when custom tokens change
            const customTokenInputs = document.querySelectorAll('.custom-token-row input');
            customTokenInputs.forEach(input => {
                input.addEventListener('input', function() {
                    // Debounce to avoid too many updates
                    clearTimeout(window.redirectlessUpdateTimeout);
                    window.redirectlessUpdateTimeout = setTimeout(function() {
                        updateRedirectlessCode();
                    }, 300);
                });
            });
        });
    </script>
<?php endif; ?>

    <script>
        let customTokenRowCount = <?= $tokenCount ?? 3 ?>;

        function updateTokenUrlAppend(input) {
            const row = input.closest('.custom-token-row');
            if (!row) return;
            
            const paramInput = row.querySelector('input[name="custom_token_parameter[]"]');
            const placeholderInput = row.querySelector('input[name="custom_token_placeholder[]"]');
            const urlAppendDisplay = row.querySelector('.url-append-display');
            
            if (!paramInput || !placeholderInput || !urlAppendDisplay) return;
            
            const param = paramInput.value.trim();
            const placeholder = placeholderInput.value.trim();
            
            if (param && placeholder) {
                urlAppendDisplay.value = '&' + param + '=' + placeholder;
            } else if (param) {
                urlAppendDisplay.value = '&' + param + '=';
            } else {
                urlAppendDisplay.value = '';
            }
        }

        function addCustomTokenRow() {
            const container = document.getElementById('custom_tokens_container');
            if (!container) return;
            const currentRows = container.querySelectorAll('.custom-token-row').length;
            
            if (currentRows >= 10) {
                alert('Maximum 10 custom tokens allowed');
                return;
            }
            
            const tokenNum = currentRows + 1;
            const tokenIdx = currentRows;
            
            const newRow = document.createElement('div');
            newRow.className = 'custom-token-row';
            newRow.style.cssText = 'display: grid; grid-template-columns: 60px 2fr 1.5fr 2fr 2.5fr 100px auto; gap: 12px; margin-bottom: 8px; align-items: center;';
            newRow.innerHTML = `
                <div style="text-align: center; font-weight: 600; color: #666;">Token ${tokenNum}</div>
                <div>
                    <input type="text" name="custom_token_name[]" 
                           placeholder="Display name"
                           maxlength="100"
                           style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px; font-size: 13px;"
                           aria-label="Token name">
                </div>
                <div>
                    <input type="text" name="custom_token_parameter[]" 
                           placeholder="e.g., sub1"
                           maxlength="50"
                           onchange="updateTokenUrlAppend(this); updateTrackingLink();"
                           style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px; font-size: 13px;"
                           aria-label="Parameter name">
                </div>
                <div>
                    <input type="text" name="custom_token_placeholder[]" 
                           placeholder="e.g., {value}"
                           maxlength="100"
                           onchange="updateTokenUrlAppend(this); updateTrackingLink();"
                           style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px; font-size: 13px;"
                           aria-label="Placeholder">
                </div>
                <div>
                    <input type="text" class="url-append-display" 
                           readonly
                           style="width: 100%; padding: 8px; background: #f9f9f9; border: 1px solid #ccc; border-radius: 4px; font-size: 12px; font-family: monospace; color: #3d5a26;"
                           aria-label="URL append preview">
                </div>
                <div style="display: flex; gap: 8px; justify-content: center;">
                    <label style="display: flex; align-items: center; gap: 4px; cursor: pointer; font-size: 12px;">
                        <input type="checkbox" name="custom_token_pass_to_lp_${tokenIdx}[]" 
                               value="1"
                               style="width: 16px; height: 16px;">
                        LP
                    </label>
                    <label style="display: flex; align-items: center; gap: 4px; cursor: pointer; font-size: 12px;">
                        <input type="checkbox" name="custom_token_pass_to_offer_${tokenIdx}[]" 
                               value="1"
                               style="width: 16px; height: 16px;">
                        Offer
                    </label>
                </div>
                <button type="button" class="btn btn-outline remove-token-btn" onclick="removeCustomTokenRow(this)" 
                        style="padding: 6px 10px; font-size: 12px;" 
                        aria-label="Remove token row">× Remove</button>
            `;
            container.appendChild(newRow);
            updateAddTokenButton();
            updateTokenNumbers();
        }

        function removeCustomTokenRow(button) {
            const container = document.getElementById('custom_tokens_container');
            if (!container) return;
            const rows = container.querySelectorAll('.custom-token-row');
            
            if (rows.length <= 1) {
                alert('At least one token row must remain');
                return;
            }
            
            button.closest('.custom-token-row').remove();
            updateAddTokenButton();
            updateTokenNumbers();
        }

        function updateTokenNumbers() {
            const container = document.getElementById('custom_tokens_container');
            if (!container) return;
            const rows = container.querySelectorAll('.custom-token-row');
            rows.forEach((row, idx) => {
                const tokenLabel = row.querySelector('div:first-child');
                if (tokenLabel) {
                    tokenLabel.textContent = 'Token ' + (idx + 1);
                }
            });
        }

        function updateAddTokenButton() {
            const container = document.getElementById('custom_tokens_container');
            if (!container) return;
            const currentRows = container.querySelectorAll('.custom-token-row').length;
            const addBtn = document.getElementById('add_token_btn');
            if (!addBtn) return;
            
            if (currentRows >= 10) {
                addBtn.disabled = true;
                addBtn.style.opacity = '0.5';
                addBtn.style.cursor = 'not-allowed';
            } else {
                addBtn.disabled = false;
                addBtn.style.opacity = '1';
                addBtn.style.cursor = 'pointer';
            }
        }

        // Initialize button state on page load
        if (document.getElementById('add_token_btn')) {
            updateAddTokenButton();
        }
        
        // Helper function to escape HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Ensure redirectRuleTokens is initialized (fallback if script tag didn't execute)
        if (typeof window.redirectRuleTokens === 'undefined') {
            window.redirectRuleTokens = {
                custom: [],
                builtIn: [],
                trafficSource: []
            };
        }

        function addRedirectRule() {
            const container = document.getElementById('redirect_rules_container');
            if (!container) {
                console.error('redirect_rules_container not found');
                return;
            }
            
            // Get all tokens from window.redirectRuleTokens (set by PHP)
            const tokenData = window.redirectRuleTokens || { custom: [], builtIn: [], trafficSource: [] };
            
            console.log('Token data:', tokenData); // Debug log
            
            // Build token options HTML
            let tokenOptionsHtml = '<option value="">Select token...</option>';
            
            // Custom tokens
            if (tokenData.custom && tokenData.custom.length > 0) {
                tokenOptionsHtml += '<optgroup label="Custom Tokens">';
                tokenData.custom.forEach(token => {
                    if (token.name) {
                        const tokenIdentifier = `custom:${escapeHtml(token.name)}`;
                        tokenOptionsHtml += `<option value="${tokenIdentifier}">${escapeHtml(token.name)}</option>`;
                    }
                });
                tokenOptionsHtml += '</optgroup>';
            }
            
            // Built-in tokens
            if (tokenData.builtIn && tokenData.builtIn.length > 0) {
                tokenOptionsHtml += '<optgroup label="Built-in Tokens">';
                tokenData.builtIn.forEach(token => {
                    if (token.name) {
                        const tokenIdentifier = `builtin:${escapeHtml(token.name)}`;
                        tokenOptionsHtml += `<option value="${tokenIdentifier}">${escapeHtml(token.name)}</option>`;
                    }
                });
                tokenOptionsHtml += '</optgroup>';
            }
            
            // Traffic source tokens (grouped by source)
            if (tokenData.trafficSource && tokenData.trafficSource.length > 0) {
                // Group by source
                const grouped = {};
                tokenData.trafficSource.forEach(token => {
                    const source = token.source || 'Unknown Traffic Source';
                    if (!grouped[source]) {
                        grouped[source] = [];
                    }
                    grouped[source].push(token);
                });
                
                // Add optgroups for each traffic source
                Object.keys(grouped).forEach(sourceName => {
                    tokenOptionsHtml += `<optgroup label="Traffic Source: ${escapeHtml(sourceName)}">`;
                    grouped[sourceName].forEach(token => {
                        if (token.name) {
                            const tokenIdentifier = `traffic_source:${escapeHtml(sourceName)}:${escapeHtml(token.name)}`;
                            tokenOptionsHtml += `<option value="${tokenIdentifier}">${escapeHtml(token.name)}</option>`;
                        }
                    });
                    tokenOptionsHtml += '</optgroup>';
                });
            }
            
            const ruleIndex = container.querySelectorAll('.redirect-rule-row').length;
            
            const newRow = document.createElement('div');
            newRow.className = 'redirect-rule-row';
            newRow.style.cssText = 'background: #f9f9f9; padding: 16px; border: 2px solid #ddd; border-radius: 4px; margin-bottom: 12px;';
            newRow.innerHTML = `
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <strong style="color: #3d5a26;">Rule #${ruleIndex + 1}</strong>
                    <button type="button" class="btn btn-outline" onclick="removeRedirectRule(this)" 
                            style="padding: 4px 8px; font-size: 12px; color: #d32f2f;">× Remove</button>
                </div>
                <div style="display: grid; grid-template-columns: 2fr 1.5fr 2fr 3fr; gap: 12px; margin-bottom: 12px;">
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 500; margin-bottom: 4px;">Token Name</label>
                        <select name="redirect_rule_token[]"
                                style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px;">
                            ${tokenOptionsHtml}
                        </select>
                    </div>
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 500; margin-bottom: 4px;">Operator</label>
                        <select name="redirect_rule_operator[]"
                                style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px;">
                            <option value="equals">Equals (=)</option>
                            <option value="not_equals">Not Equals (≠)</option>
                            <option value="contains">Contains</option>
                            <option value="starts_with">Starts With</option>
                            <option value="ends_with">Ends With</option>
                        </select>
                    </div>
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 500; margin-bottom: 4px;">Value to Match</label>
                        <input type="text" name="redirect_rule_value[]" 
                               style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px;"
                               placeholder="Value">
                    </div>
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 500; margin-bottom: 4px;">Redirect URL</label>
                        <input type="url" name="redirect_rule_url[]" 
                               style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px;"
                               placeholder="https://example.com">
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 500; margin-bottom: 4px;">Case Sensitive</label>
                        <select name="redirect_rule_case_sensitive[]"
                                style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px;">
                            <option value="0">No (Case-insensitive)</option>
                            <option value="1">Yes (Case-sensitive)</option>
                        </select>
                    </div>
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 500; margin-bottom: 4px;">Execute On</label>
                        <div style="display: flex; gap: 16px; margin-top: 8px;">
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                <input type="checkbox" name="redirect_rule_execute_on_${ruleIndex}[]" 
                                       value="campaign_click" checked>
                                <span style="font-size: 12px;">Campaign Click</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                <input type="checkbox" name="redirect_rule_execute_on_${ruleIndex}[]" 
                                       value="offer_click" checked>
                                <span style="font-size: 12px;">Offer Click</span>
                            </label>
                        </div>
                    </div>
                </div>
            `;
            container.appendChild(newRow);
            updateRuleNumbers();
        }

        function removeRedirectRule(button) {
            const container = document.getElementById('redirect_rules_container');
            if (!container) return;
            
            button.closest('.redirect-rule-row').remove();
            updateRuleNumbers();
        }

        function updateRuleNumbers() {
            const container = document.getElementById('redirect_rules_container');
            if (!container) return;
            const rows = container.querySelectorAll('.redirect-rule-row');
            rows.forEach((row, idx) => {
                const title = row.querySelector('strong');
                if (title) {
                    title.textContent = `Rule #${idx + 1}`;
                }
            });
        }

        function equalizeOfferWeights() {
            const container = document.getElementById('offer_rotation_items');
            if (!container) return;
            
            const rows = Array.from(container.querySelectorAll('.offer-rotation-row'));
            
            // Filter to only enabled rows
            const enabledRows = rows.filter(row => {
                const checkbox = row.querySelector('input[type="checkbox"]');
                return checkbox && checkbox.checked;
            });
            
            const count = enabledRows.length;
            if (count === 0) {
                alert('At least one offer must be enabled to equalize weights.');
                return;
            }
            
            const baseWeight = Math.floor(100 / count);
            const remainder = 100 - (baseWeight * count);
            
            enabledRows.forEach((row, idx) => {
                const weightInput = row.querySelector('input[name="offer_weight[]"]');
                if (weightInput && !weightInput.readOnly) {
                    // Give remainder to the last enabled item
                    weightInput.value = idx === count - 1 ? baseWeight + remainder : baseWeight;
                }
            });
        }
        
        function setRotationControlLocked(select, weightInput, isEnabled) {
            // Never use HTML disabled — those controls are omitted from POST and break
            // index alignment with offer_enabled[n] / lp_enabled[n].
            if (select) {
                select.disabled = false;
                select.setAttribute('aria-disabled', isEnabled ? 'false' : 'true');
                select.tabIndex = isEnabled ? 0 : -1;
                select.style.pointerEvents = isEnabled ? '' : 'none';
                select.style.background = isEnabled ? '' : '#f5f5f5';
                select.style.color = isEnabled ? '' : '#999';
                select.style.cursor = isEnabled ? '' : 'not-allowed';
            }
            if (weightInput) {
                weightInput.disabled = false;
                weightInput.readOnly = !isEnabled;
                weightInput.setAttribute('aria-disabled', isEnabled ? 'false' : 'true');
                weightInput.style.background = isEnabled ? '' : '#f5f5f5';
                weightInput.style.color = isEnabled ? '' : '#999';
                weightInput.style.cursor = isEnabled ? '' : 'not-allowed';
            }
        }

        function handleOfferEnabledChange(idx, isEnabled) {
            // Update hidden input
            const hiddenInput = document.getElementById('offer_enabled_hidden_' + idx);
            if (!hiddenInput) {
                console.error('handleOfferEnabledChange: Hidden input not found for idx:', idx);
                return;
            }
            hiddenInput.value = isEnabled ? '1' : '0';
            
            const weightInput = document.getElementById('offer_weight_' + idx);
            setRotationControlLocked(
                document.getElementById('offer_select_' + idx),
                weightInput,
                isEnabled
            );
            if (!isEnabled && weightInput) {
                weightInput.value = '0';
            }
            
            // Redistribute weights among enabled items
            redistributeOfferWeights();
        }
        
        function redistributeOfferWeights() {
            const container = document.getElementById('offer_rotation_items');
            if (!container) return;
            
            const rows = Array.from(container.querySelectorAll('.offer-rotation-row'));
            const enabledRows = rows.filter(row => {
                const checkbox = row.querySelector('input[type="checkbox"]');
                return checkbox && checkbox.checked;
            });
            
            const count = enabledRows.length;
            if (count === 0) return;
            
            // Get total current weight of enabled items
            let totalWeight = 0;
            enabledRows.forEach(row => {
                const weightInput = row.querySelector('input[name="offer_weight[]"]');
                if (weightInput) {
                    totalWeight += parseFloat(weightInput.value) || 0;
                }
            });
            
            // If total is 0 or disabled item had weight, redistribute evenly
            if (totalWeight === 0) {
                const baseWeight = Math.floor(100 / count);
                const remainder = 100 - (baseWeight * count);
                
                enabledRows.forEach((row, idx) => {
                    const weightInput = row.querySelector('input[name="offer_weight[]"]');
                    if (weightInput) {
                        weightInput.value = idx === count - 1 ? baseWeight + remainder : baseWeight;
                    }
                });
            } else if (totalWeight !== 100) {
                // Proportionally redistribute to sum to 100
                enabledRows.forEach(row => {
                    const weightInput = row.querySelector('input[name="offer_weight[]"]');
                    if (weightInput) {
                        const currentWeight = parseFloat(weightInput.value) || 0;
                        const newWeight = Math.round((currentWeight / totalWeight) * 100);
                        weightInput.value = newWeight;
                    }
                });
                
                // Adjust for rounding errors
                let adjustedTotal = 0;
                enabledRows.forEach(row => {
                    const weightInput = row.querySelector('input[name="offer_weight[]"]');
                    if (weightInput) {
                        adjustedTotal += parseFloat(weightInput.value) || 0;
                    }
                });
                
                if (adjustedTotal !== 100 && enabledRows.length > 0) {
                    const lastRow = enabledRows[enabledRows.length - 1];
                    const lastWeightInput = lastRow.querySelector('input[name="offer_weight[]"]');
                    if (lastWeightInput) {
                        const currentLast = parseFloat(lastWeightInput.value) || 0;
                        lastWeightInput.value = currentLast + (100 - adjustedTotal);
                    }
                }
            }
        }

        function equalizeLPWeights() {
            const container = document.getElementById('lp_items');
            if (!container) return;
            
            const rows = Array.from(container.querySelectorAll('div[style*="grid-template-columns"]'));
            
            // Filter to only enabled rows
            const enabledRows = rows.filter(row => {
                const checkbox = row.querySelector('input[type="checkbox"]');
                return checkbox && checkbox.checked;
            });
            
            const count = enabledRows.length;
            if (count === 0) {
                alert('At least one landing page must be enabled to equalize weights.');
                return;
            }
            
            const baseWeight = Math.floor(100 / count);
            const remainder = 100 - (baseWeight * count);
            
            enabledRows.forEach((row, idx) => {
                const weightInput = row.querySelector('input[name="lp_weight[]"]');
                if (weightInput && !weightInput.readOnly) {
                    // Give remainder to the last enabled item
                    weightInput.value = idx === count - 1 ? baseWeight + remainder : baseWeight;
                }
            });
        }
        
        function handleLPEnabledChange(idx, isEnabled) {
            // Update hidden input
            const hiddenInput = document.getElementById('lp_enabled_hidden_' + idx);
            if (hiddenInput) {
                hiddenInput.value = isEnabled ? '1' : '0';
            }
            
            const weightInput = document.getElementById('lp_weight_' + idx);
            setRotationControlLocked(
                document.getElementById('lp_select_' + idx),
                weightInput,
                isEnabled
            );
            if (!isEnabled && weightInput) {
                weightInput.value = '0';
            }
            
            // Redistribute weights among enabled items
            redistributeLPWeights();
        }
        
        function redistributeLPWeights() {
            const container = document.getElementById('lp_items');
            if (!container) return;
            
            const rows = Array.from(container.querySelectorAll('div[style*="grid-template-columns"]'));
            const enabledRows = rows.filter(row => {
                const checkbox = row.querySelector('input[type="checkbox"]');
                return checkbox && checkbox.checked;
            });
            
            const count = enabledRows.length;
            if (count === 0) return;
            
            // Get total current weight of enabled items
            let totalWeight = 0;
            enabledRows.forEach(row => {
                const weightInput = row.querySelector('input[name="lp_weight[]"]');
                if (weightInput) {
                    totalWeight += parseFloat(weightInput.value) || 0;
                }
            });
            
            // If total is 0 or disabled item had weight, redistribute evenly
            if (totalWeight === 0) {
                const baseWeight = Math.floor(100 / count);
                const remainder = 100 - (baseWeight * count);
                
                enabledRows.forEach((row, idx) => {
                    const weightInput = row.querySelector('input[name="lp_weight[]"]');
                    if (weightInput) {
                        weightInput.value = idx === count - 1 ? baseWeight + remainder : baseWeight;
                    }
                });
            } else if (totalWeight !== 100) {
                // Proportionally redistribute to sum to 100
                enabledRows.forEach(row => {
                    const weightInput = row.querySelector('input[name="lp_weight[]"]');
                    if (weightInput) {
                        const currentWeight = parseFloat(weightInput.value) || 0;
                        const newWeight = Math.round((currentWeight / totalWeight) * 100);
                        weightInput.value = newWeight;
                    }
                });
                
                // Adjust for rounding errors
                let adjustedTotal = 0;
                enabledRows.forEach(row => {
                    const weightInput = row.querySelector('input[name="lp_weight[]"]');
                    if (weightInput) {
                        adjustedTotal += parseFloat(weightInput.value) || 0;
                    }
                });
                
                if (adjustedTotal !== 100 && enabledRows.length > 0) {
                    const lastRow = enabledRows[enabledRows.length - 1];
                    const lastWeightInput = lastRow.querySelector('input[name="lp_weight[]"]');
                    if (lastWeightInput) {
                        const currentLast = parseFloat(lastWeightInput.value) || 0;
                        lastWeightInput.value = currentLast + (100 - adjustedTotal);
                    }
                }
            }
        }

        // Initialize disabled states and attach event listeners on page load
        function initializeDisabledStates() {
            // Initialize offer checkboxes
            const offerRows = document.querySelectorAll('.offer-rotation-row');
            offerRows.forEach((row) => {
                const checkbox = row.querySelector('input[type="checkbox"]');
                if (checkbox && checkbox.id && checkbox.id.startsWith('offer_checkbox_')) {
                    const idx = checkbox.id.replace('offer_checkbox_', '');
                    
                    // Remove any existing onchange to avoid duplicates
                    checkbox.removeAttribute('onchange');
                    
                    // Attach event listener
                    checkbox.addEventListener('change', function() {
                        handleOfferEnabledChange(idx, this.checked);
                    });
                    
                    // Lock without HTML disabled so values still post on save
                    setRotationControlLocked(
                        document.getElementById('offer_select_' + idx),
                        document.getElementById('offer_weight_' + idx),
                        checkbox.checked
                    );
                }
            });
            
            // Initialize LP checkboxes
            const lpContainer = document.getElementById('lp_items');
            if (lpContainer) {
                const lpRows = lpContainer.querySelectorAll('div[style*="grid-template-columns"]');
                lpRows.forEach((row) => {
                    const checkbox = row.querySelector('input[type="checkbox"]');
                    if (checkbox && checkbox.id && checkbox.id.startsWith('lp_checkbox_')) {
                        const idx = checkbox.id.replace('lp_checkbox_', '');
                        
                        // Remove any existing onchange to avoid duplicates
                        checkbox.removeAttribute('onchange');
                        
                        // Attach event listener
                        checkbox.addEventListener('change', function() {
                            handleLPEnabledChange(idx, this.checked);
                        });
                        
                        setRotationControlLocked(
                            document.getElementById('lp_select_' + idx),
                            document.getElementById('lp_weight_' + idx),
                            checkbox.checked
                        );
                    }
                });
            }
            
            // Note: Split flow type uses the same LPs as LP flow type (lp_items container)
            // So we don't need separate split LP checkbox handlers - they use handleLPEnabledChange
            // The split_lp_items container doesn't exist - Split uses lp_items
            const splitLPContainer = null; // Dead code - container doesn't exist
            if (false && splitLPContainer) { // Never executes
                const splitLPRows = splitLPContainer.querySelectorAll('div[style*="grid-template-columns"]');
                splitLPRows.forEach((row) => {
                    const checkbox = row.querySelector('input[type="checkbox"]');
                    if (checkbox && checkbox.id && checkbox.id.startsWith('split_lp_checkbox_')) {
                        const idx = checkbox.id.replace('split_lp_checkbox_', '');
                        
                        // Remove any existing onchange to avoid duplicates
                        checkbox.removeAttribute('onchange');
                        
                        // Attach event listener
                        checkbox.addEventListener('change', function() {
                            handleSplitLPEnabledChange(idx, this.checked);
                        });
                        
                        setRotationControlLocked(
                            document.getElementById('split_lp_select_' + idx),
                            document.getElementById('split_lp_weight_' + idx),
                            checkbox.checked
                        );
                    }
                });
            }
        }

        // Disabled <select>/<input> are omitted from POST, which reindexes offer_id[] /
        // offer_weight[] (and LP equivalents) against offer_enabled[n] / lp_enabled[n].
        // Re-enable right before submit so disabled rows still post (weight 0 + enabled 0).
        function enableDisabledRotationFieldsForSubmit() {
            const selectors = [
                '#offer_rotation_items select[name="offer_id[]"]',
                '#offer_rotation_items input[name="offer_weight[]"]',
                '#lp_items select[name="lp_id[]"]',
                '#lp_items input[name="lp_weight[]"]'
            ];
            selectors.forEach(function (sel) {
                document.querySelectorAll(sel).forEach(function (el) {
                    if (el.disabled) {
                        el.disabled = false;
                    }
                });
            });
        }

        function bindCampaignFormRotationSubmit() {
            const form = document.getElementById('campaign-form');
            if (!form || form.dataset.rotationSubmitBound === '1') {
                return;
            }
            form.dataset.rotationSubmitBound = '1';
            form.addEventListener('submit', function () {
                enableDisabledRotationFieldsForSubmit();
            });
        }

        // Initialize Facebook integration field visibility on page load
        document.addEventListener('DOMContentLoaded', function() {
            toggleFacebookIntegration();
            toggleGoogleAdsIntegration();
            initializeDisabledStates();
            updateFlowFields(); // Initialize flow fields visibility
            bindCampaignFormRotationSubmit();
        });
        
        // Also call on immediate load (for edit pages that may load before DOMContentLoaded)
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', toggleFacebookIntegration);
            document.addEventListener('DOMContentLoaded', initializeDisabledStates);
            document.addEventListener('DOMContentLoaded', updateFlowFields);
            document.addEventListener('DOMContentLoaded', bindCampaignFormRotationSubmit);
        } else {
            toggleFacebookIntegration();
            toggleGoogleAdsIntegration();
            initializeDisabledStates();
            updateFlowFields(); // Initialize flow fields visibility
            bindCampaignFormRotationSubmit();
        }

        function updateFlowFields() {
            const flowType = document.getElementById('flow_type').value;
            // LP and Split: Show LP rotation box (both use the same LPs)
            document.getElementById('lp_fields').style.display = (flowType === 'LP' || flowType === 'Split') ? 'block' : 'none';
            // Split: Show split percentage configuration
            document.getElementById('split_fields').style.display = flowType === 'Split' ? 'block' : 'none';
            // Note: Offer rotation section is always visible - no need to toggle it
        }
        
        function updateSplitPercentage() {
            const input = document.getElementById('split_traffic_to_lp_input');
            const remainingSpan = document.getElementById('split_remaining_percent');
            if (input && remainingSpan) {
                const lpPercent = parseInt(input.value) || 0;
                const directPercent = 100 - lpPercent;
                remainingSpan.textContent = `(${directPercent}% Direct)`;
            }
        }

        function addOfferRotationItem() {
            const container = document.getElementById('offer_rotation_items');
            if (!container) return;
            const existingRows = container.querySelectorAll('.offer-rotation-row');
            const newIndex = existingRows.length;
            if (existingRows.length === 0) return;
            const newItem = existingRows[0].cloneNode(true);
            
            // Reset form fields
            newItem.querySelector('select[name="offer_id[]"]').selectedIndex = 0;
            const weightInput = newItem.querySelector('input[name="offer_weight[]"]');
            if (weightInput) weightInput.value = '';
            
            // Update hidden input name and value to use new index
            const hiddenInput = newItem.querySelector('input[type="hidden"][name^="offer_enabled"]');
            const checkbox = newItem.querySelector('input[type="checkbox"]');
            const select = newItem.querySelector('select[name="offer_id[]"]');
            
            if (hiddenInput) {
                hiddenInput.name = 'offer_enabled[' + newIndex + ']';
                hiddenInput.id = 'offer_enabled_hidden_' + newIndex;
                hiddenInput.value = '1'; // Default enabled
            }
            if (checkbox) {
                checkbox.id = 'offer_checkbox_' + newIndex;
                checkbox.checked = true; // Default enabled
                checkbox.removeAttribute('name'); // Checkbox has no name, only updates hidden input
                checkbox.removeAttribute('onchange'); // Remove inline handler
                
                // Attach event listener
                checkbox.addEventListener('change', function() {
                    handleOfferEnabledChange(newIndex, this.checked);
                });
            }
            if (select) {
                select.id = 'offer_select_' + newIndex;
            }
            if (weightInput) {
                weightInput.id = 'offer_weight_' + newIndex;
            }
            setRotationControlLocked(select, weightInput, true);
            
            container.appendChild(newItem);
        }

        function addLPItem() {
            const container = document.getElementById('lp_items');
            const existingRows = container.querySelectorAll('div[style*="grid-template-columns"]');
            const newIndex = existingRows.length;
            const firstItem = existingRows[0];
            if (!firstItem) return;
            const newItem = firstItem.cloneNode(true);
            
            // Reset form fields
            newItem.querySelector('select[name="lp_id[]"]').selectedIndex = 0;
            const weightInput = newItem.querySelector('input[name="lp_weight[]"]');
            if (weightInput) weightInput.value = '';
            
            // Update hidden input name and value to use new index
            const hiddenInput = newItem.querySelector('input[type="hidden"][name^="lp_enabled"]');
            const checkbox = newItem.querySelector('input[type="checkbox"]');
            const select = newItem.querySelector('select[name="lp_id[]"]');
            
            if (hiddenInput) {
                hiddenInput.name = 'lp_enabled[' + newIndex + ']';
                hiddenInput.id = 'lp_enabled_hidden_' + newIndex;
                hiddenInput.value = '1'; // Default enabled
            }
            if (checkbox) {
                checkbox.id = 'lp_checkbox_' + newIndex;
                checkbox.checked = true; // Default enabled
                checkbox.removeAttribute('name'); // Checkbox has no name, only updates hidden input
                checkbox.removeAttribute('onchange'); // Remove inline handler
                
                // Attach event listener
                checkbox.addEventListener('change', function() {
                    handleLPEnabledChange(newIndex, this.checked);
                });
            }
            if (select) {
                select.id = 'lp_select_' + newIndex;
            }
            if (weightInput) {
                weightInput.id = 'lp_weight_' + newIndex;
            }
            setRotationControlLocked(select, weightInput, true);
            
            container.appendChild(newItem);
        }

        function copyTrackingLink(fullUrl, btnElement) {
            // fullUrl already contains the complete URL with all parameters
            const btn = btnElement;
            
            if (!btn) {
                console.error('Button element not found');
                return;
            }
            
            const originalText = btn.innerHTML;
            const originalBackground = btn.style.background;
            const originalColor = btn.style.color;
            const originalBorderColor = btn.style.borderColor;
            
            // Function to restore original button appearance
            const restoreButton = () => {
                btn.innerHTML = originalText;
                btn.style.background = originalBackground;
                btn.style.color = originalColor;
                btn.style.borderColor = originalBorderColor;
            };
            
            // Function to show success feedback
            const showSuccess = () => {
                btn.innerHTML = '✓';
                btn.style.background = '#4caf50';
                btn.style.color = '#fff';
                btn.style.borderColor = '#4caf50';
                btn.style.transform = 'scale(1.1)';
                btn.title = 'Copied!';
                setTimeout(() => {
                    restoreButton();
                    btn.style.transform = '';
                    btn.title = 'Copy full tracking link with all parameters';
                }, 2000);
            };
            
            // Fallback copy function
            const fallbackCopy = () => {
                try {
                    const input = document.createElement('textarea');
                    input.value = fullUrl;
                    input.style.position = 'fixed';
                    input.style.opacity = '0';
                    document.body.appendChild(input);
                    input.select();
                    const success = document.execCommand('copy');
                    document.body.removeChild(input);
                    
                    if (success) {
                        showSuccess();
                    } else {
                        console.error('Fallback copy failed');
                        alert('Failed to copy. Please copy manually: ' + fullUrl);
                    }
                } catch (err) {
                    console.error('Copy error:', err);
                    alert('Failed to copy. Please copy manually: ' + fullUrl);
                }
            };
            
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(fullUrl).then(() => {
                    showSuccess();
                }).catch(err => {
                    console.error('Clipboard API error:', err);
                    fallbackCopy();
                });
            } else {
                fallbackCopy();
            }
        }
    </script>
<?php
$fbPickerJsPath = __DIR__ . '/../public/assets/js/facebook-campaign-picker.js';
$fbPickerJs = ASSETS_BASE_URL . '/assets/js/facebook-campaign-picker.js?v=' . (file_exists($fbPickerJsPath) ? filemtime($fbPickerJsPath) : '1');
?>
<script src="<?= htmlspecialchars($fbPickerJs) ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.FacebookCampaignPicker) {
        window.FacebookCampaignPicker.init({
            selectedCampaignId: <?= json_encode($editCampaign ? ($editCampaign['facebook_marketing_campaign_id'] ?? null) : null) ?>,
        });
    }
});
</script>
<?php endif; ?>
