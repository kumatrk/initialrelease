<?php

declare(strict_types=1);

namespace SimpleKuma\Campaign;

use mysqli;

/**
 * Finds non-archived campaigns that reference offers or landing pages in rotation_json.
 */
class CampaignRotationReference
{
    private mysqli $db;

    /** @var list<array{id: int, name: string, status: string, rotation_json: string, fallback_offer_id?: int|null}>|null */
    private ?array $campaignRows = null;

    private ?bool $hasFallbackOfferColumn = null;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * @return array<int, list<array{id: int, name: string, status: string}>>
     */
    public function getCampaignsByOfferMap(): array
    {
        $map = [];
        foreach ($this->loadCampaignRows() as $camp) {
            $rotation = json_decode($camp['rotation_json'] ?? '{}', true) ?? [];
            $this->collectOffersFromRotation($rotation, $camp, $map);
            if ($this->hasFallbackOfferColumn() && !empty($camp['fallback_offer_id'])) {
                $this->attachCampaign($map, (int)$camp['fallback_offer_id'], $camp);
            }
        }

        return $map;
    }

    /**
     * @return array<int, list<array{id: int, name: string, status: string}>>
     */
    public function getCampaignsByLandingPageMap(): array
    {
        $map = [];
        foreach ($this->loadCampaignRows() as $camp) {
            $rotation = json_decode($camp['rotation_json'] ?? '{}', true) ?? [];
            $this->collectLandingPagesFromRotation($rotation, $camp, $map);
        }

        return $map;
    }

    /**
     * @return list<array{id: int, name: string, status: string}>
     */
    public function getCampaignsUsingOffer(int $offerId): array
    {
        return $this->getCampaignsByOfferMap()[$offerId] ?? [];
    }

    /**
     * @return list<array{id: int, name: string, status: string}>
     */
    public function getCampaignsUsingLandingPage(int $landingPageId): array
    {
        return $this->getCampaignsByLandingPageMap()[$landingPageId] ?? [];
    }

    /**
     * @param list<array{id: int, name: string, status: string}> $campaigns
     */
    public static function formatDeleteBlockMessage(string $itemLabel, array $campaigns): string
    {
        $count = count($campaigns);
        if ($count === 0) {
            return '';
        }

        $names = array_map(
            static fn(array $c): string => (string)($c['name'] ?? 'Campaign #' . ($c['id'] ?? '?')),
            $campaigns
        );
        $preview = implode(', ', array_slice($names, 0, 5));
        if ($count > 5) {
            $preview .= ', and ' . ($count - 5) . ' more';
        }

        $campaignWord = $count === 1 ? 'campaign' : 'campaigns';

        return "Cannot delete this {$itemLabel} — it is used by {$count} {$campaignWord}: {$preview}. "
            . 'Remove it from those campaigns (or archive them) first.';
    }

    /**
     * @param array<string, list<array{id: int, name: string, status: string}>> $map
     * @param array{id: int, name: string, status: string} $camp
     */
    private function attachCampaign(array &$map, int $resourceId, array $camp): void
    {
        if ($resourceId <= 0) {
            return;
        }

        if (!isset($map[$resourceId])) {
            $map[$resourceId] = [];
        }

        foreach ($map[$resourceId] as $existing) {
            if ((int)$existing['id'] === (int)$camp['id']) {
                return;
            }
        }

        $map[$resourceId][] = [
            'id' => (int)$camp['id'],
            'name' => (string)$camp['name'],
            'status' => (string)$camp['status'],
        ];
    }

    /**
     * @param array<string, mixed> $rotation
     * @param array<string, mixed> $camp
     * @param array<int, list<array{id: int, name: string, status: string}>> $map
     */
    private function collectOffersFromRotation(array $rotation, array $camp, array &$map): void
    {
        if (isset($rotation[0]) && is_array($rotation[0]) && isset($rotation[0]['id'])) {
            $this->collectOfferItems($rotation, $camp, $map);
        }

        if (isset($rotation['offers']) && is_array($rotation['offers'])) {
            $this->collectOfferItems($rotation['offers'], $camp, $map);
        }

        if (isset($rotation['lp_path']['offers']) && is_array($rotation['lp_path']['offers'])) {
            $this->collectOfferItems($rotation['lp_path']['offers'], $camp, $map);
        }

        if (isset($rotation['direct_path']['offers']) && is_array($rotation['direct_path']['offers'])) {
            $this->collectOfferItems($rotation['direct_path']['offers'], $camp, $map);
        }
    }

    /**
     * @param list<array<string, mixed>> $offers
     * @param array<string, mixed> $camp
     * @param array<int, list<array{id: int, name: string, status: string}>> $map
     */
    private function collectOfferItems(array $offers, array $camp, array &$map): void
    {
        foreach ($offers as $offer) {
            if (!is_array($offer)) {
                continue;
            }
            $offerId = isset($offer['id']) ? (int)$offer['id'] : 0;
            if ($offerId > 0) {
                $this->attachCampaign($map, $offerId, $camp);
            }
        }
    }

    /**
     * @param array<string, mixed> $rotation
     * @param array<string, mixed> $camp
     * @param array<int, list<array{id: int, name: string, status: string}>> $map
     */
    private function collectLandingPagesFromRotation(array $rotation, array $camp, array &$map): void
    {
        if (isset($rotation['landing_pages']) && is_array($rotation['landing_pages'])) {
            $this->collectLandingPageItems($rotation['landing_pages'], $camp, $map);
        }

        if (isset($rotation['lp_path']['landing_pages']) && is_array($rotation['lp_path']['landing_pages'])) {
            $this->collectLandingPageItems($rotation['lp_path']['landing_pages'], $camp, $map);
        }
    }

    /**
     * @param list<array<string, mixed>> $landingPages
     * @param array<string, mixed> $camp
     * @param array<int, list<array{id: int, name: string, status: string}>> $map
     */
    private function collectLandingPageItems(array $landingPages, array $camp, array &$map): void
    {
        foreach ($landingPages as $lp) {
            if (!is_array($lp)) {
                continue;
            }
            $lpId = isset($lp['id']) ? (int)$lp['id'] : 0;
            if ($lpId > 0) {
                $this->attachCampaign($map, $lpId, $camp);
            }
        }
    }

    private function hasFallbackOfferColumn(): bool
    {
        if ($this->hasFallbackOfferColumn !== null) {
            return $this->hasFallbackOfferColumn;
        }

        $check = $this->db->query("SHOW COLUMNS FROM campaigns LIKE 'fallback_offer_id'");
        $this->hasFallbackOfferColumn = $check && $check->num_rows > 0;

        return $this->hasFallbackOfferColumn;
    }

    /**
     * @return list<array{id: int, name: string, status: string, rotation_json: string, fallback_offer_id?: int|null}>
     */
    private function loadCampaignRows(): array
    {
        if ($this->campaignRows !== null) {
            return $this->campaignRows;
        }

        $select = $this->hasFallbackOfferColumn()
            ? 'SELECT id, name, status, rotation_json, fallback_offer_id FROM campaigns WHERE status != ?'
            : 'SELECT id, name, status, rotation_json FROM campaigns WHERE status != ?';

        $stmt = $this->db->prepare($select);
        if (!$stmt) {
            $this->campaignRows = [];
            return $this->campaignRows;
        }

        $archived = 'archived';
        $stmt->bind_param('s', $archived);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        $this->campaignRows = $rows;

        return $this->campaignRows;
    }
}
