<?php

declare(strict_types=1);

namespace SimpleKuma\Update;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Applies an extracted release tree over an existing Simple Kuma install.
 *
 * This is an overlay only: files absent from the release are never deleted.
 */
final class ReleaseUpgrader
{
    private string $installRoot;

    public function __construct(string $installRoot)
    {
        $resolved = realpath($installRoot);
        if ($resolved === false || !is_file($resolved . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php')) {
            throw new RuntimeException('The update target is not an existing Simple Kuma install.');
        }

        $this->installRoot = rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    public function getInstallRoot(): string
    {
        return $this->installRoot;
    }

    public static function readVersion(string $root): ?string
    {
        $versionFile = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'version.php';
        if (!is_file($versionFile)) {
            return null;
        }

        $data = include $versionFile;
        $version = is_array($data) ? trim((string) ($data['version'] ?? '')) : '';

        return preg_match('/^\d+\.\d+\.\d+(?:\.\d+)?(?:-[0-9A-Za-z.-]+)?$/', $version) === 1
            ? $version
            : null;
    }

    /**
     * GitHub archive downloads wrap the repository in a single generated folder.
     */
    public static function locateSourceRoot(string $extractedRoot): ?string
    {
        $resolved = realpath($extractedRoot);
        if ($resolved === false || !is_dir($resolved)) {
            return null;
        }

        if (self::readVersion($resolved) !== null) {
            return $resolved;
        }

        $children = scandir($resolved);
        if ($children === false) {
            return null;
        }

        $candidates = [];
        foreach ($children as $child) {
            if ($child === '.' || $child === '..') {
                continue;
            }
            $candidate = $resolved . DIRECTORY_SEPARATOR . $child;
            if (is_dir($candidate) && self::readVersion($candidate) !== null) {
                $candidates[] = $candidate;
            }
        }

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * @return array{
     *   ok: bool,
     *   files: list<array{rel: string, src: string, dest: string}>,
     *   skipped: list<string>,
     *   errors: list<string>
     * }
     */
    public function preview(string $sourceRoot): array
    {
        $sourceRoot = realpath($sourceRoot) ?: '';
        if ($sourceRoot === '' || self::readVersion($sourceRoot) === null) {
            return [
                'ok' => false,
                'files' => [],
                'skipped' => [],
                'errors' => ['The update source is missing a valid version.php file.'],
            ];
        }

        $files = [];
        $skipped = [];
        $errors = [];
        $sourcePrefix = str_replace('\\', '/', rtrim($sourceRoot, '/\\'));
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile() || $fileInfo->isLink()) {
                continue;
            }

            $full = str_replace('\\', '/', $fileInfo->getPathname());
            $relative = ltrim(substr($full, strlen($sourcePrefix)), '/');
            if ($relative === '' || $this->isProtectedPath($relative)) {
                $skipped[] = $relative;
                continue;
            }

            $destination = $this->installRoot . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $files[] = [
                'rel' => $relative,
                'src' => $fileInfo->getPathname(),
                'dest' => $destination,
            ];

            if (is_file($destination) && !is_writable($destination)) {
                $errors[] = "File is not writable: {$relative}";
                continue;
            }

            $existingParent = dirname($destination);
            while (!is_dir($existingParent) && dirname($existingParent) !== $existingParent) {
                $existingParent = dirname($existingParent);
            }
            if (!is_writable($existingParent)) {
                $errors[] = "Directory is not writable: {$existingParent}";
            }
        }

        return [
            'ok' => $errors === [],
            'files' => $files,
            'skipped' => array_values(array_filter($skipped, static fn (string $path): bool => $path !== '')),
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * @return array{
     *   ok: bool,
     *   files_copied: list<string>,
     *   files_skipped: list<string>,
     *   errors: list<string>,
     *   backup_location: string,
     *   config_preserved: bool
     * }
     */
    public function apply(string $sourceRoot, string $backupRoot): array
    {
        $preview = $this->preview($sourceRoot);
        $report = [
            'ok' => false,
            'files_copied' => [],
            'files_skipped' => $preview['skipped'],
            'errors' => $preview['errors'],
            'backup_location' => $backupRoot,
            'config_preserved' => true,
        ];

        if (!$preview['ok']) {
            return $report;
        }

        $configPath = $this->installRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php';
        $configHashBefore = hash_file('sha256', $configPath);
        $createdFiles = [];

        try {
            $this->createBackup($preview['files'], $backupRoot, $createdFiles);

            foreach ($preview['files'] as $file) {
                $destinationDir = dirname($file['dest']);
                if (!is_dir($destinationDir)
                    && !mkdir($destinationDir, 0755, true)
                    && !is_dir($destinationDir)) {
                    throw new RuntimeException("Failed to create directory for {$file['rel']}.");
                }

                if (!copy($file['src'], $file['dest'])) {
                    throw new RuntimeException("Failed to copy {$file['rel']}.");
                }
                $report['files_copied'][] = $file['rel'];
            }

            $configHashAfter = hash_file('sha256', $configPath);
            if ($configHashBefore === false || $configHashAfter === false || !hash_equals($configHashBefore, $configHashAfter)) {
                $report['config_preserved'] = false;
                throw new RuntimeException('config/config.php changed during the update.');
            }

            $report['ok'] = true;
        } catch (\Throwable $e) {
            $report['errors'][] = $e->getMessage();
            try {
                $this->rollback($backupRoot, $createdFiles);
            } catch (\Throwable $rollbackError) {
                $report['errors'][] = 'File rollback failed: ' . $rollbackError->getMessage();
            }
        }

        return $report;
    }

    /**
     * @param list<array{rel: string, src: string, dest: string}> $files
     * @param list<string> $createdFiles
     */
    private function createBackup(array $files, string $backupRoot, array &$createdFiles): void
    {
        if (!is_dir($backupRoot)
            && !mkdir($backupRoot, 0755, true)
            && !is_dir($backupRoot)) {
            throw new RuntimeException('Could not create the update backup directory.');
        }

        foreach ($files as $file) {
            if (!is_file($file['dest'])) {
                $createdFiles[] = $file['rel'];
                continue;
            }

            $backupFile = rtrim($backupRoot, '/\\') . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $file['rel']);
            $backupDir = dirname($backupFile);
            if (!is_dir($backupDir)
                && !mkdir($backupDir, 0755, true)
                && !is_dir($backupDir)) {
                throw new RuntimeException("Could not create backup directory for {$file['rel']}.");
            }
            if (!copy($file['dest'], $backupFile)) {
                throw new RuntimeException("Could not back up {$file['rel']}.");
            }
        }

        file_put_contents(
            rtrim($backupRoot, '/\\') . DIRECTORY_SEPARATOR . '.created-files.json',
            json_encode($createdFiles, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * @param list<string>|null $createdFiles
     */
    public function rollback(string $backupRoot, ?array $createdFiles = null): void
    {
        if (!is_dir($backupRoot)) {
            throw new RuntimeException('Update backup directory is missing.');
        }

        if ($createdFiles === null) {
            $metadata = @file_get_contents(rtrim($backupRoot, '/\\') . DIRECTORY_SEPARATOR . '.created-files.json');
            $decoded = $metadata === false ? null : json_decode($metadata, true);
            $createdFiles = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($backupRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile() || $fileInfo->getFilename() === '.created-files.json') {
                continue;
            }

            $backupPrefix = str_replace('\\', '/', rtrim($backupRoot, '/\\'));
            $full = str_replace('\\', '/', $fileInfo->getPathname());
            $relative = ltrim(substr($full, strlen($backupPrefix)), '/');
            $destination = $this->installRoot . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $destinationDir = dirname($destination);
            if (!is_dir($destinationDir)) {
                mkdir($destinationDir, 0755, true);
            }
            if (!copy($fileInfo->getPathname(), $destination)) {
                throw new RuntimeException("Could not restore {$relative}.");
            }
        }

        foreach ($createdFiles as $relative) {
            $destination = $this->installRoot . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (is_file($destination) && !unlink($destination)) {
                throw new RuntimeException("Could not remove newly-created file {$relative}.");
            }
        }
    }

    private function isProtectedPath(string $relative): bool
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');

        if ($relative === 'config/config.php' || $relative === '.env') {
            return true;
        }
        if (str_starts_with($relative, 'storage/')) {
            return true;
        }
        if ($relative === 'public/install.php' && $this->isInstalled()) {
            return true;
        }

        return false;
    }

    private function isInstalled(): bool
    {
        $config = @file_get_contents(
            $this->installRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php'
        );

        return is_string($config)
            && (str_contains($config, "define('INSTALLED', true)")
                || str_contains($config, 'define("INSTALLED", true)'));
    }
}
