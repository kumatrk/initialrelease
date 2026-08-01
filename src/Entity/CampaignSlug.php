<?php

declare(strict_types=1);

namespace SimpleKuma\Entity;

use mysqli;

/**
 * Campaign Slug Entity
 * Handles CRUD operations for campaign slugs
 */
class CampaignSlug
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Get all slugs for a campaign
     */
    public function getByCampaignId(int $campaignId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM campaign_slugs WHERE campaign_id = ? ORDER BY created_at ASC"
        );
        $stmt->bind_param('i', $campaignId);
        $stmt->execute();
        $result = $stmt->get_result();

        $slugs = [];
        while ($row = $result->fetch_assoc()) {
            $slugs[] = $row;
        }

        return $slugs;
    }

    /**
     * Get slug by slug string (for routing)
     */
    public function getBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT cs.*, c.id as campaign_id, c.name as campaign_name, c.status as campaign_status
             FROM campaign_slugs cs
             INNER JOIN campaigns c ON cs.campaign_id = c.id
             WHERE cs.slug = ?"
        );
        $stmt->bind_param('s', $slug);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        return $row ?: null;
    }

    /**
     * Get slug by ID
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM campaign_slugs WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        return $row ?: null;
    }

    /**
     * Check if slug exists (for validation)
     */
    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        if ($excludeId) {
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM campaign_slugs WHERE slug = ? AND id != ?");
            $stmt->bind_param('si', $slug, $excludeId);
        } else {
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM campaign_slugs WHERE slug = ?");
            $stmt->bind_param('s', $slug);
        }
        
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        return (int)($result['count'] ?? 0) > 0;
    }

    /**
     * Check if slug matches another campaign's campaign_key (/km/ routing uses both).
     */
    public function conflictsWithCampaignKey(string $slug, ?int $excludeCampaignId = null): bool
    {
        if ($excludeCampaignId) {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) as count FROM campaigns WHERE campaign_key = ? AND id != ?"
            );
            $stmt->bind_param('si', $slug, $excludeCampaignId);
        } else {
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM campaigns WHERE campaign_key = ?");
            $stmt->bind_param('s', $slug);
        }

        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        return (int)($result['count'] ?? 0) > 0;
    }

    /**
     * Ensure slug is unique for /km/ routing (campaign_slugs + other campaigns' keys).
     */
    private function assertSlugRoutingUnique(string $slug, ?int $excludeSlugId = null, ?int $excludeCampaignId = null): void
    {
        if ($this->slugExists($slug, $excludeSlugId)) {
            throw new \InvalidArgumentException(
                "Slug '{$slug}' already exists. Slugs must be unique across all campaigns."
            );
        }

        if ($this->conflictsWithCampaignKey($slug, $excludeCampaignId)) {
            throw new \InvalidArgumentException(
                "Slug '{$slug}' matches another campaign's tracking key. Choose a different slug to avoid routing conflicts."
            );
        }
    }

    /**
     * Validate slug format (URL-safe)
     */
    public function validateSlugFormat(string $slug): array
    {
        $errors = [];

        if (empty($slug)) {
            $errors[] = "Slug cannot be empty.";
            return $errors;
        }

        if (strlen($slug) > 50) {
            $errors[] = "Slug cannot exceed 50 characters.";
        }

        // URL-safe: alphanumeric, hyphens, underscores only
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
            $errors[] = "Slug can only contain letters, numbers, hyphens, and underscores.";
        }

        // Check for reserved routes
        $reservedRoutes = ['c', 'api', 'admin', 'install', 'public', 'track', 'lp'];
        if (in_array(strtolower($slug), $reservedRoutes, true)) {
            $errors[] = "Slug '{$slug}' is reserved and cannot be used.";
        }

        return $errors;
    }

    /**
     * Create a new slug
     */
    public function create(int $campaignId, string $slug, string $slugLabel): ?int
    {
        // Validate format
        $formatErrors = $this->validateSlugFormat($slug);
        if (!empty($formatErrors)) {
            throw new \InvalidArgumentException(implode(' ', $formatErrors));
        }

        $this->assertSlugRoutingUnique($slug, null, $campaignId);

        // Validate label
        if (empty($slugLabel)) {
            throw new \InvalidArgumentException("Slug label cannot be empty.");
        }

        if (strlen($slugLabel) > 100) {
            throw new \InvalidArgumentException("Slug label cannot exceed 100 characters.");
        }

        $stmt = $this->db->prepare(
            "INSERT INTO campaign_slugs (campaign_id, slug, slug_label, created_at) VALUES (?, ?, ?, NOW())"
        );
        $stmt->bind_param('iss', $campaignId, $slug, $slugLabel);

        if ($stmt->execute()) {
            $newId = (int) $stmt->insert_id;
            \SimpleKuma\Edge\EdgeCampaignSync::hookAfterSave($this->db, $campaignId);
            return $newId > 0 ? $newId : null;
        }

        return null;
    }

    /**
     * Update slug label
     */
    public function updateLabel(int $id, string $slugLabel): bool
    {
        if (empty($slugLabel)) {
            throw new \InvalidArgumentException("Slug label cannot be empty.");
        }

        if (strlen($slugLabel) > 100) {
            throw new \InvalidArgumentException("Slug label cannot exceed 100 characters.");
        }

        $stmt = $this->db->prepare("UPDATE campaign_slugs SET slug_label = ? WHERE id = ?");
        $stmt->bind_param('si', $slugLabel, $id);

        return $stmt->execute();
    }

    /**
     * Update slug (both slug and label)
     */
    public function update(int $id, string $slug, string $slugLabel): bool
    {
        // Validate format
        $formatErrors = $this->validateSlugFormat($slug);
        if (!empty($formatErrors)) {
            throw new \InvalidArgumentException(implode(' ', $formatErrors));
        }

        $existing = $this->getById($id);
        if (!$existing) {
            throw new \InvalidArgumentException('Slug not found.');
        }

        $campaignId = (int)$existing['campaign_id'];
        $this->assertSlugRoutingUnique($slug, $id, $campaignId);

        // Validate label
        if (empty($slugLabel)) {
            throw new \InvalidArgumentException("Slug label cannot be empty.");
        }

        if (strlen($slugLabel) > 100) {
            throw new \InvalidArgumentException("Slug label cannot exceed 100 characters.");
        }

        $stmt = $this->db->prepare("UPDATE campaign_slugs SET slug = ?, slug_label = ? WHERE id = ?");
        $stmt->bind_param('ssi', $slug, $slugLabel, $id);

        $ok = $stmt->execute();
        if ($ok) {
            $oldSlug = (string) ($existing['slug'] ?? '');
            if ($oldSlug !== '' && $oldSlug !== $slug) {
                \SimpleKuma\Edge\EdgeCampaignSync::hookBeforeDelete($this->db, '', [$oldSlug]);
            }
            \SimpleKuma\Edge\EdgeCampaignSync::hookAfterSave($this->db, $campaignId);
        }
        return $ok;
    }

    /**
     * Delete a slug
     * Note: This does NOT delete historical click data. The slug_id in clicks table will be set to NULL
     * due to ON DELETE SET NULL foreign key constraint, preserving historical attribution.
     */
    public function delete(int $id): bool
    {
        $existing = $this->getById($id);
        $stmt = $this->db->prepare("DELETE FROM campaign_slugs WHERE id = ?");
        $stmt->bind_param('i', $id);

        $ok = $stmt->execute();
        if ($ok && $existing) {
            $oldSlug = (string) ($existing['slug'] ?? '');
            $campaignId = (int) ($existing['campaign_id'] ?? 0);
            if ($oldSlug !== '') {
                \SimpleKuma\Edge\EdgeCampaignSync::hookBeforeDelete($this->db, '', [$oldSlug]);
            }
            if ($campaignId > 0) {
                \SimpleKuma\Edge\EdgeCampaignSync::hookAfterSave($this->db, $campaignId);
            }
        }
        return $ok;
    }

    /**
     * Delete all slugs for a campaign
     */
    public function deleteByCampaignId(int $campaignId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM campaign_slugs WHERE campaign_id = ?");
        $stmt->bind_param('i', $campaignId);

        return $stmt->execute();
    }
}

