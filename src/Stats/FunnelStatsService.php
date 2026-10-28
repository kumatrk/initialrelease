<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

use mysqli;

/**
 * Deep Funnel reporting from clicks_funnel_step_daily + clicks_funnel_path_daily only.
 * Never scans clicks.extra_json for unfiltered reports.
 */
final class FunnelStatsService
{
    public function __construct(private mysqli $db)
    {
    }

    public function tablesReady(): bool
    {
        $r1 = $this->db->query("SHOW TABLES LIKE 'clicks_funnel_step_daily'");
        $r2 = $this->db->query("SHOW TABLES LIKE 'clicks_funnel_path_daily'");
        return ($r1 && $r1->num_rows > 0 && $r2 && $r2->num_rows > 0);
    }

    /**
     * @return array{steps: list<array>, paths: list<array>, levels: list<array>}
     */
    public function getFunnelReport(int $campaignId, string $dateFrom, string $dateTo, ?string $nodePrefix = null): array
    {
        if (!$this->tablesReady()) {
            return [
                'steps' => [],
                'paths' => [],
                'levels' => [],
                'error' => 'Funnel aggregate tables missing. Run migration 096.',
            ];
        }

        $steps = $this->querySteps($campaignId, $dateFrom, $dateTo);
        $paths = $this->queryPaths($campaignId, $dateFrom, $dateTo, $nodePrefix);
        $levels = $this->aggregateLevels($steps);
        $names = $this->resolveNames($steps);

        foreach ($steps as &$s) {
            $lpId = (int) ($s['landing_page_id'] ?? 0);
            $offId = (int) ($s['offer_id'] ?? 0);
            $s['landing_page_name'] = $lpId > 0 ? ($names['lp'][$lpId] ?? ('LP #' . $lpId)) : null;
            $s['offer_name'] = $offId > 0 ? ($names['offer'][$offId] ?? ('Offer #' . $offId)) : null;
            $entries = (int) ($s['entries'] ?? 0);
            $continues = (int) ($s['continues'] ?? 0);
            $s['drop_off'] = max(0, $entries - $continues);
            $s['drop_off_pct'] = $entries > 0 ? round(100.0 * $s['drop_off'] / $entries, 1) : 0.0;
        }
        unset($s);

        return [
            'steps' => $steps,
            'paths' => $paths,
            'levels' => $levels,
            'node_prefix' => $nodePrefix,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function querySteps(int $campaignId, string $dateFrom, string $dateTo): array
    {
        $sql = 'SELECT level, slot, landing_page_id, offer_id,
                       SUM(entries) AS entries,
                       SUM(continues) AS continues,
                       SUM(offer_clicks) AS offer_clicks,
                       SUM(conversions) AS conversions,
                       SUM(revenue) AS revenue,
                       SUM(cost) AS cost
                FROM clicks_funnel_step_daily
                WHERE campaign_id = ? AND summary_date >= ? AND summary_date <= ?
                GROUP BY level, slot, landing_page_id, offer_id
                ORDER BY level ASC, slot ASC, landing_page_id ASC, offer_id ASC';
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('iss', $campaignId, $dateFrom, $dateTo);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function queryPaths(int $campaignId, string $dateFrom, string $dateTo, ?string $nodePrefix): array
    {
        $sql = 'SELECT path_key,
                       SUM(visitors) AS visitors,
                       SUM(offer_clicks) AS offer_clicks,
                       SUM(conversions) AS conversions,
                       SUM(revenue) AS revenue,
                       SUM(cost) AS cost
                FROM clicks_funnel_path_daily
                WHERE campaign_id = ? AND summary_date >= ? AND summary_date <= ?';
        $types = 'iss';
        $params = [$campaignId, $dateFrom, $dateTo];
        if ($nodePrefix !== null && $nodePrefix !== '') {
            $sql .= ' AND path_key LIKE ?';
            $types .= 's';
            $params[] = '%' . $this->escapeLike($nodePrefix) . '%';
        }
        $sql .= ' GROUP BY path_key ORDER BY visitors DESC LIMIT 200';

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $visitors = (int) ($row['visitors'] ?? 0);
            $convs = (int) ($row['conversions'] ?? 0);
            $row['cr'] = $visitors > 0 ? round(100.0 * $convs / $visitors, 2) : 0.0;
            $row['path_label'] = $this->prettyPath((string) $row['path_key']);
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $steps
     * @return list<array<string,mixed>>
     */
    private function aggregateLevels(array $steps): array
    {
        $byLevel = [];
        foreach ($steps as $s) {
            $level = (int) ($s['level'] ?? 0);
            if (!isset($byLevel[$level])) {
                $byLevel[$level] = [
                    'level' => $level,
                    'label' => $level === 0 ? 'Offers' : ('Level ' . $level),
                    'entries' => 0,
                    'continues' => 0,
                    'offer_clicks' => 0,
                    'conversions' => 0,
                    'revenue' => 0.0,
                    'cost' => 0.0,
                ];
            }
            $byLevel[$level]['entries'] += (int) ($s['entries'] ?? 0);
            $byLevel[$level]['continues'] += (int) ($s['continues'] ?? 0);
            $byLevel[$level]['offer_clicks'] += (int) ($s['offer_clicks'] ?? 0);
            $byLevel[$level]['conversions'] += (int) ($s['conversions'] ?? 0);
            $byLevel[$level]['revenue'] += (float) ($s['revenue'] ?? 0);
            $byLevel[$level]['cost'] += (float) ($s['cost'] ?? 0);
        }
        ksort($byLevel);
        $out = [];
        foreach ($byLevel as $row) {
            $e = (int) $row['entries'];
            $c = (int) $row['continues'];
            $row['drop_off'] = max(0, $e - $c);
            $row['drop_off_pct'] = $e > 0 ? round(100.0 * $row['drop_off'] / $e, 1) : 0.0;
            $out[] = $row;
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $steps
     * @return array{lp: array<int,string>, offer: array<int,string>}
     */
    private function resolveNames(array $steps): array
    {
        $lpIds = [];
        $offerIds = [];
        foreach ($steps as $s) {
            $lp = (int) ($s['landing_page_id'] ?? 0);
            $off = (int) ($s['offer_id'] ?? 0);
            if ($lp > 0) {
                $lpIds[$lp] = true;
            }
            if ($off > 0) {
                $offerIds[$off] = true;
            }
        }
        $lpNames = [];
        if ($lpIds !== []) {
            $ids = implode(',', array_map('intval', array_keys($lpIds)));
            $res = $this->db->query("SELECT id, name FROM landing_pages WHERE id IN ($ids)");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $lpNames[(int) $row['id']] = (string) $row['name'];
                }
            }
        }
        $offerNames = [];
        if ($offerIds !== []) {
            $ids = implode(',', array_map('intval', array_keys($offerIds)));
            $res = $this->db->query("SELECT id, name FROM offers WHERE id IN ($ids)");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $offerNames[(int) $row['id']] = (string) $row['name'];
                }
            }
        }
        return ['lp' => $lpNames, 'offer' => $offerNames];
    }

    private function prettyPath(string $pathKey): string
    {
        $parts = explode('>', $pathKey);
        $labels = [];
        foreach ($parts as $p) {
            if (str_starts_with($p, 'o:')) {
                $labels[] = 'Offer slot ' . substr($p, 2);
            } else {
                $bits = explode(':', $p);
                $labels[] = 'L' . ($bits[0] ?? '?') . ' slot ' . ($bits[1] ?? '?');
            }
        }
        return implode(' → ', $labels);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
