<?php

declare(strict_types=1);

namespace SimpleKuma\Honeycomb;

/**
 * Overlay mapped spend onto stats. Must not scan fat clicks for unfiltered KPI/chart.
 */
interface CostOverlayProvider
{
    public function providerKey(): string;

    /**
     * @param list<int> $campaignIds
     * @return array<int, float> campaignId => spend
     */
    public function overlayCampaignTotals(
        array $campaignIds,
        string $dateFrom,
        string $dateTo,
        string $timezone
    ): array;
}
