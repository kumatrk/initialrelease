<?php

declare(strict_types=1);

namespace SimpleKuma\Database\Migrations;

use mysqli;

/**
 * Migration 091 — ISP, connection type, and browser language on clicks / clicks_archive.
 * Idempotent: skips columns and indexes that already exist (partial re-runs safe).
 */
final class AddClicksIspConnectionLanguage
{
    public static function run(mysqli $db): ?string
    {
        if (!self::tableExists($db, 'clicks')) {
            return 'clicks table is missing';
        }

        $error = self::ensureClicksColumns($db);
        if ($error !== null) {
            return $error;
        }

        $error = self::ensureClicksIndexes($db);
        if ($error !== null) {
            return $error;
        }

        if (self::tableExists($db, 'clicks_archive')) {
            $error = self::ensureArchiveColumns($db);
            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    private static function ensureClicksColumns(mysqli $db): ?string
    {
        $cols = self::columnMap($db, 'clicks');
        $parts = [];

        if (!isset($cols['isp'])) {
            $after = isset($cols['postal']) ? 'AFTER postal' : 'AFTER city';
            $parts[] = "ADD COLUMN isp VARCHAR(255) NULL COMMENT 'ISP / ASN organization' {$after}";
        }
        if (!isset($cols['connection_type'])) {
            $haveIsp = isset($cols['isp']) || self::partsAddColumn($parts, 'isp');
            $after = $haveIsp ? 'AFTER isp' : (isset($cols['postal']) ? 'AFTER postal' : 'AFTER city');
            $parts[] = "ADD COLUMN connection_type VARCHAR(32) NULL COMMENT 'Cellular, Broadband, Corporate, or Unknown' {$after}";
        }
        if (!isset($cols['language'])) {
            $haveConn = isset($cols['connection_type']) || self::partsAddColumn($parts, 'connection_type');
            $haveIsp = isset($cols['isp']) || self::partsAddColumn($parts, 'isp');
            if ($haveConn) {
                $after = 'AFTER connection_type';
            } elseif ($haveIsp) {
                $after = 'AFTER isp';
            } else {
                $after = isset($cols['postal']) ? 'AFTER postal' : 'AFTER city';
            }
            $parts[] = "ADD COLUMN language VARCHAR(16) NULL COMMENT 'Primary Accept-Language tag' {$after}";
        }

        if ($parts === []) {
            return null;
        }

        $sql = 'ALTER TABLE clicks ' . implode(', ', $parts);
        return $db->query($sql) ? null : "Could not add clicks columns: {$db->error}";
    }

    private static function ensureClicksIndexes(mysqli $db): ?string
    {
        $indexes = [
            'idx_clicks_campaign_isp' => 'ADD INDEX idx_clicks_campaign_isp (campaign_id, isp(64))',
            'idx_clicks_campaign_connection_type' => 'ADD INDEX idx_clicks_campaign_connection_type (campaign_id, connection_type)',
            'idx_clicks_campaign_language' => 'ADD INDEX idx_clicks_campaign_language (campaign_id, language)',
        ];

        foreach ($indexes as $name => $fragment) {
            if (self::indexExists($db, 'clicks', $name)) {
                continue;
            }
            if (!$db->query('ALTER TABLE clicks ' . $fragment)) {
                // Concurrent apply or duplicate key name — treat as already present
                if (stripos($db->error, 'Duplicate') !== false) {
                    continue;
                }
                return "Could not add clicks index {$name}: {$db->error}";
            }
        }

        return null;
    }

    private static function ensureArchiveColumns(mysqli $db): ?string
    {
        $cols = self::columnMap($db, 'clicks_archive');
        $parts = [];
        $haveIsp = isset($cols['isp']);
        $haveConn = isset($cols['connection_type']);

        if (!$haveIsp) {
            $parts[] = "ADD COLUMN isp VARCHAR(255) NULL COMMENT 'ISP / ASN organization' AFTER city";
            $haveIsp = true;
        }
        if (!$haveConn) {
            $parts[] = "ADD COLUMN connection_type VARCHAR(32) NULL COMMENT 'Cellular, Broadband, Corporate, or Unknown' "
                . ($haveIsp ? 'AFTER isp' : 'AFTER city');
            $haveConn = true;
        }
        if (!isset($cols['language'])) {
            $parts[] = "ADD COLUMN language VARCHAR(16) NULL COMMENT 'Primary Accept-Language tag' "
                . ($haveConn ? 'AFTER connection_type' : ($haveIsp ? 'AFTER isp' : 'AFTER city'));
        }

        if ($parts === []) {
            return null;
        }

        $sql = 'ALTER TABLE clicks_archive ' . implode(', ', $parts);
        return $db->query($sql) ? null : "Could not add clicks_archive columns: {$db->error}";
    }

    /**
     * @return array<string, true>
     */
    private static function columnMap(mysqli $db, string $table): array
    {
        $map = [];
        $result = $db->query("SHOW COLUMNS FROM `{$table}`");
        if ($result === false) {
            return $map;
        }
        while ($row = $result->fetch_assoc()) {
            $map[$row['Field']] = true;
        }
        return $map;
    }

    private static function tableExists(mysqli $db, string $table): bool
    {
        $escaped = $db->real_escape_string($table);
        $result = $db->query("SHOW TABLES LIKE '{$escaped}'");
        return $result !== false && $result->num_rows > 0;
    }

    private static function indexExists(mysqli $db, string $table, string $indexName): bool
    {
        $tableEsc = $db->real_escape_string($table);
        $nameEsc = $db->real_escape_string($indexName);
        $result = $db->query("SHOW INDEX FROM `{$tableEsc}` WHERE Key_name = '{$nameEsc}'");
        return $result !== false && $result->num_rows > 0;
    }

    /**
     * @param list<string> $parts
     */
    private static function partsAddColumn(array $parts, string $column): bool
    {
        foreach ($parts as $part) {
            if (preg_match('/ADD COLUMN ' . preg_quote($column, '/') . '\b/', $part) === 1) {
                return true;
            }
        }
        return false;
    }
}
