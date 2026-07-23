<?php

declare(strict_types=1);

namespace SimpleKuma\Tracking;

use mysqli;
use SimpleKuma\Entity\CustomPostback;

/**
 * Postback Dispatcher
 * Fires outbound postbacks to traffic sources and Facebook CAPI
 */
class PostbackDispatcher
{
    private mysqli $db;
    private const MAX_RETRIES = 3;
    private const INITIAL_RETRY_DELAY = 5; // seconds
    
    /**
     * US State name → ANSI 2-letter code map (lowercase)
     * Used to normalize Meta CAPI `st` parameter when GeoIP provides full state names.
     */
    private const US_STATE_NAME_TO_CODE = [
        'alabama' => 'al',
        'alaska' => 'ak',
        'arizona' => 'az',
        'arkansas' => 'ar',
        'california' => 'ca',
        'colorado' => 'co',
        'connecticut' => 'ct',
        'delaware' => 'de',
        'district of columbia' => 'dc',
        'florida' => 'fl',
        'georgia' => 'ga',
        'hawaii' => 'hi',
        'idaho' => 'id',
        'illinois' => 'il',
        'indiana' => 'in',
        'iowa' => 'ia',
        'kansas' => 'ks',
        'kentucky' => 'ky',
        'louisiana' => 'la',
        'maine' => 'me',
        'maryland' => 'md',
        'massachusetts' => 'ma',
        'michigan' => 'mi',
        'minnesota' => 'mn',
        'mississippi' => 'ms',
        'missouri' => 'mo',
        'montana' => 'mt',
        'nebraska' => 'ne',
        'nevada' => 'nv',
        'new hampshire' => 'nh',
        'new jersey' => 'nj',
        'new mexico' => 'nm',
        'new york' => 'ny',
        'north carolina' => 'nc',
        'north dakota' => 'nd',
        'ohio' => 'oh',
        'oklahoma' => 'ok',
        'oregon' => 'or',
        'pennsylvania' => 'pa',
        'rhode island' => 'ri',
        'south carolina' => 'sc',
        'south dakota' => 'sd',
        'tennessee' => 'tn',
        'texas' => 'tx',
        'utah' => 'ut',
        'vermont' => 'vt',
        'virginia' => 'va',
        'washington' => 'wa',
        'west virginia' => 'wv',
        'wisconsin' => 'wi',
        'wyoming' => 'wy',
    ];

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Fire postbacks for a conversion
     *
     * @param bool $forceBypassMinPayout When true, fires even if payout is below campaign minimum (manual re-fire)
     */
    public function firePostbacks(int $conversionId, bool $forceBypassMinPayout = false): void
    {
        // Get conversion and related data
        $conversion = $this->getConversionData($conversionId);

        if (!$conversion) {
            error_log("PostbackDispatcher: No conversion data found for conversion_id={$conversionId}. Check if click and campaign exist.");
            return;
        }
        
        error_log("PostbackDispatcher: Firing postbacks for conversion_id={$conversionId}, click_id={$conversion['click_id']}, campaign_id={$conversion['campaign_id']}");

        if (!$forceBypassMinPayout && !$this->shouldFireOutboundPostbacks($conversion)) {
            $this->logSkippedBelowThreshold($conversion);
            return;
        }

        // Traffic-source postback templates removed — outbound URLs use campaign Custom Postbacks

        // Fire Facebook CAPI
        if ($conversion['facebook_capi_json']) {
            error_log("PostbackDispatcher: Firing Facebook CAPI for conversion_id={$conversionId}");
            $this->fireFacebookCAPI($conversion);
        } else {
            error_log("PostbackDispatcher: No Facebook CAPI integration configured for conversion_id={$conversionId}");
        }

        // Fire Google Ads API upload (when integration delivery_mode is api or both)
        if (!empty($conversion['google_ads_integration_id'])) {
            error_log("PostbackDispatcher: Attempting Google Ads conversion upload for conversion_id={$conversionId}");
            $this->fireGoogleAdsUpload($conversionId);
        }

        // Fire custom postbacks
        $this->fireCustomPostbacks($conversion);
    }

    /**
     * Push conversion to Google Ads ConversionUploadService when configured.
     */
    private function fireGoogleAdsUpload(int $conversionId): void
    {
        try {
            $uploader = new \SimpleKuma\GoogleAds\GoogleAdsConversionUploader($this->db);
            $result = $uploader->uploadConversionIfConfigured($conversionId);
            error_log(
                "PostbackDispatcher: Google Ads upload conversion_id={$conversionId} "
                . "status={$result['status']} message={$result['message']}"
            );
        } catch (\Throwable $e) {
            error_log('PostbackDispatcher: Google Ads upload error: ' . $e->getMessage());
        }
    }

