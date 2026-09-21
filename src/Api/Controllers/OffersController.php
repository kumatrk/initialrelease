<?php

declare(strict_types=1);

namespace SimpleKuma\Api\Controllers;

use mysqli;
use SimpleKuma\Api\Request;
use SimpleKuma\Api\Response;
use SimpleKuma\Auth\Permission;
use SimpleKuma\Entity\Offer;

class OffersController extends BaseController
{
    public static function index(mysqli $db, Request $request): void
    {
        $ctx = self::auth($db, $request);
        self::requirePerm($ctx, Permission::PERM_OFFER_VIEW);

        $entity = new Offer($db);
        $rows = [];
        foreach ($entity->getAll() as $row) {
            $formatted = self::formatOffer($row);
            $formatted['is_available_for_rotation'] = $entity->isAvailableForRotation($row);
            $rows[] = $formatted;
        }

        Response::success($rows);
    }

    public static function show(mysqli $db, Request $request): void
    {
        $ctx = self::auth($db, $request);
        self::requirePerm($ctx, Permission::PERM_OFFER_VIEW);

        $entity = new Offer($db);
        $row = $entity->getById(self::idParam($request));
        if (!$row) {
            Response::error('Offer not found', 404, 'not_found');
        }

        $formatted = self::formatOffer($row);
        $formatted['is_available_for_rotation'] = $entity->isAvailableForRotation($row);
        Response::success($formatted);
    }

    public static function create(mysqli $db, Request $request): void
    {
        $ctx = self::auth($db, $request);
        self::requirePerm($ctx, Permission::PERM_OFFER_MANAGE);

        $input = self::readJson($request);
        $data = array_merge([
            'name' => '',
            'url' => '',
            'payout_type' => 'CPA',
            'payout_value' => 0,
            'network_id' => null,
            'notes' => null,
            'is_24_7' => 1,
            'schedule_days' => [],
            'schedule_start_time' => null,
            'schedule_end_time' => null,
            'schedule_timezone' => 'UTC',
            'cap_enabled' => 0,
            'cap_limit' => null,
            'cap_period' => null,
            'cap_timezone' => 'UTC',
        ], self::normalizeOfferInput($input));

        $entity = new Offer($db);
        $errors = $entity->validate($data);
        if ($errors !== []) {
            self::validationFailed($errors);
        }

        $id = $entity->create($data);
        $row = $entity->getById($id);
        Response::success(self::formatOffer($row ?? ['id' => $id] + $data), 201);
    }

    public static function update(mysqli $db, Request $request): void
    {
        $ctx = self::auth($db, $request);
        self::requirePerm($ctx, Permission::PERM_OFFER_MANAGE);

        $id = self::idParam($request);
        $entity = new Offer($db);
        $existing = $entity->getById($id);
        if (!$existing) {
            Response::error('Offer not found', 404, 'not_found');
        }

        $input = self::readJson($request);
        $data = array_merge($existing, self::normalizeOfferInput($input, true));

        $errors = $entity->validate($data);
        if ($errors !== []) {
            self::validationFailed($errors);
        }

        $entity->update($id, $data);
        Response::success(self::formatOffer($entity->getById($id) ?? $data));
    }

    public static function delete(mysqli $db, Request $request): void
    {
        $ctx = self::auth($db, $request);
        self::requirePerm($ctx, Permission::PERM_OFFER_MANAGE);

        $id = self::idParam($request);
        $entity = new Offer($db);
        if (!$entity->getById($id)) {
            Response::error('Offer not found', 404, 'not_found');
        }

        $blockReason = $entity->getDeleteBlockReason($id);
        if ($blockReason !== null) {
            Response::error($blockReason, 409, 'in_use');
        }

        $entity->delete($id);
        Response::success(['deleted' => true, 'id' => $id]);
    }
}
