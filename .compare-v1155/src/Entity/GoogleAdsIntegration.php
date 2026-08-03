<?php

declare(strict_types=1);

namespace SimpleKuma\Entity;

use mysqli;
use SimpleKuma\Utils\SecretEncryption;

/**
 * Google Ads Integration Entity
 * Handles CRUD for conversion CSV/API delivery and cost-tracking credentials.
 *
 * Sensitive fields (developer_token, oauth_client_secret, oauth_refresh_token)
 * are stored encrypted at rest via SecretEncryption (APP_KEY).
 */
class GoogleAdsIntegration
{
    public const MODE_CSV = 'csv';
    public const MODE_API = 'api';
    public const MODE_BOTH = 'both';

    /** @var list<string> */
    private const SECRET_FIELDS = [
        'developer_token',
        'oauth_client_secret',
        'oauth_refresh_token',
    ];

    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function getAll(): array
    {
        $result = $this->db->query(
            "SELECT gai.*, COUNT(c.id) as campaign_count
             FROM google_ads_integrations gai
             LEFT JOIN campaigns c ON c.google_ads_integration_id = gai.id
             GROUP BY gai.id
             ORDER BY gai.name ASC"
        );

        $integrations = [];
        while ($row = $result->fetch_assoc()) {
            $integrations[] = $row;
        }

        return $integrations;
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM google_ads_integrations WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }

