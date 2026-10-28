<?php

declare(strict_types=1);

namespace SimpleKuma\Guard;

/**
 * Account anomaly alerts. Reads daily summaries and recent postback rows.
 * A click is stored once in clicks_daily_summary, on its offer and landing-page row,
 * so account totals sum every row for the date. Does not scan the clicks table.
 */
final class GrizzlyGuardAnomaly
{
    /**
     * @param array<string, array{on: bool, value: int, min: int, max: int}> $config
     * @param callable(string, string, string, string): int $alert
     */
    public static function watch(\mysqli $db, array $config, callable $alert): int
    {
        $actions = 0;
        $today = gmdate('Y-m-d');
        $yesterday = gmdate('Y-m-d', time() - 86400);
        $withBots = self::hasColumn($db, 'clicks_daily_summary', 'bot_clicks');
        $todayTotals = self::totals($db, $today, $withBots);
        $yesterdayTotals = self::totals($db, $yesterday, $withBots);

        if (!empty($config['conversion_drop']['on']) && $todayTotals !== null && $yesterdayTotals !== null) {
            $actions += self::conversionDrop($config['conversion_drop'], $todayTotals, $yesterdayTotals, $alert);
        }
        if (!empty($config['revenue_under_cost']['on']) && $todayTotals !== null) {
            $actions += self::revenueUnderCost($todayTotals, $alert);
        }
        if (!empty($config['bot_spike']['on']) && $withBots && $todayTotals !== null) {
            $actions += self::botSpike($config['bot_spike'], $todayTotals, $alert);
        }
        if (!empty($config['zone_spike']['on']) && self::hasTable($db, 'clicks_stats_by_token_daily')) {
            $actions += self::zoneSpike($db, $config['zone_spike'], $today, $yesterday, $alert);
        }
        if (!empty($config['postback_failure']['on']) && self::hasTable($db, 'postback_logs')) {
            $actions += self::postbackFailures($db, $config['postback_failure'], $alert);
        }
        if (!empty($config['tracker_errors']['on']) && self::trackerSaveFailed()) {
            $actions += $alert(
                'A click could not be saved. Check the server error log.',
                'anomaly_tracker',
                'tracker',
                'Failed to record click'
            );
        }
        if (!empty($config['no_conversions']['on'])) {
            $actions += self::noConversions($db, $config['no_conversions'], $today, $alert);
        }

        return $actions;
    }

    /**
     * @param array{on: bool, value: int, min: int, max: int} $rule
     * @param array{clicks: int, conversions: int, revenue: float, cost: float, bots: int} $today
     * @param array{clicks: int, conversions: int, revenue: float, cost: float, bots: int} $yesterday
     * @param callable(string, string, string, string): int $alert
     */
    private static function conversionDrop(array $rule, array $today, array $yesterday, callable $alert): int
    {
        if ($yesterday['conversions'] < 5 || $today['conversions'] >= $yesterday['conversions']) {
            return 0;
        }
        $drop = (int) round((1 - ($today['conversions'] / $yesterday['conversions'])) * 100);
        if ($drop < $rule['value']) {
            return 0;
        }

        return $alert(
            'Conversions today are down ' . $drop . '% from yesterday (' . $today['conversions'] . ' vs ' . $yesterday['conversions'] . ').',
            'anomaly_conversions',
            'conversion_drop',
            (string) $drop
        );
    }

    /**
     * @param array{clicks: int, conversions: int, revenue: float, cost: float, bots: int} $today
     * @param callable(string, string, string, string): int $alert
     */
    private static function revenueUnderCost(array $today, callable $alert): int
    {
        if ($today['cost'] < 1 || $today['revenue'] >= $today['cost']) {
            return 0;
        }

        return $alert(
            'Revenue today is under click cost ($' . number_format($today['revenue'], 2) . ' revenue, $' . number_format($today['cost'], 2) . ' cost).',
            'anomaly_cost',
            'revenue_under_cost',
            number_format($today['cost'], 2)
        );
    }

    /**
     * @param array{on: bool, value: int, min: int, max: int} $rule
     * @param array{clicks: int, conversions: int, revenue: float, cost: float, bots: int} $today
     * @param callable(string, string, string, string): int $alert
     */
    private static function botSpike(array $rule, array $today, callable $alert): int
    {
        if ($today['bots'] < 1) {
            return 0;
        }
        $total = $today['clicks'] + $today['bots'];
        if ($total < 20) {
            return 0;
        }
        $share = (int) round(($today['bots'] / $total) * 100);
        if ($share < $rule['value']) {
            return 0;
        }

        return $alert(
            'Known bots are ' . $share . '% of today\'s clicks (' . $today['bots'] . ' of ' . $total . ').',
            'anomaly_bots',
            'bot_spike',
            (string) $share
        );
    }

    /**
     * @param array{on: bool, value: int, min: int, max: int} $rule
     * @param callable(string, string, string, string): int $alert
     */
    private static function zoneSpike(\mysqli $db, array $rule, string $today, string $yesterday, callable $alert): int
    {
        $now = self::tokenTotals($db, $today);
        $then = self::tokenTotals($db, $yesterday);
        $worstLabel = '';
        $worstNow = 0;
        $worstThen = 0;
        foreach ($now as $key => $visits) {
            if ($visits < 30) {
                continue;
            }
            $before = $then[$key] ?? 0;
            if ($before < 5 || $visits < ($before * $rule['value'])) {
                continue;
            }
            if ($visits > $worstNow) {
                $worstNow = $visits;
                $worstThen = $before;
                $worstLabel = $key;
            }
        }
        if ($worstLabel === '') {
            return 0;
        }
        $name = self::clip(str_replace("\n", ' ', $worstLabel), 80);

        return $alert(
            'Zone ' . $name . ' jumped from ' . $worstThen . ' visits yesterday to ' . $worstNow . ' today.',
            'anomaly_zone',
            'zone_spike',
            $name
        );
    }

