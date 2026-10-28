<?php

declare(strict_types=1);

namespace SimpleKuma\Notify;

use mysqli;
use SimpleKuma\Services\EmailService;
use SimpleKuma\Settings\SettingsManager;

/**
 * Sends password resets to one channel and alerts to every channel that is on.
 */
final class NotificationDispatcher
{
    public static function sendReset(mysqli $db, string $toEmail, string $resetUrl): bool
    {
        $settings = new SettingsManager($db);
        $config = NotificationSettings::load($settings);
        $channel = (string) $config['reset_channel'];
        $title = 'Reset your Simple KUMA password';
        $text = "Use this link within 1 hour:\n" . $resetUrl . "\nIf you did not ask for this, ignore it.";
        $error = self::deliver($db, $config, $channel, $toEmail, $title, $text, $resetUrl);
        NotificationSettings::rememberResult($settings, $error === null, $error ?? '');
        if ($error !== null) {
            error_log('Simple KUMA password reset was not delivered on channel ' . $channel);

            return false;
        }

        return true;
    }

    public static function sendAlert(mysqli $db, string $title, string $body, string $legacyChannel = ''): void
    {
        $settings = new SettingsManager($db);
        $config = NotificationSettings::load($settings);
        $adminEmail = self::adminEmail($db);
        $sent = [];
        foreach ($config['alerts'] as $channel => $on) {
            if (!$on) {
                continue;
            }
            $error = self::deliver($db, $config, (string) $channel, $adminEmail, $title, $body, null);
            if ($error === null) {
                $sent[(string) $channel] = true;
            } else {
                error_log('Simple KUMA alert was not delivered on channel ' . $channel);
            }
        }
        self::deliverLegacy($db, $config, $legacyChannel, $title, $body, $adminEmail, $sent);
    }

    /**
     * @return list<string> Empty when every selected destination accepted the test.
     */
    public static function sendTest(mysqli $db): array
    {
        $settings = new SettingsManager($db);
        $config = NotificationSettings::load($settings);
        $adminEmail = self::adminEmail($db);
        $title = 'Simple KUMA test';
        $body = 'Notifications from this install are working.';
        $failures = [];
        $targets = [(string) $config['reset_channel'] => true];
        foreach ($config['alerts'] as $channel => $on) {
            if ($on) {
                $targets[(string) $channel] = true;
            }
        }
        foreach (array_keys($targets) as $channel) {
            $error = self::deliver($db, $config, $channel, $adminEmail, $title, $body, null);
            if ($error !== null) {
                $failures[] = $channel . ': ' . $error;
            }
        }
        NotificationSettings::rememberResult($settings, $failures === [], implode(' ', $failures));

        return $failures;
    }

    public static function postDiscord(string $url, string $text): ?string
    {
        return self::postJson($url, ['content' => self::clip($text, 1800)]);
    }

    public static function postSlack(string $url, string $text): ?string
    {
        return self::postJson($url, ['text' => self::clip($text, 1800)]);
    }

