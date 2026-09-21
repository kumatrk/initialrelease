<?php

declare(strict_types=1);

namespace SimpleKuma\Edge;

use mysqli;
use SimpleKuma\Entity\Offer;

/**
 * Builds redirect-only campaign snapshots for Cloudflare KV.
 */
final class EdgeCampaignSerializer
{
    private mysqli $db;
    private Offer $offers;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->offers = new Offer($db);
    }

    /**
     * @param array<string, mixed> $campaign Decoded campaign (getById style)
     * @return array<string, mixed>|null Null when ineligible
     */
    public function serialize(array $campaign): ?array
    {
        $rotation = $campaign['rotation_json'] ?? [];
        if (is_string($rotation)) {
            $rotation = json_decode($rotation, true) ?? [];
        }

        $redirectRules = $campaign['redirect_rules_json'] ?? [];
        if (is_string($redirectRules)) {
            $redirectRules = json_decode($redirectRules, true) ?? [];
        }

        $customTokens = $campaign['custom_tokens_json'] ?? [];
        if (is_string($customTokens)) {
            $customTokens = json_decode($customTokens, true) ?? [];
        }

        $slugs = $this->loadSlugs((int) $campaign['id']);
        $destinations = $this->buildDestinations($rotation);
        $fallback = $this->buildFallbackOffer($campaign);

        $destinationUrls = [];
        foreach ($destinations['lookup'] as $dest) {
            if (!empty($dest['url'])) {
                $destinationUrls[] = (string) $dest['url'];
            }
        }
        if ($fallback && !empty($fallback['url'])) {
            $destinationUrls[] = (string) $fallback['url'];
        }

        $hasCappedOffers = false;
        foreach ($destinations['lookup'] as $dest) {
            if (($dest['type'] ?? '') === 'offer' && !empty($dest['cap_enabled'])) {
                $hasCappedOffers = true;
                break;
            }
        }

        $eligibility = EdgeEligibility::evaluate($campaign, $destinationUrls, $hasCappedOffers);
        if (!$eligibility['eligible']) {
            return null;
        }

        $trafficSource = null;
        $tsId = !empty($campaign['traffic_source_id']) ? (int) $campaign['traffic_source_id'] : null;
        if ($tsId) {
            $trafficSource = $this->loadTrafficSource($tsId);
        }

        return [
            'v' => 1,
            'campaign_id' => (int) $campaign['id'],
            'campaign_key' => (string) ($campaign['campaign_key'] ?? ''),
            'status' => (string) ($campaign['status'] ?? 'active'),
            'flow_type' => (string) ($campaign['flow_type'] ?? 'DTO'),
            'edge_enabled' => true,
            'slug_id' => null,
            'slugs' => $slugs,
            'default_cpc' => isset($campaign['default_cpc']) ? (float) $campaign['default_cpc'] : null,
            'traffic_source_id' => $tsId,
            'traffic_source' => $trafficSource,
            'custom_tokens' => $this->normalizeCustomTokens($customTokens),
            'redirect_rules' => $this->normalizeRedirectRules(is_array($redirectRules) ? $redirectRules : []),
            'rotation' => $destinations['rotation'],
            'destinations' => $destinations['lookup'],
            'fallback_offer' => $fallback,
            'synced_at' => gmdate('c'),
        ];
    }

    /**
     * @return list<array{id: int, slug: string}>
     */
    private function loadSlugs(int $campaignId): array
    {
        $out = [];
        $stmt = $this->db->prepare('SELECT id, slug FROM campaign_slugs WHERE campaign_id = ?');
        if (!$stmt) {
            return $out;
        }
        $stmt->bind_param('i', $campaignId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $out[] = [
                'id' => (int) $row['id'],
                'slug' => (string) $row['slug'],
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $rotation
     * @return array{rotation: array<string, mixed>, lookup: array<string, array<string, mixed>>}
     */
    private function buildDestinations(array $rotation): array
    {
        $lookup = [];

        $enrichOffer = function (array $item) use (&$lookup): ?array {
            $id = (int) ($item['id'] ?? 0);
            if ($id <= 0) {
                return null;
            }
            $offer = $this->offers->getById($id);
            if (!$offer) {
                return null;
            }
            $enabled = !isset($item['enabled']) || $item['enabled'] !== false;
            $capEnabled = !empty($offer['cap_enabled']);
            $payload = [
                'id' => $id,
                'type' => 'offer',
                'weight' => (int) ($item['weight'] ?? 100),
                'enabled' => $enabled,
                'url' => (string) ($offer['url'] ?? ''),
                'is_24_7' => !empty($offer['is_24_7']),
                'schedule_days' => $offer['schedule_days'] ?? [],
                'schedule_start_time' => $offer['schedule_start_time'] ?? null,
                'schedule_end_time' => $offer['schedule_end_time'] ?? null,
                'schedule_timezone' => $offer['schedule_timezone'] ?? 'UTC',
                'cap_enabled' => $capEnabled,
            ];
            $lookup['offer:' . $id] = $payload;
            return [
                'id' => $id,
                'type' => 'offer',
                'weight' => $payload['weight'],
                'enabled' => $enabled,
            ];
        };

        $enrichLp = function (array $item) use (&$lookup): ?array {
            $id = (int) ($item['id'] ?? 0);
            if ($id <= 0) {
                return null;
            }
            $lp = $this->loadLandingPage($id);
            if (!$lp) {
                return null;
            }
            $enabled = !isset($item['enabled']) || $item['enabled'] !== false;
            $payload = [
                'id' => $id,
                'type' => 'landing_page',
                'weight' => (int) ($item['weight'] ?? 100),
                'enabled' => $enabled,
                'url' => (string) ($lp['url'] ?? ''),
            ];
            $lookup['lp:' . $id] = $payload;
            return [
                'id' => $id,
                'type' => 'landing_page',
                'weight' => $payload['weight'],
                'enabled' => $enabled,
            ];
        };

        // Split traffic
        if (isset($rotation['split_traffic'])) {
            $lpPath = [];
            foreach ($rotation['lp_path']['landing_pages'] ?? [] as $item) {
                $e = $enrichLp(is_array($item) ? $item : []);
                if ($e) {
                    $lpPath[] = $e;
                }
            }
            $direct = [];
            foreach ($rotation['direct_path']['offers'] ?? [] as $item) {
                $e = $enrichOffer(is_array($item) ? $item : []);
                if ($e) {
                    $direct[] = $e;
                }
            }
            return [
                'rotation' => [
                    'mode' => 'split',
                    'lp_percent' => (int) ($rotation['split_traffic']['lp_percent'] ?? 50),
                    'landing_pages' => $lpPath,
                    'offers' => $direct,
                ],
                'lookup' => $lookup,
            ];
        }

        // LP flow
        if (isset($rotation['landing_pages'])) {
            $lps = [];
            foreach ($rotation['landing_pages'] as $item) {
                $e = $enrichLp(is_array($item) ? $item : []);
                if ($e) {
                    $lps[] = $e;
                }
            }
            return [
                'rotation' => [
                    'mode' => 'lp',
                    'landing_pages' => $lps,
                ],
                'lookup' => $lookup,
            ];
        }

        // DTO — flat offer list
        $offers = [];
        foreach ($rotation as $item) {
            if (!is_array($item)) {
                continue;
            }
            // Skip nested keys if somehow present
            if (isset($item['landing_pages']) || isset($item['split_traffic'])) {
                continue;
            }
            $e = $enrichOffer($item);
            if ($e) {
                $offers[] = $e;
            }
        }

        return [
            'rotation' => [
                'mode' => 'dto',
                'offers' => $offers,
            ],
            'lookup' => $lookup,
        ];
    }

    /**
     * @param array<string, mixed> $campaign
     * @return array<string, mixed>|null
     */
    private function buildFallbackOffer(array $campaign): ?array
    {
        $fallbackId = !empty($campaign['fallback_offer_id']) ? (int) $campaign['fallback_offer_id'] : 0;
        if ($fallbackId <= 0) {
            return null;
        }
        $offer = $this->offers->getById($fallbackId);
        if (!$offer) {
            return null;
        }
        return [
            'id' => $fallbackId,
            'type' => 'offer',
            'url' => (string) ($offer['url'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadLandingPage(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT id, url, name FROM landing_pages WHERE id = ?');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadTrafficSource(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, tokens_json, cost_param_key, cost_tracking_method FROM traffic_sources WHERE id = ?'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            return null;
        }
        $tokens = $row['tokens_json'] ?? [];
        if (is_string($tokens)) {
            $tokens = json_decode($tokens, true) ?? [];
        }
        return [
            'id' => (int) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
            'tokens' => is_array($tokens) ? array_values(array_filter(array_map(static function ($token): ?array {
                if (!is_array($token)) {
                    return null;
                }
                $name = (string) ($token['name'] ?? '');
                $param = (string) ($token['parameter'] ?? '');
                if ($name === '' || $param === '') {
                    return null;
                }
                return [
                    'name' => $name,
                    'parameter' => $param,
                    'pass_to_lp' => !empty($token['pass_to_lp']),
                    'pass_to_offer' => !empty($token['pass_to_offer']),
                ];
            }, $tokens))) : [],
            'cost_param_key' => $row['cost_param_key'] ?? 'cost',
            'cost_tracking_method' => $row['cost_tracking_method'] ?? null,
        ];
    }

    /**
     * @param array<int, mixed> $tokens
     * @return list<array{name: string, parameter: string, pass_to_lp: bool, pass_to_offer: bool}>
     */
    private function normalizeCustomTokens(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }
            $name = (string) ($token['name'] ?? '');
            $param = (string) ($token['parameter'] ?? '');
            if ($name === '' || $param === '') {
                continue;
            }
            $out[] = [
                'name' => $name,
                'parameter' => $param,
                'pass_to_lp' => !empty($token['pass_to_lp']),
                'pass_to_offer' => !empty($token['pass_to_offer']),
            ];
        }
        return $out;
    }

    /**
     * @param array<int, mixed> $rules
     * @return list<array<string, mixed>>
     */
    private function normalizeRedirectRules(array $rules): array
    {
        $out = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $out[] = [
                'token_name' => (string) ($rule['token_name'] ?? ''),
                'token_source' => (string) ($rule['token_source'] ?? 'custom'),
                'operator' => (string) ($rule['operator'] ?? 'equals'),
                'value' => (string) ($rule['value'] ?? ''),
                'case_sensitive' => !empty($rule['case_sensitive']),
                'redirect_url' => (string) ($rule['redirect_url'] ?? ''),
                'execute_on' => (string) ($rule['execute_on'] ?? 'campaign_click'),
            ];
        }
        return $out;
    }
}
