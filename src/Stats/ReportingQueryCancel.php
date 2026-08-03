<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

use mysqli;

/**
 * Best-effort cancel of in-flight reporting SELECTs when the browser aborts
 * (page navigation). PHP alone cannot interrupt a blocking mysqli query on the
 * same connection — a second connection runs KILL QUERY.
 */
final class ReportingQueryCancel
{
    private static bool $registered = false;

    private static ?int $connectionId = null;

    private static string $dbHost = '';

    private static string $dbUser = '';

    private static string $dbPassword = '';

    private static string $dbName = '';

    private static int $dbPort = 3306;

    /**
     * Capture CONNECTION_ID and register a shutdown killer for abandoned requests.
     */
    public static function arm(mysqli $db): void
    {
        if (!defined('DB_HOST') || !defined('DB_USER') || !defined('DB_PASSWORD') || !defined('DB_NAME')) {
            return;
        }

        $row = $db->query('SELECT CONNECTION_ID() AS id');
        if ($row === false) {
            return;
        }
        $assoc = $row->fetch_assoc();
        $id = (int) ($assoc['id'] ?? 0);
        if ($id <= 0) {
            return;
        }

        self::$connectionId = $id;
        self::$dbHost = (string) DB_HOST;
        self::$dbUser = (string) DB_USER;
        self::$dbPassword = (string) DB_PASSWORD;
        self::$dbName = (string) DB_NAME;
        self::$dbPort = defined('DB_PORT') ? (int) DB_PORT : 3306;

        if (self::$registered) {
            return;
        }
        self::$registered = true;
        register_shutdown_function([self::class, 'shutdownKillIfAborted']);
    }

    /**
     * Exit immediately if the client already disconnected.
     */
    public static function throwIfAborted(): void
    {
        if (connection_aborted()) {
            // Trigger shutdown killer while CONNECTION_ID is still known.
            exit;
        }
    }

    public static function shutdownKillIfAborted(): void
    {
        if (self::$connectionId === null || self::$connectionId <= 0) {
            return;
        }
        if (!connection_aborted()) {
            return;
        }

        $id = self::$connectionId;
        self::$connectionId = null;

        try {
            $killer = @new mysqli(
                self::$dbHost,
                self::$dbUser,
                self::$dbPassword,
                self::$dbName,
                self::$dbPort
            );
            if ($killer->connect_error) {
                return;
            }
            // KILL QUERY stops the statement; connection may still close via request end.
            @$killer->query('KILL QUERY ' . (int) $id);
            $killer->close();
        } catch (\Throwable $e) {
            // Best-effort only — never break shutdown.
        }
    }
}
