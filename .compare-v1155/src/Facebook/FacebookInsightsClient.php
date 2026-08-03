<?php

declare(strict_types=1);

namespace SimpleKuma\Facebook;

use SimpleKuma\Http\ProxyHandler;
use SimpleKuma\Logger;

/**
 * Facebook Insights API Client
 * Handles API calls to Facebook Insights endpoint for fetching spend data
 */
class FacebookInsightsClient
{
    private string $accessToken;
    private string $apiVersion = 'v24.0'; // Updated to latest stable version
    private int $timeout = 30;
    private ?array $proxyConfig = null;
    private ?\SimpleKuma\Facebook\FacebookApiCallTracker $callTracker = null;
    private ?int $integrationId = null;
    private ?string $adAccountId = null;
    private ?Logger $logger = null;

    /** @var array<string, array{spend: float, campaign_id: ?string}> */
    private array $lastAdsetInsights = [];

    /** Current call purpose logged to facebook_api_calls.purpose */
    private ?string $callPurpose = null;

    public function __construct(string $accessToken, ?array $proxyConfig = null, ?\SimpleKuma\Facebook\FacebookApiCallTracker $callTracker = null, ?int $integrationId = null, ?Logger $logger = null)
    {
        $this->accessToken = $accessToken;
        $this->proxyConfig = $proxyConfig;
        $this->callTracker = $callTracker;
        $this->integrationId = $integrationId;
        $this->logger = $logger;
    }

    /**
     * Set ad account ID for tracking purposes
     */
    public function setAdAccountId(?string $adAccountId): void
    {
        $this->adAccountId = $adAccountId;
    }

    /**
     * Label for the next Graph request(s) (see FacebookApiCallTracker::purposeLabels).
     */
    public function setCallPurpose(?string $purpose): void
    {
        $this->callPurpose = $purpose;
    }

    /**
     * @return array<string, array{spend: float, campaign_id: ?string}>
     */
    public function getLastAdsetInsights(): array
    {
        return $this->lastAdsetInsights;
    }

    /**
     * List campaigns on an ad account (all effective statuses returned; caller filters ACTIVE).
     *
     * @return array<int, array{id: string, name: string, effective_status: string}>
     */
    public function fetchAdAccountCampaigns(string $adAccountId): array
    {
        if (strpos($adAccountId, 'act_') !== 0) {
            $adAccountId = 'act_' . $adAccountId;
        }

        $url = "https://graph.facebook.com/{$this->apiVersion}/{$adAccountId}/campaigns";
        $params = [
            'access_token' => $this->accessToken,
            'fields' => 'id,name,effective_status',
            'limit' => 500,
        ];

        $campaigns = [];
        $nextUrl = null;
        $pageCount = 0;
        $maxPages = 50;

        do {
            $pageCount++;
            $this->callPurpose = $pageCount === 1 ? 'campaign_list' : 'campaign_list_page';
            $response = $nextUrl
                ? $this->makeApiCall($nextUrl, [])
                : $this->makeApiCall($url, $params);

            if (!$response || !isset($response['data']) || !is_array($response['data'])) {
                break;
            }

            foreach ($response['data'] as $row) {
                $id = isset($row['id']) ? (string)$row['id'] : '';
                if ($id === '') {
                    continue;
                }
                $campaigns[] = [
                    'id' => $id,
                    'name' => (string)($row['name'] ?? 'Unknown'),
                    'effective_status' => (string)($row['effective_status'] ?? 'UNKNOWN'),
                ];
            }

            $nextUrl = $response['paging']['next'] ?? null;
        } while ($nextUrl && $pageCount < $maxPages);

        return $campaigns;
    }

    /**
     * @param array<int, string>|null $metaCampaignIds Meta campaign IDs to scope Insights (chunks of 50)
     */
    private function buildAdsetInsightsFilters(?array $metaCampaignIds): array
    {
        $filters = [
            ['field' => 'adset.effective_status', 'operator' => 'IN', 'value' => ['ACTIVE']],
        ];
        if (!empty($metaCampaignIds)) {
            $filters[] = [
                'field' => 'campaign.id',
                'operator' => 'IN',
                'value' => array_values(array_map('strval', $metaCampaignIds)),
            ];
        }
        return $filters;
    }

