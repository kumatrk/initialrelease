<?php

declare(strict_types=1);

namespace SimpleKuma\Honeycomb;

/**
 * Addon-owned JSON API actions for the campaign editor (pickers, status, etc.).
 *
 * Routed by core via public/api/honeycomb-addon.php?slug=…&action=…
 * so new networks do not need a new core PHP endpoint.
 */
interface AddonHttpHandler
{
    public function addonSlug(): string;

    /**
     * Handle one action. Return a JSON-serializable payload.
     * Throw or return ['success'=>false,'error'=>…] on failure.
     * Optional key `_http_status` (int) overrides the response status code.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body  Decoded JSON body for POST (else empty)
     * @return array<string, mixed>
     */
    public function handle(string $action, array $query, array $body): array;
}
