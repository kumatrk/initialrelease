<?php

declare(strict_types=1);

namespace SimpleKuma\Entity;

use mysqli;
use SimpleKuma\Tracking\MetaCapiEventResolver;
use SimpleKuma\Utils\ProxyPasswordEncryption;

/**
 * Facebook CAPI Integration Entity
 * Handles CRUD operations for Facebook Conversions API integrations
 */
class FacebookCapiIntegration
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function getAll(): array
    {
        $result = $this->db->query(
            "SELECT fci.*, COUNT(c.id) as campaign_count
             FROM facebook_capi_integrations fci
             LEFT JOIN campaigns c ON c.facebook_capi_integration_id = fci.id
             GROUP BY fci.id
             ORDER BY fci.name ASC"
        );

        $integrations = [];
        while ($row = $result->fetch_assoc()) {
            unset($row['access_token']);
            unset($row['proxy_pass_encrypted']);
            $integrations[] = $row;
        }

        return $integrations;
    }

    public function getById(int $id, bool $includeDecryptedPassword = false): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM facebook_capi_integrations WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if ($row && $includeDecryptedPassword && !empty($row['proxy_pass_encrypted'])) {
            try {
                $row['proxy_pass'] = ProxyPasswordEncryption::decrypt($row['proxy_pass_encrypted']);
            } catch (\Exception $e) {
                $row['proxy_pass'] = '';
                error_log("Failed to decrypt proxy password for integration {$id}: " . $e->getMessage());
            }
        }

        return $row;
    }

    public function create(array $data): ?int
    {
        $proxyPassEncrypted = null;
        if (!empty($data['proxy_pass'])) {
            try {
                $proxyPassEncrypted = ProxyPasswordEncryption::encrypt($data['proxy_pass']);
            } catch (\Exception $e) {
                error_log("Failed to encrypt proxy password: " . $e->getMessage());
                return null;
            }
        }

        $hasMappingCols = $this->hasMappingColumns();

        $name = $data['name'];
        $pixelId = $data['pixel_id'];
        $accessToken = $data['access_token'];
        $testCode = $data['test_code'] ?? null;
        $eventType = $data['event_type'] ?? 'Purchase';
        $useProxy = isset($data['use_proxy']) ? (int)(bool)$data['use_proxy'] : 0;
        $proxyHost = $data['proxy_host'] ?? null;
        $proxyPort = !empty($data['proxy_port']) ? (int)$data['proxy_port'] : null;
        $proxyType = $data['proxy_type'] ?? null;
        $proxyUser = $data['proxy_user'] ?? null;
        $eventMappingJson = $this->encodeMapping($data['event_mapping'] ?? $data['event_mapping_json'] ?? null);
        $sendPageview = isset($data['send_pageview_on_click']) ? (int)(bool)$data['send_pageview_on_click'] : 0;

        if ($hasMappingCols) {
            $stmt = $this->db->prepare(
                "INSERT INTO facebook_capi_integrations
                 (name, pixel_id, access_token, test_code, event_type, event_mapping_json, send_pageview_on_click,
                  use_proxy, proxy_host, proxy_port, proxy_type, proxy_user, proxy_pass_encrypted, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->bind_param(
                'ssssssiisssss',
                $name,
                $pixelId,
                $accessToken,
                $testCode,
                $eventType,
                $eventMappingJson,
                $sendPageview,
                $useProxy,
                $proxyHost,
                $proxyPort,
                $proxyType,
                $proxyUser,
                $proxyPassEncrypted
            );
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO facebook_capi_integrations
                 (name, pixel_id, access_token, test_code, event_type, use_proxy, proxy_host, proxy_port, proxy_type, proxy_user, proxy_pass_encrypted, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->bind_param(
                'sssssisssss',
                $name,
                $pixelId,
                $accessToken,
                $testCode,
                $eventType,
                $useProxy,
                $proxyHost,
                $proxyPort,
                $proxyType,
                $proxyUser,
                $proxyPassEncrypted
            );
        }

        if ($stmt->execute()) {
            return $stmt->insert_id;
        }

        error_log('FacebookCapiIntegration::create failed: ' . $stmt->error);
        return null;
    }

    public function update(int $id, array $data): bool
    {
        $proxyPassEncrypted = null;
        $updateProxyPass = false;

        if (isset($data['proxy_pass'])) {
            if (empty($data['proxy_pass'])) {
                $proxyPassEncrypted = null;
                $updateProxyPass = true;
            } else {
                try {
                    $proxyPassEncrypted = ProxyPasswordEncryption::encrypt($data['proxy_pass']);
                    $updateProxyPass = true;
                } catch (\Exception $e) {
                    error_log("Failed to encrypt proxy password: " . $e->getMessage());
                    return false;
                }
            }
        }

        $hasMappingCols = $this->hasMappingColumns();
        $name = $data['name'];
        $pixelId = $data['pixel_id'];
        $accessToken = (string) ($data['access_token'] ?? '');
        // Blank token on update = keep existing (never wipe from empty form field)
        if ($accessToken === '') {
            $existing = $this->getById($id);
            $accessToken = is_array($existing) ? (string) ($existing['access_token'] ?? '') : '';
            if ($accessToken === '') {
                return false;
            }
        }
        $testCode = $data['test_code'] ?? null;
        $eventType = $data['event_type'] ?? 'Purchase';
        $useProxy = isset($data['use_proxy']) ? (int)(bool)$data['use_proxy'] : 0;
        $proxyHost = $data['proxy_host'] ?? null;
        $proxyPort = !empty($data['proxy_port']) ? (int)$data['proxy_port'] : null;
        $proxyType = $data['proxy_type'] ?? null;
        $proxyUser = $data['proxy_user'] ?? null;
        $eventMappingJson = $this->encodeMapping($data['event_mapping'] ?? $data['event_mapping_json'] ?? null);
        $sendPageview = isset($data['send_pageview_on_click']) ? (int)(bool)$data['send_pageview_on_click'] : 0;

        if ($hasMappingCols && $updateProxyPass) {
            $stmt = $this->db->prepare(
                "UPDATE facebook_capi_integrations
                 SET name = ?, pixel_id = ?, access_token = ?, test_code = ?, event_type = ?,
                     event_mapping_json = ?, send_pageview_on_click = ?,
                     use_proxy = ?, proxy_host = ?, proxy_port = ?, proxy_type = ?, proxy_user = ?,
                     proxy_pass_encrypted = ?, updated_at = NOW()
                 WHERE id = ?"
            );
            $stmt->bind_param(
                'ssssssiisssssi',
                $name,
                $pixelId,
                $accessToken,
                $testCode,
                $eventType,
                $eventMappingJson,
                $sendPageview,
                $useProxy,
                $proxyHost,
                $proxyPort,
                $proxyType,
                $proxyUser,
                $proxyPassEncrypted,
                $id
            );
        } elseif ($hasMappingCols) {
            $stmt = $this->db->prepare(
                "UPDATE facebook_capi_integrations
                 SET name = ?, pixel_id = ?, access_token = ?, test_code = ?, event_type = ?,
                     event_mapping_json = ?, send_pageview_on_click = ?,
                     use_proxy = ?, proxy_host = ?, proxy_port = ?, proxy_type = ?, proxy_user = ?,
                     updated_at = NOW()
                 WHERE id = ?"
            );
            $stmt->bind_param(
                'ssssssiissssi',
                $name,
                $pixelId,
                $accessToken,
                $testCode,
                $eventType,
                $eventMappingJson,
                $sendPageview,
                $useProxy,
                $proxyHost,
                $proxyPort,
                $proxyType,
                $proxyUser,
                $id
            );
        } elseif ($updateProxyPass) {
            $stmt = $this->db->prepare(
                "UPDATE facebook_capi_integrations
                 SET name = ?, pixel_id = ?, access_token = ?, test_code = ?, event_type = ?,
                     use_proxy = ?, proxy_host = ?, proxy_port = ?, proxy_type = ?, proxy_user = ?,
                     proxy_pass_encrypted = ?, updated_at = NOW()
                 WHERE id = ?"
            );
            $stmt->bind_param(
                'sssssisssssi',
                $name,
                $pixelId,
                $accessToken,
                $testCode,
                $eventType,
                $useProxy,
                $proxyHost,
                $proxyPort,
                $proxyType,
                $proxyUser,
                $proxyPassEncrypted,
                $id
            );
        } else {
            $stmt = $this->db->prepare(
                "UPDATE facebook_capi_integrations
                 SET name = ?, pixel_id = ?, access_token = ?, test_code = ?, event_type = ?,
                     use_proxy = ?, proxy_host = ?, proxy_port = ?, proxy_type = ?, proxy_user = ?,
                     updated_at = NOW()
                 WHERE id = ?"
            );
            $stmt->bind_param(
                'sssssissssi',
                $name,
                $pixelId,
                $accessToken,
                $testCode,
                $eventType,
                $useProxy,
                $proxyHost,
                $proxyPort,
                $proxyType,
                $proxyUser,
                $id
            );
        }

        $ok = $stmt->execute();
        if (!$ok) {
            error_log('FacebookCapiIntegration::update failed: ' . $stmt->error);
        }
        return $ok;
    }

    public function delete(int $id): bool
    {
        $checkStmt = $this->db->prepare(
            "SELECT COUNT(*) as count FROM campaigns WHERE facebook_capi_integration_id = ?"
        );
        $checkStmt->bind_param("i", $id);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        $row = $result->fetch_assoc();

        if ($row['count'] > 0) {
            $updateStmt = $this->db->prepare(
                "UPDATE campaigns SET facebook_capi_integration_id = NULL WHERE facebook_capi_integration_id = ?"
            );
            $updateStmt->bind_param("i", $id);
            $updateStmt->execute();
        }

        $stmt = $this->db->prepare("DELETE FROM facebook_capi_integrations WHERE id = ?");
        $stmt->bind_param("i", $id);
        return $stmt->execute();
    }

    public function validate(array $data, bool $isUpdate = false): array
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors[] = "Integration name is required.";
        } elseif (strlen($data['name']) > 100) {
            $errors[] = "Integration name cannot exceed 100 characters.";
        } else {
            $query = "SELECT id FROM facebook_capi_integrations WHERE name = ?";
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

        if (empty($data['pixel_id'])) {
            $errors[] = "Pixel ID is required.";
        } elseif (strlen($data['pixel_id']) > 50) {
            $errors[] = "Pixel ID cannot exceed 50 characters.";
        }

        if (empty($data['access_token'])) {
            if (!$isUpdate) {
                $errors[] = "Access token is required.";
            }
        }

        if (!empty($data['test_code']) && strlen($data['test_code']) > 50) {
            $errors[] = "Test code cannot exceed 50 characters.";
        }

        if (empty($data['event_type'])) {
            $errors[] = "Event type is required.";
        } else {
            $sanitized = MetaCapiEventResolver::sanitizeMetaEventName((string)$data['event_type']);
            if ($sanitized === null) {
                $errors[] = "Event type is invalid.";
            } elseif (strlen($data['event_type']) > 50) {
                $errors[] = "Event type cannot exceed 50 characters.";
            }
        }

        if (isset($data['event_mapping']) && is_array($data['event_mapping'])) {
            $validated = MetaCapiEventResolver::validateMappingInput($data['event_mapping']);
            if (!$validated['ok']) {
                $errors = array_merge($errors, $validated['errors']);
            }
        }

        if (!empty($data['use_proxy'])) {
            if (empty($data['proxy_host'])) {
                $errors[] = "Proxy host is required when proxy is enabled.";
            } elseif (strlen($data['proxy_host']) > 255) {
                $errors[] = "Proxy host cannot exceed 255 characters.";
            }

            if (empty($data['proxy_port'])) {
                $errors[] = "Proxy port is required when proxy is enabled.";
            } elseif (!is_numeric($data['proxy_port']) || $data['proxy_port'] < 1 || $data['proxy_port'] > 65535) {
                $errors[] = "Proxy port must be a number between 1 and 65535.";
            }

            if (empty($data['proxy_type'])) {
                $errors[] = "Proxy type is required when proxy is enabled.";
            } elseif (!in_array($data['proxy_type'], ['HTTP', 'SOCKS5'])) {
                $errors[] = "Proxy type must be either 'HTTP' or 'SOCKS5'.";
            }

            if (!empty($data['proxy_host'])) {
                $host = $data['proxy_host'];
                if (in_array(strtolower($host), ['localhost', '127.0.0.1', '::1']) ||
                    preg_match('/^10\./', $host) ||
                    preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./', $host) ||
                    preg_match('/^192\.168\./', $host) ||
                    preg_match('/^169\.254\./', $host)) {
                    $errors[] = "Proxy host cannot be localhost or a private/internal IP address.";
                }
            }
        }

        return $errors;
    }

    private function hasMappingColumns(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $check = $this->db->query("SHOW COLUMNS FROM facebook_capi_integrations LIKE 'event_mapping_json'");
        $cached = ($check && $check->num_rows > 0);
        return $cached;
    }

    /**
     * @param array<string, string>|string|null $mapping
     */
    private function encodeMapping(array|string|null $mapping): ?string
    {
        if ($mapping === null || $mapping === '') {
            return null;
        }
        if (is_string($mapping)) {
            $decoded = json_decode($mapping, true);
            if (!is_array($decoded)) {
                return null;
            }
            $mapping = $decoded;
        }
        $validated = MetaCapiEventResolver::validateMappingInput($mapping);
        if ($validated['mapping'] === []) {
            return null;
        }
        return json_encode($validated['mapping'], JSON_UNESCAPED_UNICODE);
    }
}
