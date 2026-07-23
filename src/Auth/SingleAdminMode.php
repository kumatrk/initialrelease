<?php

declare(strict_types=1);

namespace SimpleKuma\Auth;

use mysqli;

/**
 * Single-tenant installs: every active user is treated as admin (no role picker).
 */
class SingleAdminMode
{
    public static function isEnabled(): bool
    {
        return defined('SINGLE_ADMIN_MODE') && SINGLE_ADMIN_MODE === true;
    }

    /**
     * Ensure users.role_id and user_roles point at the admin role.
     */
    public static function ensureAdminRoleForUser(mysqli $db, int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $adminRoleId = self::getAdminRoleId($db);
        if ($adminRoleId === null) {
            return;
        }

        $stmt = $db->prepare('UPDATE users SET role_id = ? WHERE id = ? AND is_active = 1');
        if ($stmt) {
            $stmt->bind_param('ii', $adminRoleId, $userId);
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $db->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)');
        if ($stmt) {
            $stmt->bind_param('ii', $userId, $adminRoleId);
            $stmt->execute();
            $stmt->close();
        }
    }

    public static function getAdminRoleId(mysqli $db): ?int
    {
        $result = $db->query("SELECT id FROM roles WHERE name = 'admin' LIMIT 1");
        if (!$result) {
            return null;
        }

        $row = $result->fetch_assoc();
        $result->free();

        return $row ? (int) $row['id'] : null;
    }

    /**
     * @return array{0: list<int>, 1: list<string>}
     */
    public static function adminSessionRoles(mysqli $db): array
    {
        $adminRoleId = self::getAdminRoleId($db);

        if ($adminRoleId === null) {
            return [[], []];
        }

        return [[$adminRoleId], ['admin']];
    }
}
