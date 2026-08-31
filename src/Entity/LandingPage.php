<?php

declare(strict_types=1);

namespace SimpleKuma\Entity;

use mysqli;
use SimpleKuma\Campaign\CampaignRotationReference;
use SimpleKuma\Edge\EdgeCampaignSync;

/**
 * Landing Page Entity
 * Handles CRUD operations for landing pages
 */
class LandingPage
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function getAll(): array
    {
        $result = $this->db->query(
            "SELECT * FROM landing_pages ORDER BY created_at DESC"
        );

        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM landing_pages WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc() ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO landing_pages (name, url, notes, created_at) 
             VALUES (?, ?, ?, NOW())"
        );

        $stmt->bind_param('sss', $data['name'], $data['url'], $data['notes']);
        $stmt->execute();
        $newId = (int)$stmt->insert_id;
        $stmt->close();
        if ($newId > 0) {
            $this->saveTags($newId, $data);
        }
        return $newId;
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE landing_pages 
            SET name = ?, url = ?, notes = ?, updated_at = NOW()
            WHERE id = ?"
        );

        $stmt->bind_param('sssi', $data['name'], $data['url'], $data['notes'], $id);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok) {
            $this->saveTags($id, $data);
            EdgeCampaignSync::hookLandingPageChanged($this->db, $id);
        }
        return $ok;
    }

    private function landingPagesTableHasTags(): bool
    {
        $result = $this->db->query("SHOW COLUMNS FROM landing_pages LIKE 'tags'");
        return $result && $result->num_rows > 0;
    }

    private function saveTags(int $lpId, array $data): void
    {
        if (!$this->landingPagesTableHasTags()) {
            return;
        }
        $tags = isset($data['tags']) && trim((string)$data['tags']) !== '' ? trim((string)$data['tags']) : null;
        if ($tags === null) {
            $stmt = $this->db->prepare('UPDATE landing_pages SET tags = NULL WHERE id = ?');
            if ($stmt) {
                $stmt->bind_param('i', $lpId);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            $stmt = $this->db->prepare('UPDATE landing_pages SET tags = ? WHERE id = ?');
            if ($stmt) {
                $stmt->bind_param('si', $tags, $lpId);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    /**
     * Returns a user-facing message when delete is blocked, or null when allowed.
     */
    public function getDeleteBlockReason(int $id): ?string
    {
        $campaigns = (new CampaignRotationReference($this->db))->getCampaignsUsingLandingPage($id);
        if ($campaigns === []) {
            return null;
        }

        return CampaignRotationReference::formatDeleteBlockMessage('landing page', $campaigns);
    }

    public function delete(int $id): bool
    {
        if ($this->getDeleteBlockReason($id) !== null) {
            return false;
        }

        $stmt = $this->db->prepare("DELETE FROM landing_pages WHERE id = ?");
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        if ($ok) {
            EdgeCampaignSync::hookLandingPageChanged($this->db, $id);
        }
        return $ok;
    }

    public function validate(array $data): array
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors['name'] = 'Landing page name is required';
        }

        if (empty($data['url'])) {
            $errors['url'] = 'Landing page URL is required';
        } elseif (!filter_var($data['url'], FILTER_VALIDATE_URL)) {
            $errors['url'] = 'Landing page URL must be a valid URL';
        }

        return $errors;
    }
}


