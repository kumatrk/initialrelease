-- Create roles table
CREATE TABLE IF NOT EXISTS roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE COMMENT 'Role name: admin, manager, billing, viewer',
    display_name VARCHAR(100) NOT NULL COMMENT 'Human-readable role name',
    description TEXT NULL COMMENT 'Role description',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert predefined roles
INSERT INTO roles (name, display_name, description) VALUES
('admin', 'Administrator', 'Full access to all features including user management'),
('manager', 'Manager', 'Full access except user management'),
('billing', 'Billing', 'Read-only access to financial data and billing reports'),
('viewer', 'Viewer', 'Read-only access to campaigns and stats, no editing')
ON DUPLICATE KEY UPDATE display_name=VALUES(display_name), description=VALUES(description);

-- Add role_id to users table for primary role
ALTER TABLE users 
ADD COLUMN role_id INT UNSIGNED NULL COMMENT 'Primary role ID' AFTER email,
ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'User active status' AFTER role_id,
ADD INDEX idx_role_id (role_id),
ADD CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL;

-- Create user_roles junction table for many-to-many relationship
CREATE TABLE IF NOT EXISTS user_roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_role (user_id, role_id),
    INDEX idx_user_id (user_id),
    INDEX idx_role_id (role_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create audit_logs table for tracking user actions
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL COMMENT 'User who performed the action (NULL for system actions)',
    action VARCHAR(50) NOT NULL COMMENT 'Action type: login, logout, create, update, delete, role_change, permission_denied',
    resource_type VARCHAR(50) NULL COMMENT 'Resource type: user, campaign, traffic_source, etc.',
    resource_id INT UNSIGNED NULL COMMENT 'Resource ID',
    description TEXT NULL COMMENT 'Action description',
    ip_address VARCHAR(45) NULL COMMENT 'IP address of user',
    user_agent TEXT NULL COMMENT 'User agent string',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_resource (resource_type, resource_id),
    INDEX idx_created_at (created_at),
    CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrate existing users to admin role
UPDATE users u
INNER JOIN roles r ON r.name = 'admin'
SET u.role_id = r.id
WHERE u.role_id IS NULL;

-- Assign existing users to admin role in user_roles table
INSERT INTO user_roles (user_id, role_id)
SELECT u.id, r.id
FROM users u
INNER JOIN roles r ON r.name = 'admin'
WHERE NOT EXISTS (
    SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role_id = r.id
);


