<?php

declare(strict_types=1);

namespace SimpleKuma\Installer;

/**
 * Config Writer
 * Generates and writes secure config.php file
 */
class ConfigWriter
{
    private string $configPath;
    private array $errors = [];

    public function __construct(?string $configPath = null)
    {
        $this->configPath = $configPath ?? dirname(__DIR__, 2) . '/config/config.php';
    }

    /**
     * Write config file
     */
    public function write(array $dbConfig, array $siteConfig): bool
    {
        $this->errors = [];

        // Check if config directory is writable
        $configDir = dirname($this->configPath);
        if (!is_writable($configDir)) {
            $this->errors[] = "Config directory '{$configDir}' is not writable";
            return false;
        }

        // Check if config already exists
        // If it exists but is incomplete/invalid, allow overwriting it
        if (file_exists($this->configPath)) {
            // Check if it's a valid, complete config file
            $isValidConfig = $this->isValidConfigFile($this->configPath);
            
            if ($isValidConfig) {
                // Valid config exists - don't overwrite
                $this->errors[] = "Configuration file already exists. Delete config/config.php to reinstall.";
                return false;
            } else {
                // Invalid/incomplete config - delete it and allow writing new one
                @unlink($this->configPath);
            }
        }

        // Generate config content
        $content = $this->generateConfigContent($dbConfig, $siteConfig);

        // Write file
        if (@file_put_contents($this->configPath, $content) === false) {
            $this->errors[] = "Failed to write config file";
            return false;
        }

        // Prefer owner-only; fall back if the web user would lose read access (shared hosts)
        $this->applyConfigPermissions();

        return true;
    }

    /**
     * Secure config.php without locking out the PHP process on panel hosts.
     */
    private function applyConfigPermissions(): void
    {
        if (!is_file($this->configPath) || PHP_OS_FAMILY === 'Windows') {
            return;
        }

        @chmod($this->configPath, 0600);

        // If 0600 made the file unreadable to us, loosen to 0640 then 0644
        if (!is_readable($this->configPath)) {
            @chmod($this->configPath, 0640);
        }
        if (!is_readable($this->configPath)) {
            @chmod($this->configPath, 0644);
        }
    }

