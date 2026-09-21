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
        $tokensJson = json_encode($data['tokens'] ?? $data['tokens_json'] ?? []);
        if (is_array($data['tokens_json'] ?? null)) {
            $tokensJson = json_encode($data['tokens_json']);
        } elseif (is_string($data['tokens_json'] ?? null) && ($data['tokens_json'] ?? '') !== '') {
            $tokensJson = $data['tokens_json'];
        }
        $costTrackingMethod = $data['cost_tracking_method'] ?? 'manual_token';
        $providerKey = array_key_exists('provider_key', $data)
            ? (trim((string) $data['provider_key']) !== '' ? trim((string) $data['provider_key']) : null)
            : null;
        $postback = $data['postback_template'] ?? null;
        $costParam = $data['cost_param_key'] ?? null;
        $costCurrency = $data['cost_currency'] ?? null;
        $name = (string) ($data['name'] ?? '');

        if ($this->hasProviderKeyColumn()) {
            $stmt = $this->db->prepare(
                'INSERT INTO traffic_sources
                (name, provider_key, tokens_json, postback_template, cost_tracking_method, cost_param_key, cost_currency, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->bind_param(
                'sssssss',
                $name,
                $providerKey,
                $tokensJson,
                $postback,
                $costTrackingMethod,
                $costParam,
                $costCurrency
            );
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO traffic_sources
                (name, tokens_json, postback_template, cost_tracking_method, cost_param_key, cost_currency, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->bind_param(
                'ssssss',
                $name,
                $tokensJson,
                $postback,
                $costTrackingMethod,
                $costParam,
                $costCurrency
            );
        }

        $stmt->execute();
        return (int) $stmt->insert_id;
    }

    /**
     * Update traffic source
     */
    public function update(int $id, array $data): bool
    {
        $tokensJson = json_encode($data['tokens'] ?? $data['tokens_json'] ?? []);
        if (is_array($data['tokens_json'] ?? null)) {
            $tokensJson = json_encode($data['tokens_json']);
        } elseif (is_string($data['tokens_json'] ?? null) && ($data['tokens_json'] ?? '') !== '') {
            $tokensJson = $data['tokens_json'];
        }
        $costTrackingMethod = $data['cost_tracking_method'] ?? 'manual_token';
        $postback = $data['postback_template'] ?? null;
        $costParam = $data['cost_param_key'] ?? null;
        $costCurrency = $data['cost_currency'] ?? null;
        $name = (string) ($data['name'] ?? '');

        if ($this->hasProviderKeyColumn() && array_key_exists('provider_key', $data)) {
            $providerKey = trim((string) $data['provider_key']) !== ''
                ? trim((string) $data['provider_key'])
                : null;
            $stmt = $this->db->prepare(
                'UPDATE traffic_sources
                SET name = ?, provider_key = ?, tokens_json = ?, postback_template = ?,
                    cost_tracking_method = ?, cost_param_key = ?, cost_currency = ?, updated_at = NOW()
                WHERE id = ?'
            );
            $stmt->bind_param(
                'sssssssi',
                $name,
                $providerKey,
                $tokensJson,
                $postback,
                $costTrackingMethod,
                $costParam,
                $costCurrency,
                $id
            );
        } else {
            $stmt = $this->db->prepare(
                'UPDATE traffic_sources
                SET name = ?, tokens_json = ?, postback_template = ?,
                    cost_tracking_method = ?, cost_param_key = ?, cost_currency = ?, updated_at = NOW()
                WHERE id = ?'
            );
            $stmt->bind_param(
                'ssssssi',
                $name,
                $tokensJson,
                $postback,
                $costTrackingMethod,
                $costParam,
                $costCurrency,
                $id
            );
        }

        $ok = $stmt->execute();
        if ($ok) {
            EdgeCampaignSync::hookTrafficSourceChanged($this->db, $id);
        }
        return $ok;
    }

    private function hasProviderKeyColumn(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $result = $this->db->query("SHOW COLUMNS FROM traffic_sources LIKE 'provider_key'");
        $cached = $result !== false && $result->num_rows > 0;
        return $cached;
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


