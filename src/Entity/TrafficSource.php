<?php

declare(strict_types=1);

namespace SimpleKuma\Entity;

use mysqli;
use SimpleKuma\Edge\EdgeCampaignSync;

/**
 * Traffic Source Entity
 * Handles CRUD operations for traffic sources
 */
class TrafficSource
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
            "SELECT * FROM traffic_sources ORDER BY name ASC"
        );

        if (!$result) {
            return [];
        }

        $sources = [];
        while ($row = $result->fetch_assoc()) {
            $row['tokens_json'] = $row['tokens_json'] ? json_decode($row['tokens_json'], true) : [];
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
            "SELECT * FROM traffic_sources WHERE id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if ($row) {
            $row['tokens_json'] = $row['tokens_json'] ? json_decode($row['tokens_json'], true) : [];
        }

        return $row ?: null;
    }

    /**
     * Create new traffic source
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO traffic_sources 
            (name, tokens_json, postback_template, cost_tracking_method, cost_param_key, cost_currency, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())"
        );

        $tokensJson = json_encode($data['tokens'] ?? []);
        $costTrackingMethod = $data['cost_tracking_method'] ?? 'manual_token';

        $stmt->bind_param(
            'ssssss',
            $data['name'],
            $tokensJson,
            $data['postback_template'],
            $costTrackingMethod,
            $data['cost_param_key'],
            $data['cost_currency']
        );

        $stmt->execute();
        return $stmt->insert_id;
    }

    /**
     * Update traffic source
     */
    public function update(int $id, array $data): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE traffic_sources 
            SET name = ?, tokens_json = ?, postback_template = ?, 
                cost_tracking_method = ?, cost_param_key = ?, cost_currency = ?, updated_at = NOW()
            WHERE id = ?"
        );

        $tokensJson = json_encode($data['tokens'] ?? []);
        $costTrackingMethod = $data['cost_tracking_method'] ?? 'manual_token';

        $stmt->bind_param(
            'ssssssi',
            $data['name'],
            $tokensJson,
            $data['postback_template'],
            $costTrackingMethod,
            $data['cost_param_key'],
            $data['cost_currency'],
            $id
        );

        $ok = $stmt->execute();
        if ($ok) {
            EdgeCampaignSync::hookTrafficSourceChanged($this->db, $id);
        }
        return $ok;
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

        // Name is required
        if (empty($data['name'])) {
            $errors['name'] = 'Traffic source name is required';
        } elseif (strlen($data['name']) > 100) {
            $errors['name'] = 'Traffic source name must be 100 characters or less';
        }

        // Cost currency validation
        if (!empty($data['cost_currency']) && strlen($data['cost_currency']) !== 3) {
            $errors['cost_currency'] = 'Currency code must be 3 characters (e.g., USD)';
        }

        // Validate tokens (unique parameters, max 20)
        if (!empty($data['tokens']) && is_array($data['tokens'])) {
            $tokenParameters = [];
            foreach ($data['tokens'] as $token) {
                $parameter = trim($token['parameter'] ?? '');
                if (empty($parameter)) {
                    continue; // Skip empty tokens
                }
                if (in_array($parameter, $tokenParameters, true)) {
                    $errors['tokens'] = "Duplicate parameter name: {$parameter}. Parameter names must be unique.";
                    break;
                }
                $tokenParameters[] = $parameter;
            }
            if (count($tokenParameters) > 20) {
                $errors['tokens'] = 'Maximum 20 tokens allowed';
            }
        }

        return $errors;
    }
}


