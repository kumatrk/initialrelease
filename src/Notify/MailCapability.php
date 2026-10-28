<?php

declare(strict_types=1);

namespace SimpleKuma\Notify;

/**
 * Whether this server can send mail, and whether an SMTP host accepts a login.
 */
final class MailCapability
{
    public static function phpMailAvailable(): bool
    {
        if (!function_exists('mail')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', strtolower((string) ini_get('disable_functions'))));

        return !in_array('mail', $disabled, true);
    }

    public static function statusLine(): string
    {
        if (self::phpMailAvailable()) {
            return 'PHP mail() is available. That means this server is allowed to send. It does not prove a message will arrive. Use Send a test after install.';
        }

        return 'PHP mail() is disabled on this server. Password reset by email needs SMTP, or a Discord, Slack, or ntfy channel, before this install can finish.';
    }

    /**
     * Installer gate. A working PHP mail() is enough to continue.
     * When mail() is off, SMTP must connect or a webhook test post must succeed.
     *
     * @param array<string, mixed> $post
     * @return array{error: ?string, config: ?array<string, mixed>}
     */
    public static function installGate(array $post): array
    {
        if (self::phpMailAvailable()) {
            return ['error' => null, 'config' => null];
        }

        $via = (string) ($post['install_reset_via'] ?? 'smtp');
        if ($via === 'smtp') {
            $probe = self::probeSmtp(
                trim((string) ($post['smtp_host'] ?? '')),
                (int) ($post['smtp_port'] ?? 587),
                trim((string) ($post['smtp_username'] ?? '')),
                (string) ($post['smtp_password'] ?? ''),
                (string) ($post['smtp_encryption'] ?? 'tls')
            );
            if ($probe !== null) {
                return ['error' => $probe, 'config' => null];
            }
            $from = trim((string) ($post['smtp_from'] ?? ''));
            if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
                return ['error' => 'Enter a From address on a domain your mail host will send for.', 'config' => null];
            }

            return ['error' => null, 'config' => [
                'reset_channel' => 'email',
                'smtp' => [
                    'host' => trim((string) ($post['smtp_host'] ?? '')),
                    'port' => (int) ($post['smtp_port'] ?? 587),
                    'username' => trim((string) ($post['smtp_username'] ?? '')),
                    'password' => (string) ($post['smtp_password'] ?? ''),
                    'encryption' => self::normalizeEncryption((string) ($post['smtp_encryption'] ?? 'tls')),
                    'from' => $from,
                ],
            ]];
        }

        $check = NotificationSettings::testInstallChannel($via, $post);
        if ($check['error'] !== null) {
            return ['error' => $check['error'], 'config' => null];
        }

        return ['error' => null, 'config' => $check['config']];
    }

    public static function normalizeEncryption(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === 'ssl' || $value === 'tls') {
            return $value;
        }

        return 'tls';
    }

    /**
     * Opens an SMTP connection and closes it. Returns an error string, or null when it worked.
     */
    public static function probeSmtp(string $host, int $port, string $username, string $password, string $encryption): ?string
    {
        if ($host === '' || !self::validHost($host)) {
            return 'Enter the SMTP host from your email provider.';
        }
        if ($port < 1 || $port > 65535) {
            return 'SMTP port must be between 1 and 65535.';
        }
        if ($username !== '' && $password === '') {
            return 'Enter the SMTP password.';
        }
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            return 'PHPMailer is not installed, so SMTP cannot be used on this server.';
        }

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = $port;
            $mail->Timeout = 8;
            $mail->SMTPAuth = $username !== '';
            $mail->Username = $username;
            $mail->Password = $password;
            $encryption = self::normalizeEncryption($encryption);
            $mail->SMTPSecure = $encryption === 'ssl'
                ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->smtpConnect();
            $mail->smtpClose();
        } catch (\Throwable $e) {
            return 'SMTP connection failed. Check the host, port, and password.';
        }

        return null;
    }

    public static function validHost(string $host): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9.-]{1,253}$/', $host) && !str_contains($host, '..');
    }
}
