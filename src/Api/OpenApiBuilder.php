<?php

declare(strict_types=1);

namespace SimpleKuma\Api;

/**
 * Builds OpenAPI 3.0 spec from RouteRegistry.
 */
class OpenApiBuilder
{
    public static function build(): array
    {
        $baseUrl = self::apiBaseUrl();

        $paths = [];
        foreach (RouteRegistry::routes() as $route) {
            if (in_array($route['path'], ['/api/v1/health', '/api/v1/openapi.json'], true)) {
                continue;
            }

            $pathKey = $route['path'];
            $method = strtolower($route['method']);
            if (!isset($paths[$pathKey])) {
                $paths[$pathKey] = [];
            }

            $paths[$pathKey][$method] = [
                'summary' => $route['description'],
                'security' => $route['auth'] ? [['BearerAuth' => []]] : [],
                'responses' => [
                    '200' => ['description' => 'Success'],
                    '401' => ['description' => 'Unauthorized'],
                    '403' => ['description' => 'Forbidden'],
                ],
            ];
        }

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Simple KUMA API',
                'version' => '1.0.0',
                'description' => 'REST API for campaign management, tracking links, and stats.',
            ],
            'servers' => [
                ['url' => $baseUrl],
            ],
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'description' => 'API key from Settings → API (format: kuma_...)',
                    ],
                ],
            ],
            'paths' => $paths,
        ];
    }

    public static function send(): never
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(self::build(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    public static function apiBaseUrl(): string
    {
        if (defined('APP_BASE_URL')) {
            return rtrim(APP_BASE_URL, '/') . '/api/v1';
        }
        if (defined('BASE_URL')) {
            return rtrim(BASE_URL, '/') . '/api/v1';
        }

        return '/api/v1';
    }
}
