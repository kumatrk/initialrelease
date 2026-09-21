<?php

declare(strict_types=1);

namespace SimpleKuma\Auth;

use mysqli;

/**
 * Permission System
 * Handles role-based access control and permission checking
 */
class Permission
{
    private mysqli $db;
    private array $userRoles = [];
    private int $userId;

    // Permission constants
    public const PERM_USER_MANAGE = 'user.manage';
    public const PERM_CAMPAIGN_VIEW = 'campaign.view';
    public const PERM_CAMPAIGN_CREATE = 'campaign.create';
    public const PERM_CAMPAIGN_EDIT = 'campaign.edit';
    public const PERM_CAMPAIGN_DELETE = 'campaign.delete';
    public const PERM_TRAFFIC_SOURCE_VIEW = 'traffic_source.view';
    public const PERM_TRAFFIC_SOURCE_MANAGE = 'traffic_source.manage';
    public const PERM_OFFER_VIEW = 'offer.view';
    public const PERM_OFFER_MANAGE = 'offer.manage';
    public const PERM_LANDING_PAGE_VIEW = 'landing_page.view';
    public const PERM_LANDING_PAGE_MANAGE = 'landing_page.manage';
    public const PERM_NETWORK_VIEW = 'network.view';
    public const PERM_NETWORK_MANAGE = 'network.manage';
    public const PERM_POSTBACK_VIEW = 'postback.view';
    public const PERM_POSTBACK_MANAGE = 'postback.manage';
    public const PERM_SETTINGS_VIEW = 'settings.view';
    public const PERM_SETTINGS_EDIT = 'settings.edit';
    public const PERM_DASHBOARD_VIEW = 'dashboard.view';
    public const PERM_STATS_VIEW = 'stats.view';
    public const PERM_VISITOR_LOG_VIEW = 'visitor_log.view';
    public const PERM_BILLING_VIEW = 'billing.view';
    public const PERM_UPDATE_MANAGE = 'update.manage';

    // Role permissions mapping
    private const ROLE_PERMISSIONS = [
        'admin' => [
            self::PERM_USER_MANAGE,
            self::PERM_CAMPAIGN_VIEW,
            self::PERM_CAMPAIGN_CREATE,
            self::PERM_CAMPAIGN_EDIT,
            self::PERM_CAMPAIGN_DELETE,
            self::PERM_TRAFFIC_SOURCE_VIEW,
            self::PERM_TRAFFIC_SOURCE_MANAGE,
            self::PERM_OFFER_VIEW,
            self::PERM_OFFER_MANAGE,
            self::PERM_LANDING_PAGE_VIEW,
            self::PERM_LANDING_PAGE_MANAGE,
            self::PERM_NETWORK_VIEW,
            self::PERM_NETWORK_MANAGE,
            self::PERM_POSTBACK_VIEW,
            self::PERM_POSTBACK_MANAGE,
            self::PERM_SETTINGS_VIEW,
            self::PERM_SETTINGS_EDIT,
            self::PERM_DASHBOARD_VIEW,
            self::PERM_STATS_VIEW,
            self::PERM_VISITOR_LOG_VIEW,
            self::PERM_BILLING_VIEW,
            self::PERM_UPDATE_MANAGE,
        ],
        'manager' => [
            self::PERM_CAMPAIGN_VIEW,
            self::PERM_CAMPAIGN_CREATE,
            self::PERM_CAMPAIGN_EDIT,
            self::PERM_CAMPAIGN_DELETE,
            self::PERM_TRAFFIC_SOURCE_VIEW,
            self::PERM_TRAFFIC_SOURCE_MANAGE,
            self::PERM_OFFER_VIEW,
            self::PERM_OFFER_MANAGE,
            self::PERM_LANDING_PAGE_VIEW,
            self::PERM_LANDING_PAGE_MANAGE,
            self::PERM_NETWORK_VIEW,
            self::PERM_NETWORK_MANAGE,
            self::PERM_POSTBACK_VIEW,
            self::PERM_POSTBACK_MANAGE,
            self::PERM_SETTINGS_VIEW,
            self::PERM_SETTINGS_EDIT,
            self::PERM_DASHBOARD_VIEW,
            self::PERM_STATS_VIEW,
            self::PERM_VISITOR_LOG_VIEW,
            self::PERM_BILLING_VIEW,
        ],
        'billing' => [
            self::PERM_DASHBOARD_VIEW,
            self::PERM_BILLING_VIEW,
            self::PERM_SETTINGS_VIEW,
        ],
        'viewer' => [
            self::PERM_DASHBOARD_VIEW,
            self::PERM_CAMPAIGN_VIEW,
            self::PERM_STATS_VIEW,
            self::PERM_VISITOR_LOG_VIEW,
        ],
    ];

