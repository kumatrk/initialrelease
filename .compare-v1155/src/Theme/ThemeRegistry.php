<?php

declare(strict_types=1);

namespace SimpleKuma\Theme;

/**
 * Central registry for UI themes.
 *
 * To add a theme:
 * 1. Add logo (and any assets) under public/assets/images/
 * 2. Add an entry below (id, label, logo filename, base light|dark)
 * 3. Create public/assets/css/themes/{id}.css as a [data-theme="{id}"] variable pack
 * 4. @import that file from public/assets/css/themes.css
 *
 * No per-page CSS or JS theme branches are required.
 */
final class ThemeRegistry
{
    public const DEFAULT_THEME = 'light';

    /**
     * @var array<string, array{label: string, logo: string, base: string}>
     */
    private const THEMES = [
        'light' => [
            'label' => 'Light',
            'logo' => 'mainlogo.png',
            'base' => 'light',
        ],
        'dark' => [
            'label' => 'Dark',
            'logo' => 'darkmodelogo2.png',
            'base' => 'dark',
        ],
    ];

    /**
     * Theme id => display label (for dropdowns).
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::THEMES as $id => $meta) {
            $out[$id] = $meta['label'];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_keys(self::THEMES);
    }

    /**
     * @return array{label: string, logo: string, base: string}|null
     */
    public static function get(string $theme): ?array
    {
        return self::THEMES[$theme] ?? null;
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

    /**
     * Surface polarity for the theme (light|dark). Used for charts and residual CSS.
     */
    public static function base(string $theme): string
    {
        $meta = self::get(self::normalize($theme));

        return ($meta['base'] ?? 'light') === 'dark' ? 'dark' : 'light';
    }

    /**
     * Logo filename for a theme (under assets/images/).
     */
    public static function logo(string $theme): string
    {
        $meta = self::get(self::normalize($theme));

        return $meta['logo'] ?? self::THEMES[self::DEFAULT_THEME]['logo'];
    }

    /**
     * Client-safe theme map for KUMA_THEME_CONFIG.
     *
     * @return array<string, array{id: string, label: string, logo: string, base: string}>
     */
    public static function toClientConfig(): array
    {
        $out = [];
        foreach (self::THEMES as $id => $meta) {
            $out[$id] = [
                'id' => $id,
                'label' => $meta['label'],
                'logo' => $meta['logo'],
                'base' => $meta['base'] === 'dark' ? 'dark' : 'light',
            ];
        }

        return $out;
    }
}
