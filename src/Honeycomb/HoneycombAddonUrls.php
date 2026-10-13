<?php

declare(strict_types=1);

namespace SimpleKuma\Honeycomb;

/**
 * Build URLs for Honeycomb generic addon HTTP + static assets.
 */
final class HoneycombAddonUrls
{
    public static function apiBase(?string $appBaseUrl = null): string
    {
        $base = $appBaseUrl ?? (defined('APP_BASE_URL') ? (string) APP_BASE_URL : '');
        return rtrim($base, '/') . '/api/honeycomb-addon.php';
    }

    public static function api(string $slug, string $action = '', ?string $appBaseUrl = null): string
    {
        $url = self::apiBase($appBaseUrl) . '?slug=' . rawurlencode($slug);
        if ($action !== '') {
            $url .= '&action=' . rawurlencode($action);
        }
        return $url;
    }

    public static function asset(string $slug, string $file, ?string $appBaseUrl = null): string
    {
        $base = $appBaseUrl ?? (defined('APP_BASE_URL') ? (string) APP_BASE_URL : '');
        return rtrim($base, '/') . '/api/honeycomb-asset.php?slug='
            . rawurlencode($slug) . '&file=' . rawurlencode($file);
    }
}
