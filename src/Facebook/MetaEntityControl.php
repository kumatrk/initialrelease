<?php

declare(strict_types=1);

namespace SimpleKuma\Facebook;

use mysqli;
use SimpleKuma\Entity\FacebookMarketingIntegration;
use SimpleKuma\Http\ProxyHandler;

/**
 * Pause or activate one mapped Meta ad or ad set.
 * Token "preview" updates the stored status and does not call Graph.
 */
class MetaEntityControl
{
    /**
     * @return array{status: string, preview: bool, name: string, level: string}
     */
    public static function change(
        mysqli $db,
        ?int $userId,
        int $campaignId,
        string $level,
        string $entityId,
        string $desired
    ): array {
        $level = $level === 'ad' ? 'ad' : ($level === 'adset' ? 'adset' : '');
        $desired = strtoupper($desired);
        if ($campaignId <= 0 || $level === '' || !ctype_digit($entityId) || !in_array($desired, ['PAUSED', 'ACTIVE'], true)) {
            throw new \InvalidArgumentException('Choose an ad or ad set to pause or activate.');
        }

        $link = self::campaignLink($db, $campaignId);
        if ($link === null) {
            throw new \RuntimeException('This campaign is not linked to a Meta campaign.');
        }

        $current = self::statusRow($db, $link['integration_id'], $link['meta_campaign_id'], $level, $entityId);
        if ($current === null) {
            throw new \RuntimeException('That ad is not on this Meta campaign.');
        }

        $from = $current['effective_status'];
        if (!in_array($from, ['ACTIVE', 'PAUSED'], true)) {
            throw new \RuntimeException('Kuma can only pause or activate an ad that is active or paused.');
        }
        if ($from === $desired) {
            return [
                'status' => $from,
                'preview' => false,
                'name' => $current['entity_name'],
                'level' => $level,
            ];
        }

        $token = trim($link['access_token']);
        $preview = $token === 'preview';
        if ($preview) {
            $stored = $desired;
        } else {
            if ($token === '') {
                self::audit($db, $userId, $campaignId, $level, $entityId, $from, $from, 'error');
                throw new \RuntimeException('This Meta account has no access token.');
            }
            try {
                $stored = self::pushStatus($db, $token, $entityId, $desired, $link['integration_id'], $link['ad_account_id']);
            } catch (\RuntimeException $e) {
                self::audit($db, $userId, $campaignId, $level, $entityId, $from, $from, 'error');
                throw $e;
            }
        }

        self::writeStatus($db, $link['integration_id'], $level, $entityId, $current['entity_name'], $link['meta_campaign_id'], $stored);
        self::audit($db, $userId, $campaignId, $level, $entityId, $from, $stored, $preview ? 'preview' : 'ok');

        return [
            'status' => $stored,
            'preview' => $preview,
            'name' => $current['entity_name'],
            'level' => $level,
        ];
    }

    /**
     * @return array{integration_id: int, meta_campaign_id: string, ad_account_id: string, access_token: string}|null
     */
    private static function campaignLink(mysqli $db, int $campaignId): ?array
    {
        $stmt = $db->prepare(
            'SELECT faa.facebook_marketing_integration_id AS integration_id,
                    faa.ad_account_id,
                    fmc.meta_campaign_id,
                    fmi.access_token
             FROM campaigns c
             INNER JOIN facebook_marketing_campaigns fmc ON fmc.id = c.facebook_marketing_campaign_id
             INNER JOIN facebook_marketing_ad_accounts faa ON faa.id = fmc.facebook_marketing_ad_account_id
             INNER JOIN facebook_marketing_integrations fmi ON fmi.id = faa.facebook_marketing_integration_id
             WHERE c.id = ?'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $campaignId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }
        $metaId = (string)($row['meta_campaign_id'] ?? '');
        $integrationId = (int)($row['integration_id'] ?? 0);
        if ($integrationId <= 0 || !ctype_digit($metaId)) {
            return null;
        }
        return [
            'integration_id' => $integrationId,
            'meta_campaign_id' => $metaId,
            'ad_account_id' => (string)($row['ad_account_id'] ?? ''),
            'access_token' => (string)($row['access_token'] ?? ''),
        ];
    }

    /**
     * @return array{entity_name: string, effective_status: string}|null
     */
    private static function statusRow(
        mysqli $db,
        int $integrationId,
        string $metaCampaignId,
        string $level,
        string $entityId
    ): ?array {
        $stmt = $db->prepare(
            'SELECT entity_name, effective_status
             FROM meta_entity_status
             WHERE ad_account_id = ? AND entity_level = ? AND entity_id = ? AND meta_campaign_id = ?'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('isss', $integrationId, $level, $entityId, $metaCampaignId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }
        return [
            'entity_name' => (string)$row['entity_name'],
            'effective_status' => strtoupper((string)$row['effective_status']),
        ];
    }

    private static function writeStatus(
        mysqli $db,
        int $integrationId,
        string $level,
        string $entityId,
        string $name,
        string $metaCampaignId,
        string $status
    ): void {
        $stmt = $db->prepare(
            'UPDATE meta_entity_status
             SET effective_status = ?, updated_at = CURRENT_TIMESTAMP
             WHERE ad_account_id = ? AND entity_level = ? AND entity_id = ? AND meta_campaign_id = ?'
        );
        if ($stmt === false) {
            throw new \RuntimeException('The new status could not be saved.');
        }
        $stmt->bind_param('sisss', $status, $integrationId, $level, $entityId, $metaCampaignId);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            throw new \RuntimeException('The new status could not be saved.');
        }
    }

    private static function pushStatus(
        mysqli $db,
        string $token,
        string $entityId,
        string $desired,
        int $integrationId,
        string $adAccountId
    ): string {
        $client = new FacebookInsightsClient(
            $token,
            self::proxyConfig($db, $integrationId),
            new FacebookApiCallTracker($db),
            $integrationId
        );
        if ($adAccountId !== '') {
            $client->setAdAccountId($adAccountId);
        }
        $result = $client->updateEntityStatus($entityId, $desired);
        if (!$result['ok'] || $result['effective_status'] === '') {
            throw new \RuntimeException($result['error'] !== '' ? $result['error'] : 'Meta did not accept that change.');
        }
        return $result['effective_status'];
    }

    private static function proxyConfig(mysqli $db, int $integrationId): ?array
    {
        $row = (new FacebookMarketingIntegration($db))->getById($integrationId, true);
        if (!$row) {
            return null;
        }
        return ProxyHandler::getProxyConfig($row);
    }

    private static function audit(
        mysqli $db,
        ?int $userId,
        int $campaignId,
        string $level,
        string $entityId,
        string $from,
        string $to,
        string $result
    ): void {
        $stmt = $db->prepare(
            'INSERT INTO meta_entity_actions
                (user_id, campaign_id, entity_level, entity_id, from_status, to_status, result)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('iisssss', $userId, $campaignId, $level, $entityId, $from, $to, $result);
        $stmt->execute();
        $stmt->close();
    }
}
