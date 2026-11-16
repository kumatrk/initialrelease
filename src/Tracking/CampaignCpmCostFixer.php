<?php

declare(strict_types=1);

namespace SimpleKuma\Tracking;

use mysqli;
use SimpleKuma\Database\ClicksTableResolver;
use SimpleKuma\Database\SchemaProbe;

/**
 * One-shot ÷1000 fix for campaigns that stored CPM rates as per-click costs.
 *
 * Divides raw click costs and matching pre-agg cost columns for the date range
 * (same assumption as the click rewrite — costs in range were inflated ×1000).
 */
final class CampaignCpmCostFixer
{
    public function __construct(private mysqli $db)
    {
    }

    /**
     * @return array{fixed_at: ?string, fixed_from: ?string, fixed_to: ?string}|null
     *   null when marker columns are missing (pre-migration).
     */
    public function getFixMarker(int $campaignId): ?array
    {
        if (!$this->hasFixMarkerColumns()) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT cpm_history_fixed_at, cpm_history_fixed_from, cpm_history_fixed_to
             FROM campaigns WHERE id = ? LIMIT 1'
        );
        if (!$stmt) {
            return [
                'fixed_at' => null,
                'fixed_from' => null,
                'fixed_to' => null,
            ];
        }
        $stmt->bind_param('i', $campaignId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        return [
            'fixed_at' => isset($row['cpm_history_fixed_at']) && $row['cpm_history_fixed_at'] !== ''
                ? (string) $row['cpm_history_fixed_at']
                : null,
            'fixed_from' => isset($row['cpm_history_fixed_from']) && $row['cpm_history_fixed_from'] !== ''
                ? (string) $row['cpm_history_fixed_from']
                : null,
            'fixed_to' => isset($row['cpm_history_fixed_to']) && $row['cpm_history_fixed_to'] !== ''
                ? (string) $row['cpm_history_fixed_to']
                : null,
        ];
    }

    public function hasBeenFixed(int $campaignId): bool
    {
        $marker = $this->getFixMarker($campaignId);
        if ($marker === null) {
            return false;
        }

        return $marker['fixed_at'] !== null;
    }

    /**
     * @return array{
     *   clicks_updated: int,
     *   archive_updated: int,
     *   summary_updated: int,
     *   token_daily_updated: int,
     *   token_hourly_updated: int,
     *   old_cost_sum: float,
     *   new_cost_sum: float,
     *   dates_touched: list<string>,
     *   fixed_at: ?string
     * }
     */
    public function fixHistory(int $campaignId, string $dateFrom, string $dateTo, bool $allowRerun = false): array
    {
        if ($campaignId < 1) {
            throw new \InvalidArgumentException('Invalid campaign ID');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            throw new \InvalidArgumentException('Dates must be YYYY-MM-DD');
        }
        if ($dateFrom > $dateTo) {
            throw new \InvalidArgumentException('date_from must be on or before date_to');
        }

        if (!$allowRerun && $this->hasBeenFixed($campaignId)) {
            $marker = $this->getFixMarker($campaignId);
            $when = $marker['fixed_at'] ?? 'previously';
            throw new \RuntimeException(
                "CPM history was already fixed on {$when}. Check “Allow run again” only if you intentionally need another ÷1000."
            );
        }

        $fromTs = $dateFrom . ' 00:00:00';
        $toExclusive = (new \DateTimeImmutable($dateTo, new \DateTimeZone('UTC')))
            ->modify('+1 day')
            ->format('Y-m-d 00:00:00');

        $dates = $this->listAffectedDates($campaignId, $fromTs, $toExclusive);
        $oldSum = $this->sumClickCost($campaignId, $fromTs, $toExclusive);

        $this->db->begin_transaction();
        try {
            $clicksUpdated = $this->divideCostInTable('clicks', $campaignId, $fromTs, $toExclusive);
            $archiveUpdated = 0;
            if (ClicksTableResolver::archiveTableExists($this->db)) {
                $archiveUpdated = $this->divideCostInTable('clicks_archive', $campaignId, $fromTs, $toExclusive);
            }

            $summaryUpdated = $this->divideSummaryCosts($campaignId, $dateFrom, $dateTo);
            $tokenDaily = $this->divideTokenTableCosts('clicks_stats_by_token_daily', $campaignId, $dateFrom, $dateTo);
            $tokenHourly = $this->divideTokenTableCosts('clicks_stats_by_token_hourly', $campaignId, $dateFrom, $dateTo);
            $this->markFixed($campaignId, $dateFrom, $dateTo);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        $newSum = $this->sumClickCost($campaignId, $fromTs, $toExclusive);
        $marker = $this->getFixMarker($campaignId);

        return [
            'clicks_updated' => $clicksUpdated,
            'archive_updated' => $archiveUpdated,
            'summary_updated' => $summaryUpdated,
            'token_daily_updated' => $tokenDaily,
            'token_hourly_updated' => $tokenHourly,
            'old_cost_sum' => $oldSum,
            'new_cost_sum' => $newSum,
            'dates_touched' => $dates,
            'fixed_at' => $marker['fixed_at'] ?? null,
        ];
    }

    private function markFixed(int $campaignId, string $dateFrom, string $dateTo): void
    {
        if (!$this->hasFixMarkerColumns()) {
            return;
        }
        $stmt = $this->db->prepare(
            'UPDATE campaigns
             SET cpm_history_fixed_at = UTC_TIMESTAMP(),
                 cpm_history_fixed_from = ?,
                 cpm_history_fixed_to = ?
             WHERE id = ?'
        );
        if (!$stmt) {
            throw new \RuntimeException('Could not prepare fix marker update: ' . $this->db->error);
        }
        $stmt->bind_param('ssi', $dateFrom, $dateTo, $campaignId);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('Fix marker update failed: ' . $err);
        }
        $stmt->close();
    }

