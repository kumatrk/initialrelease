<?php

declare(strict_types=1);

namespace SimpleKuma\Honeycomb;

use mysqli;
use RuntimeException;
use SimpleKuma\Settings\SettingsManager;
use SimpleKuma\Update\UpdateChecker;
use Throwable;
use ZipArchive;

/**
 * Download, verify, extract, enable, disable, and remove Honeycomb addons.
 */
final class AddonInstaller
{
    private const MAX_ZIP_ENTRIES = 500;
    private const MAX_UNCOMPRESSED_BYTES = 15_728_640; // 15 MiB

    /** @var list<string> */
    private const ALLOWED_EXTENSIONS = [
        'php', 'json', 'md', 'txt', 'css', 'js', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'html',
    ];

    /** @var list<string> */
    private const BLOCKED_BASENAMES = [
        '.htaccess',
        '.htpasswd',
        '.user.ini',
        'web.config',
        'php.ini',
        '.env',
        'composer.phar',
    ];

    private AddonStore $store;
    private AllowlistedHttpClient $http;

    public function __construct(
        private mysqli $db,
        private SettingsManager $settings,
        ?AddonStore $store = null,
        ?AllowlistedHttpClient $http = null
    ) {
        $this->store = $store ?? new AddonStore($db);
        $this->http = $http ?? new AllowlistedHttpClient();
    }

    /**
     * @param array<string, mixed> $catalogEntry
     * @return array{ok: bool, message: string}
     */
    public function installFromCatalogEntry(array $catalogEntry): array
    {
        $slug = trim((string) ($catalogEntry['slug'] ?? ''));
        $zipUrl = trim((string) ($catalogEntry['zip_url'] ?? ''));
        $sha256 = strtolower(trim((string) ($catalogEntry['sha256'] ?? '')));

        if ($slug === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            return ['ok' => false, 'message' => 'Invalid addon slug.'];
        }
        if ($zipUrl === '' || $sha256 === '') {
            return ['ok' => false, 'message' => 'This catalog entry is not ready to install yet (missing zip or checksum).'];
        }
        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            return ['ok' => false, 'message' => 'Catalog checksum is invalid.'];
        }
        if (empty($catalogEntry['compatible'])) {
            return ['ok' => false, 'message' => 'This addon requires a newer Kuma version.'];
        }

        $repo = HoneycombConfig::catalogRepository($this->settings);
        $tmpDir = null;

        try {
            $this->http->assertUrlBelongsToRepo($zipUrl, $repo);
            HoneycombPaths::ensureRuntimeDirs();

            $tmpDir = HoneycombPaths::honeycombRoot() . DIRECTORY_SEPARATOR . 'tmp-' . bin2hex(random_bytes(4));
            mkdir($tmpDir, 0755, true);
            $zipPath = $tmpDir . DIRECTORY_SEPARATOR . 'addon.zip';

            $download = $this->http->get($zipUrl, 60);
            // Re-check after redirects (CDN hosts are ok; path must still map to catalog repo when applicable).
            $this->http->assertUrlBelongsToRepo($download['effective_url'], $repo);

            file_put_contents($zipPath, $download['body']);
            $actual = hash_file('sha256', $zipPath);
            if (!is_string($actual) || !hash_equals($sha256, $actual)) {
                $this->deleteTree($tmpDir);
                return ['ok' => false, 'message' => 'Zip checksum did not match the catalog. Install aborted.'];
            }

            $extractPath = $tmpDir . DIRECTORY_SEPARATOR . 'extracted';
            mkdir($extractPath, 0755, true);
            $this->extractZipSafely($zipPath, $extractPath);
            $this->assertExtractedTreeSafe($extractPath);

            $sourceRoot = $this->locateManifestRoot($extractPath);
            $manifest = Manifest::fromJsonFile($sourceRoot . DIRECTORY_SEPARATOR . 'honeycomb.json');
            if ($manifest->slug() !== $slug) {
                throw new RuntimeException('Zip slug does not match the catalog entry.');
            }

            $kumaVersion = (new UpdateChecker($this->db))->getCurrentVersion();
            if (!$manifest->meetsMinKuma($kumaVersion)) {
                throw new RuntimeException('Addon min_kuma is higher than this Kuma install.');
            }

            $target = HoneycombPaths::addonDir($slug);
            if (is_dir($target)) {
                $this->deleteTree($target);
            }
            $this->copyTree($sourceRoot, $target);
            $this->store->upsert($manifest, 'enabled');
            $this->deleteTree($tmpDir);

            return ['ok' => true, 'message' => $manifest->name() . ' ' . $manifest->version() . ' installed.'];
        } catch (Throwable $e) {
            if (is_string($tmpDir) && is_dir($tmpDir)) {
                $this->deleteTree($tmpDir);
            }
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function setEnabled(string $slug, bool $enabled): array
    {
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            return ['ok' => false, 'message' => 'Invalid addon slug.'];
        }
        if ($this->store->getBySlug($slug) === null && !is_dir(HoneycombPaths::addonDir($slug))) {
            return ['ok' => false, 'message' => 'Addon is not installed.'];
        }
        $ok = $this->store->setStatus($slug, $enabled ? 'enabled' : 'disabled');
        return [
            'ok' => $ok,
            'message' => $ok
                ? ($enabled ? 'Addon enabled.' : 'Addon disabled.')
                : 'Could not update addon status.',
        ];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function uninstall(string $slug): array
    {
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            return ['ok' => false, 'message' => 'Invalid addon slug.'];
        }
        $dir = HoneycombPaths::addonDir($slug);
        if (is_dir($dir)) {
            $this->deleteTree($dir);
        }
        $this->store->delete($slug);
        // Drop API secrets for this addon; leave bindings + hourly cost history (tracking unchanged).
        try {
            (new CredentialStore($this->db))->deleteByAddon($slug);
        } catch (Throwable $e) {
            // Schema may not exist yet on older installs.
        }

        return ['ok' => true, 'message' => 'Addon removed from this install. Tracking is unchanged.'];
    }

