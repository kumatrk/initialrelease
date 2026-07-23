<?php

declare(strict_types=1);

namespace SimpleKuma\Api;

use mysqli;

/**
 * Simple path router for API v1.
 */
class Router
{
    /** @var list<array{methods: list<string>, pattern: string, regex: string, paramNames: list<string>, handler: callable(mysqli, Request): void}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): self
    {
        return $this->add(['GET'], $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): self
    {
        return $this->add(['POST'], $pattern, $handler);
    }

    public function patch(string $pattern, callable $handler): self
    {
        return $this->add(['PATCH'], $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): self
    {
        return $this->add(['DELETE'], $pattern, $handler);
    }

    /** @param list<string> $methods */
    public function add(array $methods, string $pattern, callable $handler): self
    {
        $paramNames = [];
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $matches) use (&$paramNames): string {
                $paramNames[] = $matches[1];
                return '([^/]+)';
            },
            $pattern
        );

        $this->routes[] = [
            'methods' => array_map('strtoupper', $methods),
            'pattern' => $pattern,
            'regex' => '#^' . $regex . '$#',
            'paramNames' => $paramNames,
            'handler' => $handler,
        ];

        return $this;
    }

    public function dispatch(mysqli $db, Request $request): void
    {
        foreach ($this->routes as $route) {
            if (!in_array($request->method(), $route['methods'], true)) {
                continue;
            }

            if (!preg_match($route['regex'], $request->path(), $matches)) {
                continue;
            }

            array_shift($matches);
            $params = [];
            foreach ($route['paramNames'] as $i => $name) {
                $params[$name] = $matches[$i] ?? '';
            }
            $request->setRouteParams($params);

            ($route['handler'])($db, $request);
            return;
        }

        Response::error('Not found', 404, 'not_found');
    }
}
