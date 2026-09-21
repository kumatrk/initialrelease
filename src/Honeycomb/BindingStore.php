<?php

declare(strict_types=1);

namespace SimpleKuma\Honeycomb;

use mysqli;
use RuntimeException;

/**
 * Links Kuma campaigns to remote addon account/campaign IDs (campaign_addon_bindings).
 */
final class BindingStore
{
    public function __construct(private mysqli $db)
    {
    }

    public function tableExists(): bool
    {
        $result = $this->db->query("SHOW TABLES LIKE 'campaign_addon_bindings'");
        return $result !== false && $result->num_rows > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getForCampaignAddon(int $campaignId, string $addonSlug): ?array
    {
        if (!$this->tableExists() || $campaignId < 1) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT id, campaign_id, addon_slug, remote_account_id, remote_campaign_id, extra_json, created_at, updated_at
             FROM campaign_addon_bindings
             WHERE campaign_id = ? AND addon_slug = ?
             LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('is', $campaignId, $addonSlug);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($row !== null && isset($row['extra_json']) && is_string($row['extra_json'])) {
            $decoded = json_decode($row['extra_json'], true);
            $row['extra'] = is_array($decoded) ? $decoded : [];
        }
        return $row;
    }

    /**
     * @param list<int> $campaignIds
     * @return list<array<string, mixed>>
     */
    public function listForCampaigns(array $campaignIds, ?string $addonSlug = null): array
    {
        $campaignIds = array_values(array_unique(array_filter(array_map('intval', $campaignIds))));
        if (!$this->tableExists() || $campaignIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($campaignIds), '?'));
        $sql = "SELECT id, campaign_id, addon_slug, remote_account_id, remote_campaign_id, extra_json
                FROM campaign_addon_bindings
                WHERE campaign_id IN ({$placeholders})";
        $types = str_repeat('i', count($campaignIds));
        $params = $campaignIds;
        if ($addonSlug !== null && $addonSlug !== '') {
            $sql .= ' AND addon_slug = ?';
            $types .= 's';
            $params[] = $addonSlug;
        }
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByAddon(string $addonSlug): array
    {
        if (!$this->tableExists()) {
            return [];
        }
        $stmt = $this->db->prepare(
            'SELECT id, campaign_id, addon_slug, remote_account_id, remote_campaign_id, extra_json
             FROM campaign_addon_bindings
             WHERE addon_slug = ?
             ORDER BY campaign_id ASC'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('s', $addonSlug);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        return $rows;
    }

    /**
     * @param array<string, mixed>|null $extra
     */
    public function upsert(
        int $campaignId,
        string $addonSlug,
        ?string $remoteAccountId,
        ?string $remoteCampaignId,
        ?array $extra = null
    ): bool {
        $this->assertTable();
        if ($campaignId < 1 || $addonSlug === '') {
            return false;
        }

        $remoteAccountId = $remoteAccountId !== null ? trim($remoteAccountId) : null;
        $remoteCampaignId = $remoteCampaignId !== null ? trim($remoteCampaignId) : null;
        $exportEnabled = is_array($extra) && !empty($extra['conversion_export']);
        if ($remoteAccountId === '' && $remoteCampaignId === '' && !$exportEnabled) {
            return $this->delete($campaignId, $addonSlug);
        }
        if ($remoteAccountId === '') {
            $remoteAccountId = null;
        }
        if ($remoteCampaignId === '') {
            $remoteCampaignId = null;
        }

        $extraJson = $extra !== null ? (json_encode($extra, JSON_UNESCAPED_SLASHES) ?: null) : null;
        $existing = $this->getForCampaignAddon($campaignId, $addonSlug);
        if ($existing !== null) {
            $stmt = $this->db->prepare(
                'UPDATE campaign_addon_bindings
                 SET remote_account_id = ?, remote_campaign_id = ?, extra_json = ?
                 WHERE campaign_id = ? AND addon_slug = ?'
            );
            if ($stmt === false) {
                return false;
            }
            $stmt->bind_param(
                'sssis',
                $remoteAccountId,
                $remoteCampaignId,
                $extraJson,
                $campaignId,
                $addonSlug
            );
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO campaign_addon_bindings
                (campaign_id, addon_slug, remote_account_id, remote_campaign_id, extra_json)
             VALUES (?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param(
            'issss',
            $campaignId,
            $addonSlug,
            $remoteAccountId,
            $remoteCampaignId,
            $extraJson
        );
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function delete(int $campaignId, string $addonSlug): bool
    {
        if (!$this->tableExists()) {
            return false;
        }
        $stmt = $this->db->prepare(
            'DELETE FROM campaign_addon_bindings WHERE campaign_id = ? AND addon_slug = ?'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('is', $campaignId, $addonSlug);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function campaignHasBinding(int $campaignId): bool
    {
        if (!$this->tableExists() || $campaignId < 1) {
            return false;
        }
        $stmt = $this->db->prepare(
            'SELECT 1 FROM campaign_addon_bindings WHERE campaign_id = ? LIMIT 1'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('i', $campaignId);
        $stmt->execute();
        $ok = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
        return $ok;
    }

    private function assertTable(): void
    {
        if (!$this->tableExists()) {
            throw new RuntimeException('campaign_addon_bindings table is missing. Run database migrations.');
        }
    }
}
