<?php

declare(strict_types=1);

/**
 * Simple KUMA - Forgot Password Page
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
use SimpleKuma\Services\EmailService;

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
$success = '';

// Handle password reset request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate()) {
        $error = Csrf::invalidRequestMessage();
    } else {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = 'Email address is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } else {
        $result = $auth->requestPasswordReset($email);
        
        if ($result['success']) {
            $success = $result['message'];
            
            // Send email if token was generated
            if (isset($result['token']) && isset($result['user'])) {
                $resetUrl = APP_BASE_URL . '/reset-password.php?token=' . urlencode($result['token']);
                
                // Initialize email service with auto-detection
                // Works out of the box on most PHP servers - no configuration needed!
                $emailConfig = [
                    'enabled' => true, // Enabled by default - will use PHP mail() automatically
                    'use_smtp' => false, // Set to true only if you want to use SMTP
                    // SMTP settings (optional - only needed if use_smtp = true)
                    // 'smtp_host' => 'smtp.gmail.com',
                    // 'smtp_port' => 587,
                    // 'smtp_username' => 'your-email@gmail.com',
                    // 'smtp_password' => 'your-app-password',
                    // 'smtp_encryption' => 'tls',
                    'development_mode' => defined('APP_ENV') && APP_ENV !== 'production',
                ];
                $emailService = new EmailService($db, $emailConfig);
                
                $emailSent = $emailService->sendPasswordResetEmail(
                    $result['user']['email'],
                    $result['token'],
                    $resetUrl
                );
                
                // In development mode, show the reset link
                if ($emailConfig['development_mode'] && !$emailConfig['enabled']) {
                    $success .= '<br><br><strong>Development Mode:</strong><br>';
                    $success .= '<a href="' . htmlspecialchars($resetUrl) . '" style="color: #3d5a26; word-break: break-all;">' . htmlspecialchars($resetUrl) . '</a>';
                }
            }
        } else {
            $error = $result['message'];
        }
    }
    }
}

$db->close();
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
            var t = localStorage.getItem('kuma_theme');
            if (t === 'light' || t === 'dark') {
                document.documentElement.setAttribute('data-theme', t);
            }
        } catch (e) { /* ignore */ }
    })();
    </script>
    <title>Forgot password</title>
    <link rel="icon" type="image/x-icon" href="<?= ASSETS_BASE_URL ?>/assets/images/favicon.ico">
    <link rel="stylesheet" href="<?= ASSETS_BASE_URL ?>/assets/css/themes.css">
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
    </style>
</head>
<body class="auth-page">
    <div class="login-container">
        <div class="login-header">
            <div style="margin-bottom: 16px;">
                <img src="<?= ASSETS_BASE_URL ?>/assets/images/mainlogo.png" alt="" style="max-height: 140px; height: auto;">
            </div>
            <span class="retro-text">Harder, Better, Faster, Stronger</span>
        </div>

        <div class="login-body">
            <h2 style="color: #3d5a26; margin-bottom: 24px; text-align: center;">Forgot Password?</h2>
            <p style="color: #666; margin-bottom: 24px; text-align: center; font-size: 14px;">
                Enter your email address and we'll send you a link to reset your password.
            </p>

            <?php if ($error): ?>
                <div class="error-message">
                    ⚠️ <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="success-message">
                    ✓ <?= nl2br(htmlspecialchars($success)) ?>
                </div>
            <?php endif; ?>

            <?php if (!$success): ?>
            <form method="post" action="">
                <?= Csrf::field() ?>
                <div class="form-group">
                    <label class="form-label" for="email">Email Address</label>
                    <input type="email" 
                           id="email" 
                           name="email" 
                           class="form-input" 
                           required 
                           autofocus 
                           autocomplete="email"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>

                <button type="submit" class="btn-login">
                    Send Reset Link
                </button>
            </form>
            <?php endif; ?>

            <a href="<?= APP_BASE_URL ?>/login.php" class="btn-secondary">
                ← Back to Login
            </a>

            <div class="login-footer">
                V <?= htmlspecialchars($skAppVersion) ?><br>
                <span style="font-size: 10px;">Work is Never Over</span>
            </div>
        </div>
    </div>
</body>
</html>

