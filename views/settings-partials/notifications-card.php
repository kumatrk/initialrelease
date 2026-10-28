<?php
/**
 * Account → Notifications.
 *
 * Expects $db and $canEditSettings.
 */
use SimpleKuma\Notify\MailCapability;
use SimpleKuma\Notify\NotificationSettings;
use SimpleKuma\Notify\PushSubscriptionStore;
use SimpleKuma\Notify\WebPushSender;
use SimpleKuma\Settings\SettingsManager;

$notifySettings = new SettingsManager($db);
$notify = NotificationSettings::forForm($notifySettings);
if (($_POST['action'] ?? '') === 'save_notifications' || ($_POST['action'] ?? '') === 'test_notifications') {
    $notify['reset_channel'] = (string) ($_POST['notify_reset_channel'] ?? $notify['reset_channel']);
    $notify['alerts']['email'] = !empty($_POST['notify_alert_email']);
    $notify['alerts']['discord'] = !empty($_POST['notify_alert_discord']);
    $notify['alerts']['slack'] = !empty($_POST['notify_alert_slack']);
    $notify['alerts']['telegram'] = !empty($_POST['notify_alert_telegram']);
    $notify['alerts']['ntfy'] = !empty($_POST['notify_alert_ntfy']);
    $notify['alerts']['browser'] = !empty($_POST['notify_alert_browser']);
    $notify['smtp_enabled'] = !empty($_POST['notify_smtp_enabled']);
    $notify['smtp_host'] = (string) ($_POST['notify_smtp_host'] ?? '');
    $notify['smtp_port'] = (int) ($_POST['notify_smtp_port'] ?? 587);
    $notify['smtp_username'] = (string) ($_POST['notify_smtp_username'] ?? '');
    $notify['smtp_encryption'] = (string) ($_POST['notify_smtp_encryption'] ?? 'tls');
    $notify['smtp_from'] = (string) ($_POST['notify_smtp_from'] ?? '');
    $notify['discord_url'] = (string) ($_POST['notify_discord_url'] ?? '');
    $notify['slack_url'] = (string) ($_POST['notify_slack_url'] ?? '');
    $notify['telegram_chat'] = (string) ($_POST['notify_telegram_chat'] ?? '');
    $notify['ntfy_topic'] = (string) ($_POST['notify_ntfy_topic'] ?? '');
}
$notifyMailLine = MailCapability::statusLine();
$notifyDisabled = empty($canEditSettings) ? 'disabled' : '';
$notifyResetChannels = [
    'email' => 'Email',
    'discord' => 'Discord',
    'slack' => 'Slack',
    'telegram' => 'Telegram',
    'ntfy' => 'ntfy',
];
$notifyChannels = $notifyResetChannels + ['browser' => 'Browser'];
$notifyPushCount = PushSubscriptionStore::count($db);
$notifyPushReady = WebPushSender::libraryReady() && PushSubscriptionStore::tableExists($db);
$notifyPosted = ($_POST['action'] ?? '') === 'save_notifications' || ($_POST['action'] ?? '') === 'test_notifications';
$notifyOpen = [
    'email' => !empty($notify['smtp_enabled']) || ($notifyPosted && ($notify['reset_channel'] === 'email' || !empty($notify['alerts']['email']))),
    'discord' => $notify['discord_url'] !== '' || ($notifyPosted && ($notify['reset_channel'] === 'discord' || !empty($notify['alerts']['discord']))),
    'slack' => $notify['slack_url'] !== '' || ($notifyPosted && ($notify['reset_channel'] === 'slack' || !empty($notify['alerts']['slack']))),
    'telegram' => $notify['telegram_chat'] !== '' || !empty($notify['has_telegram_token']) || ($notifyPosted && ($notify['reset_channel'] === 'telegram' || !empty($notify['alerts']['telegram']))),
    'ntfy' => $notify['ntfy_topic'] !== '' || ($notifyPosted && ($notify['reset_channel'] === 'ntfy' || !empty($notify['alerts']['ntfy']))),
    'browser' => !empty($notify['alerts']['browser']) || $notifyPushCount > 0 || ($notifyPosted && !empty($notify['alerts']['browser'])),
];
?>
<div class="account-section" id="notifications-card">
    <h3>Notifications</h3>
    <div>
        <p class="notify-hint">Checked rows receive Grizzly Guard alerts. Setup opens that row’s webhook, token, SMTP, or browser enable. <?= htmlspecialchars($notifyMailLine, ENT_QUOTES, 'UTF-8') ?></p>
        <?php if ($notify['last_test_ok'] === false && $notify['last_error'] !== ''): ?>
            <p class="notify-hint" style="color: #d32f2f;"><?= htmlspecialchars((string) $notify['last_error'], ENT_QUOTES, 'UTF-8') ?></p>
        <?php elseif ($notify['last_test_ok'] === true): ?>
            <p class="notify-hint" style="color: #2e7d32;">The last notification was accepted.</p>
        <?php endif; ?>

        <form method="post" action="?page=settings&tab=account">
            <?= \SimpleKuma\Auth\Csrf::field() ?>
            <input type="hidden" name="action" value="save_notifications" id="notify-action">

            <div class="notify-reset">
                <label class="notify-label" for="notify_reset_channel">Password reset</label>
                <select class="notify-field" name="notify_reset_channel" id="notify_reset_channel" <?= $notifyDisabled ?>>
                    <?php foreach ($notifyResetChannels as $notifyId => $notifyLabel): ?>
                        <option value="<?= $notifyId ?>" <?= $notify['reset_channel'] === $notifyId ? 'selected' : '' ?>><?= htmlspecialchars($notifyLabel, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="notify-hint">One channel gets the reset link. Skip ntfy for that link. Browser is alerts only.</p>
            </div>

            <div class="notify-board">
                <div class="notify-board__head">
                    <span>Channel</span>
                    <span>Alerts</span>
                    <span></span>
                </div>
                <?php foreach ($notifyChannels as $notifyId => $notifyLabel): ?>
                    <div class="notify-channel">
                        <div class="notify-channel__bar">
                            <span class="notify-channel__name"><?= htmlspecialchars($notifyLabel, ENT_QUOTES, 'UTF-8') ?></span>
                            <label class="notify-channel__choice">
                                <input type="checkbox" name="notify_alert_<?= $notifyId ?>" value="1" aria-label="Send Grizzly Guard alerts through <?= htmlspecialchars($notifyLabel, ENT_QUOTES, 'UTF-8') ?>" <?= !empty($notify['alerts'][$notifyId]) ? 'checked' : '' ?> <?= $notifyDisabled ?>>
                            </label>
                            <button type="button" class="btn btn-outline notify-setup" data-notify-setup><?= empty($notifyOpen[$notifyId]) ? 'Setup' : 'Hide' ?></button>
                        </div>
                        <div class="notify-channel__body" <?= empty($notifyOpen[$notifyId]) ? 'hidden' : '' ?> data-notify-body="<?= $notifyId ?>">
                            <?php if ($notifyId === 'email'): ?>
                                <p class="notify-hint">Server mail is used until SMTP is turned on.</p>
                                <label class="notify-channel__choice" style="margin-bottom: 8px;">
                                    <input type="checkbox" name="notify_smtp_enabled" value="1" <?= !empty($notify['smtp_enabled']) ? 'checked' : '' ?> <?= $notifyDisabled ?>>
                                    Send through SMTP
                                </label>
                                <div class="notify-grid">
                                    <div>
                                        <label class="notify-label" for="notify_smtp_host">Host</label>
                                        <input class="notify-field" type="text" name="notify_smtp_host" id="notify_smtp_host" value="<?= htmlspecialchars((string) $notify['smtp_host'], ENT_QUOTES, 'UTF-8') ?>" placeholder="smtp.example.com" <?= $notifyDisabled ?>>
                                    </div>
                                    <div>
                                        <label class="notify-label" for="notify_smtp_port">Port</label>
                                        <input class="notify-field" type="number" name="notify_smtp_port" id="notify_smtp_port" value="<?= (int) $notify['smtp_port'] ?>" <?= $notifyDisabled ?>>
                                    </div>
                                    <div>
                                        <label class="notify-label" for="notify_smtp_encryption">Encryption</label>
                                        <select class="notify-field" name="notify_smtp_encryption" id="notify_smtp_encryption" <?= $notifyDisabled ?>>
                                            <option value="tls" <?= $notify['smtp_encryption'] === 'tls' ? 'selected' : '' ?>>TLS</option>
                                            <option value="ssl" <?= $notify['smtp_encryption'] === 'ssl' ? 'selected' : '' ?>>SSL</option>
                                        </select>
                                    </div>
                                </div>
                                <label class="notify-label" for="notify_smtp_username">Username</label>
                                <input class="notify-field" type="text" name="notify_smtp_username" id="notify_smtp_username" value="<?= htmlspecialchars((string) $notify['smtp_username'], ENT_QUOTES, 'UTF-8') ?>" autocomplete="off" <?= $notifyDisabled ?>>
                                <label class="notify-label" for="notify_smtp_password">Password</label>
                                <input class="notify-field" type="password" name="notify_smtp_password" id="notify_smtp_password" value="" autocomplete="new-password" <?= $notifyDisabled ?>>
                                <p class="notify-hint" style="margin-top: 6px;"><?= !empty($notify['has_smtp_password']) ? 'A password is saved. Leave this blank to keep it.' : 'Required when SMTP is on.' ?></p>
                                <?php if (!empty($notify['has_smtp_password']) && $notifyDisabled === ''): ?>
                                    <label class="notify-channel__choice"><input type="checkbox" name="notify_clear_smtp_password" value="1"> Remove the saved SMTP password</label>
                                <?php endif; ?>
                                <label class="notify-label" for="notify_smtp_from">From address</label>
                                <input class="notify-field" type="email" name="notify_smtp_from" id="notify_smtp_from" value="<?= htmlspecialchars((string) $notify['smtp_from'], ENT_QUOTES, 'UTF-8') ?>" placeholder="noreply@example.com" <?= $notifyDisabled ?>>
                            <?php elseif ($notifyId === 'discord'): ?>
                                <p class="notify-hint">Private channel → Edit Channel → Integrations → Webhooks → Copy Webhook URL.</p>
                                <input class="notify-field" type="url" name="notify_discord_url" value="<?= htmlspecialchars((string) $notify['discord_url'], ENT_QUOTES, 'UTF-8') ?>" placeholder="https://discord.com/api/webhooks/…" autocomplete="off" <?= $notifyDisabled ?>>
                            <?php elseif ($notifyId === 'slack'): ?>
                                <p class="notify-hint">Paste the hooks.slack.com URL from Slack’s incoming webhook.</p>
                                <input class="notify-field" type="url" name="notify_slack_url" value="<?= htmlspecialchars((string) $notify['slack_url'], ENT_QUOTES, 'UTF-8') ?>" placeholder="https://hooks.slack.com/services/…" autocomplete="off" <?= $notifyDisabled ?>>
                            <?php elseif ($notifyId === 'telegram'): ?>
                                <p class="notify-hint">Create a bot with @BotFather, paste the token, then message the bot once and enter the chat id.</p>
                                <label class="notify-label">Bot token</label>
                                <input class="notify-field" type="password" name="notify_telegram_token" value="" autocomplete="new-password" <?= $notifyDisabled ?>>
                                <p class="notify-hint" style="margin-top: 6px;"><?= !empty($notify['has_telegram_token']) ? 'A token is saved. Leave this blank to keep it.' : 'From BotFather.' ?></p>
                                <?php if (!empty($notify['has_telegram_token']) && $notifyDisabled === ''): ?>
                                    <label class="notify-channel__choice"><input type="checkbox" name="notify_clear_telegram_token" value="1"> Remove the saved Telegram token</label>
                                <?php endif; ?>
                                <label class="notify-label">Chat id</label>
                                <input class="notify-field" type="text" name="notify_telegram_chat" value="<?= htmlspecialchars((string) $notify['telegram_chat'], ENT_QUOTES, 'UTF-8') ?>" placeholder="123456789" autocomplete="off" <?= $notifyDisabled ?>>
                            <?php elseif ($notifyId === 'browser'): ?>
                                <p class="notify-hint">Alerts only — not for password resets. Check Alerts, then enable this device. Production needs HTTPS. On iPhone, install the app first (Add to Home Screen), then enable.</p>
                                <?php if (!$notifyPushReady): ?>
                                    <p class="notify-hint" style="color: #d32f2f;">Browser push is not ready yet. Apply database migration 107 and confirm the web-push library is installed.</p>
                                <?php else: ?>
                                    <p class="notify-hint" id="notify-browser-status"><?= $notifyPushCount > 0
                                        ? htmlspecialchars($notifyPushCount . ' device' . ($notifyPushCount === 1 ? '' : 's') . ' subscribed.', ENT_QUOTES, 'UTF-8')
                                        : 'No device is subscribed yet.' ?></p>
                                    <?php if ($notifyDisabled === ''): ?>
                                        <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px;">
                                            <button type="button" class="btn btn-primary" id="notify-browser-enable">Enable on this device</button>
                                            <button type="button" class="btn btn-outline" id="notify-browser-disable">Disable this device</button>
                                        </div>
                                        <p class="notify-hint" id="notify-browser-message" style="margin-top: 8px;" hidden></p>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <p class="notify-hint">Subscribe to this topic in the ntfy app. The public server can read it, so keep reset links on another channel.</p>
                                <input class="notify-field" type="text" name="notify_ntfy_topic" id="notify_ntfy_topic" value="<?= htmlspecialchars((string) $notify['ntfy_topic'], ENT_QUOTES, 'UTF-8') ?>" autocomplete="off" <?= $notifyDisabled ?>>
                                <?php if ($notifyDisabled === ''): ?>
                                    <button type="button" class="btn btn-outline" id="notify-generate-topic" style="margin-top: 8px;">Generate a topic</button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($notifyDisabled === ''): ?>
                <div style="margin-top: 16px; display: flex; gap: 8px; flex-wrap: wrap;">
                    <button type="submit" class="btn btn-primary">Save notifications</button>
                    <button type="submit" class="btn btn-outline" onclick="document.getElementById('notify-action').value='test_notifications';">Send a test</button>
                </div>
            <?php endif; ?>
        </form>
        <script>
        (function () {
            var board = document.getElementById('notifications-card');
            if (!board) return;
            board.querySelectorAll('[data-notify-setup]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var row = button.closest('.notify-channel');
                    var body = row ? row.querySelector('[data-notify-body]') : null;
                    if (!body) return;
                    body.hidden = !body.hidden;
                    button.textContent = body.hidden ? 'Setup' : 'Hide';
                });
            });
            board.querySelectorAll('input[name^="notify_alert_"]').forEach(function (input) {
                input.addEventListener('change', function () {
                    var row = input.closest('.notify-channel');
                    var body = row ? row.querySelector('[data-notify-body]') : null;
                    var button = row ? row.querySelector('[data-notify-setup]') : null;
                    if (!body || !input.checked) return;
                    body.hidden = false;
                    if (button) button.textContent = 'Hide';
                });
            });
            var resetSelect = document.getElementById('notify_reset_channel');
            if (resetSelect) {
                resetSelect.addEventListener('change', function () {
                    var body = board.querySelector('[data-notify-body="' + resetSelect.value + '"]');
                    var button = body ? body.parentElement.querySelector('[data-notify-setup]') : null;
                    if (!body) return;
                    body.hidden = false;
                    if (button) button.textContent = 'Hide';
                });
            }
            var btn = document.getElementById('notify-generate-topic');
            var topic = document.getElementById('notify_ntfy_topic');
            if (btn && topic) {
                btn.addEventListener('click', function () {
                    var chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
                    var value = 'kuma';
                    var bytes = new Uint8Array(20);
                    if (window.crypto && window.crypto.getRandomValues) {
                        window.crypto.getRandomValues(bytes);
                    }
                    for (var i = 0; i < bytes.length; i++) {
                        value += chars[bytes[i] % chars.length];
                    }
                    topic.value = value;
                    var body = topic.closest('[data-notify-body]');
                    if (body) body.hidden = false;
                });
            }
            // Browser enable/disable is wired from pwa.js after KUMA_PWA_PUSH loads.
        })();
        </script>
    </div>
</div>
