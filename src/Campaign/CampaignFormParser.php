<?php

declare(strict_types=1);

namespace SimpleKuma\Campaign;

use SimpleKuma\Entity\Campaign;
use SimpleKuma\Entity\TrafficSource;
use SimpleKuma\Release\TrafficSourceReleaseHelper;

/**
 * Parses campaign form POST data for create flows.
 * Logic mirrors views/campaigns.php save handler — keep in sync when fields change.
 */
class CampaignFormParser
{
    /**
     * @return array{
     *   data: array<string, mixed>,
     *   errors: array<string, string|array<int, string>>,
     *   custom_postback_ids: int[],
     *   slugs: array{slug: string[], slug_label: string[]}
     * }
     */
    public static function fromPost(
        array $post,
        Campaign $campaign,
        TrafficSource $trafficSource,
        bool $isCreate = true,
        ?array $origCampaign = null
    ): array {
        $rotation = self::parseRotation($post);
        $trafficSourceId = !empty($post['traffic_source_id']) && (int)$post['traffic_source_id'] > 0
            ? (int)$post['traffic_source_id']
            : null;

        $tsData = $trafficSourceId ? $trafficSource->getById($trafficSourceId) : null;
        $googleAdsIntegrationId = TrafficSourceReleaseHelper::resolveGoogleAdsIntegrationId(
            $tsData,
            $post['google_ads_integration_id'] ?? null
        );

        $data = [
            'name' => $post['name'] ?? '',
            'campaign_group_id' => !empty($post['campaign_group_id']) ? (int)$post['campaign_group_id'] : null,
            'traffic_source_id' => $trafficSourceId,
            'flow_type' => $post['flow_type'] ?? 'DTO',
            'rotation' => $rotation,
            'tracking_domain_id' => !empty($post['tracking_domain_id']) ? (int)$post['tracking_domain_id'] : null,
            'referrer_mode' => $post['referrer_mode'] ?? $post['cloaking_mode'] ?? '',
            'redirectless_tracking' => false,
            'edge_enabled' => !empty($post['edge_enabled']),
            'pass_through' => [],
            'facebook_capi_integration_id' => TrafficSourceReleaseHelper::resolveFacebookIntegrationId(
                $tsData,
                $post['facebook_capi_integration_id'] ?? null
            ),
            'facebook_marketing_integration_id' => TrafficSourceReleaseHelper::resolveFacebookIntegrationId(
                $tsData,
                $post['facebook_marketing_integration_id'] ?? null
            ),
            'facebook_marketing_ad_account_id' => TrafficSourceReleaseHelper::resolveFacebookIntegrationId(
                $tsData,
                $post['facebook_marketing_ad_account_id'] ?? null
            ),
            'facebook_marketing_campaign_id' => TrafficSourceReleaseHelper::resolveFacebookIntegrationId(
                $tsData,
                $post['facebook_marketing_campaign_id'] ?? null
            ),
            'google_ads_integration_id' => $googleAdsIntegrationId,
            'status' => $post['status'] ?? 'active',
            'default_cpc' => !empty($post['default_cpc']) ? (float)$post['default_cpc'] : null,
            'min_postback_payout' => self::parseMinPostbackPayoutFromPost($post),
            'allow_multiple_conversions' => !empty($post['allow_multiple_conversions']),
            'fallback_offer_id' => !empty($post['fallback_offer_id']) ? (int)$post['fallback_offer_id'] : null,
            'traffic_source_postbacks' => self::parseTrafficSourcePostbacks($post, $trafficSourceId),
            'custom_tokens' => self::parseCustomTokens($post),
            'redirect_rules' => self::parseRedirectRules($post),
        ];

        $errors = $campaign->validate($data);

        if (empty($errors)) {
            if ($trafficSourceId === null || $trafficSourceId === 0) {
                $errors['traffic_source_id'] = 'Please select a traffic source.';
            } elseif ($trafficSourceId > 0) {
                if (!$tsData || !TrafficSourceReleaseHelper::isSelectableForRelease($tsData)) {
                    $errors['traffic_source_id'] = 'Please select a traffic source (Bing is not available for campaigns yet).';
                }
            }
        }

        $customPostbackIds = !empty($post['custom_postback_ids']) && is_array($post['custom_postback_ids'])
            ? array_map('intval', $post['custom_postback_ids'])
            : [];

        return [
            'data' => $data,
            'errors' => $errors,
            'custom_postback_ids' => $customPostbackIds,
            'slugs' => [
                'slug' => !empty($post['slug']) && is_array($post['slug']) ? $post['slug'] : [],
                'slug_label' => !empty($post['slug_label']) && is_array($post['slug_label']) ? $post['slug_label'] : [],
            ],
        ];
    }

