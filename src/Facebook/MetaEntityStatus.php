<?php

declare(strict_types=1);

namespace SimpleKuma\Facebook;

use mysqli;
use SimpleKuma\Stats\CampaignStatsExpressions;

/**
 * Stored Meta ad and ad set status. The stats page reads this table and does not call Graph.
 */
class MetaEntityStatus
{
    /**
     * @param list<array<string, mixed>> $rows
     */
    public static function saveRows(mysqli $db, int $integrationId, array $rows): void
    {
        if ($integrationId <= 0 || $rows === []) {
            return;
        }

        $stmt = $db->prepare(
            'INSERT INTO meta_entity_status
                (ad_account_id, entity_level, entity_id, entity_name, meta_campaign_id, effective_status)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                entity_name = VALUES(entity_name),
                meta_campaign_id = VALUES(meta_campaign_id),
                effective_status = VALUES(effective_status),
                updated_at = CURRENT_TIMESTAMP'
        );
        if ($stmt === false) {
            return;
        }

        foreach ($rows as $row) {
            $level = (string)($row['entity_level'] ?? '');
            $entityId = (string)($row['entity_id'] ?? '');
            $metaCampaignId = (string)($row['meta_campaign_id'] ?? '');
            $status = strtoupper(substr((string)($row['effective_status'] ?? ''), 0, 32));
            $name = mb_substr(trim((string)($row['entity_name'] ?? '')), 0, 512);
            if (!in_array($level, ['ad', 'adset'], true) || !ctype_digit($entityId) || !ctype_digit($metaCampaignId) || $status === '') {
                continue;
            }
            $stmt->bind_param('isssss', $integrationId, $level, $entityId, $name, $metaCampaignId, $status);
            $stmt->execute();
        }
        $stmt->close();
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{rows: list<array<string, mixed>>, enabled: bool}
     */
    public static function attach(mysqli $db, ?array $campaign, string $dimension, array $rows): array
    {
        $level = self::levelForDimension($dimension);
        if ($level === null || $campaign === null) {
            return ['rows' => $rows, 'enabled' => false];
        }

        $metaCampaignId = self::metaCampaignId($db, $campaign);
        if ($metaCampaignId === null) {
            return ['rows' => $rows, 'enabled' => false];
        }

        $stored = self::rowsForCampaign($db, $metaCampaignId, $level);
        $byId = [];
        $byName = [];
        foreach ($stored as $storedRow) {
            $byId[$storedRow['entity_id']] = $storedRow;
            $nameKey = mb_strtolower($storedRow['entity_name']);
            if ($nameKey === '') {
                continue;
            }
            if (array_key_exists($nameKey, $byName)) {
                $byName[$nameKey] = null;
            } else {
                $byName[$nameKey] = $storedRow;
            }
        }

        $matchById = in_array(CampaignStatsExpressions::unwrapDimensionKey($dimension), ['ad_id', 'adset_id'], true);
        foreach ($rows as &$row) {
            unset($row['meta_entity_id'], $row['meta_entity_level'], $row['meta_status']);
            $group = trim((string)($row['name'] ?? $row['group'] ?? ''));
            $match = null;
            if ($matchById && ctype_digit($group) && isset($byId[$group])) {
                $match = $byId[$group];
            } elseif (!$matchById) {
                $match = $byName[mb_strtolower($group)] ?? null;
            }
            if ($match === null) {
                continue;
            }
            $row['meta_entity_id'] = $match['entity_id'];
            $row['meta_entity_level'] = $level;
            $row['meta_status'] = $match['effective_status'];
        }
        unset($row);

        return ['rows' => $rows, 'enabled' => true];
    }

    public static function levelForDimension(string $dimension): ?string
    {
        $key = CampaignStatsExpressions::unwrapDimensionKey($dimension);
        if (in_array($key, ['ad_name', 'ad_id'], true)) {
            return 'ad';
        }
        if (in_array($key, ['adset_name', 'adset_id'], true)) {
            return 'adset';
        }
        return null;
    }

    public static function metaCampaignId(mysqli $db, array $campaign): ?string
    {
        $localId = (int)($campaign['facebook_marketing_campaign_id'] ?? 0);
        if ($localId <= 0) {
            return null;
        }
        $stmt = $db->prepare('SELECT meta_campaign_id FROM facebook_marketing_campaigns WHERE id = ?');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $localId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $metaId = (string)($row['meta_campaign_id'] ?? '');
        return ctype_digit($metaId) ? $metaId : null;
    }

    /**
     * @return list<array{entity_id: string, entity_name: string, effective_status: string}>
     */
    private static function rowsForCampaign(mysqli $db, string $metaCampaignId, string $level): array
    {
        $stmt = $db->prepare(
            'SELECT entity_id, entity_name, effective_status
             FROM meta_entity_status
             WHERE meta_campaign_id = ? AND entity_level = ?'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('ss', $metaCampaignId, $level);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'entity_id' => (string)$row['entity_id'],
                'entity_name' => trim((string)$row['entity_name']),
                'effective_status' => strtoupper((string)$row['effective_status']),
            ];
        }
        $stmt->close();
        return $rows;
    }
}
