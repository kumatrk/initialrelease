<?php

declare(strict_types=1);

namespace SimpleKuma\Api\Controllers;

use mysqli;
use SimpleKuma\Api\Request;
use SimpleKuma\Api\Response;
use SimpleKuma\Api\Services\CampaignTrackingLinkService;
use SimpleKuma\Auth\Permission;
use SimpleKuma\Campaign\CampaignFormParser;
use SimpleKuma\Entity\Campaign;
use SimpleKuma\Entity\CampaignSlug;
use SimpleKuma\Entity\CustomPostback;
use SimpleKuma\Entity\TrafficSource;

class CampaignsController extends BaseController
{
    /** @param array<string, mixed> $row */
    private static function formatCampaign(array $row, array $slugs = [], array $customPostbackIds = []): array
    {
        return [
            'id' => (int)$row['id'],
            'campaign_key' => $row['campaign_key'] ?? null,
            'name' => $row['name'],
            'status' => $row['status'],
            'flow_type' => $row['flow_type'],
            'traffic_source_id' => isset($row['traffic_source_id']) ? (int)$row['traffic_source_id'] : null,
            'traffic_source_name' => $row['traffic_source_name'] ?? null,
            'campaign_group_id' => isset($row['campaign_group_id']) ? (int)$row['campaign_group_id'] : null,
            'campaign_group_name' => $row['campaign_group_name'] ?? null,
            'tracking_domain_id' => isset($row['tracking_domain_id']) ? (int)$row['tracking_domain_id'] : null,
            'referrer_mode' => (string) ($row['referrer_mode'] ?? $row['cloaking_mode'] ?? ''),
            'edge_enabled' => !empty($row['edge_enabled']),
            'edge_synced_at' => $row['edge_synced_at'] ?? null,
            'default_cpc' => isset($row['default_cpc']) ? (float)$row['default_cpc'] : null,
            'min_postback_payout' => isset($row['min_postback_payout']) ? (float)$row['min_postback_payout'] : null,
            'allow_multiple_conversions' => !empty($row['allow_multiple_conversions']),
            'fallback_offer_id' => isset($row['fallback_offer_id']) ? (int)$row['fallback_offer_id'] : null,
            'rotation' => $row['rotation_json'] ?? [],
            'custom_tokens' => $row['custom_tokens_json'] ?? [],
            'redirect_rules' => $row['redirect_rules_json'] ?? [],
            'facebook_capi_integration_id' => isset($row['facebook_capi_integration_id'])
                ? (int)$row['facebook_capi_integration_id'] : null,
            'google_ads_integration_id' => isset($row['google_ads_integration_id'])
                ? (int)$row['google_ads_integration_id'] : null,
            'custom_postback_ids' => $customPostbackIds,
            'slugs' => array_map(static fn(array $s): array => [
                'id' => (int)$s['id'],
                'slug' => $s['slug'],
                'slug_label' => $s['slug_label'] ?? '',
            ], $slugs),
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    public static function index(mysqli $db, Request $request): void
    {
        $ctx = self::auth($db, $request);
        self::requirePerm($ctx, Permission::PERM_CAMPAIGN_VIEW);

        $entity = new Campaign($db);
        $slugEntity = new CampaignSlug($db);
        $rows = [];
        foreach ($entity->getAll() as $row) {
            $rows[] = self::formatCampaign($row, $slugEntity->getByCampaignId((int)$row['id']));
        }

        Response::success($rows);
    }

    public static function show(mysqli $db, Request $request): void
    {
        $ctx = self::auth($db, $request);
        self::requirePerm($ctx, Permission::PERM_CAMPAIGN_VIEW);

        $id = self::idParam($request);
        $entity = new Campaign($db);
        $row = $entity->getById($id);
        if (!$row) {
            Response::error('Campaign not found', 404, 'not_found');
        }

        $slugEntity = new CampaignSlug($db);
        $postbackEntity = new CustomPostback($db);
        $postbackIds = array_map(
            static fn(array $p): int => (int)$p['id'],
            $postbackEntity->getForCampaign($id)
        );

        Response::success(self::formatCampaign($row, $slugEntity->getByCampaignId($id), $postbackIds));
    }

    public static function create(mysqli $db, Request $request): void
    {
        $ctx = self::auth($db, $request);
        self::requirePerm($ctx, Permission::PERM_CAMPAIGN_CREATE);

        $input = self::readJson($request);
        $campaignEntity = new Campaign($db);
        $trafficSource = new TrafficSource($db);

        $parsed = CampaignFormParser::fromArray($input, $campaignEntity, $trafficSource, true);
        if ($parsed['errors'] !== []) {
            self::validationFailed($parsed['errors']);
        }

        try {
            $id = $campaignEntity->create($parsed['data']);
        } catch (\Throwable $e) {
            Response::error('Failed to create campaign: ' . $e->getMessage(), 500, 'create_failed');
        }

        $postbackEntity = new CustomPostback($db);
        $postbackEntity->setForCampaign($id, $parsed['custom_postback_ids']);

        $slugEntity = new CampaignSlug($db);
        $slugErrors = [];
        foreach ($parsed['slugs'] as $i => $slugRow) {
            try {
                $slugEntity->create($id, $slugRow['slug'], $slugRow['slug_label']);
            } catch (\InvalidArgumentException $e) {
                $slugErrors[$i] = $e->getMessage();
            }
        }
        if ($slugErrors !== []) {
            self::validationFailed(['slugs' => $slugErrors]);
        }

        $row = $campaignEntity->getById($id);
        $linkService = new CampaignTrackingLinkService();
        $tracking = $linkService->buildForCampaign($row ?? [], $trafficSource);

        $formatted = self::formatCampaign(
            $row ?? [],
            $slugEntity->getByCampaignId($id),
            $parsed['custom_postback_ids']
        );
        $formatted['tracking_url'] = $tracking['url'];

        Response::success($formatted, 201);
    }

    public static function update(mysqli $db, Request $request): void
    {
        $ctx = self::auth($db, $request);
        self::requirePerm($ctx, Permission::PERM_CAMPAIGN_EDIT);

        $id = self::idParam($request);
        $campaignEntity = new Campaign($db);
        $orig = $campaignEntity->getById($id);
        if (!$orig) {
            Response::error('Campaign not found', 404, 'not_found');
        }

        $input = self::readJson($request);
        $trafficSource = new TrafficSource($db);

        // Merge partial updates with existing campaign for parser
        $mergedInput = self::mergeCampaignInput($orig, $input);
        $parsed = CampaignFormParser::fromArray($mergedInput, $campaignEntity, $trafficSource, false, $orig);
        if ($parsed['errors'] !== []) {
            self::validationFailed($parsed['errors']);
        }

        try {
            $campaignEntity->update($id, $parsed['data']);
        } catch (\Throwable $e) {
            Response::error('Failed to update campaign: ' . $e->getMessage(), 500, 'update_failed');
        }

        if (array_key_exists('custom_postback_ids', $input)) {
            $postbackEntity = new CustomPostback($db);
            $postbackEntity->setForCampaign($id, $parsed['custom_postback_ids']);
        }

        if (array_key_exists('slugs', $input)) {
            self::syncSlugs($db, $id, $parsed['slugs']);
        }

        $row = $campaignEntity->getById($id);
        $slugEntity = new CampaignSlug($db);
        $postbackEntity = new CustomPostback($db);
        $postbackIds = array_map(
            static fn(array $p): int => (int)$p['id'],
            $postbackEntity->getForCampaign($id)
        );

        Response::success(self::formatCampaign($row ?? [], $slugEntity->getByCampaignId($id), $postbackIds));
    }

    public static function updateStatus(mysqli $db, Request $request): void
    {
        $ctx = self::auth($db, $request);
        self::requirePerm($ctx, Permission::PERM_CAMPAIGN_EDIT);

        $id = self::idParam($request);
        $campaignEntity = new Campaign($db);
        $orig = $campaignEntity->getById($id);
        if (!$orig) {
            Response::error('Campaign not found', 404, 'not_found');
        }

        $status = self::readJson($request)['status'] ?? '';
        if (!in_array($status, ['active', 'paused', 'archived'], true)) {
            self::validationFailed(['status' => 'Status must be active, paused, or archived']);
        }

        $campaignEntity->update($id, array_merge($orig, [
            'rotation' => $orig['rotation_json'] ?? [],
            'pass_through' => $orig['pass_through_json'] ?? [],
            'custom_tokens' => $orig['custom_tokens_json'] ?? [],
            'redirect_rules' => $orig['redirect_rules_json'] ?? [],
            'traffic_source_postbacks' => $orig['traffic_source_postbacks_json'] ?? [],
            'status' => $status,
        ]));

        Response::success(['id' => $id, 'status' => $status]);
    }

    public static function trackingLink(mysqli $db, Request $request): void
    {
        $ctx = self::auth($db, $request);
        self::requirePerm($ctx, Permission::PERM_CAMPAIGN_VIEW);

        $id = self::idParam($request);
        $campaignEntity = new Campaign($db);
        $row = $campaignEntity->getById($id);
        if (!$row) {
            Response::error('Campaign not found', 404, 'not_found');
        }

        $slug = $request->query('slug');
        $linkService = new CampaignTrackingLinkService();
        $trafficSource = new TrafficSource($db);
        $tracking = $linkService->buildForCampaign($row, $trafficSource, $slug);

        Response::success($tracking);
    }

    /** @param array<string, mixed> $orig @param array<string, mixed> $input */
    private static function mergeCampaignInput(array $orig, array $input): array
    {
        return array_merge([
            'name' => $orig['name'],
            'campaign_group_id' => $orig['campaign_group_id'],
            'traffic_source_id' => $orig['traffic_source_id'],
            'flow_type' => $orig['flow_type'],
            'rotation' => $orig['rotation_json'] ?? [],
            'tracking_domain_id' => $orig['tracking_domain_id'],
            'referrer_mode' => (string) ($orig['referrer_mode'] ?? $orig['cloaking_mode'] ?? ''),
            'edge_enabled' => !empty($orig['edge_enabled']),
            'facebook_capi_integration_id' => $orig['facebook_capi_integration_id'],
            'facebook_marketing_integration_id' => $orig['facebook_marketing_integration_id'],
            'facebook_marketing_ad_account_id' => $orig['facebook_marketing_ad_account_id'],
            'facebook_marketing_campaign_id' => $orig['facebook_marketing_campaign_id'] ?? null,
            'google_ads_integration_id' => $orig['google_ads_integration_id'],
            'status' => $orig['status'],
            'default_cpc' => $orig['default_cpc'],
            'min_postback_payout' => $orig['min_postback_payout'] ?? null,
            'allow_multiple_conversions' => !empty($orig['allow_multiple_conversions']),
            'fallback_offer_id' => $orig['fallback_offer_id'] ?? null,
            'custom_tokens' => $orig['custom_tokens_json'] ?? [],
            'redirect_rules' => $orig['redirect_rules_json'] ?? [],
            'traffic_source_postbacks' => $orig['traffic_source_postbacks_json'] ?? [],
            'custom_postback_ids' => [],
            'slugs' => [],
        ], $input);
    }

    /** @param list<array{slug: string, slug_label: string}> $slugs */
    private static function syncSlugs(mysqli $db, int $campaignId, array $slugs): void
    {
        $slugEntity = new CampaignSlug($db);
        $existing = $slugEntity->getByCampaignId($campaignId);
        $existingSlugs = array_column($existing, 'slug');
        $newSlugs = array_column($slugs, 'slug');

        foreach ($existing as $row) {
            if (!in_array($row['slug'], $newSlugs, true)) {
                $slugEntity->delete((int)$row['id']);
            }
        }

        $slugErrors = [];
        foreach ($slugs as $i => $slugRow) {
            if (in_array($slugRow['slug'], $existingSlugs, true)) {
                continue;
            }
            try {
                $slugEntity->create($campaignId, $slugRow['slug'], $slugRow['slug_label']);
            } catch (\InvalidArgumentException $e) {
                $slugErrors[$i] = $e->getMessage();
            }
        }

        if ($slugErrors !== []) {
            self::validationFailed(['slugs' => $slugErrors]);
        }
    }
}
