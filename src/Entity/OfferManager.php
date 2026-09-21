<?php

declare(strict_types=1);

namespace SimpleKuma\Entity;

use mysqli;

/**
 * Offer Manager
 * Handles CRUD operations for offers
 */
class OfferManager
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function getAll(): array
    {
        $result = $this->db->query(
            "SELECT o.*, n.name as network_name 
             FROM offers o 
             LEFT JOIN networks n ON o.network_id = n.id 
             ORDER BY o.name ASC"
        );

        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT o.*, n.name as network_name 
             FROM offers o 
             LEFT JOIN networks n ON o.network_id = n.id 
             WHERE o.id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc() ?: null;
    }

    public function create(array $data): int|false
    {
        $stmt = $this->db->prepare(
            "INSERT INTO offers (name, url, payout_type, payout_value, network_id, notes, created_at) 
             VALUES (?, ?, ?, ?, ?, ?, NOW())"
        );
        
        $networkId = !empty($data['network_id']) ? (int)$data['network_id'] : null;
        
        $stmt->bind_param(
            'sssdis',
            $data['name'],
            $data['url'],
            $data['payout_type'],
            $data['payout_value'],
            $networkId,
            $data['notes']
        );

        return $stmt->execute() ? $this->db->insert_id : false;
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE offers 
             SET name = ?, url = ?, payout_type = ?, payout_value = ?, network_id = ?, notes = ?, updated_at = NOW() 
             WHERE id = ?"
        );
        
        $networkId = !empty($data['network_id']) ? (int)$data['network_id'] : null;
        
        $stmt->bind_param(
            'sssdisi',
            $data['name'],
            $data['url'],
            $data['payout_type'],
            $data['payout_value'],
            $networkId,
            $data['notes'],
            $id
        );

        return $stmt->execute();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM offers WHERE id = ?");
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }

    public function validate(array $data): array
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors['name'] = 'Offer name is required';
        }

        if (empty($data['url'])) {
            $errors['url'] = 'Offer URL is required';
        } elseif (!filter_var($data['url'], FILTER_VALIDATE_URL)) {
            $errors['url'] = 'Invalid URL format';
        }

        if (empty($data['payout_type'])) {
            $errors['payout_type'] = 'Payout type is required';
        } elseif (!in_array($data['payout_type'], ['CPL', 'CPS', 'CPA'])) {
            $errors['payout_type'] = 'Invalid payout type';
        }

        if (!isset($data['payout_value']) || $data['payout_value'] < 0) {
            $errors['payout_value'] = 'Payout value must be 0 or greater';
        }

        return $errors;
    }
}

