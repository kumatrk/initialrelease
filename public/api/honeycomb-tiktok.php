<?php

declare(strict_types=1);

/**
 * Legacy shim — prefer /api/honeycomb-addon.php?slug=tiktok-cost&action=…
 * Kept for older bookmarks / cached campaign-tiktok.js fallbacks.
 */
$_GET['slug'] = 'tiktok-cost';
require __DIR__ . '/honeycomb-addon.php';
