<?php

declare(strict_types=1);

namespace SimpleKuma\Api;

use SimpleKuma\Auth\Permission;

/**
 * Central route registry — feeds Router, OpenAPI, and Settings docs.
 */
class RouteRegistry
{
    /**
     * @return list<array{method: string, path: string, permission: string|null, description: string, auth: bool}>
     */
    public static function routes(): array
    {
        return [
            ['method' => 'GET', 'path' => '/api/v1/health', 'permission' => null, 'description' => 'Health check', 'auth' => false],
            ['method' => 'GET', 'path' => '/api/v1/openapi.json', 'permission' => null, 'description' => 'OpenAPI specification', 'auth' => false],
            ['method' => 'GET', 'path' => '/api/v1/catalog', 'permission' => Permission::PERM_CAMPAIGN_VIEW, 'description' => 'Bootstrap reference data', 'auth' => true],
            ['method' => 'GET', 'path' => '/api/v1/networks', 'permission' => Permission::PERM_NETWORK_VIEW, 'description' => 'List networks', 'auth' => true],
            ['method' => 'POST', 'path' => '/api/v1/networks', 'permission' => Permission::PERM_NETWORK_MANAGE, 'description' => 'Create network', 'auth' => true],
            ['method' => 'GET', 'path' => '/api/v1/networks/{id}', 'permission' => Permission::PERM_NETWORK_VIEW, 'description' => 'Get network', 'auth' => true],
            ['method' => 'PATCH', 'path' => '/api/v1/networks/{id}', 'permission' => Permission::PERM_NETWORK_MANAGE, 'description' => 'Update network', 'auth' => true],
            ['method' => 'DELETE', 'path' => '/api/v1/networks/{id}', 'permission' => Permission::PERM_NETWORK_MANAGE, 'description' => 'Delete network', 'auth' => true],
            ['method' => 'GET', 'path' => '/api/v1/offers', 'permission' => Permission::PERM_OFFER_VIEW, 'description' => 'List offers', 'auth' => true],
            ['method' => 'POST', 'path' => '/api/v1/offers', 'permission' => Permission::PERM_OFFER_MANAGE, 'description' => 'Create offer', 'auth' => true],
            ['method' => 'GET', 'path' => '/api/v1/offers/{id}', 'permission' => Permission::PERM_OFFER_VIEW, 'description' => 'Get offer', 'auth' => true],
            ['method' => 'PATCH', 'path' => '/api/v1/offers/{id}', 'permission' => Permission::PERM_OFFER_MANAGE, 'description' => 'Update offer', 'auth' => true],
            ['method' => 'DELETE', 'path' => '/api/v1/offers/{id}', 'permission' => Permission::PERM_OFFER_MANAGE, 'description' => 'Delete offer', 'auth' => true],
            ['method' => 'GET', 'path' => '/api/v1/landing-pages', 'permission' => Permission::PERM_LANDING_PAGE_VIEW, 'description' => 'List landing pages', 'auth' => true],
            ['method' => 'POST', 'path' => '/api/v1/landing-pages', 'permission' => Permission::PERM_LANDING_PAGE_MANAGE, 'description' => 'Create landing page', 'auth' => true],
            ['method' => 'GET', 'path' => '/api/v1/landing-pages/{id}', 'permission' => Permission::PERM_LANDING_PAGE_VIEW, 'description' => 'Get landing page', 'auth' => true],
            ['method' => 'PATCH', 'path' => '/api/v1/landing-pages/{id}', 'permission' => Permission::PERM_LANDING_PAGE_MANAGE, 'description' => 'Update landing page', 'auth' => true],
            ['method' => 'DELETE', 'path' => '/api/v1/landing-pages/{id}', 'permission' => Permission::PERM_LANDING_PAGE_MANAGE, 'description' => 'Delete landing page', 'auth' => true],
            ['method' => 'GET', 'path' => '/api/v1/campaigns', 'permission' => Permission::PERM_CAMPAIGN_VIEW, 'description' => 'List campaigns', 'auth' => true],
            ['method' => 'POST', 'path' => '/api/v1/campaigns', 'permission' => Permission::PERM_CAMPAIGN_CREATE, 'description' => 'Create campaign', 'auth' => true],
            ['method' => 'GET', 'path' => '/api/v1/campaigns/{id}', 'permission' => Permission::PERM_CAMPAIGN_VIEW, 'description' => 'Get campaign', 'auth' => true],
            ['method' => 'PATCH', 'path' => '/api/v1/campaigns/{id}', 'permission' => Permission::PERM_CAMPAIGN_EDIT, 'description' => 'Update campaign', 'auth' => true],
            ['method' => 'PATCH', 'path' => '/api/v1/campaigns/{id}/status', 'permission' => Permission::PERM_CAMPAIGN_EDIT, 'description' => 'Update campaign status', 'auth' => true],
            ['method' => 'GET', 'path' => '/api/v1/campaigns/{id}/tracking-link', 'permission' => Permission::PERM_CAMPAIGN_VIEW, 'description' => 'Get campaign tracking URL', 'auth' => true],
            ['method' => 'GET', 'path' => '/api/v1/stats/campaigns', 'permission' => Permission::PERM_STATS_VIEW, 'description' => 'Campaign stats summary', 'auth' => true],
            ['method' => 'GET', 'path' => '/api/v1/stats/campaigns/{id}', 'permission' => Permission::PERM_STATS_VIEW, 'description' => 'Single campaign stats', 'auth' => true],
            ['method' => 'GET', 'path' => '/api/v1/clicks', 'permission' => Permission::PERM_VISITOR_LOG_VIEW, 'description' => 'Paginated clicks', 'auth' => true],
            ['method' => 'GET', 'path' => '/api/v1/conversions', 'permission' => Permission::PERM_STATS_VIEW, 'description' => 'Paginated conversions', 'auth' => true],
        ];
    }
}
