<?php

declare(strict_types=1);

namespace SimpleKuma\Installer;

/**
 * Detects installed state and controls installer access / removal policy.
 */
class InstallerLock
{
    public static function getConfigPath(?string $projectRoot = null): string
    {
        $root = $projectRoot ?? dirname(__DIR__, 2);

        return $root . '/config/config.php';
    }

    public static function getInstallerPath(?string $projectRoot = null): string
    {
        $root = $projectRoot ?? dirname(__DIR__, 2);

        return $root . '/public/install.php';
    }

    /**
     * True when config exists, is marked INSTALLED, and database connects.
     */
    public static function isInstalledAndHealthy(?string $projectRoot = null): bool
    {
        $configPath = self::getConfigPath($projectRoot);

        if (!is_file($configPath)) {
            return false;
        }

        $writer = new ConfigWriter($configPath);
        if (!$writer->hasValidConfig()) {
            return false;
        }

        return self::canConnectFromConfig($configPath);
    }

    /**
     * Local / dev hosts may access installer even when installed (for retesting).
     */
    public static function isLocalHost(): bool
    {
        $host = strtolower($_SERVER['HTTP_HOST'] ?? '');

        if ($host === '') {
            return true;
        }

        $host = preg_replace('/:\d+$/', '', $host) ?? $host;

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        if (str_ends_with($host, '.local') || str_ends_with($host, '.test')) {
            return true;
        }

        return false;
    }

    /**
     * Optional dev override in config.php: define('INSTALLER_DEV_MODE', true);
     */
    public static function isInstallerDevMode(): bool
    {
        return defined('INSTALLER_DEV_MODE') && INSTALLER_DEV_MODE === true;
    }

    /**
     * Block public installer only when install is fully complete (migrations + admin).
     * Config may exist with an empty database mid-install — do not block in that case.
     */
    public static function shouldBlockInstaller(?string $projectRoot = null): bool
    {
        if (self::isLocalHost() || self::isInstallerDevMode()) {
            return false;
        }

        $detector = new InstallStateDetector($projectRoot);

        return $detector->detect()['isComplete'];
    }

    /**
     * Production hosts: try to remove install.php after successful install.
     */
    public static function shouldDeleteInstaller(): bool
    {
        if (self::isLocalHost() || self::isInstallerDevMode()) {
            return false;
        }

        return true;
    }

    public static function isProductionInstall(): bool
    {
        return self::shouldDeleteInstaller();
    }

    /**
     * Neutralize public/install.php on production after a successful install.
     *
     * Prefer replacing the wizard with a tiny login redirect stub instead of
     * unlinking. Unlink leaves the browser on install.php?step=complete, so a
     * refresh returns a bare nginx/Apache 404. The stub keeps that URL safe
     * while blocking re-installation.
     */
    public static function tryDeleteInstallerFile(?string $projectRoot = null): bool
    {
        if (!self::shouldDeleteInstaller()) {
            return false;
        }

        $installerPath = self::getInstallerPath($projectRoot);

        if (!is_file($installerPath)) {
            return true;
        }

        $stub = <<<'PHP'
<?php

declare(strict_types=1);

/**
 * Installer disabled after successful setup. Redirect to login.
 */
header('X-Robots-Tag: noindex, nofollow', true);

if (!is_file(__DIR__ . '/../config/config.php')) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;max-width:640px;margin:2rem auto;">';
    echo '<h1>Installer unavailable</h1>';
    echo '<p>The installer was disabled after setup, but <code>config/config.php</code> is missing. Restore <code>public/install.php</code> from your Simple KUMA package to reinstall.</p>';
    echo '</body></html>';
    exit;
}

header('Location: login.php', true, 302);
exit;

PHP;

        if (@file_put_contents($installerPath, $stub) !== false) {
            return true;
        }

        // Last resort: remove the file (refresh of this URL may 404).
        return @unlink($installerPath);
    }

    public static function isRequestHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
            return true;
        }

        $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if (is_string($forwarded) && strtolower($forwarded) === 'https') {
            return true;
        }

        return false;
    }

    public static function getLoginUrl(): string
    {
        if (defined('APP_BASE_URL')) {
            return rtrim(APP_BASE_URL, '/') . '/login.php';
        }

        $suffix = WebPathResolver::getPublicPathSuffix();

        return WebPathResolver::buildAppBaseUrl(WebPathResolver::guessBaseUrl()) . '/login.php';
    }

    private static function canConnectFromConfig(string $configPath): bool
    {
        if (defined('DB_HOST')) {
            return self::testMysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        }

        try {
            require_once $configPath;
        } catch (\Throwable $e) {
            return false;
        }

        if (!defined('DB_HOST') || !defined('DB_USER') || !defined('DB_PASSWORD') || !defined('DB_NAME')) {
            return false;
        }

        if (defined('INSTALLED') && INSTALLED !== true) {
            return false;
        }

        return self::testMysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    }

    private static function testMysqli(string $host, string $user, string $password, string $database): bool
    {
        mysqli_report(MYSQLI_REPORT_OFF);
        $db = @new \mysqli($host, $user, $password, $database);

        if ($db->connect_error) {
            return false;
        }

        $db->close();

        return true;
    }
}
