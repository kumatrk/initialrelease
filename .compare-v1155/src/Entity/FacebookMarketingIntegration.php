<?php

declare(strict_types=1);

namespace SimpleKuma\Entity;

use mysqli;
use SimpleKuma\Utils\ProxyPasswordEncryption;

/**
 * Facebook Marketing Integration Entity
 * Handles CRUD operations for Facebook Marketing API integrations (for cost tracking)
 */
class FacebookMarketingIntegration
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function getAll(): array
    {
        $result = $this->db->query(
            "SELECT fmi.*
             FROM facebook_marketing_integrations fmi
             WHERE fmi.status = 'active'
             ORDER BY fmi.name ASC"
        );

        $integrations = [];
        while ($row = $result->fetch_assoc()) {
            // Don't expose sensitive data in the list
            unset($row['access_token']);
            unset($row['proxy_pass_encrypted']);
            $integrations[] = $row;
        }

        return $integrations;
    }

    public function getAllIncludingPaused(): array
    {
        $result = $this->db->query(
            "SELECT fmi.*
             FROM facebook_marketing_integrations fmi
             ORDER BY fmi.name ASC"
        );

        $integrations = [];
        while ($row = $result->fetch_assoc()) {
            // Don't expose sensitive data in the list
            unset($row['access_token']);
            unset($row['proxy_pass_encrypted']);
            $integrations[] = $row;
        }

        return $integrations;
    }

    public function getById(int $id, bool $includeDecryptedPassword = false): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM facebook_marketing_integrations WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        if ($row && $includeDecryptedPassword && !empty($row['proxy_pass_encrypted'])) {
            try {
                $row['proxy_pass'] = ProxyPasswordEncryption::decrypt($row['proxy_pass_encrypted']);
            } catch (\Exception $e) {
                // If decryption fails, leave proxy_pass empty
                $row['proxy_pass'] = '';
                error_log("Failed to decrypt proxy password for integration {$id}: " . $e->getMessage());
            }
        }
        
        return $row;
    }

    public function create(array $data): ?int
    {
        // Encrypt proxy password if provided
        $proxyPassEncrypted = null;
        if (!empty($data['proxy_pass'])) {
            try {
                $proxyPassEncrypted = ProxyPasswordEncryption::encrypt($data['proxy_pass']);
            } catch (\Exception $e) {
                error_log("Failed to encrypt proxy password: " . $e->getMessage());
                return null;
            }
        }
        
        $stmt = $this->db->prepare(
            "INSERT INTO facebook_marketing_integrations 
             (name, access_token, ad_account_id, status, use_proxy, proxy_host, proxy_port, proxy_type, proxy_user, proxy_pass_encrypted, created_at) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        
        // Extract values to variables for bind_param (must be by reference)
        $name = $data['name'];
        $accessToken = $data['access_token'];
        $adAccountId = $data['ad_account_id'] ?? null;
        $status = $data['status'] ?? 'active';
        $useProxy = isset($data['use_proxy']) ? (int)(bool)$data['use_proxy'] : 0;
        $proxyHost = $data['proxy_host'] ?? null;
        $proxyPort = !empty($data['proxy_port']) ? (int)$data['proxy_port'] : null;
        $proxyType = $data['proxy_type'] ?? null;
        $proxyUser = $data['proxy_user'] ?? null;
        
        $stmt->bind_param(
            'ssssisssss',
            $name,
            $accessToken,
            $adAccountId,
            $status,
            $useProxy,
            $proxyHost,
            $proxyPort,
            $proxyType,
            $proxyUser,
            $proxyPassEncrypted
        );
        
        if ($stmt->execute()) {
            return $stmt->insert_id;
        }
        
        return null;
    }

    public function update(int $id, array $data): bool
    {
        // Handle proxy password encryption
        // If proxy_pass is provided and not empty, encrypt it
        // If proxy_pass is empty string, clear the encrypted password
        // If proxy_pass is not in data array, keep existing encrypted password
        $proxyPassEncrypted = null;
        $updateProxyPass = false;
        
        if (isset($data['proxy_pass'])) {
            if (empty($data['proxy_pass'])) {
                // Empty string means clear the password
                $proxyPassEncrypted = null;
                $updateProxyPass = true;
            } else {
                // Encrypt the new password
                try {
                    $proxyPassEncrypted = ProxyPasswordEncryption::encrypt($data['proxy_pass']);
                    $updateProxyPass = true;
                } catch (\Exception $e) {
                    error_log("Failed to encrypt proxy password: " . $e->getMessage());
                    return false;
                }
            }
        }
        
        // Build UPDATE query dynamically based on whether we're updating proxy password
        if ($updateProxyPass) {
            $stmt = $this->db->prepare(
                "UPDATE facebook_marketing_integrations 
                 SET name = ?, access_token = ?, ad_account_id = ?, status = ?, 
                     use_proxy = ?, proxy_host = ?, proxy_port = ?, proxy_type = ?, proxy_user = ?, proxy_pass_encrypted = ?, updated_at = NOW() 
                 WHERE id = ?"
            );
        } else {
            $stmt = $this->db->prepare(
                "UPDATE facebook_marketing_integrations 
                 SET name = ?, access_token = ?, ad_account_id = ?, status = ?, 
                     use_proxy = ?, proxy_host = ?, proxy_port = ?, proxy_type = ?, proxy_user = ?, updated_at = NOW() 
                 WHERE id = ?"
            );
        }
        
        // Extract values to variables for bind_param (must be by reference)
        $name = $data['name'];
        $accessToken = $data['access_token'];
        $adAccountId = $data['ad_account_id'] ?? null;
        $status = $data['status'] ?? 'active';
        $useProxy = isset($data['use_proxy']) ? (int)(bool)$data['use_proxy'] : 0;
        $proxyHost = $data['proxy_host'] ?? null;
        $proxyPort = !empty($data['proxy_port']) ? (int)$data['proxy_port'] : null;
        $proxyType = $data['proxy_type'] ?? null;
        $proxyUser = $data['proxy_user'] ?? null;
        
        if ($updateProxyPass) {
            $stmt->bind_param(
                'ssssisssssi',
                $name,
                $accessToken,
                $adAccountId,
                $status,
                $useProxy,
                $proxyHost,
                $proxyPort,
                $proxyType,
                $proxyUser,
                $proxyPassEncrypted,
                $id
            );
        } else {
            $stmt->bind_param(
                'ssssissssi',
                $name,
                $accessToken,
                $adAccountId,
                $status,
                $useProxy,
                $proxyHost,
                $proxyPort,
                $proxyType,
                $proxyUser,
                $id
            );
        }
        
        return $stmt->execute();
    }

    public function delete(int $id): bool
    {
        // Note: Foreign key constraints will handle cascading deletes for hourly cost tables
        $stmt = $this->db->prepare("DELETE FROM facebook_marketing_integrations WHERE id = ?");
        $stmt->bind_param("i", $id);
        return $stmt->execute();
    }

    public function validate(array $data, bool $isUpdate = false): array
    {
        $errors = [];
        
        if (empty($data['name'])) {
            $errors[] = "Integration name is required.";
        } elseif (strlen($data['name']) > 255) {
            $errors[] = "Integration name cannot exceed 255 characters.";
        } else {
            // Check for unique name
            $query = "SELECT id FROM facebook_marketing_integrations WHERE name = ?";
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
        
        if (empty($data['access_token'])) {
            $errors[] = "Access token is required.";
        }
        
        if (!empty($data['ad_account_id']) && strlen($data['ad_account_id']) > 50) {
            $errors[] = "Ad Account ID cannot exceed 50 characters.";
        }
        
        if (!empty($data['status']) && !in_array($data['status'], ['active', 'paused'])) {
            $errors[] = "Status must be either 'active' or 'paused'.";
        }
        
        // Validate proxy settings if use_proxy is enabled
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
            
            // Validate proxy_host to prevent SSRF (block internal IPs)
            if (!empty($data['proxy_host'])) {
                $host = $data['proxy_host'];
                // Block localhost and private IP ranges
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

    /**
     * Validate access token by making a test API call to Facebook
     */
    public function validateAccessToken(string $accessToken): array
    {
        $errors = [];

        if (str_contains($accessToken, '|')) {
            $errors[] = 'This looks like an app access token (app_id|app_secret). Use a user or system-user token from Business Settings (starts with EA…).';
            return $errors;
        }

        // Meta user/system tokens use EA* prefixes (EAA long-lived, EAF short-lived from Graph Explorer / Marketing API Tools, etc.)
        if (!preg_match('/^EA[A-Za-z][A-Za-z0-9]{20,}$/', $accessToken)) {
            $errors[] = 'Access token should be a user or system-user token from Meta (starts with EA…), not an App ID or App Secret.';
        }
        
        try {
            $url = 'https://graph.facebook.com/v24.0/me/adaccounts?access_token='
                . urlencode($accessToken)
                . '&fields=id&limit=1';
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            
            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                $errors[] = 'Unable to reach Facebook API: ' . $curlError;
                return $errors;
            }
            
            if ($httpCode !== 200) {
                $responseData = json_decode(is_string($response) ? $response : '', true);
                if (isset($responseData['error'])) {
                    $errors[] = 'Invalid access token or missing ads_read permission: '
                        . ($responseData['error']['message'] ?? 'Unknown error');
                } else {
                    $errors[] = "Unable to validate access token. HTTP Code: {$httpCode}";
                }
            }
        } catch (\Exception $e) {
            $errors[] = "Error validating access token: " . $e->getMessage();
        }
        
        return $errors;
    }
}


