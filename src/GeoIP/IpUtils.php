<?php

declare(strict_types=1);

namespace SimpleKuma\GeoIP;

/**
 * IP Utility Functions
 * 
 * Provides validation and private IP detection for both IPv4 and IPv6.
 */
class IpUtils
{
    /**
     * Check if an IP address is valid (IPv4 or IPv6)
     * 
     * @param string $ip IP address to validate
     * @return bool True if valid
     */
    public static function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Check if an IP address is private/local
     * 
     * Detects:
     * - IPv4: RFC1918 private ranges, loopback, link-local
     * - IPv6: link-local (fe80::/10), unique-local (fc00::/7), loopback (::1), IPv4-mapped
     * 
     * @param string $ip IP address to check
     * @return bool True if private/local
     */
    public static function isPrivateIp(string $ip): bool
    {
        // Check if it's a valid IP first
        if (!self::isValidIp($ip)) {
            return true; // Invalid IPs are treated as private
        }

        // Check for IPv4 private ranges
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return !filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
        }

        // Check for IPv6 private ranges
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // IPv6 private ranges:
            // - fe80::/10 (link-local)
            // - fc00::/7 (unique-local: fc00::/8 and fd00::/8)
            // - ::1 (localhost)
            // - ::ffff:0:0/96 (IPv4-mapped)
            if (strpos($ip, 'fe80:') === 0 ||
                strpos($ip, 'fc00:') === 0 ||
                strpos($ip, 'fd00:') === 0 ||
                $ip === '::1' ||
                strpos($ip, '::ffff:') === 0) {
                return true;
            }
            return false; // Public IPv6
        }

        return true; // Unknown format, treat as private
    }

    /**
     * Normalize IP address format
     * 
     * @param string $ip IP address
     * @return string Normalized IP or original if invalid
     */
    public static function normalizeIp(string $ip): string
    {
        $ip = trim($ip);
        
        // Remove brackets from IPv6 if present
        if (strpos($ip, '[') === 0 && strpos($ip, ']') !== false) {
            $ip = substr($ip, 1, -1);
        }
        
        return $ip;
    }
}


