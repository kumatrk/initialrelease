<?php

declare(strict_types=1);

namespace SimpleKuma\GoogleAds;

use mysqli;
use SimpleKuma\Entity\GoogleAdsIntegration;

/**
 * Uploads offline click conversions via Google Ads ConversionUploadService.
 * Follows https://developers.google.com/google-ads/api/samples/upload-offline-conversion
 */
class GoogleAdsConversionUploader
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    private const MIN_CLICK_AGE_SECONDS = 6 * 3600;
    private const MAX_CLICK_AGE_SECONDS = 90 * 24 * 3600;
    private const MAX_ATTEMPTS = 12;

    private mysqli $db;
    private GoogleAdsIntegration $integrations;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->integrations = new GoogleAdsIntegration($db);
    }

    /**
     * Attempt upload for a conversion if the campaign integration uses API mode.
     *
     * @return array{ok: bool, status: string, message: string}
     */
    public function uploadConversionIfConfigured(int $conversionId): array
    {
        $row = $this->loadConversionRow($conversionId);
        if ($row === null) {
            return ['ok' => false, 'status' => self::STATUS_SKIPPED, 'message' => 'Conversion not found'];
        }

        $integrationId = (int)($row['google_ads_integration_id'] ?? 0);
        if ($integrationId <= 0) {
            return ['ok' => false, 'status' => self::STATUS_SKIPPED, 'message' => 'No Google Ads integration on campaign'];
        }

        $integration = $this->integrations->getById($integrationId);
        if ($integration === null) {
            return ['ok' => false, 'status' => self::STATUS_SKIPPED, 'message' => 'Integration not found'];
        }

        $mode = $integration['delivery_mode'] ?? 'csv';
        if ($mode !== 'api' && $mode !== 'both') {
            return ['ok' => false, 'status' => self::STATUS_SKIPPED, 'message' => 'Integration delivery_mode is csv-only'];
        }

        return $this->uploadWithIntegration($row, $integration);
    }

    /**
     * Process queued / retryable API uploads.
     *
     * @return array{processed: int, uploaded: int, queued: int, failed: int}
     */
    public function processPendingQueue(int $limit = 50): array
    {
        $stats = ['processed' => 0, 'uploaded' => 0, 'queued' => 0, 'failed' => 0];
        $limit = max(1, min(200, $limit));

        $sql = "
            SELECT c.id
            FROM conversions c
            INNER JOIN clicks cl ON c.click_id = cl.click_id
            INNER JOIN campaigns cp ON cl.campaign_id = cp.id
            INNER JOIN google_ads_integrations gai ON cp.google_ads_integration_id = gai.id
            WHERE gai.delivery_mode IN ('api', 'both')
              AND c.google_ads_upload_status IN (?, ?)
              AND (c.google_ads_upload_next_at IS NULL OR c.google_ads_upload_next_at <= UTC_TIMESTAMP())
              AND c.google_ads_upload_attempts < ?
            ORDER BY c.google_ads_upload_next_at IS NULL, c.google_ads_upload_next_at ASC, c.id ASC
            LIMIT {$limit}
        ";

        $queued = self::STATUS_QUEUED;
        $pending = self::STATUS_PENDING;
        $maxAttempts = self::MAX_ATTEMPTS;
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return $stats;
        }
        $stmt->bind_param('ssi', $queued, $pending, $maxAttempts);
        $stmt->execute();
        $result = $stmt->get_result();
        $ids = [];
        while ($r = $result->fetch_assoc()) {
            $ids[] = (int)$r['id'];
        }
        $stmt->close();

        foreach ($ids as $id) {
            $stats['processed']++;
            $out = $this->uploadConversionIfConfigured($id);
            if ($out['status'] === self::STATUS_UPLOADED) {
                $stats['uploaded']++;
            } elseif ($out['status'] === self::STATUS_QUEUED) {
                $stats['queued']++;
            } elseif ($out['status'] === self::STATUS_FAILED) {
                $stats['failed']++;
            }
        }

        return $stats;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $integration
     * @return array{ok: bool, status: string, message: string}
     */
    private function uploadWithIntegration(array $row, array $integration): array
    {
        $conversionId = (int)$row['id'];
        $ids = GoogleAdsClickIdHelper::extractFromExtraJson($row['extra_json'] ?? null);

        if (!GoogleAdsClickIdHelper::hasAny($ids)) {
            $this->markStatus($conversionId, self::STATUS_SKIPPED, 'No gclid/gbraid/wbraid on click');
            return ['ok' => false, 'status' => self::STATUS_SKIPPED, 'message' => 'No click ID'];
        }

        $apiErrors = $this->integrations->validateApiUpload($integration, true);
        if (!empty($apiErrors)) {
            $msg = implode('; ', $apiErrors);
            $this->markStatus($conversionId, self::STATUS_FAILED, $msg);
            return ['ok' => false, 'status' => self::STATUS_FAILED, 'message' => $msg];
        }

        $clickTs = strtotime((string)($row['click_ts'] ?? ''));
        if ($clickTs === false) {
            $this->markStatus($conversionId, self::STATUS_FAILED, 'Invalid click timestamp');
            return ['ok' => false, 'status' => self::STATUS_FAILED, 'message' => 'Invalid click timestamp'];
        }

        $age = time() - $clickTs;
        if ($age > self::MAX_CLICK_AGE_SECONDS) {
            $this->markStatus($conversionId, self::STATUS_FAILED, 'Click ID older than 90 days');
            return ['ok' => false, 'status' => self::STATUS_FAILED, 'message' => 'Click too old'];
        }

        if ($age < self::MIN_CLICK_AGE_SECONDS) {
            $waitUntil = gmdate('Y-m-d H:i:s', $clickTs + self::MIN_CLICK_AGE_SECONDS);
            $this->markQueued($conversionId, $waitUntil, 'Waiting for 6h click indexing window');
            return ['ok' => false, 'status' => self::STATUS_QUEUED, 'message' => 'Queued until ' . $waitUntil];
        }

        try {
            $this->integrations->saveConfigFile($integration);
            $configPath = $this->integrations->getConfigFilePath((int)$integration['id']);
            $customerId = GoogleAdsIntegration::normalizeCustomerId($integration['customer_id'] ?? '') ?? '';
            $clientBundle = $this->buildClient($configPath);
            if ($clientBundle === null) {
                $this->markQueuedOrFailed($conversionId, 'Google Ads PHP client not initialized');
                return ['ok' => false, 'status' => self::STATUS_FAILED, 'message' => 'Client init failed'];
            }

            [$client, $apiVersion] = $clientBundle;
            $clickConversionClass = "Google\\Ads\\GoogleAds\\{$apiVersion}\\Services\\ClickConversion";
            $resourceNamesClass = "Google\\Ads\\GoogleAds\\Util\\{$apiVersion}\\ResourceNames";

            if (!class_exists($clickConversionClass) || !class_exists($resourceNamesClass)) {
                $this->markStatus($conversionId, self::STATUS_FAILED, "Missing classes for {$apiVersion}");
                return ['ok' => false, 'status' => self::STATUS_FAILED, 'message' => 'Missing API classes'];
            }

            /** @var object $clickConversion */
            $clickConversion = new $clickConversionClass();
            $actionResource = $resourceNamesClass::forConversionAction(
                $customerId,
                (string)$integration['conversion_action_id']
            );
            $clickConversion->setConversionAction($actionResource);

            $convTs = strtotime((string)($row['ts'] ?? 'now'));
            if ($convTs === false) {
                $convTs = time();
            }
            // Google requires timezone offset: yyyy-mm-dd hh:mm:ss+|-hh:mm
            $clickConversion->setConversionDateTime(gmdate('Y-m-d H:i:s+00:00', $convTs));
            $clickConversion->setConversionValue((float)($row['value'] ?? $row['payout'] ?? 0));
            $currency = strtoupper(trim((string)($row['currency'] ?? 'USD')));
            if ($currency === '') {
                $currency = 'USD';
            }
            $clickConversion->setCurrencyCode($currency);
            $clickConversion->setOrderId((string)$conversionId);

            // Prefer gclid; if both gclid and gbraid exist, set both (upload-clicks guidance).
            if ($ids['gclid'] !== null) {
                $clickConversion->setGclid($ids['gclid']);
                if ($ids['gbraid'] !== null && method_exists($clickConversion, 'setGbraid')) {
                    $clickConversion->setGbraid($ids['gbraid']);
                }
            } elseif ($ids['gbraid'] !== null) {
                $clickConversion->setGbraid($ids['gbraid']);
            } elseif ($ids['wbraid'] !== null) {
                $clickConversion->setWbraid($ids['wbraid']);
            }

            $uploadClient = $client->getConversionUploadServiceClient();
            $response = $uploadClient->uploadClickConversions($customerId, [$clickConversion], true);

            $partialFail = method_exists($response, 'hasPartialFailureError') && $response->hasPartialFailureError();
            $this->logApiCall(
                'uploadClickConversions',
                $customerId,
                $integrationId,
                !$partialFail
            );

            if ($partialFail) {
                $errMsg = $response->getPartialFailureError()->getMessage();
                if ($this->isRetryableErrorMessage($errMsg)) {
                    $next = gmdate('Y-m-d H:i:s', time() + 3600);
                    $this->markQueued($conversionId, $next, $errMsg);
                    return ['ok' => false, 'status' => self::STATUS_QUEUED, 'message' => $errMsg];
                }
                $this->markStatus($conversionId, self::STATUS_FAILED, $errMsg);
                return ['ok' => false, 'status' => self::STATUS_FAILED, 'message' => $errMsg];
            }

            $this->markStatus($conversionId, self::STATUS_UPLOADED, null);
            return ['ok' => true, 'status' => self::STATUS_UPLOADED, 'message' => 'Uploaded'];
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $failCustomerId = GoogleAdsIntegration::normalizeCustomerId($integration['customer_id'] ?? '') ?? '';
            $this->logApiCall('uploadClickConversions', $failCustomerId, $integrationId, false);
            error_log('GoogleAdsConversionUploader: ' . $msg);
            if ($this->isRetryableErrorMessage($msg)) {
                $next = gmdate('Y-m-d H:i:s', time() + 3600);
                $this->markQueued($conversionId, $next, $msg);
                return ['ok' => false, 'status' => self::STATUS_QUEUED, 'message' => $msg];
            }
            $this->markStatus($conversionId, self::STATUS_FAILED, $msg);
            return ['ok' => false, 'status' => self::STATUS_FAILED, 'message' => $msg];
        }
    }

    /**
     * @return array{0: object, 1: string}|null
     */
    private function buildClient(string $configPath): ?array
    {
        $builderClass = null;
        $apiVersion = null;
        foreach (['V24', 'V23', 'V22', 'V21', 'V20', 'V19', 'V18', 'V17', 'V16', 'V15', 'V14', 'V13', 'V12', 'V11', 'V10'] as $version) {
            $class = "Google\\Ads\\GoogleAds\\Lib\\{$version}\\GoogleAdsClientBuilder";
            if (class_exists($class)) {
                $builderClass = $class;
                $apiVersion = $version;
                break;
            }
        }
        if ($builderClass === null) {
            return null;
        }

        try {
            $client = (new $builderClass())
                ->fromFile($configPath)
                ->build();
            return [$client, $apiVersion];
        } catch (\Throwable $e) {
            error_log('GoogleAdsConversionUploader client build failed: ' . $e->getMessage());
            return null;
        }
    }

    private function logApiCall(string $endpoint, string $customerId, int $integrationId, bool $success): void
    {
        try {
            $tracker = new GoogleAdsApiCallTracker($this->db);
            $tracker->logCall(
                $endpoint,
                'POST',
                $customerId !== '' ? $customerId : null,
                $integrationId > 0 ? $integrationId : null,
                null,
                $success
            );
        } catch (\Throwable $e) {
            error_log('GoogleAdsConversionUploader::logApiCall failed: ' . $e->getMessage());
        }
    }

    private function isRetryableErrorMessage(string $message): bool
    {
        $m = strtolower($message);
        return str_contains($m, 'too recent')
            || str_contains($m, 'click_not_found')
            || str_contains($m, 'not found')
            || str_contains($m, 'unavailable')
            || str_contains($m, 'rate')
            || str_contains($m, 'internal');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadConversionRow(int $conversionId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT c.id, c.click_id, c.ts, c.value, c.payout, c.currency, c.status,
                    c.google_ads_upload_status, c.google_ads_upload_attempts,
                    cl.extra_json, cl.ts AS click_ts,
                    cp.google_ads_integration_id
             FROM conversions c
             INNER JOIN clicks cl ON c.click_id = cl.click_id
             INNER JOIN campaigns cp ON cl.campaign_id = cp.id
             WHERE c.id = ?
             LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $conversionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    private function markStatus(int $conversionId, string $status, ?string $error): void
    {
        if (!$this->hasUploadColumns()) {
            return;
        }
        $stmt = $this->db->prepare(
            "UPDATE conversions
             SET google_ads_upload_status = ?,
                 google_ads_upload_attempts = google_ads_upload_attempts + 1,
                 google_ads_upload_next_at = NULL,
                 google_ads_upload_last_error = ?
             WHERE id = ?"
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('ssi', $status, $error, $conversionId);
        $stmt->execute();
        $stmt->close();
    }

    private function markQueued(int $conversionId, string $nextAtUtc, string $error): void
    {
        if (!$this->hasUploadColumns()) {
            return;
        }
        $status = self::STATUS_QUEUED;
        $stmt = $this->db->prepare(
            "UPDATE conversions
             SET google_ads_upload_status = ?,
                 google_ads_upload_attempts = google_ads_upload_attempts + 1,
                 google_ads_upload_next_at = ?,
                 google_ads_upload_last_error = ?
             WHERE id = ?"
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('sssi', $status, $nextAtUtc, $error, $conversionId);
        $stmt->execute();
        $stmt->close();
    }

    private function markQueuedOrFailed(int $conversionId, string $message): void
    {
        $attempts = 0;
        $stmt = $this->db->prepare('SELECT google_ads_upload_attempts FROM conversions WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param('i', $conversionId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $attempts = (int)($row['google_ads_upload_attempts'] ?? 0);
            $stmt->close();
        }
        if ($attempts + 1 >= self::MAX_ATTEMPTS) {
            $this->markStatus($conversionId, self::STATUS_FAILED, $message);
        } else {
            $this->markQueued($conversionId, gmdate('Y-m-d H:i:s', time() + 1800), $message);
        }
    }

    private function hasUploadColumns(): bool
    {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        $check = $this->db->query("SHOW COLUMNS FROM conversions LIKE 'google_ads_upload_status'");
        $has = ($check && $check->num_rows > 0);
        return $has;
    }
}
