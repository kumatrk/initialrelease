<?php

declare(strict_types=1);

namespace SimpleKuma\Database;

use mysqli;

/**
 * Process-wide cache for hot-path table and column existence checks.
 *
 * Keyed by schema, table, and column. A positive answer stays in APCu for a
 * week. A negative answer expires in a minute so a migration that adds a
 * column is picked up without a long stale "missing" result.
 */
final class SchemaProbe
{
    private const APCU_PREFIX = 'sk_schema_v1_';

    private const POSITIVE_TTL = 604800;

    private const NEGATIVE_TTL = 60;

    /** @var array<string, bool> */
    private static array $memory = [];

    private static ?string $schema = null;

    public static function tableExists(mysqli $db, string $table): bool
    {
        return self::remember($db, $table, '', static function () use ($db, $table): ?bool {
            $like = self::likeLiteral($table);
            $result = $db->query("SHOW TABLES LIKE '{$like}'");
            if ($result === false) {
                return null;
            }

            return $result->num_rows > 0;
        });
    }

    public static function columnExists(mysqli $db, string $table, string $column): bool
    {
        return self::remember($db, $table, $column, static function () use ($db, $table, $column): ?bool {
            $like = self::likeLiteral($column);
            $result = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$like}'");
            if ($result === false) {
                return null;
            }

            return $result->num_rows > 0;
        });
    }

    public static function forget(mysqli $db, string $table, string $column): void
    {
        if (!self::isIdentifier($table) || ($column !== '' && !self::isIdentifier($column))) {
            return;
        }
        $key = self::cacheKey($db, $table, $column);
        unset(self::$memory[$key]);
        if (function_exists('apcu_delete')) {
            apcu_delete(self::apcuKey($key));
        }
    }

    /**
     * @param callable(): ?bool $query null means the probe failed and must not be cached
     */
    private static function remember(mysqli $db, string $table, string $column, callable $query): bool
    {
        if (!self::isIdentifier($table) || ($column !== '' && !self::isIdentifier($column))) {
            return false;
        }

        $key = self::cacheKey($db, $table, $column);
        if (array_key_exists($key, self::$memory)) {
            return self::$memory[$key];
        }

        $apcuKey = self::apcuKey($key);
        if (function_exists('apcu_fetch')) {
            $ok = false;
            $cached = apcu_fetch($apcuKey, $ok);
            if ($ok && is_bool($cached)) {
                return self::$memory[$key] = $cached;
            }
        }

        $exists = $query();
        if ($exists === null) {
            return false;
        }

        self::$memory[$key] = $exists;
        if (function_exists('apcu_store')) {
            apcu_store($apcuKey, $exists, $exists ? self::POSITIVE_TTL : self::NEGATIVE_TTL);
        }

        return $exists;
    }

    private static function cacheKey(mysqli $db, string $table, string $column): string
    {
        return self::schemaName($db) . "\0" . $table . "\0" . $column;
    }

    private static function apcuKey(string $key): string
    {
        return self::APCU_PREFIX . hash('sha256', $key);
    }

    private static function schemaName(mysqli $db): string
    {
        if (self::$schema !== null) {
            return self::$schema;
        }
        $result = $db->query('SELECT DATABASE()');
        $name = '';
        if ($result !== false) {
            $row = $result->fetch_row();
            $name = is_array($row) && isset($row[0]) ? (string) $row[0] : '';
        }

        return self::$schema = $name;
    }

    private static function isIdentifier(string $name): bool
    {
        return $name !== '' && preg_match('/^[A-Za-z0-9_]+$/', $name) === 1;
    }

    private static function likeLiteral(string $identifier): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $identifier);
    }
}
