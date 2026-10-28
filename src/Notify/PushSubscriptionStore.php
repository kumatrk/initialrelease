<?php

declare(strict_types=1);

namespace SimpleKuma\Notify;

use mysqli;

/**
 * Persists Web Push subscriptions for admin browsers.
 */
final class PushSubscriptionStore
{
    public static function tableExists(mysqli $db): bool
    {
        $result = $db->query("SHOW TABLES LIKE 'push_subscriptions'");

        return $result !== false && $result->num_rows > 0;
    }

    /**
     * @return list<array{id:int,endpoint:string,p256dh:string,auth_key:string}>
     */
    public static function all(mysqli $db): array
    {
        if (!self::tableExists($db)) {
            return [];
        }
        $result = $db->query('SELECT id, endpoint, p256dh, auth_key FROM push_subscriptions ORDER BY id ASC');
        if (!$result) {
            return [];
        }
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'id' => (int) ($row['id'] ?? 0),
                'endpoint' => (string) ($row['endpoint'] ?? ''),
                'p256dh' => (string) ($row['p256dh'] ?? ''),
                'auth_key' => (string) ($row['auth_key'] ?? ''),
            ];
        }

        return $rows;
    }

    public static function count(mysqli $db): int
    {
        if (!self::tableExists($db)) {
            return 0;
        }
        $result = $db->query('SELECT COUNT(*) AS c FROM push_subscriptions');
        if (!$result) {
            return 0;
        }
        $row = $result->fetch_assoc();

        return (int) ($row['c'] ?? 0);
    }

    public static function countForUser(mysqli $db, int $userId): int
    {
        if (!self::tableExists($db) || $userId <= 0) {
            return 0;
        }
        $stmt = $db->prepare('SELECT COUNT(*) AS c FROM push_subscriptions WHERE user_id = ?');
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['c'] ?? 0);
    }

    public static function hasEndpoint(mysqli $db, string $endpoint): bool
    {
        if (!self::tableExists($db) || $endpoint === '') {
            return false;
        }
        $stmt = $db->prepare('SELECT id FROM push_subscriptions WHERE endpoint = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $endpoint);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_assoc() !== null;
        $stmt->close();

        return $found;
    }

    public static function upsert(
        mysqli $db,
        int $userId,
        string $endpoint,
        string $p256dh,
        string $authKey,
        string $userAgent = ''
    ): ?string {
        if (!self::tableExists($db)) {
            return 'Browser push is not available until database migration 107 is applied.';
        }
        if ($userId <= 0) {
            return 'You must be signed in to enable browser alerts.';
        }
        $endpoint = trim($endpoint);
        $p256dh = trim($p256dh);
        $authKey = trim($authKey);
        if ($endpoint === '' || !preg_match('#^https://#i', $endpoint) || strlen($endpoint) > 500) {
            return 'That push subscription endpoint is not valid.';
        }
        if ($p256dh === '' || $authKey === '' || strlen($p256dh) > 255 || strlen($authKey) > 255) {
            return 'That push subscription is missing encryption keys.';
        }
        $userAgent = mb_substr(trim($userAgent), 0, 255);
        $now = gmdate('Y-m-d H:i:s');
        $existing = $db->prepare('SELECT user_id FROM push_subscriptions WHERE endpoint = ? LIMIT 1');
        if ($existing) {
            $existing->bind_param('s', $endpoint);
            $existing->execute();
            $row = $existing->get_result()->fetch_assoc();
            $existing->close();
            if (is_array($row) && (int) ($row['user_id'] ?? 0) !== $userId) {
                return 'That browser subscription belongs to another account.';
            }
        }

        $stmt = $db->prepare(
            'INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth_key, user_agent, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE p256dh = VALUES(p256dh),
               auth_key = VALUES(auth_key), user_agent = VALUES(user_agent), updated_at = VALUES(updated_at)'
        );
        if (!$stmt) {
            return 'The push subscription could not be saved.';
        }
        $stmt->bind_param('issssss', $userId, $endpoint, $p256dh, $authKey, $userAgent, $now, $now);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok ? null : 'The push subscription could not be saved.';
    }

    public static function deleteByEndpoint(mysqli $db, string $endpoint, ?int $userId = null): void
    {
        if (!self::tableExists($db) || $endpoint === '') {
            return;
        }
        if ($userId !== null && $userId > 0) {
            $stmt = $db->prepare('DELETE FROM push_subscriptions WHERE endpoint = ? AND user_id = ?');
            if (!$stmt) {
                return;
            }
            $stmt->bind_param('si', $endpoint, $userId);
            $stmt->execute();
            $stmt->close();

            return;
        }
        $stmt = $db->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?');
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('s', $endpoint);
        $stmt->execute();
        $stmt->close();
    }

    public static function deleteById(mysqli $db, int $id): void
    {
        if (!self::tableExists($db) || $id <= 0) {
            return;
        }
        $stmt = $db->prepare('DELETE FROM push_subscriptions WHERE id = ?');
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }
}
