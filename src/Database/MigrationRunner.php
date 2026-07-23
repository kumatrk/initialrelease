<?php

declare(strict_types=1);

namespace SimpleKuma\Database;

use SimpleKuma\Database\Migrations\RemoveFbclidFromTrafficSources;
use mysqli;
use Throwable;

/**
 * Migration Runner
 * Runs database migrations in order and tracks which ones have been applied
 */
class MigrationRunner
{
    private mysqli $db;
    private array $errors = [];
    private array $applied = [];
    private string $migrationsPath;

    public function __construct(mysqli $db, ?string $migrationsPath = null)
    {
        $this->db = $db;
        $this->migrationsPath = $migrationsPath ?? dirname(__DIR__, 2) . '/database/migrations';
    }

    /**
     * Run all pending migrations
     */
    public function run(): bool
    {
        $this->errors = [];
        $this->applied = [];

        $versionError = DatabaseCompatibility::validateConnection($this->db);
        if ($versionError !== null) {
            $this->errors[] = $versionError;
            return false;
        }

        // Create migrations tracking table if it doesn't exist
        if (!$this->createMigrationsTable()) {
            return false;
        }

        // Get list of migration files
        $migrations = $this->getMigrationFiles();

        if (empty($migrations)) {
            $this->errors[] = "No migration files found in {$this->migrationsPath}";
            return false;
        }

        $pending = $this->getPendingMigrations();
        if ($pending === []) {
            return true;
        }

        foreach ($pending as $migration) {
            if (!$this->runMigration($migration)) {
                return false;
            }

            $this->applied[] = $migration;
        }

        return true;
    }

    /**
     * Migration files on disk that are not yet recorded in the migrations table.
     *
     * @return list<string>
     */
    public function getPendingMigrations(): array
    {
        if (!$this->createMigrationsTable()) {
            return [];
        }

        $files = $this->getMigrationFiles();
        if ($files === []) {
            return [];
        }

        $applied = $this->fetchAppliedMigrationsFromDb();
        $pending = [];
        foreach ($files as $migration) {
            if (!in_array($migration, $applied, true)) {
                $pending[] = $migration;
            }
        }

        return $pending;
    }

    /**
     * Status snapshot for the Settings → Updates UI.
     *
     * @return array{
     *   ok: bool,
     *   up_to_date: bool,
     *   pending: list<string>,
     *   pending_count: int,
     *   applied_count: int,
     *   total_files: int,
     *   error: ?string
     * }
     */
    public function getStatus(): array
    {
        $versionError = DatabaseCompatibility::validateConnection($this->db);
        if ($versionError !== null) {
            return [
                'ok' => false,
                'up_to_date' => false,
                'pending' => [],
                'pending_count' => 0,
                'applied_count' => 0,
                'total_files' => 0,
                'error' => $versionError,
            ];
        }

        if (!$this->createMigrationsTable()) {
            return [
                'ok' => false,
                'up_to_date' => false,
                'pending' => [],
                'pending_count' => 0,
                'applied_count' => 0,
                'total_files' => 0,
                'error' => $this->errors[0] ?? 'Could not create migrations tracking table.',
            ];
        }

        $files = $this->getMigrationFiles();
        if ($files === []) {
            return [
                'ok' => false,
                'up_to_date' => false,
                'pending' => [],
                'pending_count' => 0,
                'applied_count' => 0,
                'total_files' => 0,
                'error' => "No migration files found in {$this->migrationsPath}",
            ];
        }

        $applied = $this->fetchAppliedMigrationsFromDb();
        $pending = [];
        foreach ($files as $migration) {
            if (!in_array($migration, $applied, true)) {
                $pending[] = $migration;
            }
        }

        return [
            'ok' => true,
            'up_to_date' => $pending === [],
            'pending' => $pending,
            'pending_count' => count($pending),
            'applied_count' => count($applied),
            'total_files' => count($files),
            'error' => null,
        ];
    }

    /**
     * Create migrations tracking table
     */
    private function createMigrationsTable(): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(255) NOT NULL UNIQUE,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_migration (migration)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if (!$this->db->query($sql)) {
            $this->errors[] = "Failed to create migrations table: " . $this->db->error;
            return false;
        }

