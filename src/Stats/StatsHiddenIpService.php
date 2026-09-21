<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

use SimpleKuma\Database\ClicksTableResolver;
use SimpleKuma\GeoIP\IpUtils;
use SimpleKuma\Tracking\DailySummaryUpdater;

/**
 * Account-wide "hide IP from stats views" list (does not block traffic).
 */
final class StatsHiddenIpService
{
    /** @var list<string>|null */
    private static ?array $ipCache = null;

    private \mysqli $db;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
    }

    public function tableExists(): bool
    {
        $result = $this->db->query(
            "SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stats_hidden_ips' LIMIT 1"
        );

        return $result && $result->num_rows > 0;
    }

    /**
     * @return list<array{id: int, ip: string, note: ?string, created_at: string, created_by: ?int}>
     */
    public function list(): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $rows = [];
        $result = $this->db->query(
            'SELECT id, ip, note, created_at, created_by FROM stats_hidden_ips ORDER BY created_at DESC, id DESC'
        );
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = [
                    'id' => (int) $row['id'],
                    'ip' => (string) $row['ip'],
                    'note' => $row['note'] !== null ? (string) $row['note'] : null,
                    'created_at' => (string) $row['created_at'],
                    'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
                ];
            }
            $result->free();
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    public function listIps(): array
    {
        if (self::$ipCache !== null) {
            return self::$ipCache;
        }
        if (!$this->tableExists()) {
            self::$ipCache = [];

            return self::$ipCache;
        }

        $ips = [];
        $result = $this->db->query('SELECT ip FROM stats_hidden_ips');
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $ip = trim((string) ($row['ip'] ?? ''));
                if ($ip !== '') {
                    $ips[] = $ip;
                }
            }
            $result->free();
        }
        self::$ipCache = $ips;

        return self::$ipCache;
    }

    public function isHidden(string $ip): bool
    {
        $ip = trim($ip);
        if ($ip === '') {
            return false;
        }
        $hidden = $this->listIps();
        if ($hidden === []) {
            return false;
        }
        if (in_array($ip, $hidden, true)) {
            return true;
        }
        // Also match anonymized form when privacy masking was used at write time.
        $anonymized = $this->anonymizeIp($ip);
        if ($anonymized !== '' && $anonymized !== $ip && in_array($anonymized, $hidden, true)) {
            return true;
        }

        return false;
    }

    /**
     * SQL fragment: alias.ip NOT IN (...). Empty when list is empty / table missing.
     */
    public function exclusionSql(string $alias = 'cl'): string
    {
        $ips = $this->listIps();
        if ($ips === []) {
            return '';
        }
        $escaped = [];
        foreach ($ips as $ip) {
            $escaped[] = "'" . $this->db->real_escape_string($ip) . "'";
        }

        return "{$alias}.ip NOT IN (" . implode(',', $escaped) . ')';
    }

    /**
     * @return array{ok: bool, already: bool, clicks_adjusted: int, conversions_adjusted: int, error?: string}
     */
    public function add(string $ip, ?string $note = null, ?int $createdBy = null): array
    {
        $ip = trim($ip);
        if ($ip === '' || !IpUtils::isValidIp($ip)) {
            return ['ok' => false, 'already' => false, 'clicks_adjusted' => 0, 'conversions_adjusted' => 0, 'error' => 'Invalid IP address'];
        }
        if (!$this->tableExists()) {
            return ['ok' => false, 'already' => false, 'clicks_adjusted' => 0, 'conversions_adjusted' => 0, 'error' => 'Run migration 080 first'];
        }
        if ($this->isHidden($ip)) {
            return ['ok' => true, 'already' => true, 'clicks_adjusted' => 0, 'conversions_adjusted' => 0];
        }

        $noteVal = $note !== null ? mb_substr(trim($note), 0, 255) : null;
        if ($noteVal === '') {
            $noteVal = null;
        }

        if ($createdBy === null) {
            $stmt = $this->db->prepare(
                'INSERT INTO stats_hidden_ips (ip, note, created_by) VALUES (?, ?, NULL)'
            );
            if (!$stmt) {
                return ['ok' => false, 'already' => false, 'clicks_adjusted' => 0, 'conversions_adjusted' => 0, 'error' => $this->db->error];
            }
            $stmt->bind_param('ss', $ip, $noteVal);
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO stats_hidden_ips (ip, note, created_by) VALUES (?, ?, ?)'
            );
            if (!$stmt) {
                return ['ok' => false, 'already' => false, 'clicks_adjusted' => 0, 'conversions_adjusted' => 0, 'error' => $this->db->error];
            }
            $stmt->bind_param('ssi', $ip, $noteVal, $createdBy);
        }
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            // Race: unique key
            if (stripos($err, 'Duplicate') !== false) {
                self::clearCache();

                return ['ok' => true, 'already' => true, 'clicks_adjusted' => 0, 'conversions_adjusted' => 0];
            }

            return ['ok' => false, 'already' => false, 'clicks_adjusted' => 0, 'conversions_adjusted' => 0, 'error' => $err];
        }
        $stmt->close();
        self::clearCache();

        $adjusted = $this->omitIpFromAggregates($ip);
        TimezoneSummaryBlend::clearReliabilityMemo();

        return [
            'ok' => true,
            'already' => false,
            'clicks_adjusted' => $adjusted['clicks'],
            'conversions_adjusted' => $adjusted['conversions'],
        ];
    }

    /**
     * @return array{ok: bool, clicks_restored: int, conversions_restored: int, error?: string}
     */
    public function remove(string $ip): array
    {
        $ip = trim($ip);
        if ($ip === '') {
            return ['ok' => false, 'clicks_restored' => 0, 'conversions_restored' => 0, 'error' => 'IP is required'];
        }
        if (!$this->tableExists()) {
            return ['ok' => false, 'clicks_restored' => 0, 'conversions_restored' => 0, 'error' => 'Run migration 080 first'];
        }

        $stmt = $this->db->prepare('DELETE FROM stats_hidden_ips WHERE ip = ?');
        if (!$stmt) {
            return ['ok' => false, 'clicks_restored' => 0, 'conversions_restored' => 0, 'error' => $this->db->error];
        }
        $stmt->bind_param('s', $ip);
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();
        self::clearCache();

        if ($deleted <= 0) {
            return ['ok' => true, 'clicks_restored' => 0, 'conversions_restored' => 0];
        }

        $restored = $this->restoreIpToAggregates($ip);
        TimezoneSummaryBlend::clearReliabilityMemo();

        return [
            'ok' => true,
            'clicks_restored' => $restored['clicks'],
            'conversions_restored' => $restored['conversions'],
        ];
    }

    public function removeById(int $id): array
    {
        if ($id <= 0 || !$this->tableExists()) {
            return ['ok' => false, 'clicks_restored' => 0, 'conversions_restored' => 0, 'error' => 'Invalid id'];
        }
        $stmt = $this->db->prepare('SELECT ip FROM stats_hidden_ips WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return ['ok' => false, 'clicks_restored' => 0, 'conversions_restored' => 0, 'error' => $this->db->error];
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return ['ok' => true, 'clicks_restored' => 0, 'conversions_restored' => 0];
        }

        return $this->remove((string) $row['ip']);
    }

    public static function clearCache(): void
    {
        self::$ipCache = null;
    }

    /**
     * @return array{clicks: int, conversions: int}
     */
    private function omitIpFromAggregates(string $ip): array
    {
        $updater = new DailySummaryUpdater($this->db);
        $clickIds = $this->findClickIdsByIp($ip);
        $conversions = 0;
        foreach ($clickIds as $clickId) {
            $conversions += $this->omitConversionsForClick($updater, $clickId);
            $updater->removeClick($clickId);
        }

        return ['clicks' => count($clickIds), 'conversions' => $conversions];
    }

    /**
     * @return array{clicks: int, conversions: int}
     */
    private function restoreIpToAggregates(string $ip): array
    {
        $updater = new DailySummaryUpdater($this->db);
        $clickIds = $this->findClickIdsByIp($ip);
        $conversions = 0;
        foreach ($clickIds as $clickId) {
            $updater->restoreClick($clickId);
            $conversions += $this->restoreConversionsForClick($updater, $clickId);
        }

        return ['clicks' => count($clickIds), 'conversions' => $conversions];
    }

    private function omitConversionsForClick(DailySummaryUpdater $updater, string $clickId): int
    {
        $count = 0;
        $stmt = $this->db->prepare('SELECT payout, value, event_key FROM conversions WHERE click_id = ?');
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('s', $clickId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $payout = $row['payout'] !== null ? (float) $row['payout'] : null;
            $value = $row['value'] !== null ? (float) $row['value'] : null;
            $eventKey = isset($row['event_key']) ? (string) $row['event_key'] : null;
            $updater->removeConversion($clickId, $payout, $value, $eventKey);
            $count++;
        }
        $stmt->close();

        return $count;
    }

    private function restoreConversionsForClick(DailySummaryUpdater $updater, string $clickId): int
    {
        $count = 0;
        $stmt = $this->db->prepare('SELECT payout, value, event_key FROM conversions WHERE click_id = ?');
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('s', $clickId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $payout = $row['payout'] !== null ? (float) $row['payout'] : null;
            $value = $row['value'] !== null ? (float) $row['value'] : null;
            $eventKey = isset($row['event_key']) ? (string) $row['event_key'] : null;
            $updater->upsertConversion($clickId, $payout, $value, $eventKey);
            $count++;
        }
        $stmt->close();

        return $count;
    }

    /**
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
            foreach (ClicksTableResolver::getClickLookupTables($this->db) as $table) {
                $stmt = $this->db->prepare("SELECT click_id FROM `{$table}` WHERE ip = ?");
                if (!$stmt) {
                    continue;
                }
                $stmt->bind_param('s', $candidate);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $ids[(string) $row['click_id']] = true;
                }
                $stmt->close();
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
            $parts = explode(':', $ip);
            if (count($parts) >= 4) {
                return implode(':', array_slice($parts, 0, 4)) . '::';
            }
        }

        return $ip;
    }
}
