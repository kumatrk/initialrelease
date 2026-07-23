<?php

declare(strict_types=1);

namespace SimpleKuma\Api\Services;

use SimpleKuma\Entity\TrafficSource;
use SimpleKuma\Tracking\ClickPath;
use SimpleKuma\Utils\Formatter;

/**
 * Builds campaign tracking URLs (server-side parity with campaigns.php JS).
 */
class CampaignTrackingLinkService
{
    /**
     * @param array<string, mixed> $campaign
     * @param array<string, mixed>|null $trafficSource
     * @return array{url: string, base_url: string, identifier: string, tokens: list<string>}
     */
    public function build(array $campaign, ?array $trafficSource = null, ?string $slug = null): array
    {
        $baseDomain = rtrim(Formatter::getCampaignBaseUrl($campaign), '/');
        $identifier = $slug ?: ($campaign['campaign_key'] ?? '');
        $baseUrl = ClickPath::url($baseDomain, $identifier);

        $params = [];
        $tokenLabels = [];

        $includeTf = empty($campaign['traffic_source_id']);
        if ($trafficSource === null && !empty($campaign['traffic_source_id'])) {
            // Caller should pass traffic source when available
        }

        if ($trafficSource !== null) {
            if ($includeTf && !empty($trafficSource['id'])) {
                $params[] = 'Tf=' . (int)$trafficSource['id'];
                $tokenLabels[] = 'Tf';
            }

            $tsTokens = $trafficSource['tokens'] ?? $trafficSource['tokens_json'] ?? [];
            if (is_string($tsTokens)) {
                $tsTokens = json_decode($tsTokens, true) ?? [];
            }
            if (is_array($tsTokens)) {
                foreach ($tsTokens as $token) {
                    $parameter = $token['parameter'] ?? $token['key'] ?? '';
                    $placeholder = $token['placeholder'] ?? '{value}';
                    if ($parameter !== '' && strtolower($parameter) !== 'fbclid') {
                        $params[] = $parameter . '=' . $placeholder;
                        $tokenLabels[] = $parameter;
                    }
                }
            }

            $costParam = $trafficSource['cost_param_key'] ?? '';
            if ($costParam !== '') {
                $params[] = $costParam . '={value}';
                $tokenLabels[] = $costParam;
            }
        }

        $customTokens = $campaign['custom_tokens'] ?? $campaign['custom_tokens_json'] ?? [];
        if (is_string($customTokens)) {
            $customTokens = json_decode($customTokens, true) ?? [];
        }
        if (is_array($customTokens)) {
            foreach ($customTokens as $token) {
                $parameter = $token['parameter'] ?? '';
                $placeholder = $token['placeholder'] ?? '{value}';
                if ($parameter !== '') {
                    $params[] = $parameter . '=' . $placeholder;
                    $tokenLabels[] = $parameter;
                }
            }
        }

        $url = ClickPath::appendParams($baseUrl, $params);

        return [
            'url' => $url,
            'base_url' => $baseUrl,
            'identifier' => $identifier,
            'tokens' => $tokenLabels,
        ];
    }

    /**
     * @param array<string, mixed> $campaign
     */
    public function buildForCampaign(array $campaign, TrafficSource $trafficSourceEntity, ?string $slug = null): array
    {
        $ts = null;
        if (!empty($campaign['traffic_source_id'])) {
            $ts = $trafficSourceEntity->getById((int)$campaign['traffic_source_id']);
        }

        return $this->build($campaign, $ts, $slug);
    }
}
