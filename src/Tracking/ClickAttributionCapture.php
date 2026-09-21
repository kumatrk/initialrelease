<?php

declare(strict_types=1);

namespace SimpleKuma\Tracking;

/**
 * Shared click-time attribution enrichment for Meta CAPI, Whop Ads, etc.
 * Keeps network-specific secrets out of the hot path; only stores first-party cookies/params.
 *
 * Whop Ads notes:
 * - Pixel cookie `_wuid` is first-party to the page that runs the pixel (usually the owned LP),
 *   not the tracker host. LP CTAs should pass `_wuid` (and ideally `whop_page_url`) into Kuma.
 * - Whop resolves campaign/ad from the full landing URL query string (wacid/wasid/waid + Meta UTMs).
 *
 * @see https://docs.whop.com/developer/ads/events-api
 */
final class ClickAttributionCapture
{
    private const MAX_LANDING_URL_LEN = 2048;
    private const MAX_COOKIE_LEN = 512;

    /**
     * Enrich extra_json with cookies, original landing URL, and common visitor IDs.
     *
     * @param array<string, mixed> $extraData
     * @param array<string, mixed> $params Request query/body params already captured
     * @return array<string, mixed>
     */
    public static function enrich(array $extraData, array $params, ?array $server = null): array
    {
        $server = $server ?? $_SERVER;
        if (!isset($extraData['cookies']) || !is_array($extraData['cookies'])) {
            $extraData['cookies'] = [];
        }
        if (!isset($extraData['all_params']) || !is_array($extraData['all_params'])) {
            $extraData['all_params'] = $params;
        }

        foreach (['_fbc', '_fbp', '_wuid'] as $cookieKey) {
            $fromCookie = self::cookieValue($cookieKey);
            $fromParam = self::paramString($params, $cookieKey);
            if ($cookieKey === '_wuid' && $fromParam === '') {
                $fromParam = self::paramString($params, 'whop_visitor_id');
            }
            $value = $fromCookie !== '' ? $fromCookie : $fromParam;
            if ($value === '') {
                continue;
            }
            $value = self::limit($value, self::MAX_COOKIE_LEN);
            $extraData['cookies'][$cookieKey] = $value;
            $extraData['all_params'][$cookieKey] = $value;
        }

        if (!empty($extraData['cookies']['_wuid'])) {
            $extraData['whop_visitor_id'] = (string) $extraData['cookies']['_wuid'];
        }

        // Prefer the page URL from the Whop pixel domain (LP handoff), then the live request URL.
        $handedOff = self::paramString($params, 'whop_page_url');
        if ($handedOff === '') {
            $handedOff = self::paramString($params, 'whop_landing_url');
        }
        if ($handedOff !== '' && str_starts_with($handedOff, 'http')) {
            $extraData['original_landing_url'] = self::limit($handedOff, self::MAX_LANDING_URL_LEN);
            $extraData['whop_page_url'] = $extraData['original_landing_url'];
        } else {
            $landingUrl = self::buildOriginalLandingUrl($server, $params);
            if ($landingUrl !== null) {
                $extraData['original_landing_url'] = $landingUrl;
            }
        }

        return $extraData;
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $params
     */
    public static function buildOriginalLandingUrl(array $server, array $params): ?string
    {
        $scheme = (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') ? 'https' : 'http';
        if (!empty($server['HTTP_X_FORWARDED_PROTO'])) {
            $fwd = strtolower(trim(explode(',', (string) $server['HTTP_X_FORWARDED_PROTO'])[0]));
            if ($fwd === 'https' || $fwd === 'http') {
                $scheme = $fwd;
            }
        }
        $host = trim((string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? ''));
        if ($host === '') {
            return null;
        }

        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        if ($uri === '' || $uri[0] !== '/') {
            $uri = '/' . ltrim($uri, '/');
        }

        // Prefer the live request URI (includes query). Fall back to rebuilt query from params.
        if (str_contains($uri, '?')) {
            $url = $scheme . '://' . $host . $uri;
        } else {
            $qs = http_build_query(array_filter(
                $params,
                static fn($v, $k): bool => is_scalar($v) && (string) $k !== 'Tf',
                ARRAY_FILTER_USE_BOTH
            ));
            $url = $scheme . '://' . $host . $uri . ($qs !== '' ? '?' . $qs : '');
        }

        return self::limit($url, self::MAX_LANDING_URL_LEN);
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function paramString(array $params, string $key): string
    {
        if (!isset($params[$key]) || !is_scalar($params[$key])) {
            return '';
        }
        return trim((string) $params[$key]);
    }

    private static function cookieValue(string $name): string
    {
        if (empty($_COOKIE[$name]) || !is_scalar($_COOKIE[$name])) {
            return '';
        }
        return trim((string) $_COOKIE[$name]);
    }

    private static function limit(string $value, int $max): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }
        return substr($value, 0, $max);
    }
}
