<?php

declare(strict_types=1);

namespace SimpleKuma\Installer;

/**
 * Resolves web paths for installs at domain root, subdomain, or subfolder.
 *
 * install.php always lives in public/. Detection order:
 * 1. Compare DOCUMENT_ROOT to the public/ directory on disk (most reliable).
 * 2. Fall back to SCRIPT_NAME (e.g. /public/install.php when docroot is the project root).
 */
class WebPathResolver
{
    /**
     * Project root (parent of public/).
     */
    public static function getProjectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * True when the web server document root is the public/ folder (recommended).
     */
    public static function isDocumentRootPublicDirectory(?string $projectRoot = null): bool
    {
        if (self::isScriptDirectoryDocumentRoot()) {
            return true;
        }

        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if ($documentRoot === '') {
            return false;
        }

        $documentRootReal = realpath($documentRoot);
        $publicDirReal = realpath(($projectRoot ?? self::getProjectRoot()) . '/public');

        if ($documentRootReal === false || $publicDirReal === false) {
            return false;
        }

        return $documentRootReal === $publicDirReal;
    }

    /**
     * True when the running script's directory is the web document root.
     * Reliable during install.php when project-path comparison fails (symlinks, Plesk paths).
     */
    public static function isScriptDirectoryDocumentRoot(): bool
    {
        $scriptFile = $_SERVER['SCRIPT_FILENAME'] ?? '';
        if ($scriptFile === '') {
            return false;
        }

        $scriptDir = realpath(dirname($scriptFile));
        $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');

        if ($scriptDir === false || $documentRoot === false) {
            return false;
        }

        return $scriptDir === $documentRoot;
    }

    /**
     * Recommended layout: docroot serves public/ so app URLs omit a /public prefix.
     */
    public static function shouldUseEmptyPublicWebPrefix(): bool
    {
        return self::isDocumentRootPublicDirectory();
    }

    /**
     * URL path to the public/ directory (no trailing slash).
     * Examples: '', '/public', '/tracker/public'
     */
    public static function getPublicWebPath(?string $scriptName = null): string
    {
        if (self::isDocumentRootPublicDirectory()) {
            return self::getAppUrlPathFromScript($scriptName);
        }

        return self::getPublicWebPathFromScript($scriptName);
    }

    /**
     * Appended to BASE_URL for login, dashboard, and /assets/ URLs.
     * Examples: '', '/public'
     */
    public static function getPublicPathSuffix(?string $scriptName = null): string
    {
        if (self::shouldUseEmptyPublicWebPrefix()) {
            return '';
        }

        $suffix = self::getPublicPathSuffixFromScript($scriptName);

        if ($suffix === '/public' && self::isScriptDirectoryDocumentRoot()) {
            return '';
        }

        return $suffix;
    }

    /**
     * Normalize user-entered PUBLIC_WEB_PREFIX for config (empty or path like /public).
     */
    public static function normalizePublicPathSuffix(string $suffix): string
    {
        $suffix = trim($suffix);

        if ($suffix === '' || $suffix === '/') {
            return '';
        }

        if (str_contains($suffix, '://')) {
            return '';
        }

        if (!str_starts_with($suffix, '/')) {
            $suffix = '/' . $suffix;
        }

        return rtrim($suffix, '/');
    }

    /**
     * Validate installer input for public_path_suffix.
     */
    public static function isValidPublicPathSuffix(string $suffix): bool
    {
        $normalized = self::normalizePublicPathSuffix($suffix);

        if ($normalized === '') {
            return true;
        }

        if (strlen($normalized) > 128) {
            return false;
        }

        return (bool) preg_match('#^/[a-zA-Z0-9][a-zA-Z0-9_/-]*$#', $normalized);
    }

    /**
     * Site root URL for tracking/postbacks (scheme + host + app folder, no /public).
     */
    public static function guessBaseUrl(?string $scriptName = null): string
    {
        $protocol = InstallerLock::isRequestHttps() ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        if (self::isDocumentRootPublicDirectory()) {
            $appPath = self::getAppUrlPathFromScript($scriptName);
            return $protocol . '://' . $host . $appPath;
        }

        $webPath = self::getPublicWebPathFromScript($scriptName);

        if ($webPath === '') {
            return $protocol . '://' . $host;
        }

        if (str_ends_with($webPath, '/public')) {
            $appRoot = substr($webPath, 0, -strlen('/public'));

            return $protocol . '://' . $host . $appRoot;
        }

        return $protocol . '://' . $host . $webPath;
    }

