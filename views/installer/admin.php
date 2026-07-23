<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Simple KUMA - Create Admin Account</title>
    <link rel="icon" type="image/x-icon" href="<?= htmlspecialchars($installerAssetsBase) ?>/assets/images/favicon.ico">
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

        .installer-container {
            background: #f5f1e8;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            max-width: 700px;
            width: 100%;
            overflow: hidden;
        }

        .installer-header {
            background: #3d5a26;
            color: #f5f1e8;
            padding: 30px;
            text-align: center;
        }

        .installer-header h1 {
            font-size: 32px;
            margin-bottom: 5px;
            font-weight: 600;
        }

        .installer-header .bear-icon {
            font-size: 48px;
            margin-bottom: 10px;
        }

        .installer-header p {
            font-size: 14px;
            opacity: 0.9;
        }

        .installer-body {
            padding: 40px;
        }

        .step-title {
            color: #3d5a26;
            font-size: 24px;
            margin-bottom: 10px;
            border-bottom: 2px solid #8b6f47;
            padding-bottom: 10px;
        }

        .step-description {
            color: #666;
            font-size: 14px;
            margin-bottom: 30px;
        }

        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 20px;
            border-radius: 4px;
            margin: 20px 0;
            border-left: 4px solid #28a745;
            text-align: center;
        }

        .success-message .icon {
            font-size: 48px;
            margin-bottom: 15px;
        }

        .success-message h3 {
            font-size: 20px;
            margin-bottom: 10px;
        }

        .success-message p {
            font-size: 14px;
        }

        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 4px;
            margin: 20px 0;
            border-left: 4px solid #721c24;
        }

        .info-box {
            background: #fff;
            border: 2px solid #8b6f47;
            border-radius: 4px;
            padding: 20px;
            margin: 20px 0;
        }

        .info-box h3 {
            color: #3d5a26;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .admin-item {
            display: flex;
            padding: 10px;
            margin-bottom: 8px;
            border-radius: 4px;
            background: #f9f9f9;
        }

        .admin-label {
            font-weight: 600;
            color: #333;
            min-width: 120px;
        }

        .admin-value {
            color: #666;
            font-family: 'Courier New', monospace;
        }

        .admin-value.masked {
            color: #999;
        }

        .button-group {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            justify-content: space-between;
        }

        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }

        .btn-primary {
            background: #3d5a26;
            color: #f5f1e8;
        }

        .btn-primary:hover {
            background: #2d5016;
        }

        .btn-secondary {
            background: #8b6f47;
            color: #f5f1e8;
        }

        .btn-secondary:hover {
            background: #6d5636;
        }

        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .loading-text {
            margin-top: 10px;
            color: #666;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="installer-container">
        <div class="installer-header">
            <div style="margin-bottom: 16px;">
                <img src="<?= htmlspecialchars($installerAssetsBase) ?>/assets/images/mainlogo.png" alt="Simple KUMA" style="max-height: 150px; height: auto;">
            </div>
            <p>Affiliate Tracker Installation Wizard</p>
        </div>

        <div class="installer-body">
            <h2 class="step-title">Step 5: Create Admin Account</h2>
            <p class="step-description">
                Create the administrator account that will be used to manage the application.
            </p>

            <?php if ($success): ?>
                <div class="success-message">
                    <div class="icon">✅</div>
                    <h3>Admin Account Created Successfully!</h3>
                    <p>You can now log in to Simple KUMA with your admin credentials.</p>
                    <div class="loading-text">
                        <div class="spinner" style="border-top-color: #155724;"></div>
                        Completing installation...
                    </div>
                </div>
            <?php else: ?>
                <?php if (!empty($csrfError)): ?>
                    <div class="error-message"><?= htmlspecialchars($csrfError) ?></div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="error-message">
                        <strong>⚠️ Admin Creation Failed</strong><br>
                        <?php foreach ($errors as $error): ?>
                            • <?= htmlspecialchars($error) ?><br>
                        <?php endforeach; ?>
                        <?php if (!empty($adminExists) && !empty($loginUrl)): ?>
                            <p style="margin-top: 12px;">
                                <a href="<?= htmlspecialchars($loginUrl) ?>" class="btn btn-primary" style="display: inline-block; padding: 8px 16px; text-decoration: none;">Continue to Login →</a>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="info-box">
                    <h3>
                        <span>👤</span>
                        <span>Admin Account Details</span>
                    </h3>
                    
                    <div class="admin-item">
                        <div class="admin-label">Username:</div>
                        <div class="admin-value"><?= htmlspecialchars($adminConfig['username'] ?? 'Not set') ?></div>
                    </div>
                    
                    <div class="admin-item">
                        <div class="admin-label">Email:</div>
                        <div class="admin-value"><?= htmlspecialchars($adminConfig['email'] ?? 'Not set') ?></div>
                    </div>
                    
                    <div class="admin-item">
                        <div class="admin-label">Password:</div>
                        <div class="admin-value masked">••••••••</div>
                    </div>
                </div>

                <div class="info-box" style="background: #e7f3ff; border-color: #b3d9ff;">
                    <strong style="color: #004085;">🔒 Security Information:</strong>
                    <ul style="margin-left: 20px; margin-top: 10px; color: #004085;">
                        <li>Password will be hashed using <strong>Argon2ID</strong> algorithm</li>
                        <li>Hash parameters: Argon2id (64MB memory, 4 iterations)</li>
                        <li>Plain password will be cleared from session after creation</li>
                        <li>Default timezone: <strong>UTC</strong></li>
                        <li>Default currency: <strong>USD</strong></li>
                    </ul>
                </div>

                <form method="post" action="">
                    <?= $csrfField ?? '' ?>
                    <div class="button-group">
                        <a href="install.php?step=migrations" class="btn btn-secondary">← Back</a>
                        <button type="submit" class="btn btn-primary">Create Admin Account →</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>


