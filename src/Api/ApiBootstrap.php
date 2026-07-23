<?php

declare(strict_types=1);

namespace SimpleKuma\Api;

use SimpleKuma\Api\Controllers\CampaignsController;
use SimpleKuma\Api\Controllers\CatalogController;
use SimpleKuma\Api\Controllers\LandingPagesController;
use SimpleKuma\Api\Controllers\NetworksController;
use SimpleKuma\Api\Controllers\OffersController;
use SimpleKuma\Api\Controllers\StatsController;
use mysqli;

class ApiBootstrap
{
    public static function createRouter(): Router
    {
        $router = new Router();

        $router->get('/api/v1/health', static function (mysqli $db, Request $request): void {
            Response::success([
                'status' => 'ok',
                'version' => '1.0.0',
            ]);
        });

        $router->get('/api/v1/openapi.json', static function (mysqli $db, Request $request): void {
            OpenApiBuilder::send();
        });

        $router->get('/api/v1/catalog', [CatalogController::class, 'show']);

        $router->get('/api/v1/networks', [NetworksController::class, 'index']);
        $router->post('/api/v1/networks', [NetworksController::class, 'create']);
        $router->get('/api/v1/networks/{id}', [NetworksController::class, 'show']);
        $router->patch('/api/v1/networks/{id}', [NetworksController::class, 'update']);
        $router->delete('/api/v1/networks/{id}', [NetworksController::class, 'delete']);

        $router->get('/api/v1/offers', [OffersController::class, 'index']);
        $router->post('/api/v1/offers', [OffersController::class, 'create']);
        $router->get('/api/v1/offers/{id}', [OffersController::class, 'show']);
        $router->patch('/api/v1/offers/{id}', [OffersController::class, 'update']);
        $router->delete('/api/v1/offers/{id}', [OffersController::class, 'delete']);

        $router->get('/api/v1/landing-pages', [LandingPagesController::class, 'index']);
        $router->post('/api/v1/landing-pages', [LandingPagesController::class, 'create']);
        $router->get('/api/v1/landing-pages/{id}', [LandingPagesController::class, 'show']);
        $router->patch('/api/v1/landing-pages/{id}', [LandingPagesController::class, 'update']);
        $router->delete('/api/v1/landing-pages/{id}', [LandingPagesController::class, 'delete']);

        $router->get('/api/v1/campaigns', [CampaignsController::class, 'index']);
        $router->post('/api/v1/campaigns', [CampaignsController::class, 'create']);
        $router->get('/api/v1/campaigns/{id}', [CampaignsController::class, 'show']);
        $router->patch('/api/v1/campaigns/{id}', [CampaignsController::class, 'update']);
        $router->patch('/api/v1/campaigns/{id}/status', [CampaignsController::class, 'updateStatus']);
        $router->get('/api/v1/campaigns/{id}/tracking-link', [CampaignsController::class, 'trackingLink']);

        $router->get('/api/v1/stats/campaigns', [StatsController::class, 'campaigns']);
        $router->get('/api/v1/stats/campaigns/{id}', [StatsController::class, 'campaign']);
        $router->get('/api/v1/clicks', [StatsController::class, 'clicks']);
        $router->get('/api/v1/conversions', [StatsController::class, 'conversions']);

        return $router;
    }
}
