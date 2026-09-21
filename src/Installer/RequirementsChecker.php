<?php

declare(strict_types=1);

namespace SimpleKuma\Installer;

/**
 * Requirements Checker
 * Verifies PHP version, extensions, and file permissions
 */
class RequirementsChecker
{
    private array $errors = [];
    private array $warnings = [];
    private array $passed = [];
    private bool $permissionsFixed = false;

    /**
     * Required PHP extensions
     */
    private const REQUIRED_EXTENSIONS = [
        'mysqli',
        'pdo',
        'pdo_mysql',
        'json',
        'mbstring',
        'curl',
        'openssl',
        'fileinfo',
        'filter',
    ];

    /**
     * Recommended PHP extensions
     */
    private const RECOMMENDED_EXTENSIONS = [
        'gd',
        'zip',
        'intl',
    ];

    /**
     * Directories that need write permission
     */
    private const WRITABLE_DIRECTORIES = [
        'config',
        'storage',
        'storage/logs',
        'storage/cache',
    ];

    /**
     * Run all requirement checks
     */
    public function check(): bool
    {
        $this->checkPhpVersion();
        $this->checkRequiredExtensions();
        $this->checkRecommendedExtensions();
        $this->checkPasswordHashing();
        $this->checkFilePermissions();
        $this->checkPhpSettings();
        $this->checkCommandExecution(); // Check exec() BEFORE composer dependencies
        $this->checkComposerDependencies();

        return empty($this->errors);
    }

    /**
     * Catch Argon2/libsodium host quirks before admin account creation.
     */
    private function checkPasswordHashing(): void
    {
        $probeError = \SimpleKuma\Auth\PasswordHasher::probe();
        if ($probeError !== null) {
            $this->errors[] = $probeError;
            return;
        }

        $backend = \SimpleKuma\Auth\PasswordHasher::supportsParallelThreads()
            ? 'libargon2'
            : 'libsodium (threads limited to 1)';
        $this->passed[] = "Password hashing works (Argon2id / {$backend})";
    }

    /**
     * Check if command execution (exec/shell_exec) is enabled
     * NOTE: Simple KUMA ships with vendor folder pre-packaged, so exec() is NOT needed
     * This check is informational only - vendor folder should always be included
     */
    private function checkCommandExecution(): void
    {
        $basePath = dirname(__DIR__, 2);
        $autoloadPath = $basePath . '/vendor/autoload.php';
        
        // If vendor folder exists (pre-packaged), exec() is not required - skip check entirely
        if (file_exists($autoloadPath)) {
            // Vendor is pre-packaged - no need to check exec()
            return;
        }
        
        // Only check exec() if vendor folder is missing (shouldn't happen in normal install)
        // This is just informational - user should re-upload package with vendor folder
        $disabledFunctions = explode(',', ini_get('disable_functions'));
        $disabledFunctions = array_map('trim', $disabledFunctions);
        $disabledFunctions = array_map('strtolower', $disabledFunctions);
        
        $execDisabled = in_array('exec', $disabledFunctions);
        $shellExecDisabled = in_array('shell_exec', $disabledFunctions);
        $passthruDisabled = in_array('passthru', $disabledFunctions);
        
        // This is just informational - vendor should be pre-packaged
        if ($execDisabled && $shellExecDisabled && $passthruDisabled) {
            // All disabled - but this shouldn't matter since vendor should be pre-packaged
            // Don't add warning - vendor missing is already an error
        }
    }

    /**
     * Check PHP version (8.2+)
     */
    private function checkPhpVersion(): void
    {
        $currentVersion = PHP_VERSION;
        $requiredVersion = '8.2.0';

        if (version_compare($currentVersion, $requiredVersion, '>=')) {
            $this->passed[] = "PHP version {$currentVersion} meets requirement (>= {$requiredVersion})";
        } else {
            $this->errors[] = "PHP version {$currentVersion} is too old. Required: {$requiredVersion} or higher";
        }
    }

