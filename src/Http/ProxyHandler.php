<?php

declare(strict_types=1);

namespace SimpleKuma\Http;

use SimpleKuma\Utils\ProxyPasswordEncryption;

/**
 * Proxy Handler
 * Configures cURL requests to use proxy settings from integration configurations
 * Supports HTTP and SOCKS5 proxies with optional authentication
 */
class ProxyHandler
{
    /**
     * Configure a cURL handle with proxy settings
     * 
     * @param resource $ch cURL handle
     * @param array $proxyConfig Proxy configuration array with keys:
     *   - use_proxy (bool): Whether to use proxy
     *   - proxy_host (string): Proxy hostname or IP
     *   - proxy_port (int): Proxy port
     *   - proxy_type (string): 'HTTP' or 'SOCKS5'
     *   - proxy_user (string|null): Optional username
     *   - proxy_pass_encrypted (string|null): Encrypted password (will be decrypted)
     * @return bool True if proxy was configured, false otherwise
     */
    public static function configureCurl($ch, array $proxyConfig): bool
    {
        if (empty($proxyConfig['use_proxy']) || empty($proxyConfig['proxy_host']) || empty($proxyConfig['proxy_port'])) {
            return false;
        }

        $proxyHost = $proxyConfig['proxy_host'];
        $proxyPort = (int)$proxyConfig['proxy_port'];
        $proxyType = strtoupper($proxyConfig['proxy_type'] ?? 'HTTP');

        // Build proxy URL
        $proxyUrl = self::buildProxyUrl($proxyHost, $proxyPort, $proxyType, $proxyConfig);
        
        // Log masked proxy URL for debugging (password hidden)
        $maskedUrl = self::maskProxyUrl($proxyUrl);
        error_log("ProxyHandler: Configuring cURL with proxy: {$maskedUrl}");
        
        if ($proxyType === 'SOCKS5') {
            // SOCKS5 proxy
            curl_setopt($ch, CURLOPT_PROXY, $proxyUrl);
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
        } else {
            // HTTP proxy (default)
            curl_setopt($ch, CURLOPT_PROXY, $proxyUrl);
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
        }

        // Set proxy timeout (separate from request timeout)
        curl_setopt($ch, CURLOPT_PROXYTIMEOUT, 10);

        return true;
    }

    /**
     * Build proxy URL with optional authentication
     * 
     * @param string $host Proxy hostname
     * @param int $port Proxy port
     * @param string $type Proxy type (HTTP or SOCKS5)
     * @param array $config Full proxy config (for username/password)
     * @return string Proxy URL in format: http://[user:pass@]host:port
     */
    private static function buildProxyUrl(string $host, int $port, string $type, array $config): string
    {
        $scheme = strtolower($type) === 'socks5' ? 'socks5' : 'http';
        $url = "{$scheme}://";

        // Add authentication if provided
        if (!empty($config['proxy_user'])) {
            $username = $config['proxy_user'];
            $password = '';

            // Decrypt password if provided
            if (!empty($config['proxy_pass_encrypted'])) {
                try {
                    $password = ProxyPasswordEncryption::decrypt($config['proxy_pass_encrypted']);
                } catch (\Exception $e) {
                    error_log("ProxyHandler: Failed to decrypt proxy password: " . $e->getMessage());
                    error_log("ProxyHandler: Encryption key may not be initialized. Check APP_KEY in config.php");
                    // Continue without password - may fail auth but won't crash
                    $password = '';
                }
            }

            if (!empty($password)) {
                $url .= urlencode($username) . ':' . urlencode($password) . '@';
            } else {
                $url .= urlencode($username) . '@';
            }
        }

        $url .= $host . ':' . $port;

        return $url;
    }

    /**
     * Mask proxy URL for logging (removes password)
     * 
     * @param string $proxyUrl Full proxy URL
     * @return string Masked proxy URL safe for logging
     */
    public static function maskProxyUrl(string $proxyUrl): string
    {
        // Pattern: scheme://user:pass@host:port
        // Replace password with ****
        return preg_replace('/(:\/\/[^:]+:)([^@]+)(@)/', '$1****$3', $proxyUrl);
    }

    /**
     * Get proxy configuration from integration array
     * Extracts and prepares proxy settings for use with configureCurl
     * 
     * @param array $integration Integration data array (from database)
     * @return array Proxy configuration array, or empty array if proxy not enabled
     */
    public static function getProxyConfig(array $integration): array
    {
        if (empty($integration['use_proxy'])) {
            return [];
        }

        // Validate required proxy fields
        if (empty($integration['proxy_host']) || empty($integration['proxy_port'])) {
            error_log("ProxyHandler: Proxy enabled but missing required fields (host or port) for integration ID: " . ($integration['id'] ?? 'unknown'));
            return [];
        }

        return [
            'use_proxy' => (bool)$integration['use_proxy'],
            'proxy_host' => $integration['proxy_host'] ?? null,
            'proxy_port' => !empty($integration['proxy_port']) ? (int)$integration['proxy_port'] : null,
            'proxy_type' => $integration['proxy_type'] ?? 'HTTP',
            'proxy_user' => $integration['proxy_user'] ?? null,
            'proxy_pass_encrypted' => $integration['proxy_pass_encrypted'] ?? null,
        ];
    }

    /**
     * Test proxy connection
     * Makes a test request through the proxy to verify it works
     * 
     * @param array $proxyConfig Proxy configuration
     * @param string $testUrl URL to test (default: https://www.google.com)
     * @return array ['success' => bool, 'error' => string|null, 'response_time' => float|null]
     */
    public static function testConnection(array $proxyConfig, string $testUrl = 'https://www.google.com'): array
    {
        if (empty($proxyConfig['use_proxy'])) {
            return ['success' => false, 'error' => 'Proxy not enabled', 'response_time' => null];
        }

        $ch = curl_init();
        $startTime = microtime(true);

        curl_setopt($ch, CURLOPT_URL, $testUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request only

        // Configure proxy
        $proxyConfigured = self::configureCurl($ch, $proxyConfig);

        if (!$proxyConfigured) {
            curl_close($ch);
            return ['success' => false, 'error' => 'Invalid proxy configuration', 'response_time' => null];
        }

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $responseTime = microtime(true) - $startTime;
        
        curl_close($ch);

        if ($curlError) {
            return [
                'success' => false,
                'error' => 'Proxy connection failed: ' . $curlError,
                'response_time' => $responseTime
            ];
        }

        if ($httpCode >= 200 && $httpCode < 400) {
            return [
                'success' => true,
                'error' => null,
                'response_time' => $responseTime
            ];
        }

        return [
            'success' => false,
            'error' => "Unexpected HTTP code: {$httpCode}",
            'response_time' => $responseTime
        ];
    }
}