    /**
     * @param array<int, string>|null $metaCampaignIds
     */
    private function fetchAccountAdsetSpendPaged(string $adAccountId, string $date, ?array $metaCampaignIds): array
    {
        if (strpos($adAccountId, 'act_') !== 0) {
            $adAccountId = 'act_' . $adAccountId;
        }

        $url = "https://graph.facebook.com/{$this->apiVersion}/{$adAccountId}/insights";
        $results = [];
        $chunks = empty($metaCampaignIds) ? [null] : array_chunk(array_values($metaCampaignIds), 50);

        foreach ($chunks as $chunk) {
            $params = [
                'access_token' => $this->accessToken,
                'fields' => 'spend,adset_id,campaign_id',
                'level' => 'adset',
                'time_range' => json_encode(['since' => $date, 'until' => $date]),
                'filtering' => json_encode($this->buildAdsetInsightsFilters($chunk)),
            ];

            if ($this->logger) {
                $logParams = $params;
                if (isset($logParams['access_token'])) {
                    $logParams['access_token'] = substr($logParams['access_token'], 0, 10) . '...';
                }
                $this->logger->logDetail('Facebook API: Fetching account-level adset insights', [
                    'ad_account_id' => $adAccountId,
                    'date' => $date,
                    'meta_campaign_filter_count' => $chunk ? count($chunk) : 0,
                    'params' => $logParams,
                ]);
            }

            $nextUrl = null;
            $pageCount = 0;
            $maxPages = 75;

            do {
                $pageCount++;
                $this->callPurpose = $pageCount === 1 ? 'account_insights' : 'account_insights_page';
                $response = $nextUrl
                    ? $this->makeApiCall($nextUrl, [])
                    : $this->makeApiCall($url, $params);

                if (!$response || !isset($response['data']) || !is_array($response['data'])) {
                    break;
                }

                foreach ($response['data'] as $insight) {
                    $adsetId = isset($insight['adset_id']) ? (string)$insight['adset_id'] : '';
                    if ($adsetId === '') {
                        continue;
                    }
                    $spend = isset($insight['spend']) ? (float)$insight['spend'] : 0.0;
                    $campaignId = isset($insight['campaign_id']) ? (string)$insight['campaign_id'] : null;

                    if (isset($results[$adsetId])) {
                        $results[$adsetId]['spend'] += $spend;
                    } else {
                        $results[$adsetId] = ['spend' => $spend, 'campaign_id' => $campaignId];
                    }
                }

                $nextUrl = $response['paging']['next'] ?? null;
            } while ($nextUrl && $pageCount < $maxPages);
        }

        return $results;
    }

    /**
     * Fetch cumulative spend for an adset for the current day
     */
    public function fetchAdsetSpend(string $adsetId, string $date): ?float
    {
        $this->callPurpose = 'adset_fallback';
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$adsetId}/insights";
        
        $params = [
            'access_token' => $this->accessToken,
            'fields' => 'spend',
            'time_range' => json_encode([
                'since' => $date,
                'until' => $date
            ])
            // Note: 'level' parameter not needed when querying adset directly
        ];

        $response = $this->makeApiCall($url, $params);
        
        if (!$response || !isset($response['data'][0]['spend'])) {
            return null;
        }

