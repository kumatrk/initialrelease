<?php

declare(strict_types=1);

namespace SimpleKuma\Entity;

use mysqli;

/**
 * Custom Postback Entity
 * Manages custom postback URLs with token placeholders
 */
class CustomPostback
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Get all custom postbacks
     */
    public function getAll(): array
    {
        $result = $this->db->query(
            "SELECT cp.*, COUNT(ccp.campaign_id) as campaign_count
             FROM custom_postbacks cp
             LEFT JOIN campaign_custom_postbacks ccp ON cp.id = ccp.custom_postback_id
             GROUP BY cp.id
             ORDER BY cp.name ASC"
        );

        $postbacks = [];
        while ($row = $result->fetch_assoc()) {
            $postbacks[] = $row;
        }

        return $postbacks;
    }

    /**
     * Get custom postback by ID
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM custom_postbacks WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc() ?: null;
    }

    /**
     * Create a new custom postback
     */
    public function create(array $data): ?int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO custom_postbacks (name, postback_url, description, created_at)
             VALUES (?, ?, ?, NOW())"
        );

        $name = $data['name'];
        $postbackUrl = $data['postback_url'];
        $description = $data['description'] ?? null;

        $stmt->bind_param('sss', $name, $postbackUrl, $description);

        if ($stmt->execute()) {
            return $stmt->insert_id;
        }

        return null;
    }

    /**
     * Update a custom postback
     */
    public function update(int $id, array $data): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE custom_postbacks
             SET name = ?, postback_url = ?, description = ?, updated_at = NOW()
             WHERE id = ?"
        );

        $name = $data['name'];
        $postbackUrl = $data['postback_url'];
        $description = $data['description'] ?? null;

        $stmt->bind_param('sssi', $name, $postbackUrl, $description, $id);

        return $stmt->execute();
    }

    /**
     * Delete a custom postback
     */
    public function delete(int $id): bool
    {
        // Cascade delete will remove campaign associations automatically
        $stmt = $this->db->prepare("DELETE FROM custom_postbacks WHERE id = ?");
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }

    /**
     * Validate postback data
     */
    public function validate(array $data, bool $isUpdate = false): array
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors[] = "Postback name is required.";
        } elseif (strlen($data['name']) > 100) {
            $errors[] = "Postback name cannot exceed 100 characters.";
        } else {
            // Check for duplicate names
            $stmt = $this->db->prepare("SELECT id FROM custom_postbacks WHERE name = ?" . ($isUpdate ? " AND id != ?" : ""));
            if ($isUpdate) {
                $stmt->bind_param('si', $data['name'], $data['id']);
            } else {
                $stmt->bind_param('s', $data['name']);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $errors[] = "A postback with this name already exists.";
            }
        }

        if (empty($data['postback_url'])) {
            $errors[] = "Postback URL is required.";
        } elseif (!filter_var($data['postback_url'], FILTER_VALIDATE_URL) && !preg_match('/\{[^}]+\}/', $data['postback_url'])) {
            // Allow URLs with tokens even if they're not valid URLs yet
            $errors[] = "Postback URL must be a valid URL format.";
        }

        if (!empty($data['description']) && strlen($data['description']) > 65535) {
            $errors[] = "Description is too long.";
        }

        return $errors;
    }

    /**
     * Get custom postbacks for a specific campaign
     */
    public function getForCampaign(int $campaignId): array
    {
        $stmt = $this->db->prepare(
            "SELECT cp.* 
             FROM custom_postbacks cp
             INNER JOIN campaign_custom_postbacks ccp ON cp.id = ccp.custom_postback_id
             WHERE ccp.campaign_id = ?
             ORDER BY cp.name ASC"
        );
        $stmt->bind_param('i', $campaignId);
        $stmt->execute();
        $result = $stmt->get_result();

        $postbacks = [];
        while ($row = $result->fetch_assoc()) {
            $postbacks[] = $row;
        }

        return $postbacks;
    }

    /**
     * Set custom postbacks for a campaign (replaces existing)
     */
    public function setForCampaign(int $campaignId, array $postbackIds): bool
    {
        // Delete existing associations
        $deleteStmt = $this->db->prepare("DELETE FROM campaign_custom_postbacks WHERE campaign_id = ?");
        $deleteStmt->bind_param('i', $campaignId);
        $deleteStmt->execute();

        if (empty($postbackIds)) {
            return true; // Just clearing selections
        }

        // Insert new associations
        $insertStmt = $this->db->prepare(
            "INSERT INTO campaign_custom_postbacks (campaign_id, custom_postback_id, created_at)
             VALUES (?, ?, NOW())"
        );

        foreach ($postbackIds as $postbackId) {
            $postbackId = (int)$postbackId;
            if ($postbackId > 0) {
                $insertStmt->bind_param('ii', $campaignId, $postbackId);
                $insertStmt->execute();
            }
        }

        return true;
    }
}

