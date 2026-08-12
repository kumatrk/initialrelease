<?php

declare(strict_types=1);

namespace SimpleKuma\DataRetention;

use mysqli;
use SimpleKuma\Database\ClicksTableResolver;
use SimpleKuma\Tracking\DailySummaryUpdater;

/**
 * Settings → Data Management destructive operations.
 * Keeps clicks, archive, conversions, postback logs, and daily aggregates consistent.
 */
class DataManagementCleanup
{
    private mysqli $db;
    private DailySummaryUpdater $summaryUpdater;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->summaryUpdater = new DailySummaryUpdater($db);
    }

    /**
     * @return array{conversions_deleted: int}
     */
    public function deleteConversionsByCampaign(int $campaignId): array
    {
        $this->db->begin_transaction();
        try {
            $clickIds = $this->findClickIdsByCampaign($campaignId);
            if ($clickIds === []) {
                $this->db->commit();
                return ['conversions_deleted' => 0];
            }

            $deleted = $this->deleteConversionsForClickIds($clickIds);
            $this->db->commit();
            return ['conversions_deleted' => $deleted];
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Delete conversions by click_id list. Does NOT require the click row to still exist.
     *
     * @param list<string> $clickIds
     * @return array{conversions_deleted: int, click_ids_with_conversions: int, not_found: int}
     */
    public function deleteConversionsByClickIds(array $clickIds): array
    {
        $clickIds = $this->normalizeClickIds($clickIds);
        if ($clickIds === []) {
            return [
                'conversions_deleted' => 0,
                'click_ids_with_conversions' => 0,
                'not_found' => 0,
            ];
        }

        $this->db->begin_transaction();
        try {
            $existingWithConversions = $this->clickIdsThatHaveConversions($clickIds);
            $deleted = $this->deleteConversionsForClickIds($clickIds);

            $this->db->commit();
            return [
                'conversions_deleted' => $deleted,
                'click_ids_with_conversions' => count($existingWithConversions),
                'not_found' => max(0, count($clickIds) - count($existingWithConversions)),
            ];
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * @return array{clicks_deleted: int, conversions_deleted: int, archive_deleted: int}
     */
    public function deleteClicksByCampaign(int $campaignId): array
    {
        $this->db->begin_transaction();
        try {
            $clickIds = $this->findClickIdsByCampaign($campaignId);
            // Skip per-row aggregate adjust — campaign summaries are purged below
            $result = $this->deleteClicksAndRelated($clickIds, false);
            $this->purgeSummariesForCampaign($campaignId);
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * @return array{clicks_deleted: int, conversions_deleted: int, archive_deleted: int}
     */
    public function deleteClicksByIp(string $ip): array
    {
        $this->db->begin_transaction();
        try {
            $clickIds = $this->findClickIdsByIp($ip);
            $result = $this->deleteClicksAndRelated($clickIds);
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Match exact IP plus anonymized form (last IPv4 octet / IPv6 suffix zeroed) when privacy anonymization was used at write time.
     *
     * @return list<string>
     */
    private function findClickIdsByIp(string $ip): array
    {
        $ip = trim($ip);
        if ($ip === '') {
            return [];
        }

        $candidates = [$ip];
        $anonymized = $this->anonymizeIp($ip);
        if ($anonymized !== '' && $anonymized !== $ip) {
            $candidates[] = $anonymized;
        }

        $ids = [];
        foreach ($candidates as $candidate) {
            foreach ($this->findClickIdsByPredicate('ip = ?', 's', [$candidate]) as $clickId) {
                $ids[$clickId] = true;
            }
        }
        return array_keys($ids);
    }

    private function anonymizeIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            if (count($parts) === 4) {
                $parts[3] = '0';
                return implode('.', $parts);
            }
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if ($ip === '::1' || $ip === '::') {
                return $ip;
            }
            $expanded = explode('::', $ip);
            if (count($expanded) === 2) {
                $left = $expanded[0];
                $right = $expanded[1];
                $leftParts = $left !== '' ? explode(':', $left) : [];
                $rightParts = $right !== '' ? explode(':', $right) : [];
                $partsToKeep = min(4, count($leftParts));
                if ($partsToKeep > 0) {
                    return implode(':', array_slice($leftParts, 0, $partsToKeep)) . '::';
                }
                if (count($rightParts) > 0) {
                    return $rightParts[0] . '::';
                }
                return $ip;
            }
            $parts = explode(':', $ip);
            if (count($parts) >= 4) {
                return implode(':', array_slice($parts, 0, 4)) . '::';
            }
        }

        return $ip;
    }

    /**
     * @return array{clicks_deleted: int, conversions_deleted: int, archive_deleted: int}
     */
    public function deleteClicksBySubid(string $param, string $value): array
    {
        $this->db->begin_transaction();
        try {
            $clickIds = $this->findClickIdsBySubid($param, $value);
            $result = $this->deleteClicksAndRelated($clickIds);
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * @return array{clicks_deleted: int, conversions_deleted: int, archive_deleted: int, summaries_cleared: bool}
     */
    public function deleteAllClicks(): array
    {
        $this->db->begin_transaction();
        try {
            $conversionsDeleted = 0;
            if ($this->db->query('DELETE FROM conversions')) {
                $conversionsDeleted = (int) $this->db->affected_rows;
            }

            if ($this->tableExists('postback_logs')) {
                $this->db->query('DELETE FROM postback_logs');
            }

            $clicksDeleted = 0;
            if ($this->db->query('DELETE FROM clicks')) {
                $clicksDeleted = (int) $this->db->affected_rows;
            }

            $archiveDeleted = 0;
            if (ClicksTableResolver::archiveTableExists($this->db)) {
                if ($this->db->query('DELETE FROM clicks_archive')) {
                    $archiveDeleted = (int) $this->db->affected_rows;
                }
            }

            $this->purgeAllSummaries();

            $this->db->commit();

            return [
                'clicks_deleted' => $clicksDeleted,
                'conversions_deleted' => $conversionsDeleted,
                'archive_deleted' => $archiveDeleted,
                'summaries_cleared' => true,
            ];
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * @param list<string> $clickIds
     * @return array{clicks_deleted: int, conversions_deleted: int, archive_deleted: int}
     */
    private function deleteClicksAndRelated(array $clickIds, bool $adjustAggregates = true): array
    {
        if ($clickIds === []) {
            return [
                'clicks_deleted' => 0,
                'conversions_deleted' => 0,
                'archive_deleted' => 0,
            ];
        }

        if ($adjustAggregates) {
            foreach ($clickIds as $clickId) {
                $this->summaryUpdater->removeClick($clickId);
            }
        }

        $conversionsDeleted = $this->deleteConversionsForClickIds($clickIds, $adjustAggregates);
        $this->deletePostbackLogsForClickIds($clickIds);

        $clicksDeleted = $this->deleteFromClickTables($clickIds, 'clicks');
        $archiveDeleted = 0;
        if (ClicksTableResolver::archiveTableExists($this->db)) {
            $archiveDeleted = $this->deleteFromClickTables($clickIds, 'clicks_archive');
        }

        return [
            'clicks_deleted' => $clicksDeleted + $archiveDeleted,
            'conversions_deleted' => $conversionsDeleted,
            'archive_deleted' => $archiveDeleted,
        ];
    }

    /**
     * @param list<string> $clickIds
     */
    private function deleteConversionsForClickIds(array $clickIds, bool $adjustAggregates = true): int
    {
        if ($clickIds === []) {
            return 0;
        }

        $deleted = 0;
        foreach (array_chunk($clickIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $types = str_repeat('s', count($chunk));

            if ($adjustAggregates) {
                $select = $this->db->prepare(
                    "SELECT click_id, payout, value, event_key FROM conversions WHERE click_id IN ({$placeholders})"
                );
                if ($select) {
                    $select->bind_param($types, ...$chunk);
                    $select->execute();
                    $result = $select->get_result();
                    while ($row = $result->fetch_assoc()) {
                        $payout = $row['payout'] !== null ? (float) $row['payout'] : null;
                        $value = $row['value'] !== null ? (float) $row['value'] : null;
                        $eventKey = isset($row['event_key']) ? (string) $row['event_key'] : null;
                        $this->summaryUpdater->removeConversion((string) $row['click_id'], $payout, $value, $eventKey);
                    }
                    $select->close();
                }
            }

            $delete = $this->db->prepare("DELETE FROM conversions WHERE click_id IN ({$placeholders})");
            if ($delete) {
                $delete->bind_param($types, ...$chunk);
                $delete->execute();
                $deleted += (int) $delete->affected_rows;
                $delete->close();
            }
        }

        return $deleted;
    }

    /**
     * @param list<string> $clickIds
     */
    private function deletePostbackLogsForClickIds(array $clickIds): void
    {
        if ($clickIds === [] || !$this->tableExists('postback_logs')) {
            return;
        }

        foreach (array_chunk($clickIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $types = str_repeat('s', count($chunk));
            $stmt = $this->db->prepare("DELETE FROM postback_logs WHERE click_id IN ({$placeholders})");
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param($types, ...$chunk);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * @param list<string> $clickIds
     */
    private function deleteFromClickTables(array $clickIds, string $table): int
    {
        $deleted = 0;
        foreach (array_chunk($clickIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $types = str_repeat('s', count($chunk));
            $stmt = $this->db->prepare("DELETE FROM `{$table}` WHERE click_id IN ({$placeholders})");
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param($types, ...$chunk);
            $stmt->execute();
            $deleted += (int) $stmt->affected_rows;
            $stmt->close();
        }
        return $deleted;
    }

    /**
     * @return list<string>
     */
    private function findClickIdsByCampaign(int $campaignId): array
    {
        return $this->findClickIdsByPredicate('campaign_id = ?', 'i', [$campaignId]);
    }

    /**
     * @param list<mixed> $params
     * @return list<string>
     */
    private function findClickIdsByPredicate(string $whereSql, string $types, array $params): array
    {
        $ids = [];
        foreach (ClicksTableResolver::getClickLookupTables($this->db) as $table) {
            $stmt = $this->db->prepare("SELECT click_id FROM `{$table}` WHERE {$whereSql}");
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $ids[(string) $row['click_id']] = true;
            }
            $stmt->close();
        }
        return array_keys($ids);
    }

    /**
     * @return list<string>
     */
    private function findClickIdsBySubid(string $param, string $value): array
    {
        $ids = [];
        $where = '(
            JSON_UNQUOTE(JSON_EXTRACT(extra_json, CONCAT(\'$.all_params.\', ?))) = ?
            OR JSON_UNQUOTE(JSON_EXTRACT(extra_json, CONCAT(\'$.traffic_source_tokens.\', ?))) = ?
            OR JSON_UNQUOTE(JSON_EXTRACT(extra_json, CONCAT(\'$.custom_tokens.\', ?, \'.value\'))) = ?
        )';

        foreach (ClicksTableResolver::getClickLookupTables($this->db) as $table) {
            $stmt = $this->db->prepare("SELECT click_id FROM `{$table}` WHERE {$where}");
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param('ssssss', $param, $value, $param, $value, $param, $value);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $ids[(string) $row['click_id']] = true;
            }
            $stmt->close();
        }

        return array_keys($ids);
    }

    /**
     * @param list<string> $clickIds
     * @return list<string>
     */
    private function clickIdsThatHaveConversions(array $clickIds): array
    {
        if ($clickIds === []) {
            return [];
        }

        $found = [];
        foreach (array_chunk($clickIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $types = str_repeat('s', count($chunk));
            $stmt = $this->db->prepare(
                "SELECT DISTINCT click_id FROM conversions WHERE click_id IN ({$placeholders})"
            );
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param($types, ...$chunk);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $found[] = (string) $row['click_id'];
            }
            $stmt->close();
        }

        return $found;
    }

    /**
     * @param list<string> $clickIds
     * @return list<string>
     */
    private function normalizeClickIds(array $clickIds): array
    {
        $out = [];
        foreach ($clickIds as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $out[$id] = true;
            }
        }
        return array_keys($out);
    }

    private function purgeSummariesForCampaign(int $campaignId): void
    {
        if ($this->tableExists('clicks_daily_summary')) {
            $stmt = $this->db->prepare('DELETE FROM clicks_daily_summary WHERE campaign_id = ?');
            if ($stmt) {
                $stmt->bind_param('i', $campaignId);
                $stmt->execute();
                $stmt->close();
            }
        }
        if ($this->tableExists('clicks_stats_by_token_daily')) {
            $stmt = $this->db->prepare('DELETE FROM clicks_stats_by_token_daily WHERE campaign_id = ?');
            if ($stmt) {
                $stmt->bind_param('i', $campaignId);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    private function purgeAllSummaries(): void
    {
        if ($this->tableExists('clicks_daily_summary')) {
            $this->db->query('DELETE FROM clicks_daily_summary');
        }
        if ($this->tableExists('clicks_stats_by_token_daily')) {
            $this->db->query('DELETE FROM clicks_stats_by_token_daily');
        }
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
        return $exists;
    }
}
