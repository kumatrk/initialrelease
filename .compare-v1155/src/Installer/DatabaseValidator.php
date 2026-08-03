<?php

declare(strict_types=1);

namespace SimpleKuma\Installer;

use mysqli;
use SimpleKuma\Database\DatabaseCompatibility;

/**
 * Database Validator
 * Validates database credentials and tests connection
 */
class DatabaseValidator
{
    private array $errors = [];
    private ?mysqli $connection = null;

    /**
     * Validate database credentials
     */
    public function validate(array $data): bool
    {
        $this->errors = [];

        // Host: blank is accepted (InstallerController defaults blank → localhost before connect)

        // Validate database name (dashes allowed — common on shared hosting)
        if (empty($data['db_name'])) {
            $this->errors['db_name'] = 'Database name is required';
        } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $data['db_name'])) {
            $this->errors['db_name'] = 'Database name can only contain letters, numbers, underscores, and dashes';
        }

        // Validate username
        if (empty($data['db_user'])) {
            $this->errors['db_user'] = 'Database username is required';
        }

        // Password can be empty (for development)

        // Validate base URL (site root for tracking — not /public)
        if (empty($data['base_url'])) {
            $this->errors['base_url'] = 'Base URL is required';
        } else {
            $normalized = WebPathResolver::normalizeBaseUrl($data['base_url']);
            if (!filter_var($normalized, FILTER_VALIDATE_URL)) {
                $this->errors['base_url'] = 'Base URL must be a valid URL (e.g., https://track.example.com or https://example.com/folder)';
            } elseif (!str_starts_with(strtolower($normalized), 'https://')) {
                $this->errors['base_url'] = 'Base URL must use HTTPS (https://). Simple KUMA requires SSL for secure sessions and tracking.';
            } elseif (
                WebPathResolver::baseUrlSharesAdminHost($normalized)
                && empty($data['base_url_same_host_ack'])
            ) {
                $this->errors['base_url'] = 'Base URL uses the same host as your admin dashboard ('
                    . (WebPathResolver::extractHost($normalized) ?? 'this domain')
                    . '). Use a dedicated tracking subdomain (e.g. https://track.example.com) so click links '
                    . 'do not run on the same host as login and campaigns — this reduces false Google Safe Browsing flags. '
                    . 'Check the confirmation box below only if you must proceed on one host for now.';
            }
        }

        $pathSuffix = $data['public_path_suffix'] ?? '';
        if (!WebPathResolver::isValidPublicPathSuffix($pathSuffix)) {
            $this->errors['public_path_suffix'] = 'Path prefix must be empty or a path like /public (letters, numbers, slashes, underscores only)';
        }

        // Validate admin username
        if (empty($data['admin_username'])) {
            $this->errors['admin_username'] = 'Admin username is required';
        } elseif (strlen($data['admin_username']) < 3) {
            $this->errors['admin_username'] = 'Admin username must be at least 3 characters';
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $data['admin_username'])) {
            $this->errors['admin_username'] = 'Admin username can only contain letters, numbers, and underscores';
        }

        // Validate admin password
        if (empty($data['admin_password'])) {
            $this->errors['admin_password'] = 'Admin password is required';
        } elseif (strlen($data['admin_password']) < 8) {
            $this->errors['admin_password'] = 'Admin password must be at least 8 characters';
        }

        // Validate password confirmation
        if (empty($data['admin_password_confirm'])) {
            $this->errors['admin_password_confirm'] = 'Please confirm the admin password';
        } elseif ($data['admin_password'] !== $data['admin_password_confirm']) {
            $this->errors['admin_password_confirm'] = 'Passwords do not match';
        }

        // Validate admin email
        if (empty($data['admin_email'])) {
            $this->errors['admin_email'] = 'Admin email is required';
        } elseif (!filter_var($data['admin_email'], FILTER_VALIDATE_EMAIL)) {
            $this->errors['admin_email'] = 'Admin email must be a valid email address';
        }

        return empty($this->errors);
    }

    /**
     * Test database connection
     */
    public function testConnection(string $host, string $user, string $password, string $database): bool
    {
        // Suppress mysqli errors and handle them manually
        mysqli_report(MYSQLI_REPORT_OFF);

        $this->connection = @new mysqli($host, $user, $password, $database);

        if ($this->connection->connect_error) {
            $this->errors['connection'] = 'Database connection failed: ' . $this->connection->connect_error;
            return false;
        }

        $versionError = DatabaseCompatibility::validateConnection($this->connection);
        if ($versionError !== null) {
            $this->errors['connection'] = $versionError . ' ' . DatabaseCompatibility::getRequirementLabel() . '.';
            $this->discardConnection();
            return false;
        }

        if (!$this->checkDatabasePrivileges($this->connection)) {
            $this->discardConnection();
            return false;
        }

        return true;
    }

    /**
     * Verify the DB user can create schema objects (migrations need DDL + views).
     */
    private function checkDatabasePrivileges(\mysqli $connection): bool
    {
        $table = '_sk_install_priv_test_' . bin2hex(random_bytes(4));
        $view = $table . '_v';

        if (!$connection->query("CREATE TABLE `{$table}` (id INT PRIMARY KEY)")) {
            $this->errors['connection'] = 'Database user lacks CREATE privilege. '
                . 'Migrations require CREATE/ALTER on tables. Grant DDL rights or use a user with full access to this database. '
                . 'Server message: ' . $connection->error;

            return false;
        }

        $altered = $connection->query("ALTER TABLE `{$table}` ADD COLUMN probe TINYINT NULL");
        if (!$altered) {
            $connection->query("DROP TABLE IF EXISTS `{$table}`");
            $this->errors['connection'] = 'Database user lacks ALTER privilege. '
                . 'Migrations require ALTER TABLE. Grant DDL rights or use a user with full access to this database. '
                . 'Server message: ' . $connection->error;

            return false;
        }

        $viewOk = $connection->query("CREATE VIEW `{$view}` AS SELECT id FROM `{$table}`");
        $connection->query("DROP VIEW IF EXISTS `{$view}`");
        $connection->query("DROP TABLE IF EXISTS `{$table}`");

        if (!$viewOk) {
            $this->errors['connection'] = 'Database user lacks CREATE VIEW privilege. '
                . 'Migrations create views (e.g. clicks_unified). Grant CREATE VIEW or use a user with full access to this database. '
                . 'Server message: ' . $connection->error;

            return false;
        }

        return true;
    }

    /**
     * Get validation errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get specific error
     */
    public function getError(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }

    /**
     * Close database connection
     */
    public function closeConnection(): void
    {
        $this->discardConnection();
    }

    /**
     * Close and clear the connection handle so a later closeConnection() is a no-op.
     * Calling mysqli::close() twice throws "mysqli object is already closed" on PHP 8.2+.
     */
    private function discardConnection(): void
    {
        if ($this->connection === null) {
            return;
        }

        try {
            $this->connection->close();
        } catch (\Throwable) {
            // Already closed or otherwise unusable — ignore so the real install error is kept.
        }

        $this->connection = null;
    }

    /**
     * Get connection for testing
     */
    public function getConnection(): ?mysqli
    {
        return $this->connection;
    }
}


