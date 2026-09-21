<?php

declare(strict_types=1);

namespace SimpleKuma\Entity;

use mysqli;

/**
 * API key management for REST API v1.
 */
class ApiKey
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function tableExists(): bool
    {
        $result = $this->db->query("SHOW TABLES LIKE 'api_keys'");
        return $result !== false && $result->num_rows > 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAllForUser(int $userId): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $stmt = $this->db->prepare(
            "SELECT id, user_id, name, key_prefix, last_used_at, created_at, revoked_at
             FROM api_keys
             WHERE user_id = ? AND revoked_at IS NULL
             ORDER BY created_at DESC"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        $keys = [];
        while ($row = $result->fetch_assoc()) {
            $keys[] = $row;
        }

        return $keys;
    }

    /**
     * @return array{success: bool, plain_key?: string, id?: int, prefix?: string, error?: string}
     */
    public function create(int $userId, string $name): array
    {
        if (!$this->tableExists()) {
            return ['success' => false, 'error' => 'API keys table not found. Run database migrations.'];
        }

        $name = trim($name);
        if ($name === '') {
            return ['success' => false, 'error' => 'Key name is required'];
        }
        if (strlen($name) > 100) {
            return ['success' => false, 'error' => 'Key name must be 100 characters or less'];
        }

        $plainKey = 'kuma_' . bin2hex(random_bytes(32));
        $prefix = substr($plainKey, 0, 12);
        $hash = hash('sha256', $plainKey);

        $stmt = $this->db->prepare(
            "INSERT INTO api_keys (user_id, name, key_prefix, key_hash, created_at)
             VALUES (?, ?, ?, ?, NOW())"
        );
        $stmt->bind_param('isss', $userId, $name, $prefix, $hash);
        $stmt->execute();

        return [
            'success' => true,
            'plain_key' => $plainKey,
            'id' => $stmt->insert_id,
            'prefix' => $prefix,
        ];
    }

    public function revoke(int $id, int $userId): bool
    {
        if (!$this->tableExists()) {
            return false;
        }

        $stmt = $this->db->prepare(
            "UPDATE api_keys SET revoked_at = NOW()
             WHERE id = ? AND user_id = ? AND revoked_at IS NULL"
        );
        $stmt->bind_param('ii', $id, $userId);
        $stmt->execute();

        return $stmt->affected_rows > 0;
    }

    /**
     * @return array{user_id: int, api_key_id: int}|null
     */
    public function authenticate(string $plainKey): ?array
    {
        if (!$this->tableExists() || !str_starts_with($plainKey, 'kuma_')) {
            return null;
        }

        $hash = hash('sha256', $plainKey);
        $stmt = $this->db->prepare(
            "SELECT id, user_id FROM api_keys
             WHERE key_hash = ? AND revoked_at IS NULL"
        );
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row) {
            return null;
        }

        $update = $this->db->prepare(
            "UPDATE api_keys SET last_used_at = NOW() WHERE id = ?"
        );
        $keyId = (int)$row['id'];
        $update->bind_param('i', $keyId);
        $update->execute();

        return [
            'user_id' => (int)$row['user_id'],
            'api_key_id' => $keyId,
        ];
    }
}
