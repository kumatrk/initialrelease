<?php

declare(strict_types=1);

namespace SimpleKuma\Honeycomb;

use mysqli;

/**
 * Overlay Honeycomb addon spend onto campaign stats without scanning clicks.
 * Reads honeycomb_campaign_hourly_costs via campaign_addon_bindings.
 * Addons that write that table should not also register CostOverlayProvider for the same spend.
 */
final class HoneycombCostAggregator
{
    private HourlyCostStore $hourly;

    public function __construct(
        private mysqli $db,
        ?HourlyCostStore $hourly = null
    ) {
        $this->hourly = $hourly ?? new HourlyCostStore($db);
    }

    /**
     * @param list<int> $campaignIds
     * @return array<int, float> campaignId => spend
     */
    public function getCostsByCampaignIds(
        array $campaignIds,
        string $utcFrom,
        string $utcTo,
        string $timezone = 'UTC'
    ): array {
        unset($timezone); // reserved for future TZ-aware bucketing
        return $this->hourly->sumDeltaByCampaignIds($campaignIds, $utcFrom, $utcTo);
    }

    public function getCampaignTotalCost(
        int $campaignId,
        string $utcFrom,
        string $utcTo,
        string $timezone = 'UTC'
    ): float {
        if ($campaignId < 1) {
            return 0.0;
        }
        $map = $this->getCostsByCampaignIds([$campaignId], $utcFrom, $utcTo, $timezone);
        return (float) ($map[$campaignId] ?? 0.0);
    }
}