    /**
     * Parse JSON API body for campaign create/update.
     *
     * @return array{
     *   data: array<string, mixed>,
     *   errors: array<string, string|array<int, string>>,
     *   custom_postback_ids: int[],
     *   slugs: list<array{slug: string, slug_label: string}>
     * }
     */
    public static function fromArray(
        array $input,
        Campaign $campaign,
        TrafficSource $trafficSource,
        bool $isCreate = true,
        ?array $origCampaign = null
    ): array {
        $rotation = $input['rotation'] ?? [];
        if (!is_array($rotation)) {
            $rotation = [];
        }

        $trafficSourceId = !empty($input['traffic_source_id']) && (int)$input['traffic_source_id'] > 0
            ? (int)$input['traffic_source_id']
            : null;

        $tsData = $trafficSourceId ? $trafficSource->getById($trafficSourceId) : null;
        $googleAdsIntegrationId = TrafficSourceReleaseHelper::resolveGoogleAdsIntegrationId(
            $tsData,
            $input['google_ads_integration_id'] ?? null
        );

        $customTokens = $input['custom_tokens'] ?? [];
        if (!is_array($customTokens)) {
            $customTokens = [];
        }

        $redirectRules = $input['redirect_rules'] ?? [];
        if (!is_array($redirectRules)) {
            $redirectRules = [];
        }

        $data = [
            'name' => $input['name'] ?? '',
            'campaign_group_id' => !empty($input['campaign_group_id']) ? (int)$input['campaign_group_id'] : null,
            'traffic_source_id' => $trafficSourceId,
            'flow_type' => $input['flow_type'] ?? 'DTO',
            'rotation' => $rotation,
            'tracking_domain_id' => !empty($input['tracking_domain_id']) ? (int)$input['tracking_domain_id'] : null,
            'referrer_mode' => $input['referrer_mode'] ?? $input['cloaking_mode'] ?? '',
            'redirectless_tracking' => false,
            'edge_enabled' => !empty($input['edge_enabled']),
            'pass_through' => [],
            'facebook_capi_integration_id' => TrafficSourceReleaseHelper::resolveFacebookIntegrationId(
                $tsData,
                $input['facebook_capi_integration_id'] ?? null
            ),
            'facebook_marketing_integration_id' => TrafficSourceReleaseHelper::resolveFacebookIntegrationId(
                $tsData,
                $input['facebook_marketing_integration_id'] ?? null
            ),
            'facebook_marketing_ad_account_id' => TrafficSourceReleaseHelper::resolveFacebookIntegrationId(
                $tsData,
                $input['facebook_marketing_ad_account_id'] ?? null
            ),
            'facebook_marketing_campaign_id' => TrafficSourceReleaseHelper::resolveFacebookIntegrationId(
                $tsData,
                $input['facebook_marketing_campaign_id'] ?? null
            ),
            'google_ads_integration_id' => $googleAdsIntegrationId,
            'status' => $input['status'] ?? 'active',
            'default_cpc' => isset($input['default_cpc']) && $input['default_cpc'] !== '' && $input['default_cpc'] !== null
                ? (float)$input['default_cpc'] : null,
            'min_postback_payout' => self::parseMinPostbackPayoutFromArray($input),
            'allow_multiple_conversions' => !empty($input['allow_multiple_conversions']),
            'fallback_offer_id' => !empty($input['fallback_offer_id']) ? (int)$input['fallback_offer_id'] : null,
            'traffic_source_postbacks' => is_array($input['traffic_source_postbacks'] ?? null)
                ? $input['traffic_source_postbacks'] : [],
            'custom_tokens' => $customTokens,
            'redirect_rules' => $redirectRules,
        ];

        $errors = $campaign->validate($data);

        if (empty($errors)) {
            if ($trafficSourceId === null || $trafficSourceId === 0) {
                $errors['traffic_source_id'] = 'Please select a traffic source.';
            } elseif ($trafficSourceId > 0) {
                if (!$tsData || !TrafficSourceReleaseHelper::isSelectableForRelease($tsData)) {
                    $errors['traffic_source_id'] = 'Please select a traffic source (Bing is not available for campaigns yet).';
                }
            }
        }

        $customPostbackIds = [];
        if (!empty($input['custom_postback_ids']) && is_array($input['custom_postback_ids'])) {
            $customPostbackIds = array_map('intval', $input['custom_postback_ids']);
        }

        $slugs = [];
        if (!empty($input['slugs']) && is_array($input['slugs'])) {
            foreach ($input['slugs'] as $slugRow) {
                if (!is_array($slugRow)) {
                    continue;
                }
                $slug = trim((string)($slugRow['slug'] ?? ''));
                if ($slug === '') {
                    continue;
                }
                $slugs[] = [
                    'slug' => $slug,
                    'slug_label' => trim((string)($slugRow['slug_label'] ?? '')),
                ];
            }
        }

        return [
            'data' => $data,
            'errors' => $errors,
            'custom_postback_ids' => $customPostbackIds,
            'slugs' => $slugs,
        ];
    }

