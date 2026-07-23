<?php

declare(strict_types=1);

namespace SimpleKuma\Api\Controllers;

use mysqli;
use SimpleKuma\Api\ApiAuthContext;
use SimpleKuma\Api\Middleware\ApiKeyAuth;
use SimpleKuma\Api\Request;
use SimpleKuma\Api\Response;
use SimpleKuma\Auth\Permission;

abstract class BaseController
{
    protected static function auth(mysqli $db, Request $request): ApiAuthContext
    {
        return ApiKeyAuth::authenticate($db, $request);
    }

    protected static function requirePerm(ApiAuthContext $ctx, string $permission): void
    {
        $ctx->requirePermission($permission);
    }

    /** @return array<string, mixed> */
    protected static function readJson(Request $request): array
    {
        return $request->json();
    }

    protected static function idParam(Request $request): int
    {
        $id = (int)($request->routeParam('id') ?? 0);
        if ($id <= 0) {
            Response::error('Invalid ID', 400, 'invalid_id');
        }

        return $id;
    }

    /** @param array<string, string|list<string>> $errors */
    protected static function validationFailed(array $errors): never
    {
        Response::validationError('Validation failed', $errors);
    }

    /** @param array<string, mixed> $row */
    protected static function formatNetwork(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'postback_template' => $row['postback_template'] ?? null,
            'notes' => $row['notes'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /** @param array<string, mixed> $row */
    protected static function formatLandingPage(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'url' => $row['url'],
            'notes' => $row['notes'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /** @param array<string, mixed> $row */
    protected static function formatOffer(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'url' => $row['url'],
            'payout_type' => $row['payout_type'],
            'payout_value' => (float)$row['payout_value'],
            'network_id' => isset($row['network_id']) ? (int)$row['network_id'] : null,
            'network_name' => $row['network_name'] ?? null,
            'notes' => $row['notes'] ?? null,
            'is_24_7' => (bool)(int)($row['is_24_7'] ?? 1),
            'schedule_days' => $row['schedule_days'] ?? [],
            'schedule_start_time' => $row['schedule_start_time'] ?? null,
            'schedule_end_time' => $row['schedule_end_time'] ?? null,
            'schedule_timezone' => $row['schedule_timezone'] ?? 'UTC',
            'cap_enabled' => (bool)(int)($row['cap_enabled'] ?? 0),
            'cap_limit' => isset($row['cap_limit']) ? (int)$row['cap_limit'] : null,
            'cap_period' => $row['cap_period'] ?? null,
            'cap_timezone' => $row['cap_timezone'] ?? 'UTC',
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /** @param array<string, mixed> $input */
    protected static function normalizeOfferInput(array $input, bool $partial = false): array
    {
        $data = [];
        $fields = [
            'name', 'url', 'payout_type', 'payout_value', 'network_id', 'notes',
            'is_24_7', 'schedule_days', 'schedule_start_time', 'schedule_end_time', 'schedule_timezone',
            'cap_enabled', 'cap_limit', 'cap_period', 'cap_timezone',
        ];

        foreach ($fields as $field) {
            if ($partial && !array_key_exists($field, $input)) {
                continue;
            }
            if (array_key_exists($field, $input)) {
                $data[$field] = $input[$field];
            }
        }

        if (isset($data['network_id']) && ($data['network_id'] === '' || $data['network_id'] === null)) {
            $data['network_id'] = null;
        }
        if (isset($data['is_24_7'])) {
            $data['is_24_7'] = filter_var($data['is_24_7'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        if (isset($data['cap_enabled'])) {
            $data['cap_enabled'] = filter_var($data['cap_enabled'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }

        return $data;
    }
}
