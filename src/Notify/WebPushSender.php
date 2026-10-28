<?php

declare(strict_types=1);

namespace SimpleKuma\Notify;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use mysqli;
use SimpleKuma\Settings\SettingsManager;
use SimpleKuma\Utils\SecretEncryption;

/**
 * Sends Grizzly Guard alerts to subscribed admin browsers.
 */
final class WebPushSender
{
    private const VAPID_KEY = 'web_push_vapid';

    public static function libraryReady(): bool
    {
        return class_exists(WebPush::class) && class_exists(VAPID::class);
    }

    /**
     * @return array{publicKey:string,privateKey:string,subject:string}|null
     */
    public static function ensureVapid(SettingsManager $settings, string $subjectHint = ''): ?array
    {
        if (!self::libraryReady()) {
            return null;
        }
        $decoded = json_decode((string) $settings->get(self::VAPID_KEY, ''), true);
        if (!is_array($decoded)) {
            $decoded = [];
        }
        $public = trim((string) ($decoded['public_key'] ?? ''));
        $private = self::decrypt((string) ($decoded['private_key_enc'] ?? ''));
        $subject = trim((string) ($decoded['subject'] ?? ''));
        if ($public !== '' && $private !== '') {
            if ($subject === '') {
                $subject = self::defaultSubject($subjectHint);
            }

            return [
                'publicKey' => $public,
                'privateKey' => $private,
                'subject' => $subject,
            ];
        }

        self::ensureOpenSslConf();
        $keys = null;
        try {
            $keys = VAPID::createVapidKeys();
        } catch (\Throwable $e) {
            $keys = self::createVapidKeysViaOpenSslCli();
            if ($keys === null) {
                error_log('Simple KUMA could not create VAPID keys: ' . $e->getMessage());

                return null;
            }
        }
        $subject = self::defaultSubject($subjectHint);
        $stored = [
            'public_key' => (string) $keys['publicKey'],
            'private_key_enc' => self::encrypt((string) $keys['privateKey']),
            'subject' => $subject,
        ];
        $json = json_encode($stored, JSON_UNESCAPED_SLASHES);
        $settings->set(self::VAPID_KEY, $json === false ? '{}' : $json);

        return [
            'publicKey' => (string) $keys['publicKey'],
            'privateKey' => (string) $keys['privateKey'],
            'subject' => $subject,
        ];
    }

    public static function publicKey(SettingsManager $settings, string $subjectHint = ''): string
    {
        $keys = self::ensureVapid($settings, $subjectHint);

        return $keys['publicKey'] ?? '';
    }

