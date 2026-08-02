<?php

declare(strict_types=1);

namespace SimpleKuma\Auth;

use mysqli;

/**
 * Belt-and-suspenders gate for local utility / diagnostic PHP under public/.
 * Prefer .htaccess deny in production; this blocks execution if htaccess is missing.
 */
final class DevToolGuard
{
    /**
     * Allow CLI always. For HTTP require an authenticated admin (settings.edit or user.manage).
     * Exits with 403 on failure.
     */
    public static function requireAdminOrCli(mysqli $db): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $auth = new Auth($db);
        if (!$auth->isAuthenticated()) {
            self::forbid('Authentication required');
        }

        $permission = $auth->getPermission();
        if ($permission && (
            $permission->hasPermission(Permission::PERM_SETTINGS_EDIT)
            || $permission->hasPermission(Permission::PERM_USER_MANAGE)
        )) {
            return;
        }

        // Legacy installs with no roles: allow only outside production
        if (Auth::allowsLegacyNoRolesFallback() && empty($_SESSION['role_ids'] ?? [])) {
            return;
        }

        self::forbid('Admin permission required');
    }

    private static function forbid(string $reason): void
    {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow', true);
        echo 'Forbidden: ' . $reason;
        exit;
    }
}
