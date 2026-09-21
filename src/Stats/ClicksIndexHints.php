<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

use mysqli;

/**
 * Optional FORCE INDEX hints for hot reporting joins.
 * Covering indexes avoid PK lookups into fat click rows (extra_json, etc.).
 */
final class ClicksIndexHints
{
    private const CLICK_ID_COVER = 'idx_clicks_click_id_cover_stats';

    /** @var array<string, bool> */
    private static array $indexCache = [];

    public static function clickIdCoverAlias(mysqli $db, string $alias = 'cl', string $table = 'clicks'): string
    {
        if ($table !== 'clicks' || !self::indexExists($db, self::CLICK_ID_COVER)) {
            return $alias;
        }

        return "{$alias} FORCE INDEX (" . self::CLICK_ID_COVER . ')';
    }

    public static function clearCache(): void
    {
        self::$indexCache = [];
    }

    private static function indexExists(mysqli $db, string $indexName): bool
    {
        $key = spl_object_id($db) . ':' . $indexName;
        if (array_key_exists($key, self::$indexCache)) {
            return self::$indexCache[$key];
        }

        $stmt = $db->prepare(
            "SELECT 1
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'clicks'
               AND INDEX_NAME = ?
             LIMIT 1"
        );
        if ($stmt === false) {
            return self::$indexCache[$key] = false;
        }
        $stmt->bind_param('s', $indexName);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();

        return self::$indexCache[$key] = $exists;
    }
}
