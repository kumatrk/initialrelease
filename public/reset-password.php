<?php

declare(strict_types=1);

/**
 * Simple KUMA - Reset Password Page
 */

// Check if already installed
if (!file_exists(__DIR__ . '/../config/config.php')) {
    header('Location: install.php');
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/bootstrap_web_paths.php';

$skVersionData = is_file(__DIR__ . '/../version.php') ? include __DIR__ . '/../version.php' : [];
$skAppVersion = is_array($skVersionData) ? (string) ($skVersionData['version'] ?? '1.1.5.2') : '1.1.5.2';

use SimpleKuma\Auth\Auth;
use SimpleKuma\Auth\Csrf;
use SimpleKuma\Auth\LoginGate;
use SimpleKuma\Theme\ThemeRegistry;

// Database connection
$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$auth = new Auth($db);

// Redirect if already logged in
if ($auth->isAuthenticated()) {
    header('Location: ' . APP_BASE_URL . '/index.php');
    exit;
}

$error = '';
$success = '';
$token = $_GET['token'] ?? '';
$tokenValid = false;
$email = '';

// Validate password-reset token first (a valid reset link may bypass the login gate)
if (!empty($token)) {
    $tokenData = $auth->validateResetToken($token);
    if ($tokenData) {
        $tokenValid = true;
        $email = $tokenData['email'];
    } else {
        $error = 'Invalid or expired reset token. Please request a new password reset.';
    }
} else {
    $error = 'No reset token provided. Please check your email for the reset link.';
}

$loginGate = new LoginGate();
if ($loginGate->isEnabled($db)) {
    // Valid reset token may view this page, but must not mint a long-lived gate pass cookie
    if ($tokenValid) {
        // allow through without issuePassCookie()
    } elseif ($loginGate->validateAccess($db)) {
        $loginGate->issuePassCookie();
    } else {
        $loginGate->enforceOrRedirect($db);
    }
}

header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet', true);

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid) {
    if (!Csrf::validate()) {
        $error = Csrf::invalidRequestMessage();
    } else {
    $newPassword = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (empty($newPassword)) {
        $error = 'Password is required';
    } elseif (strlen($newPassword) < 8) {
        $error = 'Password must be at least 8 characters long';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match';
    } else {
        $result = $auth->resetPassword($token, $newPassword);
        
        if ($result['success']) {
            $success = $result['message'];
            $tokenValid = false; // Hide form after success
        } else {
            $error = $result['message'];
        }
    }
    }
}

