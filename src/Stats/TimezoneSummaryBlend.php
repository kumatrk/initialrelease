<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

/**
 * Blend clicks_daily_summary (full UTC days) with raw click scans (partial edge days)
 * so non-UTC date ranges stay timezone-accurate without scanning the full clicks table.
 */
final class TimezoneSummaryBlend
{
    /**
     * @return list<array{type: 'preagg'|'raw', from: string, to: string}>
     */
    public static function segments(string $utcFrom, string $utcTo): array
    {
        $utc = new \DateTimeZone('UTC');
        $utcFromDt = new \DateTimeImmutable($utcFrom, $utc);
        $utcToDt = new \DateTimeImmutable($utcTo, $utc);
        if ($utcToDt < $utcFromDt) {
            return [];
        }

        $cursor = new \DateTimeImmutable($utcFromDt->format('Y-m-d') . ' 00:00:00', $utc);
        $endDay = new \DateTimeImmutable($utcToDt->format('Y-m-d') . ' 00:00:00', $utc);

        $dayPieces = [];
        while ($cursor <= $endDay) {
            $dayStart = $cursor;
            $dayEnd = $cursor->setTime(23, 59, 59);
            $winStart = $utcFromDt > $dayStart ? $utcFromDt : $dayStart;
            $winEnd = $utcToDt < $dayEnd ? $utcToDt : $dayEnd;
            if ($winEnd >= $winStart) {
                $dayPieces[] = [
                    'full' => ($winStart == $dayStart && $winEnd >= $dayEnd),
                    'date' => $cursor->format('Y-m-d'),
                    'from' => $winStart->format('Y-m-d H:i:s'),
                    'to' => $winEnd->format('Y-m-d H:i:s'),
                ];
            }
            $cursor = $cursor->modify('+1 day');
        }

        return self::coalesceSegments($dayPieces);
    }

    /**
     * @param list<array{full: bool, date: string, from: string, to: string}> $dayPieces
     * @return list<array{type: 'preagg'|'raw', from: string, to: string}>
     */
    private static function coalesceSegments(array $dayPieces): array
    {
        $segments = [];
        $n = count($dayPieces);
        $i = 0;
        while ($i < $n) {
            if ($dayPieces[$i]['full']) {
                $j = $i;
                while ($j < $n && $dayPieces[$j]['full']) {
                    $j++;
                }
                $segments[] = [
                    'type' => 'preagg',
                    'from' => $dayPieces[$i]['date'],
                    'to' => $dayPieces[$j - 1]['date'],
                ];
                $i = $j;
            } else {
                $segments[] = [
                    'type' => 'raw',
                    'from' => $dayPieces[$i]['from'],
                    'to' => $dayPieces[$i]['to'],
                ];
                $i++;
            }
        }

        return $segments;
    }

    /**
     * @param list<array{type: 'preagg'|'raw', from: string, to: string}> $segments
     */
    public static function resolveSource(array $segments): string
    {
        if ($segments === []) {
            return 'raw_clicks';
        }

        $hasPre = false;
        $hasRaw = false;
        foreach ($segments as $segment) {
            if ($segment['type'] === 'preagg') {
                $hasPre = true;
            } else {
                $hasRaw = true;
            }
        }

        if ($hasPre && !$hasRaw) {
            return 'pre_aggregate';
        }
        if ($hasPre && $hasRaw) {
            return 'pre_aggregate_hybrid';
        }

        return 'raw_clicks';
    }

    /**
     * @param list<int> $campaignIds
     * @param list<array{type: 'preagg'|'raw', from: string, to: string}> $segments
     */
    public static function areSegmentsReliable(\mysqli $db, array $campaignIds, array $segments): bool
    {
        foreach ($segments as $segment) {
            if ($segment['type'] === 'preagg'
                && !self::isSummaryReliable($db, $campaignIds, $segment['from'], $segment['to'])
            ) {
                return false;
            }
        }

        return true;
    }

    /** @var array<string, bool> Request-scoped memo for reliability checks */
    private static array $reliabilityMemo = [];

    /** Soft TTL so the fast path does not re-scan raw clicks on every stats request. */
    private const RELIABILITY_CACHE_TTL = 90;

