<?php

declare(strict_types=1);

namespace SimpleKuma\Support;

use mysqli;
use Throwable;

/**
 * One recent-failures list for vendor API calls.
 * Tokens and account ids are stripped before insert. A missing table is a no-op.
 */
final class ApiFailureLog
{
    private static bool $ready = false;

    public static function record(
        mysqli $db,
        string $provider,
        string $action,
        ?int $httpCode,
        string $message,
        string $source = ''
    ): void {
        try {
            if (!self::tableReady($db)) {
                return;
            }

            $provider = self::provider($provider);
            $action = self::clip($action, 160);
            $source = self::clip($source, 80);
            $message = self::redact($message);
            if ($action === '' || $message === '') {
                return;
            }

            $code = null;
            if ($httpCode !== null && $httpCode > 0 && $httpCode < 1000) {
                $code = $httpCode;
            }

            $existing = $db->prepare(
                'SELECT id FROM api_failure_log
                 WHERE provider = ? AND action = ? AND message = ?
                   AND called_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                 LIMIT 1'
            );
            if (!$existing) {
                return;
            }
            $existing->bind_param('sss', $provider, $action, $message);
            $existing->execute();
            $dup = $existing->get_result()->fetch_assoc();
            $existing->close();
            if ($dup) {
                return;
            }

            if ($code === null) {
                $insert = $db->prepare(
                    'INSERT INTO api_failure_log (called_at, provider, action, message, source)
                     VALUES (NOW(), ?, ?, ?, ?)'
                );
                if (!$insert) {
                    return;
                }
                $insert->bind_param('ssss', $provider, $action, $message, $source);
            } else {
                $insert = $db->prepare(
                    'INSERT INTO api_failure_log (called_at, provider, action, http_code, message, source)
                     VALUES (NOW(), ?, ?, ?, ?, ?)'
                );
                if (!$insert) {
                    return;
                }
                $insert->bind_param('ssiss', $provider, $action, $code, $message, $source);
            }
            $insert->execute();
            $insert->close();

            $db->query('DELETE FROM api_failure_log WHERE called_at < DATE_SUB(NOW(), INTERVAL 14 DAY)');
        } catch (Throwable $e) {
            error_log('ApiFailureLog: ' . $e->getMessage());
        }
    }

    /**
     * @return list<array{id: int, called_at: string, provider: string, label: string, action: string, http_code: int|null, message: string, source: string}>|null
     */
    public static function recent(mysqli $db, int $limit = 40): ?array
    {
        if (!self::tableReady($db)) {
            return null;
        }
        $limit = max(1, min(100, $limit));
        $result = $db->query(
            'SELECT id, called_at, provider, action, http_code, message, source
             FROM api_failure_log
             ORDER BY id DESC
             LIMIT ' . $limit
        );
        if (!$result) {
            return [];
        }
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $provider = (string) ($row['provider'] ?? '');
            $rows[] = [
                'id' => (int) ($row['id'] ?? 0),
                'called_at' => (string) ($row['called_at'] ?? ''),
                'provider' => $provider,
                'label' => self::label($provider),
                'action' => (string) ($row['action'] ?? ''),
                'http_code' => isset($row['http_code']) && $row['http_code'] !== null ? (int) $row['http_code'] : null,
                'message' => (string) ($row['message'] ?? ''),
                'source' => (string) ($row['source'] ?? ''),
            ];
        }
        $result->free();

        return $rows;
    }

    public static function label(string $provider): string
    {
        return match ($provider) {
            'meta' => 'Meta',
            'google_ads' => 'Google Ads',
            'newsbreak' => 'NewsBreak',
            'honeycomb' => 'Honeycomb',
            'postback' => 'Postback',
            'safe_browsing' => 'Safe Browsing',
            'virustotal' => 'VirusTotal',
            default => 'API',
        };
    }

    public static function redact(string $message): string
    {
        $message = preg_replace('/access_token=[^&\s]+/i', 'access_token=redacted', $message) ?? $message;
        $message = preg_replace('/([?&](?:key|token|secret|password|api_key|apikey)=)[^&\s]+/i', '$1redacted', $message) ?? $message;
        $message = preg_replace('/\bEA[A-Za-z0-9]{20,}\b/', '[redacted]', $message) ?? $message;
        $message = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $message) ?? $message;
        $message = preg_replace('/\bact_\d{6,}\b/', 'act_[id]', $message) ?? $message;
        $message = preg_replace('#customers/\d+#', 'customers/[id]', $message) ?? $message;
        $message = preg_replace('/("(?:access_token|password|api_token|client_secret|refresh_token|proxy_pass|x-apikey)"\s*:\s*")[^"]*(")/i', '$1[redacted]$2', $message) ?? $message;
        $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', ' ', $message) ?? $message;
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        return self::clip($message, 500);
    }

    private static function provider(string $provider): string
    {
        $provider = strtolower(preg_replace('/[^a-z0-9_]/', '', $provider) ?? '');
        $allowed = ['meta', 'google_ads', 'newsbreak', 'honeycomb', 'postback', 'safe_browsing', 'virustotal'];

        return in_array($provider, $allowed, true) ? $provider : 'other';
    }

    private static function clip(string $value, int $max): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }

        return substr($value, 0, $max);
    }

    private static function tableReady(mysqli $db): bool
    {
        if (self::$ready) {
            return true;
        }
        $result = $db->query("SHOW TABLES LIKE 'api_failure_log'");
        self::$ready = $result instanceof \mysqli_result && $result->num_rows > 0;
        if ($result instanceof \mysqli_result) {
            $result->free();
        }

        return self::$ready;
    }
}
