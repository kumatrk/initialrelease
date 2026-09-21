<?php

declare(strict_types=1);

namespace SimpleKuma\Installer;

/**
 * Detects partial or complete installation state from disk + database.
 */
class InstallStateDetector
{
    public const STEP_REQUIREMENTS = 'requirements';
    public const STEP_DATABASE = 'database';
    public const STEP_CONFIG = 'config';
    public const STEP_MIGRATIONS = 'migrations';
    public const STEP_ADMIN = 'admin';
    public const STEP_COMPLETE = 'complete';

    private string $projectRoot;
    private string $configPath;
    private string $migrationsPath;

    public function __construct(?string $projectRoot = null)
    {
        $this->projectRoot = $projectRoot ?? dirname(__DIR__, 2);
        $this->configPath = $this->projectRoot . '/config/config.php';
        $this->migrationsPath = $this->projectRoot . '/database/migrations';
    }

    /**
     * @return array{
     *   hasValidConfig: bool,
     *   dbConnects: bool,
     *   hasMigrationsTable: bool,
     *   appliedMigrationCount: int,
     *   expectedMigrationCount: int,
     *   pendingMigrationCount: int,
     *   userCount: int,
     *   adminWithRole: bool,
     *   isComplete: bool,
     *   suggestedStep: string,
     *   resumeMessage: string|null
     * }
     */
    public function detect(): array
    {
        $writer = new ConfigWriter($this->configPath);
        $hasValidConfig = $writer->hasValidConfig();
        $expectedMigrationCount = $this->countMigrationFiles();

        $state = [
            'hasValidConfig' => $hasValidConfig,
            'dbConnects' => false,
            'hasMigrationsTable' => false,
            'appliedMigrationCount' => 0,
            'expectedMigrationCount' => $expectedMigrationCount,
            'pendingMigrationCount' => $expectedMigrationCount,
            'userCount' => 0,
            'adminWithRole' => false,
            'isComplete' => false,
            'suggestedStep' => self::STEP_REQUIREMENTS,
            'resumeMessage' => null,
        ];

        if (!$hasValidConfig) {
            $state['resumeMessage'] = 'No valid configuration found. Start with requirements and database setup.';

            return $state;
        }

        $db = $this->openDatabase();
        if ($db === null) {
            $state['suggestedStep'] = self::STEP_DATABASE;
            $state['resumeMessage'] = 'Configuration exists but the database connection failed. Check credentials in config/config.php or re-enter them in the database step.';

            return $state;
        }

        $state['dbConnects'] = true;

        try {
            $state['hasMigrationsTable'] = $this->tableExists($db, 'migrations');
            if ($state['hasMigrationsTable']) {
                $state['appliedMigrationCount'] = $this->countAppliedMigrations($db);
            }

            $state['pendingMigrationCount'] = max(
                0,
                $state['expectedMigrationCount'] - $state['appliedMigrationCount']
            );

            if ($this->tableExists($db, 'users')) {
                $state['userCount'] = $this->countUsers($db);
            }

            if ($this->tableExists($db, 'user_roles') && $this->tableExists($db, 'roles')) {
                $state['adminWithRole'] = $this->hasAnyAdminWithRole($db);
            }
        } finally {
            $db->close();
        }

        if ($state['pendingMigrationCount'] > 0 || !$state['hasMigrationsTable']) {
            $state['suggestedStep'] = self::STEP_MIGRATIONS;
            $state['resumeMessage'] = sprintf(
                'Configuration is ready. %d migration(s) still need to run.',
                $state['pendingMigrationCount'] > 0 ? $state['pendingMigrationCount'] : $state['expectedMigrationCount']
            );

            return $state;
        }

        if ($state['userCount'] === 0) {
            $state['suggestedStep'] = self::STEP_ADMIN;
            $state['resumeMessage'] = 'Database schema is ready. Create your admin account to finish installation.';

            return $state;
        }

        if (!$state['adminWithRole']) {
            $state['suggestedStep'] = self::STEP_ADMIN;
            $state['resumeMessage'] = 'Users exist but no admin role assignment was found. Complete the admin step or assign the admin role manually.';

            return $state;
        }

        $state['isComplete'] = true;
        $state['suggestedStep'] = self::STEP_COMPLETE;
        $state['resumeMessage'] = 'Installation appears complete. You can log in to the dashboard.';

        return $state;
    }

    /**
     * Align PHP session flags with on-disk install progress (for resume after session loss).
     */
    public function syncSessionFlags(array $state): void
    {
        if (!$state['hasValidConfig']) {
            return;
        }

        $_SESSION['requirements_passed'] = true;
        $_SESSION['database_configured'] = true;
        $_SESSION['config_written'] = true;

        if ($state['hasMigrationsTable'] && $state['pendingMigrationCount'] === 0) {
            $_SESSION['migrations_run'] = true;
        }

        if ($state['userCount'] > 0 && $state['adminWithRole']) {
            $_SESSION['admin_created'] = true;
        }
    }

    public function countMigrationFiles(): int
    {
        if (!is_dir($this->migrationsPath)) {
            return 0;
        }

        $files = glob($this->migrationsPath . '/*.sql');
        if ($files === false) {
            return 0;
        }

        $files = array_filter($files, static fn(string $f): bool => strpos(basename($f), 'rollback_') !== 0);

        return count($files);
    }

    private function openDatabase(): ?\mysqli
    {
        if (!defined('DB_HOST')) {
            if (!is_file($this->configPath)) {
                return null;
            }

            try {
                require_once $this->configPath;
            } catch (\Throwable $e) {
                return null;
            }
        }

        if (!defined('DB_HOST') || !defined('DB_USER') || !defined('DB_PASSWORD') || !defined('DB_NAME')) {
            return null;
        }

        mysqli_report(MYSQLI_REPORT_OFF);
        $db = @new \mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

        if ($db->connect_error) {
            return null;
        }

        return $db;
    }

    private function tableExists(\mysqli $db, string $table): bool
    {
        $escaped = $db->real_escape_string($table);
        $result = $db->query("SHOW TABLES LIKE '{$escaped}'");

        if ($result === false) {
            return false;
        }

        $exists = $result->num_rows > 0;
        $result->free();

        return $exists;
    }

    private function countAppliedMigrations(\mysqli $db): int
    {
        $result = $db->query('SELECT COUNT(*) AS cnt FROM migrations');
        if ($result === false) {
            return 0;
        }

        $row = $result->fetch_assoc();
        $result->free();

        return (int) ($row['cnt'] ?? 0);
    }

    private function countUsers(\mysqli $db): int
    {
        $result = $db->query('SELECT COUNT(*) AS cnt FROM users');
        if ($result === false) {
            return 0;
        }

        $row = $result->fetch_assoc();
        $result->free();

        return (int) ($row['cnt'] ?? 0);
    }

    private function hasAnyAdminWithRole(\mysqli $db): bool
    {
        $sql = "SELECT COUNT(*) AS cnt
                FROM user_roles ur
                INNER JOIN roles r ON r.id = ur.role_id
                WHERE r.name = 'admin'
                LIMIT 1";
        $result = $db->query($sql);
        if ($result === false) {
            return false;
        }

        $row = $result->fetch_assoc();
        $result->free();

        return ((int) ($row['cnt'] ?? 0)) > 0;
    }
}
