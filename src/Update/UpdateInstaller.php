<?php

declare(strict_types=1);

namespace SimpleKuma\Update;

use mysqli;
use SimpleKuma\Settings\SettingsManager;

/**
 * Update Installer
 * Handles downloading, validating, and applying updates
 */
class UpdateInstaller
{
    private mysqli $db;
    private SettingsManager $settings;
    private UpdateValidator $validator;
    private string $projectRoot;
    private const STAGING_DIR = 'updates/staging';
    private const BACKUP_DIR = 'updates/backup';
    private const GITHUB_RAW_BASE = 'https://raw.githubusercontent.com/';

    public function __construct(mysqli $db, string $projectRoot = null)
    {
        $this->db = $db;
        $this->settings = new SettingsManager($db);
        $this->validator = new UpdateValidator();
        $this->projectRoot = $projectRoot ?: dirname(__DIR__, 2);
        
        // Ensure update directories exist
        $this->ensureDirectoriesExist();
    }

    /**
     * Ensure update directories exist
     */
    private function ensureDirectoriesExist(): void
    {
        $stagingDir = $this->projectRoot . '/' . self::STAGING_DIR;
        $backupDir = $this->projectRoot . '/' . self::BACKUP_DIR;
        
        if (!is_dir($stagingDir)) {
            mkdir($stagingDir, 0755, true);
        }
        
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
    }

    /**
     * Get the configured GitHub repository
     */
    private function getRepository(): string
    {
        $repo = $this->settings->get('update_repository', null);
        return $repo ?: 'SimpleKuma/simplekuma';
    }

    /**
     * Get the branch to use (default: main)
     */
    private function getBranch(): string
    {
        return 'main'; // Could be made configurable in the future
    }

    /**
     * Create update log entry
     */
    private function createUpdateLog(string $versionFrom, string $versionTo, string $updateType, ?int $adminUserId = null): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO update_logs 
            (version_from, version_to, update_type, started_at, status, files_updated, admin_user_id)
            VALUES (?, ?, ?, NOW(), 'pending', '[]', ?)
        ");
        $stmt->bind_param('sssi', $versionFrom, $versionTo, $updateType, $adminUserId);
        $stmt->execute();
        