    public function __construct(mysqli $db, int $userId)
    {
        $this->db = $db;
        $this->userId = $userId;
        $this->loadUserRoles();
    }

    /**
     * Load user roles from database
     */
    private function loadUserRoles(): void
    {
        // Get primary role from users table
        $stmt = $this->db->prepare(
            "SELECT r.name FROM users u
             INNER JOIN roles r ON u.role_id = r.id
             WHERE u.id = ? AND u.is_active = 1"
        );
        $stmt->bind_param('i', $this->userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        if ($row) {
            $this->userRoles[] = $row['name'];
        }

        // Get additional roles from user_roles table
        $stmt = $this->db->prepare(
            "SELECT r.name FROM user_roles ur
             INNER JOIN roles r ON ur.role_id = r.id
             WHERE ur.user_id = ?"
        );
        $stmt->bind_param('i', $this->userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            if (!in_array($row['name'], $this->userRoles, true)) {
                $this->userRoles[] = $row['name'];
            }
        }
    }

    /**
     * Check if user has a specific permission
     */
    public function hasPermission(string $permission): bool
    {
        if (SingleAdminMode::isEnabled()) {
            return true;
        }

        foreach ($this->userRoles as $roleName) {
            if (isset(self::ROLE_PERMISSIONS[$roleName]) && 
                in_array($permission, self::ROLE_PERMISSIONS[$roleName], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if user has any of the specified permissions
     */
    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if user has all of the specified permissions
     */
    public function hasAllPermissions(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->hasPermission($permission)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check if user has a specific role
     */
    public function hasRole(string $roleName): bool
    {
        if (SingleAdminMode::isEnabled()) {
            return $roleName === 'admin';
        }

        return in_array($roleName, $this->userRoles, true);
    }

    /**
     * Check if user has any of the specified roles
     */
    public function hasAnyRole(array $roleNames): bool
    {
        foreach ($roleNames as $roleName) {
            if ($this->hasRole($roleName)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get all user roles
     */
    public function getRoles(): array
    {
        return $this->userRoles;
    }

    /**
     * Get all permissions for the user
     */
    public function getPermissions(): array
    {
        $permissions = [];
        foreach ($this->userRoles as $roleName) {
            if (isset(self::ROLE_PERMISSIONS[$roleName])) {
                $permissions = array_merge($permissions, self::ROLE_PERMISSIONS[$roleName]);
            }
        }
        return array_unique($permissions);
    }

    /**
     * Require permission or throw exception
     */
    public function requirePermission(string $permission): void
    {
        if (!$this->hasPermission($permission)) {
            http_response_code(403);
            throw new \RuntimeException("Access denied: Missing permission '{$permission}'");
        }
    }

    /**
     * Require role or throw exception
     */
    public function requireRole(string $roleName): void
    {
        if (!$this->hasRole($roleName)) {
            http_response_code(403);
            throw new \RuntimeException("Access denied: Missing role '{$roleName}'");
        }
    }

    /**
     * Check if user can access a resource
     */
    public function canAccess(string $resource): bool
    {
        // Map resources to permissions
        $resourcePermissions = [
            'users' => self::PERM_USER_MANAGE,
            'campaigns' => self::PERM_CAMPAIGN_VIEW,
            'traffic-sources' => self::PERM_TRAFFIC_SOURCE_VIEW,
            'offers' => self::PERM_OFFER_VIEW,
            'landing-pages' => self::PERM_LANDING_PAGE_VIEW,
            'networks' => self::PERM_NETWORK_VIEW,
            'postbacks' => self::PERM_POSTBACK_VIEW,
            'settings' => self::PERM_SETTINGS_VIEW,
            'dashboard' => self::PERM_DASHBOARD_VIEW,
            'stats' => self::PERM_STATS_VIEW,
            'visitors' => self::PERM_VISITOR_LOG_VIEW,
            'billing' => self::PERM_BILLING_VIEW,
        ];

        if (isset($resourcePermissions[$resource])) {
            return $this->hasPermission($resourcePermissions[$resource]);
        }

        return false;
    }
}


