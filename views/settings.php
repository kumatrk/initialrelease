<?php
// Settings Page
// CRITICAL: Load Composer autoloader FIRST before any other code
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

require_once __DIR__ . '/../config/config.php';

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

use SimpleKuma\Settings\SettingsManager;
use SimpleKuma\Auth\Auth;
use SimpleKuma\Auth\Csrf;
use SimpleKuma\Auth\LoginGate;
use SimpleKuma\Auth\Permission;
use SimpleKuma\Auth\SingleAdminMode;
use SimpleKuma\Database\MigrationRunner;
use SimpleKuma\Entity\CampaignGroup;
use SimpleKuma\Entity\FacebookCapiIntegration;
use SimpleKuma\Entity\GoogleAdsIntegration;
use SimpleKuma\Entity\FacebookMarketingIntegration;
use SimpleKuma\Entity\CustomPostback;
use SimpleKuma\Entity\Campaign;
use SimpleKuma\Entity\TrafficSource;
use SimpleKuma\Entity\User;
use SimpleKuma\Entity\TrackingDomain;
use SimpleKuma\Auth\AuditLogger;
use SimpleKuma\GeoIP\GeoResolver;
use SimpleKuma\GeoIP\Providers\DBIPProvider;
use SimpleKuma\GeoIP\Providers\IP2LocationProvider;
use SimpleKuma\GeoIP\Providers\IPinfoProvider;

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$settings = new SettingsManager($db);
$auth = new Auth($db);
$campaignGroup = new CampaignGroup($db);
$facebookCapi = new FacebookCapiIntegration($db);
$googleAds = new GoogleAdsIntegration($db);
$facebookMarketing = new FacebookMarketingIntegration($db);
$customPostback = new CustomPostback($db);
$campaignEntity = new Campaign($db);
$trafficSourceEntity = new TrafficSource($db);
$userEntity = new User($db);
$trackingDomainEntity = new TrackingDomain($db);
$auditLogger = new AuditLogger($db);

$currentUser = $auth->getCurrentUser();
$permission = $auth->getPermission();
$activeTab = $_GET['tab'] ?? 'domains';
$success = '';
$errors = [];

// Helper function to fetch and sync Facebook ad accounts for an integration
function fetchAndSyncFacebookAdAccounts($db, $integrationId, $accessToken, $proxyConfig = null) {
    require_once __DIR__ . '/../src/Entity/FacebookMarketingAdAccount.php';
    require_once __DIR__ . '/../src/Http/ProxyHandler.php';
    
    $adAccountEntity = new \SimpleKuma\Entity\FacebookMarketingAdAccount($db);
    
    // TEMPORARY: List of ad account IDs to exclude from import (numeric IDs only)
    $excludedAdAccountIds = [
        '1504051704056225', // Gutters
        '3025569751081602', // Bathroom Remodel
        '737217094281750',  // Conceal Carry
        '516733532603754',  // Home Security
        '6266888846696915', // Home Warranty
        '2065200140361975', // Home Warranty 2
        '911802023290518'   // Window Replacement
    ];
    
    // Helper function to check if an account ID should be excluded
    // Handles both "act_123456789" and "123456789" formats
    $isExcluded = function($accountId) use ($excludedAdAccountIds) {
        // Remove "act_" prefix if present for comparison
        $numericId = preg_replace('/^act_/', '', $accountId);
        return in_array($numericId, $excludedAdAccountIds, true);
    };
    
    // Fetch ad accounts from Facebook API
    // Include timezone_name field to auto-detect ad account timezone
    $adAccountsUrl = "https://graph.facebook.com/v24.0/me/adaccounts?access_token=" . urlencode($accessToken) . "&fields=id,name,account_id,currency,business,timezone_name&limit=100";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $adAccountsUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    // Apply proxy if configured
    if ($proxyConfig && !empty($proxyConfig['use_proxy'])) {
        \SimpleKuma\Http\ProxyHandler::configureCurl($ch, $proxyConfig);
    }
    
    $adAccountsResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $adAccountsData = json_decode($adAccountsResponse, true);
        if (isset($adAccountsData['data']) && is_array($adAccountsData['data'])) {
            $accounts = [];
            foreach ($adAccountsData['data'] as $account) {
                // TEMPORARY: Skip excluded ad accounts
                $accountId = $account['id'] ?? '';
                if ($isExcluded($accountId)) {
                    continue; // Skip this ad account
                }
                
                // Extract business manager ID if available
                $businessId = null;
                $businessName = null;
                if (isset($account['business'])) {
                    if (is_array($account['business']) && isset($account['business']['id'])) {
                        $businessId = $account['business']['id'];
                        $businessName = $account['business']['name'] ?? null;
                    } elseif (is_string($account['business'])) {
                        $businessId = $account['business'];
                    }
                }
                
                // Extract timezone from Facebook API response
                // Facebook returns timezone_name (e.g., "America/Chicago")
                $timezone = $account['timezone_name'] ?? null;
                
                $accounts[] = [
                    'ad_account_id' => $accountId,
                    'ad_account_name' => $account['name'] ?? 'Unknown',
                    'account_id' => $account['account_id'] ?? null,
                    'currency' => $account['currency'] ?? null,
                    'business_id' => $businessId,
                    'business_name' => $businessName,
                    'timezone' => $timezone
                ];
            }
            
            // Sync ad accounts to database
            if ($adAccountEntity->syncForIntegration($integrationId, $accounts)) {
                return ['success' => true, 'count' => count($accounts)];
            } else {
                return ['success' => false, 'error' => 'Failed to sync ad accounts to database'];
            }
        }
    }
    
    return ['success' => false, 'error' => 'Failed to fetch ad accounts from Facebook API'];
}

// Handle AJAX request to fetch and save Facebook ad accounts
if (isset($_GET['ajax']) && $_GET['ajax'] === 'fetch_fb_ad_accounts') {
    header('Content-Type: application/json');

    $canEditAjax = ($permission && $permission->hasPermission(Permission::PERM_SETTINGS_EDIT))
        || (Auth::allowsLegacyNoRolesFallback() && empty($_SESSION['role_ids'] ?? []));
    if (!$canEditAjax) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit;
    }

    // Prefer POST body so tokens are not stored in access logs / Referer
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST required']);
        exit;
    }
    if (!Csrf::validate()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
    $accessToken = trim((string) ($_POST['access_token'] ?? ''));
    $integrationId = !empty($_POST['integration_id']) ? (int) $_POST['integration_id'] : 0;
    
    if (empty($accessToken)) {
        echo json_encode(['success' => false, 'error' => 'Access token required']);
        exit;
    }
    
    if ($integrationId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Integration ID required']);
        exit;
    }
    
    // Get integration to get proxy config
    $integration = $facebookMarketing->getById($integrationId, true);
    if (!$integration) {
        echo json_encode(['success' => false, 'error' => 'Integration not found']);
        exit;
    }
    
    $proxyConfig = null;
    if (!empty($integration['use_proxy'])) {
        $proxyConfig = [
            'use_proxy' => true,
            'proxy_host' => $integration['proxy_host'],
            'proxy_port' => $integration['proxy_port'],
            'proxy_type' => $integration['proxy_type'],
            'proxy_user' => $integration['proxy_user'],
            'proxy_pass' => !empty($integration['proxy_pass']) ? $integration['proxy_pass'] : null
        ];
    }
    
    // Fetch and sync ad accounts (saves all to database)
    $result = fetchAndSyncFacebookAdAccounts($db, $integrationId, $accessToken, $proxyConfig);
    
    if ($result['success']) {
        // Get the saved ad accounts to return
        require_once __DIR__ . '/../src/Entity/FacebookMarketingAdAccount.php';
        $adAccountEntity = new \SimpleKuma\Entity\FacebookMarketingAdAccount($db);
        $savedAccounts = $adAccountEntity->getByIntegrationId($integrationId);
        
        $accounts = [];
        foreach ($savedAccounts as $account) {
            $accounts[] = [
                'id' => $account['ad_account_id'],
                'name' => $account['ad_account_name'],
                'account_id' => $account['account_id'],
                'currency' => $account['currency']
            ];
        }
        
        echo json_encode(['success' => true, 'accounts' => $accounts, 'count' => count($accounts)]);
    } else {
        echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Failed to fetch and save ad accounts']);
    }
    exit;
}

// Facebook cost-sync diagnostics for Settings → API Cost Updates → View details
if (isset($_GET['ajax']) && $_GET['ajax'] === 'fb_cost_sync_diagnostics') {
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../src/Facebook/FacebookApiCallTracker.php';
        $tableCheck = $db->query("SHOW TABLES LIKE 'facebook_api_calls'");
        if (!$tableCheck || $tableCheck->num_rows === 0) {
            echo json_encode([
                'success' => false,
                'error' => 'facebook_api_calls table not found. Run pending migrations.',
            ]);
            exit;
        }
        $tracker = new \SimpleKuma\Facebook\FacebookApiCallTracker($db);
        echo json_encode([
            'success' => true,
            'diagnostics' => $tracker->getDiagnosticsPayload(),
        ]);
    } catch (Throwable $e) {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to load diagnostics: ' . $e->getMessage(),
        ]);
    }
    exit;
}

// Handle success messages from redirects
if (isset($_GET['success'])) {
    $successMap = [
        'fb_integration_created' => 'Facebook CAPI integration created successfully',
        'fb_integration_updated' => 'Facebook CAPI integration updated successfully',
        'ga_integration_created' => 'Google Ads integration created successfully',
        'ga_integration_updated' => 'Google Ads integration updated successfully',
        'ga_cost_integration_created' => 'Google Ads cost integration created successfully. Conversion CSV URL is under Integrations (delivery defaults to CSV).',
        'ga_cost_integration_updated' => 'Google Ads cost integration updated successfully',
        'ga_cost_integration_deleted' => 'Google Ads cost integration deleted successfully',
        'fm_integration_created' => 'Facebook Marketing API integration created successfully',
        'fm_integration_updated' => 'Facebook Marketing API integration updated successfully',
        'custom_postback_created' => 'Custom postback created successfully',
        'custom_postback_updated' => 'Custom postback updated successfully',
        'user_created' => 'User created successfully',
        'user_updated' => 'User updated successfully',
        'user_deleted' => 'User deleted successfully',
        'domain_created' => 'Tracking domain added successfully',
        'domain_updated' => 'Tracking domain updated successfully',
        'domain_deleted' => 'Tracking domain deleted successfully',
        'domain_tested' => 'Domain verification completed',
        'update_settings_saved' => 'Update settings saved successfully',
        'update_check_complete' => 'Update check completed',
        'application_updated' => 'Simple Kuma updated successfully',
        'db_migrations_applied' => 'Database updated successfully',
        'db_up_to_date' => 'Database schema is already up to date',
        'login_gate_saved' => 'Login page privacy settings saved.',
        'edge_settings_saved' => 'Edge Redirect settings saved',
        'edge_deployed' => 'Edge Redirect Worker deployed successfully',
        'edge_disabled' => 'Edge Redirect disabled',
        'edge_health_ok' => 'Edge health check passed',
        'edge_synced' => 'Campaigns synced to Cloudflare KV',
        'edge_secret_rotated' => 'Ingest secret rotated — redeploy the Worker to push the new secret',
    ];
    $success = $successMap[$_GET['success']] ?? '';
    if ($_GET['success'] === 'login_gate_saved' && !empty($_SESSION['login_gate_url_reveal'])) {
        $success = 'Login page privacy settings saved. Copy your private login URL below — it will not be shown again.';
    }
    
    // Handle fetched accounts message
    if (isset($_GET['fetched_accounts']) && $_GET['fetched_accounts'] == '1') {
        $accountCount = isset($_GET['account_count']) ? (int)$_GET['account_count'] : 0;
        $accountMsg = $accountCount > 0 
            ? "Ad accounts fetched and saved successfully ({$accountCount} account" . ($accountCount !== 1 ? 's' : '') . ")"
            : 'Ad accounts fetched and saved successfully';
        $success = ($success ? $success . '. ' : '') . $accountMsg;
    }
}

// Flash from database migration button (success or failure)
if (!empty($_SESSION['flash_db_migrations']) && is_array($_SESSION['flash_db_migrations'])) {
    $flash = $_SESSION['flash_db_migrations'];
    unset($_SESSION['flash_db_migrations']);
    if (!empty($flash['message']) && is_string($flash['message'])) {
        $success = $flash['message'];
    }
    if (!empty($flash['errors']) && is_array($flash['errors'])) {
        $errors['general'] = implode(' ', $flash['errors']);
    }
}

if (!empty($_SESSION['flash_application_update']) && is_array($_SESSION['flash_application_update'])) {
    $flash = $_SESSION['flash_application_update'];
    unset($_SESSION['flash_application_update']);
    if (!empty($flash['message']) && is_string($flash['message'])) {
        $success = $flash['message'];
    }
    if (!empty($flash['errors']) && is_array($flash['errors'])) {
        $errors['general'] = implode(' ', array_map('strval', $flash['errors']));
    }
}

// Handle composer install success message
if (isset($_GET['composer_installed']) && $_GET['composer_installed'] === '1') {
    $success = 'Composer dependencies installed successfully! Please refresh the page to see updated status.';
}

// Load tracking domains for domains tab
$allDomains = [];
$editingDomain = null;
$testResult = null; // Initialize test result variable

// Load test result from session if available (check BEFORE domains tab check)
// This ensures test results are available regardless of tab state
if (isset($_GET['success']) && $_GET['success'] === 'domain_tested') {
    // Debug: Log what we're looking for
    error_log("Looking for domain_test_result in session. Session keys: " . print_r(array_keys($_SESSION ?? []), true));
    
    if (isset($_SESSION['domain_test_result'])) {
        $testResult = $_SESSION['domain_test_result'];
        error_log("Found domain_test_result: " . print_r($testResult, true));
        unset($_SESSION['domain_test_result']); // Clear after use
    } else {
        // Debug: If success param is set but no session data, something went wrong
        error_log("domain_test_result NOT found in session!");
        $testResult = [
            'status' => 'failed',
            'dns_ok' => false,
            'ssl_ok' => false,
            'dns_message' => 'Test was run but results were not saved. Session may have expired. Please try again.',
            'ssl_message' => 'Session data missing.',
            'error' => 'Session data not found after test completion. This might indicate a session configuration issue.'
        ];
    }
}

if ($activeTab === 'domains') {
    $allDomains = $trackingDomainEntity->getAll();
    $domainAction = $_GET['domain_action'] ?? 'list';
    $domainId = isset($_GET['domain_id']) ? (int)$_GET['domain_id'] : null;
    
    if ($domainAction === 'edit' && $domainId) {
        $editingDomain = $trackingDomainEntity->getById($domainId);
        if (!$editingDomain) {
            $errors['general'] = 'Domain not found';
        }
    }
}

// Handle GET actions (like test_domain)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'test_domain') {
    $id = (int)($_GET['domain_id'] ?? 0);
    if ($id > 0) {
        // Ensure session is started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Run verification
        $verificationResult = $trackingDomainEntity->verify($id);
        
        // Ensure all fields are set with defaults
        $testResultData = [
            'domain_id' => $id,
            'dns_ok' => isset($verificationResult['dns_ok']) ? (bool)$verificationResult['dns_ok'] : false,
            'ssl_ok' => isset($verificationResult['ssl_ok']) ? (bool)$verificationResult['ssl_ok'] : false,
            'status' => $verificationResult['status'] ?? 'pending',
            'dns_message' => $verificationResult['dns_message'] ?? 'DNS check was not performed',
            'ssl_message' => $verificationResult['ssl_message'] ?? 'SSL check was not performed',
            'error' => $verificationResult['error'] ?? null
        ];
        
        // Store test results in session to display after redirect
        $_SESSION['domain_test_result'] = $testResultData;
        
        // Reload domains to get updated status
        $allDomains = $trackingDomainEntity->getAll();
        
        // Debug: Log what we're storing
        error_log("Domain test result stored: " . print_r($testResultData, true));
        
        header('Location: ?page=settings&tab=domains&success=domain_tested&domain_id=' . $id);
        exit;
    } else {
        $errors['general'] = 'Invalid domain ID';
    }
}

// CSRF token for settings forms
Csrf::ensureToken();

