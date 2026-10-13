<?php

declare(strict_types=1);

namespace SimpleKuma\Database;

use mysqli;

/**
 * Align MySQL session charset/timezone and PHP default timezone.
 * Call after every mysqli connect so UTF-8 (e.g. Polish region names) is not
 * misinterpreted when the server default client charset is latin1/cp1250.
 */
class DbTimezone
{
    public static function applyCharset(mysqli $db): void
    {
        $charset = (defined('DB_CHARSET') && DB_CHARSET !== '') ? DB_CHARSET : 'utf8mb4';
        $db->set_charset($charset);
    }

    public static function applySession(mysqli $db): void
    {
        $db->query("SET time_zone = '+00:00'");
    }

    public static function bootstrapAppTimezone(): void
    {
        if (defined('APP_TIMEZONE') && APP_TIMEZONE !== '') {
            @date_default_timezone_set(APP_TIMEZONE);
        }
    }

    public static function init(mysqli $db): void
    {
        self::bootstrapAppTimezone();
        self::applyCharset($db);
        self::applySession($db);
    }
}