    /**
     * Guard against incomplete clicks_daily_summary (missing historical backfill).
     * If raw click volume in the UTC-day span diverges from summary, prefer raw.
     *
     * Results are memoized per request and briefly cached (APCu/file) so dashboard,
     * list, and campaign-stats calls do not each pay a full raw visitor recount.
     *
     * Drop in-request reliability memo (e.g. after hide-IP aggregate adjust).
     * File/APCu entries expire via TTL.
     *
     * @param list<int> $campaignIds
     */
    public static function clearReliabilityMemo(): void
    {
        self::$reliabilityMemo = [];
        $dir = dirname(__DIR__, 2) . '/storage/cache';
        if (is_dir($dir)) {
            foreach (glob($dir . '/sk_sum_rel_*.json') ?: [] as $file) {
                @unlink($file);
            }
        }
        if (function_exists('apcu_clear_cache')) {
            // Avoid wiping unrelated APCu; delete known keys if we had a registry.
            // Soft TTL (90s) covers the rest.
        }
    }

    /**
     * @param list<int> $campaignIds
     */
    public static function isSummaryReliable(
        \mysqli $db,
        array $campaignIds,
        string $summaryDateFrom,
        string $summaryDateTo
    ): bool {
        $campaignIds = array_values(array_unique(array_filter(array_map('intval', $campaignIds))));
        if ($campaignIds === []) {
            return false;
        }

        sort($campaignIds);
        $cacheKey = 'rel_' . md5(implode(',', $campaignIds) . '|' . $summaryDateFrom . '|' . $summaryDateTo);
        if (array_key_exists($cacheKey, self::$reliabilityMemo)) {
            return self::$reliabilityMemo[$cacheKey];
        }

        $cached = self::readReliabilityCache($cacheKey);
        if ($cached !== null) {
            self::$reliabilityMemo[$cacheKey] = $cached;

            return $cached;
        }

        $reliable = self::computeSummaryReliable($db, $campaignIds, $summaryDateFrom, $summaryDateTo);
        self::$reliabilityMemo[$cacheKey] = $reliable;
        self::writeReliabilityCache($cacheKey, $reliable);

        return $reliable;
    }

