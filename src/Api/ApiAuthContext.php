<?php

declare(strict_types=1);

namespace SimpleKuma\Api;

use mysqli;
use SimpleKuma\Auth\Permission;

/**
 * Authenticated API request context (Bearer API key).
 */
class ApiAuthContext
{
    public function __construct(
        public readonly int $userId,
        public readonly int $apiKeyId,
        public readonly Permission $permission,
        public readonly ?array $user,
    ) {
    }

    public static function fromUserId(mysqli $db, int $userId, int $apiKeyId): ?self
    {
        $stmt = $db->prepare(
            "SELECT id, username, email, timezone, currency, role_id, is_active
             FROM users WHERE id = ? AND is_active = 1"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if (!$user) {
            return null;
        }

        return new self(
            $userId,
            $apiKeyId,
            new Permission($db, $userId),
            $user,
        );
    }

    public function hasPermission(string $permission): bool
    {
        if (\SimpleKuma\Auth\SingleAdminMode::isEnabled()) {
            return true;
        }

        if ($this->permission->hasPermission($permission)) {
            return true;
        }

        return \SimpleKuma\Auth\Auth::allowsLegacyNoRolesFallback();
    }

    public function requirePermission(string $permission): void
    {
        if (!$this->hasPermission($permission)) {
            Response::error('Forbidden - Insufficient permissions', 403, 'forbidden');
        }
    }
}
