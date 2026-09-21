<?php

declare(strict_types=1);

namespace SimpleKuma\Honeycomb;

use mysqli;
use RuntimeException;
use SimpleKuma\Utils\SecretEncryption;

/**
 * Encrypted credentials for Honeycomb addons (honeycomb_credentials).
 */
final class CredentialStore
{
    public function __construct(private mysqli $db)
    {
    }

    public function tableExists(): bool
    {
        $result = $this->db->query("SHOW TABLES LIKE 'honeycomb_credentials'");
        return $result !== false && $result->num_rows > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByAddon(string $addonSlug): array
    {
        if (!$this->tableExists()) {
            return [];
        }
        $stmt = $this->db->prepare(
            'SELECT id, addon_slug, label, status, created_at, updated_at
             FROM honeycomb_credentials
             WHERE addon_slug = ?
             ORDER BY id ASC'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('s', $addonSlug);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getById(int $id, bool $withSecrets = false): ?array
    {
        if (!$this->tableExists() || $id < 1) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT id, addon_slug, label, payload_encrypted, status, created_at, updated_at
             FROM honeycomb_credentials WHERE id = ? LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($row === null) {
            return null;
        }
        if ($withSecrets) {
            $row['payload'] = $this->decryptPayload((string) ($row['payload_encrypted'] ?? ''));
        }
        unset($row['payload_encrypted']);
        return $row;
    }

    /**
     * @param array<string, mixed> $payload Plain JSON-serializable secrets/config
     */
    public function create(string $addonSlug, string $label, array $payload, string $status = 'active'): int
    {
        $this->assertTable();
        $encrypted = SecretEncryption::encrypt(json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}');
        $stmt = $this->db->prepare(
            'INSERT INTO honeycomb_credentials (addon_slug, label, payload_encrypted, status)
             VALUES (?, ?, ?, ?)'
        );
        if ($stmt === false) {
            throw new RuntimeException('Could not prepare credential insert.');
        }
        $stmt->bind_param('ssss', $addonSlug, $label, $encrypted, $status);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Could not save credential.');
        }
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    /**
     * @param array<string, mixed>|null $payload Null = keep existing secrets; merge keys that are non-empty strings
     */
    public function update(int $id, string $label, ?array $payload = null, ?string $status = null): bool
    {
        $this->assertTable();
        $existing = $this->getById($id, true);
        if ($existing === null) {
            return false;
        }

        $nextPayload = is_array($existing['payload'] ?? null) ? $existing['payload'] : [];
        if ($payload !== null) {
            foreach ($payload as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }
                if (is_string($value) && $value === '') {
                    continue; // leave blank = keep existing secret
                }
                $nextPayload[$key] = $value;
            }
        }

        $encrypted = SecretEncryption::encrypt(json_encode($nextPayload, JSON_UNESCAPED_SLASHES) ?: '{}');
        $statusVal = $status ?? (string) ($existing['status'] ?? 'active');
        $stmt = $this->db->prepare(
            'UPDATE honeycomb_credentials
             SET label = ?, payload_encrypted = ?, status = ?
             WHERE id = ?'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('sssi', $label, $encrypted, $statusVal, $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function delete(int $id): bool
    {
        if (!$this->tableExists() || $id < 1) {
            return false;
        }
        $stmt = $this->db->prepare('DELETE FROM honeycomb_credentials WHERE id = ?');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function deleteByAddon(string $addonSlug): int
    {
        if (!$this->tableExists()) {
            return 0;
        }
        $stmt = $this->db->prepare('DELETE FROM honeycomb_credentials WHERE addon_slug = ?');
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param('s', $addonSlug);
        $stmt->execute();
        $n = $stmt->affected_rows;
        $stmt->close();
        return max(0, $n);
    }

    /**
     * @return array<string, mixed>
     */
    private function decryptPayload(string $encrypted): array
    {
        if ($encrypted === '') {
            return [];
        }
        $json = SecretEncryption::decrypt($encrypted);
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function assertTable(): void
    {
        if (!$this->tableExists()) {
            throw new RuntimeException('honeycomb_credentials table is missing. Run database migrations.');
        }
    }
}