        return true;
    }

    /**
     * Get list of migration files
     */
    private function getMigrationFiles(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }

        $files = glob($this->migrationsPath . '/*.sql');
        if ($files === false) {
            return [];
        }

        // Exclude rollback scripts - only run forward migrations
        $files = array_filter($files, fn($f) => strpos(basename($f), 'rollback_') !== 0);

        // Sort by filename (should be prefixed with timestamp)
        sort($files);

        // Return just the filenames
        return array_map('basename', $files);
    }

    /**
     * Fetch already applied migrations from database
     */
    private function fetchAppliedMigrationsFromDb(): array
    {
        $result = $this->db->query("SELECT migration FROM migrations ORDER BY id");
        
        if (!$result) {
            return [];
        }

        $applied = [];
        while ($row = $result->fetch_assoc()) {
            $applied[] = $row['migration'];
        }

        return $applied;
    }

    /**
     * Run a single migration
     */
    private function runMigration(string $migration): bool
    {
        if ($migration === '047_remove_fbclid_from_traffic_sources.sql') {
            return $this->runPhpMigration(
                $migration,
                static fn (mysqli $db): ?string => RemoveFbclidFromTrafficSources::run($db)
            );
        }

        $filePath = $this->migrationsPath . '/' . $migration;

        if (!file_exists($filePath)) {
            $this->errors[] = "Migration file not found: {$migration}";
            return false;
        }

        $sql = file_get_contents($filePath);
        if ($sql === false) {
            $this->errors[] = "Failed to read migration file: {$migration}";
            return false;
        }

        // Split SQL into individual statements
        $statements = $this->splitSqlStatements($sql);

        // Execute each statement
        foreach ($statements as $statement) {
            if (trim($statement) === '') {
                continue;
            }

            try {
                $ok = $this->db->query($statement);
            } catch (Throwable $e) {
                $this->errors[] = $this->formatMigrationFailure($migration, $statement, $e->getMessage());
                return false;
            }

            if (!$ok) {
                $this->errors[] = $this->formatMigrationFailure($migration, $statement, $this->db->error);
                return false;
            }
        }

        // Record migration as applied
        $stmt = $this->db->prepare("INSERT INTO migrations (migration) VALUES (?)");
        $stmt->bind_param('s', $migration);
        
        if (!$stmt->execute()) {
            $this->errors[] = "Failed to record migration '{$migration}': " . $this->db->error;
            return false;
        }

        return true;
    }

    /**
     * @param callable(mysqli): ?string $runner Returns error message or null on success
     */
    private function runPhpMigration(string $migration, callable $runner): bool
    {
        try {
            $error = $runner($this->db);
        } catch (Throwable $e) {
            $this->errors[] = "Migration '{$migration}' failed: " . $e->getMessage();
            return false;
        }

        if ($error !== null) {
            $this->errors[] = "Migration '{$migration}' failed: {$error}";
            return false;
        }

        $stmt = $this->db->prepare('INSERT INTO migrations (migration) VALUES (?)');
        if ($stmt === false) {
            $this->errors[] = "Failed to record migration '{$migration}': " . $this->db->error;
            return false;
        }

        $stmt->bind_param('s', $migration);
        if (!$stmt->execute()) {
            $this->errors[] = "Failed to record migration '{$migration}': " . $stmt->error;
            $stmt->close();
            return false;
        }

        $stmt->close();
        return true;
    }

    private function formatMigrationFailure(string $migration, string $statement, string $error): string
    {
        $preview = preg_replace('/\s+/', ' ', trim($statement));
        if ($preview !== null && strlen($preview) > 120) {
            $preview = substr($preview, 0, 117) . '...';
        }

        $hint = '';
        if (stripos($error, 'syntax') !== false && stripos($migration, '050_') !== false) {
            $hint = ' Ensure MariaDB/MySQL is 10.3+/8.0+ and re-run after updating to the latest package.';
        }

        return "Migration '{$migration}' failed: {$error}"
            . ($preview ? " [statement: {$preview}]" : '')
            . $hint;
    }

    /**
     * Split SQL file into individual statements (respects semicolons inside quoted strings).
     */
    private function splitSqlStatements(string $sql): array
    {
        $sql = preg_replace('/--[^\n]*\n/', "\n", $sql) ?? $sql;
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql) ?? $sql;

        $statements = [];
        $current = '';
        $inString = false;
        $stringChar = '';
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if (!$inString && ($char === "'" || $char === '"')) {
                $inString = true;
                $stringChar = $char;
                $current .= $char;
                continue;
            }

            if ($inString) {
                $current .= $char;
                if ($char === $stringChar) {
                    // MySQL escapes quotes by doubling: ''
                    if ($stringChar === "'" && ($i + 1) < $length && $sql[$i + 1] === "'") {
                        $current .= $sql[++$i];
                        continue;
                    }
                    $inString = false;
                    $stringChar = '';
                }
                continue;
            }

            if ($char === ';') {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $trimmed = trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    /**
     * Get errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get applied migrations from this run
     */
    public function getAppliedMigrations(): array
    {
        return $this->applied;
    }
}