    private static function parseMinPostbackPayoutFromArray(array $input): ?float
    {
        $raw = $input['min_postback_payout'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        $val = (float)$raw;
        return $val >= 0 ? $val : null;
    }

    private static function parseRotation(array $post): array
    {
        $flowType = $post['flow_type'] ?? 'DTO';
        $offers = self::parseOffers($post);

        if ($flowType === 'DTO') {
            return $offers;
        }

        if ($flowType === 'LP') {
            $rotation = [
                'landing_pages' => [],
                'offers' => $offers,
            ];
            foreach ($post['lp_id'] ?? [] as $idx => $lpId) {
                if (!empty($lpId)) {
                    $lpEnabled = $post['lp_enabled'] ?? [];
                    $isEnabled = isset($lpEnabled[$idx]) && (
                        $lpEnabled[$idx] === '1' || $lpEnabled[$idx] === 1 || $lpEnabled[$idx] === 'true'
                    );
                    $rotation['landing_pages'][] = [
                        'type' => 'landing_page',
                        'id' => (int)$lpId,
                        'weight' => (int)(($post['lp_weight'] ?? [])[$idx] ?? 0),
                        'enabled' => $isEnabled,
                    ];
                }
            }
            return $rotation;
        }

        if ($flowType === 'Split') {
            $trafficToLP = (int)($post['split_traffic_to_lp'] ?? 50);
            $lpPathLPs = [];
            foreach ($post['lp_id'] ?? [] as $idx => $lpId) {
                if (!empty($lpId)) {
                    $lpEnabled = $post['lp_enabled'] ?? [];
                    $isEnabled = isset($lpEnabled[$idx]) && (
                        $lpEnabled[$idx] === '1' || $lpEnabled[$idx] === 1 || $lpEnabled[$idx] === 'true'
                    );
                    $lpPathLPs[] = [
                        'type' => 'landing_page',
                        'id' => (int)$lpId,
                        'weight' => (int)(($post['lp_weight'] ?? [])[$idx] ?? 0),
                        'enabled' => $isEnabled,
                    ];
                }
            }

            return [
                'split_traffic' => [
                    'lp_percent' => $trafficToLP,
                    'direct_percent' => 100 - $trafficToLP,
                ],
                'lp_path' => [
                    'landing_pages' => $lpPathLPs,
                    'offers' => $offers,
                ],
                'direct_path' => [
                    'offers' => $offers,
                ],
            ];
        }

        return $offers;
    }

    private static function parseOffers(array $post): array
    {
        $offers = [];
        $offerIds = $post['offer_id'] ?? [];
        $offerWeights = $post['offer_weight'] ?? [];
        $offerEnabled = $post['offer_enabled'] ?? [];

        if (!is_array($offerIds)) {
            return $offers;
        }

        foreach ($offerIds as $idx => $offerId) {
            $offerId = trim((string)$offerId);
            if ($offerId === '') {
                continue;
            }
            $isEnabled = isset($offerEnabled[$idx]) && (
                $offerEnabled[$idx] === '1' ||
                $offerEnabled[$idx] === 1 ||
                $offerEnabled[$idx] === 'true' ||
                $offerEnabled[$idx] === true
            );
            $offers[] = [
                'type' => 'offer',
                'id' => (int)$offerId,
                'weight' => (int)($offerWeights[$idx] ?? 0),
                'enabled' => $isEnabled,
            ];
        }

        return $offers;
    }

    private static function parseTrafficSourcePostbacks(array $post, ?int $trafficSourceId): array
    {
        $trafficSourcePostbacks = [];
        if ($trafficSourceId !== null && $trafficSourceId > 0) {
            return $trafficSourcePostbacks;
        }

        $tsPostbackConfigs = $post['traffic_source_postbacks'] ?? [];
        foreach ($tsPostbackConfigs as $tsId => $config) {
            $tsId = (int)$tsId;
            if ($tsId <= 0) {
                continue;
            }
            $tsPostbackConfig = [
                'facebook_capi_integration_id' => !empty($config['facebook_capi_integration_id'])
                    ? (int)$config['facebook_capi_integration_id'] : null,
                'google_ads_integration_id' => !empty($config['google_ads_integration_id'])
                    ? (int)$config['google_ads_integration_id'] : null,
            ];
            if ($tsPostbackConfig['facebook_capi_integration_id'] || $tsPostbackConfig['google_ads_integration_id']) {
                $trafficSourcePostbacks[$tsId] = $tsPostbackConfig;
            }
        }

        return $trafficSourcePostbacks;
    }

    private static function parseCustomTokens(array $post): array
    {
        $customTokens = [];
        $tokenNames = $post['custom_token_name'] ?? [];
        $tokenParameters = $post['custom_token_parameter'] ?? [];
        $tokenPlaceholders = $post['custom_token_placeholder'] ?? [];

        foreach ($tokenNames as $idx => $tokenName) {
            $parameter = trim($tokenParameters[$idx] ?? '');
            $placeholder = trim($tokenPlaceholders[$idx] ?? '');
            if ($parameter === '') {
                continue;
            }
            $passToLpKey = 'custom_token_pass_to_lp_' . $idx;
            $passToOfferKey = 'custom_token_pass_to_offer_' . $idx;
            $passToLp = isset($post[$passToLpKey]) && is_array($post[$passToLpKey]) && !empty($post[$passToLpKey][0]);
            $passToOffer = isset($post[$passToOfferKey]) && is_array($post[$passToOfferKey]) && !empty($post[$passToOfferKey][0]);

            $customTokens[] = [
                'name' => trim((string)$tokenName),
                'parameter' => $parameter,
                'placeholder' => $placeholder,
                'pass_to_lp' => $passToLp,
                'pass_to_offer' => $passToOffer,
            ];
        }

        return $customTokens;
    }

    private static function parseRedirectRules(array $post): array
    {
        $redirectRules = [];
        $ruleTokens = $post['redirect_rule_token'] ?? [];
        $ruleOperators = $post['redirect_rule_operator'] ?? [];
        $ruleValues = $post['redirect_rule_value'] ?? [];
        $ruleUrls = $post['redirect_rule_url'] ?? [];
        $ruleCaseSensitive = $post['redirect_rule_case_sensitive'] ?? [];

        foreach ($ruleTokens as $idx => $tokenIdentifier) {
            if (empty($tokenIdentifier) || empty($ruleOperators[$idx]) || empty($ruleValues[$idx]) || empty($ruleUrls[$idx])) {
                continue;
            }

            $tokenParts = explode(':', (string)$tokenIdentifier, 3);
            $tokenName = '';
            if (count($tokenParts) === 2) {
                $tokenName = $tokenParts[1];
            } elseif (count($tokenParts) === 3) {
                $tokenName = $tokenParts[2];
            } else {
                $tokenName = (string)$tokenIdentifier;
            }

            $executeOn = [];
            $executeOnKey = 'redirect_rule_execute_on_' . $idx;
            if (isset($post[$executeOnKey]) && is_array($post[$executeOnKey])) {
                $executeOn = $post[$executeOnKey];
            }
            if (empty($executeOn)) {
                continue;
            }

            $tokenSource = '';
            if (count($tokenParts) === 2) {
                $tokenSource = $tokenParts[0];
            } elseif (count($tokenParts) === 3) {
                $tokenSource = $tokenParts[0] . ':' . $tokenParts[1];
            }

            $redirectRules[] = [
                'token_name' => trim($tokenName),
                'token_source' => $tokenSource,
                'operator' => trim((string)$ruleOperators[$idx]),
                'value' => trim((string)$ruleValues[$idx]),
                'case_sensitive' => !empty($ruleCaseSensitive[$idx]),
                'redirect_url' => trim((string)$ruleUrls[$idx]),
                'execute_on' => $executeOn,
            ];
        }

        return $redirectRules;
    }

    private static function parseMinPostbackPayoutFromPost(array $post): ?float
    {
        $raw = $post['min_postback_payout'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        $val = (float)$raw;
        return $val >= 0 ? $val : null;
    }
}
