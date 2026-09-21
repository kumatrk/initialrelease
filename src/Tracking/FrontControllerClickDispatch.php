<?php

declare(strict_types=1);

namespace SimpleKuma\Tracking;

/**
 * Safety net when pretty click URLs fall through to the admin front controller.
 *
 * Apache rewrites /km|/go|/c → km.php via .htaccess. Nginx ignores .htaccess;
 * a bare try_files … /index.php would otherwise hit Auth and redirect to login.
 * Prefer explicit nginx rewrites (see docker/nginx.conf.example); this only
 * recovers the common misconfiguration without affecting normal dashboard requests.
 */
final class FrontControllerClickDispatch
{
    /**
     * If REQUEST_URI is a click path, hand off to km.php and exit.
     * No-op for all other URIs (dashboard, login redirects, etc.).
     */
    public static function dispatchIfNeeded(string $kmPhpPath): void
    {
        $path = self::requestPath();
        if ($path === null || $path === '/' || $path === '') {
            return;
        }

        $parsed = self::parseClickPath($path);
        if ($parsed === null) {
            return;
        }

        $_GET['key'] = $parsed['key'];
        if ($parsed['slug'] !== null && $parsed['slug'] !== '') {
            $_GET['slug'] = $parsed['slug'];
        }

        if (!is_file($kmPhpPath)) {
            error_log('FrontControllerClickDispatch: km.php missing at ' . $kmPhpPath);
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Tracking endpoint unavailable';
            exit;
        }

        require $kmPhpPath;
        exit;
    }

    /**
     * @return array{key: string, slug: string|null}|null
     */
    public static function parseClickPath(string $path): ?array
    {
        $prefixes = ClickPath::rewritePrefixes();
        if ($prefixes === []) {
            return null;
        }

        $prefixPattern = implode('|', array_map(
            static fn (string $p): string => preg_quote($p, '~'),
            $prefixes
        ));

        // Same shape as km.php / Apache rules: /{prefix}/{key}[/slug]
        // Allows optional install subdirectory before the click prefix.
        // Use ~ delimiter — path may contain # fragments in other contexts.
        if (preg_match('~/(?:' . $prefixPattern . ')/([a-zA-Z0-9]+)(?:/([^/?]*))?~', $path, $matches) !== 1) {
            return null;
        }

        $key = $matches[1] ?? '';
        if ($key === '') {
            return null;
        }

        $slug = isset($matches[2]) && $matches[2] !== '' ? $matches[2] : null;

        return ['key' => $key, 'slug' => $slug];
    }

    private static function requestPath(): ?string
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if ($requestUri === '') {
            return null;
        }

        $path = parse_url($requestUri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        return $path;
    }
}
