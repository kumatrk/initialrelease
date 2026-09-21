<?php

declare(strict_types=1);

namespace SimpleKuma\Enrichment;

/**
 * Normalize connection-type labels from traffic-source macros or ASN heuristics.
 *
 * IP geolocation cannot reliably detect Wi-Fi; residential Wi-Fi looks like Broadband.
 */
final class ConnectionTypeNormalizer
{
    public const CELLULAR = 'Cellular';
    public const BROADBAND = 'Broadband';
    public const CORPORATE = 'Corporate';
    public const UNKNOWN = 'Unknown';

    /**
     * Map raw TS token / heuristic string to a canonical label, or null if empty.
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $raw = trim($raw);
        if ($raw === '' || strcasecmp($raw, 'N/A') === 0) {
            return null;
        }

        $lower = strtolower($raw);
        $lower = str_replace(['_', '-'], ' ', $lower);

        if (preg_match('/\b(cell|cellular|mobile|3g|4g|5g|lte|carrier|gsm|cdma)\b/', $lower)) {
            return self::CELLULAR;
        }
        if (preg_match('/\b(wifi|wi[\s-]?fi|wlan)\b/', $lower)) {
            // TS may send wifi; store as Broadband (honest IP semantics) unless clearly cellular
            return self::BROADBAND;
        }
        if (preg_match('/\b(cable|dsl|broadband|fiber|fibre|ftth|satellite|dial.?up)\b/', $lower)) {
            return self::BROADBAND;
        }
        if (preg_match('/\b(corporate|business|hosting|datacenter|data.?center|cloud|vpn|proxy)\b/', $lower)) {
            return self::CORPORATE;
        }
        if (preg_match('/\b(unknown|other|na)\b/', $lower)) {
            return self::UNKNOWN;
        }

        // Exact MaxMind-style labels
        foreach ([self::CELLULAR, self::BROADBAND, self::CORPORATE, self::UNKNOWN] as $label) {
            if (strcasecmp($raw, $label) === 0) {
                return $label;
            }
        }

        // Propeller-style numeric conn codes are network-specific; keep a readable Unknown
        if (ctype_digit($raw)) {
            return self::UNKNOWN;
        }

        return self::UNKNOWN;
    }

    /**
     * Best-effort classification from ASN organization name.
     */
    public static function fromAsnOrganization(?string $org): ?string
    {
        if ($org === null || trim($org) === '') {
            return null;
        }

        $lower = strtolower($org);

        if (preg_match('/\b(mobile|cellular|wireless|lte|gsm|cdma|telefonica|vodafone|t-mobile|tmobile|verizon wireless|att mobility|at&t mobility|orange|ee limited|sprint|china mobile|china unicom|airtel|jio|reliance jio|mtn|safaricom|telcel|claro|viva|ooredoo|etisalat|softbank mobile|kddi|docomo|sk telecom|kt corporation|lg uplus)\b/', $lower)) {
            return self::CELLULAR;
        }

        if (preg_match('/\b(amazon|aws|google cloud|microsoft|azure|digitalocean|linode|hetzner|ovh|cloudflare|akamai|hosting|datacenter|data center|server|vps|colocation|colo )\b/', $lower)) {
            return self::CORPORATE;
        }

        return self::BROADBAND;
    }

    /**
     * Prefer traffic-source tokens over ASN heuristic.
     *
     * @param array<string, mixed> $params Request / TS params
     */
    public static function fromParamsOrAsn(array $params, ?string $asnOrg): ?string
    {
        foreach (['connection_type', 'conn', 'connectionType', 'connection'] as $key) {
            if (!isset($params[$key]) || !is_scalar($params[$key])) {
                continue;
            }
            $normalized = self::normalize((string) $params[$key]);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return self::fromAsnOrganization($asnOrg);
    }
}
