<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

use mysqli;
use SimpleKuma\Database\ClicksTableResolver;
use SimpleKuma\Tracking\ConversionOptInClassifier;
use SimpleKuma\Tracking\ConversionPostbackParams;
use SimpleKuma\Utils\Formatter;

class ConversionsQueryService
{
    private mysqli $db;

    /** @var bool|null */
    private ?bool $trafficSourceColumnExists = null;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * SQL select fragments + aliases for conversion source_json report keys.
     *
     * @return array{selects: list<string>, aliases: list<string>}
     */
    private function extraSelectFragments(): array
    {
        $selects = [];
        $aliases = [];
        foreach (ConversionPostbackParams::reportKeys() as $key) {
            $alias = 'pb_' . $key;
            $path = '$.' . $key;
            $selects[] = "JSON_UNQUOTE(JSON_EXTRACT(conv.source_json, '{$path}')) AS {$alias}";
            $aliases[] = $alias;
        }

        return ['selects' => $selects, 'aliases' => $aliases];
    }

    /**
     * @param 'all'|'optins'|'conversions' $eventTypeFilter
     * @param array<string, string> $extraFilters source_json key => value (from ConversionPostbackParams::parseLogFilters)
     * @return array{where: string, params: list<mixed>, types: string}
     */
    private function buildLogWhere(
        ?int $campaignId,
        string $dateFrom,
        string $dateTo,
        string $timezone,
        string $eventTypeFilter = 'all',
        array $extraFilters = []
    ): array {
        $utcRange = Formatter::convertDateRangeToUTC($dateFrom, $dateTo, $timezone);

        $where = ['conv.ts >= ?', 'conv.ts <= ?'];
        $params = [$utcRange['from'], $utcRange['to']];
        $types = 'ss';

        if ($campaignId !== null && $campaignId > 0) {
            $where[] = 'cl.campaign_id = ?';
            $params[] = $campaignId;
            $types .= 'i';
        }

        $optInList = ConversionOptInClassifier::sqlInList();
        if ($eventTypeFilter === 'optins') {
            $where[] = "LOWER(COALESCE(conv.event_key, '')) IN ({$optInList})";
        } elseif ($eventTypeFilter === 'conversions') {
            $where[] = "(conv.event_key IS NULL OR LOWER(conv.event_key) NOT IN ({$optInList}))";
        }

        $allowed = array_fill_keys(ConversionPostbackParams::reportKeys(), true);
        foreach ($extraFilters as $key => $value) {
            if (!isset($allowed[$key]) || $value === '') {
                continue;
            }
            // Escape key for JSON path (report keys are fixed alphanumeric)
            if (preg_match('/^[a-z0-9_]+$/', $key) !== 1) {
                continue;
            }
            $where[] = "JSON_UNQUOTE(JSON_EXTRACT(conv.source_json, '$.{$key}')) = ?";
            $params[] = $value;
            $types .= 's';
        }

        return [
            'where' => implode(' AND ', $where),
            'params' => $params,
            'types' => $types,
        ];
    }

