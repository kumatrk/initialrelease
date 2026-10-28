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
        <div class="account-split">
        <form method="POST" action="?page=settings&tab=account" class="account-panel">
            <?= \SimpleKuma\Auth\Csrf::field() ?>
            <input type="hidden" name="action" value="update_profile">
            <h3>Profile</h3>
            <p class="account-meta">Username <strong><?= htmlspecialchars($currentUser['username'] ?? '') ?></strong>. Reports use this timezone and currency.</p>
            <div class="account-field">
                <label for="account-email">Email</label>
                <input type="email" name="email" id="account-email" required autocomplete="email"
                       value="<?= htmlspecialchars($accountEmail) ?>">
                <?php if (isset($errors['email'])): ?>
                    <div style="color: #d32f2f; font-size: 12px; margin-top: 4px;"><?= htmlspecialchars($errors['email']) ?></div>
                <?php endif; ?>
            </div>
            <div class="account-pair">
                <div>
                    <label for="account-timezone">Timezone</label>
                    <select name="timezone" id="account-timezone">
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
                        <optgroup label="Latin America">
                            <option value="America/Bogota" <?= $accountNormalizedTimezone === 'America/Bogota' ? 'selected' : '' ?>>Bogotá (COT, UTC-5)</option>
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
                    <label for="account-currency">Currency</label>
                    <select name="currency" id="account-currency">
                        <option value="USD" <?= $accountCurrency === 'USD' ? 'selected' : '' ?>>USD - US Dollar</option>
                        <option value="EUR" <?= $accountCurrency === 'EUR' ? 'selected' : '' ?>>EUR - Euro</option>
                        <option value="GBP" <?= $accountCurrency === 'GBP' ? 'selected' : '' ?>>GBP - British Pound</option>
                        <option value="CAD" <?= $accountCurrency === 'CAD' ? 'selected' : '' ?>>CAD - Canadian Dollar</option>
                        <option value="AUD" <?= $accountCurrency === 'AUD' ? 'selected' : '' ?>>AUD - Australian Dollar</option>
                        <option value="JPY" <?= $accountCurrency === 'JPY' ? 'selected' : '' ?>>JPY - Japanese Yen</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Save profile</button>
        </form>

        <form method="POST" action="?page=settings&tab=account" class="account-panel">
            <?= \SimpleKuma\Auth\Csrf::field() ?>
            <input type="hidden" name="action" value="change_password">
            <h3>Password</h3>
            <div class="account-field">
                <label for="account-current-password">Current password</label>
                <input type="password" name="current_password" id="account-current-password" required autocomplete="current-password">
                <?php if (isset($errors['current_password'])): ?>
                    <div style="color: #d32f2f; font-size: 12px; margin-top: 4px;"><?= htmlspecialchars($errors['current_password']) ?></div>
                <?php endif; ?>
            </div>
            <div class="account-pair">
                <div>
                    <label for="account-new-password">New password</label>
                    <input type="password" name="new_password" id="account-new-password" required autocomplete="new-password">
                    <?php if (isset($errors['new_password'])): ?>
                        <div style="color: #d32f2f; font-size: 12px; margin-top: 4px;"><?= htmlspecialchars($errors['new_password']) ?></div>
                    <?php endif; ?>
                </div>
                <div>
                    <label for="account-confirm-password">Confirm</label>
                    <input type="password" name="confirm_password" id="account-confirm-password" required autocomplete="new-password">
                    <?php if (isset($errors['confirm_password'])): ?>
                        <div style="color: #d32f2f; font-size: 12px; margin-top: 4px;"><?= htmlspecialchars($errors['confirm_password']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <p class="account-meta">At least 8 characters.</p>
            <button type="submit" class="btn btn-primary">Update password</button>
        </form>
        </div>

        <?php if (!empty($canEditLoginGate)): ?>
        <div class="account-section">
        <h3>Login page privacy</h3>
        <p class="notify-hint">Hide login.php behind a secret URL. Visitors without the token go to the decoy page. This still needs a strong password.</p>

        <?php
        $loginGateOpen = isset($errors['login_gate_param']) || isset($errors['login_gate_token']) || isset($errors['login_gate_redirect_url']);
        ?>
        <?php if (!empty($loginGateRevealUrl)): ?>
        <div style="background: #fff8e1; border: 1px solid #f0c36d; border-radius: 4px; padding: 14px 16px; margin-bottom: 12px; max-width: 640px;">
            <div style="font-weight: 600; margin-bottom: 8px;">Your private login URL (copy now)</div>
            <code style="display: block; word-break: break-all; font-size: 13px;"><?= htmlspecialchars($loginGateRevealUrl) ?></code>
            <div style="font-size: 12px; color: #666; margin-top: 8px;">Bookmark this URL. The secret token cannot be shown again after you leave this page.</div>
        </div>
        <?php endif; ?>

        <form method="POST" action="?page=settings&tab=account" id="login-gate-form">
            <?= \SimpleKuma\Auth\Csrf::field() ?>
            <input type="hidden" name="action" value="update_login_gate">

            <div class="notify-board" id="login-gate-board">
                <div class="notify-board__head">
                    <span>Gate</span>
                    <span>On</span>
                    <span></span>
                </div>
                <div class="notify-channel">
                    <div class="notify-channel__bar">
                        <span class="notify-channel__name">Login page</span>
                        <label class="notify-channel__choice">
                            <input type="checkbox" name="login_gate_enabled" id="login-gate-enabled" value="1" aria-label="Enable login gate" <?= !empty($loginGateEnabled) ? 'checked' : '' ?>>
                        </label>
                        <button type="button" class="btn btn-outline notify-setup" id="login-gate-setup"><?= $loginGateOpen ? 'Hide' : 'Setup' ?></button>
                    </div>
                    <div class="notify-channel__body" id="login-gate-body" <?= $loginGateOpen ? '' : 'hidden' ?>>
                        <label class="notify-label" for="login-gate-param">URL parameter</label>
                        <input class="notify-field" type="text" name="login_gate_param" id="login-gate-param" required
                               value="<?= htmlspecialchars($loginGateParam ?? \SimpleKuma\Auth\LoginGate::DEFAULT_PARAM_NAME) ?>"
                               placeholder="mv"
                               pattern="[A-Za-z][A-Za-z0-9_]*"
                               maxlength="32">
                        <p class="notify-hint" style="margin-top: 6px;">Query key in the private URL. <code>mv</code> makes <code>login.php?mv=yourtoken</code>.</p>
                        <?php if (isset($errors['login_gate_param'])): ?>
                            <p class="notify-hint" style="color: #d32f2f;"><?= htmlspecialchars($errors['login_gate_param']) ?></p>
                        <?php endif; ?>

                        <label class="notify-label" for="login-gate-secret">Secret token</label>
                        <div style="position: relative; max-width: 480px;">
                            <input class="notify-field" type="text" name="login_gate_secret" id="login-gate-secret"
                                   value="<?= htmlspecialchars($loginGateTokenValue ?? '') ?>"
                                   autocomplete="off"
                                   autocorrect="off"
                                   autocapitalize="off"
                                   spellcheck="false"
                                   data-lpignore="true"
                                   data-1p-ignore="true"
                                   data-form-type="other"
                                   placeholder="<?= !empty($loginGateHasToken) ? 'Leave blank to keep current token' : 'e.g. mySecretToken99' ?>"
                                   style="padding-right: 52px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;">
                            <button type="button" id="login-gate-secret-toggle"
                                    aria-label="Show token"
                                    title="Show token"
                                    style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; font-size: 13px; color: #666; padding: 4px 6px;">
                                Show
                            </button>
                        </div>
                        <p class="notify-hint" style="margin-top: 6px;">Letters, numbers, hyphens, and underscores. At least 8 characters. You can set the token and turn the gate on in one save.</p>
                        <?php if (!empty($loginGateHasToken)): ?>
                            <p class="notify-hint">A token is already saved.</p>
                            <label class="notify-channel__choice">
                                <input type="checkbox" name="login_gate_clear_token" value="1">
                                Remove the saved token
                            </label>
                        <?php endif; ?>
                        <?php if (isset($errors['login_gate_token'])): ?>
                            <p class="notify-hint" style="color: #d32f2f;"><?= htmlspecialchars($errors['login_gate_token']) ?></p>
                        <?php endif; ?>

                        <label class="notify-label" for="login-gate-redirect">Decoy URL</label>
                        <input class="notify-field" type="text" name="login_gate_redirect_url" id="login-gate-redirect" inputmode="url"
                               value="<?= htmlspecialchars($loginGateRedirectUrl ?? '') ?>"
                               placeholder="Leave blank to send visitors to Google">
                        <p class="notify-hint" style="margin-top: 6px;">Must start with http:// or https://. Blank sends people to <?= htmlspecialchars(\SimpleKuma\Auth\LoginGate::FALLBACK_DECOY_URL) ?>.</p>
                        <?php if (isset($errors['login_gate_redirect_url'])): ?>
                            <p class="notify-hint" style="color: #d32f2f;"><?= htmlspecialchars($errors['login_gate_redirect_url']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="margin-top: 16px;">Save login privacy</button>
        </form>
        <script>
        (function () {
            var setup = document.getElementById('login-gate-setup');
            var body = document.getElementById('login-gate-body');
            var enabled = document.getElementById('login-gate-enabled');
            function showGate() {
                if (!body) return;
                body.hidden = false;
                if (setup) setup.textContent = 'Hide';
            }
            if (setup && body) {
                setup.addEventListener('click', function () {
                    body.hidden = !body.hidden;
                    setup.textContent = body.hidden ? 'Setup' : 'Hide';
                });
            }
            if (enabled) {
                enabled.addEventListener('change', function () {
                    if (enabled.checked) showGate();
                });
            }
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
        </div>
        <?php endif; ?>

        <?php require __DIR__ . '/notifications-card.php'; ?>
    </div>
</div>
