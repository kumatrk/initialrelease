<?php

declare(strict_types=1);

namespace SimpleKuma\Api\Controllers;

use mysqli;
use SimpleKuma\Api\Request;
use SimpleKuma\Api\Response;
use SimpleKuma\Auth\Permission;
use SimpleKuma\Entity\CampaignGroup;
use SimpleKuma\Entity\LandingPage;
use SimpleKuma\Entity\Network;
use SimpleKuma\Entity\Offer;
use SimpleKuma\Entity\TrackingDomain;
use SimpleKuma\Entity\TrafficSource;
use SimpleKuma\Release\TrafficSourceReleaseHelper;

class CatalogController extends BaseController
{
    public static function show(mysqli $db, Request $request): void
    {
        $ctx = self::auth($db, $request);
        self::requirePerm($ctx, Permission::PERM_CAMPAIGN_VIEW);

        $trafficSource = new TrafficSource($db);
        $offer = new Offer($db);
        $landingPage = new LandingPage($db);
        $network = new Network($db);
        $campaignGroup = new CampaignGroup($db);
        $trackingDomain = new TrackingDomain($db);

        $trafficSources = [];
        foreach ($trafficSource->getAll() as $ts) {
            if (!TrafficSourceReleaseHelper::isSelectableForRelease($ts)) {
                continue;
            }
            $tokens = $ts['tokens'] ?? [];
            if (is_string($tokens)) {
                $tokens = json_decode($tokens, true) ?? [];
            }
            $trafficSources[] = [
                'id' => (int)$ts['id'],
                'name' => $ts['name'],
                'cost_param_key' => $ts['cost_param_key'] ?? null,
                'cost_tracking_method' => $ts['cost_tracking_method'] ?? 'manual_token',
                'tokens' => $tokens,
            ];
        }

        $offers = [];
        foreach ($offer->getAll() as $row) {
            $formatted = self::formatOffer($row);
            $formatted['is_available_for_rotation'] = $offer->isAvailableForRotation($row);
            $offers[] = $formatted;
        }

        $groups = [];
        foreach ($campaignGroup->getAll() as $g) {
            $groups[] = ['id' => (int)$g['id'], 'name' => $g['name']];
        }

        $domains = [];
        foreach ($trackingDomain->getAll() as $d) {
            $status = $d['status'] ?? '';
            if (in_array($status, ['verified', 'verified_manual'], true)) {
                $domains[] = [
                    'id' => (int)$d['id'],
                    'domain' => $d['domain'],
                    'url' => $d['url'] ?? null,
                ];
            }
        }

        Response::success([
            'traffic_sources' => $trafficSources,
            'offers' => $offers,
            'landing_pages' => array_map([self::class, 'formatLandingPage'], $landingPage->getAll()),
            'networks' => array_map([self::class, 'formatNetwork'], $network->getAll()),
            'campaign_groups' => $groups,
            'tracking_domains' => $domains,
        ]);
    }
}
