<?php

declare(strict_types=1);

namespace SimpleKuma\Auth;

/**
 * Shared auth + permission gate for JSON/HTML API endpoints.
 */
class ApiAuth
{
    public static function requirePermission(Auth $auth, string $permission): void
    {
        if (!$auth->isAuthenticated()) {
            self::deny(401, 'Unauthorized - Authentication required');
        }

        $perm = $auth->getPermission();
        if (!$perm) {
            self::deny(403, 'Forbidden');
        }

        if (
            !$perm->hasPermission($permission)
            && !SingleAdminMode::isEnabled()
            && !(
                self::allowsLegacyNoRolesFallback()
                && empty($_SESSION['role_ids'] ?? [])
            )
        ) {
            self::deny(403, 'Forbidden - Insufficient permissions');
        }
    }

    private static function allowsLegacyNoRolesFallback(): bool
    {
        return Auth::allowsLegacyNoRolesFallback();
    }

    private static function deny(int $code, string $message): void
    {
        http_response_code($code);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['error' => $message]);
        exit;
    }
}
