<?php

declare(strict_types=1);

namespace SimpleKuma\Notify;

use mysqli;
use SimpleKuma\Settings\SettingsManager;
use SimpleKuma\Utils\SecretEncryption;

/**
 * Account notification channels. Secrets are encrypted inside one settings value.
 */
final class NotificationSettings
{
    public const KEY = 'notification_setup';

    /**
     * @return array<string, mixed>
     */
    public static function load(SettingsManager $settings): array
    {
        $decoded = json_decode((string) $settings->get(self::KEY, ''), true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        $alerts = is_array($decoded['alerts'] ?? null) ? $decoded['alerts'] : [];

        return [
            'reset_channel' => self::channelName((string) ($decoded['reset_channel'] ?? 'email')),
            'alerts' => [
                'email' => !empty($alerts['email']),
                'discord' => !empty($alerts['discord']),
                'slack' => !empty($alerts['slack']),
                'telegram' => !empty($alerts['telegram']),
                'ntfy' => !empty($alerts['ntfy']),
                'browser' => !empty($alerts['browser']),
            ],
            'smtp_enabled' => !empty($decoded['smtp_enabled']),
            'smtp_host' => trim((string) ($decoded['smtp_host'] ?? '')),
            'smtp_port' => (int) ($decoded['smtp_port'] ?? 587) ?: 587,
            'smtp_username' => trim((string) ($decoded['smtp_username'] ?? '')),
            'smtp_encryption' => MailCapability::normalizeEncryption((string) ($decoded['smtp_encryption'] ?? 'tls')),
            'smtp_from' => trim((string) ($decoded['smtp_from'] ?? '')),
            'smtp_password' => self::decrypt((string) ($decoded['smtp_password_enc'] ?? '')),
            'discord_url' => self::decrypt((string) ($decoded['discord_url_enc'] ?? '')),
            'slack_url' => self::decrypt((string) ($decoded['slack_url_enc'] ?? '')),
            'telegram_token' => self::decrypt((string) ($decoded['telegram_token_enc'] ?? '')),
            'telegram_chat' => trim((string) ($decoded['telegram_chat'] ?? '')),
            'ntfy_topic' => trim((string) ($decoded['ntfy_topic'] ?? '')),
            'last_error' => trim((string) ($decoded['last_error'] ?? '')),
            'last_test_ok' => array_key_exists('last_test_ok', $decoded) ? (bool) $decoded['last_test_ok'] : null,
        ];
    }

    /**
     * Form view. Passwords and the Telegram token are not returned.
     *
     * @return array<string, mixed>
     */
    public static function forForm(SettingsManager $settings): array
    {
        $loaded = self::load($settings);
        $loaded['has_smtp_password'] = $loaded['smtp_password'] !== '';
        $loaded['has_telegram_token'] = $loaded['telegram_token'] !== '';
        unset($loaded['smtp_password'], $loaded['telegram_token']);

        return $loaded;
    }

    /**
     * @param array<string, mixed> $post
     */
    public static function saveFromPost(SettingsManager $settings, array $post): ?string
    {
        $current = self::load($settings);
        $reset = self::channelName((string) ($post['notify_reset_channel'] ?? 'email'));
        $alerts = [
            'email' => !empty($post['notify_alert_email']),
            'discord' => !empty($post['notify_alert_discord']),
            'slack' => !empty($post['notify_alert_slack']),
            'telegram' => !empty($post['notify_alert_telegram']),
            'ntfy' => !empty($post['notify_alert_ntfy']),
            'browser' => !empty($post['notify_alert_browser']),
        ];

        $smtpPassword = (string) ($post['notify_smtp_password'] ?? '');
        if ($smtpPassword === '') {
            $smtpPassword = (string) $current['smtp_password'];
        }
        if (!empty($post['notify_clear_smtp_password'])) {
            $smtpPassword = '';
        }
        $telegramToken = trim((string) ($post['notify_telegram_token'] ?? ''));
        if ($telegramToken === '') {
            $telegramToken = (string) $current['telegram_token'];
        }
        if (!empty($post['notify_clear_telegram_token'])) {
            $telegramToken = '';
        }

        $next = [
            'reset_channel' => $reset,
            'alerts' => $alerts,
            'smtp_enabled' => !empty($post['notify_smtp_enabled']),
            'smtp_host' => trim((string) ($post['notify_smtp_host'] ?? '')),
            'smtp_port' => (int) ($post['notify_smtp_port'] ?? 587) ?: 587,
            'smtp_username' => trim((string) ($post['notify_smtp_username'] ?? '')),
            'smtp_encryption' => MailCapability::normalizeEncryption((string) ($post['notify_smtp_encryption'] ?? 'tls')),
            'smtp_from' => trim((string) ($post['notify_smtp_from'] ?? '')),
            'smtp_password' => $smtpPassword,
            'discord_url' => trim((string) ($post['notify_discord_url'] ?? '')),
            'slack_url' => trim((string) ($post['notify_slack_url'] ?? '')),
            'telegram_token' => $telegramToken,
            'telegram_chat' => trim((string) ($post['notify_telegram_chat'] ?? '')),
            'ntfy_topic' => trim((string) ($post['notify_ntfy_topic'] ?? '')),
            'last_error' => (string) $current['last_error'],
            'last_test_ok' => $current['last_test_ok'],
        ];

        $error = self::validate($next);
        if ($error !== null) {
            return $error;
        }
        self::persist($settings, $next);

        return null;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function applyInstall(mysqli $db, array $config): void
    {
        $settings = new SettingsManager($db);
        $next = self::load($settings);
        $next['reset_channel'] = self::channelName((string) ($config['reset_channel'] ?? 'email'));
        $next['alerts'][$next['reset_channel']] = true;
        if (isset($config['smtp']) && is_array($config['smtp'])) {
            $smtp = $config['smtp'];
            $next['smtp_enabled'] = true;
            $next['smtp_host'] = trim((string) ($smtp['host'] ?? ''));
            $next['smtp_port'] = (int) ($smtp['port'] ?? 587) ?: 587;
            $next['smtp_username'] = trim((string) ($smtp['username'] ?? ''));
            $next['smtp_encryption'] = MailCapability::normalizeEncryption((string) ($smtp['encryption'] ?? 'tls'));
            $next['smtp_from'] = trim((string) ($smtp['from'] ?? ''));
            $next['smtp_password'] = (string) ($smtp['password'] ?? '');
        }
        if (!empty($config['discord_url'])) {
            $next['discord_url'] = (string) $config['discord_url'];
        }
        if (!empty($config['slack_url'])) {
            $next['slack_url'] = (string) $config['slack_url'];
        }
        if (!empty($config['ntfy_topic'])) {
            $next['ntfy_topic'] = (string) $config['ntfy_topic'];
        }
        if (!self::emailCanSend($next)) {
            $next['alerts']['email'] = false;
        }
        self::persist($settings, $next);
    }

    /**
     * @param array<string, mixed> $post
     * @return array{error: ?string, config: ?array<string, mixed>}
     */
    public static function testInstallChannel(string $via, array $post): array
    {
        $via = self::channelName($via);
        if ($via === 'discord') {
            $url = trim((string) ($post['install_discord_url'] ?? ''));
            $error = self::validateDiscordUrl($url);
            if ($error !== null) {
                return ['error' => $error, 'config' => null];
            }
            $posted = NotificationDispatcher::postDiscord($url, 'Simple KUMA can send password reset links to this channel.');
            if ($posted !== null) {
                return ['error' => 'Discord did not accept the test. ' . $posted, 'config' => null];
            }

            return ['error' => null, 'config' => ['reset_channel' => 'discord', 'discord_url' => $url]];
        }
        if ($via === 'slack') {
            $url = trim((string) ($post['install_slack_url'] ?? ''));
            $error = self::validateSlackUrl($url);
            if ($error !== null) {
                return ['error' => $error, 'config' => null];
            }
            $posted = NotificationDispatcher::postSlack($url, 'Simple KUMA can send password reset links to this channel.');
            if ($posted !== null) {
                return ['error' => 'Slack did not accept the test. ' . $posted, 'config' => null];
            }

            return ['error' => null, 'config' => ['reset_channel' => 'slack', 'slack_url' => $url]];
        }
        if ($via === 'ntfy') {
            $topic = trim((string) ($post['install_ntfy_topic'] ?? ''));
            $error = self::validateNtfyTopic($topic);
            if ($error !== null) {
                return ['error' => $error, 'config' => null];
            }
            $posted = NotificationDispatcher::postNtfy($topic, 'Simple KUMA', 'Password reset links from this install can reach this topic.');
            if ($posted !== null) {
                return ['error' => 'ntfy did not accept the test. ' . $posted, 'config' => null];
            }

            return ['error' => null, 'config' => ['reset_channel' => 'ntfy', 'ntfy_topic' => $topic]];
        }

        return ['error' => 'Choose SMTP, Discord, Slack, or ntfy so a reset link has somewhere to go.', 'config' => null];
    }

    public static function channelName(string $channel): string
    {
        $channel = strtolower(trim($channel));
        if (in_array($channel, ['email', 'discord', 'slack', 'telegram', 'ntfy'], true)) {
            return $channel;
        }

        return 'email';
    }

    public static function validateDiscordUrl(string $url): ?string
    {
        $host = self::httpsHost($url);
        if ($host === null || !in_array($host, ['discord.com', 'discordapp.com'], true)) {
            return 'Discord needs an https://discord.com/api/webhooks/… URL from a private channel.';
        }
        if (!str_contains($url, '/api/webhooks/')) {
            return 'That Discord URL is not a webhook. Create one under channel settings, Integrations.';
        }

        return null;
    }

    public static function validateSlackUrl(string $url): ?string
    {
        $host = self::httpsHost($url);
        if ($host !== 'hooks.slack.com') {
            return 'Slack needs an https://hooks.slack.com/services/… incoming webhook URL.';
        }

        return null;
    }

    public static function validateNtfyTopic(string $topic): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{12,64}$/', $topic)) {
            return 'The ntfy topic must be 12 to 64 letters, numbers, dashes, or underscores. Use Generate so it cannot be guessed.';
        }

        return null;
    }

