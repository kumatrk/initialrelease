<?php

declare(strict_types=1);

namespace SimpleKuma\GoogleAds;

/**
 * Google Ads API client for campaign spend reporting (GAQL / SearchStream).
 */
class GoogleAdsInsightsClient
{
    private $client = null;
    private string $customerId;
    private string $configPath;
    private ?string $apiVersion = null;
    private ?GoogleAdsApiCallTracker $callTracker;
    private ?int $integrationId;

    public function __construct(
        string $configPath,
        string $customerId,
        ?GoogleAdsApiCallTracker $callTracker = null,
        ?int $integrationId = null
    ) {
        $this->configPath = $configPath;
        $this->customerId = preg_replace('/\D/', '', $customerId) ?? '';
        $this->callTracker = $callTracker;
        $this->integrationId = $integrationId;

        $builderClass = self::resolveLibClass('GoogleAdsClientBuilder');
        if ($builderClass === null) {
            error_log('Google Ads PHP library not installed. Run: composer require googleads/google-ads-php');
            return;
        }

        $this->apiVersion = self::versionFromClass($builderClass);

        try {
            $this->client = (new $builderClass())
                ->fromFile($configPath)
                ->build();
        } catch (\Throwable $e) {
            error_log('Google Ads Client initialization failed: ' . $e->getMessage());
            $this->client = null;
        }
    }

    /**
     * @return list<array{
     *   campaign_id: int|string,
     *   campaign_name: string,
     *   channel_type: string,
     *   date: string,
     *   impressions: int,
     *   clicks: int,
     *   avg_cpc: float,
     *   avg_cpm: float,
     *   avg_cpv: float,
     *   cost_micros: int,
     *   cost: float
     * }>
     */
    public function fetchCampaignSpend(string $date, string $channelType = 'SEARCH'): array
    {
        if (!$this->client) {
            return [];
        }

        $channelType = strtoupper($channelType);
        $query = $this->buildQuery($date, $channelType);
        $endpoint = 'searchStream:' . $channelType;

        try {
            $googleAdsServiceClient = $this->client->getGoogleAdsServiceClient();
            $stream = $this->runSearchStream($googleAdsServiceClient, $query);

            $results = [];
            foreach ($stream->iterateAllElements() as $row) {
                $campaign = $row->getCampaign();
                $segments = $row->getSegments();
                $metrics = $row->getMetrics();

                $costMicros = (int)$metrics->getCostMicros();

                $results[] = [
                    'campaign_id' => $campaign->getId(),
                    'campaign_name' => $campaign->getName(),
                    'channel_type' => (string)$campaign->getAdvertisingChannelType(),
                    'date' => $segments->getDate(),
                    'impressions' => (int)$metrics->getImpressions(),
                    'clicks' => (int)$metrics->getClicks(),
                    'avg_cpc' => $this->microsToUnits($metrics->getAverageCpc()),
                    'avg_cpm' => $this->microsToUnits($metrics->getAverageCpm()),
                    'avg_cpv' => $this->microsToUnits($metrics->getAverageCpv()),
                    'cost_micros' => $costMicros,
                    'cost' => $costMicros / 1_000_000,
                ];
            }

            $this->logApiCall($endpoint, true);
            return $results;
        } catch (\Throwable $e) {
            $this->logApiCall($endpoint, false);
            error_log('Google Ads API Error: ' . $e->getMessage());
            return [];
        }
    }

    private function logApiCall(string $endpoint, bool $success): void
    {
        if ($this->callTracker === null) {
            return;
        }

        $this->callTracker->logCall(
            $endpoint,
            'POST',
            $this->customerId !== '' ? $this->customerId : null,
            $this->integrationId,
            null,
            $success
        );
    }

    private function buildQuery(string $date, string $channelType): string
    {
        $date = preg_replace('/[^0-9\-]/', '', $date);
        $channelType = preg_replace('/[^A-Z_]/', '', strtoupper($channelType));

        return "
            SELECT
              campaign.id,
              campaign.name,
              campaign.advertising_channel_type,
              segments.date,
              metrics.impressions,
              metrics.clicks,
              metrics.average_cpc,
              metrics.average_cpm,
              metrics.average_cpv,
              metrics.cost_micros
            FROM campaign
            WHERE segments.date = '{$date}'
              AND campaign.advertising_channel_type = '{$channelType}'
        ";
    }

    /**
     * @param object $googleAdsServiceClient
     * @return object stream with iterateAllElements()
     */
    private function runSearchStream(object $googleAdsServiceClient, string $query): object
    {
        if ($this->apiVersion !== null) {
            $requestClass = "Google\\Ads\\GoogleAds\\{$this->apiVersion}\\Services\\SearchGoogleAdsStreamRequest";
            if (class_exists($requestClass) && method_exists($requestClass, 'build')) {
                return $googleAdsServiceClient->searchStream(
                    $requestClass::build($this->customerId, $query)
                );
            }
        }

        return $googleAdsServiceClient->searchStream($this->customerId, $query);
    }

    private function microsToUnits($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return ((float)$value) / 1_000_000;
    }

    public function isInitialized(): bool
    {
        return $this->client !== null;
    }

    private static function resolveLibClass(string $shortName): ?string
    {
        foreach (['V24', 'V23', 'V22', 'V21', 'V20', 'V19', 'V18', 'V17', 'V16', 'V15', 'V14', 'V13', 'V12', 'V11', 'V10'] as $version) {
            $class = "Google\\Ads\\GoogleAds\\Lib\\{$version}\\{$shortName}";
            if (class_exists($class)) {
                return $class;
            }
        }

        return null;
    }

    private static function versionFromClass(string $class): ?string
    {
        if (preg_match('/\\\\(V\d+)\\\\/', $class, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
