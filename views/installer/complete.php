<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Simple KUMA - Installation Complete</title>
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
            font-size: 64px;
            margin-bottom: 10px;
            animation: bounce 2s ease infinite;
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        .installer-header p {
            font-size: 14px;
            opacity: 0.9;
        }

        .installer-body {
            padding: 40px;
            text-align: center;
        }

        .success-icon {
            font-size: 80px;
            margin: 20px 0;
            animation: pulse 2s ease infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.8; }
        }

        h2 {
            color: #3d5a26;
            font-size: 28px;
            margin-bottom: 15px;
        }

        .description {
            color: #666;
            font-size: 16px;
            line-height: 1.6;
            margin-bottom: 30px;
        }

        .next-steps {
            background: #fff;
            border: 2px solid #8b6f47;
            border-radius: 4px;
            padding: 25px;
            margin: 30px 0;
            text-align: left;
        }

        .next-steps h3 {
            color: #3d5a26;
            font-size: 20px;
            margin-bottom: 20px;
            text-align: center;
        }

        .step-item {
            display: flex;
            gap: 15px;
            padding: 15px;
            margin-bottom: 10px;
            background: #f9f9f9;
            border-radius: 4px;
            align-items: flex-start;
        }

        .step-number {
            background: #3d5a26;
            color: #f5f1e8;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            flex-shrink: 0;
        }

        .step-content {
            flex: 1;
        }

        .step-content strong {
            color: #3d5a26;
            display: block;
            margin-bottom: 5px;
        }

        .step-content p {
            color: #666;
            font-size: 14px;
            line-height: 1.5;
        }

        .security-note {
            background: #fff3cd;
            border: 2px solid #ffc107;
            border-radius: 4px;
            padding: 20px;
            margin: 20px 0;
        }

        .security-note strong {
            color: #856404;
            display: block;
            margin-bottom: 10px;
        }

        .security-note ul {
            color: #856404;
            margin-left: 20px;
            font-size: 14px;
        }

        .security-note li {
            margin-bottom: 5px;
        }

        .btn {
            padding: 15px 40px;
            border: none;
            border-radius: 4px;
            font-size: 18px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
            margin-top: 20px;
            background: #3d5a26;
            color: #f5f1e8;
        }

        .btn:hover {
            background: #2d5016;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
        }

        .footer-note {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #8b6f47;
            color: #999;
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
            <div class="success-icon">🎉</div>
            
            <h2>Installation Complete!</h2>
            
            <p class="description">
                Simple KUMA has been successfully installed and is ready to track your affiliate campaigns.
                Your database is configured, and your admin account is created.
            </p>

            <?php if ($installerDeleted ?? false): ?>
                <div style="background: #d4edda; color: #155724; padding: 20px; border-radius: 4px; margin-bottom: 20px; border-left: 4px solid #28a745;">
                    <strong>🗑️ Installer Automatically Disabled</strong>
                    <p style="margin-top: 8px; margin-bottom: 0;">The installer has been disabled for security. Opening <code>install.php</code> now redirects to login.</p>
                </div>
            <?php else: ?>
                <div style="background: #fff3cd; color: #856404; padding: 20px; border-radius: 4px; margin-bottom: 20px; border-left: 4px solid #ffc107;">
                    <strong>⚠️ Please Delete Installer Manually</strong>
                    <p style="margin-top: 8px; margin-bottom: 0;">For security, delete <code>public/install.php</code> manually to prevent unauthorized reinstallation.</p>
                </div>
            <?php endif; ?>

            <div class="next-steps">
                <h3>📋 Next Steps</h3>
                
                <?php if (!($installerDeleted ?? false)): ?>
                <div class="step-item">
                    <div class="step-number">1</div>
                    <div class="step-content">
                        <strong>Delete the Installer</strong>
                        <p>For security, delete or rename <code>public/install.php</code> to prevent unauthorized reinstallation.</p>
                    </div>
                </div>
                <?php endif; ?>

                <div class="step-item">
                    <div class="step-number"><?= ($installerDeleted ?? false) ? '1' : '2' ?></div>
                    <div class="step-content">
                        <strong>Log In to Dashboard</strong>
                        <p>Use your admin credentials to access the application and start setting up your campaigns.</p>
                    </div>
                </div>

                <div class="step-item">
                    <div class="step-number"><?= ($installerDeleted ?? false) ? '2' : '3' ?></div>
                    <div class="step-content">
                        <strong>Set Up a Tracking Domain</strong>
                        <p>In <strong>Settings → Tracking Domains</strong>, add a dedicated host (e.g. <code>track.yourdomain.com</code>) and assign it to campaigns. Paid ad links should use that host — <strong>not</strong> your admin dashboard domain.</p>
                    </div>
                </div>

                <div class="step-item">
                    <div class="step-number"><?= ($installerDeleted ?? false) ? '3' : '4' ?></div>
                    <div class="step-content">
                        <strong>Configure Traffic Sources</strong>
                        <p>Add your traffic sources with their tracking tokens and cost parameters.</p>
                    </div>
                </div>

                <div class="step-item">
                    <div class="step-number"><?= ($installerDeleted ?? false) ? '4' : '5' ?></div>
                    <div class="step-content">
                        <strong>Create Your First Campaign</strong>
                        <p>Set up offers, landing pages, and create your first tracking campaign.</p>
                    </div>
                </div>
            </div>

            <div class="security-note">
                <strong>🔒 Security &amp; Safe Browsing:</strong>
                <ul>
                    <li>Your site must use <strong>HTTPS</strong> — login and sessions require SSL (<code>SESSION_COOKIE_SECURE</code> is enabled)</li>
                    <li>Keep <strong>admin</strong> and <strong>click tracking</strong> on separate hostnames when possible</li>
                    <li>Never send ad traffic to your admin domain — use your tracking domain only (e.g. <code>go.php?k=…</code> on <code>track.example.com</code>)</li>
                    <li><strong>Nginx:</strong> point document root at <code>public/</code> and add click rewrites for <code>/km/</code>, <code>/go/</code>, and <code>/c/</code> (see <code>docker/nginx.conf.example</code>). Without them, tracking links can open the login page. Apache uses <code>.htaccess</code> automatically.</li>
                    <li>If Chrome flagged your domain before, request a <a href="https://safebrowsing.google.com/safebrowsing/report_error/" target="_blank" rel="noopener noreferrer">Google Safe Browsing review</a> after splitting hosts</li>
                    <li>Change the default database credentials if you used "root"</li>
                    <li>Backup your <code>config/config.php</code> file securely</li>
                    <li>Set up regular database backups</li>
                    <li>Review file permissions (config should be 600)</li>
                </ul>
            </div>

            <?php
                $dashboardUrl = isset($loginUrl) && is_string($loginUrl) && $loginUrl !== ''
                    ? $loginUrl
                    : ((isset($appBaseUrl) ? $appBaseUrl : ($baseUrl ?? '')) . '/login.php');
            ?>
            <a href="<?= htmlspecialchars($dashboardUrl) ?>" class="btn">
                🚀 Go to Login
            </a>

            <div class="footer-note">
                Installation completed at <?= date('Y-m-d H:i:s') ?> UTC
            </div>
        </div>
    </div>
    <?php if ($installerDeleted ?? false): ?>
    <script>
    // Keep the success screen visible, but point the address bar at login so a
    // refresh cannot hit a missing install.php after hard delete fallbacks.
    (function () {
        try {
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', <?= json_encode($dashboardUrl, JSON_UNESCAPED_SLASHES) ?>);
            }
        } catch (e) { /* ignore */ }
    })();
    </script>
    <?php endif; ?>
</body>
</html>

