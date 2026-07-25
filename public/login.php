<?php

declare(strict_types=1);

/**
 * Simple KUMA - Login Page
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
use SimpleKuma\Theme\ThemeRegistry;

// Database connection
$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$auth = new Auth($db);

// Redirect if already logged in
if ($auth->isAuthenticated()) {
    header('Location: ' . APP_BASE_URL . '/index.php');
    exit;
}

header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet', true);

$error = '';

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate()) {
        $error = Csrf::invalidRequestMessage();
    } else {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    $result = $auth->login($username, $password, $remember);

    if ($result['success']) {
        header('Location: ' . APP_BASE_URL . '/index.php');
        exit;
    } else {
        $error = $result['message'];
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
    <title>Sign in</title>
    <link rel="icon" type="image/x-icon" href="<?= ASSETS_BASE_URL ?>/assets/images/favicon.ico">
    <link rel="stylesheet" href="<?= ASSETS_BASE_URL ?>/assets/css/themes.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #2d5016 0%, #4a7c2c 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            background: #f5f1e8;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            max-width: 400px;
            width: 100%;
            overflow: hidden;
        }

        .login-header {
            background: #3d5a26;
            color: #f5f1e8;
            padding: 40px 30px;
            text-align: center;
        }

        .login-header .bear-icon {
            font-size: 64px;
            margin-bottom: 16px;
            animation: bounce 2s ease-in-out infinite;
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        .login-header h1 {
            font-size: 28px;
            margin-bottom: 8px;
        }

        .login-header p {
            font-size: 14px;
            opacity: 0.9;
        }

        .retro-text {
            font-family: 'Courier New', monospace;
            font-size: 15px;
            font-weight: bold;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: #e8f5e9;
            text-shadow: 
                1px 1px 2px rgba(0, 0, 0, 0.8),
                0 0 3px rgba(200, 255, 200, 0.6);
            margin-top: 12px;
            display: block;
            white-space: nowrap;
        }

        @media (max-width: 480px) {
            .retro-text {
                font-size: 11px;
                letter-spacing: 1px;
                white-space: normal;
                line-height: 1.4;
            }
        }

        @media (max-width: 360px) {
            .retro-text {
                font-size: 10px;
                letter-spacing: 0.5px;
            }
        }

        .login-body {
            padding: 40px 30px;
        }

        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 12px 16px;
            border-radius: 4px;
            margin-bottom: 24px;
            border-left: 4px solid #d32f2f;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-label {
            display: block;
            color: #333;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
            transition: border-color 0.3s;
        }

        .form-input:focus {
            outline: none;
            border-color: #3d5a26;
        }

        .form-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 24px;
        }

        .form-checkbox input {
            width: 18px;
            height: 18px;
        }

        .form-checkbox label {
            font-size: 14px;
            color: #666;
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
        }

        .btn-login:hover {
            background: #2d5016;
        }

        .login-footer {
            text-align: center;
            padding-top: 24px;
            border-top: 1px solid #ddd;
            margin-top: 24px;
            font-size: 12px;
            color: #999;
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
            <h2 style="color: #3d5a26; margin-bottom: 24px; text-align: center;">Welcome Back</h2>

            <?php if ($error): ?>
                <div class="error-message">
                    ⚠️ <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <?= Csrf::field() ?>
                <div class="form-group">
                    <label class="form-label" for="username">Username</label>
                    <input type="text" 
                           id="username" 
                           name="username" 
                           class="form-input" 
                           required 
                           autofocus 
                           autocomplete="username">
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <input type="password" 
                           id="password" 
                           name="password" 
                           class="form-input" 
                           required 
                           autocomplete="current-password">
                </div>

                <div class="form-checkbox">
                    <input type="checkbox" id="remember" name="remember">
                    <label for="remember">Remember me for 30 days</label>
                </div>

                <button type="submit" class="btn-login" style="display: flex; align-items: center; justify-content: center; gap: 8px;">
                    <img src="<?= ASSETS_BASE_URL ?>/assets/images/loginbear.png" alt="Sign In" style="width: 26px; height: 26px;">
                    <span>Sign In</span>
                </button>
            </form>

            <div style="text-align: center; margin-top: 20px;">
                <a href="<?= APP_BASE_URL ?>/forgot-password.php" style="color: #3d5a26; text-decoration: none; font-size: 14px;">
                    Forgot your password?
                </a>
            </div>

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