    public static function postNtfy(string $topic, string $title, string $text): ?string
    {
        return self::postRaw(
            'https://ntfy.sh/' . rawurlencode($topic),
            self::clip($text, 1800),
            ['Title: ' . self::clip($title, 120)]
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function deliver(mysqli $db, array $config, string $channel, string $email, string $title, string $text, ?string $resetUrl): ?string
    {
        if ($channel === 'email') {
            if (!NotificationSettings::emailCanSend($config)) {
                return 'Email is not set up on this server.';
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return 'There is no email address to send to.';
            }
            $service = new EmailService($db);
            $service->applySmtp($config);
            if ($resetUrl !== null) {
                return $service->sendPasswordResetEmail($email, '', $resetUrl) ? null : 'The mail server did not accept the reset email.';
            }
            $html = '<p>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</p><p>' . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '</p>';

            return $service->sendMessage($email, $title, $html) ? null : 'The mail server did not accept the message.';
        }
        if ($channel === 'discord') {
            return self::postDiscord((string) $config['discord_url'], $title . "\n" . $text);
        }
        if ($channel === 'slack') {
            return self::postSlack((string) $config['slack_url'], $title . "\n" . $text);
        }
        if ($channel === 'ntfy') {
            return self::postNtfy((string) $config['ntfy_topic'], $title, $text);
        }
        if ($channel === 'telegram') {
            return self::postTelegram((string) $config['telegram_token'], (string) $config['telegram_chat'], $title . "\n" . $text);
        }
        if ($channel === 'browser') {
            if ($resetUrl !== null) {
                return 'Password reset cannot use the browser channel.';
            }

            return WebPushSender::sendAlert($db, $title, $text);
        }

        return 'Unknown notification channel.';
    }

    /**
     * The extra address on the Grizzly Guard tab. Skipped when that same destination was already sent.
     *
     * @param array<string, mixed> $config
     * @param array<string, bool> $sent
     */
    private static function deliverLegacy(mysqli $db, array $config, string $channel, string $title, string $body, string $adminEmail, array $sent): void
    {
        $channel = trim($channel);
        if ($channel === '') {
            return;
        }
        $text = $title . "\n" . $body;
        if (filter_var($channel, FILTER_VALIDATE_EMAIL)) {
            if ($channel === $adminEmail && !empty($sent['email'])) {
                return;
            }
            $service = new EmailService($db);
            $service->applySmtp($config);
            $html = '<p>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</p><p>' . nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')) . '</p>';
            if (!$service->sendMessage($channel, $title, $html)) {
                error_log('Simple KUMA alert was not delivered on the extra Guard address');
            }

            return;
        }
        if (NotificationSettings::validateDiscordUrl($channel) === null) {
            if (!empty($sent['discord']) && $channel === (string) $config['discord_url']) {
                return;
            }
            $error = self::postDiscord($channel, $text);
            if ($error !== null) {
                error_log('Simple KUMA alert was not delivered on the extra Guard address');
            }

            return;
        }
        if (NotificationSettings::validateSlackUrl($channel) === null) {
            if (!empty($sent['slack']) && $channel === (string) $config['slack_url']) {
                return;
            }
            $error = self::postSlack($channel, $text);
            if ($error !== null) {
                error_log('Simple KUMA alert was not delivered on the extra Guard address');
            }

            return;
        }
        if (!preg_match('#^https://#i', $channel)) {
            return;
        }
        $error = self::postJson($channel, ['source' => 'grizzly_guard', 'message' => self::clip($text, 1800)]);
        if ($error !== null) {
            error_log('Simple KUMA alert was not delivered on the extra Guard address');
        }
    }

    private static function postTelegram(string $token, string $chatId, string $text): ?string
    {
        if (NotificationSettings::validateTelegramToken($token) !== null) {
            return 'The Telegram bot token is missing.';
        }
        $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';

        return self::postJson($url, [
            'chat_id' => $chatId,
            'text' => self::clip($text, 1800),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function postJson(string $url, array $payload): ?string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return 'The message could not be encoded.';
        }

        return self::postRaw($url, $json, ['Content-Type: application/json']);
    }

    /**
     * @param list<string> $headers
     */
    private static function postRaw(string $url, string $body, array $headers): ?string
    {
        if (!function_exists('curl_init')) {
            return 'This server does not have curl, so the webhook could not be sent.';
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return 'The webhook could not be opened.';
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code === 0) {
            return 'The channel could not be reached.';
        }
        if ($code < 200 || $code >= 300) {
            return 'The channel returned HTTP ' . $code . '.';
        }

        return null;
    }

    private static function adminEmail(mysqli $db): string
    {
        $result = $db->query("SELECT email FROM users WHERE is_active = 1 AND email <> '' ORDER BY id ASC LIMIT 1");
        if (!$result) {
            return '';
        }
        $row = $result->fetch_assoc();

        return trim((string) ($row['email'] ?? ''));
    }

    private static function clip(string $value, int $max): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }

        return substr($value, 0, $max);
    }
}
