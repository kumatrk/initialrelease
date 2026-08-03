<?php

declare(strict_types=1);

/**
 * Ensures APP_BASE_URL / ASSETS_BASE_URL exist for legacy config.php files.
 * New installs define PUBLIC_WEB_PREFIX explicitly in config.
 */
if (!defined('BASE_URL')) {
    return;
}

if (!defined('PUBLIC_WEB_PREFIX')) {
    if (defined('ASSETS_BASE_URL')) {
        $base = rtrim(BASE_URL, '/');
        $prefix = str_starts_with(ASSETS_BASE_URL, $base)
            ? substr(ASSETS_BASE_URL, strlen($base))
            : '';
        define('PUBLIC_WEB_PREFIX', $prefix);
    } else {
        define('PUBLIC_WEB_PREFIX', '');
    }
}

if (!defined('ASSETS_BASE_URL')) {
    define('ASSETS_BASE_URL', rtrim(BASE_URL, '/') . PUBLIC_WEB_PREFIX);
}

if (!defined('APP_BASE_URL')) {
    define('APP_BASE_URL', rtrim(BASE_URL, '/') . PUBLIC_WEB_PREFIX);
}

// v1.1.0+ single-tenant: all users are admins (override in config.php if needed)
if (!defined('SINGLE_ADMIN_MODE')) {
    define('SINGLE_ADMIN_MODE', true);
}

// Click URL shape — set explicitly in config.php (installer writes query+go for new installs).
// When unset, ClickPath falls back to legacy path style /km/{key} for upgrades.
