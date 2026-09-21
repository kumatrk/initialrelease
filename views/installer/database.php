<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Simple KUMA - Database Configuration</title>
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

        .form-section {
            margin-bottom: 30px;
        }

        .form-section-title {
            color: #3d5a26;
            font-size: 18px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            color: #333;
            font-weight: 500;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .form-label .required {
            color: #d32f2f;
        }

        .form-input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.3s;
        }

        .form-input:focus {
            outline: none;
            border-color: #3d5a26;
        }

        .form-input.error {
            border-color: #d32f2f;
        }

        .form-help {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }

        .form-error {
            color: #d32f2f;
            font-size: 12px;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 4px;
            margin: 20px 0;
            border-left: 4px solid #721c24;
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

        .password-toggle {
            position: relative;
        }

        .password-toggle-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            font-size: 18px;
            padding: 5px;
        }

        .divider {
            height: 2px;
            background: linear-gradient(to right, transparent, #8b6f47, transparent);
            margin: 30px 0;
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
            <h2 class="step-title">Step 2: Configuration</h2>
            <p class="step-description">
                Configure your database connection and create an admin account. 
                <strong>Note:</strong> The database must already exist. Create it through your hosting panel (cPanel, phpMyAdmin, etc.) before proceeding.
                Requires <?= htmlspecialchars($requirementLabel ?? 'MySQL 8.0+ or MariaDB 10.3+') ?>.
            </p>

            <?php if (!empty($errors) && is_array($errors)): ?>
                <div class="error-message">
                    <strong>⚠️ Could not continue — please fix the following</strong>
                    <ul style="margin: 10px 0 0 18px; padding: 0;">
                        <?php foreach ($errors as $field => $message): ?>
                            <?php if ($message !== '' && $message !== null): ?>
                                <li><?= htmlspecialchars((string) $message) ?></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($connectionSuccess)): ?>
                <div style="background: #d4edda; color: #155724; padding: 20px; border-radius: 4px; margin-bottom: 20px; border-left: 4px solid #28a745; text-align: center;">
                    <div style="font-size: 48px; margin-bottom: 10px;">✅</div>
                    <strong style="font-size: 18px;">Database Connection Successful!</strong>
                    <p style="margin-top: 8px;">All credentials are valid. Proceeding to configuration...</p>
                    <div style="margin-top: 12px; font-size: 14px;">
                        <div class="spinner" style="border-top-color: #155724; display: inline-block;"></div>
                        <span style="margin-left: 8px;">Redirecting in 2 seconds...</span>
                    </div>
                </div>
                <style>
                    .spinner {
                        width: 20px;
                        height: 20px;
                        border: 3px solid rgba(21, 87, 36, 0.3);
                        border-radius: 50%;
                        border-top-color: #155724;
                        animation: spin 1s ease-in-out infinite;
                    }
                    @keyframes spin {
                        to { transform: rotate(360deg); }
                    }
                </style>
            <?php endif; ?>

            <?php if (!empty($csrfError)): ?>
                <div class="error-message" style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
                    <?= htmlspecialchars($csrfError) ?>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <?= $csrfField ?? '' ?>
                <!-- Database Configuration Section -->
                <div class="form-section">
                    <div class="form-section-title">
                        <span>🗄️</span>
                        <span>Database Configuration</span>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="db_host">
                            Database Host <span class="required">*</span>
                        </label>
                        <input 
                            type="text" 
                            id="db_host" 
                            name="db_host" 
                            class="form-input <?= isset($errors['db_host']) ? 'error' : '' ?>"
                            value="<?= htmlspecialchars(($data['db_host'] ?? '') !== '' ? $data['db_host'] : 'localhost') ?>"
                            placeholder="localhost"
                            required
                        >
                        <div class="form-help">Usually "localhost" or "127.0.0.1" — leave as localhost if unsure</div>
                        <?php if (isset($errors['db_host'])): ?>
                            <div class="form-error">⚠ <?= htmlspecialchars($errors['db_host']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="db_name">
                            Database Name <span class="required">*</span>
                        </label>
                        <input 
                            type="text" 
                            id="db_name" 
                            name="db_name" 
                            class="form-input <?= isset($errors['db_name']) ? 'error' : '' ?>"
                            value="<?= htmlspecialchars($data['db_name'] ?? '') ?>"
                            placeholder="simplekuma"
                            required
                        >
                        <div class="form-help">Must already exist. Letters, numbers, underscores, and dashes are allowed.</div>
                        <?php if (isset($errors['db_name'])): ?>
                            <div class="form-error">⚠ <?= htmlspecialchars($errors['db_name']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="db_user">
                            Database Username <span class="required">*</span>
                        </label>
                        <input 
                            type="text" 
                            id="db_user" 
                            name="db_user" 
                            class="form-input <?= isset($errors['db_user']) ? 'error' : '' ?>"
                            value="<?= htmlspecialchars($data['db_user'] ?? '') ?>"
                            placeholder="root"
                            required
                        >
                        <?php if (isset($errors['db_user'])): ?>
                            <div class="form-error">⚠ <?= htmlspecialchars($errors['db_user']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="db_password">
                            Database Password
                        </label>
                        <div class="password-toggle">
                            <input 
                                type="password" 
                                id="db_password" 
                                name="db_password" 
                                class="form-input <?= isset($errors['db_password']) ? 'error' : '' ?>"
                                value="<?= htmlspecialchars($data['db_password'] ?? '') ?>"
                                placeholder="••••••••"
                            >
                            <button type="button" class="password-toggle-btn" onclick="togglePassword('db_password')">👁️</button>
                        </div>
                        <div class="form-help">Can be empty for development environments</div>
                        <?php if (isset($errors['db_password'])): ?>
                            <div class="form-error">⚠ <?= htmlspecialchars($errors['db_password']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="divider"></div>

                <!-- Site Configuration Section -->
                <div class="form-section">
                    <div class="form-section-title">
                        <span>🌐</span>
                        <span>Site Configuration</span>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="base_url">
                            Base URL <span class="required">*</span>
                        </label>
                        <input 
                            type="url" 
                            id="base_url" 
                            name="base_url" 
                            class="form-input <?= isset($errors['base_url']) ? 'error' : '' ?>"
                            value="<?= htmlspecialchars($data['base_url'] ?? '') ?>"
                            placeholder="https://track.example.com"
                            required
                        >
                        <div class="form-help">
                            <strong>Tracking domain</strong> — use a dedicated host such as
                            <code>https://track.example.com</code> (not the same host as your admin login).
                            Do not include <code>/public</code>. A trailing slash is fine (it is removed automatically).
                        </div>
                        <div id="base-url-host-warning" style="display: none; margin-top: 12px; padding: 14px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; color: #856404; font-size: 13px; line-height: 1.5;">
                            <strong>⚠ Same host as admin dashboard</strong>
                            <p style="margin: 8px 0;">Click links will run on the same domain as your login page. That pattern is linked to false Google Safe Browsing flags. Prefer a separate tracking subdomain (e.g. <code>https://track.example.com</code>).</p>
                            <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; margin-top: 8px;">
                                <input type="checkbox" name="base_url_same_host_ack" value="1" id="base_url_same_host_ack" style="margin-top: 3px;"
                                    <?= !empty($data['base_url_same_host_ack']) ? 'checked' : '' ?>>
                                <span>I understand — I will use a dedicated tracking subdomain in Settings after install (or this is local dev only).</span>
                            </label>
                        </div>
                        <?php if (!empty($installLayoutNote)): ?>
                            <div class="form-help" style="margin-top: 8px; color: #3d5a26;">
                                <?= htmlspecialchars($installLayoutNote) ?>
                            </div>
                        <?php endif; ?>
                        <?php if (isset($errors['base_url'])): ?>
                            <div class="form-error">⚠ <?= htmlspecialchars($errors['base_url']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="public_path_suffix">
                            App URL path prefix
                        </label>
                        <input
                            type="text"
                            id="public_path_suffix"
                            name="public_path_suffix"
                            class="form-input <?= isset($errors['public_path_suffix']) ? 'error' : '' ?>"
                            value="<?= htmlspecialchars($data['public_path_suffix'] ?? '') ?>"
                            placeholder="(empty — recommended)"
                            autocomplete="off"
                        >
                        <div class="form-help">
                            Leave <strong>empty</strong> when your domain document root is the <code>public/</code> folder.
                            Use <code>/public</code> only if the web root is the project folder (not recommended).
                            You can clear this field to remove <code>/public</code> from admin and asset URLs.
                        </div>
                        <div class="form-help" style="margin-top: 4px;">
                            Admin login preview:
                            <code id="admin_login_preview"><?= htmlspecialchars(
                                ($detectedAppBaseUrl ?? '') !== '' ? $detectedAppBaseUrl . '/login.php' : '/login.php'
                            ) ?></code>
                        </div>
                        <?php if (isset($errors['public_path_suffix'])): ?>
                            <div class="form-error">⚠ <?= htmlspecialchars($errors['public_path_suffix']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <script>
                (function () {
                    var baseInput = document.getElementById('base_url');
                    var prefixInput = document.getElementById('public_path_suffix');
                    var preview = document.getElementById('admin_login_preview');
                    if (!baseInput || !prefixInput || !preview) {
                        return;
                    }
                    function normalizeBase(url) {
                        url = (url || '').trim().replace(/\/+$/, '');
                        if (url.endsWith('/public')) {
                            url = url.slice(0, -7);
                        }
                        return url.replace(/\/+$/, '');
                    }
                    function normalizePrefix(prefix) {
                        prefix = (prefix || '').trim();
                        if (!prefix || prefix === '/') {
                            return '';
                        }
                        if (prefix.indexOf('://') !== -1) {
                            return '';
                        }
                        if (prefix.charAt(0) !== '/') {
                            prefix = '/' + prefix;
                        }
                        return prefix.replace(/\/+$/, '');
                    }
                    function hostFromUrl(url) {
                        try {
                            return new URL(url).hostname.toLowerCase();
                        } catch (e) {
                            return '';
                        }
                    }
                    function updatePreview() {
                        var base = normalizeBase(baseInput.value);
                        var prefix = normalizePrefix(prefixInput.value);
                        if (!base) {
                            preview.textContent = (prefix || '') + '/login.php';
                        } else {
                            preview.textContent = base + prefix + '/login.php';
                        }
                        var warn = document.getElementById('base-url-host-warning');
                        if (warn) {
                            var baseHost = hostFromUrl(base);
                            var adminHost = window.location.hostname.toLowerCase();
                            var show = baseHost !== '' && adminHost !== '' && baseHost === adminHost
                                && adminHost !== 'localhost' && adminHost !== '127.0.0.1';
                            warn.style.display = show ? 'block' : 'none';
                        }
                    }
                    baseInput.addEventListener('input', updatePreview);
                    prefixInput.addEventListener('input', updatePreview);
                    updatePreview();
                })();
                </script>

                <div class="divider"></div>

                <!-- Admin Account Section -->
                <div class="form-section">
                    <div class="form-section-title">
                        <span>👤</span>
                        <span>Admin Account</span>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="admin_username">
                            Admin Username <span class="required">*</span>
                        </label>
                        <input 
                            type="text" 
                            id="admin_username" 
                            name="admin_username" 
                            class="form-input <?= isset($errors['admin_username']) ? 'error' : '' ?>"
                            value="<?= htmlspecialchars($data['admin_username'] ?? '') ?>"
                            placeholder="admin"
                            required
                            autocomplete="off"
                        >
                        <div class="form-help">At least 3 characters, letters, numbers, and underscores only</div>
                        <?php if (isset($errors['admin_username'])): ?>
                            <div class="form-error">⚠ <?= htmlspecialchars($errors['admin_username']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="admin_email">
                            Admin Email <span class="required">*</span>
                        </label>
                        <input 
                            type="email" 
                            id="admin_email" 
                            name="admin_email" 
                            class="form-input <?= isset($errors['admin_email']) ? 'error' : '' ?>"
                            value="<?= htmlspecialchars($data['admin_email'] ?? '') ?>"
                            placeholder="admin@example.com"
                            required
                        >
                        <?php if (isset($errors['admin_email'])): ?>
                            <div class="form-error">⚠ <?= htmlspecialchars($errors['admin_email']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="admin_password">
                            Admin Password <span class="required">*</span>
                        </label>
                        <div class="password-toggle">
                            <input 
                                type="password" 
                                id="admin_password" 
                                name="admin_password" 
                                class="form-input <?= isset($errors['admin_password']) ? 'error' : '' ?>"
                                value="<?= htmlspecialchars($data['admin_password'] ?? '') ?>"
                                placeholder="••••••••"
                                required
                                autocomplete="new-password"
                            >
                            <button type="button" class="password-toggle-btn" onclick="togglePassword('admin_password')">👁️</button>
                        </div>
                        <div class="form-help">At least 8 characters</div>
                        <?php if (isset($errors['admin_password'])): ?>
                            <div class="form-error">⚠ <?= htmlspecialchars($errors['admin_password']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="admin_password_confirm">
                            Confirm Password <span class="required">*</span>
                        </label>
                        <div class="password-toggle">
                            <input 
                                type="password" 
                                id="admin_password_confirm" 
                                name="admin_password_confirm" 
                                class="form-input <?= isset($errors['admin_password_confirm']) ? 'error' : '' ?>"
                                value="<?= htmlspecialchars($data['admin_password_confirm'] ?? '') ?>"
                                placeholder="••••••••"
                                required
                                autocomplete="new-password"
                            >
                            <button type="button" class="password-toggle-btn" onclick="togglePassword('admin_password_confirm')">👁️</button>
                        </div>
                        <?php if (isset($errors['admin_password_confirm'])): ?>
                            <div class="form-error">⚠ <?= htmlspecialchars($errors['admin_password_confirm']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="button-group">
                    <a href="install.php?step=requirements" class="btn btn-secondary">← Back</a>
                    <button type="submit" class="btn btn-primary">Test Connection & Continue →</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const btn = field.nextElementSibling;
            
            if (field.type === 'password') {
                field.type = 'text';
                btn.textContent = '🙈';
            } else {
                field.type = 'password';
                btn.textContent = '👁️';
            }
        }
    </script>
</body>
</html>