    public static function validateTelegramToken(string $token): ?string
    {
        if (!preg_match('/^\d{6,}:[A-Za-z0-9_-]{20,}$/', $token)) {
            return 'The Telegram bot token from BotFather does not look right.';
        }

        return null;
    }

    public static function emailCanSend(array $config): bool
    {
        if (empty($config['smtp_enabled']) || !MailCapability::validHost(trim((string) ($config['smtp_host'] ?? '')))) {
            return MailCapability::phpMailAvailable();
        }
        $username = trim((string) ($config['smtp_username'] ?? ''));
        if ($username !== '' && (string) ($config['smtp_password'] ?? '') === '') {
            return MailCapability::phpMailAvailable();
        }

        return true;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function rememberResult(SettingsManager $settings, bool $ok, string $error): void
    {
        $config = self::load($settings);
        $config['last_test_ok'] = $ok;
        $config['last_error'] = $ok ? '' : $error;
        self::persist($settings, $config);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function validate(array $config): ?string
    {
        if (!empty($config['smtp_enabled'])) {
            if (!MailCapability::validHost((string) $config['smtp_host'])) {
                return 'Enter the SMTP host from your email provider.';
            }
            $port = (int) ($config['smtp_port'] ?? 0);
            if ($port < 1 || $port > 65535) {
                return 'SMTP port must be between 1 and 65535.';
            }
            if (!filter_var((string) $config['smtp_from'], FILTER_VALIDATE_EMAIL)) {
                return 'Enter a From address on a domain your mail host will send for.';
            }
            if (trim((string) $config['smtp_username']) !== '' && (string) $config['smtp_password'] === '') {
                return 'Enter the SMTP password. Leave it blank later to keep the saved one.';
            }
        }

        if ((string) $config['discord_url'] !== '') {
            $error = self::validateDiscordUrl((string) $config['discord_url']);
            if ($error !== null) {
                return $error;
            }
        }
        if ((string) $config['slack_url'] !== '') {
            $error = self::validateSlackUrl((string) $config['slack_url']);
            if ($error !== null) {
                return $error;
            }
        }
        if ((string) $config['ntfy_topic'] !== '') {
            $error = self::validateNtfyTopic((string) $config['ntfy_topic']);
            if ($error !== null) {
                return $error;
            }
        }
        if ((string) $config['telegram_token'] !== '') {
            $error = self::validateTelegramToken((string) $config['telegram_token']);
            if ($error !== null) {
                return $error;
            }
        }

        $reset = (string) $config['reset_channel'];
        if ($reset === 'browser') {
            return 'Password reset cannot use the browser channel. Choose email, Discord, Slack, Telegram, or ntfy.';
        }
        $ready = self::channelReady($config, $reset);
        if ($ready !== null) {
            return 'Password reset is set to ' . $reset . '. ' . $ready;
        }
        foreach ($config['alerts'] as $channel => $on) {
            if (!$on) {
                continue;
            }
            $problem = self::channelReady($config, (string) $channel);
            if ($problem !== null) {
                return $problem;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function channelReady(array $config, string $channel): ?string
    {
        if ($channel === 'email') {
            return self::emailCanSend($config) ? null : 'Email cannot send until PHP mail() is available or SMTP is filled in.';
        }
        if ($channel === 'discord') {
            return self::validateDiscordUrl((string) ($config['discord_url'] ?? '')) ?? null;
        }
        if ($channel === 'slack') {
            return self::validateSlackUrl((string) ($config['slack_url'] ?? '')) ?? null;
        }
        if ($channel === 'ntfy') {
            return self::validateNtfyTopic((string) ($config['ntfy_topic'] ?? '')) ?? null;
        }
        if ($channel === 'telegram') {
            $tokenError = self::validateTelegramToken((string) ($config['telegram_token'] ?? ''));
            if ($tokenError !== null) {
                return $tokenError;
            }
            $chat = (string) ($config['telegram_chat'] ?? '');
            if (!preg_match('/^-?\d{1,20}$/', $chat) && !preg_match('/^@[A-Za-z0-9_]{4,}$/', $chat)) {
                return 'Enter the Telegram chat id (the number from your bot) or a public @channel name.';
            }

            return null;
        }
        if ($channel === 'browser') {
            if (!WebPushSender::libraryReady()) {
                return 'Browser push needs the web-push library on this server.';
            }

            return null;
        }

        return 'Choose a notification channel.';
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function persist(SettingsManager $settings, array $config): void
    {
        $stored = [
            'reset_channel' => self::channelName((string) ($config['reset_channel'] ?? 'email')),
            'alerts' => [
                'email' => !empty($config['alerts']['email']),
                'discord' => !empty($config['alerts']['discord']),
                'slack' => !empty($config['alerts']['slack']),
                'telegram' => !empty($config['alerts']['telegram']),
                'ntfy' => !empty($config['alerts']['ntfy']),
                'browser' => !empty($config['alerts']['browser']),
            ],
            'smtp_enabled' => !empty($config['smtp_enabled']),
            'smtp_host' => trim((string) ($config['smtp_host'] ?? '')),
            'smtp_port' => (int) ($config['smtp_port'] ?? 587) ?: 587,
            'smtp_username' => trim((string) ($config['smtp_username'] ?? '')),
            'smtp_encryption' => MailCapability::normalizeEncryption((string) ($config['smtp_encryption'] ?? 'tls')),
            'smtp_from' => trim((string) ($config['smtp_from'] ?? '')),
            'smtp_password_enc' => self::encrypt((string) ($config['smtp_password'] ?? '')),
            'discord_url_enc' => self::encrypt((string) ($config['discord_url'] ?? '')),
            'slack_url_enc' => self::encrypt((string) ($config['slack_url'] ?? '')),
            'telegram_token_enc' => self::encrypt((string) ($config['telegram_token'] ?? '')),
            'telegram_chat' => trim((string) ($config['telegram_chat'] ?? '')),
            'ntfy_topic' => trim((string) ($config['ntfy_topic'] ?? '')),
            'last_error' => trim((string) ($config['last_error'] ?? '')),
            'last_test_ok' => $config['last_test_ok'],
        ];
        $json = json_encode($stored, JSON_UNESCAPED_SLASHES);
        $settings->set(self::KEY, $json === false ? '{}' : $json);
    }

    private static function encrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return SecretEncryption::encrypt($value);
    }

    private static function decrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }
        try {
            return SecretEncryption::decryptFlexible($value);
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function httpsHost(string $url): ?string
    {
        if (!preg_match('#^https://#i', $url) || preg_match('/\s/', $url)) {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }

        return strtolower($host);
    }
}