$db->close();
$themeClientConfig = ThemeRegistry::toClientConfig();
$authLogoDefault = ThemeRegistry::logo(ThemeRegistry::DEFAULT_THEME);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <meta name="googlebot" content="noindex, nofollow, noarchive, nosnippet">
    <script>
    (function () {
        try {
            var themes = <?= json_encode($themeClientConfig, JSON_THROW_ON_ERROR) ?>;
            var t = localStorage.getItem('kuma_theme');
            if (t && themes[t]) {
                document.documentElement.setAttribute('data-theme', t);
                document.documentElement.setAttribute('data-theme-base', themes[t].base === 'dark' ? 'dark' : 'light');
                window.__KUMA_AUTH_THEME__ = themes[t];
            }
        } catch (e) { /* ignore */ }
    })();
    </script>
    <title>Reset password</title>
    <link rel="icon" type="image/x-icon" href="<?= ASSETS_BASE_URL ?>/assets/images/favicon.ico">
    <link rel="stylesheet" href="<?= ASSETS_BASE_URL ?>/assets/css/themes.css?v=<?= rawurlencode($skAppVersion) ?>">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #f5f1e8 0%, #e8e4d8 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 440px;
            overflow: hidden;
        }

        .login-header {
            background: linear-gradient(135deg, #3d5a26 0%, #5a7a3a 100%);
            padding: 30px;
            text-align: center;
            color: #fff;
        }

        .retro-text {
            display: block;
            font-size: 16px;
            font-weight: 600;
            color: #f5f1e8;
            letter-spacing: 1px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }

        .login-body {
            padding: 40px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #333;
            font-size: 14px;
        }

        .form-input {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 15px;
            transition: border-color 0.3s;
        }

        .form-input:focus {
            outline: none;
            border-color: #3d5a26;
        }

        .error-message {
            background: #fff3cd;
            border: 2px solid #ffc107;
            color: #856404;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .success-message {
            background: #d4edda;
            border: 2px solid #28a745;
            color: #155724;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .info-message {
            background: #d1ecf1;
            border: 2px solid #17a2b8;
            color: #0c5460;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .btn-login {
            width: 100%;
            padding: 14px;
            background: #3d5a26;
            color: #f5f1e8;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
            margin-top: 10px;
        }

        .btn-login:hover {
            background: #2d5016;
        }

        .btn-secondary {
            width: 100%;
            padding: 12px;
            background: #f8f9fa;
            color: #666;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            margin-top: 12px;
        }

        .btn-secondary:hover {
            background: #e9ecef;
        }

        .login-footer {
            text-align: center;
            padding-top: 24px;
            border-top: 1px solid #ddd;
            margin-top: 24px;
            font-size: 12px;
            color: #999;
        }

        .password-hint {
            font-size: 12px;
            color: #666;
            margin-top: 4px;
        }
    </style>
</head>
<body class="auth-page">
    <div class="login-container">
        <div class="login-header">
            <div style="margin-bottom: 16px;">
                <img id="auth-logo-img" src="<?= ASSETS_BASE_URL ?>/assets/images/<?= htmlspecialchars($authLogoDefault) ?>" alt="" style="max-height: 140px; height: auto;">
            </div>
            <span class="retro-text">Harder, Better, Faster, Stronger</span>
        </div>

        <div class="login-body">
            <h2 style="color: #3d5a26; margin-bottom: 24px; text-align: center;">Reset Password</h2>

            <?php if ($error): ?>
                <div class="error-message">
                    ⚠️ <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="success-message">
                    ✓ <?= htmlspecialchars($success) ?>
                </div>
                <a href="<?= APP_BASE_URL ?>/login.php" class="btn-secondary">
                    Go to Login →
                </a>
            <?php elseif ($tokenValid): ?>
                <div class="info-message">
                    Reset password for: <strong><?= htmlspecialchars($email) ?></strong>
                </div>

                <form method="post" action="">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                    
                    <div class="form-group">
                        <label class="form-label" for="password">New Password</label>
                        <input type="password" 
                               id="password" 
                               name="password" 
                               class="form-input" 
                               required 
                               autofocus 
                               autocomplete="new-password"
                               minlength="8">
                        <div class="password-hint">Must be at least 8 characters long</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="confirm_password">Confirm New Password</label>
                        <input type="password" 
                               id="confirm_password" 
                               name="confirm_password" 
                               class="form-input" 
                               required 
                               autocomplete="new-password"
                               minlength="8">
                    </div>

                    <button type="submit" class="btn-login">
                        Reset Password
                    </button>
                </form>

                <a href="<?= APP_BASE_URL ?>/login.php" class="btn-secondary">
                    ← Back to Login
                </a>
            <?php else: ?>
                <a href="<?= APP_BASE_URL ?>/forgot-password.php" class="btn-secondary">
                    Request New Reset Link
                </a>
            <?php endif; ?>

            <div class="login-footer">
                V <?= htmlspecialchars($skAppVersion) ?><br>
                <span style="font-size: 10px;">Work is Never Over</span>
            </div>
        </div>
    </div>
    <script>
    (function () {
        var meta = window.__KUMA_AUTH_THEME__;
        if (!meta || !meta.logo) return;
        var img = document.getElementById('auth-logo-img');
        if (img) {
            img.src = <?= json_encode(ASSETS_BASE_URL . '/assets/images/', JSON_THROW_ON_ERROR) ?> + meta.logo;
        }
    })();
    </script>
</body>
</html>