    /**
     * Generate config file content
     */
    private function generateConfigContent(array $dbConfig, array $siteConfig): string
    {
        $timestamp = date('Y-m-d H:i:s');
        $appKey = $this->generateAppKey();

        $dbHost = $this->exportValue($dbConfig['host']);
        $dbName = $this->exportValue($dbConfig['name']);
        $dbUser = $this->exportValue($dbConfig['user']);
        $dbPassword = $this->exportValue($dbConfig['password']);
        $baseUrl = $this->exportValue(WebPathResolver::normalizeBaseUrl($siteConfig['base_url']));
        $publicPrefix = $this->exportValue(
            $siteConfig['public_path_suffix'] ?? WebPathResolver::getPublicPathSuffix()
        );
        $appKeyExport = $this->exportValue($appKey);

        return <<<PHP
<?php

/**
 * Simple KUMA Configuration File
 * Generated on: {$timestamp}
 * 
 * WARNING: This file contains sensitive information.
 * Do not commit to version control!
 */

declare(strict_types=1);

// Database Configuration
define('DB_HOST', {$dbHost});
define('DB_NAME', {$dbName});
define('DB_USER', {$dbUser});
define('DB_PASSWORD', {$dbPassword});
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', 'utf8mb4_unicode_ci');

// Site Configuration
define('BASE_URL', {$baseUrl});
// Detected at install from web server layout (empty when docroot = public/, else usually '/public')
define('PUBLIC_WEB_PREFIX', {$publicPrefix});
define('ASSETS_BASE_URL', rtrim(BASE_URL, '/') . PUBLIC_WEB_PREFIX);
define('APP_BASE_URL', rtrim(BASE_URL, '/') . PUBLIC_WEB_PREFIX);
define('APP_KEY', {$appKeyExport});
define('APP_ENV', 'production');
define('APP_DEBUG', false);
define('SINGLE_ADMIN_MODE', true);

// Click entry URL — query style (go.php?k=) for new installs; legacy /km/ links keep working via .htaccess
define('CLICK_URL_STYLE', 'query');
define('CLICK_ENTRY_SCRIPT', 'go.php');
define('CLICK_PATH_PREFIX', 'go');

// Timezone Configuration
define('APP_TIMEZONE', 'UTC');

// Session Configuration
define('SESSION_LIFETIME', 7200); // 2 hours
define('SESSION_COOKIE_HTTPONLY', true);
define('SESSION_COOKIE_SECURE', true); // HTTPS required — login will not work over plain HTTP

// Security Configuration (threads must stay 1 on libsodium PHP builds)
define('HASH_ALGO', defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
define('HASH_OPTIONS', {$this->exportHashOptions()});

// Path Configuration
define('ROOT_PATH', dirname(__DIR__));
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('LOGS_PATH', STORAGE_PATH . '/logs');
define('CACHE_PATH', STORAGE_PATH . '/cache');

// Installation marker (set to true on the final installer step only)
define('INSTALLED', false);
define('INSTALL_DATE', '{$timestamp}');

PHP;
    }

    /**
     * Generate a secure random application key
     */
    private function generateAppKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Host-safe Argon2 options as a PHP array literal for config.php.
     */
    private function exportHashOptions(): string
    {
        return var_export(\SimpleKuma\Auth\PasswordHasher::preferredOptions(), true);
    }

    /**
     * Safe PHP literal for generated config values.
     */
    private function exportValue(string $value): string
    {
        return var_export($value, true);
    }

    /**
     * Check if config file already exists (any file on disk).
     */
    public function configExists(): bool
    {
        return file_exists($this->configPath);
    }

    /**
     * Check if config file is complete and marked installed.
     */
    public function hasValidConfig(): bool
    {
        return $this->isValidConfigFile($this->configPath);
    }

    /**
     * Get errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get config path
     */
    public function getConfigPath(): string
    {
        return $this->configPath;
    }

    /**
     * Mark installation finished (after migrations + admin user).
     */
    public function markInstallationComplete(): bool
    {
        if (!is_file($this->configPath)) {
            $this->errors[] = 'Configuration file not found';

            return false;
        }

        $content = @file_get_contents($this->configPath);
        if ($content === false || $content === '') {
            $this->errors[] = 'Could not read configuration file';

            return false;
        }

        $updated = preg_replace(
            "/define\s*\(\s*['\"]INSTALLED['\"]\s*,\s*false\s*\)/",
            "define('INSTALLED', true)",
            $content,
            1,
            $count
        );

        if ($count === 0) {
            if (preg_match("/define\s*\(\s*['\"]INSTALLED['\"]\s*,\s*true\s*\)/", $content)) {
                return true;
            }

            $this->errors[] = 'Could not update INSTALLED flag in config.php';

            return false;
        }

        if (@file_put_contents($this->configPath, $updated) === false) {
            $this->errors[] = 'Failed to write config file';

            return false;
        }

        return true;
    }

    /**
     * Check if existing config file is valid and complete
     */
    private function isValidConfigFile(string $configPath): bool
    {
        if (!file_exists($configPath)) {
            return false;
        }

        // Read config file
        $content = @file_get_contents($configPath);
        if ($content === false || empty($content)) {
            return false;
        }

        // Check if it has all required constants
        $requiredConstants = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'BASE_URL', 'APP_KEY'];
        $hasAllConstants = true;
        
        foreach ($requiredConstants as $constant) {
            if (strpos($content, "define('{$constant}'") === false && strpos($content, "define(\"{$constant}\"") === false) {
                $hasAllConstants = false;
                break;
            }
        }

        // Also check file size - a valid config should be at least 500 bytes
        $fileSize = filesize($configPath);
        if ($fileSize < 500) {
            return false;
        }

        // Try to actually load it and verify constants are defined
        try {
            // Temporarily include the config to check if constants are defined
            // We'll use a test to see if it's valid without actually defining them
            if ($hasAllConstants && $fileSize >= 500) {
                // Check if it has INSTALLED marker (indicates it was properly installed)
                if (strpos($content, "define('INSTALLED'") !== false || strpos($content, 'define("INSTALLED"') !== false) {
                    return true;
                }
            }
        } catch (Exception $e) {
            // If we can't parse it, it's invalid
            return false;
        }

        return $hasAllConstants && $fileSize >= 500;
    }
}


