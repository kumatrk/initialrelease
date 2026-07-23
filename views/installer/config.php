<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Simple KUMA - Write Configuration</title>
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

        .config-summary {
            background: #fff;
            border: 2px solid #8b6f47;
            border-radius: 4px;
            padding: 20px;
            margin: 20px 0;
        }

        .config-summary h3 {
            color: #3d5a26;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .config-item {
            display: flex;
            padding: 10px;
            margin-bottom: 8px;
            border-radius: 4px;
            background: #f9f9f9;
        }

        .config-label {
            font-weight: 600;
            color: #333;
            min-width: 150px;
        }

        .config-value {
            color: #666;
            font-family: 'Courier New', monospace;
            word-break: break-all;
        }

        .config-value.masked {
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

        .info-box {
            background: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 4px;
            padding: 15px;
            margin: 20px 0;
        }

        .info-box strong {
            color: #004085;
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
            <h2 class="step-title">Step 3: Write Configuration</h2>
            <p class="step-description">
                Review your configuration and create the config file.
            </p>

            <?php if ($success): ?>
                <div class="success-message">
                    <div class="icon">✅</div>
                    <h3>Configuration File Created Successfully!</h3>
                    <p>Your configuration has been saved securely.</p>
                    <div class="loading-text">
                        <div class="spinner" style="border-top-color: #155724;"></div>
                        Redirecting to database migration...
                    </div>
                </div>
            <?php else: ?>
                <?php if (!empty($errors)): ?>
                    <div class="error-message">
                        <strong>⚠️ Configuration Error</strong><br>
                        <?php foreach ($errors as $error): ?>
                            • <?= htmlspecialchars($error) ?><br>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="config-summary">
                    <h3>
                        <span>🗄️</span>
                        <span>Database Configuration</span>
                    </h3>
                    
                    <div class="config-item">
                        <div class="config-label">Host:</div>
                        <div class="config-value"><?= htmlspecialchars($dbConfig['host'] ?? 'Not set') ?></div>
                    </div>
                    
                    <div class="config-item">
                        <div class="config-label">Database:</div>
                        <div class="config-value"><?= htmlspecialchars($dbConfig['name'] ?? 'Not set') ?></div>
                    </div>
                    
                    <div class="config-item">
                        <div class="config-label">Username:</div>
                        <div class="config-value"><?= htmlspecialchars($dbConfig['user'] ?? 'Not set') ?></div>
                    </div>
                    
                    <div class="config-item">
                        <div class="config-label">Password:</div>
                        <div class="config-value masked">
                            <?= !empty($dbConfig['password']) ? '••••••••' : '(empty)' ?>
                        </div>
                    </div>
                </div>

                <div class="config-summary">
                    <h3>
                        <span>🌐</span>
                        <span>Site Configuration</span>
                    </h3>
                    
                    <div class="config-item">
                        <div class="config-label">Base URL (tracking):</div>
                        <div class="config-value"><?= htmlspecialchars($siteConfig['base_url'] ?? 'Not set') ?></div>
                    </div>
                    <?php
                    $previewBase = $siteConfig['base_url'] ?? '';
                    $previewSuffix = $siteConfig['public_path_suffix'] ?? \SimpleKuma\Installer\WebPathResolver::getPublicPathSuffix();
                    $previewApp = \SimpleKuma\Installer\WebPathResolver::buildAppBaseUrl(
                        $previewBase,
                        $previewSuffix !== '' ? $previewSuffix : ''
                    );
                    ?>
                    <div class="config-item">
                        <div class="config-label">App / assets path prefix:</div>
                        <div class="config-value"><code><?= htmlspecialchars($previewSuffix !== '' ? $previewSuffix : '(none — docroot is public/)') ?></code></div>
                    </div>
                    <div class="config-item">
                        <div class="config-label">Admin &amp; login URL:</div>
                        <div class="config-value"><code><?= htmlspecialchars($previewApp) ?>/login.php</code></div>
                    </div>
                </div>

                <div class="info-box">
                    <strong>ℹ️ What happens next:</strong>
                    <ul style="margin-left: 20px; margin-top: 10px;">
                        <li>A secure config.php file will be created in the config/ directory</li>
                        <li>File permissions will be set to 600 (read/write for owner only)</li>
                        <li>A random application key will be generated</li>
                        <li>Security settings will be configured</li>
                    </ul>
                </div>

                <?php if (!empty($csrfError)): ?>
                    <div class="error-message" style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
                        <?= htmlspecialchars($csrfError) ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="">
                    <?= $csrfField ?? '' ?>
                    <div class="button-group">
                        <a href="install.php?step=database" class="btn btn-secondary">← Back</a>
                        <button type="submit" class="btn btn-primary">Create Config File →</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>