    /**
     * @param array{on: bool, value: int, min: int, max: int} $rule
     * @param callable(string, string, string, string): int $alert
     */
    private static function postbackFailures(\mysqli $db, array $rule, callable $alert): int
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) AS n FROM postback_logs WHERE success = 0 AND created_at >= (NOW() - INTERVAL 1 HOUR)'
        );
        if (!$stmt) {
            return 0;
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $count = (int) ($row['n'] ?? 0);
        if ($count < $rule['value']) {
            return 0;
        }

        return $alert(
            $count . ' postbacks failed in the last hour.',
            'anomaly_postback',
            'postback_failure',
            (string) $count
        );
    }

    /**
     * @param array{on: bool, value: int, min: int, max: int} $rule
     * @param callable(string, string, string, string): int $alert
     */
    private static function noConversions(\mysqli $db, array $rule, string $today, callable $alert): int
    {
        $minClicks = $rule['value'];
        $stmt = $db->prepare(
            'SELECT c.name AS name, SUM(s.clicks) AS clicks
             FROM clicks_daily_summary s
             INNER JOIN campaigns c ON c.id = s.campaign_id
             WHERE s.summary_date = ?
             GROUP BY s.campaign_id, c.name
             HAVING SUM(s.clicks) >= ? AND SUM(s.conversions) = 0
             ORDER BY clicks DESC
             LIMIT 3'
        );
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('si', $today, $minClicks);
        $stmt->execute();
        $result = $stmt->get_result();
        $names = [];
        while ($row = $result->fetch_assoc()) {
            $names[] = self::clip((string) $row['name'], 40) . ' (' . (int) $row['clicks'] . ' clicks)';
        }
        $stmt->close();
        if ($names === []) {
            return 0;
        }

        return $alert(
            'No conversions today after the click threshold: ' . implode('; ', $names) . '.',
            'anomaly_silent',
            'no_conversions',
            (string) $minClicks
        );
    }

    /**
     * @return array{clicks: int, conversions: int, revenue: float, cost: float, bots: int}|null
     */
    private static function totals(\mysqli $db, string $date, bool $withBots): ?array
    {
        $bots = $withBots ? 'COALESCE(SUM(bot_clicks), 0)' : '0';
        $stmt = $db->prepare(
            "SELECT COALESCE(SUM(clicks), 0) AS clicks,
                    COALESCE(SUM(conversions), 0) AS conversions,
                    COALESCE(SUM(revenue), 0) AS revenue,
                    COALESCE(SUM(cost), 0) AS cost,
                    {$bots} AS bots
             FROM clicks_daily_summary
             WHERE summary_date = ?"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $date);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }

        return [
            'clicks' => (int) $row['clicks'],
            'conversions' => (int) $row['conversions'],
            'revenue' => (float) $row['revenue'],
            'cost' => (float) $row['cost'],
            'bots' => (int) $row['bots'],
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function tokenTotals(\mysqli $db, string $date): array
    {
        $stmt = $db->prepare(
            "SELECT token_param, token_value, SUM(visitors) AS visitors
             FROM clicks_stats_by_token_daily
             WHERE summary_date = ? AND token_value <> ''
             GROUP BY token_param, token_value
             HAVING visitors >= 5
             ORDER BY visitors DESC
             LIMIT 30"
        );
        $totals = [];
        if (!$stmt) {
            return $totals;
        }
        $stmt->bind_param('s', $date);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $key = (string) $row['token_param'] . '=' . (string) $row['token_value'];
            $totals[$key] = (int) $row['visitors'];
        }
        $stmt->close();

        return $totals;
    }

    private static function trackerSaveFailed(): bool
    {
        $path = ini_get('error_log');
        if (!is_string($path) || $path === '' || !is_file($path) || !is_readable($path)) {
            return false;
        }
        $size = filesize($path);
        if ($size === false || $size < 1 || $size > 5000000) {
            return false;
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $read = (int) min($size, 65536);
        if ($size > $read) {
            fseek($handle, -$read, SEEK_END);
        }
        $chunk = fread($handle, $read);
        fclose($handle);
        if (!is_string($chunk) || !str_contains($chunk, 'Failed to record click')) {
            return false;
        }

        return str_contains($chunk, gmdate('d-M-Y')) || str_contains($chunk, date('d-M-Y'));
    }

    private static function hasTable(\mysqli $db, string $table): bool
    {
        $safe = preg_replace('/[^A-Za-z0-9_]/', '', $table) ?? '';
        $result = $db->query("SHOW TABLES LIKE '{$safe}'");

        return $result !== false && $result->num_rows > 0;
    }

    private static function hasColumn(\mysqli $db, string $table, string $column): bool
    {
        $table = preg_replace('/[^A-Za-z0-9_]/', '', $table) ?? '';
        $column = preg_replace('/[^A-Za-z0-9_]/', '', $column) ?? '';
        $result = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");

        return $result !== false && $result->num_rows > 0;
    }

    private static function clip(string $value, int $max): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }

        return substr($value, 0, $max);
    }
}
