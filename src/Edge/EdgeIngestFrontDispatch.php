<?php

declare(strict_types=1);

namespace SimpleKuma\Edge;

/**
 * Safety net when POST /api/edge-click falls through to the admin front controller.
 *
 * Apache rewrites that path in public/.htaccess. Nginx does not. A bare
 * try_files … /index.php would otherwise hit Auth and 302 to login, and an
 * older Worker treated the followed login page as a successful ingest.
 */
final class EdgeIngestFrontDispatch
{
    public static function isIngestUri(string $requestUri): bool
    {
        $path = parse_url($requestUri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return false;
        }
        $path = rtrim($path, '/');
        if ($path === '') {
            return false;
        }

        return preg_match('~(?:^|/)api/edge-click$~', $path) === 1;
    }

    /**
     * If this request is the edge ingest URL, run edge-click.php and stop.
     * No-op for every other URI, including the admin UI.
     */
    public static function dispatchIfNeeded(string $edgeClickPhpPath): void
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (!is_string($requestUri) || !self::isIngestUri($requestUri)) {
            return;
        }

        if (!is_file($edgeClickPhpPath)) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'ingest_missing', 'message' => 'Edge ingest endpoint is missing']);
            exit;
        }

        require $edgeClickPhpPath;
        exit;
    }
}
