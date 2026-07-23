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
    </div>
</div>
