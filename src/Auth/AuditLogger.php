<?php

declare(strict_types=1);

namespace SimpleKuma\Auth;

use mysqli;

/**
 * Audit Logger
 * Logs user actions, role changes, and permission denials
 */
class AuditLogger
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Log an action
     */
    public function log(
        string $action,
        ?string $resourceType = null,
        ?int $resourceId = null,
        ?string $description = null,
        ?int $userId = null
    ): void {
        $userId = $userId ?? ($_SESSION['user_id'] ?? null);
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $stmt = $this->db->prepare(
            "INSERT INTO audit_logs (user_id, action, resource_type, resource_id, description, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
        );

        $stmt->bind_param(
            'ississs',
            $userId,
            $action,
            $resourceType,
            $resourceId,
            $description,
            $ipAddress,
            $userAgent
        );

        $stmt->execute();
    }

    /**
     * Log login
     */
    public function logLogin(int $userId): void
    {
        $this->log('login', 'user', $userId, "User logged in");
    }

    /**
     * Log logout
     */
    public function logLogout(int $userId): void
    {
        $this->log('logout', 'user', $userId, "User logged out");
    }

    /**
     * Log permission denied
     */
    public function logPermissionDenied(string $permission, string $resource = null): void
    {
        $description = "Permission denied: {$permission}";
        if ($resource) {
            $description .= " on {$resource}";
        }
        $this->log('permission_denied', null, null, $description);
    }

    /**
     * Log role change
     */
    public function logRoleChange(int $targetUserId, string $oldRole, string $newRole): void
    {
        $this->log('role_change', 'user', $targetUserId, "Role changed from {$oldRole} to {$newRole}");
    }

    /**
     * Get audit logs
     */
    public function getLogs(int $limit = 100, int $offset = 0, ?int $userId = null): array
    {
        $sql = "SELECT al.*, u.username 
                FROM audit_logs al
                LEFT JOIN users u ON al.user_id = u.id";
        
        $params = [];
        $types = '';
        
        if ($userId) {
            $sql .= " WHERE al.user_id = ?";
            $params[] = $userId;
            $types .= 'i';
        }
        
        $sql .= " ORDER BY al.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $this->db->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $logs = [];
        while ($row = $result->fetch_assoc()) {
            $logs[] = $row;
        }

        return $logs;
    }
}


