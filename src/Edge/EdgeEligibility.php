<?php

declare(strict_types=1);

namespace SimpleKuma\Edge;

/**
 * Determines whether a campaign can run on the Edge Worker (Phase 1).
 */
final class EdgeEligibility
{
    /** Tokens the Worker can safely substitute today. */
    private const SUPPORTED_URL_TOKENS = [
        'click_id',
        'clickid',
        'campaign_id',
    ];

    /**
     * @param array<string, mixed> $campaign Decoded campaign row
     * @param list<string> $destinationUrls Offer/LP URLs from rotation (+ fallback)
     * @param bool $hasCappedOffers True when any rotated offer has cap_enabled
     * @return array{eligible: bool, reason: string|null}
     */
    public static function evaluate(
        array $campaign,
        array $destinationUrls = [],
        bool $hasCappedOffers = false
    ): array {
        if (empty($campaign['edge_enabled'])) {
            return ['eligible' => false, 'reason' => 'Edge redirect is not enabled for this campaign'];
        }

        $status = $campaign['status'] ?? '';
        if ($status !== 'active') {
            return ['eligible' => false, 'reason' => 'Campaign is not active'];
        }

        if (!empty($campaign['redirectless_tracking'])) {
            return ['eligible' => false, 'reason' => 'Redirectless campaigns stay on origin'];
        }

        $referrerMode = trim((string) ($campaign['referrer_mode'] ?? $campaign['cloaking_mode'] ?? ''));
        if ($referrerMode !== '' && strtolower($referrerMode) !== 'none' && strtolower($referrerMode) !== 'off') {
            // Phase 1: plain 302 only
            return [
                'eligible' => false,
                'reason' => 'Referrer / cloaking modes require origin (Phase 1 supports plain 302 only)',
            ];
        }

        if ($hasCappedOffers) {
            return [
                'eligible' => false,
                'reason' => 'Offer click caps require origin (edge cannot evaluate caps live)',
            ];
        }

        foreach ($destinationUrls as $url) {
            $unsupported = self::unsupportedUrlTokens((string) $url);
            if ($unsupported !== []) {
                return [
                    'eligible' => false,
                    'reason' => 'Destination URL tokens require origin: {' . implode('}, {', $unsupported) . '}',
                ];
            }
        }

        return ['eligible' => true, 'reason' => null];
    }

    /**
     * @return list<string>
     */
    public static function unsupportedUrlTokens(string $url): array
    {
        if ($url === '' || !preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $url, $matches)) {
            return [];
        }
        $found = [];
        foreach ($matches[1] as $name) {
            if (!in_array(strtolower($name), self::SUPPORTED_URL_TOKENS, true)) {
                $found[$name] = true;
            }
        }
        return array_keys($found);
    }
}
