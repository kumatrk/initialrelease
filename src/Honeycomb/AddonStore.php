<?php

declare(strict_types=1);

namespace SimpleKuma\Honeycomb;

use mysqli;

/**
 * Persistence for installed Honeycomb addons.
 */
final class AddonStore
{
    public function __construct(private mysqli $db)
    {
    }

    public function tableExists(): bool
    {
        $result = $this->db->query("SHOW TABLES LIKE 'honeycomb_addons'");
        return $result !== false && $result->num_rows > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        if (!$this->tableExists()) {
            return [];
        }
        $result = $this->db->query('SELECT * FROM honeycomb_addons ORDER BY name ASC');
        if ($result === false) {
            return [];
        }
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getBySlug(string $slug): ?array
    {
        if (!$this->tableExists()) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM honeycomb_addons WHERE slug = ?');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('s', $slug);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ?: null;
    }

    public function upsert(Manifest $manifest, string $status = 'enabled'): bool
    {
        if (!$this->tableExists()) {
            return false;
        }
        $slug = $manifest->slug();
        $name = $manifest->name();
        $version = $manifest->version();
        $type = $manifest->type();
        $providerKey = $manifest->providerKey();
        $json = json_encode($manifest->toArray(), JSON_UNESCAPED_SLASHES);
        $stmt = $this->db->prepare(
            'INSERT INTO honeycomb_addons (slug, name, version, type, provider_key, status, manifest_json, installed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                version = VALUES(version),
                type = VALUES(type),
                provider_key = VALUES(provider_key),
                status = VALUES(status),
                manifest_json = VALUES(manifest_json),
                updated_at = NOW()'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('sssssss', $slug, $name, $version, $type, $providerKey, $status, $json);
        return $stmt->execute();
    }

    public function setStatus(string $slug, string $status): bool
    {
        if (!in_array($status, ['enabled', 'disabled'], true) || !$this->tableExists()) {
            return false;
        }
        $stmt = $this->db->prepare('UPDATE honeycomb_addons SET status = ?, updated_at = NOW() WHERE slug = ?');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ss', $status, $slug);
        return $stmt->execute();
    }

    public function delete(string $slug): bool
    {
        if (!$this->tableExists()) {
            return false;
        }
        $stmt = $this->db->prepare('DELETE FROM honeycomb_addons WHERE slug = ?');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('s', $slug);
        return $stmt->execute();
    }
}
