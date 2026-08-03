<?php

declare(strict_types=1);

namespace SimpleKuma\Facebook;

use mysqli;

/**
 * Facebook API Call Tracker
 * Tracks API calls to monitor rate limits (200 calls per hour)
 */
class FacebookApiCallTracker
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public const LAST_RUN_SETTING_KEY = 'facebook_cost_sync_last_run';

    /**
     * Log an API call
     * 
     * IMPORTANT: This should ONLY be called after an actual HTTP request to Meta's API
     * (i.e., after curl_exec()). Each call to this method represents one API call that
     * Meta counts against the rate limit. Do NOT call this for:
     * - Log entries (internal logging)
     * - Function calls (internal operations)
     * - Setup/preparation code
     * 
     * This method is called from FacebookInsightsClient::makeApiCall() after the HTTP
     * request completes, ensuring we only track actual API calls that Meta counts.
     * 
     * @param string $endpoint The API endpoint (e.g., "/act_123/insights")
     * @param string $method HTTP method (usually 'GET')
     * @param string|null $adAccountId Ad account ID
     * @param int|null $integrationId Integration ID
     * @param int|null $responseCode HTTP response code
     * @param bool $success Whether the call was successful
     * @param string|null $purpose Why the call was made (account_insights, adset_fallback, …)
     */
    public function logCall(
        string $endpoint,
        string $method = 'GET',
        ?string $adAccountId = null,
        ?int $integrationId = null,
        ?int $responseCode = null,
        bool $success = true,
        ?string $purpose = null
    ): void {
        // mysqli 'b' is BLOB, not boolean — always bind success as integer (Google tracker does the same).
        $successInt = $success ? 1 : 0;
        $responseCodeInt = $responseCode !== null ? (int)$responseCode : 0;
        $purposeValue = ($purpose !== null && $purpose !== '') ? substr($purpose, 0, 64) : '';

        if ($this->hasPurposeColumn()) {
            $stmt = $this->db->prepare(
                "INSERT INTO facebook_api_calls
                 (endpoint, method, ad_account_id, integration_id, response_code, success, purpose, called_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            if (!$stmt) {
                return;
            }
            $stmt->bind_param(
                'sssiiis',
                $endpoint,
                $method,
                $adAccountId,
                $integrationId,
                $responseCodeInt,
                $successInt,
                $purposeValue
            );
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO facebook_api_calls
                 (endpoint, method, ad_account_id, integration_id, response_code, success, called_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())"
            );
            if (!$stmt) {
                return;
            }
            $stmt->bind_param(
                'sssiii',
                $endpoint,
                $method,
                $adAccountId,
                $integrationId,
                $responseCodeInt,
                $successInt
            );
        }

        $stmt->execute();
        $stmt->close();
    }

    private ?bool $hasPurposeColumn = null;

    private function hasPurposeColumn(): bool
    {
        if ($this->hasPurposeColumn !== null) {
            return $this->hasPurposeColumn;
        }
        $result = $this->db->query("SHOW COLUMNS FROM facebook_api_calls LIKE 'purpose'");
        $this->hasPurposeColumn = $result && $result->num_rows > 0;
        return $this->hasPurposeColumn;
    }

    /**
     * Get API call count for the last hour
     * 
     * Returns the count of actual HTTP requests made to Meta's API in the last hour.
     * This matches Meta's rate limit counting (200 calls/hour limit).
     * Each row in facebook_api_calls represents one HTTP request to Meta's API.
     * 
     * @param int|null $integrationId Optional: Filter by integration ID
     * @return int Number of API calls in the last hour
     */
    public function getCallsThisHour(?int $integrationId = null): int
    {
        $sql = "SELECT COUNT(*) as count 
                FROM facebook_api_calls 
                WHERE called_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";
        
        $params = [];
        $types = '';
        
        if ($integrationId !== null) {
            $sql .= " AND integration_id = ?";
            $params[] = $integrationId;
            $types .= 'i';
        }
        
        $stmt = $this->db->prepare($sql);
        
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        return (int)($row['count'] ?? 0);
    }

    /**
     * Get API call count for today
     * 
     * Returns the count of actual HTTP requests made to Meta's API today.
     * This matches Meta's rate limit counting. Each row in facebook_api_calls
     * represents one HTTP request to Meta's API.
     * 
     * @param int|null $integrationId Optional: Filter by integration ID
     * @return int Number of API calls today
     */
    public function getCallsToday(?int $integrationId = null): int
    {
        $sql = "SELECT COUNT(*) as count 
                FROM facebook_api_calls 
                WHERE DATE(called_at) = CURDATE()";
        
        $params = [];
        $types = '';
        
        if ($integrationId !== null) {
            $sql .= " AND integration_id = ?";
            $params[] = $integrationId;
            $types .= 'i';
        }
        
        $stmt = $this->db->prepare($sql);
        
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        return (int)($row['count'] ?? 0);
    }

    /**
     * Get API call count for this month
     * 
     * Returns the count of actual HTTP requests made to Meta's API this month.
     * This matches Meta's rate limit counting. Each row in facebook_api_calls
     * represents one HTTP request to Meta's API.
     * 
     * @param int|null $integrationId Optional: Filter by integration ID
     * @return int Number of API calls this month
     */
    public function getCallsThisMonth(?int $integrationId = null): int
    {
        $sql = "SELECT COUNT(*) as count 
                FROM facebook_api_calls 
                WHERE YEAR(called_at) = YEAR(CURDATE()) 
                AND MONTH(called_at) = MONTH(CURDATE())";
        
        $params = [];
        $types = '';
        
        if ($integrationId !== null) {
            $sql .= " AND integration_id = ?";
            $params[] = $integrationId;
            $types .= 'i';
        }
        
        $stmt = $this->db->prepare($sql);
        
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        return (int)($row['count'] ?? 0);
    }

    /**
     * Get all call statistics (hour, day, month)
     * 
     * Returns statistics showing actual HTTP requests made to Meta's API.
     * These counts match exactly what Meta counts against the rate limit.
     * 
     * @param int|null $integrationId Optional: Filter by integration ID
     * @return array Statistics with keys: this_hour, today, this_month, limit_per_hour
     */
    public function getCallStats(?int $integrationId = null): array
    {
        return [
            'this_hour' => $this->getCallsThisHour($integrationId),
            'today' => $this->getCallsToday($integrationId),
            'this_month' => $this->getCallsThisMonth($integrationId),
            'failed_today' => $this->getFailedToday($integrationId),
            'endpoints_this_hour' => $this->getEndpointBreakdownThisHour($integrationId),
            'limit_per_hour' => 200
        ];
    }

    /**
     * Per-endpoint call counts for the rolling last hour (for API Usage audit).
     *
     * Endpoints are normalized so ad-account and object IDs collapse for grouping.
     *
     * @return array<int, array{endpoint: string, count: int}>
     */
    public function getEndpointBreakdownThisHour(?int $integrationId = null, int $limit = 10): array
    {
        $sql = "SELECT endpoint, COUNT(*) AS count
                FROM facebook_api_calls
                WHERE called_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";
        $params = [];
        $types = '';

        if ($integrationId !== null) {
            $sql .= ' AND integration_id = ?';
            $params[] = $integrationId;
            $types .= 'i';
        }

        $sql .= ' GROUP BY endpoint ORDER BY count DESC LIMIT ?';
        $params[] = $limit;
        $types .= 'i';

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $grouped = [];
        while ($row = $result->fetch_assoc()) {
            $normalized = $this->normalizeEndpointForDisplay((string)($row['endpoint'] ?? ''));
            if ($normalized === '') {
                $normalized = '(unknown)';
            }
            if (!isset($grouped[$normalized])) {
                $grouped[$normalized] = 0;
            }
            $grouped[$normalized] += (int)($row['count'] ?? 0);
        }
        $stmt->close();

        arsort($grouped);
        $out = [];
        foreach ($grouped as $endpoint => $count) {
            $out[] = ['endpoint' => $endpoint, 'count' => $count];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    private function normalizeEndpointForDisplay(string $endpoint): string
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return '';
        }
        // Collapse ad account and object IDs for readable grouping
        $endpoint = preg_replace('#/act_\d+#', '/act_*', $endpoint) ?? $endpoint;
        $endpoint = preg_replace('#/\d{6,}#', '/*', $endpoint) ?? $endpoint;
        return $endpoint;
    }

    /**
     * Infer purpose from endpoint when purpose was not logged (pre-deploy rows).
     */
    public function inferPurposeFromEndpoint(string $endpoint): string
    {
        $normalized = $this->normalizeEndpointForDisplay($endpoint);
        if ($normalized === '/act_*/insights' || str_contains($normalized, '/act_*/insights')) {
            return 'account_insights';
        }
        if ($normalized === '/*/insights' || preg_match('#^/\*/insights#', $normalized)) {
            return 'adset_fallback';
        }
        if (str_contains($normalized, '/campaigns')) {
            return 'campaign_list';
        }
        if ($normalized === '/' || $normalized === '') {
            return 'adset_recovery';
        }
        return 'other';
    }

    /**
     * Effective success for UI: trust HTTP 200 when the legacy success flag was wrongly stored as 0.
     */
    private function resolveCallSuccess(array $row): bool
    {
        if (((int)($row['success'] ?? 0)) === 1) {
            return true;
        }
        $code = (int)($row['response_code'] ?? 0);
        // Legacy mysqli bind bug stored success=0 for every row; HTTP 200 means Graph accepted the call.
        return $code === 200;
    }

    public function getFailedToday(?int $integrationId = null): int
    {
        // Exclude legacy rows where success was wrongly stored as 0 but Graph returned HTTP 200.
        $sql = "SELECT COUNT(*) as count
                FROM facebook_api_calls
                WHERE DATE(called_at) = CURDATE()
                  AND success = 0
                  AND (response_code IS NULL OR response_code = 0 OR response_code >= 400)";

        $params = [];
        $types = '';

        if ($integrationId !== null) {
            $sql .= ' AND integration_id = ?';
            $params[] = $integrationId;
            $types .= 'i';
        }

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return 0;
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return (int)($row['count'] ?? 0);
    }

    /**
     * Human-readable labels for call purposes (Settings diagnostics modal).
     *
     * @return array<string, string>
     */
    public static function purposeLabels(): array
    {
        return [
            'account_insights' => 'Account-level adset Insights',
            'account_insights_page' => 'Account Insights pagination',
            'account_ad_insights' => 'Account-level ad Insights',
            'account_ad_insights_page' => 'Account ad Insights pagination',
            'adset_fallback' => 'Per-adset Insights fallback',
            'adset_recovery' => 'Adset recovery (batch ads)',
            'ad_insights' => 'Per-ad Insights',
            'campaign_list' => 'Meta campaign list (UI sync)',
            'campaign_list_page' => 'Meta campaign list pagination',
            'rate_limit_retry' => 'Rate-limit retry',
            'other' => 'Other / unclassified',
        ];
    }

    /**
     * @return array<int, array{purpose: string, label: string, count: int, success: int, failed: int}>
     */
    public function getPurposeBreakdownBetween(string $startedAt, string $finishedAt): array
    {
        if (!$this->hasPurposeColumn()) {
            return $this->getEndpointBreakdownBetween($startedAt, $finishedAt);
        }

        $sql = "SELECT
                    COALESCE(NULLIF(purpose, ''), 'other') AS purpose,
                    COUNT(*) AS count,
                    SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) AS success_count,
                    SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) AS failed_count
                FROM facebook_api_calls
                WHERE called_at >= ? AND called_at <= ?
                GROUP BY COALESCE(NULLIF(purpose, ''), 'other')
                ORDER BY count DESC";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('ss', $startedAt, $finishedAt);
        $stmt->execute();
        $result = $stmt->get_result();
        $labels = self::purposeLabels();
        $grouped = [];
        while ($row = $result->fetch_assoc()) {
            $purpose = (string)($row['purpose'] ?? 'other');
            // When grouping by purpose only, empty purpose stays other — endpoint inference
            // is handled in getPurposeBreakdownThisHour / getRecentCalls for legacy rows.
            if (!isset($grouped[$purpose])) {
                $grouped[$purpose] = ['count' => 0, 'success' => 0, 'failed' => 0];
            }
            $grouped[$purpose]['count'] += (int)($row['count'] ?? 0);
            $grouped[$purpose]['success'] += (int)($row['success_count'] ?? 0);
            $grouped[$purpose]['failed'] += (int)($row['failed_count'] ?? 0);
        }
        $stmt->close();

        // If everything is blank/other, fall back to endpoint inference for this window.
        if (count($grouped) === 1 && isset($grouped['other'])) {
            return $this->getEndpointBreakdownBetween($startedAt, $finishedAt);
        }

        arsort($grouped);
        $out = [];
        foreach ($grouped as $purpose => $counts) {
            $out[] = [
                'purpose' => $purpose,
                'label' => $labels[$purpose] ?? $purpose,
                'count' => $counts['count'],
                'success' => $counts['success'],
                'failed' => $counts['failed'],
            ];
        }

        return $out;
    }

    /**
     * Fallback when purpose column is not migrated yet — group by normalized endpoint.
     *
     * @return array<int, array{purpose: string, label: string, count: int, success: int, failed: int}>
     */
    private function getEndpointBreakdownBetween(string $startedAt, string $finishedAt): array
    {
        $sql = "SELECT endpoint, COUNT(*) AS count,
                       SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) AS success_count,
                       SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) AS failed_count
                FROM facebook_api_calls
                WHERE called_at >= ? AND called_at <= ?
                GROUP BY endpoint
                ORDER BY count DESC
                LIMIT 20";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ss', $startedAt, $finishedAt);
        $stmt->execute();
        $result = $stmt->get_result();
        $grouped = [];
        while ($row = $result->fetch_assoc()) {
            $normalized = $this->normalizeEndpointForDisplay((string)($row['endpoint'] ?? ''));
            if ($normalized === '') {
                $normalized = '(unknown)';
            }
            $purpose = $this->inferPurposeFromEndpoint((string)($row['endpoint'] ?? ''));
            $key = $purpose;
            if (!isset($grouped[$key])) {
                $grouped[$key] = ['count' => 0, 'success' => 0, 'failed' => 0, 'label' => null];
            }
            $grouped[$key]['count'] += (int)($row['count'] ?? 0);
            $grouped[$key]['success'] += (int)($row['success_count'] ?? 0);
            $grouped[$key]['failed'] += (int)($row['failed_count'] ?? 0);
        }
        $stmt->close();

        $labels = self::purposeLabels();
        arsort($grouped);
        $out = [];
        foreach ($grouped as $purpose => $counts) {
            $out[] = [
                'purpose' => $purpose,
                'label' => $labels[$purpose] ?? $purpose,
                'count' => $counts['count'],
                'success' => $counts['success'],
                'failed' => $counts['failed'],
            ];
        }
        return $out;
    }

    /**
     * Recent individual Graph calls for the diagnostics modal.
     *
     * @return array<int, array{called_at: string, endpoint: string, purpose: string, purpose_label: string, ad_account_id: ?string, response_code: int, success: bool}>
     */
    public function getRecentCalls(int $limit = 40, ?string $since = null): array
    {
        $limit = max(1, min(100, $limit));
        $hasPurpose = $this->hasPurposeColumn();
        $purposeSelect = $hasPurpose ? 'purpose' : 'NULL AS purpose';

        if ($since !== null && $since !== '') {
            $sql = "SELECT called_at, endpoint, {$purposeSelect}, ad_account_id, response_code, success
                    FROM facebook_api_calls
                    WHERE called_at >= ?
                    ORDER BY called_at DESC
                    LIMIT ?";
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                return [];
            }
            $stmt->bind_param('si', $since, $limit);
        } else {
            $sql = "SELECT called_at, endpoint, {$purposeSelect}, ad_account_id, response_code, success
                    FROM facebook_api_calls
                    WHERE called_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                    ORDER BY called_at DESC
                    LIMIT ?";
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                return [];
            }
            $stmt->bind_param('i', $limit);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $labels = self::purposeLabels();
        $out = [];
        while ($row = $result->fetch_assoc()) {
            $purpose = (string)($row['purpose'] ?? '');
            $endpointRaw = (string)($row['endpoint'] ?? '');
            if ($purpose === '' || $purpose === 'other') {
                $purpose = $this->inferPurposeFromEndpoint($endpointRaw);
            }
            $out[] = [
                'called_at' => (string)($row['called_at'] ?? ''),
                'endpoint' => $this->normalizeEndpointForDisplay($endpointRaw),
                'endpoint_raw' => $endpointRaw,
                'purpose' => $purpose,
                'purpose_label' => $labels[$purpose] ?? $purpose,
                'ad_account_id' => $row['ad_account_id'] !== null ? (string)$row['ad_account_id'] : null,
                'response_code' => (int)($row['response_code'] ?? 0),
                'success' => $this->resolveCallSuccess($row),
                'success_flag' => ((int)($row['success'] ?? 0)) === 1,
            ];
        }
        $stmt->close();

        return $out;
    }

    /**
     * Persist last cost-sync run summary for Settings → View details.
     *
     * @param array<string, mixed> $summary
     */
    public function saveLastRunSummary(array $summary): bool
    {
        require_once dirname(__DIR__) . '/Settings/SettingsManager.php';
        $settings = new \SimpleKuma\Settings\SettingsManager($this->db);
        $json = json_encode($summary, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        return $settings->set(self::LAST_RUN_SETTING_KEY, $json);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLastRunSummary(): ?array
    {
        require_once dirname(__DIR__) . '/Settings/SettingsManager.php';
        $settings = new \SimpleKuma\Settings\SettingsManager($this->db);
        $raw = $settings->get(self::LAST_RUN_SETTING_KEY);
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Full payload for the Settings diagnostics modal / AJAX.
     *
     * @return array{last_run: ?array, recent_calls: array, this_hour_by_purpose: array, purpose_labels: array}
     */
    public function getDiagnosticsPayload(): array
    {
        $lastRun = $this->getLastRunSummary();
        $since = null;
        if (is_array($lastRun) && !empty($lastRun['started_at'])) {
            $since = (string)$lastRun['started_at'];
        }

        $recent = $this->getRecentCalls(50, $since);
        // If the last-run window is empty (timezone skew, or run before purpose logging),
        // still show the rolling last hour so the modal is useful.
        if ($recent === []) {
            $recent = $this->getRecentCalls(50, null);
        }

        return [
            'last_run' => $lastRun,
            'recent_calls' => $recent,
            'this_hour_by_purpose' => $this->getPurposeBreakdownThisHour(),
            'purpose_labels' => self::purposeLabels(),
        ];
    }

    /**
     * @return array<int, array{purpose: string, label: string, count: int}>
     */
    public function getPurposeBreakdownThisHour(?int $integrationId = null): array
    {
        // Prefer purpose column when populated; otherwise infer from endpoints (legacy rows).
        $sql = "SELECT endpoint, purpose, COUNT(*) AS count
                FROM facebook_api_calls
                WHERE called_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";
        $params = [];
        $types = '';
        if ($integrationId !== null) {
            $sql .= ' AND integration_id = ?';
            $params[] = $integrationId;
            $types .= 'i';
        }
        $sql .= ' GROUP BY endpoint, purpose ORDER BY count DESC LIMIT 50';

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $labels = self::purposeLabels();
        $grouped = [];
        while ($row = $result->fetch_assoc()) {
            $purpose = (string)($row['purpose'] ?? '');
            $endpoint = (string)($row['endpoint'] ?? '');
            if ($purpose === '' || $purpose === 'other') {
                $purpose = $this->inferPurposeFromEndpoint($endpoint);
            }
            if (!isset($grouped[$purpose])) {
                $grouped[$purpose] = 0;
            }
            $grouped[$purpose] += (int)($row['count'] ?? 0);
        }
        $stmt->close();

        arsort($grouped);
        $out = [];
        foreach ($grouped as $purpose => $count) {
            $out[] = [
                'purpose' => $purpose,
                'label' => $labels[$purpose] ?? $purpose,
                'count' => $count,
            ];
            if (count($out) >= 15) {
                break;
            }
        }
        return $out;
    }
}