    public function create(array $data): ?int
    {
        $hasDelivery = $this->hasDeliveryColumns();

        if ($hasDelivery) {
            $stmt = $this->db->prepare(
                "INSERT INTO google_ads_integrations (
                    name, conversion_key, delivery_mode, conversion_action_id,
                    customer_id, developer_token,
                    oauth_client_id, oauth_client_secret, oauth_refresh_token,
                    login_customer_id, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, COALESCE(?, 'active'), NOW())"
            );
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO google_ads_integrations (
                    name, conversion_key, customer_id, developer_token,
                    oauth_client_id, oauth_client_secret, oauth_refresh_token,
                    login_customer_id, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, COALESCE(?, 'active'), NOW())"
            );
        }

        $name = $data['name'] ?? '';
        $conversionKey = $data['conversion_key'] ?? '';
        $deliveryMode = $this->normalizeDeliveryMode($data['delivery_mode'] ?? self::MODE_CSV);
        $conversionActionId = $data['conversion_action_id'] ?? null;
        if ($conversionActionId === '') {
            $conversionActionId = null;
        }
        $customerId = self::normalizeCustomerId($data['customer_id'] ?? null);
        $developerToken = $this->encryptSecret($data['developer_token'] ?? null);
        $oauthClientId = $data['oauth_client_id'] ?? null;
        $oauthClientSecret = $this->encryptSecret($data['oauth_client_secret'] ?? null);
        $oauthRefreshToken = $this->encryptSecret($data['oauth_refresh_token'] ?? null);
        $loginCustomerId = self::normalizeCustomerId($data['login_customer_id'] ?? null);
        $status = $data['status'] ?? 'active';

        if ($hasDelivery) {
            $stmt->bind_param(
                'sssssssssss',
                $name,
                $conversionKey,
                $deliveryMode,
                $conversionActionId,
                $customerId,
                $developerToken,
                $oauthClientId,
                $oauthClientSecret,
                $oauthRefreshToken,
                $loginCustomerId,
                $status
            );
        } else {
            $stmt->bind_param(
                'sssssssss',
                $name,
                $conversionKey,
                $customerId,
                $developerToken,
                $oauthClientId,
                $oauthClientSecret,
                $oauthRefreshToken,
                $loginCustomerId,
                $status
            );
        }

        if ($stmt->execute()) {
            return (int)$stmt->insert_id;
        }

        return null;
    }

    public function update(int $id, array $data): bool
    {
        $existing = $this->getById($id);
        if ($existing === null) {
            return false;
        }

        $hasDelivery = $this->hasDeliveryColumns();
        $name = $data['name'] ?? $existing['name'];
        $conversionKey = $data['conversion_key'] ?? $existing['conversion_key'];
        $deliveryMode = $this->normalizeDeliveryMode($data['delivery_mode'] ?? ($existing['delivery_mode'] ?? self::MODE_CSV));
        $conversionActionId = array_key_exists('conversion_action_id', $data)
            ? ($data['conversion_action_id'] !== '' ? $data['conversion_action_id'] : null)
            : ($existing['conversion_action_id'] ?? null);
        $customerId = array_key_exists('customer_id', $data)
            ? self::normalizeCustomerId($data['customer_id'])
            : ($existing['customer_id'] ?? null);

        // Blank secret fields mean "keep existing" (forms never echo secrets back).
        $developerToken = !empty($data['developer_token'])
            ? $this->encryptSecret($data['developer_token'])
            : ($existing['developer_token'] ?? null);
        $oauthClientId = array_key_exists('oauth_client_id', $data)
            ? $data['oauth_client_id']
            : ($existing['oauth_client_id'] ?? null);
        $oauthClientSecret = !empty($data['oauth_client_secret'])
            ? $this->encryptSecret($data['oauth_client_secret'])
            : ($existing['oauth_client_secret'] ?? null);
        $oauthRefreshToken = !empty($data['oauth_refresh_token'])
            ? $this->encryptSecret($data['oauth_refresh_token'])
            : ($existing['oauth_refresh_token'] ?? null);
        $loginCustomerId = array_key_exists('login_customer_id', $data)
            ? self::normalizeCustomerId($data['login_customer_id'])
            : ($existing['login_customer_id'] ?? null);
        $status = $data['status'] ?? ($existing['status'] ?? 'active');

        if ($hasDelivery) {
            $stmt = $this->db->prepare(
                "UPDATE google_ads_integrations
                 SET name = ?, conversion_key = ?, delivery_mode = ?, conversion_action_id = ?,
                     customer_id = ?, developer_token = ?,
                     oauth_client_id = ?, oauth_client_secret = ?,
                     oauth_refresh_token = ?, login_customer_id = ?,
                     status = COALESCE(?, status),
                     updated_at = NOW()
                 WHERE id = ?"
            );
            $stmt->bind_param(
                'sssssssssssi',
                $name,
                $conversionKey,
                $deliveryMode,
                $conversionActionId,
                $customerId,
                $developerToken,
                $oauthClientId,
                $oauthClientSecret,
                $oauthRefreshToken,
                $loginCustomerId,
                $status,
                $id
            );
        } else {
            $stmt = $this->db->prepare(
                "UPDATE google_ads_integrations
                 SET name = ?, conversion_key = ?,
                     customer_id = ?, developer_token = ?,
                     oauth_client_id = ?, oauth_client_secret = ?,
                     oauth_refresh_token = ?, login_customer_id = ?,
                     status = COALESCE(?, status),
                     updated_at = NOW()
                 WHERE id = ?"
            );
            $stmt->bind_param(
                'sssssssssi',
                $name,
                $conversionKey,
                $customerId,
                $developerToken,
                $oauthClientId,
                $oauthClientSecret,
                $oauthRefreshToken,
                $loginCustomerId,
                $status,
                $id
            );
        }

        return $stmt->execute();
    }

    public function delete(int $id): bool
    {
        $checkStmt = $this->db->prepare(
            "SELECT COUNT(*) as count FROM campaigns WHERE google_ads_integration_id = ?"
        );
        $checkStmt->bind_param("i", $id);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        $row = $result->fetch_assoc();

        if (($row['count'] ?? 0) > 0) {
            $updateStmt = $this->db->prepare(
                "UPDATE campaigns SET google_ads_integration_id = NULL WHERE google_ads_integration_id = ?"
            );
            $updateStmt->bind_param("i", $id);
            $updateStmt->execute();
        }

        $stmt = $this->db->prepare("DELETE FROM google_ads_integrations WHERE id = ?");
        $stmt->bind_param("i", $id);
        $ok = $stmt->execute();
        if ($ok) {
            $this->deleteConfigFile($id);
        }

        return $ok;
    }

    public function validate(array $data, bool $isUpdate = false): array
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors[] = "Integration name is required.";
        } elseif (strlen($data['name']) > 100) {
            $errors[] = "Integration name cannot exceed 100 characters.";
        } else {
            $query = "SELECT id FROM google_ads_integrations WHERE name = ?";
            if ($isUpdate) {
                $query .= " AND id != ?";
            }
            $stmt = $this->db->prepare($query);
            if ($isUpdate) {
                $stmt->bind_param("si", $data['name'], $data['id']);
            } else {
                $stmt->bind_param("s", $data['name']);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $errors[] = "An integration with this name already exists.";
            }
        }

        if (empty($data['conversion_key'])) {
            $errors[] = "Conversion key is required.";
        } elseif (strlen($data['conversion_key']) > 100) {
            $errors[] = "Conversion key cannot exceed 100 characters.";
        } elseif (strlen($data['conversion_key']) < 10) {
            $errors[] = "Conversion key must be at least 10 characters for security.";
        }

        $mode = $this->normalizeDeliveryMode($data['delivery_mode'] ?? self::MODE_CSV);
        if (!in_array($mode, [self::MODE_CSV, self::MODE_API, self::MODE_BOTH], true)) {
            $errors[] = 'Invalid delivery mode.';
        }

        if ($mode === self::MODE_API || $mode === self::MODE_BOTH) {
            $errors = array_merge($errors, $this->validateApiUpload($data, $isUpdate));
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    public function validateApiUpload(array $data, bool $isUpdate = false): array
    {
        $errors = $this->validateCostTracking($data, $isUpdate);

        $actionId = preg_replace('/\D/', '', (string)($data['conversion_action_id'] ?? ''));
        if ($actionId === '') {
            $errors[] = 'Conversion action ID is required for API delivery.';
        }

        return $errors;
    }

    public function usesApiDelivery(array $integration): bool
    {
        $mode = $integration['delivery_mode'] ?? self::MODE_CSV;
        return $mode === self::MODE_API || $mode === self::MODE_BOTH;
    }

    public function usesCsvDelivery(array $integration): bool
    {
        $mode = $integration['delivery_mode'] ?? self::MODE_CSV;
        return $mode === self::MODE_CSV || $mode === self::MODE_BOTH;
    }

    /**
     * Return a copy with secret fields decrypted for API client / INI generation.
     *
     * @param array<string, mixed> $integration
     * @return array<string, mixed>
     */
    public function withDecryptedSecrets(array $integration): array
    {
        foreach (self::SECRET_FIELDS as $field) {
            if (!empty($integration[$field]) && is_string($integration[$field])) {
                $integration[$field] = SecretEncryption::decryptFlexible($integration[$field]);
            }
        }

        return $integration;
    }

    public function generateConfigFileContent(array $integration): string
    {
        $plain = $this->withDecryptedSecrets($integration);

        $config = "[GOOGLE_ADS]\n";
        $config .= 'developerToken = "' . $this->escapeIniValue((string)($plain['developer_token'] ?? '')) . "\"\n";

        if (!empty($plain['login_customer_id'])) {
            $config .= 'loginCustomerId = "' . $this->escapeIniValue((string)$plain['login_customer_id']) . "\"\n";
        }

        if (!empty($plain['customer_id'])) {
            $config .= 'linkedCustomerId = "' . $this->escapeIniValue((string)$plain['customer_id']) . "\"\n";
        }

        $config .= "useGrpc = false\n\n";
        $config .= "[OAUTH2]\n";
        $config .= 'clientId = "' . $this->escapeIniValue((string)($plain['oauth_client_id'] ?? '')) . "\"\n";
        $config .= 'clientSecret = "' . $this->escapeIniValue((string)($plain['oauth_client_secret'] ?? '')) . "\"\n";
        $config .= 'refreshToken = "' . $this->escapeIniValue((string)($plain['oauth_refresh_token'] ?? '')) . "\"\n";

        return $config;
    }

    public function getConfigFilePath(int $integrationId): string
    {
        $configDir = __DIR__ . '/../../storage/google_ads_configs';
        if (!is_dir($configDir)) {
            mkdir($configDir, 0750, true);
        }
        return $configDir . '/integration_' . $integrationId . '.ini';
    }

    public function saveConfigFile(array $integration): bool
    {
        $id = (int)($integration['id'] ?? 0);
        if ($id <= 0) {
            return false;
        }

        $this->reencryptPlaintextSecretsIfNeeded($integration);

        $configContent = $this->generateConfigFileContent($integration);
        $configPath = $this->getConfigFilePath($id);
        $written = file_put_contents($configPath, $configContent);
        if ($written === false) {
            return false;
        }

        @chmod($configPath, 0600);

        return true;
    }

    public function deleteConfigFile(int $integrationId): void
    {
        $path = $this->getConfigFilePath($integrationId);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getActiveForCostTracking(): array
    {
        $result = $this->db->query(
            "SELECT * FROM google_ads_integrations
             WHERE status = 'active'
               AND customer_id IS NOT NULL AND customer_id != ''
               AND developer_token IS NOT NULL AND developer_token != ''
               AND oauth_client_id IS NOT NULL AND oauth_client_id != ''
               AND oauth_client_secret IS NOT NULL AND oauth_client_secret != ''
               AND oauth_refresh_token IS NOT NULL AND oauth_refresh_token != ''
             ORDER BY name ASC"
        );

        if (!$result) {
            return [];
        }

        $integrations = [];
        while ($row = $result->fetch_assoc()) {
            $integrations[] = $row;
        }

        return $integrations;
    }

    public function isCostTrackingConfigured(array $integration): bool
    {
        foreach (['customer_id', 'developer_token', 'oauth_client_id', 'oauth_client_secret', 'oauth_refresh_token'] as $field) {
            if (empty($integration[$field])) {
                return false;
            }
        }

        return ($integration['status'] ?? 'active') === 'active';
    }

    public function hasStoredSecret(array $integration, string $field): bool
    {
        return !empty($integration[$field]);
    }

    /**
     * @return list<string>
     */
    public function validateCostTracking(array $data, bool $isUpdate = false): array
    {
        $errors = [];

        $customerId = preg_replace('/\D/', '', (string)($data['customer_id'] ?? ''));
        if ($customerId === '') {
            $errors[] = 'Google Ads Customer ID is required for API access.';
        } elseif (strlen($customerId) < 10) {
            $errors[] = 'Google Ads Customer ID must be at least 10 digits (no dashes).';
        }

        if (!$isUpdate && empty($data['developer_token'])) {
            $errors[] = 'Developer token is required for API access.';
        }

        if (empty($data['oauth_client_id'])) {
            $errors[] = 'OAuth Client ID is required for API access.';
        }

        if (!$isUpdate && empty($data['oauth_client_secret'])) {
            $errors[] = 'OAuth Client Secret is required for API access.';
        }

        if (!$isUpdate && empty($data['oauth_refresh_token'])) {
            $errors[] = 'OAuth Refresh Token is required for API access.';
        }

        return $errors;
    }

    /**
     * Validate Settings → API Costs form (name + OAuth). Conversion key is optional here.
     *
     * @return list<string>
     */
    public function validateCostSettings(array $data, bool $isUpdate = false): array
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors[] = 'Integration name is required.';
        } elseif (strlen((string)$data['name']) > 100) {
            $errors[] = 'Integration name cannot exceed 100 characters.';
        } else {
            $query = 'SELECT id FROM google_ads_integrations WHERE name = ?';
            if ($isUpdate) {
                $query .= ' AND id != ?';
            }
            $stmt = $this->db->prepare($query);
            if ($isUpdate) {
                $stmt->bind_param('si', $data['name'], $data['id']);
            } else {
                $stmt->bind_param('s', $data['name']);
            }
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $errors[] = 'An integration with this name already exists.';
            }
        }

        $status = $data['status'] ?? 'active';
        if (!in_array($status, ['active', 'inactive'], true)) {
            $errors[] = 'Invalid status.';
        }

        return array_merge($errors, $this->validateCostTracking($data, $isUpdate));
    }

    public function generateConversionKey(): string
    {
        return bin2hex(random_bytes(12));
    }

    public static function normalizeCustomerId(?string $customerId): ?string
    {
        if ($customerId === null || $customerId === '') {
            return null;
        }

        $normalized = preg_replace('/\D/', '', $customerId);

        return $normalized !== '' ? $normalized : null;
    }

    public function normalizeDeliveryMode(?string $mode): string
    {
        $mode = strtolower(trim((string)$mode));
        if (in_array($mode, [self::MODE_CSV, self::MODE_API, self::MODE_BOTH], true)) {
            return $mode;
        }
        return self::MODE_CSV;
    }

    private function encryptSecret(?string $plaintext): ?string
    {
        if ($plaintext === null || $plaintext === '') {
            return $plaintext;
        }

        try {
            return SecretEncryption::encrypt($plaintext);
        } catch (\Throwable $e) {
            error_log('GoogleAdsIntegration: failed to encrypt secret: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Lazy-migrate legacy plaintext secrets to encrypted form when cron/API runs.
     *
     * @param array<string, mixed> $integration
     */
    private function reencryptPlaintextSecretsIfNeeded(array $integration): void
    {
        $id = (int)($integration['id'] ?? 0);
        if ($id <= 0) {
            return;
        }

        $updates = [];
        foreach (self::SECRET_FIELDS as $field) {
            $raw = $integration[$field] ?? null;
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            if (SecretEncryption::looksEncrypted($raw)) {
                continue;
            }
            try {
                $updates[$field] = SecretEncryption::encrypt($raw);
            } catch (\Throwable $e) {
                error_log("GoogleAdsIntegration: re-encrypt {$field} failed: " . $e->getMessage());
            }
        }

        if ($updates === []) {
            return;
        }

        $sets = [];
        $types = '';
        $values = [];
        foreach ($updates as $field => $value) {
            $sets[] = "{$field} = ?";
            $types .= 's';
            $values[] = $value;
        }
        $types .= 'i';
        $values[] = $id;

        $sql = 'UPDATE google_ads_integrations SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = ?';
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
    }

    private function escapeIniValue(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    private function hasDeliveryColumns(): bool
    {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        $check = $this->db->query("SHOW COLUMNS FROM google_ads_integrations LIKE 'delivery_mode'");
        $has = ($check && $check->num_rows > 0);
        return $has;
    }
}
