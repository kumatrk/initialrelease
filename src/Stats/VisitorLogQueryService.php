<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

use mysqli;

/**
 * Visitor Log page queries — count and page clicks without a conversions fan-out
 * or SELECT cl.* over the whole date range (those time out on Last month).
 */
final class VisitorLogQueryService
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * @param array{
     *   utc_from: string,
     *   utc_to: string,
     *   campaign_id?: int|null,
     *   has_conversion?: string|int|null,
     *   exclude_fb_approval?: bool,
     *   page?: int,
     *   per_page?: int
     * } $opts
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function list(array $opts): array
    {
        $utcFrom = (string) $opts['utc_from'];
        $utcTo = (string) $opts['utc_to'];
        $campaignId = isset($opts['campaign_id']) ? (int) $opts['campaign_id'] : 0;
        $hasConversion = $opts['has_conversion'] ?? null;
        $excludeFb = !empty($opts['exclude_fb_approval']);
        $page = max(1, (int) ($opts['page'] ?? 1));
        $perPage = max(1, min(200, (int) ($opts['per_page'] ?? 50)));
        $offset = ($page - 1) * $perPage;

        $clickedOnly = ($hasConversion === 'clicked' || $hasConversion === 2);
        $conversionOnly = ($hasConversion === '1' || $hasConversion === 1);
        $filter = $this->buildFilter($utcFrom, $utcTo, $campaignId, $clickedOnly, $excludeFb);
        $total = $conversionOnly
            ? $this->countConvertedClicks($filter)
            : $this->countClicks($filter);
        if ($total === 0) {
            return ['rows' => [], 'total' => 0];
        }

        $ids = $conversionOnly
            ? $this->pageConvertedIds($filter, $perPage, $offset)
            : $this->pageIds($filter, $perPage, $offset);
        if ($ids === []) {
            return ['rows' => [], 'total' => $total];
        }

        return ['rows' => $this->hydrateRows($ids), 'total' => $total];
    }

    /**
     * @return array{sql: string, types: string, params: list<mixed>, force: string}
     */
    private function buildFilter(
        string $utcFrom,
        string $utcTo,
        int $campaignId,
        bool $clickedOnly,
        bool $excludeFb
    ): array {
        $where = ['cl.ts >= ?', 'cl.ts <= ?'];
        $params = [$utcFrom, $utcTo];
        $types = 'ss';

        if ($campaignId > 0) {
            $where[] = 'cl.campaign_id = ?';
            $params[] = $campaignId;
            $types .= 'i';
        }

        if ($clickedOnly) {
            $where[] = 'cl.lp_click = 1';
        }

        $useExclude = false;
        if ($excludeFb && StatsExclusionFlag::columnExists($this->db)) {
            $useExclude = $this->rangeHasExcludedClicks($utcFrom, $utcTo, $campaignId);
            if ($useExclude) {
                $where[] = 'cl.exclude_from_stats = 0';
            }
        } elseif ($excludeFb) {
            $where[] = CampaignStatsExpressions::excludeInvalidClickWhere('cl');
        }

        try {
            $hiddenIpSql = (new StatsHiddenIpService($this->db))->exclusionSql('cl');
            if ($hiddenIpSql !== '') {
                $where[] = $hiddenIpSql;
            }
        } catch (\Throwable $e) {
            // ignore if migration not applied
        }

        // Cover starts with exclude_from_stats, then ts, then lp_click — usable for
        // Last-month COUNT/page. idx_clicks_ts + lp_click (or exclude) forces fat row lookups.
        $force = $this->indexExists('idx_clicks_ts_stats_cover')
            ? ' FORCE INDEX (idx_clicks_ts_stats_cover)'
            : ($this->indexExists('idx_clicks_ts') ? ' FORCE INDEX (idx_clicks_ts)' : '');

        return [
            'sql' => implode(' AND ', $where),
            'types' => $types,
            'params' => $params,
            'force' => $force,
        ];
    }

    /**
     * @param array{sql: string, types: string, params: list<mixed>, force: string} $filter
     */
    private function countClicks(array $filter): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS total FROM clicks cl{$filter['force']} WHERE {$filter['sql']}"
        );
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param($filter['types'], ...$filter['params']);
        $stmt->execute();
        $total = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        return $total;
    }

    /**
     * Start from conversions so Last month + “has conversion” does not scan 100k clicks.
     *
     * @param array{sql: string, types: string, params: list<mixed>, force: string} $filter
     */
    private function countConvertedClicks(array $filter): int
    {
        $clickJoin = $this->indexExists('idx_clicks_click_id_cover_stats')
            ? 'clicks cl FORCE INDEX (idx_clicks_click_id_cover_stats)'
            : 'clicks cl';
        $stmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT cl.id) AS total
             FROM conversions conv
             INNER JOIN {$clickJoin} ON cl.click_id = conv.click_id
             WHERE {$filter['sql']}"
        );
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param($filter['types'], ...$filter['params']);
        $stmt->execute();
        $total = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        return $total;
    }

    /**
     * @param array{sql: string, types: string, params: list<mixed>, force: string} $filter
     * @return list<int>
     */
    private function pageConvertedIds(array $filter, int $limit, int $offset): array
    {
        $clickJoin = $this->indexExists('idx_clicks_click_id_cover_stats')
            ? 'clicks cl FORCE INDEX (idx_clicks_click_id_cover_stats)'
            : 'clicks cl';
        $stmt = $this->db->prepare(
            "SELECT cl.id
             FROM conversions conv
             INNER JOIN {$clickJoin} ON cl.click_id = conv.click_id
             WHERE {$filter['sql']}
             GROUP BY cl.id
             ORDER BY MAX(cl.ts) DESC
             LIMIT ? OFFSET ?"
        );
        if ($stmt === false) {
            return [];
        }
        $types = $filter['types'] . 'ii';
        $params = array_merge($filter['params'], [$limit, $offset]);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row['id'];
        }
        $stmt->close();

        return $ids;
    }

    /**
     * @param array{sql: string, types: string, params: list<mixed>, force: string} $filter
     * @return list<int>
     */
    private function pageIds(array $filter, int $limit, int $offset): array
    {
        $stmt = $this->db->prepare(
            "SELECT cl.id FROM clicks cl{$filter['force']}
             WHERE {$filter['sql']}
             ORDER BY cl.ts DESC
             LIMIT ? OFFSET ?"
        );
        if ($stmt === false) {
            return [];
        }
        $types = $filter['types'] . 'ii';
        $params = array_merge($filter['params'], [$limit, $offset]);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row['id'];
        }
        $stmt->close();

        return $ids;
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private function hydrateRows(array $ids): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $sql = "SELECT
                cl.id,
                cl.click_id,
                cl.ts,
                cl.ip,
                cl.city,
                cl.region,
                cl.device,
                cl.device_brand,
                cl.device_model,
                cl.os,
                cl.os_version,
                cl.browser,
                cl.browser_version,
                cl.lp_click,
                cl.extra_json,
                cp.name AS campaign_name,
                COALESCE(ts.name, cp_ts.name) AS traffic_source_name,
                o.name AS offer_name,
                lp.name AS landing_page_name,
                conv.value AS conv_value,
                conv.payout AS conv_payout,
                conv.currency AS conv_currency,
                conv.id AS has_conversion
            FROM clicks cl
            INNER JOIN campaigns cp ON cl.campaign_id = cp.id
            LEFT JOIN traffic_sources cp_ts ON cp.traffic_source_id = cp_ts.id
            LEFT JOIN traffic_sources ts ON cl.traffic_source_id = ts.id
            LEFT JOIN offers o ON cl.offer_id = o.id
            LEFT JOIN landing_pages lp ON cl.landing_page_id = lp.id
            LEFT JOIN conversions conv ON conv.id = (
                SELECT c2.id FROM conversions c2
                WHERE c2.click_id = cl.click_id
                ORDER BY c2.id DESC
                LIMIT 1
            )
            WHERE cl.id IN ({$placeholders})
            ORDER BY cl.ts DESC";

        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        $seen = [];
        while ($row = $result->fetch_assoc()) {
            $id = (int) $row['id'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    private function rangeHasExcludedClicks(string $utcFrom, string $utcTo, int $campaignId): bool
    {
        $force = $this->indexExists('idx_clicks_ts_stats_cover')
            ? ' FORCE INDEX (idx_clicks_ts_stats_cover)'
            : '';
        $sql = "SELECT 1 FROM clicks cl{$force}
                WHERE cl.exclude_from_stats = 1 AND cl.ts >= ? AND cl.ts <= ?";
        $types = 'ss';
        $params = [$utcFrom, $utcTo];
        if ($campaignId > 0) {
            $sql .= ' AND cl.campaign_id = ?';
            $types .= 'i';
            $params[] = $campaignId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            return true;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $has = $stmt->get_result()->fetch_assoc() !== null;
        $stmt->close();

        return $has;
    }

    private function indexExists(string $indexName): bool
    {
        static $cache = [];
        if (array_key_exists($indexName, $cache)) {
            return $cache[$indexName];
        }
        $stmt = $this->db->prepare(
            "SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clicks' AND INDEX_NAME = ?
             LIMIT 1"
        );
        if ($stmt === false) {
            return $cache[$indexName] = false;
        }
        $stmt->bind_param('s', $indexName);
        $stmt->execute();
        $cache[$indexName] = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();

        return $cache[$indexName];
    }
}
