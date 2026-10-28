<?php

declare(strict_types=1);

namespace SimpleKuma\NewsBreak;

use mysqli;

/**
 * Discovers NewsBreak remote campaign IDs from click tokens (param campaign_id / __CAMPAIGN_ID__).
 */
final class NewsBreakClickExtractor
{
    public function __construct(private mysqli $db)
    {
    }

    /**
     * @return list<int>
     */
    public function newsBreakTrafficSourceIds(): array
    {
        $ids = [];
        $result = $this->db->query(
            "SELECT id FROM traffic_sources
             WHERE LOWER(COALESCE(provider_key, '')) = 'newsbreak'
                OR LOWER(name) = 'newsbreak'
                OR LOWER(name) LIKE '%newsbreak%'"
        );
        if (!$result) {
            return [];
        }
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row['id'];
        }
        return $ids;
    }

    /**
     * Map remote NewsBreak campaign id → list of Kuma campaign ids that saw it recently.
     *
     * @param list<int> $trafficSourceIds
     * @return array<string, list<int>>
     */
    public function remoteToKumaCampaignMap(array $trafficSourceIds, string $utcFrom, string $utcTo): array
    {
        $trafficSourceIds = array_values(array_filter(array_map('intval', $trafficSourceIds), static fn(int $id): bool => $id > 0));
        if ($trafficSourceIds === []) {
            return [];
        }

        $in = implode(',', $trafficSourceIds);
        $remoteSql = $this->remoteCampaignIdSql('c.extra_json');
        $sql = "
            SELECT DISTINCT c.campaign_id AS kuma_campaign_id, {$remoteSql} AS remote_campaign_id
            FROM clicks c
            WHERE c.traffic_source_id IN ({$in})
              AND c.ts >= ?
              AND c.ts <= ?
              AND c.campaign_id IS NOT NULL
            HAVING remote_campaign_id IS NOT NULL
               AND remote_campaign_id != ''
               AND remote_campaign_id != 'null'
               AND remote_campaign_id NOT LIKE '\\_\\_%'
        ";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ss', $utcFrom, $utcTo);
        $stmt->execute();
        $result = $stmt->get_result();
        $map = [];
        while ($row = $result->fetch_assoc()) {
            $remote = trim((string) ($row['remote_campaign_id'] ?? ''));
            $kuma = (int) ($row['kuma_campaign_id'] ?? 0);
            if ($remote === '' || $kuma < 1) {
                continue;
            }
            if (!isset($map[$remote])) {
                $map[$remote] = [];
            }
            if (!in_array($kuma, $map[$remote], true)) {
                $map[$remote][] = $kuma;
            }
        }
        $stmt->close();

        return $map;
    }

    /**
     * Clicks for a Kuma campaign that carried a given remote campaign id in a UTC hour.
     */
    public function countClicksForHour(
        int $kumaCampaignId,
        string $remoteCampaignId,
        string $utcDate,
        int $hour
    ): int {
        $remoteSql = $this->remoteCampaignIdSql('extra_json');
        $sql = "
            SELECT COUNT(*) AS c
            FROM clicks
            WHERE campaign_id = ?
              AND {$remoteSql} = ?
              AND DATE(ts) = ?
              AND HOUR(ts) = ?
        ";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('issi', $kumaCampaignId, $remoteCampaignId, $utcDate, $hour);
        $stmt->execute();
        $c = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
        return $c;
    }

    public function remoteCampaignIdSql(string $extraJsonExpr): string
    {
        return "COALESCE(
            NULLIF(JSON_UNQUOTE(JSON_EXTRACT({$extraJsonExpr}, '$.traffic_source_tokens.campaign_id')), ''),
            NULLIF(JSON_UNQUOTE(JSON_EXTRACT({$extraJsonExpr}, '$.all_params.campaign_id')), '')
        )";
    }
}
