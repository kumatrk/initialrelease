<?php

declare(strict_types=1);

namespace SimpleKuma\Auth;

use mysqli;

/**
 * Authentication System
 * Handles login, logout, session management, and authentication
 */
class Auth
{
    private mysqli $db;
    private const SESSION_LIFETIME = 7200; // 2 hours
    private const REMEMBER_TOKEN_LIFETIME = 2592000; // 30 days

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->initSession();
    }

    /**
     * Initialize secure session
     */
    private function initSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
            session_set_cookie_params([
                'lifetime' => self::SESSION_LIFETIME,
                'path' => '/',
                'domain' => '',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    /**
     * Legacy installs with no roles: full access only outside production.
     */
    public static function allowsLegacyNoRolesFallback(): bool
    {
        if (defined('INSTALLER_DEV_MODE') && INSTALLER_DEV_MODE) {
            return true;
        }
        if (defined('APP_ENV') && APP_ENV !== 'production') {
            return true;
        }
        $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.test')) {
            return true;
        }
        return false;
    }

    /**
     * Login user
     */
    public function login(string $username, string $password, bool $remember = false): array
    {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if ($this->isLoginRateLimited($ipAddress)) {
            return [
                'success' => false,
                'message' => 'Too many login attempts. Please wait before trying again.',
            ];
        }

        // Get user from database (active accounts only)
        $stmt = $this->db->prepare(
            "SELECT id, username, pass_hash, email FROM users WHERE username = ? AND is_active = 1"
        );
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if (!$user) {
            $this->recordFailedLogin($ipAddress, $username);
            return ['success' => false, 'message' => 'Invalid username or password'];
        }

        // Verify password using Argon2ID
        if (!password_verify($password, $user['pass_hash'])) {
            $this->recordFailedLogin($ipAddress, $username);
            return ['success' => false, 'message' => 'Invalid username or password'];
        }

        // Create session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();

        // Load user roles into session
        $this->loadUserRolesIntoSession($user['id']);

        // Update last login
        $this->updateLastLogin($user['id']);

        // Log login
        $auditLogger = new \SimpleKuma\Auth\AuditLogger($this->db);
        $auditLogger->logLogin($user['id']);

        // Handle remember me
        if ($remember) {
            $this->createRememberToken($user['id']);
        }

        session_regenerate_id(true);

        return ['success' => true, 'message' => 'Login successful'];
    }

    /**
     * Logout user
     */
    public function logout(): void
    {
        // Log logout before clearing session
        if (!empty($_SESSION['user_id'])) {
            $auditLogger = new \SimpleKuma\Auth\AuditLogger($this->db);
            $auditLogger->logLogout($_SESSION['user_id']);
        }
        
        // Clear remember token if exists
        if (isset($_COOKIE['remember_token'])) {
            $this->clearRememberToken($_COOKIE['remember_token']);
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
            setcookie('remember_token', '', [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        // Clear session
        $_SESSION = [];
        
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }

    /**
     * Check if user is authenticated
     */
    public function isAuthenticated(): bool
    {
        // Check session
        if (!empty($_SESSION['logged_in']) && !empty($_SESSION['user_id'])) {
            // Check session timeout
            $loginTime = $_SESSION['login_time'] ?? 0;
            if ((time() - $loginTime) < self::SESSION_LIFETIME) {
                return true;
            }
        }

        // Check remember token
        if (isset($_COOKIE['remember_token'])) {
            return $this->loginFromRememberToken($_COOKIE['remember_token']);
        }

        return false;
    }

    /**
     * Require authentication (middleware)
     */
    public function requireAuth(): void
    {
        if ($this->isAuthenticated()) {
            return;
        }

        $loginUrl = defined('APP_BASE_URL') ? APP_BASE_URL . '/login.php' : 'login.php';

        if ($this->clientExpectsJson()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Robots-Tag: noindex, nofollow', true);
            echo json_encode(['error' => 'Unauthorized', 'login_url' => $loginUrl]);
            exit;
        }

        header('Location: ' . $loginUrl);
        exit;
    }

    /**
     * Release the exclusive PHP session lock after auth/session reads are done.
     * Call on long-running read APIs so other pages (Campaigns list, etc.) are not blocked.
     */
    public function releaseSessionLock(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    private function clientExpectsJson(): bool
    {
        $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
        if (str_contains($accept, 'application/json')) {
            return true;
        }

        return strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', 'XMLHttpRequest') === 0;
    }

    /**
     * Load user roles into session
     */
    private function loadUserRolesIntoSession(int $userId): void
    {
        $roleIds = [];
        $roleNames = [];

        // Get primary role
        $stmt = $this->db->prepare(
            "SELECT r.id, r.name FROM users u
             INNER JOIN roles r ON u.role_id = r.id
             WHERE u.id = ? AND u.is_active = 1"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        if ($row) {
            $roleIds[] = $row['id'];
            $roleNames[] = $row['name'];
        }

        // Get additional roles
        $stmt = $this->db->prepare(
            "SELECT r.id, r.name FROM user_roles ur
             INNER JOIN roles r ON ur.role_id = r.id
             WHERE ur.user_id = ?"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            if (!in_array($row['id'], $roleIds)) {
                $roleIds[] = $row['id'];
                $roleNames[] = $row['name'];
            }
        }

        if (SingleAdminMode::isEnabled()) {
            SingleAdminMode::ensureAdminRoleForUser($this->db, $userId);
            [$roleIds, $roleNames] = SingleAdminMode::adminSessionRoles($this->db);
        }

        $_SESSION['role_ids'] = $roleIds;
        $_SESSION['role_names'] = $roleNames;
    }

    /**
     * Get Permission instance for current user
     */
    public function getPermission(): ?Permission
    {
        if (!$this->isAuthenticated()) {
            return null;
        }

        return new Permission($this->db, $_SESSION['user_id']);
    }

    /**
     * Verify a plaintext password against the stored hash for a user.
     */
    public function verifyPasswordForUser(int $userId, string $plainPassword): bool
    {
        $stmt = $this->db->prepare(
            'SELECT pass_hash FROM users WHERE id = ? AND is_active = 1'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if (!$row || empty($row['pass_hash'])) {
            return false;
        }

        return password_verify($plainPassword, $row['pass_hash']);
    }

    /**
     * Change password for the given user (settings / account flow).
     *
     * @return array{success: bool, message?: string, errors?: array<string, string>}
     */
    public function changePassword(
        int $userId,
        string $currentPassword,
        string $newPassword,
        string $confirmPassword
    ): array {
        $errors = [];

        if ($currentPassword === '') {
            $errors['current_password'] = 'Current password is required';
        } elseif (!$this->verifyPasswordForUser($userId, $currentPassword)) {
            $errors['current_password'] = 'Current password is incorrect';
        }

        if ($newPassword === '') {
            $errors['new_password'] = 'New password is required';
        } elseif (strlen($newPassword) < 8) {
            $errors['new_password'] = 'Password must be at least 8 characters';
        }

        if ($newPassword !== $confirmPassword) {
            $errors['confirm_password'] = 'Passwords do not match';
        }

        if ($newPassword !== '' && $currentPassword !== '' && $newPassword === $currentPassword) {
            $errors['new_password'] = 'New password must be different from your current password';
        }

        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        $passwordHash = PasswordHasher::hash($newPassword);
        $stmt = $this->db->prepare(
            'UPDATE users SET pass_hash = ?, updated_at = NOW() WHERE id = ? AND is_active = 1'
        );
        $stmt->bind_param('si', $passwordHash, $userId);

        if (!$stmt->execute()) {
            return [
                'success' => false,
                'errors' => ['general' => 'Failed to update password'],
            ];
        }

        $this->deleteRememberTokensForUser($userId);

        return [
            'success' => true,
            'message' => 'Password changed successfully',
        ];
    }

    /**
     * Get current user
     */
    public function getCurrentUser(): ?array
    {
        if (!$this->isAuthenticated()) {
            return null;
        }

        $columns = ['id', 'username', 'email', 'timezone', 'currency', 'role_id', 'is_active'];
        foreach (['theme', 'sidebar_collapsed', 'dashboard_charts_hidden'] as $optionalCol) {
            $colCheck = $this->db->query("SHOW COLUMNS FROM users LIKE '{$optionalCol}'");
            if ($colCheck && $colCheck->num_rows > 0) {
                $columns[] = $optionalCol;
            }
        }
        $columnSql = implode(', ', $columns);

        $stmt = $this->db->prepare(
            "SELECT {$columnSql} FROM users WHERE id = ?"
        );
        $stmt->bind_param('i', $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        
        if ($user) {
            // Add role information from session
            $user['role_ids'] = $_SESSION['role_ids'] ?? [];
            $user['role_names'] = $_SESSION['role_names'] ?? [];
        }
        
        return $user;
    }

    /**
     * Update last login timestamp
     */
    private function updateLastLogin(int $userId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE users SET updated_at = NOW() WHERE id = ?"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
    }

    /**
     * Create remember token
     */
    private function createRememberToken(int $userId): void
    {
        if (!$this->rememberTokensTableExists()) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + self::REMEMBER_TOKEN_LIFETIME);

        $this->deleteRememberTokensForUser($userId);

        $stmt = $this->db->prepare(
            'INSERT INTO remember_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)'
        );
        $stmt->bind_param('iss', $userId, $tokenHash, $expiresAt);
        $stmt->execute();

        $this->setRememberCookie($token);
    }

    /**
     * Login from remember token
     */
    private function loginFromRememberToken(string $cookieValue): bool
    {
        if (!$this->rememberTokensTableExists() || $cookieValue === '') {
            return false;
        }

        $tokenHash = hash('sha256', $cookieValue);
        $now = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            'SELECT u.id, u.username, u.email, rt.id AS remember_id
             FROM remember_tokens rt
             INNER JOIN users u ON u.id = rt.user_id
             WHERE rt.token_hash = ? AND rt.expires_at > ? AND u.is_active = 1
             LIMIT 1'
        );
        $stmt->bind_param('ss', $tokenHash, $now);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if (!$user) {
            return false;
        }

        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();

        $this->loadUserRolesIntoSession((int) $user['id']);
        $this->createRememberToken((int) $user['id']);

        return true;
    }

    /**
     * Clear remember token
     */
    private function clearRememberToken(string $cookieValue): void
    {
        if ($cookieValue !== '' && $this->rememberTokensTableExists()) {
            $tokenHash = hash('sha256', $cookieValue);
            $stmt = $this->db->prepare('DELETE FROM remember_tokens WHERE token_hash = ?');
            $stmt->bind_param('s', $tokenHash);
            $stmt->execute();
        }
    }

    private function rememberTokensTableExists(): bool
    {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        $result = $this->db->query("SHOW TABLES LIKE 'remember_tokens'");
        $exists = $result && $result->num_rows > 0;
        return $exists;
    }

    private function deleteRememberTokensForUser(int $userId): void
    {
        $stmt = $this->db->prepare('DELETE FROM remember_tokens WHERE user_id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
    }

    private function setRememberCookie(string $token): void
    {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
        setcookie(
            'remember_token',
            $token,
            [
                'expires' => time() + self::REMEMBER_TOKEN_LIFETIME,
                'path' => '/',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    private function isLoginRateLimited(string $ipAddress): bool
    {
        return $this->countRecentFailedLogins($ipAddress) >= 10;
    }

    private function recordFailedLogin(string $ipAddress, string $username): void
    {
        $auditLogger = new AuditLogger($this->db);
        $auditLogger->log(
            'login_failed',
            'user',
            null,
            'Failed login for username: ' . $username,
            null
        );
    }

    private function countRecentFailedLogins(string $ipAddress): int
    {
        $oneHourAgo = date('Y-m-d H:i:s', time() - 3600);
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS count FROM audit_logs
             WHERE action = 'login_failed' AND ip_address = ? AND created_at > ?"
        );
        $stmt->bind_param('ss', $ipAddress, $oneHourAgo);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        return (int) ($row['count'] ?? 0);
    }

    /**
     * Request password reset
     * Returns ['success' => bool, 'message' => string, 'token' => string|null]
     */
    public function requestPasswordReset(string $email): array
    {
        // Rate limiting: Check for recent requests from this IP
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $recentRequests = $this->getRecentResetRequests($ipAddress);
        
        if ($recentRequests >= 3) {
            return [
                'success' => false,
                'message' => 'Too many reset requests. Please wait before trying again.'
            ];
        }

        // Find user by email
        $stmt = $this->db->prepare(
            "SELECT id, username, email FROM users WHERE email = ?"
        );
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        // Always return success to prevent email enumeration
        // But only generate token if user exists
        if (!$user) {
            return [
                'success' => true,
                'message' => 'If an account exists with that email, a password reset link has been sent.'
            ];
        }

        // Generate secure token
        $token = bin2hex(random_bytes(32)); // 64 character token
        $tokenHash = hash('sha256', $token); // Hash for database storage
        $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour expiry

        // Invalidate any existing tokens for this user
        $this->invalidateUserTokens($user['id']);

        // Store hashed token only (plain token sent via email, never stored)
        $stmt = $this->db->prepare(
            "INSERT INTO password_reset_tokens (user_id, token, token_plain, expires_at, ip_address) 
             VALUES (?, ?, '', ?, ?)"
        );
        $stmt->bind_param('isss', $user['id'], $tokenHash, $expiresAt, $ipAddress);
        
        if (!$stmt->execute()) {
            return [
                'success' => false,
                'message' => 'Failed to generate reset token. Please try again.'
            ];
        }

        return [
            'success' => true,
            'message' => 'If an account exists with that email, a password reset link has been sent.',
            'token' => $token,
            'user' => $user
        ];
    }

    /**
     * Validate reset token
     */
    public function validateResetToken(string $token): ?array
    {
        $now = date('Y-m-d H:i:s');

        $tokenHash = hash('sha256', $token);

        $stmt = $this->db->prepare(
            "SELECT prt.id, prt.user_id, prt.expires_at, prt.used_at, u.email, u.username
             FROM password_reset_tokens prt
             INNER JOIN users u ON prt.user_id = u.id
             WHERE (prt.token = ? OR prt.token_plain = ?) AND prt.expires_at > ? AND prt.used_at IS NULL
             LIMIT 1"
        );
        $stmt->bind_param('sss', $tokenHash, $token, $now);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();

        if (!$data) {
            return null;
        }

        return [
            'token_id' => $data['id'],
            'user_id' => $data['user_id'],
            'email' => $data['email'],
            'username' => $data['username']
        ];
    }

    /**
     * Reset password using token
     */
    public function resetPassword(string $token, string $newPassword): array
    {
        // Validate token
        $tokenData = $this->validateResetToken($token);
        
        if (!$tokenData) {
            return [
                'success' => false,
                'message' => 'Invalid or expired reset token.'
            ];
        }

        // Validate password strength
        if (strlen($newPassword) < 8) {
            return [
                'success' => false,
                'message' => 'Password must be at least 8 characters long.'
            ];
        }

        // Hash new password
        $passwordHash = PasswordHasher::hash($newPassword);

        // Begin transaction
        $this->db->begin_transaction();

        try {
            // Update password
            $stmt = $this->db->prepare(
                "UPDATE users SET pass_hash = ?, updated_at = NOW() WHERE id = ?"
            );
            $stmt->bind_param('si', $passwordHash, $tokenData['user_id']);
            
            if (!$stmt->execute()) {
                throw new \Exception('Failed to update password');
            }

            // Mark token as used
            $stmt = $this->db->prepare(
                "UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?"
            );
            $stmt->bind_param('i', $tokenData['token_id']);
            $stmt->execute();

            // Invalidate all other tokens for this user
            $this->invalidateUserTokens($tokenData['user_id']);

            $this->db->commit();

            return [
                'success' => true,
                'message' => 'Password reset successfully. You can now login with your new password.'
            ];
        } catch (\Exception $e) {
            $this->db->rollback();
            return [
                'success' => false,
                'message' => 'Failed to reset password. Please try again.'
            ];
        }
    }

    /**
     * Get count of recent reset requests from IP
     */
    private function getRecentResetRequests(string $ipAddress): int
    {
        $oneHourAgo = date('Y-m-d H:i:s', time() - 3600);
        
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as count FROM password_reset_tokens 
             WHERE ip_address = ? AND created_at > ?"
        );
        $stmt->bind_param('ss', $ipAddress, $oneHourAgo);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        return (int)($row['count'] ?? 0);
    }

    /**
     * Invalidate all unused tokens for a user
     */
    private function invalidateUserTokens(int $userId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE password_reset_tokens 
             SET used_at = NOW() 
             WHERE user_id = ? AND used_at IS NULL"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
    }
}

