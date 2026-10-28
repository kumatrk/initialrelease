<?php

declare(strict_types=1);

namespace SimpleKuma\Entity;

use mysqli;
use SimpleKuma\Settings\SettingsManager;

/**
 * Proves a hostname reaches this Kuma install.
 *
 * Cloudflare-proxied tracking domains resolve to Cloudflare, not the origin IP,
 * so an A-record comparison fails. A request to this install's domain-check
 * endpoint only succeeds when the hostname is actually served by this copy.
 */
final class DomainOriginProof
{
    public const SETTINGS_KEY = 'tracking_domain_verify_token';
    public const ENDPOINT_PATH = '/api/domain-check.php';

    public static function ensureToken(mysqli $db): string
    {
        $settings = new SettingsManager($db);
        $token = trim((string) $settings->get(self::SETTINGS_KEY, ''));
        if (preg_match('/^[a-f0-9]{32}$/', $token) === 1) {
            return $token;
        }
        $token = bin2hex(random_bytes(16));
        $settings->set(self::SETTINGS_KEY, $token);

        return $token;
    }

    public static function matchesResponse(int $httpCode, string $body, string $expectedToken): bool
    {
        if ($httpCode < 200 || $httpCode >= 300 || $expectedToken === '') {
            return false;
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return false;
        }
        $got = (string) ($data['token'] ?? '');
        if ($got === '') {
            return false;
        }

        return hash_equals($expectedToken, $got);
    }
}
