<?php

declare(strict_types=1);

namespace SimpleKuma\Tracking;

/**
 * Public click-entry URLs. New installs default to query style (go.php?k=).
 * Legacy /km/{key} rewrites always remain for existing ad links.
 */
final class ClickPath
{
    public const STYLE_QUERY = 'query';
    public const STYLE_PATH = 'path';

    public static function style(): string
    {
        if (defined('CLICK_URL_STYLE')) {
            $style = strtolower(trim((string) CLICK_URL_STYLE));
            if ($style === self::STYLE_QUERY || $style === self::STYLE_PATH) {
                return $style;
            }
        }

        // Upgrades without config constants keep legacy path URLs (/km/{key}).
        return self::STYLE_PATH;
    }

    public static function entryScript(): string
    {
        if (defined('CLICK_ENTRY_SCRIPT')) {
            $script = trim((string) CLICK_ENTRY_SCRIPT);
            if ($script !== '' && preg_match('#^[a-zA-Z0-9_-]{1,24}\.php$#', $script) === 1) {
                return $script;
            }
        }

        return 'go.php';
    }

    public static function prefix(): string
    {
        if (defined('CLICK_PATH_PREFIX')) {
            $prefix = trim((string) CLICK_PATH_PREFIX, '/');
            if ($prefix !== '' && preg_match('#^[a-zA-Z0-9_-]{1,24}$#', $prefix) === 1) {
                return $prefix;
            }
        }

        return self::style() === self::STYLE_QUERY ? 'go' : 'km';
    }

    public static function keyQueryParam(): string
    {
        return 'k';
    }

    public static function segment(string $identifier): string
    {
        return '/' . self::prefix() . '/' . rawurlencode($identifier);
    }

    public static function url(string $baseDomain, string $identifier): string
    {
        $base = rtrim($baseDomain, '/');
        $id = rawurlencode($identifier);

        if (self::style() === self::STYLE_QUERY) {
            return $base . '/' . self::entryScript() . '?' . self::keyQueryParam() . '=' . $id;
        }

        return $base . self::segment($identifier);
    }

    /**
     * Append tracking token query string to a base click URL.
     *
     * @param list<string> $params
     */
    public static function appendParams(string $baseUrl, array $params): string
    {
        if ($params === []) {
            return $baseUrl;
        }

        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        return $baseUrl . $separator . implode('&', $params);
    }

    /**
     * Path prefixes that rewrite to the click handler (legacy km + configured prefix).
     *
     * @return list<string>
     */
    public static function rewritePrefixes(): array
    {
        $prefixes = ['km', self::prefix()];
        if (self::prefix() !== 'c') {
            $prefixes[] = 'c';
        }
        if (self::prefix() !== 'go') {
            $prefixes[] = 'go';
        }

        return array_values(array_unique($prefixes));
    }

    /**
     * @return array{style: string, entry_script: string, path_prefix: string, key_param: string}
     */
    public static function clientConfig(): array
    {
        return [
            'style' => self::style(),
            'entry_script' => self::entryScript(),
            'path_prefix' => self::prefix(),
            'key_param' => self::keyQueryParam(),
        ];
    }
}
