<?php

declare(strict_types=1);

namespace SimpleKuma\Update;

use mysqli;
use RuntimeException;
use SimpleKuma\Database\MigrationRunner;
use SimpleKuma\Settings\SettingsManager;
use Throwable;
use ZipArchive;

/**
 * Installs a GitHub repository archive from the Kuma settings page.
 * Prefers the evergreen "latest" tag (Simple Kuma Download); version comes from version.php.
 */
final class UpdateInstaller
{
    private const MAX_DOWNLOAD_BYTES = 536870912; // 512 MiB safety limit

    private mysqli $db;
    private SettingsManager $settings;
    private string $projectRoot;

    public function __construct(mysqli $db, ?string $projectRoot = null)
    {
        $this->db = $db;
        $this->settings = new SettingsManager($db);
        $resolved = realpath($projectRoot ?: dirname(__DIR__, 2));
        if ($resolved === false) {
            throw new RuntimeException('Could not resolve the Simple Kuma install root.');
        }
        $this->projectRoot = $resolved;
    }

    /**
     * @param array<string, mixed> $release Result returned by UpdateChecker::checkForUpdates()
     * @return array{
     *   success: bool,
     *   log_id: int,
     *   files_updated?: list<string>,
     *   migrations_applied?: list<string>,
     *   backup_location?: string,
     *   errors?: list<string>
     * }
     */
    public function install(array $release, string $currentVersion, ?int $adminUserId = null): array
    {
        $targetVersion = trim((string) ($release['latest_version'] ?? ''));
        $tagName = trim((string) ($release['tag_name'] ?? ''));
        $zipballUrl = trim((string) ($release['zipball_url'] ?? ''));
        $updateType = (string) ($release['update_type'] ?? 'patch');

        $this->validateReleaseInput($targetVersion, $tagName, $zipballUrl, $currentVersion);
        $logId = $this->createUpdateLog($currentVersion, $targetVersion, $updateType, $adminUserId);
        $startedAt = time();
        $workspace = $this->projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR
            . 'updates' . DIRECTORY_SEPARATOR . 'staging' . DIRECTORY_SEPARATOR
            . $targetVersion . '-' . bin2hex(random_bytes(5));
        $archivePath = $workspace . DIRECTORY_SEPARATOR . 'update.zip';
        $extractPath = $workspace . DIRECTORY_SEPARATOR . 'extracted';
        $backupPath = $this->projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR
            . 'updates' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR
            . date('Ymd-His') . '-' . $currentVersion . '-to-' . $targetVersion;

        try {
            $this->updateLogStatus($logId, 'in_progress');
            $this->runPreflight($workspace);
            $this->downloadArchive($zipballUrl, $archivePath);
            $this->extractArchive($archivePath, $extractPath);

            $sourceRoot = ReleaseUpgrader::locateSourceRoot($extractPath);
            if ($sourceRoot === null) {
                throw new RuntimeException('The GitHub archive does not contain a valid Simple Kuma release tree.');
            }

            $archiveVersion = ReleaseUpgrader::readVersion($sourceRoot);
            if ($archiveVersion !== $targetVersion) {
                throw new RuntimeException(
                    "The downloaded tree reports version {$archiveVersion}; expected {$targetVersion} from tag {$tagName}."
                );
            }

            $upgrader = new ReleaseUpgrader($this->projectRoot);
            $preview = $upgrader->preview($sourceRoot);
            if (!$preview['ok']) {
                throw new RuntimeException(implode(' ', $preview['errors']));
            }

            $this->copyConfigBackup($backupPath);
            $applyResult = $upgrader->apply($sourceRoot, $backupPath . DIRECTORY_SEPARATOR . 'files');
            if (!$applyResult['ok']) {
                throw new RuntimeException(implode(' ', $applyResult['errors']));
            }
            // ZIP extract + copy can leave 0666 under a permissive FPM umask.
            TreePermissionNormalizer::normalizeRelativeFiles(
                $this->projectRoot,
                $applyResult['files_copied']
            );
            $this->updateLogDetails(
                $logId,
                $applyResult['files_copied'],
                [],
                time() - $startedAt,
                $backupPath
            );

            // The updated migration files are now on disk. MigrationRunner is
            // the only supported executor and records every applied migration.
            $runner = new MigrationRunner($this->db);
            if (!$runner->run()) {
                $errors = $runner->getErrors();
                throw new RuntimeException(
                    'Application files were updated, but database migrations stopped: '
                    . implode(' ', $errors)
                    . ' Re-run Update database from this page after correcting the database error.'
                );
            }
            $migrationsApplied = $runner->getAppliedMigrations();

            if (!$this->settings->set('app_version', $targetVersion)) {
                throw new RuntimeException('The update completed, but app_version could not be synchronized.');
            }
            $this->settings->set('update_check_cache', '');
            $this->settings->set('update_check_cache_time', '0');

            $executionTime = time() - $startedAt;
            $this->updateLogDetails(
                $logId,
                $applyResult['files_copied'],
                $migrationsApplied,
                $executionTime,
                $backupPath
            );
            $this->updateLogStatus($logId, 'success');

            return [
                'success' => true,
                'log_id' => $logId,
                'files_updated' => $applyResult['files_copied'],
                'migrations_applied' => $migrationsApplied,
                'backup_location' => $backupPath,
            ];
        } catch (Throwable $e) {
            $this->updateLogStatus($logId, 'failed', $e->getMessage());
            return [
                'success' => false,
                'log_id' => $logId,
                'errors' => [$e->getMessage()],
            ];
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getUpdateHistory(int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            'SELECT ul.*, u.username
             FROM update_logs ul
             LEFT JOIN users u ON ul.admin_user_id = u.id
             ORDER BY ul.started_at DESC
             LIMIT ?'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    private function validateReleaseInput(
        string $targetVersion,
        string $tagName,
        string $zipballUrl,
        string $currentVersion
    ): void {
        if (preg_match('/^\d+\.\d+\.\d+(?:\.\d+)?(?:-[0-9A-Za-z.-]+)?$/', $targetVersion) !== 1) {
            throw new RuntimeException('The target update version is invalid.');
        }
        $isEvergreen = strcasecmp($tagName, UpdateChecker::EVERGREEN_TAG) === 0;
        $isVersionTag = strcasecmp($tagName, 'v' . $targetVersion) === 0;
        if (!$isEvergreen && !$isVersionTag) {
            throw new RuntimeException(
                'The update tag must be "' . UpdateChecker::EVERGREEN_TAG . '" or v' . $targetVersion . '.'
            );
        }
        if (version_compare($currentVersion, $targetVersion, '>=')) {
            throw new RuntimeException('The selected update is not newer than the installed version.');
        }

        $parts = parse_url($zipballUrl);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'api.github.com') {
            throw new RuntimeException('The update archive URL is not a trusted GitHub API URL.');
        }
    }

    private function runPreflight(string $workspace): void
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('The PHP cURL extension is required for one-click updates.');
        }
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP Zip extension is required for one-click updates.');
        }
        if (!is_writable($this->projectRoot)) {
            throw new RuntimeException('The Simple Kuma install directory is not writable by PHP.');
        }

        if (!is_dir($workspace) && !mkdir($workspace, 0755, true) && !is_dir($workspace)) {
            throw new RuntimeException('Could not create the temporary update workspace under storage/updates.');
        }
        if (!is_writable($workspace)) {
            throw new RuntimeException('The temporary update workspace is not writable.');
        }
    }

    private function downloadArchive(string $url, string $destination): void
    {
        $handle = fopen($destination, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Could not create the temporary update archive.');
        }

        $downloaded = 0;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'SimpleKuma-OneClickUpdater/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static function (
                mixed $curl,
                float $downloadTotal,
                float $downloadNow,
                float $uploadTotal,
                float $uploadNow
            ) use (&$downloaded): int {
                $downloaded = (int) $downloadNow;
                return $downloadNow > self::MAX_DOWNLOAD_BYTES ? 1 : 0;
            },
        ]);

        $ok = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        fclose($handle);

        if ($ok !== true || $httpCode !== 200) {
            @unlink($destination);
            $detail = $downloaded > self::MAX_DOWNLOAD_BYTES
                ? 'Archive exceeded the 512 MiB safety limit.'
                : ($curlError !== '' ? $curlError : "GitHub returned HTTP {$httpCode}.");
            throw new RuntimeException('Could not download the update archive: ' . $detail);
        }
        if (!is_file($destination) || filesize($destination) === 0) {
            throw new RuntimeException('GitHub returned an empty update archive.');
        }

        $freeSpace = @disk_free_space(dirname($destination));
        $archiveSize = filesize($destination) ?: 0;
        if (is_float($freeSpace) && $freeSpace < ($archiveSize * 3)) {
            throw new RuntimeException('There is not enough free disk space to extract and back up this update.');
        }
    }

    private function extractArchive(string $archivePath, string $extractPath): void
    {
        if (!mkdir($extractPath, 0755, true) && !is_dir($extractPath)) {
            throw new RuntimeException('Could not create the update extraction directory.');
        }

        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('The downloaded update is not a readable ZIP archive.');
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = (string) $zip->getNameIndex($index);
            $normalized = str_replace('\\', '/', $entry);
            if ($normalized === ''
                || str_contains($normalized, "\0")
                || str_starts_with($normalized, '/')
                || preg_match('#(^|/)\.\.(/|$)#', $normalized) === 1
                || preg_match('#^[A-Za-z]:/#', $normalized) === 1) {
                $zip->close();
                throw new RuntimeException('The update archive contains an unsafe file path.');
            }

            $operations = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($index, $operations, $attributes)
                && (($attributes >> 16) & 0170000) === 0120000) {
                $zip->close();
                throw new RuntimeException('The update archive contains a symbolic link.');
            }
        }

        if (!$zip->extractTo($extractPath)) {
            $zip->close();
            throw new RuntimeException('Could not extract the update archive.');
        }
        $zip->close();

        TreePermissionNormalizer::normalizeTree($extractPath);
    }

    private function copyConfigBackup(string $backupPath): void
    {
        $configSource = $this->projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php';
        $configBackup = $backupPath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php';
        if (!is_dir(dirname($configBackup))
            && !mkdir(dirname($configBackup), 0755, true)
            && !is_dir(dirname($configBackup))) {
            throw new RuntimeException('Could not create the configuration backup directory.');
        }
        if (!copy($configSource, $configBackup)) {
            throw new RuntimeException('Could not back up config/config.php.');
        }
    }

    private function createUpdateLog(
        string $versionFrom,
        string $versionTo,
        string $updateType,
        ?int $adminUserId
    ): int {
        $stmt = $this->db->prepare(
            "INSERT INTO update_logs
             (version_from, version_to, update_type, started_at, status, files_updated, admin_user_id)
             VALUES (?, ?, ?, NOW(), 'pending', '[]', ?)"
        );
        if ($stmt === false) {
            throw new RuntimeException('The update log table is unavailable. Apply pending database migrations first.');
        }
        $stmt->bind_param('sssi', $versionFrom, $versionTo, $updateType, $adminUserId);
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not create the update log: ' . $stmt->error);
        }

        return (int) $this->db->insert_id;
    }

    private function updateLogStatus(int $logId, string $status, ?string $error = null): void
    {
        $completedSql = in_array($status, ['success', 'failed', 'rolled_back'], true)
            ? ', completed_at = NOW()'
            : '';
        $stmt = $this->db->prepare(
            "UPDATE update_logs SET status = ?, error_log = ?{$completedSql} WHERE id = ?"
        );
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('ssi', $status, $error, $logId);
        $stmt->execute();
    }

    /**
     * @param list<string> $filesUpdated
     * @param list<string> $migrationsApplied
     */
    private function updateLogDetails(
        int $logId,
        array $filesUpdated,
        array $migrationsApplied,
        int $executionTime,
        string $backupLocation
    ): void {
        $filesJson = json_encode($filesUpdated, JSON_UNESCAPED_SLASHES);
        $migrationsJson = json_encode($migrationsApplied, JSON_UNESCAPED_SLASHES);
        $stmt = $this->db->prepare(
            'UPDATE update_logs
             SET files_updated = ?, migrations_applied = ?, execution_time = ?, backup_location = ?,
                 rollback_available = FALSE
             WHERE id = ?'
        );
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('ssisi', $filesJson, $migrationsJson, $executionTime, $backupLocation, $logId);
        $stmt->execute();
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}
