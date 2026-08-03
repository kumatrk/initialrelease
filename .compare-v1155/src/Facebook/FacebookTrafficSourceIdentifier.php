<?php

declare(strict_types=1);

namespace SimpleKuma\Facebook;

use mysqli;

/**
 * Facebook Traffic Source Identifier
 * Identifies and filters Facebook traffic sources for cost syncing
 */
class FacebookTrafficSourceIdentifier
{
    private mysqli $db;
    private ?array $facebookSourceIds = null;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Get all Facebook traffic source IDs
     * Uses caching to avoid repeated database queries
     */
    public function getFacebookSourceIds(): array
    {
        if ($this->facebookSourceIds !== null) {
            return $this->facebookSourceIds;
        }

        // Query for traffic sources where name contains 'Facebook' (case insensitive)
        $result = $this->db->query(
            "SELECT id FROM traffic_sources 
             WHERE LOWER(name) LIKE '%facebook%' 
             ORDER BY id ASC"
        );

        $this->facebookSourceIds = [];
        while ($row = $result->fetch_assoc()) {
            $this->facebookSourceIds[] = (int)$row['id'];
        }

        return $this->facebookSourceIds;
    }

    /**
     * Check if a traffic source ID is a Facebook source
     */
    public function isFacebookSource(int $trafficSourceId): bool
    {
        $facebookIds = $this->getFacebookSourceIds();
        return in_array($trafficSourceId, $facebookIds, true);
    }

    /**
     * Filter an array of traffic source IDs to only include Facebook sources
     */
    public function filterFacebookSources(array $trafficSourceIds): array
    {
        $facebookIds = $this->getFacebookSourceIds();
        return array_values(array_intersect($trafficSourceIds, $facebookIds));
    }

    /**
     * Get count of Facebook traffic sources
     */
    public function getFacebookSourceCount(): int
    {
        return count($this->getFacebookSourceIds());
    }

    /**
     * Clear the cache (useful if traffic sources are updated)
     */
    public function clearCache(): void
    {
        $this->facebookSourceIds = null;
    }
}


