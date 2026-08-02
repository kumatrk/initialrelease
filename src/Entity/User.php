<?php

declare(strict_types=1);

namespace SimpleKuma\Entity;

use mysqli;
use SimpleKuma\Auth\SingleAdminMode;

/**
 * User Entity
 * Handles CRUD operations for users and role management
 */
class User
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Get all users with their roles
     */
    public function getAll(): array
    {
        $result = $this->db->query(
            "SELECT u.id, u.username, u.email, u.role_id, u.is_active, u.timezone, u.currency, u.created_at, u.updated_at,
                    r.name as primary_role_name, r.display_name as primary_role_display
             FROM users u
             LEFT JOIN roles r ON u.role_id = r.id
             ORDER BY u.created_at DESC"
        );

        if (!$result) {
            return [];
        }

        $users = [];
        while ($row = $result->fetch_assoc()) {
            // Get additional roles
            $stmt = $this->db->prepare(
                "SELECT r.id, r.name, r.display_name 
                 FROM user_roles ur
                 INNER JOIN roles r ON ur.role_id = r.id
                 WHERE ur.user_id = ?"
            );
            $stmt->bind_param('i', $row['id']);
            $stmt->execute();
            $rolesResult = $stmt->get_result();
            
            $additionalRoles = [];
            while ($roleRow = $rolesResult->fetch_assoc()) {
                $additionalRoles[] = $roleRow;
            }
            
            $row['additional_roles'] = $additionalRoles;
            $users[] = $row;
        }

        return $users;
    }

    /**
     * Get user by ID with roles
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT u.id, u.username, u.email, u.pass_hash, u.role_id, u.is_active, u.timezone, u.currency, u.created_at, u.updated_at,
                    r.name as primary_role_name, r.display_name as primary_role_display
             FROM users u
             LEFT JOIN roles r ON u.role_id = r.id
             WHERE u.id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if (!$row) {
            return null;
        }

        // Get additional roles
        $stmt = $this->db->prepare(
            "SELECT r.id, r.name, r.display_name 
             FROM user_roles ur
             INNER JOIN roles r ON ur.role_id = r.id
             WHERE ur.user_id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $rolesResult = $stmt->get_result();
        
        $additionalRoles = [];
        while ($roleRow = $rolesResult->fetch_assoc()) {
            $additionalRoles[] = $roleRow;
        }
        
        $row['additional_roles'] = $additionalRoles;

        return $row;
    }

    /**
     * Create new user
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO users (username, pass_hash, email, role_id, is_active, created_at) 
             VALUES (?, ?, ?, ?, ?, NOW())"
        );

        $passwordHash = \SimpleKuma\Auth\PasswordHasher::hash($data['password']);

        $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;
        $roleId = !empty($data['role_id']) ? (int)$data['role_id'] : null;
        if (SingleAdminMode::isEnabled()) {
            $roleId = SingleAdminMode::getAdminRoleId($this->db);
        }

        $stmt->bind_param(
            'sssii',
            $data['username'],
            $passwordHash,
            $data['email'],
            $roleId,
            $isActive
        );

        $stmt->execute();
        $userId = $stmt->insert_id;

        if (SingleAdminMode::isEnabled()) {
            SingleAdminMode::ensureAdminRoleForUser($this->db, (int) $userId);
        } elseif (!empty($data['additional_role_ids']) && is_array($data['additional_role_ids'])) {
            foreach ($data['additional_role_ids'] as $additionalRoleId) {
                $this->assignRole($userId, (int) $additionalRoleId);
            }
        }

        return $userId;
    }

    /**
     * Update user
     */
    public function update(int $id, array $data): bool
    {
        if (SingleAdminMode::isEnabled()) {
            unset($data['role_id'], $data['additional_role_ids']);
            SingleAdminMode::ensureAdminRoleForUser($this->db, $id);
        }

        $updates = [];
        $params = [];
        $types = '';

        if (isset($data['username'])) {
            $updates[] = 'username = ?';
            $params[] = $data['username'];
            $types .= 's';
        }

        if (isset($data['email'])) {
            $updates[] = 'email = ?';
            $params[] = $data['email'];
            $types .= 's';
        }

        if (isset($data['password'])) {
            $passwordHash = \SimpleKuma\Auth\PasswordHasher::hash($data['password']);
            $updates[] = 'pass_hash = ?';
            $params[] = $passwordHash;
            $types .= 's';
        }

        if (isset($data['role_id'])) {
            $updates[] = 'role_id = ?';
            $params[] = $data['role_id'] ?: null;
            $types .= 'i';
        }

        if (isset($data['is_active'])) {
            $updates[] = 'is_active = ?';
            $params[] = (int)$data['is_active'];
            $types .= 'i';
        }

        if (isset($data['timezone'])) {
            $updates[] = 'timezone = ?';
            $params[] = $data['timezone'];
            $types .= 's';
        }

        if (isset($data['currency'])) {
            $updates[] = 'currency = ?';
            $params[] = $data['currency'];
            $types .= 's';
        }

        if (empty($updates)) {
            return false;
        }

        $updates[] = 'updated_at = NOW()';
        $types .= 'i';
        $params[] = $id;

        $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $result = $stmt->execute();

        if ($result && isset($data['password'])) {
            // Invalidate other sessions + remember-me after any password write
            $col = $this->db->query("SHOW COLUMNS FROM users LIKE 'auth_epoch'");
            if ($col && $col->num_rows > 0) {
                $bump = $this->db->prepare('UPDATE users SET auth_epoch = auth_epoch + 1 WHERE id = ?');
                $bump->bind_param('i', $id);
                $bump->execute();
            }
            $tokTable = $this->db->query("SHOW TABLES LIKE 'remember_tokens'");
            if ($tokTable && $tokTable->num_rows > 0) {
                $del = $this->db->prepare('DELETE FROM remember_tokens WHERE user_id = ?');
                $del->bind_param('i', $id);
                $del->execute();
            }
        }

        // Update additional roles if provided
        if (isset($data['additional_role_ids']) && is_array($data['additional_role_ids'])) {
            // Remove all existing additional roles
            $this->removeAllRoles($id);
            
            // Assign new roles
            foreach ($data['additional_role_ids'] as $roleId) {
                $this->assignRole($id, (int)$roleId);
            }
        }

        return $result;
    }

    /**
     * Delete user (soft delete by setting is_active = 0)
     */
    public function delete(int $id): bool
    {
        // Soft delete by deactivating
        $stmt = $this->db->prepare("UPDATE users SET is_active = 0, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }

    /**
     * Hard delete user (removes from database)
     */
    public function hardDelete(int $id): bool
    {
        // Remove all role assignments first (will cascade)
        $stmt = $this->db->prepare("DELETE FROM user_roles WHERE user_id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();

        // Delete user
        $stmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }

    /**
     * Assign role to user
     */
    public function assignRole(int $userId, int $roleId): bool
    {
        $stmt = $this->db->prepare(
            "INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)"
        );
        $stmt->bind_param('ii', $userId, $roleId);
        return $stmt->execute();
    }

    /**
     * Remove role from user
     */
    public function removeRole(int $userId, int $roleId): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM user_roles WHERE user_id = ? AND role_id = ?"
        );
        $stmt->bind_param('ii', $userId, $roleId);
        return $stmt->execute();
    }

    /**
     * Remove all additional roles from user
     */
    private function removeAllRoles(int $userId): void
    {
        $stmt = $this->db->prepare("DELETE FROM user_roles WHERE user_id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
    }

    /**
     * Check if user has role
     */
    public function hasRole(int $userId, string $roleName): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as count FROM users u
             INNER JOIN roles r ON u.role_id = r.id
             WHERE u.id = ? AND r.name = ?
             UNION ALL
             SELECT COUNT(*) as count FROM user_roles ur
             INNER JOIN roles r ON ur.role_id = r.id
             WHERE ur.user_id = ? AND r.name = ?"
        );
        $stmt->bind_param('isii', $userId, $roleName, $userId, $roleName);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $total = 0;
        while ($row = $result->fetch_assoc()) {
            $total += (int)$row['count'];
        }
        
        return $total > 0;
    }

    /**
     * Validate user data
     */
    public function validate(array $data, bool $isUpdate = false): array
    {
        $errors = [];

        if (!$isUpdate || isset($data['username'])) {
            if (empty($data['username'])) {
                $errors['username'] = 'Username is required';
            } elseif (strlen($data['username']) < 3 || strlen($data['username']) > 50) {
                $errors['username'] = 'Username must be between 3 and 50 characters';
            } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $data['username'])) {
                $errors['username'] = 'Username can only contain letters, numbers, hyphens, and underscores';
            } else {
                // Check uniqueness - exclude current user if updating
                $currentUserId = $isUpdate ? ($data['id'] ?? 0) : 0;
                if ($currentUserId > 0) {
                    $stmt = $this->db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                    $stmt->bind_param('si', $data['username'], $currentUserId);
                } else {
                    $stmt = $this->db->prepare("SELECT id FROM users WHERE username = ?");
                    $stmt->bind_param('s', $data['username']);
                }
                $stmt->execute();
                $result = $stmt->get_result();
                $existing = $result->fetch_assoc();
                
                if ($existing) {
                    $errors['username'] = 'Username already exists';
                }
            }
        }

        if (!$isUpdate || isset($data['email'])) {
            if (empty($data['email'])) {
                $errors['email'] = 'Email is required';
            } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Invalid email format';
            } else {
                // Check uniqueness - exclude current user if updating
                $currentUserId = $isUpdate ? ($data['id'] ?? 0) : 0;
                if ($currentUserId > 0) {
                    $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                    $stmt->bind_param('si', $data['email'], $currentUserId);
                } else {
                    $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ?");
                    $stmt->bind_param('s', $data['email']);
                }
                $stmt->execute();
                $result = $stmt->get_result();
                $existing = $result->fetch_assoc();
                
                if ($existing) {
                    $errors['email'] = 'Email already exists';
                }
            }
        }

        if (!$isUpdate || isset($data['password'])) {
            if (!$isUpdate && empty($data['password'])) {
                $errors['password'] = 'Password is required';
            } elseif (!empty($data['password'])) {
                if (strlen($data['password']) < 8) {
                    $errors['password'] = 'Password must be at least 8 characters';
                }
            }
        }

        return $errors;
    }

    /**
     * Get all roles
     */
    public function getAllRoles(): array
    {
        $result = $this->db->query(
            "SELECT id, name, display_name, description FROM roles ORDER BY name"
        );

        if (!$result) {
            return [];
        }

        $roles = [];
        while ($row = $result->fetch_assoc()) {
            $roles[] = $row;
        }

        return $roles;
    }
}


