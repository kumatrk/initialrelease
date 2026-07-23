<?php

declare(strict_types=1);

namespace SimpleKuma\Entity;

use mysqli;

/**
 * Network Manager
 * Handles CRUD operations for affiliate networks
 */
class NetworkManager
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function getAll(): array
    {
        $result = $this->db->query("SELECT * FROM networks ORDER BY name ASC");
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM networks WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc() ?: null;
    }

    public function create(array $data): int|false
    {
        $stmt = $this->db->prepare(
            "INSERT INTO networks (name, postback_template, notes, created_at) VALUES (?, ?, ?, NOW())"
        );
        
        $stmt->bind_param('sss', $data['name'], $data['postback_template'], $data['notes']);
        return $stmt->execute() ? $this->db->insert_id : false;
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE networks SET name = ?, postback_template = ?, notes = ?, updated_at = NOW() WHERE id = ?"
        );
        
        $stmt->bind_param('sssi', $data['name'], $data['postback_template'], $data['notes'], $id);
        return $stmt->execute();
    }

    public function delete(int $id): bool
    {
        // Check if any offers are using this network
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM offers WHERE network_id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if ($row['count'] > 0) {
            return false; // Cannot delete if in use
        }

        $stmt = $this->db->prepare("DELETE FROM networks WHERE id = ?");
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }

    public function validate(array $data): array
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors['name'] = 'Network name is required';
        }

        return $errors;
    }
}

