<?php

declare(strict_types=1);

namespace SimpleKuma\Campaign;

use mysqli;
use SimpleKuma\Api\Services\CampaignTrackingLinkService;
use SimpleKuma\Entity\Campaign;
use SimpleKuma\Entity\CampaignSlug;
use SimpleKuma\Entity\TrackingDomain;
use SimpleKuma\Entity\TrafficSource;
use SimpleKuma\Release\TrafficSourceReleaseHelper;
use SimpleKuma\Tracking\ClickPath;
use SimpleKuma\Utils\Formatter;

/**
 * Builds JSON for the campaign-list "Get tracking link" modal (keeps heavy data off list HTML).
 */
class CampaignTrackingLinkPayloadService
{
    public function __construct(private mysqli $db)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildForCampaignId(int $campaignId): ?array
    {
        $campaignEntity = new Campaign($this->db);
        $campaign = $campaignEntity->getById($campaignId);
        if (!$campaign) {
            return null;
        }

        $trafficSourceEntity = new TrafficSource($this->db);
        $slugEntity = new CampaignSlug($this->db);
        $trackingDomainEntity = new TrackingDomain($this->db);

        $isAutoDetect = empty($campaign['traffic_source_id']);
        $trafficSourcesForModal = [];
        $selectedTrafficSource = null;

        if ($isAutoDetect) {
            foreach ($trafficSourceEntity->getAll() as $ts) {
                if (!TrafficSourceReleaseHelper::isSelectableForRelease($ts)) {
                    continue;
                }
                $trafficSourcesForModal[] = $this->normalizeTrafficSourceRow($ts);
            }
        } elseif (!empty($campaign['traffic_source_id'])) {
            $ts = $trafficSourceEntity->getById((int) $campaign['traffic_source_id']);
            if ($ts) {
                $selectedTrafficSource = $this->normalizeTrafficSourceRow($ts);
            }
        }

        $customTokens = $campaign['custom_tokens_json'] ?? [];
        if (is_string($customTokens)) {
            $customTokens = json_decode($customTokens, true) ?? [];
        }
        if (!is_array($customTokens)) {
            $customTokens = [];
        }

        $slugs = [];
        foreach ($slugEntity->getByCampaignId($campaignId) as $slugRow) {
            $slugs[] = [
                'id' => (int) ($slugRow['id'] ?? 0),
                'slug' => $slugRow['slug'] ?? '',
                'slug_label' => $slugRow['slug_label'] ?? '',
            ];
        }

        $trackingDomainUrl = '';
        if (!empty($campaign['tracking_domain_id'])) {
            $td = $trackingDomainEntity->getById((int) $campaign['tracking_domain_id']);
            if ($td && !empty($td['domain'])) {
                $domain = rtrim((string) $td['domain'], '/');
                $trackingDomainUrl = preg_match('#^https?://#i', $domain) ? $domain : 'https://' . $domain;
            }
        }

        $defaultBase = rtrim(Formatter::getCampaignBaseUrl($campaign), '/');
        $linkService = new CampaignTrackingLinkService();
        $built = $linkService->buildForCampaign($campaign, $trafficSourceEntity);

        $verifiedDomains = $trackingDomainEntity->getVerified();
        if (!is_array($verifiedDomains)) {
            $verifiedDomains = [];
        }

        return [
            'campaign_id' => $campaignId,
            'campaign_key' => (string) ($campaign['campaign_key'] ?? $campaignId),
            'is_auto_detect' => $isAutoDetect,
            'traffic_sources' => $trafficSourcesForModal,
            'selected_traffic_source' => $selectedTrafficSource,
            'custom_tokens' => $customTokens,
            'slugs' => $slugs,
            'tracking_domain' => $trackingDomainUrl,
            'tracking_domains' => $verifiedDomains,
            'default_base_url' => $defaultBase,
            'click_path_prefix' => ClickPath::prefix(),
            'click_url_style' => ClickPath::style(),
            'click_entry_script' => ClickPath::entryScript(),
            'click_key_param' => ClickPath::keyQueryParam(),
            'default_url' => $built['url'],
            'default_base_path' => $built['base_url'],
        ];
    }

    /**
     * @param array<string, mixed> $ts
     * @return array<string, mixed>
     */
    private function normalizeTrafficSourceRow(array $ts): array
    {
        $tokensJson = $ts['tokens_json'] ?? [];
        if (is_string($tokensJson)) {
            $tokensJson = json_decode($tokensJson, true) ?? [];
        }

        return [
            'id' => (int) ($ts['id'] ?? 0),
            'name' => (string) ($ts['name'] ?? ''),
            'tokens_json' => is_array($tokensJson) ? $tokensJson : [],
            'cost_param_key' => (string) ($ts['cost_param_key'] ?? ''),
        ];
    }
}
