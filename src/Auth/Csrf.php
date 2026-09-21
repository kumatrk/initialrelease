<?php

declare(strict_types=1);

namespace SimpleKuma\Auth;

/**
 * CSRF protection for app forms (login, settings, etc.).
 */
class Csrf
{
    private const SESSION_KEY = 'app_csrf';

    public static function ensureToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public static function validate(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $submitted = $_POST[self::SESSION_KEY] ?? '';
        $stored = $_SESSION[self::SESSION_KEY] ?? '';

        if (!is_string($submitted) || !is_string($stored) || $stored === '') {
            return false;
        }

        return hash_equals($stored, $submitted);
    }

    public static function field(): string
    {
        return '<input type="hidden" name="' . self::SESSION_KEY . '" value="'
            . htmlspecialchars(self::ensureToken(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function invalidRequestMessage(): string
    {
        return 'Invalid or expired form submission. Please refresh the page and try again.';
    }
}
