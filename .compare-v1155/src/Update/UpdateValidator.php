<?php

declare(strict_types=1);

namespace SimpleKuma\Update;

use mysqli;

/**
 * Update Validator
 * Handles security validation for update operations
 */
class UpdateValidator
{
    // Allowed directories for updates (relative to project root)
    private const ALLOWED_DIRECTORIES = [
        'src',
        'views',
        'public',
        'database/migrations'
    ];

    // Core files that require explicit user approval
    private const PROTECTED_FILES = [
        'config.php',
        '.env',
        'version.php'
    ];

    // Forbidden SQL operations in migrations
    private const FORBIDDEN_SQL_PATTERNS = [
        '/DROP\s+TABLE/i',
        '/DROP\s+COLUMN/i',
        '/ALTER\s+TABLE.*DROP/i',
        '/DELETE\s+FROM/i',
        '/TRUNCATE\s+TABLE/i'
    ];

    /**
     * Validate file path to prevent directory traversal
     */
    public function validateFilePath(string $path): bool
    {
        // Reject absolute paths
        if (strpos($path, '/') === 0 || preg_match('/^[A-Z]:\\\\/', $path)) {
            return false;
        }

        // Reject directory traversal attempts
        if (strpos($path, '..') !== false) {
            return false;
        }

        // Normalize path separators
        $normalized = str_replace('\\', '/', $path);
        
        // Check if path is within allowed directories
        $pathParts = explode('/', $normalized);
        if (empty($pathParts)) {
            return false;
        }

        $firstDir = $pathParts[0];
        return in_array($firstDir, self::ALLOWED_DIRECTORIES, true);
    }

    /**
     * Check if file requires explicit user approval
     */
    public function requiresApproval(string $path): bool
    {
        $filename = basename($path);
        return in_array($filename, self::PROTECTED_FILES, true);
    }

    /**
     * Verify SHA256 hash of a file
     */
    public function verifyFileHash(string $filePath, string $expectedHash): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        // Extract hash from format "sha256:hexdigest"
        if (strpos($expectedHash, 'sha256:') === 0) {
            $expectedHash = substr($expectedHash, 7);
        }

        $actualHash = hash_file('sha256', $filePath);
        
        // Use hash_equals to prevent timing attacks
        return hash_equals($expectedHash, $actualHash);
    }

    /**
     * Check if file is writable
     */
    public function isFileWritable(string $filePath): bool
    {
        // If file doesn't exist, check if directory is writable
        if (!file_exists($filePath)) {
            $dir = dirname($filePath);
            return is_writable($dir);
        }

        return is_writable($filePath);
    }

    /**
     * Check if directory is writable
     */
    public function isDirectoryWritable(string $dirPath): bool
    {
        if (!is_dir($dirPath)) {
            // Try to create it
            if (!mkdir($dirPath, 0755, true)) {
                return false;
            }
        }

        return is_writable($dirPath);
    }

    /**
     * Validate migration SQL for forbidden operations
     */
    public function validateMigrationSQL(string $sql): array
    {
        $errors = [];

        foreach (self::FORBIDDEN_SQL_PATTERNS as $pattern) {
            if (preg_match($pattern, $sql)) {
                $errors[] = "Migration contains forbidden operation: " . $pattern;
            }
        }

        return $errors;
    }

    /**
     * Check PHP version compatibility
     */
    public function checkPHPVersion(string $requiredMin): bool
    {
        return version_compare(PHP_VERSION, $requiredMin, '>=');
    }

    /**
     * Check MySQL version compatibility
     */
    public function checkMySQLVersion(mysqli $db, string $requiredMin): bool
    {
        $result = $db->query("SELECT VERSION() as version");
        if (!$result) {
            return false;
        }

        $row = $result->fetch_assoc();
        $mysqlVersion = $row['version'] ?? '0.0.0';
        
        // Extract version number (handle MariaDB format)
        $mysqlVersion = preg_replace('/[^0-9.]/', '', $mysqlVersion);
        
        return version_compare($mysqlVersion, $requiredMin, '>=');
    }

    /**
     * Check available disk space
     */
    public function checkDiskSpace(string $path, int $requiredBytes): bool
    {
        $freeBytes = disk_free_space($path);
        if ($freeBytes === false) {
            return false;
        }

        // Require at least 2x the update size for safety
        return $freeBytes >= ($requiredBytes * 2);
    }

    /**
     * Validate manifest structure
     */
    public function validateManifest(array $manifest): array
    {
        $errors = [];

        // Required fields
        $required = ['version', 'release_date', 'update_type', 'files'];
        foreach ($required as $field) {
            if (!isset($manifest[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }

        // Validate version format
        if (isset($manifest['version']) && !preg_match('/^\d+\.\d+\.\d+(\.\d+)?$/', $manifest['version'])) {
            $errors[] = "Invalid version format: {$manifest['version']}";
        }

        // Validate update type
        if (isset($manifest['update_type']) && !in_array($manifest['update_type'], ['patch', 'minor', 'major', 'hotfix'], true)) {
            $errors[] = "Invalid update_type: {$manifest['update_type']}";
        }

        // Validate files array
        if (isset($manifest['files']) && is_array($manifest['files'])) {
            foreach ($manifest['files'] as $index => $file) {
                if (!is_array($file)) {
                    $errors[] = "File entry at index {$index} is not an array";
                    continue;
                }

                $fileRequired = ['path', 'hash', 'size', 'category', 'reason'];
                foreach ($fileRequired as $field) {
                    if (!isset($file[$field])) {
                        $errors[] = "File at index {$index} missing required field: {$field}";
                    }
                }

                // Validate file path
                if (isset($file['path']) && !$this->validateFilePath($file['path'])) {
                    $errors[] = "Invalid file path at index {$index}: {$file['path']}";
                }

                // Validate hash format
                if (isset($file['hash']) && !preg_match('/^sha256:[a-f0-9]{64}$/i', $file['hash'])) {
                    $errors[] = "Invalid hash format at index {$index}";
                }

                // Validate category
                if (isset($file['category']) && !in_array($file['category'], ['added', 'changed', 'fixed', 'security'], true)) {
                    $errors[] = "Invalid category at index {$index}: {$file['category']}";
                }
            }
        }

        return $errors;
    }

    /**
     * Check if file has been modified (for conflict detection)
     */
    public function detectFileConflict(string $filePath, string $expectedHash): ?string
    {
        if (!file_exists($filePath)) {
            return null; // New file, no conflict
        }

        if (!$this->verifyFileHash($filePath, $expectedHash)) {
            return 'modified'; // File has been modified
        }

        return null; // File matches expected hash, no conflict
    }
}

