<?php

declare(strict_types=1);

namespace SimpleKuma\Entity;

use mysqli;
use SimpleKuma\Utils\SecretEncryption;

/**
 * Built-in NewsBreak Access-Token integrations (cost sync).
 */
class NewsBreakIntegration
{
    public function __construct(private mysqli $db)
    {
    }

    public function tableExists(): bool
    {
        $r = $this->db->query("SHOW TABLES LIKE 'newsbreak_integrations'");
        return $r && $r->num_rows > 0;
    }

    /** @return list<array<string, mixed>> */
    public function getAllIncludingPaused(): array
    {
        if (!$this->tableExists()) {
            return [];
        }
        $result = $this->db->query(
            "SELECT id, name, timezone, ad_account_id, status, created_at, updated_at,
                    (access_token_encrypted IS NOT NULL AND access_token_encrypted != '') AS has_token
             FROM newsbreak_integrations
             ORDER BY name ASC"
        );
        if (!$result) {
            return [];
        }
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function getActive(): array
    {
        return array_values(array_filter(
            $this->getAllIncludingPaused(),
            static fn(array $r): bool => ($r['status'] ?? '') === 'active'
        ));
    }

    public function getById(int $id, bool $includeToken = false): ?array
    {
        if (!$this->tableExists() || $id < 1) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM newsbreak_integrations WHERE id = ?');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }
        if ($includeToken && !empty($row['access_token_encrypted'])) {
            try {
                $row['access_token'] = SecretEncryption::decrypt((string) $row['access_token_encrypted']);
            } catch (\Throwable $e) {
                error_log('NewsBreakIntegration decrypt failed: ' . $e->getMessage());
                $row['access_token'] = '';
            }
        }
        unset($row['access_token_encrypted']);
        return $row;
    }

    public function create(array $data): ?int
    {
        if (!$this->tableExists()) {
            return null;
        }
        $token = trim((string) ($data['access_token'] ?? ''));
        if ($token === '') {
            return null;
        }
        try {
            $enc = SecretEncryption::encrypt($token);
        } catch (\Throwable $e) {
            error_log('NewsBreakIntegration encrypt failed: ' . $e->getMessage());
            return null;
        }
        $name = trim((string) ($data['name'] ?? 'NewsBreak'));
        if ($name === '') {
            $name = 'NewsBreak';
        }
        $tz = trim((string) ($data['timezone'] ?? 'UTC'));
        if ($tz === '') {
            $tz = 'UTC';
        }
        $adAccount = trim((string) ($data['ad_account_id'] ?? ''));
        $adAccount = $adAccount !== '' ? $adAccount : null;
        $status = (($data['status'] ?? 'active') === 'paused') ? 'paused' : 'active';

        $stmt = $this->db->prepare(
            'INSERT INTO newsbreak_integrations
             (name, access_token_encrypted, timezone, ad_account_id, status, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('sssss', $name, $enc, $tz, $adAccount, $status);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $id = (int) $this->db->insert_id;
        $stmt->close();
        return $id > 0 ? $id : null;
    }

    public function update(int $id, array $data): bool
    {
        $existing = $this->getById($id, true);
        if ($existing === null) {
            return false;
        }
        $name = trim((string) ($data['name'] ?? $existing['name'] ?? 'NewsBreak'));
        $tz = trim((string) ($data['timezone'] ?? $existing['timezone'] ?? 'UTC'));
        if ($tz === '') {
            $tz = 'UTC';
        }
        $adAccount = trim((string) ($data['ad_account_id'] ?? $existing['ad_account_id'] ?? ''));
        $adAccount = $adAccount !== '' ? $adAccount : null;
        $status = (($data['status'] ?? $existing['status'] ?? 'active') === 'paused') ? 'paused' : 'active';

        $token = trim((string) ($data['access_token'] ?? ''));
        if ($token !== '') {
            try {
                $enc = SecretEncryption::encrypt($token);
            } catch (\Throwable $e) {
                error_log('NewsBreakIntegration encrypt failed: ' . $e->getMessage());
                return false;
            }
            $stmt = $this->db->prepare(
                'UPDATE newsbreak_integrations
                 SET name = ?, access_token_encrypted = ?, timezone = ?, ad_account_id = ?, status = ?
                 WHERE id = ?'
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('sssssi', $name, $enc, $tz, $adAccount, $status, $id);
        } else {
            $stmt = $this->db->prepare(
                'UPDATE newsbreak_integrations
                 SET name = ?, timezone = ?, ad_account_id = ?, status = ?
                 WHERE id = ?'
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('ssssi', $name, $tz, $adAccount, $status, $id);
        }
        $ok = $stmt->execute();
        $stmt->close();
        return (bool) $ok;
    }

    public function delete(int $id): bool
    {
        if (!$this->tableExists() || $id < 1) {
            return false;
        }
        $stmt = $this->db->prepare('DELETE FROM newsbreak_integrations WHERE id = ?');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool) $ok;
    }
}
