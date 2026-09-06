<?php

declare(strict_types=1);

/**
 * Apply a Simple Kuma release package over an EXISTING install.
 *
 * Preserves: config/config.php, storage data, database, campaigns, legacy /km/ links.
 *
 * Usage (run from existing install root, or pass --target):
 *   php scripts/apply-release-upgrade.php --source=/path/to/extracted/v1.1.5.2 [--dry-run|--apply] [--migrations] [--json]
 *
 * --source may be a directory (extracted zip) or a .zip file (extracted to a temp dir).
 * Default mode is --dry-run (no writes). Use --apply to copy files. Use --migrations after --apply.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$options = getopt('', ['source:', 'target:', 'apply', 'migrations', 'json', 'help']);

$applyFiles = isset($options['apply']);
$runMigrations = isset($options['migrations']);
$migrationsOnly = $runMigrations && !$applyFiles;
$jsonOut = isset($options['json']);

if (isset($options['help'])) {
    echo <<<HELP
Simple Kuma — apply release upgrade (existing install)

  php scripts/apply-release-upgrade.php [options]

Options:
  --source=PATH   Extracted release folder or .zip (required for file upgrade)
  --target=PATH   Live install root (default: parent of scripts/)
  --apply         Copy release files over the install (never touches config.php)
  --migrations    Run pending DB migrations (--apply or alone after files copied)
  --json          Machine-readable summary on stdout
  --help          This message

Examples:
  Preview:  php scripts/apply-release-upgrade.php --source=/tmp/sk-v115
  Apply:    php scripts/apply-release-upgrade.php --source=/tmp/sk-v115 --apply
  Migrate:  php scripts/apply-release-upgrade.php --migrations
  All:      php scripts/apply-release-upgrade.php --source=/path/to.zip --apply --migrations

Preserves: config/config.php, storage/logs, storage/cache, storage/google_ads_configs
Skips on production: public/install.php when INSTALLED is set (non-localhost)

See UPGRADE.md for the full agent runbook.

HELP;
    exit(0);
}

if (!$migrationsOnly && empty($options['source'])) {
    fail('Missing --source. Use --help for usage.', $jsonOut);
}

$previewOnly = !empty($options['source']) && !$applyFiles && !$runMigrations;

$installRoot = isset($options['target'])
    ? realpath($options['target'])
    : realpath(dirname(__DIR__));

if ($installRoot === false) {
    fail('Could not resolve install root.', $jsonOut);
}

$configPath = $installRoot . '/config/config.php';
if (!is_file($configPath)) {
    fail("Not an existing install (missing config/config.php): {$installRoot}", $jsonOut);
}
require_once $installRoot . '/vendor/autoload.php';

try {
    $releaseUpgrader = new \SimpleKuma\Update\ReleaseUpgrader($installRoot);
} catch (Throwable $e) {
    fail($e->getMessage(), $jsonOut);
}

$sourceRoot = null;
$releaseVersion = null;
if (!empty($options['source'])) {
    $resolvedSource = resolveSourceRoot($options['source']);
    $sourceRoot = $resolvedSource === null
        ? null
        : \SimpleKuma\Update\ReleaseUpgrader::locateSourceRoot($resolvedSource);
    if ($sourceRoot === null) {
        fail('Invalid --source: ' . $options['source'], $jsonOut);
    }
    $releaseVersion = \SimpleKuma\Update\ReleaseUpgrader::readVersion($sourceRoot);
}

$currentVersion = \SimpleKuma\Update\ReleaseUpgrader::readVersion($installRoot) ?? 'unknown';

$mode = $previewOnly ? 'preview' : ($applyFiles ? ($runMigrations ? 'apply+migrations' : 'apply') : 'migrations-only');

$report = [
    'ok' => true,
    'mode' => $mode,
    'install_root' => $installRoot,
    'source_root' => $sourceRoot,
    'version_from' => $currentVersion,
    'version_to' => $releaseVersion,
    'config_preserved' => true,
    'files_copied' => [],
    'files_skipped' => [],
    'errors' => [],
    'warnings' => [],
    'migrations' => [],
    'next_steps' => [],
];

if ($releaseVersion === null) {
    $report['warnings'][] = 'Could not read version from source version.php';
}

$configHashBefore = hash_file('sha256', $configPath);

$preview = ($applyFiles || $previewOnly) && $sourceRoot !== null
    ? $releaseUpgrader->preview($sourceRoot)
    : ['ok' => true, 'files' => [], 'skipped' => [], 'errors' => []];
$filesToCopy = $preview['files'];
$report['files_skipped'] = $preview['skipped'];
if (!$preview['ok']) {
    $report['ok'] = false;
    $report['errors'] = array_merge($report['errors'], $preview['errors']);
}

if ($previewOnly) {
    $report['files_would_copy'] = count($filesToCopy);
    $report['next_steps'][] = 'Re-run with --apply to copy files.';
    $report['next_steps'][] = 'Then: --apply --migrations (or --migrations alone).';
    outputReport($report, $jsonOut);
    exit($report['ok'] ? 0 : 1);
}

if ($applyFiles) {
    $backupRoot = $installRoot . '/storage/updates/backups/cli-'
        . date('Ymd-His') . '-' . preg_replace('/[^0-9A-Za-z.-]/', '-', $currentVersion);
    $applyResult = $releaseUpgrader->apply($sourceRoot, $backupRoot);
    $report['ok'] = $applyResult['ok'];
    $report['files_copied'] = $applyResult['files_copied'];
    $report['files_skipped'] = $applyResult['files_skipped'];
    $report['config_preserved'] = $applyResult['config_preserved'];
    $report['errors'] = array_merge($report['errors'], $applyResult['errors']);
    if ($applyResult['ok'] && $applyResult['files_copied'] !== []) {
        \SimpleKuma\Update\TreePermissionNormalizer::normalizeRelativeFiles(
            $installRoot,
            $applyResult['files_copied']
        );
    }
}

if ($applyFiles) {
$configHashAfter = hash_file('sha256', $configPath);
if ($configHashBefore !== $configHashAfter) {
    $report['ok'] = false;
    $report['config_preserved'] = false;
    $report['errors'][] = 'config/config.php hash changed — aborting migrations. Restore config from backup.';
    outputReport($report, $jsonOut);
    exit(1);
}
}

if ($runMigrations) {
    require_once $installRoot . '/vendor/autoload.php';
    require_once $configPath;

    $db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    if ($db->connect_error) {
        $report['ok'] = false;
        $report['errors'][] = 'Database connection failed: ' . $db->connect_error;
    } else {
        $db->query("SET time_zone = '+00:00'");
        $runner = new \SimpleKuma\Database\MigrationRunner($db);
        if ($runner->run()) {
            $report['migrations'] = $runner->getAppliedMigrations();
        } else {
            $report['ok'] = false;
            $report['errors'] = array_merge($report['errors'], $runner->getErrors());
        }
        $db->close();
    }
} elseif ($applyFiles) {
    $report['next_steps'][] = 'Run pending migrations: php scripts/apply-release-upgrade.php --migrations';
}

$report['next_steps'][] = 'Verify: php scripts/verify-production-security.php';
$report['next_steps'][] = 'Confirm admin loads and legacy /km/{campaign_key} still redirects.';
$report['next_steps'][] = 'Optional: add CLICK_URL_STYLE defines to config.php for go.php?k= new links (see UPGRADE.md).';
$report['next_steps'][] = 'If domain was Safe Browsing flagged: move tracking to a subdomain and request review.';

outputReport($report, $jsonOut);
exit($report['ok'] ? 0 : 1);

// --- helpers ---

function fail(string $message, bool $json): void
{
    if ($json) {
        echo json_encode(['ok' => false, 'errors' => [$message]], JSON_PRETTY_PRINT) . "\n";
    } else {
        fwrite(STDERR, "ERROR: {$message}\n");
    }
    exit(1);
}

function resolveSourceRoot(string $source): ?string
{
    if (is_dir($source)) {
        return realpath($source) ?: null;
    }
    if (is_file($source) && str_ends_with(strtolower($source), '.zip')) {
        $tmp = sys_get_temp_dir() . '/sk-upgrade-' . bin2hex(random_bytes(4));
        if (!mkdir($tmp, 0755, true)) {
            return null;
        }
        register_shutdown_function(static fn () => removeTemporaryDirectory($tmp));
        $zip = new ZipArchive();
        if ($zip->open($source) !== true) {
            return null;
        }
        $zip->extractTo($tmp);
        $zip->close();
        \SimpleKuma\Update\TreePermissionNormalizer::normalizeTree($tmp);
        return realpath($tmp) ?: null;
    }
    return null;
}

function removeTemporaryDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (scandir($directory) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path) && !is_link($path)) {
            removeTemporaryDirectory($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($directory);
}

function outputReport(array $report, bool $json): void
{
    if ($json) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        return;
    }

    echo "Simple Kuma release upgrade\n";
    echo str_repeat('-', 40) . "\n";
    echo "Mode:          {$report['mode']}\n";
    echo "Install:       {$report['install_root']}\n";
    echo "Source:        " . ($report['source_root'] ?? 'n/a') . "\n";
    echo "Version:       {$report['version_from']} -> " . ($report['version_to'] ?? 'n/a') . "\n";
    echo "Config safe:   " . ($report['config_preserved'] ? 'yes (protected)' : 'NO') . "\n";

    if (isset($report['files_would_copy'])) {
        echo "Would copy:    {$report['files_would_copy']} files\n";
    } else {
        echo "Copied:        " . count($report['files_copied']) . " files\n";
        echo "Skipped:       " . count($report['files_skipped']) . " paths\n";
    }

    if ($report['migrations'] !== []) {
        echo "Migrations:    " . implode(', ', $report['migrations']) . "\n";
    }

    foreach ($report['warnings'] as $w) {
        echo "WARN: {$w}\n";
    }
    foreach ($report['errors'] as $e) {
        echo "ERROR: {$e}\n";
    }
    if ($report['next_steps'] !== []) {
        echo "\nNext steps:\n";
        foreach ($report['next_steps'] as $i => $step) {
            echo '  ' . ($i + 1) . ". {$step}\n";
        }
    }
    echo "\nStatus: " . ($report['ok'] ? 'OK' : 'FAILED') . "\n";
}
