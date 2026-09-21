<?php

declare(strict_types=1);

namespace SimpleKuma\Database;

use mysqli;

/**
 * Align MySQL session and PHP default timezone for consistent timestamp storage.
 */
class DbTimezone
{
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
        self::applySession($db);
    }
}