    /**
     * @return ?string Null on success (at least one device accepted), or an error string.
     */
    public static function sendAlert(mysqli $db, string $title, string $body, string $url = ''): ?string
    {
        if (!self::libraryReady()) {
            return 'Browser push is not installed on this server.';
        }
        $rows = PushSubscriptionStore::all($db);
        if ($rows === []) {
            return 'No browser is subscribed yet. Open Account → Notifications → Browser and enable this device.';
        }
        $settings = new SettingsManager($db);
        $keys = self::ensureVapid($settings);
        if ($keys === null) {
            return 'Browser push keys could not be created on this server.';
        }
        if ($url === '') {
            $url = rtrim((string) (defined('ASSETS_BASE_URL') ? ASSETS_BASE_URL : ''), '/') . '/index.php?page=dashboard';
        }
        $payload = json_encode([
            'title' => self::clip($title, 80),
            'body' => self::clip($body, 180),
            'url' => $url,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return 'The browser alert could not be encoded.';
        }

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => $keys['subject'],
                    'publicKey' => $keys['publicKey'],
                    'privateKey' => $keys['privateKey'],
                ],
            ], [], 8);
        } catch (\Throwable $e) {
            error_log('Simple KUMA WebPush init failed: ' . $e->getMessage());

            return 'Browser push could not start on this server.';
        }

        $accepted = 0;
        $lastError = '';
        foreach ($rows as $row) {
            try {
                $subscription = Subscription::create([
                    'endpoint' => $row['endpoint'],
                    'publicKey' => $row['p256dh'],
                    'authToken' => $row['auth_key'],
                ]);
                $report = $webPush->sendOneNotification($subscription, $payload);
            } catch (\Throwable $e) {
                $lastError = 'A browser subscription failed.';
                error_log('Simple KUMA browser push send failed: ' . $e->getMessage());
                continue;
            }
            if ($report->isSubscriptionExpired()) {
                PushSubscriptionStore::deleteById($db, $row['id']);
                continue;
            }
            if ($report->isSuccess()) {
                $accepted++;
            } else {
                $lastError = self::clip($report->getReason(), 160);
            }
        }

        if ($accepted > 0) {
            return null;
        }

        return $lastError !== '' ? $lastError : 'No browser accepted the alert.';
    }

    private static function defaultSubject(string $hint): string
    {
        $hint = trim($hint);
        if ($hint !== '' && filter_var($hint, FILTER_VALIDATE_EMAIL)) {
            return 'mailto:' . $hint;
        }
        if (defined('APP_BASE_URL') && is_string(APP_BASE_URL) && preg_match('#^https?://#i', APP_BASE_URL)) {
            return rtrim(APP_BASE_URL, '/');
        }
        if (defined('ASSETS_BASE_URL') && is_string(ASSETS_BASE_URL) && preg_match('#^https?://#i', ASSETS_BASE_URL)) {
            return rtrim(ASSETS_BASE_URL, '/');
        }

        return 'mailto:admin@localhost';
    }

    /**
     * XAMPP CLI often lacks OPENSSL_CONF; Apache already sets it for web requests.
     */
    private static function ensureOpenSslConf(): void
    {
        $current = getenv('OPENSSL_CONF');
        if (is_string($current) && $current !== '' && is_file($current)) {
            return;
        }
        $path = self::findOpenSslConf();
        if ($path === null) {
            return;
        }
        putenv('OPENSSL_CONF=' . $path);
        $_ENV['OPENSSL_CONF'] = $path;
    }

    private static function findOpenSslConf(): ?string
    {
        $candidates = [
            'C:\\xampp\\php\\extras\\ssl\\openssl.cnf',
            'C:\\xampp\\apache\\conf\\openssl.cnf',
            'C:\\xampp\\apache\\bin\\openssl.cnf',
            (defined('PHP_BINDIR') ? PHP_BINDIR : '') . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
            (defined('PHP_BINDIR') ? PHP_BINDIR : '') . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'openssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
            '/etc/ssl/openssl.cnf',
            '/etc/pki/tls/openssl.cnf',
        ];
        foreach ($candidates as $path) {
            if ($path !== '' && is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return array{publicKey:string,privateKey:string}|null
     */
    private static function createVapidKeysViaOpenSslCli(): ?array
    {
        $conf = self::findOpenSslConf();
        $openssl = self::findOpenSslBinary();
        if ($openssl === null) {
            return null;
        }
        $command = escapeshellarg($openssl) . ' ecparam -name prime256v1 -genkey -noout';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = $_ENV;
        foreach (['PATH', 'SystemRoot', 'TEMP', 'TMP', 'HOME'] as $key) {
            $value = getenv($key);
            if (is_string($value) && $value !== '') {
                $env[$key] = $value;
            }
        }
        if ($conf !== null) {
            $env['OPENSSL_CONF'] = $conf;
        }
        $process = proc_open($command, $descriptors, $pipes, null, $env);
        if (!is_resource($process)) {
            return null;
        }
        fclose($pipes[0]);
        $pem = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0 || !is_string($pem) || $pem === '' || !str_contains($pem, 'BEGIN')) {
            if (is_string($err) && $err !== '') {
                error_log('Simple KUMA openssl VAPID fallback failed: ' . trim($err));
            }

            return null;
        }
        try {
            $validated = VAPID::validate([
                'subject' => 'mailto:admin@localhost',
                'pem' => $pem,
            ]);
        } catch (\Throwable $e) {
            error_log('Simple KUMA could not parse openssl VAPID PEM: ' . $e->getMessage());

            return null;
        }

        return [
            'publicKey' => \Base64Url\Base64Url::encode($validated['publicKey']),
            'privateKey' => \Base64Url\Base64Url::encode($validated['privateKey']),
        ];
    }

    private static function findOpenSslBinary(): ?string
    {
        $candidates = [
            'C:\\xampp\\apache\\bin\\openssl.exe',
            'C:\\xampp\\php\\extras\\openssl\\openssl.exe',
            '/usr/bin/openssl',
            '/usr/local/bin/openssl',
            'openssl',
        ];
        foreach ($candidates as $path) {
            if ($path === 'openssl') {
                return $path;
            }
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
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

    private static function clip(string $value, int $max): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }

        return substr($value, 0, $max);
    }
}