$canEditSettings = ($permission && $permission->hasPermission(Permission::PERM_SETTINGS_EDIT))
    || (\SimpleKuma\Auth\Auth::allowsLegacyNoRolesFallback() && empty($_SESSION['role_ids'] ?? []));

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Handle composer install (must be before tab-specific handlers)
    if (!Csrf::validate()) {
        $errors['general'] = Csrf::invalidRequestMessage();
    } elseif (
        in_array($action, [
            'update_settings',
            'save_update_settings',
            'check_updates',
            'start_update',
            'create_group',
            'update_group',
            'delete_group',
            'create_fb_integration',
            'update_fb_integration',
            'delete_fb_integration',
            'create_ga_integration',
            'update_ga_integration',
            'delete_ga_integration',
            'create_ga_cost_integration',
            'update_ga_cost_integration',
            'delete_ga_cost_integration',
            'create_custom_postback',
            'update_custom_postback',
            'delete_custom_postback',
            'create_fm_integration',
            'update_fm_integration',
            'delete_fm_integration',
            'create_domain',
            'update_domain',
            'delete_domain',
        ], true) && !$canEditSettings
    ) {
        $errors['general'] = 'You do not have permission to edit settings.';
    } elseif ($action === 'install_composer') {
        if (!$canEditSettings) {
            $errors['general'] = 'You do not have permission to install dependencies.';
        } else {
        // Set longer execution time for composer install
        @set_time_limit(300); // 5 minutes
        @ini_set('max_execution_time', '300');
        
        // Log that we received the request
        error_log("Composer install: POST request received. Action: " . ($_POST['action'] ?? 'not set'));
        
        try {
            error_log("Composer install: Starting installation process");
            $installer = new \SimpleKuma\Installer\DependencyInstaller();
            
            if (!$installer->canExecuteCommands()) {
                $errors['general'] = 'Command execution is disabled on this server. Please install dependencies manually via SSH or contact your hosting provider.';
                error_log("Composer install: Command execution disabled");
            } else {
                error_log("Composer install: Command execution enabled, proceeding...");
                $composerSuccess = $installer->installComposerDependencies();
                $messages = $installer->getMessages();
                $installErrors = $installer->getErrors();
                
                error_log("Composer install: Success=" . ($composerSuccess ? 'true' : 'false'));
                error_log("Composer install: Messages=" . implode(' | ', $messages));
                error_log("Composer install: Errors=" . implode(' | ', $installErrors));
                
                if ($composerSuccess) {
                    $success = 'Composer dependencies installed successfully! Please refresh the page to see updated status.';
                    // Redirect to avoid form resubmission
                    header('Location: ?page=settings&tab=geoip&composer_installed=1');
                    exit;
                } else {
                    $errorMsg = 'Composer installation failed.';
                    if (!empty($installErrors)) {
                        $errorMsg .= ' Errors: ' . implode(' ', $installErrors);
                    }
                    if (!empty($messages)) {
                        $errorMsg .= ' Details: ' . implode(' ', $messages);
                    }
                    $errors['general'] = $errorMsg;
                }
            }
        } catch (\Exception $e) {
            $errorMsg = 'Error installing dependencies: ' . $e->getMessage();
            error_log("Composer install exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            $errors['general'] = $errorMsg;
        } catch (\Throwable $e) {
            $errorMsg = 'Fatal error installing dependencies: ' . $e->getMessage();
            error_log("Composer install fatal error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            $errors['general'] = $errorMsg;
        }
        } // end canEditSettings install_composer
    } elseif ($action === 'change_password') {
        $changeResult = $auth->changePassword(
            (int) $_SESSION['user_id'],
            (string) ($_POST['current_password'] ?? ''),
            (string) ($_POST['new_password'] ?? ''),
            (string) ($_POST['confirm_password'] ?? '')
        );
        if (!empty($changeResult['success'])) {
            $success = $changeResult['message'] ?? 'Password changed successfully';
        } else {
            foreach (($changeResult['errors'] ?? []) as $field => $message) {
                $errors[$field] = $message;
            }
            if (empty($errors)) {
                $errors['general'] = 'Failed to update password';
            }
        }
    } elseif ($action === 'update_login_gate') {
        $canEditLoginGate = SingleAdminMode::isEnabled()
            || ($permission && $permission->hasPermission(Permission::PERM_SETTINGS_EDIT));

        if (!$canEditLoginGate) {
            $errors['general'] = 'You do not have permission to change login page privacy settings.';
        } else {
            $loginGate = new LoginGate();
            $result = $loginGate->saveSettings($db, [
                'enabled' => isset($_POST['login_gate_enabled']),
                'secret' => (string) ($_POST['login_gate_secret'] ?? $_POST['login_gate_token'] ?? ''),
                'param' => (string) ($_POST['login_gate_param'] ?? LoginGate::DEFAULT_PARAM_NAME),
                'redirect_url' => (string) ($_POST['login_gate_redirect_url'] ?? ''),
                'clear_token' => isset($_POST['login_gate_clear_token']),
            ]);

            if (!$result['success']) {
                foreach ($result['errors'] as $field => $message) {
                    $errors[$field] = $message;
                }
            } else {
                $success = 'Login page privacy settings saved.';
                if (!empty($result['plain_token'])) {
                    $_SESSION['login_gate_url_reveal'] = $loginGate->buildLoginUrl($db, $result['plain_token']);
                    $success .= ' Copy your private login URL below — it will not be shown again.';
                }
                header('Location: ?page=settings&tab=account&success=login_gate_saved');
                exit;
            }
        }
    } elseif ($action === 'update_settings') {
        $settingsData = [
            'attribution_window_days' => $_POST['attribution_window_days'] ?? '30',
            'fb_capi_pixel_id' => $_POST['fb_capi_pixel_id'] ?? '',
            'fb_capi_access_token' => $_POST['fb_capi_access_token'] ?? '',
            'fb_capi_test_code' => $_POST['fb_capi_test_code'] ?? '',
            'log_retention_days' => $_POST['log_retention_days'] ?? '0',
            'ip_anonymization' => isset($_POST['ip_anonymization']) ? '1' : '0',
            'bot_detection_enabled' => isset($_POST['bot_detection_enabled']) ? '1' : '0',
            'bot_exclude_known_from_stats' => isset($_POST['bot_exclude_known_from_stats']) ? '1' : '0',
            'bot_exclude_suspected_from_stats' => isset($_POST['bot_exclude_suspected_from_stats']) ? '1' : '0',
            'archive_after_days' => $_POST['archive_after_days'] ?? '365',
        ];

        if ($settings->setMultiple($settingsData)) {
            $success = 'Settings saved successfully';
        } else {
            $errors['general'] = 'Failed to save settings';
        }
    } elseif ($action === 'save_edge_redirect_settings') {
        $canEdit = $permission && $permission->hasPermission(Permission::PERM_SETTINGS_EDIT);
        if (!$canEdit) {
            $errors['general'] = 'You do not have permission to edit settings.';
        } else {
            $edgeSettings = new \SimpleKuma\Edge\EdgeSettings($db);
            $sm = $edgeSettings->settingsManager();
            $sm->set(\SimpleKuma\Edge\EdgeSettings::KEY_CF_ACCOUNT_ID, trim((string) ($_POST['cf_account_id'] ?? '')));
            $sm->set(\SimpleKuma\Edge\EdgeSettings::KEY_CF_ZONE_ID, trim((string) ($_POST['cf_zone_id'] ?? '')));
            $sm->set(\SimpleKuma\Edge\EdgeSettings::KEY_CF_WORKER_NAME, trim((string) ($_POST['cf_worker_name'] ?? '')) ?: \SimpleKuma\Edge\EdgeSettings::DEFAULT_WORKER_NAME);
            $sm->set(\SimpleKuma\Edge\EdgeSettings::KEY_CF_ROUTE_PATTERN, trim((string) ($_POST['cf_route_pattern'] ?? '')));
            $sm->set(\SimpleKuma\Edge\EdgeSettings::KEY_ORIGIN_BASE_URL, rtrim(trim((string) ($_POST['origin_base_url'] ?? '')), '/'));

            $apiToken = trim((string) ($_POST['cf_api_token'] ?? ''));
            if ($apiToken !== '') {
                $edgeSettings->setApiToken($apiToken);
            }

            $edgeSettings->ensureIngestSecret();
            header('Location: ?page=settings&tab=edge-redirect&success=edge_settings_saved');
            exit;
        }
    } elseif ($action === 'deploy_edge_worker') {
        $canEdit = $permission && $permission->hasPermission(Permission::PERM_SETTINGS_EDIT);
        if (!$canEdit) {
            $errors['general'] = 'You do not have permission to deploy the Edge Worker.';
        } else {
            $deployer = new \SimpleKuma\Edge\EdgeDeployer($db);
            $result = $deployer->deploy();
            if ($result['ok']) {
                header('Location: ?page=settings&tab=edge-redirect&success=edge_deployed');
                exit;
            }
            $errors['general'] = $result['message'];
        }
    } elseif ($action === 'edge_health_check') {
        $canEdit = $permission && $permission->hasPermission(Permission::PERM_SETTINGS_EDIT);
        if (!$canEdit) {
            $errors['general'] = 'You do not have permission to run health checks.';
        } else {
            $deployer = new \SimpleKuma\Edge\EdgeDeployer($db);
            $result = $deployer->healthCheck();
            if ($result['ok']) {
                header('Location: ?page=settings&tab=edge-redirect&success=edge_health_ok');
                exit;
            }
            $errors['general'] = $result['message'];
        }
    } elseif ($action === 'disable_edge_redirect') {
        $canEdit = $permission && $permission->hasPermission(Permission::PERM_SETTINGS_EDIT);
        if (!$canEdit) {
            $errors['general'] = 'You do not have permission to disable Edge Redirect.';
        } else {
            $deployer = new \SimpleKuma\Edge\EdgeDeployer($db);
            $deployer->disable();
            header('Location: ?page=settings&tab=edge-redirect&success=edge_disabled');
            exit;
        }
    } elseif ($action === 'sync_edge_campaigns') {
        $canEdit = $permission && $permission->hasPermission(Permission::PERM_SETTINGS_EDIT);
        if (!$canEdit) {
            $errors['general'] = 'You do not have permission to sync campaigns.';
        } else {
            $sync = new \SimpleKuma\Edge\EdgeCampaignSync($db);
            $summary = $sync->syncAllEligible();
            if (!empty($summary['errors'])) {
                $errors['general'] = 'Sync finished with errors: ' . implode('; ', array_slice($summary['errors'], 0, 5));
            } else {
                header('Location: ?page=settings&tab=edge-redirect&success=edge_synced');
                exit;
            }
        }
    } elseif ($action === 'rotate_edge_ingest_secret') {
        $canEdit = $permission && $permission->hasPermission(Permission::PERM_SETTINGS_EDIT);
        if (!$canEdit) {
            $errors['general'] = 'You do not have permission to rotate the ingest secret.';
        } else {
            $edgeSettings = new \SimpleKuma\Edge\EdgeSettings($db);
            $edgeSettings->rotateIngestSecret();
            header('Location: ?page=settings&tab=edge-redirect&success=edge_secret_rotated');
            exit;
        }
    } elseif ($action === 'save_update_settings') {
        $canManageUpdates = $permission && $permission->hasPermission(Permission::PERM_UPDATE_MANAGE);
        $updateCheckEnabled = isset($_POST['update_check_enabled'])
            && $_POST['update_check_enabled'] === '1' ? '1' : '0';

        if (!$canManageUpdates) {
            $errors['general'] = 'You do not have permission to manage application updates.';
        } elseif ($settings->set('update_check_enabled', $updateCheckEnabled)) {
            // Redirect to prevent form resubmission and ensure checkbox state is refreshed
            header('Location: ?page=settings&tab=updates&success=update_settings_saved');
            exit;
        } else {
            $errors['general'] = 'Failed to save update settings';
        }
    } elseif ($action === 'check_updates') {
        $canManageUpdates = $permission && $permission->hasPermission(Permission::PERM_UPDATE_MANAGE);
        if (!$canManageUpdates) {
            $errors['general'] = 'You do not have permission to check for application updates.';
        } else {
            $updateChecker = new \SimpleKuma\Update\UpdateChecker($db);
            $updateInfo = $updateChecker->checkForUpdates(true, true);
            if (!empty($updateInfo['success'])) {
                header('Location: ?page=settings&tab=updates&success=update_check_complete');
                exit;
            }
            $errors['general'] = (string) ($updateInfo['message'] ?? 'The update check failed.');
        }
    } elseif ($action === 'start_update') {
        $canManageUpdates = $permission && $permission->hasPermission(Permission::PERM_UPDATE_MANAGE);
        if (!$canManageUpdates) {
            $errors['general'] = 'You do not have permission to install application updates.';
        } else {
            @set_time_limit(600);
            @ini_set('max_execution_time', '600');
            @ignore_user_abort(true);

            $updateChecker = new \SimpleKuma\Update\UpdateChecker($db);
            $updateInfo = $updateChecker->checkForUpdates(true, true);
            if (empty($updateInfo['success']) || empty($updateInfo['update_available'])) {
                $errors['general'] = (string) ($updateInfo['message'] ?? 'No newer tagged update is available.');
            } else {
                // Bring the current package schema up to date first. This
                // guarantees update_logs exists even on older installations.
                $preUpdateRunner = new MigrationRunner($db);
                if (!$preUpdateRunner->run()) {
                    $installResult = [
                        'success' => false,
                        'errors' => array_merge(
                            ['Current database migrations must succeed before application files can be updated.'],
                            $preUpdateRunner->getErrors()
                        ),
                    ];
                } else {
                    $lockResult = $db->query("SELECT GET_LOCK('simplekuma_application_update', 0) AS acquired");
                    $lockRow = $lockResult ? $lockResult->fetch_assoc() : null;
                    if ((int) ($lockRow['acquired'] ?? 0) !== 1) {
                        $installResult = [
                            'success' => false,
                            'errors' => ['Another application update is already running. Wait for it to finish and check again.'],
                        ];
                    } else {
                        try {
                            $installer = new \SimpleKuma\Update\UpdateInstaller($db);
                            $installResult = $installer->install(
                                $updateInfo,
                                (string) $updateInfo['current_version'],
                                isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null
                            );
                        } catch (Throwable $e) {
                            $installResult = [
                                'success' => false,
                                'errors' => [$e->getMessage()],
                            ];
                        } finally {
                            $db->query("SELECT RELEASE_LOCK('simplekuma_application_update')");
                        }
                    }
                }

                if (!empty($installResult['success'])) {
                    $fileCount = count($installResult['files_updated'] ?? []);
                    $migrationCount = count($installResult['migrations_applied'] ?? []);
                    $_SESSION['flash_application_update'] = [
                        'message' => 'Simple Kuma updated to '
                            . $updateInfo['latest_version']
                            . " ({$fileCount} files, {$migrationCount} database migration"
                            . ($migrationCount === 1 ? '' : 's') . ').',
                    ];
                    header('Location: ?page=settings&tab=updates&success=application_updated');
                    exit;
                }

                $_SESSION['flash_application_update'] = [
                    'errors' => $installResult['errors'] ?? ['The update failed.'],
                ];
                header('Location: ?page=settings&tab=updates');
                exit;
            }
        }
    } elseif ($action === 'run_db_migrations') {
        $canRunDbUpdate = $permission && (
            $permission->hasPermission(Permission::PERM_UPDATE_MANAGE)
            || $permission->hasPermission(Permission::PERM_SETTINGS_EDIT)
        );

        if (!$canRunDbUpdate) {
            $errors['general'] = 'You do not have permission to update the database schema.';
        } elseif (!Csrf::validate()) {
            $errors['general'] = Csrf::invalidRequestMessage();
        } else {
            @set_time_limit(300);
            @ini_set('max_execution_time', '300');

            $runner = new MigrationRunner($db);
            $pendingBefore = $runner->getPendingMigrations();

            if ($pendingBefore === []) {
                $_SESSION['flash_db_migrations'] = [
                    'message' => 'Database schema is already up to date. No migrations were needed.',
                ];
                header('Location: ?page=settings&tab=updates&success=db_up_to_date');
                exit;
            }

            $ok = $runner->run();
            $applied = $runner->getAppliedMigrations();
            $runErrors = $runner->getErrors();

            if ($ok) {
                $count = count($applied);
                $list = $count > 0
                    ? ' Applied: ' . implode(', ', array_slice($applied, 0, 12))
                        . ($count > 12 ? ' (+' . ($count - 12) . ' more)' : '') . '.'
                    : '';
                $_SESSION['flash_db_migrations'] = [
                    'message' => "Database updated successfully ({$count} migration"
                        . ($count === 1 ? '' : 's') . ").{$list}",
                ];
                header('Location: ?page=settings&tab=updates&success=db_migrations_applied');
                exit;
            }

            $_SESSION['flash_db_migrations'] = [
                'message' => count($applied) > 0
                    ? 'Database update stopped after applying: ' . implode(', ', $applied) . '.'
                    : 'Database update failed.',
                'errors' => $runErrors !== [] ? $runErrors : ['Unknown migration error.'],
            ];
            header('Location: ?page=settings&tab=updates');
            exit;
        }
    } elseif ($action === 'update_profile') {
        $timezone = $_POST['timezone'] ?? 'UTC';
        $currency = $_POST['currency'] ?? 'USD';

        // Validate and normalize timezone identifier
        // Convert common abbreviations to proper timezone identifiers
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
        
        if (isset($timezoneMap[$timezone])) {
            $timezone = $timezoneMap[$timezone];
        }
        
        // Validate timezone identifier
        try {
            $tz = new DateTimeZone($timezone);
            $timezone = $tz->getName(); // Get canonical name
        } catch (Exception $e) {
            // Invalid timezone, default to UTC
            $timezone = 'UTC';
            $errors['timezone'] = 'Invalid timezone, defaulted to UTC';
        }

        $stmt = $db->prepare("UPDATE users SET timezone = ?, currency = ? WHERE id = ?");
        $stmt->bind_param('ssi', $timezone, $currency, $_SESSION['user_id']);
        
        if ($stmt->execute()) {
            $success = 'Profile updated successfully';
            // Reload user data
            $currentUser = $auth->getCurrentUser();
        } else {
            $errors['general'] = 'Failed to update profile';
        }
    } elseif ($action === 'create_group') {
        $name = trim($_POST['group_name'] ?? '');
        $description = trim($_POST['group_description'] ?? '');

        if (empty($name)) {
            $errors['group_name'] = 'Group name is required';
        } else {
            $groupId = $campaignGroup->create([
                'name' => $name,
                'description' => $description
            ]);
            if ($groupId) {
                $success = 'Campaign group created successfully';
            } else {
                $errors['general'] = 'Failed to create group. Name may already exist.';
            }
        }
    } elseif ($action === 'update_group') {
        $id = (int)($_POST['group_id'] ?? 0);
        $name = trim($_POST['group_name'] ?? '');
        $description = trim($_POST['group_description'] ?? '');

        if (empty($name)) {
            $errors['group_name'] = 'Group name is required';
        } elseif ($id <= 0) {
            $errors['general'] = 'Invalid group ID';
        } else {
            if ($campaignGroup->update($id, [
                'name' => $name,
                'description' => $description
            ])) {
                $success = 'Campaign group updated successfully';
            } else {
                $errors['general'] = 'Failed to update group. Name may already exist.';
            }
        }
    } elseif ($action === 'delete_group') {
        $id = (int)($_POST['group_id'] ?? 0);
        
        if ($id <= 0) {
            $errors['general'] = 'Invalid group ID';
        } else {
            // Check if any campaigns use this group
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM campaigns WHERE campaign_group_id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            
            if ($result['count'] > 0) {
                $errors['general'] = 'Cannot delete group: ' . $result['count'] . ' campaign(s) are using it';
            } elseif ($campaignGroup->delete($id)) {
                $success = 'Campaign group deleted successfully';
            } else {
                $errors['general'] = 'Failed to delete group';
            }
        }
    } elseif ($action === 'add_manual_conversions') {
        $activeTab = 'data';
        if (!$canEditSettings) {
            $errors['general'] = 'You do not have permission to add conversions.';
        } else {
        // Handle manual conversion entry (single + bulk share this loop)
        require_once __DIR__ . '/../src/Tracking/PostbackDispatcher.php';
        require_once __DIR__ . '/../src/Tracking/DailySummaryUpdater.php';
        require_once __DIR__ . '/../src/Database/ClicksTableResolver.php';

        $conversions = [];
        $errorsList = [];
        $successList = [];
        $postbackWarnings = [];

        // Check if bulk entry mode (comma-separated)
        $bulkEntry = trim($_POST['bulk_conversions'] ?? '');
        if (!empty($bulkEntry)) {
            // Parse bulk entry: format is "click_id,revenue" per line
            $lines = preg_split('/[\r\n]+/', $bulkEntry);
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }

                $parts = explode(',', $line, 2);
                if (count($parts) === 2) {
                    $conversions[] = [
                        'click_id' => trim($parts[0]),
                        'value' => trim($parts[1]),
                        'currency' => 'USD',
                    ];
                } else {
                    $errorsList[] = "Invalid format in bulk entry: {$line} (expected: click_id,revenue)";
                }
            }
        } else {
            // Single entry mode (with plus button support)
            $clickIds = $_POST['click_id'] ?? [];
            $values = $_POST['revenue'] ?? [];
            $currencies = $_POST['currency'] ?? [];

            if (is_array($clickIds) && is_array($values)) {
                foreach ($clickIds as $idx => $clickId) {
                    $clickId = trim((string)$clickId);
                    $value = trim((string)($values[$idx] ?? '0'));
                    $currency = trim((string)($currencies[$idx] ?? 'USD'));

                    if ($clickId !== '' && $value !== '') {
                        $conversions[] = [
                            'click_id' => $clickId,
                            'value' => $value,
                            'currency' => $currency !== '' ? $currency : 'USD',
                        ];
                    }
                }
            }
        }

        if (empty($conversions)) {
            $errors['general'] = 'No valid conversions to add. Please provide at least one click ID and revenue.';
        } else {
            $summaryUpdater = new \SimpleKuma\Tracking\DailySummaryUpdater($db);
            $dispatcher = new \SimpleKuma\Tracking\PostbackDispatcher($db);
            $addedCount = 0;
            $postbacksFiredCount = 0;
            $postbacksSkippedCount = 0;

            foreach ($conversions as $conv) {
                $clickId = $conv['click_id'];
                $value = (float)($conv['value'] ?? 0);
                $currency = $conv['currency'] ?? 'USD';

                // Verify click exists (active first, then archive)
                $click = null;
                foreach (\SimpleKuma\Database\ClicksTableResolver::getClickLookupTables($db) as $clickTable) {
                    $clickStmt = $db->prepare("SELECT click_id, campaign_id FROM `{$clickTable}` WHERE click_id = ? LIMIT 1");
                    if (!$clickStmt) {
                        continue;
                    }
                    $clickStmt->bind_param('s', $clickId);
                    $clickStmt->execute();
                    $click = $clickStmt->get_result()->fetch_assoc();
                    $clickStmt->close();
                    if ($click) {
                        break;
                    }
                }

                if (!$click) {
                    $errorsList[] = "Click ID '{$clickId}' not found";
                    continue;
                }

                // Check if conversion already exists (to avoid duplicates)
                $checkStmt = $db->prepare("SELECT id FROM conversions WHERE click_id = ? LIMIT 1");
                $checkStmt->bind_param('s', $clickId);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                if ($checkResult->num_rows > 0) {
                    $checkStmt->close();
                    $errorsList[] = "Conversion for click ID '{$clickId}' already exists";
                    continue;
                }
                $checkStmt->close();

                // Insert directly to bypass attribution window, then fire postbacks + update stats
                $stmt = $db->prepare(
                    "INSERT INTO conversions 
                    (click_id, txid, event_id, value, currency, status, ts, payout, source_json) 
                    VALUES (?, NULL, NULL, ?, ?, 'approved', NOW(), NULL, ?)"
                );

                $sourceJson = json_encode([
                    'source' => 'manual',
                    'value' => $value,
                    'currency' => $currency,
                    'status' => 'approved',
                    'conversion_epoch_ms' => (int) round(microtime(true) * 1000),
                ]);

                $stmt->bind_param('sdss', $clickId, $value, $currency, $sourceJson);

                if ($stmt->execute()) {
                    $conversionId = (int)$stmt->insert_id;
                    $stmt->close();
                    $addedCount++;

                    // Keep campaign stats / token aggregates in sync (same as ConversionTracker)
                    try {
                        $summaryUpdater->upsertConversion($clickId, null, $value);
                    } catch (\Throwable $e) {
                        error_log("DailySummaryUpdater failed for manual conversion {$conversionId}: " . $e->getMessage());
                        $postbackWarnings[] = "Stats summary update failed for '{$clickId}'";
                    }

                    // Manual add is intentional — bypass min-payout filter (same as re-fire force=1)
                    try {
                        $dispatcher->firePostbacks($conversionId, true);

                        $logStmt = $db->prepare(
                            "SELECT postback_type, success, http_code, error_message
                             FROM postback_logs
                             WHERE conversion_id = ?
                             ORDER BY id DESC
                             LIMIT 20"
                        );
                        $convIdStr = (string)$conversionId;
                        $logStmt->bind_param('s', $convIdStr);
                        $logStmt->execute();
                        $logs = $logStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        $logStmt->close();

                        $sentOk = false;
                        $skipped = false;
                        $sendErrors = [];
                        foreach ($logs as $log) {
                            if (($log['postback_type'] ?? '') === 'skipped_threshold') {
                                $skipped = true;
                                continue;
                            }
                            if (!empty($log['success'])) {
                                $sentOk = true;
                            } elseif (!empty($log['error_message'])) {
                                $sendErrors[] = $log['error_message'];
                            } elseif (!empty($log['http_code']) && (int)$log['http_code'] >= 400) {
                                $sendErrors[] = 'HTTP ' . $log['http_code'];
                            }
                        }

                        if ($sentOk) {
                            $postbacksFiredCount++;
                            $successList[] = "Added conversion for click ID '{$clickId}' with revenue {$currency} {$value} (postbacks sent)";
                        } elseif ($skipped) {
                            $postbacksSkippedCount++;
                            $postbackWarnings[] = "Conversion '{$clickId}' saved but outbound postbacks were skipped (below minimum payout)";
                            $successList[] = "Added conversion for click ID '{$clickId}' with revenue {$currency} {$value} (postbacks skipped — below min payout)";
                        } elseif (empty($logs)) {
                            $postbacksSkippedCount++;
                            $postbackWarnings[] = "Conversion '{$clickId}' saved but no outbound postbacks were configured/logged (check Facebook CAPI / custom postbacks on the campaign)";
                            $successList[] = "Added conversion for click ID '{$clickId}' with revenue {$currency} {$value} (no postbacks configured)";
                        } else {
                            $postbacksSkippedCount++;
                            $errMsg = !empty($sendErrors) ? implode('; ', array_slice($sendErrors, 0, 2)) : 'see postback logs';
                            $postbackWarnings[] = "Conversion '{$clickId}' saved but postback send failed: {$errMsg}";
                            $successList[] = "Added conversion for click ID '{$clickId}' with revenue {$currency} {$value} (postback error: {$errMsg})";
                        }
                    } catch (\Exception $e) {
                        error_log("Postback failed for manual conversion {$conversionId}: " . $e->getMessage());
                        $postbacksSkippedCount++;
                        $postbackWarnings[] = "Conversion '{$clickId}' saved but postback error: " . $e->getMessage();
                        $successList[] = "Added conversion for click ID '{$clickId}' with revenue {$currency} {$value} (postback error: " . $e->getMessage() . ")";
                    }
                } else {
                    $errorsList[] = "Failed to add conversion for click ID '{$clickId}': Database error";
                    $stmt->close();
                }
            }

            if ($addedCount > 0) {
                $success = "Successfully added {$addedCount} conversion(s).";
                if ($postbacksFiredCount > 0) {
                    $success .= " {$postbacksFiredCount} had outbound postbacks sent.";
                }
                if ($postbacksSkippedCount > 0) {
                    $success .= " {$postbacksSkippedCount} had postback warnings.";
                }
                if (!empty($postbackWarnings)) {
                    $success .= ' ' . implode('; ', array_slice($postbackWarnings, 0, 5));
                }
                if (!empty($errorsList)) {
                    $success .= ' ' . count($errorsList) . ' error(s): ' . implode('; ', array_slice($errorsList, 0, 5));
                }
            } else {
                $errors['general'] = 'No conversions were added. ' . implode('; ', array_slice($errorsList, 0, 5));
            }
        }
        } // end canEditSettings for add_manual_conversions
    } elseif ($action === 'delete_clicks_by_campaign') {
        $activeTab = 'data';
        if (!$canEditSettings) {
            $errors['general'] = 'You do not have permission to delete click data.';
        } elseif (($campaignId = (int)($_POST['campaign_id'] ?? 0)) <= 0) {
            $errors['general'] = 'Invalid campaign ID';
        } else {
            try {
                require_once __DIR__ . '/../src/DataRetention/DataManagementCleanup.php';
                $cleanup = new \SimpleKuma\DataRetention\DataManagementCleanup($db);
                $result = $cleanup->deleteClicksByCampaign($campaignId);
                $success = "Deleted {$result['clicks_deleted']} click(s) and {$result['conversions_deleted']} conversion(s) for the selected campaign (including archive). Statistics for this campaign were reset.";
            } catch (\Throwable $e) {
                error_log('delete_clicks_by_campaign failed: ' . $e->getMessage());
                $errors['general'] = 'Failed to delete clicks: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_all_clicks') {
        $activeTab = 'data';
        if (!$canEditSettings) {
            $errors['general'] = 'You do not have permission to delete click data.';
        } else {
            try {
                require_once __DIR__ . '/../src/DataRetention/DataManagementCleanup.php';
                $cleanup = new \SimpleKuma\DataRetention\DataManagementCleanup($db);
                $result = $cleanup->deleteAllClicks();
                $success = "Deleted {$result['clicks_deleted']} click(s) and {$result['conversions_deleted']} conversion(s) from the database (including archive). All click statistics were reset.";
            } catch (\Throwable $e) {
                error_log('delete_all_clicks failed: ' . $e->getMessage());
                $errors['general'] = 'Failed to delete all clicks: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_clicks_by_ip') {
        $activeTab = 'data';
        $ip = trim($_POST['ip_address'] ?? '');

        if (!$canEditSettings) {
            $errors['general'] = 'You do not have permission to delete click data.';
        } elseif ($ip === '') {
            $errors['general'] = 'IP address is required';
        } else {
            try {
                require_once __DIR__ . '/../src/DataRetention/DataManagementCleanup.php';
                $cleanup = new \SimpleKuma\DataRetention\DataManagementCleanup($db);
                $result = $cleanup->deleteClicksByIp($ip);
                $success = "Deleted {$result['clicks_deleted']} click(s) and {$result['conversions_deleted']} conversion(s) from IP: {$ip}";
            } catch (\Throwable $e) {
                error_log('delete_clicks_by_ip failed: ' . $e->getMessage());
                $errors['general'] = 'Failed to delete clicks: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'hide_ip_from_stats') {
        $activeTab = 'data';
        $ip = trim($_POST['ip_address'] ?? '');
        $note = trim($_POST['note'] ?? '');
        if (!$canEditSettings) {
            $errors['general'] = 'You do not have permission to manage stats exclusions.';
        } elseif ($ip === '') {
            $errors['general'] = 'IP address is required';
        } else {
            try {
                $hiddenSvc = new \SimpleKuma\Stats\StatsHiddenIpService($db);
                $result = $hiddenSvc->add($ip, $note !== '' ? $note : null, (int)($_SESSION['user_id'] ?? 0) ?: null);
                if (!$result['ok']) {
                    $errors['general'] = $result['error'] ?? 'Failed to hide IP';
                } elseif (!empty($result['already'])) {
                    $success = "IP {$ip} is already on the hide-from-stats list.";
                } else {
                    $success = "Hid IP {$ip} from stats views (adjusted {$result['clicks_adjusted']} click(s), {$result['conversions_adjusted']} conversion(s) in aggregates). Tracking links are not blocked.";
                }
            } catch (\Throwable $e) {
                error_log('hide_ip_from_stats failed: ' . $e->getMessage());
                $errors['general'] = 'Failed to hide IP: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'unhide_ip_from_stats') {
        $activeTab = 'data';
        $ip = trim($_POST['ip_address'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        if (!$canEditSettings) {
            $errors['general'] = 'You do not have permission to manage stats exclusions.';
        } else {
        try {
            $hiddenSvc = new \SimpleKuma\Stats\StatsHiddenIpService($db);
            $result = $id > 0 ? $hiddenSvc->removeById($id) : $hiddenSvc->remove($ip);
            if (!$result['ok']) {
                $errors['general'] = $result['error'] ?? 'Failed to unhide IP';
            } else {
                $success = "Removed IP from hide-from-stats list (restored {$result['clicks_restored']} click(s), {$result['conversions_restored']} conversion(s) to aggregates).";
            }
        } catch (\Throwable $e) {
            error_log('unhide_ip_from_stats failed: ' . $e->getMessage());
            $errors['general'] = 'Failed to unhide IP: ' . $e->getMessage();
        }
        }
    } elseif ($action === 'delete_clicks_by_subid') {
        $activeTab = 'data';
        $subidParam = trim($_POST['subid_param'] ?? '');
        $subidValue = trim($_POST['subid_value'] ?? '');

        if (!$canEditSettings) {
            $errors['general'] = 'You do not have permission to delete click data.';
        } elseif ($subidParam === '' || $subidValue === '') {
            $errors['general'] = 'Both parameter name and value are required';
        } else {
            try {
                require_once __DIR__ . '/../src/DataRetention/DataManagementCleanup.php';
                $cleanup = new \SimpleKuma\DataRetention\DataManagementCleanup($db);
                $result = $cleanup->deleteClicksBySubid($subidParam, $subidValue);
                $success = "Deleted {$result['clicks_deleted']} click(s) and {$result['conversions_deleted']} conversion(s) where {$subidParam} = {$subidValue}";
            } catch (\Throwable $e) {
                error_log('delete_clicks_by_subid failed: ' . $e->getMessage());
                $errors['general'] = 'Failed to delete clicks: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_conversions_by_campaign') {
        $activeTab = 'data';
        $campaignId = (int)($_POST['campaign_id'] ?? 0);

        if (!$canEditSettings) {
            $errors['general'] = 'You do not have permission to delete conversion data.';
        } elseif ($campaignId <= 0) {
            $errors['general'] = 'Invalid campaign ID';
        } else {
            try {
                require_once __DIR__ . '/../src/DataRetention/DataManagementCleanup.php';
                $cleanup = new \SimpleKuma\DataRetention\DataManagementCleanup($db);
                $result = $cleanup->deleteConversionsByCampaign($campaignId);
                $success = "Deleted {$result['conversions_deleted']} conversion(s) for the selected campaign";
            } catch (\Throwable $e) {
                error_log('delete_conversions_by_campaign failed: ' . $e->getMessage());
                $errors['general'] = 'Failed to delete conversions: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_conversions_by_clickid') {
        $activeTab = 'data';
        $clickIdsInput = trim($_POST['click_ids'] ?? '');

        if (!$canEditSettings) {
            $errors['general'] = 'You do not have permission to delete conversion data.';
        } elseif ($clickIdsInput === '') {
            $errors['general'] = 'Click ID(s) are required';
        } else {
            $clickIds = array_map('trim', explode(',', $clickIdsInput));
            $clickIds = array_values(array_filter($clickIds, static function ($id) {
                return $id !== '';
            }));

            if ($clickIds === []) {
                $errors['general'] = 'No valid click IDs provided';
            } else {
                try {
                    require_once __DIR__ . '/../src/DataRetention/DataManagementCleanup.php';
                    $cleanup = new \SimpleKuma\DataRetention\DataManagementCleanup($db);
                    $result = $cleanup->deleteConversionsByClickIds($clickIds);
                    if ($result['conversions_deleted'] > 0) {
                        $message = "Deleted {$result['conversions_deleted']} conversion(s)";
                        if ($result['not_found'] > 0) {
                            $message .= " ({$result['not_found']} click ID(s) had no conversions)";
                        }
                        $success = $message;
                    } else {
                        $errors['general'] = 'No conversions found for the provided click ID(s)';
                    }
                } catch (\Throwable $e) {
                    error_log('delete_conversions_by_clickid failed: ' . $e->getMessage());
                    $errors['general'] = 'Failed to delete conversions: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'create_fb_integration') {
        $name = trim($_POST['fb_integration_name'] ?? '');
        $pixelId = trim($_POST['fb_pixel_id'] ?? '');
        $accessToken = trim($_POST['fb_access_token'] ?? '');
        $testCode = trim($_POST['fb_test_code'] ?? '');
        $eventType = trim($_POST['fb_event_type'] ?? '');
        $customEventType = trim($_POST['fb_custom_event_type'] ?? '');
        
        // Use custom event type if selected, otherwise use dropdown value
        if ($eventType === 'custom' && !empty($customEventType)) {
            $eventType = $customEventType;
        } elseif (empty($eventType)) {
            $eventType = 'Purchase'; // Default
        }

        $eventMapping = [];
        $mapKeys = $_POST['fb_map_key'] ?? [];
        $mapEvents = $_POST['fb_map_event'] ?? [];
        $mapCustoms = $_POST['fb_map_custom_event'] ?? [];
        if (is_array($mapKeys) && is_array($mapEvents)) {
            foreach ($mapKeys as $i => $key) {
                $key = trim((string)$key);
                $evt = trim((string)($mapEvents[$i] ?? ''));
                if ($evt === 'custom') {
                    $evt = trim((string)($mapCustoms[$i] ?? ''));
                }
                if ($key === '' || $evt === '') {
                    continue;
                }
                $eventMapping[$key] = $evt;
            }
        }
        $sendPageview = !empty($_POST['fb_send_pageview_on_click']);
        
        $validationErrors = $facebookCapi->validate([
            'name' => $name,
            'pixel_id' => $pixelId,
            'access_token' => $accessToken,
            'test_code' => $testCode,
            'event_type' => $eventType,
            'event_mapping' => $eventMapping,
        ], false);
        
        if (!empty($validationErrors)) {
            $errors['fb_integration'] = $validationErrors;
        } else {
            $integrationId = $facebookCapi->create([
                'name' => $name,
                'pixel_id' => $pixelId,
                'access_token' => $accessToken,
                'test_code' => empty($testCode) ? null : $testCode,
                'event_type' => $eventType,
                'event_mapping' => $eventMapping,
                'send_pageview_on_click' => $sendPageview,
            ]);
            
            if ($integrationId) {
                // Redirect to close edit form and show success
                header('Location: ?page=settings&tab=integrations&success=fb_integration_created');
                exit;
            } else {
                $errors['general'] = 'Failed to create integration';
            }
        }
    } elseif ($action === 'update_fb_integration') {
        $id = (int)($_POST['fb_integration_id'] ?? 0);
        $name = trim($_POST['fb_integration_name'] ?? '');
        $pixelId = trim($_POST['fb_pixel_id'] ?? '');
        $accessToken = trim($_POST['fb_access_token'] ?? '');
        $testCode = trim($_POST['fb_test_code'] ?? '');
        $eventType = trim($_POST['fb_event_type'] ?? '');
        $customEventType = trim($_POST['fb_custom_event_type'] ?? '');
        
        // Use custom event type if selected, otherwise use dropdown value
        if ($eventType === 'custom' && !empty($customEventType)) {
            $eventType = $customEventType;
        } elseif (empty($eventType)) {
            $eventType = 'Purchase'; // Default
        }

        $eventMapping = [];
        $mapKeys = $_POST['fb_map_key'] ?? [];
        $mapEvents = $_POST['fb_map_event'] ?? [];
        $mapCustoms = $_POST['fb_map_custom_event'] ?? [];
        if (is_array($mapKeys) && is_array($mapEvents)) {
            foreach ($mapKeys as $i => $key) {
                $key = trim((string)$key);
                $evt = trim((string)($mapEvents[$i] ?? ''));
                if ($evt === 'custom') {
                    $evt = trim((string)($mapCustoms[$i] ?? ''));
                }
                if ($key === '' || $evt === '') {
                    continue;
                }
                $eventMapping[$key] = $evt;
            }
        }
        $sendPageview = !empty($_POST['fb_send_pageview_on_click']);
        
        if ($id <= 0) {
            $errors['general'] = 'Invalid integration ID';
        } else {
            $validationErrors = $facebookCapi->validate([
                'id' => $id,
                'name' => $name,
                'pixel_id' => $pixelId,
                'access_token' => $accessToken,
                'test_code' => $testCode,
                'event_type' => $eventType,
                'event_mapping' => $eventMapping,
            ], true);
            
            if (!empty($validationErrors)) {
                $errors['fb_integration'] = $validationErrors;
            } else {
                $updateData = [
                    'name' => $name,
                    'pixel_id' => $pixelId,
                    'access_token' => $accessToken,
                    'test_code' => empty($testCode) ? null : $testCode,
                    'event_type' => $eventType,
                    'event_mapping' => $eventMapping,
                    'send_pageview_on_click' => $sendPageview,
                ];
                
                if ($facebookCapi->update($id, $updateData)) {
                    // Redirect to close edit form and show success
                    header('Location: ?page=settings&tab=integrations&success=fb_integration_updated');
                    exit;
                } else {
                    $errors['general'] = 'Failed to update integration';
                }
            }
        }
    } elseif ($action === 'delete_fb_integration') {
        $id = (int)($_POST['fb_integration_id'] ?? 0);
        
        if ($id <= 0) {
            $errors['general'] = 'Invalid integration ID';
        } else {
            if ($facebookCapi->delete($id)) {
                $success = 'Facebook CAPI integration deleted successfully';
            } else {
                $errors['general'] = 'Failed to delete integration';
            }
        }
    } elseif ($action === 'create_ga_integration') {
        $name = trim($_POST['ga_integration_name'] ?? '');
        $conversionKey = trim($_POST['ga_conversion_key'] ?? '');
        $deliveryMode = trim($_POST['ga_delivery_mode'] ?? 'csv');
        $conversionActionId = trim($_POST['ga_conversion_action_id'] ?? '');
        $customerId = trim($_POST['ga_customer_id'] ?? '');
        $developerToken = trim($_POST['ga_developer_token'] ?? '');
        $oauthClientId = trim($_POST['ga_oauth_client_id'] ?? '');
        $oauthClientSecret = trim($_POST['ga_oauth_client_secret'] ?? '');
        $oauthRefreshToken = trim($_POST['ga_oauth_refresh_token'] ?? '');
        $loginCustomerId = trim($_POST['ga_login_customer_id'] ?? '');

        $payload = [
            'name' => $name,
            'conversion_key' => $conversionKey,
            'delivery_mode' => $deliveryMode,
            'conversion_action_id' => $conversionActionId,
            'customer_id' => $customerId,
            'developer_token' => $developerToken,
            'oauth_client_id' => $oauthClientId,
            'oauth_client_secret' => $oauthClientSecret,
            'oauth_refresh_token' => $oauthRefreshToken,
            'login_customer_id' => $loginCustomerId,
        ];

        $validationErrors = $googleAds->validate($payload, false);

        if (!empty($validationErrors)) {
            $errors['ga_integration'] = $validationErrors;
        } else {
            $integrationId = $googleAds->create($payload);

            if ($integrationId) {
                $created = $googleAds->getById($integrationId);
                if ($created && $googleAds->isCostTrackingConfigured($created)) {
                    $googleAds->saveConfigFile($created);
                }
                header('Location: ?page=settings&tab=integrations&success=ga_integration_created');
                exit;
            } else {
                $errors['general'] = 'Failed to create integration';
            }
        }
    } elseif ($action === 'update_ga_integration') {
        $id = (int)($_POST['ga_integration_id'] ?? 0);
        $name = trim($_POST['ga_integration_name'] ?? '');
        $conversionKey = trim($_POST['ga_conversion_key'] ?? '');
        $deliveryMode = trim($_POST['ga_delivery_mode'] ?? 'csv');
        $conversionActionId = trim($_POST['ga_conversion_action_id'] ?? '');
        $customerId = trim($_POST['ga_customer_id'] ?? '');
        $developerToken = trim($_POST['ga_developer_token'] ?? '');
        $oauthClientId = trim($_POST['ga_oauth_client_id'] ?? '');
        $oauthClientSecret = trim($_POST['ga_oauth_client_secret'] ?? '');
        $oauthRefreshToken = trim($_POST['ga_oauth_refresh_token'] ?? '');
        $loginCustomerId = trim($_POST['ga_login_customer_id'] ?? '');

        if ($id <= 0) {
            $errors['general'] = 'Invalid integration ID';
        } else {
            $payload = [
                'id' => $id,
                'name' => $name,
                'conversion_key' => $conversionKey,
                'delivery_mode' => $deliveryMode,
                'conversion_action_id' => $conversionActionId,
                'customer_id' => $customerId,
                'oauth_client_id' => $oauthClientId,
                'login_customer_id' => $loginCustomerId,
            ];
            // Blank secrets mean keep existing (never echo secrets into the form).
            if ($developerToken !== '') {
                $payload['developer_token'] = $developerToken;
            }
            if ($oauthClientSecret !== '') {
                $payload['oauth_client_secret'] = $oauthClientSecret;
            }
            if ($oauthRefreshToken !== '') {
                $payload['oauth_refresh_token'] = $oauthRefreshToken;
            }

            $validationErrors = $googleAds->validate($payload, true);

            if (!empty($validationErrors)) {
                $errors['ga_integration'] = $validationErrors;
            } else {
                if ($googleAds->update($id, $payload)) {
                    $updated = $googleAds->getById($id);
                    if ($updated && $googleAds->isCostTrackingConfigured($updated)) {
                        $googleAds->saveConfigFile($updated);
                    }
                    header('Location: ?page=settings&tab=integrations&success=ga_integration_updated');
                    exit;
                } else {
                    $errors['general'] = 'Failed to update integration';
                }
            }
        }
    } elseif ($action === 'delete_ga_integration') {
        $id = (int)($_POST['ga_integration_id'] ?? 0);
        
        if ($id <= 0) {
            $errors['general'] = 'Invalid integration ID';
        } else {
            if ($googleAds->delete($id)) {
                $success = 'Google Ads integration deleted successfully';
            } else {
                $errors['general'] = 'Failed to delete integration';
            }
        }
    } elseif ($action === 'create_ga_cost_integration') {
        $name = trim($_POST['ga_cost_integration_name'] ?? '');
        $customerId = trim($_POST['ga_cost_customer_id'] ?? '');
        $developerToken = trim($_POST['ga_cost_developer_token'] ?? '');
        $oauthClientId = trim($_POST['ga_cost_oauth_client_id'] ?? '');
        $oauthClientSecret = trim($_POST['ga_cost_oauth_client_secret'] ?? '');
        $oauthRefreshToken = trim($_POST['ga_cost_oauth_refresh_token'] ?? '');
        $loginCustomerId = trim($_POST['ga_cost_login_customer_id'] ?? '');
        $status = trim($_POST['ga_cost_status'] ?? 'active');
        $conversionKey = trim($_POST['ga_cost_conversion_key'] ?? '');
        if ($conversionKey === '') {
            $conversionKey = $googleAds->generateConversionKey();
        }

        $payload = [
            'name' => $name,
            'conversion_key' => $conversionKey,
            'delivery_mode' => GoogleAdsIntegration::MODE_CSV,
            'customer_id' => $customerId,
            'developer_token' => $developerToken,
            'oauth_client_id' => $oauthClientId,
            'oauth_client_secret' => $oauthClientSecret,
            'oauth_refresh_token' => $oauthRefreshToken,
            'login_customer_id' => $loginCustomerId,
            'status' => $status,
        ];

        $validationErrors = $googleAds->validateCostSettings($payload, false);
        if (!empty($validationErrors)) {
            $errors['ga_cost_integration'] = $validationErrors;
        } else {
            $integrationId = $googleAds->create($payload);
            if ($integrationId) {
                $created = $googleAds->getById($integrationId);
                if ($created && $googleAds->isCostTrackingConfigured($created)) {
                    $googleAds->saveConfigFile($created);
                }
                header('Location: ?page=settings&tab=api-costs&success=ga_cost_integration_created');
                exit;
            }
            $errors['general'] = 'Failed to create Google Ads cost integration';
        }
    } elseif ($action === 'update_ga_cost_integration') {
        $id = (int)($_POST['ga_cost_integration_id'] ?? 0);
        $name = trim($_POST['ga_cost_integration_name'] ?? '');
        $customerId = trim($_POST['ga_cost_customer_id'] ?? '');
        $developerToken = trim($_POST['ga_cost_developer_token'] ?? '');
        $oauthClientId = trim($_POST['ga_cost_oauth_client_id'] ?? '');
        $oauthClientSecret = trim($_POST['ga_cost_oauth_client_secret'] ?? '');
        $oauthRefreshToken = trim($_POST['ga_cost_oauth_refresh_token'] ?? '');
        $loginCustomerId = trim($_POST['ga_cost_login_customer_id'] ?? '');
        $status = trim($_POST['ga_cost_status'] ?? 'active');

        if ($id <= 0) {
            $errors['general'] = 'Invalid integration ID';
        } else {
            $existing = $googleAds->getById($id);
            if ($existing === null) {
                $errors['general'] = 'Google Ads integration not found';
            } else {
                $payload = [
                    'id' => $id,
                    'name' => $name,
                    'customer_id' => $customerId,
                    'oauth_client_id' => $oauthClientId,
                    'login_customer_id' => $loginCustomerId,
                    'status' => $status,
                ];
                if ($developerToken !== '') {
                    $payload['developer_token'] = $developerToken;
                }
                if ($oauthClientSecret !== '') {
                    $payload['oauth_client_secret'] = $oauthClientSecret;
                }
                if ($oauthRefreshToken !== '') {
                    $payload['oauth_refresh_token'] = $oauthRefreshToken;
                }

                $validationErrors = $googleAds->validateCostSettings($payload, true);
                if (!empty($validationErrors)) {
                    $errors['ga_cost_integration'] = $validationErrors;
                } elseif ($googleAds->update($id, $payload)) {
                    $updated = $googleAds->getById($id);
                    if ($updated && $googleAds->isCostTrackingConfigured($updated)) {
                        $googleAds->saveConfigFile($updated);
                    }
                    header('Location: ?page=settings&tab=api-costs&success=ga_cost_integration_updated');
                    exit;
                } else {
                    $errors['general'] = 'Failed to update Google Ads cost integration';
                }
            }
        }
    } elseif ($action === 'delete_ga_cost_integration') {
        $id = (int)($_POST['ga_cost_integration_id'] ?? 0);
        if ($id <= 0) {
            $errors['general'] = 'Invalid integration ID';
        } elseif ($googleAds->delete($id)) {
            header('Location: ?page=settings&tab=api-costs&success=ga_cost_integration_deleted');
            exit;
        } else {
            $errors['general'] = 'Failed to delete Google Ads cost integration';
        }
    } elseif ($action === 'create_custom_postback') {
        $name = trim($_POST['custom_postback_name'] ?? '');
        $postbackUrl = trim($_POST['custom_postback_url'] ?? '');
        $description = trim($_POST['custom_postback_description'] ?? '');
        
        $validationErrors = $customPostback->validate([
            'name' => $name,
            'postback_url' => $postbackUrl,
            'description' => $description
        ]);
        
        if (!empty($validationErrors)) {
            $errors['custom_postback'] = $validationErrors;
        } else {
            if ($customPostback->create([
                'name' => $name,
                'postback_url' => $postbackUrl,
                'description' => $description
            ])) {
                // Redirect to close edit form and show success
                header('Location: ?page=settings&tab=integrations&success=custom_postback_created');
                exit;
            } else {
                $errors['general'] = 'Failed to create custom postback';
            }
        }
    } elseif ($action === 'update_custom_postback') {
        $id = (int)($_POST['custom_postback_id'] ?? 0);
        $name = trim($_POST['custom_postback_name'] ?? '');
        $postbackUrl = trim($_POST['custom_postback_url'] ?? '');
        $description = trim($_POST['custom_postback_description'] ?? '');
        
        if ($id <= 0) {
            $errors['general'] = 'Invalid postback ID';
        } else {
            $validationErrors = $customPostback->validate([
                'id' => $id,
                'name' => $name,
                'postback_url' => $postbackUrl,
                'description' => $description
            ], true);
            
            if (!empty($validationErrors)) {
                $errors['custom_postback'] = $validationErrors;
            } else {
                if ($customPostback->update($id, [
                    'name' => $name,
                    'postback_url' => $postbackUrl,
                    'description' => $description
                ])) {
                    // Redirect to close edit form and show success
                    header('Location: ?page=settings&tab=integrations&success=custom_postback_updated');
                    exit;
                } else {
                    $errors['general'] = 'Failed to update custom postback';
                }
            }
        }
    } elseif ($action === 'delete_custom_postback') {
        $id = (int)($_POST['custom_postback_id'] ?? 0);
        
        if ($id <= 0) {
            $errors['general'] = 'Invalid postback ID';
        } else {
            if ($customPostback->delete($id)) {
                $success = 'Custom postback deleted successfully';
            } else {
                $errors['general'] = 'Failed to delete custom postback';
            }
        }
    } elseif ($action === 'create_fm_integration') {
        $name = trim($_POST['fm_integration_name'] ?? '');
        $accessToken = trim($_POST['fm_access_token'] ?? '');
        $status = trim($_POST['fm_status'] ?? 'active');
        
        // Proxy configuration
        $useProxy = !empty($_POST['fm_use_proxy']);
        $proxyHost = trim($_POST['fm_proxy_host'] ?? '');
        $proxyPort = !empty($_POST['fm_proxy_port']) ? (int)$_POST['fm_proxy_port'] : null;
        $proxyType = trim($_POST['fm_proxy_type'] ?? 'HTTP');
        $proxyUser = trim($_POST['fm_proxy_user'] ?? '');
        $proxyPass = trim($_POST['fm_proxy_pass'] ?? '');
        
        // Optional: Validate access token
        $tokenValidationErrors = $facebookMarketing->validateAccessToken($accessToken);
        
        $validationErrors = $facebookMarketing->validate([
            'name' => $name,
            'access_token' => $accessToken,
            'ad_account_id' => null, // No longer required - we fetch all accounts
            'status' => $status,
            'use_proxy' => $useProxy,
            'proxy_host' => $proxyHost,
            'proxy_port' => $proxyPort,
            'proxy_type' => $proxyType,
            'proxy_user' => $proxyUser,
            'proxy_pass' => $proxyPass
        ], false);
        
        // Combine validation errors
        if (!empty($tokenValidationErrors)) {
            $validationErrors = array_merge($validationErrors, $tokenValidationErrors);
        }
        
        if (!empty($validationErrors)) {
            $errors['fm_integration'] = $validationErrors;
        } else {
            $integrationId = $facebookMarketing->create([
                'name' => $name,
                'access_token' => $accessToken,
                'ad_account_id' => null, // No longer used - we store all accounts in separate table
                'status' => $status,
                'use_proxy' => $useProxy,
                'proxy_host' => empty($proxyHost) ? null : $proxyHost,
                'proxy_port' => $proxyPort,
                'proxy_type' => empty($proxyType) ? null : $proxyType,
                'proxy_user' => empty($proxyUser) ? null : $proxyUser,
                'proxy_pass' => empty($proxyPass) ? null : $proxyPass
            ]);
            
            if ($integrationId) {
                // Always fetch and sync ad accounts after successful creation
                $proxyConfig = null;
                if ($useProxy) {
                    $proxyConfig = [
                        'use_proxy' => true,
                        'proxy_host' => $proxyHost,
                        'proxy_port' => $proxyPort,
                        'proxy_type' => $proxyType,
                        'proxy_user' => $proxyUser,
                        'proxy_pass' => $proxyPass
                    ];
                }
                $fetchResult = fetchAndSyncFacebookAdAccounts($db, $integrationId, $accessToken, $proxyConfig);
                
                $redirectUrl = '?page=settings&tab=api-costs&success=fm_integration_created';
                if ($fetchResult['success']) {
                    $redirectUrl .= '&fetched_accounts=1&account_count=' . ($fetchResult['count'] ?? 0);
                }
                header('Location: ' . $redirectUrl);
                exit;
            } else {
                $errors['general'] = 'Failed to create integration';
            }
        }
    } elseif ($action === 'update_fm_integration') {
        $id = (int)($_POST['fm_integration_id'] ?? 0);
        $name = trim($_POST['fm_integration_name'] ?? '');
        $accessToken = trim($_POST['fm_access_token'] ?? '');
        $status = trim($_POST['fm_status'] ?? 'active');
        
        // Proxy configuration
        $useProxy = !empty($_POST['fm_use_proxy']);
        $proxyHost = trim($_POST['fm_proxy_host'] ?? '');
        $proxyPort = !empty($_POST['fm_proxy_port']) ? (int)$_POST['fm_proxy_port'] : null;
        $proxyType = trim($_POST['fm_proxy_type'] ?? 'HTTP');
        $proxyUser = trim($_POST['fm_proxy_user'] ?? '');
        $proxyPass = trim($_POST['fm_proxy_pass'] ?? '');
        
        if ($id <= 0) {
            $errors['general'] = 'Invalid integration ID';
        } else {
            // Optional: Validate access token only when a new one is submitted
            $tokenValidationErrors = $accessToken !== ''
                ? $facebookMarketing->validateAccessToken($accessToken)
                : [];
            
            $validationErrors = $facebookMarketing->validate([
                'id' => $id,
                'name' => $name,
                'access_token' => $accessToken,
                'ad_account_id' => null, // No longer required - we fetch all accounts
                'status' => $status,
                'use_proxy' => $useProxy,
                'proxy_host' => $proxyHost,
                'proxy_port' => $proxyPort,
                'proxy_type' => $proxyType,
                'proxy_user' => $proxyUser,
                'proxy_pass' => $proxyPass
            ], true);
            
            // Combine validation errors
            if (!empty($tokenValidationErrors)) {
                $validationErrors = array_merge($validationErrors, $tokenValidationErrors);
            }
            
            if (!empty($validationErrors)) {
                $errors['fm_integration'] = $validationErrors;
            } else {
                $updateData = [
                    'name' => $name,
                    'access_token' => $accessToken,
                    'ad_account_id' => null, // No longer used - we store all accounts in separate table
                    'status' => $status,
                    'use_proxy' => $useProxy,
                    'proxy_host' => empty($proxyHost) ? null : $proxyHost,
                    'proxy_port' => $proxyPort,
                    'proxy_type' => empty($proxyType) ? null : $proxyType,
                    'proxy_user' => empty($proxyUser) ? null : $proxyUser
                ];
                
                // Only include proxy_pass if provided (to update password)
                if (!empty($proxyPass)) {
                    $updateData['proxy_pass'] = $proxyPass;
                }
                
                if ($facebookMarketing->update($id, $updateData)) {
                    // Fetch and sync ad accounts after successful update
                    $proxyConfig = null;
                    if ($useProxy) {
                        $proxyConfig = [
                            'use_proxy' => true,
                            'proxy_host' => $proxyHost,
                            'proxy_port' => $proxyPort,
                            'proxy_type' => $proxyType,
                            'proxy_user' => $proxyUser,
                            'proxy_pass' => $proxyPass
                        ];
                    }
                    fetchAndSyncFacebookAdAccounts($db, $id, $accessToken, $proxyConfig);
                    
                    header('Location: ?page=settings&tab=api-costs&success=fm_integration_updated');
                    exit;
                } else {
                    $errors['general'] = 'Failed to update integration';
                }
            }
        }
    } elseif ($action === 'delete_fm_integration') {
        $id = (int)($_POST['fm_integration_id'] ?? 0);
        
        if ($id <= 0) {
            $errors['general'] = 'Invalid integration ID';
        } else {
            if ($facebookMarketing->delete($id)) {
                $success = 'Facebook Marketing API integration deleted successfully';
            } else {
                $errors['general'] = 'Failed to delete integration';
            }
        }
    } elseif ($action === 'create_user' || $action === 'update_user') {
        if (SingleAdminMode::isEnabled()) {
            $errors['general'] = 'User management is disabled. Use Settings → Account to update your profile and password.';
            $activeTab = 'account';
        } elseif (!$permission || !$permission->hasPermission(Permission::PERM_USER_MANAGE)) {
            $errors['general'] = 'You do not have permission to manage users';
        } else {
            $userData = [
                'username' => trim($_POST['username'] ?? ''),
                'email' => trim($_POST['email'] ?? ''),
                'role_id' => !empty($_POST['role_id']) ? (int)$_POST['role_id'] : null,
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
                'additional_role_ids' => $_POST['additional_role_ids'] ?? [],
            ];

            if (!empty($_POST['password'])) {
                $userData['password'] = $_POST['password'];
            }

            // Add timezone and currency for user profile settings
            // Always process these fields if they're in the POST data (even if other validation fails)
            if (isset($_POST['timezone'])) {
                $timezone = trim($_POST['timezone']);
                
                // Validate and normalize timezone identifier
                // Convert common abbreviations to proper timezone identifiers
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
                
                if (isset($timezoneMap[$timezone])) {
                    $timezone = $timezoneMap[$timezone];
                }
                
                // Validate timezone identifier and get canonical name
                try {
                    $tz = new DateTimeZone($timezone);
                    $timezone = $tz->getName(); // Get canonical name
                } catch (Exception $e) {
                    // Invalid timezone, default to UTC
                    $timezone = 'UTC';
                }
                
                $userData['timezone'] = $timezone;
            }
            if (isset($_POST['currency'])) {
                $userData['currency'] = trim($_POST['currency']);
            }
            
            // Add user ID to userData BEFORE validation (so validation can exclude current user from uniqueness checks)
            if ($action === 'update_user') {
                $id = (int)($_POST['user_id'] ?? 0);
                if ($id > 0) {
                    $userData['id'] = $id;
                }
            }

            $validationErrors = $userEntity->validate($userData, $action === 'update_user');
            
            if (!empty($validationErrors)) {
                $errors['user'] = $validationErrors;
            } else {
                if ($action === 'create_user') {
                    $userId = $userEntity->create($userData);
                    if ($userId) {
                        $auditLogger->log('create', 'user', $userId, "User created: {$userData['username']}");
                        header('Location: ?page=settings&tab=users&success=user_created');
                        exit;
                    } else {
                        $errors['general'] = 'Failed to create user';
                    }
                } else {
                    $id = (int)($_POST['user_id'] ?? 0);
                    
                    // Note: User ID was already added to userData before validation above
                    
                    // Handle password change for current user
                    if ($id == $_SESSION['user_id'] && !empty($_POST['current_password']) && !empty($_POST['new_password'])) {
                        $currentPassword = $_POST['current_password'];
                        $newPassword = $_POST['new_password'];
                        $confirmPassword = $_POST['confirm_password'] ?? '';

                        // Validate password change
                        $currentUserData = $userEntity->getById($id);
                        if (!password_verify($currentPassword, $currentUserData['pass_hash'] ?? '')) {
                            $errors['user']['current_password'] = 'Current password is incorrect';
                        } elseif (strlen($newPassword) < 8) {
                            $errors['user']['new_password'] = 'Password must be at least 8 characters';
                        } elseif ($newPassword !== $confirmPassword) {
                            $errors['user']['confirm_password'] = 'Passwords do not match';
                        } else {
                            $userData['password'] = $newPassword;
                        }
                    }

                    if (empty($errors['user'])) {
                        // Log what we're trying to update for debugging
                        error_log("Updating user $id with data: " . json_encode(array_keys($userData)));
                        if (isset($userData['timezone'])) {
                            error_log("Timezone being set to: " . $userData['timezone']);
                        }
                        if (isset($userData['currency'])) {
                            error_log("Currency being set to: " . $userData['currency']);
                        }
                        
                        $updateResult = $userEntity->update($id, $userData);
                        
                        if ($updateResult) {
                            $auditLogger->log('update', 'user', $id, "User updated: {$userData['username']}");
                            header('Location: ?page=settings&tab=users&success=user_updated&user_action=edit&user_id=' . $id);
                            exit;
                        } else {
                            $errors['general'] = 'Failed to update user. Check error logs for details.';
                            error_log("User update failed for user ID: $id");
                        }
                    } else {
                        // Even if validation fails, log what we tried to update
                        error_log("User update validation failed for user $id. Errors: " . json_encode($errors['user']));
                        error_log("Data attempted: " . json_encode(array_keys($userData)));
                    }
                }
            }
        }
    } elseif ($action === 'delete_user') {
        if (SingleAdminMode::isEnabled()) {
            $errors['general'] = 'User management is disabled in single-admin mode.';
            $activeTab = 'account';
        } elseif (!$permission || !$permission->hasPermission(Permission::PERM_USER_MANAGE)) {
            $errors['general'] = 'You do not have permission to delete users';
        } else {
            $id = (int)($_POST['user_id'] ?? 0);
            if ($userEntity->delete($id)) {
                $auditLogger->log('delete', 'user', $id, "User deleted (soft)");
                header('Location: ?page=settings&tab=users&success=user_deleted');
                exit;
            } else {
                $errors['general'] = 'Failed to delete user';
            }
        }
    } elseif ($action === 'create_domain') {
        $domainData = [
            'domain' => rtrim(trim($_POST['domain'] ?? ''), '/')
        ];

        $validationErrors = $trackingDomainEntity->validate($domainData, false);
        if (!empty($validationErrors)) {
            $errors['domain'] = $validationErrors;
        } else {
            $domainId = $trackingDomainEntity->create($domainData);
            if ($domainId) {
                $auditLogger->log('create', 'tracking_domain', $domainId, "Tracking domain created: {$domainData['domain']}");
                header('Location: ?page=settings&tab=domains&success=domain_created');
                exit;
            } else {
                $errors['general'] = 'Failed to create domain';
            }
        }
    } elseif ($action === 'update_domain') {
        $id = (int)($_POST['domain_id'] ?? 0);
        $domainData = [
            'domain' => rtrim(trim($_POST['domain'] ?? ''), '/')
        ];

        $validationErrors = $trackingDomainEntity->validate($domainData, true);
        if (!empty($validationErrors)) {
            $errors['domain'] = $validationErrors;
        } else {
            if ($trackingDomainEntity->update($id, $domainData)) {
                $auditLogger->log('update', 'tracking_domain', $id, "Tracking domain updated: {$domainData['domain']}");
                header('Location: ?page=settings&tab=domains&success=domain_updated');
                exit;
            } else {
                $errors['general'] = 'Failed to update domain';
            }
        }
    } elseif ($action === 'delete_domain') {
        $id = (int)($_POST['domain_id'] ?? 0);
        if ($trackingDomainEntity->delete($id)) {
            $auditLogger->log('delete', 'tracking_domain', $id, "Tracking domain deleted");
            header('Location: ?page=settings&tab=domains&success=domain_deleted');
            exit;
        } else {
            $errors['general'] = 'Failed to delete domain';
        }
    }
}

// Load campaign groups for display
$allGroups = $campaignGroup->getAll();

// Load Facebook CAPI integrations for display
$allFacebookIntegrations = $facebookCapi->getAll();
$editingFacebookIntegration = null;
if (isset($_GET['edit_fb_integration'])) {
    $editingFacebookIntegration = $facebookCapi->getById((int)$_GET['edit_fb_integration']);
    if (is_array($editingFacebookIntegration)) {
        $editingFacebookIntegration['access_token_set'] = !empty($editingFacebookIntegration['access_token']);
        unset($editingFacebookIntegration['access_token'], $editingFacebookIntegration['proxy_pass_encrypted']);
    }
}
// Load Google Ads integrations for display
$allGoogleAdsIntegrations = $googleAds->getAll();
// Check if editing Google Ads integration
$editingGoogleAdsIntegration = null;
if (isset($_GET['edit_ga_integration'])) {
    $editingGoogleAdsIntegration = $googleAds->getById((int)$_GET['edit_ga_integration']);
}
// Cost credentials editor (Settings → API Cost Updates); same google_ads_integrations rows
$editingGoogleAdsCostIntegration = null;
if (isset($_GET['edit_ga_cost_integration'])) {
    $gaCostEditId = (int)$_GET['edit_ga_cost_integration'];
    $editingGoogleAdsCostIntegration = $gaCostEditId > 0
        ? ($googleAds->getById($gaCostEditId) ?? [])
        : [];
}
// Load Facebook Marketing integrations for display
$allFacebookMarketingIntegrations = $facebookMarketing->getAllIncludingPaused();
// Check if editing Facebook Marketing integration
$editingFacebookMarketingIntegration = null;
if (isset($_GET['edit_fm_integration'])) {
    $editingFacebookMarketingIntegration = $facebookMarketing->getById((int)$_GET['edit_fm_integration']);
    if (is_array($editingFacebookMarketingIntegration)) {
        $editingFacebookMarketingIntegration['access_token_set'] = !empty($editingFacebookMarketingIntegration['access_token']);
        unset($editingFacebookMarketingIntegration['access_token'], $editingFacebookMarketingIntegration['proxy_pass_encrypted']);
    }
}
// Load custom postbacks for display
$allCustomPostbacks = $customPostback->getAll();
$editingCustomPostback = null;
if (isset($_GET['edit_custom_postback'])) {
    $editingCustomPostback = $customPostback->getById((int)$_GET['edit_custom_postback']);
}
// Load campaigns and traffic sources for custom postback token context (only if editing custom postback)
$allCampaignsForTokens = [];
$allTrafficSourcesForTokens = [];
if ($activeTab === 'integrations' && isset($_GET['edit_custom_postback'])) {
    try {
        $allCampaignsForTokens = $campaignEntity->getAll();
        // Load all traffic sources to get their custom tokens
        $allTrafficSourcesForTokens = $trafficSourceEntity->getAll();
    } catch (Exception $e) {
        error_log('Error loading campaigns/traffic sources for token context: ' . $e->getMessage());
        $allCampaignsForTokens = [];
        $allTrafficSourcesForTokens = [];
    }
}

// Load campaigns for data management tab
$allCampaignsForData = [];
$statsHiddenIps = [];
if ($activeTab === 'data') {
    $campaignsResult = $db->query("SELECT id, name FROM campaigns ORDER BY name ASC");
    while ($camp = $campaignsResult->fetch_assoc()) {
        $allCampaignsForData[] = $camp;
    }
    try {
        $statsHiddenIps = (new \SimpleKuma\Stats\StatsHiddenIpService($db))->list();
    } catch (\Throwable $e) {
        // Migration 080 may not be applied yet
    }
}

// Load users for user management tab
$allUsers = [];
$allRoles = [];
$editingUser = null;
if ($activeTab === 'users') {
    if (SingleAdminMode::isEnabled()) {
        $activeTab = 'account';
    } elseif (!$permission || !$permission->hasPermission(Permission::PERM_USER_MANAGE)) {
        $errors['general'] = 'You do not have permission to manage users';
        $activeTab = 'domains'; // Redirect to domains if no permission
    } else {
        $allUsers = $userEntity->getAll();
        $allRoles = $userEntity->getAllRoles();
        
        $userAction = $_GET['user_action'] ?? 'list';
        $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
        
        if ($userAction === 'edit' && $userId) {
            $editingUser = $userEntity->getById($userId);
            if (!$editingUser) {
                $errors['general'] = 'User not found';
            }
        }
    }
}

// Load current settings
$allSettings = $settings->getAll();

// Get updated user info
if (!$currentUser) {
    $currentUser = $auth->getCurrentUser();
}

// Get user's pass_hash for password verification
$stmt = $db->prepare("SELECT pass_hash FROM users WHERE id = ?");
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$userRow = $stmt->get_result()->fetch_assoc();
$currentUser['pass_hash'] = $userRow['pass_hash'];

// Don't close DB connection here - it may still be needed
?>

<div class="page-header">
    <h1 class="page-title">Settings</h1>
    <p class="page-description">Configure your Simple KUMA installation.</p>
</div>

<?php if ($success): ?>
<div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 4px; margin-bottom: 20px; border-left: 4px solid #28a745;">
    ✅ <?= htmlspecialchars($success) ?>
</div>
<?php endif; ?>

<?php if (!empty($errors['general'])): ?>
<div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 4px; margin-bottom: 20px; border-left: 4px solid #d32f2f;">
    ⚠️ <?= htmlspecialchars($errors['general']) ?>
</div>
<?php endif; ?>

<?php
$showUsersTab = !SingleAdminMode::isEnabled()
    && $permission
    && $permission->hasPermission(Permission::PERM_USER_MANAGE);

$settingsTabs = [
    ['slug' => 'account', 'label' => 'Account'],
    ['slug' => 'domains', 'label' => 'Domains'],
    ['slug' => 'integrations', 'label' => 'Integrations'],
    ['slug' => 'api-costs', 'label' => 'API Cost Updates'],
    ['slug' => 'privacy', 'label' => 'Data Retention'],
    ['slug' => 'groups', 'label' => 'Campaign Groups'],
    ['slug' => 'data', 'label' => 'Data Management'],
    ['slug' => 'users', 'label' => 'Users', 'visible' => $showUsersTab],
    ['slug' => 'geoip', 'label' => 'Geolocation'],
    ['slug' => 'edge-redirect', 'label' => 'Edge Redirect'],
    ['slug' => 'updates', 'label' => 'Updates'],
    ['slug' => 'about', 'label' => 'About Kuma'],
];
$settingsTabs = array_values(array_filter(
    $settingsTabs,
    static fn(array $tab): bool => $tab['visible'] ?? true
));
?>

<div class="settings-layout">
    <div class="settings-mobile-nav">
        <label for="settings-section-select" class="settings-mobile-nav__label">Section</label>
        <select id="settings-section-select" class="settings-mobile-select" aria-label="Settings section">
            <?php foreach ($settingsTabs as $tab): ?>
                <option value="?page=settings&amp;tab=<?= htmlspecialchars($tab['slug']) ?>"
                    <?= $activeTab === $tab['slug'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($tab['label']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <nav class="settings-rail" aria-label="Settings sections">
        <?php foreach ($settingsTabs as $tab): ?>
            <a href="?page=settings&amp;tab=<?= htmlspecialchars($tab['slug']) ?>"
               class="settings-rail__item<?= $activeTab === $tab['slug'] ? ' is-active' : '' ?>">
                <?= htmlspecialchars($tab['label']) ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="settings-content">

<?php if ($activeTab === 'account'): ?>
    <?php
    $loginGate = new LoginGate();
    $loginGateEnabled = isset($_POST['action']) && $_POST['action'] === 'update_login_gate'
        ? isset($_POST['login_gate_enabled'])
        : $loginGate->isEnabled($db);
    $loginGateHasToken = $loginGate->hasTokenConfigured($db);
    $loginGateParam = isset($_POST['login_gate_param'])
        ? trim((string) $_POST['login_gate_param'])
        : $loginGate->getParamName($db);
    if ($loginGateParam === '') {
        $loginGateParam = LoginGate::DEFAULT_PARAM_NAME;
    }
    $loginGateRedirectUrl = isset($_POST['login_gate_redirect_url'])
        ? trim((string) $_POST['login_gate_redirect_url'])
        : $loginGate->getCustomRedirectUrl($db);
    $loginGateTokenValue = (isset($_POST['action']) && $_POST['action'] === 'update_login_gate')
        ? (string) ($_POST['login_gate_secret'] ?? $_POST['login_gate_token'] ?? '')
        : '';
    $loginGateRevealUrl = null;
    if (!empty($_SESSION['login_gate_url_reveal']) && is_string($_SESSION['login_gate_url_reveal'])) {
        $loginGateRevealUrl = $_SESSION['login_gate_url_reveal'];
        unset($_SESSION['login_gate_url_reveal']);
    }
    $canEditLoginGate = SingleAdminMode::isEnabled()
        || ($permission && $permission->hasPermission(Permission::PERM_SETTINGS_EDIT));
    ?>
    <?php require __DIR__ . '/settings-partials/account-tab.php'; ?>

<!-- Domains Tab -->
<?php elseif ($activeTab === 'domains'): ?>
    <?php $domainAction = $_GET['domain_action'] ?? 'list'; ?>
    
    <?php if ($domainAction === 'list'): ?>
        <!-- Domain List View -->
        <div class="card">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                <h2 class="card-title">Custom Tracking Domains</h2>
                <a href="?page=settings&tab=domains&domain_action=add" class="btn btn-primary">+ Add Domain</a>
            </div>
            <div class="card-body">
                <?php if (empty($allDomains)): ?>
                    <!-- Empty State -->
                    <div style="text-align: center; padding: 60px 20px; color: #666;">
                        <img src="<?= ASSETS_BASE_URL ?>/assets/images/domainbear.png" alt="Domains" style="width: 64px; height: 64px; margin-bottom: 16px; object-fit: contain;">
                        <h3 style="color: #3d5a26; margin-bottom: 12px;">No custom domains yet</h3>
                        <p style="margin-bottom: 24px; max-width: 500px; margin-left: auto; margin-right: auto;">
                            Add custom tracking domains to use branded URLs in your campaigns. This helps keep your main tracker domain private and allows you to match your landing page domains.
                        </p>
                        <a href="?page=settings&tab=domains&domain_action=add" class="btn btn-primary">Add Your First Domain</a>
                    </div>
                <?php else: ?>
                    <!-- Test Result Alert -->
                    <?php if ($testResult !== null && is_array($testResult)): ?>
                        <!-- Debug: Test result found, displaying -->
                        <div style="background: <?= $testResult['status'] === 'verified' ? '#d4edda' : ($testResult['status'] === 'failed' ? '#f8d7da' : '#fff3cd') ?>; 
                                    border: 2px solid <?= $testResult['status'] === 'verified' ? '#28a745' : ($testResult['status'] === 'failed' ? '#dc3545' : '#ffc107') ?>; 
                                    border-radius: 6px; padding: 16px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                                <span style="font-size: 24px;">
                                    <?= $testResult['status'] === 'verified' ? '✅' : ($testResult['status'] === 'failed' ? '❌' : '⚠️') ?>
                                </span>
                                <h4 style="margin: 0; color: <?= $testResult['status'] === 'verified' ? '#155724' : ($testResult['status'] === 'failed' ? '#721c24' : '#856404') ?>;">
                                    Domain Test Results
                                </h4>
                            </div>
                            <div style="font-size: 13px; color: <?= $testResult['status'] === 'verified' ? '#155724' : ($testResult['status'] === 'failed' ? '#721c24' : '#856404') ?>;">
                                <div style="margin-bottom: 8px; padding: 10px; background: rgba(255,255,255,0.5); border-radius: 4px; border-left: 3px solid <?= $testResult['dns_ok'] ? '#28a745' : '#dc3545' ?>;">
                                    <strong>DNS Check:</strong> 
                                    <?php if ($testResult['dns_ok']): ?>
                                        <span style="color: #28a745;">✅ OK</span>
                                    <?php else: ?>
                                        <span style="color: #dc3545;">❌ Failed</span>
                                    <?php endif; ?>
                                    <div style="margin-top: 4px; font-size: 12px; color: #666;">
                                        <?= !empty($testResult['dns_message']) ? htmlspecialchars($testResult['dns_message']) : 'No DNS check information available' ?>
                                    </div>
                                </div>
                                <div style="margin-bottom: 8px; padding: 10px; background: rgba(255,255,255,0.5); border-radius: 4px; border-left: 3px solid <?= $testResult['ssl_ok'] ? '#28a745' : '#dc3545' ?>;">
                                    <strong>SSL Check:</strong> 
                                    <?php if ($testResult['ssl_ok']): ?>
                                        <span style="color: #28a745;">✅ OK</span>
                                    <?php else: ?>
                                        <span style="color: #dc3545;">❌ Failed</span>
                                    <?php endif; ?>
                                    <div style="margin-top: 4px; font-size: 12px; color: #666;">
                                        <?= !empty($testResult['ssl_message']) ? htmlspecialchars($testResult['ssl_message']) : 'No SSL check information available' ?>
                                    </div>
                                </div>
                                <div style="padding: 10px; background: rgba(255,255,255,0.5); border-radius: 4px;">
                                    <strong>Overall Status:</strong> <span style="font-weight: 600; text-transform: uppercase;"><?= ucfirst($testResult['status']) ?></span>
                                </div>
                                <?php if (!empty($testResult['error'])): ?>
                                    <div style="margin-top: 12px; padding: 12px; background: rgba(0,0,0,0.1); border-radius: 4px; border-left: 3px solid #dc3545;">
                                        <strong>Details:</strong> 
                                        <div style="margin-top: 4px; font-size: 12px;">
                                            <?= htmlspecialchars($testResult['error']) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php elseif (isset($_GET['success']) && $_GET['success'] === 'domain_tested'): ?>
                        <!-- Debug: Success param present but no test result -->
                        <div style="background: #fff3cd; border: 2px solid #ffc107; border-radius: 6px; padding: 16px; margin-bottom: 20px;">
                            <h4 style="margin: 0 0 8px 0; color: #856404;">⚠️ Test Completed</h4>
                            <p style="margin: 0; color: #856404; font-size: 13px;">
                                The test was run, but the results could not be displayed. 
                                <?php if ($testResult === null): ?>
                                    Test result variable is null. Session may not be working correctly.
                                <?php else: ?>
                                    Test result is: <?= gettype($testResult) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Domain Table (Desktop) -->
                    <div class="table-wrapper desktop-only" style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: #f5f5f5; border-bottom: 2px solid #ddd;">
                                    <th style="padding: 12px; text-align: left; font-weight: 600;">Domain</th>
                                    <th style="padding: 12px; text-align: left; font-weight: 600;">Status</th>
                                    <th style="padding: 12px; text-align: left; font-weight: 600;">Last Verified</th>
                                    <th style="padding: 12px; text-align: right; font-weight: 600;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allDomains as $domain): ?>
                                    <tr style="border-bottom: 1px solid #eee;">
                                        <td style="padding: 12px; font-family: monospace; font-size: 13px; word-break: break-all;">
                                            <?= htmlspecialchars($domain['domain']) ?>
                                        </td>
                                        <td style="padding: 12px;">
                                            <?php
                                            $statusColors = [
                                                'verified' => ['bg' => '#d4edda', 'text' => '#155724', 'border' => '#28a745'],
                                                'pending' => ['bg' => '#fff3cd', 'text' => '#856404', 'border' => '#ffc107'],
                                                'failed' => ['bg' => '#f8d7da', 'text' => '#721c24', 'border' => '#dc3545']
                                            ];
                                            $statusColor = $statusColors[$domain['status']] ?? $statusColors['pending'];
                                            ?>
                                            <span style="display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; background: <?= $statusColor['bg'] ?>; color: <?= $statusColor['text'] ?>; border: 1px solid <?= $statusColor['border'] ?>;">
                                                <?= ucfirst($domain['status']) ?>
                                            </span>
                                        </td>
                                        <td style="padding: 12px; font-size: 12px; color: #666;">
                                            <?php if ($domain['verified_at']): ?>
                                                <?= date('M d, Y H:i', strtotime($domain['verified_at'])) ?>
                                            <?php else: ?>
                                                <span style="color: #999;">Never</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 12px; text-align: right;">
                                            <a href="?page=settings&tab=domains&action=test_domain&domain_id=<?= $domain['id'] ?>" 
                                               style="width: 36px; height: 36px; padding: 0; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s; color: #666; text-decoration: none; margin-right: 6px;"
                                               title="Test Domain"
                                               onclick="return confirm('Test domain verification? This will check DNS and SSL configuration.');"
                                               onmouseover="this.style.background='#e3f2fd'; this.style.borderColor='#2196F3'; this.style.color='#2196F3';"
                                               onmouseout="this.style.background='#fff'; this.style.borderColor='#ddd'; this.style.color='#666';">
                                                🔍
                                            </a>
                                            <a href="?page=settings&tab=domains&domain_action=edit&domain_id=<?= $domain['id'] ?>" 
                                               style="width: 36px; height: 36px; padding: 0; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s; color: #666; text-decoration: none; margin-right: 6px;"
                                               title="Edit Domain"
                                               onmouseover="this.style.background='#e3f2fd'; this.style.borderColor='#2196F3'; this.style.color='#2196F3';"
                                               onmouseout="this.style.background='#fff'; this.style.borderColor='#ddd'; this.style.color='#666';">
                                                ✏️
                                            </a>
                                            <form method="POST" style="display: inline; margin: 0;" 
                                                  onsubmit="return confirm('Are you sure you want to delete this domain? This will not affect existing campaigns, but they will revert to using the main tracker domain.');">
                                                <input type="hidden" name="action" value="delete_domain">
                                                <input type="hidden" name="domain_id" value="<?= $domain['id'] ?>">
                                                <button type="submit" 
                                                        style="width: 36px; height: 36px; padding: 0; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s; color: #666;"
                                                        title="Delete Domain"
                                                        onmouseover="this.style.background='#ffebee'; this.style.borderColor='#f44336'; this.style.color='#f44336';"
                                                        onmouseout="this.style.background='#fff'; this.style.borderColor='#ddd'; this.style.color='#666';">
                                                    🗑️
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Mobile Domain Cards (hidden on desktop) -->
                    <div class="mobile-domain-cards mobile-only">
                        <?php foreach ($allDomains as $domain): 
                            $statusColors = [
                                'verified' => ['bg' => '#d4edda', 'text' => '#155724', 'border' => '#28a745'],
                                'pending' => ['bg' => '#fff3cd', 'text' => '#856404', 'border' => '#ffc107'],
                                'failed' => ['bg' => '#f8d7da', 'text' => '#721c24', 'border' => '#dc3545']
                            ];
                            $statusColor = $statusColors[$domain['status']] ?? $statusColors['pending'];
                        ?>
                            <div class="mobile-domain-card" style="background: var(--color-white); border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); padding: var(--spacing-md); margin-bottom: var(--spacing-md); box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                                <!-- Header: Domain -->
                                <div style="margin-bottom: var(--spacing-sm); border-bottom: 1px solid rgba(0,0,0,0.1); padding-bottom: var(--spacing-xs);">
                                    <div style="font-weight: 600; font-size: 16px; color: #3d5a26; font-family: monospace; word-break: break-all;">
                                        <?= htmlspecialchars($domain['domain']) ?>
                                    </div>
                                </div>
                                
                                <!-- Status -->
                                <div style="margin-bottom: var(--spacing-sm);">
                                    <div style="font-size: 11px; color: #666; margin-bottom: 4px;"><strong>Status</strong></div>
                                    <div>
                                        <span style="display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 11px; font-weight: 600; background: <?= $statusColor['bg'] ?>; color: <?= $statusColor['text'] ?>; border: 1px solid <?= $statusColor['border'] ?>;">
                                            <?= ucfirst($domain['status']) ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <!-- Last Verified -->
                                <div style="margin-bottom: var(--spacing-sm);">
                                    <div style="font-size: 11px; color: #666; margin-bottom: 4px;"><strong>Last Verified</strong></div>
                                    <div style="font-size: 12px; color: #666;">
                                        <?php if ($domain['verified_at']): ?>
                                            <?= date('M d, Y H:i', strtotime($domain['verified_at'])) ?>
                                        <?php else: ?>
                                            <span style="color: #999;">Never</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Actions -->
                                <div style="display: flex; gap: 8px; border-top: 1px solid rgba(0,0,0,0.1); padding-top: var(--spacing-sm);">
                                    <a href="?page=settings&tab=domains&action=test_domain&domain_id=<?= $domain['id'] ?>" 
                                       style="flex: 1; padding: 8px 12px; font-size: 12px; border: 1px solid #ddd; border-radius: 4px; background: #fff; cursor: pointer; text-decoration: none; color: #666; text-align: center; display: inline-block;"
                                       onclick="return confirm('Test domain verification? This will check DNS and SSL configuration.');"
                                       title="Test Domain">
                                        🔍 Test
                                    </a>
                                    <a href="?page=settings&tab=domains&domain_action=edit&domain_id=<?= $domain['id'] ?>" 
                                       style="flex: 1; padding: 8px 12px; font-size: 12px; border: 1px solid #ddd; border-radius: 4px; background: #fff; cursor: pointer; text-decoration: none; color: #666; text-align: center; display: inline-block;"
                                       title="Edit Domain">
                                        ✏️ Edit
                                    </a>
                                    <form method="POST" 
                                          style="flex: 1; margin: 0;" 
                                          onsubmit="return confirm('Are you sure you want to delete this domain? This will not affect existing campaigns, but they will revert to using the main tracker domain.');">
                                        <input type="hidden" name="action" value="delete_domain">
                                        <input type="hidden" name="domain_id" value="<?= $domain['id'] ?>">
                                        <button type="submit" 
                                                style="width: 100%; padding: 8px 12px; font-size: 12px; border: 1px solid #ddd; border-radius: 4px; background: #fff; cursor: pointer; color: #666;">
                                            🗑️ Delete
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- DNS Setup Help Card -->
        <?php if (!empty($allDomains)): ?>
        <div class="card" style="margin-top: 24px;">
            <div class="card-header">
                <h3 class="card-title">📚 DNS Setup Instructions</h3>
            </div>
            <div class="card-body">
                <p style="margin-bottom: 16px;">To use a custom tracking domain, you need to configure DNS records at your domain registrar and set up the domain on your server:</p>
                
                <!-- DNS Configuration -->
                <div style="background: #f9f9f9; padding: 16px; border-radius: 6px; border-left: 4px solid #3d5a26; margin-bottom: 20px;">
                    <h4 style="margin: 0 0 12px 0; color: #3d5a26;">Step 1: Configure DNS Records</h4>
                    <p style="margin: 0 0 12px 0; font-size: 14px;">At your domain registrar, add one of the following DNS records:</p>
                    
                    <div style="background: #fff; padding: 12px; border-radius: 4px; margin-bottom: 12px;">
                        <strong style="color: #3d5a26;">Option 1: A Record (Recommended for Addon Domains)</strong>
                        <p style="margin: 8px 0 0 0; font-size: 14px;">
                            Point your domain to the same IP address as your main Kuma installation:<br>
                            <code style="background: #f5f5f5; padding: 4px 8px; border-radius: 4px; display: inline-block; margin-top: 4px;">Type: A | Host: @ (or subdomain) | Value: [Your Server IP Address]</code>
                        </p>
                        <p style="margin: 8px 0 0 0; font-size: 13px; color: #666;">
                            <strong>Note:</strong> Use the same IP address that your main Kuma domain (<?= parse_url(BASE_URL, PHP_URL_HOST) ?>) points to.
                        </p>
                    </div>
                    
                    <div style="background: #fff; padding: 12px; border-radius: 4px;">
                        <strong style="color: #3d5a26;">Option 2: CNAME Record</strong>
                        <p style="margin: 8px 0 0 0; font-size: 14px;">
                            Point your domain to your main tracker domain:<br>
                            <code style="background: #f5f5f5; padding: 4px 8px; border-radius: 4px; display: inline-block; margin-top: 4px;">Type: CNAME | Host: @ (or subdomain) | Value: <?= parse_url(BASE_URL, PHP_URL_HOST) ?></code>
                        </p>
                    </div>
                </div>
                
                <!-- cPanel Addon Domain Setup -->
                <div style="background: #e3f2fd; padding: 16px; border-radius: 6px; border-left: 4px solid #2196F3; margin-bottom: 20px;">
                    <h4 style="margin: 0 0 12px 0; color: #1976d2;">Step 2: Configure Addon Domain in cPanel</h4>
                    <p style="margin: 0 0 12px 0; font-size: 14px; color: #1565c0;">
                        <strong>If you're using cPanel, follow these steps:</strong>
                    </p>
                    <ol style="margin: 0; padding-left: 20px; font-size: 14px; line-height: 1.8; color: #1565c0;">
                        <li>Log into your cPanel account</li>
                        <li>Navigate to <strong>"Addon Domains"</strong> (under "Domains" section)</li>
                        <li>Enter your tracking domain name (e.g., <code style="background: rgba(255,255,255,0.7); padding: 2px 6px; border-radius: 3px;">track.example.com</code>)</li>
                        <li><strong>Important:</strong> In the "Document Root" field, enter the <strong>same document root path</strong> as your main Kuma installation</li>
                        <li>This allows the addon domain to share the same files and folder as your main Kuma tracker</li>
                        <li>Click "Add Domain" to create the addon domain</li>
                    </ol>
                    <div style="background: #fff; padding: 12px; border-radius: 4px; margin-top: 12px; border: 1px solid #90caf9;">
                        <p style="margin: 0; font-size: 13px; color: #1565c0;">
                            <strong>💡 Why share the document root?</strong> This ensures both domains serve the same Kuma application files, allowing your tracking domain to work with the same installation without duplicating files or configuration.
                        </p>
                    </div>
                </div>
                
                <!-- SSL Certificate -->
                <div style="background: #fff3cd; padding: 16px; border-radius: 6px; border-left: 4px solid #ffc107; margin-bottom: 20px;">
                    <h4 style="margin: 0 0 12px 0; color: #856404;">Step 3: Set Up SSL Certificate</h4>
                    <p style="margin: 0; font-size: 14px; color: #856404;">
                        After DNS propagation (usually 5-30 minutes), set up an SSL certificate:
                    </p>
                    <ul style="margin: 12px 0 0 0; padding-left: 20px; font-size: 14px; line-height: 1.8; color: #856404;">
                        <li><strong>cPanel AutoSSL:</strong> Go to "SSL/TLS Status" and click "Run AutoSSL" - it will automatically issue a free Let's Encrypt certificate</li>
                        <li><strong>Cloudflare:</strong> If using Cloudflare, enable "Full (strict)" SSL mode - Cloudflare handles SSL automatically</li>
                        <li><strong>Manual Let's Encrypt:</strong> Use cPanel's "Let's Encrypt" tool to manually request a certificate</li>
                    </ul>
                </div>
                
                <!-- Verification -->
                <div style="background: #d4edda; padding: 16px; border-radius: 6px; border-left: 4px solid #28a745;">
                    <h4 style="margin: 0 0 12px 0; color: #155724;">Step 4: Verify Setup</h4>
                    <p style="margin: 0; font-size: 14px; color: #155724;">
                        Once DNS has propagated and SSL is configured, use the <strong>"Test Domain"</strong> button (🔍) next to your domain in the list above to verify:
                    </p>
                    <ul style="margin: 12px 0 0 0; padding-left: 20px; font-size: 14px; line-height: 1.8; color: #155724;">
                        <li>DNS resolution is working correctly</li>
                        <li>SSL certificate is valid and active</li>
                        <li>Domain is accessible and pointing to your Kuma installation</li>
                    </ul>
                </div>
            </div>
        </div>
        <?php endif; ?>
    
    <?php elseif ($domainAction === 'add' || $domainAction === 'edit'): ?>
        <!-- Add/Edit Domain Form -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?= $domainAction === 'add' ? 'Add Custom Tracking Domain' : 'Edit Tracking Domain' ?></h2>
            </div>
            <div class="card-body">
                <form method="POST" action="?page=settings&tab=domains">
                    <input type="hidden" name="action" value="<?= $domainAction === 'add' ? 'create_domain' : 'update_domain' ?>">
                    <?php if ($domainAction === 'edit' && isset($editingDomain)): ?>
                        <input type="hidden" name="domain_id" value="<?= $editingDomain['id'] ?>">
                    <?php endif; ?>
                    
                    <div style="margin-bottom: 24px;">
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">
                            Domain URL <span style="color: #d32f2f;">*</span>
                        </label>
                        <input type="url" 
                               name="domain" 
                               required
                               value="<?= htmlspecialchars($editingDomain['domain'] ?? '') ?>"
                               placeholder="https://track.example.com"
                               pattern="^https://.*"
                               style="width: 100%; max-width: 500px; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                        <div style="font-size: 12px; color: #666; margin-top: 4px;">
                            Enter the full domain URL starting with https:// (e.g., https://track.example.com or https://subdomain.example.com)
                        </div>
                        <?php if (isset($errors['domain']['domain'])): ?>
                            <div style="color: #d32f2f; font-size: 12px; margin-top: 4px;">
                                <?= htmlspecialchars($errors['domain']['domain']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div style="background: #f9f9f9; padding: 16px; border-radius: 6px; border-left: 4px solid #3d5a26; margin-bottom: 24px;">
                        <h4 style="margin: 0 0 12px 0; color: #3d5a26;">Before Adding:</h4>
                        <ol style="margin: 0; padding-left: 20px; font-size: 14px; line-height: 1.8;">
                            <li>Purchase the domain (if you don't own it)</li>
                            <li>Configure DNS records (A record or CNAME) at your domain registrar</li>
                            <li><strong>If using cPanel:</strong> Add the domain as an addon domain and set it to share the document root with your main Kuma installation</li>
                            <li>Set up SSL certificate (Let's Encrypt or Cloudflare)</li>
                            <li>Add the domain here and use "Test Domain" to verify</li>
                        </ol>
                        <div style="background: #d4edda; border: 1px solid #28a745; border-radius: 4px; padding: 12px; margin-top: 16px;">
                            <p style="margin: 0; font-size: 13px; color: #155724; line-height: 1.6;">
                                <strong>✅ Seamless Integration:</strong> Once configured, your custom tracking domain will work seamlessly for:
                            </p>
                            <ul style="margin: 8px 0 0 0; padding-left: 20px; font-size: 13px; color: #155724; line-height: 1.6;">
                                <li><strong>Campaign Tracking Links:</strong> Use custom domains in campaign settings - all tracking links will use your branded domain</li>
                                <li><strong>Postback URLs:</strong> Select custom domains in Postback URLs page - networks can send conversions to your branded domain</li>
                                <li><strong>Conversion Pixels:</strong> Use custom domains for client-side tracking pixels on thank-you pages</li>
                            </ul>
                            <p style="margin: 8px 0 0 0; font-size: 13px; color: #155724; line-height: 1.6;">
                                All tracking, redirects, and postbacks work automatically because the addon domain shares the same document root and application files.
                            </p>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 12px;">
                        <button type="submit" class="btn btn-primary">
                            <?= $domainAction === 'add' ? 'Add Domain' : 'Update Domain' ?>
                        </button>
                        <a href="?page=settings&tab=domains" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

<?php elseif ($activeTab === 'integrations'): ?>
    <!-- List of Facebook Integrations -->
    <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <h2 class="card-title">Facebook Conversions API (CAPI) Integrations</h2>
            <a href="?page=settings&tab=integrations&edit_fb_integration=0" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px; color: #fff;">
                <img src="<?= ASSETS_BASE_URL ?>/assets/images/tfacebook.png" alt="" style="width: 24px; height: 24px;">
                + Add Integration
            </a>
        </div>
        <div class="card-body">
            <?php if (isset($errors['fb_integration']) && is_array($errors['fb_integration'])): ?>
                <div style="background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 12px; border-radius: 4px; margin-bottom: 20px;">
                    <strong>Errors:</strong>
                    <ul style="margin: 8px 0 0 20px;">
                        <?php foreach ($errors['fb_integration'] as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <?php if (empty($allFacebookIntegrations)): ?>
                <div style="text-align: center; padding: 40px; color: #999;">
                    <p>No Facebook CAPI integrations configured yet.</p>
                    <a href="?page=settings&tab=integrations&edit_fb_integration=0" class="btn btn-primary" style="margin-top: 16px; display: inline-flex; align-items: center; gap: 6px; color: #fff;">
                        <img src="<?= ASSETS_BASE_URL ?>/assets/images/tfacebook.png" alt="" style="width: 24px; height: 24px;">
                        Create Your First Integration
                    </a>
                </div>
            <?php else: ?>
                <div class="table-wrapper desktop-only" style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f5f5f5; border-bottom: 2px solid #ddd;">
                                <th style="padding: 12px; text-align: left; font-weight: 600;">Name</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600;">Pixel ID</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600;">Event Type</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600;">Test Code</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600;">Campaigns</th>
                                <th style="padding: 12px; text-align: right; font-weight: 600;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allFacebookIntegrations as $integration): ?>
                                <tr style="border-bottom: 1px solid #eee;">
                                    <td style="padding: 12px;"><?= htmlspecialchars($integration['name']) ?></td>
                                    <td style="padding: 12px; font-family: monospace; font-size: 13px;"><?= htmlspecialchars($integration['pixel_id']) ?></td>
                                    <td style="padding: 12px;">
                                        <span style="display: inline-block; padding: 4px 10px; background: #e3f2fd; color: #1976d2; border-radius: 12px; font-size: 12px; font-weight: 500;">
                                            <?= htmlspecialchars($integration['event_type'] ?? 'Purchase') ?>
                                        </span>
                                    </td>
                                    <td style="padding: 12px;"><?= !empty($integration['test_code']) ? htmlspecialchars($integration['test_code']) : '<span style="color: #999;">—</span>' ?></td>
                                    <td style="padding: 12px;"><?= (int)($integration['campaign_count'] ?? 0) ?></td>
                                    <td style="padding: 12px; text-align: right;">
                                        <a href="?page=settings&tab=integrations&edit_fb_integration=<?= $integration['id'] ?>" 
                                           style="width: 36px; height: 36px; padding: 0; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s; color: #666; text-decoration: none; margin-right: 6px;"
                                           title="Edit Integration"
                                           onmouseover="this.style.background='#e3f2fd'; this.style.borderColor='#2196F3'; this.style.color='#2196F3';"
                                           onmouseout="this.style.background='#fff'; this.style.borderColor='#ddd'; this.style.color='#666';">
                                            ✏️
                                        </a>
                                        <form method="post" style="display: inline; margin: 0;" 
                                              onsubmit="return confirm('Are you sure you want to delete this integration? Campaigns using it will have their integration removed.');">
                                            <input type="hidden" name="action" value="delete_fb_integration">
                                            <input type="hidden" name="fb_integration_id" value="<?= $integration['id'] ?>">
                                            <button type="submit" 
                                                    style="width: 36px; height: 36px; padding: 0; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s; color: #666;"
                                                    title="Delete Integration"
                                                    onmouseover="this.style.background='#ffebee'; this.style.borderColor='#f44336'; this.style.color='#f44336';"
                                                    onmouseout="this.style.background='#fff'; this.style.borderColor='#ddd'; this.style.color='#666';">
                                                🗑️
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Mobile Facebook CAPI Cards -->
                <div class="mobile-fb-capi-cards mobile-only">
                    <?php foreach ($allFacebookIntegrations as $integration): ?>
                        <div class="mobile-fb-capi-card" style="background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 16px; margin-bottom: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                            <!-- Header: Name -->
                            <div style="margin-bottom: var(--spacing-sm); border-bottom: 1px solid rgba(0,0,0,0.1); padding-bottom: var(--spacing-xs);">
                                <div style="font-weight: 600; font-size: 16px; color: #3d5a26;">
                                    <?= htmlspecialchars($integration['name']) ?>
                                </div>
                            </div>
                            
                            <!-- Pixel ID -->
                            <div style="margin-bottom: var(--spacing-sm);">
                                <div style="font-size: 11px; color: #666; margin-bottom: 4px;"><strong>Pixel ID</strong></div>
                                <div style="font-family: monospace; font-size: 12px; color: #333; word-break: break-all;">
                                    <?= htmlspecialchars($integration['pixel_id']) ?>
                                </div>
                            </div>
                            
                            <!-- Event Type and Test Code -->
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-sm); margin-bottom: var(--spacing-sm);">
                                <div>
                                    <div style="font-size: 11px; color: #666; margin-bottom: 4px;"><strong>Event Type</strong></div>
                                    <div>
                                        <span style="display: inline-block; padding: 4px 10px; background: #e3f2fd; color: #1976d2; border-radius: 12px; font-size: 11px; font-weight: 500;">
                                            <?= htmlspecialchars($integration['event_type'] ?? 'Purchase') ?>
                                        </span>
                                    </div>
                                </div>
                                <div>
                                    <div style="font-size: 11px; color: #666; margin-bottom: 4px;"><strong>Test Code</strong></div>
                                    <div style="font-size: 12px; color: #333;">
                                        <?= !empty($integration['test_code']) ? htmlspecialchars($integration['test_code']) : '<span style="color: #999;">—</span>' ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Campaigns -->
                            <div style="margin-bottom: var(--spacing-sm);">
                                <div style="font-size: 11px; color: #666; margin-bottom: 4px;"><strong>Campaigns</strong></div>
                                <div style="font-size: 14px; color: #333;">
                                    <?= (int)($integration['campaign_count'] ?? 0) ?>
                                </div>
                            </div>
                            
                            <!-- Actions -->
                            <div style="display: flex; gap: 8px; border-top: 1px solid rgba(0,0,0,0.1); padding-top: var(--spacing-sm);">
                                <a href="?page=settings&tab=integrations&edit_fb_integration=<?= $integration['id'] ?>" 
                                   style="flex: 1; padding: 8px 12px; font-size: 12px; border: 1px solid #ddd; border-radius: 4px; background: #fff; cursor: pointer; text-decoration: none; color: #666; text-align: center; display: inline-block;">
                                    ✏️ Edit
                                </a>
                                <form method="post" 
                                      style="flex: 1; margin: 0;" 
                                      onsubmit="return confirm('Are you sure you want to delete this integration? Campaigns using it will have their integration removed.');">
                                    <input type="hidden" name="action" value="delete_fb_integration">
                                    <input type="hidden" name="fb_integration_id" value="<?= $integration['id'] ?>">
                                    <button type="submit" 
                                            style="width: 100%; padding: 8px 12px; font-size: 12px; border: 1px solid #f44336; border-radius: 4px; background: #f44336; cursor: pointer; color: white;">
                                        🗑️ Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add/Edit Form -->
    <?php if ($editingFacebookIntegration !== null || isset($_GET['edit_fb_integration'])): ?>
        <div class="card" style="margin-top: 24px;">
            <div class="card-header">
                <h2 class="card-title"><?= $editingFacebookIntegration ? 'Edit' : 'Add' ?> Facebook CAPI Integration</h2>
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="<?= $editingFacebookIntegration ? 'update_fb_integration' : 'create_fb_integration' ?>">
                    <?php if ($editingFacebookIntegration): ?>
                        <input type="hidden" name="fb_integration_id" value="<?= $editingFacebookIntegration['id'] ?>">
                    <?php endif; ?>
                    
                    <div style="max-width: 600px;">
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Integration Name <span style="color: #d32f2f;">*</span></label>
                            <input type="text" name="fb_integration_name" 
                                   value="<?= htmlspecialchars($editingFacebookIntegration['name'] ?? '') ?>"
                                   placeholder="e.g., Main Facebook Account, Test Account"
                                   required
                                   style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                            <div style="font-size: 12px; color: #666; margin-top: 4px;">A friendly name to identify this integration</div>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Pixel ID <span style="color: #d32f2f;">*</span></label>
                            <input type="text" name="fb_pixel_id" 
                                   value="<?= htmlspecialchars($editingFacebookIntegration['pixel_id'] ?? '') ?>"
                                   placeholder="123456789012345"
                                   required
                                   style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-family: monospace;">
                            <div style="font-size: 12px; color: #666; margin-top: 4px;">Your Facebook Pixel ID from Events Manager</div>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Access Token <?php if (empty(($editingFacebookIntegration ?? [])['access_token_set'])): ?><span style="color: #d32f2f;">*</span><?php endif; ?></label>
                            <input type="password" name="fb_access_token" autocomplete="new-password"
                                   value=""
                                   placeholder="<?= !empty(($editingFacebookIntegration ?? [])['access_token_set']) ? 'Leave blank to keep existing token' : 'EAAxxxxxxxxxxxxxxxx' ?>"
                                   <?= empty(($editingFacebookIntegration ?? [])['access_token_set']) ? 'required' : '' ?>
                                   style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-family: monospace;">
                            <div style="font-size: 12px; color: #666; margin-top: 4px;">Server-side access token from Facebook Business Settings<?= !empty(($editingFacebookIntegration ?? [])['access_token_set']) ? ' (leave blank to keep current)' : '' ?></div>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Test Event Code (Optional)</label>
                            <input type="text" name="fb_test_code" 
                                   value="<?= htmlspecialchars($editingFacebookIntegration['test_code'] ?? '') ?>"
                                   placeholder="TEST12345"
                                   style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-family: monospace;">
                            <div style="font-size: 12px; color: #666; margin-top: 4px;">For testing events in Events Manager (optional)</div>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Event Type <span style="color: #d32f2f;">*</span></label>
                            <?php
                            // Get current event type (check if it's a custom value not in the list)
                            $currentEventType = $editingFacebookIntegration['event_type'] ?? 'Purchase';
                            $standardEvents = [
                                'Purchase',
                                'Lead',
                                'CompleteRegistration',
                                'Contact',
                                'FindLocation',
                                'Schedule',
                                'Search',
                                'StartTrial',
                                'SubmitApplication',
                                'Subscribe',
                                'ViewContent',
                                'AddPaymentInfo',
                                'AddToCart',
                                'AddToWishlist',
                                'CustomizeProduct',
                                'Donate',
                                'InitiateCheckout',
                                'PageView',
                            ];
                            $isCustom = !in_array($currentEventType, $standardEvents, true);
                            ?>
                            <select name="fb_event_type" id="fb_event_type" 
                                    onchange="toggleCustomEventType(this)"
                                    required
                                    style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                                <?php foreach ($standardEvents as $event): ?>
                                    <option value="<?= htmlspecialchars($event) ?>" 
                                            <?= (!$isCustom && $currentEventType === $event) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($event) ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="custom" <?= $isCustom ? 'selected' : '' ?>>Custom Event</option>
                            </select>
                            <div id="custom_event_type_wrapper" style="margin-top: 12px; <?= $isCustom ? '' : 'display: none;' ?>">
                                <input type="text" name="fb_custom_event_type" id="fb_custom_event_type"
                                       value="<?= $isCustom ? htmlspecialchars($currentEventType) : '' ?>"
                                       placeholder="Enter custom event name (e.g., CustomConversion, VideoPlay)"
                                       style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-family: monospace;">
                                <div style="font-size: 12px; color: #666; margin-top: 4px;">Enter a custom event name for Facebook CAPI</div>
                            </div>
                            <div style="font-size: 12px; color: #666; margin-top: 4px;">Default Meta event when a postback has no <code>et</code> (or an unmapped key). These 17 (+ PageView) are Meta's full web standard set; use Custom for anything else.</div>
                        </div>

                        <?php
                        $existingMapping = [];
                        if (is_array($editingFacebookIntegration) && !empty($editingFacebookIntegration['event_mapping_json'])) {
                            $decodedMap = json_decode($editingFacebookIntegration['event_mapping_json'], true);
                            if (is_array($decodedMap)) {
                                $existingMapping = $decodedMap;
                            }
                        }
                        if ($existingMapping === []) {
                            $existingMapping = [
                                'register' => 'CompleteRegistration',
                                'ftd' => 'Purchase',
                                'rebill' => 'Subscribe',
                                'lead' => 'Lead',
                            ];
                        }
                        $sendPageviewChecked = is_array($editingFacebookIntegration)
                            && !empty($editingFacebookIntegration['send_pageview_on_click']);
                        ?>

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Event mapping (optional)</label>
                            <p style="font-size: 12px; color: #666; margin: 0 0 10px;">
                                Map inbound postback <code>et</code> values to Meta event names.
                                Example: <code>et=register</code> → CompleteRegistration.
                                Prefer standard events for optimization; Custom works but Meta will not optimize for it until you create a Custom Conversion in Events Manager.
                            </p>
                            <div id="fb_event_mapping_rows">
                                <?php foreach ($existingMapping as $mapKey => $mapEvent): ?>
                                    <?php
                                    $mapIsCustom = !in_array($mapEvent, $standardEvents, true);
                                    $mapSelectValue = $mapIsCustom ? 'custom' : $mapEvent;
                                    ?>
                                    <div class="fb-map-row" style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 8px; align-items: center;">
                                        <input type="text" name="fb_map_key[]" value="<?= htmlspecialchars((string)$mapKey) ?>"
                                               placeholder="inbound key (e.g. register)"
                                               style="flex: 1; min-width: 140px; padding: 8px; border: 2px solid #ddd; border-radius: 4px; font-family: monospace;">
                                        <span style="color:#888;">→</span>
                                        <select name="fb_map_event[]" onchange="toggleMapCustom(this)"
                                                style="flex: 1; min-width: 160px; padding: 8px; border: 2px solid #ddd; border-radius: 4px;">
                                            <?php foreach ($standardEvents as $event): ?>
                                                <option value="<?= htmlspecialchars($event) ?>" <?= $mapSelectValue === $event ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($event) ?>
                                                </option>
                                            <?php endforeach; ?>
                                            <option value="custom" <?= $mapIsCustom ? 'selected' : '' ?>>Custom Event</option>
                                        </select>
                                        <input type="text" name="fb_map_custom_event[]"
                                               value="<?= $mapIsCustom ? htmlspecialchars((string)$mapEvent) : '' ?>"
                                               placeholder="Custom event name"
                                               class="fb-map-custom-input"
                                               style="flex: 1; min-width: 140px; padding: 8px; border: 2px solid #ddd; border-radius: 4px; font-family: monospace; <?= $mapIsCustom ? '' : 'display:none;' ?>">
                                        <button type="button" onclick="removeFbMapRow(this)"
                                                title="Remove mapping"
                                                aria-label="Remove mapping"
                                                style="padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; background: #f5f5f5; color: #666; cursor: pointer; white-space: nowrap;">
                                            Remove
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-secondary" onclick="addFbMapRow()" style="margin-top: 4px;">+ Add mapping</button>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display: flex; align-items: center; gap: 8px; font-weight: 600;">
                                <input type="checkbox" name="fb_send_pageview_on_click" value="1" <?= $sendPageviewChecked ? 'checked' : '' ?>>
                                Send PageView on click
                            </label>
                            <div style="font-size: 12px; color: #666; margin-top: 4px;">
                                When enabled, fires a Meta CAPI <code>PageView</code> asynchronously after a tracked click (does not delay redirects).
                            </div>
                        </div>

                        <div style="display: flex; gap: 12px;">
                            <button type="submit" class="btn btn-primary"><?= $editingFacebookIntegration ? 'Update' : 'Create' ?> Integration</button>
                            <a href="?page=settings&tab=integrations" class="btn btn-secondary">Cancel</a>
                        </div>
                    </div>
                </form>
                <script>
                function toggleCustomEventType(select) {
                    const customWrapper = document.getElementById('custom_event_type_wrapper');
                    const customInput = document.getElementById('fb_custom_event_type');
                    
                    if (select.value === 'custom') {
                        customWrapper.style.display = 'block';
                        if (customInput) {
                            customInput.required = true;
                        }
                    } else {
                        customWrapper.style.display = 'none';
                        if (customInput) {
                            customInput.required = false;
                            customInput.value = '';
                        }
                    }
                }

                function toggleMapCustom(select) {
                    const row = select.closest('.fb-map-row');
                    if (!row) return;
                    const customInput = row.querySelector('.fb-map-custom-input');
                    if (!customInput) return;
                    if (select.value === 'custom') {
                        customInput.style.display = '';
                        customInput.required = true;
                    } else {
                        customInput.style.display = 'none';
                        customInput.required = false;
                        customInput.value = '';
                    }
                }

                function removeFbMapRow(btn) {
                    const row = btn.closest('.fb-map-row');
                    if (!row) return;
                    row.remove();
                }

                function addFbMapRow() {
                    const wrap = document.getElementById('fb_event_mapping_rows');
                    if (!wrap) return;
                    const standard = <?= json_encode(array_values($standardEvents)) ?>;
                    const row = document.createElement('div');
                    row.className = 'fb-map-row';
                    row.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px;align-items:center;';
                    let opts = standard.map(e => '<option value="' + e + '">' + e + '</option>').join('');
                    opts += '<option value="custom">Custom Event</option>';
                    row.innerHTML = '<input type="text" name="fb_map_key[]" placeholder="inbound key (e.g. register)" style="flex:1;min-width:140px;padding:8px;border:2px solid #ddd;border-radius:4px;font-family:monospace;">'
                        + '<span style="color:#888;">→</span>'
                        + '<select name="fb_map_event[]" onchange="toggleMapCustom(this)" style="flex:1;min-width:160px;padding:8px;border:2px solid #ddd;border-radius:4px;">' + opts + '</select>'
                        + '<input type="text" name="fb_map_custom_event[]" placeholder="Custom event name" class="fb-map-custom-input" style="flex:1;min-width:140px;padding:8px;border:2px solid #ddd;border-radius:4px;font-family:monospace;display:none;">'
                        + '<button type="button" onclick="removeFbMapRow(this)" title="Remove mapping" aria-label="Remove mapping" style="padding:8px 12px;border:1px solid #ccc;border-radius:4px;background:#f5f5f5;color:#666;cursor:pointer;white-space:nowrap;">Remove</button>';
                    wrap.appendChild(row);
                }

                </script>
            </div>
        </div>
    <?php endif; ?>

    <!-- Google Ads Conversions Integrations (scheduled CSV import) -->
    <div class="card" style="margin-top: 24px;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <h2 class="card-title">Google Ads Conversions Integrations</h2>
            <a href="?page=settings&tab=integrations&edit_ga_integration=0" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px; color: #fff;">
                <img src="<?= ASSETS_BASE_URL ?>/assets/images/tgoogle.png" alt="" style="width: 24px; height: 24px;">
                + Add Integration
            </a>
        </div>
        <div class="card-body">
            <div style="background: #e8f5e9; border-left: 4px solid #4caf50; padding: 12px; border-radius: 4px; margin-bottom: 16px;">
                <p style="margin: 0; font-size: 13px; color: #2e7d32; line-height: 1.6;">
                    <strong>Recommended:</strong> use <strong>CSV / Data Manager</strong> so Google pulls conversions from your endpoint on a schedule.
                    Capture <code style="background: rgba(46,125,50,0.12); padding: 1px 5px; border-radius: 3px; font-size: 12px; white-space: nowrap;">gclid</code>,
                    <code style="background: rgba(46,125,50,0.12); padding: 1px 5px; border-radius: 3px; font-size: 12px; white-space: nowrap;">wbraid</code>, and
                    <code style="background: rgba(46,125,50,0.12); padding: 1px 5px; border-radius: 3px; font-size: 12px; white-space: nowrap;">gbraid</code>
                    on Google/YouTube campaigns. For braids, set the Google conversion action count to <strong>Every</strong>.
                    Hourly <strong>cost sync</strong> is configured under
                    <a href="?page=settings&tab=api-costs" style="color: #1b5e20; font-weight: 600;">API Cost Updates</a>
                    (same integration credentials). API conversion push is optional for advanced setups.
                </p>
            </div>
            <?php if (isset($errors['ga_integration']) && is_array($errors['ga_integration'])): ?>
                <div style="background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 12px; border-radius: 4px; margin-bottom: 20px;">
                    <strong>Errors:</strong>
                    <ul style="margin: 8px 0 0 20px;">
                        <?php foreach ($errors['ga_integration'] as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <?php if (empty($allGoogleAdsIntegrations)): ?>
                <div style="text-align: center; padding: 40px; color: #999;">
                    <p>No Google Ads integrations configured yet.</p>
                    <a href="?page=settings&tab=integrations&edit_ga_integration=0" class="btn btn-primary" style="margin-top: 16px; display: inline-flex; align-items: center; gap: 6px; color: #fff;">
                        <img src="<?= ASSETS_BASE_URL ?>/assets/images/tgoogle.png" alt="" style="width: 24px; height: 24px;">
                        Create Your First Integration
                    </a>
                </div>
            <?php else: ?>
                <?php 
                // Data Manager requires path ending in .csv (rewrites to google-conversions.php)
                $apiBaseUrl = APP_BASE_URL . '/api/google-conversions.csv';
                ?>
                <!-- Google Ads Table (Desktop) -->
                <div class="table-wrapper desktop-only" style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f5f5f5; border-bottom: 2px solid #ddd;">
                                <th style="padding: 12px; text-align: left; font-weight: 600;">Name</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600;">Delivery</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600;">Endpoint URL</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600;">Campaigns</th>
                                <th style="padding: 12px; text-align: right; font-weight: 600;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allGoogleAdsIntegrations as $integration): 
                                // Use conversion_key from getAll() (already included)
                                $integrationKey = $integration['conversion_key'] ?? '';
                                $endpointUrl = $apiBaseUrl . '?key=' . urlencode($integrationKey) . '&camp=google';
                                $deliveryMode = $integration['delivery_mode'] ?? 'csv';
                            ?>
                                <tr style="border-bottom: 1px solid #eee;">
                                    <td style="padding: 12px; font-weight: 600;"><?= htmlspecialchars($integration['name']) ?></td>
                                    <td style="padding: 12px; text-transform: uppercase; font-size: 12px;"><?= htmlspecialchars($deliveryMode) ?></td>
                                    <td style="padding: 12px;">
                                        <?php if ($deliveryMode === 'api'): ?>
                                            <span style="font-size: 12px; color: #666;">API push (no CSV pull required)</span>
                                        <?php else: ?>
                                        <div style="background: #fff; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-family: monospace; font-size: 11px; word-break: break-all; max-width: 500px;">
                                            <?= htmlspecialchars($endpointUrl) ?>
                                        </div>
                                        <button onclick="copyToClipboard('<?= htmlspecialchars($endpointUrl) ?>', this)" 
                                                style="margin-top: 4px; padding: 4px 12px; background: #4285f4; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 11px;">
                                            Copy
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 12px;"><?= (int)($integration['campaign_count'] ?? 0) ?></td>
                                    <td style="padding: 12px; text-align: right;">
                                        <a href="?page=settings&tab=integrations&edit_ga_integration=<?= $integration['id'] ?>" 
                                           style="width: 36px; height: 36px; padding: 0; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s; color: #666; text-decoration: none; margin-right: 6px;"
                                           title="Edit Integration"
                                           onmouseover="this.style.background='#e3f2fd'; this.style.borderColor='#2196F3'; this.style.color='#2196F3';"
                                           onmouseout="this.style.background='#fff'; this.style.borderColor='#ddd'; this.style.color='#666';">
                                            ✏️
                                        </a>
                                        <form method="post" style="display: inline; margin: 0;" 
                                              onsubmit="return confirm('Are you sure you want to delete this integration? Campaigns using it will have their integration removed.');">
                                            <input type="hidden" name="action" value="delete_ga_integration">
                                            <input type="hidden" name="ga_integration_id" value="<?= $integration['id'] ?>">
                                            <button type="submit" 
                                                    style="width: 36px; height: 36px; padding: 0; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s; color: #666;"
                                                    title="Delete Integration"
                                                    onmouseover="this.style.background='#ffebee'; this.style.borderColor='#f44336'; this.style.color='#f44336';"
                                                    onmouseout="this.style.background='#fff'; this.style.borderColor='#ddd'; this.style.color='#666';">
                                                🗑️
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Mobile Google Ads Cards -->
                <div class="mobile-google-ads-cards mobile-only">
                    <?php foreach ($allGoogleAdsIntegrations as $integration): 
                        $integrationKey = $integration['conversion_key'] ?? '';
                        $endpointUrl = $apiBaseUrl . '?key=' . urlencode($integrationKey) . '&camp=google';
                    ?>
                        <div class="mobile-google-ads-card" style="background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 16px; margin-bottom: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                            <!-- Header: Name -->
                            <div style="margin-bottom: var(--spacing-sm); border-bottom: 1px solid rgba(0,0,0,0.1); padding-bottom: var(--spacing-xs);">
                                <div style="font-weight: 600; font-size: 16px; color: #3d5a26;">
                                    <?= htmlspecialchars($integration['name']) ?>
                                </div>
                            </div>
                            
                            <!-- Endpoint URL -->
                            <div style="margin-bottom: var(--spacing-sm);">
                                <div style="font-size: 11px; color: #666; margin-bottom: 4px;"><strong>Endpoint URL</strong></div>
                                <div style="background: #fff; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-family: monospace; font-size: 11px; word-break: break-all; margin-bottom: 8px;">
                                    <?= htmlspecialchars($endpointUrl) ?>
                                </div>
                                <button onclick="copyToClipboard('<?= htmlspecialchars($endpointUrl) ?>', this)" 
                                        style="width: 100%; padding: 6px 12px; background: #4285f4; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">
                                    📋 Copy URL
                                </button>
                            </div>
                            
                            <!-- Campaigns -->
                            <div style="margin-bottom: var(--spacing-sm);">
                                <div style="font-size: 11px; color: #666; margin-bottom: 4px;"><strong>Campaigns</strong></div>
                                <div style="font-size: 14px; color: #333;">
                                    <?= (int)($integration['campaign_count'] ?? 0) ?>
                                </div>
                            </div>
                            
                            <!-- Actions -->
                            <div style="display: flex; gap: 8px; border-top: 1px solid rgba(0,0,0,0.1); padding-top: var(--spacing-sm);">
                                <a href="?page=settings&tab=integrations&edit_ga_integration=<?= $integration['id'] ?>" 
                                   style="flex: 1; padding: 8px 12px; font-size: 12px; border: 1px solid #ddd; border-radius: 4px; background: #fff; cursor: pointer; text-decoration: none; color: #666; text-align: center; display: inline-block;">
                                    ✏️ Edit
                                </a>
                                <form method="post" 
                                      style="flex: 1; margin: 0;" 
                                      onsubmit="return confirm('Are you sure you want to delete this integration? Campaigns using it will have their integration removed.');">
                                    <input type="hidden" name="action" value="delete_ga_integration">
                                    <input type="hidden" name="ga_integration_id" value="<?= $integration['id'] ?>">
                                    <button type="submit" 
                                            style="width: 100%; padding: 8px 12px; font-size: 12px; border: 1px solid #f44336; border-radius: 4px; background: #f44336; cursor: pointer; color: white;">
                                        🗑️ Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add/Edit Google Ads Integration Form -->
    <?php if ($editingGoogleAdsIntegration !== null || isset($_GET['edit_ga_integration'])): ?>
        <div class="card" style="margin-top: 24px;">
            <div class="card-header">
                <h2 class="card-title"><?= $editingGoogleAdsIntegration ? 'Edit' : 'Add' ?> Google Ads Integration</h2>
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="<?= $editingGoogleAdsIntegration ? 'update_ga_integration' : 'create_ga_integration' ?>">
                    <?php if ($editingGoogleAdsIntegration): ?>
                        <input type="hidden" name="ga_integration_id" value="<?= $editingGoogleAdsIntegration['id'] ?>">
                    <?php endif; ?>
                    
                    <div style="max-width: 600px;">
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Integration Name <span style="color: #d32f2f;">*</span></label>
                            <input type="text" name="ga_integration_name" 
                                   value="<?= htmlspecialchars($editingGoogleAdsIntegration['name'] ?? '') ?>"
                                   placeholder="e.g., Main Google Account, Secondary Account"
                                   required
                                   style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                            <div style="font-size: 12px; color: #666; margin-top: 4px;">A friendly name to identify this integration</div>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Conversion Key <span style="color: #d32f2f;">*</span></label>
                            <input type="text" name="ga_conversion_key" 
                                   value="<?= htmlspecialchars($editingGoogleAdsIntegration['conversion_key'] ?? '') ?>"
                                   placeholder="Enter a random 10-20 character string (e.g., kU9mX2pQ7wR5t)"
                                   required
                                   minlength="10"
                                   maxlength="100"
                                   style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-family: monospace;">
                            <div style="font-size: 12px; color: #666; margin-top: 4px;">
                                This key secures your Google Conversions CSV endpoint. Choose a random string (10-100 characters).
                            </div>
                        </div>

                        <?php
                        $gaDeliveryMode = $editingGoogleAdsIntegration['delivery_mode'] ?? 'csv';
                        ?>
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Conversion delivery <span style="color: #d32f2f;">*</span></label>
                            <select name="ga_delivery_mode" id="ga_delivery_mode" required
                                    style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;"
                                    onchange="toggleGaApiFields()">
                                <option value="csv" <?= $gaDeliveryMode === 'csv' ? 'selected' : '' ?>>CSV / Data Manager (Google pulls endpoint)</option>
                                <option value="api" <?= $gaDeliveryMode === 'api' ? 'selected' : '' ?>>Google Ads API push</option>
                                <option value="both" <?= $gaDeliveryMode === 'both' ? 'selected' : '' ?>>Both CSV and API</option>
                            </select>
                            <div style="font-size: 12px; color: #666; margin-top: 4px;">
                                Prefer <strong>CSV / Data Manager</strong> for most setups. API push submits sooner but Google still needs ~6 hours after the click before matching; braids can take up to ~72h in reporting.
                                Cost sync OAuth can also be managed under <a href="?page=settings&tab=api-costs">API Cost Updates</a>.
                            </div>
                        </div>

                        <div id="ga_api_fields" style="margin-bottom: 20px; padding: 16px; background: #f5f5f5; border-radius: 8px; <?= in_array($gaDeliveryMode, ['api', 'both'], true) ? '' : 'display:none;' ?>">
                            <h3 style="margin: 0 0 12px 0; font-size: 15px;">API credentials (required for API / Both)</h3>
                            <div style="margin-bottom: 12px;">
                                <label style="display: block; font-weight: 600; margin-bottom: 6px;">Conversion action ID</label>
                                <input type="text" name="ga_conversion_action_id"
                                       value="<?= htmlspecialchars($editingGoogleAdsIntegration['conversion_action_id'] ?? '') ?>"
                                       placeholder="Numeric ID from Google Ads conversion action"
                                       style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-family: monospace;">
                            </div>
                            <div style="margin-bottom: 12px;">
                                <label style="display: block; font-weight: 600; margin-bottom: 6px;">Customer ID</label>
                                <input type="text" name="ga_customer_id"
                                       value="<?= htmlspecialchars($editingGoogleAdsIntegration['customer_id'] ?? '') ?>"
                                       placeholder="1234567890 (no dashes)"
                                       style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-family: monospace;">
                            </div>
                            <div style="margin-bottom: 12px;">
                                <label style="display: block; font-weight: 600; margin-bottom: 6px;">Developer token</label>
                                <input type="password" name="ga_developer_token" autocomplete="off"
                                       value=""
                                       placeholder="<?= !empty($editingGoogleAdsIntegration['developer_token']) ? 'Leave blank to keep existing' : 'Paste developer token' ?>"
                                       style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-family: monospace;">
                            </div>
                            <div style="margin-bottom: 12px;">
                                <label style="display: block; font-weight: 600; margin-bottom: 6px;">OAuth Client ID</label>
                                <input type="text" name="ga_oauth_client_id"
                                       value="<?= htmlspecialchars($editingGoogleAdsIntegration['oauth_client_id'] ?? '') ?>"
                                       style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-family: monospace;">
                            </div>
                            <div style="margin-bottom: 12px;">
                                <label style="display: block; font-weight: 600; margin-bottom: 6px;">OAuth Client Secret</label>
                                <input type="password" name="ga_oauth_client_secret" autocomplete="new-password"
                                       value=""
                                       placeholder="<?= !empty($editingGoogleAdsIntegration['oauth_client_secret']) ? 'Leave blank to keep existing' : 'Paste client secret' ?>"
                                       style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-family: monospace;">
                            </div>
                            <div style="margin-bottom: 12px;">
                                <label style="display: block; font-weight: 600; margin-bottom: 6px;">OAuth Refresh Token</label>
                                <input type="password" name="ga_oauth_refresh_token" autocomplete="off"
                                       value=""
                                       placeholder="<?= !empty($editingGoogleAdsIntegration['oauth_refresh_token']) ? 'Leave blank to keep existing' : 'Paste refresh token' ?>"
                                       style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-family: monospace;">
                            </div>
                            <div style="margin-bottom: 0;">
                                <label style="display: block; font-weight: 600; margin-bottom: 6px;">Login customer ID (MCC, optional)</label>
                                <input type="text" name="ga_login_customer_id"
                                       value="<?= htmlspecialchars($editingGoogleAdsIntegration['login_customer_id'] ?? '') ?>"
                                       style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-family: monospace;">
                            </div>
                        </div>

                        <script>
                        function toggleGaApiFields() {
                            var mode = document.getElementById('ga_delivery_mode').value;
                            var box = document.getElementById('ga_api_fields');
                            if (box) {
                                box.style.display = (mode === 'api' || mode === 'both') ? '' : 'none';
                            }
                        }
                        </script>

                        <?php if ($editingGoogleAdsIntegration && !empty($editingGoogleAdsIntegration['conversion_key'])): ?>
                        <div style="margin-bottom: 24px; padding: 16px; background: #e8f5e9; border: 2px solid #4caf50; border-radius: 8px;">
                            <h3 style="margin: 0 0 12px 0; color: #2e7d32; font-size: 16px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                                <span>🔗</span>
                                Google Ads Conversion Script URL
                            </h3>
                            <p style="margin: 0 0 12px 0; color: #333; font-size: 14px; line-height: 1.5;">
                                Copy this URL and paste it into Google Ads <strong>Data Manager → Connect to HTTPS</strong>. The path ends in <code>.csv</code> (required by Google); auth uses the <code>key</code> query param.
                            </p>
                            <div style="background: #fff; padding: 12px; border: 1px solid #4caf50; border-radius: 4px; margin-bottom: 12px;">
                                <code id="ga-conversion-url" style="font-size: 12px; color: #2e7d32; word-break: break-all; font-family: 'Courier New', monospace; display: block;">
                                    <?= htmlspecialchars(APP_BASE_URL . '/api/google-conversions.csv?key=' . urlencode($editingGoogleAdsIntegration['conversion_key']) . '&camp=google') ?>
                                </code>
                                <button type="button" onclick="copyToClipboard('<?= htmlspecialchars(APP_BASE_URL . '/api/google-conversions.csv?key=' . urlencode($editingGoogleAdsIntegration['conversion_key']) . '&camp=google') ?>', this)" 
                                        style="margin-top: 8px; padding: 6px 16px; background: #4caf50; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; font-weight: 600;">
                                    📋 Copy Data Manager URL
                                </button>
                                <div style="margin-top: 10px; font-size: 12px; color: #555;">
                                    Legacy gclid-only schedule URL (add <code>&amp;format=legacy</code>):
                                    <code style="display:block; word-break:break-all; margin-top:4px;">
                                        <?= htmlspecialchars(APP_BASE_URL . '/api/google-conversions.csv?key=' . urlencode($editingGoogleAdsIntegration['conversion_key']) . '&camp=google&format=legacy') ?>
                                    </code>
                                </div>
                            </div>
                            <div style="font-size: 12px; color: #666; padding: 8px; background: rgba(255,255,255,0.7); border-radius: 4px;">
                                <strong>Setup Instructions:</strong><br>
                                1. In Google Ads Data Manager, choose <strong>Connect to HTTPS</strong><br>
                                2. Paste the URL above (must end with <code>.csv</code> before <code>?</code>)<br>
                                3. Enter any Username and Password (Google requires both fields; Kuma auth is the <code>key</code> in the URL)<br>
                                4. Continue to map fields and save the schedule<br>
                                5. Set import frequency to Daily or Hourly
                            </div>
                        </div>
                        <?php endif; ?>

                        <div style="display: flex; gap: 12px;">
                            <button type="submit" class="btn btn-primary"><?= $editingGoogleAdsIntegration ? 'Update' : 'Create' ?> Integration</button>
                            <a href="?page=settings&tab=integrations" class="btn btn-secondary">Cancel</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <script>
    function copyToClipboard(text, button) {
        navigator.clipboard.writeText(text).then(function() {
            // Store original button text
            const originalText = button.textContent;
            const originalBackground = button.style.background;
            
            // Update button to show success
            button.textContent = '✓ COPIED!';
            button.style.background = '#4caf50';
            button.disabled = true;
            
            // Revert after 2 seconds
            setTimeout(function() {
                button.textContent = originalText;
                button.style.background = originalBackground;
                button.disabled = false;
            }, 2000);
        }).catch(function(err) {
            alert('Failed to copy: ' + err);
        });
    }
    </script>

    <!-- Custom Postbacks Section -->
    <div class="card" style="margin-top: 24px;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <h2 class="card-title">Custom Postbacks</h2>
            <a href="?page=settings&tab=integrations&edit_custom_postback=0" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px; color: #fff;">
                <img src="<?= ASSETS_BASE_URL ?>/assets/images/postbacks.png" alt="" style="width: 24px; height: 24px;">
                + Add Postback
            </a>
        </div>
        <div class="card-body">
            <?php if (isset($errors['custom_postback']) && is_array($errors['custom_postback'])): ?>
                <div style="background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 12px; border-radius: 4px; margin-bottom: 20px;">
                    <strong>Errors:</strong>
                    <ul style="margin: 8px 0 0 20px;">
                        <?php foreach ($errors['custom_postback'] as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['edit_custom_postback'])): ?>
                <!-- Add/Edit Custom Postback Form -->
                <div style="max-width: 800px;">
                    <h3 style="margin-top: 0;"><?= $editingCustomPostback ? 'Edit Custom Postback' : 'Add Custom Postback' ?></h3>
                    
                    <form method="post">
                        <input type="hidden" name="action" value="<?= $editingCustomPostback ? 'update_custom_postback' : 'create_custom_postback' ?>">
                        <?php if ($editingCustomPostback): ?>
                            <input type="hidden" name="custom_postback_id" value="<?= $editingCustomPostback['id'] ?>">
                        <?php endif; ?>
                        
                        <div style="margin-bottom: 24px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">
                                Postback Name <span style="color: #d32f2f;">*</span>
                            </label>
                            <input type="text" name="custom_postback_name" 
                                   value="<?= htmlspecialchars($editingCustomPostback['name'] ?? '') ?>"
                                   placeholder="e.g., Network Alpha Postback"
                                   required
                                   style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                            <div style="font-size: 12px; color: #666; margin-top: 4px;">
                                A descriptive name to identify this postback configuration.
                            </div>
                        </div>

                        <div style="margin-bottom: 24px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">
                                Postback URL <span style="color: #d32f2f;">*</span>
                            </label>
                            <textarea name="custom_postback_url" 
                                      id="custom_postback_url_textarea"
                                      rows="3"
                                      placeholder="https://example.com/postback?click_id={click_id}&campaign={campaign_id}&value={value}"
                                      required
                                      style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-family: monospace; font-size: 13px;"><?= htmlspecialchars($editingCustomPostback['postback_url'] ?? '') ?></textarea>
                            <div style="font-size: 12px; color: #666; margin-top: 8px; margin-bottom: 12px;">
                                Click any token below to insert it at your cursor position:
                            </div>
                            
                            <!-- Campaign Selector for Custom Token Context -->
                            <div style="margin-bottom: 12px; padding: 8px; background: #e8f5e9; border-radius: 4px; border: 1px solid #c8e6c9;">
                                <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 12px; color: #2e7d32;">
                                    Select Campaign for Custom Token Labels (Optional):
                                </label>
                                <select id="campaign_selector_for_tokens" 
                                        onchange="updateCustomTokenLabels()"
                                        style="width: 100%; padding: 6px; border: 1px solid #66bb6a; border-radius: 3px; font-size: 12px;">
                                    <option value="">-- None selected (show generic token1-token20) --</option>
                                    <?php
                                    foreach ($allCampaignsForTokens as $camp): 
                                        // custom_tokens_json is already decoded by Campaign::getAll()
                                        $customTokens = is_array($camp['custom_tokens_json'] ?? null) 
                                            ? $camp['custom_tokens_json'] 
                                            : (json_decode($camp['custom_tokens_json'] ?? '[]', true) ?: []);
                                        // Include campaign even if it has no custom tokens, so user can access traffic source tokens
                                        $trafficSourceId = $camp['traffic_source_id'] ?? null;
                                    ?>
                                        <option value="<?= $camp['id'] ?>" 
                                                data-tokens="<?= htmlspecialchars(json_encode($customTokens, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>"
                                                data-traffic-source-id="<?= $trafficSourceId ?>">
                                            <?= htmlspecialchars($camp['name']) ?>
                                        </option>
                                    <?php 
                                    endforeach; 
                                    ?>
                                </select>
                                <div style="font-size: 11px; color: #558b2f; margin-top: 4px;">
                                    Select a campaign to see campaign and traffic source custom tokens (token1-token20) with their labels.
                                </div>
                            </div>
                            
                            <!-- Store traffic source tokens data for JavaScript -->
                            <script>
                            const trafficSourceTokens = {
                                <?php
                                foreach ($allTrafficSourcesForTokens as $ts):
                                    $tsTokens = is_array($ts['tokens_json'] ?? null) 
                                        ? $ts['tokens_json'] 
                                        : (json_decode($ts['tokens_json'] ?? '[]', true) ?: []);
                                    if (!empty($tsTokens)):
                                ?>
                                <?= $ts['id'] ?>: <?= json_encode($tsTokens, JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                                <?php
                                    endif;
                                endforeach;
                                ?>
                            };
                            const trafficSourceNames = {
                                <?php
                                foreach ($allTrafficSourcesForTokens as $ts):
                                ?>
                                <?= $ts['id'] ?>: <?= json_encode($ts['name'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                                <?php
                                endforeach;
                                ?>
                            };
                            </script>
                            
                            <div style="background: #f5f5f5; padding: 12px; border-radius: 4px; border: 1px solid #ddd;">
                                <?php
                                // TokenReplacer doesn't need database, safe to instantiate here
                                try {
                                    $tokenReplacer = new \SimpleKuma\Tracking\TokenReplacer();
                                    $availableTokens = $tokenReplacer->getAvailableTokens();
                                } catch (Exception $e) {
                                    error_log('Error loading TokenReplacer: ' . $e->getMessage());
                                    $availableTokens = ['Built-in Tokens' => [], 'Custom Tokens' => []];
                                }
                                ?>
                                
                                <!-- Built-in Tokens -->
                                <?php foreach ($availableTokens as $category => $tokens): ?>
                                    <?php if ($category === 'Built-in Tokens'): ?>
                                        <div style="margin-bottom: 12px;">
                                            <strong style="color: #3d5a26; font-size: 12px; display: block; margin-bottom: 6px;"><?= htmlspecialchars($category) ?>:</strong>
                                            <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                                                <?php foreach ($tokens as $token => $description): ?>
                                                    <button type="button" 
                                                            onclick="insertTokenAtCursor('<?= htmlspecialchars($token) ?>')"
                                                            style="padding: 4px 10px; background: #fff; border: 1px solid #3d5a26; border-radius: 3px; cursor: pointer; font-size: 11px; font-family: monospace; color: #3d5a26; transition: all 0.2s;"
                                                            onmouseover="this.style.background='#3d5a26'; this.style.color='#fff';"
                                                            onmouseout="this.style.background='#fff'; this.style.color='#3d5a26';"
                                                            title="<?= htmlspecialchars($description) ?>">
                                                                <?= htmlspecialchars($token) ?>
                                                            </button>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                
                                <!-- Custom Tokens (will be updated by JavaScript) -->
                                <div id="custom_tokens_display" style="margin-bottom: 0;">
                                    <div id="custom_tokens_header" style="margin-bottom: 6px;">
                                        <strong style="color: #3d5a26; font-size: 12px; display: block; margin-bottom: 6px;">Custom Tokens:</strong>
                                    </div>
                                    <!-- Campaign Custom Tokens -->
                                    <div id="campaign_tokens_section" style="margin-bottom: 8px; display: none;">
                                        <strong style="color: #1976d2; font-size: 11px; display: block; margin-bottom: 4px;">Campaign Tokens:</strong>
                                        <div id="campaign_tokens_buttons" style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px;"></div>
                                    </div>
                                    <!-- Traffic Source Custom Tokens -->
                                    <div id="traffic_source_tokens_section" style="margin-bottom: 8px; display: none;">
                                        <strong style="color: #7b1fa2; font-size: 11px; display: block; margin-bottom: 4px;">Traffic Source Tokens:</strong>
                                        <div id="traffic_source_tokens_buttons" style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px;"></div>
                                    </div>
                                    <!-- Generic fallback -->
                                    <div id="custom_tokens_buttons" style="display: flex; flex-wrap: wrap; gap: 6px;">
                                        <!-- Generic tokens (default) -->
                                        <?php
                                        $customTokensDefault = $availableTokens['Custom Tokens'] ?? [];
                                        foreach ($customTokensDefault as $token => $description): 
                                        ?>
                                            <button type="button" 
                                                    onclick="insertTokenAtCursor('<?= htmlspecialchars($token) ?>')"
                                                    class="custom-token-btn"
                                                    data-token="<?= htmlspecialchars($token) ?>"
                                                    style="padding: 4px 10px; background: #fff; border: 1px solid #3d5a26; border-radius: 3px; cursor: pointer; font-size: 11px; font-family: monospace; color: #3d5a26; transition: all 0.2s;"
                                                    onmouseover="this.style.background='#3d5a26'; this.style.color='#fff';"
                                                    onmouseout="this.style.background='#fff'; this.style.color='#3d5a26';"
                                                    title="<?= htmlspecialchars($description) ?>">
                                                <?= htmlspecialchars($token) ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div style="margin-bottom: 24px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">
                                Description (Optional)
                            </label>
                            <textarea name="custom_postback_description" 
                                      rows="2"
                                      placeholder="Optional description of what this postback is used for..."
                                      style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;"><?= htmlspecialchars($editingCustomPostback['description'] ?? '') ?></textarea>
                        </div>

                        <div style="display: flex; gap: 12px;">
                            <button type="submit" class="btn btn-primary"><?= $editingCustomPostback ? 'Update Postback' : 'Create Postback' ?></button>
                            <a href="?page=settings&tab=integrations" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>

                    <script>
                    function insertTokenAtCursor(token) {
                        const textarea = document.getElementById('custom_postback_url_textarea');
                        if (!textarea) return;
                        
                        // Get current cursor position
                        const cursorPos = textarea.selectionStart;
                        const textBefore = textarea.value.substring(0, cursorPos);
                        const textAfter = textarea.value.substring(cursorPos);
                        
                        // Insert token at cursor position
                        textarea.value = textBefore + token + textAfter;
                        
                        // Restore focus and set cursor position after inserted token
                        textarea.focus();
                        const newCursorPos = cursorPos + token.length;
                        textarea.setSelectionRange(newCursorPos, newCursorPos);
                    }
                    
                    function updateCustomTokenLabels() {
                        const selector = document.getElementById('campaign_selector_for_tokens');
                        const selectedOption = selector.options[selector.selectedIndex];
                        
                        const campaignTokensContainer = document.getElementById('campaign_tokens_buttons');
                        const trafficSourceTokensContainer = document.getElementById('traffic_source_tokens_buttons');
                        const genericTokensContainer = document.getElementById('custom_tokens_buttons');
                        
                        const campaignSection = document.getElementById('campaign_tokens_section');
                        const trafficSourceSection = document.getElementById('traffic_source_tokens_section');
                        
                        // Hide specific sections, show generic
                        campaignSection.style.display = 'none';
                        trafficSourceSection.style.display = 'none';
                        genericTokensContainer.style.display = 'flex';
                        
                        if (!selectedOption || !selectedOption.value) {
                            // Reset to generic tokens
                            resetToGenericTokens();
                            return;
                        }
                        
                        // Clear containers
                        campaignTokensContainer.innerHTML = '';
                        trafficSourceTokensContainer.innerHTML = '';
                        
                        let hasCampaignTokens = false;
                        let hasTrafficSourceTokens = false;
                        
                        // Process Campaign Tokens
                        try {
                            if (selectedOption.dataset.tokens) {
                                const customTokens = JSON.parse(selectedOption.dataset.tokens);
                                if (Array.isArray(customTokens) && customTokens.length > 0) {
                                    hasCampaignTokens = true;
                                    customTokens.forEach((token, index) => {
                                        const tokenNum = index + 1;
                                        const tokenKey = `{token${tokenNum}}`;
                                        const displayText = token.name ? `${token.name} (token${tokenNum})` : tokenKey;
                                        const tooltipText = token.description || token.name || `Campaign token ${tokenNum}`;
                                        
                                        const button = createTokenButton(tokenKey, displayText, tooltipText, '#1976d2');
                                        campaignTokensContainer.appendChild(button);
                                    });
                                }
                            }
                        } catch (e) {
                            console.error('Error parsing campaign tokens:', e);
                        }
                        
                                // Process Traffic Source Tokens
                        try {
                            const trafficSourceId = selectedOption.dataset.trafficSourceId;
                            if (trafficSourceId && trafficSourceTokens[trafficSourceId]) {
                                const tsTokens = trafficSourceTokens[trafficSourceId];
                                if (Array.isArray(tsTokens) && tsTokens.length > 0) {
                                    hasTrafficSourceTokens = true;
                                    // Get traffic source name for unique token format
                                    const trafficSourceName = trafficSourceNames[trafficSourceId] || 'Unknown';
                                    const tsNameSanitized = trafficSourceName.replace(/[^a-zA-Z0-9]/g, '');
                                    
                                    tsTokens.forEach((token, index) => {
                                        const tokenNum = index + 1;
                                        const paramName = token.parameter || `token${tokenNum}`;
                                        
                                        // Use unique format: {ts:TrafficSourceName:parameter}
                                        // This ensures 100% uniqueness across all traffic sources
                                        const tokenKey = `{ts:${tsNameSanitized}:${paramName}}`;
                                        const displayText = token.name 
                                            ? `${token.name} (${paramName})` 
                                            : `${paramName}`;
                                        const tooltipText = token.placeholder 
                                            ? `${token.name || 'Token'} - Parameter: ${paramName}, Placeholder: ${token.placeholder}` 
                                            : `${token.name || 'Token'} - Parameter: ${paramName}`;
                                        
                                        const button = createTokenButton(tokenKey, displayText, tooltipText, '#7b1fa2');
                                        trafficSourceTokensContainer.appendChild(button);
                                    });
                                }
                            }
                        } catch (e) {
                            console.error('Error parsing traffic source tokens:', e);
                        }
                        
                        // Show/hide sections and generic tokens
                        if (hasCampaignTokens || hasTrafficSourceTokens) {
                            genericTokensContainer.style.display = 'none';
                            if (hasCampaignTokens) {
                                campaignSection.style.display = 'block';
                            }
                            if (hasTrafficSourceTokens) {
                                trafficSourceSection.style.display = 'block';
                            }
                        } else {
                            genericTokensContainer.style.display = 'flex';
                            resetToGenericTokens();
                        }
                    }
                    
                    function createTokenButton(tokenKey, displayText, tooltipText, borderColor) {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'custom-token-btn';
                        button.setAttribute('data-token', tokenKey);
                        button.onclick = function() { insertTokenAtCursor(tokenKey); };
                        button.style.cssText = `padding: 4px 10px; background: #fff; border: 1px solid ${borderColor}; border-radius: 3px; cursor: pointer; font-size: 11px; font-family: monospace; color: ${borderColor}; transition: all 0.2s;`;
                        button.onmouseover = function() { 
                            this.style.background = borderColor; 
                            this.style.color = '#fff'; 
                        };
                        button.onmouseout = function() { 
                            this.style.background = '#fff'; 
                            this.style.color = borderColor; 
                        };
                        button.title = tooltipText;
                        button.textContent = displayText;
                        return button;
                    }
                    
                    function resetToGenericTokens() {
                        const tokensContainer = document.getElementById('custom_tokens_buttons');
                        tokensContainer.innerHTML = '';
                        
                        for (let i = 1; i <= 20; i++) {
                            const tokenKey = `{token${i}}`;
                            const button = document.createElement('button');
                            button.type = 'button';
                            button.className = 'custom-token-btn';
                            button.setAttribute('data-token', tokenKey);
                            button.onclick = function() { insertTokenAtCursor(tokenKey); };
                            button.style.cssText = 'padding: 4px 10px; background: #fff; border: 1px solid #3d5a26; border-radius: 3px; cursor: pointer; font-size: 11px; font-family: monospace; color: #3d5a26; transition: all 0.2s;';
                            button.onmouseover = function() { this.style.background='#3d5a26'; this.style.color='#fff'; };
                            button.onmouseout = function() { this.style.background='#fff'; this.style.color='#3d5a26'; };
                            button.title = `Custom campaign token ${i}`;
                            button.textContent = tokenKey;
                            
                            tokensContainer.appendChild(button);
                        }
                    }
                    </script>
                </div>
            <?php else: ?>
                <!-- Custom Postbacks List -->
                <?php if (empty($allCustomPostbacks)): ?>
                    <div style="text-align: center; padding: 40px; color: #999;">
                        <p>No custom postbacks configured yet.</p>
                        <a href="?page=settings&tab=integrations&edit_custom_postback=0" class="btn btn-primary" style="margin-top: 16px; display: inline-flex; align-items: center; gap: 6px; color: #fff;">
                            <img src="<?= ASSETS_BASE_URL ?>/assets/images/postbacks.png" alt="" style="width: 24px; height: 24px;">
                            Create Your First Postback
                        </a>
                    </div>
                <?php else: ?>
                    <!-- Custom Postbacks Table (Desktop) -->
                    <div class="table-wrapper desktop-only" style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: #f5f5f5; border-bottom: 2px solid #ddd;">
                                    <th style="padding: 12px; text-align: left; font-weight: 600;">Name</th>
                                    <th style="padding: 12px; text-align: left; font-weight: 600;">Postback URL</th>
                                    <th style="padding: 12px; text-align: left; font-weight: 600;">Campaigns</th>
                                    <th style="padding: 12px; text-align: right; font-weight: 600;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allCustomPostbacks as $postback): ?>
                                    <tr style="border-bottom: 1px solid #eee;">
                                        <td style="padding: 12px; font-weight: 600;"><?= htmlspecialchars($postback['name']) ?></td>
                                        <td style="padding: 12px;">
                                            <div style="background: #fff; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-family: monospace; font-size: 11px; word-break: break-all; max-width: 500px;">
                                                <?= htmlspecialchars($postback['postback_url']) ?>
                                            </div>
                                            <?php if (!empty($postback['description'])): ?>
                                                <div style="font-size: 11px; color: #666; margin-top: 4px;">
                                                    <?= htmlspecialchars($postback['description']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 12px;"><?= (int)($postback['campaign_count'] ?? 0) ?></td>
                                        <td style="padding: 12px; text-align: right;">
                                            <a href="?page=settings&tab=integrations&edit_custom_postback=<?= $postback['id'] ?>"
                                               style="width: 36px; height: 36px; padding: 0; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s; color: #666; text-decoration: none; margin-right: 6px;"
                                               title="Edit Postback"
                                               onmouseover="this.style.background='#e3f2fd'; this.style.borderColor='#2196F3'; this.style.color='#2196F3';"
                                               onmouseout="this.style.background='#fff'; this.style.borderColor='#ddd'; this.style.color='#666';">
                                                ✏️
                                            </a>
                                            <form method="post" style="display: inline; margin: 0;"
                                                  onsubmit="return confirm('Are you sure you want to delete this postback? Campaigns using it will have their postback removed.');">
                                                <input type="hidden" name="action" value="delete_custom_postback">
                                                <input type="hidden" name="custom_postback_id" value="<?= $postback['id'] ?>">
                                                <button type="submit" 
                                                        style="width: 36px; height: 36px; padding: 0; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s; color: #666;"
                                                        title="Delete Postback"
                                                        onmouseover="this.style.background='#ffebee'; this.style.borderColor='#f44336'; this.style.color='#f44336';"
                                                        onmouseout="this.style.background='#fff'; this.style.borderColor='#ddd'; this.style.color='#666';">
                                                    🗑️
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Mobile Custom Postbacks Cards -->
                    <div class="mobile-custom-postback-cards mobile-only">
                        <?php foreach ($allCustomPostbacks as $postback): ?>
                            <div class="mobile-custom-postback-card" style="background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 16px; margin-bottom: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                                <!-- Header: Name -->
                                <div style="margin-bottom: var(--spacing-sm); border-bottom: 1px solid rgba(0,0,0,0.1); padding-bottom: var(--spacing-xs);">
                                    <div style="font-weight: 600; font-size: 16px; color: #3d5a26;">
                                        <?= htmlspecialchars($postback['name']) ?>
                                    </div>
                                </div>
                                
                                <!-- Postback URL -->
                                <div style="margin-bottom: var(--spacing-sm);">
                                    <div style="font-size: 11px; color: #666; margin-bottom: 4px;"><strong>Postback URL</strong></div>
                                    <div style="background: #fff; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-family: monospace; font-size: 11px; word-break: break-all; margin-bottom: 4px;">
                                        <?= htmlspecialchars($postback['postback_url']) ?>
                                    </div>
                                    <?php if (!empty($postback['description'])): ?>
                                        <div style="font-size: 11px; color: #666; margin-top: 4px;">
                                            <?= htmlspecialchars($postback['description']) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Campaigns -->
                                <div style="margin-bottom: var(--spacing-sm);">
                                    <div style="font-size: 11px; color: #666; margin-bottom: 4px;"><strong>Campaigns</strong></div>
                                    <div style="font-size: 14px; color: #333;">
                                        <?= (int)($postback['campaign_count'] ?? 0) ?>
                                    </div>
                                </div>
                                
                                <!-- Actions -->
                                <div style="display: flex; gap: 8px; border-top: 1px solid rgba(0,0,0,0.1); padding-top: var(--spacing-sm);">
                                    <a href="?page=settings&tab=integrations&edit_custom_postback=<?= $postback['id'] ?>"
                                       style="flex: 1; padding: 8px 12px; font-size: 12px; border: 1px solid #ddd; border-radius: 4px; background: #fff; cursor: pointer; text-decoration: none; color: #666; text-align: center; display: inline-block;">
                                        ✏️ Edit
                                    </a>
                                    <form method="post" 
                                          style="flex: 1; margin: 0;"
                                          onsubmit="return confirm('Are you sure you want to delete this postback? Campaigns using it will have their postback removed.');">
                                        <input type="hidden" name="action" value="delete_custom_postback">
                                        <input type="hidden" name="custom_postback_id" value="<?= $postback['id'] ?>">
                                        <button type="submit" 
                                                style="width: 100%; padding: 8px 12px; font-size: 12px; border: 1px solid #f44336; border-radius: 4px; background: #f44336; cursor: pointer; color: white;">
                                            🗑️ Delete
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Postback Diagnostics -->
    <?php
    // Check if postback_logs table exists
    $postbackLogsTableExists = false;
    try {
        $tableCheck = $db->query("SHOW TABLES LIKE 'postback_logs'");
        $postbackLogsTableExists = ($tableCheck && $tableCheck->num_rows > 0);
    } catch (Exception $e) {
        $postbackLogsTableExists = false;
    }
    
    // Fetch recent postback attempts (last 50)
    $recentPostbacks = [];
    if ($postbackLogsTableExists) {
        $stmt = $db->prepare(
            "SELECT pl.*, c.click_id as conversion_click_id
             FROM postback_logs pl
             LEFT JOIN conversions c ON pl.conversion_id = c.id
             ORDER BY pl.created_at DESC
             LIMIT 50"
        );
        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $recentPostbacks[] = $row;
            }
            $stmt->close();
        }
    }
    ?>
    
    <div class="card" style="margin-top: 24px; background: #ffffff; border: 1px solid #e0e0e0;">
        <div style="padding: 16px 20px 12px 20px; border-bottom: 1px solid #e0e0e0;">
            <h2 class="card-title" style="margin: 0; font-size: 16px; font-weight: 600; color: #333;">
                Postback Diagnostics
            </h2>
            <div style="font-size: 12px; color: #666; margin-top: 4px;">
                Recent postback attempts to traffic sources and integrations
            </div>
        </div>
        <div class="card-body" style="padding: 16px 20px;">
            <?php if (!$postbackLogsTableExists): ?>
                <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px; border-radius: 4px;">
                    <div style="font-size: 13px; color: #856404;">
                        <strong>⚠️ Setup Required:</strong> The postback logs table hasn't been created yet. 
                        <a href="../public/run-migration-046.php" target="_blank" style="color: #3d5a26; font-weight: 600; text-decoration: underline;">
                            Run Migration 046
                        </a>
                    </div>
                </div>
            <?php elseif (empty($recentPostbacks)): ?>
                <div style="text-align: center; padding: 40px; color: #999;">
                    <p>No postback attempts recorded yet.</p>
                    <p style="font-size: 13px; margin-top: 8px; color: #666;">
                        Postback attempts will appear here once conversions are tracked and postbacks are fired.
                    </p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                        <thead>
                            <tr style="background: #f5f5f5; border-bottom: 2px solid #ddd;">
                                <th style="padding: 10px 8px; text-align: left; font-weight: 600; font-size: 12px; color: #666;">Time</th>
                                <th style="padding: 10px 8px; text-align: left; font-weight: 600; font-size: 12px; color: #666;">Type</th>
                                <th style="padding: 10px 8px; text-align: left; font-weight: 600; font-size: 12px; color: #666;">Status</th>
                                <th style="padding: 10px 8px; text-align: left; font-weight: 600; font-size: 12px; color: #666;">HTTP</th>
                                <th style="padding: 10px 8px; text-align: left; font-weight: 600; font-size: 12px; color: #666;">Conversion ID</th>
                                <th style="padding: 10px 8px; text-align: left; font-weight: 600; font-size: 12px; color: #666;">Click ID</th>
                                <th style="padding: 10px 8px; text-align: left; font-weight: 600; font-size: 12px; color: #666;">URL</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentPostbacks as $postback): ?>
                                <?php
                                $statusColor = $postback['success'] ? '#1e8449' : '#d32f2f';
                                $statusText = $postback['success'] ? '✓ Success' : '✗ Failed';
                                $httpCodeColor = ($postback['http_code'] >= 200 && $postback['http_code'] < 300) ? '#1e8449' : 
                                                (($postback['http_code'] >= 300 && $postback['http_code'] < 400) ? '#f57c00' : '#d32f2f');
                                $typeLabel = ucfirst(str_replace('_', ' ', $postback['postback_type']));
                                $urlDisplay = strlen($postback['url']) > 60 ? substr($postback['url'], 0, 60) . '...' : $postback['url'];
                                // Use conversion_click_id from JOIN as source of truth (directly from conversions table)
                                // Fall back to stored click_id if conversion_click_id is not available
                                $clickIdDisplay = $postback['conversion_click_id'] ?: ($postback['click_id'] ?: '—');
                                $clickIdFull = $clickIdDisplay; // Keep full version for title attribute
                                // Don't truncate - show full click ID
                                ?>
                                <tr style="border-bottom: 1px solid #eee; <?= !$postback['success'] ? 'background: #fff5f5;' : '' ?>">
                                    <td style="padding: 10px 8px; color: #666; white-space: nowrap; font-size: 12px;">
                                        <?= date('M j, H:i:s', strtotime($postback['created_at'])) ?>
                                    </td>
                                    <td style="padding: 10px 8px; color: #666; font-size: 12px;">
                                        <span style="display: inline-block; padding: 3px 8px; border-radius: 12px; background: #f0f0f0; font-size: 11px;">
                                            <?= htmlspecialchars($typeLabel) ?>
                                        </span>
                                        <?php if ($postback['attempt_number'] > 1): ?>
                                            <span style="font-size: 10px; color: #999; margin-left: 4px;">
                                                (Attempt <?= $postback['attempt_number'] ?>)
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 10px 8px;">
                                        <span style="color: <?= $statusColor ?>; font-weight: 600; font-size: 12px;">
                                            <?= $statusText ?>
                                        </span>
                                    </td>
                                    <td style="padding: 10px 8px;">
                                        <span style="color: <?= $httpCodeColor ?>; font-weight: 600; font-size: 12px; font-family: monospace;">
                                            <?= $postback['http_code'] ?: '—' ?>
                                        </span>
                                    </td>
                                    <td style="padding: 10px 8px; color: #666; font-size: 12px; font-family: monospace;">
                                        <?= htmlspecialchars($postback['conversion_id'] ?: '—') ?>
                                    </td>
                                    <td style="padding: 10px 8px; color: #666; font-size: 11px; font-family: monospace; word-break: break-all; max-width: 200px;">
                                        <span title="<?= htmlspecialchars($clickIdFull) ?>">
                                            <?= htmlspecialchars($clickIdFull) ?>
                                        </span>
                                    </td>
                                    <td style="padding: 10px 8px; color: #666; font-size: 11px; max-width: 300px;">
                                        <span title="<?= htmlspecialchars($postback['url']) ?>" style="display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                            <?= htmlspecialchars($urlDisplay) ?>
                                        </span>
                                        <?php if ($postback['error_message']): ?>
                                            <div style="font-size: 10px; color: #d32f2f; margin-top: 4px;">
                                                <?= htmlspecialchars(substr($postback['error_message'], 0, 80)) ?><?= strlen($postback['error_message']) > 80 ? '...' : '' ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div style="margin-top: 16px; padding-top: 12px; border-top: 1px solid #e0e0e0; font-size: 12px; color: #666; text-align: center;">
                    Showing last 50 postback attempts. Older attempts are archived.
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($activeTab === 'api-costs'): ?>
    <?php require __DIR__ . '/settings-partials/api-usage-overview.php'; ?>

    <!-- Facebook Marketing API Integrations List -->    <!-- Facebook Marketing API Integrations List -->
    <div class="card" style="margin-top: 24px;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <h2 class="card-title">Facebook Marketing API Integrations</h2>
            <a href="?page=settings&tab=api-costs&edit_fm_integration=0" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px; color: #fff;">
                <img src="<?= ASSETS_BASE_URL ?>/assets/images/tfacebook.png" alt="" style="width: 24px; height: 24px;">
                + Add Integration
            </a>
        </div>
        <div class="card-body">
            <?php if (isset($errors['fm_integration']) && is_array($errors['fm_integration'])): ?>
                <div style="background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 12px; border-radius: 4px; margin-bottom: 20px;">
                    <strong>Errors:</strong>
                    <ul style="margin: 8px 0 0 20px;">
                        <?php foreach ($errors['fm_integration'] as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <?php if (empty($allFacebookMarketingIntegrations)): ?>
                <div style="text-align: center; padding: 40px; color: #999;">
                    <p>No Facebook Marketing API integrations configured yet.</p>
                    <p style="font-size: 14px; margin-top: 8px; color: #666;">
                        <strong>Note:</strong> Add one integration per Facebook ad account. Used for hourly cost tracking.
                    </p>
                    <a href="?page=settings&tab=api-costs&edit_fm_integration=0" class="btn btn-primary" style="margin-top: 16px; display: inline-flex; align-items: center; gap: 6px; color: #fff;">
                        <img src="<?= ASSETS_BASE_URL ?>/assets/images/tfacebook.png" alt="" style="width: 24px; height: 24px;">
                        Create Your First Integration
                    </a>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f5f5f5; border-bottom: 2px solid #ddd;">
                                <th style="padding: 12px; text-align: left; font-weight: 600;">Name</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600;">Ad Account ID</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600;">Status</th>
                                <th style="padding: 12px; text-align: right; font-weight: 600;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allFacebookMarketingIntegrations as $integration): ?>
                                <tr style="border-bottom: 1px solid #eee;">
                                    <td style="padding: 12px;"><?= htmlspecialchars($integration['name']) ?></td>
                                    <td style="padding: 12px; font-family: monospace; font-size: 13px;">
                                        <?= !empty($integration['ad_account_id']) ? htmlspecialchars($integration['ad_account_id']) : '<span style="color: #999;">—</span>' ?>
                                    </td>
                                    <td style="padding: 12px;">
                                        <span style="display: inline-block; padding: 4px 10px; background: <?= $integration['status'] === 'active' ? '#d4edda' : '#fff3cd' ?>; color: <?= $integration['status'] === 'active' ? '#155724' : '#856404' ?>; border-radius: 12px; font-size: 12px; font-weight: 500; text-transform: capitalize;">
                                            <?= htmlspecialchars($integration['status'] ?? 'active') ?>
                                        </span>
                                    </td>
                                    <td style="padding: 12px; text-align: right;">
                                        <a href="?page=settings&tab=api-costs&edit_fm_integration=<?= $integration['id'] ?>" 
                                           style="width: 36px; height: 36px; padding: 0; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s; color: #666; text-decoration: none; margin-right: 6px;"
                                           title="Edit Integration"
                                           onmouseover="this.style.background='#e3f2fd'; this.style.borderColor='#2196F3'; this.style.color='#2196F3';"
                                           onmouseout="this.style.background='#fff'; this.style.borderColor='#ddd'; this.style.color='#666';">
                                            ✏️
                                        </a>
                                        <form method="post" style="display: inline; margin: 0;" 
                                              onsubmit="return confirm('Are you sure you want to delete this integration? This will also delete all associated hourly cost data.');">
                                            <input type="hidden" name="action" value="delete_fm_integration">
                                            <input type="hidden" name="fm_integration_id" value="<?= $integration['id'] ?>">
                                            <button type="submit" 
                                                    style="width: 36px; height: 36px; padding: 0; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s; color: #666;"
                                                    title="Delete Integration"
                                                    onmouseover="this.style.background='#ffebee'; this.style.borderColor='#f44336'; this.style.color='#f44336';"
                                                    onmouseout="this.style.background='#fff'; this.style.borderColor='#ddd'; this.style.color='#666';">
                                                🗑️
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add/Edit Facebook Marketing Integration Form -->
    <?php if ($editingFacebookMarketingIntegration !== null || isset($_GET['edit_fm_integration'])): ?>
        <div class="card" style="margin-top: 24px;">
            <div class="card-header">
                <h2 class="card-title"><?= $editingFacebookMarketingIntegration ? 'Edit' : 'Add' ?> Facebook Marketing API Integration</h2>
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="<?= $editingFacebookMarketingIntegration ? 'update_fm_integration' : 'create_fm_integration' ?>">
                    <?php if ($editingFacebookMarketingIntegration): ?>
                        <input type="hidden" name="fm_integration_id" value="<?= $editingFacebookMarketingIntegration['id'] ?>">
                    <?php endif; ?>
                    
                    <div style="max-width: 600px;">
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Integration Name <span style="color: #d32f2f;">*</span></label>
                            <input type="text" name="fm_integration_name" 
                                   value="<?= htmlspecialchars($editingFacebookMarketingIntegration['name'] ?? '') ?>"
                                   placeholder="e.g., Main Account, Client A"
                                   required
                                   style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                            <div style="font-size: 12px; color: #666; margin-top: 4px;">A friendly name to identify this integration</div>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Access Token <?php if (empty(($editingFacebookMarketingIntegration ?? [])['access_token_set'])): ?><span style="color: #d32f2f;">*</span><?php endif; ?></label>
                            <input type="password" name="fm_access_token" autocomplete="new-password"
                                   value=""
                                   placeholder="<?= !empty(($editingFacebookMarketingIntegration ?? [])['access_token_set']) ? 'Leave blank to keep existing token' : 'EAAxxxxxxxxxxxxxxxx' ?>"
                                   <?= empty(($editingFacebookMarketingIntegration ?? [])['access_token_set']) ? 'required' : '' ?>
                                   style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-family: monospace;">
                            <div style="font-size: 12px; color: #666; margin-top: 4px;">Facebook Marketing API access token from Business Settings<?= !empty(($editingFacebookMarketingIntegration ?? [])['access_token_set']) ? ' (leave blank to keep current)' : '' ?></div>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Ad Accounts</label>
                            <div id="fm_ad_account_loading" style="display: none; padding: 10px; background: #f0f0f0; border-radius: 4px; font-size: 13px; color: #666; margin-bottom: 10px;">
                                🔄 Fetching and saving ad accounts...
                            </div>
                            <div id="fm_ad_account_success" style="display: none; padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; font-size: 13px; color: #155724; margin-bottom: 10px;">
                                ✅ <span id="fm_ad_account_success_msg"></span>
                            </div>
                            <div id="fm_ad_account_error" style="display: none; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; font-size: 13px; color: #721c24; margin-bottom: 10px;">
                                ❌ <span id="fm_ad_account_error_msg"></span>
                            </div>
                            
                            <?php if ($editingFacebookMarketingIntegration): ?>
                                <?php
                                require_once __DIR__ . '/../src/Entity/FacebookMarketingAdAccount.php';
                                $adAccountEntity = new \SimpleKuma\Entity\FacebookMarketingAdAccount($db);
                                $savedAdAccounts = $adAccountEntity->getByIntegrationId($editingFacebookMarketingIntegration['id']);
                                ?>
                                <?php if (!empty($savedAdAccounts)): ?>
                                    <div style="margin-bottom: 10px; padding: 12px; background: #f8f9fa; border-radius: 4px;">
                                        <div style="font-weight: 600; margin-bottom: 8px; color: #333;">Saved Ad Accounts (<?= count($savedAdAccounts) ?>)</div>
                                        <div style="max-height: 200px; overflow-y: auto;">
                                            <?php foreach ($savedAdAccounts as $account): ?>
                                                <div style="padding: 6px 0; border-bottom: 1px solid #e0e0e0; font-size: 13px;">
                                                    <strong><?= htmlspecialchars($account['ad_account_name']) ?></strong>
                                                    <span style="color: #666; font-family: monospace; margin-left: 8px;">(<?= htmlspecialchars($account['ad_account_id']) ?>)</span>
                                                    <?php if (!empty($account['currency'])): ?>
                                                        <span style="color: #666; margin-left: 8px;">- <?= htmlspecialchars($account['currency']) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div style="margin-bottom: 10px; padding: 12px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; font-size: 13px; color: #856404;">
                                        No ad accounts saved yet. Click "Fetch & Save All Ad Accounts" to load them.
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <div style="font-size: 12px; color: #666; margin-bottom: 8px;">
                                <?php if ($editingFacebookMarketingIntegration): ?>
                                    Click the button below to fetch and save all ad accounts accessible with this access token. This will update the list of available ad accounts for campaign cost tracking.
                                <?php else: ?>
                                    <strong>Note:</strong> After saving this integration, you can click the button below to fetch and save all ad accounts accessible with this access token.
                                <?php endif; ?>
                            </div>
                            <button type="button" 
                                    id="fm_fetch_ad_accounts_btn"
                                    onclick="fetchAndSaveFacebookAdAccounts()"
                                    <?php if (!$editingFacebookMarketingIntegration): ?>disabled title="Save the integration first"<?php endif; ?>
                                    style="padding: 10px 20px; background: <?= $editingFacebookMarketingIntegration ? '#2196F3' : '#ccc' ?>; color: white; border: none; border-radius: 4px; cursor: <?= $editingFacebookMarketingIntegration ? 'pointer' : 'not-allowed' ?>; font-size: 14px; font-weight: 500;">
                                🔍 Fetch & Save All Ad Accounts
                            </button>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Status <span style="color: #d32f2f;">*</span></label>
                            <select name="fm_status" 
                                    style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                                <option value="active" <?= ($editingFacebookMarketingIntegration['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="paused" <?= ($editingFacebookMarketingIntegration['status'] ?? 'active') === 'paused' ? 'selected' : '' ?>>Paused</option>
                            </select>
                            <div style="font-size: 12px; color: #666; margin-top: 4px;">Only active integrations will be used for cost tracking</div>
                        </div>

                        <!-- Proxy Configuration Section -->
                        <div style="margin-top: 32px; margin-bottom: 20px; padding: 20px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px;">
                            <h3 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: #333; display: flex; align-items: center; gap: 8px;">
                                🔒 Proxy Configuration (Optional)
                            </h3>
                            <p style="margin: 0 0 16px 0; font-size: 13px; color: #666;">
                                Configure a proxy server to route Facebook API requests through. Useful for region-specific routing or avoiding rate limits.
                            </p>

                            <div style="margin-bottom: 16px;">
                                <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer;">
                                    <input type="checkbox" 
                                           name="fm_use_proxy" 
                                           id="fm_use_proxy"
                                           value="1"
                                           <?= !empty($editingFacebookMarketingIntegration['use_proxy']) ? 'checked' : '' ?>
                                           onchange="toggleProxyFields('fm')"
                                           style="width: 18px; height: 18px; cursor: pointer;">
                                    <span>Use Proxy for API Requests</span>
                                </label>
                            </div>

                            <div id="fm_proxy_fields" style="<?= !empty($editingFacebookMarketingIntegration['use_proxy']) ? '' : 'display: none;' ?>">
                                <div style="margin-bottom: 16px;">
                                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Proxy Host <span style="color: #d32f2f;">*</span></label>
                                    <input type="text" 
                                           name="fm_proxy_host" 
                                           id="fm_proxy_host"
                                           value="<?= htmlspecialchars($editingFacebookMarketingIntegration['proxy_host'] ?? '') ?>"
                                           placeholder="proxy.example.com or 192.0.2.1"
                                           pattern="[a-zA-Z0-9.-]+"
                                           style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-family: monospace;">
                                    <div style="font-size: 12px; color: #666; margin-top: 4px;">Proxy server hostname or IP address</div>
                                </div>

                                <div style="margin-bottom: 16px; display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                                    <div>
                                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Proxy Port <span style="color: #d32f2f;">*</span></label>
                                        <input type="number" 
                                               name="fm_proxy_port" 
                                               id="fm_proxy_port"
                                               value="<?= htmlspecialchars($editingFacebookMarketingIntegration['proxy_port'] ?? '') ?>"
                                               placeholder="8080"
                                               min="1"
                                               max="65535"
                                               style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-family: monospace;">
                                        <div style="font-size: 12px; color: #666; margin-top: 4px;">Port (1-65535)</div>
                                    </div>

                                    <div>
                                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Proxy Type <span style="color: #d32f2f;">*</span></label>
                                        <select name="fm_proxy_type" 
                                                id="fm_proxy_type"
                                                style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                                            <option value="HTTP" <?= ($editingFacebookMarketingIntegration['proxy_type'] ?? 'HTTP') === 'HTTP' ? 'selected' : '' ?>>HTTP</option>
                                            <option value="SOCKS5" <?= ($editingFacebookMarketingIntegration['proxy_type'] ?? 'HTTP') === 'SOCKS5' ? 'selected' : '' ?>>SOCKS5</option>
                                        </select>
                                        <div style="font-size: 12px; color: #666; margin-top: 4px;">Proxy protocol</div>
                                    </div>
                                </div>

                                <div style="margin-bottom: 16px;">
                                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Proxy Username (Optional)</label>
                                    <input type="text" 
                                           name="fm_proxy_user" 
                                           id="fm_proxy_user"
                                           value="<?= htmlspecialchars($editingFacebookMarketingIntegration['proxy_user'] ?? '') ?>"
                                           placeholder="username"
                                           autocomplete="off"
                                           style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-family: monospace;">
                                    <div style="font-size: 12px; color: #666; margin-top: 4px;">Proxy authentication username (if required)</div>
                                </div>

                                <div style="margin-bottom: 16px;">
                                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Proxy Password (Optional)</label>
                                    <input type="password" 
                                           name="fm_proxy_pass" 
                                           id="fm_proxy_pass"
                                           placeholder="<?= $editingFacebookMarketingIntegration ? 'Leave blank to keep current password' : 'Enter proxy password' ?>"
                                           autocomplete="new-password"
                                           style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-family: monospace;">
                                    <div style="font-size: 12px; color: #666; margin-top: 4px;">
                                        <?= $editingFacebookMarketingIntegration ? 'Leave blank to keep current password. Enter new password to update.' : 'Proxy authentication password (will be encrypted)' ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div style="display: flex; gap: 12px;">
                            <button type="submit" class="btn btn-primary"><?= $editingFacebookMarketingIntegration ? 'Update' : 'Create' ?> Integration</button>
                            <a href="?page=settings&tab=api-costs" class="btn btn-secondary">Cancel</a>
                        </div>
                        <div style="margin-top: 16px; padding: 12px; background: #e3f2fd; border-left: 4px solid #2196F3; border-radius: 4px;">
                            <div style="font-size: 13px; color: #1976d2;">
                                <strong>💡 Tip:</strong> Add one integration per Facebook ad account. Used for hourly cost tracking via Facebook Insights API.
                            </div>
                        </div>

                        <!-- Cron Job Setup Instructions -->
                        <div style="margin-top: 24px; padding: 16px; background: #fff3cd; border: 2px solid #ffc107; border-radius: 6px;">
                            <h3 style="margin: 0 0 12px 0; font-size: 16px; color: #856404; display: flex; align-items: center; gap: 8px;">
                                ⚙️ Cron Job Setup Required
                            </h3>
                            <p style="margin: 0 0 12px 0; font-size: 14px; color: #856404;">
                                For cost tracking to work, you <strong>must</strong> set up a cron job to run hourly. The script will fetch Facebook ad spend data and sync it to your database.
                            </p>
                            
                            <div style="margin-bottom: 12px;">
                                <strong style="color: #856404; display: block; margin-bottom: 6px;">Cron Script Path:</strong>
                                <code style="display: block; padding: 8px; background: #fff; border: 1px solid #ddd; border-radius: 4px; font-family: monospace; font-size: 13px; color: #333; word-break: break-all;">
                                    <?= htmlspecialchars(ROOT_PATH) ?>/scripts/fb_cost_updater.php
                                </code>
                            </div>

                            <div style="margin-bottom: 12px;">
                                <strong style="color: #856404; display: block; margin-bottom: 6px;">Cron Command (runs every hour):</strong>
                                <code style="display: block; padding: 8px; background: #fff; border: 1px solid #ddd; border-radius: 4px; font-family: monospace; font-size: 13px; color: #333; word-break: break-all;">
                                    0 * * * * /usr/local/bin/php <?= htmlspecialchars(ROOT_PATH) ?>/scripts/fb_cost_updater.php >> <?= htmlspecialchars(ROOT_PATH) ?>/public/cron-output.log 2>&1
                                </code>
                                <div style="margin-top: 8px; padding: 8px; background: #fff3cd; border-left: 3px solid #ffc107; font-size: 12px; color: #856404;">
                                    <strong>Note:</strong> The script logs to <code>storage/logs/fb_cost_updater.log</code> automatically. The cron output log above (in public folder) captures any PHP errors that occur before the script runs.
                                </div>
                            </div>

                            <div style="background: #fff; padding: 12px; border-radius: 4px; margin-bottom: 12px; border: 1px solid #ddd;">
                                <strong style="color: #856404; display: block; margin-bottom: 8px;">Setup Instructions:</strong>
                                <ol style="margin: 0; padding-left: 20px; color: #856404; font-size: 13px; line-height: 1.8;">
                                    <li><strong>cPanel:</strong> Go to "Cron Jobs" → Add New Cron Job → Select "Once Per Hour (0 * * * *)" → Paste the command above (adjust PHP path if needed)</li>
                                    <li><strong>Command Line (SSH):</strong> Run <code style="background: #f5f5f5; padding: 2px 6px; border-radius: 3px;">crontab -e</code> → Add the cron command → Save</li>
                                    <li><strong>Windows Task Scheduler:</strong> Create new task → Trigger: Daily at 00:00 → Action: Run program → Program: <code style="background: #f5f5f5; padding: 2px 6px; border-radius: 3px;">php.exe</code> → Arguments: <code style="background: #f5f5f5; padding: 2px 6px; border-radius: 3px;"><?= htmlspecialchars(str_replace('\\', '/', ROOT_PATH)) ?>/scripts/fb_cost_updater.php</code></li>
                                    <li><strong>Alternative (if PHP path differs):</strong> Find your PHP path with <code style="background: #f5f5f5; padding: 2px 6px; border-radius: 3px;">which php</code> or <code style="background: #f5f5f5; padding: 2px 6px; border-radius: 3px;">whereis php</code> and replace <code style="background: #f5f5f5; padding: 2px 6px; border-radius: 3px;">/usr/bin/php</code></li>
                                </ol>
                            </div>

                            <div style="background: #fff; padding: 12px; border-radius: 4px; margin-bottom: 12px; border: 1px solid #ddd;">
                                <strong style="color: #856404; display: block; margin-bottom: 8px;">Verify Cron is Working:</strong>
                                <ul style="margin: 0; padding-left: 20px; color: #856404; font-size: 13px; line-height: 1.8;">
                                    <li>Wait for the cron to run (check logs: <code style="background: #f5f5f5; padding: 2px 6px; border-radius: 3px;">storage/logs/fb_cost_updater.log</code>)</li>
                                    <li>Check the database: <code style="background: #f5f5f5; padding: 2px 6px; border-radius: 3px;">SELECT * FROM adset_hourly_costs ORDER BY last_synced DESC LIMIT 10</code></li>
                                    <li>View costs in your Stats page - they should include Facebook API costs automatically</li>
                                    <li>Manually test: Run <code style="background: #f5f5f5; padding: 2px 6px; border-radius: 3px;">php scripts/fb_cost_updater.php</code> from command line</li>
                                </ul>
                            </div>

                            <div style="background: #d1ecf1; padding: 10px; border-radius: 4px; border: 1px solid #bee5eb;">
                                <p style="margin: 0; font-size: 12px; color: #0c5460;">
                                    <strong>📝 Note:</strong> The cron job must run hourly to keep costs synchronized. Costs are tracked per ad and adset, and aggregated automatically in your stats views. Manual cost parameters (from other traffic sources) continue to work as before.
                                </p>
                            </div>
                        </div>
                    </div>
                </form>
                <script>
                // Toggle proxy fields visibility
                function toggleProxyFields(prefix) {
                    const checkbox = document.getElementById(prefix + '_use_proxy');
                    const fields = document.getElementById(prefix + '_proxy_fields');
                    const host = document.getElementById(prefix + '_proxy_host');
                    const port = document.getElementById(prefix + '_proxy_port');
                    const type = document.getElementById(prefix + '_proxy_type');
                    
                    if (checkbox && fields && host && port && type) {
                        if (checkbox.checked) {
                            fields.style.display = 'block';
                            host.required = true;
                            port.required = true;
                            type.required = true;
                        } else {
                            fields.style.display = 'none';
                            host.required = false;
                            port.required = false;
                            type.required = false;
                            host.value = '';
                            port.value = '';
                            type.value = 'HTTP';
                        }
                    }
                }
                
                // Fetch and save all Facebook ad accounts
                function fetchAndSaveFacebookAdAccounts() {
                    const accessTokenInput = document.querySelector('input[name="fm_access_token"]');
                    const accessToken = accessTokenInput ? accessTokenInput.value.trim() : '';
                    
                    if (!accessToken) {
                        alert('Please enter an access token first');
                        return;
                    }
                    
                    <?php if ($editingFacebookMarketingIntegration): ?>
                    const integrationId = <?= $editingFacebookMarketingIntegration['id'] ?>;
                    <?php else: ?>
                    // For new integrations, we need to save first
                    if (!confirm('Please save the integration first. After saving, you can fetch ad accounts.\n\nWould you like to save the integration now?')) {
                        return;
                    }
                    // Submit the form to save the integration first
                    const form = document.querySelector('form[method="post"]');
                    if (form) {
                        // Add a flag to indicate we want to fetch after save
                        const hiddenInput = document.createElement('input');
                        hiddenInput.type = 'hidden';
                        hiddenInput.name = 'fetch_ad_accounts_after_save';
                        hiddenInput.value = '1';
                        form.appendChild(hiddenInput);
                        form.submit();
                    }
                    return;
                    <?php endif; ?>
                    
                    const loadingDiv = document.getElementById('fm_ad_account_loading');
                    const successDiv = document.getElementById('fm_ad_account_success');
                    const errorDiv = document.getElementById('fm_ad_account_error');
                    const successMsg = document.getElementById('fm_ad_account_success_msg');
                    const errorMsg = document.getElementById('fm_ad_account_error_msg');
                    const fetchBtn = document.getElementById('fm_fetch_ad_accounts_btn');
                    
                    // Hide previous messages
                    successDiv.style.display = 'none';
                    errorDiv.style.display = 'none';
                    
                    // Show loading state
                    loadingDiv.style.display = 'block';
                    fetchBtn.disabled = true;
                    fetchBtn.textContent = '🔄 Fetching & Saving...';
                    
                    // Fetch and save ad accounts via AJAX (POST — never put token in URL/logs)
                    const formData = new FormData();
                    formData.append('access_token', accessToken);
                    formData.append('integration_id', String(integrationId));
                    formData.append('app_csrf', <?= json_encode(Csrf::ensureToken(), JSON_THROW_ON_ERROR) ?>);
                    fetch('?page=settings&tab=api-costs&ajax=fetch_fb_ad_accounts', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    })
                        .then(response => response.json())
                        .then(data => {
                            loadingDiv.style.display = 'none';
                            fetchBtn.disabled = false;
                            fetchBtn.textContent = '🔍 Fetch & Save All Ad Accounts';
                            
                            if (data.success && data.count !== undefined) {
                                successMsg.textContent = 'Successfully saved ' + data.count + ' ad account(s) to the database.';
                                successDiv.style.display = 'block';
                                
                                // Reload page after 1.5 seconds to show updated list
                                setTimeout(() => {
                                    window.location.reload();
                                }, 1500);
                            } else {
                                errorMsg.textContent = data.error || 'Failed to fetch and save ad accounts';
                                errorDiv.style.display = 'block';
                            }
                        })
                        .catch(error => {
                            loadingDiv.style.display = 'none';
                            fetchBtn.disabled = false;
                            fetchBtn.textContent = '🔍 Fetch & Save All Ad Accounts';
                            errorMsg.textContent = 'Error: ' + error.message;
                            errorDiv.style.display = 'block';
                            
                            // Show manual input as fallback
                            select.style.display = 'none';
                            select.removeAttribute('name'); // Remove name so it's not submitted
                            manualInput.style.display = 'block';
                            manualInput.setAttribute('name', 'fm_ad_account_id'); // Ensure it has the name
                            select.required = false;
                            manualInput.required = true;
                            helpText.textContent = 'Enter the Facebook Ad Account ID manually (e.g., act_123456789012345)';
                        });
                }
                
                // Initialize form state on page load
                document.addEventListener('DOMContentLoaded', function() {
                    const select = document.getElementById('fm_ad_account_id');
                    const manualInput = document.getElementById('fm_ad_account_id_manual');
                    const currentValue = '<?= htmlspecialchars($editingFacebookMarketingIntegration['ad_account_id'] ?? '', ENT_QUOTES) ?>';
                    
                    // If editing and has a value, show manual input initially (will be replaced when fetching)
                    if (currentValue && select && manualInput) {
                        manualInput.style.display = 'block';
                        select.style.display = 'none';
                        select.removeAttribute('name');
                        manualInput.setAttribute('name', 'fm_ad_account_id');
                    } else if (select && manualInput) {
                        // New form - show dropdown placeholder
                        select.style.display = 'block';
                        manualInput.style.display = 'none';
                        select.setAttribute('name', 'fm_ad_account_id');
                        manualInput.removeAttribute('name');
                    }
                });

                // Add proxy validation for Marketing Integration form
                document.addEventListener('DOMContentLoaded', function() {
                    const fmForm = document.querySelector('form[method="post"]');
                    if (fmForm && document.getElementById('fm_use_proxy')) {
                        fmForm.addEventListener('submit', function(e) {
                            const useProxy = document.getElementById('fm_use_proxy');
                            if (useProxy && useProxy.checked) {
                                const host = document.getElementById('fm_proxy_host').value.trim();
                                const port = document.getElementById('fm_proxy_port').value.trim();
                                
                                if (!host) {
                                    e.preventDefault();
                                    alert('Proxy host is required when proxy is enabled.');
                                    return false;
                                }
                                
                                if (!port || port < 1 || port > 65535) {
                                    e.preventDefault();
                                    alert('Proxy port must be a number between 1 and 65535.');
                                    return false;
                                }
                            }
                        });
                    }
                });
                </script>
            </div>
        </div>
    <?php endif; ?>

    <?php require __DIR__ . '/settings-partials/google-api-costs.php'; ?>

<?php elseif ($activeTab === 'privacy'): ?>
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Data Retention</h2>
        </div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="action" value="update_settings">
                
                <div style="max-width: 600px;">
                    <div style="margin-bottom: 32px;">
                        <h3 style="margin: 0 0 16px 0; color: #3d5a26; font-size: 18px; border-bottom: 2px solid #e0e0e0; padding-bottom: 8px;">Privacy Settings</h3>
                        <div style="margin-bottom: 24px;">
                            <label style="display: flex; align-items: center; gap: 12px; cursor: pointer;">
                                <input type="checkbox" name="ip_anonymization" 
                                       <?= ($allSettings['ip_anonymization'] ?? '0') === '1' ? 'checked' : '' ?>
                                       style="width: 20px; height: 20px;">
                                <span style="font-weight: 600;">Enable IP Anonymization</span>
                            </label>
                            <div style="font-size: 12px; color: #666; margin-top: 8px; margin-left: 32px;">
                                Masks last octet of IPv4 addresses before storage for privacy compliance
                            </div>
                        </div>
                    </div>

                    <div style="margin-bottom: 32px;">
                        <h3 style="margin: 0 0 16px 0; color: #3d5a26; font-size: 18px; border-bottom: 2px solid #e0e0e0; padding-bottom: 8px;">Bot Detection</h3>
                        <p style="font-size: 13px; color: #666; margin: 0 0 16px 0;">
                            Detects known crawlers and bots on click write (Matomo DeviceDetector + Crawler-Detect).
                            Known bots are stored but excluded from reports via the existing stats flag — redirects and dashboards stay fast.
                        </p>
                        <div style="margin-bottom: 16px;">
                            <label style="display: flex; align-items: center; gap: 12px; cursor: pointer;">
                                <input type="checkbox" name="bot_detection_enabled"
                                       <?= ($allSettings['bot_detection_enabled'] ?? '1') === '1' ? 'checked' : '' ?>
                                       style="width: 20px; height: 20px;">
                                <span style="font-weight: 600;">Enable bot detection</span>
                            </label>
                            <div style="font-size: 12px; color: #666; margin-top: 8px; margin-left: 32px;">
                                Classify bots at click time. When off, only legacy Meta crawler / invalid Facebook ad-id rules apply.
                            </div>
                        </div>
                        <div style="margin-bottom: 16px;">
                            <label style="display: flex; align-items: center; gap: 12px; cursor: pointer;">
                                <input type="checkbox" name="bot_exclude_known_from_stats"
                                       <?= ($allSettings['bot_exclude_known_from_stats'] ?? '1') === '1' ? 'checked' : '' ?>
                                       style="width: 20px; height: 20px;">
                                <span style="font-weight: 600;">Exclude known bots from stats</span>
                            </label>
                            <div style="font-size: 12px; color: #666; margin-top: 8px; margin-left: 32px;">
                                Recommended. Keeps Googlebot, preview bots, SEO crawlers, etc. out of click / LP / revenue totals.
                            </div>
                        </div>
                        <div style="margin-bottom: 8px;">
                            <label style="display: flex; align-items: center; gap: 12px; cursor: pointer;">
                                <input type="checkbox" name="bot_exclude_suspected_from_stats"
                                       <?= ($allSettings['bot_exclude_suspected_from_stats'] ?? '0') === '1' ? 'checked' : '' ?>
                                       style="width: 20px; height: 20px;">
                                <span style="font-weight: 600;">Exclude suspected bots from stats</span>
                            </label>
                            <div style="font-size: 12px; color: #666; margin-top: 8px; margin-left: 32px;">
                                Off by default to avoid false positives. Only enable if you accept possible undercounting.
                            </div>
                        </div>
                    </div>

                    <div style="margin-bottom: 32px;">
                        <h3 style="margin: 0 0 16px 0; color: #3d5a26; font-size: 18px; border-bottom: 2px solid #e0e0e0; padding-bottom: 8px;">Retention Policies</h3>
                        
                        <div style="margin-bottom: 24px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Log Retention Period</label>
                            <select name="log_retention_days" 
                                    style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                                <option value="0" <?= ($allSettings['log_retention_days'] ?? '0') === '0' ? 'selected' : '' ?>>Never delete</option>
                                <option value="30" <?= ($allSettings['log_retention_days'] ?? '0') === '30' ? 'selected' : '' ?>>30 days</option>
                                <option value="90" <?= ($allSettings['log_retention_days'] ?? '0') === '90' ? 'selected' : '' ?>>90 days</option>
                                <option value="180" <?= ($allSettings['log_retention_days'] ?? '0') === '180' ? 'selected' : '' ?>>180 days</option>
                                <option value="365" <?= ($allSettings['log_retention_days'] ?? '0') === '365' ? 'selected' : '' ?>>1 year</option>
                            </select>
                            <div style="font-size: 12px; color: #666; margin-top: 4px;">How long to keep click and conversion data (default: Never delete)</div>
                        </div>

                        <div style="margin-bottom: 24px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Attribution Window</label>
                            <input type="number" name="attribution_window_days" 
                                   value="<?= htmlspecialchars($allSettings['attribution_window_days'] ?? '30') ?>"
                                   min="1" max="365"
                                   style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                            <div style="font-size: 12px; color: #666; margin-top: 4px;">Days after click to accept conversions (default: 30 days)</div>
                        </div>

                        <div style="margin-bottom: 24px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Archive After Days</label>
                            <input type="number" name="archive_after_days" 
                                   value="<?= htmlspecialchars($allSettings['archive_after_days'] ?? '365') ?>"
                                   min="0" max="3650"
                                   style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                            <div style="font-size: 12px; color: #666; margin-top: 4px;">Move clicks older than this to archive table (0 = disabled, default: 365 days). Archived data remains accessible for reporting.</div>
                        </div>
                    </div>

                            <button type="submit" class="btn btn-primary">Save Data Retention Settings</button>
                </div>
            </form>
        </div>
    </div>

<?php elseif ($activeTab === 'groups'): ?>
    <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <h2 class="card-title">Campaign Groups</h2>
            <button onclick="showCreateGroupModal()" class="btn btn-primary">+ Create Group</button>
        </div>
        <div class="card-body">
            <?php if (empty($allGroups)): ?>
                <div style="text-align: center; padding: 40px; color: #666;">
                    <p>No campaign groups yet. Create your first group to organize campaigns.</p>
                </div>
            <?php else: ?>
                <!-- Campaign Groups Table (Desktop) -->
                <div class="table-wrapper desktop-only" style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="border-bottom: 2px solid #ddd; background: #f9f9f9;">
                                <th style="padding: 12px; text-align: left; font-weight: 600;">Name</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600;">Description</th>
                                <th style="padding: 12px; text-align: center; font-weight: 600;">Campaigns</th>
                                <th style="padding: 12px; text-align: right; font-weight: 600;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allGroups as $group): ?>
                            <tr style="border-bottom: 1px solid #eee;">
                                <td style="padding: 12px;">
                                    <strong><?= htmlspecialchars($group['name']) ?></strong>
                                </td>
                                <td style="padding: 12px; color: #666;">
                                    <?= htmlspecialchars($group['description'] ?? '') ?>
                                </td>
                                <td style="padding: 12px; text-align: center;">
                                    <span style="background: #e3f2fd; color: #1976d2; padding: 4px 12px; border-radius: 12px; font-weight: 600;">
                                        <?= (int)($group['campaign_count'] ?? 0) ?>
                                    </span>
                                </td>
                                <td style="padding: 12px; text-align: right;">
                                    <button onclick="showEditGroupModal(<?= $group['id'] ?>, '<?= htmlspecialchars($group['name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($group['description'] ?? '', ENT_QUOTES) ?>')" 
                                            style="width: 36px; height: 36px; padding: 0; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s; color: #666; margin-right: 6px;"
                                            title="Edit Group"
                                            onmouseover="this.style.background='#e3f2fd'; this.style.borderColor='#2196F3'; this.style.color='#2196F3';"
                                            onmouseout="this.style.background='#fff'; this.style.borderColor='#ddd'; this.style.color='#666';">
                                        ✏️
                                    </button>
                                    <button onclick="confirmDeleteGroup(<?= $group['id'] ?>, '<?= htmlspecialchars($group['name'], ENT_QUOTES) ?>', <?= (int)($group['campaign_count'] ?? 0) ?>)" 
                                            style="width: 36px; height: 36px; padding: 0; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s; color: #666;"
                                            title="Delete Group"
                                            onmouseover="this.style.background='#ffebee'; this.style.borderColor='#f44336'; this.style.color='#f44336';"
                                            onmouseout="this.style.background='#fff'; this.style.borderColor='#ddd'; this.style.color='#666';">
                                        🗑️
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Mobile Campaign Groups Cards -->
                <div class="mobile-group-cards mobile-only">
                    <?php foreach ($allGroups as $group): ?>
                        <div class="mobile-group-card" style="background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 16px; margin-bottom: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                            <!-- Header: Name -->
                            <div style="margin-bottom: var(--spacing-sm); border-bottom: 1px solid rgba(0,0,0,0.1); padding-bottom: var(--spacing-xs);">
                                <div style="font-weight: 600; font-size: 16px; color: #3d5a26;">
                                    <?= htmlspecialchars($group['name']) ?>
                                </div>
                            </div>
                            
                            <!-- Description -->
                            <?php if (!empty($group['description'])): ?>
                                <div style="margin-bottom: var(--spacing-sm);">
                                    <div style="font-size: 11px; color: #666; margin-bottom: 4px;"><strong>Description</strong></div>
                                    <div style="font-size: 13px; color: #333; line-height: 1.5;">
                                        <?= htmlspecialchars($group['description']) ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Campaigns -->
                            <div style="margin-bottom: var(--spacing-sm);">
                                <div style="font-size: 11px; color: #666; margin-bottom: 4px;"><strong>Campaigns</strong></div>
                                <div>
                                    <span style="background: #e3f2fd; color: #1976d2; padding: 4px 12px; border-radius: 12px; font-weight: 600; font-size: 14px;">
                                        <?= (int)($group['campaign_count'] ?? 0) ?>
                                    </span>
                                </div>
                            </div>
                            
                            <!-- Actions -->
                            <div style="display: flex; gap: 8px; border-top: 1px solid rgba(0,0,0,0.1); padding-top: var(--spacing-sm);">
                                <button onclick="showEditGroupModal(<?= $group['id'] ?>, '<?= htmlspecialchars($group['name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($group['description'] ?? '', ENT_QUOTES) ?>')"
                                        style="flex: 1; padding: 8px 12px; font-size: 12px; border: 1px solid #ddd; border-radius: 4px; background: #fff; cursor: pointer; color: #666; text-align: center;">
                                    ✏️ Edit
                                </button>
                                <button onclick="confirmDeleteGroup(<?= $group['id'] ?>, '<?= htmlspecialchars($group['name'], ENT_QUOTES) ?>', <?= (int)($group['campaign_count'] ?? 0) ?>)"
                                        style="flex: 1; padding: 8px 12px; font-size: 12px; border: 1px solid #f44336; border-radius: 4px; background: #f44336; cursor: pointer; color: white;">
                                    🗑️ Delete
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Create/Edit Group Modal -->
    <div id="groupModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div style="background: white; padding: 24px; border-radius: 8px; max-width: 500px; width: 90%; max-height: 90vh; overflow-y: auto;">
            <h3 id="modalTitle" style="margin: 0 0 20px 0;">Create Campaign Group</h3>
            <form method="post" id="groupForm">
                <input type="hidden" name="action" id="groupAction" value="create_group">
                <input type="hidden" name="group_id" id="group_id" value="">
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Group Name <span style="color: #d32f2f;">*</span></label>
                    <input type="text" name="group_name" id="group_name" required
                           style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;"
                           maxlength="100">
                    <?php if (isset($errors['group_name'])): ?>
                        <div style="color: #d32f2f; font-size: 14px; margin-top: 4px;"><?= htmlspecialchars($errors['group_name']) ?></div>
                    <?php endif; ?>
                </div>

                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Description (Optional)</label>
                    <textarea name="group_description" id="group_description" 
                              rows="3"
                              style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; resize: vertical;"
                              maxlength="500"></textarea>
                </div>

                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="button" onclick="closeGroupModal()" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Group</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function showCreateGroupModal() {
            document.getElementById('modalTitle').textContent = 'Create Campaign Group';
            document.getElementById('groupAction').value = 'create_group';
            document.getElementById('group_id').value = '';
            document.getElementById('group_name').value = '';
            document.getElementById('group_description').value = '';
            document.getElementById('groupModal').style.display = 'flex';
        }

        function showEditGroupModal(id, name, description) {
            document.getElementById('modalTitle').textContent = 'Edit Campaign Group';
            document.getElementById('groupAction').value = 'update_group';
            document.getElementById('group_id').value = id;
            document.getElementById('group_name').value = name;
            document.getElementById('group_description').value = description || '';
            document.getElementById('groupModal').style.display = 'flex';
        }

        function closeGroupModal() {
            document.getElementById('groupModal').style.display = 'none';
        }

        function confirmDeleteGroup(id, name, campaignCount) {
            if (campaignCount > 0) {
                alert('Cannot delete group "' + name + '": ' + campaignCount + ' campaign(s) are using it.\n\nRemove campaigns from this group first.');
                return;
            }
            
            if (confirm('Are you sure you want to delete the group "' + name + '"?\n\nThis action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'post';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_group">
                    <input type="hidden" name="group_id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Close modal on outside click
        document.getElementById('groupModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeGroupModal();
            }
        });
    </script>

<?php elseif ($activeTab === 'data'): ?>
    <?php require __DIR__ . '/settings-partials/data-management-tab.php'; ?>
<?php elseif ($activeTab === 'users'): ?>
    <!-- Users Tab -->
    <?php if (!$permission || !$permission->hasPermission(Permission::PERM_USER_MANAGE)): ?>
        <div class="card">
            <div class="card-body">
                <p style="color: #d32f2f;">You do not have permission to manage users.</p>
            </div>
        </div>
    <?php else: ?>
        <?php $userAction = $_GET['user_action'] ?? 'list'; ?>
        <?php if ($userAction === 'list'): ?>
            <div class="card">
                <div class="card-body">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                        <h2 style="margin: 0;">Users</h2>
                        <a href="?page=settings&tab=users&user_action=create" class="btn btn-primary">+ Add User</a>
                    </div>

                    <!-- Users Table (Desktop) -->
                    <div class="table-wrapper desktop-only" style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: #f5f5f5; border-bottom: 2px solid #ddd;">
                                    <th style="padding: 12px; text-align: left;">Username</th>
                                    <th style="padding: 12px; text-align: left;">Email</th>
                                    <th style="padding: 12px; text-align: left;">Roles</th>
                                    <th style="padding: 12px; text-align: left;">Status</th>
                                    <th style="padding: 12px; text-align: left;">Created</th>
                                    <th style="padding: 12px; text-align: right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allUsers as $user): ?>
                                    <tr style="border-bottom: 1px solid #eee;">
                                        <td style="padding: 12px;">
                                            <strong><?= htmlspecialchars($user['username']) ?></strong>
                                        </td>
                                        <td style="padding: 12px;">
                                            <?= htmlspecialchars($user['email']) ?>
                                        </td>
                                        <td style="padding: 12px;">
                                            <?php
                                            $roles = [];
                                            if ($user['primary_role_name']) {
                                                $roles[] = '<span style="background: #3d5a26; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px;">' . htmlspecialchars($user['primary_role_display']) . '</span>';
                                            }
                                            foreach ($user['additional_roles'] as $role) {
                                                $roles[] = '<span style="background: #558b2f; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px;">' . htmlspecialchars($role['display_name']) . '</span>';
                                            }
                                            echo implode(' ', $roles) ?: '<span style="color: #999;">No roles</span>';
                                            ?>
                                        </td>
                                        <td style="padding: 12px;">
                                            <?php if ($user['is_active']): ?>
                                                <span style="color: #4caf50; font-weight: 600;">Active</span>
                                            <?php else: ?>
                                                <span style="color: #999;">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 12px; color: #666;">
                                            <?= date('Y-m-d', strtotime($user['created_at'])) ?>
                                        </td>
                                        <td style="padding: 12px; text-align: right;">
                                            <a href="?page=settings&tab=users&user_action=edit&user_id=<?= $user['id'] ?>" class="btn btn-outline" style="padding: 6px 12px; font-size: 12px; margin-right: 4px;">Edit</a>
                                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this user?')">
                                                    <input type="hidden" name="action" value="delete_user">
                                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                    <button type="submit" class="btn btn-outline" 
                                                            style="padding: 6px 12px; font-size: 12px; background: #f44336; color: white; border-color: #f44336;">Delete</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Mobile User Cards -->
                    <div class="mobile-user-cards mobile-only">
                        <?php foreach ($allUsers as $user): ?>
                            <div class="mobile-user-card" style="background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 16px; margin-bottom: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                                <!-- Header: Username -->
                                <div style="margin-bottom: var(--spacing-sm); border-bottom: 1px solid rgba(0,0,0,0.1); padding-bottom: var(--spacing-xs);">
                                    <div style="font-weight: 600; font-size: 16px; color: #3d5a26;">
                                        <?= htmlspecialchars($user['username']) ?>
                                    </div>
                                </div>
                                
                                <!-- Email -->
                                <div style="margin-bottom: var(--spacing-sm);">
                                    <div style="font-size: 11px; color: #666; margin-bottom: 4px;"><strong>Email</strong></div>
                                    <div style="font-size: 13px; color: #333; word-break: break-all;">
                                        <?= htmlspecialchars($user['email']) ?>
                                    </div>
                                </div>
                                
                                <!-- Roles -->
                                <div style="margin-bottom: var(--spacing-sm);">
                                    <div style="font-size: 11px; color: #666; margin-bottom: 4px;"><strong>Roles</strong></div>
                                    <div>
                                        <?php
                                        $roles = [];
                                        if ($user['primary_role_name']) {
                                            $roles[] = '<span style="background: #3d5a26; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px; display: inline-block; margin-right: 4px; margin-bottom: 4px;">' . htmlspecialchars($user['primary_role_display']) . '</span>';
                                        }
                                        foreach ($user['additional_roles'] as $role) {
                                            $roles[] = '<span style="background: #558b2f; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px; display: inline-block; margin-right: 4px; margin-bottom: 4px;">' . htmlspecialchars($role['display_name']) . '</span>';
                                        }
                                        echo implode('', $roles) ?: '<span style="color: #999; font-size: 12px;">No roles</span>';
                                        ?>
                                    </div>
                                </div>
                                
                                <!-- Status and Created -->
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-sm); margin-bottom: var(--spacing-sm);">
                                    <div>
                                        <div style="font-size: 11px; color: #666; margin-bottom: 4px;"><strong>Status</strong></div>
                                        <div>
                                            <?php if ($user['is_active']): ?>
                                                <span style="color: #4caf50; font-weight: 600; font-size: 12px;">Active</span>
                                            <?php else: ?>
                                                <span style="color: #999; font-size: 12px;">Inactive</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div>
                                        <div style="font-size: 11px; color: #666; margin-bottom: 4px;"><strong>Created</strong></div>
                                        <div style="font-size: 12px; color: #666;">
                                            <?= date('Y-m-d', strtotime($user['created_at'])) ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Actions -->
                                <div style="display: flex; gap: 8px; border-top: 1px solid rgba(0,0,0,0.1); padding-top: var(--spacing-sm);">
                                    <a href="?page=settings&tab=users&user_action=edit&user_id=<?= $user['id'] ?>" 
                                       style="flex: 1; padding: 8px 12px; font-size: 12px; border: 1px solid #ddd; border-radius: 4px; background: #fff; cursor: pointer; text-decoration: none; color: #666; text-align: center; display: inline-block;">
                                        ✏️ Edit
                                    </a>
                                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                        <form method="POST" 
                                              style="flex: 1; margin: 0;" 
                                              onsubmit="return confirm('Are you sure you want to delete this user?');">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                            <button type="submit" 
                                                    style="width: 100%; padding: 8px 12px; font-size: 12px; border: 1px solid #f44336; border-radius: 4px; background: #f44336; cursor: pointer; color: white;">
                                                🗑️ Delete
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        <?php elseif ($userAction === 'create' || $userAction === 'edit'): ?>
            
            <div class="card">
                <div class="card-body">
                    <h2 style="margin: 0 0 24px 0;"><?= $userAction === 'create' ? 'Create User' : 'Edit User' ?></h2>

                    <form method="POST" action="?page=settings&tab=users">
                        <input type="hidden" name="action" value="<?= $userAction === 'create' ? 'create_user' : 'update_user' ?>">
                        <?php if ($userAction === 'edit' && isset($editingUser)): ?>
                            <input type="hidden" name="user_id" value="<?= $editingUser['id'] ?>">
                        <?php endif; ?>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                            <div>
                                <label style="display: block; font-weight: 600; margin-bottom: 8px;">
                                    Username <span style="color: #d32f2f;">*</span>
                                </label>
                                <input type="text" name="username" required
                                       value="<?= htmlspecialchars($editingUser['username'] ?? '') ?>"
                                       style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                                <?php if (isset($errors['user']['username'])): ?>
                                    <div style="color: #d32f2f; font-size: 12px; margin-top: 4px;"><?= htmlspecialchars($errors['user']['username']) ?></div>
                                <?php endif; ?>
                            </div>

                            <div>
                                <label style="display: block; font-weight: 600; margin-bottom: 8px;">
                                    Email <span style="color: #d32f2f;">*</span>
                                </label>
                                <input type="email" name="email" required
                                       value="<?= htmlspecialchars($editingUser['email'] ?? '') ?>"
                                       style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                                <?php if (isset($errors['user']['email'])): ?>
                                    <div style="color: #d32f2f; font-size: 12px; margin-top: 4px;"><?= htmlspecialchars($errors['user']['email']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">
                                Password <?= $userAction === 'create' ? '<span style="color: #d32f2f;">*</span>' : '<span style="color: #666;">(leave blank to keep current)</span>' ?>
                            </label>
                            <input type="password" name="password" <?= $userAction === 'create' ? 'required' : '' ?>
                                   style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                            <?php if (isset($errors['user']['password'])): ?>
                                <div style="color: #d32f2f; font-size: 12px; margin-top: 4px;"><?= htmlspecialchars($errors['user']['password']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                            <div>
                                <label style="display: block; font-weight: 600; margin-bottom: 8px;">
                                    Primary Role <span style="color: #d32f2f;">*</span>
                                </label>
                                <select name="role_id" required
                                        style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                                    <option value="">Select role...</option>
                                    <?php foreach ($allRoles as $role): ?>
                                        <option value="<?= $role['id'] ?>"
                                                <?= ($editingUser['role_id'] ?? null) == $role['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($role['display_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label style="display: block; font-weight: 600; margin-bottom: 8px;">
                                    Status
                                </label>
                                <label style="display: flex; align-items: center; gap: 8px; padding: 10px; border: 2px solid #ddd; border-radius: 4px; cursor: pointer;">
                                    <input type="checkbox" name="is_active" value="1"
                                           <?= ($editingUser['is_active'] ?? 1) ? 'checked' : '' ?>>
                                    Active
                                </label>
                            </div>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">
                                Additional Roles (Optional)
                            </label>
                            <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                <?php 
                                $editAdditionalRoleIds = array_column($editingUser['additional_roles'] ?? [], 'id');
                                foreach ($allRoles as $role): 
                                ?>
                                    <label style="display: flex; align-items: center; gap: 6px; padding: 8px 12px; border: 2px solid #ddd; border-radius: 4px; cursor: pointer;">
                                        <input type="checkbox" name="additional_role_ids[]" value="<?= $role['id'] ?>"
                                               <?= in_array($role['id'], $editAdditionalRoleIds) ? 'checked' : '' ?>>
                                        <?= htmlspecialchars($role['display_name']) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <?php if ($userAction === 'edit' && isset($editingUser)): ?>
                            <!-- Profile Settings (Timezone & Currency) -->
                            <div style="margin-top: 32px; padding-top: 32px; border-top: 2px solid #eee;">
                                <h3 style="margin: 0 0 20px 0; font-size: 18px; color: #3d5a26;">Profile Settings</h3>
                                
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                                    <div>
                                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Timezone</label>
                                        <?php
                                        // Normalize the stored timezone for comparison
                                        $storedTimezone = $editingUser['timezone'] ?? 'UTC';
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
                                        $normalizedTimezone = isset($timezoneMap[$storedTimezone]) ? $timezoneMap[$storedTimezone] : $storedTimezone;
                                        // Validate and get canonical name
                                        try {
                                            $tz = new DateTimeZone($normalizedTimezone);
                                            $normalizedTimezone = $tz->getName();
                                        } catch (Exception $e) {
                                            $normalizedTimezone = 'UTC';
                                        }
                                        ?>
                                        <select name="timezone" style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                                            <optgroup label="US & Canada">
                                                <option value="America/New_York" <?= $normalizedTimezone === 'America/New_York' ? 'selected' : '' ?>>Eastern Time (ET)</option>
                                                <option value="America/Chicago" <?= $normalizedTimezone === 'America/Chicago' ? 'selected' : '' ?>>Central Time (CT)</option>
                                                <option value="America/Denver" <?= $normalizedTimezone === 'America/Denver' ? 'selected' : '' ?>>Mountain Time (MT)</option>
                                                <option value="America/Los_Angeles" <?= $normalizedTimezone === 'America/Los_Angeles' ? 'selected' : '' ?>>Pacific Time (PT)</option>
                                            </optgroup>
                                            <optgroup label="Europe">
                                                <option value="Europe/London" <?= $normalizedTimezone === 'Europe/London' ? 'selected' : '' ?>>London (GMT/BST)</option>
                                                <option value="Europe/Paris" <?= $normalizedTimezone === 'Europe/Paris' ? 'selected' : '' ?>>Paris (CET)</option>
                                                <option value="Europe/Berlin" <?= $normalizedTimezone === 'Europe/Berlin' ? 'selected' : '' ?>>Berlin (CET)</option>
                                                <option value="Europe/Moscow" <?= $normalizedTimezone === 'Europe/Moscow' ? 'selected' : '' ?>>Moscow (MSK)</option>
                                            </optgroup>
                                            <optgroup label="Asia Pacific">
                                                <option value="Asia/Dubai" <?= $normalizedTimezone === 'Asia/Dubai' ? 'selected' : '' ?>>Dubai (GST)</option>
                                                <option value="Asia/Singapore" <?= $normalizedTimezone === 'Asia/Singapore' ? 'selected' : '' ?>>Singapore (SGT)</option>
                                                <option value="Asia/Tokyo" <?= $normalizedTimezone === 'Asia/Tokyo' ? 'selected' : '' ?>>Tokyo (JST)</option>
                                                <option value="Australia/Sydney" <?= $normalizedTimezone === 'Australia/Sydney' ? 'selected' : '' ?>>Sydney (AEDT)</option>
                                            </optgroup>
                                            <optgroup label="Other">
                                                <option value="UTC" <?= $normalizedTimezone === 'UTC' ? 'selected' : '' ?>>UTC (Universal)</option>
                                            </optgroup>
                                        </select>
                                        <?php if ($storedTimezone !== $normalizedTimezone && isset($timezoneMap[$storedTimezone])): ?>
                                            <div style="font-size: 12px; color: #666; margin-top: 4px;">
                                                Note: Timezone was updated from "<?= htmlspecialchars($storedTimezone) ?>" to "<?= htmlspecialchars($normalizedTimezone) ?>" for proper daylight saving time support.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Currency</label>
                                        <select name="currency" style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                                            <option value="USD" <?= ($editingUser['currency'] ?? 'USD') === 'USD' ? 'selected' : '' ?>>USD - US Dollar</option>
                                            <option value="EUR" <?= ($editingUser['currency'] ?? 'USD') === 'EUR' ? 'selected' : '' ?>>EUR - Euro</option>
                                            <option value="GBP" <?= ($editingUser['currency'] ?? 'USD') === 'GBP' ? 'selected' : '' ?>>GBP - British Pound</option>
                                            <option value="CAD" <?= ($editingUser['currency'] ?? 'USD') === 'CAD' ? 'selected' : '' ?>>CAD - Canadian Dollar</option>
                                            <option value="AUD" <?= ($editingUser['currency'] ?? 'USD') === 'AUD' ? 'selected' : '' ?>>AUD - Australian Dollar</option>
                                            <option value="JPY" <?= ($editingUser['currency'] ?? 'USD') === 'JPY' ? 'selected' : '' ?>>JPY - Japanese Yen</option>
                                        </select>
                                    </div>
                                </div>

                                <?php if ($editingUser['id'] == $_SESSION['user_id']): ?>
                                    <!-- Password Change (only for current user) -->
                                    <div style="margin-top: 24px; padding-top: 24px; border-top: 1px solid #eee;">
                                        <h4 style="margin: 0 0 16px 0; font-size: 16px; color: #3d5a26;">Change Password</h4>
                                        
                                        <div style="max-width: 400px;">
                                            <div style="margin-bottom: 16px;">
                                                <label style="display: block; font-weight: 600; margin-bottom: 8px;">Current Password</label>
                                                <input type="password" name="current_password" 
                                                       style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                                                <?php if (isset($errors['user']['current_password'])): ?>
                                                    <div style="color: #d32f2f; font-size: 12px; margin-top: 4px;"><?= htmlspecialchars($errors['user']['current_password']) ?></div>
                                                <?php endif; ?>
                                            </div>

                                            <div style="margin-bottom: 16px;">
                                                <label style="display: block; font-weight: 600; margin-bottom: 8px;">New Password</label>
                                                <input type="password" name="new_password" 
                                                       style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                                                <div style="font-size: 12px; color: #666; margin-top: 4px;">Minimum 8 characters</div>
                                                <?php if (isset($errors['user']['new_password'])): ?>
                                                    <div style="color: #d32f2f; font-size: 12px; margin-top: 4px;"><?= htmlspecialchars($errors['user']['new_password']) ?></div>
                                                <?php endif; ?>
                                            </div>

                                            <div style="margin-bottom: 16px;">
                                                <label style="display: block; font-weight: 600; margin-bottom: 8px;">Confirm New Password</label>
                                                <input type="password" name="confirm_password" 
                                                       style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                                                <?php if (isset($errors['user']['confirm_password'])): ?>
                                                    <div style="color: #d32f2f; font-size: 12px; margin-top: 4px;"><?= htmlspecialchars($errors['user']['confirm_password']) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div style="display: flex; gap: 12px; margin-top: 32px;">
                            <button type="submit" class="btn btn-primary">
                                <?= $userAction === 'create' ? 'Create User' : 'Update User' ?>
                            </button>
                            <a href="?page=settings&tab=users" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
<?php elseif ($activeTab === 'updates'): ?>
    <!-- Updates Tab -->
    <?php
    require_once __DIR__ . '/../src/Update/UpdateChecker.php';
    $updateChecker = new \SimpleKuma\Update\UpdateChecker($db);
    $currentVersion = $updateChecker->getCurrentVersion();
    $updateCheckEnabled = $updateChecker->isUpdateCheckEnabled();
    $canManageAppUpdate = $permission
        && $permission->hasPermission(Permission::PERM_UPDATE_MANAGE);
    // Opening Updates is intentional: admins get a fresh GitHub check (bypass 1h cache).
    // Read-only / check-only — never installs. Admin layout banner uses cache + async refresh.
    if ($canManageAppUpdate) {
        $updateChecker->checkForUpdates(true, true);
    } elseif ($updateCheckEnabled) {
        $updateChecker->checkForUpdates();
    }
    $lastUpdateCheck = $updateChecker->getLastUpdateCheck();

    $migrationRunner = new MigrationRunner($db);
    $dbMigrationStatus = $migrationRunner->getStatus();
    $canRunDbUpdate = $permission && (
        $permission->hasPermission(Permission::PERM_UPDATE_MANAGE)
        || $permission->hasPermission(Permission::PERM_SETTINGS_EDIT)
    );
    ?>
    <div class="card" style="margin-bottom: 24px;">
        <div class="card-header">
            <h2 class="card-title">Database schema</h2>
        </div>
        <div class="card-body">
            <p style="margin: 0 0 16px 0; color: #666; font-size: 14px; line-height: 1.5;">
                After unpacking a new release zip and restoring your <code>config/config.php</code> (and <code>.env</code> if you use one),
                click <strong>Update database</strong> to apply any missing schema migrations.
                This only adds pending changes — it does not delete campaigns, clicks, or other existing data.
            </p>

            <?php if (!$dbMigrationStatus['ok']): ?>
                <div style="padding: 14px 16px; background: #f8d7da; border-radius: 8px; border-left: 4px solid #d32f2f; margin-bottom: 16px;">
                    <p style="margin: 0; color: #721c24; font-size: 14px;">
                        Could not check database status:
                        <?= htmlspecialchars((string)($dbMigrationStatus['error'] ?? 'Unknown error')) ?>
                    </p>
                </div>
            <?php elseif ($dbMigrationStatus['up_to_date']): ?>
                <div style="padding: 14px 16px; background: #d4edda; border-radius: 8px; border-left: 4px solid #28a745; margin-bottom: 16px;">
                    <p style="margin: 0; color: #155724; font-size: 14px; font-weight: 600;">
                        Database schema is up to date
                    </p>
                    <p style="margin: 6px 0 0 0; color: #155724; font-size: 13px;">
                        <?= (int)$dbMigrationStatus['applied_count'] ?> of <?= (int)$dbMigrationStatus['total_files'] ?> migrations applied.
                    </p>
                </div>
            <?php else: ?>
                <div style="padding: 14px 16px; background: #fff3cd; border-radius: 8px; border-left: 4px solid #f57c00; margin-bottom: 16px;">
                    <p style="margin: 0; color: #856404; font-size: 14px; font-weight: 600;">
                        <?= (int)$dbMigrationStatus['pending_count'] ?> pending migration<?= $dbMigrationStatus['pending_count'] === 1 ? '' : 's' ?>
                    </p>
                    <p style="margin: 6px 0 0 0; color: #856404; font-size: 13px;">
                        <?= (int)$dbMigrationStatus['applied_count'] ?> already applied · <?= (int)$dbMigrationStatus['total_files'] ?> total in this package
                    </p>
                    <?php if (!empty($dbMigrationStatus['pending'])): ?>
                        <ul style="margin: 10px 0 0 0; padding-left: 18px; color: #856404; font-size: 12px; font-family: monospace; max-height: 140px; overflow-y: auto;">
                            <?php foreach ($dbMigrationStatus['pending'] as $pendingName): ?>
                                <li><?= htmlspecialchars((string)$pendingName) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($canRunDbUpdate): ?>
                <form method="POST" action="?page=settings&tab=updates" style="margin: 0;"
                      onsubmit="return confirm('Apply pending database migrations now?\n\nThis will only run missing migrations and will not wipe existing data.');">
                    <input type="hidden" name="action" value="run_db_migrations">
                    <?= Csrf::field() ?>
                    <button type="submit"
                            class="btn"
                            <?= (!$dbMigrationStatus['ok'] || $dbMigrationStatus['up_to_date']) ? 'disabled' : '' ?>
                            style="padding: 12px 24px; background: <?= (!$dbMigrationStatus['ok'] || $dbMigrationStatus['up_to_date']) ? '#9e9e9e' : '#3d5a26' ?>; color: white; border: none; border-radius: 6px; cursor: <?= (!$dbMigrationStatus['ok'] || $dbMigrationStatus['up_to_date']) ? 'not-allowed' : 'pointer' ?>; font-weight: 600;">
                        Update database
                    </button>
                </form>
            <?php else: ?>
                <p style="margin: 0; color: #666; font-size: 13px;">
                    You need Settings edit or Update manage permission to run database migrations.
                </p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Update Settings</h2>
        </div>
        <div class="card-body">
            <form method="POST" action="?page=settings&tab=updates" id="update-settings-form">
                <input type="hidden" name="action" value="save_update_settings">
                <?= Csrf::field() ?>
                
                <div style="margin-bottom: 24px;">
                    <label style="display: flex; align-items: center; gap: 12px; cursor: <?= $canManageAppUpdate ? 'pointer' : 'not-allowed' ?>; font-size: 16px; font-weight: 600; color: #3d5a26; opacity: <?= $canManageAppUpdate ? '1' : '0.65' ?>;">
                        <input type="checkbox" 
                               name="update_check_enabled" 
                               value="1" 
                               <?= $canManageAppUpdate ? '' : 'disabled' ?>
                               <?= ($updateCheckEnabled === true || $updateCheckEnabled === '1' || $settings->get('update_check_enabled', '0') === '1') ? 'checked' : '' ?>
                               style="width: 20px; height: 20px; cursor: <?= $canManageAppUpdate ? 'pointer' : 'not-allowed' ?>;">
                        <span>Enable automatic update checking</span>
                    </label>
                    <p style="margin: 8px 0 0 32px; color: #666; font-size: 14px;">
                        When enabled, Kuma will check for updates from the GitHub repository when you access the admin panel. 
                        You'll be notified if an update is available.
                    </p>
                </div>

                <div style="margin-top: 32px; padding: 20px; background: #f5f5f5; border-radius: 8px; border-left: 4px solid #3d5a26;">
                    <h3 style="margin: 0 0 12px 0; color: #3d5a26; font-size: 18px;">Current Version</h3>
                    <p style="margin: 0 0 16px 0; font-size: 16px; font-family: monospace; color: #333;">
                        <strong><?= htmlspecialchars($currentVersion) ?></strong>
                    </p>
                    
                    <?php if ($lastUpdateCheck): ?>
                        <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #ddd;">
                            <p style="margin: 0 0 8px 0; font-size: 13px; color: #666;">
                                <strong>Last checked:</strong> <?= date('M d, Y H:i', strtotime($lastUpdateCheck['checked_at'])) ?>
                            </p>
                            <?php if (!empty($lastUpdateCheck['error_message'])): ?>
                                <p style="margin: 8px 0 0 0; font-size: 13px; color: #f57c00;">
                                    ⚠️ Last check had an error: <?= htmlspecialchars($lastUpdateCheck['error_message']) ?>
                                </p>
                            <?php elseif ($lastUpdateCheck['update_available']): ?>
                                <p style="margin: 8px 0 0 0; font-size: 14px; color: #d32f2f; font-weight: 600;">
                                    ⚠️ Update available: <?= htmlspecialchars($lastUpdateCheck['latest_version']) ?>
                                </p>
                            <?php else: ?>
                                <p style="margin: 8px 0 0 0; font-size: 14px; color: #28a745;">
                                    ✅ You're running the latest version
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php elseif ($updateCheckEnabled): ?>
                        <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #ddd;">
                            <p style="margin: 0; font-size: 13px; color: #666;">
                                Update checking is enabled. The next check will occur when you access the admin panel.
                            </p>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($lastUpdateCheck && $lastUpdateCheck['update_available'] && !empty($lastUpdateCheck['changelog'])): ?>
                    <!-- Changelog Display -->
                    <div style="margin-top: 24px; padding: 20px; background: #f9f9f9; border-radius: 8px; border-left: 4px solid #f57c00;">
                        <h3 style="margin: 0 0 16px 0; color: #3d5a26; font-size: 18px;">What's New in Version <?= htmlspecialchars($lastUpdateCheck['latest_version']) ?></h3>
                        <div style="background: white; padding: 16px; border-radius: 6px; max-height: 400px; overflow-y: auto; font-size: 14px; line-height: 1.6; color: #333;">
                            <?php
                            // Parse and display changelog
                            // Release notes are remote content. Escape first, then add
                            // only the small formatting subset we control.
                            $changelog = htmlspecialchars(
                                (string) $lastUpdateCheck['changelog'],
                                ENT_QUOTES | ENT_SUBSTITUTE,
                                'UTF-8'
                            );
                            
                            // If it's markdown, convert to HTML
                            $changelog = preg_replace('/^### (.+)$/m', '<h4 style="margin: 16px 0 8px 0; color: #3d5a26; font-size: 16px; font-weight: 600;">$1</h4>', $changelog);
                            $changelog = preg_replace('/^- (.+)$/m', '<li style="margin: 4px 0;">$1</li>', $changelog);
                            $changelog = preg_replace('/^## (.+)$/m', '<h3 style="margin: 20px 0 12px 0; color: #3d5a26; font-size: 18px; font-weight: 600;">$1</h3>', $changelog);
                            
                            // Wrap list items in ul tags
                            $changelog = preg_replace('/(<li[^>]*>.*?<\/li>\s*)+/s', '<ul style="margin: 8px 0 16px 20px; padding: 0;">$0</ul>', $changelog);
                            
                            // Convert line breaks
                            $changelog = nl2br($changelog);
                            
                            echo $changelog;
                            ?>
                        </div>
                        <?php if (!empty($lastUpdateCheck['requirements'])): ?>
                            <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #ddd;">
                                <h4 style="margin: 0 0 8px 0; color: #666; font-size: 14px; font-weight: 600;">Requirements:</h4>
                                <ul style="margin: 0; padding-left: 20px; color: #666; font-size: 13px;">
                                    <?php if (isset($lastUpdateCheck['requirements']['php_min'])): ?>
                                        <li>PHP <?= htmlspecialchars($lastUpdateCheck['requirements']['php_min']) ?> or higher</li>
                                    <?php endif; ?>
                                    <?php if (isset($lastUpdateCheck['requirements']['mysql_min'])): ?>
                                        <li>MySQL <?= htmlspecialchars($lastUpdateCheck['requirements']['mysql_min']) ?> or higher</li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div style="margin-top: 24px;">
                    <button type="submit"
                            class="btn btn-primary"
                            id="save-update-settings-btn"
                            <?= $canManageAppUpdate ? '' : 'disabled' ?>
                            style="padding: 12px 24px; background: <?= $canManageAppUpdate ? '#3d5a26' : '#9e9e9e' ?>; color: white; border: none; border-radius: 6px; cursor: <?= $canManageAppUpdate ? 'pointer' : 'not-allowed' ?>; font-weight: 600;">
                        Save Settings
                    </button>
                </div>
            </form>

            <?php if ($canManageAppUpdate): ?>
                <div style="margin-top: 16px; display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
                    <form method="POST" action="?page=settings&tab=updates" style="margin: 0;">
                        <input type="hidden" name="action" value="check_updates">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn" style="padding: 12px 24px;">
                            Check for updates
                        </button>
                    </form>

                    <?php if ($lastUpdateCheck && $lastUpdateCheck['update_available']): ?>
                        <form method="POST"
                              action="?page=settings&tab=updates"
                              id="application-update-form"
                              style="margin: 0;"
                              onsubmit="if (!confirm('Install Kuma <?= htmlspecialchars((string)$lastUpdateCheck['latest_version']) ?> now?\n\nKuma will download the permanent Simple Kuma Download release from GitHub, preserve config and stored data, overlay application files, and run pending database migrations. Do not close this page until it finishes.')) return false; document.getElementById('application-update-button').disabled = true; document.getElementById('application-update-status').style.display = 'inline';">
                            <input type="hidden" name="action" value="start_update">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn" id="application-update-button" style="padding: 12px 24px; background: #f57c00; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">
                                Update Now
                            </button>
                        </form>
                    <?php endif; ?>
                    <span id="application-update-status" style="display: none; color: #666; font-size: 13px;">
                        Downloading and applying the update. Keep this page open…
                    </span>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php elseif ($activeTab === 'geoip'): ?>
    <?php
    // Check GeoIP system status
    $geoipPath = defined('GEOIP_DATABASE_PATH') && !empty(GEOIP_DATABASE_PATH) 
        ? GEOIP_DATABASE_PATH 
        : null;
    
    // Get root path for debugging
    $rootPath = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2);
    $geoipDir = $rootPath . DIRECTORY_SEPARATOR . 'geoip';
    $storageGeoipDir = $rootPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'geoip';
    
    // Check database availability
    $dbipProvider = new DBIPProvider($geoipPath);
    $ip2locationProvider = new IP2LocationProvider($geoipPath);
    $ipinfoProvider = new IPinfoProvider($geoipPath);
    
    $dbipAvailable = $dbipProvider->isAvailable();
    $ip2locationAvailable = $ip2locationProvider->isAvailable();
    $ipinfoAvailable = $ipinfoProvider->isAvailable();
    
    // Debug: Get the actual paths found by providers (using reflection to access private property)
    $dbipPath = null;
    $ip2locationPath = null;
    $ipinfoPath = null;
    try {
        $reflection = new ReflectionClass($dbipProvider);
        $prop = $reflection->getProperty('databasePath');
        $prop->setAccessible(true);
        $dbipPath = $prop->getValue($dbipProvider);
    } catch (Exception $e) {}
    try {
        $reflection = new ReflectionClass($ip2locationProvider);
        $prop = $reflection->getProperty('databasePath');
        $prop->setAccessible(true);
        $ip2locationPath = $prop->getValue($ip2locationProvider);
    } catch (Exception $e) {}
    try {
        $reflection = new ReflectionClass($ipinfoProvider);
        $prop = $reflection->getProperty('databasePath');
        $prop->setAccessible(true);
        $ipinfoPath = $prop->getValue($ipinfoProvider);
    } catch (Exception $e) {}
    
    // Check if required Composer classes exist
    // CRITICAL: Ensure autoloader is loaded before checking classes
    $autoloadPath = dirname(__DIR__, 2) . '/vendor/autoload.php';
    $composerAutoloadExists = file_exists($autoloadPath);
    if ($composerAutoloadExists && !class_exists('Composer\Autoload\ClassLoader')) {
        require_once $autoloadPath;
    }
    
    // Try both namespace formats (with and without leading backslash)
    $geoip2ReaderExists = class_exists('GeoIp2\Database\Reader') || class_exists('\GeoIp2\Database\Reader');
    $ip2locationDatabaseExists = class_exists('IP2Location\Database') || class_exists('\IP2Location\Database');
    
    // Debug: List files in geoip directories
    $geoipFiles = [];
    $storageGeoipFiles = [];
    if (is_dir($geoipDir)) {
        $geoipFiles = @scandir($geoipDir);
        if ($geoipFiles !== false) {
            $geoipFiles = array_filter($geoipFiles, function($f) { return $f !== '.' && $f !== '..'; });
        } else {
            $geoipFiles = [];
        }
    }
    if (is_dir($storageGeoipDir)) {
        $storageGeoipFiles = @scandir($storageGeoipDir);
        if ($storageGeoipFiles !== false) {
            $storageGeoipFiles = array_filter($storageGeoipFiles, function($f) { return $f !== '.' && $f !== '..'; });
        } else {
            $storageGeoipFiles = [];
        }
    }
    
    // Handle cache clear action (legacy; UI no longer exposes this)
    if (isset($_POST['action']) && $_POST['action'] === 'clear_cache') {
        try {
            $resolver = new GeoResolver($geoipPath);
            $resolver->clearCache();
            $success = 'Geolocation cache cleared successfully';
        } catch (Exception $e) {
            $errors['general'] = 'Failed to clear cache: ' . $e->getMessage();
        }
    }
    
    // Composer install is now handled in the main POST handler above
    ?>
    
    <div style="max-width: 1200px; margin: 0 auto;">
        <h2 style="margin-bottom: 24px;">Geolocation Database Management</h2>
        
        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 24px;">
            <h3 style="margin-top: 0;">System Overview</h3>
            <p style="margin-bottom: 12px;">
                Simple KUMA looks up visitor location using a <strong>multi-database fallback</strong>: it tries
                DB-IP Lite first, then IP2Location LITE, then IPinfo DB-Lite, until one returns a match.
                No API keys are required — lookups run from local database files on your server.
            </p>
            <p style="margin-bottom: 12px;">
                The location databases that ship with Kuma are the <strong>light / free</strong> editions of each provider.
                As far as we can tell from their licenses, these free editions may be redistributed with a free project
                as long as attribution is provided — which Kuma has done (see below).
            </p>
            <p style="margin-bottom: 0;">
                For newer builds of these databases, use the download links in the <strong>yellow section below</strong>
                (Attribution &amp; updates), then place the files in <code>/geoip/</code> or <code>/storage/geoip/</code>.
                Cards above show which databases are currently installed and readable.
            </p>
            
            <?php if (!$dbipAvailable || !$ip2locationAvailable || !$ipinfoAvailable): ?>
            <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 16px; border-radius: 4px; margin-top: 16px;">
                <h4 style="margin-top: 0;">Debug Information</h4>
                <p style="margin-bottom: 8px; font-size: 13px;"><strong>Root Path:</strong> <code><?= htmlspecialchars($rootPath) ?></code></p>
                <p style="margin-bottom: 8px; font-size: 13px;"><strong>GeoIP Directory:</strong> <code><?= htmlspecialchars($geoipDir) ?></code> <?= is_dir($geoipDir) ? '✓' : '✗' ?></p>
                <p style="margin-bottom: 8px; font-size: 13px;"><strong>Storage GeoIP Directory:</strong> <code><?= htmlspecialchars($storageGeoipDir) ?></code> <?= is_dir($storageGeoipDir) ? '✓' : '✗' ?></p>
                <p style="margin-bottom: 8px; font-size: 13px;"><strong>DB-IP Path Found:</strong> <code><?= $dbipPath ? htmlspecialchars($dbipPath) : 'Not found' ?></code></p>
                <p style="margin-bottom: 8px; font-size: 13px;"><strong>IP2Location Path Found:</strong> <code><?= $ip2locationPath ? htmlspecialchars($ip2locationPath) : 'Not found' ?></code></p>
                <p style="margin-bottom: 8px; font-size: 13px;"><strong>IPinfo Path Found:</strong> <code><?= $ipinfoPath ? htmlspecialchars($ipinfoPath) : 'Not found' ?></code></p>
                <?php if (!empty($geoipFiles)): ?>
                <p style="margin-bottom: 4px; font-size: 13px;"><strong>Files in /geoip/:</strong></p>
                <ul style="margin: 0 0 8px 20px; font-size: 12px;">
                    <?php foreach ($geoipFiles as $file): ?>
                    <li><code><?= htmlspecialchars($file) ?></code> <?= is_readable($geoipDir . DIRECTORY_SEPARATOR . $file) ? '✓ readable' : '✗ not readable' ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <?php if (!empty($storageGeoipFiles)): ?>
                <p style="margin-bottom: 4px; font-size: 13px;"><strong>Files in /storage/geoip/:</strong></p>
                <ul style="margin: 0; font-size: 12px;">
                    <?php foreach ($storageGeoipFiles as $file): ?>
                    <li><code><?= htmlspecialchars($file) ?></code></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <div style="margin-top: 12px; padding: 12px; background: #fff; border: 1px solid #ddd; border-radius: 4px;">
                    <p style="margin: 0 0 8px 0; font-size: 13px; font-weight: 600;">Composer Dependencies Check:</p>
                    <ul style="margin: 0; padding-left: 20px; font-size: 12px;">
                        <li>Composer Autoload: <?= $composerAutoloadExists ? '✓ Found' : '✗ Missing' ?></li>
                        <li>GeoIp2\Database\Reader: <?= $geoip2ReaderExists ? '✓ Available' : '✗ Missing (required for DB-IP and IPinfo)' ?></li>
                        <li>IP2Location\Database: <?= $ip2locationDatabaseExists ? '✓ Available' : '✗ Missing (required for IP2Location)' ?></li>
                    </ul>
                    <?php if (!$composerAutoloadExists || !$geoip2ReaderExists || !$ip2locationDatabaseExists): ?>
                    <p style="margin: 12px 0 0 0; font-size: 12px; color: #dc3545; font-weight: 600;">
                        ⚠️ Missing Composer Dependencies!
                    </p>
                    <p style="margin: 8px 0 0 0; font-size: 12px; color: #856404;">
                        The installer should have installed these automatically, but it may have failed on cPanel.
                    </p>
                    <div style="margin-top: 12px;">
                        <a href="../public/install-composer.php" target="_blank" style="background: #3d5a26; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-weight: 600; text-decoration: none; display: inline-block;">
                            🔧 Open Standalone Installer
                        </a>
                    </div>
                    <p style="margin: 8px 0 0 0; font-size: 11px; color: #666;">
                        Opens a standalone installer that works even when exec() is disabled. 
                        The installer will show you a security token to use.
                    </p>
                    <div style="margin-top: 12px; padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px;">
                        <strong style="color: #856404;">Alternative: cPanel Terminal</strong>
                        <p style="margin: 8px 0 0 0; font-size: 11px; color: #856404;">
                            If your hosting provider offers cPanel Terminal or SSH access, run these commands:
                        </p>
                        <pre style="background: #f4f4f4; padding: 10px; border-radius: 4px; margin: 8px 0 0 0; font-size: 11px; overflow-x: auto;">cd <?= htmlspecialchars(defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2)) ?>
php composer.phar install --no-dev --optimize-autoloader</pre>
                    </div>
                    <?php endif; ?>
                </div>
                <p style="margin-top: 12px; font-size: 12px; color: #856404;">
                    <strong>Note:</strong> Check your server error logs for detailed initialization errors. 
                    Files are found and readable, but initialization is failing - this usually means missing Composer dependencies.
                </p>
            </div>
            <?php endif; ?>
        </div>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 24px;">
            <!-- DB-IP Status -->
            <div style="background: white; border: 2px solid <?= $dbipAvailable ? '#28a745' : '#dc3545' ?>; border-radius: 8px; padding: 20px;">
                <h3 style="margin-top: 0; color: <?= $dbipAvailable ? '#28a745' : '#dc3545' ?>;">
                    <?= $dbipAvailable ? '✓' : '✗' ?> DB-IP Lite
                </h3>
                <p style="color: #666; font-size: 14px;">
                    <strong>Status:</strong> <?= $dbipAvailable ? 'Available' : 'Not Found' ?><br>
                    <strong>Format:</strong> MMDB<br>
                    <strong>License:</strong> CC BY 4.0<br>
                    <strong>Priority:</strong> Primary (first in fallback chain)
                </p>
                <?php if (!$dbipAvailable): ?>
                <p style="color: #dc3545; font-size: 13px; margin-top: 12px;">
                    <strong>Download:</strong> <a href="https://db-ip.com/db/download/ip-to-city-lite" target="_blank">db-ip.com</a><br>
                    <strong>Expected file:</strong> <code>dbip-city-lite.mmdb</code> or <code>DBIP-City-Lite.mmdb</code>
                </p>
                <?php endif; ?>
            </div>
            
            <!-- IP2Location Status -->
            <div style="background: white; border: 2px solid <?= $ip2locationAvailable ? '#28a745' : '#dc3545' ?>; border-radius: 8px; padding: 20px;">
                <h3 style="margin-top: 0; color: <?= $ip2locationAvailable ? '#28a745' : '#dc3545' ?>;">
                    <?= $ip2locationAvailable ? '✓' : '✗' ?> IP2Location LITE
                </h3>
                <p style="color: #666; font-size: 14px;">
                    <strong>Status:</strong> <?= $ip2locationAvailable ? 'Available' : 'Not Found' ?><br>
                    <strong>Format:</strong> BIN<br>
                    <strong>License:</strong> Free (redistribution allowed)<br>
                    <strong>Priority:</strong> Secondary (second in fallback chain)
                </p>
                <?php if (!$ip2locationAvailable): ?>
                <p style="color: #dc3545; font-size: 13px; margin-top: 12px;">
                    <strong>Download:</strong> <a href="https://lite.ip2location.com/" target="_blank">lite.ip2location.com</a><br>
                    <strong>Expected file:</strong> <code>IP2LOCATION-LITE-DB11.BIN</code>
                </p>
                <?php endif; ?>
            </div>
            
            <!-- IPinfo Status -->
            <div style="background: white; border: 2px solid <?= $ipinfoAvailable ? '#28a745' : '#dc3545' ?>; border-radius: 8px; padding: 20px;">
                <h3 style="margin-top: 0; color: <?= $ipinfoAvailable ? '#28a745' : '#dc3545' ?>;">
                    <?= $ipinfoAvailable ? '✓' : '✗' ?> IPinfo DB-Lite
                </h3>
                <p style="color: #666; font-size: 14px;">
                    <strong>Status:</strong> <?= $ipinfoAvailable ? 'Available' : 'Not Found' ?><br>
                    <strong>Format:</strong> MMDB<br>
                    <strong>License:</strong> CC BY-SA 4.0<br>
                    <strong>Priority:</strong> Tertiary (third in fallback chain)
                </p>
                <?php if (!$ipinfoAvailable): ?>
                <p style="color: #dc3545; font-size: 13px; margin-top: 12px;">
                    <strong>Download:</strong> <a href="https://ipinfo.io/products/ipinfo-db-lite" target="_blank">ipinfo.io</a><br>
                    <strong>Expected file:</strong> <code>ipinfo-lite.mmdb</code> or <code>ipinfo-db-lite.mmdb</code>
                </p>
                <?php endif; ?>
            </div>
        </div>
        
        <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 16px; border-radius: 4px; margin-bottom: 24px;">
            <h4 style="margin-top: 0;">Attribution &amp; database updates</h4>
            <p style="margin-bottom: 8px; font-size: 14px;">
                Kuma includes or expects the light/free editions of these sources. Download newer builds from the vendor pages below, then place files in <code>/geoip/</code> or <code>/storage/geoip/</code>:
            </p>
            <ul style="margin: 0 0 12px 0; padding-left: 20px; font-size: 14px;">
                <li>
                    <strong>DB-IP Lite:</strong>
                    <a href="https://db-ip.com/db/download/ip-to-city-lite" target="_blank" rel="noopener">Download</a>
                    · IP Geolocation by DB-IP (<a href="https://db-ip.com" target="_blank" rel="noopener">db-ip.com</a>) · expected <code>dbip-city-lite.mmdb</code>
                </li>
                <li>
                    <strong>IP2Location LITE:</strong>
                    <a href="https://lite.ip2location.com/" target="_blank" rel="noopener">Download</a>
                    · data from <a href="https://lite.ip2location.com" target="_blank" rel="noopener">lite.ip2location.com</a> · expected <code>IP2LOCATION-LITE-DB11.BIN</code>
                </li>
                <li>
                    <strong>IPinfo DB-Lite:</strong>
                    <a href="https://ipinfo.io/products/ipinfo-db-lite" target="_blank" rel="noopener">Download</a>
                    · data from <a href="https://ipinfo.io" target="_blank" rel="noopener">ipinfo.io</a> (CC BY-SA 4.0) · expected <code>ipinfo-lite.mmdb</code>
                </li>
            </ul>
            <p style="margin: 0; font-size: 13px; color: #666;">
                Optional CLI helper (where available): <code>php scripts/download-geoip-databases.php --all</code>.
                IP2Location and IPinfo often still need a manual download because of their distribution terms.
                The system can run with DB-IP Lite alone; all three give the best coverage.
            </p>
        </div>
    </div>

<?php elseif ($activeTab === 'edge-redirect'): ?>
    <?php
    $edgeSettings = new \SimpleKuma\Edge\EdgeSettings($db);
    $edgeStatus = $edgeSettings->statusSnapshot();
    $canEditEdge = $permission && $permission->hasPermission(Permission::PERM_SETTINGS_EDIT);
    $defaultOrigin = defined('BASE_URL') ? rtrim((string) BASE_URL, '/') : '';
    $statusValueClass = $edgeStatus['enabled'] ? 'is-ok' : 'is-muted';
    $healthValueClass = !$edgeStatus['last_health_at']
        ? 'is-muted'
        : ($edgeStatus['last_health_ok'] ? 'is-ok' : 'is-warn');
    $healthLabel = $edgeStatus['last_health_at']
        ? ($edgeStatus['last_health_ok'] ? 'OK' : 'Issue')
        : 'Not checked';
    ?>
    <link rel="stylesheet" href="<?= ASSETS_BASE_URL ?>/assets/css/settings-edge-redirect.css?v=2">

    <div class="card settings-edge-redirect">
        <h2>Edge Redirect Engine</h2>
        <p class="settings-edge-redirect__intro">
            Run campaign redirects on Cloudflare Workers so visitors hit the nearest edge POP instead of your origin.
            Typical savings: hundreds of milliseconds worldwide (often ~500 ms → ~100 ms). Analytics post back asynchronously to Simple Kuma.
        </p>

        <div class="settings-edge-redirect__stats">
            <div class="settings-edge-redirect__stat">
                <div class="settings-edge-redirect__stat-label">Status</div>
                <div class="settings-edge-redirect__stat-value <?= $statusValueClass ?>">
                    <?= $edgeStatus['enabled'] ? 'Enabled' : 'Disabled' ?>
                </div>
            </div>
            <div class="settings-edge-redirect__stat">
                <div class="settings-edge-redirect__stat-label">Health</div>
                <div class="settings-edge-redirect__stat-value <?= $healthValueClass ?>">
                    <?= htmlspecialchars($healthLabel) ?>
                </div>
            </div>
            <div class="settings-edge-redirect__stat">
                <div class="settings-edge-redirect__stat-label">Last deploy</div>
                <div class="settings-edge-redirect__stat-value is-muted"><?= htmlspecialchars((string) ($edgeStatus['last_deploy_at'] ?: '—')) ?></div>
            </div>
            <div class="settings-edge-redirect__stat">
                <div class="settings-edge-redirect__stat-label">Last campaign sync</div>
                <div class="settings-edge-redirect__stat-value is-muted"><?= htmlspecialchars((string) ($edgeStatus['last_campaign_sync_at'] ?: '—')) ?></div>
            </div>
        </div>

        <?php if (!empty($edgeStatus['last_health_message'])): ?>
            <p class="settings-edge-redirect__health-note">Last health: <?= htmlspecialchars((string) $edgeStatus['last_health_message']) ?></p>
        <?php endif; ?>

        <form method="POST" action="?page=settings&tab=edge-redirect" class="settings-edge-redirect__form">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_edge_redirect_settings">

            <h3 style="margin-bottom: 12px;">Cloudflare connection</h3>
            <div class="settings-edge-redirect__fields">
                <div>
                    <label for="edge_cf_account_id">Account ID</label>
                    <input type="text" id="edge_cf_account_id" name="cf_account_id" value="<?= htmlspecialchars($edgeStatus['account_id']) ?>"
                           <?= !$canEditEdge ? 'readonly' : '' ?>
                           placeholder="Cloudflare account ID" autocomplete="off">
                </div>
                <div>
                    <label for="edge_cf_api_token">API token</label>
                    <input type="password" id="edge_cf_api_token" name="cf_api_token" value=""
                           <?= !$canEditEdge ? 'readonly' : '' ?>
                           placeholder="<?= $edgeStatus['has_api_token'] ? 'Leave blank to keep current (' . htmlspecialchars($edgeStatus['api_token_masked']) . ')' : 'Workers Scripts Edit, Account Settings Read, Workers KV Storage Edit, Zone Workers Routes Edit' ?>"
                           autocomplete="new-password">
                    <div class="settings-edge-redirect__hint">Stored encrypted. Create a token with Workers + KV permissions.</div>
                </div>
                <div>
                    <label for="edge_cf_zone_id">Zone ID (optional, for routes)</label>
                    <input type="text" id="edge_cf_zone_id" name="cf_zone_id" value="<?= htmlspecialchars($edgeStatus['zone_id']) ?>"
                           <?= !$canEditEdge ? 'readonly' : '' ?>
                           placeholder="Zone ID for your tracking domain">
                </div>
                <div>
                    <label for="edge_cf_route_pattern">Route pattern</label>
                    <input type="text" id="edge_cf_route_pattern" name="cf_route_pattern" value="<?= htmlspecialchars($edgeStatus['route_pattern']) ?>"
                           <?= !$canEditEdge ? 'readonly' : '' ?>
                           placeholder="track.example.com/*">
                    <div class="settings-edge-redirect__hint">Attach the Worker to your proxied tracking hostname.</div>
                </div>
                <div>
                    <label for="edge_cf_worker_name">Worker name</label>
                    <input type="text" id="edge_cf_worker_name" name="cf_worker_name" value="<?= htmlspecialchars($edgeStatus['worker_name']) ?>"
                           <?= !$canEditEdge ? 'readonly' : '' ?>>
                </div>
                <div>
                    <label for="edge_origin_base_url">Origin base URL</label>
                    <input type="url" id="edge_origin_base_url" name="origin_base_url" value="<?= htmlspecialchars($edgeStatus['origin_base_url'] ?: $defaultOrigin) ?>"
                           <?= !$canEditEdge ? 'readonly' : '' ?>
                           placeholder="https://tracker.example.com">
                    <div class="settings-edge-redirect__hint">
                        Used for async ingest (<code><?= htmlspecialchars($edgeStatus['ingest_url'] ?: ($defaultOrigin . '/api/edge-click')) ?></code>)
                        and origin fallback when a campaign is not on the edge.
                    </div>
                </div>
            </div>

            <?php if ($canEditEdge): ?>
            <div style="margin-top: 18px;">
                <button type="submit" class="btn btn-primary">Save connection</button>
            </div>
            <?php endif; ?>
        </form>

        <?php if ($canEditEdge): ?>
        <div class="settings-edge-redirect__actions">
            <form method="POST" action="?page=settings&tab=edge-redirect">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="deploy_edge_worker">
                <button type="submit" class="btn btn-primary">Deploy / Update Worker</button>
            </form>
            <form method="POST" action="?page=settings&tab=edge-redirect">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="edge_health_check">
                <button type="submit" class="btn btn-secondary">Health check</button>
            </form>
            <form method="POST" action="?page=settings&tab=edge-redirect">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="sync_edge_campaigns">
                <button type="submit" class="btn btn-secondary">Sync all campaigns</button>
            </form>
            <form method="POST" action="?page=settings&tab=edge-redirect">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="rotate_edge_ingest_secret">
                <button type="submit" class="btn btn-secondary" onclick="return confirm('Rotate ingest secret? You must redeploy the Worker afterward.');">Rotate ingest secret</button>
            </form>
            <form method="POST" action="?page=settings&tab=edge-redirect">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="disable_edge_redirect">
                <button type="submit" class="btn btn-secondary" onclick="return confirm('Disable Edge Redirect in Simple Kuma?');">Disable Edge Redirect</button>
            </form>
        </div>
        <?php endif; ?>

        <div class="settings-edge-redirect__callout">
            <strong>Setup guide</strong>
            <ol>
                <li>
                    <strong>Add your tracking domain to Cloudflare.</strong>
                    Create or import the zone for the hostname you use in campaigns (for example <code>track.example.com</code>).
                    DNS must be <em>Proxied</em> (orange cloud). Gray-cloud / DNS-only will skip the Worker and hit origin as usual.
                </li>
                <li>
                    <strong>Point that hostname at your Simple Kuma server.</strong>
                    Use an A/AAAA record (or CNAME) to your origin IP / host. Keep the same document root as your main tracker
                    so <code>go.php</code>, <code>/km/…</code>, and the rest of the app still resolve on that domain.
                </li>
                <li>
                    <strong>Create a Cloudflare API token</strong> with permission to edit Workers Scripts, Workers KV Storage,
                    and Workers Routes for the zone (plus Account Settings Read). Paste Account ID and token above.
                    Leave the token blank on later saves if you only need to change other fields.
                </li>
                <li>
                    <strong>Set Origin base URL</strong> to the public HTTPS URL of this Kuma install
                    (the same host the Worker should POST click analytics to). Then set <strong>Zone ID</strong> and a
                    <strong>Route pattern</strong> such as <code>track.example.com/*</code> so Cloudflare sends click traffic to the Worker.
                </li>
                <li>
                    <strong>Save connection</strong>, then click <strong>Deploy / Update Worker</strong>.
                    That creates the KV namespace, uploads the redirect script, binds ingest credentials, and attaches the route.
                    Use <strong>Health check</strong> afterward if you want to confirm the token and KV look good.
                </li>
                <li>
                    <strong>Enable Edge redirect on each campaign</strong> you want accelerated, then save the campaign
                    so its config syncs to KV. Phase 1 only supports a normal 302 — leave Referrer privacy on
                    “Standard redirect.” Redirectless campaigns stay on origin.
                </li>
                <li>
                    <strong>Keep your existing ad links.</strong>
                    No URL change is required: <code>go.php?k=…</code>, <code>/go/…</code>, and <code>/km/…</code> still work.
                    When the campaign is edge-enabled, the Worker answers from the nearest POP and logs the click asynchronously.
                    If a campaign is not on the edge (or KV has no snapshot), traffic falls through to your origin.
                </li>
            </ol>
            <p class="settings-edge-redirect__callout-note">
                Tip: after changing offers, caps, or rotation, save the campaign again (or use <strong>Sync all campaigns</strong>)
                so the edge snapshot stays current. Rotate the ingest secret only when needed, then redeploy so the Worker gets the new secret.
            </p>
        </div>

        <p class="settings-edge-redirect__meta">
            KV namespace: <?= htmlspecialchars($edgeStatus['kv_namespace_id'] ?: 'not provisioned') ?>
            · Ingest secret: <?= $edgeStatus['has_ingest_secret'] ? 'configured' : 'not set' ?>
        </p>
    </div>

<?php elseif ($activeTab === 'about'): ?>
    <link rel="stylesheet" href="<?= ASSETS_BASE_URL ?>/assets/css/settings-about.css?v=6">

    <div class="settings-about">
        <header class="about-intro">
            <span class="about-intro-kicker">The Kuma Spirit</span>
            <h2>Team work makes<br>the <span>dream work</span></h2>
            <p>
                Simple KUMA is creator-led and community-powered. This page recognizes the people
                whose code, ideas, and commitment have made a major impact on the project.
            </p>
        </header>

        <div class="about-people-grid">
            <section class="about-panel about-panel--creator" aria-labelledby="about-creator-title">
                <div class="about-panel-heading">
                    <div>
                        <h3 id="about-creator-title">Meet the Creator</h3>
                        <p>The original bear behind Simple KUMA.</p>
                    </div>
                    <span class="about-heading-icon" aria-hidden="true">♟</span>
                </div>

                <div class="about-profile">
                    <div class="about-profile-photo-wrap">
                        <img
                            class="about-profile-photo"
                            src="<?= ASSETS_BASE_URL ?>/assets/images/quintyfresh.jpg"
                            alt="QuintyFresh (Josh), creator of Simple KUMA"
                        >
                        <span class="about-profile-badge">QuintyFresh</span>
                    </div>

                    <div class="about-profile-copy">
                        <p>
                            Hey, I'm <strong>QuintyFresh (Josh)</strong>, the developer behind Simple KUMA.
                            I set out to build a standalone, self-hosted tracker with no profit motive
                            and no unnecessary barriers.
                        </p>
                        <p>
                            After more than 10 years in affiliate marketing, I wanted to bring back
                            some counter-culture energy: people building useful things because it is
                            fun, rebellious, and worth doing.
                        </p>
                        <p>It's free because I say it is. I need no other reason.</p>

                        <blockquote class="about-quote">
                            Let's sprinkle a little chaos on things. Everything needs a little good
                            chaos every now and then.
                        </blockquote>
                    </div>
                </div>
            </section>

            <section class="about-panel about-panel--club" aria-labelledby="kuma-club-title">
                <div class="about-panel-heading">
                    <div>
                        <h3 id="kuma-club-title">The Kuma Club</h3>
                        <p>Major code and idea contributors who help move Kuma forward.</p>
                    </div>
                    <span class="about-heading-icon" aria-hidden="true">♛</span>
                </div>

                <div class="kuma-club-list">
                    <article class="kuma-member">
                        <img
                            class="kuma-member-photo"
                            src="<?= ASSETS_BASE_URL ?>/assets/images/l1ght.png"
                            alt="L1Ght"
                        >
                        <div>
                            <span class="kuma-member-kicker">Founding Kuma Club member</span>
                            <a
                                class="kuma-member-name"
                                href="https://afflift.com/f/members/l1ght.19997/"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                L1Ght <span aria-hidden="true">↗</span>
                            </a>
                            <p class="kuma-member-role">Code &amp; Ideas Contributor</p>
                            <p class="kuma-member-patched">Patched In: July 29, 2026</p>
                            <p class="kuma-member-description">
                                Recognized for major contributions, sharp ideas, and helping shape
                                Simple KUMA into a stronger tracker for everyone.
                            </p>
                        </div>
                    </article>
                </div>

                <p class="kuma-club-note">
                    <span aria-hidden="true">✦</span>
                    The Kuma Club is reserved for community members whose lasting contributions
                    have made a meaningful difference to the project.
                </p>
            </section>
        </div>

        <section class="about-values" aria-labelledby="about-values-title">
            <div class="about-values-overview">
                <div class="about-section-heading">
                    <h3 id="about-values-title">Why we keep building</h3>
                    <p>
                        Simple KUMA is more than just a tracker. It is a passion project driven by
                        a simple belief: tracking should be free, simple, self-hosted, and yours.
                    </p>
                </div>

                <div class="about-value-summary">
                    <article>
                        <span aria-hidden="true">💯</span>
                        <div>
                            <h4>100% Free</h4>
                            <p>Always has been, always will be.</p>
                        </div>
                    </article>
                    <article>
                        <span aria-hidden="true">🚀</span>
                        <div>
                            <h4>Built for Simplicity</h4>
                            <p>Simplicity is the best weapon.</p>
                        </div>
                    </article>
                    <article>
                        <span aria-hidden="true">🤝</span>
                        <div>
                            <h4>Community Driven</h4>
                            <p>Built by rebels, for rebels.</p>
                        </div>
                    </article>
                </div>
            </div>

            <div class="about-values-divider" aria-hidden="true"><span>✦</span></div>

            <div class="about-values-grid">
                <article class="about-value-card">
                    <div class="about-value-image">
                        <img
                            src="<?= ASSETS_BASE_URL ?>/assets/images/josh1.gif"
                            alt="Celebrating that Simple KUMA is 100% free"
                        >
                    </div>
                    <div class="about-value-copy">
                        <span aria-hidden="true">💯</span>
                        <h4>100% Free</h4>
                        <p>Always has been, always will be.</p>
                    </div>
                </article>

                <article class="about-value-card">
                    <div class="about-value-image">
                        <img
                            src="<?= ASSETS_BASE_URL ?>/assets/images/josh3.gif"
                            alt="Simple KUMA built for simplicity"
                        >
                    </div>
                    <div class="about-value-copy">
                        <span aria-hidden="true">🚀</span>
                        <h4>Built for Simplicity</h4>
                        <p>Simplicity is the best weapon.</p>
                    </div>
                </article>

                <article class="about-value-card">
                    <div class="about-value-image">
                        <img
                            src="<?= ASSETS_BASE_URL ?>/assets/images/josh2.gif"
                            alt="Simple KUMA's community-driven spirit"
                        >
                    </div>
                    <div class="about-value-copy">
                        <span aria-hidden="true">🤝</span>
                        <h4>Community Driven</h4>
                        <p>Built by rebels, for rebels.</p>
                    </div>
                </article>
            </div>
        </section>

        <section class="about-inspiration" aria-labelledby="about-inspiration-title">
            <div class="about-inspiration-copy">
                <span class="about-eyebrow">One of the sparks</span>
                <h3 id="about-inspiration-title">A big inspiration behind Simple KUMA</h3>
                <p>
                    Steve Jobs on creating for the joy of making something meaningful and putting
                    a little dent in the universe.
                </p>
            </div>
            <div class="about-video">
                <iframe
                    src="https://www.youtube.com/embed/kYfNvmF0Bqw"
                    title="Steve Jobs interview: a Simple KUMA inspiration"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                    referrerpolicy="strict-origin-when-cross-origin"
                    allowfullscreen
                ></iframe>
            </div>
        </section>

        <section class="about-oss" aria-labelledby="about-oss-title">
            <div class="about-oss-header">
                <span class="about-eyebrow">Built on open source</span>
                <h3 id="about-oss-title">Open Source Dependencies</h3>
                <p>
                    Simple KUMA ships free open-source libraries. Thank you to the maintainers
                    whose work powers tracking, email, geolocation, ads integrations, and bot detection.
                </p>
            </div>

            <div class="about-oss-grid">
                <article class="about-oss-card">
                    <div class="about-oss-card-top">
                        <h4>Crawler-Detect</h4>
                        <span class="about-oss-license">MIT</span>
                    </div>
                    <p>Bot and crawler user-agent detection for cleaner campaign stats.</p>
                    <a class="about-oss-link" href="https://github.com/JayBizzle/Crawler-Detect" target="_blank" rel="noopener noreferrer">
                        github.com/JayBizzle/Crawler-Detect
                    </a>
                </article>

                <article class="about-oss-card">
                    <div class="about-oss-card-top">
                        <h4>Matomo DeviceDetector</h4>
                        <span class="about-oss-license">LGPL-3.0-or-later</span>
                    </div>
                    <p>Device, OS, browser parsing — and bot classification on the same parse.</p>
                    <a class="about-oss-link" href="https://github.com/matomo-org/device-detector" target="_blank" rel="noopener noreferrer">
                        github.com/matomo-org/device-detector
                    </a>
                </article>

                <article class="about-oss-card">
                    <div class="about-oss-card-top">
                        <h4>PHPMailer</h4>
                        <span class="about-oss-license">LGPL-2.1-only</span>
                    </div>
                    <p>Reliable outbound email for alerts, resets, and notifications.</p>
                    <a class="about-oss-link" href="https://github.com/PHPMailer/PHPMailer" target="_blank" rel="noopener noreferrer">
                        github.com/PHPMailer/PHPMailer
                    </a>
                </article>

                <article class="about-oss-card">
                    <div class="about-oss-card-top">
                        <h4>MaxMind GeoIP2</h4>
                        <span class="about-oss-license">Apache-2.0</span>
                    </div>
                    <p>PHP reader for MaxMind GeoIP2 / GeoLite2 city databases.</p>
                    <a class="about-oss-link" href="https://github.com/maxmind/GeoIP2-php" target="_blank" rel="noopener noreferrer">
                        github.com/maxmind/GeoIP2-php
                    </a>
                </article>

                <article class="about-oss-card">
                    <div class="about-oss-card-top">
                        <h4>IP2Location PHP</h4>
                        <span class="about-oss-license">MIT</span>
                    </div>
                    <p>Reader for IP2Location binary geolocation databases.</p>
                    <a class="about-oss-link" href="https://github.com/ip2location/ip2location-php" target="_blank" rel="noopener noreferrer">
                        github.com/ip2location/ip2location-php
                    </a>
                </article>

                <article class="about-oss-card">
                    <div class="about-oss-card-top">
                        <h4>Google Ads API PHP</h4>
                        <span class="about-oss-license">Apache-2.0</span>
                    </div>
                    <p>Official client for Google Ads cost and conversion integrations.</p>
                    <a class="about-oss-link" href="https://github.com/googleads/google-ads-php" target="_blank" rel="noopener noreferrer">
                        github.com/googleads/google-ads-php
                    </a>
                </article>
            </div>

            <p class="about-oss-footnote">
                Also includes transitive Composer dependencies shipped under <code>vendor/</code>
                with their own licenses. GeoIP <em>database</em> attribution lives on the Geolocation settings tab.
            </p>
        </section>
    </div>

<?php endif; ?>

    </div><!-- .settings-content -->
</div><!-- .settings-layout -->

<script>
document.addEventListener('DOMContentLoaded', function () {
    var sectionSelect = document.getElementById('settings-section-select');
    if (sectionSelect) {
        sectionSelect.addEventListener('change', function () {
            if (this.value) {
                window.location.href = this.value;
            }
        });
    }

    var token = <?= json_encode(\SimpleKuma\Auth\Csrf::ensureToken(), JSON_THROW_ON_ERROR) ?>;
    document.querySelectorAll('form').forEach(function (form) {
        var method = (form.getAttribute('method') || 'get').toLowerCase();
        if (method !== 'post') {
            return;
        }
        if (form.querySelector('input[name="app_csrf"]')) {
            return;
        }
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'app_csrf';
        input.value = token;
        form.appendChild(input);
    });
});
</script>