    public static function normalizeBaseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');

        if (str_ends_with($url, '/public')) {
            $url = substr($url, 0, -strlen('/public'));
        }

        return rtrim($url, '/');
    }

    /**
     * Full web base for app pages and static assets under public/.
     */
    public static function buildAppBaseUrl(
        string $baseUrl,
        ?string $publicPathSuffix = null,
        ?string $scriptName = null
    ): string {
        $suffix = $publicPathSuffix ?? self::getPublicPathSuffix($scriptName);

        return self::normalizeBaseUrl($baseUrl) . self::normalizePublicPathSuffix($suffix);
    }

    public static function getInstallLayoutDescription(?string $scriptName = null): string
    {
        if (self::isDocumentRootPublicDirectory()) {
            $appPath = self::getAppUrlPathFromScript($scriptName);
            if ($appPath === '') {
                return 'Detected: your web root points at the public/ folder (recommended). App URLs will not include /public.';
            }

            return 'Detected: public/ is the web root inside subfolder '
                . $appPath
                . '. App URLs will not include /public.';
        }

        $suffix = self::getPublicPathSuffixFromScript($scriptName);
        $publicPath = self::getPublicWebPathFromScript($scriptName);

        return 'Detected: web root is the project folder (not public/). App URLs will use '
            . ($suffix !== '' ? $suffix : '/public')
            . '. For cleaner URLs, set your domain document root to the public/ directory.';
    }

    /**
     * Subfolder path when docroot is public/ (e.g. /tracker for /tracker/install.php).
     * Strips a trailing /public segment from SCRIPT_NAME (legacy /public/install.php URL).
     */
    private static function getAppUrlPathFromScript(?string $scriptName): string
    {
        $script = str_replace('\\', '/', $scriptName ?? ($_SERVER['SCRIPT_NAME'] ?? '/install.php'));
        $dir = rtrim(dirname($script), '/');

        if ($dir === '' || $dir === '.' || $dir === '/') {
            return '';
        }

        if ($dir === '/public' || str_ends_with($dir, '/public')) {
            $stripped = substr($dir, 0, -strlen('/public'));

            return $stripped === '' ? '' : $stripped;
        }

        return $dir;
    }

    private static function getPublicWebPathFromScript(?string $scriptName): string
    {
        $script = str_replace('\\', '/', $scriptName ?? ($_SERVER['SCRIPT_NAME'] ?? '/install.php'));
        $dir = rtrim(dirname($script), '/');

        if ($dir === '' || $dir === '.') {
            return '';
        }

        return $dir;
    }

    private static function getPublicPathSuffixFromScript(?string $scriptName): string
    {
        if (self::isScriptDirectoryDocumentRoot()) {
            return '';
        }

        $webPath = self::getPublicWebPathFromScript($scriptName);

        if ($webPath === '') {
            return '';
        }

        if (str_ends_with($webPath, '/public')) {
            return '/public';
        }

        return $webPath;
    }

    public static function extractHost(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /**
     * Local / dev hosts where admin and tracking on the same host is expected.
     */
    public static function isLocalDevelopmentHost(?string $host): bool
    {
        if ($host === null || $host === '') {
            return true;
        }

        $host = strtolower(preg_replace('/:\d+$/', '', $host));

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        return str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.local');
    }

    /**
     * True when BASE_URL host is the same site as the installer/admin host (Safe Browsing risk).
     */
    public static function baseUrlSharesAdminHost(string $baseUrl, ?string $requestHost = null): bool
    {
        $baseHost = self::extractHost(self::normalizeBaseUrl($baseUrl));
        $requestHost = strtolower(preg_replace('/:\d+$/', '', $requestHost ?? ($_SERVER['HTTP_HOST'] ?? '')));

        if ($baseHost === null || $requestHost === '') {
            return false;
        }

        if (self::isLocalDevelopmentHost($baseHost) || self::isLocalDevelopmentHost($requestHost)) {
            return false;
        }

        if ($baseHost === $requestHost) {
            return true;
        }

        $stripWww = static fn (string $host): string => str_starts_with($host, 'www.')
            ? substr($host, 4)
            : $host;

        return $stripWww($baseHost) === $stripWww($requestHost);
    }
}