    private function trafficSourceColumnExists(): bool
    {
        if ($this->trafficSourceColumnExists !== null) {
            return $this->trafficSourceColumnExists;
        }

        $checkColumn = $this->db->query("SELECT COUNT(*) AS count FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'clicks'
            AND COLUMN_NAME = 'traffic_source_id'");

        $exists = false;
        if ($checkColumn && ($row = $checkColumn->fetch_assoc())) {
            $exists = ((int) $row['count']) > 0;
        }

        $this->trafficSourceColumnExists = $exists;
        return $exists;
    }

    /**
     * @return array{from: string, joins: string, trafficSourceSelect: string}
     */
    private function buildLogFromClause(): array
    {
        $clicksTable = ClicksTableResolver::getStatsTable($this->db);
        $hasTrafficSourceCol = $this->trafficSourceColumnExists();

        $trafficSourceSelect = $hasTrafficSourceCol
            ? 'COALESCE(ts.name, cp_ts.name) AS traffic_source_name'
            : 'cp_ts.name AS traffic_source_name';

        $joins = "
            INNER JOIN {$clicksTable} cl ON conv.click_id = cl.click_id
            LEFT JOIN campaigns cp ON cl.campaign_id = cp.id
            LEFT JOIN traffic_sources cp_ts ON cp.traffic_source_id = cp_ts.id
            LEFT JOIN offers o ON cl.offer_id = o.id
            LEFT JOIN landing_pages lp ON cl.landing_page_id = lp.id";

        if ($hasTrafficSourceCol) {
            $joins .= "
            LEFT JOIN traffic_sources ts ON cl.traffic_source_id = ts.id";
        }

        return [
            'from' => 'conversions conv',
            'joins' => $joins,
            'trafficSourceSelect' => $trafficSourceSelect,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapLogRow(array $row): array
    {
        $payout = isset($row['payout']) && $row['payout'] !== null ? (float) $row['payout'] : null;
        $value = isset($row['value']) && $row['value'] !== null ? (float) $row['value'] : null;

        $mapped = [
            'id' => (int) $row['id'],
            'click_id' => $row['click_id'],
            'txid' => $row['txid'],
            'event_id' => $row['event_id'],
            'event_key' => $row['event_key'] ?? null,
            'status' => $row['status'],
            'currency' => $row['currency'],
            'ts' => $row['ts'],
            'payout' => $payout,
            'value' => $value,
            'revenue' => (float) ($payout ?? $value ?? 0),
            'source' => $row['source'] ?? null,
            'campaign_id' => isset($row['campaign_id']) ? (int) $row['campaign_id'] : null,
            'campaign_name' => $row['campaign_name'],
            'offer_id' => isset($row['offer_id']) ? (int) $row['offer_id'] : null,
            'offer_name' => $row['offer_name'],
            'landing_page_name' => $row['landing_page_name'],
            'traffic_source_name' => $row['traffic_source_name'],
            'country' => $row['country'],
            'region' => $row['region'],
            'city' => $row['city'],
            'ip' => $row['ip'],
        ];

        foreach (ConversionPostbackParams::reportKeys() as $key) {
            $alias = 'pb_' . $key;
            $raw = $row[$alias] ?? null;
            if ($raw === null || $raw === '' || $raw === 'null') {
                $mapped[$alias] = null;
            } else {
                $mapped[$alias] = (string) $raw;
            }
        }

        return $mapped;
    }

    /**
     * Whether any row in the set has a non-empty value for this pb_* column (for optional UI columns).
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function rowsHaveExtra(array $rows, string $reportKey): bool
    {
        $alias = 'pb_' . $reportKey;
        foreach ($rows as $row) {
            if (!empty($row[$alias])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param 'all'|'optins'|'conversions' $eventTypeFilter
     * @param array<string, string> $extraFilters
     * @return array{rows: list<array<string, mixed>>, total: int, total_revenue: float}
     */
    public function listConversionsForLog(
        ?int $campaignId,
        string $dateFrom,
        string $dateTo,
        string $timezone,
        int $page,
        int $perPage,
        ?int $limit = null,
        ?int $offset = null,
        string $eventTypeFilter = 'all',
        array $extraFilters = []
    ): array {
        if (!in_array($eventTypeFilter, ['all', 'optins', 'conversions'], true)) {
            $eventTypeFilter = 'all';
        }
        $filter = $this->buildLogWhere(
            $campaignId,
            $dateFrom,
            $dateTo,
            $timezone,
            $eventTypeFilter,
            $extraFilters
        );
        $clause = $this->buildLogFromClause();
        $extraSql = $this->extraSelectFragments();

        $perPage = min(max(1, $perPage), 500);
        $page = max(1, $page);
        $useOffset = $offset ?? max(0, ($page - 1) * $perPage);
        $useLimit = $limit ?? $perPage;

        $countSql = "
            SELECT COUNT(*) AS cnt,
                   COALESCE(SUM(COALESCE(conv.payout, conv.value, 0)), 0) AS total_revenue
            FROM {$clause['from']}
            {$clause['joins']}
            WHERE {$filter['where']}
        ";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->bind_param($filter['types'], ...$filter['params']);
        $countStmt->execute();
        $countRow = $countStmt->get_result()->fetch_assoc() ?: ['cnt' => 0, 'total_revenue' => 0];
        $total = (int) ($countRow['cnt'] ?? 0);
        $totalRevenue = (float) ($countRow['total_revenue'] ?? 0);

        $extraSelectSql = $extraSql['selects'] !== []
            ? ",\n                   " . implode(",\n                   ", $extraSql['selects'])
            : '';

        $selectSql = "
            SELECT conv.id, conv.click_id, conv.txid, conv.event_id, conv.event_key, conv.value, conv.currency,
                   conv.status, conv.ts, conv.payout,
                   JSON_UNQUOTE(JSON_EXTRACT(conv.source_json, '$.source')) AS source,
                   cl.campaign_id, cl.offer_id, cl.country, cl.region, cl.city, cl.ip,
                   cp.name AS campaign_name,
                   o.name AS offer_name,
                   lp.name AS landing_page_name,
                   {$clause['trafficSourceSelect']}{$extraSelectSql}
            FROM {$clause['from']}
            {$clause['joins']}
            WHERE {$filter['where']}
            ORDER BY conv.ts DESC
            LIMIT ? OFFSET ?
        ";

        $params = array_merge($filter['params'], [$useLimit, $useOffset]);
        $types = $filter['types'] . 'ii';

        $stmt = $this->db->prepare($selectSql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $this->mapLogRow($row);
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'total_revenue' => $totalRevenue,
        ];
    }

    /**
     * Conversion-first cohort: same filters as the log, then enrich each row with click fields
     * for the matching click_id only (does not scan all campaign clicks).
     *
     * @param 'all'|'optins'|'conversions' $eventTypeFilter
     * @param array<string, string> $extraFilters
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   total: int,
     *   total_revenue: float,
     *   truncated: bool,
     *   max_rows: int,
     *   click_token_keys: list<string>
     * }
     */
    public function listConversionCohort(
        ?int $campaignId,
        string $dateFrom,
        string $dateTo,
        string $timezone,
        string $eventTypeFilter = 'all',
        array $extraFilters = [],
        int $maxRows = 500
    ): array {
        if (!in_array($eventTypeFilter, ['all', 'optins', 'conversions'], true)) {
            $eventTypeFilter = 'all';
        }
        $maxRows = min(max(1, $maxRows), 1000);

        $filter = $this->buildLogWhere(
            $campaignId,
            $dateFrom,
            $dateTo,
            $timezone,
            $eventTypeFilter,
            $extraFilters
        );
        $clause = $this->buildLogFromClause();
        $extraSql = $this->extraSelectFragments();

        $countSql = "
            SELECT COUNT(*) AS cnt,
                   COALESCE(SUM(COALESCE(conv.payout, conv.value, 0)), 0) AS total_revenue
            FROM {$clause['from']}
            {$clause['joins']}
            WHERE {$filter['where']}
        ";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->bind_param($filter['types'], ...$filter['params']);
        $countStmt->execute();
        $countRow = $countStmt->get_result()->fetch_assoc() ?: ['cnt' => 0, 'total_revenue' => 0];
        $total = (int) ($countRow['cnt'] ?? 0);
        $totalRevenue = (float) ($countRow['total_revenue'] ?? 0);

        $extraSelectSql = $extraSql['selects'] !== []
            ? ",\n                   " . implode(",\n                   ", $extraSql['selects'])
            : '';

        $selectSql = "
            SELECT conv.id, conv.click_id, conv.txid, conv.event_id, conv.event_key, conv.value, conv.currency,
                   conv.status, conv.ts, conv.payout,
                   JSON_UNQUOTE(JSON_EXTRACT(conv.source_json, '$.source')) AS source,
                   cl.campaign_id, cl.offer_id, cl.country, cl.region, cl.city, cl.ip,
                   cl.device, cl.os, cl.browser, cl.cost, cl.lp_click, cl.extra_json AS click_extra_json,
                   cp.name AS campaign_name,
                   o.name AS offer_name,
                   lp.name AS landing_page_name,
                   {$clause['trafficSourceSelect']}{$extraSelectSql}
            FROM {$clause['from']}
            {$clause['joins']}
            WHERE {$filter['where']}
            ORDER BY conv.ts DESC
            LIMIT ?
        ";

        $params = array_merge($filter['params'], [$maxRows]);
        $types = $filter['types'] . 'i';

        $stmt = $this->db->prepare($selectSql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        $tokenKeySet = [];
        while ($row = $result->fetch_assoc()) {
            $mapped = $this->mapLogRow($row);
            $mapped['click_device'] = $row['device'] ?? null;
            $mapped['click_os'] = $row['os'] ?? null;
            $mapped['click_browser'] = $row['browser'] ?? null;
            $mapped['click_cost'] = isset($row['cost']) && $row['cost'] !== null ? (float) $row['cost'] : null;
            $mapped['click_lp_click'] = isset($row['lp_click']) ? (int) $row['lp_click'] : null;

            $tokens = self::extractClickTokens($row['click_extra_json'] ?? null);
            $mapped['click_tokens'] = $tokens;
            foreach (array_keys($tokens) as $tk) {
                $tokenKeySet[$tk] = true;
            }
            $rows[] = $mapped;
        }

        $clickTokenKeys = array_keys($tokenKeySet);
        sort($clickTokenKeys);

        return [
            'rows' => $rows,
            'total' => $total,
            'total_revenue' => $totalRevenue,
            'truncated' => $total > $maxRows,
            'max_rows' => $maxRows,
            'click_token_keys' => $clickTokenKeys,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function extractClickTokens(?string $extraJson): array
    {
        if ($extraJson === null || $extraJson === '') {
            return [];
        }
        $decoded = json_decode($extraJson, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach (['traffic_source_tokens', 'custom_tokens'] as $bag) {
            if (!isset($decoded[$bag]) || !is_array($decoded[$bag])) {
                continue;
            }
            foreach ($decoded[$bag] as $key => $value) {
                if (!is_string($key) || $key === '') {
                    continue;
                }
                if (is_array($value) || is_object($value)) {
                    continue;
                }
                $str = trim((string) $value);
                if ($str === '') {
                    continue;
                }
                $out[$key] = $str;
            }
        }

        return $out;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function listConversions(
        ?int $campaignId,
        string $dateFrom,
        string $dateTo,
        string $timezone,
        int $page,
        int $perPage
    ): array {
        $utcRange = Formatter::convertDateRangeToUTC($dateFrom, $dateTo, $timezone);
        $clicksTable = ClicksTableResolver::getStatsTable($this->db);
        $offset = max(0, ($page - 1) * $perPage);
        $perPage = min(max(1, $perPage), 200);

        $where = ['conv.ts >= ?', 'conv.ts <= ?'];
        $params = [$utcRange['from'], $utcRange['to']];
        $types = 'ss';

        if ($campaignId !== null) {
            $where[] = 'cl.campaign_id = ?';
            $params[] = $campaignId;
            $types .= 'i';
        }

        $whereSql = implode(' AND ', $where);

        $countSql = "
            SELECT COUNT(*) AS cnt
            FROM conversions conv
            INNER JOIN {$clicksTable} cl ON conv.click_id = cl.click_id
            WHERE {$whereSql}
        ";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = (int)($countStmt->get_result()->fetch_assoc()['cnt'] ?? 0);

        $sql = "
            SELECT conv.id, conv.click_id, conv.ts, conv.payout, conv.value,
                   cl.campaign_id, cp.name AS campaign_name, cl.offer_id
            FROM conversions conv
            INNER JOIN {$clicksTable} cl ON conv.click_id = cl.click_id
            LEFT JOIN campaigns cp ON cl.campaign_id = cp.id
            WHERE {$whereSql}
            ORDER BY conv.ts DESC
            LIMIT ? OFFSET ?
        ";

        $params[] = $perPage;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'id' => (int)$row['id'],
                'click_id' => $row['click_id'],
                'campaign_id' => (int)$row['campaign_id'],
                'campaign_name' => $row['campaign_name'],
                'offer_id' => isset($row['offer_id']) ? (int)$row['offer_id'] : null,
                'ts' => $row['ts'],
                'payout' => isset($row['payout']) ? (float)$row['payout'] : null,
                'value' => isset($row['value']) ? (float)$row['value'] : null,
                'revenue' => (float)($row['payout'] ?? $row['value'] ?? 0),
            ];
        }

        return ['rows' => $rows, 'total' => $total];
    }
}
