<?php

declare(strict_types=1);

namespace SimpleKuma\Facebook;

use SimpleKuma\Entity\FacebookMarketingAdAccount;
use SimpleKuma\Entity\FacebookMarketingCampaign;
use SimpleKuma\Entity\FacebookMarketingIntegration;
use SimpleKuma\Http\ProxyHandler;
use mysqli;

/**
 * Sync Meta campaigns for a Kuma-linked ad account (on-demand from campaign editor).
 */
class FacebookCampaignSyncService
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * @return array{success: bool, count?: int, campaigns?: array, error?: string, cached?: bool}
     */
    public function listActive(int $adAccountInternalId): array
    {
        $campaignEntity = new FacebookMarketingCampaign($this->db);
        if (!$campaignEntity->tableExists()) {
            return ['success' => false, 'error' => 'Meta campaign cache not available. Run migration 057.'];
        }

        $rows = $campaignEntity->getByAdAccountId($adAccountInternalId, 'ACTIVE');
        return [
            'success' => true,
            'count' => count($rows),
            'campaigns' => array_map(static function (array $row): array {
                return [
                    'id' => (int)$row['id'],
                    'meta_campaign_id' => $row['meta_campaign_id'],
                    'name' => $row['campaign_name'],
                    'effective_status' => $row['effective_status'],
                ];
            }, $rows),
        ];
    }

    /**
     * @return array{success: bool, count?: int, campaigns?: array, error?: string, cached?: bool}
     */
    public function sync(int $adAccountInternalId, bool $force = false): array
    {
        $campaignEntity = new FacebookMarketingCampaign($this->db);
        if (!$campaignEntity->tableExists()) {
            return ['success' => false, 'error' => 'Meta campaign cache not available. Run migration 057.'];
        }

        if (!$force) {
            $last = $campaignEntity->getLastSyncedAt($adAccountInternalId);
            if ($last !== null && strtotime($last) > time() - 60) {
                $listed = $this->listActive($adAccountInternalId);
                $listed['cached'] = true;
                return $listed;
            }
        }

        $adAccountEntity = new FacebookMarketingAdAccount($this->db);
        $adAccount = $adAccountEntity->getById($adAccountInternalId);
        if (!$adAccount) {
            return ['success' => false, 'error' => 'Ad account not found'];
        }

        $integrationId = (int)$adAccount['facebook_marketing_integration_id'];
        $integrationEntity = new FacebookMarketingIntegration($this->db);
        $integration = $integrationEntity->getById($integrationId);
        if (!$integration || ($integration['status'] ?? '') !== 'active') {
            return ['success' => false, 'error' => 'Facebook Marketing integration not found or inactive'];
        }

        $tracker = new FacebookApiCallTracker($this->db);
        $client = new FacebookInsightsClient(
            $integration['access_token'],
            ProxyHandler::getProxyConfig($integration),
            $tracker,
            $integrationId
        );
        $client->setAdAccountId($adAccount['ad_account_id'] ?? null);

        try {
            $fromApi = $client->fetchAdAccountCampaigns((string)$adAccount['ad_account_id']);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Facebook API error: ' . $e->getMessage()];
        }

        $payload = [];
        foreach ($fromApi as $row) {
            $payload[] = [
                'meta_campaign_id' => $row['id'],
                'campaign_name' => $row['name'],
                'effective_status' => $row['effective_status'],
            ];
        }

        if (!$campaignEntity->syncForAdAccount($adAccountInternalId, $payload)) {
            return ['success' => false, 'error' => 'Failed to save campaigns to database'];
        }

        return $this->listActive($adAccountInternalId);
    }
}
