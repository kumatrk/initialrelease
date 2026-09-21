<?php

declare(strict_types=1);

/**
 * Simple KUMA - Installer Wizard
 * WordPress-style installer for initial setup
 */

// Load Composer autoloader (required for installer classes and migrations)
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoloadPath)) {
    header('Content-Type: text/html; charset=utf-8');
    http_response_code(500);
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;max-width:640px;margin:2rem auto;">';
    echo '<h1>Installer: missing dependencies</h1>';
    echo '<p><code>vendor/autoload.php</code> was not found. Upload the full package (including the <code>vendor/</code> folder) or run <code>composer install</code> in the project root.</p>';
    echo '</body></html>';
    exit;
}
require_once $autoloadPath;

header('X-Robots-Tag: noindex, nofollow', true);

use SimpleKuma\Installer\InstallStateDetector;
use SimpleKuma\Installer\InstallerController;
use SimpleKuma\Installer\InstallerLock;
use SimpleKuma\Installer\WebPathResolver;

// Allow the completion screen to render even when installation is complete.
// Otherwise production would show "Already Installed" instead of the success page.
$requestedStep = is_string($_GET['step'] ?? null) ? $_GET['step'] : '';

// Block installer on production only when install is fully complete (not mid-wizard)
if ($requestedStep !== 'complete' && class_exists(InstallerLock::class) && InstallerLock::shouldBlockInstaller()) {
    $loginUrl = InstallerLock::getLoginUrl();
    $installerAssetsBase = WebPathResolver::getPublicWebPath();
    $message = 'Simple KUMA is already installed. Use the login page to access your dashboard.';
    require __DIR__ . '/../views/installer/locked.php';
    exit;
}

// Resume partial installs (e.g. config written but migrations not run yet)
if ($requestedStep === '') {
    $resumeState = (new InstallStateDetector())->detect();
    if ($resumeState['hasValidConfig'] && !$resumeState['isComplete'] && !empty($resumeState['suggestedStep'])) {
        header('Location: install.php?step=' . rawurlencode($resumeState['suggestedStep']));
        exit;
    }
}

try {
    $controller = new InstallerController();
    $controller->run();
} catch (\Throwable $e) {
    error_log('Installer: Fatal error: ' . $e->getMessage());
    error_log('Installer: at ' . $e->getFile() . ':' . $e->getLine());
    error_log('Installer: Stack trace: ' . $e->getTraceAsString());

    $isLocal = class_exists(\SimpleKuma\Installer\InstallerLock::class)
        && \SimpleKuma\Installer\InstallerLock::isLocalHost();
    $showDebug = $isLocal
        || (isset($_GET['installer_debug']) && $_GET['installer_debug'] === '1');

    header('Content-Type: text/html; charset=utf-8');
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Installer Error</title>';
    echo '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;max-width:720px;margin:2rem auto;padding:0 1rem;color:#333;}';
    echo '.box{background:#f8d7da;border:1px solid #f5c6cb;border-left:4px solid #721c24;padding:1rem;border-radius:4px;}';
    echo 'code{background:#f5f5f5;padding:2px 6px;border-radius:3px;} pre{overflow:auto;font-size:12px;}</style></head><body>';
    echo '<h1>Installer error</h1><div class="box"><p><strong>'
        . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
        . '</strong></p>';
    echo '<p>Step: <code>' . htmlspecialchars($_GET['step'] ?? 'requirements', ENT_QUOTES, 'UTF-8') . '</code></p>';
    echo '<p>Common causes: PHP version below 8.2, incomplete upload (missing <code>vendor/</code>), database credentials, or MySQL/MariaDB version below requirements.</p>';
    if ($showDebug) {
        echo '<p><small>' . htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8')
            . ':' . (int) $e->getLine() . '</small></p>';
        echo '<pre>' . htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8') . '</pre>';
    } else {
        echo '<p>Add <code>&amp;installer_debug=1</code> to this URL for a stack trace, or check <code>error_log</code> (search for <code>Installer:</code>).</p>';
    }
    echo '<p><a href="install.php?step=' . htmlspecialchars($_GET['step'] ?? 'requirements', ENT_QUOTES, 'UTF-8') . '">← Back to installer</a></p>';
    echo '</div></body></html>';
    exit;
}