    /**
     * Install from a local addon directory (dev / packaging). Still validates honeycomb.json.
     *
     * @return array{ok: bool, message: string}
     */
    public function installFromLocalDirectory(string $sourceDir, bool $enable = true): array
    {
        try {
            $sourceDir = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sourceDir), DIRECTORY_SEPARATOR);
            $manifest = Manifest::fromJsonFile($sourceDir . DIRECTORY_SEPARATOR . 'honeycomb.json');
            $kumaVersion = (new UpdateChecker($this->db))->getCurrentVersion();
            if (!$manifest->meetsMinKuma($kumaVersion)) {
                return ['ok' => false, 'message' => 'Addon min_kuma is higher than this Kuma install.'];
            }
            HoneycombPaths::ensureRuntimeDirs();
            $this->assertExtractedTreeSafe($sourceDir);
            $target = HoneycombPaths::addonDir($manifest->slug());
            if (is_dir($target)) {
                $this->deleteTree($target);
            }
            $this->copyTree($sourceDir, $target);
            $this->store->upsert($manifest, $enable ? 'enabled' : 'disabled');

            return [
                'ok' => true,
                'message' => $manifest->name() . ' ' . $manifest->version() . ' installed from local folder.',
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    private function extractZipSafely(string $zipPath, string $extractPath): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Could not open the addon zip.');
        }
        if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
            $zip->close();
            throw new RuntimeException('Addon zip has too many files.');
        }

        $uncompressed = 0;
        $unixOpsys = defined('ZipArchive::OPSYS_UNIX') ? (int) ZipArchive::OPSYS_UNIX : 3;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                $zip->close();
                throw new RuntimeException('Addon zip entry could not be read.');
            }

            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
            if (
                $name === ''
                || str_contains($name, '..')
                || str_starts_with($name, '/')
                || preg_match('#^[A-Za-z]:/#', $name) === 1
            ) {
                $zip->close();
                throw new RuntimeException('Addon zip contains an unsafe path.');
            }
            if (str_contains($name, "\0")) {
                $zip->close();
                throw new RuntimeException('Addon zip contains a null byte in a path.');
            }

            $isDir = str_ends_with($name, '/');
            if (!$isDir) {
                $this->assertAllowedAddonFilename($name);
            }

            $opsys = (int) ($stat['opsys'] ?? -1);
            $attr = (int) ($stat['external_attributes'] ?? 0);
            // Unix: high 16 bits are mode; symlink is 0120000
            if ($opsys === $unixOpsys) {
                $mode = ($attr >> 16) & 0xFFFF;
                if (($mode & 0xF000) === 0xA000) {
                    $zip->close();
                    throw new RuntimeException('Addon zip must not contain symbolic links.');
                }
            }

            $uncompressed += (int) ($stat['size'] ?? 0);
            if ($uncompressed > self::MAX_UNCOMPRESSED_BYTES) {
                $zip->close();
                throw new RuntimeException('Addon zip expands beyond the size limit.');
            }
        }

        if (!$zip->extractTo($extractPath)) {
            $zip->close();
            throw new RuntimeException('Could not extract the addon zip.');
        }
        $zip->close();
    }

    private function assertExtractedTreeSafe(string $root): void
    {
        $rootReal = realpath($root);
        if ($rootReal === false || !is_dir($rootReal)) {
            throw new RuntimeException('Extracted addon path is invalid.');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootReal, \FilesystemIterator::SKIP_DOTS)
        );
        $bytes = 0;

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (is_link($path)) {
                throw new RuntimeException('Addon package must not contain symbolic links.');
            }

            $real = realpath($path);
            if ($real === false) {
                throw new RuntimeException('Addon package escaped the extract directory.');
            }
            if ($real !== $rootReal && !str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Addon package escaped the extract directory.');
            }

            if ($file->isFile()) {
                $rel = substr($real, strlen($rootReal) + 1);
                $this->assertAllowedAddonFilename(str_replace('\\', '/', $rel));
                $bytes += (int) $file->getSize();
                if ($bytes > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new RuntimeException('Addon package exceeds the size limit.');
                }
            }
        }
    }

    private function assertAllowedAddonFilename(string $relativePath): void
    {
        $normalized = str_replace('\\', '/', $relativePath);
        $base = basename($normalized);
        if ($base === '' || $base === '.' || $base === '..') {
            throw new RuntimeException('Addon zip contains an unsafe filename.');
        }

        $baseLower = strtolower($base);
        if (in_array($baseLower, self::BLOCKED_BASENAMES, true) || str_starts_with($base, '.')) {
            // Allow .gitkeep only if ever needed; otherwise block dotted server config / hidden files.
            if ($baseLower !== '.gitkeep') {
                throw new RuntimeException('Addon zip contains a blocked file: ' . $base);
            }
        }

        if (!str_contains($base, '.')) {
            // Extensionless files (e.g. LICENSE) are fine.
            return;
        }

        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            throw new RuntimeException('Addon zip contains a disallowed file type: ' . $base);
        }
    }

    private function locateManifestRoot(string $extractPath): string
    {
        $direct = $extractPath . DIRECTORY_SEPARATOR . 'honeycomb.json';
        if (is_file($direct)) {
            return $extractPath;
        }

        $entries = scandir($extractPath) ?: [];
        $dirs = [];
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = $extractPath . DIRECTORY_SEPARATOR . $name;
            if (is_dir($full)) {
                $dirs[] = $full;
            }
        }

        if (count($dirs) === 1 && is_file($dirs[0] . DIRECTORY_SEPARATOR . 'honeycomb.json')) {
            return $dirs[0];
        }

        throw new RuntimeException('Addon zip must contain honeycomb.json at the root.');
    }

    private function copyTree(string $source, string $dest): void
    {
        mkdir($dest, 0755, true);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (is_link($path)) {
                throw new RuntimeException('Refusing to copy a symbolic link from the addon package.');
            }
            $rel = substr($path, strlen($source) + 1);
            $target = $dest . DIRECTORY_SEPARATOR . $rel;
            if ($file->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
            } else {
                $this->assertAllowedAddonFilename(str_replace('\\', '/', $rel));
                $parent = dirname($target);
                if (!is_dir($parent)) {
                    mkdir($parent, 0755, true);
                }
                copy($path, $target);
            }
        }
    }

    private function deleteTree(string $path): void
    {
        if (!is_dir($path) && !is_file($path) && !is_link($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            $p = $file->getPathname();
            if (is_link($p) || $file->isFile()) {
                unlink($p);
            } elseif ($file->isDir()) {
                rmdir($p);
            }
        }
        rmdir($path);
    }
}
