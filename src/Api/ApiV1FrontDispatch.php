<?php

declare(strict_types=1);

namespace SimpleKuma\Api;

/**
 * Safety net when /api/v1/* falls through to the admin front controller.
 *
 * Apache rewrites those paths in public/.htaccess (and public/api/.htaccess).
 * Nginx does not. A bare try_files … /index.php would otherwise hit Auth and
 * 302 to login, so Hermes / Bearer clients see a "connection" failure.
 * Prefer explicit nginx rewrites (see docker/nginx.conf.example); this only
 * recovers the common misconfiguration without affecting the admin UI.
 */
final class ApiV1FrontDispatch
{
    public static function isApiV1Uri(string $requestUri): bool
    {
        $path = parse_url($requestUri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return false;
        }

        // /api/v1, /api/v1/, /api/v1/catalog — not /api/v10 or /api/v1-backup
        // Allows an optional install subdirectory before api/v1.
        return preg_match('~(?:^|/)api/v1(?:/|$)~', $path) === 1;
    }

    /**
     * If this request is a REST API v1 URL, run api/v1/index.php and stop.
     * No-op for every other URI, including the admin UI.
     */
    public static function dispatchIfNeeded(string $apiV1IndexPhpPath): void
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (!is_string($requestUri) || !self::isApiV1Uri($requestUri)) {
            return;
        }

        if (!is_file($apiV1IndexPhpPath)) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'error' => [
                    'code' => 'api_missing',
                    'message' => 'REST API v1 front controller is missing',
                ],
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }

        require $apiV1IndexPhpPath;
        exit;
    }
}
