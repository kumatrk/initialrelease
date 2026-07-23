<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Simple KUMA - Run Migrations</title>
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
            background: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 4px;
            padding: 15px;
            margin: 20px 0;
        }

        .info-box strong {
            color: #004085;
        }

        .migrations-list {
            background: #fff;
            border: 2px solid #8b6f47;
            border-radius: 4px;
            padding: 20px;
            margin: 20px 0;
        }

        .migrations-list h3 {
            color: #3d5a26;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .migration-item {
            padding: 10px;
            margin-bottom: 8px;
            border-radius: 4px;
            background: #d4edda;
            color: #155724;
            display: flex;
            align-items: center;
            gap: 10px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
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

        .table-list {
            list-style: none;
            margin: 15px 0;
            padding-left: 20px;
        }

        .table-list li {
            padding: 5px 0;
            color: #666;
        }

        .table-list li:before {
            content: "📊 ";
            margin-right: 8px;
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
            <h2 class="step-title">Step 4: Database Migrations</h2>
            <p class="step-description">
                Set up the database schema with all required tables and indexes.
                Requires <?= htmlspecialchars(\SimpleKuma\Database\DatabaseCompatibility::getRequirementLabel()) ?>.
            </p>

            <?php if (!empty($csrfError)): ?>
                <div class="error-message"><?= htmlspecialchars($csrfError) ?></div>
            <?php endif; ?>

            <?php if (!empty($expectedMigrationCount)): ?>
                <div class="info-box">
                    <strong>Migration package:</strong>
                    <?= (int) $expectedMigrationCount ?> SQL migration file(s) in this release (001 through latest).
                    <?php if (!empty($lastAppliedMigration)): ?>
                        Last applied: <code><?= htmlspecialchars($lastAppliedMigration) ?></code>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($dbVersionError)): ?>
                <div class="error-message">
                    <strong>Database version not supported</strong><br>
                    <?= htmlspecialchars($dbVersionError) ?>
                </div>
            <?php elseif (!empty($dbServerInfo)): ?>
                <div class="info-box">
                    <strong>Connected database:</strong> <?= htmlspecialchars($dbServerInfo) ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="success-message">
                    <div class="icon">✅</div>
                    <h3>Database Schema Created Successfully!</h3>
                    <p>All tables have been created and are ready to use.</p>
                    <div class="loading-text">
                        <div class="spinner" style="border-top-color: #155724;"></div>
                        Redirecting to admin account creation...
                    </div>
                </div>

                <?php if (!empty($applied)): ?>
                    <div class="migrations-list">
                        <h3>
                            <span>✅</span>
                            <span>Applied Migrations (<?= count($applied) ?>)</span>
                        </h3>
                        <?php foreach ($applied as $migration): ?>
                            <div class="migration-item">
                                <span>✓</span>
                                <span><?= htmlspecialchars($migration) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <?php if (!empty($errors)): ?>
                    <div class="error-message">
                        <strong>⚠️ Migration Failed</strong><br>
                        <?php foreach ($errors as $error): ?>
                            • <?= htmlspecialchars($error) ?><br>
                        <?php endforeach; ?>
                        <p style="margin-top: 12px; font-size: 14px;">
                            If a migration failed partway through, update to the latest files and click
                            <strong>Run Migrations</strong> again — migrations are idempotent and skip work already applied.
                            Failed migrations are not marked complete until every statement succeeds.
                        </p>
                    </div>
                <?php endif; ?>

                <div class="info-box">
                    <strong>ℹ️ What will be created:</strong>
                    <p style="margin-top: 10px; color: #666;">
                        Running migrations creates and updates all application tables, indexes, and seed data
                        (including roles and permissions). This release includes
                        <strong><?= (int) ($expectedMigrationCount ?? 0) ?></strong> forward migration file(s).
                    </p>
                </div>

                <div class="info-box" style="background: #fff3cd; border-color: #ffc107;">
                    <strong>⚡ Performance Note:</strong>
                    <p style="margin-top: 10px; color: #856404;">
                        The migrations include optimized indexes for high performance:
                        <strong>&lt;150ms</strong> redirects and <strong>&lt;2.5s</strong> report queries.
                    </p>
                </div>

                <form method="post" action="">
                    <?= $csrfField ?? '' ?>
                    <div class="button-group">
                        <button type="submit" class="btn btn-primary" <?= !empty($dbVersionError) ? 'disabled' : '' ?>>Run Migrations →</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>


