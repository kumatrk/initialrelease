<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Simple KUMA - Installation</title>
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
            max-width: 800px;
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
            margin-bottom: 20px;
            border-bottom: 2px solid #8b6f47;
            padding-bottom: 10px;
        }

        .requirements-list {
            margin: 20px 0;
        }

        .requirement-item {
            display: flex;
            align-items: center;
            padding: 12px;
            margin-bottom: 8px;
            border-radius: 4px;
            font-size: 14px;
        }

        .requirement-item.passed {
            background: #d4edda;
            color: #155724;
        }

        .requirement-item.warning {
            background: #fff3cd;
            color: #856404;
        }

        .requirement-item.error {
            background: #f8d7da;
            color: #721c24;
        }

        .requirement-icon {
            margin-right: 12px;
            font-size: 18px;
            font-weight: bold;
        }

        .summary-box {
            background: #fff;
            border: 2px solid #8b6f47;
            border-radius: 4px;
            padding: 20px;
            margin: 20px 0;
        }

        .summary-box h3 {
            color: #3d5a26;
            margin-bottom: 10px;
        }

        .summary-stats {
            display: flex;
            gap: 20px;
            margin-top: 15px;
        }

        .stat {
            flex: 1;
            text-align: center;
            padding: 10px;
            border-radius: 4px;
        }

        .stat.passed {
            background: #d4edda;
            color: #155724;
        }

        .stat.warning {
            background: #fff3cd;
            color: #856404;
        }

        .stat.error {
            background: #f8d7da;
            color: #721c24;
        }

        .stat .number {
            font-size: 24px;
            font-weight: bold;
        }

        .stat .label {
            font-size: 12px;
            text-transform: uppercase;
            margin-top: 5px;
        }

        .button-group {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            justify-content: flex-end;
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

        .btn-primary:hover:not(:disabled) {
            background: #2d5016;
        }

        .btn-primary:disabled {
            background: #999;
            cursor: not-allowed;
        }

        .btn-secondary {
            background: #8b6f47;
            color: #f5f1e8;
        }

        .btn-secondary:hover {
            background: #6d5636;
        }

        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 4px;
            margin: 20px 0;
            border-left: 4px solid #721c24;
        }

        .collapsible-section {
            margin: 20px 0;
        }

        .collapsible-toggle {
            background: #8b6f47;
            color: #f5f1e8;
            padding: 10px 15px;
            cursor: pointer;
            border-radius: 4px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .collapsible-content {
            display: none;
            padding: 15px;
            background: #fff;
            border: 1px solid #ddd;
            border-top: none;
            border-radius: 0 0 4px 4px;
        }

        .collapsible-content.active {
            display: block;
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
            <h2 class="step-title">Step 1: Requirements Check</h2>
            <p class="step-description" style="color: #666; font-size: 14px; margin-bottom: 20px;">
                Database: <?= htmlspecialchars(\SimpleKuma\Database\DatabaseCompatibility::getRequirementLabel()) ?>
                (verified when you enter credentials in Step 2).
            </p>

            <?php if (!empty($httpsWarning)): ?>
                <div class="error-message" style="background: #fff3cd; color: #856404; border-left-color: #ffc107;">
                    <strong>HTTPS required</strong><br>
                    <?= htmlspecialchars($httpsWarning) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($csrfError)): ?>
                <div class="error-message"><?= htmlspecialchars($csrfError) ?></div>
            <?php endif; ?>

            <?php if (!empty($installState) && !empty($installState['resumeMessage']) && empty($installState['isComplete'])): ?>
                <div style="background: #e7f3ff; border: 1px solid #b3d9ff; border-radius: 4px; padding: 15px; margin-bottom: 20px;">
                    <strong>Resume installation</strong>
                    <p style="margin: 10px 0; color: #004085;"><?= htmlspecialchars($installState['resumeMessage']) ?></p>
                    <a href="install.php?resume=<?= rawurlencode($installState['suggestedStep']) ?>" class="btn btn-primary" style="margin-top: 8px;">
                        Continue at step: <?= htmlspecialchars($installState['suggestedStep']) ?> →
                    </a>
                </div>
            <?php elseif (!empty($installState['isComplete'])): ?>
                <div style="background: #d4edda; border: 1px solid #28a745; border-radius: 4px; padding: 15px; margin-bottom: 20px;">
                    <strong>Installation detected</strong>
                    <p style="margin: 10px 0;"><a href="<?= htmlspecialchars(\SimpleKuma\Installer\InstallerLock::getLoginUrl()) ?>">Go to login</a></p>
                </div>
            <?php endif; ?>

            <?php if ($results): ?>
                <div class="summary-box">
                    <h3>System Requirements Summary</h3>
                    <div class="summary-stats">
                        <div class="stat passed">
                            <div class="number"><?= count($results['passed']) ?></div>
                            <div class="label">Passed</div>
                        </div>
                        <div class="stat warning">
                            <div class="number"><?= count($results['warnings']) ?></div>
                            <div class="label">Warnings</div>
                        </div>
                        <div class="stat error">
                            <div class="number"><?= count($results['errors']) ?></div>
                            <div class="label">Errors</div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($results['permissionsFixed'])): ?>
                    <div style="background: #d1ecf1; color: #0c5460; padding: 15px; border-radius: 4px; margin: 20px 0; border-left: 4px solid #0c5460;">
                        <strong>✅ Automatic Fix Applied</strong><br>
                        File permissions for the vendor directory have been automatically fixed. This ensures Composer dependencies can be loaded properly.
                    </div>
                <?php endif; ?>

                <?php if ($installResults): ?>
                    <div style="background: <?= $installResults['composer_success'] ? '#d4edda' : '#f8d7da' ?>; color: <?= $installResults['composer_success'] ? '#155724' : '#721c24' ?>; padding: 15px; border-radius: 4px; margin: 20px 0; border-left: 4px solid <?= $installResults['composer_success'] ? '#155724' : '#721c24' ?>;">
                        <strong><?= $installResults['composer_success'] ? '✅' : '❌' ?> Dependency Installation</strong><br><br>
                        <?php if (!empty($installResults['messages'])): ?>
                            <?php foreach ($installResults['messages'] as $msg): ?>
                                <div style="margin: 5px 0;"><?= htmlspecialchars($msg) ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if (!empty($installResults['errors'])): ?>
                            <?php foreach ($installResults['errors'] as $error): ?>
                                <div style="margin: 5px 0; color: #721c24;"><strong>Error:</strong> <?= htmlspecialchars($error) ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php 
                // Check if vendor folder exists (pre-packaged)
                $vendorExists = file_exists(dirname(__DIR__, 2) . '/vendor/autoload.php');
                ?>
                
                <?php if ($vendorExists): ?>
                    <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 4px; margin: 20px 0; border-left: 4px solid #28a745;">
                        <strong>✅ Dependencies Ready</strong><br>
                        <p style="margin: 10px 0;">All required dependencies (vendor folder) are included with Simple KUMA. No installation needed - you can proceed.</p>
                    </div>
                <?php endif; ?>
                
                <?php if (!$vendorExists): ?>
                    <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 4px; margin: 20px 0; border-left: 4px solid #dc3545;">
                        <strong>❌ Vendor Folder Missing</strong><br>
                        <p style="margin: 10px 0;">The vendor folder (containing all required dependencies) is missing. Simple KUMA ships with this folder pre-packaged.</p>
                        <p style="margin: 10px 0 0 0; font-size: 14px;"><strong>Solution:</strong> Please re-upload the complete Simple KUMA package, ensuring the <code>vendor/</code> folder is included.</p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($results['errors'])): ?>
                    <div class="error-message">
                        <strong>⚠️ Cannot proceed with installation.</strong><br>
                        Please resolve all errors below before continuing.
                    </div>
                <?php endif; ?>

                <!-- Passed Checks -->
                <?php if (!empty($results['passed'])): ?>
                    <div class="collapsible-section">
                        <div class="collapsible-toggle" onclick="toggleCollapsible(this)">
                            <span>✅ Passed Checks (<?= count($results['passed']) ?>)</span>
                            <span>▼</span>
                        </div>
                        <div class="collapsible-content">
                            <?php foreach ($results['passed'] as $check): ?>
                                <div class="requirement-item passed">
                                    <span class="requirement-icon">✓</span>
                                    <?= htmlspecialchars($check) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Warnings -->
                <?php if (!empty($results['warnings'])): ?>
                    <div class="collapsible-section">
                        <div class="collapsible-toggle" onclick="toggleCollapsible(this)">
                            <span>⚠️ Warnings (<?= count($results['warnings']) ?>)</span>
                            <span>▼</span>
                        </div>
                        <div class="collapsible-content active">
                            <?php foreach ($results['warnings'] as $warning): ?>
                                <div class="requirement-item warning">
                                    <span class="requirement-icon">⚠</span>
                                    <?= htmlspecialchars($warning) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Errors -->
                <?php if (!empty($results['errors'])): ?>
                    <div class="requirements-list">
                        <h3 style="color: #721c24; margin-bottom: 15px;">❌ Errors (Must Fix)</h3>
                        <?php foreach ($results['errors'] as $error): ?>
                            <div class="requirement-item error">
                                <span class="requirement-icon">✗</span>
                                <?= htmlspecialchars($error) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="button-group">
                    <button type="button" class="btn btn-secondary" onclick="location.reload()">
                        🔄 Recheck Requirements
                    </button>
                    <form method="post" style="display: inline;">
                        <?= $csrfField ?? '' ?>
                        <button type="submit" class="btn btn-primary" <?= !$results['canProceed'] ? 'disabled' : '' ?>>
                            Next: Database Configuration →
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <p>Checking system requirements...</p>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function toggleCollapsible(element) {
            const content = element.nextElementSibling;
            const arrow = element.querySelector('span:last-child');
            content.classList.toggle('active');
            arrow.textContent = content.classList.contains('active') ? '▲' : '▼';
        }
    </script>
</body>
</html>


