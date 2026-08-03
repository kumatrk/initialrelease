<?php

declare(strict_types=1);

namespace SimpleKuma\Entity;

use mysqli;

/**
 * TrackingDomain Entity
 * Handles CRUD operations for custom tracking domains
 */
class TrackingDomain
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Get all tracking domains
     */
    public function getAll(): array
    {
        $result = $this->db->query(
            "SELECT id, domain, status, verified_at, created_at, updated_at
             FROM tracking_domains
             ORDER BY created_at DESC"
        );

        if (!$result) {
            return [];
        }

        $domains = [];
        while ($row = $result->fetch_assoc()) {
            $domains[] = $row;
        }

        return $domains;
    }

    /**
     * Get tracking domain by ID
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, domain, status, verified_at, created_at, updated_at
             FROM tracking_domains
             WHERE id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        return $row ?: null;
    }

    /**
     * Get tracking domain by domain URL
     */
    public function getByDomain(string $domain): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, domain, status, verified_at, created_at, updated_at
             FROM tracking_domains
             WHERE domain = ?"
        );
        $stmt->bind_param('s', $domain);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        return $row ?: null;
    }

    /**
     * Create new tracking domain
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO tracking_domains (domain, status, created_at)
             VALUES (?, 'pending', NOW())"
        );

        $stmt->bind_param('s', $data['domain']);
        $stmt->execute();

        return $stmt->insert_id;
    }

    /**
     * Update tracking domain
     */
    public function update(int $id, array $data): bool
    {
        $updates = [];
        $params = [];
        $types = '';

        if (isset($data['domain'])) {
            $updates[] = 'domain = ?';
            $params[] = $data['domain'];
            $types .= 's';
        }

        if (isset($data['status'])) {
            $updates[] = 'status = ?';
            $params[] = $data['status'];
            $types .= 's';
        }

        if (isset($data['verified_at'])) {
            $updates[] = 'verified_at = ?';
            $params[] = $data['verified_at'];
            $types .= 's';
        }

        if (empty($updates)) {
            return false;
        }

        $updates[] = 'updated_at = NOW()';
        $types .= 'i';
        $params[] = $id;

        $sql = "UPDATE tracking_domains SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        
        return $stmt->execute();
    }

    /**
     * Delete tracking domain
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM tracking_domains WHERE id = ?");
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }

    /**
     * Statuses that allow the domain to be selected in campaigns and postbacks.
     */
    public static function usableStatuses(): array
    {
        return ['verified', 'verified_manual'];
    }

    /**
     * Whether a domain can be manually approved for use.
     */
    public static function canBypassVerification(string $status): bool
    {
        return !in_array(trim($status), self::usableStatuses(), true);
    }

    /**
     * Human-readable label and badge colors for a domain status.
     */
    public static function statusDisplay(string $status): array
    {
        $map = [
            'verified' => ['label' => 'Verified', 'bg' => '#d4edda', 'text' => '#155724', 'border' => '#28a745'],
            'verified_manual' => ['label' => 'Verified (Manual)', 'bg' => '#cce5ff', 'text' => '#004085', 'border' => '#007bff'],
            'pending' => ['label' => 'Pending', 'bg' => '#fff3cd', 'text' => '#856404', 'border' => '#ffc107'],
            'failed' => ['label' => 'Failed', 'bg' => '#f8d7da', 'text' => '#721c24', 'border' => '#dc3545'],
        ];

        return $map[$status] ?? $map['pending'];
    }

    /**
     * Manually approve a domain for use without passing automated verification.
     */
    public function bypassVerification(int $id): bool
    {
        $domain = $this->getById($id);
        if (!$domain) {
            return false;
        }

        return $this->update($id, [
            'status' => 'verified_manual',
            'verified_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Verify domain (check DNS and SSL)
     * Returns array with 'dns_ok', 'ssl_ok', 'status', and detailed error messages
     */
    public function verify(int $id): array
    {
        $domain = $this->getById($id);
        if (!$domain) {
            return ['dns_ok' => false, 'ssl_ok' => false, 'status' => 'failed', 'error' => 'Domain not found'];
        }

        $domainUrl = $domain['domain'];
        $parsedUrl = parse_url($domainUrl);
        $host = $parsedUrl['host'] ?? '';

        if (empty($host)) {
            $this->update($id, ['status' => 'failed', 'verified_at' => date('Y-m-d H:i:s')]);
            return ['dns_ok' => false, 'ssl_ok' => false, 'status' => 'failed', 'error' => 'Invalid domain format - could not extract hostname'];
        }

        $errors = [];
        
        // Check DNS - verify domain resolves
        $dnsOk = false;
        $dnsError = '';
        
        // Check if this is localhost or local IP
        $isLocal = in_array(strtolower($host), ['localhost', '127.0.0.1', '::1']) || 
                   filter_var($host, FILTER_VALIDATE_IP) !== false ||
                   preg_match('/\.local$/', $host) ||
                   preg_match('/^192\.168\./', $host) ||
                   preg_match('/^10\./', $host) ||
                   preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $host);
        
        if ($isLocal) {
            // For local domains, skip DNS check but note it
            $dnsOk = true; // Assume local domains are OK
            $dnsError = "Local domain detected - DNS check skipped (localhost/local IP)";
        } else {
            // Get tracker's base URL to verify domain points to correct server
            $baseUrl = defined('BASE_URL') ? BASE_URL : '';
            $baseHost = '';
            $serverIps = [];
            
            if (!empty($baseUrl)) {
                $baseParsed = parse_url($baseUrl);
                $baseHost = $baseParsed['host'] ?? '';
                
                // Resolve base URL host to get server IPs
                if (!empty($baseHost) && !in_array(strtolower($baseHost), ['localhost', '127.0.0.1'])) {
                    $baseRecords = @dns_get_record($baseHost, DNS_A);
                    if ($baseRecords) {
                        foreach ($baseRecords as $record) {
                            if (isset($record['ip'])) {
                                $serverIps[] = $record['ip'];
                            }
                        }
                    }
                }
            }
            
            // Try DNS lookup for public domains
            $records = @dns_get_record($host, DNS_A + DNS_CNAME);
            if ($records === false || count($records) === 0) {
                $dnsOk = false;
                $dnsError = "DNS lookup failed - domain '$host' does not resolve or has no A/CNAME records. This might be a local domain that's not publicly accessible.";
            } else {
                $dnsOk = false; // Start as false, will be set to true if pointing correctly
                $resolvedIps = [];
                $cnameTargets = [];
                
                // Check each DNS record
                foreach ($records as $record) {
                    if (isset($record['type'])) {
                        if ($record['type'] === 'A' && isset($record['ip'])) {
                            $resolvedIps[] = $record['ip'];
                        } elseif ($record['type'] === 'CNAME' && isset($record['target'])) {
                            $cnameTargets[] = strtolower(rtrim($record['target'], '.'));
                        }
                    }
                }
                
                // Verify A records point to server IP
                if (!empty($resolvedIps)) {
                    if (!empty($serverIps)) {
                        // Check if any resolved IP matches server IP
                        $matches = array_intersect($resolvedIps, $serverIps);
                        if (!empty($matches)) {
                            $dnsOk = true;
                            $dnsError = "DNS OK - Domain points to correct server IP (" . implode(', ', $matches) . ")";
                        } else {
                            $dnsError = "DNS records found, but domain points to different IP(s): " . implode(', ', $resolvedIps) . ". Expected server IP(s): " . implode(', ', $serverIps);
                        }
                    } else {
                        // Can't verify server IP - BASE_URL host is localhost or not resolvable
                        // This means we can't confirm the domain points to this server
                        $dnsOk = false;
                        $dnsError = "DNS records found pointing to: " . implode(', ', $resolvedIps) . ", but unable to verify if this matches your server. BASE_URL is set to localhost or the host is not publicly resolvable. For localhost installations, you must manually verify the domain points to your server.";
                    }
                }
                
                // Verify CNAME records point to base URL host
                if (!empty($cnameTargets)) {
                    if (!empty($baseHost)) {
                        $baseHostLower = strtolower($baseHost);
                        $matches = false;
                        foreach ($cnameTargets as $target) {
                            if ($target === $baseHostLower || strpos($target, $baseHostLower) !== false) {
                                $matches = true;
                                break;
                            }
                        }
                        if ($matches) {
                            $dnsOk = true;
                            $dnsError = "DNS OK - CNAME points to correct host: " . implode(', ', $cnameTargets);
                        } else {
                            if (!$dnsOk) { // Only set error if A record check didn't pass
                                $dnsError = "DNS records found, but CNAME points to: " . implode(', ', $cnameTargets) . ". Expected host: " . $baseHost;
                            }
                        }
                    } else {
                        // Can't verify base host - BASE_URL not configured or is localhost
                        if (!$dnsOk) {
                            $dnsOk = false;
                            $dnsError = "DNS records found (CNAME: " . implode(', ', $cnameTargets) . "), but unable to verify if this matches your server. BASE_URL is not configured or is set to localhost. For localhost installations, you must manually verify the domain points to your server.";
                        }
                    }
                }
                
                // If we have records but couldn't verify pointing, mark as failed
                // User must manually verify for localhost installations
                if (!$dnsOk && (empty($serverIps) && empty($baseHost))) {
                    $dnsError = "DNS records found (" . count($records) . " record(s)), but unable to verify if domain points to this tracker. BASE_URL is set to localhost or not configured. For localhost installations, you must manually verify the domain points to your server's IP address.";
                }
            }
        }
        
        if (!$dnsOk) {
            $errors[] = $dnsError;
        }

        // Check SSL - verify HTTPS certificate exists and is accessible
        $sslOk = false;
        $sslError = '';
        
        // Use curl for better SSL checking
        if (function_exists('curl_init')) {
            $ch = curl_init($domainUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOBODY => true,
                CURLOPT_HEADER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false, // Allow self-signed for testing
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);
            curl_close($ch);
            
            if ($curlErrno !== 0) {
                $sslError = "SSL/Connection Error: " . ($curlError ?: "Error code $curlErrno");
            } elseif ($httpCode >= 200 && $httpCode < 400) {
                $sslOk = true;
                $sslError = "SSL OK - HTTPS accessible (HTTP $httpCode)";
            } else {
                $sslError = "HTTPS returned HTTP $httpCode";
            }
        } else {
            // Fallback to file_get_contents if curl not available
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ],
                'http' => [
                    'timeout' => 10,
                    'method' => 'HEAD',
                    'ignore_errors' => true
                ]
            ]);
            
            $oldErrorReporting = error_reporting(E_ALL);
            $oldDisplayErrors = ini_get('display_errors');
            ini_set('display_errors', '0');
            
            $result = @file_get_contents($domainUrl, false, $context);
            $httpCode = 0;
            if (isset($http_response_header) && is_array($http_response_header)) {
                foreach ($http_response_header as $header) {
                    if (preg_match('/HTTP\/\d\.\d\s+(\d+)/', $header, $matches)) {
                        $httpCode = (int)$matches[1];
                        break;
                    }
                }
            }
            
            error_reporting($oldErrorReporting);
            ini_set('display_errors', $oldDisplayErrors);
            
            if ($result !== false || ($httpCode >= 200 && $httpCode < 400)) {
                $sslOk = true;
                $sslError = "SSL OK - HTTPS accessible" . ($httpCode ? " (HTTP $httpCode)" : "");
            } else {
                $sslError = "SSL/Connection failed - Could not connect via HTTPS" . ($httpCode ? " (HTTP $httpCode)" : "");
            }
        }
        
        if (!$sslOk) {
            $errors[] = $sslError;
        }

        // Update status based on verification
        $status = 'pending';
        if ($dnsOk && $sslOk) {
            $status = 'verified';
        } elseif (!$dnsOk || !$sslOk) {
            $status = 'failed';
        }

        $this->update($id, [
            'status' => $status,
            'verified_at' => date('Y-m-d H:i:s')
        ]);

        return [
            'dns_ok' => $dnsOk,
            'ssl_ok' => $sslOk,
            'status' => $status,
            'dns_message' => $dnsError,
            'ssl_message' => $sslError,
            'error' => !empty($errors) ? implode(' | ', $errors) : null
        ];
    }

    /**
     * Validate domain data
     */
    public function validate(array $data, bool $isUpdate = false): array
    {
        $errors = [];

        if (!$isUpdate || isset($data['domain'])) {
            if (empty($data['domain'])) {
                $errors['domain'] = 'Domain is required';
            } elseif (!filter_var($data['domain'], FILTER_VALIDATE_URL)) {
                $errors['domain'] = 'Invalid domain URL format';
            } elseif (!preg_match('/^https:\/\//', $data['domain'])) {
                $errors['domain'] = 'Domain must start with https://';
            } else {
                // Check uniqueness
                $stmt = $this->db->prepare("SELECT id FROM tracking_domains WHERE domain = ?");
                $stmt->bind_param('s', $data['domain']);
                $stmt->execute();
                $result = $stmt->get_result();
                $existing = $result->fetch_assoc();
                
                if ($existing && (!$isUpdate || $existing['id'] != ($data['id'] ?? 0))) {
                    $errors['domain'] = 'Domain already exists';
                }
            }
        }

        if (isset($data['status']) && !in_array($data['status'], ['pending', 'verified', 'verified_manual', 'failed'])) {
            $errors['status'] = 'Invalid status';
        }

        return $errors;
    }

    /**
     * Get verified domains only
     */
    public function getVerified(): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, domain, status, verified_at, created_at, updated_at
             FROM tracking_domains
             WHERE status IN ('verified', 'verified_manual')
             ORDER BY domain ASC"
        );
        $stmt->execute();
        $result = $stmt->get_result();

        $domains = [];
        while ($row = $result->fetch_assoc()) {
            $domains[] = $row;
        }

        return $domains;
    }
}

