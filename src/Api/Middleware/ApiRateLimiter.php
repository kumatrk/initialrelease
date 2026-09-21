<?php

declare(strict_types=1);

namespace SimpleKuma\Api\Middleware;

use mysqli;
use SimpleKuma\Api\Response;

/**
 * Simple per-key rate limiting (120 req/min).
 */
class ApiRateLimiter
{
    private const LIMIT = 120;
    private const WINDOW_SECONDS = 60;

    public static function check(mysqli $db, int $apiKeyId): void
    {
        if (!$db->query("SHOW TABLES LIKE 'api_rate_limit_buckets'")->num_rows) {
            self::ensureTable($db);
        }

        $windowStart = (int)(floor(time() / self::WINDOW_SECONDS) * self::WINDOW_SECONDS);

        $stmt = $db->prepare(
            "INSERT INTO api_rate_limit_buckets (api_key_id, window_start, request_count)
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE request_count = request_count + 1"
        );
        $stmt->bind_param('ii', $apiKeyId, $windowStart);
        $stmt->execute();

        $check = $db->prepare(
            "SELECT request_count FROM api_rate_limit_buckets
             WHERE api_key_id = ? AND window_start = ?"
        );
        $check->bind_param('ii', $apiKeyId, $windowStart);
        $check->execute();
        $count = (int)($check->get_result()->fetch_assoc()['request_count'] ?? 0);

        if ($count > self::LIMIT) {
            Response::error('Rate limit exceeded. Max ' . self::LIMIT . ' requests per minute.', 429, 'rate_limit');
        }
    }

    private static function ensureTable(mysqli $db): void
    {
        $db->query(
            "CREATE TABLE IF NOT EXISTS api_rate_limit_buckets (
                api_key_id INT UNSIGNED NOT NULL,
                window_start INT UNSIGNED NOT NULL,
                request_count INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (api_key_id, window_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}
