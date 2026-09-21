<?php

declare(strict_types=1);

namespace SimpleKuma\Entity;

use mysqli;

/**
 * Traffic Source Manager
 * Handles CRUD operations for traffic sources
 */
class TrafficSourceManager
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Get all traffic sources
     */
    public function getAll(): array
    {
        $result = $this->db->query(
            "SELECT id, name, tokens_json, postback_template, cost_param_key, cost_currency, created_at 
             FROM traffic_sources 
             ORDER BY name ASC"
        );

        if (!$result) {
            return [];
        }

        $sources = [];
        while ($row = $result->fetch_assoc()) {
            $row['tokens_json'] = json_decode($row['tokens_json'] ?? '[]', true);
            $sources[] = $row;
        }

        return $sources;
    }

    /**
     * Get traffic source by ID
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, name, tokens_json, postback_template, cost_param_key, cost_currency, created_at 
             FROM traffic_sources 
             WHERE id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $source = $result->fetch_assoc();

        if ($source) {
            $source['tokens_json'] = json_decode($source['tokens_json'] ?? '[]', true);
        }

        return $source ?: null;
    }

    /**
     * Create new traffic source
     */
    public function create(array $data): int|false
    {
        $tokensJson = json_encode($data['tokens'] ?? []);
        
        $stmt = $this->db->prepare(
            "INSERT INTO traffic_sources 
             (name, tokens_json, postback_template, cost_param_key, cost_currency, created_at) 
             VALUES (?, ?, ?, ?, ?, NOW())"
        );
        
        $stmt->bind_param(
            'sssss',
            $data['name'],
            $tokensJson,
            $data['postback_template'],
            $data['cost_param_key'],
            $data['cost_currency']
        );

        if ($stmt->execute()) {
            return $this->db->insert_id;
        }

        return false;
    }

    /**
     * Update traffic source
     */
    public function update(int $id, array $data): bool
    {
        $tokensJson = json_encode($data['tokens'] ?? []);
        
        $stmt = $this->db->prepare(
            "UPDATE traffic_sources 
             SET name = ?, 
                 tokens_json = ?, 
                 postback_template = ?, 
                 cost_param_key = ?, 
                 cost_currency = ?,
                 updated_at = NOW()
             WHERE id = ?"
        );
        
        $stmt->bind_param(
            'sssssi',
            $data['name'],
            $tokensJson,
            $data['postback_template'],
            $data['cost_param_key'],
            $data['cost_currency'],
            $id
        );

        return $stmt->execute();
    }

    /**
     * Delete traffic source
     */
    public function delete(int $id): bool
    {
        // Check if any campaigns are using this traffic source
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM campaigns WHERE traffic_source_id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if ($row['count'] > 0) {
            return false; // Cannot delete if in use
        }

        $stmt = $this->db->prepare("DELETE FROM traffic_sources WHERE id = ?");
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }

    /**
     * Validate traffic source data
     */
    public function validate(array $data): array
    {
        $errors = [];

        // Name validation
        if (empty($data['name'])) {
            $errors['name'] = 'Traffic source name is required';
        } elseif (strlen($data['name']) > 100) {
            $errors['name'] = 'Name must be 100 characters or less';
        }

        // Cost param key validation (optional)
        if (!empty($data['cost_param_key']) && !preg_match('/^[a-zA-Z0-9_-]+$/', $data['cost_param_key'])) {
            $errors['cost_param_key'] = 'Cost param key can only contain letters, numbers, hyphens, and underscores';
        }

        // Cost currency validation (optional, must be 3 chars if provided)
        if (!empty($data['cost_currency']) && strlen($data['cost_currency']) !== 3) {
            $errors['cost_currency'] = 'Currency code must be exactly 3 characters (e.g., USD, EUR)';
        }

        // Tokens validation
        if (isset($data['tokens']) && is_array($data['tokens'])) {
            if (count($data['tokens']) > 20) {
                $errors['tokens'] = 'Maximum 20 custom parameters allowed';
            }

            foreach ($data['tokens'] as $index => $token) {
                if (empty($token['name'])) {
                    $errors["tokens_{$index}_name"] = 'Token name is required';
                }
                if (empty($token['key'])) {
                    $errors["tokens_{$index}_key"] = 'Token key is required';
                } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $token['key'])) {
                    $errors["tokens_{$index}_key"] = 'Token key can only contain letters, numbers, hyphens, and underscores';
                }
            }
        }

        return $errors;
    }
}

