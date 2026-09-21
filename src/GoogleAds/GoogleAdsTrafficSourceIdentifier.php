<?php

declare(strict_types=1);

namespace SimpleKuma\GoogleAds;

use mysqli;

/**
 * Identifies traffic sources used for Google Ads / YouTube cost tracking.
 */
class GoogleAdsTrafficSourceIdentifier
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * @return list<int>
     */
    public function getGoogleSourceIds(): array
    {
        $result = $this->db->query(
            "SELECT id FROM traffic_sources
             WHERE LOWER(name) LIKE '%google%'
                OR LOWER(name) LIKE '%youtube%'
             ORDER BY id ASC"
        );

        if (!$result) {
            return [];
        }

        $sourceIds = [];
        while ($row = $result->fetch_assoc()) {
            $sourceIds[] = (int)$row['id'];
        }

        return $sourceIds;
    }
}
