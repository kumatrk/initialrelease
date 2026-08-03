<?php
/**
 * Verify Production Security Settings
 * 
 * This script checks various security configurations before production deployment.
 * 
 * Usage: php scripts/verify-production-security.php
 */

echo "=== Production Security Verification ===\n\n";

$errors = [];
$warnings = [];
$success = [];

// Check 1: config.php exists and is not in git
echo "1. Checking config.php git status...\n";
$configPath = __DIR__ . '/../config/config.php';
if (file_exists($configPath)) {
    $gitCheck = shell_exec('git check-ignore ' . escapeshellarg($configPath) . ' 2>&1');
    if (empty(trim($gitCheck))) {
        $errors[] = "config/config.php is NOT in .gitignore!";
    } else {
        $success[] = "config/config.php is properly ignored by git";
    }
    
    // Check file permissions (Unix-like systems)
    if (PHP_OS_FAMILY !== 'Windows') {
        $perms = fileperms($configPath);
        $octalPerms = substr(sprintf('%o', $perms), -4);
        if ($octalPerms !== '0600' && $octalPerms !== '0400') {
            $warnings[] = "config/config.php permissions are {$octalPerms} (should be 0600 for security)";
        } else {
            $success[] = "config/config.php has secure permissions ({$octalPerms})";
        }
    }
} else {
    $warnings[] = "config/config.php does not exist (create from config.php.example)";
}

// Check 2: .htaccess files exist
echo "2. Checking .htaccess files...\n";
$htaccessRoot = __DIR__ . '/../.htaccess';
$htaccessPublic = __DIR__ . '/../public/.htaccess';

if (file_exists($htaccessRoot)) {
    $content = file_get_contents($htaccessRoot);
    if (strpos($content, 'config') !== false || strpos($content, 'FilesMatch') !== false) {
        $success[] = "Root .htaccess has protection rules";
    } else {
        $warnings[] = "Root .htaccess may not have config protection rules";
    }
} else {
    $warnings[] = "Root .htaccess file not found";
}

if (file_exists($htaccessPublic)) {
    $content = file_get_contents($htaccessPublic);
    if (strpos($content, 'config.php') !== false || strpos($content, 'FilesMatch') !== false) {
        $success[] = "Public .htaccess has protection rules";
    } else {
        $warnings[] = "Public .htaccess may not have config.php protection rules";
    }
} else {
    $warnings[] = "Public .htaccess file not found";
}

// Check 3: .env file is in .gitignore
echo "3. Checking .gitignore...\n";
$gitignore = __DIR__ . '/../.gitignore';
if (file_exists($gitignore)) {
    $content = file_get_contents($gitignore);
    if (strpos($content, 'config.php') !== false) {
        $success[] = ".gitignore includes config.php";
    } else {
        $errors[] = ".gitignore does NOT include config.php";
    }
    if (strpos($content, '.env') !== false) {
        $success[] = ".gitignore includes .env";
    } else {
        $warnings[] = ".gitignore may not include .env patterns";
    }
} else {
    $errors[] = ".gitignore file not found";
}

// Check 4: Check if config.php is tracked in git
echo "4. Checking git tracking...\n";
$gitTracked = shell_exec('git ls-files config/config.php 2>&1');
if (empty(trim($gitTracked))) {
    $success[] = "config/config.php is NOT tracked in git";
} else {
    $errors[] = "config/config.php IS tracked in git! Run: git rm --cached config/config.php";
}

// Check 5: Check for placeholder IP code
echo "5. Checking for placeholder/test code...\n";
$redirector = __DIR__ . '/../src/Tracking/Redirector.php';
$redirectless = __DIR__ . '/../src/Tracking/RedirectlessTracker.php';

if (file_exists($redirector)) {
    $content = file_get_contents($redirector);
    if (strpos($content, '47.39.54.188') !== false || strpos($content, 'TEMPORARY: Override localhost') !== false) {
        $errors[] = "Redirector.php still contains placeholder IP code!";
    } else {
        $success[] = "Redirector.php is clean (no placeholder IP)";
    }
}

if (file_exists($redirectless)) {
    $content = file_get_contents($redirectless);
    if (strpos($content, '47.39.54.188') !== false || strpos($content, 'TEMPORARY: Override localhost') !== false) {
        $errors[] = "RedirectlessTracker.php still contains placeholder IP code!";
    } else {
        $success[] = "RedirectlessTracker.php is clean (no placeholder IP)";
    }
}

