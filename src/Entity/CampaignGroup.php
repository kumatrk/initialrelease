<?php

declare(strict_types=1);

namespace SimpleKuma\Entity;

use mysqli;

/**
 * Campaign Group Entity
 * Handles CRUD operations for campaign groups
 */
class CampaignGroup
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function getAll(): array
    {
        $result = $this->db->query(
            "SELECT cg.*, COUNT(c.id) as campaign_count
             FROM campaign_groups cg
             LEFT JOIN campaigns c ON cg.id = c.campaign_group_id
             GROUP BY cg.id
             ORDER BY cg.name ASC"
        );

        $groups = [];
        while ($row = $result->fetch_assoc()) {
            $groups[] = $row;
        }

        return $groups;
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM campaign_groups WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc() ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO campaign_groups (name, description, created_at) 
             VALUES (?, ?, NOW())"
        );

        $description = $data['description'] ?? null;
        $stmt->bind_param('ss', $data['name'], $description);
        $stmt->execute();
        return $stmt->insert_id;
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE campaign_groups 
             SET name = ?, description = ?, updated_at = NOW()
             WHERE id = ?"
        );

        $description = $data['description'] ?? null;
        $stmt->bind_param('ssi', $data['name'], $description, $id);
        return $stmt->execute();
    }

    public function delete(int $id): bool
    {
        // Check if any campaigns use this group
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM campaigns WHERE campaign_group_id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if ($result['count'] > 0) {
            return false; // Cannot delete if campaigns are using it
        }

        $stmt = $this->db->prepare("DELETE FROM campaign_groups WHERE id = ?");
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }

    public function validate(array $data): array
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors['name'] = 'Group name is required';
        } elseif (strlen($data['name']) > 100) {
            $errors['name'] = 'Group name must be 100 characters or less';
        }

        // Check for duplicate names
        $stmt = $this->db->prepare("SELECT id FROM campaign_groups WHERE name = ? AND id != ?");
        $groupId = $data['id'] ?? 0;
        $stmt->bind_param('si', $data['name'], $groupId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $errors['name'] = 'A group with this name already exists';
        }

        return $errors;
    }
}

