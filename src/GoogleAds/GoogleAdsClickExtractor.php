<?php

declare(strict_types=1);

namespace SimpleKuma\GoogleAds;

use mysqli;

/**
 * Extracts unique Google Ads campaign IDs from recent Google/YouTube clicks.
 */
class GoogleAdsClickExtractor
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Get unique Google campaign IDs from recent clicks (last 7 days).
     *
     * @return list<array{campaign_id: string, customer_id: ?string}>
     */
    public function extractUniqueCampaigns(array $googleSourceIds): array
    {
        if (empty($googleSourceIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($googleSourceIds), '?'));
        $campaignSubquery = "SELECT id FROM campaigns WHERE traffic_source_id IN ($placeholders)";
        $campaignIdSql = GoogleAdsTokenHelper::campaignIdExtractSql('c.extra_json');
        $hasCampaignId = GoogleAdsTokenHelper::hasCampaignIdSql('c.extra_json');

        $query = "
            SELECT DISTINCT
                {$campaignIdSql} AS campaign_id,
                JSON_UNQUOTE(JSON_EXTRACT(c.extra_json, '$.traffic_source_tokens.customer_id')) AS customer_id
            FROM clicks c
            WHERE c.campaign_id IN ($campaignSubquery)
                AND c.ts >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                AND {$hasCampaignId}
            ORDER BY campaign_id ASC
        ";

        $stmt = $this->db->prepare($query);
        $bindTypes = str_repeat('i', count($googleSourceIds));
        $stmt->bind_param($bindTypes, ...$googleSourceIds);
        $stmt->execute();
        $result = $stmt->get_result();

        $campaigns = [];
        while ($row = $result->fetch_assoc()) {
            $campaignId = trim((string)($row['campaign_id'] ?? ''));
            if ($campaignId === '' || $campaignId === 'null') {
                continue;
            }

            $extractedCustomerId = trim((string)($row['customer_id'] ?? ''));
            $campaigns[] = [
                'campaign_id' => $campaignId,
                'customer_id' => ($extractedCustomerId !== '' && $extractedCustomerId !== 'null')
                    ? $extractedCustomerId
                    : null,
            ];
        }

        return $campaigns;
    }

    /**
     * @return list<array{campaign_id: string, customer_id: ?string}>
     */
    public function extractCampaignsForCustomer(array $googleSourceIds, ?string $customerId = null): array
    {
        $allCampaigns = $this->extractUniqueCampaigns($googleSourceIds);

        if ($customerId === null) {
            return $allCampaigns;
        }

        return array_values(array_filter(
            $allCampaigns,
            static fn (array $campaign): bool => ($campaign['customer_id'] ?? null) === $customerId
                || ($campaign['customer_id'] ?? null) === null
        ));
    }
}