    /**
     * @param list<int> $campaignIds
     */
    private static function computeSummaryReliable(
        \mysqli $db,
        array $campaignIds,
        string $summaryDateFrom,
        string $summaryDateTo
    ): bool {
        $placeholders = implode(',', array_fill(0, count($campaignIds), '?'));
        $types = str_repeat('i', count($campaignIds)) . 'ss';
        $params = array_merge($campaignIds, [$summaryDateFrom, $summaryDateTo]);

        $stmt = $db->prepare("
            SELECT COALESCE(SUM(s.clicks), 0) AS summary_clicks
            FROM clicks_daily_summary s
            WHERE s.campaign_id IN ({$placeholders})
              AND s.summary_date >= ?
              AND s.summary_date <= ?
        ");
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $summaryClicks = (int)($stmt->get_result()->fetch_assoc()['summary_clicks'] ?? 0);
        $stmt->close();

        // Cheap covering-index COUNT(*) on clicks (ts, campaign_id, …).
        // Do NOT add ua/ad_id/IP predicates here — they force full row reads and turn a
        // sub-second reliability check into multi-second (or timeout) on large ranges.
        // Invalid-FB / hidden-IP exclusions belong on write-time DailySummaryUpdater;
        // the 0.85–1.15 ratio below absorbs the small remaining gap.
        $utcFrom = $summaryDateFrom . ' 00:00:00';
        $utcTo = $summaryDateTo . ' 23:59:59';
        $paramsRaw = array_merge($campaignIds, [$utcFrom, $utcTo]);
        $stmt = $db->prepare("
            SELECT COUNT(*) AS raw_clicks
            FROM clicks cl
            WHERE cl.campaign_id IN ({$placeholders})
              AND cl.ts >= ?
              AND cl.ts <= ?
        ");
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param($types, ...$paramsRaw);
        $stmt->execute();
        $rawClicks = (int)($stmt->get_result()->fetch_assoc()['raw_clicks'] ?? 0);
        $stmt->close();

        if ($rawClicks === 0 && \SimpleKuma\Database\ClicksTableResolver::archiveHasRows($db)) {
            $stmt = $db->prepare("
                SELECT COUNT(*) AS raw_clicks
                FROM clicks_archive cl
                WHERE cl.campaign_id IN ({$placeholders})
                  AND cl.ts >= ?
                  AND cl.ts <= ?
            ");
            if ($stmt !== false) {
                $stmt->bind_param($types, ...$paramsRaw);
                $stmt->execute();
                $rawClicks = (int)($stmt->get_result()->fetch_assoc()['raw_clicks'] ?? 0);
                $stmt->close();
            }
        }
        if ($rawClicks === 0) {
            return true;
        }
        // Allow small gap for invalid-FB exclusion / race; hard-fail when summary clearly incomplete
        if ($summaryClicks === 0) {
            return false;
        }
        $ratio = $summaryClicks / $rawClicks;

        return $ratio >= 0.85 && $ratio <= 1.15;
    }

    private static function readReliabilityCache(string $cacheKey): ?bool
    {
        if (function_exists('apcu_fetch')) {
            $ok = false;
            $entry = apcu_fetch('sk_sum_rel_' . $cacheKey, $ok);
            if ($ok && is_array($entry) && isset($entry['v'], $entry['exp']) && $entry['exp'] >= time()) {
                return (bool)$entry['v'];
            }
        }

        $path = self::reliabilityCachePath($cacheKey);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $entry = json_decode($raw, true);
        if (!is_array($entry) || !isset($entry['v'], $entry['exp']) || (int)$entry['exp'] < time()) {
            return null;
        }

        return (bool)$entry['v'];
    }

    private static function writeReliabilityCache(string $cacheKey, bool $reliable): void
    {
        $entry = ['v' => $reliable, 'exp' => time() + self::RELIABILITY_CACHE_TTL];
        if (function_exists('apcu_store')) {
            apcu_store('sk_sum_rel_' . $cacheKey, $entry, self::RELIABILITY_CACHE_TTL);
        }

        $dir = dirname(self::reliabilityCachePath($cacheKey));
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(self::reliabilityCachePath($cacheKey), json_encode($entry), LOCK_EX);
    }

    private static function reliabilityCachePath(string $cacheKey): string
    {
        $root = dirname(__DIR__, 2);
        return $root . '/storage/cache/sk_sum_rel_' . $cacheKey . '.json';
    }

    /**
     * @param array<int, array<string, mixed>> ...$maps
     * @return array<int, array{views: int, lp_clicks: int, direct_clicks: int, conversions: int, manual_cost: float, revenue: float}>
     */
    public static function mergeCampaignMetrics(array ...$maps): array
    {
        $out = [];
        foreach ($maps as $map) {
            foreach ($map as $id => $row) {
                $id = (int)$id;
                if (!isset($out[$id])) {
                    $out[$id] = [
                        'views' => 0,
                        'lp_clicks' => 0,
                        'direct_clicks' => 0,
                        'conversions' => 0,
                        'manual_cost' => 0.0,
                        'revenue' => 0.0,
                    ];
                }
                $out[$id]['views'] += (int)($row['views'] ?? 0);
                $out[$id]['lp_clicks'] += (int)($row['lp_clicks'] ?? 0);
                $out[$id]['direct_clicks'] += (int)($row['direct_clicks'] ?? 0);
                $out[$id]['conversions'] += (int)($row['conversions'] ?? 0);
                $out[$id]['manual_cost'] += (float)($row['manual_cost'] ?? 0);
                $out[$id]['revenue'] += (float)($row['revenue'] ?? 0);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> ...$totals
     * @return array{views: int, lp_clicks: int, direct_clicks: int, conversions: int, revenue: float}
     */
    public static function mergeOverviewTotals(array ...$totals): array
    {
        $out = [
            'views' => 0,
            'lp_clicks' => 0,
            'direct_clicks' => 0,
            'conversions' => 0,
            'revenue' => 0.0,
        ];
        foreach ($totals as $row) {
            $out['views'] += (int)($row['views'] ?? 0);
            $out['lp_clicks'] += (int)($row['lp_clicks'] ?? 0);
            $out['direct_clicks'] += (int)($row['direct_clicks'] ?? 0);
            $out['conversions'] += (int)($row['conversions'] ?? 0);
            $out['revenue'] += (float)($row['revenue'] ?? 0);
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> ...$rowLists
     * @return list<array<string, mixed>>
     */
    public static function mergeBreakdownRows(array ...$rowLists): array
    {
        $byKey = [];
        foreach ($rowLists as $rows) {
            foreach ($rows as $row) {
                $key = (string)($row['group'] ?? $row['group_key'] ?? '');
                if ($key === '') {
                    continue;
                }
                if (!isset($byKey[$key])) {
                    $byKey[$key] = $row;
                    $byKey[$key]['clicks'] = (int)($row['clicks'] ?? 0);
                    $byKey[$key]['lp_clicks'] = (int)($row['lp_clicks'] ?? 0);
                    $byKey[$key]['conversions'] = (int)($row['conversions'] ?? 0);
                    $byKey[$key]['cost'] = (float)($row['cost'] ?? 0);
                    $byKey[$key]['revenue'] = (float)($row['revenue'] ?? 0);
                    $byKey[$key]['direct_clicks'] = (int)($row['direct_clicks'] ?? 0);
                    continue;
                }
                $byKey[$key]['clicks'] += (int)($row['clicks'] ?? 0);
                $byKey[$key]['lp_clicks'] += (int)($row['lp_clicks'] ?? 0);
                $byKey[$key]['conversions'] += (int)($row['conversions'] ?? 0);
                $byKey[$key]['cost'] += (float)($row['cost'] ?? 0);
                $byKey[$key]['revenue'] += (float)($row['revenue'] ?? 0);
                if (isset($row['direct_clicks'])) {
                    $byKey[$key]['direct_clicks'] = (int)($byKey[$key]['direct_clicks'] ?? 0)
                        + (int)$row['direct_clicks'];
                }
                if (empty($byKey[$key]['group_label']) && !empty($row['group_label'])) {
                    $byKey[$key]['group_label'] = $row['group_label'];
                }
                if (empty($byKey[$key]['name']) && !empty($row['name'])) {
                    $byKey[$key]['name'] = $row['name'];
                }
            }
        }

        $merged = [];
        foreach ($byKey as $key => $row) {
            $groupKey = (string)$key;
            $formatted = CampaignStatsExpressions::formatMetricsRow(
                $groupKey,
                $row['group_label'] ?? ($row['name'] ?? null),
                [
                    'clicks' => (int)$row['clicks'],
                    'lp_clicks' => (int)$row['lp_clicks'],
                    'conversions' => (int)$row['conversions'],
                    'cost' => (float)$row['cost'],
                    'revenue' => (float)$row['revenue'],
                ]
            ) + [
                'group_key' => $groupKey,
                'name' => $row['name'] ?? ($row['group_label'] ?? $groupKey),
            ];
            $direct = (int)($row['direct_clicks'] ?? 0);
            $formatted['direct_clicks'] = $direct;
            $formatted['action_clicks'] = (int)$row['lp_clicks'] + $direct;
            $merged[] = $formatted;
        }

        return $merged;
    }

    /**
     * @param list<array{id: int|string, views?: int, manual_cost?: float, revenue?: float}> $rows
     * @return list<array<string, mixed>>
     */
    public static function mergeCampaignTableRows(array ...$rowLists): array
    {
        $byId = [];
        foreach ($rowLists as $rows) {
            foreach ($rows as $row) {
                $id = (int)($row['id'] ?? 0);
                if ($id < 1) {
                    continue;
                }
                if (!isset($byId[$id])) {
                    $byId[$id] = $row;
                    $byId[$id]['views'] = (int)($row['views'] ?? 0);
                    $byId[$id]['lp_clicks'] = (int)($row['lp_clicks'] ?? 0);
                    $byId[$id]['direct_clicks'] = (int)($row['direct_clicks'] ?? 0);
                    $byId[$id]['conversions'] = (int)($row['conversions'] ?? 0);
                    $byId[$id]['manual_cost'] = (float)($row['manual_cost'] ?? 0);
                    $byId[$id]['revenue'] = (float)($row['revenue'] ?? 0);
                    $byId[$id]['invalid_clicks'] = (int)($row['invalid_clicks'] ?? 0);
                    continue;
                }
                $byId[$id]['views'] += (int)($row['views'] ?? 0);
                $byId[$id]['lp_clicks'] += (int)($row['lp_clicks'] ?? 0);
                $byId[$id]['direct_clicks'] += (int)($row['direct_clicks'] ?? 0);
                $byId[$id]['conversions'] += (int)($row['conversions'] ?? 0);
                $byId[$id]['manual_cost'] += (float)($row['manual_cost'] ?? 0);
                $byId[$id]['revenue'] += (float)($row['revenue'] ?? 0);
                $byId[$id]['invalid_clicks'] += (int)($row['invalid_clicks'] ?? 0);
            }
        }

        return array_values($byId);
    }
}