        return (float)$response['data'][0]['spend'];
    }

    /**
     * Fetch cumulative spend for an ad for the current day
     */
    public function fetchAdSpend(string $adId, string $date): ?float
    {
        $this->callPurpose = 'ad_insights';
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$adId}/insights";
        
        $params = [
            'access_token' => $this->accessToken,
            'fields' => 'spend',
            'time_range' => json_encode([
                'since' => $date,
                'until' => $date
            ])
            // Note: 'level' parameter not needed when querying ad directly
        ];

        $response = $this->makeApiCall($url, $params);
        
        if (!$response || !isset($response['data'][0]['spend'])) {
            return null;
        }

        return (float)$response['data'][0]['spend'];
    }

    /**
     * Fetch account-level adset insights for the current day
     * Returns array of [adset_id => spend] for all adsets in the account
     * 
     * SIMPLIFIED: Always uses single-date query (today in ad account timezone)
     * This eliminates date filtering complexity and ensures reliable results
     * 
     * @param string $adAccountId Ad account ID (with or without 'act_' prefix)
     * @param string $date Date in Y-m-d format (today in ad account timezone)
     * @param string|null $sinceDate DEPRECATED: No longer used, kept for backward compatibility
     * @param string|null $untilDate DEPRECATED: No longer used, kept for backward compatibility
     * @param array<int, string>|null $metaCampaignIds Optional Meta campaign IDs for scoped Insights
     * @return array Associative array of adset_id => spend (float)
     */
    public function fetchAccountAdsetSpend(
        string $adAccountId,
        string $date,
        ?string $sinceDate = null,
        ?string $untilDate = null,
        ?array $metaCampaignIds = null
    ): array {
        unset($sinceDate, $untilDate);

        $this->lastAdsetInsights = $this->fetchAccountAdsetSpendPaged($adAccountId, $date, $metaCampaignIds);

        $spendMap = [];
        foreach ($this->lastAdsetInsights as $adsetId => $row) {
            $spendMap[$adsetId] = $row['spend'];
        }

        if ($this->logger) {
            $this->logger->logDetail('Facebook API: Final results summary', [
                'ad_account_id' => $adAccountId,
                'date' => $date,
                'total_adsets_found' => count($spendMap),
                'meta_campaign_filter_count' => $metaCampaignIds ? count($metaCampaignIds) : 0,
            ]);
        }

        return $spendMap;
    }

    /**
     * Fetch account-level ad insights for the current day
     * Returns array of [ad_id => ['spend' => float, 'adset_id' => string]] for all ads in the account
     * 
     * @param string $adAccountId Ad account ID (with or without 'act_' prefix)
     * @param string $date Date in Y-m-d format (for filtering results)
     * @param string|null $sinceDate Optional: Start date for API query (7-day range)
     * @param string|null $untilDate Optional: End date for API query (7-day range)
     * @return array Associative array of ad_id => ['spend' => float, 'adset_id' => string|null]
     */
    public function fetchAccountAdSpend(string $adAccountId, string $date, ?string $sinceDate = null, ?string $untilDate = null): array
    {
        // Ensure ad account ID has 'act_' prefix
        if (strpos($adAccountId, 'act_') !== 0) {
            $adAccountId = 'act_' . $adAccountId;
        }

        $url = "https://graph.facebook.com/{$this->apiVersion}/{$adAccountId}/insights";
        
        // Use date range if provided, otherwise use single date; filter to ACTIVE ads only to save API credits
        $params = [
            'access_token' => $this->accessToken,
            'fields' => 'spend,ad_id,adset_id,date_start,date_stop',
            'level' => 'ad',
            'time_range' => json_encode([
                'since' => $sinceDate ?? $date,
                'until' => $untilDate ?? $date
            ]),
            'filtering' => json_encode([['field' => 'ad.effective_status', 'operator' => 'IN', 'value' => ['ACTIVE']]])
        ];
        
        // Add time_increment=1 for date ranges to get daily breakdown instead of aggregated results
        if ($sinceDate !== null && $untilDate !== null && $sinceDate !== $untilDate) {
            $params['time_increment'] = 1;
        }

        $results = [];
        $nextUrl = null;
        $pageCount = 0;
        $maxPages = 75; // Safety limit to prevent infinite loops
        
        // Log API request details (sanitize token)
        $logParams = $params;
        if (isset($logParams['access_token'])) {
            $logParams['access_token'] = substr($logParams['access_token'], 0, 10) . '...';
        }
        error_log("Facebook API fetchAccountAdSpend: URL={$url}, Params=" . json_encode($logParams));

        do {
            $pageCount++;
            $this->callPurpose = $pageCount === 1 ? 'account_ad_insights' : 'account_ad_insights_page';
            if ($nextUrl) {
                // Follow pagination link
                $response = $this->makeApiCall($nextUrl, []);
            } else {
                // First page
        $response = $this->makeApiCall($url, $params);
            }
        
        if (!$response || !isset($response['data']) || !is_array($response['data'])) {
                // Log why we're breaking - API call failed or returned invalid response
                $responseType = $response === null ? 'null' : (is_array($response) ? 'array' : gettype($response));
                $hasData = isset($response['data']);
                $dataIsArray = isset($response['data']) && is_array($response['data']);
                error_log("Facebook API fetchAccountAdSpend: Breaking loop - response={$responseType}, has_data={$hasData}, data_is_array={$dataIsArray}");
                if (isset($response['error'])) {
                    error_log("Facebook API fetchAccountAdSpend: API Error=" . json_encode($response['error']));
                }
                break;
        }
        
        // Log raw response count before filtering
        $rawResponseCount = count($response['data']);
        error_log("Facebook API fetchAccountAdSpend: Page {$pageCount} - Raw response count={$rawResponseCount}");

            // Process this page of results
            // When using a date range with time_increment=1, the API returns one row per day per ad
            // with date_start indicating which day the spend is for. We filter to only use data for the target date.
            // When using a single date (sinceDate == untilDate), Facebook's time_range filter ensures
            // results are for that date. The date_start/date_stop fields represent when the
            // ad/adset was active (lifetime), not the spend date.
        foreach ($response['data'] as $insight) {
            $adId = $insight['ad_id'] ?? null;
            $spend = isset($insight['spend']) ? (float)$insight['spend'] : 0.0;
            $adsetId = $insight['adset_id'] ?? null;
            $insightDate = $insight['date_start'] ?? null; // date_start indicates which day this spend is for
            
            // If using a date range, filter to only use data for the target date
            if ($sinceDate !== null && $untilDate !== null && $sinceDate !== $untilDate) {
                // Using date range with time_increment=1 - filter by date_start matching target date
                if ($insightDate !== null && $insightDate !== $date) {
                    continue; // Skip this result - it's explicitly for a different date
                }
            }
            
                // Include all ads (including zero-spend) to prevent false "missing" ads
                // If using a date range, we've already filtered to only the target date
                if ($adId) {
                // If we already have this ad, sum the spend (in case of multiple pages)
                if (isset($results[$adId])) {
                    $results[$adId]['spend'] += $spend;
                } else {
                    $results[$adId] = [
                        'spend' => $spend,
                        'adset_id' => $adsetId
                    ];
                }
            }
        }

            // Check for next page
            $nextUrl = $response['paging']['next'] ?? null;
            
        } while ($nextUrl && $pageCount < $maxPages);
        
        // Log final results count
        error_log("Facebook API fetchAccountAdSpend: Final results count=" . count($results) . ", Total pages={$pageCount}");

        return $results;
    }

    /**
     * Make API call to Facebook Graph API with retry logic for rate limits
     */
    private function makeApiCall(string $url, array $params, int $retryCount = 0): ?array
    {
        // If URL already contains query parameters (pagination), use it as-is
        // Otherwise, append params as query string
        $fullUrl = !empty($params) ? ($url . '?' . http_build_query($params)) : $url;
        
        // Extract endpoint from URL for tracking
        $endpoint = parse_url($fullUrl, PHP_URL_PATH) ?? '/';
        // Remove API version prefix if present
        $endpoint = preg_replace('#^/v\d+\.\d+/#', '/', $endpoint);
        
        // Sanitize URL for logging (remove access_token)
        $logUrl = preg_replace('/[?&]access_token=[^&]*/', '', $fullUrl);
        
        // Sanitize params for logging
        $logParams = $params;
        if (isset($logParams['access_token'])) {
            $logParams['access_token'] = substr($logParams['access_token'], 0, 10) . '...';
        }
        
        // PRE-CALL LOGGING
        if ($this->logger) {
            $this->logger->logDetail("Facebook API: Pre-call setup", [
                'endpoint' => $endpoint,
                'url' => $logUrl,
                'params' => $logParams,
                'http_method' => 'GET',
                'proxy_configured' => $this->proxyConfig !== null,
                'proxy_config' => $this->proxyConfig ? [
                    'host' => $this->proxyConfig['host'] ?? 'N/A',
                    'port' => $this->proxyConfig['port'] ?? 'N/A',
                    'type' => $this->proxyConfig['type'] ?? 'N/A'
                ] : null,
                'timeout' => $this->timeout,
                'retry_attempt' => $retryCount,
                'ad_account_id' => $this->adAccountId,
                'integration_id' => $this->integrationId
            ]);
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_HEADER, true); // Include headers in response

        // Configure proxy if provided
        $proxyConfigured = false;
        if ($this->proxyConfig) {
            ProxyHandler::configureCurl($ch, $this->proxyConfig);
            $proxyConfigured = true;
        }
        
        // CURL SETUP LOGGING
        if ($this->logger) {
            $this->logger->logDetail("Facebook API: cURL setup complete", [
                'endpoint' => $endpoint,
                'curl_options' => [
                    'CURLOPT_RETURNTRANSFER' => true,
                    'CURLOPT_TIMEOUT' => $this->timeout,
                    'CURLOPT_SSL_VERIFYPEER' => true,
                    'CURLOPT_HEADER' => true
                ],
                'proxy_configured' => $proxyConfigured,
                'proxy_details' => $this->proxyConfig ? [
                    'host' => $this->proxyConfig['host'] ?? 'N/A',
                    'port' => $this->proxyConfig['port'] ?? 'N/A',
                    'type' => $this->proxyConfig['type'] ?? 'N/A'
                ] : null,
                'ssl_verification' => true
            ]);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlInfo = curl_getinfo($ch);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        
        // Get response headers and body
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headerText = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        // Parse response headers
        $responseHeaders = [];
        if ($headerText) {
            $headerLines = explode("\r\n", $headerText);
            $responseHeaders = array_slice($headerLines, 0, 5); // First 5 headers
        }
        
        curl_close($ch);
        
        // RESPONSE LOGGING (before error checks)
        if ($this->logger) {
            $bodyPreview = substr($body, 0, 500);
            $bodyPreview = preg_replace('/"access_token"\s*:\s*"[^"]*"/', '"access_token":"[REDACTED]"', $bodyPreview);
            
            $this->logger->logDetail("Facebook API: Response received", [
                'endpoint' => $endpoint,
                'http_status_code' => $httpCode,
                'response_headers' => $responseHeaders,
                'response_body_length' => strlen($body),
                'response_body_preview' => $bodyPreview,
                'curl_error' => $curlError ?: null,
                'curl_errno' => $curlErrno ? 0 : null,
                'curl_info' => [
                    'total_time' => $curlInfo['total_time'] ?? null,
                    'connect_time' => $curlInfo['connect_time'] ?? null,
                    'namelookup_time' => $curlInfo['namelookup_time'] ?? null,
                    'size_download' => $curlInfo['size_download'] ?? null
                ]
            ]);
        }

        // Check for cURL errors
        if ($curlError) {
            // CURL ERROR LOGGING
            if ($this->logger) {
                $this->logger->logDetail("Facebook API: cURL Error", [
                    'endpoint' => $endpoint,
                    'url' => $logUrl,
                    'curl_error_code' => $curlErrno,
                    'curl_error_message' => $curlError,
                    'curl_info' => [
                        'total_time' => $curlInfo['total_time'] ?? null,
                        'connect_time' => $curlInfo['connect_time'] ?? null,
                        'namelookup_time' => $curlInfo['namelookup_time'] ?? null,
                        'http_code' => $curlInfo['http_code'] ?? null
                    ],
                    'proxy_configured' => $proxyConfigured,
                    'network_issue' => true
                ]);
            }
            
            /**
             * Log failed API call (cURL error)
             * 
             * Even though the HTTP request failed due to a cURL error, Meta still
             * counts this as an API call attempt. We log it here to match Meta's
             * rate limit counting. This happens after curl_exec() (line 511), so
             * it represents an actual attempt to contact Meta's API.
             */
            if ($this->callTracker) {
                $this->callTracker->logCall(
                    $endpoint,
                    'GET',
                    $this->adAccountId,
                    $this->integrationId,
                    null,
                    false,
                    $retryCount > 0 ? 'rate_limit_retry' : ($this->callPurpose ?? 'other')
                );
            }
            
            return null;
        }

        // JSON DECODE LOGGING
        $jsonError = null;
        $data = json_decode($body ?: $response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $jsonError = json_last_error_msg();
            if ($this->logger) {
                $this->logger->logDetail("Facebook API: JSON Decode Error", [
                    'endpoint' => $endpoint,
                    'json_error' => $jsonError,
                    'json_error_code' => json_last_error(),
                    'raw_response_preview' => substr($body, 0, 500),
                    'response_length' => strlen($body)
                ]);
            }
            return null;
        }
        
        if ($this->logger && $data !== null) {
            $this->logger->logDetail("Facebook API: JSON decode successful", [
                'endpoint' => $endpoint,
                'has_data_key' => isset($data['data']),
                'has_error_key' => isset($data['error']),
                'data_count' => isset($data['data']) && is_array($data['data']) ? count($data['data']) : 0
            ]);
        }
        
        /**
         * CRITICAL: Log API call to match Meta's rate limit counting
         * 
         * This is called AFTER curl_exec() (line 511), which means it only tracks
         * actual HTTP requests to Meta's API. This matches exactly what Meta counts
         * against the 200 calls/hour rate limit.
         * 
         * What Meta counts:
         * - Each HTTP request = 1 call (this is what we log here)
         * - Retries = additional calls (each retry makes a new HTTP request, logged separately)
         * - Failed requests = still counted (logged at line 575 for cURL errors)
         * 
         * What Meta does NOT count:
         * - Log entries (internal logging only)
         * - Function calls (internal operations)
         * - Setup/preparation code
         * 
         * The settings page displays this count, which matches Meta's rate limit tracking.
         */
        if ($this->callTracker) {
            $success = ($httpCode === 200 && !isset($data['error']));
            $this->callTracker->logCall(
                $endpoint,
                'GET',
                $this->adAccountId,
                $this->integrationId,
                $httpCode,
                $success,
                $retryCount > 0 ? 'rate_limit_retry' : ($this->callPurpose ?? 'other')
            );
        }
        
        // Check for rate limit error (error code 17)
        if ($httpCode !== 200) {
            if (isset($data['error']) && isset($data['error']['code']) && $data['error']['code'] == 17) {
                /**
                 * Rate limit error - retry with exponential backoff
                 * 
                 * IMPORTANT: Each retry makes a new HTTP request to Meta's API, which
                 * Meta counts as a separate API call. When we recursively call makeApiCall()
                 * below, it will execute curl_exec() again and log another call via logCall().
                 * This is correct behavior - Meta counts retries against the rate limit.
                 */
                if ($retryCount < 3) {
                    $waitTime = 60 + rand(0, 60); // Random wait between 60-120 seconds
                    if ($this->logger) {
                        $this->logger->logDetail("Facebook API: Rate Limit (code 17) - Retrying", [
                            'endpoint' => $endpoint,
                            'url' => $logUrl,
                            'wait_time_seconds' => $waitTime,
                            'retry_attempt' => $retryCount + 1,
                            'max_retries' => 3,
                            'retryable' => true
                        ]);
                    }
                    sleep($waitTime);
                    // Recursive call will make a new HTTP request and log it separately
                    return $this->makeApiCall($fullUrl, [], $retryCount + 1);
                } else {
                    if ($this->logger) {
                        $this->logger->logDetail("Facebook API: Rate Limit - Max retries reached", [
                            'endpoint' => $endpoint,
                            'url' => $logUrl,
                            'retry_attempts' => $retryCount,
                            'max_retries' => 3
                        ]);
                    }
                    return null;
                }
            }
            
            // HTTP ERROR LOGGING
            $errorBody = substr($body, 0, 500); // Limit to 500 chars
            $errorBody = preg_replace('/"access_token"\s*:\s*"[^"]*"/', '"access_token":"[REDACTED]"', $errorBody);
            
            if ($this->logger) {
                $this->logger->logDetail("Facebook API: HTTP Error", [
                    'endpoint' => $endpoint,
                    'url' => $logUrl,
                    'http_status_code' => $httpCode,
                    'error_response_body' => $errorBody,
                    'response_headers' => $responseHeaders,
                    'retryable' => false,
                    'has_api_error' => isset($data['error'])
                ]);
            }
            
            return null;
        }
        
        if (isset($data['error'])) {
            $errorCode = $data['error']['code'] ?? 0;
            $errorMessage = $data['error']['message'] ?? 'Unknown error';
            $errorType = $data['error']['type'] ?? 'Unknown';
            $errorSubcode = $data['error']['error_subcode'] ?? null;
            $errorUserTitle = $data['error']['error_user_title'] ?? null;
            $errorUserMessage = $data['error']['error_user_message'] ?? null;
            
            // Sanitize error object for logging
            $errorObject = $data['error'];
            if (isset($errorObject['access_token'])) {
                $errorObject['access_token'] = '[REDACTED]';
            }
            
            // API ERROR LOGGING
            if ($this->logger) {
                $this->logger->logDetail("Facebook API: API Error", [
                    'endpoint' => $endpoint,
                    'url' => $logUrl,
                    'error_code' => $errorCode,
                    'error_type' => $errorType,
                    'error_message' => $errorMessage,
                    'error_subcode' => $errorSubcode,
                    'error_user_title' => $errorUserTitle,
                    'error_user_message' => $errorUserMessage,
                    'error_object' => $errorObject,
                    'retryable' => ($errorCode == 17 && $retryCount < 3)
                ]);
            }
            
            // Classify error
            if ($errorCode == 17) {
                /**
                 * Rate limit - retry
                 * 
                 * IMPORTANT: Each retry makes a new HTTP request to Meta's API, which
                 * Meta counts as a separate API call. When we recursively call makeApiCall()
                 * below, it will execute curl_exec() again and log another call via logCall().
                 * This is correct behavior - Meta counts retries against the rate limit.
                 */
                if ($retryCount < 3) {
                    $waitTime = 60 + rand(0, 60);
                    if ($this->logger) {
                        $this->logger->logDetail("Facebook API: Rate Limit (code 17) - Retrying", [
                            'endpoint' => $endpoint,
                            'url' => $logUrl,
                            'wait_time_seconds' => $waitTime,
                            'retry_attempt' => $retryCount + 1,
                            'max_retries' => 3,
                            'retryable' => true
                        ]);
                    }
                    sleep($waitTime);
                    // Recursive call will make a new HTTP request and log it separately
                    return $this->makeApiCall($fullUrl, [], $retryCount + 1);
                }
            }
            
            return null;
        }

        return $data;
    }
    
    /**
     * Check response headers for rate limit info
     */
    private function parseRateLimitHeaders(array $headers): array
    {
        $rateLimitInfo = [];
        
        // Look for X-App-Usage and X-Ad-Account-Usage headers
        foreach ($headers as $header) {
            if (stripos($header, 'X-App-Usage:') !== false) {
                $rateLimitInfo['app_usage'] = $header;
            }
            if (stripos($header, 'X-Ad-Account-Usage:') !== false) {
                $rateLimitInfo['ad_account_usage'] = $header;
            }
        }
        
        return $rateLimitInfo;
    }

    private array $lastRateLimitInfo = [];

    /**
     * Get rate limit headers from last response
     */
    public function getRateLimitInfo(): array
    {
        return $this->lastRateLimitInfo;
    }

    /**
     * Fetch ad details (including adset_id) for a batch of ad IDs
     * Used for recovery when adset_id is missing from clicks
     * 
     * @param array $adIds Array of numeric ad IDs
     * @param int $batchSize Maximum number of ads to fetch per API call (default: 50)
     * @return array Associative array of ad_id => adset_id (only valid numeric adset_ids)
     */
    public function fetchAdDetailsBatch(array $adIds, int $batchSize = 50): array
    {
        if (empty($adIds)) {
            return [];
        }

        // Filter to only numeric ad IDs
        $validAdIds = array_filter($adIds, function($id) {
            return ctype_digit((string)$id);
        });

        if (empty($validAdIds)) {
            return [];
        }

        $recoveredAdsets = [];
        $batches = array_chunk($validAdIds, $batchSize);

        foreach ($batches as $batchIndex => $batch) {
            // Use batch request API: /?ids=id1,id2,id3&fields=adset_id
            // Facebook Graph API supports batch requests where response is an object with ID keys
            $this->callPurpose = 'adset_recovery';
            $idsParam = implode(',', $batch);
            $url = "https://graph.facebook.com/{$this->apiVersion}/?ids={$idsParam}";
            
            $params = [
                'access_token' => $this->accessToken,
                'fields' => 'adset_id'
            ];

            $response = $this->makeApiCall($url, $params);

            if ($response && is_array($response)) {
                foreach ($response as $adId => $adData) {
                    // Check for API errors in response
                    if (isset($adData['error'])) {
                        error_log("Facebook API fetchAdDetailsBatch: Error for ad_id={$adId}: " . json_encode($adData['error']));
                        continue;
                    }
                    
                    if (isset($adData['adset_id']) && ctype_digit((string)$adData['adset_id'])) {
                        $recoveredAdsets[(string)$adId] = (string)$adData['adset_id'];
                    }
                }
            } else {
                error_log("Facebook API fetchAdDetailsBatch: Invalid response for batch " . ($batchIndex + 1) . " (batch size: " . count($batch) . ")");
            }
        }

        return $recoveredAdsets;
    }
}

