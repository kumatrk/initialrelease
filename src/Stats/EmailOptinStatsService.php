<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

use mysqli;
use SimpleKuma\Tracking\ConversionOptInClassifier;
use SimpleKuma\Utils\Formatter;

/**
 * Campaign-level email opt-in KPIs. Prefers clicks_daily_summary.optins.
 */
final class EmailOptinStatsService
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * @return array{
     *   kpis: array{visitors: int, optins: int, optin_rate: float, cost: float, cost_per_optin: float},
     *   campaigns: list<array{id: int, name: string, visitors: int, optins: int, optin_rate: float, cost: float, cost_per_optin: float}>,
     *   days: list<array{day: string, visitors: int, optins: int}>
     * }
     */
    public function overview(?int $campaignId, string $dateFrom, string $dateTo, string $timezone): array
    {
        $utc = Formatter::convertDateRangeToUTC($dateFrom, $dateTo, $timezone);
        $useSummary = $this->canUseSummary($dateFrom, $dateTo, $utc['from'], $utc['to']);

        $rows = $useSummary
            ? $this->fromSummary($campaignId, substr($utc['from'], 0, 10), substr($utc['to'], 0, 10))
            : $this->fromRaw($campaignId, $utc['from'], $utc['to']);

        $days = $useSummary
            ? $this->daysFromSummary($campaignId, substr($utc['from'], 0, 10), substr($utc['to'], 0, 10))
            : $this->daysFromRaw($campaignId, $utc['from'], $utc['to']);

        $visitors = 0;
        $optins = 0;
        $cost = 0.0;
        foreach ($rows as $row) {
            $visitors += $row['visitors'];
            $optins += $row['optins'];
            $cost += $row['cost'];
        }

        return [
            'kpis' => $this->withRates($visitors, $optins, $cost),
            'campaigns' => $rows,
            'days' => $days,
        ];
    }

    private function canUseSummary(string $userFrom, string $userTo, string $utcFrom, string $utcTo): bool
    {
        if (!Formatter::canUseUtcSummaryDateRange($userFrom, $userTo, $utcFrom, $utcTo)) {
            return false;
        }
        $tables = $this->db->query(
            "SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clicks_daily_summary' LIMIT 1"
        );
        if (!$tables || $tables->num_rows === 0) {
            return false;
        }
        $col = $this->db->query(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clicks_daily_summary'
               AND COLUMN_NAME = 'optins' LIMIT 1"
        );

        return $col && $col->num_rows > 0;
    }

    /**
     * @return list<array{id: int, name: string, visitors: int, optins: int, optin_rate: float, cost: float, cost_per_optin: float}>
     */
    private function fromSummary(?int $campaignId, string $dateFrom, string $dateTo): array
    {
        $where = 's.summary_date >= ? AND s.summary_date <= ?';
        $types = 'ss';
        $params = [$dateFrom, $dateTo];
        if ($campaignId !== null && $campaignId > 0) {
            $where .= ' AND s.campaign_id = ?';
            $types .= 'i';
            $params[] = $campaignId;
        }

        $sql = "SELECT s.campaign_id, cp.name,
                       COALESCE(SUM(s.clicks), 0) AS visitors,
                       COALESCE(SUM(s.optins), 0) AS optins,
                       COALESCE(SUM(s.cost), 0) AS cost
                FROM clicks_daily_summary s
                INNER JOIN campaigns cp ON cp.id = s.campaign_id
                WHERE {$where}
                GROUP BY s.campaign_id, cp.name
                HAVING visitors > 0 OR optins > 0
                ORDER BY optins DESC, visitors DESC, cp.name";

        return $this->fetchCampaignRows($sql, $types, $params);
    }

    /**
     * @return list<array{id: int, name: string, visitors: int, optins: int, optin_rate: float, cost: float, cost_per_optin: float}>
     */
    private function fromRaw(?int $campaignId, string $utcFrom, string $utcTo): array
    {
        $optInList = ConversionOptInClassifier::sqlInList();
        $campFilter = '';
        if ($campaignId !== null && $campaignId > 0) {
            $campFilter = ' AND cl.campaign_id = ?';
        }

        $force = $this->coverForce();
        $visitorsSql = "SELECT cl.campaign_id, cp.name,
                               COUNT(*) AS visitors,
                               COALESCE(SUM(cl.cost), 0) AS cost
                        FROM clicks cl{$force}
                        INNER JOIN campaigns cp ON cp.id = cl.campaign_id
                        WHERE cl.ts >= ? AND cl.ts <= ?{$campFilter}
                        GROUP BY cl.campaign_id, cp.name";

        $clickJoin = $this->indexExists('idx_clicks_click_id_cover_stats')
            ? 'clicks cl FORCE INDEX (idx_clicks_click_id_cover_stats)'
            : 'clicks cl';
        $optinCamp = ($campaignId !== null && $campaignId > 0) ? ' AND cl.campaign_id = ?' : '';
        $optinSql = "SELECT cl.campaign_id, COUNT(*) AS optins
                     FROM conversions conv
                     INNER JOIN {$clickJoin} ON cl.click_id = conv.click_id
                     WHERE conv.ts >= ? AND conv.ts <= ?
                       AND LOWER(COALESCE(conv.event_key, '')) IN ({$optInList})
                       {$optinCamp}
                     GROUP BY cl.campaign_id";

        $byCamp = [];
        $stmt = $this->db->prepare($visitorsSql);
        if ($stmt === false) {
            return [];
        }
        $vTypes = 'ss' . (($campaignId !== null && $campaignId > 0) ? 'i' : '');
        $vParams = [$utcFrom, $utcTo];
        if ($campaignId !== null && $campaignId > 0) {
            $vParams[] = $campaignId;
        }
        $stmt->bind_param($vTypes, ...$vParams);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $id = (int) $row['campaign_id'];
            $byCamp[$id] = [
                'id' => $id,
                'name' => (string) $row['name'],
                'visitors' => (int) $row['visitors'],
                'optins' => 0,
                'cost' => (float) $row['cost'],
            ];
        }
        $stmt->close();

        $oStmt = $this->db->prepare($optinSql);
        if ($oStmt !== false) {
            $oStmt->bind_param($vTypes, ...$vParams);
            $oStmt->execute();
            $oResult = $oStmt->get_result();
            while ($row = $oResult->fetch_assoc()) {
                $id = (int) $row['campaign_id'];
                if (!isset($byCamp[$id])) {
                    $nameStmt = $this->db->prepare('SELECT name FROM campaigns WHERE id = ?');
                    $nameStmt?->bind_param('i', $id);
                    $nameStmt?->execute();
                    $name = (string) ($nameStmt?->get_result()->fetch_assoc()['name'] ?? ('#' . $id));
                    $nameStmt?->close();
                    $byCamp[$id] = [
                        'id' => $id,
                        'name' => $name,
                        'visitors' => 0,
                        'optins' => 0,
                        'cost' => 0.0,
                    ];
                }
                $byCamp[$id]['optins'] = (int) $row['optins'];
            }
            $oStmt->close();
        }

        $out = [];
        foreach ($byCamp as $row) {
            if ($row['visitors'] <= 0 && $row['optins'] <= 0) {
                continue;
            }
            $out[] = array_merge($row, $this->ratesOnly($row['visitors'], $row['optins'], $row['cost']));
        }
        usort($out, static function (array $a, array $b): int {
            return [$b['optins'], $b['visitors']] <=> [$a['optins'], $a['visitors']];
        });

        return $out;
    }

    /**
     * @return list<array{day: string, visitors: int, optins: int}>
     */
    private function daysFromSummary(?int $campaignId, string $dateFrom, string $dateTo): array
    {
        $where = 's.summary_date >= ? AND s.summary_date <= ?';
        $types = 'ss';
        $params = [$dateFrom, $dateTo];
        if ($campaignId !== null && $campaignId > 0) {
            $where .= ' AND s.campaign_id = ?';
            $types .= 'i';
            $params[] = $campaignId;
        }
        $sql = "SELECT s.summary_date AS day,
                       COALESCE(SUM(s.clicks), 0) AS visitors,
                       COALESCE(SUM(s.optins), 0) AS optins
                FROM clicks_daily_summary s
                WHERE {$where}
                GROUP BY s.summary_date
                ORDER BY s.summary_date";

        return $this->fetchDayRows($sql, $types, $params);
    }

    /**
     * @return list<array{day: string, visitors: int, optins: int}>
     */
    private function daysFromRaw(?int $campaignId, string $utcFrom, string $utcTo): array
    {
        $optInList = ConversionOptInClassifier::sqlInList();
        $force = $this->coverForce();
        $camp = '';
        $types = 'ss';
        $params = [$utcFrom, $utcTo];
        if ($campaignId !== null && $campaignId > 0) {
            $camp = ' AND cl.campaign_id = ?';
            $types .= 'i';
            $params[] = $campaignId;
        }

        $visitors = [];
        $stmt = $this->db->prepare(
            "SELECT DATE(cl.ts) AS day, COUNT(*) AS visitors
             FROM clicks cl{$force}
             WHERE cl.ts >= ? AND cl.ts <= ?{$camp}
             GROUP BY DATE(cl.ts)
             ORDER BY day"
        );
        if ($stmt !== false) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $visitors[(string) $row['day']] = (int) $row['visitors'];
            }
            $stmt->close();
        }

        $clickJoin = $this->indexExists('idx_clicks_click_id_cover_stats')
            ? 'clicks cl FORCE INDEX (idx_clicks_click_id_cover_stats)'
            : 'clicks cl';
        $optins = [];
        $oStmt = $this->db->prepare(
            "SELECT DATE(conv.ts) AS day, COUNT(*) AS optins
             FROM conversions conv
             INNER JOIN {$clickJoin} ON cl.click_id = conv.click_id
             WHERE conv.ts >= ? AND conv.ts <= ?
               AND LOWER(COALESCE(conv.event_key, '')) IN ({$optInList})
               {$camp}
             GROUP BY DATE(conv.ts)"
        );
        if ($oStmt !== false) {
            $oStmt->bind_param($types, ...$params);
            $oStmt->execute();
            $result = $oStmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $optins[(string) $row['day']] = (int) $row['optins'];
            }
            $oStmt->close();
        }

        $days = array_unique(array_merge(array_keys($visitors), array_keys($optins)));
        sort($days);
        $out = [];
        foreach ($days as $day) {
            $out[] = [
                'day' => $day,
                'visitors' => $visitors[$day] ?? 0,
                'optins' => $optins[$day] ?? 0,
            ];
        }

        return $out;
    }

    /**
     * @param list<mixed> $params
     * @return list<array{id: int, name: string, visitors: int, optins: int, optin_rate: float, cost: float, cost_per_optin: float}>
     */
    private function fetchCampaignRows(string $sql, string $types, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $visitors = (int) $row['visitors'];
            $optins = (int) $row['optins'];
            $cost = (float) $row['cost'];
            $rows[] = array_merge([
                'id' => (int) $row['campaign_id'],
                'name' => (string) $row['name'],
                'visitors' => $visitors,
                'optins' => $optins,
                'cost' => $cost,
            ], $this->ratesOnly($visitors, $optins, $cost));
        }
        $stmt->close();

        return $rows;
    }

    /**
     * @param list<mixed> $params
     * @return list<array{day: string, visitors: int, optins: int}>
     */
    private function fetchDayRows(string $sql, string $types, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'day' => (string) $row['day'],
                'visitors' => (int) $row['visitors'],
                'optins' => (int) $row['optins'],
            ];
        }
        $stmt->close();

        return $rows;
    }

    /**
     * @return array{visitors: int, optins: int, optin_rate: float, cost: float, cost_per_optin: float}
     */
    private function withRates(int $visitors, int $optins, float $cost): array
    {
        return array_merge([
            'visitors' => $visitors,
            'optins' => $optins,
            'cost' => $cost,
        ], $this->ratesOnly($visitors, $optins, $cost));
    }

    /**
     * @return array{optin_rate: float, cost_per_optin: float}
     */
    private function ratesOnly(int $visitors, int $optins, float $cost): array
    {
        return [
            'optin_rate' => $visitors > 0 ? round(($optins / $visitors) * 100, 2) : 0.0,
            'cost_per_optin' => $optins > 0 ? round($cost / $optins, 4) : 0.0,
        ];
    }

    private function coverForce(): string
    {
        return $this->indexExists('idx_clicks_ts_stats_cover')
            ? ' FORCE INDEX (idx_clicks_ts_stats_cover)'
            : '';
    }

    private function indexExists(string $name): bool
    {
        static $cache = [];
        if (array_key_exists($name, $cache)) {
            return $cache[$name];
        }
        $stmt = $this->db->prepare(
            "SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clicks' AND INDEX_NAME = ?
             LIMIT 1"
        );
        if ($stmt === false) {
            return $cache[$name] = false;
        }
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $cache[$name] = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();

        return $cache[$name];
    }
}
