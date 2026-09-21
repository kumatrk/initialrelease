<?php

declare(strict_types=1);

namespace SimpleKuma\Honeycomb;

use SimpleKuma\Settings\SettingsManager;

/**
 * Official Honeycomb catalog location. Not user-configurable.
 */
final class HoneycombConfig
{
    public const DEFAULT_REPO = 'kumatrk/honeycomb-addons';
    public const DEFAULT_REF = 'main';
    public const SETTING_LAST_CHECK = 'honeycomb_catalog_last_check';

    public const ALLOWED_PROVIDES = [
        'cost_overlay',
        'cost_sync',
        'settings_panel',
        'campaign_fields',
        'conversion_export',
    ];

    public const ALLOWED_TYPES = [
        'traffic_source',
        'utility',
        'other',
    ];

    /** Human labels for catalog type filter / badges */
    public const TYPE_LABELS = [
        'traffic_source' => 'Traffic source',
        'utility' => 'Utility',
        'other' => 'Other',
    ];

    public static function typeLabel(string $type): string
    {
        $type = trim($type);
        return self::TYPE_LABELS[$type] ?? ($type !== '' ? $type : 'Other');
    }

    public static function catalogRepository(?SettingsManager $settings = null): string
    {
        return self::DEFAULT_REPO;
    }

    public static function catalogRef(?SettingsManager $settings = null): string
    {
        return self::DEFAULT_REF;
    }

    public static function isValidRepoSlug(string $repo): bool
    {
        return preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) === 1;
    }

    public static function catalogRawUrl(string $repo, string $ref): string
    {
        return 'https://raw.githubusercontent.com/' . $repo . '/' . rawurlencode($ref) . '/catalog.json';
    }

    public static function githubRepoUrl(string $repo): string
    {
        return 'https://github.com/' . $repo;
    }
}