    /**
     * Check required PHP extensions
     */
    private function checkRequiredExtensions(): void
    {
        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            if (extension_loaded($extension)) {
                $this->passed[] = "Extension '{$extension}' is loaded";
            } else {
                $this->errors[] = "Required extension '{$extension}' is not loaded";
            }
        }
    }

    /**
     * Check recommended PHP extensions
     */
    private function checkRecommendedExtensions(): void
    {
        foreach (self::RECOMMENDED_EXTENSIONS as $extension) {
            if (extension_loaded($extension)) {
                $this->passed[] = "Recommended extension '{$extension}' is loaded";
            } else {
                $this->warnings[] = "Recommended extension '{$extension}' is not loaded (optional but recommended)";
            }
        }
    }

    /**
     * Check file and directory permissions
     */
    private function checkFilePermissions(): void
    {
        $basePath = dirname(__DIR__, 2);

        // Create directories if they don't exist
        foreach (self::WRITABLE_DIRECTORIES as $dir) {
            $fullPath = $basePath . DIRECTORY_SEPARATOR . $dir;

            // Try to create directory if it doesn't exist
            if (!is_dir($fullPath)) {
                if (!@mkdir($fullPath, 0755, true)) {
                    $this->errors[] = "Cannot create directory '{$dir}'";
                    continue;
                }
            }

            // Check if directory is writable
            if (is_writable($fullPath)) {
                $this->passed[] = "Directory '{$dir}' is writable";
            } else {
                $this->errors[] = "Directory '{$dir}' is not writable. Please set permissions to 755 or higher";
            }
        }
    }

    /**
     * Check important PHP settings
     */
    private function checkPhpSettings(): void
    {
        // Check memory limit
        $memoryLimit = ini_get('memory_limit');
        $memoryBytes = $this->convertToBytes($memoryLimit);

        if ($memoryBytes === -1 || $memoryBytes >= 128 * 1024 * 1024) {
            $this->passed[] = "Memory limit ({$memoryLimit}) is adequate";
        } elseif ($memoryBytes >= 64 * 1024 * 1024) {
            $this->warnings[] = "Memory limit ({$memoryLimit}) is low. Recommended: 128M or higher (Argon2 password hashing uses ~64MB)";
        } else {
            $this->errors[] = "Memory limit ({$memoryLimit}) is too low for installation. Set memory_limit to at least 64M (128M recommended)";
        }

        // Check max execution time
        $maxExecutionTime = (int)ini_get('max_execution_time');
        if ($maxExecutionTime === 0 || $maxExecutionTime >= 30) {
            $this->passed[] = "Max execution time ({$maxExecutionTime}s) is adequate";
        } else {
            $this->warnings[] = "Max execution time ({$maxExecutionTime}s) is low. Recommended: 30s or higher";
        }

        // Check file uploads
        if (ini_get('file_uploads')) {
            $this->passed[] = "File uploads are enabled";
        } else {
            $this->warnings[] = "File uploads are disabled (may be needed for some features)";
        }
    }

    /**
     * Convert PHP memory limit to bytes
     */
    private function convertToBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '-1') {
            return -1;
        }

        $last = strtolower($value[strlen($value) - 1]);
        $value = (int)$value;

        switch ($last) {
            case 'g':
                $value *= 1024;
                // no break
            case 'm':
                $value *= 1024;
                // no break
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    /**
     * Get all errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get all warnings
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Get all passed checks
     */
    public function getPassed(): array
    {
        return $this->passed;
    }

    /**
     * Check if Composer dependencies are installed
     * Priority: Check for existing vendor folder first (pre-packaged)
     * Only requires Composer install if vendor is missing
     */
    private function checkComposerDependencies(): void
    {
        $basePath = dirname(__DIR__, 2);
        $autoloadPath = $basePath . '/vendor/autoload.php';
        $composerJsonPath = $basePath . '/composer.json';

        // Check if composer.json exists
        if (!file_exists($composerJsonPath)) {
            // No composer.json means no dependencies needed
            return;
        }

        // PRIORITY: Check if vendor/autoload.php exists (pre-packaged)
        if (file_exists($autoloadPath)) {
            // Load autoloader if not already loaded (fix Unix permissions only on failure)
            if (!class_exists('Composer\Autoload\ClassLoader')) {
                try {
                    VendorPermissionFixer::loadAutoloader($autoloadPath);
                } catch (\Throwable $e) {
                    $this->errors[] = 'Cannot load Composer autoloader. File permissions may be incorrect. Error: ' . $e->getMessage();
                    return;
                }
            }

            // Check if critical dependencies are available
            $criticalDeps = [
                'matomo/device-detector' => 'DeviceDetector\\DeviceDetector',
                'phpmailer/phpmailer' => 'PHPMailer\\PHPMailer\\PHPMailer',
                'geoip2/geoip2' => 'GeoIp2\\Database\\Reader',
                'jaybizzle/crawler-detect' => 'Jaybizzle\\CrawlerDetect\\CrawlerDetect',
            ];

            $allPresent = true;
            foreach ($criticalDeps as $package => $class) {
                if (class_exists($class)) {
                    $this->passed[] = "Composer dependency '{$package}' is installed";
                } else {
                    $this->warnings[] = "Composer dependency '{$package}' may not be properly installed";
                    $allPresent = false;
                }
            }

            if ($allPresent) {
                $this->passed[] = "All dependencies are installed (vendor folder present)";
            }
        } else {
            // vendor/autoload.php doesn't exist - this is an error since vendor should be pre-packaged
            $this->errors[] = "Dependencies (vendor folder) not found. Simple KUMA ships with the vendor folder pre-packaged. Please ensure you uploaded the complete package.";
        }
    }

    /**
     * Get all check results
     */
    public function getResults(): array
    {
        return [
            'passed' => $this->passed,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'canProceed' => empty($this->errors),
            'permissionsFixed' => $this->permissionsFixed,
        ];
    }
}


