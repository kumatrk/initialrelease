<?php

declare(strict_types=1);

namespace SimpleKuma\Api;

/**
 * HTTP request wrapper for API v1.
 */
class Request
{
    private string $method;
    private string $path;
    /** @var array<string, string> */
    private array $query;
    /** @var array<string, mixed>|null */
    private ?array $jsonBody = null;
    /** @var array<string, string> */
    private array $routeParams = [];

    public function __construct(?string $method = null, ?string $path = null)
    {
        $this->method = strtoupper($method ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $this->path = $path ?? self::parsePathFromUri($_SERVER['REQUEST_URI'] ?? '/');
        $this->query = [];
        foreach ($_GET as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $this->query[$key] = (string)$value;
            }
        }
    }

    public static function parsePathFromUri(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path)) {
            return '/';
        }

        // Strip /public prefix if present
        if (str_starts_with($path, '/public/')) {
            $path = substr($path, 7);
        }

        // Strip web prefix (subdirectory installs)
        if (defined('PUBLIC_WEB_PREFIX') && PUBLIC_WEB_PREFIX !== '') {
            $prefix = rtrim(PUBLIC_WEB_PREFIX, '/');
            if (str_starts_with($path, $prefix . '/')) {
                $path = substr($path, strlen($prefix));
            } elseif ($path === $prefix) {
                $path = '/';
            }
        }

        // Normalize /api/v1/index.php → /api/v1
        $path = preg_replace('#/api/v1/index\.php#', '/api/v1', $path) ?? $path;

        // Ensure path starts with /api/v1
        if (!str_starts_with($path, '/api/v1')) {
            $pos = strpos($path, '/api/v1');
            if ($pos !== false) {
                $path = substr($path, $pos);
            }
        }

        $path = rtrim($path, '/') ?: '/';

        return $path;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /** @param array<string, string> $params */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function routeParam(string $name, ?string $default = null): ?string
    {
        return $this->routeParams[$name] ?? $default;
    }

    public function query(string $name, ?string $default = null): ?string
    {
        return $this->query[$name] ?? $default;
    }

    public function queryInt(string $name, ?int $default = null): ?int
    {
        $val = $this->query($name);
        if ($val === null || $val === '') {
            return $default;
        }

        return (int)$val;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if ($this->jsonBody !== null) {
            return $this->jsonBody;
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            $this->jsonBody = [];
            return $this->jsonBody;
        }

        $decoded = json_decode($raw, true);
        $this->jsonBody = is_array($decoded) ? $decoded : [];

        return $this->jsonBody;
    }

    public function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
