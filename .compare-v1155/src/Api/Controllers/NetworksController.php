<?php

declare(strict_types=1);

namespace SimpleKuma\Api\Controllers;

use mysqli;
use SimpleKuma\Api\Request;
use SimpleKuma\Api\Response;
use SimpleKuma\Auth\Permission;
use SimpleKuma\Entity\Network;

class NetworksController extends BaseController
{
    public static function index(mysqli $db, Request $request): void
    {
        $ctx = self::auth($db, $request);
        self::requirePerm($ctx, Permission::PERM_NETWORK_VIEW);

        $entity = new Network($db);
        Response::success(array_map([self::class, 'formatNetwork'], $entity->getAll()));
    }

    public static function show(mysqli $db, Request $request): void
    {
        $ctx = self::auth($db, $request);
        self::requirePerm($ctx, Permission::PERM_NETWORK_VIEW);

        $entity = new Network($db);
        $row = $entity->getById(self::idParam($request));
        if (!$row) {
            Response::error('Network not found', 404, 'not_found');
        }

        Response::success(self::formatNetwork($row));
    }

    public static function create(mysqli $db, Request $request): void
    {
        $ctx = self::auth($db, $request);
        self::requirePerm($ctx, Permission::PERM_NETWORK_MANAGE);

        $input = self::readJson($request);
        $data = [
            'name' => $input['name'] ?? '',
            'postback_template' => $input['postback_template'] ?? null,
            'notes' => $input['notes'] ?? null,
        ];

        $entity = new Network($db);
        $errors = $entity->validate($data);
        if ($errors !== []) {
            self::validationFailed($errors);
        }

        $id = $entity->create($data);
        $row = $entity->getById($id);
        Response::success(self::formatNetwork($row ?? ['id' => $id] + $data), 201);
    }

    public static function update(mysqli $db, Request $request): void
    {
        $ctx = self::auth($db, $request);
        self::requirePerm($ctx, Permission::PERM_NETWORK_MANAGE);

        $id = self::idParam($request);
        $entity = new Network($db);
        $existing = $entity->getById($id);
        if (!$existing) {
            Response::error('Network not found', 404, 'not_found');
        }

        $input = self::readJson($request);
        $data = array_merge($existing, [
            'name' => $input['name'] ?? $existing['name'],
            'postback_template' => array_key_exists('postback_template', $input)
                ? $input['postback_template'] : ($existing['postback_template'] ?? null),
            'notes' => array_key_exists('notes', $input) ? $input['notes'] : ($existing['notes'] ?? null),
        ]);

        $errors = $entity->validate($data);
        if ($errors !== []) {
            self::validationFailed($errors);
        }

        $entity->update($id, $data);
        Response::success(self::formatNetwork($entity->getById($id) ?? $data));
    }

    public static function delete(mysqli $db, Request $request): void
    {
        $ctx = self::auth($db, $request);
        self::requirePerm($ctx, Permission::PERM_NETWORK_MANAGE);

        $id = self::idParam($request);
        $entity = new Network($db);
        if (!$entity->getById($id)) {
            Response::error('Network not found', 404, 'not_found');
        }

        $entity->delete($id);
        Response::success(['deleted' => true, 'id' => $id]);
    }
}