        return (int)$this->db->insert_id;
    }

    /**
     * Update log status
     */
    private function updateLogStatus(int $logId, string $status, ?string $errorLog = null): void
    {
        $stmt = $this->db->prepare("
            UPDATE update_logs 
            SET status = ?, error_log = ?, completed_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param('ssi', $status, $errorLog, $logId);
        $stmt->execute();
    }

    /**
     * Update log with files and execution time
     */
    private function updateLogDetails(int $logId, array $filesUpdated, ?array $migrationsApplied = null, ?int $executionTime = null, ?string $backupLocation = null): void
    {
        $filesJson = json_encode($filesUpdated);
        $migrationsJson = $migrationsApplied ? json_encode($migrationsApplied) : null;
        
        $stmt = $this->db->prepare("
            UPDATE update_logs 
            SET files_updated = ?, migrations_applied = ?, execution_time = ?, backup_location = ?
            WHERE id = ?
        ");
        $stmt->bind_param('ssiss', $filesJson, $migrationsJson, $executionTime, $backupLocation, $logId);
        $stmt->execute();
    }

    /**
     * Run pre-flight validation checks
     */
    public function runPreFlightChecks(array $manifest): array
    {
        $results = [
            'php_version' => false,
            'mysql_version' => false,
            'disk_space' => false,
            'staging_dir' => false,
            'backup_dir' => false,
            'file_permissions' => [],
            'errors' => []
        ];

        // Check PHP version
        if (isset($manifest['requirements']['php_min'])) {
            $results['php_version'] = $this->validator->checkPHPVersion($manifest['requirements']['php_min']);
            if (!$results['php_version']) {
                $results['errors'][] = "PHP version " . PHP_VERSION . " is below required " . $manifest['requirements']['php_min'];
            }
        }

        // Check MySQL version
        if (isset($manifest['requirements']['mysql_min'])) {
            $results['mysql_version'] = $this->validator->checkMySQLVersion($this->db, $manifest['requirements']['mysql_min']);
            if (!$results['mysql_version']) {
                $results['errors'][] = "MySQL version is below required " . $manifest['requirements']['mysql_min'];
            }
        }

        // Calculate total size needed
        $totalSize = 0;
        if (isset($manifest['files']) && is_array($manifest['files'])) {
            foreach ($manifest['files'] as $file) {
                $totalSize += $file['size'] ?? 0;
            }
        }

        // Check disk space
        $results['disk_space'] = $this->validator->checkDiskSpace($this->projectRoot, $totalSize);
        if (!$results['disk_space']) {
            $results['errors'][] = "Insufficient disk space. Required: " . number_format($totalSize * 2) . " bytes";
        }

        // Check staging directory
        $stagingPath = $this->projectRoot . '/' . self::STAGING_DIR;
        $results['staging_dir'] = $this->validator->isDirectoryWritable($stagingPath);

        // Check backup directory
        $backupPath = $this->projectRoot . '/' . self::BACKUP_DIR;
        $results['backup_dir'] = $this->validator->isDirectoryWritable($backupPath);

        // Check file permissions for files to be updated
        if (isset($manifest['files']) && is_array($manifest['files'])) {
            foreach ($manifest['files'] as $file) {
                $filePath = $this->projectRoot . '/' . $file['path'];
                $writable = $this->validator->isFileWritable($filePath);
                $results['file_permissions'][$file['path']] = $writable;
                if (!$writable) {
                    $results['errors'][] = "File not writable: {$file['path']}";
                }
            }
        }

        return $results;
    }

    /**
     * Download a file from GitHub
     */
    private function downloadFile(string $filePath, string $targetPath): bool
    {
        $repo = $this->getRepository();
        $branch = $this->getBranch();
        $url = self::GITHUB_RAW_BASE . $repo . '/' . $branch . '/' . $filePath;

        // Create target directory if needed
        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'SimpleKuma-UpdateInstaller/1.0',
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200) {
            error_log("Failed to download {$filePath}: HTTP {$httpCode} - {$error}");
            return false;
        }

        // Write to temporary file first, then rename atomically
        $tempPath = $targetPath . '.tmp';
        if (file_put_contents($tempPath, $response) === false) {
            return false;
        }

        return rename($tempPath, $targetPath);
    }

    /**
     * Download and stage all files from manifest
     */
    public function downloadAndStageFiles(array $manifest, string $version): array
    {
        $stagingPath = $this->projectRoot . '/' . self::STAGING_DIR . '/' . $version;
        
        // Create staging directory
        if (!is_dir($stagingPath)) {
            mkdir($stagingPath, 0755, true);
        }

        $results = [
            'success' => true,
            'downloaded' => [],
            'failed' => [],
            'errors' => []
        ];

        if (!isset($manifest['files']) || !is_array($manifest['files'])) {
            $results['success'] = false;
            $results['errors'][] = 'No files specified in manifest';
            return $results;
        }

        foreach ($manifest['files'] as $file) {
            $filePath = $file['path'];
            $targetPath = $stagingPath . '/' . $filePath;

            // Download file
            if (!$this->downloadFile($filePath, $targetPath)) {
                $results['failed'][] = $filePath;
                $results['errors'][] = "Failed to download: {$filePath}";
                $results['success'] = false;
                continue;
            }

            // Verify hash
            if (!$this->validator->verifyFileHash($targetPath, $file['hash'])) {
                unlink($targetPath); // Remove invalid file
                $results['failed'][] = $filePath;
                $results['errors'][] = "Hash mismatch for: {$filePath}";
                $results['success'] = false;
                continue;
            }

            $results['downloaded'][] = $filePath;
        }

        return $results;
    }

    /**
     * Create backup of files to be updated
     */
    public function createBackup(string $versionFrom, array $files): string
    {
        $backupPath = $this->projectRoot . '/' . self::BACKUP_DIR . '/' . $versionFrom;
        
        // Create backup directory
        if (!is_dir($backupPath)) {
            mkdir($backupPath, 0755, true);
        }

        foreach ($files as $filePath) {
            $sourcePath = $this->projectRoot . '/' . $filePath;
            $backupFilePath = $backupPath . '/' . $filePath;

            if (file_exists($sourcePath)) {
                // Create directory structure in backup
                $backupDir = dirname($backupFilePath);
                if (!is_dir($backupDir)) {
                    mkdir($backupDir, 0755, true);
                }

                // Copy file to backup
                copy($sourcePath, $backupFilePath);
            }
        }

        return $backupPath;
    }

    /**
     * Apply staged files to production
     */
    public function applyFiles(string $version, array $files): array
    {
        $stagingPath = $this->projectRoot . '/' . self::STAGING_DIR . '/' . $version;
        $results = [
            'success' => true,
            'applied' => [],
            'failed' => [],
            'errors' => []
        ];

        foreach ($files as $filePath) {
            $stagedPath = $stagingPath . '/' . $filePath;
            $targetPath = $this->projectRoot . '/' . $filePath;

            if (!file_exists($stagedPath)) {
                $results['failed'][] = $filePath;
                $results['errors'][] = "Staged file not found: {$filePath}";
                $results['success'] = false;
                continue;
            }

            // Create target directory if needed
            $targetDir = dirname($targetPath);
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            // Copy file atomically
            $tempPath = $targetPath . '.tmp';
            if (!copy($stagedPath, $tempPath)) {
                $results['failed'][] = $filePath;
                $results['errors'][] = "Failed to copy staged file: {$filePath}";
                $results['success'] = false;
                continue;
            }

            // Atomic rename
            if (!rename($tempPath, $targetPath)) {
                unlink($tempPath);
                $results['failed'][] = $filePath;
                $results['errors'][] = "Failed to apply file: {$filePath}";
                $results['success'] = false;
                continue;
            }

            $results['applied'][] = $filePath;
        }

        return $results;
    }

    /**
     * Run migrations
     */
    public function runMigrations(array $migrations): array
    {
        $results = [
            'success' => true,
            'applied' => [],
            'failed' => [],
            'errors' => []
        ];

        $migrationsPath = $this->projectRoot . '/database/migrations';

        foreach ($migrations as $migrationFile) {
            $migrationPath = $migrationsPath . '/' . $migrationFile;

            if (!file_exists($migrationPath)) {
                $results['failed'][] = $migrationFile;
                $results['errors'][] = "Migration file not found: {$migrationFile}";
                $results['success'] = false;
                continue;
            }

            // Validate migration SQL
            $sql = file_get_contents($migrationPath);
            $validationErrors = $this->validator->validateMigrationSQL($sql);
            if (!empty($validationErrors)) {
                $results['failed'][] = $migrationFile;
                $results['errors'] = array_merge($results['errors'], $validationErrors);
                $results['success'] = false;
                continue;
            }

            // Execute migration in transaction
            $this->db->begin_transaction();
            try {
                // Split SQL by semicolons and execute
                $statements = array_filter(array_map('trim', explode(';', $sql)));
                foreach ($statements as $statement) {
                    if (empty($statement)) {
                        continue;
                    }
                    if (!$this->db->query($statement)) {
                        throw new \Exception("Migration failed: " . $this->db->error);
                    }
                }
                $this->db->commit();
                $results['applied'][] = $migrationFile;
            } catch (\Exception $e) {
                $this->db->rollback();
                $results['failed'][] = $migrationFile;
                $results['errors'][] = $e->getMessage();
                $results['success'] = false;
            }
        }

        return $results;
    }

    /**
     * Install update from manifest
     */
    public function install(array $manifest, string $currentVersion, ?int $adminUserId = null): array
    {
        $startTime = time();
        $targetVersion = $manifest['version'];
        $updateType = $manifest['update_type'] ?? 'patch';

        // Create update log
        $logId = $this->createUpdateLog($currentVersion, $targetVersion, $updateType, $adminUserId);

        try {
            // Update status to in_progress
            $this->updateLogStatus($logId, 'in_progress');

            // Run pre-flight checks
            $preFlightResults = $this->runPreFlightChecks($manifest);
            if (!empty($preFlightResults['errors'])) {
                $errorMsg = implode('; ', $preFlightResults['errors']);
                $this->updateLogStatus($logId, 'failed', $errorMsg);
                return [
                    'success' => false,
                    'log_id' => $logId,
                    'errors' => $preFlightResults['errors']
                ];
            }

            // Download and stage files
            $downloadResults = $this->downloadAndStageFiles($manifest, $targetVersion);
            if (!$downloadResults['success']) {
                $errorMsg = implode('; ', $downloadResults['errors']);
                $this->updateLogStatus($logId, 'failed', $errorMsg);
                return [
                    'success' => false,
                    'log_id' => $logId,
                    'errors' => $downloadResults['errors']
                ];
            }

            // Create backup
            $filePaths = array_column($manifest['files'] ?? [], 'path');
            $backupLocation = $this->createBackup($currentVersion, $filePaths);

            // Apply files
            $applyResults = $this->applyFiles($targetVersion, $filePaths);
            if (!$applyResults['success']) {
                // Rollback: restore from backup
                $this->rollbackFromBackup($backupLocation, $filePaths);
                $errorMsg = implode('; ', $applyResults['errors']);
                $this->updateLogStatus($logId, 'failed', $errorMsg);
                return [
                    'success' => false,
                    'log_id' => $logId,
                    'errors' => $applyResults['errors']
                ];
            }

            // Run migrations
            $migrationsApplied = [];
            if (!empty($manifest['migrations'])) {
                $migrationResults = $this->runMigrations($manifest['migrations']);
                $migrationsApplied = $migrationResults['applied'];
                if (!$migrationResults['success']) {
                    // Rollback files
                    $this->rollbackFromBackup($backupLocation, $filePaths);
                    $errorMsg = implode('; ', $migrationResults['errors']);
                    $this->updateLogStatus($logId, 'failed', $errorMsg);
                    return [
                        'success' => false,
                        'log_id' => $logId,
                        'errors' => $migrationResults['errors']
                    ];
                }
            }

            // Update version
            $this->settings->set('app_version', $targetVersion);

            // Update log with success
            $executionTime = time() - $startTime;
            $this->updateLogDetails($logId, $applyResults['applied'], $migrationsApplied, $executionTime, $backupLocation);
            $this->updateLogStatus($logId, 'success');
            // Mark rollback as available
            $this->db->query("UPDATE update_logs SET rollback_available = TRUE WHERE id = {$logId}");

            return [
                'success' => true,
                'log_id' => $logId,
                'files_updated' => $applyResults['applied'],
                'migrations_applied' => $migrationsApplied,
                'backup_location' => $backupLocation
            ];

        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            $this->updateLogStatus($logId, 'failed', $errorMsg);
            return [
                'success' => false,
                'log_id' => $logId,
                'errors' => [$errorMsg]
            ];
        }
    }

    /**
     * Rollback from backup
     */
    private function rollbackFromBackup(string $backupPath, array $files): void
    {
        foreach ($files as $filePath) {
            $backupFilePath = $backupPath . '/' . $filePath;
            $targetPath = $this->projectRoot . '/' . $filePath;

            if (file_exists($backupFilePath)) {
                // Create target directory if needed
                $targetDir = dirname($targetPath);
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }

                // Restore file
                copy($backupFilePath, $targetPath);
            } elseif (file_exists($targetPath)) {
                // File didn't exist in backup, remove it
                unlink($targetPath);
            }
        }
    }

    /**
     * Perform rollback for a specific update log entry
     */
    public function rollback(int $logId): array
    {
        // Get update log
        $stmt = $this->db->prepare("SELECT * FROM update_logs WHERE id = ? AND rollback_available = TRUE");
        $stmt->bind_param('i', $logId);
        $stmt->execute();
        $result = $stmt->get_result();
        $log = $result->fetch_assoc();

        if (!$log) {
            return [
                'success' => false,
                'errors' => ['Update log not found or rollback not available']
            ];
        }

        $backupPath = $log['backup_location'];
        $filesUpdated = json_decode($log['files_updated'], true) ?? [];

        if (empty($backupPath) || !is_dir($backupPath)) {
            return [
                'success' => false,
                'errors' => ['Backup directory not found']
            ];
        }

        try {
            // Restore files from backup
            $this->rollbackFromBackup($backupPath, $filesUpdated);

            // Restore version
            $this->settings->set('app_version', $log['version_from']);

            // Update log status
            $this->updateLogStatus($logId, 'rolled_back');

            return [
                'success' => true,
                'message' => 'Rollback completed successfully'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'errors' => [$e->getMessage()]
            ];
        }
    }

    /**
     * Get update history
     */
    public function getUpdateHistory(int $limit = 10): array
    {
        $stmt = $this->db->prepare("
            SELECT ul.*, u.username 
            FROM update_logs ul
            LEFT JOIN users u ON ul.admin_user_id = u.id
            ORDER BY ul.started_at DESC
            LIMIT ?
        ");
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $history = [];
        while ($row = $result->fetch_assoc()) {
            $history[] = $row;
        }
        
        return $history;
    }
}

