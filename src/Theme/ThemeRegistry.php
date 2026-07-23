<?php

declare(strict_types=1);

namespace SimpleKuma\Theme;

/**
 * Central registry for UI themes (extensible for future premade themes).
 */
final class ThemeRegistry
{
    public const DEFAULT_THEME = 'light';

    /** @var array<string, string> theme id => display label */
    private const THEMES = [
        'light' => 'Light',
        'dark' => 'Dark',
    ];

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::THEMES;
    }

    public static function isValid(string $theme): bool
    {
        return isset(self::THEMES[$theme]);
    }

    public static function normalize(?string $theme): string
    {
        if ($theme !== null && self::isValid($theme)) {
            return $theme;
        }

        return self::DEFAULT_THEME;
    }
}
