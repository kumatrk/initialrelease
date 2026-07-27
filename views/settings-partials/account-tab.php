<?php
/**
 * Account settings tab (single-admin release).
 * Expects: $currentUser, $errors
 */
$accountStoredTimezone = $currentUser['timezone'] ?? 'UTC';
$accountTimezoneMap = [
    'PT' => 'America/Los_Angeles',
    'PST' => 'America/Los_Angeles',
    'PDT' => 'America/Los_Angeles',
    'ET' => 'America/New_York',
    'EST' => 'America/New_York',
    'EDT' => 'America/New_York',
    'CT' => 'America/Chicago',
    'CST' => 'America/Chicago',
    'CDT' => 'America/Chicago',
    'MT' => 'America/Denver',
    'MST' => 'America/Denver',
    'MDT' => 'America/Denver',
];
$accountNormalizedTimezone = isset($accountTimezoneMap[$accountStoredTimezone])
    ? $accountTimezoneMap[$accountStoredTimezone]
    : $accountStoredTimezone;
try {
    $accountTz = new DateTimeZone($accountNormalizedTimezone);
    $accountNormalizedTimezone = $accountTz->getName();
} catch (Exception $e) {
    $accountNormalizedTimezone = 'UTC';
}
$accountCurrency = $currentUser['currency'] ?? 'USD';
$accountEmail = trim($_POST['email'] ?? ($currentUser['email'] ?? ''));
?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title">Account</h2>
    </div>
    <div class="card-body">
        <p style="color: #666; margin: 0 0 24px 0; font-size: 14px;">
            Manage your login profile. Dashboard and reports use your timezone and currency settings.
        </p>
        <div style="margin-bottom: 24px; max-width: 480px;">
            <div style="font-size: 12px; color: #666; margin-bottom: 4px;">Username</div>
            <div style="font-weight: 600;"><?= htmlspecialchars($currentUser['username'] ?? '') ?></div>
            <div style="font-size: 12px; color: #666; margin-top: 4px;">Username cannot be changed here.</div>
        </div>

        <h3 style="margin: 0 0 16px 0; font-size: 18px; color: #3d5a26;">Profile settings</h3>
        <form method="POST" action="?page=settings&tab=account" style="max-width: 640px; margin-bottom: 40px;">
            <?= \SimpleKuma\Auth\Csrf::field() ?>
            <input type="hidden" name="action" value="update_profile">
            <div style="margin-bottom: 20px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px;">Email</label>
                <input type="email" name="email" required autocomplete="email"
                       value="<?= htmlspecialchars($accountEmail) ?>"
                       style="width: 100%; max-width: 400px; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                <?php if (isset($errors['email'])): ?>
                    <div style="color: #d32f2f; font-size: 12px; margin-top: 4px;"><?= htmlspecialchars($errors['email']) ?></div>
                <?php endif; ?>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Timezone</label>
                    <select name="timezone" style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                        <optgroup label="US &amp; Canada">
                            <option value="America/New_York" <?= $accountNormalizedTimezone === 'America/New_York' ? 'selected' : '' ?>>Eastern Time (ET)</option>
                            <option value="America/Chicago" <?= $accountNormalizedTimezone === 'America/Chicago' ? 'selected' : '' ?>>Central Time (CT)</option>
                            <option value="America/Denver" <?= $accountNormalizedTimezone === 'America/Denver' ? 'selected' : '' ?>>Mountain Time (MT)</option>
                            <option value="America/Los_Angeles" <?= $accountNormalizedTimezone === 'America/Los_Angeles' ? 'selected' : '' ?>>Pacific Time (PT)</option>
                        </optgroup>
                        <optgroup label="Europe">
                            <option value="Europe/London" <?= $accountNormalizedTimezone === 'Europe/London' ? 'selected' : '' ?>>London (GMT/BST)</option>
                            <option value="Europe/Paris" <?= $accountNormalizedTimezone === 'Europe/Paris' ? 'selected' : '' ?>>Paris (CET)</option>
                            <option value="Europe/Berlin" <?= $accountNormalizedTimezone === 'Europe/Berlin' ? 'selected' : '' ?>>Berlin (CET)</option>
                            <option value="Europe/Moscow" <?= $accountNormalizedTimezone === 'Europe/Moscow' ? 'selected' : '' ?>>Moscow (MSK)</option>
                        </optgroup>
                        <optgroup label="Asia Pacific">
                            <option value="Asia/Dubai" <?= $accountNormalizedTimezone === 'Asia/Dubai' ? 'selected' : '' ?>>Dubai (GST)</option>
                            <option value="Asia/Singapore" <?= $accountNormalizedTimezone === 'Asia/Singapore' ? 'selected' : '' ?>>Singapore (SGT)</option>
                            <option value="Asia/Tokyo" <?= $accountNormalizedTimezone === 'Asia/Tokyo' ? 'selected' : '' ?>>Tokyo (JST)</option>
                            <option value="Australia/Sydney" <?= $accountNormalizedTimezone === 'Australia/Sydney' ? 'selected' : '' ?>>Sydney (AEDT)</option>
                        </optgroup>
                        <optgroup label="Other">
                            <option value="UTC" <?= $accountNormalizedTimezone === 'UTC' ? 'selected' : '' ?>>UTC (Universal)</option>
                        </optgroup>
                    </select>
                    <?php if (isset($errors['timezone'])): ?>
                        <div style="color: #d32f2f; font-size: 12px; margin-top: 4px;"><?= htmlspecialchars($errors['timezone']) ?></div>
                    <?php endif; ?>
                </div>
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Currency</label>
                    <select name="currency" style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                        <option value="USD" <?= $accountCurrency === 'USD' ? 'selected' : '' ?>>USD - US Dollar</option>
                        <option value="EUR" <?= $accountCurrency === 'EUR' ? 'selected' : '' ?>>EUR - Euro</option>
                        <option value="GBP" <?= $accountCurrency === 'GBP' ? 'selected' : '' ?>>GBP - British Pound</option>
                        <option value="CAD" <?= $accountCurrency === 'CAD' ? 'selected' : '' ?>>CAD - Canadian Dollar</option>
                        <option value="AUD" <?= $accountCurrency === 'AUD' ? 'selected' : '' ?>>AUD - Australian Dollar</option>
                        <option value="JPY" <?= $accountCurrency === 'JPY' ? 'selected' : '' ?>>JPY - Japanese Yen</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Save profile settings</button>
        </form>

        <h3 style="margin: 0 0 16px 0; font-size: 18px; color: #3d5a26; padding-top: 24px; border-top: 1px solid #eee;">Change password</h3>
        <form method="POST" action="?page=settings&tab=account" style="max-width: 400px;">
            <?= \SimpleKuma\Auth\Csrf::field() ?>
            <input type="hidden" name="action" value="change_password">
            <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px;">Current password</label>
                <input type="password" name="current_password" required autocomplete="current-password"
                       style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                <?php if (isset($errors['current_password'])): ?>
                    <div style="color: #d32f2f; font-size: 12px; margin-top: 4px;"><?= htmlspecialchars($errors['current_password']) ?></div>
                <?php endif; ?>
            </div>
            <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px;">New password</label>
                <input type="password" name="new_password" required autocomplete="new-password"
                       style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                <div style="font-size: 12px; color: #666; margin-top: 4px;">Minimum 8 characters</div>
                <?php if (isset($errors['new_password'])): ?>
                    <div style="color: #d32f2f; font-size: 12px; margin-top: 4px;"><?= htmlspecialchars($errors['new_password']) ?></div>
                <?php endif; ?>
            </div>
            <div style="margin-bottom: 20px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px;">Confirm new password</label>
                <input type="password" name="confirm_password" required autocomplete="new-password"
                       style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                <?php if (isset($errors['confirm_password'])): ?>
                    <div style="color: #d32f2f; font-size: 12px; margin-top: 4px;"><?= htmlspecialchars($errors['confirm_password']) ?></div>
                <?php endif; ?>
            </div>
            <button type="submit" class="btn btn-primary">Update password</button>
        </form>

        <?php if (!empty($canEditLoginGate)): ?>
        <h3 style="margin: 40px 0 16px 0; font-size: 18px; color: #3d5a26; padding-top: 24px; border-top: 1px solid #eee;">Login page privacy</h3>
        <p style="color: #666; margin: 0 0 20px 0; font-size: 14px; max-width: 640px;">
            Hide <code>login.php</code> behind a secret URL token so scanners cannot find the admin login.
            This is an obscurity layer — keep using a strong password. Without the token, visitors are sent to your decoy URL (or Google if blank).
        </p>

        <?php if (!empty($loginGateRevealUrl)): ?>
        <div style="background: #fff8e1; border: 1px solid #f0c36d; border-radius: 4px; padding: 14px 16px; margin-bottom: 20px; max-width: 640px;">
            <div style="font-weight: 600; margin-bottom: 8px;">Your private login URL (copy now)</div>
            <code style="display: block; word-break: break-all; font-size: 13px;"><?= htmlspecialchars($loginGateRevealUrl) ?></code>
            <div style="font-size: 12px; color: #666; margin-top: 8px;">Bookmark this URL. The secret token cannot be shown again after you leave this page.</div>
        </div>
        <?php endif; ?>

        <form method="POST" action="?page=settings&tab=account" id="login-gate-form" style="max-width: 640px;">
            <?= \SimpleKuma\Auth\Csrf::field() ?>
            <input type="hidden" name="action" value="update_login_gate">

            <div style="margin-bottom: 20px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px;">URL parameter name</label>
                <input type="text" name="login_gate_param" required
                       value="<?= htmlspecialchars($loginGateParam ?? \SimpleKuma\Auth\LoginGate::DEFAULT_PARAM_NAME) ?>"
                       placeholder="mv"
                       pattern="[A-Za-z][A-Za-z0-9_]*"
                       maxlength="32"
                       style="width: 100%; max-width: 200px; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                <div style="font-size: 12px; color: #666; margin-top: 4px;">
                    The query key in your private URL. Default <code>mv</code> → <code>login.php?mv=yourtoken</code>.
                    Change both this and the token for full control (e.g. <code>login.php?x=MySecret99</code>).
                </div>
                <?php if (isset($errors['login_gate_param'])): ?>
                    <div style="color: #d32f2f; font-size: 12px; margin-top: 4px;"><?= htmlspecialchars($errors['login_gate_param']) ?></div>
                <?php endif; ?>
            </div>

            <div style="margin-bottom: 20px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px;">Secret token</label>
                <div style="position: relative; max-width: 400px;">
                    <input type="text" name="login_gate_secret" id="login-gate-secret"
                           value="<?= htmlspecialchars($loginGateTokenValue ?? '') ?>"
                           autocomplete="off"
                           autocorrect="off"
                           autocapitalize="off"
                           spellcheck="false"
                           data-lpignore="true"
                           data-1p-ignore="true"
                           data-form-type="other"
                           placeholder="<?= !empty($loginGateHasToken) ? 'Leave blank to keep current token' : 'e.g. mySecretToken99' ?>"
                           style="width: 100%; padding: 10px 52px 10px 10px; border: 2px solid #ddd; border-radius: 4px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;">
                    <button type="button" id="login-gate-secret-toggle"
                            aria-label="Show token"
                            title="Show token"
                            style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; font-size: 13px; color: #666; padding: 4px 6px;">
                        Show
                    </button>
                </div>
                <div style="font-size: 12px; color: #666; margin-top: 4px;">
                    Letters, numbers, hyphens, underscores. Min 8 characters.
                    You can fill this in and enable the gate in one save.
                    Current format: <code>login.php?<?= htmlspecialchars($loginGateParam ?? \SimpleKuma\Auth\LoginGate::DEFAULT_PARAM_NAME) ?>=yourtoken</code>
                </div>
                <?php if (!empty($loginGateHasToken)): ?>
                    <div style="font-size: 12px; color: #3d5a26; margin-top: 6px;">A token is already configured.</div>
                    <label style="display: flex; align-items: center; gap: 8px; margin-top: 10px; font-size: 13px; cursor: pointer;">
                        <input type="checkbox" name="login_gate_clear_token" value="1">
                        Clear configured token (disables gate until you set a new one)
                    </label>
                <?php endif; ?>
                <?php if (isset($errors['login_gate_token'])): ?>
                    <div style="color: #d32f2f; font-size: 12px; margin-top: 4px;"><?= htmlspecialchars($errors['login_gate_token']) ?></div>
                <?php endif; ?>
            </div>

            <div style="margin-bottom: 20px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px;">Decoy redirect URL (optional)</label>
                <input type="text" name="login_gate_redirect_url" inputmode="url"
                       value="<?= htmlspecialchars($loginGateRedirectUrl ?? '') ?>"
                       placeholder="Leave blank to send unauthorized visitors to Google"
                       style="width: 100%; max-width: 480px; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                <div style="font-size: 12px; color: #666; margin-top: 4px;">
                    Must be <code>http://</code> or <code>https://</code>. Blank = <?= htmlspecialchars(\SimpleKuma\Auth\LoginGate::FALLBACK_DECOY_URL) ?>
                </div>
                <?php if (isset($errors['login_gate_redirect_url'])): ?>
                    <div style="color: #d32f2f; font-size: 12px; margin-top: 4px;"><?= htmlspecialchars($errors['login_gate_redirect_url']) ?></div>
                <?php endif; ?>
            </div>

            <div style="margin-bottom: 20px; padding: 14px 16px; background: var(--bg-secondary, #f5f5f5); border-radius: 4px; max-width: 480px;">
                <label style="display: flex; align-items: center; gap: 10px; font-weight: 600; cursor: pointer;">
                    <input type="checkbox" name="login_gate_enabled" id="login-gate-enabled" value="1"
                           <?= !empty($loginGateEnabled) ? 'checked' : '' ?>>
                    Enable login gate
                </label>
                <div style="font-size: 12px; color: #666; margin-top: 8px;">
                    Turn this on after (or while) setting the parameter and token above — one Save is enough.
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Save login privacy</button>
        </form>
        <script>
        (function () {
            var input = document.getElementById('login-gate-secret');
            var btn = document.getElementById('login-gate-secret-toggle');
            if (!input || !btn) return;

            var masked = true;
            function applyMask() {
                input.style.webkitTextSecurity = masked ? 'disc' : 'none';
                // Never switch to type=password — password managers clear it on this page
                input.type = 'text';
                btn.textContent = masked ? 'Show' : 'Hide';
                btn.setAttribute('aria-label', masked ? 'Show token' : 'Hide token');
                btn.title = masked ? 'Show token' : 'Hide token';
            }
            applyMask();
            btn.addEventListener('click', function () {
                masked = !masked;
                applyMask();
                input.focus();
            });
        })();
        </script>
        <?php endif; ?>
    </div>
</div>
