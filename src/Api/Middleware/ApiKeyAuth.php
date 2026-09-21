<?php

declare(strict_types=1);

namespace SimpleKuma\Api\Middleware;

use mysqli;
use SimpleKuma\Api\ApiAuthContext;
use SimpleKuma\Api\Request;
use SimpleKuma\Api\Response;
use SimpleKuma\Entity\ApiKey;

class ApiKeyAuth
{
    public static function authenticate(mysqli $db, Request $request): ApiAuthContext
    {
        $token = $request->bearerToken();
        if ($token === null || $token === '') {
            Response::error('Unauthorized - Bearer API key required', 401, 'unauthorized');
        }

        $apiKeyEntity = new ApiKey($db);
        $auth = $apiKeyEntity->authenticate($token);
        if ($auth === null) {
            Response::error('Unauthorized - Invalid or revoked API key', 401, 'unauthorized');
        }

        $context = ApiAuthContext::fromUserId($db, $auth['user_id'], $auth['api_key_id']);
        if ($context === null) {
            Response::error('Unauthorized - API key user not found or inactive', 401, 'unauthorized');
        }

        ApiRateLimiter::check($db, $auth['api_key_id']);

        return $context;
    }
}