// Check 6: Check config.php.example exists
echo "6. Checking for config template...\n";
$configExample = __DIR__ . '/../config/config.php.example';
if (file_exists($configExample)) {
    $success[] = "config/config.php.example template exists";
} else {
    $warnings[] = "config/config.php.example template not found";
}

// Check 7: No excluded ad account IDs in settings.php (personal data)
echo "7. Checking for personal data in settings.php...\n";
$settingsPath = __DIR__ . '/../views/settings.php';
if (file_exists($settingsPath)) {
    $settingsContent = file_get_contents($settingsPath);
    if (preg_match('/\$\s*excludedAdAccountIds\s*=\s*\[\s*[\'"]\d+[\'"]/', $settingsContent)) {
        $errors[] = "settings.php contains hardcoded excluded ad account IDs (personal data) - remove before release";
    } else {
        $success[] = "settings.php has no hardcoded excluded ad account IDs";
    }
} else {
    $warnings[] = "views/settings.php not found";
}

// Check 8: remove-excluded-ad-accounts.php should not exist
echo "8. Checking for remove-excluded-ad-accounts.php...\n";
$removeScriptPath = __DIR__ . '/../public/remove-excluded-ad-accounts.php';
if (file_exists($removeScriptPath)) {
    $errors[] = "public/remove-excluded-ad-accounts.php exists - DELETE before release (exposes personal data)";
} else {
    $success[] = "remove-excluded-ad-accounts.php properly removed";
}

// Check 9: display_errors should be off in index.php
echo "9. Checking error display in index.php...\n";
$indexPath = __DIR__ . '/../public/index.php';
if (file_exists($indexPath)) {
    $indexContent = file_get_contents($indexPath);
    if (preg_match("/display_errors\s*,\s*['\"]1['\"]/", $indexContent)) {
        $warnings[] = "index.php has display_errors=1 - set to 0 for production";
    } else {
        $success[] = "index.php has display_errors disabled";
    }
} else {
    $warnings[] = "public/index.php not found";
}

// Check 10: .htaccess test/debug blocking
echo "10. Checking .htaccess security rules...\n";
$htaccessPublicPath = __DIR__ . '/../public/.htaccess';
if (file_exists($htaccessPublicPath)) {
    $htaccessContent = file_get_contents($htaccessPublicPath);
    if (preg_match('/FilesMatch.*test-|debug-|check-|diagnose-/', $htaccessContent)) {
        $success[] = "public/.htaccess blocks test/debug/check/diagnose scripts";
    } else {
        $warnings[] = "public/.htaccess may not block test/debug scripts - verify FilesMatch rules";
    }
} else {
    $warnings[] = "public/.htaccess not found";
}

// Check 11: config.php must not enable DEBUG_MODE or TRACK_DEBUG
echo "11. Checking config.php for debug flags...\n";
if (file_exists($configPath)) {
    $configContent = file_get_contents($configPath);
    if (preg_match("/define\s*\(\s*['\"]DEBUG_MODE['\"].*true/i", $configContent) ||
        preg_match("/define\s*\(\s*['\"]TRACK_DEBUG['\"].*true/i", $configContent)) {
        $errors[] = "config.php enables DEBUG_MODE or TRACK_DEBUG - disable for production!";
    } else {
        $success[] = "config.php has no debug flags enabled";
    }
} else {
    $success[] = "config.php does not exist (will be created by installer)";
}

// Summary
echo "\n=== Summary ===\n\n";

if (!empty($success)) {
    echo "✅ SUCCESS:\n";
    foreach ($success as $msg) {
        echo "   - $msg\n";
    }
    echo "\n";
}

if (!empty($warnings)) {
    echo "⚠️  WARNINGS:\n";
    foreach ($warnings as $msg) {
        echo "   - $msg\n";
    }
    echo "\n";
}

if (!empty($errors)) {
    echo "❌ ERRORS (must fix before production):\n";
    foreach ($errors as $msg) {
        echo "   - $msg\n";
    }
    echo "\n";
    exit(1);
} else {
    echo "✅ All security checks passed!\n";
    exit(0);
}

