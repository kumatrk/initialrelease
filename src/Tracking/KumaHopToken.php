<?php

declare(strict_types=1);

namespace SimpleKuma\Tracking;

/**
 * Signed tokens for kumahop.php — prevents open redirect abuse.
 */
final class KumaHopToken
{
    private const TTL_SECONDS = 600;

    public static function sign(string $destinationUrl): string
    {
        $payload = json_encode([
            'u' => $destinationUrl,
            'e' => time() + self::TTL_SECONDS,
        ], JSON_UNESCAPED_SLASHES);

        $encoded = self::base64UrlEncode($payload);
        $signature = hash_hmac('sha256', $encoded, self::secret(), true);

        return $encoded . '.' . self::base64UrlEncode($signature);
    }

    public static function verify(string $token): ?string
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$encoded, $signature] = $parts;
        $expected = self::base64UrlEncode(hash_hmac('sha256', $encoded, self::secret(), true));
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $json = self::base64UrlDecode($encoded);
        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['u']) || (int) ($data['e'] ?? 0) < time()) {
            return null;
        }

        $url = (string) $data['u'];

        return KumaHopRedirect::isAllowedDestination($url) ? $url : null;
    }

    private static function secret(): string
    {
        if (defined('APP_KEY') && APP_KEY !== '') {
            return (string) APP_KEY;
        }

        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'));
        $isLocal = str_contains($host, 'localhost') || str_contains($host, '127.0.0.1') || str_ends_with($host, '.local');
        if (!$isLocal && (!defined('APP_ENV') || APP_ENV === 'production')) {
            throw new \RuntimeException('APP_KEY must be configured for KumaHop token signing');
        }

        return hash('sha256', 'kuma-hop-local|' . ($host !== '' ? $host : 'localhost') . '|' . __DIR__);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $pad = strlen($data) % 4;
        if ($pad > 0) {
            $data .= str_repeat('=', 4 - $pad);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}