    /**
     * Get conversion with all related data
     */
    private function getConversionData(int $conversionId): ?array
    {
        // Prefer clicks_unified so archived clicks still resolve for manual/re-fire postbacks
        $clicksTable = \SimpleKuma\Database\ClicksTableResolver::getStatsTable($this->db);

        // Check if clicks.traffic_source_id column exists (added in migration 033)
        $columnCheck = $this->db->query("SHOW COLUMNS FROM `{$clicksTable}` LIKE 'traffic_source_id'");
        $hasTrafficSourceId = ($columnCheck && $columnCheck->num_rows > 0);
        
        // Check if clicks.offer_id column exists (added in migration 020)
        $offerIdCheck = $this->db->query("SHOW COLUMNS FROM `{$clicksTable}` LIKE 'offer_id'");
        $hasOfferId = ($offerIdCheck && $offerIdCheck->num_rows > 0);

        // Check if clicks.postal column exists (migration 052)
        $postalCheck = $this->db->query("SHOW COLUMNS FROM `{$clicksTable}` LIKE 'postal'");
        $hasPostal = ($postalCheck && $postalCheck->num_rows > 0);
        $postalSelect = $hasPostal ? "cl.postal," : "NULL as postal,";

        $minPayoutCheck = $this->db->query("SHOW COLUMNS FROM campaigns LIKE 'min_postback_payout'");
        $hasMinPayoutColumn = ($minPayoutCheck && $minPayoutCheck->num_rows > 0);
        $minPayoutSelect = $hasMinPayoutColumn ? 'cp.min_postback_payout,' : 'NULL as min_postback_payout,';
        
        // Build query based on whether traffic_source_id column exists
        if ($hasTrafficSourceId) {
            $offerIdSelect = $hasOfferId ? "cl.offer_id," : "NULL as offer_id,";
            $stmt = $this->db->prepare(
                "SELECT 
                    c.*, 
                    cl.campaign_id,
                    cl.traffic_source_id as click_traffic_source_id,
                    " . $offerIdSelect . "
                    cl.ip,
                    cl.ua,
                    cl.extra_json,
                    cl.referrer,
                    cl.landing_page_id,
                    cl.ts as click_ts,
                    cl.city,
                    cl.region,
                    cl.country,
                    " . $postalSelect . "
                    cp.traffic_source_id as campaign_traffic_source_id,
                    cp.tracking_domain_id,
                    cp.campaign_key,
                    cp.facebook_capi_integration_id,
                    " . $minPayoutSelect . "
                    cp.google_ads_integration_id,
                    cp.traffic_source_postbacks_json,
                    cp.name as campaign_name,
                    td.domain as tracking_domain,
                    fci.pixel_id as fb_pixel_id,
                    fci.access_token as fb_access_token,
                    fci.test_code as fb_test_code,
                    fci.event_type as fb_event_type,
                    o.url as offer_url,
                    lp.url as landing_page_url
                FROM conversions c
                INNER JOIN `{$clicksTable}` cl ON c.click_id = cl.click_id
                INNER JOIN campaigns cp ON cl.campaign_id = cp.id
                LEFT JOIN tracking_domains td ON cp.tracking_domain_id = td.id
                LEFT JOIN facebook_capi_integrations fci ON cp.facebook_capi_integration_id = fci.id
                " . ($hasOfferId ? "LEFT JOIN offers o ON cl.offer_id = o.id" : "") . "
                LEFT JOIN landing_pages lp ON cl.landing_page_id = lp.id
                WHERE c.id = ?"
            );
        } else {
            // Fallback: clicks table doesn't have traffic_source_id column yet
            // Use campaign's traffic_source_id instead
            $offerIdSelect = $hasOfferId ? "cl.offer_id," : "NULL as offer_id,";
            $stmt = $this->db->prepare(
                "SELECT 
                    c.*, 
                    cl.campaign_id,
                    NULL as click_traffic_source_id,
                    " . $offerIdSelect . "
                    cl.ip,
                    cl.ua,
                    cl.extra_json,
                    cl.referrer,
                    cl.landing_page_id,
                    cl.ts as click_ts,
                    cl.city,
                    cl.region,
                    cl.country,
                    " . $postalSelect . "
                    cp.traffic_source_id as campaign_traffic_source_id,
                    cp.tracking_domain_id,
                    cp.campaign_key,
                    cp.facebook_capi_integration_id,
                    " . $minPayoutSelect . "
                    cp.google_ads_integration_id,
                    cp.traffic_source_postbacks_json,
                    cp.name as campaign_name,
                    td.domain as tracking_domain,
                    fci.pixel_id as fb_pixel_id,
                    fci.access_token as fb_access_token,
                    fci.test_code as fb_test_code,
                    fci.event_type as fb_event_type,
                    o.url as offer_url,
                    lp.url as landing_page_url
                FROM conversions c
                INNER JOIN `{$clicksTable}` cl ON c.click_id = cl.click_id
                INNER JOIN campaigns cp ON cl.campaign_id = cp.id
                LEFT JOIN tracking_domains td ON cp.tracking_domain_id = td.id
                LEFT JOIN facebook_capi_integrations fci ON cp.facebook_capi_integration_id = fci.id
                " . ($hasOfferId ? "LEFT JOIN offers o ON cl.offer_id = o.id" : "") . "
                LEFT JOIN landing_pages lp ON cl.landing_page_id = lp.id
                WHERE c.id = ?"
            );
        }
        
        if (!$stmt) {
            error_log("PostbackDispatcher: Failed to prepare getConversionData query: " . $this->db->error);
            return null;
        }
        
        // Use string binding for BIGINT UNSIGNED conversion_id
        $conversionIdStr = (string)$conversionId;
        $stmt->bind_param('s', $conversionIdStr);
        if (!$stmt->execute()) {
            error_log("PostbackDispatcher: Failed to execute getConversionData query: " . $stmt->error);
            $stmt->close();
            return null;
        }
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();

        if ($data) {
            $data['extra_json'] = json_decode($data['extra_json'], true);
            
            // Decode traffic_source_postbacks_json if present
            $data['traffic_source_postbacks_json'] = $data['traffic_source_postbacks_json'] 
                ? json_decode($data['traffic_source_postbacks_json'], true) 
                : [];
            
            // Determine which traffic source to use for postbacks
            $effectiveTrafficSourceId = null;
            $isAutoDetect = empty($data['campaign_traffic_source_id']);
            
            if ($isAutoDetect) {
                // Auto-detect mode: use the click's detected traffic source
                $effectiveTrafficSourceId = !empty($data['click_traffic_source_id']) 
                    ? (int)$data['click_traffic_source_id'] 
                    : null;
            } else {
                // Explicit traffic source: use campaign's traffic source
                $effectiveTrafficSourceId = !empty($data['campaign_traffic_source_id']) 
                    ? (int)$data['campaign_traffic_source_id'] 
                    : null;
            }
            
            // For auto-detect campaigns, look up postback configs from JSON
            if ($isAutoDetect && $effectiveTrafficSourceId && !empty($data['traffic_source_postbacks_json'])) {
                // Get the traffic source name to determine its type
                $tsNameStmt = $this->db->prepare("SELECT name FROM traffic_sources WHERE id = ?");
                $tsNameStmt->bind_param('i', $effectiveTrafficSourceId);
                $tsNameStmt->execute();
                $tsNameResult = $tsNameStmt->get_result();
                $tsNameRow = $tsNameResult->fetch_assoc();
                $tsName = strtolower($tsNameRow['name'] ?? '');
                
                // Determine traffic source group/type
                $tsGroup = null;
                if (strpos($tsName, 'facebook') !== false) {
                    $tsGroup = 'facebook';
                } elseif (strpos($tsName, 'youtube') !== false) {
                    $tsGroup = 'youtube';
                } elseif (strpos($tsName, 'google') !== false) {
                    $tsGroup = 'google';
                } elseif (strpos($tsName, 'bing') !== false) {
                    $tsGroup = 'bing';
                }
                
                // Find the config for this traffic source group
                // The JSON stores configs keyed by traffic source ID, but we need to find the one for this group
                $tsPostbackConfig = null;
                foreach ($data['traffic_source_postbacks_json'] as $tsId => $config) {
                    // Get the traffic source name for this ID
                    $checkTsStmt = $this->db->prepare("SELECT name FROM traffic_sources WHERE id = ?");
                    $checkTsStmt->bind_param('i', $tsId);
                    $checkTsStmt->execute();
                    $checkTsResult = $checkTsStmt->get_result();
                    $checkTsRow = $checkTsResult->fetch_assoc();
                    $checkTsName = strtolower($checkTsRow['name'] ?? '');
                    
                    // Determine group for this traffic source
                    $checkTsGroup = null;
                    if (strpos($checkTsName, 'facebook') !== false) {
                        $checkTsGroup = 'facebook';
                    } elseif (strpos($checkTsName, 'youtube') !== false) {
                        $checkTsGroup = 'youtube';
                    } elseif (strpos($checkTsName, 'google') !== false) {
                        $checkTsGroup = 'google';
                    } elseif (strpos($checkTsName, 'bing') !== false) {
                        $checkTsGroup = 'bing';
                    }
                    
                    // If groups match, use this config
                    if ($checkTsGroup === $tsGroup && $checkTsGroup !== null) {
                        $tsPostbackConfig = $config;
                        break;
                    }
                }
                
                if ($tsPostbackConfig) {
                    // Override Facebook CAPI integration if specified for this traffic source group
                    if (!empty($tsPostbackConfig['facebook_capi_integration_id'])) {
                        // Load the Facebook CAPI integration
                        $fbIntegrationStmt = $this->db->prepare(
                            "SELECT pixel_id, access_token, test_code, event_type 
                             FROM facebook_capi_integrations 
                             WHERE id = ?"
                        );
                        $fbIntegrationStmt->bind_param('i', $tsPostbackConfig['facebook_capi_integration_id']);
                        $fbIntegrationStmt->execute();
                        $fbIntegrationResult = $fbIntegrationStmt->get_result();
                        $fbIntegration = $fbIntegrationResult->fetch_assoc();
                        
                        if ($fbIntegration) {
                            $data['fb_pixel_id'] = $fbIntegration['pixel_id'];
                            $data['fb_access_token'] = $fbIntegration['access_token'];
                            $data['fb_test_code'] = $fbIntegration['test_code'] ?? null;
                            $data['fb_event_type'] = $fbIntegration['event_type'] ?? 'Purchase';
                        }
                    }
                    
                    // Override Google Ads integration if specified
                    if (!empty($tsPostbackConfig['google_ads_integration_id'])) {
                        $data['google_ads_integration_id'] = (int)$tsPostbackConfig['google_ads_integration_id'];
                    }
                    
                    // Note: Custom postbacks remain campaign-level, not per-traffic-source
                }
            }
            
            // Build Facebook CAPI config from integration table
            if (!empty($data['fb_pixel_id']) && !empty($data['fb_access_token'])) {
                $data['facebook_capi_json'] = [
                    'pixel_id' => $data['fb_pixel_id'],
                    'access_token' => $data['fb_access_token'],
                    'test_event_code' => $data['fb_test_code'] ?? null,
                    'event_type' => $data['fb_event_type'] ?? 'Purchase',
                ];
            } else {
                $data['facebook_capi_json'] = null;
            }
            
            // Store effective traffic source ID for use in postback firing
            $data['effective_traffic_source_id'] = $effectiveTrafficSourceId;
        }

        return $data ?: null;
    }