    private function hasFixMarkerColumns(): bool
    {
        return SchemaProbe::columnExists($this->db, 'campaigns', 'cpm_history_fixed_at');
    }

    private function divideCostInTable(string $table, int $campaignId, string $fromTs, string $toExclusive): int
    {
        if (!SchemaProbe::tableExists($this->db, $table)) {
            return 0;
        }
        $stmt = $this->db->prepare(
            "UPDATE `{$table}`
             SET cost = cost / 1000
             WHERE campaign_id = ?
               AND ts >= ?
               AND ts < ?
               AND cost IS NOT NULL
               AND cost > 0"
        );
        if (!$stmt) {
            throw new \RuntimeException('Could not prepare cost update: ' . $this->db->error);
        }
        $stmt->bind_param('iss', $campaignId, $fromTs, $toExclusive);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('Cost update failed: ' . $err);
        }
        $n = $stmt->affected_rows;
        $stmt->close();

        return max(0, $n);
    }

    private function divideSummaryCosts(int $campaignId, string $dateFrom, string $dateTo): int
    {
        if (!SchemaProbe::tableExists($this->db, 'clicks_daily_summary')) {
            return 0;
        }
        // RHS uses pre-update cost values in MySQL.
        $stmt = $this->db->prepare(
            'UPDATE clicks_daily_summary
             SET cost = cost / 1000,
                 profit = revenue - (cost / 1000),
                 roi = CASE
                     WHEN cost > 0 THEN ((revenue - (cost / 1000)) / (cost / 1000)) * 100
                     ELSE NULL
                 END
             WHERE campaign_id = ?
               AND summary_date >= ?
               AND summary_date <= ?
               AND cost IS NOT NULL
               AND cost > 0'
        );
        if (!$stmt) {
            throw new \RuntimeException('Could not prepare summary cost update: ' . $this->db->error);
        }
        $stmt->bind_param('iss', $campaignId, $dateFrom, $dateTo);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('Summary cost update failed: ' . $err);
        }
        $n = $stmt->affected_rows;
        $stmt->close();

        return max(0, $n);
    }

    private function divideTokenTableCosts(string $table, int $campaignId, string $dateFrom, string $dateTo): int
    {
        if (!SchemaProbe::tableExists($this->db, $table)) {
            return 0;
        }
        if (!SchemaProbe::columnExists($this->db, $table, 'cost')) {
            return 0;
        }
        $stmt = $this->db->prepare(
            "UPDATE `{$table}`
             SET cost = cost / 1000
             WHERE campaign_id = ?
               AND summary_date >= ?
               AND summary_date <= ?
               AND cost IS NOT NULL
               AND cost > 0"
        );
        if (!$stmt) {
            throw new \RuntimeException("Could not prepare {$table} cost update: " . $this->db->error);
        }
        $stmt->bind_param('iss', $campaignId, $dateFrom, $dateTo);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException("{$table} cost update failed: " . $err);
        }
        $n = $stmt->affected_rows;
        $stmt->close();

        return max(0, $n);
    }

    private function sumClickCost(int $campaignId, string $fromTs, string $toExclusive): float
    {
        $total = 0.0;
        foreach (ClicksTableResolver::getClickLookupTables($this->db) as $table) {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(cost), 0) AS s
                 FROM `{$table}`
                 WHERE campaign_id = ? AND ts >= ? AND ts < ?
                   AND cost IS NOT NULL"
            );
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param('iss', $campaignId, $fromTs, $toExclusive);
            $stmt->execute();
            $total += (float) ($stmt->get_result()->fetch_assoc()['s'] ?? 0);
            $stmt->close();
        }

        return $total;
    }

    /**
     * @return list<string>
     */
    private function listAffectedDates(int $campaignId, string $fromTs, string $toExclusive): array
    {
        $dates = [];
        foreach (ClicksTableResolver::getClickLookupTables($this->db) as $table) {
            $stmt = $this->db->prepare(
                "SELECT DISTINCT DATE(ts) AS d
                 FROM `{$table}`
                 WHERE campaign_id = ? AND ts >= ? AND ts < ?
                 ORDER BY d ASC"
            );
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param('iss', $campaignId, $fromTs, $toExclusive);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $d = (string) ($row['d'] ?? '');
                if ($d !== '') {
                    $dates[$d] = true;
                }
            }
            $stmt->close();
        }
        $list = array_keys($dates);
        sort($list);

        return $list;
    }
}
