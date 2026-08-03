<?php

declare(strict_types=1);

namespace SimpleKuma\Database;

use mysqli;

/**
 * Aligns clicks_archive schema with clicks and rebuilds clicks_unified for reporting.
 */
class ClicksUnifiedViewSync
{
    public static function sync(mysqli $db): array
    {
        $messages = [];

        if (!self::tableExists($db, 'clicks')) {
            return ['success' => false, 'messages' => ['clicks table missing']];
        }

        if (!self::tableExists($db, 'clicks_archive')) {
            return ['success' => false, 'messages' => ['clicks_archive table missing — run migration 034']];
        }

        self::ensureArchiveColumns($db, $messages);
        self::rebuildUnifiedView($db, $messages);

        return ['success' => true, 'messages' => $messages];
    }

    private static function ensureArchiveColumns(mysqli $db, array &$messages): void
    {
        $alterations = [
            "ADD COLUMN slug_id INT UNSIGNED NULL AFTER campaign_id",
            "ADD COLUMN postal VARCHAR(20) NULL COMMENT 'Postal/ZIP code' AFTER city",
            "ADD COLUMN device_brand VARCHAR(50) NULL AFTER device",
            "ADD COLUMN device_model VARCHAR(100) NULL AFTER device_brand",
            "ADD COLUMN os_version VARCHAR(20) NULL AFTER os",
            "ADD COLUMN browser_version VARCHAR(20) NULL AFTER browser",
            "ADD COLUMN ad_id BIGINT UNSIGNED GENERATED ALWAYS AS (
                CAST(JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.ad_id')) AS UNSIGNED)
            ) STORED",
            "ADD COLUMN adset_id BIGINT UNSIGNED GENERATED ALWAYS AS (
                CAST(JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.adset_id')) AS UNSIGNED)
            ) STORED",
            "ADD COLUMN ad_name_value VARCHAR(255) GENERATED ALWAYS AS (
                JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.ad_name'))
            ) STORED",
            "ADD COLUMN adset_name_value VARCHAR(255) GENERATED ALWAYS AS (
                JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.adset_name'))
            ) STORED",
            "ADD COLUMN exclude_from_stats TINYINT(1) NOT NULL DEFAULT 0
                COMMENT '1 = omit from reporting until a conversion proves the click real'
                AFTER extra_json",
        ];

        foreach ($alterations as $fragment) {
            if (preg_match('/ADD COLUMN (\w+)/', $fragment, $m)) {
                $col = $m[1];
                if (self::columnExists($db, 'clicks_archive', $col)) {
                    continue;
                }
            }
            $sql = 'ALTER TABLE clicks_archive ' . $fragment;
            if ($db->query($sql)) {
                if (isset($col)) {
                    $messages[] = "Added clicks_archive.{$col}";
                }
            } else {
                $messages[] = 'Warning: ' . $db->error . ' — ' . substr($fragment, 0, 60);
            }
        }
    }

    private static function rebuildUnifiedView(mysqli $db, array &$messages): void
    {
        $clickCols = self::getColumnNames($db, 'clicks');
        $archiveCols = self::getColumnNames($db, 'clicks_archive');
        $shared = array_values(array_intersect($clickCols, $archiveCols));

        if ($shared === []) {
            $messages[] = 'No shared columns between clicks and clicks_archive';
            return;
        }

        $colList = implode(', ', array_map(static fn (string $c) => "`{$c}`", $shared));
        $db->query('DROP VIEW IF EXISTS clicks_unified');
        $sql = "CREATE VIEW clicks_unified AS
            SELECT {$colList} FROM clicks
            UNION ALL
            SELECT {$colList} FROM clicks_archive";
        if ($db->query($sql)) {
            $messages[] = 'Rebuilt clicks_unified (' . count($shared) . ' columns)';
        } else {
            $messages[] = 'Failed to rebuild clicks_unified: ' . $db->error;
        }
    }

    private static function getColumnNames(mysqli $db, string $table): array
    {
        $cols = [];
        $result = $db->query('SHOW COLUMNS FROM `' . $db->real_escape_string($table) . '`');
        while ($row = $result->fetch_assoc()) {
            $cols[] = $row['Field'];
        }
        return $cols;
    }

    private static function columnExists(mysqli $db, string $table, string $column): bool
    {
        $dbName = $db->query('SELECT DATABASE()')->fetch_row()[0] ?? '';
        $stmt = $db->prepare(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
        );
        $stmt->bind_param('sss', $dbName, $table, $column);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
        return $exists;
    }

    private static function tableExists(mysqli $db, string $table): bool
    {
        $result = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
        return $result && $result->num_rows > 0;
    }
}