    /**
     * Fire Facebook Conversions API event
     * Uses Facebook Graph API directly (similar to Facebook SDK structure)
     */
    private function fireFacebookCAPI(array $conversion): void
    {
        $config = $conversion['facebook_capi_json'];

        if (empty($config['pixel_id']) || empty($config['access_token'])) {
            return; // Not configured
        }

        // CRITICAL: Calculate event_time FIRST (before constructing fbc)
        // Use conversion_epoch_ms (same microtime clock as fbclid_first_seen_epoch_ms) when available
        $eventTimeMs = MetaCapiTimestamps::resolveEventTimeMs($conversion);
        $eventTime = (int) floor($eventTimeMs / 1000);

        // Build event source URL - use the offer/landing page domain origin (not tracker URL)
        // CRITICAL: Meta uses event_source_url to determine the "website" source for attribution
        // We must send the actual offer/landing page domain (e.g., vervesolarnow.org), not our tracker domain
        // Format: scheme + host + port (if present) + "/" (no path, no query string, no fragments)
        $eventSourceUrl = null;
        $originalUrl = null;
        
        // Priority 1: Use offer URL if available (user went to an offer)
        if (!empty($conversion['offer_id']) && !empty($conversion['offer_url'])) {
            $originalUrl = $conversion['offer_url'];
            $eventSourceUrl = $this->extractUrlOrigin($conversion['offer_url']);
            if ($eventSourceUrl) {
                error_log("Facebook CAPI: Using offer URL origin for conversion {$conversion['id']}: {$originalUrl} → {$eventSourceUrl}");
            }
        }
        
        // Priority 2: Use landing page URL if no offer (user went to landing page)
        if (empty($eventSourceUrl) && !empty($conversion['landing_page_id']) && !empty($conversion['landing_page_url'])) {
            $originalUrl = $conversion['landing_page_url'];
            $eventSourceUrl = $this->extractUrlOrigin($conversion['landing_page_url']);
            if ($eventSourceUrl) {
                error_log("Facebook CAPI: Using landing page URL origin for conversion {$conversion['id']}: {$originalUrl} → {$eventSourceUrl}");
            }
        }
        
        // Priority 3: Fallback to referrer or landing page URL (extract base domain only)
        // CRITICAL: Per CPVLab parity, we should NOT send tracker URLs with query strings
        // Only send base domain (scheme + host + port + "/")
        if (empty($eventSourceUrl)) {
            // Try referrer first (extract base domain)
            if (!empty($conversion['referrer']) && filter_var($conversion['referrer'], FILTER_VALIDATE_URL)) {
                $eventSourceUrl = $this->extractUrlOrigin($conversion['referrer']);
                if ($eventSourceUrl) {
                    error_log("Facebook CAPI: Using referrer origin for conversion {$conversion['id']}: {$conversion['referrer']} → {$eventSourceUrl}");
                }
            }
            
            // Try to get landing page URL from campaign (extract base domain)
            if (empty($eventSourceUrl) && !empty($conversion['campaign_id'])) {
                $lpStmt = $this->db->prepare(
                    "SELECT lp.url FROM landing_pages lp
                     INNER JOIN campaigns cp ON cp.landing_page_id = lp.id
                     WHERE cp.id = ? LIMIT 1"
                );
                if ($lpStmt) {
                    $lpStmt->bind_param('i', $conversion['campaign_id']);
                    if ($lpStmt->execute()) {
                        $lpResult = $lpStmt->get_result();
                        $lpRow = $lpResult->fetch_assoc();
                        if ($lpRow && !empty($lpRow['url'])) {
                            $eventSourceUrl = $this->extractUrlOrigin($lpRow['url']);
                            if ($eventSourceUrl) {
                                error_log("Facebook CAPI: Using campaign landing page origin for conversion {$conversion['id']}: {$lpRow['url']} → {$eventSourceUrl}");
                            }
                        }
                    }
                    $lpStmt->close();
                }
            }
            
            // Final fallback: use tracker domain base URL only (no paths, no query strings)
            // This is a last resort - ideally we should always have offer/landing page URL
            if (empty($eventSourceUrl)) {
                $trackingDomainUrl = null;
                if (!empty($conversion['tracking_domain'])) {
                    $trackingDomainUrl = $conversion['tracking_domain'];
                } else {
                    $trackingDomainUrl = BASE_URL;
                }
                // Extract base domain only (no paths, no query strings)
                $eventSourceUrl = $this->extractUrlOrigin($trackingDomainUrl);
                error_log("Facebook CAPI WARNING: Using tracker domain base URL as final fallback for conversion {$conversion['id']} (no offer/landing page URL available): {$eventSourceUrl}");
            }
        }
        
        // CRITICAL: Ensure event_source_url is base domain only (strip any query strings or paths if present)
        // This ensures parity with CPVLab which only sends base domain
        if (!empty($eventSourceUrl)) {
            $parsed = parse_url($eventSourceUrl);
            if ($parsed && !empty($parsed['scheme']) && !empty($parsed['host'])) {
                // Rebuild as base domain only (scheme + host + port + "/")
                $cleanUrl = $parsed['scheme'] . '://' . $parsed['host'];
                if (!empty($parsed['port'])) {
                    $cleanUrl .= ':' . $parsed['port'];
                }
                $cleanUrl .= '/';
                
                // Only update if URL was modified (had query string or path)
                if ($cleanUrl !== $eventSourceUrl) {
                    error_log("Facebook CAPI: Cleaned event_source_url for conversion {$conversion['id']}: {$eventSourceUrl} → {$cleanUrl}");
                    $eventSourceUrl = $cleanUrl;
                }
            }
        }
        
        // Log the final event_source_url for debugging
        if ($originalUrl) {
            error_log("Facebook CAPI event_source_url for conversion {$conversion['id']}: Original URL: {$originalUrl} → Meta URL: {$eventSourceUrl}");
        } else {
            error_log("Facebook CAPI event_source_url for conversion {$conversion['id']}: {$eventSourceUrl}");
        }
        
        // Prepare user data (Facebook SDK format)
        // Note: IP and UA are sent as plain text (Facebook hashes them server-side)
        // Email and phone must be hashed client-side with SHA256
        // CRITICAL: IP and UA are REQUIRED for proper attribution matching
        $userData = [];
        
        // IP address (sent as plain text, Facebook hashes it)
        // CRITICAL: Without IP, Facebook cannot match the conversion to the click
        // Facebook CAPI supports both IPv4 and IPv6 addresses
        // CRITICAL: Do NOT send null - either include valid IP or omit the field entirely
        // Reference: https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/server-event
        if (!empty($conversion['ip'])) {
            // Validate IP address (supports both IPv4 and IPv6)
            // Facebook accepts both formats, so we validate but don't convert
            if (filter_var($conversion['ip'], FILTER_VALIDATE_IP)) {
            $userData['client_ip_address'] = $conversion['ip'];
                
                // Log IP version for debugging
                if (filter_var($conversion['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    error_log("Facebook CAPI: Using IPv6 address for conversion {$conversion['id']}: " . substr($conversion['ip'], 0, 20) . "...");
                } else {
                    error_log("Facebook CAPI: Using IPv4 address for conversion {$conversion['id']}: {$conversion['ip']}");
                }
            } else {
                error_log("Facebook CAPI ERROR: Invalid IP address format for conversion {$conversion['id']}: {$conversion['ip']}");
                // Don't include invalid IP - Facebook will reject it (field will be omitted)
            }
        } else {
            error_log("Facebook CAPI WARNING: No IP address available for conversion {$conversion['id']} - attribution may fail! (Field will be omitted, not sent as null)");
        }
        
        // User agent (sent as plain text, Facebook hashes it)
        // CRITICAL: Without UA, Facebook has less data to match the conversion
        // CRITICAL: Do NOT send null - either include valid UA or omit the field entirely
        if (!empty($conversion['ua'])) {
            $userData['client_user_agent'] = $conversion['ua'];
        } else {
            error_log("Facebook CAPI WARNING: No user agent available for conversion {$conversion['id']} - attribution may be less accurate! (Field will be omitted, not sent as null)");
        }
        
        // Extract email/phone/fbclid from extra_json if available (for better matching)
        // These MUST be hashed with SHA256 before sending (except fbclid)
        if (!empty($conversion['extra_json'])) {
            $extra = $conversion['extra_json'];
            
            // Check common email fields (hash with SHA256)
            foreach (['email', 'em', 'e', 'user_email'] as $emailKey) {
                $email = null;
                if (!empty($extra['traffic_source_tokens'][$emailKey])) {
                    $email = trim($extra['traffic_source_tokens'][$emailKey]);
                } elseif (!empty($extra['all_params'][$emailKey])) {
                    $email = trim($extra['all_params'][$emailKey]);
                }
                
                if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    // Facebook requires email to be lowercase and hashed with SHA256
                    $userData['em'] = [hash('sha256', strtolower($email))];
                    break;
                }
            }
            
            // Check common phone fields (hash with SHA256, digits only)
            foreach (['phone', 'ph', 'tel', 'phone_number'] as $phoneKey) {
                $phone = null;
                if (!empty($extra['traffic_source_tokens'][$phoneKey])) {
                    $phone = $extra['traffic_source_tokens'][$phoneKey];
                } elseif (!empty($extra['all_params'][$phoneKey])) {
                    $phone = $extra['all_params'][$phoneKey];
                }
                
                if ($phone) {
                    // Remove non-digits, then hash with SHA256
                    $phoneDigits = preg_replace('/[^0-9]/', '', $phone);
                    if (!empty($phoneDigits)) {
                        $userData['ph'] = [hash('sha256', $phoneDigits)];
                        break;
                    }
                }
            }
            
            // Extract fbc parameter - CRITICAL for conversion attribution
            $fbc = MetaCapiTimestamps::buildFbc(
                $conversion,
                $extra,
                $eventTimeMs,
                (int) $conversion['id']
            );
            if ($fbc !== null) {
                $userData['fbc'] = $fbc;
            }

            MetaCapiTimestamps::logElapsedTime((int) $conversion['id'], $eventTime, $fbc);

            // Also check for _fbp cookie (Facebook Browser ID) - improves match quality
            // Reference: https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/fbp-and-fbc/
            // Format: fb.1.{timestamp}.{browser_id}
            $fbpCookie = null;
            if (!empty($extra['traffic_source_tokens']['_fbp'])) {
                $fbpCookie = trim($extra['traffic_source_tokens']['_fbp']);
            } elseif (!empty($extra['all_params']['_fbp'])) {
                $fbpCookie = trim($extra['all_params']['_fbp']);
            } elseif (!empty($extra['cookies']['_fbp'])) {
                $fbpCookie = trim($extra['cookies']['_fbp']);
            }
            
            if ($fbpCookie && $fbpCookie !== '') {
                // _fbp cookie format: fb.1.{timestamp}.{browser_id}
                // Use it directly (do NOT hash or modify - send as-is, just like fbc)
                // CRITICAL: Do NOT hash _fbp - Meta requires it in plain text format
                $userData['fbp'] = $fbpCookie;
                error_log("Facebook CAPI: Using _fbp cookie value for conversion {$conversion['id']}");
            }
        }
        
        // CRITICAL: Add hashed geo data for improved Event Match Quality (matches CPVLab)
        // Meta uses hashed location data (ct=city, st=state, country) as match keys.
        // Per Meta docs, these fields must be normalized before hashing:
        // - ct: lowercase, no punctuation/special characters/spaces (e.g. "new york" -> "newyork")
        // - st: US states should use 2-letter ANSI code (e.g. "California" -> "ca") when possible
        // - country: ISO 3166-1 alpha-2 lowercase (e.g. "US" -> "us")
        if (!empty($conversion['city'])) {
            $ctNormalized = $this->normalizeMetaCity($conversion['city']);
            if ($ctNormalized !== null) {
                $userData['ct'] = hash('sha256', $ctNormalized);
                error_log("Facebook CAPI: Added hashed city (ct) for conversion {$conversion['id']}");
            }
        }
        if (!empty($conversion['region'])) {
            $stNormalized = $this->normalizeMetaState($conversion['region'], $conversion['country'] ?? null);
            if ($stNormalized !== null) {
                $userData['st'] = hash('sha256', $stNormalized);
                error_log("Facebook CAPI: Added hashed state/region (st) for conversion {$conversion['id']}");
            }
        }
        if (!empty($conversion['country'])) {
            $countryNormalized = $this->normalizeMetaCountryCode($conversion['country']);
            if ($countryNormalized !== null) {
                $userData['country'] = hash('sha256', $countryNormalized);
                error_log("Facebook CAPI: Added hashed country for conversion {$conversion['id']}");
            }
        }
        // zp (zip/postal): Meta requires lowercase, no spaces, no dash; US use first 5 digits only
        if (!empty($conversion['postal'])) {
            $zpNormalized = $this->normalizeMetaZip($conversion['postal'], $conversion['country'] ?? null);
            if ($zpNormalized !== null) {
                $userData['zp'] = hash('sha256', $zpNormalized);
                error_log("Facebook CAPI: Added hashed zip (zp) for conversion {$conversion['id']}");
            }
        }
        
        // Prepare custom data (value and currency)
        // Facebook CAPI requires value and currency to be present in custom_data for Purchase events
        // Reference: https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/custom-data
        $customData = [];
        
        // Always include value - must be numeric (float)
        // CRITICAL: Use 'value' field first, but fallback to 'payout' if value is NULL/empty
        // This is because conversions may store the monetary amount in 'payout' instead of 'value'
        $conversionValue = $conversion['value'] ?? null;
        
        // If value is NULL/empty, use payout as fallback (payout is the actual revenue amount)
        if (($conversionValue === null || $conversionValue === '' || $conversionValue === false) && !empty($conversion['payout'])) {
            $conversionValue = $conversion['payout'];
        }
        
        if ($conversionValue === null || $conversionValue === '' || $conversionValue === false) {
            $customData['value'] = 0.0;
        } else {
            // Cast to float - handles string "0", "0.00", numeric 0, etc.
            $floatValue = (float)$conversionValue;
            // Ensure it's a valid number (not NaN or Infinity)
            $customData['value'] = is_finite($floatValue) ? $floatValue : 0.0;
        }
        
        // Always include currency - must be ISO 4217 three-letter code (uppercase)
        // Default to USD if NULL or empty
        $conversionCurrency = $conversion['currency'] ?? null;
        if (empty($conversionCurrency) || !is_string($conversionCurrency)) {
            $customData['currency'] = 'USD';
        } else {
            // Convert to uppercase and ensure it's exactly 3 characters (ISO 4217 format)
            $currencyUpper = strtoupper(trim($conversionCurrency));
            $customData['currency'] = (strlen($currencyUpper) === 3) ? $currencyUpper : 'USD';
        }
        
        // CRITICAL: Add order_id for identity consistency (matches CPVLab)
        // order_id must be the same as event_id (click_id) for proper deduplication
        // Meta uses order_id for commerce-style conversion identity
        $customData['order_id'] = $conversion['click_id'];
        
        // Build event data matching Facebook SDK structure
        
        // Get event type from integration config, default to 'Purchase'
        $eventType = $config['event_type'] ?? 'Purchase';
        
        // CRITICAL: Meta's Conversions API Requirements for Web Events (action_source = "website")
        // Reference: https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/server-event
        // REQUIRED parameters:
        // - action_source: "website" ✅
        // - event_source_url: URL where event occurred ✅ (now using offer/landing page domain origin, not tracker URL)
        // - client_user_agent: Browser user agent string (REQUIRED for web events)
        // - client_ip_address: User IP address (highly recommended for matching)
        // 
        // Validate required fields
        if (empty($userData['client_user_agent'])) {
            error_log("Facebook CAPI CRITICAL ERROR: client_user_agent is REQUIRED for web events (action_source='website') but is missing for conversion {$conversion['id']}!");
            error_log("Facebook CAPI: Event may be rejected or have poor matching without client_user_agent");
        }
        if (empty($userData['client_ip_address'])) {
            error_log("Facebook CAPI WARNING: client_ip_address is highly recommended but missing for conversion {$conversion['id']} - matching may be poor!");
        }
        if (empty($eventSourceUrl) || $eventSourceUrl === 'https://www.website.com/') {
            error_log("Facebook CAPI WARNING: event_source_url is using placeholder/default for conversion {$conversion['id']} - this may reduce attribution quality");
        }
        
        $eventData = [
            'data' => [[
                'event_name' => $eventType,
                'event_time' => $eventTime, // Unix timestamp of when conversion occurred
                'event_id' => $conversion['click_id'], // Always use click_id for consistency with CPVLab (same as order_id)
                'action_source' => 'website', // REQUIRED: Must be "website" for web events
                'event_source_url' => $eventSourceUrl, // REQUIRED for web events: URL where event occurred (using offer/landing page domain origin for proper Meta attribution)
                'user_data' => $userData, // Must include client_user_agent (REQUIRED) and client_ip_address (recommended)
                'custom_data' => $customData,
            ]],
            'access_token' => $config['access_token'],
        ];
        
        // Add test event code if configured (for testing in Events Manager)
        if (!empty($config['test_event_code'])) {
            $eventData['test_event_code'] = $config['test_event_code'];
        }

        // CRITICAL: Log fbc parameter for debugging (always log to help diagnose attribution issues)
        $fbcValue = $userData['fbc'] ?? 'NOT SET';
        error_log("Facebook CAPI fbc parameter for conversion {$conversion['id']}: " . $fbcValue);
        
        // Log the full event data structure for debugging (truncated if too long)
        $eventDataJson = json_encode($eventData);
        $eventDataPreview = strlen($eventDataJson) > 500 ? substr($eventDataJson, 0, 500) . '...' : $eventDataJson;
        error_log("Facebook CAPI event data for conversion {$conversion['id']}: " . $eventDataPreview);
        
        // Send POST request to Facebook Graph API
        $url = "https://graph.facebook.com/v21.0/{$config['pixel_id']}/events";
        $this->sendHttpRequest($url, 'facebook_capi', $conversion['id'], 'POST', $eventDataJson, 1);
    }

    /**
     * Replace macros in template
     */
    /**
     * Extract the origin (base domain) from a URL for Meta event_source_url
     * Format: scheme + "://" + host + (port ? ":" + port : "") + "/"
     * Examples:
     *   https://vervesolarnow.org/quote/?sub3=123 → https://vervesolarnow.org/
     *   http://sub.example.com:8443/path → http://sub.example.com:8443/
     * 
     * @param string $url The full URL (may include path, query string, fragments)
     * @return string|null The origin URL with trailing slash, or null if URL is invalid
     */
    private function extractUrlOrigin(string $url): ?string
    {
        if (empty($url) || !is_string($url)) {
            return null;
        }
        
        // Handle tokenized URLs - extract origin before token replacement
        // If URL contains tokens like {click_id}, we still want to extract the origin
        // Example: https://example.com/{click_id} → https://example.com/
        $url = trim($url);
        
        // Parse the URL
        $parsed = parse_url($url);
        
        // Validate that we have at least scheme and host
        if (empty($parsed['scheme']) || empty($parsed['host'])) {
            error_log("Facebook CAPI: Invalid URL format for origin extraction: {$url}");
            return null;
        }
        
        // Build origin: scheme + "://" + host + (port if present) + "/"
        $origin = $parsed['scheme'] . '://' . $parsed['host'];
        
        // Add port if present
        if (!empty($parsed['port'])) {
            $origin .= ':' . $parsed['port'];
        }
        
        // Always include trailing slash
        $origin .= '/';
        
        return $origin;
    }

    private function replaceMacros(string $template, array $conversion): string
    {
        // Prefer each field, then fall back so either affiliate payout or pixel value works.
        $valueAmount = $conversion['value'] ?? null;
        if ($valueAmount === null || $valueAmount === '' || $valueAmount === false) {
            $valueAmount = $conversion['payout'] ?? '0';
        }
        $payoutAmount = $conversion['payout'] ?? null;
        if ($payoutAmount === null || $payoutAmount === '' || $payoutAmount === false) {
            $payoutAmount = $conversion['value'] ?? '0';
        }

        $macros = [
            '{click_id}' => $conversion['click_id'],
            '{value}' => $valueAmount ?? '0',
            '{payout}' => $payoutAmount ?? '0',
            '{currency}' => $conversion['currency'] ?? 'USD',
            '{txid}' => $conversion['txid'] ?? '',
            '{status}' => $conversion['status'] ?? 'pending',
        ];

        // Add custom params from extra_json
        if (!empty($conversion['extra_json'])) {
            foreach ($conversion['extra_json'] as $key => $value) {
                $macros["{{$key}}"] = $value;
            }
        }

        return str_replace(array_keys($macros), array_values($macros), $template);
    }

    /**
     * Hash data for Facebook (SHA256)
     */
    private function hashData(?string $data): ?string
    {
        return $data ? hash('sha256', $data) : null;
    }
    
    /**
     * Normalize city per Meta requirements for ct:
     * - lowercase
     * - remove spaces, punctuation, special characters
     * - keep alphanumeric only
     */
    private function normalizeMetaCity(?string $city): ?string
    {
        if ($city === null) {
            return null;
        }
        
        $city = strtolower(trim($city));
        if ($city === '') {
            return null;
        }
        
        // Remove anything except a-z and 0-9 (Meta examples show "newyork" style)
        $city = preg_replace('/[^a-z0-9]/', '', $city);
        return $city !== '' ? $city : null;
    }
    
    /**
     * Normalize state/region per Meta requirements for st:
     * - US states: prefer 2-letter ANSI code (lowercase)
     * - otherwise: lowercase, remove spaces/punctuation/special characters
     */
    private function normalizeMetaState(?string $region, ?string $countryCode): ?string
    {
        if ($region === null) {
            return null;
        }
        
        $regionRaw = trim($region);
        if ($regionRaw === '') {
            return null;
        }
        
        $country = $this->normalizeMetaCountryCode($countryCode);
        
        // If US, attempt to map full state name -> 2-letter code
        if ($country === 'us') {
            $regionLower = strtolower($regionRaw);
            if (isset(self::US_STATE_NAME_TO_CODE[$regionLower])) {
                return self::US_STATE_NAME_TO_CODE[$regionLower];
            }
            
            // If already a 2-letter code, accept it after normalization
            $regionCompact = preg_replace('/[^a-z]/', '', $regionLower);
            if (strlen($regionCompact) === 2) {
                return $regionCompact;
            }
        }
        
        // Non-US or unmapped: normalize to lowercase alphanumeric without spaces/punct
        $regionLower = strtolower($regionRaw);
        $regionNormalized = preg_replace('/[^a-z0-9]/', '', $regionLower);
        return $regionNormalized !== '' ? $regionNormalized : null;
    }
    
    /**
     * Normalize zip/postal per Meta requirements for zp:
     * - lowercase, no spaces, no dash
     * - US: use first 5 digits only
     */
    private function normalizeMetaZip(?string $postal, ?string $countryCode): ?string
    {
        if ($postal === null) {
            return null;
        }
        $postal = trim($postal);
        if ($postal === '') {
            return null;
        }
        $country = $this->normalizeMetaCountryCode($countryCode);
        if ($country === 'us') {
            // US: first 5 digits only, no spaces/dash
            $digits = preg_replace('/[^0-9]/', '', $postal);
            if ($digits === '') {
                return null;
            }
            return strtolower(substr($digits, 0, 5));
        }
        // Non-US: lowercase, no spaces, no dash
        $zp = strtolower(preg_replace('/[\s\-]/', '', $postal));
        return $zp !== '' ? $zp : null;
    }

    /**
     * Normalize country code per Meta requirements for country:
     * - lowercase
     * - expects ISO 3166-1 alpha-2 when available
     */
    private function normalizeMetaCountryCode(?string $countryCode): ?string
    {
        if ($countryCode === null) {
            return null;
        }
        
        $cc = strtolower(trim($countryCode));
        if ($cc === '') {
            return null;
        }
        
        // keep only letters, prefer 2-char codes
        $cc = preg_replace('/[^a-z]/', '', $cc);
        if ($cc === '') {
            return null;
        }
        
        if (strlen($cc) === 2) {
            return $cc;
        }
        
        // If some provider returned full country name, we can't reliably map here.
        // Return as-is compacted; it will still be hashed but may match less often.
        return $cc;
    }

    /**
     * Send HTTP request with retry logic
     */
    private function sendHttpRequest(
        string $url,
        string $type,
        int $conversionId,
        string $method = 'GET',
        ?string $body = null,
        int $attempt = 1
    ): void {
        // Log request body for Facebook CAPI to help diagnose attribution issues
        if ($type === 'facebook_capi' && $body) {
            $bodyPreview = strlen($body) > 1000 ? substr($body, 0, 1000) . '...' : $body;
            error_log("Facebook CAPI REQUEST for conversion {$conversionId} (attempt {$attempt}): " . $bodyPreview);
        }
        
        $ch = curl_init($url);
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // Log the attempt
        $this->logPostback($type, $conversionId, $url, $httpCode, $response, $error, $attempt, $body);

        // Retry on failure
        if ($httpCode < 200 || $httpCode >= 300) {
            if ($attempt < self::MAX_RETRIES) {
                $delay = self::INITIAL_RETRY_DELAY * pow(2, $attempt - 1);
                sleep($delay);
                $this->sendHttpRequest($url, $type, $conversionId, $method, $body, $attempt + 1);
            }
        }
    }

    /**
     * Log postback attempt
     */
    private function logPostback(
        string $type,
        int $conversionId,
        string $url,
        int $httpCode,
        $response,
        ?string $error,
        int $attempt,
        ?string $requestBody = null
    ): void {
        // Log to file (for backward compatibility)
        $logFile = LOGS_PATH . '/postbacks.log';
        $timestamp = date('Y-m-d H:i:s');
        
        $logEntry = sprintf(
            "[%s] Type: %s, Conversion: %d, Attempt: %d, HTTP: %d, URL: %s, Error: %s\n",
            $timestamp,
            $type,
            $conversionId,
            $attempt,
            $httpCode,
            $url,
            $error ?: 'none'
        );

        @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
        
        // Also log to database for diagnostics
        $this->logPostbackToDatabase($type, $conversionId, $url, $httpCode, $response, $error, $attempt, $requestBody);
    }
    
    /**
     * Log postback attempt to database
     */
    private function logPostbackToDatabase(
        string $type,
        int $conversionId,
        string $url,
        int $httpCode,
        $response,
        ?string $error,
        int $attempt,
        ?string $requestBody = null
    ): void {
        try {
            // Check if table exists first
            $tableCheck = $this->db->query("SHOW TABLES LIKE 'postback_logs'");
            if (!$tableCheck || $tableCheck->num_rows === 0) {
                // Table doesn't exist yet, skip database logging
                error_log("Postback logging skipped: postback_logs table does not exist");
                return;
            }
            
            // Get click_id from conversion for easier lookup
            $clickId = null;
            $stmt = $this->db->prepare("SELECT click_id FROM conversions WHERE id = ?");
            if ($stmt) {
                // Use string binding for BIGINT UNSIGNED
                $conversionIdStr = (string)$conversionId;
                $stmt->bind_param('s', $conversionIdStr);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    if ($row = $result->fetch_assoc()) {
                        $clickId = $row['click_id'];
                        error_log("Postback logging: Retrieved click_id={$clickId} for conversion_id={$conversionId}");
                    } else {
                        error_log("Postback logging: No conversion found for conversion_id={$conversionId}");
                    }
                } else {
                    error_log("Postback logging: Failed to execute click_id lookup for conversion_id={$conversionId}: " . $stmt->error);
                }
                $stmt->close();
            } else {
                error_log("Postback logging: Failed to prepare click_id lookup: " . $this->db->error);
            }
            
            // Determine HTTP method from URL or default to GET
            $httpMethod = 'GET';
            if (strpos($url, 'graph.facebook.com') !== false) {
                $httpMethod = 'POST'; // Facebook CAPI uses POST
            }
            
            // Determine success (HTTP 200-299)
            $success = ($httpCode >= 200 && $httpCode < 300) ? 1 : 0;
            
            // Truncate response body if too long (max 5000 chars)
            $responseBody = null;
            if ($response !== null) {
                if (is_string($response)) {
                    $responseBody = $response;
                } elseif (is_array($response) || is_object($response)) {
                    $responseBody = json_encode($response);
                }
                if ($responseBody && strlen($responseBody) > 5000) {
                    $responseBody = substr($responseBody, 0, 5000) . '... [truncated]';
                }
            }
            
            // Truncate request body if too long (max 10000 chars for JSON payloads)
            $requestBodyStored = null;
            if ($requestBody !== null) {
                $requestBodyStored = $requestBody;
                if (strlen($requestBodyStored) > 10000) {
                    $requestBodyStored = substr($requestBodyStored, 0, 10000) . '... [truncated]';
                }
            }
            
            // Check if request_body column exists
            $requestBodyColumnExists = false;
            $columnCheck = $this->db->query("SHOW COLUMNS FROM postback_logs LIKE 'request_body'");
            if ($columnCheck && $columnCheck->num_rows > 0) {
                $requestBodyColumnExists = true;
            }
            
            // Insert into database
            $columns = "postback_type, conversion_id, click_id, url, http_method, http_code, success, attempt_number, error_message, response_body";
            $placeholders = "?, ?, ?, ?, ?, ?, ?, ?, ?, ?";
            $bindTypes = 'sssssiisss';
            $bindValues = [
                    $type,
                (string)$conversionId,  // BIGINT UNSIGNED - bind as string
                    $clickId,
                    $url,
                    $httpMethod,
                    $httpCode,
                    $success,
                    $attempt,
                    $error,
                    $responseBody
            ];
            
            // Add request_body if column exists
            if ($requestBodyColumnExists) {
                $columns .= ", request_body";
                $placeholders .= ", ?";
                $bindTypes .= 's';
                $bindValues[] = $requestBodyStored;
            }
            
            $stmt = $this->db->prepare(
                "INSERT INTO postback_logs 
                ({$columns})
                VALUES ({$placeholders})"
            );
            
            if ($stmt) {
                // Note: conversion_id is BIGINT UNSIGNED, so bind as string 's' not integer 'i'
                // Must use variables, not direct casts, for bind_param
                $stmt->bind_param($bindTypes, ...$bindValues);
                if ($stmt->execute()) {
                    // Successfully logged
                    $insertId = $stmt->insert_id;
                    error_log("Postback logged to database: id={$insertId}, type={$type}, conversion_id={$conversionId}, http_code={$httpCode}");
                } else {
                    error_log("Failed to execute postback log insert: " . $stmt->error . " | SQL State: " . $stmt->sqlstate);
                }
                $stmt->close();
            } else {
                error_log("Failed to prepare postback log insert: " . $this->db->error . " | SQL State: " . $this->db->sqlstate);
            }
        } catch (\Exception $e) {
            // Log error but don't break postback execution
            error_log("Failed to log postback to database: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
        }
    }

    /**
     * Fire custom postbacks for the campaign
     */
    private function fireCustomPostbacks(array $conversion): void
    {
        $campaignId = $conversion['campaign_id'] ?? null;
        if (!$campaignId) {
            return;
        }

        // Get custom postback IDs - campaign-level only (not per-traffic-source)
        $customPostbackIds = [];
        
        // Always use campaign-level custom postbacks
        $customPostbackEntity = new CustomPostback($this->db);
        $postbacks = $customPostbackEntity->getForCampaign((int)$campaignId);
        $customPostbackIds = array_column($postbacks, 'id');

        if (empty($customPostbackIds)) {
            return; // No custom postbacks configured
        }

        // Load the actual postback records
        $customPostbackEntity = new CustomPostback($this->db);
        $postbacks = [];
        foreach ($customPostbackIds as $postbackId) {
            $postback = $customPostbackEntity->getById((int)$postbackId);
            if ($postback) {
                $postbacks[] = $postback;
            }
        }

        if (empty($postbacks)) {
            return;
        }

        // Build context for token replacement
        $click = [
            'click_id' => $conversion['click_id'] ?? '',
            'country' => null, // Would need to join clicks table for geo data
            'region' => null,
            'city' => null,
            'extra_json' => json_encode($conversion['extra_json'] ?? []),
        ];

        // Get campaign data with traffic source
        $campaignStmt = $this->db->prepare(
            "SELECT c.*, ts.tokens_json as traffic_source_tokens_json 
             FROM campaigns c 
             LEFT JOIN traffic_sources ts ON c.traffic_source_id = ts.id 
             WHERE c.id = ?"
        );
        $campaignStmt->bind_param('i', $campaignId);
        $campaignStmt->execute();
        $campaignResult = $campaignStmt->get_result();
        $campaign = $campaignResult->fetch_assoc();
        
        // Decode traffic source tokens if available
        if ($campaign && !empty($campaign['traffic_source_tokens_json'])) {
            $campaign['traffic_source_tokens'] = json_decode($campaign['traffic_source_tokens_json'], true) ?? [];
        }

        // Get click geo data
        $clickStmt = $this->db->prepare("SELECT country, region, city FROM clicks WHERE click_id = ?");
        $clickId = $conversion['click_id'] ?? '';
        $clickStmt->bind_param('s', $clickId);
        $clickStmt->execute();
        $clickResult = $clickStmt->get_result();
        $clickData = $clickResult->fetch_assoc();
        if ($clickData) {
            $click['country'] = $clickData['country'] ?? null;
            $click['region'] = $clickData['region'] ?? null;
            $click['city'] = $clickData['city'] ?? null;
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
        
        // Replace tokens in each postback URL and fire
        $tokenReplacer = new TokenReplacer();
        $context = [
            'click' => $click,
            'campaign' => $campaign ?: [],
            'conversion' => $conversion,
            'all_traffic_sources' => $allTrafficSources,
        ];

        foreach ($postbacks as $postback) {
            $postbackUrl = $tokenReplacer->replace($postback['postback_url'], $context);
            
            // Fire HTTP request
            $this->sendHttpRequest(
                $postbackUrl,
                'custom_postback',
                $conversion['id'],
                'GET'
            );
        }
    }

    /**
     * Effective monetary amount for threshold checks (value, then payout).
     */
    public static function getEffectiveConversionAmount(array $conversion): ?float
    {
        $conversionValue = $conversion['value'] ?? null;
        if ($conversionValue !== null && $conversionValue !== '' && $conversionValue !== false) {
            $floatValue = (float)$conversionValue;
            return is_finite($floatValue) ? $floatValue : null;
        }
        $payout = $conversion['payout'] ?? null;
        if ($payout !== null && $payout !== '' && $payout !== false) {
            $floatPayout = (float)$payout;
            return is_finite($floatPayout) ? $floatPayout : null;
        }
        return null;
    }

    /**
     * Whether outbound postbacks should fire (optional campaign minimum).
     */
    private function shouldFireOutboundPostbacks(array $conversion): bool
    {
        $minPayout = $conversion['min_postback_payout'] ?? null;
        if ($minPayout === null || $minPayout === '' || $minPayout === false) {
            return true;
        }
        $minFloat = (float)$minPayout;
        if (!is_finite($minFloat) || $minFloat < 0) {
            return true;
        }
        $effective = self::getEffectiveConversionAmount($conversion);
        if ($effective === null) {
            return false;
        }
        return $effective >= $minFloat;
    }

    /**
     * Log that all outbound postbacks were skipped due to minimum payout threshold.
     */
    private function logSkippedBelowThreshold(array $conversion): void
    {
        $conversionId = (int)$conversion['id'];
        $minPayout = (float)($conversion['min_postback_payout'] ?? 0);
        $effective = self::getEffectiveConversionAmount($conversion);
        $effectiveDisplay = $effective !== null ? number_format($effective, 2, '.', '') : 'unknown';
        $minDisplay = number_format($minPayout, 2, '.', '');
        $message = "Skipped: payout/value \${$effectiveDisplay} below minimum \${$minDisplay}";
        error_log("PostbackDispatcher: {$message} for conversion_id={$conversionId}");

        $this->logPostbackToDatabase(
            'skipped_threshold',
            $conversionId,
            '(not sent — below minimum payout threshold)',
            0,
            null,
            $message,
            1,
            null
        );
    }
}


