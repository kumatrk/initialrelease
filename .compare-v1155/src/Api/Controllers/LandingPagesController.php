<?php

declare(strict_types=1);

namespace SimpleKuma\Api\Controllers;

use mysqli;
use SimpleKuma\Api\Request;
use SimpleKuma\Api\Response;
use SimpleKuma\Auth\Permission;
use SimpleKuma\Entity\LandingPage;

class LandingPagesController extends BaseController
{
    public static function index(mysqli $db, Request $request): void
    {
        $ctx = self::auth($db, $request);
        self::requirePerm($ctx, Permission::PERM_LANDING_PAGE_VIEW);

        $entity = new LandingPage($db);
        Response::success(array_map([self::class, 'formatLandingPage'], $entity->getAll()));
    }

    public static function show(mysqli $db, Request $request): void
    {
        $ctx = self::auth($db, $request);
        self::requirePerm($ctx, Permission::PERM_LANDING_PAGE_VIEW);

        $entity = new LandingPage($db);
        $row = $entity->getById(self::idParam($request));
        if (!$row) {
            Response::error('Landing page not found', 404, 'not_found');
        }

        Response::success(self::formatLandingPage($row));
    }

    public static function create(mysqli $db, Request $request): void
    {
        $ctx = self::auth($db, $request);
        self::requirePerm($ctx, Permission::PERM_LANDING_PAGE_MANAGE);

        $input = self::readJson($request);
        $data = [
            'name' => $input['name'] ?? '',
            'url' => $input['url'] ?? '',
            'notes' => $input['notes'] ?? null,
        ];

        $entity = new LandingPage($db);
        $errors = $entity->validate($data);
        if ($errors !== []) {
            self::validationFailed($errors);
        }

        $id = $entity->create($data);
        $row = $entity->getById($id);
        Response::success(self::formatLandingPage($row ?? ['id' => $id] + $data), 201);
    }

    public static function update(mysqli $db, Request $request): void
    {
        $ctx = self::auth($db, $request);
        self::requirePerm($ctx, Permission::PERM_LANDING_PAGE_MANAGE);

        $id = self::idParam($request);
        $entity = new LandingPage($db);
        $existing = $entity->getById($id);
        if (!$existing) {
            Response::error('Landing page not found', 404, 'not_found');
        }

        $input = self::readJson($request);
        $data = array_merge($existing, [
            'name' => $input['name'] ?? $existing['name'],
            'url' => $input['url'] ?? $existing['url'],
            'notes' => array_key_exists('notes', $input) ? $input['notes'] : ($existing['notes'] ?? null),
        ]);

        $errors = $entity->validate($data);
        if ($errors !== []) {
            self::validationFailed($errors);
        }

        $entity->update($id, $data);
        Response::success(self::formatLandingPage($entity->getById($id) ?? $data));
    }

    public static function delete(mysqli $db, Request $request): void
    {
        $ctx = self::auth($db, $request);
        self::requirePerm($ctx, Permission::PERM_LANDING_PAGE_MANAGE);

        $id = self::idParam($request);
        $entity = new LandingPage($db);
        if (!$entity->getById($id)) {
            Response::error('Landing page not found', 404, 'not_found');
        }

        $blockReason = $entity->getDeleteBlockReason($id);
        if ($blockReason !== null) {
            Response::error($blockReason, 409, 'in_use');
        }

        $entity->delete($id);
        Response::success(['deleted' => true, 'id' => $id]);
    }
}
